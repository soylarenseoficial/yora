<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

try {
    $nombre = trim((string) ($_POST['nombreCliente'] ?? 'Sin nombre'));
    $telefono = trim((string) ($_POST['telCliente'] ?? ''));
    $cedula = trim((string) ($_POST['cedulaCliente'] ?? ''));
    $direccion_escrita = trim((string) ($_POST['direccionEscrita'] ?? 'Ubicación enviada por GPS'));
    $referencia = trim((string) ($_POST['referencia'] ?? ''));
    $contenido = trim((string) ($_POST['contenidoPaquete'] ?? 'Sin detalles'));
    $tiempo = yora_texto_tiempo((string) ($_POST['tiempoEstimado'] ?? '0'));
    $lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0;
    $lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0;

    if (!yora_coords_ok($lat, $lng)) {
        yora_fail('Ubicación de entrega inválida.');
    }
    if (mb_strlen($nombre) > 120 || mb_strlen($telefono) > 30 || mb_strlen($direccion_escrita) > 500 || mb_strlen($contenido) > 500 || mb_strlen($cedula) > 30 || mb_strlen($referencia) > 200) {
        yora_fail('Datos demasiado largos.');
    }

    $comercio = yora_one($conexion, 'SELECT nombre, lat, lng, latitud, longitud, hora_abre, hora_cierra, tipo_comercio, bloqueado_deuda FROM comercios WHERE id = ?', 'i', $comercio_id);
    if (!$comercio) {
        yora_fail('Comercio no encontrado.');
    }
    if (!yora_comercio_esta_abierto($comercio)) {
        yora_fail('El local está cerrado ahora. Revisa tu horario en Configuración.');
    }

    $credito = yora_credito_estado($conexion, $comercio_id);
    if ($credito['bloqueado']) {
        yora_fail('Tu cuenta está bloqueada por facturas vencidas. Paga tus facturas (+ $' . number_format(yora_penalizacion_reactivacion(), 2) . ' de reactivación) para despachar.');
    }

    [$rest_lat, $rest_lng] = yora_coords_comercio($comercio);
    if (!yora_coords_ok($rest_lat, $rest_lng)) {
        yora_fail('El comercio no tiene ubicación GPS configurada.');
    }

    // Distancia real por calles: es lo que se cobra y lo que ve el comercio.
    $ruta = yora_ruta_calles($rest_lat, $rest_lng, $lat, $lng);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo.');
    }

    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    $costo = $tarifa['costo'];
    $distancia = $tarifa['km'];
    $tipo_pago = 'Credito';
    $estatus = 'Buscando Conductor';
    // Calle del mapa + opcional Ref + GPS (el driver ve calle en Entregar y Ref en gris).
    $dir_completa = $direccion_escrita;
    if ($referencia !== '') {
        $dir_completa .= ' | Ref: ' . $referencia;
    }
    $dir_completa .= ' | GPS: ' . $lat . ', ' . $lng;

    if ($credito['disponible'] + 0.001 < $costo) {
        yora_fail('Crédito insuficiente (disponible $' . number_format($credito['disponible'], 2) . '). Paga facturas pendientes o espera el aumento de nivel.');
    }

    $conexion->begin_transaction();

    if (!yora_credito_consumir($conexion, $comercio_id, $costo)) {
        $conexion->rollback();
        yora_fail('Crédito insuficiente. Paga tus facturas pendientes para liberar cupo.');
    }

    $codigo_nuevo = 'ID' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    try {
        yora_exec(
            $conexion,
            'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, lat_entrega, lng_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus, codigo) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?)',
            'issssddsddsss',
            $comercio_id,
            $nombre,
            $telefono,
            $contenido,
            $dir_completa,
            $lat,
            $lng,
            $tiempo,
            $distancia,
            $costo,
            $tipo_pago,
            $estatus,
            $codigo_nuevo
        );
    } catch (Throwable $e) {
        // Fallback si faltan columnas lat/lng o choque de codigo.
        try {
            yora_exec(
                $conexion,
                'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus, codigo) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?)',
                'isssssddsss',
                $comercio_id,
                $nombre,
                $telefono,
                $contenido,
                $dir_completa,
                $tiempo,
                $distancia,
                $costo,
                $tipo_pago,
                $estatus,
                $codigo_nuevo
            );
        } catch (Throwable $e2) {
            yora_exec(
                $conexion,
                'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?)',
                'isssssddss',
                $comercio_id,
                $nombre,
                $telefono,
                $contenido,
                $dir_completa,
                $tiempo,
                $distancia,
                $costo,
                $tipo_pago,
                $estatus
            );
        }
    }
    $comanda_id = (int) $conexion->insert_id;

    $creditoFin = yora_credito_estado($conexion, $comercio_id);
    $conexion->commit();

    // Fuera de la transaccion: si algo de esto falla, el pedido ya esta hecho.
    $codigo = yora_codigo_comanda($conexion, $comanda_id);
    yora_guardar_cliente_comercio($conexion, $comercio_id, $cedula, $nombre, $telefono, $direccion_escrita);

    try {
        $local = trim((string) ($comercio['nombre'] ?? 'Un comercio'));
        yora_push_conductores($conexion, 'Nuevo pedido en el radar', $local . ' publicó un viaje. Entra al radar.', '/dashboard.php', true);
    } catch (Throwable $e) {
        error_log('push crear_comanda: ' . $e->getMessage());
    }

    $saldo_final = (float) $creditoFin['disponible'];
    $mensaje = '¡Conductor solicitado exitosamente! Pedido #' . $codigo;

    yora_json([
        'status' => 'success',
        'mensaje' => $mensaje,
        'nuevo_saldo' => $saldo_final,
        'credito_disponible' => $saldo_final,
        'credito_limite' => $creditoFin['limite'],
        'costo' => $costo,
        'distancia' => $distancia,
        'codigo' => $codigo,
    ]);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('crear_comanda: ' . $e->getMessage());
    yora_fail('No se pudo crear el pedido. Inténtalo de nuevo.');
}
