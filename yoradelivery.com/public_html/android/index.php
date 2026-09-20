<?php
$apkPath = __DIR__ . '/yora-driver.apk';
$apkListo = is_file($apkPath) && filesize($apkPath) > 0;
$apkHref = '/android/yora-driver.apk';
$waSoporte = '584225097031';
$waTxt = '+58 422-5097031';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargar Yora Driver | Android</title>
    <meta name="description" content="Descarga la app de Yora Driver para Android e instálala aunque aún no esté en Google Play.">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora: #e4441b; --bg: #f8fafc; --text: #1e293b; --gray: #64748b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 20px 80px; }
        .brand { text-align: center; margin-bottom: 28px; }
        .brand h1 { font-size: 2rem; font-weight: 800; }
        .brand h1 span { color: var(--yora); }
        .brand p { color: var(--gray); margin-top: 6px; }
        .card { background: #fff; border-radius: 20px; padding: 28px 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); border-top: 5px solid var(--yora); margin-bottom: 18px; }
        h2 { font-size: 1.15rem; color: var(--yora); margin-bottom: 12px; }
        p, li { font-size: 0.95rem; line-height: 1.55; color: #334155; }
        .aviso { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 14px 16px; font-size: 0.9rem; margin-bottom: 18px; }
        ol { padding-left: 22px; display: flex; flex-direction: column; gap: 12px; }
        li strong { color: var(--text); }
        .paso-extra { font-size: 0.85rem; color: var(--gray); display: block; margin-top: 4px; }
        .download { display: block; text-align: center; background: var(--yora); color: #fff; text-decoration: none; font-weight: 800; font-size: 1.15rem; padding: 18px 20px; border-radius: 14px; box-shadow: 0 8px 20px rgba(228,68,27,0.28); margin-top: 8px; }
        .download:hover { background: #c23310; }
        .download.disabled { pointer-events: none; opacity: 0.55; }
        .hint { text-align: center; font-size: 0.8rem; color: var(--gray); margin-top: 10px; }
        .wa { display: block; text-align: center; margin-top: 18px; color: #15803d; font-weight: 700; text-decoration: none; }
        @media (max-width: 600px) {
            .wrap { padding: 20px 14px 64px; }
            .card { padding: 22px 16px; }
            .brand h1 { font-size: 1.65rem; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <h1>Yora<span>Driver</span></h1>
        <p>Descarga oficial para Android · Barquisimeto</p>
    </div>

    <div class="aviso">
        La app todavía no está en Google Play. Al instalar el APK, Android puede mostrar avisos de seguridad. Eso es normal: el archivo sale de nuestro sitio, no de una tienda. Sigue los pasos y podrás usarla.
    </div>

    <div class="card">
        <h2>1. Qué va a pasar en tu teléfono</h2>
        <p>Chrome, Archivos o Play Protect pueden decir que el archivo es «peligroso» o que «no se permiten apps desconocidas». No es un virus: Android bloquea cualquier app que no venga de Play Store hasta que tú la autorices.</p>
    </div>

    <div class="card">
        <h2>2. Cómo permitir la instalación</h2>
        <ol>
            <li>
                <strong>Descarga el APK</strong> con el botón naranja del final de esta página.
                <span class="paso-extra">Si Chrome dice que el archivo puede ser dañino, pulsa <b>Descargar de todos modos</b> o <b>Keep anyway</b>.</span>
            </li>
            <li>
                <strong>Ábrelo</strong> desde la barra de descargas o la carpeta Descargas.
            </li>
            <li>
                <strong>Si aparece «Por seguridad, no se permite instalar aplicaciones desconocidas»</strong>, pulsa <b>Ajustes</b> y activa <b>Permitir desde esta fuente</b> (Chrome, Archivos o el navegador que usaste).
                <span class="paso-extra">En algunos Xiaomi / Samsung: Ajustes → Seguridad → Instalar apps desconocidas → elige Chrome y actívalo.</span>
            </li>
            <li>
                <strong>Vuelve atrás y pulsa Instalar.</strong>
            </li>
            <li>
                <strong>Si Google Play Protect avisa</strong>, pulsa <b>Más información</b> y luego <b>Instalar de todos modos</b>.
            </li>
            <li>
                <strong>Abre Yora Driver</strong>, entra con tu teléfono y la clave que te enviamos por correo y WhatsApp.
            </li>
        </ol>
    </div>

    <div class="card">
        <h2>3. Descargar la App</h2>
        <p>Usa solo este enlace oficial. No instales Yora desde otros sitios.</p>
        <?php if ($apkListo): ?>
            <a class="download" href="<?php echo htmlspecialchars($apkHref, ENT_QUOTES, 'UTF-8'); ?>" download="YoraDriver.apk">Descargar App</a>
            <p class="hint">Archivo APK de Yora Driver · Android</p>
        <?php else: ?>
            <a class="download" href="<?php echo htmlspecialchars($apkHref, ENT_QUOTES, 'UTF-8'); ?>" download="YoraDriver.apk">Descargar App</a>
            <p class="hint">Coloca el archivo <b>yora-driver.apk</b> en la carpeta <code>android/</code> para que la descarga quede activa.</p>
        <?php endif; ?>
        <a class="wa" href="https://wa.me/<?php echo $waSoporte; ?>">¿Necesitas ayuda? WhatsApp <?php echo $waTxt; ?></a>
    </div>
</div>
</body>
</html>
