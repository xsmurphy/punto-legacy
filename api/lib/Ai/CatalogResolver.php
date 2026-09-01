<?php
declare(strict_types=1);

namespace Punto\Api\Ai;

/**
 * Traduce a ids los NOMBRES de catálogo que el agente IA recibe del usuario.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * El modelo nunca tiene un uuid: la persona le dice "IVA 10%" y "la sucursal
 * Central". Cada acción de `/v1/ai/execute` necesita la misma traducción, y
 * hacerla suelta dentro de cada `case` ya había dejado dos copias del resolver
 * de sucursal (una en `create_item`, otra en `create_register`). Un resolver
 * duplicado es el que un día acepta una sucursal de OTRO tenant porque a una de
 * las copias se le olvidó el `companyId`.
 *
 * Vive en una clase y no en funciones de `execute.php` por la misma razón que
 * `ContactPayload`: ese archivo corre `bootstrap.php` y resuelve la auth en el
 * top-level, así que nada de lo que se defina adentro se puede ejercitar desde
 * un arnés. Acá sí — ver `api/tests/agent_action_fields_test.php`.
 */
final class CatalogResolver
{
    /**
     * Resuelve un impuesto POR NOMBRE contra el catálogo REAL del tenant.
     *
     * El match tiene DOS pasadas y ninguna es por substring: primero el nombre
     * exacto sin distinguir mayúsculas, y si no da, la TASA que se lee del
     * texto ("IVA 10%" → 10) contra `tax.rate`. La segunda pasada existe porque
     * los catálogos reales llaman "10" al impuesto y la persona dice "IVA 10%".
     *
     * El substring quedó descartado a propósito: un tenant con una tasa llamada
     * "0" —el seed de `verify_chain` tiene una— haría que "IVA 10%" matcheara
     * con el 0% por contener el carácter, y el ítem saldría facturado sin IVA.
     * Comparar el NÚMERO no tiene esa clase de falso positivo.
     *
     * ── Por qué el default NO es "sin impuesto" ────────────────────────────
     *
     * Un ítem con `taxId` NULL se vende EXENTO: `SaleService::enrichWithTaxes()`
     * no encuentra fila en `tax` ni en `taxonomy` y cae en `rate=0,
     * kind=exempt`. O sea que el ítem que cargaba el agente salía facturado sin
     * IVA y nadie se enteraba hasta el Libro de Ventas. El default de acá es el
     * MISMO que el del panel (`quick-create-item-dialog.tsx` arranca con el
     * primer impuesto del tenant, con el comentario de que sin eso "el ítem
     * nacía sin impuesto y nadie lo notaba"), y el nombre aplicado vuelve en el
     * resultado de la acción para que el agente pueda decírselo al usuario —
     * que es lo que en el panel hace el desplegable a la vista.
     *
     * @param ?string $taxName lo que dijo el usuario; vacío = aplicar el default.
     * @param mixed   $db      la conexión del wrapper (global `$db`).
     * @return array{id: string, name: string}|null null si el tenant no tiene
     *         catálogo de impuestos: no hay nada que asignar, y abortar el alta
     *         por eso dejaría al comercio sin poder cargar artículos.
     * @throws \InvalidArgumentException si se pidió un impuesto que no existe.
     */
    public static function taxByName(?string $taxName, string $companyId, $db): ?array
    {
        $catalogo = (new \Punto\Api\Taxes\TaxService($db))->list($companyId);
        if ($catalogo === []) {
            return null;
        }

        $pedido = trim((string) $taxName);
        if ($pedido === '') {
            // `TaxService::list` ordena por sortOrder NULLS LAST, name — el
            // mismo orden que ve el usuario en el desplegable del panel.
            return ['id' => (string) $catalogo[0]['id'], 'name' => (string) $catalogo[0]['name']];
        }

        $pedidoLower = mb_strtolower($pedido);
        $disponibles = [];
        foreach ($catalogo as $t) {
            $nombre = (string) ($t['name'] ?? '');
            if (mb_strtolower($nombre) === $pedidoLower) {
                return ['id' => (string) $t['id'], 'name' => $nombre];
            }
            $disponibles[] = $nombre;
        }

        // Segunda pasada: la tasa leída del texto contra `tax.rate`. Solo sobre
        // `kind='rate'` — una exenta tiene rate 0 y matchearía "0%", que no es
        // lo mismo que una tasa real del 0% (el seed tiene las dos).
        if (preg_match('/\d+(?:[.,]\d+)?/', $pedido, $m)) {
            $tasa = (float) str_replace(',', '.', $m[0]);
            foreach ($catalogo as $t) {
                // Comparación con epsilon y no `===`: los dos floats vienen de
                // fuentes distintas —uno del DECIMAL de Postgres, otro de
                // parsear el texto del usuario— y una tasa con decimales (10.5,
                // 21.5) puede diferir en el último bit sin ser un valor
                // distinto. El umbral es mucho más chico que la menor distancia
                // real entre dos tasas de un catálogo.
                if (($t['kind'] ?? '') === 'rate' && $t['rate'] !== null
                    && abs((float) $t['rate'] - $tasa) < 0.0001) {
                    return ['id' => (string) $t['id'], 'name' => (string) ($t['name'] ?? '')];
                }
            }
        }

        // El mensaje lista el catálogo real: de acá sale la repregunta del bot,
        // que hoy es su única forma de conocer los nombres (no hay read-tool
        // del catálogo de impuestos).
        throw new \InvalidArgumentException(
            "El impuesto '$pedido' no existe en el comercio" .
            ($disponibles !== [] ? '. Impuestos disponibles: ' . implode(', ', $disponibles) : '')
        );
    }

    /**
     * Resuelve NOMBRES de sucursal a sus ids dentro del tenant.
     *
     * El `companyId` va en el WHERE y no es negociable: es lo único que impide
     * que un nombre de sucursal ajeno entre como id válido a una escritura.
     *
     * @param string[] $names
     * @return string[] ids únicos, en el orden en que se pidieron
     * @throws \InvalidArgumentException si alguno no existe en el comercio.
     */
    public static function outletIdsByName(array $names, string $companyId): array
    {
        $ids = [];
        foreach ($names as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }
            $row = \ncmExecute(
                'SELECT outletId FROM outlet WHERE companyId = ? AND outletName ILIKE ? LIMIT 1',
                [$companyId, $name]
            );
            $id = (string) ($row['outletId'] ?? $row['outletid'] ?? '');
            if ($id === '') {
                throw new \InvalidArgumentException("La sucursal '$name' no existe en el comercio");
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
