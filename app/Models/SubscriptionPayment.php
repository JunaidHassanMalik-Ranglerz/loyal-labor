<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_request' => 'array',
        'provider_response' => 'array',
        'callback_payload' => 'array',
        'initiated_at' => 'datetime',
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function adminNotifications()
    {
        return $this->hasMany(AdminSubscriptionPaymentNotification::class, 'subscription_payment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function toApiArray(): array
    {
        $plan = $this->relationLoaded('subscriptionPlan')
            ? $this->subscriptionPlan
            : $this->subscriptionPlan()->first();

        return [
            'payment_id' => $this->id,
            'order_reference' => $this->order_reference,
            'subscription_id' => $this->subscription_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'environment' => $this->environment,
            'status' => $this->status,
            'transaction_reference' => $this->payfast_transaction_id,
            'failure_reason' => $this->failure_reason,
            'initiated_at' => optional($this->initiated_at)->toIso8601String(),
            'verified_at' => optional($this->verified_at)->toIso8601String(),
            'activated_at' => optional($this->activated_at)->toIso8601String(),
            'subscription_end' => $this->subscription_end,
            'subscription' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name ?: $plan->plan_type_label,
                'status' => $this->isPaid() ? 'active' : $this->status,
                'expires_at' => $this->subscription_end,
            ] : null,
        ];
    }
}
