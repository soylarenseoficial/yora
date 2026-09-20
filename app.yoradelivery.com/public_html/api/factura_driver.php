<?php
/**
 * Factura / detalle de un viaje entregado del conductor.
 * GET: id (comanda_id)
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    yora_json(['ok' => false, 'mensaje' => 'Viaje no válido'], 400);
}

$v = yora_one(
    $conexion,
    "SELECT c.*, r.nombre AS restaurante, r.logo_url
     FROM comandas c
     LEFT JOIN comercios r ON c.comercio_id = r.id
     WHERE c.id = ? AND c.conductor_id = ?
     LIMIT 1",
    'ii',
    $id,
    $conductor_id
);
if (!$v) {
    yora_json(['ok' => false, 'mensaje' => 'Factura no encontrada'], 404);
}

$codigo = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);
$restaurante = trim((string) ($v['restaurante'] ?? ''));
if ($restaurante === '') {
    $restaurante = yora_es_mandadito($v) ? 'Mandadito' : 'Pedido Yora';
}
$logo = yora_url_archivo(
    $v['logo_url'] ?? '',
    'https://cdn-icons-png.flaticon.com/512/3075/3075977.png',
    'https://comercios.yoradelivery.com'
);
$impacto = yora_impacto_billetera_driver($v);
$ganancia = yora_monto_radar_driver($v);
if (abs($impacto) > 0.001) {
    $ganancia = abs($impacto);
}
$fechaRaw = (string) ($v['fecha_entrega'] ?: $v['fecha_creacion'] ?: '');
$fechaTxt = $fechaRaw !== '' ? date('j/n/Y g:i A', strtotime($fechaRaw)) : '';

yora_json([
    'ok' => true,
    'factura' => [
        'id' => (int) $v['id'],
        'codigo' => (string) $codigo,
        'restaurante' => $restaurante,
        'logo' => $logo,
        'estatus' => (string) ($v['estatus'] ?? ''),
        'cliente' => (string) ($v['cliente_nombre'] ?? ''),
        'recogida' => yora_texto_recogida_comanda($v),
        'entrega' => yora_texto_destino($v['direccion_entrega'] ?? ''),
        'detalles' => (string) ($v['detalles_entrega'] ?? ''),
        'ganancia' => round((float) $ganancia, 2),
        'distancia_km' => round((float) ($v['distancia_km'] ?? 0), 2),
        'fecha' => $fechaTxt,
        'pagado' => (int) ($v['pagado_al_driver'] ?? 0) === 1,
        'efectivo' => yora_es_efectivo_en_mano($v),
        'mandadito' => yora_es_mandadito($v),
    ],
], 200);
