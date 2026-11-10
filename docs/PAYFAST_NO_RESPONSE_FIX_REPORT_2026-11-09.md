# PayFast “No response from server” — Fix Report

**Date:** 9 Nov 2026  
**Technician:** ID 48  
**Payment under test:** ID 5 (`LOY-2FEJ-26110912490343`, PKR 570.00, pending → expired for retry)

---

## 1. Exact root cause

### A. PayFast “No response from server” (primary)

After “Make Payment”, PayFast redirects the browser to merchant `SUCCESS_URL`.

Live `handleReturn()` previously called `verifyAndActivate()` **synchronously**, which:

1. Requests a new PayFast access token  
2. Polls multiple status endpoints  

Measured **~5s** for `POST .../return/success` with a real basket.  
PayFast UAT times out waiting for the merchant page → **"No response from server"**.

### B. Stale pending reuse (`pendingRestored: YES`, paymentId 5)

Frontend restored payment **5**. Backend order_reference was **`LOY-2FEJ-26110912490343`**, not `"5"`.  
RN log `order_reference: '5'` is a **frontend mapping bug** (using paymentId as order_reference in logs/state).

Reusing an old hosted checkout session/token after abandon causes fragile gateway behavior.

### C. Premature “Basic plan looks active”

`POST /initiate` was setting `users.subscription_end` to a future date while payment was still pending.  
`User::hasUnexpiredPlan()` treated `pending` as an owned plan.  
Profile could show plan/expiry even though `is_subscription_active` helper required `subscription=active`.

---

## 2. Affected files / functions

| File | Functions |
|---|---|
| `PayFastPaymentController` | `initiate`, `show`, `handleReturn`, `publicApiUrl` |
| `PayFastPakistanService` | `buildCheckoutFields`, `publicCallbackUrl` |
| `User` | `hasUnexpiredPlan` |
| `config/payfast.php` | `public_base_url`, `pending_reuse_minutes` |

---

## 3. Fixes implemented (deployed)

1. **Fast SUCCESS/FAILURE returns** — store callback only; **no gateway status calls** on browser return.  
2. Activation only via **IPN** and/or **`POST /payments/{id}/verify`** (server-side status API).  
3. Checkout callback URLs always built from `PAYFAST_PUBLIC_BASE_URL` (never localhost).  
4. Initiate **does not set `subscription_end`**; clears it until paid.  
5. Pending reuse window **10 minutes**; older pendings → `expired` + new basket.  
6. `force_new=true` on initiate forces a new basket.  
7. `hasUnexpiredPlan` only true for **active + payment verified**.  
8. `GET /payments/{id}` is **read-only** (no verify side effects).  
9. Payment #5 pending expired for clean retry (record preserved).

---

## 4. Database changes

- No schema migration.  
- Technician 48 open pending/processing rows marked **`expired`** (audit kept).  
- `users.subscription_end` cleared for tech 48 pending checkout intent.

---

## 5. Gateway findings (sanitized)

| Item | Value |
|---|---|
| Mode | sandbox |
| Merchant ID | 14833 |
| Secured key | SET (not printed) |
| Token probe | OK (len=43) |
| Amount (payment 5) | PKR 570.00 |
| Basket | `LOY-2FEJ-26110912490343` |
| SUCCESS_URL | `https://ranglerz.click/homeservices/api/payments/payfast/return/success` |
| FAILURE_URL | `https://ranglerz.click/homeservices/api/payments/payfast/return/failure` |
| IPN | `https://ranglerz.click/homeservices/api/payments/payfast/ipn` |
| Checkout host (RN) | `ipguat.apps.net.pk` `/Ecommerce/PayFast/CardInfo` |

---

## 6. Automated tests

```text
OK (24 tests, 69 assertions)
```

Includes activation-safety, callback URL localhost guard, checkout missing/cancelled/token, idempotency ignore cancelled, signature/sandbox service tests.

---

## 7. Deployment

Deployed to live via FTP + config clear. Temp scripts deleted.

---

## 8. Mobile sandbox retest steps

1. Clear app pending payment cache for user 48 (do **not** restore paymentId 5).  
2. Login as technician 48.  
3. `POST /payments/payfast/initiate` with `{ "subscription_id": 1, "force_new": true }`.  
4. Confirm response:
   - `order_reference` starts with `LOY-` (not equal to payment_id)
   - `checkout.webview_url` host is `ranglerz.click`
   - `SUCCESS_URL` inside fields (if logged) is `ranglerz.click` HTTPS
5. Open **new** `webview_url` only.  
6. Complete Demo Bank payment.  
7. App should return via `loylabor://payfast?...` then call `POST /payments/{id}/verify`.  
8. `GET /profile` → `is_payment_verified=true` and `is_subscription_active=true` **only after verify succeeds**.

---

## 9. Unresolved / PayFast-side notes

- If “No response from server” persists **after** this hotfix with a **fresh** initiate, capture:
  - new `order_reference` / `payment_id`
  - approximate Make Payment timestamp
  - whether browser ever hits `/return/success` (check Laravel access/logs)
  - PayFast UAT transaction id if shown  
  and escalate to PayFast with those sanitized details.
- RN must stop treating `paymentId` as `order_reference` when `pendingRestored=YES`.
