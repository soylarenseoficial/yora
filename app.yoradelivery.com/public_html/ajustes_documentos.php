<?php
/**
 * VERIFICACION DE CUENTA - YORA DRIVER
 *
 * El conductor sube aqui cada documento y, cuando esta completo, manda el
 * expediente a revision. A partir de ese momento aparece en la cola de
 * "Verificaciones" del panel HQ.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
yora_docs_esquema($conexion);
$docs = yora_docs_estado($conductor, 'https://yoradelivery.com');
$vehiculo = strtolower(trim((string) ($conductor['tipo_vehiculo'] ?? 'moto')));
$conexion->close();

$enviado_txt = '';
if ($docs['enviado_en'] !== '' && $docs['enviado_en'] !== '0000-00-00 00:00:00') {
    $ts = strtotime($docs['enviado_en']);
    if ($ts) {
        $enviado_txt = date('d/m/Y h:i A', $ts);
    }
}

yora_ajustes_inicio('Verificar mi cuenta');
?>

<div class="ax-wrap">

    <!-- RESUMEN DEL EXPEDIENTE -->
    <div class="ax-card">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
            <i class="ph <?php echo yora_h($docs['estilo']['icono']); ?>" style="font-size:2.1rem; color:<?php echo yora_h($docs['estilo']['color']); ?>;"></i>
            <div style="flex:1;">
                <strong style="display:block; font-size:1rem;">
                    <?php echo $docs['verificado'] ? 'Cuenta verificada' : 'Cuenta sin verificar'; ?>
                </strong>
                <span style="font-size:0.78rem; color:#6b7280;">
                    <?php echo (int) $docs['cargados']; ?> de <?php echo (int) $docs['total']; ?> documentos ·
                    <?php echo (int) $docs['aprobados']; ?> aprobados
                </span>
            </div>
            <span class="ax-chip" style="background:<?php echo yora_h($docs['estilo']['fondo']); ?>; color:<?php echo yora_h($docs['estilo']['color']); ?>;">
                <?php echo yora_h($docs['estilo']['texto']); ?>
            </span>
        </div>
        <div class="ax-bar"><span style="width:<?php echo (int) $docs['porcentaje']; ?>%;"></span></div>
    </div>

    <?php if ($docs['verificado']): ?>
        <div class="ax-note ok"><i class="ph ph-check-circle"></i> Tu expediente está aprobado por Yora. Ya puedes operar con normalidad. Si un documento se te vence, súbelo de nuevo aquí.</div>
    <?php elseif ($docs['global'] === 'Rechazado'): ?>
        <div class="ax-note bad">
            <i class="ph ph-warning"></i>
            <?php if ($docs['rechazados'] > 0): ?>
                Hay documentos que debes corregir. Mira cuáles están marcados en rojo, vuelve a subirlos y envía otra vez.
            <?php else: ?>
                Yora revisó tu expediente y necesita que corrijas algo. Vuelve a subir el documento que te indican y envía otra vez.
            <?php endif; ?>
            <?php if ($docs['motivo'] !== ''): ?><br><br><b>Nota de Yora:</b> <?php echo yora_h($docs['motivo']); ?><?php endif; ?>
        </div>
    <?php elseif ($docs['global'] === 'En Revisión'): ?>
        <div class="ax-note info">
            <i class="ph ph-clock-afternoon"></i> Expediente enviado<?php echo $enviado_txt !== '' ? ' el ' . yora_h($enviado_txt) : ''; ?>.
            El equipo de Yora lo está revisando; te avisamos por notificación en cuanto haya respuesta.
        </div>
    <?php elseif ($docs['global'] === 'Cargado'): ?>
        <div class="ax-note ok">
            <i class="ph ph-paper-plane-tilt"></i> Ya tienes tus <?php echo (int) $docs['total']; ?> documentos cargados.
            Falta el último paso: pulsa <b>Enviar a verificación</b> al final de esta pantalla.
        </div>
    <?php else: ?>
        <div class="ax-note warn">
            <i class="ph ph-info"></i> Sube los <?php echo (int) $docs['total']; ?> documentos y pulsa <b>Enviar a verificación</b>.
            Fotos nítidas, con buena luz y sin cortar los bordes.
        </div>
    <?php endif; ?>

    <div class="ax-label">Documentos <?php echo $vehiculo === 'bicicleta' ? '(bicicleta)' : ''; ?></div>

    <?php foreach ($docs['items'] as $campo => $doc): ?>
    <div class="ax-card" id="doc-<?php echo yora_h($campo); ?>" style="padding:14px;">
        <div style="display:flex; gap:12px; align-items:flex-start;">
            <div style="width:44px; height:44px; border-radius:13px; background:<?php echo yora_h($doc['estilo']['fondo']); ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="ph <?php echo yora_h($doc['icono']); ?>" style="font-size:1.35rem; color:<?php echo yora_h($doc['estilo']['color']); ?>;"></i>
            </div>
            <div style="flex:1; min-width:0;">
                <strong style="display:block; font-size:0.93rem; line-height:1.3;"><?php echo yora_h($doc['etiqueta']); ?></strong>
                <span style="display:block; font-size:0.75rem; color:#9ca3af; margin-top:3px; line-height:1.35;"><?php echo yora_h($doc['ayuda']); ?></span>
                <span class="ax-chip" data-chip="<?php echo yora_h($campo); ?>" style="margin-top:8px; background:<?php echo yora_h($doc['estilo']['fondo']); ?>; color:<?php echo yora_h($doc['estilo']['color']); ?>;">
                    <i class="ph <?php echo yora_h($doc['estilo']['icono']); ?>"></i><?php echo yora_h($doc['estilo']['texto']); ?>
                </span>
                <?php if ($doc['estado'] === 'Rechazado' && $doc['motivo'] !== ''): ?>
                <span style="display:block; font-size:0.75rem; color:#b91c1c; font-weight:600; margin-top:7px; line-height:1.35;">
                    <i class="ph ph-x-circle"></i> <?php echo yora_h($doc['motivo']); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($doc['cargado'] && $doc['url'] !== ''): ?>
            <a href="<?php echo yora_h($doc['url']); ?>" target="_blank" rel="noopener" style="flex-shrink:0;">
                <?php if (strtolower((string) pathinfo(parse_url($doc['url'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) === 'pdf'): ?>
                <span style="width:52px; height:52px; border-radius:11px; border:1px solid #ececec; background:#f4f4f5; display:flex; align-items:center; justify-content:center;">
                    <i class="ph ph-file-pdf" style="font-size:1.6rem; color:#dc2626;"></i>
                </span>
                <?php else: ?>
                <img src="<?php echo yora_h($doc['url']); ?>" alt="" style="width:52px; height:52px; border-radius:11px; object-fit:cover; border:1px solid #ececec; background:#f4f4f5;">
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>

        <?php $bloqueado = $doc['estado'] === 'En Revisión'; ?>
        <button type="button"
                class="ax-btn <?php echo $doc['cargado'] ? 'ax-btn-light' : 'ax-btn-primary'; ?>"
                style="margin-top:12px; padding:11px; font-size:0.86rem;"
                data-boton="<?php echo yora_h($campo); ?>"
                <?php echo $bloqueado ? 'disabled' : ''; ?>
                onclick="elegirArchivo('<?php echo yora_h($campo); ?>')">
            <i class="ph <?php echo $doc['cargado'] ? 'ph-arrows-clockwise' : 'ph-camera'; ?>"></i>
            <?php
            if ($bloqueado) {
                echo 'En revisión';
            } elseif ($doc['cargado']) {
                echo 'Cambiar archivo';
            } else {
                echo 'Subir documento';
            }
            ?>
        </button>
    </div>
    <?php endforeach; ?>

    <input type="file" id="ax-archivo" accept="image/*,application/pdf" style="display:none;" onchange="subirArchivo(this)">

    <div class="ax-card" style="margin-top:4px;">
        <strong style="display:block; font-size:0.93rem; margin-bottom:5px;">Enviar a verificación</strong>
        <span style="display:block; font-size:0.78rem; color:#6b7280; line-height:1.4; margin-bottom:13px;">
            Cuando estén los documentos obligatorios, envíalos y el equipo de Yora los revisa.
            Mientras estén en revisión no podrás cambiarlos.
        </span>
        <button type="button" id="btn-enviar" class="ax-btn ax-btn-dark" onclick="enviarVerificacion()"
                <?php echo $docs['puede_enviar'] ? '' : 'disabled'; ?>>
            <i class="ph ph-paper-plane-tilt"></i>
            <?php
            if ($docs['verificado']) {
                echo 'Ya está verificada';
            } elseif ($docs['declarado'] === 'En Revisión' && $docs['faltantes'] === 0) {
                echo 'Enviado, en revisión';
            } elseif ($docs['faltantes'] > 0) {
                echo 'Faltan ' . (int) $docs['faltantes'] . ' documento(s)';
            } else {
                echo 'Enviar a verificación';
            }
            ?>
        </button>
    </div>

    <p style="text-align:center; font-size:0.72rem; color:#b4b4bb; margin: 4px 0 24px; line-height:1.5;">
        Formatos permitidos: JPG, PNG, WEBP o PDF · hasta 5 MB por archivo.<br>
        Tus documentos solo los ve el equipo de verificación de Yora.
    </p>
</div>

<script>
    let campoActual = '';

    function elegirArchivo(campo) {
        campoActual = campo;
        document.getElementById('ax-archivo').click();
    }

    async function subirArchivo(input) {
        if (!input.files || !input.files[0] || !campoActual) return;
        const campo = campoActual;
        const boton = document.querySelector('[data-boton="' + campo + '"]');

        axCargando(true, 'Subiendo documento...');
        try {
            const archivo = await axComprimir(input.files[0]);
            const fd = new FormData();
            fd.append('tipo', campo);
            fd.append('documento', archivo, axNombre(archivo, campo));
            const data = await axEnviar('/api/subir_documento_driver.php', fd);

            const chip = document.querySelector('[data-chip="' + campo + '"]');
            if (chip && data.estilo) {
                chip.style.background = data.estilo.fondo;
                chip.style.color = data.estilo.color;
                chip.innerHTML = '<i class="ph ' + data.estilo.icono + '"></i>' + data.estilo.texto;
            }
            if (boton) {
                boton.className = 'ax-btn ax-btn-light';
                boton.innerHTML = '<i class="ph ph-arrows-clockwise"></i>Cambiar archivo';
            }
            axToast('Documento guardado', 'ok');
            // Recargamos para refrescar el resumen y el boton de envio con los
            // datos reales del servidor, no con una copia optimista.
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            axToast(e.message || 'No se pudo subir el documento', 'bad');
        }
        axCargando(false);
        input.value = '';
        campoActual = '';
    }

    async function enviarVerificacion() {
        const btn = document.getElementById('btn-enviar');
        if (!confirm('¿Enviar tus documentos al equipo de Yora para que verifiquen tu cuenta?')) return;
        btn.disabled = true;
        axCargando(true, 'Enviando expediente...');
        try {
            await axEnviar('/api/enviar_verificacion.php', new FormData());
            axCargando(false);
            axToast('Expediente enviado a revisión', 'ok');
            setTimeout(() => window.location.reload(), 900);
        } catch (e) {
            axCargando(false);
            btn.disabled = false;
            axToast(e.message || 'No se pudo enviar', 'bad');
        }
    }
</script>

<?php yora_ajustes_fin(); ?>
