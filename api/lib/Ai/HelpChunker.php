<?php

declare(strict_types=1);

namespace Punto\Api\Ai;

/**
 * HelpChunker — parte un documento de la base de conocimiento en fragmentos.
 * Ver context/82-base-de-conocimiento-punto-ai.md D8.
 *
 * ── Qué se embebe, y por qué no es solo el cuerpo ──────────────────────────
 *
 * Una sección que dice "Tocá Guardar y listo" no tiene, por sí sola, ninguna
 * palabra que la conecte con la pregunta "¿cómo configuro la impresora de
 * cocina?". El contexto que la hace encontrable —de qué documento salió, bajo
 * qué encabezados vive y a qué rubros aplica— está AFUERA del párrafo. Por eso
 * el texto que se vectoriza es título + ruta de encabezados + rubros + cuerpo,
 * y no el cuerpo pelado.
 *
 * Consecuencia directa: el hash de idempotencia se calcula sobre ESE texto
 * completo. Si cambia el título del documento cambia lo que se embebió, así que
 * el fragmento tiene que re-embeberse aunque su párrafo no se haya tocado.
 *
 * ── Fragmentado ────────────────────────────────────────────────────────────
 *
 * Por encabezado markdown (`#` a `######`), conservando la ruta de encabezados
 * anidados ("Impresoras > Cocina"). Una sección más larga que MAX_CHARS se
 * subdivide por párrafo, nunca a mitad de una oración si se puede evitar.
 *
 * Clase PURA: sin base de datos, sin red, sin estado. Es el único lugar donde
 * se decide qué entra en un fragmento, y su arnés
 * (`api/tests/help_chunker_test.php`) puede correr sin levantar nada.
 */
final class HelpChunker
{
    /** Tope de tamaño de un fragmento, en caracteres. */
    public const MAX_CHARS = 1800;

    /**
     * Piso para no dejar un fragmento residual de dos líneas: un sobrante más
     * corto que esto se pega al fragmento anterior en vez de quedar suelto.
     */
    public const MIN_TAIL_CHARS = 250;

    /**
     * @param list<string> $rubros
     * @return list<array{headingPath:string,content:string,embedText:string,contentHash:string,position:int}>
     */
    public static function chunk(
        string $title,
        string $body,
        array $rubros = [],
        ?string $audiencia = null
    ): array {
        $sections = self::splitByHeadings($body);

        $out      = [];
        $position = 0;

        foreach ($sections as $section) {
            $pieces = self::splitLongSection($section['content']);
            foreach ($pieces as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $embedText = self::buildEmbedText($title, $section['headingPath'], $rubros, $audiencia, $piece);
                $out[] = [
                    'headingPath'  => $section['headingPath'],
                    'content'      => $piece,
                    'embedText'    => $embedText,
                    'contentHash'  => self::hash($embedText),
                    'position'     => $position++,
                ];
            }
        }

        return $out;
    }

    /**
     * Texto que efectivamente se vectoriza. Determinístico: el mismo documento
     * tiene que producir siempre el mismo texto, o el hash deja de servir como
     * idempotencia y cada reindexado vuelve a pagarle a OpenRouter.
     *
     * @param list<string> $rubros
     */
    public static function buildEmbedText(
        string $title,
        string $headingPath,
        array $rubros,
        ?string $audiencia,
        string $content
    ): string {
        $parts = [];

        $title = trim($title);
        if ($title !== '') {
            $parts[] = $title;
        }
        if ($headingPath !== '') {
            $parts[] = $headingPath;
        }

        // Los rubros se ordenan antes de entrar: el owner puede tildarlos en
        // cualquier orden y eso no puede cambiar el hash (ni forzar un
        // re-embebido que no aporta nada).
        $rubros = self::normalizeRubros($rubros);
        if ($rubros) {
            $parts[] = 'Rubros: ' . implode(', ', $rubros);
        }

        $audiencia = $audiencia !== null ? trim($audiencia) : '';
        if ($audiencia !== '') {
            $parts[] = 'Audiencia: ' . $audiencia;
        }

        $parts[] = trim($content);

        return implode("\n\n", $parts);
    }

    /** Hash de idempotencia del fragmento. */
    public static function hash(string $embedText): string
    {
        return hash('sha256', $embedText);
    }

    /**
     * Normaliza la lista de rubros: recorta, saca vacíos y duplicados, y
     * ORDENA. Compartida con el servicio, que la guarda así en la base.
     *
     * @param list<string>|array<mixed> $rubros
     * @return list<string>
     */
    public static function normalizeRubros(array $rubros): array
    {
        $clean = [];
        foreach ($rubros as $r) {
            if (!is_scalar($r)) {
                continue;
            }
            $r = trim((string) $r);
            if ($r === '') {
                continue;
            }
            $clean[mb_strtolower($r)] = $r;
        }
        $out = array_values($clean);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Parte el cuerpo en secciones por encabezado markdown.
     *
     * El texto anterior al primer encabezado es una sección con ruta vacía —
     * es la intro del documento y suele ser lo que mejor lo resume; perderla
     * sería tirar justo el fragmento más representativo.
     *
     * @return list<array{headingPath:string,content:string}>
     */
    private static function splitByHeadings(string $body): array
    {
        $body  = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        $sections = [];
        /** @var array<int,string> $stack nivel => texto del encabezado */
        $stack       = [];
        $currentPath = '';
        $buffer      = [];
        $inFence     = false;

        $flush = static function () use (&$sections, &$buffer, &$currentPath): void {
            $content = trim(implode("\n", $buffer));
            if ($content !== '') {
                $sections[] = ['headingPath' => $currentPath, 'content' => $content];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            // Un `#` dentro de un bloque de código NO es un encabezado (en un
            // instructivo es un comentario de shell o un color). Sin esto, un
            // ejemplo de consola partiría el documento en fragmentos falsos.
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = !$inFence;
                $buffer[] = $line;
                continue;
            }

            if (!$inFence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flush();

                $level = strlen($m[1]);
                $text  = trim($m[2]);

                // Se descartan los niveles iguales o más profundos: un `##`
                // nuevo cierra el `###` anterior.
                foreach (array_keys($stack) as $lvl) {
                    if ($lvl >= $level) {
                        unset($stack[$lvl]);
                    }
                }
                $stack[$level] = $text;
                ksort($stack);
                $currentPath = implode(' > ', array_values($stack));
                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $sections;
    }

    /**
     * Subdivide una sección más larga que MAX_CHARS.
     *
     * Primero por párrafo (línea en blanco). Un párrafo que por sí solo pasa el
     * tope se corta duro, buscando el último espacio antes del límite para no
     * partir una palabra al medio.
     *
     * @return list<string>
     */
    private static function splitLongSection(string $content): array
    {
        if (mb_strlen($content) <= self::MAX_CHARS) {
            return [$content];
        }

        $paragraphs = preg_split('/\n\s*\n/', $content) ?: [$content];

        $pieces  = [];
        $current = '';

        $push = static function (string $piece) use (&$pieces): void {
            $piece = trim($piece);
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        };

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            foreach (self::hardSplit($paragraph) as $part) {
                $candidate = $current === '' ? $part : $current . "\n\n" . $part;
                if (mb_strlen($candidate) <= self::MAX_CHARS) {
                    $current = $candidate;
                    continue;
                }
                $push($current);
                $current = $part;
            }
        }
        $push($current);

        // Sobrante corto: se pega al anterior en vez de quedar como fragmento
        // suelto de dos líneas, que casi nunca responde nada por sí mismo.
        $count = count($pieces);
        if ($count > 1 && mb_strlen($pieces[$count - 1]) < self::MIN_TAIL_CHARS) {
            $tail = array_pop($pieces);
            $pieces[count($pieces) - 1] .= "\n\n" . $tail;
        }

        return array_values($pieces);
    }

    /**
     * Corta un párrafo que solo no entra en el tope. Busca el último espacio
     * antes del límite; si no hay ninguno (una URL larguísima, por ejemplo),
     * corta en seco.
     *
     * @return list<string>
     */
    private static function hardSplit(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::MAX_CHARS) {
            return [$paragraph];
        }

        $out = [];
        while (mb_strlen($paragraph) > self::MAX_CHARS) {
            $window = mb_substr($paragraph, 0, self::MAX_CHARS);
            $cut    = mb_strrpos($window, ' ');
            if ($cut === false || $cut < (int) (self::MAX_CHARS / 2)) {
                $cut = self::MAX_CHARS;
            }
            $out[]     = trim(mb_substr($paragraph, 0, $cut));
            $paragraph = trim(mb_substr($paragraph, $cut));
        }
        if ($paragraph !== '') {
            $out[] = $paragraph;
        }

        return $out;
    }
}
