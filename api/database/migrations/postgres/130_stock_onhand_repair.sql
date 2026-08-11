-- 130_stock_onhand_repair.sql
-- Repara `stock.stockOnHand` y agrega el índice que necesita el cálculo nuevo.
--
-- El bug: `Inventory::manageStock()` calculaba el saldo nuevo encadenando
-- contra el `stockOnHand` de la ÚLTIMA fila (por fecha), y leía esa fila de la
-- sucursal de la SESIÓN en vez de la sucursal de la operación. Dos consecuencias
-- que se combinan:
--
--   1. Una compra cargada desde el panel hacia otra sucursal leía el saldo de la
--      sucursal de la sesión — si ahí el ítem no existía, escribía el saldo como
--      si la sucursal destino estuviera en cero.
--   2. Un movimiento con fecha ANTERIOR a la última fila (una compra fechada
--      ayer, que es lo normal) se inserta en el medio del historial, y las filas
--      posteriores nunca se recalculan: sus entradas desaparecen del saldo.
--
-- Caso real: Salmón fresco en Shopping Mariano. Compra de 50 fechada a las
-- 03:00 pero cargada después de una venta de las 16:24; el saldo quedó en
-- -0.160 en vez de 49.840.
--
-- `stockCount` guarda el movimiento CON SIGNO ('-0.080', '50.000'), así que la
-- suma acumulada es el saldo por definición. Esta migración recalcula
-- `stockOnHand` como esa suma, y el código pasa a calcular desde la suma en vez
-- de encadenar snapshots.
--
-- `stockOnHandCOGS` (costo promedio) NO se toca: recalcularlo exige repetir el
-- promedio ponderado movimiento a movimiento con los costos de cada entrada, y
-- el costo histórico no está garantizado en todas las filas. Se corrige solo
-- hacia adelante, con cada movimiento nuevo.

BEGIN;

-- ============================================================
-- 1. Índice para el cálculo por suma
-- ============================================================
-- `SUM(stockCount) WHERE itemId = ? AND outletId = ?` corre en CADA movimiento
-- de stock. Los índices existentes son de una sola columna (idx_stock_item,
-- idx_stock_outlet): el compuesto evita el bitmap AND en la ruta caliente.

CREATE INDEX IF NOT EXISTS idx_stock_item_outlet ON stock (itemid, outletid);

-- ============================================================
-- 2. Recálculo de stockOnHand
-- ============================================================
-- Suma acumulada por (ítem, sucursal) en orden cronológico. Desempate por
-- stockid para que el resultado sea determinístico si dos movimientos comparten
-- timestamp — el orden entre esos dos es arbitrario pero el saldo FINAL es el
-- mismo, que es lo que importa.
--
-- Solo escribe las filas que hoy están mal, para no reescribir la tabla entera.

WITH recalculado AS (
  SELECT stockid,
         SUM(stockcount) OVER (
           PARTITION BY itemid, outletid
           ORDER BY stockdate, stockid
           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
         ) AS saldo
    FROM stock
)
UPDATE stock s
   SET stockonhand = r.saldo
  FROM recalculado r
 WHERE r.stockid = s.stockid
   AND s.stockonhand IS DISTINCT FROM r.saldo;

COMMIT;
