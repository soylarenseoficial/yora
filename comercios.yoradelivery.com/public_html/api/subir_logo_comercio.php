<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$id = yora_require_comercio();

if (!isset($_FILES['logo'])) {
    yora_fail('No se recibió el logo. Elige una imagen JPG o PNG.');
}

$logo = yora_guardar_logo_comercio($_FILES['logo']);
if (!$logo['ok']) {
    yora_fail($logo['error'] !== '' ? $logo['error'] : 'No se pudo guardar el logo. Usa JPG o PNG de máximo 5 MB.');
}

try {
    yora_exec($conexion, 'UPDATE comercios SET logo_url = ? WHERE id = ?', 'si', $logo['url'], $id);
    yora_json(['status' => 'success', 'url' => $logo['url'], 'mensaje' => 'Logo actualizado']);
} catch (Throwable $e) {
    error_log('subir_logo_comercio: ' . $e->getMessage());
    yora_fail('El archivo se subió pero no se pudo guardar en la base de datos.');
}
