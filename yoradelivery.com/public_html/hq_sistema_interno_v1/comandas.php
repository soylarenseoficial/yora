<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

date_default_timezone_set('America/Caracas');
require_once 'conexion.php';
yora_require_admin_pagina('comandas.php');
yora_timezone($conexion);

$mensaje = '';
$tipo_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !yora_peticion_propia()) {
    http_response_code(403);
    exit('Solicitud rechazada.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int) ($_POST['comanda_id'] ?? 0);
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        $c = $id > 0 ? yora_comanda_hq($conexion, $id) : null;
        if (!$c) {
            throw new RuntimeException('Viaje no encontrado.');
        }
        $codigo = yora_codigo_comanda($conexion, $id, $c['codigo'] ?? null);
        $cerrado = in_array((string) $c['estatus'], ['Entregado', 'Cancelado'], true);
        if ($cerrado && $accion !== 'corregir') {
            throw new RuntimeException('Ese viaje ya está cerrado.');
        }
        if ($accion === 'liberar') {
            yora_hq_liberar_driver($conexion, $c);
            $mensaje = 'Se quitó el motorizado del viaje <b>#' . yora_h($codigo) . '</b>. Volvió al radar.';
            $tipo_msg = 'ok';
        } elseif ($accion === 'asignar') {
            $driver_id = (int) ($_POST['driver_id'] ?? 0);
            if ($driver_id < 1) {
                throw new RuntimeException('Elige un motorizado.');
            }
            yora_hq_asignar_driver($conexion, $c, $driver_id);
            $mensaje = 'Viaje <b>#' . yora_h($codigo) . '</b> asignado al motorizado elegido.';
            $tipo_msg = 'ok';
        } elseif ($accion === 'geocerca') {
            yora_exec($conexion, 'UPDATE comandas SET geocerca_libre = 1 WHERE id = ?', 'i', $id);
            $cid = (int) ($c['conductor_id'] ?? 0);
            if ($cid > 0) {
                try {
                    yora_push_conductor($conexion, $cid, 'Geocerca abierta', 'HQ te habilitó recoger/entregar en el punto donde estás. Recarga la app.', '/dashboard.php');
                } catch (Throwable $e) {
                }
            }
            $mensaje = 'Geocerca abierta en <b>#' . yora_h($codigo) . '</b>. El motorizado puede tomar la foto aunque el pin esté mal.';
            $tipo_msg = 'ok';
        } elseif ($accion === 'corregir') {
            $dir = trim((string) ($_POST['direccion'] ?? ''));
            $lat = (float) str_replace(',', '.', (string) ($_POST['lat'] ?? '0'));
            $lng = (float) str_replace(',', '.', (string) ($_POST['lng'] ?? '0'));
            $km_raw = trim((string) ($_POST['km'] ?? ''));
            $costo_raw = trim((string) ($_POST['costo'] ?? ''));
            $km_forzado = $km_raw !== '' ? (float) str_replace(',', '.', $km_raw) : null;
            $costo_forzado = $costo_raw !== '' ? (float) str_replace(',', '.', $costo_raw) : null;
            $res = yora_hq_corregir_destino($conexion, $c, $dir, $lat, $lng, $km_forzado, $costo_forzado);
            $mensaje = 'Destino de <b>#' . yora_h($codigo) . '</b> corregido. Nueva ruta: ' . yora_h((string) $res['km']) . ' km · $' . number_format((float) $res['costo'], 2) . '. Se ajustó la tarifa y las billeteras.';
            $tipo_msg = 'ok';
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipo_msg = 'error';
    }
}

$sql = "SELECT c.*,
               COALESCE(NULLIF(r.nombre, ''), IF(c.tipo_comanda = 'mandadito', 'Mandadito', 'Yora')) AS restaurante,
               d.nombre AS conductor,
               c.tipo_comanda
        FROM comandas c
        LEFT JOIN comercios r ON c.comercio_id = r.id
        LEFT JOIN conductores d ON c.conductor_id = d.id
        ORDER BY c.id DESC LIMIT 200";
$res = $conexion->query($sql);

$drivers = yora_all(
    $conexion,
    "SELECT id, nombre, telefono, en_linea FROM conductores
     WHERE LOWER(IFNULL(estatus,'activo')) NOT IN ('pendiente','rechazado','inactivo')
     ORDER BY en_linea DESC, nombre ASC"
) ?: [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Comandas</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        :root {
            --yora-orange: #ce4e2d;
            --yora-orange-hover: #e65a36;
            --bg-body: #f4f6f8;
            --bg-card: #ffffff;
            --border-color: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif;}
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }
        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 2rem; margin-bottom: 5px; font-weight: 800; letter-spacing: -0.5px; }
        .header-subtitle { color: var(--text-muted); margin-bottom: 20px; font-size: 0.95rem; }
        .busca { width: 100%; max-width: 360px; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 10px; margin-bottom: 16px; }
        .card-table { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); width: 100%; overflow: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead tr { background: #f8fafc; border-bottom: 1px solid var(--border-color); }
        th { padding: 16px 20px; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }
        td { padding: 18px 20px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; vertical-align: middle; }
        .btn-ver { background: white; border: 1px solid #cbd5e1; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85rem; color: #475569; }
        .btn-ver:hover { border-color: var(--yora-orange); color: var(--yora-orange); }
        .alert { padding: 14px; border-radius: 10px; margin-bottom: 18px; font-weight: 500; font-size: 0.9rem; }
        .alert.ok { background: #dcfce7; border: 1px solid #22c55e; color: #166534; }
        .alert.error { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.8); z-index: 9999; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-box { background: white; padding: 28px; border-radius: 20px; width: 100%; max-width: 720px; position: relative; max-height: 92vh; overflow-y:auto; }
        .close-modal { position: absolute; top: 12px; right: 16px; font-size: 24px; cursor: pointer; color: var(--text-muted); }
        .info-caja { background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 16px; font-size: 0.9rem; border: 1px solid var(--border-color); line-height: 1.6; }
        .foto-box { background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 12px; text-align: center; flex: 1; }
        .foto-box img { max-width: 100%; height: 160px; object-fit: cover; border-radius: 8px; }
        .sin-foto { color: #94a3b8; padding: 40px 0; }
        .ops { display:grid; gap:12px; margin-top:16px; }
        .op { border:1px solid #e5e7eb; border-radius:12px; padding:14px; background:#fff; }
        .op h4 { margin:0 0 8px; font-size:0.95rem; }
        .op p { font-size:0.78rem; color:#64748b; margin:0 0 10px; }
        .op label { display:block; font-size:0.75rem; font-weight:700; margin:6px 0 4px; }
        .op input, .op select { width:100%; padding:9px 10px; border:1px solid #d1d5db; border-radius:8px; }
        .row2 { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .btn-act { border:0; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; font-family:inherit; width:100%; }
        .btn-naranja { background:var(--yora-orange); color:#fff; }
        .btn-oscuro { background:#1f2937; color:#fff; }
        .btn-verde { background:#047857; color:#fff; }
        .btn-rojo { background:#dc2626; color:#fff; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1 class="header-title">Auditoría de Comandas</h1>
        <p class="header-subtitle">Si el pin está mal, el motorizado se tarda o hay que mover el viaje: se corrige aquí, sin tocar la base de datos.</p>
        <?php if ($mensaje !== ''): ?>
            <div class="alert <?php echo $tipo_msg === 'ok' ? 'ok' : 'error'; ?>"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        <input class="busca" type="search" id="busca" placeholder="Buscar por código, comercio, cliente o driver" oninput="filtrar()">

        <div class="card-table">
            <table>
                <thead>
                    <tr>
                        <th>Orden #</th>
                        <th>Fecha y Hora</th>
                        <th>Comercio</th>
                        <th>Cliente (Contacto)</th>
                        <th>Driver</th>
                        <th>Estatus</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($res && $res->num_rows > 0) {
                        while ($row = $res->fetch_assoc()) {
                            $fecha_format = date('d/m/Y h:i A', strtotime($row['fecha_creacion']));
                            $nombre_cliente = !empty($row['cliente_nombre']) ? $row['cliente_nombre'] : 'N/A';
                            $tel_cliente = !empty($row['cliente_telefono']) ? $row['cliente_telefono'] : 'N/A';
                            $direccion_paquete = !empty($row['direccion_entrega']) ? $row['direccion_entrega'] : 'N/A';
                            [$elat, $elng] = yora_coords_entrega_comanda($row);
                            $color = '#64748b'; $bg = '#f1f5f9';
                            if ($row['estatus'] == 'Entregado') { $color = '#16a34a'; $bg = '#dcfce7'; }
                            if ($row['estatus'] == 'Cancelado') { $color = '#dc2626'; $bg = '#fee2e2'; }
                            if ($row['estatus'] == 'En Camino a Cliente' || $row['estatus'] == 'En Camino a Comercio') { $color = '#d97706'; $bg = '#fef3c7'; }
                            if ($row['estatus'] == 'Buscando Conductor') { $color = '#2563eb'; $bg = '#dbeafe'; }
                            $codigo = yora_codigo_comanda($conexion, (int) $row['id'], $row['codigo'] ?? null);
                            $origen = (($row['tipo_comanda'] ?? '') === 'mandadito') ? 'Mandadito' : (string) ($row['restaurante'] ?? '—');
                            $cerrado = in_array((string) $row['estatus'], ['Entregado', 'Cancelado'], true);
                            echo '<tr>';
                            echo '<td><b>#' . yora_h($codigo) . '</b></td>';
                            echo '<td>' . $fecha_format . '</td>';
                            echo '<td><b>' . htmlspecialchars($origen) . '</b>'
                                . ((($row['tipo_comanda'] ?? '') === 'mandadito')
                                    ? '<br><span style="font-size:0.75rem;color:#e4441b;font-weight:700;">App cliente</span>'
                                    : '')
                                . '</td>';
                            echo '<td><b>' . htmlspecialchars($nombre_cliente) . '</b><br><span style="font-size:0.8rem; color:var(--text-muted);">' . htmlspecialchars($tel_cliente) . '</span></td>';
                            echo '<td>' . htmlspecialchars($row['conductor'] ?? 'Buscando...') . '</td>';
                            echo '<td><span style="color:' . $color . '; background:' . $bg . '; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem;">' . yora_h($row['estatus']) . '</span></td>';
                            echo '<td><button class="btn-ver"
                                data-dbid="' . (int) $row['id'] . '"
                                data-id="' . yora_h($codigo) . '"
                                data-cerrado="' . ($cerrado ? '1' : '0') . '"
                                data-comercio="' . htmlspecialchars($origen, ENT_QUOTES, 'UTF-8') . '"
                                data-tipo="' . htmlspecialchars((string) ($row['tipo_comanda'] ?? ''), ENT_QUOTES, 'UTF-8') . '"
                                data-driver="' . htmlspecialchars($row['conductor'] ?? 'Sin asignar', ENT_QUOTES, 'UTF-8') . '"
                                data-cliente="' . htmlspecialchars($nombre_cliente, ENT_QUOTES, 'UTF-8') . '"
                                data-telefono="' . htmlspecialchars($tel_cliente, ENT_QUOTES, 'UTF-8') . '"
                                data-recogida="' . htmlspecialchars((string) ($row['direccion_recogida'] ?? ''), ENT_QUOTES, 'UTF-8') . '"
                                data-encargo="' . htmlspecialchars((string) ($row['detalles_entrega'] ?? ''), ENT_QUOTES, 'UTF-8') . '"
                                data-detalles="' . htmlspecialchars($direccion_paquete, ENT_QUOTES, 'UTF-8') . '"
                                data-costo="' . number_format((float) ($row['costo_delivery'] ?? 0), 2, '.', '') . '"
                                data-km="' . number_format((float) ($row['distancia_km'] ?? 0), 2, '.', '') . '"
                                data-lat="' . yora_h((string) $elat) . '"
                                data-lng="' . yora_h((string) $elng) . '"
                                data-fotorec="' . htmlspecialchars($row['foto_recogida'] ?? '', ENT_QUOTES, 'UTF-8') . '"
                                data-fotoent="' . htmlspecialchars($row['foto_entrega'] ?? '', ENT_QUOTES, 'UTF-8') . '"
                                onclick="abrirModalAuditoria(this)">
                                <i class="ph ph-wrench"></i> Gestionar
                            </button></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="7" style="text-align:center; color:#9ca3af; padding:50px;">No hay comandas registradas.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modal-evidencia" class="modal-overlay">
        <div class="modal-box">
            <span class="close-modal" onclick="cerrarModal()">&times;</span>
            <h3 style="margin-top:0; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">Viaje <span id="m_orden"></span></h3>
            <div class="info-caja">
                <p>🍔 <b id="m_comercio_label">Comercio:</b> <span id="m_comercio"></span></p>
                <p>🛵 <b>Driver:</b> <span id="m_driver"></span></p>
                <p>👤 <b>Cliente:</b> <span id="m_cliente"></span> - 📞 <span id="m_telefono" style="color:var(--yora-orange); font-weight:bold;"></span></p>
                <p id="m_fila_recogida" style="display:none;">📍 <b>Recoger en:</b> <span id="m_recogida"></span></p>
                <p id="m_fila_encargo" style="display:none;">📝 <b>Encargo:</b> <span id="m_encargo"></span></p>
                <p>📦 <b id="m_detalles_label">Paquete / Dir:</b> <span id="m_detalles"></span></p>
                <p style="margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:8px;">💵 <b>Costo:</b> $<span id="m_costo"></span> · <b>Km:</b> <span id="m_km"></span></p>
            </div>

            <div id="ops-activas" class="ops">
                <div class="op">
                    <h4>1. Quitar motorizado y devolver al radar</h4>
                    <p>Si se tardó o no puede seguir: el viaje queda libre para otro.</p>
                    <form method="POST" onsubmit="return confirm('¿Quitar a este motorizado y publicar el viaje otra vez?');">
                        <?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="comanda_id" class="hid-id">
                        <input type="hidden" name="accion" value="liberar">
                        <button class="btn-act btn-rojo" type="submit">Liberar viaje</button>
                    </form>
                </div>
                <div class="op">
                    <h4>2. Asignar a otro motorizado</h4>
                    <p>HQ elige quién lo toma. Si el paquete ya fue recogido, avísale por teléfono.</p>
                    <form method="POST" onsubmit="return confirm('¿Asignar este viaje al motorizado elegido?');">
                        <?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="comanda_id" class="hid-id">
                        <input type="hidden" name="accion" value="asignar">
                        <label>Motorizado</label>
                        <select name="driver_id" required>
                            <option value="">Elegir…</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?php echo (int) $d['id']; ?>">
                                    <?php echo yora_h($d['nombre']); ?>
                                    <?php echo ((int) ($d['en_linea'] ?? 0) === 1) ? ' · en línea' : ''; ?>
                                    <?php echo !empty($d['telefono']) ? ' · ' . yora_h($d['telefono']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-act btn-naranja" type="submit" style="margin-top:8px;">Asignar ahora</button>
                    </form>
                </div>
                <div class="op">
                    <h4>4. Abrir geocerca (emergencia)</h4>
                    <p>Si el motorizado ya está en el sitio real y el candado no lo deja entregar, esto habilita la foto ya. Conviene también corregir el pin.</p>
                    <form method="POST" onsubmit="return confirm('¿Permitir recoger o entregar aunque el GPS no coincida con el pin?');">
                        <?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="comanda_id" class="hid-id">
                        <input type="hidden" name="accion" value="geocerca">
                        <button class="btn-act btn-verde" type="submit">Permitir foto aquí</button>
                    </form>
                </div>
            </div>
            <div class="ops">
                <div class="op">
                    <h4>3. Corregir destino y tarifa</h4>
                    <p>Pega las coordenadas de Google Maps (clic derecho en el punto). Recalcula km, cobra o devuelve la diferencia al comercio (y al driver si ya se entregó) y actualiza el pin.</p>
                    <form method="POST">
                        <?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="comanda_id" class="hid-id">
                        <input type="hidden" name="accion" value="corregir">
                        <label>Dirección</label>
                        <input type="text" name="direccion" id="f_dir" required>
                        <div class="row2">
                            <div><label>Latitud</label><input type="text" name="lat" id="f_lat" required></div>
                            <div><label>Longitud</label><input type="text" name="lng" id="f_lng" required></div>
                        </div>
                        <div class="row2">
                            <div><label>Km (vacío = calcular)</label><input type="number" step="0.01" name="km" id="f_km" placeholder="Automático"></div>
                            <div><label>Costo $ (vacío = tarifa)</label><input type="number" step="0.01" name="costo" id="f_costo" placeholder="Automático"></div>
                        </div>
                        <button class="btn-act btn-oscuro" type="submit" style="margin-top:8px;">Guardar corrección</button>
                    </form>
                </div>
            </div>

            <h4 style="margin:18px 0 10px;">Evidencias</h4>
            <div style="display:flex; gap:15px;">
                <div class="foto-box">
                    <p style="font-weight:700; margin-bottom:8px;">En el local</p>
                    <div id="m_foto_rec"></div>
                </div>
                <div class="foto-box">
                    <p style="font-weight:700; margin-bottom:8px;">Entregado</p>
                    <div id="m_foto_ent"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function filtrar() {
            const q = (document.getElementById('busca').value || '').toLowerCase();
            document.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.style.display = tr.innerText.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
            });
        }
        function abrirModalAuditoria(btn) {
            const esMandadito = (btn.dataset.tipo || '') === 'mandadito';
            document.getElementById('m_orden').innerText = "#" + btn.dataset.id;
            document.getElementById('m_comercio').innerText = btn.dataset.comercio;
            document.getElementById('m_comercio_label').innerText = esMandadito ? 'Origen:' : 'Comercio:';
            document.getElementById('m_driver').innerText = btn.dataset.driver;
            document.getElementById('m_cliente').innerText = btn.dataset.cliente;
            document.getElementById('m_telefono').innerText = btn.dataset.telefono;
            document.getElementById('m_detalles').innerText = btn.dataset.detalles;
            document.getElementById('m_detalles_label').innerText = esMandadito ? 'Entregar en:' : 'Paquete / Dir:';
            document.getElementById('m_costo').innerText = btn.dataset.costo;
            document.getElementById('m_km').innerText = btn.dataset.km;
            document.getElementById('m_recogida').innerText = btn.dataset.recogida || '';
            document.getElementById('m_encargo').innerText = btn.dataset.encargo || '';
            document.getElementById('m_fila_recogida').style.display = (esMandadito && btn.dataset.recogida) ? 'block' : 'none';
            document.getElementById('m_fila_encargo').style.display = (esMandadito && btn.dataset.encargo) ? 'block' : 'none';
            document.querySelectorAll('.hid-id').forEach(function (el) { el.value = btn.dataset.dbid; });
            document.getElementById('f_dir').value = btn.dataset.detalles || '';
            document.getElementById('f_lat').value = btn.dataset.lat || '';
            document.getElementById('f_lng').value = btn.dataset.lng || '';
            document.getElementById('f_km').value = '';
            document.getElementById('f_costo').value = '';
            document.getElementById('ops-activas').style.display = btn.dataset.cerrado === '1' ? 'none' : 'grid';

            function urlSegura(valor) {
                if (!valor || valor === "null") return '';
                let limpio = String(valor).trim();
                if (!/^https?:\/\//i.test(limpio) || /[\s"'<>]/.test(limpio)) return '';
                return limpio.replaceAll('&', '&amp;').replaceAll('"', '&quot;');
            }
            let fotorec = urlSegura(btn.dataset.fotorec);
            let fotoent = urlSegura(btn.dataset.fotoent);
            document.getElementById('m_foto_rec').innerHTML = fotorec
                ? `<a href="${fotorec}" target="_blank" rel="noopener"><img src="${fotorec}"></a>`
                : `<p class="sin-foto">Sin evidencia</p>`;
            document.getElementById('m_foto_ent').innerHTML = fotoent
                ? `<a href="${fotoent}" target="_blank" rel="noopener"><img src="${fotoent}"></a>`
                : `<p class="sin-foto">Sin evidencia</p>`;
            document.getElementById('modal-evidencia').style.display = 'flex';
        }
        function cerrarModal() {
            document.getElementById('modal-evidencia').style.display = 'none';
        }
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) cerrarModal();
        }
    </script>
</body>
</html>
