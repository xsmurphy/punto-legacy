<?php
declare(strict_types=1);

/**
 * Arnés SQL de las funciones de particionado (migs 156 y 221).
 *
 * ── Por qué existe ──────────────────────────────────────────────────────
 * `ensure_month_partitions()` está en producción desde la mig 156 y la llama
 * el job `partition-ensure` de `api/v1/maintenance.php`. La mig 221 le cambió
 * el CUERPO —lo extrajo a `ensure_month_partitions_range()` y la dejó
 * delegando— para que el import de histórico pueda declarar un rango hacia
 * atrás. Un refactor de una función viva que ningún test cubría.
 *
 * El arnés del migrador (`run_encom_migration_test.sh`) ejercita el
 * IMPORTADOR en PHP, no la función SQL: pasa por ella de casualidad y solo
 * por el camino feliz. Acá se prueba la función en sí, y sobre todo los
 * caminos que el importador NO toca en una corrida normal.
 *
 * Casos:
 *   A. PARIDAD — `ensure_month_partitions()` sigue produciendo exactamente los
 *      mismos meses y los mismos límites que antes del refactor: desde la
 *      partición mensual más vieja ya creada hasta now() + N meses, contiguos
 *      y anclados en UTC.
 *   B. TOPE de 120 meses: falla RUIDOSO (excepción), no trunca en silencio.
 *   C. IDEMPOTENCIA: llamarla dos veces no duplica ni rompe.
 *   D. ZONA HORARIA: los límites quedan en UTC aunque la sesión esté en otra
 *      zona. Es el bug que encontró el arnés del migrador —agosto se solapaba
 *      4 horas con septiembre— convertido en test de regresión.
 *   E. DEFAULT CON FILAS: se desprende, se reclasifica y no se pierde ninguna
 *      fila; la FK `transaction_registry → transaction` que la función dropea
 *      queda recreada.
 *   F. NO VA HACIA ATRÁS: un dato viejo suelto NO empuja la cobertura de
 *      `ensure_month_partitions()` — el comportamiento deliberado de la mig
 *      156, que el refactor no podía cambiar.
 *
 * Uso: bash api/tests/run_partition_range_test.sh
 */

require_once __DIR__ . '/_harness.php';

$companyId = '9c2f1a77-4b3e-4d51-8c77-0e8a5b6d9911';
$outletId  = '9c2f1a77-4b3e-4d51-8c77-0e8a5b6d9922';
$userId    = '9c2f1a77-4b3e-4d51-8c77-0e8a5b6d9933';

define('COMPANY_ID', $companyId);
define('OUTLET_ID', '');
define('USER_ID', '');
define('REGISTER_ID', '');
define('ROLE_ID', '');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';

global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/** Particiones mensuales de una tabla: nombre => expresión de límites. */
function particiones(string $tabla): array
{
    global $db;
    $rs = $db->Execute(
        "SELECT c.relname AS nombre, pg_get_expr(c.relpartbound, c.oid) AS bound
           FROM pg_class p
           JOIN pg_inherits i ON i.inhparent = p.oid
           JOIN pg_class c    ON c.oid = i.inhrelid
          WHERE p.relname = ?",
        [$tabla]
    );
    $out = [];
    if ($rs !== false) {
        foreach ($rs->GetRows() as $r) {
            $out[(string) ($r['nombre'] ?? $r['NOMBRE'] ?? '')] = (string) ($r['bound'] ?? $r['BOUND'] ?? '');
        }
    }
    return $out;
}

/** Llama a la función por rango. Devuelve los nombres creados, o false si falló. */
function crearRango(string $tabla, string $col, string $desde, string $hasta): array|false
{
    global $db;
    try {
        $json = $db->GetOne(
            'SELECT array_to_json(ensure_month_partitions_range(?::regclass, ?::name, ?::date, ?::date))',
            [$tabla, $col, $desde, $hasta]
        );
    } catch (\Throwable $e) {
        return false;
    }
    if ($json === false || $json === null) {
        return false;
    }
    $arr = json_decode((string) $json, true);
    return is_array($arr) ? $arr : [];
}

try {
    // `pg_get_expr` RENDERIZA los límites en la zona de la SESIÓN: con la
    // sesión en otra zona, una partición perfectamente anclada en UTC se
    // vería como '-04' y el test daría un falso positivo de deriva. La
    // comparación de límites solo tiene sentido con la sesión en UTC.
    $db->Execute("SET TIME ZONE 'UTC'");

    // ══════════════════════════════════════════════════════════════════
    // A. PARIDAD — mismos meses y mismos límites que antes del refactor
    // ══════════════════════════════════════════════════════════════════
    $creadas = crearRango('transaction', 'transactiondate', date('Y-m-01'), date('Y-m-01'));
    check(
        'A0 · la función por rango existe y responde',
        $creadas !== false,
        'ensure_month_partitions_range() no respondió',
        $failures, $checks
    );

    $db->GetOne("SELECT array_to_json(ensure_month_partitions('transaction'::regclass, 'transactiondate'::name, 12))");

    $antes = particiones('transaction');

    // El contrato de la función vieja: desde el mes de la partición mensual
    // MÁS VIEJA ya creada, hasta now() + 12 meses, sin huecos.
    $meses = [];
    foreach ($antes as $nombre => $_) {
        if (preg_match('/^transaction_y(\d{4})m(\d{2})$/', $nombre, $m) === 1) {
            $meses[] = $m[1] . '-' . $m[2] . '-01';
        }
    }
    sort($meses);
    $primero = $meses[0] ?? date('Y-m-01');
    $ultimo  = date('Y-m-01', strtotime('+12 months'));

    $faltantes = [];
    $malBound  = [];
    $cursor    = strtotime($primero);
    while ($cursor !== false && date('Y-m-01', $cursor) <= $ultimo) {
        $nombre = 'transaction_y' . date('Y', $cursor) . 'm' . date('m', $cursor);
        if (!isset($antes[$nombre])) {
            $faltantes[] = $nombre;
        } else {
            // Límites EXACTOS, contiguos y en UTC.
            $esperado = "FOR VALUES FROM ('" . date('Y-m-01', $cursor) . " 00:00:00+00') TO ('"
                . date('Y-m-01', (int) strtotime('+1 month', $cursor)) . " 00:00:00+00')";
            if ($antes[$nombre] !== $esperado) {
                $malBound[$nombre] = $antes[$nombre] . ' != ' . $esperado;
            }
        }
        $cursor = strtotime('+1 month', $cursor);
    }

    check(
        'A1 · cubre todos los meses desde la partición más vieja hasta now()+12, sin huecos',
        $faltantes === [],
        'faltan: ' . implode(', ', array_slice($faltantes, 0, 10)),
        $failures, $checks
    );

    check(
        'A2 · y cada mes tiene los límites EXACTOS, contiguos y anclados en UTC',
        $malBound === [],
        'límites distintos de lo esperado: ' . json_encode(array_slice($malBound, 0, 5)),
        $failures, $checks
    );

    // La DEFAULT tiene que existir siempre: es la que sostiene la regla
    // offline-first de que el back nunca rechaza una venta ya emitida.
    check(
        'A3 · la partición DEFAULT existe',
        isset($antes['transaction_default']),
        'no hay transaction_default',
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // B. El tope de 120 meses falla RUIDOSO
    // ══════════════════════════════════════════════════════════════════
    $antesTope = count(particiones('transaction'));
    $lanzo     = false;
    $mensaje   = '';
    try {
        $r = $db->GetOne(
            'SELECT array_to_json(ensure_month_partitions_range(?::regclass, ?::name, ?::date, ?::date))',
            ['transaction', 'transactiondate', '1970-01-01', date('Y-m-01')]
        );
        $lanzo = ($r === false || $r === null);
    } catch (\Throwable $e) {
        $lanzo   = true;
        $mensaje = $e->getMessage();
    }

    check(
        'B1 · un rango de 670+ meses NO se acepta (falla, no trunca en silencio)',
        $lanzo,
        'la función aceptó el rango absurdo — mensaje: ' . $mensaje,
        $failures, $checks
    );

    check(
        'B2 · y no dejó ninguna partición creada a medias',
        count(particiones('transaction')) === $antesTope,
        'cambió la cantidad de particiones: ' . $antesTope . ' → ' . count(particiones('transaction')),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // C. Idempotencia
    // ══════════════════════════════════════════════════════════════════
    $mesC   = date('Y-m-01', strtotime('-2 months'));
    $uno    = crearRango('transaction', 'transactiondate', $mesC, $mesC);
    $cuenta = count(particiones('transaction'));
    $dos    = crearRango('transaction', 'transactiondate', $mesC, $mesC);

    check(
        'C1 · la primera llamada crea el mes pedido',
        is_array($uno) && count($uno) === 1,
        'creadas = ' . json_encode($uno),
        $failures, $checks
    );

    check(
        'C2 · la segunda no crea nada, no rompe, y no duplica',
        is_array($dos) && $dos === [] && count(particiones('transaction')) === $cuenta,
        'segunda llamada = ' . json_encode($dos),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // D. ZONA HORARIA — regresión del bug que encontró el arnés del migrador
    // ══════════════════════════════════════════════════════════════════
    // Con la sesión en la zona del tenant, el límite superior del mes caía
    // DENTRO del mes siguiente y Postgres rechazaba la partición por
    // solaparse. El mes elegido es el ANTERIOR al más viejo ya creado, que es
    // exactamente el caso que choca.
    $db->Execute("SET TIME ZONE 'America/Asuncion'");

    $mesD  = date('Y-m-01', strtotime('-3 months'));
    $resD  = crearRango('transaction', 'transactiondate', $mesD, $mesD);

    $db->Execute("SET TIME ZONE 'UTC'");

    $nombreD = 'transaction_y' . date('Y', (int) strtotime($mesD)) . 'm' . date('m', (int) strtotime($mesD));
    $todasD  = particiones('transaction');

    check(
        'D1 · crear un mes desde una sesión en otra zona NO choca por solapamiento',
        is_array($resD) && in_array($nombreD, $resD, true),
        'resultado = ' . json_encode($resD) . ' (antes del fix: "would overlap")',
        $failures, $checks
    );

    check(
        'D2 · y sus límites quedan en UTC, no en la zona de la sesión',
        isset($todasD[$nombreD]) && str_contains($todasD[$nombreD], '+00'),
        'bound = ' . var_export($todasD[$nombreD] ?? null, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // E. DEFAULT CON FILAS — se reclasifica sin perder nada
    // ══════════════════════════════════════════════════════════════════
    // Es el camino caro de la función (dropear FK, DETACH, mover, ATTACH,
    // recrear FK) y el que el importador NO ejercita, porque asegura las
    // particiones ANTES de insertar.
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Particiones Test\"}'::jsonb)
         ON CONFLICT (companyId) DO NOTHING",
        [$companyId]
    );
    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
         ON CONFLICT (outletId) DO NOTHING',
        [$outletId, 'Sucursal Particiones', $companyId]
    );
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
         VALUES (?, ?, ?, ?, 0, 1) ON CONFLICT (contactId) DO NOTHING',
        [$userId, 'Usuario Particiones', $companyId, $outletId]
    );

    // Un mes SIN partición: las filas caen en la DEFAULT.
    $mesE  = date('Y-m-01', strtotime('-7 months'));
    $fecha = date('Y-m-15 10:00:00', (int) strtotime($mesE));

    for ($i = 0; $i < 3; $i++) {
        $db->Execute(
            "INSERT INTO transaction
               (companyId, outletId, userId, transactionType, transactionStatus, transactionComplete,
                transactionTotal, transactionDate)
             VALUES (?, ?, ?, 0, 1, TRUE, ?, ?::timestamptz)",
            [$companyId, $outletId, $userId, 1000.0 + $i, $fecha]
        );
    }

    $enDefault = (int) $db->GetOne(
        'SELECT count(*) FROM transaction_default WHERE companyId = ?',
        [$companyId]
    );

    check(
        'E1 · con el mes sin partición, las filas caen en la DEFAULT (no fallan)',
        $enDefault === 3,
        "filas en transaction_default = $enDefault, esperado 3",
        $failures, $checks
    );

    $registryAntes = (int) $db->GetOne(
        'SELECT count(*) FROM transaction_registry WHERE companyId = ?',
        [$companyId]
    );

    $resE    = crearRango('transaction', 'transactiondate', $mesE, $mesE);
    $nombreE = 'transaction_y' . date('Y', (int) strtotime($mesE)) . 'm' . date('m', (int) strtotime($mesE));

    $enMes = (int) $db->GetOne(
        "SELECT count(*) FROM $nombreE WHERE companyId = ?",
        [$companyId]
    );
    $quedanEnDefault = (int) $db->GetOne(
        'SELECT count(*) FROM transaction_default WHERE companyId = ?',
        [$companyId]
    );
    $total = (int) $db->GetOne(
        'SELECT count(*) FROM transaction WHERE companyId = ?',
        [$companyId]
    );

    check(
        'E2 · las 3 filas se reclasifican a su partición mensual',
        $enMes === 3 && $quedanEnDefault === 0,
        "en $nombreE = $enMes, quedan en default = $quedanEnDefault",
        $failures, $checks
    );

    check(
        'E3 · y no se perdió ninguna en el camino',
        $total === 3,
        "total de filas = $total, esperado 3",
        $failures, $checks
    );

    // La función DROPEA las FK que apuntan a `transaction` para poder
    // desprender la default, y las recrea. Si se olvidara de recrear una, la
    // integridad quedaría rota en silencio.
    $fk = (int) $db->GetOne(
        "SELECT count(*) FROM pg_constraint WHERE conname = 'transaction_registry_transaction_fkey'"
    );
    check(
        'E4 · la FK transaction_registry → transaction quedó RECREADA',
        $fk === 1,
        "constraint encontrada = $fk, esperado 1",
        $failures, $checks
    );

    check(
        'E5 · y el registry conserva sus filas (el DELETE de la copia no cascadeó)',
        (int) $db->GetOne('SELECT count(*) FROM transaction_registry WHERE companyId = ?', [$companyId]) === $registryAntes
            && $registryAntes === 3,
        'registry antes/después = ' . $registryAntes . '/'
            . $db->GetOne('SELECT count(*) FROM transaction_registry WHERE companyId = ?', [$companyId]),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // F. Un dato viejo suelto NO empuja la cobertura hacia atrás
    // ══════════════════════════════════════════════════════════════════
    // Comportamiento deliberado de la mig 156: una fecha basura no puede
    // forzar la creación de años de particiones vacías. El refactor no podía
    // cambiarlo, y por eso el histórico pide su rango EXPLÍCITO en vez de
    // apoyarse en esta función.
    $db->Execute(
        "INSERT INTO transaction
           (companyId, outletId, userId, transactionType, transactionStatus, transactionComplete,
            transactionTotal, transactionDate)
         VALUES (?, ?, ?, 0, 1, TRUE, 99.0, '2019-03-10 12:00:00+00'::timestamptz)",
        [$companyId, $outletId, $userId]
    );

    $db->GetOne("SELECT array_to_json(ensure_month_partitions('transaction'::regclass, 'transactiondate'::name, 12))");

    $todasF = particiones('transaction');

    check(
        'F1 · una fecha de 2019 NO hace que se creen las particiones de 2019',
        !isset($todasF['transaction_y2019m03']),
        'se creó transaction_y2019m03 — la función se dejó empujar hacia atrás',
        $failures, $checks
    );

    check(
        'F2 · esa fila se queda en la DEFAULT, que es donde tiene que estar',
        (int) $db->GetOne('SELECT count(*) FROM transaction_default WHERE companyId = ?', [$companyId]) === 1,
        'filas viejas en default = ' . $db->GetOne('SELECT count(*) FROM transaction_default WHERE companyId = ?', [$companyId]),
        $failures, $checks
    );
} finally {
    foreach ([
        'DELETE FROM transaction WHERE companyId = ?',
        'DELETE FROM contact WHERE companyId = ?',
        'DELETE FROM outlet WHERE companyId = ?',
        'DELETE FROM company WHERE companyId = ?',
    ] as $sql) {
        try {
            $db->Execute($sql, [$companyId]);
        } catch (\Throwable $e) {
            // base descartable: la limpieza no puede voltear el resultado
        }
    }
}

harnessFinish($failures, $checks);
