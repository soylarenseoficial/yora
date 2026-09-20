<?php
/**
 * Solo lógica de créditos comercios (HQ).
 * No redefinir helpers que ya viven en seguridad.php.
 */
if (function_exists('yora_credito_estado')) {
    return;
}

function yora_penalizacion_reactivacion(): float
{
    return 3.00;
}

function yora_ensure_creditos_schema(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->query(
            "CREATE TABLE IF NOT EXISTS creditos_comercio_matriz (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo ENUM('Pequeño','Mediano','Grande') NOT NULL,
                nivel TINYINT UNSIGNED NOT NULL,
                credito DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tipo_nivel (tipo, nivel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS facturas_comercio (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                comercio_id INT(11) NOT NULL,
                fecha_consumo DATE NOT NULL,
                monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                estatus ENUM('pendiente','gracia','pagada','vencida') NOT NULL DEFAULT 'pendiente',
                vence_el DATE NOT NULL,
                gracia_hasta DATE NOT NULL,
                pagado_el DATETIME DEFAULT NULL,
                penalizacion DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_comercio_dia (comercio_id, fecha_consumo),
                KEY idx_estatus_vence (estatus, vence_el),
                KEY idx_comercio (comercio_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $n = (int) ($db->query('SELECT COUNT(*) AS c FROM creditos_comercio_matriz')->fetch_assoc()['c'] ?? 0);
        if ($n < 1) {
            $db->query(
                "INSERT INTO creditos_comercio_matriz (tipo, nivel, credito) VALUES
                ('Pequeño',1,50),('Pequeño',2,80),('Pequeño',3,120),('Pequeño',4,170),('Pequeño',5,230),
                ('Mediano',1,70),('Mediano',2,110),('Mediano',3,160),('Mediano',4,220),('Mediano',5,300),
                ('Grande',1,100),('Grande',2,150),('Grande',3,220),('Grande',4,300),('Grande',5,400)"
            );
        }
    } catch (Throwable $e) {
        error_log('yora_ensure_creditos_schema: ' . $e->getMessage());
    }
}

function yora_credito_matriz_monto(mysqli $db, ?string $tipo, int $nivel): float
{
    yora_ensure_creditos_schema($db);
    $tipo = yora_tipo_comercio_clave($tipo);
    $nivel = max(1, min(5, $nivel));
    $row = yora_one($db, 'SELECT credito FROM creditos_comercio_matriz WHERE tipo = ? AND nivel = ?', 'si', $tipo, $nivel);
    if ($row) {
        return round((float) $row['credito'], 2);
    }
    $defaults = [
        'Pequeño' => [50, 80, 120, 170, 230],
        'Mediano' => [70, 110, 160, 220, 300],
        'Grande' => [100, 150, 220, 300, 400],
    ];
    return (float) ($defaults[$tipo][$nivel - 1] ?? 50);
}

function yora_facturas_pendientes_monto(mysqli $db, int $comercio_id): float
{
    yora_ensure_creditos_schema($db);
    $row = yora_one(
        $db,
        "SELECT COALESCE(SUM(monto + penalizacion),0) AS t FROM facturas_comercio
         WHERE comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')",
        'i',
        $comercio_id
    );
    return round((float) ($row['t'] ?? 0), 2);
}

function yora_credito_estado(mysqli $db, int $comercio_id): array
{
    yora_ensure_creditos_schema($db);
    $c = yora_one(
        $db,
        'SELECT tipo_comercio, credito_limite, credito_consumido_ciclo, bloqueado_deuda, penalizacion_pendiente FROM comercios WHERE id = ?',
        'i',
        $comercio_id
    );
    if (!$c) {
        return [
            'limite' => 0.0, 'consumido' => 0.0, 'facturas' => 0.0, 'penalizacion' => 0.0,
            'disponible' => 0.0, 'bloqueado' => true, 'nivel' => 1, 'tipo' => 'Pequeño',
        ];
    }
    $nivelInfo = yora_nivel_comercio($db, $comercio_id);
    $nivelNum = (int) ($nivelInfo['nivel'] ?? 1);
    $tipo = yora_tipo_comercio_clave($c['tipo_comercio'] ?? 'Pequeño');
    $limiteMatriz = yora_credito_matriz_monto($db, $tipo, $nivelNum);
    $limite = round((float) ($c['credito_limite'] ?? 0), 2);
    if ($limite <= 0 || abs($limite - $limiteMatriz) > 0.009) {
        $limite = $limiteMatriz;
        try {
            yora_exec($db, 'UPDATE comercios SET credito_limite = ? WHERE id = ?', 'di', $limite, $comercio_id);
        } catch (Throwable $e) {
        }
    }
    $consumido = round((float) ($c['credito_consumido_ciclo'] ?? 0), 2);
    $facturas = yora_facturas_pendientes_monto($db, $comercio_id);
    $penal = round((float) ($c['penalizacion_pendiente'] ?? 0), 2);
    $disponible = round($limite - $consumido - $facturas - $penal, 2);
    $bloqueado = ((int) ($c['bloqueado_deuda'] ?? 0) === 1);
    return [
        'limite' => $limite,
        'consumido' => $consumido,
        'facturas' => $facturas,
        'penalizacion' => $penal,
        'disponible' => $disponible,
        'bloqueado' => $bloqueado,
        'nivel' => $nivelNum,
        'tipo' => $tipo,
        'nivel_info' => $nivelInfo,
    ];
}

function yora_credito_consumir(mysqli $db, int $comercio_id, float $monto): bool
{
    $monto = round($monto, 2);
    if ($monto <= 0) {
        return true;
    }
    $est = yora_credito_estado($db, $comercio_id);
    if ($est['bloqueado'] || $est['disponible'] + 0.001 < $monto) {
        return false;
    }
    $n = yora_exec(
        $db,
        'UPDATE comercios SET credito_consumido_ciclo = credito_consumido_ciclo + ? WHERE id = ? AND bloqueado_deuda = 0',
        'di',
        $monto,
        $comercio_id
    );
    return $n > 0;
}

function yora_credito_devolver(mysqli $db, int $comercio_id, float $monto): void
{
    $monto = round($monto, 2);
    if ($monto <= 0) {
        return;
    }
    try {
        yora_exec(
            $db,
            'UPDATE comercios SET credito_consumido_ciclo = GREATEST(0, credito_consumido_ciclo - ?) WHERE id = ?',
            'di',
            $monto,
            $comercio_id
        );
    } catch (Throwable $e) {
        error_log('yora_credito_devolver: ' . $e->getMessage());
    }
}

function yora_cron_creditos_comercio(mysqli $db): array
{
    yora_timezone($db);
    yora_ensure_creditos_schema($db);
    $resumen = ['facturas' => 0, 'gracia' => 0, 'bloqueos' => 0];
    $ayer = date('Y-m-d', strtotime('-1 day'));
    $hoy = date('Y-m-d');

    $rows = yora_all(
        $db,
        'SELECT id, credito_consumido_ciclo FROM comercios WHERE credito_consumido_ciclo > 0.009'
    ) ?: [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $monto = round((float) $r['credito_consumido_ciclo'], 2);
        if ($monto <= 0) {
            continue;
        }
        $vence = date('Y-m-d', strtotime($ayer . ' +7 days'));
        $gracia = date('Y-m-d', strtotime($vence . ' +1 day'));
        try {
            $db->begin_transaction();
            yora_exec(
                $db,
                "INSERT INTO facturas_comercio (comercio_id, fecha_consumo, monto, estatus, vence_el, gracia_hasta, penalizacion)
                 VALUES (?, ?, ?, 'pendiente', ?, ?, 0)
                 ON DUPLICATE KEY UPDATE monto = monto + VALUES(monto)",
                'isdss',
                $id,
                $ayer,
                $monto,
                $vence,
                $gracia
            );
            yora_exec($db, 'UPDATE comercios SET credito_consumido_ciclo = 0 WHERE id = ?', 'i', $id);
            $db->commit();
            $resumen['facturas']++;
        } catch (Throwable $e) {
            @$db->rollback();
            error_log('cron factura comercio ' . $id . ': ' . $e->getMessage());
        }
    }

    $g = yora_exec(
        $db,
        "UPDATE facturas_comercio SET estatus = 'gracia' WHERE estatus = 'pendiente' AND vence_el < ?",
        's',
        $hoy
    );
    $resumen['gracia'] = max(0, (int) $g);

    $q = $db->prepare("SELECT id, comercio_id FROM facturas_comercio WHERE estatus = 'gracia' AND gracia_hasta < ?");
    $q->bind_param('s', $hoy);
    $q->execute();
    $vencidas = $q->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $q->close();
    $pen = yora_penalizacion_reactivacion();
    foreach ($vencidas as $f) {
        try {
            yora_exec($db, "UPDATE facturas_comercio SET estatus = 'vencida', penalizacion = ? WHERE id = ?", 'di', $pen, (int) $f['id']);
            yora_exec(
                $db,
                'UPDATE comercios SET bloqueado_deuda = 1, penalizacion_pendiente = GREATEST(penalizacion_pendiente, ?) WHERE id = ?',
                'di',
                $pen,
                (int) $f['comercio_id']
            );
            $resumen['bloqueos']++;
        } catch (Throwable $e) {
            error_log('cron bloqueo: ' . $e->getMessage());
        }
    }

    return $resumen;
}

function yora_credito_aplicar_pago(mysqli $db, int $comercio_id, array $factura_ids, float $penalizacion_pagada = 0.0): void
{
    yora_ensure_creditos_schema($db);
    foreach ($factura_ids as $fid) {
        $fid = (int) $fid;
        if ($fid < 1) {
            continue;
        }
        yora_exec(
            $db,
            "UPDATE facturas_comercio SET estatus = 'pagada', pagado_el = NOW(), penalizacion = 0
             WHERE id = ? AND comercio_id = ? AND estatus IN ('pendiente','gracia','vencida')",
            'ii',
            $fid,
            $comercio_id
        );
    }
    if ($penalizacion_pagada > 0) {
        yora_exec(
            $db,
            'UPDATE comercios SET penalizacion_pendiente = GREATEST(0, penalizacion_pendiente - ?) WHERE id = ?',
            'di',
            $penalizacion_pagada,
            $comercio_id
        );
    }
    $pend = yora_facturas_pendientes_monto($db, $comercio_id);
    $row = yora_one($db, 'SELECT penalizacion_pendiente FROM comercios WHERE id = ?', 'i', $comercio_id);
    $pen = round((float) ($row['penalizacion_pendiente'] ?? 0), 2);
    if ($pend <= 0.009 && $pen <= 0.009) {
        yora_exec($db, 'UPDATE comercios SET bloqueado_deuda = 0, deuda_desde = NULL, penalizacion_pendiente = 0 WHERE id = ?', 'i', $comercio_id);
    }
}
