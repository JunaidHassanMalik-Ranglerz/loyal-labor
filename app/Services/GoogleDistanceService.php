<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDistanceService
{
    public function hasApiKey(): bool
    {
        return trim((string) config('services.google_maps.key')) !== '';
    }

    public function distanceKm($lat1, $lon1, $lat2, $lon2): ?float
    {
        if (!$this->isValidCoordinate($lat1, $lon1) || !$this->isValidCoordinate($lat2, $lon2)) {
            return null;
        }

        $originLat = (float) $lat1;
        $originLng = (float) $lon1;
        $destLat = (float) $lat2;
        $destLng = (float) $lon2;
        $fallback = $this->haversineKm($originLat, $originLng, $destLat, $destLng);

        $distances = $this->distancesFromOrigin($originLat, $originLng, [
            ['lat' => $destLat, 'lng' => $destLng],
        ]);

        return $distances[0] ?? $fallback;
    }

    /**
     * Road distance in km from one origin to many destinations.
     * Uses Google Distance Matrix when GOOGLE_MAPS_KEY is set, otherwise Haversine.
     *
     * @param  array<int, array{lat?: mixed, lng?: mixed, latitude?: mixed, longitude?: mixed}>  $destinations
     * @return array<int, float|null>
     */
    public function distancesFromOrigin(float $originLat, float $originLng, array $destinations): array
    {
        $results = [];

        foreach ($destinations as $index => $destination) {
            [$destLat, $destLng] = $this->destinationPair($destination);
            $results[$index] = $this->isValidCoordinate($destLat, $destLng)
                ? $this->haversineKm($originLat, $originLng, $destLat, $destLng)
                : null;
        }

        if (!$this->hasApiKey() || !$this->isValidCoordinate($originLat, $originLng)) {
            return $results;
        }

        $uncached = [];
        foreach ($destinations as $index => $destination) {
            [$destLat, $destLng] = $this->destinationPair($destination);
            if (!$this->isValidCoordinate($destLat, $destLng)) {
                $results[$index] = null;
                continue;
            }

            $cachedMeters = Cache::get($this->cacheKey($originLat, $originLng, $destLat, $destLng));
            if ($cachedMeters !== null) {
                $results[$index] = $this->metersToKm((float) $cachedMeters);
                continue;
            }

            $uncached[$index] = ['lat' => $destLat, 'lng' => $destLng];
        }

        if (empty($uncached)) {
            return $results;
        }

        $key = (string) config('services.google_maps.key');

        foreach (array_chunk($uncached, 25, true) as $chunk) {
            try {
                $destString = collect($chunk)
                    ->map(fn ($point) => $point['lat'] . ',' . $point['lng'])
                    ->implode('|');

                $response = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                    'origins' => $originLat . ',' . $originLng,
                    'destinations' => $destString,
                    'units' => 'metric',
                    'key' => $key,
                ]);

                $elements = $response->json('rows.0.elements');
                if (!is_array($elements)) {
                    continue;
                }

                $offset = 0;
                foreach ($chunk as $index => $point) {
                    $element = $elements[$offset] ?? null;
                    $offset++;

                    if (!is_array($element) || ($element['status'] ?? null) !== 'OK') {
                        continue;
                    }

                    $meters = $element['distance']['value'] ?? null;
                    if ($meters === null) {
                        continue;
                    }

                    Cache::put(
                        $this->cacheKey($originLat, $originLng, $point['lat'], $point['lng']),
                        (int) $meters,
                        600
                    );
                    $results[$index] = $this->metersToKm((float) $meters);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Distance Matrix batch failed: ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    public function isValidCoordinate($lat, $lng): bool
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

    private function destinationPair(array $destination): array
    {
        $lat = $destination['lat'] ?? $destination['latitude'] ?? null;
        $lng = $destination['lng'] ?? $destination['longitude'] ?? null;

        return [
            $lat !== null && $lat !== '' ? (float) $lat : null,
            $lng !== null && $lng !== '' ? (float) $lng : null,
        ];
    }

    private function metersToKm(float $meters): float
    {
        return round($meters / 1000, 1);
    }

    private function cacheKey(float $lat1, float $lon1, float $lat2, float $lon2): string
    {
        return 'gmaps_dist_' . implode('_', [
            round($lat1, 5),
            round($lon1, 5),
            round($lat2, 5),
            round($lon2, 5),
        ]);
    }
}
