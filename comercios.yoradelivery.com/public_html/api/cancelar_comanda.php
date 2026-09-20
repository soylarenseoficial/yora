<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$comanda_id = (int) ($_POST['comanda_id'] ?? 0);
if ($comanda_id < 1) {
    yora_fail('Pedido no válido.');
}

try {
    $conexion->begin_transaction();
    $comanda = yora_one($conexion, 'SELECT costo_delivery, estatus FROM comandas WHERE id = ? AND comercio_id = ? FOR UPDATE', 'ii', $comanda_id, $comercio_id);
    if (!$comanda) {
        $conexion->rollback();
        yora_fail('Pedido no encontrado.');
    }

    $estatus = $comanda['estatus'];
    $costo = (float) $comanda['costo_delivery'];

    if ($estatus === 'Buscando Conductor' || $estatus === 'Revisando') {
        $reembolso = $costo;
        $mensaje = 'Orden cancelada. Reembolso de $' . number_format($reembolso, 2) . ' devuelto a tu crédito.';
    } elseif ($estatus === 'En Camino a Comercio') {
        $reembolso = round($costo * 0.50, 2);
        $mensaje = 'Orden cancelada. Se retuvo 50% de comisión. Reembolso: $' . number_format($reembolso, 2);
    } else {
        $conexion->rollback();
        yora_fail('El pedido ya está en camino al cliente y no puede cancelarse.');
    }

    if (!yora_can_transition($estatus, 'Cancelado')) {
        $conexion->rollback();
        yora_fail('Este pedido no puede cancelarse en su estado actual.');
    }

    if (yora_exec($conexion, "UPDATE comandas SET estatus = 'Cancelado' WHERE id = ? AND comercio_id = ? AND estatus = ?", 'iis', $comanda_id, $comercio_id, $estatus) < 1) {
        $conexion->rollback();
        yora_fail('El pedido cambió de estado. Recarga e inténtalo de nuevo.');
    }
    yora_exec($conexion, 'UPDATE comercios SET credito_consumido_ciclo = GREATEST(0, credito_consumido_ciclo - ?) WHERE id = ?', 'di', $reembolso, $comercio_id);
    $conexion->commit();
    yora_json(['status' => 'success', 'mensaje' => $mensaje]);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('cancelar_comanda: ' . $e->getMessage());
    yora_fail('No se pudo cancelar el pedido.');
}
