<?php
require_once __DIR__ . '/conexion.php';
yora_require_admin_pagina('clientes.php');
yora_timezone($conexion);

$mensaje = '';
$tipo_msg = '';
$wa_pendiente = null;
$clave_nueva = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !yora_verify_same_origin() && !yora_csrf_ok()) {
    http_response_code(403);
    exit('Solicitud rechazada.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $accion = (string) ($_POST['accion'] ?? '');
    $user = $id > 0 ? yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $id) : null;
    try {
        if (!$user) {
            throw new RuntimeException('Cliente no encontrado.');
        }
        $nombre = (string) $user['nombre'];
        $tel = (string) $user['telefono'];
        if ($accion === 'verificar') {
            $revision = json_decode((string) ($user['docs_revision'] ?? ''), true);
            if (!is_array($revision)) {
                $revision = [];
            }
            foreach (array_keys(yora_cliente_docs_campos()) as $campo) {
                $revision[$campo] = ['estado' => 'Aprobado', 'motivo' => '', 'fecha' => date('Y-m-d H:i:s')];
            }
            $revision['_cuenta'] = ['verificado_en' => date('Y-m-d H:i:s'), 'quien' => yora_admin_nombre()];
            yora_cliente_guardar_revision($conexion, $id, $revision);
            yora_exec($conexion, "UPDATE usuarios_app SET estado_documentos = 'Verificado' WHERE id = ?", 'i', $id);
            yora_notificar_cliente_correo($user, 'verificado');
            $mensaje = 'Cliente <b>' . yora_h($nombre) . '</b> verificado. Se envió el correo.';
            $tipo_msg = 'ok';
            $wa_pendiente = ['tipo' => 'verificado', 'nombre' => $nombre, 'telefono' => $tel];
        } elseif ($accion === 'rechazar') {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if ($motivo === '') {
                throw new RuntimeException('Escribe el motivo del rechazo.');
            }
            $revision = json_decode((string) ($user['docs_revision'] ?? ''), true);
            if (!is_array($revision)) {
                $revision = [];
            }
            foreach (array_keys(yora_cliente_docs_campos()) as $campo) {
                $revision[$campo] = ['estado' => 'Rechazado', 'motivo' => $motivo, 'fecha' => date('Y-m-d H:i:s')];
            }
            $revision['_cuenta'] = ['motivo' => $motivo, 'fecha' => date('Y-m-d H:i:s')];
            yora_cliente_guardar_revision($conexion, $id, $revision);
            yora_exec($conexion, "UPDATE usuarios_app SET estado_documentos = 'Rechazado' WHERE id = ?", 'i', $id);
            yora_notificar_cliente_correo($user, 'rechazo', $motivo);
            $mensaje = 'Expediente de <b>' . yora_h($nombre) . '</b> devuelto.';
            $tipo_msg = 'ok';
            $wa_pendiente = ['tipo' => 'rechazo', 'nombre' => $nombre, 'telefono' => $tel, 'motivo' => $motivo];
        } elseif ($accion === 'estatus') {
            $est = (string) ($_POST['estatus'] ?? 'activo');
            if (!in_array($est, ['activo', 'suspendido'], true)) {
                $est = 'activo';
            }
            yora_exec($conexion, 'UPDATE usuarios_app SET estatus = ? WHERE id = ?', 'si', $est, $id);
            $mensaje = $est === 'suspendido'
                ? 'Cuenta de <b>' . yora_h($nombre) . '</b> suspendida.'
                : 'Cuenta de <b>' . yora_h($nombre) . '</b> reactivada.';
            $tipo_msg = 'ok';
        } elseif ($accion === 'guardar') {
            $nom = trim((string) ($_POST['nombre'] ?? ''));
            $correo = mb_strtolower(trim((string) ($_POST['correo'] ?? '')));
            $cedula = strtoupper(preg_replace('/[^VEJ0-9]/i', '', (string) ($_POST['cedula'] ?? '')));
            $telefono = preg_replace('/\D+/', '', (string) ($_POST['telefono'] ?? ''));
            $direccion = trim((string) ($_POST['direccion'] ?? ''));
            if (mb_strlen($nom) < 3) {
                throw new RuntimeException('Nombre demasiado corto.');
            }
            if ($telefono === '' || strlen($telefono) < 10) {
                throw new RuntimeException('Teléfono inválido.');
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Correo inválido.');
            }
            $otro = yora_one($conexion, 'SELECT id FROM usuarios_app WHERE telefono = ? AND id <> ?', 'si', $telefono, $id);
            if ($otro) {
                throw new RuntimeException('Ese teléfono ya pertenece a otro cliente.');
            }
            yora_exec(
                $conexion,
                'UPDATE usuarios_app SET nombre=?, correo=?, cedula=?, telefono=?, direccion=? WHERE id=?',
                'sssssi',
                $nom,
                $correo,
                $cedula,
                $telefono,
                $direccion,
                $id
            );
            $mensaje = 'Datos de <b>' . yora_h($nom) . '</b> actualizados.';
            $tipo_msg = 'ok';
        } elseif ($accion === 'clave') {
            $clave_nueva = yora_clave_aleatoria(10);
            yora_exec($conexion, 'UPDATE usuarios_app SET password = ? WHERE id = ?', 'si', password_hash($clave_nueva, PASSWORD_DEFAULT), $id);
            yora_notificar_cliente_correo($user, 'clave', $clave_nueva);
            $mensaje = 'Nueva clave para <b>' . yora_h($nombre) . '</b>: <code>' . yora_h($clave_nueva) . '</code>. Se envió el correo.';
            $tipo_msg = 'ok';
            $wa_pendiente = ['tipo' => 'clave', 'nombre' => $nombre, 'telefono' => $tel, 'clave' => $clave_nueva];
        }
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $tipo_msg = 'err';
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$filtro = (string) ($_GET['f'] ?? 'todos');
$editar_id = (int) ($_GET['id'] ?? 0);
$sql = 'SELECT u.*, (SELECT COUNT(id) FROM comandas c WHERE c.usuario_id = u.id) AS pedidos FROM usuarios_app u WHERE 1=1';
$types = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= ' AND (u.nombre LIKE ? OR u.telefono LIKE ? OR u.cedula LIKE ? OR u.correo LIKE ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($filtro === 'revision') {
    $sql .= " AND u.estado_documentos = 'En Revisión'";
} elseif ($filtro === 'verificados') {
    $sql .= " AND u.estado_documentos = 'Verificado'";
} elseif ($filtro === 'pendientes') {
    $sql .= " AND IFNULL(u.estado_documentos,'Pendiente') IN ('Pendiente','Rechazado','')";
} elseif ($filtro === 'suspendidos') {
    $sql .= " AND u.estatus = 'suspendido'";
}
$sql .= ' ORDER BY FIELD(IFNULL(u.estado_documentos,\'Pendiente\'), \'En Revisión\', \'Pendiente\', \'Rechazado\', \'Verificado\'), u.id DESC LIMIT 200';
$rows = $params ? yora_all($conexion, $sql, $types, ...$params) : yora_all($conexion, $sql);
$editando = $editar_id > 0 ? yora_one($conexion, 'SELECT * FROM usuarios_app WHERE id = ?', 'i', $editar_id) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Clientes</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        body { background:#f4f6f8; color:#1f2937; display:flex; min-height:100vh; }
        .content { flex:1; padding:36px 40px; }
        h1 { font-size:1.8rem; }
        .sub { color:#6b7280; margin-bottom:20px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; padding:12px 14px; font-size:.72rem; text-transform:uppercase; color:#6b7280; background:#f8fafc; }
        td { padding:14px; border-top:1px solid #e5e7eb; font-size:.88rem; vertical-align:top; }
        .chip { font-size:.72rem; font-weight:800; padding:3px 8px; border-radius:999px; display:inline-block; }
        .btn { border:1px solid #cbd5e1; background:#fff; border-radius:8px; padding:7px 10px; cursor:pointer; font-weight:700; font-size:.78rem; text-decoration:none; color:#1f2937; display:inline-block; margin:2px 2px 0 0; }
        .btn.ok { background:#16a34a; color:#fff; border-color:#16a34a; }
        .btn.bad { background:#dc2626; color:#fff; border-color:#dc2626; }
        .ok { background:#dcfce7; color:#166534; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
        .err { background:#fee2e2; color:#b91c1c; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
        .aviso-wa { background:#ecfdf5; border:1px solid #86efac; border-radius:12px; padding:14px; margin-bottom:14px; }
        .btn-wa { background:#16a34a; color:#fff; border:0; border-radius:10px; padding:10px 14px; font-weight:800; cursor:pointer; }
        .fotos img { width:78px; height:78px; object-fit:cover; border-radius:10px; border:1px solid #e2e8f0; }
        .filtros a { margin-right:10px; text-decoration:none; font-weight:700; font-size:.82rem; color:#64748b; }
        .filtros a.on { color:#e4441b; }
        input[type=search], input[type=text], input[type=email], input[type=tel] { padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; }
        .ficha { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:18px; }
        .ficha label { display:block; font-size:.75rem; font-weight:700; color:#64748b; margin:10px 0 4px; }
        .ficha input { width:100%; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width:800px) { .grid2 { grid-template-columns:1fr; } .content { padding:18px; } }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="content">
    <h1>Clientes (app)</h1>
    <p class="sub">Registros de client.yoradelivery.com. Verifica selfie y cédula, edita datos, suspende o envía una clave nueva. Igual que con los conductores.</p>
    <?php if ($mensaje !== ''): ?><div class="<?php echo $tipo_msg === 'ok' ? 'ok' : 'err'; ?>"><?php echo $mensaje; ?></div><?php endif; ?>
    <?php if ($wa_pendiente): ?>
        <div class="aviso-wa">
            <p>💬 ¿Le avisamos también por WhatsApp a <?php echo yora_h($wa_pendiente['nombre']); ?> (<?php echo yora_h($wa_pendiente['telefono']); ?>)?</p>
            <button type="button" class="btn-wa" onclick="enviarWhatsappPendiente()">Abrir WhatsApp</button>
        </div>
    <?php endif; ?>

    <?php if ($editando):
        $de = yora_cliente_docs_estado($editando);
        $selfie = yora_url_archivo($editando['selfie_url'] ?? '');
        $ced = yora_url_archivo($editando['cedula_foto_url'] ?? '');
    ?>
    <div class="ficha">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
            <div>
                <h2 style="font-size:1.15rem;">Expediente de <?php echo yora_h($editando['nombre']); ?></h2>
                <span class="chip" style="background:<?php echo yora_h($de['estilo']['fondo']); ?>;color:<?php echo yora_h($de['estilo']['color']); ?>;"><?php echo yora_h($de['estilo']['texto']); ?></span>
                <span class="chip" style="background:#f1f5f9;color:#475569;margin-left:6px;"><?php echo yora_h($editando['estatus'] ?: 'activo'); ?></span>
            </div>
            <a class="btn" href="clientes.php<?php echo $filtro !== 'todos' ? ('?f=' . rawurlencode($filtro)) : ''; ?>">Cerrar ficha</a>
        </div>
        <div class="fotos" style="margin:14px 0;display:flex;gap:12px;flex-wrap:wrap;">
            <div>
                <div style="font-size:.72rem;font-weight:700;color:#64748b;margin-bottom:6px;">Selfie</div>
                <?php if ($selfie): ?><a href="<?php echo yora_h($selfie); ?>" target="_blank"><img src="<?php echo yora_h($selfie); ?>" alt="Selfie" style="width:160px;height:160px;"></a><?php else: ?><span style="color:#94a3b8;">Sin foto</span><?php endif; ?>
            </div>
            <div>
                <div style="font-size:.72rem;font-weight:700;color:#64748b;margin-bottom:6px;">Cédula</div>
                <?php if ($ced): ?><a href="<?php echo yora_h($ced); ?>" target="_blank"><img src="<?php echo yora_h($ced); ?>" alt="Cédula" style="width:220px;height:140px;"></a><?php else: ?><span style="color:#94a3b8;">Sin foto</span><?php endif; ?>
            </div>
        </div>
        <form method="post" class="grid2">
            <?php echo yora_csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $editando['id']; ?>">
            <input type="hidden" name="accion" value="guardar">
            <div><label>Nombre</label><input type="text" name="nombre" required value="<?php echo yora_h($editando['nombre']); ?>"></div>
            <div><label>Teléfono</label><input type="tel" name="telefono" required value="<?php echo yora_h($editando['telefono']); ?>"></div>
            <div><label>Cédula</label><input type="text" name="cedula" value="<?php echo yora_h($editando['cedula'] ?? ''); ?>"></div>
            <div><label>Correo</label><input type="email" name="correo" value="<?php echo yora_h($editando['correo'] ?? ''); ?>"></div>
            <div style="grid-column:1/-1;"><label>Dirección</label><input type="text" name="direccion" value="<?php echo yora_h($editando['direccion'] ?? ''); ?>"></div>
            <div style="grid-column:1/-1;"><button class="btn ok" type="submit">Guardar datos</button></div>
        </form>
        <div style="margin-top:12px;">
            <?php if (!$de['verificado']): ?>
            <form method="post" style="display:inline;"><?php echo yora_csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$editando['id']; ?>">
                <input type="hidden" name="accion" value="verificar">
                <button class="btn ok" type="submit">Verificar cuenta</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="var m=prompt('Motivo del rechazo:'); if(!m) return false; this.motivo.value=m;">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$editando['id']; ?>">
                <input type="hidden" name="accion" value="rechazar">
                <input type="hidden" name="motivo" value="">
                <button class="btn bad" type="submit">Rechazar</button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline;">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$editando['id']; ?>">
                <input type="hidden" name="accion" value="estatus">
                <input type="hidden" name="estatus" value="<?php echo (($editando['estatus'] ?? '') === 'suspendido') ? 'activo' : 'suspendido'; ?>">
                <button class="btn" type="submit"><?php echo (($editando['estatus'] ?? '') === 'suspendido') ? 'Activar cuenta' : 'Suspender'; ?></button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('¿Generar una clave nueva y enviarla por correo/WhatsApp?');">
                <?php echo yora_csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$editando['id']; ?>">
                <input type="hidden" name="accion" value="clave">
                <button class="btn" type="submit">Nueva clave</button>
            </form>
            <button class="btn" type="button" onclick="whatsapp('<?php echo yora_h($editando['telefono']); ?>','<?php echo yora_h($editando['nombre']); ?>')">WhatsApp</button>
        </div>
    </div>
    <?php endif; ?>

    <form method="get" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <?php if ($editar_id): ?><input type="hidden" name="id" value="<?php echo $editar_id; ?>"><?php endif; ?>
        <input type="search" name="q" value="<?php echo yora_h($q); ?>" placeholder="Nombre, teléfono, cédula, correo" style="width:280px;">
        <button class="btn" type="submit">Buscar</button>
        <div class="filtros">
            <a class="<?php echo $filtro==='todos'?'on':''; ?>" href="clientes.php">Todos</a>
            <a class="<?php echo $filtro==='revision'?'on':''; ?>" href="clientes.php?f=revision">En revisión</a>
            <a class="<?php echo $filtro==='pendientes'?'on':''; ?>" href="clientes.php?f=pendientes">Pendientes</a>
            <a class="<?php echo $filtro==='verificados'?'on':''; ?>" href="clientes.php?f=verificados">Verificados</a>
            <a class="<?php echo $filtro==='suspendidos'?'on':''; ?>" href="clientes.php?f=suspendidos">Suspendidos</a>
        </div>
    </form>
    <div class="card">
        <table>
            <thead><tr><th>Cliente</th><th>Documentos</th><th>Estado</th><th>Pedidos</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" style="padding:40px;text-align:center;color:#94a3b8;">No hay clientes.</td></tr>
            <?php else: foreach ($rows as $u):
                $d = yora_cliente_docs_estado($u);
                $selfie = yora_url_archivo($u['selfie_url'] ?? '');
                $ced = yora_url_archivo($u['cedula_foto_url'] ?? '');
                $qs = 'id=' . (int) $u['id'] . ($filtro !== 'todos' ? '&f=' . rawurlencode($filtro) : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
            ?>
                <tr>
                    <td>
                        <b><?php echo yora_h($u['nombre']); ?></b><br>
                        <?php echo yora_h($u['telefono']); ?><br>
                        <span style="color:#64748b;font-size:.78rem;"><?php echo yora_h($u['cedula'] ?: '—'); ?> · <?php echo yora_h($u['correo'] ?: 'sin correo'); ?></span><br>
                        <span style="font-size:.72rem;color:#94a3b8;">Cuenta: <?php echo yora_h($u['estatus'] ?: 'activo'); ?></span>
                    </td>
                    <td class="fotos">
                        <?php if ($selfie): ?><a href="<?php echo yora_h($selfie); ?>" target="_blank"><img src="<?php echo yora_h($selfie); ?>" alt="Selfie"></a><?php endif; ?>
                        <?php if ($ced): ?><a href="<?php echo yora_h($ced); ?>" target="_blank"><img src="<?php echo yora_h($ced); ?>" alt="Cédula"></a><?php endif; ?>
                        <?php if (!$selfie && !$ced): ?><span style="color:#94a3b8;">Sin fotos</span><?php endif; ?>
                    </td>
                    <td><span class="chip" style="background:<?php echo yora_h($d['estilo']['fondo']); ?>;color:<?php echo yora_h($d['estilo']['color']); ?>;"><?php echo yora_h($d['estilo']['texto']); ?></span></td>
                    <td><?php echo (int) ($u['pedidos'] ?? 0); ?></td>
                    <td>
                        <a class="btn" href="clientes.php?<?php echo yora_h($qs); ?>">Abrir</a>
                        <?php if (!$d['verificado']): ?>
                        <form method="post" style="display:inline;"><?php echo yora_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="accion" value="verificar">
                            <button class="btn ok" type="submit">Verificar</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="var m=prompt('Motivo del rechazo:'); if(!m) return false; this.motivo.value=m;">
                            <?php echo yora_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="accion" value="rechazar">
                            <input type="hidden" name="motivo" value="">
                            <button class="btn bad" type="submit">Rechazar</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                            <?php echo yora_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="accion" value="estatus">
                            <input type="hidden" name="estatus" value="<?php echo (($u['estatus'] ?? '') === 'suspendido') ? 'activo' : 'suspendido'; ?>">
                            <button class="btn" type="submit"><?php echo (($u['estatus'] ?? '') === 'suspendido') ? 'Activar' : 'Suspender'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    const WA = <?php echo json_encode($wa_pendiente, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function waNum(t){ let d=String(t||'').replace(/\D+/g,''); if(!d) return ''; return d.startsWith('58')?d:'58'+d.replace(/^0+/,''); }
    function whatsapp(tel, nombre){
        const n = waNum(tel);
        if (!n) { alert('Este cliente no tiene un teléfono válido.'); return; }
        const txt = '¡Hola *' + nombre + '*! Te escribimos de *Yora Delivery* por tu cuenta en la app cliente.\n\nPuedes revisar todo en https://client.yoradelivery.com/ajustes.php';
        window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(txt), '_blank');
    }
    function enviarWhatsappPendiente(){
        if (!WA) return;
        const n = waNum(WA.telefono);
        if (!n) { alert('Este cliente no tiene un teléfono válido.'); return; }
        let txt;
        if (WA.tipo === 'verificado') {
            txt = '🎉 ¡Hola *' + WA.nombre + '*! Tu cuenta de *Yora* quedó *verificada*.\n\nYa puedes pedir mandaditos en https://client.yoradelivery.com';
        } else if (WA.tipo === 'clave') {
            txt = 'Hola *' + WA.nombre + '*, te escribimos de *Yora Delivery*.\n\nTu nueva contraseña es: *' + (WA.clave||'') + '*\n\nEntra en https://client.yoradelivery.com y cámbiala en Ajustes → Seguridad.';
        } else {
            txt = 'Hola *' + WA.nombre + '*, te escribimos de *Yora Delivery*.\n\nRevisamos tu selfie y cédula y necesitamos que corrijas esto: *' + (WA.motivo||'') + '*.\n\nEntra a Ajustes → Verificar identidad: https://client.yoradelivery.com/ajustes_documentos.php';
        }
        window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(txt), '_blank');
    }
    if (WA) { window.scrollTo({ top: 0 }); }
</script>
</body>
</html>
