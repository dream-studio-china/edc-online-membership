# CRUD Skeleton

一个面向生产实践的 Symfony 8.1 API 骨架，内置可复用的服务层抽象、模块化架构、JWT 鉴权、动态查询引擎以及可插拔的业务模块。

> English version: 见 `README.md`

> 文档站点: [GitHub Pages](https://immane.github.io/crud-skeleton) | 设计契约: [docs/design/](docs/design/)

## 目录

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
- **模块化架构**：Core 框架 + Common（CMS） + Trade（电商） + Payment（支付） + Wallet（钱包） + Wechat（微信登录+支付） + Storage（文件存储驱动） + Identity（鉴权）。
- **JWT 鉴权**：RS256 访问令牌，HMAC-SHA256 Refresh Token 轮换，含重用检测。
- **OTP 登录**：基于手机验证码的短信登录，带频率限制（阿里云）。
- **订单状态机**：Symfony Workflow（草稿 → 完成），含完整工作流 API。
- **价格计算管道**：可插拔的价格计算器，按优先级排序执行。
- **支付抵扣提供方**：支付前钩子（如钱包抵扣）在网关处理前减少发票金额 — 网关仅接收显式金额。
- **原子钱包转账 + 系统注资**：死锁预防（统一锁定顺序）、乐观锁、引用 ID 幂等。
- **钱包余额抵扣**：钱包拥有的抵扣生命周期，通过 Payment 抵扣提供方模式接入 — Payment 编排，Wallet 实现。
- **可插拔文件存储**：`MediaStorageInterface`，本地与七牛 Kodo 驱动 — tagged iterator 自动发现。
- **OpenAPI 文档**：NelmioApiDocBundle + `#[OA\*]` 属性，`/api/doc` 提供 Swagger UI。
- **系统自省**：实体元数据和路由导出接口（`/system/*`）。
- **完善的测试**：约 110+ 个测试文件，1069 个测试，~3666 个断言，87.83% 覆盖。
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
| 测试 | PHPUnit `^12.5` |
| 前端 | Stimulus + Turbo（AssetMapper） |
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
│   ├── Wallet/                    # 钱包模块
│   │   ├── Controller/Manage/    #   钱包、交易、转账 API
│   │   ├── DTO/                  #   WalletPaymentDeductionRequest
│   │   ├── Entity/               #   Wallet, WalletTransaction, WalletPaymentDeduction
│   │   ├── Repository/           #   + WalletPaymentDeductionRepository
│   │   └── Service/              #   TransferService, WalletService
│   │       └── Payment/          #   WalletGateway, WalletBalanceAdjustmentProvider, WalletPaymentDeductionService
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
│   └── Identity/                 # 鉴权模块
│       ├── Controller/App/       #   UserController (个人信息、改密码)
│       ├── Controller/Manage/    #   UserController (管理员 CRUD)
│       ├── Command/              #   CreateUserCommand (CLI)
│       ├── Controller/           #   AuthController、OtpController
│       ├── Entity/               #   User、RefreshToken
│       ├── Security/             #   JwtAuthenticator、TokenManager
│       └── Service/              #   OtpService、短信供应商
├── config/                       # Symfony 配置
│   └── packages/                 #   Doctrine、Security、Workflow、Serializer 等
├── migrations/                   # Doctrine 迁移（8 个版本）
├── tests/                        # ~110+ 个 PHPUnit 测试文件（1069 测试，~3666 断言）
├── docs/                         # 项目文档
│   ├── design/                   #   设计契约（系统、API、数据、模块、控制器）
│   │   └── bundles/              #   各模块设计文档
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

Docker 开发环境无需创建 env 文件即可启动。本机 PHP/Symfony 运行时，建议在 `.env.local` 中覆盖本地配置：

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
| **Wallet** | `App\Wallet` | 钱包与抵扣 | 余额（分）、原子转账、系统注资、幂等、钱包余额抵扣提供方、余额校验与对账 |
| **Payment** | `App\Payment` | 支付编排 | 发票（分+工作流）、网关抽象（mock/wallet/wechat）、**支付抵扣提供方契约**、Webhook、事件 |
| **Wechat** | `App\Wechat` | 微信集成 | 小程序/公众号登录、微信支付 V3、WechatUser（OneToOne→User） |
| **Storage** | `App\Storage` | 文件存储驱动 | `MediaStorageInterface`、LocalStorage、QiniuStorage、tagged iterator 自动发现 |
| **Identity** | `App\Identity` | 鉴权 | JWT (RS256)、OTP (短信)、Refresh Token 轮换 |

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
| POST | `/api/v1/manage/transfer` | 原子转账 |

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

### 系统自省 (`/system`)

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/system/entities` | 列出所有 Doctrine 实体 FQCN |
| GET | `/system/entities/{entityName}` | 实体字段 + 关联元数据 |
| GET | `/system/router` | 列出所有已注册路由 |

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

    protected ?string $serviceClass = ContentServiceInterface::class;

    public function __construct(
        protected readonly ContentServiceInterface $service
    ) {}
}
```

注意：继承 `RestController` 的控制器会通过 `#[Required]` setter 注入方式自动获取 `RequestStack`、`SerializerInterface` 和 `TranslatorInterface`。你只需要在构造函数中声明模块专属的依赖。

## 文档说明

- **[设计契约](docs/design/)** — 系统架构、API 设计、数据模型、模块设计、控制器契约、跨切面契约
- **[Bundle 设计文档](docs/design/bundles/)** — 各模块设计文档（Core、Common、Trade、Wallet、Identity）
- **[AI 上下文](docs/ai/context.md)** — 为 AI 辅助编程准备的完整代码库快照
- **[API 文档](/api/doc)** — 交互式 Swagger UI（本地运行时可用）
- **[QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md)** — 5-10 分钟快速上手

## 测试

运行全部测试：

```bash
./vendor/bin/phpunit
```

运行单个测试：

```bash
./vendor/bin/phpunit tests/Core/Service/BaseServiceUnitTest.php
```

带覆盖率（CI 强制 85% 最低线）：

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

`phpunit.dist.xml` 已配置 `APP_ENV=test` 以及 `KERNEL_CLASS=App\Kernel`。

## Docker 部署

### 架构

```
                ┌──────────────┐
   :8080  ──────│    nginx     │────── /api/* ──────┐
                └──────────────┘                     │
                                                    ▼
                                            ┌──────────────┐
                                            │  PHP-FPM 8.4 │
                                            │   (app)      │
                                            └──────┬───────┘
                                                   │
                      ┌────────────────────────────┼────────────────────┐
                      │                            │                    │
                ┌─────▼─────┐              ┌──────▼──────┐      ┌──────▼──────┐
                │  MySQL 8   │              │    Redis 7   │      │   Mailpit   │
                │            │              │  (OTP/缓存)  │      │ (邮件开发)  │
                └───────────┘              └─────────────┘      └─────────────┘
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

## 许可证

Apache-2.0。详见 [LICENSE](LICENSE)。
