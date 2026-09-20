<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$uid = yora_require_usuario();
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
if (!$user) {
    yora_fail('Cuenta no encontrada.', 401);
}
$docs = yora_cliente_docs_estado($user);
if ($docs['verificado']) {
    yora_fail('Tu cuenta ya está verificada.');
}
if ($docs['faltantes'] > 0) {
    yora_fail('Sube la selfie y la cédula antes de enviar.');
}
if ($docs['declarado'] === 'En Revisión') {
    yora_fail('Ya enviaste tus documentos. Están en revisión.');
}
$revision = json_decode((string) ($user['docs_revision'] ?? ''), true);
if (!is_array($revision)) {
    $revision = [];
}
foreach (array_keys(yora_cliente_docs_campos()) as $campo) {
    $revision[$campo] = ['estado' => 'En Revisión', 'motivo' => '', 'fecha' => date('Y-m-d H:i:s')];
}
yora_cliente_guardar_revision($conexion, $uid, $revision);
yora_exec($conexion, "UPDATE usuarios_app SET estado_documentos = 'En Revisión', docs_enviado_en = NOW() WHERE id = ?", 'i', $uid);
try {
    yora_notificar_cliente_correo($user, 'recibido');
} catch (Throwable $e) {
    error_log('mail cliente recibido: ' . $e->getMessage());
}
yora_json(['status' => 'success', 'mensaje' => 'Enviado. Te avisamos por correo cuando Yora lo revise.']);
