<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Events\BroadcastServiceRequestCreated;
use App\Models\Booking;
use App\Models\BookingBroadcastNotified;
use App\Models\BookingIndividualOffer;
use App\Models\District;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BroadcastRadiusService;
use App\Services\GoogleDistanceService;
use App\Support\BookingReference;
use App\Support\UserLocation;
use App\Traits\GlobalMailTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class BookingController extends Controller
{
    use GlobalMailTrait;

    private function bookingExpiryMinutes(): int
    {
        $value = null;
        try {
            $value = getSettings('booking_request_expiry_minutes');
        } catch (\Throwable $e) {
            $value = null;
        }

        // getSettings($key) returns stdClass when key doesn't exist; guard it
        if (is_object($value)) {
            $value = null;
        }

        $minutes = (int) ($value ?: 5);
        return $minutes < 1 ? 5 : $minutes;
    }

    public function getExpirySettings()
    {
        $minutes = $this->bookingExpiryMinutes();

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H1', 'location' => 'BookingController::getExpirySettings', 'message' => 'expiry settings read', 'data' => ['expiry_minutes' => $minutes], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'expiry_minutes' => $minutes,
            'expires_in_seconds' => $minutes * 60,
            'message' => "Booking requests expire after {$minutes} minute(s) if not accepted.",
        ]);
    }

    private function normalizeBookingStatus($status): string
    {
        $value = strtolower(trim((string) $status));

        return $value === '' ? 'pending' : $value;
    }

    private function formatBookingStatusLabel($status): string
    {
        $status = $this->normalizeBookingStatus($status);

        return match ($status) {
            'pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress' => 'Upcoming',
            'completed' => 'Completed',
            'cancelled', 'rejected' => 'Cancelled',
            'expired' => 'Expired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function resolveBookingsFilter(Request $request): string
    {
        $raw = $request->input('filters')
            ?? $request->input('filter')
            ?? $request->input('status')
            ?? $request->input('tab')
            ?? 'all';

        $filter = strtolower(trim((string) $raw));

        return match ($filter) {
            'upcoming', 'pending' => 'upcoming',
            'completed' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            'expired' => 'expired',
            'in_progress', 'in-progress' => 'in_progress',
            default => 'all',
        };
    }

    private function bookingFilterStatuses(string $filter): ?array
    {
        return match ($filter) {
            'upcoming' => ['pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress'],
            'in_progress' => ['on_the_way', 'work_started', 'work_in_progress'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled', 'rejected'],
            'expired' => ['expired'],
            default => null,
        };
    }

    private function formatHistoryStatusLabel(string $status): string
    {
        $status = $this->normalizeBookingStatus($status);

        return match ($status) {
            'completed' => 'Completed',
            'cancelled', 'rejected' => 'Cancelled',
            'on_the_way', 'work_started', 'work_in_progress' => 'In Progress',
            'pending', 'accepted' => 'Upcoming',
            'expired' => 'Expired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function formatTimeSlotValue($timeSlot): ?string
    {
        if (!$timeSlot) {
            return null;
        }

        try {
            return Carbon::parse($timeSlot)->format('H:i');
        } catch (\Throwable $e) {
            return is_string($timeSlot) ? $timeSlot : null;
        }
    }

    private function formatTimeSlot12h($timeSlot): ?string
    {
        if (!$timeSlot) {
            return null;
        }

        try {
            return Carbon::parse($timeSlot)->format('g:i A');
        } catch (\Throwable $e) {
            return is_string($timeSlot) ? $timeSlot : null;
        }
    }

    private function formatServiceDateParts($serviceDate): array
    {
        if (!$serviceDate) {
            return ['day' => null, 'date' => null, 'date_formatted' => null];
        }

        $date = Carbon::parse($serviceDate);

        return [
            'day' => $date->format('D'),
            'date' => $date->format('Y-m-d'),
            'date_formatted' => $date->format('D, M j'),
        ];
    }

    private function getPersonInitials(?string $name): string
    {
        if (!$name) {
            return '';
        }

        $parts = preg_split('/\s+/', trim($name));
        $initials = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }

        return substr($initials, 0, 2);
    }

    private function resolveBookingServiceCategory(Booking $booking): ?array
    {
        $category = $booking->serviceCategory;
        if (!$category && $booking->service_category_id) {
            $category = ServiceCategory::find($booking->service_category_id);
        }

        if ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
            ];
        }

        $matchedFromDetails = $this->matchServiceCategoryFromText(
            trim(implode(' ', array_filter([$booking->service_details, $booking->additional_notes])))
        );
        if ($matchedFromDetails) {
            return [
                'id' => $matchedFromDetails->id,
                'name' => $matchedFromDetails->name,
                'slug' => $matchedFromDetails->slug,
                'icon' => $matchedFromDetails->icon,
            ];
        }

        $technician = $booking->technician;
        if (!$technician) {
            return null;
        }

        if (!$technician->relationLoaded('serviceCategories')) {
            $technician->load('serviceCategories');
        }

        if ($technician->serviceCategories->isNotEmpty()) {
            $category = $technician->serviceCategories->first();

            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
            ];
        }

        if ($technician->services) {
            $services = is_string($technician->services)
                ? json_decode($technician->services, true)
                : $technician->services;

            if (is_array($services) && count($services) > 0) {
                $slug = (string) $services[0];

                return [
                    'id' => null,
                    'name' => ucfirst(str_replace('_', ' ', $slug)),
                    'slug' => $slug,
                    'icon' => null,
                ];
            }
        }

        return null;
    }

    private function resolveJobServiceCategoryName(Booking $booking): string
    {
        $category = $this->resolveBookingServiceCategory($booking);
        if (!empty($category['name'])) {
            return $category['name'];
        }

        $searchText = trim(implode(' ', array_filter([
            $booking->service_details,
            $booking->additional_notes,
        ])));

        if ($searchText !== '') {
            $matched = $this->matchServiceCategoryFromText($searchText);
            if ($matched) {
                return $matched->name;
            }
        }

        return 'N/A';
    }

    private function matchServiceCategoryFromText(?string $searchText): ?ServiceCategory
    {
        $searchText = trim((string) $searchText);
        if ($searchText === '') {
            return null;
        }

        $exact = ServiceCategory::query()
            ->where('name', $searchText)
            ->orWhere('slug', strtolower(str_replace(' ', '-', $searchText)))
            ->orderBy('sort_order')
            ->first();

        if ($exact) {
            return $exact;
        }

        foreach (ServiceCategory::query()->orderBy('sort_order')->get() as $item) {
            $slugName = $item->slug ? str_replace('-', ' ', $item->slug) : '';
            if (
                stripos($searchText, $item->name) !== false
                || ($slugName !== '' && stripos($searchText, $slugName) !== false)
            ) {
                return $item;
            }
        }

        return null;
    }

    private function formatTechnicianSummary(?User $technician): ?array
    {
        if (!$technician) {
            return null;
        }

        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'initials' => $this->getPersonInitials($technician->name),
            'photo' => $technician->photo ? asset($technician->photo) : null,
            'is_available' => (bool) ($technician->currently_available ?? false),
        ];
    }

    private function formatBookingCardForCustomer(Booking $booking): array
    {
        $dateParts = $this->formatServiceDateParts($booking->service_date);
        $timeFormatted = $this->formatTimeSlot12h($booking->time_slot);
        $location = trim(($booking->address ?? '') . ($booking->city ? ', ' . $booking->city : ''), ', ');
        $status = $this->normalizeBookingStatus($booking->status);
        $statusLabel = $this->formatBookingStatusLabel($status);

        return [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'booking_type' => $booking->booking_type,
            'status' => $status,
            'status_label' => $statusLabel,
            'technician' => $this->formatTechnicianSummary($booking->technician),
            'service_category' => $this->resolveBookingServiceCategory($booking),
            'service_details' => $booking->service_details,
            'service_date' => $dateParts['date'],
            'service_day' => $dateParts['day'],
            'service_date_formatted' => $dateParts['date_formatted'],
            'time_slot' => $this->formatTimeSlotValue($booking->time_slot),
            'time_formatted' => $timeFormatted,
            'date_time_formatted' => ($dateParts['date_formatted'] && $timeFormatted)
                ? $dateParts['date_formatted'] . ' • ' . $timeFormatted
                : null,
            'address' => $booking->address,
            'city' => $booking->city,
            'location' => $location ?: null,
            'payment_status' => $booking->payment_status,
            'created_at' => $booking->created_at?->toIso8601String(),
            'can_cancel' => in_array($status, ['pending', 'accepted'], true),
            'can_rate' => $status === 'completed',
            'can_rebook' => $status === 'completed',
            'can_track' => in_array($status, ['accepted', 'on_the_way', 'work_started', 'work_in_progress'], true),
        ];
    }

    private function formatBookingConfirmationForCustomer(Booking $booking): array
    {
        $card = $this->formatBookingCardForCustomer($booking);

        return [
            'booking_id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'status' => $booking->status,
            'status_label' => $card['status_label'],
            'technician_name' => $booking->technician?->name,
            'technician' => $card['technician'],
            'service_details' => $booking->service_details,
            'service_category' => $card['service_category'],
            'service_date' => $card['service_date'],
            'service_day' => $card['service_day'],
            'service_date_formatted' => $card['service_date_formatted'],
            'time_slot' => $card['time_slot'],
            'time_formatted' => $card['time_formatted'],
            'date_time_formatted' => $card['date_time_formatted'],
            'address' => $booking->address,
            'city' => $booking->city,
            'location' => $card['location'],
            'job_progress' => [
                'accepted_at' => $booking->accepted_at ? Carbon::parse($booking->accepted_at)->format('g:i A') : null,
                'on_the_way_at' => $booking->on_the_way_at ? Carbon::parse($booking->on_the_way_at)->format('g:i A') : null,
                'work_started_at' => $booking->work_started_at ? Carbon::parse($booking->work_started_at)->format('g:i A') : null,
                'work_in_progress_at' => $booking->work_in_progress_at ? Carbon::parse($booking->work_in_progress_at)->format('g:i A') : null,
                'completed_at' => $booking->completed_at ? Carbon::parse($booking->completed_at)->format('g:i A') : null,
            ],
        ];
    }

    public function getBookingConfirmation(Request $request, $bookingId)
    {
        $customer = $request->user();

        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress', 'completed'])
            ->with(['technician.serviceCategories', 'serviceCategory', 'district'])
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmed booking not found. It may still be pending or expired.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking confirmed!',
            'confirmation' => $this->formatBookingConfirmationForCustomer($booking),
        ]);
    }

    private function expirePendingBookingsQuery($query)
    {
        return $query->where('status', 'pending')
            ->where(function ($type) {
                $type->whereNull('booking_type')->orWhere('booking_type', '!=', 'broadcast');
            })
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);
    }

    private function matchingTechniciansQuery(int $serviceCategoryId, ?int $districtId = null, bool $onlineOnly = false)
    {
        $query = User::query()
            ->where('user_type', 'technician')
            ->whereAccountActivated()
            ->where('is_verified', true)
            ->whereHas('serviceCategories', function ($q) use ($serviceCategoryId) {
                $q->where('service_categories.id', $serviceCategoryId);
            });

        if ($districtId) {
            $query->where('district_id', $districtId);
        }

        if ($onlineOnly && Schema::hasColumn('users', 'currently_available')) {
            $query->where('currently_available', true);
        }

        return $query;
    }

    private function technicianCanViewBroadcast(User $technician, Booking $booking): bool
    {
        if (!$technician->isAccountActivated()) {
            return false;
        }

        if (!$booking->isBroadcast() || $booking->status !== 'pending' || $booking->technician_id) {
            return false;
        }

        if ($booking->expires_at && !$booking->isBroadcast() && Carbon::now()->greaterThan($booking->expires_at)) {
            return false;
        }

        if (!$booking->service_category_id) {
            return false;
        }

        $hasCategory = $technician->serviceCategories()
            ->where('service_categories.id', $booking->service_category_id)
            ->exists();

        if (!$hasCategory) {
            return false;
        }

        if ($this->hasTechnicianRejectedBooking($technician, (int) $booking->id)) {
            return false;
        }

        if (Schema::hasTable('booking_broadcast_notified')
            && BookingBroadcastNotified::where('booking_id', $booking->id)->exists()) {
            return BookingBroadcastNotified::where('booking_id', $booking->id)
                ->where('technician_id', $technician->id)
                ->exists();
        }

        return true;
    }

    private function ensureBookingRejectionsTable(): void
    {
        if (Schema::hasTable('booking_rejections')) {
            return;
        }

        Schema::create('booking_rejections', function ($table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('technician_id');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'technician_id']);
        });
    }

    private function hasTechnicianRejectedBooking(User $technician, int $bookingId): bool
    {
        $this->ensureBookingRejectionsTable();

        return DB::table('booking_rejections')
            ->where('booking_id', $bookingId)
            ->where('technician_id', $technician->id)
            ->exists();
    }

    private function technicianRejectedBookingIds(User $technician): array
    {
        $this->ensureBookingRejectionsTable();

        return DB::table('booking_rejections')
            ->where('technician_id', $technician->id)
            ->pluck('booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function recordTechnicianBookingRejection(User $technician, Booking $booking, ?string $reason): void
    {
        $this->ensureBookingRejectionsTable();

        $now = now();
        DB::table('booking_rejections')->updateOrInsert(
            [
                'booking_id' => $booking->id,
                'technician_id' => $technician->id,
            ],
            [
                'reason' => $reason,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    // Customer: Broadcast request to all technicians in selected category
    public function broadcastRequest(Request $request)
    {
        $request->merge([
            'latitude' => $request->input('latitude', $request->input('lat')),
            'longitude' => $request->input('longitude', $request->input('lng', $request->input('long'))),
        ]);

        $request->validate([
            'service_category_id' => 'required|exists:service_categories,id',
            'district_id' => 'nullable|exists:districts,id',
            'address' => 'required|string',
            'city' => 'required|string',
            'service_details' => 'required|string',
            'emergency_level' => 'required|in:medium,high,emergency',
            'additional_notes' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $customer = $request->user();
        $now = Carbon::now();
        $requestId = BookingReference::generateBroadcast($request->emergency_level);
        $radiusService = app(BroadcastRadiusService::class);
        $radiusService->ensureTables();
        $latitude = (float) $request->latitude;
        $longitude = (float) $request->longitude;

        $booking = Booking::create([
            'customer_id' => $customer->id,
            'technician_id' => null,
            'booking_type' => 'broadcast',
            'service_category_id' => $request->service_category_id,
            'district_id' => $request->district_id,
            'emergency_level' => $request->emergency_level,
            'status' => 'pending',
            'service_date' => $now->toDateString(),
            'time_slot' => $now->format('H:i:s'),
            'service_details' => $request->service_details,
            'address' => $request->address,
            'city' => $request->city,
            'phone' => $request->phone ?? $customer->phone,
            'additional_notes' => $request->additional_notes,
            'booking_reference' => $requestId,
            'expires_at' => null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current_radius_km' => BroadcastRadiusService::RADIUS_START_KM,
            'last_expand_prompt_at' => $now,
        ]);

        if (Schema::hasColumn('users', 'latitude')) {
            $customer->forceFill(['latitude' => $latitude, 'longitude' => $longitude])->save();
        }

        $technicians = $radiusService->syncMatchingTechnicians($booking);

        if ($technicians->isEmpty()) {
            $booking->update(['last_expand_prompt_at' => now()->subSeconds(BroadcastRadiusService::EXPAND_AFTER_SECONDS)]);
        }

        $payload = $radiusService->pushScreen($booking->fresh(['serviceCategory', 'district', 'customer']), 'broadcast_created');
        $booking->load(['serviceCategory', 'district']);

        return response()->json(array_merge($payload, [
            'message' => $technicians->isEmpty()
                ? 'No available technicians found within ' . BroadcastRadiusService::RADIUS_START_KM . ' km. The search radius will expand if nobody accepts.'
                : 'Your request has been broadcast to available technicians.',
            'booking' => [
                'id' => $booking->id,
                'request_id' => $requestId,
                'customer_id' => $booking->customer_id,
                'booking_type' => $booking->booking_type,
                'service_category' => $booking->serviceCategory?->name,
                'service_category_id' => $booking->service_category_id,
                'district' => $booking->district?->name,
                'emergency_level' => $booking->emergency_level,
                'status' => $booking->status,
                'address' => $booking->address,
                'city' => $booking->city,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current_radius_km' => BroadcastRadiusService::RADIUS_START_KM,
                'booking_reference' => $booking->booking_reference,
            ],
        ]), 201);
    }

    // Customer: Live broadcast status while waiting for first acceptance
    public function getBroadcastStatus(Request $request, $bookingId)
    {
        $customer = $request->user();

        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->where('booking_type', 'broadcast')
            ->with(['serviceCategory', 'district', 'technician'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Broadcast request not found'], 404);
        }

        $radiusService = app(BroadcastRadiusService::class);
        $radiusService->ensureTables();
        $expanded = $radiusService->maybeAutoExpand($booking);
        $booking = $expanded['booking']->fresh(['serviceCategory', 'district', 'technician', 'customer']) ?: $expanded['booking'];
        $radiusService->syncMatchingTechnicians($booking);
        $payload = $radiusService->screenPayload($booking, $expanded['expanded'] ? 'radius_expanded' : 'status');

        if ($booking->status === 'accepted') {
            $payload['message'] = 'A technician accepted your request.';
            $payload['technician'] = $booking->technician;
        } elseif ($booking->status === 'cancelled') {
            $payload['message'] = 'You cancelled this request.';
        } elseif ($expanded['expanded']) {
            $payload['message'] = 'Search radius expanded to ' . (int) $booking->current_radius_km . ' km.';
        } else {
            $payload['message'] = 'Broadcasting your request. Waiting for first technician acceptance.';
        }

        return response()->json($payload);
    }

    public function expandBroadcastRadius(Request $request, $bookingId)
    {
        $customer = $request->user();
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->where('booking_type', 'broadcast')
            ->where('status', 'pending')
            ->whereNull('technician_id')
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Broadcast request not found'], 404);
        }

        $radiusService = app(BroadcastRadiusService::class);
        $radiusService->ensureTables();
        $result = $radiusService->expandToNextRadius($booking);

        if (!$result['expanded']) {
            $payload = $radiusService->screenPayload($booking, 'status');
            $payload['message'] = 'Maximum search radius of ' . BroadcastRadiusService::RADIUS_MAX_KM . ' km already reached.';

            return response()->json($payload);
        }

        $booking = $result['booking'];
        $payload = $radiusService->pushScreen($booking, 'radius_expanded');
        $payload['message'] = $result['new_technicians']->isEmpty()
            ? 'No new technicians found within ' . $result['current_radius_km'] . ' km.'
            : $result['new_technicians']->count() . ' technicians have been notified.';

        return response()->json($payload);
    }

    public function selectBroadcastTechnician(Request $request, $bookingId)
    {
        $request->validate([
            'technician_id' => 'required|integer',
        ]);

        $customer = $request->user();
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->where('booking_type', 'broadcast')
            ->where('status', 'pending')
            ->whereNull('technician_id')
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Broadcast request not found'], 404);
        }

        $radiusService = app(BroadcastRadiusService::class);
        $radiusService->ensureTables();

        $technician = User::where('id', $request->technician_id)
            ->where('user_type', 'technician')
            ->whereAccountActivated()
            ->first();

        if (!$technician) {
            return response()->json(['error' => 'Technician not found or account not activated'], 404);
        }

        $remainingIds = $radiusService->remainingTechnicians($booking)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!in_array((int) $technician->id, $remainingIds, true)) {
            return response()->json(['error' => 'This technician is not in the current broadcast list'], 404);
        }

        $offer = BookingIndividualOffer::create([
            'booking_id' => $booking->id,
            'technician_id' => $technician->id,
            'status' => 'pending',
        ]);

        BookingBroadcastNotified::updateOrCreate(
            ['booking_id' => $booking->id, 'technician_id' => $technician->id],
            ['radius_km' => $booking->current_radius_km]
        );

        $radiusService->notifyUser(
            $technician,
            'A customer wants to select you individually',
            $customer->name . ' wants to select you from your profile. Check the top of your pending requests.',
            [
                'booking_id' => $booking->id,
                'request_id' => $booking->booking_reference,
                'type' => 'individual_select',
                'open' => 'pending_requests',
            ],
            'individual_select'
        );

        try {
            event(new BroadcastServiceRequestCreated($booking->fresh(['serviceCategory', 'district', 'customer']), [(int) $technician->id]));
        } catch (\Throwable $e) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Individual request sent to the technician.',
            'offer_id' => $offer->id,
            'technician_id' => $technician->id,
        ]);
    }

    public function approveBroadcastTechnician(Request $request, $bookingId)
    {
        $request->validate([
            'technician_id' => 'required|integer',
        ]);

        $customer = $request->user();
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->where('booking_type', 'broadcast')
            ->where('status', 'pending')
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Broadcast request not found'], 404);
        }

        $offer = BookingIndividualOffer::where('booking_id', $booking->id)
            ->where('technician_id', $request->technician_id)
            ->where('status', 'technician_accepted')
            ->latest()
            ->first();

        if (!$offer) {
            return response()->json(['error' => 'No technician acceptance waiting for your approval'], 404);
        }

        $booking->update([
            'technician_id' => $offer->technician_id,
            'status' => 'accepted',
            'accepted_at' => now(),
            'expires_at' => null,
        ]);
        $offer->update(['status' => 'customer_approved']);

        $technician = User::find($offer->technician_id);
        if ($technician) {
            app(BroadcastRadiusService::class)->notifyUser(
                $technician,
                'The customer accepted you',
                'The job is now locked to you.',
                ['booking_id' => $booking->id, 'type' => 'individual_approved']
            );
        }

        app(BroadcastRadiusService::class)->notifyUser(
            $customer,
            'Your request has been accepted',
            ($technician->name ?? 'Technician') . ' accepted your request. The job is now locked.',
            [
                'booking_id' => $booking->id,
                'technician_id' => $offer->technician_id,
                'type' => 'broadcast_accepted',
            ],
            'broadcast_accepted'
        );

        $payload = app(BroadcastRadiusService::class)->pushScreen($booking->fresh(['technician', 'serviceCategory']), 'locked');

        return response()->json(array_merge($payload, [
            'message' => 'Technician approved. The broadcast request is now locked.',
        ]));
    }

    public function declineBroadcastTechnician(Request $request, $bookingId)
    {
        $request->validate([
            'technician_id' => 'required|integer',
        ]);

        $customer = $request->user();
        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->where('booking_type', 'broadcast')
            ->where('status', 'pending')
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Broadcast request not found'], 404);
        }

        $offer = BookingIndividualOffer::where('booking_id', $booking->id)
            ->where('technician_id', $request->technician_id)
            ->where('status', 'technician_accepted')
            ->latest()
            ->first();

        if (!$offer) {
            return response()->json(['error' => 'No technician acceptance waiting for your approval'], 404);
        }

        $offer->update(['status' => 'customer_declined']);

        $technician = User::find($offer->technician_id);
        if ($technician) {
            app(BroadcastRadiusService::class)->notifyUser(
                $technician,
                'The customer did not accept you',
                $customer->name . ' declined your acceptance. The broadcast request is still open.',
                [
                    'booking_id' => $booking->id,
                    'technician_id' => $technician->id,
                    'request_id' => $booking->booking_reference,
                    'type' => 'individual_declined',
                    'open' => 'pending_requests',
                ],
                'individual_declined'
            );
        }

        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'post-fix', 'hypothesisId' => 'D', 'location' => 'BookingController::declineBroadcastTechnician', 'message' => 'customer declined individual acceptance', 'data' => ['booking_id' => $booking->id, 'technician_id' => $offer->technician_id, 'technician_found' => (bool) $technician, 'notification_type' => 'individual_declined'], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        $payload = app(BroadcastRadiusService::class)->pushScreen($booking->fresh(['serviceCategory']), 'customer_declined_technician');
        $payload['message'] = 'You declined this technician. The broadcast request is still open.';

        return response()->json($payload);
    }


    // Customer: Book a technician
    public function bookTechnician(Request $request)
    {
        $request->validate([
            'technician_id' => 'required',
            'service_date' => 'required',
            'time_slot' => 'required',
            'service_details' => 'required|string',
            'address' => 'required|string',
            'city' => 'nullable|string',
            'emergency_level' => 'nullable|string',
            'service_category_id' => 'nullable|integer|exists:service_categories,id',
            'category_id' => 'nullable|integer|exists:service_categories,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'long' => 'nullable|numeric|between:-180,180',
        ]);

        $customer = $request->user();
        $technician = User::where('id', $request->technician_id)
                         ->where('user_type', 'technician')
                         ->whereAccountActivated()
                         ->first();

        if (!$technician) {
            return response()->json(['error' => 'Technician not found or account not activated'], 404);
        }

        $emergency = strtolower(trim((string) ($request->emergency_level ?: 'low')));
        $isNowRequest = in_array($emergency, ['now', 'immediate'], true);
        $storedEmergency = match ($emergency) {
            'now', 'immediate', 'emergency' => 'emergency',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };

        $requestedAt = $this->parseRequestedBookingDateTime($request->service_date, $request->time_slot);
        if (!$requestedAt) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid service date or time. Use a valid current or future date/time.',
            ], 422);
        }

        $now = Carbon::now();

        if ($requestedAt->lt($now->copy()->subMinutes(2))) {
            return response()->json([
                'success' => false,
                'message' => 'Past date/time is not allowed. Please select current or future date and time.',
                'requested_at' => $requestedAt->format('Y-m-d H:i:s'),
                'server_time' => $now->format('Y-m-d H:i:s'),
            ], 422);
        }

        if ($isNowRequest) {
            $requestedAt = $now;
        }

        $serviceDate = $requestedAt->toDateString();
        $timeSlot = $requestedAt->format('H:i:s');

        // Check if technician is available on that date/time
        if (!$this->isTechnicianAvailable(
            $technician->id,
            $serviceDate,
            $timeSlot,
            $storedEmergency,
            $technician
        )) {
            return response()->json(['error' => 'Technician not available at this time'], 400);
        }

        // Check for existing booking conflict
        $existingBooking = Booking::where('technician_id', $technician->id)
            ->whereDate('service_date', $serviceDate)
            ->whereRaw('TIME(time_slot) = ?', [$timeSlot])
            ->whereNotIn('status', ['cancelled', 'completed', 'rejected', 'expired'])
            ->exists();

        if ($existingBooking) {
            return response()->json(['error' => 'This time slot is already booked'], 400);
        }

        $expiryMinutes = $this->bookingExpiryMinutes();

        $categoryId = $request->input('service_category_id') ?? $request->input('category_id');
        if (!$categoryId && $request->filled('service_category')) {
            $categoryId = ServiceCategory::query()
                ->where('name', $request->service_category)
                ->orWhere('slug', $request->service_category)
                ->value('id');
        }
        if (!$categoryId) {
            $categoryId = $this->matchServiceCategoryFromText($request->service_details)?->id
                ?? $this->matchServiceCategoryFromText($request->service_category)?->id;
        }

        [$latitude, $longitude] = UserLocation::coordinatesFromRequest($request);
        if ($latitude !== null && $longitude !== null) {
            UserLocation::save($customer, $latitude, $longitude);
        } else {
            $latitude = $customer->latitude !== null ? (float) $customer->latitude : null;
            $longitude = $customer->longitude !== null ? (float) $customer->longitude : null;
        }

        $bookingPayload = [
            'customer_id' => $customer->id,
            'technician_id' => $technician->id,
            'booking_type' => 'direct',
            'service_category_id' => $categoryId,
            'district_id' => $request->district_id,
            'emergency_level' => $storedEmergency,
            'status' => 'pending',
            'service_date' => $serviceDate,
            'time_slot' => $timeSlot,
            'service_details' => $request->service_details,
            'address' => $request->address,
            'city' => $request->city,
            'phone' => $request->phone ?? $customer->phone,
            'additional_notes' => $request->additional_notes,
            'booking_reference' => null,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
        ];

        if ($latitude !== null && $longitude !== null && Schema::hasColumn('bookings', 'latitude')) {
            $bookingPayload['latitude'] = $latitude;
            $bookingPayload['longitude'] = $longitude;
        }

        $booking = Booking::create($bookingPayload);
        $booking->load(['customer', 'technician', 'serviceCategory']);

        $this->notifyTechnician($technician, $booking, 'new');
        $this->notifyCustomer($booking->customer, $booking, 'request_sent');

        return response()->json([
            'success' => true,
            'message' => 'Booking request sent successfully',
            'booking' => $booking->load('customer', 'technician'),
            'expires_at' => $booking->expires_at,
            'note' => "Request will expire in {$expiryMinutes} minutes if not accepted. Reference code will be generated when technician confirms.",
        ], 201);
    }

    // Technician: Get all booking requests
    public function activeJobs(Request $request)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view active jobs.',
            ], 403);
        }

        $perPage = $this->technicianHomePerPage($request);

        $bookings = Booking::query()
            ->where('technician_id', $technician->id)
            ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress'])
            ->with([
                'customer' => function ($query) {
                    $query->select('id', 'name', 'photo', 'is_verified', 'phone');
                },
                'serviceCategory:id,name,slug,icon',
                'district:id,name',
                'technician.serviceCategories',
            ])
            ->orderByRaw("CASE status WHEN 'on_the_way' THEN 1 WHEN 'work_started' THEN 2 WHEN 'work_in_progress' THEN 3 WHEN 'accepted' THEN 4 ELSE 5 END")
            ->orderBy('service_date')
            ->orderBy('time_slot')
            ->paginate($perPage)
            ->appends($request->query());

        $jobs = collect($bookings->items())->map(function (Booking $booking) {
            return $this->formatTechnicianActiveJobCard($booking);
        })->values();

        return response()->json([
            'success' => true,
            'message' => $jobs->isEmpty()
                ? 'No active jobs right now.'
                : 'Active jobs retrieved successfully.',
            'data' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
                'jobs' => $jobs,
            ],
        ]);
    }

    public function pendingRequests(Request $request)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view pending requests.',
            ], 403);
        }

        if (!$technician->isAccountActivated()) {
            return response()->json([
                'success' => true,
                'message' => 'Activate your account to receive booking requests.',
                'filter' => 'all',
                'filters' => ['all' => 0, 'now' => 0, 'later' => 0, 'emergency' => 0],
                'new_count' => 0,
                'badge_count' => 0,
                'is_account_active' => false,
                'data' => [
                    'current_page' => 1,
                    'per_page' => $this->technicianHomePerPage($request),
                    'total' => 0,
                    'last_page' => 1,
                    'requests' => [],
                ],
            ]);
        }

        UserLocation::saveFromRequest($technician, $request);
        $technician->refresh();

        app(BroadcastRadiusService::class)->ensureTables();

        $filter = strtolower(trim((string) ($request->input('filter') ?? $request->input('type') ?? 'all')));
        if (!in_array($filter, ['all', 'now', 'later', 'emergency'], true)) {
            $filter = 'all';
        }

        $perPage = $this->technicianHomePerPage($request);
        $baseQuery = $this->technicianPendingBookingsQuery($technician);

        $filterCounts = [
            'all' => (clone $baseQuery)->count(),
            'now' => $this->applyPendingRequestTypeFilter(clone $baseQuery, 'now')->count(),
            'later' => $this->applyPendingRequestTypeFilter(clone $baseQuery, 'later')->count(),
            'emergency' => $this->applyPendingRequestTypeFilter(clone $baseQuery, 'emergency')->count(),
        ];

        $bookings = $this->applyPendingRequestTypeFilter(clone $baseQuery, $filter)
            ->orderByRaw("CASE WHEN EXISTS (
                SELECT 1 FROM booking_individual_offers o
                WHERE o.booking_id = bookings.id
                  AND o.technician_id = ?
                  AND o.status IN ('pending','technician_accepted')
            ) THEN 0 ELSE 1 END", [$technician->id])
            ->orderByRaw("CASE WHEN emergency_level = 'emergency' THEN 0 WHEN emergency_level = 'high' THEN 1 ELSE 2 END")
            ->latest()
            ->paginate($perPage)
            ->appends($request->query());

        $requests = collect($bookings->items())->map(function (Booking $booking) use ($technician) {
            return $this->formatTechnicianPendingRequestCard($booking, $technician);
        })->values();

        return response()->json([
            'success' => true,
            'message' => $requests->isEmpty()
                ? 'No pending requests right now.'
                : 'Pending requests retrieved successfully.',
            'filter' => $filter,
            'filters' => $filterCounts,
            'new_count' => $filterCounts['all'],
            'badge_count' => $filterCounts['emergency'] + $filterCounts['now'],
            'google_maps' => [
                'technician_latitude' => $technician->latitude !== null ? (float) $technician->latitude : null,
                'technician_longitude' => $technician->longitude !== null ? (float) $technician->longitude : null,
                'integration_status' => app(GoogleDistanceService::class)->hasApiKey() ? 'live' : 'haversine_fallback',
                'distance_source' => app(GoogleDistanceService::class)->hasApiKey() ? 'google_distance_matrix' : 'haversine',
            ],
            'data' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
                'requests' => $requests,
            ],
        ]);
    }

    public function getTechnicianBroadcastEmergencyRequests(Request $request)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view broadcast emergency requests.',
            ], 403);
        }

        if (!$technician->isAccountActivated()) {
            return response()->json([
                'success' => true,
                'message' => 'Activate your account to receive booking requests.',
                'is_account_active' => false,
                'data' => [],
            ]);
        }

        app(BroadcastRadiusService::class)->ensureTables();

        $categoryIds = $technician->serviceCategories()->pluck('service_categories.id')->map(fn ($id) => (int) $id)->all();
        $rejectedIds = $this->technicianRejectedBookingIds($technician);
        $perPage = $this->technicianHomePerPage($request);

        $query = Booking::query()
            ->where('booking_type', 'broadcast')
            ->where('emergency_level', 'emergency')
            ->where('status', 'pending')
            ->where(function ($q) use ($technician, $categoryIds) {
                $q->where('technician_id', $technician->id)
                    ->orWhere(function ($open) use ($technician, $categoryIds) {
                        $open->whereNull('technician_id');
                        $open->where(function ($match) use ($technician, $categoryIds) {
                            if (!empty($categoryIds)) {
                                $match->whereIn('service_category_id', $categoryIds);
                            }
                            if (Schema::hasTable('booking_broadcast_notified')) {
                                $match->orWhereExists(function ($notified) use ($technician) {
                                    $notified->select(DB::raw(1))
                                        ->from('booking_broadcast_notified as n')
                                        ->whereColumn('n.booking_id', 'bookings.id')
                                        ->where('n.technician_id', $technician->id);
                                });
                            }
                            if (empty($categoryIds) && !Schema::hasTable('booking_broadcast_notified')) {
                                $match->whereRaw('1 = 0');
                            }
                        });
                    });
            })
            ->when(!empty($rejectedIds), function ($q) use ($rejectedIds) {
                $q->whereNotIn('id', $rejectedIds);
            })
            ->with([
                'customer' => function ($q) {
                    $q->select('id', 'name', 'photo', 'is_verified', 'latitude', 'longitude', 'phone');
                },
                'serviceCategory:id,name,slug,icon',
                'district:id,name',
            ])
            ->latest();

        $bookings = $query->paginate($perPage)->appends($request->query());
        $requests = collect($bookings->items())->map(function (Booking $booking) use ($technician) {
            $card = $this->formatTechnicianPendingRequestCard($booking, $technician);
            $card['is_emergency'] = true;
            $card['request_type'] = 'emergency';
            $card['request_type_label'] = 'Emergency';
            $card['matched_by'] = (int) $booking->technician_id === (int) $technician->id
                ? 'assigned'
                : 'service_category';

            return $card;
        })->values();

        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'emergency-bcast', 'hypothesisId' => 'A', 'location' => 'BookingController::getTechnicianBroadcastEmergencyRequests', 'message' => 'broadcast emergency list', 'data' => ['technician_id' => $technician->id, 'category_ids' => $categoryIds, 'total' => $bookings->total(), 'sample_ids' => $requests->pluck('id')->take(5)->values()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => $requests->isEmpty()
                ? 'No broadcast emergency requests found.'
                : 'Broadcast emergency requests retrieved successfully.',
            'total' => $bookings->total(),
            'data' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
                'requests' => $requests,
            ],
            'pusher' => [
                'key' => config('services.pusher.key') ?: env('PUSHER_APP_KEY'),
                'cluster' => config('services.pusher.cluster') ?: env('PUSHER_APP_CLUSTER', 'ap2'),
                'channel' => 'private-technician.' . $technician->id,
                'event' => 'broadcast.emergency.created',
                'auth_endpoint' => '/broadcasting/auth',
            ],
        ]);
    }

    public function technicianBookingHistory(Request $request)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view booking history.',
            ], 403);
        }

        $filter = $this->resolveBookingsFilter($request);
        $filterStatuses = $this->bookingFilterStatuses($filter);
        $perPage = $this->technicianHomePerPage($request);

        $baseQuery = Booking::query()->where('technician_id', $technician->id);

        $upcomingCount = (clone $baseQuery)->whereIn('status', ['pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress'])->count();

        $bookings = (clone $baseQuery)
            ->when($filterStatuses !== null, function ($query) use ($filterStatuses) {
                return $query->whereIn('status', $filterStatuses);
            })
            ->with([
                'customer' => function ($query) {
                    $query->select('id', 'name', 'photo', 'is_verified', 'phone');
                },
                'serviceCategory:id,name,slug,icon',
                'district:id,name',
            ])
            ->latest()
            ->paginate($perPage)
            ->appends($request->query());

        $history = collect($bookings->items())->map(function (Booking $booking) {
            return $this->formatTechnicianHistoryCard($booking);
        })->values();

        return response()->json([
            'success' => true,
            'message' => $history->isEmpty()
                ? 'No bookings found.'
                : 'Booking history retrieved successfully.',
            'filter' => $filter === 'upcoming' ? 'pending' : $filter,
            'filters' => [
                'all' => (clone $baseQuery)->count(),
                'pending' => $upcomingCount,
                'upcoming' => $upcomingCount,
                'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
                'cancelled' => (clone $baseQuery)->whereIn('status', ['cancelled', 'rejected'])->count(),
            ],
            'data' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
                'bookings' => $history,
            ],
        ]);
    }

    private function formatTechnicianHistoryCard(Booking $booking): array
    {
        $status = $this->normalizeBookingStatus($booking->status);
        $statusLabel = $this->formatHistoryStatusLabel($status);
        $customerName = $booking->customer->name ?? 'Unknown Customer';
        $location = $this->formatJobAddress($booking);

        return [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'booking_type' => $booking->booking_type ?? 'direct',
            'customer_name' => $customerName,
            'customer_initials' => $this->customerInitials($customerName),
            'customer_photo' => $this->photoUrl($booking->customer->photo ?? null),
            'is_verified' => (bool) ($booking->customer->is_verified ?? false),
            'service_category' => $this->resolveJobServiceCategoryName($booking),
            'service_details' => $booking->service_details,
            'location' => $location,
            'address' => $booking->address,
            'city' => $booking->city,
            'date' => $this->formatJobDateLabel($booking),
            'time' => $this->formatTimeSlot12h($booking->time_slot),
            'scheduled_at' => $this->formatScheduledLabel($booking),
            'status' => $status,
            'status_label' => $statusLabel,
            'can_track' => in_array($status, ['accepted', 'on_the_way', 'work_started', 'work_in_progress'], true),
            'can_view_receipt' => $status === 'completed',
            'action' => $status === 'completed'
                ? 'view_receipt'
                : (in_array($status, ['cancelled', 'rejected'], true) ? 'view_details' : 'track_progress'),
            'track_endpoint' => '/api/bookings/' . $booking->id . '/status',
        ];
    }

    public function getBookingRequests(Request $request)
    {
        return $this->pendingRequests($request);
    }

/**
 * Transform booking requests to match the required response format
 */
private function transformBookingRequests($bookings, $technician)
{
    $result = [];
    $result['current_page'] = $bookings->currentPage();
    $result['per_page'] = $bookings->perPage();
    $result['total'] = $bookings->total();
    $result['last_page'] = $bookings->lastPage();
    $result['requests'] = [];

    foreach ($bookings as $booking) {
        // Get customer initials from name
        $customerName = $booking->customer->name ?? 'Unknown Customer';
        $nameParts = explode(' ', $customerName);
        $initials = '';
        foreach ($nameParts as $part) {
            if (!empty($part)) {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }
        $initials = substr($initials, 0, 2);

        // Calculate distance using Haversine formula
        $distance = $this->calculateDistance(
            $technician->latitude,
            $technician->longitude,
            $booking->customer->latitude ?? null,
            $booking->customer->longitude ?? null
        );

        // Format date from service_date
        $date = $booking->service_date ?? $booking->created_at;
        if ($date) {
            $dateObj = \Carbon\Carbon::parse($date);
            if ($dateObj->isToday()) {
                $formattedDate = 'Today';
            } else {
                $formattedDate = $dateObj->format('M d, Y');
            }
        } else {
            $formattedDate = null;
        }

        // Format time from time_slot
        $time = $booking->time_slot ?? null;
        if ($time) {
            $time = \Carbon\Carbon::parse($time)->format('g:i A');
        }

        // Get expiry time from expires_at
        $expiryTime = $this->getExpiryTime($booking);

        // Get priority from emergency_level
        $priority = ucfirst($booking->emergency_level ?? 'low');

        // Build full address
        $fullAddress = '';
        if ($booking->address) {
            $fullAddress = $booking->address;
        }
        if ($booking->city) {
            $fullAddress .= $fullAddress ? ', ' . $booking->city : $booking->city;
        }
        if ($booking->district && $booking->district->name) {
            $fullAddress .= $fullAddress ? ', ' . $booking->district->name : $booking->district->name;
        }

        $result['requests'][] = [
            'id' => $booking->id,
            'customer_initials' => $initials,
            'customer_name' => $customerName,
            'is_verified' => (bool)($booking->customer->is_verified ?? false),
            'service_category' => $this->resolveJobServiceCategoryName($booking),
            'description' => $booking->service_details ?? $booking->additional_notes ?? null,
            'address' => $fullAddress ?: null,
            'city' => $booking->city ?? null,
            'district' => $booking->district->name ?? null,
            'phone' => $booking->phone ?? null,
            'distance' => $distance ? $distance . ' km' : null,
            'date' => $formattedDate,
            'time' => $time,
            'priority' => $priority,
            'expires_at' => $expiryTime,
            'status' => $booking->status ?? 'pending',
            'created_at' => $booking->created_at ? \Carbon\Carbon::parse($booking->created_at)->format('g:i A') : null,
        ];
    }

    return $result;
}

private function technicianHomePerPage(Request $request): int
{
    $perPage = (int) ($request->input('limit') ?: $request->input('per_page', 20));

    return max(1, min($perPage, 50));
}

private function deleteExpiredBroadcastRequests(): void
{
    // Broadcast requests stay open until the customer cancels.
}

private function technicianInboxQuery(User $technician)
{
    $categoryIds = $technician->serviceCategories()->pluck('service_categories.id');

    $rejectedIds = $this->technicianRejectedBookingIds($technician);

    return Booking::query()
        ->when(!empty($rejectedIds), function ($query) use ($rejectedIds) {
            $query->whereNotIn('id', $rejectedIds);
        })
        ->where(function ($query) use ($technician, $categoryIds) {
            $query->where(function ($assigned) use ($technician) {
                $assigned->where('technician_id', $technician->id)
                    ->where(function ($expiry) {
                        $expiry->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', now());
                    });
            })
            ->orWhere(function ($broadcast) use ($technician, $categoryIds) {
                $broadcast->where('booking_type', 'broadcast')
                    ->whereNull('technician_id')
                    ->where('status', 'pending')
                    ->where(function ($expiry) {
                        $expiry->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', now());
                    })
                    ->when(
                        $categoryIds->isNotEmpty(),
                        function ($q) use ($categoryIds) {
                            $q->whereIn('service_category_id', $categoryIds);
                        },
                        function ($q) {
                            $q->whereRaw('1 = 0');
                        }
                    );
            });
        })
        ->with([
            'customer' => function ($query) {
                $query->select('id', 'name', 'photo', 'is_verified', 'latitude', 'longitude', 'phone');
            },
            'serviceCategory:id,name,slug,icon',
            'district:id,name',
            'technician.serviceCategories',
        ]);
}

private function technicianPendingBookingsQuery(User $technician)
{
    $categoryIds = $technician->serviceCategories()->pluck('service_categories.id');

    $rejectedIds = $this->technicianRejectedBookingIds($technician);

    return Booking::query()
        ->where('status', 'pending')
        ->when(!empty($rejectedIds), function ($query) use ($rejectedIds) {
            $query->whereNotIn('id', $rejectedIds);
        })
        ->where(function ($query) use ($technician, $categoryIds) {
            $query->where(function ($direct) use ($technician) {
                $direct->where('technician_id', $technician->id)
                    ->where(function ($type) {
                        $type->whereNull('booking_type')
                            ->orWhere('booking_type', 'direct');
                    })
                    ->where(function ($expiry) {
                        $expiry->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', now());
                    });
            })
            ->orWhere(function ($broadcast) use ($technician, $categoryIds) {
                $broadcast->where('booking_type', 'broadcast')
                    ->whereNull('technician_id')
                    ->when(
                        $categoryIds->isNotEmpty(),
                        function ($q) use ($categoryIds) {
                            $q->whereIn('service_category_id', $categoryIds);
                        },
                        function ($q) {
                            $q->whereRaw('1 = 0');
                        }
                    )
                    ->when(Schema::hasTable('booking_broadcast_notified'), function ($q) use ($technician) {
                        $q->where(function ($notified) use ($technician) {
                            $notified->whereNotExists(function ($legacy) {
                                $legacy->select(DB::raw(1))
                                    ->from('booking_broadcast_notified as n0')
                                    ->whereColumn('n0.booking_id', 'bookings.id');
                            })->orWhereExists(function ($mine) use ($technician) {
                                $mine->select(DB::raw(1))
                                    ->from('booking_broadcast_notified as n1')
                                    ->whereColumn('n1.booking_id', 'bookings.id')
                                    ->where('n1.technician_id', $technician->id);
                            });
                        });
                    });
            });
        })
        ->with([
            'customer' => function ($query) {
                $query->select('id', 'name', 'photo', 'is_verified', 'latitude', 'longitude', 'phone');
            },
            'serviceCategory:id,name,slug,icon',
            'district:id,name',
            'technician.serviceCategories',
        ]);
}

private function formatTechnicianActiveJobCard(Booking $booking): array
{
    $status = $this->normalizeBookingStatus($booking->status);
    $next = $this->nextJobStatus($status);
    $customerName = $booking->customer->name ?? 'Unknown Customer';

    return [
        'id' => $booking->id,
        'booking_reference' => $booking->booking_reference,
        'customer_name' => $customerName,
        'customer_initials' => $this->customerInitials($customerName),
        'customer_photo' => $this->photoUrl($booking->customer->photo ?? null),
        'service_category_id' => $booking->service_category_id,
        'service_category' => $this->resolveJobServiceCategoryName($booking),
        'service_details' => $booking->service_details,
        'additional_notes' => $booking->additional_notes,
        'status' => $status,
        'status_label' => $this->technicianStatusBadge($status),
        'address' => $this->formatJobAddress($booking),
        'date' => $this->formatJobDateLabel($booking),
        'time' => $this->formatTimeSlot12h($booking->time_slot),
        'scheduled_at' => $this->formatScheduledLabel($booking),
        'can_update_status' => true,
        'next_status' => $next['status'] ?? null,
        'next_status_label' => $next['label'] ?? null,
        'update_status_endpoint' => '/api/bookings/' . $booking->id . '/status',
    ];
}

private function formatTechnicianPendingRequestCard(Booking $booking, User $technician): array
{
    $customerName = $booking->customer->name ?? 'Unknown Customer';
    $isDirect = ($booking->booking_type ?? 'direct') !== 'broadcast';
    $individual = Schema::hasTable('booking_individual_offers')
        ? BookingIndividualOffer::where('booking_id', $booking->id)
            ->where('technician_id', $technician->id)
            ->whereIn('status', ['pending', 'technician_accepted'])
            ->latest()
            ->first()
        : null;
    $requestType = $this->resolvePendingRequestType($booking);
    $destLat = $booking->latitude ?? $booking->customer->latitude ?? null;
    $destLng = $booking->longitude ?? $booking->customer->longitude ?? null;
    $distanceKm = $this->googleMapsDistanceKm(
        $technician->latitude,
        $technician->longitude,
        $destLat,
        $destLng
    );
    $customerPhoto = $this->photoUrl($booking->customer->photo ?? null);
    $isVerified = (bool) ($booking->customer->is_verified ?? false);

    return [
        'id' => $booking->id,
        'booking_type' => $isDirect ? 'direct' : 'broadcast',
        'request_source' => $individual ? 'individual_select' : ($isDirect ? 'assigned_to_you' : 'service_category'),
        'is_individual_select' => (bool) $individual,
        'request_type' => $requestType,
        'request_type_label' => match ($requestType) {
            'emergency' => 'Emergency',
            'now' => 'Now',
            default => 'Later',
        },
        'is_emergency' => $requestType === 'emergency',
        'customer_name' => $customerName,
        'customer_initials' => $this->customerInitials($customerName),
        'customer_image' => $customerPhoto,
        'customer_photo' => $customerPhoto,
        'is_verified' => $isVerified,
        'verified_status' => $isVerified ? 'Verified Customer' : 'Customer',
        'service_category_id' => $booking->service_category_id,
        'service_category' => $this->resolveJobServiceCategoryName($booking),
        'service_details' => $booking->service_details,
        'description' => $booking->service_details ?? $booking->additional_notes ?? null,
        'distance' => $distanceKm !== null ? $distanceKm . ' km' : null,
        'distance_km' => $distanceKm,
        'address' => $this->formatJobAddress($booking),
        'requested_ago' => $this->formatRequestedAgo($booking->created_at),
        'date' => $this->formatJobDateLabel($booking),
        'time' => $this->formatTimeSlot12h($booking->time_slot),
        'scheduled_at' => $this->formatScheduledLabel($booking),
        'priority' => ucfirst($booking->emergency_level ?? 'low'),
        'expires_at' => $this->getExpiryTime($booking),
        'status' => $this->normalizeBookingStatus($booking->status),
        'can_accept' => true,
        'can_reject' => true,
        'accept_endpoint' => '/api/bookings/' . $booking->id . '/accept',
        'reject_endpoint' => '/api/bookings/' . $booking->id . '/reject',
    ];
}

private function customerInitials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'NA';
    }

    $initials = '';
    foreach (preg_split('/\s+/', $name) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'NA';
}

private function photoUrl(?string $photo): ?string
{
    if (!$photo) {
        return null;
    }

    if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
        return $photo;
    }

    return asset($photo);
}

private function formatJobAddress(Booking $booking): ?string
{
    $parts = array_filter([
        $booking->address ?: null,
        $booking->city ?: null,
        $booking->district->name ?? null,
    ]);

    return $parts ? implode(', ', $parts) : null;
}

private function formatJobDateLabel(Booking $booking): ?string
{
    $date = $booking->service_date ?? $booking->created_at;
    if (!$date) {
        return null;
    }

    $dateObj = Carbon::parse($date);
    if ($dateObj->isToday()) {
        return 'Today';
    }
    if ($dateObj->isTomorrow()) {
        return 'Tomorrow';
    }

    return $dateObj->format('M d, Y');
}

private function formatScheduledLabel(Booking $booking): ?string
{
    $dateLabel = $this->formatJobDateLabel($booking);
    $time = $this->formatTimeSlot12h($booking->time_slot);

    if ($dateLabel && $time) {
        return $dateLabel . ', ' . $time;
    }

    return $dateLabel ?: $time;
}

private function formatRequestedAgo($createdAt): ?string
{
    if (!$createdAt) {
        return null;
    }

    $carbon = Carbon::parse($createdAt);
    $minutes = (int) $carbon->diffInMinutes(now());

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

    $days = (int) $carbon->diffInDays(now());

    return $days === 1 ? '1 day ago' : $days . ' days ago';
}

private function technicianStatusBadge(string $status): string
{
    return match ($this->normalizeBookingStatus($status)) {
        'accepted' => 'Accepted',
        'on_the_way' => 'On the Way',
        'work_started' => 'Work Started',
        'work_in_progress' => 'Work In Progress',
        'pending' => 'Pending',
        'completed' => 'Completed',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

private function nextJobStatus(string $status): ?array
{
    return match ($this->normalizeBookingStatus($status)) {
        'accepted' => ['status' => 'on_the_way', 'label' => 'On the Way'],
        'on_the_way' => ['status' => 'work_started', 'label' => 'Work Started'],
        'work_started' => ['status' => 'work_in_progress', 'label' => 'Work In Progress'],
        'work_in_progress' => ['status' => 'completed', 'label' => 'Completed'],
        default => null,
    };
}

private function resolvePendingRequestType(Booking $booking): string
{
    if (strtolower((string) ($booking->emergency_level ?? '')) === 'emergency') {
        return 'emergency';
    }

    if ($booking->isBroadcast()) {
        return 'now';
    }

    $date = $booking->service_date ?: $booking->created_at;
    if ($date && Carbon::parse($date)->isToday()) {
        return 'now';
    }

    return 'later';
}

private function applyPendingRequestTypeFilter($query, string $filter)
{
    if ($filter === 'all') {
        return $query;
    }

    if ($filter === 'emergency') {
        return $query->where('emergency_level', 'emergency');
    }

    $query->where(function ($q) {
        $q->whereNull('emergency_level')
            ->orWhere('emergency_level', '!=', 'emergency');
    });

    if ($filter === 'now') {
        return $query->where(function ($q) {
            $q->where('booking_type', 'broadcast')
                ->orWhereDate('service_date', Carbon::today());
        });
    }

    return $query->where(function ($q) {
        $q->whereNull('booking_type')
            ->orWhere('booking_type', '!=', 'broadcast');
    })->whereDate('service_date', '>', Carbon::today());
}

private function googleMapsDistanceKm($lat1, $lon1, $lat2, $lon2): ?float
{
    return app(GoogleDistanceService::class)->distanceKm($lat1, $lon1, $lat2, $lon2);
}

/**
 * Calculate distance between two coordinates using Haversine formula
 */
private function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    return app(GoogleDistanceService::class)->distanceKm($lat1, $lon1, $lat2, $lon2);
}

/**
 * Get expiry time from expires_at
 */
private function getExpiryTime($booking)
{
    if (empty($booking->expires_at)) {
        return null;
    }

    $expires = \Carbon\Carbon::parse($booking->expires_at);
    
    // Check if already expired
    if (now()->gt($expires)) {
        return 'Expired';
    }

    // Return the expiry time in 12-hour format
    return $expires->format('g:i A');
}

private function parseRequestedBookingDateTime($date, $time): ?Carbon
{
    if (!$date || $time === null || $time === '') {
        return null;
    }

    $dateString = trim((string) $date);
    $timeString = trim((string) $time);

    try {
        $combined = Carbon::parse(trim($dateString . ' ' . $timeString));
        if ($combined) {
            return $combined;
        }
    } catch (\Throwable $e) {
        // fall through to stricter parsing
    }

    try {
        $datePart = Carbon::parse($dateString)->toDateString();
    } catch (\Throwable $e) {
        return null;
    }

    $parsedTime = null;
    foreach (['g:i A', 'g:i a', 'h:i A', 'h:i a', 'H:i:s', 'H:i', 'g:iA', 'h:iA'] as $format) {
        try {
            $parsedTime = Carbon::createFromFormat('!'.$format, $timeString);
            if ($parsedTime) {
                break;
            }
        } catch (\Throwable $e) {
            $parsedTime = null;
        }
    }

    if (!$parsedTime) {
        try {
            $parsedTime = Carbon::parse($timeString);
        } catch (\Throwable $e) {
            return null;
        }
    }

    try {
        return Carbon::parse($datePart . ' ' . $parsedTime->format('H:i:s'));
    } catch (\Throwable $e) {
        return null;
    }
}

private function technicianHasScheduledSlotConflict(User $technician, Booking $booking): bool
{
    // Broadcast / on-demand requests are "now" jobs, not reserved calendar slots.
    if ($booking->isBroadcast()) {
        return false;
    }

    if (!$booking->service_date || !$booking->time_slot) {
        return false;
    }

    $serviceDate = Carbon::parse($booking->service_date)->toDateString();
    $timeSlot = Carbon::parse($booking->time_slot)->format('H:i:s');

    return Booking::where('technician_id', $technician->id)
        ->where('id', '!=', $booking->id)
        ->whereDate('service_date', $serviceDate)
        ->whereRaw('TIME(time_slot) = ?', [$timeSlot])
        ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress'])
        ->where(function ($query) {
            $query->whereNull('booking_type')
                ->orWhere('booking_type', 'direct');
        })
        ->exists();
}

    // Technician: Accept a booking request
    public function acceptBooking(Request $request, $bookingId)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json(['error' => 'Only technicians can accept bookings'], 403);
        }

        if (!$technician->isAccountActivated()) {
            return response()->json([
                'success' => false,
                'error' => 'Activate your account with a subscription to receive and accept bookings.',
            ], 403);
        }

        app(BroadcastRadiusService::class)->ensureTables();

    $booking = DB::transaction(function () use ($bookingId, $technician) {
        $booking = Booking::where('id', $bookingId)->lockForUpdate()->first();

        if (!$booking) {
            return null;
        }

        if ($booking->isBroadcast()) {
            if (!$this->technicianCanViewBroadcast($technician, $booking)) {
                return 'forbidden';
            }
            if ($this->hasTechnicianRejectedBooking($technician, (int) $booking->id)) {
                return 'forbidden';
            }
        } elseif ((int) $booking->technician_id !== (int) $technician->id) {
            return null;
        }

        if ($booking->status === 'expired') {
            return 'expired';
        }

        if ($booking->status !== 'pending') {
            return 'processed';
        }

        if ($booking->expires_at && !$booking->isBroadcast() && Carbon::now()->greaterThan($booking->expires_at)) {
            $booking->update(['status' => 'expired']);
            return 'expired';
        }

        if ($booking->isBroadcast() && $booking->technician_id) {
            return 'taken';
        }

        if ($this->technicianHasScheduledSlotConflict($technician, $booking)) {
            return 'slot_unavailable';
        }

        if ($booking->isBroadcast()) {
            $offer = BookingIndividualOffer::where('booking_id', $booking->id)
                ->where('technician_id', $technician->id)
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($offer) {
                $offer->update(['status' => 'technician_accepted']);
                return ['awaiting_customer_approval', $booking->fresh()];
            }
        }

        $referenceCode = $booking->booking_reference ?: BookingReference::generate();

        $booking->update([
            'technician_id' => $technician->id,
            'status' => 'accepted',
            'accepted_at' => now(),
            'booking_reference' => $referenceCode,
            'expires_at' => null,
        ]);

        return $booking->fresh();
    });

    if ($booking === null) {
        return response()->json(['error' => 'Booking not found for this technician'], 404);
    }

    if ($booking === 'forbidden') {
        return response()->json(['error' => 'This broadcast request is not available for you'], 403);
    }

    if ($booking === 'expired') {
        return response()->json([
            'success' => false,
            'message' => 'This request has expired. Customer must create a new request.',
        ], 410);
    }

    if ($booking === 'processed') {
        return response()->json([
            'error' => 'Booking already processed',
        ], 409);
    }

    if ($booking === 'taken') {
        return response()->json([
            'success' => false,
            'message' => 'Another technician already accepted this request.',
        ], 409);
    }

    if ($booking === 'slot_unavailable') {
        return response()->json([
            'success' => false,
            'error' => 'This time slot is no longer available',
            'message' => 'You already have another scheduled job at this date and time.',
        ], 400);
    }

    if (is_array($booking) && ($booking[0] ?? null) === 'awaiting_customer_approval') {
        $offerBooking = $booking[1];
        $customer = $offerBooking->customer;
        if ($customer) {
            app(BroadcastRadiusService::class)->notifyUser(
                $customer,
                ($technician->name ?? 'Technician') . ' is accepting your request',
                'Do you want to approve this technician?',
                [
                    'booking_id' => $offerBooking->id,
                    'technician_id' => $technician->id,
                    'type' => 'individual_acceptance',
                    'show_approval_popup' => true,
                ],
                'individual_acceptance'
            );
        }
        app(BroadcastRadiusService::class)->pushScreen($offerBooking, 'technician_awaiting_approval');

        return response()->json([
            'success' => true,
            'awaiting_customer_approval' => true,
            'message' => 'An approval popup has been sent to the customer.',
            'booking_id' => $offerBooking->id,
        ]);
    }

        $booking->load(['customer', 'technician', 'serviceCategory', 'district']);

        $this->notifyCustomer($booking->customer, $booking, 'accepted');

        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'pre-fix', 'hypothesisId' => 'E', 'location' => 'BookingController::acceptBooking', 'message' => 'broadcast locked by technician accept', 'data' => ['booking_id' => $booking->id, 'technician_id' => $booking->technician_id, 'is_broadcast' => $booking->isBroadcast()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

    if ($booking->isBroadcast()) {
        app(BroadcastRadiusService::class)->pushScreen($booking, 'locked');
    }

    $booking->load(['customer', 'technician', 'serviceCategory', 'district']);

    return response()->json([
        'success' => true,
        'message' => 'Booking confirmed successfully',
        'booking' => $this->transformAcceptedBooking($booking),
        'booking_reference' => $booking->booking_reference,
    ]);
}

/**
 * Transform accepted booking response to match the image format
 */
private function transformAcceptedBooking($booking)
{
    // Get customer initials
    $customerName = $booking->customer->name ?? 'Unknown Customer';
    $nameParts = explode(' ', $customerName);
    $initials = '';
    foreach ($nameParts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    $initials = substr($initials, 0, 2);

    $serviceCategory = $this->resolveJobServiceCategoryName($booking);
    $status = $this->normalizeBookingStatus($booking->status);
    $next = $this->nextJobStatus($status);

    // Build full address
    $fullAddress = $this->formatJobAddress($booking);

    return [
        'id' => $booking->id,
        'booking_reference' => $booking->booking_reference,
        'customer' => [
            'id' => $booking->customer?->id,
            'name' => $customerName,
            'initials' => $this->customerInitials($customerName),
            'photo' => $this->photoUrl($booking->customer->photo ?? null),
            'is_verified' => (bool) ($booking->customer->is_verified ?? false),
            'phone' => $booking->phone ?? $booking->customer->phone ?? null,
        ],
        'customer_name' => $customerName,
        'customer_initials' => $this->customerInitials($customerName),
        'customer_photo' => $this->photoUrl($booking->customer->photo ?? null),
        'is_verified' => (bool) ($booking->customer->is_verified ?? false),
        'phone' => $booking->phone ?? $booking->customer->phone ?? null,
        'service_category' => $serviceCategory,
        'service_details' => $booking->service_details,
        'address' => $fullAddress ?: null,
        'service' => [
            'category' => $serviceCategory,
            'category_id' => $booking->service_category_id,
            'description' => $booking->service_details ?? $booking->additional_notes ?? null,
            'service_details' => $booking->service_details,
        ],
        'job_progress' => [
            'status' => $status,
            'status_label' => $this->technicianStatusBadge($status),
            'accepted_at' => $booking->accepted_at ? Carbon::parse($booking->accepted_at)->format('g:i A') : null,
            'on_the_way_at' => $booking->on_the_way_at ? Carbon::parse($booking->on_the_way_at)->format('g:i A') : null,
            'work_started_at' => $booking->work_started_at ? Carbon::parse($booking->work_started_at)->format('g:i A') : null,
            'work_in_progress_at' => $booking->work_in_progress_at ? Carbon::parse($booking->work_in_progress_at)->format('g:i A') : null,
            'completed_at' => $booking->completed_at ? Carbon::parse($booking->completed_at)->format('g:i A') : null,
        ],
        'booking_details' => [
            'service_date' => $booking->service_date ? Carbon::parse($booking->service_date)->format('M d, Y') : null,
            'time_slot' => $booking->time_slot ? Carbon::parse($booking->time_slot)->format('g:i A') : null,
            'emergency_level' => ucfirst($booking->emergency_level ?? 'low'),
            'created_at' => $booking->created_at ? Carbon::parse($booking->created_at)->format('g:i A') : null,
        ],
        'status' => $status,
        'current_status' => $status,
        'current_status_label' => $this->technicianStatusBadge($status),
        'next_status' => $next['status'] ?? null,
        'next_status_label' => $next['label'] ?? null,
    ];
}

    // Technician: Reject a booking request (direct + broadcast)
    public function rejectBooking(Request $request, $bookingId)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json(['error' => 'Only technicians can reject bookings'], 403);
        }

        $request->validate([
            'reason' => 'nullable|string',
        ]);

        $booking = Booking::where('id', $bookingId)->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found for this technician'], 404);
        }

        if ($booking->isBroadcast()) {
            $hasMatchingCategory = $booking->service_category_id
                && $technician->serviceCategories()
                    ->where('service_categories.id', $booking->service_category_id)
                    ->exists();

            if (!$hasMatchingCategory) {
                return response()->json(['error' => 'Booking not found for this technician'], 404);
            }

            if ($booking->status === 'expired') {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has already expired.',
                ], 410);
            }

            if ($booking->status !== 'pending' || $booking->technician_id) {
                return response()->json([
                    'error' => 'Booking already processed',
                    'status' => $booking->status,
                ], 409);
            }

            $this->recordTechnicianBookingRejection(
                $technician,
                $booking,
                $request->reason ?? 'Rejected by technician'
            );

            if (Schema::hasTable('booking_individual_offers')) {
                $hadIndividual = BookingIndividualOffer::where('booking_id', $booking->id)
                    ->where('technician_id', $technician->id)
                    ->whereIn('status', ['pending', 'technician_accepted'])
                    ->exists();

                BookingIndividualOffer::where('booking_id', $booking->id)
                    ->where('technician_id', $technician->id)
                    ->whereIn('status', ['pending', 'technician_accepted'])
                    ->update(['status' => 'technician_rejected']);
            } else {
                $hadIndividual = false;
            }

            $customer = $booking->customer;
            if ($customer && $hadIndividual) {
                app(BroadcastRadiusService::class)->notifyUser(
                    $customer,
                    'A technician rejected your request',
                    ($technician->name ?? 'Technician') . ' rejected your individual request. The broadcast is still open.',
                    [
                        'booking_id' => $booking->id,
                        'technician_id' => $technician->id,
                        'type' => 'individual_rejected',
                    ],
                    'individual_rejected'
                );
            }

            // #region agent log
            @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'pre-fix', 'hypothesisId' => 'D', 'location' => 'BookingController::rejectBooking', 'message' => 'broadcast reject', 'data' => ['booking_id' => $booking->id, 'technician_id' => $technician->id, 'had_individual' => (bool) $hadIndividual, 'customer_notified' => (bool) ($customer && $hadIndividual)], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion

            app(BroadcastRadiusService::class)->pushScreen($booking, 'technician_rejected');

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected',
            ]);
        }

        if ((int) $booking->technician_id !== (int) $technician->id) {
            return response()->json(['error' => 'Booking not found for this technician'], 404);
        }

        if ($booking->status === 'expired') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already expired.',
            ], 410);
        }

        if ($booking->status !== 'pending') {
            return response()->json([
                'error' => 'Booking already processed',
                'status' => $booking->status,
            ], 409);
        }

        $booking->update([
            'status' => 'rejected',
            'cancellation_reason' => $request->reason ?? 'Rejected by technician',
            'expires_at' => null,
        ]);

        $this->notifyCustomer($booking->customer, $booking, 'rejected');

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected',
        ]);
    }

    // Customer: Get my bookings
    public function myBookings(Request $request)
    {
        if ($request->user()?->user_type === 'technician') {
            return $this->technicianBookingHistory($request);
        }

        $customer = $request->user();

        $this->expirePendingBookingsQuery(
            Booking::where('customer_id', $customer->id)
        );

        Booking::where('customer_id', $customer->id)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '');
            })
            ->update(['status' => 'pending']);

        $filter = $this->resolveBookingsFilter($request);
        $filterStatuses = $this->bookingFilterStatuses($filter);

        $baseQuery = Booking::where('customer_id', $customer->id);

        $filterCounts = [
            'all' => (clone $baseQuery)->count(),
            'upcoming' => (clone $baseQuery)->whereIn('status', ['pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress'])->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled' => (clone $baseQuery)->whereIn('status', ['cancelled', 'rejected'])->count(),
        ];

        $bookingsQuery = Booking::where('customer_id', $customer->id)
            ->when($filterStatuses !== null, function ($query) use ($filterStatuses) {
                return $query->whereIn('status', $filterStatuses);
            })
            ->with(['technician.serviceCategories', 'serviceCategory', 'district'])
            ->orderBy('created_at', 'desc');

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'booking-filters', 'hypothesisId' => 'H-FILTER-PARAM', 'location' => 'BookingController::myBookings', 'message' => 'filter applied', 'data' => ['raw_filters' => $request->input('filters'), 'raw_filter' => $request->input('filter'), 'raw_status' => $request->input('status'), 'resolved' => $filter, 'filter_statuses' => $filterStatuses], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        $bookings = $bookingsQuery
            ->paginate(20)
            ->appends($request->query())
            ->through(function ($booking) use ($filter) {
                $card = $this->formatBookingCardForCustomer($booking);

                if ($card['status'] === 'expired' && $booking->isBroadcast()) {
                    $card['status_message'] = 'Your request is expired. Please broadcast a new request.';
                }

                // #region agent log
                @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'booking-filters', 'hypothesisId' => 'H-FILTER-PARAM', 'location' => 'BookingController::myBookings', 'message' => 'booking card formatted', 'data' => ['filter' => $filter, 'booking_id' => $booking->id, 'status' => $card['status'], 'status_label' => $card['status_label']], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
                // #endregion

                return $card;
            });

        return response()->json([
            'success' => true,
            'message' => $filter === 'expired'
                ? 'Your request is expired. Please broadcast a new request.'
                : null,
            'response_format' => 'booking_cards_v1',
            'filter' => $filter,
            'filters' => $filterCounts,
            'total_bookings' => $bookings->total(),
            'data' => $bookings,
        ]);
    }

    // Customer: Cancel booking
    public function cancelBooking(Request $request, $bookingId)
    {
        $customer = $request->user();

        $request->validate([
            'reason' => 'nullable|string'
        ]);

        $booking = Booking::where('id', $bookingId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if (!in_array($booking->status, ['pending', 'accepted'], true)) {
            return response()->json([
                'error' => 'Booking cannot be cancelled',
                'status' => $booking->status,
            ], 409);
        }

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->reason ?? 'Cancelled by customer',
            'expires_at' => null,
        ]);

        if ($booking->technician) {
        $this->notifyTechnician($booking->technician, $booking, 'cancelled');
        }

        if ($booking->isBroadcast()) {
            $radiusService = app(BroadcastRadiusService::class);
            $radiusService->ensureTables();
            $notifiedIds = BookingBroadcastNotified::where('booking_id', $booking->id)->pluck('technician_id');
            foreach (User::whereIn('id', $notifiedIds)->get() as $tech) {
                $radiusService->notifyUser(
                    $tech,
                    'Request cancelled',
                    'The customer cancelled the broadcast request.',
                    ['booking_id' => $booking->id, 'type' => 'broadcast_cancelled']
                );
            }
            $radiusService->pushScreen($booking, 'cancelled');
        }

        return response()->json([
            'success' => true,
            'message' => $booking->isBroadcast()
                ? 'Broadcast request cancelled successfully'
                : 'Booking cancelled successfully',
        ]);
    }

    // Technician: Request completion OTP (customer must verify before job completes)
    public function completeBooking(Request $request, $bookingId)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json(['error' => 'Only technicians can complete bookings'], 403);
        }

        $booking = Booking::with('customer')
            ->where('id', $bookingId)
            ->where('technician_id', $technician->id)
            ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found or not in completable status'], 404);
        }

        $result = $this->requestCompletionOtp($booking, $technician);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    // Technician: Verify customer OTP and complete booking
    public function verifyCompletionOtp(Request $request, $bookingId)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json(['error' => 'Only technicians can verify completion OTP'], 403);
        }

        $booking = Booking::with('customer')
            ->where('id', $bookingId)
            ->where('technician_id', $technician->id)
            ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found or cannot be completed'], 404);
        }

        if (!$booking->completion_otp) {
            return response()->json([
                'error' => 'No completion OTP was requested. Tap Complete Job first.',
            ], 422);
        }

        if ($booking->completion_otp_expires_at && now()->greaterThan($booking->completion_otp_expires_at)) {
            return response()->json([
                'error' => 'Completion OTP has expired. Please request a new one.',
            ], 422);
        }

        if ($booking->completion_otp !== $request->otp) {
            return response()->json(['error' => 'Invalid OTP'], 400);
        }

        $booking->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completion_otp' => null,
            'completion_otp_expires_at' => null,
        ]);

        $this->notifyCustomer($booking->customer, $booking, 'completed');
        $this->notifyTechnician($technician, $booking, 'completed');

        return response()->json([
            'success' => true,
            'message' => 'Booking completed successfully.',
        ]);
    }

    // Technician: Update intermediate status
    public function updateStatus(Request $request, $bookingId)
    {
        if (!$request->filled('status')) {
            return $this->getBookingStatus($request, $bookingId);
        }

        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json(['error' => 'Only technicians can update status'], 403);
        }

        $request->validate([
        'status' => 'required|in:on_the_way,work_started,work_in_progress,completed'
        ]);

    $booking = Booking::with(['customer', 'technician', 'serviceCategory', 'district'])
        ->where('id', $bookingId)
            ->where('technician_id', $technician->id)
        ->whereIn('status', ['accepted', 'on_the_way', 'work_started', 'work_in_progress'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found or cannot be updated'], 404);
        }

    // Prevent going back to previous status
    $statusFlow = ['pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress', 'completed'];
    $currentIndex = array_search($booking->status, $statusFlow);
    $newIndex = array_search($request->status, $statusFlow);
    
    if ($newIndex <= $currentIndex) {
        return response()->json([
            'error' => 'Cannot move to previous status. Current status: ' . $booking->status
        ], 422);
    }

        $updateData = ['status' => $request->status];
    
    // Set timestamps based on status
        if ($request->status == 'on_the_way') {
            $updateData['on_the_way_at'] = now();
        } elseif ($request->status == 'work_started') {
            $updateData['work_started_at'] = now();
        } elseif ($request->status == 'work_in_progress') {
            $updateData['work_in_progress_at'] = now();
    } elseif ($request->status == 'completed') {
        $result = $this->requestCompletionOtp($booking, $technician);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        }

        $booking->update($updateData);
        $booking->refresh()->load(['customer', 'technician', 'serviceCategory', 'district']);

        // Notify customer
        $this->notifyCustomer($booking->customer, $booking, $request->status);

        $payload = $this->transformAcceptedBooking($booking);
        $timeline = $this->buildTechnicianJobProgressTimeline($booking);
        $confirmAction = $this->jobProgressConfirmAction($booking);

        return response()->json([
            'success' => true,
            'message' => 'Booking status updated to ' . str_replace('_', ' ', $request->status),
            'current_status' => $payload['current_status'],
            'current_status_label' => $payload['current_status_label'],
            'customer_name' => $payload['customer_name'],
            'address' => $payload['address'],
            'service_category' => $payload['service_category'],
            'service_details' => $payload['service_details'],
            'booking' => $payload,
            'booking_reference' => $booking->booking_reference,
            'job_progress' => $timeline,
            'service_progress' => $timeline,
            'confirm_action' => $confirmAction,
        ]);
    }

    public function getTechnicianJobStatus(Request $request, $bookingId)
    {
        $technician = $request->user();

        if ($technician->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view job status.',
            ], 403);
        }

        $booking = Booking::query()
            ->where('technician_id', $technician->id)
            ->where(function ($query) use ($bookingId) {
                $query->where('id', $bookingId)
                    ->orWhere('booking_reference', $bookingId)
                    ->orWhere('booking_reference', ltrim((string) $bookingId, '#'));
            })
            ->with(['customer', 'technician', 'serviceCategory', 'district'])
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found for this technician.',
            ], 404);
        }

        $payload = $this->transformAcceptedBooking($booking);
        $timeline = $this->buildTechnicianJobProgressTimeline($booking);
        $confirmAction = $this->jobProgressConfirmAction($booking);

        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'tech-job-status', 'hypothesisId' => 'A', 'location' => 'BookingController::getTechnicianJobStatus', 'message' => 'technician job status get', 'data' => ['booking_id' => $booking->id, 'status' => $booking->status, 'customer_name' => $payload['customer_name'] ?? null, 'address' => $payload['address'] ?? null, 'service_details' => $payload['service_details'] ?? null], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => 'Technician job status retrieved successfully.',
            'current_status' => $payload['current_status'],
            'current_status_label' => $payload['current_status_label'],
            'next_status' => $payload['next_status'],
            'next_status_label' => $payload['next_status_label'],
            'customer_name' => $payload['customer_name'],
            'customer_initials' => $payload['customer_initials'],
            'customer_photo' => $payload['customer_photo'],
            'is_verified' => $payload['is_verified'],
            'phone' => $payload['phone'],
            'address' => $payload['address'],
            'service_category' => $payload['service_category'],
            'service_details' => $payload['service_details'],
            'booking' => $payload,
            'booking_reference' => $booking->booking_reference,
            'job_progress' => $timeline,
            'service_progress' => $timeline,
            'confirm_action' => $confirmAction,
            'automatic_notifications' => [
                'title' => 'Automatic Notifications',
                'body' => 'Customer will be notified automatically when you update your status.',
            ],
        ]);
    }

    public function getBookingStatus(Request $request, $bookingId)
    {
        $user = $request->user();

        $bookingQuery = Booking::query()->where(function ($query) use ($user) {
            $query->where('customer_id', $user->id)
                ->orWhere('technician_id', $user->id);
        });

        $booking = $bookingQuery->where(function ($query) use ($bookingId) {
                $query->where('id', $bookingId)
                    ->orWhere('booking_reference', $bookingId)
                    ->orWhere('booking_reference', ltrim((string) $bookingId, '#'));
            })
            ->with([
                'customer',
                'technician.serviceCategories',
                'technician.subscriptionPlan',
                'serviceCategory',
                'district',
            ])
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        }

        if (!$booking->isBroadcast() && $booking->status === 'pending' && $booking->expires_at && Carbon::now()->greaterThan($booking->expires_at)) {
            $booking->update(['status' => 'expired']);
            $booking->refresh();
        }

        $payload = $this->transformAcceptedBooking($booking);
        $timeline = $this->buildTechnicianJobProgressTimeline($booking);
        $confirmAction = $this->jobProgressConfirmAction($booking);

        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'status-api', 'hypothesisId' => 'A,B', 'location' => 'BookingController::getBookingStatus', 'message' => 'status payload fields', 'data' => ['booking_id' => $booking->id, 'user_type' => $user->user_type, 'status' => $booking->status, 'has_customer' => (bool) $booking->customer, 'customer_name' => $payload['customer_name'] ?? null, 'address' => $payload['address'] ?? null, 'service_details' => $payload['service_details'] ?? null, 'timeline_count' => count($timeline), 'confirm_status' => $confirmAction['status'] ?? null], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => 'Booking status retrieved successfully.',
            'current_status' => $payload['current_status'],
            'current_status_label' => $payload['current_status_label'],
            'next_status' => $payload['next_status'],
            'next_status_label' => $payload['next_status_label'],
            'customer_name' => $payload['customer_name'],
            'customer_initials' => $payload['customer_initials'],
            'customer_photo' => $payload['customer_photo'],
            'is_verified' => $payload['is_verified'],
            'phone' => $payload['phone'],
            'address' => $payload['address'],
            'service_category' => $payload['service_category'],
            'service_details' => $payload['service_details'],
            'booking' => $payload,
            'booking_reference' => $booking->booking_reference,
            'job_progress' => $timeline,
            'service_progress' => $timeline,
            'confirm_action' => $confirmAction,
            'automatic_notifications' => [
                'title' => 'Automatic Notifications',
                'body' => 'Customer will be notified automatically when you update your status.',
            ],
        ]);
    }

    private function buildTechnicianJobProgressTimeline(Booking $booking): array
    {
        $status = $this->normalizeBookingStatus($booking->status);
        $acceptedAt = $booking->accepted_at ? Carbon::parse($booking->accepted_at)->format('g:i A') : null;

        $steps = [
            [
                'key' => 'accepted',
                'title' => 'Request Accepted',
                'icon' => 'check',
                'done' => in_array($status, ['accepted', 'on_the_way', 'work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $status === 'accepted',
                'description' => $acceptedAt
                    ? 'You accepted the job at ' . $acceptedAt
                    : 'Waiting for this job to be accepted',
            ],
            [
                'key' => 'on_the_way',
                'title' => 'On the Way',
                'icon' => 'send',
                'done' => in_array($status, ['on_the_way', 'work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $status === 'accepted',
                'description' => 'Confirm when you\'re heading to the customer location',
                'action_label' => $status === 'accepted' ? 'Confirm: On the Way' : null,
            ],
            [
                'key' => 'work_started',
                'title' => 'Work Started',
                'icon' => 'wrench',
                'done' => in_array($status, ['work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $status === 'on_the_way',
                'description' => 'Mark when you begin the service at the location',
                'action_label' => $status === 'on_the_way' ? 'Confirm: Work Started' : null,
            ],
            [
                'key' => 'work_in_progress',
                'title' => 'Work in Progress',
                'icon' => 'hammer',
                'done' => in_array($status, ['work_in_progress', 'completed'], true),
                'is_current' => $status === 'work_started',
                'description' => 'Update progress and add notes during the job',
                'action_label' => $status === 'work_started' ? 'Confirm: Work in Progress' : null,
            ],
            [
                'key' => 'completed',
                'title' => 'Completed',
                'icon' => 'check-circle',
                'done' => $status === 'completed',
                'is_current' => $status === 'work_in_progress',
                'description' => 'Finalize the job and request customer review',
                'action_label' => $status === 'work_in_progress' ? 'Confirm: Completed' : null,
                'email_on_complete' => true,
                'email_note' => 'An OTP email will be sent to the customer to verify completion.',
            ],
        ];

        foreach ($steps as &$step) {
            if ($step['done']) {
                $step['status'] = 'Done';
            } elseif ($step['is_current']) {
                $step['status'] = 'Current';
            } else {
                $step['status'] = 'Pending';
            }
        }
        unset($step);

        return $steps;
    }

    private function jobProgressConfirmAction(Booking $booking): ?array
    {
        $status = $this->normalizeBookingStatus($booking->status);
        $next = $this->nextJobStatus($status);

        if (!$next) {
            return null;
        }

        $labels = [
            'on_the_way' => 'Confirm: On the Way',
            'work_started' => 'Confirm: Work Started',
            'work_in_progress' => 'Confirm: Work in Progress',
            'completed' => 'Confirm: Completed',
        ];

        return [
            'available' => true,
            'label' => $labels[$next['status']] ?? ('Confirm: ' . $next['label']),
            'status' => $next['status'],
            'method' => 'POST',
            'endpoint' => '/api/bookings/' . $booking->id . '/status',
            'body' => ['status' => $next['status']],
            'email_on_complete' => $next['status'] === 'completed',
            'email_note' => $next['status'] === 'completed'
                ? 'An OTP email will be sent to the customer to verify completion.'
                : null,
        ];
    }

    private function buildServiceProgressTimeline(Booking $booking): array
    {
        $technicianName = $booking->technician->name ?? 'Technician';

        return [
            [
                'key' => 'request_sent',
                'title' => 'Request Sent',
                'status' => 'Done',
                'time' => $booking->created_at ? $booking->created_at->format('h:i A') : null,
                'description' => null,
                'is_active' => in_array($booking->status, ['pending', 'accepted', 'on_the_way', 'work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $booking->status === 'pending',
            ],
            [
                'key' => 'accepted',
                'title' => 'Technician Accepted',
                'status' => $booking->accepted_at ? 'Done' : 'Pending',
                'time' => $booking->accepted_at ? Carbon::parse($booking->accepted_at)->format('h:i A') : null,
                'description' => $booking->accepted_at ? $technicianName . ' accepted your request' : null,
                'is_active' => in_array($booking->status, ['accepted', 'on_the_way', 'work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $booking->status === 'accepted',
            ],
            [
                'key' => 'on_the_way',
                'title' => 'On the Way',
                'status' => $booking->on_the_way_at ? 'Done' : ($booking->status === 'accepted' ? 'In Progress' : 'Pending'),
                'time' => $booking->on_the_way_at ? Carbon::parse($booking->on_the_way_at)->format('h:i A') : null,
                'description' => $booking->status === 'accepted' && !$booking->on_the_way_at ? 'Waiting for technician to start travel' : null,
                'is_active' => in_array($booking->status, ['on_the_way', 'work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $booking->status === 'on_the_way',
            ],
            [
                'key' => 'work_started',
                'title' => 'Work Started',
                'status' => $booking->work_started_at ? 'Done' : ($booking->status === 'on_the_way' ? 'In Progress' : 'Pending'),
                'time' => $booking->work_started_at ? Carbon::parse($booking->work_started_at)->format('h:i A') : null,
                'description' => $booking->work_started_at ? null : 'Pending technician arrival',
                'is_active' => in_array($booking->status, ['work_started', 'work_in_progress', 'completed'], true),
                'is_current' => $booking->status === 'work_started',
            ],
            [
                'key' => 'work_in_progress',
                'title' => 'Work In Progress',
                'status' => $booking->work_in_progress_at || $booking->status === 'work_in_progress' ? 'Done' : ($booking->status === 'work_started' ? 'In Progress' : 'Pending'),
                'time' => $booking->work_in_progress_at ? Carbon::parse($booking->work_in_progress_at)->format('h:i A') : null,
                'description' => $booking->status === 'work_started' && !$booking->work_in_progress_at ? 'Technician is on site' : null,
                'is_active' => in_array($booking->status, ['work_in_progress', 'completed'], true),
                'is_current' => $booking->status === 'work_in_progress',
            ],
            [
                'key' => 'completed',
                'title' => 'Completed',
                'status' => $booking->completed_at || $booking->status === 'completed' ? 'Done' : 'Pending',
                'time' => $booking->completed_at ? Carbon::parse($booking->completed_at)->format('h:i A') : null,
                'description' => ($booking->completed_at || $booking->status === 'completed') ? null : 'Service completion pending',
                'is_active' => $booking->status === 'completed',
                'is_current' => $booking->status === 'completed',
            ],
        ];
    }

    // Customer: Get single booking details
    public function getBookingDetails(Request $request, $bookingId)
    {
        $user = $request->user();

        $booking = Booking::where('id', $bookingId)
            ->where(function ($query) use ($user) {
                $query->where('customer_id', $user->id)
                    ->orWhere('technician_id', $user->id)
                    ->orWhere(function ($broadcastQuery) use ($user) {
                        if ($user->user_type === 'technician') {
                            $broadcastQuery->where('booking_type', 'broadcast')
                                ->whereNull('technician_id')
                                ->where('status', 'pending');
                        }
                    });
            })
            ->with(['customer', 'technician', 'serviceCategory', 'district'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if ($user->user_type === 'technician'
            && $booking->isBroadcast()
            && !$booking->technician_id
            && !$this->technicianCanViewBroadcast($user, $booking)) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if (!$booking->isBroadcast() && $booking->status === 'pending' && $booking->expires_at && Carbon::now()->greaterThan($booking->expires_at)) {
            $booking->update(['status' => 'expired']);
            $booking->refresh();
        }

        $timeline = $this->buildServiceProgressTimeline($booking);

        return response()->json([
            'success' => true,
            'data' => $booking,
            'service_progress' => $timeline,
            'broadcast' => $booking->isBroadcast() && $booking->service_category_id ? [
                'technicians_notified' => $this->matchingTechniciansQuery(
                    (int) $booking->service_category_id,
                    $booking->district_id ? (int) $booking->district_id : null
                )->count(),
                'status_message' => $booking->status === 'expired'
                    ? 'Your request is expired. Please broadcast a new request.'
                    : null,
            ] : null,
        ]);
    }

    // Helper: Check technician availability
    private function isTechnicianAvailable($technicianId, $date, $timeSlot, $emergencyLevel = null, $technician = null)
    {
        $debug = [
            'technician_id' => $technicianId,
            'date' => $date,
            'time_slot' => $timeSlot,
            'emergency_level' => $emergencyLevel,
        ];

        // Check if technician has any accepted booking at that time
        $conflictingBooking = Booking::where('technician_id', $technicianId)
            ->where('service_date', $date)
            ->where('time_slot', $timeSlot)
            ->whereIn('status', ['accepted', 'pending'])
            ->exists();

        if ($conflictingBooking) {
            $debug['reason'] = 'conflicting_booking';
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H1', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability rejected', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        try {
            $parsedDate = Carbon::parse($date);
        } catch (\Throwable $e) {
            $debug['reason'] = 'invalid_date';
            $debug['error'] = $e->getMessage();
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H2', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability rejected', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        $day = strtolower($parsedDate->format('l'));
        $debug['day'] = $day;

        $availability = DB::table('technician_availability')
            ->where('technician_id', $technicianId)
            ->where('day', $day)
            ->where('is_available', true)
            ->whereNull('specific_date')
            ->first();

        if (!$availability) {
            $debug['reason'] = 'no_weekly_schedule';
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H3', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability rejected', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        $debug['start_time'] = $availability->start_time;
        $debug['end_time'] = $availability->end_time;

        $emergency = strtolower(trim((string) $emergencyLevel));
        $isImmediateRequest = in_array($emergency, ['now', 'immediate', 'emergency'], true);
        $isCurrentlyOnline = $technician
            && Schema::hasColumn('users', 'currently_available')
            && (bool) ($technician->currently_available ?? false);

        $debug['is_immediate_request'] = $isImmediateRequest;
        $debug['currently_available'] = $isCurrentlyOnline;

        if ($isImmediateRequest && $isCurrentlyOnline) {
            $debug['reason'] = 'immediate_online_ok';
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H5', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability accepted', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return true;
        }

        if (empty($availability->start_time) || empty($availability->end_time)) {
            $debug['reason'] = 'open_day_no_hours';
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H4', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability accepted', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return true;
        }

        try {
            $requestSeconds = Carbon::parse($timeSlot)->secondsSinceMidnight();
            $startSeconds = Carbon::parse($availability->start_time)->secondsSinceMidnight();
            $endSeconds = Carbon::parse($availability->end_time)->secondsSinceMidnight();
        } catch (\Throwable $e) {
            $debug['reason'] = 'invalid_time_slot';
            $debug['error'] = $e->getMessage();
            // #region agent log
            @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H4', 'location' => 'BookingController::isTechnicianAvailable', 'message' => 'availability rejected', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        $withinHours = $requestSeconds >= $startSeconds && $requestSeconds <= $endSeconds;
        $debug['request_seconds'] = $requestSeconds;
        $debug['start_seconds'] = $startSeconds;
        $debug['end_seconds'] = $endSeconds;
        $debug['within_hours'] = $withinHours;
        $debug['reason'] = $withinHours ? 'within_working_hours' : 'outside_working_hours';

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H4', 'location' => 'BookingController::isTechnicianAvailable', 'message' => $withinHours ? 'availability accepted' : 'availability rejected', 'data' => $debug, 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return $withinHours;
    }

    // Helper: Notify technician (simplified - you can add real notifications)
    private function notifyTechnician($technician, $booking, $type = 'new')
    {
        if (!$technician) {
            return;
        }

        $booking->loadMissing(['customer', 'serviceCategory']);
        $customerName = $booking->customer->name ?? 'A customer';
        $service = $this->resolveJobServiceCategoryName($booking);
        $scheduled = $this->formatScheduledLabel($booking);

        [$title, $body, $notificationType, $open] = match ($type) {
            'cancelled' => [
                'Booking Cancelled',
                $customerName . ' cancelled the booking for ' . $service . '.',
                'booking_cancelled',
                'booking_history',
            ],
            'completed' => [
                'Job Completed Successfully',
                'Payment will be processed within 24 hours.',
                'job_completed',
                'booking_history',
            ],
            default => [
                'New Booking Request',
                'You received a booking request from ' . $customerName
                    . ' for ' . $service
                    . ($scheduled ? ' · ' . $scheduled : '') . '.',
                'booking_request',
                'pending_requests',
            ],
        };

        app(BroadcastRadiusService::class)->notifyUser(
            $technician,
            $title,
            $body,
            [
                'booking_id' => $booking->id,
                'request_id' => $booking->booking_reference,
                'type' => $notificationType,
                'open' => $open,
            ],
            $notificationType
        );
    }

    private function notifyCustomer($customer, $booking, $status)
    {
        if (!$customer) {
            return;
        }

        $booking->loadMissing(['technician', 'serviceCategory']);
        $techName = $booking->technician->name ?? 'Technician';
        $service = $this->resolveJobServiceCategoryName($booking);
        $notifier = app(BroadcastRadiusService::class);

        $payload = match ($status) {
            'request_sent' => [
                'title' => 'Booking Request Sent',
                'body' => 'Your booking request has been sent to ' . $techName . '. You will receive a confirmation shortly.',
                'type' => 'booking_request_sent',
            ],
            'accepted' => [
                'title' => 'Booking Confirmed',
                'body' => 'Your booking is confirmed by ' . $techName . '.',
                'type' => 'booking_confirmed',
            ],
            'on_the_way' => [
                'title' => 'Technician On the Way',
                'body' => $techName . ' is on the way to your location.',
                'type' => 'technician_on_the_way',
            ],
            'work_started' => [
                'title' => 'Work Started',
                'body' => $techName . ' has started your ' . $service . ' service.',
                'type' => 'work_started',
            ],
            'work_in_progress' => [
                'title' => 'Work In Progress',
                'body' => 'Your ' . $service . ' service is currently in progress.',
                'type' => 'work_in_progress',
            ],
            'completion_otp' => [
                'title' => 'Verify Job Completion',
                'body' => $techName . ' is completing your ' . $service . '. Check your email for the 6-digit completion code.',
                'type' => 'completion_code',
            ],
            'completed' => [
                'title' => 'Service Completed',
                'body' => 'Your ' . $service . ' service is complete. Rate your experience!',
                'type' => 'service_completed',
            ],
            'rejected' => [
                'title' => 'Booking Update',
                'body' => $techName . ' is unavailable for your ' . $service . ' request.',
                'type' => 'booking_rejected',
            ],
            default => [
                'title' => 'Booking Update',
                'body' => 'Your booking for ' . $service . ' is now ' . str_replace('_', ' ', (string) $status) . '.',
                'type' => 'booking_update',
            ],
        };

        $open = match ($status) {
            'completed' => 'rate_technician',
            'request_sent' => 'booking_status',
            'completion_otp' => 'completion_code',
            default => 'booking_status',
        };

        $notifier->notifyUser(
            $customer,
            $payload['title'],
            $payload['body'],
            [
                'booking_id' => $booking->id,
                'technician_id' => $booking->technician_id,
                'technician_name' => $techName,
                'status' => $status,
                'type' => $payload['type'],
                'open' => $open,
            ],
            $payload['type']
        );

        if ($status === 'completed') {
            $notifier->notifyUser(
                $customer,
                "Don't Forget to Rate!",
                'How was your ' . $service . ' service with ' . $techName . '?',
                [
                    'booking_id' => $booking->id,
                    'technician_id' => $booking->technician_id,
                    'technician_name' => $techName,
                    'type' => 'rate_reminder',
                    'open' => 'rate_technician',
                ],
                'rate_reminder'
            );
        }
    }

    private function requestCompletionOtp(Booking $booking, User $technician): array
    {
        $customer = $booking->customer;

        if (!$customer || !$customer->email) {
            return [
                'success' => false,
                'message' => 'Customer email not found.',
                'email_sent' => false,
                'otp_sent' => false,
            ];
        }

        if (!Schema::hasColumn('bookings', 'completion_otp')
            || !Schema::hasColumn('bookings', 'completion_otp_expires_at')) {
            $booking->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->notifyCustomer($customer, $booking, 'completed');
            $this->notifyTechnician($technician, $booking, 'completed');

            return [
                'success' => true,
                'message' => 'Job marked as completed.',
                'otp_required' => false,
                'email_sent' => false,
                'otp_sent' => false,
            ];
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $booking->update([
            'completion_otp' => $otp,
            'completion_otp_expires_at' => now()->addMinutes(30),
        ]);

        $emailError = null;

        try {
            $this->sendCompletionOtpMail(
                $customer->email,
                $customer->name,
                $otp,
                $booking,
                $technician->name
            );
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            \Log::error('Completion OTP email failed: ' . $emailError);
        }

        if ($emailError) {
            $this->notifyCustomer($customer, $booking, 'completion_otp');

            return [
                'success' => false,
                'message' => 'OTP generated but email failed to send.',
                'email_sent' => false,
                'email_error' => $emailError,
                'otp_sent' => true,
            ];
        }

        $this->notifyCustomer($customer, $booking, 'completion_otp');

        return [
            'success' => true,
            'message' => 'Completion OTP sent to customer email.',
            'email_sent' => true,
            'otp_sent' => true,
            'otp_required' => true,
            'booking_id' => $booking->id,
            'verify_endpoint' => '/api/bookings/' . $booking->id . '/verify-completion-otp',
            'expires_in_minutes' => 30,
        ];
    }

    private function sendCompletionOtpMail(
        string $email,
        string $customerName,
        string $otp,
        Booking $booking,
        string $technicianName
    ): void {
        $appName = loyalBrandName();

        try {
            [$subject, $message] = $this->fetchEmailTemplate('booking_completion_otp', [
                'user_name' => $customerName,
                'otp' => $otp,
                'app_name' => $appName,
                'booking_reference' => $booking->booking_reference ?? (string) $booking->id,
                'technician_name' => $technicianName,
            ]);
        } catch (\Throwable $e) {
            $subject = $appName . ' - Job Completion Verification';
            $message = '<p style="margin:0 0 12px;">Dear ' . e($customerName) . ',</p>'
                . '<p style="margin:0 0 8px;">Your technician has finished the job. Share this code with them to confirm completion:</p>'
                . '<p style="margin:0;"><strong style="color:#FE7701;font-size:24px;letter-spacing:6px;">' . e($otp) . '</strong></p>'
                . '<p style="font-size:13px;color:#999;">This code expires in 30 minutes.</p>';
        }

        $this->sendMail($email, $subject, $message, [], true);
    }
}