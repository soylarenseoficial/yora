<?php
require_once 'conexion.php';
yora_require_admin_pagina('banners.php');
yora_timezone($conexion);

$conexion->query("CREATE TABLE IF NOT EXISTS banners_app (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(140) NOT NULL DEFAULT '',
    cuerpo TEXT NULL,
    imagen_url VARCHAR(500) NOT NULL DEFAULT '',
    boton_texto VARCHAR(80) NOT NULL DEFAULT '',
    boton_url VARCHAR(500) NOT NULL DEFAULT '',
    audiencia ENUM('drivers','comercios','clientes','todos') NOT NULL DEFAULT 'drivers',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    orden INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mensaje = '';
$upload_dir = __DIR__ . '/../uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }

    if (isset($_POST['guardar_banner'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $cuerpo = trim((string) ($_POST['cuerpo'] ?? ''));
        $boton_texto = trim((string) ($_POST['boton_texto'] ?? ''));
        $boton_url = trim((string) ($_POST['boton_url'] ?? ''));
        $audiencia = (string) ($_POST['audiencia'] ?? 'drivers');
        $orden = (int) ($_POST['orden'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;
        if (!in_array($audiencia, ['drivers', 'comercios', 'clientes', 'todos'], true)) {
            $audiencia = 'drivers';
        }
        if ($titulo === '' || mb_strlen($titulo) > 140) {
            $mensaje = "<div class='alert error'>El título es obligatorio (máx. 140).</div>";
        } elseif ($boton_url !== '' && !preg_match('#^(https?://|/|ajustes|dashboard|billetera)#i', $boton_url)) {
            $mensaje = "<div class='alert error'>La URL del botón debe ser http(s), ruta /… o pantalla de la app (ajustes.php, etc.).</div>";
        } else {
            $img = trim((string) ($_POST['imagen_actual'] ?? ''));
            if (isset($_FILES['imagen']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $fn = yora_upload($_FILES['imagen'], $upload_dir);
                if ($fn) {
                    $img = '/uploads/' . $fn;
                } else {
                    $mensaje = "<div class='alert error'>No se pudo subir la imagen (jpg/png/webp, máx 5 MB).</div>";
                }
            }
            if ($mensaje === '') {
                if ($id > 0) {
                    yora_exec(
                        $conexion,
                        'UPDATE banners_app SET titulo=?, cuerpo=?, imagen_url=?, boton_texto=?, boton_url=?, audiencia=?, activo=?, orden=? WHERE id=?',
                        'ssssssiii',
                        $titulo, $cuerpo, $img, $boton_texto, $boton_url, $audiencia, $activo, $orden, $id
                    );
                    $mensaje = "<div class='alert success'>Banner actualizado.</div>";
                } else {
                    yora_exec(
                        $conexion,
                        'INSERT INTO banners_app (titulo, cuerpo, imagen_url, boton_texto, boton_url, audiencia, activo, orden) VALUES (?,?,?,?,?,?,?,?)',
                        'ssssssii',
                        $titulo, $cuerpo, $img, $boton_texto, $boton_url, $audiencia, $activo, $orden
                    );
                    $mensaje = "<div class='alert success'>Banner creado. Los drivers lo verán al abrir el radar.</div>";
                }
            }
        }
    } elseif (isset($_POST['toggle_banner'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $activo = (int) ($_POST['activo'] ?? 0) ? 0 : 1;
        if ($id > 0) {
            yora_exec($conexion, 'UPDATE banners_app SET activo=? WHERE id=?', 'ii', $activo, $id);
            $mensaje = "<div class='alert success'>Estado del banner actualizado.</div>";
        }
    } elseif (isset($_POST['borrar_banner'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            yora_exec($conexion, 'DELETE FROM banners_app WHERE id=?', 'i', $id);
            $mensaje = "<div class='alert success'>Banner eliminado.</div>";
        }
    }
}

$editar = null;
$edit_id = (int) ($_GET['editar'] ?? 0);
if ($edit_id > 0) {
    $editar = yora_one($conexion, 'SELECT * FROM banners_app WHERE id=?', 'i', $edit_id);
}

$lista = yora_all($conexion, 'SELECT * FROM banners_app ORDER BY orden ASC, id DESC');
$activos = 0;
foreach ($lista as $b) {
    if ((int) ($b['activo'] ?? 0) === 1) {
        $activos++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Banners App</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#ffffff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
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
        .card { background:var(--bg-card); padding:28px; border-radius:16px; border:1px solid var(--border-color); margin-bottom:20px; }
        .grid-2 { display:grid; grid-template-columns:1.1fr .9fr; gap:20px; align-items:start; }
        @media (max-width:1100px) { .grid-2 { grid-template-columns:1fr; } }
        label { display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px; }
        input, textarea, select { width:100%; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; margin-bottom:14px; }
        .btn-save { background:var(--yora-orange); color:#fff; border:none; padding:14px 22px; border-radius:12px; font-weight:700; cursor:pointer; }
        .btn-ghost { background:#f1f5f9; color:#334155; border:none; padding:10px 14px; border-radius:10px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-danger { background:#fee2e2; color:#991b1b; }
        .alert { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:0.9rem; }
        .alert.success { background:#dcfce7; color:#166534; }
        .alert.error { background:#fee2e2; color:#991b1b; }
        .kpis { display:flex; gap:12px; margin:0 0 24px; flex-wrap:wrap; }
        .kpi { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:16px 20px; min-width:140px; }
        .kpi b { display:block; font-size:1.6rem; }
        .kpi span { font-size:0.75rem; color:#64748b; font-weight:600; }
        .hint { font-size:0.82rem; color:#64748b; line-height:1.5; margin-bottom:16px; }
        .check { display:flex; align-items:center; gap:8px; margin:0 0 16px; font-weight:600; font-size:0.9rem; }
        .check input { width:auto; margin:0; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:0.85rem; vertical-align:middle; }
        th { color:#64748b; font-size:0.72rem; text-transform:uppercase; letter-spacing:.4px; }
        .thumb { width:64px; height:64px; object-fit:cover; border-radius:10px; background:#f1f5f9; }
        .badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:0.72rem; font-weight:700; }
        .badge.on { background:#dcfce7; color:#166534; }
        .badge.off { background:#f1f5f9; color:#64748b; }
        .acciones { display:flex; gap:6px; flex-wrap:wrap; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <h1 style="font-size:2rem; margin-bottom:6px;">Banners App</h1>
    <p style="color:#64748b; margin-bottom:24px;">Anuncios a pantalla completa en la app del driver (WebView). Se muestran al abrir el radar hasta que el conductor los cierre.</p>
    <div class="kpis">
        <div class="kpi"><b><?php echo count($lista); ?></b><span>Total banners</span></div>
        <div class="kpi"><b><?php echo $activos; ?></b><span>Activos ahora</span></div>
    </div>
    <?php echo $mensaje; ?>

    <div class="grid-2">
        <div class="card">
            <h3 style="margin:0 0 8px;"><?php echo $editar ? 'Editar banner #' . (int) $editar['id'] : 'Nuevo banner'; ?></h3>
            <p class="hint">Sube una imagen (casco, kit, promo…). Título y botón son lo que más se lee. El cuerpo admite varias líneas.</p>
            <form method="POST" enctype="multipart/form-data">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="guardar_banner" value="1">
                <input type="hidden" name="id" value="<?php echo (int) ($editar['id'] ?? 0); ?>">
                <input type="hidden" name="imagen_actual" value="<?php echo yora_h($editar['imagen_url'] ?? ''); ?>">

                <label>Título</label>
                <input type="text" name="titulo" maxlength="140" required placeholder="Ej: COMPLETA TU ACTIVACIÓN" value="<?php echo yora_h($editar['titulo'] ?? ''); ?>">

                <label>Texto / cuerpo</label>
                <textarea name="cuerpo" rows="4" placeholder="Durante tu visita deberás adquirir…"><?php echo yora_h($editar['cuerpo'] ?? ''); ?></textarea>

                <label>Imagen (jpg/png/webp)</label>
                <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp">
                <?php if (!empty($editar['imagen_url'])): ?>
                    <p class="hint">Actual: <a href="https://yoradelivery.com<?php echo yora_h($editar['imagen_url']); ?>" target="_blank" rel="noopener">ver imagen</a></p>
                <?php endif; ?>

                <label>Texto del botón</label>
                <input type="text" name="boton_texto" maxlength="80" placeholder="Ej: Encontrar una oficina cerca de mí" value="<?php echo yora_h($editar['boton_texto'] ?? ''); ?>">

                <label>URL / pantalla al tocar el botón</label>
                <input type="text" name="boton_url" maxlength="500" placeholder="https://…  o  ajustes_documentos.php" value="<?php echo yora_h($editar['boton_url'] ?? ''); ?>">

                <label>Audiencia</label>
                <select name="audiencia">
                    <?php
                    $aud = $editar['audiencia'] ?? 'drivers';
                    foreach (['drivers' => 'Drivers (app)', 'comercios' => 'Comercios', 'clientes' => 'Clientes', 'todos' => 'Todos'] as $k => $lab) {
                        $sel = $aud === $k ? ' selected' : '';
                        echo '<option value="' . yora_h($k) . '"' . $sel . '>' . yora_h($lab) . '</option>';
                    }
                    ?>
                </select>

                <label>Orden (menor = primero)</label>
                <input type="number" name="orden" value="<?php echo (int) ($editar['orden'] ?? 0); ?>">

                <label class="check">
                    <input type="checkbox" name="activo" value="1" <?php echo !isset($editar) || (int) ($editar['activo'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    Activo (visible en la app)
                </label>

                <button class="btn-save" type="submit"><?php echo $editar ? 'Guardar cambios' : 'Publicar banner'; ?></button>
                <?php if ($editar): ?>
                    <a class="btn-ghost" href="banners.php" style="margin-left:8px;">Cancelar</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 14px;">Listado</h3>
            <?php if (!$lista): ?>
                <p class="hint">Aún no hay banners. Crea el primero a la izquierda.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Img</th>
                        <th>Banner</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lista as $b): ?>
                    <tr>
                        <td>
                            <?php if ($b['imagen_url']): ?>
                                <img class="thumb" src="https://yoradelivery.com<?php echo yora_h($b['imagen_url']); ?>" alt="">
                            <?php else: ?>
                                <div class="thumb"></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo yora_h($b['titulo']); ?></strong><br>
                            <span style="color:#64748b;font-size:0.78rem;"><?php echo yora_h($b['audiencia']); ?> · orden <?php echo (int) $b['orden']; ?></span>
                        </td>
                        <td>
                            <span class="badge <?php echo (int) $b['activo'] ? 'on' : 'off'; ?>">
                                <?php echo (int) $b['activo'] ? 'Activo' : 'Off'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="acciones">
                                <a class="btn-ghost" href="banners.php?editar=<?php echo (int) $b['id']; ?>">Editar</a>
                                <form method="POST" style="display:inline;">
                                    <?php echo yora_csrf_field(); ?>
                                    <input type="hidden" name="toggle_banner" value="1">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <input type="hidden" name="activo" value="<?php echo (int) $b['activo']; ?>">
                                    <button class="btn-ghost" type="submit"><?php echo (int) $b['activo'] ? 'Pausar' : 'Activar'; ?></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este banner?');">
                                    <?php echo yora_csrf_field(); ?>
                                    <input type="hidden" name="borrar_banner" value="1">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <button class="btn-ghost btn-danger" type="submit">Borrar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
