# Payment 6 — “No response from server” Investigation & Fix

**Date:** 9 Nov 2026  
**Payment ID:** 6 (status left non-paid; probe only moved it to `processing`)  
**Technician:** 48  

---

## 1. Payment 6 (read-only snapshot before reconcile side-effects)

| Field | Value |
|---|---|
| payment_id | 6 |
| technician_id | 48 |
| BASKET_ID / order_reference | `LOY-RQH4-26110913130896` |
| amount | `570.00` PKR |
| status (initial) | `pending` |
| created/initiated | `2026-11-09T13:13:08+00:00` |
| verified_at / activated_at | null |
| callback_payload | **absent** (PayFast never hit SUCCESS_URL) |
| token_meta.issued_at | `2026-11-09T13:13:08+00:00` |
| SUCCESS_URL (stored) | `https://ranglerz.click/homeservices/api/payments/payfast/return/success` |
| FAILURE_URL (stored) | `https://ranglerz.click/homeservices/api/payments/payfast/return/failure` |
| CHECKOUT_URL used at initiate | `/api/payments/payfast/ipn` (legacy) |

Gateway status by basket: **HTTP 404** from PayFast status API (no successful UAT transaction record found for this basket at probe time).

Technician profile helpers: `is_subscription_active=false`, `is_payment_verified=false`.

---

## 2. Failure stage determination

| Stage | Evidence | Verdict |
|---|---|---|
| A. Before gateway submission | Checkout opened (`ipguat.../CardInfo` per RN) | Not primary |
| B. During gateway processing | Status API 404 for basket; no txn id | Possible / incomplete txn |
| **C. During merchant callback** | **CHECKOUT_URL `/ipn` returned empty HTTP 404 (not Laravel JSON)**; payment had **no** SUCCESS callback payload | **PRIMARY** |
| D. Frontend return handling | No merchant SUCCESS hit recorded | Secondary |

**Conclusion:** Failure is **C (merchant callback)**, with possible contribution from **B** if PayFast aborted after CHECKOUT_URL failure.  
This is **not** the previous SUCCESS_URL sync-verify timeout (that path already returns HTML 200 quickly). Payment 6 never reached SUCCESS_URL.

---

## 3. Callback HTTP evidence

### Before fix
| Endpoint | Method | Result |
|---|---|---|
| `/api/payments/payfast/return/success` | GET/POST | **200** ~0.8–1.2s |
| `/api/payments/payfast/return/failure` | GET/POST | **200** ~0.8s |
| `/api/payments/payfast/ipn` | GET/POST | **empty 404** (blocked before Laravel) |
| `/api/payments/payfast/notify` | POST | **empty 404** after route add (same class of block) |

Laravel fallback JSON 404 was **not** returned for `/ipn` — indicates host/WAF filter, not missing route alone.

### After fix
| Endpoint | Result |
|---|---|
| `/api/payments/payfast/return/callback` | **422** without basket; **200** with basket |
| Fast CHECKOUT_URL handler | **200** ~1.9–2.5s warm (no gateway status call) |

CSRF: API group has **no** VerifyCsrfToken. Auth: callbacks are **public**.  

---

## 4. Root cause

1. PayFast hosted checkout posts merchant notification to **CHECKOUT_URL**.  
2. That URL was `/api/payments/payfast/ipn`.  
3. On this host, `/ipn` (and `/notify`) return **empty HTTP 404** before Laravel.  
4. PayFast UAT then surfaces **"No response from server"**.  
5. Browser SUCCESS redirect never recorded for payment 6.

---

## 5. Fixes implemented (deployed)

1. Merchant callback path → **`/api/payments/payfast/return/callback`** (same namespace as working SUCCESS/FAILURE).  
2. `PAYFAST_IPN_PATH` / `config/payfast.php` updated; CHECKOUT_URL now built from this path.  
3. Callback handler **fast-ack only** (store payload, mark `processing`, no sync status API).  
4. Authoritative activation remains **`POST /payments/{id}/verify`** (+ optional later reconcile).  
5. Payment 6 **not** marked paid/cancelled by this investigation (may be `processing` after callback probe).

---

## 6. Automated tests

```text
OK (24 tests, 69 assertions)
```

---

## 7. RN retest steps (required)

1. Do **not** reuse paymentId 6 webview URL.  
2. `POST /payments/payfast/initiate` with `{ "subscription_id": 1, "force_new": true }`.  
3. Confirm initiate fields / stored meta:
   - `CHECKOUT_URL` = `.../api/payments/payfast/return/callback`
   - `SUCCESS_URL` / `FAILURE_URL` under `.../return/success|failure`
   - `order_reference` starts with `LOY-` (not payment id)
4. Complete Demo Bank.  
5. `POST /payments/{newId}/verify`.  
6. Profile active flags only after verify success.

---

## 8. Sanitized PayFast support pack (if UAT still fails after retest)

```text
Merchant ID: 14833
Mode: Sandbox / UAT (ipguat.apps.net.pk)
Basket ID (payment 6): LOY-RQH4-26110913130896
Amount: 570.00 PKR
Initiated at (UTC): 2026-11-09T13:13:08Z
Symptom: After Make Payment, UAT shows "No response from server"
Merchant CHECKOUT_URL originally: https://ranglerz.click/homeservices/api/payments/payfast/ipn
Observed merchant response to that URL: HTTP 404 empty body (blocked at edge)
SUCCESS_URL reachable: HTTP 200
No SUCCESS redirect payload received for this basket
PayFast status API for basket: HTTP 404 (no txn found at probe time)
New CHECKOUT_URL after fix: https://ranglerz.click/homeservices/api/payments/payfast/return/callback
```
