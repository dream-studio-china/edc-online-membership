<?php

declare(strict_types=1);

namespace App\Authorization\Controller\Manage;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Authorization\Service\AssignmentService;
use App\Authorization\Service\AuthorizationAuditService;
use App\Authorization\Service\AuthorizationCacheInvalidator;
use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/assignments', name: 'manage-assignments-')]
#[IsGranted('ROLE_ADMIN')]
class AssignmentController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DeleteApiViewMixin;

    public function __construct(
        protected readonly AssignmentService $service,
        private readonly AuthorizationAuditService $auditService,
        private readonly AuthorizationCacheInvalidator $cacheInvalidator,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    protected function listFilter($filter = null)
    {
        $request = $this->getRequestStack()->getCurrentRequest();
        if ($request === null) {
            return $filter;
        }
        $userUuid = $request->query->get('userUuid');
        $roleId = $request->query->get('roleId');
        $scopeType = $request->query->get('scopeType');
        $scopeUuid = $request->query->get('scopeUuid');
        $includeRevoked = $request->query->getBoolean('includeRevoked', false);

        $criteria = [];
        if (\is_string($userUuid) && $userUuid !== '') {
            $criteria['userUuid'] = $userUuid;
        }
        if (\is_string($scopeType) && $scopeType !== '') {
            $criteria['scopeType'] = $scopeType;
        }
        if (\is_string($scopeUuid) && $scopeUuid !== '') {
            $criteria['scopeUuid'] = $scopeUuid;
        }
        if (!$includeRevoked) {
            $criteria['revokedAt'] = null;
        }

        if ($filter instanceof \Doctrine\ORM\QueryBuilder) {
            foreach ($criteria as $k => $v) {
                $alias = $filter->getRootAliases()[0];
                $filter->andWhere("$alias.$k = :$k")->setParameter($k, $v);
            }
            return $filter;
        }
        if (\is_array($filter)) {
            return array_merge($criteria, $filter);
        }

        return $criteria;
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->warning('Invalid JSON', 1, null, 400);
        }
        $isBatch = array_is_list($data);
        $items = $isBatch ? $data : [$data];
        $results = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                return $this->warning('Invalid JSON', 1, null, 400);
            }
            $userUuid = trim((string) ($item['userUuid'] ?? $item['user_uuid'] ?? ''));
            $roleUuid = trim((string) ($item['roleUuid'] ?? $item['role_uuid'] ?? $item['roleId'] ?? ''));
            $scopeType = trim((string) ($item['scopeType'] ?? $item['scope_type'] ?? ''));
            $scopeUuid = isset($item['scopeUuid']) || isset($item['scope_uuid']) ? trim((string) ($item['scopeUuid'] ?? $item['scope_uuid'] ?? '')) : null;

            if ($userUuid === '' || $roleUuid === '' || $scopeType === '') {
                return $this->warning('userUuid, roleUuid and scopeType are required', 1, null, 400);
            }
            if (!UUID::is_valid($userUuid)) {
                return $this->warning('Invalid userUuid', 1, null, 400);
            }
            if ($scopeType !== 'global' && $scopeType !== 'store') {
                return $this->warning('Invalid scopeType, expected global or store', 1, null, 400);
            }
            if ($scopeType === 'global' && $scopeUuid !== null && $scopeUuid !== '') {
                return $this->warning('scopeUuid must be null for global scope', 1, null, 400);
            }
            if ($scopeType === 'store' && ($scopeUuid === null || $scopeUuid === '' || !UUID::is_valid($scopeUuid))) {
                return $this->warning('Valid scopeUuid required for store scope', 1, null, 400);
            }
            if ($scopeType === 'global') {
                $scopeUuid = null;
            }

            $role = null;
            if (UUID::is_valid($roleUuid)) {
                $role = $this->em->getRepository(Role::class)->findOneBy(['uuid' => $roleUuid]);
            }
            if ($role === null && ctype_digit($roleUuid)) {
                $role = $this->em->getRepository(Role::class)->find((int) $roleUuid);
            }
            if ($role === null) {
                $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $roleUuid]);
            }
            if ($role === null) {
                return $this->warning('Role not found', 1, null, 404);
            }
            if ($role->getScopeType() !== $scopeType) {
                return $this->warning(sprintf('Role scope "%s" incompatible with assignment scope "%s"', $role->getScopeType(), $scopeType), 1, null, 400);
            }

            $assignmentRepo = $this->em->getRepository(Assignment::class);
            $existingActive = $assignmentRepo->findActiveAssignment($userUuid, $role, $scopeType, $scopeUuid);
            if ($existingActive !== null) {
                $results[] = $existingActive;
                continue;
            }
            $roleId = $role->getId();
            \assert($roleId !== null);
            $anyExisting = $assignmentRepo->findAnyByUserRoleScope($userUuid, $roleId, $scopeType, $scopeUuid);
            $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
            if ($anyExisting !== null && $anyExisting->isRevoked()) {
                $anyExisting->setRevokedAt(null);
                $this->em->flush();
                $this->auditService->record($actorUuid, 'assignment.granted', 'assignment', $anyExisting->getUuid(), null, [
                    'userUuid' => $userUuid,
                    'roleCode' => $role->getCode(),
                    'scopeType' => $scopeType,
                    'scopeUuid' => $scopeUuid,
                ]);
                $this->em->flush();
                $this->cacheInvalidator->invalidateUser($userUuid);
                $results[] = $anyExisting;
                continue;
            }

            $assignment = new Assignment($role, $userUuid, $scopeType, $scopeUuid, $actorUuid);
            $this->em->wrapInTransaction(function () use ($assignment, $actorUuid, $userUuid, $role, $scopeType, $scopeUuid): void {
                $this->em->persist($assignment);
                $this->em->flush();
                $this->auditService->record($actorUuid, 'assignment.granted', 'assignment', $assignment->getUuid(), null, [
                    'userUuid' => $userUuid,
                    'roleCode' => $role->getCode(),
                    'scopeType' => $scopeType,
                    'scopeUuid' => $scopeUuid,
                ]);
                $this->em->flush();
            });
            $this->cacheInvalidator->invalidateUser($userUuid);
            $results[] = $assignment;
        }

        $data = $isBatch ? $results : ($results[0] ?? null);

        return $this->success($data, 'SUCCESS', 201);
    }

    public function deleteAction(int|string $id): Response
    {
        $filter = $this->mixIdToCommonFilter($id);
        $entity = $this->service->get($filter);
        if (!$entity instanceof Assignment) {
            return $this->warning('Entity is not found', 1, null, 404);
        }
        if ($entity->isRevoked()) {
            return $this->success($entity);
        }
        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $userUuid = $entity->getUserUuid();
        $entity->setRevokedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->auditService->record($actorUuid, 'assignment.revoked', 'assignment', $entity->getUuid(), null, [
            'userUuid' => $entity->getUserUuid(),
            'roleCode' => $entity->getRole()->getCode(),
            'scopeType' => $entity->getScopeType(),
            'scopeUuid' => $entity->getScopeUuid(),
        ]);
        $this->em->flush();
        $this->cacheInvalidator->invalidateUser($userUuid);

        return $this->success($entity);
    }
}
