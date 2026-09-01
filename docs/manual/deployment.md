# Deployment

## 1. Docker Compose Topology

The platform uses Docker Compose with **22 services**:

| # | Service | Image | Purpose |
|---|---------|-------|---------|
| 1 | `app` | FrankenPHP 8.4 | Root monolith (API + HTTP) |
| 2 | `worker` | FrankenPHP 8.4 | Messenger async consumer (CLI-only) |
| 3 | `scheduler` | FrankenPHP 8.4 | Outbox relay (Trade, Store, Inventory, Payment) |
| 4 | `store-app` | FrankenPHP 8.4 | Store application |
| 5 | `inventory-app` | FrankenPHP 8.4 | Inventory application |
| 6 | `payment-app` | FrankenPHP 8.4 | Payment application |
| 7 | `wallet-app` | FrankenPHP 8.4 | Wallet application |
| 8 | `identity-app` | FrankenPHP 8.4 | Identity application |
| 9 | `common-app` | FrankenPHP 8.4 | Common application |
| 10 | `trade-app` | FrankenPHP 8.4 | Trade application |
| 11 | `database` | MySQL 8.4 | Root monolith database |
| 12 | `store-database` | MySQL 8.4 | Store database |
| 13 | `inventory-database` | MySQL 8.4 | Inventory database |
| 14 | `payment-database` | MySQL 8.4 | Payment database |
| 15 | `wallet-database` | MySQL 8.4 | Wallet database |
| 16 | `identity-database` | MySQL 8.4 | Identity database |
| 17 | `common-database` | MySQL 8.4 | Common database |
| 18 | `trade-database` | MySQL 8.4 | Trade database |
| 19 | `redis` | Redis 7 Alpine | OTP storage |
| 20 | `mailer` | Mailpit | Email testing (SMTP + Web UI) |
| — | `database-test` | MySQL 8.4 | (compose.override.yaml only) |

## 2. Service Table

### Application Services

| Service | Internal Port | Host Port | Database | DB Port (host) | Healthcheck |
|---------|---------------|-----------|----------|-----------------|-------------|
| `app` | 80 | `${APP_PORT:-8080}` | `database:3306` | `${MYSQL_PORT:-33306}` | — |
| `worker` | — | — | `database:3306` | — | — |
| `scheduler` | — | — | `database:3306` | — | — |
| `store-app` | 80 | `${STORE_PORT:-8081}` | `store-database:3306` | `${STORE_MYSQL_PORT:-33307}` | `curl /` |
| `inventory-app` | 80 | `${INVENTORY_PORT:-8082}` | `inventory-database:3306` | `${INVENTORY_MYSQL_PORT:-33308}` | `curl /` |
| `payment-app` | 80 | `${PAYMENT_PORT:-8083}` | `payment-database:3306` | `${PAYMENT_MYSQL_PORT:-33309}` | `curl /` |
| `wallet-app` | 80 | `${WALLET_PORT:-8084}` | `wallet-database:3306` | `${WALLET_MYSQL_PORT:-33310}` | `curl /` |
| `identity-app` | 80 | `${IDENTITY_PORT:-8085}` | `identity-database:3306` | `${IDENTITY_MYSQL_PORT:-33311}` | `curl /` |
| `common-app` | 80 | `${COMMON_PORT:-8086}` | `common-database:3306` | `${COMMON_MYSQL_PORT:-33312}` | `curl /` |
| `trade-app` | 80 | `${TRADE_PORT:-8087}` | `trade-database:3306` | `${TRADE_MYSQL_PORT:-33313}` | `curl /` |

### Infrastructure Services

| Service | Internal Port | Host Port | Notes |
|---------|---------------|-----------|-------|
| `redis` | 6379 | 6379 | Redis 7 Alpine |
| `mailer` | 1025 (SMTP), 8025 (UI) | 1025, 8025 | Mailpit |
| `database` | 3306 | `${MYSQL_PORT:-33306}` | MySQL 8.4, `app` DB |

Worker and scheduler override `APP_ENV=prod` and disable inherited HTTP ports
and healthchecks (they are CLI services).

## 3. Development vs Production Overlays

### Development (`compose.override.yaml` — loads automatically)

```yaml
services:
  app:
    environment:
      APP_ENV: dev
      APP_DEBUG: "1"
    volumes:
      - .:/var/www/html  # Source mount for live reload
    ports:
      - "8080:80"

  store-app:
    environment:
      APP_ENV: dev
      APP_DEBUG: "1"
    volumes:
      - ./apps/store:/var/www/html
```

- Source code mounted directly into containers
- `APP_DEBUG` enabled
- Dev JWT keys generated automatically (`docker/app/entrypoint.sh`)
- Empty `.env` placeholder created for Symfony Runtime

### Production (`compose.prod.yaml`)

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
```

Production requirements:
- `.env.prod.local` based on `.env.prod.example`
- Must set: `APP_SECRET`, `REFRESH_TOKEN_SECRET`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`
- Each app needs its own secrets (e.g., `STORE_APP_SECRET`, `STORE_MYSQL_PASSWORD`)
- JWT keys generated on host at `./var/jwt/`, mounted into container
- `APP_DEBUG=0`, no source mounts
- `restart: unless-stopped` on all services

## 4. Environment Variables Reference

### Symfony Core

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_ENV` | Yes | `prod` | `dev`, `test`, or `prod` |
| `APP_DEBUG` | No | `0` | Debug mode (0 or 1) |
| `APP_SECRET` | **Yes** | — | Symfony application secret (prod) |
| `DATABASE_URL` | Yes | — | MySQL DSN |

### JWT Authentication

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `JWT_PRIVATE_KEY_PATH` | Yes | `/var/www/html/var/jwt/jwt_private.pem` | Path to RSA private key |
| `JWT_PUBLIC_KEY_PATH` | Yes | `/var/www/html/var/jwt/jwt_public.pem` | Path to RSA public key |
| `JWT_PASSPHRASE` | No | — | Private key passphrase |
| `ACCESS_TOKEN_TTL` | No | `7200` | Access token lifetime (seconds) |
| `REFRESH_TOKEN_TTL` | No | `31536000` | Refresh token lifetime (1 year) |
| `REFRESH_TOKEN_SECRET` | **Yes** | — | HMAC-SHA256 secret (prod, ≥32 chars) |

### OTP / SMS

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `OTP_REDIS_DSN` | No | `redis://redis:6379/0` | Redis for OTP storage |
| `ALIYUN_ACCESS_KEY_ID` | No | — | Alibaba Cloud SMS |
| `ALIYUN_ACCESS_KEY_SECRET` | No | — | Alibaba Cloud SMS |
| `ALIYUN_SMS_REGION` | No | `cn-hangzhou` | SMS region |
| `ALIYUN_SMS_SIGN_NAME` | No | — | SMS signature |
| `ALIYUN_SMS_TEMPLATE_LOGIN_OTP` | No | — | Login OTP template |
| `ALIYUN_SMS_DRY_RUN` | No | `true` | Disable actual SMS sending |

### WeChat

| Variable | Required | Description |
|----------|----------|-------------|
| `WECHAT_MINIAPP_APP_ID` | No | Mini Program App ID |
| `WECHAT_MINIAPP_SECRET` | No | Mini Program secret |
| `WECHAT_OFFICIAL_APP_ID` | No | Official Account App ID |
| `WECHAT_OFFICIAL_SECRET` | No | Official Account secret |
| `WECHAT_PAY_MCH_ID` | No | WeChat Pay merchant ID |
| `WECHAT_PAY_SECRET_KEY` | No | WeChat Pay APIv3 key |
| `WECHAT_PAY_PRIVATE_KEY` | No | Merchant private key path |
| `WECHAT_PAY_CERTIFICATE` | No | Platform certificate path |
| `WECHAT_PAY_NOTIFY_URL` | No | Payment notify callback URL |

### Infrastructure

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `MAILER_DSN` | No | `smtp://mailer:1025` | Mailer transport |
| `MESSENGER_TRANSPORT_DSN` | No | `doctrine://default?auto_setup=0` | Async transport |
| `DEFAULT_URI` | No | `http://localhost` | Base URL |
| `INVENTORY_ENABLED` | No | `0` | Global inventory toggle |
| `OUTBOX_PUBLISH_INTERVAL` | No | `5` | Outbox relay interval (seconds) |

### Per-App Variables

Each app uses prefixed environment variables:

| App | Prefix | Example |
|-----|--------|---------|
| Store | `STORE_` | `STORE_APP_SECRET`, `STORE_DATABASE_URL`, `STORE_MYSQL_PASSWORD` |
| Inventory | `INVENTORY_` | `INVENTORY_APP_SECRET`, `INVENTORY_DATABASE_URL` |
| Payment | `PAYMENT_` | `PAYMENT_APP_SECRET`, `PAYMENT_DATABASE_URL` |
| Wallet | `WALLET_` | `WALLET_APP_SECRET`, `WALLET_DATABASE_URL` |
| Identity | `IDENTITY_` | `IDENTITY_APP_SECRET`, `IDENTITY_DATABASE_URL` |
| Common | `COMMON_` | `COMMON_APP_SECRET`, `COMMON_DATABASE_URL` |
| Trade | `TRADE_` | `TRADE_APP_SECRET`, `TRADE_DATABASE_URL` |

## 5. JWT Key Management in Docker

### Development

The dev entrypoint (`docker/app/entrypoint.sh`) auto-generates keys:

```bash
if [ ! -f /var/www/html/var/jwt/jwt_private.pem ]; then
    mkdir -p /var/www/html/var/jwt
    openssl genpkey -algorithm RSA -out /var/www/html/var/jwt/jwt_private.pem \
      -pkeyopt rsa_keygen_bits:4096
    openssl rsa -pubout -in /var/www/html/var/jwt/jwt_private.pem \
      -out /var/www/html/var/jwt/jwt_public.pem
fi
```

Keys are generated once under the mounted `./var/jwt` directory and persist
across container restarts.

### Production

Keys are generated on the **host** machine and mounted:

```bash
# On host
mkdir -p var/jwt
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem \
  -pkeyopt rsa_keygen_bits:4096 -aes256 -pass pass:${JWT_PASSPHRASE}
openssl rsa -pubout -in var/jwt/jwt_private.pem \
  -out var/jwt/jwt_public.pem -passin pass:${JWT_PASSPHRASE}
chmod 600 var/jwt/jwt_private.pem
chmod 644 var/jwt/jwt_public.pem
```

The production entrypoint validates JWT key presence and exits with an error
if keys are missing.

## 6. Building Images

### Root Monolith

```bash
docker compose build app
```

Uses root `Dockerfile` (FrankenPHP 8.4 Alpine, Caddyfile at `docker/frankenphp/Caddyfile`).

### Per-App Services

Each app has its own `Dockerfile` and `docker/Caddyfile`:

```bash
docker compose build store-app
docker compose build payment-app
# ... etc
```

Dockerfiles are in `apps/{name}/Dockerfile`. Build context is the repository
root for the monolith, and individual app directories for per-app services.

### Build All

```bash
docker compose build
```

## 7. Running Migrations in Production

```bash
# Root monolith
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Each app
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec inventory-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec payment-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec wallet-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec identity-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec common-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec trade-app php bin/console doctrine:migrations:migrate --no-interaction
```

**Important**: Run root monolith migrations first, then per-app migrations.
Each migration chain is independent.

### Creating an Admin User

```bash
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

## 8. Mailpit for Email Testing

Mailpit catches all outgoing emails:

- **SMTP endpoint**: `mailer:1025` (internal), `localhost:1025` (host)
- **Web UI**: `http://localhost:8025`
- All emails are intercepted and viewable in the UI
- No emails are sent to real recipients

Configure in `.env`:
```ini
MAILER_DSN=smtp://mailer:1025
```

## 9. Redis for OTP

Redis stores OTP codes with TTL. No persistent storage needed.

- **Connection**: `redis://redis:6379/0`
- **Database index**: 0
- OTP codes expire automatically (configurable TTL)

## 10. FrankenPHP / Caddyfile Configuration

### Root Monolith Caddyfile (`docker/frankenphp/Caddyfile`)

```
{
    frankenphp
}

:80 {
    header {
        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        Access-Control-Allow-Headers "Content-Type, Authorization"
    }

    root * /var/www/html/public/
    php_server {
        resolve_root_symlink
    }
    encode gzip
    file_server
}
```

### Per-App Caddyfiles (`apps/{name}/docker/Caddyfile`)

Each app has a similar Caddyfile configured for its own `public/` directory
and internal port 80.

## 11. `compose.prod.yaml` Usage

```bash
# 1. Create env file from example
cp .env.prod.example .env.prod.local
# Edit with real secrets

# 2. Generate JWT keys on host
# (see section 5)

# 3. Start production stack
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build

# 4. Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
# ... repeat for each app

# 5. Create admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

### Production Differences from Dev

| Feature | Dev | Prod |
|---------|-----|------|
| Source mount | Yes | No |
| `APP_DEBUG` | 1 | 0 |
| JWT keys | Auto-generated | Host-generated + mounted |
| Secrets | Defaults in compose.yaml | `.env.prod.local` |
| Ports | Exposed directly | Via reverse proxy (recommended) |
| Logging | stderr | File or collector |
| Healthchecks | Disabled on worker/scheduler | Required |
