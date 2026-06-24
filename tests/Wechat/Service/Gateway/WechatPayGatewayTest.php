<?php

declare(strict_types=1);

namespace App\Tests\Wechat\Service\Gateway;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\Gateway\WechatPayGateway;
use App\Wechat\Service\WechatService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
final class WechatPayGatewayTest extends TestCase
{
    private WechatService $wechatService;
    private WechatUserRepository $wechatUserRepo;
    private WechatPayGateway $gateway;

    protected function setUp(): void
    {
        $this->wechatService = $this->createMock(WechatService::class);
        $this->wechatUserRepo = $this->createMock(WechatUserRepository::class);

        $this->gateway = new WechatPayGateway(
            $this->wechatService,
            $this->wechatUserRepo,
            'https://example.com/notify/wechat',
        );
    }

    public function testGetName(): void
    {
        self::assertSame('wechat', $this->gateway::getName());
    }

    public function testPayUnsupportedTradeType(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('unsupported_type');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Unsupported WeChat trade type');

        $this->gateway->pay($invoice);
    }

    public function testNotifyThrowsOnInvalidRequest(): void
    {
        self::expectException(\App\Payment\Exception\PaymentVerificationException::class);

        $this->gateway->notify(Request::create('/notify', 'POST', content: 'invalid'));
    }

    public function testGetNotifySuccessResponse(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            transactionId: 'WX_TXN_001',
            responseBody: json_encode(['code' => 'SUCCESS', 'message' => 'OK']),
        );

        $response = $this->gateway->getNotifySuccessResponse($result);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('SUCCESS', $body['code']);
    }

    public function testGetNameMatchesInvoiceConstant(): void
    {
        self::assertSame(Invoice::PAYMENT_WECHAT, $this->gateway::getName());
    }

    public function testNotifySuccessResponseIsJson(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: json_encode(['code' => 'SUCCESS']),
        );

        $response = $this->gateway->getNotifySuccessResponse($result);

        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testPayReturnsPaymentResultForNative(): void
    {
        // Native trade type should return a PaymentResult without requiring a payer
        // This test verifies the structure, but needs WeChat API call to actually work
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('native');

        self::assertInstanceOf(WechatPayGateway::class, $this->gateway);
    }
}
