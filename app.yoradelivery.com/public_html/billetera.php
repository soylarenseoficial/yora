<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conductor_id = (int) $conductor['id'];
yora_docs_esquema($conexion);

$saldo = (float) ($conductor['billetera'] ?? 0);
$nivel = trim((string) ($conductor['categoria'] ?? 'Sencillo')) ?: 'Sencillo';
$min_retiro = (strcasecmp($nivel, 'Pro') === 0) ? 5.00 : 20.00;
$puede_retirar = $saldo >= $min_retiro;

$fecha_filtro = function_exists('yora_valid_date')
    ? yora_valid_date($_GET['fecha'] ?? null)
    : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['fecha'] ?? '')) ? (string) $_GET['fecha'] : null);

$retiro_pendiente = yora_one(
    $conexion,
    "SELECT id, monto, fecha_solicitud FROM solicitudes_retiro WHERE conductor_id = ? AND estatus = 'Pendiente' ORDER BY id DESC LIMIT 1",
    'i',
    $conductor_id
);
$retiros = [];
try {
    $retiros = yora_all(
        $conexion,
        'SELECT id, monto, estatus, fecha_solicitud, fecha_pago FROM solicitudes_retiro WHERE conductor_id = ? ORDER BY id DESC LIMIT 40',
        'i',
        $conductor_id
    ) ?: [];
} catch (Throwable $e) {
    $retiros = yora_all(
        $conexion,
        'SELECT id, monto, estatus, fecha_solicitud FROM solicitudes_retiro WHERE conductor_id = ? ORDER BY id DESC LIMIT 40',
        'i',
        $conductor_id
    ) ?: [];
}

$viajes_ciclo = yora_one(
    $conexion,
    "SELECT COUNT(id) AS total, COALESCE(SUM(distancia_km), 0) AS km
     FROM comandas
     WHERE conductor_id = ? AND estatus = 'Entregado' AND pagado_al_driver = 0",
    'i',
    $conductor_id
) ?: ['total' => 0, 'km' => 0];

$sql_viajes = "SELECT c.id, c.codigo, c.costo_delivery, c.fecha_entrega, c.fecha_creacion, c.pagado_al_driver,
                      c.cliente_nombre, c.direccion_entrega, c.tipo_comanda,
                      COALESCE(NULLIF(r.nombre, ''), IF(c.tipo_comanda = 'mandadito', 'Mandadito', 'Yora')) AS restaurante
               FROM comandas c
               LEFT JOIN comercios r ON c.comercio_id = r.id
               WHERE c.conductor_id = ? AND c.estatus = 'Entregado'";
$tipos = 'i';
$args = [$conductor_id];
if ($fecha_filtro) {
    $sql_viajes .= ' AND DATE(COALESCE(c.fecha_entrega, c.fecha_creacion)) = ?';
    $tipos .= 's';
    $args[] = $fecha_filtro;
}
$sql_viajes .= ' ORDER BY COALESCE(c.fecha_entrega, c.fecha_creacion) DESC, c.id DESC LIMIT 80';
$viajes = yora_all($conexion, $sql_viajes, $tipos, ...$args) ?: [];
foreach ($viajes as $i => $v) {
    $viajes[$i]['codigo'] = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);
}

$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$ahora = date('j') . ' de ' . $meses[(int) date('n') - 1] . ' · ' . date('g:i A');
$conexion->close();

yora_ajustes_inicio('Mi billetera', '', true);
?>

<style>
    .bl-hero { padding: 4px 2px 2px; }
    .bl-hero small { display:block; font-size:0.75rem; color:#9ca3af; font-weight:600; }
    .bl-hero strong { display:block; font-size:2.35rem; font-weight:800; letter-spacing:-1.2px; margin:4px 0 0; line-height:1.05; }
    .bl-actions { display:flex; justify-content:space-around; padding: 4px 0 2px; }
    .bl-act { width:70px; text-align:center; text-decoration:none; color:#111827; background:none; border:0; cursor:pointer; font-family:inherit; }
    .bl-act i { width:48px; height:48px; border-radius:50%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-size:1.25rem; margin:0 auto 6px; color:#3f3f46; }
    .bl-act span { display:block; font-size:0.68rem; font-weight:700; color:#6b7280; }
    .bl-fecha { display:flex; align-items:center; gap:10px; }
    .bl-fecha input[type="date"] { flex:1; border:1px solid #e4e4e7; background:#fafafa; border-radius:13px; padding:12px 14px; font-size:0.92rem; font-family:inherit; }
    .bl-fecha button, .bl-fecha a { border:0; background:#111827; color:#fff; border-radius:13px; padding:12px 14px; font-weight:800; font-size:0.8rem; text-decoration:none; white-space:nowrap; cursor:pointer; font-family:inherit; }
    .bl-fecha a.ghost { background:#f3f4f6; color:#52525b; }
    .bl-row { display:flex; align-items:center; gap:12px; padding:13px 2px; width:100%; background:none; border:0; text-align:left; cursor:pointer; font-family:inherit; }
    .bl-ico { width:42px; height:42px; border-radius:50%; background:#ffedd5; color:#e4441b; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
    .bl-txt { flex:1; min-width:0; }
    .bl-txt b { display:block; font-size:0.92rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#111827; }
    .bl-txt span { display:block; font-size:0.72rem; color:#9ca3af; margin-top:2px; }
    .bl-amt { font-weight:800; font-size:0.95rem; color:#111827; white-space:nowrap; }
    #bl-factura { display:none; position:fixed; inset:0; background:rgba(15,23,42,.82); z-index:9999; align-items:center; justify-content:center; padding:18px; }
    #bl-factura .caja { background:#fff; border-radius:22px; width:100%; max-width:380px; overflow:hidden; position:relative; text-align:center; }
    #bl-factura .top { background:#e4441b; color:#fff; padding:22px 18px; font-size:1.5rem; font-weight:800; font-style:italic; }
    #bl-factura .body { padding:22px 18px 26px; }
    #bl-factura .box { text-align:left; background:#f8fafc; padding:16px; border-radius:12px; border:1px dashed #cbd5e1; font-size:0.88rem; margin:14px 0 18px; }
    #bl-factura .box p { margin:0 0 8px; }
</style>

<div class="ax-wrap">
    <div class="bl-hero">
        <small>Saldo disponible · <?php echo yora_h($ahora); ?></small>
        <strong>$<?php echo number_format($saldo, 2); ?></strong>
    </div>

    <div class="ax-card" style="padding:14px 8px 10px;">
        <div class="bl-actions">
            <button type="button" class="bl-act" onclick="retirarSaldo()">
                <i class="ph ph-export"></i><span>Retirar</span>
            </button>
            <a class="bl-act" href="ajustes_cobro.php">
                <i class="ph ph-bank"></i><span>Cobro</span>
            </a>
            <a class="bl-act" href="#retiros">
                <i class="ph ph-clock-counter-clockwise"></i><span>Retiros</span>
            </a>
            <a class="bl-act" href="#facturas">
                <i class="ph ph-receipt"></i><span>Viajes</span>
            </a>
        </div>
    </div>

    <?php if ($retiro_pendiente): ?>
    <div class="ax-note info"><i class="ph ph-clock-afternoon"></i> Tienes un retiro de $<?php echo number_format((float) $retiro_pendiente['monto'], 2); ?> en proceso.</div>
    <?php endif; ?>

    <div class="ax-note <?php echo $puede_retirar ? 'ok' : 'warn'; ?>">
        <i class="ph ph-info"></i>
        Nivel <b><?php echo yora_h($nivel); ?></b>: el mínimo para retirar es <b>$<?php echo number_format($min_retiro, 2); ?></b>.
        Este ciclo llevas <?php echo (int) $viajes_ciclo['total']; ?> viaje(s) por cobrar
        <?php if (!$puede_retirar): ?> · te faltan $<?php echo number_format($min_retiro - $saldo, 2); ?> para poder retirar<?php endif; ?>.
    </div>

    <div class="ax-label" id="retiros">Movimientos de retiro</div>
    <?php if (!$retiros): ?>
        <div class="ax-card" style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:22px 16px;">
            Aún no has solicitado un retiro.
        </div>
    <?php else: ?>
        <div class="ax-group">
        <?php foreach ($retiros as $r):
            $est = trim((string) ($r['estatus'] ?? 'Pendiente'));
            if ($est === 'Pagado') {
                $est_txt = 'Aprobado';
                $est_bg = '#dcfce7';
                $est_fg = '#166534';
            } elseif ($est === 'Rechazado') {
                $est_txt = 'Rechazado';
                $est_bg = '#fee2e2';
                $est_fg = '#991b1b';
            } else {
                $est_txt = 'Pendiente';
                $est_bg = '#fef3c7';
                $est_fg = '#92400e';
            }
            $cuando = $r['fecha_pago'] ?: $r['fecha_solicitud'];
            $ts = strtotime((string) $cuando) ?: time();
        ?>
            <div class="ax-row" style="cursor:default;">
                <i class="ph ph-bank ax-ico" style="color:#e4441b;"></i>
                <span class="ax-txt">
                    <strong>Retiro $<?php echo number_format((float) $r['monto'], 2); ?></strong>
                    <span><?php echo yora_h(date('d/m/Y h:i A', $ts)); ?><?php echo $est === 'Pagado' && !empty($r['fecha_pago']) ? ' · pagado' : ''; ?></span>
                </span>
                <span class="ax-end" style="background:<?php echo $est_bg; ?>; color:<?php echo $est_fg; ?>; border-radius:999px; padding:4px 10px; font-size:0.72rem; font-weight:800;"><?php echo yora_h($est_txt); ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="ax-label" id="facturas">Viajes completados</div>
    <div class="ax-card">
        <form class="bl-fecha" method="GET" action="billetera.php">
            <input type="date" name="fecha" value="<?php echo yora_h((string) $fecha_filtro); ?>" onchange="this.form.submit()">
            <button type="submit">Buscar</button>
            <?php if ($fecha_filtro): ?>
            <a class="ghost" href="billetera.php">Ver todos</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$viajes): ?>
        <div class="ax-card" style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:28px 16px;">
            <?php echo $fecha_filtro ? 'No hay facturas en esa fecha.' : 'Aún no tienes viajes entregados.'; ?>
        </div>
    <?php else: ?>
        <div class="ax-group">
        <?php foreach ($viajes as $v):
            $cuando = $v['fecha_entrega'] ?: $v['fecha_creacion'];
            $ts = strtotime((string) $cuando) ?: time();
            $codigo = (string) ($v['codigo'] ?? ('ID' . (int) $v['id']));
            $ganancia = yora_impacto_billetera_driver($v);
            $mostrar = yora_monto_radar_driver($v);
            if (abs($ganancia) > 0.001) {
                $mostrar = abs($ganancia);
            }
            $txt_monto = ($ganancia < -0.001 ? '-' : '+') . '$' . number_format($mostrar, 2);
            $factura = [
                'id' => $codigo,
                'fecha' => date('d/m/Y h:i A', $ts),
                'cliente' => (string) ($v['cliente_nombre'] ?? ''),
                'detalles' => function_exists('yora_texto_destino') ? yora_texto_destino($v['direccion_entrega'] ?? '') : (string) ($v['direccion_entrega'] ?? ''),
                'conductor' => 'Tú',
                'total' => number_format((float) ($v['costo_delivery'] ?? 0), 2, '.', ''),
                'ganancia' => number_format($mostrar, 2, '.', ''),
                'costo' => number_format($mostrar, 2, '.', ''),
            ];
            $pagado = (int) ($v['pagado_al_driver'] ?? 0) === 1;
            $nota_pago = yora_es_efectivo_en_mano($v) ? 'Efectivo' : ($pagado ? 'Liquidado' : 'En billetera');
        ?>
            <button type="button" class="ax-row" onclick='verFactura(<?php echo json_encode($factura, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>
                <i class="ph ph-receipt ax-ico" style="color:#e4441b;"></i>
                <span class="ax-txt">
                    <strong><?php echo yora_h($v['restaurante']); ?></strong>
                    <span><?php echo yora_h(date('d/m/Y', $ts)); ?> · #<?php echo yora_h($codigo); ?> · <?php echo yora_h($nota_pago); ?></span>
                </span>
                <span class="ax-end"><?php echo $txt_monto; ?></span>
                <i class="ph ph-caret-right ax-arrow"></i>
            </button>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="bl-factura" onclick="if(event.target===this)cerrarFactura()">
    <div class="caja">
        <div class="top">Yora<span style="font-weight:400;">Delivery</span></div>
        <button type="button" onclick="cerrarFactura()" style="position:absolute;top:12px;right:12px;width:30px;height:30px;border:0;border-radius:50%;background:rgba(0,0,0,.2);color:#fff;cursor:pointer;">×</button>
        <div class="body">
            <i class="ph ph-receipt" style="font-size:2.6rem;color:#e4441b;"></i>
            <h2 style="margin:8px 0 4px;font-size:1.25rem;">Factura #<span id="bf-id"></span></h2>
            <p id="bf-fecha" style="color:#64748b;font-size:0.85rem;font-weight:600;"></p>
            <div class="box">
                <p>🧑 <b>Cliente:</b> <span id="bf-cliente"></span></p>
                <p>📍 <b>Destino:</b> <span id="bf-detalles"></span></p>
                <p>🛵 <b>Driver:</b> <span id="bf-driver"></span></p>
                <hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0;">
                <p style="display:flex;justify-content:space-between;font-size:1.1rem;margin:0;"><b>Tu ganancia</b> <span id="bf-costo" style="color:#16a34a;font-weight:900;"></span></p>
            </div>
            <button type="button" class="ax-btn ax-btn-primary" onclick="window.print()">Imprimir comprobante</button>
        </div>
    </div>
</div>

<script>
    function verFactura(d) {
        document.getElementById('bf-id').textContent = d.id || '';
        document.getElementById('bf-fecha').textContent = d.fecha || '';
        document.getElementById('bf-cliente').textContent = d.cliente || '—';
        document.getElementById('bf-detalles').textContent = d.detalles || '—';
        document.getElementById('bf-driver').textContent = d.conductor || 'Tú';
        const mon = parseFloat(d.ganancia != null ? d.ganancia : d.costo);
        document.getElementById('bf-costo').textContent = '$' + (isFinite(mon) ? mon : 0).toFixed(2);
        document.getElementById('bl-factura').style.display = 'flex';
    }
    function cerrarFactura() {
        document.getElementById('bl-factura').style.display = 'none';
    }
    async function retirarSaldo() {
        if (!confirm('¿Retirar todo tu saldo disponible a tu cuenta de cobro?')) return;
        axCargando(true, 'Enviando solicitud...');
        try {
            await axEnviar('/api/solicitar_retiro.php', new FormData());
            axToast('Solicitud enviada', 'ok');
            setTimeout(function () { location.reload(); }, 700);
        } catch (e) {
            axToast(e.message || 'No se pudo retirar', 'bad');
        } finally {
            axCargando(false);
        }
    }
</script>
<?php yora_ajustes_fin(true, 'billetera'); ?>
