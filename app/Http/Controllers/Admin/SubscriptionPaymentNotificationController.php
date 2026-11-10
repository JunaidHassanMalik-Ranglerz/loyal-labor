<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSubscriptionPaymentNotification;
use Illuminate\Http\Request;

class SubscriptionPaymentNotificationController extends Controller
{
    public function feed(Request $request)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $adminId = (int) auth('admin')->id();

        $unreadCount = AdminSubscriptionPaymentNotification::query()
            ->where('admin_id', $adminId)
            ->where('is_read', false)
            ->count();

        $items = AdminSubscriptionPaymentNotification::query()
            ->with(['payment.technician', 'payment.subscriptionPlan'])
            ->where('admin_id', $adminId)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AdminSubscriptionPaymentNotification $n) => $n->toAdminCardArray())
            ->values();

        return response()->json([
            'success' => true,
            'unread_count' => max(0, (int) $unreadCount),
            'notifications' => $items,
            'polled_at' => now()->toIso8601String(),
        ]);
    }

    public function unreadCount()
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $count = AdminSubscriptionPaymentNotification::query()
            ->where('admin_id', (int) auth('admin')->id())
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => max(0, (int) $count),
        ]);
    }

    public function markRead(Request $request, $id)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $notification = AdminSubscriptionPaymentNotification::query()
            ->where('admin_id', (int) auth('admin')->id())
            ->findOrFail($id);

        $notification->markRead();

        $unread = AdminSubscriptionPaymentNotification::query()
            ->where('admin_id', (int) auth('admin')->id())
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => max(0, (int) $unread),
            'notification' => $notification->fresh(['payment.technician', 'payment.subscriptionPlan'])->toAdminCardArray(),
        ]);
    }

    public function markAllRead()
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $adminId = (int) auth('admin')->id();

        AdminSubscriptionPaymentNotification::query()
            ->where('admin_id', $adminId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All payment notifications marked as read.',
            'unread_count' => 0,
        ]);
    }
}
