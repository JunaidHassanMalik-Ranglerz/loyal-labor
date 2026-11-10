<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\District;
use App\Models\SocialiteCredential;
use App\Models\TechnicianAvailability;
use App\Models\TechnicianWorkGallery;
use App\Models\User;
use App\Models\UserSavedAddress;
use App\Services\SocialAuthService;
use App\Support\UserLocation;
use App\Traits\GlobalMailTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stripe\Review;
use UnexpectedValueException;

class AuthController extends Controller
{
    use GlobalMailTrait;

    /**
     * Cache key prefix used to stash registration data + OTP until it is
     * verified via register-verify-otp and actually persisted to the DB.
     */
    private const PENDING_REGISTRATION_PREFIX = 'pending_registration_';

public function register(Request $request)
{
    try {
        \Log::info('Registration started', $request->all());
        
        // ✅ Convert JSON strings to arrays (for form-data requests)
        if ($request->has('skills') && is_string($request->skills)) {
            $request->merge(['skills' => json_decode($request->skills, true)]);
        }
        
        if ($request->has('service_area') && is_string($request->service_area)) {
            $request->merge(['service_area' => json_decode($request->service_area, true)]);
        }
        
        if ($request->has('availability') && is_string($request->availability)) {
            $request->merge(['availability' => json_decode($request->availability, true)]);
        }

        // ✅ Convert services JSON string to array if needed
        if ($request->has('services') && is_string($request->services)) {
            $request->merge(['services' => json_decode($request->services, true)]);
        }

        // ✅ Convert category_ids JSON string to array (form-data support)
        if ($request->has('category_ids') && is_string($request->category_ids)) {
            $request->merge(['category_ids' => json_decode($request->category_ids, true)]);
        }
        
        // ✅ Base validation rules
        $rules = [
            'user_type' => 'required|in:customer,technician',
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => 'required',
            'district_id' => 'required|exists:districts,id',
            'password' => 'required|min:6',
            'cnic_front' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            'cnic_back' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
            'photo' => 'nullable|file|mimes:jpg,png,jpeg|max:5120',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
            'experience' => 'nullable|string',
            'service_area' => 'nullable|array',
            'availability' => 'nullable|array',
            'services' => 'nullable|array',
            'category_ids' => 'required_if:user_type,technician|array',
            'category_ids.*' => 'integer|exists:service_categories,id',
            'work_gallery' => 'nullable|array|max:20',
            'work_gallery.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120',
            'work_gallery_captions' => 'nullable|array',
            'work_gallery_captions.*' => 'nullable|string|max:255',
            'shop_reference_code' => 'nullable|string|exists:shops,reference_code',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'long' => 'nullable|numeric|between:-180,180',
            'fcmtoken' => 'nullable|string|max:255',
            'fcm_token' => 'nullable|string|max:255',
        ];
        
        // Add availability validation only if provided
        if ($request->has('availability') && is_array($request->availability)) {
            $rules['availability.*.day'] = 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday';
            $rules['availability.*.start'] = 'nullable|date_format:H:i';
            $rules['availability.*.end'] = 'nullable|date_format:H:i';
            $rules['availability.*.is_available'] = 'nullable|boolean';
        }
        
        $request->validate($rules);
        
        // ✅ Ensure directory exists
        $uploadPath = public_path('backend/img');
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
            \Log::info('Created directory: ' . $uploadPath);
        }
        
        $cnicFront = null;
        $cnicBack = null;
        $photo = null;
        $certificates = null;
        $skills = null;
        $service_area = null;
        $services = null;
        
        // ✅ File upload handling (only for technician)
        if ($request->user_type == 'technician') {

            // ✅ Store CNIC Front
            if ($request->hasFile('cnic_front')) {
                $file = $request->file('cnic_front');

                if ($file->isValid()) {
                    $filename = 'cnic_front_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move($uploadPath, $filename);
                    $cnicFront = 'backend/img/' . $filename;

                    \Log::info('CNIC Front saved: ' . $cnicFront);
                }
            }
            
            // ✅ Store CNIC Back
            if ($request->hasFile('cnic_back')) {
                $file = $request->file('cnic_back');

                if ($file->isValid()) {
                    $filename = 'cnic_back_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move($uploadPath, $filename);
                    $cnicBack = 'backend/img/' . $filename;

                    \Log::info('CNIC Back saved: ' . $cnicBack);
                }
            }
            
            // ✅ Store Profile Photo
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');

                if ($file->isValid()) {
                    $filename = 'profile_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move($uploadPath, $filename);
                    $photo = 'backend/img/' . $filename;

                    \Log::info('Profile photo saved: ' . $photo);
                }
            }
            
            // ✅ Handle certificates
            $certificatesArray = [];
            
            if ($request->hasFile('certificates')) {
                $certificatesInput = $request->file('certificates');
                
                if (!is_array($certificatesInput)) {

                    if ($certificatesInput->isValid()) {
                        $filename = 'cert_' . time() . '_' . uniqid() . '.' . $certificatesInput->getClientOriginalExtension();
                        $certificatesInput->move($uploadPath, $filename);
                        $certificatesArray[] = 'backend/img/' . $filename;
                    }

                } else {

                    foreach ($certificatesInput as $file) {

                        if ($file && $file->isValid()) {
                            $filename = 'cert_' . time() . '_' . uniqid() . '_' . rand(1000, 9999) . '.' . $file->getClientOriginalExtension();
                            $file->move($uploadPath, $filename);
                            $certificatesArray[] = 'backend/img/' . $filename;
                        }

                    }
                }
            }
            
            if (
                empty($certificatesArray) &&
                $request->has('certificates') &&
                is_string($request->certificates)
            ) {
                $decoded = json_decode($request->certificates, true);

                if (is_array($decoded)) {
                    $certificatesArray = $decoded;
                }
            }
            
            $certificates = !empty($certificatesArray)
                ? json_encode($certificatesArray)
                : null;

            $skills = $request->has('skills')
                ? json_encode($request->skills)
                : null;

            $service_area = $request->has('service_area')
                ? json_encode($request->service_area)
                : null;
            
            // ✅ Store services as JSON
            $services = $request->has('services')
                ? json_encode($request->services)
                : null;
        }
        
        // ✅ Generate OTP
        $otp = rand(100000, 999999);
        
        // ✅ Resolve shop reference code
        $referralShopId = null;

        if ($request->filled('shop_reference_code')) {
            $referralShopId = \App\Models\Shop::where(
                'reference_code',
                $request->shop_reference_code
            )->value('id');
        }
        
        // ✅ Build user data (NOT saved to database)
        $userData = [
            'user_type' => $request->user_type,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,

            // Existing response remains exactly the same
            'fcmtoken' => $request->fcmtoken ?? $request->fcm_token ?? '',
            'address' => $request->address ?? '',
            'latitude' => null,
            'longitude' => null,
            'district_id' => $request->district_id,
            'password' => Hash::make($request->password),
            'otp' => $otp,
            'is_verified' => false,
            'cnic_front' => $cnicFront,
            'cnic_back' => $cnicBack,
            'photo' => $photo,
            'certificates' => $certificates,
            'bio' => $request->bio,
            'skills' => $skills,
            'experience' => $request->experience,
            'service_area' => $service_area,
            'services' => $services,
            'status' => $request->user_type == 'technician'
                ? 'pending'
                : 'active',
            'shop_reference_code' => $request->shop_reference_code,
            'referral_shop_id' => $referralShopId,
        ];

        [$latitude, $longitude] = UserLocation::coordinatesFromRequest($request);
        if (Schema::hasColumn('users', 'latitude')) {
            $userData['latitude'] = $latitude;
            $userData['longitude'] = $longitude;
        } else {
            unset($userData['latitude'], $userData['longitude']);
        }

        // ============================================================
        // ✅ ONLY NEW ADDITION:
        // Keep address separately for UserSavedAddress after OTP verify
        // ============================================================
        $savedAddressData = [
            'label' => 'Home',
            'address' => $request->address ?? '',
            'city' => $request->city ?? '',
        ];
        
        // ✅ Availability data
        $availabilityData = [];

        if ($request->user_type == 'technician') {
            $availabilityData = $request->has('availability') &&
                !empty($request->availability)
                ? $request->availability
                : $this->getDefaultAvailability();
        }
        
        // ✅ Service category ids
        $categoryIds = $request->user_type == 'technician'
            ? array_values(
                array_unique(
                    array_map(
                        'intval',
                        (array) $request->input('category_ids', [])
                    )
                )
            )
            : [];

        $workGalleryItems = $request->user_type == 'technician'
            ? $this->storeWorkGalleryUploads($request)
            : [];

        // #region agent log
        @file_put_contents(
            base_path('debug-545283.log'),
            json_encode([
                'sessionId' => '545283',
                'hypothesisId' => 'H1',
                'location' => 'AuthController::register',
                'message' => 'register work gallery files',
                'data' => [
                    'user_type' => $request->user_type,
                    'has_work_gallery' => $request->hasFile('work_gallery'),
                    'saved_count' => count($workGalleryItems)
                ],
                'timestamp' => (int) round(microtime(true) * 1000)
            ]) . "\n",
            FILE_APPEND
        );
        // #endregion

        // ✅ Store in cache (NOT in database)
        Cache::put(
            self::PENDING_REGISTRATION_PREFIX . strtolower($request->email),
            [
                'user_data' => $userData,
                'availability' => $availabilityData,
                'category_ids' => $categoryIds,
                'work_gallery' => $workGalleryItems,

                // ====================================================
                // ✅ ONLY NEW CACHE DATA FOR ADDRESS
                // ====================================================
                'saved_address' => $savedAddressData,

            ],
            now()->addMinutes(30)
        );
        
        \Log::info(
            'Pending registration cached (not saved to users table)',
            ['email' => $request->email]
        );
        
        // ✅ Send OTP via email
        $emailError = null;

        try {
            $this->sendOtpMail(
                $request->email,
                $request->name,
                $otp
            );

            \Log::info('OTP email sent to: ' . $request->email);

        } catch (\Exception $e) {

            $emailError = $e->getMessage();

            \Log::error('OTP email failed: ' . $emailError);
        }
        
        // ✅ Prepare response with ALL request data + OTP
        $responseUser = collect($userData)
            ->except(['password'])
            ->toArray();

        $responseUser['category_ids'] = $categoryIds;

        $responseUser['work_gallery'] = collect($workGalleryItems)
            ->map(function ($item) {
                return [
                    'image' => asset($item['image']),
                    'caption' => $item['caption'] ?? null,
                ];
            })
            ->values();
        
        // ✅ Add availability to response
        $responseUser['availabilities'] = collect($availabilityData)
            ->map(function ($schedule) {
                return [
                    'day' => $schedule['day'] ?? null,
                    'start_time' => $schedule['start'] ?? null,
                    'end_time' => $schedule['end'] ?? null,
                    'is_available' => $schedule['is_available'] ?? true,
                    'specific_date' => $schedule['specific_date'] ?? null,
                ];
            })
            ->values();

        if ($emailError) {

            return response()->json([
                'message' => 'Registration data saved temporarily, but OTP email failed to send.',
                'email_sent' => false,
                'email_error' => $emailError,
                'user' => $responseUser,
                'otp' => $otp,
                'email_exists' => false,
            ], 500);
        }
        
        return response()->json([
            'message' => 'OTP sent to your email. Please verify the OTP.',
            'email_sent' => true,
            'user' => $responseUser,
            'otp' => $otp,
            'email_exists' => false,
        ], 200);
        
    } catch (\Illuminate\Validation\ValidationException $e) {

        \Log::error('Validation failed: ' . $e->getMessage());
        
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
        
    } catch (\Exception $e) {

        \Log::error('Registration failed: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'message' => 'Registration failed: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ], 500);
    }
}

private function sendOtpMail(string $email, string $userName, string $otp): void
{
    $appName = loyalBrandName();

    try {
        [$subject, $message] = $this->fetchEmailTemplate('registration_otp', [
            'user_name' => $userName,
            'otp' => $otp,
            'app_name' => $appName,
        ]);
    } catch (\Throwable $e) {
        $subject = $appName . ' - Email Verification';
        $message = '<p style="margin:0 0 12px;">Dear ' . e($userName) . ',</p><p>Your verification code is: <strong style="color:#FE7701;font-size:24px;letter-spacing:6px;">' . e($otp) . '</strong></p><p style="font-size:13px;color:#999;">This code expires in 30 minutes.</p>';
    }

        $this->sendMail($email, $subject, $message, [], true);
}

private function sendPasswordResetOtpMail(string $email, string $userName, string $otp): void
{
    $appName = loyalBrandName();

    try {
        [$subject, $message] = $this->fetchEmailTemplate('password_reset_otp', [
            'user_name' => $userName,
            'otp' => $otp,
            'app_name' => $appName,
        ]);
    } catch (\Throwable $e) {
        $subject = $appName . ' - Password Reset';
        $message = '<p style="margin:0 0 12px;">Dear ' . e($userName) . ',</p><p>Your password reset code is: <strong style="color:#FE7701;font-size:24px;letter-spacing:6px;">' . e($otp) . '</strong></p><p style="font-size:13px;color:#999;">This code expires in 30 minutes.</p>';
    }

        $this->sendMail($email, $subject, $message, [], true);
}

// Helper method for default availability
private function getDefaultAvailability(): array
{
    $availability = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
        $availability[] = [
            'day' => $day,
            'start' => '09:00',
            'end' => '18:00',
            'is_available' => true,
            'specific_date' => null,
        ];
    }
    
    $availability[] = [
        'day' => 'sunday',
        'start' => null,
        'end' => null,
        'is_available' => false,
        'specific_date' => null,
    ];
    
    return $availability;
}

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
        ]);

        $email = strtolower($request->email);
        $pendingKey = self::PENDING_REGISTRATION_PREFIX . $email;

        // Register stores the user in cache until OTP is verified — the row
        // is not in `users` yet, so exists:users was rejecting valid OTPs.
        if (Cache::has($pendingKey)) {
            return $this->registerVerifyOtp($request);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'message' => 'No pending registration found for this email, or the OTP has expired. Please register again.',
                'errors' => [
                    'email' => ['No account or pending registration found for this email.'],
                ],
            ], 422);
        }

        if ((string) $user->otp !== (string) $request->otp) {
            return response()->json(['message' => 'Invalid OTP'], 400);
        }

        $user->update(['is_verified' => true, 'otp' => null]);

        return response()->json(['message' => 'Email verified']);
    }

public function registerresendOtp(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        // ✅ Generate new OTP
        $otp = rand(100000, 999999);

        // ✅ Save/refresh OTP in register_otps table (email par updateOrCreate)
        \App\Models\RegisterOtp::updateOrCreate(
            ['email' => $email],
            ['otp' => $otp]
        );

        // ✅ Agar pending registration cache me hai to uska OTP bhi refresh karo
        $cacheKey = self::PENDING_REGISTRATION_PREFIX . $email;
        $pending = Cache::get($cacheKey);
        if ($pending) {
            $pending['user_data']['otp'] = $otp;
            Cache::put($cacheKey, $pending, now()->addMinutes(30));
        }

        // ✅ Send OTP via email — error return hoga agar mail fail ho
        try {
            $this->sendOtpMail($email, $pending['user_data']['name'] ?? 'User', $otp);
        } catch (\Exception $mailEx) {
            \Log::error('Resend OTP email failed: ' . $mailEx->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'OTP generated but email failed to send.',
                'email_sent' => false,
                'email_error' => $mailEx->getMessage(),
                'otp' => $otp,
                'email' => $email,
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'OTP resent successfully.',
            'email_sent' => true,
            'otp' => $otp,
            'email' => $email
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        \Log::error('Resend OTP failed: ' . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to generate OTP. Please try again.',
        ], 500);
    }
}

    /**
     * Verify the OTP sent during /register and, only if it matches, actually
     * create the user record (and technician availability, if applicable)
     * in the users table. Nothing is saved to the DB until this succeeds.
     */
  public function registerVerifyOtp(Request $request)
{
    try {
        // ✅ Validate request
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|numeric|digits:6',
        ]);

        $email = strtolower($request->email);
        $cacheKey = self::PENDING_REGISTRATION_PREFIX . $email;
        $pending = Cache::get($cacheKey);

        if (!$pending) {
            return response()->json([
                'message' => 'No pending registration found for this email, or the OTP has expired. Please register again.',
            ], 404);
        }

        // ✅ Verify OTP
        if ((string) $pending['user_data']['otp'] !== (string) $request->otp) {
            return response()->json(['message' => 'Invalid OTP'], 400);
        }

        // ✅ Check if user already exists (race condition guard)
        if (User::where('email', $email)->exists()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'This email is already registered.'], 409);
        }

        // ✅ Get user data from cache
        $userData = $pending['user_data'];
        
        // ✅ Remove OTP and set verification status
        // NOTE: the `users` table does NOT have an `email_verified_at` column
        // (it uses `is_verified` instead) - do not set it here, or the INSERT
        // fails with "Unknown column 'email_verified_at'".
        $userData['otp'] = null;
        $userData['is_verified'] = true;

        \Log::info('OTP verified, saving user to users table', ['email' => $email]);

        UserLocation::ensureColumns();

        // ✅ CREATE USER IN DATABASE
        $user = User::create($userData);

        // ✅ Save registration address in UserSavedAddress
        $savedAddressData = $pending['saved_address'] ?? [];
        UserSavedAddress::create([
            'user_id' => $user->id,
            'label' => $savedAddressData['label'] ?? 'Home',
            'address' => $savedAddressData['address'] ?? ($user->address ?? ''),
            'city' => $savedAddressData['city'] ?? '',
        ]);

        // ✅ Attach service categories (many-to-many) - only after OTP verified
        if (!empty($pending['category_ids'])) {
            $user->serviceCategories()->sync($pending['category_ids']);
        }

        // ✅ Create availability for technician
        if ($user->user_type == 'technician' && !empty($pending['availability'])) {
            foreach ($pending['availability'] as $schedule) {
                \App\Models\TechnicianAvailability::create([
                    'technician_id' => $user->id,
                    'day' => $schedule['day'],
                    'start_time' => $schedule['start'] ?? null,
                    'end_time' => $schedule['end'] ?? null,
                    'is_available' => $schedule['is_available'] ?? true,
                    'specific_date' => $schedule['specific_date'] ?? null,
                ]);
            }
            \Log::info('Availability created for technician', ['technician_id' => $user->id]);
        }

        $savedGallery = [];
        if ($user->user_type == 'technician' && !empty($pending['work_gallery'])) {
            foreach ($pending['work_gallery'] as $index => $item) {
                $gallery = TechnicianWorkGallery::create([
                    'technician_id' => $user->id,
                    'image' => $item['image'],
                    'caption' => $item['caption'] ?? null,
                    'sort_order' => $index + 1,
                ]);
                $savedGallery[] = [
                    'id' => $gallery->id,
                    'image' => asset($gallery->image),
                    'caption' => $gallery->caption,
                ];
            }
        }

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H2', 'location' => 'AuthController::registerVerifyOtp', 'message' => 'work gallery persisted', 'data' => ['user_id' => $user->id, 'cached_count' => count($pending['work_gallery'] ?? []), 'saved_count' => count($savedGallery)], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        // ✅ Clear cache after successful registration
        Cache::forget($cacheKey);

        // ✅ Prepare response with user data (including all fields)
        $responseUser = $user->toArray();
        $responseUser['service_categories'] = $user->serviceCategories()->get(['service_categories.id', 'name'])->toArray();
        
        // ✅ Add availabilities to response
        if ($user->user_type == 'technician') {
            $responseUser['availabilities'] = $user->availabilities->map(function ($availability) {
                return [
                    'id' => $availability->id,
                    'day' => $availability->day,
                    'start_time' => $availability->start_time,
                    'end_time' => $availability->end_time,
                    'is_available' => $availability->is_available,
                    'specific_date' => $availability->specific_date,
                ];
            });
            $responseUser['work_gallery'] = $savedGallery;
        }

        // ✅ Generate authentication token
        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'OTP verified. Registration completed successfully.',
            'user' => $responseUser,
            'token' => $token,
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        \Log::error('register-verify-otp failed: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());

        return response()->json([
            'message' => 'OTP verification failed: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ], 500);
    }
}
    public function login(Request $request)
    {
        $request->merge([
            'latitude' => $request->input('latitude', $request->input('lat')),
            'longitude' => $request->input('longitude', $request->input('lng', $request->input('long'))),
            'fcmtoken' => $request->input('fcmtoken', $request->input('fcm_token')),
        ]);

        $request->validate([
            'user_type' => 'required|in:customer,technician',
            'email' => 'required|email',
            'password' => 'required',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'fcmtoken' => 'nullable|string|max:255',
            'fcm_token' => 'nullable|string|max:255',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if ($user->user_type !== $request->user_type) {
            $correctType = ucfirst($user->user_type);

            return response()->json([
                'error' => 'Incorrect user type',
                'message' => "This email is registered as {$correctType}. Please select {$correctType} and try again.",
            ], 403);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->is_verified) {
            return response()->json(['error' => 'Verify email first'], 401);
        }

        UserLocation::save(
            $user,
            (float) $request->latitude,
            (float) $request->longitude,
            UserLocation::fcmTokenFromRequest($request)
        );
        $user->refresh();

        return response()->json([
            'message' => 'Logged in successfully',
            'user' => $user,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'token' => $user->createToken('auth')->plainTextToken,
        ]);
    }

    // POST /api/login/google
    public function loginWithGoogle(Request $request, SocialAuthService $socialAuth)
    {
        return $this->handleSocialLogin($request, $socialAuth, 'google');
    }

    // POST /api/login/apple
    public function loginWithApple(Request $request, SocialAuthService $socialAuth)
    {
        return $this->handleSocialLogin($request, $socialAuth, 'apple');
    }

    // POST /api/social-login  (provider = google|apple)
    public function socialLogin(Request $request, SocialAuthService $socialAuth)
    {
        $request->validate([
            'provider' => 'required|in:google,apple',
        ]);

        return $this->handleSocialLogin($request, $socialAuth, $request->provider);
    }

    private function handleSocialLogin(Request $request, SocialAuthService $socialAuth, string $provider)
    {
        $request->merge([
            'latitude' => $request->input('latitude', $request->input('lat')),
            'longitude' => $request->input('longitude', $request->input('lng', $request->input('long'))),
            'fcmtoken' => $request->input('fcmtoken', $request->input('fcm_token')),
            'id_token' => $request->input('id_token', $request->input('identity_token', $request->input('token'))),
        ]);

        $request->validate([
            'user_type' => 'required|in:customer,technician',
            'id_token' => 'required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'district_id' => 'nullable|exists:districts,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'fcmtoken' => 'nullable|string|max:255',
            'fcm_token' => 'nullable|string|max:255',
        ]);

        try {
            $identity = $provider === 'apple'
                ? $socialAuth->verifyAppleIdentityToken(
                    $request->id_token,
                    $request->input('email'),
                    $request->input('name')
                )
                : $socialAuth->verifyGoogleIdToken($request->id_token);
        } catch (UnexpectedValueException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        } catch (\Throwable $e) {
            Log::error('Social login verify failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not verify social login token. Please try again.',
            ], 401);
        }

        $providerId = $identity['provider_id'];
        $email = $identity['email'] ?: ($request->filled('email') ? strtolower(trim($request->email)) : null);
        $name = $identity['name'] ?: ($request->input('name') ?: 'User');

        $credential = SocialiteCredential::where('provider_name', $provider)
            ->where('provider_id', $providerId)
            ->first();

        $user = $credential?->user;
        $isNewUser = false;

        if (!$user && $email) {
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            if ($user->user_type !== $request->user_type) {
                $correctType = ucfirst($user->user_type);

                return response()->json([
                    'success' => false,
                    'error' => 'Incorrect user type',
                    'message' => "This account is registered as {$correctType}. Please select {$correctType} and try again.",
                ], 403);
            }

            if (!$credential) {
                $user->socialite()->create([
                    'provider_name' => $provider,
                    'provider_id' => $providerId,
                    'access_token' => null,
                    'refresh_token' => null,
                ]);
            }
        } else {
            if (!$email) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is required for first-time Apple/Google signup. Please share email permission and try again.',
                ], 422);
            }

            $districtId = $request->input('district_id');
            if (!$districtId) {
                $districtId = District::query()->value('id');
            }

            $create = [
                'user_type' => $request->user_type,
                'name' => $name,
                'email' => $email,
                'phone' => $request->input('phone', ''),
                'password' => Hash::make(Str::random(32)),
                'is_verified' => true,
                'status' => $request->user_type === 'technician' ? 'pending' : 'active',
            ];

            if (Schema::hasColumn('users', 'fcmtoken')) {
                $create['fcmtoken'] = UserLocation::fcmTokenFromRequest($request) ?? '';
            }
            if (Schema::hasColumn('users', 'district_id') && $districtId) {
                $create['district_id'] = $districtId;
            }
            if (Schema::hasColumn('users', 'email_verified_at')) {
                $create['email_verified_at'] = now();
            }

            $user = User::create($create);
            $isNewUser = true;

            $user->socialite()->create([
                'provider_name' => $provider,
                'provider_id' => $providerId,
                'access_token' => null,
                'refresh_token' => null,
            ]);
        }

        if ($request->filled('phone') && empty($user->phone)) {
            $user->phone = $request->phone;
        }
        if ($request->filled('district_id') && empty($user->district_id)) {
            $user->district_id = $request->district_id;
        }
        if ($request->filled('name') && ($user->name === 'User' || $user->name === '')) {
            $user->name = $request->name;
        }
        $user->is_verified = true;
        $user->save();

        if ($request->filled('latitude') && $request->filled('longitude')) {
            UserLocation::save(
                $user,
                (float) $request->latitude,
                (float) $request->longitude,
                UserLocation::fcmTokenFromRequest($request)
            );
        } elseif ($fcm = UserLocation::fcmTokenFromRequest($request)) {
            UserLocation::save($user, null, null, $fcm);
        }

        $user->refresh();

        $profileComplete = !empty($user->phone) && !empty($user->district_id);

        return response()->json([
            'success' => true,
            'message' => $isNewUser
                ? ucfirst($provider) . ' account created and logged in successfully.'
                : 'Logged in successfully with ' . ucfirst($provider) . '.',
            'provider' => $provider,
            'is_new_user' => $isNewUser,
            'profile_complete' => $profileComplete,
            'user' => $user,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'token' => $user->createToken('auth')->plainTextToken,
        ]);
    }

public function profile(Request $request)
{
    try {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $user->load(['district', 'availabilities', 'subscriptionPlan']);

        // =============================================
        // BASE RESPONSE
        // =============================================
        $response = [
            'id' => $user->id,
            'user_type' => $user->user_type,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'photo' => $user->photo ? asset($user->photo) : null,
            'address' => $user->address ?? 'Not added',
            'city' => $user->city ?? 'N/A',
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,

            // ✅ Email Verified Status (Common for both)
            'email_verified' => $user->is_verified ? 'verified' : 'pending',

            'personal_info' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'company' => $user->company ?? 'N/A',
                'position' => $user->position ?? ($user->user_type === 'technician' ? $user->bio : 'Customer'),
                'member_since' => $user->memberSince(),
                'member_since_from' => $user->memberSinceFrom(),
            ],
        ];

        $response['member_since'] = $user->memberSince();
        $response['member_since_from'] = $user->memberSinceFrom();
        $response['verified_label'] = $user->is_verified
            ? ($user->user_type === 'technician' ? 'Verified Technician' : 'Verified Customer')
            : null;

        $savedAddresses = SavedAddressController::listForUser((int) $user->id);
        $response['saved_addresses'] = [
            'total' => $savedAddresses->count(),
            'items' => $savedAddresses,
        ];

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H1', 'location' => 'AuthController::profile', 'message' => 'profile member since + addresses', 'data' => ['user_id' => $user->id, 'created_at' => optional($user->created_at)->toDateTimeString(), 'member_since' => $response['member_since'], 'member_since_from' => $response['member_since_from'], 'saved_addresses_total' => $savedAddresses->count()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        // =============================================
        // TECHNICIAN
        // =============================================
        if ($user->user_type === 'technician') {

            // Booking Stats
            $bookingStats = DB::table('bookings')
                ->where('technician_id', $user->id)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled
                ')
                ->first();

            // Reviews Count
            $reviewsCount = DB::table('reviews')
                ->where('technician_id', $user->id)
                ->where('is_approved', 1)
                ->count();

            $response['stats'] = [
                'total_bookings' => $bookingStats->total ?? 0,
                'completed' => $bookingStats->completed ?? 0,
                'pending' => $bookingStats->pending ?? 0,
                'reviews' => $reviewsCount,
            ];

            // Authoritative subscription states for frontend:
            // none | payment_pending | active | expired
            $expired = $user->subscription_end
                && now()->toDateString() > (string) $user->subscription_end;
            $hasPlanSelected = (bool) $user->subscriptionPlan && (int) ($user->subscription_id ?? 0) > 0;
            $paymentVerified = $user->isPaymentVerified();
            $subscriptionActive = $user->hasActiveSubscription();

            if ($expired && $hasPlanSelected) {
                $lifecycleStatus = 'expired';
            } elseif ($subscriptionActive) {
                $lifecycleStatus = 'active';
            } elseif ($hasPlanSelected && ($user->payment_status === 'pending' || $user->subscription === 'pending')) {
                $lifecycleStatus = 'payment_pending';
            } elseif ($hasPlanSelected) {
                $lifecycleStatus = 'inactive';
            } else {
                $lifecycleStatus = 'none';
            }

            $response['verifications'] = [
                'cnic_front' => $user->cnic_front_verified ? 'verified' : 'pending',
                'cnic_back' => $user->cnic_back_verified ? 'verified' : 'pending',
                'photo' => $user->photo_verified ? 'verified' : 'pending',
                'certificates' => $user->certificates_verified ? 'verified' : 'pending',
                'subscription' => $subscriptionActive ? 'verified' : 'pending',
            ];

            $response['availability'] = $user->availabilities->map(function ($avail) {
                return [
                    'day' => ucfirst($avail->day),
                    'start_time' => $avail->start_time,
                    'end_time' => $avail->end_time,
                    'is_available' => $avail->is_available,
                ];
            });

            // Subscription Plan — show whenever technician has purchased/selected a plan.
            if ($user->subscriptionPlan) {
                $plan = $user->subscriptionPlan;
                $planStatus = match ($lifecycleStatus) {
                    'active' => 'active',
                    'expired' => 'expired',
                    'payment_pending' => 'pending',
                    default => 'inactive',
                };

                $latestPaid = \App\Models\SubscriptionPayment::query()
                    ->where('technician_id', $user->id)
                    ->where('subscription_id', $plan->id)
                    ->where('status', \App\Models\SubscriptionPayment::STATUS_PAID)
                    ->latest('verified_at')
                    ->first();

                $expiresAt = $user->subscription_end;
                $activatedAt = optional($latestPaid?->activated_at)->toIso8601String()
                    ?: ($subscriptionActive && $user->updated_at
                        ? $user->updated_at->toIso8601String()
                        : null);

                $response['subscription_plan'] = array_merge($plan->toApiArray(), [
                    'name' => $plan->name ?: $plan->plan_type_label,
                    'plan_name' => $plan->name ?: $plan->plan_type_label,
                    'status' => $planStatus,
                    'lifecycle_status' => $lifecycleStatus,
                    'expires_at' => $expiresAt,
                    'activated_at' => $activatedAt,
                    // Keep legacy "verified/pending" plus RN-compatible "paid".
                    'payment_status' => $paymentVerified
                        ? 'paid'
                        : ($user->payment_status ?? 'pending'),
                    'is_payment_verified' => $paymentVerified,
                    'is_subscription_active' => $subscriptionActive,
                ]);
            } else {
                $response['subscription_plan'] = null;
            }

            $response['subscription_lifecycle_status'] = $lifecycleStatus;
            // Top-level aliases expected by React Native activation checks.
            $response['is_payment_verified'] = $paymentVerified;
            $response['is_subscription_active'] = $subscriptionActive;
            $response['payment_status'] = $paymentVerified
                ? 'paid'
                : (($user->payment_status === 'pending') ? 'pending' : ($user->payment_status ?? 'none'));
            $response['activated_at'] = $response['subscription_plan']['activated_at'] ?? null;
            $response['expires_at'] = $response['subscription_plan']['expires_at'] ?? null;

            $response['district_name'] = $user->district->name ?? 'N/A';
        }

        // =============================================
        // CUSTOMER
        // =============================================
        if ($user->user_type === 'customer') {

            // Booking Stats
            $bookingStats = DB::table('bookings')
                ->where('customer_id', $user->id)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled
                ')
                ->first();

            // Reviews Count
            $reviewsCount = DB::table('reviews')
                ->where('customer_id', $user->id)
                ->where('is_approved', 1)
                ->count();

            $response['stats'] = [
                'total_bookings' => (int) ($bookingStats->total ?? 0),
                'completed' => (int) ($bookingStats->completed ?? 0),
                'pending' => (int) ($bookingStats->pending ?? 0),
                'reviews' => $reviewsCount,
                'reviews_given' => $reviewsCount,
            ];

            $response['recent_bookings'] = DB::table('bookings')
                ->join('users as technicians', 'bookings.technician_id', '=', 'technicians.id')
                ->where('bookings.customer_id', $user->id)
                ->orderBy('bookings.created_at', 'desc')
                ->limit(10)
                ->select(
                    'bookings.id',
                    'bookings.booking_reference',
                    'bookings.status',
                    'bookings.service_date',
                    'bookings.total_amount',
                    'bookings.payment_status',
                    'bookings.created_at',
                    'technicians.name as technician_name',
                    'technicians.photo as technician_photo'
                )
                ->get()
                ->map(function ($booking) {
                    return [
                        'id' => $booking->id,
                        'booking_reference' => $booking->booking_reference,
                        'status' => $booking->status,
                        'service_date' => $booking->service_date,
                        'total_amount' => $booking->total_amount,
                        'payment_status' => $booking->payment_status,
                        'technician_name' => $booking->technician_name ?? 'N/A',
                        'technician_photo' => $booking->technician_photo ? asset($booking->technician_photo) : null,
                        'created_at' => $booking->created_at,
                    ];
                });

            $response['district_name'] = $user->district->name ?? 'N/A';
        }

        return response()->json([
            'status' => true,
            'message' => 'Profile fetched successfully',
            'data' => $response
        ]);

    } catch (\Exception $e) {
        Log::error('Profile Error: ' . $e->getMessage());
        return response()->json([
            'status' => false,
            'message' => 'Server Error',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

   public function forgotPassword(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        $otp = (string) rand(100000, 999999);
        $user->update([
            'otp' => $otp,
            'reset_password_token' => null,
            'reset_token_expires_at' => now()->addMinutes(30),
        ]);

        $emailError = null;
        try {
            $this->sendPasswordResetOtpMail($request->email, $user->name, $otp);
            Log::info('Password reset OTP email sent to: ' . $request->email);
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            Log::error('Password reset OTP email failed: ' . $emailError);
        }

        if ($emailError) {
            return response()->json([
                'success' => false,
                'message' => 'OTP generated but password reset email failed to send.',
                'email_sent' => false,
                'email_error' => $emailError,
                'otp' => $otp,
                'email' => $request->email,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset OTP sent to your email.',
            'email_sent' => true,
            'email' => $request->email,
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        \Log::error('Forgot password failed: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to process request. Please try again.',
        ], 500);
    }
}

    public function resetPassword(Request $request)
    {

        $user = User::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('reset_token_expires_at', '>', now())
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid or expired OTP'], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'otp' => null,
            'reset_password_token' => null,
            'reset_token_expires_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $rules = [
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'long' => 'nullable|numeric|between:-180,180',
            'fcmtoken' => 'nullable|string|max:255',
            'fcm_token' => 'nullable|string|max:255',
        ];

        if ($user->user_type === 'technician') {
            $rules['bio'] = 'nullable|string';
            $rules['experience'] = 'nullable|string';
            $rules['skills'] = 'nullable|array';
            $rules['service_area'] = 'nullable|array';
        }

        $request->validate($rules);

        $data = $request->only(['name', 'phone']);

        if ($user->user_type === 'technician') {
            if ($request->has('bio')) $data['bio'] = $request->bio;
            if ($request->has('experience')) $data['experience'] = $request->experience;
            if ($request->has('skills')) {
                $data['skills'] = is_array($request->skills) ? json_encode($request->skills) : $request->skills;
            }
            if ($request->has('service_area')) {
                $data['service_area'] = is_array($request->service_area) ? json_encode($request->service_area) : $request->service_area;
            }
        }

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('profiles', 'public');
            $data['photo'] = $path;
        }

        $user->update($data);
        UserLocation::saveFromRequest($user, $request);
        $user = $user->fresh();
        $savedAddresses = SavedAddressController::listForUser((int) $user->id);

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'hypothesisId' => 'H1', 'location' => 'AuthController::updateProfile', 'message' => 'update profile member since', 'data' => ['user_id' => $user->id, 'member_since' => $user->memberSince(), 'member_since_from' => $user->memberSinceFrom()], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'user_type' => $user->user_type,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo ? asset($user->photo) : null,
                'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
                'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
                'bio' => $user->bio,
                'experience' => $user->experience,
                'member_since' => $user->memberSince(),
                'member_since_from' => $user->memberSinceFrom(),
                'personal_info' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'member_since' => $user->memberSince(),
                    'member_since_from' => $user->memberSinceFrom(),
                ],
                'saved_addresses' => [
                    'total' => $savedAddresses->count(),
                    'items' => $savedAddresses,
                ],
            ],
        ]);
    }

    public function updateLocation(Request $request)
    {
        // Accept form-data / JSON: latitude+longitude OR lat+lng/long
        $request->merge([
            'latitude' => $request->input('latitude', $request->input('lat')),
            'longitude' => $request->input('longitude', $request->input('lng', $request->input('long'))),
        ]);

        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'fcmtoken' => 'nullable|string|max:255',
            'fcm_token' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        UserLocation::save(
            $user,
            (float) $request->latitude,
            (float) $request->longitude,
            UserLocation::fcmTokenFromRequest($request)
        );
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Location saved successfully.',
            'data' => [
                'user_id' => $user->id,
                'latitude' => (float) $user->latitude,
                'longitude' => (float) $user->longitude,
            ],
            'latitude' => (float) $user->latitude,
            'longitude' => (float) $user->longitude,
            'fcm_saved' => UserLocation::fcmTokenFromRequest($request) !== null,
        ]);
    }

    private function storeWorkGalleryUploads(Request $request): array
    {
        if (!$request->hasFile('work_gallery')) {
            return [];
        }

        $files = $request->file('work_gallery');
        if (!is_array($files)) {
            $files = [$files];
        }

        $captions = (array) $request->input('work_gallery_captions', []);
        $directory = public_path('backend/img/gallery');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $items = [];
        foreach ($files as $index => $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $filename = 'work_reg_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($directory, $filename);
            $items[] = [
                'image' => 'backend/img/gallery/' . $filename,
                'caption' => isset($captions[$index]) ? (string) $captions[$index] : null,
            ];
        }

        return $items;
    }
}
