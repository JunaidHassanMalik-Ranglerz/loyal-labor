# LoyLabor — PayFast Pakistan Sandbox Integration Report

**Environment:** SANDBOX ONLY  
**Provider:** Premier PayFast Pakistan (`gopayfast.com` / `apps.net.pk`)  
**Scope:** Technician subscription plan purchases only  
**Status:** Implementation complete — sandbox credentials + live transaction test pending  

---

## A. Existing Architecture (verified)

### Subscription lifecycle (before PayFast)

1. `GET /api/subscriptions` — list active plans (`Subscription::toApiArray()`).
2. `POST /api/subscriptions/order-summary` — pricing breakdown for selected plan.
3. `POST /api/technician/activate-subscription` — previously could mark payment verified immediately; now reserved for **manual** bank/EasyPaisa submissions (`payment_status=pending` until admin verifies).
4. `GET /api/profile` — returns `subscription_plan` + verification flags.

### Key files / tables

| Area | Location |
|---|---|
| Plans model | `app/Models/Subscription.php` |
| Technician user flags | `users.subscription_id`, `users.subscription`, `users.payment_status`, `users.subscription_end` |
| Activate / change plan | `app/Http/Controllers/Api/TechnicianController.php` |
| Profile mapping | `app/Http/Controllers/Api/AuthController.php` |
| Existing ecommerce payments | `Modules/Order`, `Modules/BasicPayment` (unrelated; **not modified**) |

### Where PayFast plugs in

```
Select plan → POST /payments/payfast/initiate
  → pending SubscriptionPayment + pending user payment flags
  → RN WebView form POST to PayFast UAT PostTransaction
  → SUCCESS/FAILURE/IPN return URLs
  → backend verifies via PayFast status API
  → only then: payment_status=verified, subscription=active
```

---

## B. Implementation

### New files

- `config/payfast.php`
- `database/migrations/2026_11_06_190000_create_subscription_payments_table.php`
- `app/Models/SubscriptionPayment.php`
- `app/Services/PayFast/PayFastPakistanService.php`
- `app/Services/PayFast/SubscriptionPaymentActivator.php`
- `app/Http/Controllers/Api/PayFastPaymentController.php`
- `resources/views/payments/payfast-checkout.blade.php`
- `resources/views/payments/payfast-return.blade.php`
- `docs/PAYFAST_SANDBOX_INTEGRATION.md`

### Modified files

- `routes/api.php`
- `app/Http/Controllers/Api/TechnicianController.php` (manual activate stays pending; PayFast redirected to initiate)
- `app/Http/Controllers/Api/AuthController.php` (authoritative lifecycle fields)
- `.env.example`

### Payment state machine

`pending → processing → paid`  
also: `failed` | `cancelled` | `expired`

Idempotency: `order_reference` unique; activation uses row lock + `activated_at` guard.

---

## C. Configuration (sandbox)

Add to `.env` (never commit secrets):

```env
PAYFAST_MODE=sandbox
PAYFAST_MERCHANT_ID=
PAYFAST_MERCHANT_NAME=LoyLabor
PAYFAST_SECURED_KEY=
PAYFAST_GRANT_TYPE=client_credentials
PAYFAST_CURRENCY=PKR
PAYFAST_SANDBOX_TOKEN_URL=https://ipguat.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken
PAYFAST_SANDBOX_CHECKOUT_URL=https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction
PAYFAST_SANDBOX_API_BASE_URL=https://ipguat.apps.net.pk/Ecommerce/api/Transaction
PAYFAST_SUCCESS_PATH=/api/payments/payfast/return/success
PAYFAST_FAILURE_PATH=/api/payments/payfast/return/failure
PAYFAST_IPN_PATH=/api/payments/payfast/ipn
PAYFAST_APP_SUCCESS_DEEP_LINK=loylabor://payment/success
PAYFAST_APP_FAILURE_DEEP_LINK=loylabor://payment/failure
```

**Missing until merchant provides sandbox credentials:**  
`PAYFAST_MERCHANT_ID`, `PAYFAST_SECURED_KEY` (and confirm merchant display name).

Live mode is hard-blocked in service (`assertSandboxOnly()`).

Migration:

```bash
php artisan migrate --path=database/migrations/2026_11_06_190000_create_subscription_payments_table.php
```

---

## D. API Documentation (for Ali / React Native)

### 1) Initiate payment (auth technician)

`POST /api/payments/payfast/initiate`  
Header: `Authorization: Bearer {token}`

```json
{ "subscription_id": 1 }
```

Success (shape):

```json
{
  "success": true,
  "message": "PayFast sandbox checkout ready...",
  "toast_message": "Redirecting to secure PayFast checkout.",
  "data": {
    "payment_id": 125,
    "order_reference": "LOY-XXXX-...",
    "subscription_id": 1,
    "amount": 570,
    "currency": "PKR",
    "status": "pending",
    "checkout": {
      "type": "form_post",
      "method": "POST",
      "url": "https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction",
      "fields": { "MERCHANT_ID": "...", "TOKEN": "...", "TXNAMT": "...", "BASKET_ID": "...", "...": "..." },
      "webview_url": "https://.../api/payments/payfast/checkout/{order_reference}?token=..."
    },
    "status_url": "https://.../api/payments/125"
  }
}
```

RN recommendation: open `checkout.webview_url` in WebView (auto form POST). Amount always comes from backend DB payable price.

### 2) Poll / verify status (auth)

- `GET /api/payments/{payment_id}`
- `POST /api/payments/{payment_id}/verify`

Backend re-checks PayFast; activates only on verified success.

### 3) Gateway callbacks (public)

- `GET|POST /api/payments/payfast/return/success`
- `GET|POST /api/payments/payfast/return/failure`
- `GET|POST /api/payments/payfast/ipn`

### 4) Profile fields after payment

`GET /api/profile`

New/clarified fields:

- `subscription_lifecycle_status`: `none | payment_pending | active | expired | inactive`
- `subscription_plan.status`: `pending | active | expired | inactive`
- `subscription_plan.payment_status`: `pending | verified`
- `subscription_plan.is_payment_verified`, `is_subscription_active`
- `verifications.subscription`: `verified` only when subscription is truly active

### Error codes

| HTTP | Code / meaning |
|---|---|
| 403 | Not technician / docs not verified |
| 409 | `plan_already_active` same plan still valid |
| 422 | PayFast via old activate endpoint |
| 502 | Gateway token/init/verify failure |

---

## E. Testing Evidence

| Case | Result |
|---|---|
| Code + migration authored | Done |
| Config sandbox defaults | Done |
| Live merchant credentials in repo | **Not present (correct)** |
| Successful sandbox card payment | **Blocked — merchant sandbox ID/key not provided** |
| Real PayFast Test Order ID | **Not generated yet** |
| Duplicate IPN / idempotent activation | Implemented (needs sandbox replay test) |
| Failed/cancelled return URLs | Implemented (needs sandbox run) |

**Blocker for Phase 8 evidence:** authorized sandbox `MERCHANT_ID` + `SECURED_KEY` from PayFast merchant portal (sandbox), then run one end-to-end WebView payment and capture real `transaction_id` / order reference.

---

## F. Deployment Readiness

1. Add sandbox env vars.
2. Run migration on target DB.
3. Deploy new/changed PHP/views/routes (separate approval required — not auto-deployed by this task).
4. Point PayFast SUCCESS/FAILURE/IPN paths to public HTTPS app URL.
5. RN: switch subscription purchase to `/payments/payfast/initiate` + status poll.
6. Do **not** set `PAYFAST_MODE=live` without explicit written authorization.

### Rollback

- Remove PayFast routes/controller/services.
- Drop `subscription_payments` table (migration down).
- Manual activate endpoint remains for bank transfer flow.

---

## STOP

Sandbox integration code is ready.  
No production payments enabled.  
No fabricated transaction IDs.  
Awaiting sandbox credentials + approved deploy before generating a real PayFast Test Order ID for merchant onboarding.
