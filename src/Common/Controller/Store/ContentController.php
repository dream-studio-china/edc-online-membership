<?php

declare(strict_types=1);

namespace App\Common\Controller\Store;

use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Authorization\Service\FieldAuthorizationServiceInterface;
use App\Common\Entity\Content;
use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Store\Repository\MembershipRepository;
use App\Store\Repository\StoreRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;

#[Route('/store/stores/{storeUuid}/contents', name: 'store-contents-')]
#[IsGranted('ROLE_USER')]
class ContentController extends RestController
{
    public function __construct(
        private readonly ContentServiceInterface $contentService,
        private readonly StoreRepository $storeRepository,
        private readonly MembershipRepository $membershipRepository,
        private readonly AuthorizationServiceInterface $authorizationService,
        private readonly FieldAuthorizationServiceInterface $fieldAuthorizationService,
    ) {
    }

    private function requireStoreAccess(string $storeUuid, string $permission): \App\Store\Entity\Store
    {
        if (!UUID::is_valid($storeUuid)) {
            throw $this->createNotFoundException('Store not found');
        }
        $store = $this->storeRepository->findOneByUuid($storeUuid);
        if ($store === null) {
            throw $this->createNotFoundException('Store not found');
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Identity\Entity\User) {
            throw $this->createAccessDeniedException('Unauthorized');
        }

        $membership = $this->membershipRepository->findForStoreAndUser($store, $user->getUuid());
        if ($membership === null || !$membership->isActive()) {
            throw $this->createAccessDeniedException('Store membership required');
        }

        $scope = AuthorizationScope::store($storeUuid);
        if (!$this->authorizationService->can($user, $permission, $scope)) {
            throw $this->createAccessDeniedException(sprintf('Missing permission "%s"', $permission));
        }

        return $store;
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(string $storeUuid, Request $request): Response
    {
        try {
            $this->requireStoreAccess($storeUuid, 'common:content:read');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->warning('Entity is not found', 1, null, 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        }

        $criteria = ['storeUuid' => $storeUuid];
        $list = $this->contentService->list($criteria, null, false);

        return $this->success($list);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(string $storeUuid, Request $request): Response
    {
        try {
            $this->requireStoreAccess($storeUuid, 'common:content:create');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->warning('Entity is not found', 1, null, 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        }

        $user = $this->getUser();
        \assert($user instanceof \App\Identity\Entity\User);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->warning('Invalid JSON', 1, null, 400);
        }

        $accepted = ['title', 'body', 'category', 'tags', 'metadata'];
        $filtered = array_intersect_key($data, array_flip($accepted));
        if (!isset($filtered['title']) || trim((string) $filtered['title']) === '') {
            return $this->warning('title is required', 1, null, 400);
        }

        $scope = AuthorizationScope::store($storeUuid);
        try {
            $filtered = $this->fieldAuthorizationService->filterWritableFields($user, 'common:content', 'create', $filtered, $accepted, $scope);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 1, null, 400);
        }

        $content = $this->contentService->new();
        if (!$content instanceof Content) {
            return $this->warning('Create failed', 1, null, 500);
        }
        $content->setStoreUuid($storeUuid);

        try {
            $this->contentService->update($content, $filtered);
        } catch (ValidatorException $e) {
            return $this->warning($e->getMessage(), 1, null, 400);
        } catch (\Exception $e) {
            return $this->warning('Create failed', 1, null, 500);
        }

        return $this->success($content, 'SUCCESS', 201);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detailAction(string $storeUuid, int $id): Response
    {
        try {
            $this->requireStoreAccess($storeUuid, 'common:content:read');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->warning('Entity is not found', 1, null, 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        }

        $criteria = ['id' => $id, 'storeUuid' => $storeUuid];
        $content = $this->contentService->get($criteria);
        if ($content === null) {
            return $this->warning('Entity is not found', 1, null, 404);
        }

        return $this->success($content);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateAction(string $storeUuid, int $id, Request $request): Response
    {
        try {
            $this->requireStoreAccess($storeUuid, 'common:content:update');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->warning('Entity is not found', 1, null, 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        }

        $user = $this->getUser();
        \assert($user instanceof \App\Identity\Entity\User);

        $criteria = ['id' => $id, 'storeUuid' => $storeUuid];
        $content = $this->contentService->get($criteria);
        if ($content === null) {
            return $this->warning('Entity is not found', 1, null, 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->warning('Invalid JSON', 1, null, 400);
        }

        $accepted = ['title', 'body', 'category', 'tags', 'metadata'];
        $filtered = array_intersect_key($data, array_flip($accepted));
        if ($filtered === []) {
            return $this->warning('No updatable fields', 1, null, 400);
        }

        $scope = AuthorizationScope::store($storeUuid);
        try {
            $filtered = $this->fieldAuthorizationService->filterWritableFields($user, 'common:content', 'update', $filtered, $accepted, $scope);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 1, null, 400);
        }

        try {
            $this->contentService->update($content, $filtered);
        } catch (ValidatorException $e) {
            return $this->warning($e->getMessage(), 1, null, 400);
        } catch (\Exception $e) {
            return $this->warning('Update failed', 1, null, 500);
        }

        return $this->success($content);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAction(string $storeUuid, int $id): Response
    {
        try {
            $this->requireStoreAccess($storeUuid, 'common:content:delete');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->warning('Entity is not found', 1, null, 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage(), 1, null, 403);
        }

        $criteria = ['id' => $id, 'storeUuid' => $storeUuid];
        $content = $this->contentService->get($criteria);
        if ($content === null) {
            return $this->warning('Entity is not found', 1, null, 404);
        }

        $this->contentService->remove($content);

        return $this->success(null, 'SUCCESS', 204);
    }
}
