<?php
/**
 * Cambio de contraseña (Ajustes o primer ingreso con clave generada por HQ).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
yora_require_write();
$conductor_id = yora_require_conductor_any();
yora_pass_flag_esquema($conexion);

$actual = (string) ($_POST['actual'] ?? '');
$nueva  = (string) ($_POST['nueva'] ?? '');

$fila = yora_one(
    $conexion,
    'SELECT password, debe_cambiar_password FROM conductores WHERE id = ?',
    'i',
    $conductor_id
);
if (!$fila) {
    yora_fail('No encontramos tu cuenta.');
}

$obligatorio = (int) ($fila['debe_cambiar_password'] ?? 0) === 1;

if ($nueva === '') {
    yora_fail('Completa la nueva contraseña.');
}
if (strlen($nueva) < 8) {
    yora_fail('La nueva contraseña debe tener al menos 8 caracteres.');
}
if ($nueva === $actual && $actual !== '') {
    yora_fail('La nueva contraseña debe ser distinta a la actual.');
}
if (!yora_rate_limit('pass-driver:' . $conductor_id, 6, 900)) {
    yora_fail('Demasiados intentos. Espera unos minutos.');
}

try {
    // Primer cambio tras clave de HQ: no exige "actual" (acabaron de entrar).
    if (!$obligatorio) {
        if ($actual === '') {
            yora_fail('Completa los dos campos.');
        }
        if (!yora_verify_and_upgrade_password($conexion, 'conductores', $conductor_id, $actual, (string) $fila['password'])) {
            yora_fail('La contraseña actual no es correcta.');
        }
    }

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    if ($obligatorio) {
        // Mantener sesión abierta para seguir en la app.
        yora_exec(
            $conexion,
            'UPDATE conductores SET password = ?, debe_cambiar_password = 0 WHERE id = ?',
            'si',
            $hash,
            $conductor_id
        );
        yora_json([
            'status'  => 'success',
            'mensaje' => 'Contraseña actualizada.',
            'cerrar_sesion' => false,
        ]);
    }

    yora_exec(
        $conexion,
        'UPDATE conductores SET password = ?, debe_cambiar_password = 0, token_app = NULL WHERE id = ?',
        'si',
        $hash,
        $conductor_id
    );

    yora_json([
        'status'  => 'success',
        'mensaje' => 'Contraseña actualizada. Vuelve a entrar con la nueva.',
        'cerrar_sesion' => true,
    ]);
} catch (Throwable $e) {
    error_log('cambiar_password_driver: ' . $e->getMessage());
    yora_fail('No se pudo cambiar la contraseña.');
}
