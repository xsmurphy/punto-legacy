<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
use Punto\Api\Support\TenantClock;
use Punto\Api\Services\RegisterLeaseService;
// DB not needed (uses ncmExecute helpers)

/**
 * DrawerService — operaciones de caja/drawer del POS (Slice 26).
 *
 * Lógica portada de app/load.php:
 *   isOpen  (L1664, branch chk=1) — verifica si el cajón está abierto.
 *   getSummary (L1664)             — resumen completo de la caja (ventas, gastos, ingresos).
 *
 * getSalesByPayment() y helpers relacionados (isParentInternalSale, isInternalSale,
 * groupByPaymentMethod) están disponibles vía api/bootstrap.php → head.php → functions.php.
 * REGISTER_ID, OUTLET_ID, COMPANY_ID son constantes definidas tras apiAuthTenant().
 *
 * Anulación de ventas (`voidedAt`, mig 154, context/40-anulacion-y-nota-credito.md):
 * este servicio NO usa `SaleFilters::notVoidedSql()` a propósito. Es reconciliación
 * de turno/caja, no un reporte de ventas — "¿una venta anulada A MITAD de turno sigue
 * apareciendo en el cierre de ESE turno?" es una pregunta de producto sin cerrar (ver
 * docblock de `Reports\SaleFilters`), no una omisión.
 */

final class DrawerService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Verifica si el cajón está abierto (hay una fila sin cerrar).
     *
     * @return bool true si hay un drawer abierto para el registro/outlet/company actuales.
     */
    public function isOpen(string $registerId, string $outletId, string $companyId): bool
    {
        $drwr = ncmExecute(
            "SELECT drawerOpenDate FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 01:00:00')
             ORDER BY drawerOpenDate DESC LIMIT 1",
            [$registerId, $outletId, $companyId]
        );
        return (bool) $drwr;
    }

    // ========================================================================
    // RECURSOS GRANULARES (patrón BFF-compone — ver §22.12)
    //
    // getSummary() abajo es el composite legacy (la API arma toda la pantalla).
    // Estos métodos exponen las piezas reusables del cierre de caja: cada uno
    // un concepto independiente (drawer abierto, extracciones, ingresos, ventas
    // por método de pago) que un reporte/dashboard puede pedir por separado. El
    // BFF (app/bff/drawer.php) hace fetch del drawer abierto, luego los hijos en
    // paralelo con `since=drawerOpenDate`, y computa el rollup (subtotal/total/…).
    // getSummary() queda como conveniencia/backward-compat.
    // ========================================================================

    /** Drawer abierto actual (la fila sin cerrar). null si la caja está cerrada. */
    public function getOpen(string $registerId, string $outletId, string $companyId): ?array
    {
        // Alias quoted para preservar camelCase post-refactor 28-jun (array
        // plano lowercase). Sin esto $drwr['drawerOpenDate'] devolvía null y
        // getSummary pasaba null a getExpenses(string $since) → TypeError.
        $drwr = ncmExecute(
            'SELECT drawerId         AS "drawerId",
                    drawerOpenDate   AS "drawerOpenDate",
                    drawerOpenAmount AS "drawerOpenAmount"
             FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 01:00:00\')
             ORDER BY drawerOpenDate DESC LIMIT 1',
            [$registerId, $outletId, $companyId]
        );
        if (!$drwr) {
            return null;
        }
        return [
            'drawerId'         => $drwr['drawerId'] ?? null,
            'drawerOpenDate'   => $drwr['drawerOpenDate'],
            'drawerOpenAmount' => $drwr['drawerOpenAmount'] ? (float) $drwr['drawerOpenAmount'] : 0.0,
        ];
    }

    /** Extracciones (efectivo) del registro desde `$since`. */
    public function getExpenses(string $registerId, string $since): array
    {
        $exp = ncmExecute(
            'SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND type IS NULL AND registerId = ?',
            [$since, $registerId]
        );
        return ['amount' => ($exp && $exp['expense']) ? (float) $exp['expense'] : 0.0];
    }

    /** Ingresos (efectivo) del registro desde `$since`, separando propinas. */
    public function getIncome(string $registerId, string $since): array
    {
        $inc = ncmExecute(
            'SELECT expensesAmount, expensesDescription FROM expenses WHERE expensesDate > ? AND type = 1 AND registerId = ?',
            [$since, $registerId],
            false,
            true
        );
        $totalIncome = 0.0;
        $totalTips   = 0.0;
        if ($inc) {
            while (!$inc->EOF) {
                $f = $inc->fields;
                if ($f['expensesDescription'] === 'PROPINA') {
                    $totalTips += (float) $f['expensesAmount'];
                }
                $totalIncome += (float) $f['expensesAmount'];
                $inc->MoveNext();
            }
            $inc->Close();
        }
        return ['total' => $totalIncome, 'tips' => $totalTips];
    }

    /**
     * Ventas agrupadas por método de pago del registro de la sesión de caja.
     * Filtra por `$drawerId` (exacto) + fallback por `$since` para filas NULL
     * (mig 70). `$drawerId` null → solo fallback por fecha (backward-compat).
     */
    public function getPaymentBreakdown(string $registerId, string $since, ?string $drawerId = null): array
    {
        $detail = getSalesByPayment($since, false, $registerId, $drawerId);
        $out    = [];
        if (validity($detail, 'array')) {
            foreach ($detail as $arr) {
                $name = str_replace('u00e9', 'é', $arr['name']);
                $out[] = [
                    'name'  => $name,
                    'type'  => $arr['type'],
                    'price' => (float) $arr['price'],
                    // Clave de agrupación tal como la resolvió
                    // `groupByPaymentMethod()`. Viaja hasta `composeSummary()`
                    // para que el arqueo por medio use la identidad con la que
                    // las filas ya se juntaron, no una re-normalización.
                    'groupKey' => (string) ($arr['groupKey'] ?? self::paymentGroupKey($name)),
                ];
            }
        }
        return $out;
    }

    /**
     * Productos vendidos en la SESIÓN de caja actual, agrupados por item.
     *
     * `itemSold` no tiene registerId/companyId propios (línea de detalle) →
     * JOIN a `transaction` para el filtro de sesión + companyId. Ventas
     * (type 0/3) suman, devoluciones (type 6) restan: `itemSold` YA guarda
     * esas líneas con signo invertido (`flipOnReturn` en
     * `SaleService::persistItemsAndStock`) → un SUM directo por item netea
     * sin lógica extra. Mismo filtro belt-and-suspenders (drawerid exacto +
     * fallback por fecha, mig 70) y exclusión de internas que
     * `getSalesByPayment` (functions.php:1033) / `getPaymentBreakdown`.
     *
     * @return array<int,array{name:string,qty:float,total:float}> ordenado por total desc.
     */
    public function getSoldProducts(string $registerId, string $since, ?string $drawerId = null): array
    {
        if ($drawerId !== null && $drawerId !== '') {
            $dateSql    = '(t.drawerid = ? OR (t.drawerid IS NULL AND t.transactionDate > ?))';
            $dateParams = [$drawerId, $since];
        } else {
            $dateSql    = 't.transactionDate > ?';
            $dateParams = [$since];
        }

        $sql = 'SELECT t.transactionId, a.itemId, a.itemSoldUnits, a.itemSoldTotal, a.itemSoldDescription,
                       i.itemName, t.transactionType, t.meta->>\'tags\' AS tags
                FROM itemSold a
                JOIN transaction t ON t.transactionId = a.transactionId
                LEFT JOIN item i ON i.itemId = a.itemId AND i.companyId = ?
                WHERE ' . $dateSql . '
                  AND t.transactionType IN (0,3,6)
                  AND t.registerId = ?
                  AND t.companyId = ?';
        $params = array_merge([COMPANY_ID], $dateParams, [$registerId, COMPANY_ID]);

        $result = ncmExecute($sql, $params, false, true);
        $group  = [];
        if ($result) {
            $rows = [];
            while (!$result->EOF) {
                $rows[] = $result->fields;
                $result->MoveNext();
            }
            $result->Close();

            // transactionParentId dropeada (mig 115) — batch lookup del origen
            // de las devoluciones (type 6) vía transaction_link, sin N+1.
            $returnIds = array_values(array_filter(array_map(
                static fn($f) => (int) $f['transactionType'] === 6 ? (string) $f['transactionId'] : null,
                $rows
            )));
            $originByReturn = $returnIds !== []
                ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds(COMPANY_ID, $returnIds, 'return')
                : [];

            foreach ($rows as $f) {
                if ((int) $f['transactionType'] === 6) {
                    $parentId = $originByReturn[(string) $f['transactionId']] ?? null;
                    $ignore   = $parentId ? isParentInternalSale($parentId) : false;
                } else {
                    $ignore = isInternalSale(json_decode((string) $f['tags'], true));
                }
                if ($ignore) {
                    continue;
                }

                $itemId = (string) $f['itemId'];
                $name   = (string) ($f['itemName'] ?: ($f['itemSoldDescription'] ?: 'Producto eliminado'));

                if (!isset($group[$itemId])) {
                    $group[$itemId] = ['name' => $name, 'qty' => 0.0, 'total' => 0.0];
                }
                $group[$itemId]['qty']   += (float) $f['itemSoldUnits'];
                $group[$itemId]['total'] += (float) $f['itemSoldTotal'];
            }
        }

        $out = array_values($group);
        usort($out, static fn(array $a, array $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /**
     * Cantidad de ventas y de clientes atendidos en la SESIÓN de caja actual.
     *
     * Mismo scoping/exclusión que `getPaymentBreakdown` (que delega en
     * `getSalesByPayment` de functions.php): filtro drawerId exacto + fallback
     * por fecha (mig 70), `transactionType IN (0,5,6)` (venta/cobro/NC), y
     * exclusión de ventas internas (`isInternalSale`/`isParentInternalSale` —
     * mismo criterio para type 5 vs el resto). Las notas de crédito
     * (type 6, "anuladas" vía devolución) NO cuentan como venta ni suman
     * cliente — mismo criterio que `composeSummary` separa `$return` de `$total`.
     *
     * @return array{salesCount:int,customersCount:int}
     */
    public function getSaleStats(string $registerId, string $since, ?string $drawerId = null): array
    {
        if ($drawerId !== null && $drawerId !== '') {
            $dateSql    = '(t.drawerid = ? OR (t.drawerid IS NULL AND t.transactionDate > ?))';
            $dateParams = [$drawerId, $since];
        } else {
            $dateSql    = 't.transactionDate > ?';
            $dateParams = [$since];
        }

        $sql = 'SELECT t.transactionId, t.customerId, t.transactionType, t.meta->>\'tags\' AS tags
                FROM transaction t
                WHERE ' . $dateSql . '
                  AND t.transactionType IN (0,5,6)
                  AND t.registerId = ?
                  AND t.companyId = ?';
        $params = array_merge($dateParams, [$registerId, $this->ctx->companyId]);

        $result      = ncmExecute($sql, $params, false, true);
        $salesCount  = 0;
        $customerIds = [];
        if ($result) {
            $rows = [];
            while (!$result->EOF) {
                $rows[] = $result->fields;
                $result->MoveNext();
            }
            $result->Close();

            // transactionParentId dropeada (mig 115) — batch lookup del origen
            // de los pagos (type 5) vía transaction_link, sin N+1.
            $paymentIds = array_values(array_filter(array_map(
                static fn($f) => (int) $f['transactionType'] === 5 ? (string) $f['transactionId'] : null,
                $rows
            )));
            $originByPayment = $paymentIds !== []
                ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($this->ctx->companyId, $paymentIds, 'credit_payment')
                : [];

            foreach ($rows as $f) {
                if ((int) $f['transactionType'] === 6) {
                    // Nota de crédito/devolución — no es venta.
                    continue;
                }

                if ((int) $f['transactionType'] === 5) {
                    $parentId = $originByPayment[(string) $f['transactionId']] ?? null;
                    $ignore   = $parentId ? isParentInternalSale($parentId) : false;
                } else {
                    $ignore = isInternalSale(json_decode((string) $f['tags'], true));
                }
                if ($ignore) {
                    continue;
                }

                $salesCount++;
                $customerId = $f['customerId'] ?? null;
                if ($customerId !== null && $customerId !== '') {
                    $customerIds[(string) $customerId] = true;
                }
            }
        }

        return ['salesCount' => $salesCount, 'customersCount' => count($customerIds)];
    }

    /**
     * Ventas por HORA para el mini-dashboard del menú del POS. Devuelve TRES
     * series independientes de la misma caja:
     *
     *   - `shift`     → el TURNO en curso (ventana de la sesión de caja). Es la
     *                   única serie que sobrevive a un turno multi-día, y es lo
     *                   que el cajero realmente quiere ver: SU turno.
     *   - `today`     → el día calendario LOCAL de HOY.
     *   - `yesterday` → el día calendario LOCAL de AYER.
     *
     * `today`/`yesterday` salen del RELOJ del tenant (`TenantClock::now()`), NO
     * de la fecha de apertura del turno. Antes "hoy" ERA el turno y "ayer" el
     * día anterior al de la APERTURA: con un turno abierto hace 2 días eso
     * comparaba la ventana entera del turno contra un día calendario de hace 3
     * días, y el delta "vs ayer a esta hora" quedaba sin sentido (bug reportado
     * 2026-08-02). Con el turno dentro de hoy, `shift` ≈ `today` — son la misma
     * plata, no hay doble conteo: el consumidor elige qué series pinta.
     *
     * Scoping y exclusiones IDÉNTICOS a `getSaleStats()`: filtro de sesión
     * drawerId exacto + fallback por fecha (mig 70) para `shift`,
     * `transactionType IN (0,5,6)`, type 6 (nota de crédito) no cuenta como
     * venta, y ventas internas fuera (`isInternalSale` / `isParentInternalSale`
     * según el tipo). La única diferencia es que acá además se acumula monto:
     * `abs(transactionTotal)` por transacción — `getSaleStats` no maneja plata y
     * el breakdown por método de pago (`getPaymentBreakdown`) no sirve para
     * agrupar por hora porque agrupa por método, no por transacción.
     *
     * Agrupación horaria: `transaction.transactionDate` es **TIMESTAMPTZ**
     * (db-schema-postgres.sql:319), así que el bucket se calcula con
     * `date_trunc('hour', transactionDate AT TIME ZONE <tz del tenant>)`. La
     * sesión de PG ya corre en 'America/Asuncion' (api/includes/db.php:68), pero
     * el `AT TIME ZONE` explícito con `TenantClock::timezone()` hace el corte
     * correcto para cualquier tenant sin depender de ese default de conexión.
     *
     * @return array{timezone:string,shift:array<int,array{hour:string,salesTotal:float,salesCount:int}>,today:array<int,array{hour:string,salesTotal:float,salesCount:int}>,yesterday:array<int,array{hour:string,salesTotal:float,salesCount:int}>}
     */
    public function getHourlyStats(string $registerId, string $since, ?string $drawerId = null): array
    {
        $tz = TenantClock::timezone($this->ctx->companyId);

        // TURNO = la ventana de la sesión de caja (la misma que el resto del
        // resumen). Puede abarcar varios días calendario.
        if ($drawerId !== null && $drawerId !== '') {
            $shiftSql    = '(t.drawerid = ? OR (t.drawerid IS NULL AND t.transactionDate > ?))';
            $shiftParams = [$drawerId, $since];
        } else {
            $shiftSql    = 't.transactionDate > ?';
            $shiftParams = [$since];
        }

        // HOY / AYER = días calendario LOCALES del tenant. `TenantClock::now()`
        // resuelve la TZ configurada por DateTimeZone explícita, así que la
        // fecha no depende del default del proceso (UTC en el container de
        // prod). Sobre esa fecha PELADA ('Y-m-d') sí es seguro restar un día:
        // no tiene offset que `strtotime()` pueda reinterpretar.
        $todayDate = substr(TenantClock::now($this->ctx->companyId), 0, 10);
        $prevDay   = date('Y-m-d', strtotime($todayDate . ' -1 day'));
        $daySql    = '(t.transactionDate AT TIME ZONE ?::text)::date = ?::date';

        return [
            'timezone'  => $tz,
            'shift'     => $this->hourlyBuckets($registerId, $tz, $shiftSql, $shiftParams),
            'today'     => $this->hourlyBuckets($registerId, $tz, $daySql, [$tz, $todayDate]),
            'yesterday' => $this->hourlyBuckets($registerId, $tz, $daySql, [$tz, $prevDay]),
        ];
    }

    /**
     * Ejecuta la query horaria con un filtro de fecha dado y devuelve los buckets
     * no vacíos ordenados cronológicamente. La exclusión de internas es PHP (los
     * helpers `isInternalSale`/`isParentInternalSale` viven en functions.php), así
     * que el GROUP BY no puede ir en SQL: se agrupa acá con la hora ya truncada
     * por Postgres.
     *
     * @param array<int,mixed> $dateParams
     * @return array<int,array{hour:string,salesTotal:float,salesCount:int}>
     */
    private function hourlyBuckets(string $registerId, string $tz, string $dateSql, array $dateParams): array
    {
        $sql = "SELECT t.transactionId,
                       to_char(date_trunc('hour', t.transactionDate AT TIME ZONE ?::text), 'YYYY-MM-DD HH24:00') AS hour,
                       abs(t.transactionTotal) AS total,
                       t.transactionType, t.meta->>'tags' AS tags
                FROM transaction t
                WHERE " . $dateSql . "
                  AND t.transactionType IN (0,5,6)
                  AND t.registerId = ?
                  AND t.companyId = ?";
        $params = array_merge([$tz], $dateParams, [$registerId, $this->ctx->companyId]);

        $result  = ncmExecute($sql, $params, false, true);
        $buckets = [];
        if ($result) {
            $rows = [];
            while (!$result->EOF) {
                $rows[] = $result->fields;
                $result->MoveNext();
            }
            $result->Close();

            // transactionParentId dropeada (mig 115) — batch lookup del origen
            // de los pagos (type 5) vía transaction_link, sin N+1.
            $paymentIds = array_values(array_filter(array_map(
                static fn($f) => (int) $f['transactionType'] === 5 ? (string) $f['transactionId'] : null,
                $rows
            )));
            $originByPayment = $paymentIds !== []
                ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($this->ctx->companyId, $paymentIds, 'credit_payment')
                : [];

            foreach ($rows as $f) {
                if ((int) $f['transactionType'] === 6) {
                    // Nota de crédito/devolución — no es venta (igual que getSaleStats).
                    continue;
                }

                if ((int) $f['transactionType'] === 5) {
                    $parentId = $originByPayment[(string) $f['transactionId']] ?? null;
                    $ignore   = $parentId ? isParentInternalSale($parentId) : false;
                } else {
                    $ignore = isInternalSale(json_decode((string) $f['tags'], true));
                }
                if ($ignore) {
                    continue;
                }

                $hour = (string) $f['hour'];
                if (!isset($buckets[$hour])) {
                    $buckets[$hour] = ['hour' => $hour, 'salesTotal' => 0.0, 'salesCount' => 0];
                }
                $buckets[$hour]['salesTotal'] += (float) $f['total'];
                $buckets[$hour]['salesCount']++;
            }
        }

        ksort($buckets);
        return array_values($buckets);
    }

    // ========================================================================
    // MUTACIONES — porteadas de app/action.php (handlers openCloseDrawer,
    // expense, drwrIncome). Antes estas acciones iban a /action.php (monolito
    // legacy); ahora van a POST /v1/drawer.php → estos métodos.
    // ========================================================================

    /**
     * Abre la caja. Falla si ya hay una abierta (devuelve 'Already Open').
     *
     * @return string|true 'Already Open' si ya está abierta; true en éxito.
     * @throws \RuntimeException en error de DB.
     */
    public function open(float $amount, string $date, string $userId): string|true
    {
        global $db;

        $existing = $this->findOpenRow($this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId);
        if ($existing !== null) {
            return 'Already Open';
        }

        try {
            $ok = $db->Execute(
                'INSERT INTO drawer
                    (drawerOpenDate, drawerOpenAmount, drawerUserOpen, drawerUID,
                     registerId, outletId, companyId)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$date, $amount, $userId, 0,
                 $this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId]
            );
        } catch (\Throwable $e) {
            // El índice uidx_drawer_register_open (mig 34) protege contra race:
            // si dos requests pasan el findOpenRow simultáneamente, el segundo
            // falla con unique violation acá.
            if (stripos($e->getMessage(), 'uidx_drawer_register_open') !== false
                || stripos($e->getMessage(), 'duplicate key') !== false) {
                return 'Already Open';
            }
            throw new \RuntimeException($e->getMessage());
        }
        if ($ok === false) {
            throw new \RuntimeException($db->ErrorMsg() ?: 'Error al abrir caja');
        }

        return true;
    }

    /**
     * Cierra la caja abierta. Falla si ya está cerrada o si la fecha de cierre
     * es anterior a la de apertura.
     *
     * @param float $amount Efectivo contado. Sigue siendo EL EFECTIVO y nada
     *   más: es lo que se compara contra `drawerExpectedAmount` (mig 164) y lo
     *   que alimenta el semáforo de cuadre del panel. El resto de los medios
     *   viaja en `$countedByMethod`.
     * @param array<int,array{key?:string,name?:string,isCash?:bool,counted:float|string}> $countedByMethod
     *   Lo contado medio por medio (mig 169). Vacío = cliente viejo o cierre
     *   encolado antes del deploy: se sintetiza la fila del efectivo con
     *   `$amount` y el cierre queda idéntico al de siempre.
     * @param array|null $closingTotals Arqueo del turno ya leído por el caller
     *   (`getClosingTotals()`). Se acepta de afuera porque el endpoint lo
     *   necesita para la respuesta y leerlo dos veces abre la puerta a que el
     *   número que se congela y el que se le informa al cajero no sean el
     *   mismo. `null` = leerlo acá (camino de los callers que no lo tienen).
     *
     * @return string|true 'Already Closed' / 'Invalid Close Date' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function close(
        float $amount,
        string $date,
        string $userId,
        array $countedByMethod = [],
        ?array $closingTotals = null,
    ): string|true {
        global $db;

        $row = $this->findOpenRow($this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId);
        if ($row === null) {
            return 'Already Closed';
        }

        if (strtotime((string) $row['drawerOpenDate']) > strtotime($date)) {
            return 'Invalid Close Date';
        }

        // Guard FK drawerUserClose → contact(contactId): si el userId de la sesión
        // (JWT) no existe como contact de este company (identidad huérfana — contact
        // borrado o de otro realm), el UPDATE de abajo violaría la FK y el cierre de
        // caja tiraba 500 opaco. drawerUserClose es NULLABLE (db-schema-postgres.sql
        // ~L869, "NULL while open") → degradamos a NULL en vez de romper el cierre.
        $userIdForClose = $userId !== '' ? $userId : null;
        if ($userIdForClose !== null) {
            $contactExists = ncmExecute(
                'SELECT contactId FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                [$userIdForClose, $this->ctx->companyId]
            );
            if (!$contactExists) {
                error_log("[DrawerService::close] userId={$userId} no existe como contact (companyId={$this->ctx->companyId}, drawerId={$row['drawerId']}) — drawerUserClose se guarda NULL para no bloquear el cierre.");
                $userIdForClose = null;
            }
        }

        // Efectivo ESPERADO, congelado en el mismo UPDATE que cierra la caja
        // (mig 164). Se lee ANTES del UPDATE porque después la fila ya no está
        // abierta y `getOpen()` no la encuentra.
        //
        // Es `subtotal` y no `total`: lo que el cajero cuenta son billetes, y
        // `total` incluye tarjetas y crédito, que no están en el cajón. Es
        // además EXACTAMENTE el número que la caja rotula "Total efectivo" en
        // pantalla (`pos-main-menu.tsx`) — el arqueo se audita contra lo que el
        // cajero tenía delante, no contra un número mejor calculado después.
        //
        // Best-effort: la caja SIEMPRE cierra. El POS es offline-first y un
        // cierre es irreversible del lado del cajero — que falle una lectura
        // de reporte no puede dejarlo con el turno abierto. Sin esperado, el
        // reporte muestra el cierre como estimado, que es lo mismo que le pasa
        // a los cierres anteriores a esta migración.
        //
        // Sale de `getClosingTotals()` y no de `getSummary()`: es el MISMO
        // `subtotal` —`composeSummary()` lo calcula sin mirar `$products` ni
        // `$stats`— pero sin las dos queries con JOIN a `itemSold` que arman
        // los productos vendidos y las estadísticas del turno, que en este
        // camino no lee nadie. Además deja al cierre y a la respuesta del
        // endpoint (`api/v1/drawer.php`, que devuelve estos totales para que el
        // POS reconcilie un cierre hecho sin conexión) leyendo por la misma
        // puerta: si mañana cambia la fórmula del arqueo, no hay dos caminos
        // que puedan quedar en desacuerdo.
        $expectedCash = null;
        $totals       = $closingTotals;
        try {
            if ($totals === null) {
                $totals = $this->getClosingTotals($this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId);
            }
            if ($totals !== null) {
                $expectedCash = (float) $totals['subtotal'];
            }
        } catch (\Throwable $e) {
            error_log("[DrawerService::close] no se pudo congelar el esperado (drawerId={$row['drawerId']}): " . $e->getMessage());
        }

        try {
            $ok = ncmExecute(
                'UPDATE drawer SET drawerCloseDate = ?, drawerCloseAmount = ?, drawerUserClose = ?, drawerExpectedAmount = ? WHERE drawerId = ?',
                [$date, $amount, $userIdForClose, $expectedCash, $row['drawerId']]
            );
        } catch (\Throwable $e) {
            error_log("[DrawerService::close] UPDATE excepción drawerId={$row['drawerId']} userId={$userId}: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage());
        }

        if ($ok === false) {
            global $db;
            $err = $db->ErrorMsg() ?: 'Error al cerrar caja';
            error_log("[DrawerService::close] UPDATE falló drawerId={$row['drawerId']} userId={$userId}: {$err}");
            throw new \RuntimeException($err);
        }

        // Post-fix ncmExecute (commit 32431817): DML exitoso devuelve Affected_Rows()
        // (int, false = error real de DB, ya manejado arriba). 0 filas = la caja ya
        // no matcheaba el WHERE (race entre el findOpenRow de arriba y este UPDATE —
        // otro request cerró la misma caja primero) → tratarlo como éxito idempotente,
        // no como 500.
        if ($ok === 0) {
            return 'Already Closed';
        }

        // Arqueo POR MEDIO DE PAGO congelado (mig 169). Va DESPUÉS del UPDATE
        // porque el cierre es el hecho y esto es su detalle: si el UPDATE no
        // pasó (caja ya cerrada por otro request), no hay cierre al que
        // colgarle un arqueo.
        //
        // Best-effort por la misma razón que el esperado de la mig 164: la
        // caja YA quedó cerrada arriba, y un fallo escribiendo el detalle no
        // puede devolverle un 500 al cajero sobre un cierre que sí ocurrió.
        // Lo que se pierde es el desglose del informe, no el cierre.
        try {
            $this->persistCount(
                (string) $row['drawerId'],
                $totals['expectedByMethod'] ?? null,
                $countedByMethod,
                $amount,
                $expectedCash,
            );
        } catch (\Throwable $e) {
            error_log("[DrawerService::close] no se pudo congelar el arqueo por medio (drawerId={$row['drawerId']}): " . $e->getMessage());
        }

        // Cerrar caja libera la tenencia de este MISMO device (context/29 §4.4:
        // "se libera al cerrar caja, o por revocación de admin" — la primera mitad
        // de esa promesa nunca estaba implementada, bug real 2026-08-19). Solo
        // cuando el que cierra es un device (pos-app): un cierre hecho desde el
        // panel (realm panel, deviceId vacío) no tiene un device propio que
        // liberar — ese camino ya tiene su salida explícita en "Liberar caja".
        // Self-release únicamente: jamás la tenencia de OTRO device, eso sería
        // el pisado automático que el owner rechazó (context/29 §6).
        //
        // Best-effort: la caja YA quedó cerrada en BD arriba. Desde 2026-08-22
        // el wrapper LANZA ante un error de SQL, así que un fallo al liberar la
        // tenencia devolvería 500 sobre un cierre que sí ocurrió — el cajero
        // vería "no se pudo cerrar" con la caja cerrada. La tenencia huérfana
        // se resuelve igual desde el panel ("Liberar caja"); el cierre no.
        if ($this->ctx->deviceId !== '') {
            try {
                RegisterLeaseService::releaseByDevice(
                    $this->ctx->deviceId,
                    $this->ctx->companyId,
                    'device:' . $this->ctx->deviceId,
                    'released',
                );
            } catch (\Throwable $e) {
                error_log('[DrawerService] releaseByDevice falló tras cerrar caja (device '
                    . $this->ctx->deviceId . '): ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Congela el arqueo por medio de pago del cierre (`drawer_count`, mig 169).
     *
     * `ON CONFLICT (drawerid, methodkey) DO UPDATE`: un cierre encolado que se
     * reenvía (la cola offline reintentando) tiene que dejar UN arqueo, no dos
     * juegos de filas. La idempotencia vive en la clave, no en un chequeo
     * previo que otra conexión puede ganar.
     *
     * @param array<int,array{key:string,name:string,isCash:bool,expected:float}>|null $expectedByMethod
     *   `null` = no se pudo leer el arqueo del servidor. Las filas se escriben
     *   igual, con esperado NULL: lo que el cajero contó es un hecho y no se
     *   tira porque el otro lado de la comparación no esté.
     * @param array<int,array> $countedByMethod Lo declarado por la caja.
     * @param float $cashAmount Efectivo contado (compat: cliente sin desglose).
     */
    private function persistCount(
        string $drawerId,
        ?array $expectedByMethod,
        array $countedByMethod,
        float $cashAmount,
        ?float $expectedCash,
    ): void {
        // Cliente sin desglose (o cierre encolado de antes del deploy): el
        // único medio que declaró es el efectivo. Se escribe igual para que el
        // informe por medio tenga una sola forma, con menos filas.
        if ($countedByMethod === []) {
            $countedByMethod = [[
                'key'     => self::paymentGroupKey(self::CASH_METHOD_NAME),
                'name'    => self::CASH_METHOD_NAME,
                'isCash'  => true,
                'counted' => $cashAmount,
            ]];
        }

        $rows = self::composeArqueo($expectedByMethod ?? [], $countedByMethod);

        foreach ($rows as $r) {
            // Un medio ESPERADO que el cajero no contó no se persiste con
            // contado 0: sería declarar que contó y no había nada, cuando lo
            // que pasó es que no lo contó. Sin declaración no hay fila.
            if ($r['counted'] === null) {
                continue;
            }
            // El esperado del efectivo sale del mismo número que la mig 164
            // congeló en `drawerExpectedAmount`: dos escrituras, un solo
            // cálculo, imposible que discrepen.
            $expected = $r['isCash'] && $expectedCash !== null ? $expectedCash : $r['expected'];
            ncmExecute(
                'INSERT INTO drawer_count
                    (drawerid, companyid, methodkey, methodname, iscash, expectedamount, countedamount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (drawerid, methodkey) DO UPDATE SET
                    methodname     = EXCLUDED.methodname,
                    iscash         = EXCLUDED.iscash,
                    expectedamount = EXCLUDED.expectedamount,
                    countedamount  = EXCLUDED.countedamount',
                [
                    $drawerId,
                    $this->ctx->companyId,
                    $r['key'],
                    $r['name'],
                    $r['isCash'] ? 't' : 'f',
                    $expected,
                    $r['counted'],
                ]
            );
        }
    }

    /**
     * Registra una extracción de efectivo desde la caja.
     * Idempotente: si ya existe un movimiento con el mismo monto y fecha para
     * este registro, devuelve 'Expense Already Exists' sin crear duplicado.
     *
     * @return string|true 'Expense Already Exists' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function addExpense(float $amount, string $note, string $date): string|true
    {
        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [$amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Expense Already Exists';
        }

        // expensesNameId es NULL para movimientos de caja (mig 33 hace la columna nullable).
        // type IS NULL = extracción (según DrawerService::getExpenses).
        // ncmInsert (no $db->Execute raw): necesitamos el id insertado para
        // engancharlo al ledger de Finanzas (FinanceLedger::recordDrawerExpense,
        // Fase 3) de forma idempotente vía sourceId.
        $expensesId = ncmInsert([
            'records' => [
                'expensesNameId'      => null,
                'expensesAmount'      => $amount,
                'expensesDate'        => $date,
                'expensesDescription' => $note,
                'userId'              => $this->ctx->userId,
                'registerId'          => $this->ctx->registerId,
                'outletId'            => $this->ctx->outletId,
                'companyId'           => $this->ctx->companyId,
            ],
            'table' => 'expenses',
        ]);
        if (!$expensesId) {
            global $db;
            $err = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            error_log('[DrawerService] addExpense INSERT falló: ' . ($err ?: 'sin detalle'));
            throw new \RuntimeException('Error al registrar extracción' . ($err !== '' ? ": {$err}" : ''));
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);

        // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe la extracción.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordDrawerExpense($this->ctx->companyId, (string) $expensesId);
        } catch (\Throwable $e) {
            error_log('[FinanceLedger] recordDrawerExpense falló para expensesId=' . $expensesId . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Registra un ingreso de efectivo a la caja.
     * Idempotente: mismo control de duplicado que addExpense.
     *
     * @return string|true 'Income Already Exists' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function addIncome(float $amount, string $note, string $date): string|true
    {
        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [(float) $amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Income Already Exists';
        }

        // type = 1 = ingreso (según DrawerService::getIncome). ncmInsert (no
        // $db->Execute raw): necesitamos el id insertado para engancharlo al
        // ledger de Finanzas (FinanceLedger::recordDrawerIncome, Fase 3).
        $expensesId = ncmInsert([
            'records' => [
                'expensesNameId'      => null,
                'expensesAmount'      => (float) $amount,
                'expensesDate'        => $date,
                'expensesDescription' => $note,
                'type'                => 1,
                'userId'              => $this->ctx->userId,
                'registerId'          => $this->ctx->registerId,
                'outletId'            => $this->ctx->outletId,
                'companyId'           => $this->ctx->companyId,
            ],
            'table' => 'expenses',
        ]);
        if (!$expensesId) {
            global $db;
            $err = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            error_log('[DrawerService] addIncome INSERT falló: ' . ($err ?: 'sin detalle'));
            throw new \RuntimeException('Error al registrar ingreso' . ($err !== '' ? ": {$err}" : ''));
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);

        // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe el ingreso.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordDrawerIncome($this->ctx->companyId, (string) $expensesId);
        } catch (\Throwable $e) {
            error_log('[FinanceLedger] recordDrawerIncome falló para expensesId=' . $expensesId . ': ' . $e->getMessage());
        }

        return true;
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * "Sin caja" → null, para TODO resolver de drawer que reciba un registerId.
     *
     * `registerId` vacío/null NO es un dato corrupto: es el estado REAL de todo
     * lo que se opera desde el panel, sin caja de por medio. El caso testigo es
     * el pago a proveedor — `PurchasesService` nunca setea `registerId` en la
     * compra (la carga es de backoffice), así que `CreditPaymentService::
     * insertReceipt()` lee la columna NULL del parent y la castea a `''`.
     *
     * Por qué la normalización va acá y no en cada call-site: mandar `''` como
     * parámetro `?` contra una columna `uuid` tira "invalid input syntax for
     * type uuid", y ese error ENVENENA la transacción Postgres que envuelve la
     * llamada (`StartTrans()` de `CreditPaymentService::create/
     * createDistributed`). El `catch` del resolver atrapa la excepción y
     * devuelve null sin ruido, pero cualquier statement posterior DENTRO de la
     * misma transacción —el INSERT real del recibo— sale con "25P02 current
     * transaction is aborted": el pago a proveedor fallaba con 500 SIEMPRE, no
     * solo el helper. Un `catch` nunca puede reparar eso; hay que no tocar la
     * DB.
     *
     * El guard existía SOLO dentro de `resolveOpenDrawerId`, y
     * `resolveDrawerIdForDate` —que su propio docblock declara "el reemplazo
     * correcto" para el money-path— nació sin él y reintrodujo el mismo 500.
     * Centralizarlo es lo que hace que el próximo resolver de esta familia no
     * pueda volver a escribirse sin la normalización.
     *
     * Solo para los resolvers de LECTURA, donde null es una respuesta válida
     * ("esta operación no cuelga de ningún turno"). Los caminos de ESCRITURA
     * (`open()`/`close()`) exigen una caja real y validan el registerId
     * aguas arriba — ahí un vacío es un error de contexto, no un null legítimo.
     */
    private static function registerIdOrNull(?string $registerId): ?string
    {
        $registerId = trim((string) $registerId);
        return $registerId === '' ? null : $registerId;
    }

    /**
     * drawerId de la caja ABIERTA de un register (scopeado por company), o null.
     * Responde "¿hay caja abierta AHORA MISMO?" — legítimo para guards de
     * abrir/cerrar caja, pero NUNCA para sellar `transaction.drawerId` en el
     * money-path (ver `resolveDrawerIdForDate`, el reemplazo correcto ahí):
     * una venta offline sincronizada tarde puede encontrar abierto un turno
     * distinto al que realmente la cobró (bug verificado 2026-08-17,
     * `context/modules/14-caja.md` regla 5).
     *
     * Best-effort: cualquier fallo devuelve null.
     *
     * Por register+company (sin outlet): el register ya determina el outlet, y
     * el money-path no siempre lo tiene a mano (el credit payment toma el
     * register del parent).
     */
    public static function resolveOpenDrawerId(?string $registerId, string $companyId): ?string
    {
        $registerId = self::registerIdOrNull($registerId);
        if ($registerId === null) {
            return null;
        }
        try {
            $row = ncmExecute(
                'SELECT drawerId AS "drawerId" FROM drawer
                 WHERE registerId = ? AND companyId = ?
                 AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 00:00:00\')
                 ORDER BY drawerOpenDate DESC LIMIT 1',
                [$registerId, $companyId]
            );
            if (!$row) {
                return null;
            }
            $id = $row['drawerId'] ?? null;
            return ($id !== null && $id !== '') ? (string) $id : null;
        } catch (\Throwable $e) {
            error_log('[DrawerService] resolveOpenDrawerId: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * drawerId de la caja de `$registerId` (scopeada por company) cuyo rango
     * `[drawerOpenDate, drawerCloseDate]` CONTIENE `$operationDate` — resuelve
     * el turno por la fecha de la OPERACIÓN, no por "qué caja está abierta
     * ahora". Reemplazo de `resolveOpenDrawerId` para el money-path: una venta
     * offline se INSERTA recién al sincronizar, minutos u horas después de
     * cobrada; preguntar "¿hay caja abierta?" en ese momento cuelga la venta
     * del turno que esté abierto AL SINCRONIZAR, no del que la cobró (bug
     * verificado 2026-08-17).
     *
     * Determinista y sin ambigüedad: `uidx_drawer_register_open` garantiza que
     * nunca hay dos turnos abiertos a la vez en la misma caja, así que los
     * rangos de una misma caja no se solapan — a lo sumo un candidato.
     *
     * Deliberadamente conservador — "fallar es más seguro que acertar mal":
     * si `$operationDate` es null/vacío/no parseable, o si NINGÚN turno de la
     * caja contiene esa fecha, devuelve null y NUNCA cae al turno abierto
     * actual (eso es exactamente el bug que este método reemplaza). Un null
     * es recuperable por el fallback de fecha del resumen (mig 70,
     * `getPaymentBreakdown` et al.); un drawerId incorrecto no lo es, porque
     * hace match exacto y gana sobre ese mismo fallback.
     *
     * Mismo centinela legacy que `resolveOpenDrawerId`/`findOpenRow`:
     * `drawerCloseDate < '2000-01-01 00:00:00'` cuenta como "sigue abierto".
     */
    public static function resolveDrawerIdForDate(?string $registerId, string $companyId, ?string $operationDate): ?string
    {
        $registerId = self::registerIdOrNull($registerId);
        if ($registerId === null) {
            return null;
        }
        if ($operationDate === null || $operationDate === '' || strtotime($operationDate) === false) {
            return null;
        }

        try {
            $row = ncmExecute(
                'SELECT drawerId AS "drawerId" FROM drawer
                 WHERE registerId = ? AND companyId = ?
                 AND drawerOpenDate <= ?
                 AND (drawerCloseDate IS NULL
                      OR drawerCloseDate < \'2000-01-01 00:00:00\'
                      OR drawerCloseDate >= ?)
                 ORDER BY drawerOpenDate DESC LIMIT 1',
                [$registerId, $companyId, $operationDate, $operationDate]
            );
            if (!$row) {
                return null;
            }
            $id = $row['drawerId'] ?? null;
            return ($id !== null && $id !== '') ? (string) $id : null;
        } catch (\Throwable $e) {
            error_log('[DrawerService] resolveDrawerIdForDate: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Devuelve la fila del drawer abierto (drawerId + drawerOpenDate) o null.
     * Centraliza la query de "hay caja abierta" usada por open() y close().
     */
    private function findOpenRow(string $registerId, string $outletId, string $companyId): ?array
    {
        $row = ncmExecute(
            'SELECT drawerId       AS "drawerId",
                    drawerOpenDate AS "drawerOpenDate"
             FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 00:00:00\')
             ORDER BY drawerOpenDate DESC LIMIT 1',
            [$registerId, $outletId, $companyId]
        );
        if (!$row) return null;
        return ncmRow($row);
    }

    /**
     * Resumen completo de la caja actual: list, date, subtotal, total, tips, returns.
     * Composite legacy/backward-compat — el path vigente es la composición en el BFF
     * (app/bff/drawer.php) a partir de los recursos granulares de arriba.
     *
     * @return array|null null si el drawer está cerrado.
     */
    public function getSummary(string $registerId, string $outletId, string $companyId): ?array
    {
        $open = $this->getOpen($registerId, $outletId, $companyId);
        if ($open === null) {
            return null;
        }
        $since    = $open['drawerOpenDate'];
        $drawerId = $open['drawerId'] ?? null;
        $expenses = $this->getExpenses($registerId, $since);
        $income   = $this->getIncome($registerId, $since);
        $payments = $this->getPaymentBreakdown($registerId, $since, $drawerId);
        $products = $this->getSoldProducts($registerId, $since, $drawerId);
        $stats    = $this->getSaleStats($registerId, $since, $drawerId);

        return self::composeSummary($open, $expenses, $income, $payments, $products, $stats);
    }

    /**
     * Totales del turno TAL COMO ESTÁN, sin productos ni estadísticas.
     *
     * Existe para una sola cosa: que el cierre pueda devolver el arqueo que el
     * servidor calculó, y que el POS pueda compararlo contra el total que
     * había mostrado sin conexión. Un cierre hecho offline se decide mirando
     * lo que ese aparato registró; si el servidor termina con otro número,
     * alguien tiene que enterarse (ver `shift-close-reconciliation.ts` del
     * front).
     *
     * No usa `getSummary()` porque no necesita lo caro: los productos vendidos
     * y las estadísticas de la sesión son dos queries con JOIN a `itemSold` que
     * nadie va a mirar en esta respuesta. Las piezas que sí importan son las
     * mismas, y el rollup lo hace `composeSummary()` — o sea que la fórmula
     * sigue estando escrita en un solo lugar.
     *
     * @return array{date:string,total:float,subtotal:float,salesTotal:float,returns:float,expectedByMethod:array<int,array{key:string,name:string,isCash:bool,expected:float}>}|null
     *         null si la caja ya está cerrada.
     */
    public function getClosingTotals(string $registerId, string $outletId, string $companyId): ?array
    {
        $open = $this->getOpen($registerId, $outletId, $companyId);
        if ($open === null) {
            return null;
        }
        $since    = $open['drawerOpenDate'];
        $drawerId = $open['drawerId'] ?? null;

        $summary = self::composeSummary(
            $open,
            $this->getExpenses($registerId, $since),
            $this->getIncome($registerId, $since),
            $this->getPaymentBreakdown($registerId, $since, $drawerId)
        );

        return [
            'date'       => (string) $summary['date'],
            'total'      => (float) $summary['total'],
            'subtotal'   => (float) $summary['subtotal'],
            'salesTotal' => (float) $summary['salesTotal'],
            'returns'    => (float) $summary['returns'],
            // Esperado POR MEDIO DE PAGO — la mitad "servidor" del arqueo que
            // el cajero completa contando cada medio. Siempre trae al menos la
            // fila del efectivo.
            'expectedByMethod' => $summary['expectedByMethod'],
        ];
    }

    /**
     * Rollup del cierre de caja a partir de las piezas granulares. Pura (sin DB) →
     * reusable por getSummary() (API) y por el BFF (que arma las piezas en paralelo).
     * MANTENER EN SYNC si cambia la fórmula (la usa app/bff/drawer.php).
     *
     * @param array{drawerOpenDate:string,drawerOpenAmount:float} $open
     * @param array{amount:float} $expenses
     * @param array{total:float,tips:float} $income
     * @param array<int,array{name:string,type:string,price:float}> $payments
     * @param array<int,array{name:string,qty:float,total:float}> $products
     * @param array{salesCount:int,customersCount:int} $stats Default 0/0 — opcional para
     *   tolerar callers viejos (ej. BFF legacy) que todavía no pasan `getSaleStats()`.
     */
    /**
     * ¿Este medio de pago entra al cajón físico?
     *
     * Única definición de "efectivo" del arqueo. Estaba inline en
     * `composeSummary()` y el reporte del panel no tenía forma de reusarla, así
     * que recomputaba el esperado con TODOS los medios de pago y marcaba
     * faltantes fantasma en cualquier turno con tarjeta. Ahora la comparten el
     * cierre (que congela el número) y `Reports\DrawersService` (que lo estima
     * para los cierres anteriores a la mig 164) — si mañana aparece otro medio
     * que mueve billetes, se agrega acá y las dos mitades quedan de acuerdo.
     *
     * `getSalesByPayment()` reclasifica las devoluciones (transactionType 6)
     * como tipo 'return', así que nunca llegan como efectivo — por eso el
     * esperado no las resta. No es un olvido: es la fórmula que el cajero ve
     * en pantalla, y el número congelado tiene que ser EXACTAMENTE ese.
     */
    /**
     * Nombre con el que se sintetiza la fila del efectivo en los dos casos en
     * que el servidor no tiene de dónde leer cómo lo llama este comercio:
     *
     *   1. El turno no tuvo NINGUNA venta en efectivo (`composeSummary()`).
     *   2. El cierre llegó SIN desglose —cliente sin actualizar o cierre
     *      encolado antes del deploy— y hay que armar la fila del cajón con
     *      `amount` (`persistCount()` y el fallback de la respuesta en
     *      `api/v1/drawer.php`).
     *
     * El emparejamiento con lo que la caja contó no depende de este texto
     * (`composeArqueo` matchea el efectivo por bandera, no por nombre) — es
     * solo la etiqueta del informe.
     */
    public const CASH_METHOD_NAME = 'Efectivo';

    public static function isCashPaymentType(string $type): bool
    {
        return in_array(strtolower($type), ['cash', 'efectivo'], true);
    }

    /**
     * Clave de agrupación de un medio de pago dentro del arqueo.
     *
     * Es EXACTAMENTE la que ya usa `groupByPaymentMethod()` (functions.php
     * ~L1015) para juntar en una sola fila el mismo medio guardado con dos
     * identificadores distintos (el slug viejo y el UUID de taxonomía nuevo):
     * el nombre resuelto, en minúsculas. Se expone como función porque ahora
     * hay un segundo lado —el conteo que manda la caja— que tiene que producir
     * la MISMA clave para que las dos mitades del arqueo se encuentren.
     * `lib/pos/local-shift-total.ts` (`paymentGroupKey`) es su espejo en el POS.
     */
    public static function paymentGroupKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * Arqueo POR MEDIO DE PAGO: lo esperado contra lo contado, medio por medio.
     *
     * Por qué existe: hasta 2026-08-24 el cierre pedía UN monto (el efectivo) y
     * el arqueo comparaba solo eso. Pero el turno se cobra por muchas vías, y
     * el cajero tiene delante los vouchers de tarjeta y los comprobantes de QR
     * igual que tiene los billetes — contar solo una parte deja el resto sin
     * control (pedido del owner, 2026-08-24).
     *
     * Pura (sin DB) a propósito: la usan el cierre (para congelar las filas en
     * `drawer_count`) y la respuesta del endpoint (para que el POS muestre el
     * informe). Una sola fórmula, dos consumidores.
     *
     * Reglas de emparejamiento, en orden:
     *   1. Por CLAVE O POR NOMBRE normalizado. No alcanza con la clave, y esto
     *      no es cinturón y tiradores: `groupByPaymentMethod()` agrupa por el
     *      nombre resuelto SOLO cuando lo puede resolver, y cae al `type` crudo
     *      (el slug `tcredito`, o un UUID de taxonomía) cuando no. La caja, en
     *      cambio, solo conoce el nombre que le mostró al cliente. Emparejar
     *      únicamente por clave dejaba sin match a todo medio con la taxonomía
     *      no resuelta: el esperado quedaba sin contar y lo contado aparecía
     *      como un sobrante por el monto entero. Lo detectó
     *      `drawer_count_by_method_test.php` antes de llegar a una caja.
     *   2. El efectivo, además, matchea por la bandera `isCash`: si el comercio
     *      renombró el medio ("Contado") y el turno no tuvo ventas en efectivo,
     *      el servidor no tiene de dónde sacar ese nombre y sintetiza la fila
     *      con el nombre canónico. Sin este tercer intento, la plata del cajón
     *      —la única que SIEMPRE hay que contar— sería la que no matchea.
     *   3. Lo contado sin esperado se agrega igual con esperado 0: un medio que
     *      el servidor no vio en el turno pero que el cajero contó es un
     *      sobrante real, no una fila para tirar.
     *
     * La fila resultante conserva la clave y el nombre del ESPERADO: es la
     * identidad con la que el servidor agrupó el turno, y es la que queda
     * congelada en `drawer_count` para que el reporte pueda agregarla entre
     * cierres.
     *
     * @param array<int,array{key:string,name:string,isCash:bool,expected:float}> $expectedByMethod
     * @param array<int,array{key?:string,name?:string,isCash?:bool,counted:float|string}> $counted
     * @return array<int,array{key:string,name:string,isCash:bool,expected:float|null,counted:float|null,difference:float|null}>
     */
    /**
     * Las identidades por las que un medio de pago puede reconocerse en el
     * arqueo: su clave de agrupación, su nombre normalizado y su slug.
     *
     * Son tres y no una porque el mismo medio viaja con nombres distintos
     * según quién lo mire: `groupByPaymentMethod()` reescribe el nombre al
     * resolver la taxonomía, así que el que la caja anotó al vender
     * ("Tarjeta de crédito") y el que el arqueo muestra ("T. Crédito") pueden
     * no coincidir, y la clave de agrupación cae al slug crudo cuando la
     * taxonomía no resuelve. El slug es lo único que los dos lados tienen
     * igual siempre.
     *
     * @return array<int,string>
     */
    private static function methodIdentities(string $key, string $name, string $code): array
    {
        return array_values(array_unique(array_filter([
            $key !== '' ? self::paymentGroupKey($key) : '',
            $name !== '' ? self::paymentGroupKey($name) : '',
            $code !== '' ? self::paymentGroupKey($code) : '',
        ])));
    }

    public static function composeArqueo(array $expectedByMethod, array $counted): array
    {
        // Normalización del conteo: la clave manda, pero un cliente puede
        // mandar solo el nombre (o solo la bandera de efectivo).
        $pending = [];
        foreach ($counted as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string) ($c['name'] ?? ''));
            $key  = trim((string) ($c['key'] ?? ''));
            if ($key === '' && $name !== '') {
                $key = self::paymentGroupKey($name);
            }
            if ($key === '') {
                continue;
            }
            $pending[] = [
                'key'     => $key,
                'name'    => $name !== '' ? $name : $key,
                // Identidades por las que esta fila puede reconocerse: clave,
                // nombre normalizado y slug del medio. Ver la regla 1.
                'ids'     => self::methodIdentities($key, $name, (string) ($c['code'] ?? '')),
                'isCash'  => (bool) ($c['isCash'] ?? self::isCashPaymentType($key)),
                'counted' => (float) ($c['counted'] ?? 0),
            ];
        }

        /** @param array<int,string> $ids identidades aceptables del medio esperado */
        $takeByIds = function (array $ids) use (&$pending): ?array {
            foreach ($pending as $i => $row) {
                if (array_intersect($ids, $row['ids']) !== []) {
                    unset($pending[$i]);
                    return $row;
                }
            }
            return null;
        };
        $takeCash = function () use (&$pending): ?array {
            foreach ($pending as $i => $row) {
                if ($row['isCash']) {
                    unset($pending[$i]);
                    return $row;
                }
            }
            return null;
        };

        $out = [];
        foreach ($expectedByMethod as $e) {
            $key      = (string) $e['key'];
            $name     = (string) $e['name'];
            $isCash   = (bool) ($e['isCash'] ?? false);
            $expected = (float) $e['expected'];
            $ids      = self::methodIdentities($key, $name, (string) ($e['code'] ?? ''));
            $match    = $takeByIds($ids) ?? ($isCash ? $takeCash() : null);
            $countedV = $match !== null ? (float) $match['counted'] : null;
            $out[] = [
                'key'        => $key,
                'name'       => $name,
                'isCash'     => $isCash,
                'expected'   => $expected,
                'counted'    => $countedV,
                'difference' => $countedV === null ? null : round($countedV - $expected, 2),
            ];
        }

        foreach ($pending as $row) {
            $out[] = [
                'key'        => $row['key'],
                'name'       => $row['name'],
                'isCash'     => $row['isCash'],
                'expected'   => 0.0,
                'counted'    => $row['counted'],
                'difference' => round($row['counted'], 2),
            ];
        }

        return $out;
    }

    public static function composeSummary(array $open, array $expenses, array $income, array $payments, array $products = [], array $stats = []): array
    {
        $cajaInicial   = (float) $open['drawerOpenAmount'];
        $expenseAmount = (float) $expenses['amount'];
        $totalIncome   = (float) $income['total'];
        $totalTips     = (float) $income['tips'];

        $cashPrice = 0.0;
        $total     = 0.0;
        $return    = 0.0;
        $list      = [['name' => 'Caja Inicial', 'amount' => $cajaInicial]];
        // Índice del medio de EFECTIVO dentro de `$expectedByMethod`, si el
        // turno tuvo alguna venta en efectivo. `null` = no la hubo y la fila se
        // sintetiza al final: el cajón se cuenta SIEMPRE, aunque no haya
        // entrado un solo billete por ventas — el fondo inicial está ahí.
        $cashIndex        = null;
        $expectedByMethod = [];
        // Solo los MÉTODOS DE PAGO reales — sin Caja Inicial / Extracciones /
        // Ingresos, que en `list` conviven con ellos porque esa lista es el
        // arqueo impreso. Se arma acá (donde ya se sabe qué fila es qué) y no
        // filtrando `list` por nombre en el consumidor: los nombres son copy.
        $paymentBreakdown = [];

        foreach ($payments as $p) {
            $price  = (float) $p['price'];
            $isCash = self::isCashPaymentType((string) $p['type']);
            if ($isCash) {
                $cashPrice = $price;
            }
            if ($p['type'] === 'return') {
                $return += $price;
            } else {
                $total += $price;
                $list[] = ['name' => $p['name'], 'amount' => $price];
                $paymentBreakdown[] = ['name' => $p['name'], 'amount' => $price];
                // `groupKey` es la clave con la que `groupByPaymentMethod()` ya
                // juntó las filas; se reusa tal cual para que el conteo de la
                // caja empareje contra la MISMA identidad y no contra una
                // segunda normalización parecida.
                $expectedByMethod[] = [
                    'key'      => (string) ($p['groupKey'] ?? self::paymentGroupKey((string) $p['name'])),
                    'name'     => (string) $p['name'],
                    // Slug/id del medio TAL COMO lo guardó la venta
                    // (`transactionPaymentType.type`). Es la única identidad
                    // que la caja y el servidor comparten con certeza: el
                    // nombre lo reescribe `groupByPaymentMethod()` al resolver
                    // la taxonomía, así que el que la caja anotó al vender y el
                    // que el arqueo muestra pueden ser dos textos distintos del
                    // mismo medio.
                    'code'     => (string) ($p['type'] ?? ''),
                    'isCash'   => $isCash,
                    'expected' => $price,
                ];
                if ($isCash && $cashIndex === null) {
                    $cashIndex = count($expectedByMethod) - 1;
                }
            }
        }

        // $total ya es la suma de payments no-return ANTES de sumarle caja
        // inicial/ingresos — se expone tal cual como salesTotal (ver brief:
        // "no dupliques el loop").
        $salesTotal = $total;

        $list[] = ['name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount];
        $list[] = ['name' => 'Ingresos (Efectivo)',     'amount' => $totalIncome];

        // Lo que se espera EN EL CAJÓN no son las ventas en efectivo sino
        // `subtotal` (inicial + ventas en efectivo + ingresos − extracciones):
        // es el número contra el que se arqueó siempre (mig 164) y el que el
        // cajero tiene en billetes delante. Los demás medios se esperan por su
        // monto de ventas, que es lo que hay en vouchers y comprobantes.
        $cashExpected = ($cajaInicial + $cashPrice + $totalIncome) - $expenseAmount;
        if ($cashIndex !== null) {
            $expectedByMethod[$cashIndex]['expected'] = $cashExpected;
            // El efectivo va primero: es lo primero que se cuenta.
            $cashRow = $expectedByMethod[$cashIndex];
            unset($expectedByMethod[$cashIndex]);
            array_unshift($expectedByMethod, $cashRow);
            $expectedByMethod = array_values($expectedByMethod);
        } else {
            array_unshift($expectedByMethod, [
                'key'      => self::paymentGroupKey(self::CASH_METHOD_NAME),
                'name'     => self::CASH_METHOD_NAME,
                'code'     => 'cash',
                'isCash'   => true,
                'expected' => $cashExpected,
            ]);
        }

        return [
            'list'             => $list,
            'paymentBreakdown' => $paymentBreakdown,
            'expectedByMethod' => $expectedByMethod,
            'date'             => $open['drawerOpenDate'],
            'subtotal'        => ($cajaInicial + $cashPrice + $totalIncome) - $expenseAmount,
            'total'           => ($cajaInicial + $total + $totalIncome) - $expenseAmount - $return,
            'tips'            => $totalTips,
            'returns'         => -$return,
            'soldProducts'    => $products,
            'salesCount'      => (int) ($stats['salesCount'] ?? 0),
            'customersCount'  => (int) ($stats['customersCount'] ?? 0),
            'salesTotal'      => $salesTotal,
        ];
    }
}
