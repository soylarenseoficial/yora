<?php
/**
 * Política de privacidad — Yora Delivery / YoraDriver (Google Play).
 * URL pública: https://yoradelivery.com/privacidad.php
 */
$legalNav = 'privacidad';
$pageTitle = 'Política de privacidad';
$pageDesc = 'Cómo Yora Delivery trata los datos de conductores, comercios y usuarios de la plataforma.';
require __DIR__ . '/include_legal_head.php';
?>
        <p class="meta">Última actualización: 19 de septiembre de 2026 · Responsable: Yora Delivery (Barquisimeto, Venezuela)</p>

        <h2>1. Quiénes somos</h2>
        <p>Yora Delivery opera una plataforma de logística y delivery en Barquisimeto. Esta política aplica a:</p>
        <ul>
            <li>El sitio web <strong>yoradelivery.com</strong> y paneles asociados.</li>
            <li>La aplicación móvil <strong>YoraDriver</strong> (paquete <code>com.yoradelivery.driver</code>) para conductores.</li>
            <li>Servicios relacionados para comercios y clientes dentro de la red Yora.</li>
        </ul>

        <h2>2. Datos que tratamos</h2>
        <p>Según el tipo de usuario, podemos tratar:</p>
        <ul>
            <li><strong>Identificación y contacto:</strong> nombre, cédula, correo, teléfono.</li>
            <li><strong>Cuenta y acceso:</strong> credenciales (contraseña almacenada de forma cifrada/hash), tokens de sesión.</li>
            <li><strong>Documentos de verificación (conductores):</strong> fotos de cédula, licencia, certificado médico, vehículo, póliza y similares, solo para aprobar o rechazar el expediente.</li>
            <li><strong>Ubicación (conductores en línea):</strong> coordenadas GPS mientras el conductor está Online, para asignar viajes y mostrar la flota al panel operativo.</li>
            <li><strong>Datos de viajes y pagos:</strong> direcciones de recogida/entrega, montos de ganancia, historial de entregas, datos de pago móvil para retiros.</li>
            <li><strong>Dispositivo y notificaciones:</strong> identificadores de push (p. ej. OneSignal) para avisar viajes y mensajes del sistema.</li>
            <li><strong>Soporte:</strong> mensajes que nos envíes por WhatsApp, correo o formularios.</li>
        </ul>

        <h2>3. Para qué usamos los datos</h2>
        <ul>
            <li>Crear y gestionar cuentas de conductor, comercio o cliente.</li>
            <li>Verificar identidad y documentos de conductores.</li>
            <li>Operar el radar de viajes, GPS en tiempo real y seguimiento de entregas.</li>
            <li>Calcular ganancias, billetera y retiros.</li>
            <li>Enviar notificaciones operativas (nuevo viaje, estado, seguridad).</li>
            <li>Prevenir fraude, abuso y acceso no autorizado (sesión única por cuenta).</li>
            <li>Cumplir obligaciones legales y responder a autoridades cuando corresponda.</li>
        </ul>

        <h2>4. Ubicación y cámara (app YoraDriver)</h2>
        <ul>
            <li><strong>Ubicación:</strong> se usa cuando el conductor se pone Online o durante un viaje activo. Puede continuar en segundo plano mediante un servicio en primer plano mientras esté Online, para que el panel y el sistema sepan su posición. Al cerrar sesión o ponerse Offline, se deja de enviar ubicación y se marca Offline.</li>
            <li><strong>Cámara / galería:</strong> se usa para foto de perfil, documentos de verificación y evidencias de entrega cuando el flujo lo requiere.</li>
        </ul>
        <p>No vendemos tu ubicación a terceros con fines publicitarios.</p>

        <h2>5. Base y conservación</h2>
        <p>Tratamos los datos para ejecutar el servicio que solicitaste (contrato/relación comercial), por seguridad legítima de la plataforma y, cuando aplique, por obligación legal. Conservamos la información mientras la cuenta esté activa y el tiempo necesario para operaciones, auditorías y reclamos. Puedes pedir corrección o baja escribiendo a soporte.</p>

        <h2>6. Con quién compartimos información</h2>
        <ul>
            <li><strong>Comercios y operación Yora:</strong> datos necesarios para cumplir pedidos (p. ej. estado del viaje, ubicación aproximada del conductor en operación).</li>
            <li><strong>Proveedores técnicos:</strong> hosting, correo, mapas/tiles, notificaciones push, en la medida necesaria para prestar el servicio.</li>
            <li><strong>Autoridades:</strong> solo si la ley lo exige.</li>
        </ul>
        <p>No vendemos bases de datos personales.</p>

        <h2>7. Seguridad</h2>
        <p>Aplicamos medidas razonables: HTTPS, hash de contraseñas, tokens de sesión, control de acceso al panel HQ y registro de eventos críticos. Ningún sistema es 100&nbsp;% seguro; te pedimos no compartir tu clave y cambiarla si sospechas acceso indebido.</p>

        <h2>8. Tus derechos</h2>
        <p>Puedes solicitar acceso, actualización o eliminación de datos de cuenta (salvo lo que debamos conservar por ley o disputas). Contacto:</p>
        <ul>
            <li>WhatsApp soporte: <a href="https://wa.me/584225097031">+58 422-5097031</a></li>
            <li>Correo: <a href="mailto:yoradelivery@gmail.com">yoradelivery@gmail.com</a></li>
            <li>Página: <a href="/soporte-publico.php">Soporte Yora</a></li>
        </ul>

        <h2>9. Menores</h2>
        <p>YoraDriver está dirigida a conductores adultos (mayoría de edad / requisitos de la plataforma, p. ej. 21+ según el registro). No está pensada para niños.</p>

        <h2>10. Cambios</h2>
        <p>Podemos actualizar esta política. Publicaremos la fecha de actualización en esta misma URL. El uso continuado de la app o el sitio implica conocimiento de la versión vigente.</p>

        <p style="margin-top:22px"><a class="cta" href="/soporte-publico.php">Contactar soporte</a></p>
<?php require __DIR__ . '/include_legal_foot.php'; ?>
