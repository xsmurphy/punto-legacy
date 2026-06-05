<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Datos de sucursales (outlets) del POS.
 *
 * Reemplaza las funciones globales (Slice 8 del plan PSR-4):
 *   - getCurrentOutletName($id)             → Store::getCurrentOutletName($id)
 *   - getAllOutletData($id)                  → Store::getAllOutletData($id)
 *   - getOutletCount($compId)               → Store::getOutletCount($compId)
 *   - selectInputOutlet($m, $mul, $cl, $n)  → Store::selectInput($match, $multi, $class, $name)
 *   - getOperatingCost($outletId)           → Store::getOperatingCost($outletId)
 *
 * Las funciones globales permanecen como wrappers — cero breaking changes en
 * los ~67 callsites totales del POS.
 *
 * Semántica preservada VERBATIM del legacy (incluidos quirks):
 *   - getCurrentOutletName: UUID entre comillas simples en WHERE (§22.5).
 *   - getAllOutletData: doble str_replace("outlet","") — el segundo es no-op, se mantiene.
 *   - getOperatingCost: fields[0] posicional, outletId concatenado sin parametrizar.
 */
final class Store
{
    /**
     * Nombre de la sucursal. Usa OUTLET_ID si no se pasa $id.
     * Equivalente legacy: `getCurrentOutletName($id)`.
     */
    public static function getCurrentOutletName(mixed $id = false): string
    {
        global $db;

        $id = $id ?: OUTLET_ID;

        // UUID entre comillas simples — patrón PG §22.5.
        $obj = $db->Execute("SELECT outletName FROM outlet WHERE outletId = '" . $id . "'");

        if (validity($obj->fields['outletName'])) {
            return $obj->fields['outletName'];
        }

        return 'None';
    }

    /**
     * Datos de una sucursal indexados con claves sin prefijo "outlet".
     * Con $id devuelve el array de esa sucursal; sin $id, mapa id → datos.
     * Equivalente legacy: `getAllOutletData($id)`.
     *
     * Quirk legacy preservado: doble str_replace en el key — el segundo es no-op
     * (el key ya no contiene "outlet" tras el primero).
     */
    public static function getAllOutletData(mixed $id = false): mixed
    {
        global $db;

        $id     = $id ?: OUTLET_ID;
        $result = $db->Execute('SELECT * FROM outlet WHERE outletId = ?', [$id]);
        $data   = [];

        if (validateResultFromDB($result)) {
            while (!$result->EOF) {
                $fields = $result->fields;
                $data[$fields['outletId']] = [];
                foreach ($fields as $key => $value) {
                    if (strpos($key, 'outlet') !== false) {
                        $key = str_replace('outlet', '', $key);
                        $key = lcfirst($key);
                        $data[$fields['outletId']][str_replace('outlet', '', $key)] = $value;
                    }
                }
                $result->MoveNext();
            }
        }
        $result->Close();

        if ($id) {
            return $data[$id];
        }

        return $data;
    }

    /**
     * Cantidad de sucursales activas de una empresa (status=1).
     * Devuelve 1 como fallback si la query falla.
     * Equivalente legacy: `getOutletCount($compId)`.
     */
    public static function getOutletCount(mixed $compId): int
    {
        $obj = ncmExecute(
            'SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 100',
            [$compId]
        );

        return $obj ? (int) $obj['count'] : 1;
    }

    /**
     * Genera el HTML de un <select> de sucursales. Retorna string
     * (el wrapper legacy hace echo del retorno).
     * Equivalente legacy: `selectInputOutlet($match, $multi, $class, $name)`.
     *
     * Nota: el parámetro $multi existe en el legacy pero estaba comentado —
     * se mantiene en la firma para compatibilidad, sin efecto en el HTML.
     */
    public static function selectInput(
        mixed  $match = '',
        bool   $multi = false,
        string $class = '',
        string $name  = 'outlet'
    ): string {
        global $db, $SQLcompanyId;

        $result = $db->Execute(
            'SELECT outletName,outletId FROM outlet WHERE ' . $SQLcompanyId . ' ORDER BY outletName ASC'
        );

        $html = '<select name="' . $name . '" class="form-control ' . $class . '">';

        if ($result) {
            while (!$result->EOF) {
                $selected = $result->fields['outletId'] == $match ? 'selected' : '';
                $html .= '<option value="' . enc($result->fields['outletId']) . '" ' . $selected . '>' . $result->fields['outletName'] . '</option>';
                $result->MoveNext();
            }
            $result->Close();
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Costo operativo de una sucursal (campo outletOperatingCosts).
     * Equivalente legacy: `getOperatingCost($outletId)`.
     *
     * Quirk legacy preservado: outletId concatenado sin parametrizar (sin cambio de semántica),
     * y acceso posicional fields[0] (ADOdb).
     */
    public static function getOperatingCost(mixed $outletId): mixed
    {
        global $db;

        $opCost = $db->Execute(
            'SELECT outletOperatingCosts FROM outlet WHERE outletId = ' . $outletId . ' LIMIT 1'
        );

        $operationCost = $opCost->fields[0];
        $opCost->Close();
        return $operationCost;
    }
}
