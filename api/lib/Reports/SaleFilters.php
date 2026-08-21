<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * SaleFilters — único punto de verdad para "¿esta fila de `transaction` es
 * una venta VÁLIDA?" en reportes/rollups. Antes de F1 (context/40-anulacion-
 * y-nota-credito.md) el criterio era solo `transactionType IN (0,3)` —
 * repetido como literal en ~20 call-sites (Dashboard, Sales, Fiscal/RG90,
 * Customers, Categories, Brands, Production, Products, Users, PaymentMethods,
 * Cashflow, ContactAnalytics...). F1 agrega un segundo criterio de exclusión
 * (`voidedAt IS NULL`, mig 154) que TODOS esos call-sites necesitan sumar
 * para que "la venta anulada no suma al total vendido" (pedido textual del
 * owner) sea cierto en cada reporte, no solo en el que se acuerden de tocar.
 *
 * Uso: agregar `SaleFilters::notVoidedSql($alias)` con AND a cualquier WHERE
 * que ya filtre `transactionType IN (0,3[,...])`. NO reemplaza ese filtro de
 * tipo — lo complementa. Deliberadamente NO incluye el filtro de tipo acá:
 * los call-sites varían (`(0,3)`, `(0,3,6)` para incluir devoluciones, etc.)
 * y unificarlo es un cambio más grande, fuera de alcance de F1.
 *
 * Qué NO debe usar esto (ver context/40, commit F1): listados/detalle que
 * MUESTRAN la venta anulada a propósito para auditoría (nunca desaparece,
 * mismo criterio que la anulación de recibos — ver
 * `Reports\TransactionsService`, que ya incluye type=7 explícito en su
 * whitelist) y reconciliación de turno/caja (¿una venta anulada A MITAD de
 * turno sigue apareciendo en el cierre de ese turno? — pregunta de producto
 * sin cerrar, no asumida acá).
 */
final class SaleFilters
{
    /**
     * Fragmento SQL, listo para concatenar con `AND` antes de él, que
     * excluye ventas anuladas (F1). `$alias` es el alias de tabla de
     * `transaction` en la query (vacío si no está aliasada).
     */
    public static function notVoidedSql(string $alias = ''): string
    {
        $col = $alias !== '' ? rtrim($alias, '.') . '.voidedat' : 'voidedat';
        return "{$col} IS NULL";
    }
}
