<?php
require_once 'conexion.php';
yora_require_admin_pagina('notificaciones.php');
$conexion->query("SET time_zone = '-04:00'");

$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }
    if (isset($_POST['guardar_onesignal'])) {
        $app = trim((string) ($_POST['onesignal_app_id'] ?? ''));
        $rest = trim((string) ($_POST['onesignal_rest_key'] ?? ''));
        yora_exec($conexion, 'UPDATE configuracion_web SET onesignal_app_id = ?, onesignal_rest_key = ? LIMIT 1', 'ss', $app, $rest);
        $mensaje = "<div class='alert success'>OneSignal guardado. El App ID tiene que ser d59093db-2bbd-4da6-8e22-6c5d50484be3 (el mismo de la APK).</div>";
    } elseif (isset($_POST['enviar_push'])) {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $cuerpo = trim((string) ($_POST['cuerpo'] ?? ''));
        $destino = (string) ($_POST['destino'] ?? 'online');
        if ($titulo === '' || $cuerpo === '' || mb_strlen($titulo) > 80 || mb_strlen($cuerpo) > 180) {
            $mensaje = "<div class='alert error'>Escribe un título (máx 80) y un mensaje (máx 180).</div>";
        } else {
            $os = yora_onesignal_config($conexion);
            if ($os['app_id'] === '' || $os['rest'] === '') {
                $mensaje = "<div class='alert error'>Falta el App ID y el REST API Key de OneSignal. Pégalos arriba (están en OneSignal → Keys & IDs, no en Median).</div>";
            } else {
                $enviados = yora_push_conductores($conexion, $titulo, $cuerpo, '/dashboard.php', $destino !== 'todos');
                if ($enviados > 0) {
                    $mensaje = "<div class='alert success'>Aviso enviado. OneSignal reportó {$enviados} destinatario(s). Si el teléfono no suena, reinstala el APK nuevo y abre YoraDriver con internet 10 segundos.</div>";
                } else {
                    $mensaje = "<div class='alert error'>OneSignal no encontró teléfonos (0 destinatarios). Abre YoraDriver, entra al dashboard y espera 10 s. En OneSignal → Settings → Google Android (FCM) tiene que estar subido el JSON de la cuenta de servicio de Firebase.</div>";
                }
            }
        }
    }
}

$os = yora_onesignal_config($conexion);
$suscritos = (int) (yora_one($conexion, "SELECT COUNT(*) AS n FROM push_subscriptions WHERE endpoint LIKE 'onesignal:%'")['n'] ?? 0);
$online = (int) (yora_one($conexion, 'SELECT COUNT(*) AS n FROM conductores WHERE en_linea = 1')['n'] ?? 0);
$os_ok = $os['app_id'] !== '' && $os['rest'] !== '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Notificaciones</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#ffffff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:var(--bg-body); color:var(--text-main); display:flex; height:100vh; overflow:hidden; }
        .sidebar { width:280px; min-width:280px; background:var(--bg-card); padding:25px 20px; border-right:1px solid var(--border-color); display:flex; flex-direction:column; }
        .sidebar-brand { display:flex; align-items:center; justify-content:space-between; margin-bottom:35px; padding-left:10px; }
        .sidebar h2 { font-size:1.5rem; margin:0; font-weight:700; }
        .sidebar h2 span { color:var(--yora-orange); }
        .badge-app { background:rgba(206,78,45,0.1); color:var(--yora-orange); font-size:0.7rem; padding:4px 8px; border-radius:6px; font-weight:600; }
        .menu-category { font-size:0.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:600; letter-spacing:1px; margin:0 0 10px 10px; }
        .menu-item { padding:12px 15px; margin-bottom:6px; border-radius:10px; font-weight:500; font-size:0.95rem; color:#4b5563; text-decoration:none; display:flex; align-items:center; gap:12px; }
        .menu-item:hover { background:#f3f4f6; }
        .menu-item.active { background:rgba(206,78,45,0.1); color:var(--yora-orange); font-weight:600; }
        .menu-bottom { margin-top:auto; border-top:1px solid var(--border-color); padding-top:15px; }
        .content { flex:1; padding:40px 50px; overflow-y:auto; }
        .card { background:var(--bg-card); padding:28px; border-radius:16px; border:1px solid var(--border-color); max-width:640px; margin-bottom:20px; }
        label { display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px; }
        input, textarea, select { width:100%; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; margin-bottom:16px; }
        .btn-save { background:var(--yora-orange); color:#fff; border:none; padding:14px 22px; border-radius:12px; font-weight:700; cursor:pointer; width:100%; }
        .btn-ghost { background:#f1f5f9; color:#334155; }
        .alert { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:0.9rem; }
        .alert.success { background:#dcfce7; color:#166534; }
        .alert.error { background:#fee2e2; color:#991b1b; }
        .kpis { display:flex; gap:12px; margin:0 0 24px; flex-wrap:wrap; }
        .kpi { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:16px 20px; min-width:160px; }
        .kpi b { display:block; font-size:1.6rem; }
        .kpi span { font-size:0.75rem; color:#64748b; font-weight:600; }
        .hint { font-size:0.82rem; color:#64748b; line-height:1.5; margin-bottom:16px; }
        code { background:#f1f5f9; padding:1px 6px; border-radius:6px; font-size:0.78rem; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <h1 style="font-size:2rem; margin-bottom:6px;">Notificaciones Push</h1>
    <p style="color:#64748b; margin-bottom:24px;">La APK de Android Studio usa OneSignal + Firebase. El navegador usa Web Push. Pega las claves una vez y sube el JSON de FCM en OneSignal.</p>
    <div class="kpis">
        <div class="kpi"><b><?php echo $suscritos; ?></b><span>APK ligadas a OneSignal</span></div>
        <div class="kpi"><b><?php echo $online; ?></b><span>Drivers en línea</span></div>
        <div class="kpi"><b><?php echo $os_ok ? 'Sí' : 'No'; ?></b><span>OneSignal listo</span></div>
    </div>
    <?php echo $mensaje; ?>

    <div class="card">
        <h3 style="margin:0 0 8px;">1. Claves de OneSignal</h3>
        <p class="hint">En <b>OneSignal → Settings → Keys &amp; IDs</b> copia el <b>OneSignal App ID</b> (tiene que ser el mismo de la APK: <code>d59093db-2bbd-4da6-8e22-6c5d50484be3</code>) y el <b>REST API Key</b>. En <b>Google Android (FCM)</b> sube el JSON de cuenta de servicio de Firebase o el push no llega al teléfono.</p>
        <form method="POST">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="guardar_onesignal" value="1">
            <label>OneSignal App ID</label>
            <input type="text" name="onesignal_app_id" value="<?php echo yora_h($os['app_id']); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
            <label>REST API Key</label>
            <input type="password" name="onesignal_rest_key" value="<?php echo yora_h($os['rest']); ?>" placeholder="Pégalo desde OneSignal → Keys & IDs" required>
            <button class="btn-save btn-ghost" type="submit">Guardar claves</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin:0 0 8px;">2. Enviar aviso</h3>
        <p class="hint">Con la cuenta abierta en la APK, el driver queda ligado como <code>yora-driver-{id}</code>. Un pedido nuevo también dispara este mismo canal.</p>
        <form method="POST">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="enviar_push" value="1">
            <label>Título</label>
            <input type="text" name="titulo" maxlength="80" required placeholder="Ej: Zona norte activa">
            <label>Mensaje</label>
            <textarea name="cuerpo" rows="3" maxlength="180" required placeholder="Hay pedidos en el radar. Entra a la app."></textarea>
            <label>Enviar a</label>
            <select name="destino">
                <option value="online">Solo drivers en línea</option>
                <option value="todos">Todos los drivers</option>
            </select>
            <button class="btn-save" type="submit">Enviar y hacer sonar</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin:0 0 8px;">3. App Android propia (gratis, sin Median Plus)</h3>
        <p class="hint">El plugin <b>Ubicación de fondo</b> de Median es de pago. No lo uses. El GPS en segundo plano va en la app de <b>Android Studio</b> que está en la carpeta <code>YoraDriverAndroid</code> del proyecto:</p>
        <ul class="hint" style="padding-left:18px; margin:0;">
            <li>Abre esa carpeta en Android Studio → <b>Build APK</b>.</li>
            <li>Sube el APK a <code>/android/yora-driver.apk</code> para que los drivers lo descarguen.</li>
            <li>GPS nativo + notificación “YoraDriver en línea” + OneSignal gratis (el de onesignal.com, no el plugin Plus de Median).</li>
            <li>En el teléfono: ubicación <b>todo el tiempo</b> y no optimizar batería.</li>
        </ul>
    </div>
</div>
</body>
</html>
