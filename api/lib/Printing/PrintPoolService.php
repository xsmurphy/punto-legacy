<?php
declare(strict_types=1);

namespace Punto\Api\Printing;

/**
 * PrintPoolService — núcleo de la Estación de Impresión (P0,
 * context/26-print-station-plan.md).
 *
 * La estación es un ROUTER TONTO: un device pareado (module='print', ver
 * DISPLAY_MODULES en api/v1/screens.php) que corre en la PC con las
 * impresoras físicas. No hay tabla `print_station` — la identidad de la
 * estación ES el device; las `station_printer` cuelgan de él.
 *
 * `print_job` es la cola durable (fuente de verdad — el WS solo notifica).
 * Transiciones con CAS (`UPDATE ... WHERE "status" = '...'`), mismo criterio
 * que OrderCoreService/ORDER_TRANSITIONS: el UPDATE deja de matchear si otra
 * estación ya reclamó el job, evitando imprimir dos veces.
 *
 * Patrón de service: mismo que OrderCoreService.php — $companyId explícito
 * en cada método público, ncmExecute() para lecturas y para los CAS (el
 * wrapper devuelve las filas afectadas reales en UPDATE/DELETE sin
 * RETURNING), StartTrans/HasFailedTrans/CompleteTrans solo donde hay
 * multi-statement (registerPrinter: check + insert/update).
 */
final class PrintPoolService
{
    /**
     * Máquina de estados de print_job.status: queued → printing → (done |
     * failed → queued si attempts < MAX_ATTEMPTS) | cancelled (solo desde
     * queued). Cada transición vive como CAS explícito (UPDATE ... WHERE
     * "status" = '<origen>') en el método correspondiente — no hay tabla de
     * whitelist genérica porque, a diferencia de OrderCoreService, acá solo
     * hay UN actor por transición (la propia estación en claim/done/failed,
     * el panel en cancel).
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Lista cerrada de docType — espejo de PrinterDocType en
     * frontend/lib/hardware/printers/binding.ts. Única copia en PHP:
     * PrinterBindingService::validate() también valida contra esta constante.
     */
    public const DOC_TYPES = ['receipt', 'factura', 'quote', 'order', 'withdraw', 'delivery', 'closeReg', 'return'];

    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // station_printer
    // ------------------------------------------------------------------

    /**
     * Registra (o re-anuncia) una impresora física desde la estación. Upsert
     * por (companyId, outletId, deviceId, transport, transportConfig): la
     * estación re-anuncia su hardware en cada arranque/reconexión — sin el
     * upsert, cada reconexión duplicaría la fila.
     *
     * @param array{name?:string, kind?:string, transport:string, transportConfig?:array} $data
     */
    public function registerPrinter(string $companyId, string $outletId, string $deviceId, array $data): array
    {
        global $db;

        $validated = $this->validatePrinter($data);

        $db->StartTrans();

        $existing = ncmExecute(
            'SELECT "id" FROM "station_printer"
              WHERE "companyId" = ? AND "outletId" = ? AND "deviceId" = ?
                AND "transport" = ? AND "transportConfig" = ?::jsonb
              LIMIT 1',
            [$companyId, $outletId, $deviceId, $validated['transport'], json_encode($validated['transportConfig'])]
        );

        if ($existing) {
            $id = (string) $existing['id'];
            $ok = $this->db->Execute(
                'UPDATE "station_printer"
                    SET "name" = ?, "kind" = ?, "status" = 1, "updatedAt" = now()
                  WHERE "id" = ? AND "companyId" = ?',
                [$validated['name'], $validated['kind'], $id, $companyId]
            );
            if ($ok === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo actualizar la impresora');
            }
        } else {
            $rs = $this->db->Execute(
                'INSERT INTO "station_printer"
                    ("id","companyId","outletId","deviceId","name","kind","transport","transportConfig")
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?::jsonb)
                 RETURNING "id"',
                [$companyId, $outletId, $deviceId, $validated['name'], $validated['kind'],
                 $validated['transport'], json_encode($validated['transportConfig'])]
            );
            if ($rs === false || $rs->EOF) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo registrar la impresora');
            }
            $id = (string) $rs->fields['id'];
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al registrar la impresora (transacción abortada)');
        }

        $printer = $this->findPrinter($companyId, $id);
        if ($printer === null) {
            throw new \RuntimeException('No se pudo leer la impresora recién registrada');
        }
        return $printer;
    }

    /**
     * Update de datos editables desde panel (rename) o desde la propia
     * estación (status). No cambia deviceId/transport — eso es un nuevo
     * registerPrinter().
     *
     * @param array{name?:string, kind?:string, status?:int} $data
     */
    public function updatePrinter(string $companyId, string $id, array $data): array
    {
        $sets   = [];
        $params = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('name no puede estar vacío');
            }
            $sets[]   = '"name" = ?';
            $params[] = $name;
        }
        if (array_key_exists('kind', $data)) {
            $kind = (string) $data['kind'];
            if (!in_array($kind, ['thermal', 'inkjet', 'matrix', 'generic'], true)) {
                throw new \InvalidArgumentException('kind inválido');
            }
            $sets[]   = '"kind" = ?';
            $params[] = $kind;
        }
        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];
            if (!in_array($status, [0, 1], true)) {
                throw new \InvalidArgumentException('status inválido (0|1)');
            }
            $sets[]   = '"status" = ?';
            $params[] = $status;
        }
        if ($sets === []) {
            throw new \InvalidArgumentException('Nada que actualizar');
        }
        $sets[] = '"updatedAt" = now()';

        $params[] = $id;
        $params[] = $companyId;
        $affected = ncmExecute(
            'UPDATE "station_printer" SET ' . implode(', ', $sets) . ' WHERE "id" = ? AND "companyId" = ?',
            $params
        );
        if (!is_int($affected) || $affected < 1) {
            throw new \RuntimeException('Impresora no encontrada');
        }

        $printer = $this->findPrinter($companyId, $id);
        if ($printer === null) {
            throw new \RuntimeException('Impresora no encontrada');
        }
        return $printer;
    }

    /** Soft-delete (status=0) — mismo criterio que revocar un device/pantalla. */
    public function deletePrinter(string $companyId, string $id): void
    {
        $affected = ncmExecute(
            'UPDATE "station_printer" SET "status" = 0, "updatedAt" = now() WHERE "id" = ? AND "companyId" = ?',
            [$id, $companyId]
        );
        if (!is_int($affected) || $affected < 1) {
            throw new \RuntimeException('Impresora no encontrada');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listPrinters(string $companyId, ?string $outletId = null): array
    {
        $where  = ['"companyId" = ?'];
        $params = [$companyId];
        if ($outletId !== null && $outletId !== '') {
            $where[]  = '"outletId" = ?';
            $params[] = $outletId;
        }
        $rs = $this->db->Execute(
            'SELECT * FROM "station_printer" WHERE ' . implode(' AND ', $where) . '
             ORDER BY "status" DESC, "name" ASC',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->presentPrinter($row);
        }
        return $out;
    }

    public function findPrinter(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM "station_printer" WHERE "id" = ? AND "companyId" = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        return $this->presentPrinter($rs->fields);
    }

    // ------------------------------------------------------------------
    // print_job
    // ------------------------------------------------------------------

    /**
     * Encola un job. El que origina (POS/panel) ya renderizó el payload —
     * este service NO conoce templates, solo enruta. outletId sale de la
     * station_printer (fuente de verdad), no del caller.
     *
     * @param array{stationPrinterId:string, format?:string, payload:string,
     *              docType?:string, copies?:int, openDrawer?:bool,
     *              sourceKind?:string, sourceId?:string} $data
     */
    public function enqueue(string $companyId, string $createdByDeviceId, array $data, ?string $requireOutletId = null): array
    {
        $stationPrinterId = (string) ($data['stationPrinterId'] ?? '');
        $payload           = (string) ($data['payload'] ?? '');
        $format            = (string) ($data['format'] ?? 'escpos');
        $docType           = isset($data['docType']) && $data['docType'] !== '' ? (string) $data['docType'] : null;
        $copies            = max(1, min(10, (int) ($data['copies'] ?? 1)));
        $openDrawer        = filter_var($data['openDrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sourceKind        = isset($data['sourceKind']) && $data['sourceKind'] !== '' ? (string) $data['sourceKind'] : null;
        $sourceId          = isset($data['sourceId']) && $data['sourceId'] !== '' ? (string) $data['sourceId'] : null;

        if ($stationPrinterId === '') {
            throw new \InvalidArgumentException('stationPrinterId requerido');
        }
        if ($payload === '') {
            throw new \InvalidArgumentException('payload requerido');
        }
        if (!in_array($format, ['escpos', 'html', 'raw'], true)) {
            throw new \InvalidArgumentException("format inválido (esperado 'escpos'|'html'|'raw')");
        }
        if ($docType !== null && !in_array($docType, self::DOC_TYPES, true)) {
            throw new \InvalidArgumentException("docType inválido (esperado " . implode('|', self::DOC_TYPES) . ')');
        }

        // stationPrinterId debe pertenecer al tenant y estar activa — el
        // outletId del job SIEMPRE sale de acá (nunca del body del caller).
        $printer = ncmExecute(
            'SELECT "outletId" FROM "station_printer" WHERE "id" = ? AND "companyId" = ? AND "status" = 1 LIMIT 1',
            [$stationPrinterId, $companyId]
        );
        if (!$printer) {
            throw new \InvalidArgumentException('stationPrinterId inválido para este tenant');
        }
        $outletId = (string) $printer['outletId'];

        // Scope de sucursal (hallazgo del review P0): un device pos-app solo
        // encola hacia impresoras de SU outlet — sin esto una caja de la
        // sucursal A podía imprimir en la cocina de la B. Panel pasa null
        // (admin cross-outlet, p.ej. reimprimir desde auditoría).
        if ($requireOutletId !== null && $requireOutletId !== '' && $outletId !== $requireOutletId) {
            throw new \InvalidArgumentException('stationPrinterId pertenece a otra sucursal');
        }

        $rs = $this->db->Execute(
            'INSERT INTO "print_job"
                ("id","companyId","outletId","stationPrinterId","docType","format","payload",
                 "copies","openDrawer","sourceKind","sourceId","createdByDeviceId")
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING "id"',
            [$companyId, $outletId, $stationPrinterId, $docType, $format, $payload,
             $copies, $openDrawer ? 't' : 'f', $sourceKind, $sourceId,
             $createdByDeviceId !== '' ? $createdByDeviceId : null]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo encolar el job de impresión');
        }
        $jobId = (string) $rs->fields['id'];

        $job = $this->findJob($companyId, $jobId);
        if ($job !== null) {
            wsPublish($companyId . ':print:' . $outletId, 'job:new', $job);
            realtimePublish('printJob', 'create', $jobId);
        }
        return $job ?? [];
    }

    /** Jobs `queued` de las impresoras que cuelgan de esta estación (device). */
    public function listPending(string $companyId, string $stationDeviceId): array
    {
        $rs = $this->db->Execute(
            'SELECT j.* FROM "print_job" j
               JOIN "station_printer" p ON p."id" = j."stationPrinterId"
              WHERE j."companyId" = ? AND p."deviceId" = ? AND j."status" = \'queued\'
              ORDER BY j."createdAt" ASC',
            [$companyId, $stationDeviceId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->presentJob($row);
        }
        return $out;
    }

    /**
     * CAS queued → printing (+attempts). Scopeada a las impresoras de esta
     * estación — una estación no puede reclamar el job de otra.
     */
    public function claim(string $companyId, string $jobId, string $stationDeviceId): array
    {
        $affected = ncmExecute(
            'UPDATE "print_job" SET "status" = \'printing\', "attempts" = "attempts" + 1, "updatedAt" = now()
              WHERE "id" = ? AND "companyId" = ? AND "status" = \'queued\'
                AND "stationPrinterId" IN (SELECT "id" FROM "station_printer" WHERE "companyId" = ? AND "deviceId" = ?)',
            [$jobId, $companyId, $companyId, $stationDeviceId]
        );
        if (!is_int($affected) || $affected < 1) {
            throw new \RuntimeException('Job no encontrado, no pertenece a esta estación, o ya no está queued');
        }
        $job = $this->findJob($companyId, $jobId);
        if ($job !== null) {
            wsPublish($companyId . ':print:' . $job['outletId'], 'job:update', $job);
            realtimePublish('printJob', 'update', $jobId);
        }
        return $job ?? [];
    }

    /** CAS printing → done. */
    public function markDone(string $companyId, string $jobId, string $stationDeviceId): array
    {
        $affected = ncmExecute(
            'UPDATE "print_job" SET "status" = \'done\', "updatedAt" = now()
              WHERE "id" = ? AND "companyId" = ? AND "status" = \'printing\'
                AND "stationPrinterId" IN (SELECT "id" FROM "station_printer" WHERE "companyId" = ? AND "deviceId" = ?)',
            [$jobId, $companyId, $companyId, $stationDeviceId]
        );
        if (!is_int($affected) || $affected < 1) {
            throw new \RuntimeException('Job no encontrado, no pertenece a esta estación, o ya no está printing');
        }
        $job = $this->findJob($companyId, $jobId);
        if ($job !== null) {
            wsPublish($companyId . ':print:' . $job['outletId'], 'job:update', $job);
            realtimePublish('printJob', 'update', $jobId);
        }
        return $job ?? [];
    }

    /**
     * CAS printing → (queued si attempts < MAX_ATTEMPTS, sino failed) + lastError.
     * El retry es automático: la estación (u otra) vuelve a ver el job en su
     * próximo listPending()/claim().
     */
    public function markFailed(string $companyId, string $jobId, string $stationDeviceId, string $error): array
    {
        $row = ncmExecute(
            'UPDATE "print_job"
                SET "status" = CASE WHEN "attempts" < ? THEN \'queued\' ELSE \'failed\' END,
                    "lastError" = ?, "updatedAt" = now()
              WHERE "id" = ? AND "companyId" = ? AND "status" = \'printing\'
                AND "stationPrinterId" IN (SELECT "id" FROM "station_printer" WHERE "companyId" = ? AND "deviceId" = ?)
              RETURNING "id"',
            [self::MAX_ATTEMPTS, $error, $jobId, $companyId, $companyId, $stationDeviceId]
        );
        if (!$row) {
            throw new \RuntimeException('Job no encontrado, no pertenece a esta estación, o ya no está printing');
        }
        $job = $this->findJob($companyId, $jobId);
        if ($job !== null) {
            wsPublish($companyId . ':print:' . $job['outletId'], 'job:update', $job);
            realtimePublish('printJob', 'update', $jobId);
        }
        return $job ?? [];
    }

    /** CAS queued → cancelled. Desde panel (auditoría/gestión de la cola). */
    public function cancel(string $companyId, string $jobId): array
    {
        $affected = ncmExecute(
            'UPDATE "print_job" SET "status" = \'cancelled\', "updatedAt" = now()
              WHERE "id" = ? AND "companyId" = ? AND "status" = \'queued\'',
            [$jobId, $companyId]
        );
        if (!is_int($affected) || $affected < 1) {
            throw new \RuntimeException('Job no encontrado o ya no está queued');
        }
        $job = $this->findJob($companyId, $jobId);
        if ($job !== null) {
            wsPublish($companyId . ':print:' . $job['outletId'], 'job:update', $job);
            realtimePublish('printJob', 'update', $jobId);
        }
        return $job ?? [];
    }

    /** @return list<array<string,mixed>> auditoría/reimpresión (panel). */
    public function listJobs(string $companyId, array $filters = []): array
    {
        $where  = ['"companyId" = ?'];
        $params = [$companyId];

        if (!empty($filters['outletId'])) {
            $where[]  = '"outletId" = ?';
            $params[] = (string) $filters['outletId'];
        }
        if (!empty($filters['stationPrinterId'])) {
            $where[]  = '"stationPrinterId" = ?';
            $params[] = (string) $filters['stationPrinterId'];
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[]  = "\"status\" IN ($placeholders)";
            foreach ($statuses as $s) $params[] = (string) $s;
        }
        if (!empty($filters['from'])) {
            $where[]  = '"createdAt" >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = '"createdAt" <= ?';
            $params[] = (string) $filters['to'];
        }

        $rs = $this->db->Execute(
            'SELECT * FROM "print_job" WHERE ' . implode(' AND ', $where) . '
             ORDER BY "createdAt" DESC LIMIT 500',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->presentJob($row);
        }
        return $out;
    }

    public function findJob(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM "print_job" WHERE "id" = ? AND "companyId" = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        return $this->presentJob($rs->fields);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array{name:string, kind:string, transport:string, transportConfig:array} */
    private function validatePrinter(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name requerido');
        }
        $kind = (string) ($data['kind'] ?? 'thermal');
        if (!in_array($kind, ['thermal', 'inkjet', 'matrix', 'generic'], true)) {
            throw new \InvalidArgumentException('kind inválido');
        }
        $transport = (string) ($data['transport'] ?? '');
        if (!in_array($transport, ['usb', 'bluetooth', 'network', 'native'], true)) {
            throw new \InvalidArgumentException("transport inválido (esperado 'usb'|'bluetooth'|'network'|'native')");
        }
        $transportConfig = $data['transportConfig'] ?? [];
        if (!is_array($transportConfig)) {
            throw new \InvalidArgumentException('transportConfig debe ser objeto');
        }
        return compact('name', 'kind', 'transport', 'transportConfig');
    }

    private function presentPrinter(array|\CaseInsensitiveArray $row): array
    {
        $rawConfig = $row['transportConfig'] ?? '{}';
        $config    = is_string($rawConfig) ? (json_decode($rawConfig, true) ?: []) : (is_array($rawConfig) ? $rawConfig : []);
        return [
            'id'              => (string) ($row['id'] ?? ''),
            'companyId'       => (string) ($row['companyId'] ?? ''),
            'outletId'        => (string) ($row['outletId'] ?? ''),
            'deviceId'        => (string) ($row['deviceId'] ?? ''),
            'name'            => (string) ($row['name'] ?? ''),
            'kind'            => (string) ($row['kind'] ?? 'thermal'),
            'transport'       => (string) ($row['transport'] ?? ''),
            'transportConfig' => $config,
            'status'          => (int) ($row['status'] ?? 0),
            'createdAt'       => $row['createdAt'] ?? null,
            'updatedAt'       => $row['updatedAt'] ?? null,
        ];
    }

    private function presentJob(array|\CaseInsensitiveArray $row): array
    {
        return [
            'id'                => (string) ($row['id'] ?? ''),
            'companyId'         => (string) ($row['companyId'] ?? ''),
            'outletId'          => (string) ($row['outletId'] ?? ''),
            'stationPrinterId'  => (string) ($row['stationPrinterId'] ?? ''),
            'docType'           => $row['docType'] ?? null,
            'format'            => (string) ($row['format'] ?? 'escpos'),
            'payload'           => (string) ($row['payload'] ?? ''),
            'copies'            => (int) ($row['copies'] ?? 1),
            'openDrawer'        => (bool) ($row['openDrawer'] ?? false),
            'status'            => (string) ($row['status'] ?? ''),
            'attempts'          => (int) ($row['attempts'] ?? 0),
            'lastError'         => $row['lastError'] ?? null,
            'sourceKind'        => $row['sourceKind'] ?? null,
            'sourceId'          => $row['sourceId'] ?? null,
            'createdByDeviceId' => $row['createdByDeviceId'] ?? null,
            'createdAt'         => $row['createdAt'] ?? null,
            'updatedAt'         => $row['updatedAt'] ?? null,
        ];
    }
}
