<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

// El autoloader de `bootstrap.php` ya resuelve `Punto\Api\Outlets\OutletScope`,
// pero este helper también lo cargan arneses y scripts CLI que no pasan por el
// bootstrap (`verify_chain/run_sale_chain.php`, `api/tests/*`). Sin esto, ahí
// sería un fatal en vez de un reporte.
require_once __DIR__ . '/../Outlets/OutletScope.php';

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
     *
     * View-scope override (frontend 2026-06-13): si bootstrap.php definió
     * `VIEW_OUTLET_ID` (porque el browser mandó header `X-Outlet-Id`), ese
     * gana sobre el `$outletId` que pasa el endpoint. Permite que el dropdown
     * del logo de frontend switchee entre sucursales o el modo "Todas"
     * (`VIEW_OUTLET_ID=''` → no se agrega filtro outletId al WHERE) sin tocar
     * los 21 endpoints de reports.
     *
     * ── Consolidado ACOTADO a un conjunto (2026-09-02) ───────────────────────
     * Hasta hoy había dos estados nada más: UNA sucursal, o TODAS. El realm
     * `api` estrenó el tercero —"las que este usuario tiene asignadas"— y es el
     * que obligó a que el helper sepa emitir `IN (...)`.
     *
     * La lista NO se expresa reusando el camino de `VIEW_OUTLET_ID=''` con un
     * filtro extra encima, que era la otra opción: ese camino significa
     * literalmente "no hay filtro de sucursal", y una segunda pieza que lo
     * corrigiera después dejaría la verdad repartida en dos lugares — con el
     * default peligroso (sin filtro) del lado del que se olvida. Acá el
     * conjunto entra por el MISMO punto que ya decide el filtro, y un caller
     * nuevo no puede saltearlo sin saltearse también el `companyId`.
     *
     * Sigue siendo interpolación y no binds a propósito: es lo que este helper
     * hace desde su primera versión (devuelve un FRAGMENTO que ~39 call sites
     * concatenan, varios re-escribiendo el alias por `str_replace`; algunos lo
     * meten en subqueries donde el orden de los `?` ya no es el del array de
     * params del endpoint). Meter placeholders acá correría los binds de todos
     * ellos —incluidos los de franja horaria insertados EN EL MEDIO del array
     * en sales/transactions/products/orders/expenses— para no ganar nada: los
     * valores son uuids validados contra `UUID_RE` en este mismo método, no
     * texto del request.
     */
    public static function build(string $companyId, string $outletId = '', string $alias = ''): string
    {
        if (!preg_match(self::UUID_RE, $companyId)) {
            throw new \RuntimeException('Contexto de empresa inválido (companyId no es UUID)');
        }
        if (defined('VIEW_OUTLET_ID')) {
            $outletId = (string) constant('VIEW_OUTLET_ID');
        }
        $p   = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $roc = " AND {$p}companyId = '" . $companyId . "'";

        // Una sucursal puntual gana: es el caso del selector del panel y el de
        // una consulta con `?outletId=`, y `= '…'` planifica igual que un `IN`
        // de un elemento pero se lee mejor en un EXPLAIN.
        if (preg_match(self::UUID_RE, $outletId)) {
            return $roc . " AND {$p}outletId = '" . $outletId . "'";
        }

        // Sin sucursal única: o no hay restricción (consolidado del tenant,
        // comportamiento histórico), o hay un conjunto acotado.
        $ids = \Punto\Api\Outlets\OutletScope::current();
        if ($ids !== []) {
            $roc .= " AND {$p}outletId IN ('" . implode("', '", $ids) . "')";
        }
        return $roc;
    }
}
