<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$rows = yora_all($conexion, "SELECT * FROM comandas WHERE usuario_id = ? ORDER BY id DESC LIMIT 40", 'i', $uid) ?: [];
$activos = [];
$pasados = [];
foreach ($rows as $r) {
    $st = (string) ($r['estatus'] ?? '');
    if (!in_array($st, ['Entregado', 'Cancelado'], true)) {
        $activos[] = $r;
    } else {
        $pasados[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Pedidos | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=4">
    <style>
        .page { padding:18px 16px 90px; }
        .item { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px; margin-bottom:10px; }
        .item b { display:block; margin-bottom:4px; }
        .st { font-size:.75rem; font-weight:800; color:#e4441b; }
        .muted { color:#64748b; font-size:.82rem; }
        .live { border-color:#fdba74; box-shadow:0 8px 22px rgba(228,68,27,.08); }
        .live-map { height:220px; border-radius:14px; margin:10px 0; background:#e2e8f0; }
        .driver { display:flex; gap:10px; align-items:center; background:#f8fafc; border-radius:12px; padding:10px; margin-top:8px; }
        .driver img { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#fff; }
        .driver strong { display:block; font-size:.88rem; }
        .driver span { font-size:.75rem; color:#64748b; }
        .call { margin-left:auto; background:#ecfdf5; color:#047857; text-decoration:none; font-weight:800; font-size:.75rem; padding:8px 10px; border-radius:10px; }
        .legs { font-size:.8rem; color:#334155; margin-top:8px; }
        .legs div { margin-bottom:3px; }
    </style>
</head>
<body>
<div class="page">
    <div class="brand" style="font-size:1.2rem;">Yora<span>.</span></div>
    <h1>Tus mandaditos</h1>
    <p class="sub">Mira en vivo qué motorizado va por tu encargo y por dónde circula.</p>

    <div id="vivos">
        <?php if (!$activos): ?>
        <p class="muted">No tienes mandaditos en curso. <a href="dashboard.php" style="color:#e4441b;font-weight:800;">Pide uno</a>.</p>
        <?php else: foreach ($activos as $r): ?>
        <div class="item live" data-live="<?php echo (int) $r['id']; ?>">
            <b>#<?php echo yora_h($r['codigo'] ?: ('MD'.$r['id'])); ?> · $<?php echo number_format((float)$r['costo_delivery'], 2); ?></b>
            <div class="st" id="st-<?php echo (int)$r['id']; ?>"><?php echo yora_h($r['estatus']); ?></div>
            <div class="legs">
                <div><b>Solicita:</b> <?php echo yora_h($r['cliente_nombre'] ?: 'Tú'); ?></div>
                <div><b>Qué retirar:</b> <?php echo yora_h($r['detalles_entrega']); ?></div>
                <div><b>Retiro:</b> <?php echo yora_h($r['direccion_recogida'] ?: 'Punto de recogida'); ?></div>
                <div><b>Entrega:</b> <?php echo yora_h(yora_texto_destino($r['direccion_entrega'] ?? '')); ?></div>
            </div>
            <div class="live-map" id="map-<?php echo (int)$r['id']; ?>"></div>
            <div class="driver" id="drv-<?php echo (int)$r['id']; ?>">
                <span class="muted">Buscando motorizado…</span>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($pasados): ?>
    <h2 style="font-size:1rem; margin:18px 0 10px;">Anteriores</h2>
    <?php foreach ($pasados as $r): ?>
        <div class="item">
            <b>#<?php echo yora_h($r['codigo'] ?: ('MD'.$r['id'])); ?> · $<?php echo number_format((float)$r['costo_delivery'], 2); ?></b>
            <div class="st"><?php echo yora_h($r['estatus']); ?><?php echo !empty($r['tipo_pago']) ? ' · ' . yora_h($r['tipo_pago']) : ''; ?></div>
            <div class="muted" style="margin-top:6px;">De: <?php echo yora_h($r['direccion_recogida'] ?: 'Recogida'); ?></div>
            <div class="muted">A: <?php echo yora_h(yora_texto_destino($r['direccion_entrega'] ?? '')); ?></div>
            <div class="muted"><?php echo yora_h($r['detalles_entrega']); ?></div>
        </div>
    <?php endforeach; endif; ?>
</div>
<nav class="nav">
    <a href="dashboard.php"><i class="ph ph-map-pin"></i>Pedir</a>
    <a class="on" href="pedidos.php"><i class="ph ph-clock-counter-clockwise"></i>Pedidos</a>
    <a href="billetera.php"><i class="ph ph-wallet"></i>Billetera</a>
    <a href="ajustes.php"><i class="ph ph-gear"></i>Ajustes</a>
</nav>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
    const mapas = {};
    const mk = {};
    function ico(color){
        return L.divIcon({
            className:'',
            iconSize:[18,18],
            iconAnchor:[9,9],
            html:'<div style="width:16px;height:16px;border-radius:50%;background:'+color+';border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>'
        });
    }
    function asegurarMapa(id, lat, lng){
        if (mapas[id]) return mapas[id];
        const el = document.getElementById('map-'+id);
        if (!el) return null;
        const map = L.map(el, { zoomControl:false }).setView([lat||10.067, lng||-69.347], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19 }).addTo(map);
        mapas[id] = map;
        mk[id] = {};
        setTimeout(function(){ map.invalidateSize(); }, 200);
        return map;
    }
    function pin(id, key, lat, lng, color, txt){
        if (lat == null || lng == null) return;
        const map = asegurarMapa(id, lat, lng);
        if (!map) return;
        if (mk[id][key]) mk[id][key].setLatLng([lat,lng]);
        else mk[id][key] = L.marker([lat,lng], { icon: ico(color) }).addTo(map).bindTooltip(txt, { permanent:false });
    }
    function pintarDriver(p){
        const box = document.getElementById('drv-'+p.id);
        const st = document.getElementById('st-'+p.id);
        if (st) st.textContent = p.estatus + (p.pago ? ' · ' + p.pago : '');
        if (!box) return;
        if (!p.conductor) {
            box.innerHTML = '<span class="muted">Buscando motorizado en el radar…</span>';
            return;
        }
        const d = p.conductor;
        const tel = d.telefono ? '<a class="call" href="tel:'+d.telefono+'">Llamar</a>' : '';
        box.innerHTML = '<img src="'+d.foto+'" alt="">'
            + '<div><strong>'+ (d.nombre || 'Motorizado') +'</strong>'
            + '<span>'+ (p.estatus || '') + (d.gps ? ' · GPS '+d.gps : ' · esperando GPS') +'</span>'
            + (d.vehiculo ? '<span>'+d.vehiculo+'</span>' : '') + '</div>' + tel;
    }
    async function tick(){
        try {
            const r = await fetch('/api/rastreo_pedido.php', { credentials:'same-origin' });
            const d = await r.json();
            (d.pedidos || []).forEach(function(p){
                pintarDriver(p);
                const pts = [];
                if (p.retiro && p.retiro.lat) { pin(p.id, 'pick', p.retiro.lat, p.retiro.lng, '#16a34a', 'Retiro'); pts.push([p.retiro.lat, p.retiro.lng]); }
                if (p.entrega && p.entrega.lat) { pin(p.id, 'drop', p.entrega.lat, p.entrega.lng, '#e4441b', 'Entrega'); pts.push([p.entrega.lat, p.entrega.lng]); }
                if (p.conductor && p.conductor.lat) { pin(p.id, 'yo', p.conductor.lat, p.conductor.lng, '#2563eb', p.conductor.nombre || 'Driver'); pts.push([p.conductor.lat, p.conductor.lng]); }
                const map = mapas[p.id];
                if (map && pts.length) {
                    try { map.fitBounds(pts, { padding:[28,28], maxZoom:16 }); } catch(e) {}
                    map.invalidateSize();
                }
            });
        } catch(e) {}
    }
    tick();
    setInterval(tick, 4000);
})();
</script>
</body>
</html>
