<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * Parsers de lo que devuelve el panel legacy (context/77 §4).
 *
 * Está aparte de `EncomClient` porque es la parte que MÁS se puede
 * equivocar y la única que se puede probar sin red: el arnés le pasa
 * fragmentos copiados textualmente del sistema vivo y verifica que salgan los
 * valores correctos. El cliente HTTP queda como transporte y nada más.
 *
 * Dos formatos, por cómo exporta el legacy:
 *
 *   1. **CSV** (`a_contacts?action=download`). Coma, campos entre comillas,
 *      saltos con `\r`. Se indexa **por nombre de columna, nunca por
 *      posición** — ver `csvRows()`.
 *   2. **Tabla HTML** server-rendered (`a_registers?list=true`,
 *      `?action=...Table`). El texto visible está formateado para mirar (miles
 *      con punto, fechas "12 ene"), así que NO se parsea: el valor crudo viaja
 *      en `data-order` / `data-filter` de cada `<td>`, y el id de la fila en
 *      `data-id` del `<tr>`.
 */
final class EncomParse
{
    /**
     * Filas de un CSV del legacy, cada una como mapa `header => valor`.
     *
     * ── Por qué por NOMBRE y no por posición ─────────────────────────────
     * El deploy vivo es MÁS VIEJO que el código del snapshot y las columnas
     * NO coinciden: el vivo trae `TELEFONO 2` y el snapshot la eliminó (su
     * "Migración 25"). Un parser posicional lee el email en la columna del
     * teléfono en una de las dos versiones — y no se entera.
     *
     * Encima el encabezado del documento fiscal es la constante `TIN_NAME`,
     * que sale de `settingTIN` del comercio: dice "RUC" en Paraguay y otra
     * cosa en otro país. Por eso esa columna se resuelve por alias y, si
     * ninguno matchea, por su posición conocida (es siempre la segunda, en
     * las dos versiones). Ver `tinOf()`.
     *
     * @return array<int,array<string,string>>
     */
    public static function csvRows(string $csv): array
    {
        $csv = self::stripBom($csv);

        // El legacy manda `\r` sueltos: se parten los tres finales de línea.
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if (count($lines) < 2) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $header = array_map(static fn($h) => self::canon((string) $h), $header);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '\\');
            $row   = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            // La posición cruda se conserva para el fallback del TIN.
            $row['__cells'] = $cells;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Valor de la columna del documento fiscal de una fila del CSV.
     *
     * El encabezado no es estable (es `TIN_NAME`), así que se prueban los
     * alias más probables y, si ninguno está, se cae a la posición 1 — que es
     * donde vive en las dos versiones conocidas del export.
     */
    public static function tinOf(array $row): string
    {
        foreach (['RUC', 'TIN', 'CI', 'NIT', 'RFC', 'CUIT', 'DOCUMENTO'] as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                return trim((string) $row[$alias]);
            }
        }
        $cells = $row['__cells'] ?? null;
        return is_array($cells) ? trim((string) ($cells[1] ?? '')) : '';
    }

    /**
     * Filas de una tabla HTML del legacy.
     *
     * Cada fila sale como `['id' => <data-id del tr>, 'cells' => [...]]`,
     * donde cada celda es el valor CRUDO: `data-order` si está (el legacy lo
     * usa para ordenar, así que es el valor sin formatear: la fecha completa,
     * el monto sin separadores), si no `data-filter`, y recién al final el
     * texto visible.
     *
     * Se usa DOMDocument y no una regex: el nombre de una caja o de un cliente
     * lo escribe el comercio y puede traer `<`, `&` o comillas. Una regex
     * posicional se desalinea con eso y termina leyendo un TIMBRADO de la
     * celda equivocada — sobre un dato fiscal eso no es un bug cosmético.
     *
     * @return array<int,array{id:string,cells:array<int,string>}>
     */
    /**
     * Tabla HTML completa: encabezados + filas.
     *
     * ── Por qué hace falta, y no alcanza con `htmlRows()` ────────────────
     * Las columnas del legacy NO son estables entre versiones. El listado de
     * cajas del sistema VIVO tiene una columna `Sucursal` en la posición 2
     * que el snapshot no tiene: leyendo por índice, el nombre de la sucursal
     * se lee como TIMBRADO y todo lo de la derecha queda corrido uno.
     *
     * Es el mismo defecto que ya se había corregido en el CSV, y la misma
     * solución: resolver la columna por su ENCABEZADO. `columnIndex()` hace
     * el match por palabra clave, que aguanta que el título cambie de
     * mayúsculas, de acentos o de redacción.
     *
     * @return array{headers:array<int,string>,rows:array<int,array{id:string,cells:array<int,string>}>}
     */
    public static function htmlTable(string $html): array
    {
        return [
            'headers' => self::htmlHeaders($html),
            'rows'    => self::htmlRows($html),
        ];
    }

    /**
     * Índice de la columna cuyo encabezado contiene alguna de las palabras
     * clave, o `null` si ninguna matchea (el caller decide el fallback).
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

    /** @return array<int,string> Encabezados normalizados del `<thead>`. */
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
            // El id de la fila NO está siempre en el mismo atributo: cajas,
            // sucursales y transacciones usan `data-id`, pero la tabla de
            // ARTÍCULOS usa `id` a secas. Mirar solo `data-id` saltea en
            // silencio todas las filas del catálogo.
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
                // Mismo caso: el valor crudo viaja en `data-order` en unas
                // tablas y en `data-sort` en otras (artículos). El texto
                // visible es el ÚLTIMO recurso — viene formateado para mirar
                // (miles con punto, "12 ene"), no para parsear.
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
     * Valor de un `<input>`/`<select>` por su atributo `name`, dentro de un
     * form HTML del legacy (`?action=edit`).
     *
     * Es lo que permite leer el bloque fiscal de una caja sin depender del
     * ORDEN de los campos en la pantalla: si el legacy mueve un input de
     * lugar, el `name` sigue siendo el mismo.
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
     * Desenvuelve `{"table": "<html>"}` — la forma en que los `action=*Table`
     * devuelven la tabla cuando se les pasa `js=true`. Si no es JSON, se
     * asume que ya vino el HTML crudo (no todos los actions envuelven).
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

    /** Normaliza un encabezado: mayúsculas, sin acentos, sin espacios de más. */
    private static function canon(string $h): string
    {
        $h = trim(self::stripBom($h));
        $h = mb_strtoupper($h, 'UTF-8');
        $h = strtr($h, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
        ]);
        return preg_replace('/\s+/u', ' ', $h) ?? $h;
    }

    private static function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }
}
