<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * Lo poco que todavía hay que parsear del panel legacy (context/77 §4).
 *
 * ── Qué queda acá, y por qué es tan poco ────────────────────────────────
 * Hasta 2026-09-11 esta clase era el corazón del export: el catálogo, los
 * clientes, las sucursales y las cajas salían de tablas HTML y de un CSV, y
 * acá vivían el parser de CSV indexado por nombre de columna, la resolución de
 * columnas por encabezado y el lector de `data-order`/`data-sort`.
 *
 * Casi todo eso SE ELIMINÓ junto con el scraping: esos dominios ahora salen de
 * `POST /fetchs`, que devuelve JSON. Un parser sin lectores no se conserva
 * "por si acaso" — se vuelve código que nadie prueba y que el próximo lector
 * asume vigente.
 *
 * Sobrevive lo que tiene lector HOY, y solo eso:
 *
 *   · `tableHtml()` + `htmlRows()` → dos lectores. El histórico de VENTAS
 *     (F2, sin implementar) sobre `a_report_transactions?action=detailTable`,
 *     y el COSTO de los artículos sobre `a_items?action=showTable` — el único
 *     dato del catálogo que `/fetchs` no manda (ver `EncomClient::itemCosts()`).
 *     El valor CRUDO viaja en `data-order`/`data-sort`; el texto visible está
 *     formateado para mirar (`1.250.000`, `12 ene`).
 *   · `htmlHeaders()` + `columnIndex()` → resolver una columna por su
 *     ENCABEZADO. Es la lección más cara de la F1: el listado vivo de cajas
 *     tenía una columna que el snapshot no tenía y, leído por índice fijo, el
 *     nombre de la sucursal se leía como TIMBRADO. La tabla de artículos era
 *     justamente la única que seguía siendo posicional; ahora que vuelve a
 *     tener un lector, lo hace por encabezado.
 *   · `formValues()` → `a_report_transactions?action=edit&id=`, el form con los
 *     ítems de UNA venta (F2).
 */
final class EncomParse
{
    /**
     * Filas de una tabla HTML del legacy.
     *
     * Cada fila sale como `['id' => <id del tr>, 'cells' => [...]]`, donde cada
     * celda es el valor CRUDO: `data-order` si está (el legacy lo usa para
     * ordenar, así que es el valor sin formatear), si no `data-sort` o
     * `data-filter`, y recién al final el texto visible.
     *
     * Se usa DOMDocument y no una regex: el nombre de un cliente lo escribe el
     * comercio y puede traer `<`, `&` o comillas. Una regex posicional se
     * desalinea con eso y termina leyendo un MONTO de la celda equivocada.
     *
     * @return array<int,array{id:string,cells:array<int,string>}>
     */
    public static function htmlRows(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // El legacy devuelve un FRAGMENTO (`<thead>…<tbody>…`), no un
        // documento: se envuelve en una tabla para que los `<tr>` no queden
        // huérfanos y el parser los vea.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><table>' . $html . '</table>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            // El id de la fila no está siempre en el mismo atributo: las
            // transacciones usan `data-id`, otras tablas del legacy usan `id` a
            // secas. Mirar solo uno saltea filas en silencio.
            $id = trim($tr->getAttribute('data-id'));
            if ($id === '') {
                $id = trim($tr->getAttribute('id'));
            }
            if ($id === '') {
                continue;   // la fila del <thead> y el <tfoot>
            }

            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                if (!$td instanceof \DOMElement) {
                    continue;
                }
                $raw = '';
                foreach (['data-order', 'data-sort', 'data-filter'] as $attr) {
                    $v = trim($td->getAttribute($attr));
                    if ($v !== '') {
                        $raw = $v;
                        break;
                    }
                }
                if ($raw === '') {
                    $raw = $td->textContent;
                }
                $cells[] = trim((string) $raw);
            }

            $out[] = ['id' => $id, 'cells' => $cells];
        }

        return $out;
    }

    /**
     * Encabezados normalizados del `<thead>`: mayúsculas, sin acentos, sin
     * espacios de más.
     *
     * @return array<int,string>
     */
    public static function htmlHeaders(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><table>' . $html . '</table>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ($doc->getElementsByTagName('th') as $th) {
            $out[] = self::canon((string) $th->textContent);
        }
        return $out;
    }

    /**
     * Índice de la columna cuyo encabezado contiene alguna de las palabras
     * clave, o `null` si ninguna matchea (el caller decide el fallback).
     *
     * El match es por palabra clave y no por igualdad para aguantar que el
     * título cambie de mayúsculas, de acentos o de redacción.
     *
     * @param array<int,string> $headers
     * @param array<int,string> $keywords ya en mayúsculas y sin acentos
     */
    public static function columnIndex(array $headers, array $keywords): ?int
    {
        foreach ($headers as $i => $h) {
            foreach ($keywords as $kw) {
                if (str_contains($h, $kw)) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** Normaliza un encabezado: mayúsculas, sin acentos, sin espacios de más. */
    private static function canon(string $h): string
    {
        $h = trim($h);
        $h = str_starts_with($h, "\xEF\xBB\xBF") ? substr($h, 3) : $h;
        $h = mb_strtoupper($h, 'UTF-8');
        $h = strtr($h, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
        ]);
        return preg_replace('/\s+/u', ' ', $h) ?? $h;
    }

    /**
     * Valor de un `<input>`/`<select>` por su atributo `name`, dentro de un
     * form HTML del legacy (`?action=edit`).
     *
     * Es lo que permite leer el detalle de una venta sin depender del ORDEN de
     * los campos en la pantalla: si el legacy mueve un input de lugar, el
     * `name` sigue siendo el mismo.
     *
     * @return array<string,string> name => value
     */
    public static function formValues(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];

        foreach (['input', 'textarea'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                if (!$el instanceof \DOMElement) {
                    continue;
                }
                $name = trim($el->getAttribute('name'));
                if ($name === '') {
                    continue;
                }
                // Un checkbox/radio sin marcar no aporta valor.
                $type = strtolower($el->getAttribute('type'));
                if (($type === 'checkbox' || $type === 'radio') && !$el->hasAttribute('checked')) {
                    continue;
                }
                $out[$name] = $tag === 'textarea'
                    ? trim($el->textContent)
                    : trim($el->getAttribute('value'));
            }
        }

        // En un <select> el valor es la <option> con `selected`.
        foreach ($doc->getElementsByTagName('select') as $sel) {
            if (!$sel instanceof \DOMElement) {
                continue;
            }
            $name = trim($sel->getAttribute('name'));
            if ($name === '') {
                continue;
            }
            foreach ($sel->getElementsByTagName('option') as $opt) {
                if ($opt instanceof \DOMElement && $opt->hasAttribute('selected')) {
                    $out[$name] = $opt->hasAttribute('value')
                        ? trim($opt->getAttribute('value'))
                        : trim($opt->textContent);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Desenvuelve `{"table": "<html>"}` — la forma en que algunos
     * `action=*Table` devuelven la tabla. Si no es JSON, se asume que ya vino
     * el HTML crudo (no todos los actions envuelven).
     */
    public static function tableHtml(string $body): string
    {
        $trimmed = ltrim($body);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $body;
        }

        $json = json_decode($body, true);
        if (is_array($json) && isset($json['table']) && is_string($json['table'])) {
            return $json['table'];
        }
        return $body;
    }
}
