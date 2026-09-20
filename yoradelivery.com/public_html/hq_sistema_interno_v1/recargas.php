<?php
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/../../config.php';
yora_require_admin_pagina('recargas.php');
$conexion->set_charset("utf8mb4");
$conexion->query("SET time_zone = '-04:00'");

// 1. PETICIÓN AJAX EN TIEMPO REAL
if(isset($_GET['ajax_recargas'])) {
    $sql = "SELECT rs.*, r.nombre, r.tipo_comercio 
            FROM recargas_saldo rs 
            JOIN comercios r ON rs.comercio_id = r.id 
            ORDER BY rs.id DESC";
    $res = $conexion->query($sql);

    if($res && $res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            
            // Empaquetamos todo para el popup
            $json_data = htmlspecialchars(json_encode([
                'id' => $row['id'],
                'comercio' => $row['nombre'],
                'nivel' => $row['tipo_comercio'],
                'fecha' => date("d/m/Y h:i A", strtotime($row['fecha_registro'])),
                'monto_usd' => number_format($row['monto'], 2),
                'monto_bs' => number_format($row['monto_bs'], 2), // Formateado
                'tasa' => number_format($row['tasa_bcv'], 2),
                'banco' => $row['banco_origen'],
                'referencia' => $row['referencia'],
                'estatus' => $row['estatus']
            ]), ENT_QUOTES, 'UTF-8');

            if($row['estatus'] == 'En Revisión') {
                $badge = '<span style="color:#d97706; background:#fef3c7; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">En Revisión</span>';
            } else if($row['estatus'] == 'Aprobada') {
                $badge = '<span style="color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">✅ Acreditado</span>';
            } else {
                $badge = '<span style="color:#dc2626; background:#fee2e2; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">❌ Rechazado</span>';
            }

            echo '<tr style="cursor:pointer; transition:0.2s;" onmouseover="this.style.backgroundColor=\'#f8fafc\'" onmouseout="this.style.backgroundColor=\'transparent\'" onclick="abrirModalRecarga('.$json_data.')">
                <td>'.date("d/m/Y h:i A", strtotime($row['fecha_registro'])).'</td>
                <td><b>'.htmlspecialchars($row['nombre']).'</b><br><span style="font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px;">Tipo: '.yora_h($row['tipo_comercio']).' · mín. $'.number_format(yora_min_recarga_comercio($row['tipo_comercio']), 0).'</span></td>
                <td><span style="font-size:0.85rem; color:#475569;">🏦 '.yora_h($row['banco_origen']).' <br><b>Ref: '.yora_h($row['referencia']).'</b></span></td>
                <td style="color:#10b981; font-weight:800; font-size:1.1rem;">$'.number_format($row['monto'], 2).'</td>
                <td>'.$badge.'</td>
                <td><button style="background:#f1f5f9; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:0.8rem; color:#475569;">Auditar ❯</button></td>
            </tr>';
        }
    } else {
        echo '<tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:50px 20px;">No hay reportes de recargas recientes.</td></tr>';
    }
    $conexion->close(); exit;
}

if (isset($_GET['ajax_recargas_clientes'])) {
    $sql = "SELECT rc.*, u.nombre, u.telefono, c.codigo AS comanda_codigo
            FROM recargas_clientes rc
            LEFT JOIN usuarios_app u ON u.id = rc.usuario_id
            LEFT JOIN comandas c ON c.id = rc.comanda_id
            ORDER BY rc.id DESC LIMIT 200";
    $res = $conexion->query($sql);
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $json_data = htmlspecialchars(json_encode([
                'id' => $row['id'],
                'cliente' => $row['nombre'] ?: 'Cliente',
                'telefono' => $row['telefono'] ?: '',
                'fecha' => date('d/m/Y h:i A', strtotime($row['fecha_registro'])),
                'monto_usd' => number_format($row['monto'], 2),
                'monto_bs' => number_format($row['monto_bs'], 2),
                'tasa' => number_format($row['tasa_bcv'], 2),
                'banco' => $row['banco_origen'],
                'referencia' => $row['referencia'],
                'estatus' => $row['estatus'],
                'viaje' => $row['comanda_id'] ? ('Viaje #' . ($row['comanda_codigo'] ?: $row['comanda_id'])) : 'Recarga billetera',
            ]), ENT_QUOTES, 'UTF-8');
            if ($row['estatus'] == 'En Revisión') {
                $badge = '<span style="color:#d97706; background:#fef3c7; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">En Revisión</span>';
            } elseif ($row['estatus'] == 'Aprobada') {
                $badge = '<span style="color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">✅ Acreditado</span>';
            } else {
                $badge = '<span style="color:#dc2626; background:#fee2e2; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">❌ Rechazado</span>';
            }
            echo '<tr style="cursor:pointer;" onclick="abrirModalCliente('.$json_data.')">
                <td>'.date('d/m/Y h:i A', strtotime($row['fecha_registro'])).'</td>
                <td><b>'.htmlspecialchars($row['nombre'] ?: 'Cliente').'</b><br><span style="font-size:0.75rem;color:#64748b;">'.htmlspecialchars($row['telefono'] ?: '').'</span></td>
                <td>'.htmlspecialchars($row['comanda_id'] ? ('Viaje #' . ($row['comanda_codigo'] ?: $row['comanda_id'])) : 'Recarga billetera').'<br><span style="font-size:0.75rem;color:#64748b;">'.yora_h($row['banco_origen']).' · Ref '.yora_h($row['referencia']).'</span></td>
                <td style="color:#10b981;font-weight:800;">$'.number_format($row['monto'], 2).'</td>
                <td>'.$badge.'</td>
            </tr>';
        }
    } else {
        echo '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:40px;">No hay pagos de clientes.</td></tr>';
    }
    $conexion->close();
    exit;
}

// 2. ACCIONES DE APROBAR / RECHAZAR
if(isset($_POST['accion']) && isset($_POST['recarga_id'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: recargas.php'); exit; }
    $id_recarga = (int) $_POST['recarga_id'];
    $accion = $_POST['accion'];
    $conexion->begin_transaction();
    $info = yora_one($conexion, 'SELECT comercio_id, monto, estatus, factura_id FROM recargas_saldo WHERE id = ? FOR UPDATE', 'i', $id_recarga);
    if ($info && $info['estatus'] === 'En Revisión') {
        if ($accion === 'aprobar') {
            if (yora_exec($conexion, "UPDATE recargas_saldo SET estatus = 'Aprobada' WHERE id = ? AND estatus = 'En Revisión'", 'i', $id_recarga) > 0) {
                $cid = (int) $info['comercio_id'];
                $fid = (int) ($info['factura_id'] ?? 0);
                $monto = round((float) $info['monto'], 2);
                if ($fid > 0 && function_exists('yora_credito_aplicar_pago')) {
                    $fac = yora_one($conexion, 'SELECT monto, penalizacion, estatus FROM facturas_comercio WHERE id = ? AND comercio_id = ?', 'ii', $fid, $cid);
                    $pen = round((float) ($fac['penalizacion'] ?? 0), 2);
                    yora_credito_aplicar_pago($conexion, $cid, [$fid], $pen);
                } elseif (function_exists('yora_credito_aplicar_pago')) {
                    // Pago libre: cubre facturas antiguas FIFO hasta el monto
                    $abiertas = yora_all(
                        $conexion,
                        "SELECT id, monto, penalizacion FROM facturas_comercio
                         WHERE comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')
                         ORDER BY fecha_consumo ASC, id ASC",
                        'i',
                        $cid
                    ) ?: [];
                    $restante = $monto;
                    $ids = [];
                    $penPagada = 0.0;
                    foreach ($abiertas as $f) {
                        $totalF = round((float) $f['monto'] + (float) $f['penalizacion'], 2);
                        if ($restante + 0.001 < $totalF) {
                            break;
                        }
                        $ids[] = (int) $f['id'];
                        $penPagada += (float) $f['penalizacion'];
                        $restante = round($restante - $totalF, 2);
                    }
                    if ($ids) {
                        yora_credito_aplicar_pago($conexion, $cid, $ids, $penPagada);
                    }
                } else {
                    yora_exec($conexion, 'UPDATE comercios SET billetera = billetera + ? WHERE id = ?', 'di', $monto, $cid);
                }
            }
        } elseif ($accion === 'rechazar') {
            yora_exec($conexion, "UPDATE recargas_saldo SET estatus = 'Rechazada' WHERE id = ? AND estatus = 'En Revisión'", 'i', $id_recarga);
        }
    }
    $conexion->commit();
    header("Location: recargas.php"); exit;
}

if (isset($_POST['accion_cli']) && isset($_POST['recarga_cli_id'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: recargas.php'); exit; }
    $id_recarga = (int) $_POST['recarga_cli_id'];
    $accion = (string) $_POST['accion_cli'];
    $conexion->begin_transaction();
    $info = yora_one($conexion, 'SELECT * FROM recargas_clientes WHERE id = ? FOR UPDATE', 'i', $id_recarga);
    if ($info && $info['estatus'] === 'En Revisión') {
        if ($accion === 'aprobar') {
            if (yora_exec($conexion, "UPDATE recargas_clientes SET estatus = 'Aprobada' WHERE id = ? AND estatus = 'En Revisión'", 'i', $id_recarga) > 0) {
                $cid = (int) ($info['comanda_id'] ?? 0);
                if ($cid > 0) {
                    yora_exec($conexion, "UPDATE comandas SET estatus = 'Buscando Conductor' WHERE id = ? AND estatus = 'Esperando Pago'", 'i', $cid);
                    try {
                        yora_push_conductores($conexion, 'Nuevo mandadito en el radar', 'Un usuario pagó un mandadito. Entra al radar.', '/dashboard.php', true);
                    } catch (Throwable $e) {
                    }
                } else {
                    yora_exec($conexion, 'UPDATE usuarios_app SET billetera = billetera + ? WHERE id = ?', 'di', (float) $info['monto'], (int) $info['usuario_id']);
                }
            }
        } elseif ($accion === 'rechazar') {
            yora_exec($conexion, "UPDATE recargas_clientes SET estatus = 'Rechazada' WHERE id = ? AND estatus = 'En Revisión'", 'i', $id_recarga);
            $cid = (int) ($info['comanda_id'] ?? 0);
            if ($cid > 0) {
                yora_exec($conexion, "UPDATE comandas SET estatus = 'Cancelado' WHERE id = ? AND estatus = 'Esperando Pago'", 'i', $cid);
            }
        }
    }
    $conexion->commit();
    header('Location: recargas.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Validación de Recargas</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange: #e4441b; --bg-body: #f4f6f8; --bg-card: #ffffff; --border-color: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        .sidebar { width: 280px; min-width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; box-sizing: border-box; z-index: 10;}
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(228,68,27,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(228,68,27,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }

        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 1.8rem; margin-bottom: 5px; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .header-subtitle { color: var(--text-muted); margin-bottom: 30px; font-size: 0.95rem; }

        .card-table { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); width: 100%; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead tr { background: #fafafa; border-bottom: 1px solid var(--border-color); }
        th { padding: 16px 24px; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; }
        td { padding: 18px 24px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; vertical-align: middle; }
        
        /* MODAL POPUP */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.8); z-index: 9999; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 450px; max-width: 90%; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.2);}
        .btn-close { position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;}
        
        .detalle-row { display:flex; justify-content:space-between; margin-bottom:12px; border-bottom:1px dashed #e2e8f0; padding-bottom:8px;}
        .detalle-row span { color:#64748b; font-size:0.85rem;}
        .detalle-row b { color:#1e293b; font-size:0.9rem;}

        .btn-act { padding: 12px; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; border: none; transition: 0.2s; width:100%; font-family:inherit;}
        .btn-aprob { background: #10b981; color: white; margin-bottom:10px; box-shadow: 0 4px 10px rgba(16,185,129,0.3);} .btn-aprob:hover { background: #059669; }
        .btn-rech { background: transparent; color: #dc2626; border:1px solid #fca5a5;} .btn-rech:hover { background: #fee2e2; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 class="header-title">Auditoría y Recargas de Comercios <span style="font-size: 1rem; color: #10b981; animation: pulse 2s infinite; vertical-align: middle; margin-left: 10px;">● En vivo</span></h1>
        <p class="header-subtitle">Verifica las referencias bancarias y aprueba los créditos prepagados para los locales.</p>

        <div class="card-table">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Restaurante / Comercio</th>
                        <th>Detalles del Pago</th>
                        <th>Monto a Acreditar</th>
                        <th>Estatus</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="tabla-recargas-vivo">
                    <tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:50px 20px;">Sincronizando reportes...</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="header-title" style="font-size:1.35rem;margin-top:36px;">Pagos de clientes (app)</h2>
        <p class="header-subtitle">Recargas de billetera y mandaditos pagados por Pago Móvil o efectivo. Al aprobar un viaje, sale al radar. El motorizado recibe el 80% al entregar.</p>
        <div class="card-table">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Detalle</th>
                        <th>Monto</th>
                        <th>Estatus</th>
                    </tr>
                </thead>
                <tbody id="tabla-recargas-clientes">
                    <tr><td colspan="5" style="text-align:center; color:#9ca3af; padding:40px;">Sincronizando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- POPUP MODAL DE AUDITORÍA -->
    <div id="modal-auditoria" class="modal-overlay">
        <div class="modal-content">
            <button class="btn-close" onclick="cerrarModal()">&times;</button>
            <h3 style="margin-top:0; margin-bottom:20px; color:#1e293b; border-bottom:2px solid #e4441b; padding-bottom:10px; display:inline-block;">Auditoría de Pago</h3>
            
            <div id="modal-body-info"></div>

            <div id="modal-acciones" style="margin-top:25px;"></div>
        </div>
    </div>

    <script>
        async function sincronizarRecargas() {
            try {
                let res = await fetch('recargas.php?ajax_recargas=1');
                let html = await res.text();
                document.getElementById('tabla-recargas-vivo').innerHTML = html;
            } catch(e) {}
            try {
                let res2 = await fetch('recargas.php?ajax_recargas_clientes=1');
                let html2 = await res2.text();
                document.getElementById('tabla-recargas-clientes').innerHTML = html2;
            } catch(e) {}
        }
        sincronizarRecargas(); 
        setInterval(sincronizarRecargas, 5000); 

        // LÓGICA DEL POPUP
        function abrirModalRecarga(data) {
            document.getElementById('modal-auditoria').style.display = 'flex';
            
            let htmlInfo = `
                <div class="detalle-row"><span>Comercio:</span> <b>${data.comercio} <small>(${data.nivel})</small></b></div>
                <div class="detalle-row"><span>Fecha:</span> <b>${data.fecha}</b></div>
                <div class="detalle-row"><span>Método de Pago:</span> <b>${data.banco}</b></div>
                <div class="detalle-row"><span>Referencia:</span> <b style="color:#e4441b; font-size:1rem;">${data.referencia}</b></div>
                
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:15px; border-radius:10px; margin-top:15px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="color:#166534; font-weight:600;">Acreditar:</span>
                        <b style="font-size:1.5rem; color:#16a34a;">$${data.monto_usd}</b>
                    </div>
                </div>`;
            
            // SECCIÓN BOLÍVARES CORREGIDA:
            // Verificamos que monto_bs no sea nulo, vacío, ni "0.00"
            if(data.monto_bs && data.monto_bs !== "0.00" && data.monto_bs !== "0") {
                htmlInfo += `
                <div style="background:#f8fafc; padding:12px 15px; border-radius:10px; margin-top:10px; border:1px dashed #cbd5e1; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span style="font-size:0.8rem; color:#64748b; display:block;">Monto reportado en Bs:</span>
                        <small style="color:#94a3b8; font-size:0.75rem;">(Tasa BCV: ${data.tasa})</small>
                    </div>
                    <b style="font-size:1.2rem; color:#1e293b;">Bs. ${data.monto_bs}</b>
                </div>`;
            }

            document.getElementById('modal-body-info').innerHTML = htmlInfo;

            let htmlAcciones = '';
            if(data.estatus === 'En Revisión') {
                htmlAcciones = `
                <form method="POST"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="recarga_id" value="${data.id}">
                    <button type="submit" name="accion" value="aprobar" class="btn-act btn-aprob" onclick="return confirm('¿Confirmas que el dinero ya está en la cuenta del banco?')">✅ Aprobar y Sumar Saldo</button>
                    <button type="submit" name="accion" value="rechazar" class="btn-act btn-rech" onclick="return confirm('¿Rechazar este reporte de pago?')">❌ Rechazar Pago</button>
                </form>`;
            } else {
                let color_estatus = (data.estatus === 'Aprobada') ? '#16a34a' : '#dc2626';
                let msj_estatus = (data.estatus === 'Aprobada') ? 'Operación cerrada: Aprobada' : 'Operación cerrada: Rechazada';
                htmlAcciones = `<div style="text-align:center; padding:10px; border-radius:8px; border:1px dashed ${color_estatus}; color:${color_estatus}; font-weight:bold;">${msj_estatus}</div>`;
            }

            document.getElementById('modal-acciones').innerHTML = htmlAcciones;
        }

        function abrirModalCliente(data) {
            document.getElementById('modal-auditoria').style.display = 'flex';
            let htmlInfo = `
                <div class="detalle-row"><span>Cliente:</span> <b>${data.cliente}</b></div>
                <div class="detalle-row"><span>Teléfono:</span> <b>${data.telefono}</b></div>
                <div class="detalle-row"><span>Tipo:</span> <b>${data.viaje}</b></div>
                <div class="detalle-row"><span>Fecha:</span> <b>${data.fecha}</b></div>
                <div class="detalle-row"><span>Método:</span> <b>${data.banco}</b></div>
                <div class="detalle-row"><span>Referencia:</span> <b style="color:#e4441b;">${data.referencia}</b></div>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:15px; border-radius:10px; margin-top:15px; display:flex; justify-content:space-between;">
                    <span style="color:#166534; font-weight:600;">Monto:</span>
                    <b style="font-size:1.5rem; color:#16a34a;">$${data.monto_usd}</b>
                </div>`;
            if(data.monto_bs && data.monto_bs !== "0.00" && data.monto_bs !== "0") {
                htmlInfo += `<div style="background:#f8fafc; padding:12px 15px; border-radius:10px; margin-top:10px; display:flex; justify-content:space-between;">
                    <span>Bs reportados (tasa ${data.tasa})</span><b>Bs. ${data.monto_bs}</b></div>`;
            }
            document.getElementById('modal-body-info').innerHTML = htmlInfo;
            let htmlAcciones = '';
            if(data.estatus === 'En Revisión') {
                htmlAcciones = `
                <form method="POST"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="recarga_cli_id" value="${data.id}">
                    <button type="submit" name="accion_cli" value="aprobar" class="btn-act btn-aprob" onclick="return confirm('¿Confirmas el pago en la cuenta de Yora?')">✅ Aprobar</button>
                    <button type="submit" name="accion_cli" value="rechazar" class="btn-act btn-rech">❌ Rechazar</button>
                </form>`;
            } else {
                htmlAcciones = `<div style="text-align:center;color:#64748b;font-weight:700;">Cerrado: ${data.estatus}</div>`;
            }
            document.getElementById('modal-acciones').innerHTML = htmlAcciones;
        }

        function cerrarModal() {
            document.getElementById('modal-auditoria').style.display = 'none';
        }
    </script>
</body>
</html>