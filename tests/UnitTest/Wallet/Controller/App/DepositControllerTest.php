<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\App;

use App\Identity\Entity\User;
use App\Wallet\Controller\App\DepositController;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Deposit\DepositService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class DepositControllerTest extends TestCase
{
    private DepositService $depositService;
    private WalletRepository $walletRepository;
    private VoucherRepository $voucherRepository;
    private DepositController $controller;
    private User $user;

    protected function setUp(): void
    {
        $this->depositService = $this->createMock(DepositService::class);
        $this->walletRepository = $this->createMock(WalletRepository::class);
        $this->voucherRepository = $this->createMock(VoucherRepository::class);

        $this->user = new User();
        $this->user->setEmail('dep@t.com')->setUsername('dep');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($this->user, 77);

        $this->controller = new DepositController(
            $this->depositService,
            $this->walletRepository,
            $this->voucherRepository,
        );
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            fn (string $id) => match ($id) {
                'security.token_storage' => $tokenStorage,
                default => null,
            }
        );

        $this->controller->setContainer($container);
        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function jsonRequest(string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function makeWallet(int $id, ?User $owner = null): Wallet
    {
        $wallet = new Wallet($owner ?? $this->user, 'CNY');
        $r = new \ReflectionProperty(Wallet::class, 'id');
        $r->setValue($wallet, $id);

        return $wallet;
    }

    private function makeAppliedVoucher(int $walletId, int $amount, ?User $owner = null): Voucher
    {
        $wallet = $this->makeWallet($walletId, $owner);
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            $amount,
            'CNY',
            'ref-1',
            'dep',
        );
        $voucher->markApplied('tx-1');

        return $voucher;
    }

    public function testCreateDepositIntoOwnWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/deposits', [
            'walletId' => 5, 'amount' => 50000, 'currency' => 'CNY',
            'referenceId' => 'APP-DEP-1', 'reason' => 'self top-up',
        ]));
        $this->injectDependencies($requestStack);

        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $this->user));
        $voucher = $this->makeAppliedVoucher(5, 50000, $this->user);
        $this->depositService->method('deposit')
            ->with(Voucher::VOUCHER_TYPE_MANUAL, 'APP-DEP-1', 5, 50000, 'CNY', 'APP-DEP-1', 'dep', 'self top-up')
            ->willReturn($voucher);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('applied', $body['data']['status']);
        self::assertSame(50000, $body['data']['amount']);
    }

    public function testCreateDepositIntoForeignWalletIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/deposits', [
            'walletId' => 5, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 88);
        $this->walletRepository->method('find')->with(5)->willReturn($this->makeWallet(5, $other));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Wallet not found', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateDepositRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/deposits', ['walletId' => 5]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('required', $body['message']);
    }

    public function testReverseOwnDeposit(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/deposits/uuid-1/reverse', [
            'reason' => 'self revert',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->makeAppliedVoucher(5, 30000, $this->user);
        $voucher->markReversed('rev-tx-1', 'self revert');
        $this->voucherRepository->method('findByUuid')->with('uuid-1')->willReturn($voucher);
        $this->depositService->method('reverse')->with('uuid-1', 'self revert')->willReturn($voucher);

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('reversed', $body['data']['status']);
    }

    public function testReverseForeignDepositIsForbidden(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/app/deposits/uuid-1/reverse', []));
        $this->injectDependencies($requestStack);

        $other = new User();
        $other->setEmail('other@t.com')->setUsername('other');
        $rId = new \ReflectionProperty(User::class, 'id');
        $rId->setValue($other, 88);
        $this->voucherRepository->method('findByUuid')->with('uuid-1')
            ->willReturn($this->makeAppliedVoucher(5, 30000, $other));

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Voucher not found', $body['message']);
    }
}
