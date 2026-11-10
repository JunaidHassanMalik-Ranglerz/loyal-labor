# LoyLabor — PayFast Pakistan Payment Flow
## Frontend Handoff Report (React Native)

**For:** React Native Frontend Developer (Ali) / Frontend Cursor Agent  
**From:** Laravel Backend (Live)  
**Date:** 6 Nov 2026  
**Environment:** SANDBOX ONLY  
**Base URL:** `https://ranglerz.click/homeservices/api`

---

## 1. Backend Status

Backend PayFast Pakistan sandbox integration is **LIVE and ready**.

Frontend must implement the technician subscription purchase UI against these APIs.

Do **not** invent payment success locally.  
Do **not** activate subscription from client side.  
Backend is the source of truth.

---

## 2. What Frontend Must Build

Technician subscription purchase flow using PayFast hosted checkout inside a WebView.

### User journey

1. Technician opens Plans screen.
2. Loads plans from backend.
3. Selects a plan.
4. Loads order summary.
5. Taps **Pay with PayFast**.
6. App calls payment initiate API (with auth token).
7. App opens returned `webview_url` (or posts `checkout.fields` to `checkout.url`).
8. Technician completes/cancels payment on PayFast page.
9. App returns from WebView / deep link.
10. App calls payment verify/status API.
11. Only if backend status = `paid`, show success and refresh profile.
12. Profile must show subscription active only when backend says so.

---

## 3. Auth Requirement

All initiate/status/verify/profile calls need:

```http
Authorization: Bearer {technician_access_token}
Accept: application/json
Content-Type: application/json
```

---

## 4. Existing Plan APIs (already available)

### 4.1 List plans

`GET /subscriptions`

Use this for the Plans screen.

### 4.2 Order summary

`POST /subscriptions/order-summary`

Body:

```json
{
  "subscription_id": 1
}
```

Response includes:

- pricing
- features
- `message` / `toast_message`
- `can_purchase` (false if same plan already active)

If `can_purchase === false`, show toast and block Pay button for that same plan. Allow upgrade to a different plan.

---

## 5. PayFast Payment APIs (NEW — use these)

### 5.1 Initiate payment

`POST /payments/payfast/initiate`

Body:

```json
{
  "subscription_id": 1,
  "force_new": true
}
```

Use `force_new: true` after any failed, cancelled, timed-out, or “No response from server” attempt so the backend issues a fresh `BASKET_ID` + checkout session.

#### Success response example

```json
{
  "success": true,
  "message": "PayFast sandbox checkout ready. Complete payment to activate your subscription.",
  "toast_message": "Redirecting to secure PayFast checkout.",
  "data": {
    "payment_id": 125,
    "order_reference": "LOY-XXXX-26110612345678",
    "subscription_id": 1,
    "plan_name": "Basic",
    "amount": 570,
    "currency": "PKR",
    "status": "pending",
    "environment": "sandbox",
    "checkout": {
      "type": "form_post",
      "method": "POST",
      "url": "https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction",
      "fields": {
        "MERCHANT_ID": "...",
        "MERCHANT_NAME": "LoyLabor",
        "TOKEN": "...",
        "TXNAMT": "570.00",
        "BASKET_ID": "LOY-XXXX-...",
        "SUCCESS_URL": "...",
        "FAILURE_URL": "...",
        "CHECKOUT_URL": "...",
        "SIGNATURE": "..."
      },
      "webview_url": "https://ranglerz.click/homeservices/api/payments/payfast/checkout/LOY-XXXX-...?token=..."
    },
    "status_url": "https://ranglerz.click/homeservices/api/payments/125"
  }
}
```

#### Frontend action after initiate

**Recommended (simplest):**

1. Save `payment_id` in state/storage.
2. Open WebView with `data.checkout.webview_url`.
3. Show toast from `toast_message`.

**Alternative:**

Build an HTML form POST using `checkout.url` + `checkout.fields` and load it in WebView.

Never hardcode amount. Always use backend amount.

---

### 5.2 Verify payment after WebView closes / deep link return

`POST /payments/{payment_id}/verify`

Example:

`POST /payments/125/verify`

#### Possible outcomes

- `status: "paid"` → payment success, subscription activated
- `status: "pending"` / `processing` → still waiting; poll again
- `status: "failed"` / `cancelled` → show failure toast

Show toast from `toast_message` / `message`.

---

### 5.3 Get payment status (optional poll)

`GET /payments/{payment_id}`

Use for polling every 2–3 seconds (max ~10 times) after return if verify still pending.

---

## 6. Deep Links (optional but supported)

Backend return pages try to open:

- Success: `loylabor://payment/success?payment_id=...&order_reference=...&status=...`
- Failure: `loylabor://payment/failure?payment_id=...&order_reference=...&status=...`

Frontend should:

1. Register these deep links.
2. On open, read `payment_id`.
3. Call verify/status API.
4. Then refresh profile.

If deep link is not ready yet, still call verify when WebView closes/navigates back.

---

## 7. Profile API (source of truth after payment)

`GET /profile`

Important fields:

```json
{
  "subscription_lifecycle_status": "none | payment_pending | active | expired | inactive",
  "verifications": {
    "subscription": "pending | verified"
  },
  "subscription_plan": {
    "id": 1,
    "name": "Basic",
    "status": "pending | active | expired | inactive",
    "payment_status": "pending | verified",
    "is_payment_verified": false,
    "is_subscription_active": false,
    "expires_at": "2026-11-29",
    "lifecycle_status": "payment_pending"
  }
}
```

### UI rules

| Backend state | What to show |
|---|---|
| `subscription_plan = null` | No plan |
| `lifecycle_status = payment_pending` | Payment in progress / under confirmation |
| `lifecycle_status = active` + `is_subscription_active = true` | Account/plan active |
| `lifecycle_status = expired` | Plan expired, allow repurchase |
| Same plan still active | Block repurchase; allow upgrade |

Do **not** show Account Activated until:

- payment verify returns `paid`, **or**
- profile says `is_subscription_active = true`

---

## 8. Exact Frontend Implementation Steps (for Cursor)

Implement this flow in React Native:

### Step A — Plans screen
1. Call `GET /subscriptions`
2. Show plans with price/discount/payable
3. On select plan → go to Order Summary

### Step B — Order Summary screen
1. Call `POST /subscriptions/order-summary` with `subscription_id`
2. Show breakdown + toast message
3. If `can_purchase === false`, disable Pay CTA and show message
4. Pay CTA label: `Pay with PayFast — PKR {payable}`

### Step C — Initiate payment
1. On Pay press → `POST /payments/payfast/initiate`
2. Store `payment_id`
3. Show `toast_message`
4. Navigate to PayFast WebView using `checkout.webview_url`

### Step D — WebView screen
1. Load `webview_url`
2. On close / navigation back / deep link:
   - call `POST /payments/{payment_id}/verify`
3. If pending, poll `GET /payments/{payment_id}` a few times
4. On `paid`:
   - success toast
   - call `GET /profile`
   - go to Account Activated / Dashboard
5. On failed/cancelled:
   - failure toast
   - stay on plans/summary

### Step E — Same-plan restriction
If initiate/order-summary returns already-active plan message (HTTP 409 / `can_purchase=false`):
show toast:
> You already have an active {Plan Name} plan... You can upgrade to a different plan.

---

## 9. Do / Don’t

### Do
- Use backend payable amount only
- Use bearer auth
- Verify via backend after checkout
- Refresh profile after success
- Support upgrade to a different plan

### Don’t
- Don’t mark payment success from WebView URL alone
- Don’t trust frontend-only status
- Don’t call old activate endpoint for PayFast
- Don’t send amount from client to charge
- Don’t enable live PayFast mode

---

## 10. Old endpoint note

`POST /technician/activate-subscription` is for **manual bank/EasyPaisa** only.

For PayFast use:

`POST /payments/payfast/initiate`

If you send `payment_method: "payfast"` to activate-subscription, backend returns error telling you to use initiate API.

---

## 11. Current Live Endpoints Summary

| Action | Method | Endpoint |
|---|---|---|
| List plans | GET | `/subscriptions` |
| Order summary | POST | `/subscriptions/order-summary` |
| Start PayFast payment | POST | `/payments/payfast/initiate` |
| Payment status | GET | `/payments/{payment_id}` |
| Verify payment | POST | `/payments/{payment_id}/verify` |
| Profile | GET | `/profile` |

Full base:

`https://ranglerz.click/homeservices/api`

---

## 12. Known Backend Pending Item

Sandbox merchant credentials may still need to be filled on server:

- `PAYFAST_MERCHANT_ID`
- `PAYFAST_SECURED_KEY`

Frontend can still implement full UI/flow now.  
Initiate will fail until credentials are present on server.

---

## 13. Acceptance Checklist for Frontend

- [ ] Plans list loads
- [ ] Order summary loads with toast message
- [ ] PayFast initiate opens WebView
- [ ] Back from WebView triggers verify
- [ ] Paid status activates UI only after backend confirm
- [ ] Profile refresh shows plan name + active/pending correctly
- [ ] Same active plan cannot be re-bought
- [ ] Different plan upgrade allowed
- [ ] Failure/cancel shows proper toast

---

## 14. Message for Frontend Cursor Agent

Copy/paste this:

> Backend PayFast Pakistan sandbox is already live for LoyLabor technician subscriptions. Implement React Native flow: list plans → order summary → POST `/payments/payfast/initiate` → open `checkout.webview_url` in WebView → on return call POST `/payments/{payment_id}/verify` (poll GET status if pending) → only on `paid` refresh GET `/profile` and show activated. Do not treat WebView redirect alone as success. Do not use `/technician/activate-subscription` for PayFast. Respect `can_purchase` and same-plan restriction messages. Base URL: `https://ranglerz.click/homeservices/api`. Use Bearer auth. Full details are in this handoff document.
