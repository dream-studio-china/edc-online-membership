<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Repository\RoleRepository;
use App\Authorization\Service\AuthorizationAuditService;
use App\Authorization\Service\AuthorizationCacheInvalidator;
use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/assignments', name: 'store-assignments-', requirements: ['scopeId' => '\d+|[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class AssignmentController extends RestController
{
    public function __construct(
        private readonly StoreServiceInterface $storeService,
        private readonly MembershipServiceInterface $membershipService,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly RoleRepository $roleRepository,
        private readonly UserRepository $userRepository,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
        private readonly AuthorizationAuditService $auditService,
        private readonly AuthorizationCacheInvalidator $cacheInvalidator,
        /** @var list<string> */
        #[Autowire('%store.staff_assignable_role_codes%')]
        private readonly array $assignableRoleCodes,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/store/{scopeId}/assignments',
        summary: 'List active staff assignments for a Store',
        parameters: [new OA\Parameter(name: 'scopeId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Assignments returned'), new OA\Response(response: 403, description: 'Manager or owner membership required')],
        tags: ['Store'],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(string $scopeId): Response
    {
        try {
            $store = $this->managedStore($scopeId);

            return $this->success(array_map($this->assignmentData(...), $this->assignmentRepository->findActiveByStoreScope($store->getUuid())));
        } catch (AccessDeniedHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_FORBIDDEN);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_NOT_FOUND);
        }
    }

    #[OA\Post(
        path: '/api/v1/store/{scopeId}/assignments',
        summary: 'Grant an allowlisted Store role to an active Store member',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['userUuid', 'roleUuid'], properties: [
            new OA\Property(property: 'userUuid', type: 'string', format: 'uuid'),
            new OA\Property(property: 'roleUuid', type: 'string', format: 'uuid'),
        ])),
        responses: [new OA\Response(response: 201, description: 'Assignment granted'), new OA\Response(response: 403, description: 'Manager or owner membership required')],
        tags: ['Store'],
    )]
    #[Route('', name: 'grant', methods: ['POST'])]
    public function grantAction(Request $request, string $scopeId): Response
    {
        try {
            $store = $this->managedStore($scopeId);
            $content = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($content)) {
                throw new \InvalidArgumentException('Invalid JSON.');
            }
            if (array_key_exists('scopeType', $content) || array_key_exists('scopeUuid', $content) || array_key_exists('scope_type', $content) || array_key_exists('scope_uuid', $content)) {
                throw new \InvalidArgumentException('scopeType and scopeUuid are set by the Store route.');
            }

            $userUuid = trim((string) ($content['userUuid'] ?? ''));
            $roleUuid = trim((string) ($content['roleUuid'] ?? ''));
            if (!UUID::is_valid($userUuid) || !UUID::is_valid($roleUuid)) {
                throw new \InvalidArgumentException('userUuid and roleUuid must be valid UUIDs.');
            }
            if (!$this->userRepository->findOneBy(['uuid' => $userUuid]) instanceof User) {
                throw new NotFoundHttpException('User not found.');
            }
            $this->membershipService->requireAuthorization($store, $userUuid);

            $role = $this->roleRepository->findOneByUuid($roleUuid);
            if (!$role instanceof Role) {
                throw new NotFoundHttpException('Role not found.');
            }
            if ($role->getScopeType() !== Role::SCOPE_STORE || !in_array($role->getCode(), $this->assignableRoleCodes, true)) {
                throw new \InvalidArgumentException('Role is not assignable by Store managers.');
            }

            $assignment = $this->assignmentRepository->findActiveAssignment($userUuid, $role, Assignment::SCOPE_STORE, $store->getUuid());
            if ($assignment instanceof Assignment) {
                return $this->success($this->assignmentData($assignment), 'Assignment already granted.');
            }

            $roleId = $role->getId();
            \assert($roleId !== null);
            $assignment = $this->assignmentRepository->findAnyByUserRoleScope($userUuid, $roleId, Assignment::SCOPE_STORE, $store->getUuid());
            $created = false;
            if ($assignment instanceof Assignment) {
                $assignment->setRevokedAt(null)->setGrantedByUuid($this->actorUuid());
            } else {
                $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_STORE, $store->getUuid(), $this->actorUuid());
                $this->em->persist($assignment);
                $created = true;
            }

            $this->auditService->record($this->actorUuid(), 'assignment.granted', 'assignment', $assignment->getUuid(), null, $this->assignmentAuditData($assignment));
            $this->em->flush();
            $this->cacheInvalidator->invalidateUser($userUuid);

            return $this->success($this->assignmentData($assignment), $created ? 'Assignment granted.' : 'Assignment reactivated.', $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_BAD_REQUEST);
        } catch (AccessDeniedHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_FORBIDDEN);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_NOT_FOUND);
        } catch (\RuntimeException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_FORBIDDEN);
        }
    }

    #[OA\Delete(
        path: '/api/v1/store/{scopeId}/assignments/{assignmentUuid}',
        summary: 'Revoke an assignment in the current Store only',
        parameters: [new OA\Parameter(name: 'scopeId', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'assignmentUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Assignment revoked'), new OA\Response(response: 403, description: 'Manager or owner membership required'), new OA\Response(response: 404, description: 'Assignment not found in this Store')],
        tags: ['Store'],
    )]
    #[Route('/{assignmentUuid}', name: 'revoke', methods: ['DELETE'], requirements: ['assignmentUuid' => '[0-9a-fA-F-]{36}'])]
    public function revokeAction(string $scopeId, string $assignmentUuid): Response
    {
        try {
            $store = $this->managedStore($scopeId);
            $assignment = $this->assignmentRepository->findOneByUuid($assignmentUuid);
            if (!$assignment instanceof Assignment || $assignment->getScopeType() !== Assignment::SCOPE_STORE || $assignment->getScopeUuid() !== $store->getUuid()) {
                throw new NotFoundHttpException('Assignment not found in this Store.');
            }
            if ($assignment->isActive()) {
                $assignment->setRevokedAt(new \DateTimeImmutable());
                $this->auditService->record($this->actorUuid(), 'assignment.revoked', 'assignment', $assignment->getUuid(), null, $this->assignmentAuditData($assignment));
                $this->em->flush();
                $this->cacheInvalidator->invalidateUser($assignment->getUserUuid());
            }

            return $this->success('', 'SUCCESS', Response::HTTP_NO_CONTENT);
        } catch (AccessDeniedHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_FORBIDDEN);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_NOT_FOUND);
        }
    }

    private function managedStore(string $scopeId): Store
    {
        $store = $this->storeService->get(ctype_digit($scopeId) ? ['id' => (int) $scopeId] : ['uuid' => $scopeId], false);
        if (!$store instanceof Store) {
            throw new NotFoundHttpException('Store not found.');
        }

        $actorUuid = $this->actorUuid();
        if ($actorUuid === null) {
            throw new AccessDeniedHttpException('Access denied.');
        }
        try {
            $this->membershipService->requireAuthorization($store, $actorUuid, [Membership::ROLE_OWNER, Membership::ROLE_MANAGER]);
        } catch (\RuntimeException) {
            throw new AccessDeniedHttpException('Store owner or manager membership is required.');
        }

        return $store;
    }

    private function actorUuid(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUuid() : null;
    }

    /** @return array<string, mixed> */
    private function assignmentData(Assignment $assignment): array
    {
        $role = $assignment->getRole();

        return [
            'uuid' => $assignment->getUuid(),
            'userUuid' => $assignment->getUserUuid(),
            'scopeType' => $assignment->getScopeType(),
            'scopeUuid' => $assignment->getScopeUuid(),
            'status' => $assignment->isActive() ? 'active' : 'revoked',
            'createdAt' => $assignment->getCreatedAt()->format(DATE_ATOM),
            'role' => [
                'uuid' => $role->getUuid(),
                'code' => $role->getCode(),
                'name' => $role->getName(),
                'scopeType' => $role->getScopeType(),
                'permissions' => array_values(array_map(static fn ($permission): string => $permission->getCode(), $role->getPermissions()->toArray())),
            ],
        ];
    }

    /** @return array{userUuid: string, roleCode: string, scopeType: string, scopeUuid: ?string} */
    private function assignmentAuditData(Assignment $assignment): array
    {
        return [
            'userUuid' => $assignment->getUserUuid(),
            'roleCode' => $assignment->getRole()->getCode(),
            'scopeType' => $assignment->getScopeType(),
            'scopeUuid' => $assignment->getScopeUuid(),
        ];
    }
}
