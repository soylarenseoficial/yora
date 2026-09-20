<?php
/**
 * Solicitud de eliminación de datos — Google Play / Data Safety.
 * URL: https://yoradelivery.com/eliminar-datos.php
 */
$legalNav = 'eliminar';
$pageTitle = 'Eliminación de datos · Yora Delivery Driver';
$pageDesc = 'Cómo solicitar la eliminación de tu cuenta y datos personales en Yora Delivery Driver.';
require __DIR__ . '/include_legal_head.php';

$wa = '584225097031';
$mail = 'yoradelivery@gmail.com';
?>
        <p class="meta">Aplicación: <strong>Yora Delivery Driver</strong> · Desarrollador: <strong>Yora Delivery</strong> · Última actualización: 19 de septiembre de 2026</p>

        <h2>1. Qué puedes solicitar</h2>
        <p>Si eres conductor (u otro usuario) de <strong>Yora Delivery Driver</strong> / Yora Delivery, puedes pedir que eliminemos tu cuenta y los datos personales asociados.</p>

        <h2>2. Cómo solicitarlo</h2>
        <ol style="padding-left:1.2rem;margin-bottom:14px;color:#334155;font-size:0.95rem;line-height:1.55">
            <li style="margin-bottom:8px">Escríbenos por WhatsApp o correo (abajo).</li>
            <li style="margin-bottom:8px">Indica: <strong>asunto “Eliminar mis datos”</strong>, nombre completo, cédula o correo de la cuenta, y confirma que eres el titular.</li>
            <li style="margin-bottom:8px">Te responderemos para verificar la identidad (por seguridad).</li>
            <li style="margin-bottom:8px">Tras la verificación, procesamos la solicitud. Plazo orientativo: <strong>hasta 30 días</strong>.</li>
        </ol>
        <p>
            <a class="cta" href="https://wa.me/<?php echo $wa; ?>?text=Hola%2C%20soy%20usuario%20de%20Yora%20Delivery%20Driver%20y%20solicito%20eliminar%20mi%20cuenta%20y%20datos">Solicitar por WhatsApp</a>
        </p>
        <p style="margin-top:14px">Correo: <a href="mailto:<?php echo htmlspecialchars($mail, ENT_QUOTES, 'UTF-8'); ?>?subject=Eliminar%20mis%20datos%20Yora%20Delivery%20Driver"><?php echo htmlspecialchars($mail, ENT_QUOTES, 'UTF-8'); ?></a></p>
        <p>También: <a href="/soporte-publico.php">Centro de soporte Yora</a></p>

        <h2>3. Datos que se eliminan</h2>
        <ul>
            <li>Datos de cuenta: nombre, correo, teléfono, cédula vinculada al perfil.</li>
            <li>Credenciales de acceso y tokens de sesión.</li>
            <li>Documentos de verificación subidos (fotos de cédula, licencia, etc.), cuando ya no sea necesario conservarlos.</li>
            <li>Foto de perfil y datos de pago móvil asociados a la cuenta.</li>
            <li>Identificadores de notificaciones push vinculados a esa cuenta.</li>
            <li>Ubicación en tiempo real / estado Online (se deja de recopilar al cerrar la cuenta).</li>
        </ul>

        <h2>4. Datos que podemos conservar un tiempo</h2>
        <p>Por obligaciones legales, fiscales, antifraude o disputas de entregas, podemos retener de forma limitada:</p>
        <ul>
            <li>Registros de viajes, montos y movimientos de billetera ya cerrados.</li>
            <li>Evidencias de entrega o incidentes asociados a reclamos abiertos.</li>
            <li>Logs técnicos mínimos necesarios para seguridad.</li>
        </ul>
        <p>Esos datos se conservan solo el tiempo necesario (orientativo: hasta <strong>12–24 meses</strong>, o más si la ley lo exige) y no se usan para marketing.</p>

        <h2>5. Mientras se procesa</h2>
        <p>Puedes dejar de usar la app y cerrar sesión. Al ponerse Offline / cerrar sesión, Yora deja de recibir ubicación en vivo de ese dispositivo.</p>

        <h2>6. Política completa</h2>
        <p>Más detalle en la <a href="/privacidad.php">Política de privacidad de Yora Delivery</a>.</p>
<?php
// Extender nav legal con enlace a esta página
require __DIR__ . '/include_legal_foot.php';
?>
