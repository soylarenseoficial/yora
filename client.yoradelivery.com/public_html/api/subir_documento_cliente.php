<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$uid = yora_require_usuario();
$tipo = trim((string) ($_POST['tipo'] ?? ''));
if (!isset(yora_cliente_docs_campos()[$tipo])) {
    yora_fail('Documento no válido.');
}
if (!isset($_FILES['documento'])) {
    yora_fail('No se recibió ninguna foto.');
}
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
if (!$user) {
    yora_fail('Cuenta no encontrada.', 401);
}
$docs = yora_cliente_docs_estado($user);
if (($docs['items'][$tipo]['estado'] ?? '') === 'En Revisión') {
    yora_fail('Esa foto está en revisión.');
}
$guardado = yora_guardar_documento_comercio($_FILES['documento']);
if (empty($guardado['ok'])) {
    yora_fail($guardado['error'] ?? 'No se pudo guardar la foto.');
}
$url = (string) $guardado['url'];
yora_exec($conexion, "UPDATE usuarios_app SET {$tipo} = ? WHERE id = ?", 'si', $url, $uid);
$revision = [];
$raw = trim((string) ($user['docs_revision'] ?? ''));
if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) {
        $revision = $tmp;
    }
}
$revision[$tipo] = ['estado' => 'Cargado', 'motivo' => '', 'fecha' => date('Y-m-d H:i:s')];
unset($revision['_cuenta']['motivo'], $revision['_cuenta']['fecha']);
yora_cliente_guardar_revision($conexion, $uid, $revision);
if (($user['estado_documentos'] ?? '') === 'Verificado') {
    // renovación: no quitar verificado global
} else {
    yora_exec($conexion, "UPDATE usuarios_app SET estado_documentos = 'Pendiente' WHERE id = ?", 'i', $uid);
}
yora_json(['status' => 'success', 'mensaje' => 'Foto cargada.', 'url' => $url]);
