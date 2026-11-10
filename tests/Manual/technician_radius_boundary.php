<?php

/**
 * Manual boundary harness (no DB):
 *   php tests/Manual/technician_radius_boundary.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Support\GeoDistance;

$originLat = 24.8607;
$originLng = 67.0011;
$radius = GeoDistance::TECHNICIAN_DISCOVERY_RADIUS_KM;

$cases = [
    ['label' => '0 km', 'km' => 0.0, 'expect' => true],
    ['label' => '5 km', 'km' => 5.0, 'expect' => true],
    ['label' => '12 km', 'km' => 12.0, 'expect' => true],
    ['label' => '20 km', 'km' => 20.0, 'expect' => true],
    ['label' => '24.9 km', 'km' => 24.9, 'expect' => true],
    ['label' => '25 km', 'km' => 25.0, 'expect' => true],
    ['label' => '25.1 km', 'km' => 25.1, 'expect' => false],
    ['label' => '30 km', 'km' => 30.0, 'expect' => false],
    ['label' => '50 km', 'km' => 50.0, 'expect' => false],
    ['label' => 'missing coords', 'km' => null, 'expect' => false],
];

$failed = 0;
foreach ($cases as $case) {
    if ($case['km'] === null) {
        $ok = !GeoDistance::withinRadiusKm($originLat, $originLng, null, null, $radius);
        $distance = null;
    } else {
        $destLat = $originLat + ($case['km'] / 111.2);
        $distance = GeoDistance::haversineKm($originLat, $originLng, $destLat, $originLng);
        $ok = GeoDistance::withinRadiusKm($originLat, $originLng, $destLat, $originLng, $radius) === $case['expect'];
    }

    $status = $ok ? 'PASS' : 'FAIL';
    if (!$ok) {
        $failed++;
    }

    echo sprintf(
        "[%s] %s | distance=%s | expect_include=%s\n",
        $status,
        $case['label'],
        $distance === null ? 'null' : round($distance, 3),
        $case['expect'] ? 'yes' : 'no'
    );
}

exit($failed === 0 ? 0 : 1);
