<?php

namespace Tests\Unit;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    /** Karachi reference point */
    private float $originLat = 24.8607;
    private float $originLng = 67.0011;

    public function test_missing_coordinates_are_invalid(): void
    {
        $this->assertNull(GeoDistance::haversineKm($this->originLat, $this->originLng, null, 67.0));
        $this->assertFalse(GeoDistance::withinRadiusKm($this->originLat, $this->originLng, null, null));
    }

    public function test_zero_km_is_included(): void
    {
        $d = GeoDistance::haversineKm($this->originLat, $this->originLng, $this->originLat, $this->originLng);
        $this->assertNotNull($d);
        $this->assertEqualsWithDelta(0.0, $d, 0.001);
        $this->assertTrue(GeoDistance::withinRadiusKm($this->originLat, $this->originLng, $this->originLat, $this->originLng));
    }

    /**
     * ~1 degree latitude ≈ 111.2 km → offset degrees = km / 111.2
     *
     * @dataProvider distanceCases
     */
    public function test_radius_boundary(float $offsetKm, bool $shouldInclude): void
    {
        $destLat = $this->originLat + ($offsetKm / 111.2);
        $destLng = $this->originLng;

        $distance = GeoDistance::haversineKm($this->originLat, $this->originLng, $destLat, $destLng);
        $this->assertNotNull($distance);
        $this->assertEqualsWithDelta($offsetKm, $distance, 0.15);

        $within = GeoDistance::withinRadiusKm(
            $this->originLat,
            $this->originLng,
            $destLat,
            $destLng,
            GeoDistance::TECHNICIAN_DISCOVERY_RADIUS_KM
        );

        $this->assertSame($shouldInclude, $within, "offset={$offsetKm}km distance={$distance}");
    }

    public static function distanceCases(): array
    {
        return [
            '5 km include' => [5.0, true],
            '12 km include' => [12.0, true],
            '20 km include' => [20.0, true],
            '24.9 km include' => [24.9, true],
            '25 km include' => [25.0, true],
            '25.1 km exclude' => [25.1, false],
            '30 km exclude' => [30.0, false],
            '50 km exclude' => [50.0, false],
        ];
    }

    public function test_discovery_radius_is_capped_at_25(): void
    {
        $this->assertSame(25.0, GeoDistance::discoveryRadiusKm(null));
        $this->assertSame(25.0, GeoDistance::discoveryRadiusKm(50));
        $this->assertSame(10.0, GeoDistance::discoveryRadiusKm(10));
    }
}
