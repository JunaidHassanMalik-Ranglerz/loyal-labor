<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $perPage = max(1, min((int) $request->input('per_page', 20), 50));

        $paginator = UserNotification::where('user_id', $userId)
            ->latest()
            ->paginate($perPage)
            ->appends($request->query());

        $items = collect($paginator->items())->map(fn (UserNotification $notification) => $this->formatCard($notification))->values();

        $groups = $items
            ->groupBy(fn (array $item) => $item['group_label'])
            ->map(fn ($groupItems, $label) => [
                'label' => $label,
                'items' => $groupItems->values(),
            ])
            ->values();

        $unreadCount = UserNotification::where('user_id', $userId)->where('is_read', false)->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'unread_label' => $unreadCount . ' Unread',
            'mark_all_read_endpoint' => '/api/notifications/read-all',
            'groups' => $groups,
            'data' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'notifications' => $items,
            ],
        ]);
    }

    public function markRead(Request $request, $id)
    {
        $notification = UserNotification::where('user_id', $request->user()->id)->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => $this->formatCard($notification->fresh()),
        ]);
    }

    public function markAllRead(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }

    public function clearAll(Request $request)
    {
        $deletedCount = UserNotification::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications cleared.',
            'deleted_count' => $deletedCount,
        ]);
    }

    private function formatCard(UserNotification $notification): array
    {
        $createdAt = $notification->created_at ? Carbon::parse($notification->created_at) : now();
        $type = (string) ($notification->type ?: 'general');

        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'body' => $notification->body,
            'type' => $type,
            'icon' => $this->iconForType($type),
            'is_read' => (bool) $notification->is_read,
            'unread_dot' => !(bool) $notification->is_read,
            'time_ago' => $this->timeAgo($createdAt),
            'group_label' => $this->groupLabel($createdAt),
            'created_at' => $createdAt->toDateTimeString(),
            'data' => $notification->data ?? [],
            'push_status' => $notification->push_status,
        ];
    }

    private function iconForType(string $type): string
    {
        return match ($type) {
            'booking_confirmed', 'broadcast_accepted', 'accepted' => 'calendar',
            'booking_request_sent' => 'calendar',
            'technician_on_the_way', 'on_the_way' => 'navigation',
            'service_completed', 'completed', 'job_completed', 'work_started', 'work_in_progress' => 'check',
            'rate_reminder' => 'star',
            'booking_request', 'broadcast_request', 'individual_select', 'new' => 'alert',
            'booking_cancelled', 'individual_declined', 'individual_rejected', 'booking_rejected' => 'close',
            'subscription_activated' => 'crown',
            'completion_code' => 'key',
            default => 'bell',
        };
    }

    private function groupLabel(Carbon $date): string
    {
        if ($date->isToday()) {
            return 'TODAY';
        }
        if ($date->isYesterday()) {
            return 'YESTERDAY';
        }

        return strtoupper($date->format('M d'));
    }

    private function timeAgo(Carbon $date): string
    {
        $minutes = (int) $date->diffInMinutes(now());

        if ($minutes < 1) {
            return 'Just now';
        }
        if ($minutes < 60) {
            return $minutes . ' min ago';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
        }

        $days = (int) $date->diffInDays(now());

        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }
}
