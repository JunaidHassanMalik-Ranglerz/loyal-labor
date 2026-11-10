<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSubscriptionPaymentNotification extends Model
{
    protected $fillable = [
        'admin_id',
        'subscription_payment_id',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function markRead(): bool
    {
        if ($this->is_read) {
            return false;
        }

        $this->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();

        return true;
    }

    public function toAdminCardArray(): array
    {
        $payment = $this->relationLoaded('payment')
            ? $this->payment
            : $this->payment()->with(['technician', 'subscriptionPlan'])->first();

        $technician = $payment?->technician;
        $plan = $payment?->subscriptionPlan;
        $planName = $plan
            ? ($plan->name ?: $plan->plan_type_label)
            : 'Subscription';

        return [
            'id' => $this->id,
            'is_read' => (bool) $this->is_read,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'created_at_human' => optional($this->created_at)->diffForHumans(),
            'payment_id' => $payment?->id,
            'technician_name' => $technician?->name ?: ($technician?->email ?: 'Technician'),
            'plan_name' => $planName,
            'amount' => $payment ? (float) $payment->amount : null,
            'currency' => $payment?->currency ?: 'PKR',
            'status' => $payment?->status,
            'gateway' => $payment?->gateway,
            'order_reference' => $payment?->order_reference,
            'transaction_id' => $payment?->payfast_transaction_id,
            'detail_url' => $payment
                ? route('admin.subscription-payments.show', $payment->id)
                : null,
        ];
    }
}
