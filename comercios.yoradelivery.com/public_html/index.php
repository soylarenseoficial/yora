<?php
require_once __DIR__ . '/../config.php';

yora_mant_exigir($conexion, 'comercios', isset($_SESSION['comercio_id']) ? (int) $_SESSION['comercio_id'] : null);

if (isset($_SESSION['comercio_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_rate_limit('login-comercio:' . yora_client_ip(), 8, 900)) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } else {
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $row = yora_one($conexion, 'SELECT id, nombre, password, estatus FROM comercios WHERE telefono = ?', 's', $telefono);
        if ($row && yora_verify_and_upgrade_password($conexion, 'comercios', (int) $row['id'], $password, (string) $row['password'])) {
            $est = strtolower(trim((string) ($row['estatus'] ?? 'activo')));
            if ($est === 'pendiente') {
                $error = 'Tu solicitud sigue en revisión. Te avisamos por correo y WhatsApp cuando te aprueben.';
            } elseif ($est === 'rechazado') {
                $error = 'Esta solicitud no fue aprobada. Escribe a soporte si quieres volver a postularte.';
            } else {
                if (yora_mant_bloquea($conexion, 'comercios', (int) $row['id'])) {
                    $error = yora_mant_cfg($conexion)['mensaje'];
                } else {
                yora_session_regenerate();
                $_SESSION['comercio_id'] = (int) $row['id'];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login | Yora Comercios</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        :root {
            --yora-orange: #e4441b;
            --yora-orange-hover: #c23310;
            --bg-main: #f8fafc;
            --card-bg: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --input-bg: #f1f5f9;
            --input-border: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; -webkit-tap-highlight-color: transparent;}
        
        body {
            background-color: var(--bg-main);
            background-image: radial-gradient(circle at top right, rgba(228, 68, 27, 0.05), transparent 400px),
                              radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.05), transparent 400px);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-dark);
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.6);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        /* Ajuste para el logo real de Yora */
        .logo-container img {
            max-width: 220px;
            height: auto;
            margin-bottom: 15px;
        }

        .logo-container h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.2;
            display: none; /* Ocultamos el texto H1 porque tu logo ya lo dice */
        }

        .logo-container p {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-top: 5px;
            font-weight: 500;
        }

        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper i.left-icon { position: absolute; left: 15px; font-size: 1.3rem; color: #94a3b8; transition: 0.3s; }
        .input-wrapper input { width: 100%; padding: 14px 15px 14px 48px; background-color: var(--input-bg); border: 1px solid var(--input-border); border-radius: 12px; font-size: 0.95rem; color: var(--text-dark); outline: none; transition: all 0.3s ease; }
        .input-wrapper input:focus { border-color: var(--yora-orange); background-color: #ffffff; box-shadow: 0 0 0 4px rgba(228, 68, 27, 0.1); }
        .input-wrapper input:focus + i.left-icon { color: var(--yora-orange); }

        .btn-toggle-pass { position: absolute; right: 15px; background: none; border: none; font-size: 1.2rem; color: #94a3b8; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; }
        .btn-toggle-pass:hover { color: var(--text-dark); }

        .btn-login { width: 100%; background: linear-gradient(135deg, var(--yora-orange) 0%, var(--yora-orange-hover) 100%); color: white; border: none; padding: 15px; border-radius: 12px; font-size: 1.05rem; font-weight: 700; cursor: pointer; box-shadow: 0 8px 20px rgba(228, 68, 27, 0.25); transition: all 0.3s ease; margin-top: 10px; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(228, 68, 27, 0.35); }
        .btn-login:active { transform: translateY(0); }

        .alert { background-color: #fee2e2; color: #dc2626; padding: 12px 15px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; border: 1px solid #fca5a5; animation: shake 0.4s ease-in-out; }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
        }

        .footer-links { text-align: center; margin-top: 25px; font-size: 0.85rem; color: var(--text-muted); }
        .footer-links a { color: var(--yora-orange); text-decoration: none; font-weight: 600; margin-left: 5px; }

        @media (max-width: 480px) {
            body { padding: 15px; }
            .login-card { padding: 35px 25px; }
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="logo-container">
                <img src="https://yoradelivery.com/uploads/logocomercionegro.png" alt="Yora Comercios">
                <h1>Yora Comercios</h1>
                <p>Ingresa para gestionar tus envíos</p>
            </div>

            <?php if(!empty($error)): ?>
                <div class="alert">
                    <i class="ph ph-warning-circle" style="font-size: 1.2rem;"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php">
                <?php echo yora_csrf_field(); ?>
                <div class="form-group">
                    <label>Teléfono de Acceso</label>
                    <div class="input-wrapper">
                        <input type="tel" name="telefono" placeholder="Ej: 04141234567" required autocomplete="tel">
                        <i class="ph ph-phone left-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Contraseña</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Ingresa tu clave" required>
                        <i class="ph ph-lock-key left-icon"></i>
                        <button type="button" class="btn-toggle-pass" onclick="togglePassword()">
                            <i class="ph ph-eye" id="eye-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    Ingresar al Panel <i class="ph ph-arrow-right"></i>
                </button>
            </form>

            <div class="footer-links">
                ¿No tienes cuenta comercial? <a href="https://wa.me/584225097031" target="_blank">Contáctanos</a>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.classList.remove('ph-eye');
                eyeIcon.classList.add('ph-eye-slash');
            } else {
                passInput.type = 'password';
                eyeIcon.classList.remove('ph-eye-slash');
                eyeIcon.classList.add('ph-eye');
            }
        }
    </script>
</body>
</html>