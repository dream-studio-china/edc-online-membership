<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Trade\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

class OrderWorkflowListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.transition' => 'onTransition',
        ];
    }

    public function onTransition(TransitionEvent $event): void
    {
        /** @var Order $order */
        $order = $event->getSubject();
        $transitionName = $event->getTransition()->getName();

        $this->logger->info(sprintf(
            'Order #%d transition: %s',
            $order->getId() ?? 0,
            $transitionName,
        ));

        switch ($transitionName) {
            case 'cancel':
                $order->setCancelledAt(new \DateTimeImmutable());
                break;
            case 'pay':
                if ($order->getPaidAt() === null) {
                    $order->setPaidAt(new \DateTimeImmutable());
                }
                break;
            case 'fulfill':
                if ($order->getFulfilledAt() === null) {
                    $order->setFulfilledAt(new \DateTimeImmutable());
                }
                break;
            case 'complete':
                $order->setCompletedAt(new \DateTimeImmutable());
                break;
            case 'refund':
                if ($order->getRefundedAt() === null) {
                    $order->setRefundedAt(new \DateTimeImmutable());
                }
                break;
        }
    }
}
