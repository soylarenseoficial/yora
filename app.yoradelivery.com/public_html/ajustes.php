<?php
/**
 * AJUSTES - YORA DRIVER
 *
 * Pantalla principal de la cuenta del conductor. Antes todo esto vivia
 * dentro de un popup del dashboard; ahora cada cosa tiene su propia
 * pantalla y desde aqui se entra a cada una.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conductor_id = (int) $conductor['id'];

yora_docs_esquema($conexion);
$docs = yora_docs_estado($conductor, 'https://yoradelivery.com');

$viajes = yora_one($conexion, "SELECT COUNT(id) AS total FROM comandas WHERE conductor_id = ? AND estatus = 'Entregado'", 'i', $conductor_id);
$total_historico = (int) ($viajes['total'] ?? 0);

// Club Yora: mismos tramos que muestra el dashboard.
if ($total_historico >= 500) {
    $nivel = 'Diamante';
} elseif ($total_historico >= 200) {
    $nivel = 'Oro';
} elseif ($total_historico >= 50) {
    $nivel = 'Plata';
} else {
    $nivel = 'Bronce';
}

$foto = yora_url_archivo($conductor['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://yoradelivery.com');
$saldo = (float) ($conductor['billetera'] ?? 0);
$conexion->close();

if ($docs['verificado']) {
    $texto_docs = 'Tu expediente está aprobado';
} elseif ($docs['faltantes'] > 0) {
    $texto_docs = 'Te faltan ' . $docs['faltantes'] . ' de ' . $docs['total'] . ' documentos';
} elseif ($docs['global'] === 'Rechazado') {
    $texto_docs = 'Hay documentos que debes corregir';
} elseif ($docs['global'] === 'Cargado') {
    $texto_docs = 'Ya están todos: envíalos a revisión';
} else {
    $texto_docs = 'Enviado, esperando revisión de Yora';
}

yora_ajustes_inicio('Ajustes', '', true);
?>

<div class="ax-hero">
    <div class="ax-avatar">
        <img src="<?php echo yora_h($foto); ?>" alt="Tu foto" id="ax-foto">
        <div class="ax-pencil" onclick="document.getElementById('ax-input-foto').click()"><i class="ph ph-pencil-simple"></i></div>
    </div>
    <h2><?php echo yora_h($conductor['nombre']); ?></h2>
    <p><?php echo yora_h($conductor['correo'] ?: 'Sin correo registrado'); ?></p>
    <p><?php echo yora_h($conductor['telefono']); ?> · C.I. <?php echo yora_h($conductor['cedula'] ?: '—'); ?></p>
</div>
<input type="file" id="ax-input-foto" accept="image/*" style="display:none;" onchange="cambiarFoto(this)">

<div class="ax-wrap">

    <!-- ESTADO DE LA VERIFICACION: lo primero que tiene que ver -->
    <a href="ajustes_documentos.php" style="text-decoration:none; color:inherit;">
        <div class="ax-card" style="border-color:<?php echo yora_h($docs['estilo']['color']); ?>33;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="ph <?php echo yora_h($docs['estilo']['icono']); ?>" style="font-size:1.8rem; color:<?php echo yora_h($docs['estilo']['color']); ?>;"></i>
                <div style="flex:1; min-width:0;">
                    <strong style="display:block; font-size:0.97rem;">Verificación de la cuenta</strong>
                    <span style="display:block; font-size:0.78rem; color:#6b7280; margin-top:2px;"><?php echo yora_h($texto_docs); ?></span>
                </div>
                <span class="ax-chip" style="background:<?php echo yora_h($docs['estilo']['fondo']); ?>; color:<?php echo yora_h($docs['estilo']['color']); ?>;">
                    <?php echo yora_h($docs['estilo']['texto']); ?>
                </span>
            </div>

            <?php if (!$docs['verificado']): ?>
            <div class="ax-bar" style="margin-top:14px;"><span style="width:<?php echo (int) $docs['porcentaje']; ?>%;"></span></div>
            <div style="display:flex; justify-content:space-between; margin-top:7px; font-size:0.72rem; font-weight:700; color:#9ca3af;">
                <span><?php echo (int) $docs['cargados']; ?> de <?php echo (int) $docs['total']; ?> documentos subidos</span>
                <span style="color:#e4441b;">Completar <i class="ph ph-arrow-right"></i></span>
            </div>
            <?php endif; ?>
        </div>
    </a>

    <?php if (!$docs['verificado']): ?>
        <?php if ($docs['global'] === 'Rechazado'): ?>
        <div class="ax-note bad">
            <i class="ph ph-warning"></i> Revisamos tu expediente y hay algo que corregir.
            <?php if ($docs['motivo'] !== ''): ?><br>Motivo: <?php echo yora_h($docs['motivo']); ?><?php endif; ?>
        </div>
        <?php elseif ($docs['global'] === 'En Revisión'): ?>
        <div class="ax-note info"><i class="ph ph-clock-afternoon"></i> Tus documentos están en revisión. Te avisamos en cuanto el equipo de Yora los apruebe.</div>
        <?php elseif ($docs['global'] === 'Cargado'): ?>
        <div class="ax-note ok"><i class="ph ph-paper-plane-tilt"></i> Ya subiste todos tus documentos. Entra y pulsa <b>Enviar a verificación</b> para que Yora los revise.</div>
        <?php else: ?>
        <div class="ax-note warn"><i class="ph ph-info"></i> Para poder operar con normalidad necesitas verificar tu cuenta con tus documentos.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ax-label">Mi cuenta</div>
    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'href' => 'ajustes_perfil.php',
            'icono' => 'ph-user',
            'titulo' => 'Detalles del perfil',
            'texto' => 'Nombre, correo, teléfono y foto',
        ]);
        yora_ajustes_fila([
            'href' => 'ajustes_documentos.php',
            'icono' => 'ph-identification-card',
            'titulo' => 'Verificar mi cuenta',
            'texto' => $texto_docs,
            'chip' => $docs['estilo'],
        ]);
        yora_ajustes_fila([
            'href' => 'ajustes_cobro.php',
            'icono' => 'ph-bank',
            'titulo' => 'Datos de cobro',
            'texto' => 'Dónde te pagamos tus ganancias',
            'valor' => trim((string) ($conductor['banco_pago'] ?? '')) !== '' ? 'Registrado' : 'Falta',
        ]);
        yora_ajustes_fila([
            'href' => 'ajustes_seguridad.php',
            'icono' => 'ph-lock-key',
            'titulo' => 'Seguridad',
            'texto' => 'Cambiar tu contraseña de acceso',
        ]);
        ?>
    </div>

    <div class="ax-label">Mi trabajo</div>
    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'href' => 'billetera.php',
            'icono' => 'ph-wallet',
            'titulo' => 'Billetera',
            'texto' => 'Saldo, retiros y facturas',
            'valor' => '$' . number_format($saldo, 2),
        ]);
        yora_ajustes_fila([
            'href' => 'dashboard.php?tab=misviajes',
            'icono' => 'ph-map-pin-line',
            'titulo' => 'Mis viajes',
            'texto' => 'Viajes activos y en curso',
        ]);
        yora_ajustes_fila([
            'href' => 'ajustes_club.php',
            'icono' => 'ph-medal',
            'titulo' => 'Club Yora',
            'texto' => $total_historico . ' viajes entregados',
            'valor' => $nivel,
        ]);
        ?>
    </div>

    <div class="ax-label">Ayuda</div>
    <div class="ax-group">
        <?php
        $saludo = rawurlencode('Hola, soy ' . (string) $conductor['nombre'] . ', conductor de Yora. Necesito ayuda con:');
        yora_ajustes_fila([
            'href' => 'https://wa.me/' . YORA_WHATSAPP_SOPORTE . '?text=' . $saludo,
            'externo' => true,
            'icono' => 'ph-headset',
            'titulo' => 'Soporte',
            'texto' => 'Escríbenos por WhatsApp',
        ]);
        yora_ajustes_fila([
            'href' => 'https://yoradelivery.com/',
            'externo' => true,
            'icono' => 'ph-file-text',
            'titulo' => 'Términos y condiciones',
        ]);
        ?>
    </div>

    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'onclick' => 'cerrarSesion()',
            'icono' => 'ph-sign-out',
            'titulo' => 'Cerrar sesión',
            'clase' => 'danger',
            'flecha' => false,
        ]);
        ?>
    </div>

    <p style="text-align:center; font-size:0.72rem; color:#b4b4bb; margin: 6px 0 20px;">
        Yora Driver App V 1.0
    </p>
</div>

<script>
    async function cambiarFoto(input) {
        if (!input.files || !input.files[0]) return;
        axCargando(true, 'Actualizando tu foto...');
        try {
            const foto = await axComprimir(input.files[0]);
            const fd = new FormData();
            fd.append('foto', foto, axNombre(foto, 'perfil'));
            const data = await axEnviar('/api/subir_foto.php', fd);
            if (data.url) document.getElementById('ax-foto').src = data.url + '?v=' + Date.now();
            axToast('Foto actualizada', 'ok');
        } catch (e) {
            axToast(e.message || 'No se pudo subir la foto', 'bad');
        }
        axCargando(false);
        input.value = '';
    }

    function cerrarSesion() {
        if (!confirm('¿Cerrar tu sesión en este teléfono?')) return;
        try {
            const os = (window.median && window.median.onesignal) || (window.gonative && window.gonative.onesignal) || null;
            if (os && typeof os.logout === 'function') os.logout();
        } catch (e) {}
        window.location.href = 'logout.php';
    }
</script>

<?php yora_ajustes_fin(true); ?>
