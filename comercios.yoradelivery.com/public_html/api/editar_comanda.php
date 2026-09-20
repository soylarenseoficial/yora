<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$comanda_id = (int) ($_POST['edit_comanda_id'] ?? 0);
$nombre = trim((string) ($_POST['nombreCliente'] ?? ''));
$telefono = trim((string) ($_POST['telCliente'] ?? ''));
$direccion_escrita = trim((string) ($_POST['direccionEscrita'] ?? ''));
$referencia = trim((string) ($_POST['referencia'] ?? ''));
$contenido = trim((string) ($_POST['contenidoPaquete'] ?? ''));
$tiempo = yora_texto_tiempo((string) ($_POST['tiempoEstimado'] ?? '0'));
$lat = (float) ($_POST['lat'] ?? 0);
$lng = (float) ($_POST['lng'] ?? 0);

if ($comanda_id < 1 || !yora_coords_ok($lat, $lng)) {
    yora_fail('Datos de edición inválidos.');
}

try {
    $comercio = yora_one($conexion, 'SELECT lat, lng, latitud, longitud FROM comercios WHERE id = ?', 'i', $comercio_id);
    [$rest_lat, $rest_lng] = yora_coords_comercio($comercio);
    // Misma regla que al crear: ruta real por calles.
    $ruta = yora_ruta_calles($rest_lat, $rest_lng, $lat, $lng);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo.');
    }
    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    $nuevo_costo = $tarifa['costo'];
    $distancia = $tarifa['km'];

    $dir_completa = $direccion_escrita !== '' ? $direccion_escrita : ('GPS ' . $lat . ', ' . $lng);
    if ($referencia !== '') {
        $dir_completa .= ' | Ref: ' . $referencia;
    }
    $dir_completa .= ' | GPS: ' . $lat . ', ' . $lng;

    $conexion->begin_transaction();
    $comanda = yora_one($conexion, 'SELECT costo_delivery, estatus FROM comandas WHERE id = ? AND comercio_id = ? FOR UPDATE', 'ii', $comanda_id, $comercio_id);
    if (!$comanda) {
        $conexion->rollback();
        yora_fail('Pedido no encontrado.');
    }
    if ($comanda['estatus'] !== 'Buscando Conductor' && $comanda['estatus'] !== 'Revisando') {
        $conexion->rollback();
        yora_fail('Ya fue asignado al driver, no se puede editar.');
    }

    $diferencia = round($nuevo_costo - (float) $comanda['costo_delivery'], 2);
    if ($diferencia > 0) {
        if (!yora_credito_consumir($conexion, $comercio_id, $diferencia)) {
            $conexion->rollback();
            yora_fail('Crédito insuficiente para cubrir la nueva distancia.');
        }
    } elseif ($diferencia < 0) {
        yora_credito_devolver($conexion, $comercio_id, abs($diferencia));
    }

    yora_exec(
        $conexion,
        'UPDATE comandas SET cliente_nombre = ?, cliente_telefono = ?, detalles_entrega = ?, tiempo_estimado = ?, direccion_entrega = ?, distancia_km = ?, costo_delivery = ?, lat_entrega = ?, lng_entrega = ? WHERE id = ? AND comercio_id = ?',
        'sssssddddii',
        $nombre,
        $telefono,
        $contenido,
        $tiempo,
        $dir_completa,
        $distancia,
        $nuevo_costo,
        $lat,
        $lng,
        $comanda_id,
        $comercio_id
    );

    $nuevo_saldo = (float) yora_credito_estado($conexion, $comercio_id)['disponible'];
    $conexion->commit();
    yora_json(['status' => 'success', 'mensaje' => 'Pedido actualizado exitosamente', 'nuevo_saldo' => $nuevo_saldo]);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('editar_comanda: ' . $e->getMessage());
    yora_fail('No se pudo actualizar el pedido.');
}
