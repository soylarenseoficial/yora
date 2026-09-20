<?php
/**
 * Perfil del conductor para YoraDriver nativo.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_api();
yora_mant_exigir($conexion, 'drivers', $conductor_id);

$row = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$row) {
    yora_json(['ok' => false, 'mensaje' => 'Conductor no encontrado'], 404);
}

$docs = yora_docs_estado($row);
$foto = yora_url_archivo(
    $row['foto_perfil'] ?? '',
    'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
    'https://yoradelivery.com'
);

$viajes = yora_one(
    $conexion,
    "SELECT COUNT(id) AS total FROM comandas WHERE conductor_id = ? AND estatus = 'Entregado'",
    'i',
    $conductor_id
);

$docsItems = [];
foreach (($docs['items'] ?? []) as $campo => $item) {
    if (!is_array($item)) {
        continue;
    }
    $estadoItem = (string) ($item['estado'] ?? '');
    if ($estadoItem === 'No aplica') {
        continue;
    }
    $docsItems[] = [
        'campo' => (string) ($item['campo'] ?? $campo),
        'etiqueta' => (string) ($item['etiqueta'] ?? $campo),
        'ayuda' => (string) ($item['ayuda'] ?? ''),
        'estado' => $estadoItem,
        'motivo' => (string) ($item['motivo'] ?? ''),
        'url' => (string) ($item['url'] ?? ''),
        'aplica' => !empty($item['aplica']),
    ];
}

yora_json([
    'ok' => true,
    'driver_id' => (int) $row['id'],
    'nombre' => (string) ($row['nombre'] ?? ''),
    'cedula' => (string) ($row['cedula'] ?? ''),
    'telefono' => (string) ($row['telefono'] ?? ''),
    'correo' => (string) ($row['correo'] ?? ''),
    'foto' => $foto,
    'billetera' => round((float) ($row['billetera'] ?? 0), 2),
    'categoria' => (string) ($row['categoria'] ?? 'Sencillo'),
    'en_linea' => (int) ($row['en_linea'] ?? 0),
    'verificado' => !empty($docs['verificado']),
    'docs_texto' => !empty($docs['verificado'])
        ? 'Tu expediente está aprobado'
        : (string) ($docs['global'] ?? 'Pendiente'),
    'debe_cambiar_password' => (function () use ($conexion, $row) {
        yora_pass_flag_esquema($conexion);
        $f = yora_one($conexion, 'SELECT debe_cambiar_password FROM conductores WHERE id = ?', 'i', (int) $row['id']);
        return yora_conductor_debe_cambiar_password($f ?: $row);
    })(),
    'docs' => [
        'global' => (string) ($docs['global'] ?? 'Pendiente'),
        'puede_enviar' => !empty($docs['puede_enviar']),
        'faltantes' => (int) ($docs['faltantes'] ?? 0),
        'items' => $docsItems,
    ],
    'viajes_entregados' => (int) ($viajes['total'] ?? 0),
    'banco_pago' => (string) ($row['banco_pago'] ?? ''),
    'telefono_pago' => (string) ($row['telefono_pago'] ?? ''),
    'cedula_pago' => (string) ($row['cedula_pago'] ?? ''),
    'cobro_registrado' => trim((string) ($row['banco_pago'] ?? '')) !== ''
        && trim((string) ($row['telefono_pago'] ?? '')) !== ''
        && trim((string) ($row['cedula_pago'] ?? '')) !== '',
], 200);
