-- 164_drawer_expected_amount.sql
-- Efectivo ESPERADO en el cajón, congelado en el momento del cierre.
--
-- PROBLEMA: `drawer` guardaba el monto CONTADO (`drawerCloseAmount`) pero no
-- contra qué se lo comparó. El reporte de Control de Cajas recomputaba el
-- "teórico" en el cliente, y encima con la fórmula equivocada: sumaba `sold`
-- (TODOS los medios de pago) contra un monto contado que es SOLO efectivo, así
-- que cualquier turno con una venta con tarjeta salía con un faltante que no
-- existía. El propio código lo admitía ("Aproximación: solo cash impacta el
-- cajón pero acá no tenemos el split por método de pago").
--
-- Y aunque la fórmula fuera correcta, recomputar no sirve para auditar: un
-- arqueo se compara contra lo que se esperaba ESE día. Ventas que sincronizan
-- tarde (offline-first), extracciones cargadas después, una corrección de
-- precios — todo mueve el número recomputado y con él el veredicto de un
-- cierre de hace un mes. Con el cierre de período (mig 157) el pasado ya es
-- inmutable: el número contra el que se arqueó tiene que quedar igual de
-- congelado que el contado.
--
-- SOLUCIÓN: una columna con el efectivo esperado, escrita en el mismo UPDATE
-- que cierra la caja, con EXACTAMENTE el número que el cajero tenía delante
-- (`composeSummary()['subtotal']` = caja inicial + ventas en efectivo +
-- ingresos − extracciones). La diferencia NO se persiste: es
-- `drawerCloseAmount − drawerExpectedAmount`, determinística a partir de dos
-- valores ya congelados, y guardarla sería un tercer número que puede
-- desincronizarse de los otros dos.
--
-- NULL = no hay dato congelado: caja todavía abierta, o cerrada ANTES de esta
-- migración. El reporte NO lo trata como cero — un cero acá se leería como
-- "se esperaba que el cajón estuviera vacío", que es una acusación, no un
-- dato. Los cierres históricos se muestran con un esperado RECALCULADO y
-- marcados como estimados; el veredicto nunca se presenta como si hubiera
-- quedado registrado.
--
-- La tolerancia con la que se clasifica (verde/rojo/amarillo) NO se congela
-- acá a propósito: los HECHOS del arqueo (contado y esperado) son inmutables,
-- pero con cuánta diferencia el comercio considera que "cuadra" es una
-- política de lectura que el dueño puede cambiar y que debe aplicarse
-- también al historial (`company.config->>'settingDrawerTolerance'`).

BEGIN;

-- ============================================================
-- 1. COLUMNA
-- ============================================================
-- Mismo tipo que drawerOpenAmount / drawerCloseAmount: los tres son montos
-- del mismo arqueo y se restan entre sí.
-- Sin comillas dobles → PG la crea como `drawerexpectedamount`, igual que el
-- resto de las columnas de esta tabla legacy (context/08 §44). El acceso
-- camelCase desde PHP lo resuelve CaseInsensitiveArray.
ALTER TABLE drawer
  ADD COLUMN IF NOT EXISTS drawerExpectedAmount DECIMAL(15,2);

COMMENT ON COLUMN drawer.drawerExpectedAmount IS
  'Efectivo esperado en el cajon, congelado al cerrar (caja inicial + ventas en efectivo + ingresos - extracciones). NULL = caja abierta o cerrada antes de la mig 164. La diferencia se deriva: drawerCloseAmount - drawerExpectedAmount.';

COMMIT;
