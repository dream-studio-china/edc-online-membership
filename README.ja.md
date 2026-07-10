# CRUD Skeleton

Symfony 8.1 ベースのプロダクション向け API スケルトン。再利用可能なサービス層の抽象化、モジュラーアーキテクチャ、JWT 認証、動的クエリエンジン、プラグイン可能なビジネスモジュールを提供します。

> English: [README.md](README.md) · Chinese (Simplified): [README.zh-cn.md](README.zh-cn.md) · Chinese (Traditional): [README.zh-hant.md](README.zh-hant.md)

> ドキュメントサイト: [GitHub Pages](https://immane.github.io/crud-skeleton) | 設計契約: [docs/design/](docs/design/)

## 目次

- [機能](#機能)
- [技術スタック](#技術スタック)
- [プロジェクト構成](#プロジェクト構成)
- [モジュール概要](#モジュール概要)
- [テスト](#テスト)
- [Docker デプロイ](#docker-デプロイ)
- [国際化（i18n）](#国際化i18n)
- [ライセンス](#ライセンス)

## 機能

- **CRUD サービス抽象化**: `new()`、`get()`、`list()`、`update()`、`remove()`
- **動的クエリシステム**: リクエストパラメータによるフィルタリング、ソート、グループ化を DQL にコンパイル
- **Trait ベースのコントローラ構成**: 9 つの mixin trait（List、Detail、Create、Update、Delete、Workflow、Singleton、Transform）を組み合わせて利用
- **モジュラーアーキテクチャ**: Core フレームワーク + Common（CMS）+ Promotion（DSL駆動プロモーション）+ Trade（EC）+ Payment（決済）+ Wallet（ウォレット）+ Wechat（微信）+ Storage（ストレージ）+ Identity（認証）
- **JWT 認証**: RS256 アクセストークン、HMAC-SHA256 リフレッシュトークンのローテーション
- **OTP ログイン**: 電話番号ベースのワンタイムパスワード（SMS）
- **注文ステートマシン**: Symfony Workflow（下書き → 完了）、完全なワークフロー API
- **価格計算パイプライン**: プラグイン可能な価格計算機、優先順位順に実行
- **原子ウォレット転送**: デッドロック防止、楽観的ロック、冪等性
- **ファイルストレージ**: ローカルおよび Qiniu Kodo ドライバのプラグイン可能なアーキテクチャ
- **OpenAPI ドキュメント**: NelmioApiDocBundle + Swagger UI（`/api/doc`）
- **プロモーション DSL エンジン**: カスタム lexer/parser/evaluator による人間可読なプロモーションルール。7 種類のプロモーション（full_reduction、discount、gift、nth_discount、tiered、free_shipping、member_discount）。タグ付き価格計算機（優先度 60）として Trade 価格パイプラインの小計集計後に実行。会員向け SKU 割引、マルチストアルーティング、グローバルキャンペーン、`best_price` コンフリクトモード（候補をシミュレーションして最低金額を選択）をサポート。
- **Profile エンティティ**: ユーザー登録時に Doctrine リスナーにより自動生成。レベル（bronze→diamond）、ニックネーム、アバター、メタデータを保持。ポイントは Wallet（currency=POINTS）に委譲。
- **Docker Compose**: MySQL 8 + Redis + Mailpit による開発環境

## 技術スタック

| コンポーネント | 技術 |
|---------------|------|
| 言語 | PHP `>= 8.4` |
| フレームワーク | Symfony `8.1.*` |
| ORM | Doctrine ORM `^3.6` |
| データベース | MySQL 8（Docker/本番）/ SQLite（テスト） |
| 認証 | JWT（RS256）+ OTP（SMS） |
| API ドキュメント | NelmioApiDocBundle（OpenAPI 3） |
| テスト | PHPUnit `^12.5` |
| ドキュメント | MkDocs Material（GitHub Pages） |

## プロジェクト構成

```text
.
├── src/
│   ├── Core/                     # フレームワークコア
│   ├── Common/                   # CMS モジュール（7 エンティティ）
│   ├── Trade/                    # EC モジュール
│   ├── Wallet/                   # ウォレットモジュール
│   ├── Payment/                  # 決済モジュール
│   ├── Wechat/                   # 微信モジュール
│   ├── Storage/                  # ストレージモジュール
│   ├── Promotion/                # プロモーションモジュール（DSL エンジン）
│   └── Identity/                 # 認証モジュール
├── config/                       # Symfony 設定
├── migrations/                   # Doctrine マイグレーション（12 バージョン）
├── tests/                        # 1589 テスト、5165 アサーション、91%+ カバレッジ
├── translations/                 # 多言語翻訳ファイル
└── compose.yaml                  # Docker Compose
```

## モジュール概要

| モジュール | 名前空間 | 用途 | 主な機能 |
|-----------|---------|------|---------|
| **Core** | `App\Core` | フレームワーク基盤 | RestController、BaseService、View mixin、式パーサー |
| **Common** | `App\Common` | CMS | カテゴリ、タグ、コンテンツ、コメント、ページ、メディア、設定 |
| **Trade** | `App\Trade` | EC | 商品 + 仕様、注文（ステートマシン）、価格計算パイプライン |
| **Wallet** | `App\Wallet` | ウォレット | 残高（セント）、原子転送、システム入金、冪等性、調整 |
| **Payment** | `App\Payment` | 決済管理 | 請求書（セント+ワークフロー）、ゲートウェイ抽象化 |
| **Wechat** | `App\Wechat` | 微信連携 | ミニプログラム/公式アカウントログイン、微信 Pay V3 |
| **Storage** | `App\Storage` | ファイルストレージ | LocalStorage、QiniuStorage |
| **Promotion** | `App\Promotion` | DSL駆動プロモーション | カスタム DSL lexer/parser/evaluator、7 種類の戦略、`trade.price_calculator`（優先度 60）、会員向け SKU 割引、マルチストアルーティング、`best_price` コンフリクトモード |
| **Identity** | `App\Identity` | 認証 | JWT（RS256）、OTP（SMS）、リフレッシュトークンローテーション、Profile エンティティ（自動生成、レベル、ポイントは Wallet に委譲） |

## テスト

**1589 テスト · 5165 アサーション · 91%+ ラインカバレッジ**

```bash
./vendor/bin/phpunit
```

カバレッジ表示：
```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

HTML カバレッジレポートの生成：
```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html var/coverage
```

### テストグループ

| グループ | 数 | カバー範囲 |
|----------|-----|-----------|
| Common | 69+ | CMS エンティティ、メディアアップロード/削除、バッチ更新 |
| Trade | 171+ | 注文、価格設定パイプライン、ワークフロー |
| Wallet | 105+ | 転送、ウォレットサービス、決済ゲートウェイ、残高監査 |
| Payment | 60+ | ゲートウェイ、レジストリ、調整、請求書、マルチゲートウェイ統合 |
| Identity | 116+ | 認証、OTP、トークン、UserService、Profile エンティティ/コントローラ |
| Promotion | 320+ | エンティティ、DSL lexer/parser/evaluator、戦略、エンジン、計算機、コントローラ、実際の SQLite 見積パイプライン統合 |
| Wechat | 59+ | 認証、サービス、決済ゲートウェイ、コントローラ、リポジトリ |
| Core | 70+ | BaseService、RestController、式パーサー、シリアライザ、システムコントローラ |
| Integration | 20+ | モジュール間結合テスト |

## Docker デプロイ

### 開発環境

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

### 本番環境

```bash
cp .env.prod.example .env.prod.local
# .env.prod.local に APP_SECRET、REFRESH_TOKEN_SECRET、MYSQL_PASSWORD 等を記入
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app php bin/console doctrine:migrations:migrate --no-interaction
```

## 国際化（i18n）

このプロジェクトは Symfony Translation コンポーネントによる国際化をサポートしています。翻訳ファイルは `translations/` ディレクトリに YAML 形式で保存されています。

### サポートされているロケール

| ロケールコード | ファイル | 言語 |
|--------------|---------|------|
| `en` | `translations/messages.en.yaml` | 英語（デフォルト） |
| `zh` | `translations/messages.zh.yaml` | 中国語（簡体字） |
| `zh_Hant` | `translations/messages.zh_Hant.yaml` | 中国語（繁体字） |
| `ja` | `translations/messages.ja.yaml` | 日本語 |

### 動作仕組み

1. **例外メッセージ** — API ルートでキャッチされなかった例外は `ExceptionInterceptor` によって処理され、`$this->translator->trans($exception->getMessage())` が呼び出されます。例外メッセージが翻訳キーとして使用されます。
2. **コントローラのエラーレスポンス** — `RestController::warning()`、`AuthController::error()`、`OtpController::error()`、`LoginController::error()` はすべて翻訳処理を経由します。
3. **JWT 認証失敗** — `JwtAuthenticator::onAuthenticationFailure()` は JSON レスポンスを返す前にエラーメッセージを翻訳します。
4. **エンティティフィールド名** — `/system/entities/{entityName}` エンドポイントはフィールド名を翻訳します（例：`createdAt` → `Created at` → `作成日時`）。

### 言語検出

`LocaleListener`（`src/Core/EventListener/LocaleListener.php`）が自動的にユーザーの言語を検出します：

1. **クエリパラメータ** — `?_locale=ja` が最優先
2. **Accept-Language ヘッダー** — ブラウザの `Accept-Language` ヘッダーを読み取り、サポート言語にマッピング：
   - `zh-CN`、`zh-Hans` → `zh`（簡体字）
   - `zh-TW`、`zh-HK`、`zh-Hant` → `zh_Hant`（繁体字）
   - `ja-JP` → `ja`（日本語）
3. **フォールバック** — 未サポートの言語は `en`（設定された `default_locale`）にフォールバック。

### 新しい言語の追加

1. 翻訳ファイルを作成：`translations/messages.{locale}.yaml`
2. `src/Core/EventListener/LocaleListener.php` の `SUPPORTED_LOCALES` と `LOCALE_MAP` にロケールコードを追加
3. Symfony は `translations/` ディレクトリ内のファイルを自動検出するため、追加設定は不要。

### 多言語ドキュメント

| 言語 | ファイル |
|------|---------|
| English | [README.md](README.md) |
| Chinese (Simplified) | [README.zh-cn.md](README.zh-cn.md) |
| Chinese (Traditional) | [README.zh-hant.md](README.zh-hant.md) |

## ライセンス

Apache-2.0。詳細は [LICENSE](LICENSE) をご覧ください。
