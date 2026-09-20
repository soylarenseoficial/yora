<?php
/**
 * Subida del RIF/cédula desde el panel del comercio.
 * Si el archivo no se guarda, se responde error (nunca éxito silencioso).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$id = yora_require_comercio();
yora_timezone($conexion);

if (!isset($_FILES['documento'])) {
    yora_fail('No se recibió el documento. Elige una foto JPG/PNG o un PDF.');
}

$doc = yora_guardar_documento_comercio($_FILES['documento']);
if (!$doc['ok']) {
    yora_fail($doc['error'] !== '' ? $doc['error'] : 'No se pudo guardar el documento. Usa JPG, PNG o PDF de máximo 5 MB.');
}

try {
    yora_exec(
        $conexion,
        "UPDATE comercios SET documento_url = ?, estado_documentos = 'En Revisión' WHERE id = ?",
        'si',
        $doc['url'],
        $id
    );
    yora_json([
        'status'  => 'success',
        'url'     => $doc['url'],
        'mensaje' => 'Documento enviado. Yora lo revisará en el panel HQ.',
    ]);
} catch (Throwable $e) {
    error_log('subir_documento_comercio: ' . $e->getMessage());
    yora_fail('El archivo se subió pero no se pudo guardar en la base de datos.');
}
