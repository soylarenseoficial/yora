<?php
/**
 * ENVIO DEL EXPEDIENTE A REVISION - YORA DRIVER
 *
 * Marca la cuenta como "En Revisión" para que aparezca en la cola de
 * Verificaciones del panel HQ. Solo se acepta si estan todos los documentos
 * que le corresponden a su vehiculo.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$conductor = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$conductor) {
    yora_fail('Cuenta no encontrada.', 401);
}

yora_docs_esquema($conexion);
$estado = yora_docs_estado($conductor);

if ($estado['verificado']) {
    yora_fail('Tu cuenta ya está verificada.');
}
if ($estado['faltantes'] > 0) {
    yora_fail('Todavía te faltan ' . $estado['faltantes'] . ' documento(s) por subir.');
}
if ($estado['declarado'] === 'En Revisión') {
    yora_fail('Ya enviaste tu expediente. Está en revisión.');
}

try {
    yora_exec(
        $conexion,
        "UPDATE conductores SET estado_documentos = 'En Revisión' WHERE id = ?",
        'i',
        $conductor_id
    );
    if (yora_docs_esquema($conexion)) {
        yora_exec($conexion, 'UPDATE conductores SET docs_enviado_en = NOW() WHERE id = ?', 'i', $conductor_id);
    }

    // Los documentos aprobados siguen aprobados; el resto pasa a la cola.
    $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
    foreach ($estado['items'] as $campo => $doc) {
        if (empty($doc['aplica'])) {
            continue;
        }
        $marca = is_array($revision[$campo] ?? null) ? $revision[$campo] : [];
        if (($marca['estado'] ?? '') === 'Aprobado') {
            continue;
        }
        $revision[$campo] = ['estado' => 'En Revisión', 'motivo' => '', 'fecha' => date('Y-m-d H:i:s')];
    }
    unset($revision['_cuenta']);
    yora_docs_guardar_revision($conexion, $conductor_id, $revision);

    yora_json([
        'status'  => 'success',
        'mensaje' => 'Expediente enviado. El equipo de Yora lo revisará pronto.',
    ]);
} catch (Throwable $e) {
    error_log('enviar_verificacion: ' . $e->getMessage());
    yora_fail('No se pudo enviar el expediente. Inténtalo de nuevo.');
}
