<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();

if (!isset($_FILES['foto'])) {
    yora_fail('No se recibió la foto.');
}

$nombre_archivo = yora_upload($_FILES['foto'], __DIR__ . '/../uploads/');
if (!$nombre_archivo) {
    yora_fail('La foto debe ser JPG, PNG o WEBP (máx. 5 MB).');
}

try {
    $url_foto = 'https://app.yoradelivery.com/uploads/' . $nombre_archivo;
    yora_exec($conexion, 'UPDATE conductores SET foto_perfil = ? WHERE id = ?', 'si', $url_foto, $conductor_id);
    yora_json(['status' => 'success', 'url' => $url_foto]);
} catch (Throwable $e) {
    error_log('subir_foto: ' . $e->getMessage());
    yora_fail('No se pudo guardar la foto.');
}
