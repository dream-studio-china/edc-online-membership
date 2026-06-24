<?php

declare(strict_types=1);

namespace App\Trade\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/app/orders', name: 'app-orders-')]
#[IsGranted('ROLE_USER')]
class OrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly OrderServiceInterface $service,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $workflow,
    ) {
    }

    protected function commonFilter(): array
    {
        $user = $this->getUser();
        if ($user === null) {
            return ['id' => -1];
        }
        return ['user' => $user];
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $currency = $content['currency'] ?? 'CNY';
        $notes = $content['notes'] ?? null;
        $user = $this->getUser();

        try {
            $result = $this->service->calculatePrices($items, $currency);

            $order = $this->service->createOrder(
                $result->items,
                $user,
                $result->totalAmount,
                $currency,
                $notes,
            );

            return $this->success($order, 'Order created', 201);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/items', name: 'items', methods: ['GET'])]
    public function itemsAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        return $this->success($order->getItems()->toArray());
    }

    #[Route('/{id<\d+>}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancelAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'cancel')) {
            return $this->warning('Order cannot be cancelled in current status.', 400, '', 400);
        }

        try {
            $this->service->wrapInTransaction(function () use ($order) {
                $this->workflow->apply($order, 'cancel');
            });
            return $this->success($order, 'Order cancelled');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/payment', name: 'payment', methods: ['POST'])]
    public function paymentAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
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
}
