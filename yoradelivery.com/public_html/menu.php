<?php
require_once __DIR__ . '/../config.php';

$comercio_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($comercio_id < 1) {
    die("<h2 style='text-align:center;margin-top:50px;font-family:sans-serif;'>Comercio no especificado.</h2>");
}

$comercio = yora_one($conexion, 'SELECT nombre, telefono, logo_url FROM comercios WHERE id = ? LIMIT 1', 'i', $comercio_id);
if (!$comercio) {
    die("<h2 style='text-align:center;margin-top:50px;font-family:sans-serif;'>Este comercio no existe.</h2>");
}

$productos = [];
$check_tabla = $conexion->query("SHOW TABLES LIKE 'productos'");
if ($check_tabla && $check_tabla->num_rows > 0) {
    $productos = yora_all($conexion, "SELECT id, nombre, descripcion, precio FROM productos WHERE comercio_id = ? AND estado = 'activo'", 'i', $comercio_id);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú - <?php echo yora_h($comercio['nombre']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; margin: 0; padding-bottom: 90px; }
        .header { background: #fff; padding: 25px; text-align: center; }
        .header img { max-width: 90px; border-radius: 50%; object-fit: cover; }
        .container { padding: 20px; max-width: 600px; margin: 0 auto; }
        .card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .info h4 { margin: 0 0 5px 0; color: #333; }
        .info p { margin: 0; color: #777; font-size: 0.85em; }
        .price { font-weight: 600; color: #FF5A09; margin-top: 5px; display: block; }
        .btn-add { background: #FF5A09; color: white; border: none; padding: 8px 14px; border-radius: 20px; font-weight: 600; cursor: pointer; }
        .floating-cart { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee; }
        .btn-whatsapp { background: #25D366; color: white; border: none; padding: 12px 20px; border-radius: 30px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="header">
        <?php $logo_local = yora_url_archivo($comercio['logo_url'] ?? ''); ?>
        <?php if ($logo_local !== ''): ?>
            <img src="<?php echo yora_h($logo_local); ?>" alt="Logo">
        <?php endif; ?>
        <h2><?php echo yora_h($comercio['nombre']); ?></h2>
    </div>
    <div class="container">
        <?php if (count($productos) > 0): ?>
            <?php foreach ($productos as $prod): ?>
                <div class="card">
                    <div class="info">
                        <h4><?php echo yora_h($prod['nombre']); ?></h4>
                        <p><?php echo yora_h($prod['descripcion']); ?></p>
                        <span class="price">$<?php echo number_format((float) $prod['precio'], 2); ?></span>
                    </div>
                    <button class="btn-add" onclick="agregar(<?php echo htmlspecialchars(json_encode($prod['nombre'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (float) $prod['precio']; ?>)">Agregar</button>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align:center; color:#777; margin-top:40px;">Este comercio configurará su menú digital muy pronto.</p>
        <?php endif; ?>
    </div>
    <div class="floating-cart">
        <div>
            <span style="font-size:0.8em; color:#777;">Total estimado</span><br>
            <strong id="total-text" style="font-size:1.1em;">$0.00</strong>
        </div>
        <button class="btn-whatsapp" onclick="enviarPedido()">Pedir por WhatsApp</button>
    </div>
    <script>
        let carrito = [];
        let total = 0;
        let whatsappComercio = <?php echo json_encode(preg_replace('/\D+/', '', (string) $comercio['telefono'])); ?>;

        function agregar(nombre, precio) {
            carrito.push({nombre, precio});
            total += precio;
            document.getElementById('total-text').innerText = '$' + total.toFixed(2);
        }

        function enviarPedido() {
            if (carrito.length === 0) {
                alert("Agrega productos al carrito primero.");
                return;
            }
            let cliente = prompt("¿Cuál es tu nombre?");
            if (!cliente) return;
            let direccion = prompt("¿Cuál es tu dirección de entrega?");
            if (!direccion) return;
            let msg = `Hola, soy *${cliente}*. Quiero hacer este pedido:\n\n`;
            carrito.forEach(i => { msg += `• 1x ${i.nombre} ($${i.precio.toFixed(2)})\n`; });
            msg += `\n*Total: $${total.toFixed(2)}*\nDirección: ${direccion}`;
            window.open(`https://wa.me/${whatsappComercio}?text=` + encodeURIComponent(msg), '_blank');
        }
    </script>
</body>
</html>
