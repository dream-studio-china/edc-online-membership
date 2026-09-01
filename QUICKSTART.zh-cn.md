# 快速上手

当前仓库以一个模块化单体应用和独立 Store、Inventory、Payment、Wallet、Identity、
Common、Trade 应用运行。Docker Compose 会启动 Web 应用（FrankenPHP）、七个已提取应用、
共享 Messenger worker 和共享 scheduler；这还不是目标的多服务运行时。

## Docker

Docker 是推荐的本地运行方式，主机只需要 Docker。

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec inventory-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec payment-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec wallet-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec identity-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec common-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec trade-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

验证登录：

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

访问地址：

- API：`http://localhost:8080`
- Store API：`http://localhost:8081`
- Inventory API：`http://localhost:8082`
- Payment runtime 冒烟：`http://localhost:8083`（尚未可切流）
- Wallet runtime 冒烟：`http://localhost:8084`（尚未可切流）
- Identity runtime 冒烟：`http://localhost:8085`（尚未可切流）
- Common runtime 冒烟：`http://localhost:8086`（尚未可切流）
- Trade runtime 冒烟：`http://localhost:8087`（尚未可切流）
- OpenAPI UI：`http://localhost:8080/api/doc`
- Mailpit：`http://localhost:8025`

常用命令：

```bash
docker compose logs -f app worker scheduler store-app inventory-app payment-app wallet-app identity-app common-app trade-app
docker compose exec app php bin/console about
docker compose exec app php bin/console doctrine:migrations:status
docker compose exec store-app php bin/console doctrine:migrations:status
docker compose exec inventory-app php bin/console doctrine:migrations:status
docker compose exec payment-app php bin/console doctrine:migrations:status
docker compose exec wallet-app php bin/console doctrine:migrations:status
docker compose exec identity-app php bin/console doctrine:migrations:status
docker compose exec common-app php bin/console doctrine:migrations:status
docker compose exec trade-app php bin/console doctrine:migrations:status
docker compose down
```

Docker 首次启动会在 `var/jwt/` 生成开发 JWT 密钥。短信和微信等可选集成在配置前保持关闭。

## 本机 PHP

本机运行需要 PHP 8.4+、Composer、MySQL、配置好的 `.env.local` 和本地 JWT 密钥。
它面向有此需求的贡献者；默认本地工作流使用 Docker。

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
php -S 127.0.0.1:8000 -t public
```

在 `.env.local` 设置 `DATABASE_URL`、`APP_SECRET`、`REFRESH_TOKEN_SECRET`、
`JWT_PRIVATE_KEY_PATH` 和 `JWT_PUBLIC_KEY_PATH`。生成密钥：

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
```

架构迁移路线见
[微服务迁移契约](docs/design/microservice-transition.md)。
