<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\App\Helpers\Date;

use Punto\Api\Contacts\ContactDisplayName;

/**
 * Dominio de Reportes — Cierres de Caja / Drawers (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportDrawersService.php (Fase 2 batch 9). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO en `listMovements` (no `getROC(1)` interno)
 *  - `getAllSalesByDrawerPeriod` (sólo en panel) → portado como `salesByDrawerPeriod()` privado
 *    que recibe `$roc` (no usa getROC interno). Mismo SQL y semántica.
 *  - `sumTotalBetweenDateRanges` (sólo en panel) → portado como `sumForRegister()` privado.
 *  - `getSalesByPayment($from, $to, $register, false)` (resolvería a la versión de /app: firma
 *    `($from,$to,$regId)` — funciona pero filtra tipo 6 además de 0,5, semántica distinta a la
 *    panel) → reemplazado por `NonAddingSales::salesByPayment` con $roc register-scoped.
 *
 * Tenant: $roc en lista; companyId SIEMPRE bound en lookups y WRITE. Helpers globales
 * usados (existen en /app): isInternalSale, isParentInternalSale, groupByPaymentMethod,
 * getPaymentMethodName.
 *
 * Endurecimientos vs legacy (heredados del panel original):
 *  - El detalle se re-consulta por id (no confía en blob del cliente).
 *  - `remove()` scopea por companyId (legacy era IDOR + LIMIT roto en PG).
 *  - Las sumas de expenses (extracciones/ingresos) filtran por companyId además de registerId.
 *
 * Anulación de ventas (`voidedAt`, mig 154, context/40-anulacion-y-nota-credito.md):
 * este servicio NO usa `SaleFilters::notVoidedSql()` a propósito, mismo motivo que
 * `Services\DrawerService` — es reconciliación de turno/caja, no un reporte de ventas.
 */
final class DrawersService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string,float> Tolerancia por company — el listado la usa una vez por fila. */
    private array $toleranceCache = [];

    /**
     * Tolerancia de cuadre vigente para el comercio, en unidades de su moneda.
     * La expone el endpoint para que la UI pueda decir con qué margen se está
     * clasificando (un "cuadra" sin saber la tolerancia no es informativo).
     */
    public function tolerance(string $companyId): float
    {
        return $this->toleranceFor($companyId);
    }

    /** @return array filas de cajas con componentes crudos. */
    public function listMovements($from, $to, string $roc, string $companyId): array
    {
        $res = ncmExecute(
            "SELECT drawerId, registerId, outletId,
                    drawerOpenDate, drawerOpenAmount, drawerCloseDate, drawerCloseAmount,
                    drawerExpectedAmount, drawerUserOpen, drawerUserClose
             FROM drawer
             WHERE drawerOpenDate >= ? AND drawerOpenDate <= ?" . $roc . "
             ORDER BY drawerUID ASC
             LIMIT 1000",
            [$from, $to], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw = []; $outletIds = []; $registerIds = []; $userIds = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $outlet = (string) ($f['outletId'] ?? '');
            $reg    = (string) ($f['registerId'] ?? '');
            $uOpen  = (string) ($f['drawerUserOpen'] ?? '');
            $uClose = (string) ($f['drawerUserClose'] ?? '');
            if ($outlet !== '') { $outletIds[$outlet] = true; }
            if ($reg    !== '') { $registerIds[$reg] = true; }
            if ($uOpen  !== '') { $userIds[$uOpen] = true; }
            if ($uClose !== '') { $userIds[$uClose] = true; }

            $raw[] = [
                'drawerId'    => (string) $f['drawerId'],
                'registerId'  => $reg,
                'outletId'    => $outlet,
                'openDate'    => (string) ($f['drawerOpenDate'] ?? ''),
                'openAmount'  => (float)  ($f['drawerOpenAmount'] ?? 0),
                'closeDate'   => (string) ($f['drawerCloseDate'] ?? ''),
                'closeAmount' => (float)  ($f['drawerCloseAmount'] ?? 0),
                // NULL se conserva como NULL: es "no hay esperado congelado",
                // no "el esperado era 0" (mig 164).
                'expected'    => isset($f['drawerExpectedAmount']) && $f['drawerExpectedAmount'] !== null
                    ? (float) $f['drawerExpectedAmount']
                    : null,
                'userOpen'    => $uOpen,
                'userClose'   => $uClose,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $outlets   = $this->nameMap('outlet',   'outletId',   'outletName',   array_keys($outletIds),   $companyId);
        $registers = $this->nameMap('register', 'registerId', 'registerName', array_keys($registerIds), $companyId);
        $users     = ContactDisplayName::batch(array_keys($userIds), $companyId);

        // Ventas por caja del período (una sola query, scopeada por $roc del caller).
        $allSales = $this->salesByDrawerPeriod($from, $to, $roc);

        $tolerance = $this->toleranceFor($companyId);

        $rows = [];
        foreach ($raw as $r) {
            $isClosed = $r['closeDate'] !== '';
            $closeBound = $isClosed ? $r['closeDate'] : date('Y-m-d ', strtotime(TODAY)) . Date::END_OF_DAY;
            $t = $this->componentsFor($r['openDate'], $closeBound, $r['registerId'], $companyId, $allSales, $roc);
            $count = $this->cashCount($isClosed, $r['expected'], $r['closeAmount'], $r['openAmount'], $t, $tolerance);

            $rows[] = [
                'drawerId'     => $r['drawerId'],
                'outletName'   => $outlets[$r['outletId']] ?? '',
                'registerName' => $registers[$r['registerId']] ?? '',
                'openDate'     => $r['openDate'],
                'openAmount'   => $r['openAmount'],
                'closeDate'    => $r['closeDate'],
                'closeAmount'  => $r['closeAmount'],
                'openUserName' => $users[$r['userOpen']]  ?? '',
                'closeUserName'=> $users[$r['userClose']] ?? '',
                'isClosed'     => $isClosed,
                'sold'         => $t['sold'],
                'cashSold'     => $t['cash'],
                'expense'      => $t['expense'],
                'income'       => $t['income'],
                'return'       => $t['return'],
            ] + $count;
        }

        return $rows;
    }

    /** Detalle de una caja (re-consultado, no blob del cliente). SCOPEADO por companyId. */
    public function detail(string $drawerId, string $companyId, string $roc): ?array
    {
        $d = ncmExecute(
            "SELECT drawerId, registerId, outletId,
                    drawerOpenDate, drawerOpenAmount, drawerCloseDate, drawerCloseAmount,
                    drawerExpectedAmount, drawerUserOpen, drawerUserClose
             FROM drawer WHERE drawerId = ? AND companyId = ? LIMIT 1",
            [$drawerId, $companyId]
        );
        if (!$d) {
            return null;
        }

        $register = (string) ($d['registerId'] ?? '');
        $outlet   = (string) ($d['outletId'] ?? '');
        $uOpen    = (string) ($d['drawerUserOpen'] ?? '');
        $uClose   = (string) ($d['drawerUserClose'] ?? '');
        $openDate = (string) ($d['drawerOpenDate'] ?? '');
        $closeDate= (string) ($d['drawerCloseDate'] ?? '');
        $isClosed = $closeDate !== '';
        $closeBound = $isClosed ? $closeDate : date('Y-m-d ', strtotime(TODAY)) . Date::END_OF_DAY;

        $names     = ContactDisplayName::batch(array_filter([$uOpen, $uClose]), $companyId);
        $outlets   = $this->nameMap('outlet',   'outletId',   'outletName',   [$outlet],   $companyId);
        $registers = $this->nameMap('register', 'registerId', 'registerName', [$register], $companyId);

        $t = $this->componentsFor($openDate, $closeBound, $register, $companyId, null, $roc);

        // Desglose por medio de pago — $roc scopeado al register de esta caja específica.
        // NonAddingSales::salesByPayment usa los tipos 0,5 (igual que la versión panel; la
        // versión de /app incluye tipo 6 = devoluciones, que NO se quieren acá).
        $payments = [];
        $regRoc = $this->buildRegisterRoc($companyId, $register);
        if ($regRoc !== '') {
            $closeArg = $isClosed ? $closeDate : '';
            foreach (NonAddingSales::salesByPayment($openDate, $closeArg, $regRoc) as $p) {
                $code = (string) ($p['type'] ?? '');
                $payments[] = [
                    'type'  => $code,
                    'label' => getPaymentMethodName($code),
                    'price' => (float) ($p['price'] ?? 0),
                ];
            }
        }

        $openAmount  = (float) ($d['drawerOpenAmount'] ?? 0);
        $closeAmount = (float) ($d['drawerCloseAmount'] ?? 0);
        $frozen      = isset($d['drawerExpectedAmount']) && $d['drawerExpectedAmount'] !== null
            ? (float) $d['drawerExpectedAmount']
            : null;
        $count = $this->cashCount(
            $isClosed, $frozen, $closeAmount, $openAmount, $t, $this->toleranceFor($companyId)
        );

        return [
            'drawerId'     => (string) $d['drawerId'],
            'outletName'   => $outlets[$outlet]     ?? '',
            'registerName' => $registers[$register] ?? '',
            'openDate'     => $openDate,
            'openAmount'   => $openAmount,
            'closeDate'    => $closeDate,
            'closeAmount'  => $closeAmount,
            'openUserName' => $names[$uOpen]  ?? '',
            'closeUserName'=> $names[$uClose] ?? '',
            'isClosed'     => $isClosed,
            'sold'         => $t['sold'],
            'cashSold'     => $t['cash'],
            'expense'      => $t['expense'],
            'income'       => $t['income'],
            'return'       => $t['return'],
            'payments'     => $payments,
            'countByMethod'=> $this->countByMethod(
                $drawerId,
                $companyId,
                $isClosed,
                $closeAmount,
                $count['expectedAmount'] ?? null,
                $this->toleranceFor($companyId)
            ),
        ] + $count;
    }

    /**
     * El arqueo del cierre MEDIO POR MEDIO (mig 169): lo esperado, lo contado
     * y el veredicto de cada uno.
     *
     * Dos fuentes, y la distinción importa:
     *
     *   - `source='frozen'` — filas de `drawer_count`, escritas por el cierre
     *     con los números que el cajero tenía delante. Es el arqueo REAL.
     *   - `source='estimated'` — no hay filas: el cierre es anterior a la mig
     *     169, o lo hizo el panel (que solo pide el efectivo). Se sintetiza la
     *     ÚNICA fila que sí se conoce, la del cajón, a partir de las columnas
     *     de `drawer`. No se inventan las demás: un medio sin fila es un medio
     *     que nadie contó, y mostrarlo en cero sería afirmar un cuadre que
     *     nunca ocurrió.
     *
     * Caja abierta ⇒ lista vacía: todavía no hay nada contado.
     *
     * @return array<int,array{key:string,name:string,isCash:bool,expected:float|null,counted:float,difference:float|null,status:string,source:string}>
     */
    private function countByMethod(
        string $drawerId,
        string $companyId,
        bool $isClosed,
        float $closeAmount,
        float|int|string|null $expectedCash,
        float $tolerance,
    ): array {
        if (!$isClosed) {
            return [];
        }

        $rows = [];
        $rs = ncmExecute(
            'SELECT methodkey, methodname, iscash, expectedamount, countedamount
               FROM drawer_count
              WHERE drawerid = ? AND companyid = ?
              ORDER BY iscash DESC, countedamount DESC',
            [$drawerId, $companyId],
            false,
            true
        );
        if ($rs) {
            while (!$rs->EOF) {
                $f        = $rs->fields;
                $expected = $f['expectedamount'] !== null ? (float) $f['expectedamount'] : null;
                $counted  = (float) $f['countedamount'];
                $rows[]   = [
                    'key'        => (string) $f['methodkey'],
                    'name'       => (string) $f['methodname'],
                    'isCash'     => (bool) $f['iscash'],
                    'expected'   => $expected,
                    'counted'    => $counted,
                    'difference' => $expected === null ? null : round($counted - $expected, 2),
                    'status'     => CashCountStatus::classify($counted, $expected, $tolerance),
                    'source'     => 'frozen',
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        if ($rows !== []) {
            return $rows;
        }

        $expected = $expectedCash !== null ? (float) $expectedCash : null;
        return [[
            'key'        => 'efectivo',
            'name'       => 'Efectivo',
            'isCash'     => true,
            'expected'   => $expected,
            'counted'    => $closeAmount,
            'difference' => $expected === null ? null : round($closeAmount - $expected, 2),
            'status'     => CashCountStatus::classify($closeAmount, $expected, $tolerance),
            'source'     => 'estimated',
        ]];
    }

    /** Cierra una caja. SCOPEADO por companyId. */
    public function close(string $drawerId, string $companyId, string $date, float $amount, string $userId): bool
    {
        global $db;
        // Esperado congelado (mig 164), igual que en el cierre desde el POS. Se
        // calcula ANTES del UPDATE: después la caja ya no está abierta y la
        // ventana del turno sería otra. NULL si no se pudo — el cierre nunca se
        // bloquea por no poder leer un número de reporte.
        $expected = $this->expectedCashFor($drawerId, $companyId, $date);

        // Doble barrera: scope por companyId + `drawerCloseDate IS NULL` para no
        // re-cerrar (pisar monto/fecha de) una caja ya cerrada. La edición de una
        // caja cerrada se hace por `correct()`, no por re-close. Affected_Rows()=0
        // ⇒ ya cerrada / no existe / de otra company ⇒ false.
        $r = $db->Execute(
            "UPDATE drawer SET drawerCloseAmount = ?, drawerCloseDate = ?, drawerUserClose = ?, drawerExpectedAmount = ?
             WHERE drawerId = ? AND companyId = ? AND drawerCloseDate IS NULL
             RETURNING drawerId",
            [$amount, $date, ($userId !== '' ? $userId : null), $expected, $drawerId, $companyId]
        );
        return $r !== false && $r->RecordCount() > 0;
    }

    /**
     * Corrige fechas/montos de apertura y cierre. SCOPEADO por companyId.
     *
     * Re-congela el esperado con la ventana YA corregida. Corregir un arqueo es
     * un acto explícito de re-declararlo: si el dueño cambia el monto de
     * apertura o mueve la fecha de cierre, el esperado viejo pasa a estar
     * calculado sobre datos que ya no son los de esta caja, y dejarlo quieto
     * daría un veredicto contra un número que no corresponde a ninguna de las
     * dos versiones. Recalcular es seguro sobre el pasado: con el cierre de
     * período (mig 157) las filas económicas de un mes cerrado son inmutables,
     * así que el recálculo de un turno viejo da siempre lo mismo.
     */
    public function correct(string $drawerId, string $companyId, string $openDate, string $closeDate, float $openAmount, float $closeAmount): bool
    {
        global $db;
        $expected = $closeDate !== ''
            ? $this->expectedCashFor($drawerId, $companyId, $closeDate, $openDate, $openAmount)
            // Corrección que reabre la caja (sin fecha de cierre): no hay arqueo
            // que congelar, y el valor viejo describiría un cierre que ya no existe.
            : null;

        $r = $db->Execute(
            "UPDATE drawer SET drawerOpenDate = ?, drawerCloseDate = ?, drawerOpenAmount = ?, drawerCloseAmount = ?, drawerExpectedAmount = ?
             WHERE drawerId = ? AND companyId = ?",
            [$openDate, ($closeDate !== '' ? $closeDate : null), $openAmount, $closeAmount, $expected, $drawerId, $companyId]
        );
        return $r !== false;
    }

    /**
     * Efectivo esperado de UNA caja, con la misma fórmula que el cierre del POS
     * (caja inicial + ventas en efectivo + ingresos − extracciones), acotado a
     * la ventana [apertura, cierre].
     *
     * `$openDate`/`$openAmount` son opcionales: sirven para el caso de
     * `correct()`, donde los valores corregidos todavía no están en la fila.
     * Cuando no vienen, se leen de la BD.
     *
     * Best-effort por diseño (devuelve NULL ante cualquier problema): lo llaman
     * dos writes que no pueden fallar por esto. Un NULL deja el cierre marcado
     * como estimado en el reporte, que es degradación, no pérdida.
     */
    private function expectedCashFor(
        string $drawerId,
        string $companyId,
        string $closeBound,
        ?string $openDateOverride = null,
        ?float $openAmountOverride = null,
    ): ?float {
        try {
            $d = ncmExecute(
                'SELECT registerId, drawerOpenDate, drawerOpenAmount FROM drawer
                 WHERE drawerId = ? AND companyId = ? LIMIT 1',
                [$drawerId, $companyId]
            );
            if (!$d) {
                return null;
            }
            $registerId = (string) ($d['registerId'] ?? '');
            $openDate   = $openDateOverride   ?? (string) ($d['drawerOpenDate'] ?? '');
            $openAmount = $openAmountOverride ?? (float) ($d['drawerOpenAmount'] ?? 0);
            if ($registerId === '' || $openDate === '') {
                return null;
            }

            // ROC scopeado a ESTA caja: la ventana de un arqueo es de un
            // register, no del outlet activo del que mira el reporte.
            $regRoc = $this->buildRegisterRoc($companyId, $registerId);
            if ($regRoc === '') {
                return null;
            }

            $t = $this->componentsFor($openDate, $closeBound, $registerId, $companyId, null, $regRoc);
            return $openAmount + $t['cash'] + $t['income'] - $t['expense'];
        } catch (\Throwable $e) {
            error_log("[Reports\\DrawersService] no se pudo congelar el esperado (drawerId={$drawerId}): " . $e->getMessage());
            return null;
        }
    }

    /** Elimina una caja. SCOPEADO por companyId (legacy era IDOR + LIMIT roto en PG). */
    public function remove(string $drawerId, string $companyId): bool
    {
        global $db;
        $r = $db->Execute('DELETE FROM drawer WHERE drawerId = ? AND companyId = ?', [$drawerId, $companyId]);
        return $r !== false;
    }

    /** Vendido / vendido-en-efectivo / extracciones / ingresos / devoluciones para una caja [open, closeBound]. */
    private function componentsFor(string $openDate, string $closeBound, string $registerId, string $companyId, ?array $allSales, string $roc): array
    {
        if ($allSales === null) {
            $allSales = $this->salesByDrawerPeriod($openDate, $closeBound, $roc);
        }
        $sums = $this->sumForRegister($allSales, $registerId, $openDate, $closeBound);
        $sold = $sums['total'];
        $cash = $sums['cash'];

        // type IS NULL = extracción; type IS NOT NULL = ingreso (semántica legacy).
        $exp = ncmExecute(
            "SELECT SUM(expensesAmount) AS v FROM expenses
             WHERE expensesDate > ? AND expensesDate < ? AND type IS NULL AND registerId = ? AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $expense = $exp ? abs((float) ($exp['v'] ?? 0)) : 0.0;

        $inc = ncmExecute(
            "SELECT SUM(expensesAmount) AS v FROM expenses
             WHERE expensesDate > ? AND expensesDate < ? AND type IS NOT NULL AND registerId = ? AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $income = $inc ? abs((float) ($inc['v'] ?? 0)) : 0.0;

        $ret = ncmExecute(
            "SELECT SUM(transactionTotal) AS total, SUM(transactionDiscount) AS disc FROM transaction
             WHERE transactionDate BETWEEN ? AND ? AND registerId = ? AND transactionType = 6 AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $return = ($ret && $ret['total'] !== null) ? ((float) $ret['total'] - (float) ($ret['disc'] ?? 0)) : 0.0;

        return ['sold' => $sold, 'cash' => $cash, 'expense' => $expense, 'income' => $income, 'return' => $return];
    }

    /**
     * Cuadre de una caja: qué se esperaba, qué se contó, y el veredicto.
     *
     * `$frozen` es `drawer.drawerExpectedAmount` (mig 164) — el efectivo que el
     * cajero tenía delante al cerrar. Cuando está, MANDA: es el número contra
     * el que se arqueó ese día. El estimado se recalcula con datos de hoy, y
     * "hoy" incluye ventas que sincronizaron tarde y extracciones cargadas
     * después; usarlo pudiendo usar el congelado sería reescribir el veredicto
     * de un cierre viejo cada vez que alguien abre el reporte.
     *
     * Sin congelado (cierres anteriores a la migración) se estima con la MISMA
     * fórmula — caja inicial + ventas en efectivo + ingresos − extracciones —
     * y se marca `expectedSource = 'estimated'`. Se estima en vez de mostrar un
     * guión porque el dueño necesita poder mirar su historial, y se marca
     * porque un veredicto recalculado no es un veredicto que quedó registrado:
     * la UI lo muestra como estimado, no como hecho.
     *
     * @param array{sold:float,cash:float,expense:float,income:float,return:float} $t
     * @return array{expectedAmount:float|null,expectedSource:string|null,difference:float|null,cashStatus:string}
     */
    private function cashCount(bool $isClosed, ?float $frozen, float $closeAmount, float $openAmount, array $t, float $tolerance): array
    {
        if (!$isClosed) {
            // Caja abierta: no hay monto contado, así que no hay cuadre. El
            // esperado igual se informa — es el "debería haber" en vivo.
            return [
                'expectedAmount' => $openAmount + $t['cash'] + $t['income'] - $t['expense'],
                'expectedSource' => 'live',
                'difference'     => null,
                'cashStatus'     => CashCountStatus::UNKNOWN,
            ];
        }

        $expected = $frozen ?? ($openAmount + $t['cash'] + $t['income'] - $t['expense']);
        $source   = $frozen !== null ? 'frozen' : 'estimated';

        return [
            'expectedAmount' => $expected,
            'expectedSource' => $source,
            'difference'     => $closeAmount - $expected,
            'cashStatus'     => CashCountStatus::classify($closeAmount, $expected, $tolerance),
        ];
    }

    /**
     * Tolerancia efectiva del comercio, resuelta una sola vez por request.
     * Se lee acá (backend) y no en el front porque el veredicto se calcula
     * acá: `context/08` §58 — "si la regla se enforcea en el backend, el flag
     * también se lee en el backend".
     */
    private function toleranceFor(string $companyId): float
    {
        if (isset($this->toleranceCache[$companyId])) {
            return $this->toleranceCache[$companyId];
        }
        $general   = (new \Punto\Api\Settings\SettingsService())->general($companyId);
        $tolerance = CashCountStatus::effectiveTolerance(
            (float) ($general[CashCountStatus::SETTING_KEY] ?? 0),
            (bool) ($general['decimal'] ?? false),
        );
        return $this->toleranceCache[$companyId] = $tolerance;
    }

    /**
     * Port fiel de getAllSalesByDrawerPeriod() del panel — sólo el ROC se recibe por parámetro
     * (no se recalcula adentro). Devuelve por registerId: lista de {date, total, cash}
     * (total= transactionTotal - transactionDiscount) filtrando ventas internas.
     *
     * `cash` se calcula en esta MISMA pasada (no en una query por caja) porque el
     * esperado del cajón sólo cuenta el efectivo: la parte cobrada con tarjeta o a
     * crédito no entra en billetes. Traer `transactionPaymentType` acá no agrega
     * una sola consulta — el listado ya recorre estas filas.
     *
     * @return array<string, array<int, array{date:string,total:float,cash:float}>>
     */
    private function salesByDrawerPeriod(string $from, string $to, string $roc): array
    {
        $sql = "SELECT transactionId, transactionTotal as total, transactionDiscount as discount, registerId,
                       transactionDate, transactionType, transactionPaymentType, meta->>'tags' AS tags
                FROM transaction
                WHERE transactionDate BETWEEN ? AND ?
                  AND transactionType IN (0,5,6)" . $roc;

        $result = ncmExecute($sql, [$from, $to], false, true);
        if (!$result) {
            return [];
        }

        $rows = [];
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }
        $result->Close();

        // mig 115: transactionParentId dropeada — batch lookup del origen de
        // los pagos (type 5) vía transaction_link. companyId sale de $roc
        // (Roc::build lo embebe como literal validado), igual criterio que
        // NonAddingSales::salesByPayment (mismo helper acá, duplicado a
        // propósito: son clases sin relación de herencia).
        $companyId = self::companyIdFromRoc($roc);
        $paymentIds = array_values(array_filter(array_map(
            static fn($f) => (int) $f['transactionType'] === 5 ? (string) $f['transactionId'] : null,
            $rows
        )));
        $originByPayment = ($paymentIds !== [] && $companyId !== '')
            ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($companyId, $paymentIds, 'credit_payment')
            : [];

        $a = [];
        foreach ($rows as $f) {
            if ((int) $f['transactionType'] === 5) {
                $parentId = $originByPayment[(string) $f['transactionId']] ?? null;
                $ignore   = $parentId ? isParentInternalSale($parentId) : false;
            } else {
                $tags   = json_decode((string) ($f['tags'] ?? ''), true);
                $ignore = isInternalSale($tags);
            }
            if (!$ignore) {
                $reg = (string) ($f['registerId'] ?? '');
                $a[$reg][] = [
                    'date'  => (string) $f['transactionDate'],
                    'total' => (float) $f['total'] - (float) $f['discount'],
                    'cash'  => self::cashPortion($f),
                ];
            }
        }
        return $a;
    }

    /**
     * Parte de una transacción que entró al cajón en billetes.
     *
     * Reusa `groupByPaymentMethod()` — el mismo normalizador que usa
     * `getSalesByPayment()` en el cierre — en vez de leer el JSON crudo: ahí
     * viven el fallback de `{name,total}` sin `price`, el clamp de pago parcial
     * y el resolve de id/slug a nombre. Duplicar ese criterio acá haría que el
     * esperado ESTIMADO del reporte y el esperado CONGELADO del cierre dieran
     * distinto sobre los mismos datos, que es peor que no estimar nada.
     *
     * Devoluciones (transactionType 6): 0. `getSalesByPayment()` las reclasifica
     * como tipo 'return' y el esperado del cierre no las descuenta — el
     * estimado tiene que hacer lo mismo o los dos números no serían
     * comparables.
     *
     * @param array<string,mixed>|\ArrayAccess $f Fila cruda de `transaction`.
     */
    private static function cashPortion($f): float
    {
        if ((int) $f['transactionType'] === 6) {
            return 0.0;
        }
        $methods = json_decode((string) ($f['transactionPaymentType'] ?? ''), true);
        if (!is_array($methods) || $methods === []) {
            return 0.0;
        }
        $grouped = groupByPaymentMethod($methods, []);
        if (!is_array($grouped)) {
            return 0.0;
        }
        $cash = 0.0;
        foreach ($grouped as $m) {
            if (\Punto\Api\Services\DrawerService::isCashPaymentType((string) ($m['type'] ?? ''))) {
                $cash += (float) ($m['price'] ?? 0);
            }
        }
        return $cash;
    }

    /**
     * Extrae companyId del fragmento `$roc` (Roc::build() lo embebe como
     * literal validado: `AND companyId = 'uuid'`).
     */
    private static function companyIdFromRoc(string $roc): string
    {
        return preg_match("/companyId\\s*=\\s*'([0-9a-f-]{36})'/i", $roc, $m) === 1 ? $m[1] : '';
    }

    /**
     * Port de sumTotalBetweenDateRanges() del panel, extendido para devolver
     * también la parte en efectivo del mismo recorte (una sola pasada).
     *
     * @return array{total:float,cash:float}
     */
    private function sumForRegister(array $allSales, string $registerId, string $from, string $to): array
    {
        if ($to === '0000-00-00 00:00:00' || $to === '') {
            $to = TODAY;
        }
        $total = 0.0;
        $cash  = 0.0;
        foreach ($allSales as $reg => $values) {
            if ($reg !== $registerId) { continue; }
            foreach ($values as $data) {
                if ($data['date'] > $from && $data['date'] < $to) {
                    $total += (float) $data['total'];
                    $cash  += (float) ($data['cash'] ?? 0);
                }
            }
        }
        return ['total' => $total, 'cash' => $cash];
    }

    /** ROC scoped a register específico (companyId + registerId). Sólo si ambos son UUID. */
    private function buildRegisterRoc(string $companyId, string $registerId): string
    {
        if (!preg_match(self::UUID_RE, $companyId) || !preg_match(self::UUID_RE, $registerId)) {
            return '';
        }
        return " AND companyId = '" . $companyId . "' AND registerId = '" . $registerId . "'";
    }

    /** Lookup batch id→name de outlet/register, scopeado por companyId. */
    private function nameMap(string $table, string $idCol, string $nameCol, array $ids, string $companyId): array
    {
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }
}
