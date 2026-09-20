<?php

require_once __DIR__ . '/../../config.php';
yora_require_admin_pagina('gestion_pagos.php');
$conexion->set_charset("utf8mb4");

// 1. PETICIÓN AJAX EN TIEMPO REAL
if(isset($_GET['ajax_pagos'])) {
    $sql = "SELECT s.*, c.nombre, c.cedula, c.telefono, c.categoria, c.foto_perfil, c.banco_pago, c.telefono_pago, c.cedula_pago 
            FROM solicitudes_retiro s 
            JOIN conductores c ON s.conductor_id = c.id 
            ORDER BY s.id DESC";
    $res = $conexion->query($sql);
    
    if($res && $res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            $badge = ($row['estatus'] == 'Pendiente') ? '<span class="badge-status" style="background:#fef3c7; color:#d97706;">Pendiente</span>' : (($row['estatus'] == 'Pagado') ? '<span class="badge-status" style="background:#dcfce7; color:#16a34a;">Liquidado</span>' : '<span class="badge-status" style="background:#fee2e2; color:#dc2626;">Rechazado</span>');
            
            $foto = yora_url_archivo($row['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com');
            $banco = !empty($row['banco_pago']) ? htmlspecialchars($row['banco_pago']) : 'No registrado';
            $tel_p = !empty($row['telefono_pago']) ? htmlspecialchars($row['telefono_pago']) : 'N/A';
            $ced_p = !empty($row['cedula_pago']) ? htmlspecialchars($row['cedula_pago']) : 'N/A';

            // Verificamos si hay datos válidos de pago móvil
            $info_banco = ($banco != 'No registrado') ? '🏦 <b>'.$banco.'</b><br>📱 '.$tel_p.'<br>🪪 V-'.$ced_p : '<span style="color:#dc2626; font-size:0.8rem; font-weight:600;">⚠️ Sin datos bancarios</span>';

            echo '<tr>
                <td>'.date("d/m/Y h:i A", strtotime($row['fecha_solicitud'])).'</td>
                <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <img src="'.yora_h(yora_safe_url($foto, 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png')).'" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid #e2e8f0;">
                        <div><p style="margin:0; font-weight:600;">'.htmlspecialchars($row['nombre']).'</p><span style="font-size:0.75rem; background:#f1f5f9; padding:2px 6px; border-radius:4px; color:#475569;">'.yora_h($row['categoria']).'</span></div>
                    </div>
                </td>
                <td>
                    <div style="background:#f8fafc; padding:8px; border-radius:8px; border:1px dashed #cbd5e1; font-size:0.8rem;">
                        '.$info_banco.'
                    </div>
                </td>
                <td>
                    <p style="color:#e4441b; font-weight:800; font-size:1.15rem; margin:0;">$'.number_format($row['monto'], 2).'</p>
                    <p style="margin:0; font-size:0.8rem; color:#10b981; font-weight:600;" class="monto-bs" data-usd="'.$row['monto'].'">Calculando Bs...</p>
                </td>
                <td>'.$badge.'</td>
                <td>';
            if($row['estatus'] == 'Pendiente') {
                echo '<form method="POST" style="display:inline;">
                        '.yora_csrf_field().'
                        <input type="hidden" name="retiro_id" value="'.$row['id'].'">
                        <button type="submit" name="accion" value="aprobar" class="btn-act btn-aprob" onclick="return confirm(\'¿Confirmas que ya realizaste el Pago Móvil?\')">Marcar Pagado</button>
                        <button type="submit" name="accion" value="rechazar" class="btn-act btn-rech" onclick="return confirm(\'¿Devolver saldo a la app del driver?\')">Rechazar</button>
                      </form>';
            } else { echo '<span style="color:#9ca3af; font-size:0.85rem;">Cerrada</span>'; }
            echo '</td></tr>';
        }
    } else { echo '<tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:50px 20px;">No hay solicitudes registradas actualmente.</td></tr>'; }
    $conexion->close(); exit;
}

// 2. ACCIONES DE BOTONES
if(isset($_POST['accion']) && isset($_POST['retiro_id'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: gestion_pagos.php'); exit; }
    $id_retiro = (int) $_POST['retiro_id'];
    $conexion->begin_transaction();
    $datos = yora_one($conexion, 'SELECT conductor_id, monto, estatus FROM solicitudes_retiro WHERE id = ? FOR UPDATE', 'i', $id_retiro);
    if ($datos && $datos['estatus'] === 'Pendiente') {
        if ($_POST['accion'] === 'aprobar') {
            yora_exec($conexion, "UPDATE solicitudes_retiro SET estatus = 'Pagado', fecha_pago = NOW() WHERE id = ? AND estatus = 'Pendiente'", 'i', $id_retiro);
        } elseif ($_POST['accion'] === 'rechazar') {
            if (yora_exec($conexion, "UPDATE solicitudes_retiro SET estatus = 'Rechazado' WHERE id = ? AND estatus = 'Pendiente'", 'i', $id_retiro) > 0) {
                $cid = (int) $datos['conductor_id'];
                $monto = (float) $datos['monto'];
                yora_exec($conexion, 'UPDATE conductores SET billetera = billetera + ? WHERE id = ?', 'di', $monto, $cid);
            }
        }
    }
    $conexion->commit();
    header("Location: gestion_pagos.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Gestión de Pagos</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange: #e4441b; --bg-body: #f4f6f8; --bg-card: #ffffff; --border-color: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        .sidebar { width: 280px; min-width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; box-sizing: border-box; z-index: 10;}
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(228,68,27,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(228,68,27,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }

        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 1.8rem; margin-bottom: 5px; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); display:flex; justify-content:space-between; align-items:center;}
        .header-subtitle { color: var(--text-muted); margin-bottom: 30px; font-size: 0.95rem; }

        .card-table { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); width: 100%; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead tr { background: #fafafa; border-bottom: 1px solid var(--border-color); }
        th { padding: 16px 24px; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }
        td { padding: 18px 24px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; vertical-align: middle; }
        tbody tr:hover { background-color: #fafbfc; }

        .badge-status { padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; display: inline-block; }
        .btn-act { padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 0.8rem; cursor: pointer; border: none; transition: 0.2s; }
        .btn-aprob { background: #dcfce7; color: #16a34a; }
        .btn-aprob:hover { background: #16a34a; color: white; }
        .btn-rech { background: #fee2e2; color: #dc2626; margin-left: 6px; }
        .btn-rech:hover { background: #dc2626; color: white; }
    </style>
</head>
<body>

    <?php
    yora_timezone($conexion);
    $bcv_hq = yora_tasa_bcv($conexion);
    ?>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 class="header-title">
            Gestión de Pagos
            <span style="font-size:0.8rem; background:#e2e8f0; color:#475569; padding:6px 12px; border-radius:20px; font-weight:600;">Tasa BCV: Bs. <span id="tasa-bcv-display"><?php echo $bcv_hq['tasa'] > 0 ? number_format($bcv_hq['tasa'], 2) : '—'; ?></span></span>
        </h1>
        <p class="header-subtitle">Realiza los Pago Móviles a tus conductores utilizando la tasa oficial del BCV.<?php echo $bcv_hq['fecha'] !== '' ? ' Actualizada el ' . yora_h($bcv_hq['fecha']) . '.' : ''; ?></p>

        <div class="card-table">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Conductor</th>
                        <th>Datos de Pago Móvil</th>
                        <th>Monto a Pagar</th>
                        <th>Estatus</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-pagos-vivo">
                    <tr><td colspan="6" style="text-align:center; padding:50px;">Sincronizando Bóveda...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let tasaBCV = <?php echo json_encode((float) $bcv_hq['tasa']); ?>;

        async function obtenerDolarBCV() {
            try {
                let res = await fetch('tasa_bcv.php');
                let data = await res.json();
                if (data && Number(data.tasa) > 0) {
                    tasaBCV = parseFloat(data.tasa);
                }
            } catch (e) {}
            if (tasaBCV > 0) {
                document.getElementById('tasa-bcv-display').innerText = tasaBCV.toFixed(2);
            }
        }

        async function sincronizarPagos() {
            try {
                let res = await fetch('gestion_pagos.php?ajax_pagos=1');
                let html = await res.text();
                document.getElementById('tabla-pagos-vivo').innerHTML = html;
                
                document.querySelectorAll('.monto-bs').forEach(el => {
                    let usd = parseFloat(el.getAttribute('data-usd'));
                    let bs = usd * tasaBCV;
                    el.innerText = "Bs. " + bs.toFixed(2);
                });
            } catch(e) { console.log("Reconectando bóveda..."); }
        }

        obtenerDolarBCV().then(() => { 
            sincronizarPagos(); 
            setInterval(sincronizarPagos, 5000); 
        });
    </script>
</body>
</html>