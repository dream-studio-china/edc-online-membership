# Database & Migrations

## 1. Doctrine ORM Conventions

- **ORM Version**: Doctrine ORM 3.6 with DBAL 4.x
- **Database**: MySQL 8.4 (production/Docker), SQLite (tests)
- **Mapping**: PHP attributes (no annotations, no XML, no YAML for entities)
- **Configuration**: `config/packages/doctrine.yaml` (root), `apps/{name}/config/packages/doctrine.yaml` (per-app)

### Entity Manager

The root monolith uses `default` entity manager. Each per-app service has its
own entity manager configured in its `doctrine.yaml`:

```yaml
# apps/store/config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'
    orm:
        auto_generate_proxy_classes: true
        mappings:
            Store:
                dir: '%kernel.project_dir%/src'
                prefix: 'App\Store'
```

## 2. Entity Conventions

### PHP Attribute Mapping

All entities use PHP 8 attributes for ORM mapping:

```php
<?php

declare(strict_types=1);

namespace App\Trade\Trade\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'trade_order')]
final class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'status', type: 'string', length: 40)]
    private string $status;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    // Constructor, getters, business methods...
}
```

### Required Entity Conventions

| Convention | Rule |
|------------|------|
| `$id` | Auto-increment integer PK. NEVER exposed outside the owning service. |
| `$uuid` | `VARCHAR(36)`, UUIDv4. External identity for cross-service references. |
| `$createdAt` | `datetime_immutable`, set in constructor. |
| `$updatedAt` | `datetime_immutable`, nullable, updated via lifecycle or setter. |
| Soft delete | `bool $isDeleted = false` where needed (Trade `Product`, `Specification`). |
| Version | `#[ORM\Version] int $version` for optimistic locking (Wallet). |

### Relationships

- `ManyToOne` / `OneToMany` for aggregate-internal relationships
- **No cross-service FKs** — use UUID references only
- `ON DELETE SET NULL` for nullable ownership (Media → User)
- `ON DELETE CASCADE` for aggregate-owned entities (Order → OrderItem)
- Collections use `Collection<int, Entity>` generic PHPDoc

### Table Naming

- `snake_case` with module prefix: `trade_order`, `trade_product`, `store_membership`,
  `payment_invoice`, `wallet_transaction`, `common_category`, `identity_refresh_token`,
  `inventory_reservation`, `inventory_stock`
- Entity class name may differ from physical table (e.g., `Membership` → `store_membership`
  table via explicit `#[ORM\Table(name: 'store_membership')]`)

## 3. Repository Pattern

All repositories extend `ServiceEntityRepository`:

```php
<?php

declare(strict_types=1);

namespace App\Trade\Trade\Repository;

use App\Trade\Trade\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findByUuid(string $uuid): ?Order
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /** @return Order[] */
    public function findPendingByStore(string $storeUuid): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.storeUuid = :store')
            ->andWhere('o.status = :status')
            ->setParameter('store', $storeUuid)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }
}
```

### Repository Rules

- Controllers **never** access repositories directly — go through Service layer
- Services inject repositories via constructor DI
- Repository methods return typed results: `?Entity`, `Entity[]`, or `QueryBuilder`
- Complex queries stay in repositories, not services

## 4. Migration Workflow

### Generate

```bash
# Root monolith
php bin/console doctrine:migrations:diff

# Per-app
cd apps/store
php bin/console doctrine:migrations:diff
```

### Verify

```bash
# Check migration SQL
php bin/console doctrine:migrations:migrate --dry-run

# Status
php bin/console doctrine:migrations:status
```

### Apply

```bash
# Docker
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction

# Native
php bin/console doctrine:migrations:migrate --no-interaction
cd apps/store && php bin/console doctrine:migrations:migrate --no-interaction
```

### Migration Naming

Format: `Version{YYYYMMDDHHMMSS}.php`

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trade_store_directory (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            store_code VARCHAR(64) NOT NULL,
            store_name VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            payload JSON NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_xxx (uuid),
            UNIQUE INDEX UNIQ_yyy (store_code),
            PRIMARY KEY(id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_store_directory');
    }
}
```

## 5. Per-App Migration Baselines

Each extracted app has its own independent migration baseline under
`apps/{name}/migrations/`. These are self-contained and do not reference root
monolith migrations.

| App | Migration Baseline | Tables |
|-----|--------------------|--------|
| Store | `Version20260730000000` | 6 tables: `store`, `store_membership`, `store_order`, `store_inbox_message`, `store_outbox_message`, `store_trade_order_cancellation` |
| Inventory | `Version20260726000000` | 9 tables: `inventory_material`, `inventory_stock`, `inventory_specification_recipe`, `inventory_recipe_line`, `inventory_reservation`, `inventory_reservation_line`, `inventory_ledger_entry`, `inventory_inbox_message`, `inventory_outbox_message` |
| Payment | `Version20260730050000` | 3 tables: `payment_invoice`, `payment_payer_directory`, `payment_outbox_message` |
| Wallet | `Version20260730040000` | 3 tables: `wallet`, `wallet_transaction`, `wallet_payment_deduction` |
| Identity | (baseline) | `users`, `identity_refresh_token`, `identity_profile`, `wechat_user` |
| Common | (baseline) | 8 tables: `common_category`, `common_tag`, `common_content`, `common_content_tag`, `common_comment`, `common_page`, `common_media`, `common_setting`, `common_picture` |
| Trade | (baseline) | Trade + Promotion tables: `trade_product`, `trade_specification`, `trade_order`, `trade_order_item`, `trade_outbox_message`, `trade_store_directory`, `promotion_template`, `promotion` |

## 6. Root Migration Chain

The root monolith's `migrations/` directory holds the **historical migration
chain** (20+ migrations). This chain remains for the transition period and
will be removed after Gateway cutover. New business tables belong in per-app
migrations, not the root chain.

### Historical Migration History

| Timestamp | Content |
|-----------|---------|
| 20250514000000 | `users`, `common_content` |
| 20250515000001 | `identity_refresh_token` |
| 20250516000000 | CMS tables (category, tag, content_tag, media, page, comment, setting) |
| 20250517000000 | `wallet`, `wallet_transaction` |
| 20250620000000 | Trade tables (product, specification, order, order_item) |
| 20250621000000 | Order timestamps (paid_at, refunded_at, fulfilled_at, etc.) |
| 20250624223701 | `payment_invoice`, `wechat_user`, `messenger_messages` |
| 20260626000000 | `wallet_payment_deduction` |
| 20260703000000-20260703020000 | Media storage/owner, `identity_profile` |
| 20260704000000 | Promotion tables |
| 20260713000000 | `common_picture` |
| 20260725000000-20260725050000 | Identity UUID, Store/Outbox/Inbox tables, Trade Outbox, Specification UUID |
| 20260726000000 | Inventory tables + `store_trade_order_cancellation` |
| 20260729000000 | Outbox correlation/causation columns; `trade_store_directory` |
| 20260730000000-20260730050000 | Per-app baselines, Wallet identity cutover, Payment app baseline |

## 7. Schema Conventions

### Column Types

| Concept | MySQL Type | Doctrine Type | Notes |
|---------|-----------|---------------|-------|
| UUID | `VARCHAR(36)` | `string` | Always UUIDv4, unique index |
| Money/Cents | `BIGINT` | `bigint` | Stored in cents; API boundary converts ×/÷100 |
| JSON | `JSON` | `json` | Prefer over `TEXT` for structured data |
| Timestamps | `DATETIME` | `datetime_immutable` | `created_at` (NOT NULL), `updated_at` (nullable) |
| Boolean | `TINYINT(1)` | `boolean` | Soft delete, enable flags |
| Status | `VARCHAR(40)` | `string` | Prefer over `ENUM` for migration flexibility |
| Text | `TEXT` | `text` | Long strings (shipping address, descriptions) |

### Indexes

```php
#[ORM\Table(name: 'trade_order')]
#[ORM\Index(columns: ['store_uuid'], name: 'idx_trade_order_store')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_trade_order_status_date')]
#[ORM\UniqueConstraint(name: 'uq_trade_order_uuid', columns: ['uuid'])]
```

### Money Convention

All monetary values are stored as `bigint` cents internally:

```php
class Wallet
{
    #[ORM\Column(type: 'bigint')]
    private int $balance = 0;  // In cents: 1000 = $10.00
}
```

API layer converts:
```php
// Input: $10.00 → 1000
$amountInCents = (int) ($request->amount * 100);

// Output: 1000 → 10.00
$data['balance'] = $wallet->getBalance() / 100;
```

## 8. Identity: `ownerUuid` Pattern

Services that reference a user identity use `ownerUuid` (a `VARCHAR(36)` UUID
string), **never** a cross-service FK to `users.id`:

```php
class Wallet
{
    #[ORM\Column(type: 'string', length: 36)]
    private string $ownerUuid;  // References Identity User.uuid

    // NEVER:
    // #[ORM\ManyToOne(targetEntity: User::class)]
    // private User $user;  // BANNED: cross-service FK
}
```

Wallet's legacy `user_id` FK and `User` ORM association have been removed
(`Version20260730040000`). Other services follow the same pattern.

## 9. Outbox/Inbox Tables

### Producer Outbox

```sql
CREATE TABLE {module}_outbox_message (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    event_type VARCHAR(128) NOT NULL,
    aggregate_type VARCHAR(255) NOT NULL,
    aggregate_id VARCHAR(36) NOT NULL,
    payload JSON NOT NULL,
    correlation_id VARCHAR(36) DEFAULT NULL,
    causation_id VARCHAR(36) DEFAULT NULL,
    claimed TINYINT(1) DEFAULT 0,
    claimed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_claimed (claimed, created_at)
);
```

### Consumer Inbox

```sql
CREATE TABLE {module}_inbox_message (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL UNIQUE,
    event_type VARCHAR(128) NOT NULL,
    aggregate_id VARCHAR(36) NOT NULL,
    consumed_at DATETIME NOT NULL,
    INDEX idx_event_id (event_id)
);
```

The `UNIQUE` constraint on `event_id` enforces idempotency at the database level.

## 10. Testing with SQLite

All test suites use SQLite with the path `var/test.db`:

```yaml
# phpunit.xml overrides
<env name="DATABASE_URL" value="sqlite:///var/test.db"/>
<env name="APP_ENV" value="test"/>
```

SQLite has limitations compared to MySQL:
- No `ENUM` type — use `VARCHAR` for status columns
- No JSON functions — store JSON as `TEXT` in tests
- Case sensitivity differs — use `LIKE` for case-insensitive search
- No `ON DELETE SET NULL` on some SQLite versions — test accordingly

Migrations should use `VARCHAR` instead of `ENUM` to maintain SQLite compatibility.
