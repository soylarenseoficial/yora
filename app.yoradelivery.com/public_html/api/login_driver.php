<?php
/**
 * Login JSON para YoraDriver nativo (APK).
 *
 * 1) Cédula + contraseña (legacy): POST cedula + password
 * 2) OTP por correo (bypass WAF): POST modo=otp_enviar|otp_verificar
 *    Correo SIN "@" en el body:
 *      - e = hex del email completo (recomendado)
 *      - mu + md (user + dominio; md puede usar _ por .)
 *      - p1 + p2 + p3 (user + host + tld)
 *      - headers X-Yora-Mu / X-Yora-Md
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
yora_timezone($conexion);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yora_json(['ok' => false, 'mensaje' => 'Método no permitido'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $in = $decoded;
    }
}
if (!$in) {
    $in = $_POST;
}
// Cloudflare WAF bloquea hex con "40" (@) en el BODY; sí deja pasar query/headers.
$in = array_merge($_GET, $in);

if (!function_exists('yora_login_hex_bin')) {
    function yora_login_hex_bin(string $hex): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $hex);
        // "zz" = marcador del byte 0x40 (@) para evadir WAF en body.
        $hex = str_ireplace('zz', '40', $hex);
        if ($hex === '' || (strlen($hex) % 2) !== 0) {
            return '';
        }
        $bin = @hex2bin($hex);
        return is_string($bin) ? trim($bin) : '';
    }
}

if (!function_exists('yora_login_leer_correo_any')) {
    function yora_login_leer_correo_any(array $in): string
    {
        // 1) Header completo (canal más fiable vs Cloudflare).
        $he = trim((string) ($_SERVER['HTTP_X_YORA_E'] ?? ''));
        if ($he !== '') {
            $bin = yora_login_hex_bin($he);
            if ($bin !== '') {
                return mb_strtolower($bin);
            }
        }

        // 2) Hex partido user/dom (eu+ed o a+b) — sin byte 40 en un solo campo.
        $eu = yora_login_hex_bin((string) ($in['eu'] ?? $in['a'] ?? ''));
        $ed = yora_login_hex_bin((string) ($in['ed'] ?? $in['b'] ?? ''));
        if ($eu !== '' && $ed !== '') {
            return mb_strtolower($eu . '@' . $ed);
        }

        // 3) Hex en dos mitades h1+h2
        $h1 = preg_replace('/[^0-9a-fA-F]/', '', (string) ($in['h1'] ?? ''));
        $h2 = preg_replace('/[^0-9a-fA-F]/', '', (string) ($in['h2'] ?? ''));
        if ($h1 !== '' && $h2 !== '') {
            $bin = yora_login_hex_bin($h1 . $h2);
            if ($bin !== '') {
                return mb_strtolower($bin);
            }
        }

        // 4) Hex completo (query/header/body si el WAF lo deja).
        $hex = (string) ($in['e'] ?? $in['eh'] ?? $in['correo_hex'] ?? '');
        if ($hex !== '') {
            $bin = yora_login_hex_bin($hex);
            if ($bin !== '') {
                return mb_strtolower($bin);
            }
        }

        // 5) Texto partido (mu/md). WAF bloquea mail_user/mail_dom.
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

if (!function_exists('yora_login_otp_ensure_table')) {
    function yora_login_otp_ensure_table(mysqli $conexion): void
    {
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
    }
}

$modo = strtolower(trim((string) ($in['modo'] ?? $in['action'] ?? $in['op'] ?? '')));

// ── OTP enviar ──────────────────────────────────────────────────────────────
if (in_array($modo, ['otp_enviar', 'send_code', 'enviar_codigo', 'otp_send'], true)) {
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
    yora_login_otp_ensure_table($conexion);

    // Si el cliente reintenta el envío (WAF/red), NO invalidar el código recién creado.
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
}

// ── OTP verificar ───────────────────────────────────────────────────────────
if (in_array($modo, ['otp_verificar', 'verify_code', 'verificar_codigo', 'otp_verify'], true)) {
    $correo = yora_login_leer_correo_any($in);
    $codigo = preg_replace('/\D+/', '', (string) ($in['codigo'] ?? $in['code'] ?? $in['otp'] ?? ''));
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
    yora_login_otp_ensure_table($conexion);
    // Margen de 5 min por desfase de reloj / reintentos del cliente.
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
        $ultimo = yora_one(
            $conexion,
            'SELECT id, codigo, expira FROM driver_login_otp WHERE conductor_id = ? ORDER BY id DESC LIMIT 1',
            'i',
            $driver_id
        );
        if ($ultimo && trim((string) ($ultimo['codigo'] ?? '')) === $codigo) {
            yora_json(['ok' => false, 'mensaje' => 'Ese código ya expiró. Pulsa Reenviar.'], 401);
        }
        yora_json(['ok' => false, 'mensaje' => 'Código incorrecto. Revísalo e inténtalo de nuevo.'], 401);
    }

    yora_exec($conexion, 'DELETE FROM driver_login_otp WHERE conductor_id = ?', 'i', $driver_id);

    if (yora_mant_bloquea($conexion, 'drivers', $driver_id)) {
        $cfg = yora_mant_cfg($conexion);
        yora_json(['ok' => false, 'mensaje' => $cfg['mensaje']], 503);
    }

    $token = yora_conductor_abrir_sesion_api($conexion, $driver_id);
    $docs = yora_docs_estado($row);
    yora_pass_flag_esquema($conexion);
    $rowFlag = yora_one($conexion, 'SELECT debe_cambiar_password FROM conductores WHERE id = ?', 'i', $driver_id) ?: $row;
    yora_json([
        'ok' => true,
        'driver_id' => $driver_id,
        'nombre' => (string) ($row['nombre'] ?? ''),
        'cedula' => (string) ($row['cedula'] ?? ''),
        'token' => $token,
        'verificado' => !empty($docs['verificado']),
        'debe_cambiar_password' => yora_conductor_debe_cambiar_password($rowFlag),
    ], 200);
}

// ── Login cédula + contraseña ───────────────────────────────────────────────
$cedula = trim((string) ($in['cedula'] ?? ''));
$password = (string) ($in['password'] ?? '');

if ($cedula === '' || $password === '') {
    yora_json(['ok' => false, 'mensaje' => 'Cédula y contraseña son obligatorias.'], 400);
}

if (!yora_rate_limit('login-driver-api:' . yora_client_ip(), 8, 900)) {
    yora_json(['ok' => false, 'mensaje' => 'Demasiados intentos. Espera unos minutos.'], 429);
}

$row = yora_one($conexion, 'SELECT * FROM conductores WHERE cedula = ?', 's', $cedula);
$db_pass = '';
if ($row) {
    $db_pass = (string) ($row['password'] ?? $row['contrasena'] ?? $row['clave'] ?? '');
}

if (!$row || !yora_verify_and_upgrade_password($conexion, 'conductores', (int) $row['id'], $password, $db_pass)) {
    yora_json(['ok' => false, 'mensaje' => 'Cédula o contraseña incorrectos.'], 401);
}

$driver_id = (int) $row['id'];
$estatus = (string) ($row['estatus'] ?? '');

if ($estatus === 'pendiente') {
    yora_json([
        'ok' => false,
        'mensaje' => 'Tu postulación sigue en revisión. Te avisaremos por correo y WhatsApp en cuanto quede aprobada.',
    ], 403);
}
if ($estatus === 'rechazado') {
    yora_json([
        'ok' => false,
        'mensaje' => 'Tu solicitud fue rechazada. Escríbenos por WhatsApp si quieres volver a postularte.',
    ], 403);
}
if ($estatus === 'inactivo') {
    yora_json([
        'ok' => false,
        'mensaje' => 'Tu cuenta está suspendida. Contacta a soporte de Yora para reactivarla.',
    ], 403);
}

if (yora_mant_bloquea($conexion, 'drivers', $driver_id)) {
    $cfg = yora_mant_cfg($conexion);
    yora_json(['ok' => false, 'mensaje' => $cfg['mensaje']], 503);
}

$token = yora_conductor_abrir_sesion_api($conexion, $driver_id);
$docs = yora_docs_estado($row);
yora_pass_flag_esquema($conexion);
$rowFlag = yora_one($conexion, 'SELECT debe_cambiar_password FROM conductores WHERE id = ?', 'i', $driver_id) ?: $row;

yora_json([
    'ok' => true,
    'driver_id' => $driver_id,
    'nombre' => (string) ($row['nombre'] ?? ''),
    'cedula' => (string) ($row['cedula'] ?? $cedula),
    'token' => $token,
    'verificado' => !empty($docs['verificado']),
    'debe_cambiar_password' => yora_conductor_debe_cambiar_password($rowFlag),
], 200);

