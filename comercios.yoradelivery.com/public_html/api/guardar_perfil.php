<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$rif = trim((string) ($_POST['rif'] ?? ''));
$direccion = trim((string) ($_POST['direccion'] ?? ''));
$lat = (float) ($_POST['lat'] ?? 0);
$lng = (float) ($_POST['lng'] ?? 0);

if ($nombre === '' || mb_strlen($nombre) > 120) {
    yora_fail('Nombre de comercio inválido.');
}

try {
    $abre = yora_hora_sql((string) ($_POST['hora_abre'] ?? ''));
    $cierra = yora_hora_sql((string) ($_POST['hora_cierra'] ?? ''));
    if (trim((string) ($_POST['hora_abre'] ?? '')) !== '' && $abre === '') {
        yora_fail('Hora de apertura inválida.');
    }
    if (trim((string) ($_POST['hora_cierra'] ?? '')) !== '' && $cierra === '') {
        yora_fail('Hora de cierre inválida.');
    }
    $horario_txt = ($abre !== '' && $cierra !== '')
        ? (substr($abre, 0, 5) . ' a ' . substr($cierra, 0, 5))
        : '';

    yora_exec(
        $conexion,
        'UPDATE comercios SET nombre = ?, rif = ?, direccion = ?, latitud = ?, longitud = ?, lat = ?, lng = ?, hora_abre = NULLIF(?, \'\'), hora_cierra = NULLIF(?, \'\'), horario = ? WHERE id = ?',
        'sssddddsssi',
        $nombre,
        $rif,
        $direccion,
        $lat,
        $lng,
        $lat,
        $lng,
        $abre,
        $cierra,
        $horario_txt,
        $comercio_id
    );

    if (!empty($_POST['password'])) {
        if (strlen((string) $_POST['password']) < 8) {
            yora_fail('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        // Cambiar clave exige la actual: si roban la sesión, no pueden secuestrar la cuenta.
        $actual = (string) ($_POST['password_actual'] ?? '');
        $row = yora_one($conexion, 'SELECT password FROM comercios WHERE id = ?', 'i', $comercio_id);
        if (!$row || $actual === '' || !password_verify($actual, (string) $row['password'])) {
            yora_fail('Para cambiar la contraseña debes escribir tu contraseña actual.');
        }
        $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
        yora_exec($conexion, 'UPDATE comercios SET password = ? WHERE id = ?', 'si', $hash, $comercio_id);
    }

    if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $logo = yora_guardar_logo_comercio($_FILES['logo']);
        if (!$logo['ok']) {
            yora_fail($logo['error'] !== '' ? $logo['error'] : 'No se pudo guardar el logo.');
        }
        yora_exec($conexion, 'UPDATE comercios SET logo_url = ? WHERE id = ?', 'si', $logo['url'], $comercio_id);
    }

    $doc_err = (int) ($_FILES['documento']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($doc_err !== UPLOAD_ERR_NO_FILE) {
        $doc = yora_guardar_documento_comercio($_FILES['documento']);
        if (!$doc['ok']) {
            yora_fail($doc['error'] !== '' ? $doc['error'] : 'No se pudo guardar el documento. Usa JPG, PNG o PDF de máximo 5 MB.');
        }
        yora_exec(
            $conexion,
            "UPDATE comercios SET documento_url = ?, estado_documentos = 'En Revisión' WHERE id = ?",
            'si',
            $doc['url'],
            $comercio_id
        );
    }

    $logo_actual = yora_one($conexion, 'SELECT logo_url FROM comercios WHERE id = ?', 'i', $comercio_id);
    yora_json([
        'status' => 'success',
        'mensaje' => 'Cambios guardados correctamente',
        'url' => yora_url_archivo($logo_actual['logo_url'] ?? '', ''),
    ]);
} catch (Throwable $e) {
    error_log('guardar_perfil: ' . $e->getMessage());
    yora_fail('No se pudieron guardar los cambios.');
}
