<?php
require_once __DIR__ . '/../config.php';
$lista = [];
try {
    $lista = yora_all(
        $conexion,
        "SELECT id, nombre, slug, descripcion, categoria, direccion, logo_url, horario, hora_abre, hora_cierra
         FROM comercios
         WHERE perfil_publico = 1
         ORDER BY nombre ASC"
    );
} catch (Throwable $e) {
    $lista = [];
}
$logo_web = yora_url_archivo((string) (yora_one($conexion, 'SELECT logo_url FROM configuracion_web LIMIT 1')['logo_url'] ?? ''));
$cats = [];
foreach ($lista as $c) {
    $cat = trim((string) ($c['categoria'] ?? ''));
    if ($cat !== '') {
        $cats[$cat] = ($cats[$cat] ?? 0) + 1;
    }
}
ksort($cats);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comercios aliados | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=12">
</head>
<body>
<nav class="navbar">
    <a class="nav-logo" href="/"><?php if ($logo_web): ?><img src="<?php echo yora_h($logo_web); ?>" alt="Yora"><?php else: ?><strong>Yora</strong><?php endif; ?></a>
    <button type="button" class="nav-burger" onclick="document.body.classList.toggle('nav-open')" aria-label="Menú">☰</button>
    <div class="nav-links">
        <a href="/">Inicio</a>
        <a href="/registroscomercio.php" class="btn-app btn-orange">Unir mi local</a>
    </div>
</nav>

<section class="dir-hero">
    <p class="eyebrow"><?php echo count($lista); ?> aliados en Barquisimeto</p>
    <h1>Todos los comercios</h1>
    <p>Elige un local, arma el pedido y envíalo por WhatsApp. Yora se encarga de la calle.</p>
    <input class="dir-search" type="search" id="busca" placeholder="Buscar por nombre o categoría..." oninput="filtrar()">
</section>

<section class="directorio" style="padding-top:8px;">
    <?php if ($cats): ?>
    <div class="chips" id="chips">
        <button type="button" class="chip on" data-cat="" onclick="chip(this,'')">Todos</button>
        <?php foreach ($cats as $nombre => $n): ?>
        <button type="button" class="chip" data-cat="<?php echo yora_h(mb_strtolower($nombre)); ?>" onclick="chip(this,this.getAttribute('data-cat'))"><?php echo yora_h($nombre); ?> · <?php echo (int) $n; ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="dir-grid" id="dir-grid">
        <?php foreach ($lista as $c):
            $slug = trim((string) ($c['slug'] ?? '')) ?: yora_slug_comercio((string) $c['nombre'], (int) $c['id']);
            $logo = yora_url_archivo($c['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
            $abierto = yora_comercio_esta_abierto($c);
            $q = mb_strtolower($c['nombre'] . ' ' . ($c['categoria'] ?? '') . ' ' . ($c['direccion'] ?? ''));
        ?>
        <a class="dir-card" data-q="<?php echo yora_h($q); ?>" data-cat="<?php echo yora_h(mb_strtolower((string) ($c['categoria'] ?? ''))); ?>" href="/comercio.php?s=<?php echo urlencode($slug); ?>">
            <div class="dir-top">
                <img src="<?php echo yora_h($logo); ?>" alt="" onerror="this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png'">
                <div>
                    <strong><?php echo yora_h($c['nombre']); ?></strong>
                    <span><?php echo yora_h($c['categoria'] ?: 'Comercio aliado'); ?> · <?php echo $abierto ? 'Abierto' : 'Cerrado'; ?></span>
                    <em><?php echo yora_h($c['horario'] ?: ($c['direccion'] ?? '')); ?></em>
                </div>
            </div>
            <div class="dir-go">Ver menú</div>
        </a>
        <?php endforeach; ?>
        <?php if (!$lista): ?>
        <div class="dir-empty"><h3>Aún no hay perfiles públicos</h3><p>Cuando un comercio se verifica y se marca como visible, aparece aquí.</p></div>
        <?php endif; ?>
    </div>
</section>

<footer class="footer">
    <div class="footer-bottom"><span>© <?php echo date('Y'); ?> Yora · Barquisimeto</span></div>
</footer>
<script>
var catActiva = '';
function filtrar() {
    var q = (document.getElementById('busca').value || '').toLowerCase().trim();
    document.querySelectorAll('.dir-card').forEach(function (c) {
        var okQ = !q || (c.getAttribute('data-q') || '').indexOf(q) !== -1;
        var okC = !catActiva || (c.getAttribute('data-cat') || '') === catActiva;
        c.style.display = (okQ && okC) ? '' : 'none';
    });
}
function chip(btn, cat) {
    catActiva = cat || '';
    document.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('on'); });
    btn.classList.add('on');
    filtrar();
}
</script>
</body>
</html>
