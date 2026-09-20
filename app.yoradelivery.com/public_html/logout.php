<?php
require_once __DIR__ . '/../config.php';

// Al salir se borra el token para que el proximo login entre limpio.
if (!empty($_SESSION['conductor_id'])) {
    try {
        yora_conductor_cerrar_sesion($conexion, (int) $_SESSION['conductor_id']);
    } catch (Throwable $e) {
        error_log('logout driver: ' . $e->getMessage());
    }
}

yora_logout('index.php');
