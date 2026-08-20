-- 153_fin_movement_category_split_unique.sql
-- Gasto dividido por categoría en compras (owner 2026-08-20, F0 sobre
-- context/22-finanzas-module-plan.md): una compra con líneas de gasto en
-- categorías distintas (ej. insumos de limpieza + ingredientes de cocina en
-- la misma factura) ahora genera VARIOS `fin_movement` — uno por porción
-- exacta de categoría — en vez de uno solo. El saldo de la cuenta no cambia
-- (la suma de las porciones da exacto el mismo total de antes), pero el
-- UNIQUE parcial de mig 73 (`companyid, source, sourceid, accountid`) ya no
-- alcanza: hoy asume 1 sola fila por (origen, cuenta), y con el split hay
-- varias filas legítimas para el MISMO (origen, cuenta) — cada una con su
-- propia categoría.
--
-- Se agrega `categoryid` a la clave de unicidad. `categoryid` es NULLABLE
-- (una porción "sin categoría" es válida — línea sin línea/cabecera/ítem que
-- la clasifique), y en PostgreSQL dos NULL nunca son iguales para un UNIQUE
-- normal — eso permitiría duplicar la porción sin categoría en un reintento
-- del hook (rompe la idempotencia que MovementService::recordDerivedMovement
-- depende para no duplicar saldo). Se usa `COALESCE(categoryid, <sentinel>)`
-- para que "sin categoría" cuente como UN valor más a los fines de
-- unicidad — el sentinel es un UUID que nunca puede colisionar con una
-- categoría real (fin_category.categoryid es gen_random_uuid()).
--
-- `MovementService::recordDerivedMovement()` y `CheckService::ensureMovement()`
-- (las dos INSERT...ON CONFLICT que dependen de este índice) se actualizan en
-- el mismo commit que esta migración — el `ON CONFLICT` debe listar
-- EXACTAMENTE esta misma expresión o Postgres tira "no unique or exclusion
-- constraint matching the ON CONFLICT specification".

BEGIN;

DROP INDEX IF EXISTS uidx_fin_movement_source;

CREATE UNIQUE INDEX uidx_fin_movement_source
    ON fin_movement (
        companyid,
        source,
        sourceid,
        accountid,
        COALESCE(categoryid, '00000000-0000-0000-0000-000000000000'::uuid)
    )
    WHERE (sourceid IS NOT NULL);

COMMIT;
