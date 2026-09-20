<?php
require_once __DIR__ . '/../../config.php';
yora_require_admin(true);
header('Content-Type: application/json; charset=utf-8');
yora_timezone($conexion);
$forzar = isset($_GET['forzar']) || isset($_POST['forzar']);
$bcv = yora_tasa_bcv($conexion, $forzar);
yora_json([
    'status' => 'success',
    'tasa'   => round((float) $bcv['tasa'], 4),
    'fecha'  => $bcv['fecha'],
    'fuente' => $bcv['fuente'],
]);
