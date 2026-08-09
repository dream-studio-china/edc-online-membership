<?php

declare(strict_types=1);

namespace App\Tests\Wallet\Controller\Manage;

use App\Wallet\Entity\WalletTransaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Controller\Manage\TransferController;
use App\Wallet\Service\TransferResult;
use App\Wallet\Service\TransferServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class TransferControllerTest extends TestCase
{
    private TransferServiceInterface $service;
    private TransferController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TransferServiceInterface::class);
        $this->controller = new TransferController($this->service);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

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

    private function makeTransaction(int $id, int $amount, string $type): WalletTransaction
    {
        $tx = new WalletTransaction('uuid-' . $id, $amount, $type);
        $tx->markCompleted();
        $ref = new \ReflectionProperty(WalletTransaction::class, 'id');
        $ref->setValue($tx, $id);

        return $tx;
    }

    // ──────────────── createAction: guard clauses ────────────────

    public function testCreateActionRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', ['fromWalletId' => 1]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('fromWalletId, toWalletId, and amount are required', $body['message']);
    }

    public function testCreateActionRejectsNegativeAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => -50,
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Amount must be positive', $body['message']);
    }

    public function testCreateActionRejectsNonNumericAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 'abc',
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Amount must be positive', $body['message']);
    }

    public function testCreateActionZeroAmountShouldReportAmountNotPositive(): void
    {
        // KNOWN BUG: src/Wallet/Controller/Manage/TransferController.php:32 uses empty()
        // to check `amount`, so `0` (and the string "0") are treated as "missing" and the
        // request fails with the misleading 'fromWalletId, toWalletId, and amount are
        // required' message instead of 'Amount must be positive'. The HTTP code is 400
        // either way, but the message is wrong. See
        // docs/issues/coverage-2026-08-09/wallet-manage.md (BUG-3).
        $this->markTestSkipped(
            'Known bug (src/Wallet/Controller/Manage/TransferController.php:32): empty() treats '
            . 'amount 0 as "missing", so the wrong validation message is returned.'
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 0,
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Amount must be positive', $body['message']);
    }

    public function testCreateActionRejectsInvalidJson(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/api/v1/manage/transfers', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{not json');
        $requestStack->push($request);
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
    }

    // ──────────────── createAction: success path ────────────────

    public function testCreateActionSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => '7', 'toWalletId' => '8', 'amount' => '30000',
            'referenceId' => 'REF-1', 'description' => 'payout',
        ]));
        $this->injectDependencies($requestStack);

        $tx = $this->makeTransaction(42, 30000, WalletTransaction::TYPE_TRANSFER);
        $this->service->method('transfer')
            ->with(7, 8, 30000, 'REF-1', 'payout')
            ->willReturn(new TransferResult($tx, 70000, 30000));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Transfer completed', $body['message']);
        self::assertSame(42, $body['data']['transactionId']);
        self::assertSame('uuid-42', $body['data']['uuid']);
        self::assertSame(7, $body['data']['fromWalletId']);
        self::assertSame(8, $body['data']['toWalletId']);
        self::assertSame(30000, $body['data']['amount']);
        self::assertEquals(300.0, $body['data']['amountFloat']);
        self::assertSame('completed', $body['data']['status']);
        self::assertSame(70000, $body['data']['fromWalletBalanceAfter']);
        self::assertSame(30000, $body['data']['toWalletBalanceAfter']);
        self::assertIsArray($body['data']['createdAt']);
    }

    // ──────────────── createAction: exception branches ────────────────

    public function testCreateActionInsufficientFunds(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 999999,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(
            new InsufficientFundsException(1, 100, 999999)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(402, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(402, $body['code']);
        self::assertStringContainsString('Insufficient funds', $body['message']);
    }

    public function testCreateActionWalletFrozen(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(new WalletFrozenException(1));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(403, $body['code']);
        self::assertStringContainsString('frozen', $body['message']);
    }

    public function testCreateActionSameWallet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 1, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(new SameWalletTransferException());

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Cannot transfer to the same wallet', $body['message']);
    }

    public function testCreateActionInvalidArgument(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(
            new \InvalidArgumentException('Invalid reference id')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('Invalid reference id', $body['message']);
    }

    public function testCreateActionRuntimeNotFoundMapsTo404(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 999, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(
            new \RuntimeException('Source wallet #999 not found')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Source wallet #999 not found', $body['message']);
    }

    public function testCreateActionRuntimeErrorMapsTo500(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('transfer')->willThrowException(
            new \RuntimeException('Database connection lost')
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(500, $body['code']);
        self::assertSame('Database connection lost', $body['message']);
    }

    public function testCreateActionIdempotentReplayEchoesStoredAmount(): void
    {
        // KNOWN BUG: on an idempotent replay (existing referenceId) TransferService returns
        // the ORIGINAL stored transaction, but TransferController builds the response with the
        // NEW request amount as `data.amount` while `data.amountFloat`/transactionId come from
        // the stored transaction (src/Wallet/Controller/Manage/TransferController.php:53-54).
        // A replay with a different amount returns an internally inconsistent 201 body
        // (e.g. amount=99999 but amountFloat=500.0). See
        // docs/issues/coverage-2026-08-09/wallet-manage.md (BUG-4).
        $this->markTestSkipped(
            'Known bug (src/Wallet/Controller/Manage/TransferController.php:53): on idempotent '
            . 'replay the response echoes the request amount instead of the stored transaction amount.'
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 99999, 'referenceId' => 'REF-REPLAY',
        ]));
        $this->injectDependencies($requestStack);

        $tx = $this->makeTransaction(7, 50000, WalletTransaction::TYPE_TRANSFER);
        $this->service->method('transfer')
            ->with(1, 2, 99999, 'REF-REPLAY', null)
            ->willReturn(new TransferResult($tx, 50000, 0));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(50000, $body['data']['amount']);
        self::assertSame(500.0, $body['data']['amountFloat']);
    }

    public function testDepositActionRejectsMissingFields(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', ['amount' => 100]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('toWalletId and amount are required', $body['message']);
    }

    public function testDepositActionRejectsNonPositiveAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 1, 'amount' => -50,
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Amount must be positive', $body['message']);
    }

    public function testDepositActionZeroAmountShouldReportAmountNotPositive(): void
    {
        // KNOWN BUG: same empty() issue as createAction — deposit amount 0 is reported
        // as 'toWalletId and amount are required' instead of 'Amount must be positive'.
        // See docs/issues/coverage-2026-08-09/wallet-manage.md (BUG-3).
        $this->markTestSkipped(
            'Known bug (src/Wallet/Controller/Manage/TransferController.php:79): empty() treats '
            . 'deposit amount 0 as "missing", so the wrong validation message is returned.'
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 1, 'amount' => 0,
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Amount must be positive', $body['message']);
    }

    public function testDepositActionRejectsInvalidJson(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/api/v1/manage/transfers/deposit', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '###');
        $requestStack->push($request);
        $this->injectDependencies($requestStack);

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
    }

    // ──────────────── depositAction: success path ────────────────

    public function testDepositActionSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => '9', 'amount' => 50000, 'referenceId' => 'DEP-1',
        ]));
        $this->injectDependencies($requestStack);

        $tx = $this->makeTransaction(9, 50000, WalletTransaction::TYPE_DEPOSIT);
        $this->service->method('deposit')
            ->with(9, 50000, 'DEP-1', null)
            ->willReturn(new TransferResult($tx, 0, 50000));

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Deposit completed', $body['message']);
        self::assertSame(9, $body['data']['transactionId']);
        self::assertSame('uuid-9', $body['data']['uuid']);
        self::assertSame(9, $body['data']['toWalletId']);
        self::assertSame(50000, $body['data']['amount']);
        self::assertEquals(500.0, $body['data']['amountFloat']);
        self::assertSame('deposit', $body['data']['type']);
        self::assertSame('completed', $body['data']['status']);
        self::assertSame(50000, $body['data']['toWalletBalanceAfter']);
        self::assertIsArray($body['data']['createdAt']);
    }

    // ──────────────── depositAction: exception branches ────────────────

    public function testDepositActionWalletFrozen(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 1, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(new WalletFrozenException(1));

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(403, $body['code']);
        self::assertStringContainsString('frozen', $body['message']);
    }

    public function testDepositActionInvalidArgument(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 1, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new \InvalidArgumentException('Deposit amount must be positive')
        );

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('Deposit amount must be positive', $body['message']);
    }

    public function testDepositActionRuntimeNotFoundMapsTo404(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 999, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new \RuntimeException('Target wallet #999 not found')
        );

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Target wallet #999 not found', $body['message']);
    }

    public function testDepositActionRuntimeErrorMapsTo500(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('/api/v1/manage/transfers/deposit', [
            'toWalletId' => 1, 'amount' => 100,
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('deposit')->willThrowException(
            new \RuntimeException('Database connection lost')
        );

        $response = $this->controller->depositAction($requestStack->getCurrentRequest());

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(500, $body['code']);
        self::assertSame('Database connection lost', $body['message']);
    }
}
