-- 203_addon_group_qty_mode.sql
-- Grupos de add-ons con CANTIDAD LIBRE POR OPCIÓN y tope sobre la SUMA
-- (context/41-addons-y-combos.md, pedido del owner 2026-09-07).
--
-- ============================================================
-- QUÉ NO SE PODÍA EXPRESAR ANTES
-- ============================================================
--
-- El caso del owner: "Caja surtida de 100 empanadas. Me lista varios sabores
-- y entre ellos elijo la cantidad que quiera de cada uno, pero el límite
-- global son 100."
--
-- El modelo de la mig 134 tiene DOS contadores y ninguno es ese:
--
--   · `addon_group.minselect`/`maxselect` cuentan OPCIONES DISTINTAS elegidas
--     (regla 4 de context/modules/02-combos-y-addons.md). "maxSelect=100" en
--     una caja de empanadas significaría "hasta 100 SABORES distintos", no
--     "hasta 100 empanadas".
--   · `addon_group_option.maxqty` topea la repetición de UNA MISMA opción
--     ("2x panceta"). Es por opción: doce sabores con maxqty=100 cada uno
--     dejan armar una caja de 1.200 empanadas.
--
-- Nada suma las cantidades a nivel GRUPO. Sin eso, la caja de 100 no es
-- representable — y aproximarla con `maxqty` en cada sabor es un tope que
-- miente (no es global) y que hay que re-tipear en cada opción cada vez que
-- cambia el tamaño de la caja.
--
-- ============================================================
-- 1. `qtymode` — QUÉ CUENTAN minselect/maxselect
-- ============================================================
--
-- Se agrega UNA columna de MODO al grupo en vez de un par de columnas
-- `minqty`/`maxqty` paralelas. Los dos modos son mutuamente excluyentes por
-- definición —un grupo topea variedad O topea unidades, nunca las dos cosas
-- a la vez— así que columnas paralelas dejarían siempre dos de cuatro en
-- NULL y abrirían el estado ambiguo "las cuatro cargadas, ¿cuál manda?".
--
--   · 'options'  (DEFAULT, comportamiento HISTÓRICO sin cambio alguno):
--                minselect/maxselect cuentan opciones distintas elegidas.
--   · 'quantity' (nuevo): minselect/maxselect acotan la SUMA de cantidades
--                del grupo. La caja de 100 es minselect=100, maxselect=100
--                (exacto); un rango — "entre 6 y 12" — también se expresa.
--
-- El DEFAULT no es cosmético: todas las filas vivas quedan en 'options' y
-- ningún grupo existente cambia de semántica con esta migración.
--
-- SMALLINT alcanza y no se toca: el tope de una caja surtida vive en el
-- orden de las decenas/centenas, muy lejos de 32767.
--
-- El CHECK viejo `maxselect >= GREATEST(minselect,1)` sigue valiendo en los
-- dos modos (min=max=100 lo cumple), así que tampoco se toca.
--
-- ============================================================
-- IDENTIFICADORES: TODO LOWERCASE SIN COMILLAS
-- ============================================================
--
-- La mig 134 creó estas dos tablas con columnas camelCase ENTRECOMILLADAS
-- (`"maxQty"`, `"minSelect"`), pero la mig **150** normalizó el schema entero
-- a lowercase justamente para eliminar esa clase de bug: hoy la columna real
-- se llama `maxqty`, y `ALTER TABLE ... "maxQty"` falla con
-- `column "maxQty" of relation "addon_group_option" does not exist`.
--
-- Es un error de RUNTIME que no atrapa ni el build ni el lint (visto en la
-- primera corrida de esta migración contra el arnés). Todo lo que se agregue
-- de acá en adelante va sin comillas — así PG lo pliega a minúsculas y
-- coincide con lo que el resto del schema ya tiene.

BEGIN;

ALTER TABLE addon_group
    ADD COLUMN IF NOT EXISTS qtymode VARCHAR(16) NOT NULL DEFAULT 'options';

-- ADD CONSTRAINT no acepta IF NOT EXISTS en Postgres: se consulta el catálogo.
-- (Ojo si algún día se busca este CHECK por su texto: PG normaliza
-- `IN (...)` a `= ANY (ARRAY[...])` — buscar por conname, no por definición.)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'ck_addon_group_qty_mode'
           AND conrelid = 'addon_group'::regclass
    ) THEN
        ALTER TABLE addon_group
            ADD CONSTRAINT ck_addon_group_qty_mode
            CHECK (qtymode IN ('options', 'quantity'));
    END IF;
END
$$;

COMMENT ON COLUMN addon_group.qtymode IS
  'options = minselect/maxselect cuentan opciones distintas (histórico); quantity = acotan la SUMA de cantidades del grupo (caja surtida).';

-- ============================================================
-- 2. `maxqty` NULLABLE — "sin tope propio de la opción"
-- ============================================================
--
-- En una caja de 100 empanadas cada sabor puede llegar a 100 (todas del
-- mismo). Obligar a escribir 100 en cada uno de los doce sabores duplica el
-- tope del grupo en doce lugares que se desincronizan apenas la caja pasa a
-- ser de 50: el comercio cambia el tope del grupo y los sabores siguen
-- dejando pasar 100.
--
-- NULL = la opción no impone tope propio; el efectivo es el del GRUPO (su
-- `maxSelect` bajo 'quantity') o ninguno. Es dato ausente de verdad, no un
-- centinela: por eso NULL y no 0 (0 chocaría además con el CHECK existente).
--
-- El CHECK `maxqty >= 1` NO se toca y sigue siendo correcto: en SQL
-- `NULL >= 1` evalúa a NULL, y un CHECK solo rechaza cuando da FALSE.
--
-- Sin backfill y sin cambio de default: las filas existentes conservan su
-- número y siguen topeando exactamente igual.

ALTER TABLE addon_group_option
    ALTER COLUMN maxqty DROP NOT NULL;

COMMENT ON COLUMN addon_group_option.maxqty IS
  'Tope de repetición de ESTA opción. NULL = sin tope propio: manda el del grupo (maxselect bajo qtymode=quantity) o ninguno.';

COMMIT;
