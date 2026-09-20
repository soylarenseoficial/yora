<?php
require_once __DIR__ . '/../yora_cargar.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
yora_require_usuario();
$q = trim((string) ($_POST['q'] ?? ''));
if (mb_strlen($q) < 3) {
    yora_fail('Escribe al menos 3 caracteres.');
}
if (!yora_rate_limit('geo-u:' . yora_client_ip(), 45, 60)) {
    yora_fail('Demasiadas búsquedas. Espera un momento.');
}
yora_json(['status' => 'success', 'resultados' => yora_buscar_direccion_lara($q)]);
