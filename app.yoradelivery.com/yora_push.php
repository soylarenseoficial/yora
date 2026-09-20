<?php
/**
 * Web Push para la app de drivers.
 * Las claves VAPID se generan una vez y se guardan en configuracion_web.
 */
if (defined('YORA_PUSH_LOADED')) {
    return;
}
define('YORA_PUSH_LOADED', true);

function yora_b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function yora_b64u_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'), true);
}

function yora_vapid_asegurar(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $fila = yora_one($db, 'SELECT vapid_public, vapid_private FROM configuracion_web LIMIT 1');
    $pub = trim((string) ($fila['vapid_public'] ?? ''));
    $priv = trim((string) ($fila['vapid_private'] ?? ''));
    if ($pub !== '' && $priv !== '') {
        $cache = ['public' => $pub, 'private' => $priv];
        return $cache;
    }

    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false) {
        error_log('yora_push: no se pudieron crear claves VAPID');
        $cache = ['public' => '', 'private' => ''];
        return $cache;
    }
    openssl_pkey_export($key, $pem);
    $det = openssl_pkey_get_details($key);
    $x = $det['ec']['x'] ?? '';
    $y = $det['ec']['y'] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) {
        $cache = ['public' => '', 'private' => ''];
        return $cache;
    }
    $pub = yora_b64u_encode("\x04" . $x . $y);
    $priv = $pem;
    try {
        yora_exec($db, 'UPDATE configuracion_web SET vapid_public = ?, vapid_private = ? LIMIT 1', 'ss', $pub, $priv);
    } catch (Throwable $e) {
        error_log('yora_push vapid save: ' . $e->getMessage());
    }
    $cache = ['public' => $pub, 'private' => $priv];
    return $cache;
}

function yora_push_guardar(mysqli $db, int $conductor_id, string $endpoint, string $p256dh, string $auth): void
{
    if ($endpoint === '' || $p256dh === '' || $auth === '' || mb_strlen($endpoint) > 500) {
        return;
    }
    yora_exec(
        $db,
        'INSERT INTO push_subscriptions (conductor_id, endpoint, p256dh, auth_key) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE conductor_id = VALUES(conductor_id), p256dh = VALUES(p256dh), auth_key = VALUES(auth_key), actualizado = CURRENT_TIMESTAMP',
        'isss',
        $conductor_id,
        $endpoint,
        $p256dh,
        $auth
    );
}

function yora_ecdsa_der_a_raw(string $der): string
{
    $offset = 2;
    if ((ord($der[1]) & 0x80) !== 0) {
        $offset += (ord($der[1]) & 0x7f);
    }
    $rLen = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLen);
    $offset += 2 + $rLen;
    $sLen = ord($der[$offset + 1]);
    $s = substr($der, $offset + 2, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function yora_push_jwt(string $aud, string $privPem, string $pubB64): string
{
    $header = yora_b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = yora_b64u_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:yoradelivery@gmail.com',
    ]));
    $data = $header . '.' . $payload;
    $raw = '';
    openssl_sign($data, $raw, $privPem, OPENSSL_ALGO_SHA256);
    return $data . '.' . yora_b64u_encode(yora_ecdsa_der_a_raw($raw));
}

function yora_hkdf(string $salt, string $ikm, string $info, int $len): string
{
    return hash_hkdf('sha256', $ikm, $len, $info, $salt);
}

function yora_push_cifrar(string $payload, string $p256dh, string $auth): ?string
{
    $uaPub = yora_b64u_decode($p256dh);
    $authSecret = yora_b64u_decode($auth);
    if (strlen($uaPub) !== 65 || strlen($authSecret) !== 16) {
        return null;
    }

    $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($local === false) {
        return null;
    }
    $localDet = openssl_pkey_get_details($local);
    $asPub = "\x04" . $localDet['ec']['x'] . $localDet['ec']['y'];

    $uaPem = yora_ec_public_pem($uaPub);
    $uaKey = openssl_pkey_get_public($uaPem);
    if ($uaKey === false) {
        return null;
    }
    $shared = openssl_pkey_derive($uaKey, $local);
    if ($shared === false) {
        return null;
    }

    $infoKey = "WebPush: info\x00" . $uaPub . $asPub;
    $ikm = yora_hkdf($authSecret, $shared, $infoKey, 32);
    $salt = random_bytes(16);
    $cek = yora_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = yora_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        return null;
    }
    $rs = pack('N', 4096);
    return $salt . $rs . chr(strlen($asPub)) . $asPub . $cipher . $tag;
}

function yora_ec_public_pem(string $uncomp): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uncomp;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function yora_push_enviar_uno(array $vapid, array $sub, string $payload): bool
{
    $endpoint = (string) $sub['endpoint'];
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $aud = $parts['scheme'] . '://' . $parts['host'];
    $body = yora_push_cifrar($payload, (string) $sub['p256dh'], (string) $sub['auth_key']);
    if ($body === null) {
        return false;
    }
    $jwt = yora_push_jwt($aud, $vapid['private'], $vapid['public']);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'TTL: 120',
            'Urgency: high',
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Avisa a los drivers. $solo_online = true manda solo a los que estan en linea.
 * La APK de Median usa OneSignal; el navegador usa Web Push.
 */
function yora_push_conductores(mysqli $db, string $titulo, string $cuerpo, string $url = '/dashboard.php', bool $solo_online = true): int
{
    $ok = 0;
    $externos = [];
    $players = [];
    $subs_os = [];

    if ($solo_online) {
        $filas = yora_all($db, 'SELECT id FROM conductores WHERE en_linea = 1') ?: [];
        $osRows = yora_all(
            $db,
            "SELECT s.endpoint, s.p256dh, s.auth_key
             FROM push_subscriptions s
             INNER JOIN conductores c ON c.id = s.conductor_id
             WHERE c.en_linea = 1 AND s.endpoint LIKE 'onesignal:%'"
        ) ?: [];
    } else {
        $filas = yora_all($db, 'SELECT id FROM conductores') ?: [];
        $osRows = yora_all($db, "SELECT endpoint, p256dh, auth_key FROM push_subscriptions WHERE endpoint LIKE 'onesignal:%'") ?: [];
    }
    foreach ($filas as $f) {
        $externos[] = 'yora-driver-' . (int) $f['id'];
    }
    foreach ($osRows as $r) {
        $p = trim((string) ($r['p256dh'] ?? ''));
        $a = trim((string) ($r['auth_key'] ?? ''));
        $ep = (string) ($r['endpoint'] ?? '');
        if (str_starts_with($ep, 'onesignal:')) {
            $ext = substr($ep, 10);
            if ($ext !== '') {
                $externos[] = $ext;
            }
        }
        if ($p !== '' && $p !== 'os' && strlen($p) > 8) {
            $players[] = $p;
        }
        if ($a !== '' && $a !== 'os' && strlen($a) > 8) {
            $subs_os[] = $a;
        }
    }

    $ok += yora_onesignal_enviar($db, $externos, $titulo, $cuerpo, $url);
    if ($subs_os) {
        $porSub = yora_onesignal_enviar_ids($db, $titulo, $cuerpo, $url, 'include_subscription_ids', $subs_os);
        if ($porSub > $ok) {
            $ok = $porSub;
        }
    }
    if ($players) {
        $porPlayer = yora_onesignal_enviar_ids($db, $titulo, $cuerpo, $url, 'include_subscription_ids', $players);
        if ($porPlayer > $ok) {
            $ok = $porPlayer;
        }
    }
    $seg = yora_onesignal_enviar_segmento($db, $titulo, $cuerpo, $url);
    if ($seg > $ok) {
        $ok = $seg;
    }

    $vapid = yora_vapid_asegurar($db);
    if ($vapid['public'] === '' || $vapid['private'] === '') {
        return $ok;
    }
    $sql = "SELECT s.endpoint, s.p256dh, s.auth_key FROM push_subscriptions s";
    if ($solo_online) {
        $sql .= " INNER JOIN conductores c ON c.id = s.conductor_id WHERE c.en_linea = 1 AND s.endpoint NOT LIKE 'onesignal:%'";
    } else {
        $sql .= " WHERE s.endpoint NOT LIKE 'onesignal:%'";
    }
    $subs = yora_all($db, $sql);
    $payload = json_encode(['title' => $titulo, 'body' => $cuerpo, 'url' => $url], JSON_UNESCAPED_UNICODE);
    foreach ($subs as $sub) {
        try {
            if (yora_push_enviar_uno($vapid, $sub, $payload)) {
                $ok++;
            }
        } catch (Throwable $e) {
            error_log('yora_push: ' . $e->getMessage());
        }
    }
    return $ok;
}

function yora_onesignal_config(mysqli $db): array
{
    $fila = yora_one($db, 'SELECT onesignal_app_id, onesignal_rest_key FROM configuracion_web LIMIT 1') ?: [];
    return [
        'app_id' => trim((string) ($fila['onesignal_app_id'] ?? '')),
        'rest'   => trim((string) ($fila['onesignal_rest_key'] ?? '')),
    ];
}

function yora_onesignal_post(array $cfg, array $body): array
{
    $auths = ['Key ' . $cfg['rest'], 'Basic ' . $cfg['rest']];
    $ultimo = ['code' => 0, 'recipients' => 0, 'ok' => false, 'error' => ''];
    foreach ($auths as $auth) {
        $ch = curl_init('https://onesignal.com/api/v1/notifications');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: ' . $auth,
            ],
        ]);
        $resp = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($resp, true);
        $err = '';
        if (is_array($json) && isset($json['errors'])) {
            $err = json_encode($json['errors']);
        }
        if ($code < 200 || $code >= 300) {
            error_log('onesignal: HTTP ' . $code . ' ' . substr($resp, 0, 300));
        }
        $ultimo = [
            'code' => $code,
            'recipients' => (int) ($json['recipients'] ?? 0),
            'ok' => $code >= 200 && $code < 300,
            'error' => $err,
        ];
        if ($code !== 401 && $code !== 403) {
            return $ultimo;
        }
    }
    return $ultimo;
}

function yora_onesignal_base(array $cfg, string $titulo, string $cuerpo, string $url): array
{
    $abs = (str_starts_with($url, 'http')) ? $url : ('https://app.yoradelivery.com' . $url);
    return [
        'app_id' => $cfg['app_id'],
        'headings' => ['en' => $titulo, 'es' => $titulo],
        'contents' => ['en' => $cuerpo, 'es' => $cuerpo],
        'data' => ['targetUrl' => $abs, 'url' => $url],
        'priority' => 10,
        'ttl' => 600,
        'android_accent_color' => 'FFE4441B',
        'android_visibility' => 1,
        'android_sound' => 'default',
        'small_icon' => 'ic_stat_yora',
        'target_channel' => 'push',
    ];
}

function yora_onesignal_enviar_ids(mysqli $db, string $titulo, string $cuerpo, string $url, string $campo, array $ids): int
{
    $cfg = yora_onesignal_config($db);
    if ($cfg['app_id'] === '' || $cfg['rest'] === '' || !$ids) {
        return 0;
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $enviados = 0;
    foreach (array_chunk($ids, 200) as $lote) {
        $body = yora_onesignal_base($cfg, $titulo, $cuerpo, $url);
        $body[$campo] = $lote;
        $body['target_channel'] = 'push';
        $r = yora_onesignal_post($cfg, $body);
        $enviados += $r['recipients'];
    }
    return $enviados;
}

function yora_onesignal_enviar_segmento(mysqli $db, string $titulo, string $cuerpo, string $url, string $segmento = 'Subscribed Users'): int
{
    $cfg = yora_onesignal_config($db);
    if ($cfg['app_id'] === '' || $cfg['rest'] === '') {
        return 0;
    }
    $body = yora_onesignal_base($cfg, $titulo, $cuerpo, $url);
    $body['included_segments'] = [$segmento];
    $r = yora_onesignal_post($cfg, $body);
    if (!$r['ok'] || $r['recipients'] === 0) {
        $body['included_segments'] = ['All'];
        $r2 = yora_onesignal_post($cfg, $body);
        if ($r2['ok'] && $r2['recipients'] > $r['recipients']) {
            $r = $r2;
        }
    }
    return $r['recipients'];
}

function yora_onesignal_enviar_filtro(mysqli $db, string $titulo, string $cuerpo, string $url, array $filters): int
{
    $cfg = yora_onesignal_config($db);
    if ($cfg['app_id'] === '' || $cfg['rest'] === '' || !$filters) {
        return 0;
    }
    $body = yora_onesignal_base($cfg, $titulo, $cuerpo, $url);
    $body['filters'] = $filters;
    $r = yora_onesignal_post($cfg, $body);
    return $r['ok'] ? $r['recipients'] : 0;
}

function yora_onesignal_enviar(mysqli $db, array $external_ids, string $titulo, string $cuerpo, string $url): int
{
    $cfg = yora_onesignal_config($db);
    if ($cfg['app_id'] === '' || $cfg['rest'] === '' || !$external_ids) {
        return 0;
    }
    $external_ids = array_values(array_unique(array_filter($external_ids)));
    $enviados = 0;
    foreach (array_chunk($external_ids, 200) as $lote) {
        $nuevo = yora_onesignal_base($cfg, $titulo, $cuerpo, $url);
        $nuevo['include_aliases'] = ['external_id' => $lote];
        $nuevo['target_channel'] = 'push';
        $r = yora_onesignal_post($cfg, $nuevo);
        if (!$r['ok'] || $r['recipients'] === 0) {
            $viejo = yora_onesignal_base($cfg, $titulo, $cuerpo, $url);
            $viejo['include_external_user_ids'] = $lote;
            $viejo['channel_for_external_user_ids'] = 'push';
            $r2 = yora_onesignal_post($cfg, $viejo);
            if ($r2['ok'] && $r2['recipients'] > $r['recipients']) {
                $r = $r2;
            }
        }
        $enviados += $r['recipients'];
    }
    return $enviados;
}

/**
 * Aviso a UN conductor concreto (documentos aprobados, rechazados, etc.).
 * Prueba por OneSignal (la APK) y tambien por Web Push (navegador). Nunca
 * interrumpe: si el push falla, el panel sigue guardando el cambio.
 */
function yora_push_conductor(mysqli $db, int $conductor_id, string $titulo, string $cuerpo, string $url = '/ajustes_documentos.php'): int
{
    if ($conductor_id <= 0) {
        return 0;
    }
    $ok = 0;
    try {
        $ok += yora_onesignal_enviar($db, ['yora-driver-' . $conductor_id], $titulo, $cuerpo, $url);
    } catch (Throwable $e) {
        error_log('yora_push_conductor onesignal: ' . $e->getMessage());
    }
    try {
        $vapid = yora_vapid_asegurar($db);
        if ($vapid['public'] === '' || $vapid['private'] === '') {
            return $ok;
        }
        $subs = yora_all(
            $db,
            "SELECT endpoint, p256dh, auth_key FROM push_subscriptions WHERE conductor_id = ? AND endpoint NOT LIKE 'onesignal:%'",
            'i',
            $conductor_id
        );
        $payload = (string) json_encode(['title' => $titulo, 'body' => $cuerpo, 'url' => $url], JSON_UNESCAPED_UNICODE);
        foreach ($subs as $sub) {
            if (yora_push_enviar_uno($vapid, $sub, $payload)) {
                $ok++;
            }
        }
    } catch (Throwable $e) {
        error_log('yora_push_conductor webpush: ' . $e->getMessage());
    }
    return $ok;
}
