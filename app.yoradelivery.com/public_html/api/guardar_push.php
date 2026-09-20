<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor();

$tipo = trim((string) ($_POST['tipo'] ?? 'web'));
try {
    if ($tipo === 'onesignal') {
        $ext = trim((string) ($_POST['external_id'] ?? ('yora-driver-' . $conductor_id)));
        $player = trim((string) ($_POST['player_id'] ?? $ext));
        $sub = trim((string) ($_POST['subscription_id'] ?? ''));
        if ($ext === '') {
            yora_fail('Suscripción incompleta.');
        }
        yora_push_guardar($conexion, $conductor_id, 'onesignal:' . $ext, $player !== '' ? $player : 'os', ($sub !== '' ? $sub : 'os'));
        yora_json(['status' => 'success', 'canal' => 'onesignal']);
    }

    $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
    $p256dh = trim((string) ($_POST['p256dh'] ?? ''));
    $auth = trim((string) ($_POST['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        yora_fail('Suscripción incompleta.');
    }
    yora_push_guardar($conexion, $conductor_id, $endpoint, $p256dh, $auth);
    yora_json(['status' => 'success', 'canal' => 'web']);
} catch (Throwable $e) {
    error_log('guardar_push: ' . $e->getMessage());
    yora_fail('No se pudo guardar la suscripción.');
}
