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
- [Docker 说明](#docker-说明)
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
- 原子的钱包转账，含死锁预防、乐观锁和幂等性。
- JWT 鉴权（RS256）配合 Refresh Token 轮换，以及手机验证码登录。
- 完整的设计契约文档，确保新模块开发一致性。

## 功能特性

- **CRUD 服务抽象**：`new()`、`get()`、`list()`、`update()`、`updateWithoutListener()`、`remove()`。
- **动态查询系统**：通过请求参数控制筛选/排序/排序/分组/字段选择，表达式编译为 DQL。
- **Trait 组合式控制器**：9 个 mixin trait（List、Detail、Create、Update、Delete、Workflow、Singleton、Transform）可按需组合。
- **模块化架构**：Core 框架 + Common（CMS） + Trade（电商） + Wallet（钱包） + Identity（鉴权）。
- **JWT 鉴权**：RS256 访问令牌，HMAC-SHA256 Refresh Token 轮换，含重用检测。
- **OTP 登录**：基于手机验证码的短信登录，带频率限制（阿里云）。
- **订单状态机**：Symfony Workflow（草稿 → 完成），含完整工作流 API。
- **价格计算管道**：可插拔的价格计算器，按优先级排序执行。
- **原子钱包转账**：死锁预防（统一锁定顺序）、乐观锁、引用 ID 幂等。
- **OpenAPI 文档**：NelmioApiDocBundle + `#[OA\*]` 属性，`/api/doc` 提供 Swagger UI。
- **完善的测试**：约 79 个测试文件，CI 强制 80% 覆盖率。
- **Docker Compose**：PostgreSQL 16 + Mailpit 开发环境。

## 技术栈

| 组件 | 技术 |
|------|------|
| 语言 | PHP `>= 8.4` |
| 框架 | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| 数据库 | PostgreSQL 16（生产）/ SQLite（测试） |
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
│   │   ├── Controller/App/       #   Product、Order 列表
│   │   ├── Controller/Manage/    #   Product、Specification、Order（CRUD + 工作流）
│   │   ├── Entity/               #   Product、Specification、Order、OrderItem
│   │   ├── Service/              #   OrderService、价格计算管道
│   │   └── Service/Pricing/      #   PriceCalculatorInterface + 3 个实现
│   ├── Wallet/                   # 钱包模块
│   │   ├── Controller/Manage/    #   Wallet、Transaction、Transfer
│   │   ├── Entity/               #   Wallet、WalletTransaction
│   │   └── Service/              #   TransferService（原子转账）
│   └── Identity/                 # 鉴权模块
│       ├── Controller/           #   AuthController、OtpController
│       ├── Entity/               #   User、RefreshToken
│       ├── Security/             #   JwtAuthenticator、TokenManager
│       └── Service/              #   OtpService、短信供应商
├── config/                       # Symfony 配置
│   └── packages/                 #   Doctrine、Security、Workflow、Serializer 等
├── migrations/                   # Doctrine 迁移（5 个版本）
├── tests/                        # ~79 个 PHPUnit 测试文件
├── docs/                         # 项目文档
│   ├── design/                   #   设计契约（系统、API、数据、模块、控制器）
│   │   └── bundles/              #   各模块设计文档
│   └── ai/                       #   AI 上下文快照
├── compose.yaml                  # PostgreSQL 16
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

### 3) 准备环境变量

建议在 `.env.local` 中覆盖本地配置：

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

## 配置说明

关键环境变量（参考 `.env` 和 `.env.example`）：

| 变量 | 用途 |
|------|------|
| `APP_ENV` | 运行环境（`dev`/`prod`/`test`） |
| `APP_SECRET` | Symfony 应用密钥 |
| `DATABASE_URL` | PostgreSQL 连接字符串 |
| `JWT_PRIVATE_KEY_PATH` | RS256 私钥路径 |
| `JWT_PUBLIC_KEY_PATH` | RS256 公钥路径 |
| `JWT_PASSPHRASE` | 密钥密码 |
| `JWT_REFRESH_TOKEN_SECRET` | HMAC-SHA256 密钥 |
| `MAILER_DSN` | 邮件发送器 |

生产环境请不要在仓库中提交明文密钥。

## 本地运行

### 方式 A：本机运行 Symfony

```bash
symfony server:start
```

或：

```bash
php -S 127.0.0.1:8000 -t public
```

### 方式 B：使用 Docker 启动数据库

```bash
docker compose up -d
```

然后执行数据库迁移：

```bash
php bin/console doctrine:migrations:migrate
```

## 模块概览

| 模块 | 命名空间 | 用途 | 核心特性 |
|------|---------|------|---------|
| **Core** | `App\Core` | 框架基础 | RestController、BaseService、View mixin、表达式解析器 |
| **Common** | `App\Common` | CMS | 分类（树）、标签、内容、评论（多态）、页面、媒体、设置（KV） |
| **Trade** | `App\Trade` | 电商 | 产品 + 规格、订单（状态机）、价格计算管道 |
| **Wallet** | `App\Wallet` | 钱包 | 余额（分）、原子转账、幂等、乐观锁 |
| **Identity** | `App\Identity` | 鉴权 | JWT (RS256)、OTP (短信)、Refresh Token 轮换 |

## API 路由

### Identity（`/api/auth`）

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/auth/login` | 账号 + 密码登录 |
| POST | `/api/auth/otp/request` | 请求短信验证码 |
| POST | `/api/auth/otp/verify` | 验证验证码 |
| POST | `/api/auth/token/refresh` | 刷新令牌 |
| POST | `/api/auth/logout` | 退出登录 |

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

### Wallet

| 方法 | 路径 | 说明 |
|------|------|------|
| GET/POST/PUT/DELETE | `/api/v1/manage/wallets[/{id}]` | 钱包 CRUD |
| GET | `/api/v1/manage/transactions` | 交易列表 |
| POST | `/api/v1/manage/transfer` | 原子转账 |

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
  - `new()`、`update()`、`updateWithoutListener()`、`remove()`
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

带覆盖率（CI 强制 80% 最低线）：

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

`phpunit.dist.xml` 已配置 `APP_ENV=test` 以及 `KERNEL_CLASS=App\Kernel`。

## Docker 说明

仓库包含：

- `compose.yaml` - PostgreSQL 16 服务
- `compose.override.yaml` - 本机端口映射与 Mailpit

默认端口：

- PostgreSQL: `5432`
- Mailpit SMTP: `1025`
- Mailpit UI: `8025`

## 常见问题

### 运行 PHPUnit 提示 PHP 版本过低

请确认当前 CLI PHP 版本满足 `composer.json` 要求（`>= 8.4`）。

### 数据库连接失败

- 检查 `DATABASE_URL`。
- 确认 PostgreSQL 正在运行（`docker compose ps`）。
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

当前 `composer.json` 中标记为 `proprietary`。

如果你要公开发布仓库，建议补充标准 `LICENSE` 文件并同步更新元数据。
