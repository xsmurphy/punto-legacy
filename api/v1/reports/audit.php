<?php
/**
 * REST canónico (API compartida /api) — Auditoría de Acciones Tenant.
 *
 *   GET /v1/reports/audit?from=&to= → { rows: [...] }
 *
 * Solo para roles con privilegio (roleId !== 7). Scoped por companyId del JWT.
 * Resuelve nombre de usuario (contact.contactName) y sucursal (outlet.outletName).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Gate: solo roles con permiso de auditoría.
if (!hasPermission('reports.audit.view')) {
    apiError('No tenés permiso para esta acción (requiere: reports.audit.view)', 403);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

// Los alias camelCase NO son cosmética: `AuditRow` (frontend/hooks/use-reports.ts)
// declara createdAt/userId/outletId/targetId/companyId, y la columna "Fecha" de
// la tabla lee `accessorKey: "createdAt"`. PG devuelve las columnas con el
// nombre del catálogo —lowercase desde la mig 150—, así que sin alias la
// request salía 200 con N filas y la tabla pintaba la fecha vacía en todas.
//
// `meta` va como `metaJson` por una razón distinta y peor: `meta` es uno de los
// tres nombres que `Query::flattenJsonb()` desempaqueta y BORRA de la fila (junto
// con `data` y `config`). Pedida como `ta.meta`, la columna desaparecía del
// resultado y sus claves internas se colaban como columnas sueltas del row —
// una key del realm `api` mandaba un `keyId` al front y ningún `meta`. El alias
// lo saca de esa lista, que es la salida que el propio docblock de
// `Query::rawJsonb()` documenta para SQL bajo nuestro control.
//
// `createdAt` sale como ISO-8601 UTC con 'Z' y no como el `YYYY-MM-DD HH:MI:SS+00`
// crudo de PG: ese formato con espacio no es ISO válido y `new Date(v)` lo
// resuelve distinto según el browser (Invalid Date en varios). Con 'Z' explícito
// el browser lo convierte a la hora local del que mira — sin fijar ninguna zona
// en el backend.
$rs = ncmExecute(
    'SELECT
        ta.id,
        ta.companyid   AS "companyId",
        ta.userid      AS "userId",
        ta.outletid    AS "outletId",
        ta.realm,
        ta.method,
        ta.endpoint,
        ta.targetid    AS "targetId",
        ta.meta::text  AS "metaJson",
        ta.ip,
        to_char(ta.createdat AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\') AS "createdAt",
        c.contactName  AS "userName",
        o.outletName   AS "outletName"
     FROM tenant_audit ta
     LEFT JOIN contact c ON c.contactId = ta.userid   AND c.companyId = ta.companyid
     LEFT JOIN outlet  o ON o.outletId  = ta.outletid AND o.companyId = ta.companyid
     WHERE ta.companyid = ?
       AND ta.createdat BETWEEN ? AND ?
     ORDER BY ta.createdat DESC
     LIMIT 1000',
    [(string) COMPANY_ID, $from, $to],
    false,
    true  // forceObj = true → devuelve un recordset del wrapper, hay que iterarlo
);

// ncmExecute con forceObj=true devuelve el recordset, NO un array — materializar.
//
// Se arma cada fila campo por campo en vez de devolver `$rs->fields` crudo: esa
// fila es un CaseInsensitiveArray al que el wrapper le pudo haber mezclado
// claves, y lo que sale por la API es un contrato con el front, no el shape
// interno del DB layer. Explícito = lo que devuelve el endpoint es exactamente
// lo que `AuditRow` declara, ni una clave más.
$rows = [];
if ($rs && is_object($rs)) {
    while (!$rs->EOF) {
        $f = $rs->fields;

        // meta: `{}` y `[]` (lo que da json_encode([]) en la instrumentación
        // base de tenantAudit) significan lo mismo —no hay metadata— y el front
        // la tipa `Record<string, unknown> | null`. Se normaliza a null para que
        // "sin metadata" sea un solo valor y no tres.
        $metaDecoded = json_decode((string) ($f['metaJson'] ?? ''), true);
        $meta = (is_array($metaDecoded) && $metaDecoded !== []) ? $metaDecoded : null;

        $rows[] = [
            'id'         => $f['id'],
            'companyId'  => $f['companyId'],
            'userId'     => $f['userId'],
            'outletId'   => $f['outletId'],
            'realm'      => $f['realm'],
            'method'     => $f['method'],
            'endpoint'   => $f['endpoint'],
            'targetId'   => $f['targetId'],
            'meta'       => $meta,
            'ip'         => $f['ip'],
            'createdAt'  => $f['createdAt'],
            'userName'   => $f['userName'],
            'outletName' => $f['outletName'],
        ];
        $rs->MoveNext();
    }
}

apiOk(['rows' => $rows]);
