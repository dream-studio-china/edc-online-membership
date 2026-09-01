# CRUD Platform

CRUD Platform 是從 [crud-skeleton](https://github.com/immane/crud-skeleton) 演進而來的面向生產環境的 Symfony 微服務平台，從模組化單體架構演進而來，具備 DDD、服務隔離和事件驅動通訊能力。

> English: [README.md](README.md) · 简体中文: [README.zh-cn.md](README.zh-cn.md) · 日本語: [README.ja.md](README.ja.md)

---

## 專案目標

目標是建立一個**單一儲存庫、多個可獨立部署 Symfony 應用程式**的平台。每個服務將獨立
擁有 Kernel、設定、資料庫與遷移、訊息佇列、worker、排程工作、Docker 映像、測試與 CI。

目前儲存庫仍是**模組化單體**，不是已完成的微服務系統：它共用一個 Kernel、Composer
專案、容器、資料庫、遷移鏈、Messenger 佇列、worker、scheduler 與 Docker 映像。
`Trade → Store → Inventory` 的 Outbox/Inbox 流程是第一個提取邊界。

目標目錄、邊界規則與提取準入條件請見
[Microservice Transition Contract](docs/design/microservice-transition.md)。

---

## 架構

### 服務拓撲

```
                    ┌──────────────────────────────────────────────┐
                    │              API 閘道 / 邊緣路由              │
                    └──────┬──────┬──────┬───────────┬────────┬────┘
                           │      │      │           │        │ 
    ┌──────────┐  ┌────────┴─┐ ┌──┴───┐ ┌┴──────┐ ┌──┴───┐ ┌──┴────┐
    │ 身分認證 │  │  交易     │ │門市  │ │內容   │ │錢包  │ │支付   │
    │  :8085   │  │  :8087   │ │:8081 │ │:8086  │ │:8084 │ │:8083  │
    └────┬─────┘  └────┬─────┘ └──┬───┘ └──┬────┘ └──┬───┘ └───┬───┘
         │             │          │        │         │         │
    ┌────┴────┐   ┌────┴──────┐ ┌─┴───┐ ┌──┴───┐ ┌───┴───┐ ┌───┴───┐
    │   DB    │   │    DB     │ │ DB  │ │  DB  │ │  DB   │ │  DB   │
    │identity │   │  trade    │ │store│ │common│ │wallet │ │payment│
    └─────────┘   └───────────┘ └─────┘ └──────┘ └───────┘ └───────┘

    ┌──────────┐
    │  庫存    │  + 根單體 (app :8080) — 過渡宿主
    │  :8082   │  + Worker + Scheduler（共享）
    └────┬─────┘  + Redis + Mailpit
    ┌────┴────┐
    │   DB    │
    │inventory│
    └─────────┘
```

### 事件驅動整合（Outbox / Inbox）

```
  Trade                    Store                   Inventory
  ┌──────────┐ outbox      ┌──────────┐ outbox     ┌──────────┐
  │ 訂單     │──訂單──→   │ 門市     │──保留──→   │ 物料     │
  │ 已建立   │ created.v1 │ 訂單     │ request.v1 │ 保留     │
  │          │←─接受────  │ 已接受   │←─確認────  │ 確認     │
  │          │ accepted   │          │ confirmed  │          │
  └──────────┘            └──────────┘            └──────────┘
       │                        │                       │
       └── store.directory.upserted.v1 ──→ 本地投影
```

### 分層架構（每服務）

```
  HTTP 控制器    ←  唯一接觸 Request/Response 的層
        │
  服務層         ←  所有業務邏輯、交易、驗證
        │
  倉儲層         ←  資料存取（Doctrine）
        │
  實體 / 領域    ←  持久化與聚合不變性
        │
  基礎設施       ←  ORM、快取、序列化器（框架提供）
```

### 儲存庫佈局

```
├── apps/                         # 可獨立部署的服務
│   ├── identity/                 # App\Identity — 認證、JWT、OTP、微信登入
│   │   ├── src/Main/             #   帳戶、Profile、Refresh Token
│   │   └── src/Wechat/           #   微信小程式 / 公眾號 OAuth 介接器
│   ├── common/                   # App\Common — CMS、媒體、分類、標籤
│   │   ├── src/Main/             #   內容實體與 CRUD
│   │   └── src/Storage/          #   可插拔檔案上傳（本地、七牛）
│   ├── trade/                    # App\Trade — 訂單、商品、定價
│   │   ├── src/Trade/            #   訂單工作流、Outbox、訊息處理器
│   │   └── src/Promotion/        #   DSL 驅動促銷引擎，7 種策略
│   ├── store/                    # App\Store — 多門市營運
│   ├── inventory/                # App\Inventory — 庫存保留、配方
│   ├── payment/                  # App\Payment — 發票、閘道、調整
│   ├── wallet/                   # App\Wallet — 帳本、轉帳、扣款
├── packages/
│   ├── platform-kernel/          # App\Core 框架（RestController、DQL、工具集）
│   ├── integration-contracts/    # 版本化中性事件載體
│   └── legacy-messenger-compat/  # 歷史 Messenger 包裝 FQCN
├── src/                          # 根單體（僅過渡宿主）
│   ├── Bridge/                   #   組合介接器（根 → 服務埠）
│   └── Kernel.php                #   根 Kernel
├── config/                       # 根服務裝配、路由、Doctrine 映射
├── docs/                         # 設計契約、AI 上下文
└── scripts/                      # 冒煙測試、覆蓋率工具、交易演示
```

### 提取狀態

| 目標上下文 | 已遷移至 | 狀態 |
|---|---|---|
| Platform Kernel | `packages/platform-kernel` | 共用框架函式庫 |
| Commerce | `apps/trade`（Trade + Promotion） | 已提取；仍保留 Payment 直接依賴 |
| Store Operations | `apps/store` | 已提取 |
| Inventory | `apps/inventory` | 已提取；正式環境受限 |
| Payments | `apps/payment`（閘道、調整） | 已提取；閘道由 Payment 應用持有 |
| Wallet/Ledger | `apps/wallet` | 已提取；僅用 `ownerUuid` |
| Identity & Access | `apps/identity`（Main + 微信登入） | 已提取 |
| Content/Media | `apps/common`（CMS + Storage） | 已提取 |

---

## 目前能力

- **框架**：Symfony 8.1、PHP 8.4+、Doctrine ORM 3.6、MySQL 8、SQLite 測試環境。
- **身分與存取**：RS256 JWT、Refresh Token 輪換、OTP/SMS（阿里雲）、密碼登入、
  微信小程式 / 公眾號 OAuth 登入。
- **交易**：商品目錄、訂單狀態機（draft→completed→refunded）、門市感知定價管線
  （基礎→數量→小計→促銷）、多門市接單/拒絕工作流。
- **促銷引擎**：自訂 DSL 詞法/語法分析器/解釋器，7 種策略類型（折扣、贈品、
  階梯、滿減、免運、會員、第 N 件折扣）。
- **門市營運**：多門市目錄、門市範圍訂單、會員、員工訂單管理。
- **庫存**：物料主資料、規格配方、原子庫存保留、每店 `allowNegativeStock` 策略
  （預設關閉，未達正式標準）。
- **支付**：發票生命週期（pending→paid→refunded）、多閘道註冊中心（mock、錢包、
  微信支付 V3）、支付前調整管線。
- **錢包**：複式帳本、轉帳、支付扣款、餘額審計、樂觀鎖、冪等充值。
- **CMS 與媒體**：分類、標籤、內容、評論、頁面、設定，可插拔媒體儲存（本地、七牛）。
- **整合**：版本化 Trade/Store/Inventory/Payment 事件，Outbox/Inbox 冪等，
  correlation/causation 鏈路傳播，10 個中性事件載體。
- **國際化**：Symfony Translation — 英文、簡體中文、繁體中文、日文（每語言 ~280 條）。
- **API 文件**：NelmioApiDoc + Swagger UI `/api/doc`，自動標籤，44+ 命名 Schema。

---

## 快速開始

Docker 是建議的本機執行方式。在儲存庫根目錄執行，主機只需要 Docker：

```bash
docker compose up -d --build

# 根單體
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 已提取應用
for svc in store-app inventory-app payment-app wallet-app identity-app common-app trade-app; do
  docker compose exec $svc php bin/console doctrine:migrations:migrate --no-interaction
done

# 建立管理員
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 驗證
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

| 服務 | 埠 | 資料庫 | 狀態 |
|---|---|---|---|
| 根單體 (app) | `8080` | `database` | 過渡宿主 |
| 門市 | `8081` | `store-database` | 已提取 |
| 庫存 | `8082` | `inventory-database` | 已提取，受限 |
| 支付 | `8083` | `payment-database` | 已提取 |
| 錢包 | `8084` | `wallet-database` | 已提取 |
| 身分 | `8085` | `identity-database` | 已提取 |
| 內容 | `8086` | `common-database` | 已提取 |
| 交易 | `8087` | `trade-database` | 已提取 |

- OpenAPI UI：`http://localhost:8080/api/doc`
- Mailpit（郵件測試）：`http://localhost:8025`
- Worker/Scheduler 日誌：`docker compose logs -f worker scheduler`

本機 PHP 執行與排錯請參閱 [QUICKSTART.md](QUICKSTART.md)。

---

## 開發

```bash
# 測試套件
./vendor/bin/phpunit                           # 根整合測試 + 剩餘單元測試
composer coverage                              # 全部 8 套件 + 聚合門禁（>= 90%）

# 靜態分析
composer phpstan                                # PHPStan Level 8
composer deptrac                                # 架構邊界檢查
composer rector:types:check                     # Rector 型別規則 dry-run

# 文件
mkdocs build --strict
```

**測試結構**：七個獨立應用測試套件（common、identity、inventory、payment、store、
trade、wallet）與根整合測試（963 個）獨立執行。聚合行覆蓋率為 **91.36%**（1,785
個測試，6,098 個斷言），透過 phpcov 合併門禁在 CI 中強制執行。

本機命令需要 PHP 8.4+。

---

## 整合契約

十個版本化中性載體連接各服務：

| 型別 | 載體 | 方向 |
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
| 事件 | `payment.invoice.{paid,failed,cancelled,refunded}.v1` | Payment → Trade（進行中） |

每個信封包含 `eventId`、`type`、`version`、`aggregateType`、`aggregateId`、
`occurredAt`、`correlationId`、`causationId` 和 `payload`。發布者在同一交易中
原子寫入 Outbox；消費者透過 `eventId` 實現 Inbox 冪等。

---

## 關鍵模式

| 模式 | 位置 | 說明 |
|---|---|---|
| **Outbox/Inbox** | Trade、Store、Inventory、Payment | 持久事件遞送，冪等保證 |
| **鏈路追蹤** | 所有 Outbox | cross-service 傳播 `correlationId`/`causationId` |
| **UUID 識別** | Trade、Wallet | `UUID::v4()` 作為外部參考識別 |
| **貨幣以分為單位** | Wallet、Trade、Payment | `bigint` 分，API 邊界 ×/÷100 |
| **狀態機** | Trade | Symfony Workflow 驅動訂單生命週期 |
| **定價管線** | Trade | 帶優先級的 `PriceCalculatorInterface` |
| **閘道註冊中心** | Payment | `#[AutowireIterator]` 可插拔支付閘道 |
| **調整管線** | Payment | 閘道執行前的支付前扣減掛鉤 |
| **樂觀鎖** | Wallet | `#[ORM\Version]` 在 Wallet 實體 |
| **快照** | Trade | `OrderItem` 保存 `specSnapshot`/`productSnapshot` |
| **軟刪除** | Trade | Product、Specification 的 `isDeleted` 標誌 |
| **commonFilter** | 控制器 | 使用者範圍或管理員範圍的 QueryBuilder 注入 |
| **促銷 DSL** | Promotion | 自訂詞法/語法解析器/解釋器 |
| **Dry-run 回填** | Trade、Store、Inventory | 可控分批復原的關聯回填命令 |
| **Token 輪換** | Identity | HMAC-SHA256 Refresh Token + 重用檢測 |

---

## 主控台命令

| 命令 | 服務 | 用途 |
|---|---|---|
| `app:identity:user:create` | Identity | 建立帶角色的使用者 |
| `app:trade:outbox:publish` | Trade | 遞送未發布的整合事件 |
| `app:store:outbox:publish` | Store | 遞送接單/拒絕事件 |
| `app:inventory:outbox:publish` | Inventory | 遞送保留結果 |
| `app:payment:outbox:publish` | Payment | 遞送發票生命週期事件 |
| `app:trade:outbox:backfill-correlation` | Trade | 關聯回填（dry-run / --apply） |
| `app:store:outbox:backfill-correlation` | Store | 關聯回填（dry-run / --apply） |
| `app:inventory:outbox:backfill-correlation` | Inventory | 關聯回填（dry-run / --apply） |
| `app:payment:outbox:backfill-correlation` | Payment | 關聯回填（dry-run / --apply） |
| `app:store:outbox:backfill-directory` | Store | 回填門市目錄事件 |
| `app:inventory:reservations:release-expired` | Inventory | 釋放過期保留 |
| `app:storage:qiniu:settings:init` | Common | 初始化七牛設定 |

---

## Docker Compose 拓撲

`compose.yaml` 共 **22 個服務**：

| 分組 | 服務 |
|---|---|
| 根 | `app`（FrankenPHP）、`worker`（Messenger 非同步）、`scheduler`（outbox 遞送） |
| 應用 | `store-app`、`inventory-app`、`payment-app`、`wallet-app`、`identity-app`、`common-app`、`trade-app` |
| 資料庫 | `database`（根）、`store-database`、`inventory-database`、`payment-database`、`wallet-database`、`identity-database`、`common-database`、`trade-database` |
| 基礎設施 | `redis`（OTP/快取）、`mailer`（Mailpit） |

Worker 消費共享 `async` 傳輸。Scheduler 輪詢 Trade、Store、Inventory、Payment
的 Outbox 發布，以及庫存過期保留釋放。

---

## 開發者入門

新加入專案？按以下順序閱讀[開發手冊](docs/manual/index.md)：

1. [架構](docs/manual/architecture.md) — 理解服務拓撲、分層和模式
2. [快速上手](docs/manual/getting-started.md) — 搭建 Docker 環境並驗證
3. [專案結構](docs/manual/project-structure.md) — 了解程式碼組織方式
4. [核心框架](docs/manual/core-framework.md) — `RestController`、`BaseService`、View Mixin、EventListener、Utils
5. [核心用法](docs/manual/core-usage.md) — 實戰：構建 Controller、Service、檔案上傳、自訂 Action
6. [查詢系統](docs/manual/query-system.md) — 掌握 `@filter`、`@sort`、`@order`、`@dql`、`@select`、`@groupBy`、`@expands`、`@display`、`@transform`
7. [API 契約](docs/manual/api-contracts.md) — 請求/回應信封、認證、分頁、錯誤處理、API 文件
8. [開發契約](docs/manual/development-contracts.md) — 編碼規則、分層邊界、命名、安全

之後按需查閱：[測試](docs/manual/testing.md)、[整合事件](docs/manual/integration-events.md)、
[資料庫與遷移](docs/manual/database-and-migrations.md)、[部署](docs/manual/deployment.md)、
[提取服務](docs/manual/extracting-a-service.md)、[國際化](docs/manual/i18n.md)。

## 文件

- [開發手冊](docs/manual/index.md) — 完整開發者指南（16 份文件）
- [Microservice Transition Contract](docs/design/microservice-transition.md)
- [System Architecture](docs/design/system-architecture.md)
- [System Contracts](docs/design/system-contracts.md)
- [Module Design](docs/design/module-design.md)
- [AI Context](docs/ai/context.md)
- [文件站點](https://immane.github.io/crud-skeleton)

---

## 授權

Apache-2.0。請見 [LICENSE](LICENSE)。
