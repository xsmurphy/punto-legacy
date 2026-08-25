-- 169_drawer_count_by_method.sql
-- El arqueo del cierre, MEDIO DE PAGO POR MEDIO DE PAGO.
--
-- PROBLEMA: el cierre de caja pedía UN monto —el efectivo— y la mig 164
-- congeló contra qué se lo comparaba (`drawer.drawerExpectedAmount`). Pero un
-- turno se cobra por muchas vías: el cajero tiene delante los vouchers de las
-- tarjetas y los comprobantes de QR y transferencia igual que tiene los
-- billetes, y de esa plata nadie le pedía cuenta. Con el control de caja a
-- ciegas la asimetría se vuelve absurda: la pantalla le pide "contá el efectivo
-- y cerrá", y el resto del turno pasa sin control (pedido del owner,
-- 2026-08-24).
--
-- SOLUCIÓN: una fila por medio de pago del turno, escrita en el mismo cierre,
-- con lo esperado y lo contado congelados igual que los de la mig 164. Es una
-- TABLA y no una columna jsonb en `drawer` porque el arqueo por medio es un
-- conjunto de hechos, no un atributo del cierre: el reporte los va a querer
-- agregar entre cierres ("¿qué medio descuadra siempre?", "¿cuánto suma el
-- faltante de tarjeta este mes?"), y eso contra jsonb es una consulta que
-- nadie escribe dos veces.
--
-- QUÉ NO CAMBIA (y por qué): `drawer.drawerCloseAmount` y
-- `drawer.drawerExpectedAmount` siguen siendo EL EFECTIVO, exactamente con el
-- significado que tenían. Todo lo que ya los lee —el semáforo de cuadre
-- (`Reports\CashCountStatus`), el reporte de Control de Cajas, la corrección de
-- apertura— sigue leyendo lo mismo. Esta tabla AGREGA los otros medios y
-- duplica la fila del efectivo para que el informe por medio se lea de un solo
-- lugar; las dos escrituras salen del mismo cálculo y en la misma transacción
-- lógica del cierre, así que no pueden divergir.
--
-- COMPATIBILIDAD: un cliente sin actualizar (o un cierre que quedó encolado en
-- una tablet antes del deploy) manda solo `amount`. Ese caso escribe UNA fila,
-- la del efectivo, con el mismo par contado/esperado que la mig 164 — o sea
-- que el cierre viejo y el nuevo producen informes de la misma forma, con
-- menos filas. Un cierre ANTERIOR a esta migración no tiene filas: la ausencia
-- se lee como "este cierre no arqueó por medio", nunca como ceros (un cero acá
-- diría "no había nada que contar", que es una acusación, no un dato — mismo
-- criterio que la mig 164 con el esperado NULL).
--
-- La tolerancia con la que se clasifica cada fila (verde/rojo/amarillo) NO se
-- congela acá, por la misma razón que en la mig 164: los HECHOS son inmutables,
-- la política de lectura del dueño no (`company.config->>'settingDrawerTolerance'`).

BEGIN;

-- ============================================================
-- 1. TABLA
-- ============================================================
-- Identificadores en minúscula sin comillas: es una tabla NUEVA y sigue la
-- convención de las creadas por migración (period_close, register_lease), no
-- la del legacy camelCase entrecomillado (context/08 §44).
CREATE TABLE IF NOT EXISTS drawer_count (
  drawercountid uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
  drawerid      uuid          NOT NULL REFERENCES drawer(drawerId) ON DELETE CASCADE,
  companyid     uuid          NOT NULL REFERENCES company(companyId),
  -- Clave de agrupación del medio: el nombre resuelto en minúsculas, la MISMA
  -- que usa groupByPaymentMethod() para juntar el slug viejo y el UUID de
  -- taxonomía nuevo en una sola fila (DrawerService::paymentGroupKey()).
  methodkey     text          NOT NULL,
  -- Nombre legible CONGELADO. Si el comercio renombra el medio mañana, el
  -- arqueo de ayer tiene que seguir diciendo cómo se llamaba ayer.
  methodname    text          NOT NULL,
  -- ¿Es la plata del cajón? Se guarda y no se deriva de methodkey porque el
  -- comercio puede renombrar el efectivo ("Contado") y el informe histórico
  -- tiene que seguir sabiendo cuál fila era el cajón.
  iscash        boolean       NOT NULL DEFAULT false,
  -- NULL = no se pudo congelar el esperado de ese medio (mismo criterio que
  -- drawer.drawerExpectedAmount). Nunca 0 por defecto.
  expectedamount DECIMAL(15,2),
  -- Lo que el cajero declaró haber contado de ese medio.
  countedamount  DECIMAL(15,2) NOT NULL,
  createdat      timestamptz   NOT NULL DEFAULT now(),
  -- Un medio, una fila por cierre. Hace que un reenvío del mismo cierre
  -- (cola offline reintentando) actualice en vez de duplicar el arqueo.
  UNIQUE (drawerid, methodkey)
);

COMMENT ON TABLE drawer_count IS
  'Mig 169: arqueo del cierre de caja por medio de pago. Una fila por medio '
  'del turno, con lo esperado y lo contado congelados al cerrar. La fila '
  'iscash duplica drawer.drawerCloseAmount/drawerExpectedAmount (mig 164), '
  'que siguen siendo la fuente de esos dos numeros para el semaforo de cuadre. '
  'Sin filas = cierre anterior a esta migracion: NO se lee como ceros.';

-- El reporte entra por cierre (detalle de un drawer) y por tenant+medio
-- (agregado del mes). companyid al frente en el segundo porque el aislamiento
-- multi-tenant es el primer filtro de toda query del panel.
CREATE INDEX IF NOT EXISTS idx_drawer_count_drawer  ON drawer_count(drawerid);
CREATE INDEX IF NOT EXISTS idx_drawer_count_company ON drawer_count(companyid, methodkey);

COMMIT;
