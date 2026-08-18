<?php

declare(strict_types=1);

/**
 * verify_receipt_numbering.php — arnés chico que demuestra, sin mockear
 * nada, que el recibo (transactionType=5) ahora tiene correlativo propio
 * (context/modules/17-numeracion.md §regla 7, context/37-numeracion-
 * documentos.md F5).
 *
 * Antes de este fix, TODO recibo salía con invoiceNo=0: `CreditPaymentService
 * ::insertReceipt()` y `VPaymentService::settleCreditInvoice()` llamaban al
 * helper legacy `getNextDocNumber(0, 5, $companyId, $registerId)`, que
 * resuelve a `max($number, último invoiceNo de type 5)` — con $number=0 y sin
 * histórico POSITIVO de type 5 (todos los recibos previos también tenían
 * invoiceNo=0), esto SIEMPRE devolvía 0. Dos recibos consecutivos eran
 * indistinguibles por número.
 *
 * El fix: `DocumentNumber::allocate('recibo', SCOPE_REGISTER, $registerId,
 * $companyId)` — misma secuencia atómica (`document_sequence`, INSERT...
 * ON CONFLICT...RETURNING) que ya usan factura/cotización/orden/etc.
 *
 * Casos:
 *   1. Cobro de crédito a CLIENTE (`CreditPaymentService::create()`,
 *      kind='credit_payment') de una factura A → recibo con invoiceNo > 0.
 *   2. Cobro de crédito a CLIENTE de una factura B (mismo companyId/
 *      register, contacto distinto) → invoiceNo DISTINTO y CORRELATIVO
 *      (exactamente +1 sobre el caso 1) — el caso central que este arnés
 *      cierra: antes de este fix, ambos hubieran salido en 0.
 *   3. `document_sequence` (companyId, doctype='recibo', scopetype=
 *      'register', scopeid=PY_REGISTER) quedó con nextnumber = invoiceNo del
 *      caso 2 + 1 — la fuente de verdad avanzó, no es un side-effect en
 *      memoria.
 *   4. `VPaymentService::settleCreditInvoice()` (el otro call-site migrado,
 *      cobro de crédito vía ePOS) consume la MISMA secuencia — invoiceNo
 *      sigue siendo +1 sobre el caso 2, demostrando que ambos call-sites
 *      comparten un solo correlativo por caja (scope register, D2 de
 *      context/37), no dos contadores independientes.
 *   5. El pago a PROVEEDOR (`kind='purchase_payment'`) NO migró en esta
 *      sesión — la compra (transactionType=4) no tiene registerId
 *      confiable (ver CreditPaymentService::insertReceipt, comentario junto
 *      al `invoiceNo`). Sigue con el comportamiento legacy (invoiceNo=0),
 *      a propósito: se verifica acá para dejar el gap documentado en el
 *      arnés, no solo en el reporte de la tarea.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

// data.php exige $companyId YA seteado en el scope del caller (require
// comparte variables) — si no, hace die() silencioso. Mismo patrón que
// verify_realtime.php/run_sale_chain.php.
$companyId = $PY_COMPANY;
require API_APP_DIR . '/data.php'; // define('TODAY', ...) — insertReceipt()/settleCreditInvoice() lo usan

require_once __DIR__ . '/../../services/CreditPaymentService.php';
require_once __DIR__ . '/../../services/VPaymentService.php';
require_once __DIR__ . '/../../Context/TenantContext.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\CreditPaymentService;
use Punto\Api\Services\VPaymentService;
// Contacto sembrado por seed.sql — la creditability no la valida
// CreditPaymentService (ese gate es del POS, pay-dialog.tsx), así que
// cualquier contacto type=1 alcanza.
$PY_CUSTOMER = '2b9f6a71-3e2b-4b34-9b5a-7a6a6a6a6a6a';

global $db;
$failures = [];

// ── Reset idempotente (Postgres reusado entre corridas locales) — mismo
//    criterio que verify_register_lease.php: borrar SOLO lo que este arnés
//    pudo haber dejado, nunca tocar datos reales.
//
//    Los recibos (type=5) que insertReceipt()/settleCreditInvoice() crean NO
//    llevan transactionUID 'VERIFY-RECEIPT-%' — generateUID() les pone un UID
//    propio (microtime). Se identifican vía transaction_link.derivedId
//    (originId = una de las facturas 'VERIFY-RECEIPT-%' de este arnés).
//    Orden obligatorio por el FK transaction_link.derivedid → transaction:
//    primero se captura la lista de recibos con `DELETE ... RETURNING` sobre
//    transaction_link (borra el link Y devuelve a qué recibo apuntaba en el
//    mismo statement), recién ahí se puede borrar la fila `transaction` del
//    recibo sin violar la FK. ─────────────────────────────────────────────
$orphanReceipts = ncmExecute(
    "DELETE FROM transaction_link
      WHERE companyId = ? AND kind IN ('credit_payment','purchase_payment')
        AND originId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-RECEIPT-%' AND companyId = ?)
      RETURNING derivedid",
    [$PY_COMPANY, $PY_COMPANY], false, false, true
);
$orphanReceiptIds = is_array($orphanReceipts) ? array_column($orphanReceipts, 'derivedid') : [];
if ($orphanReceiptIds !== []) {
    $ph = implode(',', array_fill(0, count($orphanReceiptIds), '?'));
    ncmExecute("DELETE FROM transaction WHERE transactionid IN ($ph)", $orphanReceiptIds);
}
ncmExecute("DELETE FROM vPayments WHERE companyId = ? AND uid LIKE 'VERIFY-RECEIPT-%'", [$PY_COMPANY]);
ncmExecute("DELETE FROM transaction WHERE transactionUID LIKE 'VERIFY-RECEIPT-%' AND companyId = ?", [$PY_COMPANY]);

/** Inserta una factura a crédito (type=3) impaga, lista para pagarse. */
function verifyMakeCreditSale(string $companyId, string $outletId, string $registerId, string $userId, string $customerId, string $uid, float $total): string
{
    $row = ncmExecute(
        'INSERT INTO transaction
            (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, customerid, registerid, userid,
             responsibleid, outletid, companyid)
         VALUES (now(), ?, 3, FALSE, 1, ?, ?, ?, ?, ?, ?, ?)
         RETURNING transactionid',
        [$total, $uid, $customerId, $registerId, $userId, $userId, $outletId, $companyId]
    );
    return (string) ($row['transactionid'] ?? '');
}

try {
    // ── Caso 1 + 2: dos cobros de crédito a CLIENTE, mismo register ────────
    $saleA = verifyMakeCreditSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_CUSTOMER, 'VERIFY-RECEIPT-SALE-A', 1000);
    $saleB = verifyMakeCreditSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_CUSTOMER, 'VERIFY-RECEIPT-SALE-B', 2000);

    if ($saleA === '' || $saleB === '') {
        $failures[] = 'Setup: no se pudieron crear las facturas a crédito de prueba (saleA/saleB)';
    } else {
        $svc = new CreditPaymentService();

        $receiptA = $svc->create($PY_COMPANY, $PY_USER, [['parentTransactionId' => $saleA, 'amount' => 1000]], 'efectivo', null, null, true);
        $invoiceNoA = ncmExecute('SELECT invoiceno FROM transaction WHERE transactionid = ?', [$receiptA['id']]);
        $invoiceNoA = (int) ($invoiceNoA['invoiceno'] ?? 0);

        if ($invoiceNoA < 1) {
            $failures[] = "Caso 1: recibo A esperaba invoiceNo > 0, llegó {$invoiceNoA} — este es exactamente el bug (todo recibo salía en 0)";
        } else {
            echo "[verify_receipt_numbering] OK caso 1: recibo A (cobro a cliente) tiene invoiceNo={$invoiceNoA} (> 0, ya no comparte el 0 de antes)\n";
        }

        $receiptB = $svc->create($PY_COMPANY, $PY_USER, [['parentTransactionId' => $saleB, 'amount' => 2000]], 'efectivo', null, null, true);
        $invoiceNoB = ncmExecute('SELECT invoiceno FROM transaction WHERE transactionid = ?', [$receiptB['id']]);
        $invoiceNoB = (int) ($invoiceNoB['invoiceno'] ?? 0);

        if ($invoiceNoB === $invoiceNoA) {
            $failures[] = "Caso 2: recibo B esperaba un invoiceNo DISTINTO del recibo A ({$invoiceNoA}), llegó el MISMO — el bug central (todos los recibos comparten el 0) seguiría vigente";
        } elseif ($invoiceNoB !== $invoiceNoA + 1) {
            $failures[] = "Caso 2: recibo B esperaba invoiceNo={$invoiceNoA}+1=" . ($invoiceNoA + 1) . ', llegó ' . $invoiceNoB . ' — no es correlativo (¿hueco o colisión de scope?)';
        } else {
            echo "[verify_receipt_numbering] OK caso 2 (CENTRAL): dos recibos consecutivos tienen invoiceNo distintos y correlativos ({$invoiceNoA} → {$invoiceNoB})\n";
        }

        // ── Caso 3: document_sequence es la fuente de verdad, no un side-effect en memoria ──
        $seqRow = ncmExecute(
            'SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = ? AND scopetype = ? AND scopeid = ?',
            [$PY_COMPANY, 'recibo', 'register', $PY_REGISTER]
        );
        $nextNumber = (int) ($seqRow['nextnumber'] ?? 0);
        if ($nextNumber !== $invoiceNoB + 1) {
            $failures[] = "Caso 3: document_sequence.nextnumber esperaba " . ($invoiceNoB + 1) . " (siguiente a emitir tras el recibo B), llegó {$nextNumber}";
        } else {
            echo "[verify_receipt_numbering] OK caso 3: document_sequence (doctype=recibo, scope=register) avanzó a nextnumber={$nextNumber} — la secuencia es la fuente de verdad, no un contador en memoria\n";
        }

        // ── Caso 4: VPaymentService::settleCreditInvoice() comparte LA MISMA secuencia ──
        $saleC = verifyMakeCreditSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_CUSTOMER, 'VERIFY-RECEIPT-SALE-C', 500);
        if ($saleC === '') {
            $failures[] = 'Setup: no se pudo crear la factura a crédito de prueba (saleC, para VPaymentService)';
        } else {
            $ctx = new TenantContext($PY_COMPANY, $PY_OUTLET, $PY_USER, $PY_REGISTER, '');
            $vpay = new VPaymentService($ctx);
            $vpayResult = $vpay->add($PY_COMPANY, $PY_OUTLET, $PY_USER, [
                'UID'        => 'VERIFY-RECEIPT-SALE-C',
                'saleAmount' => 500,
                'authCode'   => 'VERIFY-RECEIPT-AUTH-C',
                'source'     => 'bancardPOS',
                'order'      => 'VERIFY-RECEIPT-ORDER-C',
            ]);

            if (empty($vpayResult['success'])) {
                $failures[] = 'Caso 4: VPaymentService::add() no devolvió success — ' . json_encode($vpayResult);
            } else {
                // Identifica EL recibo por el vínculo real (transaction_link,
                // originId=saleC) en vez de una heurística por monto/fecha —
                // exacto incluso si otro caso dejó un recibo de igual monto.
                $receiptC = ncmExecute(
                    "SELECT t.invoiceno FROM transaction t
                      JOIN transaction_link tl ON tl.derivedid = t.transactionid
                      WHERE tl.companyid = ? AND tl.originid = ? AND tl.kind = 'credit_payment'
                      LIMIT 1",
                    [$PY_COMPANY, $saleC]
                );
                $invoiceNoC = (int) ($receiptC['invoiceno'] ?? 0);
                if ($invoiceNoC !== $invoiceNoB + 1) {
                    $failures[] = "Caso 4: recibo C (VPaymentService::settleCreditInvoice) esperaba invoiceNo=" . ($invoiceNoB + 1) . " (misma secuencia que A/B), llegó {$invoiceNoC} — ¿los dos call-sites quedaron en secuencias separadas?";
                } else {
                    echo "[verify_receipt_numbering] OK caso 4: VPaymentService::settleCreditInvoice() y CreditPaymentService::insertReceipt() comparten LA MISMA secuencia por caja (invoiceNo={$invoiceNoC}, correlativo sobre A/B) — un solo correlativo de recibo por register, no dos contadores\n";
                }
            }
        }
    }

    // ── Caso 5: el pago a PROVEEDOR queda documentado como gap conocido (no migrado esta sesión) ──
    // $registerId='' es el valor REAL que insertReceipt() calcula para el pago
    // a proveedor: PurchasesService nunca setea registerId (verificado por
    // grep, no queda en ningún fixture de compra), así que
    // `(string)($firstParent['registerId'] ?? '')` resuelve a cadena vacía —
    // no un UUID de mentira. Se prueba con ese mismo valor, no con un
    // register real, para no maquillar el gap.
    //
    // NOTA colateral (no arreglada acá, fuera de alcance): la query interna
    // de getNextDocNumber() liga registerId='' contra una columna uuid, que
    // Postgres rechaza (22P02 invalid input syntax for type uuid) — ncmExecute
    // atrapa el error y devuelve false, así que el helper igual resuelve a 0
    // sin crashear, pero cada pago a proveedor loguea un "[DB] Execute error"
    // en producción. Mismo comportamiento ANTES y DESPUÉS de este fix — no es
    // una regresión, solo queda registrado acá para que no se pierda.
    $legacyInvoiceNo = getNextDocNumber(0, 5, $PY_COMPANY, '');
    if ((int) $legacyInvoiceNo !== 0) {
        $failures[] = "Caso 5: getNextDocNumber(0,5,...,'') esperaba seguir devolviendo 0 (comportamiento legacy sin cambios para purchase_payment), llegó {$legacyInvoiceNo} — si esto cambió, revisar si alguien migró el path de proveedor sin actualizar este arnés";
    } else {
        echo "[verify_receipt_numbering] OK caso 5 (gap documentado): el pago a proveedor sigue sin correlativo propio (getNextDocNumber sigue en 0 con registerId='', el valor real que produce PurchasesService) — pendiente de decisión del owner sobre el scope, no resuelto en esta sesión\n";
    }
} catch (\Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_receipt_numbering] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_receipt_numbering] TODO OK\n";
exit(0);
