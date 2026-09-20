<?php
require_once 'conexion.php';
yora_require_admin_pagina('creditos_comercios.php');
yora_timezone($conexion);
if (is_file(__DIR__ . '/../../yora_creditos.php')) {
    require_once __DIR__ . '/../../yora_creditos.php';
}
yora_ensure_creditos_schema($conexion);

$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        http_response_code(403);
        exit('Solicitud rechazada.');
    }
    if (isset($_POST['guardar_matriz'])) {
        $map = [
            'Pequeño' => 'pequeno',
            'Mediano' => 'mediano',
            'Grande' => 'grande',
        ];
        foreach ($map as $tipo => $slug) {
            for ($n = 1; $n <= 5; $n++) {
                $val = (float) ($_POST['credito_' . $slug . '_' . $n] ?? 0);
                if ($val < 0) {
                    $val = 0;
                }
                yora_exec(
                    $conexion,
                    'INSERT INTO creditos_comercio_matriz (tipo, nivel, credito) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE credito = VALUES(credito)',
                    'sid',
                    $tipo,
                    $n,
                    $val
                );
            }
        }
        $mensaje = "<div class='alert success'>Matriz de créditos actualizada.</div>";
    }
}

$matriz = [];
$rows = yora_all($conexion, 'SELECT tipo, nivel, credito FROM creditos_comercio_matriz ORDER BY tipo, nivel') ?: [];
foreach ($rows as $r) {
    $matriz[$r['tipo']][(int) $r['nivel']] = (float) $r['credito'];
}
$defaults = [
    'Pequeño' => [1 => 50, 2 => 80, 3 => 120, 4 => 170, 5 => 230],
    'Mediano' => [1 => 70, 2 => 110, 3 => 160, 4 => 220, 5 => 300],
    'Grande' => [1 => 100, 2 => 150, 3 => 220, 4 => 300, 5 => 400],
];
foreach ($defaults as $t => $nivs) {
    foreach ($nivs as $n => $v) {
        if (!isset($matriz[$t][$n])) {
            $matriz[$t][$n] = $v;
        }
    }
}

$facturas = yora_all(
    $conexion,
    "SELECT f.*, c.nombre AS comercio
     FROM facturas_comercio f
     JOIN comercios c ON c.id = f.comercio_id
     WHERE f.estatus IN ('pendiente','gracia','vencida')
     ORDER BY f.vence_el ASC, f.id DESC
     LIMIT 80"
) ?: [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Créditos comercios</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange:#ce4e2d; --bg-body:#f4f6f8; --bg-card:#ffffff; --border-color:#e5e7eb; --text-main:#1f2937; --text-muted:#6b7280; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:var(--bg-body); color:var(--text-main); display:flex; height:100vh; overflow:hidden; }
        .content { flex:1; padding:40px 50px; overflow-y:auto; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; margin-bottom:20px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:0.85rem; }
        input[type=number] { width:90px; padding:8px; border:1px solid #e2e8f0; border-radius:8px; }
        .btn-save { background:var(--yora-orange); color:#fff; border:none; padding:12px 20px; border-radius:12px; font-weight:700; cursor:pointer; }
        .alert { padding:12px 16px; border-radius:10px; margin-bottom:16px; }
        .alert.success { background:#dcfce7; color:#166534; }
        .badge { display:inline-block; padding:3px 8px; border-radius:6px; font-size:0.72rem; font-weight:700; }
        .b-pendiente { background:#fef3c7; color:#92400e; }
        .b-gracia { background:#ffedd5; color:#9a3412; }
        .b-vencida { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="content">
    <h1 style="margin-bottom:8px;">Créditos comercios</h1>
    <p style="color:#64748b;margin-bottom:20px;">Matriz tipo × nivel (editable). Facturas abiertas abajo.</p>
    <?php echo $mensaje; ?>

    <div class="card">
        <h3 style="margin-bottom:14px;">Matriz de crédito (USD)</h3>
        <form method="post">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="guardar_matriz" value="1">
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <?php for ($n = 1; $n <= 5; $n++): ?><th>Nivel <?php echo $n; ?></th><?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $slugs = ['Pequeño' => 'pequeno', 'Mediano' => 'mediano', 'Grande' => 'grande'];
                foreach ($slugs as $tipo => $slug):
                ?>
                    <tr>
                        <td><b><?php echo yora_h($tipo); ?></b></td>
                        <?php for ($n = 1; $n <= 5; $n++): ?>
                            <td>
                                <input type="number" step="0.01" min="0"
                                    name="credito_<?php echo $slug; ?>_<?php echo $n; ?>"
                                    value="<?php echo htmlspecialchars((string) ($matriz[$tipo][$n] ?? 0)); ?>">
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:14px;"><button class="btn-save" type="submit">Guardar matriz</button></p>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-bottom:14px;">Facturas abiertas</h3>
        <table>
            <thead>
                <tr><th>ID</th><th>Comercio</th><th>Consumo</th><th>Monto</th><th>Vence</th><th>Gracia</th><th>Estado</th><th>Penaliz.</th></tr>
            </thead>
            <tbody>
            <?php if (!$facturas): ?>
                <tr><td colspan="8" style="color:#64748b;">Sin facturas pendientes.</td></tr>
            <?php else: foreach ($facturas as $f): ?>
                <tr>
                    <td>#<?php echo (int) $f['id']; ?></td>
                    <td><?php echo yora_h($f['comercio']); ?></td>
                    <td><?php echo yora_h($f['fecha_consumo']); ?></td>
                    <td>$<?php echo number_format((float) $f['monto'], 2); ?></td>
                    <td><?php echo yora_h($f['vence_el']); ?></td>
                    <td><?php echo yora_h($f['gracia_hasta']); ?></td>
                    <td><span class="badge b-<?php echo yora_h($f['estatus']); ?>"><?php echo yora_h($f['estatus']); ?></span></td>
                    <td>$<?php echo number_format((float) $f['penalizacion'], 2); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p style="margin-top:12px;font-size:0.85rem;color:#64748b;">Los pagos se aprueban en <a href="recargas.php">Gestión de pagos / recargas</a>.</p>
    </div>
</div>
</body>
</html>
