# CRUD Skeleton

一个面向生产实践的 Symfony 8.1 API 骨架，内置可复用的服务层抽象、模块化架构、JWT 鉴权、动态查询引擎以及可插拔的业务模块。

> English: [README.md](README.md) · Chinese (Traditional): [README.zh-hant.md](README.zh-hant.md) · Japanese: [README.ja.md](README.ja.md)

> 文档站点: [GitHub Pages](https://immane.github.io/crud-skeleton) | 设计契约: [docs/design/](docs/design/)

## 架构

应用是分层 Symfony API：控制器基于 trait 组合的视图 mixin 调用 `BaseService`（CRUD + 动态查询），服务承载业务规则，Doctrine ORM 持久化到 MySQL。模块依赖 Core，跨模块仅通过服务接口交互。

```mermaid
flowchart TB
    Core["<b>Core 框架</b><br/>BaseService · View Mixins · Expression→DQL"]

    Identity["Identity<br/>鉴权 · JWT · OTP · User"]
    Common["Common<br/>CMS（7 实体）"]
    Storage["Storage<br/>媒体驱动"]
    Wechat["Wechat<br/>登录 + 支付"]
    Wallet["Wallet<br/>余额 · 转账 · 凭证"]
    Payment["Payment<br/>发票 · 网关 · 抵扣"]
    Trade["Trade<br/>订单 · 定价"]
    Store["Store<br/>多门店 Outbox"]
    Inventory["Inventory<br/>库存 · 预留"]
    Promotion["Promotion<br/>DSL 引擎"]
    Settlement["Settlement<br/>分账 · 终态"]
    Exchange["Exchange（设计）<br/>汇率 · 资金池 · 发行"]

    Identity --> Core
    Common --> Core
    Storage --> Core
    Storage --> Common
    Wechat --> Core
    Wechat --> Identity
    Wallet --> Core
    Wallet --> Identity
    Payment --> Core
    Payment --> Wallet
    Trade --> Core
    Trade --> Payment
    Trade --> Store
    Trade --> Inventory
    Promotion --> Core
    Promotion --> Trade
    Settlement --> Core
    Settlement --> Wallet
    Exchange -. "design" .-> Core
```

一次业务操作（例如钱包支付）的请求流程：

```mermaid
sequenceDiagram
    participant C as 客户端
    participant Ctrl as 控制器
    participant S as 服务
    participant P as Provider
    participant DB as Doctrine ORM / MySQL

    C->>Ctrl: POST /api/v1/...（JSON body）
    Ctrl->>S: 调用服务（校验后的载荷）
    S->>P: 解析 provider + assertPermitted()
    S->>DB: wrapInTransaction { 账本 + 审计写入 }
    S-->>Ctrl: 结果 / 实体
    Ctrl-->>C: 统一响应信封
```

## 目录

- [架构](#架构)
- [快速上手指南](#快速上手指南)
- [为什么使用这个项目](#为什么使用这个项目)
- [功能特性](#功能特性)
- [技术栈](#技术栈)
- [项目结构](#项目结构)
- [快速开始](#快速开始)
- [配置说明](#配置说明)
- [本地运行](#本地运行)
- [模块概览](#模块概览)
- [API 路由](#api-路由)
- [服务层设计说明](#服务层设计说明)
- [动态查询系统](#动态查询系统)
- [如何创建自己的 CRUD 模块](#如何创建自己的-crud-模块)
- [文档说明](#文档说明)
- [测试](#测试)
- [Docker 部署](#docker-部署)
- [常见问题](#常见问题)
- [贡献指南](#贡献指南)
- [国际化（i18n）](#国际化i18n)
- [许可证](#许可证)

## 快速上手指南

如果你希望 5-10 分钟跑通本地登录与鉴权，请直接看 [QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md)。

在 macOS 下建议优先使用 Homebrew PHP（`/opt/homebrew/bin/php`），避免与系统默认 PHP 版本冲突。

## 为什么使用这个项目

这是一个为 Symfony 后端 CRUD 开发准备的干净基础骨架。

相比纯脚手架模板，它额外提供：

- 通用 `BaseService` 契约，统一实体 CRUD 操作。
- 通过 PHP trait 组合实现可复用的 API 视图 mixin（list/detail/create/update/delete/workflow）。
- 控制器瘦身模式：业务逻辑放在服务层。
- 基于表达式的动态查询（`@filter`、`@sort`、`@dql`），编译为 DQL 并具备内存回退能力。
- 可插拔的电商价格计算管道与订单状态机。
- 基于发票的统一支付框架，含网关抽象（mock、wallet、wechat）和可插拔的支付抵扣提供方。
- 原子的钱包转账，含死锁预防、乐观锁、幂等性，钱包余额抵扣作为支付抵扣提供方。
- 可插拔的文件存储驱动（本地、七牛 Kodo），统一 `MediaStorageInterface`。
- JWT 鉴权（RS256）配合 Refresh Token 轮换，以及手机验证码登录。
- 密码自助注册，用户个人信息管理，管理员用户 CRUD。
- 钱包余额校验与对账：时刻保证 SUM(所有钱包) == SUM(所有存款)。
- 完整的设计契约文档，确保新模块开发一致性。

## 功能特性

- **CRUD 服务抽象**：`new()`、`get()`、`list()`、`update()`、`remove()`。
- **动态查询系统**：通过请求参数控制筛选/排序/排序/分组/字段选择，表达式编译为 DQL。
- **Trait 组合式控制器**：9 个 mixin trait（List、Detail、Create、Update、Delete、Workflow、Singleton、Transform）可按需组合。
- **模块化架构**：Core 框架 + Common（CMS） + Promotion（DSL 驱动促销引擎） + Trade（电商） + Store（门店交易发件箱） + Inventory（物料、库存、配方、预留） + Payment（支付） + Wallet（钱包） + Wechat（微信登录+支付） + Storage（文件存储驱动） + Identity（鉴权）。
- **JWT 鉴权**：RS256 访问令牌，HMAC-SHA256 Refresh Token 轮换，含重用检测。
- **OTP 登录**：基于手机验证码的短信登录，带频率限制（阿里云）。
- **订单状态机**：Symfony Workflow（草稿 → 完成），含完整工作流 API。
- **价格计算管道**：可插拔的价格计算器，按优先级排序执行。
- **支付抵扣提供方**：支付前钩子（如钱包抵扣）在网关处理前减少发票金额 — 网关仅接收显式金额。
- **原子钱包转账**：死锁预防（统一锁定顺序）、悲观锁（`SELECT … FOR UPDATE`）、引用 ID 幂等。
- **凭证存款与取款**：以追加式的 `wallet_voucher` 审计（边界账本）为背书的单边入账/出账。voucher 类型的权限由 provider 自行裁决（`manual` 需要 `ROLE_ADMIN`；CLI/队列视为可信调用），并发重复 `referenceId` 幂等返回，不再撞唯一索引报错。
- **钱包账务**：余额校验（`SUM(余额) == SUM(贷方凭证) − SUM(借方凭证)`）与逐钱包对账，覆盖存款、取款、转账与冻结。
- **钱包余额抵扣**：钱包拥有的抵扣生命周期，通过 Payment 抵扣提供方模式接入 — Payment 编排，Wallet 实现。
- **汇率域（设计）**：资金池背书的点数经济设计（`docs/design/bundles/exchange.md`）——生效期汇率、bcmath 换算引擎、质押/发行/兑换/赎回，围绕造市商监管的资金池。
- **分账与终态**：已确认资金 → 不可变上下文 → 版本化规则 → 可审计的计划/分账 → 通过 Wallet 端口入账。18 位精确金额（brick/math）、确定性最大余数舍入、原凭证冲正、SQL outbox/inbox 保证跨模块可靠交接。
- **可插拔文件存储**：`MediaStorageInterface`，本地与七牛 Kodo 驱动 — tagged iterator 自动发现。
- **OpenAPI 文档**：NelmioApiDocBundle + `#[OA\*]` 属性，`/api/doc` 提供 Swagger UI。
- **系统自省**：实体元数据和路由导出接口（`/system/*`）。
- **促销 DSL 引擎**：自定义词法/语法/求值器，支持 7 种促销类型（满减、折扣、赠品、第 N 件折扣、阶梯、免运费、会员折扣）。作为标签定价计算器（优先级 60）运行在 Trade 价格管道汇总小计之后。支持会员定向 SKU 折扣、多门店路由、全平台活动，以及 `best_price` 冲突模式（模拟候选活动并选择最低总价）。
- **Profile 实体**：用户注册时通过 Doctrine 监听器自动创建。包含等级（青铜→钻石）、昵称、头像、元数据。积分委托给 Wallet（currency=POINTS）。
- **质量门禁**：CI 中执行 PHPUnit 覆盖率检查、PHPStan Level 8 与 Rector 类型规则检查。
- **健康检查**：`/health/live`（存活探针）与 `/health/ready`（DB + 可选 Redis 就绪探针）——公开探针，用于 Docker healthcheck。
- **速率限制**：登录、注册、OTP、微信登录与支付端点按客户端 IP 滑动窗口限流（429 + `Retry-After`）。
- **Prometheus 指标**：`/metrics` 文本格式——每 worker 的 HTTP 计数器/耗时直方图，以及实时 DB 指标（outbox 积压、失败消息队列）。
- **Docker Compose**：MySQL 8 + Mailpit 开发环境。

## 技术栈

| 组件 | 技术 |
|------|------|
| 语言 | PHP `>= 8.4` |
| 框架 | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| 数据库 | MySQL 8（Docker/生产）/ SQLite（测试） |
| 鉴权 | JWT (RS256) + OTP (短信) |
| API 文档 | NelmioApiDocBundle (OpenAPI 3) |
| 测试 | PHPUnit `^12.5`（支持 paratest 并行运行） |
| 前端 | [crud-admin](https://github.com/immane/crud-admin) — 配置驱动的管理后台 |
| 文档 | MkDocs Material (GitHub Pages) |

完整依赖请查看 `composer.json`。

## 项目结构

```text
.
├── src/
│   ├── Core/                     # 框架核心
│   │   ├── Controller/           #   RestController（API 控制器基类）
│   │   ├── Service/              #   BaseService、ExpressionService、QueryBuilderFactory
│   │   ├── Service/Concern/      #   Traits: Infrastructure、ReadList、Mutation
│   │   ├── View/                 #   9 个控制器 mixin trait
│   │   ├── Parser/               #   表达式 → DQL 编译器
│   │   ├── Serializer/           #   FlatNormalizer、CircularReferenceHandler
│   │   ├── EventListener/        #   ExceptionInterceptor、ControllerListener
│   │   └── Utils/                #   UUID、Math、RSA、ArrayCommon 等
│   ├── Common/                   # CMS 模块（7 实体）
│   │   ├── Controller/App/       #   公开只读接口
│   │   ├── Controller/Manage/    #   管理端 CRUD 接口
│   │   ├── Entity/               #   Category、Tag、Content、Comment、Page、Media、Setting
│   │   ├── Repository/
│   │   └── Service/
│   ├── Trade/                    # 电商模块
│   │   ├── Controller/App/       #   Product、Order、Specification 列表
│   │   ├── Controller/Manage/    #   Product、Specification、Order（CRUD + 工作流）
│   │   ├── Entity/               #   Product、Specification、Order、OrderItem
│   │   ├── Service/              #   OrderService、价格计算管道
│   │   └── Service/Pricing/      #   PriceCalculatorInterface + 3 个实现
│   ├── Store/                     # 门店模块
│   │   ├── Controller/Manage/    #   门店 CRUD
│   │   ├── Entity/               #   Store
│   │   ├── Repository/
│   │   ├── Service/              #   StoreService
│   │   └── MessageHandler/       #   创建/接受/拒绝/取消 出站消费者
│   ├── Wallet/                    # 钱包模块
│   │   ├── Controller/App/       #   钱包、交易、凭证（自助服务）
│   │   ├── Controller/Manage/    #   钱包、交易、凭证（存款/取款/反冲）
│   │   ├── DTO/                  #   PaymentDeductionRequest
│   │   ├── Entity/               #   Wallet, Transaction, Voucher, VoucherComment, PaymentDeduction
│   │   ├── Repository/           #   Wallet, Transaction, Voucher, VoucherComment, PaymentDeduction
│   │   └── Service/              #   TransferService, WalletService, VoucherService
│   │       ├── Deposit/          #   DepositService + provider 注册表（凭证入账）
│   │       ├── Withdraw/         #   WithdrawService + provider 注册表（凭证出账）
│   │       └── Payment/          #   WalletGateway, WalletBalanceAdjustmentProvider, PaymentDeductionService
│   ├── Payment/                  # 支付模块
│   │   ├── Controller/App/       #   发票列表/详情/支付
│   │   ├── Controller/Manage/    #   发票创建/取消/退款/转换
│   │   ├── Controller/Webhook/   #   提供方支付回调
│   │   ├── DTO/                  #   CreateInvoiceRequest, PaymentResult, PaymentAdjustmentContext/Result 等
│   │   ├── Entity/               #   Invoice（分、工作流、网关）
│   │   ├── Event/                #   InvoicePaid, Refunded, Cancelled, Failed
│   │   ├── Exception/            #   GatewayNotFound, Verification, Transition
│   │   ├── Repository/
│   │   └── Service/              #   InvoiceService, PaymentGatewayRegistry
│   │       ├── Adjustment/       #   PaymentAdjustmentProviderInterface, PaymentAdjustmentRegistry
│   │       └── Gateway/          #   MockGateway
│   ├── Wechat/                   # 微信模块
│   │   ├── Controller/           #   LoginController（小程序 + 公众号）
│   │   ├── Controller/App/       #   WechatUser CRUD（用户范围）
│   │   ├── Controller/Manage/    #   WechatUser CRUD（管理员）
│   │   ├── Entity/               #   WechatUser（OneToOne→User）
│   │   ├── Repository/
│   │   └── Service/              #   WechatService, WechatAuthService, WechatUserService
│   │       └── Payment/          #   WechatPayGateway
│   ├── Storage/                  # 存储模块（可插拔文件上传驱动）
│   │   ├── Service/              #   MediaStorageInterface, MediaStorageRegistry
│   │   │   ├── LocalStorage.php       # 本地文件系统（public/uploads/）
│   │   │   └── QiniuStorage.php       # 七牛 Kodo CDN
│   │   └── Resources/config/     #   services_storage.yaml
│   ├── Promotion/                # 促销模块（DSL 引擎）
│   │   ├── Controller/App/       #   只读促销接口
│   │   ├── Controller/Manage/    #   管理端促销 CRUD
│   │   ├── Entity/               #   PromotionTemplate、Promotion
│   │   ├── Repository/
│   │   ├── Service/              #   PromotionService、PromotionTemplateService、PromotionCalculator
│   │   │   └── Dsl/              #   DSL 词法/语法/求值器
│   │   ├── Strategy/             #   7 种促销策略
│   │   └── Exception/
│   ├── Inventory/                # 库存模块（物料、库存、配方、预留）
│   │   ├── Controller/Manage/    #   物料、库存、配方管理
│   │   ├── Entity/               #   Material、Stock、SpecificationRecipe、Reservation 等
│   │   ├── Repository/
│   │   ├── Service/              #   InventoryService（预留/释放/调整）
│   │   ├── MessageHandler/       #   预留请求/释放处理器
│   │   └── Command/              #   PublishOutboxCommand、ReleaseExpiredReservationsCommand
│   ├── Settlement/                # 分账模块（计划、规则、入账）
│   │   ├── Controller/Manage/     #   计划、规则、规则版本、审计视图
│   │   ├── Entity/                #   SettlementPlan、SettlementAllocation、SettlementRule 等
│   │   ├── Repository/
│   │   ├── Service/               #   SettlementService、SettlementRuleEngine、Money
│   │   ├── MessageHandler/        #   资金确认、入账处理器
│   │   └── Command/               #   PublishOutboxCommand、RequeueDuePostingCommand
│   └── Identity/                 # 鉴权模块
│       ├── Controller/App/       #   UserController (个人信息、改密码)、ProfileController
│       ├── Controller/Manage/    #   UserController (管理员 CRUD)、ProfileController
│       ├── Command/              #   CreateUserCommand (CLI)
│       ├── Controller/           #   AuthController、OtpController
│       ├── Entity/               #   User、RefreshToken、Profile
│       ├── Security/             #   JwtAuthenticator、TokenManager
│       └── Service/              #   OtpService、短信供应商
├── config/                       # Symfony 配置
│   └── packages/                 #   Doctrine、Security、Workflow、Serializer 等
├── migrations/                   # Doctrine 迁移（20 个版本）
├── tests/                        # 默认套件 2224 个 PHPUnit 测试，按层组织：
│   ├── UnitTest/                 #   纯单元测试（无 kernel/DB）
│   ├── Integration/              #   kernel + DB + HTTP 测试及共享 helper
│   └── LowValue/                 #   弃用/低价值测试，默认运行排除
├── docs/                         # 项目文档
│   ├── design/                   #   设计契约（系统、API、数据、模块、控制器）
│   │   │   └── bundles/              #   各模块设计文档（含 Promotion）
│   ├── testing/                  #   测试质量契约（策略、矩阵、不变量）
│   ├── issues/                   #   审计与覆盖率报告
│   └── ai/                       #   AI 上下文快照
├── compose.yaml                  # MySQL 8
├── compose.override.yaml         # 端口映射 + Mailpit
└── mkdocs.yml                    # MkDocs Material 配置
```

## 快速开始

### 1) 克隆仓库

```bash
git clone https://github.com/immane/crud-skeleton.git
cd crud-skeleton
```

### 2) 安装依赖

```bash
composer install
```

### 3) 为本机 PHP 准备环境变量

Docker 开发环境无需创建 env 文件即可启动，并会自动启动 Messenger `worker` 和 Trade/Store Outbox `scheduler`。可通过 `docker compose logs -f worker scheduler` 查看异步处理。本机 PHP/Symfony 运行时，建议在 `.env.local` 中覆盖本地配置：

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4"
JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=
REFRESH_TOKEN_SECRET=change-this-secret
```

## 配置说明

环境变量文件职责：

| 文件 | 用途 | 是否提交 |
|------|------|----------|
| `.env` | 已提交的 Symfony 默认值，不放密钥 | 是 |
| `.env.dev`、`.env.test` | 已提交的开发/测试默认值 | 是 |
| `.env.local`、`.env.*.local` | 本机覆盖值和密钥 | 否 |
| `.env.example` | 本地开发变量参考 | 是 |
| `.env.prod.example` | 生产 Docker 模板 | 是 |
| `.env.prod.local` | 真实生产 Docker 配置 | 否 |

关键环境变量：

| 变量 | 用途 |
|------|------|
| `APP_ENV` | 运行环境（`dev`/`prod`/`test`） |
| `APP_SECRET` | Symfony 应用密钥 |
| `DATABASE_URL` | MySQL 连接字符串 |
| `JWT_PRIVATE_KEY_PATH` | RS256 私钥路径 |
| `JWT_PUBLIC_KEY_PATH` | RS256 公钥路径 |
| `JWT_PASSPHRASE` | 密钥密码 |
| `REFRESH_TOKEN_SECRET` | HMAC-SHA256 密钥 |
| `MAILER_DSN` | 邮件发送器 |

生产环境请不要在仓库中提交明文密钥。使用真实系统环境变量，或通过 `docker compose --env-file .env.prod.local` 提供。

### 媒体存储与七牛

媒体上传通过 `App\Storage\Service\MediaStorageInterface` 支持多种存储驱动。

| 驱动 | 状态 | 说明 |
|------|------|------|
| `local` | 内置 | 默认驱动。文件保存到 `public/uploads/{YYYYMM}/...`，返回 `/uploads/...` 路径。 |
| `qiniu` | 可选 | 七牛 Kodo 驱动。需要安装七牛 PHP SDK，并在 `common_setting` 中配置密钥。 |

默认上传驱动通过以下环境变量控制：

```dotenv
MEDIA_STORAGE_DEFAULT=local
```

上传时可以通过 multipart 表单字段 `storage` 指定驱动：

```bash
curl -X POST http://localhost:8080/api/v1/manage/media/upload \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/photo.jpg" \
  -F "storage=qiniu"
```

#### 启用七牛

七牛 SDK 默认不作为项目依赖安装。只有实际使用 `storage=qiniu` 的部署环境才需要安装：

```bash
composer require qiniu/php-sdk
```

Docker 环境：

```bash
docker compose exec app composer require qiniu/php-sdk
```

生产 compose 命令需要带上生产 compose 文件和 env 文件：

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app composer require qiniu/php-sdk
```

七牛配置从 `common_setting` 读取，不从 `.env` 读取。使用前需要创建以下配置：

| Key | Value |
|-----|-------|
| `qiniu.access_key` | 七牛 access key |
| `qiniu.secret_key` | 七牛 secret key |
| `qiniu.bucket` | Bucket 名称 |
| `qiniu.domain` | Bucket 公开访问域名，例如 `https://cdn.example.com` |

可以使用命令创建缺失的配置项；已有配置不会被覆盖：

```bash
php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

Docker 环境：

```bash
docker compose exec app php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

也可以通过管理端 settings API 创建配置：

```bash
curl -X POST http://localhost:8080/api/v1/manage/settings \
  -H "Authorization: Bearer <admin-token>" \
  -H "Content-Type: application/json" \
  -d '[
    {"key":"qiniu.access_key","value":"<access-key>","type":"string","groupName":"storage","label":"Qiniu Access Key"},
    {"key":"qiniu.secret_key","value":"<secret-key>","type":"string","groupName":"storage","label":"Qiniu Secret Key"},
    {"key":"qiniu.bucket","value":"<bucket>","type":"string","groupName":"storage","label":"Qiniu Bucket"},
    {"key":"qiniu.domain","value":"https://cdn.example.com","type":"string","groupName":"storage","label":"Qiniu Domain"}
  ]'
```

如果未安装 SDK 却使用 `storage=qiniu`，API 会返回明确错误，提示安装 `qiniu/php-sdk`。

## 本地运行

### 方式 A：本机运行 Symfony

```bash
symfony server:start
```

或：

```bash
php -S 127.0.0.1:8000 -t public
```

### 方式 B：Docker 开发环境

本地开发环境，一键启动所有服务（app、nginx、MySQL、Redis、Mailpit）：

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

应用访问地址：`http://localhost:${APP_PORT:-8080}`。

## 模块概览

| 模块 | 命名空间 | 用途 | 核心特性 |
|------|---------|------|---------|
| **Core** | `App\Core` | 框架基础 | RestController、BaseService、View mixin、表达式解析器 |
| **Common** | `App\Common` | CMS | 分类（树）、标签、内容、评论（多态）、页面、媒体、设置（KV） |
| **Trade** | `App\Trade` | 电商 | 产品 + 规格、订单（状态机）、价格计算管道 |
| **Inventory** | `App\Inventory` | 库存管理 | 门店物料库存 + 规格配方 + 预留（原子库存锁）+ 库存台账审计 + 负库存策略 |
| **Wallet** | `App\Wallet` | 钱包与抵扣 | 余额（分）、原子转账、凭证存款与取款（provider 权限）、幂等、钱包余额抵扣提供方、余额校验与对账 |
| **Payment** | `App\Payment` | 支付编排 | 发票（分+工作流）、网关抽象（mock/wallet/wechat）、**支付抵扣提供方契约**、Webhook、事件 |
| **Wechat** | `App\Wechat` | 微信集成 | 小程序/公众号登录、微信支付 V3、WechatUser（OneToOne→User） |
| **Storage** | `App\Storage` | 文件存储驱动 | `MediaStorageInterface`、LocalStorage、QiniuStorage、tagged iterator 自动发现 |
| **Promotion** | `App\Promotion` | DSL 驱动促销 | 自定义 DSL 词法/语法/求值器、7 种策略类型、作为 `trade.price_calculator`（优先级 60）、会员定向 SKU 折扣、多门店路由、`best_price` 冲突模式 |
| **Identity** | `App\Identity` | 鉴权 | JWT (RS256)、OTP (短信)、Refresh Token 轮换、Profile 实体（自动创建、等级、积分委托给 Wallet） |
| **Settlement** | `App\Settlement` | 分账与终态 | 已确认资金 → 不可变上下文 → 版本化规则 → 可审计的计划/分账 → 通过 Wallet 端口入账；18 位精确金额、最大余数舍入、原凭证冲正、SQL outbox/inbox、后台规则配置 |
| **Exchange** | `App\ExchangeBundle` *(设计)* | 资金池背书的点数经济 | 生效期汇率（混合：锚定 + 直接对）、bcmath 换算、质押/发行/兑换/赎回、造市商资金池 —— 仅设计文档，尚未实现 |

## API 路由

### Identity（`/api/auth`）

| 方法 | 路径 | 说明 |
|------|------|------|
| **POST** | **`/api/auth/register`** | **密码自注册 → 返回令牌** |
| POST | `/api/auth/login` | 账号 + 密码登录 |
| POST | `/api/auth/otp/request` | 请求短信验证码 |
| POST | `/api/auth/otp/verify` | 验证验证码 |
| POST | `/api/auth/token/refresh` | 刷新令牌 |
| POST | `/api/auth/logout` | 退出登录 |

### Profile (`/api/v1/app/profiles`, `/api/v1/manage/profiles`)

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/app/profiles` | 当前用户个人信息 |
| PUT | `/api/v1/app/profiles` | 更新昵称、头像、元数据 |
| GET/POST/PUT/DELETE | `/api/v1/manage/profiles[/{id}]` | 管理端 Profile CRUD（含等级） |

### User (`/api/v1/app/users`, `/api/v1/manage/users`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/users/me` | 当前用户个人信息 |
| PUT | `/api/v1/app/users/me` | 更新个人信息 |
| POST | `/api/v1/app/users/change-password` | 修改密码 |
| GET/POST/PUT/DELETE | `/api/v1/manage/users[/{id}]` | 管理员用户 CRUD |
| POST | `/api/v1/manage/users/{id}/change-password` | 管理员修改任意用户密码 |

### Common — App（公开只读）

| 方法 | 路径 |
|------|------|
| GET | `/api/v1/app/categories` |
| GET | `/api/v1/app/categories/{id}` |
| GET | `/api/v1/app/contents` |
| GET | `/api/v1/app/contents/{id}` |
| GET | `/api/v1/app/tags` |
| GET | `/api/v1/app/comments` |
| GET | `/api/v1/app/pages` |
| GET | `/api/v1/app/media` |
| GET | `/api/v1/app/settings` |

### Common — Manage（管理端 CRUD，ROLE_ADMIN）

| 方法 | 路径 |
|------|------|
| GET/POST | `/api/v1/manage/{resource}` |
| GET/PUT/DELETE | `/api/v1/manage/{resource}/{id}` |
| POST | `/api/v1/manage/{resource}/batch-update` |

资源：`categories`、`contents`、`tags`、`comments`、`pages`、`media`、`settings`

### Trade

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/app/products` | 产品列表 |
| GET | `/api/v1/app/orders` | 我的订单 |
| GET/POST/PUT/DELETE | `/api/v1/manage/products[/{id}]` | 产品 CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/specifications[/{id}]` | 规格 CRUD |
| POST | `/api/v1/manage/orders` | 创建订单（含计算） |
| GET | `/api/v1/manage/orders/todo` | 待处理订单 |
| GET | `/api/v1/manage/orders/{id}/transitions` | 可用状态转换 |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | 执行状态转换 |
| POST | `/api/v1/app/orders/{id}/payment` | 发起订单支付 |
| POST | `/api/v1/manage/orders/{id}/payment` | 管理端发起订单支付 |
| POST | `/api/v1/manage/orders/{id}/refund` | 通过关联发票退款 |

### Wallet

| 方法 | 路径 | 说明 |
|------|------|------|
| GET/POST/PUT/DELETE | `/api/v1/manage/wallets[/{id}]` | 钱包 CRUD |
| **GET** | **`/api/v1/manage/wallets/balance`** | **校验会计恒等式** |
| **POST** | **`/api/v1/manage/wallets/reconcile`** | **逐钱包对账** |
| GET | `/api/v1/manage/transactions` | 交易列表 |
| POST | `/api/v1/manage/transactions` | 原子转账（创建账本交易） |
| **POST** | **`/api/v1/manage/vouchers/deposit`** | **凭证存款（充值）** |
| **POST** | **`/api/v1/manage/vouchers/withdraw`** | **凭证取款（出账）** |
| POST | `/api/v1/manage/vouchers/{uuid}/reverse` | 反冲存款或取款凭证 |
| GET | `/api/v1/manage/vouchers[/{id}]` | 凭证列表/详情（管理端） |
| **POST** | **`/api/v1/app/vouchers/deposit`** | **向自己钱包自助存款** |
| **POST** | **`/api/v1/app/vouchers/withdraw`** | **从自己钱包自助取款** |
| POST | `/api/v1/app/vouchers/{uuid}/reverse` | 反冲自己的凭证 |

deposit/withdraw 的 `voucherType` 为请求参数：`Manage` 默认 `manual`（仅管理员），`App` 必须由外部集成方传入。类型的权限由已注册 provider 的 `assertPermitted()` 裁决。资金流向：存款 = 单边入账（`fromWallet = null`）；取款 = 单边出账（`toWallet = null`）；反冲把资金退回源钱包。

### Payment

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/app/invoices` | 用户发票列表 |
| GET | `/api/v1/app/invoices/{id}` | 发票详情 |
| POST | `/api/v1/app/invoices/{id}/pay/{payment}` | 通过网关支付发票 |
| GET | `/api/v1/manage/invoices` | 管理端发票列表 |
| POST | `/api/v1/manage/invoices` | 创建发票 |
| POST | `/api/v1/manage/invoices/{id}/pay/{payment}` | 管理端支付发票 |
| POST | `/api/v1/manage/invoices/{id}/cancel` | 取消未付发票 |
| POST | `/api/v1/manage/invoices/{id}/refund` | 退款已付发票 |
| GET | `/api/v1/manage/invoices/{id}/transitions` | 可用状态转换 |
| POST | `/api/payment/notify/{payment}` | 提供方回调 (webhook) |

### Wechat (`/api/wechat`)

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/wechat/miniapp/login` | 小程序登录（`js_code` → JWT） |
| POST | `/api/wechat/miniapp/phone` | 绑定微信手机号 |
| GET | `/api/wechat/oauth/url` | 公众号 OAuth 跳转地址 |
| POST | `/api/wechat/oauth/callback` | OAuth 回调（`code` → JWT） |
| GET | `/api/v1/app/wechat-users` | 用户范围 WechatUser CRUD |
| GET | `/api/v1/manage/wechat-users` | 管理员 WechatUser CRUD |

### Promotion

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/app/promotions` | 活跃促销列表 |
| GET | `/api/v1/app/promotions/{id}` | 促销详情 |
| GET/POST/PUT/DELETE | `/api/v1/manage/promotions[/{id}]` | 管理端促销 CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/promotion-templates[/{id}]` | 管理端促销模板 CRUD（DSL 编辑） |

### Settlement（管理端，ROLE_ADMIN）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/manage/settlement-plans` | 分账计划列表 |
| GET | `/api/v1/manage/settlement-plans/{id}` | 计划详情（含分账明细） |
| POST | `/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/post` | 将分账入账到 Wallet |
| POST | `/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/reverse` | 冲正已入账的分账 |
| GET | `/api/v1/manage/settlement-allocations` | 分账明细列表 |
| GET | `/api/v1/manage/settlement-allocations/{id}` | 分账明细详情 |
| GET | `/api/v1/manage/settlement-rules` | 规则列表 |
| GET | `/api/v1/manage/settlement-rules/{id}` | 规则详情 |
| POST | `/api/v1/manage/settlement-rules` | 创建规则草稿（`code`、`name`） |
| GET | `/api/v1/manage/settlement-rules/configuration` | 可接受的规则配置 schema |
| GET | `/api/v1/manage/settlement-rule-versions` | 规则版本列表 |
| GET | `/api/v1/manage/settlement-rule-versions/{id}` | 规则版本详情 |
| POST | `/api/v1/manage/settlement-rule-versions` | 创建草稿版本（`ruleUuid`、`definition`、`priority`、`effectiveFrom`） |
| PUT | `/api/v1/manage/settlement-rule-versions/{id}` | 更新草稿版本配置 |
| POST | `/api/v1/manage/settlement-rule-versions/{uuid}/publish` | 发布草稿版本 |
| GET | `/api/v1/manage/settlement-outbox-messages` | outbox 消息列表 |
| GET | `/api/v1/manage/settlement-outbox-messages/{id}` | outbox 消息详情 |
| GET | `/api/v1/manage/settlement-consumed-events` | 已消费资金事件列表 |
| GET | `/api/v1/manage/settlement-consumed-events/{id}` | 已消费事件详情 |

Settlement 通过版本化规则将已确认的资金分配给收款方，再通过 `SettlementVoucherPort` 边界将每条分账入账到 Wallet。收款方配置为 `{"type":"wallet","id":"<wallet-id>"}`。入账命令通过 Settlement outbox 异步投递（`app:settlement:outbox:publish`），可重试失败由 `app:settlement:allocations:requeue-due` 重新入队。

### 系统自省 (`/system`)

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/system/entities` | 列出所有 Doctrine 实体 FQCN |
| GET | `/system/entities/{entityName}` | 实体字段 + 关联元数据 |
| GET | `/system/router` | 列出所有已注册路由 |

### 平台运维端点（公开，无需认证）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/health/live` | 存活探针 |
| GET | `/health/ready` | 就绪探针（数据库必需，Redis 可选） |
| GET | `/metrics` | Prometheus 指标（文本格式） |

### 请求示例

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/manage/contents" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{"title":"Hello","body":"World"}'
```

### 响应格式

所有接口返回统一的 JSON 信封：

```json
{
  "data": {},
  "code": 200,
  "message": "SUCCESS",
  "paginator": {
    "page": 1,
    "limit": 20,
    "pages": 5,
    "total": 100
  }
}
```

## 服务层设计说明

`BaseService` 已按职责拆分为 `src/Core/Service/Concern` 下的 trait：

- **`BaseServiceInfrastructureTrait`**
  - EntityManager/Repository/Logger/Serializer 访问
  - RequestStack 与 Validator 封装
  - 事务封装（`wrapInTransaction`）
  - ExpressionService 与 LegacyEvaluator 的延迟初始化
- **`BaseServiceReadListTrait`**
  - `get()` 与 `list()` 的读取逻辑
  - 基于 QueryBuilder 的列表能力，请求参数驱动筛选/排序/分组/字段选择
  - DQL 编译（`ExpressionDqlParser`）加内存回退
- **`BaseServiceMutationTrait`**
  - `new()`、`update()`、`remove()`
  - 关系字段、日期字段、反射元数据处理
  - Symfony Serializer 处理标量字段
  - Symfony Validator 集成

外部契约通过 `BaseServiceInterface` 保持不变，兼容现有调用代码。

## 动态查询系统

`list()` 方法支持以下查询参数：

| 参数 | 说明 | 示例 |
|------|------|------|
| `page` | 页码 | `1` |
| `limit` | 每页条数 | `20` |
| `@filter` | 表达式 WHERE 条件 | `entity.status == "active"` |
| `@dql` | 原始 DQL 子查询 | `(entity.price > 100)` |
| `@order` | 排序字段 | `createdAt\|DESC` |
| `@select` | DQL SELECT 覆盖 | `entity.id, entity.name` |
| `@sort` | 内存排序回退 | `item.getPrice()` |
| `@expands` | 嵌套展开 | `category,tags` |
| `@display` | 字段投影 | `complex` / `reduce` |

过滤表达式支持：`==`、`!=`、`>`、`<`、`>=`、`<=`、`&&`、`||`、`!`、`matches`（正则），以及链式属性（`entity.getCategory().getName()`）。

## 如何创建自己的 CRUD 模块

详见 **[模块设计契约](docs/design/module-design.md)**。

简要步骤：

1. 在 `src/{Module}/Entity` 创建 Doctrine 实体。
2. 创建继承 `BaseService` + 实现 `{Name}ServiceInterface` 的服务类。
3. 创建继承 `ServiceEntityRepository` 的仓库类。
4. 创建 App（公开只读）和 Manage（管理端 CRUD）控制器，组合 mixin trait。
5. 在 `config/routes.yaml` 注册路由。
6. 创建 Doctrine 迁移。

最小控制器示例：

```php
namespace App\Common\Controller\App;

use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;

class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly ContentServiceInterface $service
    ) {}
}
```

注意：继承 `RestController` 的控制器会通过 `#[Required]` setter 注入方式自动获取 `RequestStack`、`SerializerInterface` 和 `TranslatorInterface`。你只需要在构造函数中声明模块专属的依赖。

## 文档说明

- **[设计契约](docs/design/)** — 系统架构、API 设计、数据模型、模块设计、控制器契约、跨切面契约
- **[Bundle 设计文档](docs/design/bundles/)** — 各模块设计文档（Core、Common、Trade、Wallet、Identity、Promotion、Settlement）
- **[Runbooks 运维手册](docs/runbooks/)** — 分步操作指南（Promotion、Settlement）
- **[AI 上下文](docs/ai/context.md)** — 为 AI 辅助编程准备的完整代码库快照
- **[API 文档](/api/doc)** — 交互式 Swagger UI（本地运行时可用）
- **[QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md)** — 5-10 分钟快速上手

## 测试

**默认套件 2224 个测试 · 7951 个断言**（另有 **477 个低价值测试** 默认排除）。测试按层组织在 `tests/` 下：

- `tests/UnitTest/` — 纯单元测试（无 kernel/DB），命名空间 `App\Tests\UnitTest\...`
- `tests/Integration/` — kernel + DB + HTTP 测试及共享 helper（`DatabaseBootstrapTrait`、`IntegrationWebTestCase`），命名空间 `App\Tests\Integration\...`
- `tests/LowValue/` — 测试审计标记的弃用/低价值测试；默认运行排除，通过 `--group low-value` 执行

串行运行全部测试：

```bash
./vendor/bin/phpunit
```

并行运行（约 2-3 倍加速，内置每 worker SQLite 隔离）：

```bash
PARATEST=1 ./vendor/bin/paratest --processes 8 --runner WrapperRunner
```

运行单个测试文件：

```bash
./vendor/bin/phpunit tests/UnitTest/Core/Service/BaseServiceInfrastructureTraitTest.php
```

显式运行被排除的低价值测试：

```bash
./vendor/bin/phpunit --group low-value
```

带覆盖率报告：

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

生成 HTML 覆盖率报告：

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html var/coverage
```

`phpunit.dist.xml` 已配置 `APP_ENV=test` 以及 `KERNEL_CLASS=App\Kernel`。

### 静态分析

项目需要 PHP 8.4 或更高版本。运行与 CI 相同的静态检查：

```bash
composer phpstan
composer rector:types:check
```

PHPStan 以 Level 8 检查其配置的 `src/` 范围。CI 中的 Rector 仅检查 Doctrine Collection/Repository 的 PHPDoc 类型规则；`composer rector` 是更广泛的可选重构命令，应用前应审查其改动。

### 按层统计的测试覆盖

| 层 | 约计数 | 覆盖范围 |
|------|-------|----------|
| UnitTest | 189 个文件 | 实体、工具、DSL 引擎、促销策略、基于 mock 的服务/控制器、工作流状态机 |
| Integration | 71 个文件 | 跨模块流程、API 回归、outbox/inbox 幂等、并发、健康/指标/限流端点 |
| LowValue | 43 个文件 | 审计标记的重复测试与追覆盖率测试（默认排除） |

测试质量契约见 [docs/testing/crud-skeleton-production/](docs/testing/crud-skeleton-production/README.md)，标记低价值测试的审计见 [docs/issues/test-audit-2026-08-09/](docs/issues/test-audit-2026-08-09/README.md)。

## Docker 部署

### 架构

```mermaid
flowchart LR
    Client[客户端 / 浏览器] -->|:8080| Nginx[nginx:alpine]
    Nginx -->|/api/*| Fpm["PHP-FPM 8.4<br/>(app, Symfony)"]
    Nginx -->|/api/doc| Swagger[Swagger UI<br/>NelmioApiDoc]
    Fpm --> MySQL[(MySQL 8)]
    Fpm --> Redis[(Redis 7<br/>OTP / 缓存)]
    Fpm --> Mailpit[Mailpit<br/>邮件开发]
    Fpm --> Worker[Messenger worker<br/>handler / outbox]
    Fpm --> Scheduler[Scheduler<br/>outbox 发布]
```

| 服务 | 镜像 | 容器 | 用途 |
|------|------|------|------|
| **nginx** | `nginx:alpine` | 反向代理 | 路由请求到 PHP-FPM，处理静态文件 |
| **app** | `Dockerfile` 构建 | PHP-FPM 8.4 | Symfony 应用 |
| **database** | `mysql:8.4` | MySQL 8 | 持久化数据存储 |
| **redis** | `redis:7-alpine` | Redis 7 | OTP 存储、缓存 |
| **mailer** | `axllent/mailpit` | Mailpit | 开发环境邮件查看器 |

### 开发环境

```bash
# 一键启动。本地 Docker 开发不需要创建 env 文件。
docker compose up -d --build

# 首次运行：数据库迁移 + 创建管理员
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 应用 → http://localhost:8080   Swagger → http://localhost:8080/api/doc
```

启动时自动完成：
- `docker/app/entrypoint.sh` 会在挂载的 `./var/jwt` 目录下生成一次开发 JWT 密钥，后续启动复用
- `compose.override.yaml` 自动加载开发配置（`APP_ENV=dev`、`APP_DEBUG=1`）
- `compose.yaml` 提供安全的开发默认密钥
- 所有可选功能（微信、短信）默认禁用 — 如需启用可使用 `.env` 或 `--env-file`

如果需要定制 Docker 端口、数据库密码或可选集成，建议显式传入 Docker env 文件：

```bash
cp .env.example .env.docker.local
docker compose --env-file .env.docker.local up -d --build
```

不要把生产密钥写入已提交的 `.env` 文件。

### 生产环境

#### 第一步：准备生产 env 文件

```bash
cp .env.prod.example .env.prod.local
```

编辑 `.env.prod.local`，至少设置：

```dotenv
APP_SECRET=你的64字符随机密钥
REFRESH_TOKEN_SECRET=你的32字节随机密钥
MYSQL_PASSWORD=你的数据库密码
MYSQL_ROOT_PASSWORD=你的 root 数据库密码
DEFAULT_URI=https://api.example.com
```

可选集成可以留空。微信、短信变量留空时，对应功能自动禁用。

#### 第二步：在主机上生成 JWT 密钥

密钥通过 `./var` 绑定挂载持久化在容器外：

```bash
mkdir -p var/jwt
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
chmod 600 var/jwt/jwt_private.pem
```

> 如果私钥有密码，在 `.env.prod.local` 中设置 `JWT_PASSPHRASE`。

#### 第三步：启动

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
```

#### 第四步：初始化

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

#### 第五步：验证

```bash
curl -s http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

### 环境变量参考

**生产必填**：

| 变量 | 用途 |
|------|------|
| `APP_SECRET` | Symfony 应用密钥 |
| `REFRESH_TOKEN_SECRET` | Refresh Token 的 HMAC-SHA256 密钥 |
| `MYSQL_PASSWORD` | MySQL 应用用户密码 |
| `MYSQL_ROOT_PASSWORD` | MySQL root 密码 |

**compose.yaml 已提供**（开发默认值，生产环境按需覆盖）：

| 变量 | Docker 默认值 |
|------|---------------|
| `DATABASE_URL` | `mysql://app:...@database:3306/app` |
| `MAILER_DSN` | `smtp://mailer:1025` |
| `OTP_REDIS_DSN` | `redis://redis:6379/0` |
| `JWT_PRIVATE_KEY_PATH` | `/var/www/html/var/jwt/jwt_private.pem` |
| `JWT_PUBLIC_KEY_PATH` | `/var/www/html/var/jwt/jwt_public.pem` |

**可选功能**（留空表示禁用）：

| 功能 | 需要的变量（完整列表见 `.env.example` 或 `.env`） |
|------|---------------------------------------------------|
| 阿里云短信 | `ALIYUN_ACCESS_KEY_ID`、`ALIYUN_ACCESS_KEY_SECRET` 等 |
| 微信小程序 | `WECHAT_MINIAPP_APP_ID`、`WECHAT_MINIAPP_SECRET` |
| 微信公众号 | `WECHAT_OFFICIAL_APP_ID`、`WECHAT_OFFICIAL_SECRET` 等 |
| 微信支付 V3 | `WECHAT_PAY_MCH_ID`、`WECHAT_PAY_SECRET_KEY` 等 |

### 常用命令

下面命令用于 Docker 开发环境。生产环境请在 `docker compose` 后追加 `-f compose.yaml -f compose.prod.yaml --env-file .env.prod.local`。

```bash
# 查看日志
docker compose logs -f app

# 运行 Symfony 命令
docker compose exec app php bin/console about

# 进入 app 容器
docker compose exec app bash

# 清除缓存
docker compose exec app php bin/console cache:clear

# 查看待执行的迁移
docker compose exec app php bin/console doctrine:migrations:status

# 停止所有服务
docker compose down

# 重置并重启（警告：删除所有数据）
docker compose down -v && docker compose up -d --build
```

### 自定义 nginx 配置

修改 `docker/nginx/default.conf` 文件。常见定制：
- 添加 TLS/SSL 证书并监听 443 端口
- 将 `server_name` 改为你的域名
- 添加速率限制或 IP 白名单

修改后重建：
```bash
docker compose up -d --build nginx
```

### 升级

开发环境：

```bash
git pull
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console cache:clear
```

生产环境：

```bash
git pull
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console cache:clear
```

## 常见问题

### 运行 PHPUnit 提示 PHP 版本过低

请确认当前 CLI PHP 版本满足 `composer.json` 要求（`>= 8.4`）。

### 数据库连接失败

- 检查 `DATABASE_URL`。
- 确认 MySQL 正在运行（`docker compose ps`）。
- 确认数据库用户名、密码、库名与 compose 配置一致。

### 返回结果为空或序列化异常

检查 serializer 服务配置，以及 `@display`、`@expands`、`@filter` 等请求参数。

### 鉴权返回 401

- 按 `QUICKSTART.zh-cn.md` 步骤生成 JWT 密钥。
- 确认请求头含 `Authorization: Bearer {token}`。
- 检查令牌是否过期（默认 7200 秒）。

## 贡献指南

1. Fork 后创建功能分支。
2. 遵循[设计契约](docs/design/)保证一致性。
3. 保持 PR 小而聚焦。
4. 行为变化请补充/更新测试。
5. 使用 conventional commit 信息（如 `feat(module): 描述`）。

## 国际化（i18n）

项目通过 Symfony Translation 组件支持国际化。翻译文件存储在 `translations/` 目录下。

### 支持的语言

| 语言代码 | 文件 | 语言 |
|----------|------|------|
| `en` | `translations/messages.en.yaml` | 英语（默认） |
| `zh` | `translations/messages.zh.yaml` | 简体中文 |
| `zh_Hant` | `translations/messages.zh_Hant.yaml` | 繁体中文 |
| `ja` | `translations/messages.ja.yaml` | 日语 |

### 工作原理

1. **异常消息** — 所有 API 路由上未捕获的异常会经过 `ExceptionInterceptor`，调用 `$this->translator->trans($exception->getMessage())`，异常消息原文作为翻译键。
2. **控制器错误响应** — `RestController::warning()`、`AuthController::error()`、`OtpController::error()`、`LoginController::error()` 均走翻译流程。
3. **JWT 认证失败** — `JwtAuthenticator::onAuthenticationFailure()` 在返回 JSON 响应前翻译错误消息。
4. **实体字段名** — `/system/entities/{entityName}` 接口会翻译字段名称（如 `createdAt` → `Created at` → `创建时间`）。

### 语言检测

`LocaleListener`（`src/Core/EventListener/LocaleListener.php`）自动检测用户语言：

1. **查询参数** — `?_locale=zh` 优先级最高
2. **Accept-Language 请求头** — 读取浏览器 `Accept-Language` 头并映射到支持的语言：
   - `zh-CN`、`zh-Hans` → `zh`（简体）
   - `zh-TW`、`zh-HK`、`zh-Hant` → `zh_Hant`（繁体）
   - `ja-JP` → `ja`（日语）
3. **回退** — 不支持的语言自动回退到 `en`（配置的 `default_locale`）。

### 添加新语言

1. 创建翻译文件：`translations/messages.{locale}.yaml`
2. 在 `src/Core/EventListener/LocaleListener.php` 的 `SUPPORTED_LOCALES` 和 `LOCALE_MAP` 中添加语言代码
3. 翻译配置文件（`config/packages/translation.yaml`）会自动发现 `translations/` 目录下的新文件。

### 多语言文档

| 语言 | 文件 |
|------|------|
| English | [README.md](README.md) |
| Chinese (Traditional) | [README.zh-hant.md](README.zh-hant.md) |
| Japanese | [README.ja.md](README.ja.md) |

## 许可证

Apache-2.0。详见 [LICENSE](LICENSE)。
