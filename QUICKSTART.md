# Quick Start

This Quick Start walks you through a minimal, runnable development setup: generating JWT keys, running migrations to prepare the database, creating an admin user, and testing authentication.

Prerequisites
 - PHP 8.5 (Homebrew is recommended on macOS)
 - Composer
 - MySQL / MariaDB or PostgreSQL (as configured in `DATABASE_URL`)
 - Optional: Symfony CLI

On macOS (Homebrew):

```bash
brew install php@8.5 composer
```

1) Install dependencies

```bash
composer install
```

2) Configure environment variables

Create or update `.env.local` (do not commit) with values appropriate for your environment. Example:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://user:password@127.0.0.1:3306/crud_skeleton?serverVersion=8.0&charset=utf8mb4"

JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=
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
```

3) Generate development JWT keys

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
chmod 600 var/jwt_dev_private.pem
```

If your private key is not encrypted, leave `JWT_PASSPHRASE` empty.

4) Initialize the database (recommended unified migration flow)

Use Homebrew PHP to avoid CLI version mismatch:

```bash
/opt/homebrew/bin/php bin/console doctrine:schema:drop --force
/opt/homebrew/bin/php bin/console doctrine:migrations:migrate --no-interaction
```

5) Create an administrator account

```bash
/opt/homebrew/bin/php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

6) Start the local server

```bash
php -S 127.0.0.1:8000 -t public
```

or

```bash
symfony server:start
```

7) Log in and test protected endpoints

Obtain an access token:

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
   -H 'Content-Type: application/json' \
   -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
   | /opt/homebrew/bin/php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"];')
```

Call a management endpoint (requires `ROLE_ADMIN`):

```bash
curl -i -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/manage/contents
```

8) API documentation

- Swagger UI: `http://127.0.0.1:8000/api/doc`
- Click `Authorize` and paste `Bearer <access_token>` to try authenticated endpoints in the UI.

Troubleshooting

- `openssl_sign(): ... cannot be coerced into a private key`:
   - Verify `JWT_PRIVATE_KEY_PATH` exists and points to a valid PEM file.
   - If you configured `JWT_PASSPHRASE`, ensure it matches the private key; set it empty if your key is unencrypted.

- OTP Redis / Predis errors:
   - The app defaults to local cache OTP storage for development to avoid Redis dependency. Run ` /opt/homebrew/bin/php bin/console cache:clear` if you switched back to Redis.

- `doctrine:migrations:migrate` failing due to missing tables:
   - Ensure you have the latest migrations and run the unified migration flow in step 4.

