<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$uid = yora_require_usuario();

if (!isset($_FILES['foto'])) {
    yora_fail('No se recibió la foto.');
}
$guardado = yora_guardar_logo_comercio($_FILES['foto']);
if (empty($guardado['ok'])) {
    yora_fail($guardado['error'] ?? 'No se pudo guardar la foto.');
}
$url = (string) $guardado['url'];
yora_exec($conexion, 'UPDATE usuarios_app SET foto_url = ? WHERE id = ?', 'si', $url, $uid);
yora_json(['status' => 'success', 'url' => $url, 'mensaje' => 'Foto actualizada.']);
