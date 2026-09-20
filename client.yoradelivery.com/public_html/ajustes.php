<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
if (!$user) { yora_logout('index.php'); }
$docs = yora_cliente_docs_estado($user);
$foto = yora_url_archivo($user['foto_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png');
$pedidos = yora_one($conexion, 'SELECT COUNT(id) AS n FROM comandas WHERE usuario_id = ?', 'i', $uid);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Ajustes | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=4">
</head>
<body>
<div class="ax-hero">
    <div style="position:relative;display:inline-block;">
        <img id="ax-foto" src="<?php echo yora_h($foto); ?>" alt="">
        <button type="button" onclick="document.getElementById('ax-input-foto').click()" style="position:absolute;right:0;bottom:4px;width:32px;height:32px;border:0;border-radius:50%;background:#e4441b;color:#fff;cursor:pointer;"><i class="ph ph-pencil-simple"></i></button>
    </div>
    <h2><?php echo yora_h($user['nombre']); ?></h2>
    <p><?php echo yora_h($user['telefono']); ?><?php echo !empty($user['cedula']) ? ' · ' . yora_h($user['cedula']) : ''; ?></p>
    <p style="margin-top:6px;font-weight:800;color:#e4441b;">Billetera $<?php echo number_format((float)($user['billetera'] ?? 0), 2); ?></p>
</div>
<input type="file" id="ax-input-foto" accept="image/*" style="display:none;" onchange="cambiarFoto(this)">
<div class="ax-wrap">
    <a href="ajustes_documentos.php" style="text-decoration:none;color:inherit;">
        <div class="ax-card" style="border-color:<?php echo yora_h($docs['estilo']['color']); ?>33;">
            <div style="display:flex;align-items:center;gap:12px;">
                <i class="ph <?php echo yora_h($docs['estilo']['icono']); ?>" style="font-size:1.8rem;color:<?php echo yora_h($docs['estilo']['color']); ?>;"></i>
                <div style="flex:1;">
                    <strong>Verificación de identidad</strong>
                    <div style="font-size:.78rem;color:#64748b;margin-top:2px;"><?php echo (int)$docs['cargados']; ?> de <?php echo (int)$docs['total']; ?> documentos</div>
                </div>
                <span class="ax-chip" style="background:<?php echo yora_h($docs['estilo']['fondo']); ?>;color:<?php echo yora_h($docs['estilo']['color']); ?>;"><?php echo yora_h($docs['estilo']['texto']); ?></span>
            </div>
            <?php if (!$docs['verificado']): ?>
            <div class="ax-bar" style="margin-top:12px;"><span style="width:<?php echo (int)$docs['porcentaje']; ?>%;"></span></div>
            <?php endif; ?>
        </div>
    </a>
    <?php if ($docs['verificado']): ?>
        <div class="ax-note ok"><i class="ph ph-check-circle"></i> Tu cuenta está verificada. Ya puedes pedir mandaditos con normalidad.</div>
    <?php elseif ($docs['global'] === 'Rechazado'): ?>
        <div class="ax-note bad">Hay que corregir tu verificación.<?php echo $docs['motivo'] !== '' ? ' Motivo: ' . yora_h($docs['motivo']) : ''; ?></div>
    <?php elseif ($docs['global'] === 'En Revisión'): ?>
        <div class="ax-note info">Tus documentos están en revisión. Te avisamos por correo y WhatsApp.</div>
    <?php else: ?>
        <div class="ax-note warn">Para pedir mandaditos debes verificar tu identidad: selfie y foto de tu cédula.</div>
    <?php endif; ?>

    <div class="ax-group">
        <a class="ax-row" href="ajustes_perfil.php"><span class="ax-ico"><i class="ph ph-user"></i></span><span style="flex:1;"><b>Detalles del perfil</b><br><span style="font-size:.75rem;color:#94a3b8;">Nombre, correo, cédula y dirección</span></span><i class="ph ph-caret-right" style="color:#cbd5e1;"></i></a>
        <a class="ax-row" href="billetera.php"><span class="ax-ico"><i class="ph ph-wallet"></i></span><span style="flex:1;"><b>Mi billetera</b><br><span style="font-size:.75rem;color:#94a3b8;">Recarga y paga tus mandaditos</span></span><i class="ph ph-caret-right" style="color:#cbd5e1;"></i></a>
        <a class="ax-row" href="ajustes_documentos.php"><span class="ax-ico"><i class="ph ph-identification-card"></i></span><span style="flex:1;"><b>Verificar identidad</b><br><span style="font-size:.75rem;color:#94a3b8;">Selfie y cédula</span></span><i class="ph ph-caret-right" style="color:#cbd5e1;"></i></a>
        <a class="ax-row" href="ajustes_seguridad.php"><span class="ax-ico"><i class="ph ph-lock-key"></i></span><span style="flex:1;"><b>Seguridad</b><br><span style="font-size:.75rem;color:#94a3b8;">Cambiar contraseña</span></span><i class="ph ph-caret-right" style="color:#cbd5e1;"></i></a>
    </div>
    <p class="hint" style="text-align:center;">Has pedido <?php echo (int)($pedidos['n'] ?? 0); ?> mandadito(s).</p>
    <a class="link" href="logout.php"><b>Cerrar sesión</b></a>
</div>
<nav class="nav">
    <a href="dashboard.php"><i class="ph ph-map-pin"></i>Pedir</a>
    <a href="pedidos.php"><i class="ph ph-clock-counter-clockwise"></i>Pedidos</a>
    <a href="billetera.php"><i class="ph ph-wallet"></i>Billetera</a>
    <a class="on" href="ajustes.php"><i class="ph ph-gear"></i>Ajustes</a>
</nav>
<script>
    const CSRF = <?php echo json_encode(yora_csrf_token()); ?>;
    async function cambiarFoto(input){
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('foto', input.files[0]);
        const r = await fetch('/api/subir_foto_cliente.php', {method:'POST', body:fd});
        const d = await r.json();
        alert(d.mensaje || 'Listo');
        if (d.status==='success' && d.url) document.getElementById('ax-foto').src = d.url;
        input.value = '';
    }
</script>
</body>
</html>
