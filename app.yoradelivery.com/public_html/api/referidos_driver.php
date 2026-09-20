<?php
/**
 * Sistema de referidos del conductor.
 * GET  -> código, link, lista de referidos
 * POST accion=aplicar + codigo -> vincula al conductor actual como referido (una vez)
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

try {
    $conexion->query(
        "CREATE TABLE IF NOT EXISTS driver_referidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referidor_id INT NOT NULL,
            referido_id INT NOT NULL,
            codigo VARCHAR(24) NOT NULL,
            creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_referido (referido_id),
            INDEX (referidor_id),
            INDEX (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
}

$me = yora_one($conexion, 'SELECT id, nombre, cedula FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$me) {
    yora_json(['ok' => false, 'mensaje' => 'Cuenta no encontrada'], 404);
}

$codigo = 'YORA' . str_pad((string) $conductor_id, 4, '0', STR_PAD_LEFT);
$link = 'https://app.yoradelivery.com/?ref=' . rawurlencode($codigo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if ($accion === 'aplicar') {
        $ref = strtoupper(trim((string) ($_POST['codigo'] ?? '')));
        $ref = preg_replace('/[^A-Z0-9]/', '', $ref);
        if ($ref === '' || !preg_match('/^YORA(\d+)$/', $ref, $m)) {
            yora_json(['ok' => false, 'mensaje' => 'Código de referido inválido.'], 400);
        }
        $refId = (int) $m[1];
        if ($refId <= 0 || $refId === $conductor_id) {
            yora_json(['ok' => false, 'mensaje' => 'No puedes usar tu propio código.'], 400);
        }
        $refRow = yora_one($conexion, 'SELECT id FROM conductores WHERE id = ?', 'i', $refId);
        if (!$refRow) {
            yora_json(['ok' => false, 'mensaje' => 'Ese código no existe.'], 404);
        }
        $ya = yora_one($conexion, 'SELECT id FROM driver_referidos WHERE referido_id = ?', 'i', $conductor_id);
        if ($ya) {
            yora_json(['ok' => false, 'mensaje' => 'Ya tienes un referidor registrado.'], 409);
        }
        yora_exec(
            $conexion,
            'INSERT INTO driver_referidos (referidor_id, referido_id, codigo) VALUES (?, ?, ?)',
            'iis',
            $refId,
            $conductor_id,
            $ref
        );
        yora_json(['ok' => true, 'mensaje' => 'Código aplicado. ¡Bienvenido al equipo!'], 200);
    }
    yora_json(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
}

$lista = yora_all(
    $conexion,
    "SELECT r.id, r.creado, c.nombre, c.foto_perfil, c.estatus,
            (SELECT COUNT(*) FROM comandas x WHERE x.conductor_id = c.id AND x.estatus = 'Entregado') AS viajes
     FROM driver_referidos r
     INNER JOIN conductores c ON c.id = r.referido_id
     WHERE r.referidor_id = ?
     ORDER BY r.id DESC
     LIMIT 50",
    'i',
    $conductor_id
) ?: [];

$items = [];
foreach ($lista as $row) {
    $foto = yora_url_archivo(
        $row['foto_perfil'] ?? '',
        'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
        'https://yoradelivery.com'
    );
    $items[] = [
        'id' => (int) $row['id'],
        'nombre' => (string) ($row['nombre'] ?? 'Conductor'),
        'foto' => $foto,
        'estatus' => (string) ($row['estatus'] ?? ''),
        'viajes' => (int) ($row['viajes'] ?? 0),
        'fecha' => (string) ($row['creado'] ?? ''),
    ];
}

$miRef = yora_one(
    $conexion,
    'SELECT referidor_id, codigo FROM driver_referidos WHERE referido_id = ? LIMIT 1',
    'i',
    $conductor_id
);

yora_json([
    'ok' => true,
    'codigo' => $codigo,
    'link' => $link,
    'mensaje' => 'Comparte tu código. Cuando un conductor nuevo se registre con él, aparecerá aquí.',
    'total' => count($items),
    'referidos' => $items,
    'mi_referidor' => $miRef ? [
        'codigo' => (string) $miRef['codigo'],
        'referidor_id' => (int) $miRef['referidor_id'],
    ] : null,
], 200);
