<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();

$estado = ((int) ($_POST['estado'] ?? 0) === 1) ? 1 : 0;

try {
    // Sin la cuenta verificada no se puede operar: solo dejamos ponerse offline.
    if ($estado === 1) {
        $conductor = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
        $docs = yora_docs_estado($conductor ?: []);
        if (!$docs['verificado']) {
            if ($docs['faltantes'] > 0) {
                $aviso = 'Para conectarte primero verifica tu cuenta: te faltan ' . $docs['faltantes'] . ' documento(s).';
            } elseif ($docs['global'] === 'Rechazado') {
                $aviso = 'Yora te devolvió el expediente: corrige lo que te indican y envíalo otra vez.';
            } elseif ($docs['global'] === 'Cargado') {
                $aviso = 'Ya tienes todos tus documentos: pulsa Enviar a verificación para que Yora los revise.';
            } else {
                $aviso = 'Tu cuenta está en revisión. Te avisamos en cuanto Yora apruebe tus documentos.';
            }
            yora_json([
                'status'    => 'error',
                'mensaje'   => $aviso,
                'verificar' => true,
                'ir'        => '/ajustes_documentos.php',
            ]);
        }
        // GPS opcional (APK lite anti-ANR): Online sin coords está permitido.
        $lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
        $lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
        if (yora_coords_ok($lat, $lng)) {
            yora_guardar_gps_conductor($conexion, $conductor_id, $lat, $lng);
        }
    }

    yora_exec($conexion, 'UPDATE conductores SET en_linea = ? WHERE id = ?', 'ii', $estado, $conductor_id);
    yora_json(['status' => 'success', 'estado' => $estado]);
} catch (Throwable $e) {
    error_log('toggle_status: ' . $e->getMessage());
    yora_fail('No se pudo cambiar el estado.');
}
