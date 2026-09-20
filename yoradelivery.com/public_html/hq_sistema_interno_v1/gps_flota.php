<?php
require_once __DIR__ . '/../../config.php';
yora_require_admin(true);
header('Content-Type: application/json; charset=utf-8');
yora_timezone($conexion);

// Quien no manda GPS hace > 90 s deja de estar "en línea" (app cerrada / sin latido).
try {
    yora_exec(
        $conexion,
        "UPDATE conductores
         SET en_linea = 0
         WHERE en_linea = 1
           AND (
             ultima_gps IS NULL
             OR ultima_gps = '0000-00-00 00:00:00'
             OR ultima_gps < DATE_SUB(NOW(), INTERVAL 90 SECOND)
           )"
    );
} catch (Throwable $e) {
    error_log('gps_flota stale offline: ' . $e->getMessage());
}

$filas = [];
try {
    $filas = yora_all(
        $conexion,
        "SELECT id, nombre, telefono, foto_perfil, tipo_vehiculo, placa, ultima_lat, ultima_lng, ultima_gps, ultima_heading, ultima_acc
         FROM conductores
         WHERE en_linea = 1
           AND estatus = 'activo'
           AND ultima_gps IS NOT NULL
           AND ultima_gps <> '0000-00-00 00:00:00'
           AND ultima_gps >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
         ORDER BY nombre ASC"
    ) ?: [];
} catch (Throwable $e) {
    $filas = yora_all(
        $conexion,
        "SELECT id, nombre, telefono, foto_perfil, tipo_vehiculo, placa, ultima_lat, ultima_lng, ultima_gps
         FROM conductores
         WHERE en_linea = 1 AND estatus = 'activo'
         ORDER BY nombre ASC"
    ) ?: [];
}

$drivers = [];
foreach ($filas as $d) {
    $lat = (float) ($d['ultima_lat'] ?? 0);
    $lng = (float) ($d['ultima_lng'] ?? 0);
    $ok = yora_coords_ok($lat, $lng);
    $gps_ts = strtotime((string) ($d['ultima_gps'] ?? '')) ?: 0;
    $hace = ($ok && $gps_ts > 0) ? max(0, time() - $gps_ts) : null;
    if ($hace === null || $hace > 90) {
        continue;
    }
    $heading = isset($d['ultima_heading']) && $d['ultima_heading'] !== null && $d['ultima_heading'] !== ''
        ? (float) $d['ultima_heading'] : null;
    $acc = isset($d['ultima_acc']) && $d['ultima_acc'] !== null && $d['ultima_acc'] !== ''
        ? (float) $d['ultima_acc'] : null;
    $drivers[] = [
        'id'       => (int) $d['id'],
        'nombre'   => (string) $d['nombre'],
        'telefono' => (string) $d['telefono'],
        'vehiculo' => (string) ($d['tipo_vehiculo'] ?? 'moto'),
        'placa'    => (string) ($d['placa'] ?? ''),
        'foto'     => yora_url_archivo($d['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com'),
        'lat'      => $ok ? $lat : null,
        'lng'      => $ok ? $lng : null,
        'gps'      => $ok ? (string) ($d['ultima_gps'] ?? '') : '',
        'hace'     => $hace,
        'heading'  => ($heading !== null && $heading >= 0) ? $heading : null,
        'acc'      => ($acc !== null && $acc > 0) ? $acc : null,
        'vivo'     => ($hace !== null && $hace <= 20),
    ];
}

yora_json(['status' => 'success', 'now' => date('H:i:s'), 'drivers' => $drivers]);
