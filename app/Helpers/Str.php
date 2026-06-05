<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers de texto y encoding del POS.
 *
 * Reemplaza las funciones globales (Slice 4 del plan PSR-4):
 *   - toUTF8($t)            → Str::toUtf8($t)
 *   - markupt2HTML($opts)   → Str::markupHtml($opts)
 *   - isHTML($s)            → Str::isHtml($s)
 *   - isBase64Decode($s)    → Str::tryBase64Decode($s)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~268 callsites totales del POS:
 *   - toUTF8:           238 callers
 *   - markupt2HTML:      19 callers
 *   - isBase64Decode:     9 callers
 *   - isHTML:             2 callers
 *
 * Semántica preservada VERBATIM. NO REFACTORIZAR la tabla `HtMrules`/`MtHrules`
 * de markupHtml — define el formato de notas/mensajes del POS y cambios afectan
 * receipts, agendas, notificaciones por SMS, etc.
 *
 * Nombre `Str` (no `String`) por:
 *   1. `string` es tipo built-in PHP — confusión con autocompletion.
 *   2. Convención Laravel/Symfony para clases utility de texto.
 *   3. Match con el namespace corto típico del ecosistema.
 */
final class Str
{
    /**
     * Normaliza encoding a UTF-8 y corrige mojibake común (Ã¡ → á, etc.).
     * Equivalente legacy: `toUTF8($text)`.
     *
     * @param mixed $text Acepta cualquier tipo — null/false/array → '' (paridad legacy).
     * @return string '' si entrada inválida o conversión falla, sino UTF-8 corregido.
     */
    public static function toUtf8(mixed $text): string
    {
        if (!Validation::isValid($text)) {
            return '';
        }

        $wrong = ['Ã¡', 'Ã©', 'Ã³', 'º', 'Ã±', 'í±', 'Ã']; // la í ('Ã') siempre al final
        $right = ['á',  'é',  'ó',  'ú', 'ñ',  'ñ',  'í'];

        $text = str_replace($wrong, $right, (string) $text);
        $text = rtrim($text);

        if (!Validation::isValid($text)) {
            return '-';
        }

        $utfd = mb_convert_encoding($text, 'UTF-8');
        if (!Validation::isValid($utfd)) {
            return '-';
        }

        return (string) $utfd;
    }

    /**
     * Detecta si un string contiene HTML (cualquier tag).
     * Equivalente legacy: `isHTML($string)`.
     */
    public static function isHtml(string $string): bool
    {
        return $string !== strip_tags($string);
    }

    /**
     * Convierte entre formato markup ligero (estilo WhatsApp: *bold*, _italic_, ~under~)
     * y HTML, en ambas direcciones. Detecta dirección si no se especifica.
     *
     * Equivalente legacy: `markupt2HTML($options)`.
     *
     * @param array|string $options
     *   - Si array: ['text' => '...', 'type' => 'HtM'|'MtH'] (type opcional).
     *   - Si string: el texto a convertir (dirección autodetectada por isHtml()).
     * @return string Texto convertido. strip_tags se aplica en ambos paths.
     */
    public static function markupHtml(array|string $options): string
    {
        if (is_array($options)) {
            $text = $options['text'] ?: '';
            $type = $options['type'] ?? false;
        } else {
            $text = $options;
            $type = false;
        }

        if (!$type) {
            $type = self::isHtml($text) ? 'HtM' : 'MtH';
        }

        $HtMrules = [
            ['find' => '<br>',                  'replace' => '\n'],
            ['find' => '<br/>',                 'replace' => '\n'],
            ['find' => '<br />',                'replace' => '\n'],
            ['find' => '<b>',                   'replace' => '*'],
            ['find' => '</b>',                  'replace' => '*'],
            ['find' => '<strong>',              'replace' => '*'],
            ['find' => '</strong>',             'replace' => '*'],
            ['find' => '<em>',                  'replace' => '_'],
            ['find' => '</em>',                 'replace' => '_'],
            ['find' => '<i>',                   'replace' => '_'],
            ['find' => '</i>',                  'replace' => '_'],
            ['find' => '</i>',                  'replace' => '_'], // dup intencional (paridad legacy)
            ['find' => '<li>',                  'replace' => '- '],
            ['find' => '</li>',                 'replace' => ''],
            ['find' => '<u>',                   'replace' => '~'],
            ['find' => '</u>',                  'replace' => '~'],
            ['find' => '&nbsp;&nbsp;•&nbsp;',   'replace' => '- '],
            ['find' => '<div>',                 'replace' => '\n'],
            ['find' => '</div>',                'replace' => ''],
            ['find' => '<p>',                   'replace' => '\n'],
            ['find' => '</p>',                  'replace' => ''],
        ];

        $MtHrules = [
            ['find' => '/\*(.*?)\*/',     'replace' => '<strong>$1</strong>'],
            ['find' => '/\_(.*?)\_/',     'replace' => '<em>$1</em>'],
            ['find' => '/\~(.*?)\~/',     'replace' => '<u>$1</u>'],
            ['find' => '/\- (.*?)/',      'replace' => '<br>&nbsp;&nbsp;•&nbsp; $1 &nbsp;'],
            ['find' => '/\```(.*?)\```/', 'replace' => '<pre>$1</pre>'],
        ];

        if ($type === 'HtM') {
            foreach ($HtMrules as $rule) {
                $parts = explode($rule['find'], $text);
                $text  = implode($rule['replace'], $parts);
            }
            return strip_tags($text);
        }

        // MtH
        $text = strip_tags($text);
        $text = implode('<br>', explode('\n', $text));
        $text = implode('<br>', explode('\r', $text));
        $text = str_replace(['\n', '\r'], ['<br>', '<br>'], $text);
        $text = nl2br($text);

        foreach ($MtHrules as $rule) {
            $text = preg_replace($rule['find'], $rule['replace'], $text);
        }
        return $text;
    }

    /**
     * Si el string parece base64 válido, lo decodifica + html_entity_decode.
     * Sino retorna el string original sin tocar.
     * Equivalente legacy: `isBase64Decode($str)`.
     */
    public static function tryBase64Decode(string $str): string
    {
        if (preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $str)) {
            return html_entity_decode((string) base64_decode($str));
        }
        return $str;
    }
}
