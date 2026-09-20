<?php
/**
 * Sync JSON para YoraDriver nativo (radar + mis viajes).
 * Auth: Bearer / X-Yora-Token o sesión web.
 * GET: radio, lat, lng
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_mant_exigir($conexion, 'drivers', $conductor_id);
yora_timezone($conexion);

function yora_sync_distancia($lat1, $lon1, $lat2, $lon2): float
{
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
        return 0.0;
    }
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    return (rad2deg(acos(min(1, max(-1, $dist)))) * 60 * 1.1515 * 1.609344);
}

function yora_sync_item_viaje(mysqli $db, array $v, ?float $mi_lat, ?float $mi_lng): array
{
    try {
        if (trim((string) ($v['restaurante'] ?? '')) === '') {
            $v['restaurante'] = yora_es_mandadito($v)
                ? ('Mandadito · ' . trim((string) ($v['cliente_nombre'] ?? 'Cliente')))
                : 'Pedido Yora';
        }
        $v['codigo'] = yora_codigo_comanda($db, (int) $v['id'], $v['codigo'] ?? null);
        [$pick_lat, $pick_lng] = yora_coords_recogida_comanda($v);
        if (!yora_coords_ok($pick_lat, $pick_lng)) {
            $pick_lat = (float) ($v['rest_lat'] ?? 0);
            $pick_lng = (float) ($v['rest_lng'] ?? 0);
        }
        [$drop_lat, $drop_lng] = yora_coords_entrega_comanda($v);
        if (!yora_coords_ok($drop_lat, $drop_lng)) {
            $gpsTxt = yora_gps_desde_direccion($v['direccion_entrega'] ?? '');
            if ($gpsTxt) {
                [$drop_lat, $drop_lng] = $gpsTxt;
            }
        }
        $dist = 0.0;
        if ($mi_lat !== null && $mi_lng !== null && yora_coords_ok($pick_lat, $pick_lng)) {
            $dist = yora_sync_distancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
        }
        $logo = yora_url_archivo(
            $v['logo_url'] ?? '',
            'https://cdn-icons-png.flaticon.com/512/819/819814.png',
            'https://comercios.yoradelivery.com'
        );
        $nombreLocal = trim((string) ($v['restaurante'] ?? ''));
        $textoRecogida = yora_texto_recogida_comanda($v);
        if ($nombreLocal !== '' && $textoRecogida !== '' && stripos($textoRecogida, $nombreLocal) === false) {
            $textoRecogida = $nombreLocal . ' · ' . $textoRecogida;
        }
        $textoEntrega = yora_texto_destino($v['direccion_entrega'] ?? '');
        $textoRef = '';
        if (function_exists('yora_texto_referencia')) {
            $textoRef = (string) yora_texto_referencia($v['direccion_entrega'] ?? '');
        }
        if ($textoEntrega === '' || mb_strlen($textoEntrega) < 3) {
            $textoEntrega = $textoRef !== '' ? $textoRef : trim((string) ($v['detalles_entrega'] ?? ''));
        }
        if ($textoEntrega === '') {
            $textoEntrega = 'Destino en el mapa';
        }
        return [
            'id' => (int) $v['id'],
            'codigo' => (string) ($v['codigo'] ?? ('ID' . $v['id'])),
            'estatus' => (string) ($v['estatus'] ?? ''),
            'restaurante' => $nombreLocal,
            'logo' => $logo,
            'ganancia' => round(yora_monto_radar_driver($v), 2),
            'distancia_km' => round($dist > 0 ? $dist : (float) ($v['distancia_km'] ?? 0), 2),
            'tiempo_estimado' => (string) ($v['tiempo_estimado'] ?? ''),
            'recogida' => $textoRecogida !== '' ? $textoRecogida : 'Local',
            'entrega' => $textoEntrega,
            'referencia' => $textoRef,
            'mandadito' => yora_es_mandadito($v),
            'efectivo' => yora_es_efectivo_en_mano($v),
            'cliente_nombre' => (string) ($v['cliente_nombre'] ?? ''),
            'detalles' => (string) ($v['detalles_entrega'] ?? ''),
            'pick_lat' => $pick_lat,
            'pick_lng' => $pick_lng,
            'drop_lat' => $drop_lat,
            'drop_lng' => $drop_lng,
            'lote_id' => (string) ($v['lote_id'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('yora_sync_item_viaje: ' . $e->getMessage());
        return [
            'id' => (int) ($v['id'] ?? 0),
            'codigo' => (string) ($v['codigo'] ?? ('ID' . (int) ($v['id'] ?? 0))),
            'estatus' => (string) ($v['estatus'] ?? ''),
            'restaurante' => (string) ($v['restaurante'] ?? 'Pedido'),
            'logo' => '',
            'ganancia' => 0.0,
            'distancia_km' => 0.0,
            'tiempo_estimado' => '',
            'recogida' => 'Local',
            'entrega' => 'Destino',
            'referencia' => '',
            'mandadito' => false,
            'efectivo' => false,
            'cliente_nombre' => (string) ($v['cliente_nombre'] ?? ''),
            'detalles' => '',
            'pick_lat' => 0.0,
            'pick_lng' => 0.0,
            'drop_lat' => 0.0,
            'drop_lng' => 0.0,
            'lote_id' => '',
        ];
    }
}

$cond = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$cond) {
    yora_json(['ok' => false, 'mensaje' => 'Conductor no encontrado'], 404);
}
$docs = yora_docs_estado($cond);
$en_linea = (int) ($cond['en_linea'] ?? 0);

$mi_lat = (isset($_GET['lat']) && $_GET['lat'] !== '') ? (float) $_GET['lat'] : null;
$mi_lng = (isset($_GET['lng']) && $_GET['lng'] !== '') ? (float) $_GET['lng'] : null;
$radio_km = isset($_GET['radio']) ? (float) $_GET['radio'] : 5.0;
$tiene_gps = ($mi_lat !== null && $mi_lng !== null && yora_coords_ok($mi_lat, $mi_lng));
$gps_fuente = $tiene_gps ? 'app' : 'ninguna';

// APK lite sin GPS en vivo: usa última ubicación guardada para aplicar el rango.
if (!$tiene_gps) {
    $ula = (float) ($cond['ultima_lat'] ?? 0);
    $ulo = (float) ($cond['ultima_lng'] ?? 0);
    if (yora_coords_ok($ula, $ulo)) {
        $mi_lat = $ula;
        $mi_lng = $ulo;
        $tiene_gps = true;
        $gps_fuente = 'ultima';
    }
}

$sql_base = "SELECT c.*, r.nombre AS restaurante, r.direccion AS res_dir, r.logo_url,
    COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat, COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng,
    r.telefono AS rest_telefono
    FROM comandas c LEFT JOIN comercios r ON c.comercio_id = r.id";

$activos = [];
$revisando = [];
$res_act = $conexion->query(
    "$sql_base WHERE c.conductor_id = $conductor_id AND c.estatus NOT IN ('Entregado', 'Cancelado') ORDER BY c.id ASC"
);
if ($res_act) {
    while ($v = $res_act->fetch_assoc()) {
        $item = yora_sync_item_viaje($conexion, $v, $mi_lat, $mi_lng);
        if (($v['estatus'] ?? '') === 'Revisando') {
            $revisando[] = $item;
        } else {
            $activos[] = $item;
        }
    }
}

$anuncios = [];
$anuncios_ids = [];
$mensaje_radar = '';

if ($en_linea === 0) {
    $mensaje_radar = 'Estás Offline. Ponte Online para ver pedidos.';
} elseif (count($revisando) > 0) {
    $mensaje_radar = 'Tienes un viaje por confirmar. Termínalo antes de tomar otro.';
} elseif (count($activos) > 0) {
    $mensaje_radar = 'Tienes un viaje en curso. Termínalo antes de tomar otro.';
} elseif (!$tiene_gps) {
    // Sin ninguna ubicación: listar, pero avisar que el rango no se puede aplicar.
    $res_radar = $conexion->query(
        "$sql_base WHERE c.estatus = 'Buscando Conductor' ORDER BY c.id ASC LIMIT 40"
    );
    if ($res_radar) {
        while ($v = $res_radar->fetch_assoc()) {
            $item = yora_sync_item_viaje($conexion, $v, null, null);
            $anuncios[] = $item;
            $anuncios_ids[] = (int) $v['id'];
        }
    }
    $mensaje_radar = count($anuncios) === 0
        ? 'No hay pedidos disponibles ahora.'
        : 'Sin GPS aún: el filtro de rango se activará con la ubicación.';
} else {
    $res_radar = $conexion->query(
        "$sql_base WHERE c.estatus = 'Buscando Conductor' ORDER BY c.id ASC"
    );
    $candidatos = [];
    if ($res_radar) {
        while ($v = $res_radar->fetch_assoc()) {
            [$pick_lat, $pick_lng] = yora_coords_recogida_comanda($v);
            if (!yora_coords_ok($pick_lat, $pick_lng)) {
                $pick_lat = (float) ($v['rest_lat'] ?? 0);
                $pick_lng = (float) ($v['rest_lng'] ?? 0);
            }
            $punto_ok = yora_coords_ok($pick_lat, $pick_lng);
            $dist = 0.0;
            if ($radio_km != 999) {
                if (!$punto_ok) {
                    continue;
                }
                $dist = yora_sync_distancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
                if ($dist > $radio_km) {
                    continue;
                }
            } elseif ($punto_ok) {
                $dist = yora_sync_distancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
            }
            $v['dist_a_mi'] = $dist;
            $candidatos[] = $v;
        }
    }
    foreach ($candidatos as $v) {
        $item = yora_sync_item_viaje($conexion, $v, $mi_lat, $mi_lng);
        if (($v['dist_a_mi'] ?? 0) > 0) {
            $item['distancia_km'] = round((float) $v['dist_a_mi'], 2);
        }
        $anuncios[] = $item;
        $anuncios_ids[] = (int) $v['id'];
    }
    if (count($anuncios) === 0) {
        $mensaje_radar = 'No hay viajes a ' . $radio_km . ' km de ti.';
    }
}

$finger = sha1(json_encode([
    'e' => $en_linea,
    'a' => $anuncios_ids,
    'r' => array_map(function ($x) { return $x['id'] . ':' . $x['estatus']; }, $revisando),
    'v' => array_map(function ($x) { return $x['id'] . ':' . $x['estatus']; }, $activos),
], JSON_UNESCAPED_UNICODE));

// Versionado Play Store: no tumbar el sync si faltan columnas nuevas en el VPS.
$cfgApp = [];
try {
    $cfgApp = yora_one(
        $conexion,
        'SELECT play_store_url FROM configuracion_web LIMIT 1'
    ) ?: [];
    $extra = @$conexion->query(
        'SELECT driver_min_version_code, driver_latest_version FROM configuracion_web LIMIT 1'
    );
    if ($extra && ($row = $extra->fetch_assoc())) {
        $cfgApp = array_merge($cfgApp, $row);
    }
} catch (Throwable $e) {
    $cfgApp = [];
}

yora_json([
    'ok' => true,
    'en_linea' => $en_linea,
    'verificado' => !empty($docs['verificado']),
    'nombre' => (string) ($cond['nombre'] ?? ''),
    'driver_id' => $conductor_id,
    'billetera' => round((float) ($cond['billetera'] ?? 0), 2),
    'radio_km' => $radio_km,
    'gps_activo' => $tiene_gps ? 1 : 0,
    'gps_fuente' => $gps_fuente,
    'mensaje_radar' => $mensaje_radar,
    'anuncios' => $anuncios,
    'anuncios_ids' => $anuncios_ids,
    'revisando' => $revisando,
    'mis_viajes' => $activos,
    'finger' => $finger,
    'app_min_version_code' => (int) ($cfgApp['driver_min_version_code'] ?? 0),
    'app_latest_version' => (string) ($cfgApp['driver_latest_version'] ?? ''),
    'app_update_url' => trim((string) ($cfgApp['play_store_url'] ?? ''))
        ?: 'https://play.google.com/store/apps/details?id=com.yoradelivery.driver',
], 200);
