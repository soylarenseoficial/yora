/**
 * YoraDriver: GPS en segundo plano (Median), pantalla encendida y ruta in-app.
 * No abre Google Maps: eso mata el WebView y deja de localizar / notificar.
 */
(function (w) {
    'use strict';

    const YORA = (w.YORA = w.YORA || {});
    let wakeLock = null;
    let gpsWatchId = null;
    let fondoOn = false;
    let navMap = null;
    let navLine = null;
    let mkYo = null;
    let mkPick = null;
    let mkDrop = null;
    let navDatos = null;
    let ultimoGpsMs = 0;
    let navHeading = 0;
    let navSiguiendo = false;
    let navSteps = [];
    let navStepIdx = 0;
    let navUltimoRecalc = 0;
    let navRouteCoords = [];

    function puente() {
        return w.median || w.gonative || null;
    }

    function esMedian() {
        return !!(w.median || w.gonative);
    }

    function n(v) {
        const x = parseFloat(v);
        return isFinite(x) ? x : null;
    }

    let navSpeedKmh = 0;
    let navEtaSeg = 0;
    let navEtaKm = 0;
    let navTileLayer = null;
    let leafletCargando = null;

    function conLeaflet(fn) {
        if (w.L) { fn(); return; }
        if (leafletCargando) { leafletCargando.then(fn); return; }
        leafletCargando = new Promise(function (resolve) {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = '/js/leaflet.css';
            document.head.appendChild(css);
            const s = document.createElement('script');
            s.src = '/js/leaflet.js';
            s.onload = function () { resolve(); };
            s.onerror = function () { resolve(); };
            document.head.appendChild(s);
        });
        leafletCargando.then(fn);
    }

    function aplicarPos(lat, lng, heading, acc, speedMs) {
        if (!isFinite(lat) || !isFinite(lng)) return;
        w.myLat = lat;
        w.myLng = lng;
        YORA.lat = lat;
        YORA.lng = lng;
        if (isFinite(heading) && heading >= 0) navHeading = heading;
        if (isFinite(speedMs) && speedMs >= 0) {
            navSpeedKmh = Math.round(speedMs * 3.6);
            const sp = document.getElementById('nav-speed-val');
            if (sp) sp.textContent = String(navSpeedKmh);
        }
        ultimoGpsMs = Date.now();
        const badge = document.getElementById('gps-vivo');
        if (badge) {
            badge.textContent = 'GPS vivo';
            badge.className = 'gps-vivo on';
        }
        if (typeof w.enviarGpsVivo === 'function') {
            w.enviarGpsVivo({
                coords: {
                    latitude: lat,
                    longitude: lng,
                    heading: heading,
                    accuracy: acc,
                    speed: speedMs
                }
            });
        }
        pintarNavYo();
    }

    YORA.locationUpdated = function (data) {
        try {
            const locs = (data && data.locations) ? data.locations : (data ? [data] : []);
            if (!locs.length) return;
            const last = locs[locs.length - 1] || {};
            const lat = n(last.latitude != null ? last.latitude : last.lat);
            const lng = n(last.longitude != null ? last.longitude : last.lng);
            if (lat == null || lng == null) return;
            aplicarPos(lat, lng, n(last.bearing), n(last.horizontalAccuracy), n(last.speed));
        } catch (e) {}
    };
    w.locationUpdated = YORA.locationUpdated;
    async function pedirWakeLock() {
        try {
            if (wakeLock && !wakeLock.released) return;
            if (navigator.wakeLock && document.visibilityState === 'visible') {
                wakeLock = await navigator.wakeLock.request('screen');
                wakeLock.addEventListener('release', function () {
                    if (document.visibilityState === 'visible') setTimeout(pedirWakeLock, 400);
                });
            }
        } catch (e) {}
    }

    function pantallaNativa(on) {
        try {
            if (w.YoraNative && typeof w.YoraNative.keepScreenOn === 'function') {
                w.YoraNative.keepScreenOn(!!on);
            }
        } catch (e) {}
        const m = puente();
        try {
            if (m && m.screen) {
                if (on && typeof m.screen.keepScreenOn === 'function') m.screen.keepScreenOn();
                if (!on && typeof m.screen.keepScreenNormal === 'function') m.screen.keepScreenNormal();
            }
        } catch (e) {}
        try {
            if (m && m.webview && typeof m.webview.setWindowProperties === 'function') {
                m.webview.setWindowProperties({ keepScreenOn: !!on });
            }
        } catch (e) {}
        if (on) pedirWakeLock();
    }

    function gpsFondoStart() {
        const url = (YORA.gpsFondoUrl || '');
        try {
            if (w.YoraNative && typeof w.YoraNative.startBackgroundGps === 'function') {
                if (typeof w.YoraNative.permisosTrabajoListos === 'function' && !w.YoraNative.permisosTrabajoListos()) {
                    if (typeof w.YoraNative.pedirPermisosTrabajo === 'function') w.YoraNative.pedirPermisosTrabajo();
                }
                w.YoraNative.startBackgroundGps(url);
                fondoOn = true;
                return;
            }
        } catch (e) {}
        if (fondoOn) {
            try {
                const m = puente();
                if (m && m.backgroundLocation && typeof m.backgroundLocation.start === 'function') {
                    m.backgroundLocation.start(requestFondo());
                }
            } catch (e) {}
            return;
        }
        const m = puente();
        const bg = m && m.backgroundLocation;
        if (!bg || typeof bg.start !== 'function') return;
        try {
            bg.start(requestFondo());
            fondoOn = true;
        } catch (e) {
            fondoOn = false;
        }
    }

    function requestFondo() {
        return {
            callback: 'locationUpdated',
            postUrl: YORA.gpsFondoUrl || '',
            iosBackgroundIndicator: true,
            iosPauseAutomatically: false,
            iosDesiredAccuracy: 'bestForNavigation',
            iosActivityType: 'automotiveNavigation',
            iosDistanceFilter: 8,
            androidInterval: 12000,
            androidFastestInterval: 8000,
            androidPriority: 'highAccuracy',
            androidSmallestDisplacement: 5,
            androidNotificationTitle: 'YoraDriver en línea',
            androidNotificationText: 'Localizando y recibiendo pedidos. Toca para volver.'
        };
    }

    function gpsFondoStop() {
        try {
            if (w.YoraNative && typeof w.YoraNative.stopBackgroundGps === 'function') {
                w.YoraNative.stopBackgroundGps();
            }
        } catch (e) {}
        const m = puente();
        try {
            if (m && m.backgroundLocation && typeof m.backgroundLocation.stop === 'function') {
                m.backgroundLocation.stop();
            }
        } catch (e) {}
        fondoOn = false;
    }

    let gpsStartTimer = null;

    function watchHtml5(modo) {
        if (!('geolocation' in navigator)) return;
        const nav = modo === 'nav';
        // Solo durante guía in-app. Nunca en idle/offline (modelo Uber/Glovo).
        if (!nav) return;
        if (gpsWatchId != null) stopWatchHtml5();
        gpsWatchId = navigator.geolocation.watchPosition(
            function (pos) {
                aplicarPos(pos.coords.latitude, pos.coords.longitude, pos.coords.heading, pos.coords.accuracy, pos.coords.speed);
            },
            function () {
                const badge = document.getElementById('gps-vivo');
                if (badge && Date.now() - ultimoGpsMs > 20000) {
                    badge.textContent = 'GPS ausente';
                    badge.className = 'gps-vivo off';
                }
            },
            { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 }
        );
    }

    function stopWatchHtml5() {
        if (gpsWatchId == null) return;
        try { navigator.geolocation.clearWatch(gpsWatchId); } catch (e) {}
        gpsWatchId = null;
    }

    function pedirFixUnaVez() {
        try {
            if (w.YoraNative && typeof w.YoraNative.pedirUltimaUbicacion === 'function') {
                w.YoraNative.pedirUltimaUbicacion();
                return;
            }
        } catch (e) {}
        if (!('geolocation' in navigator)) return;
        navigator.geolocation.getCurrentPosition(function (pos) {
            aplicarPos(pos.coords.latitude, pos.coords.longitude, pos.coords.heading, pos.coords.accuracy, pos.coords.speed);
        }, function () {}, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
    }

    YORA.pedirFixUnaVez = pedirFixUnaVez;

    YORA.esperarFix = function (ms) {
        return new Promise(function (resolve) {
            const t0 = Date.now();
            const lim = typeof ms === 'number' ? ms : 8000;
            pedirFixUnaVez();
            const iv = setInterval(function () {
                if (YORA.lat && YORA.lng) {
                    clearInterval(iv);
                    resolve(true);
                    return;
                }
                if (Date.now() - t0 > lim) {
                    clearInterval(iv);
                    resolve(false);
                }
            }, 400);
        });
    };

    YORA.estaOnline = function () {
        const btn = document.getElementById('status-toggle');
        return !!(btn && btn.classList.contains('online'));
    };

    /** Arranque suave: no GPS al abrir. Solo Online (diferido) o guía. */
    YORA.activarVivo = function (forzarFondo) {
        const online = forzarFondo || YORA.estaOnline();
        if (online) {
            // forzarFondo = usuario acaba de aceptar / Online → inmediato
            YORA.fondoSegunEstado(true, !!forzarFondo);
        } else {
            YORA.fondoSegunEstado(false);
        }
        if (navSiguiendo) {
            pantallaNativa(true);
            watchHtml5('nav');
        }
    };

    YORA.fondoSegunEstado = function (online, inmediato) {
        if (gpsStartTimer) {
            clearTimeout(gpsStartTimer);
            gpsStartTimer = null;
        }
        if (online) {
            const arrancar = function () {
                if (!YORA.estaOnline() && !navSiguiendo && !inmediato) return;
                stopWatchHtml5();
                gpsFondoStart();
                pedirFixUnaVez();
            };
            // Al abrir (sync): diferir. Al tocar Online: inmediato.
            if (inmediato) arrancar();
            else {
                gpsStartTimer = setTimeout(function () {
                    gpsStartTimer = null;
                    arrancar();
                }, 2500);
            }
        } else {
            gpsFondoStop();
            if (!navSiguiendo) {
                stopWatchHtml5();
                pantallaNativa(false);
            }
        }
    };



    function iconoNav(color) {
        return L.divIcon({
            className: '',
            iconSize: [22, 22],
            iconAnchor: [11, 11],
            html: '<div style="width:18px;height:18px;border-radius:50%;background:' + color + ';border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);"></div>'
        });
    }

    function iconoYo(heading) {
        const rot = (isFinite(heading) ? heading : 0);
        return L.divIcon({
            className: '',
            iconSize: [56, 56],
            iconAnchor: [28, 28],
            html: '<div style="width:56px;height:56px;display:flex;align-items:center;justify-content:center;transform:rotate(' + rot + 'deg);">'
                + '<div style="position:relative;width:28px;height:40px;">'
                + '<div style="position:absolute;left:50%;top:0;transform:translateX(-50%);width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-bottom:22px solid rgba(66,133,244,.35);"></div>'
                + '<div style="position:absolute;left:50%;bottom:2px;transform:translateX(-50%);width:18px;height:18px;border-radius:50%;background:#1a73e8;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.45);"></div>'
                + '</div></div>'
        });
    }

    function puntoMirada(lat, lng, heading, metros) {
        const rad = ((isFinite(heading) ? heading : 0) * Math.PI) / 180;
        const dLat = (metros / 111320) * Math.cos(rad);
        const dLng = (metros / (111320 * Math.cos((lat * Math.PI) / 180))) * Math.sin(rad);
        return [lat + dLat, lng + dLng];
    }

    let navUiMs = 0;
    let navIconMs = 0;

    function pintarNavYo() {
        if (!navMap || !YORA.lat || !YORA.lng) return;
        const ll = [YORA.lat, YORA.lng];
        if (mkYo) {
            mkYo.setLatLng(ll);
        } else {
            mkYo = L.marker(ll, { icon: navSiguiendo ? iconoYo(navHeading) : iconoNav('#1a73e8'), zIndexOffset: 1000 }).addTo(navMap);
        }
        if (!navSiguiendo) return;
        const ahora = Date.now();
        if (ahora - navIconMs > 2000) {
            navIconMs = ahora;
            mkYo.setIcon(iconoYo(navHeading));
        }
        if (ahora - navUiMs < 900) return;
        navUiMs = ahora;
        const mira = puntoMirada(YORA.lat, YORA.lng, navHeading, 55);
        navMap.setView(mira, Math.max(navMap.getZoom(), 17), { animate: false });
        avanzarInstruccion();
        if (ahora - navUltimoRecalc > 25000 || distanciaARuta() > 70) {
            navUltimoRecalc = ahora;
            recalcularDesdeAqui();
        }
    }

    function distanciaARuta() {
        if (!navRouteCoords.length || !YORA.lat || !YORA.lng) return 0;
        let min = 1e9;
        for (let i = 0; i < navRouteCoords.length; i += 8) {
            const c = navRouteCoords[i];
            const d = haversineM(YORA.lat, YORA.lng, c[0], c[1]);
            if (d < min) min = d;
        }
        return min;
    }

    function destinoActivo() {
        if (!navDatos) return null;
        if (navDatos.estatus === 'En Camino a Cliente') return navDatos.drop;
        if (navDatos.pick && navDatos.pick[0] != null) {
            if (YORA.lat && YORA.lng) {
                const dPick = haversineM(YORA.lat, YORA.lng, navDatos.pick[0], navDatos.pick[1]);
                if (dPick < 70) return navDatos.drop;
            }
            return navDatos.pick;
        }
        return navDatos.drop;
    }

    function haversineM(a, b, c, d) {
        const R = 6371000;
        const toRad = function (x) { return x * Math.PI / 180; };
        const dLat = toRad(c - a);
        const dLng = toRad(d - b);
        const s = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(toRad(a)) * Math.cos(toRad(c)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
    }

    function flechaManeuver(step) {
        if (!step || !step.maneuver) return '↑';
        const m = step.maneuver.type || '';
        const mod = step.maneuver.modifier || '';
        if (m === 'arrive') return '◎';
        if (mod.indexOf('left') >= 0) return '↩';
        if (mod.indexOf('right') >= 0) return '↪';
        if (mod.indexOf('uturn') >= 0) return '↺';
        if (m === 'roundabout') return '⟳';
        return '↑';
    }

    function mostrarInstruccion(texto, meta, flecha) {
        const t = document.getElementById('nav-instruccion-txt');
        const m = document.getElementById('nav-instruccion-meta');
        const a = document.getElementById('nav-arrow');
        if (t) t.textContent = texto || '';
        if (m) m.textContent = meta || '';
        if (a) a.textContent = flecha || '↑';
        // Compat si HTML viejo
        const el = document.getElementById('nav-instruccion');
        if (el && !t) el.innerHTML = texto + (meta ? '<small>' + meta + '</small>' : '');
    }

    function mostrarLuego(step) {
        const box = document.getElementById('nav-luego');
        if (!box) return;
        if (!step) { box.classList.remove('on'); return; }
        const txt = document.getElementById('nav-luego-txt');
        const arr = document.getElementById('nav-luego-arrow');
        const nombre = step.name || step.ref || '';
        if (txt) txt.textContent = nombre ? nombre : textoManeuver(step).replace(/^Gira a la |^Continúa por |^Sigue derecho por /i, '');
        if (arr) arr.textContent = flechaManeuver(step);
        box.classList.add('on');
    }

    function actualizarEtaDock(distM, durS) {
        navEtaKm = (distM || 0) / 1000;
        navEtaSeg = durS || 0;
        const mins = Math.max(1, Math.round(navEtaSeg / 60));
        const etaMin = document.getElementById('nav-eta-min');
        const etaMeta = document.getElementById('nav-eta-meta');
        const tit = document.getElementById('nav-titulo');
        const titSub = document.getElementById('nav-titulo-sub');
        const llegada = new Date(Date.now() + navEtaSeg * 1000);
        const hh = String(llegada.getHours()).padStart(2, '0');
        const mm = String(llegada.getMinutes()).padStart(2, '0');
        if (etaMin) etaMin.textContent = mins + ' min';
        if (etaMeta) etaMeta.textContent = navEtaKm.toFixed(1) + ' km · ' + hh + ':' + mm;
        if (titSub) titSub.textContent = navEtaKm.toFixed(1) + ' km · ~' + mins + ' min';
        else if (tit && !tit.querySelector('span')) tit.textContent = navEtaKm.toFixed(1) + ' km · ~' + mins + ' min';
    }

    function textoManeuver(step) {
        if (!step) return 'Sigue la ruta';
        const m = (step.maneuver && step.maneuver.type) || '';
        const mod = (step.maneuver && step.maneuver.modifier) || '';
        const nombre = step.name || step.ref || 'la vía';
        const mapa = {
            'turn|left': 'Gira a la izquierda', 'turn|right': 'Gira a la derecha',
            'turn|slight left': 'Inclínate a la izquierda', 'turn|slight right': 'Inclínate a la derecha',
            'turn|sharp left': 'Giro cerrado a la izquierda', 'turn|sharp right': 'Giro cerrado a la derecha',
            'new name|': 'Continúa por', 'depart|': 'Sal hacia', 'arrive|': 'Llegaste',
            'merge|': 'Incorpórate', 'roundabout|': 'Entra a la rotonda',
            'fork|left': 'Toma la salida izquierda', 'fork|right': 'Toma la salida derecha', 'continue|': 'Sigue derecho por'
        };
        const base = mapa[m + '|' + mod] || mapa[m + '|'] || 'Continúa';
        if (m === 'arrive') return 'Has llegado al punto';
        if (m === 'depart') return 'en dirección a ' + nombre;
        if (m === 'continue' || m === 'new name') return 'en dirección a ' + nombre;
        return base + (nombre && nombre !== 'la vía' ? ' ' + nombre : '');
    }

    function avanzarInstruccion() {
        if (!navSteps.length || !YORA.lat || !YORA.lng) return;
        while (navStepIdx < navSteps.length - 1) {
            const step = navSteps[navStepIdx];
            const loc = step.maneuver && step.maneuver.location;
            if (!loc) { navStepIdx++; continue; }
            if (haversineM(YORA.lat, YORA.lng, loc[1], loc[0]) < 40) navStepIdx++;
            else break;
        }
        const actual = navSteps[navStepIdx] || navSteps[navSteps.length - 1];
        const metros = Math.round(actual.distance || 0);
        const distTxt = metros >= 1000 ? (metros / 1000).toFixed(1) + ' km' : metros + ' m';
        mostrarInstruccion(textoManeuver(actual), 'En ' + distTxt, flechaManeuver(actual));
        const next = navSteps[navStepIdx + 1];
        if (next && (next.maneuver && next.maneuver.type) !== 'arrive') mostrarLuego(next);
        else mostrarLuego(null);

        // ETA restante aprox. sumando steps pendientes
        let remDist = 0, remDur = 0;
        for (let i = navStepIdx; i < navSteps.length; i++) {
            remDist += navSteps[i].distance || 0;
            remDur += navSteps[i].duration || 0;
        }
        if (remDist > 0) actualizarEtaDock(remDist, remDur);
    }

    async function trazarRuta(puntos, opts) {
        if (!navMap || puntos.length < 2) return false;
        opts = opts || {};
        const coords = puntos.map(function (p) { return p[1] + ',' + p[0]; }).join(';');
        try {
            const res = await fetch('https://router.project-osrm.org/route/v1/driving/' + coords + '?overview=full&geometries=geojson&steps=true');
            const data = await res.json();
            if (data && data.routes && data.routes[0] && data.routes[0].geometry) {
                const route = data.routes[0];
                const geo = route.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
                navRouteCoords = geo;
                if (navLine) navMap.removeLayer(navLine);
                navLine = L.polyline(geo, { color: '#1a73e8', weight: 8, opacity: 0.95, lineCap: 'round', lineJoin: 'round' }).addTo(navMap);
                navSteps = [];
                (route.legs || []).forEach(function (leg) { (leg.steps || []).forEach(function (s) { navSteps.push(s); }); });
                navStepIdx = 0;
                actualizarEtaDock(route.distance || 0, route.duration || 0);
                if (!opts.noFit && !navSiguiendo) navMap.fitBounds(navLine.getBounds(), { padding: [56, 56], maxZoom: 17 });
                avanzarInstruccion();
                return true;
            }
        } catch (e) {}
        if (navLine) navMap.removeLayer(navLine);
        navLine = L.polyline(puntos, { color: '#1a73e8', weight: 5, dashArray: '10 8', opacity: 0.85 }).addTo(navMap);
        navRouteCoords = puntos.slice();
        if (!opts.noFit) navMap.fitBounds(puntos, { padding: [36, 36], maxZoom: 16 });
        mostrarInstruccion('Ruta estimada', 'Revisa el camino', '↑');
        return false;
    }

    function puntosRutaActual() {
        const pts = [];
        if (YORA.lat && YORA.lng) pts.push([YORA.lat, YORA.lng]);
        const dest = destinoActivo();
        if (dest && dest[0] != null && dest[1] != null) pts.push(dest);
        else if (navDatos) {
            if (navDatos.pick && navDatos.pick[0] != null && navDatos.estatus !== 'En Camino a Cliente') pts.push(navDatos.pick);
            if (navDatos.drop && navDatos.drop[0] != null) pts.push(navDatos.drop);
        }
        return pts;
    }

    function recalcularDesdeAqui() {
        const pts = puntosRutaActual();
        if (pts.length >= 2) trazarRuta(pts, { noFit: true });
    }

    function modoGuia(on) {
        const overlay = document.getElementById('nav-viaje');
        if (!overlay) return;
        if (on) overlay.classList.add('nav-mode');
        else overlay.classList.remove('nav-mode');
        setTimeout(function () {
            if (navMap) navMap.invalidateSize();
        }, 120);
    }

    YORA.iniciarRecorrido = function () {
        if (!navDatos) return;
        navSiguiendo = true;
        navUltimoRecalc = 0;
        pantallaNativa(true);
        modoGuia(true);
        // Solo al guiar: GPS más frecuente para el mapa (idle online usa solo GpsService).
        watchHtml5('nav');
        const btn = document.getElementById('nav-btn-go');
        if (btn) { btn.textContent = 'Guiando…'; btn.style.background = '#0f9d58'; }
        mostrarInstruccion('Calculando recorrido…', 'No salgas de Yora', '↑');
        const pts = puntosRutaActual();
        if (pts.length >= 2) {
            trazarRuta(pts).then(function () {
                if (YORA.lat && YORA.lng) {
                    if (mkYo) mkYo.setIcon(iconoYo(navHeading));
                    const mira = puntoMirada(YORA.lat, YORA.lng, navHeading, 55);
                    navMap.setView(mira, 18);
                }
            });
        } else mostrarInstruccion('Activa el GPS', 'Para iniciar el recorrido', '◎');
    };

    YORA.detenerRecorrido = function () {
        navSiguiendo = false;
        modoGuia(false);
        mostrarLuego(null);
        const nativo = !!(w.YoraNative && typeof w.YoraNative.startBackgroundGps === 'function');
        if (nativo && YORA.estaOnline()) stopWatchHtml5();
        else if (!nativo) watchHtml5('soft');
        const btn = document.getElementById('nav-btn-go');
        if (btn) { btn.textContent = 'Iniciar'; btn.style.background = ''; }
        if (mkYo) mkYo.setIcon(iconoNav('#1a73e8'));
        mostrarInstruccion('Guía pausada', 'Toca Iniciar para continuar', '↑');
        if (!YORA.estaOnline()) pantallaNativa(false);
    };

    YORA.abrirRuta = function (el) {
        const pick = [n(el.getAttribute('data-pick-lat')), n(el.getAttribute('data-pick-lng'))];
        const drop = [n(el.getAttribute('data-drop-lat') || el.getAttribute('data-lat')), n(el.getAttribute('data-drop-lng') || el.getAttribute('data-lng'))];
        navDatos = {
            pick: pick, drop: drop,
            pickTxt: el.getAttribute('data-pick-txt') || 'Punto de retiro',
            dropTxt: el.getAttribute('data-drop-txt') || 'Punto de entrega',
            estatus: el.getAttribute('data-estatus') || ''
        };
        navSiguiendo = false; navSteps = []; navStepIdx = 0; navRouteCoords = [];
        modoGuia(false);
        mostrarLuego(null);
        document.getElementById('nav-pick-txt').textContent = navDatos.pickTxt;
        document.getElementById('nav-drop-txt').textContent = navDatos.dropTxt;
        const btn = document.getElementById('nav-btn-go');
        if (btn) { btn.textContent = 'Iniciar'; btn.style.background = ''; }
        mostrarInstruccion('Cargando mapa…', 'Un momento', '↑');
        document.getElementById('nav-viaje').style.display = 'flex';
        conLeaflet(function () {
            if (!w.L) {
                mostrarInstruccion('Sin mapa', 'Revisa tu conexión e inténtalo', '◎');
                return;
            }
            setTimeout(function () {
            if (!navMap) {
                navMap = L.map('nav-mapa', { zoomControl: true, attributionControl: false }).setView([10.067, -69.347], 14);
                navTileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap'
                }).addTo(navMap);
            }
            if (mkPick && navMap.hasLayer(mkPick)) navMap.removeLayer(mkPick);
            if (mkDrop && navMap.hasLayer(mkDrop)) navMap.removeLayer(mkDrop);
            mkPick = mkDrop = null;
            const pts = [];
            if (YORA.lat && YORA.lng) pts.push([YORA.lat, YORA.lng]);
            if (pick[0] != null && pick[1] != null) {
                mkPick = L.marker(pick, { icon: iconoNav('#0f9d58') }).addTo(navMap).bindTooltip('Retiro', { permanent: true, direction: 'top', className: 'nav-tip' });
                if (navDatos.estatus !== 'En Camino a Cliente') pts.push(pick);
            }
            if (drop[0] != null && drop[1] != null) {
                mkDrop = L.marker(drop, { icon: iconoNav('#ea4335') }).addTo(navMap).bindTooltip('Entrega', { permanent: true, direction: 'right', className: 'nav-tip' });
                pts.push(drop);
            }
            pintarNavYo();
            navMap.invalidateSize();
            if (pts.length >= 2) trazarRuta(pts).then(function () { setTimeout(function () { YORA.iniciarRecorrido(); }, 280); });
            else if (pts.length === 1) { navMap.setView(pts[0], 16); setTimeout(function () { YORA.iniciarRecorrido(); }, 280); }
            }, 40);
        });
    };

    YORA.cerrarRuta = function () {
        navSiguiendo = false;
        modoGuia(false);
        mostrarLuego(null);
        const nativo = !!(w.YoraNative && typeof w.YoraNative.startBackgroundGps === 'function');
        if (nativo && YORA.estaOnline()) stopWatchHtml5();
        if (!YORA.estaOnline()) pantallaNativa(false);
        document.getElementById('nav-viaje').style.display = 'none';
    };

    YORA.centrarYo = function () {
        if (!navMap || !YORA.lat || !YORA.lng) return;
        if (navSiguiendo) {
            const mira = puntoMirada(YORA.lat, YORA.lng, navHeading, 55);
            navMap.setView(mira, 18);
        } else {
            navMap.setView([YORA.lat, YORA.lng], 18);
        }
    };
    w.abrirRutaViaje = function (el) { YORA.abrirRuta(el); };
    w.cerrarRutaViaje = function () { YORA.cerrarRuta(); };

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            if (navSiguiendo) pantallaNativa(true);
            YORA.activarVivo(YORA.estaOnline());
            if (typeof w.sincronizarApp === 'function') w.sincronizarApp(false);
            if (typeof w.activarPush === 'function') w.activarPush();
        }
    });
    w.addEventListener('pageshow', function () {
        if (navSiguiendo) pantallaNativa(true);
        if (YORA.estaOnline()) gpsFondoStart();
    });
    w.addEventListener('focus', function () {
        if (YORA.estaOnline()) gpsFondoStart();
    });

    document.addEventListener('click', function (e) {
        const a = e.target.closest && e.target.closest('a[href*="google.com/maps"], a[href*="maps.google"], a[href*="maps.app.goo.gl"]');
        if (!a) return;
        e.preventDefault();
        const card = a.closest('.card-viaje');
        const mapa = card ? card.querySelector('.mapa-gps') : null;
        if (mapa) YORA.abrirRuta(mapa);
    }, true);

    YORA.esMedian = esMedian;
})(window);
