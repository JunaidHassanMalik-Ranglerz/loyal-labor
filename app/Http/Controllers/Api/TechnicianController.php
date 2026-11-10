<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ResolvesSanctumApiUser;
use App\Models\Booking;
use App\Models\ServiceCategory;
use App\Models\TechnicianAvailability;
use App\Models\TechnicianReview;
use App\Models\TechnicianWorkGallery;
use App\Models\User;
use App\Services\BroadcastRadiusService;
use App\Services\GoogleDistanceService;
use App\Support\GeoDistance;
use App\Support\UserLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TechnicianController extends Controller
{
    use ResolvesSanctumApiUser;

    //
	public function submitVerification(Request $request)
{
    $user = $request->user();

    // Only technicians can submit verification
    if ($user->user_type != 'technician') {
        return response()->json([
            'success' => false,
            'message' => 'Not a technician.'
        ], 403);
    }

    // Check required documents uploaded
    if (!$user->cnic_front || !$user->cnic_back || !$user->photo) {
        return response()->json([
            'success' => false,
            'message' => 'Please upload CNIC front, CNIC back and photo first.'
        ], 400);
    }

    // Submit for review only if still pending
    if ($user->status === 'pending') {
        $user->update([
            'status' => 'review'
        ]);
    }

    // Check account approval
    $isApproved = $user->is_verified &&
                  $user->cnic_front_verified &&
                  $user->cnic_back_verified &&
                  $user->photo_verified;

    // Certificates are required only for technicians
    if ($user->user_type === 'technician') {
        $isApproved = $isApproved && $user->certificates_verified;
    }

    return response()->json([
        'success' => 200,
        'message' => $isApproved
            ? 'Account verification approved.'
            : 'Account approval pending.',
        'verification_status' => $isApproved ? 'approved' : 'pending',
        'status' => $user->status,
        'data' => [
            'email_verified' => (bool) $user->is_verified,
            'cnic_front_verified' => (bool) $user->cnic_front_verified,
            'cnic_back_verified' => (bool) $user->cnic_back_verified,
            'photo_verified' => (bool) $user->photo_verified,
            'certificates_verified' => (bool) $user->certificates_verified,
        ]
    ]);
}

    public function activateSubscription(Request $request)
    {
        return $this->assignSubscriptionPlan($request);
    }

    public function changeSubscriptionPlan(Request $request)
    {
        return $this->assignSubscriptionPlan($request);
    }

    private function assignSubscriptionPlan(Request $request)
    {
        $user = $request->user();

        if ($user->user_type != 'technician') {
            return response()->json(['success' => false, 'message' => 'Only technicians can select a subscription plan.'], 403);
        }

        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'payment_method' => 'nullable|string|max:100',
        ]);

        $subscription = \App\Models\Subscription::where('id', $request->subscription_id)
            ->where('is_active', true)
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Selected subscription plan is not active.',
            ], 404);
        }

        if (!$user->allDocumentsVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'All documents must be verified by admin before you can submit payment / activate.',
            ], 403);
        }

        // Same plan cannot be purchased again until it expires. Other plans (upgrade) are allowed.
        if ($user->hasUnexpiredPlan((int) $subscription->id)) {
            $planName = $subscription->name ?: $subscription->plan_type_label;
            $expiry = $user->subscription_end
                ? \Illuminate\Support\Carbon::parse($user->subscription_end)->format('d M Y')
                : null;
            $message = 'You already have an active ' . $planName . ' plan'
                . ($expiry ? ' until ' . $expiry : '')
                . '. You cannot purchase this plan again until it expires. You can upgrade to a different plan.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'toast_message' => $message,
                'code' => 'plan_already_active',
                'can_upgrade' => true,
                'current_plan' => [
                    'id' => $subscription->id,
                    'name' => $planName,
                    'expires_at' => $user->subscription_end,
                ],
            ], 409);
        }

        // Manual / offline methods stay pending until admin verifies.
        // PayFast purchases must use POST /api/payments/payfast/initiate.
        $paymentMethod = strtolower((string) ($request->payment_method ?? 'bank_transfer'));
        if ($paymentMethod === 'payfast') {
            return response()->json([
                'success' => false,
                'message' => 'Please use POST /api/payments/payfast/initiate for PayFast subscription payments.',
                'toast_message' => 'Use PayFast checkout to complete this purchase.',
                'code' => 'use_payfast_initiate',
            ], 422);
        }

        $data = [
            'subscription_id' => $subscription->id,
            'subscription' => 'pending',
            'payment_status' => 'pending',
            'subscription_end' => $subscription->endsAtFrom(now())->toDateString(),
        ];

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'sub-duration', 'hypothesisId' => 'H-DUR3', 'location' => 'TechnicianController::activateSubscription', 'message' => 'subscription end calculated', 'data' => ['subscription_id' => $subscription->id, 'duration' => $subscription->duration_value, 'duration_unit' => $subscription->duration_unit, 'subscription_end' => $data['subscription_end']], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        if ($request->filled('payment_method') && Schema::hasColumn('users', 'payment_method')) {
            $data['payment_method'] = $request->payment_method;
        }

        if ($request->hasFile('payment_screenshot')) {
            $data['payment_screenshot'] = $request->file('payment_screenshot')->store('payments', 'public');
        }

        $user->update($data);
        $user->refresh()->load('subscriptionPlan');

        $plan = $user->subscriptionPlan;
        $planPayload = $plan ? $plan->toApiArray() : null;
        $planName = is_array($planPayload) ? ($planPayload['name'] ?? 'subscription') : 'subscription';
        $durationLabel = is_array($planPayload) ? (string) ($planPayload['duration_label'] ?? '') : '';
        $planLabel = trim($planName . ($durationLabel !== '' ? ' — ' . $durationLabel : ''));
        $expiry = $user->subscription_end
            ? \Illuminate\Support\Carbon::parse($user->subscription_end)->format('d M Y')
            : null;
        $amount = isset($planPayload['payable_price'])
            ? 'PKR ' . number_format((float) $planPayload['payable_price'], 0)
            : null;

        app(BroadcastRadiusService::class)->notifyUser(
            $user,
            'Payment Submitted',
            'Your ' . $planLabel . ' payment is under review. Your account will activate after payment verification.',
            [
                'type' => 'subscription_payment_pending',
                'subscription_id' => $subscription->id,
                'plan_name' => $planName,
                'plan_label' => $planLabel,
                'amount' => $amount,
                'subscription_end' => $user->subscription_end,
                'expiry_date' => $expiry,
                'payment_status' => $user->payment_status,
                'open' => 'activation',
            ],
            'subscription_payment_pending'
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment submitted. Account will activate after payment verification.',
            'toast_message' => 'Payment submitted for review.',
            'payment_method' => $user->payment_method,
            'payment_status' => $user->payment_status,
            'subscription' => $planPayload,
            'subscription_end' => $user->subscription_end,
            'status' => $user->status,
            'is_account_active' => $user->isAccountActivated(),
        ]);
    }

    // Get technician status
    public function status(Request $request)
    {
        $user = $request->user()->load('subscriptionPlan');
        
        return response()->json([
            'documents' => [
                'cnic_front' => !is_null($user->cnic_front),
                'cnic_back' => !is_null($user->cnic_back),
                'photo' => !is_null($user->photo),
                'certificates' => !empty($user->certificates),
            ],
            'verification' => [
                'cnic_front_verified' => (bool) $user->cnic_front_verified,
                'cnic_back_verified' => (bool) $user->cnic_back_verified,
                'photo_verified' => (bool) $user->photo_verified,
                'certificates_verified' => (bool) $user->certificates_verified,
            ],
            'account_status' => $user->status,
            'payment_status' => $user->payment_status ?? 'none',
            'subscription_active' => $user->hasActiveSubscription(),
            'subscription_end' => $user->subscription_end,
            'is_featured' => $user->isFeaturedTechnician(),
            'has_verified_badge' => $user->hasVerifiedPlanBadge(),
            'feature_keys' => $user->activeFeatureKeys(),
            'subscription_plan' => $user->subscriptionPlan ? $user->subscriptionPlan->toApiArray() : null,
            'availability' => $user->availabilities,
            'currently_available' => Schema::hasColumn('users', 'currently_available')
                ? (bool) ($user->currently_available ?? true)
                : true,
        ]);
    }

    // GET /api/technician/activation — "Account Pending Activation" screen
    public function activationScreen(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can view activation status.',
            ], 403);
        }

        $documentsVerified = $user->allDocumentsVerified();
        $paymentVerified = $user->isPaymentVerified();
        $subscriptionActive = $user->hasActiveSubscription();
        $accountStatus = (string) ($user->status ?? 'pending');
        $isActive = $user->isAccountActivated();
        $paymentRequired = $documentsVerified && !$paymentVerified;

        $reviewStatus = match (true) {
            $accountStatus === 'rejected' => 'rejected',
            $documentsVerified => 'completed',
            default => 'pending',
        };

        $subtitle = match (true) {
            $isActive => 'Your account is active and ready for bookings.',
            !$documentsVerified => 'Your documents are under review.',
            !$paymentVerified => 'Documents verified. Submit payment to activate your account.',
            default => 'Your profile is under review.',
        };

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $isActive ? 'Account Active' : 'Account Pending Activation',
                'subtitle' => $subtitle,
                'payment_required' => $paymentRequired,
                'payment_banner' => [
                    'show' => $paymentRequired,
                    'title' => $paymentRequired ? 'Payment Required to Activate' : null,
                    'message' => $paymentRequired
                        ? 'Your documents have been verified. To start receiving bookings, you must activate your account with a subscription plan.'
                        : null,
                ],
                'checklist' => [
                    [
                        'key' => 'documents_verified',
                        'label' => 'Documents Verified',
                        'done' => $documentsVerified,
                        'status' => $documentsVerified ? 'verified' : 'pending',
                        'status_label' => $documentsVerified ? 'Verified' : 'Pending',
                    ],
                    [
                        'key' => 'account_under_review',
                        'label' => 'Account Under Review',
                        'done' => $documentsVerified,
                        'status' => $reviewStatus,
                        'status_label' => $documentsVerified ? 'Approved' : ($reviewStatus === 'rejected' ? 'Rejected' : 'Pending'),
                    ],
                    [
                        'key' => 'subscription_required',
                        'label' => 'Subscription Required',
                        'done' => $paymentVerified && $subscriptionActive,
                        'status' => ($paymentVerified && $subscriptionActive) ? 'active' : ($paymentVerified ? 'pending' : 'required'),
                        'status_label' => ($paymentVerified && $subscriptionActive)
                            ? 'Active'
                            : ($paymentVerified ? 'Pending' : 'Required'),
                    ],
                ],
                'documents' => [
                    'cnic_front' => !is_null($user->cnic_front),
                    'cnic_back' => !is_null($user->cnic_back),
                    'photo' => !is_null($user->photo),
                    'certificates' => !empty($user->certificates),
                ],
                'verification' => [
                    'cnic_front_verified' => (bool) $user->cnic_front_verified,
                    'cnic_back_verified' => (bool) $user->cnic_back_verified,
                    'photo_verified' => (bool) $user->photo_verified,
                    'certificates_verified' => (bool) $user->certificates_verified,
                    'all_verified' => $documentsVerified,
                ],
                'account_status' => $accountStatus,
                'payment_status' => $user->payment_status ?? 'none',
                'subscription_active' => $subscriptionActive,
                'subscription_end' => $user->subscription_end,
                'can_activate' => $paymentRequired,
                'is_account_active' => $isActive,
            ],
        ]);
    }

    public function updateAvailability(Request $request)
    {
        $user = $request->user();

        if ($user->user_type != 'technician') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $incoming = $request->input('availability');
        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'pre-fix', 'hypothesisId' => 'A,B,C,E', 'location' => 'TechnicianController::updateAvailability:entry', 'message' => 'incoming availability payload', 'data' => ['user_id' => $user->id, 'availability_is_array' => is_array($incoming), 'count' => is_array($incoming) ? count($incoming) : 0, 'first_keys' => is_array($incoming) && isset($incoming[0]) && is_array($incoming[0]) ? array_keys($incoming[0]) : (is_array($incoming) ? array_keys($incoming) : []), 'sample' => is_array($incoming) ? array_slice($incoming, 0, 2) : $incoming, 'all_input_keys' => array_keys($request->all())], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        foreach ($request->availability as $schedule) {
            $rawStart = $schedule['start'] ?? $schedule['start_time'] ?? null;
            $rawEnd = $schedule['end'] ?? $schedule['end_time'] ?? null;
            // #region agent log
            @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'pre-fix', 'hypothesisId' => 'A,B,C', 'location' => 'TechnicianController::updateAvailability:row', 'message' => 'row before save', 'data' => ['day' => $schedule['day'] ?? null, 'keys' => is_array($schedule) ? array_keys($schedule) : [], 'start' => $schedule['start'] ?? null, 'end' => $schedule['end'] ?? null, 'start_time' => $schedule['start_time'] ?? null, 'end_time' => $schedule['end_time'] ?? null, 'start_ampm' => $schedule['start_ampm'] ?? $schedule['start_period'] ?? null, 'end_ampm' => $schedule['end_ampm'] ?? $schedule['end_period'] ?? null, 'raw_start' => $rawStart, 'raw_end' => $rawEnd, 'start_has_ampm' => is_string($rawStart) && preg_match('/am|pm/i', $rawStart) ? true : false, 'end_has_ampm' => is_string($rawEnd) && preg_match('/am|pm/i', $rawEnd) ? true : false], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            TechnicianAvailability::updateOrCreate(
                [
                    'technician_id' => $user->id,
                    'day' => $schedule['day'],
                    'specific_date' => $schedule['specific_date'] ?? null,
                ],
                [
                    'start_time' => $schedule['start'] ?? null,
                    'end_time' => $schedule['end'] ?? null,
                    'is_available' => $schedule['is_available'] ?? true,
                ]
            );
        }

        $saved = TechnicianAvailability::where('technician_id', $user->id)->get();
        // #region agent log
        @file_put_contents(base_path('debug-1d5da7.log'), json_encode(['sessionId' => '1d5da7', 'runId' => 'pre-fix', 'hypothesisId' => 'A,D', 'location' => 'TechnicianController::updateAvailability:saved', 'message' => 'saved rows after write', 'data' => ['count' => $saved->count(), 'rows' => $saved->map(fn ($row) => ['id' => $row->id, 'day' => $row->day, 'start_time' => $row->start_time, 'end_time' => $row->end_time, 'attributes' => array_keys($row->getAttributes())])->take(7)->values()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'message' => 'Availability updated successfully',
            'availability' => $saved,
        ]);
    }

    public function toggleDayAvailability(Request $request)
    {
        $user = $request->user();

        if ($user->user_type != 'technician') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'day' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'is_available' => 'required|boolean',
        ]);

        $availability = TechnicianAvailability::where([
            'technician_id' => $user->id,
            'day' => $request->day,
        ])->first();

        if ($availability) {
            $availability->update(['is_available' => $request->is_available]);
        }

        return response()->json([
            'message' => $request->is_available
                ? "Now available on {$request->day}"
                : "Now off on {$request->day}",
            'availability' => $availability,
        ]);
    }

    public function toggleCurrentlyAvailable(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json(['success' => false, 'message' => 'Only technicians can update availability.'], 403);
        }

        $request->validate([
            'is_available' => 'required|boolean',
        ]);

        if (!Schema::hasColumn('users', 'currently_available')) {
            return response()->json([
                'success' => false,
                'message' => 'currently_available column missing. Run migration: 2026_07_22_120000_add_currently_available_to_users_table.php',
            ], 500);
        }

        $isAvailable = $request->boolean('is_available');

        if ($isAvailable && !$user->isAccountActivated()) {
            return response()->json([
                'success' => false,
                'message' => 'Activate your account with a subscription before going available for bookings.',
                'currently_available' => (bool) ($user->currently_available ?? false),
                'is_account_active' => false,
            ], 403);
        }

        $user->update([
            'currently_available' => $isAvailable,
        ]);

        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => $isAvailable
                ? 'You are now available for bookings.'
                : 'You are now marked as busy.',
            'currently_available' => $isAvailable,
            'availability_status' => $isAvailable ? 'Available' : 'Busy',
        ]);
    }

	public function checkTechnicianAvailability(Request $request)
{
    $request->validate([
        'technician_id' => 'required|integer|exists:users,id',
    ]);

    $technician = User::where('id', $request->technician_id)
        ->where('user_type', 'technician')
        ->whereAccountActivated()
        ->first();

    if (!$technician) {
        return response()->json([
            'success' => false,
            'message' => 'Technician not found or account not activated.',
        ], 404);
    }

    $isAvailable = (bool) $technician->currently_available;

    return response()->json([
        'success' => true,
        'technician_id' => $technician->id,
        'currently_available' => $isAvailable,
        'availability_status' => $isAvailable ? 'Available' : 'Busy',
    ]);
}

    public function getTechnicians(Request $request)
    {
        $request->validate([
            'filter' => 'nullable|in:all,top_rated,nearest,featured',
            'sort_by' => 'nullable|in:rating,distance,name,featured',
            'service_category_id' => 'nullable|integer|exists:service_categories,id',
            'category' => 'nullable',
            'search' => 'nullable|string|max:100',
            'q' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'district_id' => 'nullable|integer|exists:districts,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'long' => 'nullable|numeric|between:-180,180',
            // Accepted for compatibility; discovery is hard-capped at 25 km.
            'radius_km' => 'nullable|numeric|min:1|max:25',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filter = $request->input('filter', 'nearest');
        $search = trim((string) (
            $request->input('search')
            ?? $request->input('q')
            ?? $request->input('name')
            ?? ''
        ));
        $authUser = $this->resolveSanctumUser($request);
        [$customerLat, $customerLng, $locationSource] = UserLocation::resolve($request, $authUser, true);
        $radiusKm = GeoDistance::discoveryRadiusKm(
            $request->filled('radius_km') ? (float) $request->input('radius_km') : null
        );
        $haversineSql = GeoDistance::usersHaversineSql();
        $districtId = $request->filled('district_id') ? (int) $request->district_id : null;

        if ($customerLat === null || $customerLng === null) {
            return response()->json([
                'success' => false,
                'message' => 'No location found. POST /api/location with latitude & longitude (auth required) to save GPS in DB, or send latitude/longitude on this request.',
                'location' => [
                    'save_endpoint' => [
                        'method' => 'POST',
                        'url' => '/api/location',
                        'auth' => 'Bearer token required',
                        'form_fields' => ['latitude', 'longitude'],
                        'aliases' => ['lat', 'lng', 'long'],
                    ],
                    'radius_km' => GeoDistance::TECHNICIAN_DISCOVERY_RADIUS_KM,
                    'source' => $locationSource,
                ],
            ], 422);
        }

        $query = User::query()
            ->where('users.user_type', 'technician')
            ->whereAccountActivated()
            ->whereNotNull('users.latitude')
            ->whereNotNull('users.longitude')
            ->select('users.*')
            ->selectRaw($haversineSql . ' as distance_km', [$customerLat, $customerLng, $customerLat])
            ->whereRaw($haversineSql . ' <= ?', [$customerLat, $customerLng, $customerLat, $radiusKm])
            ->orderBy('distance_km');

        // Home search: "carpenter" → category; "ali" → name (any category)
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'LIKE', $like)
                    ->orWhereHas('serviceCategories', function ($categoryQuery) use ($like) {
                        $categoryQuery->where('service_categories.name', 'LIKE', $like)
                            ->orWhere('service_categories.slug', 'LIKE', $like);
                    });
            });
        }

        if ($request->filled('service_category_id')) {
            $categoryId = (int) $request->service_category_id;

            if (!ServiceCategory::whereKey($categoryId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service category not found.',
                ], 404);
            }

            $query->whereIn('users.id', function ($subQuery) use ($categoryId) {
                $subQuery->select('user_id')
                    ->from('service_category_user')
                    ->where('service_category_id', $categoryId);
            });
        } elseif ($request->filled('category')) {
            $categoryIds = is_array($request->category)
                ? $request->category
                : array_filter(array_map('trim', explode(',', (string) $request->category)));

            $query->whereHas('serviceCategories', function ($categoryQuery) use ($categoryIds) {
                $categoryQuery->whereIn('service_categories.id', $categoryIds);
            });
        }

        if (Schema::hasTable('technician_reviews')) {
            $query->withAvg('approvedTechnicianReviews as avg_rating', 'rating')
                ->withCount('approvedTechnicianReviews as review_count');
        }

        if ($filter === 'top_rated' && Schema::hasTable('technician_reviews')) {
            $query->having('review_count', '>', 0);
        }

        if ($districtId) {
            $query->where('users.district_id', $districtId);
        }

        // Full matching set first (distance filter already in SQL) — then optional page slice.
        $technicians = $query->with(['serviceCategories', 'availabilities', 'subscriptionPlan'])->get()->values();

        // PHP safety net: re-check Haversine <= radius and drop invalid coords / float edge cases.
        $technicians = $technicians->filter(function ($tech) use ($customerLat, $customerLng, $radiusKm) {
            $distance = GeoDistance::haversineKm(
                $customerLat,
                $customerLng,
                $tech->latitude !== null ? (float) $tech->latitude : null,
                $tech->longitude !== null ? (float) $tech->longitude : null
            );

            if ($distance === null || $distance > $radiusKm) {
                return false;
            }

            $tech->distance_km = round($distance, 3);

            return true;
        })->values();

        $ratingBreakdowns = [];
        if (Schema::hasTable('technician_reviews') && $technicians->isNotEmpty()) {
            $breakdownRows = TechnicianReview::query()
                ->where('is_approved', true)
                ->whereIn('technician_id', $technicians->pluck('id'))
                ->select('technician_id', 'rating', DB::raw('COUNT(*) as total'))
                ->groupBy('technician_id', 'rating')
                ->get();

            foreach ($breakdownRows as $row) {
                $ratingBreakdowns[$row->technician_id][(int) $row->rating] = (int) $row->total;
            }
        }

        $mapped = $technicians->map(function ($tech) use ($request, $ratingBreakdowns) {
            $avgRating = round((float) ($tech->avg_rating ?? 0), 1);
            $reviewCount = (int) ($tech->review_count ?? 0);
            $distanceKm = round((float) ($tech->distance_km ?? 0), 2);

            $filteredCategoryId = $request->filled('service_category_id')
                ? (int) $request->service_category_id
                : null;

            $primaryCategory = $filteredCategoryId
                ? ($tech->serviceCategories->firstWhere('id', $filteredCategoryId)
                    ?? $tech->serviceCategories->first())
                : $tech->serviceCategories->first();

            $currentlyAvailable = Schema::hasColumn('users', 'currently_available')
                ? (bool) ($tech->currently_available ?? true)
                : true;

            $featureKeys = $tech->activeFeatureKeys();
            $isFeatured = $tech->isFeaturedTechnician();
            $hasVerifiedBadge = $tech->hasVerifiedPlanBadge();
            $plan = $tech->subscriptionPlan;

            return [
                'id' => $tech->id,
                'name' => $tech->name,
                'photo' => $tech->photo ? asset($tech->photo) : null,
                'rating' => $avgRating,
                'review_count' => $reviewCount,
                'rating_label' => $this->ratingLabel($avgRating),
                'service_category' => $primaryCategory ? [
                    'id' => $primaryCategory->id,
                    'name' => $primaryCategory->name,
                ] : null,
                'service_categories' => $tech->serviceCategories->map(fn ($cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                ])->values(),
                'experience' => $tech->experience,
                'latitude' => $tech->latitude !== null ? (float) $tech->latitude : null,
                'longitude' => $tech->longitude !== null ? (float) $tech->longitude : null,
                'distance_km' => $distanceKm,
                'distance_text' => $distanceKm . ' km away',
                'currently_available' => $currentlyAvailable,
                'availability_status' => $this->resolveAvailabilityStatus($currentlyAvailable),
                'is_featured' => $isFeatured,
                'featured_badge' => $isFeatured ? 'FEATURED' : null,
                'has_verified_badge' => $hasVerifiedBadge,
                'subscription' => [
                    'plan_type' => $plan->plan_type ?? null,
                    'plan_type_label' => $plan?->plan_type_label,
                    'is_active' => $tech->hasActiveSubscription(),
                    'feature_keys' => $featureKeys,
                ],
            ];
        });

        if ($filter === 'featured') {
            $mapped = $mapped->filter(fn ($t) => $t['is_featured'])->values();
        }

        if ($filter === 'top_rated') {
            $mapped = $mapped->filter(fn ($t) => $t['review_count'] > 0)
                ->sort(function ($a, $b) use ($ratingBreakdowns) {
                    $score = function ($tech) use ($ratingBreakdowns) {
                        $breakdown = $ratingBreakdowns[$tech['id']] ?? [];

                        return [
                            (int) ($breakdown[5] ?? 0),
                            (int) ($breakdown[4] ?? 0),
                            $tech['rating'],
                            $tech['review_count'],
                        ];
                    };

                    $left = $score($a);
                    $right = $score($b);

                    foreach ($left as $index => $value) {
                        if ($value === $right[$index]) {
                            continue;
                        }

                        return $right[$index] <=> $value;
                    }

                    return 0;
                })
                ->values();
        }

        $sortBy = $request->input('sort_by', $filter === 'nearest' || !$request->filled('sort_by') ? 'distance' : null);
        if ($sortBy === 'rating') {
            $mapped = $mapped->sort(function ($a, $b) use ($ratingBreakdowns) {
                $score = function ($tech) use ($ratingBreakdowns) {
                    $breakdown = $ratingBreakdowns[$tech['id']] ?? [];

                    return [
                        (int) ($breakdown[5] ?? 0),
                        (int) ($breakdown[4] ?? 0),
                        $tech['rating'],
                        $tech['review_count'],
                    ];
                };

                $left = $score($a);
                $right = $score($b);

                foreach ($left as $index => $value) {
                    if ($value === $right[$index]) {
                        continue;
                    }

                    return $right[$index] <=> $value;
                }

                return 0;
            })->values();
        } elseif ($sortBy === 'name') {
            $mapped = $mapped->sortBy('name')->values();
        } elseif ($sortBy === 'featured') {
            $mapped = $mapped->sort(function ($a, $b) {
                $aPriority = ($a['is_featured'] ? 2 : 0)
                    + (in_array('priority_search_placement', $a['subscription']['feature_keys'] ?? [], true) ? 1 : 0);
                $bPriority = ($b['is_featured'] ? 2 : 0)
                    + (in_array('priority_search_placement', $b['subscription']['feature_keys'] ?? [], true) ? 1 : 0);

                if ($aPriority === $bPriority) {
                    return $a['distance_km'] <=> $b['distance_km'];
                }

                return $bPriority <=> $aPriority;
            })->values();
        } else {
            // Default / nearest: nearest distance first
            $mapped = $mapped->sortBy('distance_km')->values();
            $sortBy = 'distance';
        }

        $total = $mapped->count();
        $featuredList = $mapped->filter(fn ($t) => $t['is_featured'])->values();
        $categoryFilter = $request->input('service_category_id') ?? $request->input('category', 'all');

        // Pagination is optional. Without per_page, return the full eligible set.
        $paginate = $request->filled('per_page') || $request->filled('page');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $paginate
            ? min(100, max(1, (int) $request->input('per_page', 20)))
            : max($total, 1);
        $lastPage = max(1, (int) ceil(($total ?: 1) / $perPage));
        $pageItems = $paginate
            ? $mapped->slice(($page - 1) * $perPage, $perPage)->values()
            : $mapped->values();

        return response()->json([
            'success' => true,
            'message' => $total === 0
                ? ($search !== ''
                    ? 'No technicians found for "' . $search . '" within ' . $radiusKm . ' km.'
                    : 'No technicians found within ' . $radiusKm . ' km.')
                : 'Technicians retrieved successfully.',
            'filter' => $filter,
            'sort_by' => $sortBy,
            'search' => $search !== '' ? $search : null,
            'service_category_id' => $categoryFilter,
            'location' => [
                'latitude' => $customerLat,
                'longitude' => $customerLng,
                'source' => $locationSource,
                'radius_km' => $radiusKm,
                'distance_formula' => 'haversine',
                'includes_boundary' => true,
            ],
            'radius_check' => [
                'radius_km' => $radiusKm,
                'max_distance_in_results' => $total > 0 ? $mapped->max('distance_km') : null,
                'all_within_radius' => $mapped->every(fn ($t) => $t['distance_km'] <= $radiusKm),
                'excluded_missing_coords' => true,
            ],
            'featured_total' => $featuredList->count(),
            'featured' => $featuredList,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $paginate ? $perPage : $total,
                'total' => $total,
                'last_page' => $paginate ? $lastPage : 1,
                'paginated' => $paginate,
            ],
            'total' => $total,
            'data' => $pageItems,
        ]);
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return round((float) GeoDistance::haversineKm($lat1, $lon1, $lat2, $lon2), 1);
    }

    private function resolveAvailabilityStatus(bool $currentlyAvailable): string
    {
        return $currentlyAvailable ? 'Available' : 'Busy';
    }

    private function ratingLabel(float $rating): string
    {
        return match (true) {
            $rating >= 4.5 => 'Excellent',
            $rating >= 4.0 => 'Good',
            $rating >= 3.0 => 'Average',
            $rating > 0 => 'Fair',
            default => 'New',
        };
    }

    public function subscriptionOrderSummary(Request $request, $subscriptionId = null)
    {
        $id = $subscriptionId ?: $request->input('subscription_id');

        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'subscription_id is required.',
                'toast_message' => 'Please select a subscription plan to continue.',
            ], 422);
        }

        $subscription = \App\Models\Subscription::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription plan not found.',
                'toast_message' => 'The selected subscription plan is unavailable. Please choose another plan.',
            ], 404);
        }

        $summary = $subscription->toOrderSummaryArray();
        $planName = $summary['name'] ?? ($subscription->name ?: $subscription->plan_type_label);
        $message = 'Order summary for ' . $planName . ' is ready. Please review the pricing and proceed to payment.';
        $canPurchase = true;
        $user = $request->user('sanctum') ?? auth('sanctum')->user();

        if ($user && $user->user_type === 'technician' && $user->hasUnexpiredPlan((int) $subscription->id)) {
            $canPurchase = false;
            $expiry = $user->subscription_end
                ? \Illuminate\Support\Carbon::parse($user->subscription_end)->format('d M Y')
                : null;
            $message = 'You already have an active ' . $planName . ' plan'
                . ($expiry ? ' until ' . $expiry : '')
                . '. You cannot purchase this plan again until it expires. You can upgrade to a different plan.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'toast_message' => $message,
            'can_purchase' => $canPurchase,
            'data' => $summary,
        ]);
    }

    public function getSubscriptions()
    {
        $subscriptions = \App\Models\Subscription::query()
            ->where('is_active', true)
            ->orderBy('price_pkr')
            ->get()
            ->map(fn ($sub) => $sub->toApiArray());

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'sub-impl', 'hypothesisId' => 'H-SUB4', 'location' => 'TechnicianController::getSubscriptions', 'message' => 'subscriptions api response', 'data' => ['total' => $subscriptions->count(), 'sample' => $subscriptions->first()], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'total' => $subscriptions->count(),
            'data' => $subscriptions,
        ]);
    }

	public function getSkills(Request $request)
    {
        try {
            // Query binding with optional filters
            $query = DB::table('skills')
                ->select('id', 'name', 'created_at', 'updated_at');

            // ✅ Search by name (optional)
            if ($request->has('search') && !empty($request->search)) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            }

            // ✅ Order by (optional)
            $orderBy = $request->order_by ?? 'id';
            $orderDir = $request->order_dir ?? 'asc';
            $query->orderBy($orderBy, $orderDir);

            // ✅ Pagination (optional)
            $perPage = $request->per_page ?? 10;
            $skills = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Skills fetched successfully',
                'data' => $skills->items(),
                'pagination' => [
                    'current_page' => $skills->currentPage(),
                    'per_page' => $skills->perPage(),
                    'total' => $skills->total(),
                    'last_page' => $skills->lastPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch skills: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getTechnicianProfile(Request $request, $technicianId)
    {
        $technician = User::query()
            ->where('id', $technicianId)
            ->where('user_type', 'technician')
            ->whereAccountActivated()
            ->with(['serviceCategories', 'availabilities', 'workGalleries'])
            ->first();

        if (!$technician) {
            return response()->json([
                'success' => false,
                'message' => 'Technician not found or account not activated.',
            ], 404);
        }

        $skills = $technician->skills;
        if (is_string($skills)) {
            $skills = json_decode($skills, true) ?? [];
        }

        $jobsCompleted = Booking::where('technician_id', $technician->id)
            ->where('status', 'completed')
            ->count();

        $ratingStats = null;
        $highestRating = 0;
        $avgRating = 0;
        $reviewCount = 0;

        if (Schema::hasTable('technician_reviews')) {
            $ratingStats = TechnicianReview::where('technician_id', $technician->id)
                ->where('is_approved', true)
                ->selectRaw('AVG(rating) as avg_rating, MAX(rating) as highest_rating, COUNT(*) as review_count')
                ->first();

            $avgRating = round((float) ($ratingStats->avg_rating ?? 0), 1);
            $highestRating = (int) ($ratingStats->highest_rating ?? 0);
            $reviewCount = (int) ($ratingStats->review_count ?? 0);
        }

        $primaryCategory = $technician->serviceCategories->first();
        $currentlyAvailable = Schema::hasColumn('users', 'currently_available')
            ? (bool) ($technician->currently_available ?? true)
            : true;

        $galleryItems = $technician->workGalleries->map(fn ($item) => [
            'id' => $item->id,
            'image' => asset($item->image),
            'caption' => $item->caption,
        ])->values();

        $galleryTotal = $galleryItems->count();

        $authUser = $this->resolveSanctumUser($request);
        [$originLat, $originLng] = UserLocation::resolve($request, $authUser, (bool) $authUser);
        $distanceKm = GeoDistance::haversineKm(
            $originLat,
            $originLng,
            $technician->latitude !== null ? (float) $technician->latitude : null,
            $technician->longitude !== null ? (float) $technician->longitude : null
        );
        $distanceKm = $distanceKm !== null ? round($distanceKm, 2) : null;

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H3', 'location' => 'TechnicianController::getTechnicianProfile', 'message' => 'profile work gallery', 'data' => ['technician_id' => $technician->id, 'total' => $galleryTotal, 'sample' => $galleryItems->take(2)->values()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $technician->id,
                'name' => $technician->name,
                'photo' => $technician->photo ? asset($technician->photo) : null,
                'distance_km' => $distanceKm,
                'distance_text' => $distanceKm !== null ? $distanceKm . ' km away' : null,
                'bio' => $technician->bio,
                'experience' => $technician->experience,
                'service_category' => $primaryCategory ? [
                    'id' => $primaryCategory->id,
                    'name' => $primaryCategory->name,
                ] : null,
                'service_categories' => $technician->serviceCategories->map(fn ($cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                ])->values(),
                'rating' => $avgRating,
                'highest_rating' => $highestRating,
                'review_count' => $reviewCount,
                'rating_label' => $this->ratingLabel($avgRating),
                'jobs_completed' => $jobsCompleted,
                'skills' => is_array($skills) ? $skills : [],
                'currently_available' => $currentlyAvailable,
                'availability_status' => $this->resolveAvailabilityStatus($currentlyAvailable),
                'availability' => $technician->availabilities->map(fn ($slot) => [
                    'day' => $slot->day,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'is_available' => (bool) $slot->is_available,
                ])->values(),
                'work_gallery' => [
                    'total' => $galleryTotal,
                    'remaining' => max(0, $galleryTotal - 3),
                    'items' => $galleryItems,
                ],
            ],
        ]);
    }

    public function uploadWorkGallery(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json(['success' => false, 'message' => 'Only technicians can upload work gallery images.'], 403);
        }

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'caption' => 'nullable|string|max:255',
        ]);

        $file = $request->file('image');
        $directory = public_path('backend/img/gallery');

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'work_' . $user->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($directory, $filename);
        $imagePath = 'backend/img/gallery/' . $filename;

        $gallery = TechnicianWorkGallery::create([
            'technician_id' => $user->id,
            'image' => $imagePath,
            'caption' => $request->caption,
            'sort_order' => (int) TechnicianWorkGallery::where('technician_id', $user->id)->max('sort_order') + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Work image uploaded successfully.',
            'data' => [
                'id' => $gallery->id,
                'image' => asset($gallery->image),
                'caption' => $gallery->caption,
            ],
        ], 201);
    }

    public function myWorkGallery(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json(['success' => false, 'message' => 'Only technicians can view work gallery.'], 403);
        }

        $items = TechnicianWorkGallery::where('technician_id', $user->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'image' => asset($item->image),
                'caption' => $item->caption,
                'created_at' => $item->created_at,
            ]);

        return response()->json([
            'success' => true,
            'total' => $items->count(),
            'data' => $items,
        ]);
    }

    public function deleteWorkGallery(Request $request, $galleryId)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json(['success' => false, 'message' => 'Only technicians can delete work gallery images.'], 403);
        }

        $gallery = TechnicianWorkGallery::where('technician_id', $user->id)->findOrFail($galleryId);

        $fullPath = public_path($gallery->image);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $gallery->delete();

        return response()->json([
            'success' => true,
            'message' => 'Work gallery image deleted.',
        ]);
    }



public function getServiceCategory()
{
    try {
        $data = ServiceCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon', 'sort_order'])
            ->map(function ($category) {
                $iconUrl = $category->iconUrl();

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $iconUrl,
                    'icon_url' => $iconUrl,
                    'sort_order' => (int) $category->sort_order,
                ];
            })
            ->values();

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H4', 'location' => 'TechnicianController::getServiceCategory', 'message' => 'api active categories only', 'data' => ['total' => $data->count(), 'inactive_skipped' => ServiceCategory::where('is_active', false)->count(), 'sample' => $data->take(2)->values()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => 'Service categories retrieved successfully.',
            'total' => $data->count(),
            'data' => $data,
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

   public function searchServiceCategories(Request $request)
{
    $name = trim((string) (
        $request->input('name')
        ?? $request->input('search')
        ?? $request->input('q')
        ?? ''
    ));

    $query = ServiceCategory::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('name');

    if ($name !== '') {
        $query->where(function ($q) use ($name) {
            $q->where('name', 'LIKE', '%' . $name . '%')
              ->orWhere('slug', 'LIKE', '%' . $name . '%');
        });
    }

    $data = $query->get([
        'id',
        'name',
        'slug',
        'icon',
        'sort_order'
    ])->map(function ($category) {

        $iconUrl = $category->iconUrl();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $iconUrl,
            'icon_url' => $iconUrl,
            'sort_order' => (int) $category->sort_order,
        ];
    })->values();

    return response()->json([
        'success' => true,
        'message' => $data->isEmpty()
            ? 'No service categories found.'
            : 'Service categories retrieved successfully.',
        'search' => $name,
        'total' => $data->count(),
        'data' => $data,
    ]);
}

    // GET /api/technician/profile — My Profile screen data
    public function myProfile(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can access this profile.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Technician profile retrieved successfully.',
            'data' => $this->technicianMyProfilePayload($user),
        ]);
    }

    // POST /api/technician/profile/update — update only My Profile screen inputs
    public function updateMyProfile(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only technicians can update this profile.',
            ], 403);
        }

        foreach (['skills', 'service_area', 'availability', 'certificates'] as $jsonField) {
            if ($request->has($jsonField) && is_string($request->input($jsonField))) {
                $decoded = json_decode($request->input($jsonField), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$jsonField => $decoded]);
                }
            }
        }

        $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'district_id' => 'nullable|exists:districts,id',
            'bio' => 'nullable|string',
            'experience' => 'nullable|string|max:255',
            'skills' => 'nullable|array',
            'skills.*' => 'nullable|string|max:100',
            'service_area' => 'nullable',
            'availability' => 'nullable|array',
            'availability.*.day' => 'required_with:availability|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'availability.*.is_available' => 'nullable|boolean',
            'availability.*.start' => 'nullable|date_format:H:i',
            'availability.*.end' => 'nullable|date_format:H:i',
            'availability.*.start_time' => 'nullable|date_format:H:i',
            'availability.*.end_time' => 'nullable|date_format:H:i',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'cnic_front' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'cnic_back' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'certificates' => 'nullable',
            'certificates.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $data = [];

        if ($request->filled('name')) {
            $data['name'] = $request->name;
        }
        if ($request->has('phone')) {
            $data['phone'] = $request->phone;
        }
        if ($request->filled('district_id')) {
            $data['district_id'] = (int) $request->district_id;
        }
        if ($request->has('bio')) {
            $data['bio'] = $request->bio;
        }
        if ($request->has('experience')) {
            $data['experience'] = $request->experience;
        }
        if ($request->has('skills')) {
            $data['skills'] = array_values(array_filter((array) $request->skills, fn ($s) => trim((string) $s) !== ''));
        }
        if ($request->has('service_area')) {
            $serviceArea = $request->input('service_area');
            if (is_string($serviceArea)) {
                $serviceArea = trim($serviceArea) === '' ? [] : [$serviceArea];
            }
            $data['service_area'] = is_array($serviceArea) ? array_values($serviceArea) : [];
        }

        $uploadPath = public_path('backend/img');
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        if ($request->hasFile('photo')) {
            $data['photo'] = $this->storeTechnicianDocument($request->file('photo'), 'profile_');
            $data['photo_verified'] = false;
        }
        if ($request->hasFile('cnic_front')) {
            $data['cnic_front'] = $this->storeTechnicianDocument($request->file('cnic_front'), 'cnic_front_');
            $data['cnic_front_verified'] = false;
        }
        if ($request->hasFile('cnic_back')) {
            $data['cnic_back'] = $this->storeTechnicianDocument($request->file('cnic_back'), 'cnic_back_');
            $data['cnic_back_verified'] = false;
        }

        if ($request->hasFile('certificates')) {
            $certs = $request->file('certificates');
            if (!is_array($certs)) {
                $certs = [$certs];
            }
            $saved = [];
            foreach ($certs as $file) {
                if ($file && $file->isValid()) {
                    $saved[] = $this->storeTechnicianDocument($file, 'cert_');
                }
            }
            if ($saved !== []) {
                $data['certificates'] = $saved;
                $data['certificates_verified'] = false;
            }
        }

        if ($data !== []) {
            $user->update($data);
        }

        if ($request->has('availability') && is_array($request->availability)) {
            foreach ($request->availability as $schedule) {
                $day = strtolower((string) ($schedule['day'] ?? ''));
                if ($day === '') {
                    continue;
                }

                TechnicianAvailability::updateOrCreate(
                    [
                        'technician_id' => $user->id,
                        'day' => $day,
                        'specific_date' => $schedule['specific_date'] ?? null,
                    ],
                    [
                        'start_time' => $schedule['start'] ?? $schedule['start_time'] ?? null,
                        'end_time' => $schedule['end'] ?? $schedule['end_time'] ?? null,
                        'is_available' => array_key_exists('is_available', $schedule)
                            ? filter_var($schedule['is_available'], FILTER_VALIDATE_BOOLEAN)
                            : true,
                    ]
                );
            }
        }

        $user = $user->fresh(['district', 'availabilities']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $this->technicianMyProfilePayload($user),
        ]);
    }

    private function storeTechnicianDocument($file, string $prefix): string
    {
        $uploadPath = public_path('backend/img');
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $filename = $prefix . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($uploadPath, $filename);

        return 'backend/img/' . $filename;
    }

    private function technicianMyProfilePayload(User $user): array
    {
        $user->loadMissing(['district', 'availabilities']);

        $ratingStats = TechnicianReview::where('technician_id', $user->id)
            ->where('is_approved', true)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as review_count')
            ->first();

        $avgRating = round((float) ($ratingStats->avg_rating ?? 0), 1);
        $reviewCount = (int) ($ratingStats->review_count ?? 0);

        $skills = $user->skills;
        if (is_string($skills)) {
            $skills = json_decode($skills, true) ?? [];
        }
        $skills = is_array($skills) ? array_values($skills) : [];

        $serviceArea = $user->service_area;
        if (is_string($serviceArea)) {
            $decoded = json_decode($serviceArea, true);
            $serviceArea = json_last_error() === JSON_ERROR_NONE ? $decoded : [$serviceArea];
        }
        $serviceArea = is_array($serviceArea) ? array_values($serviceArea) : [];
        $serviceAreaLabel = $serviceArea !== [] ? implode(', ', $serviceArea) : null;

        $certificates = $user->certificates;
        if (is_string($certificates)) {
            $certificates = json_decode($certificates, true) ?? [];
        }
        $certificates = is_array($certificates) ? array_values($certificates) : [];

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $availabilityByDay = $user->availabilities->keyBy(fn ($row) => strtolower((string) $row->day));
        $availability = collect($days)->map(function ($day) use ($availabilityByDay) {
            $row = $availabilityByDay->get($day);
            $isAvailable = $row ? (bool) $row->is_available : false;

            return [
                'day' => $day,
                'day_label' => ucfirst($day),
                'is_available' => $isAvailable,
                'status_label' => $isAvailable ? 'Available' : 'Day Off',
                'start_time' => $row->start_time ?? null,
                'end_time' => $row->end_time ?? null,
            ];
        })->values();

        $status = strtolower((string) ($user->status ?? 'pending'));
        $statusLabels = [
            'pending' => 'Account Pending Activation',
            'review' => 'Account Under Review',
            'active' => 'Account Active',
            'inactive' => 'Account Inactive',
            'rejected' => 'Account Rejected',
        ];

        $docStatus = function (bool $verified): string {
            return $verified ? 'Verified' : 'Pending';
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'photo' => $user->photo ? asset($user->photo) : null,
            'account_status' => $user->status,
            'account_status_label' => $statusLabels[$status] ?? ucfirst((string) $user->status),
            'stats' => [
                'rating' => $avgRating,
                'reviews' => $reviewCount,
                'experience' => $user->experience ?: null,
            ],
            'personal_info' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'district_id' => $user->district_id,
                'district' => $user->district->name ?? null,
                'member_since' => $user->memberSince(),
            ],
            'availability' => $availability,
            'bio' => $user->bio,
            'skills' => $skills,
            'service_area' => $serviceArea,
            'service_area_label' => $serviceAreaLabel,
            'experience' => $user->experience,
            'documents' => [
                'photo' => [
                    'uploaded' => !empty($user->photo),
                    'url' => $user->photo ? asset($user->photo) : null,
                    'status' => $docStatus((bool) $user->photo_verified),
                    'verified' => (bool) $user->photo_verified,
                ],
                'cnic_front' => [
                    'uploaded' => !empty($user->cnic_front),
                    'url' => $user->cnic_front ? asset($user->cnic_front) : null,
                    'status' => $docStatus((bool) $user->cnic_front_verified),
                    'verified' => (bool) $user->cnic_front_verified,
                ],
                'cnic_back' => [
                    'uploaded' => !empty($user->cnic_back),
                    'url' => $user->cnic_back ? asset($user->cnic_back) : null,
                    'status' => $docStatus((bool) $user->cnic_back_verified),
                    'verified' => (bool) $user->cnic_back_verified,
                ],
                'certificates' => [
                    'uploaded' => $certificates !== [],
                    'urls' => array_map(fn ($path) => asset($path), $certificates),
                    'status' => $docStatus((bool) $user->certificates_verified),
                    'verified' => (bool) $user->certificates_verified,
                ],
            ],
        ];
    }

}
