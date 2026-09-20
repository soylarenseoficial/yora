<?php
/**
 * =====================================================================
 *  INTERFAZ COMUN DE LAS PANTALLAS DE AJUSTES - YORA DRIVER
 * =====================================================================
 *  Vive fuera de public_html: no es una pagina, solo define el marco
 *  visual que comparten ajustes.php y sus subpantallas para que todas
 *  se vean igual (barra superior, tarjetas, filas, avisos y toasts).
 * =====================================================================
 */

if (!defined('YORA_SECURITY_LOADED')) {
    http_response_code(403);
    exit('Acceso no permitido.');
}

/**
 * Puerta de entrada de todas las pantallas de ajustes: exige sesion viva
 * (una sola por conductor) y devuelve su ficha completa.
 */
function yora_ajustes_conductor(mysqli $db): array
{
    if (empty($_SESSION['conductor_id'])) {
        header('Location: index.php');
        exit;
    }
    $id = (int) $_SESSION['conductor_id'];
    yora_mant_exigir($db, 'drivers', $id);
    if (!yora_conductor_sesion_vigente($db, $id)) {
        yora_logout('index.php?sesion=duplicada');
    }
    yora_timezone($db);
    $fila = yora_one($db, 'SELECT * FROM conductores WHERE id = ?', 'i', $id);
    if (!$fila) {
        yora_logout('index.php');
    }
    return $fila;
}

/**
 * Abre la pagina: cabecera HTML, estilos y barra superior.
 *
 * $volver  URL de la flecha de retroceso ('' oculta la flecha).
 * $nav     true para pintar el menu inferior de la app (solo en el hub).
 */
function yora_ajustes_inicio(string $titulo, string $volver = 'ajustes.php', bool $nav = false): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#ffffff">
    <title><?php echo yora_h($titulo); ?> | YoraDriver</title>
    <link rel="manifest" href="/manifest.json?v=8">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; font-family: 'Poppins', sans-serif; }
        body { background: #f6f7f8; color: #111827; min-height: 100vh; padding-bottom: <?php echo $nav ? '110px' : '40px'; ?>; }

        .ax-top { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 1px solid #eee;
                  display: flex; align-items: center; gap: 12px; padding: 14px 16px; }
        .ax-top a.ax-back { color: #111827; font-size: 1.5rem; line-height: 1; display: flex; text-decoration: none; }
        .ax-top h1 { font-size: 1.12rem; font-weight: 800; letter-spacing: -0.2px; }

        .ax-wrap { max-width: 520px; margin: 0 auto; padding: 14px 14px 0; }
        .ax-card { background: #fff; border: 1px solid #ececec; border-radius: 18px; padding: 16px; margin-bottom: 14px; }
        .ax-card.tight { padding: 6px 16px; }
        .ax-card h2 { font-size: 0.72rem; font-weight: 800; color: #9ca3af; text-transform: uppercase;
                      letter-spacing: 0.6px; margin: 4px 0 6px; }

        .ax-group { background: #fff; border: 1px solid #ececec; border-radius: 18px; overflow: hidden; margin-bottom: 14px; }
        .ax-label { font-size: 0.72rem; font-weight: 800; color: #9ca3af; text-transform: uppercase;
                    letter-spacing: 0.6px; margin: 18px 6px 8px; }

        .ax-row { display: flex; align-items: center; gap: 13px; padding: 15px 16px; text-decoration: none;
                  color: #111827; background: #fff; border-bottom: 1px solid #f3f4f6; width: 100%;
                  border-left: 0; border-right: 0; border-top: 0; cursor: pointer; text-align: left; font-size: 1rem; }
        .ax-row:last-child { border-bottom: none; }
        .ax-row:active { background: #fafafa; }
        .ax-row i.ax-ico { font-size: 1.35rem; color: #3f3f46; flex-shrink: 0; }
        .ax-row .ax-txt { flex: 1; min-width: 0; }
        .ax-row .ax-txt strong { display: block; font-size: 0.94rem; font-weight: 600; line-height: 1.25; }
        .ax-row .ax-txt span { display: block; font-size: 0.75rem; color: #9ca3af; margin-top: 2px; line-height: 1.3; }
        .ax-row .ax-end { font-size: 0.8rem; font-weight: 700; color: #e4441b; white-space: nowrap; }
        .ax-row i.ax-arrow { font-size: 1.1rem; color: #c4c4c8; flex-shrink: 0; }
        .ax-row.danger i.ax-ico, .ax-row.danger .ax-txt strong { color: #dc2626; }

        .ax-chip { display: inline-flex; align-items: center; gap: 5px; font-size: 0.7rem; font-weight: 800;
                   padding: 4px 10px; border-radius: 999px; white-space: nowrap; }

        .ax-hero { background: linear-gradient(180deg, #ffe9e0 0%, #f6f7f8 100%); padding: 22px 16px 8px; text-align: center; }
        .ax-hero .ax-avatar { position: relative; display: inline-block; }
        .ax-hero img { width: 104px; height: 104px; border-radius: 50%; object-fit: cover; border: 4px solid #e4441b; background: #fff; }
        .ax-hero .ax-pencil { position: absolute; bottom: 2px; right: 2px; width: 34px; height: 34px; border-radius: 50%;
                              background: #e4441b; color: #fff; border: 3px solid #fff; display: flex; align-items: center;
                              justify-content: center; font-size: 1rem; cursor: pointer; }
        .ax-hero h2 { font-size: 1.35rem; font-weight: 800; margin-top: 12px; }
        .ax-hero p { font-size: 0.84rem; color: #6b7280; margin-top: 3px; }

        .ax-field { margin-bottom: 14px; }
        .ax-field label { display: block; font-size: 0.75rem; font-weight: 700; color: #6b7280; margin-bottom: 6px; }
        .ax-field input, .ax-field select { width: 100%; padding: 13px 14px; border: 1px solid #e4e4e7; border-radius: 13px;
                                            font-size: 0.95rem; background: #fafafa; outline: none; color: #111827; }
        .ax-field input:focus, .ax-field select:focus { border-color: #e4441b; background: #fff; }
        .ax-field input:disabled { color: #6b7280; background: #f4f4f5; }
        .ax-field small { display: block; font-size: 0.72rem; color: #9ca3af; margin-top: 6px; line-height: 1.35; }

        .ax-btn { width: 100%; border: none; padding: 15px; border-radius: 14px; font-weight: 800; font-size: 0.95rem;
                  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
                  font-family: 'Poppins', sans-serif; }
        .ax-btn:active { transform: scale(0.99); }
        .ax-btn[disabled] { opacity: 0.5; }
        .ax-btn-primary { background: #e4441b; color: #fff; }
        .ax-btn-dark { background: #111827; color: #fff; }
        .ax-btn-light { background: #fff; color: #52525b; border: 1px solid #e4e4e7; }

        .ax-note { border-radius: 14px; padding: 13px 15px; font-size: 0.82rem; font-weight: 600; line-height: 1.45; margin-bottom: 14px; }
        .ax-note.warn { background: #fef3c7; color: #92400e; }
        .ax-note.ok { background: #dcfce7; color: #166534; }
        .ax-note.bad { background: #fee2e2; color: #b91c1c; }
        .ax-note.info { background: #eff6ff; color: #1d4ed8; }

        .ax-bar { height: 10px; border-radius: 999px; background: #eceef1; overflow: hidden; }
        .ax-bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #f97316, #e4441b); }

        .ax-nav { background: #fff; display: flex; justify-content: space-around; padding: 10px 4px 16px;
                  position: fixed; bottom: 0; left: 0; width: 100%; border-top: 1px solid #eee; z-index: 100; }
        .ax-nav a { text-align: center; color: #9ca3af; font-size: 0.65rem; font-weight: 700; flex: 1;
                    text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .ax-nav a i { font-size: 1.5rem; }
        .ax-nav a.active { color: #e4441b; }

        #ax-toast { position: fixed; left: 50%; bottom: 92px; transform: translate(-50%, 20px); z-index: 9999;
                    background: #111827; color: #fff; font-size: 0.85rem; font-weight: 600; padding: 13px 18px;
                    border-radius: 14px; max-width: 88%; text-align: center; opacity: 0; pointer-events: none;
                    transition: opacity 0.25s, transform 0.25s; }
        #ax-toast.show { opacity: 1; transform: translate(-50%, 0); }
        #ax-toast.ok { background: #15803d; }
        #ax-toast.bad { background: #b91c1c; }

        #ax-load { position: fixed; inset: 0; background: rgba(15,23,42,0.88); z-index: 9998; display: none;
                   flex-direction: column; align-items: center; justify-content: center; color: #fff; }
        #ax-load i { font-size: 3rem; color: #e4441b; animation: ax-spin 1s linear infinite; }
        #ax-load p { margin-top: 14px; font-weight: 700; font-size: 1rem; }
        #ax-load small { color: #94a3b8; font-size: 0.78rem; margin-top: 4px; }
        @keyframes ax-spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <header class="ax-top">
        <?php if ($volver !== ''): ?>
        <a class="ax-back" href="<?php echo yora_h($volver); ?>" aria-label="Volver"><i class="ph ph-arrow-left"></i></a>
        <?php endif; ?>
        <h1><?php echo yora_h($titulo); ?></h1>
    </header>
    <?php
}

/** Cierra la pagina: menu inferior opcional, toast, overlay y utilidades JS. */
function yora_ajustes_fin(bool $nav = false, string $activo = 'ajustes'): void
{
    $on = static function (string $clave) use ($activo): string {
        return $clave === $activo ? ' class="active"' : '';
    };
    ?>
    <div id="ax-toast"></div>
    <div id="ax-load"><i class="ph ph-spinner-gap"></i><p>Subiendo...</p><small>No cierres la app</small></div>

    <?php if ($nav): ?>
    <nav class="ax-nav">
        <a href="dashboard.php?tab=anuncios"<?php echo $on('radar'); ?>><i class="ph ph-broadcast"></i><span>Radar</span></a>
        <a href="dashboard.php?tab=misviajes"<?php echo $on('viajes'); ?>><i class="ph ph-map-pin-line"></i><span>Viajes</span></a>
        <a href="billetera.php"<?php echo $on('billetera'); ?>><i class="ph ph-wallet"></i><span>Billetera</span></a>
        <a href="ajustes.php"<?php echo $on('ajustes'); ?>><i class="ph ph-gear"></i><span>Ajustes</span></a>
    </nav>
    <?php endif; ?>

    <script>
        function axToast(mensaje, tipo) {
            const t = document.getElementById('ax-toast');
            t.textContent = mensaje;
            t.className = 'show ' + (tipo || '');
            clearTimeout(window.__axToast);
            window.__axToast = setTimeout(() => { t.className = tipo || ''; }, 3200);
        }

        function axCargando(visible, texto) {
            const c = document.getElementById('ax-load');
            if (texto) c.querySelector('p').textContent = texto;
            c.style.display = visible ? 'flex' : 'none';
        }

        /** POST a la API con la sesion de la app. Devuelve el JSON o lanza. */
        async function axEnviar(url, datos) {
            const res = await fetch(url, { method: 'POST', body: datos });
            if (res.status === 401) {
                window.location.href = 'index.php?sesion=duplicada';
                throw new Error('sesion');
            }
            const json = await res.json();
            if (json.status !== 'success') {
                throw new Error(json.mensaje || 'No se pudo completar la operación.');
            }
            return json;
        }

        /**
         * El servidor exige que la extension del nombre case con el tipo real
         * del archivo, asi que el nombre se arma a partir del tipo.
         */
        function axNombre(archivo, base) {
            const tipos = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'application/pdf': 'pdf' };
            return base + '.' + (tipos[archivo && archivo.type] || 'jpg');
        }

        /**
         * Reduce la foto antes de subirla: los telefonos sacan imagenes de 6 MB
         * y la subida se cae con datos moviles lentos.
         */
        function axComprimir(file) {
            return new Promise((resolve) => {
                if (!file || file.type === 'application/pdf' || !/^image\//.test(file.type || '')) {
                    resolve(file);
                    return;
                }
                const reader = new FileReader();
                reader.onerror = () => resolve(file);
                reader.onload = (ev) => {
                    const img = new Image();
                    img.onerror = () => resolve(file);
                    img.onload = () => {
                        const w = img.width || 0, h = img.height || 0;
                        if (w < 8 || h < 8) { resolve(file); return; }
                        const escala = Math.min(1, 1600 / Math.max(w, h));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.max(1, Math.round(w * escala));
                        canvas.height = Math.max(1, Math.round(h * escala));
                        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                        canvas.toBlob((blob) => {
                            // Un HEIC de iPhone no lo acepta el servidor: en ese caso
                            // nos quedamos con el JPEG del canvas aunque pese mas.
                            const soportado = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
                            const sirve = blob && blob.size > 0 && (!soportado || blob.size < file.size);
                            resolve(sirve ? blob : file);
                        }, 'image/jpeg', 0.78);
                    };
                    img.src = ev.target.result;
                };
                reader.readAsDataURL(file);
            });
        }
    </script>
</body>
</html>
    <?php
}

/**
 * Fila de menu con icono, texto, valor a la derecha y flecha.
 * $f: ['href'|'onclick', 'icono', 'titulo', 'texto', 'valor', 'chip', 'clase', 'flecha']
 */
function yora_ajustes_fila(array $f): void
{
    $etiqueta = '<i class="ph ' . yora_h($f['icono'] ?? 'ph-circle') . ' ax-ico"></i>'
        . '<span class="ax-txt"><strong>' . yora_h($f['titulo'] ?? '') . '</strong>'
        . (!empty($f['texto']) ? '<span>' . yora_h($f['texto']) . '</span>' : '')
        . '</span>';

    if (!empty($f['chip']) && is_array($f['chip'])) {
        $estilo = $f['chip'];
        $etiqueta .= '<span class="ax-chip" style="background:' . yora_h($estilo['fondo']) . '; color:' . yora_h($estilo['color']) . ';">'
            . '<i class="ph ' . yora_h($estilo['icono']) . '"></i>' . yora_h($estilo['texto']) . '</span>';
    } elseif (!empty($f['valor'])) {
        $etiqueta .= '<span class="ax-end">' . yora_h($f['valor']) . '</span>';
    }

    if (($f['flecha'] ?? true) !== false) {
        $etiqueta .= '<i class="ph ' . (empty($f['externo']) ? 'ph-caret-right' : 'ph-arrow-up-right') . ' ax-arrow"></i>';
    }

    $clase = 'ax-row' . (!empty($f['clase']) ? ' ' . $f['clase'] : '');

    if (!empty($f['href'])) {
        $externo = !empty($f['externo']) ? ' target="_blank" rel="noopener"' : '';
        echo '<a class="' . $clase . '" href="' . yora_h($f['href']) . '"' . $externo . '>' . $etiqueta . '</a>';
        return;
    }
    echo '<button type="button" class="' . $clase . '" onclick="' . yora_h($f['onclick'] ?? '') . '">' . $etiqueta . '</button>';
}
