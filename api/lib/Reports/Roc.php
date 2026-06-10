<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Helper compartido: construye el fragmento SQL " AND companyId='…' [AND outletId='…']"
 * para los reportes que antes usaban `getROC(1)` del panel (que no existe en /api).
 *
 * Recibe los ids del contexto (COMPANY_ID / OUTLET_ID — claims firmados del JWT, no del
 * request) con guard UUID — defense-in-depth + neutraliza interpolación insegura aunque
 * los valores ya son confiables. Opcionalmente prefija un alias para queries con JOIN
 * (brands usa `c.`, categories usa `b.`).
 *
 * El alias `register` no se prefija acá porque el realm `panel` siempre trae `rid=''` —
 * el panel scopea por usuario/sucursal, no por caja.
 *
 * Patrón establecido en Fase 2 batch 1 (brands/categories/stock-day); centralizado acá
 * cuando apareció la 4ª vez (P2 del code-reviewer).
 */
final class Roc
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param string $companyId UUID del tenant — obligatorio (guard valida formato).
     * @param string $outletId  UUID del outlet — opcional; vacío o no-UUID → no se agrega.
     * @param string $alias     Prefijo `b.` / `c.` para los nombres de columna en JOIN; default sin prefijo.
     * @return string Fragmento que arranca con " AND " (concatenable después de un WHERE existente).
     * @throws \RuntimeException si companyId no es UUID — el endpoint debe abortar con 500.
     */
    public static function build(string $companyId, string $outletId = '', string $alias = ''): string
    {
        if (!preg_match(self::UUID_RE, $companyId)) {
            throw new \RuntimeException('Contexto de empresa inválido (companyId no es UUID)');
        }
        $p   = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $roc = " AND {$p}companyId = '" . $companyId . "'";
        if (preg_match(self::UUID_RE, $outletId)) {
            $roc .= " AND {$p}outletId = '" . $outletId . "'";
        }
        return $roc;
    }
}
