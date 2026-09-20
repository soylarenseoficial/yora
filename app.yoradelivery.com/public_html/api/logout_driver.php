<?php
/**
 * Cierra sesión nativa: invalida token y fuerza Offline (sale del mapa HQ).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yora_json(['ok' => false, 'mensaje' => 'Método no permitido'], 405);
}

$conductor_id = yora_require_conductor_api();
yora_conductor_cerrar_sesion($conexion, $conductor_id);
// Refuerzo por si la columna en_linea falló en algún entorno viejo.
yora_exec($conexion, 'UPDATE conductores SET en_linea = 0 WHERE id = ?', 'i', $conductor_id);
$_SESSION = [];

yora_json(['ok' => true, 'mensaje' => 'Sesión cerrada', 'en_linea' => 0], 200);
