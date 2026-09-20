<?php
/**
 * GPS nativo de Median en segundo plano.
 * El WebView pausa el JavaScript al abrir Maps o al minimizar; este endpoint
 * sigue recibiendo la ubicación porque Median la POSTEA sin cookies.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yora_fail('Método no permitido', 405);
}

$conductor_id = (int) ($_GET['c'] ?? $_POST['c'] ?? 0);
$token = (string) ($_GET['k'] ?? $_POST['k'] ?? '');
if (!function_exists('yora_gps_fondo_ok') || !yora_gps_fondo_ok($conductor_id, $token)) {
    yora_fail('Token GPS inválido.', 403);
}

$raw = (string) file_get_contents('php://input');
$json = json_decode($raw, true);
$lat = 0.0;
$lng = 0.0;
$heading = null;
$acc = null;

if (is_array($json)) {
    $locs = $json['locations'] ?? null;
    if (is_array($locs) && $locs) {
        $last = $locs[count($locs) - 1];
        if (is_array($last)) {
            $lat = (float) ($last['latitude'] ?? $last['lat'] ?? 0);
            $lng = (float) ($last['longitude'] ?? $last['lng'] ?? 0);
            if (isset($last['bearing']) && is_numeric($last['bearing'])) {
                $heading = (float) $last['bearing'];
            }
            if (isset($last['horizontalAccuracy']) && is_numeric($last['horizontalAccuracy'])) {
                $acc = (float) $last['horizontalAccuracy'];
            }
        }
    }
    if (!yora_coords_ok($lat, $lng)) {
        $lat = (float) ($json['latitude'] ?? $json['lat'] ?? 0);
        $lng = (float) ($json['longitude'] ?? $json['lng'] ?? 0);
    }
}

if (!yora_coords_ok($lat, $lng)) {
    $lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
    $lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
}

if (!yora_coords_ok($lat, $lng)) {
    yora_fail('GPS inválido.');
}

yora_guardar_gps_conductor($conexion, $conductor_id, $lat, $lng, $heading, $acc);
yora_json(['status' => 'success']);
