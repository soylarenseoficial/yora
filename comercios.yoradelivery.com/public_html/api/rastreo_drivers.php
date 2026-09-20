<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

[$rest_lat, $rest_lng] = yora_coords_comercio(
    yora_one($conexion, 'SELECT latitud, longitud, lat, lng FROM comercios WHERE id = ?', 'i', $comercio_id) ?: []
);

$filas = yora_all(
    $conexion,
    "SELECT c.id, c.estatus, c.direccion_entrega, c.cliente_nombre, c.conductor_id,
            d.nombre AS conductor_nombre, d.telefono AS conductor_telefono, d.foto_perfil,
            d.ultima_lat, d.ultima_lng, d.ultima_gps
     FROM comandas c
     LEFT JOIN conductores d ON c.conductor_id = d.id
     WHERE c.comercio_id = ?
       AND c.estatus NOT IN ('Entregado', 'Cancelado')
     ORDER BY c.id DESC
     LIMIT 20",
    'i',
    $comercio_id
) ?: [];

$envios = [];
foreach ($filas as $p) {
    $dest = yora_gps_desde_direccion((string) ($p['direccion_entrega'] ?? ''));
    $lat = (float) ($p['ultima_lat'] ?? 0);
    $lng = (float) ($p['ultima_lng'] ?? 0);
    $tiene = yora_coords_ok($lat, $lng);
    $envios[] = [
        'id'         => (int) $p['id'],
        'estatus'    => (string) $p['estatus'],
        'cliente'    => (string) $p['cliente_nombre'],
        'conductor'  => $p['conductor_id'] ? [
            'id'       => (int) $p['conductor_id'],
            'nombre'   => (string) $p['conductor_nombre'],
            'telefono' => (string) $p['conductor_telefono'],
            'foto'     => yora_url_archivo($p['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com'),
            'lat'      => $tiene ? $lat : null,
            'lng'      => $tiene ? $lng : null,
            'gps'      => $tiene ? (string) ($p['ultima_gps'] ?? '') : '',
        ] : null,
        'destino'    => $dest ? ['lat' => $dest[0], 'lng' => $dest[1]] : null,
    ];
}

yora_json([
    'status'   => 'success',
    'comercio' => yora_coords_ok($rest_lat, $rest_lng) ? ['lat' => $rest_lat, 'lng' => $rest_lng] : null,
    'envios'   => $envios,
]);
