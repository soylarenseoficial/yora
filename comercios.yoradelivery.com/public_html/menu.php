<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['comercio_id'])) {
    header('Location: index.php');
    exit;
}
$comercio_id = (int) $_SESSION['comercio_id'];
yora_timezone($conexion);
yora_ensure_schema($conexion);

$comercio = yora_one($conexion, 'SELECT * FROM comercios WHERE id = ?', 'i', $comercio_id);
if (!$comercio) {
    header('Location: index.php');
    exit;
}
$menu_habilitado = (int) ($comercio['gestion_menu'] ?? 0) === 1;

$mensaje = '';
$upload_dir = yora_dir_uploads_dominio('yoradelivery.com') ?: (__DIR__ . '/uploads/');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$menu_habilitado) {
        $mensaje = 'Yora aún no habilitó tu menú.';
    } elseif (!yora_peticion_propia()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    } elseif (isset($_POST['agregar'])) {
        $nombre = trim((string) ($_POST['p_nombre'] ?? ''));
        $desc = trim((string) ($_POST['p_descripcion'] ?? ''));
        $cat = trim((string) ($_POST['p_categoria'] ?? ''));
        $precio = round((float) ($_POST['p_precio'] ?? 0), 2);
        $img = '';
        if (isset($_FILES['p_imagen']) && ($_FILES['p_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = yora_upload($_FILES['p_imagen'], $upload_dir);
            if ($fn) {
                $img = (strpos($upload_dir, 'yoradelivery.com') !== false)
                    ? 'https://yoradelivery.com/uploads/' . $fn
                    : 'https://comercios.yoradelivery.com/uploads/' . $fn;
            }
        }
        if ($nombre === '' || $precio <= 0) {
            $mensaje = 'Nombre y precio son obligatorios.';
        } else {
            yora_exec(
                $conexion,
                'INSERT INTO productos (comercio_id, nombre, descripcion, precio, imagen_url, estado, categoria) VALUES (?,?,?,?,?,?,?)',
                'issdsss',
                $comercio_id, $nombre, $desc, $precio, $img, 'activo', $cat
            );
            $mensaje = 'Plato agregado al menú.';
        }
    } elseif (isset($_POST['borrar'])) {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        if ($pid > 0) {
            yora_exec($conexion, 'DELETE FROM productos WHERE id = ? AND comercio_id = ?', 'ii', $pid, $comercio_id);
            $mensaje = 'Plato eliminado.';
        }
    } elseif (isset($_POST['toggle'])) {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        $est = (($_POST['estado'] ?? '') === 'activo') ? 'inactivo' : 'activo';
        yora_exec($conexion, 'UPDATE productos SET estado = ? WHERE id = ? AND comercio_id = ?', 'sii', $est, $pid, $comercio_id);
        $mensaje = 'Visibilidad actualizada.';
    }
}

$productos = yora_all($conexion, 'SELECT * FROM productos WHERE comercio_id = ? ORDER BY categoria ASC, id ASC', 'i', $comercio_id) ?: [];
$slug = trim((string) ($comercio['slug'] ?? '')) ?: yora_slug_comercio((string) $comercio['nombre'], $comercio_id);
$logo = yora_url_archivo($comercio['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png');
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi menú | YoraB2B</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:#f4f7fe; color:#1e293b; padding:22px 16px 80px; }
        .wrap { max-width:720px; margin:0 auto; }
        a.back { color:#e4441b; font-weight:700; text-decoration:none; font-size:0.88rem; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; }
        h1 { font-size:1.45rem; font-weight:800; }
        .hint { color:#64748b; font-size:0.82rem; margin:6px 0 16px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:18px; margin-bottom:14px; }
        .plato { display:flex; gap:12px; align-items:center; padding:12px 0; border-bottom:1px solid #f1f5f9; }
        .plato:last-of-type { border-bottom:0; }
        .plato img { width:56px; height:56px; border-radius:12px; object-fit:cover; background:#f1f5f9; }
        label { display:block; font-size:0.75rem; font-weight:700; color:#64748b; margin:10px 0 5px; }
        input, textarea { width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:12px; font-family:inherit; background:#f8fafc; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .btn { background:#e4441b; color:#fff; border:none; padding:12px 16px; border-radius:12px; font-weight:800; cursor:pointer; }
        .btn-ghost { background:#f1f5f9; color:#334155; }
        .btn-red { background:#fee2e2; color:#b91c1c; }
        .ok { background:#dcfce7; color:#166534; padding:10px 12px; border-radius:10px; font-size:0.82rem; font-weight:700; margin-bottom:12px; }
        @media (max-width:640px){ .grid { grid-template-columns:1fr; } }
        .bottom-nav { display:none; }
        @media (max-width:900px){
            .bottom-nav { display:flex; position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:1px solid #e2e8f0; padding:8px 6px env(safe-area-inset-bottom); z-index:50; }
            .bottom-nav a { flex:1; text-align:center; text-decoration:none; color:#64748b; font-size:0.65rem; font-weight:700; padding:8px 4px; }
            .bottom-nav a.active { color:#e4441b; }
            .bottom-nav i { display:block; font-size:1.45rem; margin:0 auto 2px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <a class="back" href="dashboard.php"><i class="ph ph-arrow-left"></i> Volver al panel</a>
    <h1>Menú del directorio</h1>
    <?php if (!$menu_habilitado): ?>
    <div class="ok" style="background:#fef3c7;color:#92400e;">Yora todavía no habilitó la gestión de menú para este local. Puedes ver esta pantalla, pero para publicar platos pídeselo a soporte.</div>
    <?php endif; ?>
    <p class="hint">Estos platos aparecen en yoradelivery.com. El cliente arma el carrito y te llega el pedido por WhatsApp.
        <a href="https://yoradelivery.com/comercio.php?s=<?php echo urlencode($slug); ?>" target="_blank" style="color:#e4441b; font-weight:700;">Ver ficha pública</a>
    </p>
    <?php if ($mensaje !== ''): ?><div class="ok"><?php echo yora_h($mensaje); ?></div><?php endif; ?>

    <div class="card">
        <?php if (!$productos): ?>
            <p class="hint" style="margin:0;">Todavía no tienes platos. Agrega el primero abajo.</p>
        <?php endif; ?>
        <?php foreach ($productos as $p):
            $pimg = yora_url_archivo($p['imagen_url'] ?? '', '', 'https://yoradelivery.com');
        ?>
        <div class="plato">
            <?php if ($pimg): ?><img src="<?php echo yora_h($pimg); ?>" alt=""><?php endif; ?>
            <div style="flex:1; min-width:0;">
                <b><?php echo yora_h($p['nombre']); ?></b>
                <div style="font-size:0.75rem; color:#64748b;"><?php echo yora_h($p['categoria'] ?: 'Menú'); ?> · $<?php echo number_format((float)$p['precio'], 2); ?> · <?php echo yora_h($p['estado']); ?></div>
            </div>
            <form method="POST"><?php echo yora_csrf_field(); ?>
                <input type="hidden" name="toggle" value="1">
                <input type="hidden" name="producto_id" value="<?php echo (int) $p['id']; ?>">
                <input type="hidden" name="estado" value="<?php echo yora_h($p['estado']); ?>">
                <button class="btn btn-ghost" type="submit"><?php echo $p['estado'] === 'activo' ? 'Ocultar' : 'Mostrar'; ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Quitar este plato?');"><?php echo yora_csrf_field(); ?>
                <input type="hidden" name="borrar" value="1">
                <input type="hidden" name="producto_id" value="<?php echo (int) $p['id']; ?>">
                <button class="btn btn-red" type="submit">Quitar</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2 style="font-size:1rem; margin-bottom:8px;">Agregar plato</h2>
        <form method="POST" enctype="multipart/form-data">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="agregar" value="1">
            <div class="grid">
                <div><label>Nombre</label><input name="p_nombre" required placeholder="Hamburguesa clásica"></div>
                <div><label>Sección</label><input name="p_categoria" placeholder="Hamburguesas"></div>
                <div><label>Precio USD</label><input type="number" step="0.01" min="0.01" name="p_precio" required></div>
                <div><label>Foto</label><input type="file" name="p_imagen" accept="image/*"></div>
            </div>
            <label>Descripción</label>
            <textarea name="p_descripcion" rows="2" placeholder="Pan brioche, carne, queso..."></textarea>
            <button class="btn" type="submit" style="margin-top:14px; width:100%;">Agregar al menú</button>
        </form>
    </div>
</div>
<nav class="bottom-nav">
    <a href="dashboard.php"><i class="ph ph-rocket-launch"></i>Inicio</a>
    <a href="dashboard.php#historial"><i class="ph ph-clock-counter-clockwise"></i>Pedidos</a>
    <a href="dashboard.php#recargas"><i class="ph ph-wallet"></i>Billetera</a>
    <a class="active" href="menu.php"><i class="ph ph-fork-knife"></i>Menú</a>
    <a href="dashboard.php#perfil"><i class="ph ph-storefront"></i>Perfil</a>
</nav>
<script>
fetch('/api/latido_comercio.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {});
setInterval(function () { fetch('/api/latido_comercio.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {}); }, 25000);
</script>
</body>
</html>
