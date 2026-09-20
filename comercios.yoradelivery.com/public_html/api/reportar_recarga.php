<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$monto = (float) ($_POST['monto'] ?? 0);
$banco = trim((string) ($_POST['banco'] ?? ''));
$referencia = trim((string) ($_POST['referencia'] ?? ''));
$factura_id = (int) ($_POST['factura_id'] ?? 0);
$fecha_pago = date('Y-m-d');

if ($monto < 1 || $monto > 50000 || $referencia === '' || mb_strlen($referencia) > 40 || $banco === '') {
    yora_fail('Monto mínimo $1, referencia y banco son obligatorios.');
}

$tasa_bcv = 0.0;
$monto_bs = 0.0;
if ($banco === 'Pago Móvil') {
    $tasa_bcv = (float) yora_tasa_bcv($conexion)['tasa'];
    if ($tasa_bcv <= 0) {
        yora_fail('No pudimos obtener la tasa del BCV en este momento. Intenta de nuevo en unos minutos.');
    }
    $monto_bs = round($monto * $tasa_bcv, 2);
}

if (!yora_rate_limit('recarga:' . $comercio_id, 8, 3600)) {
    yora_fail('Demasiados reportes recientes. Espera un momento.');
}

if ($factura_id > 0) {
    $fac = yora_one(
        $conexion,
        "SELECT id FROM facturas_comercio WHERE id = ? AND comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')",
        'ii',
        $factura_id,
        $comercio_id
    );
    if (!$fac) {
        yora_fail('Factura no válida o ya pagada.');
    }
}

try {
    $dup = yora_one($conexion, 'SELECT id FROM recargas_saldo WHERE referencia = ? AND banco_origen = ? LIMIT 1', 'ss', $referencia, $banco);
    if ($dup) {
        yora_fail('Esa referencia ya fue reportada.');
    }
    yora_exec(
        $conexion,
        "INSERT INTO recargas_saldo (comercio_id, monto, monto_bs, tasa_bcv, banco_origen, referencia, fecha_pago, estatus, factura_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'En Revisión', ?)",
        'idddsssi',
        $comercio_id,
        $monto,
        $monto_bs,
        $tasa_bcv,
        $banco,
        $referencia,
        $fecha_pago,
        $factura_id > 0 ? $factura_id : null
    );
    yora_json(['status' => 'success', 'mensaje' => 'Pago enviado. El equipo de Yora lo validará pronto.']);
} catch (Throwable $e) {
    error_log('reportar_recarga: ' . $e->getMessage());
    yora_fail('No se pudo registrar el pago.');
}
