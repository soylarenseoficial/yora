<?php
header('Content-Type: text/plain; charset=utf-8');
echo "YORA PING\nPHP " . PHP_VERSION . "\n";
foreach (['config.php', 'seguridad.php', 'yora_push.php', 'yora_cargar.php', 'index.php'] as $f) {
    echo $f . ' => ' . (is_file(__DIR__ . '/' . $f) ? 'OK' : 'FALTA') . "\n";
}
echo "ok\n";
