<?php

declare(strict_types=1);

namespace App\Wechat\Service\Gateway;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\WechatService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WechatPayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly WechatService $wechatService,
        private readonly WechatUserRepository $wechatUserRepository,
        #[Autowire('%wechat.pay.notify_url%')]
        private readonly string $notifyUrl,
    ) {}

    public static function getName(): string
    {
        return Invoice::PAYMENT_WECHAT;
    }

    public function pay(Invoice $invoice, array $options = []): PaymentResult
    {
        $app = $this->wechatService->getPayApp();
        $tradeType = $invoice->getTradeType() ?? 'jsapi';

        $body = [
            'mchid' => (string) $app->getMerchant()->getMerchantId(),
            'out_trade_no' => $invoice->getOutTradeNo(),
            'appid' => $this->wechatService->getMiniApp()->getAccount()->getAppId(),
            'description' => $invoice->getSubject() ?? $invoice->getDescription() ?? 'Payment',
            'notify_url' => $this->notifyUrl,
            'amount' => [
                'total' => $invoice->getAmount(),
                'currency' => $invoice->getCurrency(),
            ],
        ];

        if ($tradeType === 'jsapi') {
            $wechatUser = $this->wechatUserRepository->findByUser($invoice->getPayer());
            if ($wechatUser === null) {
                throw new \RuntimeException('WeChat user not found for payer. Login via WeChat first.');
            }
            $body['payer'] = ['openid' => $wechatUser->getOpenid()];

            $response = $app->getClient()->postJson('v3/pay/transactions/jsapi', $body);
            $result = $response->toArray(false);

            $prepayId = $result['prepay_id'] ?? '';
            $miniAppId = $this->wechatService->getMiniApp()->getAccount()->getAppId();
            $config = $app->getUtils()->buildMiniAppConfig($prepayId, $miniAppId, 'RSA');

            return new PaymentResult(
                invoice: $invoice,
                status: Invoice::STATUS_PAYING,
                payload: $config->toArray(),
                message: 'WeChat JSAPI order created',
            );
        }

        if ($tradeType === 'native') {
            $response = $app->getClient()->postJson('v3/pay/transactions/native', $body);
            $result = $response->toArray(false);

            return new PaymentResult(
                invoice: $invoice,
                status: Invoice::STATUS_PAYING,
                payUrl: $result['code_url'] ?? null,
                message: 'WeChat Native order created',
            );
        }

        throw new \InvalidArgumentException(sprintf('Unsupported WeChat trade type: %s', $tradeType));
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        $app = $this->wechatService->getPayApp();

        try {
            $server = $app->getServer();

            $notifyResult = null;
            $server->handlePaid(function ($message, \Closure $next) use ($app, $request, &$notifyResult) {
                $app->getValidator()->validate($request);

                $notifyResult = new PaymentNotifyResult(
                    payment: self::getName(),
                    outTradeNo: $message->out_trade_no ?? '',
                    status: Invoice::STATUS_PAID,
                    amount: (int) ($message->amount['total'] ?? 0),
                    currency: $message->amount['currency'] ?? 'CNY',
                    transactionId: $message->transaction_id ?? null,
                    paidAt: isset($message->success_time)
                        ? new \DateTimeImmutable($message->success_time)
                        : new \DateTimeImmutable(),
                    rawData: $message->toArray(),
                    responseBody: json_encode(['code' => 'SUCCESS', 'message' => '成功']),
                );

                return $next($message);
            });

            $server->serve();

            if ($notifyResult === null) {
                throw new PaymentVerificationException('WeChat notify: unsupported event type.');
            }

            return $notifyResult;
        } catch (\Throwable $e) {
            throw new PaymentVerificationException(
                'WeChat notify verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult
    {
        $app = $this->wechatService->getPayApp();

        $outRefundNo = 'REF' . $invoice->getOutTradeNo() . '_' . date('YmdHis');
        $body = [
            'out_trade_no' => $invoice->getOutTradeNo(),
            'out_refund_no' => $outRefundNo,
            'reason' => $reason,
            'amount' => [
                'refund' => $amount,
                'total' => $invoice->getAmount(),
                'currency' => $invoice->getCurrency(),
            ],
        ];

        $response = $app->getClient()->postJson('v3/refund/domestic/refunds', $body);
        $result = $response->toArray(false);

        $refundStatus = match ($result['status'] ?? '') {
            'SUCCESS' => ($amount >= $invoice->getAmount() - $invoice->getRefundedAmount())
                ? Invoice::STATUS_REFUNDED
                : Invoice::STATUS_PARTIAL_REFUNDED,
            default => $invoice->getStatus(),
        };

        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $refundStatus,
            refundId: $result['refund_id'] ?? null,
            rawData: $result,
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new JsonResponse(
            json_decode($result->responseBody, true) ?? ['code' => 'SUCCESS', 'message' => '成功']
        );
    }
}
