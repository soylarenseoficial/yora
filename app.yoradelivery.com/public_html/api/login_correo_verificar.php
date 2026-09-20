<?php
/**
 * Verifica código de login y abre sesión API del conductor.
 * POST: correo_b64 (o correo) + codigo
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
yora_timezone($conexion);

if (!function_exists('yora_login_leer_correo_post')) {
    function yora_login_leer_correo_post(): string
    {
        $correo = trim((string) ($_POST['correo'] ?? $_POST['email'] ?? $_POST['correo_safe'] ?? ''));
        if ($correo === '' && !empty($_POST['correo_b64'])) {
            $raw = base64_decode((string) $_POST['correo_b64'], true);
            if (is_string($raw)) {
                $correo = trim($raw);
            }
        }
        if ($correo === '') {
            $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($ct, 'application/json') !== false) {
                $j = json_decode((string) file_get_contents('php://input'), true);
                if (is_array($j)) {
                    $correo = trim((string) ($j['correo'] ?? $j['email'] ?? ''));
                    if ($correo === '' && !empty($j['correo_b64'])) {
                        $raw = base64_decode((string) $j['correo_b64'], true);
                        if (is_string($raw)) {
                            $correo = trim($raw);
                        }
                    }
                }
            }
        }
        $correo = str_ireplace(['(at)', '[at]', '__at__'], '@', $correo);
        return mb_strtolower($correo);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yora_json(['ok' => false, 'mensaje' => 'Método no permitido'], 405);
}

$correo = yora_login_leer_correo_post();
$codigo = preg_replace('/\D+/', '', (string) ($_POST['codigo'] ?? $_POST['code'] ?? ''));

if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    yora_json(['ok' => false, 'mensaje' => 'Correo inválido.'], 400);
}
if (strlen($codigo) !== 5) {
    yora_json(['ok' => false, 'mensaje' => 'El código debe tener 5 dígitos.'], 400);
}

if (!yora_rate_limit('login-otp-verify:' . yora_client_ip() . ':' . $correo, 20, 900)) {
    yora_json(['ok' => false, 'mensaje' => 'Demasiados intentos. Espera unos minutos.'], 429);
}

$row = yora_one($conexion, 'SELECT * FROM conductores WHERE LOWER(TRIM(correo)) = ? LIMIT 1', 's', $correo);
if (!$row) {
    yora_json(['ok' => false, 'mensaje' => 'Código incorrecto o expirado.'], 401);
}

$driver_id = (int) $row['id'];
$otp = yora_one(
    $conexion,
    'SELECT id, codigo, expira FROM driver_login_otp
     WHERE conductor_id = ? AND codigo = ? AND expira >= NOW()
     ORDER BY id DESC LIMIT 1',
    'is',
    $driver_id,
    $codigo
);
if (!$otp) {
    $any = yora_one(
        $conexion,
        'SELECT id, expira FROM driver_login_otp WHERE conductor_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        $driver_id
    );
    if ($any) {
        yora_json(['ok' => false, 'mensaje' => 'Código incorrecto o expirado. Solicita uno nuevo.'], 401);
    }
    yora_json(['ok' => false, 'mensaje' => 'Código incorrecto o expirado.'], 401);
}

yora_exec($conexion, 'DELETE FROM driver_login_otp WHERE conductor_id = ?', 'i', $driver_id);

if (yora_mant_bloquea($conexion, 'drivers', $driver_id)) {
    $cfg = yora_mant_cfg($conexion);
    yora_json(['ok' => false, 'mensaje' => $cfg['mensaje']], 503);
}

$token = yora_conductor_abrir_sesion_api($conexion, $driver_id);
$docs = yora_docs_estado($row);

yora_json([
    'ok' => true,
    'driver_id' => $driver_id,
    'nombre' => (string) ($row['nombre'] ?? ''),
    'cedula' => (string) ($row['cedula'] ?? ''),
    'token' => $token,
    'verificado' => !empty($docs['verificado']),
], 200);
