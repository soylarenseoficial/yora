<?php
/**
 * CLUB YORA - YORA DRIVER
 * Nivel del conductor segun viajes entregados y beneficios de cada nivel.
 * Antes era un widget dentro de la pestana Perfil del dashboard.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conductor_id = (int) $conductor['id'];

$fila = yora_one($conexion, "SELECT COUNT(id) AS total FROM comandas WHERE conductor_id = ? AND estatus = 'Entregado'", 'i', $conductor_id);
$total_historico = (int) ($fila['total'] ?? 0);
$conexion->close();

// Mismos tramos que usa el dashboard.
$niveles = [
    ['nombre' => 'Bronce',   'desde' => 0,   'color' => '#b45309', 'fondo' => '#fef3c7', 'icono' => 'ph-medal',
     'beneficio' => 'Nivel inicial. Tasa estándar de ganancias.'],
    ['nombre' => 'Plata',    'desde' => 50,  'color' => '#475569', 'fondo' => '#e2e8f0', 'icono' => 'ph-medal',
     'beneficio' => 'Radar prioritario: los pedidos te suenan 2 segundos antes que a los Bronce.'],
    ['nombre' => 'Oro',      'desde' => 200, 'color' => '#ca8a04', 'fondo' => '#fef08a', 'icono' => 'ph-trophy',
     'beneficio' => 'Cero comisiones de retiro bancario y soporte preferencial Yora.'],
    ['nombre' => 'Diamante', 'desde' => 500, 'color' => '#0284c7', 'fondo' => '#e0f2fe', 'icono' => 'ph-diamond',
     'beneficio' => 'Uniforme gratis, viajes VIP directos y cero comisiones de retiro.'],
];

$actual = $niveles[0];
$indice = 0;
foreach ($niveles as $i => $n) {
    if ($total_historico >= $n['desde']) {
        $actual = $n;
        $indice = $i;
    }
}
$siguiente = $niveles[$indice + 1] ?? null;
$meta = $siguiente ? (int) $siguiente['desde'] : (int) $actual['desde'];
$porcentaje = $siguiente ? (int) round(min(100, ($total_historico / max(1, $meta)) * 100)) : 100;
$faltan = $siguiente ? max(0, $meta - $total_historico) : 0;

yora_ajustes_inicio('Club Yora');
?>

<div class="ax-wrap">

    <div class="ax-card" style="text-align:center;">
        <i class="ph <?php echo yora_h($actual['icono']); ?>" style="font-size:3rem; color:<?php echo yora_h($actual['color']); ?>;"></i>
        <h2 style="font-size:1.4rem; font-weight:800; color:#111827; text-transform:none; letter-spacing:0; margin:6px 0 2px;">
            Nivel <?php echo yora_h($actual['nombre']); ?>
        </h2>
        <p style="font-size:0.82rem; color:#6b7280; margin-bottom:16px;"><?php echo $total_historico; ?> viajes entregados</p>

        <div class="ax-bar"><span style="width:<?php echo $porcentaje; ?>%; background:linear-gradient(90deg, <?php echo yora_h($actual['color']); ?>88, <?php echo yora_h($actual['color']); ?>);"></span></div>
        <p style="font-size:0.75rem; font-weight:700; color:#9ca3af; margin-top:8px;">
            <?php if ($siguiente): ?>
                Te faltan <?php echo $faltan; ?> viajes para <?php echo yora_h($siguiente['nombre']); ?>
            <?php else: ?>
                Máximo nivel alcanzado. Eres leyenda Yora.
            <?php endif; ?>
        </p>
    </div>

    <div class="ax-note ok" style="background:<?php echo yora_h($actual['fondo']); ?>; color:<?php echo yora_h($actual['color']); ?>;">
        <b>Beneficio activo:</b> <?php echo yora_h($actual['beneficio']); ?>
    </div>

    <div class="ax-label">Todos los niveles</div>
    <div class="ax-group">
        <?php foreach ($niveles as $i => $n): $logrado = $total_historico >= $n['desde']; ?>
        <div class="ax-row" style="cursor:default;">
            <i class="ph <?php echo yora_h($n['icono']); ?> ax-ico" style="color:<?php echo yora_h($n['color']); ?>;"></i>
            <span class="ax-txt">
                <strong><?php echo yora_h($n['nombre']); ?> · desde <?php echo (int) $n['desde']; ?> viajes</strong>
                <span><?php echo yora_h($n['beneficio']); ?></span>
            </span>
            <?php if ($i === $indice): ?>
                <span class="ax-chip" style="background:#ffedd5; color:#c2410c;">Actual</span>
            <?php elseif ($logrado): ?>
                <i class="ph ph-check-circle ax-arrow" style="color:#16a34a;"></i>
            <?php else: ?>
                <i class="ph ph-lock-simple ax-arrow"></i>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php yora_ajustes_fin(); ?>
