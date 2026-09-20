<?php
require_once __DIR__ . '/../config.php';
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $mensaje = 'Solicitud rechazada. Recarga la página.';
        $tipo_mensaje = 'error';
    } elseif (!yora_rate_limit('reg-comercio:' . yora_client_ip(), 4, 3600)) {
        $mensaje = 'Demasiadas solicitudes desde esta red. Inténtalo más tarde.';
        $tipo_mensaje = 'error';
    } else {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $rif = trim((string) ($_POST['rif'] ?? ''));
        $razon = trim((string) ($_POST['razon_social'] ?? ''));
        $categoria = trim((string) ($_POST['categoria'] ?? 'Restaurante'));
        $direccion = trim((string) ($_POST['direccion'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $correo = trim((string) ($_POST['correo'] ?? ''));
        $horario = trim((string) ($_POST['horario'] ?? ''));

        if ($nombre === '' || $telefono === '' || $direccion === '' || $correo === '') {
            $mensaje = 'Completa local, dirección, WhatsApp y correo.';
            $tipo_mensaje = 'error';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje = 'El correo no es válido.';
            $tipo_mensaje = 'error';
        } elseif (yora_one($conexion, 'SELECT id FROM comercios WHERE telefono = ? LIMIT 1', 's', $telefono)) {
            $mensaje = 'Ya existe un comercio con ese teléfono.';
            $tipo_mensaje = 'error';
        } else {
            $logo = 'https://cdn-icons-png.flaticon.com/512/819/819814.png';
            if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $subida = yora_guardar_logo_comercio($_FILES['logo']);
                if ($subida['ok']) {
                    $logo = $subida['url'];
                }
            }
            $hash = password_hash(yora_clave_aleatoria(), PASSWORD_DEFAULT);
            yora_exec(
                $conexion,
                'INSERT INTO comercios (nombre, rif, razon_social, categoria, direccion, telefono, password, logo_url, estado_documentos, correo, horario, perfil_publico, estatus)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)',
                'ssssssssssss',
                $nombre, $rif, $razon !== '' ? $razon : $nombre, $categoria, $direccion, $telefono, $hash, $logo, 'Pendiente', $correo, $horario, 'pendiente'
            );
            $new_id = (int) $conexion->insert_id;
            if ($new_id > 0) {
                $slug = yora_slug_comercio($nombre, $new_id);
                try {
                    yora_exec($conexion, 'UPDATE comercios SET slug = ? WHERE id = ?', 'si', $slug, $new_id);
                } catch (Throwable $e) {}
            }
            $mensaje = '¡Solicitud enviada! El equipo de Yora revisará tu local y te avisará para activar el panel.';
            $tipo_mensaje = 'exito';
            if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $cuerpo = '<p>Recibimos tu solicitud para afiliar <b>' . yora_h($nombre) . '</b> a Yora Delivery.</p>
                    <p>Cuando el equipo verifique el local te enviaremos el acceso al panel de comercios.</p>';
                $html = yora_plantilla_correo('Recibimos tu solicitud, ' . $nombre, $cuerpo);
                yora_enviar_correo($correo, $nombre, 'Recibimos tu solicitud | Yora Delivery', $html);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Afilia tu comercio | Yora Delivery</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora: #e4441b; --bg: #f8fafc; --card: #ffffff; --text: #1e293b; --gray: #64748b; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--bg); color: var(--text); padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; background: var(--card); padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border-top: 5px solid var(--yora); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { font-weight: 800; font-size: 2rem; }
        .header h1 span { color: var(--yora); }
        .header p { color: var(--gray); font-size: 0.95rem; margin-top: 5px; }
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: 600; text-align: center; }
        .alert.exito { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .section-title { font-size: 1.1rem; font-weight: 700; color: var(--yora); margin: 30px 0 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media(max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: 1 / -1; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; color: #475569; }
        input, select, textarea { width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9rem; background: #f8fafc; outline: none; }
        input:focus, select:focus, textarea:focus { border-color: var(--yora); background: #fff; }
        input[type="file"] { padding: 10px; font-size: 0.8rem; background: #f1f5f9; border: 1px dashed #cbd5e1; }
        .btn-submit { background: var(--yora); color: white; width: 100%; padding: 16px; border: none; border-radius: 12px; font-weight: 700; font-size: 1.1rem; cursor: pointer; margin-top: 20px; }
        .back { display:block; text-align:center; margin-top:16px; color:var(--gray); font-weight:600; text-decoration:none; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Yora<span>Comercios</span></h1>
        <p>Afilia tu local a la red logística de Barquisimeto.</p>
    </div>
    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo yora_h($tipo_mensaje); ?>"><?php echo yora_h($mensaje); ?></div>
    <?php endif; ?>
    <?php if ($tipo_mensaje !== 'exito'): ?>
    <form method="POST" enctype="multipart/form-data">
        <?php echo yora_csrf_field(); ?>
        <div class="section-title">🏪 Datos del local</div>
        <div class="grid-2">
            <div class="form-group"><label>Nombre del local</label><input type="text" name="nombre" required></div>
            <div class="form-group"><label>RIF</label><input type="text" name="rif" placeholder="J-12345678-9"></div>
            <div class="form-group"><label>Razón social</label><input type="text" name="razon_social"></div>
            <div class="form-group"><label>Categoría</label>
                <select name="categoria">
                    <option>Restaurante</option>
                    <option>Hamburguesas</option>
                    <option>Pizzería</option>
                    <option>Farmacia</option>
                    <option>Bodegón</option>
                    <option>Café</option>
                    <option>Postres</option>
                    <option>Otro</option>
                </select>
            </div>
            <div class="form-group full"><label>Dirección</label><input type="text" name="direccion" required></div>
            <div class="form-group"><label>WhatsApp del local</label><input type="text" name="telefono" placeholder="04141234567" required></div>
            <div class="form-group"><label>Correo (ahí te llega la clave cuando te aprueben)</label><input type="email" name="correo" required></div>
            <div class="form-group full"><label>Horario</label><input type="text" name="horario" placeholder="5:00 PM a 11:00 PM"></div>
            <div class="form-group full"><label>Logo (opcional)</label><input type="file" name="logo" accept="image/*"></div>
        </div>
        <p style="color:#64748b; font-size:0.85rem; margin-top:8px;">El equipo de Yora revisa tu local. Si te aprueban, te enviamos la clave por correo y WhatsApp. No elijas contraseña ahora.</p>
        <button type="submit" class="btn-submit">Enviar solicitud de afiliación</button>
    </form>
    <?php endif; ?>
    <a class="back" href="/">← Volver a Yora</a>
</div>
</body>
</html>
