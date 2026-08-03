<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
use Punto\Api\Support\TenantClock;
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
                $out[] = [
                    'name'  => str_replace('u00e9', 'é', $arr['name']),
                    'type'  => $arr['type'],
                    'price' => (float) $arr['price'],
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
     * @return string|true 'Already Closed' / 'Invalid Close Date' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function close(float $amount, string $date, string $userId): string|true
    {
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

        try {
            $ok = ncmExecute(
                'UPDATE drawer SET drawerCloseDate = ?, drawerCloseAmount = ?, drawerUserClose = ? WHERE drawerId = ?',
                [$date, $amount, $userIdForClose, $row['drawerId']]
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

        return true;
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
     * drawerId de la caja ABIERTA de un register (scopeado por company), o null.
     * Helper compartido para sellar `transaction.drawerId` en el momento de la
     * venta/pago (mig 70). Best-effort: cualquier fallo devuelve null → la venta
     * se registra sin drawerId (recuperable por el fallback de fecha del resumen).
     *
     * Por register+company (sin outlet): el register ya determina el outlet, y
     * el money-path no siempre lo tiene a mano (el credit payment toma el
     * register del parent).
     */
    public static function resolveOpenDrawerId(string $registerId, string $companyId): ?string
    {
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
        // Solo los MÉTODOS DE PAGO reales — sin Caja Inicial / Extracciones /
        // Ingresos, que en `list` conviven con ellos porque esa lista es el
        // arqueo impreso. Se arma acá (donde ya se sabe qué fila es qué) y no
        // filtrando `list` por nombre en el consumidor: los nombres son copy.
        $paymentBreakdown = [];

        foreach ($payments as $p) {
            $price = (float) $p['price'];
            if (in_array(strtolower((string) $p['type']), ['cash', 'efectivo'], true)) {
                $cashPrice = $price;
            }
            if ($p['type'] === 'return') {
                $return += $price;
            } else {
                $total += $price;
                $list[] = ['name' => $p['name'], 'amount' => $price];
                $paymentBreakdown[] = ['name' => $p['name'], 'amount' => $price];
            }
        }

        // $total ya es la suma de payments no-return ANTES de sumarle caja
        // inicial/ingresos — se expone tal cual como salesTotal (ver brief:
        // "no dupliques el loop").
        $salesTotal = $total;

        $list[] = ['name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount];
        $list[] = ['name' => 'Ingresos (Efectivo)',     'amount' => $totalIncome];

        return [
            'list'             => $list,
            'paymentBreakdown' => $paymentBreakdown,
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
