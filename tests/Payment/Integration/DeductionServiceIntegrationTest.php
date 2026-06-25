<?php

declare(strict_types=1);

namespace App\Tests\Payment\Integration;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use App\Payment\Repository\DeductionRepository;
use App\Payment\Service\DeductionService;
use App\Payment\Service\InvoiceServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;

final class DeductionServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $invoiceService;
    private DeductionService $deductionService;
    private DeductionRepository $deductionRepository;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoiceService = static::getContainer()->get(InvoiceServiceInterface::class);
        $this->deductionService = static::getContainer()->get(DeductionService::class);
        $this->deductionRepository = static::getContainer()->get(DeductionRepository::class);
    }

    public function testApplyReleaseAndRefundAreIdempotent(): void
    {
        [$payer, $wallet] = $this->createUserWallet('deduct-payer@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 600);

        $deduction = $this->deductionService->apply($invoice, 250, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(Deduction::STATUS_APPLIED, $deduction->getStatus());
        self::assertSame(250, $this->deductionService->sumAppliedAmount($invoice));
        self::assertSame($deduction, $this->deductionService->findApplied($invoice));
        self::assertSame($deduction, $this->deductionRepository->findWalletBalanceByInvoice($invoice));

        $same = $this->deductionService->apply($invoice, 250, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame($deduction->getId(), $same->getId());

        $this->em->refresh($wallet);
        $this->em->refresh($systemWallet);
        self::assertSame(750, $wallet->getBalance());
        self::assertSame(250, $systemWallet->getBalance());

        $this->deductionService->release($invoice, 'release');
        $this->em->refresh($wallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1000, $wallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
        self::assertNull($this->deductionService->findApplied($invoice));

        $invoice2 = $this->createInvoice($payer, 600);
        $this->deductionService->apply($invoice2, 300, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        $this->deductionService->refund($invoice2, 'refund');
        $this->em->refresh($wallet);
        $this->em->refresh($systemWallet);
        self::assertSame(1000, $wallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    public function testCreateRequestFromOptionsBranches(): void
    {
        [$payer] = $this->createUserWallet('deduct-options@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        self::assertNull($this->deductionService->createRequestFromOptions($invoice, []));
        self::assertNull($this->deductionService->createRequestFromOptions($invoice, ['walletAmount' => 0]));
        self::assertNull($this->deductionService->applyFromOptions($invoice, []));
        self::assertNull($this->deductionService->refund($invoice, 'no applied deduction'));
        self::assertFalse($this->deductionService->hasApplied($invoice));

        $request = $this->deductionService->createRequestFromOptions($invoice, [
            'deduction' => [
                'type' => Deduction::TYPE_WALLET_BALANCE,
                'amount' => 200,
                'currency' => 'CNY',
                'options' => ['systemWalletId' => 123],
            ],
        ]);

        self::assertNotNull($request);
        self::assertSame(Deduction::TYPE_WALLET_BALANCE, $request->type);
        self::assertSame(200, $request->amount);
        self::assertSame('CNY', $request->currency);
        self::assertSame(123, $request->options['systemWalletId']);
    }

    public function testReleasedDeductionPreventsReapply(): void
    {
        [$payer] = $this->createUserWallet('deduct-reapply@example.com', 1000);
        [, $systemWallet] = $this->createUserWallet('deduct-reapply-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 500);

        $this->deductionService->apply($invoice, 200, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
        $this->deductionService->release($invoice, 'release');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice deduction already exists with status "released".');
        $this->deductionService->apply($invoice, 200, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
    }

    public function testValidationBranches(): void
    {
        [$payer] = $this->createUserWallet('deduct-validate@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($invoice, 100, 'USD', ['systemWalletId' => 1]);
    }

    public function testAmountCannotExceedInvoice(): void
    {
        [$payer] = $this->createUserWallet('deduct-exceed@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($invoice, 501, 'CNY', ['systemWalletId' => 1]);
    }

    public function testUnsupportedTypeIsRejected(): void
    {
        [$payer] = $this->createUserWallet('deduct-type@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($invoice, 100, 'CNY', ['systemWalletId' => 1], 'coupon');
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        [$payer] = $this->createUserWallet('deduct-positive@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($invoice, 0, 'CNY', ['systemWalletId' => 1]);
    }

    public function testApplyRequiresPayer(): void
    {
        $invoice = $this->createPersistedInvoice(null, 500);

        $this->expectException(\RuntimeException::class);
        $this->deductionService->apply($invoice, 100, 'CNY', ['systemWalletId' => 1]);
    }

    public function testApplyRequiresSystemWalletId(): void
    {
        [$payer] = $this->createUserWallet('deduct-system-missing@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->apply($invoice, 100, 'CNY');
    }

    public function testApplyRequiresPayerWallet(): void
    {
        $payer = $this->createUser('deduct-wallet-missing@example.com');
        $invoice = $this->createInvoice($payer, 500);

        $this->expectException(\RuntimeException::class);
        $this->deductionService->apply($invoice, 100, 'CNY', ['systemWalletId' => 1]);
    }

    public function testApplyMarksDeductionFailedWhenTransferFails(): void
    {
        [$payer] = $this->createUserWallet('deduct-transfer-fails@example.com', 100);
        [, $systemWallet] = $this->createUserWallet('deduct-transfer-fails-system@example.com', 0);
        $invoice = $this->createInvoice($payer, 500);

        try {
            $this->deductionService->apply($invoice, 200, 'CNY', ['systemWalletId' => $systemWallet->getId()]);
            self::fail('Expected wallet transfer to fail.');
        } catch (\Throwable) {
        }

        $deduction = $this->deductionRepository->findWalletBalanceByInvoice($invoice);
        self::assertNotNull($deduction);
        self::assertSame(Deduction::STATUS_FAILED, $deduction->getStatus());
        self::assertNotEmpty($deduction->getMetadata()['failedReason']);
    }

    public function testReleaseRequiresPayer(): void
    {
        $invoice = $this->createPersistedInvoice(null, 500);
        $this->persistAppliedDeduction($invoice, 100, ['toWalletId' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->deductionService->release($invoice, 'release');
    }

    public function testReleaseRequiresSystemWalletId(): void
    {
        [$payer] = $this->createUserWallet('deduct-reverse-system@example.com', 1000);
        $invoice = $this->createInvoice($payer, 500);
        $this->persistAppliedDeduction($invoice, 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->deductionService->release($invoice, 'release');
    }

    public function testReleaseRequiresPayerWallet(): void
    {
        $payer = $this->createUser('deduct-reverse-wallet@example.com');
        $invoice = $this->createInvoice($payer, 500);
        $this->persistAppliedDeduction($invoice, 100, ['toWalletId' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->deductionService->release($invoice, 'release');
    }

    private function createInvoice(User $payer, int $amount): Invoice
    {
        return $this->invoiceService->createInvoice(new CreateInvoiceRequest('deduction_test', uniqid('src-', true), Invoice::SCENE_ORDER, $amount, 'CNY', $payer));
    }

    private function createPersistedInvoice(?User $payer, int $amount): Invoice
    {
        $invoice = (new Invoice())
            ->setSourceType('deduction_test')
            ->setSourceId(uniqid('src-', true))
            ->setScene(Invoice::SCENE_ORDER)
            ->setAmount($amount)
            ->setCurrency('CNY')
            ->setPayer($payer);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    private function persistAppliedDeduction(Invoice $invoice, int $amount, ?array $metadata = null): Deduction
    {
        $deduction = new Deduction($invoice, $amount, 'CNY', uniqid('manual-deduction-', true));
        $deduction->markApplied(uniqid('tx-', true), $metadata);
        $this->em->persist($deduction);
        $this->em->flush();

        return $deduction;
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword('password');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array{User, Wallet} */
    private function createUserWallet(string $email, int $balance): array
    {
        $user = $this->createUser($email);
        $wallet = new Wallet($user, 'CNY');
        $this->em->persist($wallet);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', ['balance' => $balance, 'id' => $wallet->getId()]);
        $this->em->refresh($wallet);

        return [$user, $wallet];
    }
}
