<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de `Store::getAllOutletData()` — las columnas que la migración 14
 * degradó al JSONB `data` tienen que volver en el resultado.
 *
 * ── Qué regresión cubre ──────────────────────────────────────────────────────
 *
 * `14_outlet_jsonb_demote_and_latlng.sql` movió outletAddress, outletPhone,
 * outletWhatsApp, outletEmail, outletBillingName, outletRUC y
 * outletDescription de columnas de `outlet` al JSONB `data`. El método sigue
 * haciendo `SELECT *`, así que NO explota — simplemente devolvía 6 claves en
 * vez de 13, porque `$db->Execute()` no aplica `flattenJsonb()` y la columna
 * `data` (que no contiene la palabra "outlet") quedaba fuera del filtro de
 * prefijo. Falla silenciosa: `api/data.php` definía OUTLET_EMAIL, OUTLET_PHONE,
 * OUTLET_ADDRESS y OUTLET_WHATS_APP en null sin que nadie se enterara.
 *
 * Este arnés inserta una sucursal con los 7 campos en el JSONB y verifica que
 * los lea, además de las claves que sí siguen siendo columnas.
 *
 * Uso: necesita Postgres migrado.
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/outlet_data_jsonb_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\App\Domain\Store;

$failures = 0;
$checks   = 0;

function check(string $label, mixed $got, mixed $want, int &$failures, int &$checks): void
{
    $checks++;
    if ($got === $want) {
        printf("  OK    %-46s = %s\n", $label, var_export($got, true));
        return;
    }
    $failures++;
    printf("  FALLA %-46s esperado %s, obtenido %s\n", $label, var_export($want, true), var_export($got, true));
}

// ── Fixture propio: una company + un outlet con los 7 campos en el JSONB ─────
$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // tenant "Verify PY" del seed
$outletId  = 'd7e1c0aa-1111-4b2b-9c3d-0f0e0d0c0b0a';

$existsCompany = ncmExecute('SELECT companyId FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
if (!$existsCompany) {
    // Sin el seed de verify_chain, tomamos cualquier company real de la base.
    $any = ncmExecute('SELECT companyId FROM company LIMIT 1');
    if (!$any) {
        fwrite(STDERR, "No hay ninguna company en la base — no se puede correr el arnés.\n");
        harnessFinish(1, 0);
    }
    $companyId = $any['companyId'];
}

$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletId]);

$db->Execute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId, data)
     VALUES (?, ?, 1, ?, ?::jsonb)",
    [
        $outletId,
        'Sucursal Arnés JSONB',
        $companyId,
        json_encode([
            'outletEmail'       => 'sucursal@ejemplo.com',
            'outletPhone'       => '595991000111',
            'outletAddress'     => 'Av. Siempre Viva 742',
            'outletWhatsApp'    => '595991000222',
            'outletBillingName' => 'Arnés SA',
            'outletRUC'         => '80012345-6',
            'outletDescription' => 'sucursal de prueba del arnés',
            // Clave del JSONB que NO lleva prefijo "outlet": debe quedar afuera
            // del resultado, igual que antes (el filtro de prefijo se conserva).
            'priceListId'       => 'no-deberia-aparecer',
        ], JSON_UNESCAPED_UNICODE),
    ]
);

echo "=== Store::getAllOutletData() — campos degradados al JSONB (mig 14) ===\n\n";

$row = Store::getAllOutletData($outletId);

// ── Claves que viven en el JSONB `data` ──────────────────────────────────────
check('email (data->outletEmail)',            $row['email'],       'sucursal@ejemplo.com',           $failures, $checks);
check('phone (data->outletPhone)',            $row['phone'],       '595991000111',                   $failures, $checks);
check('address (data->outletAddress)',        $row['address'],     'Av. Siempre Viva 742',           $failures, $checks);
check('billingName (data->outletBillingName)', $row['billingName'], 'Arnés SA',                       $failures, $checks);
check('description (data->outletDescription)', $row['description'], 'sucursal de prueba del arnés',   $failures, $checks);

// `outletWhatsApp` y `outletRUC` conservan el camelCase/mayúsculas del JSONB:
// tras sacar el prefijo quedan `whatsApp` y `rUC`. `api/data.php` los lee en
// minúscula — el CaseInsensitiveArray es lo que hace que ambas formas resuelvan.
check('whatsapp (lookup en minúscula)',       $row['whatsapp'],    '595991000222',                   $failures, $checks);
check('whatsApp (lookup camelCase)',          $row['whatsApp'],    '595991000222',                   $failures, $checks);
check('ruc (lookup en minúscula)',            $row['ruc'],         '80012345-6',                     $failures, $checks);

// ── Claves que siguen siendo columnas reales de la tabla ─────────────────────
check('name (columna outletName)',            $row['name'],        'Sucursal Arnés JSONB',           $failures, $checks);
check('id (columna outletId)',                $row['id'],          $outletId,                        $failures, $checks);
check('status (columna outletStatus)',        (int) $row['status'], 1,                               $failures, $checks);

// ── El filtro de prefijo se conserva: sin "outlet" en el nombre, no entra ────
check('priceListId NO se cuela',              $row['priceListId'], null,                             $failures, $checks);
check('companyId NO se cuela',                $row['companyId'],   null,                             $failures, $checks);

// ── Sucursal inexistente: CIA vacía, no "Undefined array key" ────────────────
$missing = Store::getAllOutletData('00000000-0000-4000-8000-000000000999');
check('sucursal inexistente → email null',    $missing['email'],   null,                             $failures, $checks);

// ── Cleanup ──────────────────────────────────────────────────────────────────
$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletId]);

harnessFinish($failures, $checks);
