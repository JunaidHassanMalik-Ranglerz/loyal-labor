<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BroadcastEmergencyCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Booking $booking,
        public array $technicianIds = []
    ) {
        $this->booking->loadMissing(['serviceCategory', 'district', 'customer']);
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('service-category.' . (int) $this->booking->service_category_id),
        ];

        foreach ($this->technicianIds as $technicianId) {
            $channels[] = new PrivateChannel('technician.' . (int) $technicianId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'broadcast.emergency.created';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking;

        return [
            'event' => 'broadcast_emergency_created',
            'is_emergency' => true,
            'request_id' => $booking->booking_reference,
            'booking_id' => $booking->id,
            'booking_type' => $booking->booking_type,
            'status' => $booking->status,
            'emergency_level' => $booking->emergency_level,
            'service_category_id' => $booking->service_category_id,
            'service_category' => $booking->serviceCategory?->name,
            'service_details' => $booking->service_details,
            'address' => $booking->address,
            'city' => $booking->city,
            'customer' => [
                'id' => $booking->customer?->id,
                'name' => $booking->customer?->name,
            ],
            'open' => 'broadcast_emergency_requests',
            'list_endpoint' => '/api/technician/broadcast-emergency-requests',
        ];
    }
}
