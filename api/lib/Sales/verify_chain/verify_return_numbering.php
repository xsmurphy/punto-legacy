<?php

declare(strict_types=1);

/**
 * verify_return_numbering.php — arnés chico que demuestra, sin mockear nada,
 * que la devolución de venta (transactionType=6, `ReturnService::create()`)
 * ahora tiene correlativo propio (context/modules/17-numeracion.md §7,
 * context/40-anulacion-y-nota-credito.md).
 *
 * Antes de este fix, `ReturnService::create()` insertaba la transacción SIN
 * `invoiceno` (columna ausente del INSERT) y sin llamar a
 * `DocumentNumber::allocate()` — toda devolución salía con invoiceNo=NULL,
 * indistinguible de cualquier otra.
 *
 * El fix: `DocumentNumber::allocate('nota_credito', SCOPE_OUTLET, $outletId,
 * $companyId)` dentro de la misma transacción que ya abre `create()`.
 * Scope OUTLET (no REGISTER, a diferencia de `recibo`): `returns.php` acepta
 * llamadas desde 'panel' sin caja abierta (`registerId` puede llegar NULL),
 * mientras que `outletId` es obligatorio en el endpoint — mismo criterio que
 * `remision` (context/37 D2, context/42-remision.md).
 *
 * Bug adicional encontrado y corregido en el camino (pre-existente, no
 * relacionado con numeración): la query interna de `create()` leía columnas
 * `i.itemhasstock`/`i.itemlocationid` que NUNCA existieron en `item`
 * (Postgres las rechaza con "column ... does not exist" ANTES de mirar
 * datos) — `ReturnService::create()` no podía ejecutarse con NINGÚN item,
 * nunca. Se corrigió a `i.itemtrackinventory`/`i.locationid` (mismas
 * columnas que usan `ItemService`, `StockTransferService`,
 * `InventoryCountService`, `SaleService.php:1847/1870`) porque bloqueaba
 * ejercitar el código que esta tarea numera.
 *
 * Tercer bug pre-existente encontrado en la misma corrida (también
 * desbloqueante): `create()` citaba la tabla como `"itemSold"` (comillas,
 * case-sensitive). La tabla se creó SIN comillas (`CREATE TABLE itemSold`,
 * db-schema-postgres.sql), así que Postgres la guarda plegada a minúsculas
 * (`itemsold`) — citarla como `"itemSold"` exige coincidencia EXACTA y
 * Postgres la rechaza ("relation itemSold does not exist"). Se corrigió
 * quitando las comillas en las 3 ocurrencias de `create()` (mismo criterio
 * que `SaleService::AutoExecute('itemSold', ...)`, que nunca cita el
 * nombre) — la TERCERA (rama `alreadyReturned`, `$returnIds !== []`) la
 * atrapó recién el code-reviewer: ninguno de los casos 1-4 originales la
 * ejercitaba, por eso el caso 5 de abajo existe. El mismo patrón sigue
 * roto, sin tocar, en `PurchaseCreditNoteService.php` (3 usos) y
 * `EInvoiceService.php:1767` (1 uso) — flageado en el reporte final.
 *
 * Casos:
 *   1. Devolución A, con caja (registerId=PY_REGISTER, simula pos-app) sobre
 *      la venta A → invoiceNo > 0.
 *   2. Devolución B, SIN caja (registerId=null, simula panel sin caja
 *      abierta) sobre la venta B → invoiceNo DISTINTO y CORRELATIVO
 *      (exactamente +1 sobre el caso 1) — el caso CENTRAL: dos devoluciones
 *      consecutivas, UNA con caja y OTRA sin caja, comparten la MISMA
 *      secuencia porque el scope es outlet, no register. Antes de este fix
 *      ninguna de las dos tenía número.
 *   3. `document_sequence` (companyId, doctype='nota_credito',
 *      scopetype='outlet', scopeid=PY_OUTLET) quedó con
 *      nextnumber = invoiceNo(B) + 1 — la secuencia es la fuente de verdad.
 *   4. La fila `transaction` de la devolución B persiste `registerid=NULL`
 *      — el scope outlet no fabrica un registerId que el caller no mandó.
 *   5. Segunda devolución PARCIAL sobre la MISMA venta A (que vendió qty=2,
 *      el caso 1 ya devolvió 1) → ejercita la rama `alreadyReturned`
 *      (`$returnIds !== []`) y numera invoiceNo=B+1, correlativo sobre B.
 *      5b. Una TERCERA devolución sobre la venta A (ya devuelta por
 *      completo) se rechaza — guard D1 (context/40) contra la SUMA de
 *      devoluciones previas, no solo la última.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Item NO stockeable (itemtrackinventory=FALSE) — evita ejercitar
// Inventory::manageStock en este arnés chico, que ya se cubre en
// verify_realtime.php con items stockeables dedicados.
$PY_ITEM = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // VERIFY-10-INC, precio 11000

require_once __DIR__ . '/../../services/ReturnService.php';

use Punto\Api\Services\ReturnService;

global $db;
$failures = [];

// ── Reset idempotente (Postgres reusado entre corridas locales) — mismo
//    criterio que verify_receipt_numbering.php: las devoluciones (type=6)
//    y sus links se identifican vía transaction_link.originId apuntando a
//    las ventas 'VERIFY-RETURN-%' de este arnés (no llevan UID propio con
//    ese prefijo — ReturnService no setea transactionUID). Orden obligatorio
//    por el FK transaction_link.derivedid → transaction: primero se borra
//    el link (y se captura a qué devolución apuntaba), recién ahí la fila
//    `transaction` de la devolución. ─────────────────────────────────────
$orphanReturns = ncmExecute(
    "DELETE FROM transaction_link
      WHERE companyId = ? AND kind = 'return'
        AND originId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-RETURN-%' AND companyId = ?)
      RETURNING derivedid",
    [$PY_COMPANY, $PY_COMPANY], false, false, true
);
$orphanReturnIds = is_array($orphanReturns) ? array_column($orphanReturns, 'derivedid') : [];
if ($orphanReturnIds !== []) {
    $ph = implode(',', array_fill(0, count($orphanReturnIds), '?'));
    ncmExecute("DELETE FROM itemSold WHERE transactionid IN ($ph)", $orphanReturnIds);
    ncmExecute("DELETE FROM transaction WHERE transactionid IN ($ph)", $orphanReturnIds);
}
ncmExecute(
    "DELETE FROM itemSold WHERE transactionid IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-RETURN-%' AND companyId = ?)",
    [$PY_COMPANY]
);
ncmExecute("DELETE FROM transaction WHERE transactionUID LIKE 'VERIFY-RETURN-%' AND companyId = ?", [$PY_COMPANY]);

/** Inserta una venta pagada (type=0) con UNA línea de itemSold, lista para devolver. */
function verifyMakeReturnableSale(
    string $companyId, string $outletId, string $registerId, string $userId,
    string $itemId, string $uid, float $unitPrice, float $qty
): string {
    $row = ncmExecute(
        'INSERT INTO transaction
            (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, registerid, userid, outletid, companyid)
         VALUES (now(), ?, 0, TRUE, 1, ?, ?, ?, ?, ?)
         RETURNING transactionid',
        [$unitPrice * $qty, $uid, $registerId, $userId, $outletId, $companyId]
    );
    $transactionId = (string) ($row['transactionid'] ?? '');
    if ($transactionId === '') {
        return '';
    }
    ncmExecute(
        'INSERT INTO itemSold (itemid, transactionid, itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldcogs, itemsolddate)
         VALUES (?, ?, ?, ?, 0, 0, now())',
        [$itemId, $transactionId, $qty, $unitPrice * $qty]
    );
    return $transactionId;
}

try {
    $saleA = verifyMakeReturnableSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_ITEM, 'VERIFY-RETURN-SALE-A', 11000, 2);
    $saleB = verifyMakeReturnableSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_ITEM, 'VERIFY-RETURN-SALE-B', 11000, 2);

    if ($saleA === '' || $saleB === '') {
        $failures[] = 'Setup: no se pudieron crear las ventas de prueba (saleA/saleB)';
    } else {
        $svc = new ReturnService();

        // ── Caso 1: devolución A, CON caja (pos-app) ────────────────────
        $returnA = $svc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, $PY_REGISTER, $saleA, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', 'VERIFY-RETURN A');
        $rowA = ncmExecute('SELECT invoiceno, registerid FROM transaction WHERE transactionid = ?', [$returnA['id']]);
        $invoiceNoA = (int) ($rowA['invoiceno'] ?? 0);

        if ($invoiceNoA < 1) {
            $failures[] = "Caso 1: devolución A esperaba invoiceNo > 0, llegó {$invoiceNoA} — este es exactamente el bug (la devolución salía sin número)";
        } else {
            echo "[verify_return_numbering] OK caso 1: devolución A (con caja) tiene invoiceNo={$invoiceNoA} (> 0, antes salía NULL)\n";
        }

        // ── Caso 2 (CENTRAL): devolución B, SIN caja (panel, registerId=null) ──
        $returnB = $svc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, null, $saleB, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', 'VERIFY-RETURN B');
        $rowB = ncmExecute('SELECT invoiceno, registerid FROM transaction WHERE transactionid = ?', [$returnB['id']]);
        $invoiceNoB = (int) ($rowB['invoiceno'] ?? 0);

        if ($invoiceNoB === $invoiceNoA) {
            $failures[] = "Caso 2: devolución B esperaba un invoiceNo DISTINTO de la devolución A ({$invoiceNoA}), llegó el MISMO";
        } elseif ($invoiceNoB !== $invoiceNoA + 1) {
            $failures[] = "Caso 2: devolución B esperaba invoiceNo={$invoiceNoA}+1=" . ($invoiceNoA + 1) . ", llegó {$invoiceNoB} — no es correlativo (¿hueco o colisión de scope?)";
        } else {
            echo "[verify_return_numbering] OK caso 2 (CENTRAL): dos devoluciones consecutivas (con caja y sin caja) tienen invoiceNo distintos y correlativos ({$invoiceNoA} → {$invoiceNoB})\n";
        }

        // ── Caso 3: document_sequence es la fuente de verdad ────────────
        $seqRow = ncmExecute(
            'SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = ? AND scopetype = ? AND scopeid = ?',
            [$PY_COMPANY, 'nota_credito', 'outlet', $PY_OUTLET]
        );
        $nextNumber = (int) ($seqRow['nextnumber'] ?? 0);
        if ($nextNumber !== $invoiceNoB + 1) {
            $failures[] = "Caso 3: document_sequence.nextnumber esperaba " . ($invoiceNoB + 1) . ' (siguiente a emitir tras la devolución B), llegó ' . $nextNumber;
        } else {
            echo "[verify_return_numbering] OK caso 3: document_sequence (doctype=nota_credito, scope=outlet) avanzó a nextnumber={$nextNumber} — la secuencia es la fuente de verdad\n";
        }

        // ── Caso 4: scope outlet no fabrica un registerId inexistente ───
        // Acceso directo (SIN `??`): `??` trata "clave ausente" y "valor
        // NULL real" como lo mismo, y acá lo que se quiere distinguir es
        // exactamente que la columna persistió NULL de verdad.
        $registerIdB = $rowB['registerid'];
        if ($registerIdB !== null) {
            $failures[] = "Caso 4: devolución B (llamada sin caja) esperaba registerid=NULL persistido, llegó " . var_export($registerIdB, true) . ' — el scope outlet no debería depender ni fabricar un registerId';
        } else {
            echo "[verify_return_numbering] OK caso 4: la devolución sin caja persiste registerid=NULL — el scope outlet no fabrica un dato que el caller no mandó\n";
        }

        // ── Caso 5: SEGUNDA devolución parcial sobre la MISMA venta (saleA
        //    vendió qty=2, el caso 1 ya devolvió 1) — ejercita la rama
        //    `$returnIds !== []` de create() (lee `alreadyReturned` sumando
        //    las devoluciones previas). Esta rama tenía SU PROPIA referencia
        //    entre comillas a `"itemSold"` (bug hermano del corregido en el
        //    SELECT/INSERT principal, atrapado en code review porque ninguno
        //    de los casos 1-4 la ejercitaba: cada venta recibía una sola
        //    devolución, `$returnIds` siempre era `[]`). Sin la unidad
        //    restante disponible (qty=1) tampoco se podría probar el guard
        //    de "qty ya devuelta" contra la SUMA de NC previas (D1,
        //    context/40).
        $returnA2 = $svc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, $PY_REGISTER, $saleA, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', 'VERIFY-RETURN A2 (segunda devolución de la misma venta)');
        $rowA2 = ncmExecute('SELECT invoiceno FROM transaction WHERE transactionid = ?', [$returnA2['id']]);
        $invoiceNoA2 = (int) ($rowA2['invoiceno'] ?? 0);

        if ($invoiceNoA2 !== $invoiceNoB + 1) {
            $failures[] = "Caso 5: segunda devolución sobre saleA esperaba invoiceNo=" . ($invoiceNoB + 1) . " (continúa la MISMA secuencia outlet), llegó {$invoiceNoA2} — o la rama alreadyReturned (\$returnIds !== []) sigue rota (relation itemSold does not exist), o la numeración se pisó";
        } else {
            echo "[verify_return_numbering] OK caso 5: segunda devolución parcial sobre la misma venta (rama alreadyReturned, \$returnIds !== []) ejecuta sin error y numera invoiceNo={$invoiceNoA2}, correlativo sobre la anterior\n";
        }

        // Guard D1 (context/40): saleA vendió qty=2, ya se devolvieron 1+1=2 —
        // una TERCERA devolución debe RECHAZARSE (no hay más para devolver).
        try {
            $svc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, $PY_REGISTER, $saleA, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', 'VERIFY-RETURN A3 (no debería poder)');
            $failures[] = 'Caso 5b: una tercera devolución sobre saleA (ya devuelta por completo) debía rechazarse y no lanzó excepción — el guard de "qty ya devuelta" contra la suma de NC previas no está funcionando';
        } catch (\InvalidArgumentException $e) {
            echo "[verify_return_numbering] OK caso 5b: tercera devolución sobre saleA (ya completa) se rechaza — {$e->getMessage()}\n";
        }
    }
} catch (\Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_return_numbering] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_return_numbering] TODO OK\n";
exit(0);
