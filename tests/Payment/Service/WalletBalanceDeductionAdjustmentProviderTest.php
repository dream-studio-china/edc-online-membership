<?php

declare(strict_types=1);

namespace App\Tests\Payment\Service;

use App\Payment\DTO\DeductionRequest;
use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use App\Payment\Repository\DeductionRepository;
use App\Payment\Service\Adjustment\WalletBalanceDeductionAdjustmentProvider;
use App\Payment\Service\DeductionService;
use PHPUnit\Framework\TestCase;

final class WalletBalanceDeductionAdjustmentProviderTest extends TestCase
{
    public function testSupportsUsesDeductionRequestParsing(): void
    {
        $invoice = new Invoice();
        $deductionService = $this->createMock(DeductionService::class);
        $deductionService->method('createRequestFromOptions')
            ->willReturnOnConsecutiveCalls(
                new DeductionRequest(Deduction::TYPE_WALLET_BALANCE, 300, 'CNY'),
                null,
            );

        $provider = new WalletBalanceDeductionAdjustmentProvider($deductionService, $this->createMock(DeductionRepository::class));

        self::assertTrue($provider->supports($invoice, 'mock', ['walletAmount' => 300]));
        self::assertFalse($provider->supports($invoice, 'mock', []));
    }

    public function testApplyReturnsGenericAdjustmentResult(): void
    {
        $invoice = new Invoice();
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $deductionService = $this->createMock(DeductionService::class);
        $deductionService->method('applyFromOptions')->willReturn($deduction);

        $provider = new WalletBalanceDeductionAdjustmentProvider($deductionService, $this->createMock(DeductionRepository::class));
        $result = $provider->apply(new PaymentAdjustmentContext($invoice, 'mock', 1000, 'CNY', ['walletAmount' => 300]));

        self::assertSame(Deduction::TYPE_WALLET_BALANCE, $result->provider);
        self::assertSame(300, $result->amount);
        self::assertSame('CNY', $result->currency);
        self::assertSame('deduction-ref-1', $result->referenceId);
        self::assertSame('txn-1', $result->payload['transactionId']);
        self::assertSame(Deduction::STATUS_APPLIED, $result->payload['status']);
    }

    public function testApplyRejectsMissingDeductionRequest(): void
    {
        $deductionService = $this->createMock(DeductionService::class);
        $deductionService->method('applyFromOptions')->willReturn(null);
        $provider = new WalletBalanceDeductionAdjustmentProvider($deductionService, $this->createMock(DeductionRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet balance deduction request is missing.');

        $provider->apply(new PaymentAdjustmentContext(new Invoice(), 'mock', 1000, 'CNY'));
    }

    public function testAppliedReturnsEmptyOrGenericAdjustmentResult(): void
    {
        $invoice = new Invoice();
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $deductionService = $this->createMock(DeductionService::class);
        $deductionService->method('findApplied')->willReturnOnConsecutiveCalls(null, $deduction);

        $provider = new WalletBalanceDeductionAdjustmentProvider($deductionService, $this->createMock(DeductionRepository::class));

        self::assertSame([], $provider->applied($invoice));
        self::assertSame(300, $provider->applied($invoice)[0]->amount);
    }

    public function testReleaseAndRefundReturnReversedAdjustmentResults(): void
    {
        $invoice = new Invoice();
        $deduction = self::deduction($invoice)->markApplied('txn-1');
        $released = self::deduction($invoice)->markApplied('txn-1')->markReleased('release-txn-1', 'cancel');
        $refunded = self::deduction($invoice)->markApplied('txn-1')->markRefunded('refund-txn-1', 'refund');

        $deductionRepository = $this->createMock(DeductionRepository::class);
        $deductionRepository->method('findOneBy')->willReturn($deduction);
        $deductionService = $this->createMock(DeductionService::class);
        $deductionService->method('release')->with($invoice, 'cancel')->willReturn($released);
        $deductionService->method('refund')->with($invoice, 'refund')->willReturn($refunded);

        $provider = new WalletBalanceDeductionAdjustmentProvider($deductionService, $deductionRepository);
        $adjustment = new PaymentAdjustmentResult(Deduction::TYPE_WALLET_BALANCE, 300, 'CNY', 'deduction-ref-1');

        $releaseResult = $provider->release($adjustment, 'cancel');
        self::assertSame(Deduction::STATUS_RELEASED, $releaseResult->payload['status']);
        self::assertSame('release-txn-1', $releaseResult->payload['reversalTransactionId']);

        $refundResult = $provider->refund($adjustment, 'refund');
        self::assertSame(Deduction::STATUS_REFUNDED, $refundResult->payload['status']);
        self::assertSame('refund-txn-1', $refundResult->payload['reversalTransactionId']);
    }

    public function testReleaseRejectsUnknownDeductionReference(): void
    {
        $deductionRepository = $this->createMock(DeductionRepository::class);
        $deductionRepository->method('findOneBy')->willReturn(null);
        $provider = new WalletBalanceDeductionAdjustmentProvider($this->createMock(DeductionService::class), $deductionRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet balance deduction "missing-ref" not found.');

        $provider->release(new PaymentAdjustmentResult(Deduction::TYPE_WALLET_BALANCE, 300, 'CNY', 'missing-ref'), 'cancel');
    }

    private static function deduction(Invoice $invoice): Deduction
    {
        return new Deduction($invoice, 300, 'CNY', 'deduction-ref-1');
    }
}
