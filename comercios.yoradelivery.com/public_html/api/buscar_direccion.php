<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();

if (empty($_SESSION['comercio_id']) && empty($_SESSION['usuario_id']) && empty($_SESSION['conductor_id']) && empty($_SESSION['admin_logged'])) {
    yora_fail('Sesión expirada.', 401);
}

$q = trim((string) ($_POST['q'] ?? $_POST['texto'] ?? ''));
if (mb_strlen($q) < 3) {
    yora_fail('Escribe al menos 3 caracteres.');
}
if (!yora_rate_limit('geo:' . (yora_client_ip()), 45, 60)) {
    yora_fail('Demasiadas búsquedas. Espera un momento.');
}

$items = yora_buscar_direccion_lara($q);
yora_json(['status' => 'success', 'resultados' => $items]);
