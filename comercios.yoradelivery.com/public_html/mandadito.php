<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['comercio_id'])) {
    header('Location: index.php');
    exit;
}
$comercio_id = (int) $_SESSION['comercio_id'];
yora_mant_exigir($conexion, 'comercios', $comercio_id);
yora_timezone($conexion);

$comercio = yora_one($conexion, 'SELECT * FROM comercios WHERE id = ?', 'i', $comercio_id);
if (!$comercio) {
    header('Location: index.php');
    exit;
}

$nombre_comercio = (string) ($comercio['nombre'] ?? 'Comercio');
$logo_comercio = yora_url_archivo($comercio['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png');
$credito = yora_credito_estado($conexion, $comercio_id);
$saldo = (float) ($credito['disponible'] ?? 0);
[$raw_lat, $raw_lng] = yora_coords_comercio($comercio);
$tiene_gps = yora_coords_ok($raw_lat, $raw_lng);
$rest_lat = $tiene_gps ? $raw_lat : 10.0645;
$rest_lng = $tiene_gps ? $raw_lng : -69.3569;
$dir_local = trim((string) ($comercio['direccion'] ?? 'Mi local'));
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mandadito | YoraB2B</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        :root { --yora-orange:#e4441b; --text-dark:#1e293b; --text-muted:#64748b; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:#f8fafc; color:var(--text-dark); min-height:100vh; }
        .top { background:#fff; border-bottom:1px solid #e2e8f0; padding:14px 20px; display:flex; align-items:center; gap:12px; }
        .top img { width:40px; height:40px; border-radius:10px; object-fit:cover; }
        .top a { color:var(--yora-orange); font-weight:700; text-decoration:none; font-size:0.9rem; }
        .wrap { max-width:720px; margin:0 auto; padding:20px 16px 40px; }
        .box { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:22px; }
        h1 { font-size:1.35rem; margin-bottom:6px; }
        .hint { color:var(--text-muted); font-size:0.88rem; margin-bottom:18px; line-height:1.45; }
        .form-group { margin-bottom:14px; position:relative; }
        .form-group label { display:block; font-size:0.85rem; font-weight:600; color:#475569; margin-bottom:5px; }
        .form-group input, .form-group textarea { width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:10px; font-size:0.9rem; background:#f8fafc; }
        .map { height:300px; border-radius:12px; border:1px solid #cbd5e1; margin-bottom:8px; }
        @media (max-width:900px) { .map { height:52vw; min-height:240px; max-height:340px; } }
        .precio { background:#fff5f5; border:1px dashed #fca5a5; padding:14px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin:16px 0; }
        .btn { background:linear-gradient(135deg,#e4441b,#c23310); color:#fff; width:100%; padding:14px; border:0; border-radius:10px; font-weight:800; cursor:pointer; font-size:1rem; }
        .saldo { font-size:0.85rem; color:#64748b; margin-top:10px; text-align:center; }
        .modos { display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
        .modos button { flex:1; min-width:120px; border:1px solid #e2e8f0; background:#f8fafc; padding:10px; border-radius:10px; font-weight:700; cursor:pointer; font-size:0.82rem; }
        .modos button.on { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
        .modos .btn-fs { background:#0f172a; color:#fff; border-color:#0f172a; flex:1.2; }
        .autocomplete-items { position:absolute; border:1px solid #e2e8f0; z-index:99; top:100%; left:0; right:0; border-radius:0 0 10px 10px; background:#fff; max-height:220px; overflow-y:auto; box-shadow:0 10px 15px rgba(0,0,0,0.1); display:none; }
        .autocomplete-items div { padding:10px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:0.85rem; }
        .autocomplete-items div:hover { background:#fef3c7; }
        .row-busca { display:flex; gap:8px; }
        .row-busca button { background:#1e293b; color:#fff; border:0; padding:0 14px; border-radius:10px; cursor:pointer; }
        .pin-txt { font-size:0.78rem; font-weight:600; color:#1e293b; margin:2px 0 8px; min-height:1.1em; }
        #mapa-fullscreen { display:none; position:fixed; inset:0; z-index:5000; background:#0f172a; flex-direction:column; }
        #mapa-fullscreen.on { display:flex; }
        #mapa-fullscreen .fs-top { display:flex; gap:10px; align-items:center; padding:12px 14px; background:#0f172a; color:#fff; position:relative; }
        #mapa-fullscreen .fs-top button { border:0; background:#1e293b; color:#fff; width:40px; height:40px; border-radius:12px; font-size:1.2rem; cursor:pointer; }
        #mapa-fullscreen .fs-top input { flex:1; border:0; border-radius:12px; padding:12px 14px; font-size:0.9rem; }
        #mapa-fs { flex:1; min-height:0; background:#e2e8f0; position:relative; }
        .fs-crosshair {
            position:absolute; left:50%; top:50%; width:44px; height:44px; margin:-44px 0 0 -22px;
            z-index:650; pointer-events:none; display:flex; align-items:flex-end; justify-content:center;
            filter:drop-shadow(0 3px 6px rgba(0,0,0,.35)); transition: transform .12s ease;
        }
        .fs-crosshair.pick::before {
            content:''; width:28px; height:28px; border-radius:50% 50% 50% 0; transform:rotate(-45deg);
            background:#16a34a; border:3px solid #fff;
        }
        .fs-crosshair.drop::before {
            content:''; width:28px; height:28px; border-radius:50% 50% 50% 0; transform:rotate(-45deg);
            background:#e4441b; border:3px solid #fff;
        }
        #mapa-fullscreen.dragging .fs-crosshair { transform: translateY(-10px); }
        #mapa-fullscreen .fs-modos { display:flex; gap:8px; padding:0 14px 10px; background:#0f172a; }
        #mapa-fullscreen .fs-modos button { flex:1; border:0; border-radius:10px; padding:10px; font-weight:700; font-size:0.8rem; cursor:pointer; background:#1e293b; color:#fff; }
        #mapa-fullscreen .fs-modos button.on { background:#e4441b; }
        #mapa-fullscreen .fs-bottom { padding:14px 16px 22px; background:#fff; border-radius:18px 18px 0 0; }
        #mapa-fullscreen .fs-bottom p { margin:0 0 6px; font-size:0.85rem; color:#334155; font-weight:600; }
        #mapa-fullscreen .fs-bottom .fs-hint { font-size:0.72rem; color:#94a3b8; font-weight:500; margin:0 0 12px; }
        #mapa-fullscreen .fs-bottom .btn-ok { width:100%; border:0; background:#e4441b; color:#fff; font-weight:800; padding:14px; border-radius:14px; font-size:1rem; cursor:pointer; }
        #fs-sugerencias { position:absolute; left:14px; right:14px; top:64px; background:#fff; border-radius:12px; max-height:180px; overflow:auto; z-index:10; display:none; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        #fs-sugerencias div { padding:12px; border-bottom:1px solid #f1f5f9; font-size:0.82rem; cursor:pointer; }
    </style>
</head>
<body>
    <div class="top">
        <img src="<?php echo yora_h($logo_comercio); ?>" alt="">
        <div style="flex:1;">
            <strong><?php echo yora_h($nombre_comercio); ?></strong>
            <div style="font-size:0.75rem; color:#64748b;">Mandadito</div>
        </div>
        <a href="dashboard.php">← Envíos</a>
    </div>
    <div class="wrap">
        <div class="box">
            <h1>Pedir un mandadito</h1>
            <p class="hint">Busca o marca en el mapa: <b>verde = recoger</b>, <b>naranja = entregar</b>. El precio es la ruta por calles.</p>
            <div class="form-group">
                <label>¿Qué debe recoger o comprar?</label>
                <textarea id="encargo" rows="3" placeholder="Ej: 2 resmas de papel, pedir factura a nombre del local"></textarea>
            </div>
            <div class="form-group">
                <label>Dirección de recogida</label>
                <div class="row-busca">
                    <input type="text" id="dir_rec" placeholder="Ej: Sambil, carrera 21 con 25…" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault(); buscarPin('pick');}" oninput="buscarPinLive('pick')">
                    <button type="button" onclick="buscarPin('pick')"><i class="ph ph-magnifying-glass"></i></button>
                </div>
                <div id="res_pick" class="autocomplete-items"></div>
                <p class="pin-txt" id="txt-pick">Mueve el pin verde o busca arriba</p>
            </div>
            <div class="form-group">
                <label>Dirección de entrega</label>
                <div class="row-busca">
                    <input type="text" id="dir_ent" value="<?php echo yora_h($dir_local); ?>" placeholder="Ej: mi local, carrera 17 con 27…" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault(); buscarPin('drop');}" oninput="buscarPinLive('drop')">
                    <button type="button" onclick="buscarPin('drop')"><i class="ph ph-magnifying-glass"></i></button>
                </div>
                <div id="res_drop" class="autocomplete-items"></div>
                <p class="pin-txt" id="txt-drop"><?php echo yora_h($dir_local ?: 'Tu local'); ?></p>
            </div>
            <div class="modos">
                <button type="button" id="modo-pick" class="on" onclick="setModo('pick')">1. Recogida</button>
                <button type="button" id="modo-drop" onclick="setModo('drop')">2. Entrega</button>
                <button type="button" class="btn-fs" onclick="abrirMapaGrande()"><i class="ph ph-arrows-out"></i> Mapa grande</button>
            </div>
            <div id="mapa" class="map"></div>
            <p class="hint" style="margin:0 0 8px;">Toca el mapa para el pin activo, o abre el mapa grande (más fácil en teléfono).</p>
            <div class="precio">
                <span id="txt-precio" style="font-size:0.88rem; color:#64748b;">Marca ambos pines para cotizar</span>
                <strong id="num-precio" style="font-size:1.3rem; color:#e4441b;">$0.00</strong>
            </div>
            <button type="button" class="btn" id="btn-mandar" onclick="enviarMandadito()">Publicar mandadito</button>
            <p class="saldo">Crédito disponible: $<?php echo number_format($saldo, 2); ?></p>
        </div>
    </div>

    <div id="mapa-fullscreen">
        <div class="fs-top">
            <button type="button" onclick="cerrarMapaGrande()" aria-label="Cerrar">←</button>
            <input id="fs-buscar" type="text" placeholder="Busca calle, cruce o sitio…" autocomplete="off"
                onkeydown="if(event.key==='Enter'){event.preventDefault(); buscarEnMapaGrande();}"
                oninput="buscarEnMapaGrandeLive()">
            <div id="fs-sugerencias"></div>
        </div>
        <div class="fs-modos">
            <button type="button" id="fs-modo-pick" class="on" onclick="setModoFs('pick')">Recogida (verde)</button>
            <button type="button" id="fs-modo-drop" onclick="setModoFs('drop')">Entrega (naranja)</button>
        </div>
        <div id="mapa-fs">
            <div class="fs-crosshair pick" id="fs-cross" aria-hidden="true"></div>
        </div>
        <div class="fs-bottom">
            <p id="fs-dir-txt">Mueve el mapa: el pin queda al centro</p>
            <p class="fs-hint">Cambia Recogida / Entrega arriba, luego confirma</p>
            <button type="button" class="btn-ok" onclick="confirmarMapaGrande()">Usar estos puntos</button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/js/yora_geo.js?v=2"></script>
    <script>
        const restLat = <?php echo json_encode((float) $rest_lat); ?>;
        const restLng = <?php echo json_encode((float) $rest_lng); ?>;
        let pickLat = restLat + 0.004, pickLng = restLng + 0.004;
        let dropLat = restLat, dropLng = restLng;
        let modo = 'pick';
        let rutaLinea = null;
        let silenciar = false;
        let mapFs = null;
        let fsIgnorarMove = false;
        let otroMkFs = null;

        const iconoPick = L.divIcon({ html:'<div style="background:#16a34a;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);"></div>', className:'', iconSize:[26,26], iconAnchor:[13,26] });
        const iconoDrop = L.divIcon({ html:'<div style="background:#e4441b;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);"></div>', className:'', iconSize:[26,26], iconAnchor:[13,26] });
        const map = L.map('mapa').setView([restLat, restLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(map);
        const pickMk = L.marker([pickLat, pickLng], { draggable:true, icon:iconoPick }).addTo(map).bindPopup('Recoger');
        const dropMk = L.marker([dropLat, dropLng], { draggable:true, icon:iconoDrop }).addTo(map).bindPopup('Entregar');

        function setModo(m) {
            modo = m;
            document.getElementById('modo-pick').classList.toggle('on', m === 'pick');
            document.getElementById('modo-drop').classList.toggle('on', m === 'drop');
        }
        function setModoFs(m) {
            setModo(m);
            document.getElementById('fs-modo-pick').classList.toggle('on', m === 'pick');
            document.getElementById('fs-modo-drop').classList.toggle('on', m === 'drop');
            const cross = document.getElementById('fs-cross');
            cross.classList.toggle('pick', m === 'pick');
            cross.classList.toggle('drop', m === 'drop');
            if (mapFs) {
                fsIgnorarMove = true;
                const ll = m === 'pick' ? [pickLat, pickLng] : [dropLat, dropLng];
                mapFs.setView(ll, Math.max(mapFs.getZoom(), 16));
                syncOtroMkFs();
                setTimeout(function () { fsIgnorarMove = false; }, 200);
            }
        }
        function syncOtroMkFs() {
            if (!mapFs) return;
            if (otroMkFs) { mapFs.removeLayer(otroMkFs); otroMkFs = null; }
            if (modo === 'pick') {
                otroMkFs = L.marker([dropLat, dropLng], { icon: iconoDrop, interactive: false }).addTo(mapFs);
            } else {
                otroMkFs = L.marker([pickLat, pickLng], { icon: iconoPick, interactive: false }).addTo(mapFs);
            }
        }
        function dibujarRuta(geo) {
            if (rutaLinea) { map.removeLayer(rutaLinea); rutaLinea = null; }
            if (!geo || !geo.length) return;
            const pts = geo.map(c => [c[1], c[0]]);
            rutaLinea = L.polyline(pts, { color:'#e4441b', weight:5, opacity:0.75 }).addTo(map);
            map.fitBounds(rutaLinea.getBounds(), { padding:[28,28] });
        }
        async function nombrar(cual) {
            const lat = cual === 'pick' ? pickLat : dropLat;
            const lng = cual === 'pick' ? pickLng : dropLng;
            const input = document.getElementById(cual === 'pick' ? 'dir_rec' : 'dir_ent');
            const txt = document.getElementById(cual === 'pick' ? 'txt-pick' : 'txt-drop');
            const fsTxt = document.getElementById('fs-dir-txt');
            if (txt) txt.textContent = 'Buscando nombre de la calle…';
            silenciar = true;
            try {
                const fd = new FormData();
                fd.append('lat', lat); fd.append('lng', lng);
                const r = await fetch('/api/reverse.php', { method: 'POST', body: fd });
                const d = await r.json();
                const nombre = (d.status === 'success' && d.nombre) ? d.nombre : (lat.toFixed(5) + ', ' + lng.toFixed(5));
                if (txt) txt.textContent = nombre;
                if (fsTxt && ((cual === 'pick' && modo === 'pick') || (cual === 'drop' && modo === 'drop'))) {
                    fsTxt.textContent = nombre;
                }
                if (input && (!input.value || input.dataset.auto === '1')) {
                    input.value = nombre;
                    input.dataset.auto = '1';
                }
            } catch (e) {
                if (txt) txt.textContent = 'Pin marcado';
            }
            setTimeout(function () { silenciar = false; }, 700);
        }
        async function cotizar() {
            const fd = new FormData();
            fd.append('lat_recogida', pickLat);
            fd.append('lng_recogida', pickLng);
            fd.append('lat', dropLat);
            fd.append('lng', dropLng);
            fd.append('ruta', '1');
            document.getElementById('txt-precio').textContent = 'Calculando ruta por calles…';
            try {
                const res = await fetch('/api/cotizar_mandadito.php', { method:'POST', body:fd });
                const data = await res.json();
                if (data.status === 'success') {
                    const etiqueta = (data.fuente === 'calles')
                        ? ('Ruta por calles: ' + data.km + ' km · ~' + data.minutos + ' min')
                        : ('Estimado: ' + data.km + ' km');
                    document.getElementById('txt-precio').textContent = etiqueta;
                    document.getElementById('num-precio').textContent = '$' + Number(data.costo).toFixed(2);
                    dibujarRuta(data.geometria);
                } else {
                    document.getElementById('txt-precio').textContent = data.mensaje || 'No se pudo cotizar';
                }
            } catch (e) {
                document.getElementById('txt-precio').textContent = 'Error al cotizar';
            }
        }
        function fijarPick(lat, lng, centrar) {
            pickLat = lat; pickLng = lng;
            pickMk.setLatLng([lat, lng]);
            if (centrar) map.setView([lat, lng], Math.max(map.getZoom(), 16));
            nombrar('pick');
            cotizar();
        }
        function fijarDrop(lat, lng, centrar) {
            dropLat = lat; dropLng = lng;
            dropMk.setLatLng([lat, lng]);
            if (centrar) map.setView([lat, lng], Math.max(map.getZoom(), 16));
            nombrar('drop');
            cotizar();
        }
        pickMk.on('dragend', function () {
            const p = pickMk.getLatLng(); fijarPick(p.lat, p.lng, false);
        });
        dropMk.on('dragend', function () {
            const p = dropMk.getLatLng(); fijarDrop(p.lat, p.lng, false);
        });
        map.on('click', function (e) {
            if (modo === 'pick') fijarPick(e.latlng.lat, e.latlng.lng, false);
            else fijarDrop(e.latlng.lat, e.latlng.lng, false);
        });

        function abrirMapaGrande() {
            const box = document.getElementById('mapa-fullscreen');
            box.classList.add('on');
            setModoFs(modo);
            setTimeout(function () {
                const ll = modo === 'pick' ? [pickLat, pickLng] : [dropLat, dropLng];
                if (!mapFs) {
                    mapFs = L.map('mapa-fs', { zoomControl: true }).setView(ll, 16);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(mapFs);
                    mapFs.on('dragstart zoomstart', function () { box.classList.add('dragging'); });
                    mapFs.on('moveend', function () {
                        box.classList.remove('dragging');
                        if (fsIgnorarMove) return;
                        const c = mapFs.getCenter();
                        if (modo === 'pick') fijarPick(c.lat, c.lng, false);
                        else fijarDrop(c.lat, c.lng, false);
                        syncOtroMkFs();
                    });
                } else {
                    fsIgnorarMove = true;
                    mapFs.setView(ll, Math.max(mapFs.getZoom(), 16));
                    setTimeout(function () { fsIgnorarMove = false; }, 200);
                }
                syncOtroMkFs();
                mapFs.invalidateSize();
                nombrar(modo);
            }, 60);
        }
        function cerrarMapaGrande() {
            const box = document.getElementById('mapa-fullscreen');
            box.classList.remove('on', 'dragging');
            if (mapFs) {
                const c = mapFs.getCenter();
                if (modo === 'pick') fijarPick(c.lat, c.lng, true);
                else fijarDrop(c.lat, c.lng, true);
            }
            setTimeout(function () { map.invalidateSize(); }, 80);
        }
        function confirmarMapaGrande() { cerrarMapaGrande(); }

        async function buscarPin(cual) {
            if (silenciar) return;
            const input = document.getElementById(cual === 'pick' ? 'dir_rec' : 'dir_ent');
            const lista = document.getElementById(cual === 'pick' ? 'res_pick' : 'res_drop');
            await yoraBuscarDireccion(input.value, lista, function (r, meta) {
                if (!meta || !meta.auto) {
                    input.value = r.nombre;
                    input.dataset.auto = '0';
                }
                if (cual === 'pick') {
                    fijarPick(r.lat, r.lng, true);
                    if (!meta || !meta.auto) setModo('drop');
                } else {
                    fijarDrop(r.lat, r.lng, true);
                }
            });
        }
        const _pickLive = yoraDebounce(function () { if (!silenciar) buscarPin('pick'); }, 450);
        const _dropLive = yoraDebounce(function () { if (!silenciar) buscarPin('drop'); }, 450);
        function buscarPinLive(cual) { (cual === 'pick' ? _pickLive : _dropLive)(); }

        async function buscarEnMapaGrande() {
            const input = document.getElementById('fs-buscar');
            const lista = document.getElementById('fs-sugerencias');
            await yoraBuscarDireccion(input.value, lista, function (r) {
                input.value = r.nombre;
                lista.style.display = 'none';
                if (modo === 'pick') fijarPick(r.lat, r.lng, true);
                else fijarDrop(r.lat, r.lng, true);
                if (mapFs) {
                    fsIgnorarMove = true;
                    mapFs.setView([r.lat, r.lng], 16);
                    syncOtroMkFs();
                    setTimeout(function () { fsIgnorarMove = false; }, 200);
                }
            });
        }
        const buscarEnMapaGrandeLive = yoraDebounce(buscarEnMapaGrande, 450);

        document.getElementById('dir_rec').addEventListener('input', function () { this.dataset.auto = '0'; });
        document.getElementById('dir_ent').addEventListener('input', function () { this.dataset.auto = '0'; });

        async function enviarMandadito() {
            const encargo = document.getElementById('encargo').value.trim();
            const dir = document.getElementById('dir_rec').value.trim();
            const dirE = document.getElementById('dir_ent').value.trim();
            if (!encargo) { alert('Describe el encargo.'); return; }
            if (!dir) { alert('Escribe o busca la dirección de recogida.'); return; }
            if (!dirE) { alert('Escribe o busca la dirección de entrega.'); return; }
            const btn = document.getElementById('btn-mandar');
            btn.disabled = true; btn.textContent = 'Publicando...';
            const fd = new FormData();
            fd.append('encargo', encargo);
            fd.append('direccionRecogida', dir);
            fd.append('direccionEntrega', dirE);
            fd.append('lat_recogida', pickLat);
            fd.append('lng_recogida', pickLng);
            fd.append('lat', dropLat);
            fd.append('lng', dropLng);
            try {
                const res = await fetch('/api/crear_mandadito.php', { method:'POST', body:fd });
                const data = await res.json();
                alert(data.mensaje || 'Listo');
                if (data.status === 'success') window.location.href = 'dashboard.php';
            } catch (e) { alert('Error al conectar.'); }
            btn.disabled = false; btn.textContent = 'Publicar mandadito';
        }

        nombrar('pick');
        nombrar('drop');
        cotizar();
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.form-group')) {
                document.getElementById('res_pick').style.display = 'none';
                document.getElementById('res_drop').style.display = 'none';
            }
            if (!e.target.closest('#mapa-fullscreen .fs-top')) {
                const fs = document.getElementById('fs-sugerencias');
                if (fs) fs.style.display = 'none';
            }
        });
    </script>
</body>
</html>
