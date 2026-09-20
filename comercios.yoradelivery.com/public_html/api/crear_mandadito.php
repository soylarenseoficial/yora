<?php
/**
 * Mandadito: el comercio (o a futuro un usuario) pide recoger en A
 * y entregar donde está él. Se cobra al crédito del comercio.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

try {
    $encargo = trim((string) ($_POST['encargo'] ?? ''));
    $dir_recogida = trim((string) ($_POST['direccionRecogida'] ?? ''));
    $lat_rec = isset($_POST['lat_recogida']) ? (float) $_POST['lat_recogida'] : 0;
    $lng_rec = isset($_POST['lng_recogida']) ? (float) $_POST['lng_recogida'] : 0;
    $lat_dest = isset($_POST['lat']) ? (float) $_POST['lat'] : 0;
    $lng_dest = isset($_POST['lng']) ? (float) $_POST['lng'] : 0;
    $dir_entrega = trim((string) ($_POST['direccionEntrega'] ?? ''));

    if ($encargo === '' || mb_strlen($encargo) > 500) {
        yora_fail('Describe qué debe recoger o comprar el motorizado.');
    }
    if ($dir_recogida === '' || mb_strlen($dir_recogida) > 500) {
        yora_fail('Escribe la dirección de recogida.');
    }
    if (!yora_coords_ok($lat_rec, $lng_rec)) {
        yora_fail('Marca en el mapa el punto de recogida.');
    }

    $comercio = yora_one(
        $conexion,
        'SELECT nombre, telefono, direccion, lat, lng, latitud, longitud, bloqueado_deuda FROM comercios WHERE id = ?',
        'i',
        $comercio_id
    );
    if (!$comercio) {
        yora_fail('Comercio no encontrado.');
    }

    if (!yora_coords_ok($lat_dest, $lng_dest)) {
        yora_fail('Marca en el mapa el punto de entrega.');
    }
    if ($dir_entrega === '') {
        yora_fail('Escribe la dirección de entrega.');
    }

    $credito = yora_credito_estado($conexion, $comercio_id);
    if ($credito['bloqueado']) {
        yora_fail('Tu cuenta está bloqueada por facturas vencidas. Paga para pedir un mandadito.');
    }

    $ruta = yora_ruta_calles($lat_rec, $lng_rec, $lat_dest, $lng_dest, false);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo.');
    }

    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    $costo = $tarifa['costo'];
    $distancia = $tarifa['km'];
    $dir_completa = $dir_entrega . ' | GPS: ' . $lat_dest . ', ' . $lng_dest;
    $nombre = trim((string) ($comercio['nombre'] ?? 'Comercio'));
    $telefono = trim((string) ($comercio['telefono'] ?? ''));
    $tiempo = 'Ya está listo para retirar';

    if ($credito['disponible'] + 0.001 < $costo) {
        yora_fail('Crédito insuficiente. Paga facturas pendientes para liberar cupo.');
    }

    $conexion->begin_transaction();
    if (!yora_credito_consumir($conexion, $comercio_id, $costo)) {
        $conexion->rollback();
        yora_fail('Crédito insuficiente. Paga tus facturas pendientes.');
    }

    $codigo_nuevo = 'MD' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    try {
        yora_exec(
            $conexion,
            'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus, codigo, tipo_comanda, direccion_recogida, lat_recogida, lng_recogida, lat_entrega, lng_entrega) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'isssssddsssssdddd',
            $comercio_id,
            $nombre,
            $telefono,
            $encargo,
            $dir_completa,
            $tiempo,
            $distancia,
            $costo,
            'Billetera',
            'Buscando Conductor',
            $codigo_nuevo,
            'mandadito',
            $dir_recogida,
            $lat_rec,
            $lng_rec,
            $lat_dest,
            $lng_dest
        );
    } catch (Throwable $eIns) {
        yora_exec(
            $conexion,
            'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus, tipo_comanda, direccion_recogida, lat_recogida, lng_recogida, lat_entrega, lng_entrega) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?)',
            'isssssddssssdddd',
            $comercio_id,
            $nombre,
            $telefono,
            $encargo,
            $dir_completa,
            $tiempo,
            $distancia,
            $costo,
            'Billetera',
            'Buscando Conductor',
            'mandadito',
            $dir_recogida,
            $lat_rec,
            $lng_rec,
            $lat_dest,
            $lng_dest
        );
    }
    $comanda_id = (int) $conexion->insert_id;
    $creditoFin = yora_credito_estado($conexion, $comercio_id);
    $conexion->commit();

    $codigo = yora_codigo_comanda($conexion, $comanda_id);
    try {
        yora_push_conductores($conexion, 'Nuevo mandadito en el radar', $nombre . ' pidió un mandadito. Entra al radar.', '/dashboard.php', true);
    } catch (Throwable $e) {
        error_log('push crear_mandadito: ' . $e->getMessage());
    }

    yora_json([
        'status'      => 'success',
        'mensaje'     => 'Mandadito al aire. Pedido #' . $codigo,
        'nuevo_saldo' => (float) ($creditoFin['disponible'] ?? 0),
        'credito_disponible' => (float) ($creditoFin['disponible'] ?? 0),
        'costo'       => $costo,
        'distancia'   => $distancia,
        'codigo'      => $codigo,
    ]);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('crear_mandadito: ' . $e->getMessage());
    yora_fail('No se pudo crear el mandadito. Inténtalo de nuevo.');
}
