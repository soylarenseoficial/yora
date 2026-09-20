<?php
require_once __DIR__ . '/../../config.php';

if (empty($_SESSION['admin_logged'])) {
    http_response_code(403);
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$conexion_sidebar = $conexion;
$pendientes_pagos = 0; $pendientes_recargas = 0; $pendientes_soporte = 0; $pendientes_docs = 0; $pendientes_comercios = 0; $pendientes_clientes = 0;
$rol_hq = yora_admin_rol();

if ($conexion_sidebar && !$conexion_sidebar->connect_error) {
    // Expedientes que los conductores ya enviaron a verificar desde la app.
    $res_docs = $conexion_sidebar->query("SELECT COUNT(id) as total FROM conductores WHERE estado_documentos = 'En Revisión'");
    if ($res_docs) $pendientes_docs = intval($res_docs->fetch_assoc()['total']);

    $res_count = $conexion_sidebar->query("SELECT COUNT(id) as total FROM solicitudes_retiro WHERE estatus = 'Pendiente'");
    if ($res_count) $pendientes_pagos = intval($res_count->fetch_assoc()['total']);

    $res_rec = $conexion_sidebar->query("SELECT COUNT(id) as total FROM recargas_saldo WHERE estatus = 'En Revisión'");
    if ($res_rec) $pendientes_recargas = intval($res_rec->fetch_assoc()['total']);
    $res_rec_cli = $conexion_sidebar->query("SELECT COUNT(id) as total FROM recargas_clientes WHERE estatus = 'En Revisión'");
    if ($res_rec_cli) $pendientes_recargas += intval($res_rec_cli->fetch_assoc()['total']);

    $res_sop = $conexion_sidebar->query("SELECT COUNT(id) as total FROM reportes_soporte WHERE estatus = 'Abierto'");
    if ($res_sop) $pendientes_soporte = intval($res_sop->fetch_assoc()['total']);

    $res_com = $conexion_sidebar->query("SELECT COUNT(id) as total FROM comercios WHERE estatus = 'pendiente'");
    if ($res_com) $pendientes_comercios = intval($res_com->fetch_assoc()['total']);

    $res_com_docs = $conexion_sidebar->query("SELECT COUNT(id) as total FROM comercios WHERE estado_documentos IN ('En Revisión', 'En Revision') OR (IFNULL(documento_url,'') <> '' AND IFNULL(estado_documentos,'') NOT IN ('Verificado', 'Rechazado'))");
    if ($res_com_docs) {
        $pendientes_comercios += intval($res_com_docs->fetch_assoc()['total']);
    }

    $res_cli = $conexion_sidebar->query("SELECT COUNT(id) as total FROM usuarios_app WHERE estado_documentos = 'En Revisión'");
    if ($res_cli) {
        $pendientes_clientes = intval($res_cli->fetch_assoc()['total']);
    }
}

$etiqueta_rol = [
    'super' => 'Super Admin',
    'comercios' => 'Comercios',
    'drivers' => 'Drivers',
];
?>
<div class="hq-topbar">
    <button type="button" class="hq-burger" onclick="hqMenu(true)" aria-label="Menú">☰</button>
    <strong>Yora<span>Admin</span></strong>
    <span style="width:42px;"></span>
</div>
<div class="hq-fondo" id="hq-fondo" onclick="hqMenu(false)"></div>
<div class="sidebar" id="hq-sidebar">
    <div class="sidebar-brand">
        <h2>Yora<span>Admin</span></h2>
        <span class="badge-app"><?php echo yora_h($etiqueta_rol[$rol_hq] ?? 'HQ'); ?></span>
        <button type="button" class="hq-cerrar" onclick="hqMenu(false)" aria-label="Cerrar">✕</button>
    </div>
    
    <?php if (yora_admin_puede('index.php') || yora_admin_puede('comercios.php') || yora_admin_puede('conductores.php') || yora_admin_puede('comandas.php')): ?>
    <div class="menu-category">Navegación</div>
    <?php endif; ?>
    <?php if (yora_admin_puede('index.php')): ?>
    <a href="index.php" class="menu-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
        <span class="icon">📊</span> Tablero General
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('comercios.php')): ?>
    <a href="comercios.php" class="menu-item <?php echo ($current_page == 'comercios.php') ? 'active' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center;">
        <span><span class="icon">🏪</span> Comercios</span>
        <?php if ($pendientes_comercios > 0): ?>
            <span style="background: #e4441b; color: white; padding: 2px 7px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $pendientes_comercios; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('directorio.php')): ?>
    <a href="directorio.php" class="menu-item <?php echo ($current_page == 'directorio.php') ? 'active' : ''; ?>">
        <span class="icon">🍽️</span> Directorio
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('conductores.php')): ?>
    <a href="conductores.php" class="menu-item <?php echo ($current_page == 'conductores.php') ? 'active' : ''; ?>">
        <span class="icon">🛵</span> Conductores
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('clientes.php')): ?>
    <a href="clientes.php" class="menu-item <?php echo ($current_page == 'clientes.php') ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
        <span><span class="icon">👤</span> Clientes</span>
        <?php if (!empty($pendientes_clientes)): ?>
            <span style="background:#e4441b;color:white;padding:2px 7px;border-radius:12px;font-size:0.75rem;font-weight:bold;"><?php echo (int) $pendientes_clientes; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('mapa.php')): ?>
    <a href="mapa.php" class="menu-item <?php echo ($current_page == 'mapa.php') ? 'active' : ''; ?>">
        <span class="icon">🗺️</span> Mapa
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('verificaciones.php')): ?>
    <a href="verificaciones.php" class="menu-item <?php echo ($current_page == 'verificaciones.php') ? 'active' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center;">
        <span><span class="icon">🛡️</span> Verificaciones</span>
        <?php if($pendientes_docs > 0): ?>
            <span style="background: #e4441b; color: white; padding: 2px 7px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $pendientes_docs; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('comandas.php')): ?>
    <a href="comandas.php" class="menu-item <?php echo ($current_page == 'comandas.php') ? 'active' : ''; ?>">
        <span class="icon">📦</span> Comandas (Viajes)
    </a>
    <?php endif; ?>
    
    <div class="menu-category" style="margin-top: 25px;">Sistema</div>
    
    <?php if (yora_admin_puede('gestion_pagos.php')): ?>
    <a href="gestion_pagos.php" class="menu-item <?php echo ($current_page == 'gestion_pagos.php') ? 'active' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center;">
        <span><span class="icon">🏦</span> Gestión de Pagos</span>
        <?php if($pendientes_pagos > 0): ?>
            <span style="background: #e4441b; color: white; padding: 2px 7px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $pendientes_pagos; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (yora_admin_puede('recargas.php')): ?>
    <a href="recargas.php" class="menu-item <?php echo ($current_page == 'recargas.php') ? 'active' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center;">
        <span><span class="icon">💳</span> Validación Recargas</span>
        <?php if($pendientes_recargas > 0): ?>
            <span style="background: #10b981; color: white; padding: 2px 7px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $pendientes_recargas; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (yora_admin_puede('soporte.php')): ?>
    <a href="soporte.php" class="menu-item <?php echo ($current_page == 'soporte.php') ? 'active' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center;">
        <span><span class="icon">🚨</span> Soporte Técnico</span>
        <?php if($pendientes_soporte > 0): ?>
            <span style="background: #dc2626; color: white; padding: 2px 7px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $pendientes_soporte; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (yora_admin_puede('tarifas.php')): ?>
    <a href="tarifas.php" class="menu-item <?php echo ($current_page == 'tarifas.php') ? 'active' : ''; ?>">
        <span class="icon">💵</span> Tarifas y Finanzas
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('tarifas.php') || yora_admin_puede('creditos_comercios.php')): ?>
    <a href="creditos_comercios.php" class="menu-item <?php echo ($current_page == 'creditos_comercios.php') ? 'active' : ''; ?>">
        <span class="icon">🧾</span> Créditos comercios
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('notificaciones.php')): ?>
    <a href="notificaciones.php" class="menu-item <?php echo ($current_page == 'notificaciones.php') ? 'active' : ''; ?>">
        <span class="icon">🔔</span> Notificaciones
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('banners.php')): ?>
    <a href="banners.php" class="menu-item <?php echo ($current_page == 'banners.php') ? 'active' : ''; ?>">
        <span class="icon">📢</span> Banners App
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('mantenimiento.php')): ?>
    <a href="mantenimiento.php" class="menu-item <?php echo ($current_page == 'mantenimiento.php') ? 'active' : ''; ?>">
        <span class="icon">🛠️</span> Mantenimiento
    </a>
    <?php endif; ?>
    
    <?php if (yora_admin_puede('ajustes.php')): ?>
    <a href="ajustes.php" class="menu-item <?php echo ($current_page == 'ajustes.php') ? 'active' : ''; ?>">
        <span class="icon">⚙️</span> Ajustes Web
    </a>
    <?php endif; ?>
    <?php if (yora_admin_puede('usuarios.php')): ?>
    <a href="usuarios.php" class="menu-item <?php echo ($current_page == 'usuarios.php') ? 'active' : ''; ?>">
        <span class="icon">👥</span> Equipo HQ
    </a>
    <?php endif; ?>
    
    <div class="menu-bottom">
        <a href="logout.php" class="menu-item logout-btn">
            <span class="icon">🚪</span> Cerrar Sesión
        </a>
    </div>
</div>
<script>
    function hqMenu(abrir) {
        const bar = document.getElementById('hq-sidebar');
        const fondo = document.getElementById('hq-fondo');
        if (!bar) return;
        bar.classList.toggle('abierto', !!abrir);
        if (fondo) fondo.classList.toggle('on', !!abrir);
    }
    document.querySelectorAll('#hq-sidebar a.menu-item').forEach(function (a) {
        a.addEventListener('click', function () { hqMenu(false); });
    });
</script>
<link rel="stylesheet" href="hq_app.css?v=5">
<style>
html.hq-movil, html.hq-movil body { display:block !important; height:auto !important; overflow-x:hidden !important; overflow-y:auto !important; }
html.hq-movil .hq-topbar { display:flex !important; }
html.hq-movil .sidebar { display:none !important; position:fixed !important; left:0; top:0; width:min(86vw,320px) !important; height:100dvh !important; z-index:220; }
html.hq-movil .sidebar.abierto { display:flex !important; flex-direction:column !important; }
html.hq-movil .hq-fondo.on { display:block !important; }
html.hq-movil .content { width:100% !important; max-width:100% !important; padding:14px 12px 36px !important; display:block !important; }
html.hq-movil .grid-cards, html.hq-movil .kpi-grid, html.hq-movil .dashboard-layout, html.hq-movil .form-grid, html.hq-movil .form-grid-3 { display:block !important; grid-template-columns:1fr !important; }
</style>
