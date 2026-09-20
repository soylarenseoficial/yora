<?php
/**
 * Historial de viajes entregados del conductor (APK).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$q = trim((string) ($_GET['q'] ?? ''));
$limit = min(80, max(10, (int) ($_GET['limit'] ?? 40)));

$sql = "SELECT c.id, c.codigo, c.costo_delivery, c.fecha_entrega, c.fecha_creacion,
               c.cliente_nombre, c.pagado_al_driver,
               COALESCE(NULLIF(r.nombre, ''), NULLIF(c.cliente_nombre, ''), 'Mandadito') AS restaurante,
               r.logo_url
        FROM comandas c
        LEFT JOIN comercios r ON c.comercio_id = r.id
        WHERE c.conductor_id = ? AND c.estatus = 'Entregado'";
$types = 'i';
$params = [$conductor_id];

if ($q !== '') {
    $sql .= ' AND (c.codigo LIKE ? OR r.nombre LIKE ? OR c.cliente_nombre LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY COALESCE(c.fecha_entrega, c.fecha_creacion) DESC, c.id DESC LIMIT ' . (int) $limit;

$rows = yora_all($conexion, $sql, $types, ...$params) ?: [];
$viajes = [];
foreach ($rows as $v) {
    $logo = yora_url_archivo(
        $v['logo_url'] ?? '',
        'https://cdn-icons-png.flaticon.com/512/3075/3075977.png',
        'https://comercios.yoradelivery.com'
    );
    $fechaRaw = (string) ($v['fecha_entrega'] ?: $v['fecha_creacion'] ?: '');
    $ts = $fechaRaw !== '' ? strtotime($fechaRaw) : false;
    $fechaTxt = $ts ? date('j F Y • g:i A', $ts) : '';
    $fechaTxt = str_replace(
        ['January','February','March','April','May','June','July','August','September','October','November','December'],
        ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'],
        $fechaTxt
    );
    $viajes[] = [
        'id' => (int) $v['id'],
        'codigo' => (string) ($v['codigo'] ?? ('ID' . $v['id'])),
        'restaurante' => (string) ($v['restaurante'] ?? 'Comercio'),
        'logo' => $logo,
        'ganancia' => round((float) ($v['costo_delivery'] ?? 0), 2),
        'fecha' => $fechaTxt,
        'fecha_raw' => $fechaRaw,
        'cliente' => (string) ($v['cliente_nombre'] ?? ''),
        'pagado' => (int) ($v['pagado_al_driver'] ?? 0) === 1,
    ];
}

yora_json([
    'ok' => true,
    'total' => count($viajes),
    'viajes' => $viajes,
], 200);
