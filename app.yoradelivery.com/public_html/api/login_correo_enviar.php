<?php
/**
 * Envía código de login al correo del conductor.
 * POST: correo_b64 (Base64 del email) — el WAF del VPS bloquea "@" en form-urlencoded.
 * También acepta correo / email / correo_safe (con (at) en vez de @).
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
$codigo = (string) random_int(10000, 99999);

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

yora_exec($conexion, 'DELETE FROM driver_login_otp WHERE conductor_id = ? OR expira < NOW()', 'i', $driver_id);
yora_exec(
    $conexion,
    'INSERT INTO driver_login_otp (conductor_id, codigo, expira) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))',
    'is',
    $driver_id,
    $codigo
);

$nombre = (string) ($row['nombre'] ?? 'Conductor');
$codigoEspaciado = implode(' ', str_split($codigo));
$logo = 'https://yoradelivery.com/uploads/Logo5blanco.png';
$html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#111111;font-family:Arial,Helvetica,sans-serif;">'
    . '<div style="max-width:480px;margin:0 auto;padding:28px 18px;color:#fff;">'
    . '<div style="text-align:center;margin-bottom:16px;">'
    . '<img src="' . htmlspecialchars($logo) . '" alt="Yora" style="height:36px;width:auto;"></div>'
    . '<div style="background:#e4441b;border-radius:18px;padding:26px 18px;text-align:center;margin-bottom:18px;'
    . 'background-image:repeating-linear-gradient(-45deg,rgba(0,0,0,.06),rgba(0,0,0,.06) 12px,transparent 12px,transparent 24px);">'
    . '<img src="' . htmlspecialchars($logo) . '" alt="Yora" style="height:42px;width:auto;margin-bottom:10px;">'
    . '<div style="font-size:12px;letter-spacing:1.2px;opacity:.95;font-weight:700;">HAZLO POSIBLE, HAZLO YORA</div></div>'
    . '<p style="text-align:center;font-size:15px;line-height:1.45;margin:0 0 8px;">Solicitud para iniciar sesión en tu cuenta YoraDriver</p>'
    . '<p style="text-align:center;font-size:14px;color:#d1d5db;margin:0;">Tu código de verificación es:</p>'
    . '<div style="background:#1f1f1f;border-radius:14px;padding:18px;text-align:center;'
    . 'font-size:32px;font-weight:800;letter-spacing:8px;margin:14px 0 22px;">'
    . htmlspecialchars($codigoEspaciado) . '</div>'
    . '<p style="text-align:center;font-size:12px;color:#9ca3af;margin:0;">Válido por 15 minutos. Si no fuiste tú, ignora este correo.</p>'
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
