<?php

declare(strict_types=1);

namespace App\Tests\Wechat\Service\Gateway;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\Gateway\WechatPayGateway;
use App\Wechat\Service\WechatService;
use EasyWeChat\Pay\Application as PayApp;
use EasyWeChat\Pay\Client as PayClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        self::assertSame(Invoice::PAYMENT_WECHAT, $this->gateway::getName());
    }

    public function testPayUnsupportedTradeTypeThrows(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('unsupported');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Unsupported WeChat trade type');

        $this->gateway->pay($invoice);
    }

    public function testPayJsapiWithoutWechatUserThrows(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('jsapi');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');
        $invoice->method('getPayer')->willReturn(null);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat user not found');

        $this->gateway->pay($invoice);
    }

    public function testNotifyThrowsOnSignatureFailure(): void
    {
        self::expectException(PaymentVerificationException::class);

        $this->gateway->notify(Request::create('/notify', 'POST', content: 'invalid'));
    }

    public function testNotifyThrowsOnUnsupportedEvent(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $server = $this->createMock(\EasyWeChat\Pay\Server::class);

        $this->wechatService->method('getPayApp')->willReturn($payApp);
        $payApp->method('getServer')->willReturn($server);

        $server->method('handlePaid')->willReturnSelf();
        $server->method('serve')->willReturn($this->createMock(\Psr\Http\Message\ResponseInterface::class));

        self::expectException(PaymentVerificationException::class);
        self::expectExceptionMessage('unsupported event type');

        $this->gateway->notify(Request::create('/notify', 'POST'));
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

    public function testGetNotifySuccessResponseFallbackBody(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: 'invalid json',
        );

        $response = $this->gateway->getNotifySuccessResponse($result);
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('SUCCESS', $body['code']);
    }
}
