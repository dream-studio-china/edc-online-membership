# Liansheng Bundle Design

## Overview

`src/Liansheng/` is an outbound adapter for Lincson Cloud (Liansheng). It owns the vendor HTTP contract and does not persist vendor data or couple it to local Store, Identity, or Wallet entities.

## Service Contract

`LianshengServiceInterface` exposes the documented APIs needed by this application:

| Method | Vendor API | Purpose |
|---|---|---|
| `getStore()` | 0102 `POST /api/open/getapptoken` | Gets the store token and its store metadata. |
| `getBusinessRevenueReport()` | 0504 `POST /api/open/rptbusiness` | Gets a date-range business revenue report. |
| `getMemberByMobile()` | 0701 `GET /api/vip.api?method=getvipmember` with JSON body | Gets member profiles by mobile number. The response may contain multiple records. |

The store token is held in `cache.app` until one minute before the vendor-reported expiry. No token, credential, member data, or report data is stored in Doctrine.

Live testing shows that 0701 requires `method=getvipmember` in the query string and a JSON request body. The body accepts `mobile`, `cardid`, or `cloudId`; the returned `code` field may be null.

## Controllers

| Method | Local endpoint | Authorization | Vendor API |
|---|---|---|---|
| `GET` | `/api/v1/app/liansheng/store` | `ROLE_USER` | 0102 |
| `GET` | `/api/v1/app/liansheng/member?mobile=...` | `ROLE_USER` | 0701 |
| `GET` | `/api/v1/manage/liansheng/business-revenue?beginDate=YYYY-MM-DD&endDate=YYYY-MM-DD` | `ROLE_ADMIN` | 0504 |

Controllers own local request validation, response envelopes, authorization, and mapping vendor failures to HTTP 502. The 0102 token ID is internal and is removed from the store response.

## Configuration

Set credentials in an uncommitted environment file or secret store:

```dotenv
LIANSHENG_BASE_URL=https://wx2.lincson.cn/web
LIANSHENG_APP_CODE=
LIANSHENG_APP_SECRET=
LIANSHENG_USER_ID=1
```

`LianshengApiException` represents transport, HTTP, invalid JSON, and vendor-level response failures.

`https://wx2.lincson.cn/web` has a valid certificate for `wx2.lincson.cn` and serves the documented open API over HTTPS. The older `https://test.lincson.cn/web` hostname has a certificate mismatch and its HTTPS `/web/api/*` path rejects POST with 405; it must not be used.
