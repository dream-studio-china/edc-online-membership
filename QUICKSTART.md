# Quick Start

This repository runs as one modular-monolith application with independent Store,
Inventory, Payment, Wallet, Identity, Common, and Trade apps. Docker Compose starts
the web app (FrankenPHP), seven extracted apps, one shared Messenger worker, and one
shared scheduler. It is not yet the target multi-service runtime.

## Docker

Docker is the recommended local path and the only host prerequisite.

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

Verify login:

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

Open:

- API: `http://localhost:8080`
- Store API: `http://localhost:8081`
- Inventory API: `http://localhost:8082`
- Payment runtime smoke: `http://localhost:8083` (not traffic-ready)
- Wallet runtime smoke: `http://localhost:8084` (not traffic-ready)
- Identity runtime smoke: `http://localhost:8085` (not traffic-ready)
- Common runtime smoke: `http://localhost:8086` (not traffic-ready)
- Trade runtime smoke: `http://localhost:8087` (not traffic-ready)
- OpenAPI UI: `http://localhost:8080/api/doc`
- Mailpit: `http://localhost:8025`

Useful commands:

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

Docker creates development JWT keys under `var/jwt/` on first start. Optional SMS
and WeChat integrations remain disabled until configured.

## Native PHP

Native execution requires PHP 8.4+, Composer, MySQL, a configured `.env.local`,
and local JWT keys. It is intended for contributors who need it; Docker is the
default local workflow.

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
php -S 127.0.0.1:8000 -t public
```

Set `DATABASE_URL`, `APP_SECRET`, `REFRESH_TOKEN_SECRET`, `JWT_PRIVATE_KEY_PATH`,
and `JWT_PUBLIC_KEY_PATH` in `.env.local`. Generate keys with:

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
```

For the architecture roadmap, see
[Microservice Transition Contract](docs/design/microservice-transition.md).
