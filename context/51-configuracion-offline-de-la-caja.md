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
  futura puede olvidarse de respetarla. Desde la mig 169 el cajero a ciegas sí
  ve la LISTA de medios que tiene que contar (no sus montos) — ver abajo.
- **El arqueo definitivo lo calcula el servidor** con el monto contado cuando
  el cierre sincroniza. El bloque de la pantalla lo repite con todas las
  letras y cada fila dice "según este dispositivo".
- **No se imprime el ticket de cierre sin conexión**: lista montos del turno
  que el device no puede sostener.

### El cierre se declara MEDIO POR MEDIO (2026-08-24, mig 169)

Hasta acá el cierre pedía **un** monto: el efectivo. El resto del turno —los
vouchers de las tarjetas, los comprobantes de QR y transferencia— no se contaba.
Con el control a ciegas la asimetría era absurda: la pantalla decía "contá el
efectivo y cerrá", y todo lo demás pasaba sin arqueo (pedido del owner).

**Ahora el cierre declara lo contado de cada medio del turno**, y el efectivo
está SIEMPRE, aunque no haya habido una sola venta en efectivo: el fondo
inicial está en el cajón desde que el turno abrió.

| | Qué se cuenta | Qué se ve mientras se cuenta |
|---|---|---|
| **Normal** | Todos los medios del turno + efectivo | El esperado de cada medio y la diferencia en vivo |
| **A ciegas** | Los mismos | Solo lo que uno tipea. Ningún esperado, ninguna diferencia |

La lista de medios NO se apaga a ciegas, y no contradice la regla: lo que el
dueño decidió ocultar son los **acumulados**, no la existencia de las ventas con
tarjeta. Un cajero que no sabe qué medios tuvo el turno no puede contarlos, y
entonces no hay arqueo. Por eso la lista sale de una función aparte
(`computeLocalShiftMethods()`), que es blind-safe **por construcción** —no
computa montos, así que no hay monto que se pueda filtrar— y
`computeLocalShiftTotals()` sigue devolviendo `null` a ciegas, intacta.

**Payload** (`POST /v1/drawer.php`, `action=close`):

```jsonc
{ "action": "close", "amount": 148000,          // EFECTIVO contado, como siempre
  "counted": [                                   // nuevo, opcional
    { "key": "efectivo", "name": "Efectivo", "code": "cash", "isCash": true, "counted": 148000 },
    { "key": "tcredito", "name": "Tarjeta de crédito", "code": "tcredito", "isCash": false, "counted": 70000 }] }
```

`amount` **sigue siendo el efectivo y nada más**: es lo que se compara contra
`drawerExpectedAmount` (mig 164) y lo que alimenta el semáforo de cuadre del
panel. Mandar ahí la suma de todos los medios convertiría cada turno con tarjeta
en un sobrante gigante — el bug inverso al que la mig 164 vino a arreglar.

**Compatibilidad**: `counted` es opcional. Un cliente sin actualizar, o un cierre
que quedó **encolado en una tablet antes del deploy**, manda solo `amount` y
cierra exactamente como siempre; el servidor escribe la fila del efectivo y
listo. La cola offline reenvía con `counted` intacto cuando lo tiene, y el
`ON CONFLICT (drawerid, methodkey)` hace que un reintento deje **un** arqueo, no
dos.

**Emparejar lo contado con lo esperado no es trivial** y estuvo mal DOS veces
antes de quedar bien. `groupByPaymentMethod()` agrupa por el nombre *resuelto*
por taxonomía y cae al slug crudo cuando no resuelve; la caja solo conoce el
nombre que ella anotó al vender.

1. Solo por clave → el esperado quedaba sin contar y lo contado salía como
   sobrante por el monto entero. Lo encontró el arnés.
2. Por una BOLSA de identidades (clave+nombre+slug juntos, cualquier
   intersección gana) → con dos medios donde el slug de uno es el nombre del
   otro (`QR` con code `transferencia`, más `Transferencia`), el primero se
   quedaba el conteo del segundo y **un turno que cuadraba perfecto salía con
   dos diferencias inventadas**. Lo encontró el review.

Lo vigente: **pasadas ordenadas y excluyentes, siempre dimensión contra la
misma dimensión** — clave, después slug, después nombre normalizado; lo que
matchea sale del pool. El efectivo, además, por bandera en una ÚLTIMA pasada,
para que nunca le gane a un match exacto. Un arqueo que acusa a un cajero
honesto es el peor resultado posible de esta función, y es el modo de falla que
esta forma vuelve imposible.

**Reintento y reparación.** Un cierre reenviado sobre una caja ya cerrada no
corta en `'Already Closed'`: repara el desglose que falte
(`repairCountForClosedDrawer()`, ubicando el turno por `drawerCloseDate =
$date`, no por "el último cerrado"). Sin ese camino, un `UPDATE` que pasó con un
`INSERT` de detalle que falló perdía el arqueo por medio en silencio, y el
`ON CONFLICT (drawerid, methodkey)` que la idempotencia promete era código
muerto. El `COALESCE` del UPSERT impide que una reparación que no conoce el
esperado borre el que ya estaba congelado: un NULL entrante es "no sé", nunca
"olvidate del que sabías".

**El modo a ciegas se filtra en el SERVIDOR**, no solo en el JSX: `drawer.php`
consulta `registerBlindControl` y devuelve el resumen sin totales y el `closing`
sin `expected` ni `difference`. Sobrevive la lista de medios sin montos — sin
saber QUÉ contar no hay arqueo. Ver `context/modules/14-caja.md` regla 7, que
esto corrige.

**Respuesta**: `closing.byMethod` trae `{key, name, isCash, expected, counted,
difference}` por medio. El POS lo muestra una sola vez apenas cierra (nunca a
ciegas) y el panel lo lee en `GET /v1/reports/drawers?id=` →
`countByMethod`, con el veredicto de `CashCountStatus` por fila. Un cierre
anterior a la mig 169 no tiene filas: se informa solo la del cajón, marcada
`source='estimated'`. Los demás medios **no** se muestran en cero — un cero ahí
diría "se contó y no había nada".

Verificación: `api/tests/drawer_count_by_method_test.php` (23 checks contra
Postgres real — incluye el cruce de medios del review y la reparación del
reenvío) + `frontend/lib/pos/__tests__/drawer-count-by-method.test.ts`. El
arnés de la mig 164 (`drawer_cash_count_test.php`, 23 checks) sigue en verde:
el efectivo no cambió de significado.

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

## 8. El turno no cierra con órdenes o espacios abiertos (2026-08-25)

**Estado:** implementado, branch `frontend/cierre-turno-sin-abiertos`.
**Pedido del owner:** *"para poder cerrar el turno se tienen que cerrar todas
las órdenes y espacios, no pueden quedar órdenes abiertas. Pero esto tiene que
ser una función opcional que el comercio pueda activar o no."*

Vive acá y no en un doc propio porque es el mismo interruptor que
`blindControl` en todo lo que importa —una decisión del dueño que la caja
obedece y no puede cambiar— y porque su parte difícil es la de este doc: qué
pasa sin red.

### El interruptor

| | Dónde |
|---|---|
| Guardado | `company.config->>'settingDrawerRequireClosedOrders'` (JSONB), **apagado por default** |
| Se edita en | Panel → Ajustes → POS → "Cajas y arqueo" → *Exigir órdenes y espacios cerrados* |
| Baja a la caja por | `GET /v1/register?resource=config` → `config.requireClosedOrders` |

Es **por COMERCIO**, no por caja — así lo pidió el owner. Esa es la única
diferencia con `blindControl` (que vive en `register.data`, por caja); el
tratamiento en el endpoint de config es idéntico y por el mismo motivo: se
agrega **después** del `array_intersect_key` contra `POS_CONFIG_DEFAULTS`, así
que el `PUT` del device físicamente no lo puede tocar (§5). Lo administra el
panel y la caja solo lo lee.

Baja por la config de la caja y no por el bootstrap a propósito: la config
está cacheada offline (`local-register-state.ts`), y sin red el POS igual
tiene que saber si la regla está prendida para avisar antes de encolar.

### Alcance: SUCURSAL, no caja. Lo decidió el schema

El criterio de partida del owner era mirar solo lo que ESA caja tiene abierto
("el turno es de una caja"), condicionado a verificar si las órdenes están
atadas a caja o a sucursal. Verificado, y decide lo contrario:

1. **`space_session` no tiene columna de caja** (mig 80/81): `companyid`,
   `outletid`, `tableid` y nada más. Un espacio es de la sucursal y cualquier
   caja lo cobra. Un gate por caja no podría mirar espacios — la mitad del
   pedido.
2. **`pos_order.registerid` existe pero no lo filtra nadie**: ni
   `OrderCoreService::list()`, ni el guard de scope, ni la pantalla de órdenes
   del POS. La orden que abrió una tablet pareada a la caja A la cobra la caja
   B, y la lista que ve el cajero es la de la SUCURSAL. Gatear por ahí sería
   estrenar una dimensión que hoy no significa nada, y dejar pasar justo las
   órdenes de espacio.

**Abierto** = `pos_order.status NOT IN ('closed','cancelled')` (mismo idiom que
`SpaceService`/`SpaceSettlementService`) y `space_session.status IN
('open','bill_requested')` (el predicado del índice único parcial
`uq_space_session_active_per_space`, que es la fuente de verdad de "ocupado").
Una sesión fusionada queda `closed` por diseño de la mig 163: no necesita
exclusión propia.

> **A CONFIRMAR CON EL OWNER — una orden ya cobrada puede bloquear el cierre.**
> El estado de la orden y el cobro son **ortogonales**: en el flujo "Orden en
> venta" se factura primero y se ordena después
> (`OrderCoreService.php:71-78`), así que una orden en `delivered` o
> `out_for_delivery` puede no deber un guaraní y bloquear igual. Hoy bloquea, a
> propósito: la regla que se pidió es literal ("no pueden quedar órdenes
> abiertas") y una orden cobrada sin entregar es justo el pendiente operativo
> que no debería cruzar de un turno al siguiente. Si el criterio es "solo lo que
> debe plata", el cambio es acotado — sacar `delivered`/`out_for_delivery` de
> los estados que bloquean, en `ShiftCloseGate::ORDER_CLOSED_STATUSES`.

**La contrapartida está declarada**: una caja no cierra su turno mientras otra
caja de la misma sucursal tenga algo abierto. Por eso el interruptor nace
apagado y lo prende el comercio. Lo que **no** es, es un callejón: todo lo que
bloquea se ve y se cierra desde el MISMO POS, porque órdenes y espacios se
listan por sucursal.

### Una sola consulta para las dos puntas

`ShiftCloseGate::blockers()` (`api/lib/services/ShiftCloseGate.php`) alimenta
**las dos**: el `GET /v1/drawer.php?resource=blockers` con el que el POS
deshabilita el botón, y el `details` del 422 si el cierre se intenta igual. Si
fueran dos consultas, el cajero podría ver "todo listo" antes de tocar el botón
y comerse el rechazo después.

El front pinta el impedimento **en el control de la acción**: botón "Cerrar
caja" deshabilitado + tooltip, nunca un toast post-intento. Y debajo, en el
cuerpo scrolleable de Control de Caja (arriba de la barra fija, sin desplazar
nada — `context/14` §10), el aviso con **qué** falta: los espacios por nombre,
las órdenes por número, y botones a `/pos/ordenes` y `/pos/espacios`. El
tooltip cumple la convención; el bloque es lo que la hace usable en tablet,
donde un botón deshabilitado no tiene hover que revele nada.

El rechazo del servidor es **422 con `details`**, no el 500 del
`catch (\RuntimeException)` de `drawer.php` — que sigue siendo correcto para lo
que se lanza ahí (errores de DB de `DrawerService`). `apiError()` acepta ahora
un `details` opcional en el wrapper compartido, para que el próximo error
estructurado no reinvente el envelope.

### Sin red no hay gate — y es una decisión

**Órdenes y espacios NO están en el snapshot offline.** No los baja el
bootstrap; son queries de red con refetch. Así que sin conexión el dispositivo
no tiene ni siquiera un dato viejo que mirar.

Aunque lo tuviera, la decisión sería la misma: **bloquear un cierre con datos
vencidos es peor que dejarlo pasar.** La orden que el snapshot cree abierta
puede haberla cerrado otra terminal hace horas, y el cajero quedaría con la
plata contada, sin poder terminar el turno y sin forma de comprobar nada. Sin
red el cierre **procede y se encola**, con el aviso a la vista de que la regla
se valida al sincronizar.

### El cierre encolado que llega y encuentra órdenes abiertas

Tres piezas lo mantienen fuera del limbo. **La segunda es la que hace justo el
juicio** y la agregó el review — sin ella la feature era un bloqueo de caja
esperando a pasar:

1. **El gate solo corre con el turno abierto de verdad** (`$svc->isOpen(...)`
   antes de `assertCanClose`). Un cierre que ya se aplicó y se reenvía pasa
   derecho por el camino idempotente `'Already Closed'`. Sin esta guarda,
   órdenes abiertas DESPUÉS de que ese turno terminó rechazarían para siempre
   una operación que ya no tiene nada que validar — y como el canal `drawer` es
   FIFO (§2), ese rechazo congelaría además la apertura del turno siguiente.

2. **El gate se juzga contra el momento del cierre, no contra el presente.**
   `assertCanClose()` recibe el `date` del payload —la hora en que el cajero
   REALMENTE cerró, la misma con la que se sella `drawerCloseDate`— y acota a
   `pos_order.created_at < $date` y `space_session.opened_at < $date`.

   El caso que arregla no lo cubre `isOpen`: cierre offline a las 22:00 que
   sincroniza a las 10:00 del día siguiente **con el turno todavía abierto en el
   servidor**. Sin el corte lo frenan las órdenes que otra caja abrió a las
   9:00 — que no tienen nada que ver con el turno que se cerró — y como el 422
   es terminal y el canal es FIFO, el cajero de la mañana queda trabado por algo
   ajeno. Exactamente el limbo que `context/08` §53 busca evitar.

   La semántica final es **"existía al cerrar Y sigue abierto ahora"**: una
   orden de las 21:00 que alguien cerró a las 23:00 ya no aparece, que es el
   resultado correcto — se resolvió. Online, `date` es *ahora* y el corte no
   cambia nada.

   Comparar el string naive contra `timestamptz` es válido porque
   `TenantClock::apply()` deja la sesión de PG en la TZ del comercio
   (`apiAuthTenant` → `data.php`), que es la convención de storage del proyecto.
   Un `date` que no parsea se descarta y el gate vuelve a juzgar contra el
   presente — el lado estricto, nunca uno que deje pasar un cierre por mandar
   basura.

3. **Cuando el turno sigue abierto y el bloqueo es legítimo, hay salida.**
   `classify()` manda el 422 a terminal (no es reintentable tal cual), la fila
   queda en **Pendientes** con su etiqueta congelada y en el aviso de Control de
   Caja (§6), con **reintentar** y **descartar**. Lo que bloquea son órdenes y
   espacios de la misma sucursal, visibles y cerrables desde ese mismo POS: se
   cierran, se toca reintentar, y el cierre entra. Ese es el resultado
   deseado, no un daño colateral — es exactamente la disciplina que la función
   existe para imponer.

No contradice la regla dura de `context/08` §53 ("el backend NUNCA rechaza una
venta ya emitida"): un cierre de turno no es un documento emitido, y las ventas
del turno ya sincronizaron antes de que el cierre salga (`canSendPendingOp`,
§4). Lo que se rechaza es el ARQUEO, y se rechaza de forma reversible.

### Verificación

`frontend/lib/pos/__tests__/shift-close-gate.test.ts` — 11 checks sobre la
parte pura (`lib/pos/shift-close-gate.ts`): normalización del payload,
singular/plural de los mensajes, etiquetas. Comprobado que se pone rojo
revirtiendo la guarda de null y la de `enabled`.

**Sin arnés PHP todavía** — no se pudo correr nada contra Postgres en el
entorno de la sesión. El SQL se verificó contra las migraciones (79, 80, 81,
163 y las posteriores que tocan esas tablas), pero `ShiftCloseGate` no tiene
todavía su `api/tests/*.php` como sí lo tienen las migs 164 y 169.

---

## 9. Pendiente

- **Arnés PHP de `ShiftCloseGate`** contra Postgres real: que el gate
  bloquee con una orden abierta, que NO bloquee con el flag apagado, que un
  reenvío sobre una caja ya cerrada pase derecho (la guarda anti-limbo), y que
  una sesión fusionada no cuente.
- **Bloqueo por inactividad** en Ajustes sigue siendo un input sin backend
  (`TODO (backend)` preexistente, fuera del alcance de este slice).
- **Alta de `station_printer`** (el servidor de impresión) sigue requiriendo
  red: es un pareo de dispositivo, no configuración. Solo los *bindings* son
  offline.
