# Quick Start / 快速上手

> 5 分钟完成本地可登录、可调用受保护接口的最小流程。

## 方式 A：Docker（推荐）

无需本地安装 PHP、Composer 或数据库，仅需 **Docker**。

```bash
# 1) 启动所有服务（app、nginx、PostgreSQL、Redis、Mailpit）
docker compose up -d --build

# 2) 执行数据库迁移
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3) 创建管理员
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 4) 登录获取 token
curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

应用地址：`http://localhost:8080`。Swagger 文档：`http://localhost:8080/api/doc`。

---

## 方式 B：本机 PHP

环境要求

- PHP `8.5`（建议 Homebrew）
- Composer
- MySQL/MariaDB（按你的 `DATABASE_URL`）
- 可选：Symfony CLI

macOS (Homebrew) 推荐：

```bash
brew install php@8.5 composer
```

## 1) 安装依赖

```bash
composer install
```

## 2) 配置环境变量

在项目根创建/更新 `.env.local`（不要提交到 Git）：

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://user:password@127.0.0.1:3306/crud_skeleton?serverVersion=8.0&charset=utf8mb4"

JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=dev-passphrase
ACCESS_TOKEN_TTL=7200
REFRESH_TOKEN_TTL=31536000
REFRESH_TOKEN_SECRET=change-this-secret

OTP_TTL=300
OTP_REDIS_DSN=redis://127.0.0.1:6379/0

ALIYUN_ACCESS_KEY_ID=
ALIYUN_ACCESS_KEY_SECRET=
ALIYUN_SMS_REGION=cn-hangzhou
ALIYUN_SMS_SIGN_NAME=DemoApp
ALIYUN_SMS_TEMPLATE_LOGIN_OTP=SMS_0000001
ALIYUN_SMS_TEMPLATE_VERIFY_PHONE=SMS_0000002
ALIYUN_SMS_DRY_RUN=true

# 微信（小程序+公众号+支付 — 可选，仅需微信功能时配置）
WECHAT_MINIAPP_APP_ID=
WECHAT_MINIAPP_SECRET=
WECHAT_OFFICIAL_APP_ID=
WECHAT_OFFICIAL_SECRET=
WECHAT_OFFICIAL_TOKEN=
WECHAT_OFFICIAL_AES_KEY=
WECHAT_PAY_MCH_ID=
WECHAT_PAY_SECRET_KEY=
WECHAT_PAY_PRIVATE_KEY=
WECHAT_PAY_CERTIFICATE=
WECHAT_PAY_NOTIFY_URL=
```

## 3) 生成 JWT 开发密钥

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
chmod 600 var/jwt_dev_private.pem
```

> 如果私钥是未加密 PEM，可把 `JWT_PASSPHRASE` 置空（`JWT_PASSPHRASE=`）。

## 4) 初始化数据库（统一迁移流程）

> 使用 Homebrew PHP 8.5，避免系统默认 PHP 版本不一致。

```bash
/opt/homebrew/bin/php bin/console doctrine:schema:drop --force
/opt/homebrew/bin/php bin/console doctrine:migrations:migrate --no-interaction
```

## 5) 创建管理员账号

```bash
/opt/homebrew/bin/php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

## 6) 启动服务

```bash
php -S 127.0.0.1:8000 -t public
```

或：

```bash
symfony server:start
```

## 7) 登录并验证受保护接口

获取 token：

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
  | /opt/homebrew/bin/php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"];')
```

访问管理接口（需要 `ROLE_ADMIN`）：

```bash
curl -i -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/manage/contents
```

## 8) API 文档

- Swagger UI: `http://127.0.0.1:8000/api/doc`
- 右上角 `Authorize` 输入：`Bearer <access_token>`

## 9) 系统自省接口

```bash
# 列出所有 Doctrine 实体
curl http://127.0.0.1:8000/system/entities

# 获取实体字段元数据
curl http://127.0.0.1:8000/system/entities/App%5CCommon%5CEntity%5CCategory

# 列出所有已注册路由
curl http://127.0.0.1:8000/system/router
```

## 常见问题

1. `openssl_sign(): ... cannot be coerced into a private key`
   - 检查 `JWT_PRIVATE_KEY_PATH` 是否存在
   - 检查 `JWT_PASSPHRASE` 与私钥是否匹配（不匹配可置空）

2. OTP 报 Redis/Predis 类型错误
   - 当前默认已使用本地缓存 OTP 存储（不依赖 Redis）
   - 先执行：`/opt/homebrew/bin/php bin/console cache:clear`

3. `migrations:migrate` 失败提示缺少 `users`
   - 请确保已拉取最新迁移并执行第 4 步（统一迁移流程）
