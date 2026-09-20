<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_timezone($conexion);

$id = (int) ($_POST['id'] ?? 0);
$estatus = trim((string) ($_POST['estatus'] ?? ''));
$permitidos = ['En Camino a Cliente', 'Entregado'];
if ($id < 1 || !in_array($estatus, $permitidos, true)) {
    yora_fail('Transición de estado no permitida.');
}

const YORA_RADIO_GEOCERCA = 1.0;

try {
    $comanda = yora_one(
        $conexion,
        'SELECT c.estatus, c.costo_delivery, c.direccion_entrega, c.lote_id, c.comercio_id, c.tipo_comanda, c.tipo_pago, c.lat_recogida, c.lng_recogida, c.lat_entrega, c.lng_entrega, c.geocerca_libre, COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat, COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng
         FROM comandas c
         LEFT JOIN comercios r ON r.id = c.comercio_id
         WHERE c.id = ? AND c.conductor_id = ?',
        'ii',
        $id,
        $conductor_id
    );
    if (!$comanda) {
        yora_fail('Viaje no encontrado.');
    }
    if (!yora_can_transition($comanda['estatus'], $estatus)) {
        yora_fail('No puedes pasar de "' . $comanda['estatus'] . '" a "' . $estatus . '".');
    }

    $mi_lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
    $mi_lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
    if (!yora_coords_ok($mi_lat, $mi_lng)) {
        // Fallback: última ubicación del conductor (APK lite sin GPS en vivo).
        $ult = yora_one($conexion, 'SELECT ultima_lat, ultima_lng FROM conductores WHERE id = ?', 'i', $conductor_id);
        $mi_lat = (float) ($ult['ultima_lat'] ?? 0);
        $mi_lng = (float) ($ult['ultima_lng'] ?? 0);
    }
    if (!yora_coords_ok($mi_lat, $mi_lng)) {
        yora_fail('Activa la ubicación del teléfono para poder marcar este paso.');
    }

    if ($estatus === 'En Camino a Cliente') {
        $donde = yora_es_mandadito($comanda) ? 'el punto de recogida' : 'el comercio';
        [$ref_lat, $ref_lng] = yora_coords_recogida_comanda($comanda);
        $referencia = yora_coords_ok($ref_lat, $ref_lng) ? [$ref_lat, $ref_lng] : null;
    } else {
        $donde = 'la dirección de entrega';
        [$ref_lat, $ref_lng] = yora_coords_entrega_comanda($comanda);
        $referencia = yora_coords_ok($ref_lat, $ref_lng) ? [$ref_lat, $ref_lng] : yora_gps_desde_direccion($comanda['direccion_entrega']);
    }

    $libre = (int) ($comanda['geocerca_libre'] ?? 0) === 1;
    if ($referencia && !$libre) {
        $distancia = yora_haversine($mi_lat, $mi_lng, $referencia[0], $referencia[1]);
        if ($distancia > YORA_RADIO_GEOCERCA) {
            yora_fail('Estás a ' . number_format($distancia, 1) . ' km de ' . $donde . '. Acércate para poder continuar.');
        }
    }

    if (!isset($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        yora_fail('Es obligatorio adjuntar la foto de evidencia.');
    }
    $dir_ev = __DIR__ . '/../uploads/evidencias/';
    if (!is_dir($dir_ev)) {
        @mkdir($dir_ev, 0755, true);
    }
    $nombre_foto = yora_upload($_FILES['foto'], $dir_ev);
    if (!$nombre_foto) {
        $nombre_foto = yora_guardar_evidencia_fallback($_FILES['foto'], $dir_ev);
    }
    if (!$nombre_foto) {
        yora_fail('No se pudo guardar la foto. Prueba otra vez o usa la cámara del teléfono.');
    }
    $url_foto = 'https://app.yoradelivery.com/uploads/evidencias/' . $nombre_foto;
    $foto_col = ($estatus === 'Entregado') ? 'foto_entrega' : 'foto_recogida';

    $conexion->begin_transaction();
    $lote = trim((string) ($comanda['lote_id'] ?? ''));

    if ($estatus === 'En Camino a Cliente' && $lote !== '') {
        $ok = yora_exec(
            $conexion,
            "UPDATE comandas SET estatus = ?, {$foto_col} = ? WHERE lote_id = ? AND conductor_id = ? AND estatus = ?",
            'sssis',
            $estatus,
            $url_foto,
            $lote,
            $conductor_id,
            $comanda['estatus']
        );
    } else {
        $ok = yora_exec(
            $conexion,
            "UPDATE comandas SET estatus = ?, {$foto_col} = ? WHERE id = ? AND conductor_id = ? AND estatus = ?",
            'ssiis',
            $estatus,
            $url_foto,
            $id,
            $conductor_id,
            $comanda['estatus']
        );
    }
    if ($ok < 1) {
        $conexion->rollback();
        yora_fail('El viaje cambió de estado. Recarga e inténtalo de nuevo.');
    }

    if ($estatus === 'Entregado') {
        yora_acreditar_viaje_driver($conexion, $conductor_id, $comanda);
        try {
            yora_exec($conexion, 'UPDATE comandas SET fecha_entrega = NOW() WHERE id = ?', 'i', $id);
        } catch (Throwable $e) {
            error_log('cambiar_estatus fecha_entrega: ' . $e->getMessage());
        }
    }

    $conexion->commit();

    if ($estatus === 'Entregado' && (int) ($comanda['comercio_id'] ?? 0) > 0) {
        yora_comercio_contar_entregas($conexion, (int) $comanda['comercio_id']);
    }

    yora_json(['status' => 'success', 'mensaje' => 'Evidencia guardada y viaje actualizado.']);
} catch (Throwable $e) {
    try {
        $conexion->rollback();
    } catch (Throwable $ignored) {
    }
    $msg = trim($e->getMessage());
    error_log('cambiar_estatus: ' . $msg);
    // No ocultar mensajes de negocio (geocerca, foto, transición).
    if ($msg !== '' && stripos($msg, 'yora_fail') === false && strlen($msg) < 220) {
        yora_fail($msg);
    }
    yora_fail('No se pudo actualizar el viaje. Inténtalo de nuevo.');
}

function yora_guardar_evidencia_fallback(array $file, string $destDir): ?string
{
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return null;
    }
    if (($file['size'] ?? 0) > 8388608) {
        return null;
    }
    $mime = function_exists('yora_mime_archivo')
        ? yora_mime_archivo($tmp)
        : '';
    if ($mime === '' && function_exists('getimagesize')) {
        $info = @getimagesize($tmp);
        if (is_array($info) && !empty($info['mime'])) {
            $mime = (string) $info['mime'];
        }
    }
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg'];
    $ext = $exts[$mime] ?? null;
    if ($ext === null) {
        // Cámara Android suele mandar JPEG aunque el MIME falle.
        $extNom = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($extNom, ['jpg', 'jpeg', 'jfif', 'jpe'], true)) {
            $ext = 'jpg';
        } elseif ($extNom === 'png') {
            $ext = 'png';
        } elseif ($extNom === 'webp') {
            $ext = 'webp';
        } else {
            $ext = 'jpg';
        }
    }
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }
    $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $dest = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
    if (!$ok) {
        return null;
    }
    @chmod($dest, 0644);
    return $name;
}
