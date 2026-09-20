<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

try {
    $conexion->begin_transaction();
    $datos = yora_one($conexion, 'SELECT billetera, categoria FROM conductores WHERE id = ? FOR UPDATE', 'i', $conductor_id);
    if (!$datos) {
        $conexion->rollback();
        yora_fail('Conductor no encontrado.');
    }
    $billetera = (float) $datos['billetera'];
    $categoria = !empty($datos['categoria']) ? $datos['categoria'] : 'Sencillo';
    $minimo_retiro = ($categoria === 'Pro') ? 5.00 : 20.00;

    if ($billetera < $minimo_retiro) {
        $conexion->rollback();
        yora_fail('Como conductor nivel ' . $categoria . ', tu mínimo de retiro es de $' . number_format($minimo_retiro, 2));
    }

    $pendiente = yora_one($conexion, "SELECT id FROM solicitudes_retiro WHERE conductor_id = ? AND estatus = 'Pendiente' LIMIT 1", 'i', $conductor_id);
    if ($pendiente) {
        $conexion->rollback();
        yora_fail('Ya tienes una solicitud de retiro en proceso.');
    }

    if (yora_exec($conexion, 'UPDATE conductores SET billetera = 0 WHERE id = ? AND billetera = ?', 'id', $conductor_id, $billetera) < 1) {
        $conexion->rollback();
        yora_fail('El saldo cambió. Inténtalo de nuevo.');
    }
    yora_exec($conexion, "INSERT INTO solicitudes_retiro (conductor_id, monto, estatus) VALUES (?, ?, 'Pendiente')", 'id', $conductor_id, $billetera);
    yora_exec($conexion, "UPDATE comandas SET pagado_al_driver = 1 WHERE conductor_id = ? AND estatus = 'Entregado' AND pagado_al_driver = 0", 'i', $conductor_id);
    $conexion->commit();
    yora_json(['status' => 'success', 'mensaje' => 'Solicitud de $' . number_format($billetera, 2) . ' enviada. Tu contador de viajes inicia un nuevo ciclo.']);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('solicitar_retiro: ' . $e->getMessage());
    yora_fail('No se pudo solicitar el retiro.');
}
