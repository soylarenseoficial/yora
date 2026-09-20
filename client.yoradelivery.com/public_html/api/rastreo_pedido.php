<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
$uid = yora_require_usuario();
yora_timezone($conexion);

$id = (int) ($_GET['id'] ?? 0);
$sql = "SELECT c.id, c.codigo, c.estatus, c.tipo_pago, c.costo_delivery, c.detalles_entrega,
               c.direccion_recogida, c.direccion_entrega, c.lat_recogida, c.lng_recogida,
               c.lat_entrega, c.lng_entrega, c.cliente_nombre, c.conductor_id, c.tipo_comanda,
               d.nombre AS conductor_nombre, d.telefono AS conductor_telefono, d.foto_perfil,
               d.ultima_lat, d.ultima_lng, d.ultima_gps, d.tipo_vehiculo, d.placa
        FROM comandas c
        LEFT JOIN conductores d ON c.conductor_id = d.id
        WHERE c.usuario_id = ?";
$types = 'i';
$args = [$uid];
if ($id > 0) {
    $sql .= ' AND c.id = ?';
    $types .= 'i';
    $args[] = $id;
}
$sql .= " AND c.estatus NOT IN ('Entregado', 'Cancelado') ORDER BY c.id DESC LIMIT 12";
$filas = yora_all($conexion, $sql, $types, ...$args) ?: [];

$pedidos = [];
foreach ($filas as $p) {
    [$plat, $plng] = yora_coords_recogida_comanda($p);
    [$dlat, $dlng] = yora_coords_entrega_comanda($p);
    $lat = (float) ($p['ultima_lat'] ?? 0);
    $lng = (float) ($p['ultima_lng'] ?? 0);
    $gps_ok = yora_coords_ok($lat, $lng);
    $cid = (int) ($p['conductor_id'] ?? 0);
    $pedidos[] = [
        'id'         => (int) $p['id'],
        'codigo'     => (string) ($p['codigo'] ?: ('MD' . $p['id'])),
        'estatus'    => (string) $p['estatus'],
        'pago'       => (string) ($p['tipo_pago'] ?? ''),
        'costo'      => (float) $p['costo_delivery'],
        'encargo'    => (string) ($p['detalles_entrega'] ?? ''),
        'solicitante'=> (string) ($p['cliente_nombre'] ?? ''),
        'retiro'     => [
            'texto' => yora_texto_recogida_comanda($p),
            'lat'   => yora_coords_ok($plat, $plng) ? $plat : null,
            'lng'   => yora_coords_ok($plat, $plng) ? $plng : null,
        ],
        'entrega'    => [
            'texto' => yora_texto_destino($p['direccion_entrega'] ?? ''),
            'lat'   => yora_coords_ok($dlat, $dlng) ? $dlat : null,
            'lng'   => yora_coords_ok($dlat, $dlng) ? $dlng : null,
        ],
        'conductor'  => $cid > 0 ? [
            'id'       => $cid,
            'nombre'   => (string) ($p['conductor_nombre'] ?? 'Motorizado'),
            'telefono' => (string) ($p['conductor_telefono'] ?? ''),
            'foto'     => yora_url_archivo($p['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com'),
            'vehiculo' => trim((string) ($p['tipo_vehiculo'] ?? '') . ' ' . (string) ($p['placa'] ?? '')),
            'lat'      => $gps_ok ? $lat : null,
            'lng'      => $gps_ok ? $lng : null,
            'gps'      => $gps_ok ? (string) ($p['ultima_gps'] ?? '') : '',
        ] : null,
    ];
}

yora_json(['status' => 'success', 'pedidos' => $pedidos]);
