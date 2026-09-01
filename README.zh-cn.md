# CRUD Platform

CRUD Platform 是从 [crud-skeleton](https://github.com/immane/crud-skeleton) 演进而来的面向生产环境的 Symfony 微服务平台，从模块化单体架构演进而来，具备 DDD、服务隔离和事件驱动通信能力。

> English: [README.md](README.md) · 繁體中文: [README.zh-hant.md](README.zh-hant.md) · 日本語: [README.ja.md](README.ja.md)

---

## 项目目标

目标是构建一个**单仓库、多独立部署 Symfony 应用**的平台。每个服务将独立拥有
Kernel、配置、数据库和迁移、消息队列、worker、定时任务、Docker 镜像、测试和 CI。

当前仓库仍是**模块化单体**，不是已经完成的微服务系统：它共享一个 Kernel、Composer
项目、容器、数据库、迁移链、Messenger 队列、worker、scheduler 和 Docker 镜像。
`Trade → Store → Inventory` 的 Outbox/Inbox 链路是首个提取边界。

目标目录、边界规则与提取准入条件见
[微服务迁移契约](docs/design/microservice-transition.md)。

---

## 架构

### 服务拓扑

```
                    ┌──────────────────────────────────────────────┐
                    │              API 网关 / 边缘路由              │
                    └──────┬──────┬──────┬───────────┬────────┬────┘
                           │      │      │           │        │ 
    ┌──────────┐  ┌────────┴─┐ ┌──┴───┐ ┌┴──────┐ ┌──┴───┐ ┌──┴────┐
    │ 身份认证 │  │  交易     │ │门店  │ │内容   │ │钱包  │ │支付   │
    │  :8085   │  │  :8087   │ │:8081 │ │:8086  │ │:8084 │ │:8083  │
    └────┬─────┘  └────┬─────┘ └──┬───┘ └──┬────┘ └──┬───┘ └───┬───┘
         │             │          │        │         │         │
    ┌────┴────┐   ┌────┴──────┐ ┌─┴───┐ ┌──┴───┐ ┌───┴───┐ ┌───┴───┐
    │   DB    │   │    DB     │ │ DB  │ │  DB  │ │  DB   │ │  DB   │
    │identity │   │  trade    │ │store│ │common│ │wallet │ │payment│
    └─────────┘   └───────────┘ └─────┘ └──────┘ └───────┘ └───────┘

    ┌──────────┐
    │  库存    │  + 根单体 (app :8080) — 过渡宿主
    │  :8082   │  + Worker + Scheduler（共享）
    └────┬─────┘  + Redis + Mailpit
    ┌────┴────┐
    │   DB    │
    │inventory│
    └─────────┘
```

### 事件驱动集成（Outbox / Inbox）

```
  Trade                    Store                   Inventory
  ┌──────────┐ outbox      ┌──────────┐ outbox     ┌──────────┐
  │ 订单     │──订单──→   │ 门店     │──预留──→   │ 物料     │
  │ 已创建   │ created.v1 │ 订单     │ request.v1 │ 预留     │
  │          │←─接受────  │ 已接受   │←─确认────  │ 确认     │
  │          │ accepted   │          │ confirmed  │          │
  └──────────┘            └──────────┘            └──────────┘
       │                        │                       │
       └── store.directory.upserted.v1 ──→ 本地投影
```

### 分层架构（每服务）

```
  HTTP 控制器    ←  唯一接触 Request/Response 的层
        │
  服务层         ←  所有业务逻辑、事务、验证
        │
  仓库层         ←  数据访问（Doctrine）
        │
  实体 / 领域    ←  持久化和聚合不变性
        │
  基础设施       ←  ORM、缓存、序列化器（框架提供）
```

### 仓库布局

```
├── apps/                         # 可独立部署的服务
│   ├── identity/                 # App\Identity — 认证、JWT、OTP、微信登录
│   │   ├── src/Main/             #   账户、Profile、Refresh Token
│   │   └── src/Wechat/           #   微信小程序 / 公众号 OAuth 适配器
│   ├── common/                   # App\Common — CMS、媒体、分类、标签
│   │   ├── src/Main/             #   内容实体与 CRUD
│   │   └── src/Storage/          #   可插拔文件上传（本地、七牛）
│   ├── trade/                    # App\Trade — 订单、商品、定价
│   │   ├── src/Trade/            #   订单工作流、Outbox、消息处理器
│   │   └── src/Promotion/        #   DSL 驱动促销引擎，7 种策略
│   ├── store/                    # App\Store — 多门店运营
│   ├── inventory/                # App\Inventory — 库存预留、配方
│   ├── payment/                  # App\Payment — 发票、网关、调整
│   ├── wallet/                   # App\Wallet — 账本、转账、扣款
├── packages/
│   ├── platform-kernel/          # App\Core 框架（RestController、DQL、工具集）
│   ├── integration-contracts/    # 版本化中性事件载体
│   └── legacy-messenger-compat/  # 历史 Messenger 包装 FQCN
├── src/                          # 根单体（仅过渡宿主）
│   ├── Bridge/                   #   组合适配器（根 → 服务端口）
│   └── Kernel.php                #   根 Kernel
├── config/                       # 根服务装配、路由、Doctrine 映射
├── docs/                         # 设计契约、AI 上下文
└── scripts/                      # 冒烟测试、覆盖率工具、交易演示
```

### 提取状态

| 目标上下文 | 已迁移至 | 状态 |
|---|---|---|
| Platform Kernel | `packages/platform-kernel` | 共享框架库 |
| Commerce | `apps/trade`（Trade + Promotion） | 已提取；仍保留 Payment 直接依赖 |
| Store Operations | `apps/store` | 已提取 |
| Inventory | `apps/inventory` | 已提取；生产环境受限 |
| Payments | `apps/payment`（网关、调整） | 已提取；网关由 Payment 应用持有 |
| Wallet/Ledger | `apps/wallet` | 已提取；仅用 `ownerUuid` |
| Identity & Access | `apps/identity`（Main + 微信登录） | 已提取 |
| Content/Media | `apps/common`（CMS + Storage） | 已提取 |

---

## 当前能力

- **框架**：Symfony 8.1、PHP 8.4+、Doctrine ORM 3.6、MySQL 8、SQLite 测试环境。
- **身份与访问**：RS256 JWT、Refresh Token 轮换、OTP/SMS（阿里云）、密码登录、
  微信小程序 / 公众号 OAuth 登录。
- **交易**：商品目录、订单状态机（draft→completed→refunded）、门店感知定价管线
  （基础→数量→小计→促销）、多门店接单/拒绝工作流。
- **促销引擎**：自定义 DSL 词法/语法分析器/解释器，7 种策略类型（折扣、赠品、
  阶梯、满减、包邮、会员、第 N 件折扣）。
- **门店运营**：多门店目录、门店范围订单、会员、员工订单管理。
- **库存**：物料主数据、规格配方、原子库存预留、每店 `allowNegativeStock` 策略
  （默认关闭，未达生产标准）。
- **支付**：发票生命周期（pending→paid→refunded）、多网关注册中心（mock、钱包、
  微信支付 V3）、支付前调整管线。
- **钱包**：复式账本、转账、支付扣款、余额审计、乐观锁、幂等充值。
- **CMS 与媒体**：分类、标签、内容、评论、页面、设置，可插拔媒体存储（本地、七牛）。
- **集成**：版本化 Trade/Store/Inventory/Payment 事件，Outbox/Inbox 幂等，
  correlation/causation 链路传播，10 个中性事件载体。
- **国际化**：Symfony Translation — 英文、简体中文、繁体中文、日文（每语言 ~280 条）。
- **API 文档**：NelmioApiDoc + Swagger UI `/api/doc`，自动标签，44+ 命名 Schema。

---

## 快速开始

Docker 是推荐的本地运行方式。在仓库根目录执行，主机只需要 Docker：

```bash
docker compose up -d --build

# 根单体
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 已提取应用
for svc in store-app inventory-app payment-app wallet-app identity-app common-app trade-app; do
  docker compose exec $svc php bin/console doctrine:migrations:migrate --no-interaction
done

# 创建管理员
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 验证
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

| 服务 | 端口 | 数据库 | 状态 |
|---|---|---|---|
| 根单体 (app) | `8080` | `database` | 过渡宿主 |
| 门店 | `8081` | `store-database` | 已提取 |
| 库存 | `8082` | `inventory-database` | 已提取，受限 |
| 支付 | `8083` | `payment-database` | 已提取 |
| 钱包 | `8084` | `wallet-database` | 已提取 |
| 身份 | `8085` | `identity-database` | 已提取 |
| 内容 | `8086` | `common-database` | 已提取 |
| 交易 | `8087` | `trade-database` | 已提取 |

- OpenAPI UI：`http://localhost:8080/api/doc`
- Mailpit（邮件测试）：`http://localhost:8025`
- Worker/Scheduler 日志：`docker compose logs -f worker scheduler`

排障和本机 PHP 运行说明见 [QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md)。

---

## 开发

```bash
# 测试套件
./vendor/bin/phpunit                           # 根集成测试 + 剩余单元测试
composer coverage                              # 全部 8 套件 + 聚合门禁（>= 90%）

# 静态分析
composer phpstan                                # PHPStan Level 8
composer deptrac                                # 架构边界检查
composer rector:types:check                     # Rector 类型规则 dry-run

# 文档
mkdocs build --strict
```

**测试结构**：七个独立应用测试套件（common、identity、inventory、payment、store、
trade、wallet）与根集成测试（963 个）独立运行。聚合行覆盖率为 **91.36%**（1,785
个测试，6,098 个断言），通过 phpcov 合并门禁在 CI 中强制执行。

本机命令需使用 PHP 8.4+。

---

## 集成契约

十个版本化中性载体连接各服务：

| 类型 | 载体 | 方向 |
|---|---|---|
| 事件 | `trade.order.created.v1` | Trade → Store |
| 事件 | `trade.order.cancelled.v1` | Trade → Store |
| 事件 | `store.order.accepted.v1` | Store → Trade |
| 事件 | `store.order.rejected.v1` | Store → Trade |
| 事件 | `store.directory.upserted.v1` | Store → Trade（投影） |
| 命令 | `inventory.reservation.requested.v1` | Store → Inventory |
| 命令 | `inventory.reservation.release.requested.v1` | Store → Inventory |
| 事件 | `inventory.reservation.confirmed.v1` | Inventory → Store |
| 事件 | `inventory.reservation.rejected.v1` | Inventory → Store |
| 事件 | `inventory.reservation.released.v1` | Inventory → Store |
| 事件 | `payment.invoice.{paid,failed,cancelled,refunded}.v1` | Payment → Trade（进行中） |

每个信封包含 `eventId`、`type`、`version`、`aggregateType`、`aggregateId`、
`occurredAt`、`correlationId`、`causationId` 和 `payload`。发布者在同一事务中
原子写入 Outbox；消费者通过 `eventId` 实现 Inbox 幂等。

---

## 关键模式

| 模式 | 位置 | 说明 |
|---|---|---|
| **Outbox/Inbox** | Trade、Store、Inventory、Payment | 持久事件投递，幂等保证 |
| **链路追踪** | 所有 Outbox | cross-service 传播 `correlationId`/`causationId` |
| **UUID 标识** | Trade、Wallet | `UUID::v4()` 作为外部引用标识 |
| **货币以分为单位** | Wallet、Trade、Payment | `bigint` 分，API 边界 ×/÷100 |
| **状态机** | Trade | Symfony Workflow 驱动订单生命周期 |
| **定价管线** | Trade | 带优先级的 `PriceCalculatorInterface` |
| **网关注册中心** | Payment | `#[AutowireIterator]` 可插拔支付网关 |
| **调整管线** | Payment | 网关执行前的支付前扣减钩子 |
| **乐观锁** | Wallet | `#[ORM\Version]` 在 Wallet 实体 |
| **快照** | Trade | `OrderItem` 保存 `specSnapshot`/`productSnapshot` |
| **软删除** | Trade | Product、Specification 的 `isDeleted` 标志 |
| **commonFilter** | 控制器 | 用户范围或管理员范围的 QueryBuilder 注入 |
| **促销 DSL** | Promotion | 自定义词法/语法解析器/解释器 |
| **Dry-run 回填** | Trade、Store、Inventory | 可控分批复原的关联回填命令 |
| **Token 轮换** | Identity | HMAC-SHA256 Refresh Token + 重用检测 |

---

## 控制台命令

| 命令 | 服务 | 用途 |
|---|---|---|
| `app:identity:user:create` | Identity | 创建带角色的用户 |
| `app:trade:outbox:publish` | Trade | 投递未发布的集成事件 |
| `app:store:outbox:publish` | Store | 投递接单/拒绝事件 |
| `app:inventory:outbox:publish` | Inventory | 投递预留结果 |
| `app:payment:outbox:publish` | Payment | 投递发票生命周期事件 |
| `app:trade:outbox:backfill-correlation` | Trade | 关联回填（dry-run / --apply） |
| `app:store:outbox:backfill-correlation` | Store | 关联回填（dry-run / --apply） |
| `app:inventory:outbox:backfill-correlation` | Inventory | 关联回填（dry-run / --apply） |
| `app:payment:outbox:backfill-correlation` | Payment | 关联回填（dry-run / --apply） |
| `app:store:outbox:backfill-directory` | Store | 回填门店目录事件 |
| `app:inventory:reservations:release-expired` | Inventory | 释放过期预留 |
| `app:storage:qiniu:settings:init` | Common | 初始化七牛设置 |

---

## Docker Compose 拓扑

`compose.yaml` 共 **22 个服务**：

| 分组 | 服务 |
|---|---|
| 根 | `app`（FrankenPHP）、`worker`（Messenger 异步）、`scheduler`（outbox 投递） |
| 应用 | `store-app`、`inventory-app`、`payment-app`、`wallet-app`、`identity-app`、`common-app`、`trade-app` |
| 数据库 | `database`（根）、`store-database`、`inventory-database`、`payment-database`、`wallet-database`、`identity-database`、`common-database`、`trade-database` |
| 基础设施 | `redis`（OTP/缓存）、`mailer`（Mailpit） |

Worker 消费共享 `async` 传输。Scheduler 轮询 Trade、Store、Inventory、Payment
的 Outbox 发布，以及库存过期预留释放。

---

## 开发者入门

新加入项目？按以下顺序阅读[开发手册](docs/manual/index.md)：

1. [架构](docs/manual/architecture.md) — 理解服务拓扑、分层和模式
2. [快速上手](docs/manual/getting-started.md) — 搭建 Docker 环境并验证
3. [项目结构](docs/manual/project-structure.md) — 了解代码组织方式
4. [核心框架](docs/manual/core-framework.md) — `RestController`、`BaseService`、View Mixin、EventListener、Utils
5. [核心用法](docs/manual/core-usage.md) — 实战：构建 Controller、Service、文件上传、自定义 Action
6. [查询系统](docs/manual/query-system.md) — 掌握 `@filter`、`@sort`、`@order`、`@dql`、`@select`、`@groupBy`、`@expands`、`@display`、`@transform`
7. [API 契约](docs/manual/api-contracts.md) — 请求/响应信封、认证、分页、错误处理、API 文档
8. [开发契约](docs/manual/development-contracts.md) — 编码规则、分层边界、命名、安全

之后按需查阅：[测试](docs/manual/testing.md)、[集成事件](docs/manual/integration-events.md)、
[数据库与迁移](docs/manual/database-and-migrations.md)、[部署](docs/manual/deployment.md)、
[提取服务](docs/manual/extracting-a-service.md)、[国际化](docs/manual/i18n.md)。

## 文档

- [开发手册](docs/manual/index.md) — 完整开发者指南（16 份文档）
- [微服务迁移契约](docs/design/microservice-transition.md)
- [系统架构](docs/design/system-architecture.md)
- [系统契约](docs/design/system-contracts.md)
- [模块设计](docs/design/module-design.md)
- [AI 上下文](docs/ai/context.md)
- [文档站点](https://immane.github.io/crud-skeleton)

---

## 许可证

Apache-2.0。见 [LICENSE](LICENSE)。
