<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\Manage;

use App\Identity\Entity\User;
use App\Wallet\Controller\Manage\DepositController;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Service\Deposit\DepositService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class DepositControllerTest extends TestCase
{
    private DepositService $service;
    private DepositController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(DepositService::class);
        $this->controller = new DepositController($this->service);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
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

    private function createAppliedVoucher(int $amount, string $suffix): Voucher
    {
        $user = new User();
        $user->setEmail('depctl@t.com')->setUsername('depctl');
        $wallet = new Wallet($user, 'CNY');
        $rId = new \ReflectionProperty(Wallet::class, 'id');
        $rId->setValue($wallet, 9);

        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-' . $suffix,
            $amount,
            'CNY',
            'ref-' . $suffix,
            'admin',
        );
        $voucher->markApplied('tx-' . $suffix);

        return $voucher;
    }

    // ──────────────── createAction ────────────────

    #[Group('low-value')]
    public function testCreateActionRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', ['walletId' => 1]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('walletId, amount, currency, and referenceId are required', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRejectsNegativeAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 1, 'amount' => -50, 'currency' => 'CNY', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('walletId, amount, currency, and referenceId are required', $body['message']);
    }

    public function testCreateActionSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 9, 'amount' => 50000, 'currency' => 'CNY',
            'referenceId' => 'DEP-1', 'reason' => 'Manual funding',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->createAppliedVoucher(50000, 'ctl1');
        $this->service->method('deposit')
            ->with(Voucher::VOUCHER_TYPE_MANUAL, 'DEP-1', 9, 50000, 'CNY', 'DEP-1', 'system', 'Manual funding')
            ->willReturn($voucher);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Deposit completed', $body['message']);
        self::assertSame('applied', $body['data']['status']);
        self::assertSame(50000, $body['data']['amount']);
        self::assertEquals(500.0, $body['data']['amountFloat']);
        self::assertSame('CNY', $body['data']['currency']);
    }

    public function testCreateActionWalletFrozen(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 1, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(new WalletFrozenException(1));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('frozen', $body['message']);
    }

    public function testCreateActionInvalidArgument(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 1, 'amount' => 100, 'currency' => 'USD', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new \InvalidArgumentException('Currency mismatch')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Currency mismatch', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateActionRuntimeNotFoundMapsTo404(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 999, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new \RuntimeException('Target wallet #999 not found')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Target wallet #999 not found', $body['message']);
    }

    public function testCreateActionInsufficientFundsMapsTo402(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits', [
            'walletId' => 1, 'amount' => 100, 'currency' => 'CNY', 'referenceId' => 'r1',
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new InsufficientFundsException(1, 50, 100)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(402, $response->getStatusCode());
    }

    // ──────────────── reverseAction ────────────────

    public function testReverseActionSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits/uuid-1/reverse', [
            'reason' => 'Admin correction',
        ]));
        $this->injectDependencies($requestStack);

        $voucher = $this->createAppliedVoucher(30000, 'rev1');
        $voucher->markReversed('rev-tx-rev1', 'Admin correction');
        $this->service->method('reverse')->with('uuid-1', 'Admin correction')->willReturn($voucher);

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Deposit reversed', $body['message']);
        self::assertSame('reversed', $body['data']['status']);
        self::assertSame('rev-tx-rev1', $body['data']['reversalTransactionId']);
    }

    public function testReverseActionVoucherNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits/missing/reverse', []));
        $this->injectDependencies($requestStack);

        $this->service->method('reverse')->willThrowException(
            new \RuntimeException('Voucher "missing" not found.')
        );

        $response = $this->controller->reverseAction('missing', $requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Voucher "missing" not found.', $body['message']);
    }

    public function testReverseActionInvalidStateMapsTo409(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/deposits/uuid-1/reverse', []));
        $this->injectDependencies($requestStack);

        $this->service->method('reverse')->willThrowException(
            new \LogicException('Voucher cannot be reversed from status "pending".')
        );

        $response = $this->controller->reverseAction('uuid-1', $requestStack->getCurrentRequest());

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('pending', $body['message']);
    }
}
