<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}
$uid = (int) $_SESSION['usuario_id'];
yora_mant_exigir($conexion, 'clientes', $uid);
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
if (!$user) {
    yora_logout('index.php');
}
$nombre = (string) $user['nombre'];
$docs = yora_cliente_docs_estado($user);
$saldo = (float) ($user['billetera'] ?? 0);
$tasa = (float) (yora_tasa_bcv($conexion)['tasa'] ?? 0);
$pago_hq = yora_pago_hq();
$lat0 = yora_coords_ok((float) ($user['lat'] ?? 0), (float) ($user['lng'] ?? 0)) ? (float) $user['lat'] : 10.067;
$lng0 = yora_coords_ok((float) ($user['lat'] ?? 0), (float) ($user['lng'] ?? 0)) ? (float) $user['lng'] : -69.347;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#e4441b">
    <title>Pedir | Yora</title>
    <link rel="manifest" href="/manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=3">
    <style>
        html, body { height:100%; overflow:hidden; }
        .app { height:100%; display:flex; flex-direction:column; padding-bottom:58px; }
        .map-wrap { position:relative; height:38vh; min-height:220px; background:#e2e8f0; }
        #mapa { position:absolute; inset:0; width:100%; height:100%; }
        .gps { position:absolute; right:12px; bottom:12px; z-index:25; width:44px; height:44px; border:0; border-radius:14px; background:#fff; box-shadow:0 8px 20px rgba(15,23,42,.16); cursor:pointer; }
        .panel { flex:1; background:#fff; overflow:auto; padding:14px 16px 20px; }
        .hello { font-size:.86rem; color:#64748b; margin-bottom:12px; }
        .hello b { color:#0f172a; }
        .addr { display:flex; gap:10px; align-items:flex-start; margin-bottom:8px; position:relative; }
        .dots { width:18px; padding-top:12px; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .dot-g { width:10px; height:10px; background:#16a34a; border-radius:50%; }
        .line { width:2px; flex:1; min-height:18px; background:#e2e8f0; }
        .dot-o { width:10px; height:10px; background:#e4441b; border-radius:50%; }
        .addr-fields { flex:1; min-width:0; }
        .addr input { margin-bottom:8px; padding:11px 12px; font-size:.88rem; }
        .autocomplete-items { position:absolute; left:28px; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:12px; z-index:30; max-height:170px; overflow:auto; display:none; box-shadow:0 10px 24px rgba(15,23,42,.12); }
        .autocomplete-items div { padding:10px; font-size:.82rem; border-bottom:1px solid #f1f5f9; cursor:pointer; }
        .precio { display:flex; justify-content:space-between; align-items:center; background:#fff7ed; border-radius:14px; padding:12px; margin:10px 0; gap:10px; }
        .precio span { font-size:.82rem; color:#9a3412; }
        .pay { margin-top:10px; }
        .pay-opts { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0; }
        .pay-opts label { flex:1; min-width:90px; border:1.5px solid #e2e8f0; border-radius:12px; padding:8px 8px; font-size:.72rem; font-weight:700; text-align:center; cursor:pointer; background:#f8fafc; }
        .pay-opts label.on { border-color:#e4441b; background:#fff7ed; color:#9a3412; }
        .pay-opts input { display:none; }
        .pay-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; font-size:.78rem; color:#334155; display:none; margin-top:8px; }
        .pay-box b { display:block; margin-bottom:4px; }
        .pay-box .row { display:flex; justify-content:space-between; gap:8px; margin:4px 0; }
        .pay { text-align:center; font-size:.75rem; color:#94a3b8; margin-top:8px; }
        .map-hint { position:absolute; left:12px; top:12px; z-index:25; background:#fff; border-radius:10px; padding:6px 10px; font-size:.72rem; font-weight:700; color:#475569; box-shadow:0 6px 16px rgba(15,23,42,.12); }
        @media (min-width:900px) {
            .app { flex-direction:row; }
            .panel { width:420px; flex:0 0 420px; height:100%; border-right:1px solid #e2e8f0; }
            .map-wrap { flex:1; height:100%; min-height:0; }
        }
    </style>
</head>
<body>
<div class="app">
    <div class="map-wrap">
        <div id="mapa"></div>
        <div class="map-hint">Verde = recoger · Naranja = entregar</div>
        <button class="gps" type="button" onclick="miUbicacion()" title="Mi ubicación"><i class="ph ph-crosshair" style="font-size:1.3rem;"></i></button>
    </div>
    <div class="panel">
        <?php if (!$docs['puede_pedir']): ?>
        <a href="ajustes_documentos.php" class="ax-note warn" style="display:block;text-decoration:none;margin-bottom:12px;">Verifica tu identidad (selfie + cédula) para pedir mandaditos. Toca aquí.</a>
        <?php elseif ($docs['global'] === 'En Revisión'): ?>
        <div class="ax-note info" style="margin-bottom:12px;">Tu verificación está en revisión. Mientras tanto ya puedes pedir.</div>
        <?php endif; ?>
        <div class="hello">Hola, <b><?php echo yora_h(explode(' ', $nombre)[0]); ?></b> · ¿A dónde lo mandamos?</div>
        <div class="addr">
            <div class="dots"><span class="dot-g"></span><span class="line"></span><span class="dot-o"></span></div>
            <div class="addr-fields">
                <input id="dir_rec" placeholder="Recoger en…  (Sambil, carrera 19 con 25)" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault(); buscar('pick');}" oninput="livePick()">
                <div id="res_pick" class="autocomplete-items" style="top:46px;"></div>
                <input id="dir_ent" placeholder="Entregar en…  (tu casa, carrera 17 con 27)" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault(); buscar('drop');}" oninput="liveDrop()">
                <div id="res_drop" class="autocomplete-items" style="top:100px;"></div>
            </div>
        </div>
        <textarea id="encargo" rows="2" placeholder="¿Qué recogen? Ej: una receta en la farmacia"></textarea>
        <div class="precio"><span id="txt">Marca recoger (verde) y entregar (naranja)</span><b id="num">$0.00</b></div>
        <div class="pay-opts">
            <label class="on"><input type="radio" name="pago" value="Billetera" checked onchange="verPago()">Billetera<br><small id="saldo-lbl">$<?php echo number_format($saldo, 2); ?></small></label>
            <label><input type="radio" name="pago" value="Pago Móvil" onchange="verPago()">Pago Móvil</label>
            <label><input type="radio" name="pago" value="Efectivo" onchange="verPago()">Efectivo</label>
        </div>
        <div class="pay-box" id="box-pm">
            <b>Pago Móvil a Yora</b>
            <div class="row"><span>🏦 <?php echo yora_h($pago_hq['banco']); ?> (<?php echo yora_h($pago_hq['codigo']); ?>)</span></div>
            <div class="row"><span>📱 <?php echo yora_h($pago_hq['telefono']); ?></span><a href="#" onclick="navigator.clipboard.writeText('<?php echo yora_h($pago_hq['tel_num']); ?>');alert('Copiado');return false;" style="color:#16a34a;font-weight:800;">Copiar</a></div>
            <div class="row"><span>🪪 <?php echo yora_h($pago_hq['cedula']); ?></span><a href="#" onclick="navigator.clipboard.writeText('<?php echo yora_h($pago_hq['ced_num']); ?>');alert('Copiado');return false;" style="color:#16a34a;font-weight:800;">Copiar</a></div>
            <div class="row"><span>Transferir</span><b id="bs-pago" style="color:#16a34a;">Bs. 0.00</b></div>
            <input id="ref_pago" placeholder="Número de referencia" style="margin-top:8px;">
        </div>
        <div class="pay-box" id="box-ef">
            <b>Efectivo al motorizado</b>
            <p>Le pagas el total en efectivo cuando te entregue el encargo.</p>
        </div>
        <button class="btn" type="button" id="go" onclick="pedir()">Pedir motorizado</button>
        <p class="pay">Billetera y Pago Móvil se validan con Yora. El efectivo se lo das al motorizado.</p>
    </div>
</div>
<nav class="nav">
    <a class="on" href="dashboard.php"><i class="ph ph-map-pin"></i>Pedir</a>
    <a href="pedidos.php"><i class="ph ph-clock-counter-clockwise"></i>Pedidos</a>
    <a href="billetera.php"><i class="ph ph-wallet"></i>Billetera</a>
    <a href="ajustes.php"><i class="ph ph-gear"></i>Ajustes</a>
</nav>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="/js/yora_geo.js?v=2"></script>
<script>
    const CSRF = <?php echo json_encode(yora_csrf_token()); ?>;
    const lat0 = <?php echo json_encode($lat0); ?>, lng0 = <?php echo json_encode($lng0); ?>;
    const SALDO = <?php echo json_encode($saldo); ?>;
    const TASA = <?php echo json_encode($tasa); ?>;
    let lastCosto = 0;
    let silenciarBusqueda = false;
    let pickLat = lat0 + 0.0025, pickLng = lng0 + 0.0025, dropLat = lat0, dropLng = lng0, linea = null;
    const icoP = L.divIcon({ html:'<div style="background:#16a34a;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>', className:'', iconSize:[26,26], iconAnchor:[13,26] });
    const icoD = L.divIcon({ html:'<div style="background:#e4441b;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>', className:'', iconSize:[26,26], iconAnchor:[13,26] });
    const map = L.map('mapa', { zoomControl:true }).setView([lat0, lng0], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'© OSM' }).addTo(map);
    const mkP = L.marker([pickLat, pickLng], { draggable:true, icon:icoP }).addTo(map);
    const mkD = L.marker([dropLat, dropLng], { draggable:true, icon:icoD }).addTo(map);

    function enLara(lat, lng){ return lat > 9.92 && lat < 10.28 && lng > -69.58 && lng < -69.12; }
    function ajustarMapa(){
        map.invalidateSize();
        try { map.fitBounds(L.latLngBounds([mkP.getLatLng(), mkD.getLatLng()]), { padding:[40,40], maxZoom:15 }); } catch(e) {}
    }
    function ruta(geo){ if(linea){ map.removeLayer(linea); linea=null;} if(!geo||!geo.length) return; linea=L.polyline(geo.map(c=>[c[1],c[0]]), {color:'#e4441b', weight:5, opacity:.8}).addTo(map); }
    async function cotizar(){
        const fd=new FormData(); fd.append('_csrf', CSRF); fd.append('lat_recogida',pickLat); fd.append('lng_recogida',pickLng); fd.append('lat',dropLat); fd.append('lng',dropLng); fd.append('ruta','1');
        document.getElementById('txt').textContent='Calculando ruta por calles…';
        try{
            const r=await fetch('/api/cotizar.php',{method:'POST',body:fd}); const d=await r.json();
            if(d.status==='success'){
                lastCosto = Number(d.costo)||0;
                document.getElementById('txt').textContent=(d.fuente==='calles'?'Por calles: ':'Est.: ')+d.km+' km · ~'+d.minutos+' min';
                document.getElementById('num').textContent='$'+lastCosto.toFixed(2);
                ruta(d.geometria);
                verPago();
            }
            else { document.getElementById('txt').textContent=d.mensaje||'No se pudo cotizar'; document.getElementById('num').textContent='$0.00'; }
        }catch(e){ document.getElementById('txt').textContent='Error al cotizar'; }
    }
    async function nombrar(cual){
        const lat = cual==='pick'?pickLat:dropLat;
        const lng = cual==='pick'?pickLng:dropLng;
        const input = document.getElementById(cual==='pick'?'dir_rec':'dir_ent');
        const fd=new FormData(); fd.append('_csrf', CSRF); fd.append('lat', lat); fd.append('lng', lng);
        silenciarBusqueda = true;
        try{
            const r=await fetch('/api/reverse.php',{method:'POST',body:fd}); const d=await r.json();
            if(d.status==='success' && d.nombre){
                input.value = d.nombre;
            } else if (!input.value) {
                input.value = lat.toFixed(5) + ', ' + lng.toFixed(5);
            }
        }catch(e){
            if (!input.value) input.value = lat.toFixed(5) + ', ' + lng.toFixed(5);
        }
        setTimeout(function(){ silenciarBusqueda = false; }, 800);
    }
    mkP.on('dragend',()=>{ const p=mkP.getLatLng(); pickLat=p.lat; pickLng=p.lng; nombrar('pick'); cotizar(); });
    mkD.on('dragend',()=>{ const p=mkD.getLatLng(); dropLat=p.lat; dropLng=p.lng; nombrar('drop'); cotizar(); });
    map.on('click', e=>{
        const dp = map.distance(e.latlng, mkP.getLatLng());
        const dd = map.distance(e.latlng, mkD.getLatLng());
        if (dp <= dd) { pickLat=e.latlng.lat; pickLng=e.latlng.lng; mkP.setLatLng(e.latlng); nombrar('pick'); }
        else { dropLat=e.latlng.lat; dropLng=e.latlng.lng; mkD.setLatLng(e.latlng); nombrar('drop'); }
        cotizar();
    });
    async function buscar(cual){
        if (silenciarBusqueda) return;
        const input = document.getElementById(cual==='pick'?'dir_rec':'dir_ent');
        const lista = document.getElementById(cual==='pick'?'res_pick':'res_drop');
        await yoraBuscarDireccion(input.value, lista, function(r, meta){
            if (silenciarBusqueda) return;
            if (!meta || !meta.auto) input.value = r.nombre;
            if (cual==='pick'){ pickLat=r.lat; pickLng=r.lng; mkP.setLatLng([r.lat,r.lng]); }
            else { dropLat=r.lat; dropLng=r.lng; mkD.setLatLng([r.lat,r.lng]); }
            map.setView([r.lat,r.lng],16); map.invalidateSize(); cotizar();
        });
    }
    const livePick = yoraDebounce(function(){ if (!silenciarBusqueda) buscar('pick'); }, 450);
    const liveDrop = yoraDebounce(function(){ if (!silenciarBusqueda) buscar('drop'); }, 450);
    function miUbicacion(){
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function(pos){
            if (!enLara(pos.coords.latitude, pos.coords.longitude)) { alert('Tu GPS no está en Lara. Coloca el pin naranja a mano.'); return; }
            dropLat = pos.coords.latitude; dropLng = pos.coords.longitude;
            mkD.setLatLng([dropLat, dropLng]);
            map.setView([dropLat, dropLng], 16);
            nombrar('drop');
            if (!document.getElementById('dir_ent').value) document.getElementById('dir_ent').value = 'Mi ubicación';
            ajustarMapa(); cotizar();
        });
    }
    function metodoPago(){
        const el = document.querySelector('input[name="pago"]:checked');
        return el ? el.value : 'Billetera';
    }
    function verPago(){
        const m = metodoPago();
        document.querySelectorAll('.pay-opts label').forEach(function(l){ l.classList.toggle('on', l.querySelector('input').value===m); });
        document.getElementById('box-pm').style.display = m==='Pago Móvil' ? 'block' : 'none';
        document.getElementById('box-ef').style.display = m==='Efectivo' ? 'block' : 'none';
        if (TASA > 0 && lastCosto > 0) {
            document.getElementById('bs-pago').textContent = 'Bs. ' + (lastCosto * TASA).toFixed(2);
        }
    }
    async function pedir(){
        const enc=document.getElementById('encargo').value.trim();
        const nomPick = document.getElementById('dir_rec').value.trim() || 'Punto de recogida';
        const nomDrop = document.getElementById('dir_ent').value.trim() || 'Destino';
        if(!enc){ alert('Describe qué deben recoger.'); return; }
        const pago = metodoPago();
        if (pago === 'Billetera' && lastCosto > SALDO + 0.001) {
            alert('Tu billetera no alcanza. Recárgala o paga este viaje con Pago Móvil.');
            return;
        }
        let ref = '';
        if (pago === 'Pago Móvil') ref = document.getElementById('ref_pago').value.trim();
        if (pago === 'Pago Móvil' && !ref) { alert('Coloca la referencia del pago para que Yora lo verifique.'); return; }
        const btn=document.getElementById('go'); btn.disabled=true; btn.textContent='Publicando…';
        const fd=new FormData();
        fd.append('_csrf', CSRF);
        fd.append('encargo',enc);
        fd.append('direccionRecogida', nomPick);
        fd.append('direccionEntrega', nomDrop);
        fd.append('lat_recogida',pickLat); fd.append('lng_recogida',pickLng);
        fd.append('lat',dropLat); fd.append('lng',dropLng);
        fd.append('tipo_pago', pago);
        fd.append('referencia', ref);
        try{
            const r=await fetch('/api/crear_mandadito.php',{method:'POST',body:fd}); const d=await r.json();
            alert(d.mensaje||'Listo');
            if(d.status==='success') location.href='pedidos.php';
        }catch(e){ alert('Error al conectar'); }
        btn.disabled=false; btn.textContent='Pedir motorizado';
    }
    window.addEventListener('resize', function(){ map.invalidateSize(); });
    setTimeout(function(){ ajustarMapa(); nombrar('pick'); nombrar('drop'); cotizar(); }, 200);
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos){
            if (!enLara(pos.coords.latitude, pos.coords.longitude)) { ajustarMapa(); cotizar(); return; }
            dropLat = pos.coords.latitude; dropLng = pos.coords.longitude;
            mkD.setLatLng([dropLat, dropLng]);
            nombrar('drop');
            ajustarMapa(); cotizar();
        }, function(){ ajustarMapa(); cotizar(); });
    }
    document.addEventListener('click', function(e){
        if (!e.target.closest('.addr')) {
            document.getElementById('res_pick').style.display='none';
            document.getElementById('res_drop').style.display='none';
        }
    });
</script>
</body>
</html>
