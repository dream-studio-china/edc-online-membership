<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\ApiViewMessages;
use App\Core\View\ScopedListApiViewMixin;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Store\Entity\Membership;
use App\Store\Repository\MembershipRepository;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreManagerAuthorizationApiMixin;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/members', name: 'store-members-', requirements: ['scopeId' => '\d+|[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class MembershipController extends RestController
{
    use StoreManagerAuthorizationApiMixin, ScopedListApiViewMixin;

    public function __construct(
        protected readonly MembershipServiceInterface $service,
        private readonly StoreServiceInterface $storeService,
        private readonly MembershipRepository $membershipRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        $this->authorizeStoreManagementAction();
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    protected function membershipService(): MembershipServiceInterface
    {
        return $this->service;
    }

    protected function scopedListFilter(string $scopeId): \Doctrine\ORM\QueryBuilder
    {
        return $this->membershipRepository->createQueryBuilder('entity')
            ->andWhere('entity.store = :store')
            ->andWhere('entity.status != :revokedStatus')
            ->setParameter('store', $this->storeForManagement())
            ->setParameter('revokedStatus', Membership::STATUS_REVOKED);
    }

    /**
     * 成员列表（含 user 显示信息，与 assignments 口径一致，前端下拉/表格直接展示姓名电话）。
     * 覆盖 ScopedListApiViewMixin::listAction（类方法优先于 trait 方法）。
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(string $scopeId): Response
    {
        try {
            $this->authorizeApiAction('list');
            // service->list() 返回 QueryBuilder（由 success→pagination 负责执行），此处需先执行再映射
            $built = $this->service->list($this->scopedListFilter($scopeId), null, false);
            // @select/@groupBy 的返回不是 Membership 实体；保留原通用列表的投影行为。
            if (!$built instanceof QueryBuilder) {
                return $this->success($built);
            }
            $memberships = $built->getQuery()->getResult();
            if (!is_array($memberships) && !$memberships instanceof \Traversable) {
                $memberships = [];
            }
            $rows = [];
            foreach ($memberships as $membership) {
                if (!$membership instanceof Membership) {
                    continue;
                }
                $rows[] = [
                    'userUuid' => $membership->getUserUuid(),
                    'user' => $this->userData($this->userRepository->findOneBy(['uuid' => $membership->getUserUuid()])),
                    'role' => $membership->getRole(),
                    'status' => $membership->getStatus(),
                    'createdAt' => $membership->getCreatedAt()->format(DATE_ATOM),
                ];
            }

            return $this->success($rows);
        } catch (AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        }
    }

    /** @return array<string, mixed> */
    private function userData(?User $user): array
    {
        if (!$user instanceof User) {
            return ['uuid' => null, 'username' => null, 'nickname' => null, 'phone' => null];
        }

        return [
            'uuid' => $user->getUuid(),
            'username' => $user->getUsername(),
            'nickname' => $user->getProfile()?->getNickname(),
            'phone' => $user->getPhone(),
        ];
    }
}
