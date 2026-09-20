<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

try {
    yora_exec($conexion, 'UPDATE comercios SET ultima_conexion = NOW() WHERE id = ?', 'i', $comercio_id);
} catch (Throwable $e) {
}

/**
 * Lista envíos activos. Resiliente a collation / columnas.
 */
try {
    $rows = [];
    $sql = "SELECT c.*, d.nombre AS conductor, d.telefono AS conductor_telefono, d.foto_perfil
            FROM comandas c
            LEFT JOIN conductores d ON c.conductor_id = d.id
            WHERE c.comercio_id = ? AND c.estatus NOT IN ('Entregado', 'Cancelado')
            ORDER BY c.id DESC";
    try {
        $rows = yora_all($conexion, $sql, 'i', $comercio_id);
    } catch (Throwable $e1) {
        // Fallback sin JOIN si hay choque de collation u otras columnas.
        error_log('sync_comercio join: ' . $e1->getMessage());
        $rows = yora_all(
            $conexion,
            "SELECT c.* FROM comandas c
             WHERE c.comercio_id = ? AND c.estatus NOT IN ('Entregado', 'Cancelado')
             ORDER BY c.id DESC",
            'i',
            $comercio_id
        );
        foreach ($rows as &$r) {
            $r['conductor'] = null;
            $r['conductor_telefono'] = null;
            $r['foto_perfil'] = null;
            if (!empty($r['conductor_id'])) {
                try {
                    $d = yora_one(
                        $conexion,
                        'SELECT nombre, telefono, foto_perfil FROM conductores WHERE id = ? LIMIT 1',
                        'i',
                        (int) $r['conductor_id']
                    );
                    if ($d) {
                        $r['conductor'] = $d['nombre'] ?? null;
                        $r['conductor_telefono'] = $d['telefono'] ?? null;
                        $r['foto_perfil'] = $d['foto_perfil'] ?? null;
                    }
                } catch (Throwable $e2) {
                }
            }
        }
        unset($r);
    }

    $html = '';
    if ($rows) {
        foreach ($rows as $v) {
            try {
                $codigo = function_exists('yora_codigo_comanda')
                    ? yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null)
                    : (string) ($v['codigo'] ?? $v['id']);
                $nombre = trim((string) ($v['cliente_nombre'] ?? ''));
                if ($nombre === '') {
                    $nombre = 'Cliente';
                }
                $referencia = function_exists('yora_texto_destino')
                    ? yora_texto_destino($v['direccion_entrega'] ?? '')
                    : trim((string) ($v['direccion_entrega'] ?? ''));
                $gps = function_exists('yora_gps_desde_direccion')
                    ? (yora_gps_desde_direccion($v['direccion_entrega'] ?? '') ?: [0, 0])
                    : [0, 0];

                $json_data = htmlspecialchars(json_encode([
                    'id' => (int) $v['id'],
                    'codigo' => $codigo,
                    'cliente' => $nombre,
                    'tel' => (string) ($v['cliente_telefono'] ?? ''),
                    'dir' => $referencia,
                    'detalles' => (string) ($v['detalles_entrega'] ?? ''),
                    'costo' => number_format((float) ($v['costo_delivery'] ?? 0), 2),
                    'estatus' => (string) ($v['estatus'] ?? ''),
                    'tiempo' => (string) ($v['tiempo_estimado'] ?? '0'),
                    'lat' => $gps[0] ?? 0,
                    'lng' => $gps[1] ?? 0,
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

                $color = '#d97706';
                if (($v['estatus'] ?? '') === 'Buscando Conductor') {
                    $color = '#3b82f6';
                } elseif (($v['estatus'] ?? '') === 'En Camino a Cliente') {
                    $color = '#10b981';
                }

                if (!empty($v['conductor_id'])) {
                    $foto = function_exists('yora_url_archivo')
                        ? yora_url_archivo($v['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com')
                        : 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
                    $driver = '<div style="display:flex; align-items:center; gap:10px;">'
                        . '<img src="' . yora_h($foto) . '" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fdba74;">'
                        . '<div><b style="display:block;color:#9a3412;">' . yora_h((string) ($v['conductor'] ?? 'Conductor')) . '</b>'
                        . '<span style="font-size:0.75rem;color:#9a3412;">' . yora_h((string) ($v['conductor_telefono'] ?? '')) . '</span></div></div>';
                    $btn_mapa = '<span style="color:#1d4ed8; cursor:pointer; font-weight:700; margin-left:10px;" onclick="verRastreo(' . (int) $v['id'] . ')">Ver mapa</span>';
                } else {
                    $driver = 'Buscando Conductor...';
                    $btn_mapa = '';
                }
                $nota_ref = $referencia !== ''
                    ? '<p style="font-size:0.85rem; color:#64748b; margin-bottom:6px;">🏡 ' . yora_h($referencia) . '</p>'
                    : '';
                $nota_paquete = !empty($v['detalles_entrega'])
                    ? '<p style="font-size:0.85rem; color:#64748b; margin-bottom:10px;">📦 ' . yora_h($v['detalles_entrega']) . '</p>'
                    : '';
                $esMandadito = function_exists('yora_es_mandadito') ? yora_es_mandadito($v) : false;

                $html .= '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:15px; margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <b style="color:#1e293b;">' . ($esMandadito ? 'Mandadito' : 'Cliente') . ': ' . yora_h($nombre) . '</b>
                    <span style="color:' . $color . '; font-size:0.8rem; font-weight:700;">' . yora_h((string) ($v['estatus'] ?? '')) . '</span>
                </div>
                ' . $nota_ref . $nota_paquete . '
                <div style="background:#fff7ed; padding:10px 12px; border-radius:8px; border:1px dashed #fdba74; font-size:0.8rem; color:#9a3412; margin-bottom:10px;">
                    ' . $driver . '
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.8rem; color:#94a3b8;">
                    <span>Orden #' . yora_h($codigo) . ' | Costo: $' . number_format((float) ($v['costo_delivery'] ?? 0), 2) . '</span>
                    <span><span style="color:#3b82f6; cursor:pointer; font-weight:600;" onclick="abrirModalPedido(' . $json_data . ')">Ver Opciones</span>' . $btn_mapa . '</span>
                </div>
            </div>';
            } catch (Throwable $rowErr) {
                error_log('sync_comercio row: ' . $rowErr->getMessage());
            }
        }
    }

    if ($html === '') {
        $html = '<div style="text-align:center; padding:30px; color:#9ca3af;">No hay envíos activos.</div>';
    }

    $activos = (int) (yora_one($conexion, "SELECT COUNT(id) as total FROM comandas WHERE comercio_id = ? AND estatus NOT IN ('Entregado', 'Cancelado')", 'i', $comercio_id)['total'] ?? 0);
    $entregados_hoy = (int) (yora_one($conexion, "SELECT COUNT(id) as total FROM comandas WHERE comercio_id = ? AND estatus = 'Entregado' AND DATE(fecha_creacion) = CURDATE()", 'i', $comercio_id)['total'] ?? 0);
    $total_historico = (int) (yora_one($conexion, "SELECT COUNT(id) as total FROM comandas WHERE comercio_id = ? AND estatus = 'Entregado'", 'i', $comercio_id)['total'] ?? 0);

    yora_json([
        'ok' => true,
        'html' => $html,
        'stat_activos' => $activos,
        'stat_hoy' => $entregados_hoy,
        'stat_historico' => $total_historico,
    ]);
} catch (Throwable $e) {
    error_log('sync_comercio: ' . $e->getMessage());
    yora_json([
        'ok' => false,
        'mensaje' => 'No se pudo sincronizar: ' . $e->getMessage(),
        'html' => '<div style="text-align:center;padding:20px;color:#b91c1c;">Error al cargar envíos. Recarga la página.</div>',
        'stat_activos' => 0,
        'stat_hoy' => 0,
        'stat_historico' => 0,
    ], 500);
}
