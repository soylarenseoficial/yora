<?php
/**
 * Arranque. En public_html deben estar: config.php, seguridad.php y yora_push.php
 */
if (defined('YORA_CONFIG_LOADED')) {
    return;
}
define('YORA_CONFIG_LOADED', true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$falta = [];
foreach (['config.php', 'seguridad.php', 'yora_push.php'] as $f) {
    if (!is_file(__DIR__ . '/' . $f)) {
        $falta[] = $f;
    }
}
if ($falta) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Faltan archivos en public_html</h1><ul>';
    foreach ($falta as $f) {
        echo '<li><b>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</b></li>';
    }
    echo '</ul><p>Súbelos a la misma carpeta que index.php (no a inc, no un nivel arriba).</p>';
    exit;
}

require_once __DIR__ . '/config.php';
