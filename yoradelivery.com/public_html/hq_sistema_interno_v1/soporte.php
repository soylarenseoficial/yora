<?php
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/../../config.php';
yora_require_admin_pagina('soporte.php');
$conexion->set_charset("utf8mb4");

if(isset($_POST['resolver']) && isset($_POST['reporte_id'])) {
    if (!yora_verify_same_origin() && !yora_csrf_ok()) { header('Location: soporte.php'); exit; }
    $id_rep = (int) $_POST['reporte_id'];
    yora_exec($conexion, "UPDATE reportes_soporte SET estatus = 'Cerrado' WHERE id = ?", 'i', $id_rep);
    header("Location: soporte.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/hq_head.php'; ?>
    <title>YoraAdmin | Soporte Técnico</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --yora-orange: #e4441b; --bg-body: #f4f6f8; --bg-card: #ffffff; --border-color: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        .sidebar { width: 280px; min-width: 280px; background-color: var(--bg-card); padding: 25px 20px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; z-index: 10;}
        .sidebar-brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 35px; padding-left: 10px; }
        .sidebar h2 { font-size: 1.5rem; margin: 0; font-weight: 700; color: var(--text-main); }
        .sidebar h2 span { color: var(--yora-orange); }
        .badge-app { background: rgba(228,68,27,0.1); color: var(--yora-orange); font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; }
        .menu-category { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 1px; margin: 0 0 10px 10px; }
        .menu-item { padding: 12px 15px; margin-bottom: 6px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; font-size: 0.95rem; color: #4b5563; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover { background-color: #f3f4f6; color: var(--text-main); transform: translateX(3px); }
        .menu-item.active { background-color: rgba(228,68,27,0.1); color: var(--yora-orange); font-weight: 600; }
        .menu-bottom { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; }

        .content { flex: 1; padding: 40px 50px; overflow-y: auto; background-color: var(--bg-body); }
        .card-table { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); width: 100%; overflow: hidden; margin-top:20px;}
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 16px 24px; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; background: #fafafa;}
        td { padding: 18px 24px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; vertical-align: middle; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h1 style="font-size: 1.8rem; font-weight: 700; color: var(--text-dark);">Soporte Técnico Operativo</h1>
        <p style="color: var(--text-muted);">Bandeja de entrada de problemas reportados por los comercios respecto al delivery.</p>

        <div class="card-table">
            <table>
                <thead>
                    <tr>
                        <th>Fecha / Orden</th>
                        <th>Comercio Afectado</th>
                        <th>Motivo Principal</th>
                        <th>Descripción de lo Sucedido</th>
                        <th>Estatus</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT rs.*, r.nombre, r.telefono 
                            FROM reportes_soporte rs 
                            JOIN comercios r ON rs.comercio_id = r.id 
                            ORDER BY rs.id DESC";
                    $res = $conexion->query($sql);

                    if($res && $res->num_rows > 0) {
                        while($row = $res->fetch_assoc()) {
                            $badge = ($row['estatus'] == 'Abierto') ? '<span style="color:#dc2626; background:#fee2e2; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; animation: pulse 2s infinite;">URGENTE</span>' : '<span style="color:#16a34a; font-weight:700; font-size:0.8rem;">Resuelto</span>';
                            
                            echo '<tr '.($row['estatus'] == 'Abierto' ? 'style="background:#fff5f5;"' : '').'>
                                <td>'.date("d/m/Y h:i A", strtotime($row['fecha_registro'])).'<br><b style="color:#e4441b;">Orden #'.$row['comanda_id'].'</b></td>
                                <td><b>'.htmlspecialchars($row['nombre']).'</b><br><small style="color:#64748b;">Telf: '.$row['telefono'].'</small></td>
                                <td><b>'.yora_h($row['motivo']).'</b></td>
                                <td style="max-width:300px; line-height:1.4;">'.htmlspecialchars($row['descripcion']).'</td>
                                <td>'.$badge.'</td>
                                <td>';
                            if($row['estatus'] == 'Abierto') {
                                echo '<form method="POST">'.yora_csrf_field().'
                                        <input type="hidden" name="reporte_id" value="'.$row['id'].'">
                                        <button type="submit" name="resolver" style="background:#10b981; color:white; border:none; padding:8px 12px; border-radius:8px; cursor:pointer; font-weight:600;" onclick="return confirm(\'¿Marcar como resuelto?\')">Cerrar Caso</button>
                                      </form>';
                            }
                            echo '</td></tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:50px 20px;">No hay reportes de soporte.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>