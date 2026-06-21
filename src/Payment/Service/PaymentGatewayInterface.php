<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentGatewayInterface
{
    public static function getName(): string;
    public function pay(Invoice $invoice, array $options = []): PaymentResult;
    public function notify(Request $request): PaymentNotifyResult;
    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult;
    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response;
}
