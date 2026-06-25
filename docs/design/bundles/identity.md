Identity Module Design
======================

Overview
--------
This document describes the Identity module (src/Identity). It implements:

- JWT access tokens (RS256) with a 7200s TTL
- server-stored refresh tokens (opaque, hashed) with 1 year TTL and rotation
- phone-based OTP login/verification, delivered via Alibaba Cloud SMS
- identifier-based login: identifier can be email, username or a verified phone
- **password-based user self-registration** (`POST /api/auth/register`)
- **user profile management** with password change and profile update
- **admin user CRUD** with managed password changes

Goals
-----
- Keep existing identifier+password flow intact
- Add phone+OTP as an additional auth path
- **Add password registration** as a self-service onboarding path
- **Add user controllers** for profile management (App) and admin CRUD (Manage)
- Use Redis for OTP storage and rate-limiting
- Use RS256 for JWT signing and verify with public key
- Store refresh tokens hashed in MySQL (identity_refresh_token table)

Environment variables (.env.example)
-----------------------------------
See .env.example at repository root for all variables. Key ones:

- JWT_PRIVATE_KEY_PATH, JWT_PUBLIC_KEY_PATH, JWT_PASSPHRASE
- ACCESS_TOKEN_TTL (7200)
- REFRESH_TOKEN_TTL (31536000)
- REFRESH_TOKEN_SECRET
- OTP_TTL (300)
- OTP_REDIS_DSN
- ALIYUN_ACCESS_KEY_ID, ALIYUN_ACCESS_KEY_SECRET
- ALIYUN_SMS_SIGN_NAME
- ALIYUN_SMS_TEMPLATE_LOGIN_OTP
- ALIYUN_SMS_TEMPLATE_VERIFY_PHONE
- ALIYUN_SMS_DRY_RUN (development safe flag)

DB Schema (MySQL)
------------------
We will add the following table for refresh tokens (migration SQL will be provided):

CREATE TABLE identity_refresh_token (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  refresh_token_hash VARCHAR(128) NOT NULL,
  jti VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  replaced_by_token_id BIGINT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent TEXT,
  INDEX (refresh_token_hash),
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

Note: users.phone is nullable; a UNIQUE index on phone is used. MySQL allows multiple NULLs, which satisfies "nullable but unique when present" semantics.

Token flows
-----------

Login (identifier + password)
- POST /api/auth/login { identifier, password }
- identifier may be email, username or a phone (phone allowed only if phone_verified=true)
- on success returns { access_token, refresh_token, expires_in }

Register (password self-registration)
- POST /api/auth/register { email, username, password, phone? }
- Public endpoint (no auth required)
- Validates uniqueness of email, username, and phone
- Creates User with hashed password via UserService::register()
- Returns JWT tokens directly (same format as login)
- Password minimum 6 characters

OTP Login / Verify
- POST /api/auth/otp/request { phone, purpose }
  - generates OTP, stores hash in Redis, sends SMS via Aliyun
- POST /api/auth/otp/verify { phone, otp, purpose }
  - verifies OTP; if purpose=login issues tokens; if purpose=verify_phone marks phone_verified

Token Refresh
- POST /api/auth/token/refresh { refresh_token }
- Server looks up hashed refresh token, validates, rotates (creates new refresh token, revokes old)

Logout
- POST /api/auth/logout { refresh_token }
- marks refresh token revoked

User Profile (App)
------------------

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/app/users/me` | ROLE_USER | Get current user profile |
| PUT | `/api/v1/app/users/me` | ROLE_USER | Update email, username, phone, optional password |
| POST | `/api/v1/app/users/change-password` | ROLE_USER | Change own password (requires current password) |

User Management (Manage)
------------------------

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/manage/users` | ROLE_ADMIN | List all users |
| GET | `/api/v1/manage/users/{id}` | ROLE_ADMIN | View user detail |
| POST | `/api/v1/manage/users` | ROLE_ADMIN | Create user (with hashed password) |
| PUT | `/api/v1/manage/users/{id}` | ROLE_ADMIN | Update user (email, username, password, phone, roles) |
| DELETE | `/api/v1/manage/users/{id}` | ROLE_ADMIN | Delete user |
| POST | `/api/v1/manage/users/{id}/change-password` | ROLE_ADMIN | Admin change user password (no current pw required) |

UserService
-----------

`App\Identity\Service\UserService` extends `BaseService` and encapsulates all user business logic:

| Method | Description |
|--------|-------------|
| `register($email, $username, $password, $phone)` | Validate uniqueness, create User, hash password, persist |
| `verifyPassword($user, $password)` | Verify a plain password against a User |
| `changePassword($user, $currentPassword, $newPassword)` | Verify current password, hash and set new, persist |
| `adminChangePassword($user, $newPassword)` | Hash and set new password, persist (no current pw check) |
| `updateProfile($user, $data)` | Validate uniqueness of email/username/phone, update fields, optional password change |
| `update($object, $data)` | Auto-hashes password when present in data; skips empty passwords |

Security Considerations
-----------------------
- Use HTTPS only
- Keep private key & secrets out of repo; use secret manager
- Hash refresh tokens with HMAC-SHA256 using REFRESH_TOKEN_SECRET
- OTPs are 6-digit numeric, stored as hash in Redis, one-time use, TTL 5 minutes
- Implement rate-limits: per-phone, per-IP, and per-account limits
- Detect refresh token reuse: if a revoked/replaced token is used, revoke all user tokens

Aliyun SMS Integration
----------------------
- Aliyun SDK (alibabacloud/client) will be used. The provider reads keys from env.
- Templates must be created and approved in Aliyun console. Template variables: {code}
- We provide ALIYUN_SMS_DRY_RUN for staging to avoid real sends

Implementation notes
--------------------
- Namespace: App\Identity
- Paths: src/Identity/{Entity,Repository,Service,Sms,Security,Controller,Resources}
- Services registered under src/Identity/Resources/config/services_identity.yaml
- Tests: unit tests for TokenManager, OtpService, and **UserService**; integration tests for all endpoints

Test Coverage
-------------

| File | Type | Coverage |
|------|------|----------|
| `UserServiceTest` | Unit (28 tests) | register, changePassword, adminChangePassword, updateProfile, update password hashing |
| `UserControllerTest` | Unit (3 tests) | Unauthenticated access rejection for all actions |
| `UserApiIntegrationTest` | Integration (45 tests) | Register flow, login, profile, change-password, update-profile, manage CRUD, manage change-password, specification browsing, wallet deposit, transfer, balance, reconcile, edge cases for all endpoints |
| `AuthControllerTest` | Unit (existing) | Login, logout, refresh, OTP verification |

Next steps
----------
I will generate the code patch implementing the module (files, migrations, README) and post it for review. After you approve, I will commit the changes to the repository in small steps with tests and migrations.
