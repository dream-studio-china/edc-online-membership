# CRUD Platform

CRUD Platform は [crud-skeleton](https://github.com/immane/crud-skeleton) から発展した本番向け Symfony マイクロサービスプラットフォームで、モジュラーモノリスアーキテクチャから進化し、DDD、サービス分離、イベント駆動通信を備えています。

> English: [README.md](README.md) · 简体中文: [README.zh-cn.md](README.zh-cn.md) · 繁體中文: [README.zh-hant.md](README.zh-hant.md)

---

## プロジェクトの目標

目標は、**単一リポジトリ内で独立してデプロイできる複数の Symfony アプリケーション**です。
各サービスは Kernel、設定、データベースとマイグレーション、キュー、worker、定期ジョブ、
Docker イメージ、テスト、CI を所有します。

現在は完成済みのマイクロサービスではなく、**モジュラーモノリス**です。Kernel、Composer
プロジェクト、コンテナ、データベース、マイグレーション履歴、Messenger キュー、worker、
scheduler、Docker イメージを共有しています。`Trade → Store → Inventory` の
Outbox/Inbox フローが最初の抽出境界です。

目標ディレクトリ、境界ルール、抽出条件は
[Microservice Transition Contract](docs/design/microservice-transition.md) を参照してください。

---

## アーキテクチャ

### サービストポロジー

```
                    ┌──────────────────────────────────────────────┐
                    │           API ゲートウェイ / エッジ          │
                    └──────┬──────┬──────┬───────────┬────────┬────┘
                           │      │      │           │        │ 
    ┌──────────┐  ┌────────┴─┐ ┌──┴───┐ ┌┴──────┐ ┌──┴───┐ ┌──┴────┐
    │ 認証     │  │ 取引     │ │店舗  │ │コンテンツ │ │財布  │ │決済   │
    │ Identity │  │ Commerce │ │Store │ │Content  │ │Wallet│ │Payment│
    │  :8085   │  │  :8087   │ │:8081 │ │:8086    │ │:8084 │ │:8083  │
    └────┬─────┘  └────┬─────┘ └──┬───┘ └──┬──────┘ └──┬───┘ └───┬───┘
         │             │          │        │           │         │
    ┌────┴────┐   ┌────┴──────┐ ┌─┴───┐ ┌──┴───┐ ┌─────┴───┐ ┌───┴───┐
    │   DB    │   │    DB     │ │ DB  │ │  DB  │ │   DB    │ │  DB   │
    │identity │   │  trade    │ │store│ │common│ │ wallet  │ │payment│
    └─────────┘   └───────────┘ └─────┘ └──────┘ └─────────┘ └───────┘

    ┌──────────┐
    │ 在庫     │  + ルートモノリス (app :8080) — 移行ホスト
    │Inventory │  + Worker + Scheduler（共有）
    │  :8082   │  + Redis + Mailpit
    └────┬─────┘
    ┌────┴────┐
    │   DB    │
    │inventory│
    └─────────┘
```

### イベント駆動統合（Outbox / Inbox）

```
  Trade                    Store                   Inventory
  ┌──────────┐ outbox      ┌──────────┐ outbox     ┌──────────┐
  │ order    │──order──→  │ store    │──reserve──→│ material │
  │ created  │ created.v1 │ order    │ request.v1 │ reserve  │
  │          │←─accept─── │ accepted │←─confirm───│ confirm  │
  │          │ accepted   │          │ confirmed  │          │
  └──────────┘            └──────────┘            └──────────┘
       │                        │                       │
       └── store.directory.upserted.v1 ──→ local projection
```

### レイヤーアーキテクチャ（サービス毎）

```
  HTTP コントローラ     ←  Request/Response に触れる唯一の層
        │
  サービス層            ←  すべてのビジネスロジック、トランザクション、検証
        │
  リポジトリ層          ←  データアクセス（Doctrine）
        │
  エンティティ / ドメイン ←  永続化と集約不変条件
        │
  インフラストラクチャ  ←  ORM、キャッシュ、シリアライザ（フレームワーク提供）
```

### リポジトリレイアウト

```
├── apps/                         # 独立デプロイ可能なサービス
│   ├── identity/                 # App\Identity — 認証、JWT、OTP、WeChat ログイン
│   │   ├── src/Main/             #   アカウント、プロファイル、リフレッシュトークン
│   │   └── src/Wechat/           #   WeChat ミニプログラム / OAuth アダプタ
│   ├── common/                   # App\Common — CMS、メディア、カテゴリ、タグ
│   │   ├── src/Main/             #   コンテンツエンティティと CRUD
│   │   └── src/Storage/          #   プラグ可能ファイルアップロード（Local、Qiniu）
│   ├── trade/                    # App\Trade — 注文、商品、価格計算
│   │   ├── src/Trade/            #   注文ワークフロー、Outbox、メッセージハンドラ
│   │   └── src/Promotion/        #   DSL 駆動プロモーションエンジン、7 戦略
│   ├── store/                    # App\Store — マルチ店舗運営
│   ├── inventory/                # App\Inventory — 在庫予約、レシピ
│   ├── payment/                  # App\Payment — 請求書、ゲートウェイ、調整
│   ├── wallet/                   # App\Wallet — 台帳、送金、控除
├── packages/
│   ├── platform-kernel/          # App\Core フレームワーク（RestController、DQL、ユーティリティ）
│   ├── integration-contracts/    # バージョン付き中立イベントキャリア
│   └── legacy-messenger-compat/  # 過去の Messenger ラッパー FQCN
├── src/                          # ルートモノリス（移行ホストのみ）
│   ├── Bridge/                   #   構成アダプタ（ルート → サービスポート）
│   └── Kernel.php                #   ルート Kernel
├── config/                       # ルートサービス配線、ルーティング、Doctrine マッピング
├── docs/                         # 設計契約、AI コンテキスト
└── scripts/                      # スモークテスト、カバレッジツール、取引デモ
```

### 抽出状況

| 対象コンテキスト | 移行先 | 状況 |
|---|---|---|
| Platform Kernel | `packages/platform-kernel` | 共有フレームワークライブラリ |
| Commerce | `apps/trade`（Trade + Promotion） | 抽出済み。Payment 直接依存は残存 |
| Store Operations | `apps/store` | 抽出済み |
| Inventory | `apps/inventory` | 抽出済み。本番利用は制限付き |
| Payments | `apps/payment`（ゲートウェイ、調整） | 抽出済み。ゲートウェイは Payment が所有 |
| Wallet/Ledger | `apps/wallet` | 抽出済み。`ownerUuid` のみ |
| Identity & Access | `apps/identity`（Main + WeChat ログイン） | 抽出済み |
| Content/Media | `apps/common`（CMS + Storage） | 抽出済み |

---

## 現在の機能

- **フレームワーク**: Symfony 8.1、PHP 8.4+、Doctrine ORM 3.6、MySQL 8、SQLite テスト。
- **ID とアクセス**: RS256 JWT、Refresh Token ローテーション、OTP/SMS（Alibaba Cloud）、
  パスワードログイン、WeChat ミニプログラム / 公式アカウント OAuth ログイン。
- **取引**: 商品カタログ、注文ステートマシン（draft→completed→refunded）、
  店舗認識価格パイプライン（基本→数量→小計→プロモーション）、
  マルチ店舗受諾/拒否ワークフロー。
- **プロモーションエンジン**: カスタム DSL 字句/構文解析器/評価器、7 戦略タイプ
  （割引、ギフト、段階、満額割引、送料無料、会員、N 個目割引）。
- **店舗運営**: マルチ店舗ディレクトリ、店舗スコープ注文、メンバーシップ、
  スタッフ注文管理。
- **在庫**: 材料マスタ、仕様レシピ、アトミック在庫予約、店舗別 `allowNegativeStock`
  ポリシー（デフォルト無効、本番未対応）。
- **決済**: 請求書ライフサイクル（pending→paid→refunded）、マルチゲートウェイ
  レジストリ（mock、ウォレット、WeChat Pay V3）、支払前調整パイプライン。
- **ウォレット**: 複式簿記台帳、送金、支払控除、残高監査、楽観ロック、冪等入金。
- **CMS とメディア**: カテゴリ、タグ、コンテンツ、コメント、ページ、設定、
  プラグ可能メディアストレージ（Local、Qiniu）。
- **統合**: バージョン付き Trade/Store/Inventory/Payment イベント、Outbox/Inbox 冪等性、
  correlation/causation トレース伝播、10 の中立キャリア。
- **i18n**: Symfony Translation — 英語、簡体字中国語、繁体字中国語、日本語（言語毎 ~280 キー）。
- **API ドキュメント**: NelmioApiDoc + Swagger UI `/api/doc`、自動タグ、44+ 名前付きスキーマ。

---

## クイックスタート

Docker が推奨されるローカル実行方法です。リポジトリのルートで実行してください。
ホスト側に必要なのは Docker だけです。

```bash
docker compose up -d --build

# ルートモノリス
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 抽出済みアプリ
for svc in store-app inventory-app payment-app wallet-app identity-app common-app trade-app; do
  docker compose exec $svc php bin/console doctrine:migrations:migrate --no-interaction
done

# 管理者作成
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 確認
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

| サービス | ポート | データベース | 状況 |
|---|---|---|---|
| ルートモノリス (app) | `8080` | `database` | 移行ホスト |
| 店舗 | `8081` | `store-database` | 抽出済み |
| 在庫 | `8082` | `inventory-database` | 抽出済み、制限付き |
| 決済 | `8083` | `payment-database` | 抽出済み |
| ウォレット | `8084` | `wallet-database` | 抽出済み |
| 認証 | `8085` | `identity-database` | 抽出済み |
| コンテンツ | `8086` | `common-database` | 抽出済み |
| 取引 | `8087` | `trade-database` | 抽出済み |

- OpenAPI UI: `http://localhost:8080/api/doc`
- Mailpit（メールテスト）: `http://localhost:8025`
- Worker/Scheduler ログ: `docker compose logs -f worker scheduler`

ネイティブ PHP の実行とトラブルシューティングは [QUICKSTART.md](QUICKSTART.md) を参照してください。

---

## 開発

```bash
# テストスイート
./vendor/bin/phpunit                           # ルート統合テスト + 残存ユニットテスト
composer coverage                              # 全 8 スイート + 集約ゲート（>= 90%）

# 静的解析
composer phpstan                                # PHPStan Level 8
composer deptrac                                # アーキテクチャ境界チェック
composer rector:types:check                     # Rector 型ルール dry-run

# ドキュメント
mkdocs build --strict
```

**テスト構造**: 7 つの独立アプリテストスイート（common、identity、inventory、payment、
store、trade、wallet）がルート統合テスト（963 テスト）と共に独立実行されます。
集約行カバレッジは **91.36%**（1,785 テスト、6,098 アサーション）で、
phpcov マージゲートにより CI で強制されます。

ローカルコマンドには PHP 8.4+ が必要です。

---

## 統合契約

10 のバージョン付き中立キャリアがサービスを接続します：

| 型 | キャリア | 方向 |
|---|---|---|
| イベント | `trade.order.created.v1` | Trade → Store |
| イベント | `trade.order.cancelled.v1` | Trade → Store |
| イベント | `store.order.accepted.v1` | Store → Trade |
| イベント | `store.order.rejected.v1` | Store → Trade |
| イベント | `store.directory.upserted.v1` | Store → Trade（投影） |
| コマンド | `inventory.reservation.requested.v1` | Store → Inventory |
| コマンド | `inventory.reservation.release.requested.v1` | Store → Inventory |
| イベント | `inventory.reservation.confirmed.v1` | Inventory → Store |
| イベント | `inventory.reservation.rejected.v1` | Inventory → Store |
| イベント | `inventory.reservation.released.v1` | Inventory → Store |
| イベント | `payment.invoice.{paid,failed,cancelled,refunded}.v1` | Payment → Trade（進行中） |

各エンベロープは `eventId`、`type`、`version`、`aggregateType`、`aggregateId`、
`occurredAt`、`correlationId`、`causationId`、`payload` を含みます。
パブリッシャーは同一トランザクションで Outbox にアトミック書き込みし、
コンシューマーは `eventId` による Inbox 冪等性を使用します。

---

## 主要パターン

| パターン | 場所 | 説明 |
|---|---|---|
| **Outbox/Inbox** | Trade、Store、Inventory、Payment | 永続イベント配送、冪等性保証 |
| **相関トレース** | 全 Outbox | サービス間 `correlationId`/`causationId` 伝播 |
| **UUID 識別子** | Trade、Wallet | 外部参照用 `UUID::v4()` |
| **金額はセント単位** | Wallet、Trade、Payment | `bigint` セント、API 境界で ×/÷100 |
| **ステートマシン** | Trade | Symfony Workflow による注文ライフサイクル |
| **価格パイプライン** | Trade | 優先度付き `PriceCalculatorInterface` |
| **ゲートウェイレジストリ** | Payment | プラグ可能決済ゲートウェイ用 `#[AutowireIterator]` |
| **調整パイプライン** | Payment | ゲートウェイ実行前の支払前控除フック |
| **楽観ロック** | Wallet | Wallet エンティティの `#[ORM\Version]` |
| **スナップショット** | Trade | `OrderItem` が `specSnapshot`/`productSnapshot` を保持 |
| **ソフトデリート** | Trade | Product、Specification の `isDeleted` |
| **commonFilter** | コントローラ | ユーザースコープまたは管理者スコープの QueryBuilder 注入 |
| **プロモーション DSL** | Promotion | カスタム字句/構文解析器/評価器 |
| **Dry-run バックフィル** | Trade、Store、Inventory | 境界付き再開可能な相関バックフィルコマンド |
| **トークンローテーション** | Identity | HMAC-SHA256 Refresh Token + 再利用検出 |

---

## コンソールコマンド

| コマンド | サービス | 目的 |
|---|---|---|
| `app:identity:user:create` | Identity | ロール付きユーザー作成 |
| `app:trade:outbox:publish` | Trade | 未公開統合イベントの配送 |
| `app:store:outbox:publish` | Store | 受諾/拒否イベントの配送 |
| `app:inventory:outbox:publish` | Inventory | 予約結果の配送 |
| `app:payment:outbox:publish` | Payment | 請求書ライフサイクルイベントの配送 |
| `app:trade:outbox:backfill-correlation` | Trade | 相関バックフィル（dry-run / --apply） |
| `app:store:outbox:backfill-correlation` | Store | 相関バックフィル（dry-run / --apply） |
| `app:inventory:outbox:backfill-correlation` | Inventory | 相関バックフィル（dry-run / --apply） |
| `app:payment:outbox:backfill-correlation` | Payment | 相関バックフィル（dry-run / --apply） |
| `app:store:outbox:backfill-directory` | Store | 店舗ディレクトリイベントのバックフィル |
| `app:inventory:reservations:release-expired` | Inventory | 期限切れ予約の解放 |
| `app:storage:qiniu:settings:init` | Common | Qiniu 設定の初期化 |

---

## Docker Compose トポロジー

`compose.yaml` の **22 サービス**：

| グループ | サービス |
|---|---|
| ルート | `app`（FrankenPHP）、`worker`（Messenger 非同期）、`scheduler`（outbox 配送） |
| アプリ | `store-app`、`inventory-app`、`payment-app`、`wallet-app`、`identity-app`、`common-app`、`trade-app` |
| データベース | `database`（ルート）、`store-database`、`inventory-database`、`payment-database`、`wallet-database`、`identity-database`、`common-database`、`trade-database` |
| インフラ | `redis`（OTP/キャッシュ）、`mailer`（Mailpit） |

Worker は共有 `async` トランスポートを消費します。Scheduler は Trade、Store、
Inventory、Payment の Outbox 公開と在庫期限切れ予約解放をポーリングします。

---

## 開発者オンボーディング

プロジェクトに初めて参加する方は、[開発マニュアル](docs/manual/index.md)を以下の順序でお読みください：

1. [アーキテクチャ](docs/manual/architecture.md) — サービストポロジー、レイヤー、パターンを理解
2. [クイックスタート](docs/manual/getting-started.md) — Docker 環境を構築して動作確認
3. [プロジェクト構造](docs/manual/project-structure.md) — コードの配置場所を把握
4. [コアフレームワーク](docs/manual/core-framework.md) — `RestController`、`BaseService`、View Mixin、EventListener、Utils
5. [コア使用法](docs/manual/core-usage.md) — 実践: Controller、Service、ファイルアップロード、カスタムアクションの構築
6. [クエリシステム](docs/manual/query-system.md) — `@filter`、`@sort`、`@order`、`@dql`、`@select`、`@groupBy`、`@expands`、`@display`、`@transform` をマスター
7. [API 契約](docs/manual/api-contracts.md) — リクエスト/レスポンスエンベロープ、認証、ページネーション、エラー処理、API ドキュメント
8. [開発契約](docs/manual/development-contracts.md) — コーディングルール、レイヤー境界、命名規則、セキュリティ

その後、必要に応じて参照: [テスト](docs/manual/testing.md)、[統合イベント](docs/manual/integration-events.md)、
[データベースとマイグレーション](docs/manual/database-and-migrations.md)、[デプロイメント](docs/manual/deployment.md)、
[サービス抽出](docs/manual/extracting-a-service.md)、[国際化](docs/manual/i18n.md)。

## ドキュメント

- [開発マニュアル](docs/manual/index.md) — 包括的開発者ガイド（16 ドキュメント）
- [Microservice Transition Contract](docs/design/microservice-transition.md)
- [System Architecture](docs/design/system-architecture.md)
- [System Contracts](docs/design/system-contracts.md)
- [Module Design](docs/design/module-design.md)
- [AI Context](docs/ai/context.md)
- [ドキュメントサイト](https://immane.github.io/crud-skeleton)

---

## ライセンス

Apache-2.0。詳細は [LICENSE](LICENSE) を参照してください。
