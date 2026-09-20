<?php
/**
 * DETALLES DEL PERFIL - YORA DRIVER
 *
 * El conductor puede corregir su correo y su telefono (son sus datos de
 * contacto). Nombre, cedula, vehiculo y placa son datos del expediente:
 * los cambia Soporte para que nadie altere una cuenta ya verificada.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conexion->close();

$foto = yora_url_archivo($conductor['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://yoradelivery.com');
$vehiculos = ['moto' => 'Moto', 'bicicleta' => 'Bicicleta', 'carga' => 'Vehículo de carga'];
$vehiculo = $vehiculos[strtolower(trim((string) ($conductor['tipo_vehiculo'] ?? '')))] ?? '—';
$saludo = rawurlencode('Hola, soy ' . (string) $conductor['nombre'] . ' (C.I. ' . (string) $conductor['cedula'] . '). Necesito corregir un dato de mi perfil:');

yora_ajustes_inicio('Detalles del perfil');
?>

<div class="ax-hero">
    <div class="ax-avatar">
        <img src="<?php echo yora_h($foto); ?>" alt="Tu foto" id="ax-foto">
        <div class="ax-pencil" onclick="document.getElementById('ax-input-foto').click()"><i class="ph ph-camera"></i></div>
    </div>
    <h2><?php echo yora_h($conductor['nombre']); ?></h2>
    <p>Conductor <?php echo yora_h($conductor['categoria'] ?: 'Sencillo'); ?></p>
</div>
<input type="file" id="ax-input-foto" accept="image/*" style="display:none;" onchange="cambiarFoto(this)">

<div class="ax-wrap">

    <div class="ax-label">Datos de contacto</div>
    <div class="ax-card">
        <div class="ax-field">
            <label>Correo electrónico</label>
            <input type="email" id="f_correo" value="<?php echo yora_h($conductor['correo']); ?>" placeholder="tucorreo@gmail.com" autocomplete="email">
        </div>
        <div class="ax-field">
            <label>Teléfono (WhatsApp)</label>
            <input type="tel" id="f_telefono" value="<?php echo yora_h($conductor['telefono']); ?>" placeholder="04121234567" autocomplete="tel">
            <small>Es el número por el que te contacta Soporte y los clientes.</small>
        </div>
        <button type="button" id="btn-guardar" class="ax-btn ax-btn-primary" onclick="guardarContacto()">
            <i class="ph ph-check"></i> Guardar cambios
        </button>
    </div>

    <div class="ax-label">Datos del expediente</div>
    <div class="ax-card">
        <div class="ax-field">
            <label>Nombre completo</label>
            <input type="text" value="<?php echo yora_h($conductor['nombre']); ?>" disabled>
        </div>
        <div class="ax-field">
            <label>Cédula (con esta entras a la app)</label>
            <input type="text" value="<?php echo yora_h($conductor['cedula'] ?: '—'); ?>" disabled>
        </div>
        <div class="ax-field">
            <label>Vehículo</label>
            <input type="text" value="<?php echo yora_h($vehiculo . ($conductor['placa'] ? ' · ' . $conductor['placa'] : '')); ?>" disabled>
        </div>
        <div class="ax-note info" style="margin-bottom:0;">
            <i class="ph ph-lock-simple"></i> Estos datos están protegidos porque forman parte de tu verificación. Si alguno está mal, escríbenos y lo corregimos.
        </div>
        <a class="ax-btn ax-btn-light" style="margin-top:13px; text-decoration:none;" href="https://wa.me/<?php echo YORA_WHATSAPP_SOPORTE; ?>?text=<?php echo $saludo; ?>" target="_blank" rel="noopener">
            <i class="ph ph-whatsapp-logo"></i> Solicitar cambio a Soporte
        </a>
    </div>

    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'href' => 'ajustes_documentos.php',
            'icono' => 'ph-identification-card',
            'titulo' => 'Verificar mi cuenta',
            'texto' => 'Tus documentos y su estado',
        ]);
        ?>
    </div>
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

    async function guardarContacto() {
        const btn = document.getElementById('btn-guardar');
        const fd = new FormData();
        fd.append('seccion', 'contacto');
        fd.append('correo', document.getElementById('f_correo').value.trim());
        fd.append('telefono', document.getElementById('f_telefono').value.trim());

        btn.disabled = true;
        try {
            await axEnviar('/api/actualizar_perfil_driver.php', fd);
            axToast('Datos guardados', 'ok');
        } catch (e) {
            axToast(e.message || 'No se pudo guardar', 'bad');
        }
        btn.disabled = false;
    }
</script>

<?php yora_ajustes_fin(); ?>
