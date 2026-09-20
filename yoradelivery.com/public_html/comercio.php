<?php
require_once __DIR__ . '/../config.php';
yora_timezone($conexion);
$slug = trim((string) ($_GET['s'] ?? ''));
if ($slug === '' || mb_strlen($slug) > 80) {
    header('Location: /comercios.php');
    exit;
}
$c = yora_one(
    $conexion,
    "SELECT * FROM comercios WHERE slug = ? AND perfil_publico = 1 LIMIT 1",
    's',
    $slug
);
if (!$c) {
    header('Location: /comercios.php');
    exit;
}
$logo = yora_url_archivo($c['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
$cfg = yora_one($conexion, 'SELECT logo_url, tasa_bcv FROM configuracion_web LIMIT 1') ?: [];
$logo_web = yora_url_archivo((string) ($cfg['logo_url'] ?? ''));
$tasa = (float) ($cfg['tasa_bcv'] ?? 0);
$productos = yora_all($conexion, "SELECT * FROM productos WHERE comercio_id = ? AND estado = 'activo' ORDER BY categoria ASC, id ASC", 'i', (int) $c['id']);
$secciones = [];
foreach ($productos as $p) {
    $sec = trim((string) ($p['categoria'] ?? '')) ?: 'Menú';
    $secciones[$sec][] = $p;
}
$wa = preg_replace('/\D+/', '', (string) $c['telefono']);
if ($wa !== '' && strpos($wa, '58') !== 0) {
    $wa = '58' . ltrim($wa, '0');
}
$abierto = yora_comercio_esta_abierto($c);
$hora_txt = '';
$ha = substr((string) ($c['hora_abre'] ?? ''), 0, 5);
$hc = substr((string) ($c['hora_cierra'] ?? ''), 0, 5);
if ($ha !== '' && $hc !== '') {
    $hora_txt = $ha . ' a ' . $hc;
} elseif (!empty($c['horario'])) {
    $hora_txt = (string) $c['horario'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo yora_h($c['nombre']); ?> | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=12">
</head>
<body class="menu-page">
<nav class="navbar">
    <a class="nav-logo" href="/"><?php if ($logo_web): ?><img src="<?php echo yora_h($logo_web); ?>" alt="Yora"><?php else: ?><strong>Yora</strong><?php endif; ?></a>
    <div class="nav-links"><a href="/comercios.php">Directorio</a></div>
</nav>

<header class="menu-hero">
    <img src="<?php echo yora_h($logo); ?>" alt="">
    <div>
        <h1><?php echo yora_h($c['nombre']); ?></h1>
        <p><?php echo yora_h($c['categoria'] ?: 'Comercio aliado'); ?></p>
        <em><?php echo $abierto ? 'Abierto ahora' : 'Cerrado ahora'; ?><?php echo $hora_txt !== '' ? ' · ' . yora_h($hora_txt) : ''; ?></em>
        <?php if (!empty($c['direccion'])): ?><span><?php echo yora_h($c['direccion']); ?></span><?php endif; ?>
    </div>
</header>
<?php if (!$abierto): ?>
<p class="menu-about" style="background:#fee2e2;color:#991b1b;padding:12px 14px;border-radius:12px;">Este local está cerrado ahora.<?php echo $hora_txt !== '' ? ' Horario: ' . yora_h($hora_txt) . '.' : ''; ?></p>
<?php endif; ?>
<?php if (!empty($c['descripcion'])): ?>
<p class="menu-about"><?php echo yora_h($c['descripcion']); ?></p>
<?php endif; ?>

<?php if ($secciones): ?>
<nav class="menu-cats">
    <?php foreach (array_keys($secciones) as $sec): ?>
        <a href="#sec-<?php echo yora_h(rawurlencode($sec)); ?>"><?php echo yora_h($sec); ?></a>
    <?php endforeach; ?>
</nav>
<?php foreach ($secciones as $sec => $items): ?>
<section class="menu-sec" id="sec-<?php echo yora_h(rawurlencode($sec)); ?>">
    <h2><?php echo yora_h($sec); ?></h2>
    <?php foreach ($items as $p):
        $pimg = yora_url_archivo($p['imagen_url'] ?? '', '', 'https://yoradelivery.com');
        $item = [
            'id' => (int) $p['id'],
            'n' => $p['nombre'],
            'p' => (float) $p['precio'],
        ];
    ?>
    <article class="menu-item">
        <?php if ($pimg): ?><img src="<?php echo yora_h($pimg); ?>" alt=""><?php endif; ?>
        <div>
            <strong><?php echo yora_h($p['nombre']); ?></strong>
            <?php if (!empty($p['descripcion'])): ?><p><?php echo yora_h($p['descripcion']); ?></p><?php endif; ?>
            <b>$<?php echo number_format((float) $p['precio'], 2); ?></b>
        </div>
        <?php if ($abierto): ?>
        <button type="button" class="btn-app btn-orange" onclick='addCart(<?php echo json_encode($item, JSON_UNESCAPED_UNICODE); ?>)'>Pedir</button>
        <?php else: ?>
        <button type="button" class="btn-app" disabled style="opacity:.5;cursor:default;">Cerrado</button>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php else: ?>
<p class="menu-about">Este local ya está en Yora. El menú se está cargando; mientras tanto puedes escribirles por WhatsApp.</p>
<?php endif; ?>

<?php if ($abierto): ?>
<button type="button" class="cart-fab" id="cart-fab" onclick="abrirCarrito()">Mi pedido <span id="cart-n">0</span></button>
<?php endif; ?>

<div id="cart-modal" class="modal-overlay">
    <div class="modal-content" style="width:min(420px,100%);">
        <span class="close-btn" onclick="cerrarCarrito()">&times;</span>
        <h3>Mi pedido</h3>
        <div id="cart-lines"></div>
        <p id="cart-total" style="font-weight:800; margin:12px 0;"></p>
        <button type="button" class="btn-app btn-orange" style="width:100%;" onclick="enviarWhatsapp()">Enviar pedido por WhatsApp</button>
    </div>
</div>

<script>
const WA = <?php echo json_encode($wa); ?>;
const LOCAL = <?php echo json_encode($c['nombre']); ?>;
const TASA = <?php echo json_encode($tasa); ?>;
const ABIERTO = <?php echo $abierto ? 'true' : 'false'; ?>;
let cart = [];
function addCart(item) {
    if (!ABIERTO) { alert('Este local está cerrado ahora.'); return; }
    const found = cart.find(x => x.id === item.id);
    if (found) found.q += 1; else cart.push({...item, q: 1});
    pintar();
}
function pintar() {
    const n = cart.reduce((a, x) => a + x.q, 0);
    const num = document.getElementById('cart-n');
    const fab = document.getElementById('cart-fab');
    if (num) num.innerText = n;
    if (fab) fab.style.display = n ? 'flex' : 'none';
    const box = document.getElementById('cart-lines');
    if (!box) return;
    const total = cart.reduce((a, x) => a + x.p * x.q, 0);
    box.innerHTML = cart.map(x => `<div style="display:flex;justify-content:space-between;gap:8px;margin:8px 0;"><span>${x.q}× ${x.n}</span><b>$${(x.p*x.q).toFixed(2)}</b></div>`).join('') || '<p>Vacío</p>';
    let extra = '';
    if (TASA > 0) extra = ` · Bs ${(total * TASA).toFixed(2)}`;
    document.getElementById('cart-total').innerText = 'Total USD $' + total.toFixed(2) + extra;
}
function abrirCarrito() { document.getElementById('cart-modal').style.display = 'flex'; pintar(); }
function cerrarCarrito() { document.getElementById('cart-modal').style.display = 'none'; }
function enviarWhatsapp() {
    if (!cart.length) return;
    let msg = 'Hola, quiero pedir en *' + LOCAL + '*:%0A';
    cart.forEach(x => { msg += '%0A- ' + x.q + 'x ' + x.n + ' ($' + (x.p * x.q).toFixed(2) + ')'; });
    const total = cart.reduce((a, x) => a + x.p * x.q, 0);
    msg += '%0A%0ATotal: $' + total.toFixed(2);
    if (!WA) { alert('Este comercio aún no tiene WhatsApp cargado.'); return; }
    window.open('https://wa.me/' + WA + '?text=' + msg, '_blank');
}
pintar();
</script>
</body>
</html>
