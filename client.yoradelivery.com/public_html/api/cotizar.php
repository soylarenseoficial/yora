<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
yora_require_usuario();

$lat_rec = (float) ($_POST['lat_recogida'] ?? 0);
$lng_rec = (float) ($_POST['lng_recogida'] ?? 0);
$lat = (float) ($_POST['lat'] ?? 0);
$lng = (float) ($_POST['lng'] ?? 0);
if (!yora_coords_ok($lat_rec, $lng_rec) || !yora_coords_ok($lat, $lng)) {
    yora_fail('Marca recogida y entrega en el mapa.');
}
try {
    $ruta = yora_ruta_calles($lat_rec, $lng_rec, $lat, $lng, true);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo (80 km).');
    }
    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    yora_json([
        'status'    => 'success',
        'km'        => $tarifa['km'],
        'minutos'   => $ruta['minutos'],
        'costo'     => $tarifa['costo'],
        'precio_km' => $tarifa['precio_km'],
        'minimo'    => $tarifa['minima'],
        'fuente'    => $ruta['fuente'],
        'geometria' => $ruta['geometria'],
    ]);
} catch (Throwable $e) {
    error_log('client cotizar: ' . $e->getMessage());
    yora_fail('No se pudo calcular la ruta.');
}
