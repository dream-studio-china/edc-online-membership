<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Service\Payment;

use App\Payment\Bridge\Wallet\WalletBalanceAdjustment;
use App\Payment\Bridge\Wallet\WalletBalanceAdjustmentPortInterface;
use App\Payment\Service\Adjustment\WalletBalanceAdjustmentProvider;
use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class WalletBalanceAdjustmentProviderTest extends TestCase
{
    public function testSupportsUsesScalarCurrency(): void
    {
        $service = $this->createMock(WalletBalanceAdjustmentPortInterface::class);
        $service->expects(self::once())->method('supports')->with('CNY', ['walletAmount' => 300])->willReturn(false);

        self::assertFalse($this->provider($service)->supports((new Invoice())->setCurrency('CNY'), 'mock', ['walletAmount' => 300]));
    }

    public function testApplyMapsInvoiceToWalletReference(): void
    {
        $invoice = (new Invoice())->setPayerUuid('payer')->setCurrency('CNY')->setAmount(1000)->setOutTradeNo('ORDER-1');
        $service = $this->createMock(WalletBalanceAdjustmentPortInterface::class);
        $service->expects(self::once())->method('apply')->willReturn(new WalletBalanceAdjustment(300, 'CNY', 'deduction-1'));
        $result = $this->provider($service)->apply(new PaymentAdjustmentContext($invoice, 'mock', 1000, 'CNY', ['walletAmount' => 300]));

        self::assertSame('deduction-1', $result->referenceId);
        self::assertSame(300, $result->amount);
    }

    public function testApplyRequiresPayer(): void
    {
        $this->expectExceptionMessage('Invoice has no payer for wallet deduction.');
        $this->provider($this->createMock(WalletBalanceAdjustmentPortInterface::class))->apply(new PaymentAdjustmentContext(new Invoice(), 'mock', 1000, 'CNY'));
    }

    private function provider(WalletBalanceAdjustmentPortInterface $service): WalletBalanceAdjustmentProvider
    {
        return new WalletBalanceAdjustmentProvider($service);
    }
}
