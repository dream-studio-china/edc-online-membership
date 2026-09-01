<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderAcceptedMessage;
use CrudPlatform\IntegrationContracts\Event\V1\StoreOrderAccepted;
use App\Trade\Service\OrderServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class StoreOrderAcceptedHandler
{
    public function __construct(
        private OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private WorkflowInterface $workflow,
    ) {
    }

    public function __invoke(StoreOrderAcceptedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $orderUuid = is_array($payload) ? ($payload['orderUuid'] ?? null) : null;
        $storeUuid = is_array($payload) ? ($payload['storeUuid'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid)) {
            throw new \InvalidArgumentException('Invalid store.order.accepted.v1 envelope.');
        }
        $order = $this->orderService->get(['uuid' => $orderUuid]);
        if (!$order instanceof Order || ($order->getMetadata()['_store']['uuid'] ?? null) !== $storeUuid) {
            return;
        }
        if ($this->workflow->can($order, 'store_accept')) {
            $this->orderService->wrapInTransaction(function () use ($order): void {
                $this->workflow->apply($order, 'store_accept');
            });
        }
    }

    #[AsMessageHandler(handles: StoreOrderAccepted::class)]
    public function handleContract(StoreOrderAccepted $message): void
    {
        $this(new StoreOrderAcceptedMessage($message->envelope));
    }
}
