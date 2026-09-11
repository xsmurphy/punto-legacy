<?php
declare(strict_types=1);

namespace Punto\Api\Outlets;

// Este servicio lo usan los DOS realms y el `admin` no pasa por
// `api/bootstrap.php`, así que no tiene sus funciones globales. Las clases las
// resuelve el autoloader compartido (`api/autoload.php`, cargado desde
// `includes/db.php`); lo que hay que traer a mano son los archivos de
// FUNCIONES, que ningún autoloader puede resolver: `ncmExecute`/`ncmRows`/
// `ncmInsert` y `realtimePublish()`. `functions.php` no tiene efectos de
// carga — son requires y declaraciones de funciones.
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/realtime.php';

use Punto\Api\Notifications\TenantNotice;

/**
 * Solicitudes de alta de sucursal (tabla `outlet_request`, mig 219).
 *
 * ── Por qué existe ──────────────────────────────────────────────────────
 * Cada sucursal se factura al PRECIO DEL PLAN del tenant por mes (owner,
 * 2026-09-11). Crear una sucursal es entonces un hecho COMERCIAL: el comercio
 * la PIDE desde el panel y Punto la aprueba desde /admin. Nadie se
 * auto-aprovisiona facturación.
 *
 * ── Un solo servicio para las dos superficies ───────────────────────────
 * Hay métodos de realm `panel` (pedir, consultar el estado) y de realm
 * `admin` (listar la cola, resolver). Viven juntos A PROPÓSITO: son la misma
 * tabla y el mismo invariante ("una sola pendiente por empresa"). Partirlo en
 * dos servicios —como están hoy `BillingService` y `CompanyAdminService` para
 * `billing_request`— duplica el SQL de la tabla en dos archivos y es cómo se
 * llega a que uno de los dos aprenda una regla que el otro no. El gate de
 * realm es de los ENDPOINTS, no de este servicio, que no autentica a nadie.
 *
 * ── El alta real la hace `OutletsService` ───────────────────────────────
 * `resolve(approve: true)` llama a `OutletsService::create()`, nunca a un
 * INSERT propio: ese servicio es el que encadena depósito default + caja, y
 * la cadena es un invariante con arnés propio
 * (`api/tests/outlet_chain_invariant_test.php`).
 */
final class OutletRequestService
{
    /** Estados posibles de una solicitud. */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // ══════════════════════════════════════════════════════════════════════
    // Realm PANEL — lo que ve y hace el comercio
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Estado que necesita el panel para pintar la entrada del switcher y el
     * diálogo del paywall: la solicitud pendiente (si hay) + el precio que
     * gobierna el costo de la sucursal nueva.
     *
     * Va todo en UNA respuesta y bajo el permiso de sucursales a propósito.
     * El precio del plan también lo devuelve `/v1/billing`, pero ese endpoint
     * exige `billing.view` — un permiso que el encargado que administra
     * sucursales puede perfectamente no tener. Pedirle dos permisos para ver
     * un diálogo de alta sería cambiar el modelo de permisos por una comodidad
     * de implementación.
     *
     * @return array{
     *   pending: array<string,mixed>|null,
     *   outletCount: int,
     *   plan: array{code:int, name:string, price:float|null},
     *   currentMonthly: float|null,
     *   nextMonthly: float|null
     * }
     */
    public function status(string $companyId): array
    {
        $pending = $this->pending($companyId);

        $plan = $this->planPricing($companyId);

        // Sucursales ACTIVAS: son las que se facturan. Una dada de baja no
        // ocupa lugar en la cuenta, así que tampoco puede inflar el total que
        // se le muestra al comercio.
        $countRow = ncmExecute(
            'SELECT count(*)::int AS n FROM outlet WHERE companyId = ? AND outletStatus = 1',
            [$companyId]
        );
        $outletCount = (int) ($countRow['n'] ?? 0);

        $price = $plan['price'];

        return [
            'pending'        => $pending,
            'outletCount'    => $outletCount,
            'plan'           => $plan,
            // `null` cuando el plan no tiene precio (trial / plan 0). El front
            // NO inventa un monto en ese caso — ver el docblock de planPricing.
            'currentMonthly' => $price === null ? null : $price * max(1, $outletCount),
            'nextMonthly'    => $price === null ? null : $price * (max(1, $outletCount) + 1),
        ];
    }

    /**
     * La solicitud pendiente de la empresa, o null.
     *
     * @return array<string,mixed>|null
     */
    public function pending(string $companyId): ?array
    {
        $rows = ncmRows(
            "SELECT id, name, address, status, createdAt, requestedByName
               FROM outlet_request
              WHERE companyId = ? AND status = ?
              ORDER BY createdAt DESC
              LIMIT 1",
            [$companyId, self::STATUS_PENDING]
        );

        if ($rows === []) {
            return null;
        }

        return $this->shapeTenantRow($rows[0]);
    }

    /**
     * Crea la solicitud. NO crea la sucursal.
     *
     * El invariante "una sola pendiente" lo garantiza el índice único parcial
     * de la mig 219, no este `if`: dos pestañas del panel apretando a la vez
     * pasan cualquier chequeo de aplicación. El SELECT previo existe solo para
     * devolver un mensaje humano en el caso común; la violación del índice se
     * traduce igual a 409.
     *
     * @return array{ok:bool, requestId?:string, error?:string, code?:int}
     */
    public function create(
        string $companyId,
        ?string $userId,
        string $name,
        ?string $address = null
    ): array {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'El nombre de la sucursal es requerido', 'code' => 422];
        }
        // Sin `strip_tags()`: el nombre y la dirección terminan siendo los de
        // la SUCURSAL (`outlet.outletName` / `data.outletAddress`) y tienen que
        // ser idénticos a lo que el comercio escribió — `OutletsService::update()`
        // tampoco los toca. El escape es responsabilidad del render, no del
        // storage. Acá solo se acota al largo de la columna.
        $name    = mb_substr($name, 0, 255);
        $address = ($address !== null && trim($address) !== '')
            ? mb_substr(trim($address), 0, 1000)
            : null;

        if ($this->pending($companyId) !== null) {
            return [
                'ok'    => false,
                'error' => 'Ya tenés una solicitud de sucursal pendiente',
                'code'  => 409,
            ];
        }

        $requestedByName = $this->userDisplayName($companyId, $userId);

        try {
            $id = ncmInsert([
                'table'   => 'outlet_request',
                'records' => [
                    'companyId'       => $companyId,
                    'requestedBy'     => $userId !== null && $userId !== '' ? $userId : null,
                    'requestedByName' => $requestedByName,
                    'name'            => $name,
                    'address'         => $address,
                    'status'          => self::STATUS_PENDING,
                ],
            ]);
        } catch (\Punto\Api\Support\DbQueryException $e) {
            // La carrera que el SELECT de arriba NO puede cerrar: dos requests
            // simultáneas, las dos ven "no hay pendiente", la segunda choca
            // contra el índice único parcial. 23505 = unique_violation.
            //
            // El wrapper LANZA en vez de devolver false (context/08), así que
            // esto no es defensa de más: sin el catch, la segunda pestaña del
            // comercio recibe un 500 por una situación perfectamente normal.
            if ($e->sqlState() === '23505') {
                return ['ok' => false, 'error' => 'Ya tenés una solicitud de sucursal pendiente', 'code' => 409];
            }
            throw $e;
        }

        if (!$id) {
            return ['ok' => false, 'error' => 'No se pudo registrar la solicitud', 'code' => 500];
        }

        return ['ok' => true, 'requestId' => (string) $id];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Realm ADMIN — la cola de Punto
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cola de solicitudes para /admin.
     *
     * @param string      $status    'pending' | 'approved' | 'rejected' | '' (todas)
     * @param string|null $companyId Acota a una empresa (ficha del tenant). null = global.
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(string $status = self::STATUS_PENDING, ?string $companyId = null): array
    {
        $allowed = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, ''];
        if (!in_array($status, $allowed, true)) {
            $status = self::STATUS_PENDING;
        }

        $where  = [];
        $params = [];
        if ($status !== '') {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }
        if ($companyId !== null && $companyId !== '') {
            $where[]  = 'r.companyId = ?';
            $params[] = $companyId;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $rows = ncmRows(
            "SELECT r.id, r.companyId, r.requestedBy, r.requestedByName, r.name, r.address,
                    r.status, r.reason, r.createdAt, r.resolvedAt, r.resolvedBy, r.outletId,
                    c.config->>'companyName' AS companyName
               FROM outlet_request r
               JOIN company c ON c.companyId = r.companyId
               $whereSql
              ORDER BY r.createdAt DESC
              LIMIT 200",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'              => (string) ($r['id'] ?? ''),
                'companyId'       => (string) ($r['companyid'] ?? $r['companyId'] ?? ''),
                'companyName'     => (string) ($r['companyname'] ?? $r['companyName'] ?? ''),
                'requestedBy'     => $this->nullableString($r['requestedby'] ?? $r['requestedBy'] ?? null),
                'requestedByName' => $this->nullableString($r['requestedbyname'] ?? $r['requestedByName'] ?? null),
                'name'            => (string) ($r['name'] ?? ''),
                'address'         => $this->nullableString($r['address'] ?? null),
                'status'          => (string) ($r['status'] ?? ''),
                'reason'          => $this->nullableString($r['reason'] ?? null),
                'createdAt'       => $this->nullableString($r['createdat'] ?? $r['createdAt'] ?? null),
                'resolvedAt'      => $this->nullableString($r['resolvedat'] ?? $r['resolvedAt'] ?? null),
                'resolvedBy'      => $this->nullableString($r['resolvedby'] ?? $r['resolvedBy'] ?? null),
                'outletId'        => $this->nullableString($r['outletid'] ?? $r['outletId'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Aprueba o rechaza. Al aprobar CREA la sucursal por el servicio real.
     *
     * ── Una sola transacción, sin compensación ──────────────────────────
     * `DB::StartTrans()` anida por profundidad (`transDepth`), así que el
     * `StartTrans()` interno de `OutletsService::create()` entra en ESTA
     * transacción y commitea con ella. O se crean la sucursal + su depósito +
     * su caja Y la solicitud queda `approved`, o no pasa nada. La alternativa
     * —marcar aprobada y después crear— obliga a compensar a mano un fallo del
     * alta, que es la clase de código que nadie prueba hasta que hace falta.
     *
     * El `FOR UPDATE` serializa dos admins resolviendo la misma solicitud: el
     * segundo lee `approved` y se va por el 409.
     *
     * El aviso al comercio se manda DESPUÉS del commit: un email no se puede
     * hacer rollback, y mandarlo dentro de la transacción significa avisar de
     * una sucursal que todavía puede no existir.
     *
     * @param string|null $reason Motivo. OBLIGATORIO al rechazar.
     * @return array{ok:bool, status?:string, outletId?:string|null, error?:string, code?:int}
     */
    public function resolve(string $requestId, bool $approve, ?string $reason, string $resolvedBy): array
    {
        global $db;

        $reason = $reason !== null ? trim(strip_tags($reason)) : '';
        if (!$approve && $reason === '') {
            return ['ok' => false, 'error' => 'El motivo del rechazo es obligatorio', 'code' => 422];
        }
        $reason = $reason !== '' ? mb_substr($reason, 0, 1000) : null;

        if ($resolvedBy === '') {
            $resolvedBy = 'admin';
        }

        $companyId = '';
        $outletId  = null;
        $newStatus = $approve ? self::STATUS_APPROVED : self::STATUS_REJECTED;
        $reqName   = '';

        $db->StartTrans();
        try {
            // `$rs === false` no puede darse hoy (el wrapper LANZA ante un
            // error de SQL, ver context/08) y por eso no se chequea: lo que sí
            // se chequea es el EOF, que es el "no existe" legítimo.
            $rs = $db->Execute(
                'SELECT id, companyId, name, address, status
                   FROM outlet_request
                  WHERE id = ?
                  LIMIT 1
                    FOR UPDATE',
                [$requestId]
            );
            if ($rs->EOF) {
                $db->FailTrans();
                $db->CompleteTrans();
                return ['ok' => false, 'error' => 'Solicitud no encontrada', 'code' => 404];
            }

            $f   = $rs->fields;
            $get = fn(string $k) => $f[$k] ?? $f[strtolower($k)] ?? null;

            $current = (string) ($get('status') ?? '');
            if ($current !== self::STATUS_PENDING) {
                $db->FailTrans();
                $db->CompleteTrans();
                return ['ok' => false, 'error' => "La solicitud ya fue resuelta ($current)", 'code' => 409];
            }

            $companyId = (string) $get('companyId');
            $reqName   = (string) ($get('name') ?? '');
            $reqAddr   = $get('address');

            if ($approve) {
                // El alta REAL. Encadena depósito default + caja.
                $svc      = new OutletsService();
                $fields   = ['name' => $reqName];
                if ($reqAddr !== null && (string) $reqAddr !== '') {
                    $fields['address'] = (string) $reqAddr;
                }
                $outletId = $svc->create($companyId, $fields, OutletsService::ORIGIN_REQUEST_APPROVAL);

                if (!$outletId) {
                    throw new \RuntimeException('El alta de la sucursal no devolvió id');
                }
            }

            $db->Execute(
                'UPDATE outlet_request
                    SET status = ?, reason = ?, resolvedAt = now(), resolvedBy = ?, outletId = ?
                  WHERE id = ?',
                [$newStatus, $reason, mb_substr($resolvedBy, 0, 120), $outletId, $requestId]
            );

            if (!$db->CompleteTrans()) {
                error_log('[outlet_request] resolve falló al commitear: ' . ($db->FirstError() ?: 'sin detalle'));
                return ['ok' => false, 'error' => 'No se pudo resolver la solicitud', 'code' => 500];
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            // Defensa: si `CompleteTrans()` llegó acá con la profundidad ya en
            // 0 (un throw DENTRO del commit), es un no-op y la transacción PG
            // quedaría abierta para el resto del request.
            if ($db->HasOpenTransaction()) {
                $db->RollbackTrans();
            }

            // El gate de origen del creador es una regla de NEGOCIO, no un
            // fallo: se devuelve tal cual y con 422. Meterlo en el 500 genérico
            // escondería el único mensaje que explica qué pasó.
            if ($e instanceof \DomainException) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 422];
            }

            // El texto del driver va al log, no al cliente: filtra el schema.
            error_log('[outlet_request] resolve falló: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo resolver la solicitud', 'code' => 500];
        }

        // ── Después del commit ──────────────────────────────────────────
        $this->notifyResolution($companyId, $reqName, $approve, $reason);

        if ($approve) {
            // Regla base del proyecto: toda mutación de datos del tenant se
            // propaga a sus dispositivos. Acá el companyId va EXPLÍCITO — el
            // realm admin no define COMPANY_ID y sin el argumento el publish
            // se silencia (o peor, saldría por el tenant equivocado).
            realtimePublish('outlet', 'create', (string) $outletId, 'all', $companyId);
            realtimePublish('register', 'create', null, 'all', $companyId);
        }
        realtimePublish('outletRequest', 'update', $requestId, 'all', $companyId);

        return ['ok' => true, 'status' => $newStatus, 'outletId' => $outletId];
    }

    // ══════════════════════════════════════════════════════════════════════
    // privados
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Precio vigente del plan del tenant.
     *
     * Una sola query contra la MISMA fuente que `/admin` y `/v1/billing`:
     * `company.plan = plans.plan_code`. No se instancia `BillingService` para
     * esto porque su `summary()` trae pagos, add-ons, uso y créditos IA — todo
     * lo que este diálogo no necesita, con los permisos que no tiene.
     *
     * `price = null` (y no 0) cuando el tenant está en un plan sin precio
     * —trial, plan 0, plan borrado— porque el front tiene que poder distinguir
     * "sale gratis" de "todavía no sabemos cuánto sale". Inventar un monto en
     * un diálogo que promete una cifra de facturación es peor que no mostrarlo.
     *
     * OJO (deuda heredada, no introducida acá): `plans.price` NO tiene moneda
     * propia. El panel de "Mi plan" lo rotula USD y /admin lo rotula Gs — el
     * mismo número. Acá se usa la moneda del TENANT, que es lo que el owner
     * pidió y lo único que el bootstrap ofrece hoy.
     *
     * @return array{code:int, name:string, price:float|null}
     */
    private function planPricing(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT c.plan, p.name, p.price
               FROM company c
               LEFT JOIN plans p ON p.plan_code = c.plan
              WHERE c.companyId = ?
              LIMIT 1',
            [$companyId]
        );

        $code  = (int) ($row['plan'] ?? 0);
        $name  = (string) ($row['name'] ?? '');
        $price = isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null;

        if ($price !== null && $price <= 0) {
            $price = null;
        }

        return ['code' => $code, 'name' => $name, 'price' => $price];
    }

    /** Nombre del usuario que pidió, snapshoteado al crear la solicitud. */
    private function userDisplayName(string $companyId, ?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $row = ncmExecute(
            'SELECT contactName, contactSecondName
               FROM contact
              WHERE contactId = ? AND companyId = ?
              LIMIT 1',
            [$userId, $companyId]
        );
        if (!$row) {
            return null;
        }

        $name = trim(
            (string) ($row['contactName'] ?? '') . ' ' . (string) ($row['contactSecondName'] ?? '')
        );

        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    /**
     * Avisa al comercio el resultado. Best-effort por diseño: el resultado de
     * la solicitud ya está commiteado y un email caído no puede volver atrás
     * una sucursal creada.
     */
    private function notifyResolution(string $companyId, string $outletName, bool $approve, ?string $reason): void
    {
        try {
            $to = TenantNotice::ownerEmail($companyId);
            if ($to === null) {
                return;
            }

            if ($approve) {
                $subject = 'Tu nueva sucursal ya está activa';
                $text    = "Aprobamos la solicitud de la sucursal \"$outletName\".\n\n"
                    . "Ya podés verla en el selector de sucursales del panel, con su depósito y su caja.\n"
                    . "El costo de la sucursal se suma a tu facturación mensual.";
            } else {
                $subject = 'Sobre tu solicitud de sucursal';
                $text    = "No pudimos aprobar la solicitud de la sucursal \"$outletName\".\n\n"
                    . 'Motivo: ' . ($reason ?? 'sin detalle') . "\n\n"
                    . 'Si necesitás revisarlo, respondé este correo.';
            }

            $res = TenantNotice::sendEmail($to, $subject, $text);
            if ($res !== true) {
                error_log("[outlet_request] aviso no enviado a " . TenantNotice::maskEmail($to) . ": $res");
            }
        } catch (\Throwable $e) {
            // Best-effort, pero NUNCA mudo: la solicitud ya está resuelta y el
            // comercio se quedó sin enterarse. Un catch sin log convierte eso
            // en invisible (la convención del proyecto, ver `DB.php`).
            error_log('[outlet_request] aviso de resolución falló: ' . $e->getMessage());
        }
    }

    /** Fila de la solicitud tal como la ve el COMERCIO (sin datos internos). */
    private function shapeTenantRow(mixed $r): array
    {
        return [
            'id'              => (string) ($r['id'] ?? ''),
            'name'            => (string) ($r['name'] ?? ''),
            'address'         => $this->nullableString($r['address'] ?? null),
            'status'          => (string) ($r['status'] ?? ''),
            'createdAt'       => $this->nullableString($r['createdat'] ?? $r['createdAt'] ?? null),
            'requestedByName' => $this->nullableString($r['requestedbyname'] ?? $r['requestedByName'] ?? null),
        ];
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;

        return $s !== '' ? $s : null;
    }
}
