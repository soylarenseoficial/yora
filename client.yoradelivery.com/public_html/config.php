<?php
/**
 * Config de client.yoradelivery.com. Vive junto a seguridad.php y yora_push.php.
 */
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'config.php') {
    http_response_code(404);
    exit;
}

$host = getenv('YORA_DB_HOST') ?: 'localhost';
$db   = getenv('YORA_DB_NAME') ?: '25951632071123';
$user = getenv('YORA_DB_USER') ?: '07112325951632';
$pass = getenv('YORA_DB_PASS') ?: 'N3s8yyjrAYacB8Xd';

mysqli_report(MYSQLI_REPORT_OFF);
$conexion = new mysqli($host, $user, $pass, $db);
if ($conexion->connect_error) {
    error_log('YORA CLIENT - Error de conexión a BD: ' . $conexion->connect_error);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>No pude conectar a la base de datos</h1>';
    echo '<pre>' . htmlspecialchars($conexion->connect_error, ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}
$conexion->set_charset('utf8mb4');

require_once __DIR__ . '/seguridad.php';
yora_boot();
