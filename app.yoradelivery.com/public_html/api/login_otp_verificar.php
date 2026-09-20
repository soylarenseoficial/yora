<?php
/**
 * Verifica código de login y abre sesión API del conductor.
 * Preferir login_driver.php?modo=otp_verificar.
 * POST sin "@": e (hex), mu+md, p1+p2+p3 + codigo.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
yora_timezone($conexion);

if (!function_exists('yora_login_leer_correo_any')) {
    function yora_login_leer_correo_any(array $in): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', (string) ($in['e'] ?? $in['eh'] ?? $in['correo_hex'] ?? ''));
        if ($hex !== '' && (strlen($hex) % 2) === 0) {
            $bin = @hex2bin($hex);
            if (is_string($bin) && $bin !== '') {
                return mb_strtolower(trim($bin));
            }
        }
        $user = trim((string) ($in['mu'] ?? $in['u'] ?? $in['mail_user'] ?? ''));
        $dom = trim((string) ($in['md'] ?? $in['d'] ?? $in['mail_dom'] ?? ''));
        $dom = str_replace('_', '.', $dom);
        if ($user !== '' && $dom !== '') {
            return mb_strtolower($user . '@' . $dom);
        }
        $p1 = trim((string) ($in['p1'] ?? ''));
        $p2 = trim((string) ($in['p2'] ?? ''));
        $p3 = trim((string) ($in['p3'] ?? ''));
        if ($p1 !== '' && $p2 !== '' && $p3 !== '') {
            return mb_strtolower($p1 . '@' . $p2 . '.' . $p3);
        }
        $hu = trim((string) ($_SERVER['HTTP_X_YORA_MU'] ?? ''));
        $hd = trim((string) ($_SERVER['HTTP_X_YORA_MD'] ?? ''));
        $hd = str_replace('_', '.', $hd);
        if ($hu !== '' && $hd !== '') {
            return mb_strtolower($hu . '@' . $hd);
        }
        $correo = trim((string) ($in['correo'] ?? $in['email'] ?? $in['correo_safe'] ?? ''));
        if ($correo === '' && !empty($in['correo_b64'])) {
            $decoded = base64_decode((string) $in['correo_b64'], true);
            if (is_string($decoded)) {
                $correo = trim($decoded);
            }
        }
        $correo = str_ireplace(['(at)', '[at]', '__at__'], '@', $correo);
        return mb_strtolower($correo);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yora_json(['ok' => false, 'mensaje' => 'Método no permitido'], 405);
}

$in = $_POST;
$correo = yora_login_leer_correo_any($in);
$codigo = preg_replace('/\D+/', '', (string) ($in['codigo'] ?? $in['code'] ?? ''));

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
     WHERE conductor_id = ? AND codigo = ?
       AND expira >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     ORDER BY id DESC LIMIT 1',
    'is',
    $driver_id,
    $codigo
);
if (!$otp) {
    yora_json(['ok' => false, 'mensaje' => 'Código incorrecto. Revísalo e inténtalo de nuevo.'], 401);
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
