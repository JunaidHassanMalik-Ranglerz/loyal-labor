<?php

use App\Http\Controllers\Admin\AddonsController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\Auth\NewPasswordController;
use App\Http\Controllers\Admin\Auth\PasswordResetLinkController;
use App\Http\Controllers\Staff\Auth\PasswordResetLinkController as StaffPasswordResetLinkController;
use App\Http\Controllers\Staff\Auth\NewPasswordController as StaffNewPasswordController;
use App\Http\Controllers\Admin\StaffDashboardController;
use App\Http\Controllers\Staff\Auth\StaffAuthenticatedSessionController;
use App\Http\Controllers\Staff\ShopController;
use App\Http\Controllers\Staff\StaffboardController;
use App\Http\Middleware\StaffAuthMiddleware;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\StateController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CacheController;


$adminPrefix = config('custom.admin_login_prefix', 'admin');
$staffPrefix = config('custom.staff_login_prefix', 'staff');
Route::post('/admin/staff/change-status/{id}', [\App\Http\Controllers\Admin\StaffController::class , 'changeStatus'])->name('staff.change.status');
Route::get('/dashboard', [\App\Http\Controllers\Admin\StaffDashboardController::class , 'index'])->name('staff.dashboard');
Route::post('/shops/save-draft', [\App\Http\Controllers\Admin\StaffDashboardController::class , 'saveDraft'])->name('staff.shops.save-draft');
Route::get('/shops/get-draft', [\App\Http\Controllers\Admin\StaffDashboardController::class , 'getDraft'])->name('staff.shops.get-draft');
Route::post('/shops/clear-draft', [\App\Http\Controllers\Admin\StaffDashboardController::class , 'clearDraft'])->name('staff.shops.clear-draft');
Route::post('/shops/direct-save', [\App\Http\Controllers\Admin\StaffDashboardController::class , 'directSave'])->name('staff.shops.direct-save'); // New route
Route::get('/clear-cache', [CacheController::class, 'clearAllCache'])->name('clear.cache');
Route::post('users/verify-email/{user}', [App\Http\Controllers\Admin\UserController::class, 'verifyEmail'])->name('admin.users.verify-email');
// Assign Permissions Direct Routes
Route::middleware(['auth:admin'])->group(function () {
    Route::get('admin/assign-permissions', [App\Http\Controllers\Admin\RolesController::class, 'assignPermissionsForm'])->name('assign.permissions.form');
    Route::get('get-role-permissions/{role}', [App\Http\Controllers\Admin\RolesController::class, 'getRolePermissions'])->name('get.role.permissions');
    Route::put('update-role-permissions', [App\Http\Controllers\Admin\RolesController::class, 'updateRolePermissions'])->name('update.role.permissions');
});


if ($adminPrefix !== 'admin') {
    Route::prefix($adminPrefix)->name('admin.')->group(function () {
        Route::get('login', [AuthenticatedSessionController::class , 'create'])->name('login');
        Route::post('	', [AuthenticatedSessionController::class , 'store'])->name('store-login');
        Route::get('forgot-password', [PasswordResetLinkController::class , 'create'])->name('password.request');
        Route::post('/forget-password', [PasswordResetLinkController::class , 'custom_forget_password'])->name('forget-password');
        Route::get('reset-password/{token}', [NewPasswordController::class , 'custom_reset_password_page'])->name('password.reset');
        Route::post('/reset-password-store/{token}', [NewPasswordController::class , 'custom_reset_password_store'])->name('password.reset-store');
    });
}
else {
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('login', [AuthenticatedSessionController::class , 'create'])->name('login');
        Route::post('store-login', [AuthenticatedSessionController::class , 'store'])->name('store-login');
        Route::get('forgot-password', [PasswordResetLinkController::class , 'create'])->name('password.request');
        Route::post('/forget-password', [PasswordResetLinkController::class , 'custom_forget_password'])->name('forget-password');
        Route::get('reset-password/{token}', [NewPasswordController::class , 'custom_reset_password_page'])->name('password.reset');
        Route::post('/reset-password-store/{token}', [NewPasswordController::class , 'custom_reset_password_store'])->name('password.reset-store');
    });
}

if ($adminPrefix !== 'staff') {
    Route::prefix($staffPrefix)->name('staff.')->group(function () {
        Route::get('login', [StaffAuthenticatedSessionController::class , 'create'])->name('login');
        Route::post('store-login', [StaffAuthenticatedSessionController::class , 'store'])->name('store-login');
        Route::get('forgot-password', [StaffPasswordResetLinkController::class , 'create'])->name('password.request');
        Route::post('/forget-password', [StaffPasswordResetLinkController::class , 'custom_forget_password'])->name('forget-password');
        Route::get('reset-password/{token}', [StaffNewPasswordController::class , 'custom_reset_password_page'])->name('password.reset');
        Route::post('/reset-password-store/{token}', [StaffNewPasswordController::class , 'custom_reset_password_store'])->name('password.reset-store');

        Route::post('logout', [StaffAuthenticatedSessionController::class , 'destroy'])
            ->name('logout');
    });
}
else {
    Route::prefix('staff')->name('staff.')->group(function () {
        Route::get('login', [StaffAuthenticatedSessionController::class , 'create'])->name('login');
        Route::post('store-login', [StaffAuthenticatedSessionController::class , 'store'])->name('store-login');
        Route::get('forgot-password', [StaffPasswordResetLinkController::class , 'create'])->name('password.request');
        Route::post('/forget-password', [StaffPasswordResetLinkController::class , 'custom_forget_password'])->name('forget-password');
        Route::get('reset-password/{token}', [StaffNewPasswordController::class , 'custom_reset_password_page'])->name('password.reset');
        Route::post('/reset-password-store/{token}', [StaffNewPasswordController::class , 'custom_reset_password_store'])->name('password.reset-store');

        Route::post('logout', [StaffAuthenticatedSessionController::class , 'destroy'])
            ->name('logout');
    });
}

Route::prefix('staff')->name('staff.')->middleware([StaffAuthMiddleware::class, 'staff.status'])->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\Staff\StaffboardController::class , 'dashboard'])->name('dashboard');

    // Shop Management Routes for Staff
    Route::get('/shops', [App\Http\Controllers\Staff\ShopController::class , 'index'])->name('shop.index');
    Route::get('/shops/create', [App\Http\Controllers\Staff\ShopController::class , 'create'])->name('shop.create');
    Route::post('/shops', [App\Http\Controllers\Staff\ShopController::class , 'store'])->name('shop.store');
    Route::get('/shops/{id}', [App\Http\Controllers\Staff\ShopController::class , 'show'])->name('shop.show');
    Route::get('/shops/{id}/edit', [App\Http\Controllers\Staff\ShopController::class , 'edit'])->name('shop.edit');
    Route::put('/shops/{id}', [App\Http\Controllers\Staff\ShopController::class , 'update'])->name('shop.update');
    Route::delete('/shops/{id}', [App\Http\Controllers\Staff\ShopController::class , 'destroy'])->name('shop.destroy');

    // Photo Routes for Staff
    Route::delete('shop/delete-photo/{photoId}', [ShopController::class, 'deletePhoto'])->name('shop.delete-photo');
    Route::post('shop/set-primary-photo', [ShopController::class, 'setPrimaryPhoto'])->name('shop.set-primary-photo');
    Route::controller(\App\Http\Controllers\Staff\ProfileController::class)->group(function () {
            Route::get('edit-profile', 'edit_profile')->name('edit-profile');
            Route::put('profile-update', 'profile_update')->name('profile-update');
            Route::put('update-password', 'update_password')->name('update-password');
        }
        );
        
        // Staff Jobs Routes
        Route::get('jobs', [\App\Http\Controllers\Staff\JobController::class, 'index'])->name('jobs.index');
        Route::get('jobs/{id}', [\App\Http\Controllers\Staff\JobController::class, 'show'])->name('jobs.show');
        Route::post('jobs/mark-done/{id}', [\App\Http\Controllers\Staff\JobController::class, 'markAsDone'])->name('jobs.mark-done');
        Route::post('jobs/mark-undone/{id}', [\App\Http\Controllers\Staff\JobController::class, 'markAsUndone'])->name('jobs.mark-undone');
    });

    Route::group(['as' => 'admin.', 'prefix' => 'admin'], function () {
    
    Route::middleware(['auth:admin'])->group(function () {
        
        // ✅ SAHI TAREEQA - 'admin/' already hai, toh direct 'shop-management/categories' likho
        Route::get('shop-management/categories', [\App\Http\Controllers\Admin\ShopManagementController::class, 'Shopindex'])
            ->name('shop-categories.index');
        
        Route::post('shop-management/categories/store', [\App\Http\Controllers\Admin\ShopManagementController::class, 'store'])
            ->name('shop-categories.store');
        
        Route::put('shop-management/categories/update-status/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'updateStatus'])
            ->name('shop-categories.update-status');
        
        Route::delete('shop-management/categories/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'destroy'])
            ->name('shop-categories.destroy');

        Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
        Route::post('users/change-status/{id}', [\App\Http\Controllers\Admin\UserController::class, 'changeStatus'])
            ->name('users.change-status');
        Route::post('users/{user}/verify-document', [\App\Http\Controllers\Admin\UserController::class, 'verifyDocument'])
            ->name('users.verify-document');
        Route::post('users/{user}/verify-payment', [\App\Http\Controllers\Admin\UserController::class, 'verifyPayment'])
            ->name('users.verify-payment');
        Route::resource('bookings', \App\Http\Controllers\Admin\BookingController::class)->only(['index', 'destroy']);
        Route::get('bookings-settings', [\App\Http\Controllers\Admin\BookingController::class, 'settings'])->name('bookings.settings');
        Route::post('bookings-settings', [\App\Http\Controllers\Admin\BookingController::class, 'updateSettings'])->name('bookings.settings.update');

        Route::get('app-notifications', [\App\Http\Controllers\Admin\AppNotificationController::class, 'index'])->name('app-notifications.index');
        Route::get('app-notifications/create', [\App\Http\Controllers\Admin\AppNotificationController::class, 'create'])->name('app-notifications.create');
        Route::get('app-notifications/search-users', [\App\Http\Controllers\Admin\AppNotificationController::class, 'searchUsers'])->name('app-notifications.search-users');
        Route::post('app-notifications', [\App\Http\Controllers\Admin\AppNotificationController::class, 'store'])->name('app-notifications.store');
        Route::post('app-notifications/{id}/send', [\App\Http\Controllers\Admin\AppNotificationController::class, 'send'])->name('app-notifications.send');
        Route::delete('app-notifications/{id}', [\App\Http\Controllers\Admin\AppNotificationController::class, 'destroy'])->name('app-notifications.destroy');
    });
});


Route::group(['as' => 'admin.', 'prefix' => 'admin'], function () {
    /* Start admin auth route */
    Route::post('logout', [AuthenticatedSessionController::class , 'destroy'])->name('logout');


    /* End admin auth route */

    Route::middleware(['auth:admin', 'admin.status'])->group(function () {
            Route::get('/', [DashboardController::class , 'dashboard']);
            Route::get('dashboard', [DashboardController::class , 'dashboard'])->name('dashboard');

            Route::resource('country', CountryController::class);
            Route::resource('city', CityController::class);
            Route::resource('state', StateController::class);
            Route::get('/all-states-by-country/{id}', [StateController::class , 'getAllStateByCountry'])->name('get.all.states.by.country');

            Route::get('/all-cities-by-state/{id}', [CityController::class , 'getAllCitiesByState'])->name('get.all.cities.by.state');

            Route::controller(AdminProfileController::class)->group(function () {
                    Route::get('edit-profile', 'edit_profile')->name('edit-profile');
                    Route::put('profile-update', 'profile_update')->name('profile-update');
                    Route::put('update-password', 'update_password')->name('update-password');
                }
                );

                // Roles CRUD Routes
                Route::resource('/role', RolesController::class);
                

                // Assign Roles to Admins (new)
                Route::get('assign-roles', [\App\Http\Controllers\Admin\AssignRoleController::class, 'index'])->name('assign-roles.index');
                Route::post('assign-roles', [\App\Http\Controllers\Admin\AssignRoleController::class, 'assign'])->name('assign-roles.assign');
                Route::get('assign-roles/{admin}', [\App\Http\Controllers\Admin\AssignRoleController::class, 'getAdminRoles'])->name('assign-roles.get');

                Route::resource('admin', AdminController::class)->except('show');
                Route::put('admin-status/{id}', [AdminController::class , 'changeStatus'])->name('admin.status');

                Route::resource('staff', \App\Http\Controllers\Admin\StaffController::class);

                // District Routes
                Route::resource('districts', \App\Http\Controllers\Admin\DistrictController::class);

                // Subscriptions Routes
                Route::resource('subscriptions', \App\Http\Controllers\Admin\SubscriptionController::class);
                Route::post('subscriptions/change-status/{id}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'changeStatus'])
                    ->name('subscriptions.change-status');

                // Subscription payments (PayFast) + per-admin notifications
                Route::get('subscription-payments', [\App\Http\Controllers\Admin\SubscriptionPaymentController::class, 'index'])
                    ->name('subscription-payments.index');
                Route::get('subscription-payments/{id}', [\App\Http\Controllers\Admin\SubscriptionPaymentController::class, 'show'])
                    ->name('subscription-payments.show');
                Route::get('subscription-payment-notifications/feed', [\App\Http\Controllers\Admin\SubscriptionPaymentNotificationController::class, 'feed'])
                    ->name('subscription-payment-notifications.feed');
                Route::get('subscription-payment-notifications/unread-count', [\App\Http\Controllers\Admin\SubscriptionPaymentNotificationController::class, 'unreadCount'])
                    ->name('subscription-payment-notifications.unread-count');
                Route::post('subscription-payment-notifications/mark-all-read', [\App\Http\Controllers\Admin\SubscriptionPaymentNotificationController::class, 'markAllRead'])
                    ->name('subscription-payment-notifications.mark-all-read');
                Route::post('subscription-payment-notifications/{id}/read', [\App\Http\Controllers\Admin\SubscriptionPaymentNotificationController::class, 'markRead'])
                    ->name('subscription-payment-notifications.mark-read');
                Route::post('broadcasting/auth', function (\Illuminate\Http\Request $request) {
                    return Broadcast::auth($request);
                })->middleware('auth:admin')->name('broadcasting.auth');

                // Service Categories Routes
                Route::resource('service-categories', \App\Http\Controllers\Admin\ServiceCategoryController::class)->except(['show']);
                Route::post('service-categories/change-status/{id}', [\App\Http\Controllers\Admin\ServiceCategoryController::class, 'changeStatus'])
                    ->name('service-categories.change-status');

                // Help Supports (Complaints) Routes
                Route::resource('help-supports', \App\Http\Controllers\Admin\HelpSupportController::class)->only(['index', 'show', 'destroy']);
                Route::post('districts/change-status/{id}', [\App\Http\Controllers\Admin\DistrictController::class, 'changeStatus'])->name('districts.change-status');
                Route::post('districts/bulk-delete', [\App\Http\Controllers\Admin\DistrictController::class, 'bulkDelete'])->name('districts.bulk-delete');
                Route::get('districts/active', [\App\Http\Controllers\Admin\DistrictController::class, 'getActiveDistricts'])->name('districts.active');
                Route::get('districts/search', [\App\Http\Controllers\Admin\DistrictController::class, 'search'])->name('districts.search');

                // Staff Permissions Routes
                Route::get('staff-permissions', [\App\Http\Controllers\Admin\StaffPermissionController::class, 'index'])->name('staff-permissions.index');
                Route::get('staff-permissions/{id}', [\App\Http\Controllers\Admin\StaffPermissionController::class, 'show'])->name('staff-permissions.show');
                Route::get('staff-permissions/{id}/edit', [\App\Http\Controllers\Admin\StaffPermissionController::class, 'edit'])->name('staff-permissions.edit');
                Route::put('staff-permissions/{id}', [\App\Http\Controllers\Admin\StaffPermissionController::class, 'update'])->name('staff-permissions.update');

                // Admin Activity Logs Routes
                Route::get('activity-logs', [\App\Http\Controllers\Admin\AdminActivityController::class, 'index'])->name('activity-logs.index');
                Route::get('activity-logs/{activity}', [\App\Http\Controllers\Admin\AdminActivityController::class, 'show'])->name('activity-logs.show');
                Route::get('activity-logs/admin/{admin}', [\App\Http\Controllers\Admin\AdminActivityController::class, 'getAdminActivities'])->name('activity-logs.admin');

                // Shop Management Routes
                Route::get('shop-management', [\App\Http\Controllers\Admin\ShopManagementController::class, 'index'])->name('shop-management.index');
                Route::get('shop-management/shop-list', [\App\Http\Controllers\Admin\ShopManagementController::class, 'shopList'])->name('shop-management.shop-list');
                Route::get('shop-management/job-details', [\App\Http\Controllers\Admin\ShopManagementController::class, 'jobDetails'])->name('shop-management.job-details');
                Route::get('shop-management/job-notes', [\App\Http\Controllers\Admin\ShopManagementController::class, 'jobNotes'])->name('shop-management.job-notes');
                Route::get('shop-management/job/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'showJobDetails'])->name('shop-management.job-details.show');
                Route::delete('assigned-jobs/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'delete'])->name('assigned-jobs.destroy');
                Route::get('shop-management/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'show'])->name('shop-management.show');
                Route::post('shop-management/toggle-job-status/{id}', [\App\Http\Controllers\Admin\ShopManagementController::class, 'toggleJobStatus'])->name('shop-management.toggle-job-status');
                Route::put('{photo}/set-primary-photo', [App\Http\Controllers\Admin\ShopManagementController::class, 'setPrimaryPhoto'])
                    ->name('shop.set-primary-photo');
                Route::post('shop-management/{id}/assign', [\App\Http\Controllers\Admin\ShopManagementController::class, 'assignJob'])->name('shop-management.assign');
                Route::get('shop-management/{id}/district', [\App\Http\Controllers\Admin\ShopManagementController::class, 'getShopDistrict'])->name('shop-management.get-shop-district');
                Route::get('shop-management/staff/permissions', [\App\Http\Controllers\Admin\ShopManagementController::class, 'getStaffWithPermissions'])->name('shop-management.staff-permissions');
                Route::post('shop-management/bulk-assign', [\App\Http\Controllers\Admin\ShopManagementController::class, 'bulkAssign'])->name('shop-management.bulk-assign');
                
                Route::get('settings', [SettingController::class , 'settings'])->name('settings');
                Route::get('sync-modules', [AddonsController::class , 'syncModules'])->name('addons.sync');
            }
            );
        });
