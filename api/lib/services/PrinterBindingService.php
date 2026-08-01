<?php
namespace Punto\Api\Services;

class PrinterBindingService {
    private string $companyId;
    private string $outletId;

    public function __construct(string $companyId, string $outletId) {
        $this->companyId = $companyId;
        $this->outletId  = $outletId;
    }

    private function registerBelongsToTenant(string $registerId): bool {
        $rs = ncmExecute(
            'SELECT 1 FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $this->companyId],
            false, true
        );
        return $rs && !$rs->EOF;
    }

    /** outletId REAL de la caja (no $this->outletId — para panel eso puede
     *  venir vacío/cross-outlet; la validación de transport=station necesita
     *  la sucursal de la caja dueña del binding, igual que el guard de
     *  PrintPoolService::enqueue). */
    private function registerOutletId(string $registerId): ?string {
        $rs = ncmExecute(
            'SELECT outletId FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $this->companyId],
            false, true
        );
        if (!$rs || $rs->EOF) return null;
        $v = $rs->fields['outletId'] ?? null;
        $rs->Close();
        return $v !== null ? (string)$v : null;
    }

    /** companyId de un stationPrinterId no alcanza — debe pertenecer a la
     *  MISMA sucursal que la caja del binding. */
    private function stationPrinterBelongsToOutlet(string $stationPrinterId, string $outletId): bool {
        $rs = ncmExecute(
            'SELECT 1 FROM "station_printer" WHERE "id" = ? AND "companyId" = ? AND "outletId" = ? LIMIT 1',
            [$stationPrinterId, $this->companyId, $outletId],
            false, true
        );
        return $rs && !$rs->EOF;
    }

    public function list(string $registerId): array {
        if (!$this->registerBelongsToTenant($registerId)) return [];
        $rs = ncmExecute(
            'SELECT * FROM "printer_binding" WHERE "registerId" = ? AND "companyId" = ? ORDER BY "createdAt" ASC',
            [$registerId, $this->companyId],
            false, true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->normalize($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    public function create(string $registerId, array $data): array {
        if (!$this->registerBelongsToTenant($registerId)) {
            throw new \RuntimeException('registerId no pertenece al tenant', 403);
        }
        $validated = $this->validate($data, true, $registerId);
        $rs = ncmExecute(
            'INSERT INTO "printer_binding"
               ("id","companyId","outletId","registerId","name","color","transport",
                "vendorId","productId","deviceLabel","mode","templateId","paperWidthMm",
                "copies","openDrawer","autoPrint","printDelay","categoryIds","docTypes",
                bluetoothdeviceid,networkhost,networkport,"stationPrinterId")
             VALUES
               (gen_random_uuid(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?::jsonb,?,?,?,?)
             RETURNING *',
            [
                $this->companyId,
                $this->outletId,
                $registerId,
                $validated['name'],
                $validated['color'],
                $validated['transport'],
                $validated['vendorId'],
                $validated['productId'],
                $validated['deviceLabel'],
                $validated['mode'],
                $validated['templateId'],
                $validated['paperWidthMm'],
                $validated['copies'],
                $validated['openDrawer'] ? 't' : 'f',
                $validated['autoPrint'] ? 't' : 'f',
                $validated['printDelay'],
                json_encode($validated['categoryIds']),
                json_encode($validated['docTypes']),
                $validated['bluetoothDeviceId'] ?? null,
                $validated['networkHost'] ?? null,
                $validated['networkPort'] ?? null,
                $validated['stationPrinterId'] ?? null,
            ],
            false, true
        );
        if (!$rs || $rs->EOF) throw new \RuntimeException('Error al crear binding', 500);
        $row = $this->normalize($rs->fields);
        $rs->Close();
        return $row;
    }

    public function update(string $id, array $data): array {
        // transport=station necesita la sucursal de la caja dueña del binding
        // para validar el stationPrinterId — el body de update() rara vez trae
        // registerId (solo id + los campos que cambian).
        $registerId = null;
        $rowRs = ncmExecute(
            'SELECT "registerId" FROM "printer_binding" WHERE "id" = ? AND "companyId" = ? LIMIT 1',
            [$id, $this->companyId],
            false, true
        );
        if ($rowRs && !$rowRs->EOF) {
            $registerId = (string) $rowRs->fields['registerId'];
            $rowRs->Close();
        }

        $validated = $this->validate($data, false, $registerId);
        $sets = [];
        $params = [];
        $fieldMap = [
            'name' => '"name"', 'color' => '"color"', 'transport' => '"transport"',
            'vendorId' => '"vendorId"', 'productId' => '"productId"',
            'deviceLabel' => '"deviceLabel"', 'mode' => '"mode"',
            'templateId' => '"templateId"', 'paperWidthMm' => '"paperWidthMm"',
            'copies' => '"copies"', 'openDrawer' => '"openDrawer"',
            'autoPrint' => '"autoPrint"', 'printDelay' => '"printDelay"',
            'bluetoothDeviceId' => 'bluetoothdeviceid',
            'networkHost'       => 'networkhost',
            'networkPort'       => 'networkport',
            'stationPrinterId'  => '"stationPrinterId"',
        ];
        foreach ($fieldMap as $key => $col) {
            if (array_key_exists($key, $validated)) {
                $sets[] = "$col = ?";
                $val = $validated[$key];
                if (is_bool($val)) $val = $val ? 't' : 'f';
                $params[] = $val;
            }
        }
        if (isset($validated['categoryIds'])) {
            $sets[] = '"categoryIds" = ?::jsonb';
            $params[] = json_encode($validated['categoryIds']);
        }
        if (isset($validated['docTypes'])) {
            $sets[] = '"docTypes" = ?::jsonb';
            $params[] = json_encode($validated['docTypes']);
        }
        if (empty($sets)) throw new \RuntimeException('Nada que actualizar', 422);
        $sets[] = '"updatedAt" = now()';
        $params[] = $id;
        $params[] = $this->companyId;
        $rs = ncmExecute(
            'UPDATE "printer_binding" SET ' . implode(', ', $sets) .
            ' WHERE "id" = ? AND "companyId" = ? RETURNING *',
            $params,
            false, true
        );
        if (!$rs || $rs->EOF) throw new \RuntimeException('Binding no encontrado', 404);
        $row = $this->normalize($rs->fields);
        $rs->Close();
        return $row;
    }

    public function delete(string $id): void {
        ncmExecute(
            'DELETE FROM "printer_binding" WHERE "id" = ? AND "companyId" = ?',
            [$id, $this->companyId],
            false, false
        );
    }

    private function validate(array $data, bool $requireAll = true, ?string $registerId = null): array {
        $out = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name !== '' || $requireAll) {
            if ($name === '') throw new \RuntimeException('name requerido', 422);
            if (strlen($name) > 120) throw new \RuntimeException('name demasiado largo', 422);
            $out['name'] = $name;
        }

        if (isset($data['color']) || $requireAll) {
            // Acepta un key de la paleta unificada (color-palette.ts) O un hex
            // legacy. La UI hoy manda el key (ej. "amber"); resolveColorBg() lo
            // resuelve en lectura. Antes solo validaba hex → "color inválido"
            // con cualquier método nuevo. Mantené sincronizada la lista con
            // frontend/lib/ui/color-palette.ts si crece.
            $color = (string)($data['color'] ?? '');
            $paletteKeys = ['amber', 'slate', 'sky', 'rose', 'emerald', 'violet'];
            $isHex = (bool) preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color);
            if ($color !== '' && !$isHex && !in_array($color, $paletteKeys, true))
                throw new \RuntimeException('color inválido', 422);
            $out['color'] = $color !== '' ? $color : '#7bd148';
        }

        if (isset($data['transport']) || $requireAll) {
            $transport = (string)($data['transport'] ?? 'usb');
            if (!in_array($transport, ['usb', 'bluetooth', 'network', 'native', 'station'], true))
                throw new \RuntimeException('transport inválido', 422);
            $out['transport'] = $transport;

            // Defaults explícitos para campos de transport opcionales
            $out['bluetoothDeviceId'] = null;
            $out['networkHost'] = null;
            $out['networkPort'] = null;
            $out['stationPrinterId'] = null;

            switch ($transport) {
                case 'usb':
                    break;
                case 'bluetooth':
                    $btId = trim((string)($data['bluetoothDeviceId'] ?? ''));
                    if ($btId === '') throw new \RuntimeException('bluetoothDeviceId requerido para transport bluetooth', 422);
                    $out['bluetoothDeviceId'] = $btId;
                    break;
                case 'network':
                    $host = trim((string)($data['networkHost'] ?? ''));
                    if ($host === '') throw new \RuntimeException('networkHost requerido para transport network', 422);
                    if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9._-]+$/', $host))
                        throw new \RuntimeException('networkHost inválido', 422);
                    $port = (int)($data['networkPort'] ?? 9100);
                    if ($port < 1 || $port > 65535) throw new \RuntimeException('networkPort debe ser 1-65535', 422);
                    $out['networkHost'] = $host;
                    $out['networkPort'] = $port;
                    break;
                case 'native':
                    break;
                case 'station':
                    $stationPrinterId = trim((string)($data['stationPrinterId'] ?? ''));
                    if ($stationPrinterId === '') throw new \RuntimeException('stationPrinterId requerido para transport station', 422);
                    if ($registerId === null) throw new \RuntimeException('No se pudo resolver la caja del binding', 422);
                    $outletId = $this->registerOutletId($registerId);
                    if ($outletId === null) throw new \RuntimeException('No se pudo resolver la sucursal de la caja', 422);
                    if (!$this->stationPrinterBelongsToOutlet($stationPrinterId, $outletId))
                        throw new \RuntimeException('stationPrinterId inválido para esta sucursal', 422);
                    $out['stationPrinterId'] = $stationPrinterId;
                    break;
            }
        }

        if (isset($data['mode']) || $requireAll) {
            $mode = (string)($data['mode'] ?? 'escpos');
            if (!in_array($mode, ['escpos', 'native'], true))
                throw new \RuntimeException('mode inválido', 422);
            $out['mode'] = $mode;
        }

        if (isset($data['paperWidthMm']) || $requireAll) {
            $pw = (int)($data['paperWidthMm'] ?? 80);
            if (!in_array($pw, [58, 80], true))
                throw new \RuntimeException('paperWidthMm debe ser 58 o 80', 422);
            $out['paperWidthMm'] = $pw;
        }

        if (isset($data['copies']) || $requireAll) {
            $copies = (int)($data['copies'] ?? 1);
            if ($copies < 1 || $copies > 10)
                throw new \RuntimeException('copies debe ser 1-10', 422);
            $out['copies'] = $copies;
        }

        if (array_key_exists('vendorId', $data)) $out['vendorId'] = $data['vendorId'] === null ? null : (int)$data['vendorId'];
        elseif ($requireAll) $out['vendorId'] = null;

        if (array_key_exists('productId', $data)) $out['productId'] = $data['productId'] === null ? null : (int)$data['productId'];
        elseif ($requireAll) $out['productId'] = null;

        if (array_key_exists('deviceLabel', $data)) $out['deviceLabel'] = $data['deviceLabel'] === '' ? null : (string)$data['deviceLabel'];
        elseif ($requireAll) $out['deviceLabel'] = null;

        if (array_key_exists('templateId', $data)) $out['templateId'] = $data['templateId'] === '' || $data['templateId'] === null ? null : (string)$data['templateId'];
        elseif ($requireAll) $out['templateId'] = null;

        if (isset($data['openDrawer']) || $requireAll) $out['openDrawer'] = filter_var($data['openDrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (isset($data['autoPrint']) || $requireAll) $out['autoPrint'] = filter_var($data['autoPrint'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (isset($data['printDelay']) || $requireAll) $out['printDelay'] = max(0, (int)($data['printDelay'] ?? 0));

        if (isset($data['categoryIds']) || $requireAll) {
            $cids = $data['categoryIds'] ?? [];
            if (!is_array($cids)) throw new \RuntimeException('categoryIds debe ser array', 422);
            $out['categoryIds'] = array_values(array_map('strval', $cids));
        }

        if (isset($data['docTypes']) || $requireAll) {
            $dts = $data['docTypes'] ?? [];
            if (!is_array($dts)) throw new \RuntimeException('docTypes debe ser array', 422);
            $valid = ['receipt','factura','quote','order','withdraw','delivery','closeReg','return'];
            foreach ($dts as $dt) {
                if (!in_array($dt, $valid, true)) throw new \RuntimeException("docType inválido: $dt", 422);
            }
            $out['docTypes'] = array_values($dts);
        }

        return $out;
    }

    /**
     * Garantiza array indexado (no asociativo) para JSONB que el front consume con .filter/.map.
     * Si la BD guardo `{}` o el json_decode falla, devuelve `[]` antes que romper el contrato.
     */
    private static function toIndexedArray(mixed $v): array
    {
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($v)) return [];
        return array_values($v);
    }

    private function normalize(array|\CaseInsensitiveArray $f): array {
        $get = function(string $camel, string $lower) use ($f) {
            return $f[$camel] ?? $f[$lower] ?? null;
        };
        return [
            'id'           => (string)$get('id', 'id'),
            'companyId'    => (string)$get('companyId', 'companyid'),
            'outletId'     => (string)$get('outletId', 'outletid'),
            'registerId'   => (string)$get('registerId', 'registerid'),
            'name'         => (string)$get('name', 'name'),
            'color'        => (string)$get('color', 'color'),
            'transport'    => (string)$get('transport', 'transport'),
            'vendorId'     => $get('vendorId', 'vendorid') !== null ? (int)$get('vendorId', 'vendorid') : null,
            'productId'    => $get('productId', 'productid') !== null ? (int)$get('productId', 'productid') : null,
            'deviceLabel'  => $get('deviceLabel', 'devicelabel') !== null ? (string)$get('deviceLabel', 'devicelabel') : null,
            'mode'         => (string)($get('mode', 'mode') ?? 'escpos'),
            'templateId'   => $get('templateId', 'templateid') !== null ? (string)$get('templateId', 'templateid') : null,
            'paperWidthMm' => (int)($get('paperWidthMm', 'paperwidthmm') ?? 80),
            'copies'       => (int)($get('copies', 'copies') ?? 1),
            'openDrawer'   => (bool)$get('openDrawer', 'opendrawer'),
            'autoPrint'    => (bool)$get('autoPrint', 'autoprint'),
            'printDelay'   => (int)($get('printDelay', 'printdelay') ?? 0),
            'bluetoothDeviceId' => $get('bluetoothDeviceId', 'bluetoothdeviceid') !== null ? (string)$get('bluetoothDeviceId', 'bluetoothdeviceid') : null,
            'networkHost'       => $get('networkHost', 'networkhost') !== null ? (string)$get('networkHost', 'networkhost') : null,
            'networkPort'       => $get('networkPort', 'networkport') !== null ? (int)$get('networkPort', 'networkport') : null,
            'stationPrinterId'  => $get('stationPrinterId', 'stationprinterid') !== null ? (string)$get('stationPrinterId', 'stationprinterid') : null,
            'categoryIds'  => self::toIndexedArray($get('categoryIds', 'categoryids')),
            'docTypes'     => self::toIndexedArray($get('docTypes', 'doctypes')),
            'createdAt'    => (string)($get('createdAt', 'createdat') ?? ''),
            'updatedAt'    => (string)($get('updatedAt', 'updatedat') ?? ''),
        ];
    }
}
