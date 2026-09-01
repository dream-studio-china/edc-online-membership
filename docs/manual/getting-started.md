# Getting Started

## 1. Prerequisites

- **Docker** and Docker Compose (recommended)
- **PHP 8.4+** for native development (Homebrew PHP 8.5 at `/opt/homebrew/opt/php@8.5/bin/php` on macOS)
- **Composer 2.x**
- **OpenSSL** (for JWT key generation)
- **MySQL 8.0** client (for direct DB access)

## 2. Docker Quick Start

The repository uses `compose.yaml` with 22 services covering all 8 applications:

```bash
# Clone and start all services
git clone <repository-url> crud-platform
cd crud-platform
docker compose up -d --build

# Run monolith migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Run migrations for each extracted app
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec inventory-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec payment-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec wallet-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec identity-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec common-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec trade-app php bin/console doctrine:migrations:migrate --no-interaction

# Create an admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

During development, `compose.override.yaml` loads automatically:
- Sets `APP_ENV=dev`, `APP_DEBUG=1`
- Mounts source code for live reload
- Exposes debug ports

## 3. Port Table

| Service | HTTP Port | Database | DB Port (mapped) |
|---------|-----------|----------|------------------|
| **app** (monolith) | 8080 | database | 33306 |
| **store-app** | 8081 | store-database | 33307 |
| **inventory-app** | 8082 | inventory-database | 33308 |
| **payment-app** | 8083 | payment-database | 33309 |
| **wallet-app** | 8084 | wallet-database | 33310 |
| **identity-app** | 8085 | identity-database | 33311 |
| **common-app** | 8086 | common-database | 33312 |
| **trade-app** | 8087 | trade-database | 33313 |
| **worker** | — (CLI) | — | — |
| **scheduler** | — (CLI) | — | — |
| **redis** | 6379 | — | — |
| **mailer** (Mailpit) | 8025 (UI) / 1025 (SMTP) | — | — |

- Root database port (`MYSQL_PORT`) defaults to `33306`; configure via env to avoid
  host-side MySQL collisions.
- App HTTP ports are configurable via `APP_PORT`, `STORE_PORT`, etc. env vars.

## 4. Native PHP Setup (Without Docker)

```bash
# Install dependencies
composer install

# Set up environment
cp .env .env.local
# Edit .env.local with your DB connection, JWT paths, etc.

# Generate JWT keys (see section 5)

# Create database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction

# Create admin user
php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# Start development server
php -S localhost:8000 -t public/
# Or with FrankenPHP:
php public/index.php
```

For each extracted app (e.g., Store):
```bash
cd apps/store
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php -S localhost:8081 -t public/
```

## 5. JWT Key Generation

The platform uses RS256 JWT for authentication. Generate keys:

```bash
# Create key directory
mkdir -p var/jwt

# Generate private key
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem \
  -pkeyopt rsa_keygen_bits:4096 \
  -aes256 -pass pass:your_passphrase

# Generate public key
openssl rsa -pubout -in var/jwt/jwt_private.pem \
  -out var/jwt/jwt_public.pem \
  -passin pass:your_passphrase

# Set permissions
chmod 600 var/jwt/jwt_private.pem
chmod 644 var/jwt/jwt_public.pem
```

Set in `.env.local`:
```ini
JWT_PRIVATE_KEY_PATH=%kernel.project_dir%/var/jwt/jwt_private.pem
JWT_PUBLIC_KEY_PATH=%kernel.project_dir%/var/jwt/jwt_public.pem
JWT_PASSPHRASE=your_passphrase
REFRESH_TOKEN_SECRET=your-hmac-secret-at-least-32-chars
```

In Docker, the dev entrypoint (`docker/app/entrypoint.sh`) generates dev keys
automatically under mounted `./var/jwt` if missing. In production, keys are
generated on the host and mounted into the container.

## 6. Verifying the Setup

### Health Check

```bash
# API doc (public)
curl http://localhost:8080/api/doc

# Login
curl -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@example.com","password":"P@ssw0rd"}'
```

Expected response:
```json
{
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "...",
    "expires_in": 7200
  },
  "code": 200,
  "message": "Success"
}
```

### Smoke Tests

```bash
# Run API smoke tests
bash scripts/tests/api-smoke.sh

# Run Store orchestration smoke
bash scripts/tests/store-smoke.sh

# Run Trade workflow demo
php scripts/tests/demo-trade-workflow.php
```

## 7. Common Troubleshooting

### "Could not find driver" (Database)

Ensure the MySQL PDO driver is installed:
```bash
# macOS with Homebrew PHP
php -m | grep pdo_mysql

# Docker: verify database container is healthy
docker compose ps database
```

### JWT Key Permission Denied

```bash
# Check file permissions
ls -la var/jwt/

# Ensure private key is readable by the PHP process
chmod 644 var/jwt/jwt_private.pem  # in Docker (no passphrase by default)
```

### Port Already in Use

```bash
# Check what's using the port
lsof -i :8080

# Configure alternative ports via .env:
echo "APP_PORT=9080" >> .env
echo "MYSQL_PORT=43306" >> .env
```

### Container Build Fails

```bash
# Clear build cache
docker compose build --no-cache

# Check disk space
docker system df
```

### Migration Errors on Extracted Apps

Each app has its own database. Ensure the database exists:
```bash
docker compose exec store-app php bin/console doctrine:database:create --if-not-exists
docker compose exec store-app php bin/console doctrine:migrations:migrate --no-interaction
```

### PHP Version Issues (Native)

The platform requires PHP 8.4+. On macOS, Homebrew PHP 8.5 is recommended:
```bash
# Check current version
php -v

# Use Homebrew PHP 8.5 explicitly
/opt/homebrew/opt/php@8.5/bin/php -v
/opt/homebrew/opt/php@8.5/bin/php bin/console about
```

### Swagger/Mailpit Not Accessible

- **Swagger UI**: `http://localhost:8080/api/doc`
- **Mailpit UI**: `http://localhost:8025`
- Ensure `compose.override.yaml` is loaded (check `docker compose config`)

### Container Ports Not Exposed

Development ports are defined in `compose.override.yaml`. If they're not
available, ensure the file is automatically loaded (default behavior) or
explicitly include it:
```bash
docker compose -f compose.yaml -f compose.override.yaml up -d
```
