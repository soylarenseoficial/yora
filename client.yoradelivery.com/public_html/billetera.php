<?php
require_once __DIR__ . '/yora_cargar.php';
if (empty($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['usuario_id'];
$user = yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $uid);
if (!$user) { yora_logout('index.php'); }
$saldo = (float) ($user['billetera'] ?? 0);
$tasa = (float) (yora_tasa_bcv($conexion)['tasa'] ?? 0);
$pago_hq = yora_pago_hq();
$recargas = [];
try {
    $recargas = yora_all($conexion, 'SELECT * FROM recargas_clientes WHERE usuario_id = ? AND IFNULL(comanda_id,0) = 0 ORDER BY id DESC LIMIT 30', 'i', $uid) ?: [];
} catch (Throwable $e) {
    $recargas = [];
}
$csrf = yora_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Billetera | Yora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="/app.css?v=5">
    <style>
        .page { padding:18px 16px 90px; max-width:520px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#1f2937,#111827); color:#fff; border-radius:20px; padding:22px; text-align:center; }
        .hero small { color:#94a3b8; font-weight:700; font-size:.72rem; letter-spacing:.4px; text-transform:uppercase; }
        .hero strong { display:block; font-size:2.4rem; letter-spacing:-1px; margin-top:4px; }
        .box { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px; margin-top:14px; }
        select { width:100%; padding:13px 14px; border:1.5px solid #e2e8f0; border-radius:14px; background:#f8fafc; font-size:1rem; font-family:inherit; }
        .item { display:flex; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
        .item:last-child { border-bottom:0; }
        .pay-info { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:12px; font-size:.82rem; margin:10px 0; display:none; }
        .pay-info.ef { background:#f8fafc; border-style:dashed; border-color:#cbd5e1; }
    </style>
</head>
<body>
<div class="page">
    <div class="brand" style="font-size:1.2rem;">Yora<span>.</span></div>
    <div class="hero">
        <small>Tu billetera</small>
        <strong>$<?php echo number_format($saldo, 2); ?></strong>
        <p style="margin-top:8px;font-size:.78rem;color:#cbd5e1;">Recarga para pagar tus mandaditos desde la app.</p>
    </div>
    <div class="box">
        <h2 style="font-size:1rem;margin-bottom:8px;">Recargar</h2>
        <label>Método</label>
        <select id="banco" onchange="verMetodo()">
            <option value="Pago Móvil">Pago Móvil (Bs)</option>
            <option value="Efectivo">Efectivo ($)</option>
        </select>
        <div class="pay-info" id="info-pm" style="display:block;">
            <div>🏦 <b><?php echo yora_h($pago_hq['banco']); ?></b> (<?php echo yora_h($pago_hq['codigo']); ?>)</div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;">📱 <?php echo yora_h($pago_hq['telefono']); ?> <a href="#" onclick="navigator.clipboard.writeText('<?php echo yora_h($pago_hq['tel_num']); ?>');alert('Copiado');return false;" style="color:#16a34a;font-weight:800;">Copiar</a></div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;">🪪 <?php echo yora_h($pago_hq['cedula']); ?> <a href="#" onclick="navigator.clipboard.writeText('<?php echo yora_h($pago_hq['ced_num']); ?>');alert('Copiado');return false;" style="color:#16a34a;font-weight:800;">Copiar</a></div>
        </div>
        <div class="pay-info ef" id="info-ef">
            <b>Oficina Central Yora</b>
            <p><?php echo yora_h($pago_hq['efectivo']); ?></p>
        </div>
        <label>Monto ($)</label>
        <input type="number" id="monto" min="1" step="0.01" placeholder="5.00" oninput="calcBs()">
        <div id="caja-bs" style="margin-top:8px;font-size:.85rem;font-weight:800;color:#16a34a;"></div>
        <label>Referencia</label>
        <input id="ref" placeholder="Obligatoria para verificar">
        <button class="btn" type="button" onclick="enviar()">Enviar reporte</button>
        <p class="hint">HQ valida el pago y el saldo cae aquí. Mínimo $1.00.</p>
    </div>
    <div class="box">
        <h2 style="font-size:1rem;">Historial de recargas</h2>
        <?php if (!$recargas): ?>
            <p class="hint" style="padding:12px 0;">Aún no has recargado.</p>
        <?php else: foreach ($recargas as $r): ?>
            <div class="item">
                <div>
                    <b>$<?php echo number_format((float)$r['monto'], 2); ?></b><br>
                    <span style="color:#64748b;"><?php echo yora_h($r['banco_origen']); ?> · Ref <?php echo yora_h($r['referencia']); ?></span>
                </div>
                <span><?php echo yora_h($r['estatus']); ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<nav class="nav">
    <a href="dashboard.php"><i class="ph ph-map-pin"></i>Pedir</a>
    <a href="pedidos.php"><i class="ph ph-clock-counter-clockwise"></i>Pedidos</a>
    <a class="on" href="billetera.php"><i class="ph ph-wallet"></i>Billetera</a>
    <a href="ajustes.php"><i class="ph ph-gear"></i>Ajustes</a>
</nav>
<script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    const TASA = <?php echo json_encode($tasa); ?>;
    function verMetodo(){
        const m = document.getElementById('banco').value;
        document.getElementById('info-pm').style.display = m==='Pago Móvil' ? 'block' : 'none';
        document.getElementById('info-ef').style.display = m==='Efectivo' ? 'block' : 'none';
        calcBs();
    }
    function calcBs(){
        const m = document.getElementById('banco').value;
        const n = parseFloat(document.getElementById('monto').value)||0;
        const caja = document.getElementById('caja-bs');
        if (m==='Pago Móvil' && TASA>0 && n>0) caja.textContent = 'Transferir Bs. ' + (n*TASA).toFixed(2);
        else caja.textContent = '';
    }
    async function enviar(){
        const monto = parseFloat(document.getElementById('monto').value)||0;
        const banco = document.getElementById('banco').value;
        const ref = document.getElementById('ref').value.trim();
        if (monto < 1) { alert('El mínimo es $1.00'); return; }
        if (!ref) { alert('Coloca la referencia.'); return; }
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('monto', String(monto));
        fd.append('banco', banco);
        fd.append('referencia', ref);
        const r = await fetch('/api/recargar_billetera.php', {method:'POST', body:fd});
        const d = await r.json();
        alert(d.mensaje||'Listo');
        if (d.status==='success') location.reload();
    }
</script>
</body>
</html>
