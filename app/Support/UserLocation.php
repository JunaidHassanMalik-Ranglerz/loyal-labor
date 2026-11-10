<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserLocation
{
    public static function coordinatesFromRequest(Request $request): array
    {
        $lat = $request->input('latitude', $request->input('lat'));
        $lng = $request->input('longitude', $request->input('lng', $request->input('long')));

        $lat = ($lat === '' || $lat === null) ? null : (is_numeric($lat) ? (float) $lat : null);
        $lng = ($lng === '' || $lng === null) ? null : (is_numeric($lng) ? (float) $lng : null);

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }

        // Both must be valid together; never return a half pair.
        if ($lat === null || $lng === null) {
            return [null, null];
        }

        return [$lat, $lng];
    }

    /**
     * Prefer request coordinates over saved user coordinates.
     * Optionally persist request coords immediately for subsequent calls.
     *
     * @return array{0: ?float, 1: ?float, 2: string} [lat, lng, source]
     */
    public static function resolve(Request $request, ?User $user = null, bool $persistRequestCoords = true): array
    {
        [$requestLat, $requestLng] = self::coordinatesFromRequest($request);

        if ($requestLat !== null && $requestLng !== null) {
            if ($persistRequestCoords && $user) {
                self::save($user, $requestLat, $requestLng, self::fcmTokenFromRequest($request));
            }

            return [$requestLat, $requestLng, 'request'];
        }

        if ($user && GeoDistance::isValidPair($user->latitude ?? null, $user->longitude ?? null)) {
            return [(float) $user->latitude, (float) $user->longitude, 'saved'];
        }

        return [null, null, 'none'];
    }

    public static function fcmTokenFromRequest(Request $request): ?string
    {
        $token = $request->input('fcmtoken', $request->input('fcm_token'));
        $token = is_string($token) ? trim($token) : '';

        return $token !== '' ? $token : null;
    }

    public static function save(User $user, ?float $latitude, ?float $longitude, ?string $fcmToken = null): User
    {
        self::ensureColumns();

        $data = [];

        if ($latitude !== null && $longitude !== null && Schema::hasColumn('users', 'latitude')) {
            $data['latitude'] = $latitude;
            $data['longitude'] = $longitude;
        }

        if ($fcmToken !== null && $fcmToken !== '' && Schema::hasColumn('users', 'fcmtoken')) {
            $data['fcmtoken'] = $fcmToken;
        }

        if ($data !== []) {
            $user->forceFill($data)->save();
        }

        return $user;
    }

    public static function saveFromRequest(User $user, Request $request): User
    {
        [$lat, $lng] = self::coordinatesFromRequest($request);

        return self::save($user, $lat, $lng, self::fcmTokenFromRequest($request));
    }

    public static function ensureColumns(): void
    {
        if (!Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function ($table) {
                $table->decimal('latitude', 10, 8)->nullable();
            });
        }

        if (!Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function ($table) {
                $table->decimal('longitude', 11, 8)->nullable();
            });
        }
    }
}
