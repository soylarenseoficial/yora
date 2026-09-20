<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json; charset=utf-8');
$comercio_id = yora_require_comercio();
yora_timezone($conexion);

try {
    $saldo_actual = (float) (yora_credito_estado($conexion, $comercio_id)['disponible'] ?? 0);
    $rows = yora_all($conexion, 'SELECT * FROM recargas_saldo WHERE comercio_id = ? ORDER BY id DESC', 'i', $comercio_id);
    $html = '';

    if ($rows) {
        foreach ($rows as $row) {
            if ($row['estatus'] === 'En Revisión') {
                $badge = '<span style="color:#d97706; background:#fef3c7; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold;">En Revisión</span>';
            } elseif ($row['estatus'] === 'Aprobada') {
                $badge = '<span style="color:#16a34a; background:#dcfce7; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold;">Aprobada</span>';
            } else {
                $badge = '<span style="color:#dc2626; background:#fee2e2; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold;">Rechazada</span>';
            }
            $fecha_formato = date('d/m/Y h:i A', strtotime($row['fecha_registro']));
            $html .= '<tr>
            <td style="padding:12px; border-bottom:1px solid #e2e8f0; font-size:0.85rem;">' . yora_h($fecha_formato) . '</td>
            <td style="padding:12px; border-bottom:1px solid #e2e8f0; font-size:0.9rem; font-weight:bold; color:#e4441b;">$' . number_format((float) $row['monto'], 2) . '</td>
            <td style="padding:12px; border-bottom:1px solid #e2e8f0; font-size:0.85rem;">' . yora_h($row['banco_origen']) . '<br><small style="color:#64748b;">Ref: ' . yora_h($row['referencia']) . '</small></td>
            <td style="padding:12px; border-bottom:1px solid #e2e8f0;">' . $badge . '</td>
        </tr>';
        }
    } else {
        $html = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#9ca3af; font-size:0.9rem;">No has realizado recargas aún.</td></tr>';
    }

    yora_json(['html' => $html, 'saldo_billetera' => number_format($saldo_actual, 2)]);
} catch (Throwable $e) {
    error_log('historial_recargas: ' . $e->getMessage());
    yora_fail('No se pudo cargar el historial de recargas.');
}
