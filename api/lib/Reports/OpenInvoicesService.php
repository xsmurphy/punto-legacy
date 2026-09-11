<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Cuentas por Cobrar/Pagar / Open Invoices (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportOpenInvoicesService.php (Fase 2 batch 3). Único cambio
 * vs el original: namespace + `final` + recibe $companyId por parámetro en vez de leerlo
 * de la constante global (mejor higiene multi-tenant; el endpoint pasa COMPANY_ID).
 *
 * Read-only. Sin ROC (companyId siempre bound en cada SELECT). Helpers globales usados:
 * getContactData, getCustomerName — ambos viven en app/includes/functions.php.
 *
 * Réplica fiel del legacy:
 *  - el "self-heal write" del legacy (marcar transactionComplete=1 durante el GET) se ELIMINA
 *    (convención §16 — nunca escribir en un read; además los contactos totalmente pagados
 *    ya quedan ocultos por el filtro needsTopay).
 *  - estado de vencimiento: vencida si dueDate <= hoy (o sin dueDate); resto "por vencer"
 *    (réplica de la rareza del legacy donde la rama "normal" nunca se daba).
 */
final class OpenInvoicesService
{
    /**
     * @param string $state 'income' (tipo 3, clientes) | 'outcome' (tipo 4, proveedores).
     * @param string|null $contactId Filtra a UN contacto puntual (customerId/supplierId
     *   según $state) — usado por el diálogo de cobro multi-factura del panel para listar
     *   las facturas a crédito pendientes de un cliente sin traer las de toda la empresa.
     *   Reusa el mismo cálculo que el reporte agregado (mismo dueStatus, mismo
     *   payedByParent vía transaction_link) en vez de duplicar la query.
     * @param list<string> $outletIds Alcance por sucursal (`OutletScope::effectiveIds()`).
     *   `[]` = consolidado ("Todas"), que es lo que el selector manda como 'all'; un
     *   elemento = esa sucursal; 2+ = las asignadas al usuario. Sin esto el reporte
     *   mezclaba las facturas de TODAS las sucursales aunque el usuario tuviera una
     *   elegida (reporte del tester, 2026-08-28).
     */
    public function general($state, $companyId, ?string $contactId = null, array $outletIds = [])
    {
        $isToPay    = ($state === 'outcome');
        $isCustomer = !$isToPay;

        $invoices = $this->openCreditInvoices($companyId, $isCustomer, $contactId, $outletIds);
        if ($invoices === []) {
            return ['rows' => [], 'kpi' => ['totalDebt' => 0, 'accounts' => 0, 'expired' => 0, 'toExpire' => 0]];
        }

        $byContact = [];
        $saleIds   = [];
        foreach ($invoices as $inv) {
            $byContact[$inv['cid']][] = $inv;
            $saleIds[] = $inv['saleId'];
        }
        $payedMap = $this->payedByParent($saleIds, $companyId, $isCustomer);

        $today  = strtotime(date('Y-m-d 00:00:00'));
        $rows = [];
        $kTotalDebt = 0.0; $kAccounts = 0; $kExpired = 0; $kToExpire = 0;

        foreach ($byContact as $cid => $contactInvoices) {
            $contact = getContactData($cid, $isToPay ? 'id' : 'uid', true);
            $name = $contact ? getCustomerName($contact) : 'Sin Contacto Asociado';
            $balance = $this->contactBalance($contactInvoices, $payedMap);

            // El conteo de cuentas/vencidas/por-vencer corre sobre TODAS las
            // facturas del contacto, gated o no (réplica fiel del legacy: un
            // contacto que terminó de pagar igual aportaba a estos contadores
            // mientras estuvo en la lista de "incompletas").
            $outInvoices = [];
            foreach ($balance['invoices'] as $inv) {
                $strDue = $inv['dueDate'] ? strtotime($inv['dueDate']) : 0;
                $dueStatus = ($strDue <= $today) ? 'expired' : 'toExpire';
                if ($dueStatus === 'expired') { $kExpired++; } else { $kToExpire++; }
                $kAccounts++;

                $outInvoices[] = [
                    'invoiceNo' => $inv['invoiceNo'],
                    'saleId'    => $inv['saleId'],
                    'date'      => $inv['date'],
                    'dueDate'   => $inv['dueDate'],
                    'total'     => $inv['total'],
                    'payed'     => $inv['payed'],
                    'topay'     => $inv['topay'],
                    'dueStatus' => $dueStatus,
                ];
            }

            if (!$balance['needsTopay']) { continue; }
            $kTotalDebt += $balance['totalDebt'];

            $rows[] = [
                'contactId'  => (string) $cid,
                'name'       => $name,
                'tin'        => (string) ($contact['ruc'] ?? '-'),
                'phone'      => (string) ($contact['phone'] ?? ($contact['phone2'] ?? '')),
                'email'      => (string) ($contact['email'] ?? ''),
                'totalSales' => $balance['totalSales'],
                'totalPaid'  => $balance['totalPaid'],
                'totalDebt'  => $balance['totalDebt'],
                'invoices'   => $outInvoices,
            ];
        }

        return ['rows' => $rows, 'kpi' => [
            'totalDebt' => $kTotalDebt, 'accounts' => $kAccounts, 'expired' => $kExpired, 'toExpire' => $kToExpire,
        ]];
    }

    /**
     * VISTA AGREGADA de las dos puntas del crédito — lo que alimenta el
     * dashboard de `/reports/open-invoices` (`?view=summary`).
     *
     * No es un reporte nuevo: es el MISMO universo que `general()` (las dos
     * llamadas pasan por `openCreditInvoices()` + `payedByParent()` +
     * `contactBalance()`), agregado por antigüedad, por contacto y por
     * vencimiento. Si el listado y el dashboard divergieran alguna vez, sería
     * por otra razón que no es "cada uno calcula la deuda a su manera": la
     * resta `total - payed` sigue viviendo en un solo lugar.
     *
     * ── Qué cuenta como plata abierta ────────────────────────────────────────
     * El saldo es `topay` (total de la factura menos lo aplicado por
     * `transaction_link`: recibos, notas de crédito, devoluciones), o sea el
     * PENDIENTE, nunca el emitido. Y entran solo las facturas con `topay > 0`:
     * las que quedaron en cero siguen con `transactionComplete = false` porque
     * el self-heal del legacy se eliminó (ver el docblock de la clase), así que
     * son ruido — y una sobrepagada (topay < 0) restaría de su bucket de
     * antigüedad, que es peor que ruido. Por eso `count` acá es "comprobantes
     * con saldo" y no coincide con el `kpi.accounts` del listado, que cuenta
     * TODAS las facturas de los contactos deudores (réplica del legacy).
     *
     * ── "Hoy" ────────────────────────────────────────────────────────────────
     * Sale de `TenantClock::now()`, no de `date()`: el corte del día tiene que
     * ser el del comercio. `general()` usa `date()` y hoy funciona porque el
     * embudo de auth ya aplicó la TZ del tenant, pero eso es cierto del
     * REQUEST, no del método — un cron o el realm /admin lo llamarían corrido.
     *
     * @param list<string> $outletIds Mismo alcance que `general()`: `[]` = todas.
     * @return array{
     *   today:string,
     *   totals:array{receivable:float,payable:float,net:float,receivableCount:int,payableCount:int},
     *   receivable:array, payable:array, projection:array
     * }
     */
    public function summary(string $companyId, array $outletIds = []): array
    {
        $today = new \DateTimeImmutable(
            substr(\Punto\Api\Support\TenantClock::now($companyId), 0, 10),
            new \DateTimeZone('UTC')
        );

        $receivable = $this->openBalances($companyId, true, $outletIds, $today);
        $payable    = $this->openBalances($companyId, false, $outletIds, $today);

        $sideIn  = $this->sideSummary($receivable, true);
        $sideOut = $this->sideSummary($payable, false);

        return [
            'today'  => $today->format('Y-m-d'),
            'totals' => [
                'receivable'      => $sideIn['open'],
                'payable'         => $sideOut['open'],
                'net'             => $sideIn['open'] - $sideOut['open'],
                'receivableCount' => $sideIn['count'],
                'payableCount'    => $sideOut['count'],
            ],
            'receivable' => $sideIn,
            'payable'    => $sideOut,
            'projection' => $this->dueSchedule($receivable, $payable, $today),
        ];
    }

    /**
     * Las facturas ABIERTAS de una punta, ya con su saldo y su fecha de corte
     * resuelta. Es el insumo único de los tres bloques del dashboard
     * (antigüedad, ranking por contacto y proyección) — que los tres lean la
     * misma lista es lo que garantiza que los totales cierren entre sí.
     *
     * `effectiveDue` es la fecha contra la que se mide: el VENCIMIENTO, y si el
     * comprobante no lo tiene, la fecha de EMISIÓN con `missingDue = true`. La
     * alternativa (dejarlas afuera) esconde plata real; la otra (meterlas en
     * "por vencer") es peor todavía, porque una factura sin vencimiento suele
     * ser vieja. Se cuentan aparte y el reporte las declara en pantalla.
     *
     * @return list<array{cid:string,saleId:string,invoiceNo:string,date:string,dueDate:string,
     *                    open:float,effectiveDue:\DateTimeImmutable,missingDue:bool,daysOverdue:int}>
     */
    private function openBalances(
        string $companyId,
        bool $isCustomer,
        array $outletIds,
        \DateTimeImmutable $today
    ): array {
        $invoices = $this->openCreditInvoices($companyId, $isCustomer, null, $outletIds);
        if ($invoices === []) {
            return [];
        }

        $payedMap = $this->payedByParent(array_column($invoices, 'saleId'), $companyId, $isCustomer);

        $out = [];
        foreach ($invoices as $inv) {
            $open = $inv['total'] - ($payedMap[$inv['saleId']] ?? 0);
            if ($open <= 0) {
                continue;
            }

            $due        = $this->asDate($inv['dueDate']);
            $missingDue = $due === null;
            if ($missingDue) {
                $due = $this->asDate($inv['date']) ?? $today;
            }

            $out[] = [
                'cid'          => $inv['cid'],
                'saleId'       => $inv['saleId'],
                'invoiceNo'    => $inv['invoiceNo'],
                'date'         => $inv['date'],
                'dueDate'      => $inv['dueDate'],
                'open'         => $open,
                'effectiveDue' => $due,
                'missingDue'   => $missingDue,
                // Positivo = vencida hace N días. Vencer HOY ya cuenta como
                // vencida, igual que en `general()` (dueDate <= hoy).
                'daysOverdue'  => -$this->dayDelta($today, $due),
            ];
        }

        return $out;
    }

    /**
     * Los agregados de UNA punta: total abierto, vencido vs. por vencer,
     * antigüedad y el top 10 de contactos.
     *
     * La antigüedad se cuenta DESDE EL VENCIMIENTO, no desde la emisión: un
     * plazo de 90 días recién otorgado no es una deuda vieja, y medirla desde
     * la emisión pintaría de rojo a todo comercio que venda a plazo largo.
     * `overdue` es exactamente la suma de los cuatro buckets — no se devuelve
     * el "por vencer" adentro de `aging` para que esa identidad sea verificable
     * de un vistazo.
     */
    private function sideSummary(array $balances, bool $isCustomer): array
    {
        $buckets = ['0-30' => [0.0, 0], '31-60' => [0.0, 0], '61-90' => [0.0, 0], '90+' => [0.0, 0]];
        $overdue = [0.0, 0];
        $notDue  = [0.0, 0];
        $noDue   = [0.0, 0];
        $open    = 0.0;
        $count   = 0;
        $byContact = [];

        foreach ($balances as $b) {
            $open += $b['open'];
            $count++;

            if ($b['missingDue']) {
                $noDue[0] += $b['open'];
                $noDue[1]++;
            }

            if ($b['daysOverdue'] >= 0) {
                $overdue[0] += $b['open'];
                $overdue[1]++;
                $k = $b['daysOverdue'] <= 30 ? '0-30'
                    : ($b['daysOverdue'] <= 60 ? '31-60'
                    : ($b['daysOverdue'] <= 90 ? '61-90' : '90+'));
                $buckets[$k][0] += $b['open'];
                $buckets[$k][1]++;
            } else {
                $notDue[0] += $b['open'];
                $notDue[1]++;
            }

            $cid = $b['cid'];
            if (!isset($byContact[$cid])) {
                $byContact[$cid] = ['open' => 0.0, 'count' => 0, 'oldest' => null];
            }
            $byContact[$cid]['open'] += $b['open'];
            $byContact[$cid]['count']++;
            $prev = $byContact[$cid]['oldest'];
            if ($prev === null || strcmp((string) $b['date'], (string) $prev['date']) < 0) {
                $byContact[$cid]['oldest'] = $b;
            }
        }

        return [
            'open'       => $open,
            'count'      => $count,
            'contacts'   => count($byContact),
            'overdue'    => ['amount' => $overdue[0], 'count' => $overdue[1]],
            'notDue'     => ['amount' => $notDue[0],  'count' => $notDue[1]],
            // Sin vencimiento cargado: su antigüedad se midió desde la EMISIÓN,
            // así que ya está sumada arriba. Se declara para que el reporte lo
            // pueda decir en pantalla en vez de que el número mienta callado.
            'sinVencimiento' => ['amount' => $noDue[0], 'count' => $noDue[1]],
            'aging'      => array_map(
                static fn ($k) => ['bucket' => $k, 'amount' => $buckets[$k][0], 'count' => $buckets[$k][1]],
                array_keys($buckets)
            ),
            'top'        => $this->topContacts($byContact, $isCustomer),
        ];
    }

    /**
     * Los 10 contactos con más plata abierta, con su comprobante más viejo.
     *
     * El nombre se resuelve con `getContactData()` + `getCustomerName()`, igual
     * que `general()` — y SOLO para estos 10 por punta: resolverlo para todos
     * los contactos con deuda sería un N+1 sobre `contact` para tirar el 95% de
     * las filas. Desempate por `contactId` para que dos contactos con el mismo
     * saldo no intercambien lugares entre dos requests.
     */
    private function topContacts(array $byContact, bool $isCustomer): array
    {
        $ids = array_keys($byContact);
        usort($ids, static function ($a, $b) use ($byContact) {
            $cmp = $byContact[$b]['open'] <=> $byContact[$a]['open'];
            return $cmp !== 0 ? $cmp : strcmp((string) $a, (string) $b);
        });

        $out = [];
        foreach (array_slice($ids, 0, 10) as $cid) {
            $agg     = $byContact[$cid];
            $contact = getContactData($cid, $isCustomer ? 'uid' : 'id', true);
            $oldest  = $agg['oldest'];

            $out[] = [
                'contactId' => (string) $cid,
                'name'      => $contact ? getCustomerName($contact) : 'Sin Contacto Asociado',
                'open'      => $agg['open'],
                'count'     => $agg['count'],
                'oldest'    => $oldest === null ? null : [
                    'invoiceNo'   => $oldest['invoiceNo'],
                    'saleId'      => $oldest['saleId'],
                    'date'        => $oldest['date'],
                    'dueDate'     => $oldest['dueDate'],
                    'daysOverdue' => $oldest['daysOverdue'],
                ],
            ];
        }

        return $out;
    }

    /**
     * PROYECCIÓN POR VENCIMIENTO — aritmética de fechas, NO un pronóstico.
     *
     * Reparte lo que YA está emitido y abierto en las 8 semanas que vienen,
     * según la fecha en que cada comprobante vence. No modela estacionalidad,
     * no proyecta ventas futuras, no estima probabilidad de cobro: si nadie
     * paga nada, ninguna de estas semanas ocurre. El nombre del método y esta
     * nota existen para que nadie lo lea como un forecast ni lo "mejore"
     * agregándole tendencia — para eso haría falta otra cosa, con otro nombre.
     *
     * Tres buckets fuera de la grilla, los tres visibles a propósito:
     *   `overdue` lo ya vencido (arrastre: debería haber entrado y no entró),
     *   `beyond`  lo que vence después de la semana 8,
     *   y las semanas se cuentan desde HOY (semana 1 = hoy..hoy+6), no desde
     *   el lunes: la pregunta es "de acá a una semana", no "en qué semana del
     *   calendario".
     */
    private function dueSchedule(array $receivable, array $payable, \DateTimeImmutable $today): array
    {
        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $from = $today->modify('+' . ($i * 7) . ' days');
            $weeks[] = [
                'week'    => $i + 1,
                'from'    => $from->format('Y-m-d'),
                'to'      => $from->modify('+6 days')->format('Y-m-d'),
                'inflow'  => 0.0,
                'outflow' => 0.0,
            ];
        }
        $overdue = ['inflow' => 0.0, 'outflow' => 0.0];
        $beyond  = ['inflow' => 0.0, 'outflow' => 0.0];

        foreach ([[$receivable, 'inflow'], [$payable, 'outflow']] as [$balances, $key]) {
            foreach ($balances as $b) {
                if ($b['daysOverdue'] >= 0) {
                    $overdue[$key] += $b['open'];
                    continue;
                }
                $idx = intdiv(-$b['daysOverdue'], 7);
                if ($idx >= 8) {
                    $beyond[$key] += $b['open'];
                    continue;
                }
                $weeks[$idx][$key] += $b['open'];
            }
        }

        foreach ($weeks as $i => $w) {
            $weeks[$i]['net'] = $w['inflow'] - $w['outflow'];
        }

        return [
            // Lo dice el payload, no solo el código: quien consuma esto desde
            // afuera (MCP, export) tiene que saber qué está leyendo.
            'basis'   => 'dueDate',
            'overdue' => $overdue + ['net' => $overdue['inflow'] - $overdue['outflow']],
            'weeks'   => $weeks,
            'beyond'  => $beyond + ['net' => $beyond['inflow'] - $beyond['outflow']],
        ];
    }

    /**
     * 'Y-m-d ...' → fecha sin hora anclada en UTC, o null si no hay fecha.
     *
     * UTC no es un descuido: el ancla es irrelevante mientras sea la MISMA para
     * "hoy" y para los vencimientos, y anclarlas en una zona con DST hace que
     * la resta de dos medianoches dé 23 o 25 horas y que un `floor(.../86400)`
     * se corra un día dos veces por año. Acá la diferencia se toma con `diff()`
     * sobre fechas, que cuenta días de calendario.
     */
    private function asDate(string $raw): ?\DateTimeImmutable
    {
        $d = substr(trim($raw), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        return new \DateTimeImmutable($d, new \DateTimeZone('UTC'));
    }

    /** Días de calendario de $a a $b (negativo si $b es anterior). */
    private function dayDelta(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * Saldo pendiente de UN contacto puntual (cuentas por cobrar si
     * $isCustomer, por pagar si no) — misma fuente de verdad que general():
     * transacciones a crédito incompletas (transactionComplete = false)
     * menos lo saldado vía transaction_link (payedByParent, mig 115/123),
     * decidido por `contactBalance()` (ver docblock ahí — es el mismo método
     * que usa general() para calcular cada fila, no una réplica).
     *
     * Existe para que `ContactAnalyticsService::compute()` (ficha de
     * contacto) no reimplemente el cálculo del saldo con su propio criterio
     * — antes leía la columna muerta `transactionPaid` (nadie la escribe) y
     * divergía en silencio del reporte general.
     */
    public function forContact(string $contactId, string $companyId, bool $isCustomer): float
    {
        $invoices = $this->openCreditInvoices($companyId, $isCustomer, $contactId);
        if ($invoices === []) {
            return 0.0;
        }

        $payedMap = $this->payedByParent(array_column($invoices, 'saleId'), $companyId, $isCustomer);
        $balance  = $this->contactBalance($invoices, $payedMap);

        return $balance['needsTopay'] ? $balance['totalDebt'] : 0.0;
    }

    /**
     * Estado de cuenta completo de UN contacto — mismo cálculo de saldo que
     * `forContact()`/`general()` (pasa por `contactBalance()`, la única resta
     * `total - payed` de la clase) pero devuelve el DETALLE que la ficha de
     * contacto necesita: cada factura a crédito abierta con su saldo, y qué
     * recibos se le aplicaron (un recibo puede repartirse entre varias
     * facturas — mig 123, `TransactionLinkService::mapDerivedDetailsByOrigins()`).
     *
     * Alcance: solo facturas ABIERTAS (`transactionComplete = false`), igual
     * que `forContact()` — es "cuánto debe hoy", no el historial completo de
     * ventas a crédito (eso ya lo cubre el tab "Transacciones" del perfil).
     * Por eso `summary.totalCredited` (suma de esas facturas) cierra
     * exactamente con `totalPaid + totalDebt` — no hay una tercera cifra
     * flotando sin relación con las otras dos.
     *
     * @return array{summary:array{totalDebt:float,totalCredited:float,totalPaid:float},invoices:array}
     */
    public function contactStatement(string $contactId, string $companyId, bool $isCustomer): array
    {
        $empty = ['summary' => ['totalDebt' => 0.0, 'totalCredited' => 0.0, 'totalPaid' => 0.0], 'invoices' => []];

        $invoices = $this->openCreditInvoices($companyId, $isCustomer, $contactId);
        if ($invoices === []) {
            return $empty;
        }
        $saleIds = array_column($invoices, 'saleId');

        $payedMap = $this->payedByParent($saleIds, $companyId, $isCustomer);
        $balance  = $this->contactBalance($invoices, $payedMap);

        // FIX: hardcodeado a 'credit_payment' sin importar $isCustomer — para
        // proveedores esto dejaba "Pagos aplicados" SIEMPRE vacío (los pagos a
        // proveedor se linkean con kind='purchase_payment', mig 115/123).
        // `payedByParent` (arriba) tenía el MISMO hardcodeo — fixeado junto con
        // este, ver su docblock: para proveedores el saldo mostrado tampoco
        // bajaba con pagos parciales, solo al saldar la factura del todo.
        $paymentKind = $isCustomer ? 'credit_payment' : 'purchase_payment';
        $links = new \Punto\Api\Services\TransactionLinkService();
        $paymentsByOrigin = $links->mapDerivedDetailsByOrigins($companyId, $saleIds, $paymentKind);

        $today = strtotime(date('Y-m-d 00:00:00'));
        $out   = [];
        foreach ($balance['invoices'] as $inv) {
            $strDue    = $inv['dueDate'] ? strtotime($inv['dueDate']) : 0;
            $dueStatus = $inv['topay'] <= 0 ? 'paid' : (($strDue <= $today) ? 'expired' : 'toExpire');

            $out[] = [
                'saleId'    => $inv['saleId'],
                'invoiceNo' => $inv['invoiceNo'],
                'date'      => $inv['date'],
                'dueDate'   => $inv['dueDate'],
                'total'     => $inv['total'],
                'paid'      => $inv['payed'],
                'balance'   => $inv['topay'],
                'dueStatus' => $dueStatus,
                'payments'  => array_map(static fn ($p) => [
                    'transactionId' => $p['derivedId'],
                    'invoiceNo'     => $p['invoiceNo'],
                    'date'          => $p['date'],
                    'amount'        => $p['amount'],
                    // 6 = anulado (transactionStatus) — el recibo sigue
                    // visible para auditoría, ya no cuenta en `paid`/`balance`
                    // de arriba (mapSumDerivedAmounts ya lo excluye).
                    'voided'        => ((int) $p['status']) === 6,
                ], $paymentsByOrigin[$inv['saleId']] ?? []),
            ];
        }

        return [
            'summary' => [
                'totalDebt'      => $balance['totalDebt'],
                'totalCredited'  => $balance['totalSales'],
                'totalPaid'      => $balance['totalPaid'],
            ],
            'invoices' => $out,
        ];
    }

    /**
     * Fetch ÚNICO de "facturas a crédito abiertas" — la única query de la
     * clase que decide QUÉ entra en la deuda, igual que `contactBalance()` es
     * la única que decide CUÁNTO. `general()` (reporte de todos los
     * contactos), `forContact()` (saldo agregado de uno) y
     * `contactStatement()` (estado de cuenta detallado) pasan las tres por
     * acá: antes cada una repetía su propio SELECT y podían divergir sin que
     * nada lo delatara.
     *
     * POR QUÉ NO `getAssoc=true` (el bug que motivó la extracción)
     * -----------------------------------------------------------
     * Las tres versiones anteriores usaban `ncmExecute(..., false, false,
     * true)`. Ese 5º parámetro es `$getAssoc`, que delega en
     * `DB::GetAssoc()`, y GetAssoc keyea el resultado por el valor de la
     * PRIMERA columna proyectada (`$assoc[reset($row)] = $row`) PISANDO las
     * filas que repiten esa clave.
     *
     * `forContact()`/`contactStatement()` proyectaban `transactionId as
     * saleId` primero — único por fila, así que sobrevivían. Pero
     * `general()` proyectaba `$contactCol as cid` (customerId/supplierId)
     * primero, que se repite una vez por cada factura del mismo contacto:
     * TODAS las facturas de un contacto colapsaban en UNA sola, la última
     * del `ORDER BY`. El reporte de Cuentas por Cobrar/Pagar mostraba
     * "1 factura" para todo el mundo y un saldo arbitrariamente menor al
     * real (verificado en prod 2026-08-24: un cliente con 9 facturas
     * abiertas por 1.616.100 figuraba con una sola de 52.000). El diálogo
     * de cobro/pago multi-factura del panel consume ESTE mismo endpoint
     * (`MultiInvoicePaymentDialog`), así que también listaba una sola
     * factura por contacto.
     *
     * Se lee con `forceObj=true` (recordset, `while (!$rs->EOF)`): iteración
     * fila por fila, sin ninguna clave que pueda colisionar. Es la razón por
     * la que este método existe en vez de arreglar el SELECT de `general()`
     * en su lugar — mientras las tres consultas convivan, cualquiera puede
     * volver a nacer con la primera columna equivocada.
     *
     * También desaparece el `LIMIT 5000` que tenía `general()`: truncar en
     * silencio el listado de facturas abiertas es la misma clase de bug que
     * el colapso — un total de deuda que miente sin avisar. El universo está
     * acotado por `transactionComplete = false` (lo saldado sale solo).
     *
     * Cliente: total NETO de descuento. Proveedor: total crudo. Es la regla
     * que ya aplicaban las tres, y la MISMA que usa el camino de escritura
     * (`CreditPaymentService::create()`) para validar sobrepago.
     *
     * @return list<array{saleId:string,cid:string,invoiceNo:string,date:string,dueDate:string,total:float}>
     */
    /**
     * @param list<string> $outletIds Sucursales por las que filtrar. `[]` = TODAS.
     *
     * El filtro es OPT-IN y solo lo pasa `general()` — el reporte, que es lo que el
     * selector de sucursal del panel gobierna. `forContact()` y `contactStatement()`
     * lo dejan vacío A PROPÓSITO: el saldo de un contacto es lo que le debe a la
     * EMPRESA, no a una sucursal. Scopearlo haría que el diálogo de cobro ofrezca
     * cobrar contra una deuda parcial y que la ficha del cliente muestre menos de lo
     * que realmente debe.
     *
     * Los PAGOS no se filtran por sucursal en ningún caso: `payedByParent` los
     * resuelve por id de la factura de origen (`paidForCreditOrigins`), así que una
     * factura emitida en la sucursal A y cobrada en la B sigue descontando bien.
     * Filtrar los pagos por outlet haría reaparecer como impaga una factura ya
     * cobrada en otro mostrador.
     */
    private function openCreditInvoices(
        string $companyId,
        bool $isCustomer,
        ?string $contactId = null,
        array $outletIds = []
    ): array {
        $type       = $isCustomer ? 3 : 4;
        $contactCol = $isCustomer ? 'customerId' : 'supplierId';

        $sql = "SELECT transactionId as saleId, $contactCol as cid, transactionDate as date,
                       transactionDueDate as dueDate, invoiceNo as invoice, invoicePrefix as prefix,
                       transactionTotal as total, transactionDiscount as discount
                FROM transaction
                WHERE transactionComplete = false AND transactionType = ? AND companyId = ?";
        $params = [$type, $companyId];
        // La ÚNICA excepción al criterio de la clase (todo bindeado, sin
        // `Roc::build`): el alcance por sucursal ya no es un valor, es un
        // conjunto de 0, 1 o N uuids, y su fragmento se arma con
        // `OutletScope::sqlFilter()` — que interpola uuids re-validados. La
        // razón es el `$contactId` de acá abajo: con placeholders, pasar de una
        // sucursal a dos agrega un `?` EN EL MEDIO y ese bind se corre en
        // silencio, comparando el contacto contra un uuid de sucursal.
        $sql .= \Punto\Api\Outlets\OutletScope::sqlFilter('outletId', $outletIds);
        if ($contactId !== null && $contactId !== '') {
            $sql .= " AND $contactCol = ?";
            $params[] = $contactId;
        }
        $sql .= ' ORDER BY transactionDate DESC';

        $rs  = ncmExecute($sql, $params, false, true);
        $out = [];
        while ($rs && !$rs->EOF) {
            $f     = $rs->fields;
            $total = $isCustomer
                ? ((float) ($f['total'] ?? 0) - (float) ($f['discount'] ?? 0))
                : (float) ($f['total'] ?? 0);
            $out[] = [
                'saleId'    => (string) ($f['saleId'] ?? ''),
                'cid'       => (string) ($f['cid'] ?? ''),
                'invoiceNo' => (string) ($f['prefix'] ?? '') . (string) ($f['invoice'] ?? ''),
                'date'      => (string) ($f['date'] ?? ''),
                'dueDate'   => (string) ($f['dueDate'] ?? ''),
                'total'     => $total,
            ];
            $rs->MoveNext();
        }

        return $out;
    }

    /**
     * Núcleo único de "cuánto debe un contacto": dado su listado de facturas
     * abiertas (cada una con al menos `saleId`/`total`) y el `payedMap` ya
     * resuelto (`payedByParent`), resta payed de total factura por factura.
     * `general()` (reporte agregado, todos los contactos) y `forContact()`
     * (ficha de un contacto puntual) pasan AMBOS por acá — es la única resta
     * `total - payed` de la clase. Antes de esta extracción, T6 se debía a
     * que `ContactAnalyticsService` reimplementaba esta cuenta con su propio
     * criterio (columna `transactionPaid`, que nadie escribe) y divergía en
     * silencio del reporte general; ahora no hay una segunda fórmula que
     * pueda volver a desincronizarse.
     *
     * `needsTopay`: alcanza con que UNA factura tenga saldo positivo para que
     * el contacto cuente como deudor, aunque el neto total dé negativo por
     * otra factura sobrepagada (réplica fiel del legacy).
     *
     * @param array<int,array{saleId:string,total:float,dueDate?:string,invoiceNo?:string,date?:string}> $invoices
     * @param array<string,float> $payedMap
     * @return array{needsTopay:bool,totalSales:float,totalPaid:float,totalDebt:float,invoices:array}
     */
    private function contactBalance(array $invoices, array $payedMap): array
    {
        $needsTopay = false;
        $totalSales = 0.0; $totalPaid = 0.0; $totalDebt = 0.0;
        $detail = [];

        foreach ($invoices as $inv) {
            $payed = $payedMap[$inv['saleId']] ?? 0;
            $topay = $inv['total'] - $payed;
            if ($topay > 0) { $needsTopay = true; }

            $totalSales += $inv['total'];
            $totalPaid  += $payed;
            $totalDebt  += $topay;

            $detail[] = $inv + ['payed' => $payed, 'topay' => $topay];
        }

        return [
            'needsTopay' => $needsTopay,
            'totalSales' => $totalSales,
            'totalPaid'  => $totalPaid,
            'totalDebt'  => $totalDebt,
            'invoices'   => $detail,
        ];
    }

    /**
     * Lo que reduce la deuda por documento origen, scopeado por companyId.
     *
     * Delega en `TransactionLinkService::paidForCreditOrigins()` — LA
     * superficie única para este cálculo (antes vivía acá duplicada, y por
     * separado, ad-hoc y divergente, en `Services\TransactionService::
     * getSingle()` (POS) y `Transactions\TransactionDetailService::find()`
     * (panel `/transactions/{id}`); ninguna de esas dos restaba
     * `return`/`purchase_credit_note`, así que Caja y panel podían mostrar
     * saldos distintos para la misma factura con una nota de crédito
     * aplicada. Las tres migraron a este único método).
     *
     * `$isCustomer`: FIX (2026-08-16) — antes hardcodeaba `kind='credit_payment'`
     * sin importar el estado, así que un pago a PROVEEDOR (`purchase_payment`)
     * nunca reducía el saldo mostrado acá.
     */
    private function payedByParent(array $ids, $companyId, bool $isCustomer = true)
    {
        return (new \Punto\Api\Services\TransactionLinkService())->paidForCreditOrigins($companyId, $ids, $isCustomer);
    }
}
