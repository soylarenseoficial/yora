<?php

require_once __DIR__ . '/conexion.php';
yora_require_admin_pagina('conductores.php');
yora_timezone($conexion);

if (!defined('YORA_URL_ANDROID')) {
    define('YORA_URL_ANDROID', 'https://yoradelivery.com/android');
}
if (!defined('YORA_WA_SOPORTE')) {
    define('YORA_WA_SOPORTE', '584225097031');
}
if (!defined('YORA_WA_SOPORTE_TXT')) {
    define('YORA_WA_SOPORTE_TXT', '+58 422-5097031');
}
if (!defined('YORA_CANAL_DRIVERS')) {
    define('YORA_CANAL_DRIVERS', 'https://chat.whatsapp.com/JerJmqZMrKOIk0XSE6ASgU');
}

$mensaje_sys = "";
$tipo_mensaje = "";
$wa_pendiente = null; // Dispara el aviso con el boton de WhatsApp tras aprobar, rechazar o enviar clave

// Toda accion POST del panel exige token CSRF u origen propio.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !yora_verify_same_origin() && !yora_csrf_ok()) {
    http_response_code(403);
    exit('Solicitud rechazada.');
}

// Valores que acepta la base de datos. Fuera de estas listas MySQL guarda vacio.
$VEHICULOS  = ['moto' => 'Moto', 'bicicleta' => 'Bicicleta', 'carga' => 'Vehículo de carga'];
$ESTATUS    = [
    'activo'    => '🟢 Activo (puede operar)',
    'inactivo'  => '🔴 Suspendido / Bloqueado',
    'pendiente' => '🟡 Pendiente de revisión',
    'rechazado' => '⚫ Rechazado (baja definitiva)',
];
$CATEGORIAS = ['Sencillo' => 'Sencillo', 'Pro' => 'Pro'];
$SI_NO      = ['No' => 'No', 'Si' => 'Sí'];
// 'En Revisión' es el estado que deja la app cuando el conductor envia su
// expediente. Si no estuviera en esta lista, al guardar este formulario se
// volveria 'Verificado' sin que nadie lo haya revisado.
$VERIFICACION = [
    'Verificado'  => 'Verificado',
    'En Revisión' => 'En Revisión (enviado por el driver)',
    'Pendiente'   => 'Pendiente',
    'Rechazado'   => 'Rechazado',
];

// Documentos que el conductor puede tener cargados.
$DOCUMENTOS = [
    'foto_carnet'      => '👤 Cédula de identidad',
    'foto_licencia'    => '📄 Licencia de conducir',
    'foto_certificado' => '🏥 Certificado médico',
    'foto_circulacion' => '📝 Carnet de circulación',
    'foto_origen'      => '📜 Certificado de origen',
    'foto_rcv'         => '🛡️ Póliza RCV',
    'foto_vehiculo'    => '🛵 Foto del vehículo',
];

$UPLOAD_DIR = __DIR__ . '/../uploads/';

/** Deja el valor dentro de la lista permitida o devuelve el primero. */
function opcion_valida($valor, array $opciones, string $defecto): string
{
    $valor = (string) $valor;
    return array_key_exists($valor, $opciones) ? $valor : $defecto;
}

function yora_str($valor): string
{
    return trim((string) ($valor ?? ''));
}

function yora_generar_clave_app(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $clave = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < 10; $i++) {
        $clave .= $chars[random_int(0, $max)];
    }
    return $clave;
}

function yora_html_instrucciones_apk(): string
{
    $url = YORA_URL_ANDROID;
    return '<p><b>Cómo instalar la app en Android</b></p>
        <p>La app todavía <b>no está en Google Play</b>. Al descargar el APK, tu teléfono puede rechazarlo o decir que es peligroso. Es normal: Android bloquea cualquier app que no venga de la tienda hasta que tú la autorices.</p>
        <ol>
            <li>Entra a <a href="' . yora_h($url) . '">' . yora_h($url) . '</a> y pulsa <b>Descargar App</b>.</li>
            <li>Si Chrome dice que el archivo puede ser dañino, pulsa <b>Descargar de todos modos</b>.</li>
            <li>Abre el archivo descargado (Yora Driver).</li>
            <li>Si aparece «Por seguridad, no se permite instalar aplicaciones desconocidas», pulsa <b>Ajustes</b> y activa <b>Permitir desde esta fuente</b>.</li>
            <li>Vuelve atrás y pulsa <b>Instalar</b>.</li>
            <li>Si Google Play Protect avisa, pulsa <b>Más información</b> y luego <b>Instalar de todos modos</b>.</li>
        </ol>
        <p>Guía completa: <a href="' . yora_h($url) . '">' . yora_h($url) . '</a></p>';
}

function yora_texto_instrucciones_apk(): string
{
    return "⚠️ La app todavía *no está en Google Play*. Al descargar, el teléfono puede avisar que el archivo es peligroso. Es normal. Haz esto:\n\n"
        . "1. Entra a " . YORA_URL_ANDROID . " y pulsa *Descargar App*.\n"
        . "2. Si Chrome dice que es dañino, pulsa «Descargar de todos modos».\n"
        . "3. Abre el archivo descargado.\n"
        . "4. Si dice que no se pueden instalar apps desconocidas, pulsa *Ajustes* y activa «Permitir desde esta fuente».\n"
        . "5. Vuelve e *Instalar*.\n"
        . "6. Si Play Protect avisa, pulsa «Más información» y luego «Instalar de todos modos».\n";
}

function yora_html_canal_drivers(): string
{
    $url = YORA_CANAL_DRIVERS;
    return '<p style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:14px 16px;">
        <b>¡Únete al canal informativo para Drivers para mantenerte informado de las actualizaciones!</b><br>
        <a href="' . yora_h($url) . '">' . yora_h($url) . '</a>
    </p>';
}

function yora_texto_canal_drivers(): string
{
    return "¡Únete al canal informativo para Drivers para mantenerte informado de las actualizaciones!\n"
        . YORA_CANAL_DRIVERS . "\n";
}

function yora_html_bloque_acceso($cedula, $clave): string
{
    $cedula = yora_str($cedula);
    $clave = yora_str($clave);
    $claveHtml = $clave !== ''
        ? '<div style="font-size:15px;"><b>Contraseña:</b> <span style="font-family:monospace; font-size:16px; color:#e4441b;">' . yora_h($clave) . '</span></div>'
        : '<div style="font-size:15px;">Usa la clave que te enviamos por correo o WhatsApp.</div>';

    return '<table width="100%" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; margin:18px 0;">
            <tr><td style="padding:20px 24px;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:800; letter-spacing:1px; margin-bottom:12px;">Tu acceso</div>
                <div style="font-size:15px; margin-bottom:8px;"><b>Usuario (cédula):</b> <span style="font-family:monospace; font-size:16px;">' . yora_h($cedula !== '' ? $cedula : '—') . '</span></div>
                ' . $claveHtml . '
            </td></tr>
        </table>';
}

function yora_enviar_correo_acceso($correo, $nombre, $cedula, $clave, $titulo, $intro): array
{
    $correo = yora_str($correo);
    $nombre = yora_str($nombre);
    $cedula = yora_str($cedula);
    $clave = yora_str($clave);
    $titulo = yora_str($titulo);
    $intro = (string) ($intro ?? '');
    $err = null;
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return [false, 'sin correo'];
    }
    $cuerpo = $intro
        . yora_html_bloque_acceso($cedula, $clave)
        . yora_html_instrucciones_apk()
        . yora_html_canal_drivers()
        . '<p>Soporte WhatsApp: <b>' . yora_h(YORA_WA_SOPORTE_TXT) . '</b></p>';
    $html = yora_plantilla_correo($titulo, $cuerpo, '#e4441b', 'Descarga la App', YORA_URL_ANDROID);
    $ok = yora_enviar_correo($correo, $nombre, 'Tu acceso a Yora Driver | Descarga la App', $html, $err);
    return [$ok, yora_str($err)];
}

// =====================================================================
// 1. REGISTRAR NUEVO CONDUCTOR (MANUAL DESDE EL ADMIN)
// =====================================================================
if (isset($_POST['registrar'])) {
    $nombre       = trim($_POST['nombre'] ?? '');
    $cedula       = trim($_POST['cedula'] ?? '');
    $rif          = 'V-' . preg_replace('/^V-?\s*/i', '', trim($_POST['rif'] ?? ''));
    $correo       = trim($_POST['correo'] ?? '');
    $telefono     = trim($_POST['telefono'] ?? '');
    $fnac         = trim($_POST['fecha_nacimiento'] ?? '');
    $vehiculo     = opcion_valida($_POST['vehiculo'] ?? '', $VEHICULOS, 'moto');
    $placa        = trim($_POST['placa'] ?? '');
    $licencia_num = trim($_POST['licencia_num'] ?? '');
    $cert_medico  = trim($_POST['certificado_medico'] ?? '');
    $banco        = trim($_POST['banco_pago'] ?? '');
    $telf_pago    = trim($_POST['telefono_pago'] ?? '');
    $ced_pago     = trim($_POST['cedula_pago'] ?? '');
    $categoria    = opcion_valida($_POST['categoria'] ?? '', $CATEGORIAS, 'Sencillo');
    $estatus      = opcion_valida($_POST['estatus_inicial'] ?? '', $ESTATUS, 'activo');
    $antecedentes = opcion_valida($_POST['antecedentes_penales'] ?? '', $SI_NO, 'No');
    $otra_app     = opcion_valida($_POST['otra_app'] ?? '', $SI_NO, 'No');
    // Un registro nuevo nace sin verificar: la verificación la da el equipo
    // cuando revisa los documentos en "Verificaciones".
    $verificacion = opcion_valida($_POST['estado_documentos'] ?? '', $VERIFICACION, 'Pendiente');
    $saldo        = round((float) ($_POST['billetera'] ?? 0), 2);
    $password_plana = (string) ($_POST['password'] ?? '');

    $edad = yora_valid_date($fnac) ? date_diff(date_create($fnac), date_create('today'))->y : 0;

    // Validaciones antes de tocar la base de datos
    if ($nombre === '' || $telefono === '' || $cedula === '') {
        $mensaje_sys = "Nombre, cédula y teléfono son obligatorios.";
        $tipo_mensaje = "error";
    } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $mensaje_sys = "El correo electrónico no tiene un formato válido.";
        $tipo_mensaje = "error";
    } elseif (strlen($password_plana) < 8) {
        $mensaje_sys = "La contraseña de acceso debe tener al menos 8 caracteres.";
        $tipo_mensaje = "error";
    } elseif ($edad < 21) {
        $mensaje_sys = "El conductor debe ser mayor de 21 años (revisa la fecha de nacimiento).";
        $tipo_mensaje = "error";
    } elseif (yora_one($conexion, 'SELECT id FROM conductores WHERE telefono = ? OR cedula = ? LIMIT 1', 'ss', $telefono, $cedula)) {
        $mensaje_sys = "Ya existe un conductor con ese teléfono o esa cédula.";
        $tipo_mensaje = "error";
    } else {
        // Documentos: todos opcionales en el alta manual
        $archivos = [];
        foreach (array_keys($DOCUMENTOS) as $campo) {
            $archivos[$campo] = '';
            if (isset($_FILES[$campo]) && ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $guardado = yora_upload($_FILES[$campo], $UPLOAD_DIR);
                if ($guardado) {
                    $archivos[$campo] = 'uploads/' . $guardado;
                } else {
                    $mensaje_sys = "El archivo de «" . $DOCUMENTOS[$campo] . "» debe ser JPG, PNG o WEBP de máximo 5 MB.";
                    $tipo_mensaje = "error";
                }
            }
        }

        if ($tipo_mensaje !== 'error') {
            $password = password_hash($password_plana, PASSWORD_DEFAULT);
            $sql = "INSERT INTO conductores
                    (nombre, cedula, rif, correo, fecha_nacimiento, telefono, tipo_vehiculo, placa,
                     licencia, certificado_medico, banco_pago, telefono_pago, cedula_pago, categoria,
                     password, estatus, antecedentes_penales, otra_app, estado_documentos, billetera,
                     foto_carnet, foto_licencia, foto_certificado, foto_circulacion, foto_origen, foto_rcv, foto_vehiculo)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            try {
                yora_exec(
                    $conexion,
                    $sql,
                    'sssssssssssssssssssdsssssss',
                    $nombre, $cedula, $rif, $correo, $fnac, $telefono, $vehiculo, $placa,
                    $licencia_num, $cert_medico, $banco, $telf_pago, $ced_pago, $categoria,
                    $password, $estatus, $antecedentes, $otra_app, $verificacion, $saldo,
                    $archivos['foto_carnet'], $archivos['foto_licencia'], $archivos['foto_certificado'],
                    $archivos['foto_circulacion'], $archivos['foto_origen'], $archivos['foto_rcv'],
                    $archivos['foto_vehiculo']
                );
                yora_pass_flag_esquema($conexion);
                $nuevoId = (int) $conexion->insert_id;
                if ($nuevoId > 0) {
                    yora_exec($conexion, 'UPDATE conductores SET debe_cambiar_password = 1 WHERE id = ?', 'i', $nuevoId);
                }

                $mensaje_sys = "Conductor <b>" . yora_h($nombre) . "</b> registrado con éxito.";
                $tipo_mensaje = "exito";

                if ($estatus === 'activo') {
                    $intro = '<p>Tu cuenta de conductor ya está creada y activa. Descarga la app, instálala con los pasos de abajo y entra con tu <b>cédula</b> y esta clave:</p>';
                    [$mailOk, $mailErr] = yora_enviar_correo_acceso($correo, $nombre, $cedula, $password_plana, '¡Bienvenido a la red, ' . $nombre . '!', $intro);
                    if ($correo !== '') {
                        if ($mailOk) {
                            $mensaje_sys .= " Correo de bienvenida enviado a " . yora_h($correo) . ".";
                        } else {
                            $mensaje_sys .= " <b>Aviso:</b> no se pudo enviar el correo (" . yora_h((string) $mailErr) . ").";
                        }
                    }
                    $wa_pendiente = [
                        'tipo'     => 'acceso',
                        'nombre'   => $nombre,
                        'telefono' => $telefono,
                        'cedula'   => $cedula,
                        'clave'    => $password_plana,
                    ];
                } else {
                    $mensaje_sys .= " Quedó en pendiente: al aprobarlo se genera la clave y se envía un solo correo y un WhatsApp.";
                }
            } catch (Throwable $e) {
                error_log('YORA alta conductor: ' . $e->getMessage());
                $mensaje_sys = "No se pudo registrar el conductor. Revisa que la cédula o el teléfono no estén ya en uso.";
                $tipo_mensaje = "error";
            }
        }
    }
}

// =====================================================================
// 2. APROBAR CONDUCTOR (Desde Pendientes)
// =====================================================================
if (isset($_POST['aprobar_conductor'])) {
    $id = (int) ($_POST['id_conductor'] ?? 0);
    $info = yora_one($conexion, 'SELECT nombre, correo, telefono, cedula FROM conductores WHERE id = ?', 'i', $id);
    if ($info) {
        $clave = yora_generar_clave_app();
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        yora_pass_flag_esquema($conexion);
        yora_exec(
            $conexion,
            "UPDATE conductores SET estatus = 'activo', estado_documentos = 'Pendiente', password = ?, debe_cambiar_password = 1 WHERE id = ?",
            'si',
            $hash,
            $id
        );

        $nombre = yora_str($info['nombre'] ?? '');
        $correo = yora_str($info['correo'] ?? '');
        $telefono = yora_str($info['telefono'] ?? '');
        $cedula = yora_str($info['cedula'] ?? '');

        $mensaje_sys = "Conductor <b>" . yora_h($nombre) . "</b> aprobado. Clave generada.";
        $tipo_mensaje = "exito";

        $intro = '<p>Tu perfil fue revisado y <b>aprobado</b>. Ya eres oficialmente un Yora Driver.</p>
                  <p>Descarga la app, instálala (Android puede mostrar avisos de seguridad porque aún no estamos en Google Play) y entra con tu <b>cédula</b> y la clave de abajo.</p>';
        [$mailOk, $mailErr] = yora_enviar_correo_acceso(
            $correo,
            $nombre,
            $cedula,
            $clave,
            '¡Bienvenido a la red, ' . $nombre . '!',
            $intro
        );
        if ($mailOk) {
            $mensaje_sys .= " Se envió un solo correo de acceso.";
        } else {
            $mensaje_sys .= " <b>Aviso:</b> el correo no salió (" . yora_h($mailErr) . ").";
        }

        $wa_pendiente = [
            'tipo'     => 'acceso',
            'nombre'   => $nombre,
            'telefono' => $telefono,
            'cedula'   => $cedula,
            'clave'    => $clave,
        ];
    }
}

// =====================================================================
// 3. RECHAZAR CONDUCTOR (Desde Pendientes)
// =====================================================================
if (isset($_POST['rechazar_conductor'])) {
    $id = (int) ($_POST['id_conductor'] ?? 0);
    $motivo = trim((string) ($_POST['motivo_rechazo'] ?? ''));
    $info = yora_one($conexion, 'SELECT nombre, correo, telefono FROM conductores WHERE id = ?', 'i', $id);
    if ($info) {
        yora_exec($conexion, "UPDATE conductores SET estatus = 'rechazado' WHERE id = ?", 'i', $id);

        $cuerpo = '<p>Hola ' . yora_h($info['nombre']) . ', revisamos tu postulación y por ahora <b>no podemos aprobar tu ingreso</b> por el siguiente motivo:</p>
                   <blockquote style="background:#f8fafc; padding:15px; border-left:4px solid #dc2626; font-style:italic; margin:16px 0;">' . yora_h($motivo) . '</blockquote>
                   <p>Puedes corregirlo y volver a postularte cuando quieras.</p>
                   <p>Soporte WhatsApp: <b>' . yora_h(YORA_WA_SOPORTE_TXT) . '</b></p>';
        $html = yora_plantilla_correo('Actualización de tu solicitud', $cuerpo, '#dc2626');

        $err = null;
        yora_enviar_correo((string) $info['correo'], (string) $info['nombre'], 'Actualización sobre tu postulación a Yora', $html, $err);

        $mensaje_sys = "Conductor <b>" . yora_h($info['nombre']) . "</b> rechazado" . ($err ? " (el correo no salió: " . yora_h($err) . ")" : " y notificado por correo") . ".";
        $tipo_mensaje = "error";

        $wa_pendiente = [
            'tipo'     => 'rechazo',
            'nombre'   => $info['nombre'],
            'telefono' => $info['telefono'],
            'motivo'   => $motivo,
        ];
    }
}

// =====================================================================
// 4. ACTUALIZAR EXPEDIENTE COMPLETO (Desde Flota Activa o Pendientes)
// =====================================================================
if (isset($_POST['actualizar_conductor'])) {
    $id = (int) ($_POST['driver_id'] ?? 0);
    $actual = yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $id);

    if (!$actual) {
        $mensaje_sys = "No encontramos ese conductor.";
        $tipo_mensaje = "error";
    } else {
        $nombre       = yora_str($_POST['nombre'] ?? '');
        $cedula       = yora_str($_POST['cedula'] ?? '');
        $rif          = 'V-' . preg_replace('/^V-?\s*/i', '', yora_str($_POST['rif'] ?? ''));
        $correo       = yora_str($_POST['correo'] ?? '');
        $telefono     = yora_str($_POST['telefono'] ?? '');
        $fnac         = yora_valid_date(trim($_POST['fecha_nacimiento'] ?? '')) ? trim($_POST['fecha_nacimiento']) : null;
        $vehiculo     = opcion_valida($_POST['tipo_vehiculo'] ?? '', $VEHICULOS, (string) $actual['tipo_vehiculo']);
        $placa        = trim($_POST['placa'] ?? '');
        $licencia     = trim($_POST['licencia'] ?? '');
        $certificado  = trim($_POST['certificado_medico'] ?? '');
        $banco        = trim($_POST['banco_pago'] ?? '');
        $telf_pago    = trim($_POST['telefono_pago'] ?? '');
        $ced_pago     = trim($_POST['cedula_pago'] ?? '');
        $categoria    = opcion_valida($_POST['categoria'] ?? '', $CATEGORIAS, 'Sencillo');
        $antecedentes = opcion_valida($_POST['antecedentes_penales'] ?? '', $SI_NO, 'No');
        $otra_app     = opcion_valida($_POST['otra_app'] ?? '', $SI_NO, 'No');
        // Si llega un valor raro, se respeta el estado que ya tenía guardado.
        $verificacion = opcion_valida($_POST['estado_documentos'] ?? '', $VERIFICACION, (string) ($actual['estado_documentos'] ?: 'Pendiente'));
        $en_linea     = isset($_POST['en_linea']) ? 1 : 0;
        $ajuste_saldo = round((float) ($_POST['ajuste_billetera'] ?? 0), 2);

        // Si esta en viaje no lo sacamos del viaje sin querer al guardar el formulario.
        $estatus = opcion_valida($_POST['estatus'] ?? '', $ESTATUS, 'activo');
        if ($actual['estatus'] === 'en_viaje' && $estatus === 'activo') {
            $estatus = 'en_viaje';
        }

        if ($nombre === '' || $telefono === '') {
            $mensaje_sys = "El nombre y el teléfono no pueden quedar vacíos.";
            $tipo_mensaje = "error";
        } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje_sys = "El correo electrónico no tiene un formato válido.";
            $tipo_mensaje = "error";
        } elseif (yora_one($conexion, 'SELECT id FROM conductores WHERE telefono = ? AND id <> ? LIMIT 1', 'si', $telefono, $id)) {
            $mensaje_sys = "Ese teléfono ya lo usa otro conductor.";
            $tipo_mensaje = "error";
        } else {
            try {
                yora_exec(
                    $conexion,
                    'UPDATE conductores SET nombre=?, cedula=?, rif=?, correo=?, telefono=?, fecha_nacimiento=?,
                        tipo_vehiculo=?, placa=?, licencia=?, certificado_medico=?, banco_pago=?, telefono_pago=?,
                        cedula_pago=?, categoria=?, antecedentes_penales=?, otra_app=?, estado_documentos=?,
                        en_linea=?, estatus=?, billetera = billetera + ?
                     WHERE id=?',
                    'sssssssssssssssssisdi',
                    $nombre, $cedula, $rif, $correo, $telefono, $fnac,
                    $vehiculo, $placa, $licencia, $certificado, $banco, $telf_pago,
                    $ced_pago, $categoria, $antecedentes, $otra_app, $verificacion,
                    $en_linea, $estatus, $ajuste_saldo, $id
                );

                // Reemplazo de documentos (solo los que suban de nuevo)
                $subidos = 0;
                foreach (array_keys($DOCUMENTOS) as $campo) {
                    if (isset($_FILES[$campo]) && ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $guardado = yora_upload($_FILES[$campo], $UPLOAD_DIR);
                        if ($guardado) {
                            $ruta = 'uploads/' . $guardado;
                            yora_exec($conexion, "UPDATE conductores SET {$campo} = ? WHERE id = ?", 'si', $ruta, $id);
                            $subidos++;
                        }
                    }
                }

                // Cambio de contraseña / generador de clave de la App
                $nueva_clave = (string) ($_POST['nueva_password'] ?? '');
                $clave_cambiada = false;
                if ($nueva_clave !== '') {
                    if (strlen($nueva_clave) < 8) {
                        throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
                    }
                    $hash = password_hash($nueva_clave, PASSWORD_DEFAULT);
                    yora_pass_flag_esquema($conexion);
                    yora_exec(
                        $conexion,
                        'UPDATE conductores SET password = ?, debe_cambiar_password = 1 WHERE id = ?',
                        'si',
                        $hash,
                        $id
                    );
                    $clave_cambiada = true;
                }

                $mensaje_sys = "Expediente de <b>" . yora_h($nombre) . "</b> actualizado.";
                if ($subidos > 0)     { $mensaje_sys .= " $subidos documento(s) reemplazado(s)."; }
                if ($ajuste_saldo != 0) { $mensaje_sys .= " Ajuste de saldo: $" . number_format($ajuste_saldo, 2) . "."; }
                if ($clave_cambiada)  {
                    $era_pendiente = yora_str($actual['estatus'] ?? '') === 'pendiente';
                    $ahora_activo = $estatus === 'activo';
                    // Un solo aviso: si sigue pendiente, no mandamos correo/WhatsApp (eso lo hace Aprobar).
                    $notificar = !$era_pendiente || $ahora_activo;
                    if ($notificar) {
                        $intro = $ahora_activo && $era_pendiente
                            ? '<p>Tu perfil fue <b>aprobado</b>. Descarga la app, instálala con los pasos de abajo y entra con tu <b>cédula</b> y esta clave:</p>'
                            : '<p>Actualizamos tu clave de <b>Yora Driver</b>. Entra con tu <b>cédula</b> y estos datos:</p>';
                        [$mailOk, $mailErr] = yora_enviar_correo_acceso($correo, $nombre, $cedula, $nueva_clave, 'Tu acceso a Yora Driver', $intro);
                        if ($mailOk) {
                            $mensaje_sys .= " Contraseña enviada por correo a " . yora_h($correo) . ".";
                        } else {
                            $mensaje_sys .= " Contraseña guardada. <b>Aviso:</b> el correo no salió (" . yora_h((string) $mailErr) . ").";
                        }
                        $wa_pendiente = [
                            'tipo'     => 'acceso',
                            'nombre'   => $nombre,
                            'telefono' => $telefono,
                            'cedula'   => $cedula,
                            'clave'    => $nueva_clave,
                        ];
                    } else {
                        $mensaje_sys .= " Clave guardada. Al pulsar Aprobar se enviará un solo correo y un WhatsApp.";
                    }
                }
                $tipo_mensaje = "exito";
            } catch (Throwable $e) {
                error_log('YORA update conductor: ' . $e->getMessage());
                $mensaje_sys = "No se pudo guardar: " . yora_h($e->getMessage());
                $tipo_mensaje = "error";
            }
        }
    }
}

// =====================================================================
// EXTRAER LISTAS
// =====================================================================
$conductores_pendientes = [];
$conductores_activos = [];

$res = $conexion->query("SELECT * FROM conductores ORDER BY id DESC");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        if ($row['estatus'] == 'pendiente') {
            $conductores_pendientes[] = $row;
        } else {
            $conductores_activos[] = $row;
        }
    }
}

// Datos completos para el modal, sin exponer el hash de la contraseña.
$datos_modal = [];
foreach (array_merge($conductores_pendientes, $conductores_activos) as $row) {
    unset($row['password'], $row['token_app']);
    $datos_modal[(int) $row['id']] = $row;
}

// Solo lo necesario para el botón de WhatsApp de la lista de postulantes.
$datos_postulantes = [];
foreach ($conductores_pendientes as $row) {
    $datos_postulantes[(int) $row['id']] = ['nombre' => $row['nombre'], 'telefono' => $row['telefono']];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Flota y Solicitudes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #e4441b; --primary-hover: #c23310; --bg-main: #f3f4f6; --surface: #ffffff; --text-dark: #111827; --text-gray: #6b7280; --border: #e5e7eb; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-main); color: var(--text-dark); display: flex; height: 100vh; overflow: hidden; }

        /* === SIDEBAR === */
        .sidebar { width: 280px; min-width: 280px; background-color: var(--surface); padding: 25px 20px; border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 10;}
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; color: var(--text-dark); }
        .sidebar h2 span { color: var(--primary); }
        .badge-app { background: rgba(228,68,27,0.1); color: var(--primary); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-gray); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-dark); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(228,68,27,0.1); color: var(--primary); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border); padding-top: 15px; }

        /* === CONTENIDO & TABS === */
        .content { flex: 1; padding: 30px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; }
        .tabs-container { display: flex; gap: 10px; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
        .tab-btn { background: none; border: none; padding: 12px 25px; font-size: 1rem; font-weight: 700; color: var(--text-gray); cursor: pointer; border-bottom: 3px solid transparent; transition: 0.3s; }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; animation: fadeIn 0.3s; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .card-modern { background: var(--surface); border-radius: 20px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); }

        /* === TABLAS === */
        .modern-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .modern-table th { padding: 0 15px 10px; font-size: 0.75rem; text-transform: uppercase; color: var(--text-gray); font-weight: 700; text-align: left; border-bottom: 1px solid var(--border);}
        .modern-table td { background: #fff; padding: 15px; font-size: 0.85rem; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); vertical-align: middle;}
        .modern-table td:first-child { border-left: 1px solid var(--border); border-radius: 12px 0 0 12px; }
        .modern-table td:last-child { border-right: 1px solid var(--border); border-radius: 0 12px 12px 0; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; display: inline-block;}
        .badge-pendiente { background: #fef08a; color: #854d0e; }
        .badge-activo { background: #dcfce7; color: #16a34a; }
        .badge-rechazado, .badge-suspendido { background: #fee2e2; color: #dc2626; }
        .badge-doc { background: #e0f2fe; color: #0369a1; }

        .btn-action { padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;}
        .btn-action:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-green { background: #10b981; color: white; }
        .btn-red { background: #ef4444; color: white; }
        .btn-blue { background: #3b82f6; color: white; }
        .btn-whatsapp { background: #25D366; color: white; text-decoration: none; }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 600; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-exito { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .aviso-wa { display: flex; align-items: center; justify-content: space-between; gap: 15px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 12px; padding: 15px 18px; margin-bottom: 20px; }
        .aviso-wa p { font-size: 0.9rem; color: #15803d; font-weight: 600; margin: 0; }
        .btn-wa-grande { background: #25D366; color: #fff; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; cursor: pointer; white-space: nowrap; font-family: inherit; }

        /* === FORMULARIO MANUAL Y MODAL === */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;}
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;}
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-gray); margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; background: #f9fafb;}
        .form-control:focus { border-color: var(--primary); outline: none; background: #fff; }
        .form-hint { font-size: 0.72rem; color: var(--text-gray); margin-top: 4px; display: block; }
        .btn-submit { background: var(--primary); color: white; padding: 12px 20px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; width: 100%; font-size:1rem; font-family: inherit;}
        .clave-row { display: flex; gap: 8px; align-items: stretch; }
        .clave-row .form-control { flex: 1; }
        .btn-generar { background: #111827; color: #fff; border: none; border-radius: 8px; padding: 0 14px; font-weight: 700; cursor: pointer; font-size: 0.8rem; font-family: inherit; white-space: nowrap; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(17, 24, 39, 0.7); z-index: 1000; justify-content: center; align-items: center; backdrop-filter: blur(4px); padding: 20px;}
        .modal-content { background: var(--surface); padding: 35px; border-radius: 20px; width: 100%; max-width: 780px; max-height: 92vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .section-title { font-size: 0.9rem; font-weight: 700; color: var(--primary); margin: 22px 0 10px; border-bottom: 2px solid #f3f4f6; padding-bottom: 5px;}
        .doc-item { border: 1px solid var(--border); border-radius: 10px; padding: 12px; background: #f9fafb; }
        .doc-item strong { font-size: 0.78rem; display: block; margin-bottom: 6px; color: #374151;}
        .doc-item a { font-size: 0.75rem; color: #3b82f6; font-weight: 600; }
        .doc-item input[type=file] { font-size: 0.72rem; margin-top: 6px; width: 100%; }
        .doc-falta { font-size: 0.75rem; color: #b45309; font-weight: 600; }
        .resumen-saldo { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; padding: 12px 15px; font-size: 0.85rem; color: #9a3412; font-weight: 600; margin-bottom: 12px;}
        .hint-pendientes { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; margin-bottom: 16px; }
        .flota-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
        .flota-bar input, .flota-bar select { border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; font-family:inherit; font-size:0.9rem; }
        .flota-bar input { flex:1; min-width:180px; }
        .flota-bar button { background:#e4441b; color:#fff; border:0; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; font-family:inherit; }
        .flota-vacio { display:none; text-align:center; padding:24px; color:#94a3b8; }
        @media (max-width: 900px) {
            body { display: block; height: auto; overflow: auto; }
            .sidebar { width: 100%; min-width: 0; }
            .content { padding: 16px; }
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
            .tabs-container { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="content">
        <?php
        $en_linea_ahora = 0;
        foreach ($conductores_activos as $c_on) {
            if ((int) ($c_on['en_linea'] ?? 0) === 1) {
                $en_linea_ahora++;
            }
        }
        ?>
        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:12px;">
            <h2 style="font-weight: 800; font-size: 1.8rem; color: var(--text-dark); margin:0;">Centro de Flota y Verificaciones</h2>
            <span style="background:#dcfce7; color:#166534; font-weight:800; font-size:0.85rem; padding:6px 12px; border-radius:999px;">
                ● <?php echo (int) $en_linea_ahora; ?> en línea ahora
            </span>
        </div>

        <?php if ($mensaje_sys != ''): ?>
            <div class="alert alert-<?php echo yora_h($tipo_mensaje); ?>"><?php echo $mensaje_sys; ?></div>
        <?php endif; ?>

        <?php if ($wa_pendiente): ?>
            <div class="aviso-wa">
                <p>💬 ¿Le enviamos el mensaje por WhatsApp a <?php echo yora_h($wa_pendiente['nombre']); ?> (<?php echo yora_h($wa_pendiente['telefono']); ?>)?</p>
                <button type="button" class="btn-wa-grande" onclick="enviarWhatsappPendiente()">Abrir WhatsApp</button>
            </div>
        <?php endif; ?>

        <!-- CONTROLES DE PESTAÑAS -->
        <div class="tabs-container">
            <button class="tab-btn active" onclick="openTab('tab-pendientes', this)">🔔 Solicitudes Pendientes (<?php echo count($conductores_pendientes); ?>)</button>
            <button class="tab-btn" onclick="openTab('tab-activos', this)">🛵 Flota Activa (<?php echo count($conductores_activos); ?>) · <?php echo (int) $en_linea_ahora; ?> en línea</button>
            <button class="tab-btn" onclick="openTab('tab-manual', this)">➕ Registro Manual</button>
        </div>

        <!-- PESTAÑA 1: SOLICITUDES PENDIENTES -->
        <div id="tab-pendientes" class="tab-content active card-modern">
            <p class="hint-pendientes"><b>Un solo aviso:</b> pulsa <b>Aprobar y enviar acceso</b>. Se genera la clave, se activa el conductor y llega un correo y un WhatsApp (cédula + clave + descarga). No hace falta generar la clave antes.</p>
            <div class="table-container">
                <table class="modern-table">
                    <thead style="background: #f8fafc; text-align: left; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 15px;">👤 Postulante</th>
                            <th style="padding: 15px;">🛵 Vehículo y 🛡️ Seguridad</th>
                            <th style="padding: 15px;">🏦 Datos Bancarios</th>
                            <th style="padding: 15px;">📁 Documentos</th>
                            <th style="padding: 15px;">⚙️ Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($conductores_pendientes) > 0): ?>
                            <?php foreach ($conductores_pendientes as $row): ?>
                            <tr>
                                <td style="padding: 15px; font-size: 0.85rem; line-height: 1.6;">
                                    <strong style="font-size: 1rem; color: #1e293b;"><?php echo yora_h($row['nombre']); ?></strong><br>
                                    <span class="badge badge-pendiente"><?php echo yora_h(ucfirst($row['estatus'])); ?></span><br><br>
                                    <span style="color: #64748b;">CI:</span> <?php echo yora_h($row['cedula']); ?><br>
                                    <span style="color: #64748b;">RIF:</span> <?php echo yora_h($row['rif']); ?><br>
                                    <span style="color: #64748b;">Nac:</span> <?php echo $row['fecha_nacimiento'] ? date("d/m/Y", strtotime($row['fecha_nacimiento'])) : '—'; ?><br>
                                    <span style="color: #64748b;">Telf:</span> <?php echo yora_h($row['telefono']); ?><br>
                                    <span style="color: #64748b;">✉️</span> <?php echo yora_h($row['correo'] ?: 'Sin correo'); ?>
                                </td>

                                <td style="padding: 15px; font-size: 0.85rem; line-height: 1.6;">
                                    <strong style="color: #1e293b;"><?php echo yora_h(strtoupper((string) $row['tipo_vehiculo'])); ?></strong><br>
                                    <span style="color: #64748b;">Placa:</span> <?php echo yora_h($row['placa']); ?><br>
                                    <span style="color: #64748b;">Licencia Nº:</span> <?php echo yora_h($row['licencia']); ?><br>
                                    <span style="color: #64748b;">Cert. Médico:</span> <?php echo yora_h($row['certificado_medico']); ?><br>
                                    <hr style="border: 0; border-top: 1px dashed #cbd5e1; margin: 8px 0;">
                                    <span style="color: #64748b;">Antecedentes Penales:</span>
                                    <strong style="color: <?php echo ($row['antecedentes_penales'] == 'Si') ? '#dc2626' : '#16a34a'; ?>;"><?php echo yora_h($row['antecedentes_penales']); ?></strong><br>
                                    <span style="color: #64748b;">Usa otra App:</span> <strong><?php echo yora_h($row['otra_app']); ?></strong>
                                </td>

                                <td style="padding: 15px; font-size: 0.85rem; line-height: 1.6;">
                                    <strong style="color: #1e293b;"><?php echo yora_h($row['banco_pago']); ?></strong><br>
                                    <span style="color: #64748b;">Telf. Pago:</span> <?php echo yora_h($row['telefono_pago']); ?><br>
                                    <span style="color: #64748b;">CI Pago:</span> <?php echo yora_h($row['cedula_pago']); ?>
                                </td>

                                <td style="padding: 15px; font-size: 0.85rem; line-height: 1.6;">
                                    <?php $tiene_docs = false; ?>
                                    <?php foreach ($DOCUMENTOS as $campo => $etiqueta): ?>
                                        <?php if (!empty($row[$campo])): $tiene_docs = true; ?>
                                            <a href="<?php echo yora_h(yora_url_archivo($row[$campo])); ?>" target="_blank" rel="noopener" style="color:#475569; text-decoration:none; display:block;"><?php echo $etiqueta; ?></a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!$tiene_docs): ?><span class="doc-falta">Sin documentos cargados</span><?php endif; ?>
                                </td>

                                <td>
                                    <button type="button" class="btn-action btn-blue" onclick="abrirEditar(<?php echo (int) $row['id']; ?>)">✏️ Editar</button>
                                    <form method="POST" style="display:inline-block;"><?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="id_conductor" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" name="aprobar_conductor" class="btn-action btn-green">✅ Aprobar y enviar acceso</button>
                                    </form>
                                    <form method="POST" style="display:inline-block;" onsubmit="return false;" id="form-rechazo-<?php echo (int) $row['id']; ?>">
                                        <?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="id_conductor" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="motivo_rechazo" id="motivo-<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="rechazar_conductor" value="1">
                                        <button type="button" class="btn-action btn-red" onclick="procesarRechazo(<?php echo (int) $row['id']; ?>)">❌ Rechazar</button>
                                    </form>
                                    <button type="button" class="btn-action btn-whatsapp" style="margin-top:6px;" onclick="whatsappPostulante(<?php echo (int) $row['id']; ?>)">💬 WhatsApp</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-gray);">No hay postulaciones pendientes de revisión.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PESTAÑA 2: FLOTA ACTIVA Y GESTIÓN -->
        <div id="tab-activos" class="tab-content card-modern">
            <form class="flota-bar" onsubmit="return buscarFlota();">
                <input type="search" id="busca-flota" placeholder="Buscar por cédula o nombre" autocomplete="off" oninput="buscarFlota()">
                <select id="filtro-flota" onchange="buscarFlota()">
                    <option value="">Todos</option>
                    <option value="enlinea">En línea</option>
                    <option value="offline">Fuera de línea</option>
                    <option value="verificado">Verificados</option>
                    <option value="pendiente">Sin verificar</option>
                </select>
                <button type="submit">Buscar</button>
                <button type="button" style="background:#f3f4f6; color:#334155;" onclick="limpiarFlota()">Limpiar</button>
            </form>
            <p id="flota-vacio" class="flota-vacio">Ningún motorizado coincide con esa búsqueda.</p>
            <div class="table-container">
                <table class="modern-table">
                    <thead><tr><th>Piloto</th><th>Vehículo</th><th>Datos Bancarios</th><th>Saldo / Docs</th><th>Gestión / Contacto</th></tr></thead>
                    <tbody>
                        <?php if (count($conductores_activos) > 0): ?>
                            <?php foreach ($conductores_activos as $row):
                                $estatus = (string) $row['estatus'];
                                $color_bdg = ($estatus == 'activo' || $estatus == 'en_viaje') ? 'badge-activo' : 'badge-suspendido';
                                $docs_row = yora_docs_estado($row);
                            ?>
                            <tr class="fila-flota"
                                data-cedula="<?php echo yora_h(preg_replace('/\D+/', '', (string) $row['cedula'])); ?>"
                                data-nombre="<?php echo yora_h(mb_strtolower((string) $row['nombre'])); ?>"
                                data-linea="<?php echo (int) $row['en_linea']; ?>"
                                data-docs="<?php echo $docs_row['verificado'] ? 'verificado' : 'pendiente'; ?>">
                                <td>
                                    <?php
                                    $foto_piloto = yora_url_archivo(
                                        $row['foto_perfil'] ?? '',
                                        'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
                                        'https://app.yoradelivery.com'
                                    );
                                    ?>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <img src="<?php echo yora_h(yora_safe_url($foto_piloto, 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png')); ?>" alt="" style="width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid #e5e7eb; background:#f3f4f6; flex-shrink:0;">
                                        <div>
                                            <b><?php echo yora_h($row['nombre']); ?></b><br>
                                            <span class="badge <?php echo $color_bdg; ?>"><?php echo yora_h(ucfirst(str_replace('_', ' ', $estatus))); ?></span>
                                            <?php if ($row['en_linea']): ?><span class="badge badge-doc">En línea</span><?php endif; ?>
                                            <br><small style="color:#94a3b8;">CI <?php echo yora_h($row['cedula']); ?> · <?php echo yora_h($row['telefono']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <b><?php echo yora_h(ucfirst((string) $row['tipo_vehiculo'])); ?></b><br>
                                    <small><?php echo yora_h($row['placa']); ?></small><br>
                                    <small style="color:#94a3b8;"><?php echo yora_h($row['categoria']); ?></small>
                                </td>
                                <td>
                                    🏦 <?php echo yora_h($row['banco_pago']); ?><br>
                                    📱 <?php echo yora_h($row['telefono_pago']); ?><br>
                                    <small style="color:#94a3b8;">CI <?php echo yora_h($row['cedula_pago']); ?></small>
                                </td>
                                <td>
                                    <b style="color:#16a34a;">$<?php echo number_format((float) $row['billetera'], 2); ?></b><br>
                                    <span class="badge" style="background:<?php echo yora_h($docs_row['estilo']['fondo']); ?>; color:<?php echo yora_h($docs_row['estilo']['color']); ?>;">
                                        <?php echo yora_h($docs_row['estilo']['texto']); ?>
                                    </span><br>
                                    <small style="color:#94a3b8;">
                                        <?php echo (int) $docs_row['cargados']; ?>/<?php echo (int) $docs_row['total']; ?> documentos ·
                                        <a href="verificaciones.php" style="color:#3b82f6; font-weight:600;">revisar</a>
                                    </small>
                                </td>
                                <td>
                                    <button class="btn-action btn-blue" onclick="abrirEditar(<?php echo (int) $row['id']; ?>)">⚙️ Gestionar</button>
                                    <button class="btn-action btn-whatsapp" onclick="whatsappBienvenida(<?php echo (int) $row['id']; ?>)">💬 WhatsApp</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-gray);">No hay conductores registrados en el sistema.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PESTAÑA 3: REGISTRO MANUAL -->
        <div id="tab-manual" class="tab-content card-modern">
            <h3 style="margin-bottom: 5px; color: var(--text-dark);">Añadir Conductor a la Flota</h3>
            <p style="color:var(--text-gray); font-size:0.85rem; margin-bottom:20px;">Si lo dejas Activo, recibe un correo con cédula, clave, descarga y el canal de Drivers. Si lo dejas Pendiente, el aviso sale al aprobarlo (un solo correo y un WhatsApp).</p>

            <form method="POST" enctype="multipart/form-data"><?php echo yora_csrf_field(); ?>
                <div class="section-title">Datos Personales</div>
                <div class="form-grid">
                    <div class="form-group"><label>Nombre Completo *</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="form-group"><label>Correo Electrónico</label><input type="email" name="correo" class="form-control" placeholder="Para enviarle sus credenciales"></div>
                    <div class="form-group"><label>Cédula (usuario de la App) *</label><input type="text" name="cedula" class="form-control" required></div>
                    <div class="form-group"><label>RIF (V-)</label><input type="text" name="rif" class="form-control" placeholder="25951632"></div>
                    <div class="form-group"><label>Fecha Nacimiento *</label><input type="date" name="fecha_nacimiento" class="form-control" required><span class="form-hint">Debe ser mayor de 21 años.</span></div>
                    <div class="form-group"><label>Teléfono (WhatsApp) *</label><input type="text" name="telefono" class="form-control" placeholder="04121234567" required></div>
                </div>

                <div class="section-title">Vehículo y Nivel</div>
                <div class="form-grid">
                    <div class="form-group"><label>Vehículo</label>
                        <select name="vehiculo" class="form-control">
                            <?php foreach ($VEHICULOS as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Placa</label><input type="text" name="placa" class="form-control"></div>
                    <div class="form-group"><label>Categoría</label>
                        <select name="categoria" class="form-control">
                            <?php foreach ($CATEGORIAS as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Estatus Inicial</label>
                        <select name="estatus_inicial" class="form-control">
                            <option value="activo">🟢 Activo Inmediato</option>
                            <option value="pendiente">🟡 Dejar en Pendiente</option>
                            <option value="inactivo">🔴 Crear Suspendido</option>
                        </select>
                    </div>
                </div>

                <div class="section-title">Seguridad y Pagos</div>
                <div class="form-grid">
                    <div class="form-group"><label>Licencia Nº</label><input type="text" name="licencia_num" class="form-control"></div>
                    <div class="form-group"><label>Certificado Médico Nº</label><input type="text" name="certificado_medico" class="form-control"></div>
                    <div class="form-group"><label>Banco (Pago Móvil)</label><input type="text" name="banco_pago" class="form-control"></div>
                    <div class="form-group"><label>Teléfono (Pago Móvil)</label><input type="text" name="telefono_pago" class="form-control"></div>
                    <div class="form-group"><label>Cédula Titular del Pago</label><input type="text" name="cedula_pago" class="form-control"></div>
                    <div class="form-group"><label>Contraseña Acceso App *</label><input type="password" name="password" class="form-control" minlength="8" required><span class="form-hint">Mínimo 8 caracteres. El usuario de la app es la cédula. Si lo registras activo, se envía un solo correo y WhatsApp.</span></div>
                </div>

                <div class="section-title">Verificación y Saldo</div>
                <div class="form-grid-3">
                    <div class="form-group"><label>Antecedentes Penales</label>
                        <select name="antecedentes_penales" class="form-control">
                            <?php foreach ($SI_NO as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>¿Usa otra App?</label>
                        <select name="otra_app" class="form-control">
                            <?php foreach ($SI_NO as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Estado de Documentos</label>
                        <select name="estado_documentos" class="form-control">
                            <?php foreach ($VERIFICACION as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="max-width:280px; margin-bottom:15px;">
                    <label>Saldo inicial en billetera ($)</label>
                    <input type="number" step="0.01" min="0" name="billetera" class="form-control" value="0.00">
                </div>

                <div class="section-title">Documentos (opcionales, JPG/PNG/WEBP hasta 5 MB)</div>
                <div class="form-grid-3">
                    <?php foreach ($DOCUMENTOS as $campo => $etiqueta): ?>
                        <div class="doc-item">
                            <strong><?php echo $etiqueta; ?></strong>
                            <input type="file" name="<?php echo yora_h($campo); ?>" accept="image/jpeg,image/png,image/webp">
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" name="registrar" class="btn-submit" style="margin-top:20px;">Registrar Manualmente</button>
            </form>
        </div>

    </div>

    <!-- MODAL DE EDICIÓN COMPLETA -->
    <div id="modal-editar" class="modal">
        <div class="modal-content">
            <h3 id="modal-nombre-driver" style="font-size: 1.4rem; font-weight:800; margin-bottom:5px;">Gestionar Expediente</h3>
            <p style="color:var(--text-gray); font-size:0.85rem; margin-bottom:20px;">Todos los datos registrados del conductor. Lo que edites aquí se guarda al pulsar «Guardar Cambios».</p>

            <form method="POST" enctype="multipart/form-data"><?php echo yora_csrf_field(); ?>
                <input type="hidden" name="driver_id" id="e_id">

                <div class="section-title">Control de Acceso (Sanciones)</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Estatus del Conductor</label>
                        <select name="estatus" id="e_est" class="form-control" style="background:#fef2f2; border-color:#fca5a5; font-weight:bold;">
                            <?php foreach ($ESTATUS as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-hint" id="e_est_hint"></span>
                    </div>
                    <div class="form-group">
                        <label>Disponibilidad</label>
                        <label style="display:flex; align-items:center; gap:8px; font-weight:500; color:#374151; padding-top:8px;">
                            <input type="checkbox" name="en_linea" id="e_enlinea" style="width:18px; height:18px;"> Aparece en línea para recibir viajes
                        </label>
                    </div>
                </div>

                <div class="section-title">Datos Personales</div>
                <div class="form-grid">
                    <div class="form-group"><label>Nombre Completo</label><input type="text" name="nombre" id="e_nombre" class="form-control" required></div>
                    <div class="form-group"><label>Correo Electrónico</label><input type="email" name="correo" id="e_correo" class="form-control"></div>
                    <div class="form-group"><label>Cédula (usuario de la App)</label><input type="text" name="cedula" id="e_cedula" class="form-control"></div>
                    <div class="form-group"><label>Teléfono (WhatsApp)</label><input type="text" name="telefono" id="e_telefono" class="form-control" required></div>
                    <div class="form-group"><label>RIF</label><input type="text" name="rif" id="e_rif" class="form-control"></div>
                    <div class="form-group"><label>Fecha de Nacimiento</label><input type="date" name="fecha_nacimiento" id="e_fnac" class="form-control"></div>
                </div>

                <div class="section-title">Vehículo</div>
                <div class="form-grid-3">
                    <div class="form-group"><label>Tipo de Vehículo</label>
                        <select name="tipo_vehiculo" id="e_vehiculo" class="form-control">
                            <?php foreach ($VEHICULOS as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Placa</label><input type="text" name="placa" id="e_placa" class="form-control"></div>
                    <div class="form-group"><label>Categoría</label>
                        <select name="categoria" id="e_cat" class="form-control">
                            <?php foreach ($CATEGORIAS as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="section-title">Datos Legales y Verificación</div>
                <div class="form-grid">
                    <div class="form-group"><label>Licencia Nº</label><input type="text" name="licencia" id="e_licencia" class="form-control"></div>
                    <div class="form-group"><label>Certificado Médico Nº</label><input type="text" name="certificado_medico" id="e_cert" class="form-control"></div>
                    <div class="form-group"><label>Antecedentes Penales</label>
                        <select name="antecedentes_penales" id="e_antecedentes" class="form-control">
                            <?php foreach ($SI_NO as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>¿Usa otra App?</label>
                        <select name="otra_app" id="e_otraapp" class="form-control">
                            <?php foreach ($SI_NO as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Estado de Documentos</label>
                        <select name="estado_documentos" id="e_verificacion" class="form-control">
                            <?php foreach ($VERIFICACION as $valor => $texto): ?>
                                <option value="<?php echo yora_h($valor); ?>"><?php echo yora_h($texto); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Registrado el</label><input type="text" id="e_registro" class="form-control" disabled></div>
                </div>

                <div class="section-title">Operaciones y Pagos</div>
                <div class="form-grid-3">
                    <div class="form-group"><label>Banco (Pago Móvil)</label><input type="text" name="banco_pago" id="e_banco" class="form-control"></div>
                    <div class="form-group"><label>Teléfono Pago</label><input type="text" name="telefono_pago" id="e_telf" class="form-control"></div>
                    <div class="form-group"><label>Cédula Titular Pago</label><input type="text" name="cedula_pago" id="e_cedp" class="form-control"></div>
                </div>

                <div class="resumen-saldo">Saldo actual en billetera: <span id="e_saldo_actual">$0.00</span></div>
                <div class="form-group" style="max-width:320px; margin-bottom:10px;">
                    <label>Ajuste de saldo ($)</label>
                    <input type="number" step="0.01" name="ajuste_billetera" id="e_ajuste" class="form-control" value="0">
                    <span class="form-hint">Positivo para abonar, negativo para descontar. Déjalo en 0 si no quieres tocar el saldo.</span>
                </div>

                <div class="section-title">Acceso a la App</div>
                <div class="form-group">
                    <label>Clave de la App</label>
                    <div class="clave-row">
                        <input type="text" name="nueva_password" id="e_password" class="form-control" placeholder="Genera una clave o escríbela" minlength="8" autocomplete="new-password">
                        <button type="button" class="btn-generar" onclick="generarClaveApp()">Generar clave</button>
                    </div>
                    <span class="form-hint">Si el conductor ya está activo, al guardar se envía un correo y un WhatsApp de restablecimiento. Si está pendiente, no se envía nada: usa <b>Aprobar y enviar acceso</b> para que le llegue un solo aviso. Usuario de la app: la cédula.</span>
                </div>

                <div class="section-title">Documentos</div>
                <div class="form-grid-3" id="e_documentos"></div>

                <div style="display:flex; gap:15px; margin-top:25px;">
                    <button type="button" onclick="cerrarEditar()" style="background:#f3f4f6; color:#4b5563; border:none; padding:12px; border-radius:8px; cursor:pointer; flex:1; font-weight:600; font-family:inherit;">Cancelar</button>
                    <button type="submit" name="actualizar_conductor" class="btn-submit" style="flex:2; margin-top:0;">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const DRIVERS = <?php echo json_encode($datos_modal, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const POSTULANTES = <?php echo json_encode($datos_postulantes, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const DOCUMENTOS = <?php echo json_encode($DOCUMENTOS, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const WA_PENDIENTE = <?php echo json_encode($wa_pendiente, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const YORA_ANDROID = <?php echo json_encode(YORA_URL_ANDROID, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const YORA_WA_SOPORTE = <?php echo json_encode(YORA_WA_SOPORTE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const YORA_WA_SOPORTE_TXT = <?php echo json_encode(YORA_WA_SOPORTE_TXT, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const YORA_CANAL_DRIVERS = <?php echo json_encode(YORA_CANAL_DRIVERS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escaparHtml(valor) {
            if (valor === null || valor === undefined) return '';
            return String(valor)
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
        }

        function urlArchivo(ruta) {
            const limpio = String(ruta || '').trim();
            if (!limpio || /[\s"'<>]/.test(limpio)) return '';
            if (/^https?:\/\//i.test(limpio)) return limpio;
            return '/' + limpio.replace(/^\/+/, '');
        }

        function openTab(tabName, boton) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            boton.classList.add('active');
        }

        function buscarFlota() {
            const q = (document.getElementById('busca-flota').value || '').replace(/\D+/g, '');
            const nombre = (document.getElementById('busca-flota').value || '').toLowerCase().trim();
            const filtro = document.getElementById('filtro-flota').value;
            let visibles = 0;
            document.querySelectorAll('.fila-flota').forEach(function (tr) {
                const ced = tr.getAttribute('data-cedula') || '';
                const nom = tr.getAttribute('data-nombre') || '';
                const linea = tr.getAttribute('data-linea') === '1';
                const docs = tr.getAttribute('data-docs') || '';
                let ok = true;
                if (nombre !== '') {
                    ok = (q !== '' && ced.indexOf(q) !== -1) || nom.indexOf(nombre) !== -1;
                }
                if (ok && filtro === 'enlinea') ok = linea;
                if (ok && filtro === 'offline') ok = !linea;
                if (ok && filtro === 'verificado') ok = docs === 'verificado';
                if (ok && filtro === 'pendiente') ok = docs === 'pendiente';
                tr.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            document.getElementById('flota-vacio').style.display = visibles ? 'none' : 'block';
            return false;
        }

        function limpiarFlota() {
            document.getElementById('busca-flota').value = '';
            document.getElementById('filtro-flota').value = '';
            buscarFlota();
        }

        function valor(id, dato) {
            const campo = document.getElementById(id);
            if (campo) campo.value = (dato === null || dato === undefined) ? '' : dato;
        }

        function generarClaveApp() {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
            const bytes = new Uint8Array(10);
            crypto.getRandomValues(bytes);
            let clave = '';
            for (let i = 0; i < bytes.length; i++) clave += chars[bytes[i] % chars.length];
            const campo = document.getElementById('e_password');
            campo.type = 'text';
            campo.value = clave;
            campo.focus();
            campo.select();
        }

        function abrirEditar(id) {
            const d = DRIVERS[id];
            if (!d) { alert("No pudimos cargar los datos de este conductor."); return; }

            document.getElementById('modal-nombre-driver').innerText = "Gestionar: " + d.nombre;

            valor('e_id', d.id);
            valor('e_nombre', d.nombre);
            valor('e_correo', d.correo);
            valor('e_cedula', d.cedula);
            valor('e_telefono', d.telefono);
            valor('e_rif', d.rif);
            valor('e_fnac', d.fecha_nacimiento);
            valor('e_placa', d.placa);
            valor('e_licencia', d.licencia);
            valor('e_cert', d.certificado_medico);
            valor('e_banco', d.banco_pago);
            valor('e_telf', d.telefono_pago);
            valor('e_cedp', d.cedula_pago);
            valor('e_ajuste', 0);
            valor('e_password', '');
            valor('e_registro', d.fecha_registro || d.creado_en || '—');

            document.getElementById('e_vehiculo').value = d.tipo_vehiculo || 'moto';
            document.getElementById('e_cat').value = d.categoria || 'Sencillo';
            document.getElementById('e_antecedentes').value = d.antecedentes_penales || 'No';
            document.getElementById('e_otraapp').value = d.otra_app || 'No';
            document.getElementById('e_verificacion').value = d.estado_documentos || 'Pendiente';
            document.getElementById('e_enlinea').checked = String(d.en_linea) === '1';
            document.getElementById('e_saldo_actual').innerText = "$" + Number(d.billetera || 0).toFixed(2);

            const enViaje = d.estatus === 'en_viaje';
            document.getElementById('e_est').value = enViaje ? 'activo' : (d.estatus || 'activo');
            document.getElementById('e_est_hint').innerText = enViaje
                ? 'Ahora mismo está EN VIAJE. Si lo dejas en Activo, seguirá su viaje con normalidad.'
                : '';

            let html = '';
            for (const [campo, etiqueta] of Object.entries(DOCUMENTOS)) {
                const ruta = d[campo];
                const enlace = urlArchivo(ruta)
                    ? `<a href="${escaparHtml(urlArchivo(ruta))}" target="_blank" rel="noopener">Ver documento actual</a>`
                    : `<span class="doc-falta">Sin cargar</span>`;
                html += `<div class="doc-item">
                            <strong>${escaparHtml(etiqueta)}</strong>
                            ${enlace}
                            <input type="file" name="${escaparHtml(campo)}" accept="image/jpeg,image/png,image/webp">
                         </div>`;
            }
            document.getElementById('e_documentos').innerHTML = html;

            document.getElementById('modal-editar').style.display = 'flex';
        }

        function cerrarEditar() { document.getElementById('modal-editar').style.display = 'none'; }

        function numeroWhatsapp(telefono) {
            let digitos = String(telefono || '').replace(/\D+/g, '');
            if (!digitos) return '';
            if (digitos.startsWith('58')) return digitos;
            return '58' + digitos.replace(/^0+/, '');
        }

        function whatsappTexto(telefono, mensaje) {
            const numero = numeroWhatsapp(telefono);
            if (!numero) { alert("Este conductor no tiene un teléfono válido registrado."); return; }
            window.open(`https://wa.me/${numero}?text=${encodeURIComponent(mensaje)}`, '_blank');
        }

        function instruccionesApk() {
            return "⚠️ La app todavía *no está en Google Play*. Al descargar, el teléfono puede avisar que el archivo es peligroso. Es normal. Haz esto:\n\n"
                + "1. Entra a " + YORA_ANDROID + " y pulsa *Descargar App*.\n"
                + "2. Si Chrome dice que es dañino, pulsa «Descargar de todos modos».\n"
                + "3. Abre el archivo descargado.\n"
                + "4. Si dice que no se pueden instalar apps desconocidas, pulsa *Ajustes* y activa «Permitir desde esta fuente».\n"
                + "5. Vuelve e *Instalar*.\n"
                + "6. Si Play Protect avisa, pulsa «Más información» y luego «Instalar de todos modos».\n";
        }

        function mensajeAcceso(nombre, cedula, clave) {
            let texto = `🎉 ¡Hola *${nombre}*! Te escribimos de *Yora Delivery*.\n\n`
                + `Tu perfil ya está *aprobado y activo*. 🛵\n\n`
                + `📱 Descarga la App aquí:\n${YORA_ANDROID}\n\n`;
            if (cedula) {
                texto += `👤 *Usuario (cédula):* ${cedula}\n`;
            }
            if (clave) {
                texto += `🔑 *Contraseña:* ${clave}\n`;
            }
            texto += `\n` + instruccionesApk()
                + `\n¡Únete al canal informativo para Drivers para mantenerte informado de las actualizaciones!\n`
                + `${YORA_CANAL_DRIVERS}\n`
                + `\nSoporte: ${YORA_WA_SOPORTE_TXT}\nhttps://wa.me/${YORA_WA_SOPORTE}\n\n¡Buenos viajes! 🛵`;
            return texto;
        }

        function whatsappBienvenida(id) {
            const d = DRIVERS[id];
            if (!d) return;
            whatsappTexto(d.telefono, mensajeAcceso(d.nombre, d.cedula, ''));
        }

        function whatsappPostulante(id) {
            const p = POSTULANTES[id];
            if (!p) return;
            whatsappTexto(p.telefono, `¡Hola *${p.nombre}*! Te escribimos de *Yora Delivery* para revisar tu postulación como conductor. 🛵\n\nSoporte: ${YORA_WA_SOPORTE_TXT}`);
        }

        function enviarWhatsappPendiente() {
            if (!WA_PENDIENTE) return;
            let mensaje;
            if (WA_PENDIENTE.tipo === 'rechazo') {
                mensaje = `Hola *${WA_PENDIENTE.nombre}*. Te contactamos de *Yora Delivery*.\n\n`
                    + `Revisamos tu solicitud y por ahora no podemos aprobarla debido a: *${WA_PENDIENTE.motivo}*.\n\n`
                    + `Puedes corregirlo y volver a postularte cuando quieras.\n\nSoporte: ${YORA_WA_SOPORTE_TXT}`;
            } else {
                mensaje = mensajeAcceso(WA_PENDIENTE.nombre, WA_PENDIENTE.cedula || '', WA_PENDIENTE.clave || '');
            }
            whatsappTexto(WA_PENDIENTE.telefono, mensaje);
        }

        function procesarRechazo(id) {
            let motivo = prompt("Indica el motivo del rechazo para notificarle:");
            if (motivo) {
                document.getElementById('motivo-' + id).value = motivo;
                document.getElementById('form-rechazo-' + id).submit();
            }
        }

        if (WA_PENDIENTE) { window.scrollTo({ top: 0 }); }
    </script>
</body>
</html>