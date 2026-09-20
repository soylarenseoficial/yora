<?php
/**
 * Club / Nivel del conductor (Bronce → Diamante).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$fila = yora_one(
    $conexion,
    "SELECT COUNT(id) AS total FROM comandas WHERE conductor_id = ? AND estatus = 'Entregado'",
    'i',
    $conductor_id
);
$total = (int) ($fila['total'] ?? 0);

$niveles = [
    [
        'nombre' => 'Bronce',
        'desde' => 0,
        'color' => '#b45309',
        'fondo' => '#fef3c7',
        'beneficio' => 'Nivel inicial. Tasa estándar de ganancias.',
    ],
    [
        'nombre' => 'Plata',
        'desde' => 50,
        'color' => '#475569',
        'fondo' => '#e2e8f0',
        'beneficio' => 'Radar prioritario: los pedidos te suenan 2 segundos antes.',
    ],
    [
        'nombre' => 'Oro',
        'desde' => 200,
        'color' => '#ca8a04',
        'fondo' => '#fef08a',
        'beneficio' => 'Cero comisiones de retiro y soporte preferencial Yora.',
    ],
    [
        'nombre' => 'Diamante',
        'desde' => 500,
        'color' => '#0284c7',
        'fondo' => '#e0f2fe',
        'beneficio' => 'Uniforme gratis, viajes VIP y cero comisiones de retiro.',
    ],
];

$actual = $niveles[0];
$indice = 0;
foreach ($niveles as $i => $n) {
    if ($total >= (int) $n['desde']) {
        $actual = $n;
        $indice = $i;
    }
}
$siguiente = $niveles[$indice + 1] ?? null;
$meta = $siguiente ? (int) $siguiente['desde'] : (int) $actual['desde'];
$porcentaje = $siguiente ? (int) round(min(100, ($total / max(1, $meta)) * 100)) : 100;
$faltan = $siguiente ? max(0, $meta - $total) : 0;

$list = [];
foreach ($niveles as $i => $n) {
    $list[] = [
        'nombre' => $n['nombre'],
        'desde' => (int) $n['desde'],
        'color' => $n['color'],
        'fondo' => $n['fondo'],
        'beneficio' => $n['beneficio'],
        'actual' => $i === $indice,
        'logrado' => $total >= (int) $n['desde'],
    ];
}

yora_json([
    'ok' => true,
    'viajes_entregados' => $total,
    'nivel' => $actual['nombre'],
    'color' => $actual['color'],
    'fondo' => $actual['fondo'],
    'beneficio' => $actual['beneficio'],
    'porcentaje' => $porcentaje,
    'faltan' => $faltan,
    'siguiente' => $siguiente['nombre'] ?? null,
    'niveles' => $list,
], 200);
