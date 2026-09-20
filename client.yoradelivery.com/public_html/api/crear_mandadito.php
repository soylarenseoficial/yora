<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$uid = yora_require_usuario();
yora_timezone($conexion);

try {
    $user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
    if (!$user) {
        yora_fail('Cuenta no encontrada.', 401);
    }
    if (($user['estatus'] ?? 'activo') !== 'activo') {
        yora_fail('Tu cuenta está suspendida. Escribe a soporte.');
    }
    $docs = yora_cliente_docs_estado($user);
    if (!$docs['puede_pedir']) {
        yora_fail('Verifica tu identidad en Ajustes (selfie y cédula) para pedir un mandadito.');
    }
    $encargo = trim((string) ($_POST['encargo'] ?? ''));
    $dir_rec = trim((string) ($_POST['direccionRecogida'] ?? 'Punto de recogida'));
    $dir_ent = trim((string) ($_POST['direccionEntrega'] ?? 'Destino'));
    $lat_rec = (float) ($_POST['lat_recogida'] ?? 0);
    $lng_rec = (float) ($_POST['lng_recogida'] ?? 0);
    $lat = (float) ($_POST['lat'] ?? 0);
    $lng = (float) ($_POST['lng'] ?? 0);
    $metodo = trim((string) ($_POST['tipo_pago'] ?? 'Billetera'));
    $referencia = trim((string) ($_POST['referencia'] ?? ''));
    if (!in_array($metodo, yora_metodos_pago_cliente(), true)) {
        $metodo = 'Billetera';
    }
    if ($encargo === '' || mb_strlen($encargo) > 500) {
        yora_fail('Describe qué deben recoger.');
    }
    if (!yora_coords_ok($lat_rec, $lng_rec) || !yora_coords_ok($lat, $lng)) {
        yora_fail('Marca recogida y entrega en el mapa.');
    }
    $ruta = yora_ruta_calles($lat_rec, $lng_rec, $lat, $lng, false);
    if ($ruta['km'] > 80) {
        yora_fail('La distancia supera el límite operativo.');
    }
    $tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
    $costo = (float) $tarifa['costo'];
    $comision = yora_comision_yora($costo);
    $dir_completa = $dir_ent . ' | GPS: ' . $lat . ', ' . $lng;
    $codigo = 'MD' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    $saldo = (float) ($user['billetera'] ?? 0);

    $estatus = 'Buscando Conductor';
    $tipo_pago = $metodo;
    if ($metodo === 'Billetera') {
        if ($saldo + 0.0001 < $costo) {
            yora_fail('Saldo insuficiente. Recarga tu billetera o paga este viaje con Pago Móvil.');
        }
    } elseif ($metodo === 'Pago Móvil') {
        if ($referencia === '') {
            yora_fail('Coloca el número de referencia del pago para que Yora lo verifique.');
        }
        $estatus = 'Esperando Pago';
    }

    yora_exec($conexion, 'UPDATE usuarios_app SET lat = ?, lng = ? WHERE id = ?', 'ddi', $lat, $lng, $uid);

    $conexion->begin_transaction();
    try {
        if ($metodo === 'Billetera') {
            if (yora_exec($conexion, 'UPDATE usuarios_app SET billetera = billetera - ? WHERE id = ? AND billetera >= ?', 'did', $costo, $uid, $costo) < 1) {
                throw new RuntimeException('Saldo insuficiente.');
            }
        }

        $sql = 'INSERT INTO comandas (comercio_id, conductor_id, cliente_nombre, cliente_telefono, detalles_entrega, direccion_entrega, tiempo_estimado, distancia_km, costo_delivery, comision_yora, tipo_pago, estatus, codigo, tipo_comanda, direccion_recogida, lat_recogida, lng_recogida, lat_entrega, lng_entrega, usuario_id) VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $tipos = 'sssssdddsssssddddi';
        $args = [
            (string) $user['nombre'],
            (string) $user['telefono'],
            $encargo,
            $dir_completa,
            'Ya está listo para retirar',
            $tarifa['km'],
            $costo,
            $comision,
            $tipo_pago,
            $estatus,
            $codigo,
            'mandadito',
            $dir_rec,
            $lat_rec,
            $lng_rec,
            $lat,
            $lng,
            $uid,
        ];
        try {
            yora_exec($conexion, $sql, $tipos, ...$args);
        } catch (Throwable $eNull) {
            $sql0 = str_replace('VALUES (NULL, NULL,', 'VALUES (0, NULL,', $sql);
            yora_exec($conexion, $sql0, $tipos, ...$args);
        }
        $comanda_id = (int) $conexion->insert_id;

        if ($metodo === 'Pago Móvil') {
            yora_reportar_pago_cliente($conexion, $uid, $costo, $metodo, $referencia, $comanda_id);
        }
        $conexion->commit();
    } catch (Throwable $eTx) {
        $conexion->rollback();
        throw $eTx;
    }

    if ($estatus === 'Buscando Conductor') {
        try {
            yora_push_conductores($conexion, 'Nuevo mandadito en el radar', 'Un usuario pidió un mandadito. Entra al radar.', '/dashboard.php', true);
        } catch (Throwable $e) {
            error_log('push client mandadito: ' . $e->getMessage());
        }
        if ($metodo === 'Billetera') {
            $mensaje = 'Mandadito publicado. #' . $codigo . ' · Se descontó $' . number_format($costo, 2) . ' de tu billetera.';
        } elseif ($metodo === 'Efectivo') {
            $mensaje = 'Mandadito al aire. #' . $codigo . ' · Pagas $' . number_format($costo, 2) . ' en efectivo al motorizado.';
        } else {
            $mensaje = 'Mandadito publicado. #' . $codigo . '.';
        }
    } else {
        $mensaje = 'Reportamos tu pago de $' . number_format($costo, 2) . '. En cuanto Yora lo verifique, el motorizado ve el viaje #' . $codigo . ' en el radar.';
    }

    yora_json([
        'status'  => 'success',
        'mensaje' => $mensaje,
        'codigo'  => $codigo,
        'costo'   => $costo,
        'estatus' => $estatus,
    ]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($msg === 'Saldo insuficiente.' || str_starts_with($msg, 'Esa referencia') || str_starts_with($msg, 'Coloca') || str_starts_with($msg, 'Monto') || str_starts_with($msg, 'No pudimos') || str_starts_with($msg, 'Método')) {
        yora_fail($msg);
    }
    error_log('client crear_mandadito: ' . $msg);
    yora_fail('No se pudo publicar el mandadito. Inténtalo de nuevo.');
}
