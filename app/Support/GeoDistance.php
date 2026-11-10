<?php

namespace App\Support;

class GeoDistance
{
    /** Strict technician discovery radius (km). */
    public const TECHNICIAN_DISCOVERY_RADIUS_KM = 25.0;

    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Haversine distance in kilometers.
     * Returns null when any coordinate is missing/invalid.
     */
    public static function haversineKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if (!self::isValidPair($lat1, $lng1) || !self::isValidPair($lat2, $lng2)) {
            return null;
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function isValidPair(mixed $lat, mixed $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return false;
        }
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    public static function withinRadiusKm(
        ?float $originLat,
        ?float $originLng,
        ?float $destLat,
        ?float $destLng,
        float $radiusKm = self::TECHNICIAN_DISCOVERY_RADIUS_KM
    ): bool {
        $distance = self::haversineKm($originLat, $originLng, $destLat, $destLng);

        return $distance !== null && $distance <= $radiusKm;
    }

    /**
     * SQL Haversine expression using users.latitude / users.longitude.
     * Bindings order for select/where: [lat, lng, lat] then radius for where.
     */
    public static function usersHaversineSql(): string
    {
        return '(6371 * acos(LEAST(1, GREATEST(-1,
            cos(radians(?)) * cos(radians(users.latitude)) * cos(radians(users.longitude) - radians(?))
            + sin(radians(?)) * sin(radians(users.latitude))
        ))))';
    }

    /** Clamp discovery radius to the mandatory 25 km max. */
    public static function discoveryRadiusKm(?float $requested = null): float
    {
        if ($requested === null || $requested <= 0) {
            return self::TECHNICIAN_DISCOVERY_RADIUS_KM;
        }

        return min((float) $requested, self::TECHNICIAN_DISCOVERY_RADIUS_KM);
    }
}
