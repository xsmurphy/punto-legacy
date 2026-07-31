<?php
/**
 * Migration 102b — Seed del método de pago "Cheque" (systemKey='check') para
 * TODOS los tenants existentes.
 *
 * Contexto: F1 de context/30-cheques-prevision-creditos.md necesita un medio
 * de pago real con `systemKey='check'` para que el POS dispare el prompt de
 * identifier (nro de cheque) y para que PaymentMethodResolver/FinanceLedger
 * puedan reconocerlo de forma estable. `PaymentMethodService::ensureSeed()`
 * ahora incluye "Cheque" en sus defaults, pero ese seed SOLO corre cuando un
 * tenant tiene CERO medios de pago — todo tenant existente ya tiene
 * Efectivo/T.Crédito/etc y nunca dispararía el seed. Este backfill cubre esa
 * brecha, mismo criterio que la mig 93 (backfill de permiso nuevo).
 *
 * Idempotente: solo inserta si el tenant no tiene ya un método con
 * systemKey='check' (chequeado con jsonb_exists sobre taxonomyExtra
 * decodificado en PHP — la columna es TEXT, no jsonb, así que no se puede
 * usar el operador ->> en SQL crudo, mismo bug documentado en
 * PaymentMethodService).
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$companies = $pdo->query('SELECT companyId FROM company')->fetchAll(PDO::FETCH_ASSOC);

$methodsStmt = $pdo->prepare(
    "SELECT taxonomyId, taxonomyExtra FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod'"
);
$insertStmt = $pdo->prepare(
    "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
     VALUES (gen_random_uuid(), ?, 'paymentMethod', 'Cheque', ?::jsonb)"
);

$created = 0;
$skipped = 0;

foreach ($companies as $company) {
    $companyId = (string) $company['companyid'];

    $methodsStmt->execute([$companyId]);
    $rows = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Tenant sin ningún medio de pago todavía: lo cubre PaymentMethodService::
    // ensureSeed() (lazy, al primer GET /v1/payment-methods) — no hace falta
    // insertar acá, evita crear medios "huérfanos" para tenants nunca usados.
    if (count($rows) === 0) {
        $skipped++;
        continue;
    }

    $hasCheck = false;
    $maxSortOrder = -1;
    foreach ($rows as $row) {
        $extra = json_decode((string) ($row['taxonomyextra'] ?? '{}'), true) ?: [];
        if (($extra['systemKey'] ?? null) === 'check') {
            $hasCheck = true;
            break;
        }
        if (isset($extra['sortOrder']) && (int) $extra['sortOrder'] > $maxSortOrder) {
            $maxSortOrder = (int) $extra['sortOrder'];
        }
    }
    if ($hasCheck) {
        $skipped++;
        continue;
    }

    $extra = [
        'code'                  => 'F',
        'hasChange'             => false,
        'requiresIdentifier'    => true,
        'identifierLabel'       => 'Nro de cheque',
        'identifierPlaceholder' => 'Ej. 001234',
        'systemKey'             => 'check',
        'color'                 => 'rose',
        'sortOrder'             => $maxSortOrder + 1,
    ];
    $insertStmt->execute([$companyId, json_encode($extra)]);
    $created++;
}

echo "[migrate] 102_seed_check_payment_method: {$created} tenant(s) con 'Cheque' creado, {$skipped} sin cambios\n";
