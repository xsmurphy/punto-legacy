<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers de fecha/tiempo del POS.
 *
 * Reemplaza las funciones globales (Slice 5 del plan PSR-4):
 *   - niceDate($d, ...)           → Date::nice($d, ...)
 *   - niceDate2($d, $type)        → Date::niceAgo($d, $type)
 *   - getNextDatePeriod(...)      → Date::nextPeriod(...)
 *   - dateStartEndTime($s, $e)    → Date::startEndTime($s, $e)
 *   - translateNamesOfWeek($w, ?) → Date::translateWeekName($w, ?)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~185 callsites totales del POS:
 *   - niceDate:             166 callers (función más usada del módulo)
 *   - getNextDatePeriod:      9 callers (cálculo recurrente de ventas)
 *   - niceDate2:              5 callers (display "Hace 2 horas")
 *   - dateStartEndTime:       5 callers (split de rango horario)
 *   - translateNamesOfWeek:   0 callers externos (interno de nice())
 *
 * NOTA: depende de `$GLOBALS['meses']` (array i18n de meses ES) definido
 * en config.php / data.php del bootstrap de /app. Se accede vía
 * `$GLOBALS['meses']` para no asumir scope ni romper si el global no
 * existe (fallback array vacío + 'Sin fecha').
 *
 * NO se incluyó `strToDate` — vive en panel/includes/functions.php
 * (fuera de scope del refactor /app).
 */
final class Date
{
    /**
     * Formatea una fecha al estilo legacy del POS:
     *   "Domingo 03 de Junio, 2026 a las 14:30"
     *
     * Equivalente legacy: `niceDate($date, $hours, $noDay, $year, $weekDay)`.
     *
     * @param mixed $date     Cualquier formato parseable por strtotime() o '0000-00-00 00:00:00'.
     * @param bool  $hours    Si true, append " a las HH:MM".
     * @param bool  $noDay    Si true, omite el día del mes.
     * @param bool  $year     Si true (default), append ", YYYY".
     * @param bool  $weekDay  Si true, prepend nombre del día ("Lunes", "Martes"...).
     * @return string         "Sin fecha" si entrada inválida; sino fecha formateada.
     */
    public static function nice(mixed $date, bool $hours = false, bool $noDay = false, bool $year = true, bool $weekDay = false): string
    {
        if ($date === '0000-00-00 00:00:00' || !Validation::isValid($date)) {
            return 'Sin fecha';
        }

        $meses = $GLOBALS['meses'] ?? [];
        $ts    = strtotime((string) $date);

        $y       = $year ? ', ' . date('Y', $ts) : '';
        $m       = (int) date('m', $ts);
        $d       = $noDay ? '' : date('d', $ts) . ' de ';
        $h       = date('H', $ts);
        $mi      = date('i', $ts);
        $l       = $weekDay ? self::translateWeekName(date('l', $ts)) . ' ' : '';
        $hoursto = $hours ? ' a las ' . $h . ':' . $mi : '';
        $monthName = $meses[$m - 1] ?? '';

        return $l . $d . $monthName . $y . $hoursto;
    }

    /**
     * Formato "tiempo relativo": "Hace 2 horas", "Hace 3 días", etc.
     * Equivalente legacy: `niceDate2($datetime, $type)`.
     *
     * @param string $type 'normal' (default, 1 unidad + prefijo "Hace"),
     *                     'full' (todas las unidades + prefijo),
     *                     'small' (1 unidad, abreviada, sin prefijo).
     */
    public static function niceAgo(mixed $datetime, string $type = 'normal'): string
    {
        if ($datetime === '0000-00-00 00:00:00' || !Validation::isValid($datetime)) {
            return 'Sin fecha';
        }

        $now    = new \DateTime();
        $ago    = new \DateTime((string) $datetime);
        $diff   = $now->diff($ago);
        $plural = '';

        // El legacy calcula semanas a partir de $diff->d; replico verbatim.
        $weekends  = floor($diff->d / 7);
        $diff->d  -= $weekends * 7;

        if ($type === 'small') {
            $string = [
                'y' => 'año', 'm' => 'mes', 'w' => 'sem',
                'd' => 'día', 'h' => 'h',   'i' => 'min', 's' => 'seg',
            ];
        } else {
            $string = [
                'y' => 'año', 'm' => 'mes', 'w' => 'semana',
                'd' => 'día', 'h' => 'hora', 'i' => 'minutos', 's' => 'segundos',
            ];
        }

        foreach ($string as $k => &$v) {
            if (!empty($diff->$k)) {
                if ($type !== 'small') {
                    $plural = ($k === 'm') ? 'es' : 's';
                }
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? $plural : '');
            } else {
                unset($string[$k]);
            }
        }

        if ($type === 'normal') {
            $string = array_slice($string, 0, 1);
            return $string ? 'Hace ' . implodes(', ', $string) : 'Ahora';
        }
        if ($type === 'full') {
            return $string ? 'Hace ' . implodes(', ', $string) : 'Ahora';
        }
        // small
        $string = array_slice($string, 0, 1);
        return $string ? implodes(', ', $string) : 'Ahora';
    }

    /**
     * Devuelve la fecha + ($times × periodo) en el formato especificado.
     * Usado por el cron de ventas recurrentes para calcular `nextDate` y `endDate`.
     * Equivalente legacy: `getNextDatePeriod($frecuency, $times, $date, $format)`.
     *
     * @param string $frequency 'daily' | 'weekly' | 'monthly' | 'quarterly' | 'yearly' | otro (no-op).
     * @param int    $times     Cantidad de periodos a sumar.
     * @param string $date      Fecha base (default: TODAY).
     * @param string $format    Formato de salida para date() (default: 'Y-m-d 00:00:00').
     *
     * NOTA: 'fortnight' está documentada en el legacy pero su rama está vacía
     * (cae al else de no-op). Preservado verbatim por paridad.
     */
    public static function nextPeriod(string $frequency, int $times, ?string $date = null, string $format = 'Y-m-d 00:00:00'): string
    {
        $date = $date ?? (defined('TODAY') ? TODAY : date('Y-m-d H:i:s'));

        $ts = match ($frequency) {
            'daily'     => strtotime($date . ' +' . $times . ' day'),
            'weekly'    => strtotime($date . ' +' . $times . ' week'),
            'monthly'   => strtotime($date . ' +' . $times . ' month'),
            'quarterly' => strtotime($date . ' +' . ($times * 3) . ' month'),
            'yearly'    => strtotime($date . ' +' . $times . ' year'),
            default     => strtotime($date), // incluye 'fortnight' (paridad legacy: rama vacía).
        };

        return date($format, $ts);
    }

    /**
     * Split de rango horario "YYYY-MM-DD HH:MM:SS" → [fecha, inicio HH:MM, fin HH:MM].
     * Equivalente legacy: `dateStartEndTime($startDate, $endDate)`.
     *
     * @return array{0: string, 1: string, 2: string} [date, startHHMM, endHHMM].
     */
    public static function startEndTime(string $startDate, string $endDate): array
    {
        $date  = explodes(' ', $startDate, 0);
        $start = explodes(' ', $startDate, 1);
        $end   = explodes(' ', $endDate,   1);

        $start = explodes(':', $start, 0) . ':' . explodes(':', $start, 1);
        $end   = explodes(':', $end,   0) . ':' . explodes(':', $end,   1);

        return [$date, $start, $end];
    }

    /**
     * Traduce nombre de día/mes en inglés al español (o reemplaza vacío).
     * Equivalente legacy: `translateNamesOfWeek($word, $lang)`.
     *
     * NOTA: el legacy tenía argumento `$lang` con rama 'br' completamente
     * comentada (bug histórico inocuo). Se preserva verbatim: $lang se ignora.
     */
    public static function translateWeekName(string $word, string $lang = 'es'): string
    {
        $src = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $es  = ['Lunes',  'Martes',  'Miércoles', 'Jueves',   'Viernes', 'Sábado',  'Domingo'];
        return str_replace($src, $es, $word);
    }
}
