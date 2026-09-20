<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['comercio_id'])) {
    header('Location: index.php');
    exit;
}
$comercio_id = (int) $_SESSION['comercio_id'];
yora_mant_exigir($conexion, 'comercios', $comercio_id);
yora_timezone($conexion);
$meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
$fecha_hoy = date('d') . " de " . $meses[date('n')-1];

// 1. Datos del comercio
$res = $conexion->query("SELECT * FROM comercios WHERE id = $comercio_id");
$comercio = $res->fetch_assoc();

// 2. Estadísticas
$res_activos = $conexion->query("SELECT COUNT(id) as total FROM comandas WHERE comercio_id = $comercio_id AND estatus NOT IN ('Entregado', 'Cancelado')");
$pedidos_activos = $res_activos->fetch_assoc()['total'] ?? 0;

$res_hoy = $conexion->query("SELECT COUNT(id) as total FROM comandas WHERE comercio_id = $comercio_id AND estatus = 'Entregado' AND DATE(fecha_creacion) = CURDATE()");
$entregados_hoy = $res_hoy->fetch_assoc()['total'] ?? 0;

$res_total = $conexion->query("SELECT COUNT(id) as total FROM comandas WHERE comercio_id = $comercio_id AND estatus = 'Entregado'");
$total_historico = $res_total->fetch_assoc()['total'] ?? 0;

// 3. Agenda de clientes del comercio, para autocompletar por nombre o cédula.
$clientes_array = [];
try {
    $clientes_array = yora_all(
        $conexion,
        'SELECT cedula, nombre, telefono, referencia FROM clientes_comercio WHERE comercio_id = ? ORDER BY ultima_vez DESC LIMIT 400',
        'i',
        $comercio_id
    );
} catch (Throwable $e) {
    error_log('agenda clientes: ' . $e->getMessage());
}
$clientes_json = json_encode($clientes_array, JSON_UNESCAPED_UNICODE);

// 4. Club Yora + línea de crédito
$nivel = yora_nivel_comercio($conexion, $comercio_id);
$bono_acreditado = yora_acreditar_bono_mensual($conexion, $comercio_id, $nivel);
$credito = yora_credito_estado($conexion, $comercio_id);
$deuda = yora_estado_deuda_comercio($conexion, $comercio_id);
$facturas_abiertas = yora_all(
    $conexion,
    "SELECT id, fecha_consumo, monto, estatus, vence_el, gracia_hasta, penalizacion
     FROM facturas_comercio
     WHERE comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')
     ORDER BY vence_el ASC, id ASC
     LIMIT 40",
    'i',
    $comercio_id
) ?: [];

// Tarifa vigente: la misma que aplica el servidor al cobrar.
$precio_km_actual = yora_precio_km($conexion);
$tarifa_minima = yora_tarifa_minima($conexion);
$tramos_tarifa = yora_tramos_tarifa($conexion);

// Tasa del dia ya resuelta en el servidor, para que la pantalla abra con el
// valor correcto aunque la consulta al endpoint tarde o falle.
$tasa_bcv_actual = yora_tasa_bcv($conexion)['tasa'];

$conexion->close();

$nombre_comercio = !empty($comercio['nombre']) ? $comercio['nombre'] : "Comercio DEMO";
$saldo_prepagado = (float) ($credito['disponible'] ?? 0);
$min_recarga = 1;
$rif = !empty($comercio['rif']) ? $comercio['rif'] : "";
$director = !empty($comercio['director_nombre']) ? $comercio['director_nombre'] : "";
$cedula_dir = !empty($comercio['director_cedula']) ? $comercio['director_cedula'] : "";
$direccion_local = !empty($comercio['direccion']) ? $comercio['direccion'] : "";
$logo_comercio = yora_url_archivo($comercio['logo_url'] ?? '', 'https://cdn-icons-png.flaticon.com/512/819/819814.png');
$doc_url = yora_url_archivo($comercio['documento_url'] ?? '', '');
$estado_doc = !empty($comercio['estado_documentos']) ? $comercio['estado_documentos'] : 'Pendiente';

// 🔥 LÓGICA DE COLORES DE LA INSIGNIA SUPERIOR 🔥
if ($estado_doc == 'Verificado') {
    $badge_bg = '#dcfce7'; $badge_color = '#16a34a'; $badge_icon = 'ph-shield-check';
} elseif ($estado_doc == 'En Revisión') {
    $badge_bg = '#fef3c7'; $badge_color = '#d97706'; $badge_icon = 'ph-clock-afternoon';
} else {
    $badge_bg = '#fee2e2'; $badge_color = '#dc2626'; $badge_icon = 'ph-shield-warning';
    $estado_doc = 'No Verificado';
}

// CLUB YORA: los niveles y sus beneficios se configuran en el panel HQ
// (Tarifas y Finanzas), asi que aqui solo se muestran.
$nivel_nombre   = (string) $nivel['nombre'];
$nivel_color    = (string) $nivel['color'];
$nivel_estrellas = (int) $nivel['estrellas'];
$pedidos_mes    = (int) $nivel['pedidos_mes'];
$meta_nivel     = max(1, (int) $nivel['meta']);
$beneficios     = $nivel['lista_beneficios'];
$bono_mensual   = (float) $nivel['bono_mensual'];

$nivel_icono = $nivel_estrellas >= 3 ? 'ph-trophy' : ($nivel_estrellas >= 1 ? 'ph-medal' : 'ph-storefront');
$nivel_bg = '#f1f5f9';

$progreso_porcentaje = $nivel['pedidos_hasta'] === null
    ? 100
    : min(100, ($pedidos_mes / $meta_nivel) * 100);

$min_recarga = yora_min_recarga_comercio($comercio['tipo_comercio'] ?? 'Pequeño');
$tipo_local = yora_tipo_comercio_clave($comercio['tipo_comercio'] ?? 'Pequeño');

// Mismo criterio que usan las APIs al cobrar: el mapa y el precio deben partir del mismo punto.
[$raw_lat, $raw_lng] = yora_coords_comercio($comercio);

$tiene_gps = ($raw_lat != 0 && $raw_lng != 0);
$rest_lat = $tiene_gps ? $raw_lat : 10.0645;
$rest_lng = $tiene_gps ? $raw_lng : -69.3569;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>YoraB2B | Panel de Comercio</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        :root { --yora-orange: #e4441b; --bg-main: #f4f7fe; --text-dark: #1e293b; --text-muted: #64748b; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; -webkit-tap-highlight-color: transparent;}
        body { background-color: var(--bg-main); color: var(--text-dark); display: flex; height: 100vh; overflow-x: hidden; }
        
        .sidebar { width: 260px; min-width: 260px; background: white; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; padding: 30px 20px; z-index: 100; }
        .logo-area { text-align: center; margin-bottom: 30px; }
        .logo-area img { width: 80px; height: 80px; border-radius: 20px; object-fit: cover; border: 2px solid #f1f5f9; margin-bottom: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .logo-area h2 { font-size: 1.2rem; font-weight: 700; color: var(--text-dark); line-height: 1.2;}
        .logo-area p { font-size: 0.85rem; font-weight: 700; border-radius: 8px; display:inline-block; padding: 2px 10px; margin-top:5px; }

        .nav-menu { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .nav-item { display: flex; align-items: center; gap: 15px; color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.95rem; cursor: pointer; padding: 12px 18px; border-radius: 12px; transition: 0.2s;}
        .nav-item i { font-size: 1.3rem; }
        .nav-item:hover { background: #f8fafc; color: var(--text-dark); }
        .nav-item.active { background: #fff0ed; color: var(--yora-orange); font-weight: 600; }

        .bottom-nav { display: none; background: rgba(255, 255, 255, 0.98); box-shadow: 0 -4px 15px rgba(0,0,0,0.05); justify-content: space-around; padding: 10px 5px 15px; position: fixed; bottom: 0; left:0; width: 100%; z-index: 1000; }
        .bottom-nav .nav-item { flex-direction: column; gap: 4px; padding: 8px; font-size: 0.65rem; font-weight: 600; flex: 1; text-align: center; border-radius:8px;}
        .bottom-nav .nav-item i { font-size: 1.5rem; transition: transform 0.2s; }
        .bottom-nav .nav-item.active i { transform: translateY(-2px); }

        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-y: auto; overflow-x: hidden; width: 100%; position:relative; }
        .top-header { padding: 30px 40px 10px; display: flex; justify-content: space-between; align-items: flex-end; width:100%;}
        .top-header h1 { font-size: 2rem; font-weight: 700; color: var(--text-dark); line-height: 1.1; }
        .top-header p { color: var(--text-muted); font-size: 0.95rem; }

        .container { padding: 20px 40px 40px; display: none; gap: 30px; align-items: flex-start; flex-direction: column; width:100%; max-width:100%;}
        .container.active { display: flex; }
        
        .box { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); width: 100%; border: 1px solid #e2e8f0; box-sizing:border-box;}
        .box-title { font-size: 1.2rem; color: #1f2937; margin-bottom: 20px; font-weight: 700; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;}
        
        .flex-layout { display: flex; gap: 30px; width: 100%; align-items: flex-start; }
        .col-side { width: 400px; flex-shrink: 0; }
        .col-main { flex: 1; min-width: 0; width:100%; }

        /* TARJETAS ESTADÍSTICAS Y NIVEL */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; width: 100%; }
        .stat-card { background: white; padding: 20px; border-radius: 16px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 10px rgba(0,0,0,0.02);}
        .stat-card .info p { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 5px;}
        .stat-card .info h3 { font-size: 1.6rem; color: var(--text-dark); font-weight: 800; line-height: 1;}
        .stat-card .icon { width: 45px; height: 45px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 1.4rem; flex-shrink:0;}

        /* CLUB YORA - NIVEL WIDGET */
        .level-widget { background: #ffffff; border-radius: 20px; padding: 25px; border: 1px solid #e2e8f0; width: 100%; display: flex; flex-direction: column; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .level-header { display: flex; justify-content: space-between; align-items: center; }
        .level-header h3 { font-size: 1.3rem; font-weight: 800; color: var(--text-dark); display:flex; align-items:center; gap:8px;}
        .progress-container { width: 100%; background-color: #f1f5f9; border-radius: 10px; height: 12px; overflow: hidden; position: relative;}
        .progress-bar { height: 100%; background: linear-gradient(90deg, <?php echo $nivel_color; ?>88 0%, <?php echo $nivel_color; ?> 100%); transition: width 1s ease-in-out; border-radius: 10px;}
        .level-benefits { background: <?php echo $nivel_bg; ?>; border-left: 4px solid <?php echo $nivel_color; ?>; padding: 15px; border-radius: 0 10px 10px 0; font-size: 0.9rem; color: <?php echo $nivel_color; ?>; font-weight: 600; }

        .hero-balance { background: #1e293b; border-radius: 20px; padding: 35px 40px; color: white; display: flex; justify-content: space-between; align-items: center; width: 100%; position: relative; overflow: hidden; box-shadow: 0 10px 25px rgba(30,41,59,0.2); box-sizing:border-box;}
        .hero-balance .text-area { z-index: 2; position: relative; }
        .hero-balance h2 { font-size: 3.5rem; font-weight: 800; margin: 5px 0; }
        .hero-balance p { color: #94a3b8; font-weight: 500; font-size: 1rem; }
        .hero-balance i { position: absolute; right: -20px; top: 50%; transform: translateY(-50%); font-size: 12rem; color: rgba(255,255,255,0.03); z-index: 1;}

        .form-group { margin-bottom: 15px; position: relative; width:100%;}
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 5px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9rem; outline: none; background: #f8fafc; box-sizing:border-box;}
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { border-color: #e4441b; background: white; box-shadow: 0 0 0 3px rgba(228,68,27,0.1);}
        
        .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:15px; width:100%; }
        .col-span-2 { grid-column: span 2; }

        .autocomplete-items { position: absolute; border: 1px solid #e2e8f0; border-top: none; z-index: 99; top: 100%; left: 0; right: 0; border-radius: 0 0 10px 10px; background-color: #fff; max-height: 200px; overflow-y: auto; box-shadow: 0 10px 15px rgba(0,0,0,0.1); display: none; }
        .autocomplete-items div { padding: 10px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; color: #1e293b; }
        .autocomplete-items div:hover { background-color: #fef3c7; color: #d97706; font-weight: 600; }
        
        .map-container { height: 280px; width:100%; background: #e2e8f0; border-radius: 12px; margin-bottom: 8px; border: 1px solid #cbd5e1; z-index:1;}
        .map-hint-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:8px; }
        .map-hint-bar button { border:0; border-radius:10px; padding:10px 12px; font-weight:700; font-size:0.78rem; cursor:pointer; }
        .map-hint-bar .btn-mapa-grande { background:#0f172a; color:#fff; flex:1; min-width:140px; }
        .map-hint-bar .btn-centrar { background:#fff7ed; color:#c2410c; }
        #texto-dir-destino { font-size:0.82rem; font-weight:600; color:#1e293b; margin:4px 0 10px; line-height:1.35; min-height:1.2em; }
        #mapa-fullscreen { display:none; position:fixed; inset:0; z-index:5000; background:#0f172a; flex-direction:column; }
        #mapa-fullscreen.on { display:flex; }
        #mapa-fullscreen .fs-top { display:flex; gap:10px; align-items:center; padding:12px 14px; background:#0f172a; color:#fff; }
        #mapa-fullscreen .fs-top button { border:0; background:#1e293b; color:#fff; width:40px; height:40px; border-radius:12px; font-size:1.2rem; cursor:pointer; }
        #mapa-fullscreen .fs-top input { flex:1; border:0; border-radius:12px; padding:12px 14px; font-size:0.9rem; }
        #mapa-fs { flex:1; min-height:0; background:#e2e8f0; position:relative; }
        .fs-crosshair {
            position:absolute; left:50%; top:50%; width:44px; height:44px; margin:-44px 0 0 -22px;
            z-index:650; pointer-events:none; display:flex; align-items:flex-end; justify-content:center;
            filter:drop-shadow(0 3px 6px rgba(0,0,0,.35));
            transition: transform .12s ease;
        }
        .fs-crosshair::before {
            content:''; width:28px; height:28px; border-radius:50% 50% 50% 0; transform:rotate(-45deg);
            background:#e4441b; border:3px solid #fff;
        }
        #mapa-fullscreen.dragging .fs-crosshair { transform: translateY(-10px); }
        #mapa-fullscreen .fs-bottom { padding:14px 16px 22px; background:#fff; border-radius:18px 18px 0 0; }
        #mapa-fullscreen .fs-bottom p { margin:0 0 10px; font-size:0.85rem; color:#334155; font-weight:600; }
        #mapa-fullscreen .fs-bottom .fs-hint { font-size:0.72rem; color:#94a3b8; font-weight:500; margin:-4px 0 12px; }
        #mapa-fullscreen .fs-bottom .btn-ok { width:100%; border:0; background:#e4441b; color:#fff; font-weight:800; padding:14px; border-radius:14px; font-size:1rem; cursor:pointer; }
        #fs-sugerencias { position:absolute; left:14px; right:14px; top:64px; background:#fff; border-radius:12px; max-height:180px; overflow:auto; z-index:10; display:none; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        #fs-sugerencias div { padding:12px; border-bottom:1px solid #f1f5f9; font-size:0.82rem; cursor:pointer; }
        @media (min-width: 901px) {
            .map-container { height: 340px; }
        }
        @media (max-width: 900px) {
            .map-container { height: 52vw; min-height: 260px; max-height: 360px; }
        }
        .caja-precio { background: #fff5f5; border: 1px dashed #fca5a5; padding: 15px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;}
        
        .btn-primary { background: linear-gradient(135deg, #e4441b 0%, #c23310 100%); color: white; width: 100%; padding: 14px; border: none; border-radius: 10px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(228,68,27,0.2);}
        .btn-primary:active { transform: scale(0.98); }
        
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius:8px;}
        table { width: 100%; border-collapse: collapse; min-width: 600px;}
        th, td { text-align: left; padding: 14px 12px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem;}
        th { color: #64748b; font-weight: 600; font-size: 0.8rem; text-transform: uppercase;}
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.8); z-index: 9999; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 450px; max-width: 90%; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.2); max-height: 90vh; overflow-y:auto; box-sizing:border-box;}
        .btn-close { position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;}

        @media (max-width: 850px) {
            body { flex-direction: column; overflow-y: hidden;}
            .sidebar { display: none; } 
            .bottom-nav { display: flex; }
            .main-wrapper { padding-bottom: 80px; }
            .top-header { flex-direction: column; gap: 15px; padding: 20px 15px 10px !important; align-items: center; text-align: center;}
            .container { padding: 15px 15px !important; gap: 20px !important; }
            .box { padding: 20px 15px !important; }
            .flex-layout { flex-direction: column; gap: 20px; }
            .col-side { width: 100% !important; }
            .col-main { width: 100% !important; }
            .stats-grid { grid-template-columns: 1fr 1fr !important; gap: 15px !important; }
            .hero-balance { flex-direction: column; text-align: center; gap: 15px; padding: 25px 20px !important;}
            .hero-balance h2 { font-size: 2.5rem !important; }
            .hero-balance .text-area { width: 100%; }
            .hero-balance i { display: none; }
            .grid-2 { grid-template-columns: 1fr !important; }
            .col-span-2 { grid-column: span 1 !important; }
            .table-responsive button { padding: 6px 8px; font-size: 0.7rem; }
        }
    </style>
</head>
<body>

    <!-- BARRA LATERAL (PC) -->
    <aside class="sidebar">
        <div class="logo-area">
            <img src="<?php echo yora_h($logo_comercio); ?>" id="sidebar-logo" alt="Logo Comercio" onerror="this.onerror=null;this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png';">
            <h2><?php echo htmlspecialchars($nombre_comercio); ?></h2>
            <p style="background:<?php echo $nivel_bg; ?>; color:<?php echo $nivel_color; ?>;"><i class="<?php echo $nivel_icono; ?>"></i> Nivel <?php echo $nivel_nombre; ?></p>
        </div>
        <nav class="nav-menu">
            <a class="nav-item active" onclick="cambiarTab('dashboard', this)"><i class="ph ph-rocket-launch"></i> <span>Inicio y Envíos</span></a>
            <a class="nav-item" href="mandadito.php"><i class="ph ph-bag"></i> <span>Mandadito</span></a>
            <a class="nav-item" onclick="cambiarTab('historial', this); cargarHistorial();"><i class="ph ph-clock-counter-clockwise"></i> <span>Últimos Pedidos</span></a>
            <a class="nav-item" onclick="cambiarTab('recargas', this)" id="nav-recargas"><i class="ph ph-wallet"></i> <span>Crédito y pagos</span></a>
            <a class="nav-item" onclick="cambiarTab('perfil', this)"><i class="ph ph-storefront"></i> <span>Configuración</span></a>
            <a class="nav-item" href="menu.php"><i class="ph ph-fork-knife"></i> <span>Mi menú</span></a>
            <a href="logout.php" class="nav-item" style="color:#dc2626;"><i class="ph ph-sign-out"></i> <span>Salir</span></a>
        </nav>
    </aside>

    <!-- NAVBAR INFERIOR (MÓVILES) -->
    <nav class="bottom-nav">
        <a class="nav-item active" onclick="cambiarTab('dashboard', this)"><i class="ph ph-rocket-launch"></i> Inicio</a>
        <a class="nav-item" href="mandadito.php"><i class="ph ph-bag"></i> Mandadito</a>
        <a class="nav-item" onclick="cambiarTab('historial', this); cargarHistorial();"><i class="ph ph-clock-counter-clockwise"></i> Pedidos</a>
        <a class="nav-item" onclick="cambiarTab('recargas', this)"><i class="ph ph-wallet"></i> Crédito</a>
        <a class="nav-item" href="menu.php"><i class="ph ph-fork-knife"></i> Menú</a>
        <a class="nav-item" onclick="cambiarTab('perfil', this)"><i class="ph ph-storefront"></i> Perfil</a>
    </nav>

    <main class="main-wrapper">
        <header class="top-header">
            <div>
                <h1>Bienvenido a Yora</h1>
                <p>Panel administrativo central • Hoy, <?php echo $fecha_hoy; ?></p>
            </div>
            <div style="text-align:right;">
                <!-- 🔥 AQUÍ ESTÁ EL BOTÓN DE ESTADO INTELIGENTE 🔥 -->
                <span style="background:<?php echo yora_comercio_esta_abierto($comercio) ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo yora_comercio_esta_abierto($comercio) ? '#166534' : '#991b1b'; ?>; padding:6px 12px; border-radius:20px; font-weight:700; font-size:0.85rem; margin-right:8px;">
                    <?php echo yora_comercio_esta_abierto($comercio) ? '● Abierto' : '○ Cerrado'; ?>
                </span>
                <span style="background:<?php echo $badge_bg; ?>; color:<?php echo $badge_color; ?>; padding:6px 12px; border-radius:20px; font-weight:700; font-size:0.85rem;">
                    <i class="ph <?php echo $badge_icon; ?>" style="vertical-align:middle;"></i> Cuenta <?php echo $estado_doc; ?>
                </span>
            </div>
        </header>

        <!-- DASHBOARD -->
        <div id="tab-dashboard" class="container active">
           <div class="stats-grid">
                <div class="stat-card">
                    <div class="info"><p>En Curso</p><h3 id="stat-top-activos"><?php echo $pedidos_activos; ?></h3></div>
                    <div class="icon" style="background:#e0e7ff; color:#2563eb;"><i class="ph ph-motorcycle"></i></div>
                </div>
                <div class="stat-card">
                    <div class="info"><p>Entregados Hoy</p><h3 id="stat-top-hoy"><?php echo $entregados_hoy; ?></h3></div>
                    <div class="icon" style="background:#dcfce7; color:#16a34a;"><i class="ph ph-check-circle"></i></div>
                </div>
                <div class="stat-card">
                    <div class="info"><p>Histórico</p><h3 id="stat-top-historico"><?php echo $total_historico; ?></h3></div>
                    <div class="icon" style="background:#f3e8ff; color:#9333ea;"><i class="ph ph-stack"></i></div>
                </div>
                <div class="stat-card">
                    <div class="info"><p>Verificación</p><h3 style="font-size:1rem; margin-top:5px; color:<?php echo $badge_color; ?>;"><?php echo $estado_doc; ?></h3></div>
                    <div class="icon" style="background:<?php echo $badge_bg; ?>; color:<?php echo $badge_color; ?>;"><i class="ph <?php echo $badge_icon; ?>"></i></div>
                </div>
            </div>

            <?php if (!yora_comercio_esta_abierto($comercio)): ?>
            <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:14px 18px; border-radius:14px; margin-bottom:18px; font-weight:700;">
                El local está cerrado según tu horario. No se pueden crear envíos hasta la hora de apertura.
            </div>
            <?php endif; ?>

            <?php if ($bono_acreditado > 0): ?>
            <div style="background:#dcfce7; border:1px solid #22c55e; color:#166534; padding:16px 20px; border-radius:14px; margin-bottom:20px; display:flex; align-items:center; gap:14px;">
                <span style="font-size:1.8rem;">🎁</span>
                <div>
                    <b style="font-size:1rem;">¡Bono de <?php echo $nivel_nombre; ?> acreditado!</b><br>
                    <span style="font-size:0.85rem;">Te regalamos $<?php echo number_format($bono_acreditado, 2); ?> en tu billetera por tu nivel de este mes.</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($deuda['bloqueado']): ?>
            <div style="background:#fee2e2; border:1px solid #ef4444; color:#991b1b; padding:16px 20px; border-radius:14px; margin-bottom:20px;">
                <b style="font-size:1rem;">Cuenta bloqueada por factura vencida</b><br>
                <span style="font-size:0.85rem;">Debes $<?php echo number_format($deuda['deuda'], 2); ?>. Reporta el pago (+ $<?php echo number_format(3, 2); ?> de reactivación si aplica) para despachar de nuevo.</span>
            </div>
            <?php elseif (($credito['facturas'] ?? 0) > 0): ?>
            <div style="background:#fef3c7; border:1px solid #f59e0b; color:#92400e; padding:16px 20px; border-radius:14px; margin-bottom:20px;">
                <b style="font-size:1rem;">Facturas por pagar</b><br>
                <span style="font-size:0.85rem;">Abiertas: $<?php echo number_format((float) $credito['facturas'], 2); ?>. Disponible: $<?php echo number_format((float) $credito['disponible'], 2); ?> / límite $<?php echo number_format((float) $credito['limite'], 2); ?>.</span>
            </div>
            <?php endif; ?>

            <!-- 🔥 WIDGET DEL CLUB YORA (NIVELES) 🔥 -->
            <div class="level-widget">
                <div class="level-header">
                    <h3>
                        <i class="<?php echo $nivel_icono; ?>" style="color:<?php echo yora_h($nivel_color); ?>;"></i>
                        Club Yora: <?php echo yora_h($nivel_nombre); ?>
                        <?php echo str_repeat('★', $nivel_estrellas); ?>
                    </h3>
                    <span style="font-weight:700; font-size:0.9rem; color:var(--text-muted);">
                        <?php echo $pedidos_mes; ?> entregados este mes
                        <?php if ($nivel['pedidos_hasta'] !== null && $nivel['siguiente_nombre'] !== ''): ?>
                            · faltan <?php echo max(0, $meta_nivel - $pedidos_mes); ?> para <?php echo yora_h($nivel['siguiente_nombre']); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="progress-container">
                    <div class="progress-bar" style="width: <?php echo $progreso_porcentaje; ?>%;"></div>
                </div>
                <div class="level-benefits">
                    <b>Beneficios activos:</b>
                    <ul style="margin:8px 0 0; padding-left:20px;">
                        <?php foreach ($beneficios as $b): ?>
                            <li style="margin-bottom:4px;"><?php echo (stripos($b, '$') !== false && stripos($b, 'gratis') !== false) ? '🎁 ' : ''; ?><?php echo yora_h($b); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="hero-balance">
                <i class="ph ph-credit-card"></i>
                <div class="text-area">
                    <p>Crédito disponible</p>
                    <h2>$<span id="texto-saldo-navbar"><?php echo number_format($saldo_prepagado, 2); ?></span></h2>
                    <p style="font-size:0.8rem;opacity:.75;margin-top:4px;">Límite $<?php echo number_format((float) ($credito['limite'] ?? 0), 2); ?> · Facturas $<?php echo number_format((float) ($credito['facturas'] ?? 0), 2); ?></p>
                </div>
                <div class="text-area" style="max-width:300px;">
                    <button onclick="cambiarTab('recargas', document.getElementById('nav-recargas'))" style="background:var(--yora-orange); color:white; border:none; padding:12px 25px; border-radius:12px; font-weight:700; font-size:1rem; cursor:pointer; width:100%; box-shadow:0 4px 15px rgba(228,68,27,0.3);">Pagar facturas</button>
                </div>
            </div>

            <div class="flex-layout">
                <div class="col-side text-left">
                    <div class="box" id="box-formulario">
                        <h2 class="box-title" id="form-titulo">Solicitar YoraDelivery</h2>
                        <a href="mandadito.php" style="display:flex; align-items:center; gap:10px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; text-decoration:none; border-radius:12px; padding:10px 12px; margin-bottom:16px; font-size:0.85rem; font-weight:600;">
                            <i class="ph ph-bag" style="font-size:1.3rem;"></i>
                            <span>¿Necesitas que un motorizado te compre o recoja algo? Abre <b>Mandadito</b>.</span>
                        </a>
                        
                        <div class="form-group" style="margin-bottom:8px;">
                            <label>🔎 Busca la dirección del cliente (Barquisimeto / Lara)</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="buscar_direccion" placeholder="Ej: carrera 19 con calle 25, Sambil, Trinitarias…" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault(); buscarDireccion();}" oninput="buscarDireccionLive()">
                                <button type="button" onclick="buscarDireccion()" id="btn-buscar-dir" style="background:#1e293b; color:white; border:none; padding:0 16px; border-radius:10px; cursor:pointer; font-weight:600; flex-shrink:0;"><i class="ph ph-magnifying-glass"></i></button>
                            </div>
                            <div id="resultados-direccion" class="autocomplete-items"></div>
                        </div>

                        <div class="form-group">
                            <label>📍 Destino del cliente</label>
                            <div class="map-hint-bar">
                                <button type="button" class="btn-mapa-grande" onclick="abrirMapaGrande()"><i class="ph ph-arrows-out"></i> Mapa grande (fácil)</button>
                                <button type="button" class="btn-centrar" onclick="centrarEnLocal()"><i class="ph ph-crosshair"></i> Mi local</button>
                            </div>
                            <div id="map-solicitud" class="map-container"></div>
                            <p id="texto-dir-destino">Busca arriba o abre el mapa grande y mueve el mapa hasta el destino</p>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px; gap:10px;">
                                <span style="font-size:0.72rem; color:#94a3b8;" id="coords-destino"></span>
                            </div>
                        </div>

                        <div class="caja-precio">
                            <div>
                                <p style="font-size:0.85rem; font-weight:700; color:#1e293b; margin-bottom:2px;" id="texto-distancia">Ruta: mueve el pin al destino</p>
                                <p style="font-size:0.75rem; color:#94a3b8;" id="texto-tarifa">Costo $<?php echo number_format($precio_km_actual, 2); ?>/km (Mín $<?php echo number_format($tarifa_minima, 2); ?>)</p>
                            </div>
                            <h3 id="texto-precio">$0.00</h3>
                        </div>

                        <div class="form-group">
                            <label>Cédula del cliente</label>
                            <input type="text" id="c_cedula" placeholder="V-25951632" autocomplete="off" oninput="programarCargaCliente()" onchange="cargarClientePorCedula()" onblur="cargarClientePorCedula()">
                            <span id="aviso-cliente" style="display:none; font-size:0.75rem; font-weight:600; color:#16a34a; margin-top:4px;"></span>
                        </div>

                        <div class="form-group">
                            <label>Nombre y apellido</label>
                            <input type="text" id="c_nombre" placeholder="Ej: Héctor Gallardo" autocomplete="off" onkeyup="buscarCliente(this.value)">
                            <div id="autocomplete-list" class="autocomplete-items"></div>
                        </div>

                        <div class="form-group"><label>Teléfono</label><input type="tel" id="c_telefono" placeholder="0414-1234567"></div>

                        <div class="form-group"><label>🏡 Una de referencia (Ejemplo: Casa rosada)</label><input type="text" id="c_direccion" placeholder="Ej: Portón negro, calle cerrada..."></div>
                        <div class="form-group"><label>📦 Contenido del Paquete</label><textarea id="c_detalles" rows="2" placeholder="Ej: 2 Pizzas Familiares"></textarea></div>
                        
                        <div class="form-group">
                            <label>⏳ Tiempo de preparación:</label>
                            <select id="c_tiempo">
                                <option value="0">¡Ya está listo para retirar!</option>
                                <option value="5">Estará listo en 5 minutos</option>
                                <option value="10">Estará listo en 10 minutos</option>
                                <option value="15">Estará listo en 15 minutos (máx)</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-primary" onclick="cancelarEdicion()" id="btn-cancelar-edicion" style="display:none; background:#9ca3af; box-shadow:none; flex:1;">Volver</button>
                            <button class="btn-primary" onclick="procesarFormulario()" id="btn-solicitar" style="flex:2;"><i class="ph ph-paper-plane-tilt"></i> Enviar al Aire</button>
                        </div>
                    </div>
                </div>

                <div class="col-main">
                    <div class="box">
                        <h2 class="box-title" style="display:flex; justify-content:space-between; align-items:center;">
                            <span>Envíos Activos <span style="color:#e4441b; animation:pulse 1.5s infinite;">●</span></span>
                        </h2>
                        <div id="lista-pedidos-activos">Cargando envíos...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- HISTORIAL -->
        <div id="tab-historial" class="container">
            <div class="box flex-layout" style="align-items:flex-end; gap:15px; padding:20px;">
                <div class="form-group" style="margin-bottom:0; flex:1;">
                    <label>Desde</label>
                    <input type="date" id="h_desde" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0; flex:1;">
                    <label>Hasta</label>
                    <input type="date" id="h_hasta" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-side" style="width:auto;"><button class="btn-primary" style="margin-bottom:0; margin-top:0;" onclick="cargarHistorial()"><i class="ph ph-funnel"></i> Filtrar</button></div>
            </div>

            <div class="flex-layout">
                <div class="box col-main text-center">
                    <h3 id="stat-pedidos" style="font-size:2.5rem; color:var(--yora-orange); margin-bottom:5px;">0</h3>
                    <p style="color:#64748b; font-weight:600; font-size:0.9rem;">Pedidos Entregados</p>
                </div>
                <div class="box col-main text-center">
                    <h3 id="stat-gastado" style="font-size:2.5rem; color:#10b981; margin-bottom:5px;">$0.00</h3>
                    <p style="color:#64748b; font-weight:600; font-size:0.9rem;">Inversión Total</p>
                </div>
            </div>

            <div class="box">
                <h2 class="box-title">Registro General de Envíos</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Orden #</th>
                                <th>Fecha y Hora</th>
                                <th>Cliente</th>
                                <th>Estatus</th>
                                <th>Costo</th>
                                <th>Opciones</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-historial">
                            <tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:30px;">Cargando historial...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RECARGAS -->
        <div id="tab-recargas" class="container">
            <div class="flex-layout">
                <div class="col-side text-left">
                    <div class="box">
                        <h2 class="box-title">Reportar pago de factura</h2>
                        <p style="font-size:0.85rem; color:#64748b; margin-bottom:15px;">Tipo <b><?php echo yora_h($tipo_local); ?></b>. Límite de crédito: <b style="color:var(--yora-orange);">$<?php echo number_format((float) ($credito['limite'] ?? 0), 2); ?></b>. El pago libera crédito al aprobarse en HQ.</p>

                        <div class="form-group">
                            <label>Factura a pagar</label>
                            <select id="r_factura" onchange="syncMontoFactura()">
                                <option value="0">Pago general (HQ aplica FIFO)</option>
                                <?php foreach ($facturas_abiertas as $f): ?>
                                <option value="<?php echo (int) $f['id']; ?>"
                                    data-monto="<?php echo number_format((float) $f['monto'] + (float) ($f['penalizacion'] ?? 0), 2, '.', ''); ?>">
                                    #<?php echo (int) $f['id']; ?> · <?php echo yora_h($f['fecha_consumo']); ?> · $<?php echo number_format((float) $f['monto'], 2); ?> · <?php echo yora_h($f['estatus']); ?> · vence <?php echo yora_h($f['vence_el']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo de Pago</label>
                            <select id="r_banco" onchange="cambiarDatosPago()">
                                <option value="">Selecciona un método...</option>
                                <option value="Pago Móvil">Pago Móvil (Bs)</option>
                                <option value="Efectivo">Efectivo ($)</option>
                                <option value="Zelle">Zelle ($)</option>
                                <option value="Binance">Binance USDT</option>
                                <option value="Paypal">PayPal ($)</option>
                            </select>
                        </div>

                        <div id="info-pago-movil" style="display:none; background:#f0fdf4; padding:15px; border-radius:10px; border:1px solid #bbf7d0; margin-bottom:15px; font-size:0.85rem;">
                            <p style="margin:0 0 5px;">🏦 <b>Bancaribe</b> (0114)</p>
                            <p style="margin:0 0 5px; display:flex; justify-content:space-between;">📱 0414-5530182 <span style="color:#16a34a; font-weight:bold; cursor:pointer;" onclick="navigator.clipboard.writeText('04145530182'); alert('Copiado');">Copiar</span></p>
                            <p style="margin:0; display:flex; justify-content:space-between;">🪪 V-25.951.632 <span style="color:#16a34a; font-weight:bold; cursor:pointer;" onclick="navigator.clipboard.writeText('25951632'); alert('Copiado');">Copiar</span></p>
                        </div>
                        <div id="info-efectivo" style="display:none; background:#f8fafc; padding:15px; border-radius:10px; border:1px dashed #cbd5e1; margin-bottom:15px; font-size:0.85rem;"><p style="margin:0 0 5px;">📍 <b>Oficina Central Yora</b></p><p style="margin:0; color:#475569;">Carrera 17 con calle 27 y 28.</p></div>
                        <div id="info-zelle" style="display:none; background:#faf5ff; padding:15px; border-radius:10px; border:1px solid #e9d5ff; margin-bottom:15px; font-size:0.85rem;"><p style="margin:0; display:flex; justify-content:space-between;">🟣 <b>+1 (801) 651-7480</b> <span style="color:#9333ea; font-weight:bold; cursor:pointer;" onclick="navigator.clipboard.writeText('+18016517480'); alert('Copiado');">Copiar</span></p></div>
                        <div id="info-binance" style="display:none; background:#fffbeb; padding:15px; border-radius:10px; border:1px solid #fde68a; margin-bottom:15px; font-size:0.85rem;"><p style="margin:0; display:flex; justify-content:space-between;">🟡 <b>saraisalvarez29@gmail.com</b> <span style="color:#d97706; font-weight:bold; cursor:pointer;" onclick="navigator.clipboard.writeText('saraisalvarez29@gmail.com'); alert('Copiado');">Copiar</span></p></div>
                        <div id="info-paypal" style="display:none; background:#eff6ff; padding:15px; border-radius:10px; border:1px solid #bfdbfe; margin-bottom:15px; font-size:0.85rem;"><p style="margin:0; display:flex; justify-content:space-between;">🔵 <b>soylarensecontacto@gmail.com</b> <span style="color:#2563eb; font-weight:bold; cursor:pointer;" onclick="navigator.clipboard.writeText('soylarensecontacto@gmail.com'); alert('Copiado');">Copiar</span></p></div>

                        <div class="grid-2">
                            <div class="form-group"><label>Monto ($)</label><input type="number" id="r_monto" step="0.01" onkeyup="calcularBsRecarga()"></div>
                            <div class="form-group" id="caja-monto-bs" style="display:none;"><label>Transferir (Bs)</label><input type="text" id="r_monto_bs" readonly style="background:#f0fdf4; color:#16a34a; font-weight:bold; border-color:#bbf7d0;"></div>
                        </div>

                        <div class="form-group"><label>Referencia</label><input type="text" id="r_referencia" placeholder="Obligatorio para verificar"></div>
                        <button class="btn-primary" onclick="enviarRecarga()" id="btn-recargar" style="background:#10b981; margin-top:10px;">Enviar Reporte</button>
                    </div>
                </div>
                <div class="col-main">
                    <div class="box">
                        <h2 class="box-title">Historial de pagos</h2>
                        <div class="table-responsive">
                            <table>
                                <thead><tr><th>Fecha</th><th>Monto</th><th>Método / Ref</th><th>Estatus</th></tr></thead>
                                <tbody id="tabla-mis-recargas"><tr><td colspan="4" style="text-align:center; padding:30px;">Cargando...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PERFIL, VERIFICACIÓN Y CONTRASEÑA -->
        <div id="tab-perfil" class="container">
            <div class="box text-left">
                <h2 class="box-title">Configuración del Comercio</h2>
                <div class="flex-layout" style="align-items:center;">
                    <div class="col-side" style="width:150px; text-align:center;">
                        <img id="imgLogoActual" src="<?php echo yora_h($logo_comercio); ?>" alt="Logo" onerror="this.onerror=null;this.src='https://cdn-icons-png.flaticon.com/512/819/819814.png';" style="width:120px; height:120px; border-radius:16px; object-fit:cover; border:2px solid #e2e8f0; margin-bottom:10px;">
                        <input type="file" id="inputLogo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" style="display:none;" onchange="subirLogoComercio(this)">
                        <button type="button" onclick="document.getElementById('inputLogo').click()" style="background:white; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:0.8rem; width: 100%;">Cambiar Logo</button>
                        <p id="logo-msg" style="font-size:0.75rem; color:#64748b; margin:8px 0 0; line-height:1.3;">JPG o PNG, máximo 5 MB. Se guarda al instante.</p>
                    </div>
                    <div class="col-main grid-2">
                        <div class="form-group"><label>Nombre Comercial</label><input type="text" id="p_nombre" value="<?php echo htmlspecialchars($nombre_comercio); ?>"></div>
                        <div class="form-group"><label>RIF</label><input type="text" id="p_rif" value="<?php echo htmlspecialchars($rif); ?>"></div>
                        <div class="form-group col-span-2"><label>Dirección Matriz</label><input type="text" id="p_direccion" value="<?php echo htmlspecialchars($direccion_local); ?>"></div>
                    </div>
                </div>
                
                <h4 style="margin:25px 0 10px; color:#1e293b; font-size:1rem;">🕐 Horario de trabajo</h4>
                <p style="color:#64748b; font-size:0.82rem; margin-bottom:10px;">El local se abre y se cierra solo todos los días a estas horas (hora de Venezuela). Fuera de horario no se pueden crear envíos. Pulsa <b>Guardar Cambios</b> para aplicarlas.</p>
                <?php
                    $ha_db = yora_hora_sql((string) ($comercio['hora_abre'] ?? ''));
                    $hc_db = yora_hora_sql((string) ($comercio['hora_cierra'] ?? ''));
                    $tiene_horario = ($ha_db !== '' && $hc_db !== '');
                ?>
                <div class="grid-2">
                    <div class="form-group"><label>Abre</label><input type="time" id="p_abre" value="<?php echo yora_h($tiene_horario ? substr($ha_db, 0, 5) : '08:00'); ?>"></div>
                    <div class="form-group"><label>Cierra</label><input type="time" id="p_cierra" value="<?php echo yora_h($tiene_horario ? substr($hc_db, 0, 5) : '22:00'); ?>"></div>
                </div>
                <?php $ahora_abierto = yora_comercio_esta_abierto($comercio); ?>
                <p style="font-size:0.82rem; font-weight:700; color:<?php echo $ahora_abierto ? '#16a34a' : '#dc2626'; ?>; margin-bottom:8px;">
                    Ahora mismo: <?php echo $ahora_abierto ? 'Abierto' : 'Cerrado'; ?>
                    <?php if ($tiene_horario): ?>
                        <span style="font-weight:500; color:#64748b;">(<?php echo yora_h(substr($ha_db, 0, 5) . '–' . substr($hc_db, 0, 5)); ?>)</span>
                    <?php endif; ?>
                </p>
                <?php if (!$tiene_horario): ?>
                    <p style="font-size:0.8rem; color:#b45309; background:#fffbeb; border:1px solid #fde68a; padding:8px 10px; border-radius:8px; margin-bottom:18px;">Aún no hay horario guardado: el local aparece <b>abierto todo el día</b>. Elige hora de apertura y cierre y pulsa Guardar Cambios.</p>
                <?php else: ?>
                    <p style="margin-bottom:18px;"></p>
                <?php endif; ?>

                <h4 style="margin:25px 0 10px; color:#1e293b; font-size:1rem;">📍 Ubicación Matriz en Mapa</h4>
                <div id="map-perfil" class="map-container" style="height: 250px;"></div>
                <div style="display:none;"><input type="hidden" id="p_lat" value="<?php echo $rest_lat; ?>"><input type="hidden" id="p_lng" value="<?php echo $rest_lng; ?>"></div>

                <hr style="border:0; border-top:1px solid #e2e8f0; margin:30px 0;">
                <h4 style="margin-bottom:8px; color:#1e293b; font-size:1rem;"><i class="ph ph-fork-knife"></i> Menú del directorio</h4>
                <p style="color:#64748b; font-size:0.85rem; margin-bottom:12px;">Controla los platos que salen en yoradelivery.com. El cliente arma el carrito y el pedido te llega por WhatsApp.</p>
                <a href="menu.php" class="btn-primary" style="display:inline-flex; width:auto; padding:12px 18px; text-decoration:none;"><i class="ph ph-pencil-simple"></i> Gestionar mi menú</a>

                <hr style="border:0; border-top:1px solid #e2e8f0; margin:30px 0;">

                <!-- VERIFICACIÓN DE CUENTA -->
                <h4 style="margin-bottom:8px; color:#1e293b; font-size:1rem;"><i class="ph ph-shield-check"></i> Verificación de Cuenta</h4>
                <p style="color:#64748b; font-size:0.85rem; margin-bottom:12px;">Sube tu RIF digitalizado o Cédula (JPG, PNG o PDF, máx. 5 MB). El documento llega al panel HQ de Yora para revisión.</p>
                <?php
                    $est_bd = trim((string) ($comercio['estado_documentos'] ?? 'Pendiente'));
                    $caja_bg = '#fef2f2'; $caja_bd = '#fecaca'; $caja_tx = '#991b1b';
                    $caja_txt = 'Aún no has enviado documento. Elige el archivo y pulsa Enviar a verificación.';
                    if ($est_bd === 'Verificado') {
                        $caja_bg = '#f0fdf4'; $caja_bd = '#bbf7d0'; $caja_tx = '#166534';
                        $caja_txt = 'Cuenta verificada.';
                    } elseif ($est_bd === 'En Revisión' || $est_bd === 'En Revision') {
                        $caja_bg = '#fffbeb'; $caja_bd = '#fde68a'; $caja_tx = '#92400e';
                        $caja_txt = 'Documento en revisión. Yora lo verá en el panel HQ.';
                    } elseif ($est_bd === 'Rechazado') {
                        $caja_txt = 'El documento fue rechazado. Sube uno nuevo y envíalo otra vez.';
                    } elseif ($doc_url !== '') {
                        $caja_bg = '#fffbeb'; $caja_bd = '#fde68a'; $caja_tx = '#92400e';
                        $caja_txt = 'Ya hay un documento cargado. Si no aparece en HQ, pulsa Enviar a verificación de nuevo.';
                    }
                ?>
                <div style="background:<?php echo $caja_bg; ?>; border:1px solid <?php echo $caja_bd; ?>; color:<?php echo $caja_tx; ?>; border-radius:10px; padding:10px 12px; font-size:0.85rem; font-weight:600; margin-bottom:12px;">
                    Estado: <?php echo yora_h($est_bd === 'Pendiente' ? 'Pendiente' : $est_bd); ?>. <?php echo yora_h($caja_txt); ?>
                    <?php if ($doc_url !== ''): ?>
                        <br><a href="<?php echo yora_h($doc_url); ?>" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline;">Ver archivo enviado</a>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <input type="file" id="p_documento" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf" style="background:white; padding:10px;">
                </div>
                <button type="button" class="btn-primary" style="display:inline-flex; width:auto; padding:12px 18px; margin-top:4px;" onclick="enviarDocumento()" id="btn-enviar-doc"><i class="ph ph-upload-simple"></i> Enviar a verificación</button>

                <hr style="border:0; border-top:1px solid #e2e8f0; margin:30px 0;">

                <!-- CAMBIO DE CONTRASEÑA -->
                <h4 style="margin-bottom:15px; color:#1e293b; font-size:1rem;"><i class="ph ph-lock-key"></i> Cambiar Contraseña de Acceso</h4>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Contraseña Actual</label>
                        <input type="password" id="p_pass_actual" placeholder="Solo si vas a cambiarla" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label>Nueva Contraseña</label>
                        <input type="password" id="p_pass_nueva" placeholder="Dejar en blanco para mantener la actual" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Confirmar Nueva Contraseña</label>
                        <input type="password" id="p_pass_confirmar" placeholder="Repite la contraseña" autocomplete="new-password">
                    </div>
                </div>

                <div style="text-align:right;">
                    <button class="btn-primary" style="max-width:200px; margin-top:15px;" onclick="guardarPerfil()" id="btn-guardar-perfil">Guardar Cambios</button>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL PEDIDOS ACTIVOS -->
    <div id="modal-pedido" class="modal-overlay">
        <div class="modal-content">
            <button class="btn-close" onclick="cerrarModal()">&times;</button>
            <h3 id="modal-titulo" style="margin-bottom:15px; color:#1e293b; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Detalles del Pedido</h3>
            <div id="modal-contenido" style="margin-bottom:20px; color:#475569; font-size:0.95rem; line-height:1.6;"></div>
            <div id="modal-acciones" style="display:flex; gap:10px; flex-direction:column;"></div>
        </div>
    </div>

    <!-- MODAL FACTURA -->
    <div id="modal-factura" class="modal-overlay">
        <div class="modal-content" style="text-align:center;">
            <button class="btn-close" onclick="document.getElementById('modal-factura').style.display='none';">&times;</button>
            <div style="background:#e4441b; color:white; margin:-30px -30px 20px; padding:22px 20px; border-radius:20px 20px 0 0;">
                <p style="margin:0; font-size:0.75rem; letter-spacing:1px; opacity:0.85;">YORA DELIVERY</p>
                <h2 style="margin:4px 0 0; font-size:1.6rem;">Factura #<span id="f_orden"></span></h2>
            </div>
            <p style="color:#64748b; font-size:0.85rem; margin-bottom:20px;" id="f_fecha"></p>
            
            <div style="text-align:left; background:#f8fafc; padding:20px; border-radius:12px; border:1px dashed #cbd5e1;">
                <p style="margin-bottom:8px;">🧑 <b>Cliente:</b> <span id="f_cliente"></span></p>
                <p style="margin-bottom:8px;">📍 <b>Destino:</b> <span id="f_detalles"></span></p>
                <p style="margin-bottom:8px;">🛵 <b>Driver Asignado:</b> <span id="f_driver"></span></p>
                <hr style="border:0; border-top:1px solid #e2e8f0; margin:15px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:1.2rem;">
                    <b>Total Pagado:</b> <span id="f_costo" style="color:#10b981; font-weight:900;"></span>
                </div>
            </div>
            <button class="btn-primary" style="margin-top:20px; background:#1e293b;" onclick="window.print()"><i class="ph ph-printer"></i> Imprimir Comprobante</button>
        </div>
    </div>

    <!-- MODAL EVIDENCIAS -->
    <div id="modal-evidencia" class="modal-overlay">
        <div class="modal-content" style="text-align:center; max-width:600px;">
            <button class="btn-close" onclick="document.getElementById('modal-evidencia').style.display='none';">&times;</button>
            <h3 style="margin-bottom:20px; color:#1e293b;">Evidencias de Entrega #<span id="e_orden"></span></h3>
            
            <div class="flex-layout" style="gap:15px;">
                <div style="flex:1; border:1px solid #e2e8f0; padding:10px; border-radius:12px; width:100%;">
                    <p style="font-weight:700; font-size:0.9rem; margin-bottom:10px; color:#475569;">📍 Recogida en Local</p>
                    <div id="e_foto_rec"></div>
                </div>
                <div style="flex:1; border:1px solid #e2e8f0; padding:10px; border-radius:12px; width:100%;">
                    <p style="font-weight:700; font-size:0.9rem; margin-bottom:10px; color:#475569;">✅ Entregado al Cliente</p>
                    <div id="e_foto_ent"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL REPORTE -->
    <div id="modal-reporte" class="modal-overlay">
        <div class="modal-content">
            <button class="btn-close" onclick="document.getElementById('modal-reporte').style.display='none';">&times;</button>
            <h3 style="margin-bottom:15px; color:#1e293b;">🚨 Reportar a Soporte</h3>
            <p style="font-size:0.85rem; color:#64748b; margin-bottom:15px;">Incidencia con la Orden <b id="rep-orden-id"></b></p>
            <input type="hidden" id="rep_comanda_id">
            <div class="form-group"><label>Motivo</label><select id="rep_motivo"><option>Pedido derramado o dañado</option><option>Driver nunca llegó al cliente</option><option>Comportamiento inadecuado del driver</option><option>El cliente canceló la compra</option></select></div>
            <div class="form-group"><label>Describe lo sucedido</label><textarea id="rep_descripcion" rows="3"></textarea></div>
            <button class="btn-primary" style="background:#dc2626;" onclick="enviarReporte()" id="btn-enviar-rep">Enviar Caso</button>
        </div>
    </div>

    <div id="modal-rastreo" class="modal-overlay">
        <div class="modal-content" style="width:640px; max-width:94%; padding:18px;">
            <button class="btn-close" onclick="cerrarRastreo()">&times;</button>
            <h3 id="rastreo-titulo" style="margin:0 0 10px; font-size:1.05rem;">Ubicación del motorizado</h3>
            <p id="rastreo-sub" style="margin:0 0 10px; font-size:0.8rem; color:#64748b;"></p>
            <div id="mapa-rastreo" style="height:320px; border-radius:14px; background:#e2e8f0;"></div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/js/yora_geo.js?v=2"></script>
    <script>
        const clientesGuardados = <?php echo $clientes_json; ?>;

        function rellenarCliente(c) {
            if (c.nombre) document.getElementById('c_nombre').value = c.nombre;
            if (c.telefono) document.getElementById('c_telefono').value = c.telefono;
            if (c.referencia) document.getElementById('c_direccion').value = c.referencia;
            if (c.cedula) document.getElementById('c_cedula').value = c.cedula;
        }

        function avisoCliente(texto) {
            const aviso = document.getElementById('aviso-cliente');
            aviso.innerText = texto || '';
            aviso.style.display = texto ? 'block' : 'none';
        }

        // Al escribir la cedula se traen los datos que ya tenemos de ese cliente
        // EN ESTE comercio. La ubicacion no se rellena: el mismo cliente puede
        // pedir a direcciones distintas y el pin debe marcarse siempre a mano.
        let ultimaCedulaBuscada = '';
        let timerCedula = null;
        function programarCargaCliente() {
            clearTimeout(timerCedula);
            timerCedula = setTimeout(cargarClientePorCedula, 400);
        }
        async function cargarClientePorCedula() {
            const cedula = document.getElementById('c_cedula').value.trim();
            if (!cedula || cedula === ultimaCedulaBuscada) return;
            ultimaCedulaBuscada = cedula;

            try {
                let res = await fetch('/api/buscar_cliente.php?cedula=' + encodeURIComponent(cedula));
                let data = await res.json();
                if (data.encontrado) {
                    rellenarCliente(data);
                    avisoCliente('✅ Cliente frecuente: ' + data.pedidos + ' pedido(s) contigo. Datos cargados.');
                } else {
                    avisoCliente('');
                }
            } catch(e) {
                avisoCliente('');
            }
        }

        function buscarCliente(val) {
            let list = document.getElementById('autocomplete-list');
            list.innerHTML = '';
            if(!val) { list.style.display = 'none'; return; }

            let matches = clientesGuardados.filter(c => (c.nombre || '').toLowerCase().includes(val.toLowerCase())).slice(0, 8);
            if(matches.length > 0) {
                list.style.display = 'block';
                matches.forEach(m => {
                    let div = document.createElement('div');
                    div.innerHTML = `<b>${escaparHtml(m.nombre)}</b> <span style="color:#94a3b8; font-size:0.75rem;">(${escaparHtml(m.telefono || 'sin teléfono')})</span>`;
                    div.onclick = function() {
                        rellenarCliente(m);
                        ultimaCedulaBuscada = m.cedula || '';
                        avisoCliente('✅ Datos cargados de tu agenda.');
                        list.style.display = 'none';
                    };
                    list.appendChild(div);
                });
            } else {
                list.style.display = 'none';
            }
        }
        
        document.addEventListener("click", function (e) {
            if (e.target.id !== "c_nombre") document.getElementById("autocomplete-list").style.display = "none";
        });

        function cambiarTab(tabId, element) {
            document.querySelectorAll('.container').forEach(c => c.style.display = 'none');
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            document.getElementById('tab-' + tabId).style.display = 'flex';
            element.classList.add('active');
            setTimeout(() => { 
                if(tabId === 'dashboard') mapSol.invalidateSize();
                if(tabId === 'perfil') mapPer.invalidateSize();
            }, 100);
        }

        const CSRF = <?php echo json_encode(yora_csrf_token()); ?>;

        // Todas las llamadas internas viajan con el token: así no dependemos
        // de que el navegador mande la cabecera Origin.
        (function () {
            const fetchOriginal = window.fetch;
            window.fetch = function (url, opciones) {
                if (typeof url === 'string' && url.startsWith('/api/')
                    && opciones && opciones.body instanceof FormData
                    && !opciones.body.has('_csrf')) {
                    opciones.body.append('_csrf', CSRF);
                }
                return fetchOriginal.apply(this, arguments);
            };
        })();

        const restLat = <?php echo $rest_lat; ?>; const restLng = <?php echo $rest_lng; ?>;
        const COMERCIO_TIENE_GPS = <?php echo $tiene_gps ? 'true' : 'false'; ?>;
        let destLat = restLat; let destLng = restLng;
        let distanciaGlobal = 0; let costoFinal = 0; let editComandaId = null;
        let cotizacionValida = false;

        const CAPA_MAPA = () => L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        });

        // Iconos distintos para que no se confunda el local con el destino.
        const iconoLocal = L.divIcon({
            html: '<div style="background:#16a34a; width:26px; height:26px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); border:3px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,.35);"></div>',
            className: '', iconSize: [26, 26], iconAnchor: [13, 26]
        });
        const iconoDestino = L.divIcon({
            html: '<div style="background:#e4441b; width:26px; height:26px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); border:3px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,.35);"></div>',
            className: '', iconSize: [26, 26], iconAnchor: [13, 26]
        });

        // ===================== MAPA DE SOLICITUD =====================
        let mapSol = L.map('map-solicitud', { zoomControl: true }).setView([restLat, restLng], 15);
        CAPA_MAPA().addTo(mapSol);
        L.marker([restLat, restLng], { icon: iconoLocal }).addTo(mapSol).bindPopup("🏠 <b>Tu Local</b>");

        let clientMarker = L.marker([restLat + 0.004, restLng + 0.004], { draggable: true, icon: iconoDestino })
            .addTo(mapSol).bindPopup("📦 <b>Destino</b><br>Arrástrame al punto exacto");
        let rutaDibujada = null;
        let temporizadorCotizar = null;

        let fsIgnorarMove = false;
        function fijarDestino(lat, lng, centrar) {
            destLat = lat; destLng = lng;
            clientMarker.setLatLng([lat, lng]);
            if (centrar) mapSol.setView([lat, lng], Math.max(mapSol.getZoom(), 16));
            if (mapFs) {
                if (centrar) {
                    fsIgnorarMove = true;
                    mapFs.setView([lat, lng], Math.max(mapFs.getZoom(), 16));
                    setTimeout(function () { fsIgnorarMove = false; }, 200);
                }
            }
            document.getElementById('coords-destino').innerText = `GPS: ${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            nombrarDestino(lat, lng);
            pedirCotizacion();
        }

        let silenciarDir = false;
        async function nombrarDestino(lat, lng) {
            const el = document.getElementById('texto-dir-destino');
            const fsTxt = document.getElementById('fs-dir-txt');
            if (el) el.textContent = 'Buscando nombre de la calle…';
            try {
                const fd = new FormData();
                fd.append('lat', lat); fd.append('lng', lng);
                const r = await fetch('/api/reverse.php', { method: 'POST', body: fd });
                const d = await r.json();
                const nombre = (d.status === 'success' && d.nombre) ? d.nombre : (lat.toFixed(5) + ', ' + lng.toFixed(5));
                if (el) el.textContent = nombre;
                if (fsTxt) fsTxt.textContent = nombre;
                window.__yoraDirMapa = nombre;
                // NO rellenar c_direccion: ese campo es solo la referencia (casa rosada, portón…).
            } catch (e) {
                if (el) el.textContent = 'Pin marcado (sin nombre de calle)';
                window.__yoraDirMapa = lat.toFixed(5) + ', ' + lng.toFixed(5);
            }
        }

        clientMarker.on('dragend', function () {
            const p = clientMarker.getLatLng();
            fijarDestino(p.lat, p.lng, false);
        });

        // Tocar el mapa también coloca el pin: en el móvil arrastrar es incómodo.
        mapSol.on('click', function (e) {
            fijarDestino(e.latlng.lat, e.latlng.lng, false);
        });

        let mapFs = null;
        function abrirMapaGrande() {
            const box = document.getElementById('mapa-fullscreen');
            box.classList.add('on');
            setTimeout(function () {
                if (!mapFs) {
                    mapFs = L.map('mapa-fs', { zoomControl: true, tap: true }).setView([destLat, destLng], 16);
                    CAPA_MAPA().addTo(mapFs);
                    L.marker([restLat, restLng], { icon: iconoLocal }).addTo(mapFs);
                    // Pin fijo al centro (estilo Uber): el usuario mueve el mapa, no el marcador.
                    mapFs.on('dragstart zoomstart', function () {
                        box.classList.add('dragging');
                    });
                    mapFs.on('moveend', function () {
                        box.classList.remove('dragging');
                        if (fsIgnorarMove) return;
                        const c = mapFs.getCenter();
                        fijarDestino(c.lat, c.lng, false);
                    });
                    mapFs.on('click', function (e) {
                        fsIgnorarMove = true;
                        mapFs.setView(e.latlng, Math.max(mapFs.getZoom(), 16));
                        fijarDestino(e.latlng.lat, e.latlng.lng, false);
                        setTimeout(function () { fsIgnorarMove = false; }, 200);
                    });
                } else {
                    fsIgnorarMove = true;
                    mapFs.setView([destLat, destLng], Math.max(mapFs.getZoom(), 16));
                    setTimeout(function () { fsIgnorarMove = false; }, 200);
                }
                mapFs.invalidateSize();
                nombrarDestino(destLat, destLng);
            }, 60);
        }
        function cerrarMapaGrande() {
            const box = document.getElementById('mapa-fullscreen');
            box.classList.remove('on');
            box.classList.remove('dragging');
            // Al cerrar, el centro del mapa grande es el destino definitivo.
            if (mapFs) {
                const c = mapFs.getCenter();
                fijarDestino(c.lat, c.lng, true);
            }
            setTimeout(function () { mapSol.invalidateSize(); }, 80);
        }
        function confirmarMapaGrande() {
            cerrarMapaGrande();
        }

        function centrarEnLocal() { mapSol.setView([restLat, restLng], 16); }

        function dibujarRuta(geometria) {
            if (rutaDibujada) { mapSol.removeLayer(rutaDibujada); rutaDibujada = null; }
            if (!geometria || !geometria.length) return;
            // OSRM entrega [lng, lat]; Leaflet espera [lat, lng].
            const puntos = geometria.map(c => [c[1], c[0]]);
            rutaDibujada = L.polyline(puntos, { color: '#e4441b', weight: 5, opacity: 0.75 }).addTo(mapSol);
            mapSol.fitBounds(rutaDibujada.getBounds(), { padding: [25, 25] });
        }

        // El precio SIEMPRE lo decide el servidor: así el comercio ve lo que se le cobra.
        async function pedirCotizacion() {
            clearTimeout(temporizadorCotizar);
            temporizadorCotizar = setTimeout(cotizarAhora, 350);
        }

        async function cotizarAhora() {
            if (!COMERCIO_TIENE_GPS) {
                document.getElementById('texto-distancia').innerText = "Falta la ubicación de tu local";
                document.getElementById('texto-tarifa').innerText = "Ve a Configuración y marca tu local en el mapa";
                return;
            }

            cotizacionValida = false;
            document.getElementById('texto-distancia').innerText = "Calculando ruta por calles...";
            document.getElementById('texto-precio').innerText = "—";

            try {
                const fd = new FormData();
                fd.append('lat', destLat);
                fd.append('lng', destLng);
                fd.append('ruta', '1');

                const res = await fetch('/api/cotizar.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status !== 'success') {
                    document.getElementById('texto-distancia').innerText = data.mensaje || "No se pudo calcular la ruta";
                    document.getElementById('texto-precio').innerText = "$0.00";
                    return;
                }

                distanciaGlobal = parseFloat(data.km);
                costoFinal = parseFloat(data.costo);
                cotizacionValida = true;

                const etiqueta = (data.fuente === 'calles')
                    ? `Ruta por calles: ${distanciaGlobal.toFixed(2)} km · ~${data.minutos} min`
                    : `Distancia estimada: ${distanciaGlobal.toFixed(2)} km`;

                document.getElementById('texto-distancia').innerText = etiqueta;
                document.getElementById('texto-tarifa').innerText =
                    `Costo $${parseFloat(data.precio_km).toFixed(2)}/km (Mín $${parseFloat(data.minimo).toFixed(2)})`;
                document.getElementById('texto-precio').innerText = "$" + costoFinal.toFixed(2);

                dibujarRuta(data.geometria);
            } catch (e) {
                document.getElementById('texto-distancia').innerText = "Error de conexión al calcular la ruta";
                document.getElementById('texto-precio').innerText = "$0.00";
            }
        }

        // Compatibilidad con el resto del código que ya llamaba a esta función.
        function actualizarDistanciaVisual() { pedirCotizacion(); }

        // ===================== BUSCADOR DE DIRECCIONES =====================
        async function buscarDireccion() {
            const texto = document.getElementById('buscar_direccion').value.trim();
            const lista = document.getElementById('resultados-direccion');
            const btn = document.getElementById('btn-buscar-dir');
            btn.innerHTML = '...';
            await yoraBuscarDireccion(texto, lista, function (r, meta) {
                if (!meta || !meta.auto) {
                    document.getElementById('buscar_direccion').value = r.nombre;
                    const dir = document.getElementById('c_direccion');
                    if (dir && !dir.value) dir.value = r.nombre;
                }
                fijarDestino(parseFloat(r.lat), parseFloat(r.lng), true);
            });
            btn.innerHTML = '<i class="ph ph-magnifying-glass"></i>';
        }
        const buscarDireccionLive = yoraDebounce(buscarDireccion, 450);
        async function buscarEnMapaGrande() {
            const input = document.getElementById('fs-buscar');
            const lista = document.getElementById('fs-sugerencias');
            await yoraBuscarDireccion(input.value, lista, function (r) {
                input.value = r.nombre;
                fijarDestino(r.lat, r.lng, true);
                lista.style.display = 'none';
            });
        }
        const buscarEnMapaGrandeLive = yoraDebounce(buscarEnMapaGrande, 450);
        (function () {
            const dir = document.getElementById('c_direccion');
            if (dir) dir.addEventListener('input', function () { this.dataset.auto = '0'; });
        })();

        document.addEventListener("click", function (e) {
            if (e.target.id !== "buscar_direccion" && e.target.id !== "btn-buscar-dir") {
                const lista = document.getElementById("resultados-direccion");
                if (lista) lista.style.display = "none";
            }
            if (!e.target.closest('#mapa-fullscreen .fs-top')) {
                const fs = document.getElementById('fs-sugerencias');
                if (fs) fs.style.display = 'none';
            }
        });

        // ===================== MAPA DEL PERFIL =====================
        let mapPer = L.map('map-perfil', { zoomControl: true }).setView([restLat, restLng], 16);
        CAPA_MAPA().addTo(mapPer);
        let perfilMarker = L.marker([restLat, restLng], { draggable: true, icon: iconoLocal }).addTo(mapPer);

        function fijarLocal(lat, lng) {
            perfilMarker.setLatLng([lat, lng]);
            document.getElementById('p_lat').value = lat.toFixed(6);
            document.getElementById('p_lng').value = lng.toFixed(6);
        }
        perfilMarker.on('dragend', function () {
            const p = perfilMarker.getLatLng();
            fijarLocal(p.lat, p.lng);
        });
        mapPer.on('click', function (e) { fijarLocal(e.latlng.lat, e.latlng.lng); });

        // Cotizamos el punto inicial para que el precio no arranque en blanco.
        fijarDestino(restLat + 0.004, restLng + 0.004, false);

        async function procesarFormulario() {
            let nombre = document.getElementById('c_nombre').value; let detalles = document.getElementById('c_detalles').value;
            let cedula = document.getElementById('c_cedula').value; 
            if(nombre === '' || detalles === '') { alert("El Nombre y Contenido son obligatorios."); return; }
            if(!cotizacionValida || distanciaGlobal <= 0) { alert("Espera a que termine de calcularse la ruta, o mueve el pin al destino del cliente."); return; }

            document.getElementById('btn-solicitar').innerHTML = "Procesando...";
            let fd = new FormData();
            fd.append('nombreCliente', nombre); fd.append('telCliente', document.getElementById('c_telefono').value);
            fd.append('cedulaCliente', cedula);
            // Dirección = calle del mapa; referencia = cuadro 🏡 (casa rosada, etc.)
            const dirMapa = (window.__yoraDirMapa || document.getElementById('texto-dir-destino')?.textContent || '').trim();
            const refCasa = document.getElementById('c_direccion').value.trim();
            fd.append('direccionEscrita', dirMapa || ('GPS ' + destLat.toFixed(5) + ', ' + destLng.toFixed(5)));
            fd.append('referencia', refCasa);
            fd.append('contenidoPaquete', detalles); 
            fd.append('tiempoEstimado', document.getElementById('c_tiempo').value);
            // Solo mandamos el destino: la distancia y el costo los recalcula el servidor.
            fd.append('lat', destLat); fd.append('lng', destLng);
            if(editComandaId !== null) fd.append('edit_comanda_id', editComandaId);

            try {
                let res = await fetch((editComandaId !== null) ? '/api/editar_comanda.php' : '/api/crear_comanda.php', { method: 'POST', body: fd });
                let data = await res.json();
                if(data.status === 'success') {
                    cancelarEdicion(); document.getElementById('texto-saldo-navbar').innerText = data.nuevo_saldo.toFixed(2);
                    sincronizarComercio(); cargarHistorial();
                } else { alert("❌ " + data.mensaje); document.getElementById('btn-solicitar').innerHTML = "Reintentar"; }
            } catch(e) { alert("Error de conexión"); }
        }

        function abrirModalPedido(data) {
            document.getElementById('modal-pedido').style.display = 'flex';
            document.getElementById('modal-titulo').innerText = 'Orden #' + (data.codigo || data.id) + ' - ' + data.cliente;
            document.getElementById('modal-contenido').innerHTML = `<p><b>Estatus:</b> <span style="color:#e4441b; font-weight:bold;">${escaparHtml(data.estatus)}</span></p><p><b>Contenido:</b> ${escaparHtml(data.detalles)}</p><p><b>Costo actual:</b> $${escaparHtml(data.costo)}</p>`;
            
            let htmlAcciones = '';
            if(data.estatus === 'Buscando Conductor' || data.estatus === 'Revisando') {
                let dataString = encodeURIComponent(JSON.stringify(data));
                htmlAcciones += `<button onclick="prepararEdicion('${dataString}')" style="background:#3b82f6; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600; font-size:1rem;">✏️ Editar Pedido y Ruta</button>`;
                htmlAcciones += `<button onclick="cancelarPedido(${data.id})" style="background:transparent; color:#dc2626; border:1px solid #fca5a5; padding:8px 15px; border-radius:8px; cursor:pointer; font-weight:600;">❌ Cancelar Pedido (Gratis)</button>`;
            } else if (data.estatus === 'En Camino a Comercio') {
                htmlAcciones = `<p style="font-size:0.75rem; color:#dc2626; margin-bottom:5px; text-align:center;">El driver va en camino. Cancelar descuenta 50% de penalidad.</p><button onclick="cancelarPedido(${data.id})" style="background:#dc2626; color:white; border:none; padding:10px 15px; border-radius:8px; cursor:pointer; font-weight:600;">⚠️ Cancelar (Penalidad 50%)</button>`;
            } else { htmlAcciones = `<p style="color:#64748b; font-size:0.85rem; font-weight:bold; text-align:center;">El pedido va en camino al cliente. No se puede cancelar.</p>`; }

            htmlAcciones += `<button onclick="abrirModalReporte(${data.id})" style="background:#fee2e2; color:#dc2626; border:1px dashed #fca5a5; padding:10px; border-radius:8px; cursor:pointer; font-weight:600; width:100%; margin-top:10px;">🚨 Reportar Problema</button>`;
            document.getElementById('modal-acciones').innerHTML = htmlAcciones;
        }

        function abrirModalFactura(data) {
            document.getElementById('modal-factura').style.display = 'flex';
            document.getElementById('f_orden').innerText = data.codigo || data.id;
            document.getElementById('f_fecha').innerText = data.fecha;
            document.getElementById('f_driver').innerText = data.conductor;
            document.getElementById('f_cliente').innerText = data.cliente;
            document.getElementById('f_detalles').innerText = data.detalles;
            document.getElementById('f_costo').innerText = "$" + data.costo;
        }

        // Escapa texto antes de insertarlo con innerHTML.
        function escaparHtml(valor) {
            if (valor === null || valor === undefined) return '';
            return String(valor)
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
        }

        // Solo permite http(s): bloquea javascript: y data: en src/href.
        function urlSegura(valor) {
            if (!valor || valor === "null") return '';
            let limpio = String(valor).trim();
            if (!/^https?:\/\//i.test(limpio) || /[\s"'<>]/.test(limpio)) return '';
            return limpio;
        }

        function abrirModalEvidencia(data) {
            document.getElementById('modal-evidencia').style.display = 'flex';
            document.getElementById('e_orden').innerText = data.codigo || data.id;

            const urlRec = urlSegura(data.foto_recogida);
            const urlEnt = urlSegura(data.foto_entrega);
            const sinFoto = '<p style="color:#94a3b8; padding:30px 0; font-size:0.8rem;">Sin foto</p>';

            let imgRec = urlRec
                ? `<a href="${escaparHtml(urlRec)}" target="_blank" rel="noopener"><img src="${escaparHtml(urlRec)}" style="width:100%; height:150px; object-fit:cover; border-radius:8px;"></a>`
                : sinFoto;

            let imgEnt = urlEnt
                ? `<a href="${escaparHtml(urlEnt)}" target="_blank" rel="noopener"><img src="${escaparHtml(urlEnt)}" style="width:100%; height:150px; object-fit:cover; border-radius:8px;"></a>`
                : sinFoto;

            document.getElementById('e_foto_rec').innerHTML = imgRec;
            document.getElementById('e_foto_ent').innerHTML = imgEnt;
        }

        function cerrarModal() { 
            document.getElementById('modal-pedido').style.display = 'none'; 
            document.getElementById('modal-factura').style.display = 'none'; 
            document.getElementById('modal-evidencia').style.display = 'none'; 
        }

        function abrirModalReporte(comanda_id) {
            cerrarModal(); 
            document.getElementById('modal-reporte').style.display = 'flex';
            document.getElementById('rep_comanda_id').value = comanda_id;
            document.getElementById('rep-orden-id').innerText = "#" + comanda_id;
        }

        async function enviarReporte() {
            let fd = new FormData();
            fd.append('comanda_id', document.getElementById('rep_comanda_id').value);
            fd.append('motivo', document.getElementById('rep_motivo').value);
            fd.append('descripcion', document.getElementById('rep_descripcion').value);
            document.getElementById('btn-enviar-rep').innerText = "Enviando...";
            try {
                let res = await fetch('/api/reportar_problema.php', { method: 'POST', body: fd });
                let data = await res.json();
                alert(data.mensaje);
                document.getElementById('modal-reporte').style.display = 'none'; document.getElementById('rep_descripcion').value = '';
            } catch(e) {}
            document.getElementById('btn-enviar-rep').innerText = "Enviar Caso";
        }

        function prepararEdicion(dataString) {
            cerrarModal(); let data = JSON.parse(decodeURIComponent(dataString)); editComandaId = data.id;
            document.getElementById('form-titulo').innerText = "Editando Orden #" + (data.codigo || data.id);
            document.getElementById('c_nombre').value = data.cliente; document.getElementById('c_telefono').value = data.tel;
            document.getElementById('c_direccion').value = data.dir; document.getElementById('c_detalles').value = data.detalles; document.getElementById('c_tiempo').value = data.tiempo;
            fijarDestino(parseFloat(data.lat), parseFloat(data.lng), true);
            document.getElementById('btn-solicitar').innerHTML = "💾 Actualizar Pedido"; document.getElementById('btn-cancelar-edicion').style.display = "block"; window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function cancelarEdicion() {
            editComandaId = null; document.getElementById('form-titulo').innerText = "Solicitar YoraDelivery";
            document.getElementById('c_nombre').value = ''; document.getElementById('c_telefono').value = ''; document.getElementById('c_cedula').value = ''; document.getElementById('c_direccion').value = ''; document.getElementById('c_detalles').value = '';
            ultimaCedulaBuscada = ''; avisoCliente('');
            document.getElementById('btn-solicitar').innerHTML = "<i class='ph ph-paper-plane-tilt'></i> Enviar al Aire"; document.getElementById('btn-cancelar-edicion').style.display = "none";
        }

        async function cancelarPedido(id) {
            if(confirm("¿Confirmas la cancelación de este pedido?")) {
                let fd = new FormData(); fd.append('comanda_id', id);
                try {
                    let res = await fetch('/api/cancelar_comanda.php', { method: 'POST', body: fd }); let data = await res.json();
                    if(data.status === 'success') { alert("✅ " + data.mensaje); window.location.reload(); } else { alert("❌ " + data.mensaje); }
                } catch(e) {}
            }
        }

        function aplicarLogoComercio(url) {
            if (!url) return;
            const cache = url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
            const actual = document.getElementById('imgLogoActual');
            const lado = document.getElementById('sidebar-logo');
            if (actual) { actual.onerror = function () { this.onerror = null; this.src = 'https://cdn-icons-png.flaticon.com/512/819/819814.png'; }; actual.src = cache; }
            if (lado) { lado.onerror = function () { this.onerror = null; this.src = 'https://cdn-icons-png.flaticon.com/512/819/819814.png'; }; lado.src = cache; }
        }

        async function subirLogoComercio(input) {
            if (!input.files || !input.files[0]) return;
            const archivo = input.files[0];
            const msg = document.getElementById('logo-msg');
            if (archivo.size > 5 * 1024 * 1024) {
                if (msg) { msg.style.color = '#dc2626'; msg.textContent = 'La imagen pesa más de 5 MB.'; }
                alert('❌ La imagen debe pesar menos de 5 MB.');
                input.value = '';
                return;
            }
            if (archivo.type && archivo.type.indexOf('image/') === 0) {
                const local = URL.createObjectURL(archivo);
                aplicarLogoComercio(local);
            }
            let fd = new FormData();
            fd.append('logo', archivo);
            document.getElementById('imgLogoActual').style.opacity = '0.5';
            document.getElementById('sidebar-logo').style.opacity = '0.5';
            if (msg) { msg.style.color = '#64748b'; msg.textContent = 'Subiendo logo...'; }
            try {
                let res = await fetch('/api/subir_logo_comercio.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                let data = await res.json();
                if (data.status === 'success' && data.url) {
                    aplicarLogoComercio(data.url);
                    if (msg) { msg.style.color = '#16a34a'; msg.textContent = 'Logo actualizado.'; }
                    alert('✅ Logo actualizado');
                } else {
                    if (msg) { msg.style.color = '#dc2626'; msg.textContent = data.mensaje || 'No se pudo guardar el logo.'; }
                    alert('❌ ' + (data.mensaje || 'No se pudo guardar el logo.'));
                }
            } catch (e) {
                if (msg) { msg.style.color = '#dc2626'; msg.textContent = 'Error de conexión al subir el logo.'; }
                alert('❌ Error al conectar con el servidor al subir el logo.');
            }
            document.getElementById('imgLogoActual').style.opacity = '1';
            document.getElementById('sidebar-logo').style.opacity = '1';
            input.value = '';
        }

        async function guardarPerfil() {
            let passActual = document.getElementById('p_pass_actual').value;
            let passNueva = document.getElementById('p_pass_nueva').value;
            let passConfirmar = document.getElementById('p_pass_confirmar').value;

            if(passNueva !== '' || passConfirmar !== '') {
                if(passNueva !== passConfirmar) {
                    alert("❌ Las contraseñas no coinciden.");
                    return;
                }
                if(passNueva.length < 8) {
                    alert("❌ La contraseña debe tener al menos 8 caracteres.");
                    return;
                }
                if(passActual === '') {
                    alert("❌ Escribe tu contraseña actual para poder cambiarla.");
                    return;
                }
            }

            let fd = new FormData(); 
            fd.append('nombre', document.getElementById('p_nombre').value); 
            fd.append('rif', document.getElementById('p_rif').value); 
            fd.append('direccion', document.getElementById('p_direccion').value); 
            fd.append('lat', document.getElementById('p_lat').value); 
            fd.append('lng', document.getElementById('p_lng').value);
            fd.append('hora_abre', document.getElementById('p_abre').value);
            fd.append('hora_cierra', document.getElementById('p_cierra').value);

            if(passNueva !== '') {
                fd.append('password', passNueva);
                fd.append('password_actual', passActual);
            }

            let docInput = document.getElementById('p_documento');
            if(docInput && docInput.files && docInput.files[0]) {
                if (docInput.files[0].size > 5 * 1024 * 1024) {
                    alert("❌ El documento no puede pesar más de 5 MB.");
                    return;
                }
                fd.append('documento', docInput.files[0]);
            }
            let logoInput = document.getElementById('inputLogo');
            if (logoInput && logoInput.files && logoInput.files[0]) {
                fd.append('logo', logoInput.files[0]);
            }

            document.getElementById('btn-guardar-perfil').innerText = "Guardando...";
            try { 
                let res = await fetch('/api/guardar_perfil.php', { method: 'POST', body: fd }); 
                let data = await res.json(); 
                if(data.status === 'success') {
                    alert("✅ Cambios guardados correctamente.");
                    document.getElementById('p_pass_nueva').value = '';
                    document.getElementById('p_pass_confirmar').value = '';
                    window.location.reload();
                } else {
                    alert("❌ " + (data.mensaje || "Error al guardar."));
                }
            } catch(e) { 
                alert("Error al conectar con el servidor."); 
            }
            document.getElementById('btn-guardar-perfil').innerText = "Guardar Cambios";
        }

        async function enviarDocumento() {
            let input = document.getElementById('p_documento');
            if (!input || !input.files || !input.files[0]) {
                alert("Elige primero el RIF o la cédula (JPG, PNG o PDF).");
                return;
            }
            if (input.files[0].size > 5 * 1024 * 1024) {
                alert("❌ El documento no puede pesar más de 5 MB.");
                return;
            }
            let btn = document.getElementById('btn-enviar-doc');
            let fd = new FormData();
            fd.append('documento', input.files[0]);
            btn.innerText = "Enviando...";
            btn.disabled = true;
            try {
                let res = await fetch('/api/subir_documento_comercio.php', { method: 'POST', body: fd });
                let data = await res.json();
                if (data.status === 'success') {
                    alert("✅ Documento enviado. Ya aparece en el panel HQ para revisión.");
                    window.location.reload();
                } else {
                    alert("❌ " + (data.mensaje || "No se pudo enviar el documento."));
                }
            } catch (e) {
                alert("Error al conectar con el servidor. Inténtalo de nuevo.");
            }
            btn.innerHTML = '<i class="ph ph-upload-simple"></i> Enviar a verificación';
            btn.disabled = false;
        }

        // La tasa la manda nuestro servidor, no el navegador: si no hay tasa
        // disponible se queda en 0 y el panel lo dice, en vez de calcular los
        // bolivares con un numero viejo escrito en el codigo.
        let tasaBCVGlobal = <?php echo json_encode(round((float) $tasa_bcv_actual, 2)); ?>;

        async function obtenerTasaRecarga() {
            try {
                let res = await fetch('/api/tasa_bcv.php');
                let data = await res.json();
                if (data.status === 'success' && data.tasa > 0) { tasaBCVGlobal = parseFloat(data.tasa); }
            } catch(e) {}
            document.getElementById('r_monto_bs').placeholder = tasaBCVGlobal > 0
                ? "Tasa BCV: Bs. " + tasaBCVGlobal.toFixed(2)
                : "Tasa BCV no disponible";
            calcularBsRecarga();
        }

        function calcularBsRecarga() {
            let montoUSD = parseFloat(document.getElementById('r_monto').value); let metodo = document.getElementById('r_banco').value;
            if(!isNaN(montoUSD) && tasaBCVGlobal > 0 && metodo === 'Pago Móvil') { document.getElementById('r_monto_bs').value = "Bs. " + (montoUSD * tasaBCVGlobal).toFixed(2); } else { document.getElementById('r_monto_bs').value = ""; }
        }

        function cambiarDatosPago() {
            let metodo = document.getElementById('r_banco').value;
            document.getElementById('info-pago-movil').style.display = 'none'; document.getElementById('info-efectivo').style.display = 'none'; document.getElementById('info-zelle').style.display = 'none'; document.getElementById('info-binance').style.display = 'none'; document.getElementById('info-paypal').style.display = 'none'; document.getElementById('caja-monto-bs').style.display = 'none';
            if(metodo === 'Pago Móvil') { document.getElementById('info-pago-movil').style.display = 'block'; document.getElementById('caja-monto-bs').style.display = 'block'; calcularBsRecarga(); }
            if(metodo === 'Efectivo') document.getElementById('info-efectivo').style.display = 'block'; if(metodo === 'Zelle') document.getElementById('info-zelle').style.display = 'block'; if(metodo === 'Binance') document.getElementById('info-binance').style.display = 'block'; if(metodo === 'Paypal') document.getElementById('info-paypal').style.display = 'block';
        }

        function syncMontoFactura() {
            const sel = document.getElementById('r_factura');
            if (!sel) return;
            const opt = sel.options[sel.selectedIndex];
            const m = opt ? parseFloat(opt.getAttribute('data-monto') || '0') : 0;
            if (m > 0 && document.getElementById('r_monto')) {
                document.getElementById('r_monto').value = m.toFixed(2);
                calcularBsRecarga();
            }
        }

        async function enviarRecarga() {
            let monto = parseFloat(document.getElementById('r_monto').value); let minRecarga = 1; let banco = document.getElementById('r_banco').value; let ref = document.getElementById('r_referencia').value;
            let facturaId = document.getElementById('r_factura') ? document.getElementById('r_factura').value : '0';
            if(banco === '') { alert("Selecciona un tipo de pago."); return; } if(isNaN(monto) || monto < minRecarga) { alert("El monto mínimo es $" + minRecarga); return; } if(ref === '') { alert("Debes colocar el número de referencia."); return; }
            document.getElementById('btn-recargar').innerText = "Enviando...";
            let fd = new FormData(); fd.append('monto', monto); fd.append('banco', banco); fd.append('referencia', ref); fd.append('factura_id', facturaId);
            try { let res = await fetch('/api/reportar_recarga.php', { method: 'POST', body: fd }); let data = await res.json(); alert(data.mensaje); if(data.status === 'success') { document.getElementById('r_monto').value = ''; document.getElementById('r_referencia').value = ''; document.getElementById('r_monto_bs').value = ''; cargarMisRecargas(); } } catch(e) { alert("Error de conexión"); }
            document.getElementById('btn-recargar').innerText = "Enviar Reporte de Pago";
        }

        async function cargarMisRecargas() {
            try { let res = await fetch('/api/historial_recargas.php'); let data = await res.json(); if(document.getElementById('tabla-mis-recargas')) { document.getElementById('tabla-mis-recargas').innerHTML = data.html; } if(data.saldo_billetera !== undefined) { document.getElementById('texto-saldo-navbar').innerText = data.saldo_billetera; } } catch(e) {}
        }

        async function cargarHistorial() {
            let fd = new FormData();
            fd.append('desde', document.getElementById('h_desde').value);
            fd.append('hasta', document.getElementById('h_hasta').value);
            try {
                let res = await fetch('/api/historial_comercio.php', { method: 'POST', body: fd });
                let data = await res.json();
                if(data.status === 'success') {
                    document.getElementById('tabla-historial').innerHTML = data.html;
                    document.getElementById('stat-pedidos').innerText = data.total_pedidos;
                    document.getElementById('stat-gastado').innerText = "$" + data.total_gastado.toFixed(2);
                }
            } catch(e) {}
        }

        async function sincronizarComercio() {
            const box = document.getElementById('lista-pedidos-activos');
            try {
                let res = await fetch('/api/sync_comercio.php', { credentials: 'same-origin', cache: 'no-store' });
                let text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch (parseErr) {
                    if (box) box.innerHTML = '<div style="text-align:center;padding:20px;color:#b91c1c;">Error al cargar envíos (respuesta inválida).</div>';
                    return;
                }
                if (box) {
                    box.innerHTML = (data.html !== undefined && data.html !== null)
                        ? data.html
                        : ('<div style="text-align:center;padding:20px;color:#b91c1c;">' + (data.mensaje || 'No se pudieron cargar los envíos.') + '</div>');
                }
                if (document.getElementById('stat-top-activos') && data.stat_activos !== undefined) {
                    document.getElementById('stat-top-activos').innerText = data.stat_activos;
                    document.getElementById('stat-top-hoy').innerText = data.stat_hoy;
                    document.getElementById('stat-top-historico').innerText = data.stat_historico;
                }
            } catch (e) {
                if (box && box.innerText.indexOf('Cargando') !== -1) {
                    box.innerHTML = '<div style="text-align:center;padding:20px;color:#b91c1c;">Sin conexión al sincronizar envíos.</div>';
                }
            }
        }

        let mapaRastreo = null;
        let markerDriver = null;
        let markerDestino = null;
        let markerLocal = null;
        let rastreoTimer = null;
        let rastreoId = 0;

        function iconoRastreo(color) {
            return L.icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-' + color + '.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41], iconAnchor: [12, 41]
            });
        }

        function cerrarRastreo() {
            document.getElementById('modal-rastreo').style.display = 'none';
            if (rastreoTimer) { clearInterval(rastreoTimer); rastreoTimer = null; }
        }

        async function pintarRastreo() {
            try {
                const res = await fetch('/api/rastreo_drivers.php');
                const data = await res.json();
                const envio = (data.envios || []).find(function (e) { return e.id === rastreoId; });
                if (!envio || !envio.conductor) {
                    document.getElementById('rastreo-sub').innerText = 'Ese envío ya no está activo.';
                    return;
                }
                const d = envio.conductor;
                document.getElementById('rastreo-titulo').innerText = d.nombre || 'Motorizado';
                document.getElementById('rastreo-sub').innerText = (envio.estatus || '') + (d.gps ? ' · actualizado ' + d.gps : ' · esperando GPS del driver');

                if (!mapaRastreo) {
                    mapaRastreo = L.map('mapa-rastreo').setView([<?php echo $rest_lat; ?>, <?php echo $rest_lng; ?>], 14);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: 'Yora' }).addTo(mapaRastreo);
                }

                const puntos = [];
                if (data.comercio) {
                    if (!markerLocal) markerLocal = L.marker([data.comercio.lat, data.comercio.lng], { icon: iconoRastreo('green') }).addTo(mapaRastreo).bindPopup('Tu local');
                    puntos.push([data.comercio.lat, data.comercio.lng]);
                }
                if (envio.destino) {
                    if (markerDestino) markerDestino.setLatLng([envio.destino.lat, envio.destino.lng]);
                    else markerDestino = L.marker([envio.destino.lat, envio.destino.lng], { icon: iconoRastreo('orange') }).addTo(mapaRastreo).bindPopup('Cliente');
                    puntos.push([envio.destino.lat, envio.destino.lng]);
                }
                if (d.lat && d.lng) {
                    if (markerDriver) markerDriver.setLatLng([d.lat, d.lng]);
                    else markerDriver = L.marker([d.lat, d.lng], { icon: iconoRastreo('red') }).addTo(mapaRastreo).bindPopup(d.nombre);
                    puntos.push([d.lat, d.lng]);
                }
                if (puntos.length) {
                    mapaRastreo.fitBounds(puntos, { padding: [30, 30], maxZoom: 16 });
                }
                setTimeout(function () { mapaRastreo.invalidateSize(); }, 200);
            } catch (e) {}
        }

        function verRastreo(id) {
            rastreoId = parseInt(id, 10) || 0;
            document.getElementById('modal-rastreo').style.display = 'flex';
            pintarRastreo();
            if (rastreoTimer) clearInterval(rastreoTimer);
            rastreoTimer = setInterval(pintarRastreo, 8000);
        }

        obtenerTasaRecarga();
        cargarHistorial(); 
        cargarMisRecargas();
        sincronizarComercio(); 
        
        setInterval(sincronizarComercio, 4000);
        function latidoComercio() { fetch('/api/latido_comercio.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {}); }
        latidoComercio();
        setInterval(latidoComercio, 25000);
        setInterval(cargarMisRecargas, 5000);
    </script>

    <div id="mapa-fullscreen">
        <div class="fs-top" style="position:relative;">
            <button type="button" onclick="cerrarMapaGrande()" aria-label="Cerrar">←</button>
            <input id="fs-buscar" type="text" placeholder="Busca calle, cruce o sitio…" autocomplete="off"
                onkeydown="if(event.key==='Enter'){event.preventDefault(); buscarEnMapaGrande();}"
                oninput="buscarEnMapaGrandeLive()">
            <div id="fs-sugerencias"></div>
        </div>
        <div id="mapa-fs">
            <div class="fs-crosshair" aria-hidden="true"></div>
        </div>
        <div class="fs-bottom">
            <p id="fs-dir-txt">Mueve el mapa: el pin queda al centro</p>
            <p class="fs-hint">También puedes buscar la calle arriba o tocar el mapa</p>
            <button type="button" class="btn-ok" onclick="confirmarMapaGrande()">Usar este destino</button>
        </div>
    </div>
</body>
</html>