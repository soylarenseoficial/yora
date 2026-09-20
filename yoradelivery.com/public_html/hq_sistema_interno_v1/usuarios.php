<?php
require_once 'conexion.php';
yora_require_admin_pagina('usuarios.php');

$mensaje = '';
$roles = [
    'super' => 'Super Admin (todo)',
    'comercios' => 'Comercios (recargas, soporte, comercios, comandas, directorio)',
    'drivers' => 'Drivers (conductores, comandas, pagos)',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }
    if (isset($_POST['crear_usuario'])) {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $rol = strtolower(trim((string) ($_POST['rol'] ?? 'comercios')));
        $pass = (string) ($_POST['password'] ?? '');
        if ($usuario === '' || $nombre === '' || strlen($pass) < 8) {
            $mensaje = "<div class='alert error'>Usuario, nombre y contraseña de 8+ caracteres.</div>";
        } elseif (!isset($roles[$rol])) {
            $mensaje = "<div class='alert error'>Rol inválido.</div>";
        } elseif (yora_one($conexion, 'SELECT id FROM usuarios_admin WHERE usuario = ?', 's', $usuario)) {
            $mensaje = "<div class='alert error'>Ese usuario ya existe.</div>";
        } else {
            yora_exec($conexion, 'INSERT INTO usuarios_admin (usuario, password, nombre, rol) VALUES (?,?,?,?)', 'ssss', $usuario, password_hash($pass, PASSWORD_DEFAULT), $nombre, $rol);
            $mensaje = "<div class='alert success'>Cuenta creada. Entrarán por el mismo login del HQ.</div>";
        }
    } elseif (isset($_POST['cambiar_rol'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $rol = strtolower(trim((string) ($_POST['rol'] ?? '')));
        $yo = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id === $yo) {
            $mensaje = "<div class='alert error'>No puedes cambiar tu propio rol.</div>";
        } elseif (!isset($roles[$rol]) || $id < 1) {
            $mensaje = "<div class='alert error'>Datos inválidos.</div>";
        } else {
            yora_exec($conexion, 'UPDATE usuarios_admin SET rol = ? WHERE id = ?', 'si', $rol, $id);
            $mensaje = "<div class='alert success'>Rol actualizado.</div>";
        }
    } elseif (isset($_POST['reset_pass'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pass = (string) ($_POST['password'] ?? '');
        if ($id < 1 || strlen($pass) < 8) {
            $mensaje = "<div class='alert error'>Contraseña de 8+ caracteres.</div>";
        } else {
            yora_exec($conexion, 'UPDATE usuarios_admin SET password = ? WHERE id = ?', 'si', password_hash($pass, PASSWORD_DEFAULT), $id);
            $mensaje = "<div class='alert success'>Contraseña restablecida.</div>";
        }
    }
}

$equipo = yora_all($conexion, 'SELECT id, usuario, nombre, rol FROM usuarios_admin ORDER BY id ASC');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Equipo HQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#fff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
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
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; margin-bottom:20px; max-width:820px; }
        label { display:block; font-size:0.8rem; font-weight:700; margin-bottom:6px; }
        input, select { width:100%; padding:11px 12px; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:12px; font-family:inherit; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .btn { background:var(--yora-orange); color:#fff; border:none; padding:12px 18px; border-radius:10px; font-weight:700; cursor:pointer; }
        .btn-ghost { background:#f1f5f9; color:#334155; }
        table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        th { text-align:left; color:#64748b; font-size:0.72rem; text-transform:uppercase; padding:8px 0; }
        td { padding:10px 0; border-top:1px solid #f1f5f9; vertical-align:middle; }
        .alert { padding:12px 14px; border-radius:10px; margin-bottom:16px; }
        .alert.success { background:#dcfce7; color:#166534; }
        .alert.error { background:#fee2e2; color:#991b1b; }
        .hint { color:#64748b; font-size:0.82rem; margin-bottom:16px; line-height:1.45; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <h1 style="margin-bottom:6px;">Equipo HQ</h1>
    <p class="hint">Solo roles del panel interno. No toca las apps de comercios ni drivers.</p>
    <?php echo $mensaje; ?>
    <div class="card">
        <h3 style="margin:0 0 14px;">Nueva cuenta</h3>
        <form method="POST">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="crear_usuario" value="1">
            <div class="grid">
                <div><label>Usuario</label><input name="usuario" required></div>
                <div><label>Nombre</label><input name="nombre" required></div>
                <div><label>Rol</label>
                    <select name="rol">
                        <?php foreach ($roles as $k => $t): ?><option value="<?php echo yora_h($k); ?>"><?php echo yora_h($t); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Contraseña</label><input type="password" name="password" minlength="8" required></div>
            </div>
            <button class="btn" type="submit">Crear</button>
        </form>
    </div>
    <div class="card">
        <h3 style="margin:0 0 14px;">Cuentas</h3>
        <table>
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Clave</th></tr></thead>
            <tbody>
            <?php foreach ($equipo as $u): ?>
                <tr>
                    <td><?php echo yora_h($u['nombre']); ?></td>
                    <td><?php echo yora_h($u['usuario']); ?></td>
                    <td>
                        <form method="POST" style="display:flex; gap:6px; align-items:center;">
                            <?php echo yora_csrf_field(); ?>
                            <input type="hidden" name="cambiar_rol" value="1">
                            <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <select name="rol" style="margin:0; width:auto;" <?php echo ((int)$u['id'] === (int)($_SESSION['admin_id'] ?? 0)) ? 'disabled' : ''; ?>>
                                <?php foreach ($roles as $k => $t): ?>
                                    <option value="<?php echo yora_h($k); ?>" <?php echo ($u['rol'] ?? 'super') === $k ? 'selected' : ''; ?>><?php echo yora_h($k); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ((int)$u['id'] !== (int)($_SESSION['admin_id'] ?? 0)): ?>
                            <button class="btn btn-ghost" type="submit">OK</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:flex; gap:6px;">
                            <?php echo yora_csrf_field(); ?>
                            <input type="hidden" name="reset_pass" value="1">
                            <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <input type="password" name="password" placeholder="Nueva" minlength="8" style="margin:0; width:140px;" required>
                            <button class="btn btn-ghost" type="submit">Cambiar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
