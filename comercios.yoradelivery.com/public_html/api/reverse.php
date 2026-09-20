<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
yora_require_comercio();
$lat = (float) ($_POST['lat'] ?? 0);
$lng = (float) ($_POST['lng'] ?? 0);
if (!yora_coords_ok($lat, $lng)) {
    yora_fail('Coordenadas inválidas.');
}
if (!yora_rate_limit('revc:' . yora_client_ip(), 40, 60)) {
    yora_fail('Espera un momento.');
}
$nombre = yora_reverse_geocode_lara($lat, $lng);
yora_json(['status' => 'success', 'nombre' => $nombre, 'lat' => $lat, 'lng' => $lng]);
