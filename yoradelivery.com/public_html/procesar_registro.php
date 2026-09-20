<?php
require_once __DIR__ . '/../config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}
if (!yora_verify_same_origin() && !yora_csrf_ok()) {
    header('Location: index.php?registro=error');
    exit;
}
if (!yora_rate_limit('lead:' . yora_client_ip(), 8, 3600)) {
    header('Location: index.php?registro=error');
    exit;
}

$tipo = $_POST['tipo_registro'] ?? '';
$whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
if ($whatsapp === '' || mb_strlen($whatsapp) > 30 || !in_array($tipo, ['comercio', 'conductor'], true)) {
    header('Location: index.php?registro=error');
    exit;
}

try {
    if ($tipo === 'comercio') {
        $nombre = trim((string) ($_POST['nombre_local'] ?? ''));
        if ($nombre === '') {
            header('Location: index.php?registro=error');
            exit;
        }
        yora_exec($conexion, "INSERT INTO solicitudes_comercios (nombre_local, whatsapp, fecha, estado) VALUES (?, ?, NOW(), 'pendiente')", 'ss', $nombre, $whatsapp);
    } else {
        $nombre = trim((string) ($_POST['nombre_conductor'] ?? ''));
        $vehiculo = trim((string) ($_POST['tipo_vehiculo'] ?? ''));
        if ($nombre === '') {
            header('Location: index.php?registro=error');
            exit;
        }
        yora_exec($conexion, "INSERT INTO solicitudes_conductores (nombre, whatsapp, vehiculo, fecha, estado) VALUES (?, ?, ?, NOW(), 'pendiente')", 'sss', $nombre, $whatsapp, $vehiculo);
    }
    header('Location: index.php?registro=exito');
} catch (Throwable $e) {
    error_log('procesar_registro: ' . $e->getMessage());
    header('Location: index.php?registro=error');
}
exit;
