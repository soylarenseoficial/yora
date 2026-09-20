<?php
require_once __DIR__ . '/../config.php';
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) {
        $mensaje = 'Solicitud rechazada. Recarga la página.';
        $tipo_mensaje = 'error';
    } elseif (!yora_rate_limit('reg-driver:' . yora_client_ip(), 4, 3600)) {
        $mensaje = 'Demasiadas solicitudes desde esta red. Inténtalo más tarde.';
        $tipo_mensaje = 'error';
    } else {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $cedula = trim((string) ($_POST['cedula'] ?? ''));
        $rif = 'V-' . preg_replace('/^V-?/i', '', trim((string) ($_POST['rif'] ?? '')));
        $correo = trim((string) ($_POST['correo'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $fnac = trim((string) ($_POST['fecha_nacimiento'] ?? ''));
        $vehiculo = strtolower(trim((string) ($_POST['vehiculo'] ?? '')));
        if (!in_array($vehiculo, ['moto', 'bicicleta', 'carga'], true)) {
            $vehiculo = 'moto';
        }
        $placa = trim((string) ($_POST['placa'] ?? ''));
        $licencia_num = trim((string) ($_POST['licencia_num'] ?? ''));
        $cert_medico = trim((string) ($_POST['certificado_medico'] ?? ''));
        $antecedentes = ($_POST['antecedentes_penales'] ?? '') === 'Si' ? 'Si' : 'No';
        $otra_app = ($_POST['otra_app'] ?? '') === 'Si' ? 'Si' : 'No';

        $edad = 0;
        if (yora_valid_date($fnac)) {
            $edad = date_diff(date_create($fnac), date_create('today'))->y;
        }

        if ($nombre === '' || $cedula === '' || $correo === '' || $telefono === '') {
            $mensaje = 'Completa los datos personales para continuar.';
            $tipo_mensaje = 'error';
        } elseif ($edad < 21) {
            $mensaje = 'Debes ser mayor de 21 años para aplicar como Yora Driver.';
            $tipo_mensaje = 'error';
        } else {
            $dup = yora_one($conexion, 'SELECT id FROM conductores WHERE cedula = ? LIMIT 1', 's', $cedula);
            if ($dup) {
                $mensaje = 'Ya existe una solicitud o cuenta con esa cédula.';
                $tipo_mensaje = 'error';
            } else {
                // Fotos, banca y contraseña se completan después en el panel o en la app.
                $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $estatus = 'pendiente';
                $categoria = 'Sencillo';
                yora_exec(
                    $conexion,
                    'INSERT INTO conductores
                    (nombre, cedula, rif, fecha_nacimiento, telefono, correo, tipo_vehiculo, placa,
                    licencia, foto_licencia, certificado_medico, foto_certificado, foto_circulacion, foto_origen, foto_rcv, foto_vehiculo, foto_carnet,
                    antecedentes_penales, otra_app, banco_pago, telefono_pago, cedula_pago, categoria, password, estatus)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    'sssssssssssssssssssssssss',
                    $nombre,
                    $cedula,
                    $rif,
                    $fnac,
                    $telefono,
                    $correo,
                    $vehiculo,
                    $placa,
                    $licencia_num,
                    '',
                    $cert_medico,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $antecedentes,
                    $otra_app,
                    '',
                    '',
                    '',
                    $categoria,
                    $password,
                    $estatus
                );
                $mensaje = '¡Solicitud enviada con éxito! El equipo de Yora revisará tu perfil y te notificará pronto.';
                $tipo_mensaje = 'exito';

                if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $cuerpo = '<p>Recibimos tu postulación para ser <b>Yora Driver</b>. 🛵</p>
                        <p>Nuestro equipo va a revisar tu perfil y te avisaremos por este mismo correo y por WhatsApp
                        en cuanto quede aprobado. Normalmente respondemos en menos de 48 horas hábiles.</p>
                        <p>Más adelante te pediremos documentos, datos de pago y tu contraseña de acceso desde la app o el panel.</p>';
                    $html = yora_plantilla_correo('¡Recibimos tu solicitud, ' . $nombre . '!', $cuerpo);
                    yora_enviar_correo($correo, $nombre, 'Recibimos tu postulación | Yora Delivery', $html);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conduce con Yora | Registro de Flota</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora: #e4441b; --bg: #f8fafc; --card: #ffffff; --text: #1e293b; --gray: #64748b; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--bg); color: var(--text); padding: 40px 20px; }

        .container { max-width: 800px; margin: 0 auto; background: var(--card); padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border-top: 5px solid var(--yora); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { font-weight: 800; font-size: 2rem; color: var(--text); }
        .header h1 span { color: var(--yora); }
        .header p { color: var(--gray); font-size: 0.95rem; margin-top: 5px; }

        .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: 600; text-align: center; }
        .alert.exito { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }

        .note { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; padding: 12px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 20px; line-height: 1.45; }

        .section-title { font-size: 1.1rem; font-weight: 700; color: var(--yora); margin: 30px 0 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media(max-width: 600px) {
            body { padding: 16px 12px; }
            .container { padding: 24px 18px; }
            .grid-2 { grid-template-columns: 1fr; }
        }

        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: 1 / -1; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; color: #475569; }
        input[type="text"], input[type="email"], input[type="date"], select {
            width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9rem; background: #f8fafc; outline: none; transition: 0.3s;
        }
        input:focus, select:focus { border-color: var(--yora); box-shadow: 0 0 0 3px rgba(228,68,27,0.1); background: #fff; }

        .btn-submit { background: var(--yora); color: white; width: 100%; padding: 16px; border: none; border-radius: 12px; font-weight: 700; font-size: 1.1rem; cursor: pointer; margin-top: 20px; box-shadow: 0 4px 15px rgba(228,68,27,0.3); transition: 0.2s; }
        .btn-submit:hover { transform: translateY(-2px); background: #c23310; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>Yora<span>Drivers</span></h1>
        <p>Postúlate y forma parte de la red logística exclusiva de Barquisimeto.</p>
    </div>

    <?php if($mensaje != ''): ?>
        <div class="alert <?php echo $tipo_mensaje; ?>"><?php echo yora_h($mensaje); ?></div>
    <?php endif; ?>

    <?php if($tipo_mensaje != 'exito'): ?>
    <p class="note">Este registro es rápido. Los documentos, datos de pago y la contraseña de la app se completan después, desde el panel de Yora o en la aplicación.</p>
    <form method="POST">
        <?php echo yora_csrf_field(); ?>

        <div class="section-title">👤 Datos Personales</div>
        <div class="grid-2">
            <div class="form-group"><label>Nombre Completo</label><input type="text" name="nombre" placeholder="Ej: Pedro Pérez" required></div>
            <div class="form-group"><label>Correo Electrónico</label><input type="email" name="correo" placeholder="tucorreo@gmail.com" required></div>
            <div class="form-group"><label>Cédula de Identidad</label><input type="text" name="cedula" placeholder="12345678" required></div>
            <div class="form-group"><label>RIF Personal</label><input type="text" name="rif" placeholder="V-12345678" required></div>
            <div class="form-group"><label>Fecha de Nacimiento</label><input type="date" name="fecha_nacimiento" required></div>
            <div class="form-group"><label>Teléfono (WhatsApp)</label><input type="text" name="telefono" placeholder="04141234567" required></div>
        </div>

        <div class="section-title">🛵 Datos del Vehículo</div>
        <div class="grid-2">
            <div class="form-group"><label>Tipo de Vehículo</label>
                <select name="vehiculo" required>
                    <option value="moto">Moto</option>
                    <option value="carga">Vehículo de carga</option>
                    <option value="bicicleta">Bicicleta</option>
                </select>
            </div>
            <div class="form-group"><label>Placa del Vehículo</label><input type="text" name="placa" required></div>
            <div class="form-group"><label>Número de Licencia</label><input type="text" name="licencia_num" required></div>
            <div class="form-group"><label>Certificado Médico Nº</label><input type="text" name="certificado_medico" required></div>
        </div>

        <div class="section-title">🛡️ Seguridad</div>
        <div class="grid-2">
            <div class="form-group"><label>¿Posee Antecedentes Penales?</label>
                <select name="antecedentes_penales" required><option value="No">No</option><option value="Si">Sí</option></select>
            </div>
            <div class="form-group"><label>¿Trabajas con otra App de Delivery?</label>
                <select name="otra_app" required><option value="No">No</option><option value="Si">Sí</option></select>
            </div>
        </div>

        <button type="submit" class="btn-submit">Enviar Solicitud de Ingreso</button>
    </form>
    <?php endif; ?>
</div>

</body>
</html>
