<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
$docs = yora_cliente_docs_estado($user);
$csrf = yora_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Verificar identidad | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=4">
</head>
<body>
<div class="ax-top"><a href="ajustes.php"><i class="ph ph-arrow-left"></i></a><h1>Verificar identidad</h1></div>
<div class="ax-wrap">
    <div class="ax-card">
        <strong><?php echo $docs['verificado'] ? 'Cuenta verificada' : 'Cuenta sin verificar'; ?></strong>
        <div style="font-size:.78rem;color:#64748b;margin:4px 0 10px;"><?php echo (int)$docs['cargados']; ?> de <?php echo (int)$docs['total']; ?> documentos</div>
        <div class="ax-bar"><span style="width:<?php echo (int)$docs['porcentaje']; ?>%;"></span></div>
    </div>
    <?php if ($docs['verificado']): ?>
        <div class="ax-note ok">Tu selfie y tu cédula ya fueron aprobadas.</div>
    <?php elseif ($docs['global'] === 'Rechazado'): ?>
        <div class="ax-note bad"><?php echo yora_h($docs['motivo'] ?: 'Corrige las fotos y envía otra vez.'); ?></div>
    <?php elseif ($docs['global'] === 'En Revisión'): ?>
        <div class="ax-note info">Expediente enviado. Te avisamos por correo y WhatsApp.</div>
    <?php elseif ($docs['puede_enviar']): ?>
        <div class="ax-note ok">Ya están las dos fotos. Pulsa <b>Enviar a verificación</b>.</div>
    <?php else: ?>
        <div class="ax-note warn">Sube una selfie de frente y la foto de tu cédula.</div>
    <?php endif; ?>

    <?php foreach ($docs['items'] as $campo => $doc): ?>
    <div class="ax-card">
        <div style="display:flex;gap:12px;align-items:flex-start;">
            <div style="width:44px;height:44px;border-radius:13px;background:<?php echo yora_h($doc['estilo']['fondo']); ?>;display:flex;align-items:center;justify-content:center;">
                <i class="ph <?php echo yora_h($doc['icono']); ?>" style="color:<?php echo yora_h($doc['estilo']['color']); ?>;font-size:1.35rem;"></i>
            </div>
            <div style="flex:1;">
                <strong><?php echo yora_h($doc['etiqueta']); ?></strong>
                <div style="font-size:.75rem;color:#94a3b8;margin-top:3px;"><?php echo yora_h($doc['ayuda']); ?></div>
                <span class="ax-chip" id="chip-<?php echo yora_h($campo); ?>" style="margin-top:8px;display:inline-block;background:<?php echo yora_h($doc['estilo']['fondo']); ?>;color:<?php echo yora_h($doc['estilo']['color']); ?>;"><?php echo yora_h($doc['estilo']['texto']); ?></span>
            </div>
            <img id="img-<?php echo yora_h($campo); ?>" src="<?php echo yora_h($doc['url']); ?>" alt="" style="width:52px;height:52px;border-radius:11px;object-fit:cover;background:#f1f5f9;<?php echo $doc['url'] ? '' : 'display:none;'; ?>">
        </div>
        <button type="button" class="btn" style="margin-top:12px;" <?php echo $doc['estado']==='En Revisión' ? 'disabled' : ''; ?> onclick="elegir('<?php echo yora_h($campo); ?>')">
            <?php echo $doc['cargado'] ? 'Cambiar foto' : 'Subir foto'; ?>
        </button>
    </div>
    <?php endforeach; ?>
    <input type="file" id="archivo" accept="image/*" capture="environment" style="display:none;" onchange="subir(this)">
    <button type="button" class="btn" id="btn-enviar" onclick="enviar()" <?php echo $docs['puede_enviar'] ? '' : 'disabled'; ?>>Enviar a verificación</button>
</div>
<script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    let campoActivo = '';
    function elegir(c){ campoActivo=c; document.getElementById('archivo').click(); }
    async function subir(inp){
        if (!inp.files || !inp.files[0] || !campoActivo) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('tipo', campoActivo);
        fd.append('documento', inp.files[0]);
        const r = await fetch('/api/subir_documento_cliente.php', {method:'POST', body:fd});
        const d = await r.json();
        alert(d.mensaje || 'Listo');
        if (d.status==='success') location.reload();
        inp.value='';
    }
    async function enviar(){
        const fd = new FormData(); fd.append('_csrf', CSRF);
        const r = await fetch('/api/enviar_verificacion.php', {method:'POST', body:fd});
        const d = await r.json();
        alert(d.mensaje || 'Listo');
        if (d.status==='success') location.reload();
    }
</script>
</body>
</html>
