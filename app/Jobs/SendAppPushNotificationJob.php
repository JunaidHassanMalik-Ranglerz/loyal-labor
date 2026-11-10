<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\FcmPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAppPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $appNotificationId)
    {
    }

    public function handle(): void
    {
        $campaign = AppNotification::find($this->appNotificationId);

        if (!$campaign) {
            return;
        }

        $usersQuery = User::query()->whereIn('user_type', ['customer', 'technician']);

        if ($campaign->target_audience === 'customers') {
            $usersQuery->where('user_type', 'customer');
        } elseif ($campaign->target_audience === 'technicians') {
            $usersQuery->where('user_type', 'technician');
        } elseif ($campaign->target_audience === 'specific_users') {
            $userIds = array_filter(array_map('intval', $campaign->target_user_ids ?? []));

            if (empty($userIds)) {
                $campaign->update(['status' => 'failed']);

                return;
            }

            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->get(['id', 'fcmtoken', 'name']);

        foreach ($users as $user) {
            $userNotification = UserNotification::create([
                'user_id' => $user->id,
                'app_notification_id' => $campaign->id,
                'title' => $campaign->title,
                'body' => $campaign->body,
                'type' => 'admin_broadcast',
                'data' => $campaign->payload,
                'push_status' => 'pending',
            ]);

            $this->sendViaFcmStub($user, $userNotification);
        }

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    private function sendViaFcmStub(User $user, UserNotification $notification): void
    {
        $data = array_merge([
            'notification_id' => (string) $notification->id,
            'type' => $notification->type,
        ], $notification->data ?? []);

        if (empty($user->fcmtoken)) {
            $notification->update(['push_status' => 'skipped']);
            Log::info('App push skipped — no FCM token', ['user_id' => $user->id]);

            return;
        }

        $sent = app(FcmPushService::class)->sendToToken(
            $user->fcmtoken,
            $notification->title,
            $notification->body,
            $data
        );

        $notification->update(['push_status' => $sent ? 'sent' : 'failed']);
    }
}
