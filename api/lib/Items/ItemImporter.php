<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * ItemImporter — parsea CSV y crea/actualiza items en bulk.
 *
 * Port del legacy `panel/a_items.php?action=importCSV`, adaptado al refactor
 * de 12 kinds canónicos. Acepta el label en español del kind ("Producto",
 * "Servicio", "Insumo con stock", etc.) o el slug ("producto", "insumo_stock").
 *
 * Headers esperados (case-insensitive, sin acentos):
 *   KIND, NOMBRE, SKU, MARCA, CATEGORIA, DESCRIPCION, COSTO, PRECIO,
 *   IMPUESTO, SUCURSAL, DESCUENTO_PCT, UOM, MERMA_PCT, COMISION_PCT,
 *   STOCK_MINIMO
 *
 * Delimitador: auto-detecta `,` vs `;` (gana el que más aparece en el body).
 * Máximo 2000 filas por subida (paridad con legacy).
 */
final class ItemImporter
{
    public const MAX_ROWS = 2000;

    public const HEADERS = [
        'KIND', 'NOMBRE', 'SKU', 'MARCA', 'CATEGORIA', 'DESCRIPCION',
        'COSTO', 'PRECIO', 'IMPUESTO', 'SUCURSAL', 'DESCUENTO_PCT', 'UOM',
        'MERMA_PCT', 'COMISION_PCT', 'STOCK_MINIMO',
    ];

    public const TEMPLATE_EXAMPLES = [
        [
            'producto', 'Café Espresso', 'CAF-001', 'Nespresso', 'Bebidas',
            'Café espresso doble', '5000', '12000', '10', '', '0',
            'unidad', '0', '0', '5',
        ],
        [
            'servicio', 'Corte de cabello', 'SRV-001', '', 'Servicios',
            'Corte y peinado', '0', '25000', '10', 'Central', '0',
            '', '0', '10', '',
        ],
    ];

    /** Map de label legacy → kind canónico (case-insensitive). */
    public const LEGACY_KIND_LABELS = [
        // Legacy CSV usaba "producto/servicio/compuesto" sin distinguir granularidad.
        'producto'              => 'producto',
        'servicio'              => 'servicio',
        'compuesto'             => 'insumo_stock',
        // Labels nuevos en español (KIND_META.label).
        'insumo con stock'      => 'insumo_stock',
        'insumo sin stock'      => 'insumo_sin_stock',
        'insumo de control'     => 'insumo_control',
        'producción directa'    => 'produccion_directa',
        'produccion directa'    => 'produccion_directa',
        'producción previa'     => 'produccion_previa',
        'produccion previa'     => 'produccion_previa',
        'pack de sesiones'      => 'servicio_sesiones',
        'combo fijo'            => 'combo_fijo',
        'combo dinámico'        => 'combo_dinamico',
        'combo dinamico'        => 'combo_dinamico',
        'descuento'             => 'descuento',
        'gift card'             => 'giftcard',
        'giftcard'              => 'giftcard',
    ];

    public const VALID_KINDS = [
        'producto', 'insumo_stock', 'insumo_sin_stock', 'insumo_control',
        'produccion_directa', 'produccion_previa',
        'servicio', 'servicio_sesiones',
        'combo_fijo', 'combo_dinamico',
        'descuento', 'giftcard',
    ];

    private ItemService $svc;
    private $db;

    public function __construct(ItemService $svc, $db)
    {
        $this->svc = $svc;
        $this->db  = $db;
    }

    /**
     * @param string $fileContents Raw bytes del CSV.
     * @param string $companyId
     * @param string $mode 'insert' | 'update' — update busca por SKU.
     * @return array { created:int, updated:int, errors:[{line:int, message:string}], total:int }
     */
    public function import(string $fileContents, string $companyId, string $mode = 'insert'): array
    {
        $report = ['created' => 0, 'updated' => 0, 'errors' => [], 'total' => 0];

        $delim = $this->detectDelimiter($fileContents);
        $lines = preg_split("/\r\n|\n|\r/", $fileContents) ?: [];
        // Drop trailing empty lines.
        while (count($lines) && trim(end($lines)) === '') array_pop($lines);
        if (count($lines) < 2) {
            $report['errors'][] = ['line' => 0, 'message' => 'El archivo no tiene filas de datos.'];
            return $report;
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            $report['errors'][] = ['line' => 0, 'message' => 'Máximo ' . self::MAX_ROWS . ' filas por archivo.'];
            return $report;
        }

        $headerRow = str_getcsv((string) array_shift($lines), $delim);
        $headerMap = $this->mapHeaders($headerRow);
        if (!isset($headerMap['NOMBRE'])) {
            $report['errors'][] = ['line' => 1, 'message' => 'Falta la columna NOMBRE.'];
            return $report;
        }

        // Pre-cargamos sucursales para no consultar por fila.
        $outlets = $this->loadOutlets($companyId);

        foreach ($lines as $idx => $rawLine) {
            $lineNum = $idx + 2; // 1-indexed + header
            $report['total']++;
            if (trim($rawLine) === '') continue;

            $row = str_getcsv($rawLine, $delim);
            try {
                $result = $this->processRow($row, $headerMap, $outlets, $companyId, $mode);
                if ($result === 'created') $report['created']++;
                elseif ($result === 'updated') $report['updated']++;
            } catch (\Throwable $e) {
                $report['errors'][] = ['line' => $lineNum, 'message' => $e->getMessage()];
            }
        }
        return $report;
    }

    public function templateCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::HEADERS);
        foreach (self::TEMPLATE_EXAMPLES as $row) fputcsv($out, $row);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string) $csv;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function detectDelimiter(string $contents): string
    {
        $sample = substr($contents, 0, 8192);
        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    /** @return array<string,int> header label (canonical) → column index */
    private function mapHeaders(array $rawHeaders): array
    {
        $map = [];
        foreach ($rawHeaders as $i => $h) {
            $canon = $this->canonHeader((string) $h);
            if ($canon !== '') $map[$canon] = $i;
        }
        return $map;
    }

    private function canonHeader(string $h): string
    {
        $h = trim($h);
        $h = mb_strtoupper($h, 'UTF-8');
        $h = strtr($h, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        // Reemplazar separadores comunes por '_' y limpiar.
        $h = preg_replace('/[\s\-]+/u', '_', $h) ?? $h;
        $h = preg_replace('/[^A-Z_]/u', '', $h) ?? $h;
        $aliases = [
            'PRECIO_DE_VENTA'  => 'PRECIO',
            'PRECIO_VENTA'     => 'PRECIO',
            'PRECIO_DE_COSTO'  => 'COSTO',
            'COSTO_PROMEDIO'   => 'COSTO',
            'TITULO'           => 'NOMBRE',
            'NAME'             => 'NOMBRE',
            'TIPO'             => 'KIND',
            'CATEGORY'         => 'CATEGORIA',
            'BRAND'            => 'MARCA',
            'TAX'              => 'IMPUESTO',
            'IVA'              => 'IMPUESTO',
            'DESCUENTO'        => 'DESCUENTO_PCT',
            'MERMA'            => 'MERMA_PCT',
            'COMISION'         => 'COMISION_PCT',
            'PERC_COMISION'    => 'COMISION_PCT',
            'STOCK_MIN'        => 'STOCK_MINIMO',
            'UNIDAD'           => 'UOM',
            'UNIDAD_DE_MEDIDA' => 'UOM',
        ];
        return $aliases[$h] ?? $h;
    }

    /** @return 'created'|'updated' */
    private function processRow(array $row, array $headerMap, array $outlets, string $companyId, string $mode): string
    {
        $get = fn(string $key) => isset($headerMap[$key], $row[$headerMap[$key]]) ? trim((string) $row[$headerMap[$key]]) : '';

        $name = $get('NOMBRE');
        if ($name === '') throw new \RuntimeException('NOMBRE vacío');

        $kind = $this->resolveKind($get('KIND'));
        $sku  = $get('SKU');

        // En modo update, encontrar el item existente por SKU.
        $existingId = null;
        if ($mode === 'update') {
            if ($sku === '') throw new \RuntimeException('Modo update requiere SKU');
            $rs = $this->db->Execute(
                'SELECT itemId FROM item WHERE itemSKU = ? AND companyId = ? LIMIT 1',
                [$sku, $companyId]
            );
            if ($rs !== false && !$rs->EOF) {
                $existingId = (string) $rs->fields['itemId'];
            }
            if ($existingId === null) throw new \RuntimeException('No existe item con SKU ' . $sku);
        }

        // Resolver taxonomies (autocreate si no existen).
        $brandName    = $get('MARCA');
        $categoryName = $get('CATEGORIA');
        $taxName      = $get('IMPUESTO');
        $outletLabel  = strtolower($get('SUCURSAL'));

        $brandId    = $brandName    !== '' ? \getTaxonomyIdOrInsert($brandName, 'brand') : null;
        $categoryId = $categoryName !== '' ? \getTaxonomyIdOrInsert($categoryName, 'category') : null;
        $taxId      = $taxName      !== '' ? \getTaxonomyIdOrInsert($taxName, 'tax') : null;
        $outletId   = ($outletLabel === '' || $outletLabel === 'todas') ? null : ($outlets[$outletLabel] ?? null);

        $legacyFlags = $this->legacyFlagsForKind($kind);

        $record = [
            'itemName'              => $name,
            'itemSKU'               => $sku !== '' ? $sku : null,
            'itemKind'              => $kind,
            'itemType'              => $legacyFlags['itemType'],
            'itemCanSale'           => $legacyFlags['itemCanSale'],
            'itemTrackInventory'    => $legacyFlags['itemTrackInventory'],
            'itemProduction'        => $legacyFlags['itemProduction'],
            'itemDescription'       => $get('DESCRIPCION'),
            'itemCost'              => $this->numOrNull($get('COSTO')),
            'itemPrice'             => $this->numOrNull($get('PRECIO')),
            'itemDiscount'          => $this->numOrZero($get('DESCUENTO_PCT')),
            'itemUOM'               => $get('UOM'),
            'itemWaste'             => $this->numOrZero($get('MERMA_PCT')),
            'itemComissionPercent'  => $this->numOrZero($get('COMISION_PCT')),
            'autoReOrder'           => $this->numOrZero($get('STOCK_MINIMO')) > 0 ? 1 : 0,
            'autoReOrderLevel'      => $this->numOrZero($get('STOCK_MINIMO')),
            'brandId'               => $brandId,
            'categoryId'            => $categoryId,
            'taxId'                 => $taxId,
            'outletId'              => $outletId,
            'itemStatus'            => 1,
            'itemTaxIncluded'       => 1,
            'updated_at'            => \TODAY,
        ];

        if ($existingId !== null) {
            $ok = $this->svc->update($existingId, $companyId, $record);
            if (!$ok) throw new \RuntimeException('Update falló');
            return 'updated';
        }

        $record['itemDate'] = \TODAY;
        $record['companyId'] = $companyId;
        $newId = $this->svc->createBlank($companyId, $legacyFlags['itemType'], $kind);
        if ($newId === false) throw new \RuntimeException('No se pudo crear el item');
        $ok = $this->svc->update($newId, $companyId, $record);
        if (!$ok) throw new \RuntimeException('Update post-create falló');
        return 'created';
    }

    private function resolveKind(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return 'producto';
        $lower = mb_strtolower($raw, 'UTF-8');
        if (in_array($lower, self::VALID_KINDS, true)) return $lower;
        return self::LEGACY_KIND_LABELS[$lower] ?? 'producto';
    }

    private function legacyFlagsForKind(string $kind): array
    {
        $map = [
            'producto'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
            'insumo_stock'       => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
            'insumo_sin_stock'   => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'insumo_control'     => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
            'produccion_directa' => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'produccion_previa'  => ['itemType' => 'production', 'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 1],
            'servicio'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'servicio_sesiones'  => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'combo_fijo'         => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'combo_dinamico'     => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'descuento'          => ['itemType' => 'discount',   'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
            'giftcard'           => ['itemType' => 'giftcard',   'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        ];
        return $map[$kind] ?? $map['producto'];
    }

    private function loadOutlets(string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT outletId, outletName FROM outlet WHERE companyId = ?',
            [$companyId]
        );
        $out = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $r) {
                $name = mb_strtolower((string) ($r['outletname'] ?? $r['outletName'] ?? ''), 'UTF-8');
                $id   = (string) ($r['outletid'] ?? $r['outletId'] ?? '');
                if ($name !== '' && $id !== '') $out[$name] = $id;
            }
        }
        return $out;
    }

    private function numOrNull(string $v): ?float
    {
        $v = str_replace([',', ' '], ['.', ''], trim($v));
        if ($v === '' || !is_numeric($v)) return null;
        return (float) $v;
    }

    private function numOrZero(string $v): float
    {
        return $this->numOrNull($v) ?? 0.0;
    }
}
