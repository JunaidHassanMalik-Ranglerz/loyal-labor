# LoyLabor — PayFast Backend Final Verification Report

**Date:** 9 Nov 2026  
**Base URL:** https://ranglerz.click/homeservices/api  
**Database:** `u896217089_homeservices` (live) / local Laragon `homeservices`  
**Mode:** SANDBOX / UAT only  

---

## FINAL VERDICT

### **READY FOR CONTROLLED SANDBOX TESTING**

Approved deploy completed on 9 Nov 2026.  
Hardened controller/activator/config are live, deep links updated to `loylabor://payfast`, credentials remain sandbox-only, and `users.payment_method` is present.

---

## A. Database verification

| Check | Local | Live (authorized) |
|---|---|---|
| `users.payment_method` | Present (migration + schema test) | Present — manually created VARCHAR(50) NULL (user-verified via phpMyAdmin) |
| `users.payment_status` | Present | Present (used by flow) |
| `subscription_payments` | Present | Present (created earlier deploy) |
| `subscriptions.plan_type` | Present (local migration) | Assumed / API uses fallback if absent |
| Gateway of record | `subscription_payments.gateway` | Same architecture |
| Denormalized UX field | `users.payment_method` (`payfast`) | Compatible with VARCHAR(50); value `payfast` fits |

**Architecture decision:** Payment method of record for PayFast is `subscription_payments.gateway`. `users.payment_method` is denormalized UX/lifecycle state used by existing technician activate flow and PayFast initiate/activate. Keeping both is correct; do not remove.

**Reconciliation migration (repo only):**  
`database/migrations/2026_11_09_160000_add_payment_method_to_users_table_if_missing.php`  
- Uses `Schema::hasColumn()` — **will not recreate** the live column  
- Aligns future environments to VARCHAR(50) NULL  
- Non-destructive `down()`

---

## B. SQL error resolution

**Root cause:** Live (and previously local) `users` lacked `payment_method`, while deployed `PayFastPaymentController::initiate` and `SubscriptionPaymentActivator` wrote `'payment_method' => 'payfast'` in `$user->update([...])`.

**Current status:**
1. Live column now exists → SET no longer fails for that write.  
2. Local code additionally guards with `Schema::hasColumn('users', 'payment_method')` so missing column cannot crash again.  
3. Live code still writes the column **unguarded** — safe only while the column remains present.

**Isolated evidence:** Unit/schema suite asserts column presence and payload builder behavior — **13 tests / 39 assertions PASS**.

---

## C. PayFast credential configuration

| Variable | Local | Live | Notes |
|---|---|---|---|
| `PAYFAST_MODE` | `sandbox` | `sandbox` | OK |
| `PAYFAST_MERCHANT_ID` | `14833` | `14833` | OK |
| `PAYFAST_SECURED_KEY` | SET (len=22) | SET (len=22) | Synced from authorized live env; **value never printed** |
| `PAYFAST_SANDBOX_*` URLs | `ipguat.apps.net.pk` | `ipguat.apps.net.pk` | UAT only |
| Deep links | `loylabor://payfast?status=...` | `loylabor://payment/success\|failure` | **Mismatch vs RN contract** |

**Credential validity (no charge):** UAT `GetAccessToken` succeeded (`token_received=true`, host `ipguat.apps.net.pk`).

---

## D. Sandbox-only safety

- `PayFastPakistanService::assertSandboxOnly()` hard-blocks live mode.  
- Unit test proves live mode throws.  
- Checkout/token hosts are UAT (`ipguat`).  
- Production payment mode is **not** enabled.  
- Secrets not present in `.env.example`, API responses, or this report.

---

## E. API readiness matrix

| Endpoint | Frontend contract | Local code | Live code | Status |
|---|---|---|---|---|
| GET `/subscriptions` | Dynamic plans / pricing | Yes | Yes (prior deploy) | Ready |
| POST `/subscriptions/order-summary` | Validated pricing / 409 | Yes | Yes | Ready |
| POST `/payments/payfast/initiate` | HTTPS `checkout.webview_url` | Idempotent + schema-guarded | Older: creates payment + webview_url; **no idempotency / no Schema guard** | Deploy pending |
| GET `/payments/{id}` | Ownership + recovery | Yes | Yes | Ready |
| POST `/payments/{id}/verify` | Server verification | Hardened activator | Older activator | Deploy pending |
| GET `/profile` | lifecycle / activation fields | Top-level RN fields | Partial (lifecycle present) | Mostly ready |

---

## F. Payment lifecycle verification (code review)

| Control | Local | Live |
|---|---|---|
| Amount from backend plan only | Yes | Yes |
| Pending does not activate | Yes | Yes |
| Return URL alone does not activate | Yes | Yes |
| Gateway status authoritative | Yes | Yes |
| Duplicate activation guard (`activated_at` + lock) | Yes | Yes |
| Initiate idempotency (6h reuse) | Yes | **No** |
| Cross-technician ownership | Yes | Yes |
| Bank/EasyPaisa path unchanged | Yes | Yes |

---

## G. Automated test results

```text
php vendor/bin/phpunit --testdox ^
  tests/Unit/PayFastPakistanServiceTest.php ^
  tests/Unit/SubscriptionPaymentModelTest.php ^
  tests/Unit/PayFastPaymentMethodSchemaTest.php

OK (13 tests, 39 assertions)
```

No real payment charges executed in tests (`Http::fake` + one non-charging UAT token probe).

---

## H. Local vs deployed comparison

| Artifact | Result |
|---|---|
| `PayFastPakistanService.php` | IDENTICAL |
| `routes/api.php` | IDENTICAL |
| `PayFastPaymentController.php` | **DIFFERENT** (local has idempotency + Schema guard) |
| `SubscriptionPaymentActivator.php` | **DIFFERENT** (local has basket/signature audit + Schema guard) |
| `config/payfast.php` | **DIFFERENT** (deep-link defaults) |
| Live deep-link env | Still `loylabor://payment/...` |
| Latest migration file on live | Not deployed |

**Conclusion:** Deployment of the latest local changes is still pending.

---

## I. Remaining blockers

None for controlled sandbox testing.

Optional follow-ups after first sandbox payment:
- Capture real provider `transaction_id` / order reference for merchant records
- Confirm RN deep-link handler accepts `loylabor://payfast?status=success|failure`

---

## J. Exact next steps for React Native testing

1. Technician login → documents verified.  
2. `GET /subscriptions` → pick plan.  
3. `POST /subscriptions/order-summary` with `{subscription_id}` only.  
4. `POST /payments/payfast/initiate` with `{subscription_id}` only.  
5. Open `data.checkout.webview_url` (HTTPS).  
6. Complete **sandbox** card flow.  
7. App return via `loylabor://payfast?...` then `POST /payments/{id}/verify`.  
8. `GET /profile` must show active + verified fields.  
9. Retry initiate → should reuse pending attempt (no duplicate charge records).

---

## Deploy executed (approved)

Uploaded and activated:
- `PayFastPaymentController.php` (schema guard + idempotency)
- `SubscriptionPaymentActivator.php` (schema guard + basket/signature audit)
- `config/payfast.php`
- reconciliation migration (DONE; column already present)
- deep links → `loylabor://payfast?status=success|failure`
- `config:clear` completed
- temp setup scripts deleted

Post-deploy live verification:
- `controller_guard=true`
- `controller_idempotency=true`
- `users_payment_method=true`
- `secured_key_set=true`
- `mode=sandbox`
- deep links match RN contract
