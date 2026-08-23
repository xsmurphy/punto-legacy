# 51 — Configuración offline de la caja (cola de operaciones)

**Estado:** implementado 2026-08-23 (branch `frontend/pos-config-offline`).
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
| Cerrar caja | Sí, **a ciegas** | canal `drawer` — ver §4 |
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
demás. Store `pendingOps` en la MISMA IndexedDB (`offline-db.ts` v4, único
dueño del schema).

| Archivo | Rol |
|---|---|
| `lib/pos/pending-ops.ts` | la cola: encolar, peek, marcar, descartar, backoff |
| `lib/pos/pending-ops-sync.ts` | el motor: qué se manda, en qué orden, qué se reintenta |
| `lib/pos/pending-ops-transport.ts` | de una fila a su request HTTP |
| `lib/pos/local-register-state.ts` | la vista local = servidor + lo que falta mandar |
| `hooks/use-pending-ops-sync.ts` | ciclo de vida (rescate, intervalo, evento `online`) |

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

## 4. Decisión de producto — el cierre a ciegas NO estima el total

Sin red el servidor no puede dar el total del turno. La pregunta era si mostrar
en su lugar el total *conocido por este dispositivo*.

**Decisión: no se muestra ningún total esperado.** El cierre offline es a
ciegas, igual que `blindControl`.

Por qué. El device conoce con certeza las ventas que tiene en cola, y hasta
podría sumarles el último resumen que alcanzó a leer. Pero entre esas dos cosas
hay un hueco real: las ventas que **sí** llegaron al servidor después de esa
última lectura no están ni en el resumen viejo ni en la cola. Un total corto en
una pantalla de arqueo no es un dato incompleto, es un dato **engañoso** — hace
que un faltante parezca cuadrar. Y el arqueo es plata.

Lo que sí se muestra, deliberadamente fuera del marco del arqueo: *"Este
dispositivo tiene N ventas sin enviar por X. No es el total del turno."* Es
información sobre la cola (y sobre cuánta plata está en riesgo si el aparato se
pierde), no sobre el turno.

El monto contado se registra igual y **el arqueo lo calcula el servidor cuando
el cierre sincroniza**, exactamente como si hubiera sido online. Lo único
degradado es lo que se ve mientras tanto. Tampoco se imprime el ticket de
cierre sin conexión: lista montos del turno que no existen; cuando sincroniza,
el reporte sale del panel.

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
  error — tocá para revisar"* → abre `SyncQueueDialog`, que ahora lista las
  operaciones ARRIBA de las ventas, con su etiqueta congelada al encolar
  ("Cerrar caja — 1.250.000 Gs"), reintentar y descartar.
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
