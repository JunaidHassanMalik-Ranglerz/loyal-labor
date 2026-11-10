# LOYLABOR — PayFast Laravel Sandbox Safety & Readiness Audit

**Auditor role:** Principal Laravel / Payment / Security audit (read-only)  
**Date:** 9 Nov 2026  
**Backend Base URL:** https://ranglerz.click/homeservices/api  
**Docs:** https://gopayfast.com/docs  
**Scope:** Technician subscription PayFast integration only  
**Method:** Static code inspection + authorized read-only live `.env`/file inspection + non-mutating public route probes  
**Mutations:** None (no payments initiated, no DB/code/credential changes)

---

## FINAL VERDICT

### **BACKEND READINESS BLOCKED — MISSING CONFIGURATION OR EVIDENCE**

### Real-money safety answer

**CAN WE SAFELY PERFORM A SANDBOX TEST WITHOUT REAL MONEY?**

### **UNKNOWN — TESTING BLOCKED**

**Why not YES**
- Live `PAYFAST_MERCHANT_ID` = **EMPTY**
- Live `PAYFAST_SECURED_KEY` = **EMPTY**
- Therefore no authoritative proof that a filled merchant is a **test** merchant
- No provider-approved test card/OTP instructions verified from merchant portal during this audit
- No automated gateway mock suite executed (0 payment gateway tests found)

**Why not NO (production risk)**
- Live `PAYFAST_MODE=sandbox` (verified)
- Runtime hard-block: `assertSandboxOnly()` refuses live mode
- Sandbox hosts default to `ipguat.apps.net.pk` (UAT)
- Missing credentials cause safe failure (exception), not production fallback
- No payment initiation was performed in this audit

---

## A. Environment Evidence

| Configuration | Expected | Verified | Evidence |
|---|---|---|---|
| Deployed PayFast routes | Present on live | **PASS** | Live `routes/api.php` contains initiate/verify/show/return/ipn/checkout |
| Deployed PayFast controller/service | Present | **PASS** | Live files exist; contracts include `webview_url`, `environment`, `payment_id` |
| `PAYFAST_MODE` | `sandbox` | **PASS** | Live `.env`: `PAYFAST_MODE=sandbox` |
| `PAYFAST_MERCHANT_ID` exists | Set (sandbox merchant) | **FAIL / EMPTY** | Live key present but value empty |
| `PAYFAST_SECURED_KEY` exists | Set (sandbox key) | **FAIL / EMPTY** | Live key present but value empty |
| Credentials are test merchant | Sandbox merchant only | **BLOCKED** | Cannot classify empty credentials; no merchant portal inspection authorized |
| Sandbox token URL | UAT GetAccessToken | **PASS (config)** | Live env: `https://ipguat.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken` |
| Sandbox checkout URL | UAT PostTransaction | **PASS (config)** | Live env: `https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction` |
| Status/API base | UAT Transaction API | **PASS (config)** | Live env: `https://ipguat.apps.net.pk/Ecommerce/api/Transaction` |
| Live hosts present in config | Documented but unused | **PASS w/ caveat** | `config/payfast.php` also defines `ipg1.apps.net.pk` for live mode |
| Silent production fallback | Must not occur | **PASS (code)** | `assertSandboxOnly()` throws if mode != sandbox; empty merchant/key throws |
| APP_ENV | n/a | Observed | Live `APP_ENV=production` (app host), PayFast still sandbox-mode |
| Git commit of deploy | Identified | **BLOCKED** | No deploy commit hash available via FTP inspection |

---

## B. Real-Money Safety Detail

### Architecture controls that reduce production risk
1. `PAYFAST_MODE` currently `sandbox`.
2. `PayFastPakistanService::assertSandboxOnly()` called on token, checkout field build, and status fetch.
3. Checkout URL resolution uses `config('payfast.' . mode() . '.*')` — with sandbox mode this resolves to `ipguat` URLs.
4. Empty credentials fail closed (no alternate live credential path).

### Residual risks / unknowns
1. **Credential class unknown** until sandbox merchant ID/key are provided and confirmed by PayFast as UAT.
2. Hostname `ipguat` is strong UAT signal, but audit rule forbids declaring money-safe from hostname alone.
3. Official gopayfast docs use `<BASE_URL>` placeholders; merchant-specific sandbox confirmation still required.
4. No verified provider test instrument list captured in this audit.
5. Callback authenticity relies primarily on **server-side status re-query by basket id**, not a documented HMAC/ITN signature verification of the raw callback body.

### Controlled test recommendation
Do **not** run any payment until:
1. Sandbox merchant credentials are filled.
2. PayFast confirms they are UAT/sandbox credentials.
3. PayFast provides approved test card/account/OTP method.
4. Then perform one controlled sandbox transaction only.

---

## C. API Readiness

Static/route inspection only. **No initiate calls against live with auth.**

| API | Verdict | Notes |
|---|---|---|
| `GET /subscriptions` | **PASS** | Live probe 200; public catalog |
| `POST /subscriptions/order-summary` | **PASS (static)** | Implemented; returns toast + `can_purchase` |
| `POST /payments/payfast/initiate` | **PARTIAL** | Auth + sandbox guards + amount from DB implemented; **cannot succeed until credentials set**. Unauth probe = 401 |
| `POST /payments/{id}/verify` | **PASS (static)** | Auth + ownership + gateway re-check + activation gate |
| `GET /payments/{id}` | **PASS (static)** | Auth + ownership + optional re-verify |
| `GET /profile` | **PASS (static)** | Returns lifecycle + verification flags |

### Contract / business-rule checks (static)

| Rule | Verdict | Evidence |
|---|---|---|
| Auth on initiate/verify/status | **PASS** | Sanctum group routes |
| Technician-only initiate | **PASS** | Controller role check |
| Docs must be verified | **PASS** | Initiate + activator |
| Server-calculated payable amount | **PASS** | `$plan->resolvedPayablePrice()` |
| Duplicate same-plan prevention | **PASS** | `hasUnexpiredPlan()` → HTTP 409 |
| Pending payment handling | **PASS** | statuses pending/processing |
| Unique order reference | **PASS** | unique DB + generator loop |
| Idempotent activation | **PASS** | `lockForUpdate` + `activated_at` guard |
| Frontend redirect alone activates | **PASS (safe)** | success URL calls verify; activation only after status success |

---

## D. Checkout & Callback Readiness

### Initiate response contract (code-verified)
Present in live controller:
- `data.payment_id`
- `data.checkout.webview_url`
- `data.checkout.type` = `form_post`
- `data.checkout.url` + `data.checkout.fields`
- `data.status`
- `data.environment`

### `webview_url`
- Format: `/api/payments/payfast/checkout/{order_reference}?token=...`
- HTTPS app URL (via `url(...)`)
- Temporary access token required in query string
- Token is short-lived PayFast access token (gateway expiry); URL becomes unusable when token expires / payment finalized
- Page auto form-POSTs to configured checkout URL (`PostTransaction`)

### Callback endpoints
- `/payments/payfast/return/success`
- `/payments/payfast/return/failure`
- `/payments/payfast/ipn`

### Verification model
1. Capture callback/return payload (basket id).
2. Re-query PayFast status by basket id with fresh access token.
3. Normalize success codes (`00` / success heuristics).
4. Mark paid, store `payfast_transaction_id`.
5. Activate subscription only if docs verified + amount matches plan payable.

### Gaps
- No dedicated unit/feature test suite for PayFast found (`*PayFast*Test*` = 0).
- Callback body signature validation (hashed parameters) not implemented as a first-line authenticity check.
- Amount comparison uses stored payment vs current plan payable at activation; provider-reported amount field is not independently asserted from status payload.

---

## E. Outstanding Issues

### Backend issues
1. Live merchant credentials empty → initiate cannot succeed.
2. Missing automated mock tests for token/checkout/callback/idempotency.
3. Callback authenticity mostly status-API based; strengthen with documented hash checks if PayFast merchant guide requires them.
4. Deployed git commit not identifiable via FTP.

### PayFast merchant / provider issues
1. Need confirmed sandbox Merchant ID + Secured Key.
2. Need official sandbox test instrument + OTP/3DS instructions.
3. Need confirmation which response field is the onboarding **Test Order ID**.

### Frontend dependencies
1. Must use initiate → WebView → verify/profile refresh flow.
2. Must not treat return URL as success.
3. Deep links optional; verify API mandatory.

---

## F. Test Order ID Requirements (investigation only)

| Question | Finding |
|---|---|
| LoyLabor internal reference | `subscription_payments.order_reference` (e.g. `LOY-XXXX-...`) — **not** the PayFast Test Order ID |
| Provider transaction id storage | `subscription_payments.payfast_transaction_id` |
| Likely Test Order ID candidate | PayFast `transaction_id` from status/callback after successful sandbox payment |
| Basket/order id | Same as internal `order_reference` sent as `BASKET_ID` |
| How to retrieve later | `GET /payments/{id}` → `transaction_reference`; or DB column `payfast_transaction_id` |
| Portal submission | Confirm with PayFast signup/onboarding UI which field they want (transaction id vs basket id) |
| Fabricated ID | **Not created** (forbidden) |

---

## G. Automated Verification Executed

| Check | Type | Result |
|---|---|---|
| Live env key presence/mode/URLs (redacted) | Read-only FTP | Executed |
| Live route/controller/service presence | Read-only FTP | Executed |
| Local file presence | Static | 6/6 present |
| `GET /subscriptions` | Non-mutating HTTP | 200 |
| `POST /payments/payfast/initiate` unauthenticated | Non-mutating HTTP | 401 |
| Payment initiation with auth | **Not run** (forbidden) | Skipped |
| Gateway token/checkout calls | **Not run** (forbidden) | Skipped |
| PHPUnit PayFast suite | None found | **0 tests executed** |

**Exact executed non-mutating probes:** 2 HTTP probes + static/live file inspections.  
**Gateway-mutating tests:** 0.

---

## H. Final Verdict (repeat)

**BACKEND READINESS BLOCKED — MISSING CONFIGURATION OR EVIDENCE**

Code is sandbox-oriented and hard-blocks live mode, but controlled sandbox testing is **not authorized by evidence** until sandbox merchant credentials and PayFast-approved test method are confirmed.

### Next safe steps (outside this audit)
1. Obtain sandbox `PAYFAST_MERCHANT_ID` + `PAYFAST_SECURED_KEY` from PayFast.
2. Confirm with PayFast Support they are UAT-only.
3. Obtain approved test card/account instructions.
4. Then request a separate authorization for one controlled sandbox transaction to capture real Test Order ID.

---

**STOP.** No payments were initiated. No secrets exposed. No code/DB/credential mutations performed.
