<?php
/**
 * SUBIDA DE UN DOCUMENTO DEL EXPEDIENTE - YORA DRIVER
 *
 * Antes esta API guardaba un unico archivo en "documento_url" y marcaba la
 * cuenta como "En Revisión" al instante. Ahora cada documento va a su propia
 * columna (las mismas que ve el panel HQ) y la cuenta solo pasa a revision
 * cuando el conductor pulsa "Enviar a verificación".
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$tipo = trim((string) ($_POST['tipo'] ?? ''));
if (!isset($_FILES['documento'])) {
    yora_fail('No se recibió ningún archivo.');
}

$conductor = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$conductor) {
    yora_fail('Cuenta no encontrada.', 401);
}

// El tipo tiene que ser uno de los documentos que le tocan a SU vehiculo:
// asi nadie puede escribir en otra columna de la tabla.
$permitidos = yora_docs_para_vehiculo($conductor['tipo_vehiculo'] ?? 'moto');
if (!isset($permitidos[$tipo])) {
    yora_fail('Ese documento no corresponde a tu tipo de vehículo.');
}

$estado_previo = yora_docs_estado($conductor);
if (($estado_previo['items'][$tipo]['estado'] ?? '') === 'En Revisión') {
    yora_fail('Ese documento está en revisión. Espera la respuesta de Yora para cambiarlo.');
}

$nombre_doc = yora_upload($_FILES['documento'], __DIR__ . '/../uploads/', ['jpg', 'jpeg', 'png', 'webp'], 5242880, true);
if (!$nombre_doc) {
    yora_fail('Formato no soportado (usa JPG, PNG, WEBP o PDF, máx. 5 MB).');
}

try {
    $url_doc = 'https://app.yoradelivery.com/uploads/' . $nombre_doc;
    yora_exec($conexion, "UPDATE conductores SET {$tipo} = ? WHERE id = ?", 'si', $url_doc, $conductor_id);

    // El documento vuelve a estado "cargado": si estaba rechazado, se limpia el
    // motivo para que el conductor no siga viendo un error ya corregido.
    yora_docs_esquema($conexion);
    $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
    $revision[$tipo] = ['estado' => 'Cargado', 'motivo' => '', 'fecha' => date('Y-m-d H:i:s')];

    // Se borra la observación de la cuenta (ya la está corrigiendo), pero NO la
    // firma de aprobación del HQ: un verificado que renueva un papel sigue
    // trabajando y el documento nuevo entra a revisión por su cuenta.
    $cuenta = is_array($revision['_cuenta'] ?? null) ? $revision['_cuenta'] : [];
    unset($cuenta['motivo'], $cuenta['fecha'], $cuenta['rechazado_en']);
    if ($cuenta) {
        $revision['_cuenta'] = $cuenta;
    } else {
        unset($revision['_cuenta']);
    }
    yora_docs_guardar_revision($conexion, $conductor_id, $revision);

    // La cuenta vuelve a "Pendiente" salvo que el HQ la haya aprobado de verdad.
    // Sin esto, los conductores que arrastran el viejo 'Verificado' por defecto
    // se auto-verificaban con solo terminar de subir sus fotos.
    $estado_guardado = (string) ($conductor['estado_documentos'] ?? '');
    $aprobada_por_hq = trim((string) ($cuenta['verificado_en'] ?? '')) !== '';
    if (!$aprobada_por_hq && $estado_guardado !== 'Pendiente') {
        yora_exec($conexion, "UPDATE conductores SET estado_documentos = 'Pendiente' WHERE id = ?", 'i', $conductor_id);
        $conductor['estado_documentos'] = 'Pendiente';
    }

    $conductor[$tipo] = $url_doc;
    $conductor['docs_revision'] = json_encode($revision, JSON_UNESCAPED_UNICODE);
    $estado = yora_docs_estado($conductor, 'https://yoradelivery.com');

    yora_json([
        'status'   => 'success',
        'mensaje'  => 'Documento guardado.',
        'url'      => $url_doc,
        'estilo'   => $estado['items'][$tipo]['estilo'] ?? yora_docs_estilo('Cargado'),
        'cargados' => $estado['cargados'],
        'total'    => $estado['total'],
        'completo' => $estado['completo'],
        'puede_enviar' => $estado['puede_enviar'],
    ]);
} catch (Throwable $e) {
    error_log('subir_documento_driver: ' . $e->getMessage());
    yora_fail('No se pudo guardar el documento.');
}
