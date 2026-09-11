<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomMigrationException.php';
require_once __DIR__ . '/EncomMigrationService.php';

/**
 * Importa el HISTÓRICO del legacy: ventas con sus líneas, compras y
 * movimientos de caja (context/77 §17, F2 del migrador).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LA REGLA QUE MANDA SOBRE TODO: esto es un REGISTRO CONTABLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Lo que se importa acá son hechos que YA OCURRIERON en otro sistema. Se
 * registran para poder LEERLOS —reportes, balance, cuentas por cobrar y por
 * pagar— y nada más. El histórico **NO TOCA**:
 *
 *   · **stock** — la mercadería de esas ventas ya salió del depósito hace
 *     meses. El saldo de HOY lo pone la apertura de inventario (dominio
 *     `stock`, §16), que es una foto del presente. Descontar además cada
 *     venta histórica dejaría el inventario en negativo por el mismo
 *     movimiento contado dos veces.
 *   · **caja / arqueos** — esos turnos se cerraron en el otro sistema.
 *   · **numeración fiscal de Punto** — el número lo trae el documento del
 *     legacy, CONGELADO. Nada de `document_sequence`, que es justo lo que el
 *     import de cajas (§5) dejó posicionado para la PRÓXIMA venta real.
 *   · **facturación electrónica** — esos documentos ya se emitieron (o no) en
 *     su momento. Nada se encola a SIFEN.
 *
 * ── Por qué NO se usa `SaleService::save()` ──────────────────────────────
 * Es la excepción explícita al D4 del migrador ("importar por los servicios
 * reales"), y es legítima porque **la operación es OTRA**: no se está
 * vendiendo, se está asentando algo que ya se vendió.
 *
 * `SaleService::save()` hace exactamente las cuatro cosas de la lista de
 * arriba: toma el próximo número de `document_sequence`, encola el documento
 * electrónico, mueve el ledger de stock y mueve la caja. Llamarlo con una
 * fecha vieja no lo convierte en un asiento histórico: lo convierte en una
 * VENTA NUEVA con fecha vieja, que le rompe la serie fiscal al comercio y le
 * descuadra el inventario.
 *
 * Por eso acá hay un camino de escritura propio, ACOTADO a insertar los
 * hechos (`transaction` + `itemSold`, y `expenses` para los movimientos de
 * caja) y deliberadamente incapaz de hacer nada más. No es un atajo por
 * velocidad: es que el servicio de ventas modela otra cosa.
 *
 * ── Lo que sí se respeta del camino normal ───────────────────────────────
 *   · `ncmInsert()`, el helper canónico de inserción (resuelve el nombre real
 *     de cada columna contra el schema de PG y rutea lo que no es columna al
 *     JSONB). No es SQL crudo a mano.
 *   · `transaction_registry` se puebla SOLO, por el trigger AFTER INSERT de
 *     la mig 156 — por eso el `itemSold` va después del `transaction` y en la
 *     misma transacción (su propio trigger exige que el registry ya exista).
 *   · `rollupMarkDirty()` + `rollup_reconcile()`, el mecanismo de rollups de
 *     siempre.
 *
 * ── Marca de origen ──────────────────────────────────────────────────────
 * `transaction` NO tiene columna de origen, y la única que se le parece
 * —`channel`— tiene un CHECK cerrado (`mostrador|mesa|delivery`): inventarle
 * un valor es exactamente el error que el proyecto ya documentó con
 * `stockSource` (un valor nuevo sale crudo en pantalla y ningún reporte lo
 * entiende). La convención REAL del repo para "esta fila vino de una
 * migración" es `migration_map`, que además es lo que da idempotencia. Se usa
 * esa, y se deja `meta.importedFrom` en la fila para que quien mire la
 * transacción suelta sepa de dónde salió sin cruzar tablas.
 */
final class EncomHistoryImporter
{
    /** Cuántos meses hacia atrás se traen si el operador no eligió rango. */
    private const DEFAULT_MONTHS_BACK = 12;

    /** @var array<int,array{domain:string,message:string,at:string}> */
    private array $errors = [];

    /** @var array<int,array{at:string,message:string}> */
    private array $log = [];

    /** Catálogo del destino: sku normalizado → itemId, nombre normalizado → itemId. */
    private ?array $itemBySku  = null;
    private array $itemByName  = [];

    /** itemId → `item.itemCost`, o null si el artículo no tiene costo cargado. */
    private array $costByItemId = [];

    /** Artículos cuyas líneas entraron SIN costo, para la bitácora. */
    private array $sinCostoLineas = [];

    /** Días tocados por dominio de rollup: "dominio|YYYY-MM-DD" → true. */
    private array $diasSucios = [];

    public function __construct(
        private readonly string $companyId,
        private readonly EncomSource $source,
        private readonly ?string $jobId = null,
    ) {
    }

    /** @return array<int,array{domain:string,message:string,at:string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<int,array{at:string,message:string}> */
    public function log(): array
    {
        return $this->log;
    }

    // ═══════════════════════════════════════════════════════════════════
    // VENTAS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param array $options `historyFrom` / `historyTo` ('YYYY-MM-DD').
     * @return array{total:int,imported:int,skipped:int,failed:int}
     */
    public function sales(array $options): array
    {
        global $db;

        $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        [$desde, $hasta] = $this->range($options);

        $this->ensurePartitions($desde, $hasta);

        $sinCliente = 0;
        $sinUsuario = [];

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'sales_history')) {
                continue;
            }

            try {
                $ventas = $this->source->salesHistory($mesIni . ' 00:00:00', $mesFin . ' 23:59:59');
            } catch (\Throwable $e) {
                $this->fail('sales_history', 'No se pudieron traer las ventas de ' . $mesIni . ': ' . $e->getMessage());
                continue;
            }

            foreach ($ventas as $venta) {
                $legacyId = trim((string) ($venta['ID'] ?? ''));
                if ($legacyId === '') {
                    continue;
                }

                $counts['total']++;

                // Idempotencia: esta venta ya se asentó en una corrida previa.
                // Es lo que hace que un job cortado a la mitad se pueda
                // relanzar sin duplicar un solo asiento.
                if (EncomMigrationService::mapped($this->companyId, 'sale_history', $legacyId) !== null) {
                    $counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($venta['date'] ?? '');
                if ($fecha === null) {
                    $counts['failed']++;
                    $this->fail('sales_history', 'Venta ' . $legacyId . ': no se pudo leer la fecha.');
                    continue;
                }

                // Las dimensiones obligatorias NO se adivinan (memoria del
                // proyecto: prohibido resolver una dimensión faltante con "la
                // primera activa"). `transaction.userid` y `.outletid` son NOT
                // NULL, así que sin mapa la venta no entra y se dice por qué.
                $outletId = $this->mapOf('outlet', $venta['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $counts['failed']++;
                    $this->fail(
                        'sales_history',
                        'Venta ' . $legacyId . ': la sucursal "' . (string) ($venta['outlet'] ?? '')
                        . '" no está migrada. Migrá la configuración y volvé a lanzar.'
                    );
                    continue;
                }

                $userId = $this->mapOf('user', $venta['user'] ?? '', 'user_name');
                if ($userId === '') {
                    $counts['failed']++;
                    $sinUsuario[trim((string) ($venta['user'] ?? '(sin usuario)'))] = true;
                    continue;
                }

                $customerId = $this->mapOf('customer', $venta['customer'] ?? '', 'customer_name');
                if ($customerId === '') {
                    $sinCliente++;
                }

                try {
                    $lineas = $this->source->saleLines($legacyId);
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    $this->fail('sales_history', 'Venta ' . $legacyId . ': no se pudo traer el detalle: ' . $e->getMessage());
                    continue;
                }

                try {
                    $db->StartTrans();

                    $txId = $this->insertTransaction($venta, $fecha, $outletId, $userId, $customerId, $lineas);
                    $this->insertLines(
                        $txId, $fecha, $lineas, $outletId, $userId, $legacyId,
                        $this->esCredito($venta) ? '3' : '0'
                    );

                    // El asiento y su marca, ATÓMICOS. Sin esto, un worker que
                    // muere entre las dos escrituras deja la venta sin marcar y
                    // la corrida siguiente la asienta DE NUEVO: el comercio
                    // vería el doble de facturación en sus reportes.
                    EncomMigrationService::remember(
                        $this->companyId, 'sale_history', $legacyId, $txId, $this->jobId
                    );

                    $db->CompleteTrans();

                    $this->marcarSucio(['sales', 'item_sales', 'payments'], $fecha);
                    $counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $counts['failed']++;
                    $this->fail('sales_history', 'Venta ' . $legacyId . ': ' . $e->getMessage());
                }
            }
        }

        if ($sinCliente > 0) {
            $this->note(
                $sinCliente . ' venta(s) quedaron SIN cliente porque el cliente del legacy no está migrado. '
                . 'El asiento es correcto (el total y los ítems están); lo que falta es a quién se le vendió. '
                . 'No se inventan contactos.'
            );
        }

        $this->avisarSinCosto();

        if ($sinUsuario !== []) {
            $this->note(
                'Ventas NO importadas por usuario sin migrar: ' . implode(', ', array_slice(array_keys($sinUsuario), 0, 20))
                . '. Una transacción de Punto no puede existir sin el usuario que la hizo, y ponerle otro sería '
                . 'atribuirle ventas a quien no las hizo. Migrá los usuarios y volvé a lanzar.'
            );
        }

        $this->drenarRollups();

        return $counts;
    }

    // ═══════════════════════════════════════════════════════════════════
    // COMPRAS
    // ═══════════════════════════════════════════════════════════════════

    /** @return array{total:int,imported:int,skipped:int,failed:int} */
    public function purchases(array $options): array
    {
        global $db;

        $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        [$desde, $hasta] = $this->range($options);

        $this->ensurePartitions($desde, $hasta);

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'purchases_history')) {
                continue;
            }

            $desdeTs = $mesIni . ' 00:00:00';
            $hastaTs = $mesFin . ' 23:59:59';

            try {
                $compras = $this->source->purchasesHistory($desdeTs, $hastaTs);
            } catch (\Throwable $e) {
                $this->fail('purchases_history', 'No se pudieron traer las compras de ' . $mesIni . ': ' . $e->getMessage());
                continue;
            }

            // Las líneas del rango vienen en UNA request y se agrupan por
            // número de documento: el detalle del legacy no trae el id de la
            // compra, solo el `#Documento`.
            $lineasPorDoc = [];
            try {
                foreach ($this->source->purchaseLines($desdeTs, $hastaTs) as $l) {
                    $doc = trim((string) ($l['docNumber'] ?? ''));
                    if ($doc !== '') {
                        $lineasPorDoc[$doc][] = $l;
                    }
                }
            } catch (\Throwable $e) {
                $this->note('No se pudo traer el detalle de las compras de ' . $mesIni . ': entran solo las cabeceras.');
            }

            foreach ($compras as $compra) {
                $legacyId = trim((string) ($compra['ID'] ?? ''));
                $doc      = trim((string) ($compra['docNumber'] ?? ''));
                if ($legacyId === '') {
                    $legacyId = $doc;
                }
                if ($legacyId === '') {
                    continue;
                }

                $counts['total']++;

                if (EncomMigrationService::mapped($this->companyId, 'purchase_history', $legacyId) !== null) {
                    $counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($compra['date'] ?? '');
                if ($fecha === null) {
                    $counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': no se pudo leer la fecha.');
                    continue;
                }

                $outletId = $this->mapOf('outlet', $compra['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $counts['failed']++;
                    $this->fail(
                        'purchases_history',
                        'Compra ' . $legacyId . ': la sucursal "' . (string) ($compra['outlet'] ?? '')
                        . '" no está migrada.'
                    );
                    continue;
                }

                $userId = $this->mapOf('user', $compra['user'] ?? '', 'user_name');
                if ($userId === '') {
                    $counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': el usuario no está migrado.');
                    continue;
                }

                // El proveedor es un contacto como el cliente: si no está
                // migrado, la compra entra sin él (el gasto es correcto igual).
                $supplierId = $this->mapOf('customer', $compra['supplier'] ?? '', 'supplier_name');
                $lineas     = $lineasPorDoc[$doc] ?? [];

                try {
                    $db->StartTrans();

                    $txId = $this->insertPurchase($compra, $fecha, $outletId, $userId, $supplierId, $lineas);
                    $this->insertLines(
                        $txId, $fecha, $lineas, $outletId, $userId, $legacyId,
                        $this->esCredito($compra) ? '4' : '1'
                    );

                    EncomMigrationService::remember(
                        $this->companyId, 'purchase_history', $legacyId, $txId, $this->jobId
                    );

                    $db->CompleteTrans();

                    $this->marcarSucio(['expenses'], $fecha);
                    $counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': ' . $e->getMessage());
                }
            }
        }

        $this->avisarSinCosto();
        $this->drenarRollups();

        return $counts;
    }

    // ═══════════════════════════════════════════════════════════════════
    // GASTOS / MOVIMIENTOS DE CAJA
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Movimientos de caja históricos.
     *
     * Entran a `expenses` y NADA MÁS. En particular **no** se llama a
     * `FinanceLedger::recordDrawerExpense()`, que es lo que el camino normal
     * hace después de insertar: ese método mueve el saldo de una cuenta
     * financiera HOY, y el saldo de hoy no lo define una extracción de hace
     * ocho meses que ya ocurrió en el otro sistema.
     *
     * @return array{total:int,imported:int,skipped:int,failed:int}
     */
    public function expenses(array $options): array
    {
        global $db;

        $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        [$desde, $hasta] = $this->range($options);

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'expenses_history')) {
                continue;
            }

            try {
                $filas = $this->source->expensesHistory($mesIni . ' 00:00:00', $mesFin . ' 23:59:59');
            } catch (\Throwable $e) {
                $this->fail('expenses_history', 'No se pudieron traer los movimientos de caja de ' . $mesIni . ': ' . $e->getMessage());
                continue;
            }

            foreach ($filas as $fila) {
                $legacyId = trim((string) ($fila['ID'] ?? ''));
                if ($legacyId === '') {
                    continue;
                }

                $counts['total']++;

                if (EncomMigrationService::mapped($this->companyId, 'expense_history', $legacyId) !== null) {
                    $counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($fila['date'] ?? '');
                $monto = $fila['total'] ?? null;
                if ($fecha === null || !is_float($monto)) {
                    $counts['failed']++;
                    $this->fail('expenses_history', 'Movimiento de caja ' . $legacyId . ': fecha o monto ilegibles.');
                    continue;
                }

                $outletId = $this->mapOf('outlet', $fila['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $counts['failed']++;
                    $this->fail(
                        'expenses_history',
                        'Movimiento de caja ' . $legacyId . ': la sucursal "' . (string) ($fila['outlet'] ?? '')
                        . '" no está migrada.'
                    );
                    continue;
                }

                try {
                    $db->StartTrans();

                    $id = \ncmInsert([
                        'records' => [
                            // NULL = movimiento de caja (mig 33). No es un
                            // gasto categorizado: el legacy no manda categoría.
                            'expensesNameId'      => null,
                            'expensesAmount'      => abs($monto),
                            'expensesDate'        => $fecha,
                            'expensesDescription' => $this->descripcionGasto($fila),
                            // `type` 1 = ingreso, NULL = extracción.
                            'type'                => $this->esIngreso($fila) ? 1 : null,
                            'userId'              => $this->mapOf('user', $fila['user'] ?? '', 'user_name') ?: null,
                            'registerId'          => $this->mapOf('register', $fila['register'] ?? '', 'register_name') ?: null,
                            'outletId'            => $outletId,
                            'companyId'           => $this->companyId,
                        ],
                        'table' => 'expenses',
                    ]);

                    if (!$id) {
                        throw new \RuntimeException('no se pudo insertar el movimiento de caja');
                    }

                    EncomMigrationService::remember(
                        $this->companyId, 'expense_history', $legacyId, (string) $id, $this->jobId
                    );

                    $db->CompleteTrans();

                    $this->marcarSucio(['drawer_expenses'], $fecha);
                    $counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $counts['failed']++;
                    $this->fail('expenses_history', 'Movimiento de caja ' . $legacyId . ': ' . $e->getMessage());
                }
            }
        }

        $this->drenarRollups();

        return $counts;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Escritura de los hechos
    // ═══════════════════════════════════════════════════════════════════

    /** Inserta la cabecera de una venta histórica. Devuelve el transactionId. */
    private function insertTransaction(
        array $venta,
        string $fecha,
        string $outletId,
        string $userId,
        string $customerId,
        array $lineas,
    ): string {
        [$prefix, $number] = $this->documento((string) ($venta['docNumber'] ?? ''));

        $esCredito = $this->esCredito($venta);
        $anulada   = $this->esAnulada($venta);

        $units = 0.0;
        foreach ($lineas as $l) {
            $units += (float) ($l['qty'] ?? 0);
        }

        $records = [
            'transactionDate'   => $fecha,
            // 0 contado / 3 crédito. La ANULADA no se importa como tipo 7: el
            // marcador vigente es `voidedAt` (mig 154) y es el que los rollups
            // miran para excluirla (mig 155). Con tipo 7 la venta quedaría
            // fuera de los reportes por otro camino y perdería su tipo real.
            'transactionType'   => $esCredito ? 3 : 0,
            'transactionStatus' => 1,
            // Una venta a crédito histórica sigue siendo una cuenta por
            // cobrar: se asienta abierta, como la asentaría el camino normal.
            'transactionComplete' => $esCredito ? 0 : 1,
            'transactionTotal'  => (float) ($venta['total'] ?? 0),
            'transactionTax'    => $venta['tax'] ?? null,
            'transactionDiscount' => $venta['discount'] ?? null,
            'transactionUnitsSold' => $units > 0 ? $units : null,
            'transactionNote'   => $this->recortar((string) ($venta['note'] ?? ''), 255),
            'transactionDueDate' => $this->fecha($venta['dueDate'] ?? '') ,
            'invoiceNo'         => $number,
            'invoicePrefix'     => $prefix,
            // El timbrado del documento tal como lo emitió el otro sistema.
            // Es dato del papel, no una serie de Punto: `document_sequence` no
            // se toca.
            'invoiceAuth'       => trim((string) ($venta['authNo'] ?? '')) ?: null,
            'customerId'        => $customerId !== '' ? $customerId : null,
            'userId'            => $userId,
            'outletId'          => $outletId,
            'registerId'        => $this->mapOf('register', $venta['register'] ?? '', 'register_name') ?: null,
            'companyId'         => $this->companyId,
            'meta'              => json_encode($this->meta($venta), JSON_UNESCAPED_UNICODE),
        ];

        if ($anulada) {
            $records['voidedAt']   = $fecha;
            $records['voidReason'] = 'Anulada en el sistema anterior';
        }

        $txId = \ncmInsert(['records' => $records, 'table' => 'transaction']);
        if (!$txId) {
            throw new \RuntimeException('no se pudo insertar la venta');
        }

        return (string) $txId;
    }

    /** Inserta la cabecera de una compra histórica. Devuelve el transactionId. */
    private function insertPurchase(
        array $compra,
        string $fecha,
        string $outletId,
        string $userId,
        string $supplierId,
        array $lineas,
    ): string {
        [$prefix, $number] = $this->documento((string) ($compra['docNumber'] ?? ''));

        $esCredito = $this->esCredito($compra);

        $units = 0.0;
        foreach ($lineas as $l) {
            $units += (float) ($l['qty'] ?? 0);
        }

        $records = [
            'transactionDate'      => $fecha,
            // 1 contado / 4 crédito — los mismos que escribe PurchasesService.
            'transactionType'      => $esCredito ? 4 : 1,
            'transactionStatus'    => 1,
            'transactionComplete'  => $esCredito ? 0 : 1,
            'transactionTotal'     => (float) ($compra['total'] ?? 0),
            'transactionTax'       => $compra['tax'] ?? null,
            'transactionUnitsSold' => $units > 0 ? $units : null,
            'transactionDueDate'   => $this->fecha($compra['dueDate'] ?? ''),
            // El documento del PROVEEDOR tiene columnas propias (mig 144): el
            // número de una compra no es una serie de Punto.
            'supplierDocPrefix'    => $prefix,
            'supplierDocNo'        => $number,
            'supplierAuthNo'       => trim((string) ($compra['authNo'] ?? '')) ?: null,
            'invoicePrefix'        => $prefix,
            'invoiceNo'            => $number,
            'userId'               => $userId,
            'supplierId'           => $supplierId !== '' ? $supplierId : null,
            'outletId'             => $outletId,
            'companyId'            => $this->companyId,
            'meta'                 => json_encode($this->meta($compra), JSON_UNESCAPED_UNICODE),
        ];

        $txId = \ncmInsert(['records' => $records, 'table' => 'transaction']);
        if (!$txId) {
            throw new \RuntimeException('no se pudo insertar la compra');
        }

        return (string) $txId;
    }

    /**
     * Inserta las líneas de un documento histórico.
     *
     * Corre DENTRO de la transacción del caller y DESPUÉS del insert de la
     * cabecera, no por prolijidad: `itemsold` tiene un trigger BEFORE INSERT
     * que completa sus dimensiones desde `transaction_registry` y **lanza** si
     * el `transactionId` todavía no está ahí. El registry lo puebla el trigger
     * AFTER INSERT de `transaction`, así que el orden es obligatorio.
     */
    private function insertLines(
        string $txId,
        string $fecha,
        array $lineas,
        string $outletId,
        string $userId,
        string $legacyDocId,
        string $typeStr,
    ): void {
        foreach ($lineas as $linea) {
            $nombre = trim((string) ($linea['itemName'] ?? ''));
            $qty    = $linea['qty'] ?? null;

            if ($nombre === '' && trim((string) ($linea['legacyItemId'] ?? '')) === '') {
                continue;
            }

            $itemId = $this->resolverArticulo($linea);
            if ($itemId === '') {
                // Nunca se descarta una línea en silencio: sin ella el total
                // del documento deja de cerrar contra la suma de sus ítems.
                $this->fail(
                    'sales_history',
                    'Documento ' . $legacyDocId . ': no se pudo resolver el artículo "' . $nombre . '".'
                );
                continue;
            }

            $total = $linea['total'] ?? null;
            if ($total === null && $qty !== null && ($linea['price'] ?? null) !== null) {
                $total = (float) $qty * (float) $linea['price'];
            }

            $records = [
                'itemSoldTotal'       => (float) ($total ?? 0),
                'itemSoldTax'         => $linea['tax'] ?? null,
                'itemSoldUnits'       => $qty,
                'itemSoldDate'        => $fecha,
                'itemSoldDescription' => $this->recortar($nombre, 255) ?: null,
                'itemId'              => $itemId,
                'transactionId'       => $txId,
                'userId'              => $userId,
                'companyId'           => $this->companyId,
                'outletId'            => $outletId,
            ];

            // ── COGS: el contrato es el de `SaleService` ───────────────────
            // La columna guarda el costo UNITARIO (no el de la línea): es lo
            // que devuelve `resolveUnitCOGS()` y lo que persiste
            // `persistItemsAndStock()`, sin multiplicar por la cantidad.
            //
            // Y se OMITE cuando no se sabe, en vez de escribirse null: pasa por
            // `flipOnReturn()`, que ante un valor no válido devuelve **0**, y un
            // 0 se lee como "costó nada" → margen 100% en todos los reportes de
            // ese artículo. Omitir deja la columna en NULL, que es la verdad.
            // Mismo criterio que la apertura de stock (§16.3).
            //
            // `flipOnReturn` es no-op para los tipos que importa el histórico
            // (0/3 venta, 1/4 compra) y solo invierte el signo en la devolución
            // (tipo 6). Se llama igual para que el contrato quede literal y no
            // haya que acordarse de esto si algún día se importan devoluciones.
            $cogs = $this->costFor($itemId);
            if ($cogs !== null) {
                $records['itemSoldCOGS'] = \flipOnReturn($typeStr, $cogs);
            } else {
                $this->sinCostoLineas[$nombre !== '' ? $nombre : $itemId] = true;
            }

            $ok = \ncmInsert([
                'records' => $records,
                'table'   => 'itemSold',
            ]);

            if (!$ok) {
                throw new \RuntimeException('no se pudo insertar una línea del documento ' . $legacyDocId);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Resolución de referencias
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `itemId` de Punto para una línea histórica.
     *
     * Orden: el mapa por id del legacy (si el form lo expuso) → SKU → nombre
     * normalizado → artículo HISTÓRICO archivado.
     *
     * El artículo histórico existe para que los totales CIERREN: una línea sin
     * artículo no se puede insertar (`itemsold.itemid` es NOT NULL con FK), y
     * descartarla dejaría una venta cuyo total no coincide con la suma de sus
     * ítems. Nace archivado (`itemStatus = 0`) y no vendible, así que no
     * aparece en el POS ni en el catálogo activo — todos los lectores filtran
     * `itemStatus = 1`.
     */
    private function resolverArticulo(array $linea): string
    {
        $legacyItemId = trim((string) ($linea['legacyItemId'] ?? ''));
        if ($legacyItemId !== '') {
            $id = $this->mapOf('item', $legacyItemId);
            if ($id !== '') {
                return $id;
            }
        }

        $this->cargarCatalogo();

        $nombre = trim((string) ($linea['itemName'] ?? ''));
        $clave  = $this->normalizar($nombre);

        if ($clave !== '' && isset($this->itemByName[$clave])) {
            return $this->itemByName[$clave];
        }

        if ($nombre === '') {
            return '';
        }

        // Ya se creó el histórico de este nombre en una corrida anterior.
        $previo = EncomMigrationService::mapped($this->companyId, 'item_history', $this->claveMapa($clave));
        if ($previo !== null) {
            return $this->itemByName[$clave] = $previo;
        }

        return $this->crearArticuloHistorico($nombre, $clave);
    }

    /** Crea el artículo archivado que sostiene las líneas sin match. */
    private function crearArticuloHistorico(string $nombre, string $clave): string
    {
        require_once dirname(__DIR__) . '/Items/ItemService.php';
        require_once dirname(__DIR__) . '/Items/ItemRepository.php';

        global $db;

        $items  = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));
        $itemId = $items->createBlank($this->companyId, 'product', 'producto');

        if (!is_string($itemId) || $itemId === '') {
            return '';
        }

        $ok = $items->update($itemId, $this->companyId, [
            'itemName'           => $this->recortar('[Histórico] ' . $nombre, 190),
            'itemKind'           => 'producto',
            'itemType'           => 'product',
            // No vendible y archivado: existe para que el asiento cierre, no
            // para que alguien lo venda por accidente.
            'itemCanSale'        => 0,
            'itemTrackInventory' => 0,
            'itemProduction'     => 0,
            'itemStatus'         => 0,
            'updated_at'         => TODAY,
        ]);

        if (!$ok) {
            return '';
        }

        EncomMigrationService::remember(
            $this->companyId, 'item_history', $this->claveMapa($clave), $itemId, $this->jobId
        );

        $this->note('Artículo histórico creado (no existe en el catálogo migrado): ' . $nombre);

        return $this->itemByName[$clave] = $itemId;
    }

    /** Catálogo del destino indexado por SKU y por nombre normalizado. */
    private function cargarCatalogo(): void
    {
        if ($this->itemBySku !== null) {
            return;
        }

        $this->itemBySku = [];

        // SIN filtro de estado: el costo se necesita también para los
        // artículos HISTÓRICOS (que nacen archivados). El filtro se aplica
        // abajo, y solo a los índices de BÚSQUEDA — un archivado no puede
        // ganar un match por nombre contra el catálogo vivo.
        $rs = \ncmExecute(
            'SELECT itemId, itemName, itemSKU, itemCost, itemStatus FROM item WHERE companyId = ?',
            [$this->companyId],
            false,
            true
        );

        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $f  = $rs->fields;
                $id = (string) ($f['itemId'] ?? $f['itemid'] ?? '');
                if ($id !== '') {
                    // `is_numeric` y no un cast: NULL es "no lo sé" y tiene que
                    // llegar como null hasta la decisión de escribir o no el
                    // COGS. Un 0 acá se volvería margen 100% para siempre.
                    $costo = $f['itemCost'] ?? $f['itemcost'] ?? null;
                    $this->costByItemId[$id] = is_numeric($costo) ? (float) $costo : null;

                    $activo = (int) ($f['itemStatus'] ?? $f['itemstatus'] ?? 0) === 1;
                    if ($activo) {
                        $sku    = $this->normalizar((string) ($f['itemSKU'] ?? $f['itemsku'] ?? ''));
                        $nombre = $this->normalizar((string) ($f['itemName'] ?? $f['itemname'] ?? ''));
                        if ($sku !== '') {
                            $this->itemBySku[$sku] = $id;
                        }
                        if ($nombre !== '' && !isset($this->itemByName[$nombre])) {
                            $this->itemByName[$nombre] = $id;
                        }
                    }
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }
    }

    /**
     * Costo UNITARIO del artículo para una línea histórica.
     *
     * Es el costo ACTUAL del artículo (`item.itemCost`), no el del día de la
     * venta: el legacy no expone el costo de cada venta. Ver §17.9 del doc —
     * el margen histórico es una aproximación conocida y declarada.
     *
     * Sale de `item.itemCost` y no del promedio ponderado del ledger a
     * propósito: `itemCost` es el que el propio migrador escribió (o el que
     * soporte cargó después) y NO depende de que el dominio de apertura de
     * stock haya corrido. Con la apertura corrida los dos valen lo mismo.
     */
    private function costFor(string $itemId): ?float
    {
        $this->cargarCatalogo();
        return $this->costByItemId[$itemId] ?? null;
    }

    /**
     * Id de Punto para una referencia del legacy.
     *
     * El histórico llega con NOMBRES (el listado del panel muestra "Casa
     * Central", no el hashid), así que además del mapa por id se busca por
     * nombre — y lo que se encuentra así se DEJA ESCRITO en el mapa, para que
     * la corrida siguiente no tenga que volver a adivinar y para que quede
     * registrado con qué se emparejó.
     */
    private function mapOf(string $domain, mixed $valor, ?string $aliasDomain = null): string
    {
        $v = trim((string) ($valor ?? ''));
        if ($v === '') {
            return '';
        }

        $directo = EncomMigrationService::mapped($this->companyId, $domain, $v);
        if ($directo !== null) {
            return $directo;
        }

        if ($aliasDomain === null) {
            return '';
        }

        $clave  = $this->claveMapa($this->normalizar($v));
        $alias  = EncomMigrationService::mapped($this->companyId, $aliasDomain, $clave);
        if ($alias !== null) {
            return $alias;
        }

        $id = $this->buscarPorNombre($domain, $v);
        if ($id === '') {
            return '';
        }

        EncomMigrationService::remember($this->companyId, $aliasDomain, $clave, $id, $this->jobId);

        return $id;
    }

    /** Busca una entidad del destino por nombre normalizado. */
    private function buscarPorNombre(string $domain, string $nombre): string
    {
        [$tabla, $col, $id] = match ($domain) {
            'outlet'   => ['outlet', 'outletName', 'outletId'],
            'register' => ['register', 'registerName', 'registerId'],
            'user'     => ['contact', 'contactName', 'contactId'],
            'customer' => ['contact', 'contactName', 'contactId'],
            // Falla RUIDOSO, no devolviendo vacío: un dominio nuevo que se
            // cablee a `mapOf()` con alias y se olvide de esta tabla dejaría
            // de resolver SIEMPRE, y el síntoma sería "todas las ventas
            // rechazadas por referencia sin migrar" — un rato largo de buscar
            // en el lugar equivocado.
            default    => throw new \LogicException(
                'EncomHistoryImporter: el dominio "' . $domain . '" no tiene tabla de búsqueda por nombre.'
            ),
        };

        $row = \ncmExecute(
            "SELECT $id AS id FROM $tabla
              WHERE companyId = ? AND lower(trim($col)) = lower(trim(?)) LIMIT 1",
            [$this->companyId, $nombre]
        );

        if (!$row) {
            return '';
        }

        return (string) ($row['id'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Particiones, período cerrado y rollups
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Crea las particiones mensuales que el rango histórico necesita.
     *
     * ── Por qué hace falta, verificado contra Postgres real ──────────────
     * `transaction` e `itemsold` están particionadas por mes y tienen
     * partición DEFAULT, así que un INSERT con fecha vieja **no falla**: cae
     * en la DEFAULT. El problema es que se queda ahí PARA SIEMPRE —
     * `ensure_month_partitions()` ancla su cobertura en la partición más vieja
     * YA CREADA y, a propósito, no se deja empujar hacia atrás por los datos.
     * Un año de histórico terminaría entero en la DEFAULT y toda consulta por
     * un mes viejo la escanearía: el particionado (E1 de context/48) anulado
     * justo para el comercio que más filas trajo.
     *
     * Por eso el rango se declara ANTES de insertar, con
     * `ensure_month_partitions_range()` (mig 221), que es la puerta explícita
     * para un rango conocido y acotado. No contradice la regla de la mig 156
     * —que una fecha basura suelta no mueva la cobertura—: acá el rango no es
     * un accidente, es lo que el operador pidió importar.
     */
    private function ensurePartitions(string $desde, string $hasta): void
    {
        global $db;

        foreach ([['transaction', 'transactiondate'], ['itemsold', 'itemsolddate']] as [$tabla, $col]) {
            try {
                $db->Execute(
                    'SELECT ensure_month_partitions_range(?::regclass, ?::name, ?::date, ?::date)',
                    [$tabla, $col, $desde, $hasta]
                );
            } catch (\Throwable $e) {
                // Si no se pudieron crear, el import NO se aborta: las filas
                // caen en la DEFAULT y el histórico igual se lee. Lo que se
                // pierde es el beneficio del particionado, y eso se dice.
                $this->note(
                    'No se pudieron preparar las particiones de ' . $tabla . ' para el rango histórico ('
                    . $e->getMessage() . '). El histórico entra igual, pero conviene revisarlo.'
                );
            }
        }
    }

    /**
     * ¿El mes cae en un período cerrado?
     *
     * El guard de la base (`fn_period_guard`, mig 157) es BEFORE UPDATE OR
     * DELETE **únicamente**: un INSERT con fecha en un período cerrado entra
     * sin que nada lo frene (es deliberado — regla offline-first, el back
     * nunca rechaza una venta ya emitida). O sea que la base NO va a proteger
     * al comercio de que una migración le reescriba un mes ya cerrado y
     * conciliado.
     *
     * Así que el chequeo lo hace el importador, ANTES de insertar y por MES
     * entero: se salta el mes completo con un mensaje claro, en vez de dejar
     * medio mes importado.
     */
    private function periodoCerrado(string $mesIni, string $domain): bool
    {
        global $db;

        try {
            $cerrado = $db->GetOne(
                'SELECT period_is_closed(?::uuid, ?::timestamptz)',
                [$this->companyId, $mesIni . ' 12:00:00']
            );
        } catch (\Throwable $e) {
            return false;
        }

        $esCerrado = $cerrado === true || $cerrado === 't' || $cerrado === 'true' || $cerrado === 1 || $cerrado === '1';

        if ($esCerrado) {
            $this->fail(
                $domain,
                'El período ' . substr($mesIni, 0, 7) . ' está CERRADO en esta empresa: no se importa nada de ese '
                . 'mes. Un período cerrado ya fue conciliado y sus reportes son definitivos. Si el histórico tiene '
                . 'que entrar igual, hay que reabrirlo antes a propósito.'
            );
        }

        return $esCerrado;
    }

    /** Anota el día como sucio para el rollup (mecanismo de siempre). */
    private function marcarSucio(array $domains, string $fecha): void
    {
        $dia = substr($fecha, 0, 10);
        foreach ($domains as $d) {
            $this->diasSucios[$d . '|' . $dia] = true;
        }
        \rollupMarkDirty($this->companyId, $domains, $dia);
    }

    /**
     * Recalcula lo que el import ensució.
     *
     * Sin esto el histórico NO APARECE en ningún reporte: los reportes leen
     * los rollups pre-agregados, no la tabla de hechos. Marcarlo sucio solo
     * alcanzaría si alguien esperara a que corra el cron — y el operador está
     * mirando la pantalla del job ahora.
     *
     * Se drena con `rollup_reconcile()`, el MISMO motor que usa el job de
     * mantenimiento, en tandas acotadas y con techo: un histórico grande no
     * puede dejar al worker recalculando sin fin.
     */
    private function drenarRollups(): void
    {
        global $db;

        if ($this->diasSucios === []) {
            return;
        }

        $vueltas = 0;
        do {
            try {
                $hechos = (int) $db->GetOne('SELECT rollup_reconcile(?)', [500]);
            } catch (\Throwable $e) {
                $this->note('No se pudieron recalcular los reportes del histórico: ' . $e->getMessage()
                    . '. Van a quedar al día cuando corra el mantenimiento.');
                return;
            }
            $vueltas++;
        } while ($hechos >= 500 && $vueltas < 200);

        $this->diasSucios = [];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Deja en la bitácora los artículos cuyas líneas entraron SIN costo.
     *
     * No es cosmético: esas líneas tienen `itemSoldCOGS` en NULL, así que no
     * suman margen en ningún reporte. Cargarle el costo al artículo y volver a
     * lanzar NO las arregla —el asiento ya está escrito y no se re-importa—,
     * así que lo que hay que saber es exactamente cuáles quedaron así.
     */
    private function avisarSinCosto(): void
    {
        if ($this->sinCostoLineas === []) {
            return;
        }

        $nombres = array_keys($this->sinCostoLineas);
        $this->note(
            'Líneas importadas SIN costo (su margen no va a figurar en los reportes, porque el artículo no '
            . 'tiene costo cargado en Punto): ' . implode(', ', array_slice($nombres, 0, 30))
            . (count($nombres) > 30 ? ' … y ' . (count($nombres) - 30) . ' más.' : '')
            . ' No se les pone 0: un 0 se lee como "costó nada" y daría margen 100%.'
        );

        $this->sinCostoLineas = [];
    }

    /** Rango a importar: lo que eligió el operador, o los últimos 12 meses. */
    private function range(array $options): array
    {
        $desde = $this->soloFecha((string) ($options['historyFrom'] ?? ''));
        $hasta = $this->soloFecha((string) ($options['historyTo'] ?? ''));

        $hasta ??= date('Y-m-d');
        $desde ??= date('Y-m-d', strtotime('-' . self::DEFAULT_MONTHS_BACK . ' months'));

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde, $hasta];
    }

    /**
     * Los meses del rango, como pares (primer día, último día).
     *
     * El import va por MES y no de una: un año de un comercio mediano son
     * miles de ventas con una request por cada una, y si el proceso se corta
     * la corrida siguiente tiene que poder retomar sin volver a pedir —ni a
     * insertar— lo que ya entró.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function months(string $desde, string $hasta): array
    {
        $out = [];
        $cur = strtotime(substr($desde, 0, 8) . '01');
        $fin = strtotime(substr($hasta, 0, 8) . '01');

        while ($cur !== false && $cur <= $fin) {
            $ini   = date('Y-m-01', $cur);
            $out[] = [$ini, date('Y-m-t', $cur)];
            $cur   = strtotime('+1 month', $cur);
        }

        return $out;
    }

    /** Fecha del legacy → timestamp aceptable por PG, o null. */
    private function fecha(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '' || $s === '-') {
            return null;
        }

        // `data-order` suele traer un timestamp unix o un 'Y-m-d H:i:s'.
        if (preg_match('/^\d{9,13}$/', $s) === 1) {
            $ts = (int) $s;
            if ($ts > 99999999999) {
                $ts = (int) ($ts / 1000);   // milisegundos
            }
            return date('Y-m-d H:i:s', $ts);
        }

        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private function soloFecha(string $s): ?string
    {
        $s = trim($s);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }

    /**
     * "001-001-0001234" → ['001-001', 1234].
     *
     * El número va CONGELADO tal como lo emitió el otro sistema. No se pide
     * uno nuevo a `document_sequence`: eso le movería al comercio la serie con
     * la que va a facturar mañana.
     *
     * @return array{0:?string,1:?int}
     */
    private function documento(string $doc): array
    {
        $doc = trim($doc);
        if ($doc === '') {
            return [null, null];
        }

        if (preg_match('/^(\d{3}-\d{3})-?0*(\d+)$/', $doc, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        $digits = preg_replace('/\D/', '', $doc) ?? '';
        return [null, $digits !== '' ? (int) $digits : null];
    }

    /** Marca de origen de la fila. Ver el docblock de la clase. */
    private function meta(array $row): array
    {
        return [
            'importedFrom' => 'encom',
            'legacyId'     => (string) ($row['ID'] ?? ''),
            'legacyDoc'    => (string) ($row['docNumber'] ?? ''),
            'migrationJob' => $this->jobId,
        ];
    }

    /** ¿El documento es a crédito? Define el tipo de transacción y el signo. */
    private function esCredito(array $row): bool
    {
        return $this->contiene((string) ($row['type'] ?? ''), ['CREDITO', 'CRÉDITO']);
    }

    /** ¿El legacy marcó este documento como anulado? */
    private function esAnulada(array $venta): bool
    {
        return $this->contiene((string) ($venta['type'] ?? ''), ['ANUL', 'CANCEL'])
            || $this->contiene((string) ($venta['docType'] ?? ''), ['ANUL', 'CANCEL']);
    }

    private function esIngreso(array $fila): bool
    {
        return $this->contiene((string) ($fila['type'] ?? ''), ['INGRESO', 'ENTRADA', 'DEPOSITO']);
    }

    private function descripcionGasto(array $fila): string
    {
        $nota = trim((string) ($fila['note'] ?? ''));
        return $nota !== '' ? $this->recortar($nota, 255) : 'Movimiento de caja migrado del sistema anterior';
    }

    private function contiene(string $texto, array $agujas): bool
    {
        $t = mb_strtoupper(trim($texto), 'UTF-8');
        foreach ($agujas as $a) {
            if ($t !== '' && str_contains($t, mb_strtoupper($a, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    /**
     * Clave para `migration_map.legacyid`, que es `varchar(64)`.
     *
     * Mismo criterio que `compoundKey()`/`openingKey()` del importador de
     * catálogo: una clave que no entra haría fallar el INSERT de la marca, y
     * la marca es lo único que impide volver a asentar el mismo hecho.
     */
    private function claveMapa(string $clave): string
    {
        return strlen($clave) <= 64 ? $clave : 'h:' . sha1($clave);
    }

    private function recortar(string $s, int $max): string
    {
        $s = trim($s);
        return mb_strlen($s, 'UTF-8') <= $max ? $s : mb_substr($s, 0, $max, 'UTF-8');
    }

    private function fail(string $domain, string $message): void
    {
        $this->errors[] = ['domain' => $domain, 'message' => $message, 'at' => gmdate('c')];
    }

    private function note(string $message): void
    {
        $this->log[] = ['at' => gmdate('c'), 'message' => $message];
    }
}
