<?php

// Misma conexion que el resto del panel. Cerrar $conexion aqui deja el
// sidebar sin base de datos y PHP 8 mata la pagina en blanco.
require_once 'conexion.php';
yora_require_admin_pagina('ajustes.php');

$config = [];
try {
    $res = $conexion->query('SELECT * FROM configuracion_web LIMIT 1');
    $config = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : [];
} catch (Throwable $e) {
    error_log('ajustes: ' . $e->getMessage());
}

// Valores por defecto para que la pantalla abra aunque la tabla este vacia.
$config += [
    'modo_mantenimiento'    => 0,
    'titulo_mantenimiento'  => '',
    'mensaje_mantenimiento' => '',
    'color_fondo'           => '#0f172a',
    'nav_link1'             => '',
    'nav_link2'             => '',
    'nav_btn'               => '',
    'hero_titulo'           => '',
    'hero_texto'            => '',
    'hero_btn'              => '',
    'prefooter_titulo'      => '',
    'prefooter_texto'       => '',
    'prefooter_btn1'        => '',
    'prefooter_btn2'        => '',
    'footer_about'          => '',
    'play_store_url'        => '',
    'app_store_url'         => '',
];

// El input de color solo acepta #rrggbb; si en la base hay otra cosa, el
// navegador lo deja en negro y parece que se perdio la configuracion.
$color_fondo = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $config['color_fondo'])
    ? $config['color_fondo']
    : '#0f172a';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Panel de Control</title>
    <!-- FUENTE POPPINS -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --yora-orange: #ce4e2d;
            --yora-orange-hover: #e65a36;
            --bg-body: #f4f6f8;       /* Fondo gris extra claro para dar profundidad */
            --bg-card: #ffffff;       /* Tarjetas blanco puro */
            --border-color: #e5e7eb;  /* Bordes gris muy suave */
            --text-main: #1f2937;     /* Texto principal oscuro */
            --text-muted: #6b7280;    /* Texto secundario gris medio */
        }

        body {
            margin: 0; font-family: 'Poppins', sans-serif;
            background-color: var(--bg-body); color: var(--text-main);
            display: flex; height: 100vh; overflow: hidden;
        }

        /* BARRA LATERAL TIPO APP (CLARA) */
        .sidebar {
            width: 280px; background-color: var(--bg-card); padding: 25px 20px;
            border-right: 1px solid var(--border-color); display: flex; flex-direction: column;
            box-sizing: border-box; box-shadow: 2px 0 10px rgba(0,0,0,0.02);
        }
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(206,78,45,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }

        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        
        .menu-item {
            padding: 12px 15px; margin-bottom: 6px; border-radius: 10px;
            cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem;
            color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px;
        }
        .menu-item .icon { font-size: 1.1rem; }
        
        /* Efecto Hover Suave */
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        
        /* Item Activo (Fondo naranja muy claro con texto naranja) */
        .menu-item.active { background-color: rgba(206,78,45,0.1); color: var(--yora-orange); font-weight: 600; }
        
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }
        .logout-btn:hover { background-color: #fee2e2; color: #ef4444; }

        /* CONTENIDO PRINCIPAL */
        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 1.8rem; margin-bottom: 30px; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        
        .grid-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start; }
        
        /* TARJETAS FLOTANTES BLANCAS */
        .card {
            background-color: var(--bg-card); padding: 30px; border-radius: 16px;
            border: 1px solid var(--border-color); 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            transition: box-shadow 0.3s;
        }
        .card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08); }
        
        /* Títulos de tarjeta con detalle naranja */
        .card h3 { margin-top: 0; color: var(--text-main); font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; font-weight: 600; display:flex; align-items:center; gap: 8px;}
        .card h3::before { content: ""; display: block; width: 4px; height: 16px; background: var(--yora-orange); border-radius: 4px; }

        /* FORMULARIOS ESTILO STRIPE/SHOPIFY */
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.85rem; color: var(--text-main); font-weight: 500; }
        
        input[type="text"], textarea, input[type="number"], input[type="password"], input[type="tel"] {
            width: 100%; padding: 12px 16px; background-color: #ffffff; border: 1px solid #d1d5db;
            color: var(--text-main); border-radius: 10px; box-sizing: border-box; font-family: 'Poppins', sans-serif; font-size: 0.9rem; transition: all 0.3s;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.02);
        }
        
        input[type="text"]:focus, textarea:focus, input[type="number"]:focus, input[type="password"]:focus, input[type="tel"]:focus { 
            outline: none; border-color: var(--yora-orange); box-shadow: 0 0 0 3px rgba(206,78,45,0.15); 
        }
        
        input[type="file"] { font-size: 0.85rem; color: var(--text-muted); background: #f9fafb; padding: 10px; border-radius: 10px; border: 1px dashed #d1d5db; width: 100%; box-sizing: border-box; cursor: pointer; }

        /* SWITCH ESTILO IOS */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e5e7eb; transition: .3s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: var(--yora-orange); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* BOTÓN DE ACCIÓN PRINCIPAL */
        .btn-save-container { margin-top: 30px; text-align: right; }
        .btn-save {
            background-color: var(--yora-orange); color: white; border: none;
            padding: 12px 30px; font-size: 0.95rem; border-radius: 10px; cursor: pointer;
            font-weight: 600; transition: all 0.3s; box-shadow: 0 4px 6px rgba(206, 78, 45, 0.2); font-family: 'Poppins', sans-serif;
        }
        .btn-save:hover { background-color: var(--yora-orange-hover); transform: translateY(-2px); box-shadow: 0 6px 12px rgba(206, 78, 45, 0.3); }
    </style>
</head>
<body>

    <!-- INCLUIMOS EL SIDEBAR REUTILIZABLE -->
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 class="header-title">Apariencia de la Página</h1>
        
        <div class="grid-cards">
            
            <!-- TARJETA 1: MODO MANTENIMIENTO -->
            <div class="card">
                <h3>Modo Mantenimiento</h3>
                <div class="form-group">
                    <label>Activar Mantenimiento</label>
                    <label class="switch">
                        <input type="checkbox" id="mantenimientoToggle" <?php echo ($config['modo_mantenimiento'] == 1) ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="form-group">
                    <label>Título del Mantenimiento</label>
                    <input type="text" id="tituloMant" value="<?php echo yora_h($config['titulo_mantenimiento'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Mensaje para visitantes</label>
                    <textarea id="mensajeMant" rows="3"><?php echo yora_h($config['mensaje_mantenimiento'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Color de Fondo de la Página</label>
                    <input type="color" id="colorFondo" value="<?php echo yora_h($color_fondo); ?>" style="height: 40px; border:none; background:transparent; cursor:pointer;">
                </div>
                <div class="form-group">
                    <label>Imagen Central (Mantenimiento)</label>
                    <input type="file" id="imagenMantUpload" accept="image/*">
                </div>
            </div>

            <!-- TARJETA 2: CABECERA (NAVBAR) Y LOGOS -->
            <div class="card">
                <h3>Cabecera y Logos</h3>
                <div class="form-group">
                    <label>Enlace Menú 1</label>
                    <input type="text" id="navLink1" value="<?php echo htmlspecialchars($config['nav_link1'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Enlace Menú 2</label>
                    <input type="text" id="navLink2" value="<?php echo htmlspecialchars($config['nav_link2'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Texto Botón Cabecera</label>
                    <input type="text" id="navBtn" value="<?php echo htmlspecialchars($config['nav_btn'] ?? ''); ?>">
                </div>
                <hr style="border: 0; border-top: 1px solid #333; margin: 25px 0;">
                <div class="form-group">
                    <label>Logo Principal Web</label>
                    <input type="file" id="logoUpload" accept="image/*">
                </div>
                <div class="form-group">
                    <label>Favicon (Icono navegador)</label>
                    <input type="file" id="faviconUpload" accept="image/*">
                </div>
            </div>

            <!-- TARJETA 3: HERO SECTION -->
            <div class="card">
                <h3>Sección Principal (Hero)</h3>
                <div class="form-group">
                    <label>Título principal (un renglón por línea, sin códigos HTML)</label>
                    <textarea id="heroTitulo" rows="2"><?php echo yora_h(str_ireplace(['<br />','<br/>','<br>'], "\n", (string) ($config['hero_titulo'] ?? ''))); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Texto Secundario</label>
                    <textarea id="heroTexto" rows="3"><?php echo htmlspecialchars($config['hero_texto'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Texto del Botón</label>
                    <input type="text" id="heroBtn" value="<?php echo htmlspecialchars($config['hero_btn'] ?? ''); ?>">
                </div>
            </div>

            <!-- TARJETA 4: PIE DE PÁGINA (FOOTER) -->
            <div class="card">
                <h3>Pre-Footer y Footer</h3>
                <div class="form-group">
                    <label>Título Pre-Footer (Llamado a la acción)</label>
                    <input type="text" id="prefooterTitulo" value="<?php echo htmlspecialchars($config['prefooter_titulo'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Texto Pre-Footer</label>
                    <textarea id="prefooterTexto" rows="2"><?php echo htmlspecialchars($config['prefooter_texto'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Botón 1 (Conductores)</label>
                    <input type="text" id="prefooterBtn1" value="<?php echo htmlspecialchars($config['prefooter_btn1'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Botón 2 (Comercios)</label>
                    <input type="text" id="prefooterBtn2" value="<?php echo htmlspecialchars($config['prefooter_btn2'] ?? ''); ?>">
                </div>
                <hr style="border: 0; border-top: 1px solid #333; margin: 25px 0;">
                <div class="form-group">
                    <label>Texto 'Acerca de' (pie de página)</label>
                    <textarea id="footerAbout" rows="3"><?php echo htmlspecialchars($config['footer_about'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Link Google Play (app drivers)</label>
                    <input type="text" id="playStore" value="<?php echo htmlspecialchars($config['play_store_url'] ?? ''); ?>" placeholder="https://play.google.com/store/apps/details?id=...">
                </div>
                <div class="form-group">
                    <label>Link App Store (iPhone)</label>
                    <input type="text" id="appStore" value="<?php echo htmlspecialchars($config['app_store_url'] ?? ''); ?>" placeholder="https://apps.apple.com/...">
                </div>
            </div>

        </div>

        <div class="btn-save-container">
            <button class="btn-save" onclick="guardarConfiguracion()">Guardar Todos los Cambios</button>
        </div>
    </div>

    <script>
        const CSRF_AJUSTES = <?php echo json_encode(yora_csrf_token()); ?>;

        async function guardarConfiguracion() {
            let formData = new FormData();
            formData.append('_csrf', CSRF_AJUSTES);
            
            // Datos Mantenimiento
            formData.append('mantenimiento', document.getElementById('mantenimientoToggle').checked);
            formData.append('titulo', document.getElementById('tituloMant').value);
            formData.append('mensaje', document.getElementById('mensajeMant').value);
            formData.append('color_fondo', document.getElementById('colorFondo').value);
            
            // Navbar
            formData.append('nav_link1', document.getElementById('navLink1').value);
            formData.append('nav_link2', document.getElementById('navLink2').value);
            formData.append('nav_btn', document.getElementById('navBtn').value);

            // Hero
            formData.append('hero_titulo', document.getElementById('heroTitulo').value);
            formData.append('hero_texto', document.getElementById('heroTexto').value);
            formData.append('hero_btn', document.getElementById('heroBtn').value);

            // Footer
            formData.append('prefooter_titulo', document.getElementById('prefooterTitulo').value);
            formData.append('prefooter_texto', document.getElementById('prefooterTexto').value);
            formData.append('prefooter_btn1', document.getElementById('prefooterBtn1').value);
            formData.append('prefooter_btn2', document.getElementById('prefooterBtn2').value);
            formData.append('footer_about', document.getElementById('footerAbout').value);
            formData.append('play_store_url', document.getElementById('playStore').value);
            formData.append('app_store_url', document.getElementById('appStore').value);
            
            // Imágenes
            let logoFile = document.getElementById('logoUpload').files[0];
            if (logoFile) formData.append('logo', logoFile);

            let faviconFile = document.getElementById('faviconUpload').files[0];
            if (faviconFile) formData.append('favicon', faviconFile);

            let mantFile = document.getElementById('imagenMantUpload').files[0];
            if (mantFile) formData.append('imagen_mant', mantFile);

            try {
                let response = await fetch('../api/guardar_config.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-TOKEN': CSRF_AJUSTES }
                });
                let result = await response.json();
                
                if (result.status === 'success') {
                    alert('¡Guardado exitoso! La web ha sido actualizada.');
                    location.reload(); 
                } else {
                    alert('Error: ' + result.mensaje);
                }
            } catch (error) {
                alert('Ocurrió un error al conectar con la API.');
                console.error(error);
            }
        }
    </script>
</body>
</html>