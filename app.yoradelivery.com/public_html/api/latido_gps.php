<?php
/**
 * GPS del conductor (APK Online). Acepta Bearer / X-Yora-Token o sesión web.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();

$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
$heading = (isset($_POST['heading']) && $_POST['heading'] !== '') ? (float) $_POST['heading'] : null;
$acc = (isset($_POST['acc']) && $_POST['acc'] !== '') ? (float) $_POST['acc'] : null;

if (!yora_coords_ok($lat, $lng)) {
    yora_fail('GPS inválido.');
}

yora_guardar_gps_conductor($conexion, $conductor_id, $lat, $lng, $heading, $acc);
yora_json(['status' => 'success', 'ts' => date('Y-m-d H:i:s')]);
