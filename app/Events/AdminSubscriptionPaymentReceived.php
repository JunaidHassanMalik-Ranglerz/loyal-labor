<?php

namespace App\Events;

use App\Models\AdminSubscriptionPaymentNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a verified subscription payment notification to one admin.
 * Payload never includes gateway secrets or payment instruments.
 * Uses ShouldBroadcastNow so shared hosts without a queue worker still push.
 */
class AdminSubscriptionPaymentReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AdminSubscriptionPaymentNotification $notification
    ) {
        $this->notification->loadMissing(['payment.technician', 'payment.subscriptionPlan']);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.' . $this->notification->admin_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'subscription.payment.received';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => $this->notification->toAdminCardArray(),
            'unread_count' => AdminSubscriptionPaymentNotification::query()
                ->where('admin_id', $this->notification->admin_id)
                ->where('is_read', false)
                ->count(),
        ];
    }
}
