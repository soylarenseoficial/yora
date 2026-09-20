<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

$desde = yora_valid_date($_POST['desde'] ?? null) ?: date('Y-m-01');
$hasta = yora_valid_date($_POST['hasta'] ?? null) ?: date('Y-m-d');

try {
    $rows = yora_all(
        $conexion,
        'SELECT c.*, d.nombre AS conductor
         FROM comandas c
         LEFT JOIN conductores d ON c.conductor_id = d.id
         WHERE c.comercio_id = ?
         AND DATE(c.fecha_creacion) >= ?
         AND DATE(c.fecha_creacion) <= ?
         ORDER BY c.id DESC',
        'iss',
        $comercio_id,
        $desde,
        $hasta
    );

    $html = '';
    $total_gastado = 0;
    $total_pedidos = 0;

    if ($rows) {
        foreach ($rows as $v) {
            if ($v['estatus'] === 'Entregado') {
                $total_pedidos++;
                $total_gastado += (float) $v['costo_delivery'];
            }
            $nombre_cliente = !empty($v['cliente_nombre']) ? $v['cliente_nombre'] : 'Sin Nombre';
            $tel_cliente = !empty($v['cliente_telefono']) ? $v['cliente_telefono'] : 'Sin Teléfono';
            $direccion_paquete = !empty($v['direccion_entrega']) ? $v['direccion_entrega'] : '';

            // Codigo publico del pedido: los creados antes de esta mejora
            // reciben el suyo aqui, la primera vez que se abre el historial.
            $codigo = yora_codigo_comanda($conexion, (int) $v['id'], $v['codigo'] ?? null);

            // 'id' sigue siendo el numero interno porque con el se edita, se
            // cancela y se reporta el pedido. 'codigo' es solo lo que se ve.
            $json_data = htmlspecialchars(json_encode([
                'id' => (int) $v['id'],
                'codigo' => $codigo,
                'fecha' => date('d/m/Y h:i A', strtotime($v['fecha_creacion'])),
                'cliente' => $nombre_cliente . ' (' . $tel_cliente . ')',
                'detalles' => $direccion_paquete,
                'costo' => number_format((float) $v['costo_delivery'], 2),
                'estatus' => $v['estatus'],
                'conductor' => !empty($v['conductor']) ? $v['conductor'] : 'N/A',
                'foto_recogida' => $v['foto_recogida'],
                'foto_entrega' => $v['foto_entrega'],
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

            $color = '#64748b';
            $bg = '#f1f5f9';
            if ($v['estatus'] === 'Entregado') {
                $color = '#16a34a';
                $bg = '#dcfce7';
            }
            if ($v['estatus'] === 'Cancelado') {
                $color = '#dc2626';
                $bg = '#fee2e2';
            }
            if ($v['estatus'] === 'En Camino a Cliente' || $v['estatus'] === 'En Camino a Comercio') {
                $color = '#d97706';
                $bg = '#fef3c7';
            }
            if ($v['estatus'] === 'Buscando Conductor') {
                $color = '#2563eb';
                $bg = '#dbeafe';
            }

            $html .= '<tr>
            <td><b>#' . yora_h($codigo) . '</b></td>
            <td>' . date('d/m/Y h:i A', strtotime($v['fecha_creacion'])) . '</td>
            <td><b>' . yora_h($nombre_cliente) . '</b></td>
            <td><span style="color:' . $color . '; background:' . $bg . '; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem;">' . yora_h($v['estatus']) . '</span></td>
            <td><b>$' . number_format((float) $v['costo_delivery'], 2) . '</b></td>
            <td>
    <button onclick="abrirModalFactura(' . $json_data . ')" style="background:white; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:0.75rem; color:#475569; margin-right:5px;"><i class="ph ph-receipt"></i> Factura</button>
    <button onclick="abrirModalEvidencia(' . $json_data . ')" style="background:white; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:0.75rem; color:#16a34a;"><i class="ph ph-camera"></i> Evidencia</button>
</td>
        </tr>';
        }
    } else {
        $html = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#9ca3af;">No hay pedidos en este rango de fechas.</td></tr>';
    }

    yora_json([
        'status' => 'success',
        'html' => $html,
        'total_pedidos' => $total_pedidos,
        'total_gastado' => $total_gastado,
    ]);
} catch (Throwable $e) {
    error_log('historial_comercio: ' . $e->getMessage());
    yora_fail('No se pudo cargar el historial.');
}
