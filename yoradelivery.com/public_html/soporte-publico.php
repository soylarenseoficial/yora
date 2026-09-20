<?php
/**
 * Soporte / contacto público — Play Store y app.
 * URL: https://yoradelivery.com/soporte-publico.php
 * (nombre distinto de hq.../soporte.php del panel interno)
 */
$legalNav = 'soporte';
$pageTitle = 'Soporte Yora';
$pageDesc = 'Contacto y ayuda para conductores YoraDriver, comercios y usuarios.';
require __DIR__ . '/include_legal_head.php';

$wa = '584225097031';
$waTxt = '+58 422-5097031';
$mail = 'yoradelivery@gmail.com';
?>
        <p class="meta">Atención en Barquisimeto · Horario orientativo: lun–sáb 9:00–18:00 (hora Venezuela)</p>

        <h2>Contacto rápido</h2>
        <ul>
            <li><strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo $wa; ?>?text=Hola%2C%20necesito%20soporte%20de%20Yora"><?php echo htmlspecialchars($waTxt, ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li><strong>Correo:</strong> <a href="mailto:<?php echo htmlspecialchars($mail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mail, ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li><strong>Web:</strong> <a href="https://yoradelivery.com/">yoradelivery.com</a></li>
        </ul>
        <p><a class="cta" href="https://wa.me/<?php echo $wa; ?>?text=Hola%2C%20soy%20conductor%20YoraDriver%20y%20necesito%20ayuda">Escribir por WhatsApp</a></p>

        <h2>YoraDriver (conductores)</h2>
        <ul>
            <li>No puedo iniciar sesión / código OTP</li>
            <li>Verificación de documentos</li>
            <li>Problemas con Online, GPS o viajes</li>
            <li>Billetera y retiros</li>
            <li>Cambio de teléfono (solo por soporte, por seguridad)</li>
        </ul>
        <p>Indica tu <strong>cédula</strong> o correo de la cuenta para agilizar.</p>

        <h2>Comercios</h2>
        <p>Afiliación, recargas y operación del panel: usa el mismo WhatsApp o el registro en <a href="/registroscomercio.php">Unir mi local</a>.</p>

        <h2>Documentos legales</h2>
        <ul>
            <li><a href="/privacidad.php">Política de privacidad</a></li>
            <li><a href="/terminos.php">Términos y condiciones</a></li>
            <li><a href="/android/">Descarga / instalación Android</a></li>
        </ul>

        <h2>Datos para Google Play</h2>
        <p>Nombre del desarrollador / marca: <strong>Yora Delivery</strong><br>
        App: <strong>Yora Delivery Driver</strong> (<code>com.yoradelivery.driver</code>)<br>
        Correo de contacto: <strong><?php echo htmlspecialchars($mail, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        Política de privacidad: <strong>https://yoradelivery.com/privacidad.php</strong></p>
<?php require __DIR__ . '/include_legal_foot.php'; ?>
