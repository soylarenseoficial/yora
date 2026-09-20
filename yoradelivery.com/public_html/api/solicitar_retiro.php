<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
yora_fail('Este endpoint ya no está disponible. Usa la app de conductores.', 403);
