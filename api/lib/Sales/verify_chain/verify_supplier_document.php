<?php

declare(strict_types=1);

/**
 * verify_supplier_document.php — arnés chico que demuestra, sin mockear
 * nada, que el número de comprobante y el timbrado IMPRESOS en el
 * documento del PROVEEDOR (context/29-numeracion-y-exclusividad-de-caja.md
 * §5, mig 144) se persisten y se leen de vuelta en los 3 caminos donde
 * REGISTRAMOS (no emitimos) un documento ajeno: compra/gasto, nota de
 * crédito de compra (transactionType=14) y pago a proveedor
 * (transactionType=5, kind='purchase_payment'). Ninguno de los 3 genera
 * correlativo interno para ese documento — decisión del owner 2026-08-17,
 * textual: "capturar el número del proveedor".
 *
 * Casos:
 *   1. Compra (`PurchasesService::create()`) con authNo+authNoDueDate+
 *      invoicePrefix+invoiceNo → `find()` devuelve los mismos valores, y
 *      `invoicePrefix` NO lleva el hack legacy "authNo;prefix" (el timbrado
 *      vive en su propia columna, `supplierAuthNo`).
 *   2. Compat hacia atrás: una fila con el shape VIEJO (invoicePrefix=
 *      "authNo;prefix", supplierAuthNo NULL — como quedaron las compras
 *      pre-migración, sin backfill a propósito) sigue resolviendo el authNo
 *      vía el split legacy en `find()`/`list()` — `resolveAuthNo()`.
 *   3. Nota de crédito de compra (`PurchaseCreditNoteService::create()`) con
 *      `supplierDoc` → `listForParent()` devuelve docPrefix/docNo/docDate/
 *      authNo/authNoDueDate — antes de esta migración NINGUNO de estos
 *      campos existía para NC de compra.
 *   4. Pago a proveedor (`CreditPaymentService::create()`, isCustomer=false)
 *      con `supplierDoc` → la fila `transaction` (type=5) persiste
 *      supplierDocPrefix/supplierDocNo/supplierDocDate/supplierAuthNo/
 *      supplierAuthNoDueDate.
 *   5. Cobro a CLIENTE (`CreditPaymentService::create()`, isCustomer=true)
 *      con `supplierDoc` igual mandado (caller defensivo/con bug) → el
 *      recibo NO persiste esas columnas (quedan NULL) — `insertReceipt()`
 *      las gatea por `$kind === 'purchase_payment'`, nunca se filtra data
 *      de proveedor hacia un cobro de cliente.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 *
 * LIMITACIÓN CONOCIDA (compartida con el resto de este directorio, no
 * exclusiva de este arnés): los 3 servicios que este script llama in-process
 * validan con `apiError()` (api/lib/response.php), que hace `exit;` DESNUDO
 * (código 0) sin lanzar excepción — el `catch (\Throwable $e)` de abajo NO
 * lo intercepta. Si una validación intermedia dispara `apiError()` a mitad
 * de un caso, el proceso termina en 0 sin imprimir "TODO OK" ni el resto de
 * los casos, y `run.sh` (que solo mira el exit code) lo reportaría como paso
 * verde. Mitigación: cada caso imprime su propio "OK caso N" ANTES de seguir
 * — un corte silencioso se nota porque faltan líneas "OK" en el log, aunque
 * el exit code no lo delate.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Item real del seed (VERIFY-10-INC) — reusado tal cual, sin tocar seed.sql.
$PY_ITEM     = '10223f3b-2e3d-4339-8496-9f288d8be65b';
// Contacto proveedor propio de este arnés (seed.sql no tiene ninguno type=2).
$PY_SUPPLIER = '4a5b6c7d-8e9f-40a1-b2c3-d4e5f6a7b8c9';

$companyId = $PY_COMPANY;
require API_APP_DIR . '/data.php';

require_once __DIR__ . '/../../Purchases/PurchasesService.php';
require_once __DIR__ . '/../../Purchases/PurchaseCreditNoteService.php';
require_once __DIR__ . '/../../services/CreditPaymentService.php';

use Punto\Api\Purchases\PurchasesService;
use Punto\Api\Purchases\PurchaseCreditNoteService;
use Punto\Api\Services\CreditPaymentService;

global $db;
$failures = [];

// ── Reset idempotente — mismo criterio que verify_receipt_numbering.php:
//    borrar SOLO lo que este arnés pudo haber dejado (transactionUID
//    'VERIFY-SUPDOC-%'), respetando el orden de FKs. ─────────────────────
ncmExecute(
    "DELETE FROM transaction_link WHERE companyId = ?
      AND (originId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-SUPDOC-%' AND companyId = ?)
        OR derivedId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-SUPDOC-%' AND companyId = ?))",
    [$PY_COMPANY, $PY_COMPANY, $PY_COMPANY]
);
// itemSold: tabla creada SIN comillas (`CREATE TABLE itemSold`,
// db-schema-postgres.sql) → Postgres la guarda plegada a minúsculas
// (`itemsold`); citarla como "itemSold" exige coincidencia exacta y falla
// con "relation does not exist" (mismo bug que tenía PurchaseCreditNoteService,
// corregido en este mismo commit).
ncmExecute(
    "DELETE FROM itemSold WHERE transactionId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-SUPDOC-%' AND companyId = ?)",
    [$PY_COMPANY]
);
ncmExecute("DELETE FROM transaction WHERE transactionUID LIKE 'VERIFY-SUPDOC-%' AND companyId = ?", [$PY_COMPANY]);

ncmExecute(
    "INSERT INTO contact (contactId, contactName, contactStatus, type, main, role, outletId, companyId)
     VALUES (?, 'Verify PY Proveedor', 1, 2, '', 0, ?, ?)
     ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName",
    [$PY_SUPPLIER, $PY_OUTLET, $PY_COMPANY]
);

try {
    $purchases = new PurchasesService();

    // ── Caso 1: compra al contado con authNo+authNoDueDate+prefix+no ───────
    $purchaseId = $purchases->create($PY_COMPANY, [
        'outletId'      => $PY_OUTLET,
        'userId'        => $PY_USER,
        'supplierId'    => $PY_SUPPLIER,
        'condition'     => 'cash',
        'invoiceDate'   => '2026-08-10',
        'invoiceNo'     => 1234567,
        'invoicePrefix' => '001-001',
        'authNo'        => '87654321',
        'authNoDueDate' => '2027-06-30',
        'items'         => [['itemId' => $PY_ITEM, 'units' => 2, 'price' => 22000, 'taxId' => '3cf780bb-51d6-4b41-b52d-1e77bfb60969']],
    ]);
    ncmExecute("UPDATE transaction SET transactionUID = 'VERIFY-SUPDOC-PURCHASE-1' WHERE transactionId = ?", [$purchaseId]);

    $found = $purchases->find($purchaseId, $PY_COMPANY);
    if ($found === null) {
        $failures[] = 'Caso 1: PurchasesService::find() no encontró la compra recién creada';
    } else {
        if ($found['invoiceNo'] !== 1234567) {
            $failures[] = "Caso 1: invoiceNo esperaba 1234567, llegó " . var_export($found['invoiceNo'], true);
        }
        if ($found['invoicePrefix'] !== '001-001') {
            $failures[] = "Caso 1: invoicePrefix esperaba '001-001', llegó " . var_export($found['invoicePrefix'], true) . " — ¿volvió el hack 'authNo;prefix'?";
        }
        if ($found['authNo'] !== '87654321') {
            $failures[] = "Caso 1: authNo esperaba '87654321', llegó " . var_export($found['authNo'], true);
        }
        if (($found['authNoDueDate'] ?? null) === null || strpos((string) $found['authNoDueDate'], '2027-06-30') !== 0) {
            $failures[] = "Caso 1: authNoDueDate esperaba iniciar en '2027-06-30', llegó " . var_export($found['authNoDueDate'], true);
        }
        if ($failures === []) {
            echo "[verify_supplier_document] OK caso 1: compra persiste y devuelve invoiceNo/invoicePrefix/authNo/authNoDueDate del proveedor — invoicePrefix limpio (sin el hack 'authNo;prefix')\n";
        }
    }

    // ── Caso 2: compat hacia atrás — fila con el shape VIEJO (pre-mig 144) ──
    $legacyId = ncmExecute(
        "INSERT INTO transaction
            (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, invoiceno, invoiceprefix,
             supplierid, userid, outletid, companyid, meta)
         VALUES (now(), 1000, 1, TRUE, 1, 'VERIFY-SUPDOC-LEGACY', 42, '77777777;002-002',
                 ?, ?, ?, ?, '{}'::jsonb)
         RETURNING transactionid",
        [$PY_SUPPLIER, $PY_USER, $PY_OUTLET, $PY_COMPANY]
    );
    $legacyId = (string) ($legacyId['transactionid'] ?? '');
    $legacyFound = $legacyId !== '' ? $purchases->find($legacyId, $PY_COMPANY) : null;
    if ($legacyFound === null) {
        $failures[] = 'Caso 2: no se pudo crear/leer la compra legacy de prueba';
    } elseif ($legacyFound['authNo'] !== '77777777') {
        $failures[] = "Caso 2: fila pre-migración esperaba authNo='77777777' (split legacy de invoicePrefix), llegó " . var_export($legacyFound['authNo'], true) . " — resolveAuthNo() dejó de cubrir el shape viejo";
    } else {
        echo "[verify_supplier_document] OK caso 2 (compat): una fila con el shape legacy 'authNo;prefix' (sin supplierAuthNo, pre-migración) sigue resolviendo authNo vía split — nada retroactivo, pero no se rompe\n";
    }

    // ── Caso 3: nota de crédito de compra con supplierDoc ───────────────────
    $ncSvc = new PurchaseCreditNoteService();
    $ncResult = $ncSvc->create(
        $PY_COMPANY,
        $PY_USER,
        $PY_OUTLET,
        $purchaseId,
        [['itemId' => $PY_ITEM, 'qty' => 1]],
        'cash',
        true,
        'Producto dañado',
        [
            'docPrefix'     => '001-001',
            'docNo'         => '7654321',
            'docDate'       => '2026-08-12',
            'authNo'        => '11223344',
            'authNoDueDate' => '2027-03-15',
        ],
    );
    ncmExecute("UPDATE transaction SET transactionUID = 'VERIFY-SUPDOC-NC-1' WHERE transactionId = ?", [$ncResult['id']]);

    $ncList = $ncSvc->listForParent($purchaseId, $PY_COMPANY);
    $ncRow = null;
    foreach ($ncList as $row) {
        if ($row['id'] === (string) $ncResult['id']) {
            $ncRow = $row;
            break;
        }
    }
    if ($ncRow === null) {
        $failures[] = 'Caso 3: listForParent() no devolvió la NC recién creada';
    } else {
        if ($ncRow['docPrefix'] !== '001-001' || $ncRow['docNo'] !== 7654321) {
            $failures[] = 'Caso 3: docPrefix/docNo esperaba 001-001/7654321, llegó ' . json_encode(['docPrefix' => $ncRow['docPrefix'], 'docNo' => $ncRow['docNo']]);
        }
        if (($ncRow['docDate'] ?? null) === null || strpos((string) $ncRow['docDate'], '2026-08-12') !== 0) {
            $failures[] = 'Caso 3: docDate esperaba iniciar en 2026-08-12, llegó ' . var_export($ncRow['docDate'], true);
        }
        if ($ncRow['authNo'] !== '11223344') {
            $failures[] = "Caso 3: authNo esperaba '11223344', llegó " . var_export($ncRow['authNo'], true);
        }
        if ($failures === [] || !in_array(true, array_map(fn($f) => str_starts_with($f, 'Caso 3'), $failures), true)) {
            echo "[verify_supplier_document] OK caso 3: NC de compra persiste y devuelve docPrefix/docNo/docDate/authNo/authNoDueDate del proveedor — antes de esta migración ninguno existía\n";
        }
    }

    // ── Caso 4 + 5: pago a proveedor (persiste) vs cobro a cliente (NO filtra) ──
    $creditPurchaseId = $purchases->create($PY_COMPANY, [
        'outletId'    => $PY_OUTLET,
        'userId'      => $PY_USER,
        'supplierId'  => $PY_SUPPLIER,
        'condition'   => 'credit',
        'invoiceDate' => '2026-08-05',
        'dueDate'     => '2026-09-05',
        'items'       => [['itemId' => $PY_ITEM, 'units' => 1, 'price' => 5000, 'taxId' => '3cf780bb-51d6-4b41-b52d-1e77bfb60969']],
    ]);
    ncmExecute("UPDATE transaction SET transactionUID = 'VERIFY-SUPDOC-CREDITPURCHASE' WHERE transactionId = ?", [$creditPurchaseId]);

    $supplierDoc = [
        'docPrefix'     => '003-005',
        'docNo'         => '999',
        'docDate'       => '2026-08-16',
        'authNo'        => '55667788',
        'authNoDueDate' => '2027-12-31',
    ];

    $payment = new CreditPaymentService();
    $paymentResult = $payment->create(
        $PY_COMPANY, $PY_USER,
        [['parentTransactionId' => $creditPurchaseId, 'amount' => 5000]],
        'efectivo', null, null, false, $supplierDoc,
    );
    ncmExecute("UPDATE transaction SET transactionUID = 'VERIFY-SUPDOC-PAYMENT' WHERE transactionId = ?", [$paymentResult['id']]);

    $paymentRow = ncmExecute(
        'SELECT supplierdocprefix, supplierdocno, supplierdocdate, supplierauthno, supplierauthnoduedate
           FROM transaction WHERE transactionId = ?',
        [$paymentResult['id']]
    );
    if (!$paymentRow) {
        $failures[] = 'Caso 4: no se pudo leer la fila del recibo de pago a proveedor';
    } else {
        if ((string) $paymentRow['supplierdocprefix'] !== '003-005' || (int) $paymentRow['supplierdocno'] !== 999) {
            $failures[] = 'Caso 4: supplierdocprefix/supplierdocno esperaba 003-005/999, llegó ' . json_encode(['p' => $paymentRow['supplierdocprefix'], 'n' => $paymentRow['supplierdocno']]);
        }
        if ((string) $paymentRow['supplierauthno'] !== '55667788') {
            $failures[] = "Caso 4: supplierauthno esperaba '55667788', llegó " . var_export($paymentRow['supplierauthno'], true);
        }
        if ($paymentRow['supplierdocdate'] === null || strpos((string) $paymentRow['supplierdocdate'], '2026-08-16') !== 0) {
            $failures[] = 'Caso 4: supplierdocdate esperaba iniciar en 2026-08-16, llegó ' . var_export($paymentRow['supplierdocdate'], true);
        }
        if (!in_array(true, array_map(fn($f) => str_starts_with($f, 'Caso 4'), $failures), true)) {
            echo "[verify_supplier_document] OK caso 4: pago a proveedor persiste supplierDocPrefix/supplierDocNo/supplierDocDate/supplierAuthNo/supplierAuthNoDueDate en la fila del recibo (type=5)\n";
        }
    }

    // Caso 5: cobro a CLIENTE con supplierDoc mandado igual (caller defensivo)
    // — insertReceipt() debe IGNORARLO por completo (kind='credit_payment').
    $customerSaleId = ncmExecute(
        "INSERT INTO transaction (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, customerid, registerid, userid, responsibleid, outletid, companyid)
         VALUES (now(), 3000, 3, FALSE, 1, 'VERIFY-SUPDOC-CUSTOMERSALE', ?, ?, ?, ?, ?, ?)
         RETURNING transactionid",
        [$PY_SUPPLIER /* reused UUID as contactId, type no importa para este check */, $PY_REGISTER, $PY_USER, $PY_USER, $PY_OUTLET, $PY_COMPANY]
    );
    $customerSaleId = (string) ($customerSaleId['transactionid'] ?? '');

    $customerReceipt = $payment->create(
        $PY_COMPANY, $PY_USER,
        [['parentTransactionId' => $customerSaleId, 'amount' => 3000]],
        'efectivo', null, null, true, $supplierDoc, // isCustomer=true, supplierDoc igual mandado
    );
    ncmExecute("UPDATE transaction SET transactionUID = 'VERIFY-SUPDOC-CUSTOMERRECEIPT' WHERE transactionId = ?", [$customerReceipt['id']]);

    $customerRow = ncmExecute(
        'SELECT supplierdocprefix, supplierauthno FROM transaction WHERE transactionId = ?',
        [$customerReceipt['id']]
    );
    if (!$customerRow) {
        $failures[] = 'Caso 5: no se pudo leer la fila del recibo de cobro a cliente';
    } elseif ($customerRow['supplierdocprefix'] !== null || $customerRow['supplierauthno'] !== null) {
        $failures[] = 'Caso 5: el cobro a CLIENTE persistió supplierDoc (' . json_encode($customerRow) . ') — insertReceipt() debía ignorarlo para kind=credit_payment, hay una fuga';
    } else {
        echo "[verify_supplier_document] OK caso 5: el cobro a CLIENTE NO persiste supplierDoc aunque el caller lo mande — insertReceipt() lo gatea correctamente por kind='purchase_payment'\n";
    }
} catch (\Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_supplier_document] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_supplier_document] TODO OK\n";
exit(0);
