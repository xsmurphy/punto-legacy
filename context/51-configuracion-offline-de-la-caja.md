# 51 — Configuración offline de la caja (cola de operaciones)

**Estado:** implementado 2026-08-23 (branch `frontend/pos-config-offline`).
**Corrección del owner, mismo día:** *"si hay forma de mostrar el total mucho
mejor"* — el cierre offline SÍ muestra el total de lo que este dispositivo
registró, con los huecos declarados en pantalla. Ver §4.
**Pedido del owner:** "La caja debe funcionar como una app en modo offline.
Yo debo poder cambiar la configuración que afecte la caja, hotkeys, etc., y que
funcione offline; luego al recuperar la conexión se sincroniza. Lo que NO se
puede cambiar offline son la sucursal y la caja. […] También debo poder abrir y
cerrar caja offline; solo que el cierre será a ciegas porque no tendremos el
total vendido."

---

## 1. Qué se puede hacer sin conexión y qué no

| Operación | Sin red | Cómo |
|---|---|---|
| Ajustes del POS (interruptores) | Sí | patch encolado, canal `pos-config` |
| Hotkeys (grilla de accesos) | Sí | grilla entera encolada, canal `hotkeys` |
| Apariencia (tema) | Sí | ya era local — `next-themes`, nunca tocó la red |
| Impresoras (alta/edición/baja de bindings) | Sí | canal `printer-bindings` |
| Abrir caja | Sí | canal `drawer` |
| Cerrar caja | Sí, con el total de este dispositivo | canal `drawer` — ver §4 |
| Extracción / ingreso de efectivo | Sí | canal `drawer` |
| **Cambiar de sucursal o de caja** | **No** | selectores deshabilitados con el motivo a la vista |

Cambiar de sucursal o de caja no se puede y no es una limitación técnica que
haya que levantar después: son las dimensiones que definen la numeración fiscal
y la exclusividad de la caja (`context/29`), y el snapshot offline solo conoce
la sucursal actual — no hay a dónde mudarse. Lo que **sí** se arregló es que
esos selectores mostraran los valores actuales en vez de aparecer vacíos (el
bug reportado): salen del snapshot del bootstrap, deshabilitados, con el motivo
escrito debajo en una línea de altura constante.

---

## 2. Arquitectura — cola de operaciones

Generalización de la cola de ventas (`lib/pos/offline-queue.ts`) a todo lo
demás. Store `pendingOps` en la MISMA IndexedDB (`offline-db.ts` v5, único
dueño del schema — v5 agrega `shiftJournal`, ver §4).

| Archivo | Rol |
|---|---|
| `lib/pos/pending-ops.ts` | la cola: encolar, peek, marcar, descartar, backoff |
| `lib/pos/pending-ops-sync.ts` | el motor: qué se manda, en qué orden, qué se reintenta |
| `lib/pos/pending-ops-transport.ts` | de una fila a su request HTTP |
| `lib/pos/local-register-state.ts` | la vista local = servidor + lo que falta mandar |
| `hooks/use-pending-ops-sync.ts` | ciclo de vida (rescate, intervalo, evento `online`) |
| `lib/pos/shift-journal.ts` | lo que este device registró del turno (§4) |
| `lib/pos/local-shift-total.ts` | el total local, puro, con sus huecos (§4) |
| `lib/pos/shift-close-reconciliation.ts` | local vs. servidor al sincronizar el cierre (§4) |

Tres invariantes:

1. **Canal FIFO** (`stream`). Dentro de un canal las operaciones se aplican en
   orden y de a una, y la primera que no sale bien **frena el canal**. En
   `drawer` eso es lo que impide aplicar la apertura del turno siguiente sobre
   una caja que el servidor todavía cree abierta porque el cierre quedó
   rechazado. Los canales entre sí no se frenan.
2. **Cerco por caja** (`registerId`). Una operación encolada para la caja A
   nunca se aplica si el device ahora es la caja B: se marca terminal con
   `REGISTER_CHANGED` y un texto que dice qué hacer. Volver a esa caja y
   reintentar la envía.
3. **Idempotencia**. Ver §3.

`pendingOps` **sobrevive a `purgeOfflineSnapshots()`** (el logout / la
revocación remota), igual que `pendingSales` y por el mismo motivo: ahí adentro
puede haber un cierre de caja, y que la sesión se muera no deshace que el
cajero haya contado la plata y cerrado.

---

## 3. Idempotencia — sin tabla de recibos

El caso a sobrevivir no es el rechazo (ese se ve) sino el silencioso: la
request llegó y se aplicó, y la respuesta se perdió. El device no distingue eso
de "no llegó" y reintenta. Cada operación tiene que tolerarlo:

- **Ajustes / hotkeys** — son `PUT` de valores: aplicarlos dos veces deja el
  mismo estado.
- **Apertura / cierre de caja** — ya eran idempotentes en el backend:
  `DrawerService::open()` devuelve `'Already Open'` (con
  `uidx_drawer_register_open` detrás) y `close()` devuelve `'Already Closed'`.
- **Extracción / ingreso** — dedupe por (monto, fecha, caja) en
  `addExpense`/`addIncome`; la fecha lleva segundos y es la del momento en que
  se operó, así que el reenvío colisiona consigo mismo.
- **Alta de impresora** — era la única que NO lo era (`gen_random_uuid()` en el
  `INSERT` daba una fila nueva por envío). Ahora **el `id` lo genera el
  cliente** y el `INSERT` va con `ON CONFLICT ("id") DO NOTHING` + re-SELECT
  scopeado por `companyId` (`PrinterBindingService::create`). Un id de otro
  comercio da 409, nunca la fila ajena. Bonus: la impresora creada sin red
  tiene su id definitivo desde el primer momento, así que se la puede editar o
  borrar antes de que sincronice.

No hizo falta migración: `printer_binding.id` ya era la PK.
Arnés: `api/tests/printer_binding_idempotency_test.php` (11 checks contra
Postgres real; verificado que se pone rojo).

---

## 4. Decisión de producto — el total del turno según este dispositivo

Sin red el servidor no puede dar el total del turno. La pregunta era si mostrar
en su lugar el total *conocido por este dispositivo*.

**Primera decisión (2026-08-23, revertida el mismo día): no mostrar ninguno.**
El argumento: el device conoce sus ventas en cola, pero las que llegaron al
servidor después de la última lectura del resumen no están ni en el resumen
viejo ni en la cola; un total corto en una pantalla de arqueo no es un dato
incompleto sino uno engañoso, porque hace que un faltante parezca cuadrar.

**Decisión vigente (owner): se muestra el total, con los huecos escritos al
lado.** El argumento anterior suponía que otro aparato podía estar vendiendo en
la misma caja sin que este se enterara — y eso ya no puede pasar: la tenencia
de caja es EXCLUSIVA y está implementada (`register_lease`, migs 141/143,
context/29 §4) y el device la conoce sin red por el grant local con TTL
(`register-tenancy.ts`). Mientras la caja es de este device, **sus ventas son
el turno**.

### De dónde sale el número

De un registro propio, no de un cache del servidor: el store `shiftJournal`
(IndexedDB v5, `lib/pos/shift-journal.ts`) anota cada venta que esta caja
emitió y cada movimiento que hizo, **con red o sin ella**, en el momento en que
ocurre. Una venta que ya sincronizó pesa lo mismo que una en cola — si el total
se leyera de `pendingSales` iría *bajando* a medida que vuelve la conexión, que
es lo peor que puede hacer un número de arqueo.

El cálculo es puro (`lib/pos/local-shift-total.ts`) y usa la misma fórmula que
`DrawerService::composeSummary()`: inicial + ventas + ingresos − extracciones,
con el efectivo separado para el conteo del cajón.

### Los huecos, y qué se hace con cada uno

| Hueco | Detectable | Qué se hace |
|---|---|---|
| Ventas del turno anteriores a la tenencia de este device | Sí (`heldSince` > apertura) | Se muestra el total con la advertencia |
| El turno no lo abrió este device (no conoce el inicial) | Sí (no hay `drawerOpen` en el journal) | Se omite la fila "Caja Inicial" y se avisa |
| El journal arrancó con el turno empezado (app actualizada) | Sí (`journalSince` > apertura) | Se avisa |
| Movimientos de efectivo hechos desde el panel | No | Advertencia PERMANENTE, siempre visible |
| Cobros de crédito y operaciones de otras cajas | No | Misma advertencia permanente |

`heldSince` es nuevo en el grant de tenencia: se fija cuando aparece un
`registerLeaseId` distinto y no se mueve con los latidos. Si el grant se perdió
(base limpiada, re-pareo) se re-estampa en el presente, o sea que el device se
declara con MENOS cobertura de la que tiene y advierte de más — el lado seguro
del error.

### Lo que NO cambia

- **`blindControl` manda.** Con el control a ciegas prendido no hay total, y
  que se caiga la red no es una excusa. La regla vive dentro de
  `computeLocalShiftTotals()` (devuelve `null`), no en el JSX: ninguna pantalla
  futura puede olvidarse de respetarla.
- **El arqueo definitivo lo calcula el servidor** con el monto contado cuando
  el cierre sincroniza. El bloque de la pantalla lo repite con todas las
  letras y cada fila dice "según este dispositivo".
- **No se imprime el ticket de cierre sin conexión**: lista montos del turno
  que el device no puede sostener.

### Si el total local no coincide con el del servidor

No puede pasar desapercibido, así que el cierre ahora **devuelve el arqueo**:
`POST /v1/drawer.php` con `action=close` responde `closing` con
total/subtotal/salesTotal/date, leídos ANTES del UPDATE (después no hay turno
abierto que sumar). El cierre encolado viaja con el total que el device tenía
(`localTotals`, se guarda y no se manda), y al aplicarse se comparan:

- **Coinciden** → toast de éxito y el informe queda en Control de Caja.
- **No coinciden** → toast de advertencia (15 s) y el informe se pinta en
  destructivo, con los dos números y la diferencia, hasta que alguien lo
  descarta. La diferencia puede ser un faltante o una operación que el device
  no vio; las dos posibilidades necesitan que alguien las mire.

Para que esa comparación signifique algo, el motor **no manda el cierre hasta
que las ventas del turno sincronizaron** (`canSendPendingOp`): son dos colas
independientes y sin ese freno el servidor cerraría el arqueo sin las ventas
que todavía están en vuelo, y toda diferencia sería un falso positivo. Es una
espera, no un fallo: no cuenta intentos ni marca error, y `SyncQueueDialog` la
explica en la fila del cierre.

Verificación server-side: `api/tests/drawer_closing_totals_test.php` (14 checks
contra Postgres real — incluye el caso de la extracción hecha desde el panel,
que es el hueco #2 convertido en número). Comprobado que se pone rojo
revirtiendo la conducta, en las dos mitades.

---

## 5. Regla de conflicto — la caja gana en lo que tocó

Escenario: alguien cambia los ajustes desde el panel mientras la caja está
offline y también los cambia.

> **La caja gana en los campos que TOCÓ; el panel conserva todo lo demás.**

No es una preferencia sino una consecuencia de cómo se encola: se guarda un
**PATCH de las claves que el cajero cambió**, nunca una copia de la config
entera. `PUT /v1/register?resource=config` ya mergea parcial sobre lo guardado,
así que un cambio del panel en OTRA clave sobrevive intacto. La colisión real
—la misma clave en los dos lados— se resuelve a favor de la caja porque:

1. es la config de ESA caja: nadie más cambia de comportamiento;
2. el cajero está parado frente al aparato con el interruptor ya mostrando su
   elección, y una reversión silenciosa se lee como "el ajuste no anda";
3. no necesita reloj confiable. Un *last-write-wins* por timestamp dependería
   de relojes de tablets que ya sabemos que derivan — toda la maquinaria de
   `tenantNow` existe por eso.

**La excepción se enforcea estructuralmente, no por regla de merge.**
`blindControl` no está en `POS_CONFIG_DEFAULTS`, así que el `PUT` del device
físicamente no puede tocarlo: lo administra solo el panel. Cualquier otra cosa
que el owner quiera reservar al panel sale del whitelist por el mismo camino,
no se agrega a una tabla de prioridades.

**Hotkeys** son la salvedad: el endpoint no tiene forma parcial, así que la
grilla se reemplaza entera y la caja gana del todo. Se justifica porque la
grilla se edita únicamente desde el POS (el panel no tiene editor) y un merge
por slot armaría una disposición que nadie diseñó.

Mientras una operación está en cola, las lecturas del recurso **no la pisan**:
la vista es "servidor + lo que falta mandar". Sin eso, el refetch que dispara
la reconexión puede entrar antes de que la cola drene y le borra al cajero, de
la pantalla, el cambio que acaba de hacer.

---

## 6. Estado pendiente en pantalla

Un solo indicador, el que ya existía: `OfflineStatusPill` (arriba de la toolbar
del carrito). Las operaciones entran ahí, no en una banda nueva — la banda
`OfflineBanner` se eliminó en 2026-08-23 justamente por duplicar avisos.

- Sin conexión: *"Sin conexión · 2 ventas y 1 cambio en cola"*.
- Algo rechazado (venta u operación): pill destructivo, *"N pendientes con
  error — tocá para revisar"* → navega a **Menú → Pendientes**
  (`SyncQueueList`), que lista las operaciones ARRIBA de las ventas, con su
  etiqueta congelada al encolar ("Cerrar caja — 1.250.000 Gs"), reintentar y
  descartar. El `SyncQueueDialog` que antes mostraba esta misma tabla se
  eliminó en 2026-08-23: la sección ES el lugar donde se ven, no la antesala.
  La sección se llama "Pendientes" y ya no "Ventas pendientes" porque acá
  adentro puede haber un cierre de caja, y nadie lo buscaría bajo "Ventas".
- Con conexión y sin nada roto las operaciones en cola no se avisan: se
  sincronizan solas en segundos.

Además, **Control de Caja** muestra en su propio cuerpo las operaciones de caja
rechazadas, con el detalle y un botón "Revisar". No es un aviso duplicado: es
la pantalla donde está parada la persona responsable del arqueo, y un cierre
que falló no puede depender de que mire el pill.

Nada de esto desplaza controles: la línea de motivo bajo los selectores tiene
altura constante y el aviso de Control de Caja vive al final del cuerpo
scrolleable, arriba de la barra de acciones fija (`context/14` §10).

---

## 7. Efecto colateral corregido — un cliente por realm

`useCreatePrinterBinding` / `useUpdate…` / `useDelete…` pegaban siempre con
`api` (cookie del panel) aunque la lectura fuera del device. En el browser del
operador "andaba" porque tiene las dos sesiones abiertas, pero **escribía con
el scope del panel, no con el de la caja** — el mismo cruce de realms que causó
el bug de espacios (memoria `project_client_per_realm_no_cross_credentials`).
Ahora las mutaciones aceptan `client`, y `PrintersManager` recibe `client` +
`outletId` desde el POS. Sin ese arreglo la cola offline se habría montado
sobre un camino online ya torcido.

---

## 8. Pendiente

- **Bloqueo por inactividad** en Ajustes sigue siendo un input sin backend
  (`TODO (backend)` preexistente, fuera del alcance de este slice).
- **Alta de `station_printer`** (el servidor de impresión) sigue requiriendo
  red: es un pareo de dispositivo, no configuración. Solo los *bindings* son
  offline.
