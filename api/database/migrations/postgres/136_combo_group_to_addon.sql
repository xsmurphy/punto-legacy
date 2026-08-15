-- 136_combo_group_to_addon.sql
-- F5 de context/41: unifica el combo dinámico con el mecanismo de add-ons.
--
-- Contexto. `combo_group` / `combo_group_item` (mig 20) fueron una maquinaria
-- de combo dinámico a medio construir: PANEL-ONLY. `SaleService` nunca las
-- consultó, así que esos grupos jamás afectaron precio ni stock de una venta.
-- La decisión del owner (context/41, 2026-08-14) unificó combo dinámico con
-- add-ons: un combo dinámico es "un producto con grupos de add-ons". Esta
-- migración copia los datos existentes al modelo vivo (`addon_group` /
-- `addon_group_option`, mig 134) para que los grupos que el dueño ya cargó
-- sigan existiendo — ahora sí leídos por la venta.
--
-- NO dropea `combo_group` ni `combo_group_item`: el sub-recurso
-- `/v1/items?resource=combo-groups` queda deprecado pero respondiendo (ver
-- @deprecated en ComboGroupService), y los datos originales quedan como
-- respaldo verificable. El DROP es una migración posterior, cuando el
-- sub-recurso se retire de verdad.
--
-- IDEMPOTENTE: `NOT EXISTS` por (itemId, nombre normalizado). Correr dos veces
-- no duplica grupos. Todo en una transacción: o migra entero o no migra nada.
--
-- ── Mapeo de campos ───────────────────────────────────────────────────────
--
--   combo_group.parentItemId          → addon_group."itemId"
--   combo_group.name                  → addon_group."name"      (ver nota A)
--   combo_group.minSelection          → addon_group."minSelect"
--   combo_group.maxSelection          → addon_group."maxSelect" (ver nota B)
--   combo_group.sort                  → addon_group."sort"
--   combo_group_item.childItemId      → addon_group_option."itemId"
--   combo_group_item.extraPrice       → addon_group_option."priceDelta"
--   combo_group_item.isPreselected    → addon_group_option."isDefault"
--   combo_group_item.sort             → addon_group_option."sort"
--
-- extraPrice/isPreselected se preservan aunque el brief de F5 los daba por
-- descartados: son EXACTAMENTE priceDelta e isDefault del modelo nuevo (D2:
-- "el precio lo define cada opción"), y tirarlos sería perder configuración
-- que el dueño ya cargó. `isLocked` va en FALSE — el modelo viejo no tenía
-- concepto de opción fija (preseleccionada ≠ no-removible), así que
-- inventarlo cambiaría el comportamiento. `maxQty` va en 1 (default): el
-- modelo viejo no repetía una misma opción dentro del grupo.
--
-- Nota A — `name`: addon_group."name" es VARCHAR(80) y combo_group.name es
-- VARCHAR(120) → se trunca a 80. El CHECK btrim(name) <> '' obliga además a
-- un fallback para nombres en blanco (no debería haber, pero un INSERT
-- directo a la tabla vieja pudo dejarlos): se usa 'Grupo'.
--
-- Nota B — `maxSelect`: el CHECK de addon_group exige
-- `maxSelect >= GREATEST(minSelect, 1)`, mientras combo_group solo exigía
-- `maxSelection >= minSelection` (con minSelection >= 0). Un grupo viejo
-- min=0/max=0 es legal allá e ilegal acá — se eleva a 1, que es la lectura
-- semántica correcta ("grupo opcional donde se elige a lo sumo uno").
--
-- Nota C — grupos por CATEGORÍA: `sourceType='category'` era un grupo
-- dinámico ("cualquier ítem de esta categoría"), y el modelo de add-ons no
-- tiene ese modo — sus opciones son ítems explícitos (D1/D2: cada opción
-- lleva su propio priceDelta). Se EXPANDE a un snapshot: las opciones son los
-- ítems activos (itemStatus=1) de esa categoría, de la misma company, al
-- momento de correr esta migración. Consecuencia asumida y visible para el
-- dueño: a partir de acá, agregar un ítem a la categoría ya NO lo expone
-- automáticamente en el combo — hay que agregarlo como opción en la ficha.
-- Es el precio de que la venta por fin lea estos grupos.
--
-- Nota D — nombres DUPLICADOS en el origen: `combo_group` no tiene UNIQUE
-- (parentItemId, name), así que un ítem puede tener dos grupos con el mismo
-- nombre. El destino no los distingue — la idempotencia de esta migración es
-- por `itemId` + `name` — y copiarlos como dos filas dejaría dos grupos
-- indistinguibles en la ficha, imposibles de reconciliar en una segunda
-- corrida. Se FUSIONAN en uno solo (y no se descarta ninguno: tirar el
-- segundo sería perder configuración del dueño). El sobreviviente toma
-- min/max/sort del de menor `sort` y hereda las opciones de TODOS sus
-- homónimos; una opción repetida entre ellos queda una sola vez, con el
-- priceDelta MAYOR — el que cobra, para no regalar sin querer un add-on que
-- el dueño cobraba en uno de los dos grupos.
--
-- El ítem padre se excluye de sus propias opciones (una opción no puede ser
-- el producto mismo — AddonService lo valida al escribir).

BEGIN;

WITH cg_norm AS (
    -- Nombre normalizado una sola vez: lo usan el NOT EXISTS de idempotencia,
    -- el INSERT del grupo y el vínculo grupo<->opciones. Los tres tienen que
    -- coincidir exactamente.
    SELECT cg.groupId          AS old_group_id,
           cg.parentItemId     AS parent_item_id,
           cg.companyId        AS company_id,
           cg.sourceType       AS source_type,
           cg.sourceCategoryId AS source_category_id,
           cg.minSelection     AS min_selection,
           cg.maxSelection     AS max_selection,
           cg.sort             AS sort,
           COALESCE(NULLIF(btrim(left(cg.name, 80)), ''), 'Grupo') AS norm_name
      FROM combo_group cg
),
grp AS MATERIALIZED (
    -- UNA fila por (ítem, nombre) — ver nota D. DISTINCT ON se queda con el de
    -- menor `sort`, desempatando por groupId para que el resultado no dependa
    -- del orden físico de las filas.
    --
    -- MATERIALIZED es obligatorio, no cosmético: este CTE se lee tres veces
    -- (los grupos + las dos ramas de opciones) y contiene gen_random_uuid().
    -- Sin materializar, cada lectura generaría UUIDs distintos y las opciones
    -- apuntarían a grupos inexistentes.
    SELECT DISTINCT ON (n.parent_item_id, n.norm_name)
           gen_random_uuid() AS new_group_id,
           n.parent_item_id,
           n.company_id,
           n.norm_name,
           n.min_selection,
           n.max_selection,
           n.sort
      FROM cg_norm n
     WHERE NOT EXISTS (
               SELECT 1
                 FROM "addon_group" ag
                WHERE ag."itemId" = n.parent_item_id
                  AND ag."name"   = n.norm_name
           )
     ORDER BY n.parent_item_id, n.norm_name, n.sort, n.old_group_id
),
ins_groups AS (
    INSERT INTO "addon_group"
        ("groupId", "companyId", "itemId", "name", "minSelect", "maxSelect", "sort", "status")
    SELECT g.new_group_id,
           g.company_id,
           g.parent_item_id,
           g.norm_name,
           g.min_selection,
           GREATEST(g.max_selection, GREATEST(g.min_selection, 1)),  -- nota B
           g.sort,
           TRUE
      FROM grp g
    RETURNING 1
),
opts AS (
    -- sourceType='items': lista explícita, con su extraPrice y su preselección.
    SELECT g.new_group_id           AS group_id,
           gi.childItemId           AS item_id,
           GREATEST(gi.extraPrice, 0) AS price_delta,  -- CHECK priceDelta >= 0
           gi.isPreselected         AS is_default,
           gi.sort                  AS src_sort
      FROM grp g
      -- El vínculo es por (ítem, nombre) y NO por groupId: así las opciones de
      -- los grupos homónimos fusionados (nota D) caen todas en el grupo
      -- sobreviviente en vez de perderse con su grupo de origen.
      JOIN cg_norm n
        ON n.parent_item_id = g.parent_item_id
       AND n.norm_name      = g.norm_name
       AND n.source_type    = 'items'
      JOIN combo_group_item gi ON gi.groupId = n.old_group_id
     WHERE gi.childItemId <> g.parent_item_id

    UNION ALL

    -- sourceType='category': snapshot de la categoría (nota C).
    --
    -- La categoría de un ítem vive HOY en dos lugares y hay que mirar los dos:
    -- `item_category` (m2m, mig 16) y la FK legacy `item.categoryId`, que la
    -- propia mig 16 declara vigente ("se mantiene para compat legacy hasta
    -- Slice E") y solo backfilleó UNA vez, al aplicarse. Un ítem creado por un
    -- camino que escribe únicamente `item.categoryId` no tiene fila en
    -- `item_category`, y mirar solo la m2m lo dejaría fuera del combo en
    -- silencio — justo el modo de falla que esta migración no puede permitirse,
    -- porque no hay vuelta atrás para el dueño: la categoría deja de
    -- expandirse sola después de F5.
    SELECT g.new_group_id,
           i.itemId,
           0,
           FALSE,
           -- Una categoría no trae orden propio: se ordena alfabéticamente, que
           -- es como el dueño espera ver una lista de bebidas en la ficha.
           -- Desempate por itemId para que dos nombres iguales no dependan del
           -- orden físico de las filas.
           (row_number() OVER (PARTITION BY g.new_group_id ORDER BY i.itemName, i.itemId) - 1)::INT
      FROM grp g
      JOIN cg_norm n
        ON n.parent_item_id = g.parent_item_id
       AND n.norm_name      = g.norm_name
       AND n.source_type    = 'category'
      JOIN item i
        -- companyId del ítem contra el del GRUPO: una categoría pertenece a un
        -- solo tenant, pero el filtro explícito es lo que garantiza que ninguna
        -- opción cruce de company aunque el dato de origen esté sucio.
        ON i.companyId  = g.company_id
       AND i.itemStatus = 1
       AND i.itemId    <> g.parent_item_id
       AND (
               i.categoryId = n.source_category_id
            OR EXISTS (
                   SELECT 1
                     FROM item_category ic
                    WHERE ic.itemId     = i.itemId
                      AND ic.categoryId = n.source_category_id
               )
           )
),
dedup AS (
    -- Una opción por (grupo, ítem): tanto la fusión de homónimos como el doble
    -- camino de categoría (legacy + m2m) pueden proponer el mismo ítem dos
    -- veces, y `addon_group_option` no tiene UNIQUE que lo frene. Gana el
    -- priceDelta más alto (nota D).
    SELECT DISTINCT ON (group_id, item_id)
           group_id, item_id, price_delta, is_default, src_sort
      FROM opts
     ORDER BY group_id, item_id, price_delta DESC, src_sort
)
INSERT INTO "addon_group_option"
    ("groupId", "itemId", "priceDelta", "isDefault", "isLocked", "maxQty", "sort")
SELECT group_id,
       item_id,
       price_delta,
       is_default,
       FALSE,
       1,
       -- `sort` se recalcula en vez de copiarse: dos grupos fusionados traen
       -- dos secuencias que arrancan ambas en 0, y dejarlas crudas daría un
       -- orden ambiguo dentro del grupo resultante.
       (row_number() OVER (PARTITION BY group_id ORDER BY src_sort, item_id) - 1)::SMALLINT
  FROM dedup;

COMMIT;
