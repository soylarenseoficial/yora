<?php
/**
 * SEGURIDAD - YORA DRIVER
 * Cambio de contrasena y cierre de sesion.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ajustes_ui.php';

$conductor = yora_ajustes_conductor($conexion);
$conexion->close();

yora_ajustes_inicio('Seguridad');
?>

<div class="ax-wrap">

    <div class="ax-card">
        <h2>Cambiar contraseña</h2>
        <div class="ax-field">
            <label>Contraseña actual</label>
            <input type="password" id="pass_actual" autocomplete="current-password">
        </div>
        <div class="ax-field">
            <label>Nueva contraseña</label>
            <input type="password" id="pass_nueva" autocomplete="new-password" minlength="8">
        </div>
        <div class="ax-field">
            <label>Repite la nueva contraseña</label>
            <input type="password" id="pass_repetir" autocomplete="new-password" minlength="8">
            <small>Mínimo 8 caracteres. Al cambiarla se cierra la sesión en cualquier otro teléfono.</small>
        </div>
        <button type="button" id="btn-pass" class="ax-btn ax-btn-primary" onclick="cambiarPassword()">
            <i class="ph ph-lock-key"></i> Guardar contraseña
        </button>
    </div>

    <div class="ax-note info">
        <i class="ph ph-devices"></i> Tu cuenta solo puede estar abierta en un teléfono a la vez. Si entras en otro, la sesión anterior se cierra sola.
    </div>

    <div class="ax-label">Sesión</div>
    <div class="ax-group">
        <?php
        yora_ajustes_fila([
            'onclick' => 'cerrarSesion()',
            'icono' => 'ph-sign-out',
            'titulo' => 'Cerrar sesión',
            'texto' => 'Tendrás que entrar de nuevo con tu cédula',
            'clase' => 'danger',
            'flecha' => false,
        ]);
        ?>
    </div>

    <div class="ax-group">
        <?php
        $saludo = rawurlencode('Hola, soy ' . (string) $conductor['nombre'] . ', conductor de Yora. No puedo acceder a mi cuenta:');
        yora_ajustes_fila([
            'href' => 'https://wa.me/' . YORA_WHATSAPP_SOPORTE . '?text=' . $saludo,
            'externo' => true,
            'icono' => 'ph-headset',
            'titulo' => '¿Olvidaste tu contraseña?',
            'texto' => 'Soporte te genera una nueva',
        ]);
        ?>
    </div>
</div>

<script>
    async function cambiarPassword() {
        const actual = document.getElementById('pass_actual').value;
        const nueva = document.getElementById('pass_nueva').value;
        const repetir = document.getElementById('pass_repetir').value;

        if (!actual || !nueva) { axToast('Completa todos los campos', 'bad'); return; }
        if (nueva.length < 8) { axToast('La nueva contraseña necesita 8 caracteres', 'bad'); return; }
        if (nueva !== repetir) { axToast('Las contraseñas nuevas no coinciden', 'bad'); return; }

        const btn = document.getElementById('btn-pass');
        btn.disabled = true;
        const fd = new FormData();
        fd.append('actual', actual);
        fd.append('nueva', nueva);

        try {
            await axEnviar('/api/cambiar_password_driver.php', fd);
            axToast('Contraseña actualizada. Vuelve a entrar.', 'ok');
            setTimeout(() => { window.location.href = 'index.php'; }, 1400);
        } catch (e) {
            axToast(e.message || 'No se pudo cambiar', 'bad');
            btn.disabled = false;
        }
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

<?php yora_ajustes_fin(); ?>
