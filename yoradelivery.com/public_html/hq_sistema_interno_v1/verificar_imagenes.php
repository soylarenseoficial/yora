<?php
/**
 * DIAGNOSTICO DE IMAGENES Y DOCUMENTOS - YORA
 *
 * Comprueba de una sola pasada por que una imagen no abre: si el .htaccess
 * de uploads esta rompiendo el directorio, si el archivo no existe en disco,
 * si existe pero sin permiso de lectura, o si la URL guardada en la base de
 * datos apunta a un sitio equivocado.
 *
 * Abrir:  https://yoradelivery.com/hq_sistema_interno_v1/verificar_imagenes.php
 * Borrar del servidor cuando termines de revisar.
 */
require_once 'conexion.php';
yora_require_admin_pagina('verificar_imagenes.php');
header('Content-Type: text/plain; charset=utf-8');

$ok = 0;
$fallos = 0;

function titulo(string $t): void
{
    echo "\n" . str_repeat('=', 70) . "\n" . $t . "\n" . str_repeat('=', 70) . "\n";
}

/** Comprueba una URL publica y devuelve el codigo HTTP (0 si no hubo respuesta). */
function codigo_http(string $url): int
{
    if (!function_exists('curl_init')) {
        return -1;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'YoraDiagnostico/1.0',
    ]);
    curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $codigo;
}

function explicar(int $codigo): string
{
    switch (true) {
        case $codigo === 200: return 'OK';
        case $codigo === 403: return 'PROHIBIDO -> permisos del archivo o de la carpeta';
        case $codigo === 404: return 'NO EXISTE -> la URL guardada en la base de datos no coincide con el archivo';
        case $codigo === 500: return 'ERROR 500 -> el .htaccess de esa carpeta tiene una directiva que el servidor no admite';
        case $codigo === -1:  return 'no se pudo comprobar (cURL desactivado en el servidor)';
        case $codigo === 0:   return 'sin respuesta (DNS, SSL o firewall)';
        default: return 'codigo inesperado';
    }
}

echo "DIAGNOSTICO DE IMAGENES Y DOCUMENTOS - YORA\n";
echo 'Fecha: ' . date('Y-m-d H:i:s') . "\n";

titulo('1. COMO CORRE PHP EN ESTE SERVIDOR');
echo 'Version de PHP: ' . PHP_VERSION . "\n";
echo 'Interfaz (SAPI): ' . PHP_SAPI . "\n";
$es_modulo = (PHP_SAPI === 'apache2handler');
echo 'Modo: ' . ($es_modulo ? "mod_php (php_flag SI funciona en .htaccess)\n" : "FastCGI / PHP-FPM / LiteSpeed\n");
if (!$es_modulo) {
    echo "  -> Con este modo, un \"php_flag\" suelto en un .htaccess hace que\n";
    echo "     Apache devuelva 500 en TODA esa carpeta. Es la causa tipica de\n";
    echo "     que las imagenes de /uploads/ dejen de abrir.\n";
}
echo 'Tamano maximo de subida: ' . ini_get('upload_max_filesize') . ' (post_max_size: ' . ini_get('post_max_size') . ")\n";
echo 'Extension fileinfo (necesaria para validar el tipo): ' . (class_exists('finfo') ? "SI\n" : "NO -> ninguna subida sera aceptada\n");

titulo('2. CARPETAS DE SUBIDA');
$carpetas = [
    'yoradelivery.com'          => __DIR__ . '/../uploads/',
    'comercios.yoradelivery.com' => yora_dir_uploads_dominio('comercios.yoradelivery.com'),
    'app.yoradelivery.com'      => yora_dir_uploads_dominio('app.yoradelivery.com'),
];
foreach ($carpetas as $dominio => $dir) {
    echo "\n[$dominio]\n";
    if ($dir === null) {
        echo "  NO LOCALIZADA desde este dominio (normal si cada sitio esta aislado).\n";
        continue;
    }
    echo '  Ruta: ' . $dir . "\n";
    if (!is_dir($dir)) {
        echo "  NO EXISTE -> creala y dale permisos 755.\n";
        $fallos++;
        continue;
    }
    $permisos = substr(sprintf('%o', fileperms($dir)), -4);
    echo '  Permisos: ' . $permisos . ' | escribible: ' . (is_writable($dir) ? 'SI' : 'NO') . "\n";
    if (!is_writable($dir)) {
        echo "  PROBLEMA: PHP no puede guardar archivos nuevos aqui.\n";
        $fallos++;
    }

    $htaccess = rtrim($dir, '/\\') . '/.htaccess';
    if (!is_file($htaccess)) {
        echo "  .htaccess: no hay (aceptable, pero sin proteccion extra).\n";
    } else {
        $contenido = (string) file_get_contents($htaccess);
        $suelto = preg_match('/^\s*php_(flag|value|admin_flag|admin_value)\b/mi', $contenido)
            && !preg_match('/<IfModule\s+mod_php/i', $contenido);
        if ($suelto && !$es_modulo) {
            echo "  .htaccess: PELIGRO -> tiene php_flag fuera de <IfModule>. Esto\n";
            echo "             provoca el error 500 en las imagenes. Reemplazalo.\n";
            $fallos++;
        } else {
            echo "  .htaccess: correcto.\n";
        }
    }
}

titulo('3. DOCUMENTOS DE CONDUCTORES');
$columnas = ['foto_carnet', 'foto_licencia', 'foto_certificado', 'foto_circulacion', 'foto_origen', 'foto_rcv', 'foto_vehiculo', 'foto_perfil', 'documento_url'];
$existentes = [];
$res = $conexion->query('SHOW COLUMNS FROM conductores');
while ($res && $c = $res->fetch_assoc()) {
    if (in_array($c['Field'], $columnas, true)) {
        $existentes[] = $c['Field'];
    }
}

$lista = implode(', ', $existentes);
$filas = $existentes ? yora_all($conexion, "SELECT id, nombre, {$lista} FROM conductores ORDER BY id DESC LIMIT 40") : [];
$base_local = realpath(__DIR__ . '/../uploads');

foreach ($filas as $fila) {
    $lineas = [];
    foreach ($existentes as $campo) {
        $valor = trim((string) ($fila[$campo] ?? ''));
        if ($valor === '') {
            continue;
        }
        $url = yora_url_archivo($valor);
        if ($url === '') {
            $lineas[] = "    $campo: valor invalido en la base de datos -> '$valor'";
            $fallos++;
            continue;
        }
        if ($url[0] === '/') {
            // Archivo del propio dominio: se comprueba directamente en disco.
            $fisico = $base_local . '/' . basename($url);
            if (!is_file($fisico)) {
                $lineas[] = "    $campo: FALTA EN DISCO -> $fisico";
                $fallos++;
            } elseif (!is_readable($fisico)) {
                $lineas[] = "    $campo: SIN PERMISO DE LECTURA (" . substr(sprintf('%o', fileperms($fisico)), -4) . ") -> $fisico";
                $fallos++;
            } else {
                $codigo = codigo_http('https://' . ($_SERVER['HTTP_HOST'] ?? 'yoradelivery.com') . $url);
                if ($codigo === 200 || $codigo === -1) {
                    $ok++;
                } else {
                    $lineas[] = "    $campo: HTTP $codigo (" . explicar($codigo) . ") -> $url";
                    $fallos++;
                }
            }
        } else {
            $codigo = codigo_http($url);
            if ($codigo === 200 || $codigo === -1) {
                $ok++;
            } else {
                $lineas[] = "    $campo: HTTP $codigo (" . explicar($codigo) . ") -> $url";
                $fallos++;
            }
        }
    }
    if ($lineas) {
        echo "\n  #{$fila['id']} {$fila['nombre']}\n" . implode("\n", $lineas) . "\n";
    }
}

titulo('4. EVIDENCIAS DE ENTREGA (fotos que suben los conductores)');
$comandas = yora_all($conexion, "SELECT id, foto_recogida, foto_entrega FROM comandas WHERE foto_recogida <> '' OR foto_entrega <> '' ORDER BY id DESC LIMIT 40");
if (!$comandas) {
    echo "  No hay ninguna comanda con evidencias guardadas todavia.\n";
}
foreach ($comandas as $c) {
    foreach (['foto_recogida', 'foto_entrega'] as $campo) {
        $url = yora_url_archivo($c[$campo] ?? '');
        if ($url === '') {
            continue;
        }
        $codigo = codigo_http($url);
        if ($codigo === 200 || $codigo === -1) {
            $ok++;
        } else {
            echo "  Pedido #{$c['id']} $campo: HTTP $codigo (" . explicar($codigo) . ")\n    $url\n";
            $fallos++;
        }
    }
}

titulo('5. LOGOS DE COMERCIOS');
$comercios = yora_all($conexion, "SELECT id, nombre, logo_url FROM comercios WHERE logo_url <> '' ORDER BY id DESC LIMIT 40");
foreach ($comercios as $r) {
    $url = yora_url_archivo($r['logo_url'], '', 'https://comercios.yoradelivery.com');
    if ($url === '' || strpos($url, 'cdn-icons-png.flaticon.com') !== false) {
        continue;
    }
    $codigo = codigo_http($url);
    if ($codigo === 200 || $codigo === -1) {
        $ok++;
    } else {
        echo "  #{$r['id']} {$r['nombre']}: HTTP $codigo (" . explicar($codigo) . ")\n    $url\n";
        $fallos++;
    }
}

titulo('RESULTADO');
echo "Archivos comprobados correctos: $ok\n";
echo "Problemas encontrados: $fallos\n";
if ($fallos === 0) {
    echo "\nTodo en orden. Ya puedes borrar este archivo del servidor.\n";
} else {
    echo "\nRevisa cada linea de arriba: dice exactamente que falla en cada archivo.\n";
}
