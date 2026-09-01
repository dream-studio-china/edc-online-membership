# Project Structure

## 1. Full Directory Tree

```
crud-platform/
├── apps/                              # Independently deployable business services
│   ├── common/                        # CMS (Content, Category, Tag, Comment, Page, Media, Setting, Picture) + Storage
│   │   ├── bin/console                #   CLI entry point
│   │   ├── composer.json              #   App-owned dependencies
│   │   ├── composer.lock
│   │   ├── config/                    #   Symfony/Doctrine/Framework/Security config
│   │   │   ├── bundles.php            #   Bundle registration
│   │   │   ├── routes.yaml            #   Route imports
│   │   │   ├── services.yaml          #   Service wiring
│   │   │   ├── reference.php          #   Environment parameter reference
│   │   │   └── packages/              #   Package configs (doctrine, framework, security, media)
│   │   ├── migrations/                #   Standalone migration baseline
│   │   ├── Dockerfile                 #   Independent FrankenPHP image
│   │   ├── docker/Caddyfile           #   FrankenPHP server config
│   │   ├── phpunit.xml                #   PHPUnit config (SQLite, coverage target)
│   │   ├── .env                       #   Environment defaults
│   │   ├── public/                    #   Web root
│   │   ├── src/                       #   App\Common namespace
│   │   │   ├── Main/                  #   CMS module
│   │   │   │   ├── Kernel.php         #     App\Common\Kernel
│   │   │   │   ├── Controller/        #     App/, Manage/, Public/ subdirectories
│   │   │   │   ├── Entity/            #     Category, Tag, Content, Comment, Page, Media, Setting, Picture
│   │   │   │   ├── Repository/        #     One per entity
│   │   │   │   ├── Service/           #     One Service + ServiceInterface per entity
│   │   │   │   └── Security/          #     NullUserUuidResolver
│   │   │   └── Storage/               #   Storage module
│   │   │       ├── Command/           #     InitQiniuSettingsCommand
│   │   │       ├── Resources/config/  #     services_storage.yaml (explicit wiring)
│   │   │       └── Service/           #     MediaStorageInterface, MediaStorageRegistry, LocalStorage, QiniuStorage
│   │   ├── tests/                     #   Unit + integration tests (74 tests)
│   │   └── var/                       #   Cache, logs
│   │
│   ├── identity/                      # Authentication + WeChat login
│   │   ├── (structure mirrors common/)
│   │   └── src/
│   │       ├── Main/                  #   Auth module
│   │       │   ├── Kernel.php         #     App\Identity\Kernel
│   │       │   ├── Controller/        #     AuthController, App/, Manage/
│   │       │   ├── Entity/            #     User, RefreshToken, Profile
│   │       │   ├── Security/          #     JwtAuthenticator, TokenManager
│   │       │   ├── Service/           #     UserService, OtpService, SMS providers
│   │       │   └── Command/           #     CreateUserCommand
│   │       └── Wechat/                #   WeChat module
│   │           ├── Entity/            #     WechatUser (OneToOne → User)
│   │           ├── Repository/
│   │           ├── Service/           #     WechatService, WechatAuthService, WechatUserService
│   │           └── Controller/
│   │
│   ├── trade/                         # Commerce + Promotion
│   │   └── src/
│   │       ├── Trade/                 #   Commerce module
│   │       │   ├── Kernel.php         #     App\Trade\Kernel
│   │       │   ├── Controller/        #     App/, Manage/
│   │       │   ├── Entity/            #     Product, Specification, Order, OrderItem, TradeOutboxMessage, TradeStoreDirectory
│   │       │   ├── Repository/
│   │       │   ├── Service/           #     OrderService, Pricing/* (calculators)
│   │       │   ├── MessageHandler/    #     Store acceptance/rejection + Invoice lifecycle consumers
│   │       │   ├── EventListener/     #     OrderInvoiceListener
│   │       │   ├── Command/           #     PublishOutboxCommand, BackfillOutboxCorrelation
│   │       │   └── Exception/
│   │       └── Promotion/             #   Promotion module
│   │           ├── Entity/            #     PromotionTemplate, Promotion
│   │           ├── Repository/
│   │           ├── Service/           #     PromotionService, PromotionCalculator, Dsl/*
│   │           ├── Strategy/          #     7 strategy implementations
│   │           └── Controller/        #     App/, Manage/
│   │
│   ├── store/                         # Store operations
│   │   └── src/                       # App\Store namespace (de-prefixed entities)
│   │       ├── Kernel.php             #   App\Store\Kernel
│   │       ├── Controller/            #   App/, Manage/, Staff/
│   │       ├── Entity/                #   Store, Membership, StoreOrder, InboxMessage, OutboxMessage, TradeOrderCancellation
│   │       ├── Repository/
│   │       ├── Service/               #   MembershipService, OutboxService, StoreService, StoreOrderService
│   │       ├── MessageHandler/        #   Trade order + Inventory outcome consumers
│   │       ├── EventListener/         #   StoreDirectoryOutboxListener
│   │       └── Command/               #   PublishOutbox, BackfillOutboxCorrelation, BackfillStoreDirectory
│   │
│   ├── inventory/                     # Stock & reservation
│   │   └── src/                       # App\Inventory namespace
│   │       ├── Entity/                #   Material, InventoryStock, SpecificationRecipe, RecipeLine, InventoryReservation, ReservationLine, InventoryLedgerEntry, InboxMessage, OutboxMessage
│   │       ├── Repository/
│   │       ├── Service/
│   │       ├── MessageHandler/
│   │       └── Command/               #   PublishOutbox, BackfillOutboxCorrelation, ReleaseExpiredReservations
│   │
│   ├── payment/                       # Invoices & payment gateways
│   │   └── src/                       # App\Payment namespace
│   │       ├── Kernel.php
│   │       ├── Controller/            #   App/, Manage/, Webhook/ (WeChat notify)
│   │       ├── Entity/                #   Invoice, PayerDirectory, PaymentOutboxMessage
│   │       ├── Repository/
│   │       ├── Service/               #   InvoiceService, PaymentOutboxService, Gateway/*, Adjustment/*
│   │       ├── DTO/                   #   CreateInvoiceRequest, PaymentResult, etc.
│   │       ├── Event/                 #   InvoicePaidEvent, InvoiceRefundedEvent, etc.
│   │       └── Command/               #   PublishOutboxCommand
│   │
│   └── wallet/                        # Balances & transactions
│       └── src/                       # App\Wallet namespace
│           ├── Kernel.php
│           ├── Controller/            #   App/, Manage/
│           ├── Entity/                #   Wallet, WalletTransaction, WalletPaymentDeduction
│           ├── Repository/
│           ├── Service/               #   WalletService, TransferService, TransactionService, Payment/*
│           ├── DTO/
│           └── Exception/             #   InsufficientFundsException, SameWalletTransferException, WalletFrozenException
│
├── packages/                          # Reusable PHP libraries
│   ├── platform-kernel/               #   Framework core (App\Core namespace)
│   │   └── src/
│   │       ├── Controller/            #     RestController, System/
│   │       ├── View/                  #     ApiView, 9 trait mixins, ApiViewMessages
│   │       ├── Service/               #     BaseService, BaseServiceInterface, Concern/ traits
│   │       ├── Parser/                #     ExpressionDqlParser
│   │       ├── Serializer/            #     FlatNormalizer
│   │       ├── EventListener/         #     ExceptionInterceptor, ControllerListener, OpenApiEnricherListener, LocaleListener, AccessLogListener
│   │       ├── Security/              #     UserUuidResolverInterface, UserUuidPrincipalInterface
│   │       └── Utils/                 #     UUID, Math, RSA, Location, Inflect
│   ├── integration-contracts/         #   Transport-neutral v1 event carriers
│   └── legacy-messenger-compat/       #   Historical native-PHP wrapper FQCNs
│
├── src/                               # Root monolith (transition scaffolds only)
│   ├── Kernel.php                     #   App\Kernel (MicroKernelTrait)
│   └── Bridge/                        #   Temporary adapters (e.g., PaymentWallet)
│
├── config/                            # Root monolith configuration
│   ├── services.yaml                  #   Service wiring + exclusion rules
│   ├── routes.yaml                    #   Route imports (all 7 apps + system)
│   └── packages/                      #   framework.yaml, security.yaml, translation.yaml, workflow.yaml, messenger.yaml, nelmio_api_doc.yaml, doctrine.yaml, doctrine_migrations.yaml
│
├── migrations/                        # Root monolith migration chain (historical)
├── translations/                      # i18n: messages.{en,zh,zh_Hant,ja}.yaml
├── tests/                             # Root integration tests (963)
├── scripts/                           # tests/ (smoke scripts), coverage/
├── docs/
│   ├── manual/                        # This collection
│   ├── design/                        # Architecture contracts
│   │   ├── system-architecture.md
│   │   ├── microservice-transition.md
│   │   ├── api-design.md
│   │   ├── data-model.md
│   │   ├── module-design.md
│   │   ├── controller-design.md
│   │   ├── api-documentation.md
│   │   ├── system-contracts.md
│   │   ├── security-hardening.md
│   │   └── bundles/                   # Per-module design docs (core, common, trade, wallet, identity, wechat, payment, storage, promotion, inventory, store)
│   ├── ai/                            # AI context snapshots
│   ├── openapi/                       # Frontend integration guides
│   └── theme/                         # MkDocs theme overrides
│
├── public/                            # Root monolith web root
│   ├── index.php                      #   Front controller
│   └── .htaccess                      #   Apache rewrite + Authorization header
│
├── docker/                            # Root deployment config
│   ├── app/entrypoint.sh              #   JWT key gen + .env placeholder
│   └── frankenphp/Caddyfile           #   Root monolith Caddy config
│
├── compose.yaml                       # Docker Compose (22 services)
├── compose.override.yaml              # Dev overrides (source mount, debug)
├── compose.prod.yaml                  # Production overlay
├── Dockerfile                         # Root monolith FrankenPHP image
├── .dockerignore
├── mkdocs.yml                         # MkDocs Material site config
├── phpunit.xml                        # Root test suite config (SQLite)
├── phpstan.dist.neon                  # PHPStan Level 8 config
├── deptrac.yaml                       # Architecture boundary rules
├── deptrac-baseline.yaml              # Known violations baseline
├── rector.php                         # Rector type rules
├── composer.json                      # Root dependencies
├── composer.lock
├── .env, .env.prod.example
└── README.md (en, zh-cn, zh-hant, ja)
```

## 2. Naming Conventions

### Namespaces

| Pattern | Example | Notes |
|---------|---------|-------|
| `App\{Module}\*` | `App\Trade\Trade\Service\OrderService` | Business apps under `apps/` |
| `App\Core\*` | `App\Core\Service\BaseService` | Framework shared library (`packages/platform-kernel/`) |
| `App\Bridge\*` | `App\Bridge\PaymentWallet\WalletBalanceAdjustmentPort` | Temporary monolith adapters |
| `App\{Module}\Main\*` | `App\Identity\Main\Entity\User` | Module with sub-modules (Main + Wechat, Main + Storage, Trade + Promotion) |
| `App\{Module}\{Sub}\*` | `App\Identity\Wechat\Entity\WechatUser` | Sub-module under a main module |

### Class Naming

| Type | Convention | Example |
|------|-----------|---------|
| Entity | Singular PascalCase, matches table | `Product`, `OrderItem` |
| Repository | `{Entity}Repository` | `ProductRepository` |
| Service | `{Entity}Service` | `ProductService` |
| Service Interface | `{Entity}ServiceInterface` | `ProductServiceInterface` |
| Controller | `{Entity}Controller` | `ProductController` |
| Message Handler | Descriptive suffix | `TradeOrderCreatedHandler` |
| Command | PascalCase, `{Verb}{Noun}Command` | `PublishOutboxCommand` |
| Exception | Descriptive suffix | `InsufficientFundsException` |
| DTO | Descriptive suffix | `CreateInvoiceRequest` |
| Event | `{Entity}{Action}Event` | `InvoicePaidEvent` |

### File Naming

- One class per file. File name matches class name exactly.
- `.php` extension.
- Interface files use the same naming as the implementing class.

### Table Naming

- `snake_case`, module prefix where needed: `trade_order`, `trade_product`,
  `store_membership`, `payment_invoice`, `wallet_transaction`, `common_category`,
  `identity_refresh_token`, `inventory_reservation`.
- De-prefixed entity class names may differ from physical table names (e.g., PHP
  class `Membership` maps to `store_membership` table).

## 3. Module Structure Pattern

Each business module follows this directory layout:

```
Controller/
  App/              # Authenticated user endpoints (ROLE_USER)
  Manage/           # Admin endpoints (ROLE_ADMIN)
  Public/           # Anonymous endpoints (optional, e.g., Common Media)
  Webhook/          # External webhook handlers (optional, e.g., Payment notify)
Entity/             # Doctrine entities (ORM attributes)
Repository/         # Doctrine repositories (extends ServiceEntityRepository)
Service/            # Business logic (extends BaseService)
  {Entity}ServiceInterface.php  # Interface for DI and testing
MessageHandler/     # Async message consumers
EventListener/      # Doctrine lifecycle or kernel event listeners
Command/            # CLI commands
Exception/          # Module-specific exceptions
DTO/                # Data transfer objects (immutable request/response shapes)
Event/              # Domain events (optional)
Security/           # Authenticators, resolvers (optional)
Resources/config/   # Module-specific services.yaml (optional)
```

Controllers compose trait mixins from `App\Core\View`:
```php
class ProductController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DetailApiViewMixin;
    use CreateApiViewMixin;
    use UpdateApiViewMixin;
    use DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['name', 'price'];
    protected array $acceptedCreateProperties = ['name', 'price', 'description'];
    protected array $acceptedUpdateProperties = ['name', 'price', 'description'];
}
```

## 4. `apps/` vs `packages/` Distinction

| Feature | `apps/` | `packages/` |
|---------|---------|-------------|
| Has its own Kernel | Yes | No |
| Has its own database | Yes | No |
| Has its own migrations | Yes | No |
| Has its own Docker image | Yes | No |
| Contains business entities | Yes | **NEVER** |
| Is independently deployable | Yes (target) | No (library) |
| Owns a `composer.json` | Yes | Yes |

## 5. Config File Conventions

### Root Monolith (`config/`)

| File | Purpose |
|------|---------|
| `services.yaml` | Service wiring, exclusions, `_instanceof` auto-tag rules |
| `routes.yaml` | Prefix-based route imports for all apps + auth + system |
| `packages/doctrine.yaml` | DBAL + ORM config, SQLite fallback for tests |
| `packages/security.yaml` | Authenticator, public access rules, role hierarchy |
| `packages/translation.yaml` | `default_locale: en`, translations path |
| `packages/workflow.yaml` | Order state machine (8 states + guard events) |
| `packages/messenger.yaml` | Transport routing, async/worker config |
| `packages/nelmio_api_doc.yaml` | OpenAPI 3.1 schemas, path patterns |
| `packages/framework.yaml` | Framework defaults, serializer, validation |

### Per-App Config (`apps/{name}/config/`)

Each app has its own `services.yaml`, `routes.yaml`, `packages/doctrine.yaml`, etc.
These are self-contained and do not reference root config. Doctrine mappings point
exclusively to the app's own entities.

### Environment Variables

- Root monolith: `.env` at repository root
- Per-app: `.env` at `apps/{name}/.env`
- Docker: defaults in `compose.yaml` environment sections
- Production: `.env.prod.local` from `.env.prod.example`

## 6. Where to Put New Code

| What you're adding | Where it goes |
|--------------------|---------------|
| New business entity for an existing service | `apps/{service}/src/{Module}/Entity/` |
| New CRUD controller for an existing entity | `apps/{service}/src/{Module}/Controller/{App\|Manage}/` |
| New business logic | `apps/{service}/src/{Module}/Service/` |
| New async message handler | `apps/{service}/src/{Module}/MessageHandler/` |
| New CLI command | `apps/{service}/src/{Module}/Command/` |
| New integration event carrier | `packages/integration-contracts/src/` |
| New framework utility | `packages/platform-kernel/src/` |
| New temporary bridge/adapter | `src/Bridge/` (monolith, will be removed) |
| New test for an app | `apps/{service}/tests/` |
| New cross-cutting integration test | `tests/Integration/` |
| New migration for an app | `apps/{service}/migrations/` |
| Migration for root monolith tables | `migrations/` |

**Rule of thumb**: If the code owns business state, it belongs in `apps/{service}/`.
If it's reusable infrastructure, it belongs in `packages/`. Root `src/` is for
transition bridges only and will be removed after Gateway cutover.
