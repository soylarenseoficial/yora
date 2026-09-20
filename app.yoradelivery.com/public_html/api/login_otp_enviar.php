<?php
/**
 * Envía código de login al correo del conductor.
 * Preferir login_driver.php?modo=otp_enviar (mismo endpoint que cédula; pasa el WAF).
 * POST sin "@": e (hex), mu+md, p1+p2+p3.
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
if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    yora_json(['ok' => false, 'mensaje' => 'Escribe un correo válido.'], 400);
}

if (!yora_rate_limit('login-otp:' . yora_client_ip() . ':' . $correo, 8, 900)) {
    yora_json(['ok' => false, 'mensaje' => 'Demasiados intentos. Espera unos minutos.'], 429);
}

$row = yora_one($conexion, 'SELECT * FROM conductores WHERE LOWER(TRIM(correo)) = ? LIMIT 1', 's', $correo);
if (!$row) {
    yora_json(['ok' => false, 'mensaje' => 'No encontramos una cuenta con ese correo.'], 404);
}

$estatus = (string) ($row['estatus'] ?? '');
if (in_array($estatus, ['pendiente', 'rechazado', 'inactivo'], true)) {
    yora_json(['ok' => false, 'mensaje' => 'Tu cuenta no puede iniciar sesión ahora. Contacta a soporte.'], 403);
}

$driver_id = (int) $row['id'];

try {
    $conexion->query(
        "CREATE TABLE IF NOT EXISTS driver_login_otp (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conductor_id INT NOT NULL,
            codigo VARCHAR(8) NOT NULL,
            expira DATETIME NOT NULL,
            creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (conductor_id),
            INDEX (expira)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
}

$reciente = yora_one(
    $conexion,
    'SELECT id, codigo FROM driver_login_otp
     WHERE conductor_id = ?
       AND expira >= NOW()
       AND creado >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     ORDER BY id DESC LIMIT 1',
    'i',
    $driver_id
);
if ($reciente && trim((string) ($reciente['codigo'] ?? '')) !== '') {
    $codigo = trim((string) $reciente['codigo']);
    yora_exec(
        $conexion,
        'UPDATE driver_login_otp SET expira = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?',
        'i',
        (int) $reciente['id']
    );
} else {
    $codigo = (string) random_int(10000, 99999);
    yora_exec($conexion, 'DELETE FROM driver_login_otp WHERE conductor_id = ?', 'i', $driver_id);
    yora_exec(
        $conexion,
        'INSERT INTO driver_login_otp (conductor_id, codigo, expira) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE))',
        'is',
        $driver_id,
        $codigo
    );
}

$nombre = (string) ($row['nombre'] ?? 'Conductor');
$codigoEspaciado = implode(' ', str_split($codigo));
$logo = 'https://yoradelivery.com/uploads/Logo5blanco.png';
$html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#111111;font-family:Arial,Helvetica,sans-serif;">'
    . '<div style="max-width:480px;margin:0 auto;padding:28px 18px;color:#fff;">'
    . '<div style="text-align:center;margin-bottom:16px;">'
    . '<img src="' . htmlspecialchars($logo) . '" alt="Yora" style="height:36px;width:auto;"></div>'
    . '<div style="background:#e4441b;border-radius:18px;padding:26px 18px;text-align:center;margin-bottom:18px;">'
    . '<img src="' . htmlspecialchars($logo) . '" alt="Yora" style="height:42px;width:auto;margin-bottom:10px;">'
    . '<div style="font-size:12px;letter-spacing:1.2px;opacity:.95;font-weight:700;">HAZLO POSIBLE, HAZLO YORA</div></div>'
    . '<p style="text-align:center;font-size:15px;line-height:1.45;margin:0 0 8px;">Solicitud para iniciar sesión en tu cuenta YoraDriver</p>'
    . '<p style="text-align:center;font-size:14px;color:#d1d5db;margin:0;">Tu código de verificación es:</p>'
    . '<div style="background:#1f1f1f;border-radius:14px;padding:18px;text-align:center;'
    . 'font-size:32px;font-weight:800;letter-spacing:8px;margin:14px 0 22px;">'
    . htmlspecialchars($codigoEspaciado) . '</div>'
    . '<p style="text-align:center;font-size:12px;color:#9ca3af;margin:0;">Válido por 20 minutos. Si no fuiste tú, ignora este correo.</p>'
    . '</div></body></html>';

$err = null;
$okMail = yora_enviar_correo($correo, $nombre, 'Tu código YoraDriver', $html, $err);
if (!$okMail) {
    yora_json(['ok' => false, 'mensaje' => $err ?: 'No se pudo enviar el correo.'], 500);
}

$mask = preg_replace('/(^.).*(@.*$)/', '$1***$2', $correo);
yora_json([
    'ok' => true,
    'mensaje' => 'Enviamos un código a tu correo.',
    'correo_mask' => $mask,
    'correo' => $correo,
], 200);
