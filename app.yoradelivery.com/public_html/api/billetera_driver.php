<?php
/**
 * Billetera del conductor (APK nativo).
 * Auth: Bearer / X-Yora-Token o sesión.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_mant_exigir($conexion, 'drivers', $conductor_id);
yora_timezone($conexion);

$cond = yora_one($conexion, 'SELECT id, nombre, billetera, categoria, foto_perfil, telefono, correo, cedula, banco_pago, telefono_pago, cedula_pago FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$cond) {
    yora_json(['ok' => false, 'mensaje' => 'Conductor no encontrado'], 404);
}

$saldo = round((float) ($cond['billetera'] ?? 0), 2);
$nivel = trim((string) ($cond['categoria'] ?? 'Sencillo')) ?: 'Sencillo';
$min_retiro = (strcasecmp($nivel, 'Pro') === 0) ? 5.00 : 20.00;

$ciclo = yora_one(
    $conexion,
    "SELECT COUNT(id) AS total, COALESCE(SUM(costo_delivery), 0) AS ganancia
     FROM comandas
     WHERE conductor_id = ? AND estatus = 'Entregado' AND pagado_al_driver = 0",
    'i',
    $conductor_id
) ?: ['total' => 0, 'ganancia' => 0];

$retiro_pendiente = yora_one(
    $conexion,
    "SELECT id, monto, fecha_solicitud FROM solicitudes_retiro
     WHERE conductor_id = ? AND estatus = 'Pendiente' ORDER BY id DESC LIMIT 1",
    'i',
    $conductor_id
);

$retiros = [];
try {
    $rows = yora_all(
        $conexion,
        'SELECT id, monto, estatus, fecha_solicitud, fecha_pago
         FROM solicitudes_retiro WHERE conductor_id = ? ORDER BY id DESC LIMIT 20',
        'i',
        $conductor_id
    ) ?: [];
    foreach ($rows as $r) {
        $retiros[] = [
            'id' => (int) $r['id'],
            'monto' => round((float) $r['monto'], 2),
            'estatus' => (string) ($r['estatus'] ?? ''),
            'fecha' => (string) ($r['fecha_solicitud'] ?? ''),
            'fecha_pago' => (string) ($r['fecha_pago'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $retiros = [];
}

$foto = yora_url_archivo(
    $cond['foto_perfil'] ?? '',
    'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
    'https://yoradelivery.com'
);

$movimientos = [];
try {
    $viajesMov = yora_all(
        $conexion,
        "SELECT id, codigo, costo_delivery, fecha_entrega, fecha_creacion,
                COALESCE(NULLIF((SELECT nombre FROM comercios r WHERE r.id = c.comercio_id), ''), cliente_nombre, 'Viaje') AS titulo
         FROM comandas c
         WHERE conductor_id = ? AND estatus = 'Entregado'
         ORDER BY COALESCE(fecha_entrega, fecha_creacion) DESC, id DESC
         LIMIT 30",
        'i',
        $conductor_id
    ) ?: [];
    foreach ($viajesMov as $m) {
        $fr = (string) ($m['fecha_entrega'] ?: $m['fecha_creacion'] ?: '');
        $movimientos[] = [
            'id' => 'v' . (int) $m['id'],
            'comanda_id' => (int) $m['id'],
            'codigo' => (string) ($m['codigo'] ?? ('ID' . $m['id'])),
            'titulo' => 'Viaje entregado',
            'subtitulo' => (string) ($m['titulo'] ?? ''),
            'monto' => round((float) ($m['costo_delivery'] ?? 0), 2),
            'tipo' => 'ingreso',
            'fecha' => $fr !== '' ? date('j/n/Y', strtotime($fr)) : '',
            'fecha_raw' => $fr,
        ];
    }
    foreach ($retiros as $r) {
        $movimientos[] = [
            'id' => 'r' . (int) $r['id'],
            'titulo' => 'Retiro ' . (string) $r['estatus'],
            'subtitulo' => '',
            'monto' => -abs((float) $r['monto']),
            'tipo' => 'retiro',
            'fecha' => !empty($r['fecha']) ? date('j/n/Y', strtotime($r['fecha'])) : '',
            'fecha_raw' => (string) ($r['fecha'] ?? ''),
        ];
    }
    usort($movimientos, static function ($a, $b) {
        return strcmp((string) ($b['fecha_raw'] ?? ''), (string) ($a['fecha_raw'] ?? ''));
    });
    $movimientos = array_slice($movimientos, 0, 40);
} catch (Throwable $e) {
    $movimientos = [];
}

yora_json([
    'ok' => true,
    'saldo' => $saldo,
    'nivel' => $nivel,
    'min_retiro' => $min_retiro,
    'puede_retirar' => $saldo >= $min_retiro && empty($retiro_pendiente),
    'retiro_pendiente' => $retiro_pendiente ? [
        'id' => (int) $retiro_pendiente['id'],
        'monto' => round((float) $retiro_pendiente['monto'], 2),
        'fecha' => (string) ($retiro_pendiente['fecha_solicitud'] ?? ''),
    ] : null,
    'viajes_ciclo' => (int) ($ciclo['total'] ?? 0),
    'ganancia_ciclo' => round((float) ($ciclo['ganancia'] ?? 0), 2),
    'retiros' => $retiros,
    'movimientos' => $movimientos,
    'actualizado' => date('j F Y • g:i A'),
    'nombre' => (string) ($cond['nombre'] ?? ''),
    'foto' => $foto,
    'telefono' => (string) ($cond['telefono'] ?? ''),
    'correo' => (string) ($cond['correo'] ?? ''),
    'cedula' => (string) ($cond['cedula'] ?? ''),
    'banco_pago' => (string) ($cond['banco_pago'] ?? ''),
    'telefono_pago' => (string) ($cond['telefono_pago'] ?? ''),
    'cedula_pago' => (string) ($cond['cedula_pago'] ?? ''),
    'cobro_registrado' => trim((string) ($cond['banco_pago'] ?? '')) !== ''
        && trim((string) ($cond['telefono_pago'] ?? '')) !== ''
        && trim((string) ($cond['cedula_pago'] ?? '')) !== '',
], 200);
