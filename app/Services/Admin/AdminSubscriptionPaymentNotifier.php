<?php

namespace App\Services\Admin;

use App\Events\AdminSubscriptionPaymentReceived;
use App\Models\Admin;
use App\Models\AdminSubscriptionPaymentNotification;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminSubscriptionPaymentNotifier
{
    /**
     * Create per-admin notifications for a freshly verified paid payment.
     * Idempotent: unique(admin_id, subscription_payment_id) prevents duplicates.
     */
    public function notifyVerifiedPayment(SubscriptionPayment $payment): void
    {
        if (!$payment->isPaid()) {
            return;
        }

        $payment->loadMissing(['technician', 'subscriptionPlan']);

        $admins = Admin::query()
            ->where('status', 'active')
            ->get(['id']);

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($admins as $admin) {
            try {
                $created = false;

                $notification = DB::transaction(function () use ($admin, $payment, &$created) {
                    $existing = AdminSubscriptionPaymentNotification::query()
                        ->where('admin_id', $admin->id)
                        ->where('subscription_payment_id', $payment->id)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $existing;
                    }

                    $created = true;

                    return AdminSubscriptionPaymentNotification::create([
                        'admin_id' => $admin->id,
                        'subscription_payment_id' => $payment->id,
                        'is_read' => false,
                        'read_at' => null,
                    ]);
                });

                if ($created) {
                    $notification->setRelation('payment', $payment);
                    event(new AdminSubscriptionPaymentReceived($notification));
                }
            } catch (\Throwable $e) {
                Log::warning('Admin subscription payment notification failed', [
                    'admin_id' => $admin->id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
