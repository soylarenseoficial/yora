<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$uid = yora_require_usuario();
yora_timezone($conexion);

$monto = (float) ($_POST['monto'] ?? 0);
$banco = trim((string) ($_POST['banco'] ?? ''));
$referencia = trim((string) ($_POST['referencia'] ?? ''));
if ($monto < 1) {
    yora_fail('El mínimo de recarga es $1.00.');
}
try {
    yora_reportar_pago_cliente($conexion, $uid, $monto, $banco, $referencia, 0);
    yora_json(['status' => 'success', 'mensaje' => 'Recarga enviada. Yora la valida y el saldo cae en tu billetera.']);
} catch (Throwable $e) {
    yora_fail($e->getMessage());
}
