<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['conductor_id']) || !isset($_GET['id'])) {
    die('Acceso denegado.');
}
$id = (int) $_GET['id'];
$conductor_id = (int) $_SESSION['conductor_id'];
$factura = yora_one($conexion, 'SELECT c.*, r.nombre AS restaurante FROM comandas c LEFT JOIN comercios r ON c.comercio_id = r.id WHERE c.id = ? AND c.conductor_id = ?', 'ii', $id, $conductor_id);
if (!$factura) {
    die('Factura no encontrada.');
}
if (trim((string) ($factura['restaurante'] ?? '')) === '') {
    $factura['restaurante'] = yora_es_mandadito($factura) ? 'Mandadito' : 'Pedido Yora';
}
$ganancia = yora_impacto_billetera_driver($factura);
$mostrar = yora_monto_radar_driver($factura);
if (abs($ganancia) > 0.001) {
    $mostrar = abs($ganancia);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura #<?php echo $id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f4f6f8; font-family: 'Poppins', sans-serif; display: flex; justify-content: center; padding: 20px; }
        .ticket { background: white; width: 100%; max-width: 400px; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; border-top: 8px solid #10b981; }
        .ticket h1 { margin: 0; font-size: 1.5rem; color: #1f2937; }
        .ticket p { color: #6b7280; font-size: 0.9rem; margin: 5px 0 20px; }
        .monto { font-size: 2.5rem; font-weight: 700; color: #166534; margin: 20px 0; background: #dcfce7; border-radius: 12px; padding: 15px 0;}
        .detalle { text-align: left; border-top: 1px dashed #d1d5db; padding-top: 15px; margin-top: 15px; }
        .detalle div { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9rem; color: #4b5563; }
        .btn-volver { display: block; background: #1f2937; color: white; text-decoration: none; padding: 15px; border-radius: 10px; margin-top: 30px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="ticket">
        <h1><?php echo htmlspecialchars($factura['restaurante']); ?></h1>
        <p>Orden #<?php echo $id; ?> • <?php echo date("d M Y", strtotime($factura['fecha_creacion'])); ?></p>
        
        <div class="monto">$<?php echo number_format($mostrar, 2); ?></div>
        
        <div class="detalle">
            <div><span>Estatus:</span> <span style="color:#10b981; font-weight:bold;">Completado ✅</span></div>
            <div><span>Cliente:</span> <span><?php echo htmlspecialchars($factura['cliente_nombre']); ?></span></div>
            <div><span>Distancia:</span> <span><?php echo htmlspecialchars($factura['distancia_km']); ?> km</span></div>
        </div>
        
        <a href="javascript:history.back()" class="btn-volver">🔙 Volver a la App</a>
    </div>
</body>
</html>