<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\Security\UserUuidResolverInterface;
use App\Core\Service\BaseService;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/manage/orders', name: 'manage-orders-')]
#[IsGranted('ROLE_ADMIN')]
class OrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly OrderServiceInterface $service,
        private readonly StoreContextResolverInterface $storeContextResolver,
        private readonly UserUuidResolverInterface $userUuidResolver,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $workflow,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $userUuid = isset($content['user']) ? $this->userUuidResolver->resolveUserUuid((int) $content['user']) : null;
        if (isset($content['user']) && $userUuid === null) {
            return $this->warning('User not found.', 404, '', 404);
        }
        $currency = $content['currency'] ?? 'CNY';
        $notes = $content['notes'] ?? null;

        try {
            $storeContext = $this->storeContextResolver->resolve();
            $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);

            $order = $this->service->createOrder(
                $result->items,
                $userUuid,
                $result->totalAmount,
                $currency,
                $notes,
                null,
                $storeContext,
            );

            return $this->success($order, 'SUCCESS', 201);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/quote', name: 'quote', methods: ['POST'])]
    public function quoteAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $currency = $content['currency'] ?? 'CNY';

        try {
            $storeContext = $this->storeContextResolver->resolve();
            $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);
            return $this->success($result, 'Quote calculated');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT'])]
    public function updateAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if ($order->getStatus() !== Order::STATUS_DRAFT) {
            return $this->warning('Only draft orders can be updated.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $allowed = ['notes', 'metadata'];
        $data = [];
        foreach ($allowed as $prop) {
            if (array_key_exists($prop, $content)) {
                $data[$prop] = $content[$prop];
            }
        }

        return $this->service->update($order, $data)
            ? $this->success($order)
            : $this->warning();
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    public function deleteAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if ($order->getStatus() !== Order::STATUS_DRAFT) {
            return $this->warning('Only draft orders can be deleted.', 400, '', 400);
        }

        return $this->service->remove($order)
            ? $this->success('', 'SUCCESS', 204)
            : $this->warning();
    }

    #[Route('/{id<\d+>}/items', name: 'items', methods: ['GET'])]
    public function itemsAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        return $this->success($order->getItems()->toArray());
    }

    #[Route('/{id<\d+>}/pay', name: 'pay', methods: ['POST'])]
    public function payAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'pay')) {
            return $this->warning('Order cannot be paid in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $systemWalletId = (int) ($content['systemWalletId'] ?? 0);
        $paymentMethod = $content['paymentMethod'] ?? 'wallet';

        if ($systemWalletId <= 0) {
            return $this->warning('systemWalletId is required.', 400, '', 400);
        }

        try {
            // Wallet transfer happens in its own nested transaction (savepoint).
            // workflow.apply() and update() persist inside the outer transaction,
            // ensuring atomicity: if status update fails, wallet transfer rolls back.
            $this->service->wrapInTransaction(function () use ($order, $systemWalletId, $paymentMethod) {
                $this->service->pay($order, $systemWalletId, $paymentMethod);
                $this->workflow->apply($order, 'pay');
                $this->service->update($order, []);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, 'Payment processed');
    }

    #[Route('/{id<\d+>}/payment', name: 'payment', methods: ['POST'])]
    public function paymentAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'pay')) {
            return $this->warning('Order cannot be paid in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $payment = (string) ($content['payment'] ?? 'mock');

        try {
            return $this->success($this->service->createPayment($order, $payment, $content), 'Payment started');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/fulfill', name: 'fulfill', methods: ['POST'])]
    public function fulfillAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'fulfill')) {
            return $this->warning('Order cannot be fulfilled in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];

        try {
            $this->service->wrapInTransaction(function () use ($order, $content) {
                $this->service->fulfill($order, $content);
                $this->workflow->apply($order, 'fulfill');
                $this->service->update($order, []);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, 'Order fulfilled');
    }

    #[Route('/{id<\d+>}/refund', name: 'refund', methods: ['POST'])]
    public function refundAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'refund')) {
            return $this->warning('Order cannot be refunded in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $systemWalletId = (int) ($content['systemWalletId'] ?? 0);
        $reason = $content['reason'] ?? '';

        if ($reason === '') {
            return $this->warning('reason is required.', 400, '', 400);
        }

        try {
            if ($order->getInvoiceId() !== null) {
                return $this->success($this->service->refundPayment($order, $reason, $content), 'Refund processed');
            }

            if ($systemWalletId <= 0) {
                return $this->warning('systemWalletId is required.', 400, '', 400);
            }

            // Wallet refund + workflow transition + persist in ONE atomic transaction
            $this->service->wrapInTransaction(function () use ($order, $systemWalletId, $reason) {
                $this->service->refund($order, $systemWalletId, $reason);
                $this->workflow->apply($order, 'refund');
                $this->service->update($order, []);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, 'Refund processed');
    }

    #[Route('/todo', name: 'todo-list', methods: ['GET'])]
    public function todoAction(): Response
    {
        $entities = BaseService::listResultToCollection(
            $this->service->list(null, null, false)
        )->toArray();

        $entities = array_filter($entities, function ($entity): bool {
            return count($this->workflow->getEnabledTransitions($entity)) > 0;
        });

        return $this->success(array_values($entities));
    }

    #[Route('/{id<\d+>}/transitions', name: 'available-transitions', methods: ['GET'])]
    public function transitionsAction(int $id): Response
    {
        $entity = $this->service->get(['id' => $id]);

        if (!$entity) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $transitions = $this->workflow->getEnabledTransitions($entity);

        return $this->success($transitions);
    }

    #[Route('/{id<\d+>}/do/{transition}', name: 'do-transition', methods: ['POST'])]
    public function doTransitionAction(Request $request, int $id, string $transition): Response
    {
        try {
            $entity = $this->service->get(['id' => $id]);

            if (!$entity) {
                return $this->warning('Order not found.', 404, '', 404);
            }

            if (!$this->workflow->can($entity, $transition)) {
                throw new ValidatorException('Current transition cannot be applied.');
            }

            $content = json_decode($request->getContent(), true);

            $this->service->wrapInTransaction(function ($em) use ($entity, $content, $transition) {
                if ($transition === 'cancel') {
                    $this->cancelLinkedInvoice($entity);
                }
                if ($content) {
                    $this->service->update($entity, $content);
                }
                $this->workflow->apply($entity, $transition);
            });

        } catch (\Throwable $e) {
            return $this->warning($e->getMessage());
        }

        return $this->success();
    }

    private function cancelLinkedInvoice(Order $order): void
    {
        $this->service->cancel($order);
    }
}
