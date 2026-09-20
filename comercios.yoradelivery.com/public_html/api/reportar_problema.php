<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$comanda_id = (int) ($_POST['comanda_id'] ?? 0);
$motivo = trim((string) ($_POST['motivo'] ?? ''));
$descripcion = trim((string) ($_POST['descripcion'] ?? ''));

if ($comanda_id < 1 || $motivo === '' || mb_strlen($motivo) > 120) {
    yora_fail('Datos de reporte inválidos.');
}

try {
    $propia = yora_one($conexion, 'SELECT id FROM comandas WHERE id = ? AND comercio_id = ?', 'ii', $comanda_id, $comercio_id);
    if (!$propia) {
        yora_fail('Pedido no encontrado.');
    }
    yora_exec(
        $conexion,
        'INSERT INTO reportes_soporte (comanda_id, comercio_id, motivo, descripcion) VALUES (?, ?, ?, ?)',
        'iiss',
        $comanda_id,
        $comercio_id,
        $motivo,
        $descripcion
    );
    yora_json(['status' => 'success', 'mensaje' => 'Reporte enviado. Soporte Yora revisará el caso.']);
} catch (Throwable $e) {
    error_log('reportar_problema: ' . $e->getMessage());
    yora_fail('No se pudo enviar el reporte.');
}
