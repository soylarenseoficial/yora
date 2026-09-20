<?php
require_once __DIR__ . '/yora_cargar.php';
if (!empty($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

function yora_post(string $k): string
{
    return trim((string) ($_POST[$k] ?? ''));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $error = 'Solicitud rechazada. Recarga la página.';
    } elseif (!yora_rate_limit('reg-user:' . yora_client_ip(), 5, 3600)) {
        $error = 'Demasiados registros desde esta red. Espera un rato.';
    } else {
        $nombre = yora_post('nombre');
        $apellido = yora_post('apellido');
        $nombre_completo = trim($nombre . ' ' . $apellido);
        $cedula = strtoupper(preg_replace('/[^VEJ0-9]/i', '', yora_post('cedula')));
        $tel = preg_replace('/\D+/', '', yora_post('telefono'));
        $correo = mb_strtolower(yora_post('correo'));
        $direccion = yora_post('direccion');
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        $acepto = !empty($_POST['acepto']);

        if (mb_strlen($nombre) < 2 || mb_strlen($apellido) < 2) {
            $error = 'Escribe nombre y apellido.';
        } elseif (mb_strlen($nombre_completo) > 80) {
            $error = 'El nombre es demasiado largo.';
        } elseif (!preg_match('/^[VEJ]\d{6,9}$/', $cedula)) {
            $error = 'Cédula inválida. Ejemplo: V25951632';
        } elseif (strlen($tel) < 10 || strlen($tel) > 15) {
            $error = 'Teléfono inválido. Usa el de Venezuela, 11 dígitos.';
        } elseif ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un correo válido.';
        } elseif (mb_strlen($direccion) < 8) {
            $error = 'Escribe tu dirección en Barquisimeto o Lara.';
        } elseif (strlen($password) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = 'La contraseña debe tener letras y números.';
        } elseif ($password !== $password2) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!$acepto) {
            $error = 'Debes aceptar los términos para crear la cuenta.';
        } elseif (yora_one($conexion, 'SELECT id FROM usuarios_app WHERE telefono = ?', 's', $tel)) {
            $error = 'Ese teléfono ya está registrado. Entra con tu clave.';
        } else {
            $correo_usado = false;
            try {
                $correo_usado = (bool) yora_one($conexion, 'SELECT id FROM usuarios_app WHERE correo = ?', 's', $correo);
            } catch (Throwable $ignored) {
            }
            if ($correo_usado) {
                $error = 'Ese correo ya está registrado.';
            }
        }
        if ($error === '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                yora_exec(
                    $conexion,
                    'INSERT INTO usuarios_app (nombre, telefono, password, correo, cedula, direccion, estatus) VALUES (?,?,?,?,?,?,?)',
                    'sssssss',
                    $nombre_completo,
                    $tel,
                    $hash,
                    $correo,
                    $cedula,
                    $direccion,
                    'activo'
                );
            } catch (Throwable $e) {
                yora_exec($conexion, 'INSERT INTO usuarios_app (nombre, telefono, password, estatus) VALUES (?,?,?,?)', 'ssss', $nombre_completo, $tel, $hash, 'activo');
            }
            yora_session_regenerate();
            $_SESSION['usuario_id'] = (int) $conexion->insert_id;
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#e4441b">
    <title>Crear cuenta | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app.css?v=3">
</head>
<body class="auth">
    <div class="card">
        <div class="brand">Yora<span>.</span></div>
        <h1>Crea tu cuenta</h1>
        <p class="sub">Así el motorizado sabe quién pide y a dónde llega.</p>
        <?php if ($error !== ''): ?><div class="err"><?php echo yora_h($error); ?></div><?php endif; ?>
        <form method="POST" autocomplete="on">
            <?php echo yora_csrf_field(); ?>
            <div class="grid2">
                <div>
                    <label>Nombre</label>
                    <input type="text" name="nombre" required placeholder="María" value="<?php echo yora_h($_POST['nombre'] ?? ''); ?>">
                </div>
                <div>
                    <label>Apellido</label>
                    <input type="text" name="apellido" required placeholder="Pérez" value="<?php echo yora_h($_POST['apellido'] ?? ''); ?>">
                </div>
            </div>
            <div class="grid2">
                <div>
                    <label>Cédula</label>
                    <input type="text" name="cedula" required placeholder="V25951632" value="<?php echo yora_h($_POST['cedula'] ?? ''); ?>">
                </div>
                <div>
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" required placeholder="04141234567" inputmode="tel" value="<?php echo yora_h($_POST['telefono'] ?? ''); ?>">
                </div>
            </div>
            <label>Correo</label>
            <input type="email" name="correo" required placeholder="tu@correo.com" value="<?php echo yora_h($_POST['correo'] ?? ''); ?>">
            <label>Dirección en Lara</label>
            <input type="text" name="direccion" required placeholder="Ej: carrera 19 con 25, Barquisimeto" value="<?php echo yora_h($_POST['direccion'] ?? ''); ?>">
            <p class="hint">Con esto el motorizado te ubica más rápido.</p>
            <div class="grid2">
                <div>
                    <label>Contraseña</label>
                    <input type="password" name="password" required placeholder="Letras y números" minlength="8">
                </div>
                <div>
                    <label>Repite la contraseña</label>
                    <input type="password" name="password2" required placeholder="Otra vez" minlength="8">
                </div>
            </div>
            <label class="check">
                <input type="checkbox" name="acepto" value="1" <?php echo !empty($_POST['acepto']) ? 'checked' : ''; ?>>
                <span>Acepto pedir envíos en Barquisimeto / Lara y pagar en efectivo al motorizado.</span>
            </label>
            <button class="btn" type="submit">Crear cuenta</button>
        </form>
        <a class="link" href="index.php">¿Ya tienes cuenta? <b>Entrar</b></a>
    </div>
</body>
</html>
