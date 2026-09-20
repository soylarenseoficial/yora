<?php
/**
 * Banners / anuncios fullscreen para YoraDriver (APK).
 * Misma tabla banners_app que administra HQ → banners.php
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

try {
    $conexion->query(
        "CREATE TABLE IF NOT EXISTS banners_app (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(140) NOT NULL DEFAULT '',
            cuerpo TEXT NULL,
            imagen_url VARCHAR(500) NOT NULL DEFAULT '',
            boton_texto VARCHAR(80) NOT NULL DEFAULT '',
            boton_url VARCHAR(500) NOT NULL DEFAULT '',
            audiencia ENUM('drivers','comercios','clientes','todos') NOT NULL DEFAULT 'drivers',
            activo TINYINT(1) NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
}

$rows = [];
try {
    $rows = yora_all(
        $conexion,
        "SELECT id, titulo, cuerpo, imagen_url, boton_texto, boton_url, orden
         FROM banners_app
         WHERE activo = 1 AND audiencia IN ('drivers','todos')
         ORDER BY orden ASC, id DESC
         LIMIT 8"
    ) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

$banners = [];
foreach ($rows as $bn) {
    $img = trim((string) ($bn['imagen_url'] ?? ''));
    if ($img !== '' && !preg_match('#^https?://#i', $img)) {
        $img = 'https://yoradelivery.com/' . ltrim($img, '/');
    }
    $banners[] = [
        'id' => (int) $bn['id'],
        'titulo' => (string) ($bn['titulo'] ?? ''),
        'cuerpo' => (string) ($bn['cuerpo'] ?? ''),
        'imagen' => $img,
        'boton' => (string) ($bn['boton_texto'] ?? ''),
        'url' => trim((string) ($bn['boton_url'] ?? '')),
    ];
}

yora_json([
    'ok' => true,
    'banners' => $banners,
], 200);
