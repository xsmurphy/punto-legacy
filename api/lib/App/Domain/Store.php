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
     * Con $id devuelve los datos de esa sucursal; sin $id, mapa id → datos.
     * Equivalente legacy: `getAllOutletData($id)`.
     *
     * ── Por qué pasa por `Query::flattenJsonb()` ─────────────────────────────
     *
     * La migración 14 (`14_outlet_jsonb_demote_and_latlng.sql`) degradó al
     * JSONB `data` siete columnas de `outlet`: outletAddress, outletPhone,
     * outletWhatsApp, outletEmail, outletBillingName, outletRUC y
     * outletDescription. La migración dio por sentado que los lectores
     * `SELECT *` seguirían funcionando "via _flattenJsonb, cero cambios"
     * (punto 4 de su cabecera) — y para `ncmExecute()` es cierto.
     *
     * Pero este método NO usa `ncmExecute()`: usa `$db->Execute()`, que
     * devuelve `DBResult` con el `fetchAll(PDO::FETCH_ASSOC)` crudo. Ahí `data`
     * llega como STRING JSON sin desempacar, y como el nombre de esa columna no
     * contiene "outlet", el filtro de abajo la descartaba entera. Resultado: la
     * función devolvía sólo las 6 columnas que quedaron en la tabla (id, name,
     * status, creationDate, purchaseOrderNo, orderTransferNo) y las 7 demoted
     * desaparecían en silencio — sin error, sin warning, sin 500.
     *
     * El daño concreto está en `api/data.php`, que define OUTLET_EMAIL,
     * OUTLET_PHONE, OUTLET_ADDRESS y OUTLET_WHATS_APP desde acá: las cuatro
     * quedaban null. `Notification.php` mandaba mails con el Reply-To de
     * fallback y el link de WhatsApp de `functions.php` nunca se emitía.
     *
     * La solución es la del resto del código migrado — el helper canónico
     * `Query::flattenJsonb()`, el MISMO que aplica `ncmExecute()` — y no un
     * `json_decode` a mano acá, que sería una tercera copia de la lógica.
     *
     * ── Por qué el retorno es CaseInsensitiveArray ───────────────────────────
     *
     * Las columnas reales llegan de PG en minúscula (`outletname`) mientras que
     * las keys del JSONB conservan el camelCase con que se escribieron
     * (`outletWhatsApp`). Tras sacarles el prefijo quedan `name` y `whatsApp`
     * respectivamente — y `api/data.php` las lee como `['name']` y
     * `['whatsapp']`. Con un array plano, `whatsapp` no matchea `whatsApp` y el
     * dato se vuelve a perder por un problema de mayúsculas.
     *
     * Devolver la CIA del DB layer (el contrato de fila de todo el codebase)
     * hace que las dos formas resuelvan igual, sin pedirle a cada caller que
     * adivine cómo se escribió la key en el JSONB. Es un superset del array
     * anterior: `foreach`, `count()` y `json_encode()` siguen funcionando.
     *
     * Quirk legacy preservado: el prefijo se saca con `str_ireplace` + `lcfirst`,
     * así que `outletRUC` queda como `rUC`. Se mantiene tal cual para no romper
     * a nadie que lo lea así — con la CIA, `['ruc']` también funciona.
     */
    public static function getAllOutletData(mixed $id = false): mixed
    {
        global $db;

        $id     = $id ?: OUTLET_ID;
        $result = $db->Execute('SELECT * FROM outlet WHERE outletId = ?', [$id]);
        $data   = [];

        if (validateResultFromDB($result)) {
            while (!$result->EOF) {
                // Desempaca `data`/`meta`/`config` al nivel de la fila. Sin
                // esto las columnas demoted por la mig 14 no existen para el
                // filtro de abajo (ver docblock).
                $fields = \Punto\App\Database\Query::flattenJsonb($result->fields);

                $outletId = $fields['outletId'] ?? null; // CIA: lookup case-insensitive
                if ($outletId !== null) {
                    $row = [];
                    foreach ($fields as $key => $value) {
                        if (stripos((string) $key, 'outlet') !== false) {
                            $newKey       = lcfirst((string) str_ireplace('outlet', '', (string) $key));
                            $row[$newKey] = $value;
                        }
                    }
                    $data[$outletId] = new \CaseInsensitiveArray($row);
                }
                $result->MoveNext();
            }
            $result->Close();
        }

        if ($id) {
            // Sucursal inexistente: CIA vacía en vez de "Undefined array key".
            // Los callers hacen `$row['name']` sin chequear y una CIA vacía
            // devuelve null en cada lookup, que es lo que ya asumían.
            return $data[$id] ?? new \CaseInsensitiveArray([]);
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
     * y acceso posicional fields[0].
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
