<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $err = 'Solicitud rechazada.';
    } else {
        $nueva = (string) ($_POST['password'] ?? '');
        $otra = (string) ($_POST['password2'] ?? '');
        if (strlen($nueva) < 8) {
            $err = 'Mínimo 8 caracteres.';
        } elseif ($nueva !== $otra) {
            $err = 'No coinciden.';
        } else {
            yora_exec($conexion, 'UPDATE usuarios_app SET password=? WHERE id=?', 'si', password_hash($nueva, PASSWORD_DEFAULT), $uid);
            $msg = 'Contraseña actualizada.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Seguridad | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=4">
</head>
<body>
<div class="ax-top"><a href="ajustes.php"><i class="ph ph-arrow-left"></i></a><h1>Seguridad</h1></div>
<div class="ax-wrap">
    <?php if ($msg): ?><div class="ok"><?php echo yora_h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?php echo yora_h($err); ?></div><?php endif; ?>
    <form method="POST" class="ax-card">
        <?php echo yora_csrf_field(); ?>
        <label>Nueva contraseña</label>
        <input type="password" name="password" required minlength="8">
        <label>Repítela</label>
        <input type="password" name="password2" required minlength="8">
        <button class="btn" type="submit">Cambiar contraseña</button>
    </form>
</div>
</body>
</html>
