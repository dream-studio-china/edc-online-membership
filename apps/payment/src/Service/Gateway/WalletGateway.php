<?php

declare(strict_types=1);

namespace App\Payment\Service\Gateway;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use CrudPlatform\IntegrationContracts\Wallet\WalletTransferPortInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WalletGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly WalletTransferPortInterface $walletTransfer, #[Autowire('%payment.system_wallet_id%')] private readonly ?int $systemWalletId = null) {}

    public static function getName(): string { return Invoice::PAYMENT_WALLET; }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $transactionId = $this->walletTransfer->debitOwner($this->payerUuid($invoice, 'payment'), $invoice->getCurrency(), $this->systemWalletId($options, 'payment'), $amount, 'invoice-pay-' . $invoice->getOutTradeNo(), $invoice->getSubject() ?? ('Payment for invoice ' . $invoice->getOutTradeNo()));
        return new PaymentResult(
            $invoice,
            Invoice::STATUS_PAID,
            payload: ['transactionId' => $transactionId],
            message: 'Wallet payment completed',
        );
    }

    public function notify(Request $request): PaymentNotifyResult { throw new PaymentVerificationException('Wallet gateway does not accept external notify callbacks.'); }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $transactionId = $this->walletTransfer->creditOwner($this->payerUuid($invoice, 'refund'), $invoice->getCurrency(), $this->systemWalletId($options, 'refund'), $amount, 'invoice-refund-' . $invoice->getOutTradeNo() . '-' . ($invoice->getRefundedAmount() + $amount), $reason);
        return new PaymentRefundResult($invoice, $amount, $amount >= ($paidAmount - $invoice->getRefundedAmount()) ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PARTIAL_REFUNDED, $transactionId, ['reason' => $reason, 'transactionId' => $transactionId]);
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response { return new Response($result->responseBody, 200, ['Content-Type' => 'text/plain']); }

    /** @param array<string, mixed> $options */
    private function systemWalletId(array $options, string $operation): int
    {
        $walletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($walletId <= 0) { throw new \InvalidArgumentException(sprintf('systemWalletId is required for wallet %s.', $operation)); }
        return $walletId;
    }

    private function payerUuid(Invoice $invoice, string $operation): string
    {
        $payerUuid = $invoice->getPayerUuid();
        if ($payerUuid === null) { throw new \RuntimeException(sprintf('Invoice has no payer for wallet %s.', $operation)); }
        return $payerUuid;
    }
}
