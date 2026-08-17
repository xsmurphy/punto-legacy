-- 139_satelite_touch_parent.sql
-- Ítem/contacto como raíces de sync — trigger genérico de satélites
-- (context/45-satelites-item-contact-sync.md).
--
-- Regla del owner: cualquier cambio a un dato relacionado a un item o un
-- contacto dispara la recarga de ESE item/contacto completo. `item` y
-- `contact` son las únicas dos entidades con delta por fila + lápidas
-- (mig 138, context/43-sync-incremental.md) — bumpear su `updated_at`
-- desde el satélite basta para que el mecanismo YA existente las levante,
-- sin watermarks nuevos por tabla.
--
-- ── Reloj: por qué NO alcanza con now() a secas ─────────────────────────────
-- `item.updated_at`/`contact.updated_at` son TIMESTAMPTZ, pero el resto del
-- codebase NO las escribe con `now()` de Postgres — usa `TODAY`
-- (`api/data.php`, `date('Y-m-d H:i:s')` bajo `date_default_timezone_set(
-- $tenant->settingTimeZone)`) o, en la API REST que no pasa por data.php,
-- `TenantClock::now($companyId)` (api/lib/Support/TenantClock.php) — MISMA
-- fórmula: hora actual, expresada en la timezone CONFIGURADA DEL TENANT,
-- como string naive. `api/includes/db.php` fija la sesión de Postgres a
-- `SET TIME ZONE 'America/Asuncion'` SIEMPRE, sin importar el tenant — por
-- eso ese naive string, al guardarse en una columna TIMESTAMPTZ, se
-- reinterpreta vía esa sesión fija. El watermark que compara el POS
-- (`SyncService::watermarks()->serverTime`) es TODAY. Mientras TODO lo que
-- escribe `updated_at` use la MISMA fórmula (hora del tenant, no la de
-- Postgres), la comparación `>` cierra sin importar qué zona horaria
-- representa el string — es la conclusión ya auditada en mig 138/context/43
-- para los writes directos de item/contact.
--
-- Un trigger que usara `now()` a secas ROMPE esa igualdad para cualquier
-- tenant cuya `settingTimeZone` no sea América/Asunción — existe un fixture
-- real así (`api/lib/Sales/verify_chain/seed.sql`, tenant MX,
-- `settingTimeZone: America/Mexico_City`, ~2-3h de offset contra Asunción):
-- el trigger escribiría la hora "verdadera" (UTC exacta) mientras el resto
-- de los writes de ese tenant siguen en hora naive de Ciudad de México
-- interpretada como si fuera Asunción — exactamente la mezcla de relojes
-- que `context/43-sync-incremental.md` identificó y corrigió para
-- `ItemRepository::archive()`/`ContactRepository::archive()`. Verificado
-- contra Postgres real con el tenant MX del fixture (ver reporte de esta
-- sesión) — no es un riesgo teórico.
--
-- `fn_tenant_wall_clock(companyId)` reproduce la MISMA fórmula que
-- `TenantClock::now()` enteramente en SQL: `now() AT TIME ZONE tz` da la
-- hora actual expresada como wall-clock naive EN esa timezone — el mismo
-- naive string que TODAY/TenantClock producirían — y al asignarlo a la
-- columna TIMESTAMPTZ se reinterpreta vía la sesión fija de Postgres, igual
-- que cualquier otro write. Fallback a 'America/Asuncion' si el tenant no
-- tiene `settingTimeZone` configurada o el valor es inválido (mismo guard
-- que `data.php`/`TenantClock::timezone()`) — nunca aborta la transacción
-- del caller por una config de tenant corrupta.
--
-- ── Diseño: una función genérica, N triggers ────────────────────────────────
-- Mismo patrón que `fn_record_deletion()` (mig 138). La mayoría de los
-- satélites tienen la FK al padre EN LA PROPIA FILA (TG_ARGV[0..2]). Dos
-- (`addon_group_option`, ver más abajo) la tienen INDIRECTA — apuntan a un
-- grupo, que a su vez apunta al item — se resuelve con un join opcional
-- (TG_ARGV[3..5]).
--
-- No-op real: un UPDATE que reescribe la fila con los MISMOS valores
-- (`to_jsonb(OLD) IS NOT DISTINCT FROM to_jsonb(NEW)`) no bumpea — evita
-- trabajo inútil en upserts idempotentes.
--
-- UPDATE que mueve la fila de un padre a otro (ej. reasignar un override de
-- lista de precio a otro item) bumpea LOS DOS — padre viejo y nuevo.
--
-- Idempotente: CREATE OR REPLACE FUNCTION + DROP TRIGGER IF EXISTS + CREATE
-- TRIGGER. No reescribe ninguna tabla existente — agregar un trigger toma
-- un lock ACCESS EXCLUSIVE instantáneo, no debería poder abortar el boot
-- (mismo criterio que mig 138, ver su comentario sobre mig 122).

-- ── 1. Reloj tenant-aware ────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION fn_tenant_wall_clock(p_company_id UUID) RETURNS TIMESTAMP AS $$
DECLARE
  v_tz     TEXT;
  v_result TIMESTAMP;
BEGIN
  SELECT config->>'settingTimeZone' INTO v_tz FROM company WHERE companyId = p_company_id;
  IF v_tz IS NULL OR btrim(v_tz) = '' THEN
    v_tz := 'America/Asuncion';
  END IF;

  BEGIN
    v_result := now() AT TIME ZONE v_tz;
  EXCEPTION WHEN invalid_parameter_value THEN
    -- settingTimeZone corrupta/no-IANA (SQLSTATE 22023): no abortar el write
    -- del caller por un dato de config sucio — mismo guard que
    -- data.php/TenantClock. Acotado a este SQLSTATE (no WHEN OTHERS) para no
    -- esconder un bug real de otro tipo en esta línea.
    v_result := now() AT TIME ZONE 'America/Asuncion';
  END;

  RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE;

-- ── 2. Función genérica de touch ─────────────────────────────────────────────
-- TG_ARGV[0] parent_table   'item' | 'contact'
-- TG_ARGV[1] parent_pk_col  'itemid' | 'contactid'
-- TG_ARGV[2] fk_col         columna que apunta al padre — en la propia fila
--                           del trigger si no hay join, o en join_table si sí
-- TG_ARGV[3] join_table     opcional — tabla intermedia (ej. "addon_group")
-- TG_ARGV[4] join_key_col   opcional — columna DE ESTA FILA que apunta a join_table
-- TG_ARGV[5] join_pk_col    opcional — PK de join_table (matchea join_key_col)
CREATE OR REPLACE FUNCTION fn_touch_parent() RETURNS TRIGGER AS $$
DECLARE
  parent_table  TEXT := TG_ARGV[0];
  parent_pk_col TEXT := TG_ARGV[1];
  fk_col        TEXT := TG_ARGV[2];
  join_table    TEXT := NULLIF(TG_ARGV[3], '');
  join_key_col  TEXT := NULLIF(TG_ARGV[4], '');
  join_pk_col   TEXT := NULLIF(TG_ARGV[5], '');
  new_fk        UUID;
  old_fk        UUID;
  new_join_key  UUID;
  old_join_key  UUID;
BEGIN
  IF TG_OP = 'UPDATE' AND to_jsonb(OLD) IS NOT DISTINCT FROM to_jsonb(NEW) THEN
    RETURN NULL;
  END IF;

  IF join_table IS NULL THEN
    IF TG_OP <> 'DELETE' THEN
      new_fk := (to_jsonb(NEW) ->> fk_col)::uuid;
    END IF;
    IF TG_OP <> 'INSERT' THEN
      old_fk := (to_jsonb(OLD) ->> fk_col)::uuid;
    END IF;
  ELSE
    IF TG_OP <> 'DELETE' THEN
      new_join_key := (to_jsonb(NEW) ->> join_key_col)::uuid;
      IF new_join_key IS NOT NULL THEN
        EXECUTE format('SELECT %I FROM %I WHERE %I = $1', fk_col, join_table, join_pk_col)
          INTO new_fk USING new_join_key;
      END IF;
    END IF;
    IF TG_OP <> 'INSERT' THEN
      old_join_key := (to_jsonb(OLD) ->> join_key_col)::uuid;
      IF old_join_key IS NOT NULL THEN
        EXECUTE format('SELECT %I FROM %I WHERE %I = $1', fk_col, join_table, join_pk_col)
          INTO old_fk USING old_join_key;
      END IF;
    END IF;
  END IF;

  IF new_fk IS NOT NULL THEN
    EXECUTE format(
      'UPDATE %I SET updated_at = fn_tenant_wall_clock(%I.%I) WHERE %I = $1',
      parent_table, parent_table, 'companyid', parent_pk_col
    ) USING new_fk;
  END IF;

  IF old_fk IS NOT NULL AND old_fk IS DISTINCT FROM new_fk THEN
    EXECUTE format(
      'UPDATE %I SET updated_at = fn_tenant_wall_clock(%I.%I) WHERE %I = $1',
      parent_table, parent_table, 'companyid', parent_pk_col
    ) USING old_fk;
  END IF;

  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- ── 3. Triggers — satélites de `item` ────────────────────────────────────────

DROP TRIGGER IF EXISTS trg_item_image_touch_item ON item_image;
CREATE TRIGGER trg_item_image_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_image
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

-- Solo parentitemid: la receta describe al PADRE (qué productos entran en
-- su composición), no al insumo (childitemid) — el insumo sigue siendo el
-- mismo producto, con su propio precio/stock/receta, sin importar en
-- cuántas recetas ajenas participe.
DROP TRIGGER IF EXISTS trg_item_compound_touch_item ON item_compound;
CREATE TRIGGER trg_item_compound_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_compound
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'parentitemid');

DROP TRIGGER IF EXISTS trg_item_category_touch_item ON item_category;
CREATE TRIGGER trg_item_category_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_category
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

DROP TRIGGER IF EXISTS trg_item_brand_touch_item ON item_brand;
CREATE TRIGGER trg_item_brand_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_brand
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

DROP TRIGGER IF EXISTS trg_item_tag_touch_item ON item_tag;
CREATE TRIGGER trg_item_tag_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_tag
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

DROP TRIGGER IF EXISTS trg_itemlocation_touch_item ON itemLocation;
CREATE TRIGGER trg_itemlocation_touch_item
AFTER INSERT OR UPDATE OR DELETE ON itemLocation
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

-- addon_group: FK directa (tabla nueva, quoted camelCase — mig 134).
DROP TRIGGER IF EXISTS trg_addon_group_touch_item ON "addon_group";
CREATE TRIGGER trg_addon_group_touch_item
AFTER INSERT OR UPDATE OR DELETE ON "addon_group"
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemId');

-- addon_group_option: FK INDIRECTA — "itemId" en esta tabla es el producto
-- QUE SE AGREGA como opción (un item ajeno), no el dueño del grupo. El
-- dueño se resuelve vía "groupId" -> addon_group."itemId". Si el DELETE
-- llega por CASCADE desde borrar el addon_group entero, el join no
-- encuentra el padre (ya no existe dentro de la misma transacción) y no
-- bumpea nada acá — inofensivo, el trigger de addon_group YA bumpeó ese
-- item en el mismo statement (ver verificación en el reporte de la sesión).
DROP TRIGGER IF EXISTS trg_addon_group_option_touch_item ON "addon_group_option";
CREATE TRIGGER trg_addon_group_option_touch_item
AFTER INSERT OR UPDATE OR DELETE ON "addon_group_option"
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemId', 'addon_group', 'groupId', 'groupId');

DROP TRIGGER IF EXISTS trg_price_list_item_touch_item ON price_list_item;
CREATE TRIGGER trg_price_list_item_touch_item
AFTER INSERT OR UPDATE OR DELETE ON price_list_item
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

-- Solo packitemid — mismo criterio que item_compound: componentitemid es
-- un producto propio, no algo que el pack "describe".
DROP TRIGGER IF EXISTS trg_pack_component_touch_item ON pack_component;
CREATE TRIGGER trg_pack_component_touch_item
AFTER INSERT OR UPDATE OR DELETE ON pack_component
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'packitemid');

-- NOTA — `stock` NO lleva trigger: `Inventory::manageStock()` ya bumpea
-- item.updatedAt en su único choke point (api/lib/App/Domain/Inventory.php,
-- updateRowLastUpdate('item', ...)). Un trigger acá DUPLICARÍA ese UPDATE en
-- cada movimiento de inventario (cada línea de cada venta) — no sumarlo.
--
-- NOTA — `sold_pack`/`sold_pack_usage`, `voucher_item`,
-- `document_remision_item`, `production_order`, `waste_event`: EXCLUIDAS a
-- propósito (context/45) — transaccionales, registran un EVENTO sobre el
-- item con volumen comparable a `transaction`, no lo configuran.
--
-- NOTA — `combo_group`/`combo_group_item` (mig 20): EXCLUIDAS. Deprecadas
-- desde F5 (mig 136, context/41) — el panel ya no monta el editor,
-- `SaleService` nunca las consultó (nunca afectaron precio ni stock de una
-- venta), y sus datos ya fueron copiados a `addon_group`/`addon_group_option`
-- (que SÍ llevan trigger arriba). Bumpear `item.updatedAt` por un cambio acá
-- dispararía un re-sync completo del item a cada POS por una tabla que
-- NINGÚN consumidor lee — costo sin beneficio. El sub-recurso
-- `/v1/items?resource=combo-groups` sigue respondiendo (compat con
-- integradores externos) pero deliberadamente sin trigger.

-- ── 4. Triggers — satélites de `contact` ─────────────────────────────────────

DROP TRIGGER IF EXISTS trg_customeraddress_touch_contact ON customerAddress;
CREATE TRIGGER trg_customeraddress_touch_contact
AFTER INSERT OR UPDATE OR DELETE ON customerAddress
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('contact', 'contactid', 'customerid');

DROP TRIGGER IF EXISTS trg_contactnote_touch_contact ON contactNote;
CREATE TRIGGER trg_contactnote_touch_contact
AFTER INSERT OR UPDATE OR DELETE ON contactNote
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('contact', 'contactid', 'customerid');

-- NOTA — `cRecordValue` (valores de formulario custom por contacto):
-- EXCLUIDA. Auditado el codebase completo (api/lib, api/v1) y NO existe
-- ningún INSERT/UPDATE hacia esta tabla — el único write es el DELETE del
-- wipe de tenant (CompanyAdminService, borra la company entera, no necesita
-- bumpear un contacto que se está yendo con ella). Es lectura legacy
-- panel-only (CustomerService.php, formularios custom) y nunca viaja en
-- ningún payload del POS (bootstrap/bulk-get/delta). Resuelve el "juicio
-- pendiente" de context/45: sin write path activo y sin consumidor en el
-- payload, un trigger acá nunca dispararía en la práctica y no tiene nada
-- que proteger — si la feature revive con un write path real, sumar el
-- trigger en ese mismo cambio (mismo criterio que combo_group arriba).
