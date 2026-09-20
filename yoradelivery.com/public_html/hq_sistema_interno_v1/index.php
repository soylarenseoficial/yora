<?php

require_once 'conexion.php';
yora_require_admin_pagina('index.php');
yora_timezone($conexion);
if (is_file(__DIR__ . '/../../yora_creditos.php')) {
    require_once __DIR__ . '/../../yora_creditos.php';
}

// 1. Ganancias Netas Yora (20% por viaje entregado)
$ganancia_yora = 0;
$res_yora = $conexion->query("SELECT SUM(costo_delivery * 0.20) as ganancia FROM comandas WHERE estatus = 'Entregado'");
if ($res_yora) {
    $row = $res_yora->fetch_assoc();
    $ganancia_yora = floatval($row['ganancia'] ?? 0);
}

// 2. Comercios + crédito activo (no billetera prepago)
$total_comercios = 0;
$credito_comprometido = 0.0;
$credito_limite_total = 0.0;
$saldo_extra_historico = 0.0;
$res_comercios = $conexion->query("SELECT id, billetera, credito_limite, credito_consumido_ciclo, penalizacion_pendiente FROM comercios");
if ($res_comercios) {
    while ($row = $res_comercios->fetch_assoc()) {
        $total_comercios++;
        $saldo_extra_historico += (float) ($row['billetera'] ?? 0);
        $credito_limite_total += (float) ($row['credito_limite'] ?? 0);
        $credito_comprometido += (float) ($row['credito_consumido_ciclo'] ?? 0) + (float) ($row['penalizacion_pendiente'] ?? 0);
    }
}
$facturas_abiertas = 0.0;
$res_fac = $conexion->query("SELECT COALESCE(SUM(monto + penalizacion),0) AS t FROM facturas_comercio WHERE estatus IN ('pendiente','gracia','vencida')");
if ($res_fac) {
    $facturas_abiertas = (float) ($res_fac->fetch_assoc()['t'] ?? 0);
}
$credito_comprometido += $facturas_abiertas;
$credito_disponible_sistema = max(0, $credito_limite_total - $credito_comprometido);

// 3. Conductores Totales
$total_conductores = 0;
$res_cond = $conexion->query("SELECT COUNT(*) as total FROM conductores");
if ($res_cond) {
    $row = $res_cond->fetch_assoc();
    $total_conductores = intval($row['total'] ?? 0);
}

// 4. Viajes de Hoy
$viajes_hoy = 0;
$res_viajes = $conexion->query("SELECT COUNT(*) as total FROM comandas WHERE estatus = 'Entregado' AND DATE(fecha_creacion) = CURDATE()");
if ($res_viajes) {
    $row = $res_viajes->fetch_assoc();
    $viajes_hoy = intval($row['total'] ?? 0);
}

$drivers_en_linea = 0;
$res_on = $conexion->query("SELECT COUNT(*) AS total FROM conductores WHERE en_linea = 1 AND estatus = 'activo'");
if ($res_on) {
    $drivers_en_linea = (int) ($res_on->fetch_assoc()['total'] ?? 0);
}

$comercios_en_panel = 0;
$res_panel = $conexion->query("SELECT ultima_conexion FROM comercios");
if ($res_panel) {
    while ($tmp = $res_panel->fetch_assoc()) {
        if (yora_comercio_online($tmp['ultima_conexion'] ?? null)) {
            $comercios_en_panel++;
        }
    }
}

// 5. Últimos comercios registrados
$ultimos_comercios = $conexion->query("SELECT id, nombre, telefono, billetera, credito_limite FROM comercios ORDER BY id DESC LIMIT 5");

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Tablero</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
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

        .sidebar { width: 280px; min-width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; z-index: 10; box-shadow: 2px 0 10px rgba(0,0,0,0.02); }
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(206,78,45,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item .icon { font-size: 1.1rem; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(206,78,45,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }
        .logout-btn:hover { background-color: #fee2e2; color: #ef4444; }

        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 2rem; margin-bottom: 5px; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .header-subtitle { color: var(--text-muted); margin-bottom: 30px; font-size: 0.95rem; }
        
        .hero-balance { background: #1e293b; border-radius: 20px; padding: 35px 40px; color: white; display: flex; justify-content: space-between; align-items: center; width: 100%; position: relative; overflow: hidden; box-shadow: 0 10px 25px rgba(30,41,59,0.2); margin-bottom: 30px;}
        .hero-balance .text-area { z-index: 2; position: relative; }
        .hero-balance h2 { font-size: 3.5rem; font-weight: 800; margin: 5px 0; color: #10b981;}
        .hero-balance p { color: #94a3b8; font-weight: 600; font-size: 1rem; text-transform:uppercase; letter-spacing:1px;}
        .hero-balance i { position: absolute; right: -20px; top: 50%; transform: translateY(-50%); font-size: 14rem; color: rgba(255,255,255,0.03); z-index: 1;}

        .kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 40px; }
        .kpi-card { background: var(--bg-card); padding: 25px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; }
        .kpi-card .info p { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 5px;}
        .kpi-card .info h3 { font-size: 1.8rem; font-weight: 800; color: var(--text-main); margin: 0; }
        .kpi-card .icon { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 1.6rem; }
        
        .dashboard-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        .card { background: var(--bg-card); padding: 25px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; color: var(--text-main); font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; font-weight: 600; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; }
        th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; }
        .saldo-badge { background: rgba(206, 78, 45, 0.1); color: var(--yora-orange); padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;}
        .flota-box { text-align: center; padding: 20px 10px; }
        #mapa-flota { height: 420px; width: 100%; border-radius: 16px; border: 1px solid var(--border-color); }
        .mapa-card { margin-bottom: 30px; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 class="header-title">Visión General</h1>
        <p class="header-subtitle">Bienvenido al Centro de Comando de Yora Delivery, <b><?php echo yora_h($_SESSION['admin_nombre'] ?? 'Admin'); ?></b>.</p>
        
        <div class="hero-balance">
            <i class="ph ph-vault"></i>
            <div class="text-area">
                <p>Ganancias Netas Yora (20% x Viaje)</p>
                <h2>$<?php echo number_format($ganancia_yora, 2); ?></h2>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="info">
                    <p>Comercios Afiliados</p>
                    <h3 class="kpi-number"><?php echo $total_comercios; ?></h3>
                </div>
                <div class="icon" style="background:#f1f5f9; color:#475569;"><i class="ph ph-storefront"></i></div>
            </div>
            <div class="kpi-card">
                <div class="info">
                    <p>Crédito disponible</p>
                    <h3 class="kpi-number" style="color:#0f766e;">$<?php echo number_format($credito_disponible_sistema, 2); ?></h3>
                    <small style="color:#94a3b8;font-size:0.7rem;">Facturas $<?php echo number_format($facturas_abiertas, 2); ?><?php if ($saldo_extra_historico > 0.009): ?> · Extra +$<?php echo number_format($saldo_extra_historico, 2); ?><?php endif; ?></small>
                </div>
                <div class="icon" style="background:#ecfdf5; color:#0f766e;"><i class="ph ph-credit-card"></i></div>
            </div>
            <div class="kpi-card">
                <div class="info">
                    <p>Conductores · en línea</p>
                    <h3 class="kpi-number"><?php echo (int) $drivers_en_linea; ?> <span style="font-size:0.9rem;color:#16a34a;font-weight:700;">/ <?php echo $total_conductores; ?></span></h3>
                </div>
                <div class="icon" style="background:#dcfce7; color:#16a34a;"><i class="ph ph-motorcycle"></i></div>
            </div>
            <div class="kpi-card">
                <div class="info">
                    <p>Comercios en el panel</p>
                    <h3 class="kpi-number" style="color:#16a34a;"><?php echo (int) $comercios_en_panel; ?></h3>
                </div>
                <div class="icon" style="background:#dcfce7; color:#16a34a;"><i class="ph ph-storefront"></i></div>
            </div>
            <div class="kpi-card">
                <div class="info">
                    <p>Viajes de Hoy</p>
                    <h3 class="kpi-number" style="color:#10b981;"><?php echo $viajes_hoy; ?></h3>
                </div>
                <div class="icon" style="background:#dcfce7; color:#16a34a;"><i class="ph ph-package"></i></div>
            </div>
        </div>

        <div class="card mapa-card">
            <h3>Mapa · conductores en línea <a href="mapa.php" style="float:right; font-size:0.8rem; color:#ce4e2d; text-decoration:none; font-weight:700;">Abrir mapa completo →</a></h3>
            <div id="mapa-flota"></div>
        </div>

        <div class="dashboard-layout">
            <div class="card">
                <h3>Últimos Comercios Registrados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Comercio</th>
                            <th>Contacto</th>
                            <th>Crédito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($ultimos_comercios): ?>
                            <?php while($row = $ultimos_comercios->fetch_assoc()):
                                $cid = (int) ($row['id'] ?? 0);
                                $cred = function_exists('yora_credito_estado')
                                    ? yora_credito_estado($conexion, $cid)
                                    : ['disponible' => 0, 'limite' => (float) ($row['credito_limite'] ?? 0)];
                                $extra = round((float) ($row['billetera'] ?? 0), 2);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['telefono']); ?></td>
                                <td>
                                    <span class="saldo-badge">$<?php echo number_format((float) ($cred['disponible'] ?? 0), 2); ?></span>
                                    <?php if ($extra > 0.009): ?>
                                        <span style="color:#16a34a;font-weight:800;font-size:0.78rem;margin-left:6px;">+$<?php echo number_format($extra, 2); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                        <?php if($total_comercios == 0): ?>
                            <tr><td colspan="3" style="text-align:center; color:#888; padding:20px;">No hay comercios registrados aún.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Estado de la Flota</h3>
                <div class="flota-box">
                    <i class="ph ph-broadcast" style="color:<?php echo $drivers_en_linea > 0 ? '#16a34a' : '#64748b'; ?>; font-size:4rem;"></i>
                    <h4 style="margin: 10px 0 5px; color:#1e293b; font-size:1.1rem;">
                        <?php echo (int) $drivers_en_linea; ?> driver<?php echo $drivers_en_linea === 1 ? '' : 's'; ?> en línea
                    </h4>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">
                        <?php echo (int) $comercios_en_panel; ?> comercio<?php echo $comercios_en_panel === 1 ? '' : 's'; ?> conectado<?php echo $comercios_en_panel === 1 ? '' : 's'; ?> al panel ahora.
                    </p>
                    <p style="margin-top:12px;"><a href="conductores.php" style="color:#ce4e2d; font-weight:700; text-decoration:none;">Ver conductores →</a></p>
                </div>
            </div>
        </div>

    </div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="mapa_flota.js?v=4"></script>
<script>pintarMapaFlota('mapa-flota', { ajustar: true });</script>
</body>
</html>