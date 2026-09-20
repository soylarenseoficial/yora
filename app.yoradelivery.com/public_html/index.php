<?php
require_once __DIR__ . '/../config.php';

yora_mant_exigir($conexion, 'drivers', isset($_SESSION['conductor_id']) ? (int) $_SESSION['conductor_id'] : null);

if (isset($_SESSION['conductor_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (($_GET['sesion'] ?? '') === 'duplicada') {
    $error = 'Tu cuenta se abrió en otro dispositivo. Vuelve a iniciar sesión aquí para seguir trabajando.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_rate_limit('login-driver:' . yora_client_ip(), 8, 900)) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } else {
        $cedula = trim((string) ($_POST['cedula'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $row = yora_one($conexion, 'SELECT * FROM conductores WHERE cedula = ?', 's', $cedula);
        $db_pass = '';
        if ($row) {
            $db_pass = (string) ($row['password'] ?? $row['contrasena'] ?? $row['clave'] ?? '');
        }
        if ($row && yora_verify_and_upgrade_password($conexion, 'conductores', (int) $row['id'], $password, $db_pass)) {
            // El estatus manda: solo la flota aprobada puede entrar a la app.
            $estatus_driver = (string) ($row['estatus'] ?? '');
            if ($estatus_driver === 'pendiente') {
                $error = 'Tu postulación sigue en revisión. Te avisaremos por correo y WhatsApp en cuanto quede aprobada.';
            } elseif ($estatus_driver === 'rechazado') {
                $error = 'Tu solicitud fue rechazada. Escríbenos por WhatsApp si quieres volver a postularte.';
            } elseif ($estatus_driver === 'inactivo') {
                $error = 'Tu cuenta está suspendida. Contacta a soporte de Yora para reactivarla.';
            } else {
                // Deja esta como la unica sesion abierta de este conductor.
                if (yora_mant_bloquea($conexion, 'drivers', (int) $row['id'])) {
                    $error = yora_mant_cfg($conexion)['mensaje'];
                } else {
                yora_conductor_abrir_sesion($conexion, (int) $row['id']);
                header('Location: dashboard.php');
                exit;
                }
            }
        } else {
            $error = 'Cédula o contraseña incorrectos.';
        }
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#f4f6f8">
    <title>YoraDriver | Iniciar Sesión</title>
    <link rel="manifest" href="/manifest.json?v=9">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; height: 100dvh; overflow: hidden; overscroll-behavior: none; }
        body { background: linear-gradient(135deg, #f4f6f8 0%, #e2e8f0 100%); display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; margin: 0; }
        .login-card { background: white; width: 100%; max-width: 400px; padding: 36px 28px; border-radius: 24px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); text-align: center; flex-shrink: 0; }
        .login-logo { width: 100%; max-width: 220px; margin: 0 auto 10px auto; display: block; }
        .input-group { text-align: left; margin-bottom: 20px; position: relative; }
        .input-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #4b5563; margin-bottom: 8px; }
        .input-group i { position: absolute; bottom: 14px; left: 15px; font-size: 1.2rem; color: #9ca3af; }
        .input-group input { width: 100%; padding: 14px 14px 14px 45px; border-radius: 12px; border: 2px solid #e5e7eb; background: #f9fafb; font-size: 0.95rem; outline: none; }
        .input-group input:focus { border-color: #e4441b; background: white; box-shadow: 0 0 0 4px rgba(228, 68, 27, 0.1); }
        .btn-login { background: linear-gradient(135deg, #e4441b 0%, #c23310 100%); color: white; width: 100%; border: none; padding: 16px; border-radius: 14px; font-weight: 700; font-size: 1.05rem; cursor: pointer; box-shadow: 0 6px 20px rgba(228, 68, 27, 0.3); display: flex; align-items: center; justify-content: center; gap: 10px; }
        .error-msg { background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; margin-bottom: 20px; border: 1px solid #fca5a5; display: flex; justify-content: center; gap: 8px; }
        .support-link { display: block; margin-top: 25px; font-size: 0.85rem; color: #6b7280; text-decoration: none; font-weight: 500; }
        .support-link span { color: #e4441b; font-weight: 700; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="https://yoradelivery.com/uploads/logodriver.png" alt="YoraDriver" class="login-logo">
        <h2 style="font-size: 1.3rem; margin-bottom:5px;">¡Hola de nuevo!</h2>
        <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 25px;">Ingresa para empezar a recibir viajes.</p>

        <?php if($error != ''): ?><div class="error-msg"><i class="ph ph-warning-circle"></i><?php echo yora_h($error); ?></div><?php endif; ?>

        <form method="POST" action="">
            <?php echo yora_csrf_field(); ?>
            <div class="input-group">
                <label>Cédula de Identidad</label>
                <i class="ph ph-identification-card"></i>
                <input type="number" name="cedula" placeholder="Ej: 25123456" required>
            </div>
            <div class="input-group">
                <label>Contraseña</label>
                <i class="ph ph-lock-key"></i>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-login">Entrar a la ruta <i class="ph ph-arrow-right"></i></button>
        </form>
        
        <a href="https://wa.me/<?php echo YORA_WHATSAPP_SOPORTE; ?>" target="_blank" class="support-link">¿No puedes iniciar sesión? <span>Contacta a un asesor</span></a>
    </div>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function (regs) {
                return Promise.all(regs.map(function (r) { return r.unregister(); }));
            }).then(function () {
                if (!window.caches) return;
                return caches.keys().then(function (keys) {
                    return Promise.all(keys.map(function (k) { return caches.delete(k); }));
                });
            }).then(function () {
                return navigator.serviceWorker.register('/sw.js?v=9');
            }).catch(function () {});
        }
    </script>
</body>
</html>