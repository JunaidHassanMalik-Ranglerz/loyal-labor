# PayFast WebView “Payment not found” — Investigation Report

**Date:** 9 Nov 2026  
**Technician ID:** 48 (`Ranglerzali@gmail.com`)  
**Live DB:** `u896217089_homeservices`

---

## Root cause

Two compounding issues:

1. **Exact UI/API string `Payment not found.`** comes from:
   - `GET /api/payments/{paymentId}`
   - `POST /api/payments/{paymentId}/verify`  
   when the payment row is missing **or** not owned by the authenticated technician.

2. Live runtime evidence for technician **48**:
   - `subscription_payments` rows for technician 48: **0**
   - Total rows in `subscription_payments`: **0**
   - Therefore any app call using a **stale `payment_id`** after the testing reset correctly returns **Payment not found.**

3. Separately, checkout WebView URLs were broken because live `APP_URL` resolved to **`http://localhost`** (APP_URL missing/incorrect in live `.env`).  
   Initiate built:
   `http://localhost/api/payments/payfast/checkout/{order_reference}?token=...`  
   which cannot work on a physical device / RN WebView against production hosting.

Checkout route itself (`GET /payments/payfast/checkout/{order_reference}`) does **not** emit the JSON string `Payment not found.` (it used `firstOrFail` / now returns an explicit HTML 404). The RN message matches the **status/verify** JSON contract.

---

## Checklist answers

| Question | Answer |
|---|---|
| Payment record exists (tech 48) | **NO** |
| Checkout reference matches | **N/A** (no rows to match) |
| Checkout token valid | **N/A** (no live checkout record; token not evaluated) |
| Database connection matches | **YES** (`u896217089_homeservices` used by deployed Laravel) |
| Frontend or backend responsibility | **Both**: backend returned localhost checkout URLs; frontend likely reused old `payment_id` after reset with empty payments table |

---

## Exact required fix (applied)

1. Set live:
   - `APP_URL=https://ranglerz.click/homeservices`
   - `PAYFAST_PUBLIC_BASE_URL=https://ranglerz.click/homeservices`
2. Deploy hardened `PayFastPaymentController`:
   - `publicApiUrl()` prefers non-localhost public base
   - checkout missing payment → clear HTML 404
   - cancelled/failed payments → 410
   - idempotency never reuses cancelled/failed/expired/empty references
3. RN must:
   - Call **fresh** `POST /payments/payfast/initiate`
   - Open **only** the new `data.checkout.webview_url`
   - Never reopen a previous `payment_id` after a DB reset

---

## Tests performed

Command:

```bash
php vendor/bin/phpunit --testdox tests/Unit/PayFastCheckoutControllerTest.php ...
```

Coverage added in `PayFastCheckoutControllerTest`:
- Valid checkout reference lookup
- Missing payment → 404
- Cancelled payment → 410
- Pending payment requires token
- Idempotency ignores cancelled
- public API URL avoids localhost when `PAYFAST_PUBLIC_BASE_URL` set

---

## Remaining blockers

- Technician 48 still has **zero** payment rows until a new successful initiate.
- After initiate, confirm `webview_url` host is `ranglerz.click` (not localhost).
- Do not run a real gateway charge until RN opens the new HTTPS checkout URL.
