<?php

declare(strict_types=1);

namespace App\Tests\Payment\Entity;

use App\Payment\Entity\PaymentOutboxMessage;
use PHPUnit\Framework\TestCase;

final class PaymentOutboxMessageTest extends TestCase
{
    public function testItExposesTheEventDetailsAndCanBePublished(): void
    {
        $message = new PaymentOutboxMessage(
            'payment.invoice.paid',
            'invoice',
            'invoice-1',
            ['amount' => 123],
            'correlation-1',
            'cause-1',
        );

        self::assertNull($message->getId());
        self::assertNotSame('', $message->getEventId());
        self::assertSame('correlation-1', $message->getCorrelationId());
        self::assertSame('cause-1', $message->getCausationId());
        self::assertSame('payment.invoice.paid', $message->getTopic());
        self::assertSame('invoice-1', $message->getAggregateId());
        self::assertSame(['amount' => 123], $message->getPayload());
        self::assertInstanceOf(\DateTimeImmutable::class, $message->getOccurredAt());

        $message->markPublished();
    }
}
