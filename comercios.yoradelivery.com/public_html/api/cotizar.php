<?php
/**
 * Cotiza un envio calculando la ruta REAL por calles desde el comercio
 * hasta el punto que el usuario marca en el mapa.
 *
 * El panel ya no calcula precios por su cuenta: pide la cifra aqui para que
 * lo que ve el comercio sea exactamente lo que se le va a cobrar.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();

$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
$con_ruta = !empty($_POST['ruta']);

if (!yora_coords_ok($lat, $lng)) {
    yora_fail('Ubicación de entrega inválida.');
}

try {
    $comercio = yora_one($conexion, 'SELECT lat, lng, latitud, longitud FROM comercios WHERE id = ?', 'i', $comercio_id);
    if (!$comercio) {
        yora_fail('Comercio no encontrado.');
    }

    [$rest_lat, $rest_lng] = yora_coords_comercio($comercio);
    if (!yora_coords_ok($rest_lat, $rest_lng)) {
        yora_fail('Tu comercio no tiene ubicación GPS configurada. Ve a Configuración y marca tu local en el mapa.');
    }

    $ruta = yora_ruta_calles($rest_lat, $rest_lng, $lat, $lng, $con_ruta);

    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo (80 km).');
    }

    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);

    // El desglose deja ver por que costo eso: que km se cobraron en cada tramo.
    $detalle = [];
    foreach ($tarifa['desglose'] as $t) {
        $detalle[] = $t['km'] . ' km x $' . number_format($t['precio'], 2);
    }

    yora_json([
        'status'     => 'success',
        'km'         => $tarifa['km'],
        'minutos'    => $ruta['minutos'],
        'costo'      => $tarifa['costo'],
        'precio_km'  => $tarifa['precio_km'],
        'minimo'     => $tarifa['minima'],
        'desglose'   => $detalle,
        'fuente'     => $ruta['fuente'],
        'geometria'  => $ruta['geometria'],
        'origen'     => ['lat' => $rest_lat, 'lng' => $rest_lng],
    ]);
} catch (Throwable $e) {
    error_log('cotizar: ' . $e->getMessage());
    yora_fail('No se pudo calcular la ruta. Inténtalo de nuevo.');
}
