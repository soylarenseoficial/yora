<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$conductor_id = yora_require_conductor();
yora_mant_exigir($conexion, 'drivers', $conductor_id);
if (!yora_conductor_sesion_vigente($conexion, $conductor_id)) {
    yora_fail('Tu cuenta se abrió en otro dispositivo. Vuelve a iniciar sesión.', 401);
}
yora_timezone($conexion);

$res_cond = $conexion->query("SELECT * FROM conductores WHERE id = $conductor_id");
$datos_cond = $res_cond->fetch_assoc();
$en_linea = intval($datos_cond['en_linea']);
$billetera = isset($datos_cond['billetera']) ? floatval($datos_cond['billetera']) : 0.00;

$html_anuncios = ''; $html_misviajes = ''; $html_resumen = '';
$trigger_misviajes = false; $anuncios_ids = []; 

function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
    if(($lat1 == $lat2) && ($lon1 == $lon2)) return 0;
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    return (rad2deg(acos($dist)) * 60 * 1.1515 * 1.609344); 
}

function estiloBloque($estilo): string
{
    if (!$estilo) {
        return '';
    }
    return 'border:2px solid ' . yora_h($estilo['borde']) . ';background:' . yora_h($estilo['fondo']) . ';';
}

function chipNivel($estilo): string
{
    if (!$estilo || ($estilo['estrella'] ?? '') === '') {
        return '';
    }
    return '<span class="chip-nivel" style="color:' . yora_h($estilo['color']) . ';background:' . yora_h($estilo['fondo']) . ';border:1px solid ' . yora_h($estilo['borde']) . ';">'
        . yora_h($estilo['nombre']) . ' ' . $estilo['estrella']
        . '</span>';
}

function cajaMandaditoDriver(array $v): string
{
    if (!yora_es_mandadito($v)) {
        return '';
    }
    $quien = trim((string) ($v['cliente_nombre'] ?? ''));
    $que = trim((string) ($v['detalles_entrega'] ?? ''));
    $donde = yora_texto_recogida_comanda($v);
    $html = '<div class="pack-box radar-mandadito">';
    if ($quien !== '') {
        $html .= '<p><b>Solicita:</b> ' . yora_h($quien) . '</p>';
    }
    if ($que !== '') {
        $html .= '<p><b>Qué retirar:</b> ' . yora_h($que) . '</p>';
    }
    if ($donde !== '') {
        $html .= '<p><b>Dónde retirar:</b> ' . yora_h($donde) . '</p>';
    }
    $html .= '</div>';
    return $html;
}

function attrMapaViaje(array $v, $pick_lat, $pick_lng, $lat, $lng, string $texto_recogida, string $direccion_corta, string $estatus = ''): string
{
    return 'data-pick-lat="' . yora_h((string) $pick_lat) . '" data-pick-lng="' . yora_h((string) $pick_lng)
        . '" data-drop-lat="' . yora_h((string) $lat) . '" data-drop-lng="' . yora_h((string) $lng)
        . '" data-lat="' . yora_h((string) $lat) . '" data-lng="' . yora_h((string) $lng)
        . '" data-pick-txt="' . yora_h($texto_recogida) . '" data-drop-txt="' . yora_h($direccion_corta)
        . '" data-estatus="' . yora_h($estatus) . '"';
}

function generarHeaderComercio($v, $estilo = null) {
    $logo = yora_url_archivo($v['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
    $hora_publicacion = date("g:i A", strtotime($v['fecha_creacion']));
    $codigo = !empty($v['codigo']) ? $v['codigo'] : ('ID' . $v['id']);
    $estrella = ($estilo && $estilo['estrella'] !== '')
        ? '<span style="color:' . yora_h($estilo['color']) . '; font-size:0.8rem; margin-left:3px;">' . $estilo['estrella'] . '</span>'
        : '';
    return '
    <div class="ride-head">
        <img src="'.yora_h($logo).'" alt="">
        <div>
            <strong>'.yora_h($v['restaurante']).$estrella.'</strong>
            <span>#'.yora_h($codigo).' · '.$hora_publicacion.'</span>
        </div>
    </div>';
}

$mi_lat = (isset($_GET['lat']) && $_GET['lat'] !== '') ? floatval($_GET['lat']) : null;
$mi_lng = (isset($_GET['lng']) && $_GET['lng'] !== '') ? floatval($_GET['lng']) : null;
$radio_km = isset($_GET['radio']) ? floatval($_GET['radio']) : 3;
$tiene_gps = ($mi_lat !== null && $mi_lng !== null && yora_coords_ok($mi_lat, $mi_lng));
// El GPS lo guarda el latido nativo / latido_gps; no duplicar en cada sync.

// --- PESTAÑA 1: MIS VIAJES ---
$sql_activos = "SELECT c.*, r.nombre AS restaurante, r.direccion AS res_dir, r.logo_url, COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat, COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng, r.telefono AS rest_telefono 
                FROM comandas c LEFT JOIN comercios r ON c.comercio_id = r.id 
                WHERE c.conductor_id = $conductor_id AND c.estatus NOT IN ('Entregado', 'Cancelado') ORDER BY c.id ASC";
$res_activos = $conexion->query($sql_activos);
$num_activos = $res_activos->num_rows;
$esta_revisando = false;
$viajes_finger = [];

if($num_activos > 0) {
    while($v = $res_activos->fetch_assoc()) {
        $viajes_finger[] = (int) $v['id'] . ':' . (string) ($v['estatus'] ?? '');
        $v['codigo'] = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);
        if (trim((string) ($v['restaurante'] ?? '')) === '') {
            $v['restaurante'] = yora_es_mandadito($v) ? ('Mandadito · ' . trim((string) ($v['cliente_nombre'] ?? 'Cliente'))) : 'Pedido Yora';
        }
        $estilo_activo = ((int) ($v['comercio_id'] ?? 0) > 0)
            ? yora_estilo_nivel(yora_nivel_comercio($conexion, (int) $v['comercio_id']))
            : null;
        $ganancia = yora_monto_radar_driver($v); 
        
        $direccion_raw = $v['direccion_entrega'];
        $partes = explode("| GPS", $direccion_raw); 
        $direccion_corta = trim(str_replace(["Destino:", "|", "GPS:"], "", $partes[0])); 
        if(empty($direccion_corta)) { $direccion_corta = "Ubicación en el mapa"; }

        [$lat, $lng] = yora_coords_entrega_comanda($v);
        if (!yora_coords_ok($lat, $lng)) {
            $gps = isset($partes[1]) ? explode(",", str_replace(":", "", $partes[1])) : [0, 0];
            $lat = floatval(trim($gps[0] ?? 0));
            $lng = floatval(trim($gps[1] ?? 0));
        }

        $rest_lat = floatval($v['rest_lat']);
        $rest_lng = floatval($v['rest_lng']);
        $es_mandadito = yora_es_mandadito($v);
        [$pick_lat, $pick_lng] = yora_coords_recogida_comanda($v);
        if (!yora_coords_ok($pick_lat, $pick_lng)) {
            $pick_lat = $rest_lat;
            $pick_lng = $rest_lng;
        }
        $texto_recogida = yora_texto_recogida_comanda($v);
        $etiqueta_tipo = $es_mandadito ? '<span style="background:#fff7ed;color:#9a3412;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:800;margin-left:6px;">Mandadito</span>' : '';
        $attr_mapa = attrMapaViaje($v, $pick_lat, $pick_lng, $lat, $lng, $texto_recogida, $direccion_corta, (string) $v['estatus']);
        $caja_mandadito = cajaMandaditoDriver($v);
        if (yora_es_efectivo_en_mano($v)) {
            $etiqueta_tipo .= '<span style="background:#ecfdf5;color:#047857;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:800;margin-left:4px;">Efectivo</span>';
        }
        
        $detalles_paquete = !empty($v['detalles_entrega']) ? yora_h($v['detalles_entrega']) : 'Sin detalles extra';
        $telf_comercio = !empty($v['rest_telefono']) ? htmlspecialchars($v['rest_telefono']) : 'No registrado';

        $header_comercio = generarHeaderComercio($v, $estilo_activo) . $etiqueta_tipo;

        if($v['estatus'] == 'Revisando') {
            $esta_revisando = true;
            $html_anuncios .= '
            <div class="card-viaje card-eval" style="'.estiloBloque($estilo_activo).'">
                <div class="card-header"><span class="badge-ganancia">$'.number_format($ganancia, 2).'</span><span class="badge-km">'.$v['distancia_km'].' km</span></div>
                '.$header_comercio.'
                <div id="map-eval-'.$v['id'].'" class="mapa-gps mapa-static" '.$attr_mapa.' role="button" tabindex="0"><span>🗺️ Mapa · toca Ver ruta</span></div>
                <button type="button" class="btn-ruta" onclick="abrirRutaViaje(document.getElementById(\'map-eval-'.$v['id'].'\'))">Ver ruta</button>
                '.$caja_mandadito.'
                <ol class="eval-steps">
                    <li>
                        <span class="step-num">1</span>
                        <div>
                            <small>Recoger</small>
                            <strong>'.yora_h($texto_recogida).'</strong>
                            <em>'.yora_h($v['tiempo_estimado']).'</em>
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div>
                            <small>Entregar</small>
                            <strong>'.yora_h($direccion_corta).'</strong>
                        </div>
                    </li>
                </ol>
                <div class="eval-btns">
                    <button type="button" onclick="gestionarViaje('.$v['id'].', \'liberar\')" class="btn-ghost">Soltar</button>
                    <button type="button" onclick="gestionarViaje('.$v['id'].', \'aceptar\')" class="btn-accept">Aceptar viaje</button>
                </div>
            </div>';
        } else {
            $trigger_misviajes = true;
            $btn = '';
            
            // LÓGICA DE CANDADOS GEOFENCING CON SALVAVIDAS GPS
            $libre = (int) ($v['geocerca_libre'] ?? 0) === 1;
            $geo_ok = $libre ? 1 : 0;
            if($v['estatus'] == 'En Camino a Comercio') {
                if($libre) {
                    $btn = '<button onclick="cambiarEstatus('.$v['id'].', \'En Camino a Cliente\')" class="btn-main btn-blue">📸 Tomar Foto y Recoger</button>';
                } elseif($mi_lat && $mi_lng && $pick_lat) {
                    $dist_comercio = calcularDistancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
                    if($dist_comercio <= 1.0) {
                      $geo_ok = 1;
                      $btn = '<button onclick="cambiarEstatus('.$v['id'].', \'En Camino a Cliente\')" class="btn-main btn-blue">📸 Tomar Foto y Recoger</button>';
                    } else {
                        $btn = '<button disabled class="btn-main" style="background:#9ca3af; cursor:not-allowed;">🔒 Acércate al punto de recogida</button>';
                    }
                } else {
                    $btn = '<button disabled class="btn-main" style="background:#9ca3af; cursor:not-allowed;">📍 Activa el GPS para recoger</button>';
                }
            } else {
                if($libre) {
                    $btn = '<button onclick="cambiarEstatus('.$v['id'].', \'Entregado\')" class="btn-main btn-green">📸 Tomar Foto y Entregar</button>';
                } elseif($mi_lat && $mi_lng && $lat) {
                    $dist_cliente = calcularDistancia($mi_lat, $mi_lng, $lat, $lng);
                    if($dist_cliente <= 1.0) {
                     $geo_ok = 1;
                     $btn = '<button onclick="cambiarEstatus('.$v['id'].', \'Entregado\')" class="btn-main btn-green">📸 Tomar Foto y Entregar</button>';
                    } else {
                        $btn = '<button disabled class="btn-main" style="background:#9ca3af; cursor:not-allowed;">🔒 Acércate al punto de entrega</button>';
                    }
                } else {
                    $btn = '<button disabled class="btn-main" style="background:#9ca3af; cursor:not-allowed;">📍 Activa el GPS para entregar</button>';
                }
            }
            $viajes_finger[count($viajes_finger) - 1] = (int) $v['id'] . ':' . (string) ($v['estatus'] ?? '') . ':g' . $geo_ok;
            
            $btn_llamar_cliente = '<a href="tel:'.htmlspecialchars($v['cliente_telefono']).'" class="call-cli">Cliente</a>';
            $btn_llamar_comercio = $es_mandadito
                ? ''
                : '<a href="tel:'.$telf_comercio.'" class="call-loc">Local</a>';

            $html_misviajes .= '
            <div class="card-viaje active-card" style="'.estiloBloque($estilo_activo).'">
                <div class="card-header"><span class="badge-ganancia">$'.number_format($ganancia, 2).'</span><span class="badge-km">'.$v['distancia_km'].' km</span></div>
                '.$header_comercio.'
                <div id="map-curso-'.$v['id'].'" class="mapa-gps mapa-vivo mapa-static" '.$attr_mapa.' role="button" tabindex="0"><span>🗺️ En vivo · toca Ver ruta</span></div>
                <button type="button" class="btn-ruta" onclick="abrirRutaViaje(document.getElementById(\'map-curso-'.$v['id'].'\'))">Ver ruta</button>
                <ol class="eval-steps">
                    <li>
                        <span class="step-num">1</span>
                        <div>
                            <small>Recoger</small>
                            <strong>'.yora_h($texto_recogida).'</strong>
                            <em>'.yora_h($v['tiempo_estimado']).'</em>
                        </div>
                    </li>
                    <li>
                        <span class="step-num">2</span>
                        <div>
                            <small>Entregar</small>
                            <strong>'.yora_h($direccion_corta).'</strong>
                        </div>
                    </li>
                </ol>
                <div class="pack-box">
                    '.($es_mandadito ? '<p><b>Solicita:</b> '.yora_h($v['cliente_nombre']).'</p>' : '<p><b>'.htmlspecialchars($v['cliente_nombre']).'</b></p>').'
                    <p><b>'.($es_mandadito ? 'Qué retirar:' : 'Lleva:').'</b> '.$detalles_paquete.'</p>
                    <p><b>Retiro:</b> '.yora_h($texto_recogida).'</p>
                    <div class="call-row">'.$btn_llamar_cliente . $btn_llamar_comercio.'</div>
                </div>
                <div>'.$btn.'</div>
                <div class="card-footer">'.yora_h($v['estatus']).'</div>
            </div>';
        }
    }
}
if(!$trigger_misviajes) { $html_misviajes = '<div class="empty-state">No tienes viajes en curso.</div>'; }

// --- PESTAÑA 2: RADAR ---
if($en_linea === 0) {
    $html_anuncios = '<div class="empty-state" style="margin-top:60px;"><span style="font-size:3.5rem; display:block; margin-bottom:15px;">😴</span><h3 style="color:#1f2937; margin-bottom:5px;">Estás Desconectado</h3><p style="color:#6b7280; font-size:0.9rem;">Ponte Online para recibir viajes.</p></div>';
} else {
    if(!$esta_revisando && $num_activos === 0) {
        if ($radio_km != 999 && !$tiene_gps) {
            $html_anuncios = '<div class="empty-state" style="margin-top:50px;"><span style="font-size: 2.5rem; display:inline-block; animation: pulse 2s infinite;">📡</span><p style="margin-top:15px;">Activando tu GPS...<br><span style="font-size:0.85rem; color:#64748b;">El rango se mide hasta el <b>punto de recogida</b>.</span></p></div>';
        } else {
        $sql_radar = "SELECT c.*, r.nombre AS restaurante, r.direccion AS res_dir, r.logo_url, COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat, COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng 
                      FROM comandas c LEFT JOIN comercios r ON c.comercio_id = r.id 
                      WHERE c.estatus = 'Buscando Conductor' ORDER BY c.id ASC";
        $res_radar = $conexion->query($sql_radar);
        $candidatos = [];
        if ($res_radar && $res_radar->num_rows > 0) {
            while ($v = $res_radar->fetch_assoc()) {
                $v['dist_a_mi'] = 0;
                [$pick_lat, $pick_lng] = yora_coords_recogida_comanda($v);
                if (!yora_coords_ok($pick_lat, $pick_lng)) {
                    $pick_lat = (float) ($v['rest_lat'] ?? 0);
                    $pick_lng = (float) ($v['rest_lng'] ?? 0);
                }
                $punto_ok = yora_coords_ok($pick_lat, $pick_lng);
                if ($radio_km != 999) {
                    if (!$punto_ok) {
                        continue;
                    }
                    $v['dist_a_mi'] = calcularDistancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
                    if ($v['dist_a_mi'] > $radio_km) {
                        continue;
                    }
                } elseif ($tiene_gps && $punto_ok) {
                    $v['dist_a_mi'] = calcularDistancia($mi_lat, $mi_lng, $pick_lat, $pick_lng);
                }
                $v['codigo'] = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);
                $candidatos[] = $v;
            }
        }

        $para_ganga = [];
        foreach ($candidatos as $cand) {
            if (!yora_es_mandadito($cand)) {
                $para_ganga[] = $cand;
            }
        }
        $pares = yora_pares_ganga($para_ganga, 1.0);
        $en_ganga = [];
        foreach ($pares as $par) {
            $en_ganga[(int) $par[0]['id']] = true;
            $en_ganga[(int) $par[1]['id']] = true;
        }

        $anuncios_mostrados = 0;

        foreach ($pares as $par) {
            [$a, $b] = $par;
            $estilo = yora_estilo_nivel(yora_nivel_comercio($conexion, (int) $a['comercio_id']));
            $total = ((float) $a['costo_delivery'] + (float) $b['costo_delivery']) * 0.80;
            $anuncios_ids[] = (int) $a['id'];
            $anuncios_ids[] = (int) $b['id'];
            $anuncios_mostrados++;
            $dir_a = trim(explode('|', $a['direccion_entrega'])[0]);
            $dir_b = trim(explode('|', $b['direccion_entrega'])[0]);

            $logo_lote = yora_url_archivo($a['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
            $hora_a = date('H:i', strtotime($a['fecha_creacion']));
            $hora_b = date('H:i', strtotime($b['fecha_creacion']));
            $html_anuncios .= '
            <div class="lote-wrap lote-nivel" style="'.estiloBloque($estilo).'">
                <div class="lote-nivel-tag">'.chipNivel($estilo).'</div>
                <div class="lote-mini">
                    <div class="lote-mini-top">
                        <img src="'.yora_h($logo_lote).'" alt="">
                        <div class="lote-mini-meta">
                            <strong>'.yora_h($a['restaurante']).'</strong>
                            <span>'.yora_h($a['res_dir']).'</span>
                        </div>
                        <em>'.$hora_a.'</em>
                    </div>
                    <div class="lote-id">#'.yora_h($a['codigo']).'</div>
                    <div class="lote-dest">'.yora_h($dir_a).'</div>
                </div>
                <div class="lote-pill">ENTREGA DOBLE · $'.number_format($total, 2).' · 2 pedidos</div>
                <div class="lote-mini">
                    <div class="lote-mini-top">
                        <img src="'.yora_h($logo_lote).'" alt="">
                        <div class="lote-mini-meta">
                            <strong>'.yora_h($b['restaurante']).'</strong>
                            <span>'.yora_h($b['res_dir']).'</span>
                        </div>
                        <em>'.$hora_b.'</em>
                    </div>
                    <div class="lote-id">#'.yora_h($b['codigo']).'</div>
                    <div class="lote-dest">'.yora_h($dir_b).'</div>
                </div>
                <button type="button" class="lote-btn" onclick="gestionarLote('.(int)$a['id'].', '.(int)$b['id'].')">TOMAR LOS DOS</button>
            </div>';
        }

        foreach ($candidatos as $v) {
            if (isset($en_ganga[(int) $v['id']])) {
                continue;
            }
            $anuncios_mostrados++;
            $ganancia = yora_monto_radar_driver($v);
            $anuncios_ids[] = $v['id'];
            $ifRid = (int) ($v['comercio_id'] ?? 0);
            $estilo = $ifRid > 0 ? yora_estilo_nivel(yora_nivel_comercio($conexion, $ifRid)) : null;
            if (trim((string) ($v['restaurante'] ?? '')) === '') {
                $v['restaurante'] = yora_es_mandadito($v) ? ('Mandadito · ' . trim((string) ($v['cliente_nombre'] ?? 'Cliente'))) : 'Pedido Yora';
            }
            $header_comercio = generarHeaderComercio($v, $estilo);
            if (yora_es_mandadito($v)) {
                $header_comercio .= '<span style="background:#fff7ed;color:#9a3412;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:800;">Mandadito</span>';
            }
            if (yora_es_efectivo_en_mano($v)) {
                $header_comercio .= '<span style="background:#ecfdf5;color:#047857;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:800;margin-left:4px;">Efectivo</span>';
            }
            $badge_cerca = ($v['dist_a_mi'] > 0)
                ? ('A ' . number_format(round((float) $v['dist_a_mi'], 1), 1) . ' km')
                : (number_format((float) $v['distancia_km'], 1) . ' km');

            $html_anuncios .= '
            <div class="card-viaje card-radar" style="'.estiloBloque($estilo).'">
                <div class="radar-top">
                    '.$header_comercio.'
                    <div class="radar-pay">
                        '.chipNivel($estilo).'
                        <strong>$'.number_format($ganancia, 2).'</strong>
                    </div>
                </div>
                <div class="radar-lines">
                    <div class="radar-line"><span class="dot pick"></span><span>'.yora_h(yora_texto_recogida_comanda($v)).'</span></div>
                    <div class="radar-line"><span class="dot drop"></span><span>'.yora_h(yora_texto_destino($v['direccion_entrega'])).'</span></div>
                </div>
                '.cajaMandaditoDriver($v).'
                <div class="radar-foot">
                    <span class="chip">'.yora_h($badge_cerca).'</span>
                    <span class="chip chip-ok">'.yora_h($v['tiempo_estimado']).'</span>
                    <button type="button" onclick="gestionarViaje('.$v['id'].', \'bloquear\')" class="btn-go">GO</button>
                </div>
            </div>';
        }

        if ($anuncios_mostrados === 0) {
            $html_anuncios = '<div class="empty-state" style="margin-top:50px;"><span style="font-size: 2.5rem; display:inline-block; animation: pulse 2s infinite;">📡</span><p style="margin-top:15px;">No hay viajes de comercios a <b>'.$radio_km.' km</b> de ti.<br>Intenta ampliar tu cobertura.</p></div>';
        }
        }
    } elseif ($num_activos >= 1 && !$esta_revisando) {
        $html_anuncios = '<div class="empty-state" style="color:#e4441b;">⚠️ Termina el viaje actual para ver más pedidos.</div>';
    }
}

// --- PESTAÑA 3: FINANZAS (ligera por defecto; historial solo si ?hist=1) ---
$res_stats = $conexion->query("SELECT COUNT(id) as total, SUM(distancia_km) as km FROM comandas WHERE conductor_id = $conductor_id AND estatus = 'Entregado' AND pagado_al_driver = 0");
$stats = $res_stats->fetch_assoc();

$min_retiro = (!empty($datos_cond['categoria']) && $datos_cond['categoria'] == 'Pro') ? 5.00 : 20.00;
$btn_retiro = ($billetera >= $min_retiro) ? '<button onclick="solicitarRetiro()" class="btn-main btn-green" style="margin-top:15px; padding:12px; font-size:0.9rem;">💸 Retirar Dinero Hoy</button>' : '<p style="font-size:0.8rem; color:#9ca3af; margin-top:10px;">Mínimo para retirar: $'.number_format($min_retiro, 2).'</p>';

$html_resumen = '
<div style="background: linear-gradient(135deg, #1f2937, #111827); border-radius:20px; padding:25px; color:white; margin-bottom:20px; text-align:center; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
    <p style="color:#9ca3af; margin-bottom:5px; font-weight:600;">Saldo Disponible</p>
    <h1 style="font-size:3rem; margin:0; color:#e4441b;">$'.number_format($billetera, 2).'</h1>
    '.$btn_retiro.'
</div>
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-box"><span class="icon">📦</span><h2>'.intval($stats['total']).'</h2><p>Viajes Actuales</p></div>
    <div class="stat-box"><span class="icon">📍</span><h2>'.number_format(floatval($stats['km']), 1).'</h2><p>Km Actual</p></div>
</div><h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px;">Historial de Ingresos</h3>';

$incluir_hist = (string) ($_GET['hist'] ?? '') === '1';
if ($incluir_hist) {
$fecha_filtro = yora_valid_date($_GET['fecha'] ?? null);
$sql_hist = "SELECT c.*, COALESCE(r.nombre, c.cliente_nombre) AS restaurante FROM comandas c LEFT JOIN comercios r ON c.comercio_id = r.id WHERE c.conductor_id = $conductor_id AND c.estatus = 'Entregado'";
if ($fecha_filtro) {
    $sql_hist .= " AND DATE(COALESCE(c.fecha_entrega, c.fecha_creacion)) = '" . $conexion->real_escape_string($fecha_filtro) . "'";
}
$sql_hist .= " ORDER BY c.id DESC LIMIT 40";
$res_historial = $conexion->query($sql_hist);
$n_hist = 0;
while($v = $res_historial->fetch_assoc()) {
    $n_hist++;
    $codigo_f = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);
    $impacto = yora_impacto_billetera_driver($v);
    $ganancia_mostrar = yora_monto_radar_driver($v);
    if (abs($impacto) > 0.001) {
        $ganancia_mostrar = abs($impacto);
    }
    $total_viaje = (float) ($v['costo_delivery'] ?? 0);
    $txt_monto = ($impacto < -0.001 ? '-' : '+') . '$' . number_format($ganancia_mostrar, 2);
    if (yora_es_efectivo_en_mano($v)) {
        $estado_pago = '<span style="color:#047857; font-size:0.7rem; font-weight:bold;">💵 Efectivo · comisión Yora</span>';
    } else {
        $estado_pago = ($v['pagado_al_driver'] == 1) ? '<span style="color:#10b981; font-size:0.7rem; font-weight:bold;">✅ Liquidado por Yora</span>' : '<span style="color:#d97706; font-size:0.7rem; font-weight:bold;">⏳ Por Cobrar (En Billetera)</span>';
    }
    $payload_f = [
        'id' => $codigo_f,
        'fecha' => date('d/m/Y h:i A', strtotime($v['fecha_entrega'] ?: $v['fecha_creacion'])),
        'cliente' => (string) ($v['cliente_nombre'] ?? ''),
        'detalles' => yora_texto_destino($v['direccion_entrega'] ?? ''),
        'conductor' => 'Tú',
        'total' => number_format($total_viaje, 2, '.', ''),
        'ganancia' => number_format($ganancia_mostrar, 2, '.', ''),
        'costo' => number_format($ganancia_mostrar, 2, '.', ''),
    ];
    $data_f = htmlspecialchars(json_encode($payload_f, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $html_resumen .= '
    <div class="factura-item" style="cursor:pointer;" onclick="abrirModalFactura('.$data_f.')">
        <div class="factura-icon" style="background:#ffedd5; color:#e4441b;"><i class="ph ph-receipt"></i></div>
        <div class="factura-info"><h4>'.yora_h($v['restaurante']).'</h4><p>'.$txt_monto.' • #'.yora_h($codigo_f).'</p>'.$estado_pago.'</div>
    </div>';
}
if ($n_hist === 0) {
    $html_resumen .= '<p style="text-align:center; color:#94a3b8; font-size:0.85rem; padding:20px 0;">No hay facturas en esa fecha.</p>';
}
} else {
    $html_resumen .= '<p style="text-align:center; color:#94a3b8; font-size:0.85rem; padding:12px 0;">Abre Billetera para ver el historial.</p>';
}

$finger = sha1(json_encode([
    'e' => (int) $en_linea,
    'a' => $anuncios_ids,
    'v' => $viajes_finger,
    'b' => round((float) $billetera, 2),
    'r' => (float) $radio_km,
    'rev' => $esta_revisando ? 1 : 0,
], JSON_UNESCAPED_UNICODE));

echo json_encode([
    'anuncios' => $html_anuncios,
    'misviajes' => $html_misviajes,
    'resumen' => $html_resumen,
    'trigger_misviajes' => $trigger_misviajes,
    'anuncios_ids' => $anuncios_ids,
    'estado_actual' => $en_linea,
    'finger' => $finger,
], JSON_UNESCAPED_UNICODE);
$conexion->close();
?>