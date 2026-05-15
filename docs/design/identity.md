Identity Module Design
======================

Overview
--------
This document describes the Identity module (src/Identity) we will add to the project. It implements:

- JWT access tokens (RS256) with a 7200s TTL
- server-stored refresh tokens (opaque, hashed) with 1 year TTL and rotation
- phone-based OTP login/verification, delivered via Alibaba Cloud SMS
- identifier-based login: identifier can be email, username or a verified phone

Goals
-----
- Keep existing identifier+password flow intact
- Add phone+OTP as an additional auth path
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
- Tests: unit tests for TokenManager and OtpService; integration tests for endpoints

Next steps
----------
I will generate the code patch implementing the module (files, migrations, README) and post it for review. After you approve, I will commit the changes to the repository in small steps with tests and migrations.
