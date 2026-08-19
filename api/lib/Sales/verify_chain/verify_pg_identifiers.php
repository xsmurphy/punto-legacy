<?php

declare(strict_types=1);

/**
 * verify_pg_identifiers.php — arnés chico que ejercita, sin mockear nada,
 * los caminos rotos por identificadores Postgres entrecomillados con
 * case incorrecto (`"itemSold"`, `"itemLocation"`, `a.adminid`,
 * `"contactPhone"`/`"contactId"`/`companyid`/`"contactStatus"`/
 * `"contactTIN"`) — el mismo bug ya corregido en `ReturnService::create()`
 * (base de esta branch, ver su docblock para la explicación completa del
 * mecanismo: identificador creado SIN comillas → Postgres lo pliega a
 * minúsculas → citarlo con comillas y case original exige match EXACTO y
 * Postgres tira "relation/column ... does not exist").
 *
 * Caso 1 — EInvoiceService::buildCreditNoteArrayForMapper() (línea ~1767,
 *   FACTURACIÓN ELECTRÓNICA, el más grave de los reportados): privado, se
 *   invoca vía Reflection porque no tiene entrypoint público que no dependa
 *   de red (tryIssueInline() sigue con el envío real a SIFEN, fuera de
 *   alcance de este arnés "sin red"). Arma: venta devolvible + su
 *   einvoice_document 'issued' (factura original) + ReturnService::create()
 *   real (que ya deja itemSold + transaction_link kind='return' listos) →
 *   llama al método fijado y verifica que arme el array de la nota de
 *   crédito sin el error "relation itemsold does not exist".
 *
 * Caso 2 — PurchaseCreditNoteService::create() (líneas ~157/179/271): NO se
 *   puede cerrar en verde end-to-end. Descubrimos en el camino un SEGUNDO
 *   bug, independiente del de comillas y FUERA de alcance de esta tarea
 *   (no es de citado, es una columna que no existe): la query de la línea
 *   ~161 hace `MIN(taxid::text) AS taxid` contra `itemSold`, pero esa tabla
 *   NO tiene columna `taxid` (sí `itemsoldtax`) — Postgres tira "column
 *   taxid does not exist" ANTES de llegar a las líneas 179/271. Ver el
 *   reporte final de la tarea (punto C) para el detalle — no se corrige acá
 *   (regla del proyecto: la solución a UN bug no absorbe otro no
 *   relacionado sin decisión explícita). Lo que SÍ demuestra este caso: el
 *   fallo cambió de "relation itemsold does not exist" (el bug de comillas,
 *   ya no ocurre) a "column taxid does not exist" (el bug de columna,
 *   pendiente) — evidencia directa y objetiva de que Postgres ahora
 *   RESUELVE la relación itemSold correctamente y solo se queja de una
 *   columna puntual dentro de ella.
 *
 * Caso 3 — PurchaseCreditNoteService::void() (línea ~401) y
 *   creditedQtyByItem() (línea ~507, la que estaba en comillas ESCAPADAS
 *   `\"itemSold\"` dentro de un string PHP con comillas dobles — un grep
 *   ingenuo no la encontraba): como create() no se puede correr de punta a
 *   punta (caso 2), se arma la fixture de una nota de crédito de compra ya
 *   emitida con INSERTs directos (transaction type=14 + itemSold negativo +
 *   transaction_link kind='purchase_credit_note' + stock
 *   stocksource='purchase_credit_note', el mismo shape que create()
 *   generaría si no estuviera bloqueada) y se llaman void()/
 *   creditedQtyByItem() DIRECTAMENTE — código real, no una copia — contra
 *   esa fixture.
 *
 * Caso 4 — LocationTaxonomyService::delete() (línea ~59): guard FK bloqueaba
 *   CUALQUIER borrado de depósito (tiraba excepción antes de siquiera mirar
 *   si había ítems asignados). Crea un depósito, asigna un ítem, verifica
 *   que bloquee; libera el ítem, verifica que borre.
 *
 * Caso 5 — CompanyAdminService::listNotes() (línea ~1423, `a.adminid`):
 *   join a admin_user (columnas lowercase-nativas, a diferencia de
 *   tenant_note que sí se creó con columnas quoted camelCase) rompía CUALQUIER
 *   listado de notas internas del admin panel que tuviera al menos una nota.
 *   Crea un admin_user + una nota vía createNote() real, lista con
 *   listNotes() y verifica que authorName resuelva (antes: excepción).
 *
 * Caso 6 — ContactImporter::importFromCsv() modo 'update' (líneas 130/136):
 *   el import CSV de contactos en modo actualización NUNCA podía encontrar
 *   un contacto existente por teléfono o RUC (contact es tabla legacy,
 *   TODAS sus columnas lowercase-nativas) — cada fila de un update
 *   terminaba creando un duplicado en vez de actualizar. Siembra un
 *   contacto CON RUC, importa un CSV de una fila con el mismo RUC en modo
 *   'update', verifica 1 updated / 0 created y que no haya duplicado —
 *   ejercita el segundo branch (línea 134-138, match por contactTIN), no el
 *   de teléfono: el de teléfono (línea 128-132, match por contactPhone)
 *   tiene ADEMÁS un bug de formato completamente distinto y fuera de
 *   alcance (`phoneToE164()` deja el '+' inicial y lo compara tal cual
 *   contra `contactPhone`, que el proyecto guarda SIN '+' — nunca matchea
 *   ni siquiera con las comillas ya arregladas), reportado en el punto C
 *   del reporte final, no corregido acá.
 *
 * Uso: ver run.sh, que lo invoca como paso propio con las mismas env vars
 * POSTGRES_*. Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$PY_ITEM     = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // VERIFY-10-INC, precio 11000, taxid 10%

// data.php exige $companyId/$outletId/$userId/$registerId/$roleId YA seteados
// en el scope del caller (require comparte variables) — mismo patrón que
// verify_realtime.php/verify_receipt_numbering.php. Define COMPANY_ID/
// OUTLET_ID/USER_ID/TODAY que varios de los caminos de abajo necesitan.
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = $PY_REGISTER;
$roleId     = '1';
require API_APP_DIR . '/data.php';

require_once __DIR__ . '/../../services/ReturnService.php';
require_once __DIR__ . '/../../EInvoice/EInvoiceService.php';
require_once __DIR__ . '/../../services/TransactionLinkService.php';
require_once __DIR__ . '/../../Purchases/PurchaseCreditNoteService.php';
require_once __DIR__ . '/../../Taxonomies/LocationTaxonomyService.php';
require_once __DIR__ . '/../../Admin/CompanyAdminService.php';
require_once __DIR__ . '/../../Contacts/ContactImporter.php';
require_once __DIR__ . '/../../Contacts/ContactService.php';
require_once __DIR__ . '/../../Contacts/ContactRepository.php';

use Punto\Api\Services\ReturnService;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Services\TransactionLinkService;
use Punto\Api\Purchases\PurchaseCreditNoteService;
use Punto\Api\Taxonomies\LocationTaxonomyService;
use Punto\Api\Admin\CompanyAdminService;
use Punto\Api\Contacts\ContactImporter;
use Punto\Api\Contacts\ContactService;
use Punto\Api\Contacts\ContactRepository;
// CompanyAdminService.php no declara namespace (clase global) — sin `use`,
// referenciada como \CompanyAdminService más abajo.

global $db;
$failures = [];

// ── Reset idempotente (Postgres reusado entre corridas locales) ────────────
ncmExecute("DELETE FROM itemSold WHERE transactionid IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?)", [$PY_COMPANY]);
ncmExecute("DELETE FROM stock WHERE transactionid IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?)", [$PY_COMPANY]);
ncmExecute("DELETE FROM transaction_link WHERE companyId = ? AND (originId IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?) OR derivedid IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?))", [$PY_COMPANY, $PY_COMPANY, $PY_COMPANY]);
ncmExecute("DELETE FROM einvoice_document WHERE companyid = ? AND transactionid IN (SELECT transactionId FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?)", [$PY_COMPANY, $PY_COMPANY]);
ncmExecute("DELETE FROM transaction WHERE transactionUID LIKE 'VERIFY-PGID-%' AND companyId = ?", [$PY_COMPANY]);
ncmExecute("DELETE FROM itemLocation WHERE companyid = ? AND itemid = ?", [$PY_COMPANY, $PY_ITEM]);
ncmExecute("DELETE FROM taxonomy WHERE companyid = ? AND taxonomytype = 'location' AND taxonomyname = 'VERIFY-PGID-LOCATION'", [$PY_COMPANY]);
ncmExecute('DELETE FROM tenant_note WHERE companyid = ? AND body = \'VERIFY-PGID-NOTE\'', [$PY_COMPANY]);
ncmExecute("DELETE FROM admin_user WHERE email = 'verify-pgid-admin@punto.la'", []);
// contacttin (no contactname) porque el caso 6 RENOMBRA el contacto al
// actualizarlo — un cleanup por nombre exacto dejaría de matchear la fila
// en la segunda corrida local y el arnés dejaría de ser idempotente.
ncmExecute("DELETE FROM contact WHERE companyid = ? AND contacttin = 'VERIFY-PGID-RUC-80012345'", [$PY_COMPANY]);

/** Inserta una venta pagada (type=0) con UNA línea de itemSold, lista para devolver. */
function verifyPgidMakeReturnableSale(
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
    // ═══════════════════════════════════════════════════════════════════
    // Caso 1 — EInvoiceService::buildCreditNoteArrayForMapper() (línea ~1767)
    // ═══════════════════════════════════════════════════════════════════
    $saleForNC = verifyPgidMakeReturnableSale($PY_COMPANY, $PY_OUTLET, $PY_REGISTER, $PY_USER, $PY_ITEM, 'VERIFY-PGID-SALE-NC', 11000, 1);
    if ($saleForNC === '') {
        $failures[] = 'Caso 1 setup: no se pudo crear la venta a devolver';
    } else {
        // Factura original YA EMITIDA (issued, con CDC) — buildCreditNoteArrayForMapper
        // la exige para resolver associatedCdc.
        ncmExecute(
            "INSERT INTO einvoice_document (companyid, transactionid, doctype, status, cdc, issued_at)
             VALUES (?, ?, 'FC', 'issued', '01800695561001001000000012025081712345678901', now())",
            [$PY_COMPANY, $saleForNC]
        );

        $svc = new ReturnService();
        $return = $svc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, $PY_REGISTER, $saleForNC, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', 'VERIFY-PGID-RETURN');
        $returnId = (string) $return['id'];

        $eInvoiceSvc = new EInvoiceService();
        $ref = new \ReflectionMethod($eInvoiceSvc, 'buildCreditNoteArrayForMapper');
        $ref->setAccessible(true);
        try {
            $ncArray = $ref->invoke($eInvoiceSvc, $PY_COMPANY, $returnId);
            if (!is_array($ncArray) || empty($ncArray['items'])) {
                $failures[] = 'Caso 1: buildCreditNoteArrayForMapper() no devolvió items — esperaba al menos 1 línea de la devolución';
            } elseif ($ncArray['associatedCdc'] !== '01800695561001001000000012025081712345678901') {
                $failures[] = 'Caso 1: associatedCdc no coincide con el CDC de la factura original';
            } else {
                echo "[verify_pg_identifiers] OK caso 1: EInvoiceService::buildCreditNoteArrayForMapper() lee itemSold sin el error \"relation itemsold does not exist\" — arma " . count($ncArray['items']) . " línea(s), associatedCdc correcto\n";
            }
        } catch (\Throwable $e) {
            $failures[] = 'Caso 1: buildCreditNoteArrayForMapper() tiró excepción — ' . $e->getMessage();
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 2 — PurchaseCreditNoteService::create() (líneas ~157/179/271):
    // NO cierra en verde — bug de columna `taxid` inexistente en itemSold,
    // independiente del de comillas y fuera de alcance (ver docblock).
    // Lo que se demuestra: el error cambia de clase.
    // ═══════════════════════════════════════════════════════════════════
    $purchaseRow = ncmExecute(
        'INSERT INTO transaction
            (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, userid, outletid, companyid)
         VALUES (now(), ?, 1, TRUE, 1, ?, ?, ?, ?)
         RETURNING transactionid',
        [11000, 'VERIFY-PGID-PURCHASE', $PY_USER, $PY_OUTLET, $PY_COMPANY]
    );
    $purchaseId = (string) ($purchaseRow['transactionid'] ?? '');
    ncmExecute(
        'INSERT INTO itemSold (itemid, transactionid, itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldtax, itemsolddate)
         VALUES (?, ?, ?, ?, 0, 0, now())',
        [$PY_ITEM, $purchaseId, 1, 11000]
    );

    $pcnSvc = new PurchaseCreditNoteService();
    try {
        $pcnSvc->create($PY_COMPANY, $PY_USER, $PY_OUTLET, $purchaseId, [['itemId' => $PY_ITEM, 'qty' => 1]], 'cash', false, 'VERIFY-PGID-NC');
        $failures[] = 'Caso 2: PurchaseCreditNoteService::create() no tiró excepción — si esto pasa, alguien corrigió el bug de taxid reportado en el punto C; actualizar este arnés para afirmar el caso completo (hoy no cierra a propósito)';
    } catch (\Throwable $e) {
        // create() envuelve el error real de Postgres en un mensaje genérico
        // ("Item ... no encontrado en la compra original", porque GetRow()
        // devuelve false ante CUALQUIER error de Execute(), no solo "0 filas")
        // — el texto real de Postgres sobrevive en $db->ErrorMsg() (firstError
        // de la transacción actual), no en $e->getMessage().
        $dbErr = $db->ErrorMsg();
        if (stripos($dbErr, 'itemsold') !== false && stripos($dbErr, 'does not exist') !== false && stripos($dbErr, 'taxid') === false) {
            $failures[] = "Caso 2: PurchaseCreditNoteService::create() sigue fallando por itemSold/relation (el bug de comillas NO está resuelto) — {$dbErr}";
        } elseif (stripos($dbErr, 'taxid') !== false) {
            echo "[verify_pg_identifiers] OK caso 2: PurchaseCreditNoteService::create() ya NO falla por \"relation itemsold does not exist\" (bug de comillas corregido — Postgres ahora RESUELVE la relación itemSold) — falla por el bug DISTINTO y fuera de alcance de la columna taxid inexistente en itemSold (reportado en el punto C del reporte final, no corregido acá): {$dbErr}\n";
        } else {
            $failures[] = "Caso 2: PurchaseCreditNoteService::create() falló con un error inesperado (ni itemSold/relation ni taxid) — mensaje: {$e->getMessage()} / db: {$dbErr}";
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 3 — PurchaseCreditNoteService::void() (línea ~401) y
    // creditedQtyByItem() (línea ~507, comillas escapadas). Fixture armada
    // con INSERTs directos porque create() no puede generarla (caso 2).
    // ═══════════════════════════════════════════════════════════════════
    $ncRow = ncmExecute(
        'INSERT INTO transaction
            (transactiondate, transactiontotal, transactiontype, transactioncomplete,
             transactionstatus, transactionuid, userid, outletid, companyid, supplierid)
         VALUES (now(), ?, 14, TRUE, 1, ?, ?, ?, ?, NULL)
         RETURNING transactionid',
        [11000, 'VERIFY-PGID-NC-DIRECT', $PY_USER, $PY_OUTLET, $PY_COMPANY]
    );
    $ncId = (string) ($ncRow['transactionid'] ?? '');
    ncmExecute(
        'INSERT INTO itemSold (itemid, transactionid, itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldtax, itemsolddate)
         VALUES (?, ?, ?, ?, 0, 0, now())',
        [$PY_ITEM, $ncId, -1, 11000]
    );
    ncmExecute(
        "INSERT INTO stock (itemid, transactionid, outletid, companyid, stocksource, stockcount)
         VALUES (?, ?, ?, ?, 'purchase_credit_note', -1)",
        [$PY_ITEM, $ncId, $PY_OUTLET, $PY_COMPANY]
    );
    (new TransactionLinkService())->link($PY_COMPANY, $purchaseId, $ncId, 'purchase_credit_note');

    try {
        $qtyByItem = $pcnSvc->creditedQtyByItem($purchaseId, $PY_COMPANY);
        if ((float) ($qtyByItem[$PY_ITEM] ?? 0) !== 1.0) {
            $failures[] = 'Caso 3a: creditedQtyByItem() esperaba qty=1 para ' . $PY_ITEM . ', llegó ' . var_export($qtyByItem[$PY_ITEM] ?? null, true);
        } else {
            echo "[verify_pg_identifiers] OK caso 3a: PurchaseCreditNoteService::creditedQtyByItem() lee itemSold (comillas escapadas \\\"itemSold\\\" en el source, no las agarraba un grep ingenuo) y suma qty=1 correctamente\n";
        }
    } catch (\Throwable $e) {
        $failures[] = 'Caso 3a: creditedQtyByItem() tiró excepción — ' . $e->getMessage();
    }

    try {
        $voidResult = $pcnSvc->void($ncId, $PY_COMPANY, $PY_USER);
        $statusRow = ncmExecute('SELECT transactionstatus FROM transaction WHERE transactionid = ?', [$ncId]);
        if ((int) ($statusRow['transactionstatus'] ?? 0) !== 6) {
            $failures[] = 'Caso 3b: void() no dejó transactionstatus=6 (anulada)';
        } else {
            echo "[verify_pg_identifiers] OK caso 3b: PurchaseCreditNoteService::void() lee itemSold para revertir stock sin \"relation itemsold does not exist\" y anula la NC\n";
        }
    } catch (\Throwable $e) {
        $failures[] = 'Caso 3b: void() tiró excepción — ' . $e->getMessage();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 4 — LocationTaxonomyService::delete() (línea ~59)
    // ═══════════════════════════════════════════════════════════════════
    $locSvc = new LocationTaxonomyService($db);
    $loc = $locSvc->create($PY_COMPANY, $PY_OUTLET, 'VERIFY-PGID-LOCATION');
    $locationId = $loc['id'];

    ncmExecute(
        'INSERT INTO itemLocation (itemid, locationid, outletid, companyid)
         VALUES (?, ?, ?, ?)',
        [$PY_ITEM, $locationId, $PY_OUTLET, $PY_COMPANY]
    );

    try {
        $delBlocked = $locSvc->delete($PY_COMPANY, $locationId);
        if (($delBlocked['blocked'] ?? false) !== true) {
            $failures[] = 'Caso 4a: delete() con un ítem asignado esperaba blocked=true, llegó ' . var_export($delBlocked, true) . ' — antes de este fix tiraba excepción ("relation itemlocation does not exist") en vez de bloquear prolijamente';
        } else {
            echo "[verify_pg_identifiers] OK caso 4a: LocationTaxonomyService::delete() lee itemLocation (antes \"itemLocation\" tiraba excepción SIEMPRE) y bloquea el borrado con {$delBlocked['items']} ítem(s) asignado(s)\n";
        }

        ncmExecute('DELETE FROM itemLocation WHERE itemid = ? AND locationid = ?', [$PY_ITEM, $locationId]);

        $delOk = $locSvc->delete($PY_COMPANY, $locationId);
        if (($delOk['blocked'] ?? true) !== false) {
            $failures[] = 'Caso 4b: delete() sin ítems asignados esperaba blocked=false, llegó ' . var_export($delOk, true);
        } else {
            echo "[verify_pg_identifiers] OK caso 4b: LocationTaxonomyService::delete() sin ítems asignados borra el depósito sin bloquear\n";
        }
    } catch (\Throwable $e) {
        $failures[] = 'Caso 4: LocationTaxonomyService::delete() tiró excepción — ' . $e->getMessage() . ' (antes de este fix, SIEMPRE tiraba "relation itemlocation does not exist", bloqueando CUALQUIER borrado de depósito)';
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 5 — CompanyAdminService::listNotes() (línea ~1423, a.adminid)
    // ═══════════════════════════════════════════════════════════════════
    $adminRow = ncmExecute(
        "INSERT INTO admin_user (email, passwordhash, name) VALUES ('verify-pgid-admin@punto.la', 'x', 'Verify PGID Admin') RETURNING adminid",
        []
    );
    $adminId = (string) ($adminRow['adminid'] ?? '');

    $adminSvc = new \CompanyAdminService();
    $created = $adminSvc->createNote($PY_COMPANY, $adminId, 'VERIFY-PGID-NOTE');
    if (!($created['ok'] ?? false)) {
        $failures[] = 'Caso 5 setup: createNote() falló — ' . ($created['error'] ?? '?');
    } else {
        try {
            $notesPage = $adminSvc->listNotes($PY_COMPANY, 1, 20);
            $found = null;
            foreach ($notesPage['rows'] as $row) {
                if (($row['body'] ?? '') === 'VERIFY-PGID-NOTE') {
                    $found = $row;
                    break;
                }
            }
            if ($found === null) {
                $failures[] = 'Caso 5: listNotes() no devolvió la nota sembrada';
            } elseif (($found['authorName'] ?? '') !== 'Verify PGID Admin') {
                $failures[] = 'Caso 5: listNotes() esperaba authorName="Verify PGID Admin" (join a admin_user), llegó ' . var_export($found['authorName'] ?? null, true);
            } else {
                echo "[verify_pg_identifiers] OK caso 5: CompanyAdminService::listNotes() joinea admin_user (columnas lowercase-nativas, a.adminId sin comillas) y resuelve authorName correctamente\n";
            }
        } catch (\Throwable $e) {
            $failures[] = 'Caso 5: listNotes() tiró excepción — ' . $e->getMessage() . ' (antes de este fix, a.adminid rompía CUALQUIER listado de notas del admin panel que tuviera al menos 1 nota)';
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 6 — ContactImporter::importFromCsv() modo 'update' (líneas 130/136)
    // ═══════════════════════════════════════════════════════════════════
    $contactSvc = new ContactService(new ContactRepository($db));
    $existingContactId = $contactSvc->create($PY_COMPANY, [
        'name' => 'VERIFY-PGID-CONTACT',
        'tin'  => 'VERIFY-PGID-RUC-80012345',
        'type' => 1,
    ]);

    $csvPath = tempnam(sys_get_temp_dir(), 'verify_pgid_contacts_');
    file_put_contents($csvPath, "TIPO,NOMBRE,TELEFONO,EMAIL,RUC_CI,DIRECCION,NOTAS\ncliente,VERIFY-PGID-CONTACT (updated),,,VERIFY-PGID-RUC-80012345,,");

    try {
        $importer = new ContactImporter($db);
        $report = $importer->importFromCsv($csvPath, 'update', $PY_COMPANY, $PY_USER);
        @unlink($csvPath);

        if (($report['errors'] ?? []) !== []) {
            $failures[] = 'Caso 6: import en modo update tiró errores — ' . json_encode($report['errors']);
        } elseif (($report['updated'] ?? 0) !== 1 || ($report['created'] ?? 0) !== 0) {
            $failures[] = "Caso 6: import en modo update esperaba updated=1/created=0 (matchear el contacto existente por teléfono), llegó updated=" . ($report['updated'] ?? '?') . ' created=' . ($report['created'] ?? '?') . ' — antes de este fix, "contactPhone"/"contactId" con comillas nunca encontraba el existente y SIEMPRE creaba un duplicado';
        } else {
            $dupCheck = ncmExecute(
                'SELECT COUNT(*) AS n FROM contact WHERE companyid = ? AND contacttin = ?',
                [$PY_COMPANY, 'VERIFY-PGID-RUC-80012345']
            );
            if ((int) ($dupCheck['n'] ?? 0) !== 1) {
                $failures[] = 'Caso 6: tras el update esperaba exactamente 1 contacto con ese RUC, llegó ' . ($dupCheck['n'] ?? '?') . ' — el import creó un duplicado';
            } else {
                echo "[verify_pg_identifiers] OK caso 6: ContactImporter::importFromCsv() modo 'update' matchea el contacto existente por contactTIN (sin comillas) — updated=1, created=0, sin duplicado\n";
            }
        }
    } catch (\Throwable $e) {
        @unlink($csvPath);
        $failures[] = 'Caso 6: importFromCsv() tiró excepción — ' . $e->getMessage();
    }
} catch (\Throwable $e) {
    $failures[] = 'Excepción no capturada: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_pg_identifiers] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_pg_identifiers] TODO OK\n";
exit(0);
