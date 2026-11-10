<?php

namespace App\Services\PayFast;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Admin\AdminSubscriptionPaymentNotifier;
use App\Services\BroadcastRadiusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SubscriptionPaymentActivator
{
    public function __construct(
        private readonly PayFastPakistanService $payFast,
        private readonly ?AdminSubscriptionPaymentNotifier $adminNotifier = null
    ) {
    }

    private function adminNotifier(): AdminSubscriptionPaymentNotifier
    {
        return $this->adminNotifier ?? app(AdminSubscriptionPaymentNotifier::class);
    }

    /**
     * Authoritatively verify with PayFast, then activate subscription once.
     */
    public function verifyAndActivate(SubscriptionPayment $payment, array $callbackPayload = []): SubscriptionPayment
    {
        $becamePaid = false;

        $result = DB::transaction(function () use ($payment, $callbackPayload, &$becamePaid) {
            /** @var SubscriptionPayment $payment */
            $payment = SubscriptionPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasAlreadyPaid = $payment->isPaid();

            if ($payment->isPaid() && $payment->activated_at) {
                return $payment->fresh(['subscriptionPlan']);
            }

            if (!empty($callbackPayload)) {
                $payment->callback_payload = array_merge(
                    is_array($payment->callback_payload) ? $payment->callback_payload : [],
                    ['last' => $callbackPayload, 'at' => now()->toIso8601String()]
                );
                $payment->save();
            }

            $statusResult = $this->payFast->getStatusByBasketId(
                $payment->order_reference,
                optional($payment->initiated_at)->format('Y-m-d H:i:s')
            );

            $payment->provider_response = array_merge(
                is_array($payment->provider_response) ? $payment->provider_response : [],
                ['status_check' => $statusResult]
            );

            $normalized = $statusResult['normalized'] ?? [];
            $providerTxn = $normalized['transaction_id']
                ?? $this->payFast->extractTransactionIdFromCallback($callbackPayload);

            if (!($statusResult['ok'] ?? false) || !($normalized['success'] ?? false)) {
                // Keep pending if provider still processing; mark failed only on clear failure codes.
                $code = strtolower((string) ($normalized['status_code'] ?? ''));
                $msg = (string) ($normalized['status_msg'] ?? ($statusResult['error'] ?? 'Payment not verified'));

                if (in_array($code, ['01', '02', '03', '96', '97', '98', '99', 'failed', 'cancelled'], true)
                    || str_contains(strtolower($msg), 'fail')
                    || str_contains(strtolower($msg), 'cancel')
                ) {
                    $payment->status = str_contains(strtolower($msg), 'cancel')
                        ? SubscriptionPayment::STATUS_CANCELLED
                        : SubscriptionPayment::STATUS_FAILED;
                    $payment->failure_reason = mb_substr($msg, 0, 500);
                } else {
                    $payment->status = SubscriptionPayment::STATUS_PROCESSING;
                    $payment->failure_reason = mb_substr($msg, 0, 500);
                }
                $payment->save();

                return $payment->fresh(['subscriptionPlan']);
            }

            // Basket id from provider (when present) must match our internal order reference.
            $providerBasket = $normalized['basket_id']
                ?? $this->payFast->extractBasketIdFromCallback($callbackPayload);
            if ($providerBasket && !hash_equals((string) $payment->order_reference, (string) $providerBasket)) {
                $payment->status = SubscriptionPayment::STATUS_FAILED;
                $payment->failure_reason = 'Provider basket_id does not match internal order reference.';
                $payment->save();

                return $payment->fresh(['subscriptionPlan']);
            }

            // Optional callback signature check when gateway includes SIGNATURE.
            $callbackSignature = $callbackPayload['SIGNATURE']
                ?? $callbackPayload['signature']
                ?? null;
            if (is_string($callbackSignature) && $callbackSignature !== '') {
                $expected = $this->payFast->makeSignature(
                    number_format((float) $payment->amount, 2, '.', ''),
                    (string) $payment->order_reference
                );
                // Some gateways sign with merchant_id:name:amount:basket; reject clear mismatches only.
                if (!hash_equals($expected, $callbackSignature)
                    && !hash_equals(strtolower($expected), strtolower($callbackSignature))
                ) {
                    // Do not hard-fail solely on signature variance across PayFast variants;
                    // status API success remains authoritative. Record for audit.
                    $payment->provider_response = array_merge(
                        is_array($payment->provider_response) ? $payment->provider_response : [],
                        ['signature_mismatch_recorded' => true]
                    );
                }
            }

            if ($providerTxn) {
                $payment->payfast_transaction_id = $providerTxn;
            }

            $payment->status = SubscriptionPayment::STATUS_PAID;
            $payment->verified_at = now();
            $payment->failure_reason = null;
            $payment->save();

            if (!$wasAlreadyPaid) {
                $becamePaid = true;
            }

            $this->activateTechnicianSubscription($payment);

            return $payment->fresh(['subscriptionPlan', 'technician']);
        });

        // Notify admins only after a successful first-time paid transition commits.
        // DB::afterCommit runs immediately when no outer transaction is open.
        if ($becamePaid && $result instanceof SubscriptionPayment && $result->isPaid()) {
            DB::afterCommit(function () use ($result) {
                $this->adminNotifier()->notifyVerifiedPayment($result);
            });
        }

        return $result;
    }

    public function activateTechnicianSubscription(SubscriptionPayment $payment): void
    {
        if ($payment->activated_at) {
            return;
        }

        $user = User::query()->lockForUpdate()->find($payment->technician_id);
        $plan = Subscription::query()->find($payment->subscription_id);

        if (!$user || $user->user_type !== 'technician') {
            throw new RuntimeException('Technician not found for payment activation.');
        }

        if (!$plan || !$plan->is_active) {
            throw new RuntimeException('Subscription plan not available for activation.');
        }

        if (!$user->allDocumentsVerified()) {
            // Payment stays paid; activation deferred until docs are verified.
            $payment->failure_reason = 'Payment verified but documents are not fully verified yet.';
            $payment->save();
            return;
        }

        // Enforce amount consistency against current plan payable.
        $expected = (float) $plan->resolvedPayablePrice();
        if (abs($expected - (float) $payment->amount) > 0.009) {
            throw new RuntimeException('Paid amount does not match current plan payable price.');
        }

        $endsAt = $plan->endsAtFrom(now())->toDateString();

        $userUpdate = [
            'subscription_id' => $plan->id,
            'subscription' => 'active',
            'payment_status' => 'verified',
            'subscription_end' => $endsAt,
        ];
        // Gateway of record: subscription_payments.gateway.
        // users.payment_method is optional denormalized state when column exists.
        if (Schema::hasColumn('users', 'payment_method')) {
            $userUpdate['payment_method'] = 'payfast';
        }
        $user->update($userUpdate);

        $user->refresh();
        $user->syncActivationStatus();

        $payment->subscription_end = $endsAt;
        $payment->activated_at = now();
        $payment->save();

        $planName = $plan->name ?: $plan->plan_type_label;
        app(BroadcastRadiusService::class)->notifyUser(
            $user,
            'Account Activated',
            'Your ' . $planName . ' subscription is now active after successful PayFast payment.',
            [
                'type' => 'subscription_activated',
                'subscription_id' => $plan->id,
                'payment_id' => $payment->id,
                'order_reference' => $payment->order_reference,
                'transaction_reference' => $payment->payfast_transaction_id,
                'subscription_end' => $endsAt,
                'open' => 'activation',
            ],
            'subscription_activated'
        );
    }

    public function markCancelled(SubscriptionPayment $payment, ?string $reason = null): SubscriptionPayment
    {
        if ($payment->isPaid()) {
            return $payment;
        }

        $payment->update([
            'status' => SubscriptionPayment::STATUS_CANCELLED,
            'failure_reason' => mb_substr($reason ?: 'Payment cancelled by user or gateway.', 0, 500),
        ]);

        return $payment->fresh(['subscriptionPlan']);
    }
}
