<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderRejectedMessage;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderRejected;
use App\Trade\Service\OrderServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class StoreOrderRejectedHandler
{
    public function __construct(
        private OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private WorkflowInterface $workflow,
    ) {
    }

    public function __invoke(StoreOrderRejectedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $orderUuid = is_array($payload) ? ($payload['orderUuid'] ?? null) : null;
        $storeUuid = is_array($payload) ? ($payload['storeUuid'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid)) {
            throw new \InvalidArgumentException('Invalid store.order.rejected.v1 envelope.');
        }
        $order = $this->orderService->get(['uuid' => $orderUuid]);
        if (!$order instanceof Order || ($order->getMetadata()['_store']['uuid'] ?? null) !== $storeUuid) {
            return;
        }

        $this->orderService->wrapInTransaction(function () use ($order): void {
            if ($this->workflow->can($order, 'store_reject')) {
                $this->workflow->apply($order, 'store_reject');
            }
        });
    }

    #[AsMessageHandler(handles: StoreOrderRejected::class)]
    public function handleContract(StoreOrderRejected $message): void
    {
        $this(new StoreOrderRejectedMessage($message->envelope));
    }
}
