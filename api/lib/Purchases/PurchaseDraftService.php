<?php
declare(strict_types=1);

namespace Punto\Api\Purchases;

use Punto\Api\Support\TenantClock;

/**
 * Borradores de compra generados por OCR/IA (context/32-ocr-facturas-compra.md).
 *
 * v1 = foto → extracción IA (BFF Next, api/ocr-invoice/route.ts) → borrador
 * `pending` → revisión/corrección humana → `approve` crea la compra real vía
 * `PurchasesService::create` (mismo código que el alta manual). La IA NUNCA
 * escribe stock/finanzas — solo llena `extracted` (jsonb crudo, inmutable) y
 * sube la imagen. `edited` es un `PurchaseCreatePayload` (mismo shape que el
 * form manual de /purchase) que el frontend arma a partir de `extracted` y
 * va corrigiendo; `approve` usa SIEMPRE `edited`, nunca reinterpreta
 * `extracted` del lado del servidor — evita duplicar el parseo de fechas/
 * impuestos/prefijos que ya vive en el form.
 *
 * Tabla `purchase_draft` (mig 105): columnas en minúscula sin comillas —
 * mismo motivo que las tablas de Finanzas (72_finance.sql): ncmInsert/
 * ncmExecute arman SQL con las keys del array PHP sin comillas.
 *
 * Soporte PDF (2026-07-31, context/32): `imageref` (columna) y el campo
 * `image` del multipart de creación pueden contener una foto (JPG/PNG/WEBP)
 * O un PDF de factura — el nombre no cambió para evitar un refactor de
 * schema innecesario. `uploadImage()` es un dispatcher por mime real: las
 * imágenes pasan por el pipeline de resize+recompresión de siempre; los PDF
 * se suben TAL CUAL (passthrough, sin GD) porque el modelo de OCR
 * (google/gemini-3.5-flash) lee el PDF nativo, incluido multipágina.
 */
final class PurchaseDraftService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_INPUT_BYTES = 8 * 1024 * 1024; // 8 MB — imágenes y PDF
    private const MAX_DIMENSION = 2000; // px — más grande que ItemImageService (1024):
    // una factura necesita legibilidad de números finos (nro, RUC, montos),
    // una foto de producto no.
    private const JPEG_QUALITY = 90;
    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const PDF_MIME = 'application/pdf';

    /**
     * Lista borradores con paginación + filtro por status. Devuelve resumen
     * (no `edited`/`extracted` completo) para listados livianos.
     *
     * @param array{status?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'd.companyid = ?';
        $params = [$companyId];

        if (!empty($filters['status'])) {
            $where   .= ' AND d.status = ?';
            $params[] = (string) $filters['status'];
        }

        $limit  = isset($filters['limit'])  ? max(1, min(200, (int) $filters['limit'])) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM purchase_draft d WHERE {$where}", $params);
        $total    = (int) ($totalRow['n'] ?? 0);

        $sql = "SELECT d.draftid, d.status, d.imageref, d.extracted::text AS extracted_raw,
                       d.contactid, d.transactionid, d.error, d.created_at, d.approved_at,
                       c.contactName AS contactname
                  FROM purchase_draft d
                  LEFT JOIN contact c ON c.contactId = d.contactid
                 WHERE {$where}
                 ORDER BY d.created_at DESC
                 LIMIT {$limit} OFFSET {$offset}";

        $res  = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($res) {
            while (!$res->EOF) {
                $rows[] = $this->presentSummary($res->fields);
                $res->MoveNext();
            }
            $res->Close();
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Detalle completo (incluye `extracted` + `edited`). */
    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        // ::text AS *_raw: mismo patrón que PurchasesService::find — el
        // wrapper aplana/borra columnas jsonb reales al leerlas (CaseInsensitiveArray
        // + flattenJsonb), así que el alias es la única forma confiable de
        // recuperar el contenido crudo.
        $row = ncmExecute(
            "SELECT d.*, d.extracted::text AS extracted_raw, d.edited::text AS edited_raw,
                    c.contactName AS contactname, c.contactTIN AS contacttin
               FROM purchase_draft d
               LEFT JOIN contact c ON c.contactId = d.contactid
              WHERE d.draftid = ? AND d.companyid = ?
              LIMIT 1",
            [$id, $companyId]
        );
        if (!$row) {
            return null;
        }
        return $this->present($row);
    }

    /**
     * Crea un borrador a partir de una imagen + el JSON crudo devuelto por la
     * IA (o null/error si la extracción falló — igual se deja el borrador
     * para que el usuario cargue los datos a mano en vez de perder la foto).
     * Sube la imagen a S3 y hace auto-match de proveedor por RUC.
     *
     * @param array $file $_FILES['image']
     */
    public function create(
        string $companyId,
        string $outletId,
        string $userId,
        array $file,
        ?array $extracted,
        ?string $extractError = null
    ): array {
        if (!preg_match(self::UUID_RE, $companyId)) {
            throw new \RuntimeException('companyId inválido');
        }
        if (!preg_match(self::UUID_RE, $outletId)) {
            throw new \RuntimeException('outletId inválido');
        }
        if (!preg_match(self::UUID_RE, $userId)) {
            throw new \RuntimeException('userId requerido (sesión inválida)');
        }

        $draftId  = function_exists('generateUuidV7') ? generateUuidV7() : $this->uuidV4();
        $imageUrl = $this->uploadImage($companyId, $draftId, $file);

        // Auto-match de proveedor por RUC — sugerencia corregible, nunca
        // vinculante: el usuario puede cambiarla en la pantalla de revisión.
        $contactId = null;
        $ruc = trim((string) ($extracted['supplier']['ruc'] ?? ''));
        if ($ruc !== '') {
            $match = ncmExecute(
                'SELECT contactId FROM contact
                  WHERE companyId = ? AND contactType = 2 AND contactStatus = 1 AND contactTIN = ?
                  LIMIT 1',
                [$companyId, $ruc]
            );
            if ($match) {
                $contactId = (string) $match['contactId'];
            }
        }

        $extractedJson = json_encode($extracted ?? new \stdClass());
        if ($extractedJson === false) {
            $extractedJson = '{}';
        }

        // Sin extracción todavía → el borrador entra a la COLA (mig 176): la
        // imagen ya está guardada y visible en el listado, y la IA lo enriquece
        // después. Con extracción (camino síncrono legacy) nace listo para
        // revisar. Un error de extracción es 'failed', no 'pending': un
        // borrador vacío en la bandeja de revisión no se distinguía de uno que
        // el modelo sí pudo leer.
        $status = $extracted !== null
            ? ($extractError !== null ? 'failed' : 'pending')
            : ($extractError !== null ? 'failed' : 'queued');

        $inserted = ncmInsert([
            'records' => [
                'draftid'   => $draftId,
                'companyid' => $companyId,
                'outletid'  => $outletId,
                'userid'    => $userId,
                'status'    => $status,
                'imageref'  => $imageUrl,
                'extracted' => $extractedJson,
                'contactid' => $contactId,
                'error'     => $extractError,
            ],
            'table' => 'purchase_draft',
        ]);
        if (!$inserted) {
            throw new \RuntimeException('No se pudo guardar el borrador');
        }

        return $this->find($draftId, $companyId) ?? [];
    }

    /**
     * Toma el borrador para procesar: 'queued' → 'processing'.
     *
     * El UPDATE condicionado por status es el lock: si dos procesos intentan el
     * mismo borrador (el `after()` del upload y el job de requeue pisándose),
     * solo uno afecta filas y el otro se va sin trabajo. Sin esto, la misma
     * factura se extrae dos veces y se cobran dos veces los créditos.
     *
     * @return bool true si ESTE llamador se quedó con el trabajo.
     */
    public function claim(string $id, string $companyId): bool
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return false;
        }
        // `ncmExecute` devuelve la FILA (o falsy si no hubo ninguna) — con
        // RETURNING alcanza para saber si ganamos el lock.
        $row = ncmExecute(
            "UPDATE purchase_draft
                SET status = 'processing', processing_at = now(), attempts = attempts + 1
              WHERE draftid = ? AND companyid = ? AND status IN ('queued', 'processing')
                AND (status = 'queued' OR processing_at < now() - interval '10 minutes')
              RETURNING draftid",
            [$id, $companyId]
        );
        return (bool) $row;
    }

    /**
     * Cierra la extracción: escribe lo que la IA devolvió y pasa a 'pending'
     * (listo para revisar) o 'failed'.
     *
     * Vuelve a intentar el match de proveedor por RUC — al crear el borrador
     * todavía no había datos extraídos, así que es acá donde por primera vez se
     * conoce el RUC del emisor.
     */
    public function completeExtraction(
        string $id,
        string $companyId,
        ?array $extracted,
        ?string $extractError = null
    ): array {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $extractedJson = json_encode($extracted ?? new \stdClass());
        if ($extractedJson === false) {
            $extractedJson = '{}';
        }

        $contactId = null;
        $ruc = trim((string) ($extracted['supplier']['ruc'] ?? ''));
        if ($ruc !== '') {
            $match = ncmExecute(
                'SELECT contactId FROM contact
                  WHERE companyId = ? AND contactType = 2 AND contactStatus = 1 AND contactTIN = ?
                  LIMIT 1',
                [$companyId, $ruc]
            );
            if ($match) {
                $contactId = (string) $match['contactId'];
            }
        }

        $status = $extractError !== null ? 'failed' : 'pending';
        // SOLO cierra borradores que están en la cola. Sin este guard, un
        // `?resource=complete` tardío (o malicioso) sobre uno ya APROBADO lo
        // devolvía a 'pending' con `extracted` arbitrario, y como approve() es
        // idempotente por status, el segundo approve creaba una SEGUNDA compra
        // real. También evita des-rechazar un borrador que el usuario descartó
        // mientras la IA todavía lo estaba leyendo.
        ncmExecute(
            "UPDATE purchase_draft
                SET status = ?, extracted = ?::jsonb, error = ?, contactid = COALESCE(?, contactid)
              WHERE draftid = ? AND companyid = ? AND status IN ('queued', 'processing')",
            [$status, $extractedJson, $extractError, $contactId, $id, $companyId]
        );
        return $this->find($id, $companyId) ?? [];
    }

    /**
     * Reencola un borrador que no se pudo leer. Resetea `attempts` porque es
     * una decisión NUEVA del usuario, no un reintento automático: si la foto
     * era ilegible y la reemplaza el criterio de "ya se intentó 3 veces" no
     * aplica.
     */
    public function retry(string $id, string $companyId): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $row = ncmExecute(
            "UPDATE purchase_draft
                SET status = 'queued', attempts = 0, processing_at = NULL, error = NULL
              WHERE draftid = ? AND companyid = ? AND status = 'failed'
              RETURNING draftid",
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Solo se puede reintentar un borrador que falló');
        }
        return $this->find($id, $companyId) ?? [];
    }

    /**
     * Devuelve a la cola los borradores que quedaron colgados en 'processing'
     * (el proceso murió a mitad — típicamente un deploy recicló el contenedor
     * mientras la IA respondía) y marca 'failed' los que ya agotaron intentos.
     *
     * @return array{requeued:int, failed:int}
     */
    public function requeueStale(int $staleMinutes = 10, int $maxAttempts = 3): array
    {
        global $db;
        $failed = $db->Execute(
            "UPDATE purchase_draft
                SET status = 'failed',
                    error = COALESCE(error, 'La extracción no pudo completarse tras varios intentos')
              WHERE status = 'processing'
                AND processing_at < now() - (? || ' minutes')::interval
                AND attempts >= ?
              RETURNING draftid",
            [$staleMinutes, $maxAttempts]
        );
        $failedCount = 0;
        while ($failed !== false && !$failed->EOF) { $failedCount++; $failed->MoveNext(); }

        $requeued = $db->Execute(
            "UPDATE purchase_draft
                SET status = 'queued'
              WHERE status = 'processing'
                AND processing_at < now() - (? || ' minutes')::interval
                AND attempts < ?
              RETURNING draftid",
            [$staleMinutes, $maxAttempts]
        );
        $requeuedCount = 0;
        while ($requeued !== false && !$requeued->EOF) { $requeuedCount++; $requeued->MoveNext(); }

        return ['requeued' => $requeuedCount, 'failed' => $failedCount];
    }

    /**
     * Guarda correcciones del usuario. `edited` reemplaza entero (el
     * frontend manda el form completo, no un patch parcial — mismo shape que
     * `PurchaseCreatePayload`). Solo se puede editar mientras está `pending`.
     */
    public function update(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $current = ncmExecute(
            'SELECT status FROM purchase_draft WHERE draftid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$current) {
            throw new \RuntimeException('Borrador no encontrado');
        }
        if ((string) $current['status'] !== 'pending') {
            throw new \RuntimeException('Solo se puede editar un borrador pendiente');
        }

        $sets   = [];
        $params = [];

        if (array_key_exists('edited', $data)) {
            $editedJson = json_encode(is_array($data['edited']) ? $data['edited'] : []);
            $sets[]   = 'edited = ?::jsonb';
            $params[] = $editedJson !== false ? $editedJson : '{}';
        }
        if (array_key_exists('contactId', $data)) {
            $contactId = trim((string) ($data['contactId'] ?? ''));
            if ($contactId !== '' && !preg_match(self::UUID_RE, $contactId)) {
                throw new \RuntimeException('contactId inválido');
            }
            $sets[]   = 'contactid = ?';
            $params[] = $contactId !== '' ? $contactId : null;
        }

        if (empty($sets)) {
            return $this->find($id, $companyId) ?? [];
        }

        $params[] = $id;
        $params[] = $companyId;
        ncmExecute(
            'UPDATE purchase_draft SET ' . implode(', ', $sets) . ' WHERE draftid = ? AND companyid = ?',
            $params
        );

        return $this->find($id, $companyId) ?? [];
    }

    /**
     * Aprueba el borrador: crea la compra real vía `PurchasesService::create`
     * con el payload editado. Idempotente — un draft ya `approved` devuelve
     * el mismo `transactionId` sin volver a crear nada. Lock `FOR UPDATE`
     * (mismo patrón que `LoanService::payInstallment`) para que dos clicks
     * concurrentes en "Aprobar" no generen dos compras.
     *
     * `$editedOverride`: si el frontend manda `edited` junto con el approve
     * (guardar+aprobar en un solo request), pisa lo guardado antes de crear
     * la compra. Si es null, usa el último `edited` persistido vía `update`.
     */
    public function approve(string $id, string $companyId, string $userId, ?array $editedOverride = null): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        if (!preg_match(self::UUID_RE, $userId)) {
            throw new \RuntimeException('userId requerido (sesión inválida)');
        }

        $db->StartTrans();

        $row = ncmExecute(
            'SELECT * FROM purchase_draft WHERE draftid = ? AND companyid = ? LIMIT 1 FOR UPDATE',
            [$id, $companyId]
        );
        if (!$row) {
            $db->CompleteTrans();
            throw new \RuntimeException('Borrador no encontrado');
        }

        $status = (string) $row['status'];

        // Idempotencia por TRANSACCIÓN, no solo por status: si el borrador ya
        // generó una compra, no puede generar otra aunque su status haya vuelto
        // a 'pending' por algún camino (un `complete` tardío, una corrección
        // manual en BD). El status es un estado editable; `transactionid` es el
        // hecho consumado.
        if ($row['transactionid'] !== null && (string) $row['transactionid'] !== '') {
            $db->CompleteTrans();
            return [
                'draftId'         => $id,
                'status'          => $status === 'approved' ? 'approved' : $status,
                'transactionId'   => (string) $row['transactionid'],
                'alreadyApproved' => true,
                'warning'         => null,
            ];
        }

        if ($status === 'approved') {
            $db->CompleteTrans();
            return [
                'draftId'         => $id,
                'status'          => 'approved',
                'transactionId'   => $row['transactionid'] !== null ? (string) $row['transactionid'] : null,
                'alreadyApproved' => true,
                'warning'         => null,
            ];
        }
        if ($status === 'rejected') {
            $db->CompleteTrans();
            throw new \RuntimeException('El borrador fue rechazado — no se puede aprobar');
        }

        $edited = $editedOverride;
        if ($edited === null) {
            $raw = $row['edited'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $edited  = is_array($decoded) ? $decoded : null;
            } elseif (is_array($raw)) {
                $edited = $raw;
            }
        }
        if (!is_array($edited) || empty($edited['items'])) {
            $db->CompleteTrans();
            throw new \RuntimeException('Revisá y guardá los datos del borrador antes de aprobar');
        }

        // Server decide siempre outletId/userId finales — nunca confía en lo
        // que mande el body del cliente para estos dos campos.
        $edited['outletId'] = $edited['outletId'] ?? (string) $row['outletid'];
        $edited['userId']   = $userId;

        // Warning NO bloqueante: misma factura + mismo proveedor ya registrada.
        // Se calcula ANTES de crear (si create() falla, no queremos el warning).
        $warning = $this->findDuplicateWarning($companyId, $edited);

        $purchases = new PurchasesService();
        try {
            $transactionId = $purchases->create($companyId, $edited);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la compra: ' . $e->getMessage());
        }

        ncmExecute(
            "UPDATE purchase_draft SET status = 'approved', transactionid = ?, approved_at = ?
              WHERE draftid = ? AND companyid = ?",
            [$transactionId, TenantClock::now($companyId), $id, $companyId]
        );

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo aprobar el borrador: la transacción abortó');
        }

        return [
            'draftId'         => $id,
            'status'          => 'approved',
            'transactionId'   => $transactionId,
            'alreadyApproved' => false,
            'warning'         => $warning,
        ];
    }

    /** Rechaza el borrador. Idempotente; no se puede rechazar uno ya aprobado. */
    public function reject(string $id, string $companyId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }

        $db->StartTrans();

        $row = ncmExecute(
            'SELECT status FROM purchase_draft WHERE draftid = ? AND companyid = ? LIMIT 1 FOR UPDATE',
            [$id, $companyId]
        );
        if (!$row) {
            $db->CompleteTrans();
            throw new \RuntimeException('Borrador no encontrado');
        }
        $status = (string) $row['status'];
        if ($status === 'approved') {
            $db->CompleteTrans();
            throw new \RuntimeException('El borrador ya fue aprobado — no se puede rechazar');
        }
        if ($status !== 'rejected') {
            ncmExecute(
                "UPDATE purchase_draft SET status = 'rejected' WHERE draftid = ? AND companyid = ?",
                [$id, $companyId]
            );
        }

        $db->CompleteTrans();

        return ['draftId' => $id, 'status' => 'rejected'];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Warning no bloqueante (fuera de alcance v1 el bloqueo — ver plan):
     * ¿ya existe una compra activa con el mismo invoiceNo + mismo proveedor?
     * Match por supplierId (no por texto de RUC) — el supplierId ya es la
     * resolución 1:1 al contacto, más robusto que comparar strings de RUC
     * con formatos distintos.
     */
    private function findDuplicateWarning(string $companyId, array $edited): ?string
    {
        $invoiceNo  = isset($edited['invoiceNo']) && $edited['invoiceNo'] !== '' ? (int) $edited['invoiceNo'] : null;
        $supplierId = (string) ($edited['supplierId'] ?? '');
        if ($invoiceNo === null || $supplierId === '' || !preg_match(self::UUID_RE, $supplierId)) {
            return null;
        }
        $match = ncmExecute(
            'SELECT transactionId FROM transaction
              WHERE companyId = ? AND transactionType IN (1,4) AND transactionStatus <> 6
                AND invoiceNo = ? AND supplierId = ?
              LIMIT 1',
            [$companyId, $invoiceNo, $supplierId]
        );
        return $match ? 'Ya existe una compra registrada con esta factura y este proveedor.' : null;
    }

    private function presentSummary(array|\CaseInsensitiveArray $f): array
    {
        $extracted = $this->decodeJsonb($f['extracted_raw'] ?? null) ?? [];
        return [
            'id'            => (string) $f['draftid'],
            'status'        => (string) $f['status'],
            'imageUrl'      => $f['imageref'] !== null ? (string) $f['imageref'] : null,
            'supplierName'  => $f['contactname'] ?? ($extracted['supplier']['name'] ?? null),
            'invoiceNumber' => $extracted['invoice']['number'] ?? null,
            'total'         => isset($extracted['totals']['total']) ? (float) $extracted['totals']['total'] : null,
            'confidence'    => isset($extracted['confidence']) ? (float) $extracted['confidence'] : null,
            'transactionId' => $f['transactionid'] !== null ? (string) $f['transactionid'] : null,
            'error'         => $f['error'] ?? null,
            'createdAt'     => (string) $f['created_at'],
        ];
    }

    private function present(array|\CaseInsensitiveArray $row): array
    {
        $extracted = $this->decodeJsonb($row['extracted_raw'] ?? $row['extracted'] ?? null);
        return [
            'id'            => (string) $row['draftid'],
            'status'        => (string) $row['status'],
            'imageUrl'      => $row['imageref'] !== null ? (string) $row['imageref'] : null,
            'extracted'     => $extracted,
            'edited'        => $this->decodeJsonb($row['edited_raw'] ?? $row['edited'] ?? null),
            'warnings'      => $this->computeWarnings($extracted),
            'contactId'     => $row['contactid'] !== null ? (string) $row['contactid'] : null,
            'contactName'   => $row['contactname'] ?? null,
            'contactTin'    => $row['contacttin'] ?? null,
            'transactionId' => $row['transactionid'] !== null ? (string) $row['transactionid'] : null,
            'outletId'      => (string) $row['outletid'],
            'userId'        => (string) $row['userid'],
            'error'         => $row['error'] ?? null,
            'createdAt'     => (string) $row['created_at'],
            'approvedAt'    => $row['approved_at'] !== null ? (string) $row['approved_at'] : null,
        ];
    }

    /**
     * Validación aritmética post-extracción — la IA no valida sus propias
     * sumas y nadie más lo chequeaba. Compara (1) suma de items[].total vs
     * totals.subtotal y (2) subtotal + iva5 + iva10 - discount vs
     * totals.total, con tolerancia ±1 (redondeos de la imagen/OCR).
     *
     * NO bloquea nada — son warnings informativos para la pantalla de
     * revisión, el humano decide igual. Única fuente de esta validación:
     * se recalcula acá en cada lectura a partir de `extracted` (jsonb
     * inmutable, nunca se persiste un resultado que pueda desincronizarse).
     *
     * @param array<string,mixed>|null $extracted
     * @return string[]
     */
    private function computeWarnings(?array $extracted): array
    {
        if ($extracted === null) {
            return [];
        }

        $tolerance = 1.0;
        $warnings  = [];

        $items  = is_array($extracted['items'] ?? null) ? $extracted['items'] : [];
        $totals = is_array($extracted['totals'] ?? null) ? $extracted['totals'] : [];

        $itemsSum      = 0.0;
        $hasItemTotals = false;
        foreach ($items as $item) {
            if (is_array($item) && isset($item['total']) && is_numeric($item['total'])) {
                $itemsSum += (float) $item['total'];
                $hasItemTotals = true;
            }
        }

        $subtotal = isset($totals['subtotal']) && is_numeric($totals['subtotal']) ? (float) $totals['subtotal'] : null;
        if ($hasItemTotals && $subtotal !== null && abs($itemsSum - $subtotal) > $tolerance) {
            $warnings[] = 'Los montos no cuadran: la suma de los ítems no coincide con el subtotal.';
        }

        $iva5     = isset($totals['iva5']) && is_numeric($totals['iva5']) ? (float) $totals['iva5'] : 0.0;
        $iva10    = isset($totals['iva10']) && is_numeric($totals['iva10']) ? (float) $totals['iva10'] : 0.0;
        $discount = isset($totals['discount']) && is_numeric($totals['discount']) ? (float) $totals['discount'] : 0.0;
        $total    = isset($totals['total']) && is_numeric($totals['total']) ? (float) $totals['total'] : null;

        if ($subtotal !== null && $total !== null) {
            $expected = $subtotal + $iva5 + $iva10 - $discount;
            if (abs($expected - $total) > $tolerance) {
                $warnings[] = 'Los montos no cuadran: subtotal + IVA - descuento no coincide con el total.';
            }
        }

        return $warnings;
    }

    private function decodeJsonb($val): ?array
    {
        if ($val === null) {
            return null;
        }
        if (is_array($val)) {
            return $val;
        }
        if (is_string($val) && $val !== '') {
            $decoded = json_decode($val, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Sube el archivo de la factura a S3/DO Spaces. Dispatcher por mime
     * REAL (finfo — nunca el Content-Type declarado por el cliente):
     * imagen → pipeline de resize+recompresión de siempre; PDF →
     * passthrough. Límite de 8 MB para ambos. Mime no soportado o archivo
     * excedido → error claro en español.
     */
    private function uploadImage(string $companyId, string $draftId, array $file): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Archivo no recibido');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Archivo vacío o mayor a 8 MB');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string) $finfo->file($file['tmp_name']);

        if ($realMime === self::PDF_MIME) {
            return $this->uploadPdf($companyId, $draftId, $file['tmp_name']);
        }
        if (in_array($realMime, self::ALLOWED_IMAGE_MIMES, true)) {
            return $this->uploadImageFile($companyId, $draftId, $file['tmp_name']);
        }
        throw new \RuntimeException('Formato no soportado — subí una foto (JPG/PNG/WEBP) o un PDF');
    }

    /** PDF: se guarda TAL CUAL — sin GD, sin resize, sin recompresión. */
    private function uploadPdf(string $companyId, string $draftId, string $tmpPath): string
    {
        $data = file_get_contents($tmpPath);
        if ($data === false) {
            throw new \RuntimeException('No se pudo leer el archivo');
        }
        $objectKey = "purchase-drafts/{$companyId}/{$draftId}.pdf";
        $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX);
        return $s3->put($objectKey, $data, self::PDF_MIME, true);
    }

    /** Imagen: pipeline de siempre — resize a MAX_DIMENSION + recompresión JPEG q90. */
    private function uploadImageFile(string $companyId, string $draftId, string $tmpPath): string
    {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new \RuntimeException('El archivo no es una imagen válida');
        }

        $data = file_get_contents($tmpPath);
        if ($data === false) {
            throw new \RuntimeException('No se pudo leer el archivo');
        }
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new \RuntimeException('No se pudo procesar la imagen');
        }

        [$origW, $origH] = [imagesx($src), imagesy($src)];
        if ($origW > self::MAX_DIMENSION || $origH > self::MAX_DIMENSION) {
            $ratio = $origW / $origH;
            if ($origW >= $origH) {
                $newW = self::MAX_DIMENSION;
                $newH = (int) round(self::MAX_DIMENSION / $ratio);
            } else {
                $newW = (int) round(self::MAX_DIMENSION * $ratio);
                $newH = self::MAX_DIMENSION;
            }
            $dst   = imagecreatetruecolor($newW, $newH);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, self::JPEG_QUALITY);
        $jpegData = (string) ob_get_clean();
        imagedestroy($src);

        $objectKey = "purchase-drafts/{$companyId}/{$draftId}.jpg";
        $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX);
        return $s3->put($objectKey, $jpegData, 'image/jpeg', true);
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
