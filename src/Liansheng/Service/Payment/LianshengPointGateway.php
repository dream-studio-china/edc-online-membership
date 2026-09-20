<?php

declare(strict_types=1);

namespace App\Liansheng\Service\Payment;

use App\Liansheng\Service\LianshengServiceInterface;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LianshengPointGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly LianshengServiceInterface $lianshengService) {}

    public static function getName(): string
    {
        return Invoice::PAYMENT_LIANSHENG_POINT;
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Liansheng points payment amount must be positive.');
        }
        $mobile = $this->verifiedPayerMobile($invoice, 'payment');

        $reference = $invoice->getOutTradeNo();
        $response = $this->lianshengService->deductMemberPoints(
            mobile: $mobile,
            points: $amount,
            reference: $reference,
            remarks: mb_substr($invoice->getSubject() ?? 'Invoice payment', 0, 255),
            roomTable: 'ONLINE',
        );

        return new PaymentResult(
            invoice: $invoice,
            status: Invoice::STATUS_PAID,
            payload: [
                'gateway' => self::getName(),
                'transactionId' => $reference,
                'reference' => $reference,
                'points' => $amount,
                'providerCode' => $response['code'] ?? 0,
                'providerMessage' => is_string($response['msg'] ?? null) ? $response['msg'] : null,
            ],
            message: 'Liansheng points payment completed',
        );
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        throw new PaymentVerificationException('Liansheng points gateway is synchronous and does not accept notify callbacks.');
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Liansheng points refund amount must be positive.');
        }
        $remaining = $paidAmount - $invoice->getRefundedAmount();
        if ($amount > $remaining) {
            throw new \InvalidArgumentException('Liansheng points refund exceeds the paid remaining amount.');
        }

        $mobile = $this->verifiedPayerMobile($invoice, 'refund');
        $expiryDate = $this->lianshengService->getPointRefundExpiryDate();

        $cumulativeRefund = $invoice->getRefundedAmount() + $amount;
        $reference = sprintf('%s-R%d', $invoice->getOutTradeNo(), $cumulativeRefund);
        $response = $this->lianshengService->creditMemberPoints(
            mobile: $mobile,
            points: $amount,
            reference: $reference,
            remarks: mb_substr($reason, 0, 255),
            expiryDate: $expiryDate,
            roomTable: 'ONLINE',
        );

        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $amount >= $remaining ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PARTIAL_REFUNDED,
            refundId: $reference,
            rawData: [
                'reference' => $reference,
                'points' => $amount,
                'expiryDate' => $expiryDate->format('Y-m-d'),
                'providerCode' => $response['code'] ?? 0,
                'providerMessage' => is_string($response['msg'] ?? null) ? $response['msg'] : null,
            ],
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new Response($result->responseBody, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    private function verifiedPayerMobile(Invoice $invoice, string $operation): string
    {
        if ($invoice->getCurrency() !== Invoice::CURRENCY_LIANSHENG_POINT) {
            throw new \InvalidArgumentException(sprintf(
                'Liansheng points %s requires %s currency.',
                $operation,
                Invoice::CURRENCY_LIANSHENG_POINT,
            ));
        }

        $payer = $invoice->getPayer();
        if ($payer === null) {
            throw new \RuntimeException(sprintf('Invoice has no payer for Liansheng points %s.', $operation));
        }
        $mobile = trim((string) $payer->getPhone());
        if ($mobile === '' || !$payer->isPhoneVerified()) {
            throw new \RuntimeException(sprintf('Payer must have a verified phone for Liansheng points %s.', $operation));
        }

        return $mobile;
    }
}
