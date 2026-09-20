<?php
/**
 * DATOS DEL PERFIL DEL CONDUCTOR - YORA DRIVER
 *
 * seccion=contacto -> correo y telefono (los puede corregir cuando quiera).
 * seccion=cobro    -> pago movil, y SOLO si todavia esta vacio. Cambiar unos
 *                     datos de cobro ya registrados se hace por Soporte para
 *                     que nadie desvie los pagos de otro conductor.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();

$seccion = trim((string) ($_POST['seccion'] ?? ''));

$conductor = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $conductor_id);
if (!$conductor) {
    yora_fail('Cuenta no encontrada.', 401);
}

/** Deja el telefono en digitos: los bancos y WhatsApp no aceptan otra cosa. */
function yora_solo_digitos(string $valor): string
{
    return (string) preg_replace('/\D+/', '', $valor);
}

if ($seccion === 'basico' || $seccion === 'contacto') {
    $nombre = trim((string) ($_POST['nombre'] ?? ($conductor['nombre'] ?? '')));
    $correo = trim((string) ($_POST['correo'] ?? ($conductor['correo'] ?? '')));
    // Teléfono bloqueado: solo el registrado en el alta (seguridad).
    $telefono = yora_solo_digitos((string) ($conductor['telefono'] ?? ''));

    if ($nombre !== '' && (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 80)) {
        yora_fail('Escribe tu nombre completo.');
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        yora_fail('Ese correo no tiene un formato válido.');
    }
    if ($correo !== '' && yora_one($conexion, 'SELECT id FROM conductores WHERE LOWER(TRIM(correo)) = LOWER(?) AND id <> ? LIMIT 1', 'si', $correo, $conductor_id)) {
        yora_fail('Ese correo ya está registrado en otra cuenta de Yora.');
    }

    try {
        if ($seccion === 'basico') {
            yora_exec(
                $conexion,
                'UPDATE conductores SET nombre = ?, correo = ? WHERE id = ?',
                'ssi',
                $nombre !== '' ? $nombre : (string) ($conductor['nombre'] ?? ''),
                $correo,
                $conductor_id
            );
        } else {
            yora_exec(
                $conexion,
                'UPDATE conductores SET correo = ? WHERE id = ?',
                'si',
                $correo,
                $conductor_id
            );
        }
        yora_json(['status' => 'success', 'mensaje' => 'Datos actualizados.', 'telefono' => $telefono]);
    } catch (Throwable $e) {
        error_log('actualizar_perfil_driver contacto: ' . $e->getMessage());
        yora_fail('No se pudieron guardar los datos.');
    }
}

if ($seccion === 'cobro') {
    $ya_registrado = trim((string) ($conductor['banco_pago'] ?? '')) !== ''
        && trim((string) ($conductor['telefono_pago'] ?? '')) !== ''
        && trim((string) ($conductor['cedula_pago'] ?? '')) !== '';
    if ($ya_registrado) {
        yora_fail('Tus datos de cobro ya están registrados. Para cambiarlos escribe a Soporte.');
    }

    $banco = trim((string) ($_POST['banco_pago'] ?? ''));
    $telefono_pago = yora_solo_digitos((string) ($_POST['telefono_pago'] ?? ''));
    $cedula_pago = yora_solo_digitos((string) ($_POST['cedula_pago'] ?? ''));

    if (mb_strlen($banco) < 3 || mb_strlen($banco) > 50) {
        yora_fail('Selecciona o escribe el nombre de tu banco.');
    }
    if (strlen($telefono_pago) < 10 || strlen($telefono_pago) > 15) {
        yora_fail('El teléfono del pago móvil debe estar completo.');
    }
    if (strlen($cedula_pago) < 6 || strlen($cedula_pago) > 12) {
        yora_fail('Revisa la cédula del titular.');
    }

    try {
        yora_exec(
            $conexion,
            'UPDATE conductores SET banco_pago = ?, telefono_pago = ?, cedula_pago = ? WHERE id = ?',
            'sssi',
            $banco,
            $telefono_pago,
            $cedula_pago,
            $conductor_id
        );
        yora_json(['status' => 'success', 'mensaje' => 'Datos de cobro registrados.']);
    } catch (Throwable $e) {
        error_log('actualizar_perfil_driver cobro: ' . $e->getMessage());
        yora_fail('No se pudieron guardar los datos de cobro.');
    }
}

yora_fail('Solicitud no válida.');
