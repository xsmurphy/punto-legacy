# Asistente IA en la caja — plan del módulo

> Estado: **plan sin implementar** (2026-08-30). D7 (UI) la cerró el owner. D1
> quedó resuelta por el camino C —reusar la maquinaria de sesiones read-only que
> el MCP ya puso en producción esta semana— y D2 queda cerrada por construcción.
> **Lo que necesita OK es D3: el recorte del catálogo.** El resto son propuestas
> marcadas **[?]**.
>
> Reescrito tras la objeción del owner a la primera versión: *"ya tenemos MCP
> creado, ya lo pude conectar con Claude, ¿no podemos hacer una versión de
> nuestro propio asistente en el POS?"*. Tenía razón: el plan anterior inventaba
> un modelo de identidad nuevo para un problema que ya estaba resuelto.

## Por qué

El agente IA de Punto (`context/17`) existe, funciona y vive solo en el panel.
El pedido es que exista también en la caja — donde está la persona que atiende.

Las preguntas de la caja son otras que las del panel: *"¿tenemos stock de
esto?"*, *"¿cuánto debe este cliente?"*, *"¿cuánto llevo vendido hoy?"*.
Lecturas cortas, con respuesta inmediata, contra datos que el POS ya toca.

Lo que bloqueaba no era el modelo ni las tools: era que **la caja no tiene la
credencial con la que el agente sabe hablar**. Y esa credencial ya existe —
solo que se construyó para otra cosa.

## El dato que desbloquea todo

**Una API key del MCP es una `auth_session` con realm `mcp`.**

```php
// api/lib/Auth/McpKeyService.php:76
$token = \authSessionCreate('mcp', [
    'companyId' => $ctx['companyId'],
    'userId'    => $ctx['userId'],
    'outletId'  => $ctx['outletId'],
    'roleId'    => $ctx['roleId'],
    'module'    => 'mcp',
    'expiresAt' => $expiresAt,
    'meta'      => ['name' => $name],
]);
```

No es un mecanismo paralelo: es la misma maquinaria de sesiones de siempre, con
`realm` como columna (`varchar(16)` sin CHECK, sin migración). Y alrededor de
ella ya está en producción, desde el 2026-08-30, todo lo que este plan
necesitaba construir:

| Pieza | Dónde | Qué da |
|---|---|---|
| Emisión de sesión read-only | `api/includes/auth_session.php:32-67` | acepta `expiresAt`, `registerId`, `deviceId`, `meta` arbitrarios |
| Read-only **forzado en el embudo** | `api/bootstrap.php:153` | verbo ≠ GET/HEAD → 405, sin que el endpoint opine |
| Opt-in por endpoint | 13 archivos, ej. `api/v1/items.php:150` | `apiAuthTenant(['panel','pos-app','mcp'])` |
| Rate limit por sesión | `api/bootstrap.php:180-193` | dos ventanas, cuenta por `AUTHED_SESSION_ID` |
| Auditoría de lecturas | `api/bootstrap.php:316-318` | el realm read-only audita GETs, no solo mutaciones |
| Revocación | `auth_session.php:304-338` | por sessionId, por token, por device |
| Catálogo de tools sin transporte | `frontend/lib/agent/read-tools.ts:548` | `buildReadOnlyFetchTools()`, ya consumido por `app/api/mcp/route.ts:120` |

La pregunta del owner —*"¿por qué no se puede tener el mismo motor que se usa en
el panel, también en el POS?"*— tiene respuesta: **se puede, y el motor ya está
enchufado a una credencial que no es la del panel.** Falta emitirle una a la
caja.

---

# Arquitectura elegida — C: sesión de turno, read-only, emitida en el unlock

Al desbloquear con PIN, `api/v1/unlock-pin.php` ya verifica a la persona contra
la BD (`unlock-pin.php:47`) y emite la `OperatorAssertion`
(`unlock-pin.php:139`). Ahí mismo, y solo ahí, emite además **una sesión
read-only de vida corta atada al turno**, con el mismo `authSessionCreate` que
emite una key MCP.

```
unlock con PIN (server-side, verifica contra la BD)
  ├─→ OperatorAssertion          → QUIÉN opera (16h, no es sesión)
  └─→ auth_session realm 'pos-ai' → credencial READ-ONLY del turno
         userId/roleId = el operador · outletId + registerId = esta caja
         expiresAt = fin de turno · meta = { operatorId, registerId }
                    │
                    ▼
      frontend/app/api/pos/agent/*   ← BFF del POS, manda ESTE Bearer
                    │                   (no el del panel, no el eterno del device)
                    ├─→ OpenRouter                    (mismo motor que el panel)
                    └─→ api/ /v1/*  apiAuthTenant([...,'pos-ai'])
                            read-only forzado en el embudo → 405
```

El chat usa **la misma UI, el mismo `buildReadOnlyFetchTools`, los mismos
endpoints**. Lo único nuevo es de dónde sale el Bearer.

### Por qué esto disuelve lo que bloqueaba

- **No hay realm de identidad nuevo.** La primera versión de este doc proponía
  `pos-operator`, que era media sesión de operador adelantada fuera de
  `context/21`. C no re-modela la identidad: la sesión hereda
  `userId`/`roleId` del operador igual que una key MCP hereda los de quien la
  emitió.
- **No se toca el Bearer eterno del device.** No hace falta abrir `pos-app` a
  `/v1/reports/*`. La credencial nace con el turno y muere con él.
- **No se toca el mandato POS/panel.** Ninguna cookie, ningún realm `panel` en
  la caja, ningún endpoint del POS multi-realm. Es una credencial de tenant
  read-only más, del mismo tipo que la que el owner ya conectó a Claude.
- **El read-only no depende de disciplina**, es el guard del embudo que ya
  protege a `mcp` (`bootstrap.php:140-153`).
- **El filtro `pos.` deja de ser un problema.** Los permisos que llegan a la
  caja están recortados a `pos.*` (`unlock-pin.php:127-131`) — pero con C el
  alcance de los DATOS no lo deciden los permisos del operador, lo decide qué
  endpoints optaron al realm. El permiso solo gatea el ITEM de la UI (D4).

## D1 — El realm: hermano (`pos-ai`), no `mcp` reusado

Evaluados los dos, como pidió el brief. Reusar `mcp` literalmente sale gratis
—los 13 endpoints ya lo aceptan, el guard ya existe— pero **hay un argumento
que lo descarta, y no es de prolijidad**.

### El argumento decisivo: el recorte del catálogo tiene que ser del backend

El recorte de D3 se aplica en el front: qué tools se le arman al modelo. Si la
sesión del turno tuviera realm `mcp`, **el backend no podría distinguirla de una
API key**, y los 13 endpoints que aceptan `mcp` incluyen
`/v1/finance/movements`, `/v1/finance/checks` y `/v1/finance/summary`.

Ese token vive en el browser de una tablet compartida. Quien lo saque de ahí
—o cualquiera que abra las devtools— lee la tesorería del comercio con un `curl`,
sin pasar por el chat ni por el catálogo recortado. **El recorte sería
cosmético.**

Con un realm hermano, el opt-in de endpoints **ES** el recorte: un endpoint que
no lleva `'pos-ai'` en su allowlist no es alcanzable desde la caja, venga la
request del chat o de donde sea. Eso lo mueve al embudo, que es el criterio del
proyecto (`bootstrap.php:140-152`: *"seguro por construcción, no por
disciplina"*).

### Tres colisiones más, todas verificadas

Aunque el argumento de arriba no existiera, reusar `mcp` rompe tres cosas:

1. **La UI de keys.** `McpKeyService::listForCompany()` filtra
   `WHERE realm = 'mcp'` (`McpKeyService.php:100-102`). Cada turno de cada cajero
   aparecería en `/settings/mcp-keys` como una integración revocable. Con 4
   cajeros y 2 turnos, esa lista es basura en una semana.
2. **La auditoría.** `bootstrap.php:317` audita **todos los GET** del realm
   `mcp`, y es correcto para un cliente de análisis. Multiplicado por cada
   pregunta de cada cajero, inunda `tenant_audit` y entierra justo lo que esa
   regla existe para hacer visible.
3. **El rate limit.** 60/min y 5000/día por key (`bootstrap.php:184-187`) están
   calibrados para un modelo haciendo análisis. Por sesión de turno significan
   otra cosa: cada turno estrena su propio presupuesto diario.

### El costo, dicho

Un realm hermano obliga a: (a) refactorizar los tres `$realm === 'mcp'` del
embudo — el guard read-only pasa a un conjunto `READ_ONLY_REALMS`, la auditoría
y el rate limit quedan por realm; y (b) agregar `'pos-ai'` a la allowlist de los
endpoints del recorte, que son **menos** que los 13 de `mcp`, no más.

Riesgo real a anotar: un endpoint futuro que agregue `'mcp'` y se olvide de
`'pos-ai'` (o al revés). Se mitiga con el mismo arnés de inventario de
allowlists que ya existe (`mcp_realm_test`), no con memoria.

## D2 — Read-only: CERRADA por construcción

No es una decisión de alcance que alguien tenga que respetar: el embudo corta
405 ante cualquier verbo que no sea GET/HEAD antes de que el endpoint se entere
(`bootstrap.php:153`). Para que el asistente de la caja escribiera habría que
sacarlo de la familia de realms read-only, que es un cambio visible y
deliberado, no un olvido.

Vale igual dejar dicho el criterio, porque la presión va a venir: *"que me
cargue el cliente mientras hablo con él"*. La respuesta correcta es un flujo del
POS con el asistente como atajo hacia él — no una escritura del agente. La caja
ya tiene sus escrituras, con su permiso, su confirmación y su comportamiento
offline.

---

## D3 [?] — EL recorte del catálogo. Esta es la decisión

Es lo único que de verdad queda por decidir, y ahora pesa el doble: con C, **la
lista de tools y la lista de endpoints con opt-in son la misma decisión**. Lo
que entre acá es lo que la caja puede leer, por el chat o por fuera de él.

El catálogo completo son 20 tools (`read-tools.ts`). Propuesta para la caja:

### DENTRO — 7 tools

| Tool | Endpoint que opta a `pos-ai` | Pregunta de mostrador que responde |
|---|---|---|
| `get_items` | `/v1/items` | "¿a cuánto está esto?", "¿qué presentaciones hay?" |
| `get_stock` | `/v1/reports/stock` | "¿nos queda?", "¿hay en la otra sucursal?" |
| `get_contacts` | `/v1/contacts` | "¿cuánto debe este cliente?", "¿qué datos tiene?" |
| `get_categories` | `/v1/categories` | ubicar un ítem cuando el cajero no sabe el nombre exacto |
| `get_brands` | `/v1/brands` | ídem |
| `get_transactions` | `/v1/reports/transactions` | "¿cuánto vendí hoy?", "¿esa venta se cobró?" |
| `get_drawers` | `/v1/reports/drawers` | "¿cómo viene la caja?", "¿cuánto hay en efectivo?" |

`get_settings` (`/v1/settings`) entra **solo si** el chat necesita moneda y
decimales para formatear. Si eso ya viene del bootstrap del POS, queda afuera:
es superficie que no hace falta abrir.

### AFUERA — y por qué, tool por tool

- **`get_report`** — **la exclusión más importante.** Es una meta-tool que
  despacha a ~20 endpoints por un mapa de nombres
  (`read-tools.ts:386-405`). Incluirla es abrir el catálogo entero con una sola
  entrada: `flujo_de_caja`, `cuentas_por_cobrar`, `cuentas_por_pagar`,
  `compras_y_gastos`, `staff_usuarios`, `inventario`. Queda afuera **completa**;
  las dos lecturas de reports que la caja sí necesita entran por sus tools
  propias (`get_transactions`, `get_drawers`).
- **`get_finance_accounts`, `get_finance_summary`, `get_finance_movements`,
  `get_finance_checks`** — tesorería del comercio: cuentas, saldos, cheques.
  Nada de eso se responde de pie frente a un cliente.
- **`get_sales_summary`** (`/v1/reports/summary_year`) — histórico anual. Es una
  pregunta de escritorio; para eso está el panel.
- **`get_customer_evolution`** (`/v1/reports/dashboard`) — analítica de
  cartera.
- **`get_top_products`** — reporte de gestión. Discutible, y es el primero que
  yo revisaría si el owner quiere ampliar: no expone plata, y "¿qué se vende
  más?" sí es pregunta de mostrador. Lo dejo afuera por defecto porque el
  criterio es abrir de a poco.
- **`get_users`** (`/v1/users`) — roster de empleados. Un cajero no necesita
  leer los datos de sus compañeros.
- **`get_outlets`** — otras sucursales. `get_stock` ya cubre el caso legítimo
  ("¿hay en la otra sucursal?") sin exponer la estructura del comercio.
- **`get_tags`** — sin caso de uso en la caja.
- **`render_chart`** — ya lo excluye `buildReadOnlyFetchTools` por ser de
  presentación (`app/api/mcp/route.ts:31`). En una tablet de caja, además, un
  gráfico no es la respuesta.

### El gap que este recorte NO cierra, y hay que verificar

`get_transactions` y `get_drawers` dicen "del turno propio", pero **está sin
verificar que `/v1/reports/transactions` y `/v1/reports/drawers` filtren por el
`registerId` de la sesión**. Si no lo hacen, un cajero lee las ventas de toda la
empresa, que es justo lo que el recorte quiere evitar. La sesión de turno lleva
`outletId` y `registerId` (`authSessionCreate` los acepta,
`auth_session.php:46`), así que el dato está — falta confirmar que el endpoint
lo honre, y si no, hacerlo. Es trabajo de M1, no un detalle.

---

## Decisiones restantes

### D4 [?] — Permiso propio `pos.ai.use`, solo para gatear el item de la UI

`ai.agent.use` **no puede llegar a la caja**: `unlock-pin.php:127-131` filtra los
permisos del operador al prefijo `pos.`, a propósito y con el motivo escrito
(`unlock-pin.php:122-126`) — el resto son permisos de panel que en la caja no
gobiernan nada y solo agrandarían lo que se cachea en una tablet compartida.

Propuesta: **`pos.ai.use`** en `PermissionCatalog.php`, grupo `POS` (junto a
`pos.sale.create` y compañía, `:36-56`), con `since` como `pos.space.override`.

Alcance del permiso, ahora que C existe: gatea **el item del sidebar y la
emisión de la sesión de turno**. No gatea los datos — eso es D3. Y permite
habilitar el asistente en el mostrador sin abrir el del panel, que hoy no se
puede expresar.

### D5 [?] — Créditos: el tenant paga; el actor va en `meta`

Hallazgo que sigue en pie: **hoy el ledger no registra actor en ningún realm**.
El INSERT de `api/v1/ai/debit.php:86-90` escribe `companyId`, `delta`,
`balanceAfter`, `reason`, tokens y `meta`; y `meta` lleva solo `capability`,
`model`, `requestId` (`debit.php:59`). Ni en el panel se sabe qué usuario gastó
los créditos.

- **El deudor sigue siendo la company.** Mismo tenant, mismo servicio, mismo
  pool. Nada de un segundo balance para la caja.
- **Con C el actor natural es la sesión de turno**: ya lleva `userId` del
  operador y `registerId` de la caja, así que `{ realm, sessionId, operatorId,
  registerId }` en `meta` sale sin resolver nada nuevo. Cambio de una línea en
  `debit.php:59`, sin migración (`meta` es jsonb).
- Arregla de paso el hueco del panel, donde conviene guardar el `userId`.

Sin esto, "el asistente de la caja me gasta los créditos" es un reclamo que
nadie va a poder investigar.

### D6 [?] — Offline: deshabilitado con motivo visible

El POS opera sin internet por diseño (`project_offline_scope`, `context/51`),
y el asistente necesita red por partida doble: el modelo es remoto y las tools
son fetches.

Propuesta: **el item queda en su lugar, disabled, con el motivo en el tooltip**.
Es la convención explícita del POS — el aviso va en el control de la acción, no
en una banda (`feedback_pos_alerts_on_the_action_not_banners`) — y no mueve
nada de lugar (`feedback_pos_stable_layout_no_shifts`). Señal:
`useOnlineStatus()` (`frontend/hooks/use-online-status.ts:17`), ya en uso en la
caja.

Encolar queda descartado: una respuesta a *"¿tenés stock?"* que llega cuando
vuelve internet no sirve. Se encolan operaciones que deben ocurrir
(`context/51`), no preguntas.

**Segundo caso, motivo distinto:** en desbloqueo **offline** el PIN se valida
local contra el roster cacheado y no hay sesión emitida
(`frontend/lib/pos/space-access.ts:52`, `lock-store.ts:138`). El operador tiene
identidad local pero no credencial. Mismo tratamiento visual, otro texto —
*"Volvé a ingresar tu PIN para usar el asistente"*— porque lo que lo destraba es
otra acción.

### D7 — CERRADA por el owner (2026-08-30): trigger en el sidebar del POS

- **Desktop**: primer item del **footer** del sidebar, **arriba de "Modo"**. El
  footer del POS es donde viven los controles de estado de la caja, no la
  navegación (`frontend/components/layout/pos-sidebar.tsx:277-281`, docblock del
  `SidebarFooter`); hoy son "Modo" (`:288-298`) y "Bloquear" (`:302-314`).
- **Mobile**: eso cae dentro del bottom drawer que abre el ícono **Módulos** —
  en mobile el sidebar ES un Sheet.
- Elegido explícitamente sobre las alternativas. El FAB del panel queda
  descartado para la caja: taparía el CTA de cobrar.

Consecuencia técnica ya aprendida y revertida: **el trigger y el chat se montan
en la misma superficie**. En mobile el sidebar se desmonta al tocar el item, así
que un chat montado dentro del sidebar se cerraría solo — mismo motivo por el
que `PosModeDialog` vive en el layout.

### D8 [?] — Componente de chat propio, lógica compartida

`AgentChatContent` (`frontend/components/agent/agent-chat-content.tsx:39`) está
escrito para el Sheet del FAB del panel — tanto que `/chat` **ya no lo reusa** y
tiene su propia implementación por layout (`app/(panel)/chat/page.tsx:43`).
Dentro del propio panel ese componente ya se bifurcó una vez.

La caja pide más diferencias que `/chat`: touch targets y tipografía de caja
(`project_pos_touch_keyboard_first`), hotkey de apertura y ESC sin robarle
atajos al POS (`hooks/use-hotkeys.ts`), autofocus al abrir, y sin selector de
view-scope (el POS no tiene override de sucursal). `feedback_no_fill_unrequested_modules`
es explícita: *el POS rara vez quiere lo mismo que el panel.*

Propuesta: componente propio en `components/pos/`, compartiendo **el hook de
chat y el catálogo**, no el JSX.

### D9 [?] — Ciclo de vida de la sesión de turno

Es nuevo y hay que decidirlo, porque una credencial que nace en el unlock tiene
que morir en algún lado:

- **Al bloquear la caja** (`lock()`, `lock-store.ts:115`) → **se revoca**, con
  `authSessionRevokeBySessionId()` (`auth_session.php:304`). El bloqueo es la
  señal de que el operador se fue; dejar viva su credencial es dejar la caja
  abierta.
- **Vigencia** → **igual a la de la `OperatorAssertion`, 16h**
  (`OperatorAssertion.php:69`). Dos relojes distintos para el mismo turno serían
  dos formas distintas de fallar. Vencida, `authResolve` la rechaza
  (`auth_session.php:187`) y el front trata el 401 como D6: item disabled,
  *"Volvé a ingresar tu PIN"*.
- **Al despairear el device** → ya cubierto por
  `authSessionRevokeByDevice()` (`auth_session.php:338`), siempre que la sesión
  se emita con el `deviceId` de la caja. Emitirla sin `deviceId` la dejaría
  huérfana de esa revocación — es el detalle que hay que no olvidar.
- **Visibilidad** → aparece en `/settings/sessions` como sesión de la caja, NO
  en `/settings/mcp-keys`. Ver D1, colisión 1.

---

## Fases

| Fase | Qué | Depende de |
|---|---|---|
| **M0** | Realm `pos-ai`: `READ_ONLY_REALMS` en el embudo + emisión en `unlock-pin` + revocación en lock/expiry (D9) | D1 |
| **M1** | Opt-in de los endpoints del recorte + scope por `registerId` en transactions/drawers + arnés de allowlists | M0, **D3** |
| **M2** | Permiso `pos.ai.use`: catálogo + backfill de roles | D4 |
| **M3** | BFF `/api/pos/agent/*` + `POS_TOOL_IDS` sobre `buildReadOnlyFetchTools` | M1 |
| **M4** | UI: item en el footer (D7), componente propio (D8), estados offline (D6) | M3, D7, D8 |
| **M5** | Actor en `meta` del ledger (D5) — arregla también el hueco del panel | M3 |

M0 es mucho más chico que en la versión anterior de este doc: no modela
identidad, no toca `OperatorAssertion`, no adelanta `context/21`. Es un realm
más sobre maquinaria que ya corre en producción.

M2 y M5 no dependen de D1 ni de D3 y pueden ir primero.
M4 se puede armar antes de M0, pero **no se mergea** en ese estado: un item que
abre un chat que no puede leer nada es el bug de hoy con otra forma.

### Qué queda NO verificado en cada fase

- **M0** — que `authResolve` y los resolvers de contexto traten bien un realm
  que lleva `registerId` y `deviceId` pero no es `pos-app`
  (`bootstrap.php:195-243` ramifica por `pos-app`). Hay que probarlo con arnés,
  no razonarlo.
- **M1** — el arnés puede verificar que los endpoints del recorte rechazan
  escritura; **no** puede verificar que ninguno que nadie tocó quedó accesible.
  Hace falta el inventario explícito de allowlists, como en `mcp_realm_test`. Y
  el scope por `registerId` (§D3) hay que confirmarlo endpoint por endpoint.
- **M3** — que las descripciones del catálogo, escritas para un usuario de
  panel, funcionen para preguntas de mostrador. Solo se sabe probando con un
  modelo real (mismo pendiente que `context/58` M2).
- **M4** — el drawer mobile con el teclado virtual abierto. Es donde se rompen
  los chats en tablets y no se verifica sin dispositivo.
- **M5** — el rate limit real: el PHP de desarrollo no tiene phpredis, así que
  solo se ejercita la rama FAIL_OPEN (mismo límite que `context/58`).

---

## Alternativas evaluadas y subordinadas

Las dos que estructuraban la versión anterior de este doc. Ninguna está mal;
C les gana por lo mismo en los dos casos: **menos superficie nueva, reusa lo que
se probó esta semana, y no roza el mandato POS/panel.**

### A — Realm de identidad propio (`pos-operator`), sesión por persona

Emitir en el unlock una sesión que re-modele al operador: revocable, con su
propio rol, como paso adelantado de `context/21`.

**Por qué C le gana:** A construye media reescritura de auth dentro de un feature
de agente — exactamente lo que `OperatorAssertion.php:53-57` evitó a propósito
al diferir esa pieza. C emite una credencial de datos, no una identidad nueva:
hereda `userId`/`roleId` igual que una key MCP. A sigue siendo la respuesta
correcta el día que haga falta una sesión de operador de verdad; ese día no es
este, y no lo dispara un chat.

### B — Superficie POS propia (`/v1/pos/ai/*` + catálogo reducido)

Endpoints nuevos escritos para la caja, con su propio catálogo.

**Por qué C le gana:** B duplica los **endpoints** —30 lecturas reimplementadas o
un proxy interno que las llame, y ese proxy vuelve a ser, con otro nombre, la
decisión de C— y arrastra el riesgo de un segundo catálogo que se desincroniza.
C consigue lo mismo que B quería (que la caja no alcance lo que no es suyo) con
el opt-in de endpoints como recorte, sin escribir un endpoint nuevo.

---

## Arquitecturas RECHAZADAS

Leer esto **antes** de proponer algo en este módulo.

### 1. Montar el componente del panel en `/pos` con la credencial del panel

Es lo que se hizo el 2026-08-30 y se revirtió en `80a21be2`. Dos fallas
independientes:

- **El gate leía el realm equivocado.** `usePermission()` sale de
  `useBootstrap()` (`frontend/hooks/use-permissions.ts:10-13`), el bootstrap del
  realm PANEL. En una caja pareada el rol es el rol `device`
  (`api/bootstrap.php:229-239`), que no tiene `ai.agent.use` → el item no
  aparecía. En un browser con sesión de panel, sí. **El botón existía o no según
  cómo se hubiera abierto la caja**: no es un error, es una inconsistencia
  silenciosa que no se detecta en desarrollo, se detecta en el local del
  cliente.
- **El chat habría fallado igual.**
  `frontend/app/api/agent/chat/route.ts:36` reenvía el `authorization` de la
  request —el Bearer del PANEL— y el backend es `apiAuthTenant(['panel'])`
  (`api/v1/ai/execute.php:19`, ídem `debit.php:17`). Una caja pareada no tiene
  ese Bearer.

El componente revertido documentaba la suposición equivocada: *"El agente corre
contra la API del PANEL con la credencial del operador"*. Esa credencial no
existía — es la que C emite.

**No hay versión arreglada de esto.** "Que el POS también mande la cookie del
panel" es literalmente el incidente de `feedback_pos_token_only_no_realms`, tres
veces (2026-07-19, 08-24, 08-25).

### 2. Que el unlock por PIN emita una sesión de realm `panel`

Atajo tentador: el PIN ya identifica a la persona.

Es una **escalada de privilegios**, no una integración. El PIN es de 4 dígitos y
`pinhash` es un SHA-256 **sin sal** (`api/v1/unlock-pin.php:47`) — 10.000
combinaciones, documentado como tal en `api/v1/bootstrap.php:173`. Ese hash se
cachea a propósito en el localStorage de una tablet compartida para permitir el
desbloqueo offline. Quien tenga la tablet enumera el espacio en segundos.

El PIN es aceptable **porque no abre nada por sí solo**. Esa propiedad es lo que
lo mantiene barato, y es lo que este atajo destruye.

**Corolario para C:** la sesión de turno es read-only y acotada al recorte de D3
justamente por esto. Su valor como botín tiene que quedar por debajo de lo que
cuesta adivinar un PIN de 4 dígitos. Si algún día alguien propone ampliarla, ese
es el techo contra el que se mide.

### 3. Reusar el realm `pos-app` para las lecturas del agente

El read-only no se puede forzar en el embudo para el realm con el que el POS
**escribe ventas**. Sin embudo, el opt-in de los endpoints de lectura le da el
flujo de caja al Bearer **eterno** del device, con o sin asistente de por medio y
con o sin operador desbloqueado.

### 4. Reusar el realm `mcp` tal cual para la sesión de turno

Descartado en D1: el backend no podría distinguir la sesión de una caja de una
API key, así que el recorte del catálogo sería cosmético —el token de la tablet
leería `/v1/finance/*` con un `curl`— y de paso rompe la UI de keys, la
auditoría y el rate limit.

### 5. Duplicar el catálogo de tools para el POS

Con C deja de hacer falta: el subconjunto sale de un `POS_TOOL_IDS` sobre el
mismo `buildReadOnlyFetchTools` (`read-tools.ts:548`), y el límite duro lo pone
el opt-in de endpoints en el backend.

Dos listas escritas por separado se desincronizan en semanas, y las
descripciones son la parte cara de mantener — *"Las descripciones de las tools
SON la UX"* (`context/58` §Arquitectura). Un catálogo POS escrito a mano
tendría, además, descripciones sin probar contra un modelo, que es justo lo que
`context/58` advierte que se subestima.

---

## Riesgos

- **La línea de D3 se va a querer correr.** Cada tool que se agregue amplía lo
  que una tablet compartida puede leer, y ahora también lo que un token filtrado
  alcanza. El techo está en §RECHAZADAS 2.
- **Divergencia de allowlists** entre `mcp` y `pos-ai` al agregar endpoints
  nuevos. Se mitiga con arnés de inventario, no con memoria.
- **Inyección por datos**, igual que en `context/58`: nombres de contacto y notas
  de ítem los escriben terceros y llegan al modelo como contexto. Se devuelven
  etiquetados como datos, nunca interpolados en descripciones de tools.
- **Créditos sin dueño visible** hasta que exista M5.

## Relacionados

- `context/58-mcp-server.md` — de donde sale todo: realm read-only, opt-in por
  endpoint, guard en el embudo, catálogo compartido. M0/M1 en producción.
- `context/17` — agente IA propio: alcance, OpenRouter, créditos.
- `context/21-auth-rewrite.md` — donde vive la sesión de operador "de verdad"
  (alternativa A), que C deliberadamente NO adelanta.
- `context/51-configuracion-offline-de-la-caja.md` — qué se encola y qué no.
- `context/08-convenciones-criticas.md` §60 — un cliente HTTP = un realm.

---

## Lo que necesita OK del owner para arrancar

**Bloqueante, una sola:**

1. **D3 — el recorte del catálogo.** Las 7 tools de la tabla "DENTRO", y las
   exclusiones de la lista "AFUERA" — en especial que **`get_report` queda afuera
   completa** y que finanzas no entra. Con C, esa lista no es una preferencia de
   UI: define a qué endpoints se les hace el opt-in, o sea qué puede leer la caja
   por cualquier vía.

**Conviene que mire, no bloquea:**

2. **D1 — realm hermano `pos-ai` en lugar de reusar `mcp`.** Está resuelta con un
   argumento concreto (el recorte tiene que vivir en el backend), pero cuesta un
   refactor chico del embudo y conviene que sepa por qué no salió gratis.
3. **D9 — ciclo de vida**: revocar al bloquear, 16h de vigencia, atada al
   `deviceId`.

El resto (D4 permiso, D5 actor en el ledger, D6 offline, D8 componente propio)
son propuestas con su defensa arriba. D2 quedó cerrada por construcción y D7 la
cerró él.
