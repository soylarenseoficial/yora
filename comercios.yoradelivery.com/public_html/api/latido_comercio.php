<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);
try {
    yora_exec($conexion, 'UPDATE comercios SET ultima_conexion = NOW() WHERE id = ?', 'i', $comercio_id);
    yora_json(['status' => 'success']);
} catch (Throwable $e) {
    yora_json(['status' => 'ok']);
}
