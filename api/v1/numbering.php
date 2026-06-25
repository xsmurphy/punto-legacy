<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$authCtx = apiAuthPosContext();

if (($authCtx['registerId'] ?? '') === '') {
    apiError('Seleccioná una caja antes de operar', 403);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$count  = max(1, min(200, (int) ($body['count'] ?? 100)));
$regId  = $authCtx['registerId'];
$compId = $authCtx['companyId'];

// Check for existing active leases
$rsActive = ncmExecute(
    'SELECT "invoiceNo", "leaseId", "expiresAt" FROM "numbering_lease" WHERE "registerId" = ? AND "companyId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW() ORDER BY "invoiceNo" ASC',
    [$regId, $compId],
    false,
    true // forceObj — returns recordset
);

if ($rsActive !== false && $rsActive !== 0) {
    $invoiceNos    = [];
    $firstLeaseId  = null;
    $firstExpiresAt = null;

    while (!$rsActive->EOF) {
        $row = $rsActive->fields;
        if ($firstLeaseId === null) {
            $firstLeaseId   = $row['leaseId'];
            $firstExpiresAt = $row['expiresAt'];
        }
        $invoiceNos[] = (int) $row['invoiceNo'];
        $rsActive->MoveNext();
    }

    if (count($invoiceNos) > 0) {
        apiOk([
            'from'      => $invoiceNos[0],
            'to'        => $invoiceNos[count($invoiceNos) - 1],
            'leaseId'   => $firstLeaseId,
            'expiresAt' => $firstExpiresAt,
        ]);
    }
}

// No active lease — find the highest existing invoiceNo and issue a new block
$maxRow  = ncmExecute(
    'SELECT MAX("invoiceNo") AS maxno FROM transaction WHERE "registerId" = ? AND "companyId" = ?',
    [$regId, $compId]
);
$maxno   = ($maxRow && isset($maxRow['maxno']) && $maxRow['maxno'] !== null) ? (int) $maxRow['maxno'] : 0;
$next    = $maxno + 1;

$expiresAtDb   = date('Y-m-d H:i:s', strtotime('+24 hours'));
$expiresAtJson = date('c', strtotime('+24 hours'));

// Generate first leaseId up-front
$uuidRow      = ncmExecute("SELECT gen_random_uuid() AS id", false);
$firstLeaseId = $uuidRow['id'];

for ($i = $next; $i < $next + $count; $i++) {
    if ($i === $next) {
        $lid = $firstLeaseId;
    } else {
        $lidRow = ncmExecute("SELECT gen_random_uuid() AS id", false);
        $lid    = $lidRow['id'];
    }

    ncmExecute(
        'INSERT INTO "numbering_lease" ("leaseId","companyId","outletId","registerId","invoiceNo","expiresAt") VALUES (?,?,?,?,?,?)',
        [$lid, $compId, $authCtx['outletId'], $regId, $i, $expiresAtDb]
    );
}

apiOk([
    'from'      => $next,
    'to'        => $next + $count - 1,
    'leaseId'   => $firstLeaseId,
    'expiresAt' => $expiresAtJson,
]);
