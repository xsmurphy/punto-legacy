# Asistente IA en la caja — plan del módulo

> Estado: **plan sin implementar** (2026-08-30). D1 y D7 las cerró el owner.
> El resto son propuestas marcadas **[?]**.
>
> Tercera versión. Las dos anteriores inventaron un realm nuevo (`pos-operator`,
> después `pos-ai`) para un problema que ya estaba resuelto. El owner las cortó
> con la pregunta correcta: *"el panel tiene su realm y se conecta internamente a
> las tools compartidas con el MCP, ¿no podés hacer lo mismo con POS?"*. Sí. Eso
> es exactamente lo que hay que hacer.

## Por qué

El agente IA de Punto (`context/17`) existe, funciona y vive solo en el panel.
El pedido es que exista también en la caja — donde está la persona que atiende.

Las preguntas de la caja son otras que las del panel: *"¿tenemos stock de
esto?"*, *"¿cuánto debe este cliente?"*, *"¿cuánto llevo vendido hoy?"*.
Lecturas cortas, con respuesta inmediata, contra datos que el POS ya toca.

## D1 — CERRADA por el owner (2026-08-30): el POS usa `pos-app`, sin realm nuevo

**El panel no tiene un realm especial para el agente.** Usa `panel`, y las tools
leen `/v1/*` con esa misma credencial. La simetría honesta es que el POS use
`pos-app`: el chat de la caja manda al BFF con el Bearer del device y las tools
leen con esa credencial, igual que el panel con la suya.

Y está casi todo hecho. Los siete endpoints del recorte (D3), hoy:

| Endpoint | Realms que acepta hoy | Falta |
|---|---|---|
| `/v1/items` (`items.php:150`) | `['panel','pos-app','mcp']` | — |
| `/v1/contacts` (`contacts.php:31`) | `['panel','pos-app','mcp']` | — |
| `/v1/categories` (`categories.php:22`) | GET → `['panel','pos-app','mcp']` | — |
| `/v1/brands` (`brands.php:22`) | GET → `['panel','pos-app','mcp']` | — |
| `/v1/reports/transactions` (`transactions.php:24`) | GET → `['panel','pos-app','mcp']` | — |
| `/v1/reports/stock` (`stock.php:12`) | `['panel','mcp']` | **`pos-app`** |
| `/v1/reports/drawers` (`drawers.php:14`) | `['panel','mcp']` | **`pos-app`** |

**Cinco de siete ya aceptan el realm del POS.** El trabajo de backend de todo
este plan son **dos líneas**.

`transactions.php` ya está resuelto y con el razonamiento escrito: su docblock
(`transactions.php:15-19`) explica que para `pos-app` el `OUTLET_ID` sale de la
fila `device`, *"así el listado de transacciones del POS queda scopeado a la
sucursal de la caja"* — corrección de un bug del 2026-07-30 en que el POS pedía
con la cookie del operador y mostraba la sucursal del view-scope del panel.

## D2 — REABIERTA por el owner (2026-08-31): el asistente de la caja ESCRIBE

> *"Veo que el bot en /pos es solo lectura, sería bueno que se adapte a los
> permisos del usuario (cajero) conectado. De esta forma si necesita modificar
> algo de un producto no tiene que entrar al panel, solo se lo pide al bot."*

El read-only cae. Lo que lo reemplaza **no es "ahora también escribe"**: es que
la autorización deja de colgar de la credencial y pasa a colgar de la PERSONA.

**El invariante que gobierna esta fase, y que no se negocia:** el Bearer del
realm `pos-app` es del DISPOSITIVO. No expira y vive en el localStorage de una
tablet que comparten todos los turnos. **Ninguna escritura se autoriza con él
solo.** La persona se prueba con la `OperatorAssertion` que emite el unlock por
PIN (`api/lib/Auth/OperatorAssertion.php`), y cada acción se evalúa contra el rol
de esa persona (`OperatorContext::can`). Sin operador identificado no hay
escritura: 403, fail-closed, sin modo degradado.

Tres piezas lo sostienen:

1. **Un actor, no dos caminos.** `api/lib/Ai/AgentActor.php` responde "quién es y
   qué puede" una sola vez, y lo usan las DOS mitades de la operación
   (`/v1/ai/confirm` y `/v1/ai/execute`). El gate de entrada es `ai.agent.use`
   contra la credencial en `panel` y `pos.ai.use` contra el rol del operador en
   `pos-app`; el gate por acción (contactos, ítems, taxonomías) sale del mismo
   objeto. Que `confirm` se afloje sin que `execute` se entere deja de ser
   posible.
2. **El `confirmToken` se ata a quien lo pidió.** Se guardaba el `userId` y no se
   miraba nunca: en una tablet que desbloquean tres personas por turno, eso
   significaba que el lote registrado por el encargado lo podía ejecutar el mozo
   que tipeó su PIN después. Ahora `aiConfirmStoreConsume()` exige el mismo
   actor, y un intento ajeno no le quema el token a su dueño.
3. **`create_user` no se puede pedir desde la caja**, bloqueada por realm y
   explícitamente. Se creía que quedaba fuera sola porque exige
   `ai.agent.elevated` y `unlock-pin.php` filtra al prefijo `pos.` — **eso es
   falso**: ese filtro decide qué se cachea en la tablet, no qué evalúa el
   backend, y el rol real de un encargado sí tiene esa clave. Sin el bloqueo,
   "creá un usuario" desde el mostrador llegaba hasta `RoleEscalation`.

**El alcance de escritura no se amplió ni una acción**: es el que el agente ya
tenía (contactos, ítems básicos, categorías/marcas/etiquetas). Nada de ventas,
caja, sucursales ni permisos — eso sigue siendo del POS y del panel.

Lo que sigue vigente de la versión anterior de esta decisión es el análisis del
gate por método para las LECTURAS, que se conserva abajo porque no cambió.

### D2 (histórico) — Read-only por gate de método, no por invariante de embudo

Esto cambia de fundamento respecto de la versión anterior y **no hay que
maquillarlo**.

Cuando el plan proponía un realm hermano, el read-only lo garantizaba el embudo:
`api/bootstrap.php:153` corta 405 para cualquier verbo que no sea GET/HEAD. Con
`pos-app` **eso no aplica**, y no puede aplicar: `pos-app` es el realm con el que
el POS **escribe ventas**.

El read-only pasa a ser el patrón que esos endpoints ya usan — el allowlist
depende del método:

```php
// api/v1/categories.php:22 — el patrón vigente
$ctx = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app', 'mcp'] : ['panel']);
```

Es el patrón vigente del proyecto y es correcto, pero es **disciplina por
endpoint, no invariante de embudo**. Su costo, dicho sin vueltas: *un endpoint
nuevo que se olvide del gate por método deja escribir a la caja.* No falla en
tests; falla en producción. Es la misma clase de riesgo que `bootstrap.php:140-152`
describe para el MCP — solo que acá no tenemos el embudo para taparlo.

Mitigación disponible: extender el arnés de inventario de allowlists para que
verifique que **todo** endpoint que acepta `pos-app` restringe sus verbos de
escritura a `panel`. Eso convierte la disciplina en algo verificable, que es lo
más cerca del embudo que se puede estar sin un realm propio.

Y el criterio de alcance sigue en pie, porque la presión va a venir (*"que me
cargue el cliente mientras hablo con él"*): la respuesta correcta es un flujo del
POS con el asistente como atajo hacia él, no una escritura del agente. La caja ya
tiene sus escrituras, con su permiso, su confirmación y su comportamiento
offline.

---

## EL riesgo del plan: qué lee realmente el Bearer del device

El Bearer del device **no expira y no identifica a una persona**
(`frontend/lib/auth/device-token.ts:47`; el `userId` que resuelve es el del
contacto que pareó la caja, `api/bootstrap.php:225-227`, y el rol es el rol
`device` del tenant, `:229-239`). Abrir `/v1/reports/drawers` a `pos-app`
significa que el token guardado en esa tablet lee arqueos **con o sin alguien
desbloqueado**.

Lo verifiqué en el código. Tres hallazgos, y uno es peor de lo que se suponía.

### 1. El scope es por SUCURSAL, nunca por caja

`Roc::build()` es el que arma el filtro, y arma exactamente dos condiciones
(`api/lib/Reports/Roc.php:39-53`):

```php
$roc = " AND {$p}companyId = '" . $companyId . "'";
if (preg_match(self::UUID_RE, $outletId)) {
    $roc .= " AND {$p}outletId = '" . $outletId . "'";
}
```

**No hay filtro por `registerId`, en ningún lado.** `drawers.php:84` llama
`Roc::build(COMPANY_ID, OUTLET_ID)` y con eso lista movimientos
(`drawers.php:113-116`).

Consecuencia directa, y **corrige lo que decía la versión anterior de este
doc**: `get_transactions` y `get_drawers` no devuelven "las ventas del turno
propio". Devuelven **todo lo de la sucursal** — todas las cajas, todos los
cajeros. Para `drawers` el período por defecto son los últimos 7 días
(`drawers.php:108`).

Esto no es un bug: para `transactions` es el comportamiento deliberado y
documentado del POS (`transactions.php:15-19`). Pero significa que la promesa
"del turno propio" no se puede cumplir con estos endpoints tal como están, y el
plan no debe fingir que sí.

### 2. Ninguno de los tres GET chequea permisos

- `drawers.php:21` — `hasPermission('reports.drawers.view')` está **dentro de la
  rama POST**. El GET no chequea nada.
- `transactions.php:31` (POST) y `:81` (PUT) — las dos son de escritura. El GET
  no chequea nada.
- `stock.php` — **no tiene ni una llamada a `hasPermission`**.

O sea: opting-in de `pos-app` en `drawers` y `stock` le da lectura al rol
`device` **sin ninguna capa de permisos debajo**. No alcanza con decir "el rol
device no tiene `reports.*`": esos endpoints no miran `reports.*` en el GET.

### 3. Lo único que NO se puede ampliar desde la caja: el view-scope

`VIEW_OUTLET_ID` —el override que con `'all'` haría que `Roc::build` no filtre
por outlet y devuelva el consolidado de toda la empresa— está **restringido al
realm `panel`** de forma explícita (`api/bootstrap.php:284`, con el comentario
*"Restringido a realm 'panel': el POS no debería poder enviar este header"*).

Una request `pos-app` no puede ensanchar su scope más allá de su sucursal. Es la
única barrera que hoy funciona sola, y conviene saber que existe.

### D9 [?] — La respuesta: exigir `OperatorAssertion` en `drawers` (y en `transactions`)

Dado 1 y 2, abrir `drawers` a `pos-app` a secas es abrir los arqueos de la
sucursal a un token eterno sin dueño. La pieza que falta ya existe y es
justamente para esto: la `OperatorAssertion` identifica a la PERSONA
(`api/lib/Auth/OperatorAssertion.php:30-42`), y `OperatorContext::can()`
(`api/lib/Auth/OperatorContext.php:100`) resuelve un permiso contra el rol de esa
persona — **sin el filtro `pos.`**, que es solo lo que se le manda al front
(`unlock-pin.php:127-131`).

Propuesta, por endpoint:

- **`/v1/reports/drawers`** — exigir operador identificado **y**
  `reports.drawers.view` sobre su rol. Convierte "la tablet lee arqueos" en "la
  persona que acaba de tipear su PIN, y cuyo rol puede ver arqueos, lee arqueos".
  Es el mismo permiso que ya exige el POST (`drawers.php:21`), aplicado donde
  falta.
- **`/v1/reports/transactions`** — mismo gate con `reports.sales.view`. Menos
  urgente (el POS ya lo usa hoy sin operador, y romperlo sería una regresión),
  así que **hay que verificar qué call-sites existen antes de tocarlo**. Si el
  POS lo consume en pantallas que corren sin desbloqueo, el gate va solo para la
  ruta del agente.
- **`/v1/reports/stock`** y los otros cuatro — no hace falta. Precio, stock,
  categorías, marcas y contactos son lo que la caja ya muestra en la pantalla de
  venta; exigir operador ahí no protege nada que no esté ya expuesto.

Sin este gate, D3 recorta lo que el modelo puede pedir, pero no lo que el token
puede leer. **Es trabajo obligatorio de la fase 1, no un detalle.**

---

## D3 [?] — El recorte del catálogo

Sin cambios respecto de la versión anterior (aprobado salvo que el owner diga lo
contrario), con la corrección de scope del §riesgo: donde decía "del turno
propio", léase "de la sucursal de la caja".

### DENTRO — 7 tools

| Tool | Endpoint | Pregunta de mostrador que responde |
|---|---|---|
| `get_items` | `/v1/items` | "¿a cuánto está esto?", "¿qué presentaciones hay?" |
| `get_stock` | `/v1/reports/stock` | "¿nos queda?", "¿hay en la otra sucursal?" |
| `get_contacts` | `/v1/contacts` | "¿cuánto debe este cliente?", "¿qué datos tiene?" |
| `get_categories` | `/v1/categories` | ubicar un ítem cuando no se sabe el nombre exacto |
| `get_brands` | `/v1/brands` | ídem |
| `get_transactions` | `/v1/reports/transactions` | "¿cuánto se vendió hoy?", "¿esa venta se cobró?" |
| `get_drawers` | `/v1/reports/drawers` | "¿cómo viene la caja?", "¿cuánto hay en efectivo?" |

`get_settings` entra **solo si** el chat necesita moneda y decimales para
formatear. Si eso ya viene del bootstrap del POS, queda afuera: es superficie que
no hace falta abrir.

### AFUERA — y por qué

- **`get_report`** — **la exclusión más importante.** Es una meta-tool que
  despacha a ~20 endpoints por un mapa de nombres
  (`frontend/lib/agent/read-tools.ts:386-405`). Incluirla abre el catálogo entero
  con una sola entrada: `flujo_de_caja`, `cuentas_por_cobrar`,
  `cuentas_por_pagar`, `compras_y_gastos`, `staff_usuarios`, `inventario`. Queda
  afuera **completa**; las dos lecturas de reports que la caja sí necesita entran
  por sus tools propias.
- **`get_finance_accounts`, `get_finance_summary`, `get_finance_movements`,
  `get_finance_checks`** — tesorería del comercio. Nada de eso se responde de pie
  frente a un cliente. (Además hoy son `['panel','mcp']`: no darles `pos-app` es
  el límite duro.)
- **`get_sales_summary`** (`/v1/reports/summary_year`) — histórico anual, pregunta
  de escritorio.
- **`get_customer_evolution`** (`/v1/reports/dashboard`) — analítica de cartera.
- **`get_top_products`** — reporte de gestión. Es el primero que yo revisaría si
  el owner quiere ampliar: no expone plata y "¿qué se vende más?" sí es pregunta
  de mostrador. Afuera por defecto porque el criterio es abrir de a poco.
- **`get_users`** — roster de empleados.
- **`get_outlets`** — estructura del comercio; `get_stock` ya cubre el caso
  legítimo.
- **`get_tags`** — sin caso de uso en la caja.
- **`render_chart`** — ya lo excluye `buildReadOnlyFetchTools`
  (`app/api/mcp/route.ts:31`); en una tablet de caja un gráfico tampoco es la
  respuesta.

### Cómo se aplica el recorte — sin tocar el catálogo compartido

**`frontend/lib/agent/read-tools.ts` es el catálogo COMPARTIDO con el MCP y NO se
modifica.** El recorte se hace del lado del POS: una lista propia de ids
(`POS_TOOL_IDS`) que filtra lo que devuelve `buildReadOnlyFetchTools()`
(`read-tools.ts:548`), en un archivo del POS. Nada de editar el catálogo, nada de
agregarle un parámetro, nada de una segunda copia.

Dos razones: hay **otra sesión trabajando en todo lo del MCP** en paralelo, y
—independientemente de eso— un catálogo bifurcado se desincroniza en semanas y
las descripciones son la parte cara de mantener (`context/58` §Arquitectura).

---

## Decisiones restantes

### D4 [?] — Permiso propio `pos.ai.use`, solo para gatear el item de la UI

`ai.agent.use` **no puede llegar a la caja**: `unlock-pin.php:127-131` filtra los
permisos del operador al prefijo `pos.`, a propósito y con el motivo escrito
(`:122-126`) — el resto son permisos de panel que en la caja no gobiernan nada y
solo agrandarían lo que se cachea en una tablet compartida.

Propuesta: **`pos.ai.use`** en `PermissionCatalog.php`, grupo `POS` (junto a
`pos.sale.create` y compañía, `:36-56`), con `since` como `pos.space.override`.

Gatea el item del sidebar y la ruta del chat. **No gatea los datos** — eso son D3
y D9. Y permite habilitar el asistente en el mostrador sin abrir el del panel,
que hoy no se puede expresar.

### D5 [?] — Créditos: el tenant paga; el actor va en `meta`

Hallazgo que sigue en pie: **hoy el ledger no registra actor en ningún realm**.
El INSERT de `api/v1/ai/debit.php:86-90` escribe `companyId`, `delta`,
`balanceAfter`, `reason`, tokens y `meta`; y `meta` lleva solo `capability`,
`model`, `requestId` (`debit.php:59`). Ni en el panel se sabe qué usuario gastó
los créditos.

- **El deudor sigue siendo la company.** Mismo tenant, mismo servicio, mismo
  pool. Nada de un segundo balance para la caja.
- **El actor va en `meta`**: `{ realm, registerId, operatorId }`. Cambio de una
  línea en `debit.php:59`, sin migración (`meta` es jsonb). El `operatorId` sale
  de la `OperatorAssertion` — con D1 cerrado no hay sesión de turno de donde
  sacarlo, así que **si D9 no se implementa, en la caja el actor es la caja, no
  la persona**. Un motivo más para D9.
- Arregla de paso el hueco del panel, donde conviene guardar el `userId`.

Nota: `debit.php` es `apiAuthTenant(['panel'])` (`:17`). La ruta del agente en la
caja necesita que ese endpoint acepte `pos-app` **para POST**, que es
precisamente lo contrario del gate por método de D2. Es la única escritura que
este plan necesita, es del propio sistema (no del tenant) y hay que decidirla
aparte — no puede colarse como parte del opt-in de lecturas.

### D6 [?] — Offline: deshabilitado con motivo visible

El POS opera sin internet por diseño (`project_offline_scope`, `context/51`), y
el asistente necesita red por partida doble: el modelo es remoto y las tools son
fetches.

Propuesta: **el item queda en su lugar, disabled, con el motivo en el tooltip**.
Es la convención explícita del POS — el aviso va en el control de la acción, no
en una banda (`feedback_pos_alerts_on_the_action_not_banners`) — y no mueve nada
de lugar (`feedback_pos_stable_layout_no_shifts`). Señal: `useOnlineStatus()`
(`frontend/hooks/use-online-status.ts:17`), ya en uso en la caja.

Encolar queda descartado: una respuesta a *"¿tenés stock?"* que llega cuando
vuelve internet no sirve. Se encolan operaciones que deben ocurrir
(`context/51`), no preguntas.

**Segundo caso, si D9 entra:** en desbloqueo **offline** el PIN se valida local
contra el roster cacheado y no hay `operatorToken`
(`frontend/lib/pos/space-access.ts:52`, `lock-store.ts:138`). Las tools con gate
de operador fallarían. Mismo tratamiento visual, otro texto — *"Volvé a ingresar
tu PIN"*— porque lo que lo destraba es otra acción.

### D7 — CERRADA por el owner (2026-08-30): trigger en el sidebar del POS

- **Desktop**: primer item del **footer** del sidebar, **arriba de "Modo"**. El
  footer del POS es donde viven los controles de estado de la caja, no la
  navegación (`frontend/components/layout/pos-sidebar.tsx:277-281`, docblock del
  `SidebarFooter`); hoy son "Modo" (`:288-298`) y "Bloquear" (`:302-314`).
- **Mobile**: eso cae dentro del bottom drawer que abre el ícono **Módulos** — en
  mobile el sidebar ES un Sheet.
- Elegido explícitamente sobre las alternativas. El FAB del panel queda
  descartado para la caja: taparía el CTA de cobrar.

Consecuencia técnica ya aprendida y revertida: **el trigger y el chat se montan en
la misma superficie**. En mobile el sidebar se desmonta al tocar el item, así que
un chat montado dentro del sidebar se cerraría solo — mismo motivo por el que
`PosModeDialog` vive en el layout.

### D8 [?] — Componente de chat propio, lógica compartida

`AgentChatContent` (`frontend/components/agent/agent-chat-content.tsx:39`) está
escrito para el Sheet del FAB del panel — tanto que `/chat` **ya no lo reusa** y
tiene su propia implementación por layout (`app/(panel)/chat/page.tsx:43`).
Dentro del propio panel ese componente ya se bifurcó una vez.

La caja pide más diferencias: touch targets y tipografía de caja
(`project_pos_touch_keyboard_first`), hotkey de apertura y ESC sin robarle atajos
al POS (`hooks/use-hotkeys.ts`), autofocus al abrir, y sin selector de view-scope
(el POS no tiene override — ver §riesgo, punto 3).

Propuesta: componente propio en `components/pos/`, compartiendo **el hook de chat
y el catálogo**, no el JSX.

---

## Fases

Mucho más chicas que en las versiones anteriores: ya no hay fase de realm.

| Fase | Qué | Depende de |
|---|---|---|
| **F1** | Opt-in de `stock.php` y `drawers.php` + gate de operador en `drawers` (D9) + scope verificado | D3, D9 |
| **F2** | BFF `/api/pos/agent/chat` con Bearer del device, sin reenviar cookies | F1 |
| **F3** | Recorte del catálogo: `POS_TOOL_IDS` filtrando `buildReadOnlyFetchTools`, **sin tocar `read-tools.ts`** | F2, D3 |
| **F4** | UI: item del footer (D7), componente de chat (D8), gate del item, estados offline (D6) | F3, D7 |
| **F5** | Permiso `pos.ai.use` (D4) + actor en el ledger y `debit.php` para `pos-app` (D5) | F4 |
| **F6** | **Escritura con permisos del operador** (D2 reabierta): `AgentActor` + `confirm`/`execute` con realm `pos-app`, `confirmToken` atado al actor, `X-Operator-Token` de punta a punta, tools de escritura en el set del POS | F4 |

**F1 son dos líneas**, una por archivo:

```
api/v1/reports/stock.php:12    ['panel','mcp']  →  ['panel','pos-app','mcp']
api/v1/reports/drawers.php:14  ['panel','mcp']  →  ['panel','pos-app','mcp']
```

⚠️ **Riesgo de conflicto**: las dos líneas nombran hoy a `mcp`, y hay **otra
sesión trabajando en todo lo del MCP**. Diff mínimo de una línea por archivo, sin
reordenar ni reformatear nada alrededor.

F5 puede ir antes que todo lo demás: no depende de ninguna otra fase para
escribirse, solo para servir de algo.

### Qué queda NO verificado en cada fase

- **F1** — si el gate de operador en `transactions` rompe call-sites existentes
  del POS. Hay que enumerarlos antes de tocarlo (§D9). Y que `stock.php`, que no
  pasa por `Roc::build` de la misma forma, quede efectivamente scopeado a la
  sucursal del device — no lo verifiqué.
- **F2** — que el BFF no herede el patrón de `app/api/agent/chat/route.ts:36`,
  que reenvía el `authorization` entrante. Acá tiene que inyectar el Bearer del
  device, como `lib/api/pos-fetch.ts:49`, y **nunca** reenviar cookies.
- **F3** — que las descripciones del catálogo, escritas para un usuario de panel,
  funcionen para preguntas de mostrador. Solo se sabe probando con un modelo real
  (mismo pendiente que `context/58` M2).
- **F4** — el drawer mobile con el teclado virtual abierto. Es donde se rompen los
  chats en tablets y no se verifica sin dispositivo.
- **F5** — el consumo real de créditos por caja. No hay forma de estimarlo antes
  de que alguien lo use.
- **F6** — el flujo completo contra un modelo real: que entienda que tiene que
  llamar `register_action` una sola vez por pedido y esperar la confirmación
  (en el panel costó tres iteraciones de prompt). Tampoco se verificó el 403 de
  operador ausente contra la API corriendo — el arnés de permisos cubre que la
  clave esté gateada, no el round-trip. Y el ledger sigue sin registrar al
  operador que gastó los créditos: eso es F5, no entró acá.

---

## Arquitecturas RECHAZADAS

Leer esto **antes** de proponer algo en este módulo.

### 1. Montar el componente del panel en `/pos` con la credencial del panel

Es lo que se hizo el 2026-08-30 y se revirtió en `80a21be2`. Dos fallas
independientes:

- **El gate leía el realm equivocado.** `usePermission()` sale de `useBootstrap()`
  (`frontend/hooks/use-permissions.ts:10-13`), el bootstrap del realm PANEL. En
  una caja pareada el rol es el rol `device` (`api/bootstrap.php:229-239`), que no
  tiene `ai.agent.use` → el item no aparecía. En un browser con sesión de panel,
  sí. **El botón existía o no según cómo se hubiera abierto la caja**: no es un
  error, es una inconsistencia silenciosa que no se detecta en desarrollo, se
  detecta en el local del cliente.
- **El chat habría fallado igual.** `frontend/app/api/agent/chat/route.ts:36`
  reenvía el `authorization` de la request —el Bearer del PANEL— y el backend es
  `apiAuthTenant(['panel'])` (`api/v1/ai/execute.php:19`). Una caja pareada no
  tiene ese Bearer.

**No hay versión arreglada de esto.** "Que el POS también mande la cookie del
panel" es literalmente el incidente de `feedback_pos_token_only_no_realms`, tres
veces (2026-07-19, 08-24, 08-25).

### 2. Realm propio para el asistente de la caja (`pos-operator`, `pos-ai`)

Las dos versiones anteriores de este doc. `pos-operator` re-modelaba la identidad
del operador; `pos-ai` emitía en el unlock una sesión read-only de vida corta,
copiando el patrón de las keys MCP.

**Por qué se rechazan:** eran **superficie nueva para un problema ya resuelto**.
El panel no necesitó un realm para su agente —usa `panel` y las tools leen con
esa credencial— y el POS tampoco lo necesita: cinco de los siete endpoints del
recorte ya aceptan `pos-app`. Inventar un realm contradice la regla del propio
proyecto de no agregar mecanismos cuando el que hay alcanza, y en el caso de
`pos-ai` costaba además refactorizar el embudo y mantener dos allowlists en
paralelo que se iban a desincronizar.

Lo que sí hay que reconocer, porque es el costo real de haberlas rechazado: con
un realm propio, el read-only era un **invariante de embudo**; con `pos-app` es
**disciplina por endpoint** (D2). Se cambió una garantía por simplicidad, a
sabiendas.

`pos-operator` sigue siendo la respuesta correcta el día que haga falta una
sesión de operador de verdad — pero eso es el rewrite de `context/21`
(`OperatorAssertion.php:53-57`), y no lo dispara un chat.

### 3. Que el unlock por PIN emita una sesión de realm `panel`

Atajo tentador: el PIN ya identifica a la persona.

Es una **escalada de privilegios**, no una integración. El PIN es de 4 dígitos y
`pinhash` es un SHA-256 **sin sal** (`api/v1/unlock-pin.php:47`) — 10.000
combinaciones, documentado como tal en `api/v1/bootstrap.php:173`. Ese hash se
cachea a propósito en el localStorage de una tablet compartida para permitir el
desbloqueo offline. Quien tenga la tablet enumera el espacio en segundos.

El PIN es aceptable **porque no abre nada por sí solo**. Esa propiedad es lo que
lo mantiene barato, y es lo que este atajo destruye. Es también el techo contra
el que se mide cualquier ampliación de D3.

### 4. Duplicar o editar el catálogo de tools para el POS

`frontend/lib/agent/read-tools.ts` es **compartido con el MCP y no se modifica**.
El recorte sale de una lista de ids del lado del POS sobre lo que devuelve
`buildReadOnlyFetchTools` (`read-tools.ts:548`).

Dos listas escritas por separado se desincronizan en semanas, y las descripciones
son la parte cara de mantener — *"Las descripciones de las tools SON la UX"*
(`context/58` §Arquitectura). Un catálogo POS escrito a mano tendría, además,
descripciones sin probar contra un modelo. Y hay otra sesión trabajando sobre ese
archivo.

---

## Riesgos

- **El gate por método es disciplina, no invariante** (D2). Un endpoint nuevo que
  acepte `pos-app` sin restringir sus escrituras a `panel` le da escritura a la
  caja. Mitigación: arnés de inventario de allowlists.
- **El Bearer del device es eterno y sin dueño.** Sin D9, abrir `drawers` expone
  los arqueos de la sucursal a cualquiera que tenga la tablet, haya o no alguien
  desbloqueado — y esos GET no chequean permisos (§riesgo, punto 2).
- **Los reports no scopean por caja**, solo por sucursal (`Roc.php:39-53`). Toda
  promesa de "lo tuyo" en la UI del chat sería falsa; el copy tiene que decir "de
  esta sucursal".
- **Conflicto con la sesión del MCP** en las dos líneas de F1, que hoy nombran a
  `mcp`. Diff mínimo, sin reformateos.
- **`read-tools.ts` es compartido**: tocarlo rompe al MCP y pisa a la otra
  sesión.
- **Inyección por datos**, igual que en `context/58`: nombres de contacto y notas
  de ítem los escriben terceros y llegan al modelo como contexto. Se devuelven
  etiquetados como datos, nunca interpolados en descripciones de tools.
- **Créditos sin dueño visible** hasta que exista F5.

## Relacionados

- `context/17` — agente IA propio: alcance, OpenRouter, créditos.
- `context/58-mcp-server.md` — el catálogo compartido y el patrón de opt-in.
  **Otra sesión lo está trabajando; no tocar.**
- `context/21-auth-rewrite.md` — donde vive la sesión de operador "de verdad".
- `context/51-configuracion-offline-de-la-caja.md` — qué se encola y qué no.
- `context/08-convenciones-criticas.md` §60 — un cliente HTTP = un realm.

---

## Lo que necesita OK del owner para arrancar

**Bloqueante:**

1. **D9 — el gate de operador en `drawers`.** Es la única decisión que cambia el
   trabajo de F1, y nace de un hallazgo que no estaba sobre la mesa: esos GET no
   chequean permisos y el scope es por sucursal, así que el opt-in a secas expone
   los arqueos de la sucursal al token eterno de la tablet. Con gate, el asistente
   arranca más acotado pero honesto.

**Confirmar, no bloquea:**

2. **D3 — las 7 tools**, con la corrección de que `get_transactions` y
   `get_drawers` devuelven **la sucursal**, no el turno. Si eso no le sirve, la
   respuesta no es filtrar en el front: es agregar el filtro por `registerId`, que
   es trabajo nuevo y hay que decidirlo ahora.
3. **D5 — que `debit.php` acepte `pos-app` para POST**, que es la única escritura
   que este plan necesita y va contra el gate por método de D2.

El resto (D4 permiso, D6 offline, D8 componente propio) son propuestas con su
defensa arriba. D1, D2 y D7 ya están cerradas.
