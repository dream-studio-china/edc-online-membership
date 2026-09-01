<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Service\Payment;

use App\Wallet\Repository\WalletPaymentDeductionRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\DTO\WalletPaymentReference;
use App\Wallet\Entity\WalletPaymentDeduction;
use App\Wallet\Service\Payment\WalletPaymentDeductionService;
use App\Wallet\Service\TransferServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WalletPaymentDeductionServiceOptionsTest extends TestCase
{
    private function service(): WalletPaymentDeductionService
    {
        return new WalletPaymentDeductionService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(WalletPaymentDeductionRepository::class),
            $this->createMock(WalletRepository::class),
            $this->createMock(TransferServiceInterface::class),
        );
    }

    public function testWalletAmountOptionCreatesACurrencyAwareBalanceRequest(): void
    {
        $request = $this->service()->createRequestFromOptions('CNY', [
            'walletAmount' => '250',
            'currency' => 'USD',
            'source' => 'checkout',
        ]);

        self::assertNotNull($request);
        self::assertSame('wallet_balance', $request->type);
        self::assertSame(250, $request->amount);
        self::assertSame('USD', $request->currency);
        self::assertSame('checkout', $request->options['source']);
    }

    public function testInvalidWalletAmountDoesNotCreateADeductionRequest(): void
    {
        self::assertNull($this->service()->createRequestFromOptions('CNY', ['walletAmount' => 0]));
        self::assertNull($this->service()->createRequestFromOptions('CNY', ['walletAmount' => -1]));
        self::assertNull($this->service()->createRequestFromOptions('CNY', []));
    }

    public function testNestedDeductionOptionsAreMergedIntoTheRequest(): void
    {
        $request = $this->service()->createRequestFromOptions('CNY', [
            'channel' => 'app',
            'deduction' => [
                'amount' => 125,
                'currency' => 'CNY',
                'options' => ['systemWalletId' => 3],
            ],
        ]);

        self::assertNotNull($request);
        self::assertSame(125, $request->amount);
        self::assertSame('CNY', $request->currency);
        self::assertSame('app', $request->options['channel']);
        self::assertSame(3, $request->options['systemWalletId']);
    }

    public function testApplyRejectsInvalidDeductionRequestsBeforeLookingUpWallets(): void
    {
        $payment = new WalletPaymentReference('invoice-1', 'PAY-1', 1, 'owner-1', 100, 'CNY', 'Order');

        $invalidRequests = [
            [0, 'CNY', WalletPaymentDeduction::TYPE_WALLET_BALANCE, 'Deduction amount must be positive.'],
            [101, 'CNY', WalletPaymentDeduction::TYPE_WALLET_BALANCE, 'Deduction amount cannot exceed invoice amount.'],
            [100, 'USD', WalletPaymentDeduction::TYPE_WALLET_BALANCE, 'Deduction currency must match invoice currency.'],
            [100, 'CNY', 'credit_card', 'Unsupported deduction type: credit_card'],
        ];

        foreach ($invalidRequests as [$amount, $currency, $type, $message]) {
            try {
                $this->service()->apply($payment, $amount, $currency, [], $type);
                self::fail('Expected invalid deduction request to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
