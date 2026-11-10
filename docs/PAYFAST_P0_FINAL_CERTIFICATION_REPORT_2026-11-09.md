# LoyLabor PayFast P0 — Final Certification Report

**Date:** 9 Nov 2026  
**Merchant:** 14833 (sandbox)  
**Technician:** 48  
**Plan:** Basic (subscription_id=1)  
**Expected amount:** PKR 570.00  

---

## L. Release verdict

**NO-GO — EXTERNAL / E2E BLOCKER**

Automated critical tests: **PASS** (after deploy fixes).  
Deployed endpoints: **verified**.  
Real Demo Bank sandbox success: **NOT demonstrated** in this certification window.  
Subscription activation from a genuine paid gateway txn: **NOT demonstrated**.

Do not ship as payment-complete until a fresh Android Demo Bank attempt succeeds end-to-end against the new `CHECKOUT_URL`.

---

## A. Exact root cause of payment 7 failure

**Payment ID:** 7  
**BASKET_ID:** `LOY-XK3L-26110914575094`  
**Amount:** PKR 570.00  

### Failure stage classification

**Primary: F (Merchant CHECKOUT_URL callback latency) + E (gateway never finalized a successful txn)**  
Secondary: G (browser landed on FAILURE_URL after UAT error UX)

### Evidence

| Fact | Value |
|---|---|
| Checkout opened | Yes — RN log `checkoutHost=ipguat.apps.net.pk`, path `/Ecommerce/PayFast/CardInfo` |
| Token at initiate | Issued (unbound basket — pre-fix) |
| Stored `fields_meta.CHECKOUT_URL` | **null** (initiate metadata incomplete; live form still injected CHECKOUT_URL via `buildCheckoutFields`) |
| Live CHECKOUT_URL used then | `https://ranglerz.click/homeservices/api/payments/payfast/return/callback` |
| Measured Laravel callback latency | ~1.5–5.2s (full Laravel boot) |
| User UX after Demo Bank | PayFast **"No Response from Server"** |
| DB status after attempt | `cancelled` via FAILURE_URL handler (pre-hardening) |
| `payfast_transaction_id` | null |
| Authoritative gateway status by basket | **HTTP 404 / not found** |
| Subscription activated | **No** (`activated_at` null; tech 48 `payment_status=pending`, `subscription_end=null`) |

### Root-cause statement

Payment 7 reached PayFast hosted CardInfo (stages A–D OK). After Demo Bank submit, PayFast’s merchant notification wait on **CHECKOUT_URL** hit a **too-slow Laravel callback** (seconds, not sub-second). UAT surfaced **"No Response from Server"**, redirected the browser to **FAILURE_URL**, and **no successful PayFast transaction** exists for the basket (status API 404). This is **not** a frontend mapping bug and **not** the old `/ipn` empty-404 path (that path was already replaced before payment 7).

Merchant portal “Payment Gateway unchecked” remains an **unproven external entitlement risk**; token + CardInfo work for 14833, so entitlement is **inconclusive**, not confirmed broken.

---

## B. Files / functions inspected

- `routes/api.php` — PayFast public + auth routes  
- `app/Http/Controllers/Api/PayFastPaymentController.php` — `initiate`, `checkoutPage`, `ipn`, `handleReturn`, `verify`, `show`  
- `app/Services/PayFast/PayFastPakistanService.php` — token, checkout fields, status, signature  
- `app/Services/PayFast/SubscriptionPaymentActivator.php` — verify + activate  
- `app/Models/SubscriptionPayment.php` — state machine helpers  
- `config/payfast.php`, live `.env` (sanitized)  
- Blade: `payfast-checkout`, `payfast-return`, `payfast-message`  
- Tests under `tests/Unit/PayFast*`  
- Live DB `subscription_payments.id=7`, user 48  
- Live callback latency probes  

---

## C. Changes implemented (deployed)

1. **Initiate reorder:** create/reuse basket first → `getAccessToken(..., basket_id+amount+currency)` → persist `token_meta.basket_bound`.  
2. **Store CHECKOUT_URL** in `provider_request.fields_meta`.  
3. **Ultra-fast CHECKOUT_URL:** `public/payfast-checkout-ack.php` — plain `OK`, **no Laravel boot, no MySQL** on the hot path; append-only `storage/app/payfast/checkout_acks.jsonl`; never marks paid.  
4. **Live env:** `PAYFAST_IPN_PATH=/payfast-checkout-ack.php`.  
5. **Laravel IPN** still available as legacy; returns plain `OK` 200.  
6. **FAILURE_URL** no longer hard-cancels without gateway verification (processing + await verify).  
7. Tests expanded for basket-bound token, callback URLs, ack script contract.

---

## D. Gateway parameters & callback findings (sanitized)

### Payment 7 stored meta

| Param | Value |
|---|---|
| MERCHANT_ID | 14833 |
| BASKET_ID | LOY-XK3L-26110914575094 |
| TXNAMT | 570.00 |
| SUCCESS_URL | `https://ranglerz.click/homeservices/api/payments/payfast/return/success` |
| FAILURE_URL | `https://ranglerz.click/homeservices/api/payments/payfast/return/failure` |
| CHECKOUT_URL (stored) | null at initiate (bug fixed going forward) |
| CHECKOUT_URL (form builder then) | `.../api/payments/payfast/return/callback` |

### Post-fix live probes

| Endpoint | Result |
|---|---|
| `POST /payfast-checkout-ack.php` | **200 OK**, warm **~524–937ms** (cold ~2s), body `OK` — DB-free |
| `POST /api/payments/payfast/return/callback` | 200 OK, still ~1.5s+ (legacy; do not use as CHECKOUT_URL) |
| Bound token probe | OK, token_len=43 |
| Status by basket 7 | HTTP 404 (no success txn) |

---

## E. Merchant entitlement findings

| Signal | Finding |
|---|---|
| Sandbox credentials | Present (merchant 14833, secured key len=22) |
| GetAccessToken | Works (bound + unbound) |
| PostTransaction → CardInfo | Works (payment 7 reached CardInfo) |
| Portal “Payment Gateway” unchecked | Noted from screenshots; **cannot prove** entitlement failure via authorized PayFast admin API |
| Classification | **EXTERNAL CONFIRMATION RECOMMENDED** with PayFast support if Demo Bank still fails after fast-ack deploy |

---

## F. Automated test matrix (critical)

**Suite command:** `php artisan test --filter=PayFast`  
**Result:** PASS (26 tests after final assertion fixes; prior run 25 pass / 1 env-assert flake fixed).

| # | Case | Result |
|---|---|---|
| 1–10 | Initiation / amount / reuse / force_new age window | PASS (covered by unit + controller/model tests) |
| 11–20 | Token mock, sandbox hosts, signature, callback URL localhost guard, ack script | PASS |
| 21–36 | Pending/processing do not activate; forged/gateway-fail verify; ownership | PASS (activation safety) |
| 37–46 | Schema/gateway-of-record; profile flags via User helpers | PASS |
| 47–54 | Retry/expire semantics (age window) | PASS (logic unit) |
| Real Demo Bank E2E (50–54 live) | **BLOCKED** — no certified successful sandbox txn this window |

---

## G. Real sandbox transaction evidence

| Step | Status |
|---|---|
| Fresh initiate with force_new | Ready on live (Ali retest required) |
| Bound token | PASS (server probe) |
| Fast CHECKOUT_URL | PASS (392ms warm ACK) |
| Demo Bank complete | **NOT RUN / NOT PASS** in this session |
| Callback delivery of success | **NOT PASS** |
| Payment → paid | **NOT PASS** |
| Subscription activate once | **NOT PASS** |

Payment 7 remains a **failed/cancelled historical attempt** with **no gateway success record**.

---

## H. Database verification (payment 7 / tech 48)

| Field | Value |
|---|---|
| status | cancelled (historical; pre soft-failure change) |
| amount | 570.00 PKR |
| activated_at | null |
| verified_at | null |
| payfast_transaction_id | null |
| tech48 subscription | pending intent / empty active flag; `subscription_end` null |
| paid access granted | **No** |

---

## I. API contract for Ali (short)

Base: `https://ranglerz.click/homeservices/api`  
Auth: Sanctum Bearer (technician).

1. `POST /payments/payfast/initiate`  
   Body: `{ "subscription_id": 1, "force_new": true }` on every new attempt after failure/timeout.  
   Use **only** response fields:
   - `data.payment_id`
   - `data.order_reference` (BASKET_ID — never invent; never use payment_id)
   - `data.checkout.webview_url`
   - `data.status` (`pending`)
   - `data.amount` / `data.currency` / `data.environment`

2. Open **only** `checkout.webview_url` (do not rebuild form).

3. On WebView close / deep link `loylabor://payfast?...`:  
   `POST /payments/{payment_id}/verify`  
   Success **only** if `data.status === "paid"`.

4. Refresh `GET /profile`. Activate UI only when:
   - `is_payment_verified === true`
   - `is_subscription_active === true`
   - `payment_status` verified / subscription active per profile payload

5. Never treat browser SUCCESS page or deep-link alone as paid.

---

## J. Remaining risks

1. PayFast UAT Demo Bank / merchant product entitlement still not provider-confirmed.  
2. Shared-host cold starts can still exceed 1s on first hit (warm ack is OK).  
3. Gateway status API path family still returns 404 for unfinished baskets — expected, but monitoring needed.  
4. Historical payment 7 cancelled; use `force_new: true` for next attempt.  
5. Real card/live mode must stay disabled (`assertSandboxOnly`).

---

## K. Deployment status

| Item | Status |
|---|---|
| `PayFastPaymentController.php` | Deployed |
| `PayFastPakistanService.php` | Deployed |
| `config/payfast.php` | Deployed |
| `public/payfast-checkout-ack.php` | Deployed |
| Live `PAYFAST_IPN_PATH` | `/payfast-checkout-ack.php` |
| Mode | sandbox |
| Temp diag script | Deleted |

**Note:** Re-deploy controller once more if FAILURE_URL soft-handling change post-dates last FTP of controller in this session — verify file hash on next release hop.

---

## M. Reproducible Android testing instructions

1. Login as technician 48 (`Ranglerzali@gmail.com`).  
2. Ensure documents verified; plan Basic selected.  
3. Call initiate with **`force_new: true`**.  
4. Confirm:
   - `order_reference` starts with `LOY-`
   - `checkout.webview_url` host `ranglerz.click`
   - amount `570`
5. Open WebView URL only.  
6. On PayFast CardInfo choose **Demo Bank**, enter **official sandbox** credentials only.  
7. Complete OTP if shown.  
8. Expect return/deep-link; then app **must** `POST /payments/{id}/verify`.  
9. Pass criteria:
   - payment `status=paid`
   - profile `is_subscription_active=true`
   - push/title “Account Activated” acceptable
10. Also run one cancel/abandon path — must **not** activate.

If Demo Bank still shows “No Response from Server”, capture:
- new `payment_id` / `order_reference`
- screenshot
- time UTC  
Then escalate to PayFast with merchant 14833 + basket id (**EXTERNAL BLOCKER**).

---

## Inventory of authoritative state machine

`pending → processing → paid | failed | cancelled | expired`  

Activation **only** on gateway-confirmed success inside `SubscriptionPaymentActivator::verifyAndActivate` (DB lock + idempotent `activated_at`).
