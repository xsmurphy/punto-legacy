-- 84 — Limpieza de bloques decorativos soft-deleted (bug editor de layout 2026-07-19).
--
-- SpaceService::delete() aplicaba soft-delete (status=0) también a los
-- decorativos (bar/decor_wall/decor_plant), pero list() no filtra por status:
-- el bloque "eliminado" volvía en cada refetch y el editor lo re-pintaba.
-- Desde este fix los decorativos se hard-deletean (no tienen space_session ni
-- pos_order propios — son escenografía). Esta mig borra los zombies ya
-- generados. El CASCADE de space_session.tableid es inofensivo acá: un decor
-- jamás abre sesión (invariante enforced en SpaceSessionService::open()).
-- Sin filtro de companyid A PROPÓSITO: data-fix one-off cross-tenant — el
-- bug afectó a todos los tenants por igual.
DELETE FROM space
 WHERE shape IN ('bar', 'decor_wall', 'decor_plant')
   AND status = 0;
