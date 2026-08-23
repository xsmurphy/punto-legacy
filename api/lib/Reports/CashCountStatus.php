<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Veredicto de cuadre de un arqueo: ¿el efectivo contado coincide con el
 * esperado, falta plata, o sobra?
 *
 * Vive acá y no en el front porque el mismo veredicto lo consumen el listado
 * del reporte, el detalle y el export XLSX. Tres lugares clasificando por su
 * cuenta es tres lugares donde la tolerancia puede quedar distinta, y un
 * arqueo que dice "cuadra" en la tabla y "faltante" en el detalle no sirve
 * para nada.
 *
 * ── La tolerancia ────────────────────────────────────────────────────────────
 *
 * Un faltante de 1 guaraní no es un faltante: los importes se guardan en
 * DECIMAL(15,2) y la moneda con la que se cuenta el cajón no tiene esa
 * granularidad (en PY la moneda más chica en circulación es de 50 Gs). Pintar
 * de rojo esa diferencia entrena al dueño a ignorar el color, que es
 * exactamente lo que el semáforo viene a evitar.
 *
 * Por eso hay DOS números y se toma el mayor:
 *
 *   1. **Piso de redondeo** (`roundingFloor`): una unidad mínima de la moneda
 *      del tenant — 1 para monedas sin decimales (guaraní), 0.01 para las de
 *      dos (dólar). No es configurable porque no es una política de negocio:
 *      es el ruido del sistema numérico. Reproduce además el `< 1` que el
 *      reporte ya usaba, así que ningún tenant ve cambiar el veredicto de sus
 *      cierres por estrenar la columna configurable.
 *
 *   2. **Tolerancia del comercio** (`settingDrawerTolerance` en
 *      `company.config`, `context/08` §58 — toda regla que clasifica es
 *      configurable). Default **0**: el comercio que no la tocó arquea
 *      exacto, y una diferencia real de 100 Gs se ve. El default NO es un
 *      número inventado tipo "1000 Gs" justamente porque este reporte existe
 *      para encontrar faltantes: una tolerancia que nadie pidió escondería
 *      faltantes reales en todos los tenants a la vez. El que redondea el
 *      vuelto a 50/100 la sube y deja de ver ruido.
 *
 * El veredicto NO se congela con el cierre (a diferencia del contado y el
 * esperado, ver mig 164): los hechos son inmutables, la política de lectura
 * no. Si el dueño sube la tolerancia, el historial se reclasifica — que es lo
 * que querría, no lo contrario.
 *
 * ── Qué NO es esto ───────────────────────────────────────────────────────────
 *
 * No confundir con el informe de discrepancia del cierre sin conexión
 * (`frontend/lib/pos/shift-close-reconciliation.ts`, branch
 * `frontend/pos-config-offline`). Son dos comparaciones distintas y ninguna
 * reemplaza a la otra:
 *
 *   - Aquélla compara EL TOTAL DEL DEVICE contra EL TOTAL DEL SERVIDOR, y
 *     responde "¿este dispositivo vio todo el turno?". Vive en el POS, se
 *     guarda en IndexedDB, se le muestra al cajero y se descarta cuando la
 *     leyó. Es transitoria y del dispositivo.
 *   - Ésta compara EL EFECTIVO CONTADO contra EL ESPERADO, y responde "¿está
 *     la plata?". Vive en Postgres, es permanente, y se muestra en el reporte
 *     del panel al dueño — nunca en la caja.
 *
 * Pueden coexistir sin contradecirse porque miran lados distintos de la
 * ecuación: un cierre puede tener el informe de discrepancia en verde (el
 * device vio todas las ventas) y aun así ser un faltante acá (faltan billetes),
 * y al revés. Lo único que NO puede pasar es que las dos aparezcan juntas en la
 * misma pantalla: el semáforo no se pinta en el POS.
 */
final class CashCountStatus
{
    /** El efectivo contado coincide con el esperado (dentro de la tolerancia). */
    public const OK = 'ok';
    /** Falta dinero: contado < esperado. El caso grave. */
    public const SHORT = 'short';
    /** Sobra dinero: contado > esperado. Anomalía, pero no pérdida. */
    public const OVER = 'over';
    /** No hay con qué comparar: caja abierta, o cierre sin monto esperado. */
    public const UNKNOWN = 'unknown';

    /** Clave del override por comercio en `company.config`. */
    public const SETTING_KEY = 'settingDrawerTolerance';

    /**
     * Techo del override. No es una regla de negocio, es un guard contra el
     * dedo pegado en el 0: una tolerancia de 10 millones apaga el reporte
     * entero sin que nadie se entere de que lo apagó.
     */
    public const MAX_TOLERANCE = 1000000.0;

    /**
     * Unidad mínima de la moneda del tenant. `$decimal` es el toggle
     * `settingDecimal` (SettingsService::general()['decimal']): true = la
     * moneda usa 2 decimales.
     */
    public static function roundingFloor(bool $decimal): float
    {
        return $decimal ? 0.01 : 1.0;
    }

    /**
     * Tolerancia efectiva = la del comercio, nunca por debajo del piso de
     * redondeo. Negativos y valores absurdos se clampean acá y no en el
     * caller: es el único lugar donde el número se convierte en veredicto.
     */
    public static function effectiveTolerance(float $configured, bool $decimal): float
    {
        $clamped = min(abs($configured), self::MAX_TOLERANCE);
        return max($clamped, self::roundingFloor($decimal));
    }

    /**
     * @param float|null $counted  Efectivo que el cajero contó (drawerCloseAmount).
     * @param float|null $expected Efectivo que debía haber. NULL ⇒ UNKNOWN: sin
     *                             esperado no hay veredicto, y asumir 0 sería
     *                             declarar un sobrante por el total del cajón.
     */
    public static function classify(?float $counted, ?float $expected, float $tolerance): string
    {
        if ($counted === null || $expected === null) {
            return self::UNKNOWN;
        }
        $diff = $counted - $expected;
        // `<=`: con el piso en 1 Gs, una diferencia de exactamente 1 Gs cuadra.
        // Es el caso que el owner nombró explícitamente ("1 guaraní por
        // redondeo no debería pintar todo de rojo").
        if (abs($diff) <= $tolerance) {
            return self::OK;
        }
        return $diff < 0 ? self::SHORT : self::OVER;
    }
}
