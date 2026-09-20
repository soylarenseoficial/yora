<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

try {
    $saldo = (float) (yora_credito_estado($conexion, $comercio_id)['disponible'] ?? 0);
    $pedidos = yora_all(
        $conexion,
        "SELECT c.*, d.nombre AS conductor_nombre, d.telefono AS conductor_telefono, d.foto_perfil
         FROM comandas c
         LEFT JOIN conductores d ON c.conductor_id = d.id
         WHERE c.comercio_id = ? AND DATE(c.fecha_creacion) = CURDATE() AND c.estatus != 'Cancelado'
         ORDER BY c.id DESC LIMIT 20",
        'i',
        $comercio_id
    );

    $html = '';
    if ($pedidos) {
        foreach ($pedidos as $pedido) {
            if ($pedido['conductor_id']) {
                $foto = yora_url_archivo($pedido['foto_perfil'] ?? '', 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', 'https://app.yoradelivery.com');
                $conductor_texto = '
            <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                <img src="' . yora_h($foto) . '" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid #10b981;">
                <span><b>' . yora_h($pedido['conductor_nombre']) . '</b> - ' . yora_h($pedido['conductor_telefono']) . '</span>
            </div>';
            } else {
                $conductor_texto = 'Buscando en la zona...';
            }

            $btn_cancelar = ($pedido['estatus'] === 'Buscando Conductor')
                ? '<button onclick="cancelarPedido(' . (int) $pedido['id'] . ')" style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 5px 10px; border-radius: 8px; cursor: pointer; font-size: 0.75rem; font-weight: 600; margin-top:10px;">Cancelar</button>'
                : '';

            $estatus = $pedido['estatus'];
            $bg = '#fef9c3';
            $text = '#a16207';
            $border = '#fde047';
            $label = $estatus;
            if ($estatus === 'Revisando') {
                $bg = '#f3f4f6';
                $text = '#4b5563';
                $border = '#d1d5db';
                $label = 'Conductor Evaluando...';
            } elseif ($estatus === 'En Camino a Comercio') {
                $bg = '#eff6ff';
                $text = '#1d4ed8';
                $border = '#bfdbfe';
            } elseif ($estatus === 'En Camino a Cliente') {
                $bg = '#f3e8ff';
                $text = '#7e22ce';
                $border = '#e9d5ff';
            } elseif ($estatus === 'Entregado') {
                $bg = '#dcfce7';
                $text = '#15803d';
                $border = '#bbf7d0';
            }

            $badge = '<span style="background: ' . $bg . '; color: ' . $text . '; border: 1px solid ' . $border . '; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; display: inline-block;">' . yora_h($label) . '</span>';
            $btn_mapa = $pedido['conductor_id']
                ? '<button type="button" onclick="verRastreo(' . (int) $pedido['id'] . ')" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:6px 10px; border-radius:8px; cursor:pointer; font-size:0.75rem; font-weight:700; margin-top:8px;">Ver en mapa</button>'
                : '';

            $html .= '
        <div style="border-left: 5px solid ' . $text . '; background: white; padding: 20px; margin-bottom: 15px; border-radius: 12px; display: flex; justify-content: space-between; align-items: flex-start; gap:12px; flex-wrap:wrap;">
            <div style="flex:1; min-width:180px;">
                <h4 style="margin: 0 0 8px 0; color: #1f2937; font-size: 1.1rem;">Pedido a: ' . yora_h($pedido['cliente_nombre']) . '</h4>
                <p style="margin: 0; font-size: 0.85rem; color: #6b7280; line-height: 1.5;">
                    <strong>Tiempo Listo:</strong> <span style="color:var(--yora-orange); font-weight:bold;">' . yora_h($pedido['tiempo_estimado']) . '</span><br>
                    <strong>Conductor:</strong> ' . $conductor_texto . '<br>
                    <strong style="display:block; margin-top:6px;">Destino:</strong> ' . yora_h(yora_texto_destino($pedido['direccion_entrega'] ?? '')) . '
                </p>
            </div>
            <div style="text-align: right;">' . $badge . '<p style="margin: 10px 0 0 0; font-size: 0.75rem; color: #9ca3af;">Orden #' . yora_h(yora_codigo_comanda($conexion, (int) $pedido['id'], $pedido['codigo'] ?? null)) . ' | $' . number_format((float) $pedido['costo_delivery'], 2) . '</p>' . $btn_cancelar . $btn_mapa . '</div>
        </div>';
        }
    } else {
        $html = '<div style="background: white; padding: 30px; border-radius: 12px; text-align: center; border: 1px dashed #d1d5db;"><p style="color: #6b7280; margin-top: 10px;">No tienes pedidos activos en este momento.</p></div>';
    }

    yora_json(['saldo' => number_format((float) $saldo, 2), 'html' => $html]);
} catch (Throwable $e) {
    error_log('obtener_pedidos_activos: ' . $e->getMessage());
    yora_fail('No se pudieron cargar los pedidos.');
}
