<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $err = 'Solicitud rechazada.';
    } else {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $correo = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
        $cedula = strtoupper(preg_replace('/[^VEJ0-9]/i', '', (string) ($_POST['cedula'] ?? '')));
        $direccion = trim((string) ($_POST['direccion'] ?? ''));
        if (mb_strlen($nombre) < 3) {
            $err = 'Nombre demasiado corto.';
        } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $err = 'Correo inválido.';
        } else {
            try {
                yora_exec($conexion, 'UPDATE usuarios_app SET nombre=?, correo=?, cedula=?, direccion=? WHERE id=?', 'ssssi', $nombre, $correo, $cedula, $direccion, $uid);
            } catch (Throwable $e) {
                yora_exec($conexion, 'UPDATE usuarios_app SET nombre=? WHERE id=?', 'si', $nombre, $uid);
            }
            $user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
            $msg = 'Perfil actualizado.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Perfil | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=4">
</head>
<body>
<div class="ax-top"><a href="ajustes.php"><i class="ph ph-arrow-left"></i></a><h1>Detalles del perfil</h1></div>
<div class="ax-wrap">
    <?php if ($msg): ?><div class="ok"><?php echo yora_h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?php echo yora_h($err); ?></div><?php endif; ?>
    <form method="POST" class="ax-card">
        <?php echo yora_csrf_field(); ?>
        <label>Nombre</label>
        <input name="nombre" required value="<?php echo yora_h($user['nombre'] ?? ''); ?>">
        <label>Cédula</label>
        <input name="cedula" placeholder="V25951632" value="<?php echo yora_h($user['cedula'] ?? ''); ?>">
        <label>Teléfono</label>
        <input value="<?php echo yora_h($user['telefono'] ?? ''); ?>" disabled>
        <label>Correo</label>
        <input type="email" name="correo" value="<?php echo yora_h($user['correo'] ?? ''); ?>">
        <label>Dirección</label>
        <input name="direccion" value="<?php echo yora_h($user['direccion'] ?? ''); ?>">
        <button class="btn" type="submit">Guardar</button>
    </form>
</div>
</body>
</html>
