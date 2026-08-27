<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DistrictController;
use App\Http\Controllers\Api\HelpSupportController;
use App\Http\Controllers\Api\TechnicianController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Home Services Mobile API (prefix: /api)
|--------------------------------------------------------------------------
| Local base URL example:
| http://localhost/homeservices-12Mar2026/api
*/

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Home Services API is running.',
        'base_url' => url('/api'),
        'public_endpoints' => [
            'POST /api/register',
            'POST /api/register-verify-otp',
            'POST /api/verify-otp',
            'POST /api/login',
            'GET  /api/districts',
            'POST /api/forgot-password',
            'POST /api/reset-password',
            'GET  /api/technicians?filter=top_rated',
            'GET  /api/technicians/{id}/reviews',
        ],
        'auth_header' => 'Authorization: Bearer {token}',
    ]);
});

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/register-verify-otp', [AuthController::class, 'registerVerifyOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/districts', [DistrictController::class, 'index']);
Route::match(['get', 'post'], '/districts/{districtId}/cities', [DistrictController::class, 'cities']);
Route::match(['get', 'post'], '/cities', [DistrictController::class, 'cities']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/technicians', [TechnicianController::class, 'getTechnicians']);
Route::get('/technicians/{technicianId}/profile', [TechnicianController::class, 'getTechnicianProfile']);
Route::get('/technicians/{technicianId}/reviews', [\App\Http\Controllers\Api\ReviewController::class, 'technicianReviews']);
Route::get('/skills', [TechnicianController::class, 'getSkills']);
Route::get('/subscriptions', [TechnicianController::class, 'getSubscriptions']);
Route::get('/get-service-category', [TechnicianController::class, 'getServiceCategory']);
Route::match(['get', 'post'], '/service-categories/search', [TechnicianController::class, 'searchServiceCategories']);
Route::match(['get', 'post'], '/search-service-category', [TechnicianController::class, 'searchServiceCategories']);
Route::get('/bookings/expiry-settings', [BookingController::class, 'getExpirySettings']);
Route::post('/register-resend-otp', [AuthController::class, 'registerresendOtp']);


// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/profile/update', [AuthController::class, 'updateProfile']);
    Route::get('/addresses', [\App\Http\Controllers\Api\SavedAddressController::class, 'index']);
    Route::post('/addresses', [\App\Http\Controllers\Api\SavedAddressController::class, 'store']);
    Route::post('/addresses/{id}', [\App\Http\Controllers\Api\SavedAddressController::class, 'update']);
    Route::put('/addresses/{id}', [\App\Http\Controllers\Api\SavedAddressController::class, 'update']);
    Route::delete('/addresses/{id}', [\App\Http\Controllers\Api\SavedAddressController::class, 'destroy']);
    Route::post('/addresses/{id}/default', [\App\Http\Controllers\Api\SavedAddressController::class, 'makeDefault']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Help & Support
    Route::post('/help-support', [HelpSupportController::class, 'submit']);

    // Technician
    Route::post('/technician/submit-verification', [TechnicianController::class, 'submitVerification']);
    Route::post('/technician/activate-subscription', [TechnicianController::class, 'activateSubscription']);
    Route::post('/technician/change-subscription-plan', [TechnicianController::class, 'changeSubscriptionPlan']);
    Route::get('/technician/status', [TechnicianController::class, 'status']);
    Route::post('/technician/availability', [TechnicianController::class, 'updateAvailability']);
    Route::post('/technician/availability/toggle', [TechnicianController::class, 'toggleDayAvailability']);
    Route::post('/technician/currently-available', [TechnicianController::class, 'toggleCurrentlyAvailable']);
	Route::get('/technician/availability', [TechnicianController::class, 'checkTechnicianAvailability']);
    Route::post('/technician/work-gallery', [TechnicianController::class, 'uploadWorkGallery']);
    Route::get('/technician/work-gallery', [TechnicianController::class, 'myWorkGallery']);
    Route::delete('/technician/work-gallery/{galleryId}', [TechnicianController::class, 'deleteWorkGallery']);
    Route::match(['get', 'post'], '/technician/active-jobs', [BookingController::class, 'activeJobs']);
    Route::match(['get', 'post'], '/technician/pending-requests', [BookingController::class, 'pendingRequests']);
    Route::match(['get', 'post'], '/technician/booking-requests', [BookingController::class, 'pendingRequests']);
    Route::match(['get', 'post'], '/technician/broadcast-emergency-requests', [BookingController::class, 'getTechnicianBroadcastEmergencyRequests']);
    Route::match(['get', 'post'], '/technician/emergency-broadcast-requests', [BookingController::class, 'getTechnicianBroadcastEmergencyRequests']);
    Route::match(['get', 'post'], '/technician/booking-history', [BookingController::class, 'technicianBookingHistory']);
    Route::get('/technician/job-status-get/{bookingId}', [BookingController::class, 'getTechnicianJobStatus']);
    Route::get('/technician/job-status/{bookingId}', [BookingController::class, 'getTechnicianJobStatus']);
    Route::get('/technician/job-status/{bookingId}/get', [BookingController::class, 'getTechnicianJobStatus']);

    // Bookings
    Route::post('/bookings/broadcast', [BookingController::class, 'broadcastRequest']);
    Route::get('/bookings/{bookingId}/broadcast-status', [BookingController::class, 'getBroadcastStatus']);
    Route::post('/bookings/{bookingId}/expand-radius', [BookingController::class, 'expandBroadcastRadius']);
    Route::post('/bookings/{bookingId}/select-technician', [BookingController::class, 'selectBroadcastTechnician']);
    Route::post('/bookings/{bookingId}/approve-technician', [BookingController::class, 'approveBroadcastTechnician']);
    Route::post('/bookings/{bookingId}/decline-technician', [BookingController::class, 'declineBroadcastTechnician']);
    Route::post('/bookings', [BookingController::class, 'bookTechnician']);
    Route::get('/bookings/my', [BookingController::class, 'myBookings']);
    Route::match(['get', 'post'], '/bookings/history', [BookingController::class, 'technicianBookingHistory']);
    Route::get('/bookings/requests', [BookingController::class, 'getBookingRequests']);
    Route::match(['get', 'post'], '/bookings/active-jobs', [BookingController::class, 'activeJobs']);
    Route::match(['get', 'post'], '/bookings/pending-requests', [BookingController::class, 'pendingRequests']);
    Route::match(['get', 'post'], '/bookings/booking-requests', [BookingController::class, 'pendingRequests']);
    Route::match(['get', 'post'], '/booking-requests', [BookingController::class, 'pendingRequests']);
    Route::get('/bookings/{bookingId}/confirmation', [BookingController::class, 'getBookingConfirmation']);
    Route::get('/bookings/{bookingId}/status', [BookingController::class, 'getBookingStatus']);
    Route::get('/bookings/{bookingId}', [BookingController::class, 'getBookingDetails']);
    Route::post('/bookings/{bookingId}/accept', [BookingController::class, 'acceptBooking']);
    Route::post('/bookings/{bookingId}/reject', [BookingController::class, 'rejectBooking']);
    Route::post('/bookings/{bookingId}/cancel', [BookingController::class, 'cancelBooking']);
    Route::post('/bookings/{bookingId}/status', [BookingController::class, 'updateStatus']);
    Route::post('/bookings/{bookingId}/complete', [BookingController::class, 'completeBooking']);
    Route::post('/bookings/{bookingId}/verify-completion-otp', [BookingController::class, 'verifyCompletionOtp']);

    Route::post('/reviews/technician', [\App\Http\Controllers\Api\ReviewController::class, 'storeTechnicianReview'])
        ->middleware('auth:sanctum');
    Route::post('/technicians/{technicianId}/rate', [\App\Http\Controllers\Api\ReviewController::class, 'rateTechnician'])
        ->middleware('auth:sanctum');

    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\NotificationController::class, 'clearAll']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
});

// Keep API 404 inside api group (avoid web.php fallback when method/URL is wrong)
Route::fallback(function (Request $request) {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found. Check URL and HTTP method (GET/POST).',
        'path' => $request->path(),
        'method' => $request->method(),
        'hint' => str_starts_with($request->path(), 'api/register')
            ? 'Use POST /api/register (browser GET will not work).'
            : null,
    ], 404);
});