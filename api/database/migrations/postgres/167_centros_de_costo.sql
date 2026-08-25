-- 167_centros_de_costo.sql
-- Centros de costo + código contable externo en las categorías de Finanzas.
--
-- PEDIDO DEL OWNER (2026-08-24): "Crear una sección para administrar Centros
-- de Costo, que solo van a servir para clasificar a qué centro va cada gasto,
-- similar a las categorías de gastos. Las categorías de gastos deben tener
-- aparte del nombre un campo para asignarle un código para poder matchear con
-- el listado de categorías del contador de la empresa usando un sistema o
-- listado externo."
--
-- DECISIÓN CERRADA CON EL OWNER: el centro de costo es OPCIONAL al cargar un
-- gasto. Los históricos quedan sin asignar y se clasifican después desde el
-- panel — de ahí que `costcenterid` sea NULLABLE y que NO haya backfill.
--
-- ============================================================
-- POR QUÉ `fin_movement` Y NO `expenses`
-- ============================================================
--
-- Los gastos viven en DOS tablas: `expenses` (movimientos de caja del POS) y
-- `fin_movement` (el libro consolidado de Finanzas, que incluye los expenses
-- vía source='expense' MÁS las compras source='purchase', las devoluciones,
-- las cuotas de préstamo y las cargas manuales). `fin_movement` es el único
-- lugar donde están TODOS los gastos: ponerlo en `expenses` dejaría afuera
-- las compras, que es donde está el grueso del gasto de un comercio.
--
-- ============================================================
-- POR QUÉ `code` ES COLUMNA Y NO UNA CLAVE EN `data` (jsonb)
-- ============================================================
--
-- `fin_category.data` existe y está vacío en producción (nadie lo lee ni lo
-- escribe hoy). Igual el código va en columna propia:
--
--   1. El PUNTO del campo es matchear contra el listado del contador → se
--      filtra, se ordena y se exporta por él. Un `data->>'code'` obliga a
--      repetir el cast en cada SELECT, en el índice y en el UNIQUE.
--   2. Necesita un INVARIANTE de unicidad por comercio: dos categorías con el
--      mismo código rompen exactamente el matcheo para el que se pidió el
--      campo. Un UNIQUE sobre una expresión JSONB es posible pero frágil (el
--      mismo problema de la mig 165 con `taxonomyextra`).
--   3. `CategoryService::shape()` devuelve campos explícitos, no el jsonb
--      crudo — una columna es una línea; el jsonb sería una línea más un
--      decode.
--
-- Aplica a categorías de INGRESO y de EGRESO por igual: es la misma tabla y
-- el mismo formulario, y separar por `kind` sería un `if` sin beneficio.

BEGIN;

-- ============================================================
-- 1. Código contable externo en las categorías
-- ============================================================

ALTER TABLE fin_category
    ADD COLUMN IF NOT EXISTS code varchar(40);

COMMENT ON COLUMN fin_category.code IS
    'Código contable externo — sirve para matchear la categoría contra el '
    'plan de cuentas del contador del comercio (sistema o listado de afuera). '
    'Opcional, único por comercio (case-insensitive) cuando está cargado.';

-- Unicidad por COMERCIO, no por (comercio, kind): el plan de cuentas del
-- contador es una sola lista — un código no puede designar a la vez una
-- categoría de ingreso y una de egreso.
--
-- `lower()` porque el operador va a tipear "A100" un día y "a100" al otro y
-- para el contador es el mismo código.
--
-- Parcial (WHERE code IS NOT NULL AND btrim(code) <> ''): el código es
-- opcional, así que las categorías sin código no compiten por unicidad. El
-- `btrim(code) <> ''` cubre el caso de un string vacío escrito por un caller
-- que no normalizó — el service normaliza '' → NULL, pero el índice no
-- depende de esa disciplina.
CREATE UNIQUE INDEX IF NOT EXISTS uq_fin_category_code
    ON fin_category (companyid, lower(code))
    WHERE code IS NOT NULL AND btrim(code) <> '';

COMMENT ON INDEX uq_fin_category_code IS
    'Un código contable no puede designar dos categorías del mismo comercio.';

-- ============================================================
-- 2. `fin_cost_center` — los centros de costo
-- ============================================================
--
-- LISTA PLANA, SIN JERARQUÍA: el owner los describe como una lista para
-- clasificar a qué centro va cada gasto. La jerarquía de 2 niveles que sí
-- tiene `fin_category` (parentid) no se pidió acá y agregarla ahora sería
-- inventar un requisito — con el costo de arrastrar las mismas reglas de
-- validación (padre del mismo kind, no anidar a 3 niveles, no borrar con
-- hijas) para nada.
--
-- Mismas columnas de gestión que `fin_category` a propósito (`sortorder`,
-- `status` como soft-delete, `data` de reserva): son dos taxonomías del mismo
-- módulo y la UI las administra con el mismo patrón.
--
-- NO lleva `kind`: un centro de costo es un destino del gasto (una sucursal,
-- un área, una obra), no un signo contable. El mismo centro puede recibir un
-- egreso y, si el día de mañana se clasifican ingresos, también un ingreso.
--
-- NO lleva `issystem`: no hay centros de costo por defecto que sembrar — la
-- lista arranca vacía y la carga el comercio. Sin seed no hay nada que
-- proteger de un borrado.

CREATE TABLE IF NOT EXISTS fin_cost_center (
    costcenterid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    companyid    uuid NOT NULL,
    name         varchar(120) NOT NULL,
    code         varchar(40),
    sortorder    int NOT NULL DEFAULT 0,
    status       smallint NOT NULL DEFAULT 1,   -- 1=activo, 0=archivado
    created_at   timestamptz NOT NULL DEFAULT now(),
    data         jsonb NOT NULL DEFAULT '{}'::jsonb
);

COMMENT ON TABLE fin_cost_center IS
    'Centros de costo del comercio — clasifican a qué centro se imputa cada '
    'gasto de `fin_movement`. Lista plana (sin jerarquía). Ver mig 167.';

CREATE INDEX IF NOT EXISTS idx_fin_cost_center_company
    ON fin_cost_center (companyid, status, sortorder);

-- Mismo criterio que `uq_fin_category_code`: el código es para matchear
-- contra el sistema del contador, así que no puede repetirse dentro del
-- comercio.
CREATE UNIQUE INDEX IF NOT EXISTS uq_fin_cost_center_code
    ON fin_cost_center (companyid, lower(code))
    WHERE code IS NOT NULL AND btrim(code) <> '';

COMMENT ON INDEX uq_fin_cost_center_code IS
    'Un código contable no puede designar dos centros de costo del mismo comercio.';

-- El NOMBRE también es único por comercio: dos centros de costo homónimos son
-- indistinguibles en el selector del formulario de gasto, y el operador
-- terminaría imputando a cualquiera de los dos al azar. `status` NO entra en
-- la clave — reusar el nombre de un centro archivado volvería ambiguo el
-- histórico ya imputado al viejo.
CREATE UNIQUE INDEX IF NOT EXISTS uq_fin_cost_center_name
    ON fin_cost_center (companyid, lower(name));

-- ============================================================
-- 3. `fin_movement.costcenterid` — la imputación
-- ============================================================
--
-- NULLABLE y sin default: el centro de costo es opcional (decisión del
-- owner). Todo el histórico —696 movimientos en producción— queda en NULL y
-- se clasifica después editando el movimiento desde el panel.
--
-- FK real (no referencia lógica): `fin_cost_center` es una tabla chica y
-- local al mismo comercio, sin partición ni réplica de por medio, así que no
-- aplica el motivo por el que `checkid`/`reconciliationid` quedaron como
-- referencias lógicas. ON DELETE no hace falta: el borrado de un centro es
-- SOFT (status=0), nunca físico — la FK es justamente la que garantiza que
-- nadie lo borre de verdad y deje movimientos apuntando al vacío.

ALTER TABLE fin_movement
    ADD COLUMN IF NOT EXISTS costcenterid uuid REFERENCES fin_cost_center(costcenterid);

COMMENT ON COLUMN fin_movement.costcenterid IS
    'Centro de costo al que se imputa el movimiento. OPCIONAL (decisión del '
    'owner 2026-08-24): los movimientos sin clasificar aparecen bajo "Sin '
    'centro de costo" en el listado y en el reporte. Ver mig 167.';

-- Parcial: la mayoría de las filas van a tener NULL por mucho tiempo
-- (histórico sin clasificar) y no aportan nada a un índice cuyo único uso es
-- el filtro "movimientos de ESTE centro" y el GROUP BY del reporte. El caso
-- "sin centro" se resuelve con el índice de (companyid, date) que ya existe.
CREATE INDEX IF NOT EXISTS idx_fin_movement_costcenter
    ON fin_movement (companyid, costcenterid, date DESC)
    WHERE costcenterid IS NOT NULL;

-- OJO — el UNIQUE parcial de mig 153 (companyid, source, sourceid, accountid,
-- COALESCE(categoryid, <sentinel>)) NO se toca. El centro de costo no divide
-- un movimiento derivado en porciones: una compra se parte por CATEGORÍA (una
-- fila por categoría) pero se imputa ENTERA a un solo centro de costo. Sumar
-- `costcenterid` a esa clave permitiría duplicar saldo ante un reintento del
-- hook que resolviera un centro distinto.

COMMIT;
