<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

use Punto\Api\Support\DbQueryException;

/**
 * El código contable EXTERNO que llevan las categorías (`fin_category.code`) y
 * los centros de costo (`fin_cost_center.code`).
 *
 * Existe para matchear la taxonomía interna de Punto contra el plan de cuentas
 * del contador del comercio (un sistema o listado de afuera). Las dos
 * taxonomías lo tratan igual, así que la normalización y el mapeo de la
 * violación de unicidad viven acá una sola vez en vez de duplicarse en
 * `CategoryService` y `CostCenterService`.
 *
 * Ver mig 167_centros_de_costo.sql.
 */
final class AccountingCode
{
    /** Igual que el `varchar(40)` de las dos columnas. */
    public const MAX_LEN = 40;

    /**
     * '' → NULL. Los índices únicos de la mig 167 son PARCIALES y no cuentan
     * los vacíos, pero guardar '' haría que el listado muestre una celda vacía
     * en vez del guion de "sin código".
     *
     * NO fuerza mayúsculas ni formato: el código lo dicta el sistema del
     * contador y este campo existe para copiarlo TAL CUAL. La unicidad sí es
     * case-insensitive (los índices van sobre `lower(code)`), así que "A100" y
     * "a100" no pueden convivir aunque se guarden como se escribieron.
     */
    public static function normalize(mixed $code): ?string
    {
        $code = trim((string) ($code ?? ''));
        if ($code === '') {
            return null;
        }
        if (mb_strlen($code) > self::MAX_LEN) {
            throw new \RuntimeException('El código no puede superar los ' . self::MAX_LEN . ' caracteres');
        }
        return $code;
    }

    /**
     * Corre $fn traduciendo la violación de un UNIQUE de la mig 167 en un
     * mensaje que el operador entiende.
     *
     * La red final es el ÍNDICE, no un pre-check con SELECT: entre la
     * verificación y la escritura hay una ventana de carrera (mismo criterio
     * que `SettingsService::updateGeneral()` con el slug de la company).
     *
     * @param array<string,string> $byIndex índice → mensaje.
     * @param string $fallback mensaje ante un 23505 de un índice no listado.
     */
    public static function guardUnique(callable $fn, array $byIndex, string $fallback)
    {
        try {
            return $fn();
        } catch (DbQueryException $e) {
            $msg = $e->getMessage();
            foreach ($byIndex as $index => $friendly) {
                if (stripos($msg, $index) !== false) {
                    throw new \RuntimeException($friendly);
                }
            }
            if ($e->sqlState() === '23505' || stripos($msg, 'duplicate key') !== false) {
                throw new \RuntimeException($fallback);
            }
            throw $e;
        }
    }
}
