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
| `deductMemberPoints()` | 0703 `POST /api/vip.api?method=vipsubscore` | Deducts a positive integer number of member points synchronously. |
| `creditMemberPoints()` | 0703 `POST /api/vip.api?method=vipsubscore` | Credits a positive integer number of member points synchronously. |

The store token is held in `cache.app` until one minute before the vendor-reported expiry. No token, credential, member data, or report data is stored in Doctrine.

Live testing shows that 0701 requires `method=getvipmember` in the query string and a JSON request body. The body accepts `mobile`, `cardid`, or `cloudId`; the returned `code` field may be null.

## Controllers

| Method | Local endpoint | Authorization | Vendor API |
|---|---|---|---|
| `GET` | `/api/v1/app/liansheng/store` | `ROLE_USER` | 0102 |
| `GET` | `/api/v1/app/liansheng/member?mobile=...` | `ROLE_USER` | 0701 |
| `GET` | `/api/v1/manage/liansheng/business-revenue?beginDate=YYYY-MM-DD&endDate=YYYY-MM-DD` | `ROLE_ADMIN` | 0504 |

Controllers own local request validation, response envelopes, authorization, and mapping vendor failures to HTTP 502. The 0102 token ID is internal and is removed from the store response.

## Payment Gateway

`LianshengPointGateway` implements `PaymentGatewayInterface` in the Liansheng module and is auto-registered as `payment.gateway`.

| Contract | Value |
|---|---|
| Payment name | `liansheng_point` |
| Required invoice currency | `LIANSHENG_POINT` (input `liansheng_point` is normalized by `Invoice`) |
| Amount unit | One integer amount unit equals one Liansheng point; it is not divided by 100 |
| Member identity | The invoice payer's verified phone number; request options cannot override it |
| Provider reference | Stable invoice `outTradeNo`, sent as both `accno` and `billno` |
| Completion | Synchronous: one 0703 call returning vendor success produces `PaymentResult(status=paid)` |
| Retry | The gateway performs no internal retry |
| Notify | Unsupported because payment completes synchronously |
| Refund | Synchronous 0703 credit with `dirflag=+`; full and partial refunds are supported |

Payment is initiated through the existing endpoint:

```text
POST /api/v1/app/invoices/{id}/pay/liansheng_point
```

The invoice must already use currency `LIANSHENG_POINT`, have a positive integer amount, and belong to a payer with a verified phone. A provider error or ambiguous transport failure is propagated and never converted to a paid result. Reusing `outTradeNo` gives the provider a stable reference, but exactly-once behavior across an unknown network outcome still depends on provider-side deduplication of `accno`/`billno`.

Refunds call 0703 with `dirflag=+`, `creditscore` set to the refunded points, and `debitscore=0`. The reference is `outTradeNo-R{cumulativeRefundedPoints}`, so a partial refund and a later full balance refund are distinct provider operations. Restored points use `LIANSHENG_POINT_REFUND_EXPIRY_DATE` (`2099-12-31` by default); set it to the vendor-approved `YYYY-MM-DD` expiry policy in deployment configuration.

Live tests using exactly the published 0703 JSON fields are currently rejected by the provider with `未能匹配 CRMFACTORY 参数类型！`. Adding the undocumented `crmfactory` field from the 0102 response, in the body/query/header, does not change that response. The adapter therefore follows the published request contract; the test tenant requires a vendor-side CRM Factory configuration fix before 0703 can execute.

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
