<?php
require_once __DIR__ . '/../../config.php';
if (isset($_SESSION['admin_logged'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $error = 'Solicitud rechazada. Recarga la página.';
    } elseif (!yora_rate_limit('login-admin:' . yora_client_ip(), 6, 900)) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $row = yora_one($conexion, 'SELECT * FROM usuarios_admin WHERE usuario = ?', 's', $usuario);
        if ($row && password_verify($password, (string) $row['password'])) {
            yora_session_regenerate();
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_id'] = (int) $row['id'];
            $_SESSION['admin_nombre'] = $row['nombre'];
            $rol = strtolower(trim((string) ($row['rol'] ?? 'super')));
            $_SESSION['admin_rol'] = in_array($rol, ['super', 'comercios', 'drivers'], true) ? $rol : 'super';
            header('Location: ' . yora_admin_inicio());
            exit;
        }
        $error = 'Usuario o contraseña incorrectos';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Iniciar Sesión</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { 
            background: #f8fafc; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            padding: 20px;
        }
        .login-card { 
            background: white; 
            padding: 40px; 
            border-radius: 20px; 
            box-shadow: 0 10px 30px rgba(228,68,27,0.1); 
            width: 100%; 
            max-width: 400px; 
            text-align: center; 
            border-top: 6px solid #e4441b;
        }
        .logo { font-size: 2.2rem; font-weight: 800; color: #1e293b; margin-bottom: 5px; letter-spacing: -1px; }
        .logo span { color: #e4441b; }
        .subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 25px; font-weight: 500; }
        
        .input-group { margin-bottom: 20px; text-align: left; }
        .input-group label { display: block; font-weight: 600; color: #475569; font-size: 0.85rem; margin-bottom: 8px; }
        .input-group input { 
            width: 100%; 
            padding: 14px 15px; 
            border: 2px solid #e2e8f0; 
            border-radius: 12px; 
            font-size: 1rem; 
            outline: none; 
            transition: 0.3s; 
            color: #1e293b;
            background: #f8fafc;
        }
        .input-group input:focus { border-color: #e4441b; box-shadow: 0 0 0 3px rgba(228,68,27,0.1); background: white; }
        
        .btn-login { 
            background: linear-gradient(135deg, #e4441b 0%, #c23310 100%);
            color: white; 
            width: 100%; 
            padding: 15px; 
            border: none; 
            border-radius: 12px; 
            font-size: 1.05rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.2s; 
            box-shadow: 0 4px 15px rgba(228,68,27,0.3); 
            margin-top: 5px;
        }
        .btn-login:active { transform: scale(0.97); }

        .error-msg {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 20px;
            border: 1px solid #fca5a5;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo">Yora<span>Admin</span></div>
        <p class="subtitle">Centro de Comando Central</p>

        <?php if($error != ''): ?>
            <div class="error-msg">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php echo yora_csrf_field(); ?>
            <div class="input-group">
                <label>Usuario Administrativo</label>
                <input type="text" name="usuario" placeholder="Ej: admin" required autocomplete="off">
            </div>
            <div class="input-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="••••••••" required autocomplete="off">
            </div>
            <button type="submit" class="btn-login">Ingresar al Sistema</button>
        </form>
    </div>

</body>
</html>