# Loyal Labor — PayFast Backend Verification Report

**Date:** 9 Nov 2026  
**Base URL:** https://ranglerz.click/homeservices/api  
**Mode target:** SANDBOX  
**Deploy:** Not performed in this pass (explicit approval required)

---

## A. Environment Configuration

| Variable name | Purpose | Local status | Live status (last audit) |
|---|---|---|---|
| `PAYFAST_MODE` | sandbox/live switch | configured: `sandbox` | configured: `sandbox` |
| `PAYFAST_MERCHANT_ID` | Merchant identity | configured: `14833` | was empty; needs deploy of value |
| `PAYFAST_SECURED_KEY` | Gateway secret | **MISSING / BLOCKER** | **EMPTY / BLOCKER** |
| `PAYFAST_MERCHANT_NAME` | Checkout display + signature | configured | configured |
| `PAYFAST_SANDBOX_TOKEN_URL` | UAT token endpoint | configured (`ipguat`) | configured |
| `PAYFAST_SANDBOX_CHECKOUT_URL` | UAT PostTransaction | configured (`ipguat`) | configured |
| `PAYFAST_SANDBOX_API_BASE_URL` | UAT status API base | configured | configured |
| `PAYFAST_SUCCESS_PATH` | HTTPS return success | configured | configured |
| `PAYFAST_FAILURE_PATH` | HTTPS return failure | configured | configured |
| `PAYFAST_IPN_PATH` | Notification callback | configured | configured |
| `PAYFAST_APP_SUCCESS_DEEP_LINK` | Mobile return | `loylabor://payfast?status=success` | needs update on deploy |
| `PAYFAST_APP_FAILURE_DEEP_LINK` | Mobile return | `loylabor://payfast?status=failure` | needs update on deploy |

**Secret values are never printed.**

### Blocker
`PAYFAST_SECURED_KEY` was **not found** in authorized project configuration, chat history, or agent stores.  
Merchant ID `14833` was applied locally. Secured key must be supplied before sandbox initiation can succeed.

---

## B. Backend Implementation

### Already implemented
- PayFast service (`PayFastPakistanService`) with sandbox hard-block
- Initiate / verify / status / IPN / return / hosted checkout page
- `subscription_payments` table + model
- Activation only after gateway status success
- Profile lifecycle fields
- Same-plan rebuy restrictions
- Server-side payable amount from plan pricing

### Changed in this pass
1. Local `.env`: set `PAYFAST_MERCHANT_ID=14833`, deep links → `loylabor://payfast?...`
2. `config/payfast.php` + `.env.example` deep-link defaults updated
3. Initiate idempotency: reuse open pending/processing payment for same technician+plan (6h window)
4. Profile top-level RN compatibility fields:
   - `is_payment_verified`
   - `is_subscription_active`
   - `payment_status` (`paid` when verified)
   - `activated_at`
   - `expires_at`
5. Activator: basket_id mismatch guard + optional signature mismatch audit note
6. Added mocked unit tests

### Not changed
- Production mode not enabled
- No real gateway calls
- No deploy (pending approval + secured key)

---

## C. API Contract Verification

| Endpoint | Frontend Compatibility | Status |
|---|---|---|
| GET /subscriptions | Dynamic plans + original/discount/payable | PASS |
| POST /subscriptions/order-summary | Validated pricing + can_purchase + toast | PASS |
| POST /payments/payfast/initiate | HTTPS `checkout.webview_url` + form_post fields | PASS (code); runtime blocked without secured key |
| POST /payments/{id}/verify | Server verification / no client success trust | PASS |
| GET /payments/{id} | Payment recovery + ownership | PASS |
| GET /profile | Lifecycle + activation truth fields | PASS (updated) |

Initiate response includes:
- `data.payment_id`
- `data.checkout.type = form_post`
- `data.checkout.url`
- `data.checkout.fields`
- `data.checkout.webview_url`
- `data.status`
- `data.environment`

---

## D. Security Verification

| Control | Status |
|---|---|
| Sanctum auth on initiate/verify/status | PASS |
| Technician ownership checks | PASS |
| Amount from backend plan payable only | PASS |
| Duplicate same-plan purchase → 409 | PASS |
| Initiate idempotency (reuse open attempt) | PASS (added) |
| Live mode hard-block (`assertSandboxOnly`) | PASS |
| Missing credentials fail closed | PASS |
| Activation only after gateway status success | PASS |
| Success URL alone does not activate | PASS |
| Duplicate callback activation guard (`activated_at` + lock) | PASS |
| Cross-technician payment access blocked | PASS (code) |
| Secrets not in API responses | PASS |
| Basket id mismatch rejection | PASS (added) |

---

## E. Test Results

Command:

```bash
php vendor/bin/phpunit --testdox tests/Unit/PayFastPakistanServiceTest.php tests/Unit/SubscriptionPaymentModelTest.php
```

**Result: OK (10 tests, 30 assertions) — all passing**

| Test | Result |
|---|---|
| Sandbox mode is default and live is blocked | PASS |
| Missing secured key fails closed | PASS |
| Missing merchant id fails closed | PASS |
| Signature matches documented formula | PASS |
| Checkout fields use sandbox host and hide no secured key | PASS |
| Normalize status detects success and failure | PASS |
| Get access token uses sandbox endpoint with mock | PASS |
| Live host is not used while sandbox mode enabled | PASS |
| Paid and final helpers | PASS |
| Api array exposes frontend contract keys | PASS |

No real PayFast network charges (`Http::fake`). Feature/HTTP integration coverage for initiate/verify/IPN remains unit-mocked at service layer; end-to-end sandbox still requires the secured key.

---

## F. Sandbox Readiness

### **PARTIALLY READY** (blocked on credentials)

**Ready**
- Code path, routes, contracts, sandbox hosts, hard live-block, profile/verify/idempotency
- Unit tests green (10/10)

**Blocked for controlled sandbox testing**
- `PAYFAST_SECURED_KEY` missing in authorized environment (local + live)
- Live server still needs secured key + deploy of this pass’s code updates
- No real Test Order ID yet (forbidden to fabricate)

### Next actions
1. Provide `PAYFAST_SECURED_KEY` securely.
2. Approve deploy of updated files + set key on live `.env`.
3. Run `php artisan config:clear` on target after env update.
4. Perform one controlled sandbox payment to capture provider `transaction_id`.
