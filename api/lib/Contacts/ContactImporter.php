<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

final class ContactImporter
{
    public const MAX_ROWS = 2000;
    public const HEADERS  = ['TIPO', 'NOMBRE', 'TELEFONO', 'EMAIL', 'RUC_CI', 'DIRECCION', 'NOTAS'];

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array { total:int, created:int, updated:int, errors:[{line:int, message:string}] }
     */
    public function importFromCsv(string $csvPath, string $mode, string $companyId, string $userId): array
    {
        if (!function_exists('phoneToE164')) {
            require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
        }

        $report = ['total' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];

        $fh = @fopen($csvPath, 'r');
        if (!$fh) {
            $report['errors'][] = ['line' => 0, 'message' => 'No se pudo abrir el archivo'];
            return $report;
        }

        $headerRow = fgetcsv($fh);
        if (!$headerRow) {
            fclose($fh);
            $report['errors'][] = ['line' => 0, 'message' => 'Archivo vacío o sin headers'];
            return $report;
        }

        $headers = array_map(fn($h) => trim(strtoupper((string) $h)), $headerRow);

        if (!in_array('NOMBRE', $headers, true)) {
            fclose($fh);
            $report['errors'][] = ['line' => 1, 'message' => 'Falta la columna NOMBRE'];
            return $report;
        }

        $svc  = new ContactService(new ContactRepository($this->db));
        $line = 1;

        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $report['total']++;

            if ($report['total'] > self::MAX_ROWS) {
                $report['errors'][] = ['line' => $line, 'message' => 'Máximo ' . self::MAX_ROWS . ' filas'];
                break;
            }

            if (implode('', $row) === '') continue;

            try {
                $result = $this->processRow($row, $headers, $mode, $companyId, $svc, $line);
                if ($result === 'created') $report['created']++;
                elseif ($result === 'updated') $report['updated']++;
            } catch (\Throwable $e) {
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        fclose($fh);
        return $report;
    }

    private function processRow(array $row, array $headers, string $mode, string $companyId, ContactService $svc, int $lineNum): string
    {
        $get = function(string $col) use ($row, $headers): string {
            $idx = array_search($col, $headers, true);
            return $idx !== false ? trim((string) ($row[$idx] ?? '')) : '';
        };

        $nombre    = $get('NOMBRE');
        $tipoStr   = strtolower($get('TIPO'));
        $telefono  = $get('TELEFONO');
        $email     = $get('EMAIL');
        $rucCi     = $get('RUC_CI');
        $direccion = $get('DIRECCION');
        $notas     = $get('NOTAS');

        if ($nombre === '') {
            throw new \InvalidArgumentException("Línea {$lineNum}: NOMBRE es obligatorio");
        }

        $type = 0;
        if (in_array($tipoStr, ['cliente', 'clientes', 'c', '1'], true)) {
            $type = 1;
        } elseif (in_array($tipoStr, ['proveedor', 'proveedores', 'p', '2'], true)) {
            $type = 2;
        } elseif ($tipoStr === '') {
            $type = 1;
        } else {
            throw new \InvalidArgumentException("Línea {$lineNum}: TIPO inválido '{$tipoStr}' (usar cliente/proveedor)");
        }

        $phoneE164 = null;
        if ($telefono !== '') {
            // El CSV trae números en formato local, sin prefijo internacional.
            // Se parsean con el país del TENANT: esta llamada no pasaba país y
            // caía al default 'PY' de la firma, así que importar la agenda de
            // un comercio de otro país generaba teléfonos +595 inexistentes.
            $iso = \Punto\Api\Support\TenantLocale::country($companyId);
            $phoneE164 = function_exists('phoneToE164') ? phoneToE164($telefono, $iso) : $telefono;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $data = [
            'name' => $nombre,
            'type' => $type,
        ];
        if ($phoneE164 !== null) $data['phone']   = $phoneE164;
        if ($email !== '')       $data['email']   = $email;
        if ($rucCi !== '')       $data['tin']     = $rucCi;
        if ($direccion !== '')   $data['address'] = $direccion;
        if ($notas !== '')       $data['note']    = $notas;

        if ($mode === 'update') {
            $existing = null;
            // contact se creó SIN quotes → Postgres plegó todas sus columnas
            // a minúsculas (contactid, companyid, contactphone, etc). Citarlas
            // en camelCase ("contactId", "companyId"...) exige match exacto
            // de mayúsculas y Postgres tira "column does not exist" — mismo
            // bug documentado en detalle en ReturnService::create().
            if ($phoneE164 !== null) {
                $existing = ncmExecute(
                    'SELECT contactId FROM contact WHERE companyId = ? AND contactPhone = ? AND contactStatus = 1 LIMIT 1',
                    [$companyId, $phoneE164]
                );
            }
            if (!$existing && $rucCi !== '') {
                $existing = ncmExecute(
                    'SELECT contactId FROM contact WHERE companyId = ? AND contactTIN = ? AND contactStatus = 1 LIMIT 1',
                    [$companyId, $rucCi]
                );
            }
            if ($existing) {
                $contactId = (string) ($existing['contactId'] ?? $existing['contactid'] ?? '');
                if ($contactId !== '') {
                    $svc->update($contactId, $companyId, $data);
                    return 'updated';
                }
            }
        }

        $svc->create($companyId, $data);
        return 'created';
    }
}
