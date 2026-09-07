<?php

declare(strict_types=1);

namespace Punto\Api\Sales;

use Punto\Api\Support\TenantClock;

/**
 * Vigencia del timbrado de la caja — LA comparación, en un solo lugar.
 *
 * Regla del owner (2026-08-08): el timbrado se configura por caja y tiene
 * vencimiento; con el timbrado vencido NO se puede facturar. Un documento
 * emitido con timbrado caído es inválido ante la autoridad fiscal, así que el
 * corte es duro — no un aviso que el cajero pueda saltear.
 *
 * ## Por qué existe esta clase y no un `if` en cada endpoint
 *
 * Hasta 2026-09-07 la comparación vivía suelta dentro de
 * `RegisterService::invoiceAuthError()` y tenía UN solo caller
 * (`/v1/register/claim.php`): el momento de TOMAR la caja. El camino que
 * realmente emite el documento —`SaleService::save()`, que congela el timbrado
 * en cada venta (mig 145)— nunca comparaba nada, así que una caja tomada antes
 * del vencimiento seguía facturando indefinidamente con el timbrado caído.
 * Poner un segundo `if` en `sales.php` habría dejado dos comparaciones que
 * pueden divergir (y de hecho tienen que diferir en UN punto: contra qué fecha
 * comparan). Acá hay una sola regla, parametrizada por la fecha.
 *
 * ## Contra qué fecha se compara
 *
 * **Contra la fecha de la OPERACIÓN, no contra el `now()` del servidor.** Una
 * venta cobrada a las 22:00 del último día de vigencia que recién sincroniza al
 * día siguiente es LEGAL: el documento se emitió dentro del plazo. Es el mismo
 * principio que `DrawerService::resolveDrawerIdForDate()` (regla 5 de
 * `context/modules/14-caja.md`): la dimensión se resuelve por CUÁNDO se operó,
 * nunca por "qué hora es ahora".
 *
 * El caller pasa esa fecha ya en la zona del tenant: `SaleInput::resolveDate()`
 * la construye con `TenantClock::atInstant()` desde el epoch del device. Para
 * el caso "ahora" (tomar la caja) está `nowFor()`, que la resuelve con
 * `TenantClock::now()`. Ninguno de los dos usa `date()` a secas.
 *
 * La comparación es por DÍA, con strings `YYYY-MM-DD`: el timbrado vence al
 * TERMINAR su último día, no a la medianoche UTC. Los strings ISO ordenan igual
 * que las fechas, así que `>=` alcanza y no hay que construir `DateTime` ni
 * arrastrar una TZ más a la comparación.
 *
 * > **Dos supuestos que conviene tener escritos.**
 * >
 * > 1. El valor que dejó el backfill de la mig 26 tiene forma
 * >    `YYYY-MM-DDTHH:MM:SSZ` (UTC), porque la columna era `TIMESTAMPTZ` antes
 * >    de bajar al JSONB. Recortar a 10 caracteres da el día correcto mientras
 * >    la hora guardada no cruce el día al pasar a la zona del tenant; para los
 * >    tenants al oeste de UTC (todas las Américas) eso no puede pasar. Lo que
 * >    escribe hoy el panel es una fecha pura, así que el caso es sólo el
 * >    histórico.
 * > 2. La fecha de la operación sale del `timestamp` del DEVICE
 * >    (`SaleInput::resolveDate()`), acotado únicamente a
 * >    `[MIN_ISSUE_TIMESTAMP, now + MAX_ISSUE_SKEW_SECONDS]`. Una tablet con el
 * >    reloj atrasado puede, en teoría, saltear este 422 indefinidamente. Es la
 * >    consecuencia buscada de que la venta encolada valga por su fecha real:
 * >    la alternativa —comparar contra `now()`— rechaza ventas legales, que es
 * >    el daño peor. La red de contención es la marca del §"Dónde NO se
 * >    aplica": una venta emitida fuera de vigencia queda registrada como tal.
 *
 * ## Sin vencimiento cargado = sin bloqueo
 *
 * `registerInvoiceAuthExpiration` vacío o `null` NO bloquea nada. Hay comercios
 * que operan sin numeración fiscal (mismo criterio que el filtro de
 * `TransactionsService::registerInfo()`), y el día del deploy no se les puede
 * parar la caja por un campo que nunca llenaron. El guard solo muerde donde el
 * dato existe.
 *
 * ## Dónde NO se aplica
 *
 * En `/v1/offline-sync.php`. Esa venta YA SE EMITIÓ —el ticket está en la mano
 * del cliente— y el backend nunca rechaza una venta ya emitida (context/08
 * §53): rechazarla solo esconde el problema y traba la cola entera. Ahí el
 * documento se ACEPTA y se MARCA (`SaleService` escribe
 * `meta.invoiceAuthExpiredAtEmission`). En teoría el POS ya la bloqueó
 * localmente antes de imprimir (`lib/pos/emission-block.ts`), así que esa marca
 * es la red de seguridad para un reloj de device corrido o una config vieja, no
 * un camino esperado.
 */
final class InvoiceAuthGate
{
    /** Clasificación que viaja en `error.details.code` del 422. */
    public const CODE = 'invoice_auth_expired';

    /**
     * Vencimiento configurado para la caja, o `null` si no hay ninguno.
     *
     * `ncmExecute('SELECT data ...')` APLANA el JSONB (`Query::flattenJsonb`):
     * las claves de `data` llegan directo en la fila y `$row['data']` ya no
     * existe. Y con `LIMIT 1` el resultado es un `CaseInsensitiveArray`, no un
     * array plano — `is_array()` sobre eso da `false` (bug ya pisado en
     * `lease.php` y documentado en `resolveFrozenInvoiceAuth()`), por eso el
     * chequeo real es `is_array() || ArrayAccess`.
     */
    public static function expirationFor(string $registerId, string $companyId): ?string
    {
        if ($registerId === '' || $companyId === '') {
            return null;
        }

        $row = ncmExecute(
            'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $companyId]
        );
        if (!(is_array($row) || $row instanceof \ArrayAccess)) {
            return null;
        }

        $exp = trim((string) ($row['registerInvoiceAuthExpiration'] ?? ''));

        return $exp === '' ? null : $exp;
    }

    /**
     * Fecha de la operación para el caso "ahora", en la zona del tenant.
     * `TenantClock::now()` devuelve `Y-m-d H:i:s` naive; acá solo interesa el día.
     */
    public static function nowFor(string $companyId): string
    {
        return TenantClock::now($companyId);
    }

    /**
     * ¿El timbrado `$expiration` estaba vencido en `$operationDate`?
     *
     * Las dos entradas se recortan a `YYYY-MM-DD` antes de comparar: el
     * vencimiento se guarda como fecha pura pero puede llegar con hora desde la
     * columna `TIMESTAMPTZ` histórica, y la fecha de operación SIEMPRE trae hora
     * (`Y-m-d H:i:s`). Comparar los 10 primeros caracteres es lo que implementa
     * "vence al terminar su último día".
     */
    public static function isExpiredOn(?string $expiration, string $operationDate): bool
    {
        $exp = self::day($expiration);
        if ($exp === null) {
            return false;   // sin vencimiento cargado no se bloquea nunca
        }
        $day = self::day($operationDate);
        if ($day === null) {
            return false;   // sin fecha de operación no hay nada que comparar
        }

        return $day > $exp;
    }

    /**
     * Corta la emisión si el timbrado estaba vencido en la fecha de la operación.
     *
     * @throws InvoiceAuthExpiredException 422 con `details.code = invoice_auth_expired`
     */
    public static function assertValidAt(
        string $registerId,
        string $companyId,
        string $operationDate,
    ): void {
        $expiration = self::expirationFor($registerId, $companyId);
        if (!self::isExpiredOn($expiration, $operationDate)) {
            return;
        }

        $expDay = (string) self::day($expiration);
        throw new InvoiceAuthExpiredException(
            [
                'code'          => self::CODE,
                'expiredOn'     => $expDay,
                'operationDate' => (string) self::day($operationDate),
            ],
            self::message($expDay),
        );
    }

    /**
     * El texto que ve el cajero. Vive acá y no en el endpoint para que el POS y
     * el panel no puedan decir dos cosas distintas del mismo hecho.
     */
    public static function message(string $expirationDay): string
    {
        return 'El timbrado de esta caja venció el ' . $expirationDay
             . '. Actualizalo en Sucursales → Cajas para poder seguir facturando.';
    }

    /** Los 10 primeros caracteres si parecen `YYYY-MM-DD`; `null` si no. */
    private static function day(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        $day = substr($v, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }
}
