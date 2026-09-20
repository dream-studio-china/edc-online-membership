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
| `registerMemberByMobile()` | 0702 `POST /api/vip.api?method=addvip` | Registers a missing member with zero points and balances. |
| `getMemberCardTypes()` | `GET /api/wx.api?method=getvipcardtype&isamount=F` | Gets Store-specific member card types; use each item's `id` as 0702 `cardtypeId`. |
| `getMemberScoreBook()` | 0704 `GET /api/wx.api?method=getscorebook` | Gets the paginated member points ledger by mobile or vipId. |
| `deductMemberPoints()` | 0703 `POST /api/vip.api?method=vipsubscore` | Deducts a positive integer number of member points synchronously. |
| `creditMemberPoints()` | 0703 `POST /api/vip.api?method=vipsubscore` | Credits a positive integer number of member points synchronously. |

The store token is held in `cache.app` until one minute before the vendor-reported expiry. No token, credential, member data, or report data is stored in Doctrine.

Live testing shows that 0701 requires `method=getvipmember` in the query string and a JSON request body. The body accepts `mobile`, `cardid`, or `cloudId`; no matching member returns `code=501`, `msg=没有匹配到会员资料！`, and `data=null`, which the adapter maps to an empty list. The local member endpoint registers an empty 0701 result through 0702 and immediately returns its successful registration response. Registration uses the mobile as the vendor `name` and `alias`, with zero initial score and balances. 0702 requires a Store-specific `memberCardTypeId`.

## Controllers

| Method | Local endpoint | Authorization | Vendor API |
|---|---|---|---|
| `GET` | `/api/v1/app/liansheng/store` | `ROLE_USER` | 0102 |
| `GET` | `/api/v1/app/liansheng/member?mobile=...` | `ROLE_USER` | 0701 |
| `GET` | `/api/v1/app/liansheng/member-card-types` | `ROLE_USER` | getvipcardtype |
| `GET` | `/api/v1/app/liansheng/member-scorebook?mobile=...` or `?vipId=...` | `ROLE_USER` | 0704 |
| `GET` | `/api/v1/manage/liansheng/business-revenue?beginDate=YYYY-MM-DD&endDate=YYYY-MM-DD` | `ROLE_ADMIN` | 0504 |
| `POST` | `/api/v1/manage/liansheng/member-points` | `ROLE_ADMIN` | 0703 |

Controllers own local request validation, response envelopes, authorization, and mapping vendor failures to HTTP 502. The 0102 token ID is internal and is removed from the store response.

Every Liansheng endpoint requires `X-Store-Code`; it selects the Store-specific Liansheng configuration and token. The Manage 0703 endpoint accepts `mobile`, `dirflag` (`-`/`+`), positive integer `points`, stable `reference`, non-empty `roomtable`, and non-empty `remarks`. It derives the mutually exclusive `creditscore`/`debitscore` fields and delegates to the same Service used by the payment Gateway.

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

Refunds call 0703 with `dirflag=+`, `creditscore` set to the refunded points, and `debitscore=0`. The reference is `outTradeNo-R{cumulativeRefundedPoints}`, so a partial refund and a later full balance refund are distinct provider operations. Restored points use `settings.liansheng.pointRefundExpiryDate` (`2099-12-31` by default); set it to the vendor-approved `YYYY-MM-DD` expiry policy for the Store.

The local test configuration uses `http://test.lincson.cn/web`. It completes 0102, 0701, 0703, and 0704 with the supplied test application, including a verified 10-point deduction and matching credit. This HTTP host is suitable only for local testing because credentials and tokens are transmitted in plaintext; production requires a vendor-provided HTTPS endpoint whose 0703 CRM Factory is configured for the Store.

0703 sends every documented field on every adjustment. `creditscore` and `debitscore` are both present, with the inactive direction set to `0`; `roomtable` is the non-empty `ONLINE` default and `remarks` falls back to `Liansheng points adjustment` when the caller provides none.

## Configuration

Liansheng is configured per Store. Every Liansheng controller or payment request must include `X-Store-Code`; the active Store's `settings` provide its connection and credentials:

```json
{
  "liansheng": {
    "baseUrl": "http://test.lincson.cn/web",
    "appCode": "...",
    "appSecret": "...",
    "userId": "1",
    "pointRefundExpiryDate": "2099-12-31",
    "memberCardTypeId": "..."
  }
}
```

`LianshengApiException` represents transport, HTTP, invalid JSON, and vendor-level response failures.

`https://test.lincson.cn/web` has a certificate mismatch and its HTTPS `/web/api/*` path rejects POST with 405. The local test Store intentionally uses the vendor's HTTP endpoint instead; do not use this plaintext endpoint outside local testing.
