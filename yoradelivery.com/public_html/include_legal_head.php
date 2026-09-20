<?php
/**
 * Cabecera compartida para páginas legales públicas (Play Store / app).
 * @var string $pageTitle
 * @var string $pageDesc
 */
$pageTitle = $pageTitle ?? 'Yora Delivery';
$pageDesc = $pageDesc ?? 'Yora Delivery · Barquisimeto';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --yora: #E4441B;
            --yora-dark: #C23310;
            --bg: #F8FAFC;
            --text: #1A1410;
            --muted: #64748B;
            --card: #FFFFFF;
            --line: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.65;
        }
        .top {
            background: linear-gradient(135deg, #FF8A5C, var(--yora), var(--yora-dark));
            color: #fff;
            padding: 28px 20px 36px;
        }
        .top-inner { max-width: 820px; margin: 0 auto; }
        .brand {
            display: inline-block;
            font-weight: 800;
            font-size: 1.05rem;
            letter-spacing: 0.02em;
            color: #fff;
            text-decoration: none;
            margin-bottom: 18px;
        }
        .top h1 { font-size: clamp(1.6rem, 4vw, 2.1rem); font-weight: 800; margin-bottom: 8px; }
        .top p { opacity: 0.92; max-width: 560px; font-size: 0.98rem; }
        .wrap { max-width: 820px; margin: -18px auto 48px; padding: 0 16px; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 28px 22px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
        }
        .nav-legal {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }
        .nav-legal a {
            text-decoration: none;
            color: var(--yora);
            font-weight: 700;
            font-size: 0.88rem;
            background: #FFF1EB;
            padding: 8px 12px;
            border-radius: 999px;
        }
        .nav-legal a.active { background: var(--yora); color: #fff; }
        h2 {
            font-size: 1.15rem;
            margin: 26px 0 10px;
            color: var(--text);
        }
        h2:first-of-type { margin-top: 8px; }
        p, li { color: #334155; font-size: 0.95rem; margin-bottom: 10px; }
        ul { padding-left: 1.2rem; margin-bottom: 12px; }
        li { margin-bottom: 6px; }
        .meta {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 18px;
        }
        .cta {
            display: inline-block;
            margin-top: 8px;
            background: var(--yora);
            color: #fff;
            text-decoration: none;
            font-weight: 800;
            padding: 12px 18px;
            border-radius: 999px;
        }
        .cta:hover { background: var(--yora-dark); }
        footer {
            text-align: center;
            color: var(--muted);
            font-size: 0.82rem;
            padding: 0 16px 40px;
        }
        footer a { color: var(--yora); font-weight: 600; text-decoration: none; }
        @media (max-width: 600px) {
            .card { padding: 22px 16px; }
        }
    </style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="https://yoradelivery.com/">yoradelivery</a>
        <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</header>
<main class="wrap">
    <div class="card">
        <nav class="nav-legal" aria-label="Legal">
            <a href="/privacidad.php" class="<?php echo ($legalNav ?? '') === 'privacidad' ? 'active' : ''; ?>">Privacidad</a>
            <a href="/terminos.php" class="<?php echo ($legalNav ?? '') === 'terminos' ? 'active' : ''; ?>">Términos</a>
            <a href="/eliminar-datos.php" class="<?php echo ($legalNav ?? '') === 'eliminar' ? 'active' : ''; ?>">Eliminar datos</a>
            <a href="/soporte-publico.php" class="<?php echo ($legalNav ?? '') === 'soporte' ? 'active' : ''; ?>">Soporte</a>
        </nav>
