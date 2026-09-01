# Testing

## 1. Test Structure

The platform uses **PHPUnit 12.5** with two tiers of tests:

| Tier | Location | Database | Description |
|------|----------|----------|-------------|
| **Root Integration** | `tests/` | SQLite `var/test.db` | Cross-module integration tests for the monolith (963 tests) |
| **Per-App Unit** | `apps/{name}/tests/` | SQLite `var/test.db` | Service-specific unit + integration tests |

All test suites use **SQLite** in-memory databases. No MySQL or Docker is required
for local testing. The test environment is configured via `phpunit.xml` and
`APP_ENV=test`.

## 2. Per-App Test Suites

| App | Location | Test Count | Coverage Artifact |
|-----|----------|-----------|-------------------|
| **Common** | `apps/common/tests/` | 74 | `build/coverage/common.xml` |
| **Identity** | `apps/identity/tests/` | 209 | `build/coverage/identity.xml` |
| **Trade** | `apps/trade/tests/` | 412 | `build/coverage/trade.xml` |
| **Wallet** | `apps/wallet/tests/` | 60 | `build/coverage/wallet.xml` |
| **Payment** | `apps/payment/tests/` | 37 | `build/coverage/payment.xml` |
| **Store** | `apps/store/tests/` | 20 | `build/coverage/store.xml` |
| **Inventory** | `apps/inventory/tests/` | 11 | `build/coverage/inventory.xml` |

### What Each Suite Tests

#### Common (`apps/common/tests/`)
- Entity tests: Category, Comment, Content, Media, Page, Picture, Setting, Tag
- Media upload/delete controller integration
- Picture CRUD integration
- Storage service tests (LocalStorage, QiniuStorage)
- InitQiniuSettingsCommand

#### Identity (`apps/identity/tests/`)
- Auth controller (login, register, refresh, logout)
- OTP controller (request, verify)
- TokenManager (JWT creation, refresh rotation, reuse detection)
- UserService (register, password hashing, profile update)
- Wechat module (login flow, WechatUser, auth service)
- Controller integration tests

#### Trade (`apps/trade/tests/`)
- Entity tests: Product, Specification, Order, OrderItem
- OrderService (create, pay, refund, fulfill, calculate prices)
- Pricing calculators (Base, Quantity, TotalAggregator, Promotion)
- Promotion DSL: Lexer, Parser, Evaluator, 7 strategies
- PromotionCalculator integration
- Controller integration (products, orders, specifications)
- Message handler tests (Store acceptance/rejection consumers)

#### Wallet (`apps/wallet/tests/`)
- Entity tests: Wallet, WalletTransaction
- WalletService (create, balance, freeze)
- TransferService (transfer, deposit with idempotency)
- Payment integration (WalletPaymentDeduction, WalletGateway)
- API regression tests
- Exception tests (InsufficientFundsException, etc.)

#### Payment (`apps/payment/tests/`)
- Gateway tests (MockGateway, WalletGateway, WechatPayGateway)
- PaymentGatewayRegistry (autowire discovery)
- Adjustment provider tests (WalletBalanceAdjustmentProvider)
- Invoice entity and service tests

#### Store (`apps/store/tests/`)
- Store entity/service tests
- Trade→Store→Trade integration flow
- Store-Inventory integration (reservation flows)
- Message handler tests

#### Inventory (`apps/inventory/tests/`)
- Entity tests (Material, Stock, Recipe, Reservation)
- Service tests (reservation processing, recipe resolution)
- Integration tests (reservation request flow)
- Message handler tests
- API tests (materials, recipes, stocks)

## 3. Root Integration Tests (`tests/`)

963 cross-module tests covering:

- **Core framework**: BaseService, ExpressionDqlParser, FlatNormalizer, RestController
- **Controller tests**: System introspection, entity metadata, router
- **LocaleListener**: Language detection and translation
- **Cross-service integration**: Full workflow tests spanning multiple modules

### Test Organization

```
tests/
├── Core/
│   ├── Controller/System/
│   │   ├── EntityControllerTest.php
│   │   └── RouterControllerTest.php
│   ├── Parser/
│   │   └── ExpressionDqlParserTest.php
│   ├── Serializer/
│   │   └── FlatNormalizerTest.php
│   ├── Service/
│   │   └── BaseServiceTest.php
│   └── EventListener/
│       └── LocaleListenerTest.php
├── Integration/
│   ├── Common/
│   ├── Identity/
│   ├── Trade/
│   ├── Store/
│   ├── Payment/
│   ├── Wallet/
│   └── Inventory/
└── bootstrap.php
```

## 4. Running Tests Locally

### PHP Version

The recommended local PHP is **Homebrew PHP 8.5** at:
```
/opt/homebrew/opt/php@8.5/bin/php
```

Default system `php` may point to PHP 7.4 (macOS), which is insufficient.

### Root Tests

```bash
# All root tests
/opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit

# Specific test file
/opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit tests/Core/Controller/System/EntityControllerTest.php

# With coverage
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit --coverage-html var/coverage
```

### Per-App Tests

```bash
# Example: Trade tests
cd apps/trade
/opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit

# With coverage
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit --coverage-clover ../../build/coverage/trade.xml
```

### Docker Tests

```bash
# Root tests in Docker
docker compose exec app php vendor/bin/phpunit

# Per-app tests in Docker
docker compose exec trade-app php vendor/bin/phpunit
```

### Smoke Scripts

```bash
# API smoke test (requires running app)
bash scripts/tests/api-smoke.sh

# Store orchestration smoke (requires running Store + Trade)
bash scripts/tests/store-smoke.sh

# Trade workflow demo (generates 100 orders into SQLite)
/opt/homebrew/opt/php@8.5/bin/php scripts/tests/demo-trade-workflow.php
```

## 5. Coverage

### Current State

- **Aggregate coverage**: **91.36%** (via `phpcov merge`)
- **Total tests**: 1785
- **Total assertions**: 6098

### Coverage Commands

```bash
# Root coverage
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit --coverage-clover build/coverage/root.xml

# Per-app coverage (example: Trade)
cd apps/trade
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit --coverage-clover ../../build/coverage/trade.xml

# Merge and check gate
phpcov merge build/coverage --clover build/coverage/merged.xml
# Gate is ≥ 90%
```

### HTML Coverage Report

```bash
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit --coverage-html var/coverage
# Open var/coverage/index.html in browser
```

## 6. Writing Tests

### Unit Tests (Service Layer)

Located in per-app `tests/` directories. Test business logic in isolation:

```php
<?php

declare(strict_types=1);

namespace App\Wallet\Tests\Service;

use App\Wallet\Entity\Wallet;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Service\WalletService;
use PHPUnit\Framework\TestCase;

final class WalletServiceTest extends TestCase
{
    private WalletService $walletService;
    private EntityManagerInterface $entityManager; // mocked

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->walletService = new WalletService($this->entityManager);
    }

    public function testTransferFailsWhenInsufficientFunds(): void
    {
        $source = new Wallet(/* ... */, balance: 1000);  // cents
        $target = new Wallet(/* ... */, balance: 0);

        $this->expectException(InsufficientFundsException::class);
        $this->walletService->transfer($source, $target, 2000); // 2000 > 1000
    }
}
```

### Integration Tests (Controller/API Level)

Located in per-app `tests/` directories. Test the HTTP layer:

```php
<?php

declare(strict_types=1);

namespace App\Trade\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testCreateOrderReturns201(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/app/orders', [
            'json' => [
                'items' => [
                    ['specificationId' => 'uuid-here', 'quantity' => 2],
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);
    }
}
```

### Root Integration Tests

Located in `tests/Integration/`. These test cross-service workflows and core
framework components:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderWorkflowTest extends WebTestCase
{
    public function testFullOrderLifecycle(): void
    {
        $client = static::createClient();
        // 1. Login
        // 2. Create order
        // 3. Pay order
        // 4. Fulfill order
        // 5. Verify wallet balance changes
    }
}
```

### Fixtures

Use in-line entity creation or data providers:

```php
public static function orderAmountProvider(): array
{
    return [
        'small order' => [1000, 1],    // $10.00, 1 item
        'large order' => [100000, 5],  // $1000.00, 5 items
    ];
}

#[DataProvider('orderAmountProvider')]
public function testPaymentCalculation(int $amount, int $itemCount): void
{
    // ...
}
```

### Mocking

- `createMock()` for service dependencies in unit tests
- `EntityManagerInterface` is the most commonly mocked dependency
- Avoid mocking entities; create real instances for value-object semantics
- For controller tests, use `WebTestCase::createClient()` which boots the full
  Symfony kernel with SQLite

## 7. Test Naming Conventions

- **Test class**: `{TargetClass}Test.php` — e.g., `OrderServiceTest.php`
- **Test method**: `test{Scenario}()` or `test{Method}{Condition}()`
  - `testTransferFailsWhenInsufficientFunds()`
  - `testCreateOrderReturns201()`
  - `testInvoiceLifecycle()`
- **Data providers**: `{context}Provider()` returning an array of named cases
- **Test directories**: Mirror the source structure under `tests/`

## 8. CI Test Jobs

Each per-app test suite runs as a separate GitHub Actions job in parallel:

```yaml
jobs:
  test-common:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: php-actions/composer@v6
      - run: cd apps/common && composer test -- --coverage-clover ../../build/coverage/common.xml

  test-identity:
    # ... similar for each app
```

All per-app coverage artifacts are merged via `phpcov`:
```bash
phpcov merge build/coverage --clover build/coverage/merged.xml
```

The merge gate is enforced at **≥ 90% aggregate line coverage**.
