<?php
/**
 * Agenda de clientes del comercio.
 *
 * Busca por cedula dentro de ESTE comercio unicamente: cada local tiene su
 * propia cartera y nunca ve los clientes de otro. Devuelve nombre, telefono y
 * referencia para rellenar el formulario; la ubicacion NO se guarda, porque el
 * mismo cliente puede pedir a direcciones distintas.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();

$cedula = trim((string) ($_POST['cedula'] ?? $_GET['cedula'] ?? ''));
if ($cedula === '' || mb_strlen($cedula) > 30) {
    yora_json(['status' => 'success', 'encontrado' => false]);
}

try {
    $cliente = yora_one(
        $conexion,
        'SELECT nombre, telefono, referencia, pedidos FROM clientes_comercio WHERE comercio_id = ? AND cedula = ? LIMIT 1',
        'is',
        $comercio_id,
        $cedula
    );

    if (!$cliente) {
        yora_json(['status' => 'success', 'encontrado' => false]);
    }

    yora_json([
        'status'     => 'success',
        'encontrado' => true,
        'nombre'     => (string) $cliente['nombre'],
        'telefono'   => (string) $cliente['telefono'],
        'referencia' => (string) $cliente['referencia'],
        'pedidos'    => (int) $cliente['pedidos'],
    ]);
} catch (Throwable $e) {
    error_log('buscar_cliente: ' . $e->getMessage());
    yora_json(['status' => 'success', 'encontrado' => false]);
}
