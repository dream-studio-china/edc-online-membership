<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Service\Payment;

use App\Identity\Entity\User;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use App\Liansheng\Service\Payment\LianshengPointGateway;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
final class LianshengPointGatewayTest extends TestCase
{
    public function testPaysSynchronouslyWithInvoiceReference(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $invoice = $this->invoice();
        $service->expects(self::once())->method('deductMemberPoints')->with(
            '13802542123',
            120,
            $invoice->getOutTradeNo(),
            'Points order',
            null,
            'ONLINE',
        )->willReturn(['code' => 0, 'msg' => 'OK', 'data' => null]);
        $gateway = new LianshengPointGateway($service);

        $result = $gateway->pay($invoice, 120);

        self::assertSame(Invoice::PAYMENT_LIANSHENG_POINT, $gateway::getName());
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame($invoice->getOutTradeNo(), $result->payload['transactionId']);
        self::assertSame(120, $result->payload['points']);
        self::assertSame('OK', $result->payload['providerMessage']);
    }

    public function testRejectsNonLianshengPointCurrency(): void
    {
        $gateway = new LianshengPointGateway($this->createMock(LianshengServiceInterface::class));
        $invoice = $this->invoice()->setCurrency('CNY');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires LIANSHENG_POINT currency');

        $gateway->pay($invoice, 120);
    }

    public function testRequiresVerifiedPayerPhone(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('deductMemberPoints');
        $gateway = new LianshengPointGateway($service);
        $invoice = $this->invoice(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified phone');

        $gateway->pay($invoice, 120);
    }

    public function testDoesNotConvertProviderFailureToPaid(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->method('deductMemberPoints')->willThrowException(new LianshengApiException('insufficient points'));
        $gateway = new LianshengPointGateway($service);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('insufficient points');

        $gateway->pay($this->invoice(), 120);
    }

    public function testRejectsNotify(): void
    {
        $gateway = new LianshengPointGateway($this->createMock(LianshengServiceInterface::class));

        try {
            $gateway->notify(new Request());
            self::fail('Notify should not be supported.');
        } catch (PaymentVerificationException $exception) {
            self::assertStringContainsString('synchronous', $exception->getMessage());
        }

    }

    public function testRefundIsUnsupported(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('deductMemberPoints');
        $gateway = new LianshengPointGateway($service);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('refunds are not supported');

        $gateway->refund($this->invoice(), 120, 120, 'Customer cancelled');
    }

    public function testNotifySuccessResponseUsesResultBody(): void
    {
        $gateway = new LianshengPointGateway($this->createMock(LianshengServiceInterface::class));
        $result = new PaymentNotifyResult(
            payment: Invoice::PAYMENT_LIANSHENG_POINT,
            outTradeNo: 'PAY-1',
            status: Invoice::STATUS_PAID,
            amount: 120,
            responseBody: 'OK',
        );

        $response = $gateway->getNotifySuccessResponse($result);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    private function invoice(bool $phoneVerified = true): Invoice
    {
        $payer = (new User())
            ->setPhone('13802542123')
            ->setPhoneVerified($phoneVerified);

        return (new Invoice())
            ->setCurrency('liansheng_point')
            ->setPayer($payer)
            ->setSubject('Points order');
    }
}
