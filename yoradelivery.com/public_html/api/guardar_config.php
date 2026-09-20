<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_admin_pagina('ajustes.php', true);
yora_require_write();

try {
    $modo_mantenimiento = (isset($_POST['mantenimiento']) && $_POST['mantenimiento'] === 'true') ? 1 : 0;
    $titulo = trim((string) ($_POST['titulo'] ?? ''));
    $mensaje = trim((string) ($_POST['mensaje'] ?? ''));
    $color_fondo = trim((string) ($_POST['color_fondo'] ?? ''));
    $nav_link1 = trim((string) ($_POST['nav_link1'] ?? ''));
    $nav_link2 = trim((string) ($_POST['nav_link2'] ?? ''));
    $nav_btn = trim((string) ($_POST['nav_btn'] ?? ''));
    $hero_titulo = trim((string) ($_POST['hero_titulo'] ?? ''));
    $hero_texto = trim((string) ($_POST['hero_texto'] ?? ''));
    $hero_btn = trim((string) ($_POST['hero_btn'] ?? ''));
    $prefooter_titulo = trim((string) ($_POST['prefooter_titulo'] ?? ''));
    $prefooter_texto = trim((string) ($_POST['prefooter_texto'] ?? ''));
    $prefooter_btn1 = trim((string) ($_POST['prefooter_btn1'] ?? ''));
    $prefooter_btn2 = trim((string) ($_POST['prefooter_btn2'] ?? ''));
    $footer_about = trim((string) ($_POST['footer_about'] ?? ''));
    $play_store_url = trim((string) ($_POST['play_store_url'] ?? ''));
    $app_store_url = trim((string) ($_POST['app_store_url'] ?? ''));

    $uploadDir = __DIR__ . '/../uploads/';
    $logo_url = isset($_FILES['logo']) ? yora_upload($_FILES['logo'], $uploadDir) : null;
    $favicon_url = isset($_FILES['favicon']) ? yora_upload($_FILES['favicon'], $uploadDir) : null;
    $imagen_mant = isset($_FILES['imagen_mant']) ? yora_upload($_FILES['imagen_mant'], $uploadDir) : null;

    $sql = 'UPDATE configuracion_web SET modo_mantenimiento = ?, titulo_mantenimiento = ?, mensaje_mantenimiento = ?, color_fondo = ?, nav_link1 = ?, nav_link2 = ?, nav_btn = ?, hero_titulo = ?, hero_texto = ?, hero_btn = ?, prefooter_titulo = ?, prefooter_texto = ?, prefooter_btn1 = ?, prefooter_btn2 = ?, footer_about = ?, play_store_url = ?, app_store_url = ?';
    $types = 'issssssssssssssss';
    $params = [$modo_mantenimiento, $titulo, $mensaje, $color_fondo, $nav_link1, $nav_link2, $nav_btn, $hero_titulo, $hero_texto, $hero_btn, $prefooter_titulo, $prefooter_texto, $prefooter_btn1, $prefooter_btn2, $footer_about, $play_store_url, $app_store_url];

    if ($logo_url) {
        $sql .= ', logo_url = ?';
        $types .= 's';
        $params[] = '/uploads/' . $logo_url;
    }
    if ($favicon_url) {
        $sql .= ', favicon_url = ?';
        $types .= 's';
        $params[] = '/uploads/' . $favicon_url;
    }
    if ($imagen_mant) {
        $sql .= ', imagen_mantenimiento = ?';
        $types .= 's';
        $params[] = '/uploads/' . $imagen_mant;
    }
    $sql .= ' LIMIT 1';

    yora_exec($conexion, $sql, $types, ...$params);
    yora_json(['status' => 'success', 'mensaje' => 'Configuración guardada correctamente.']);
} catch (Throwable $e) {
    error_log('guardar_config: ' . $e->getMessage());
    yora_fail('No se pudo guardar la configuración.');
}
