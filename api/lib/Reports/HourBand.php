<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\App\Helpers\Date;

/**
 * La FRANJA HORARIA de un reporte, como valor que viaja del endpoint al
 * servicio (F1 de `context/67-filtro-de-franja-horaria.md`).
 *
 * ── Por qué existe un objeto y no dos strings sueltos ────────────────────────
 *
 * `Date::hourRange()` (F0) resuelve el predicado, pero lo resuelve PARA UNA
 * COLUMNA. Y un mismo servicio filtra sobre columnas distintas dentro del mismo
 * reporte: `SalesService::summary()` toca `transaction.transactionDate` en unas
 * queries y `itemSold.itemSoldDate` en la de giftcards; `TransactionsService`
 * alterna entre `a.transactionDate` y la columna sin alias según el dataset. Un
 * fragmento SQL ya armado —la forma en que viaja `$roc`— no sirve acá: quedaría
 * clavado a una columna y el servicio tendría que recibir uno por cada una.
 *
 * Lo que viaja, entonces, son los EXTREMOS ya validados, y cada query pide su
 * propio fragmento con `on($columna)`. La validación ocurre una sola vez, en el
 * endpoint (`fromRequest()`), y el resultado es un objeto que por construcción
 * no puede llevar una hora inválida: para cuando llega al servicio, "franja mal
 * formada" ya dejó de ser un estado posible.
 *
 * ── Sin franja es el caso normal, y no cuesta nada ───────────────────────────
 *
 * La enorme mayoría de las consultas no pide franja. Una `HourBand` vacía es el
 * default del parámetro en cada firma (`new HourBand()`), y `on()` devuelve
 * fragmento vacío y cero binds: la query sale byte por byte como salía antes de
 * esta feature. Eso es lo que hace que cablearla en un servicio no tenga riesgo
 * para los reportes que nadie va a filtrar por hora.
 *
 * ── Zona horaria ────────────────────────────────────────────────────────────
 *
 * No se pasa TZ explícita: los servicios de reportes corren SIEMPRE detrás de
 * `apiAuthTenant()`, que vía `TenantClock::apply()` deja la sesión de Postgres
 * en la zona del comercio. Es la misma premisa sobre la que ya se apoyan
 * `Date::reportRange()` y los `EXTRACT(HOUR ...)` que existen desde antes (ver
 * `context/67`, arquitecturas rechazadas). Un consumidor que arme la query
 * FUERA de un request —un cron, el realm `/admin`— no debe usar esta clase:
 * tiene que llamar a `Date::hourRange()` pasando el huso explícito.
 */
final class HourBand
{
    /**
     * @param string $from Extremo inferior `HH:MM[:SS]`, o vacío = desde el inicio del día.
     * @param string $to   Extremo superior `HH:MM[:SS]`, o vacío = hasta el final del día.
     *
     * @throws \InvalidArgumentException si algún extremo no tiene formato de hora.
     *         Construir a mano con una hora inválida es un error de programación;
     *         el input del cliente entra por `fromRequest()`, que degrada en vez
     *         de explotar.
     */
    public function __construct(
        private readonly string $from = '',
        private readonly string $to = '',
    ) {
        if (!Date::isHourBound($this->from) || !Date::isHourBound($this->to)) {
            throw new \InvalidArgumentException('HourBand: franja horaria inválida (esperado HH:MM o HH:MM:SS)');
        }
    }

    /**
     * Resuelve la franja del request.
     *
     * Devuelve `[banda, valid]` con la misma forma y la misma semántica que
     * `Date::reportRange()`: cuando `valid` es false la banda viene VACÍA —el
     * reporte sale sin filtrar, resultado más amplio y nunca uno inventado—
     * para que un caller que prefiera degradar pueda ignorar el flag. El
     * endpoint que quiera cortar con 422 mira el flag.
     *
     * @param mixed $hourFrom Crudo del request (acepta el `false` de validateHttp()).
     * @param mixed $hourTo   Crudo del request.
     *
     * @return array{0: self, 1: bool}
     */
    public static function fromRequest(mixed $hourFrom, mixed $hourTo): array
    {
        $from = trim((string) ($hourFrom === false || $hourFrom === null ? '' : $hourFrom));
        $to   = trim((string) ($hourTo === false || $hourTo === null ? '' : $hourTo));

        if (!Date::isHourBound($from) || !Date::isHourBound($to)) {
            return [new self(), false];
        }

        return [new self($from, $to), true];
    }

    /** ¿No hay franja pedida? (el caso de la enorme mayoría de las consultas) */
    public function isEmpty(): bool
    {
        return $this->from === '' && $this->to === '';
    }

    /**
     * El fragmento de `WHERE` y sus binds para UNA columna de fecha.
     *
     * El fragmento arranca con `" AND "` y viene parentizado (convención de
     * `Roc::build()`), así que se concatena después de cualquier `WHERE` que ya
     * tenga condiciones sin cambiarle el significado — incluido el `OR` de la
     * franja que cruza medianoche.
     *
     * CONTRATO del call-site (heredado de `Date::hourRange()`): el fragmento se
     * agrega sólo a una query que YA acota por rango de fechas. El predicado de
     * franja no usa índice; se apoya en que el rango ya redujo el conjunto.
     *
     * Los binds van EN LA POSICIÓN donde se concatena el fragmento, que no es
     * necesariamente el final: una query con varios rangos bindeados
     * (`CustomersService::dashboard()` tiene tres) se rompe si se hace
     * `array_merge` al final sin mirar dónde quedó el `?`.
     *
     * @param string $column Columna de fecha, con alias si la query usa alias (`t.transactionDate`).
     *
     * @return array{0: string, 1: array<int, string>} [sql, params]. Vacíos si no hay franja.
     */
    public function on(string $column): array
    {
        // Se descarta el tercer elemento (`valid`) A PROPÓSITO: el constructor
        // ya rechazó todo extremo mal formado, así que acá no puede ser false.
        // Si algún día `hourRange()` empieza a invalidar por otra razón —una
        // columna, un huso—, este descarte deja de ser seguro y hay que
        // propagar el flag en vez de ignorarlo.
        [$sql, $params] = Date::hourRange($column, $this->from, $this->to);

        return [$sql, $params];
    }
}
