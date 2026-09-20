<?php

require_once 'conexion.php';
yora_require_admin_pagina('restaurantes.php');
yora_timezone($conexion);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

$mensaje = '';
$wa_pendiente = null;

// Toda accion POST del panel exige token CSRF u origen propio.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !yora_verify_same_origin() && !yora_csrf_ok()) {
    http_response_code(403);
    exit('Solicitud rechazada.');
}

// ========================================================
// APROBACIÓN RÁPIDA DE VERIFICACIÓN DE CUENTA
// ========================================================
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar_doc'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: restaurantes.php'); exit; }
    $id_doc = (int) $_POST['aprobar_doc'];
    yora_exec($conexion, "UPDATE restaurantes SET estado_documentos = 'Verificado' WHERE id = ?", 'i', $id_doc);
    header("Location: restaurantes.php?aprobado=1&tab=verificaciones");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rechazar_doc'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: restaurantes.php'); exit; }
    $id_doc = (int) $_POST['rechazar_doc'];
    yora_exec($conexion, "UPDATE restaurantes SET estado_documentos = 'Rechazado' WHERE id = ?", 'i', $id_doc);
    header("Location: restaurantes.php?doc_rechazado=1&tab=verificaciones");
    exit;
}

if(isset($_GET['aprobado'])) {
    $mensaje = "<div class='alert success'>✅ ¡Comercio verificado exitosamente! Su insignia cambió a Verificado.</div>";
}
if (isset($_GET['doc_rechazado'])) {
    $mensaje = "<div class='alert success'>Documento rechazado. El comercio puede subir uno nuevo desde su panel.</div>";
}

// ========================================================
// 1. REGISTRAR NUEVO COMERCIO
// ========================================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_restaurante'])) {
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $rif = trim((string) ($_POST['rif'] ?? ''));
    $razon_social = trim((string) ($_POST['razon_social'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $direccion = trim((string) ($_POST['direccion'] ?? ''));
    $lat = floatval($_POST['latitud']);
    $lng = floatval($_POST['longitud']);
    $tipo_comercio = yora_tipo_comercio_clave((string) ($_POST['tipo_comercio'] ?? 'Pequeño'));
    
    $password_plana = yora_clave_aleatoria();
    $hash = password_hash($password_plana, PASSWORD_DEFAULT);

    $logo_url = 'https://cdn-icons-png.flaticon.com/512/819/819814.png';
    $aviso_logo = '';

    if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $logo = yora_guardar_logo_comercio($_FILES['logo']);
        $logo_url = $logo['url'];
        if (!$logo['ok']) {
            $aviso_logo = ' <b>Aviso:</b> ' . yora_h($logo['error'] !== '' ? $logo['error'] : 'el logo no se pudo guardar y quedó el ícono por defecto.');
        }
    }

    $check = $telefono === '' ? true : (bool) yora_one($conexion, 'SELECT id FROM restaurantes WHERE telefono = ?', 's', $telefono);
    if($check) {
        if ($mensaje === '') { $mensaje = "<div class='alert error'>Ese número de teléfono ya está registrado.</div>"; }
    } else {
        $sql = "INSERT INTO restaurantes (nombre, rif, razon_social, direccion, latitud, longitud, lat, lng, telefono, correo, password, billetera, logo_url, tipo_comercio, estado_documentos, estatus)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pendiente','activo')";

        $alta_ok = true;
        try {
            yora_exec($conexion, $sql, 'ssssddddsssdss', $nombre, $rif, $razon_social, $direccion, $lat, $lng, $lat, $lng, $telefono, $correo, $hash, $saldo, $logo_url, $tipo_comercio);
        } catch (Throwable $e) { $alta_ok = false; }

        if($alta_ok) {
            $mensaje = "<div class='alert success'>¡Comercio registrado con éxito!" . $aviso_logo . "</div>";

            if(!empty($correo)) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $GLOBALS['YORA_SMTP_HOST'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $GLOBALS['YORA_SMTP_USER']; 
                    $mail->Password   = $GLOBALS['YORA_SMTP_PASS'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = $GLOBALS['YORA_SMTP_PORT'];

                    $mail->setFrom('yoradelivery@gmail.com', 'Yora Delivery B2B');
                    $mail->addAddress($correo, $nombre);
                    $mail->CharSet = 'UTF-8';

                    $mail->isHTML(true);
                    $mail->Subject = 'Bienvenido a Yora Delivery B2B | Credenciales de Acceso';
                    
                    $mail->Body = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    </head>
                    <body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #f1f5f9; padding: 30px 10px;">
                            <tr>
                                <td align="center">
                                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.06);">
                                        <tr>
                                            <td align="center" style="background: linear-gradient(135deg, #ce4e2d 0%, #b83d1e 100%); padding: 40px 20px;">
                                                <img src="https://yoradelivery.com/uploads/logo_1788139976.png" alt="Yora Delivery" style="max-width: 170px; height: auto; display: block; border: 0;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 40px 35px 30px; text-align: center;">
                                                <h1 style="color: #0f172a; font-size: 24px; font-weight: 800; margin: 0 0 12px; letter-spacing: -0.5px;">¡Bienvenido a la red, ' . htmlspecialchars($nombre) . '!</h1>
                                                <p style="color: #64748b; font-size: 15px; line-height: 1.6; margin: 0 0 28px;">
                                                    Tu cuenta comercial ha sido activada exitosamente. A partir de este momento puedes solicitar entregas express, asignar rutas y gestionar tu logística en tiempo real.
                                                </p>
                                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; margin-bottom: 30px; text-align: left;">
                                                    <tr>
                                                        <td style="padding: 22px 25px;">
                                                            <div style="font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 800; letter-spacing: 1px; margin-bottom: 12px;">Tus Credenciales de Entrada</div>
                                                            <div style="font-size: 15px; color: #1e293b; margin-bottom: 10px;">
                                                                <strong style="color: #475569;">Usuario:</strong> <span style="font-family: monospace; font-size: 16px; font-weight: 700; color: #0f172a;">' . htmlspecialchars($telefono) . '</span>
                                                            </div>
                                                            <div style="font-size: 15px; color: #1e293b;">
                                                                <strong style="color: #475569;">Contraseña:</strong> <span style="font-family: monospace; font-size: 16px; font-weight: 700; color: #ce4e2d;">' . htmlspecialchars($password_plana) . '</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                    <tr>
                                                        <td align="center">
                                                            <a href="https://comercios.yoradelivery.com" target="_blank" style="display: inline-block; background-color: #ce4e2d; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 16px 36px; border-radius: 12px; box-shadow: 0 6px 20px rgba(206, 78, 45, 0.35);">
                                                                Ingresar al Panel de Comercio &rarr;
                                                            </a>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0 35px;">
                                                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 0;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 30px 35px 35px; text-align: center;">
                                                <p style="color: #94a3b8; font-size: 12px; line-height: 1.5; margin: 0 0 10px;">
                                                    Este correo fue enviado de forma automática por la infraestructura de Yora Delivery.
                                                </p>
                                                <p style="color: #64748b; font-size: 12px; margin: 0;">
                                                    ¿Necesitas soporte operativo? Escríbenos directamente a <a href="https://wa.me/584225097031" style="color: #ce4e2d; text-decoration: none; font-weight: 700;">WhatsApp Business</a>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </body>
                    </html>';

                    $mail->send();
                    $mensaje .= "<div class='alert success' style='margin-top:-10px;'>📧 Correo de bienvenida enviado a " . htmlspecialchars($correo) . "</div>";
                    $wa_pendiente = [
                        'tipo' => 'acceso',
                        'nombre' => $nombre,
                        'telefono' => $telefono,
                        'clave' => $password_plana,
                    ];
                } catch (Exception $e) {
                    error_log('YORA mail comercio: ' . $mail->ErrorInfo);
                    $mensaje .= "<div class='alert error' style='margin-top:-10px;'>Comercio creado, pero falló el envío del correo de bienvenida.</div>";
                }
            }

        } else {
            $mensaje = "<div class='alert error'>No se pudo registrar el comercio. Revisa que el RIF y el teléfono no estén duplicados.</div>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar_comercio'])) {
    $id = (int) ($_POST['id_comercio'] ?? 0);
    $info = yora_one($conexion, 'SELECT nombre, correo, telefono FROM restaurantes WHERE id = ?', 'i', $id);
    if ($info) {
        $clave = yora_clave_aleatoria();
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        yora_exec($conexion, "UPDATE restaurantes SET estatus = 'activo', password = ? WHERE id = ?", 'si', $hash, $id);
        $nombre = trim((string) $info['nombre']);
        $correo = trim((string) $info['correo']);
        $telefono = trim((string) $info['telefono']);
        $mensaje = "<div class='alert success'>Comercio <b>" . yora_h($nombre) . "</b> aprobado. Clave generada.</div>";
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $cuerpo = '<p>Tu local <b>' . yora_h($nombre) . '</b> ya forma parte de Yora Delivery.</p>
                <p>Entra al panel con tu <b>teléfono</b> y esta clave:</p>
                <p>Usuario: <b>' . yora_h($telefono) . '</b><br>Contraseña: <b>' . yora_h($clave) . '</b></p>
                <p>Panel: <a href="https://comercios.yoradelivery.com">comercios.yoradelivery.com</a></p>';
            $html = yora_plantilla_correo('¡Bienvenido a Yora, ' . $nombre . '!', $cuerpo, '#ce4e2d', 'Entrar al panel', 'https://comercios.yoradelivery.com');
            $err = null;
            if (yora_enviar_correo($correo, $nombre, 'Tu acceso al panel Yora Comercios', $html, $err)) {
                $mensaje .= "<div class='alert success'>Correo enviado a " . yora_h($correo) . ".</div>";
            } else {
                $mensaje .= "<div class='alert error'>El correo no salió (" . yora_h((string) $err) . ").</div>";
            }
        }
        $wa_pendiente = ['tipo' => 'acceso', 'nombre' => $nombre, 'telefono' => $telefono, 'clave' => $clave];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rechazar_comercio'])) {
    $id = (int) ($_POST['id_comercio'] ?? 0);
    $motivo = trim((string) ($_POST['motivo_rechazo'] ?? ''));
    $info = yora_one($conexion, 'SELECT nombre, correo, telefono FROM restaurantes WHERE id = ?', 'i', $id);
    if ($info) {
        yora_exec($conexion, "UPDATE restaurantes SET estatus = 'rechazado' WHERE id = ?", 'i', $id);
        $mensaje = "<div class='alert error'>Comercio <b>" . yora_h($info['nombre']) . "</b> rechazado.</div>";
        if (!empty($info['correo']) && filter_var($info['correo'], FILTER_VALIDATE_EMAIL)) {
            $cuerpo = '<p>Hola ' . yora_h($info['nombre']) . ', revisamos tu solicitud y por ahora no podemos afiliar el local.</p>
                <blockquote style="background:#f8fafc;padding:12px;border-left:4px solid #dc2626;">' . yora_h($motivo) . '</blockquote>';
            $html = yora_plantilla_correo('Actualización de tu solicitud', $cuerpo, '#dc2626');
            $err = null;
            yora_enviar_correo((string) $info['correo'], (string) $info['nombre'], 'Actualización sobre tu solicitud | Yora', $html, $err);
        }
        $wa_pendiente = ['tipo' => 'rechazo', 'nombre' => $info['nombre'], 'telefono' => $info['telefono'], 'motivo' => $motivo];
    }
}

// ========================================================
// 2. ACTUALIZAR COMERCIO EXISTENTE (DATOS, GPS Y SALDO)
// ========================================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_restaurante'])) {
    $id_comercio = intval($_POST['id_comercio']);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $rif = trim((string) ($_POST['rif'] ?? ''));
    $razon_social = trim((string) ($_POST['razon_social'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));
    $direccion = trim((string) ($_POST['direccion'] ?? ''));
    $lat = floatval($_POST['latitud'] ?? 0);
    $lng = floatval($_POST['longitud'] ?? 0);
    $recarga = round(floatval($_POST['recarga'] ?? 0), 2);
    $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
    $categoria = trim((string) ($_POST['categoria'] ?? ''));
    $horario = trim((string) ($_POST['horario'] ?? ''));
    $instagram = trim((string) ($_POST['instagram'] ?? ''));
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $publico = isset($_POST['perfil_publico']) ? 1 : 0;
    $menu = isset($_POST['gestion_menu']) ? 1 : 0;
    $tipo_comercio = yora_tipo_comercio_clave((string) ($_POST['tipo_comercio'] ?? 'Pequeño'));
    $slug = function_exists('yora_slug_comercio')
        ? yora_slug_comercio($nombre, $id_comercio)
        : (strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nombre)) . '-' . $id_comercio);
    $aviso_logo = '';

    // Horas en local: no depende de una función nueva de seguridad.php.
    $abre_in = trim((string) ($_POST['hora_abre'] ?? ''));
    $cierra_in = trim((string) ($_POST['hora_cierra'] ?? ''));
    $abre = (preg_match('/^(\d{2}:\d{2})/', $abre_in, $m) ? $m[1] . ':00' : '');
    $cierra = (preg_match('/^(\d{2}:\d{2})/', $cierra_in, $m2) ? $m2[1] . ':00' : '');
    if ($horario === '' && $abre !== '' && $cierra !== '') {
        $horario = substr($abre, 0, 5) . ' a ' . substr($cierra, 0, 5);
    }

    try {
        // Mismo UPDATE que ya funcionaba; correo/horas van aparte para no tumbar la página.
        yora_exec(
            $conexion,
            'UPDATE restaurantes SET nombre = ?, rif = ?, razon_social = ?, telefono = ?, direccion = ?, latitud = ?, longitud = ?, lat = ?, lng = ?, billetera = billetera + ?, descripcion = ?, categoria = ?, horario = ?, instagram = ?, perfil_publico = ?, gestion_menu = ?, slug = ? WHERE id = ?',
            'sssssdddddssssiisi',
            $nombre, $rif, $razon_social, $telefono, $direccion, $lat, $lng, $lat, $lng, $recarga,
            $descripcion, $categoria, $horario, $instagram, $publico, $menu, $slug, $id_comercio
        );

        try {
            yora_exec(
                $conexion,
                'UPDATE restaurantes SET correo = ?, hora_abre = NULLIF(?, \'\'), hora_cierra = NULLIF(?, \'\') WHERE id = ?',
                'sssi',
                $correo,
                $abre,
                $cierra,
                $id_comercio
            );
        } catch (Throwable $e2) {
            error_log('restaurantes extra: ' . $e2->getMessage());
        }

        try {
            yora_exec($conexion, 'UPDATE restaurantes SET tipo_comercio = ? WHERE id = ?', 'si', $tipo_comercio, $id_comercio);
        } catch (Throwable $eTipo) {
            error_log('restaurantes tipo: ' . $eTipo->getMessage());
        }

        $logo_err = (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($logo_err === UPLOAD_ERR_OK && (int) ($_FILES['logo']['size'] ?? 0) > 5 * 1024 * 1024) {
            $aviso_logo = ' El logo pesa más de 5 MB. Comprime la imagen e inténtalo de nuevo.';
        } elseif ($logo_err === UPLOAD_ERR_OK && isset($_FILES['logo'])) {
            try {
                $logo = yora_guardar_logo_comercio($_FILES['logo']);
                if (!empty($logo['ok'])) {
                    yora_exec($conexion, 'UPDATE restaurantes SET logo_url = ? WHERE id = ?', 'si', $logo['url'], $id_comercio);
                } else {
                    $aviso_logo = ' ' . yora_h((string) ($logo['error'] ?? 'El logo no se pudo guardar.'));
                }
            } catch (Throwable $e3) {
                error_log('restaurantes logo: ' . $e3->getMessage());
                $aviso_logo = ' No se pudo guardar el logo. Usa un JPG o PNG de menos de 5 MB.';
            }
        } elseif (in_array($logo_err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $aviso_logo = ' El logo pesa más de lo que permite el servidor (máx. 5 MB).';
        }

        if(!empty($_POST['password'])) {
            if (strlen((string) $_POST['password']) < 8) {
                throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            }
            $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
            yora_exec($conexion, 'UPDATE restaurantes SET password = ? WHERE id = ?', 'si', $hash, $id_comercio);
        }

        $mensaje = "<div class='alert success'>¡Comercio actualizado correctamente!" . $aviso_logo . "</div>";
    } catch (Throwable $e) {
        error_log('restaurantes actualizar: ' . $e->getMessage());
        $mensaje = "<div class='alert error'>No se pudo actualizar el comercio. Revisa los datos e inténtalo de nuevo.</div>";
    }
}

$lista_restaurantes = $conexion->query("
    SELECT r.*, 
    (SELECT COUNT(id) FROM comandas c WHERE c.restaurante_id = r.id AND c.estatus = 'Entregado') AS entregas_totales,
    (SELECT COUNT(id) FROM comandas c WHERE c.restaurante_id = r.id AND c.estatus = 'Entregado' AND COALESCE(c.fecha_entrega, c.fecha_creacion) >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS club_mes
    FROM restaurantes r ORDER BY r.id DESC
");

function yora_hq_json_comercio(array $row): string
{
    unset($row['password']);
    $row['documento_url'] = yora_url_archivo($row['documento_url'] ?? '', '', 'https://comercios.yoradelivery.com');
    $row['logo_url'] = yora_url_archivo($row['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
    return htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
}

if (!function_exists('yora_comercio_verificacion_pendiente')) {
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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>Yora Admin | Restaurantes</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    
    <style>
        :root {
            --yora-orange: #ce4e2d; --yora-orange-hover: #e65a36;
            --bg-body: #f4f6f8; --bg-card: #ffffff;
            --border-color: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280;
        }

        body { margin: 0; font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        .sidebar { width: 280px; min-width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; box-sizing: border-box; box-shadow: 2px 0 10px rgba(0,0,0,0.02); }
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(206,78,45,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item .icon { font-size: 1.1rem; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(206,78,45,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }
        .logout-btn:hover { background-color: #fee2e2; color: #ef4444; }

        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .header-title { margin-top: 0; font-size: 1.8rem; margin-bottom: 20px; font-weight: 800; letter-spacing: -0.5px; color: var(--text-main); }
        
        .grid-cards { display: grid; grid-template-columns: minmax(280px, 400px) minmax(0, 1fr); gap: 25px; align-items: start; }
        .card-dir { min-width: 0; }
        .hq-tabs { display:flex; gap:8px; flex-wrap:wrap; margin: 0 0 18px; }
        .hq-tab { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; font-family:inherit; color:#64748b; }
        .hq-tab.on { background:#fff7ed; color:#ce4e2d; border-color:#fdba74; }
        .hq-pane { display:none; }
        .hq-pane.on { display:block; }
        @media (max-width: 1100px) { .grid-cards { grid-template-columns: 1fr; } }
        
        .card { background-color: var(--bg-card); padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; color: var(--text-main); font-size: 1.2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; font-weight: 700; display:flex; align-items:center; gap: 8px;}
        .card h3::before { content: ""; display: block; width: 4px; height: 18px; background: var(--yora-orange); border-radius: 4px; }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.85rem; color: var(--text-main); font-weight: 600; }
        input[type="text"], textarea, input[type="number"], input[type="password"], input[type="tel"], input[type="email"], input[type="time"], input[type="file"], select {
            width: 100%; padding: 12px 14px; background-color: #f8fafc; border: 1px solid #d1d5db;
            color: var(--text-main); border-radius: 10px; box-sizing: border-box; font-family: 'Poppins', sans-serif; font-size: 0.95rem; transition: all 0.3s;
        }
        input:focus, textarea:focus { outline: none; border-color: var(--yora-orange); box-shadow: 0 0 0 3px rgba(206,78,45,0.15); background: #ffffff;}
        
        .btn-save { background-color: var(--yora-orange); color: white; border: none; padding: 14px 20px; font-size: 1rem; border-radius: 10px; cursor: pointer; font-weight: 700; width: 100%; transition: all 0.3s; box-shadow: 0 4px 15px rgba(206,78,45,0.2);}
        .btn-save:hover { background-color: var(--yora-orange-hover); transform: translateY(-2px); }

        #mapa-admin, #mapa-editar { height: 200px; width: 100%; border-radius: 10px; margin-bottom: 15px; z-index: 1; border: 1px solid #d1d5db;}

        .upload-box { border: 2px dashed #cbd5e1; padding: 20px; border-radius: 12px; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; }
        .upload-box:hover { border-color: var(--yora-orange); background: #fff5f3;}

        .btn-generar { background: #f1f5f9; border: 1px solid #cbd5e1; color: var(--text-main); font-weight: 600; font-size: 0.85rem; border-radius: 10px; padding: 0 15px; cursor: pointer; transition: 0.2s; white-space: nowrap;}
        .btn-generar:hover { background: #e2e8f0; border-color: #94a3b8; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 15px 12px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; }
        th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px;}
        .saldo-badge { background: rgba(206, 78, 45, 0.1); color: var(--yora-orange); padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;}
        
        .btn-action { background: #f3f4f6; border: 1px solid #d1d5db; color: #4b5563; padding: 6px 12px; border-radius: 8px; cursor: pointer; font-size: 0.8rem; margin-right: 5px; transition: 0.2s; font-weight:500;}
        .btn-action:hover { border-color: var(--text-muted); color: var(--text-main); background: #e5e7eb;}
        .btn-stats { border-color: rgba(206,78,45,0.3); color: var(--yora-orange); background: rgba(206,78,45,0.05); }
        .btn-stats:hover { background: var(--yora-orange); color: white; border-color: var(--yora-orange); }

        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; font-size: 0.9rem; }
        .alert.success { background: #dcfce7; border: 1px solid #22c55e; color: #166534; }
        .alert.error { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; }

        .modal-overlay { 
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(15,23,42,0.8); backdrop-filter: blur(4px); z-index: 2000; 
            justify-content: center; align-items: center; 
        }
        .modal-box { 
            background: var(--bg-card); padding: 35px; border-radius: 20px; 
            width: 100%; max-width: 580px; position: relative; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); max-height: 90vh; overflow-y: auto; 
        }
        .close-modal { position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: var(--text-muted); transition:0.2s;}
        .close-modal:hover { color: var(--yora-orange); }
        .info-caja { background: var(--bg-body); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 20px; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 class="header-title">Gestión de Comercios B2B</h1>
        <?php echo $mensaje; ?>
        <?php if ($wa_pendiente): ?>
        <div class="alert success" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <span>💬 ¿Le avisamos por WhatsApp a <?php echo yora_h($wa_pendiente['nombre']); ?> (<?php echo yora_h($wa_pendiente['telefono']); ?>)?</span>
            <button type="button" onclick="enviarWhatsappPendiente()" style="background:#25D366; color:#fff; border:0; border-radius:10px; padding:10px 16px; font-weight:800; cursor:pointer; font-family:inherit;">Abrir WhatsApp</button>
        </div>
        <?php endif; ?>

        <?php
            $pendientes = [];
            $activos = [];
            $docs_pendientes = [];
            if ($lista_restaurantes) {
                $lista_restaurantes->data_seek(0);
                while ($tmp = $lista_restaurantes->fetch_assoc()) {
                    $est = strtolower(trim((string) ($tmp['estatus'] ?? 'activo')));
                    if ($est === '' || $est === 'activo' || $est === 'inactivo') {
                        $activos[] = $tmp;
                    } elseif ($est === 'pendiente') {
                        $pendientes[] = $tmp;
                    }
                    if (yora_comercio_verificacion_pendiente($tmp)) {
                        $docs_pendientes[] = $tmp;
                    }
                }
            }
            $comercios_online = 0;
            $comercios_abiertos = 0;
            foreach ($activos as $tmp) {
                if (yora_comercio_online($tmp['ultima_conexion'] ?? null)) {
                    $comercios_online++;
                }
                if (yora_comercio_esta_abierto($tmp)) {
                    $comercios_abiertos++;
                }
            }
            $tab_get = (string) ($_GET['tab'] ?? '');
            $tab_on = 'directorio';
            if ($tab_get === 'verificaciones' || $tab_get === 'solicitudes' || $tab_get === 'directorio' || $tab_get === 'registro') {
                $tab_on = $tab_get;
            } elseif ($pendientes) {
                $tab_on = 'solicitudes';
            } elseif ($docs_pendientes) {
                $tab_on = 'verificaciones';
            }
        ?>

        <div class="hq-tabs">
            <button type="button" class="hq-tab <?php echo $tab_on === 'solicitudes' ? 'on' : ''; ?>" onclick="hqTab('solicitudes', this)">Solicitudes (<?php echo count($pendientes); ?>)</button>
            <button type="button" class="hq-tab <?php echo $tab_on === 'verificaciones' ? 'on' : ''; ?>" onclick="hqTab('verificaciones', this)">Verificaciones (<?php echo count($docs_pendientes); ?>)</button>
            <button type="button" class="hq-tab <?php echo $tab_on === 'directorio' ? 'on' : ''; ?>" onclick="hqTab('directorio', this)">Directorio activo (<?php echo count($activos); ?>)</button>
            <button type="button" class="hq-tab <?php echo $tab_on === 'registro' ? 'on' : ''; ?>" onclick="hqTab('registro', this)">Registro manual</button>
        </div>

        <div id="pane-solicitudes" class="hq-pane <?php echo $tab_on === 'solicitudes' ? 'on' : ''; ?>">
            <div class="card">
                <h3>Solicitudes de afiliación</h3>
                <?php if (!$pendientes): ?>
                    <p style="color:#94a3b8; font-weight:600;">No hay comercios esperando revisión.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th>Local</th><th>Contacto</th><th>Dirección</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendientes as $row):
                            $img = yora_url_archivo($row['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
                        ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <img src="<?php echo yora_h($img); ?>" style="width:44px;height:44px;border-radius:12px;object-fit:cover;border:1px solid #e2e8f0;">
                                        <div>
                                            <strong><?php echo yora_h($row['nombre']); ?></strong><br>
                                            <small style="color:#94a3b8;"><?php echo yora_h($row['rif']); ?> · <?php echo yora_h($row['categoria'] ?: 'Sin categoría'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo yora_h($row['telefono']); ?><br><small style="color:#94a3b8;"><?php echo yora_h($row['correo']); ?></small></td>
                                <td><?php echo yora_h($row['direccion']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;"><?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="id_comercio" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" name="aprobar_comercio" value="1" class="btn-action" style="background:#dcfce7;color:#166534;border-color:#bbf7d0;" onclick="return confirm('¿Aprobar y enviar la clave?')">Aprobar</button>
                                    </form>
                                    <form method="POST" id="form-rechazo-<?php echo (int) $row['id']; ?>" style="display:inline;"><?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="id_comercio" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="rechazar_comercio" value="1">
                                        <input type="hidden" name="motivo_rechazo" id="motivo-<?php echo (int) $row['id']; ?>">
                                        <button type="button" class="btn-action" style="background:#fee2e2;color:#b91c1c;" onclick="rechazarComercio(<?php echo (int) $row['id']; ?>)">Rechazar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="pane-verificaciones" class="hq-pane <?php echo $tab_on === 'verificaciones' ? 'on' : ''; ?>">
            <div class="card">
                <h3>Documentos de verificación</h3>
                <p style="color:#64748b; font-size:0.88rem; margin:0 0 14px;">RIF o cédula que el comercio envía desde Configuración de su panel. Ábrelo, aprueba o pide uno nuevo.</p>
                <?php if (!$docs_pendientes): ?>
                    <p style="color:#94a3b8; font-weight:600;">No hay documentos pendientes de revisión.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th>Comercio</th><th>Estado</th><th>Documento</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($docs_pendientes as $row):
                            $img = yora_url_archivo($row['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
                            $doc = yora_url_archivo($row['documento_url'] ?? '', '', 'https://comercios.yoradelivery.com');
                            $est_doc = trim((string) ($row['estado_documentos'] ?? 'Pendiente'));
                        ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <img src="<?php echo yora_h($img); ?>" alt="" style="width:44px;height:44px;border-radius:12px;object-fit:cover;border:1px solid #e2e8f0;">
                                        <div>
                                            <strong><?php echo yora_h($row['nombre']); ?></strong><br>
                                            <small style="color:#94a3b8;"><?php echo yora_h($row['rif']); ?> · <?php echo yora_h($row['telefono']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="font-weight:800; color:<?php echo ($est_doc === 'En Revisión' || $est_doc === 'En Revision') ? '#d97706' : '#dc2626'; ?>;"><?php echo yora_h($est_doc); ?></span></td>
                                <td>
                                    <?php if ($doc !== ''): ?>
                                        <a href="<?php echo yora_h($doc); ?>" target="_blank" rel="noopener" style="color:var(--yora-orange); font-weight:700; text-decoration:none;"><i class="ph ph-file-text"></i> Ver documento</a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">Sin archivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;"><?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="aprobar_doc" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" class="btn-action" style="background:#dcfce7;color:#166534;border-color:#bbf7d0;">Aprobar</button>
                                    </form>
                                    <form method="POST" style="display:inline;"><?php echo yora_csrf_field(); ?>
                                        <input type="hidden" name="rechazar_doc" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" class="btn-action" style="background:#fee2e2;color:#b91c1c;" onclick="return confirm('¿Rechazar este documento? El comercio podrá subir otro.');">Rechazar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="pane-directorio" class="hq-pane <?php echo $tab_on === 'directorio' ? 'on' : ''; ?>">
            <p style="margin:0 0 12px; font-weight:700;">
                <span style="background:#dcfce7; color:#166534; padding:5px 10px; border-radius:999px; font-size:0.82rem;">● <?php echo (int) $comercios_online; ?> conectados al panel</span>
                <span style="background:#eff6ff; color:#1d4ed8; padding:5px 10px; border-radius:999px; font-size:0.82rem; margin-left:6px;"><?php echo (int) $comercios_abiertos; ?> abiertos ahora</span>
            </p>
            <div class="card card-dir">
                <h3>Directorio Activo</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Logo / Comercio</th>
                                <th>Contacto</th>
                                <th>Panel</th>
                                <th>Local</th>
                                <th>Club (mes)</th>
                                <th>Billetera</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activos): ?>
                                <?php foreach ($activos as $row):
                                    $img = yora_url_archivo($row['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png', 'https://comercios.yoradelivery.com');
                                    $json_data = yora_hq_json_comercio($row);
                                    $est_doc = trim((string) ($row['estado_documentos'] ?? 'Pendiente'));
                                ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:15px;">
                                        <img src="<?php echo htmlspecialchars($img); ?>" onerror="this.onerror=null;this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png';" style="width:45px; height:45px; border-radius:12px; object-fit:cover; border:1px solid #e2e8f0;">
                                        <div>
                                            <strong style="color:var(--text-main); font-size:0.95rem;"><?php echo htmlspecialchars($row['nombre']); ?></strong>
                                            <?php if ($est_doc === 'En Revisión' || $est_doc === 'En Revision'): ?>
                                                <span style="display:inline-block; margin-left:6px; background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:999px; font-size:0.7rem; font-weight:800;">Doc. pendiente</span>
                                            <?php elseif ($est_doc === 'Verificado'): ?>
                                                <span style="display:inline-block; margin-left:6px; background:#dcfce7; color:#166534; padding:2px 8px; border-radius:999px; font-size:0.7rem; font-weight:800;">Verificado</span>
                                            <?php endif; ?>
                                            <br>
                                            <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($row['rif']); ?> · <?php echo yora_h(yora_tipo_comercio_clave($row['tipo_comercio'] ?? 'Pequeño')); ?></span>
                                        </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['telefono']); ?></td>
                                    <td><?php if (yora_comercio_online($row['ultima_conexion'] ?? null)): ?>
                                        <span style="color:#16a34a; font-weight:800; font-size:0.8rem;">● En el panel</span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:0.8rem;">Desconectado</span>
                                    <?php endif; ?></td>
                                    <td><?php if (yora_comercio_esta_abierto($row)): ?>
                                        <span style="color:#16a34a; font-weight:800; font-size:0.8rem;">Abierto</span>
                                    <?php else: ?>
                                        <span style="color:#dc2626; font-weight:800; font-size:0.8rem;">Cerrado</span>
                                    <?php endif; ?>
                                    <br><small style="color:#94a3b8;"><?php
                                        $ha = substr((string) ($row['hora_abre'] ?? ''), 0, 5);
                                        $hc = substr((string) ($row['hora_cierra'] ?? ''), 0, 5);
                                        echo ($ha && $hc) ? yora_h($ha . '–' . $hc) : 'Sin horario';
                                    ?></small></td>
                                    <td><span class="saldo-badge"><?php echo (int) ($row['club_mes'] ?? 0); ?> entregados</span></td>
                                    <td><span class="saldo-badge">$<?php echo number_format($row['billetera'], 2); ?></span></td>
                                    <td>
                                        <button class="btn-action" onclick="abrirModalEditar(<?php echo $json_data; ?>)">Editar</button>
                                        <button class="btn-action btn-stats" onclick="abrirModalMetricas(<?php echo $json_data; ?>)">Métricas</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center; color:#888; padding:30px;">No hay comercios activos aún.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="pane-registro" class="hq-pane <?php echo $tab_on === 'registro' ? 'on' : ''; ?>">
            <div class="card" style="max-width:560px;">
                <h3>Nuevo Comercio</h3>
                <form method="POST" enctype="multipart/form-data"><?php echo yora_csrf_field(); ?>
                    <input type="hidden" name="agregar_restaurante" value="1">
                    
                    <div class="form-group">
                        <label>Logo del Comercio (Recomendado)</label>
                        <div class="upload-box" onclick="document.getElementById('c_logo').click()">
                            <i class="ph ph-camera" style="font-size: 2rem; color: #94a3b8;"></i>
                            <p style="margin: 5px 0 0; font-size: 0.85rem; color: var(--text-muted); font-weight:500;">Haz clic para subir imagen</p>
                            <input type="file" id="c_logo" name="logo" accept="image/png, image/jpeg, image/webp" style="display:none;" onchange="previewLogo(this)">
                            <img id="logo-preview" src="" style="display:none; width: 80px; height: 80px; object-fit: cover; margin: 15px auto 0; border-radius: 12px; border: 2px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        </div>
                    </div>

                    <div class="form-group"><label>Nombre Comercial</label><input type="text" name="nombre" required placeholder="Ej: Pizzería La Nonna"></div>
                    <div class="form-group">
                        <label>Tipo de local (recarga mínima)</label>
                        <select name="tipo_comercio">
                            <?php foreach (yora_tipos_comercio() as $t): ?>
                                <option value="<?php echo yora_h($t['clave']); ?>"><?php echo yora_h($t['etiqueta']); ?> · mín. $<?php echo number_format($t['min_recarga'], 0); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex: 1;"><label>RIF</label><input type="text" name="rif" required placeholder="J-12345678"></div>
                        <div class="form-group" style="flex: 2;"><label>Razón Social</label><input type="text" name="razon_social" required placeholder="Inversiones C.A."></div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex: 1;">
                            <label>Teléfono (Usuario)</label>
                            <input type="tel" name="telefono" required placeholder="04141234567">
                        </div>
                        <div class="form-group" style="flex: 2;">
                            <label>Correo Electrónico</label>
                            <input type="email" name="correo" required placeholder="ejemplo@correo.com">
                        </div>
                    </div>
                    
                    <p style="color:#64748b; font-size:0.82rem; margin:0 0 16px;">La clave se genera sola. Al registrar se envía por correo y puedes abrir WhatsApp para avisarle.</p>
                    
                    <div class="form-group">
                        <label>Ubicación GPS (Haz clic en el mapa para colocar el pin)</label>
                        <div id="mapa-admin"></div>
                        <input type="hidden" id="lat" name="latitud" value="10.0645">
                        <input type="hidden" id="lng" name="longitud" value="-69.3569">
                    </div>
                    
                    <div class="form-group"><label>Dirección Escrita</label><input type="text" name="direccion" required placeholder="Centro de Barquisimeto..."></div>
                    <div class="form-group"><label>Saldo Inicial (Recarga) $</label><input type="number" step="0.01" name="saldo" value="0.00" required></div>
                    
                    <button type="submit" class="btn-save">Registrar Comercio</button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR COMPLETO -->
    <div id="modalEditar" class="modal-overlay">
        <div class="modal-box">
            <span class="close-modal" onclick="cerrarModales()">&times;</span>
            <h3 style="color:var(--text-main); margin-top:0; border-bottom:1px solid #e2e8f0; padding-bottom:15px; margin-bottom:20px;">Gestionar y Editar Comercio</h3>
            <form method="POST" enctype="multipart/form-data"><?php echo yora_csrf_field(); ?>
                <input type="hidden" name="actualizar_restaurante" value="1">
                <input type="hidden" id="edit_id" name="id_comercio">

                <div class="form-group">
                    <label>Logo de perfil</label>
                    <div style="display:flex; gap:14px; align-items:center;">
                        <img id="edit_logo_preview" src="https://cdn-icons-png.flaticon.com/512/819/819814.png" alt="Logo" style="width:72px; height:72px; border-radius:14px; object-fit:cover; border:1px solid #e2e8f0; background:#f8fafc;">
                        <div style="flex:1;">
                            <input type="file" name="logo" id="edit_logo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" onchange="previewLogoEditar(this)">
                            <small style="color:#64748b; display:block; margin-top:6px;">JPG, PNG o WEBP. Máximo 5 MB. Déjalo vacío si no quieres cambiarlo.</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group"><label>Nombre Comercial</label><input type="text" id="edit_nombre" name="nombre" required></div>
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;"><label>RIF</label><input type="text" id="edit_rif" name="rif" required></div>
                    <div class="form-group" style="flex: 2;"><label>Razón Social</label><input type="text" id="edit_razon" name="razon_social" required></div>
                </div>
                <div class="form-group"><label>Teléfono (Usuario Acceso)</label><input type="tel" id="edit_telefono" name="telefono" required></div>
                <div class="form-group">
                    <label>Tipo de local (recarga mínima)</label>
                    <select id="edit_tipo" name="tipo_comercio">
                        <?php foreach (yora_tipos_comercio() as $t): ?>
                            <option value="<?php echo yora_h($t['clave']); ?>"><?php echo yora_h($t['etiqueta']); ?> · mín. $<?php echo number_format($t['min_recarga'], 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#64748b; display:block; margin-top:6px;">Club Yora (Bronce/Plata/Oro) se calcula por pedidos del mes. Esto es el tamaño del local.</small>
                </div>
                <div class="form-group"><label>Correo Electrónico</label><input type="email" id="edit_correo" name="correo" placeholder="correo@local.com"></div>
                <div class="form-group"><label>Dirección Escrita</label><input type="text" id="edit_direccion" name="direccion" required></div>
                <div class="form-group"><label>Qué vende (directorio público)</label><input type="text" id="edit_categoria" name="categoria" placeholder="Pizzería, farmacia, bodegón..."></div>
                <div class="form-group"><label>Descripción pública</label><textarea id="edit_descripcion" name="descripcion" rows="2" placeholder="Lo que verá el cliente en yoradelivery.com"></textarea></div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;"><label>Horario (texto)</label><input type="text" id="edit_horario" name="horario" placeholder="Lun-Sáb 8am-8pm"></div>
                    <div class="form-group" style="flex:1;"><label>Instagram</label><input type="text" id="edit_instagram" name="instagram" placeholder="@local"></div>
                </div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;"><label>Abre</label><input type="time" id="edit_abre" name="hora_abre"></div>
                    <div class="form-group" style="flex:1;"><label>Cierra</label><input type="time" id="edit_cierra" name="hora_cierra"></div>
                </div>
                <p style="color:#64748b; font-size:0.8rem; margin:-8px 0 16px;">Estas horas cierran el local en el panel del comercio (hora Venezuela). Si las dejas vacías, aparece abierto todo el día.</p>
                <label style="display:flex; align-items:center; gap:8px; margin-bottom:16px; font-weight:600;">
                    <input type="checkbox" id="edit_publico" name="perfil_publico" value="1"> Mostrar en el directorio de yoradelivery.com
                </label>
                <label style="display:flex; align-items:center; gap:8px; margin-bottom:16px; font-weight:600;">
                    <input type="checkbox" id="edit_menu" name="gestion_menu" value="1"> Permitir que el comercio gestione su menú
                </label>

                <div class="form-group">
                    <label>📍 Ubicación GPS del Local (Haz clic para mover)</label>
                    <div id="mapa-editar"></div>
                    <input type="hidden" id="edit_lat" name="latitud">
                    <input type="hidden" id="edit_lng" name="longitud">
                </div>
                
                <div class="form-group info-caja" style="background: #fff7ed; border-color: #ffedd5;">
                    <label style="color:var(--yora-orange); font-weight:bold;">Ajustar Saldo de Billetera ($)</label>
                    <small style="color:var(--text-muted); display:block; margin-bottom:10px;">Escribe un número positivo (ej: 20) para sumar saldo, o negativo (ej: -5) para restar al saldo actual.</small>
                    <input type="number" step="0.01" name="recarga" value="0.00" required style="border-color: #fdba74;">
                </div>

                <div class="form-group"><label>Nueva Contraseña (Opcional)</label><input type="password" name="password" placeholder="Déjalo en blanco si no deseas cambiarla"></div>

                <button type="submit" class="btn-save">Guardar Todos los Cambios</button>
            </form>
        </div>
    </div>

    <!-- MODAL MÉTRICAS Y AUDITORÍA DE VERIFICACIÓN -->
    <div id="modalMetricas" class="modal-overlay">
        <div class="modal-box">
            <span class="close-modal" onclick="cerrarModales()">&times;</span>
            <h3 style="color:var(--text-main); margin-top:0; border-bottom:1px solid #e2e8f0; padding-bottom:15px; margin-bottom:20px;" id="m_nombre">Estadísticas</h3>
            <div class="info-caja">
                <p style="margin:8px 0; font-size:0.95rem;"><strong>RIF:</strong> <span id="m_rif" style="color:var(--text-muted);"></span></p>
                <p style="margin:8px 0; font-size:0.95rem;"><strong>Razón Social:</strong> <span id="m_razon" style="color:var(--text-muted);"></span></p>
                <p style="margin:8px 0; font-size:0.95rem;"><strong>Dirección:</strong> <span id="m_dir" style="color:var(--text-muted);"></span></p>
                <p style="margin:8px 0; font-size:0.95rem;"><strong>Estado de Cuenta:</strong> <span id="m_estado" style="font-weight:700;"></span></p>
                
                <!-- ÁREA DE AUDITORÍA DE DOCUMENTO -->
                <div id="m_doc_container" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-top:12px;"></div>

                <hr style="border:0; border-top:1px dashed var(--border-color); margin:15px 0;">
                <p style="margin:5px 0; font-size:0.95rem; display:flex; justify-content:space-between; align-items:center;">
                    <strong>Total de Envíos:</strong> 
                    <span id="m_viajes" style="color:var(--yora-orange); font-weight:800; font-size:1.5rem;"></span>
                </p>
            </div>
            <button onclick="cerrarModales()" class="btn-save" style="background:#4b5563; box-shadow:none;">Cerrar Ventana</button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const WA_PENDIENTE = <?php echo json_encode($wa_pendiente, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function hqTab(id, btn) {
            document.querySelectorAll('.hq-tab').forEach(function (t) { t.classList.remove('on'); });
            document.querySelectorAll('.hq-pane').forEach(function (p) { p.classList.remove('on'); });
            btn.classList.add('on');
            document.getElementById('pane-' + id).classList.add('on');
            if (id === 'registro' && typeof map !== 'undefined') {
                setTimeout(function () { map.invalidateSize(); }, 180);
            }
        }
        function rechazarComercio(id) {
            var motivo = prompt('Indica el motivo del rechazo para notificarle:');
            if (!motivo) return;
            document.getElementById('motivo-' + id).value = motivo;
            document.getElementById('form-rechazo-' + id).submit();
        }
        function numeroWhatsapp(telefono) {
            var digitos = String(telefono || '').replace(/\D+/g, '');
            if (!digitos) return '';
            if (digitos.startsWith('58')) return digitos;
            return '58' + digitos.replace(/^0+/, '');
        }
        function enviarWhatsappPendiente() {
            if (!WA_PENDIENTE) return;
            var numero = numeroWhatsapp(WA_PENDIENTE.telefono);
            if (!numero) { alert('No hay un teléfono válido.'); return; }
            var mensaje;
            if (WA_PENDIENTE.tipo === 'rechazo') {
                mensaje = 'Hola *' + WA_PENDIENTE.nombre + '*. Te contactamos de *Yora Delivery*.\n\nRevisamos tu solicitud y por ahora no podemos afiliar el local debido a: *' + (WA_PENDIENTE.motivo || '') + '*.\n\nSoporte: +58 422-5097031';
            } else {
                mensaje = '🎉 ¡Hola *' + WA_PENDIENTE.nombre + '*! Te escribimos de *Yora Delivery*.\n\nTu local ya está *aprobado y activo*.\n\n🖥️ Panel: https://comercios.yoradelivery.com\n📱 *Usuario (teléfono):* ' + (WA_PENDIENTE.telefono || '') + '\n🔑 *Contraseña:* ' + (WA_PENDIENTE.clave || '') + '\n\nSoporte: +58 422-5097031\nhttps://wa.me/584225097031';
            }
            window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(mensaje), '_blank');
        }

        function previewLogo(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    let imgPreview = document.getElementById('logo-preview');
                    imgPreview.src = e.target.result;
                    imgPreview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function generarPassword() {
            const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
            let pass = "";
            for(let i=0; i<8; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
            document.getElementById('c_password').value = pass;
        }

        // MAPA NUEVO COMERCIO
        const map = L.map('mapa-admin').setView([10.0645, -69.3569], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        const marker = L.marker([10.0645, -69.3569], {draggable: true}).addTo(map);
        
        function updateLocation(lat, lng) {
            document.getElementById('lat').value = lat.toFixed(6);
            document.getElementById('lng').value = lng.toFixed(6);
        }

        marker.on('dragend', function(e) {
            let pos = marker.getLatLng();
            updateLocation(pos.lat, pos.lng);
        });

        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            updateLocation(e.latlng.lat, e.latlng.lng);
        });

        // MAPA EDITAR COMERCIO
        let mapEdit = null;
        let markerEdit = null;

        function inicializarMapaEditar(lat, lng) {
            if (!mapEdit) {
                mapEdit = L.map('mapa-editar').setView([lat, lng], 14);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapEdit);
                markerEdit = L.marker([lat, lng], {draggable: true}).addTo(mapEdit);

                markerEdit.on('dragend', function() {
                    let pos = markerEdit.getLatLng();
                    document.getElementById('edit_lat').value = pos.lat.toFixed(6);
                    document.getElementById('edit_lng').value = pos.lng.toFixed(6);
                });

                mapEdit.on('click', function(e) {
                    markerEdit.setLatLng(e.latlng);
                    document.getElementById('edit_lat').value = e.latlng.lat.toFixed(6);
                    document.getElementById('edit_lng').value = e.latlng.lng.toFixed(6);
                });
            } else {
                mapEdit.setView([lat, lng], 14);
                markerEdit.setLatLng([lat, lng]);
            }
            document.getElementById('edit_lat').value = parseFloat(lat).toFixed(6);
            document.getElementById('edit_lng').value = parseFloat(lng).toFixed(6);
            setTimeout(() => { mapEdit.invalidateSize(); }, 200);
        }

        function previewLogoEditar(input) {
            if (!input.files || !input.files[0]) return;
            if (input.files[0].size > 5 * 1024 * 1024) {
                alert('El logo pesa más de 5 MB. Comprime la imagen (JPG/PNG liviano) e inténtalo de nuevo.');
                input.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('edit_logo_preview').src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }

        function abrirModalEditar(comercio) {
            document.getElementById('edit_id').value = comercio.id;
            document.getElementById('edit_nombre').value = comercio.nombre;
            document.getElementById('edit_rif').value = comercio.rif;
            document.getElementById('edit_razon').value = comercio.razon_social;
            document.getElementById('edit_telefono').value = comercio.telefono;
            document.getElementById('edit_tipo').value = comercio.tipo_comercio || 'Pequeño';
            document.getElementById('edit_correo').value = comercio.correo || '';
            document.getElementById('edit_direccion').value = comercio.direccion || '';
            document.getElementById('edit_categoria').value = comercio.categoria || '';
            document.getElementById('edit_descripcion').value = comercio.descripcion || '';
            document.getElementById('edit_horario').value = comercio.horario || '';
            document.getElementById('edit_instagram').value = comercio.instagram || '';
            document.getElementById('edit_publico').checked = comercio.perfil_publico == 1;
            document.getElementById('edit_menu').checked = comercio.gestion_menu == 1;
            document.getElementById('edit_abre').value = (comercio.hora_abre || '').toString().substring(0, 5);
            document.getElementById('edit_cierra').value = (comercio.hora_cierra || '').toString().substring(0, 5);
            document.getElementById('edit_logo').value = '';
            var logo = comercio.logo_url || 'https://cdn-icons-png.flaticon.com/512/819/819814.png';
            document.getElementById('edit_logo_preview').src = logo;
            document.getElementById('edit_logo_preview').onerror = function () {
                this.onerror = null;
                this.src = 'https://cdn-icons-png.flaticon.com/512/819/819814.png';
            };
            
            let cLat = parseFloat(comercio.latitud || comercio.lat || 10.0645);
            let cLng = parseFloat(comercio.longitud || comercio.lng || -69.3569);

            document.getElementById('modalEditar').style.display = 'flex';
            inicializarMapaEditar(cLat, cLng);
        }

        function abrirModalMetricas(comercio) {
            document.getElementById('m_nombre').innerText = comercio.nombre;
            document.getElementById('m_rif').innerText = comercio.rif;
            document.getElementById('m_razon').innerText = comercio.razon_social;
            document.getElementById('m_dir').innerText = comercio.direccion;
            document.getElementById('m_viajes').innerText = comercio.entregas_totales ? comercio.entregas_totales : '0';
            
            let estado = comercio.estado_documentos || 'Pendiente';
            let estadoSpan = document.getElementById('m_estado');
            estadoSpan.innerText = estado;
            if(estado === 'Verificado') {
                estadoSpan.style.color = '#16a34a';
            } else if(estado === 'En Revisión' || estado === 'En Revision') {
                estadoSpan.style.color = '#d97706';
            } else {
                estadoSpan.style.color = '#dc2626';
            }

            let docUrl = String(comercio.documento_url || '').trim();
            if (docUrl && !/^https?:\/\//i.test(docUrl)) {
                docUrl = 'https://comercios.yoradelivery.com/' + docUrl.replace(/^\/+/, '');
            }
            if (docUrl && !/^https?:\/\//i.test(docUrl)) {
                docUrl = '';
            }
            let csrf = <?php echo json_encode(yora_csrf_token()); ?>;
            let docContainer = document.getElementById('m_doc_container');
            if(docUrl) {
                let acciones = estado === 'Verificado'
                    ? '<span style="color:#16a34a; font-weight:700; font-size:0.8rem;">Aprobado</span>'
                    : '<form method="POST" style="display:inline"><input type="hidden" name="_csrf" value="'+csrf+'"><input type="hidden" name="aprobar_doc" value="'+String(comercio.id)+'"><button type="submit" style="background:#dcfce7; color:#16a34a; padding:6px 12px; border-radius:8px; font-weight:700; font-size:0.8rem; border:1px solid #bbf7d0; cursor:pointer;">Aprobar Cuenta</button></form>'
                      + '<form method="POST" style="display:inline;margin-left:6px;"><input type="hidden" name="_csrf" value="'+csrf+'"><input type="hidden" name="rechazar_doc" value="'+String(comercio.id)+'"><button type="submit" style="background:#fee2e2; color:#b91c1c; padding:6px 12px; border-radius:8px; font-weight:700; font-size:0.8rem; border:1px solid #fecaca; cursor:pointer;">Rechazar</button></form>';
                docContainer.innerHTML = '<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">'
                    + '<a href="'+docUrl.replace(/"/g, '%22')+'" target="_blank" rel="noopener" style="color:var(--yora-orange); font-weight:600; text-decoration:none; font-size:0.9rem;"><i class="ph ph-file-text"></i> Ver Documento Adjunto</a>'
                    + acciones
                    + '</div>';
            } else {
                docContainer.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem;"><i class="ph ph-info"></i> El comercio aún no ha subido documento de verificación.</span>';
            }

            document.getElementById('modalMetricas').style.display = 'flex';
        }

        function cerrarModales() {
            document.getElementById('modalEditar').style.display = 'none';
            document.getElementById('modalMetricas').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                cerrarModales();
            }
        }
    </script>
</body>
</html>