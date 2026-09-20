<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_conductor();
$vapid = yora_vapid_asegurar($conexion);
yora_json(['status' => 'success', 'publicKey' => $vapid['public'] ?? '']);
