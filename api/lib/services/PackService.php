<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

/**
 * PackService — gestión de packs/combos de servicios.
 *
 * Un pack es un item (itemType='pack') que agrupa N servicios/productos con
 * cantidades. Al venderse se genera una instancia (sold_pack) con fecha de
 * vencimiento. El cajero canjea servicios desde el POS marcando cuántos se
 * realizaron (sold_pack_usage). Al vencer el pack se bloquea (status=0).
 *
 * Convenciones:
 *  - Lecturas: ncmExecute (flatten JSONB).
 *  - Escrituras: $db->Execute / $db->Insert directos parametrizados.
 *  - Todas las ops scopeadas por companyId del JWT.
 */
final class PackService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    // ── Componentes ──────────────────────────────────────────────────────────

    /**
     * Lista los componentes de un pack (para el editor de items en panel-next).
     *
     * @return list<array{packComponentId:string, componentItemId:string, componentName:string, componentQty:int, sort:int}>
     */
    public function listComponents(string $companyId, string $packItemId): array
    {
        $rs = ncmExecute(
            'SELECT pc.packComponentId, pc.componentItemId, pc.componentQty, pc.sort,
                    i.itemName AS componentName
               FROM pack_component pc
               JOIN item i ON i.itemId = pc.componentItemId
              WHERE pc.packItemId = ? AND pc.companyId = ?
              ORDER BY pc.sort ASC, pc.createdAt ASC',
            [$packItemId, $companyId],
            false,
            true
        );

        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[] = [
                    'packComponentId' => (string) ($f['packcomponentid'] ?? $f['packComponentId'] ?? ''),
                    'componentItemId' => (string) ($f['componentitemid'] ?? $f['componentItemId'] ?? ''),
                    'componentName'   => (string) ($f['componentname']   ?? $f['componentName']   ?? ''),
                    'componentQty'    => (int)    ($f['componentqty']    ?? $f['componentQty']    ?? 1),
                    'sort'            => (int)    ($f['sort']            ?? 0),
                ];
                $rs->MoveNext();
            }
        }
        return $out;
    }

    /**
     * Reemplaza TODOS los componentes de un pack (bulk replace).
     *
     * @param list<array{componentItemId:string, componentQty:int, sort:int}> $components
     * @return list<array> Los componentes tal como quedan.
     */
    public function setComponents(string $companyId, string $packItemId, array $components): array
    {
        global $db;

        // Verificar que el pack pertenece al tenant.
        $check = ncmExecute(
            'SELECT itemId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$packItemId, $companyId]
        );
        if (!$check) {
            return [];
        }

        $db->StartTrans();

        $db->Execute(
            'DELETE FROM pack_component WHERE packItemId = ? AND companyId = ?',
            [$packItemId, $companyId]
        );

        foreach ($components as $i => $comp) {
            $cItemId = trim((string) ($comp['componentItemId'] ?? ''));
            if ($cItemId === '') {
                continue;
            }
            $db->Execute(
                'INSERT INTO pack_component (packItemId, componentItemId, componentQty, sort, companyId)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $packItemId,
                    $cItemId,
                    max(1, (int) ($comp['componentQty'] ?? 1)),
                    isset($comp['sort']) ? (int) $comp['sort'] : $i,
                    $companyId,
                ]
            );
        }

        if (!$db->CompleteTrans()) {
            return [];
        }

        return $this->listComponents($companyId, $packItemId);
    }

    // ── Packs del cliente ────────────────────────────────────────────────────

    /**
     * Lista los packs activos de un cliente (status=1, no vencidos).
     * Actualiza lazy el status de los vencidos antes de devolver.
     *
     * Shape por pack:
     *   soldPackId, packName, expiresAt, daysLeft, status, components[{packComponentId,
     *   componentName, total, used, remaining}]
     */
    public function listForContact(string $companyId, string $contactId): array
    {
        global $db;

        // Lazy expiry: marcar vencidos antes de consultar.
        $db->Execute(
            "UPDATE sold_pack SET status = 0, updatedAt = now()
              WHERE companyId = ? AND contactId = ? AND status = 1 AND expiresAt < now()",
            [$companyId, $contactId]
        );

        $packs = ncmExecute(
            'SELECT sp.soldPackId, sp.packItemId, sp.expiresAt, sp.status,
                    i.itemName AS packName
               FROM sold_pack sp
               JOIN item i ON i.itemId = sp.packItemId
              WHERE sp.companyId = ? AND sp.contactId = ?
              ORDER BY sp.status DESC, sp.expiresAt ASC',
            [$companyId, $contactId],
            false,
            true
        );

        $out = [];
        if (!$packs) {
            return $out;
        }

        while (!$packs->EOF) {
            $p       = $packs->fields;
            $spId    = (string) ($p['soldpackid']   ?? $p['soldPackId']   ?? '');
            $piId    = (string) ($p['packitemid']   ?? $p['packItemId']   ?? '');
            $expAt   = (string) ($p['expiresat']    ?? $p['expiresAt']    ?? '');
            $status  = (int)    ($p['status']       ?? 0);
            $pkName  = (string) ($p['packname']     ?? $p['packName']     ?? '');

            $daysLeft = null;
            if ($expAt !== '' && $status === 1) {
                $diff = (int) ceil((strtotime($expAt) - time()) / 86400);
                $daysLeft = max(0, $diff);
            }

            // Componentes con saldo restante.
            $comps = $this->loadComponentsWithBalance($companyId, $spId, $piId);

            $out[] = [
                'soldPackId' => $spId,
                'packName'   => $pkName,
                'expiresAt'  => $expAt,
                'daysLeft'   => $daysLeft,
                'status'     => $status,
                'components' => $comps,
            ];

            $packs->MoveNext();
        }

        return $out;
    }

    /**
     * Crea una o más instancias de sold_pack al vender un pack.
     * qty > 1 → crea qty filas independientes.
     * expiresAt = now() + packDurationDays.
     *
     * No lanza: si falla, loguea y retorna [].
     */
    public function createFromSale(
        string $companyId,
        string $packItemId,
        string $contactId,
        string $transactionId,
        string $outletId,
        int $qty
    ): array {
        global $db;

        // packDurationDays desde item.data JSONB (flatten via ncmExecute).
        $itmRow = ncmExecute(
            'SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$packItemId, $companyId]
        );
        $durationDays = (int) (($itmRow['packDurationDays'] ?? null) ?: 30);

        $created = [];
        for ($i = 0; $i < max(1, $qty); $i++) {
            $res = $db->Execute(
                "INSERT INTO sold_pack
                   (packItemId, contactId, transactionId, outletId, companyId, expiresAt, status)
                 VALUES (?, ?, ?, ?, ?, now() + INTERVAL '{$durationDays} days', 1)
                 RETURNING soldPackId",
                [$packItemId, $contactId, $transactionId ?: null, $outletId ?: null, $companyId]
            );
            if ($res && !$res->EOF) {
                $created[] = (string) ($res->fields['soldpackid'] ?? $res->fields['soldPackId'] ?? '');
            }
        }

        return $created;
    }

    /**
     * Registra uno o varios canjes de servicios de un pack.
     *
     * $usages = [{packComponentId, qty}]
     * Valida: pack activo, no vencido, saldo disponible.
     * Si todos los saldos llegan a 0 → status = 2 (consumido completamente).
     *
     * @return array{ok:bool, soldPackId:string, components:list<array>}
     */
    public function recordUsage(
        string $companyId,
        string $soldPackId,
        array $usages,
        ?string $performedBy
    ): array {
        global $db;

        // Verificar que el pack existe, pertenece al tenant y está activo.
        $sp = ncmExecute(
            'SELECT soldPackId, packItemId, status, expiresAt, contactId
               FROM sold_pack
              WHERE soldPackId = ? AND companyId = ? LIMIT 1',
            [$soldPackId, $companyId]
        );
        if (!$sp) {
            return ['ok' => false, 'error' => 'Pack no encontrado'];
        }
        if ((int) ($sp['status'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'El pack no está activo'];
        }
        $expAt = (string) ($sp['expiresat'] ?? $sp['expiresAt'] ?? '');
        if ($expAt !== '' && strtotime($expAt) < time()) {
            // Marcar vencido lazy.
            $db->Execute(
                'UPDATE sold_pack SET status = 0, updatedAt = now() WHERE soldPackId = ?',
                [$soldPackId]
            );
            return ['ok' => false, 'error' => 'El pack está vencido'];
        }

        $packItemId = (string) ($sp['packitemid'] ?? $sp['packItemId'] ?? '');

        // Validar saldo por componente antes de escribir.
        foreach ($usages as $u) {
            $pcId = trim((string) ($u['packComponentId'] ?? ''));
            $qty  = max(1, (int) ($u['qty'] ?? 1));
            if ($pcId === '') {
                continue;
            }

            $bal = $this->getComponentBalance($companyId, $soldPackId, $pcId);
            if ($bal === null) {
                return ['ok' => false, 'error' => "Componente $pcId no pertenece al pack"];
            }
            if ($bal < $qty) {
                return ['ok' => false, 'error' => "Saldo insuficiente para componente $pcId (disponible: $bal, solicitado: $qty)"];
            }
        }

        $outletId = defined('OUTLET_ID') ? OUTLET_ID : null;

        $db->StartTrans();
        foreach ($usages as $u) {
            $pcId = trim((string) ($u['packComponentId'] ?? ''));
            $qty  = max(1, (int) ($u['qty'] ?? 1));
            if ($pcId === '') {
                continue;
            }
            $db->Execute(
                'INSERT INTO sold_pack_usage
                   (soldPackId, packComponentId, qty, performedBy, outletId, companyId, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$soldPackId, $pcId, $qty, $performedBy ?: null, $outletId, $companyId, null]
            );
        }

        // Actualizar updatedAt.
        $db->Execute(
            'UPDATE sold_pack SET updatedAt = now() WHERE soldPackId = ?',
            [$soldPackId]
        );

        if (!$db->CompleteTrans()) {
            return ['ok' => false, 'error' => 'Error al guardar canjes'];
        }

        // Verificar si todos los saldos llegaron a 0 → status=2.
        $comps = $this->loadComponentsWithBalance($companyId, $soldPackId, $packItemId);
        $allConsumed = count($comps) > 0 && array_sum(array_column($comps, 'remaining')) === 0;
        if ($allConsumed) {
            $db->Execute(
                'UPDATE sold_pack SET status = 2, updatedAt = now() WHERE soldPackId = ?',
                [$soldPackId]
            );
        }

        return ['ok' => true, 'soldPackId' => $soldPackId, 'components' => $comps];
    }

    /**
     * Bloquea packs vencidos del tenant (lazy expiry masiva — para cron o llamada puntual).
     * Retorna la cantidad de packs bloqueados.
     */
    public function expireStale(string $companyId): int
    {
        global $db;
        $res = $db->Execute(
            "UPDATE sold_pack SET status = 0, updatedAt = now()
              WHERE companyId = ? AND status = 1 AND expiresAt < now()",
            [$companyId]
        );
        return $res ? (int) $db->Affected_Rows() : 0;
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * Retorna los componentes de un sold_pack con su saldo restante.
     *
     * @return list<array{packComponentId:string, componentName:string, total:int, used:int, remaining:int}>
     */
    private function loadComponentsWithBalance(string $companyId, string $soldPackId, string $packItemId): array
    {
        $rs = ncmExecute(
            'SELECT pc.packComponentId, pc.componentQty, i.itemName AS componentName,
                    COALESCE(SUM(pu.qty), 0) AS usedQty
               FROM pack_component pc
               JOIN item i ON i.itemId = pc.componentItemId
               LEFT JOIN sold_pack_usage pu
                      ON pu.packComponentId = pc.packComponentId
                     AND pu.soldPackId = ?
              WHERE pc.packItemId = ? AND pc.companyId = ?
              GROUP BY pc.packComponentId, pc.componentQty, i.itemName, pc.sort
              ORDER BY pc.sort ASC, pc.createdAt ASC',
            [$soldPackId, $packItemId, $companyId],
            false,
            true
        );

        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f     = $rs->fields;
                $total = (int) ($f['componentqty'] ?? $f['componentQty'] ?? 1);
                $used  = (int) ($f['usedqty']      ?? $f['usedQty']      ?? 0);
                $out[] = [
                    'packComponentId' => (string) ($f['packcomponentid'] ?? $f['packComponentId'] ?? ''),
                    'componentName'   => (string) ($f['componentname']   ?? $f['componentName']   ?? ''),
                    'total'           => $total,
                    'used'            => $used,
                    'remaining'       => max(0, $total - $used),
                ];
                $rs->MoveNext();
            }
        }
        return $out;
    }

    /**
     * Retorna el saldo disponible de un componente en un sold_pack.
     * Retorna null si el componente no pertenece al pack.
     */
    private function getComponentBalance(string $companyId, string $soldPackId, string $packComponentId): ?int
    {
        // Obtener packItemId del sold_pack.
        $sp = ncmExecute(
            'SELECT packItemId FROM sold_pack WHERE soldPackId = ? AND companyId = ? LIMIT 1',
            [$soldPackId, $companyId]
        );
        if (!$sp) {
            return null;
        }
        $packItemId = (string) ($sp['packitemid'] ?? $sp['packItemId'] ?? '');

        $row = ncmExecute(
            'SELECT pc.componentQty, COALESCE(SUM(pu.qty), 0) AS usedQty
               FROM pack_component pc
               LEFT JOIN sold_pack_usage pu
                      ON pu.packComponentId = pc.packComponentId
                     AND pu.soldPackId = ?
              WHERE pc.packComponentId = ? AND pc.packItemId = ? AND pc.companyId = ?
              GROUP BY pc.componentQty',
            [$soldPackId, $packComponentId, $packItemId, $companyId]
        );
        if (!$row) {
            return null;
        }
        $total = (int) ($row['componentqty'] ?? $row['componentQty'] ?? 0);
        $used  = (int) ($row['usedqty']      ?? $row['usedQty']      ?? 0);
        return max(0, $total - $used);
    }
}
