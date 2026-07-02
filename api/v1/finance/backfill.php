<?php
/**
 * REST — Importar histórico de Finanzas (Fase 3).
 *
 *   POST /v1/finance/backfill → recorre ventas/pagos de crédito/compras/
 *                                extracciones-ingresos de caja del tenant y
 *                                genera los `fin_movement` faltantes.
 *
 * Idempotente: reejecutable sin duplicar (ver api/database/seeds/finance_backfill.php
 * y el UNIQUE de mig 73). Botón "Importar histórico" en Finanzas → Ajustes.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

define('FINANCE_BACKFILL_NO_BOOTSTRAP', true);
require_once __DIR__ . '/../../database/seeds/finance_backfill.php';

$companyId = (string) COMPANY_ID;

// Guard contra doble-ejecución concurrente (el botón se puede clickear dos
// veces): advisory lock por tenant. Si otro backfill del mismo tenant ya está
// corriendo, rechazamos en vez de re-escanear el mismo set de transacciones.
// La key es un hash estable del companyId (bigint que pg_try_advisory_lock pide).
$lockKey = ncmExecute("SELECT ('x' || substr(md5(?), 1, 15))::bit(60)::bigint AS k", [$companyId]);
$lockId  = (int) ($lockKey['k'] ?? 0);
$gotLock = ncmExecute('SELECT pg_try_advisory_lock(?) AS locked', [$lockId]);
$locked  = $gotLock['locked'] ?? false;
if (!($locked === true || $locked === 't' || $locked === '1' || $locked === 1)) {
    apiError('Ya hay una importación de histórico en curso para este comercio. Esperá a que termine.', 409);
}

$before = ncmExecute('SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ?', [$companyId]);
$countBefore = (int) ($before['n'] ?? 0);

try {
    $counts = financeBackfillCompany($companyId);
} catch (\Throwable $e) {
    ncmExecute('SELECT pg_advisory_unlock(?)', [$lockId]);
    apiError('Error al importar histórico: ' . $e->getMessage(), 500);
}

ncmExecute('SELECT pg_advisory_unlock(?)', [$lockId]);

$after = ncmExecute('SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ?', [$companyId]);
$countAfter = (int) ($after['n'] ?? 0);

apiOk([
    'sales'          => $counts['sales'],
    'creditPayments' => $counts['creditPayments'],
    'purchases'      => $counts['purchases'],
    'drawerExpenses' => $counts['drawerExpenses'],
    'drawerIncomes'  => $counts['drawerIncomes'],
    'errors'         => $counts['errors'],
    'movementsBefore' => $countBefore,
    'movementsAfter'  => $countAfter,
    'movementsCreated' => $countAfter - $countBefore,
]);
