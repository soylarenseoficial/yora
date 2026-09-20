<?php
/**
 * Tasa oficial del BCV para el panel de comercios.
 *
 * La consulta la hace el servidor (una vez al dia, guardada en
 * configuracion_web) y no el navegador: asi todos los comercios ven y pagan
 * con la misma tasa, y no depende de que una API externa acepte peticiones
 * desde el navegador.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_comercio();

try {
    $bcv = yora_tasa_bcv($conexion);
    yora_json([
        'status' => 'success',
        'tasa'   => round($bcv['tasa'], 2),
        'fecha'  => $bcv['fecha'],
        'fuente' => $bcv['fuente'],
    ]);
} catch (Throwable $e) {
    error_log('tasa_bcv: ' . $e->getMessage());
    yora_fail('No se pudo consultar la tasa.');
}
