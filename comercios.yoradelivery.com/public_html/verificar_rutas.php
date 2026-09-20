<?php
/**
 * VERIFICADOR TEMPORAL - BORRAR DESPUES DE USARLO
 *
 * Comprueba que el servidor puede calcular rutas por calles y que la tarifa
 * del panel es la que se va a cobrar.
 *
 * Subir a:  comercios.yoradelivery.com/public_html/verificar_rutas.php
 * Abrir:    https://comercios.yoradelivery.com/verificar_rutas.php
 * BORRARLO en cuanto termines.
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== VERIFICACION DE RUTAS Y TARIFAS - YORA ===\n\n";

echo "PHP: " . PHP_VERSION . "\n";
echo "cURL disponible: " . (function_exists('curl_init') ? 'SI' : 'NO') . "\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'SI' : 'NO') . "\n";
echo "Carpeta temporal escribible: " . (is_writable(sys_get_temp_dir()) ? 'SI (' . sys_get_temp_dir() . ')' : 'NO') . "\n\n";

if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    echo "!!! PROBLEMA: el servidor no puede hacer peticiones salientes.\n";
    echo "    Las distancias se calcularian estimadas, no por calles.\n";
    echo "    Pide al hosting que active cURL.\n\n";
}

$precio = yora_precio_km($conexion);
echo "Precio por km configurado: $" . number_format($precio, 2) . "\n";
echo "Tarifa minima: $" . number_format(YORA_TARIFA_MINIMA, 2) . "\n\n";

echo "--- PRUEBA DE RUTA REAL (Barquisimeto) ---\n";
$t0 = microtime(true);
$ruta = yora_ruta_calles(10.0645, -69.3569, 10.0450, -69.3200, true);
$ms = round((microtime(true) - $t0) * 1000);

echo "Fuente: " . strtoupper($ruta['fuente']) . ($ruta['fuente'] === 'calles' ? ' (correcto)' : ' (OSRM no respondio: revisa la salida a internet)') . "\n";
echo "Distancia: " . $ruta['km'] . " km\n";
echo "Tiempo estimado: " . $ruta['minutos'] . " min\n";
echo "Puntos para dibujar la ruta: " . (is_array($ruta['geometria']) ? count($ruta['geometria']) : 0) . "\n";
echo "Tardo: {$ms} ms\n";

$tarifa = yora_calcular_tarifa($conexion, $ruta['km']);
echo "Costo que se cobraria: $" . number_format($tarifa['costo'], 2)
   . "  (" . $ruta['km'] . " km x " . $precio . ")\n\n";

echo "--- COMERCIOS CON COORDENADAS DESCUADRADAS ---\n";
$filas = yora_all($conexion, 'SELECT id, nombre, lat, lng, latitud, longitud FROM comercios');
$problemas = 0;
foreach ($filas as $r) {
    $a_ok = yora_coords_ok((float) $r['latitud'], (float) $r['longitud']);
    $b_ok = yora_coords_ok((float) $r['lat'], (float) $r['lng']);

    if (!$a_ok && !$b_ok) {
        echo "  [SIN GPS] #{$r['id']} {$r['nombre']} -> no puede pedir envios\n";
        $problemas++;
        continue;
    }
    if ($a_ok && $b_ok) {
        $dif = yora_haversine((float) $r['latitud'], (float) $r['longitud'], (float) $r['lat'], (float) $r['lng']);
        if ($dif > 0.2) {
            echo "  [DESCUADRE] #{$r['id']} {$r['nombre']} -> los dos puntos guardados estan a "
               . number_format($dif, 2) . " km. Usando latitud/longitud. Reubica el local en Configuracion.\n";
            $problemas++;
        }
    }
}
if ($problemas === 0) {
    echo "  Todo correcto.\n";
}

echo "\n=== FIN. BORRA ESTE ARCHIVO DEL SERVIDOR. ===\n";
