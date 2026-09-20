<?php
/**
 * Cron diario créditos comercios.
 * aaPanel Cron (00:05): curl -s "https://comercios.yoradelivery.com/api/cron_creditos.php?key=CLAVE"
 * Define YORA_CRON_KEY en config o usa el valor por defecto (cámbialo).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');

$clave = (string) (getenv('YORA_CRON_KEY') ?: 'yora_cron_creditos_2026');
$got = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals($clave, $got)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'Forbidden']);
    exit;
}

yora_timezone($conexion);
$res = yora_cron_creditos_comercio($conexion);
echo json_encode(['ok' => true, 'resultado' => $res], JSON_UNESCAPED_UNICODE);
