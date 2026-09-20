<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['conductor_id'])) {
    header('Location: index.php');
    exit;
}
$conductor_id = (int) $_SESSION['conductor_id'];

yora_mant_exigir($conexion, 'drivers', $conductor_id);

// Sesion unica: si entro en otro telefono, esta pantalla ya no vale.
if (!yora_conductor_sesion_vigente($conexion, $conductor_id)) {
    yora_logout('index.php?sesion=duplicada');
}

yora_timezone($conexion);

// 1. Obtener datos del conductor
$res = $conexion->query("SELECT * FROM conductores WHERE id = $conductor_id");
$conductor = $res->fetch_assoc();

// 2. Estado real del expediente: alimenta el aviso de verificación del radar.
//    El perfil, el Club Yora y los ajustes viven ahora en sus propias
//    pantallas (ajustes.php y compañía), no dentro de este dashboard.
$docs = yora_docs_estado($conductor);

// Sin verificar no se opera: si venía marcado En Línea de antes, lo bajamos.
if (!$docs['verificado'] && (int) ($conductor['en_linea'] ?? 0) === 1) {
    yora_exec($conexion, 'UPDATE conductores SET en_linea = 0 WHERE id = ?', 'i', $conductor_id);
    $conductor['en_linea'] = 0;
}

// Banners HQ → app (modal diferido; sin CREATE TABLE en cada apertura).
$banners_driver = [];
try {
    $banners_driver = yora_all(
        $conexion,
        "SELECT id, titulo, cuerpo, imagen_url, boton_texto, boton_url, orden
         FROM banners_app
         WHERE activo = 1 AND audiencia IN ('drivers','todos')
         ORDER BY orden ASC, id DESC
         LIMIT 8"
    );
} catch (Throwable $e) {
    $banners_driver = [];
}
$banners_json = [];
foreach ($banners_driver as $bn) {
    $img = trim((string) ($bn['imagen_url'] ?? ''));
    if ($img !== '' && !preg_match('#^https?://#i', $img)) {
        $img = 'https://yoradelivery.com/' . ltrim($img, '/');
    }
    $url = trim((string) ($bn['boton_url'] ?? ''));
    $banners_json[] = [
        'id' => (int) $bn['id'],
        'titulo' => (string) ($bn['titulo'] ?? ''),
        'cuerpo' => (string) ($bn['cuerpo'] ?? ''),
        'imagen' => $img,
        'boton' => (string) ($bn['boton_texto'] ?? ''),
        'url' => $url,
    ];
}

$conexion->close();

// Pestaña con la que se abre la app. Las pantallas de ajustes vuelven aquí
// con ?tab=... para dejar al conductor donde estaba.
$tab_inicial = (string) ($_GET['tab'] ?? 'anuncios');
if ($tab_inicial === 'resumen') {
    header('Location: billetera.php');
    exit;
}
if (!in_array($tab_inicial, ['anuncios', 'misviajes'], true)) {
    $tab_inicial = 'anuncios';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>YoraDriver | App</title>
    <link rel="manifest" href="/manifest.json?v=10">
    <!-- Sin Google Fonts / Phosphor / Leaflet al abrir: arranque liviano en el teléfono -->
    
    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;}
        body { background-color: #f6f7f8; color: #111827; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        #splash-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: #ffffff; z-index: 9999;
            display: flex; justify-content: center; align-items: center;
            transition: opacity 0.6s ease-out;
        }
        #splash-screen img { width: 140px; animation: pulse-splash 1.5s infinite ease-in-out; }
        @keyframes pulse-splash {
            0% { transform: scale(0.9); opacity: 0.8; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(0.9); opacity: 0.8; }
        }
        .hide-splash { opacity: 0; pointer-events: none; }

        .header { background: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; z-index: 10;}
        .header .logo img { height: 30px; max-width: 160px; object-fit: contain; vertical-align: middle; }
        .toggle-btn { padding: 5px 12px; border-radius: 999px; font-weight: 700; font-size: 0.78rem; cursor: pointer; transition: 0.2s; border: 1px solid transparent;}
        .toggle-btn.online { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .toggle-btn.offline { background: #fff; color: #6b7280; border-color: #e5e7eb; }

        .tab-content { flex: 1; overflow-y: auto; padding: 12px 14px; padding-bottom: 118px; display: none; }
        .tab-content.active { display: block; }

        .card-viaje { background: #fff; border-radius: 18px; padding: 14px; margin-bottom: 12px; border: 1px solid #ececec; box-shadow: none; }
        .active-card { border-color: #e5e7eb; background: #fff; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .badge-ganancia { background: #fff7ed; color: #c2410c; padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 0.88rem;}
        .badge-km { background: #f4f4f5; color: #52525b; padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 0.75rem;}
        .card-body { display: flex; justify-content: space-between; align-items: center; }
        .card-body .info { flex: 1; padding-right: 10px;}
        .card-body h4 { font-size: 1rem; margin-bottom: 4px; color: #111827; text-transform: uppercase;}
        .card-body p { font-size: 0.78rem; color: #52525b; margin-bottom: 3px; line-height:1.35;}
        .card-body hr { border: 0; border-top: 1px dashed #e5e7eb; margin: 8px 0;}
        .card-footer { margin-top: 12px; text-align: center; font-size: 0.75rem; padding-top: 10px; border-top: 1px solid #f1f1f1; color: #71717a; }

        .ride-head { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
        .ride-head img { width:36px; height:36px; border-radius:10px; object-fit:cover; background:#f4f4f5; }
        .ride-head strong { display:block; font-size:0.88rem; color:#111827; text-transform:uppercase; line-height:1.2; }
        .ride-head span { font-size:0.72rem; color:#71717a; }

        .card-radar .radar-top { display:flex; align-items:flex-start; gap:8px; margin-bottom:2px; }
        .card-radar .radar-top .ride-head { flex:1; margin-bottom:8px; }
        .card-radar .radar-pay { font-size:1.05rem; font-weight:800; color:#c2410c; white-space:nowrap; padding-top:2px; text-align:right; }
        .card-radar .radar-pay strong { display:block; }
        .chip-nivel { display:inline-block; font-size:0.62rem; font-weight:800; padding:2px 7px; border-radius:999px; letter-spacing:.2px; line-height:1.3; }
        .lote-wrap.lote-nivel { padding:10px; border-radius:20px; }
        .lote-nivel-tag { margin:0 0 8px; text-align:right; }
        .card-radar .radar-lines { margin: 2px 0 12px; }
        .radar-line { display:flex; align-items:flex-start; gap:8px; font-size:0.78rem; color:#3f3f46; line-height:1.35; margin-bottom:6px; }
        .radar-line .dot { width:8px; height:8px; border-radius:50%; margin-top:5px; flex-shrink:0; }
        .radar-line .dot.pick { background:#e4441b; }
        .radar-line .dot.drop { background:#a1a1aa; }
        .radar-foot { display:flex; align-items:center; gap:8px; }
        .chip { background:#f4f4f5; color:#3f3f46; font-size:0.7rem; font-weight:700; padding:5px 9px; border-radius:999px; }
        .chip-ok { background:#fff7ed; color:#c2410c; }
        .radar-foot .btn-go { margin-left:auto; }

        .card-eval { padding: 14px; }
        .eval-steps { list-style:none; margin: 0 0 14px; padding:0; }
        .eval-steps li { display:flex; gap:10px; margin-bottom:12px; }
        .eval-steps li:last-child { margin-bottom:0; }
        .step-num { width:22px; height:22px; border-radius:50%; background:#111827; color:#fff; font-size:0.7rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }
        .eval-steps small { display:block; font-size:0.68rem; font-weight:700; color:#a1a1aa; text-transform:uppercase; letter-spacing:.3px; }
        .eval-steps strong { display:block; font-size:0.84rem; font-weight:600; color:#18181b; line-height:1.3; }
        .eval-steps em { display:inline-block; margin-top:4px; font-style:normal; font-size:0.72rem; font-weight:700; color:#c2410c; background:#fff7ed; padding:3px 8px; border-radius:8px; }
        .eval-btns { display:flex; gap:8px; }
        .btn-ghost { flex:1; background:#fff; color:#52525b; border:1px solid #e4e4e7; padding:13px; border-radius:14px; font-weight:700; font-size:0.92rem; cursor:pointer; }
        .btn-accept { flex:1.6; background:#e4441b; color:#fff; border:none; padding:13px; border-radius:14px; font-weight:800; font-size:0.92rem; cursor:pointer; }
        .pack-box { background:#fafafa; border:1px solid #f1f1f1; padding:10px 12px; border-radius:12px; margin: 8px 0 12px; }
        .pack-box p { margin:0 0 4px; font-size:0.78rem; color:#3f3f46; }
        .call-row { display:flex; gap:8px; margin-top:8px; }
        .call-row a { flex:1; text-align:center; padding:8px; border-radius:10px; text-decoration:none; font-weight:700; font-size:0.75rem; }
        .call-cli { background:#fff7ed; color:#c2410c; }
        .call-loc { background:#f4f4f5; color:#3f3f46; }
        
        .mapa-gps { height: 168px; width: 100%; border-radius: 14px; background: #ececec; margin-bottom: 10px; border: none; z-index: 1; cursor: pointer;}
        .mapa-gps:active { opacity: 0.85; }
        .mapa-gps.mapa-vivo { height: 210px; }
        .mapa-gps.mapa-static {
            display:flex; align-items:center; justify-content:center;
            background: linear-gradient(145deg, #e2e8f0 0%, #f8fafc 55%, #dbeafe 100%);
            color:#334155; font-weight:700; font-size:0.88rem;
        }
        .mapa-gps.mapa-static span { pointer-events:none; }
        .btn-ruta { width:100%; border:none; background:#111827; color:#fff; font-weight:800; font-size:0.82rem; padding:11px; border-radius:12px; margin-bottom:12px; cursor:pointer; }
        .radar-mandadito { background:#fff7ed; border:1px solid #fed7aa; }
        .radar-mandadito p { margin:0 0 4px; font-size:0.78rem; color:#9a3412; }
        /* GPS sigue activo en JS; oculto para el conductor */
        .gps-vivo { display:none !important; }

        /* —— Navegación in-app (estilo Google Maps) —— */
        #nav-viaje {
            display:none; position:fixed; inset:0; background:#e8eef5; z-index:12000;
            flex-direction:column; overflow:hidden;
        }
        #nav-viaje .nav-map-wrap {
            position:relative; flex:1; min-height:0; overflow:hidden;
            background:#e8eef5;
        }
        #nav-mapa { position:absolute; inset:0; transform-origin: center 68%; transition: transform .35s ease; }
        #nav-viaje.nav-mode #nav-mapa {
            transform: perspective(1000px) rotateX(26deg) scale(1.14);
            bottom: -4%;
        }
        #nav-viaje.nav-mode #nav-mapa .leaflet-control-zoom { display:none; }

        .nav-hud-top {
            position:absolute; left:12px; right:12px; top:max(10px, env(safe-area-inset-top));
            z-index:8; pointer-events:none; display:flex; flex-direction:column; gap:8px;
        }
        .nav-hud-top > * { pointer-events:auto; }
        #nav-viaje:not(.nav-mode) .nav-hud-top { display:none; }

        #nav-instruccion {
            display:flex; align-items:center; gap:14px;
            background:#0f9d58; color:#fff; border-radius:16px;
            padding:14px 16px; box-shadow:0 10px 28px rgba(0,0,0,.22);
            font-size:1.2rem; font-weight:800; line-height:1.25;
        }
        #nav-instruccion .nav-arrow {
            flex-shrink:0; width:48px; height:48px; border-radius:12px;
            background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center;
            font-size:1.7rem; font-weight:900;
        }
        #nav-instruccion .nav-arrow-txt { flex:1; min-width:0; }
        #nav-instruccion small {
            display:block; margin-top:4px; font-weight:700;
            color:rgba(255,255,255,.88); font-size:0.9rem;
        }
        #nav-luego {
            display:none; align-self:flex-start; background:#0f9d58; color:#fff;
            border-radius:12px; padding:8px 12px; font-size:0.78rem; font-weight:800;
            box-shadow:0 6px 16px rgba(0,0,0,.18); gap:8px; align-items:center;
        }
        #nav-luego.on { display:inline-flex; }
        #nav-luego .nav-arrow-mini { font-size:1rem; }

        .nav-side-btns {
            position:absolute; right:12px; top:42%;
            z-index:7; display:none; flex-direction:column; gap:10px;
        }
        #nav-viaje.nav-mode .nav-side-btns { display:flex; }
        .nav-side-btns button {
            width:46px; height:46px; border:0; border-radius:50%;
            background:#fff; color:#1a73e8; font-size:1.15rem; cursor:pointer;
            box-shadow:0 4px 14px rgba(0,0,0,.18);
        }

        .nav-speed {
            display:none; position:absolute; left:14px; bottom:108px; z-index:7;
            width:64px; height:64px; border-radius:50%; background:#fff; color:#0f172a;
            border:3px solid #fff; box-shadow:0 6px 18px rgba(0,0,0,.2);
            flex-direction:column; align-items:center; justify-content:center;
            font-weight:900; line-height:1;
        }
        #nav-viaje.nav-mode .nav-speed { display:flex; }
        .nav-speed b { font-size:1.15rem; color:#0f172a; }
        .nav-speed span { font-size:0.55rem; color:#64748b; margin-top:2px; letter-spacing:.3px; }

        .nav-dock {
            display:none; position:absolute; left:10px; right:10px; bottom:max(10px, env(safe-area-inset-bottom));
            z-index:8; background:#fff; color:#0f172a;
            padding:12px 14px; border-radius:22px;
            align-items:center; gap:12px;
            box-shadow:0 10px 30px rgba(0,0,0,.2);
        }
        #nav-viaje.nav-mode .nav-dock { display:flex; }
        .nav-dock .nav-x {
            width:44px; height:44px; border:0; border-radius:50%;
            background:#f1f5f9; color:#0f172a; font-size:1.15rem; font-weight:800; cursor:pointer;
        }
        .nav-dock .nav-eta { flex:1; text-align:center; }
        .nav-dock .nav-eta strong {
            display:block; font-size:1.55rem; font-weight:900; color:#0f9d58; letter-spacing:-.5px;
        }
        .nav-dock .nav-eta small { color:#64748b; font-size:0.82rem; font-weight:600; }
        .nav-dock .nav-recenter {
            width:44px; height:44px; border:0; border-radius:50%;
            background:#1a73e8; color:#fff; font-size:1.1rem; cursor:pointer;
        }

        #nav-viaje .nav-preview {
            position:absolute; left:0; right:0; bottom:0; z-index:8;
            background:#fff; border-radius:20px 20px 0 0;
            padding:14px 16px calc(16px + env(safe-area-inset-bottom));
            box-shadow:0 -8px 30px rgba(0,0,0,.18);
        }
        #nav-viaje.nav-mode .nav-preview { display:none; }
        .nav-preview .nav-preview-head {
            display:flex; align-items:center; gap:10px; margin-bottom:10px;
        }
        .nav-preview .nav-preview-head button {
            border:0; background:#f1f5f9; width:40px; height:40px; border-radius:50%;
            font-size:1.1rem; cursor:pointer; color:#0f172a;
        }
        .nav-preview .nav-preview-head h3 { margin:0; flex:1; font-size:1.05rem; color:#0f172a; font-weight:800; }
        .nav-preview .nav-preview-head h3 span { display:block; font-size:0.78rem; color:#64748b; font-weight:600; margin-top:2px; }
        .nav-leg { display:flex; gap:10px; align-items:flex-start; margin-bottom:8px; font-size:0.8rem; color:#3f3f46; }
        .nav-leg i { width:18px; height:18px; border-radius:50%; display:inline-block; margin-top:2px; flex-shrink:0; }
        .nav-leg .yo { background:#1a73e8; }
        .nav-leg .pick { background:#0f9d58; }
        .nav-leg .drop { background:#ea4335; }
        #nav-btn-go {
            margin-top:10px; width:100%; border:0; border-radius:28px; padding:14px 18px;
            background:#1a73e8; color:#fff; font-weight:800; font-size:1rem;
            display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer;
            box-shadow:0 4px 14px rgba(26,115,232,.35);
        }
        #nav-btn-go::before { content:'➤'; font-size:0.95rem; }
        .leaflet-tooltip.nav-tip {
            background:#fff; color:#0f172a; border:0; border-radius:8px;
            font-weight:700; font-size:0.72rem; padding:4px 8px; box-shadow:0 2px 8px rgba(0,0,0,.18);
        }
        .leaflet-tooltip.nav-tip::before { border-top-color:#fff; }

        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-box { background: white; padding: 16px; border-radius: 16px; text-align: center; border: 1px solid #ececec;}
        .stat-box .icon { font-size: 1.5rem; display:block; margin-bottom: 5px;}
        .stat-box h2 { font-size: 1.5rem; color: #1f2937; margin: 0; }
        .stat-box p { font-size: 0.75rem; color: #9ca3af; margin: 0; text-transform: uppercase; letter-spacing:0.5px;}

        .factura-item { background: white; padding: 12px 14px; border-radius: 14px; display: flex; align-items: center; margin-bottom: 8px; border: 1px solid #ececec;}
        .factura-icon { background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 10px; font-weight: 800; font-size:0.7rem; margin-right: 15px;}
        .factura-info h4 { font-size: 0.95rem; margin:0;}
        .factura-info p { font-size: 0.8rem; color: #9ca3af; margin:0;}

        .bottom-nav { 
            background: #fff;
            display: flex; justify-content: space-around; padding: 10px 4px 16px; 
            position: fixed; bottom: 0; width: 100%; border-top: 1px solid #eee; z-index: 100; 
        }
        .nav-item { 
            text-align: center; color: #9ca3af; font-size: 0.65rem; font-weight: 600; 
            flex: 1; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px;
            transition: all 0.2s ease;
        }
        .nav-item.active { color: #e4441b; }
        .nav-item i, .nav-item .nav-ico { font-size: 1.35rem; line-height: 1; transition: transform 0.2s; display:block; }
        .nav-item.active i, .nav-item.active .nav-ico { transform: translateY(-2px); }
        
        .empty-state { text-align: center; color: #9ca3af; font-weight: 600; margin-top: 40px; }

        .btn-go {
            background: #111827;
            color: #fff; border: none; min-width: 52px; height: 40px; padding: 0 14px; border-radius: 12px;
            font-weight: 800; font-size: 0.78rem; letter-spacing: .3px; cursor: pointer;
            box-shadow: none; transition: transform 0.15s ease;
            display: flex; align-items: center; justify-content: center;
        }
        .btn-go:active { transform: scale(0.96); }
        
        .btn-main {
            width: 100%; border: none; padding: 14px; border-radius: 14px; font-weight: 700; font-size: 0.95rem;
            cursor: pointer; color: white; transition: transform 0.15s ease;
            box-shadow: none; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-main:active { transform: scale(0.98); }
        .btn-green { background: #e4441b; }
        .btn-blue { background: #111827; }
        .btn-light { background: #fff; color: #52525b; border: 1px solid #e4e4e7; }
        .btn-light:active { background: #f4f4f5; }

        .lote-wrap { display:flex; flex-direction:column; gap:0; margin-bottom:14px; }
        .lote-mini { background:#ecfdf5; border-radius:16px; padding:12px 14px; }
        .lote-mini-top { display:flex; align-items:flex-start; gap:10px; }
        .lote-mini-top img { width:32px; height:32px; border-radius:9px; object-fit:cover; background:#fff; }
        .lote-mini-meta { flex:1; min-width:0; }
        .lote-mini-meta strong { display:block; font-size:0.86rem; color:#14532d; line-height:1.2; }
        .lote-mini-meta span { display:block; font-size:0.7rem; color:#3f6212; margin-top:2px; line-height:1.25; }
        .lote-mini-top em { font-style:normal; font-size:0.72rem; font-weight:700; color:#166534; white-space:nowrap; }
        .lote-id { margin-top:6px; font-weight:800; color:#7e22ce; font-size:0.78rem; }
        .lote-dest { display:inline-block; margin-top:5px; background:#f3e8ff; color:#6b21a8; font-size:0.7rem; font-weight:700; padding:3px 9px; border-radius:999px; }
        .lote-pill { align-self:center; z-index:2; margin:-8px 0; background:#fff; border:1px solid #bbf7d0; color:#047857; font-size:0.68rem; font-weight:800; letter-spacing:0.2px; padding:5px 12px; border-radius:999px; }
        .lote-btn { margin-top:8px; width:100%; border:none; background:#e4441b; color:#fff; font-weight:800; font-size:0.82rem; letter-spacing:0.3px; padding:12px; border-radius:14px; cursor:pointer; }

        .filter-bar { background:#fff; padding:10px 12px; border-radius:14px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; border: 1px solid #ececec; }
        .filter-bar span { font-size:0.78rem; font-weight:700; color:#52525b; }
        .filter-bar select, .filter-bar input { padding:6px 10px; border-radius:10px; border:1px solid #e5e7eb; font-family:'Poppins'; font-weight:700; color:#e4441b; outline:none; background:#fff; font-size:0.8rem; }

        .aviso-verificar { display:flex; align-items:center; gap:11px; background:#fff; border:1px solid #ececec; border-radius:16px; padding:13px 14px; margin-bottom:12px; text-decoration:none; color:#111827; }
        .aviso-verificar > i:first-child { font-size:1.6rem; flex-shrink:0; }
        .aviso-verificar span { flex:1; min-width:0; }
        .aviso-verificar strong { display:block; font-size:0.88rem; line-height:1.25; }
        .aviso-verificar em { display:block; font-style:normal; font-size:0.74rem; color:#71717a; margin-top:2px; line-height:1.35; }

        /* Banners HQ (modal estilo activación) */
        #yora-banner {
            display:none; position:fixed; inset:0; z-index:14000;
            background:#f4f7fb; flex-direction:column; padding:18px 18px max(18px, env(safe-area-inset-bottom));
        }
        #yora-banner.on { display:flex; }
        #yora-banner .yb-close {
            align-self:flex-end; width:40px; height:40px; border:0; border-radius:50%;
            background:#0f2744; color:#fff; font-size:1.15rem; font-weight:800; cursor:pointer;
        }
        #yora-banner .yb-body { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:8px 6px 12px; overflow:auto; }
        #yora-banner .yb-title { font-size:1.45rem; font-weight:900; color:#0f2744; line-height:1.2; margin:0 0 10px; letter-spacing:-.3px; }
        #yora-banner .yb-title em { font-style:normal; color:#0d9488; }
        #yora-banner .yb-text { font-size:0.88rem; color:#334155; line-height:1.45; margin:0 0 18px; max-width:340px; white-space:pre-line; }
        #yora-banner .yb-img {
            width:min(100%, 320px); max-height:280px; object-fit:contain; margin:0 auto 18px;
            border-radius:16px; background:transparent;
        }
        #yora-banner .yb-dots { display:flex; gap:7px; justify-content:center; margin:8px 0 16px; }
        #yora-banner .yb-dots i { width:8px; height:8px; border-radius:50%; background:#cbd5e1; display:block; }
        #yora-banner .yb-dots i.on { background:#0f2744; }
        #yora-banner .yb-cta {
            width:100%; max-width:420px; border:0; border-radius:16px; padding:16px 18px;
            background:#0f2744; color:#fff; font-weight:800; font-size:0.95rem; cursor:pointer;
            box-shadow:0 8px 20px rgba(15,39,68,.25);
        }
        #yora-banner .yb-cta[hidden] { display:none !important; }
    </style>
</head>
<body>

    <!-- PANTALLA DE CARGA (SPLASH) -->
    <div id="splash-screen">
        <img src="https://yoradelivery.com/uploads/Isotipo.png" alt="Cargando YoraDriver">
    </div>

    <header class="header">
        <div class="logo">
            <img src="https://yoradelivery.com/uploads/logodriver.png" alt="YoraDriver">
        </div>
        <!-- 🔥 BUG DE SEGURIDAD CORREGIDO: Botón bloqueado hasta tener GPS real 🔥 -->
        <div style="display:flex; align-items:center;">
            <span id="gps-vivo" class="gps-vivo off">GPS off</span>
            <div id="status-toggle" class="toggle-btn offline" style="opacity:0.5; pointer-events:none;" onclick="toggleStatus(1)">○ Offline</div>
        </div>
    </header>

    <!-- 1. PESTAÑA RADAR -->
    <main id="tab-anuncios" class="tab-content active">
        <?php if (!$docs['verificado']): ?>
        <!-- Aviso de verificación: mientras el expediente no esté aprobado,
             el conductor ve en el radar qué le falta y a dónde ir. -->
        <a href="ajustes_documentos.php" class="aviso-verificar">
            <i style="font-size:1.4rem;line-height:1;"><?php
                $ic = (string) ($docs['estilo']['icono'] ?? '');
                echo (strpos($ic, 'check') !== false || strpos($ic, 'seal') !== false) ? '✅' : ((strpos($ic, 'x') !== false || strpos($ic, 'warning') !== false) ? '⚠️' : '📄');
            ?></i>
            <span>
                <strong>
                    <?php
                    if ($docs['global'] === 'En Revisión') {
                        echo 'Documentos en revisión';
                    } elseif ($docs['global'] === 'Rechazado') {
                        echo 'Tienes documentos por corregir';
                    } elseif ($docs['global'] === 'Cargado') {
                        echo 'Falta enviar tu expediente';
                    } else {
                        echo 'Verifica tu cuenta para operar';
                    }
                    ?>
                </strong>
                <em>
                    <?php
                    if ($docs['global'] === 'En Revisión') {
                        echo 'El equipo de Yora está revisando tu expediente.';
                    } elseif ($docs['global'] === 'Cargado') {
                        echo 'Ya subiste todo. Pulsa aquí y envíalo a verificación.';
                    } elseif ($docs['faltantes'] > 0) {
                        echo 'Te faltan ' . (int) $docs['faltantes'] . ' de ' . (int) $docs['total'] . ' documentos. Súbelos aquí.';
                    } else {
                        echo 'Revisa las observaciones y vuelve a enviarlos.';
                    }
                    ?>
                </em>
            </span>
            <span style="color:#c4c4c8;font-size:1.2rem;">›</span>
        </a>
        <?php endif; ?>
        <div class="filter-bar">
            <span>Rango</span>
            <select id="radioFiltro" onchange="sincronizarApp(true)">
                <option value="1">1 km</option>
                <option value="2">2 km</option>
                <option value="3" selected>3 km</option>
                <option value="5">5 km</option>
                <option value="10">10 km</option>
                <option value="15">15 km</option>
                <option value="999">Toda la ciudad</option>
            </select>
        </div>
        <div id="content-anuncios">Cargando anuncios...</div>
    </main>

    <!-- 2. PESTAÑA MIS VIAJES -->
    <main id="tab-misviajes" class="tab-content">
        <div id="content-misviajes">Cargando tus viajes...</div>
    </main>

    <!-- 3. PESTAÑA BILLETERA Y FACTURAS -->
    <main id="tab-resumen" class="tab-content">
        
        <!-- CALENDARIO DE FILTRO PARA BILLETERA -->
        <div class="filter-bar">
            <span>Filtrar por día</span>
            <input type="date" id="billetera_fecha" onchange="sincronizarApp(true)" value="">
        </div>

        <div id="content-resumen">Cargando resumen...</div>
    </main>

    <!-- ======================================================== -->
    <!-- 🔥 MODAL DE FACTURA PREMIUM (Sincronizado) 🔥 -->
    <!-- ======================================================== -->
    <div id="modal-factura" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.8); z-index:9999; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
        <div style="background:white; border-radius:24px; width:90%; max-width:400px; overflow:hidden; position:relative; text-align:center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            
            <div style="background: #e4441b; padding: 25px 20px; color: white;">
                <h2 style="margin:0; font-weight:800; font-size:1.8rem; letter-spacing:1px; font-style:italic;">
                    Yora<span style="font-weight:400;">Delivery</span>
                </h2>
            </div>
            
            <div style="padding: 30px 20px;">
                <div style="font-size: 3.5rem; color: #e4441b; margin-bottom:10px;">🧾</div>
                <h2 style="margin: 0 0 5px; color: #1e293b; font-size: 1.5rem; font-weight:800;">Factura #<span id="f_orden"></span></h2>
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px; font-weight:600;" id="f_fecha"></p>
                
                <div style="text-align:left; background:#f8fafc; padding:20px; border-radius:12px; border:1px dashed #cbd5e1; margin-bottom: 20px;">
                    <p style="margin-bottom:8px; font-size: 0.9rem;">🧑 <b>Cliente:</b> <span id="f_cliente"></span></p>
                    <p style="margin-bottom:8px; font-size: 0.9rem;">📍 <b>Destino:</b> <span id="f_detalles"></span></p>
                    <p style="margin-bottom:8px; font-size: 0.9rem;">🛵 <b>Driver:</b> <span id="f_driver"></span></p>
                    <hr style="border:0; border-top:1px solid #e2e8f0; margin:15px 0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:1.2rem;">
                        <b>Tu ganancia:</b> <span id="f_costo" style="color:#10b981; font-weight:900;"></span>
                    </div>
                </div>
                
                <button onclick="window.print()" style="background: #e4441b; color: white; width: 100%; padding: 16px; border: none; border-radius: 30px; font-weight: 800; font-size: 1rem; cursor: pointer; box-shadow: 0 6px 15px rgba(228,68,27,0.3); transition: transform 0.2s;">
                    IMPRIMIR COMPROBANTE
                </button>
            </div>

            <button onclick="cerrarModalFactura()" style="position:absolute; top:15px; right:15px; background:rgba(0,0,0,0.2); border:none; border-radius:50%; width:30px; height:30px; color:white; cursor:pointer; display:flex; justify-content:center; align-items:center; font-size:1.2rem;">&times;</button>
        </div>
    </div>


    <!-- INPUT INVISIBLE PARA ABRIR CÁMARA -->
    <input type="file" id="input-foto-evidencia" accept="image/*" capture="environment" style="display:none;" onchange="subirEvidenciaYEstatus(this)">
    
    <!-- PANTALLA DE CARGA DE EVIDENCIA -->
    <div id="cargando-evidencia" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.9); z-index:9999; color:white; justify-content:center; align-items:center; flex-direction:column; backdrop-filter: blur(5px);">
        <div style="font-size:3.2rem; color:#e4441b; animation: spin 1s linear infinite;">⟳</div>
        <p style="margin-top:15px; font-weight:700; font-size:1.1rem; letter-spacing:1px;">Comprimiendo y subiendo...</p>
        <p style="font-size:0.8rem; color:#94a3b8; margin-top:5px;">Por favor no cierres la app</p>
    </div>

    <!-- MENÚ INFERIOR MODERNO -->
    <nav class="bottom-nav">
        <div id="btn-nav-anuncios" class="nav-item active" onclick="cambiarTab('anuncios', this)">
            <span class="nav-ico">📡</span><span>Radar</span>
        </div>
        <div id="btn-nav-misviajes" class="nav-item" onclick="cambiarTab('misviajes', this)">
            <span class="nav-ico">📍</span><span>Viajes</span>
        </div>
        <a href="billetera.php" class="nav-item" style="text-decoration:none;">
            <span class="nav-ico">💰</span><span>Billetera</span>
        </a>
        <!-- Ajustes ya no es una pestaña: es su propia pantalla con perfil,
             verificación de cuenta, datos de cobro y seguridad. -->
        <a href="ajustes.php" class="nav-item" style="text-decoration:none;<?php echo $docs['verificado'] ? '' : ' position:relative;'; ?>">
            <span class="nav-ico">⚙️</span><span>Ajustes</span>
            <?php if (!$docs['verificado']): ?>
            <em style="position:absolute; top:-2px; right:calc(50% - 18px); width:9px; height:9px; border-radius:50%; background:#e4441b; border:2px solid #fff;"></em>
            <?php endif; ?>
        </a>
    </nav>

    <div id="nav-viaje">
        <div class="nav-map-wrap">
            <div id="nav-mapa"></div>
            <div class="nav-hud-top">
                <div id="nav-instruccion">
                    <div class="nav-arrow" id="nav-arrow">↑</div>
                    <div class="nav-arrow-txt">
                        <span id="nav-instruccion-txt">Preparando guía…</span>
                        <small id="nav-instruccion-meta">Dentro de Yora</small>
                    </div>
                </div>
                <div id="nav-luego"><span class="nav-arrow-mini" id="nav-luego-arrow">↩</span> Luego <span id="nav-luego-txt"></span></div>
            </div>
            <div class="nav-side-btns">
                <button type="button" onclick="YORA.centrarYo()" title="Centrar" aria-label="Centrar">◎</button>
            </div>
            <div class="nav-speed"><b id="nav-speed-val">0</b><span>km/h</span></div>
            <div class="nav-dock">
                <button type="button" class="nav-x" onclick="YORA.detenerRecorrido(); cerrarRutaViaje();" aria-label="Salir">✕</button>
                <div class="nav-eta">
                    <strong id="nav-eta-min">— min</strong>
                    <small id="nav-eta-meta">— km · —:—</small>
                </div>
                <button type="button" class="nav-recenter" onclick="YORA.centrarYo()" aria-label="Mi ubicación">➤</button>
            </div>
        </div>
        <div class="nav-preview">
            <div class="nav-preview-head">
                <button type="button" onclick="cerrarRutaViaje()" aria-label="Cerrar">←</button>
                <h3 id="nav-titulo">Ruta del viaje<span id="nav-titulo-sub">Motorizado · dentro de Yora</span></h3>
            </div>
            <div class="nav-leg"><i class="yo"></i><div><b>Tú ahora</b><div>Tu ubicación GPS</div></div></div>
            <div class="nav-leg"><i class="pick"></i><div><b>Retiro</b><div id="nav-pick-txt">—</div></div></div>
            <div class="nav-leg"><i class="drop"></i><div><b>Entrega</b><div id="nav-drop-txt">—</div></div></div>
            <button type="button" id="nav-btn-go" onclick="YORA.iniciarRecorrido()">Iniciar</button>
        </div>
    </div>

    <div id="yora-banner" aria-hidden="true">
        <button type="button" class="yb-close" onclick="yoraBannerCerrar()" aria-label="Cerrar">✕</button>
        <div class="yb-body">
            <h2 class="yb-title" id="yb-title"></h2>
            <p class="yb-text" id="yb-text"></p>
            <img class="yb-img" id="yb-img" alt="" hidden>
            <div class="yb-dots" id="yb-dots"></div>
            <button type="button" class="yb-cta" id="yb-cta" hidden></button>
        </div>
    </div>

    <script src="/js/yora_driver_vivo.js?v=22"></script>
    <script>
        const YORA_DRIVER_ID = <?php echo (int) $conductor_id; ?>;
        const DRIVER_VERIFICADO = <?php echo $docs['verificado'] ? 'true' : 'false'; ?>;
        const YORA_BANNERS = <?php echo json_encode($banners_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        window.YORA = window.YORA || {};
        YORA.gpsFondoUrl = <?php echo json_encode('https://app.yoradelivery.com/api/latido_gps_fondo.php?c=' . (int) $conductor_id . '&k=' . yora_gps_fondo_token($conductor_id)); ?>;
        const styleSpin = document.createElement('style');
        styleSpin.innerHTML = `@keyframes spin { 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(styleSpin);

        let ybIdx = 0;
        let ybPendientes = [];
        function yoraBannerKey() {
            return 'yora_banner_seen_v1_' + YORA_DRIVER_ID;
        }
        function yoraBannerSeen() {
            try { return JSON.parse(localStorage.getItem(yoraBannerKey()) || '[]'); } catch (e) { return []; }
        }
        function yoraBannerMark(ids) {
            try {
                const prev = yoraBannerSeen();
                const set = {};
                prev.concat(ids || []).forEach(function (id) { set[String(id)] = 1; });
                localStorage.setItem(yoraBannerKey(), JSON.stringify(Object.keys(set).map(Number)));
            } catch (e) {}
        }
        function yoraBannerAccentTitle(t) {
            const s = String(t || '');
            // Resalta la última palabra (estilo Ridery "ACTIVACIÓN")
            const parts = s.trim().split(/\s+/);
            if (parts.length < 2) return s.replace(/</g, '&lt;');
            const last = parts.pop();
            return parts.join(' ').replace(/</g, '&lt;') + ' <em>' + last.replace(/</g, '&lt;') + '</em>';
        }
        function yoraBannerRender() {
            const b = ybPendientes[ybIdx];
            if (!b) return;
            document.getElementById('yb-title').innerHTML = yoraBannerAccentTitle(b.titulo);
            document.getElementById('yb-text').textContent = b.cuerpo || '';
            const img = document.getElementById('yb-img');
            if (b.imagen) {
                img.src = b.imagen;
                img.hidden = false;
            } else {
                img.removeAttribute('src');
                img.hidden = true;
            }
            const dots = document.getElementById('yb-dots');
            dots.innerHTML = '';
            ybPendientes.forEach(function (_, i) {
                const el = document.createElement('i');
                if (i === ybIdx) el.className = 'on';
                el.onclick = function () { ybIdx = i; yoraBannerRender(); };
                dots.appendChild(el);
            });
            const cta = document.getElementById('yb-cta');
            if (b.boton) {
                cta.hidden = false;
                cta.textContent = b.boton;
                cta.onclick = function () {
                    yoraBannerMark([b.id]);
                    const u = (b.url || '').trim();
                    if (!u) { yoraBannerCerrar(); return; }
                    if (/^https?:\/\//i.test(u)) {
                        window.open(u, '_blank');
                        yoraBannerCerrar();
                    } else {
                        window.location.href = u.replace(/^\//, '');
                    }
                };
            } else {
                cta.hidden = true;
            }
        }
        function yoraBannerAbrir() {
            const seen = yoraBannerSeen();
            ybPendientes = (YORA_BANNERS || []).filter(function (b) { return seen.indexOf(b.id) < 0; });
            if (!ybPendientes.length) return;
            ybIdx = 0;
            yoraBannerRender();
            const box = document.getElementById('yora-banner');
            box.classList.add('on');
            box.setAttribute('aria-hidden', 'false');
        }
        function yoraBannerCerrar() {
            if (ybPendientes.length) yoraBannerMark(ybPendientes.map(function (b) { return b.id; }));
            const box = document.getElementById('yora-banner');
            box.classList.remove('on');
            box.setAttribute('aria-hidden', 'true');
        }
        window.yoraBannerCerrar = yoraBannerCerrar;

        window.addEventListener('load', () => {
            setTimeout(() => { document.getElementById('splash-screen').classList.add('hide-splash'); }, 350);
            // Banner mucho después del primer paint (no pelear con la UI).
            setTimeout(yoraBannerAbrir, 8000);
        });

        let tab_actual = <?php echo json_encode($tab_inicial); ?>;
        let ultimosAnuncios = []; 
        let ultimoFinger = ""; 
        let syncEnCurso = false;
        let sonidoAlerta = null;
        function getSonidoAlerta() {
            if (!sonidoAlerta) {
                try { sonidoAlerta = new Audio('/sounds/pedido.mp3'); } catch (e) {
                    try { sonidoAlerta = new Audio('https://cdn.pixabay.com/download/audio/2021/08/04/audio_0625c1539c.mp3'); } catch (e2) {}
                }
            }
            return sonidoAlerta;
        }

        function cambiarTab(tabId, element) {
            tab_actual = tabId;
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
            
            document.getElementById('tab-' + tabId).classList.add('active');
            element.classList.add('active');
            setTimeout(() => { window.dispatchEvent(new Event('resize')); }, 100);
            if (tabId === 'resumen' || tabId === 'misviajes') sincronizarApp(true);
        }

        // Al volver desde Ajustes (dashboard.php?tab=...) se abre la pestaña
        // en la que estaba el conductor, no siempre el radar.
        if (tab_actual !== 'anuncios') {
            cambiarTab(tab_actual, document.getElementById('btn-nav-' + tab_actual));
        }

        async function asegurarPermisosTrabajo() {
            try {
                if (!window.YoraNative || typeof YoraNative.permisosTrabajoListos !== 'function') return true;
                if (YoraNative.permisosTrabajoListos()) return true;
                if (typeof YoraNative.pedirPermisosTrabajo === 'function') YoraNative.pedirPermisosTrabajo();
                for (let i = 0; i < 50; i++) {
                    await new Promise(function (r) { setTimeout(r, 400); });
                    if (YoraNative.permisosTrabajoListos()) return true;
                }
                alert('Activa ubicación todo el tiempo, notificaciones y sin ahorro de batería para llevar pedidos.');
                return false;
            } catch (e) {
                return true;
            }
        }

        async function gestionarLote(id1, id2) {
            if (!(await asegurarPermisosTrabajo())) return;
            const formData = new FormData();
            formData.append('comanda_id', id1);
            formData.append('comanda_id_2', id2);
            formData.append('accion', 'bloquear_lote');
            try {
                let res = await fetch('/api/gestionar_viaje.php', { method: 'POST', body: formData });
                let data = await res.json();
                if (data.status === 'success') {
                    if (window.YORA && typeof YORA.activarVivo === 'function') YORA.activarVivo(true);
                    sincronizarApp(true);
                } else {
                    alert("❌ " + data.mensaje);
                    sincronizarApp(true);
                }
            } catch(e) { alert("Sin conexión a internet."); }
        }

        async function gestionarViaje(id, accion) {
            if (accion === 'aceptar' || accion === 'bloquear') {
                if (!(await asegurarPermisosTrabajo())) return;
            }
            const formData = new FormData();
            formData.append('comanda_id', id); formData.append('accion', accion);
            try {
                let res = await fetch('/api/gestionar_viaje.php', { method: 'POST', body: formData });
                let data = await res.json();
                if(data.status === 'success') {
                    if(accion == 'aceptar') {
                        cambiarTab('misviajes', document.getElementById('btn-nav-misviajes'));
                        if (window.YORA && typeof YORA.activarVivo === 'function') YORA.activarVivo(true);
                    }
                    sincronizarApp(true); 
                } else { alert("❌ " + data.mensaje); sincronizarApp(true); }
            } catch(e) { alert("Sin conexión a internet."); }
        }

        let estatusPendiente = '';
        let idComandaPendiente = null;

        function cambiarEstatus(id, estatus) {
            if(!myLat || !myLng) {
                alert("📍 Necesitamos tu ubicación para confirmar este paso. Activa el GPS y espera unos segundos.");
                return;
            }
            idComandaPendiente = id;
            estatusPendiente = estatus;
            document.getElementById('input-foto-evidencia').click();
        }

        async function subirEvidenciaYEstatus(input) {
            if (!(input.files && input.files[0])) return;
            const overlay = document.getElementById('cargando-evidencia');
            overlay.style.display = 'flex';

            const original = input.files[0];
            let foto = original;
            try {
                foto = await comprimirFoto(original);
            } catch (e) {
                foto = original;
            }

            const fd = new FormData();
            fd.append('id', idComandaPendiente);
            fd.append('estatus', estatusPendiente);
            fd.append('lat', myLat);
            fd.append('lng', myLng);
            fd.append('foto', foto, 'evidencia.jpg');

            try {
                const res = await fetch('/api/cambiar_estatus.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    sincronizarApp(true);
                } else {
                    alert(data.mensaje || 'No se pudo actualizar el viaje.');
                }
            } catch (e) {
                alert('Error de conexión al enviar la foto. Revisa internet e inténtalo de nuevo.');
            }

            overlay.style.display = 'none';
            input.value = '';
        }

        function comprimirFoto(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onerror = () => reject(new Error('leer'));
                reader.onload = function (event) {
                    const img = new Image();
                    img.onerror = () => reject(new Error('imagen'));
                    img.onload = function () {
                        const w = img.width || 0;
                        const h = img.height || 0;
                        if (w < 8 || h < 8) {
                            resolve(file);
                            return;
                        }
                        const canvas = document.createElement('canvas');
                        const MAX = 1280;
                        const scale = Math.min(1, MAX / w);
                        canvas.width = Math.max(1, Math.round(w * scale));
                        canvas.height = Math.max(1, Math.round(h * scale));
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                        canvas.toBlob(function (blob) {
                            resolve(blob && blob.size > 0 ? blob : file);
                        }, 'image/jpeg', 0.72);
                    };
                    img.src = event.target.result;
                };
                reader.readAsDataURL(file);
            });
        }

        function tagOneSignal(tags) {
            try {
                const os = puenteOneSignal();
                if (!os) return;
                if (typeof os.sendTags === 'function') os.sendTags(tags);
                else if (os.tags && typeof os.tags.add === 'function') os.tags.add(tags);
            } catch (e) {}
        }

        async function toggleStatus(nuevoEstadoNum) {
            // Sin cuenta verificada no se puede conectar: lo llevamos a subir sus documentos.
            if(nuevoEstadoNum === 1 && !DRIVER_VERIFICADO) {
                if(confirm("🔒 Para conectarte y recibir pedidos primero debes verificar tu cuenta.\n\n¿Vamos a tus documentos ahora?")) {
                    window.location.href = 'ajustes_documentos.php';
                }
                return;
            }
            if (nuevoEstadoNum === 1) {
                if (!(await asegurarPermisosTrabajo())) return;
                if (window.YORA && typeof YORA.fondoSegunEstado === 'function') {
                    YORA.fondoSegunEstado(true, true);
                }
                if (!myLat || !myLng) {
                    const ok = window.YORA && typeof YORA.esperarFix === 'function'
                        ? await YORA.esperarFix(9000)
                        : false;
                    if (!ok && (!myLat || !myLng)) {
                        alert("⏳ No hay señal GPS todavía. Sal a un lugar abierto e inténtalo de nuevo.");
                        if (window.YORA && typeof YORA.fondoSegunEstado === 'function') {
                            YORA.fondoSegunEstado(false);
                        }
                        return;
                    }
                }
            }
            const formData = new FormData();
            formData.append('estado', nuevoEstadoNum);
            if (myLat && myLng) {
                formData.append('lat', String(myLat));
                formData.append('lng', String(myLng));
            }
            try {
                let res = await fetch('/api/toggle_status.php', { method: 'POST', body: formData });
                let data = await res.json();
                if(data.verificar) {
                    if(confirm("🔒 " + data.mensaje + "\n\n¿Vamos a tus documentos ahora?")) {
                        window.location.href = 'ajustes_documentos.php';
                    }
                    return;
                }
                if(data.status === 'success') {
                    let btn = document.getElementById('status-toggle');
                    if(nuevoEstadoNum === 1) {
                        btn.className = 'toggle-btn online'; btn.innerHTML = '● Online';
                        btn.setAttribute('onclick', "toggleStatus(0)");
                    } else {
                        btn.className = 'toggle-btn offline'; btn.innerHTML = '○ Offline';
                        btn.setAttribute('onclick', "toggleStatus(1)");
                    }
                    tagOneSignal({ en_linea: String(nuevoEstadoNum), driver_id: String(YORA_DRIVER_ID) });
                    if (window.YORA && typeof YORA.fondoSegunEstado === 'function') {
                        YORA.fondoSegunEstado(nuevoEstadoNum === 1, true);
                    }
                    sincronizarApp(true);
                }
            } catch (e) { alert("Sin conexión a internet."); }
        }

        // FUNCIONES DE LA FACTURA
        function abrirModalFactura(data) {
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch (e) { data = {}; }
            }
            data = data || {};
            const total = parseFloat(data.total != null ? data.total : data.costo);
            const ganancia = parseFloat(data.ganancia != null ? data.ganancia : data.costo);
            document.getElementById('f_orden').innerText = data.id || "N/A";
            document.getElementById('f_fecha').innerText = data.fecha || "N/A";
            document.getElementById('f_cliente').innerText = data.cliente || "N/A";
            document.getElementById('f_detalles').innerText = data.detalles || "N/A";
            document.getElementById('f_driver').innerText = data.conductor || "Tú";
            const monto = isFinite(ganancia) ? ganancia : (isFinite(total) ? total : 0);
            document.getElementById('f_costo').innerText = "$" + Number(monto).toFixed(2);
            document.getElementById('modal-factura').style.display = 'flex';
        }

        function cerrarModalFactura() {
            document.getElementById('modal-factura').style.display = 'none';
        }

        async function solicitarRetiro() {
            if(confirm("¿Deseas retirar todo tu saldo disponible a tu cuenta bancaria registrada?")) {
                try {
                    let res = await fetch('/api/solicitar_retiro.php', { method: 'POST', body: new FormData() });
                    let data = await res.json();
                    if(data.status === 'success') { alert("✅ " + data.mensaje); sincronizarApp(true); } 
                    else { alert("❌ " + data.mensaje); }
                } catch(e) { alert("Error de conexión."); }
            }
        }

        function dibujarMapas() {
            // Sin Leaflet en las tarjetas: solo atajo a la navegación in-app.
            document.querySelectorAll('.mapa-gps.mapa-static').forEach(function (div) {
                if (div.dataset.bound === '1') return;
                div.dataset.bound = '1';
                div.addEventListener('click', function () { abrirRutaViaje(div); });
            });
        }

        var myLat = null; var myLng = null;
        let gpsEnviadoEn = 0;
        function hayGpsNativo() {
            return !!(window.YoraNative && typeof YoraNative.startBackgroundGps === 'function');
        }
        function enviarGpsVivo(pos) {
            if (!pos || !pos.coords) return;
            const first = !myLat;
            myLat = pos.coords.latitude;
            myLng = pos.coords.longitude;
            if (window.YORA) { YORA.lat = myLat; YORA.lng = myLng; }
            if (first) setTimeout(function () { sincronizarApp(true); }, 2500);
            // En APK el GpsService ya manda al servidor: no duplicar latidos.
            if (hayGpsNativo()) return;
            const ahora = Date.now();
            if (ahora - gpsEnviadoEn < 10000) return;
            gpsEnviadoEn = ahora;
            const fd = new FormData();
            fd.append('lat', String(myLat));
            fd.append('lng', String(myLng));
            if (typeof pos.coords.heading === 'number' && isFinite(pos.coords.heading) && pos.coords.heading >= 0) {
                fd.append('heading', String(pos.coords.heading));
            }
            if (typeof pos.coords.accuracy === 'number' && isFinite(pos.coords.accuracy)) {
                fd.append('acc', String(pos.coords.accuracy));
            }
            fetch('/api/latido_gps.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
        }
        window.enviarGpsVivo = enviarGpsVivo;

        async function sincronizarApp(forzarUpdate = false) {
            if (syncEnCurso) return;
            syncEnCurso = true;
            try {
                let radio = document.getElementById('radioFiltro') ? document.getElementById('radioFiltro').value : 3;
                let fecha_filtro = document.getElementById('billetera_fecha') ? document.getElementById('billetera_fecha').value : '';
                const quiereHist = (tab_actual === 'resumen') ? '1' : '0';
                
                let url = `/api/sync_app.php?radio=${radio}&fecha=${fecha_filtro}&hist=${quiereHist}`;
                if(myLat && myLng) url += `&lat=${myLat}&lng=${myLng}`;

                let res = await fetch(url);

                if (res.status === 401) {
                    window.location.href = 'index.php?sesion=duplicada';
                    return;
                }
                if (res.status === 503) {
                    const d = await res.json().catch(function () { return {}; });
                    alert(d.mensaje || 'Sistema en mantenimiento.');
                    return;
                }

                let data = await res.json();
                const finger = data.finger || '';
                
                if(finger !== ultimoFinger || forzarUpdate) {
                    document.getElementById('content-anuncios').innerHTML = data.anuncios;
                    document.getElementById('content-misviajes').innerHTML = data.misviajes;
                    if (quiereHist === '1' || forzarUpdate) {
                        document.getElementById('content-resumen').innerHTML = data.resumen;
                    }
                    
                    let btn = document.getElementById('status-toggle');
                    btn.style.opacity = "1";
                    btn.style.pointerEvents = "auto";

                    if(!DRIVER_VERIFICADO) {
                        btn.className = 'toggle-btn offline'; btn.innerHTML = '🔒 Verificar';
                        btn.setAttribute('onclick', "toggleStatus(1)");
                        if (window.YORA && typeof YORA.fondoSegunEstado === 'function') YORA.fondoSegunEstado(false);
                    } else if(data.estado_actual == '1') {
                        btn.className = 'toggle-btn online'; btn.innerHTML = '● Online';
                        btn.setAttribute('onclick', "toggleStatus(0)");
                        // Diferido: no pelear con el arranque del WebView.
                        if (window.YORA && typeof YORA.fondoSegunEstado === 'function') YORA.fondoSegunEstado(true, false);
                    } else if(data.estado_actual == '0') {
                        btn.className = 'toggle-btn offline'; btn.innerHTML = '○ Offline';
                        btn.setAttribute('onclick', "toggleStatus(1)");
                        // Apaga GPS huérfano (notificación que quedó activa).
                        if (window.YORA && typeof YORA.fondoSegunEstado === 'function') YORA.fondoSegunEstado(false);
                    }

                    ultimoFinger = finger; 
                    dibujarMapas(); 
                }
                
                if (data.anuncios_ids) {
                    let hayNuevos = data.anuncios_ids.some(id => !ultimosAnuncios.includes(id));
                    if (hayNuevos && data.anuncios_ids.length > 0) {
                        const s = getSonidoAlerta();
                        if (s) s.play().catch(e => console.log("Sonido bloqueado"));
                        avisarPedidoNuevo(data.anuncios_ids.length);
                    }
                    ultimosAnuncios = data.anuncios_ids;
                }

            } catch(error) { 
                console.log("Esperando conexión a internet..."); 
                let btn = document.getElementById('status-toggle');
                btn.style.opacity = "0.5";
                btn.style.pointerEvents = "none";
            } finally {
                syncEnCurso = false;
            }
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            const out = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
            return out;
        }

        async function avisarPedidoNuevo(cuantos) {
            try {
                const s = getSonidoAlerta();
                if (s) { s.currentTime = 0; s.play().catch(function(){}); }
            } catch (e) {}
            try { if (navigator.vibrate) navigator.vibrate([400, 120, 400, 120, 600]); } catch (e) {}
            if (!('Notification' in window) || Notification.permission !== 'granted') return;
            try {
                const reg = await navigator.serviceWorker.ready;
                await reg.showNotification('Nuevo pedido Yora', {
                    body: cuantos > 1 ? 'Hay ' + cuantos + ' viajes en el radar.' : 'Hay un viaje nuevo en el radar.',
                    icon: 'https://yoradelivery.com/uploads/Isotipo.png',
                    tag: 'yora-pedido-' + Date.now(),
                    renotify: true,
                    requireInteraction: true,
                    vibrate: [400, 120, 400, 120, 600],
                    data: { url: '/dashboard.php' }
                });
            } catch (e) {}
        }

        function puenteOneSignal() {
            return (window.median && window.median.onesignal)
                || (window.gonative && window.gonative.onesignal)
                || null;
        }

        async function esperarOneSignal(ms) {
            const hasta = Date.now() + ms;
            while (Date.now() < hasta) {
                const p = puenteOneSignal();
                if (p) return p;
                await new Promise(r => setTimeout(r, 250));
            }
            return puenteOneSignal();
        }

        async function guardarSuscripcionOneSignal(ext, player, subId) {
            const fd = new FormData();
            fd.append('tipo', 'onesignal');
            fd.append('external_id', ext);
            fd.append('player_id', player || ext);
            fd.append('subscription_id', subId || '');
            await fetch('/api/guardar_push.php', { method: 'POST', body: fd });
        }

        async function ligarOneSignalNativo() {
            if (!(window.YoraNative)) return false;
            const ext = 'yora-driver-' + YORA_DRIVER_ID;
            const os = await esperarOneSignal(2500);
            if (!os) return false;
            try { if (typeof os.login === 'function') await os.login(ext); } catch (e) {}
            try { if (typeof os.optIn === 'function') os.optIn(); } catch (e) {}
            try {
                const enLinea = (document.getElementById('status-toggle') && document.getElementById('status-toggle').classList.contains('online')) ? '1' : '0';
                if (typeof os.sendTags === 'function') os.sendTags({ driver_id: String(YORA_DRIVER_ID), en_linea: enLinea, app: 'yoradriver' });
            } catch (e) {}
            setTimeout(async function () {
                try {
                    let player = '', subId = '', info = null;
                    if (typeof os.info === 'function') info = await os.info();
                    else if (typeof os.onesignalInfo === 'function') info = await os.onesignalInfo();
                    if (info) {
                        player = info.oneSignalId || info.oneSignalUserId || info.userId || '';
                        subId = (info.subscription && (info.subscription.id || info.subscription.token)) || info.subscriptionId || '';
                        if (!player && subId) player = subId;
                    }
                    await guardarSuscripcionOneSignal(ext, player, subId);
                } catch (e) {}
            }, 2000);
            return true;
        }

        async function activarPush() {
            try {
                if (window.YoraNative) { await ligarOneSignalNativo(); return; }
            } catch (e) {}
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
            try {
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') return;
                const reg = await navigator.serviceWorker.register('/sw.js?v=10');
                await navigator.serviceWorker.ready;
                const vapid = await fetch('/api/vapid.php').then(r => r.json());
                if (!vapid.publicKey) return;
                let sub = await reg.pushManager.getSubscription();
                if (!sub) {
                    sub = await reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapid.publicKey)
                    });
                }
                const keys = sub.toJSON().keys || {};
                const fd = new FormData();
                fd.append('endpoint', sub.endpoint);
                fd.append('p256dh', keys.p256dh || '');
                fd.append('auth', keys.auth || '');
                await fetch('/api/guardar_push.php', { method: 'POST', body: fd });
            } catch (e) {
                console.log('Push no disponible en este teléfono.');
            }
        }

        setTimeout(function () { sincronizarApp(); }, 1800);
        setInterval(() => sincronizarApp(false), 25000);
        window.addEventListener('load', () => {
            setTimeout(activarPush, 6000);
        });
        document.addEventListener('touchstart', function unlockAudio() {
            try {
                const s = getSonidoAlerta();
                if (s) s.play().then(function(){ s.pause(); s.currentTime = 0; }).catch(function(){});
            } catch (e) {}
            document.removeEventListener('touchstart', unlockAudio);
        }, { once: true });
        window.median_library_ready = function () {
            setTimeout(activarPush, 5000);
        };
    </script>
</body>
</html>