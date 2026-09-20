<?php
require_once 'conexion.php';
yora_require_admin_pagina('mantenimiento.php');
yora_timezone($conexion);

$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }
    if (isset($_POST['guardar_mant'])) {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $texto = trim((string) ($_POST['mensaje'] ?? ''));
        $web = isset($_POST['mant_web']) ? 1 : 0;
        $drivers = isset($_POST['mant_drivers']) ? 1 : 0;
        $comercios = isset($_POST['mant_comercios']) ? 1 : 0;
        $clientes = isset($_POST['mant_clientes']) ? 1 : 0;
        $wl_d = trim((string) ($_POST['wl_drivers'] ?? ''));
        $wl_c = trim((string) ($_POST['wl_comercios'] ?? ''));
        $wl_u = trim((string) ($_POST['wl_clientes'] ?? ''));

        yora_exec(
            $conexion,
            'UPDATE configuracion_web SET
                modo_mantenimiento = ?,
                titulo_mantenimiento = ?,
                mensaje_mantenimiento = ?,
                mant_drivers = ?,
                mant_comercios = ?,
                mant_clientes = ?,
                mant_whitelist_drivers = ?,
                mant_whitelist_comercios = ?,
                mant_whitelist_clientes = ?
             LIMIT 1',
            'issiiisss',
            $web, $titulo, $texto, $drivers, $comercios, $clientes, $wl_d, $wl_c, $wl_u
        );

        // Apaga flota en línea si cerramos drivers (excepto whitelist de pruebas).
        if ($drivers === 1) {
            $wl = array_filter(array_map('intval', preg_split('/[\s,;]+/', $wl_d) ?: []));
            if ($wl) {
                $ids = implode(',', $wl);
                @$conexion->query("UPDATE conductores SET en_linea = 0 WHERE en_linea = 1 AND id NOT IN ($ids)");
            } else {
                @$conexion->query('UPDATE conductores SET en_linea = 0 WHERE en_linea = 1');
            }
        }

        $mensaje = "<div class='alert success'>Mantenimiento guardado. Los canales activos quedan bloqueados de inmediato (excepto IDs en lista blanca).</div>";
    }
}

$cfg = yora_one($conexion, 'SELECT * FROM configuracion_web LIMIT 1') ?: [];
$cfg += [
    'modo_mantenimiento' => 0,
    'titulo_mantenimiento' => 'Estamos en mantenimiento',
    'mensaje_mantenimiento' => 'Yora está en mantenimiento para mejorar el servicio. Volvemos pronto.',
    'mant_drivers' => 0,
    'mant_comercios' => 0,
    'mant_clientes' => 0,
    'mant_whitelist_drivers' => '',
    'mant_whitelist_comercios' => '',
    'mant_whitelist_clientes' => '',
];
$algún = ((int) $cfg['modo_mantenimiento'] || (int) $cfg['mant_drivers'] || (int) $cfg['mant_comercios'] || (int) $cfg['mant_clientes']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Mantenimiento</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#ffffff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:var(--bg-body); color:var(--text-main); display:flex; height:100vh; overflow:hidden; }
        .content { flex:1; padding:40px 50px; overflow-y:auto; }
        .card { background:var(--bg-card); padding:28px; border-radius:16px; border:1px solid var(--border-color); max-width:760px; margin-bottom:18px; }
        .alert { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:0.9rem; }
        .alert.success { background:#dcfce7; color:#166534; }
        .banner-on { background:#7f1d1d; color:#fff; border-radius:14px; padding:14px 16px; margin-bottom:18px; font-weight:700; max-width:760px; }
        .banner-off { background:#ecfdf5; color:#065f46; border-radius:14px; padding:14px 16px; margin-bottom:18px; font-weight:700; max-width:760px; }
        label { display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px; }
        input[type=text], textarea { width:100%; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; margin-bottom:14px; }
        .toggle-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 0; border-bottom:1px solid #f1f5f9; }
        .toggle-row:last-child { border-bottom:0; }
        .toggle-row strong { display:block; font-size:0.95rem; }
        .toggle-row span { display:block; font-size:0.78rem; color:#64748b; font-weight:500; margin-top:3px; }
        .switch { position:relative; width:52px; height:30px; flex-shrink:0; }
        .switch input { opacity:0; width:0; height:0; }
        .slider { position:absolute; inset:0; background:#cbd5e1; border-radius:999px; cursor:pointer; transition:.2s; }
        .slider:before { content:""; position:absolute; width:24px; height:24px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
        .switch input:checked + .slider { background:#e4441b; }
        .switch input:checked + .slider:before { transform:translateX(22px); }
        .btn-save { background:#e4441b; color:#fff; border:none; padding:14px 22px; border-radius:12px; font-weight:800; cursor:pointer; width:100%; margin-top:8px; }
        .hint { font-size:0.82rem; color:#64748b; line-height:1.5; margin-bottom:14px; }
        code { background:#f1f5f9; padding:1px 6px; border-radius:6px; font-size:0.78rem; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <h1 style="font-size:2rem; margin-bottom:6px;">Modo mantenimiento</h1>
    <p style="color:#64748b; margin-bottom:20px; max-width:760px;">Cierra drivers, comercios, clientes o la web pública mientras corriges el sistema. El panel HQ siempre queda abierto.</p>

    <?php if ($algún): ?>
        <div class="banner-on">⚠ Mantenimiento ACTIVO en al menos un canal. Los usuarios normales no pueden operar.</div>
    <?php else: ?>
        <div class="banner-off">Sistema operativo (ningún canal en mantenimiento).</div>
    <?php endif; ?>

    <?php echo $mensaje; ?>

    <form method="POST" class="card">
        <?php echo yora_csrf_field(); ?>
        <input type="hidden" name="guardar_mant" value="1">

        <div class="toggle-row">
            <div><strong>App Drivers</strong><span>YoraDriver / app.yoradelivery.com</span></div>
            <label class="switch"><input type="checkbox" name="mant_drivers" value="1" <?php echo (int)$cfg['mant_drivers'] ? 'checked' : ''; ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div><strong>Comercios B2B</strong><span>comercios.yoradelivery.com</span></div>
            <label class="switch"><input type="checkbox" name="mant_comercios" value="1" <?php echo (int)$cfg['mant_comercios'] ? 'checked' : ''; ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div><strong>App Clientes</strong><span>client.yoradelivery.com</span></div>
            <label class="switch"><input type="checkbox" name="mant_clientes" value="1" <?php echo (int)$cfg['mant_clientes'] ? 'checked' : ''; ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div><strong>Web pública</strong><span>yoradelivery.com (landing)</span></div>
            <label class="switch"><input type="checkbox" name="mant_web" value="1" <?php echo (int)$cfg['modo_mantenimiento'] ? 'checked' : ''; ?>><span class="slider"></span></label>
        </div>

        <hr style="border:0;border-top:1px solid #eef2f7;margin:18px 0;">

        <label>Título que ven los usuarios</label>
        <input type="text" name="titulo" maxlength="140" value="<?php echo yora_h($cfg['titulo_mantenimiento']); ?>" required>

        <label>Mensaje</label>
        <textarea name="mensaje" rows="3" required><?php echo yora_h($cfg['mensaje_mantenimiento']); ?></textarea>

        <p class="hint">Lista blanca: IDs que <b>sí pueden entrar</b> aunque el canal esté cerrado (cuentas del equipo para probar). Sepáralos por coma. Ejemplo drivers: <code>12, 36</code></p>

        <label>Whitelist IDs drivers</label>
        <input type="text" name="wl_drivers" placeholder="Ej: 12, 36" value="<?php echo yora_h($cfg['mant_whitelist_drivers']); ?>">

        <label>Whitelist IDs comercios</label>
        <input type="text" name="wl_comercios" placeholder="Ej: 5, 18" value="<?php echo yora_h($cfg['mant_whitelist_comercios']); ?>">

        <label>Whitelist IDs clientes</label>
        <input type="text" name="wl_clientes" placeholder="Ej: 3" value="<?php echo yora_h($cfg['mant_whitelist_clientes']); ?>">

        <button class="btn-save" type="submit">Guardar mantenimiento</button>
    </form>
</div>
</body>
</html>
