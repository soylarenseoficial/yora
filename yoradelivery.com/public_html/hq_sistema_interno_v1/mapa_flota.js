function pintarMapaFlota(id, opts) {
    opts = opts || {};
    const el = document.getElementById(id);
    if (!el || typeof L === 'undefined') return;

    if (!document.getElementById('yora-moto-css')) {
        const css = document.createElement('style');
        css.id = 'yora-moto-css';
        css.textContent = `
        .yora-moto { position:relative; width:56px; height:72px; pointer-events:none; }
        .yora-moto-pulse {
            position:absolute; left:50%; top:22px; width:42px; height:42px; margin-left:-21px; margin-top:-21px;
            border-radius:50%; background:rgba(206,78,45,.28); transform:scale(.6); opacity:.9;
        }
        .yora-moto.vivo .yora-moto-pulse { animation: yoraPulse 1.6s ease-out infinite; }
        .yora-moto.stale .yora-moto-pulse { display:none; }
        .yora-moto-pin {
            position:absolute; left:50%; top:6px; width:44px; height:44px; margin-left:-22px;
            border-radius:50%; overflow:hidden; border:3px solid #fff;
            box-shadow:0 6px 16px rgba(15,23,42,.28); background:#ce4e2d;
        }
        .yora-moto-dir {
            position:absolute; left:50%; top:0; width:0; height:0; margin-left:-7px;
            border-left:7px solid transparent; border-right:7px solid transparent;
            border-bottom:10px solid #ce4e2d; transform-origin:50% 32px;
            transform:rotate(var(--rot, 0deg)); filter:drop-shadow(0 1px 2px rgba(0,0,0,.35));
        }
        .yora-moto.vivo .yora-moto-pin { border-color:#fff; box-shadow:0 6px 16px rgba(206,78,45,.35), 0 0 0 3px rgba(206,78,45,.25); }
        .yora-moto.stale .yora-moto-pin { filter:grayscale(.35); border-color:#e2e8f0; }
        .yora-moto-pin img { width:100%; height:100%; object-fit:cover; display:block; }
        .yora-moto-ico {
            position:absolute; right:-2px; bottom:-2px; width:20px; height:20px; border-radius:50%;
            background:#0f172a; color:#fff; font-size:11px; line-height:20px; text-align:center;
            border:2px solid #fff;
        }
        .yora-moto-name {
            position:absolute; left:50%; bottom:0; transform:translateX(-50%);
            background:#0f172a; color:#fff; font:700 10px/1 Poppins,sans-serif;
            padding:3px 7px; border-radius:999px; white-space:nowrap; max-width:110px;
            overflow:hidden; text-overflow:ellipsis; box-shadow:0 4px 10px rgba(15,23,42,.2);
        }
        .yora-moto.sel .yora-moto-pin { box-shadow:0 0 0 4px #ce4e2d, 0 8px 18px rgba(15,23,42,.35); }
        @keyframes yoraPulse {
            0% { transform:scale(.55); opacity:.55; }
            100% { transform:scale(1.85); opacity:0; }
        }
        .yora-pop { min-width:190px; font-family:Poppins,sans-serif; }
        .yora-pop-top { display:flex; gap:10px; align-items:center; margin-bottom:8px; }
        .yora-pop-top img { width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid #ce4e2d; }
        .yora-pop b { display:block; font-size:.95rem; color:#0f172a; }
        .yora-pop small { color:#64748b; }
        .yora-chip { display:inline-block; margin-top:6px; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; }
        .yora-chip.on { background:#dcfce7; color:#166534; }
        .yora-chip.off { background:#f1f5f9; color:#64748b; }
        `;
        document.head.appendChild(css);
    }

    const map = L.map(id, { zoomControl: true }).setView([10.0645, -69.3569], 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19
    }).addTo(map);

    const capas = {};
    const circulos = {};
    const rumboCalc = {};
    let listaCache = [];
    let primerAjuste = true;
    let seguirId = null;
    let animando = {};

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function textoDriver(d) {
        return ((d.nombre || '') + ' ' + (d.telefono || '') + ' ' + (d.placa || '') + ' ' + (d.vehiculo || '')).toLowerCase();
    }
    function rumbo(a, b, c, d) {
        const r1 = a * Math.PI / 180, r2 = c * Math.PI / 180, dl = (d - b) * Math.PI / 180;
        const y = Math.sin(dl) * Math.cos(r2);
        const x = Math.cos(r1) * Math.sin(r2) - Math.sin(r1) * Math.cos(r2) * Math.cos(dl);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }
    function haceTexto(s) {
        if (s == null) return 'Sin GPS';
        if (s < 8) return 'En vivo';
        if (s < 60) return 'Hace ' + s + ' s';
        if (s < 3600) return 'Hace ' + Math.round(s / 60) + ' min';
        return 'GPS viejo';
    }
    function iconoMoto(d, heading) {
        const vivo = !!d.vivo;
        const sel = seguirId === d.id;
        const rot = (typeof heading === 'number' && isFinite(heading)) ? heading : 0;
        const html = '<div class="yora-moto ' + (vivo ? 'vivo' : 'stale') + (sel ? ' sel' : '') + '" style="--rot:' + rot + 'deg">'
            + '<div class="yora-moto-pulse"></div>'
            + '<div class="yora-moto-dir"></div>'
            + '<div class="yora-moto-pin"><img src="' + esc(d.foto) + '" alt=""><span class="yora-moto-ico">🛵</span></div>'
            + '<div class="yora-moto-name">' + esc((d.nombre || 'Driver').split(' ')[0]) + '</div>'
            + '</div>';
        return L.divIcon({ html: html, className: 'yora-moto-wrap', iconSize: [56, 72], iconAnchor: [28, 36] });
    }
    function htmlPopup(d) {
        const vivo = !!d.vivo;
        return '<div class="yora-pop"><div class="yora-pop-top">'
            + '<img src="' + esc(d.foto) + '" alt="">'
            + '<div><b>' + esc(d.nombre || 'Driver') + '</b>'
            + '<small>' + esc(d.vehiculo || 'moto') + (d.placa ? ' · ' + esc(d.placa) : '') + '</small>'
            + '<small style="display:block">' + esc(d.telefono || '') + '</small></div></div>'
            + '<span class="yora-chip ' + (vivo ? 'on' : 'off') + '">' + (vivo ? '● ' : '') + haceTexto(d.hace) + '</span>'
            + (d.acc ? '<small style="display:block;margin-top:6px;color:#64748b">Precisión ±' + Math.round(d.acc) + ' m</small>' : '')
            + '</div>';
    }
    function moverSuave(marker, lat, lng, ms) {
        const from = marker.getLatLng();
        const dist = map.distance(from, L.latLng(lat, lng));
        if (dist < 1.5) {
            marker.setLatLng([lat, lng]);
            return;
        }
        const dur = dist > 400 ? Math.min(ms * 1.4, 2800) : ms;
        const key = marker._leaflet_id;
        const t0 = performance.now();
        animando[key] = t0;
        function frame(now) {
            if (animando[key] !== t0) return;
            const t = Math.min(1, (now - t0) / dur);
            const e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            marker.setLatLng([
                from.lat + (lat - from.lat) * e,
                from.lng + (lng - from.lng) * e
            ]);
            if (t < 1) requestAnimationFrame(frame);
            else delete animando[key];
        }
        requestAnimationFrame(frame);
    }

    function pintarLista(filtro) {
        const caja = document.getElementById('lista-motos');
        const nFiltro = document.getElementById('n-filtro');
        if (!caja) return;
        const q = (filtro || '').trim().toLowerCase();
        const vis = listaCache.filter(function (d) { return !q || textoDriver(d).indexOf(q) !== -1; });
        if (nFiltro) nFiltro.textContent = String(vis.length);
        if (!vis.length) {
            caja.innerHTML = '<p class="mapa-vacio">Ningún motorizado coincide con la búsqueda.</p>';
            return;
        }
        caja.innerHTML = vis.map(function (d) {
            const gps = (d.lat != null && d.lng != null);
            const on = seguirId === d.id ? ' on' : '';
            return '<button type="button" class="mapa-item' + on + '" data-id="' + d.id + '">'
                + '<img src="' + esc(d.foto) + '" alt="">'
                + '<span><b>' + esc(d.nombre || 'Driver') + '</b>'
                + '<small>' + esc(d.placa || d.vehiculo || '') + (d.telefono ? ' · ' + esc(d.telefono) : '') + '</small>'
                + '<small class="' + (d.vivo ? 'ok' : (gps ? 'off' : 'off')) + '">'
                + (gps ? haceTexto(d.hace) : 'Sin ubicación') + '</small></span></button>';
        }).join('');
        caja.querySelectorAll('.mapa-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                ubicarMotorizado(parseInt(btn.getAttribute('data-id'), 10));
            });
        });
    }

    function ubicarMotorizado(id) {
        const d = listaCache.find(function (x) { return Number(x.id) === Number(id); });
        if (!d) return;
        if (d.lat == null || d.lng == null) {
            alert('Ese motorizado está en línea pero todavía no envía GPS.');
            return;
        }
        seguirId = d.id;
        map.setView([d.lat, d.lng], 17, { animate: true });
        if (capas[d.id]) {
            capas[d.id].setIcon(iconoMoto(d, rumboCalc[d.id]));
            capas[d.id].openPopup();
        }
        pintarLista(document.getElementById('buscar-moto') ? document.getElementById('buscar-moto').value : '');
    }

    function buscarMotorizado(q) {
        pintarLista(q);
        const query = (q || '').trim().toLowerCase();
        if (!query) { seguirId = null; return null; }
        const hit = listaCache.find(function (d) {
            return textoDriver(d).indexOf(query) !== -1 && d.lat != null && d.lng != null;
        }) || listaCache.find(function (d) { return textoDriver(d).indexOf(query) !== -1; });
        if (hit) ubicarMotorizado(hit.id);
        return hit;
    }

    map.on('dragstart', function () { seguirId = null; });

    async function cargar() {
        try {
            const res = await fetch('gps_flota.php', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            listaCache = (data && data.drivers) ? data.drivers : [];
            const n = document.getElementById('n-linea');
            if (n) n.textContent = String(listaCache.length);
            const vistos = {};
            const bounds = [];
            listaCache.forEach(function (d) {
                if (d.lat == null || d.lng == null) return;
                vistos[d.id] = true;
                bounds.push([d.lat, d.lng]);
                let heading = d.heading;
                if ((heading == null || heading < 0) && capas[d.id]) {
                    const prev = capas[d.id].getLatLng();
                    const metros = map.distance(prev, L.latLng(d.lat, d.lng));
                    if (metros > 4) heading = rumbo(prev.lat, prev.lng, d.lat, d.lng);
                    else heading = rumboCalc[d.id];
                }
                if (typeof heading === 'number') rumboCalc[d.id] = heading;
                const html = htmlPopup(d);
                const ico = iconoMoto(d, rumboCalc[d.id]);
                if (capas[d.id]) {
                    moverSuave(capas[d.id], d.lat, d.lng, 2200);
                    capas[d.id].setIcon(ico);
                    capas[d.id].setPopupContent(html);
                } else {
                    capas[d.id] = L.marker([d.lat, d.lng], { icon: ico, riseOnHover: true }).addTo(map).bindPopup(html);
                }
                if (d.acc && d.acc >= 12 && d.acc <= 120) {
                    if (circulos[d.id]) {
                        circulos[d.id].setLatLng([d.lat, d.lng]);
                        circulos[d.id].setRadius(d.acc);
                    } else {
                        circulos[d.id] = L.circle([d.lat, d.lng], {
                            radius: d.acc, color: '#ce4e2d', weight: 1,
                            fillColor: '#ce4e2d', fillOpacity: 0.08, interactive: false
                        }).addTo(map);
                    }
                } else if (circulos[d.id]) {
                    map.removeLayer(circulos[d.id]);
                    delete circulos[d.id];
                }
                if (seguirId === d.id) {
                    map.panTo([d.lat, d.lng], { animate: true, duration: 1.1 });
                }
            });
            Object.keys(capas).forEach(function (mid) {
                if (!vistos[mid]) {
                    map.removeLayer(capas[mid]);
                    delete capas[mid];
                    if (circulos[mid]) { map.removeLayer(circulos[mid]); delete circulos[mid]; }
                    if (Number(seguirId) === Number(mid)) seguirId = null;
                }
            });
            const buscar = document.getElementById('buscar-moto');
            pintarLista(buscar ? buscar.value : '');
            if (bounds.length && opts.ajustar !== false && primerAjuste) {
                map.fitBounds(bounds, { padding: [48, 48], maxZoom: 15 });
                primerAjuste = false;
            }
        } catch (e) {}
    }

    const input = document.getElementById('buscar-moto');
    if (input) {
        let t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            const q = input.value;
            pintarLista(q);
            t = setTimeout(function () { if (q.trim()) buscarMotorizado(q); }, 280);
        });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                buscarMotorizado(input.value);
            }
        });
    }
    const btn = document.getElementById('btn-buscar-moto');
    if (btn) {
        btn.addEventListener('click', function () {
            buscarMotorizado(input ? input.value : '');
        });
    }

    cargar();
    setInterval(cargar, 2500);
    setTimeout(function () { map.invalidateSize(); }, 250);
    window.addEventListener('resize', function () { map.invalidateSize(); });
}
