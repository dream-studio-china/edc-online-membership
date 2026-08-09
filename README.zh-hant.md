# CRUD Skeleton

一個面向生產實踐的 Symfony 8.1 API 骨架，內建可復用的服務層抽象、模組化架構、JWT 鑑權、動態查詢引擎以及可插拔的業務模組。

> English: [README.md](README.md) · Chinese (Simplified): [README.zh-cn.md](README.zh-cn.md) · Japanese: [README.ja.md](README.ja.md)

> 文件站點: [GitHub Pages](https://immane.github.io/crud-skeleton) | 設計契約: [docs/design/](docs/design/)

## 目錄

- [功能特性](#功能特性)
- [技術棧](#技術棧)
- [項目結構](#項目結構)
- [模組概覽](#模組概覽)
- [測試](#測試)
- [Docker 部署](#docker-部署)
- [國際化（i18n）](#國際化i18n)
- [許可證](#許可證)

## 功能特性

- **CRUD 服務抽象**：`new()`、`get()`、`list()`、`update()`、`remove()`
- **動態查詢系統**：透過請求參數控制篩選/排序/分組/欄位選擇，表達式編譯為 DQL
- **Trait 組合式控制器**：9 個 mixin trait（List、Detail、Create、Update、Delete、Workflow、Singleton、Transform）可按需組合
- **模組化架構**：Core 框架 + Common（CMS）+ Promotion（DSL 驅動促銷引擎）+ Trade（電商）+ Store（門店交易發件箱）+ Inventory（物料、庫存、配方、預留）+ Payment（支付）+ Wallet（錢包）+ Wechat（微信登入+支付）+ Storage（檔案儲存驅動）+ Identity（鑑權）
- **JWT 鑑權**：RS256 存取令牌，HMAC-SHA256 Refresh Token 輪換，含重用檢測
- **OTP 登入**：基於手機驗證碼的簡訊登入，含頻率限制（阿里雲）
- **訂單狀態機**：Symfony Workflow（草稿 → 完成），含完整工作流 API
- **價格計算管道**：可插拔的價格計算器，按優先級排序執行
- **支付抵扣提供方**：支付前鉤子（如錢包抵扣）在網關處理前減少發票金額 — 網關僅接收顯式金額
- **原子錢包轉帳 + 系統注資**：死鎖預防（統一鎖定順序）、樂觀鎖、引用 ID 冪等
- **錢包餘額抵扣**：錢包擁有的抵扣生命週期，透過 Payment 抵扣提供方模式接入 — Payment 編排，Wallet 實現
- **可插拔檔案儲存**：`MediaStorageInterface`，本地與七牛 Kodo 驅動 — tagged iterator 自動發現
- **OpenAPI 文件**：NelmioApiDocBundle + `#[OA\*]` 屬性，`/api/doc` 提供 Swagger UI
- **系統自省**：實體元資料和路由匯出介面（`/system/*`）
- **促銷 DSL 引擎**：自訂詞法/語法/求值器，支援 7 種促銷類型（滿減、折扣、贈品、第 N 件折扣、階梯、免運費、會員折扣）。作為標籤定價計算器（優先級 60）運行在 Trade 價格管道彙總小計之後。支援會員定向 SKU 折扣、多門店路由、全平台活動，以及 `best_price` 衝突模式（模擬候選活動並選擇最低總價）。
- **Profile 實體**：用戶註冊時透過 Doctrine 監聽器自動建立。包含等級（青銅→鑽石）、暱稱、頭像、元資料。積分委託給 Wallet（currency=POINTS）。
- **健康檢查**：`/health/live`（存活）與 `/health/ready`（DB + 可選 Redis 就緒）公開探針，供 Docker healthcheck 使用
- **速率限制**：登入/註冊/OTP/微信登入/支付端點按用戶端 IP 滑動窗口限流（429 + `Retry-After`）
- **Prometheus 指標**：`/metrics` 文本格式——每 worker HTTP 計數器/耗時直方圖 + 即時 DB 指標（outbox 積壓、失敗訊息佇列）
- **Docker Compose**：MySQL 8 + Mailpit 開發環境

## 技術棧

| 組件 | 技術 |
|------|------|
| 語言 | PHP `>= 8.4` |
| 框架 | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| 資料庫 | MySQL 8（Docker/生產）/ SQLite（測試） |
| 鑑權 | JWT (RS256) + OTP (簡訊) |
| API 文件 | NelmioApiDocBundle (OpenAPI 3) |
| 測試 | PHPUnit `^12.5`（支援 paratest 並行） |
| 前端 | [crud-admin](https://github.com/immane/crud-admin) — 配置驅動的管理後台 |
| 文件 | MkDocs Material (GitHub Pages) |

## 項目結構

```text
.
├── src/
│   ├── Core/                     # 框架核心
│   ├── Common/                   # CMS 模組（7 實體）
│   ├── Trade/                    # 電商模組
│   ├── Wallet/                   # 錢包模組
│   ├── Payment/                  # 支付模組
│   ├── Wechat/                   # 微信模組
│   ├── Storage/                  # 儲存模組
│   ├── Promotion/                # 促銷模組（DSL 引擎）
│   ├── Store/                     # 門店模組
│   ├── Inventory/                 # 庫存模組（物料、庫存、配方、預留）
│   └── Identity/                 # 鑑權模組
├── config/                       # Symfony 配置
├── migrations/                   # Doctrine 遷移（20 個版本）
├── tests/                        # 預設套件 2222 tests，按層組織：
│   ├── UnitTest/                 #   純單元測試（無 kernel/DB）
│   ├── Integration/              #   kernel + DB + HTTP 測試及共享 helper
│   └── LowValue/                 #   棄用/低價值測試，預設運行排除
├── translations/                 # 多語言翻譯檔案
└── compose.yaml                  # Docker Compose
```

## 模組概覽

| 模組 | 命名空間 | 用途 | 核心特性 |
|------|---------|------|---------|
| **Core** | `App\Core` | 框架基礎 | RestController、BaseService、View mixin、表達式解析器 |
| **Common** | `App\Common` | CMS | 分類（樹）、標籤、內容、評論、頁面、媒體、設定 |
| **Trade** | `App\Trade` | 電商 | 產品 + 規格、訂單（狀態機）、價格計算管道 |
| **Inventory** | `App\Inventory` | 庫存管理 | 門店物料庫存 + 規格配方 + 預留（原子庫存鎖）+ 庫存台帳審計 + 負庫存策略 |
| **Wallet** | `App\Wallet` | 錢包與抵扣 | 餘額（分）、原子轉帳、系統注資、冪等、對帳 |
| **Payment** | `App\Payment` | 支付編排 | 發票（分+工作流）、網關抽象、支付抵扣提供方契約 |
| **Wechat** | `App\Wechat` | 微信整合 | 小程式/公眾號登入、微信支付 V3 |
| **Storage** | `App\Storage` | 檔案儲存驅動 | LocalStorage、QiniuStorage |
| **Promotion** | `App\Promotion` | DSL 驅動促銷 | 自訂 DSL 詞法/語法/求值器、7 種策略類型、作為 `trade.price_calculator`（優先級 60）、會員定向 SKU 折扣、多門店路由、`best_price` 衝突模式 |
| **Identity** | `App\Identity` | 鑑權 | JWT (RS256)、OTP (簡訊)、Refresh Token 輪換、Profile 實體（自動建立、等級、積分委託給 Wallet） |

## 測試

**預設套件 2222 tests · 7942 assertions**（另有 477 低價值測試預設排除）。

串行執行所有測試：

```bash
./vendor/bin/phpunit
```

並行執行（約 2-3 倍加速）：

```bash
PARATEST=1 ./vendor/bin/paratest --processes 8 --runner WrapperRunner
```

顯式執行被排除的低價值測試：

```bash
./vendor/bin/phpunit --group low-value
```

含覆蓋率報告（CI 門檻 90%）：

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

生成 HTML 覆蓋率報告：
```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html var/coverage
```

### 靜態分析

專案需要 PHP 8.4 或更新版本。執行與 CI 相同的靜態檢查：

```bash
composer phpstan
composer rector:types:check
```

PHPStan 以 Level 8 檢查設定的 `src/` 範圍。CI 的 Rector 僅檢查 Doctrine Collection/Repository PHPDoc 型別規則；`composer rector` 是範圍較廣的選用重構命令，套用前應先審查變更。

### 測試分組

| 分組 | 數量 | 覆蓋範圍 |
|------|------|----------|
| Common | 69+ | CMS 實體、媒體上傳/刪除、批次更新 |
| Trade | 171+ | 訂單、定價管道、工作流 |
| Wallet | 105+ | 轉帳、錢包服務、支付網關、餘額審計 |
| Payment | 60+ | 網關、註冊表、調整、發票、多網關整合 |
| Identity | 116+ | 認證、OTP、令牌、UserService、Profile 實體/控制器 |
| Promotion | 197+ | 實體、DSL 詞法/語法/求值器、策略、引擎、計算器、控制器 |
| Wechat | 59+ | 認證、服務、支付網關、控制器、儲存庫 |
| Core | 70+ | BaseService、RestController、表達式解析器、序列化器、系統控制器 |
| Integration | 20+ | 跨模組整合測試 |

## Docker 部署

### 開發環境

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

Compose 也會自動啟動 Messenger `worker` 與 Trade/Store Outbox `scheduler`。可使用 `docker compose logs -f worker scheduler` 檢視非同步處理日誌。

### 生產環境

```bash
cp .env.prod.example .env.prod.local
# 編輯 .env.prod.local 填入 APP_SECRET、REFRESH_TOKEN_SECRET、MYSQL_PASSWORD 等
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
```

## 國際化（i18n）

項目透過 Symfony Translation 元件支援國際化。翻譯檔案儲存在 `translations/` 目錄。

### 支援的語言

| 語言代碼 | 檔案 | 語言 |
|----------|------|------|
| `en` | `translations/messages.en.yaml` | 英語（預設） |
| `zh` | `translations/messages.zh.yaml` | 簡體中文 |
| `zh_Hant` | `translations/messages.zh_Hant.yaml` | 繁體中文 |
| `ja` | `translations/messages.ja.yaml` | 日語 |

### 工作原理

1. **異常訊息** — API 路由上未捕獲的異常會經過 `ExceptionInterceptor`，呼叫 `$this->translator->trans($exception->getMessage())`，異常訊息原文作為翻譯鍵。
2. **控制器錯誤響應** — `RestController::warning()`、`AuthController::error()`、`OtpController::error()`、`LoginController::error()` 均經由翻譯器處理。
3. **JWT 認證失敗** — `JwtAuthenticator::onAuthenticationFailure()` 在回傳 JSON 響應前翻譯錯誤訊息。
4. **實體欄位名稱** — `/system/entities/{entityName}` 端點會翻譯欄位名稱（如 `createdAt` → `Created at` → `建立時間`）。

### 語言偵測

`LocaleListener`（`src/Core/EventListener/LocaleListener.php`）自動偵測使用者語言：

1. **查詢參數** — `?_locale=zh_Hant` 優先級最高
2. **Accept-Language 請求頭** — 讀取瀏覽器 `Accept-Language` 頭並對應到支援的語言：
   - `zh-CN`、`zh-Hans` → `zh`（簡體）
   - `zh-TW`、`zh-HK`、`zh-Hant` → `zh_Hant`（繁體）
   - `ja-JP` → `ja`（日語）
3. **回退** — 不支援的語言自動回退到 `en`（設定的 `default_locale`）。

### 多語言文件

| 語言 | 檔案 |
|------|------|
| English | [README.md](README.md) |
| Chinese (Simplified) | [README.zh-cn.md](README.zh-cn.md) |
| Japanese | [README.ja.md](README.ja.md) |

## 許可證

Apache-2.0。詳見 [LICENSE](LICENSE)。
