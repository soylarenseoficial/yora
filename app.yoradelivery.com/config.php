<?php
/**
 * =====================================================================
 *  CONFIGURACIÓN CENTRAL DE BASE DE DATOS - YORA APP
 * =====================================================================
 *  Este archivo vive FUERA de "public_html" a propósito: en un hosting
 *  normal (cPanel, Plesk, etc.) todo lo que está fuera de public_html
 *  NO es accesible por navegador, así que las credenciales no quedan
 *  expuestas aunque alguien adivine la ruta.
 *
 *  ANTES SE HACÍA: cada archivo .php tenía su propia copia de usuario
 *  y contraseña de la base de datos (34 archivos en todo el proyecto).
 *  AHORA: todos los archivos llaman a este único lugar.
 *
 *  ⚠️ ACCIÓN PENDIENTE PARA TI:
 *  1. Entra a tu panel de hosting (phpMyAdmin / MySQL Databases) y
 *     CAMBIA la contraseña de la base de datos, porque la anterior
 *     quedó guardada en el código fuente en 34 archivos distintos.
 *  2. Cuando la cambies, reemplaza el valor de YORA_DB_PASS más abajo
 *     (o, mejor aún, configúrala como variable de entorno en tu
 *     hosting si lo permite, y no la escribas aquí en texto plano).
 * =====================================================================
 */

// Si tu hosting permite variables de entorno, se usarán automáticamente.
// Si no, se usa el valor de respaldo que está a la derecha del "?:".
$host = getenv('YORA_DB_HOST') ?: 'localhost';
$db   = getenv('YORA_DB_NAME') ?: '25951632071123';
$user = getenv('YORA_DB_USER') ?: '07112325951632';
$pass = getenv('YORA_DB_PASS') ?: 'N3s8yyjrAYacB8Xd';

// Evita que mysqli lance excepciones con detalles internos del servidor
mysqli_report(MYSQLI_REPORT_OFF);

$conexion = new mysqli($host, $user, $pass, $db);

if ($conexion->connect_error) {
    // El detalle real del error queda en el log del servidor, nunca en pantalla
    error_log('YORA APP - Error de conexión a BD: ' . $conexion->connect_error);
    http_response_code(500);
    die('Error interno del servidor. Intenta de nuevo en unos minutos.');
}

$conexion->set_charset('utf8mb4');

// SMTP (mismo correo que yoradelivery.com). Sin esto OTP falla con "Could not authenticate".
$GLOBALS['YORA_SMTP_HOST'] = getenv('YORA_SMTP_HOST') ?: 'smtp.gmail.com';
$GLOBALS['YORA_SMTP_USER'] = getenv('YORA_SMTP_USER') ?: 'yoradelivery@gmail.com';
$GLOBALS['YORA_SMTP_PASS'] = getenv('YORA_SMTP_PASS') ?: 'uxmixscjsazbjlnr';
$GLOBALS['YORA_SMTP_PORT'] = (int) (getenv('YORA_SMTP_PORT') ?: 465);
$YORA_SMTP_HOST = $GLOBALS['YORA_SMTP_HOST'];
$YORA_SMTP_USER = $GLOBALS['YORA_SMTP_USER'];
$YORA_SMTP_PASS = $GLOBALS['YORA_SMTP_PASS'];
$YORA_SMTP_PORT = $GLOBALS['YORA_SMTP_PORT'];

require_once __DIR__ . '/seguridad.php';
yora_boot();
