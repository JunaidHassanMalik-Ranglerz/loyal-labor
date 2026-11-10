# Admin Real-Time Subscription Payment Notifications — Delivery Report

**Date:** 10 Nov 2026  
**Project:** LoyLabor  
**Scope:** Admin panel monitoring for gateway-verified PayFast subscription payments  

---

## 1. Existing architecture findings

| Area | Finding |
|---|---|
| Admin auth | Session guard `admin` (`App\Models\Admin`), Spatie permissions |
| Permissions | `subscriptions.view` (System Records) reused for payment UI |
| Legacy admin notifications | `Modules\GlobalSetting\...\AdminNotification` — **global** `is_read` (not per-admin) |
| Navbar legacy bell | Commented out in `master_layout` |
| Broadcasting | `BROADCAST_DRIVER=pusher` configured; `Broadcast::routes` was Sanctum-only |
| Echo in admin | Not previously loaded |
| Payment source of truth | `subscription_payments` + `SubscriptionPaymentActivator::verifyAndActivate` |
| Activation rule | Paid only after independent PayFast status success |

**Decision:** Do **not** reuse global `AdminNotification` for payment success (shared read state violates multi-admin requirements). Introduce per-admin notification rows keyed to `subscription_payments`.

---

## 2. Implementation summary

1. On first transition to gateway-verified **`paid`**, after DB commit, create one unread notification per **active** admin.  
2. Idempotent via unique `(admin_id, subscription_payment_id)`.  
3. Broadcast `subscription.payment.received` on `private-admin.{id}` (Pusher / `ShouldBroadcastNow`).  
4. Admin UI: navbar dropdown + sidebar badge on **Subscription Payments**, with **15s polling fallback** (always on) so refresh/reconnect resyncs.  
5. Listing + detail pages for all payment statuses (pending/paid/failed/cancelled/expired/processing).  
6. Opening via notification marks **that admin’s** notification read only.  
7. Index listing **does not show technician ID** (name only), per product request.

---

## 3. Files modified / added

### Added
- `database/migrations/2026_11_10_120000_create_admin_subscription_payment_notifications_table.php`
- `app/Models/AdminSubscriptionPaymentNotification.php`
- `app/Services/Admin/AdminSubscriptionPaymentNotifier.php`
- `app/Events/AdminSubscriptionPaymentReceived.php`
- `app/Http/Controllers/Admin/SubscriptionPaymentController.php`
- `app/Http/Controllers/Admin/SubscriptionPaymentNotificationController.php`
- `resources/views/admin/subscription-payments/index.blade.php`
- `resources/views/admin/subscription-payments/show.blade.php`
- `resources/views/admin/partials/subscription-payment-notifications.blade.php`
- `resources/views/admin/partials/subscription-payment-notifications-scripts.blade.php`
- `tests/Unit/AdminSubscriptionPaymentNotificationTest.php`
- `docs/ADMIN_REALTIME_PAYMENT_NOTIFICATIONS_REPORT.md`

### Modified
- `app/Services/PayFast/SubscriptionPaymentActivator.php` — post-commit notify on first paid
- `app/Models/SubscriptionPayment.php` — `adminNotifications()` relation
- `routes/admin.php` — payment + notification + admin broadcasting auth routes
- `routes/channels.php` — `admin.{adminId}` private channel
- `resources/views/admin/sidebar.blade.php` — menu + unread badge
- `resources/views/admin/master_layout.blade.php` — navbar dropdown include
- `resources/views/admin/partials/javascripts.blade.php` — scripts include

---

## 4. Database changes

Table: `admin_subscription_payment_notifications`

| Column | Notes |
|---|---|
| `admin_id` | FK → admins |
| `subscription_payment_id` | FK → subscription_payments |
| `is_read` / `read_at` | Per-admin read state |
| unique `(admin_id, subscription_payment_id)` | Duplicate callback/verify safe |
| index `(admin_id, is_read, created_at)` | Unread counter / feed |

Financial truth remains on `subscription_payments` (amount, gateway txn, status, etc.).

**Deploy:** run migration on each environment:

```bash
php artisan migrate --path=database/migrations/2026_11_10_120000_create_admin_subscription_payment_notifications_table.php
```

---

## 5. Notification & broadcasting architecture

```
PayFast verify success
  → SubscriptionPaymentActivator (lock, paid, activate)
  → DB commit
  → AdminSubscriptionPaymentNotifier (firstOrCreate per admin)
  → AdminSubscriptionPaymentReceived (ShouldBroadcastNow)
  → private-admin.{adminId}
```

- Auth endpoint: `POST /admin/broadcasting/auth` (`auth:admin`)
- Channel auth: active Admin matching `{adminId}`
- Payload: technician name, plan, amount, status, URLs — **no tokens/secrets**
- **Polling fallback:** `GET /admin/subscription-payment-notifications/feed` + unread-count every 15s

---

## 6. Sidebar counter behavior

- Badge `#sidebarPaymentUnreadBadge` next to **Subscription Payments**
- Synced with navbar badge from same feed/unread endpoints
- Increments only for **unread success notifications** for the logged-in admin
- Decrements on mark-read / mark-all-read
- Zero state: badge hidden (`d-none`)
- Never negative (`Math.max(0, …)`)

---

## 7. Admin payment listing & detail

- Index: filters (status, plan, technician, gateway, order ref, date range, my read/unread)
- Columns include Payment ID, technician **name** (no technician ID), plan, original/discount/payable, status, gateway, order ref, txn id, date, actions
- Detail: technician, subscription pricing, transaction, lifecycle signals from stored fields only

---

## 8. Authorization controls

- All payment/notification routes behind `auth:admin`
- Permission gate: `subscriptions.view`
- Mark-read scoped to `auth('admin')->id()`
- Notification endpoints cannot change payment settlement status
- Private channel rejects non-matching / inactive admins

---

## 9. Automated test results

| Suite | Result |
|---|---|
| `AdminSubscriptionPaymentNotificationTest` (pure unit: non-paid skip, mark-read idempotent, safe payload, channel name) | Implemented |
| DB-dependent multi-admin persistence / activator notify | Requires MySQL; skipped when DB down |
| Local MySQL during this delivery window | **Unavailable** (`Connection refused` on `127.0.0.1:3306`) |

**Verdict for automated DB suite:** run after MySQL is up:

```bash
php artisan test --filter=AdminSubscriptionPaymentNotification
php artisan test --filter=PayFast
```

---

## 10. Real-time browser testing results

| Check | Result |
|---|---|
| Polling endpoints wired in admin layout | Implemented |
| Pusher Echo subscribe path | Implemented (depends on live Pusher + admin session) |
| End-to-end toast on real sandbox paid event | **NOT PASS** — no certified sandbox paid txn in this window |

Do **not** claim real-time E2E PASS until a verified paid payment is observed in an admin browser session.

---

## 11. Deployment requirements

1. Deploy new/changed PHP, Blade, routes, migration.  
2. Run migration above.  
3. Confirm `BROADCAST_DRIVER=pusher` + valid Pusher keys (already present in env).  
4. Clear caches: `php artisan route:clear && php artisan view:clear && php artisan config:clear`.  
5. Ensure admin users have `subscriptions.view`.  
6. Optional: confirm `/admin/broadcasting/auth` returns 200 for logged-in admin.

---

## 12. Remaining blockers

1. Local/CI MySQL was down — full DB test evidence pending.  
2. Real sandbox paid payment still required to certify live toast + badge increment.  
3. Shared-host cold starts may delay first poll; badge recovers within ≤15s.

---

## 13. Final readiness verdict

**CODE COMPLETE + LIVE DEPLOYED / PARTIALLY VERIFIED — NO-GO for “real-time E2E PASS”**

- Feature implemented against existing PayFast paid lifecycle (no second source of truth).  
- Per-admin unread state + listing/detail + polling + Pusher hooks ready.  
- **Live (10 Nov 2026):** PHP/Blade/routes uploaded; `admin_subscription_payment_notifications` table + FKs/unique/index created; caches cleared; `/admin/subscription-payments` returns auth redirect (302); `payfast-checkout-ack.php` OK.  
- Cannot certify browser real-time PASS without a gateway-verified paid event observed in admin UI.

---

## Admin QA checklist

1. Login as admin → see **Subscription Payments** menu.  
2. Confirm badge 0 when no unread.  
3. Trigger a gateway-verified paid payment (sandbox).  
4. Within ~15s (or instantly via Pusher): badge increments; dropdown shows technician/plan/amount.  
5. Click notification → detail opens; badge decreases; second admin still sees unread.  
6. Mark all read → badges clear.  
7. Open payment from list without `notification_id` → unrelated notifications stay unread.
