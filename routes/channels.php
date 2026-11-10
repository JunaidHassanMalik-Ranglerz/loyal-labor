<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('customer.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('technician.{id}', function ($user, $id) {
    return $user->user_type === 'technician' && (int) $user->id === (int) $id;
});

Broadcast::channel('broadcast.{bookingId}', function ($user, $bookingId) {
    $booking = \App\Models\Booking::find($bookingId);
    if (!$booking) {
        return false;
    }

    if ((int) $user->id === (int) $booking->customer_id) {
        return true;
    }

    if ($user->user_type === 'technician' && \Illuminate\Support\Facades\Schema::hasTable('booking_broadcast_notified')) {
        return \App\Models\BookingBroadcastNotified::where('booking_id', $booking->id)
            ->where('technician_id', $user->id)
            ->exists();
    }

    return false;
});

Broadcast::channel('service-category.{categoryId}', function ($user, $categoryId) {
    if ($user->user_type !== 'technician') {
        return false;
    }

    return $user->serviceCategories()
        ->where('service_categories.id', (int) $categoryId)
        ->exists();
});

Broadcast::channel('livechat.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('trackactiveuser', function ($user) {
    return $user->only('id');
});

/*
| Admin private channel (PayFast subscription payment notifications).
| Authenticated via /admin/broadcasting/auth (auth:admin).
*/
Broadcast::channel('admin.{adminId}', function ($user, $adminId) {
    if ($user instanceof \App\Models\Admin) {
        return (int) $user->id === (int) $adminId && $user->status === 'active';
    }

    // Fallback if default guard resolves an Admin unexpectedly.
    $admin = auth('admin')->user();
    return $admin
        && (int) $admin->id === (int) $adminId
        && $admin->status === 'active';
});
