<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

/**
 * WalletService — núcleo de la wallet multi-nivel (context/74, F1).
 *
 * Expone TODAS las operaciones de la v1 (load, transfer, spend, refund,
 * adjust) y la jerarquía (setParent), para que la caja (F2) y el alta de hijos
 * con transferencias (F3) solo las cableen. Nadie más escribe `wallet_movement`.
 *
 * ── Saldo ──────────────────────────────────────────────────────────────────
 *
 * El saldo de (contacto, bolsillo) es la suma de sus movimientos. Se lee como
 * el `balanceafter` de la fila de mayor `seq` — O(1) por el índice
 * `idx_wallet_movement_balance` — y la BD garantiza que ese valor ES la suma
 * (trigger `trg_wallet_movement_chain`, mig 232). No hay campo `balance`
 * (§10).
 *
 * ── Concurrencia: chequear y escribir es UNA operación ─────────────────────
 *
 * Toda operación que mueve saldo toma `pg_advisory_xact_lock` por
 * (contacto, bolsillo) DENTRO de la transacción y recién después lee el saldo.
 * Dos cajas que debitan el mismo bolsillo a la vez se serializan: la segunda
 * lee el saldo que dejó la primera. Chequear en la caja y descontar después
 * es exactamente lo que §10 rechaza.
 *
 * La transferencia toma los DOS locks en orden determinístico (por la clave
 * del lock, no por quién es origen): dos transferencias cruzadas no se
 * esperan mutuamente.
 *
 * El lock es de transacción (`_xact_`): se suelta en el COMMIT de la
 * transacción MÁS EXTERNA. Cuando la caja (F2) llame a `spend()` dentro de la
 * transacción de la venta, el bolsillo queda tomado hasta que la venta entera
 * confirme — que es lo correcto: si la venta hace rollback, el débito se va
 * con ella y nadie leyó un saldo que no existió.
 *
 * ── Transacciones anidadas y realtime ──────────────────────────────────────
 *
 * Cada operación abre `StartTrans()`; el wrapper anida (solo el nivel externo
 * confirma). El aviso de tiempo real va FUERA de la transacción: si esta
 * operación es la externa, publica tras su commit; si corre dentro de otra
 * (la venta de F2), NO publica — el orquestador llama a `publishChange()`
 * después de SU commit. Notificar un débito que la venta puede revertir sería
 * un aviso fantasma (mismo criterio que `OrderCoreService::updateStatus()`).
 *
 * ── Alcance ────────────────────────────────────────────────────────────────
 *
 * Todo filtra por `companyId`. No hay alcance por sucursal: el saldo es del
 * COMERCIO (§3.1, "todo vive dentro de un comercio") — el mismo bolsillo se
 * consume en cualquier sucursal.
 *
 * ── Montos ─────────────────────────────────────────────────────────────────
 *
 * La aritmética se hace en CENTAVOS enteros, no en float: el chequeo "no deja
 * negativo" no puede depender de que 0.1 + 0.2 dé 0.3. La columna es
 * NUMERIC(15,2), así que dos decimales alcanzan para cualquier moneda del
 * tenant (las que no usan decimales simplemente no los tienen).
 */
final class WalletService
{
    public const TYPES          = ['load', 'transfer', 'spend', 'refund', 'adjust'];
    public const BILLING_MODES  = ['A', 'B'];
    /** Modo que implementa la v1 (§4, D1). B es F5. */
    public const BILLING_MODE_V1 = 'A';

    /** Tope del monto de UNA operación: el de la columna NUMERIC(15,2). */
    private const MAX_CENTS = 9_999_999_999_999;

    // ═══════════════════════════════════════════════════════════════════════
    // Bolsillos (catálogo del comercio)
    // ═══════════════════════════════════════════════════════════════════════

    /** @return list<array{id: string, name: string, active: bool, createdAt: string}> */
    public function listPockets(string $companyId, bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, active, created_at FROM wallet_pocket WHERE companyid = ?';
        if ($onlyActive) {
            $sql .= ' AND active';
        }
        $sql .= ' ORDER BY lower(name)';

        $rs  = $this->db()->Execute($sql, [$companyId]);
        $out = [];
        while ($rs && !$rs->EOF) {
            $out[] = $this->pocketRow($rs->fields);
            $rs->MoveNext();
        }
        return $out;
    }

    public function findPocket(string $companyId, string $pocketId): ?array
    {
        if (!self::isUuid($pocketId)) {
            return null;
        }
        $rs = $this->db()->Execute(
            'SELECT id, name, active, created_at FROM wallet_pocket WHERE id = ? AND companyid = ?',
            [$pocketId, $companyId]
        );
        return ($rs && !$rs->EOF) ? $this->pocketRow($rs->fields) : null;
    }

    public function createPocket(string $companyId, string $name): array
    {
        $name = $this->cleanPocketName($name);
        $this->assertPocketNameFree($companyId, $name, null);

        $rs = $this->db()->Execute(
            'INSERT INTO wallet_pocket (companyid, name) VALUES (?, ?) RETURNING id',
            [$companyId, $name]
        );
        $id = (string) $rs->fields['id'];

        realtimePublish('wallet', 'create', $id, 'all', $companyId);
        return $this->findPocket($companyId, $id) ?? [];
    }

    public function renamePocket(string $companyId, string $pocketId, string $name): array
    {
        $this->requirePocket($companyId, $pocketId);
        $name = $this->cleanPocketName($name);
        $this->assertPocketNameFree($companyId, $name, $pocketId);

        $this->db()->Execute(
            'UPDATE wallet_pocket SET name = ? WHERE id = ? AND companyid = ?',
            [$name, $pocketId, $companyId]
        );

        realtimePublish('wallet', 'update', $pocketId, 'all', $companyId);
        return $this->findPocket($companyId, $pocketId) ?? [];
    }

    /**
     * Activar / desactivar. No hay borrado: el bolsillo tiene historia. Uno
     * inactivo con saldo sigue mostrando ese saldo; lo que deja de poder es
     * recibir cargas, transferencias y pagos nuevos.
     */
    public function setPocketActive(string $companyId, string $pocketId, bool $active): array
    {
        $this->requirePocket($companyId, $pocketId);
        $this->db()->Execute(
            'UPDATE wallet_pocket SET active = ? WHERE id = ? AND companyid = ?',
            [$active, $pocketId, $companyId]
        );

        realtimePublish('wallet', 'update', $pocketId, 'all', $companyId);
        return $this->findPocket($companyId, $pocketId) ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Jerarquía (§3.1)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Pone (o saca, con `$parentId = null`) al contacto bajo un titular.
     *
     * Reglas: los dos son clientes del MISMO comercio, un solo nivel (el
     * titular no puede ser hijo de nadie y el hijo no puede tener hijos), sin
     * auto-referencia. La BD las vuelve a chequear (`trg_contact_parent_guard`
     * + CHECK, mig 232); acá se chequean antes para devolver un mensaje.
     *
     * Y una más, propia de la wallet: el contacto que cambia de lugar en la
     * jerarquía tiene que tener TODOS sus bolsillos en cero. Un titular con
     * saldo que pasa a hijo quedaría con plata "cargada desde afuera" en un
     * hijo, y un hijo con saldo que cambia de titular se llevaría plata de una
     * familia a otra: en los dos casos se pierde la trazabilidad que §3.1 pide
     * ("cada peso es rastreable desde que entró"). Se vacía antes con un
     * ajuste, con motivo.
     *
     * Toma el lock de CADA bolsillo del contacto antes de mirar los saldos, así
     * que un débito concurrente no puede colarse entre el chequeo y el cambio.
     */
    public function setParent(string $companyId, string $contactId, ?string $parentId, string $actorId): void
    {
        $db = $this->db();
        $this->requireActor($companyId, $actorId);

        $db->StartTrans();
        try {
            $contact = $this->requireCustomer($companyId, $contactId, true, 'update');

            if ($parentId !== null && $parentId !== '') {
                if ($parentId === $contactId) {
                    throw new WalletException('Un cliente no puede ser titular de sí mismo');
                }
                $parent = $this->requireCustomer($companyId, $parentId, true, 'share');
                if ($parent['parentcontactid'] !== null) {
                    throw new WalletException('El titular elegido ya depende de otro cliente');
                }
                if ($this->hasChildren($companyId, $contactId)) {
                    throw new WalletException('Este cliente tiene clientes a cargo: no puede depender de otro');
                }
            } else {
                $parentId = null;
            }

            if ($contact['parentcontactid'] === $parentId) {
                $db->CompleteTrans();
                return; // sin cambios
            }

            foreach ($this->pocketIdsWithMovements($companyId, $contactId) as $pocketId) {
                $this->lock($contactId, $pocketId);
            }
            foreach ($this->pocketIdsWithMovements($companyId, $contactId) as $pocketId) {
                if ($this->balanceCents($companyId, $contactId, $pocketId) !== 0) {
                    throw new WalletException('El cliente tiene saldo: dejalo en cero antes de cambiar su titular', 409);
                }
            }

            $db->Execute(
                'UPDATE contact SET parentcontactid = ? WHERE contactid = ? AND companyid = ?',
                [$parentId, $contactId, $companyId]
            );
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        if (!$db->CompleteTrans()) {
            throw new WalletException('No se pudo actualizar el titular', 500);
        }

        if (!$db->InTrans()) {
            realtimePublish('contact', 'update', $contactId, 'all', $companyId);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Lectura
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Saldo vigente del contacto en cada bolsillo del comercio. Incluye los
     * bolsillos inactivos solo si tienen saldo (un saldo no desaparece de la
     * vista porque se desactivó el bolsillo).
     *
     * @return list<array{pocketId: string, name: string, active: bool, balance: float}>
     */
    public function balances(string $companyId, string $contactId): array
    {
        if (!self::isUuid($contactId)) {
            return [];
        }
        $rs = $this->db()->Execute(
            'SELECT p.id, p.name, p.active,
                    COALESCE((SELECT m.balanceafter FROM wallet_movement m
                               WHERE m.companyid = p.companyid AND m.contactid = ? AND m.pocketid = p.id
                               ORDER BY m.seq DESC LIMIT 1), 0) AS balance
               FROM wallet_pocket p
              WHERE p.companyid = ?
              ORDER BY lower(p.name)',
            [$contactId, $companyId]
        );
        $out = [];
        while ($rs && !$rs->EOF) {
            $f       = $rs->fields;
            $active  = self::bool($f['active']);
            $balance = (float) $f['balance'];
            if ($active || $balance != 0.0) {
                $out[] = [
                    'pocketId' => (string) $f['id'],
                    'name'     => (string) $f['name'],
                    'active'   => $active,
                    'balance'  => $balance,
                ];
            }
            $rs->MoveNext();
        }
        return $out;
    }

    /** Saldo vigente de UN bolsillo. Lectura sin lock: para mostrar, no para decidir. */
    public function balance(string $companyId, string $contactId, string $pocketId): float
    {
        return self::fromCents($this->balanceCents($companyId, $contactId, $pocketId));
    }

    /**
     * Movimientos de un contacto, del más nuevo al más viejo. Paginado por
     * cursor sobre `seq` (`beforeSeq` = el `seq` más chico de la página
     * anterior): estable aunque entren movimientos nuevos mientras se pagina.
     *
     * @return array{movements: list<array>, nextBeforeSeq: ?int}
     */
    public function movements(string $companyId, string $contactId, ?string $pocketId = null, int $limit = 50, ?int $beforeSeq = null): array
    {
        if (!self::isUuid($contactId)) {
            return ['movements' => [], 'nextBeforeSeq' => null];
        }
        $limit  = max(1, min(200, $limit));
        $where  = ['m.companyid = ?', 'm.contactid = ?'];
        $params = [$companyId, $contactId];
        if ($pocketId !== null && $pocketId !== '') {
            if (!self::isUuid($pocketId)) {
                return ['movements' => [], 'nextBeforeSeq' => null];
            }
            $where[]  = 'm.pocketid = ?';
            $params[] = $pocketId;
        }
        if ($beforeSeq !== null && $beforeSeq > 0) {
            $where[]  = 'm.seq < ?';
            $params[] = $beforeSeq;
        }
        $params[] = $limit + 1;

        $rs = $this->db()->Execute(
            'SELECT m.id, m.seq, m.pocketid, p.name AS pocketname, m.type, m.amount, m.balanceafter,
                    m.billingmode, m.transfergroupid, m.sourcetype, m.sourceid, m.reason, m.createdat,
                    m.actorcontactid, a.contactname AS actorname,
                    (SELECT o.contactid FROM wallet_movement o
                      WHERE o.companyid = m.companyid AND o.transfergroupid = m.transfergroupid
                        AND o.id <> m.id LIMIT 1) AS counterpartid,
                    (SELECT c.contactname FROM wallet_movement o JOIN contact c ON c.contactid = o.contactid
                      WHERE o.companyid = m.companyid AND o.transfergroupid = m.transfergroupid
                        AND o.id <> m.id LIMIT 1) AS counterpartname
               FROM wallet_movement m
               JOIN wallet_pocket p ON p.id = m.pocketid AND p.companyid = m.companyid
               LEFT JOIN contact a ON a.contactid = m.actorcontactid
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY m.seq DESC
              LIMIT ?',
            $params
        );

        $rows = [];
        while ($rs && !$rs->EOF) {
            $rows[] = $this->movementRow($rs->fields);
            $rs->MoveNext();
        }
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int) end($rows)['seq'];
        }
        return ['movements' => $rows, 'nextBeforeSeq' => $next];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Operaciones que mueven saldo
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Carga: + en el bolsillo de un TITULAR (§3.1). Un hijo nunca se carga
     * desde afuera — solo recibe transferencias de su titular.
     *
     * `$billingMode` se CONGELA en la fila (§4): la v1 solo implementa 'A',
     * pero se graba igual para que el día que exista el B un saldo cargado
     * bajo A no se vuelva a facturar.
     */
    public function load(
        string $companyId,
        string $titularId,
        string $pocketId,
        float $amount,
        string $billingMode,
        ?string $sourceType,
        ?string $sourceId,
        string $actorId,
    ): array {
        if (!in_array($billingMode, self::BILLING_MODES, true)) {
            throw new WalletException('Modo de facturación inválido');
        }
        $cents = self::positiveCents($amount);

        return $this->mutate($companyId, $actorId, [$titularId], function () use ($companyId, $titularId, $pocketId, $cents, $billingMode, $sourceType, $sourceId, $actorId) {
            $contact = $this->requireCustomer($companyId, $titularId, true, 'share');
            if ($contact['parentcontactid'] !== null) {
                throw new WalletException('A un cliente a cargo solo se le transfiere saldo desde su titular');
            }
            $this->requirePocket($companyId, $pocketId, true);
            $this->lock($titularId, $pocketId);

            return $this->insert($companyId, $titularId, $pocketId, 'load', $cents, [
                'billingmode' => $billingMode,
                'sourcetype'  => $sourceType,
                'sourceid'    => $sourceId,
                'actor'       => $actorId,
            ]);
        });
    }

    /**
     * Pago con saldo: − en el bolsillo elegido (D4: lo elige el cajero).
     *
     * Si no alcanza, NO escribe nada y lanza `WalletInsufficientFundsException`
     * con cuánto había (leído bajo el lock). La caja (F2, D6) usa ese número
     * para debitar lo que hay y cobrar la diferencia con otro medio.
     */
    public function spend(
        string $companyId,
        string $contactId,
        string $pocketId,
        float $amount,
        ?string $sourceType,
        ?string $sourceId,
        string $actorId,
    ): array {
        $cents = self::positiveCents($amount);

        return $this->mutate($companyId, $actorId, [$contactId], function () use ($companyId, $contactId, $pocketId, $cents, $sourceType, $sourceId, $actorId) {
            $this->requireCustomer($companyId, $contactId, true);
            $this->requirePocket($companyId, $pocketId, true);
            $this->lock($contactId, $pocketId);

            return $this->insert($companyId, $contactId, $pocketId, 'spend', -$cents, [
                'sourcetype' => $sourceType,
                'sourceid'   => $sourceId,
                'actor'      => $actorId,
            ]);
        });
    }

    /**
     * Reversa de un pago con saldo: + en el mismo bolsillo.
     *
     * Siempre referencia el pago que revierte (`sourceType`/`sourceId` del
     * spend), y lo devuelto para ese origen nunca supera lo que se pagó con
     * él: devolver dos veces la misma venta crearía saldo de la nada. El
     * chequeo va bajo el lock del bolsillo, así que dos reversas simultáneas
     * del mismo pago no pasan las dos. Funciona aunque el bolsillo esté
     * inactivo: la reversa corrige algo que ya pasó.
     */
    public function refund(
        string $companyId,
        string $contactId,
        string $pocketId,
        float $amount,
        string $sourceType,
        string $sourceId,
        string $actorId,
        ?string $reason = null,
    ): array {
        $cents = self::positiveCents($amount);
        if (trim($sourceType) === '' || !self::isUuid($sourceId)) {
            throw new WalletException('Indicá el pago que se revierte');
        }

        return $this->mutate($companyId, $actorId, [$contactId], function () use ($companyId, $contactId, $pocketId, $cents, $sourceType, $sourceId, $actorId, $reason) {
            $this->requireCustomer($companyId, $contactId, false);
            $this->requirePocket($companyId, $pocketId, false);
            $this->lock($contactId, $pocketId);

            $rs = $this->db()->Execute(
                "SELECT COALESCE(SUM(CASE WHEN type = 'spend'  THEN -amount ELSE 0 END), 0) * 100 AS spent,
                        COALESCE(SUM(CASE WHEN type = 'refund' THEN  amount ELSE 0 END), 0) * 100 AS refunded
                   FROM wallet_movement
                  WHERE companyid = ? AND contactid = ? AND pocketid = ?
                    AND sourcetype = ? AND sourceid = ?",
                [$companyId, $contactId, $pocketId, $sourceType, $sourceId]
            );
            $spent    = (int) round((float) $rs->fields['spent']);
            $refunded = (int) round((float) $rs->fields['refunded']);
            if ($spent === 0) {
                throw new WalletException('No hay un pago con saldo de este cliente para revertir', 404);
            }
            if ($refunded + $cents > $spent) {
                throw new WalletException('Lo que se devuelve supera lo que se pagó con saldo', 409);
            }

            return $this->insert($companyId, $contactId, $pocketId, 'refund', $cents, [
                'sourcetype' => $sourceType,
                'sourceid'   => $sourceId,
                'reason'     => $reason,
                'actor'      => $actorId,
            ]);
        });
    }

    /**
     * Corrección manual, en cualquier sentido, con motivo obligatorio (§8: un
     * error se corrige con otro movimiento, con motivo y autor). Un ajuste
     * negativo tampoco deja el bolsillo bajo cero.
     *
     * Vale sobre titulares e hijos y sobre bolsillos inactivos: corregir no es
     * operar.
     */
    public function adjust(
        string $companyId,
        string $contactId,
        string $pocketId,
        float $signedAmount,
        string $reason,
        string $actorId,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new WalletException('Indicá el motivo del ajuste');
        }
        if (mb_strlen($reason) > 500) {
            throw new WalletException('El motivo es demasiado largo');
        }
        $cents = self::toCents($signedAmount);
        if ($cents === 0) {
            throw new WalletException('El monto del ajuste no puede ser cero');
        }
        if (abs($cents) > self::MAX_CENTS) {
            throw new WalletException('El monto es demasiado grande');
        }

        return $this->mutate($companyId, $actorId, [$contactId], function () use ($companyId, $contactId, $pocketId, $cents, $reason, $actorId) {
            $this->requireCustomer($companyId, $contactId, false);
            $this->requirePocket($companyId, $pocketId, false);
            $this->lock($contactId, $pocketId);

            return $this->insert($companyId, $contactId, $pocketId, 'adjust', $cents, [
                'sourcetype' => 'manual',
                'reason'     => $reason,
                'actor'      => $actorId,
            ]);
        });
    }

    /**
     * Transferencia titular → hijo en UN bolsillo (§3.4). Dos movimientos con
     * el mismo `transfergroupid` en UNA transacción: existen los dos o ninguno.
     *
     * Solo hacia abajo y solo hacia un hijo DE ESE titular. Si el titular no
     * tiene saldo suficiente, no se escribe ningún lado.
     *
     * @return array{transferGroupId: string, from: array, to: array}
     */
    public function transfer(
        string $companyId,
        string $titularId,
        string $childId,
        string $pocketId,
        float $amount,
        string $actorId,
        ?string $reason = null,
    ): array {
        $cents = self::positiveCents($amount);
        if ($titularId === $childId) {
            throw new WalletException('El origen y el destino son el mismo cliente');
        }

        return $this->mutate($companyId, $actorId, [$titularId, $childId], function () use ($companyId, $titularId, $childId, $pocketId, $cents, $actorId, $reason) {
            $titular = $this->requireCustomer($companyId, $titularId, true, 'share');
            $child   = $this->requireCustomer($companyId, $childId, true, 'share');
            if ($titular['parentcontactid'] !== null) {
                throw new WalletException('Solo un titular puede transferir saldo');
            }
            if ($child['parentcontactid'] !== $titularId) {
                throw new WalletException('El destino no es un cliente a cargo de este titular');
            }
            $this->requirePocket($companyId, $pocketId, true);

            // Orden determinístico de los dos locks: por la CLAVE, no por el
            // rol. Así una transferencia A→B y otra que toque B→A (hoy no
            // existe, pero un ajuste cruzado sí) nunca se bloquean en cruz.
            $keys = [self::lockKey($titularId, $pocketId), self::lockKey($childId, $pocketId)];
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $this->lockByKey($key);
            }

            $groupId = (string) $this->db()->Execute('SELECT gen_random_uuid() AS id')->fields['id'];
            $opts    = ['transfergroupid' => $groupId, 'reason' => $reason, 'actor' => $actorId];

            $from = $this->insert($companyId, $titularId, $pocketId, 'transfer', -$cents, $opts);
            $to   = $this->insert($companyId, $childId, $pocketId, 'transfer', $cents, $opts);

            return ['transferGroupId' => $groupId, 'from' => $from, 'to' => $to];
        });
    }

    /**
     * Aviso de tiempo real para los contactos cuyo saldo cambió. Lo llama el
     * orquestador que envolvió operaciones de la wallet en SU transacción
     * (la venta de F2), después de su commit — ver el docblock de la clase.
     *
     * @param list<string> $contactIds
     */
    public function publishChange(string $companyId, array $contactIds): void
    {
        try {
            realtimePublish('wallet', 'update', null, 'all', $companyId, array_values(array_unique($contactIds)));
        } catch (\Throwable $e) {
            error_log('[wallet] realtime ignorado: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Infra
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Envuelve una operación en su transacción (anidable) y publica el aviso
     * de tiempo real FUERA de ella, solo si esta era la transacción externa.
     *
     * El autor se valida acá y no en cada operación: ninguna escritura de saldo
     * puede pasar sin uno (§8).
     *
     * @param list<string> $contactIds
     */
    private function mutate(string $companyId, string $actorId, array $contactIds, callable $op): array
    {
        $db = $this->db();
        $db->StartTrans();
        try {
            $this->requireActor($companyId, $actorId);
            $result = $op();
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        if (!$db->CompleteTrans()) {
            throw new WalletException('No se pudo registrar el movimiento', 500);
        }

        if (!$db->InTrans()) {
            $this->publishChange($companyId, $contactIds);
        }
        return $result;
    }

    /**
     * Escribe UN movimiento contra el saldo leído BAJO EL LOCK (que el caller
     * ya tomó). Si dejaría el bolsillo negativo, no escribe y lanza con lo
     * que había.
     *
     * @param array{billingmode?: ?string, transfergroupid?: ?string, sourcetype?: ?string, sourceid?: ?string, reason?: ?string, actor: string} $opts
     */
    private function insert(string $companyId, string $contactId, string $pocketId, string $type, int $cents, array $opts): array
    {
        $prev  = $this->balanceCents($companyId, $contactId, $pocketId);
        $after = $prev + $cents;
        if ($after < 0) {
            throw new WalletInsufficientFundsException(self::fromCents($prev), self::fromCents(-$cents));
        }

        $sourceId = $opts['sourceid'] ?? null;
        if ($sourceId !== null && $sourceId !== '' && !self::isUuid($sourceId)) {
            throw new WalletException('Origen del movimiento inválido');
        }
        $sourceType = isset($opts['sourcetype']) ? trim((string) $opts['sourcetype']) : null;
        $reason     = isset($opts['reason']) ? trim((string) $opts['reason']) : null;

        $rs = $this->db()->Execute(
            'INSERT INTO wallet_movement
                (companyid, contactid, pocketid, type, amount, balanceafter, billingmode,
                 transfergroupid, sourcetype, sourceid, actorcontactid, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id, seq, createdat',
            [
                $companyId,
                $contactId,
                $pocketId,
                $type,
                self::centsToSql($cents),
                self::centsToSql($after),
                $opts['billingmode'] ?? null,
                $opts['transfergroupid'] ?? null,
                $sourceType !== '' ? $sourceType : null,
                ($sourceId !== null && $sourceId !== '') ? $sourceId : null,
                $opts['actor'],
                $reason !== '' ? $reason : null,
            ]
        );

        return [
            'id'           => (string) $rs->fields['id'],
            'seq'          => (int) $rs->fields['seq'],
            'contactId'    => $contactId,
            'pocketId'     => $pocketId,
            'type'         => $type,
            'amount'       => self::fromCents($cents),
            'balanceAfter' => self::fromCents($after),
            'billingMode'  => $opts['billingmode'] ?? null,
            'createdAt'    => (string) $rs->fields['createdat'],
        ];
    }

    /** Saldo en centavos = balanceafter del último movimiento, 0 si no hay. */
    private function balanceCents(string $companyId, string $contactId, string $pocketId): int
    {
        $rs = $this->db()->Execute(
            'SELECT balanceafter * 100 AS cents FROM wallet_movement
              WHERE companyid = ? AND contactid = ? AND pocketid = ?
              ORDER BY seq DESC LIMIT 1',
            [$companyId, $contactId, $pocketId]
        );
        return ($rs && !$rs->EOF) ? (int) round((float) $rs->fields['cents']) : 0;
    }

    /** Lock de transacción sobre (contacto, bolsillo). Se suelta en el COMMIT externo. */
    private function lock(string $contactId, string $pocketId): void
    {
        $this->lockByKey(self::lockKey($contactId, $pocketId));
    }

    private function lockByKey(string $key): void
    {
        if (!$this->db()->HasOpenTransaction()) {
            // Un advisory lock de transacción fuera de una transacción se
            // suelta al terminar la sentencia: no protegería nada.
            throw new \LogicException('El lock de la wallet requiere una transacción abierta');
        }
        $this->db()->Execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    private static function lockKey(string $contactId, string $pocketId): string
    {
        return 'wallet:' . strtolower($contactId) . ':' . strtolower($pocketId);
    }

    /**
     * El contacto tiene que ser un CLIENTE del comercio. `$mustBeActive` para
     * las operaciones nuevas (cargar, pagar, transferir, cambiar titular); una
     * corrección o una reversa sobre un cliente archivado sí se permite.
     *
     * `$rowLock` protege la JERARQUÍA leída contra un cambio concurrente de
     * titular: `setParent()` toma la fila del contacto que mueve con
     * `update`, y las operaciones que dependen de quién es titular de quién
     * (cargar, transferir, y el padre en `setParent()`) la leen con `share`.
     * Así "¿es titular?" no puede cambiar entre el chequeo y la escritura. Un
     * pago no depende de la jerarquía y no toma lock de fila.
     *
     * @param ''|'share'|'update' $rowLock
     * @return array{contactid: string, parentcontactid: ?string}
     */
    private function requireCustomer(string $companyId, string $contactId, bool $mustBeActive, string $rowLock = ''): array
    {
        if (!self::isUuid($contactId)) {
            throw new WalletException('Cliente no encontrado', 404);
        }
        $lockSql = match ($rowLock) {
            'share'  => ' FOR SHARE',
            'update' => ' FOR UPDATE',
            default  => '',
        };
        $rs = $this->db()->Execute(
            'SELECT contactid, parentcontactid, COALESCE(contactstatus, 1) AS status
               FROM contact WHERE contactid = ? AND companyid = ? AND type = 1' . $lockSql,
            [$contactId, $companyId]
        );
        if (!$rs || $rs->EOF) {
            throw new WalletException('Cliente no encontrado', 404);
        }
        if ($mustBeActive && (int) $rs->fields['status'] !== 1) {
            throw new WalletException('El cliente está archivado', 409);
        }
        $parent = $rs->fields['parentcontactid'];
        return [
            'contactid'       => (string) $rs->fields['contactid'],
            'parentcontactid' => ($parent === null || $parent === '') ? null : (string) $parent,
        ];
    }

    private function requirePocket(string $companyId, string $pocketId, bool $mustBeActive = false): array
    {
        $pocket = $this->findPocket($companyId, $pocketId);
        if ($pocket === null) {
            throw new WalletException('Bolsillo no encontrado', 404);
        }
        if ($mustBeActive && !$pocket['active']) {
            throw new WalletException('El bolsillo está desactivado', 409);
        }
        return $pocket;
    }

    /** El autor es un usuario del comercio (contact type 0), siempre (§8). */
    private function requireActor(string $companyId, string $actorId): void
    {
        if (!self::isUuid($actorId)) {
            throw new WalletException('No se pudo identificar quién hace el movimiento', 403);
        }
        $rs = $this->db()->Execute(
            'SELECT 1 FROM contact WHERE contactid = ? AND companyid = ? AND type = 0',
            [$actorId, $companyId]
        );
        if (!$rs || $rs->EOF) {
            throw new WalletException('No se pudo identificar quién hace el movimiento', 403);
        }
    }

    private function hasChildren(string $companyId, string $contactId): bool
    {
        $rs = $this->db()->Execute(
            'SELECT 1 FROM contact WHERE companyid = ? AND parentcontactid = ? LIMIT 1',
            [$companyId, $contactId]
        );
        return $rs && !$rs->EOF;
    }

    /** @return list<string> */
    private function pocketIdsWithMovements(string $companyId, string $contactId): array
    {
        $rs  = $this->db()->Execute(
            'SELECT DISTINCT pocketid FROM wallet_movement WHERE companyid = ? AND contactid = ? ORDER BY pocketid',
            [$companyId, $contactId]
        );
        $ids = [];
        while ($rs && !$rs->EOF) {
            $ids[] = (string) $rs->fields['pocketid'];
            $rs->MoveNext();
        }
        return $ids;
    }

    private function cleanPocketName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw new WalletException('Indicá el nombre del bolsillo');
        }
        if (mb_strlen($name) > 80) {
            throw new WalletException('El nombre es demasiado largo');
        }
        return $name;
    }

    private function assertPocketNameFree(string $companyId, string $name, ?string $exceptId): void
    {
        $rs = $this->db()->Execute(
            'SELECT id FROM wallet_pocket WHERE companyid = ? AND lower(btrim(name)) = lower(btrim(?))',
            [$companyId, $name]
        );
        if ($rs && !$rs->EOF && (string) $rs->fields['id'] !== (string) $exceptId) {
            throw new WalletException('Ya existe un bolsillo con ese nombre', 409);
        }
    }

    private function pocketRow($f): array
    {
        return [
            'id'        => (string) $f['id'],
            'name'      => (string) $f['name'],
            'active'    => self::bool($f['active']),
            'createdAt' => (string) $f['created_at'],
        ];
    }

    private function movementRow($f): array
    {
        $opt = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;
        return [
            'id'              => (string) $f['id'],
            'seq'             => (int) $f['seq'],
            'pocketId'        => (string) $f['pocketid'],
            'pocketName'      => (string) $f['pocketname'],
            'type'            => (string) $f['type'],
            'amount'          => (float) $f['amount'],
            'balanceAfter'    => (float) $f['balanceafter'],
            'billingMode'     => $opt($f['billingmode']),
            'transferGroupId' => $opt($f['transfergroupid']),
            'counterpartId'   => $opt($f['counterpartid']),
            'counterpartName' => $opt($f['counterpartname']),
            'sourceType'      => $opt($f['sourcetype']),
            'sourceId'        => $opt($f['sourceid']),
            'reason'          => $opt($f['reason']),
            'actorId'         => (string) $f['actorcontactid'],
            'actorName'       => $opt($f['actorname']),
            'createdAt'       => (string) $f['createdat'],
        ];
    }

    private function db(): object
    {
        global $db;
        return $db;
    }

    private static function isUuid(?string $v): bool
    {
        return is_string($v)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
    }

    private static function bool($v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }

    private static function toCents(float $amount): int
    {
        if (!is_finite($amount)) {
            throw new WalletException('Monto inválido');
        }
        return (int) round($amount * 100);
    }

    private static function positiveCents(float $amount): int
    {
        $cents = self::toCents($amount);
        if ($cents <= 0) {
            throw new WalletException('El monto tiene que ser mayor a cero');
        }
        if ($cents > self::MAX_CENTS) {
            throw new WalletException('El monto es demasiado grande');
        }
        return $cents;
    }

    private static function fromCents(int $cents): float
    {
        return $cents / 100;
    }

    /** Centavos → literal NUMERIC exacto ("1234.05", "-0.50"), sin pasar por float. */
    private static function centsToSql(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs  = abs($cents);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
