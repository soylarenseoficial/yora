<?php
/**
 * Capa de seguridad compartida de YORA.
 * Se carga desde config.php (fuera de public_html).
 */

if (defined('YORA_SECURITY_LOADED')) {
    return;
}
define('YORA_SECURITY_LOADED', true);

function yora_boot(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    yora_session_start();
    yora_security_headers();
    yora_no_store_si_app();
    if (isset($GLOBALS['conexion']) && $GLOBALS['conexion'] instanceof mysqli) {
        yora_ensure_schema($GLOBALS['conexion']);
    }
}

/**
 * Crea las tablas y columnas nuevas la primera vez que se abre el sistema.
 * Sin esto, las mejoras (tarifas por tramos, club, clientes, codigos de
 * factura, tasa BCV, sesion unica) reventarian al consultar campos que
 * todavia no existen en el VPS.
 */
function yora_ensure_schema(mysqli $db): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;

    $marca = sys_get_temp_dir() . '/yora_schema_v16.ok';
    if (is_file($marca) && (time() - filemtime($marca)) < 3600) {
        return;
    }

    $sentencias = [
        "CREATE TABLE IF NOT EXISTS tarifas_tramos (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            orden INT NOT NULL DEFAULT 1,
            km_hasta DECIMAL(6,2) DEFAULT NULL,
            precio_km DECIMAL(10,2) NOT NULL DEFAULT 0.40
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS niveles_comercio (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nivel INT NOT NULL,
            nombre VARCHAR(40) NOT NULL,
            pedidos_desde INT NOT NULL DEFAULT 0,
            pedidos_hasta INT DEFAULT NULL,
            bono_mensual DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            margen_credito DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            color VARCHAR(20) NOT NULL DEFAULT '#94a3b8',
            estrellas INT NOT NULL DEFAULT 0,
            beneficios TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS clientes_comercio (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            comercio_id INT NOT NULL,
            cedula VARCHAR(30) NOT NULL,
            nombre VARCHAR(120) DEFAULT '',
            telefono VARCHAR(30) DEFAULT '',
            referencia VARCHAR(255) DEFAULT '',
            pedidos INT NOT NULL DEFAULT 1,
            ultima_vez TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_comercio_cedula (comercio_id, cedula)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conductor_id INT NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth_key VARCHAR(80) NOT NULL,
            creado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_endpoint (endpoint),
            KEY idx_conductor (conductor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS productos (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            comercio_id INT NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT,
            precio DECIMAL(10,2) NOT NULL DEFAULT 0,
            imagen_url VARCHAR(255) DEFAULT NULL,
            estado ENUM('activo','inactivo') DEFAULT 'activo',
            categoria VARCHAR(80) DEFAULT '',
            orden INT NOT NULL DEFAULT 0,
            KEY comercio_id (comercio_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS usuarios_app (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL,
            telefono VARCHAR(30) NOT NULL,
            password VARCHAR(255) NOT NULL,
            lat DECIMAL(10,7) DEFAULT NULL,
            lng DECIMAL(10,7) DEFAULT NULL,
            estatus VARCHAR(20) NOT NULL DEFAULT 'activo',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_telefono (telefono)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS recargas_clientes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            comanda_id INT DEFAULT NULL,
            monto DECIMAL(10,2) NOT NULL DEFAULT 0,
            monto_bs DECIMAL(12,2) NOT NULL DEFAULT 0,
            tasa_bcv DECIMAL(12,4) NOT NULL DEFAULT 0,
            banco_origen VARCHAR(40) NOT NULL DEFAULT '',
            referencia VARCHAR(80) NOT NULL DEFAULT '',
            fecha_pago DATE DEFAULT NULL,
            estatus VARCHAR(20) NOT NULL DEFAULT 'En Revisión',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_usuario (usuario_id),
            KEY idx_estatus (estatus)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($sentencias as $sql) {
        if (!$db->query($sql)) {
            error_log('yora_ensure_schema: ' . $db->error);
        }
    }

    $columnas = [
        'configuracion_web' => [
            'tasa_bcv'       => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
            'tasa_bcv_fecha' => 'DATE DEFAULT NULL',
            'tasa_bcv_fuente'=> "VARCHAR(40) DEFAULT NULL",
            'tasa_bcv_manual'=> 'TINYINT(1) NOT NULL DEFAULT 0',
            'tarifa_minima'  => 'DECIMAL(10,2) NOT NULL DEFAULT 1.00',
            'vapid_public'      => 'TEXT',
            'vapid_private'     => 'TEXT',
            'onesignal_app_id'  => 'VARCHAR(64) DEFAULT NULL',
            'onesignal_rest_key'=> 'VARCHAR(120) DEFAULT NULL',
            'play_store_url'    => 'VARCHAR(255) DEFAULT NULL',
            'app_store_url'     => 'VARCHAR(255) DEFAULT NULL',
                        'whatsapp_publico'  => 'VARCHAR(30) DEFAULT NULL',
            'mant_drivers'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mant_comercios'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mant_clientes'     => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mant_whitelist_drivers'   => 'TEXT',
            'mant_whitelist_comercios' => 'TEXT',
            'mant_whitelist_clientes'  => 'TEXT',

        ],
        'usuarios_admin' => [
            'rol' => "VARCHAR(20) NOT NULL DEFAULT 'super'",
        ],
        'productos' => [
            'categoria' => "VARCHAR(80) DEFAULT ''",
            'orden'     => 'INT NOT NULL DEFAULT 0',
        ],
        'comercios' => [
            'deuda_desde'     => 'DATETIME DEFAULT NULL',
            'bloqueado_deuda' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'bono_mes'        => 'VARCHAR(7) DEFAULT NULL',
            'slug'            => 'VARCHAR(80) DEFAULT NULL',
            'descripcion'     => 'TEXT',
            'categoria'       => 'VARCHAR(80) DEFAULT NULL',
            'perfil_publico'  => 'TINYINT(1) NOT NULL DEFAULT 0',
            'horario'         => 'VARCHAR(120) DEFAULT NULL',
            'instagram'       => 'VARCHAR(120) DEFAULT NULL',
            'correo'          => 'VARCHAR(120) DEFAULT NULL',
            'entregas_totales'=> 'INT NOT NULL DEFAULT 0',
            'envios_totales'  => 'INT NOT NULL DEFAULT 0',
            'gestion_menu'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ultima_conexion' => 'DATETIME DEFAULT NULL',
            'hora_abre'       => 'TIME DEFAULT NULL',
            'hora_cierra'     => 'TIME DEFAULT NULL',
            'estatus'         => "VARCHAR(20) NOT NULL DEFAULT 'activo'",
            'tipo_comercio'   => "VARCHAR(40) NOT NULL DEFAULT 'Pequeño'",
        ],
        'conductores' => [
            'ultima_lat' => 'DECIMAL(10,7) DEFAULT NULL',
            'ultima_lng' => 'DECIMAL(10,7) DEFAULT NULL',
            'ultima_gps' => 'DATETIME DEFAULT NULL',
            'ultima_heading' => 'DECIMAL(6,2) DEFAULT NULL',
            'ultima_acc' => 'DECIMAL(8,2) DEFAULT NULL',
        ],
        'solicitudes_retiro' => [
            'fecha_pago' => 'DATETIME DEFAULT NULL',
        ],
        'usuarios_app' => [
            'correo'            => 'VARCHAR(120) DEFAULT NULL',
            'cedula'            => 'VARCHAR(20) DEFAULT NULL',
            'foto_url'          => 'VARCHAR(255) DEFAULT NULL',
            'direccion'         => 'VARCHAR(255) DEFAULT NULL',
            'selfie_url'        => 'VARCHAR(255) DEFAULT NULL',
            'cedula_foto_url'   => 'VARCHAR(255) DEFAULT NULL',
            'estado_documentos' => "VARCHAR(40) NOT NULL DEFAULT 'Pendiente'",
            'docs_enviado_en'   => 'DATETIME DEFAULT NULL',
            'docs_revision'     => 'TEXT NULL',
            'billetera'         => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        ],
        'comandas' => [
            'codigo'         => 'VARCHAR(16) DEFAULT NULL',
            'lote_id'        => 'VARCHAR(32) DEFAULT NULL',
            'fecha_entrega'  => 'DATETIME DEFAULT NULL',
            'foto_recogida'  => 'VARCHAR(255) DEFAULT NULL',
            'foto_entrega'   => 'VARCHAR(255) DEFAULT NULL',
            'tipo_comanda'   => "VARCHAR(20) NOT NULL DEFAULT 'delivery'",
            'direccion_recogida' => 'VARCHAR(500) DEFAULT NULL',
            'lat_recogida'   => 'DECIMAL(10,7) DEFAULT NULL',
            'lng_recogida'   => 'DECIMAL(10,7) DEFAULT NULL',
            'lat_entrega'    => 'DECIMAL(10,7) DEFAULT NULL',
            'lng_entrega'    => 'DECIMAL(10,7) DEFAULT NULL',
            'usuario_id'     => 'INT DEFAULT NULL',
            'geocerca_libre' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ];

    foreach ($columnas as $tabla => $lista) {
        foreach ($lista as $col => $def) {
            $existe = $db->query("SHOW COLUMNS FROM `{$tabla}` LIKE '" . $db->real_escape_string($col) . "'");
            if ($existe && $existe->num_rows === 0) {
                if (!$db->query("ALTER TABLE `{$tabla}` ADD COLUMN `{$col}` {$def}")) {
                    error_log('yora_ensure_schema alter: ' . $db->error);
                }
            }
        }
    }

    // Mandaditos de usuarios comunes: no hay restaurante que pague.
    $col_rest = $db->query("SHOW COLUMNS FROM comandas LIKE 'comercio_id'");
    if ($col_rest && ($info = $col_rest->fetch_assoc()) && stripos((string) ($info['Null'] ?? ''), 'YES') === false) {
        $db->query('ALTER TABLE comandas MODIFY comercio_id INT DEFAULT NULL');
    }

    $idx = $db->query("SHOW INDEX FROM comandas WHERE Key_name = 'codigo'");
    if ($idx && $idx->num_rows === 0) {
        $db->query('ALTER TABLE comandas ADD UNIQUE KEY codigo (codigo)');
    }

    $hay_tramos = $db->query('SELECT COUNT(*) AS n FROM tarifas_tramos');
    if ($hay_tramos && (int) ($hay_tramos->fetch_assoc()['n'] ?? 0) === 0) {
        $precio = 0.40;
        $fila = $db->query('SELECT precio_km FROM configuracion_web LIMIT 1');
        if ($fila && ($r = $fila->fetch_assoc()) && (float) $r['precio_km'] > 0) {
            $precio = (float) $r['precio_km'];
        }
        $db->query("INSERT INTO tarifas_tramos (orden, km_hasta, precio_km) VALUES
            (1, 8.00, {$precio}),
            (2, 12.00, " . round($precio * 0.75, 2) . "),
            (3, NULL, " . round($precio * 0.60, 2) . ")");
    }

    $hay_niveles = $db->query('SELECT COUNT(*) AS n FROM niveles_comercio');
    if ($hay_niveles && (int) ($hay_niveles->fetch_assoc()['n'] ?? 0) === 0) {
        $db->query("INSERT INTO niveles_comercio (nivel, nombre, pedidos_desde, pedidos_hasta, bono_mensual, margen_credito, color, estrellas, beneficios) VALUES
            (1, 'Nivel 1', 0, 50, 0.00, 0.00, '#64748b', 0, 'Acceso total al panel'),
            (2, 'Nivel 2', 51, 150, 5.00, 10.00, '#b45309', 1, '5$ gratis acreditados a la billetera (mensual)|Bloque de comanda color bronce con estrella|Margen de respaldo de -10$ (24 horas para recargar)'),
            (3, 'Nivel 3', 151, 300, 10.00, 20.00, '#64748b', 2, '10$ gratis acreditados a la billetera (mensual)|Bloque de comanda color plata con estrella|Margen de respaldo de -20$ (24 horas para recargar)'),
            (4, 'Nivel 4', 301, 500, 15.00, 30.00, '#ca8a04', 3, '15$ gratis acreditados a la billetera (mensual)|Bloque de comanda color oro con estrella|Margen de respaldo de -30$ (24 horas para recargar)')");
    }

    @touch($marca);
}

function yora_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/** Evita que Chrome/PWA sirvan login o paneles viejos y dejen la sesión trabada. */
function yora_no_store(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function yora_no_store_si_app(): void
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $es_app = in_array($host, ['app.yoradelivery.com', 'comercios.yoradelivery.com', 'client.yoradelivery.com'], true);
    $logueado = !empty($_SESSION['conductor_id']) || !empty($_SESSION['comercio_id'])
        || !empty($_SESSION['usuario_id']) || !empty($_SESSION['admin_logged']);
    if ($es_app || $logueado) {
        yora_no_store();
    }
}

function yora_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('YORASESSID');
    $dias = 14 * 24 * 3600;
    session_set_cookie_params([
        'lifetime' => $dias,
        'path' => '/',
        'secure' => yora_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $dias);
    session_start();
}

function yora_session_regenerate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function yora_logout(string $redirect): void
{
    yora_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $redirect);
    exit;
}

function yora_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
}

function yora_allowed_hosts(): array
{
    return [
        'yoradelivery.com',
        'www.yoradelivery.com',
        'app.yoradelivery.com',
        'comercios.yoradelivery.com',
        'client.yoradelivery.com',
    ];
}

function yora_host_allowed(?string $url): bool
{
    if (!$url) {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    return in_array(strtolower($host), yora_allowed_hosts(), true);
}

function yora_verify_same_origin(): bool
{
    $origen = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origen === '') {
        return false;
    }
    if (yora_host_allowed($origen)) {
        return true;
    }

    // Mismo host que atiende la peticion: es same-origin real aunque el
    // dominio no este en la lista (entornos de prueba, staging, IP directa).
    $host_actual = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host_origen = strtolower((string) parse_url($origen, PHP_URL_HOST));
    if ($host_actual !== '' && $host_origen !== '') {
        $puerto = parse_url($origen, PHP_URL_PORT);
        return $host_origen === $host_actual
            || ($puerto && ($host_origen . ':' . $puerto) === $host_actual);
    }

    return false;
}

/** CSRF, mismo origen, o PWA/WebView que manda Sec-Fetch-Site pero no Origin. */
function yora_peticion_propia(): bool
{
    if (yora_csrf_ok()) {
        return true;
    }
    if (yora_verify_same_origin()) {
        return true;
    }
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    return in_array($site, ['same-origin', 'same-site'], true);
}

function yora_csrf_token(): string
{
    yora_session_start();
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function yora_csrf_ok(): bool
{
    yora_session_start();
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent)
        && isset($_SESSION['_csrf'])
        && is_string($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $sent);
}

function yora_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(yora_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function yora_require_write(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        yora_fail('Método no permitido');
    }
    if (!yora_peticion_propia()) {
        http_response_code(403);
        yora_fail('Solicitud rechazada');
    }
}

function yora_fail(string $msg, int $code = 400): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['status' => 'error', 'mensaje' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function yora_json(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function yora_require_comercio(): int
{
    yora_session_start();
    if (empty($_SESSION['comercio_id'])) {
        yora_fail('Sesión expirada. Recarga la página.', 401);
    }
    return (int) $_SESSION['comercio_id'];
}

function yora_require_usuario(): int
{
    yora_session_start();
    if (empty($_SESSION['usuario_id'])) {
        yora_fail('Sesión expirada. Recarga la página.', 401);
    }
    return (int) $_SESSION['usuario_id'];
}

/**
 * Sesion unica del conductor.
 *
 * Al iniciar sesion se guarda un token nuevo en conductores.token_app y una
 * copia en la sesion. Si el mismo driver entra desde otro telefono, el token
 * de la base cambia y la sesion anterior deja de coincidir, asi que solo puede
 * haber una cuenta abierta a la vez.
 */
function yora_conductor_abrir_sesion(mysqli $db, int $id): void
{
    yora_session_start();
    yora_session_regenerate();
    $token = bin2hex(random_bytes(32));
    yora_exec($db, 'UPDATE conductores SET token_app = ? WHERE id = ?', 'si', $token, $id);
    $_SESSION['conductor_id'] = $id;
    $_SESSION['conductor_token'] = $token;
}

function yora_conductor_cerrar_sesion(mysqli $db, int $id): void
{
    yora_exec($db, 'UPDATE conductores SET token_app = NULL WHERE id = ?', 'i', $id);
}

function yora_conductor_sesion_vigente(mysqli $db, int $id): bool
{
    $fila = yora_one($db, 'SELECT token_app FROM conductores WHERE id = ?', 'i', $id);
    if (!$fila) {
        return false;
    }
    $activo = (string) ($fila['token_app'] ?? '');
    // Las sesiones que ya estaban abiertas antes de activar el token siguen
    // siendo validas hasta el proximo login: nadie queda fuera al desplegar.
    if ($activo === '') {
        return true;
    }
    $mio = (string) ($_SESSION['conductor_token'] ?? '');
    if ($mio !== '' && hash_equals($activo, $mio)) {
        return true;
    }
    // Sesión anterior al token (o PWA que perdió el token en cache): reciclarla.
    if ($mio === '' && !empty($_SESSION['conductor_id']) && (int) $_SESSION['conductor_id'] === $id) {
        $_SESSION['conductor_token'] = $activo;
        return true;
    }
    return false;
}

function yora_require_conductor(): int
{
    yora_session_start();
    if (empty($_SESSION['conductor_id'])) {
        yora_fail('Sesión inválida', 401);
    }
    $id = (int) $_SESSION['conductor_id'];

    $db = $GLOBALS['conexion'] ?? null;
    if ($db instanceof mysqli && !yora_conductor_sesion_vigente($db, $id)) {
        $_SESSION = [];
        yora_fail('Tu cuenta se abrió en otro dispositivo. Vuelve a iniciar sesión.', 401);
    }
    return $id;
}

function yora_require_admin(bool $json = false): void
{
    yora_session_start();
    if (empty($_SESSION['admin_logged'])) {
        if ($json) {
            yora_fail('No autorizado', 401);
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * Modo mantenimiento del sistema (HQ → drivers / comercios / clientes / web).
 */
function yora_mant_parse_ids($raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $raw) as $p) {
        $n = (int) trim($p);
        if ($n > 0) {
            $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

function yora_mant_cfg(mysqli $db): array
{
    static $cache = null;
    static $ts = 0;
    if (is_array($cache) && (time() - $ts) < 4) {
        return $cache;
    }
    $row = [];
    try {
        $row = yora_one(
            $db,
            'SELECT modo_mantenimiento, titulo_mantenimiento, mensaje_mantenimiento,
                    mant_drivers, mant_comercios, mant_clientes,
                    mant_whitelist_drivers, mant_whitelist_comercios, mant_whitelist_clientes
             FROM configuracion_web LIMIT 1'
        ) ?: [];
    } catch (Throwable $e) {
        $row = yora_one($db, 'SELECT modo_mantenimiento, titulo_mantenimiento, mensaje_mantenimiento FROM configuracion_web LIMIT 1') ?: [];
    }
    $cache = [
        'web' => (int) ($row['modo_mantenimiento'] ?? 0) === 1,
        'drivers' => (int) ($row['mant_drivers'] ?? 0) === 1,
        'comercios' => (int) ($row['mant_comercios'] ?? 0) === 1,
        'clientes' => (int) ($row['mant_clientes'] ?? 0) === 1,
        'titulo' => trim((string) ($row['titulo_mantenimiento'] ?? '')) ?: 'Estamos en mantenimiento',
        'mensaje' => trim((string) ($row['mensaje_mantenimiento'] ?? '')) ?: 'Yora está en mantenimiento para mejorar el servicio. Volvemos pronto.',
        'wl_drivers' => yora_mant_parse_ids($row['mant_whitelist_drivers'] ?? ''),
        'wl_comercios' => yora_mant_parse_ids($row['mant_whitelist_comercios'] ?? ''),
        'wl_clientes' => yora_mant_parse_ids($row['mant_whitelist_clientes'] ?? ''),
    ];
    $ts = time();
    return $cache;
}

function yora_mant_es_admin_hq(): bool
{
    yora_session_start();
    return !empty($_SESSION['admin_logged']);
}

/**
 * true = bloquear acceso al canal.
 * Los IDs en whitelist (cuentas de prueba del equipo) sí pueden entrar.
 */
function yora_mant_bloquea(mysqli $db, string $canal, ?int $id = null): bool
{
    if (yora_mant_es_admin_hq()) {
        return false;
    }
    $c = yora_mant_cfg($db);
    $on = false;
    $wl = [];
    if ($canal === 'drivers') {
        $on = $c['drivers'];
        $wl = $c['wl_drivers'];
    } elseif ($canal === 'comercios') {
        $on = $c['comercios'];
        $wl = $c['wl_comercios'];
    } elseif ($canal === 'clientes') {
        $on = $c['clientes'];
        $wl = $c['wl_clientes'];
    } elseif ($canal === 'web') {
        $on = $c['web'];
    }
    if (!$on) {
        return false;
    }
    if ($id !== null && $id > 0 && in_array($id, $wl, true)) {
        return false;
    }
    return true;
}

function yora_mant_exigir(mysqli $db, string $canal, ?int $id = null): void
{
    if (!yora_mant_bloquea($db, $canal, $id)) {
        return;
    }
    $c = yora_mant_cfg($db);
    $titulo = $c['titulo'];
    $mensaje = $c['mensaje'];

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $esJson = str_contains($accept, 'application/json')
        || str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');

    if ($esJson || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/'))) {
        yora_fail($mensaje, 503);
    }

    if ($canal === 'drivers' && $id && $id > 0) {
        @yora_exec($db, 'UPDATE conductores SET en_linea = 0 WHERE id = ?', 'i', $id);
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $t = yora_h($titulo);
    $m = yora_h($mensaje);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $t . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;700;800&display=swap" rel="stylesheet">'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;color:#fff;font-family:Poppins,sans-serif;padding:24px;text-align:center}'
        . '.box{max-width:420px}.badge{display:inline-block;background:#e4441b;color:#fff;font-size:.72rem;font-weight:800;letter-spacing:.6px;padding:6px 10px;border-radius:999px;margin-bottom:16px}'
        . 'h1{font-size:1.55rem;margin:0 0 12px;line-height:1.25}p{color:#cbd5e1;font-size:.95rem;line-height:1.5;margin:0}</style></head><body>'
        . '<div class="box"><div class="badge">MANTENIMIENTO</div><h1>' . $t . '</h1><p>' . $m . '</p></div></body></html>';
    exit;
}

function yora_admin_rol(): string
{
    $rol = strtolower(trim((string) ($_SESSION['admin_rol'] ?? 'super')));
    return in_array($rol, ['super', 'comercios', 'drivers'], true) ? $rol : 'super';
}

function yora_admin_paginas(): array
{
    return [
        'super' => [
            'index.php', 'comercios.php', 'conductores.php', 'clientes.php', 'verificaciones.php', 'comandas.php',
            'gestion_pagos.php', 'recargas.php', 'soporte.php', 'tarifas.php',
            'notificaciones.php', 'ajustes.php', 'directorio.php', 'usuarios.php',
            'verificar_imagenes.php', 'mapa.php',
        ],
        'comercios' => [
            'comercios.php', 'clientes.php', 'comandas.php', 'recargas.php', 'soporte.php', 'directorio.php', 'mapa.php',
        ],
        'drivers' => [
            'conductores.php', 'verificaciones.php', 'comandas.php', 'gestion_pagos.php', 'mapa.php',
        ],
    ];
}

function yora_admin_puede(string $archivo): bool
{
    $archivo = basename($archivo);
    $map = yora_admin_paginas();
    $rol = yora_admin_rol();
    return in_array($archivo, $map[$rol] ?? [], true);
}

function yora_admin_inicio(): string
{
    $map = [
        'comercios' => 'comercios.php',
        'drivers'   => 'conductores.php',
        'super'     => 'index.php',
    ];
    return $map[yora_admin_rol()] ?? 'index.php';
}

function yora_admin_nombre(): string
{
    yora_session_start();
    $nombre = trim((string) ($_SESSION['admin_nombre'] ?? ''));
    return $nombre !== '' ? $nombre : 'HQ';
}

function yora_require_admin_pagina(string $archivo, bool $json = false): void
{
    yora_require_admin($json);
    if (yora_admin_puede($archivo)) {
        return;
    }
    if ($json) {
        yora_fail('No autorizado', 403);
    }
    header('Location: ' . yora_admin_inicio());
    exit;
}

function yora_titulo_web(?string $s): string
{
    $s = str_ireplace(['<br />', '<br/>', '<br>'], "\n", (string) $s);
    return nl2br(yora_h($s), false);
}

function yora_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function yora_rate_limit(string $key, int $max, int $seconds): bool
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yora_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, static function ($t) use ($now, $seconds) {
        return ($now - (int) $t) < $seconds;
    }));
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function yora_query(mysqli $db, string $sql, string $types = '', ...$params): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('YORA prepare: ' . $db->error);
        throw new RuntimeException('Error interno');
    }
    if ($types !== '' && $params) {
        $bind = [];
        foreach ($params as $i => $_) {
            $bind[$i] = &$params[$i];
        }
        array_unshift($bind, $types);
        $stmt->bind_param(...$bind);
    }
    if (!$stmt->execute()) {
        error_log('YORA execute: ' . $stmt->error);
        $stmt->close();
        throw new RuntimeException('Error interno');
    }
    return $stmt;
}

function yora_one(mysqli $db, string $sql, string $types = '', ...$params): ?array
{
    $stmt = yora_query($db, $sql, $types, ...$params);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function yora_all(mysqli $db, string $sql, string $types = '', ...$params): array
{
    $stmt = yora_query($db, $sql, $types, ...$params);
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function yora_exec(mysqli $db, string $sql, string $types = '', ...$params): int
{
    $stmt = yora_query($db, $sql, $types, ...$params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

function yora_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Las comandas guardan el punto de entrega dentro del texto de la direccion
 * con el formato "... | GPS: lat, lng". Devuelve [lat, lng] o null.
 */
function yora_gps_desde_direccion(?string $direccion): ?array
{
    if (!$direccion || stripos($direccion, 'GPS') === false) {
        return null;
    }
    $partes = preg_split('/\|\s*GPS\s*:?/i', $direccion);
    if (!isset($partes[1])) {
        return null;
    }
    $coords = explode(',', $partes[1]);
    if (count($coords) < 2) {
        return null;
    }
    $lat = (float) trim($coords[0]);
    $lng = (float) trim($coords[1]);
    return yora_coords_ok($lat, $lng) ? [$lat, $lng] : null;
}

/**
 * Devuelve la URL solo si es http(s). Bloquea javascript:, data: y HTML inyectado.
 */
function yora_safe_url(?string $url, string $fallback = ''): string
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return $fallback;
    }
    if (preg_match('/[\s"\'<>]/', $url)) {
        return $fallback;
    }
    return $url;
}

function yora_valid_date(?string $d): ?string
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

function yora_mime_imagen(string $mime): ?string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    return $map[strtolower(trim($mime))] ?? null;
}

function yora_upload(array $file, string $destDir, array $allowedExt = ['jpg', 'jpeg', 'png', 'webp'], int $maxBytes = 5242880, bool $allowPdf = false): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if ($allowPdf) {
        $allowedExt[] = 'pdf';
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $map = [
        'jpg' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
        'png' => ['image/png', 'image/x-png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'jfif' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
        'jpe' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
    ];
    // Foto del celular sin extensión, o .jfif / .heic disfrazado: usamos el MIME real.
    if (!isset($map[$ext]) || !in_array($mime, $map[$ext], true)) {
        $desde_mime = yora_mime_imagen($mime);
        if ($desde_mime && in_array($desde_mime, $allowedExt, true)) {
            $ext = $desde_mime;
        } elseif ($desde_mime === 'jpg' && in_array('jpeg', $allowedExt, true)) {
            $ext = 'jpg';
        } elseif ($allowPdf && in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            $ext = 'pdf';
        } else {
            return null;
        }
    }
    if (!in_array($ext, $allowedExt, true) && !($ext === 'jpg' && in_array('jpeg', $allowedExt, true))) {
        return null;
    }
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }
    $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $dest = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }
    // move_uploaded_file respeta el umask de PHP-FPM. Con umask 0077 el archivo
    // queda 0600 y el proceso que sirve la web no puede leerlo (403 al abrirlo).
    @chmod($dest, 0644);
    return $name;
}

/**
 * Devuelve una URL utilizable en src/href para un archivo subido.
 *
 * En la base de datos conviven dos formatos historicos: rutas relativas al
 * dominio ("uploads/x.jpg", "/uploads/x.jpg") que guardan el panel HQ y el
 * formulario de registro, y URLs absolutas ("https://app.yoradelivery.com/...")
 * que guardan las apps de conductor y comercio. Las relativas se devuelven
 * ancladas a la raiz del dominio para que funcionen tambien desde subcarpetas
 * como /hq_sistema_interno_v1/.
 *
 * $base sirve cuando el archivo pertenece a otro subdominio (por ejemplo, un
 * logo de comercio mostrado dentro de la app del conductor).
 */
function yora_url_archivo(?string $ruta, string $fallback = '', string $base = ''): string
{
    $ruta = trim((string) $ruta);
    if ($ruta === '') {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $ruta)) {
        return yora_safe_url($ruta, $fallback);
    }
    // Sin http(s) solo aceptamos una ruta interna limpia: nada de "javascript:",
    // "data:", "//otro-dominio" ni saltos de carpeta.
    if (preg_match('/[\s"\'<>:]/', $ruta) || strpos($ruta, '..') !== false) {
        return $fallback;
    }
    return rtrim($base, '/') . '/' . ltrim($ruta, '/');
}

/**
 * Carpeta fisica de "uploads" de otro subdominio de Yora, vista desde el
 * dominio principal.
 *
 * En el VPS cada sitio vive en /home/<usuario>/domains/<dominio>/public_html,
 * asi que desde el DOCUMENT_ROOT del dominio principal hay que subir DOS
 * niveles para llegar a la carpeta "domains", no uno. Devuelve null si no
 * encuentra una carpeta real: es preferible avisar a guardar el archivo en un
 * arbol inventado que despues nadie sirve por HTTP.
 */
function yora_dir_uploads_dominio(string $dominio): ?string
{
    $root = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $aqui = rtrim(str_replace('\\', '/', __DIR__), '/');
    $candidatos = [];
    if ($root !== '') {
        $candidatos[] = dirname($root, 2) . '/' . $dominio . '/public_html/uploads/';
        $candidatos[] = dirname($root, 2) . '/domains/' . $dominio . '/public_html/uploads/';
        $candidatos[] = dirname($root) . '/' . $dominio . '/public_html/uploads/';
        $candidatos[] = dirname($root) . '/domains/' . $dominio . '/public_html/uploads/';
        $candidatos[] = dirname($root, 2) . '/domains/' . $dominio . '/public_html/uploads/';
        $candidatos[] = $root . '/uploads/';
    }
    $candidatos[] = $aqui . '/public_html/uploads/';
    $candidatos[] = dirname($aqui) . '/domains/' . $dominio . '/public_html/uploads/';
    $candidatos[] = dirname($aqui) . '/' . $dominio . '/public_html/uploads/';
    $candidatos[] = '/home/yoradelivery/domains/' . $dominio . '/public_html/uploads/';
    $candidatos[] = '/var/www/' . $dominio . '/public_html/uploads/';
    $candidatos[] = '/var/www/' . $dominio . '/uploads/';

    $vistos = [];
    foreach ($candidatos as $dir) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        if (isset($vistos[$dir])) {
            continue;
        }
        $vistos[$dir] = true;
        if (!is_dir($dir)) {
            $padre = dirname(rtrim($dir, '/'));
            if (is_dir($padre)) {
                @mkdir($dir, 0775, true);
            }
        }
        if (is_dir($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                @chmod($dir, 0777);
            }
            if (is_writable($dir)) {
                return $dir;
            }
        }
    }
    return null;
}

function yora_clave_aleatoria(int $largo = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $clave = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $largo; $i++) {
        $clave .= $chars[random_int(0, $max)];
    }
    return $clave;
}

/**
 * Relee la foto subida y la deja en un JPG/PNG estándar.
 * Así no depende de la extensión del celular (.jfif, sin extensión, etc.)
 * y se puede reintentar el guardado en varias carpetas (file_put_contents
 * no consume el tmp como sí hace move_uploaded_file).
 */
function yora_normalizar_imagen_upload(array $file, int $maxLado = 1400): array
{
    $errores = [
        UPLOAD_ERR_INI_SIZE   => 'La imagen pesa más de lo que permite el servidor. Sube una más liviana.',
        UPLOAD_ERR_FORM_SIZE  => 'La imagen supera el máximo de 5 MB.',
        UPLOAD_ERR_PARTIAL    => 'La imagen se subió a medias. Inténtalo de nuevo.',
        UPLOAD_ERR_NO_FILE    => 'No se recibió ninguna imagen.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para subidas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
    ];
    $codigo = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($codigo !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $errores[$codigo] ?? 'No se pudo recibir la imagen.'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No se recibió ninguna imagen.'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5242880) {
        return ['ok' => false, 'error' => 'La imagen debe pesar menos de 5 MB.'];
    }
    $raw = @file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'error' => 'No se pudo leer la imagen subida.'];
    }
    $info = @getimagesizefromstring($raw);
    if (!$info || empty($info['mime'])) {
        return ['ok' => false, 'error' => 'El archivo no es una imagen válida. Usa JPG o PNG (las fotos HEIC del iPhone no sirven: en el iPhone elige “Más compatible”).'];
    }
    $ext = yora_mime_imagen((string) $info['mime']);
    if (!$ext) {
        return ['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG o WEBP.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => true, 'bin' => $raw, 'ext' => $ext];
    }
    $im = @imagecreatefromstring($raw);
    if (!$im) {
        return ['ok' => false, 'error' => 'No se pudo procesar la imagen. Prueba con otro JPG o PNG.'];
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > $maxLado || $h > $maxLado) {
        $scale = $maxLado / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($ext === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
        }
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }
    ob_start();
    if ($ext === 'png' && function_exists('imagepng')) {
        imagesavealpha($im, true);
        imagepng($im, null, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        imagewebp($im, null, 82);
    } else {
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($im);
        }
        imagejpeg($im, null, 86);
        $ext = 'jpg';
    }
    $bin = (string) ob_get_clean();
    imagedestroy($im);
    if ($bin === '') {
        return ['ok' => false, 'error' => 'No se pudo procesar la imagen.'];
    }
    return ['ok' => true, 'bin' => $bin, 'ext' => $ext];
}

/** Pares carpeta física + URL pública donde puede vivir un logo. */
function yora_pares_uploads_logo(): array
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $url_host = [
        'comercios.yoradelivery.com' => 'https://comercios.yoradelivery.com/uploads/',
        'yoradelivery.com'           => 'https://yoradelivery.com/uploads/',
        'www.yoradelivery.com'       => 'https://yoradelivery.com/uploads/',
        'app.yoradelivery.com'       => 'https://app.yoradelivery.com/uploads/',
        'client.yoradelivery.com'    => 'https://client.yoradelivery.com/uploads/',
    ];
    $pares = [];
    $root = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($root !== '') {
        $pares[] = [$root . '/uploads/', $url_host[$host] ?? ('https://' . ($host !== '' ? $host : 'yoradelivery.com') . '/uploads/')];
    }
    $fijos = [
        ['comercios.yoradelivery.com', 'https://comercios.yoradelivery.com/uploads/'],
        ['yoradelivery.com', 'https://yoradelivery.com/uploads/'],
        ['client.yoradelivery.com', 'https://client.yoradelivery.com/uploads/'],
    ];
    foreach ($fijos as [$dominio, $base]) {
        $dir = yora_dir_uploads_dominio($dominio);
        if ($dir) {
            $pares[] = [$dir, $base];
        }
        $pares[] = ['/home/yoradelivery/domains/' . $dominio . '/public_html/uploads/', $base];
    }
    $vistos = [];
    $limpios = [];
    foreach ($pares as [$dir, $base]) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        if (isset($vistos[$dir])) {
            continue;
        }
        $vistos[$dir] = true;
        $limpios[] = [$dir, $base];
    }
    return $limpios;
}

function yora_escribir_upload(string $dir, string $nombre, string $bin): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    @chmod($dir, 0775);
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
    $dest = $dir . $nombre;
    if (@file_put_contents($dest, $bin) === false) {
        return false;
    }
    @chmod($dest, 0644);
    return is_file($dest) && filesize($dest) > 0;
}

/** Guarda el logo de un comercio y devuelve ['url'=>..., 'ok'=>bool, 'error'=>...]. */
function yora_guardar_logo_comercio(array $file): array
{
    $fallback = 'https://cdn-icons-png.flaticon.com/512/819/819814.png';
    $norm = yora_normalizar_imagen_upload($file);
    if (!$norm['ok']) {
        return ['url' => $fallback, 'ok' => false, 'error' => (string) ($norm['error'] ?? 'No se pudo guardar el logo.')];
    }
    $nombre = bin2hex(random_bytes(8)) . '_' . time() . '.' . $norm['ext'];
    foreach (yora_pares_uploads_logo() as [$dir, $base]) {
        if (yora_escribir_upload($dir, $nombre, $norm['bin'])) {
            return ['url' => $base . $nombre, 'ok' => true, 'error' => ''];
        }
    }
    return [
        'url'   => $fallback,
        'ok'    => false,
        'error' => 'No se pudo escribir el logo en uploads. En FileZilla, clic derecho en la carpeta uploads → Permisos 0775 (marca “recursivo” solo si te lo pide el hosting).',
    ];
}

/**
 * Guarda el RIF/cédula de un comercio (JPG, PNG, WEBP o PDF) y devuelve
 * ['url'=>..., 'ok'=>bool, 'error'=>...]. Reintenta en las mismas carpetas
 * que el logo: si uploads no escribible, avisa en vez de fingir éxito.
 */
function yora_guardar_documento_comercio(array $file): array
{
    $errores = [
        UPLOAD_ERR_INI_SIZE   => 'El documento pesa más de lo que permite el servidor. Sube uno de máximo 5 MB.',
        UPLOAD_ERR_FORM_SIZE  => 'El documento supera el máximo de 5 MB.',
        UPLOAD_ERR_PARTIAL    => 'El documento se subió a medias. Inténtalo de nuevo.',
        UPLOAD_ERR_NO_FILE    => 'No se recibió ningún documento.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para subidas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
    ];
    $codigo = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($codigo !== UPLOAD_ERR_OK) {
        return ['url' => '', 'ok' => false, 'error' => $errores[$codigo] ?? 'No se pudo recibir el documento.'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['url' => '', 'ok' => false, 'error' => 'No se recibió el documento.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return ['url' => '', 'ok' => false, 'error' => 'El archivo está vacío.'];
    }
    if ($size > 5242880) {
        return ['url' => '', 'ok' => false, 'error' => 'El documento no puede pesar más de 5 MB.'];
    }

    $mime = '';
    if (class_exists('finfo')) {
        $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '');
    }
    $ext_nom = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $raw = @file_get_contents($file['tmp_name']);
    $es_pdf = in_array($mime, ['application/pdf', 'application/x-pdf'], true)
        || $ext_nom === 'pdf'
        || ($raw !== false && strncmp($raw, '%PDF', 4) === 0);

    if ($es_pdf) {
        if ($raw === false || strncmp($raw, '%PDF', 4) !== 0) {
            return ['url' => '', 'ok' => false, 'error' => 'El PDF no es válido.'];
        }
        $nombre = bin2hex(random_bytes(8)) . '_' . time() . '.pdf';
        foreach (yora_pares_uploads_logo() as [$dir, $base]) {
            if (yora_escribir_upload($dir, $nombre, $raw)) {
                return ['url' => $base . $nombre, 'ok' => true, 'error' => ''];
            }
        }
        return [
            'url'   => '',
            'ok'    => false,
            'error' => 'No se pudo escribir el documento en uploads. En FileZilla, permisos 0775 en la carpeta uploads.',
        ];
    }

    $img = yora_guardar_logo_comercio($file);
    if (!$img['ok']) {
        $err = trim((string) ($img['error'] ?? ''));
        if ($err === '') {
            $err = 'Usa una foto JPG/PNG o un PDF de máximo 5 MB. Si es un iPhone, evita HEIC: expórtalo como JPG.';
        } else {
            $err = str_ireplace('el logo', 'el documento', $err);
            $err = str_ireplace('logo', 'documento', $err);
        }
        return ['url' => '', 'ok' => false, 'error' => $err];
    }
    return ['url' => (string) $img['url'], 'ok' => true, 'error' => ''];
}

/** Comercio con documento enviado y aún no aprobado por HQ. */
function yora_comercio_verificacion_pendiente(array $row): bool
{
    $est = trim((string) ($row['estado_documentos'] ?? ''));
    $url = trim((string) ($row['documento_url'] ?? ''));
    if (strcasecmp($est, 'Verificado') === 0) {
        return false;
    }
    if ($est === 'En Revisión' || $est === 'En Revision') {
        return true;
    }
    return $url !== '';
}

function yora_hora_sql(?string $h): string
{
    $h = trim((string) $h);
    if ($h === '') {
        return '';
    }
    if (!preg_match('/^(\d{2}:\d{2})(:\d{2})?/', $h, $m)) {
        return '';
    }
    return isset($m[2]) && $m[2] !== '' ? ($m[1] . $m[2]) : ($m[1] . ':00');
}

function yora_haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    if ($lat1 === $lat2 && $lon1 === $lon2) {
        return 0.0;
    }
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Descarga una URL con timeout corto. Devuelve null si falla.
 */
function yora_http_get(string $url, int $timeout = 6, string $accept = 'text/html,application/json;q=0.9,*/*;q=0.8'): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $codigo === 200) ? (string) $body : null;
    }

    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'header' => "User-Agent: YoraDelivery/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** Centro y caja de Barquisimeto / Gran Barquisimeto (Lara). */
function yora_bbox_lara(): array
{
    return [
        'lat' => 10.0670,
        'lng' => -69.3467,
        'oeste' => -69.52,
        'este'  => -69.18,
        'norte' => 10.22,
        'sur'   => 9.94,
    ];
}

/** Sitios conocidos de Barquisimeto para cuando Nominatim no encuentra el nombre local. */
function yora_pois_barquisimeto(): array
{
    return [
        ['k' => ['sambil', 'cc sambil', 'sambil barquisimeto'], 'lat' => 10.0678, 'lng' => -69.2795, 'n' => 'CC Sambil Barquisimeto'],
        ['k' => ['trinitarias', 'cc las trinitarias', 'las trinitarias'], 'lat' => 10.0642, 'lng' => -69.3178, 'n' => 'CC Las Trinitarias'],
        ['k' => ['las plazas', 'cc las plazas'], 'lat' => 10.0678, 'lng' => -69.3225, 'n' => 'CC Las Plazas'],
        ['k' => ['metropolis', 'metrópolis', 'cc metropolis'], 'lat' => 10.0548, 'lng' => -69.3512, 'n' => 'CC Metrópolis'],
        ['k' => ['verrol', 'cc verrol'], 'lat' => 10.0674, 'lng' => -69.3472, 'n' => 'CC Verrol'],
        ['k' => ['las mercedes', 'cc las mercedes'], 'lat' => 10.0618, 'lng' => -69.3385, 'n' => 'CC Las Mercedes'],
        ['k' => ['obelisco', 'el obelisco'], 'lat' => 10.0647, 'lng' => -69.3169, 'n' => 'Obelisco de Barquisimeto'],
        ['k' => ['ucla', 'universidad centroccidental', 'lisandro alvarado'], 'lat' => 10.0670, 'lng' => -69.3228, 'n' => 'UCLA'],
        ['k' => ['unexpo', 'unexpo barquisimeto'], 'lat' => 10.0675, 'lng' => -69.2806, 'n' => 'UNEXPO Barquisimeto'],
        ['k' => ['terminal', 'terminal de pasajeros', 'terminal barquisimeto'], 'lat' => 10.0735, 'lng' => -69.3220, 'n' => 'Terminal de Pasajeros'],
        ['k' => ['plaza bolivar', 'plaza bolívar', 'centro de barquisimeto'], 'lat' => 10.0739, 'lng' => -69.3228, 'n' => 'Plaza Bolívar'],
        ['k' => ['catedral', 'catedral de barquisimeto'], 'lat' => 10.0752, 'lng' => -69.3234, 'n' => 'Catedral de Barquisimeto'],
        ['k' => ['hospital central', 'antonio maria pines'], 'lat' => 10.0701, 'lng' => -69.3238, 'n' => 'Hospital Central'],
        ['k' => ['paseo los leones', 'los leones'], 'lat' => 10.0674, 'lng' => -69.3222, 'n' => 'Paseo Los Leones'],
        ['k' => ['estadio', 'antonio herrera gutierrez', 'cardenales'], 'lat' => 10.0649, 'lng' => -69.3088, 'n' => 'Estadio Antonio Herrera Gutiérrez'],
        ['k' => ['parque del este', 'parque este'], 'lat' => 10.0732, 'lng' => -69.3015, 'n' => 'Parque del Este'],
        ['k' => ['ciudadela', 'ciudadela norte'], 'lat' => 10.0820, 'lng' => -69.3350, 'n' => 'Ciudadela'],
        ['k' => ['nueva buscaregua', 'buscaregua'], 'lat' => 10.0905, 'lng' => -69.3520, 'n' => 'Nueva Buscaregua'],
        ['k' => ['nueva segovia'], 'lat' => 10.0580, 'lng' => -69.3350, 'n' => 'Nueva Segovia'],
        ['k' => ['el paraiso', 'el paraíso'], 'lat' => 10.0785, 'lng' => -69.3480, 'n' => 'El Paraíso'],
        ['k' => ['patarata'], 'lat' => 10.0550, 'lng' => -69.3050, 'n' => 'Patarata'],
        ['k' => ['santa rosa'], 'lat' => 10.0480, 'lng' => -69.2800, 'n' => 'Santa Rosa'],
        ['k' => ['cabudare', 'centro de cabudare'], 'lat' => 10.0330, 'lng' => -69.2630, 'n' => 'Cabudare'],
        ['k' => ['agua viva'], 'lat' => 10.0150, 'lng' => -69.2850, 'n' => 'Agua Viva'],
        ['k' => ['av venezuela', 'avenida venezuela'], 'lat' => 10.0648, 'lng' => -69.3402, 'n' => 'Avenida Venezuela'],
        ['k' => ['av lara', 'avenida lara', 'av. lara'], 'lat' => 10.0672, 'lng' => -69.3018, 'n' => 'Avenida Lara'],
        ['k' => ['av vargas', 'avenida vargas'], 'lat' => 10.0665, 'lng' => -69.2880, 'n' => 'Avenida Vargas'],
        ['k' => ['circunvalacion', 'circunvalación'], 'lat' => 10.0555, 'lng' => -69.3100, 'n' => 'Circunvalación'],
        ['k' => ['zona industrial', 'zona ind'], 'lat' => 10.0520, 'lng' => -69.3680, 'n' => 'Zona Industrial I'],
        ['k' => ['farmatodo trinitarias'], 'lat' => 10.0645, 'lng' => -69.3185, 'n' => 'Farmatodo Trinitarias'],
        ['k' => ['locatel'], 'lat' => 10.0658, 'lng' => -69.3205, 'n' => 'Locatel Barquisimeto'],
    ];
}

/**
 * Estima un cruce del damero de Barquisimeto (carrera X con calle Y).
 * Origen: Carrera 17 con Calle 25 ≈ Plaza Bolívar.
 */
function yora_cruce_barquisimeto(string $q): ?array
{
    $ql = mb_strtolower(trim($q), 'UTF-8');
    $ql = strtr($ql, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $ql = preg_replace('/\b(carrera|cra\.?|cr\.?|car\.?)\b/u', 'carrera', $ql) ?? $ql;
    $ql = preg_replace('/\b(calle|cll?\.?|calles)\b/u', 'calle', $ql) ?? $ql;
    $ql = preg_replace('/\b(con|y|&|entre)\b/u', ' con ', $ql) ?? $ql;
    $ql = preg_replace('/\s+/u', ' ', $ql) ?? $ql;

    $carrera = null;
    $calle = null;
    if (preg_match('/carrera\s*(\d{1,3})/u', $ql, $m)) {
        $carrera = (int) $m[1];
    }
    if (preg_match('/calle\s*(\d{1,3})/u', $ql, $m)) {
        $calle = (int) $m[1];
    }
    if ($carrera !== null && $calle === null && preg_match('/carrera\s*\d{1,3}\s*con\s*(\d{1,3})/u', $ql, $m)) {
        $calle = (int) $m[1];
    }
    if ($calle !== null && $carrera === null && preg_match('/calle\s*\d{1,3}\s*con\s*(\d{1,3})/u', $ql, $m)) {
        $carrera = (int) $m[1];
    }
    if ($carrera === null && $calle === null && preg_match('/\b(\d{1,3})\s*con\s*(\d{1,3})\b/u', $ql, $m)) {
        $carrera = (int) $m[1];
        $calle = (int) $m[2];
    }
    if ($carrera === null || $calle === null || $carrera < 1 || $carrera > 55 || $calle < 1 || $calle > 70) {
        return null;
    }

    $lat = 10.0739 + (($calle - 25) * 0.00074);
    $lng = -69.3228 - (($carrera - 17) * 0.00082);
    $bbox = yora_bbox_lara();
    if ($lat < $bbox['sur'] || $lat > $bbox['norte'] || $lng < $bbox['oeste'] || $lng > $bbox['este']) {
        return null;
    }

    return [
        'nombre' => 'Carrera ' . $carrera . ' con Calle ' . $calle . ', Barquisimeto',
        'lat'    => round($lat, 6),
        'lng'    => round($lng, 6),
        'fuente' => 'cruce',
    ];
}

function yora_normalizar_busqueda_dir(string $q): string
{
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));
    $ql = mb_strtolower($q, 'UTF-8');
    if ($ql === '') {
        return '';
    }
    if (!preg_match('/barquisimeto|cabudare|lara|venezuela|iribarren/i', $ql)) {
        $q .= ', Barquisimeto, Lara, Venezuela';
    }
    return $q;
}

/**
 * Busca una dirección sesgada a Barquisimeto/Lara.
 * Combina sitios locales, Nominatim (servidor) y Photon.
 */
function yora_buscar_direccion_lara(string $q): array
{
    $q = trim($q);
    if (mb_strlen($q) < 3) {
        return [];
    }

    $clave = sha1(mb_strtolower($q, 'UTF-8'));
    $cache = sys_get_temp_dir() . '/yora_geo_' . $clave . '.json';
    if (is_file($cache)) {
        $guardado = json_decode((string) file_get_contents($cache), true);
        $edad = time() - filemtime($cache);
        // Vacío se guarda poco tiempo: Nominatim a veces falla y no debe bloquear Lara 24 h.
        $ttl = (is_array($guardado) && $guardado !== []) ? 86400 : 120;
        if (is_array($guardado) && $edad < $ttl) {
            return $guardado;
        }
    }

    $bbox = yora_bbox_lara();
    $ql = mb_strtolower($q, 'UTF-8');
    $out = [];

    $cruce = yora_cruce_barquisimeto($q);
    if ($cruce) {
        $out[] = $cruce;
    }

    foreach (yora_pois_barquisimeto() as $poi) {
        foreach ($poi['k'] as $alias) {
            if (mb_strpos($ql, $alias) !== false) {
                $out[] = [
                    'nombre' => $poi['n'],
                    'lat'    => $poi['lat'],
                    'lng'    => $poi['lng'],
                    'fuente' => 'local',
                ];
                break;
            }
        }
    }

    $query = yora_normalizar_busqueda_dir($q);
    $viewbox = $bbox['oeste'] . ',' . $bbox['norte'] . ',' . $bbox['este'] . ',' . $bbox['sur'];
    $q_cruce = $cruce ? $cruce['nombre'] . ', Lara, Venezuela' : $query;
    $urls = [
        'https://nominatim.openstreetmap.org/search?format=json&addressdetails=0&limit=8&countrycodes=ve&accept-language=es'
            . '&viewbox=' . rawurlencode($viewbox) . '&bounded=1&q=' . rawurlencode($query),
        'https://nominatim.openstreetmap.org/search?format=json&addressdetails=0&limit=6&countrycodes=ve&accept-language=es'
            . '&q=' . rawurlencode($q_cruce),
        'https://photon.komoot.io/api/?lang=es&limit=8&lat=' . $bbox['lat'] . '&lon=' . $bbox['lng']
            . '&q=' . rawurlencode($query),
    ];

    foreach ($urls as $url) {
        $body = yora_http_get($url, 7);
        if ($body === null) {
            continue;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            continue;
        }
        $items = isset($data['features']) && is_array($data['features']) ? $data['features'] : $data;
        foreach ($items as $r) {
            $lat = 0.0;
            $lng = 0.0;
            $nombre = '';
            if (isset($r['geometry']['coordinates'][0])) {
                $lng = (float) $r['geometry']['coordinates'][0];
                $lat = (float) $r['geometry']['coordinates'][1];
                $nombre = (string) ($r['properties']['name'] ?? '');
                $city = (string) ($r['properties']['city'] ?? $r['properties']['state'] ?? '');
                if ($city !== '') {
                    $nombre = trim($nombre . ($nombre !== '' ? ', ' : '') . $city);
                }
                if ($nombre === '') {
                    $nombre = (string) ($r['properties']['street'] ?? 'Resultado');
                }
            } else {
                $lat = (float) ($r['lat'] ?? 0);
                $lng = (float) ($r['lon'] ?? $r['lng'] ?? 0);
                $nombre = (string) ($r['display_name'] ?? '');
            }
            if (!yora_coords_ok($lat, $lng) || $nombre === '') {
                continue;
            }
            // Descarta puntos muy lejos de Lara.
            if ($lat < $bbox['sur'] - 0.35 || $lat > $bbox['norte'] + 0.35 || $lng < $bbox['oeste'] - 0.35 || $lng > $bbox['este'] + 0.35) {
                continue;
            }
            $out[] = ['nombre' => $nombre, 'lat' => $lat, 'lng' => $lng, 'fuente' => 'geo'];
        }
        if (count($out) >= 8) {
            break;
        }
    }

    $vistos = [];
    $limpios = [];
    foreach ($out as $item) {
        $k = round($item['lat'], 5) . ',' . round($item['lng'], 5);
        if (isset($vistos[$k])) {
            continue;
        }
        $vistos[$k] = true;
        $limpios[] = $item;
        if (count($limpios) >= 8) {
            break;
        }
    }

    @file_put_contents($cache, json_encode($limpios, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $limpios;
}

/**
 * Nombre real de una coordenada en Lara (Nominatim). El damero solo es respaldo.
 */
function yora_reverse_geocode_lara(float $lat, float $lng): string
{
    if (!yora_coords_ok($lat, $lng)) {
        return '';
    }
    $clave = sha1(sprintf('rev4:%.5f,%.5f', $lat, $lng));
    $cache = sys_get_temp_dir() . '/yora_rev_' . $clave . '.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 86400) {
        $txt = trim((string) file_get_contents($cache));
        if ($txt !== '') {
            return $txt;
        }
    }

    $nombre = '';
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=es'
        . '&lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lng);
    $body = yora_http_get($url, 7);
    $data = $body ? json_decode($body, true) : null;
    if (is_array($data)) {
        $addr = is_array($data['address'] ?? null) ? $data['address'] : [];
        $via = '';
        foreach (['road', 'pedestrian', 'path', 'footway', 'residential', 'neighbourhood'] as $k) {
            $v = trim((string) ($addr[$k] ?? ''));
            if ($v !== '') {
                $via = $v;
                break;
            }
        }
        $num = trim((string) ($addr['house_number'] ?? ''));
        $barrio = '';
        foreach (['suburb', 'neighbourhood', 'quarter', 'city_district'] as $k) {
            $v = trim((string) ($addr[$k] ?? ''));
            if ($v !== '' && strcasecmp($v, $via) !== 0) {
                $barrio = $v;
                break;
            }
        }
        $ciudad = '';
        foreach (['city', 'town', 'municipality', 'village'] as $k) {
            $v = trim((string) ($addr[$k] ?? ''));
            if ($v !== '') {
                $ciudad = $v;
                break;
            }
        }
        $partes = [];
        if ($via !== '') {
            $partes[] = $num !== '' ? ($via . ' ' . $num) : $via;
        }
        if ($barrio !== '') {
            $partes[] = $barrio;
        }
        if ($ciudad !== '') {
            $partes[] = $ciudad;
        }
        $nombre = $partes ? implode(', ', $partes) : trim((string) ($data['name'] ?? ''));
        if ($nombre === '') {
            $nombre = trim((string) ($data['display_name'] ?? ''));
            // Acorta display_name demasiado largo (país, estado…).
            if ($nombre !== '') {
                $trozos = array_map('trim', explode(',', $nombre));
                $nombre = implode(', ', array_slice($trozos, 0, 3));
            }
        }
    }

    // Solo si Nominatim falló: estimación damero (marcada como aproximada).
    if ($nombre === '' && $lat > 10.02 && $lat < 10.14 && $lng > -69.40 && $lng < -69.26) {
        $calle = (int) round(25 + (($lat - 10.0739) / 0.00074));
        $carrera = (int) round(17 - (($lng + 69.3228) / 0.00082));
        if ($carrera >= 1 && $carrera <= 55 && $calle >= 1 && $calle <= 70) {
            $nombre = 'Aprox. Carrera ' . $carrera . ' con Calle ' . $calle . ', Barquisimeto';
        }
    }

    if ($nombre === '') {
        $nombre = 'Pin en el mapa (' . number_format($lat, 5, '.', '') . ', ' . number_format($lng, 5, '.', '') . ')';
    }

    @file_put_contents($cache, $nombre, LOCK_EX);
    return $nombre;
}

/**
 * Distancia real de conduccion por calles (OSRM). Si el servicio no responde
 * usa la linea recta con un factor de sinuosidad para no bloquear el pedido.
 *
 * Devuelve ['km', 'minutos', 'geometria', 'fuente' => 'calles'|'estimada'].
 */
function yora_ruta_calles(float $lat1, float $lng1, float $lat2, float $lng2, bool $con_geometria = false): array
{
    $recta = yora_haversine($lat1, $lng1, $lat2, $lng2);

    // Factor medio entre distancia en linea recta y recorrido real en ciudad.
    $estimada = [
        'km' => round($recta * 1.35, 2),
        'minutos' => (int) max(1, ceil(($recta * 1.35) / 22 * 60)),
        'geometria' => null,
        'fuente' => 'estimada',
    ];

    if ($recta < 0.05) {
        return ['km' => round($recta, 2), 'minutos' => 1, 'geometria' => null, 'fuente' => 'calles'];
    }

    // Cache por coordenadas redondeadas a ~11 m para no golpear el servicio en cada tecla.
    $clave = sha1(sprintf(
        '%.4f,%.4f,%.4f,%.4f,%d',
        $lat1,
        $lng1,
        $lat2,
        $lng2,
        $con_geometria ? 1 : 0
    ));
    $archivo = sys_get_temp_dir() . '/yora_ruta_' . $clave . '.json';
    if (is_file($archivo) && (time() - filemtime($archivo)) < 86400) {
        $guardado = json_decode((string) file_get_contents($archivo), true);
        if (is_array($guardado) && isset($guardado['km'])) {
            return $guardado;
        }
    }

    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=%s&geometries=geojson',
        $lng1,
        $lat1,
        $lng2,
        $lat2,
        $con_geometria ? 'simplified' : 'false'
    );

    $body = yora_http_get($url, 6);
    if ($body === null) {
        return $estimada;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
        return $estimada;
    }

    $ruta = $data['routes'][0];
    $km = round(((float) ($ruta['distance'] ?? 0)) / 1000, 2);
    if ($km <= 0) {
        return $estimada;
    }

    $resultado = [
        'km' => $km,
        'minutos' => (int) max(1, ceil(((float) ($ruta['duration'] ?? 0)) / 60)),
        'geometria' => $con_geometria ? ($ruta['geometry']['coordinates'] ?? null) : null,
        'fuente' => 'calles',
    ];

    @file_put_contents($archivo, json_encode($resultado), LOCK_EX);
    return $resultado;
}

/**
 * Tasa oficial del BCV, consultada desde el servidor una vez al dia.
 *
 * Antes la pedia el navegador a una API que ya no responde, y el codigo tenia
 * escrita una tasa fija de respaldo; el resultado era que todas las recargas
 * se convertian a bolivares con una tasa vieja. Ahora la busca el servidor en
 * varias fuentes y la guarda en configuracion_web.
 *
 * Devuelve ['tasa', 'fecha', 'fuente']. Si nunca se pudo obtener ninguna,
 * 'tasa' vale 0.0 y el panel debe avisar en lugar de calcular con un numero
 * inventado.
 */
function yora_tasa_bcv(mysqli $db, bool $forzar = false): array
{
    date_default_timezone_set('America/Caracas');
    $hoy = date('Y-m-d');
    $guardada = 0.0;
    $fecha_guardada = '';
    $fuente_guardada = '';
    $es_manual = false;

    try {
        $fila = yora_one($db, 'SELECT * FROM configuracion_web LIMIT 1');
        if ($fila) {
            $guardada = (float) ($fila['tasa_bcv'] ?? 0);
            $fecha_guardada = (string) ($fila['tasa_bcv_fecha'] ?? '');
            $fuente_guardada = trim((string) ($fila['tasa_bcv_fuente'] ?? ''));
            $es_manual = (int) ($fila['tasa_bcv_manual'] ?? 0) === 1;
        }
    } catch (Throwable $e) {
        error_log('yora_tasa_bcv lectura: ' . $e->getMessage());
    }

    // Tasa escrita a mano en HQ no se pisa sola (las APIs a veces traen otro número).
    if (!$forzar && $guardada > 0 && ($es_manual || $fecha_guardada === $hoy)) {
        $fuente = $es_manual ? 'manual' : ($fuente_guardada !== '' ? $fuente_guardada : 'guardada');
        return ['tasa' => $guardada, 'fecha' => $fecha_guardada, 'fuente' => $fuente, 'manual' => $es_manual];
    }

    $marca = sys_get_temp_dir() . '/yora_bcv_intento';
    if (!$forzar && is_file($marca) && (time() - filemtime($marca)) < 300) {
        return [
            'tasa' => $guardada,
            'fecha' => $fecha_guardada,
            'fuente' => $guardada > 0 ? ($fuente_guardada !== '' ? $fuente_guardada : 'desactualizada') : 'sin_datos',
            'manual' => $es_manual,
        ];
    }
    @touch($marca);

    [$nueva, $origen] = yora_tasa_bcv_remota();
    if ($nueva > 0) {
        yora_tasa_bcv_guardar($db, $nueva, $hoy, $origen, false);
        return ['tasa' => $nueva, 'fecha' => $hoy, 'fuente' => $origen, 'manual' => false];
    }

    return [
        'tasa' => $guardada,
        'fecha' => $fecha_guardada,
        'fuente' => $guardada > 0 ? ($fuente_guardada !== '' ? $fuente_guardada : 'desactualizada') : 'sin_datos',
        'manual' => $es_manual,
    ];
}

function yora_tasa_bcv_guardar(mysqli $db, float $tasa, string $fecha, string $fuente, bool $manual): void
{
    $tasa = round($tasa, 4);
    try {
        $afectadas = yora_exec(
            $db,
            'UPDATE configuracion_web SET tasa_bcv = ?, tasa_bcv_fecha = ?, tasa_bcv_fuente = ?, tasa_bcv_manual = ? LIMIT 1',
            'dssi',
            $tasa,
            $fecha,
            $fuente,
            $manual ? 1 : 0
        );
        if ($afectadas < 1 && !yora_one($db, 'SELECT tasa_bcv FROM configuracion_web LIMIT 1')) {
            yora_exec(
                $db,
                'INSERT INTO configuracion_web (tasa_bcv, tasa_bcv_fecha, tasa_bcv_fuente, tasa_bcv_manual) VALUES (?, ?, ?, ?)',
                'dssi',
                $tasa,
                $fecha,
                $fuente,
                $manual ? 1 : 0
            );
        }
    } catch (Throwable $e) {
        error_log('yora_tasa_bcv_guardar: ' . $e->getMessage());
    }
}

/** Convierte "842,20670000" o "842.2067" a float. */
function yora_tasa_bcv_numero(string $texto): float
{
    $texto = trim(str_replace(["\xc2\xa0", ' '], '', $texto));
    if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $texto) || (str_contains($texto, ',') && str_contains($texto, '.') && strrpos($texto, ',') > strrpos($texto, '.'))) {
        $texto = str_replace('.', '', $texto);
        $texto = str_replace(',', '.', $texto);
    } elseif (str_contains($texto, ',') && !str_contains($texto, '.')) {
        $texto = str_replace(',', '.', $texto);
    }
    $tasa = is_numeric($texto) ? (float) $texto : 0.0;
    return ($tasa > 1 && $tasa < 1000000) ? round($tasa, 4) : 0.0;
}

function yora_tasa_bcv_desde_html(string $html): float
{
    if (preg_match('/USD.{0,500}?(\d{1,3}(?:\.\d{3})*,\d{2,8}|\d{2,4}[.,]\d{2,8})/is', $html, $m)) {
        $tasa = yora_tasa_bcv_numero($m[1]);
        if ($tasa > 20) {
            return $tasa;
        }
    }
    if (preg_match('/\$\s*USD.{0,200}?(\d{1,3}(?:\.\d{3})*,\d{2,8}|\d{2,4}[.,]\d{2,8})/is', $html, $m)) {
        return yora_tasa_bcv_numero($m[1]);
    }
    return 0.0;
}

/** Consulta BCV oficial primero; APIs solo de respaldo. [tasa, fuente] */
function yora_tasa_bcv_remota(): array
{
    $paginas = [
        'https://www.bcv.org.ve/',
        'https://www.bcv.org.ve/estadisticas/tipo-cambio-de-referencia-smc',
    ];
    foreach ($paginas as $url) {
        $html = yora_http_get($url, 12, 'text/html');
        if ($html === null) {
            continue;
        }
        $tasa = yora_tasa_bcv_desde_html($html);
        if ($tasa > 0) {
            return [$tasa, 'bcv.org.ve'];
        }
    }

    $fuentes = [
        ['https://pydolarve.org/api/v2/snapshot?type=bcv', ['monitors.usd.price', 'usd.price', 'price']],
        ['https://pydolarve.org/api/v1/dollar?page=bcv', ['monitors.usd.price', 'monitors.bcv.price']],
        ['https://ve.dolarapi.com/v1/dolares/oficial', ['promedio', 'venta', 'compra', 'precio']],
        ['https://bcv.today/api/v1/rate.json', ['USD']],
        ['https://s3.amazonaws.com/dolartoday/data.json', ['USD.bcv', 'USD.dolartoday']],
    ];

    foreach ($fuentes as [$url, $rutas]) {
        $cuerpo = yora_http_get($url, 8, 'application/json');
        if ($cuerpo === null) {
            continue;
        }
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            continue;
        }
        foreach ($rutas as $ruta) {
            $valor = $datos;
            foreach (explode('.', $ruta) as $clave) {
                if (!is_array($valor) || !isset($valor[$clave])) {
                    $valor = null;
                    break;
                }
                $valor = $valor[$clave];
            }
            $tasa = is_numeric($valor) ? (float) $valor : 0.0;
            if ($tasa > 1 && $tasa < 1000000) {
                return [round($tasa, 4), parse_url($url, PHP_URL_HOST) ?: 'api'];
            }
        }
    }

    return [0.0, ''];
}

const YORA_TARIFA_MINIMA = 1.00;

/** Numero de soporte de Yora, en formato wa.me (sin + ni espacios). */
const YORA_WHATSAPP_SOPORTE = '584225097031';

/** El mismo numero, escrito para leerlo (correos, mensajes, pantallas). */
const YORA_WHATSAPP_SOPORTE_TXT = '+58 422-5097031';

/**
 * La tabla comercios arrastra dos pares de columnas de coordenadas
 * (latitud/longitud y lat/lng) que en algunos registros no coinciden.
 * Todo el sistema debe leerlas en el MISMO orden o el mapa mostraria un
 * origen y el cobro usaria otro.
 *
 * Orden oficial: latitud/longitud y, si estan vacias, lat/lng.
 */
function yora_coords_comercio(array $fila): array
{
    $lat = (float) ($fila['latitud'] ?? 0);
    $lng = (float) ($fila['longitud'] ?? 0);

    if (!yora_coords_ok($lat, $lng)) {
        $lat = (float) ($fila['lat'] ?? 0);
        $lng = (float) ($fila['lng'] ?? 0);
    }

    return yora_coords_ok($lat, $lng) ? [$lat, $lng] : [0.0, 0.0];
}

/**
 * Tamaño del local (no es Club Yora). Define ventajas operativas,
 * empezando por el mínimo de recarga. Más reglas se pueden colgar aquí.
 */
function yora_tipos_comercio(): array
{
    return [
        'Pequeño' => [
            'clave'       => 'Pequeño',
            'etiqueta'    => 'Pequeño',
            'min_recarga' => 5.00,
        ],
        'Pequeño mediano' => [
            'clave'       => 'Pequeño mediano',
            'etiqueta'    => 'Pequeño mediano',
            'min_recarga' => 10.00,
        ],
        'Mediano' => [
            'clave'       => 'Mediano',
            'etiqueta'    => 'Mediano',
            'min_recarga' => 15.00,
        ],
        'Mediano grande' => [
            'clave'       => 'Mediano grande',
            'etiqueta'    => 'Mediano grande',
            'min_recarga' => 20.00,
        ],
        'Grande' => [
            'clave'       => 'Grande',
            'etiqueta'    => 'Grande',
            'min_recarga' => 25.00,
        ],
    ];
}

function yora_tipo_comercio_clave(?string $tipo): string
{
    $t = trim((string) $tipo);
    $tipos = yora_tipos_comercio();
    return isset($tipos[$t]) ? $t : 'Pequeño';
}

function yora_min_recarga_comercio(?string $tipo): float
{
    $clave = yora_tipo_comercio_clave($tipo);
    return (float) yora_tipos_comercio()[$clave]['min_recarga'];
}

function yora_es_mandadito(array $c): bool
{
    return strcasecmp(trim((string) ($c['tipo_comanda'] ?? '')), 'mandadito') === 0;
}

/** Datos de cobro de Yora HQ. El Pago Móvil 0414-5530182 no se cambia. */
function yora_pago_hq(): array
{
    return [
        'banco'    => 'Bancaribe',
        'codigo'   => '0114',
        'telefono' => '0414-5530182',
        'tel_num'  => '04145530182',
        'cedula'   => 'V-25.951.632',
        'ced_num'  => '25951632',
        'efectivo' => 'Carrera 17 con calle 27 y 28, Barquisimeto.',
    ];
}

function yora_metodos_pago_cliente(): array
{
    return ['Billetera', 'Pago Móvil', 'Efectivo'];
}

function yora_reportar_pago_cliente(mysqli $db, int $uid, float $monto, string $banco, string $referencia, ?int $comanda_id = null): int
{
    $banco = trim($banco);
    $referencia = trim($referencia);
    if ($monto < 0.5 || $monto > 50000 || $referencia === '' || mb_strlen($referencia) > 40) {
        throw new RuntimeException('Monto o referencia inválidos.');
    }
    if (!in_array($banco, ['Pago Móvil', 'Efectivo'], true)) {
        throw new RuntimeException('Método de pago no válido.');
    }
    $dup = yora_one($db, 'SELECT id FROM recargas_clientes WHERE referencia = ? AND banco_origen = ? LIMIT 1', 'ss', $referencia, $banco);
    if ($dup) {
        throw new RuntimeException('Esa referencia ya fue reportada.');
    }
    $tasa = 0.0;
    $bs = 0.0;
    if ($banco === 'Pago Móvil') {
        $tasa = (float) yora_tasa_bcv($db)['tasa'];
        if ($tasa <= 0) {
            throw new RuntimeException('No pudimos obtener la tasa del BCV. Inténtalo en unos minutos.');
        }
        $bs = round($monto * $tasa, 2);
    }
    yora_exec(
        $db,
        "INSERT INTO recargas_clientes (usuario_id, comanda_id, monto, monto_bs, tasa_bcv, banco_origen, referencia, fecha_pago, estatus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'En Revisión')",
        'iidddsss',
        $uid,
        (int) ($comanda_id ?? 0),
        $monto,
        $bs,
        $tasa,
        $banco,
        $referencia,
        date('Y-m-d')
    );
    return (int) $db->insert_id;
}

function yora_ganancia_driver(float $costo): float
{
    return round($costo * 0.80, 2);
}

function yora_comision_yora(float $costo): float
{
    return round($costo * 0.20, 2);
}

/** El viaje ya lo cobró Yora (billetera, pago móvil u otro prepago). No es efectivo en mano. */
function yora_viaje_paga_billetera(array $c): bool
{
    $tipo = trim((string) ($c['tipo_pago'] ?? ''));
    if ($tipo === '' || strcasecmp($tipo, 'Efectivo') === 0) {
        return false;
    }
    return true;
}

/** Al entregar: prepago/PM acredita 80%; efectivo en mano descuenta el 20% de Yora. */
function yora_acreditar_viaje_driver(mysqli $db, int $conductor_id, array $comanda): void
{
    if ($conductor_id < 1) {
        return;
    }
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    if ($costo <= 0) {
        return;
    }
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        $comision = yora_comision_yora($costo);
        if ($comision > 0) {
            yora_exec($db, 'UPDATE conductores SET billetera = billetera - ? WHERE id = ?', 'di', $comision, $conductor_id);
        }
        return;
    }
    if (!yora_viaje_paga_billetera($comanda)) {
        return;
    }
    $ganancia = yora_ganancia_driver($costo);
    if ($ganancia <= 0) {
        return;
    }
    yora_exec($db, 'UPDATE conductores SET billetera = billetera + ? WHERE id = ?', 'di', $ganancia, $conductor_id);
}

/** Lo que entra (+) o sale (-) de la billetera del driver al entregar. */
function yora_impacto_billetera_driver(array $comanda): float
{
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    if ($costo <= 0) {
        return 0.0;
    }
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        return -yora_comision_yora($costo);
    }
    if (!yora_viaje_paga_billetera($comanda)) {
        return 0.0;
    }
    return yora_ganancia_driver($costo);
}

/** En radar: efectivo en mano es el total; prepago es el 80%. */
function yora_monto_radar_driver(array $comanda): float
{
    $costo = (float) ($comanda['costo_delivery'] ?? 0);
    $tipo = trim((string) ($comanda['tipo_pago'] ?? ''));
    if (strcasecmp($tipo, 'Efectivo') === 0 && yora_es_mandadito($comanda)) {
        return round($costo, 2);
    }
    return yora_ganancia_driver($costo);
}

function yora_es_efectivo_en_mano(array $comanda): bool
{
    return strcasecmp(trim((string) ($comanda['tipo_pago'] ?? '')), 'Efectivo') === 0
        && yora_es_mandadito($comanda);
}

function yora_comanda_hq(mysqli $db, int $id): ?array
{
    return yora_one(
        $db,
        'SELECT c.*, COALESCE(NULLIF(r.nombre, \'\'), IF(c.tipo_comanda = \'mandadito\', \'Mandadito\', \'Yora\')) AS restaurante,
                r.billetera AS rest_billetera, COALESCE(NULLIF(r.latitud,0), r.lat) AS rest_lat,
                COALESCE(NULLIF(r.longitud,0), r.lng) AS rest_lng, r.direccion AS res_dir,
                d.nombre AS conductor, d.telefono AS conductor_tel
         FROM comandas c
         LEFT JOIN comercios r ON r.id = c.comercio_id
         LEFT JOIN conductores d ON d.id = c.conductor_id
         WHERE c.id = ?',
        'i',
        $id
    );
}

function yora_ajustar_saldos_por_costo(mysqli $db, array $comanda, float $nuevo_costo): void
{
    $viejo = (float) ($comanda['costo_delivery'] ?? 0);
    $diff = round($nuevo_costo - $viejo, 2);
    if (abs($diff) < 0.001) {
        return;
    }
    $rid = (int) ($comanda['comercio_id'] ?? 0);
    if ($rid > 0) {
        if ($diff > 0) {
            yora_exec($db, 'UPDATE comercios SET billetera = billetera - ? WHERE id = ?', 'di', $diff, $rid);
        } else {
            yora_exec($db, 'UPDATE comercios SET billetera = billetera + ? WHERE id = ?', 'di', abs($diff), $rid);
        }
    } elseif (yora_es_mandadito($comanda) && strcasecmp((string) ($comanda['tipo_pago'] ?? ''), 'Billetera') === 0) {
        $uid = (int) ($comanda['usuario_id'] ?? 0);
        if ($uid > 0) {
            if ($diff > 0) {
                yora_exec($db, 'UPDATE usuarios_app SET billetera = billetera - ? WHERE id = ?', 'di', $diff, $uid);
            } else {
                yora_exec($db, 'UPDATE usuarios_app SET billetera = billetera + ? WHERE id = ?', 'di', abs($diff), $uid);
            }
        }
    }
    if (($comanda['estatus'] ?? '') === 'Entregado') {
        $cid = (int) ($comanda['conductor_id'] ?? 0);
        if ($cid > 0) {
            $delta = round(yora_impacto_billetera_driver(array_merge($comanda, ['costo_delivery' => $nuevo_costo])) - yora_impacto_billetera_driver($comanda), 2);
            if (abs($delta) >= 0.001) {
                yora_exec($db, 'UPDATE conductores SET billetera = billetera + ? WHERE id = ?', 'di', $delta, $cid);
            }
        }
    }
}

function yora_hq_liberar_driver(mysqli $db, array $c): void
{
    $id = (int) $c['id'];
    $viejo = (int) ($c['conductor_id'] ?? 0);
    $codigo = yora_codigo_comanda($db, $id, $c['codigo'] ?? null);
    yora_exec(
        $db,
        "UPDATE comandas SET conductor_id = NULL, estatus = 'Buscando Conductor', lote_id = NULL WHERE id = ? AND estatus NOT IN ('Entregado','Cancelado')",
        'i',
        $id
    );
    if ($viejo > 0) {
        try {
            yora_push_conductor($db, $viejo, 'Viaje reasignado', 'HQ te quitó el viaje #' . $codigo . '.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    try {
        yora_push_conductores($db, 'Viaje de vuelta al radar', 'El pedido #' . $codigo . ' está de nuevo disponible.', '/dashboard.php', true);
    } catch (Throwable $e) {
    }
}

function yora_hq_asignar_driver(mysqli $db, array $c, int $driver_id): void
{
    $id = (int) $c['id'];
    $driver = yora_one($db, 'SELECT id, nombre, estatus FROM conductores WHERE id = ?', 'i', $driver_id);
    if (!$driver) {
        throw new RuntimeException('Conductor no encontrado.');
    }
    $est = strtolower(trim((string) ($driver['estatus'] ?? 'activo')));
    if (in_array($est, ['pendiente', 'rechazado', 'inactivo'], true)) {
        throw new RuntimeException('Ese conductor no puede tomar viajes.');
    }
    $viejo = (int) ($c['conductor_id'] ?? 0);
    $estatus_actual = (string) ($c['estatus'] ?? '');
    $nuevo_estatus = $estatus_actual === 'En Camino a Cliente' ? 'En Camino a Cliente' : 'En Camino a Comercio';
    yora_exec(
        $db,
        "UPDATE comandas SET conductor_id = ?, estatus = ?, lote_id = NULL WHERE id = ? AND estatus NOT IN ('Entregado','Cancelado')",
        'isi',
        $driver_id,
        $nuevo_estatus,
        $id
    );
    $codigo = yora_codigo_comanda($db, $id, $c['codigo'] ?? null);
    if ($viejo > 0 && $viejo !== $driver_id) {
        try {
            yora_push_conductor($db, $viejo, 'Viaje reasignado', 'HQ te quitó el viaje #' . $codigo . '.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    try {
        yora_push_conductor($db, $driver_id, 'HQ te asignó un viaje', 'Pedido #' . $codigo . '. Entra al radar.', '/dashboard.php');
    } catch (Throwable $e) {
    }
}

function yora_hq_corregir_destino(mysqli $db, array $c, string $direccion, float $lat, float $lng, ?float $km_forzado, ?float $costo_forzado): array
{
    if (!yora_coords_ok($lat, $lng)) {
        throw new RuntimeException('Las coordenadas de entrega no son válidas.');
    }
    [$plat, $plng] = yora_coords_recogida_comanda($c);
    if (!yora_coords_ok($plat, $plng)) {
        throw new RuntimeException('No hay punto de recogida para recalcular la ruta.');
    }
    $ruta = yora_ruta_calles($plat, $plng, $lat, $lng, false);
    $km = $km_forzado !== null && $km_forzado > 0 ? $km_forzado : (float) $ruta['km'];
    if ($km > 80) {
        throw new RuntimeException('La distancia supera el límite operativo (80 km).');
    }
    $tarifa = yora_calcular_tarifa($db, $km);
    $nuevo_costo = $costo_forzado !== null && $costo_forzado > 0 ? round($costo_forzado, 2) : (float) $tarifa['costo'];
    $dir = trim($direccion);
    if ($dir === '') {
        $dir = 'Destino corregido por HQ';
    }
    if (!str_contains($dir, 'GPS:')) {
        $dir .= ' | GPS: ' . $lat . ', ' . $lng;
    }
    yora_ajustar_saldos_por_costo($db, $c, $nuevo_costo);
    yora_exec(
        $db,
        'UPDATE comandas SET direccion_entrega = ?, lat_entrega = ?, lng_entrega = ?, distancia_km = ?, costo_delivery = ?, comision_yora = ?, geocerca_libre = 0 WHERE id = ?',
        'sdddddi',
        $dir,
        $lat,
        $lng,
        round($km, 2),
        $nuevo_costo,
        yora_comision_yora($nuevo_costo),
        (int) $c['id']
    );
    $cid = (int) ($c['conductor_id'] ?? 0);
    if ($cid > 0) {
        try {
            yora_push_conductor($db, $cid, 'Destino actualizado', 'HQ corrigió la dirección del viaje. Recarga el mapa.', '/dashboard.php');
        } catch (Throwable $e) {
        }
    }
    return ['km' => round($km, 2), 'costo' => $nuevo_costo];
}

/** Punto donde el motorizado recoge (local o pin del mandadito). */
function yora_coords_recogida_comanda(array $c): array
{
    if (yora_es_mandadito($c)) {
        $lat = (float) ($c['lat_recogida'] ?? 0);
        $lng = (float) ($c['lng_recogida'] ?? 0);
        if (yora_coords_ok($lat, $lng)) {
            return [$lat, $lng];
        }
    }
    $lat = (float) ($c['rest_lat'] ?? $c['latitud'] ?? 0);
    $lng = (float) ($c['rest_lng'] ?? $c['longitud'] ?? 0);
    return yora_coords_ok($lat, $lng) ? [$lat, $lng] : [0.0, 0.0];
}

function yora_texto_recogida_comanda(array $c): string
{
    if (yora_es_mandadito($c)) {
        $t = trim((string) ($c['direccion_recogida'] ?? ''));
        return $t !== '' ? $t : 'Punto de recogida';
    }
    $t = trim((string) ($c['res_dir'] ?? $c['direccion'] ?? ''));
    return $t !== '' ? $t : 'Local';
}

/** Coordenadas de entrega: columna propia o GPS pegado a la dirección. */
function yora_coords_entrega_comanda(array $c): array
{
    $lat = (float) ($c['lat_entrega'] ?? 0);
    $lng = (float) ($c['lng_entrega'] ?? 0);
    if (yora_coords_ok($lat, $lng)) {
        return [$lat, $lng];
    }
    $gps = yora_gps_desde_direccion($c['direccion_entrega'] ?? '');
    return $gps ?: [0.0, 0.0];
}

/**
 * Tramos de distancia configurados en "Tarifas y Finanzas", de menor a mayor.
 * El ultimo tramo tiene 'hasta' = null, es decir "de ahi en adelante".
 */
function yora_tramos_tarifa(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $filas = [];
    try {
        $filas = yora_all($db, 'SELECT km_hasta, precio_km FROM tarifas_tramos ORDER BY orden ASC, id ASC');
    } catch (Throwable $e) {
        error_log('yora_tramos_tarifa: ' . $e->getMessage());
    }

    $tramos = [];
    foreach ($filas as $f) {
        $precio = (float) $f['precio_km'];
        if ($precio <= 0) {
            continue;
        }
        $tramos[] = [
            'hasta'  => $f['km_hasta'] === null ? null : (float) $f['km_hasta'],
            'precio' => $precio,
        ];
    }

    // Sin tramos configurados se sigue usando el precio unico de siempre.
    if (!$tramos) {
        $row = yora_one($db, 'SELECT precio_km FROM configuracion_web LIMIT 1');
        $precio = $row ? (float) $row['precio_km'] : 0.40;
        $tramos[] = ['hasta' => null, 'precio' => $precio > 0 ? $precio : 0.40];
    }

    // El ultimo tramo cubre siempre hasta el infinito, pase lo que pase.
    $tramos[count($tramos) - 1]['hasta'] = null;

    $cache = $tramos;
    return $cache;
}

function yora_tarifa_minima(mysqli $db): float
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $minima = YORA_TARIFA_MINIMA;
    try {
        $row = yora_one($db, 'SELECT tarifa_minima FROM configuracion_web LIMIT 1');
        if ($row && (float) $row['tarifa_minima'] > 0) {
            $minima = (float) $row['tarifa_minima'];
        }
    } catch (Throwable $e) {
        error_log('yora_tarifa_minima: ' . $e->getMessage());
    }
    $cache = $minima;
    return $cache;
}

/** Precio del primer tramo: es el "desde" que se muestra en los paneles. */
function yora_precio_km(mysqli $db): float
{
    $tramos = yora_tramos_tarifa($db);
    return (float) $tramos[0]['precio'];
}

/**
 * Cobro PROGRESIVO por tramos: cada kilometro paga el precio del tramo al que
 * pertenece, no el del tramo donde cae la distancia total. Con tramos de
 * 0-8 km a 0,40 y 8-12 km a 0,30, un envio de 10 km cuesta
 * 8 x 0,40 + 2 x 0,30 = 3,80, no 10 x 0,30.
 */
function yora_calcular_tarifa(mysqli $db, float $km): array
{
    $km = max(0.0, $km);
    $tramos = yora_tramos_tarifa($db);

    $costo = 0.0;
    $desde = 0.0;
    $desglose = [];

    foreach ($tramos as $tramo) {
        $hasta = $tramo['hasta'] === null ? $km : min($km, (float) $tramo['hasta']);
        $km_tramo = $hasta - $desde;

        if ($km_tramo > 0) {
            $parcial = $km_tramo * $tramo['precio'];
            $costo += $parcial;
            $desglose[] = [
                'desde'  => round($desde, 2),
                'hasta'  => round($hasta, 2),
                'km'     => round($km_tramo, 2),
                'precio' => $tramo['precio'],
                'monto'  => round($parcial, 2),
            ];
        }

        $desde = $tramo['hasta'] === null ? $km : (float) $tramo['hasta'];
        if ($desde >= $km) {
            break;
        }
    }

    $minima = yora_tarifa_minima($db);

    return [
        'km'        => round($km, 2),
        'precio_km' => (float) $tramos[0]['precio'],
        'costo'     => max($minima, round($costo, 2)),
        'minima'    => $minima,
        'desglose'  => $desglose,
    ];
}

/**
 * Codigo publico del pedido, tipo "ID3541". Es lo que ven comercio, driver y
 * cliente, para no andar mostrando el numero correlativo de la base de datos.
 *
 * Los pedidos creados antes de esta mejora no tienen codigo: se les genera y
 * se guarda la primera vez que alguien los abre.
 */
function yora_codigo_comanda(mysqli $db, int $comanda_id, ?string $actual = null): string
{
    $actual = trim((string) $actual);
    if ($actual !== '') {
        return $actual;
    }

    $fila = yora_one($db, 'SELECT codigo FROM comandas WHERE id = ?', 'i', $comanda_id);
    if ($fila && trim((string) $fila['codigo']) !== '') {
        return trim((string) $fila['codigo']);
    }

    for ($intento = 0; $intento < 25; $intento++) {
        // Tras varios choques se pasa a 6 digitos para no quedarse atascado.
        $digitos = $intento < 15 ? 5 : 6;
        $codigo = 'ID' . str_pad((string) random_int(1, (10 ** $digitos) - 1), $digitos, '0', STR_PAD_LEFT);
        try {
            $puesto = yora_exec(
                $db,
                "UPDATE comandas SET codigo = ? WHERE id = ? AND (codigo IS NULL OR codigo = '')",
                'si',
                $codigo,
                $comanda_id
            );
            if ($puesto > 0) {
                return $codigo;
            }
            // No se actualizo: o ya tenia codigo, o lo puso otra peticion.
            $fila = yora_one($db, 'SELECT codigo FROM comandas WHERE id = ?', 'i', $comanda_id);
            if ($fila && trim((string) $fila['codigo']) !== '') {
                return trim((string) $fila['codigo']);
            }
        } catch (Throwable $e) {
            // Codigo repetido: se reintenta con otro numero.
        }
    }

    return 'ID' . str_pad((string) $comanda_id, 5, '0', STR_PAD_LEFT);
}

/** Cuenta pedidos entregados del comercio. No debe tumbar un viaje si la columna no existe. */
function yora_comercio_contar_entregas(mysqli $db, int $comercio_id): void
{
    if ($comercio_id < 1) {
        return;
    }
    $n = 0;
    try {
        $n = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas WHERE comercio_id = ? AND TRIM(estatus) = 'Entregado'",
            'i',
            $comercio_id
        )['total'] ?? 0);
    } catch (Throwable $e) {
        return;
    }
    foreach (['entregas_totales', 'envios_totales'] as $col) {
        try {
            yora_exec($db, "UPDATE comercios SET {$col} = ? WHERE id = ?", 'ii', $n, $comercio_id);
        } catch (Throwable $e) {
            error_log('yora_comercio_contar_entregas ' . $col . ': ' . $e->getMessage());
        }
    }
}

/** Los niveles del Club Yora de comercios, tal como estan en el panel HQ. */
function yora_niveles_comercio(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $cache = yora_all($db, 'SELECT * FROM niveles_comercio ORDER BY pedidos_desde ASC, nivel ASC');
    } catch (Throwable $e) {
        error_log('yora_niveles_comercio: ' . $e->getMessage());
    }
    return $cache;
}

/**
 * Nivel del comercio segun los pedidos ENTREGADOS en el mes en curso.
 * El contador se reinicia solo el dia 1 de cada mes.
 */
function yora_nivel_comercio(mysqli $db, int $comercio_id): array
{
    $pedidos = 0;
    try {
        $pedidos = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas
             WHERE comercio_id = ?
               AND TRIM(estatus) = 'Entregado'
               AND COALESCE(fecha_entrega, fecha_creacion) >= ?",
            'is',
            $comercio_id,
            date('Y-m-01 00:00:00')
        )['total'] ?? 0);
        $vida = (int) (yora_one(
            $db,
            "SELECT COUNT(*) AS total FROM comandas WHERE comercio_id = ? AND TRIM(estatus) = 'Entregado'",
            'i',
            $comercio_id
        )['total'] ?? 0);
        yora_comercio_contar_entregas($db, $comercio_id);
    } catch (Throwable $e) {
        error_log('yora_nivel_comercio: ' . $e->getMessage());
    }

    $niveles = yora_niveles_comercio($db);
    $actual = null;
    $siguiente = null;

    foreach ($niveles as $n) {
        $desde = (int) $n['pedidos_desde'];
        $hasta = $n['pedidos_hasta'] === null ? PHP_INT_MAX : (int) $n['pedidos_hasta'];
        if ($pedidos >= $desde && $pedidos <= $hasta) {
            $actual = $n;
        } elseif ($pedidos < $desde && $siguiente === null) {
            $siguiente = $n;
        }
    }

    if ($actual === null) {
        $actual = $niveles[0] ?? [
            'nivel' => 1, 'nombre' => 'Nivel 1', 'pedidos_desde' => 0, 'pedidos_hasta' => 50,
            'bono_mensual' => 0.00, 'margen_credito' => 0.00, 'color' => '#94a3b8',
            'estrellas' => 0, 'beneficios' => 'Acceso total al panel',
        ];
    }

    $actual['pedidos_mes']      = $pedidos;
    $actual['meta']             = $siguiente ? (int) $siguiente['pedidos_desde'] : max(1, (int) $actual['pedidos_desde']);
    $actual['siguiente_nombre'] = (string) ($siguiente['nombre'] ?? '');
    $actual['lista_beneficios'] = array_values(array_filter(array_map(
        'trim',
        explode('|', (string) ($actual['beneficios'] ?? ''))
    )));

    return $actual;
}

/**
 * Acredita el bono del nivel una sola vez por mes calendario.
 * Devuelve el monto acreditado, o 0 si ya lo recibio o su nivel no da bono.
 */
function yora_acreditar_bono_mensual(mysqli $db, int $comercio_id, array $nivel): float
{
    $bono = round((float) ($nivel['bono_mensual'] ?? 0), 2);
    if ($bono <= 0) {
        return 0.0;
    }

    // La condicion del WHERE es la que garantiza que no se pague dos veces,
    // aunque el comercio abra el panel en dos pestanas a la vez.
    $mes = date('Y-m');
    try {
        $n = yora_exec(
            $db,
            'UPDATE comercios SET billetera = billetera + ?, bono_mes = ? WHERE id = ? AND (bono_mes IS NULL OR bono_mes <> ?)',
            'dsis',
            $bono,
            $mes,
            $comercio_id,
            $mes
        );
        return $n > 0 ? $bono : 0.0;
    } catch (Throwable $e) {
        error_log('yora_acreditar_bono_mensual: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Margen de respaldo en saldo.
 *
 * Un comercio con nivel puede seguir despachando aunque su billetera llegue a
 * cero, hasta el limite negativo que da su nivel. Si pasa mas de 24 horas en
 * negativo sin recargar, queda bloqueado hasta que salde la deuda.
 */
function yora_estado_deuda_comercio(mysqli $db, int $comercio_id, float $saldo, ?string $deuda_desde, array $nivel): array
{
    $margen = round((float) ($nivel['margen_credito'] ?? 0), 2);
    $deuda_desde = trim((string) $deuda_desde);

    if ($saldo >= 0) {
        if ($deuda_desde !== '') {
            try {
                yora_exec($db, 'UPDATE comercios SET deuda_desde = NULL, bloqueado_deuda = 0 WHERE id = ?', 'i', $comercio_id);
            } catch (Throwable $e) {
                error_log('yora_estado_deuda_comercio: ' . $e->getMessage());
            }
        }
        return ['margen' => $margen, 'deuda' => 0.0, 'horas' => 0.0, 'restantes' => 24.0, 'bloqueado' => false];
    }

    try {
        if ($deuda_desde === '') {
            $deuda_desde = date('Y-m-d H:i:s');
            yora_exec($db, 'UPDATE comercios SET deuda_desde = ? WHERE id = ?', 'si', $deuda_desde, $comercio_id);
        }

        $horas = (time() - strtotime($deuda_desde)) / 3600;
        $bloqueado = $horas >= 24;
        yora_exec($db, 'UPDATE comercios SET bloqueado_deuda = ? WHERE id = ?', 'ii', $bloqueado ? 1 : 0, $comercio_id);

        return [
            'margen'    => $margen,
            'deuda'     => round(abs($saldo), 2),
            'horas'     => round($horas, 1),
            'restantes' => max(0.0, round(24 - $horas, 1)),
            'bloqueado' => $bloqueado,
        ];
    } catch (Throwable $e) {
        error_log('yora_estado_deuda_comercio: ' . $e->getMessage());
        return ['margen' => $margen, 'deuda' => round(abs($saldo), 2), 'horas' => 0.0, 'restantes' => 24.0, 'bloqueado' => false];
    }
}

/**
 * Guarda o actualiza el cliente en la agenda del comercio.
 * Cada comercio tiene la suya: la cedula solo es unica dentro del mismo local.
 */
function yora_guardar_cliente_comercio(mysqli $db, int $comercio_id, string $cedula, string $nombre, string $telefono, string $referencia): void
{
    $cedula = trim($cedula);
    if ($cedula === '' || mb_strlen($cedula) > 30) {
        return;
    }

    try {
        yora_exec(
            $db,
            'INSERT INTO clientes_comercio (comercio_id, cedula, nombre, telefono, referencia, pedidos)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                telefono = VALUES(telefono),
                referencia = IF(VALUES(referencia) = \'\', referencia, VALUES(referencia)),
                pedidos = pedidos + 1,
                ultima_vez = NOW()',
            'issss',
            $comercio_id,
            $cedula,
            mb_substr($nombre, 0, 120),
            mb_substr($telefono, 0, 30),
            mb_substr($referencia, 0, 255)
        );
    } catch (Throwable $e) {
        error_log('yora_guardar_cliente_comercio: ' . $e->getMessage());
    }
}

/**
 * Une pedidos del mismo comercio cuya entrega esta a 1 km o menos.
 * Cada pedido entra en un solo par. Devuelve [ [pedidoA, pedidoB], ... ].
 */

function yora_texto_destino(?string $direccion): string
{
    $texto = trim(explode('|', (string) $direccion)[0]);
    $texto = trim(preg_replace('/^(destino\s*:)?/iu', '', $texto));
    return $texto;
}

function yora_clave_destino(?string $direccion): string
{
    $t = mb_strtolower(yora_texto_destino($direccion), 'UTF-8');
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    $genericos = [
        '',
        'ubicacion enviada por gps',
        'ubicación enviada por gps',
        'ubicacion en el mapa',
        'ubicación en el mapa',
    ];
    if (in_array($t, $genericos, true) || mb_strlen($t) < 3) {
        return '';
    }
    return $t;
}

function yora_texto_tiempo(string $valor): string
{
    $map = [
        '0' => 'Ya está listo para retirar',
        '5' => 'Listo en 5 min',
        '10' => 'Listo en 10 min',
        '15' => 'Listo en 15 min',
        '30' => 'Listo en 30 min',
        '45' => 'Listo en 45 min',
        '60' => 'Listo en 60 min',
        'Lo antes posible' => 'Ya está listo para retirar',
    ];
    $valor = trim($valor);
    return $map[$valor] ?? $valor;
}

function yora_pares_ganga(array $pedidos, float $radio_km = 1.0): array
{
    $por_comercio = [];
    foreach ($pedidos as $p) {
        $por_comercio[(int) ($p['comercio_id'] ?? 0)][] = $p;
    }

    $pares = [];
    $usados = [];

    foreach ($por_comercio as $lista) {
        $n = count($lista);
        if ($n < 2) {
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            $a = $lista[$i];
            $id_a = (int) $a['id'];
            if (isset($usados[$id_a])) {
                continue;
            }
            $gps_a = yora_gps_desde_direccion($a['direccion_entrega'] ?? '');
            $clave_a = yora_clave_destino($a['direccion_entrega'] ?? '');
            if (!$gps_a && $clave_a === '') {
                continue;
            }
            $mejor = null;
            $mejor_dist = $radio_km + 1;
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $lista[$j];
                $id_b = (int) $b['id'];
                if (isset($usados[$id_b])) {
                    continue;
                }
                $gps_b = yora_gps_desde_direccion($b['direccion_entrega'] ?? '');
                $clave_b = yora_clave_destino($b['direccion_entrega'] ?? '');
                $d = null;
                if ($gps_a && $gps_b) {
                    $d = yora_haversine($gps_a[0], $gps_a[1], $gps_b[0], $gps_b[1]);
                }
                $misma_ref = ($clave_a !== '' && $clave_a === $clave_b);
                $cerca = ($d !== null && $d <= $radio_km);
                if (!$cerca && !$misma_ref) {
                    continue;
                }
                $score = ($d !== null) ? $d : 0.0;
                if ($score < $mejor_dist) {
                    $mejor = $b;
                    $mejor_dist = $score;
                }
            }
            if ($mejor) {
                $usados[$id_a] = true;
                $usados[(int) $mejor['id']] = true;
                $pares[] = [$a, $mejor];
            }
        }
    }

    return $pares;
}

/** Color, fondo y estrellas del bloque de comanda segun el nivel del comercio. */
function yora_estilo_nivel(array $nivel): array
{
    $estrellas = (int) ($nivel['estrellas'] ?? 0);
    $color = (string) ($nivel['color'] ?? '#64748b');
    if ($estrellas >= 3) {
        return ['borde' => '#ca8a04', 'fondo' => '#fffbeb', 'color' => '#ca8a04', 'estrella' => '★★★', 'nombre' => (string) ($nivel['nombre'] ?? 'Nivel 4')];
    }
    if ($estrellas === 2) {
        return ['borde' => '#94a3b8', 'fondo' => '#f8fafc', 'color' => '#475569', 'estrella' => '★★', 'nombre' => (string) ($nivel['nombre'] ?? 'Nivel 3')];
    }
    if ($estrellas === 1) {
        return ['borde' => '#b45309', 'fondo' => '#fff7ed', 'color' => '#b45309', 'estrella' => '★', 'nombre' => (string) ($nivel['nombre'] ?? 'Nivel 2')];
    }
    return ['borde' => '#e5e7eb', 'fondo' => '#ffffff', 'color' => $color, 'estrella' => '', 'nombre' => (string) ($nivel['nombre'] ?? 'Nivel 1')];
}

function yora_coords_ok(float $lat, float $lng): bool
{
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0);
}

/** Firma para que Median mande GPS en segundo plano sin cookies de sesión. */
function yora_gps_fondo_clave(): string
{
    $pass = getenv('YORA_DB_PASS') ?: 'yora-gps';
    return hash('sha256', 'yora-gps-fondo-v1|' . $pass, true);
}

function yora_gps_fondo_token(int $id): string
{
    return hash_hmac('sha256', 'c:' . $id, yora_gps_fondo_clave());
}

function yora_gps_fondo_ok(int $id, string $token): bool
{
    return $id > 0 && $token !== '' && hash_equals(yora_gps_fondo_token($id), $token);
}

function yora_guardar_gps_conductor(mysqli $db, int $id, float $lat, float $lng, ?float $heading = null, ?float $acc = null): void
{
    if ($id < 1 || !yora_coords_ok($lat, $lng)) {
        return;
    }
    $sql = 'UPDATE conductores SET ultima_lat = ?, ultima_lng = ?, ultima_gps = NOW()';
    $types = 'dd';
    $params = [$lat, $lng];
    if ($heading !== null && is_finite($heading) && $heading >= 0 && $heading <= 360) {
        $sql .= ', ultima_heading = ?';
        $types .= 'd';
        $params[] = round($heading, 2);
    }
    if ($acc !== null && is_finite($acc) && $acc >= 0 && $acc < 5000) {
        $sql .= ', ultima_acc = ?';
        $types .= 'd';
        $params[] = round($acc, 2);
    }
    $sql .= ' WHERE id = ?';
    $types .= 'i';
    $params[] = $id;
    yora_exec($db, $sql, $types, ...$params);
}

function yora_comercio_online(?string $ultima): bool
{
    if ($ultima === null || trim($ultima) === '' || $ultima === '0000-00-00 00:00:00') {
        return false;
    }
    $ts = strtotime($ultima);
    return $ts !== false && (time() - $ts) <= 180;
}

function yora_comercio_esta_abierto(array $comercio): bool
{
    $abre = yora_hora_sql((string) ($comercio['hora_abre'] ?? ''));
    $cierra = yora_hora_sql((string) ($comercio['hora_cierra'] ?? ''));
    if ($abre === '' || $cierra === '') {
        return true;
    }
    $ahora = date('H:i:s');
    if ($abre <= $cierra) {
        return $ahora >= $abre && $ahora <= $cierra;
    }
    return $ahora >= $abre || $ahora <= $cierra;
}

function yora_can_transition(string $from, string $to): bool
{
    $map = [
        'Buscando Conductor' => ['Revisando', 'Cancelado'],
        'Revisando' => ['En Camino a Comercio', 'Buscando Conductor', 'Cancelado'],
        'En Camino a Comercio' => ['En Camino a Cliente', 'Cancelado'],
        'En Camino a Cliente' => ['Entregado'],
        'Esperando Pago' => ['Cancelado', 'Buscando Conductor'],
    ];
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

function yora_timezone(mysqli $db): void
{
    date_default_timezone_set('America/Caracas');
    $db->query("SET time_zone = '-04:00'");
    $db->set_charset('utf8mb4');
}

function yora_password_looks_hashed(string $hash): bool
{
    return (bool) preg_match('/^\$2[ayb]\$\d{2}\$/', $hash);
}

/**
 * Carga PHPMailer desde cualquiera de las rutas donde vive en el hosting.
 */
function yora_phpmailer_cargado(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }
    $rutas = [
        __DIR__ . '/public_html/PHPMailer/',
        __DIR__ . '/public_html/hq_sistema_interno_v1/PHPMailer/',
        __DIR__ . '/PHPMailer/',
        '/home/yoradelivery/public_html/hq_sistema_interno_v1/PHPMailer/',
        '/home/yoradelivery/domains/yoradelivery.com/public_html/PHPMailer/',
        '/home/yoradelivery/domains/yoradelivery.com/public_html/hq_sistema_interno_v1/PHPMailer/',
    ];
    foreach ($rutas as $base) {
        if (is_file($base . 'PHPMailer.php')) {
            require_once $base . 'Exception.php';
            require_once $base . 'PHPMailer.php';
            require_once $base . 'SMTP.php';
            return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
        }
    }
    return false;
}

/**
 * Envia un correo y deja en $error el motivo exacto del fallo.
 * Intenta SMTPS (465) y, si el hosting lo bloquea, reintenta con STARTTLS (587).
 */
function yora_enviar_correo(string $destino, string $nombre, string $asunto, string $html, ?string &$error = null): bool
{
    $error = null;
    $destino = trim($destino);

    if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        $error = 'El conductor no tiene un correo electrónico válido registrado.';
        return false;
    }
    if (!yora_phpmailer_cargado()) {
        $error = 'No se encontró la librería PHPMailer en el servidor.';
        return false;
    }

    $intentos = [
        [(int) ($GLOBALS['YORA_SMTP_PORT'] ?? 465), 'ssl'],
        [587, 'tls'],
    ];

    foreach ($intentos as [$puerto, $cifrado]) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $GLOBALS['YORA_SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $GLOBALS['YORA_SMTP_USER'] ?? '';
            $mail->Password = $GLOBALS['YORA_SMTP_PASS'] ?? '';
            $mail->SMTPSecure = $cifrado;
            $mail->Port = $puerto;
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($GLOBALS['YORA_SMTP_USER'] ?? 'yoradelivery@gmail.com', 'Yora Delivery');
            $mail->addReplyTo($GLOBALS['YORA_SMTP_USER'] ?? 'yoradelivery@gmail.com', 'Soporte Yora Delivery');
            $mail->addAddress($destino, $nombre !== '' ? $nombre : $destino);
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body = $html;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));

            $mail->send();
            return true;
        } catch (Throwable $e) {
            $error = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            error_log('YORA mail (' . $cifrado . ':' . $puerto . '): ' . $error);
        }
    }

    return false;
}

/**
 * Envoltura HTML comun para los correos transaccionales.
 */
function yora_plantilla_correo(string $titulo, string $cuerpo, string $color = '#e4441b', string $cta_texto = '', string $cta_url = ''): string
{
    $boton = '';
    if ($cta_texto !== '' && $cta_url !== '') {
        $boton = '<tr><td align="center" style="padding: 10px 35px 35px;">
            <a href="' . yora_h($cta_url) . '" style="display:inline-block; background-color:' . $color . '; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700; padding:16px 36px; border-radius:12px;">' . yora_h($cta_texto) . '</a>
        </td></tr>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
    <body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f1f5f9; padding:30px 10px;">
        <tr><td align="center">
          <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:580px; background-color:#ffffff; border-radius:24px; overflow:hidden;">
            <tr><td align="center" style="background:' . $color . '; padding:32px 20px;">
              <h1 style="color:#ffffff; font-size:22px; font-weight:800; margin:0;">Yora Delivery</h1>
            </td></tr>
            <tr><td style="padding:35px 35px 10px;">
              <h2 style="color:#0f172a; font-size:20px; font-weight:800; margin:0 0 14px;">' . yora_h($titulo) . '</h2>
              <div style="color:#475569; font-size:15px; line-height:1.65;">' . $cuerpo . '</div>
            </td></tr>
            ' . $boton . '
            <tr><td style="padding:0 35px 30px; text-align:center; color:#94a3b8; font-size:12px;">
              Mensaje automático de Yora Delivery. ¿Dudas? Escríbenos por WhatsApp al ' . YORA_WHATSAPP_SOPORTE_TXT . '.
            </td></tr>
          </table>
        </td></tr>
      </table>
    </body></html>';
}

function yora_slug_comercio(string $nombre, int $id): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre;
    $s = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $s));
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'comercio';
    }
    return $s . '-' . $id;
}

function yora_verify_and_upgrade_password(mysqli $db, string $table, int $id, string $plain, string $stored): bool
{
    $permitidas = ['conductores' => true, 'comercios' => true, 'usuarios_app' => true];
    if (!isset($permitidas[$table])) {
        return false;
    }
    if ($stored !== '' && password_verify($plain, $stored)) {
        return true;
    }
    if ($stored !== '' && !yora_password_looks_hashed($stored) && hash_equals($stored, $plain)) {
        $new = password_hash($plain, PASSWORD_DEFAULT);
        yora_exec($db, "UPDATE {$table} SET password = ? WHERE id = ?", 'si', $new, $id);
        return true;
    }
    return false;
}

/**
 * =====================================================================
 *  VERIFICACION DE CUENTA DE CONDUCTORES (expediente de documentos)
 * =====================================================================
 *  Un solo catalogo para las dos puntas del sistema: la app del driver
 *  (donde sube cada documento) y el panel HQ (donde se aprueba o se
 *  rechaza). Si algun dia se agrega o se quita un documento, se cambia
 *  aqui y los dos lados quedan igual.
 *
 *  Estado por documento: Falta | Cargado | En Revisión | Aprobado | Rechazado
 *  Estado de la cuenta (conductores.estado_documentos): Pendiente |
 *  En Revisión | Verificado | Rechazado
 * =====================================================================
 */

/** Documentos del expediente, en el orden en que se le piden al conductor. */
function yora_docs_catalogo(): array
{
    return [
        'foto_carnet' => [
            'etiqueta'  => 'Cédula de identidad',
            'icono'     => 'ph-identification-badge',
            'ayuda'     => 'Foto de tu cédula por el lado de los datos. Nítida y sin cortar los bordes.',
            'vehiculos' => ['moto', 'bicicleta', 'carga'],
        ],
        'foto_licencia' => [
            'etiqueta'  => 'Licencia de conducir',
            'icono'     => 'ph-identification-card',
            'ayuda'     => 'Vigente y legible. Si tiene datos por detrás, sube el lado con la foto.',
            'vehiculos' => ['moto', 'carga'],
        ],
        'foto_certificado' => [
            'etiqueta'  => 'Certificado médico',
            'icono'     => 'ph-first-aid-kit',
            'ayuda'     => 'Certificado médico vigente a tu nombre.',
            'vehiculos' => ['moto', 'bicicleta', 'carga'],
        ],
        'foto_circulacion' => [
            'etiqueta'  => 'Carnet de circulación (opcional)',
            'icono'     => 'ph-file-text',
            'ayuda'     => 'Opcional. Si tu moto es nueva y no lo tienes, sube el certificado de origen. Con uno de los dos basta.',
            'vehiculos' => ['moto', 'carga'],
        ],
        'foto_origen' => [
            'etiqueta'  => 'Certificado de origen (opcional)',
            'icono'     => 'ph-scroll',
            'ayuda'     => 'Opcional. Sirve si no tienes carnet de circulación. Si ya subiste el carnet, este no aplica.',
            'vehiculos' => ['moto', 'carga'],
        ],
        'foto_rcv' => [
            'etiqueta'  => 'Póliza RCV',
            'icono'     => 'ph-shield-check',
            'ayuda'     => 'Responsabilidad civil vigente. Si está vencida no podemos aprobarla.',
            'vehiculos' => ['moto', 'carga'],
        ],
        'foto_vehiculo' => [
            'etiqueta'  => 'Foto del vehículo',
            'icono'     => 'ph-motorcycle',
            'ayuda'     => 'De frente, completo y con la placa visible.',
            'vehiculos' => ['moto', 'bicicleta', 'carga'],
        ],
    ];
}

/**
 * Documentos que le tocan a este conductor. Una bicicleta no tiene placa ni
 * póliza RCV: pedirselos solo lo dejaria trabado para siempre.
 */
function yora_docs_para_vehiculo(?string $tipo): array
{
    $tipo = strtolower(trim((string) $tipo));
    if (!in_array($tipo, ['moto', 'bicicleta', 'carga'], true)) {
        $tipo = 'moto';
    }
    $lista = [];
    foreach (yora_docs_catalogo() as $campo => $info) {
        if (in_array($tipo, $info['vehiculos'], true)) {
            $lista[$campo] = $info;
        }
    }
    return $lista;
}

/**
 * Añade las dos columnas que necesita la revisión documento por documento.
 * Es idempotente: mira si ya existen y solo las crea la primera vez, asi el
 * despliegue no depende de que alguien recuerde correr el SQL a mano.
 */
function yora_docs_esquema(mysqli $db): bool
{
    static $listo = null;
    if ($listo !== null) {
        return $listo;
    }
    $listo = false;
    try {
        $tiene = [];
        $res = $db->query("SHOW COLUMNS FROM conductores LIKE 'docs\\_%'");
        while ($res && $fila = $res->fetch_assoc()) {
            $tiene[] = (string) $fila['Field'];
        }
        $faltan = [];
        if (!in_array('docs_revision', $tiene, true)) {
            $faltan[] = 'ADD COLUMN docs_revision TEXT NULL';
        }
        if (!in_array('docs_enviado_en', $tiene, true)) {
            $faltan[] = 'ADD COLUMN docs_enviado_en DATETIME NULL';
        }
        // La columna nacio con DEFAULT 'Verificado': cada conductor nuevo entraba
        // ya verificado sin haber subido nada. Se corrige aparte, porque si este
        // ALTER fallara no debe bloquear el guardado de las revisiones.
        $col = $db->query("SHOW COLUMNS FROM conductores LIKE 'estado_documentos'");
        $info = $col ? $col->fetch_assoc() : null;
        if ($info && trim((string) ($info['Default'] ?? '')) === 'Verificado') {
            $db->query("ALTER TABLE conductores MODIFY COLUMN estado_documentos VARCHAR(20) DEFAULT 'Pendiente'");
        }

        if (!$faltan) {
            $listo = true;
            return $listo;
        }
        $listo = (bool) $db->query('ALTER TABLE conductores ' . implode(', ', $faltan));
        if (!$listo) {
            error_log('yora_docs_esquema: ' . $db->error);
        }
    } catch (Throwable $e) {
        error_log('yora_docs_esquema: ' . $e->getMessage());
    }
    return $listo;
}

/** Lee la revisión guardada (JSON) y devuelve siempre un array manejable. */
function yora_docs_leer_revision($valor): array
{
    if (is_array($valor)) {
        return $valor;
    }
    $texto = trim((string) $valor);
    if ($texto === '') {
        return [];
    }
    $datos = json_decode($texto, true);
    return is_array($datos) ? $datos : [];
}

function yora_docs_guardar_revision(mysqli $db, int $id, array $revision): void
{
    if (!yora_docs_esquema($db)) {
        return;
    }
    $json = json_encode($revision, JSON_UNESCAPED_UNICODE);
    yora_exec($db, 'UPDATE conductores SET docs_revision = ? WHERE id = ?', 'si', (string) $json, $id);
}

/**
 * Firma la aprobación de una cuenta: marca cada documento como aprobado y deja
 * constancia de quién y cuándo. Esa firma es la única prueba válida de que la
 * cuenta la revisó una persona, así que la usan la app y el panel por igual.
 */
function yora_docs_aprobar_cuenta(mysqli $db, array $conductor, string $quien = 'HQ'): void
{
    $id = (int) ($conductor['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
    $ahora = date('Y-m-d H:i:s');
    foreach (array_keys(yora_docs_para_vehiculo($conductor['tipo_vehiculo'] ?? 'moto')) as $campo) {
        $revision[$campo] = ['estado' => 'Aprobado', 'motivo' => '', 'fecha' => $ahora];
    }
    $revision['_cuenta'] = ['verificado_en' => $ahora, 'verificado_por' => $quien, 'motivo' => ''];
    yora_docs_guardar_revision($db, $id, $revision);
    yora_exec($db, "UPDATE conductores SET estado_documentos = 'Verificado' WHERE id = ?", 'i', $id);
}

/** Le quita la firma de aprobación a una cuenta (la deja sin verificar). */
function yora_docs_quitar_aprobacion(mysqli $db, array $conductor, string $motivo = ''): void
{
    $id = (int) ($conductor['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
    $cuenta = is_array($revision['_cuenta'] ?? null) ? $revision['_cuenta'] : [];
    unset($cuenta['verificado_en'], $cuenta['verificado_por']);
    if ($motivo !== '') {
        $cuenta['motivo'] = $motivo;
        $cuenta['fecha'] = date('Y-m-d H:i:s');
    }
    if ($cuenta) {
        $revision['_cuenta'] = $cuenta;
    } else {
        unset($revision['_cuenta']);
    }
    yora_docs_guardar_revision($db, $id, $revision);
}

/** Colores y texto de cada estado, iguales en la app y en el panel. */
function yora_docs_estilo(string $estado): array
{
    switch ($estado) {
        case 'Aprobado':
            return ['texto' => 'Aprobado', 'color' => '#15803d', 'fondo' => '#dcfce7', 'icono' => 'ph-check-circle'];
        case 'Rechazado':
            return ['texto' => 'Rechazado', 'color' => '#b91c1c', 'fondo' => '#fee2e2', 'icono' => 'ph-x-circle'];
        case 'En Revisión':
            return ['texto' => 'En revisión', 'color' => '#a16207', 'fondo' => '#fef3c7', 'icono' => 'ph-clock-afternoon'];
        case 'Cargado':
            return ['texto' => 'Listo para enviar', 'color' => '#1d4ed8', 'fondo' => '#dbeafe', 'icono' => 'ph-paper-plane-tilt'];
        case 'Verificado':
            return ['texto' => 'Verificado', 'color' => '#15803d', 'fondo' => '#dcfce7', 'icono' => 'ph-shield-check'];
        case 'Pendiente':
            return ['texto' => 'Sin verificar', 'color' => '#b45309', 'fondo' => '#ffedd5', 'icono' => 'ph-shield-warning'];
        case 'No aplica':
            return ['texto' => 'No aplica', 'color' => '#64748b', 'fondo' => '#f1f5f9', 'icono' => 'ph-minus-circle'];
        case 'Opcional':
            return ['texto' => 'Opcional', 'color' => '#64748b', 'fondo' => '#f1f5f9', 'icono' => 'ph-info'];
        default:
            return ['texto' => 'Falta subirlo', 'color' => '#b91c1c', 'fondo' => '#fee2e2', 'icono' => 'ph-upload-simple'];
    }
}

/**
 * Radiografia completa del expediente de un conductor.
 *
 * Recibe la fila de "conductores" (tal cual sale de SELECT *) y devuelve todo
 * lo que necesitan las pantallas: estado real de la cuenta, estado de cada
 * documento, cuantos faltan y si ya puede mandarlo a revisión.
 *
 * Ojo con el historico: la columna estado_documentos nacio con valor por
 * defecto 'Verificado', asi que hay conductores marcados como verificados sin
 * un solo documento cargado. Por eso el estado que manda es el CALCULADO: si
 * falta algun documento obligatorio, la cuenta no esta verificada.
 */
function yora_docs_estado(array $conductor, string $base_url = ''): array
{
    $declarado = trim((string) ($conductor['estado_documentos'] ?? ''));
    if (!in_array($declarado, ['Pendiente', 'En Revisión', 'Verificado', 'Rechazado'], true)) {
        $declarado = 'Pendiente';
    }
    $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
    $pedidos = yora_docs_para_vehiculo($conductor['tipo_vehiculo'] ?? 'moto');

    // Una cuenta solo esta verificada si alguien del HQ la aprobo y quedo
    // firmada en "_cuenta.verificado_en". Sin esa firma, un estado_documentos
    // = 'Verificado' es solo el valor por defecto viejo de la tabla: si no,
    // el conductor se auto-verificaria con solo terminar de subir sus fotos.
    $firmado = trim((string) ($revision['_cuenta']['verificado_en'] ?? '')) !== '';
    $aprobado_hq = $declarado === 'Verificado' && $firmado;

    $tiene_circ = trim((string) ($conductor['foto_circulacion'] ?? '')) !== '';
    $tiene_ori  = trim((string) ($conductor['foto_origen'] ?? '')) !== '';

    $items = [];
    $cargados = 0;
    $aprobados = 0;
    $rechazados = 0;
    $requeridos = 0;
    $faltantes = 0;

    foreach ($pedidos as $campo => $info) {
        $ruta = trim((string) ($conductor[$campo] ?? ''));
        $cargado = $ruta !== '';
        $marca = is_array($revision[$campo] ?? null) ? $revision[$campo] : [];
        $motivo = trim((string) ($marca['motivo'] ?? ''));
        $aplica = true;

        if (in_array($campo, ['foto_origen', 'foto_circulacion'], true) && !$cargado) {
            if ($campo === 'foto_circulacion') {
                $estado = $tiene_ori ? 'No aplica' : 'Opcional';
            } else {
                $estado = $tiene_circ ? 'No aplica' : 'Opcional';
            }
            $motivo = '';
            $aplica = false;
        } elseif (!$cargado) {
            $estado = 'Falta';
            $motivo = '';
        } elseif (($marca['estado'] ?? '') === 'Aprobado') {
            $estado = 'Aprobado';
        } elseif (($marca['estado'] ?? '') === 'Rechazado') {
            $estado = 'Rechazado';
        } elseif ($declarado === 'En Revisión') {
            $estado = 'En Revisión';
        } elseif ($aprobado_hq) {
            $estado = 'Aprobado';
        } else {
            $estado = 'Cargado';
        }

        if ($aplica) {
            $requeridos++;
            if ($cargado) {
                $cargados++;
            }
            if ($estado === 'Falta') {
                $faltantes++;
            }
        }
        if ($estado === 'Aprobado') {
            $aprobados++;
        }
        if ($estado === 'Rechazado') {
            $rechazados++;
        }

        $items[$campo] = $info + [
            'campo'   => $campo,
            'url'     => $cargado ? yora_url_archivo($ruta, '', $base_url) : '',
            'cargado' => $cargado,
            'aplica'  => $aplica,
            'estado'  => $estado,
            'motivo'  => $motivo,
            'estilo'  => yora_docs_estilo($estado),
            'fecha'   => trim((string) ($marca['fecha'] ?? '')),
        ];
    }

    $total = $requeridos;

    if ($aprobado_hq && $faltantes === 0) {
        $global = 'Verificado';
    } elseif ($faltantes > 0) {
        $global = 'Pendiente';
    } elseif ($rechazados > 0 || $declarado === 'Rechazado') {
        $global = 'Rechazado';
    } elseif ($declarado === 'En Revisión') {
        $global = 'En Revisión';
    } else {
        // Estan los documentos, pero todavia no pulso "Enviar a verificación".
        $global = 'Cargado';
    }

    $motivo_global = '';
    if (is_array($revision['_cuenta'] ?? null)) {
        $motivo_global = trim((string) ($revision['_cuenta']['motivo'] ?? ''));
    }

    return [
        'global'      => $global,
        'declarado'   => $declarado,
        'estilo'      => yora_docs_estilo($global),
        'motivo'      => $global === 'Rechazado' ? $motivo_global : '',
        'items'       => $items,
        'total'       => $total,
        'cargados'    => $cargados,
        'faltantes'   => $faltantes,
        'aprobados'   => $aprobados,
        'rechazados'  => $rechazados,
        'porcentaje'  => $total > 0 ? (int) round(($cargados / $total) * 100) : 0,
        'completo'    => $faltantes === 0,
        'verificado'  => $global === 'Verificado',
        'enviado_en'  => trim((string) ($conductor['docs_enviado_en'] ?? '')),
        // Puede mandar el expediente cuando esta completo y no esta ya en cola ni aprobado.
        'puede_enviar' => $faltantes === 0 && $global !== 'Verificado' && $declarado !== 'En Revisión',
    ];
}

/** Documentos de identidad del usuario de client.yoradelivery.com. */
function yora_cliente_docs_campos(): array
{
    return [
        'selfie_url' => [
            'etiqueta' => 'Selfie',
            'ayuda'    => 'Foto de tu cara, de frente, con buena luz. Sin gorra ni lentes oscuros.',
            'icono'    => 'ph-camera',
        ],
        'cedula_foto_url' => [
            'etiqueta' => 'Cédula de identidad',
            'ayuda'    => 'Foto de tu cédula por el lado de los datos. Nítida y sin cortar los bordes.',
            'icono'    => 'ph-identification-card',
        ],
    ];
}

function yora_cliente_docs_estilo(string $estado): array
{
    $map = [
        'Verificado'  => ['texto' => 'Verificado', 'color' => '#16a34a', 'fondo' => '#dcfce7', 'icono' => 'ph-seal-check'],
        'En Revisión' => ['texto' => 'En revisión', 'color' => '#d97706', 'fondo' => '#fef3c7', 'icono' => 'ph-clock-afternoon'],
        'Rechazado'   => ['texto' => 'Rechazado', 'color' => '#dc2626', 'fondo' => '#fee2e2', 'icono' => 'ph-warning'],
        'Cargado'     => ['texto' => 'Cargado', 'color' => '#2563eb', 'fondo' => '#dbeafe', 'icono' => 'ph-check'],
        'Pendiente'   => ['texto' => 'Pendiente', 'color' => '#64748b', 'fondo' => '#f1f5f9', 'icono' => 'ph-identification-card'],
    ];
    return $map[$estado] ?? $map['Pendiente'];
}

function yora_cliente_docs_estado(array $user): array
{
    $declarado = trim((string) ($user['estado_documentos'] ?? 'Pendiente'));
    if ($declarado === '') {
        $declarado = 'Pendiente';
    }
    $revision = [];
    $raw = trim((string) ($user['docs_revision'] ?? ''));
    if ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $revision = $tmp;
        }
    }
    $items = [];
    $cargados = 0;
    $total = 0;
    foreach (yora_cliente_docs_campos() as $campo => $info) {
        $total++;
        $url = trim((string) ($user[$campo] ?? ''));
        $marca = is_array($revision[$campo] ?? null) ? $revision[$campo] : [];
        $est = trim((string) ($marca['estado'] ?? ''));
        if ($est === '' && $url !== '') {
            $est = 'Cargado';
        }
        if ($est === '') {
            $est = 'Pendiente';
        }
        if ($url !== '') {
            $cargados++;
        }
        $items[$campo] = [
            'etiqueta' => $info['etiqueta'],
            'ayuda'    => $info['ayuda'],
            'icono'    => $info['icono'],
            'url'      => $url,
            'cargado'  => $url !== '',
            'estado'   => $est,
            'motivo'   => trim((string) ($marca['motivo'] ?? '')),
            'estilo'   => yora_cliente_docs_estilo($est),
        ];
    }
    $faltantes = $total - $cargados;
    $global = $declarado;
    if ($declarado !== 'Verificado' && $declarado !== 'En Revisión' && $declarado !== 'Rechazado') {
        $global = $faltantes === 0 ? 'Cargado' : 'Pendiente';
    }
    $cuenta = is_array($revision['_cuenta'] ?? null) ? $revision['_cuenta'] : [];
    return [
        'items'        => $items,
        'cargados'     => $cargados,
        'faltantes'    => $faltantes,
        'total'        => $total,
        'porcentaje'   => $total > 0 ? (int) round(($cargados / $total) * 100) : 0,
        'global'       => $global,
        'declarado'    => $declarado,
        'verificado'   => $declarado === 'Verificado',
        'puede_pedir'  => in_array($declarado, ['Verificado', 'En Revisión'], true),
        'puede_enviar' => $faltantes === 0 && $declarado !== 'Verificado' && $declarado !== 'En Revisión',
        'motivo'       => trim((string) ($cuenta['motivo'] ?? '')),
        'enviado_en'   => trim((string) ($user['docs_enviado_en'] ?? '')),
        'estilo'       => yora_cliente_docs_estilo($global === 'Cargado' ? 'Cargado' : $declarado),
    ];
}

function yora_cliente_guardar_revision(mysqli $db, int $id, array $revision): void
{
    $json = json_encode($revision, JSON_UNESCAPED_UNICODE);
    yora_exec($db, 'UPDATE usuarios_app SET docs_revision = ? WHERE id = ?', 'si', (string) $json, $id);
}

function yora_notificar_cliente_correo(array $user, string $tipo, string $motivo = ''): void
{
    $correo = trim((string) ($user['correo'] ?? ''));
    $nombre = trim((string) ($user['nombre'] ?? 'Cliente'));
    if ($correo === '') {
        return;
    }
    if ($tipo === 'verificado') {
        $html = yora_plantilla_correo(
            'Tu cuenta quedó verificada',
            '<p>Hola <b>' . yora_h($nombre) . '</b>, ya revisamos tu selfie y tu cédula. Tu cuenta de Yora está <b>verificada</b>.</p><p>Ya puedes pedir mandaditos con normalidad.</p>',
            '#16a34a',
            'Pedir un mandadito',
            'https://client.yoradelivery.com/dashboard.php'
        );
        yora_enviar_correo($correo, $nombre, 'Tu cuenta Yora está verificada', $html);
        return;
    }
    if ($tipo === 'rechazo') {
        $html = yora_plantilla_correo(
            'Necesitamos que corrijas tu verificación',
            '<p>Hola <b>' . yora_h($nombre) . '</b>, revisamos tus documentos y necesitamos que los subas de nuevo.</p><p><b>Motivo:</b> ' . yora_h($motivo !== '' ? $motivo : 'Foto ilegible o no coincide.') . '</p><p>Entra a <b>Ajustes → Verificar identidad</b>, corrige y envía otra vez.</p>',
            '#dc2626',
            'Corregir documentos',
            'https://client.yoradelivery.com/ajustes_documentos.php'
        );
        yora_enviar_correo($correo, $nombre, 'Yora: corrige tu verificación', $html);
        return;
    }
    if ($tipo === 'recibido') {
        $html = yora_plantilla_correo(
            'Recibimos tu verificación',
            '<p>Hola <b>' . yora_h($nombre) . '</b>, ya tenemos tu selfie y tu cédula. El equipo de Yora las está revisando.</p><p>Te avisamos por este correo y por WhatsApp cuando quede lista.</p>',
            '#d97706',
            'Ver el estado',
            'https://client.yoradelivery.com/ajustes.php'
        );
        yora_enviar_correo($correo, $nombre, 'Yora recibió tus documentos', $html);
        return;
    }
    if ($tipo === 'clave') {
        $html = yora_plantilla_correo(
            'Nueva contraseña de tu cuenta',
            '<p>Hola <b>' . yora_h($nombre) . '</b>, el equipo de Yora generó una nueva contraseña para tu cuenta:</p><p style="font-size:1.35rem;font-weight:800;letter-spacing:1px;">' . yora_h($motivo) . '</p><p>Entra a <a href="https://client.yoradelivery.com">client.yoradelivery.com</a> y cámbiala en Ajustes → Seguridad.</p>',
            '#e4441b',
            'Entrar a Yora',
            'https://client.yoradelivery.com'
        );
        yora_enviar_correo($correo, $nombre, 'Yora: tu nueva contraseña', $html);
    }
}

require_once __DIR__ . '/yora_push.php';
