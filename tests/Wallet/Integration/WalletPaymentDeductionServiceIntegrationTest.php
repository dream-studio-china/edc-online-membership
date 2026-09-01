<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Integration;

use App\Identity\Main\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\DTO\WalletPaymentReference;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\WalletPaymentDeduction;
use App\Wallet\Repository\WalletPaymentDeductionRepository;
use App\Wallet\Service\Payment\WalletPaymentDeductionService;
use Doctrine\ORM\EntityManagerInterface;

final class WalletPaymentDeductionServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private WalletPaymentDeductionService $deductionService;
    private WalletPaymentDeductionRepository $deductionRepository;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->deductionService = static::getContainer()->get(WalletPaymentDeductionService::class);
        $this->deductionRepository = static::getContainer()->get(WalletPaymentDeductionRepository::class);
    }

    public function testApplyReleaseAndRefundAreIdempotent(): void
    {
        [$payer, $wallet] = $this->createUserWallet('deduct-payer@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-system@example.com', 0);
        $payment = $this->payment($payer, 'invoice-1', 600);

        $deduction = $this->deductionService->apply($payment, 250, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(WalletPaymentDeduction::STATUS_APPLIED, $deduction->getStatus());
        self::assertSame(250, $this->deductionService->sumAppliedAmount($payment->invoiceId));
        self::assertSame($deduction, $this->deductionService->findApplied($payment->invoiceId));
        self::assertSame($deduction, $this->deductionRepository->findWalletBalanceByInvoiceId($payment->invoiceId));

        self::assertSame($deduction->getId(), $this->deductionService->apply($payment, 250, 'CNY', ['systemWalletId' => $systemWallet->getId()])->getId());
        $this->deductionService->release($payment->invoiceId, 'release');
        self::assertNull($this->deductionService->findApplied($payment->invoiceId));

        $payment2 = $this->payment($payer, 'invoice-2', 600);
        $this->deductionService->apply($payment2, 300, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        $this->deductionService->refund($payment2->invoiceId, 'refund');
        $this->em->refresh($wallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1000, $wallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    public function testParsesAndAppliesOptions(): void
    {
        [$payer] = $this->createUserWallet('deduct-options@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-options-system@example.com', 0);
        $payment = $this->payment($payer, 'invoice-options', 500);

        self::assertNull($this->deductionService->createRequestFromOptions('CNY', []));
        $deduction = $this->deductionService->applyFromOptions($payment, ['walletAmount' => 200, 'systemWalletId' => $systemWallet->getId()]);
        self::assertNotNull($deduction);
        self::assertSame($payment->invoiceId, $deduction->getInvoiceId());
        self::assertSame($payment->invoiceNo, $deduction->getInvoiceNo());
        self::assertSame($payer->getId(), $deduction->getPayerId());
    }

    public function testRejectsInvalidDeduction(): void
    {
        [$payer] = $this->createUserWallet('deduct-invalid@example.com', 1000);
        $payment = $this->payment($payer, 'invoice-invalid', 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($payment, 501, 'CNY', ['systemWalletId' => 1]);
    }

    /** @return array{User, Wallet} */
    private function createUserWallet(string $email, int $balance): array
    {
        $user = new User();
        $user->setEmail($email)->setUsername(strstr($email, '@', true))->setPassword('password')->setRoles(['ROLE_USER']);
        $wallet = new Wallet($user->getUuid(), 'CNY');
        $this->em->persist($user);
        $this->em->persist($wallet);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', ['balance' => $balance, 'id' => $wallet->getId()]);
        $this->em->refresh($wallet);

        return [$user, $wallet];
    }

    private function payment(User $payer, string $invoiceId, int $amount): WalletPaymentReference
    {
        return new WalletPaymentReference($invoiceId, 'NO-' . $invoiceId, $payer->getId() ?? throw new \LogicException('Payer must be persisted.'), $payer->getUuid(), $amount, 'CNY', 'Wallet deduction');
    }
}
