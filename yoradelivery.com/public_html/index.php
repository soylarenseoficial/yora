<?php
require_once __DIR__ . '/../config.php';
if ($conexion->connect_error) { die("Error de conexión"); }

$resultado = $conexion->query("SELECT * FROM configuracion_web LIMIT 1");
$config = $resultado ? $resultado->fetch_assoc() : [];

$directorio = [];
try {
    $directorio = yora_all(
        $conexion,
        "SELECT id, nombre, slug, descripcion, categoria, direccion, logo_url, horario
         FROM comercios
         WHERE perfil_publico = 1
         ORDER BY nombre ASC
         LIMIT 24"
    );
} catch (Throwable $e) {
    $directorio = [];
}

if ((int) ($config['modo_mantenimiento'] ?? 0) === 1) {
    $bg = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($config['color_fondo'] ?? '')) ? $config['color_fondo'] : '#0f172a';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Yora Delivery</title><style>body{background-color:'.yora_h($bg).';color:white;font-family:Arial,sans-serif;display:flex;flex-direction:column;justify-content:center;align-items:center;height:100vh;margin:0;text-align:center;padding:0 20px}.maintenance-box{border-top:4px solid #ce4e2d;background-color:rgba(255,255,255,0.05);padding:40px;border-radius:8px;max-width:600px}h2{color:#ce4e2d;font-size:2rem;margin-top:0}</style></head><body>';
    $logo_web = yora_url_archivo($config['logo_url'] ?? '');
    if ($logo_web !== '') echo '<img src="'.yora_h($logo_web).'" style="max-width:300px;margin-bottom:30px">';
    echo '<div class="maintenance-box"><h2>'.yora_h($config['titulo_mantenimiento'] ?? '').'</h2><p>'.yora_h($config['mensaje_mantenimiento'] ?? '').'</p></div></body></html>';
    exit;
}

$logo_web = yora_url_archivo($config['logo_url'] ?? '');
$favicon_web = yora_url_archivo($config['favicon_url'] ?? '');
$n_aliados = count($directorio);
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yora Delivery | Barquisimeto</title>
    <?php if ($favicon_web !== ''): ?>
    <link rel="icon" href="<?php echo yora_h($favicon_web); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=12">
</head>
<body>
    <nav class="navbar">
        <a class="nav-logo" href="/">
            <?php if ($logo_web !== ''): ?>
                <img src="<?php echo yora_h($logo_web); ?>" alt="Yora">
            <?php else: ?>
                <strong>Yora</strong>
            <?php endif; ?>
        </a>
        <button type="button" class="nav-burger" onclick="document.body.classList.toggle('nav-open')" aria-label="Menú">☰</button>
        <div class="nav-links">
            <a href="/comercios.php">Comercios</a>
            <a href="/registrosdriver.php">Conductores</a>
            <a href="/registroscomercio.php" class="btn-app btn-orange"><?php echo yora_h($config['nav_btn'] ?: 'Unir mi local'); ?></a>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-text">
            <p class="eyebrow">Barquisimeto · logística B2B</p>
            <h1><?php echo yora_titulo_web($config['hero_titulo'] ?: "Pide ahora.\nYora lo mueve."); ?></h1>
            <p><?php echo yora_h($config['hero_texto'] ?? 'La red de delivery de los comercios de Barquisimeto: menú público, motorizado en minutos y recarga directa. Sin comisiones raras.'); ?></p>
            <div class="hero-actions">
                <a href="/comercios.php" class="btn-app btn-orange">Explorar comercios</a>
                <a href="/registroscomercio.php" class="btn-app btn-ghost"><?php echo yora_h($config['hero_btn'] ?: 'Afiliar mi local'); ?></a>
            </div>
        </div>
        <div class="hero-phone">
            <div class="phone-shell">
                <div class="phone-screen">
                    <div class="phone-card">
                        <span>● En camino</span>
                        <strong>Pedido #ID20418</strong>
                        <p>SoyLarense → Casa roja</p>
                        <div class="phone-bar"><i></i></div>
                    </div>
                    <div class="phone-card" style="margin-top:12px;">
                        <strong>$1.60</strong>
                        <p>Doble entrega · 1.2 km</p>
                    </div>
                </div>
            </div>
            <div class="float-card a">
                <strong><?php echo $n_aliados ?: '—'; ?></strong>
                <p>Aliados visibles</p>
            </div>
            <div class="float-card b">
                <strong>0%</strong>
                <p>Comisión oculta</p>
            </div>
        </div>
    </section>

    <section class="stats-bar">
        <div class="stat-item"><h3>100%</h3><p>Hecho para comercios</p></div>
        <div class="stat-item"><h3><?php echo $n_aliados ?: '24/7'; ?></h3><p><?php echo $directorio ? 'Aliados en el directorio' : 'Soporte local'; ?></p></div>
        <div class="stat-item"><h3>1 app</h3><p>Comercio y motorizado</p></div>
        <div class="stat-item"><h3>Hoy</h3><p>Saldo y viajes claros</p></div>
    </section>

    <section class="how">
        <h2>Así de simple</h2>
        <div class="how-grid">
            <article class="how-card"><div class="how-n">1</div><h3>Elige el local</h3><p>Entra al directorio, mira el menú y arma el pedido. Se envía por WhatsApp al comercio.</p></article>
            <article class="how-card"><div class="how-n">2</div><h3>El local despacha</h3><p>El comercio pide un Yora desde su panel. Un motorizado toma el viaje en el radar.</p></article>
            <article class="how-card"><div class="how-n">3</div><h3>Llega en minutos</h3><p>Ruta clara, foto de entrega y tarifa por distancia. Tú no pagas comisiones escondidas.</p></article>
        </div>
    </section>

    <section id="directorio" class="directorio">
        <div class="section-head">
            <div>
                <h2>Directorio de aliados</h2>
                <p>Pide el menú de cada local. El delivery lo mueve Yora, como una red de tiendas en la ciudad.</p>
            </div>
            <input type="search" id="busca-directorio" placeholder="Buscar pizzería, farmacia..." oninput="filtrarDirectorio(this.value)">
        </div>
        <div class="dir-grid" id="dir-grid">
            <?php if ($directorio): foreach ($directorio as $c):
                $slug = trim((string) ($c['slug'] ?? '')) ?: yora_slug_comercio((string) $c['nombre'], (int) $c['id']);
                $logo = yora_url_archivo($c['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
            ?>
            <a class="dir-card" data-q="<?php echo yora_h(mb_strtolower($c['nombre'] . ' ' . ($c['categoria'] ?? ''))); ?>" href="/comercio.php?s=<?php echo urlencode($slug); ?>">
                <div class="dir-top">
                    <img src="<?php echo yora_h($logo); ?>" alt="" onerror="this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png'">
                    <div>
                        <strong><?php echo yora_h($c['nombre']); ?></strong>
                        <span><?php echo yora_h($c['categoria'] ?: 'Comercio aliado'); ?></span>
                        <em><?php echo yora_h($c['horario'] ?: ($c['direccion'] ?? '')); ?></em>
                    </div>
                </div>
                <div class="dir-go">Ver menú</div>
            </a>
            <?php endforeach; else: ?>
            <div class="dir-empty">
                <h3>El directorio se está armando</h3>
                <p>Cuando un comercio se publica desde HQ aparece aquí con su menú.</p>
                <a href="/registroscomercio.php" class="btn-app btn-orange">Unir mi local</a>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($directorio): ?>
        <p class="dir-more"><a href="/comercios.php">Ver todos los comercios</a></p>
        <?php endif; ?>
    </section>

    <section id="negocios" class="split-section">
        <div class="split-content">
            <h5 class="badge">Para comercios</h5>
            <h2>Despacha sin comisiones raras</h2>
            <p>Saldo prepagado, tarifa por distancia y un driver en minutos. Tú te ocupas de la cocina; Yora de la calle.</p>
            <a href="/registroscomercio.php" class="btn-app btn-orange">Unir mi comercio</a>
        </div>
        <div id="conductores" class="split-content alt">
            <h5 class="badge">Para drivers</h5>
            <h2>Viajes claros, pago al día</h2>
            <p>Radar por kilómetros, doble ganga y billetera dentro de la app. Una sola sesión por teléfono.</p>
            <a href="/registrosdriver.php" class="btn-app btn-ghost">Quiero conducir</a>
        </div>
    </section>

    <section class="cta-band">
        <div>
            <h2>¿Tu local aún no está en Yora?</h2>
            <p>Afiliación sencilla. El equipo te activa el panel y sales en el directorio.</p>
        </div>
        <a href="/registroscomercio.php" class="btn-app">Solicitar afiliación</a>
    </section>

    <footer class="footer">
        <div class="footer-grid">
            <div>
                <div class="footer-logo"><?php if ($logo_web !== ''): ?><img src="<?php echo yora_h($logo_web); ?>" alt="Yora"><?php endif; ?></div>
                <p class="footer-about"><?php echo yora_h($config['footer_about'] ?? 'Yora es la compañía logística detrás de la red que mueve comercios y comercios en Barquisimeto.'); ?></p>
            </div>
            <div>
                <h4>Comercios</h4>
                <ul>
                    <li><a href="/comercios.php">Directorio</a></li>
                    <li><a href="/registroscomercio.php">Afiliar mi local</a></li>
                    <li><a href="/privacidad.php">Privacidad</a></li>
                    <li><a href="/terminos.php">Términos</a></li>
                    <li><a href="/soporte-publico.php">Soporte</a></li>
                </ul>
            </div>
            <div>
                <h4>Descarga la app</h4>
                <div class="store-btns">
                    <?php if (!empty($config['play_store_url'])): ?>
                    <a class="store-btn" href="<?php echo yora_h($config['play_store_url']); ?>" target="_blank" rel="noopener">Google Play</a>
                    <?php else: ?>
                    <span class="store-btn disabled">Google Play</span>
                    <?php endif; ?>
                    <?php if (!empty($config['app_store_url'])): ?>
                    <a class="store-btn" href="<?php echo yora_h($config['app_store_url']); ?>" target="_blank" rel="noopener">App Store</a>
                    <?php else: ?>
                    <span class="store-btn disabled">App Store</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© <?php echo date('Y'); ?> Yora · Barquisimeto</span>
        </div>
    </footer>

    <script>
        function filtrarDirectorio(q) {
            q = (q || '').toLowerCase().trim();
            document.querySelectorAll('.dir-card').forEach(function (card) {
                card.style.display = !q || (card.getAttribute('data-q') || '').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>
</body>
</html>
