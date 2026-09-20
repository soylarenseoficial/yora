<?php
/**
 * =====================================================================
 *  CENTRO DE VERIFICACIONES DE CONDUCTORES - YORA HQ
 * =====================================================================
 *  Cola de trabajo del equipo de verificacion: aqui llegan los
 *  expedientes que los conductores envian desde la app (Ajustes ->
 *  Verificar mi cuenta).
 *
 *  Se puede aprobar o rechazar documento por documento (con motivo, que
 *  el conductor ve en su app) y despues aprobar o rechazar la cuenta
 *  completa. Cada decision le manda una notificacion al telefono.
 * =====================================================================
 */
require_once __DIR__ . '/conexion.php';
yora_require_admin_pagina('verificaciones.php');
yora_timezone($conexion);

yora_docs_esquema($conexion);

// Acciones sensibles: exigimos el token, no solo el mismo origen.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (!yora_verify_same_origin() || !yora_csrf_ok())) {
    http_response_code(403);
    exit('Solicitud rechazada.');
}

$mensaje_sys = '';
$tipo_mensaje = '';
$wa_pendiente = null;

/** Deja el motivo en algo publicable: sin HTML y de largo razonable. */
function motivo_limpio($valor): string
{
    $texto = trim((string) $valor);
    $texto = (string) preg_replace('/\s+/u', ' ', strip_tags($texto));
    return mb_substr($texto, 0, 220);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int) ($_POST['driver_id'] ?? 0);
    $accion = (string) ($_POST['accion'] ?? '');
    $conductor = $id > 0 ? yora_one($conexion, 'SELECT * FROM conductores WHERE id = ?', 'i', $id) : null;

    if (!$conductor) {
        $mensaje_sys = 'No encontramos ese conductor.';
        $tipo_mensaje = 'error';
    } else {
        $revision = yora_docs_leer_revision($conductor['docs_revision'] ?? '');
        $pedidos = yora_docs_para_vehiculo($conductor['tipo_vehiculo'] ?? 'moto');
        $nombre = (string) $conductor['nombre'];

        try {
            if ($accion === 'documento') {
                $campo = (string) ($_POST['campo'] ?? '');
                $estado = (string) ($_POST['estado'] ?? '');
                $motivo = motivo_limpio($_POST['motivo'] ?? '');

                if (!isset($pedidos[$campo])) {
                    throw new RuntimeException('Ese documento no corresponde a este conductor.');
                }
                if (!in_array($estado, ['Aprobado', 'Rechazado'], true)) {
                    throw new RuntimeException('Acción no válida sobre el documento.');
                }
                if ($estado === 'Rechazado' && $motivo === '') {
                    throw new RuntimeException('Escribe el motivo del rechazo: el conductor lo lee en su app.');
                }

                $revision[$campo] = [
                    'estado' => $estado,
                    'motivo' => $estado === 'Rechazado' ? $motivo : '',
                    'fecha'  => date('Y-m-d H:i:s'),
                ];
                yora_docs_guardar_revision($conexion, $id, $revision);

                $etiqueta = (string) $pedidos[$campo]['etiqueta'];
                $mensaje_sys = $estado === 'Aprobado'
                    ? '✅ ' . yora_h($etiqueta) . ' aprobado en el expediente de ' . yora_h($nombre) . '.'
                    : '❌ ' . yora_h($etiqueta) . ' rechazado. ' . yora_h($nombre) . ' ya puede verlo en su app.';
                $tipo_mensaje = 'exito';

                if ($estado === 'Rechazado') {
                    // El expediente vuelve al conductor: si no, se queda en revisión y no puede reenviarlo.
                    yora_exec($conexion, "UPDATE conductores SET estado_documentos = 'Rechazado' WHERE id = ?", 'i', $id);
                    yora_push_conductor(
                        $conexion,
                        $id,
                        'Revisa un documento',
                        $etiqueta . ': ' . $motivo,
                        '/ajustes_documentos.php'
                    );
                }
            } elseif ($accion === 'verificar') {
                $estado_actual = yora_docs_estado($conductor);
                if ($estado_actual['faltantes'] > 0) {
                    throw new RuntimeException('Le faltan ' . $estado_actual['faltantes'] . ' documento(s): no se puede verificar todavía.');
                }

                // Deja firmada la aprobación: es la prueba de que la revisó una
                // persona y no el simple valor por defecto de la tabla.
                yora_docs_aprobar_cuenta($conexion, $conductor, yora_admin_nombre());

                yora_push_conductor(
                    $conexion,
                    $id,
                    '¡Cuenta verificada! 🎉',
                    'Tu expediente fue aprobado. Ya puedes operar con normalidad en Yora.',
                    '/dashboard.php'
                );

                $mensaje_sys = '🎉 Cuenta de <b>' . yora_h($nombre) . '</b> verificada. Le llegó la notificación a su app.';
                $tipo_mensaje = 'exito';
                $wa_pendiente = ['tipo' => 'verificado', 'nombre' => $nombre, 'telefono' => (string) $conductor['telefono'], 'motivo' => ''];
            } elseif ($accion === 'rechazar') {
                $motivo = motivo_limpio($_POST['motivo'] ?? '');
                if ($motivo === '') {
                    throw new RuntimeException('Escribe el motivo del rechazo.');
                }

                // Los documentos ya aprobados se respetan: solo se marcan los
                // que quedan pendientes, para que no repita trabajo hecho.
                foreach (array_keys($pedidos) as $campo) {
                    $marca = is_array($revision[$campo] ?? null) ? $revision[$campo] : [];
                    if (($marca['estado'] ?? '') === 'Aprobado') {
                        continue;
                    }
                    if (trim((string) ($conductor[$campo] ?? '')) === '') {
                        continue;
                    }
                    $revision[$campo] = [
                        'estado' => 'Rechazado',
                        'motivo' => trim((string) ($marca['motivo'] ?? '')) !== '' ? $marca['motivo'] : $motivo,
                        'fecha'  => date('Y-m-d H:i:s'),
                    ];
                }
                $revision['_cuenta'] = ['motivo' => $motivo, 'fecha' => date('Y-m-d H:i:s')];
                yora_docs_guardar_revision($conexion, $id, $revision);
                yora_exec($conexion, "UPDATE conductores SET estado_documentos = 'Rechazado' WHERE id = ?", 'i', $id);

                yora_push_conductor(
                    $conexion,
                    $id,
                    'Tu verificación necesita cambios',
                    $motivo,
                    '/ajustes_documentos.php'
                );

                $mensaje_sys = 'Expediente de <b>' . yora_h($nombre) . '</b> devuelto con observaciones.';
                $tipo_mensaje = 'exito';
                $wa_pendiente = ['tipo' => 'rechazo', 'nombre' => $nombre, 'telefono' => (string) $conductor['telefono'], 'motivo' => $motivo];
            } elseif ($accion === 'recordar') {
                $estado_actual = yora_docs_estado($conductor);
                $falta_enviar = $estado_actual['faltantes'] === 0;
                yora_push_conductor(
                    $conexion,
                    $id,
                    $falta_enviar ? 'Falta enviar tu expediente' : 'Te faltan documentos',
                    $falta_enviar
                        ? 'Ya subiste todos tus documentos. Entra a Ajustes y pulsa "Enviar a verificación".'
                        : 'Sube los ' . $estado_actual['faltantes'] . ' documentos que faltan para verificar tu cuenta de Yora.',
                    '/ajustes_documentos.php'
                );
                $mensaje_sys = 'Recordatorio enviado a <b>' . yora_h($nombre) . '</b>.';
                $tipo_mensaje = 'exito';
                $wa_pendiente = [
                    'tipo' => $falta_enviar ? 'sin_enviar' : 'recordatorio',
                    'nombre' => $nombre,
                    'telefono' => (string) $conductor['telefono'],
                    'motivo' => (string) $estado_actual['faltantes'],
                ];
            } else {
                throw new RuntimeException('Acción desconocida.');
            }
        } catch (Throwable $e) {
            $mensaje_sys = yora_h($e->getMessage());
            $tipo_mensaje = 'error';
        }
    }
}

// =====================================================================
//  COLA DE TRABAJO
// =====================================================================
$filas = yora_all($conexion, "SELECT * FROM conductores WHERE estatus <> 'rechazado' ORDER BY id DESC");

$grupos = ['revisar' => [], 'incompletos' => [], 'rechazados' => [], 'verificados' => []];
foreach ($filas as $fila) {
    $estado = yora_docs_estado($fila);
    $item = ['fila' => $fila, 'docs' => $estado];
    if ($estado['declarado'] === 'En Revisión' && $estado['faltantes'] === 0) {
        $grupos['revisar'][] = $item;
    } elseif ($estado['global'] === 'Verificado') {
        $grupos['verificados'][] = $item;
    } elseif ($estado['global'] === 'Rechazado') {
        $grupos['rechazados'][] = $item;
    } else {
        $grupos['incompletos'][] = $item;
    }
}

// Los que enviaron primero se atienden primero.
usort($grupos['revisar'], static function (array $a, array $b): int {
    return strcmp((string) $a['docs']['enviado_en'], (string) $b['docs']['enviado_en']);
});

/** Tarjeta de un conductor con todos sus documentos y las acciones. */
function pintar_expediente(array $item): void
{
    $fila = $item['fila'];
    $docs = $item['docs'];
    $id = (int) $fila['id'];
    $enviado = $docs['enviado_en'] !== '' && $docs['enviado_en'] !== '0000-00-00 00:00:00'
        ? date('d/m/Y h:i A', strtotime($docs['enviado_en']))
        : '';
    ?>
    <div class="exp-card">
        <div class="exp-head">
            <div>
                <strong><?php echo yora_h($fila['nombre']); ?></strong>
                <span class="badge" style="background:<?php echo yora_h($docs['estilo']['fondo']); ?>; color:<?php echo yora_h($docs['estilo']['color']); ?>;">
                    <?php echo yora_h($docs['estilo']['texto']); ?>
                </span>
                <small>
                    CI <?php echo yora_h($fila['cedula'] ?: '—'); ?> ·
                    <?php echo yora_h(ucfirst((string) $fila['tipo_vehiculo'])); ?>
                    <?php echo $fila['placa'] ? ' ' . yora_h($fila['placa']) : ''; ?> ·
                    <?php echo yora_h($fila['telefono']); ?>
                    <?php if ($enviado !== ''): ?> · enviado <?php echo yora_h($enviado); ?><?php endif; ?>
                </small>
            </div>
            <div class="exp-progreso">
                <b><?php echo (int) $docs['aprobados']; ?>/<?php echo (int) $docs['total']; ?></b>
                <span>aprobados</span>
            </div>
        </div>

        <?php if ($docs['motivo'] !== ''): ?>
        <div class="exp-motivo">Última observación enviada: <?php echo yora_h($docs['motivo']); ?></div>
        <?php endif; ?>

        <div class="exp-docs">
            <?php foreach ($docs['items'] as $campo => $doc): ?>
            <div class="exp-doc">
                <div class="exp-doc-top">
                    <span class="badge" style="background:<?php echo yora_h($doc['estilo']['fondo']); ?>; color:<?php echo yora_h($doc['estilo']['color']); ?>;">
                        <?php echo yora_h($doc['estilo']['texto']); ?>
                    </span>
                </div>
                <strong><?php echo yora_h($doc['etiqueta']); ?></strong>

                <?php if ($doc['cargado'] && $doc['url'] !== ''): ?>
                    <?php $es_pdf = strtolower((string) pathinfo((string) parse_url($doc['url'], PHP_URL_PATH), PATHINFO_EXTENSION)) === 'pdf'; ?>
                    <a href="<?php echo yora_h($doc['url']); ?>" target="_blank" rel="noopener" class="exp-thumb">
                        <?php if ($es_pdf): ?>
                            <span class="exp-pdf">📄 Ver PDF</span>
                        <?php else: ?>
                            <img src="<?php echo yora_h($doc['url']); ?>" alt="<?php echo yora_h($doc['etiqueta']); ?>" loading="lazy">
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <div class="exp-vacio">Sin cargar</div>
                <?php endif; ?>

                <?php if ($doc['estado'] === 'Rechazado' && $doc['motivo'] !== ''): ?>
                <p class="exp-doc-motivo"><?php echo yora_h($doc['motivo']); ?></p>
                <?php endif; ?>

                <?php if ($doc['cargado']): ?>
                <div class="exp-doc-btns">
                    <form method="POST"><?php echo yora_csrf_field(); ?>
                        <input type="hidden" name="driver_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="accion" value="documento">
                        <input type="hidden" name="campo" value="<?php echo yora_h($campo); ?>">
                        <input type="hidden" name="estado" value="Aprobado">
                        <button type="submit" class="mini-btn ok" <?php echo $doc['estado'] === 'Aprobado' ? 'disabled' : ''; ?>>Aprobar</button>
                    </form>
                    <button type="button" class="mini-btn no" onclick="rechazarDoc(<?php echo $id; ?>, '<?php echo yora_h($campo); ?>', '<?php echo yora_h(addslashes($doc['etiqueta'])); ?>')">Rechazar</button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="exp-acciones">
            <?php if ($docs['faltantes'] === 0): ?>
            <?php if ($docs['global'] === 'Cargado'): ?>
            <!-- Tiene todo cargado pero no pulsó "Enviar": se puede aprobar
                 igual, no tiene sentido esperar por un botón. -->
            <form method="POST">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="driver_id" value="<?php echo $id; ?>">
                <input type="hidden" name="accion" value="recordar">
                <button type="submit" class="btn-action btn-blue">🔔 Recordarle que lo envíe</button>
            </form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('¿Verificar la cuenta de <?php echo yora_h(addslashes($fila['nombre'])); ?>? Se le avisa a su teléfono.');">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="driver_id" value="<?php echo $id; ?>">
                <input type="hidden" name="accion" value="verificar">
                <button type="submit" class="btn-action btn-green" <?php echo $docs['global'] === 'Verificado' ? 'disabled' : ''; ?>>
                    ✅ Verificar cuenta
                </button>
            </form>
            <button type="button" class="btn-action btn-red" onclick="rechazarCuenta(<?php echo $id; ?>, '<?php echo yora_h(addslashes($fila['nombre'])); ?>')">
                ↩️ Devolver con observaciones
            </button>
            <?php else: ?>
            <form method="POST">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="driver_id" value="<?php echo $id; ?>">
                <input type="hidden" name="accion" value="recordar">
                <button type="submit" class="btn-action btn-blue">🔔 Recordarle los <?php echo (int) $docs['faltantes']; ?> que faltan</button>
            </form>
            <?php endif; ?>

            <button type="button" class="btn-action btn-whatsapp" onclick="whatsapp('<?php echo yora_h($fila['telefono']); ?>', '<?php echo yora_h(addslashes($fila['nombre'])); ?>')">💬 WhatsApp</button>
            <a class="btn-action btn-gris" href="conductores.php">📁 Ver expediente completo</a>
        </div>
    </div>
    <?php
}

// La conexión no se cierra aquí: sidebar.php la necesita para sus contadores.
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Verificaciones</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #e4441b; --bg-main: #f3f4f6; --surface: #ffffff; --text-dark: #111827; --text-gray: #6b7280; --border: #e5e7eb; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-main); color: var(--text-dark); display: flex; height: 100vh; overflow: hidden; }

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

        .content { flex: 1; padding: 30px; overflow-y: auto; }
        .tabs-container { display: flex; gap: 10px; border-bottom: 2px solid var(--border); margin: 20px 0; flex-wrap: wrap; }
        .tab-btn { background: none; border: none; padding: 12px 22px; font-size: 0.95rem; font-weight: 700; color: var(--text-gray); cursor: pointer; border-bottom: 3px solid transparent; font-family: inherit; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 18px; font-size: 0.88rem; font-weight: 600; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-exito { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .aviso-wa { display: flex; align-items: center; justify-content: space-between; gap: 15px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 12px; padding: 15px 18px; margin-bottom: 18px; }
        .aviso-wa p { font-size: 0.9rem; color: #15803d; font-weight: 600; }
        .btn-wa-grande { background: #25D366; color: #fff; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 700; cursor: pointer; font-family: inherit; }

        .badge { padding: 3px 9px; border-radius: 20px; font-size: 0.68rem; font-weight: 800; display: inline-block; }

        .exp-card { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 20px; margin-bottom: 18px; }
        .exp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 14px; }
        .exp-head strong { font-size: 1.08rem; display: inline-block; margin-right: 8px; }
        .exp-head small { display: block; color: var(--text-gray); font-size: 0.78rem; margin-top: 6px; }
        .exp-progreso { text-align: center; background: #f9fafb; border: 1px solid var(--border); border-radius: 12px; padding: 8px 14px; white-space: nowrap; }
        .exp-progreso b { display: block; font-size: 1.15rem; color: var(--primary); }
        .exp-progreso span { font-size: 0.65rem; text-transform: uppercase; color: var(--text-gray); font-weight: 700; letter-spacing: 0.5px; }
        .exp-motivo { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 13px; font-size: 0.82rem; font-weight: 600; margin-bottom: 14px; }

        .exp-docs { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 12px; }
        .exp-doc { border: 1px solid var(--border); border-radius: 12px; padding: 11px; background: #fcfcfd; display: flex; flex-direction: column; }
        .exp-doc-top { margin-bottom: 7px; }
        .exp-doc strong { font-size: 0.76rem; line-height: 1.3; display: block; margin-bottom: 8px; color: #374151; }
        .exp-thumb { display: block; }
        .exp-thumb img { width: 100%; height: 108px; object-fit: cover; border-radius: 9px; border: 1px solid var(--border); background: #f3f4f6; }
        .exp-pdf { display: flex; align-items: center; justify-content: center; height: 108px; border-radius: 9px; background: #f3f4f6; border: 1px solid var(--border); font-size: 0.8rem; font-weight: 700; color: #b91c1c; }
        .exp-vacio { height: 108px; display: flex; align-items: center; justify-content: center; border-radius: 9px; border: 1px dashed #d1d5db; color: #9ca3af; font-size: 0.75rem; font-weight: 600; }
        .exp-doc-motivo { font-size: 0.72rem; color: #b91c1c; font-weight: 600; margin-top: 7px; line-height: 1.35; }
        .exp-doc-btns { display: flex; gap: 6px; margin-top: 9px; }
        .exp-doc-btns form { flex: 1; }
        .mini-btn { width: 100%; border: none; border-radius: 8px; padding: 7px 4px; font-size: 0.72rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .mini-btn.ok { background: #dcfce7; color: #15803d; }
        .mini-btn.no { background: #fee2e2; color: #b91c1c; flex: 1; }
        .mini-btn[disabled] { opacity: 0.45; cursor: default; }

        .exp-acciones { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; border-top: 1px solid #f3f4f6; padding-top: 15px; }
        .btn-action { padding: 10px 15px; border-radius: 9px; font-weight: 700; cursor: pointer; border: none; font-size: 0.82rem; font-family: inherit; text-decoration: none; display: inline-block; }
        .btn-green { background: #10b981; color: #fff; }
        .btn-red { background: #ef4444; color: #fff; }
        .btn-blue { background: #3b82f6; color: #fff; }
        .btn-gris { background: #f3f4f6; color: #4b5563; }
        .btn-whatsapp { background: #25D366; color: #fff; }
        .btn-action[disabled] { opacity: 0.5; cursor: default; }

        .vacio { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 45px; text-align: center; color: var(--text-gray); font-weight: 600; }
        .hint { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; margin-bottom: 16px; }

        @media (max-width: 900px) {
            body { display: block; height: auto; overflow: auto; }
            .sidebar { width: 100%; min-width: 0; }
            .content { padding: 16px; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="content">
        <h2 style="font-weight:800; font-size:1.8rem;">Verificaciones de Conductores</h2>
        <p style="color:var(--text-gray); font-size:0.9rem; margin-top:6px;">
            Los conductores suben sus documentos desde <b>Ajustes → Verificar mi cuenta</b> en la app y aparecen aquí.
        </p>

        <?php if ($mensaje_sys !== ''): ?>
            <div class="alert alert-<?php echo yora_h($tipo_mensaje); ?>" style="margin-top:18px;"><?php echo $mensaje_sys; ?></div>
        <?php endif; ?>

        <?php if ($wa_pendiente): ?>
            <div class="aviso-wa">
                <p>💬 ¿Le avisamos también por WhatsApp a <?php echo yora_h($wa_pendiente['nombre']); ?> (<?php echo yora_h($wa_pendiente['telefono']); ?>)?</p>
                <button type="button" class="btn-wa-grande" onclick="enviarWhatsappPendiente()">Abrir WhatsApp</button>
            </div>
        <?php endif; ?>

        <div class="tabs-container">
            <button class="tab-btn active" onclick="abrirTab('t-revisar', this)">🕓 Por revisar (<?php echo count($grupos['revisar']); ?>)</button>
            <button class="tab-btn" onclick="abrirTab('t-incompletos', this)">📭 Sin enviar (<?php echo count($grupos['incompletos']); ?>)</button>
            <button class="tab-btn" onclick="abrirTab('t-rechazados', this)">↩️ Con observaciones (<?php echo count($grupos['rechazados']); ?>)</button>
            <button class="tab-btn" onclick="abrirTab('t-verificados', this)">✅ Verificados (<?php echo count($grupos['verificados']); ?>)</button>
        </div>

        <div id="t-revisar" class="tab-content active">
            <div class="hint"><b>Cola de trabajo:</b> expedientes completos que el conductor ya envió, del más antiguo al más nuevo. Aprueba documento por documento y termina con «Verificar cuenta».</div>
            <?php if (!$grupos['revisar']): ?>
                <div class="vacio">No hay expedientes esperando revisión. 🎉</div>
            <?php else: ?>
                <?php foreach ($grupos['revisar'] as $item) { pintar_expediente($item); } ?>
            <?php endif; ?>
        </div>

        <div id="t-incompletos" class="tab-content">
            <div class="hint">Conductores que aún no han enviado su expediente: o les faltan documentos, o ya los subieron todos y no pulsaron «Enviar a verificación». Puedes mandarles un recordatorio a la app.</div>
            <?php if (!$grupos['incompletos']): ?>
                <div class="vacio">Todos los conductores enviaron su expediente.</div>
            <?php else: ?>
                <?php foreach ($grupos['incompletos'] as $item) { pintar_expediente($item); } ?>
            <?php endif; ?>
        </div>

        <div id="t-rechazados" class="tab-content">
            <div class="hint">Expedientes devueltos. El conductor ve el motivo en su app y puede volver a enviarlos corregidos.</div>
            <?php if (!$grupos['rechazados']): ?>
                <div class="vacio">No hay expedientes devueltos.</div>
            <?php else: ?>
                <?php foreach ($grupos['rechazados'] as $item) { pintar_expediente($item); } ?>
            <?php endif; ?>
        </div>

        <div id="t-verificados" class="tab-content">
            <?php if (!$grupos['verificados']): ?>
                <div class="vacio">Todavía no hay cuentas verificadas.</div>
            <?php else: ?>
                <?php foreach ($grupos['verificados'] as $item) { pintar_expediente($item); } ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formulario oculto: lo reutilizan los rechazos para no repetir HTML -->
    <form method="POST" id="form-motivo" style="display:none;">
        <?php echo yora_csrf_field(); ?>
        <input type="hidden" name="driver_id" id="m_driver">
        <input type="hidden" name="accion" id="m_accion">
        <input type="hidden" name="campo" id="m_campo">
        <input type="hidden" name="estado" id="m_estado">
        <input type="hidden" name="motivo" id="m_motivo">
    </form>

    <script>
        const WA_PENDIENTE = <?php echo json_encode($wa_pendiente, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const WA_SOPORTE_TXT = '+58 422-5097031';

        function abrirTab(id, boton) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            boton.classList.add('active');
        }

        function enviarMotivo(driverId, accion, campo, estado, motivo) {
            document.getElementById('m_driver').value = driverId;
            document.getElementById('m_accion').value = accion;
            document.getElementById('m_campo').value = campo || '';
            document.getElementById('m_estado').value = estado || '';
            document.getElementById('m_motivo').value = motivo;
            document.getElementById('form-motivo').submit();
        }

        function rechazarDoc(driverId, campo, etiqueta) {
            const motivo = prompt('¿Por qué se rechaza «' + etiqueta + '»?\nEl conductor leerá este texto en su app:');
            if (!motivo || !motivo.trim()) return;
            enviarMotivo(driverId, 'documento', campo, 'Rechazado', motivo.trim());
        }

        function rechazarCuenta(driverId, nombre) {
            const motivo = prompt('Motivo para devolver el expediente de ' + nombre + ':\nEl conductor lo verá en su app y podrá corregir.');
            if (!motivo || !motivo.trim()) return;
            enviarMotivo(driverId, 'rechazar', '', '', motivo.trim());
        }

        function numeroWhatsapp(telefono) {
            let d = String(telefono || '').replace(/\D+/g, '');
            if (!d) return '';
            return d.startsWith('58') ? d : '58' + d.replace(/^0+/, '');
        }

        function whatsapp(telefono, nombre) {
            const numero = numeroWhatsapp(telefono);
            if (!numero) { alert('Este conductor no tiene un teléfono válido registrado.'); return; }
            const texto = '¡Hola *' + nombre + '*! Te escribimos de *Yora Delivery* por la verificación de tu cuenta. 🛵\n\n'
                + 'Puedes revisar tus documentos en la app: *Ajustes → Verificar mi cuenta*.\n\nSoporte: ' + WA_SOPORTE_TXT;
            window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(texto), '_blank');
        }

        function enviarWhatsappPendiente() {
            if (!WA_PENDIENTE) return;
            const numero = numeroWhatsapp(WA_PENDIENTE.telefono);
            if (!numero) { alert('Este conductor no tiene un teléfono válido registrado.'); return; }
            let texto;
            if (WA_PENDIENTE.tipo === 'verificado') {
                texto = '🎉 ¡Hola *' + WA_PENDIENTE.nombre + '*! Tu cuenta de *Yora Driver* quedó *verificada*.\n\n'
                    + 'Ya puedes operar con normalidad. ¡Buenos viajes! 🛵\n\nSoporte: ' + WA_SOPORTE_TXT;
            } else if (WA_PENDIENTE.tipo === 'sin_enviar') {
                texto = 'Hola *' + WA_PENDIENTE.nombre + '*, te escribimos de *Yora Delivery*.\n\n'
                    + 'Ya tenemos todos tus documentos cargados, pero falta que los *envíes a verificación*.\n'
                    + 'Entra a la app: *Ajustes → Verificar mi cuenta* y pulsa *Enviar a verificación*.\n\nSoporte: ' + WA_SOPORTE_TXT;
            } else if (WA_PENDIENTE.tipo === 'recordatorio') {
                texto = 'Hola *' + WA_PENDIENTE.nombre + '*, te escribimos de *Yora Delivery*.\n\n'
                    + 'Para poder operar necesitas verificar tu cuenta: te faltan *' + WA_PENDIENTE.motivo + '* documento(s).\n'
                    + 'Súbelos desde la app en *Ajustes → Verificar mi cuenta*.\n\nSoporte: ' + WA_SOPORTE_TXT;
            } else {
                texto = 'Hola *' + WA_PENDIENTE.nombre + '*, te escribimos de *Yora Delivery*.\n\n'
                    + 'Revisamos tus documentos y necesitamos que corrijas esto: *' + WA_PENDIENTE.motivo + '*.\n\n'
                    + 'Vuelve a subirlos en la app: *Ajustes → Verificar mi cuenta* y envíalos otra vez.\n\nSoporte: ' + WA_SOPORTE_TXT;
            }
            window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(texto), '_blank');
        }

        if (WA_PENDIENTE) { window.scrollTo({ top: 0 }); }
    </script>
</body>
</html>
