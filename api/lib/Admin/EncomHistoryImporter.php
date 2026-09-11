<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomMigrationException.php';
require_once __DIR__ . '/EncomExportTruncatedException.php';
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

    /** itemId → categoría del artículo, para congelarla en la línea. */
    private array $categoryByItemId = [];

    /**
     * Las ventas históricas ya asentadas, para pegarles su log de ítems.
     *
     * `null` = todavía no se cargaron. Se indexan por las tres formas con las
     * que el log puede nombrar a su venta: el id del legacy, el documento
     * completo (`001-001-0001234`) y el número suelto (`6103`, que es como lo
     * trae el sistema vivo).
     */
    private ?array $txPorId = null;
    private array $txPorLegacy = [];
    private array $txPorDocumento = [];
    /** @var array<int,array<int,string>> número → ids de venta (puede haber varias) */
    private array $txPorNumero = [];

    /** Documentos del log de ítems que no tienen venta importada. */
    private array $lineasHuerfanas = [];

    /** Documentos cuyo número existe en más de una venta: no se elige ninguna. */
    private array $lineasAmbiguas = [];

    /** Cuántas líneas terminaron colgadas de un artículo histórico. */
    private int $lineasEnHistorico = 0;

    /** Artículos cuyas líneas entraron SIN costo, para la bitácora. */
    private array $sinCostoLineas = [];

    /** Días tocados por dominio de rollup: "dominio|YYYY-MM-DD" → true. */
    private array $diasSucios = [];

    /**
     * Conteos del dominio EN CURSO.
     *
     * Es estado del objeto y no una variable local por una razón concreta: un
     * dominio puede ABORTAR a mitad de camino (un mes truncado, el detalle de
     * venta ilegible) y ahí el `return` no ocurre nunca. Con un local, todo lo
     * que sí había entrado hasta ese momento se perdía de la pantalla del job
     * y el operador veía el error sin saber cuánto quedó asentado. Acá el
     * dispatcher lo lee igual (`counts()`), haya `return` o excepción.
     *
     * @var array{total:int,imported:int,skipped:int,failed:int,lines:int}
     */
    private array $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'lines' => 0];

    /** Momento del último latido, en segundos con decimales. */
    private float $ultimoLatido = 0.0;

    /**
     * Cada cuánto late. 20 s es holgado contra los 45 minutos que tarda el
     * reaper y corto contra el ritmo real del import (una request paceada por
     * venta, 1,1 s), así que hay decenas de latidos entre dos chequeos.
     */
    private const LATIDO_SEGUNDOS = 20.0;

    /**
     * @param \Closure|null $heartbeat Se invoca con los conteos del dominio en
     *        curso para que el job muestre avance Y siga vivo. Nulo en el arnés
     *        y en cualquier uso sin job: el importador no conoce la tabla.
     */
    public function __construct(
        private readonly string $companyId,
        private readonly EncomSource $source,
        private readonly ?string $jobId = null,
        private readonly ?\Closure $heartbeat = null,
    ) {
    }

    /**
     * Los conteos del dominio que corrió (o que estaba corriendo cuando falló).
     *
     * @return array{total:int,imported:int,skipped:int,failed:int,lines:int}
     */
    public function counts(): array
    {
        return $this->counts;
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

        $this->resetCounts();
        [$desde, $hasta] = $this->range($options);

        $this->assertPrerequisitos('sales_history');
        $this->ensurePartitions($desde, $hasta);

        // Dos cosas distintas que hasta hoy se contaban juntas y se reportaban
        // como la segunda (F4): el legacy NO traía cliente (celda vacía, lo
        // normal en mostrador) contra el legacy traía uno que acá no existe.
        // La primera no es un problema y no hay nada que hacer; la segunda es
        // un contacto sin migrar y tiene arreglo. Decir siempre "no está
        // migrado" mandaba a buscar un cliente que nunca existió.
        $sinClienteEnElLegacy = 0;
        $clienteSinMigrar     = [];
        $sinUsuario           = [];

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'sales_history')) {
                continue;
            }

            $desdeTs = $mesIni . ' 00:00:00';
            $hastaTs = $mesFin . ' 23:59:59';

            try {
                $ventas = $this->source->salesHistory($desdeTs, $hastaTs);
            } catch (EncomExportTruncatedException $e) {
                // Un export incompleto NO es un problema del mes: es el dominio
                // entero el que no se puede dar por bueno. Se re-lanza para que
                // aborte arriba, en vez de asentar los meses que sí entraron y
                // dejar al comercio con un año al que le faltan filas que nadie
                // sabe cuáles son.
                throw $e;
            } catch (\Throwable $e) {
                $this->fail('sales_history', 'No se pudieron traer las ventas de ' . $mesIni . ': ' . $e->getMessage());
                continue;
            }

            foreach ($ventas as $venta) {
                $this->latir();

                $legacyId = trim((string) ($venta['ID'] ?? ''));
                if ($legacyId === '') {
                    continue;
                }

                $this->counts['total']++;

                // Idempotencia: esta venta ya se asentó en una corrida previa.
                // Es lo que hace que un job cortado a la mitad se pueda
                // relanzar sin duplicar un solo asiento.
                if (EncomMigrationService::mapped($this->companyId, 'sale_history', $legacyId) !== null) {
                    $this->counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($venta['date'] ?? '');
                if ($fecha === null) {
                    $this->counts['failed']++;
                    $this->fail('sales_history', 'Venta ' . $legacyId . ': no se pudo leer la fecha.');
                    continue;
                }

                // Las dimensiones obligatorias NO se adivinan (memoria del
                // proyecto: prohibido resolver una dimensión faltante con "la
                // primera activa"). `transaction.userid` y `.outletid` son NOT
                // NULL, así que sin mapa la venta no entra y se dice por qué.
                $outletId = $this->mapOf('outlet', $venta['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $this->counts['failed']++;
                    $this->fail(
                        'sales_history',
                        'Venta ' . $legacyId . ': la sucursal "' . (string) ($venta['outlet'] ?? '')
                        . '" no está migrada. Migrá la configuración y volvé a lanzar.'
                    );
                    continue;
                }

                $userId = $this->mapOf('user', $venta['user'] ?? '', 'user_name');
                if ($userId === '') {
                    $this->counts['failed']++;
                    $sinUsuario[trim((string) ($venta['user'] ?? '(sin usuario)'))] = true;
                    continue;
                }

                $clienteLegacy = $this->textoDeCelda($venta['customer'] ?? '');
                $customerId    = $clienteLegacy !== '' ? $this->mapOf('customer', $clienteLegacy, 'customer_name') : '';
                if ($customerId === '') {
                    if ($clienteLegacy === '') {
                        $sinClienteEnElLegacy++;
                    } else {
                        $clienteSinMigrar[$clienteLegacy] = true;
                    }
                }

                try {
                    $db->StartTrans();

                    // Solo la CABECERA. Las líneas son el OTRO log del legacy
                    // —los ítems vendidos viven en una tabla aparte, con su
                    // propio reporte— y entran después con `adjuntarLineas()`,
                    // en bloque para todo el mes.
                    $txId = $this->insertTransaction($venta, $fecha, $outletId, $userId, $customerId);

                    // El asiento y su marca, ATÓMICOS. Sin esto, un worker que
                    // muere entre las dos escrituras deja la venta sin marcar y
                    // la corrida siguiente la asienta DE NUEVO: el comercio
                    // vería el doble de facturación en sus reportes.
                    EncomMigrationService::remember(
                        $this->companyId, 'sale_history', $legacyId, $txId, $this->jobId
                    );

                    $db->CompleteTrans();

                    $this->marcarSucio(['sales', 'item_sales', 'payments'], $fecha);
                    $this->counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $this->counts['failed']++;
                    $this->fail('sales_history', 'Venta ' . $legacyId . ': ' . $e->getMessage());
                }
            }

            // El SEGUNDO log del mes, una vez que sus cabeceras ya están
            // asentadas y mapeadas: sin eso no habría a qué pegarle cada línea.
            $this->adjuntarLineas($desdeTs, $hastaTs, $mesIni);
        }

        if ($sinClienteEnElLegacy > 0) {
            $this->note(
                $sinClienteEnElLegacy . ' venta(s) entraron sin cliente porque EL LEGACY NO TRAÍA NINGUNO (la celda '
                . 'viene vacía, que es lo normal en una venta de mostrador). No falta migrar nada: el asiento está '
                . 'completo y así se vendió.'
            );
        }

        if ($clienteSinMigrar !== []) {
            $nombres = array_keys($clienteSinMigrar);
            $this->note(
                count($nombres) . ' cliente(s) del legacy NO están migrados, así que sus ventas entraron sin cliente: '
                . implode(', ', array_slice($nombres, 0, 20))
                . (count($nombres) > 20 ? ' … y ' . (count($nombres) - 20) . ' más.' : '')
                . ' El asiento es correcto (el total y los ítems están); lo que falta es a quién se le vendió. '
                . 'Migrá los clientes y volvé a lanzar. No se inventan contactos.'
            );
        }

        $this->avisarLineas();
        $this->avisarSinCosto();

        if ($sinUsuario !== []) {
            $this->note(
                'Ventas NO importadas por usuario sin migrar: ' . implode(', ', array_slice(array_keys($sinUsuario), 0, 20))
                . '. Una transacción de Punto no puede existir sin el usuario que la hizo, y ponerle otro sería '
                . 'atribuirle ventas a quien no las hizo. Migrá los usuarios y volvé a lanzar.'
            );
        }

        $this->drenarRollups();

        return $this->counts;
    }

    // ═══════════════════════════════════════════════════════════════════
    // COMPRAS
    // ═══════════════════════════════════════════════════════════════════

    /** @return array{total:int,imported:int,skipped:int,failed:int,lines:int} */
    public function purchases(array $options): array
    {
        global $db;

        $this->resetCounts();
        [$desde, $hasta] = $this->range($options);

        $this->assertPrerequisitos('purchases_history');
        $this->ensurePartitions($desde, $hasta);

        $proveedorSinMigrar = [];

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'purchases_history')) {
                continue;
            }

            $desdeTs = $mesIni . ' 00:00:00';
            $hastaTs = $mesFin . ' 23:59:59';

            try {
                $compras = $this->source->purchasesHistory($desdeTs, $hastaTs);
            } catch (EncomExportTruncatedException $e) {
                throw $e;
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
            } catch (EncomExportTruncatedException $e) {
                // Un detalle capado deja compras con la mitad de sus líneas, y
                // eso no se distingue después de una compra que de verdad tenía
                // pocas. Aborta el dominio igual que un listado truncado.
                throw $e;
            } catch (\Throwable $e) {
                // Con la CAUSA, que es lo que faltaba: este camino tapaba un
                // `return []` mudo del cliente y 207 compras entraron sin una
                // sola línea sin que el job dijera nada.
                $this->note(
                    'No se pudo traer el detalle de las compras de ' . $mesIni . ': entran solo las cabeceras. '
                    . $e->getMessage()
                );
            }

            foreach ($compras as $compra) {
                $this->latir();

                $legacyId = trim((string) ($compra['ID'] ?? ''));
                $doc      = trim((string) ($compra['docNumber'] ?? ''));
                if ($legacyId === '') {
                    $legacyId = $doc;
                }
                if ($legacyId === '') {
                    continue;
                }

                $this->counts['total']++;

                if (EncomMigrationService::mapped($this->companyId, 'purchase_history', $legacyId) !== null) {
                    $this->counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($compra['date'] ?? '');
                if ($fecha === null) {
                    $this->counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': no se pudo leer la fecha.');
                    continue;
                }

                $outletId = $this->mapOf('outlet', $compra['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $this->counts['failed']++;
                    $this->fail(
                        'purchases_history',
                        'Compra ' . $legacyId . ': la sucursal "' . (string) ($compra['outlet'] ?? '')
                        . '" no está migrada.'
                    );
                    continue;
                }

                $userId = $this->mapOf('user', $compra['user'] ?? '', 'user_name');
                if ($userId === '') {
                    $this->counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': el usuario no está migrado.');
                    continue;
                }

                // El proveedor es un contacto como el cliente: si no está
                // migrado, la compra entra sin él (el gasto es correcto igual).
                // Y como con el cliente, se distingue "el legacy no traía
                // proveedor" de "el proveedor no está migrado": solo el segundo
                // tiene algo que hacer al respecto.
                $proveedorLegacy = $this->textoDeCelda($compra['supplier'] ?? '');
                $supplierId      = $proveedorLegacy !== ''
                    ? $this->mapOf('customer', $proveedorLegacy, 'supplier_name')
                    : '';
                if ($supplierId === '' && $proveedorLegacy !== '') {
                    $proveedorSinMigrar[$proveedorLegacy] = true;
                }

                $lineas = $lineasPorDoc[$doc] ?? [];

                try {
                    $db->StartTrans();

                    $txId = $this->insertPurchase($compra, $fecha, $outletId, $userId, $supplierId, $lineas);
                    $this->counts['lines'] += $this->insertLines(
                        $txId, $fecha, $lineas, $outletId, $userId, $legacyId,
                        $this->esCredito($compra) ? '4' : '1',
                        'purchases_history'
                    );

                    EncomMigrationService::remember(
                        $this->companyId, 'purchase_history', $legacyId, $txId, $this->jobId
                    );

                    $db->CompleteTrans();

                    $this->marcarSucio(['expenses'], $fecha);
                    $this->counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $this->counts['failed']++;
                    $this->fail('purchases_history', 'Compra ' . $legacyId . ': ' . $e->getMessage());
                }
            }
        }

        if ($proveedorSinMigrar !== []) {
            $nombres = array_keys($proveedorSinMigrar);
            $this->note(
                count($nombres) . ' proveedor(es) del legacy NO están migrados, así que sus compras entraron sin '
                . 'proveedor: ' . implode(', ', array_slice($nombres, 0, 20))
                . (count($nombres) > 20 ? ' … y ' . (count($nombres) - 20) . ' más.' : '')
                . ' El gasto es correcto; lo que falta es a quién se le compró.'
            );
        }

        // Una compra SIN una sola línea no es un error de fila —el total de la
        // compra es correcto igual— pero que NINGUNA haya traído líneas sí es
        // una señal, y es exactamente lo que pasó en la primera corrida real:
        // 207 compras, cero líneas, job en verde.
        if ($this->counts['imported'] > 0 && $this->counts['lines'] === 0) {
            $this->note(
                'Las ' . $this->counts['imported'] . ' compras entraron SIN una sola línea de detalle. Los totales '
                . 'están bien, pero no hay qué se compró: revisá en esta misma bitácora si el detalle del legacy no '
                . 'se pudo leer.'
            );
        }

        $this->avisarSinCosto();
        $this->drenarRollups();

        return $this->counts;
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

        $this->resetCounts();
        [$desde, $hasta] = $this->range($options);

        $this->assertPrerequisitos('expenses_history');

        foreach ($this->months($desde, $hasta) as [$mesIni, $mesFin]) {
            if ($this->periodoCerrado($mesIni, 'expenses_history')) {
                continue;
            }

            try {
                $filas = $this->source->expensesHistory($mesIni . ' 00:00:00', $mesFin . ' 23:59:59');
            } catch (EncomExportTruncatedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->fail('expenses_history', 'No se pudieron traer los movimientos de caja de ' . $mesIni . ': ' . $e->getMessage());
                continue;
            }

            foreach ($filas as $fila) {
                $this->latir();

                $legacyId = trim((string) ($fila['ID'] ?? ''));
                if ($legacyId === '') {
                    continue;
                }

                $this->counts['total']++;

                if (EncomMigrationService::mapped($this->companyId, 'expense_history', $legacyId) !== null) {
                    $this->counts['skipped']++;
                    continue;
                }

                $fecha = $this->fecha($fila['date'] ?? '');
                $monto = $fila['total'] ?? null;
                if ($fecha === null || !is_float($monto)) {
                    $this->counts['failed']++;
                    $this->fail('expenses_history', 'Movimiento de caja ' . $legacyId . ': fecha o monto ilegibles.');
                    continue;
                }

                $outletId = $this->mapOf('outlet', $fila['outlet'] ?? '', 'outlet_name');
                if ($outletId === '') {
                    $this->counts['failed']++;
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
                    $this->counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $this->counts['failed']++;
                    $this->fail('expenses_history', 'Movimiento de caja ' . $legacyId . ': ' . $e->getMessage());
                }
            }
        }

        $this->drenarRollups();

        return $this->counts;
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
    ): string {
        [$prefix, $number] = $this->documento((string) ($venta['docNumber'] ?? ''));

        $esCredito = $this->esCredito($venta);
        $anulada   = $this->esAnulada($venta);

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
            // Las unidades se completan cuando entra el log de ítems vendidos
            // (`adjuntarLineas()`): al asentar la cabecera todavía no se
            // leyeron sus líneas, porque son otro reporte.
            'transactionUnitsSold' => null,
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
        string $domain,
    ): int {
        $escritas = 0;

        foreach ($lineas as $linea) {
            $entro = $this->insertarLinea($linea, [
                'txId'        => $txId,
                'fecha'       => $fecha,
                'outletId'    => $outletId,
                'userId'      => $userId,
                'legacyDocId' => $legacyDocId,
                'typeStr'     => $typeStr,
                'domain'      => $domain,
            ]);

            if ($entro) {
                $escritas++;
            }
        }

        return $escritas;
    }

    /**
     * Escribe UNA línea de un documento histórico. Devuelve si entró.
     *
     * Es el ÚNICO escritor de `itemSold` del migrador, y por eso lo comparten
     * los dos caminos que tienen líneas: el detalle de compras (que viene con
     * su documento) y el log de ítems vendidos (que se pega por documento a
     * una venta ya asentada). Duplicarlo habría duplicado también el contrato
     * del COGS, que es justo lo que no puede divergir.
     */
    private function insertarLinea(array $linea, array $ctx): bool
    {
        $nombre = trim((string) ($linea['itemName'] ?? ''));
        $qty    = $linea['qty'] ?? null;

        if ($nombre === '' && trim((string) ($linea['legacyItemId'] ?? '')) === '') {
            return false;
        }

        $itemId = $this->resolverArticulo($linea);
        if ($itemId === '') {
            // Nunca se descarta una línea en silencio: sin ella el total del
            // documento deja de cerrar contra la suma de sus ítems.
            //
            // El dominio viene por contexto: escrito fijo, el error de una
            // línea de COMPRA se reportaba bajo `sales_history` y mandaba a
            // revisar las ventas.
            $this->fail(
                (string) $ctx['domain'],
                'Documento ' . (string) $ctx['legacyDocId'] . ': no se pudo resolver el artículo "' . $nombre . '".'
            );
            return false;
        }

        $typeStr = (string) $ctx['typeStr'];

        $total = $linea['total'] ?? null;
        if ($total === null && $qty !== null && ($linea['price'] ?? null) !== null) {
            $total = (float) $qty * (float) $linea['price'];
        }

        $records = [
            // El total viene CON IVA INCLUIDO y así se guarda: verificado
            // contra dos filas del sistema vivo (21.000/11 = 1.909 y 6.000/11
            // = 545, que son exactamente los IVA de esas filas). No se le
            // descuenta el impuesto — además es la convención del proyecto.
            'itemSoldTotal'       => (float) ($total ?? 0),
            'itemSoldTax'         => $linea['tax'] ?? null,
            'itemSoldUnits'       => $qty,
            'itemSoldDate'        => (string) $ctx['fecha'],
            'itemSoldDescription' => $this->recortar($nombre, 255) ?: null,
            'itemId'              => $itemId,
            'transactionId'       => (string) $ctx['txId'],
            'userId'              => (string) $ctx['userId'],
            'companyId'           => $this->companyId,
            'outletId'            => (string) $ctx['outletId'],
        ];

        // Columnas que el log de ítems vendidos trae y que tienen su par
        // exacto en `itemSold`. Se escriben solo si vinieron: el detalle de
        // compras no las tiene, y un 0 inventado es un dato falso.
        if (is_numeric($linea['discount'] ?? null)) {
            $records['itemSoldDiscount'] = \flipOnReturn($typeStr, (float) $linea['discount']);
        }
        if (is_numeric($linea['comission'] ?? null)) {
            $records['itemSoldComission'] = \flipOnReturn($typeStr, (float) $linea['comission']);
        }

        // La categoría se congela desde el ARTÍCULO ya migrado y no desde el
        // texto del legacy: `itemSoldCategory` es un FK a `taxonomy`, no un
        // nombre, y esta es exactamente la regla de `SaleService` (D8 de
        // context/48 — un rollup por categoría no puede mirar el catálogo de
        // hoy).
        $categoria = $this->categoryByItemId[$itemId] ?? null;
        if ($categoria !== null && $categoria !== '') {
            $records['itemSoldCategory'] = $categoria;
        }

        // ── COGS: el contrato es el de `SaleService` ───────────────────────
        // La columna guarda el costo UNITARIO (no el de la línea): es lo que
        // devuelve `resolveUnitCOGS()` y lo que persiste
        // `persistItemsAndStock()`, sin multiplicar por la cantidad.
        //
        // La FUENTE preferida es el costo REAL con el que se vendió, que trae
        // el log de ítems vendidos. `item.itemCost` —el costo de HOY— queda
        // como respaldo para cuando el log no lo trae: es una aproximación y
        // está declarada como tal (context/77 §17.9).
        //
        // Se OMITE cuando no se sabe, en vez de escribirse null: pasa por
        // `flipOnReturn()`, que ante un valor no válido devuelve **0**, y un 0
        // se lee como "costó nada" → margen 100% para siempre en ese artículo.
        // Mismo criterio que la apertura de stock (§16.3).
        //
        // `flipOnReturn` es no-op para los tipos que importa el histórico (0/3
        // venta, 1/4 compra) y solo invierte el signo en la devolución (tipo
        // 6). Se llama igual para que el contrato quede literal.
        $cogs = $this->cogsUnitario($linea) ?? $this->costFor($itemId);
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
            throw new \RuntimeException('no se pudo insertar una línea del documento ' . (string) $ctx['legacyDocId']);
        }

        return true;
    }

    /**
     * Costo UNITARIO de una línea del log de ítems vendidos, o null.
     *
     * ── El único lugar donde vive la conversión ──────────────────────────
     * El log trae el costo de la LÍNEA, no el unitario. Está VERIFICADO con
     * dos filas del sistema vivo, despejándolo con la columna `Utilidad`:
     *
     *   · 21.000 − 11.400 = 9.600 = Utilidad, con Cantidad 3 ⇒ unitario 3.800
     *   ·  6.000 −  2.800 = 3.200 = Utilidad, con Cantidad 1 ⇒ unitario 2.800
     *
     * Y `itemSoldCOGS` guarda el UNITARIO. Escribir el costo de la línea tal
     * cual habría inflado el costo —y hundido el margen— por un factor igual a
     * la cantidad, en silencio y para siempre.
     *
     * Sin cantidad no hay conversión posible, y ahí devuelve "no sé" en vez de
     * un número: el caller decide, y lo que NO puede pasar es un 0.
     */
    private function cogsUnitario(array $linea): ?float
    {
        $costoDeLaLinea = $linea['cost'] ?? null;
        $qty            = $linea['qty'] ?? null;

        if (!is_numeric($costoDeLaLinea) || !is_numeric($qty) || (float) $qty === 0.0) {
            return null;
        }

        return round((float) $costoDeLaLinea / (float) $qty, 4);
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

        // ── El SKU manda sobre el nombre ─────────────────────────────────
        // El log de ítems vendidos trae `Código/SKU`, y en un comercio que
        // cargó su catálogo a mano —el caso del primer cliente real— los
        // nombres NO coinciden (mayúsculas, abreviaturas, faltas) y los
        // códigos sí. Emparejar por nombre primero le erraba o mandaba al
        // artículo histórico lo que en realidad existía en el catálogo.
        $sku = $this->normalizar((string) ($linea['sku'] ?? ''));
        if ($sku !== '' && $sku !== '-' && isset($this->itemBySku[$sku])) {
            return $this->itemBySku[$sku];
        }

        $nombre = trim((string) ($linea['itemName'] ?? ''));
        $clave  = $this->claveDeArticulo($nombre);

        if ($clave !== '' && isset($this->itemByName[$clave])) {
            return $this->itemByName[$clave];
        }

        if ($nombre === '') {
            return '';
        }

        // Ya se creó el histórico de este nombre en una corrida anterior.
        $previo = EncomMigrationService::mapped($this->companyId, 'item_history', $this->claveMapa($clave));
        if ($previo !== null) {
            $this->lineasEnHistorico++;
            return $this->itemByName[$clave] = $previo;
        }

        $this->lineasEnHistorico++;

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
            'SELECT itemId, itemName, itemSKU, itemCost, itemStatus, categoryId FROM item WHERE companyId = ?',
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

                    // La categoría del ítem, para congelarla en la línea: es
                    // exactamente lo que hace `SaleService` (D8 de context/48).
                    $cat = $f['categoryId'] ?? $f['categoryid'] ?? null;
                    $this->categoryByItemId[$id] = ($cat !== null && trim((string) $cat) !== '')
                        ? (string) $cat
                        : null;

                    $activo = (int) ($f['itemStatus'] ?? $f['itemstatus'] ?? 0) === 1;
                    if ($activo) {
                        $sku    = $this->normalizar((string) ($f['itemSKU'] ?? $f['itemsku'] ?? ''));
                        $nombre = $this->claveDeArticulo((string) ($f['itemName'] ?? $f['itemname'] ?? ''));
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
    // Prerequisitos del dominio
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Que exista al menos UNA sucursal a la que colgar un asiento. Se evalúa
     * una sola vez, antes de iterar y antes de pedirle nada al legacy.
     *
     * ── Por qué existe: incidente del 2026-09-11 ─────────────────────────
     * Un job real terminó con **512 errores**, todos de la forma "Venta X: la
     * sucursal OLIVA no está migrada". La causa verdadera eran cuatro errores
     * de `config` que quedaron sepultados: el export pedía `/fetchs` contra el
     * panel y recibía 404, así que no se mapeó ni una sucursal. Quien miraba
     * el job veía 512 veces el SÍNTOMA y no tenía cómo llegar a la causa.
     *
     * ── Por qué un chequeo previo y no un corte "a los N errores iguales" ─
     * Se eligió el prerequisito por tres razones:
     *
     *   1. **Nombra la causa, no el síntoma.** Un corte por repetición sigue
     *      diciendo "la sucursal no está migrada" —la frase que manda a mirar
     *      el lugar equivocado—, solo que menos veces. Acá el mensaje dice que
     *      falta el prerequisito y a qué dominio hay que ir a mirar.
     *   2. **Se evalúa antes de gastar la red.** Un corte por N errores ya
     *      pagó N requests paceadas a 60/min contra el legacy (y en ventas es
     *      una request POR VENTA) para terminar sabiendo lo que se podía saber
     *      con dos `count(*)` locales.
     *   3. **No necesita un umbral.** "N errores de la misma causa" obliga a
     *      elegir N y a clasificar mensajes por parecido, que es una heurística
     *      que se desajusta sola. La condición real es binaria: sin sucursales,
     *      NINGÚN asiento puede entrar.
     *
     * Esto NO reemplaza al error por fila: una sucursal suelta que no resuelve
     * —el comercio tiene tres y el legacy nombra una cuarta— sigue siendo un
     * error de ESA venta, que es información legítima. Lo que se corta es el
     * caso en que el dominio entero era imposible desde antes de empezar.
     *
     * Se cuentan las dos fuentes con las que `mapOf()` resuelve una sucursal:
     * el mapa de la migración y las sucursales del destino (que es contra lo
     * que busca por nombre). Con cualquiera de las dos no vacía, el dominio
     * corre normal.
     */
    private function assertPrerequisitos(string $domain): void
    {
        $enDestino = \ncmExecute(
            'SELECT count(*) AS n FROM outlet WHERE companyId = ?',
            [$this->companyId]
        );
        $mapeadas = \ncmExecute(
            "SELECT count(*) AS n FROM migration_map WHERE companyid = ? AND domain = 'outlet'",
            [$this->companyId]
        );

        if ((int) ($enDestino['n'] ?? 0) > 0 || (int) ($mapeadas['n'] ?? 0) > 0) {
            return;
        }

        throw new EncomMigrationException(
            'No se importó nada: la empresa destino no tiene NINGUNA sucursal, y un asiento histórico '
            . 'necesita una (es un dato obligatorio de la transacción). La causa está en el dominio '
            . '"config" —miralo en esta misma lista: si falló, ahí está el error de verdad—. '
            . 'Migrá la configuración y volvé a lanzar ' . $domain . '.',
            422
        );
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

    /**
     * Pega el LOG DE ÍTEMS VENDIDOS del mes a las ventas ya asentadas.
     *
     * ── Por qué es un segundo log y no "el detalle de cada venta" ────────
     * En el legacy los ítems vendidos viven en una tabla APARTE de las
     * transacciones y tienen su propio reporte en bloque. El histórico son
     * entonces DOS LOGS INDEPENDIENTES, y eso cambia el costo por dos órdenes
     * de magnitud: la vía anterior pedía el form de edición de cada venta —una
     * request por venta, 6.927 en el primer cliente real, más de dos horas
     * paceadas contra el servidor donde el comercio factura—. Este log entero
     * entra en unas pocas páginas de 1000.
     *
     * ── Cómo se pega cada línea, y qué pasa si no se puede ───────────────
     * Por el `#Documento`, el mismo patrón que ya usan las compras. Una línea
     * cuya venta NO está importada no se asienta NUNCA: `itemsold.transactionid`
     * es NOT NULL con FK, y las dos salidas fáciles están mal —inventarle una
     * transacción falsea la facturación del período, y descartarla en silencio
     * repite el bug que este trabajo vino a cerrar—. Se cuentan y se informan.
     *
     * Corre DESPUÉS de las cabeceras del mes porque necesita que estén
     * mapeadas para encontrarlas.
     */
    private function adjuntarLineas(string $desdeTs, string $hastaTs, string $mesIni): void
    {
        global $db;

        try {
            $filas = $this->source->itemsSoldHistory($desdeTs, $hastaTs);
        } catch (EncomExportTruncatedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail(
                'sales_history',
                'No se pudo traer el log de ítems vendidos de ' . $mesIni . ': ' . $e->getMessage()
                . ' Las ventas de ese mes quedan con sus totales, pero sin detalle: no hay ranking de productos '
                . 'ni margen para ese período.'
            );
            return;
        }

        if ($filas === []) {
            return;
        }

        $this->cargarTransaccionesImportadas();

        $porVenta = [];
        foreach ($filas as $fila) {
            $this->latir();

            $clave = $this->claveDeLinea($fila);

            // Idempotencia POR LÍNEA: una corrida que se cortó a la mitad —o
            // que se relanza para completar un mes— no vuelve a asentar lo que
            // ya entró. Sin esto, el comercio vería el doble de unidades
            // vendidas en todos sus reportes.
            if (EncomMigrationService::mapped($this->companyId, 'sale_line_history', $clave) !== null) {
                continue;
            }

            $txId = $this->transaccionDeLaLinea($fila);
            if ($txId === '') {
                continue;   // ya quedó contada como huérfana o como ambigua
            }

            $porVenta[$txId][] = ['clave' => $clave, 'linea' => $fila];
        }

        foreach ($porVenta as $txId => $items) {
            $tx = $this->txPorId[$txId] ?? null;
            if ($tx === null) {
                continue;
            }

            $doc = (string) ($items[0]['linea']['docNumber'] ?? '');

            try {
                $db->StartTrans();

                $escritas = 0;
                $unidades = 0.0;

                foreach ($items as $item) {
                    $entro = $this->insertarLinea($item['linea'], [
                        'txId'        => $txId,
                        // La fecha viene POR LÍNEA en este log; la de la
                        // cabecera es el respaldo.
                        'fecha'       => $this->fecha($item['linea']['date'] ?? '') ?? $tx['date'],
                        'outletId'    => $tx['outletId'],
                        'userId'      => $tx['userId'],
                        'legacyDocId' => $doc,
                        'typeStr'     => $tx['typeStr'],
                        'domain'      => 'sales_history',
                    ]);

                    if (!$entro) {
                        continue;
                    }

                    // La marca va en la MISMA transacción que la línea, por lo
                    // mismo que la cabecera (§17.6).
                    EncomMigrationService::remember(
                        $this->companyId, 'sale_line_history', $item['clave'], $txId, $this->jobId
                    );

                    $escritas++;
                    $unidades += (float) ($item['linea']['qty'] ?? 0);
                }

                // Las unidades de la venta se completan acá: cuando entró la
                // cabecera, sus líneas todavía no se habían leído.
                if ($escritas > 0) {
                    \ncmExecute(
                        'UPDATE transaction
                            SET transactionUnitsSold = COALESCE(transactionUnitsSold, 0) + ?
                          WHERE transactionId = ? AND companyId = ?',
                        [$unidades, $txId, $this->companyId]
                    );
                }

                $db->CompleteTrans();

                $this->counts['lines'] += $escritas;
                $this->marcarSucio(['sales', 'item_sales'], (string) $tx['date']);
            } catch (\Throwable $e) {
                $db->FailTrans();
                $db->CompleteTrans();
                $this->fail(
                    'sales_history',
                    'Documento ' . $doc . ': no se pudieron asentar sus líneas: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Índice de las ventas históricas ya asentadas, por sus tres nombres
     * posibles. Una sola consulta por corrida.
     */
    private function cargarTransaccionesImportadas(): void
    {
        if ($this->txPorId !== null) {
            return;
        }

        $this->txPorId = [];

        $rs = \ncmExecute(
            "SELECT m.legacyid AS legacyid, t.transactionid, t.transactiondate, t.outletid,
                    t.userid, t.transactiontype, t.invoiceprefix, t.invoiceno
               FROM migration_map m
               JOIN transaction t ON t.transactionid = m.puntoid::uuid
              WHERE m.companyid = ? AND m.domain = 'sale_history'",
            [$this->companyId],
            false,
            true
        );

        if ($rs === false || !is_object($rs)) {
            return;
        }

        while (!$rs->EOF) {
            $f    = $rs->fields;
            $txId = (string) ($f['transactionid'] ?? '');

            if ($txId !== '') {
                $this->txPorId[$txId] = [
                    'date'     => (string) ($f['transactiondate'] ?? ''),
                    'outletId' => (string) ($f['outletid'] ?? ''),
                    'userId'   => (string) ($f['userid'] ?? ''),
                    'typeStr'  => (string) ((int) ($f['transactiontype'] ?? 0)),
                ];

                $legacy = trim((string) ($f['legacyid'] ?? ''));
                if ($legacy !== '') {
                    $this->txPorLegacy[$legacy] = $txId;
                }

                $numero  = (int) ($f['invoiceno'] ?? 0);
                $prefijo = trim((string) ($f['invoiceprefix'] ?? ''));
                if ($numero > 0) {
                    $this->txPorNumero[$numero][] = $txId;
                    if ($prefijo !== '') {
                        $this->txPorDocumento[$prefijo . '-' . $numero] = $txId;
                    }
                }
            }

            $rs->MoveNext();
        }

        $rs->Close();
    }

    /**
     * A qué venta pertenece una fila del log de ítems. '' si no se puede saber.
     *
     * El número SUELTO (`6103`, que es como lo trae el sistema vivo) identifica
     * bien mientras haya una sola venta con ese número. Si hay varias —dos
     * cajas pueden repetir número bajo timbrados distintos— NO se elige: pegar
     * la línea a la venta equivocada le mueve el margen a dos comprobantes y no
     * queda rastro de que pasó.
     */
    private function transaccionDeLaLinea(array $fila): string
    {
        $ref = trim((string) ($fila['saleRef'] ?? ''));
        if ($ref !== '' && isset($this->txPorLegacy[$ref])) {
            return $this->txPorLegacy[$ref];
        }

        $doc = trim((string) ($fila['docNumber'] ?? ''));
        if ($doc === '') {
            $this->lineasHuerfanas['(sin documento)'] = true;
            return '';
        }

        [$prefijo, $numero] = $this->documento($doc);

        if ($prefijo !== null && $numero !== null && isset($this->txPorDocumento[$prefijo . '-' . $numero])) {
            return $this->txPorDocumento[$prefijo . '-' . $numero];
        }

        if ($numero !== null && isset($this->txPorNumero[$numero])) {
            $candidatas = array_values(array_unique($this->txPorNumero[$numero]));
            if (count($candidatas) === 1) {
                return $candidatas[0];
            }

            $this->lineasAmbiguas[$doc] = true;
            return '';
        }

        $this->lineasHuerfanas[$doc] = true;
        return '';
    }

    /**
     * Clave de idempotencia de una línea del log.
     *
     * El id de la fila es lo mejor. Sin él se arma una clave compuesta y
     * estable: si dos líneas idénticas del mismo documento colapsaran, el
     * riesgo es no volver a asentar una repetida — el lado correcto para
     * equivocarse, porque el otro duplica unidades vendidas.
     */
    private function claveDeLinea(array $fila): string
    {
        $id = trim((string) ($fila['ID'] ?? ''));
        if ($id !== '') {
            return $this->claveMapa('il:' . $id);
        }

        return $this->claveMapa('ilc:' . sha1(implode('|', [
            (string) ($fila['docNumber'] ?? ''),
            (string) ($fila['itemName'] ?? ''),
            (string) ($fila['qty'] ?? ''),
            (string) ($fila['total'] ?? ''),
        ])));
    }

    /** Lo que hay que saber del log de ítems después de importarlo. */
    private function avisarLineas(): void
    {
        if ($this->lineasHuerfanas !== []) {
            $docs = array_keys($this->lineasHuerfanas);
            $this->note(
                'Hay líneas vendidas que NO se pudieron pegar a ninguna venta importada, así que no se asentaron ('
                . count($docs) . ' documento(s)): ' . implode(', ', array_slice($docs, 0, 20))
                . (count($docs) > 20 ? ' … y ' . (count($docs) - 20) . ' más.' : '')
                . ' Pasa cuando esa venta no entró —por ejemplo, su usuario no está migrado— o quedó fuera del '
                . 'rango. No se les inventa una transacción: falsearía la facturación del período.'
            );
        }

        if ($this->lineasAmbiguas !== []) {
            $docs = array_keys($this->lineasAmbiguas);
            $this->note(
                'Hay líneas cuyo número de documento corresponde a MÁS DE UNA venta importada (pasa cuando dos '
                . 'cajas repiten numeración bajo timbrados distintos), así que no se asentaron: '
                . implode(', ', array_slice($docs, 0, 20))
                . (count($docs) > 20 ? ' … y ' . (count($docs) - 20) . ' más.' : '')
                . ' Elegir una al azar le movería el margen a un comprobante que no es.'
            );
        }

        if ($this->lineasEnHistorico > 0) {
            $this->note(
                $this->lineasEnHistorico . ' línea(s) quedaron colgadas de un artículo HISTÓRICO archivado porque '
                . 'su artículo no existe en el catálogo migrado (el sistema anterior nombra sus artículos distinto, '
                . 'por ejemplo con sufijos de stock). Los totales cierran, pero esas unidades no suman al ranking '
                . 'del artículo real.'
            );
        }

        $this->lineasHuerfanas  = [];
        $this->lineasAmbiguas   = [];
        $this->lineasEnHistorico = 0;
    }

    /**
     * Clave con la que se compara el NOMBRE de un artículo.
     *
     * Además de normalizar, saca el sufijo de stock con el que el legacy
     * bautiza sus artículos ("Empanada de Choclo fritas-stock", "Gaseosa de
     * 250-Stock"). Es UNA regla y acotada al final del nombre, no una lista de
     * reglas de limpieza: el catálogo que el comercio cargó a mano en Punto no
     * arrastra ese sufijo, y sin sacarlo casi todas las líneas caerían en el
     * artículo histórico y el ranking quedaría partido en dos mundos.
     *
     * Solo afecta la COMPARACIÓN. Lo que se guarda en la línea sigue siendo el
     * nombre tal como vino, y lo que no matchea igual queda nombrado en la
     * bitácora en vez de forzarse.
     */
    private function claveDeArticulo(string $nombre): string
    {
        $clave = $this->normalizar($nombre);
        $clave = preg_replace('/[\s\-]*stock$/u', '', $clave) ?? $clave;

        return trim($clave);
    }

    /** Deja los conteos en cero al empezar un dominio. */
    private function resetCounts(): void
    {
        $this->counts       = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'lines' => 0];
        $this->ultimoLatido = microtime(true);
    }

    /**
     * Latido: avisa cuánto lleva hecho, y con eso que sigue vivo.
     *
     * Es por TIEMPO y no cada N filas porque el ritmo cambia por dominio: una
     * venta cuesta una request paceada (1,1 s) y un movimiento de caja cuesta
     * un INSERT. "Cada 25 filas" serían 30 segundos en un caso y milisegundos
     * en el otro.
     *
     * El importador no sabe qué es un `migration_job` y no tiene por qué: lo
     * único que hace es llamar al callback con sus conteos.
     */
    private function latir(): void
    {
        if ($this->heartbeat === null) {
            return;
        }

        $ahora = microtime(true);
        if (($ahora - $this->ultimoLatido) < self::LATIDO_SEGUNDOS) {
            return;
        }
        $this->ultimoLatido = $ahora;

        ($this->heartbeat)($this->counts);
    }

    /**
     * Texto real de una celda del legacy: '' cuando no había dato.
     *
     * El legacy pinta "-" en las celdas vacías. Tomarlo como nombre hace
     * buscar un cliente llamado "-" y contar como "no mapeó" lo que en
     * realidad era "no había" — que es la confusión que el aviso del job
     * arrastraba.
     */
    private function textoDeCelda(mixed $v): string
    {
        $s = trim((string) ($v ?? ''));
        return ($s === '-' || $s === '—') ? '' : $s;
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
