<?php
require_once 'conexion.php';
yora_require_admin_pagina('tarifas.php');
yora_timezone($conexion);
$mensaje = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !yora_verify_same_origin() && !yora_csrf_ok()) {
    header('Location: tarifas.php');
    exit;
}

if (isset($_POST['actualizar_tarifas'])) {
    $minima = round((float) ($_POST['tarifa_minima'] ?? 1), 2);
    if ($minima <= 0 || $minima > 50) {
        $minima = 1.00;
    }

    $tramos = [];
    $hastas  = $_POST['km_hasta'] ?? [];
    $precios = $_POST['precio_km'] ?? [];
    $n = min(count($hastas), count($precios), 6);
    for ($i = 0; $i < $n; $i++) {
        $precio = round((float) $precios[$i], 2);
        if ($precio <= 0 || $precio > 50) {
            continue;
        }
        $hasta_raw = trim((string) $hastas[$i]);
        $hasta = ($hasta_raw === '' || $i === $n - 1) ? null : round((float) $hasta_raw, 2);
        $tramos[] = ['hasta' => $hasta, 'precio' => $precio];
    }

    if (count($tramos) < 2) {
        $mensaje = "<div class='alert error'>Configura al menos dos tramos (por ejemplo 0-8 km y 8-12 km).</div>";
    } else {
        try {
            $conexion->begin_transaction();
            $conexion->query('DELETE FROM tarifas_tramos');
            $orden = 1;
            foreach ($tramos as $t) {
                if ($t['hasta'] === null) {
                    yora_exec($conexion, 'INSERT INTO tarifas_tramos (orden, km_hasta, precio_km) VALUES (?, NULL, ?)', 'id', $orden, $t['precio']);
                } else {
                    yora_exec($conexion, 'INSERT INTO tarifas_tramos (orden, km_hasta, precio_km) VALUES (?, ?, ?)', 'idd', $orden, $t['hasta'], $t['precio']);
                }
                $orden++;
            }
            yora_exec($conexion, 'UPDATE configuracion_web SET tarifa_minima = ?, precio_km = ? LIMIT 1', 'dd', $minima, $tramos[0]['precio']);
            $conexion->commit();
            $mensaje = "<div class='alert success'>Tarifas actualizadas. El cobro por tramos ya está activo en todos los comercios.</div>";
        } catch (Throwable $e) {
            $conexion->rollback();
            error_log('tarifas: ' . $e->getMessage());
            $mensaje = "<div class='alert error'>No se pudieron guardar las tarifas.</div>";
        }
    }
}

if (isset($_POST['actualizar_bcv'])) {
    yora_timezone($conexion);
    $bcv_now = yora_tasa_bcv($conexion, true);
    if ($bcv_now['tasa'] > 0) {
        $mensaje = "<div class='alert success'>Tasa actualizada: Bs. " . number_format($bcv_now['tasa'], 2) . " (" . yora_h((string) $bcv_now['fuente']) . ").</div>";
    } else {
        $mensaje = "<div class='alert error'>No se pudo consultar el BCV ahora. Colócala a mano con el recuadro de abajo.</div>";
    }
}

if (isset($_POST['guardar_bcv_manual'])) {
    yora_timezone($conexion);
    $manual = yora_tasa_bcv_numero((string) ($_POST['tasa_manual'] ?? ''));
    if ($manual < 20 || $manual > 100000) {
        $mensaje = "<div class='alert error'>Escribe una tasa válida (ejemplo: 842.21).</div>";
    } else {
        yora_tasa_bcv_guardar($conexion, $manual, date('Y-m-d'), 'manual', true);
        $mensaje = "<div class='alert success'>Tasa manual guardada: Bs. " . number_format($manual, 2) . ". No se pisa sola hasta que actualices o la cambies otra vez.</div>";
    }
}

if (isset($_POST['actualizar_club'])) {
    $niveles_in = $_POST['nivel'] ?? [];
    try {
        $conexion->begin_transaction();
        foreach ($niveles_in as $id => $n) {
            $id = (int) $id;
            if ($id < 1) {
                continue;
            }
            $hasta_raw = trim((string) ($n['hasta'] ?? ''));
            $hasta = $hasta_raw === '' ? null : (int) $hasta_raw;
            $beneficios = implode('|', array_filter(array_map('trim', explode("\n", (string) ($n['beneficios'] ?? '')))));
            if ($hasta === null) {
                yora_exec(
                    $conexion,
                    'UPDATE niveles_comercio SET nombre=?, pedidos_desde=?, pedidos_hasta=NULL, bono_mensual=?, margen_credito=?, color=?, estrellas=?, beneficios=? WHERE id=?',
                    'siddsisi',
                    trim((string) ($n['nombre'] ?? '')),
                    (int) ($n['desde'] ?? 0),
                    round((float) ($n['bono'] ?? 0), 2),
                    round((float) ($n['margen'] ?? 0), 2),
                    preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($n['color'] ?? '')) ? $n['color'] : '#64748b',
                    max(0, min(5, (int) ($n['estrellas'] ?? 0))),
                    $beneficios,
                    $id
                );
            } else {
                yora_exec(
                    $conexion,
                    'UPDATE niveles_comercio SET nombre=?, pedidos_desde=?, pedidos_hasta=?, bono_mensual=?, margen_credito=?, color=?, estrellas=?, beneficios=? WHERE id=?',
                    'siiddsisi',
                    trim((string) ($n['nombre'] ?? '')),
                    (int) ($n['desde'] ?? 0),
                    $hasta,
                    round((float) ($n['bono'] ?? 0), 2),
                    round((float) ($n['margen'] ?? 0), 2),
                    preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($n['color'] ?? '')) ? $n['color'] : '#64748b',
                    max(0, min(5, (int) ($n['estrellas'] ?? 0))),
                    $beneficios,
                    $id
                );
            }
        }
        $conexion->commit();
        $mensaje = "<div class='alert success'>Club Yora actualizado. Los beneficios ya rigen para comercios y para el bloque de la comanda en la app.</div>";
    } catch (Throwable $e) {
        $conexion->rollback();
        error_log('club: ' . $e->getMessage());
        $mensaje = "<div class='alert error'>No se pudieron guardar los niveles.</div>";
    }
}

$tramos = yora_all($conexion, 'SELECT * FROM tarifas_tramos ORDER BY orden ASC, id ASC');
if (!$tramos) {
    $tramos = [
        ['km_hasta' => 8, 'precio_km' => yora_precio_km($conexion)],
        ['km_hasta' => 12, 'precio_km' => 0.30],
        ['km_hasta' => null, 'precio_km' => 0.25],
    ];
}
$minima = yora_tarifa_minima($conexion);
$niveles = yora_niveles_comercio($conexion);
$bcv = yora_tasa_bcv($conexion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Tarifas y Finanzas</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --yora-orange: #ce4e2d; --yora-orange-hover: #e65a36;
            --bg-body: #f4f6f8; --bg-card: #ffffff;
            --border-color: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280;
        }
        body { margin: 0; font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; box-sizing: border-box; box-shadow: 2px 0 10px rgba(0,0,0,0.02); }
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(206,78,45,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(206,78,45,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }
        .logout-btn:hover { background-color: #fee2e2; color: #ef4444; }
        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 1.8rem; margin-bottom: 5px; font-weight: 700; }
        .header-subtitle { color: var(--text-muted); margin-bottom: 25px; font-size: 0.95rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        .card { background: var(--bg-card); padding: 28px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card.wide { grid-column: 1 / -1; }
        .card h3 { margin: 0 0 18px; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; display:flex; align-items:center; gap: 8px;}
        .card h3::before { content: ""; display: block; width: 4px; height: 16px; background: var(--yora-orange); border-radius: 4px; }
        label { display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-family: inherit; box-sizing: border-box; }
        input:focus, textarea:focus { outline: none; border-color: var(--yora-orange); box-shadow: 0 0 0 3px rgba(206,78,45,0.12); }
        .tramo { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; background: #f8fafc; padding: 12px; border-radius: 10px; }
        .nivel { border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 14px; }
        .nivel-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
        .btn-save { background: var(--yora-orange); color: white; border: none; padding: 14px 20px; font-size: 0.95rem; border-radius: 10px; cursor: pointer; font-weight: 600; width: 100%; margin-top: 8px; font-family: inherit; }
        .btn-save:hover { background: var(--yora-orange-hover); }
        .alert { padding: 14px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; font-size: 0.9rem; }
        .alert.success { background: #dcfce7; border: 1px solid #22c55e; color: #166534; }
        .alert.error { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; }
        .info-box { background: #fff7ed; padding: 14px; border-radius: 10px; border: 1px dashed #fdba74; margin-bottom: 18px; font-size: 0.85rem; color: #9a3412; }
        .chip { display:inline-block; background:#f1f5f9; padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; color:#475569; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1 class="header-title">Tarifas y Finanzas</h1>
        <p class="header-subtitle">Precio por tramos, Club Yora y tasa BCV del día.</p>
        <?php echo $mensaje; ?>

        <div class="grid">
            <div class="card">
                <h3>Costo por tramos de distancia</h3>
                <div class="info-box">
                    El cobro es progresivo: un envío de 10 km paga los primeros 8 km al precio del tramo 1 y los 2 restantes al del tramo 2. Así los viajes largos no se disparan.
                </div>
                <form method="POST"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="actualizar_tarifas" value="1">
                    <?php
                    $desde = 0;
                    foreach ($tramos as $i => $t):
                        $hasta = $t['km_hasta'];
                        $es_ultimo = ($i === count($tramos) - 1);
                    ?>
                    <div class="tramo">
                        <div>
                            <label><?php echo $es_ultimo ? 'Desde ' . $desde . ' km en adelante' : 'Hasta (km)'; ?></label>
                            <input type="<?php echo $es_ultimo ? 'text' : 'number'; ?>" name="km_hasta[]" step="0.1" <?php echo $es_ultimo ? 'value="" placeholder="Sin tope" readonly style="background:#f1f5f9;"' : 'value="' . yora_h((string) $hasta) . '" required'; ?>>
                        </div>
                        <div>
                            <label>Precio / km ($)</label>
                            <input type="number" name="precio_km[]" step="0.01" min="0.01" value="<?php echo yora_h(number_format((float) $t['precio_km'], 2, '.', '')); ?>" required>
                        </div>
                    </div>
                    <?php
                        $desde = $hasta !== null ? (float) $hasta : $desde;
                    endforeach;
                    ?>
                    <div style="margin: 8px 0 16px;">
                        <label>Tarifa mínima por envío ($)</label>
                        <input type="number" name="tarifa_minima" step="0.01" min="0.01" value="<?php echo yora_h(number_format($minima, 2, '.', '')); ?>" required>
                    </div>
                    <button type="submit" class="btn-save">Guardar tarifas</button>
                </form>
            </div>

            <div class="card">
                <h3>Tasa BCV del día</h3>
                <p style="font-size:0.9rem; color:#475569; line-height:1.5;">
                    Primero se lee el USD de <b>bcv.org.ve</b>. Si esa página no responde, queda la última tasa o la que coloques a mano.
                </p>
                <p style="font-size:2rem; font-weight:800; color:var(--yora-orange); margin:18px 0 6px;">
                    <?php echo $bcv['tasa'] > 0 ? 'Bs. ' . number_format($bcv['tasa'], 2) : 'Sin tasa'; ?>
                </p>
                <span class="chip"><?php echo yora_h((string) ($bcv['fuente'] ?? '')); ?><?php echo ($bcv['fecha'] ?? '') !== '' ? ' · ' . yora_h((string) $bcv['fecha']) : ''; ?><?php echo !empty($bcv['manual']) ? ' · no se pisa sola' : ''; ?></span>
                <?php if ($bcv['tasa'] <= 0): ?>
                    <p style="margin-top:12px; color:#b91c1c; font-size:0.85rem;">No hay tasa disponible. Las recargas en bolívares pedirán reintentar.</p>
                <?php endif; ?>
                <form method="POST" style="margin-top:16px;"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="actualizar_bcv" value="1">
                    <button type="submit" class="btn-save">Consultar BCV ahora</button>
                </form>
                <form method="POST" style="margin-top:14px; padding-top:14px; border-top:1px dashed #e5e7eb;"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="guardar_bcv_manual" value="1">
                    <label>Tasa manual (Bs. por $1)</label>
                    <input type="number" name="tasa_manual" step="0.0001" min="20" placeholder="842.21" value="<?php echo $bcv['tasa'] > 0 ? yora_h(number_format($bcv['tasa'], 4, '.', '')) : ''; ?>" required>
                    <button type="submit" class="btn-save" style="background:#1f2937;">Guardar tasa manual</button>
                </form>
            </div>

            <div class="card wide">
                <h3>Club Yora Comercios</h3>
                <div class="info-box">
                    Los rangos son pedidos <b>entregados en el mes en curso</b>. El bono se acredita una sola vez por mes, con icono de regalo. El margen de respaldo permite seguir vendiendo en negativo; si no recargan en 24 horas, la cuenta se bloquea.
                </div>
                <form method="POST"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="actualizar_club" value="1">
                    <div class="grid">
                        <?php foreach ($niveles as $n): ?>
                        <div class="nivel" style="border-left: 5px solid <?php echo yora_h($n['color']); ?>;">
                            <div class="nivel-head">
                                <b><?php echo yora_h($n['nombre']); ?> <?php echo str_repeat('★', (int) $n['estrellas']); ?></b>
                                <input type="color" name="nivel[<?php echo (int) $n['id']; ?>][color]" value="<?php echo yora_h($n['color']); ?>" style="width:42px; height:32px; padding:0; border:none; background:transparent;">
                            </div>
                            <div class="tramo">
                                <div>
                                    <label>Nombre</label>
                                    <input type="text" name="nivel[<?php echo (int) $n['id']; ?>][nombre]" value="<?php echo yora_h($n['nombre']); ?>">
                                </div>
                                <div>
                                    <label>Estrellas (0-3)</label>
                                    <input type="number" name="nivel[<?php echo (int) $n['id']; ?>][estrellas]" min="0" max="5" value="<?php echo (int) $n['estrellas']; ?>">
                                </div>
                                <div>
                                    <label>Desde (pedidos/mes)</label>
                                    <input type="number" name="nivel[<?php echo (int) $n['id']; ?>][desde]" min="0" value="<?php echo (int) $n['pedidos_desde']; ?>">
                                </div>
                                <div>
                                    <label>Hasta (vacío = sin tope)</label>
                                    <input type="number" name="nivel[<?php echo (int) $n['id']; ?>][hasta]" min="0" value="<?php echo $n['pedidos_hasta'] === null ? '' : (int) $n['pedidos_hasta']; ?>">
                                </div>
                                <div>
                                    <label>Bono mensual ($)</label>
                                    <input type="number" step="0.01" name="nivel[<?php echo (int) $n['id']; ?>][bono]" value="<?php echo yora_h(number_format((float) $n['bono_mensual'], 2, '.', '')); ?>">
                                </div>
                                <div>
                                    <label>Margen de respaldo ($)</label>
                                    <input type="number" step="0.01" name="nivel[<?php echo (int) $n['id']; ?>][margen]" value="<?php echo yora_h(number_format((float) $n['margen_credito'], 2, '.', '')); ?>">
                                </div>
                            </div>
                            <label>Beneficios (uno por línea)</label>
                            <textarea name="nivel[<?php echo (int) $n['id']; ?>][beneficios]" rows="3"><?php echo yora_h(str_replace('|', "\n", (string) $n['beneficios'])); ?></textarea>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn-save">Guardar Club Yora</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
