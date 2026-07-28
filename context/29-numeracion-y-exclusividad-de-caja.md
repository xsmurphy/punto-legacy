# 29 — Exclusividad de caja + numeración fiscal correcta

> Diseño cerrado con el owner 2026-07-28, a partir de una verificación
> directa contra producción (no teoría): hoy hay **4 dispositivos POS
> activos sobre la misma caja** (`019ead57-13f7-7c68-907f-2497d8f6e96c`) y
> un bloque de 100 números vivo (801–900) en otra. Esto es un P0 fiscal
> latente, no un caso hipotético.

---

## 1. El invariante que manda

Por punto de expedición (timbrado + caja):

1. **No puede haber números de factura duplicados.**
2. **El orden de los números tiene que coincidir con el orden de las
   fechas de emisión.** Si la 201 se emitió el 02/01, la 102 no puede
   emitirse después.

Violarlo es multa económica **por cada factura**, no una multa única. Todo
lo que sigue en este documento existe para sostener estos dos puntos, nada
más.

---

## 2. Estado actual verificado (2026-07-28)

- `numbering_lease` (mig 54): `leaseId, companyId, outletId, registerId,
  invoiceNo, leasedAt, consumedAt, expiresAt`. **No tiene `deviceId`** — una
  fila por número, sin dueño.
- `api/v1/numbering/lease.php`: al pedir un bloque, busca el lease activo
  sin consumir de esa caja (`registerId`) y **se lo devuelve a quien lo
  pida**, sin mirar qué dispositivo pregunta. Dos dispositivos de la misma
  caja piden número → reciben **el mismo bloque** → duplicado garantizado
  en cuanto ambos consuman offline.
- Bloques de hasta 200 números (default 100, `count` en el body), **TTL de
  24 horas corridas** desde que se emite (`+24 hours`) — cruza la
  medianoche sin relación con la fecha de emisión.
- La asignación de un bloque *nuevo* SÍ está serializada con
  `pg_advisory_xact_lock(hashtext(registerId))` (fix de un P0 anterior:
  carrera entre dos `INSERT` con el mismo `MAX(invoiceNo)+1`). Eso evita que
  dos requests concurrentes generen el mismo número al **crear** el bloque,
  pero no impide que ese mismo bloque ya existente se **entregue** dos
  veces a dos tenedores distintos.
- `DeviceAuth::pairOrReuse` deduplica por `(companyId, registerId,
  browserLocalId)`: evita que el *mismo navegador* re-parée un device
  nuevo cada vez. **No** impide que un segundo navegador/tablet se paree
  contra la misma caja — ese es justamente el caso de los 4 dispositivos
  en producción.
- Consumo: `api/v1/offline-sync.php` marca `consumedAt = NOW()` por
  `(invoiceNo, registerId, companyId)` al sincronizar una venta offline —
  no valida quién es el dueño del lease, cualquier dispositivo con acceso a
  la caja puede consumir cualquier número de ese registro.

Conclusión: el diseño actual previene la carrera de **creación** de bloque,
pero no tiene noción de **tenencia** — la pieza que falta para sostener el
invariante del §1 cuando hay más de un dispositivo por caja.

---

## 3. Decisión: exclusividad de caja atada al dispositivo

**Una caja tiene UN tenedor (`deviceId`) a la vez.** El lease deja de ser
"un bloque de números de esta caja" y pasa a ser "un bloque de números que
ESTE dispositivo tiene reservado en esta caja, mientras la tiene tomada".

### 3.1 Por qué NO "el último pisa al anterior"

Se evaluó y se descarta explícitamente. Dos fallas independientes, cada
una suficiente para descartarlo:

- **Duplicado por desconexión silenciosa.** Si el tenedor anterior (A)
  quedó offline justo cuando B le pisa la caja, A no se entera de que
  perdió la tenencia. A sigue emitiendo con su bloque local — que ya nadie
  invalidó del lado de A — y aparecen dos facturas con el mismo número
  cuando ambos sincronicen.
- **Rompe fecha↔número aunque no duplique.** A tiene reservado 801–900 y
  va por la 810. B toma la caja, arranca en 901, emite la 905 hoy a las
  09:00. A, todavía con su bloque en la tablet, emite la 812 hoy a las
  15:00. Resultado: número menor (812) con fecha posterior a un número
  mayor (905) ya emitido — viola el invariante 2 del §1 sin que exista
  ningún número repetido.

**El que llega segundo recibe un rechazo explícito**, no la caja. La
respuesta dice qué dispositivo la tiene y hasta cuándo expira su tenencia,
para que el POS muestre un mensaje útil ("Esta caja la está usando la
tablet de la barra, libera a las 14:32") en vez de un error genérico.

---

## 4. Máquina de estados del lease de caja

Unidad de tenencia nueva: **lease de caja** (`register_lease`, separado del
lease de *números*, ver §5.1) — vive mientras un dispositivo tiene la caja
tomada, y es dueño de cero o más bloques de números emitidos durante esa
tenencia.

```
                    ┌──────────┐
        ┌──────────►│  LIBRE   │◄─────────────────────────────┐
        │           └────┬─────┘                              │
        │                │ dispositivo pide tomar la caja      │
        │                ▼                                    │
        │           ┌──────────┐   vencimiento (fecha outlet)   │
        │  libera ok │ TOMADA   ├───────────────────────────►  │ VENCIDA
        │◄───────────┤          │                              │ (números no
        │  (cierre   └────┬─────┘                              │  consumidos
        │  de caja / └────┼──────────────────────────────┐     │  se ANULAN)
        │  "liberar")     │ admin fuerza liberación        │    │
        │                 ▼                                │    │
        │           ┌──────────┐                            │    │
        └───────────┤ FORZADA  │◄───────────────────────────┘    │
                     └────┬─────┘                                │
                          │ números no consumidos del tenedor     │
                          │ anterior se ANULAN                    │
                          └───────────────────────────────────────┘
```

Transiciones y qué pasa en cada corte:

| Corte | Qué dispara | Efecto sobre el lease de caja | Efecto sobre los números |
|---|---|---|---|
| **Toma normal** | Dispositivo sin caja tomada llama a `lease.php` | LIBRE → TOMADA, `deviceId` = el que pide | — |
| **Segundo dispositivo pide la misma caja, TOMADA por otro** | Llamada concurrente | Sin cambio. Rechazo 409 con `{ holderDeviceId, expiresAt }` | — |
| **Liberación normal** | El propio tenedor cierra caja o toca "liberar caja" | TOMADA → LIBRE | Números no consumidos del bloque activo se **anulan** (ver §6) — no quedan disponibles para el próximo tenedor |
| **Vencimiento** | Cambia la fecha del outlet (§4.1) sin liberación explícita | TOMADA → VENCIDA → LIBRE | Idem: se anulan los no consumidos |
| **Liberación forzada** | Admin desde panel, tenedor no responde (offline, tablet perdida, etc.) | TOMADA → FORZADA → LIBRE | Se anulan los no consumidos del tenedor anterior. El tenedor anterior, si vuelve online, recibe rechazo si intenta emitir con ese lease |
| **Dispositivo cambia de caja** | El mismo device pide lease en OTRA caja mientras tiene una tomada | La caja anterior se libera igual que "liberación normal" (no queda tomada por un dispositivo que ya se fue) | Idem anulación |
| **Re-pareo del mismo navegador** | `DeviceAuth` reusa `deviceId` existente (mismo `browserLocalId`) | Es el MISMO `deviceId` → mantiene la tenencia si la tenía, no dispara ningún cambio de estado | Sin efecto — sigue siendo el mismo tenedor |

### 4.1 El lease muere con la FECHA del outlet, no a las 24h corridas

⚠ CORRECCIÓN (owner, 2026-07-28): la versión anterior de esta sección ataba
el vencimiento al "día fiscal". **Ese concepto no existe en Punto y no lo
maneja el sistema**: la jornada fiscal la define el contador del comercio,
no nosotros. Lo que Punto ofrece es el **control de caja OPCIONAL** — si el
comercio lo habilita, hay un reporte del total vendido entre apertura y
cierre, sin importar fecha ni hora; si no lo habilita, opera normal y no hay
registro de apertura/cierre.

Por eso el ancla del vencimiento **no puede ser** ni el día fiscal ni el
arqueo:

- **El arqueo no sirve como ancla**: es opcional (la mitad de los comercios
  no lo usa) y una sesión de caja puede cruzar varios días. Un lease atado a
  un arqueo abierto tres días seguidos arrastra números viejos a fechas
  nuevas — el problema que este documento existe para evitar.
- **El día fiscal no sirve como ancla**: no está modelado en el sistema y
  depende del contador de cada comercio.

`expiresAt` se calcula como **el final de la FECHA del outlet** (00:00–23:59
en su timezone) vigente al momento de la toma. La justificación no es
contable sino directa: el invariante que protegemos es *orden de números =
orden de fechas*, así que basta con que ningún bloque sobreviva a un cambio
de fecha. Si se toma la caja a las 23:00, el lease vence a la medianoche de
ESE día y no arrastra el bloque al siguiente.

Esto es independiente del control de caja: un comercio sin control de caja
igual tiene leases que vencen con la fecha, y uno con arqueo abierto que
cruza la medianoche pierde el lease pero NO el arqueo — son dos ciclos
distintos y no hay que acoplarlos.


### 4.2 Tope por vencimiento de timbrado (responsabilidad del motor)

Decisión del owner (2026-07-28): **impedir que un comercio facture con el
timbrado vencido es responsabilidad de nuestro motor de facturación**, no
del contador. Los cierres fiscales, en cambio, NO nos corresponden — ver
§4.1.

El timbrado ya está modelado: `register.data->>'registerInvoiceAuth'` y
`registerInvoiceAuthExpiration` (demoted a JSONB en la mig 26). De ahí salen
tres reglas:

1. **`expiresAt` del lease = `min(fin de la fecha del outlet, vencimiento
   del timbrado)`.** Un bloque reservado no puede sobrevivir al timbrado que
   lo ampara: si vence el jueves, el lease tomado el jueves muere el jueves,
   no a la medianoche por defecto.
2. **Sin timbrado vigente no se entregan números.** El chequeo va del lado
   del servidor, al tomar la caja y al pedir bloque — no en el cliente, que
   puede estar desactualizado u offline.
3. **El bloque offline lleva el tope adentro.** El device no puede emitir
   con números de un bloque cuyo timbrado ya venció, aunque no tenga red
   para enterarse: la fecha de corte viaja con el lease y el POS la respeta
   localmente.

⚠ **Bloqueante para activar esto**: hoy **ningún register de producción
tiene timbrado cargado** (verificado 2026-07-28: `registerInvoiceAuth` y
`registerInvoiceAuthExpiration` vacíos en todos). Un check estricto
bloquearía el 100% de la facturación el día que se active. Hace falta una
transición explícita — cargar timbrados primero, avisar durante N días, y
recién después endurecer — y decidir qué hace el motor ante un register
**sin** timbrado cargado (¿bloquea, avisa, o deja pasar?). Es distinto de
"timbrado vencido", que sí bloquea sin discusión.

**Aviso anticipado**: el panel debería avisar con antelación (p. ej. 30/15/7
días) que el timbrado de una caja está por vencer. Un bloqueo sorpresa un
lunes a la mañana es peor que el problema que evita.

**Convergencia con impresión**: los bloques `auth_number`, `auth_start_date`
y `auth_expiration` de las plantillas de ticket piden exactamente estos
datos y hoy no viajan al POS (flag abierto del catálogo de bloques,
2026-07-28). Exponer el timbrado en el bootstrap del POS resuelve las dos
cosas de una.

---

## 5. Superficie técnica

### 5.1 `numbering_lease` — columnas nuevas

- `"deviceId" UUID NOT NULL` — quién es el tenedor. Referencia a `device`.
  Migración de backfill obligatoria antes de poner el `NOT NULL` (ver §6).
- Índice único parcial: `UNIQUE ("registerId") WHERE "consumedAt" IS NULL
  AND status = 'active'` a nivel de **tenencia de caja**, no de número —
  ver la tabla nueva abajo. La tabla `numbering_lease` sigue siendo "un
  número, una fila"; la tenencia vive en una tabla separada para no
  mezclar dos conceptos (bloque de números vs. dueño de la caja).

### 5.2 Tabla nueva: `register_lease` (tenencia de caja)

```
registerLeaseId  UUID PK
companyId        UUID
outletId         UUID
registerId       UUID
deviceId         UUID          -- tenedor actual
status           TEXT          -- 'active' | 'released' | 'expired' | 'forced'
takenAt          TIMESTAMPTZ
expiresAt        TIMESTAMPTZ   -- fin de la fecha del outlet, no +24h
releasedAt       TIMESTAMPTZ
releasedBy       TEXT          -- 'device' | 'expiry' | 'admin:{contactId}'
```

`UNIQUE (registerId) WHERE status = 'active'` — esto ES el mecanismo que
garantiza un solo tenedor por caja a nivel de base de datos, no solo a
nivel de aplicación. `numbering_lease` gana `registerLeaseId UUID` (nullable
para las filas viejas, backfill en §6) que ata cada bloque de números a la
tenencia bajo la cual se emitió.

### 5.3 `api/v1/numbering/lease.php`

- Antes de servir o crear un bloque: `SELECT ... FROM register_lease WHERE
  registerId = ? AND status = 'active' FOR UPDATE` (dentro del mismo
  `pg_advisory_xact_lock` que ya existe).
  - Si no hay fila activa → crear `register_lease` con `deviceId` del
    request, `expiresAt` = fin de la fecha del outlet, y recién ahí emitir/servir el
    bloque de números.
  - Si hay fila activa y `deviceId` coincide → servir el bloque (comportamiento
    actual, sin cambios).
  - Si hay fila activa y `deviceId` NO coincide → **409**, body
    `{ holderDeviceId, holderDeviceName, expiresAt }`. No se emite número.
- El `apiAuthPosContext()` ya trae `deviceId` del JWT del realm `device`
  (mismo patrón que KDS/print pool) — no hace falta nada nuevo del lado de
  auth, solo pasarlo al servicio.

### 5.4 Consumo — `offline-sync.php` y el camino online

- `offline-sync.php`: al marcar `consumedAt`, validar que el
  `registerLeaseId` del número siga `status = 'active'` Y pertenezca al
  `deviceId` que sincroniza. Si el lease de caja fue liberado/forzado
  mientras el dispositivo estaba offline, el número quedó anulado en la
  transición (§6) — el sync de esa venta debe fallar con un error
  explícito y accionable, no silencioso (mismo criterio fail-closed que
  rollups financieros, ver `08-convenciones-criticas.md`).
- Camino online (venta sin números pre-reservados, si existe/se agrega): sin
  cambios de diseño acá — no consume de `numbering_lease`, es harina de otro
  costal (ver §7, punto 6).

### 5.5 Panel — nueva vista de cajas

- Página o sección (a ubicar en Ajustes o en el módulo de cajas existente,
  a definir con `context/14-ui-conventions.md`) que lista, por outlet: caja
  → tenedor actual (`deviceName`) → desde cuándo → vence cuándo → botón
  "Liberar caja" (acción de admin, requiere permiso — reusar catálogo de
  permisos existente, ver `PermissionCatalog.php`).
- "Liberar caja" desde el panel = liberación forzada (§4, fila FORZADA):
  invalida los números no consumidos del tenedor actual y deja la caja
  LIBRE. Confirmación explícita en el modal (Dialog, no Sheet) — es una
  acción irreversible sobre numeración fiscal.

### 5.6 POS — mensaje de caja tomada

- Al intentar operar sin lease de caja propio (409 del §5.3): pantalla
  bloqueante con el mensaje "Esta caja la está usando {holderDeviceName},
  disponible aprox. {expiresAt}" + reintentar. No debe permitir vender sin
  numeración válida bajo ninguna circunstancia — cae dentro del alcance
  online-only si se decide la opción B del punto 6 en §7, o bloquea del
  todo si se decide la opción A.

---

## 6. Migración de lo que ya está en producción

No puede quedar un estado intermedio donde dos dispositivos sigan
compartiendo números — el cutover tiene que ser atómico por caja.

1. **Backfill de `register_lease`**: por cada `registerId` con lease
   activo hoy (`consumedAt IS NULL AND expiresAt > NOW()`), crear una fila
   `register_lease` con `status='active'`, `deviceId` = **el último
   dispositivo que consumió un número de esa caja** (`MAX(leasedAt)` o
   `MAX(consumedAt)` en `numbering_lease`/`transaction`) como tenedor de
   hecho. Los demás dispositivos ya pareados a esa caja quedan sin
   tenencia — la primera vez que pidan lease reciben el 409 normal.
2. **Los bloques vivos sin dueño claro** (caja con 4 dispositivos activos,
   como la verificada hoy) se **anulan íntegramente** en el corte: no hay
   forma de saber cuál de los 4 es el "legítimo" retroactivamente, y
   dejarlos vivos perpetúa el riesgo de duplicado un día más. Se anota el
   hueco (§6.1) y se arranca de cero: el primer dispositivo que pida
   número después del cutover toma la caja limpia.
3. **`numbering_lease.registerLeaseId`**: nullable en la migración, backfill
   con el `registerLeaseId` recién creado para las filas activas; las
   filas ya consumidas (`consumedAt IS NOT NULL`) quedan `NULL` — no
   importan para el invariante, son historia.
4. **Orden de despliegue**: migración de schema (tabla + columna nullable)
   → script de backfill de tenencias → deploy de `lease.php` con la
   validación nueva. Nunca al revés — el endpoint nuevo sin backfill
   rechazaría a todo el mundo (`register_lease` vacía).

### 6.1 Números anulados — dónde queda el hueco

Los números liberados/vencidos/forzados **se anulan, nunca se reciclan**
(decisión cerrada — reasignar un bloque huérfano mañana reintroduce números
viejos con fecha nueva, exactamente el problema del §1.2). Tabla nueva
`numbering_void` (o columna `voidedAt/voidReason` en `numbering_lease`, más
simple ya que la fila ya existe): `invoiceNo, registerId, voidedAt,
voidReason ('released'|'expired'|'forced'), registerLeaseId`. Es el registro
que permite explicarle a la SET por qué hay un salto en la numeración de esa
caja — el hueco queda justificado y timestamped, no es un misterio.

---

## 7. Riesgos y preguntas abiertas

| Riesgo | Detalle | Mitigación / estado |
|---|---|---|
| **Reloj del dispositivo desincronizado** | `expiresAt` se calcula server-side (fin de la fecha del outlet, por su timezone), no con el reloj del device — un device con hora mal puesta no adelanta ni atrasa su propio vencimiento | Ya cubierto por diseño: toda fecha de expiración es del servidor, el device solo la muestra |
| **Dispositivo desaparece con la caja tomada** (se rompe, se pierde, batería muerta) | La caja queda TOMADA hasta el cambio de fecha del outlet o hasta que un admin la libere a mano | Liberación forzada (§3, §5.5) es la vía rápida; sin ella, el peor caso es esperar al corte del día — acotado, nunca "para siempre" |
| **Ventana de bloqueo máxima** | Sin intervención de admin, una caja puede quedar inutilizable el resto de la fecha si el dispositivo tenedor murió a media mañana | Aceptado como trade-off: es preferible a arriesgar un duplicado. El panel de cajas (§5.5) es la herramienta para no depender de "esperar a mañana" |
| **Doble tenencia por bug de red** (request de toma llega dos veces) | El `UNIQUE (registerId) WHERE status='active'` en BD es la garantía real, no la lógica de aplicación — un segundo INSERT concurrente falla en el constraint, no en una condición de carrera de lectura | Cubierto por diseño (mismo patrón que el advisory lock ya usado en creación de bloques) |
| **Venta cae offline justo cuando la caja se libera/vence** | El dispositivo sigue emitiendo localmente con un lease que el servidor ya invalidó | El número consumido offline con un `registerLeaseId` no-activo falla el sync explícitamente (§5.4) — la venta no se pierde (queda en cola local) pero requiere re-numeración manual antes de poder sincronizar. Es un caso a resolver con UX específica, no cubierto en detalle acá |

### Flag abierto — dispositivo sin caja tomada, ¿puede vender?

Sin decidir todavía (⚠ para el owner): cuando un dispositivo pierde/no
tiene la tenencia de una caja, dos caminos:

- **(A) No opera.** Simple, seguro, pero dos tablets de la misma caja no
  pueden cobrar en simultáneo nunca, ni siquiera cuando ambas tienen
  conectividad perfecta.
- **(B) Vende online**, con numeración asignada por el servidor **en el
  momento de emitir** (correlativa por construcción, sin bloque
  pre-reservado). Legal **solo si nadie tiene un bloque offline vivo
  reservado en esa caja** — un bloque offline vivo puede consumirse después
  con fecha posterior al número que el servidor ya asignó online, violando
  el invariante 2 del §1. Si se elige (B), la caja tiene que poder estar en
  un modo "solo online, sin bloques offline permitidos" — mecanismo no
  diseñado en este documento.

---

## 8. Fases

| Fase | Contenido | Depende de |
|---|---|---|
| **F0 — Schema** | Mig: `register_lease` + `numbering_lease.registerLeaseId` (nullable) + `numbering_lease.voidedAt/voidReason` (o tabla `numbering_void`) | — |
| **F1 — Backfill** | Script: crear `register_lease` activa por caja con lease vivo hoy (tenedor = último consumidor), anular bloques huérfanos de cajas con >1 dispositivo activo (§6) | F0 |
| **F2 — Endpoint** | `lease.php`: chequeo de tenencia, 409 con holder info, creación de `register_lease` al tomar caja, `expiresAt` = fin de la fecha del outlet | F0, F1 |
| **F3 — Consumo** | `offline-sync.php`: validar `registerLeaseId` activo + dueño antes de marcar `consumedAt`; anulación de no-consumidos en liberación/vencimiento/forzado | F2 |
| **F4 — Panel** | Vista de cajas: tenedor, vencimiento, "liberar caja" (forzado) con permiso dedicado | F2 |
| **F5 — POS** | Mensaje de caja tomada (409) + reintento; UX de venta offline que falla sync por lease invalidado | F2, F3 |
| **F6 — Decisión pendiente** | Resolver el flag del §7 (online-sin-tenencia) y diseñar su mecanismo si se elige la opción B | F2 |

---

## Nota

Falta agregar la fila de este documento (`29-numeracion-y-exclusividad-de-caja.md`)
a la tabla de docs de `CLAUDE.md` — no se hizo en esta sesión porque el
archivo está reservado por la sesión paralela de facturación electrónica
(`context/28-*`).
