<?php
/**
 * REST — Reportes de Finanzas (montos agregados por dimensión).
 *
 *   GET /v1/finance/reports?by=category&from=&to= → ingresos/egresos/neto
 *                                                    del período, agrupados
 *                                                    por categoría (default).
 *   GET /v1/finance/reports?by=account&from=&to=  → ídem, agrupado por cuenta.
 *   GET /v1/finance/reports?by=costcenter&from=&to= → ídem, agrupado por
 *                                                    centro de costo (mig 167).
 *
 *       from/to default: mes calendario actual (tenant-local, naive — §51),
 *       mismo default que /v1/finance/summary. Este router mapea 1 archivo
 *       .php = 1 URL (sin PATH_INFO — ver api/router.php), por eso la
 *       dimensión viaja en el query string `by`, no en el path.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$companyId = (string) COMPANY_ID;

// Rango del período. Dos cosas las resuelve el helper compartido:
//
//   1. Una fecha SOLA en `to` significa el FINAL de ese día. Antes viajaba tal
//      cual y Postgres la leía como 00:00:00, así que `to=2026-09-01` devolvía
//      el 1 de septiembre vacío. El panel no lo pegaba (su `rangeToBackend()`
//      ya manda la hora); sí el agente IA y los consumidores por API key.
//   2. La fecha tiene que EXISTIR, no solo matchear el formato: "2026-99-99"
//      pasaba el regex y rompía como bind param. El checkdate() que este
//      endpoint tenía inline ahora vive en Date::isRangeBound() y vale para
//      todos los reportes.
//
// Se ignora a propósito el flag de validez: acá un rango mal formado degrada
// al default (mes calendario actual) en vez de cortar con 422.
[$from, $to, $rangeOk] = Date::reportRange(
    $_GET['from'] ?? '',
    $_GET['to'] ?? '',
    date('Y-m-01 00:00:00'),
);
if (!$rangeOk) {
    // Degradar en silencio es peor que fallar: el cliente cree que pidio un
    // periodo y recibe otro. No se rompe el contrato (sigue sin 422, hay
    // callers que dependen del degrade), pero queda rastro para diagnosticar.
    error_log(sprintf(
        '[finance/reports] rango invalido, se degrada al mes actual: from=%s to=%s',
        (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')
    ));
}

$by = trim((string) ($_GET['by'] ?? 'category'));

$movementSvc = new \Punto\Api\Finance\MovementService();

if ($by === 'account') {
    apiOk(['rows' => $movementSvc->totalsByAccount($companyId, $from, $to), 'period' => ['from' => $from, 'to' => $to]]);
}

if ($by === 'costcenter') {
    apiOk(['rows' => $movementSvc->totalsByCostCenter($companyId, $from, $to), 'period' => ['from' => $from, 'to' => $to]]);
}

// Default: category.
apiOk(['rows' => $movementSvc->totalsByCategory($companyId, $from, $to), 'period' => ['from' => $from, 'to' => $to]]);
