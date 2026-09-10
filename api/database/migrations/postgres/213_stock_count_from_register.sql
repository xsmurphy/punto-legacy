-- Migration 213 — `stockCountFromRegister`: el cajero genera el conteo.
--
-- Las listas fijas de conteo salieron de Ajustes (owner 2026-09-10): el
-- conteo lo hace el cajero, así que es el cajero quien elige qué contar. En
-- Ajustes queda un switch que habilita esa generación desde la caja.
--
-- ── Por qué hace falta backfill ─────────────────────────────────────────────
--
-- El flag es POSITIVO ("puede generar conteos") y en el JSONB un flag ausente
-- vale falso. Sin esta migración, todo comercio que hoy cuenta en el mostrador
-- se quedaría sin poder contar hasta que alguien entre a Ajustes a prenderlo:
-- el editor de listas ya no existe y el switch nuevo arrancaría apagado.
--
-- El criterio es el comportamiento OBSERVADO, no una preferencia declarada:
-- tener al menos una lista fija cargada es la evidencia de que ese comercio
-- venía contando desde la caja. Los demás arrancan apagado, que es el default
-- conservador de la D3 (el alcance del conteo no era decisión del cajero).
--
-- `stockCountLists` NO se borra. Es trabajo que el dueño cargó, ya no tiene
-- editor ni viaja al bootstrap, y los conteos viejos referencian esas listas
-- por id en `inventory_count.scope` — borrarlas dejaría su historial sin
-- nombre. Se apaga como entrada, no se destruye como dato.
--
-- ── `settingObj` es un STRING JSON, no un objeto jsonb ──────────────────────
--
-- `SettingsService` lo escribe con `json_encode($obj)` (`:748`, `:1066`), o
-- sea que dentro del JSONB `config` la clave guarda TEXTO. Por eso el valor
-- nuevo se arma como jsonb, se pasa a `::text` y se envuelve en `to_jsonb()`:
-- escribirlo como objeto jsonb "funcionaría" para el `json_decode` de PHP
-- pero dejaría dos tipos distintos conviviendo bajo la misma clave, y el
-- próximo merge `||` de `ncmUpdate` se comportaría distinto según el tenant.
--
-- Idempotente: el WHERE excluye a quien ya tiene la clave definida.

-- ── Por qué un DO por fila y no un UPDATE masivo ───────────────────────────
--
-- El UPDATE tiene que castear `settingObj` y `stockCountLists` de texto a
-- jsonb. Si UN tenant tiene ahí un JSON malformado, el cast lanza y aborta la
-- sentencia ENTERA: la migración falla, y como las migraciones corren al
-- arrancar el contenedor del backend, una fila corrupta dejaría a TODOS los
-- comercios sin API. El bucle aísla el fallo en la fila que lo causa y sigue.

DO $$
DECLARE
  r          RECORD;
  obj        jsonb;
  lists      jsonb;
  touched    int := 0;
  skipped    int := 0;
BEGIN
  FOR r IN SELECT companyid, config FROM company LOOP
    BEGIN
      lists := COALESCE(NULLIF(r.config->>'stockCountLists', ''), '[]')::jsonb;
      obj   := COALESCE(NULLIF(r.config->>'settingObj', ''), '{}')::jsonb;
    EXCEPTION WHEN others THEN
      -- Config ilegible: se deja intacta y se sigue. El comercio prende el
      -- switch a mano desde Ajustes; romper el deploy de todos por esta fila
      -- sería mucho peor.
      skipped := skipped + 1;
      CONTINUE;
    END;

    -- Sin listas cargadas no hay evidencia de que este comercio contara desde
    -- la caja: arranca apagado, que es el default conservador.
    CONTINUE WHEN jsonb_typeof(lists) <> 'array' OR lists = '[]'::jsonb;
    -- Ya definido (en cualquier sentido): no se pisa.
    --
    -- `jsonb_exists()` y NO el operador `?`: el `?` de jsonb lo reescribe el
    -- driver como placeholder de PDO y aborta el boot — es exactamente lo que
    -- tiró los deploys de las migs 74 y 77.
    CONTINUE WHEN jsonb_exists(obj, 'stockCountFromRegister');

    UPDATE company
       SET config = jsonb_set(
             config,
             '{settingObj}',
             to_jsonb((obj || jsonb_build_object('stockCountFromRegister', true))::text),
             true
           )
     WHERE companyid = r.companyid;

    touched := touched + 1;
  END LOOP;

  RAISE NOTICE '213: % comercios habilitados, % con config ilegible', touched, skipped;
END $$;
