<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
use DB;

require_once __DIR__ . '/../meta_transaction.php';
require_once __DIR__ . '/../Auth/RoleService.php';

/**
 * TransactionService — operaciones sobre transacciones/órdenes del POS (Slice 6).
 *
 * Lógica portada de app/action.php:
 *   deleteTransaction  (L1188) — elimina una transacción
 *   deleteInPrintServer (L155) — elimina un job de la cola de impresión
 *   rejectOrder        (L1260) — rechaza una orden (status 6); la notif WS va al caller
 *   recordItemDeletion  (L605) — inserta en itemDeleted (historial de borrado de ítems)
 *   voidTransaction (voidSale en functions.php:4658) — anula una transacción (type→7)
 *
 * Bugs PG corregidos respecto al legacy:
 *   - LIMIT 1 en DELETE → eliminado (transactionId es PK, limita solo).
 *   - db_prepare() interpolado en WHERE → bindeo parametrizado.
 *   - UUIDs enviados sin comillas en SQL → bindeados.
 *
 * El caller (api/v1/transactions.php) es responsable de los efectos secundarios
 * (sendWS, updateLastTimeEdit) para que el servicio sea puro de BD.
 */

final class TransactionService
{
    public function __construct(
        public readonly TenantContext $ctx,
        public readonly \DB $db,
    ) {}

    /**
     * Lee una transacción por ID y devuelve el shape que espera el POS front.
     * tags y transactionDetails viven en meta JSONB (§22.6); ncmExecute/_flattenJsonb
     * los expone como strings en $fields antes de json_decode.
     *
     * @return array|null null si la transacción no existe.
     */
    public function getSingle(string $transactionId, string $companyId): ?array
    {
        $fields = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$fields) {
            return null;
        }
        // Post-refactor 28-jun: ncmExecute devuelve array plano lowercase, pero
        // este método tiene ~20 accesos $fields['transactionType']/etc. en
        // camelCase. Envolver en CaseInsensitiveArray para preservar el lookup
        // case-insensitive sin reescribir todo el método. Bug: facturas a
        // crédito mostraban total=0 y CTA 'Duplicar' en lugar de 'Pagar'
        // porque transactionType === '3' nunca matcheaba (key era 'transactiontype').
        if (!($fields instanceof \CaseInsensitiveArray)) {
            $fields = new \CaseInsensitiveArray((array) $fields);
        }

        // tags en meta → _flattenJsonb expone como JSON string
        $rawTags = $fields['tags'] ?? null;
        $tags    = $rawTags ? (implodes(',', json_decode($rawTags, true)) ?: '') : '';

        // Shape dual: legacy {type, price, extra, UID} y nuevo POS {name, total}.
        // El "name" del nuevo shape contiene la KEY (ej "efectivo"), no el display.
        // Resolvemos siempre via getPaymentMethodName para devolver display friendly.
        $paymentType  = json_decode($fields['transactionPaymentType'] ?? '', true);
        $paymentTypes = [];
        if ($paymentType) {
            foreach ($paymentType as $v) {
                $key = (string) ($v['type'] ?? $v['name'] ?? '');
                $paymentTypes[] = [
                    'amount' => (float) ($v['price'] ?? $v['total'] ?? 0),
                    'name'   => getPaymentMethodName($key),
                    'type'   => $key,
                    'extra'  => $v['extra'] ?? null,
                    'UID'    => $v['UID'] ?? '',
                ];
            }
        }

        $payedData        = [];
        $payed            = 0;
        $creditPayments   = null;
        $creditNotes      = [];
        $appointments     = [];
        $paymentsReceived = [];
        if ((string) $fields['transactionType'] === '3') {
            $payedData = $this->getTypePayments($transactionId, $companyId);
            $payedRow  = ncmExecute(
                'SELECT SUM(ABS(transactionTotal - COALESCE(transactionDiscount, 0))) as payed FROM transaction WHERE transactionType IN(5,6) AND transactionParentId = ? AND companyId = ?',
                [$transactionId, $companyId]
            );
            $payed = (float) ($payedRow['payed'] ?? 0);

            $cpTotal   = (float)($fields['transactionTotal'] ?? 0) - (float)($fields['transactionDiscount'] ?? 0);
            $cpPaidRow = ncmExecute(
                "SELECT COALESCE(SUM(transactionTotal), 0) AS paid FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?",
                [$transactionId, $companyId]
            );
            $cpPaid = (float)($cpPaidRow['paid'] ?? 0);
            $creditPayments = [
                'total' => $cpTotal,
                'paid'  => $cpPaid,
                'debt'  => max(0.0, $cpTotal - $cpPaid),
            ];

            $prResult = $this->db->Execute(
                'SELECT transactionId, transactionDate, transactionTotal, invoiceNo, transactionPaymentType
                 FROM transaction
                 WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?
                 ORDER BY transactionDate DESC',
                [$transactionId, $companyId]
            );
            if ($prResult && !$prResult->EOF) {
                while (!$prResult->EOF) {
                    $r   = $prResult->fields;
                    $pm  = json_decode($r['transactionPaymentType'] ?? '[]', true) ?? [];
                    $paymentsReceived[] = [
                        'transactionId' => (string)($r['transactionId'] ?? ''),
                        'date'          => (string)($r['transactionDate'] ?? ''),
                        'amount'        => (float)($r['transactionTotal'] ?? 0),
                        'invoiceNo'     => (string)($r['invoiceNo'] ?? ''),
                        'paymentMethod' => (string)($pm[0]['name'] ?? ''),
                    ];
                    $prResult->MoveNext();
                }
                $prResult->Close();
            }
        }

        $cnResult = $this->db->Execute(
            'SELECT transactionId, transactionDate, transactionTotal, invoiceNo
             FROM transaction
             WHERE transactionParentId = ? AND transactionType = 6 AND companyId = ?',
            [$transactionId, $companyId]
        );
        if ($cnResult && !$cnResult->EOF) {
            while (!$cnResult->EOF) {
                $r = $cnResult->fields;
                $creditNotes[] = [
                    'transactionId'    => (string)($r['transactionId'] ?? ''),
                    'transactionDate'  => (string)($r['transactionDate'] ?? ''),
                    'transactionTotal' => (float)($r['transactionTotal'] ?? 0),
                    'invoiceNo'        => isset($r['invoiceNo']) ? (string)$r['invoiceNo'] : null,
                ];
                $cnResult->MoveNext();
            }
            $cnResult->Close();
        }

        $aptResult = $this->db->Execute(
            'SELECT transactionId, transactionDate, transactionTotal
             FROM transaction
             WHERE transactionParentId = ? AND transactionType = 13 AND companyId = ?',
            [$transactionId, $companyId]
        );
        if ($aptResult && !$aptResult->EOF) {
            while (!$aptResult->EOF) {
                $r = $aptResult->fields;
                $appointments[] = [
                    'transactionId'    => (string)($r['transactionId'] ?? ''),
                    'transactionDate'  => (string)($r['transactionDate'] ?? ''),
                    'transactionTotal' => (float)($r['transactionTotal'] ?? 0),
                ];
                $aptResult->MoveNext();
            }
            $aptResult->Close();
        }

        $address = null;
        if ((string) $fields['transactionType'] === '12') {
            $address = getCustomerTransactionAddress($transactionId, true);
        }

        $startDate = false;
        $startH    = false;
        $endH      = false;
        if (!empty($fields['fromDate']) && !empty($fields['toDate'])) {
            [$startDate, $startH, $endH] = dateStartEndTime($fields['fromDate'], $fields['toDate']);
        }

        $isSession = $fields['transactionParentId'] ? enc($fields['transactionParentId']) : false;
        $discount  = (float) ($fields['transactionDiscount'] ?? 0);
        $total     = (float) ($fields['transactionTotal'] ?? 0) - $discount;

        // transactionDetails en meta → _flattenJsonb expone como JSON string
        $rawDetails       = $fields['transactionDetails'] ?? null;
        $transactionDatas = $rawDetails ? json_decode($rawDetails, true) : null;

        $customerName = '';
        $customerUuid = (string) ($fields['customerId'] ?? '');
        if ($customerUuid !== '') {
            $cusRow = ncmExecute(
                "SELECT contactName, data->>'contactSecondName' AS contactSecondName FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1",
                [$customerUuid, $companyId]
            );
            if ($cusRow) {
                $customerName = $cusRow['contactName']
                    ? toUTF8($cusRow['contactName'])
                    : toUTF8($cusRow['contactSecondName'] ?? '');
            }
        }

        return [
            'transactionId'   => enc($fields['transactionId']),
            'customerId'      => enc($fields['customerId']),
            'customerUnd'     => $fields['customerId'],
            'customerName'    => $customerName,
            'userId'          => enc($fields['userId']),
            'note'            => $fields['transactionNote'],
            'tags'            => $tags,
            'documentNo'      => $fields['invoiceNo'],
            'invoicePrefix'   => $fields['invoicePrefix'] ?? '',
            'name'            => $fields['transactionName'],
            'type'            => $fields['transactionType'],
            'status'          => $fields['transactionStatus'],
            'date'            => $fields['transactionDate'],
            'dueDate'         => $fields['transactionDueDate'],
            'startDate'       => $startDate,
            'endDate'         => $fields['toDate'],
            'startHour'       => $startH,
            'endHour'         => $endH,
            'hasSession'      => $isSession,
            'isSession'       => $isSession,
            'parentID'        => $isSession,
            'UID'             => $fields['timestamp'],
            'pMethods'        => $paymentTypes,
            'toPay'           => $total - $payed,
            'total'           => number_format($total, 2, '.', ''),
            'discount'        => number_format($discount, 2, '.', ''),
            'payedData'       => $payedData,
            'transactionData'  => $rawDetails,
            'transactionDatas' => $transactionDatas,
            'address'          => $address,
            'creditPayments'   => $creditPayments,
            'creditNotes'      => $creditNotes,
            'appointments'     => $appointments,
            'paymentsReceived' => $paymentsReceived,
        ];
    }

    /**
     * Pagos de crédito/deuda (tipo 5) de una transacción padre.
     * Versión corregida de getAllTransactionPayments: parametriza el UUID (la versión
     * legacy lo interpolaba sin comillas → siempre falla en PG).
     */
    private function getTypePayments(string $transactionId, string $companyId): array
    {
        $result = $this->db->Execute(
            'SELECT transactionId, transactionTotal, userId, transactionDate, transactionPaymentType, invoiceNo
             FROM transaction
             WHERE transactionType = 5 AND transactionParentId = ? AND companyId = ?
             LIMIT 100',
            [$transactionId, $companyId]
        );
        if (!$result || $result->EOF) {
            return [];
        }
        $a = [];
        while (!$result->EOF) {
            $f   = $result->fields;
            $a[] = [
                'id'        => enc($f['transactionId']),
                'total'     => abs((float) $f['transactionTotal']),
                'userid'    => $f['userId'],
                'date'      => $f['transactionDate'],
                'methods'   => $f['transactionPaymentType'],
                'receiptNo' => $f['invoiceNo'],
            ];
            $result->MoveNext();
        }
        $result->Close();
        return $a;
    }


    /**
     * Elimina una transacción. Requiere scope companyId (tenant).
     * LIMIT 1 omitido: transactionId es PRIMARY KEY → único por definición.
     */
    public function delete(string $transactionId, string $companyId): bool
    {
        $res = $this->db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Actualiza la nota de una transacción. La nota llega en markup propio del POS;
     * `markupt2HTML` la convierte a HTML antes de persistir (igual que action.php legacy).
     * Multi-tenant: scope companyId obligatorio.
     */
    public function setNote(string $transactionId, string $companyId, string $note): bool
    {
        $html = markupt2HTML(['text' => $note, 'type' => 'HtM']);
        $res  = $this->db->Execute(
            'UPDATE transaction SET transactionNote = ?, updated_at = NOW()
              WHERE transactionId = ? AND companyId = ?',
            [$html, $transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Elimina un job de la cola printServer por transactionId + companyId.
     */
    public function deletePrintJob(string $transactionId, string $companyId): bool
    {
        $res = $this->db->Execute(
            'DELETE FROM printServer WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Cambia el estado de una transacción (slice 36b — strangler de action.php?action=sale&status=…).
     *
     * Encapsula la lógica de BD del legacy:
     *   - schedule + status=6: marca complete=1; si hay session vinculada a transacción padre,
     *     borra y recrea comisiones de itemSold leyendo de transactionDetails (meta jsonb).
     *     Si no hay session, INSERT en toScheduleUID si llegó uid.
     *   - schedule + status in [4,5] con padre: revierte a status=0 + limpia from/toDate +
     *     borra comisión asociada (sesión recurrente reseteada).
     *   - status != 'reminder': UPDATE transactionStatus + updated_at + nota opcional.
     *   - status === 'reminder': NO se cambia estado, solo se devuelve para que el caller
     *     dispare notificación (email/SMS de recordatorio).
     *
     * Side-effects de notificación (WS, push, email, SMS) NO van acá — los hace el endpoint.
     *
     * @param string $transactionId  UUID de la transacción.
     * @param string $newStatus      Status objetivo: '0'..'6' o 'reminder'.
     * @param string $companyId      UUID del tenant.
     * @param string $outletId       UUID del outlet.
     * @param ?string $motive        Motivo opcional (strip_tags antes de persistir).
     * @param bool   $isSchedule     true si el caller vino del módulo schedule (afecta la lógica de session/parent).
     * @param ?string $scheduleUid   UUID externo (tracking del scheduling), usado en INSERT toScheduleUID cuando no hay session.
     * @return array{
     *   ok: bool,
     *   finalStatus: string,
     *   customerId: ?string,
     *   userId: ?string,
     *   transactionDate: ?string,
     *   notifyNeeded: bool
     * }  customerId/userId/date para que el endpoint arme las notificaciones; notifyNeeded
     *    indica si el caller debe disparar email/SMS/push (schedule + final in [1,4,5,reminder]).
     */
    public function changeStatus(
        string $transactionId,
        string $newStatus,
        string $companyId,
        string $outletId,
        ?string $motive,
        bool $isSchedule,
        ?string $scheduleUid
    ): array {
        $finalStatus     = $newStatus;
        $extraUpdates    = [];     // columnas extra a setear ('fromDate'=>NULL, 'toDate'=>NULL, 'transactionComplete'=>1)

        // ----- Fase 1: lógica especial de schedule (completion + parent reversion) ----
        if ($isSchedule) {

            if ($newStatus === '6' || $newStatus === 6) {
                $extraUpdates['transactionComplete'] = 1;

                // ¿La transacción es session vinculada a venta padre?
                $session = ncmExecute(
                    'SELECT * FROM transaction
                      WHERE transactionType = 13 AND transactionParentId IS NOT NULL
                        AND invoicePrefix IS NOT NULL
                        AND transactionId = ? AND companyId = ? LIMIT 1',
                    [$transactionId, $companyId]
                );

                if ($session) {
                    // Recrear comisiones desde transactionDetails (meta jsonb).
                    $details = txDetailsFromMeta(txMetaRead($transactionId, $companyId, 13));
                    if (!empty($details)) {
                        $this->db->Execute(
                            'DELETE FROM comission WHERE transactionId = ?',
                            [$transactionId]
                        );
                        foreach ($details as $row) {
                            $rawItemId   = $row['itemId'] ?? '';
                            $itemId      = $rawItemId !== '' ? dec($rawItemId) : '';
                            $count       = $row['count'] ?? 0;
                            $uniPrice    = $row['uniPrice'] ?? 0;
                            $comTotal    = getItemComsissionTotal($itemId, $count, $uniPrice, true);
                            if ($comTotal) {
                                $this->db->AutoExecute('comission', [
                                    'comissionTotal'  => $comTotal,
                                    'comissionSource' => 'session',
                                    'userId'          => $session['userId'],
                                    'transactionId'   => $transactionId,
                                    'outletId'        => $outletId,
                                    'companyId'       => $companyId,
                                ], 'INSERT');
                            }
                        }
                    }
                } elseif ($scheduleUid !== null && $scheduleUid !== '') {
                    // No es session — solo trackear el uid externo del scheduling.
                    $this->db->AutoExecute('toScheduleUID', [
                        'scheduleId'     => $transactionId,
                        'transactionUID' => $scheduleUid,
                    ], 'INSERT');
                }

            } elseif ($newStatus === '4' || $newStatus === '5' || $newStatus === 4 || $newStatus === 5) {
                // ¿Es session de una recurrente? Reseteamos a status=0.
                $row = ncmExecute(
                    'SELECT transactionParentId FROM transaction
                      WHERE transactionId = ? AND companyId = ? LIMIT 1',
                    [$transactionId, $companyId]
                );
                $parent = $row['transactionParentId'] ?? null;
                if ($parent !== null && $parent !== '') {
                    $finalStatus = '0';
                    $extraUpdates['fromDate'] = null;
                    $extraUpdates['toDate']   = null;
                    $this->db->Execute(
                        'DELETE FROM comission WHERE transactionId = ?',
                        [$transactionId]
                    );
                }
            }
        }

        // ----- Fase 2: UPDATE de transactionStatus (salvo 'reminder' que no cambia estado) ----
        if ($finalStatus !== 'reminder') {
            $sets   = ['transactionStatus = ?', 'updated_at = NOW()'];
            $params = [(string) $finalStatus];

            if ($motive !== null && $motive !== '') {
                $sets[]   = 'transactionNote = ?';
                $params[] = strip_tags($motive);
            }
            foreach ($extraUpdates as $col => $val) {
                $sets[]   = "$col = ?";
                $params[] = $val;
            }

            $params[] = $transactionId;
            $params[] = $companyId;

            $res = $this->db->Execute(
                'UPDATE transaction SET ' . implode(', ', $sets)
                . ' WHERE transactionId = ? AND companyId = ?',
                $params
            );
            if ($res === false) {
                return [
                    'ok'              => false,
                    'finalStatus'     => (string) $finalStatus,
                    'customerId'      => null,
                    'userId'          => null,
                    'transactionDate' => null,
                    'notifyNeeded'    => false,
                ];
            }
        }

        // ----- Fase 3: traer datos para el caller (notificaciones) ------------------
        $notifyNeeded = $isSchedule && (in_array((string) $finalStatus, ['1', '4', '5'], true) || $finalStatus === 'reminder');

        $customerId      = null;
        $userId          = null;
        $transactionDate = null;
        if ($notifyNeeded) {
            $row = ncmExecute(
                'SELECT customerId, userId, transactionDate FROM transaction
                  WHERE transactionId = ? AND companyId = ? LIMIT 1',
                [$transactionId, $companyId]
            );
            if ($row) {
                $customerId      = $row['customerId']      ?? null;
                $userId          = $row['userId']          ?? null;
                $transactionDate = $row['transactionDate'] ?? null;
            }
        }

        return [
            'ok'              => true,
            'finalStatus'     => (string) $finalStatus,
            'customerId'      => $customerId,
            'userId'          => $userId,
            'transactionDate' => $transactionDate,
            'notifyNeeded'    => $notifyNeeded,
        ];
    }

    /**
     * Rechaza una orden: transactionStatus = 6 + nota opcional.
     * La notificación WS (sendWS) queda en el caller (api/v1/transactions.php).
     *
     * @param string      $transactionId  UUID de la transacción.
     * @param string      $companyId      UUID del tenant.
     * @param string|null $motive         Motivo del rechazo (opcional). Se strip_tags.
     * @return bool       true si no hubo error de BD (igual que el legacy !==false; no implica que existía).
     */
    public function reject(string $transactionId, string $companyId, ?string $motive): bool
    {
        $setCols = 'transactionStatus = ?, updated_at = NOW()';
        $params  = [6];

        if ($motive !== null && $motive !== '') {
            $setCols  .= ', transactionNote = ?';
            $params[]  = strip_tags($motive);
        }
        $params[] = $transactionId;
        $params[] = $companyId;

        $res = $this->db->Execute(
            "UPDATE transaction SET $setCols WHERE transactionId = ? AND companyId = ?",
            $params
        );
        return $res !== false;
    }

    /**
     * Registra la eliminación de un ítem en el historial (itemDeleted).
     * El motive se sanitiza con markupt2HTML (igual que el legacy; preserva formato markup).
     *
     * @param string $itemId    UUID del ítem eliminado.
     * @param string $motive    Motivo del borrado.
     * @param string $userId    UUID del usuario que lo borró (se guarda en el JSON data).
     * @param string $companyId UUID del tenant.
     * @param string $outletId  UUID del outlet.
     */
    public function recordItemDeletion(
        string $itemId,
        string $motive,
        string $userId,
        string $companyId,
        string $outletId
    ): bool {
        $data = json_encode(['motive' => markupt2HTML(['text' => $motive, 'type' => 'HtM']), 'user' => $userId]);
        $res  = $this->db->Execute(
            'INSERT INTO itemDeleted (itemId, date, data, companyId, outletId)
             VALUES (?, NOW(), ?, ?, ?)',
            [$itemId, $data, $companyId, $outletId]
        );
        return $res !== false;
    }

    // -------------------------------------------------------------------------
    // Slice 28 — quotesList + savedList (load.php L2863 + L2979)
    // -------------------------------------------------------------------------

    /**
     * Lista paginada de transacciones para el panel de ventas (buildList).
     * Cubre listType = 'quotes' (type 9) y 'saved' (type 2).
     *
     * Retorna { date, listName, transactionsList[], footBtn } — mismo shape
     * que OrderService::getList (orders), compatible con #transactionsListTpl.
     *
     * Fija el SQL injection del legacy (concatenación de COMPANY_ID / outletId /
     * customerId / dates directamente en la cadena SQL).
     */
    // -------------------------------------------------------------------------
    // Slice 29 — transactions (load.php L2260) — lista principal de ventas
    // -------------------------------------------------------------------------

    /**
     * Lista principal de transacciones para el panel de ventas (buildList default).
     *
     * Tipos: 0 (contado), 3 (crédito), 6 (devolución), 7 (anulado), 9 (cotización),
     *        10 (delivery). Roles 4/5: solo tipos 2 y 10, filtrado por userId.
     *
     * Fija el SQL injection del legacy (COMPANY_ID/OUTLET_ID/userId/dates/ids
     * concatenados en strings). Los IDs de crédito batched via IN(?) son UUIDs
     * provenientes de nuestra propia query — no vienen del usuario.
     *
     * Retorna { date, listName, transactionsList[], footBtn } — compatible con
     * #transactionsListTpl. La ruta HTML nunca se usa vía buildList (json:1 siempre).
     */
    public function getMainList(
        string  $outletId,
        string  $companyId,
        string  $userId,
        string  $roleId,
        ?string $encCustomerId,
        ?string $date,
        int     $limit,
        int     $offset = 0,
        string  $q = '',
        ?int    $typeFilter = null
    ): array {
        global $dec, $ts;

        $limit  = min(100, max(1, $limit));
        $offset = max(0, $offset);

        $where  = ['t.companyId = ?'];
        $params = [$companyId];

        if (!\RoleService::hasPermission('reports.sales.view', (string) $roleId, $companyId)) {
            $where[]  = 't.transactionType IN (2, 10)';
            $where[]  = 't.userId = ?';
            $params[] = $userId;
        } else {
            $where[] = 't.transactionType IN (0, 3, 6, 7, 9, 10)';
        }

        if ($encCustomerId) {
            $where[]  = 't.customerId = ?';
            $params[] = dec($encCustomerId);
        } else {
            $where[]  = 't.outletId = ?';
            $params[] = $outletId;
        }

        if ($date) {
            $where[]  = 't.transactionDate BETWEEN ? AND ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
            $limit    = 2000;
            $offset   = 0;
        }

        if ($q !== '') {
            $where[]  = '(t.invoiceNo::text ILIKE ? OR t.transactionId::text ILIKE ? OR c.contactName ILIKE ?)';
            $like     = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($typeFilter !== null) {
            $where[]  = 't.transactionType = ?';
            $params[] = $typeFilter;
        }

        $sql = 'SELECT t.* FROM transaction t LEFT JOIN contact c ON t.customerId = c.contactId WHERE ' . implode(' AND ', $where)
             . ' ORDER BY t.transactionDate DESC LIMIT ' . (int) $limit
             . ' OFFSET ' . (int) $offset;
        $rs  = $this->db->Execute($sql, $params);

        // First pass: collect IDs and row references for batch subquery
        $trsIds = [];
        $rows   = [];
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f        = $rs->fields;   // CaseInsensitiveArray — each MoveNext() creates a new one
                $trsIds[] = (string) $f['transactionId'];
                $rows[]   = $f;
                $rs->MoveNext();
            }
        }

        // Batch: paid/returned amounts for credit transactions (type 3)
        $toPaidMap = [];
        if (!empty($trsIds)) {
            $ph     = implode(',', array_fill(0, count($trsIds), '?'));
            $paidRs = $this->db->Execute(
                "SELECT SUM(ABS(transactionTotal)) AS payed, transactionParentId
                   FROM transaction
                  WHERE transactionType IN (5, 6) AND companyId = ?
                    AND transactionParentId IN ($ph)
                  GROUP BY transactionParentId",
                array_merge([$companyId], $trsIds)
            );
            if ($paidRs && !$paidRs->EOF) {
                while (!$paidRs->EOF) {
                    $pf = $paidRs->fields;
                    $toPaidMap[(string) $pf['transactionParentId']] = (float) ($pf['payed'] ?? 0);
                    $paidRs->MoveNext();
                }
            }
        }

        $out = [
            'date'             => $date,
            'listName'         => 'Transacciones',
            'transactionsList' => [],
            'footBtn'          => '',
        ];

        foreach ($rows as $f) {
            $type   = (string) ($f['transactionType']   ?? '');
            $status = (string) ($f['transactionStatus'] ?? '');
            $trsId  = (string) $f['transactionId'];
            $tTotal = (float) ($f['transactionTotal']  ?? 0) - (float) ($f['transactionDiscount'] ?? 0);
            $topay  = 0;

            if ($type === '3') {
                $topay = $tTotal - ($toPaidMap[$trsId] ?? 0);
            }

            // --- borderColor (type 13 has different scheme) ---
            $stat = 'b-5x b-l ';
            if ($type === '13') {
                switch ($status) {
                    case '0': $stat .= ($f['transactionComplete'] == 1) ? 'b-primary' : 'b-light'; break;
                    case '1': $stat .= 'b-info';    break;
                    case '2': $stat .= 'b-warning'; break;
                    case '3': $stat .= 'b-success'; break;
                    case '4': $stat .= 'b-danger';  break;
                    case '5': $stat .= 'b-dark';    break;
                    case '6': $stat  = '';           break;
                }
            } else {
                switch ($status) {
                    case '0': case '1': $stat .= 'b-light';          break;
                    case '2':           $stat .= 'b-info';            break;
                    case '3':           $stat .= 'b-primary';         break;
                    case '4':           $stat .= 'b-success';         break;
                    case '5':           $stat .= 'b-danger';          break;
                    case '6':           $stat  = 'b-3x b-l b-dark';   break;
                }
            }

            // --- typeOfSale label ---
            if ($type === '3') {
                if ($topay > ($tTotal / 2))     { $typeText = 'bg-light text-danger'; }
                elseif ($topay <= ($tTotal / 2)){ $typeText = 'bg-light text-warning-dker'; }
                if ($topay < 1)                 { $typeText = 'bg-light text-success'; }
                $typeOfSale = '<span class="label ' . ($typeText ?? '') . ' text-xs">Crédito</span>';
            } elseif ($type === '2')  { $typeOfSale = '<span class="label text-dark b b-light text-xs">Guardado</span>';    }
            elseif  ($type === '6')   { $typeOfSale = '<span class="label bg-danger lter text-xs">Devolución</span>';       }
            elseif  ($type === '7')   { $typeOfSale = '<span class="label bg-dark text-xs">Anulado</span>';                }
            elseif  ($type === '9')   { $typeOfSale = '<span class="label bg-warning dk text-xs">Cotización</span>';       }
            elseif  ($type === '10')  { $typeOfSale = '<span class="label text-info b b-light text-xs">Envío</span>';      }
            elseif  ($type === '11')  { $typeOfSale = '<span class="label bg-success text-xs">Orden</span>';               }
            elseif  ($type === '13')  { $typeOfSale = '<span class="label bg-primary text-xs">Agenda</span>';              }
            else                      { $typeOfSale = '<span class="label bg-light text-xs">Contado</span>';               }

            // --- name prefix (non-default transactionNames) ---
            $txName = (string) ($f['transactionName'] ?? '');
            $name   = ($txName !== 'Sale' && $txName !== 'Quote' && $txName !== '')
                ? '<span class="text-info">' . $txName . '</span>'
                : '';

            // --- customer name (per-row lookup; N+1 preserved for fidelity) ---
            $customerId = (string) ($f['customerId'] ?? '');
            $customerD  = '';
            if ($customerId !== '') {
                $cusRow = ncmExecute(
                    "SELECT contactName, data->>'contactSecondName' AS contactSecondName FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1",
                    [$customerId, $companyId]
                );
                if ($cusRow) {
                    $customerD = $cusRow['contactName']
                        ? toUTF8($cusRow['contactName'])
                        : toUTF8($cusRow['contactSecondName'] ?? '');
                }
            }

            $inTotal = formatCurrentNumber($tTotal, $dec, $ts);
            $dateStr = niceDate($f['transactionDate'] ?? '', true);

            $out['transactionsList'][] = [
                'id'              => enc($trsId),
                'title'           => $name . ' ' . $customerD,
                'customerName'    => $customerD,
                'date'            => $dateStr,
                'rawDate'         => (string) ($f['transactionDate'] ?? ''),
                'docNumber'       => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                'invoiceNo'       => (string) ($f['invoiceNo'] ?? ''),
                'invoicePrefix'   => (string) ($f['invoicePrefix'] ?? ''),
                'amount'          => $inTotal,
                'rawTotal'        => $tTotal,
                'debt'            => ($type === '3') ? max(0.0, $topay) : 0,
                'label'           => $typeOfSale,
                'type'            => $f['transactionType'],
                'borderColor'     => $stat,
            ];
        }

        $out['hasMore'] = !$date && count($rows) === $limit;

        $ecuid = $encCustomerId ?? '';
        if (!empty($out['transactionsList'])) {
            $out['footBtn'] = $date
                ? '<a href="#transactions&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</a>'
                : '<a href="#transactions&l=' . ($limit + 50) . '&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</a>';
        } else {
            $out['footBtn'] = '<a href="#transactions&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</a>';
        }

        return $out;
    }

    public function getTransactionList(
        string  $listType,
        string  $outletId,
        string  $companyId,
        ?string $encCustomerId,
        ?string $date,
        int     $limit
    ): array {
        global $dec, $ts;

        static $configs = [
            'quotes' => [
                'txType'   => 9,
                'listName' => 'Cotizaciones',
                'label'    => '<span class="label bg-warning dk text-xs">Cotización</span>',
                'anchor'   => 'openQuotes',
                'colors'   => ['0'=>'b-light','1'=>'b-light','2'=>'b-info','3'=>'b-primary',
                               '4'=>'b-success','5'=>'b-danger','6'=>'b-3x b-l b-dark'],
            ],
            'saved' => [
                'txType'   => 2,
                'listName' => 'Guardado',
                'label'    => '<span class="label text-dark b b-light text-xs">Guardado</span>',
                'anchor'   => 'openSaved',
                'colors'   => ['0'=>'b-light','1'=>'b-light','2'=>'b-info','3'=>'b-primary',
                               '4'=>'b-success','5'=>'b-danger','6'=>'b-3x b-l b-dark'],
            ],
        ];

        $cfg = $configs[$listType] ?? $configs['quotes'];

        $where  = ['transactionType = ?', 'companyId = ?'];
        $params = [(int) $cfg['txType'], $companyId];

        if ($encCustomerId) {
            $where[]  = 'customerId = ?';
            $params[] = dec($encCustomerId);
        } else {
            $where[]  = 'outletId = ?';
            $params[] = $outletId;
        }

        if ($date) {
            $where[]  = 'transactionDate BETWEEN ? AND ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
            $limit    = 1500;
        }

        $sql = 'SELECT * FROM transaction WHERE ' . implode(' AND ', $where)
             . ' ORDER BY transactionDate DESC LIMIT ' . (int) $limit;
        $rs  = $this->db->Execute($sql, $params);

        $anchor = $cfg['anchor'];
        $out = [
            'date'             => $date,
            'listName'         => $cfg['listName'],
            'transactionsList' => [],
            'footBtn'          => '',
        ];

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $status = (string) ($f['transactionStatus'] ?? '');
                $cusData = getCustomerData($f['customerId'] ?? null, 'uid');
                $cusName = iftn(getCustomerName($cusData), 'Sin Nombre');

                $txName = ($listType === 'saved')
                    ? '<span class="text-info">' . ($f['transactionName'] ?? '') . '</span>'
                    : '';

                $stat = 'b-5x b-l ' . ($cfg['colors'][$status] ?? '');

                $out['transactionsList'][] = [
                    'id'          => enc($f['transactionId']),
                    'title'       => $txName . ' ' . $cusName,
                    'date'        => niceDate($f['transactionDate']),
                    'docNumber'   => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                    'amount'      => formatCurrentNumber($f['transactionTotal'], $dec, $ts),
                    'label'       => $cfg['label'],
                    'type'        => $f['transactionType'],
                    'borderColor' => $stat,
                ];

                $rs->MoveNext();
            }

            $out['footBtn'] = $date
                ? '<a href="#' . $anchor . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>'
                : '<a href="#' . $anchor . '&l=' . ($limit + 50) . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        } else {
            $out['footBtn'] = '<a href="#' . $anchor . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

        return $out;
    }

    /**
     * Anula una transacción (type→7). Port de voidSale() (app/includes/functions.php:4658).
     *
     * Bugs PG corregidos vs el legacy:
     *   - giftcard restore: WHERE incompleto (`AND outletId = ` sin valor) → parametrizado.
     *   - points/storeCredit: `contactAmount + $dat['price']` concatenado → parametrizado.
     *   - companyName: `getValue('setting','settingName',...)` — tabla `setting` no existe
     *     en PG → usa la constante COMPANY_NAME (definida tras apiAuthTenant).
     *   - responsibleId: usa el userId del JWT (el que realmente operó la caja).
     *
     * Side-effects (updateLastTimeEdit, sendAuditoria) van en el caller (api/v1/transactions.php)
     * para mantener el servicio puro de BD (misma separación que reject/delete).
     *
     * @return bool true si la anulación se guardó; false si la tx falló.
     */
    public function voidTransaction(
        string $transactionId,
        string $companyId,
        string $outletId,
        string $userId,
        string $motive = ''
    ): bool {
        $this->db->StartTrans();

        // 1. Leer métodos de pago para reponer balances de cliente/giftcard.
        $tx = ncmExecute(
            'SELECT customerId, transactionPaymentType, outletId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );

        if ($tx) {
            $customerId = $tx['customerId'] ?? null;
            // Iterar los pagos RAW (no groupByPaymentMethod) porque groupBy hace
            // unset($nu['extra']) antes de pushear — $dat['extra'] sería siempre null
            // y el restore de giftcard nunca funcionaría. Para el void basta iterar
            // cada línea de pago individualmente (no necesitamos agrupar totales).
            $payments = json_decode($tx['transactionPaymentType'] ?? '', true);
            foreach (is_array($payments) ? $payments : [] as $payment) {
                $type  = (string) ($payment['type']  ?? '');
                $price = (float)  ($payment['price'] ?? 0);
                if ($price <= 0) {
                    continue;
                }

                // Devolver balance del cliente (solo si hay cliente válido).
                if (validity($customerId) && $type === 'points') {
                    $this->db->Execute(
                        "UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount + ?, updated_at = ? WHERE contactId = ?",
                        [$price, TODAY, $customerId]
                    );
                } elseif (validity($customerId) && $type === 'storeCredit') {
                    $this->db->Execute(
                        "UPDATE contact SET contactStoreCredit = contactStoreCredit + ?, updated_at = ? WHERE contactId = ?",
                        [$price, TODAY, $customerId]
                    );
                } elseif ($type === 'giftcard') {
                    // Devolver saldo a gift card — FIX: WHERE completo + companyId scope
                    // + parametrizado. El legacy tenía `AND outletId = ` sin valor →
                    // sintácticamente rota. También: groupByPaymentMethod descarta
                    // 'extra' → el legacy nunca restauró giftcards en void (bug silencioso).
                    $extra = $payment['extra'] ?? null;
                    if (is_numeric($extra)) {
                        $this->db->Execute(
                            'UPDATE giftCardSold SET giftCardSoldValue = giftCardSoldValue + ? WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ?',
                            [$price, $extra, $extra, $companyId]
                        );
                    }
                }
            }
        }

        // 2. Marcar transacción como anulada (type=7).
        ncmUpdate([
            'records' => [
                'transactionType' => '7',
                'transactionNote' => $motive,
                'responsibleId'   => $userId,
            ],
            'table' => 'transaction',
            'where' => "transactionId = '" . $transactionId . "'",
        ]);

        // 3. Eliminar sub-transacciones (pagos type=5/6 vinculados por parentId).
        ncmExecute('DELETE FROM transaction WHERE transactionParentId = ?', [$transactionId]);

        // 4. Reponer inventario por cada itemSold.
        $items = ncmExecute(
            'SELECT itemId, itemSoldUnits FROM itemSold WHERE transactionId = ?',
            [$transactionId],
            false,
            true
        );
        if ($items) {
            while (!$items->EOF) {
                $f      = $items->fields;
                $itemId = $f['itemid'] ?? $f['itemId'] ?? '';
                $units  = (float) ($f['itemsoldunits'] ?? $f['itemSoldUnits'] ?? 0);

                // Reponer ingredientes si es compound.
                $compound = getCompoundsArray($itemId);
                if (is_array($compound) && $compound !== []) {
                    foreach ($compound as $comr) {
                        $comId  = $comr['compoundId'];
                        $locRow = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$comId, $companyId]);
                        manageStock([
                            'itemId'        => $comId,
                            'outletId'      => $outletId,
                            'date'          => TODAY,
                            'count'         => abs((float) $comr['toCompoundQty'] * $units),
                            'source'        => 'void',
                            'locationId'    => $locRow['locationId'] ?? null,
                            'transactionId' => $transactionId,
                        ]);
                    }
                }

                $locRow = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itemId, $companyId]);
                manageStock([
                    'itemId'        => $itemId,
                    'outletId'      => $outletId,
                    'date'          => TODAY,
                    'locationId'    => $locRow['locationId'] ?? null,
                    'count'         => abs($units),
                    'source'        => 'void',
                    'transactionId' => $transactionId,
                ]);

                $items->MoveNext();
            }
            $items->Close();
        }

        // 5. Eliminar itemSold y giftCardSold vinculados.
        $this->db->Execute('DELETE FROM itemSold WHERE transactionId = ?', [$transactionId]);
        $this->db->Execute('DELETE FROM giftCardSold WHERE transactionId = ?', [$transactionId]);

        $failed = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();

        return !$failed;
    }
}
