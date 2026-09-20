<?php
require_once 'conexion.php';
yora_require_admin_pagina('mapa.php');
yora_timezone($conexion);
$en_linea = (int) (yora_one($conexion, "SELECT COUNT(*) AS n FROM conductores WHERE en_linea = 1 AND estatus = 'activo'")['n'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Mapa de flota</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#fff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
        body { background:var(--bg-body); color:var(--text-main); display:flex; height:100vh; overflow:hidden; }
        .sidebar { width:280px; min-width:280px; background:var(--bg-card); padding:25px 20px; border-right:1px solid var(--border-color); display:flex; flex-direction:column; }
        .sidebar-brand { display:flex; align-items:center; justify-content:space-between; margin-bottom:35px; padding-left:10px; }
        .sidebar h2 { font-size:1.5rem; margin:0; font-weight:700; }
        .sidebar h2 span { color:var(--yora-orange); }
        .badge-app { background:rgba(206,78,45,0.1); color:var(--yora-orange); font-size:0.7rem; padding:4px 8px; border-radius:6px; font-weight:600; }
        .menu-category { font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:600; letter-spacing:1px; margin:0 0 10px 10px; }
        .menu-item { padding:12px 15px; margin-bottom:6px; border-radius:10px; font-weight:500; font-size:0.95rem; color:#4b5563; text-decoration:none; display:flex; align-items:center; gap:12px; }
        .menu-item:hover { background:#f3f4f6; color:var(--text-main); }
        .menu-item.active { background:rgba(206,78,45,0.1); color:var(--yora-orange); font-weight:600; }
        .menu-bottom { margin-top:auto; border-top:1px solid var(--border-color); padding-top:15px; }
        .content { flex:1; padding:18px 20px; overflow:hidden; display:flex; flex-direction:column; min-width:0; }
        .mapa-top { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:12px; }
        .header-title { font-size:1.55rem; font-weight:800; margin:0 0 4px; }
        .header-subtitle { color:var(--text-muted); font-size:0.88rem; }
        .mapa-busca { display:flex; gap:8px; align-items:center; flex:1; min-width:260px; max-width:520px; }
        .mapa-busca input {
            flex:1; padding:12px 14px; border:1px solid #d1d5db; border-radius:12px; font-size:0.95rem;
            font-family:inherit; background:#fff;
        }
        .mapa-busca input:focus { outline:none; border-color:var(--yora-orange); box-shadow:0 0 0 3px rgba(206,78,45,.15); }
        .mapa-busca button {
            background:var(--yora-orange); color:#fff; border:0; border-radius:12px; padding:12px 16px;
            font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap;
        }
        .mapa-cuerpo { flex:1; min-height:0; display:flex; gap:12px; }
        #mapa-flota { flex:1; min-width:0; min-height:280px; border-radius:18px; border:1px solid var(--border-color); overflow:hidden; background:#e2e8f0; }
        .mapa-lado {
            width:300px; min-width:240px; background:#fff; border:1px solid var(--border-color);
            border-radius:18px; overflow:auto; padding:10px;
        }
        .mapa-lado h4 { font-size:0.8rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:6px 8px 10px; }
        .mapa-item {
            width:100%; display:flex; gap:10px; align-items:center; text-align:left; border:0;
            background:#f8fafc; border-radius:12px; padding:8px; margin-bottom:8px; cursor:pointer; font-family:inherit;
        }
        .mapa-item:hover { background:#fff7ed; }
        .mapa-item img { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
        .mapa-item span { display:flex; flex-direction:column; min-width:0; }
        .mapa-item b { font-size:0.88rem; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mapa-item small { color:#64748b; font-size:0.72rem; }
        .mapa-item.on { background:#fff7ed; outline:2px solid #fdba74; }
        .mapa-item small.ok { color:#16a34a; font-weight:700; }
        .header-subtitle b.vivo { color:#16a34a; }
        .mapa-item small.off { color:#b45309; font-weight:700; }
        .mapa-vacio { color:#94a3b8; font-size:0.85rem; padding:16px 8px; }
        .leaflet-popup-content { font-family:'Poppins',sans-serif; }
        @media (max-width: 900px) {
            .mapa-lado { width:100%; min-width:0; max-height:180px; }
            .mapa-cuerpo { flex-direction:column; }
        }
        html.hq-movil body { height:100dvh !important; overflow:hidden !important; }
        html.hq-movil .content { display:flex !important; flex-direction:column !important; height:calc(100dvh - 58px); padding:10px !important; overflow:hidden !important; }
        html.hq-movil #mapa-flota { min-height:220px !important; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <div class="mapa-top">
        <div>
            <h1 class="header-title">Mapa de la flota</h1>
            <p class="header-subtitle"><b id="n-linea" class="vivo"><?php echo $en_linea; ?></b> en línea · mapa en vivo cada 2,5 s · <span id="n-filtro"><?php echo $en_linea; ?></span> en la lista</p>
        </div>
        <div class="mapa-busca">
            <input type="search" id="buscar-moto" placeholder="Buscar motorizado por nombre, teléfono o placa..." autocomplete="off">
            <button type="button" id="btn-buscar-moto">Ubicar</button>
        </div>
    </div>
    <div class="mapa-cuerpo">
        <div id="mapa-flota"></div>
        <aside class="mapa-lado">
            <h4>Motorizados en línea</h4>
            <div id="lista-motos"><p class="mapa-vacio">Cargando flota...</p></div>
        </aside>
    </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="mapa_flota.js?v=4"></script>
<script>pintarMapaFlota('mapa-flota', { ajustar: true });</script>
</body>
</html>
