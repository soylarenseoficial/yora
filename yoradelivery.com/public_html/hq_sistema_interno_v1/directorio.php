<?php
require_once 'conexion.php';
yora_require_admin_pagina('directorio.php');
yora_timezone($conexion);

$mensaje = '';
$upload_dir = __DIR__ . '/../uploads/';
$id = (int) ($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }
    if (isset($_POST['guardar_perfil'])) {
        $id = (int) ($_POST['comercio_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $categoria = trim((string) ($_POST['categoria'] ?? 'Restaurante'));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $horario = trim((string) ($_POST['horario'] ?? ''));
        $instagram = trim((string) ($_POST['instagram'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $direccion = trim((string) ($_POST['direccion'] ?? ''));
        $publico = isset($_POST['perfil_publico']) ? 1 : 0;
        $menu = isset($_POST['gestion_menu']) ? 1 : 0;
        if ($id < 1 || $nombre === '') {
            $mensaje = "<div class='alert error'>Falta el comercio.</div>";
        } else {
            $slug = yora_slug_comercio($nombre, $id);
            yora_exec(
                $conexion,
                'UPDATE comercios SET nombre = ?, categoria = ?, descripcion = ?, horario = ?, instagram = ?, telefono = ?, direccion = ?, perfil_publico = ?, gestion_menu = ?, slug = ? WHERE id = ?',
                'sssssssiisi',
                $nombre, $categoria, $descripcion, $horario, $instagram, $telefono, $direccion, $publico, $menu, $slug, $id
            );
            $mensaje = "<div class='alert success'>Perfil del directorio guardado.</div>";
        }
    } elseif (isset($_POST['agregar_producto'])) {
        $id = (int) ($_POST['comercio_id'] ?? 0);
        $nombre = trim((string) ($_POST['p_nombre'] ?? ''));
        $desc = trim((string) ($_POST['p_descripcion'] ?? ''));
        $cat = trim((string) ($_POST['p_categoria'] ?? ''));
        $precio = round((float) ($_POST['p_precio'] ?? 0), 2);
        $img = '';
        if (isset($_FILES['p_imagen']) && ($_FILES['p_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = yora_upload($_FILES['p_imagen'], $upload_dir);
            if ($fn) {
                $img = '/uploads/' . $fn;
            }
        }
        if ($id < 1 || $nombre === '' || $precio <= 0) {
            $mensaje = "<div class='alert error'>Nombre y precio del plato son obligatorios.</div>";
        } else {
            yora_exec(
                $conexion,
                'INSERT INTO productos (comercio_id, nombre, descripcion, precio, imagen_url, estado, categoria) VALUES (?,?,?,?,?,?,?)',
                'issdsss',
                $id, $nombre, $desc, $precio, $img, 'activo', $cat
            );
            $mensaje = "<div class='alert success'>Plato agregado al menú.</div>";
        }
    } elseif (isset($_POST['borrar_producto'])) {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        $id = (int) ($_POST['comercio_id'] ?? 0);
        if ($pid > 0) {
            yora_exec($conexion, 'DELETE FROM productos WHERE id = ? AND comercio_id = ?', 'ii', $pid, $id);
            $mensaje = "<div class='alert success'>Plato eliminado.</div>";
        }
    } elseif (isset($_POST['toggle_producto'])) {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        $id = (int) ($_POST['comercio_id'] ?? 0);
        $est = (($_POST['estado'] ?? '') === 'activo') ? 'inactivo' : 'activo';
        yora_exec($conexion, 'UPDATE productos SET estado = ? WHERE id = ? AND comercio_id = ?', 'sii', $est, $pid, $id);
        $mensaje = "<div class='alert success'>Visibilidad del plato actualizada.</div>";
    } elseif (isset($_POST['editar_producto'])) {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        $id = (int) ($_POST['comercio_id'] ?? 0);
        $nombre = trim((string) ($_POST['p_nombre'] ?? ''));
        $desc = trim((string) ($_POST['p_descripcion'] ?? ''));
        $cat = trim((string) ($_POST['p_categoria'] ?? ''));
        $precio = round((float) ($_POST['p_precio'] ?? 0), 2);
        if ($pid < 1 || $id < 1 || $nombre === '' || $precio <= 0) {
            $mensaje = "<div class='alert error'>Nombre y precio del plato son obligatorios.</div>";
        } else {
            yora_exec(
                $conexion,
                'UPDATE productos SET nombre = ?, descripcion = ?, categoria = ?, precio = ? WHERE id = ? AND comercio_id = ?',
                'sssdii',
                $nombre, $desc, $cat, $precio, $pid, $id
            );
            if (isset($_FILES['p_imagen']) && ($_FILES['p_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $fn = yora_upload($_FILES['p_imagen'], $upload_dir);
                if ($fn) {
                    yora_exec($conexion, 'UPDATE productos SET imagen_url = ? WHERE id = ? AND comercio_id = ?', 'sii', '/uploads/' . $fn, $pid, $id);
                }
            }
            $mensaje = "<div class='alert success'>Plato actualizado.</div>";
        }
    }
}

$lista = yora_all($conexion, 'SELECT id, nombre, categoria, perfil_publico, slug, telefono, logo_url FROM comercios ORDER BY nombre ASC');
$comercio = $id > 0 ? yora_one($conexion, 'SELECT * FROM comercios WHERE id = ?', 'i', $id) : null;
$productos = $comercio ? yora_all($conexion, 'SELECT * FROM productos WHERE comercio_id = ? ORDER BY categoria ASC, id ASC', 'i', $id) : [];
$categorias_existentes = yora_all($conexion, "SELECT DISTINCT categoria FROM comercios WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria");
$menu_grupos = [];
foreach ($productos ?: [] as $p) {
    $sec = trim((string) ($p['categoria'] ?? '')) ?: 'Menú';
    $menu_grupos[$sec][] = $p;
}
$n_platos = is_array($productos) ? count($productos) : 0;
$n_activos = 0;
foreach ($productos ?: [] as $p) {
    if (($p['estado'] ?? '') === 'activo') {
        $n_activos++;
    }
}
$n_publicos = 0;
foreach ($lista ?: [] as $c) {
    if ((int) ($c['perfil_publico'] ?? 0) === 1) {
        $n_publicos++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Directorio</title>
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
        .content { flex:1; padding:28px 32px; overflow-y:auto; }
        .page-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-bottom:18px; }
        .page-head h1 { font-size:1.55rem; font-weight:800; letter-spacing:-.03em; }
        .kpis { display:flex; gap:8px; flex-wrap:wrap; }
        .kpi { background:#fff; border:1px solid #e5e7eb; border-radius:999px; padding:7px 12px; font-size:.78rem; font-weight:700; color:#475569; }
        .kpi b { color:#ce4e2d; }
        .layout { display:grid; grid-template-columns:300px 1fr; gap:18px; align-items:start; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
        .busca { width:100%; padding:11px 12px; border:1px solid #e2e8f0; border-radius:12px; margin:10px 0 12px; font-family:inherit; background:#f8fafc; }
        .shop { display:flex; gap:10px; padding:10px; border-radius:14px; text-decoration:none; color:inherit; margin-bottom:6px; border:1px solid transparent; }
        .shop:hover { background:#f8fafc; }
        .shop.on { background:#fff7ed; border-color:#fed7aa; }
        .shop img { width:44px; height:44px; border-radius:12px; object-fit:cover; background:#f1f5f9; }
        .shop b { display:block; font-size:0.86rem; }
        .shop span { font-size:0.72rem; color:#64748b; }
        .dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:4px; }
        .dot.on { background:#16a34a; } .dot.off { background:#cbd5e1; }
        label { display:block; font-size:0.78rem; font-weight:700; margin:10px 0 5px; }
        input, textarea, select { width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; }
        .btn { background:var(--yora-orange); color:#fff; border:none; padding:11px 16px; border-radius:10px; font-weight:700; cursor:pointer; font-family:inherit; }
        .btn-ghost { background:#f1f5f9; color:#334155; }
        .btn-red { background:#fee2e2; color:#b91c1c; }
        .btn-sm { padding:8px 12px; font-size:.78rem; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .hero-local { display:flex; gap:14px; align-items:center; margin-bottom:14px; }
        .hero-local img { width:64px; height:64px; border-radius:16px; object-fit:cover; border:1px solid #e2e8f0; }
        .pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:.72rem; font-weight:800; }
        .pill.on { background:#dcfce7; color:#166534; }
        .pill.off { background:#f1f5f9; color:#64748b; }
        .sec-title { font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin:18px 0 8px; font-weight:800; }
        .plato { display:flex; gap:12px; align-items:flex-start; padding:12px; border:1px solid #f1f5f9; border-radius:16px; margin-bottom:10px; background:#fafbfc; }
        .plato.oculto { opacity:.55; }
        .plato img { width:64px; height:64px; border-radius:14px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
        .plato .acciones { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
        .edit-box { display:none; margin-top:10px; background:#fff; border:1px dashed #fdba74; border-radius:12px; padding:12px; }
        .edit-box.open { display:block; }
        .nuevo { border:2px dashed #e2e8f0; border-radius:16px; padding:16px; margin-top:8px; background:#fff; }
        .alert { padding:12px 14px; border-radius:10px; margin-bottom:14px; }
        .alert.success { background:#dcfce7; color:#166534; }
        .alert.error { background:#fee2e2; color:#991b1b; }
        .hint { color:#64748b; font-size:0.82rem; }
        .empty { text-align:center; color:#94a3b8; padding:28px 10px; }
        @media(max-width:900px){ .layout { grid-template-columns:1fr; } .content { padding:16px; } }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <div class="page-head">
        <div>
            <h1>Directorio y menú</h1>
            <p class="hint">El mismo comercio de HQ. Lo que marques visible sale en yoradelivery.com; el carrito va al WhatsApp del local.</p>
        </div>
        <div class="kpis">
            <span class="kpi"><b><?php echo count($lista ?: []); ?></b> comercios</span>
            <span class="kpi"><b><?php echo (int) $n_publicos; ?></b> públicos</span>
            <?php if ($comercio): ?><span class="kpi"><b><?php echo (int) $n_activos; ?></b>/<?php echo (int) $n_platos; ?> platos visibles</span><?php endif; ?>
        </div>
    </div>
    <?php echo $mensaje; ?>
    <div class="layout">
        <div class="card">
            <b>Comercios</b>
            <input class="busca" type="search" id="busca-shop" placeholder="Buscar local..." oninput="filtrarShops(this.value)">
            <div style="max-height:70vh; overflow:auto;">
                <?php foreach ($lista as $c):
                    $logo = yora_url_archivo($c['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
                ?>
                <a class="shop <?php echo ((int)$c['id'] === $id) ? 'on' : ''; ?>" data-q="<?php echo yora_h(mb_strtolower($c['nombre'] . ' ' . ($c['categoria'] ?? ''))); ?>" href="directorio.php?id=<?php echo (int) $c['id']; ?>">
                    <img src="<?php echo yora_h($logo); ?>" alt="" onerror="this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png'">
                    <div>
                        <b><?php echo yora_h($c['nombre']); ?></b>
                        <span><i class="dot <?php echo ((int)$c['perfil_publico'] === 1) ? 'on' : 'off'; ?>"></i><?php echo yora_h($c['categoria'] ?: 'Sin categoría'); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <?php if (!$comercio): ?>
                <div class="card empty">Elige un comercio a la izquierda para publicar su perfil y armar el menú.</div>
            <?php else:
                $logo = yora_url_archivo($comercio['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
                $slug = trim((string) ($comercio['slug'] ?? '')) ?: yora_slug_comercio((string) $comercio['nombre'], (int) $comercio['id']);
            ?>
            <div class="card" style="margin-bottom:16px;">
                <div class="hero-local">
                    <img src="<?php echo yora_h($logo); ?>" alt="">
                    <div style="flex:1;">
                        <h2 style="font-size:1.2rem; font-weight:800;"><?php echo yora_h($comercio['nombre']); ?></h2>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">
                            <span class="pill <?php echo ((int)$comercio['perfil_publico'] === 1) ? 'on' : 'off'; ?>"><?php echo ((int)$comercio['perfil_publico'] === 1) ? 'Público en la web' : 'Oculto en la web'; ?></span>
                            <a href="https://yoradelivery.com/comercio.php?s=<?php echo urlencode($slug); ?>" target="_blank" style="font-size:0.8rem; color:#e4441b; font-weight:700;">Ver en yoradelivery.com →</a>
                        </div>
                    </div>
                </div>
                <form method="POST">
                    <?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="guardar_perfil" value="1">
                    <input type="hidden" name="comercio_id" value="<?php echo (int) $comercio['id']; ?>">
                    <div class="grid-2">
                        <div><label>Nombre</label><input name="nombre" value="<?php echo yora_h($comercio['nombre']); ?>" required></div>
                        <div>
                            <label>Categoría</label>
                            <input name="categoria" list="cats" value="<?php echo yora_h($comercio['categoria'] ?? ''); ?>" placeholder="Hamburguesas, farmacia...">
                            <datalist id="cats">
                                <?php foreach ($categorias_existentes as $cat): ?>
                                <option value="<?php echo yora_h($cat['categoria']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <label>Qué vende (texto público)</label>
                    <textarea name="descripcion" rows="3"><?php echo yora_h($comercio['descripcion'] ?? ''); ?></textarea>
                    <div class="grid-2">
                        <div><label>Horario</label><input name="horario" value="<?php echo yora_h($comercio['horario'] ?? ''); ?>" placeholder="5:00 PM a 11:00 PM"></div>
                        <div><label>WhatsApp del local</label><input name="telefono" value="<?php echo yora_h($comercio['telefono'] ?? ''); ?>"></div>
                        <div><label>Dirección</label><input name="direccion" value="<?php echo yora_h($comercio['direccion'] ?? ''); ?>"></div>
                        <div><label>Instagram</label><input name="instagram" value="<?php echo yora_h($comercio['instagram'] ?? ''); ?>"></div>
                    </div>
                    <label style="display:flex; gap:8px; align-items:center; margin:14px 0;">
                        <input type="checkbox" name="perfil_publico" value="1" <?php echo ((int)$comercio['perfil_publico'] === 1) ? 'checked' : ''; ?> style="width:auto;">
                        Visible en yoradelivery.com
                    </label>
                    <label style="display:flex; gap:8px; align-items:center; margin:14px 0;">
                        <input type="checkbox" name="gestion_menu" value="1" <?php echo ((int)($comercio['gestion_menu'] ?? 0) === 1) ? 'checked' : ''; ?> style="width:auto;">
                        El comercio puede gestionar este menú desde su panel
                    </label>
                    <button class="btn" type="submit">Guardar perfil</button>
                </form>
            </div>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                    <h3 style="font-size:1.05rem;">Menú del directorio</h3>
                    <span class="hint"><?php echo (int) $n_platos; ?> platos · el cliente pide por WhatsApp</span>
                </div>
                <?php if (!$productos): ?>
                    <p class="empty">Este local aún no tiene platos. Agrégalos abajo.</p>
                <?php endif; ?>
                <?php foreach ($menu_grupos as $sec => $items): ?>
                    <div class="sec-title"><?php echo yora_h($sec); ?></div>
                    <?php foreach ($items as $p):
                        $pimg = yora_url_archivo($p['imagen_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/1046/1046784.png', 'https://yoradelivery.com');
                        $oculto = ($p['estado'] ?? '') !== 'activo';
                    ?>
                    <div class="plato <?php echo $oculto ? 'oculto' : ''; ?>">
                        <img src="<?php echo yora_h($pimg); ?>" alt="">
                        <div style="flex:1; min-width:0;">
                            <b><?php echo yora_h($p['nombre']); ?></b>
                            <?php if (!empty($p['descripcion'])): ?><div class="hint"><?php echo yora_h($p['descripcion']); ?></div><?php endif; ?>
                            <div style="margin-top:4px; font-weight:800; color:#ce4e2d;">$<?php echo number_format((float)$p['precio'], 2); ?></div>
                            <div class="acciones">
                                <form method="POST"><?php echo yora_csrf_field(); ?>
                                    <input type="hidden" name="toggle_producto" value="1">
                                    <input type="hidden" name="comercio_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="producto_id" value="<?php echo (int) $p['id']; ?>">
                                    <input type="hidden" name="estado" value="<?php echo yora_h($p['estado']); ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?php echo $oculto ? 'Mostrar' : 'Ocultar'; ?></button>
                                </form>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEdit(<?php echo (int) $p['id']; ?>)">Editar</button>
                                <form method="POST" onsubmit="return confirm('¿Quitar este plato?');"><?php echo yora_csrf_field(); ?>
                                    <input type="hidden" name="borrar_producto" value="1">
                                    <input type="hidden" name="comercio_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="producto_id" value="<?php echo (int) $p['id']; ?>">
                                    <button class="btn btn-red btn-sm" type="submit">Quitar</button>
                                </form>
                            </div>
                            <form class="edit-box" id="edit-<?php echo (int) $p['id']; ?>" method="POST" enctype="multipart/form-data">
                                <?php echo yora_csrf_field(); ?>
                                <input type="hidden" name="editar_producto" value="1">
                                <input type="hidden" name="comercio_id" value="<?php echo (int) $id; ?>">
                                <input type="hidden" name="producto_id" value="<?php echo (int) $p['id']; ?>">
                                <div class="grid-2">
                                    <div><label>Nombre</label><input name="p_nombre" value="<?php echo yora_h($p['nombre']); ?>" required></div>
                                    <div><label>Sección</label><input name="p_categoria" value="<?php echo yora_h($p['categoria'] ?? ''); ?>"></div>
                                    <div><label>Precio USD</label><input type="number" step="0.01" min="0.01" name="p_precio" value="<?php echo yora_h(number_format((float)$p['precio'], 2, '.', '')); ?>" required></div>
                                    <div><label>Nueva foto</label><input type="file" name="p_imagen" accept="image/jpeg,image/png,image/webp"></div>
                                </div>
                                <label>Descripción</label>
                                <textarea name="p_descripcion" rows="2"><?php echo yora_h($p['descripcion'] ?? ''); ?></textarea>
                                <button class="btn btn-sm" type="submit" style="margin-top:10px;">Guardar plato</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <div class="nuevo">
                    <b>Agregar plato</b>
                    <form method="POST" enctype="multipart/form-data" style="margin-top:10px;">
                        <?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="agregar_producto" value="1">
                        <input type="hidden" name="comercio_id" value="<?php echo (int) $id; ?>">
                        <div class="grid-2">
                            <div><label>Plato</label><input name="p_nombre" required placeholder="Tequeño de queso"></div>
                            <div><label>Sección del menú</label><input name="p_categoria" placeholder="Entradas"></div>
                            <div><label>Precio USD</label><input type="number" step="0.01" min="0.01" name="p_precio" required></div>
                            <div><label>Foto</label><input type="file" name="p_imagen" accept="image/jpeg,image/png,image/webp"></div>
                        </div>
                        <label>Descripción</label>
                        <textarea name="p_descripcion" rows="2" placeholder="Pan brioche, milanesa..."></textarea>
                        <button class="btn" type="submit" style="margin-top:12px;">Agregar al menú</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function filtrarShops(q) {
    q = (q || '').toLowerCase().trim();
    document.querySelectorAll('.shop').forEach(function (el) {
        el.style.display = !q || (el.getAttribute('data-q') || '').indexOf(q) !== -1 ? '' : 'none';
    });
}
function toggleEdit(id) {
    var box = document.getElementById('edit-' + id);
    if (!box) return;
    box.classList.toggle('open');
}
</script>
</body>
</html>
