<?php
require_once __DIR__ . '/yora_cargar.php';
yora_mant_exigir($conexion, 'clientes', isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null);
if (!empty($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_rate_limit('login-user:' . yora_client_ip(), 8, 900)) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } else {
        $tel = preg_replace('/\D+/', '', (string) ($_POST['telefono'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $row = $tel !== '' ? yora_one($conexion, 'SELECT * FROM usuarios_app WHERE telefono = ?', 's', $tel) : null;
        if ($row && yora_verify_and_upgrade_password($conexion, 'usuarios_app', (int) $row['id'], $password, (string) $row['password'])) {
            if (($row['estatus'] ?? 'activo') !== 'activo') {
                $error = 'Tu cuenta está suspendida. Escribe a soporte.';
            } else {
                if (yora_mant_bloquea($conexion, 'clientes', (int) $row['id'])) {
                    $error = yora_mant_cfg($conexion)['mensaje'];
                } else {
                    yora_session_regenerate();
                    $_SESSION['usuario_id'] = (int) $row['id'];
                    header('Location: dashboard.php');
                    exit;
                }
            }
        } else {
            $error = 'Teléfono o contraseña incorrectos.';
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#e4441b">
    <title>Yora | Entrar</title>
    <link rel="manifest" href="/manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app.css?v=3">
</head>
<body class="auth">
    <div class="card">
        <div class="brand">Yora<span>.</span></div>
        <h1>Pide un mandadito</h1>
        <p class="sub">Recogen donde tú digas y te lo llevan. Barquisimeto y Lara.</p>
        <?php if ($error !== ''): ?><div class="err"><?php echo yora_h($error); ?></div><?php endif; ?>
        <form method="POST">
            <?php echo yora_csrf_field(); ?>
            <label>Teléfono</label>
            <input type="tel" name="telefono" required placeholder="04141234567" inputmode="tel">
            <label>Contraseña</label>
            <input type="password" name="password" required placeholder="••••••••">
            <button class="btn" type="submit">Entrar</button>
        </form>
        <a class="link" href="registro.php">¿No tienes cuenta? <b>Regístrate</b></a>
    </div>
</body>
</html>
