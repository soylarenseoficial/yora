<?php
/**
 * Cotiza un mandadito: de un punto de recogida hasta donde está el solicitante
 * (en el panel comercio, el destino es el GPS del local).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();

$lat_rec = isset($_POST['lat_recogida']) ? (float) $_POST['lat_recogida'] : 0.0;
$lng_rec = isset($_POST['lng_recogida']) ? (float) $_POST['lng_recogida'] : 0.0;
$lat_dest = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
$lng_dest = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
$con_ruta = !empty($_POST['ruta']);

if (!yora_coords_ok($lat_rec, $lng_rec)) {
    yora_fail('Marca en el mapa el punto de recogida.');
}

try {
    $comercio = yora_one($conexion, 'SELECT lat, lng, latitud, longitud FROM comercios WHERE id = ?', 'i', $comercio_id);
    if (!$comercio) {
        yora_fail('Comercio no encontrado.');
    }
    if (!yora_coords_ok($lat_dest, $lng_dest)) {
        yora_fail('Marca en el mapa el punto de entrega.');
    }

    $ruta = yora_ruta_calles($lat_rec, $lng_rec, $lat_dest, $lng_dest, true);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo (80 km).');
    }

    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    $detalle = [];
    foreach ($tarifa['desglose'] as $t) {
        $detalle[] = $t['km'] . ' km x $' . number_format($t['precio'], 2);
    }

    yora_json([
        'status'    => 'success',
        'km'        => $tarifa['km'],
        'minutos'   => $ruta['minutos'],
        'costo'     => $tarifa['costo'],
        'precio_km' => $tarifa['precio_km'],
        'minimo'    => $tarifa['minima'],
        'desglose'  => $detalle,
        'fuente'    => $ruta['fuente'],
        'geometria' => $ruta['geometria'],
        'origen'    => ['lat' => $lat_rec, 'lng' => $lng_rec],
        'destino'   => ['lat' => $lat_dest, 'lng' => $lng_dest],
    ]);
} catch (Throwable $e) {
    error_log('cotizar_mandadito: ' . $e->getMessage());
    yora_fail('No se pudo calcular la ruta. Inténtalo de nuevo.');
}
