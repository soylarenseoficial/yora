<?php
/**
 * DATOS DE COBRO (PAGO MOVIL) - YORA DRIVER
 *
 * Si el conductor todavia no tiene datos de cobro, los registra el mismo:
 * sin ellos no se le puede pagar. Una vez registrados quedan bloqueados y
 * solo Soporte los cambia, porque es el dato mas sensible de la cuenta.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conexion->close();

$banco = trim((string) ($conductor['banco_pago'] ?? ''));
$telf_pago = trim((string) ($conductor['telefono_pago'] ?? ''));
$ced_pago = trim((string) ($conductor['cedula_pago'] ?? ''));
$registrado = $banco !== '' && $telf_pago !== '' && $ced_pago !== '';

// Bancos que mas usan los drivers; "Otro" deja escribirlo a mano.
$bancos = [
    'Banesco', 'Banco de Venezuela', 'Mercantil', 'BNC', 'Bancaribe', 'Provincial',
    'Banco del Tesoro', 'Bancamiga', 'Banplus', 'BOD', 'Venezolano de Crédito', 'Banco Plaza',
];

$saludo = rawurlencode('Hola, soy ' . (string) $conductor['nombre'] . ' (C.I. ' . (string) $conductor['cedula'] . '). Necesito cambiar mis datos de cobro:');

yora_ajustes_inicio('Datos de cobro');
?>

<div class="ax-wrap">

    <div class="ax-note info">
        <i class="ph ph-bank"></i> Aquí te enviamos tus ganancias cuando pides un retiro. Debe ser una cuenta a tu nombre.
    </div>

    <?php if ($registrado): ?>
    <div class="ax-card">
        <h2>Pago móvil registrado</h2>
        <div style="display:flex; justify-content:space-between; padding:11px 0; border-bottom:1px dashed #ececec;">
            <span style="color:#6b7280; font-size:0.85rem;">Banco</span>
            <b style="font-size:0.85rem;"><?php echo yora_h($banco); ?></b>
        </div>
        <div style="display:flex; justify-content:space-between; padding:11px 0; border-bottom:1px dashed #ececec;">
            <span style="color:#6b7280; font-size:0.85rem;">Teléfono</span>
            <b style="font-size:0.85rem;"><?php echo yora_h($telf_pago); ?></b>
        </div>
        <div style="display:flex; justify-content:space-between; padding:11px 0 14px;">
            <span style="color:#6b7280; font-size:0.85rem;">Cédula del titular</span>
            <b style="font-size:0.85rem;"><?php echo yora_h($ced_pago); ?></b>
        </div>
        <div class="ax-note warn" style="margin-bottom:13px;">
            <i class="ph ph-lock-simple"></i> Por seguridad, cambiar estos datos se hace con Soporte. Así nadie puede desviar tus pagos.
        </div>
        <a class="ax-btn ax-btn-light" style="text-decoration:none;" href="https://wa.me/<?php echo YORA_WHATSAPP_SOPORTE; ?>?text=<?php echo $saludo; ?>" target="_blank" rel="noopener">
            <i class="ph ph-whatsapp-logo"></i> Solicitar cambio a Soporte
        </a>
    </div>
    <?php else: ?>
    <div class="ax-card">
        <h2>Registra tu pago móvil</h2>
        <div class="ax-field">
            <label>Banco</label>
            <select id="f_banco" onchange="verOtro()">
                <option value="">Selecciona tu banco</option>
                <?php foreach ($bancos as $b): ?>
                <option value="<?php echo yora_h($b); ?>"<?php echo $banco === $b ? ' selected' : ''; ?>><?php echo yora_h($b); ?></option>
                <?php endforeach; ?>
                <option value="__otro">Otro banco...</option>
            </select>
        </div>
        <div class="ax-field" id="caja-otro" style="display:none;">
            <label>Nombre del banco</label>
            <input type="text" id="f_banco_otro" placeholder="Escribe el nombre del banco">
        </div>
        <div class="ax-field">
            <label>Teléfono del pago móvil</label>
            <input type="tel" id="f_telefono" value="<?php echo yora_h($telf_pago ?: $conductor['telefono']); ?>" placeholder="04121234567">
        </div>
        <div class="ax-field">
            <label>Cédula del titular</label>
            <input type="text" id="f_cedula" value="<?php echo yora_h($ced_pago ?: $conductor['cedula']); ?>" placeholder="25123456">
            <small>Debe coincidir con el titular de la cuenta. Si no coincide, el banco rechaza el pago.</small>
        </div>
        <button type="button" id="btn-guardar" class="ax-btn ax-btn-primary" onclick="guardarCobro()">
            <i class="ph ph-check"></i> Guardar datos de cobro
        </button>
    </div>
    <?php endif; ?>

    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'href' => 'billetera.php',
            'icono' => 'ph-wallet',
            'titulo' => 'Ir a mi billetera',
            'texto' => 'Saldo, retiros y facturas',
        ]);
        ?>
    </div>
</div>

<script>
    function verOtro() {
        const sel = document.getElementById('f_banco');
        document.getElementById('caja-otro').style.display = sel.value === '__otro' ? 'block' : 'none';
    }

    async function guardarCobro() {
        const btn = document.getElementById('btn-guardar');
        const sel = document.getElementById('f_banco');
        const banco = sel.value === '__otro' ? document.getElementById('f_banco_otro').value.trim() : sel.value;

        const fd = new FormData();
        fd.append('seccion', 'cobro');
        fd.append('banco_pago', banco);
        fd.append('telefono_pago', document.getElementById('f_telefono').value.trim());
        fd.append('cedula_pago', document.getElementById('f_cedula').value.trim());

        btn.disabled = true;
        try {
            await axEnviar('/api/actualizar_perfil_driver.php', fd);
            axToast('Datos de cobro guardados', 'ok');
            setTimeout(() => window.location.reload(), 900);
        } catch (e) {
            axToast(e.message || 'No se pudo guardar', 'bad');
            btn.disabled = false;
        }
    }
</script>

<?php yora_ajustes_fin(); ?>
