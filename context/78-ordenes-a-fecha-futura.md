# 78 — Órdenes a fecha futura

> Estado: **plan sin implementar** (2026-09-16). D1-D5 PROPUESTAS sin OK del
> owner. Es el prerequisito concreto de la etapa A/B de `context/70-viandas.md`
> (el "lote del día" de ese plan asume una agrupación por fecha que hoy no
> existe).

## 1. El pedido (palabras del owner)

Hoy las órdenes son para el momento (mesa, delivery). Pero hay clientes que
hacen pedidos **para fechas futuras** — ej. comidas para dentro de una semana.
El módulo de producción en lote solo produce lo que está en órdenes AHORA; no
permite traer los pedidos de una fecha específica.

## 2. Qué existe hoy (verificado 2026-09-16)

- **`pos_order` no tiene fecha de entrega.** Sus únicos tiempos son
  `created_at`/`sent_at`/`closed_at` (mig 79). No hay forma de decir "esto es
  para el viernes".
- **El alimentador del lote trae TODA la cola abierta.**
  `OrderDemandService` (`api/lib/Orders/OrderDemandService.php`) agrega por
  producto los ítems `pending`/`preparing` de órdenes no terminales de la
  sucursal — sin ningún filtro temporal. Su propio docblock ya anticipa
  "acotar por fecha" como la evolución correcta.
- **La "agenda" del POS (modo violeta) NO es esto.** Son citas/turnos: filas
  de `transaction` con `fromDate`/`toDate` (`ScheduleService`). Otro dominio,
  no se toca ni se reusa — una cita no es una orden con líneas de cocina.
- **El KDS y la cola** muestran todo lo `pending` sin mirar fechas — hoy no
  pueden hacer otra cosa porque el dato no existe.

## 3. El diseño

Una columna y un filtro. Deliberadamente chico:

1. **`pos_order.scheduled_for`** — la fecha de entrega. `NULL` = para ahora
   (todo lo existente sigue igual). Columna real con índice, NO una clave en
   el JSONB `data`: se filtra, se agrupa y se ordena por ella en cada
   pantalla que la use.
2. **Capturarla al tomar el pedido** — campo opcional en el flujo de órdenes
   (POS y panel). Sin fecha, nada cambia.
3. **El lote filtra por fecha** — `OrderDemandService` acepta la fecha y trae
   solo la demanda de ese día. La pantalla del lote
   (`/produccion/lote`) suma un selector de fecha.
4. **La cocina no ve el futuro** — una orden con `scheduled_for` posterior a
   hoy no aparece en el KDS ni en la cola hasta que llegue su día. Es la
   contracara obligatoria: sin esto, el pedido de la semana que viene
   ensucia la cola de hoy los siete días.
5. **Vista de pedidos futuros** — el listado de órdenes filtra/agrupa por
   `scheduled_for`, para responder "¿qué tengo comprometido para el viernes?".

## 4. Decisiones propuestas (sin OK del owner)

- **D1 — Fecha sola, hora opcional.** El caso real es "para el viernes"; la
  hora exacta importa en delivery puntual, no en producción. Propuesta:
  `scheduled_for TIMESTAMPTZ`, la UI pide fecha y la hora es opcional; el
  lote y el KDS comparan por DÍA (en la zona del comercio, `TenantClock`).
- **D2 — El lote trae UNA fecha, con "hoy" incluyendo las sin fecha.** Traer
  "viernes" = órdenes del viernes. Traer "hoy" = las de hoy MÁS las sin fecha
  (que significan "para ahora") MÁS las vencidas no producidas (un pedido de
  ayer que nadie cocinó sigue siendo trabajo pendiente, no desaparece).
  Rango de fechas: solo si el owner lo pide — una fecha por lote mantiene el
  lote auditable ("este lote es la producción del viernes").
- **D3 — El KDS muestra desde el día de entrega.** No configurable en v1.
  Si un comercio necesita empezar a cocinar el jueves lo que entrega el
  viernes, ese anticipo es del LOTE de producción (que puede traer la fecha
  que quiera cuando quiera), no del KDS.
- **D4 — La orden futura se toma con las reglas de hoy.** Sin reserva de
  stock (coherente con `context/53`: ninguna orden toca stock hoy; el
  "comprometido" derivado de órdenes abiertas, cuando se haga, las incluye
  gratis porque son `pos_order` normales). Sin señas ni anticipos en v1 —
  si el cliente paga por adelantado, eso es la wallet (`context/74`) o la
  venta prepaga de `context/70`, no este plan.
- **D5 — El realtime no cambia.** La orden futura emite los mismos eventos
  que cualquier orden; el KDS la filtra al renderizar, no al suscribirse.

## 5. Fases

- **F1 — Columna + captura.** Mig de `scheduled_for` + campo opcional en el
  flujo de órdenes (POS y panel) + el listado la muestra y filtra.
- **F2 — Lote por fecha.** Parámetro de fecha en `OrderDemandService` (con la
  regla D2) + selector de fecha en `/produccion/lote`.
- **F3 — Cocina.** KDS y cola excluyen lo futuro (D3).

F1 y F2 resuelven el pedido del owner. F3 es la contracara que evita el ruido.

## 6. Arquitecturas rechazadas

- **La fecha en el JSONB `data`.** Se filtra y agrupa por ella en el lote, el
  KDS y el listado — es una columna con índice o cada pantalla paga un scan.
- **Reusar la agenda (`transaction.fromDate`).** Las citas no tienen líneas de
  cocina ni entran al lote; mezclar los dominios rompe los dos.
- **Una entidad "pedido programado" aparte de `pos_order`.** Duplicaría el
  flujo entero (líneas, add-ons, estados, KDS, demanda) para agregarle un
  campo. La orden futura ES una orden.
- **Reservar stock al tomar la orden futura.** Contradice la decisión vigente
  de `context/53` (D1-D4: ninguna orden toca stock; el comprometido es
  derivado). Si se implementa el comprometido, estas órdenes entran solas.
