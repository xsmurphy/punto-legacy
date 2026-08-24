<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

use Punto\Api\PaymentMethods\PaymentMethodResolver;
use Punto\Api\Support\TenantClock;

/**
 * CRUD de movimientos de Finanzas (`fin_movement`) — el ledger de caja simple
 * (single-entry, NO partida doble). Actualiza `fin_account.currentbalance`
 * transaccionalmente en cada insert/void.
 *
 * Idempotencia: el UNIQUE (companyid, source, sourceid) WHERE sourceid IS NOT
 * NULL evita duplicar movimientos derivados (Fase 3). Las cargas manuales no
 * traen sourceid → no aplica el UNIQUE.
 *
 * Multi-tenant: $companyId siempre explícito (§33.2).
 */
final class MovementService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const KINDS = ['income', 'expense'];

    /**
     * SELECT list compartido por `list()`, `find()` y `transfer()` — resuelve
     * los nombres de cuenta/categoría/centro de costo y el medio de pago.
     *
     * Estaba copiado tres veces con la misma cadena de LEFT JOIN; sumarle el
     * centro de costo (mig 167) habría hecho una cuarta copia. Se centraliza
     * para que un JOIN nuevo entre en un solo lugar.
     *
     * `c.code`/`cc.code` viajan al front porque el export XLSX del listado
     * tiene que llevarlos: matchear contra el sistema del contador es el
     * PUNTO del campo, y un export sin el código no sirve para eso.
     */
    private const SELECT_LIST = "SELECT m.*,
                   a.name  AS accountname,
                   c.name  AS categoryname,
                   c.code  AS categorycode,
                   cc.name AS costcentername,
                   cc.code AS costcentercode,
                   t.taxonomyname AS paymentmethodname
              FROM fin_movement m
              LEFT JOIN fin_account a ON a.accountid = m.accountid
              LEFT JOIN fin_category c ON c.categoryid = m.categoryid
              LEFT JOIN fin_cost_center cc ON cc.costcenterid = m.costcenterid
              LEFT JOIN taxonomy t ON t.taxonomyid::text = m.paymentmethod
                                   AND t.companyid = m.companyid
                                   AND t.taxonomytype = 'paymentMethod'";

    /**
     * Lista movimientos con filtros + paginación.
     * @param array{accountId?:string,categoryId?:string,costCenterId?:string,kind?:string,from?:string,to?:string,q?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'm.companyid = ? AND m.status = 1';
        $params = [$companyId];

        if (!empty($filters['accountId']) && preg_match(self::UUID_RE, $filters['accountId'])) {
            $where   .= ' AND m.accountid = ?';
            $params[] = $filters['accountId'];
        }
        if (!empty($filters['categoryId'])) {
            // 'none' es un filtro explícito, no un UUID: expone los
            // movimientos sin clasificar (excluye 'transfer', que legítima-
            // mente no lleva categoría — mover plata entre cuentas propias no
            // es ni gasto ni ingreso real, ver `transfer()`).
            if ($filters['categoryId'] === 'none') {
                $where .= " AND m.categoryid IS NULL AND m.source != 'transfer'";
            } elseif (preg_match(self::UUID_RE, $filters['categoryId'])) {
                $where   .= ' AND m.categoryid = ?';
                $params[] = $filters['categoryId'];
            }
        }
        if (!empty($filters['costCenterId'])) {
            // 'none' expone los movimientos SIN imputar — el filtro que hace
            // usable la decisión del owner de que el centro sea opcional: los
            // históricos quedan sin asignar y hay que poder encontrarlos para
            // clasificarlos después (ver `reclassify()`).
            //
            // Excluye source='transfer' por el mismo motivo que el filtro de
            // categoría: mover plata entre cuentas propias no se imputa a
            // ningún centro (no es gasto de nadie), así que una transferencia
            // JAMÁS va a salir de esta lista. Dejarla adentro llenaría de ruido
            // permanente la única vista que sirve para saber qué falta
            // clasificar.
            if ($filters['costCenterId'] === 'none') {
                $where .= " AND m.costcenterid IS NULL AND m.source != 'transfer'";
            } elseif (preg_match(self::UUID_RE, $filters['costCenterId'])) {
                $where   .= ' AND m.costcenterid = ?';
                $params[] = $filters['costCenterId'];
            }
        }
        if (!empty($filters['kind']) && in_array($filters['kind'], self::KINDS, true)) {
            $where   .= ' AND m.kind = ?';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['from'])) {
            $where   .= ' AND m.date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where   .= ' AND m.date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where   .= ' AND m.description ILIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit']))  : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM fin_movement m WHERE {$where}", $params);
        $total = (int) ($totalRow['n'] ?? 0);

        // El LEFT JOIN taxonomy de SELECT_LIST resuelve paymentmethod → nombre
        // real cuando es un taxonomyId (ventas nuevas, UUID); las claves legacy
        // ('efectivo', etc.) no matchean ahí y se resuelven en shape() vía
        // PaymentMethodResolver (bug 2026-07-30: el listado mostraba el UUID
        // crudo en "Medio de pago").
        $sql = self::SELECT_LIST . "
                 WHERE {$where}
                 ORDER BY m.date DESC, m.created_at DESC
                 LIMIT {$limit} OFFSET {$offset}";

        $rs = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields, $companyId);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Totales de ingresos/egresos del período vía agregación SQL — evita
     * truncar en tenants de alto volumen (list() cappea en 500 filas).
     *
     * @return array{income:float,expense:float,netFlow:float}
     */
    public function totalsByKind(string $companyId, string $from, string $to): array
    {
        $row = ncmExecute(
            "SELECT COALESCE(SUM(amount) FILTER (WHERE kind = 'income'), 0) AS income,
                    COALESCE(SUM(amount) FILTER (WHERE kind = 'expense'), 0) AS expense
               FROM fin_movement
              WHERE companyid = ? AND status = 1 AND date BETWEEN ? AND ?",
            [$companyId, $from, $to]
        );
        $income  = (float) ($row['income'] ?? 0);
        $expense = (float) ($row['expense'] ?? 0);
        return ['income' => $income, 'expense' => $expense, 'netFlow' => $income - $expense];
    }

    /**
     * Reporte "Por categoría": ingresos/egresos/neto del período agrupados
     * por categoría (agregación SQL, no trunca como list()). Incluye una fila
     * "Sin categoría" (id null) para movimientos con categoryid NULL.
     *
     * @return list<array{id:?string,name:string,income:float,expense:float,net:float}>
     */
    public function totalsByCategory(string $companyId, string $from, string $to): array
    {
        $rs = ncmExecute(
            "SELECT c.categoryid, c.name, m.kind, COALESCE(SUM(m.amount), 0) AS total
               FROM fin_movement m
               LEFT JOIN fin_category c ON c.categoryid = m.categoryid
              WHERE m.companyid = ? AND m.status = 1 AND m.date BETWEEN ? AND ?
              GROUP BY c.categoryid, c.name, m.kind",
            [$companyId, $from, $to],
            false,
            true
        );
        return $this->pivotByKind($rs, 'categoryid', 'Sin categoría');
    }

    /**
     * Reporte "Por cuenta": ingresos/egresos/neto del período agrupados por
     * cuenta. Análogo a totalsByCategory().
     *
     * @return list<array{id:?string,name:string,income:float,expense:float,net:float}>
     */
    public function totalsByAccount(string $companyId, string $from, string $to): array
    {
        $rs = ncmExecute(
            "SELECT a.accountid, a.name, m.kind, COALESCE(SUM(m.amount), 0) AS total
               FROM fin_movement m
               LEFT JOIN fin_account a ON a.accountid = m.accountid
              WHERE m.companyid = ? AND m.status = 1 AND m.date BETWEEN ? AND ?
              GROUP BY a.accountid, a.name, m.kind",
            [$companyId, $from, $to],
            false,
            true
        );
        return $this->pivotByKind($rs, 'accountid', 'Sin cuenta');
    }

    /**
     * Pivotea filas {idcol, name, kind, total} en {id, name, income, expense, net}
     * agrupando por id. Reusado por totalsByCategory()/totalsByAccount().
     */
    private function pivotByKind($rs, string $idCol, string $nullLabel): array
    {
        $byId = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $id = $f[$idCol] !== null ? (string) $f[$idCol] : null;
                $key = $id ?? '__null__';
                if (!isset($byId[$key])) {
                    $byId[$key] = [
                        'id'      => $id,
                        'name'    => $id !== null ? (string) $f['name'] : $nullLabel,
                        'income'  => 0.0,
                        'expense' => 0.0,
                    ];
                }
                $kind = (string) $f['kind'];
                if ($kind === 'income' || $kind === 'expense') {
                    $byId[$key][$kind] += (float) $f['total'];
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        $rows = [];
        foreach ($byId as $row) {
            $rows[] = [
                'id'      => $row['id'],
                'name'    => $row['name'],
                'income'  => $row['income'],
                'expense' => $row['expense'],
                'net'     => $row['income'] - $row['expense'],
            ];
        }
        // Sin categoría/cuenta al final, resto alfabético.
        usort($rows, function ($a, $b) {
            if ($a['id'] === null) return 1;
            if ($b['id'] === null) return -1;
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute(
            self::SELECT_LIST . ' WHERE m.movementid = ? AND m.companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        return $row ? $this->shape($row, $companyId) : null;
    }

    /**
     * Crea un movimiento manual (entrada o salida). Actualiza el saldo de la
     * cuenta en la misma transacción.
     *
     * @param array{accountId:string,categoryId?:string|null,costCenterId?:string|null,kind:string,amount:float|string,date?:string,description?:string,paymentMethod?:string,userId?:string,outletId?:string} $data
     */
    public function create(string $companyId, array $data): array
    {
        global $db;

        $accountId = (string) ($data['accountId'] ?? '');
        if (!preg_match(self::UUID_RE, $accountId)) {
            throw new \RuntimeException('accountId requerido y debe ser UUID');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('kind debe ser income o expense');
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto debe ser mayor a cero');
        }
        // Categoría opcional (decisión del owner 2026-08-20): no bloquea la
        // carga. Si viene, valida que exista; si no, el movimiento queda sin
        // clasificar y aparece en el filtro "Sin categoría" del listado.
        $categoryId = (string) ($data['categoryId'] ?? '');
        $categoryId = ($categoryId !== '' && preg_match(self::UUID_RE, $categoryId)) ? $categoryId : null;
        if ($categoryId !== null && !(new CategoryService())->find($categoryId, $companyId)) {
            throw new \RuntimeException('Categoría no encontrada');
        }

        $costCenterId = $this->resolveCostCenterId($companyId, $data['costCenterId'] ?? null);

        $account = (new AccountService())->find($accountId, $companyId);
        if (!$account) {
            throw new \RuntimeException('Cuenta no encontrada');
        }

        $db->StartTrans();

        $movementId = $this->insertMovement($companyId, [
            'accountid'     => $accountId,
            'categoryid'    => $categoryId,
            'costcenterid'  => $costCenterId,
            'kind'          => $kind,
            'amount'        => $amount,
            'date'          => $this->normalizeDate($data['date'] ?? null, $companyId),
            'description'   => (string) ($data['description'] ?? '') ?: null,
            'paymentmethod' => (string) ($data['paymentMethod'] ?? '') ?: null,
            'source'        => 'manual',
            'userid'        => (string) ($data['userId'] ?? '') ?: null,
            'outletid'      => (string) ($data['outletId'] ?? '') ?: null,
        ]);

        $this->applyBalanceDelta($accountId, $companyId, $kind, $amount);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed || !$movementId) {
            throw new \RuntimeException('No se pudo registrar el movimiento');
        }

        $row = $this->find((string) $movementId, $companyId);
        if (!$row) {
            throw new \RuntimeException('Movimiento creado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Transferencia entre cuentas propias: 2 movimientos (egreso origen +
     * ingreso destino) con el mismo transfergroupid. Categoría null (una
     * transferencia no es ingreso/egreso real del negocio).
     *
     * @param array{fromAccountId:string,toAccountId:string,amount:float|string,date?:string,description?:string,userId?:string,outletId?:string} $data
     */
    public function transfer(string $companyId, array $data): array
    {
        global $db;

        $fromId = (string) ($data['fromAccountId'] ?? '');
        $toId   = (string) ($data['toAccountId'] ?? '');
        if (!preg_match(self::UUID_RE, $fromId) || !preg_match(self::UUID_RE, $toId)) {
            throw new \RuntimeException('fromAccountId y toAccountId requeridos');
        }
        if ($fromId === $toId) {
            throw new \RuntimeException('La cuenta de origen y destino no pueden ser la misma');
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto debe ser mayor a cero');
        }

        $accSvc = new AccountService();
        if (!$accSvc->find($fromId, $companyId) || !$accSvc->find($toId, $companyId)) {
            throw new \RuntimeException('Cuenta de origen o destino no encontrada');
        }

        $date        = $this->normalizeDate($data['date'] ?? null, $companyId);
        $description = (string) ($data['description'] ?? '') ?: null;
        $userId      = (string) ($data['userId'] ?? '') ?: null;
        $outletId    = (string) ($data['outletId'] ?? '') ?: null;
        $groupId     = $this->uuidV4();

        $db->StartTrans();

        $this->insertMovement($companyId, [
            'accountid'       => $fromId,
            'kind'            => 'expense',
            'amount'          => $amount,
            'date'            => $date,
            'description'     => $description ?? 'Transferencia entre cuentas',
            'source'          => 'transfer',
            'transfergroupid' => $groupId,
            'userid'          => $userId,
            'outletid'        => $outletId,
        ]);
        $this->applyBalanceDelta($fromId, $companyId, 'expense', $amount);

        $this->insertMovement($companyId, [
            'accountid'       => $toId,
            'kind'            => 'income',
            'amount'          => $amount,
            'date'            => $date,
            'description'     => $description ?? 'Transferencia entre cuentas',
            'source'          => 'transfer',
            'transfergroupid' => $groupId,
            'userid'          => $userId,
            'outletid'        => $outletId,
        ]);
        $this->applyBalanceDelta($toId, $companyId, 'income', $amount);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo registrar la transferencia');
        }

        $rs = ncmExecute(
            self::SELECT_LIST . ' WHERE m.transfergroupid = ? AND m.companyid = ?
              ORDER BY m.kind ASC',
            [$groupId, $companyId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields, $companyId);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return ['transferGroupId' => $groupId, 'movements' => $rows];
    }

    /**
     * Anula un movimiento manual (soft-void: status=0) y revierte el saldo.
     * Movimientos derivados (source != 'manual'/'transfer') no se pueden
     * anular desde acá — se anulan desde el módulo de origen (Fase 3).
     *
     * Transferencias (source='transfer'): anula AMBOS legs del mismo
     * transfergroupid en la misma transacción — anular solo uno dejaría un
     * movimiento "fantasma" visible y las dos cuentas con saldos
     * inconsistentes (bug real detectado en code review, fix antes de
     * exponer "anular" en la UI de transferencias).
     */
    public function void(string $id, string $companyId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $row = ncmExecute(
            'SELECT * FROM fin_movement WHERE movementid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Movimiento no encontrado');
        }
        if ((int) $row['status'] !== 1) {
            throw new \RuntimeException('El movimiento ya fue anulado');
        }
        $source = (string) $row['source'];
        if (!in_array($source, ['manual', 'transfer'], true)) {
            throw new \RuntimeException('Este movimiento se generó automáticamente — anulalo desde su origen');
        }

        // Transferencia: recolectar AMBOS legs del transfergroupid (activo=status 1).
        $legs = [$row];
        if ($source === 'transfer' && !empty($row['transfergroupid'])) {
            $rs = ncmExecute(
                'SELECT * FROM fin_movement WHERE transfergroupid = ? AND companyid = ? AND status = 1',
                [(string) $row['transfergroupid'], $companyId],
                false,
                true
            );
            $legs = [];
            if ($rs && is_object($rs)) {
                while (!$rs->EOF) {
                    $legs[] = $rs->fields;
                    $rs->MoveNext();
                }
                $rs->Close();
            }
            if (empty($legs)) {
                $legs = [$row];
            }
        }

        $db->StartTrans();
        foreach ($legs as $leg) {
            $legId        = (string) $leg['movementid'];
            $legAccountId = (string) $leg['accountid'];
            $legKind      = (string) $leg['kind'];
            $legAmount    = (float) $leg['amount'];
            $reverseKind  = $legKind === 'income' ? 'expense' : 'income';

            ncmExecute('UPDATE fin_movement SET status = 0 WHERE movementid = ? AND companyid = ?', [$legId, $companyId]);
            $this->applyBalanceDelta($legAccountId, $companyId, $reverseKind, $legAmount);
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo anular el movimiento');
        }

        return ['id' => $id, 'status' => 0];
    }

    /**
     * Inserta un movimiento derivado (source != 'manual'/'transfer') de forma
     * idempotente y aplica el delta de saldo SOLO si la fila se creó de
     * verdad. Reusado por `FinanceLedger` (hooks en vivo + backfill, Fase 3)
     * — es la misma primitiva que usan `create()`/`transfer()` acá arriba,
     * así que el saldo nunca se calcula dos veces con lógica distinta.
     *
     * Idempotencia: confía en el UNIQUE (companyid, source, sourceid, accountid)
     * WHERE sourceid IS NOT NULL (mig 73). `sourceId` es obligatorio acá —
     * los movimientos manuales/transferencias siguen pasando por
     * `insertMovement()` (sourceid NULL, no aplica el UNIQUE).
     *
     * @param array{accountId:string,categoryId?:string|null,costCenterId?:string|null,kind:string,amount:float,date?:string,description?:string,paymentMethod?:string,userId?:string,outletId?:string} $fields
     * @return array{inserted:bool,movementId:?string}
     */
    public function recordDerivedMovement(string $companyId, string $source, string $sourceId, array $fields): array
    {
        global $db;

        $accountId = (string) ($fields['accountId'] ?? '');
        if (!preg_match(self::UUID_RE, $accountId)) {
            throw new \RuntimeException('accountId requerido y debe ser UUID');
        }
        $kind = (string) ($fields['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('kind debe ser income o expense');
        }
        $amount = (float) ($fields['amount'] ?? 0);
        if ($amount <= 0) {
            return ['inserted' => false, 'movementId' => null];
        }
        // Categoría opcional (decisión del owner 2026-08-20): los callers
        // (FinanceLedger, LoanService) resuelven la mejor categoría que
        // puedan (default del ítem/categoría semilla) pero nunca es
        // bloqueante — si no hay ninguna, el movimiento queda sin clasificar.
        $categoryId = (string) ($fields['categoryId'] ?? '');
        $categoryId = ($categoryId !== '' && preg_match(self::UUID_RE, $categoryId)) ? $categoryId : null;
        // Centro de costo: opcional igual que la categoría, y NUNCA bloqueante
        // acá. Un movimiento derivado nace de un hecho ya consumado (una venta
        // cobrada, una compra pagada) — si el centro que trae el origen no
        // existe o está archivado, se imputa sin centro y se clasifica después
        // desde el panel. Tirar una excepción haría fallar el hook y dejaría el
        // ledger sin el movimiento, que es infinitamente peor que un
        // movimiento sin clasificar.
        $costCenterId = (string) ($fields['costCenterId'] ?? '');
        $costCenterId = ($costCenterId !== '' && preg_match(self::UUID_RE, $costCenterId)) ? $costCenterId : null;
        if ($costCenterId !== null && !(new CostCenterService())->isAssignable($costCenterId, $companyId)) {
            $costCenterId = null;
        }

        $movementId = $this->uuidV4();
        $date       = $this->normalizeDate($fields['date'] ?? null, $companyId);
        $userId     = (string) ($fields['userId'] ?? '') ?: null;
        $outletId   = (string) ($fields['outletId'] ?? '') ?: null;
        $description = (string) ($fields['description'] ?? '') ?: null;
        $paymentMethod = (string) ($fields['paymentMethod'] ?? '') ?: null;

        $db->StartTrans();

        // Idempotencia ATÓMICA: INSERT ... ON CONFLICT DO NOTHING RETURNING.
        // El UNIQUE (companyid,source,sourceid,accountid,COALESCE(categoryid,
        // <sentinel>)) de mig 153 es el árbitro — extiende el de mig 73 con
        // `categoryid` para permitir VARIAS filas por (origen, cuenta) cuando
        // una compra se divide por categoría (F0, owner 2026-08-20): antes
        // había 1 sola fila posible por (origen, cuenta), ahora puede haber
        // una por cada porción de categoría. El COALESCE trata "sin
        // categoría" (categoryid NULL) como un valor más a los fines de
        // unicidad — sin él, dos porciones "sin categoría" del mismo
        // reintento no colisionarían (NULL ≠ NULL en un UNIQUE normal) y
        // duplicarían saldo.
        // Si otra request/backfill ya insertó esta porción exacta (origen,
        // cuenta, categoría), el ON CONFLICT no crea fila y el RETURNING
        // viene vacío → NO aplicamos delta de saldo. Elimina la ventana
        // TOCTOU del patrón SELECT-luego-INSERT: el chequeo de existencia y
        // la inserción son una sola sentencia atómica.
        //
        // `costcenterid` NO entra en la clave del ON CONFLICT (ver mig 167):
        // una compra se parte por CATEGORÍA pero se imputa ENTERA a un solo
        // centro. Si estuviera en la clave, un reintento del hook que
        // resolviera un centro distinto crearía una fila nueva y duplicaría
        // saldo.
        $inserted = ncmExecute(
            'INSERT INTO fin_movement
                (movementid, companyid, accountid, categoryid, costcenterid, kind, amount, date,
                 description, paymentmethod, source, sourceid, userid, outletid, status)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?, ?, ?, ?, ?::uuid, ?, ?, 1)
             ON CONFLICT (companyid, source, sourceid, accountid, COALESCE(categoryid, \'00000000-0000-0000-0000-000000000000\'::uuid))
                 WHERE sourceid IS NOT NULL
             DO NOTHING
             RETURNING movementid',
            [
                $movementId, $companyId, $accountId, $categoryId, $costCenterId, $kind, $amount, $date,
                $description, $paymentMethod, $source, $sourceId, $userId, $outletId,
            ]
        );

        // RETURNING trae fila SOLO si el INSERT creó de verdad → delta una única vez.
        if ($inserted && !empty($inserted['movementid'])) {
            $this->applyBalanceDelta($accountId, $companyId, $kind, $amount);
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException("No se pudo registrar el movimiento derivado ({$source}/{$sourceId})");
        }

        if ($inserted && !empty($inserted['movementid'])) {
            return ['inserted' => true, 'movementId' => (string) $inserted['movementid']];
        }

        // Conflicto: ya existía ESTA porción exacta (origen, cuenta,
        // categoría) — IS NOT DISTINCT FROM trata NULL=NULL como true, igual
        // que el COALESCE del índice, para no traer una porción de OTRA
        // categoría del mismo (origen, cuenta) por error.
        $winner = ncmExecute(
            'SELECT movementid FROM fin_movement
              WHERE companyid = ? AND source = ? AND sourceid = ? AND accountid = ?
                AND categoryid IS NOT DISTINCT FROM ? LIMIT 1',
            [$companyId, $source, $sourceId, $accountId, $categoryId]
        );
        return ['inserted' => false, 'movementId' => $winner ? (string) $winner['movementid'] : null];
    }

    /**
     * Anula (soft-void) todos los movimientos activos de un origen derivado
     * + revierte el saldo. Reusado por `FinanceLedger::voidBySource()`.
     */
    public function voidBySource(string $companyId, string $source, string $sourceId): int
    {
        global $db;

        $rs = ncmExecute(
            'SELECT * FROM fin_movement WHERE companyid = ? AND source = ? AND sourceid = ? AND status = 1',
            [$companyId, $source, $sourceId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $rs->fields;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        if (empty($rows)) {
            return 0;
        }

        $db->StartTrans();
        foreach ($rows as $row) {
            $legId       = (string) $row['movementid'];
            $accountId   = (string) $row['accountid'];
            $kind        = (string) $row['kind'];
            $amount      = (float) $row['amount'];
            $reverseKind = $kind === 'income' ? 'expense' : 'income';

            ncmExecute('UPDATE fin_movement SET status = 0 WHERE movementid = ? AND companyid = ?', [$legId, $companyId]);
            $this->applyBalanceDelta($accountId, $companyId, $reverseKind, $amount);
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException("No se pudo anular los movimientos de {$source}/{$sourceId}");
        }

        return count($rows);
    }

    /**
     * RECLASIFICA un movimiento: cambia a qué categoría y a qué centro de
     * costo se imputa. NO toca monto, cuenta, kind ni fecha.
     *
     * Es la contraparte obligatoria de que el centro de costo sea OPCIONAL
     * (decisión del owner 2026-08-24): "los históricos quedan sin asignar y se
     * clasifican después". Sin esta operación esa decisión no cierra — los 696
     * movimientos de producción se quedarían sin centro para siempre y el
     * reporte por centro nunca cuadraría con la realidad.
     *
     * POR QUÉ ESTE RECORTE DE CAMPOS. Monto, cuenta y kind determinan el saldo
     * cacheado en `fin_account.currentbalance`; cambiarlos exigiría revertir y
     * reaplicar el delta, y en un movimiento DERIVADO además divergiría del
     * hecho que lo originó (la venta seguiría diciendo otra cosa). La
     * clasificación, en cambio, es metadata pura: no mueve un guaraní.
     *
     * POR QUÉ APLICA A MOVIMIENTOS DERIVADOS (a diferencia de `void()`, que
     * los rechaza). Anular un derivado sería mentirle al origen — la venta
     * existió. Reclasificarlo no: la categoría y el centro son una decisión
     * administrativa POSTERIOR al hecho, y justamente el grueso de lo que hay
     * que clasificar son compras y gastos de caja, que son todos derivados.
     * Un movimiento ANULADO (status != 1) sí se rechaza: reclasificar algo que
     * ya no cuenta en ningún reporte solo puede ser un error del operador.
     *
     * @param array{categoryId?:string|null,costCenterId?:string|null} $data
     */
    public function reclassify(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $row = ncmExecute(
            'SELECT status FROM fin_movement WHERE movementid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Movimiento no encontrado');
        }
        if ((int) $row['status'] !== 1) {
            throw new \RuntimeException('El movimiento está anulado: no se puede reclasificar');
        }

        $records = ['movementid' => $id];

        // Cada campo se toca SOLO si su clave viene en el payload — así un
        // PUT que solo asigna el centro de costo no borra la categoría que ya
        // había resuelto el hook de origen.
        if (array_key_exists('categoryId', $data)) {
            $categoryId = trim((string) ($data['categoryId'] ?? ''));
            $categoryId = ($categoryId !== '' && preg_match(self::UUID_RE, $categoryId)) ? $categoryId : null;
            if ($categoryId !== null && !(new CategoryService())->find($categoryId, $companyId)) {
                throw new \RuntimeException('Categoría no encontrada');
            }
            $records['categoryid'] = $categoryId;
        }
        if (array_key_exists('costCenterId', $data)) {
            $records['costcenterid'] = $this->resolveCostCenterId($companyId, $data['costCenterId']);
        }

        if (count($records) === 1) {
            throw new \RuntimeException('Nada que reclasificar');
        }

        ncmUpdate([
            'records'     => $records,
            'table'       => 'fin_movement',
            'where'       => 'movementid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]);

        $updated = $this->find($id, $companyId);
        if (!$updated) {
            throw new \RuntimeException('No se pudo releer el movimiento reclasificado');
        }
        return $updated;
    }

    /**
     * Reporte "Por centro de costo": ingresos/egresos/neto del período
     * agrupados por centro. Análogo a totalsByCategory()/totalsByAccount() —
     * misma agregación SQL y mismo pivot, así que la fila "Sin centro de
     * costo" (id null) sale sola y queda al final del listado.
     *
     * @return list<array{id:?string,name:string,income:float,expense:float,net:float}>
     */
    public function totalsByCostCenter(string $companyId, string $from, string $to): array
    {
        $rs = ncmExecute(
            "SELECT cc.costcenterid, cc.name, m.kind, COALESCE(SUM(m.amount), 0) AS total
               FROM fin_movement m
               LEFT JOIN fin_cost_center cc ON cc.costcenterid = m.costcenterid
              WHERE m.companyid = ? AND m.status = 1 AND m.date BETWEEN ? AND ?
              GROUP BY cc.costcenterid, cc.name, m.kind",
            [$companyId, $from, $to],
            false,
            true
        );
        return $this->pivotByKind($rs, 'costcenterid', 'Sin centro de costo');
    }

    // ── helpers internos ─────────────────────────────────────────────────

    /**
     * Valida el centro de costo que llega de un formulario del panel.
     *
     * A diferencia de `recordDerivedMovement()` —donde un centro inválido se
     * degrada a null para no tumbar el hook— acá SÍ lanza: hubo un humano
     * eligiendo en un selector, y guardar en silencio algo distinto de lo que
     * eligió es peor que el error.
     *
     * Rechaza los ARCHIVADOS: un centro dado de baja conserva su histórico
     * pero no recibe imputaciones nuevas (ver `CostCenterService::isAssignable`).
     */
    private function resolveCostCenterId(string $companyId, mixed $raw): ?string
    {
        $id = trim((string) ($raw ?? ''));
        if ($id === '') {
            return null;
        }
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('costCenterId inválido');
        }
        if (!(new CostCenterService())->isAssignable($id, $companyId)) {
            throw new \RuntimeException('Centro de costo no encontrado o archivado');
        }
        return $id;
    }

    private function insertMovement(string $companyId, array $fields)
    {
        return ncmInsert([
            'records' => array_merge(['companyid' => $companyId, 'status' => 1], $fields),
            'table'   => 'fin_movement',
        ]);
    }

    /** Suma (income) o resta (expense) el monto del cache currentbalance. */
    private function applyBalanceDelta(string $accountId, string $companyId, string $kind, float $amount): void
    {
        $sign = $kind === 'income' ? '+' : '-';
        ncmExecute(
            "UPDATE fin_account SET currentbalance = currentbalance {$sign} ? WHERE accountid = ? AND companyid = ?",
            [$amount, $accountId, $companyId]
        );
    }

    private function normalizeDate(?string $val, string $companyId): string
    {
        $val = $val !== null ? trim($val) : '';
        if ($val === '') {
            return TenantClock::now($companyId);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val . ' 00:00:00';
        }
        return $val;
    }

    /** '' y NULL colapsan a null — la UI pinta un guion, no una celda vacía. */
    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return $s !== '' ? $s : null;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * $companyId es opcional para no romper callers legacy que todavía no lo
     * pasan; sin él, paymentMethodName cae al valor crudo (mejor que un 500).
     */
    private function shape($f, string $companyId = ''): array
    {
        $paymentMethod = $f['paymentmethod'] !== null ? (string) $f['paymentmethod'] : null;
        $paymentMethodName = isset($f['paymentmethodname']) && $f['paymentmethodname'] !== null
            ? (string) $f['paymentmethodname']
            : null;
        // El JOIN por taxonomyId solo resuelve ventas nuevas (UUID). Claves
        // legacy ('efectivo', 'cheque', slugs del POS…) no matchean ahí —
        // se resuelven acá vía el mismo wrapper que usa el resto del sistema
        // (PaymentMethodResolver), sin duplicar el mapa de aliases.
        if ($paymentMethodName === null && $paymentMethod !== null && $paymentMethod !== '' && $companyId !== '') {
            $paymentMethodName = (new PaymentMethodResolver())->resolveMethodName($companyId, $paymentMethod);
        }

        return [
            'id'                 => (string) $f['movementid'],
            'accountId'          => (string) $f['accountid'],
            'accountName'        => $f['accountname'] !== null ? (string) $f['accountname'] : null,
            'categoryId'         => $f['categoryid'] !== null ? (string) $f['categoryid'] : null,
            'categoryName'       => $f['categoryname'] !== null ? (string) $f['categoryname'] : null,
            // Códigos contables externos (mig 167) — viajan al front para que
            // el export XLSX del listado los lleve; sin ellos el export no
            // sirve para matchear contra el sistema del contador.
            'categoryCode'       => $this->nullableString($f['categorycode'] ?? null),
            'costCenterId'       => isset($f['costcenterid']) && $f['costcenterid'] !== null ? (string) $f['costcenterid'] : null,
            'costCenterName'     => $this->nullableString($f['costcentername'] ?? null),
            'costCenterCode'     => $this->nullableString($f['costcentercode'] ?? null),
            'kind'               => (string) $f['kind'],
            'amount'             => (float) $f['amount'],
            'date'               => (string) $f['date'],
            'description'        => $f['description'] !== null ? (string) $f['description'] : null,
            'paymentMethod'      => $paymentMethod,
            // Nombre para mostrar — cae al valor crudo si no se pudo resolver
            // (mejor un slug legible que un campo vacío en la UI).
            'paymentMethodName'  => $paymentMethodName ?? $paymentMethod,
            'source'             => (string) $f['source'],
            'sourceId'           => $f['sourceid'] !== null ? (string) $f['sourceid'] : null,
            'transferGroupId'    => $f['transfergroupid'] !== null ? (string) $f['transfergroupid'] : null,
            'reconciled'         => (bool) $f['reconciled'],
            'status'             => (int) $f['status'],
        ];
    }
}
