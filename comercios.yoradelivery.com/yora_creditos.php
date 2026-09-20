<?php
/**
 * Creditos / tipos comercio (compartido HQ + paneles).
 * Se carga con function_exists para no redefinir.
 */
if (function_exists('yora_credito_estado')) {
    return;
}

function yora_tipos_comercio(): array
{
    return [
        'Pequeño' => [
            'clave'       => 'Pequeño',
            'etiqueta'    => 'Pequeño',
            'min_recarga' => 1.00,
        ],
        'Mediano' => [
            'clave'       => 'Mediano',
            'etiqueta'    => 'Mediano',
            'min_recarga' => 1.00,
        ],
        'Grande' => [
            'clave'       => 'Grande',
            'etiqueta'    => 'Grande',
            'min_recarga' => 1.00,
        ],
    ];
}

function yora_tipo_comercio_clave(?string $tipo): string
{
    $t = trim((string) $tipo);
    if ($t === 'Pequeño mediano') {
        return 'Pequeño';
    }
    if ($t === 'Mediano grande') {
        return 'Grande';
    }
    $tipos = yora_tipos_comercio();
    return isset($tipos[$t]) ? $t : 'Pequeño';
}

function yora_min_recarga_comercio(?string $tipo): float
{
    return 1.00;
}

/** Penalización fija al reactivar tras gracia (USD). */
function yora_penalizacion_reactivacion(): float
{
    return 3.00;
}

function yora_ensure_creditos_schema(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->query(
            "CREATE TABLE IF NOT EXISTS creditos_comercio_matriz (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo ENUM('Pequeño','Mediano','Grande') NOT NULL,
                nivel TINYINT UNSIGNED NOT NULL,
                credito DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tipo_nivel (tipo, nivel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS facturas_comercio (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                comercio_id INT(11) NOT NULL,
                fecha_consumo DATE NOT NULL,
                monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                estatus ENUM('pendiente','gracia','pagada','vencida') NOT NULL DEFAULT 'pendiente',
                vence_el DATE NOT NULL,
                gracia_hasta DATE NOT NULL,
                pagado_el DATETIME DEFAULT NULL,
                penalizacion DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_comercio_dia (comercio_id, fecha_consumo),
                KEY idx_estatus_vence (estatus, vence_el),
                KEY idx_comercio (comercio_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $n = (int) ($db->query('SELECT COUNT(*) AS c FROM creditos_comercio_matriz')->fetch_assoc()['c'] ?? 0);
        if ($n < 1) {
            $db->query(
                "INSERT INTO creditos_comercio_matriz (tipo, nivel, credito) VALUES
                ('Pequeño',1,50),('Pequeño',2,80),('Pequeño',3,120),('Pequeño',4,170),('Pequeño',5,230),
                ('Mediano',1,70),('Mediano',2,110),('Mediano',3,160),('Mediano',4,220),('Mediano',5,300),
                ('Grande',1,100),('Grande',2,150),('Grande',3,220),('Grande',4,300),('Grande',5,400)"
            );
        }
    } catch (Throwable $e) {
        error_log('yora_ensure_creditos_schema: ' . $e->getMessage());
    }
}

function yora_credito_matriz_monto(mysqli $db, ?string $tipo, int $nivel): float
{
    yora_ensure_creditos_schema($db);
    $tipo = yora_tipo_comercio_clave($tipo);
    $nivel = max(1, min(5, $nivel));
    $row = yora_one($db, 'SELECT credito FROM creditos_comercio_matriz WHERE tipo = ? AND nivel = ?', 'si', $tipo, $nivel);
    if ($row) {
        return round((float) $row['credito'], 2);
    }
    $defaults = [
        'Pequeño' => [50, 80, 120, 170, 230],
        'Mediano' => [70, 110, 160, 220, 300],
        'Grande' => [100, 150, 220, 300, 400],
    ];
    return (float) ($defaults[$tipo][$nivel - 1] ?? 50);
}

function yora_facturas_pendientes_monto(mysqli $db, int $comercio_id): float
{
    yora_ensure_creditos_schema($db);
    $row = yora_one(
        $db,
        "SELECT COALESCE(SUM(monto + penalizacion),0) AS t FROM facturas_comercio
         WHERE comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')",
        'i',
        $comercio_id
    );
    return round((float) ($row['t'] ?? 0), 2);
}

/**
 * Estado de línea de crédito del comercio.
 * disponible = limite - consumido_ciclo - facturas_abiertas - penalizacion
 */
function yora_credito_estado(mysqli $db, int $comercio_id): array
{
    yora_ensure_creditos_schema($db);
    $c = yora_one(
        $db,
        'SELECT tipo_comercio, credito_limite, credito_consumido_ciclo, bloqueado_deuda, penalizacion_pendiente FROM comercios WHERE id = ?',
        'i',
        $comercio_id
    );
    if (!$c) {
        return [
            'limite' => 0.0, 'consumido' => 0.0, 'facturas' => 0.0, 'penalizacion' => 0.0,
            'disponible' => 0.0, 'bloqueado' => true, 'nivel' => 1, 'tipo' => 'Pequeño',
        ];
    }
    $nivelInfo = yora_nivel_comercio($db, $comercio_id);
    $nivelNum = (int) ($nivelInfo['nivel'] ?? 1);
    $tipo = yora_tipo_comercio_clave($c['tipo_comercio'] ?? 'Pequeño');
    $limiteMatriz = yora_credito_matriz_monto($db, $tipo, $nivelNum);
    $limite = round((float) ($c['credito_limite'] ?? 0), 2);
    if ($limite <= 0 || abs($limite - $limiteMatriz) > 0.009) {
        $limite = $limiteMatriz;
        try {
            yora_exec($db, 'UPDATE comercios SET credito_limite = ? WHERE id = ?', 'di', $limite, $comercio_id);
        } catch (Throwable $e) {
        }
    }
    $consumido = round((float) ($c['credito_consumido_ciclo'] ?? 0), 2);
    $facturas = yora_facturas_pendientes_monto($db, $comercio_id);
    $penal = round((float) ($c['penalizacion_pendiente'] ?? 0), 2);
    $disponible = round($limite - $consumido - $facturas - $penal, 2);
    $bloqueado = ((int) ($c['bloqueado_deuda'] ?? 0) === 1);
    return [
        'limite' => $limite,
        'consumido' => $consumido,
        'facturas' => $facturas,
        'penalizacion' => $penal,
        'disponible' => $disponible,
        'bloqueado' => $bloqueado,
        'nivel' => $nivelNum,
        'tipo' => $tipo,
        'nivel_info' => $nivelInfo,
    ];
}

/** Descuenta del crédito del ciclo (despacho). */
function yora_credito_consumir(mysqli $db, int $comercio_id, float $monto): bool
{
    $monto = round($monto, 2);
    if ($monto <= 0) {
        return true;
    }
    $est = yora_credito_estado($db, $comercio_id);
    if ($est['bloqueado'] || $est['disponible'] + 0.001 < $monto) {
        return false;
    }
    $n = yora_exec(
        $db,
        'UPDATE comercios SET credito_consumido_ciclo = credito_consumido_ciclo + ? WHERE id = ? AND bloqueado_deuda = 0',
        'di',
        $monto,
        $comercio_id
    );
    return $n > 0;
}

/** Devuelve crédito al ciclo (cancelación / ajuste). */
function yora_credito_devolver(mysqli $db, int $comercio_id, float $monto): void
{
    $monto = round($monto, 2);
    if ($monto <= 0) {
        return;
    }
    try {
        yora_exec(
            $db,
            'UPDATE comercios SET credito_consumido_ciclo = GREATEST(0, credito_consumido_ciclo - ?) WHERE id = ?',
            'di',
            $monto,
            $comercio_id
        );
    } catch (Throwable $e) {
        error_log('yora_credito_devolver: ' . $e->getMessage());
    }
}

function yora_es_mandadito(array $c): bool
{
    return strcasecmp(trim((string) ($c['tipo_comanda'] ?? '')), 'mandadito') === 0;
}

/** Datos de cobro de Yora HQ. El Pago Móvil 0414-5530182 no se cambia. */
function yora_pago_hq(): array
{
    return [
        'banco'    => 'Bancaribe',
        'codigo'   => '0114',
        'telefono' => '0414-5530182',
        'tel_num'  => '04145530182',
        'cedula'   => 'V-25.951.632',
        'ced_num'  => '25951632',
        'efectivo' => 'Carrera 17 con calle 27 y 28, Barquisimeto.',
    ];
}

function yora_metodos_pago_cliente(): array
{
    return ['Billetera', 'Pago Móvil', 'Efectivo'];
}

function yora_reportar_pago_cliente(mysqli $db, int $uid, float $monto, string $banco, string $referencia, ?int $comanda_id = null): int
{
    $banco = trim($banco);
    $referencia = trim($referencia);
    if ($monto < 0.5 || $monto > 50000 || $referencia === '' || mb_strlen($referencia) > 40) {
        throw new RuntimeException('Monto o referencia inválidos.');
    }
    if (!in_array($banco, ['Pago Móvil', 'Efectivo'], true)) {
        throw new RuntimeException('Método de pago no válido.');
    }
    $dup = yora_one($db, 'SELECT id FROM recargas_clientes WHERE referencia = ? AND banco_origen = ? LIMIT 1', 'ss', $referencia, $banco);
    if ($dup) {
        throw new RuntimeException('Esa referencia ya fue reportada.');
    }
    $tasa = 0.0;
    $bs = 0.0;
    if ($banco === 'Pago Móvil') {
        $tasa = (float) yora_tasa_bcv($db)['tasa'];
        if ($tasa <= 0) {
            throw new RuntimeException('No pudimos obtener la tasa del BCV. Inténtalo en unos minutos.');
        }
        $bs = round($monto * $tasa, 2);
    }
    yora_exec(
        $db,
        "INSERT INTO recargas_clientes (usuario_id, comanda_id, monto, monto_bs, tasa_bcv, banco_origen, referencia, fecha_pago, estatus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'En Revisión')",
        'iidddsss',
        $uid,
        (int) ($comanda_id ?? 0),
        $monto,
        $bs,
        $tasa,
        $banco,
        $referencia,
        date('Y-m-d')
    );
    return (int) $db->insert_id;
}

function yora_ganancia_driver(float $costo): float
{
    return round($costo * 0.80, 2);
}

function yora_comision_yora(float $costo): float
{
    return round($costo * 0.20, 2);
}

/** El viaje ya lo cobró Yora (billetera, pago móvil u otro prepago). No es efectivo en mano. */
function yora_viaje_paga_billetera(array $c): bool
{
    $tipo = trim((string) ($c['tipo_pago'] ?? ''));
    if ($tipo === '' || strcasecmp($tipo, 'Efectivo') === 0) {
        return false;
    }
    return true;
}

/** Al entregar: prepago/PM acredita 80%; efectivo en mano descuenta el 20% de Yora. */
function yora_acreditar_viaje_driver(mysqli $db, int $conductor_id, array $comanda): void
{
    if ($conductor_id < 1) {
        return;
    }
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    if ($costo <= 0) {
        return;
    }
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        $comision = yora_comision_yora($costo);
        if ($comision > 0) {
            yora_exec($db, 'UPDATE conductores SET billetera = billetera - ? WHERE id = ?', 'di', $comision, $conductor_id);
        }
        return;
    }
    if (!yora_viaje_paga_billetera($comanda)) {
        return;
    }
    $ganancia = yora_ganancia_driver($costo);
    if ($ganancia <= 0) {
        return;
    }
    yora_exec($db, 'UPDATE conductores SET billetera = billetera + ? WHERE id = ?', 'di', $ganancia, $conductor_id);
}

/** Lo que entra (+) o sale (-) de la billetera del driver al entregar. */
function yora_impacto_billetera_driver(array $comanda): float
{
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    if ($costo <= 0) {
        return 0.0;
    }
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        return -yora_comision_yora($costo);
    }
    if (!yora_viaje_paga_billetera($comanda)) {
        return 0.0;
    }
    return yora_ganancia_driver($costo);
}

/** En radar: efectivo en mano es el total; prepago es el 80%. */
function yora_monto_radar_driver(array $comanda): float
{
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        return round($costo, 2);
    }
    return yora_ganancia_driver($costo);
}

function yora_es_efectivo_en_mano(array $comanda): bool
{
    return strcasecmp(trim((string) ($comanda['tipo_pago'] ?? '')), 'Efectivo') === 0
        && yora_es_mandadito($comanda);
}

function yora_comanda_hq(mysqli $db, int $id): ?array
{
    return yora_one(
        $db,
        'SELECT c.*, COALESCE(NULLIF(r.nombre, \'\'), IF(c.tipo_comanda = \'mandadito\', \'Mandadito\', \'Yora\')) AS restaurante,
                r.billetera AS rest_billetera, COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat,
                COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng, r.direccion AS res_dir,
                d.nombre AS conductor, d.telefono AS conductor_tel
         FROM comandas c
         LEFT JOIN comercios r ON r.id = c.comercio_id
         LEFT JOIN conductores d ON d.id = c.conductor_id
         WHERE c.id = ?',
        'i',
        $id
    );
}

function yora_ajustar_saldos_por_costo(mysqli $db, array $comanda, float $nuevo_costo): void
{
    $viejo = (float) ($comanda['costo_delivery'] ?? 0);
    $diff = round($nuevo_costo - $viejo, 2);
    if (abs($diff) < 0.001) {
        return;
    }
    $rid = (int) ($comanda['comercio_id'] ?? 0);
    if ($rid > 0) {
        if ($diff > 0) {
            yora_exec($db, 'UPDATE comercios SET billetera = billetera - ? WHERE id = ?', 'di', $diff, $rid);
        } else {
            yora_exec($db, 'UPDATE comercios SET billetera = billetera + ? WHERE id = ?', 'di', abs($diff), $rid);
        }
    } elseif (yora_es_mandadito($comanda) && strcasecmp((string) ($comanda['tipo_pago'] ?? ''), 'Billetera') === 0) {
        $uid = (int) ($comanda['usuario_id'] ?? 0);
        if ($uid > 0) {
            if ($diff > 0) {
                yora_exec($db, 'UPDATE usuarios_app SET billetera = billetera - ? WHERE id = ?', 'di', $diff, $uid);
            } else {
                yora_exec($db, 'UPDATE usuarios_app SET billetera = billetera + ? WHERE id = ?', 'di', abs($diff), $uid);
            }
        }
    }
    if (($comanda['estatus'] ?? '') === 'Entregado') {
        $cid = (int) ($comanda['conductor_id'] ?? 0);
        if ($cid > 0) {
            $delta = round(yora_impacto_billetera_driver(array_merge($comanda, ['costo_delivery' => $nuevo_costo])) - yora_impacto_billetera_driver($comanda), 2);
            if (abs($delta) >= 0.001) {
                yora_exec($db, 'UPDATE conductores SET billetera = billetera + ? WHERE id = ?', 'di', $delta, $cid);
            }
        }
    }
}

function yora_hq_liberar_driver(mysqli $db, array $c): void
{
    $id = (int) $c['id'];
    $viejo = (int) ($c['conductor_id'] ?? 0);
    $codigo = yora_codigo_comanda($db, $id, $c['codigo'] ?? null);
    yora_exec(
        $db,
        "UPDATE comandas SET conductor_id = NULL, estatus = 'Buscando Conductor', lote_id = NULL WHERE id = ? AND estatus NOT IN ('Entregado','Cancelado')",
        'i',
        $id
    );
    if ($viejo > 0) {
        try {
            yora_push_conductor($db, $viejo, 'Viaje reasignado', 'HQ te quitó el viaje #' . $codigo . '.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    try {
        yora_push_conductores($db, 'Viaje de vuelta al radar', 'El pedido #' . $codigo . ' está de nuevo disponible.', '/dashboard.php', true);
    } catch (Throwable $e) {
    }
}

function yora_hq_asignar_driver(mysqli $db, array $c, int $driver_id): void
{
    $id = (int) $c['id'];
    $driver = yora_one($db, 'SELECT id, nombre, estatus FROM conductores WHERE id = ?', 'i', $driver_id);
    if (!$driver) {
        throw new RuntimeException('Conductor no encontrado.');
    }
    $est = strtolower(trim((string) ($driver['estatus'] ?? 'activo')));
    if (in_array($est, ['pendiente', 'rechazado', 'inactivo'], true)) {
        throw new RuntimeException('Ese conductor no puede tomar viajes.');
    }
    $viejo = (int) ($c['conductor_id'] ?? 0);
    $estatus_actual = (string) ($c['estatus'] ?? '');
    $nuevo_estatus = $estatus_actual === 'En Camino a Cliente' ? 'En Camino a Cliente' : 'En Camino a Comercio';
    yora_exec(
        $db,
        "UPDATE comandas SET conductor_id = ?, estatus = ?, lote_id = NULL WHERE id = ? AND estatus NOT IN ('Entregado','Cancelado')",
        'isi',
        $driver_id,
        $nuevo_estatus,
        $id
    );
    $codigo = yora_codigo_comanda($db, $id, $c['codigo'] ?? null);
    if ($viejo > 0 && $viejo !== $driver_id) {
        try {
            yora_push_conductor($db, $viejo, 'Viaje reasignado', 'HQ te quitó el viaje #' . $codigo . '.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    try {
        yora_push_conductor($db, $driver_id, 'HQ te asignó un viaje', 'Pedido #' . $codigo . '. Entra al radar.', '/dashboard.php');
    } catch (Throwable $e) {
    }
}

function yora_hq_corregir_destino(mysqli $db, array $c, string $direccion, float $lat, float $lng, ?float $km_forzado, ?float $costo_forzado): array
{
    if (!yora_coords_ok($lat, $lng)) {
        throw new RuntimeException('Las coordenadas de entrega no son válidas.');
    }
    [$plat, $plng] = yora_coords_recogida_comanda($c);
    if (!yora_coords_ok($plat, $plng)) {
        throw new RuntimeException('No hay punto de recogida para recalcular la ruta.');
    }
    $ruta = yora_ruta_calles($plat, $plng, $lat, $lng, false);
    $km = $km_forzado !== null && $km_forzado > 0 ? $km_forzado : (float) $ruta['km'];
    if ($km > 80) {
        throw new RuntimeException('La distancia supera el límite operativo (80 km).');
    }
    $tarifa = yora_calcular_tarifa($db, $km);
    $nuevo_costo = $costo_forzado !== null && $costo_forzado > 0 ? round($costo_forzado, 2) : (float) $tarifa['costo'];
    $dir = trim($direccion);
    if ($dir === '') {
        $dir = 'Destino corregido por HQ';
    }
    if (!str_contains($dir, 'GPS:')) {
        $dir .= ' | GPS: ' . $lat . ', ' . $lng;
    }
    yora_ajustar_saldos_por_costo($db, $c, $nuevo_costo);
    yora_exec(
        $db,
        'UPDATE comandas SET direccion_entrega = ?, lat_entrega = ?, lng_entrega = ?, distancia_km = ?, costo_delivery = ?, comision_yora = ?, geocerca_libre = 0 WHERE id = ?',
        'sdddddi',
        $dir,
        $lat,
        $lng,
        round($km, 2),
        $nuevo_costo,
        yora_comision_yora($nuevo_costo),
        (int) $c['id']
    );
    $cid = (int) ($c['conductor_id'] ?? 0);
    if ($cid > 0) {
        try {
            yora_push_conductor($db, $cid, 'Destino actualizado', 'HQ corrigió la dirección del viaje. Recarga el mapa.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    return ['km' => round($km, 2), 'costo' => $nuevo_costo];
}

/** Punto donde el motorizado recoge (local o pin del mandadito). */
function yora_coords_recogida_comanda(array $c): array
{
    if (yora_es_mandadito($c)) {
        $lat = (float) ($c['lat_recogida'] ?? 0);
        $lng = (float) ($c['lng_recogida'] ?? 0);
        if (yora_coords_ok($lat, $lng)) {
            return [$lat, $lng];
        }
    }
    $lat = (float) ($c['rest_lat'] ?? $c['latitud'] ?? 0);
    $lng = (float) ($c['rest_lng'] ?? $c['longitud'] ?? 0);
    return yora_coords_ok($lat, $lng) ? [$lat, $lng] : [0.0, 0.0];
}

function yora_texto_recogida_comanda(array $c): string
{
    if (yora_es_mandadito($c)) {
        $t = trim((string) ($c['direccion_recogida'] ?? ''));
        return $t !== '' ? $t : 'Punto de recogida';
    }
    $t = trim((string) ($c['res_dir'] ?? $c['direccion'] ?? ''));
    return $t !== '' ? $t : 'Local';
}

/** Coordenadas de entrega: columna propia o GPS pegado a la dirección. */
function yora_coords_entrega_comanda(array $c): array
{
    $lat = (float) ($c['lat_entrega'] ?? 0);
    $lng = (float) ($c['lng_entrega'] ?? 0);
    if (yora_coords_ok($lat, $lng)) {
        return [$lat, $lng];
    }
    $gps = yora_gps_desde_direccion($c['direccion_entrega'] ?? '');
    return $gps ?: [0.0, 0.0];
}

/**
 * Tramos de distancia configurados en "Tarifas y Finanzas", de menor a mayor.
 * El ultimo tramo tiene 'hasta' = null, es decir "de ahi en adelante".
 */
function yora_tramos_tarifa(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $filas = [];
    try {
        $filas = yora_all($db, 'SELECT km_hasta, precio_km FROM tarifas_tramos ORDER BY orden ASC, id ASC');
    } catch (Throwable $e) {
        error_log('yora_tramos_tarifa: ' . $e->getMessage());
    }

    $tramos = [];
    foreach ($filas as $f) {
        $precio = (float) $f['precio_km'];
        if ($precio <= 0) {
            continue;
        }
        $tramos[] = [
            'hasta'  => $f['km_hasta'] === null ? null : (float) $f['km_hasta'],
            'precio' => $precio,
        ];
    }

    // Sin tramos configurados se sigue usando el precio unico de siempre.
    if (!$tramos) {
        $row = yora_one($db, 'SELECT precio_km FROM configuracion_web LIMIT 1');
        $precio = $row ? (float) $row['precio_km'] : 0.40;
        $tramos[] = ['hasta' => null, 'precio' => $precio > 0 ? $precio : 0.40];
    }

    // El ultimo tramo cubre siempre hasta el infinito, pase lo que pase.
    $tramos[count($tramos) - 1]['hasta'] = null;

    $cache = $tramos;
    return $cache;
}

function yora_tarifa_minima(mysqli $db): float
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $minima = YORA_TARIFA_MINIMA;
    try {
        $row = yora_one($db, 'SELECT tarifa_minima FROM configuracion_web LIMIT 1');
        if ($row && (float) $row['tarifa_minima'] > 0) {
            $minima = (float) $row['tarifa_minima'];
        }
    } catch (Throwable $e) {
        error_log('yora_tarifa_minima: ' . $e->getMessage());
    }
    $cache = $minima;
    return $cache;
}

/** Precio del primer tramo: es el "desde" que se muestra en los paneles. */
function yora_precio_km(mysqli $db): float
{
    $tramos = yora_tramos_tarifa($db);
    return (float) $tramos[0]['precio'];
}

/**
 * Cobro PROGRESIVO por tramos: cada kilometro paga el precio del tramo al que
 * pertenece, no el del tramo donde cae la distancia total. Con tramos de
 * 0-8 km a 0,40 y 8-12 km a 0,30, un envio de 10 km cuesta
 * 8 x 0,40 + 2 x 0,30 = 3,80, no 10 x 0,30.
 */
function yora_calcular_tarifa(mysqli $db, float $km): array
{
    $km = max(0.0, $km);
    $tramos = yora_tramos_tarifa($db);

    $costo = 0.0;
    $desde = 0.0;
    $desglose = [];

    foreach ($tramos as $tramo) {
        $hasta = $tramo['hasta'] === null ? $km : min($km, (float) $tramo['hasta']);
        $km_tramo = $hasta - $desde;

        if ($km_tramo > 0) {
            $parcial = $km_tramo * $tramo['precio'];
            $costo += $parcial;
            $desglose[] = [
                'desde'  => round($desde, 2),
                'hasta'  => round($hasta, 2),
                'km'     => round($km_tramo, 2),
                'precio' => $tramo['precio'],
                'monto'  => round($parcial, 2),
            ];
        }

        $desde = $tramo['hasta'] === null ? $km : (float) $tramo['hasta'];
        if ($desde >= $km) {
            break;
        }
    }

    $minima = yora_tarifa_minima($db);

    return [
        'km'        => round($km, 2),
        'precio_km' => (float) $tramos[0]['precio'],
        'costo'     => max($minima, round($costo, 2)),
        'minima'    => $minima,
        'desglose'  => $desglose,
    ];
}

/**
 * Codigo publico del pedido, tipo "ID3541". Es lo que ven comercio, driver y
 * cliente, para no andar mostrando el numero correlativo de la base de datos.
 *
 * Los pedidos creados antes de esta mejora no tienen codigo: se les genera y
 * se guarda la primera vez que alguien los abre.
 */
function yora_codigo_comanda(mysqli $db, int $comanda_id, ?string $actual = null): string
{
    $actual = trim((string) $actual);
    if ($actual !== '') {
        return $actual;
    }

    $fila = yora_one($db, 'SELECT codigo FROM comandas WHERE id = ?', 'i', $comanda_id);
    if ($fila && trim((string) $fila['codigo']) !== '') {
        return trim((string) $fila['codigo']);
    }

    for ($intento = 0; $intento < 25; $intento++) {
        // Tras varios choques se pasa a 6 digitos para no quedarse atascado.
        $digitos = $intento < 15 ? 5 : 6;
        $codigo = 'ID' . str_pad((string) random_int(1, (10 ** $digitos) - 1), $digitos, '0', STR_PAD_LEFT);
        try {
            $puesto = yora_exec(
                $db,
                "UPDATE comandas SET codigo = ? WHERE id = ? AND (codigo IS NULL OR codigo = '')",
                'si',
                $codigo,
                $comanda_id
            );
            if ($puesto > 0) {
                return $codigo;
            }
            // No se actualizo: o ya tenia codigo, o lo puso otra peticion.
            $fila = yora_one($db, 'SELECT codigo FROM comandas WHERE id = ?', 'i', $comanda_id);
            if ($fila && trim((string) $fila['codigo']) !== '') {
                return trim((string) $fila['codigo']);
            }
        } catch (Throwable $e) {
            // Codigo repetido: se reintenta con otro numero.
        }
    }

    return 'ID' . str_pad((string) $comanda_id, 5, '0', STR_PAD_LEFT);
}

/** Cuenta pedidos entregados del comercio. No debe tumbar un viaje si la columna no existe. */
function yora_comercio_contar_entregas(mysqli $db, int $comercio_id): void
{
    if ($comercio_id < 1) {
        return;
    }
    $n = 0;
    try {
        $n = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas WHERE comercio_id = ? AND TRIM(estatus) = 'Entregado'",
            'i',
            $comercio_id
        )['total'] ?? 0);
    } catch (Throwable $e) {
        return;
    }
    foreach (['entregas_totales', 'envios_totales'] as $col) {
        try {
            yora_exec($db, "UPDATE comercios SET {$col} = ? WHERE id = ?", 'ii', $n, $comercio_id);
        } catch (Throwable $e) {
            error_log('yora_comercio_contar_entregas ' . $col . ': ' . $e->getMessage());
        }
    }
}

/** Los niveles del Club Yora de comercios, tal como estan en el panel HQ. */
function yora_niveles_comercio(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $cache = yora_all($db, 'SELECT * FROM niveles_comercio ORDER BY pedidos_desde ASC, nivel ASC');
    } catch (Throwable $e) {
        error_log('yora_niveles_comercio: ' . $e->getMessage());
    }
    return $cache;
}

/**
 * Nivel del comercio segun los pedidos ENTREGADOS en el mes en curso.
 * El contador se reinicia solo el dia 1 de cada mes.
 */
function yora_nivel_comercio(mysqli $db, int $comercio_id): array
{
    $pedidos = 0;
    try {
        $pedidos = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas
             WHERE comercio_id = ?
               AND TRIM(estatus) = 'Entregado'
               AND COALESCE(fecha_entrega, fecha_creacion) >= ?",
            'is',
            $comercio_id,
            date('Y-m-01 00:00:00')
        )['total'] ?? 0);
        $vida = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas WHERE comercio_id = ? AND TRIM(estatus) = 'Entregado'",
            'i',
            $comercio_id
        )['total'] ?? 0);
        yora_comercio_contar_entregas($db, $comercio_id);
    } catch (Throwable $e) {
        error_log('yora_nivel_comercio: ' . $e->getMessage());
    }

    $niveles = yora_niveles_comercio($db);
    $actual = null;
    $siguiente = null;

    foreach ($niveles as $n) {
        $desde = (int) $n['pedidos_desde'];
        $hasta = $n['pedidos_hasta'] === null ? PHP_INT_MAX : (int) $n['pedidos_hasta'];
        if ($pedidos >= $desde && $pedidos <= $hasta) {
            $actual = $n;
        } elseif ($pedidos < $desde && $siguiente === null) {
            $siguiente = $n;
        }
    }

    if ($actual === null) {
        $actual = $niveles[0] ?? [
            'nivel' => 1, 'nombre' => 'Nivel 1', 'pedidos_desde' => 0, 'pedidos_hasta' => 50,
            'bono_mensual' => 0.00, 'margen_credito' => 0.00, 'color' => '#94a3b8',
            'estrellas' => 0, 'beneficios' => 'Acceso total al panel',
        ];
    }

    $actual['pedidos_mes']      = $pedidos;
    $actual['meta']             = $siguiente ? (int) $siguiente['pedidos_desde'] : max(1, (int) $actual['pedidos_desde']);
    $actual['siguiente_nombre'] = (string) ($siguiente['nombre'] ?? '');
    $actual['lista_beneficios'] = array_values(array_filter(array_map(
        'trim',
        explode('|', (string) ($actual['beneficios'] ?? ''))
    )));

    return $actual;
}

/**
 * Acredita el bono del nivel una sola vez por mes calendario.
 * Devuelve el monto acreditado, o 0 si ya lo recibio o su nivel no da bono.
 */
function yora_acreditar_bono_mensual(mysqli $db, int $comercio_id, array $nivel): float
{
    $bono = round((float) ($nivel['bono_mensual'] ?? 0), 2);
    if ($bono <= 0) {
        return 0.0;
    }

    // La condicion del WHERE es la que garantiza que no se pague dos veces,
    // aunque el comercio abra el panel en dos pestanas a la vez.
    $mes = date('Y-m');
    try {
        $n = yora_exec(
            $db,
            'UPDATE comercios SET billetera = billetera + ?, bono_mes = ? WHERE id = ? AND (bono_mes IS NULL OR bono_mes <> ?)',
            'dsis',
            $bono,
            $mes,
            $comercio_id,
            $mes
        );
        return $n > 0 ? $bono : 0.0;
    } catch (Throwable $e) {
        error_log('yora_acreditar_bono_mensual: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Margen de respaldo en saldo.
 *
 * Un comercio con nivel puede seguir despachando aunque su billetera llegue a
 * cero, hasta el limite negativo que da su nivel. Si pasa mas de 24 horas en
 * negativo sin recargar, queda bloqueado hasta que salde la deuda.
 */
function yora_estado_deuda_comercio(mysqli $db, int $comercio_id, float $saldo = 0.0, ?string $deuda_desde = null, array $nivel = []): array
{
    $est = yora_credito_estado($db, $comercio_id);
    return [
        'margen' => 0.0,
        'deuda' => round($est['facturas'] + $est['penalizacion'], 2),
        'horas' => 0.0,
        'restantes' => 0.0,
        'bloqueado' => $est['bloqueado'],
        'disponible' => $est['disponible'],
        'limite' => $est['limite'],
        'consumido' => $est['consumido'],
    ];
}

/**
 * Cierre diario: factura el consumo del día anterior; aplica gracia y bloqueos.
 */
function yora_cron_creditos_comercio(mysqli $db): array
{
    yora_timezone($db);
    yora_ensure_creditos_schema($db);
    $resumen = ['facturas' => 0, 'gracia' => 0, 'bloqueos' => 0];
    $ayer = date('Y-m-d', strtotime('-1 day'));
    $hoy = date('Y-m-d');

    $rows = yora_all(
        $db,
        'SELECT id, credito_consumido_ciclo FROM comercios WHERE credito_consumido_ciclo > 0.009'
    ) ?: [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $monto = round((float) $r['credito_consumido_ciclo'], 2);
        if ($monto <= 0) {
            continue;
        }
        $vence = date('Y-m-d', strtotime($ayer . ' +7 days'));
        $gracia = date('Y-m-d', strtotime($vence . ' +1 day'));
        try {
            $db->begin_transaction();
            yora_exec(
                $db,
                "INSERT INTO facturas_comercio (comercio_id, fecha_consumo, monto, estatus, vence_el, gracia_hasta, penalizacion)
                 VALUES (?, ?, ?, 'pendiente', ?, ?, 0)
                 ON DUPLICATE KEY UPDATE monto = monto + VALUES(monto)",
                'isdss',
                $id,
                $ayer,
                $monto,
                $vence,
                $gracia
            );
            yora_exec($db, 'UPDATE comercios SET credito_consumido_ciclo = 0 WHERE id = ?', 'i', $id);
            $db->commit();
            $resumen['facturas']++;
        } catch (Throwable $e) {
            @$db->rollback();
            error_log('cron factura comercio ' . $id . ': ' . $e->getMessage());
        }
    }

    $g = yora_exec(
        $db,
        "UPDATE facturas_comercio SET estatus = 'gracia' WHERE estatus = 'pendiente' AND vence_el < ?",
        's',
        $hoy
    );
    $resumen['gracia'] = max(0, (int) $g);

    $q = $db->prepare("SELECT id, comercio_id FROM facturas_comercio WHERE estatus = 'gracia' AND gracia_hasta < ?");
    $q->bind_param('s', $hoy);
    $q->execute();
    $vencidas = $q->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $q->close();
    $pen = yora_penalizacion_reactivacion();
    foreach ($vencidas as $f) {
        try {
            yora_exec($db, "UPDATE facturas_comercio SET estatus = 'vencida', penalizacion = ? WHERE id = ?", 'di', $pen, (int) $f['id']);
            yora_exec(
                $db,
                'UPDATE comercios SET bloqueado_deuda = 1, penalizacion_pendiente = GREATEST(penalizacion_pendiente, ?) WHERE id = ?',
                'di',
                $pen,
                (int) $f['comercio_id']
            );
            $resumen['bloqueos']++;
        } catch (Throwable $e) {
            error_log('cron bloqueo: ' . $e->getMessage());
        }
    }

    return $resumen;
}

function yora_credito_aplicar_pago(mysqli $db, int $comercio_id, array $factura_ids, float $penalizacion_pagada = 0.0): void
{
    yora_ensure_creditos_schema($db);
    foreach ($factura_ids as $fid) {
        $fid = (int) $fid;
        if ($fid < 1) {
            continue;
        }
        yora_exec(
            $db,
            "UPDATE facturas_comercio SET estatus = 'pagada', pagado_el = NOW(), penalizacion = 0
             WHERE id = ? AND comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')",
            'ii',
            $fid,
            $comercio_id
        );
    }
    if ($penalizacion_pagada > 0) {
        yora_exec(
            $db,
            'UPDATE comercios SET penalizacion_pendiente = GREATEST(0, penalizacion_pendiente - ?) WHERE id = ?',
            'di',
            $penalizacion_pagada,
            $comercio_id
        );
    }
    $pend = yora_facturas_pendientes_monto($db, $comercio_id);
    $row = yora_one($db, 'SELECT penalizacion_pendiente FROM comercios WHERE id = ?', 'i', $comercio_id);
    $pen = round((float) ($row['penalizacion_pendiente'] ?? 0), 2);
    if ($pend <= 0.009 && $pen <= 0.009) {
        yora_exec($db, 'UPDATE comercios SET bloqueado_deuda = 0, deuda_desde = NULL, penalizacion_pendiente = 0 WHERE id = ?', 'i', $comercio_id);
    }
}

/**
 * Guarda o actualiza el cliente en la agenda del comercio.
 * Cada comercio tiene la suya: la cedula solo es unica dentro del mismo local.
 */
