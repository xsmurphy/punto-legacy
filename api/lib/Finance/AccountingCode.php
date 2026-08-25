<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * El código contable EXTERNO que llevan las categorías (`fin_category.code`) y
 * los centros de costo (`fin_cost_center.code`).
 *
 * Existe para matchear la taxonomía interna de Punto contra el plan de cuentas
 * del contador del comercio (un sistema o listado de afuera). Las dos
 * taxonomías lo tratan igual, así que la normalización vive acá una sola vez
 * en vez de duplicarse en `CategoryService` y `CostCenterService`.
 *
 * La traducción del 23505 a un mensaje legible NO vive acá: es un problema del
 * stack de BD, no del código contable — está en `Support\UniqueViolation`, que
 * cierra el mismo defecto para CUALQUIER índice único alcanzable desde un
 * formulario (incluido el de la mig 153, que no tiene nada que ver con
 * códigos).
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

}
