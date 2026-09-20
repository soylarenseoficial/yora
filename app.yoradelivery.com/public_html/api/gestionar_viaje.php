<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_mant_exigir($conexion, 'drivers', $conductor_id);

$comanda_id = (int) ($_POST['comanda_id'] ?? 0);
$comanda_id_2 = (int) ($_POST['comanda_id_2'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');
if ($comanda_id < 1 || !in_array($accion, ['bloquear', 'liberar', 'aceptar', 'bloquear_lote'], true)) {
    yora_fail('Datos inválidos.');
}

try {
    // Tomar un viaje nuevo exige cuenta verificada. 'aceptar' y 'liberar' se
    // permiten siempre: un viaje ya tomado hay que poder terminarlo o soltarlo.
    if ($accion === 'bloquear' || $accion === 'bloquear_lote') {
        $conductor = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
        if (!yora_docs_estado($conductor ?: [])['verificado']) {
            yora_fail('Verifica tu cuenta con tus documentos para poder tomar viajes.');
        }
    }

    $en_curso = (int) (yora_one(
        $conexion,
        "SELECT COUNT(*) AS total FROM comandas WHERE conductor_id = ? AND estatus NOT IN ('Entregado', 'Cancelado')",
        'i',
        $conductor_id
    )['total'] ?? 0);

    $conexion->begin_transaction();

    if ($accion === 'bloquear_lote') {
        if ($comanda_id_2 < 1 || $comanda_id_2 === $comanda_id) {
            $conexion->rollback();
            yora_fail('Esta ganga ya no está disponible.');
        }
        if ($en_curso >= 1) {
            $conexion->rollback();
            yora_fail('Ya tienes un viaje en curso. Entrégalo para poder tomar otro.');
        }

        $a = yora_one($conexion, "SELECT id, comercio_id, estatus FROM comandas WHERE id = ? AND estatus = 'Buscando Conductor'", 'i', $comanda_id);
        $b = yora_one($conexion, "SELECT id, comercio_id, estatus FROM comandas WHERE id = ? AND estatus = 'Buscando Conductor'", 'i', $comanda_id_2);
        if (!$a || !$b || (int) $a['comercio_id'] !== (int) $b['comercio_id']) {
            $conexion->rollback();
            yora_fail('Esta doble ganga ya la tomó otro driver.');
        }

        $lote = 'G' . bin2hex(random_bytes(8));
        $ok1 = yora_exec($conexion, "UPDATE comandas SET estatus = 'Revisando', conductor_id = ?, lote_id = ? WHERE id = ? AND estatus = 'Buscando Conductor'", 'isi', $conductor_id, $lote, $comanda_id);
        $ok2 = yora_exec($conexion, "UPDATE comandas SET estatus = 'Revisando', conductor_id = ?, lote_id = ? WHERE id = ? AND estatus = 'Buscando Conductor'", 'isi', $conductor_id, $lote, $comanda_id_2);
        if ($ok1 < 1 || $ok2 < 1) {
            $conexion->rollback();
            yora_fail('Esta doble ganga ya la tomó otro driver.');
        }
    } elseif ($accion === 'bloquear') {
        if ($en_curso >= 1) {
            $conexion->rollback();
            yora_fail('Ya tienes un viaje en curso. Entrégalo para poder tomar otro.');
        }
        $ok = yora_exec($conexion, "UPDATE comandas SET estatus = 'Revisando', conductor_id = ? WHERE id = ? AND estatus = 'Buscando Conductor'", 'ii', $conductor_id, $comanda_id);
        if ($ok < 1) {
            $conexion->rollback();
            yora_fail('No se pudo realizar la acción. Quizás otro conductor fue más rápido.');
        }
    } elseif ($accion === 'liberar') {
        $fila = yora_one($conexion, 'SELECT lote_id FROM comandas WHERE id = ? AND conductor_id = ? AND estatus = ?', 'iis', $comanda_id, $conductor_id, 'Revisando');
        if (!$fila) {
            $conexion->rollback();
            yora_fail('No se pudo soltar el viaje.');
        }
        $lote = trim((string) ($fila['lote_id'] ?? ''));
        if ($lote !== '') {
            yora_exec($conexion, "UPDATE comandas SET estatus = 'Buscando Conductor', conductor_id = NULL, lote_id = NULL WHERE lote_id = ? AND conductor_id = ? AND estatus = 'Revisando'", 'si', $lote, $conductor_id);
        } else {
            yora_exec($conexion, "UPDATE comandas SET estatus = 'Buscando Conductor', conductor_id = NULL WHERE id = ? AND conductor_id = ? AND estatus = 'Revisando'", 'ii', $comanda_id, $conductor_id);
        }
    } else {
        $fila = yora_one($conexion, 'SELECT lote_id FROM comandas WHERE id = ? AND conductor_id = ? AND estatus = ?', 'iis', $comanda_id, $conductor_id, 'Revisando');
        if (!$fila) {
            $conexion->rollback();
            yora_fail('No se pudo aceptar el viaje.');
        }
        $lote = trim((string) ($fila['lote_id'] ?? ''));
        if ($lote !== '') {
            yora_exec($conexion, "UPDATE comandas SET estatus = 'En Camino a Comercio' WHERE lote_id = ? AND conductor_id = ? AND estatus = 'Revisando'", 'si', $lote, $conductor_id);
        } else {
            $ok = yora_exec($conexion, "UPDATE comandas SET estatus = 'En Camino a Comercio' WHERE id = ? AND conductor_id = ? AND estatus = 'Revisando'", 'ii', $comanda_id, $conductor_id);
            if ($ok < 1) {
                $conexion->rollback();
                yora_fail('No se pudo realizar la acción. Quizás otro conductor fue más rápido.');
            }
        }
    }

    $conexion->commit();
    yora_json(['status' => 'success']);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('gestionar_viaje: ' . $e->getMessage());
    yora_fail('No se pudo gestionar el viaje.');
}
