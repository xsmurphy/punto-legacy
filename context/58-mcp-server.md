# MCP Server de Punto — plan del módulo

> Estado: **plan sin implementar** (2026-08-29). D1–D3 las cerró el owner en la
> conversación que originó este doc; D4–D13 son PROPUESTAS y necesitan su OK
> (marcadas **[?]**). No es lo próximo — ver §Cuándo.

## Por qué

Punto ya tiene agente IA propio (`context/17`): OpenRouter, model-agnostic,
acotado a lecturas y escrituras simples (contactos, ítems básicos, usuarios
no-admin, catálogo) con `confirmToken`, y facturado por `ai_credit_ledger`.

Lo que NO puede hacer, por diseño, es lo que aparece cuando la data de Punto se
combina con herramientas que Punto nunca va a construir: la presentación para el
directorio, la campaña en la herramienta de mailing del comercio, el modelo en
una planilla, el cruce con datos que no viven en el sistema.

Un MCP server no es "reportes avanzados". Es **Punto convertido en fuente de
datos dentro del flujo de trabajo del cliente**.

## Decisiones cerradas

### D1 — Dos superficies, dos momentos. No se reemplazan (owner)

| | Agente IA de Punto | Claude (u otro) + MCP de Punto |
|---|---|---|
| Analogía del owner | El Panel | Power BI + Excel |
| Momento | Mostrador, dentro del flujo | Escritorio, pensando |
| Usuario | Común, poco técnico, cuida el costo | Power user, ya usa IA a diario |
| Alcance | Solo info del sistema | Mezcla integraciones, plugins, skills |
| Quién paga el razonamiento | Punto (`ai_credit_ledger`) | El cliente, en su propia suscripción |

Nadie piensa que Power BI compite con los reportes del ERP: conviven porque
sirven momentos distintos. **Esta decisión NO es licencia para congelar el
agente propio** — vive dentro del flujo de trabajo, que es la posición más
valiosa del producto, y esa es razón para mejorarlo.

### D2 — El caso comercial es posicionamiento y canal, NO ingreso de plan

El mercado real son PyMEs paraguayas: los tenants que hoy pagan Claude Pro **y**
saben qué es un MCP son un puñado. Justificarlo por ingreso de plan alto hace
que en seis meses parezca un fracaso. El caso que cierra:

- **Posicionamiento** — "el único POS de Paraguay que conectás a tu IA" vende
  incluso a quien no lo va a usar.
- **Costo marginal casi nulo** una vez que existe la F0 de `context/47`.
- **Deals grandes** — un franquiciador analizando a sus franquiciados
  (`context/55`) es el perfil power user donde el ticket justifica el plan.

### D3 — Va contra la MISMA API, sin BFF (owner)

El contenedor de `api/` ya está separado del BFF y del front. El MCP server es
un cliente HTTP más de `/v1/*`, igual que el panel o el POS. No hay stack nuevo.

## Decisiones propuestas — falta OK del owner

### D4 [?] — Realm propio `api`, no reusar `panel`

`auth_session` (mig 69) ya modela todo lo necesario y **no cuesta migración**:
`realm` es `varchar(16)` sin CHECK, `tokenHash` es sha256 del token crudo (que
nunca se guarda), `status`/`revokedAt`/`revokedBy` dan revocación, el índice
`(companyId, realm, status)` es el que alimenta la UI de sesiones que ya existe
en `/settings/sessions`, y `meta jsonb` es donde viven los scopes.

El realm tiene que ser **propio** por la razón contraria al costo: como
`apiAuthTenant(['panel'])` es el allowlist endpoint por endpoint, reusar
`panel` le daría a las keys TODO lo que el panel puede hacer, incluidas las
escrituras, sin que nadie lo haya decidido. Con `api`, cada endpoint opta
explícitamente. Es la misma disciplina que ya evitó tres incidentes en el POS
(`context/08` §60, `feedback_pos_token_only_no_realms`).

### D5 [?] — Read-only en F1, y NUNCA más alcance que el agente propio

El agente propio está acotado a propósito. Si el MCP existe para "hacer más",
un modelo **externo y no auditado** —system prompt que no controlás, corriendo
en la máquina del cliente— termina con más privilegio que el tuyo, que sí
auditaste y al que le pusiste confirmación explícita.

El valor está en que el razonamiento lo pone el cliente, no en más escrituras.

Presión conocida: el caso "segmentar clientes y accionar marketing" tiene una
versión que escribe de vuelta (etiquetar contactos, crear una promo). Hoy queda
afuera —se lee de Punto y se actúa en otra herramienta— pero va a aparecer.
Diseñar sabiéndolo no cuesta; descubrirlo después sí.

### D6 [?] — Los permisos de la key ⊆ los del usuario que la generó

En `meta`. Una key filtrada no puede poder más que su dueño. Sin esto, una key
es una escalada de privilegios esperando a pasar.

### D7 [?] — Expiración por defecto, con rotación

Acá difiere del POS. `expiresAt = null` ("device POS eterno") es correcto para
un dispositivo pareado que ves físicamente y desparealás desde Ajustes. Una API
key vive en un archivo de config que se sincroniza a la nube y a veces termina
en un repo. Vencimiento largo, pero vencimiento.

### D8 [?] — Se sirve desde el catálogo de `context/47` F0, nunca con queries propias

La analogía del owner ("Excel") es el mejor argumento para esta decisión: la
patología de la cultura export-a-Excel es que cada analista termina con su
propia definición de "ventas". Si el deck del directorio dice un número y el
panel dice otro, **el que queda mal es Punto**.

La F0 de `context/47` ya define el registro declarativo de datasets
(dimensiones/métricas/filtros → `RollupReader`/services, ejecutado con
`Roc::build`). Cada herramienta MCP debe ser una entrada de ese catálogo, no
SQL nuevo. Con eso, agregar una herramienta es declarativo.

### D9 [?] — El contador como canal, no solo el dueño como usuario

En Paraguay toda PyME tiene contador, y **el contador ES el power user**: es
literalmente quien hace análisis y reportes complejos todos los meses, hoy
pidiéndole exports al comercio.

Un contador con 30 clientes recomendando Punto vale más que treinta upsells de
plan alto. Implica que el comercio pueda **invitar a un tercero** con una key de
alcance acotado (lectura contable), no solo generar una para sí mismo. Es el
mismo `meta` con scopes — pero conviene decidirlo antes, no después.

### D10 [?] — Gateado por plan, y no solo por monetizar

Gatear algo de costo marginal casi nulo para vender plan es legítimo, pero la
razón más fuerte es otra: **limita quién puede martillar la API**. Un power user
con un modelo en loop hace mucho más volumen que un cajero.

### D11 [?] — La fuente compartida es el CATÁLOGO, no el transporte MCP

Pregunta del owner: ¿conviene que el agente propio consuma el mismo MCP, para
tener una sola fuente de código?

Sí a una sola fuente — pero una capa más abajo. Lo que no debe duplicarse son
las **definiciones**: qué datasets existen, qué dimensiones y filtros aceptan,
cómo se llaman y cómo se describen. Eso es la F0 de `context/47`, y de ahí
cuelgan los dos consumidores:

```
                    ┌─→ MCP server ──→ cliente externo (Claude u otro)
catálogo (F0) ──────┤
                    └─→ agente de Punto (in-process)
```

Lo que NO conviene es `catálogo → MCP server → agente`: el agente corre dentro
del propio backend, así que enrutarlo por el MCP le agrega un salto de red,
serialización de protocolo y un punto de falla nuevo para llegar a datos que ya
tiene al lado — y ata la disponibilidad de una funcionalidad del producto a un
servicio cuyo propósito es servir a terceros.

La divergencia que preocupa es real (dos listas de tools que se separan), pero
se evita en el registro declarativo, no en el transporte.

### D12 [?] — Telemetría de uso de tools, NO un benchmark Claude vs Punto IA

La otra mitad de la pregunta del owner era medir la performance de los dos
motores contra la misma fuente. Compartir la fuente saca UNA variable y deja las
que deciden el resultado: modelo mucho más grande, el prompting del propio
usuario, iteración multi-turno y otras herramientas en la mezcla. No es
comparable y no va a serlo.

Y el resultado ya se conoce: que Claude gana en análisis complejo es la premisa
de D1 — es por eso que se segmentó. Medirlo confirma lo que ya se diseñó
asumiendo.

Lo que sí vale medir, y que el catálogo compartido da gratis porque es un solo
esquema para los dos lados: **qué tools se usan, con qué argumentos y dónde
fallan.**

- Una tool llamada con filtros que el catálogo rechaza = hueco de catálogo.
- El agente propio que nunca encuentra la tool correcta = problema de
  DESCRIPCIÓN, no de modelo (ver §Arquitectura: las descripciones son la UX).
- Una tool que concentra el grueso de las llamadas = dónde conviene invertir.

### D13 [?] — El agente como CLIENTE de MCPs de terceros (futuro, solo en el radar)

Distinto de D11 y no lo cambia. Si algún día se quiere que el agente propio use
las herramientas del comercio (su CRM, su mailing), lo que conviene es que sea
cliente MCP — consumiendo servidores AJENOS, no el propio. Se anota porque
cambia la arquitectura interna del agente y conviene saberlo antes de cerrarla.

## Arquitectura

```
Claude Desktop / otro cliente MCP
        │  (API key del tenant, generada en /settings/sessions)
        ▼
  MCP server de Punto          ← proceso propio, stateless
        │  Bearer, realm 'mcp'
        ▼
  api/ (contenedor existente)  ← /v1/*, apiAuthTenant(['mcp'])
        │
        ▼
  Catálogo + ejecutor (context/47 F0) ─→ RollupReader / services
```

El MCP server NO habla con la BD. Es un traductor de protocolo sobre la API que
ya existe — misma razón por la que el POS no habla con la BD.

**Las descripciones de las tools SON la UX.** El modelo del cliente no tiene tu
interfaz, tus tooltips ni tu contexto: lo único que lee para decidir es el
nombre de la herramienta, su descripción y los campos que devuelve. Diez
herramientas bien nombradas y bien descritas, con vocabulario del dominio y
columnas etiquetadas, valen más que cuarenta endpoints crudos. Es trabajo de
redacción tanto como de código, y es donde se gana o se pierde.

## El primer paso NO es la F0 de `context/47` (corregido 2026-08-30)

La primera versión de este doc daba la F0 de `context/47` (catálogo + ejecutor
declarativo) como prerequisito y estimaba 3-4 semanas. **Estaba mal**, y el
motivo fue leer `context/17`, que decía "Planificado" cuando el agente ya
estaba construido.

Lo que hay en realidad: **20 tools de lectura ya definidas**, con `description`
escrita para un LLM e `inputSchema` en zod, y `execute` que es un fetch fino
contra `/v1/*` — la MISMA API que consumiría el MCP. Las descripciones además
ya están probadas contra un modelo real, que es justo la parte que este doc
advierte que se subestima (§Arquitectura).

**Hecho el 2026-08-30**: esas 20 definiciones se extrajeron a
`frontend/lib/agent/read-tools.ts`, un catálogo agnóstico del transporte. No
importa `tool()` del AI SDK —ese helper ES el transporte— sino un `defineTool`
propio que hace lo único necesario: atar el tipo de `execute` al de
`inputSchema`. `route.ts` las consume con un spread y bajó de 734 a 282 líneas.
Los cuerpos quedaron byte-idénticos al original: la extracción no cambió una
sola definición.

Consecuencia para el plan: **M1 deja de depender de la F0 de `context/47`**. El
MCP server pasa a ser un segundo transporte sobre un catálogo que ya existe. La
F0 sigue valiendo la pena —volver el catálogo declarativo, con dimensiones y
filtros validados— pero como MEJORA posterior, no como bloqueo.

Estimación revisada: **~1,5 a 2 semanas** para un MCP read-only vendible, con
M0 (realm, keys, scopes, auditoría) como la pieza más grande.

## Fases

| Fase | Qué | Depende de |
|---|---|---|
| **M0** | Realm `mcp` + emisión/revocación de API keys + auditoría de cada llamada | ✅ **HECHA 2026-08-30** |
| **M1** | MCP server + opt-in del realm en los endpoints de lectura | ✅ **HECHA 2026-08-30** |
| **M2** | Redacción del catálogo de tools: nombres, descripciones, etiquetas de campo. Prueba real con Claude Desktop contra un tenant de prueba | ✅ **VERIFICADA 2026-08-31** (conector conectado, 4 tools ejecutadas contra ICAS con datos reales) |
| **M3** | Invitación a terceros (contador) con scope de lectura contable (D9) | M0 |
| **M4** | Gating por plan (D10) — el **rate limit ya está**, ver abajo | M1 |
| **M5** | Telemetría de uso de tools, mismo esquema para MCP y agente propio (D12) | M1, `context/17` |

## M0 — cómo quedó (2026-08-30)

Salió más barato de lo planificado porque casi todo existía:

- **Sin migración.** Una key es una fila de `auth_session` (mig 69) con
  `realm = 'mcp'`; esa columna es `varchar(16)` sin CHECK. `authResolve()`
  tampoco se tocó: ya chequea `in_array($realm, $allowedRealms)`.
- **Permisos ⊆ usuario, por construcción.** La key se emite con el mismo
  `userId`/`roleId`/`outletId` del operador, así que `hasPermission()` resuelve
  idéntico. No hay segunda tabla de permisos que pueda divergir (D6 sin código
  propio).
- **UI en página aparte**, `/settings/mcp-keys`, NO como sección de Sesiones: una
  sesión solo se revoca, una key se emite y su token se muestra una sola vez.
  Verbos distintos. Las keys igual aparecen en Sesiones con la etiqueta
  "Integración".
- **Auditoría sobre `tenant_audit`** (mig 35), que ya tenía columna `realm`,
  índices y retención por pg_cron. El único cambio en `bootstrap.php`: la regla
  general audita mutaciones y NO los GET; el realm `mcp` es la excepción
  invertida, porque sus lecturas son todo el producto — auditar solo mutaciones
  en un realm read-only sería no auditar nada. Se guarda `keyId` en `meta` para
  saber qué integración llamó; el nombre no, porque vive en `auth_session.meta`
  y el SELECT de `authSessionLookup()` es el hot path de toda request
  autenticada.

Arnés `api/tests/mcp_key_test.php`, 24/24.

**Lo que M0 NO incluye, y hace falta para probar con un cliente real:** ningún
endpoint acepta todavía el realm `mcp` — `/v1/*` es `apiAuthTenant(['panel'])`,
así que hoy una key válida sería rechazada. Ese opt-in es parte de M1, junto con
el server.

## M1 — cómo quedó (2026-08-30)

**El server es un ROUTE, no un contenedor.** `frontend/app/api/mcp/route.ts`,
con `WebStandardStreamableHTTPServerTransport` del SDK oficial. MCP sobre
Streamable HTTP es JSON-RPC por POST: un endpoint más. Un proceso propio solo
haría falta con transporte stdio o para escalar/aislar esto del panel — ninguna
aplica, y evitarlo ahorra una app en Coolify con su env y su dominio.

Dos propiedades que NO son preferencias:

- **Stateless obligatorio** (`sessionIdGenerator: undefined`). Un route handler
  de Next no garantiza el mismo proceso entre requests, así que cualquier estado
  en memoria se perdería de forma intermitente — el peor modo de fallar.
- **Un `McpServer` por request.** Las tools se arman con el Bearer de ESA
  request. Un server module-level compartido quedaría con la credencial del
  primer tenant que lo tocó y las lecturas de todos saldrían con esa key: leak
  cross-tenant silencioso. Instanciar por request lo hace imposible por
  construcción.

**El realm opt-in llegó con el read-only en el embudo.** Los 18 endpoints que el
catálogo consulta agregan `'mcp'` a su allowlist, pero muchos sirven GET y
mutaciones en el mismo archivo: si el read-only dependiera de que cada uno mire
el método, UN olvido daría escritura a una API key. El guard vive en
`apiAuthTenant()` — realm `mcp` con verbo distinto de GET/HEAD corta 405.
`devices.php` y `mcp-keys.php` quedan FUERA a propósito: una key filtrada no
enumera cajas ni se fabrica más keys.

Arneses: `mcp_realm_test` 9/9 (endpoints reales, en subproceso) y
`lib/__tests__/mcp-route.test.ts` 3/3 (handshake `initialize` + `tools/list`
contra el route, con Requests estándar).

**Lo que sigue sin verificar**: nadie lo conectó a un cliente real. El smoke
cubre el modo de falla más probable —que el server arranque pero hable mal el
protocolo— pero no reemplaza una conexión de verdad.

## El realm se llama `api`, no `mcp` (rename 2026-08-30, mig 182)

Pregunta del owner: *"¿no se pueden usar esas keys como API keys? ¿son solo para
MCP?"*. Se pueden, y el nombre estaba mal.

El realm es la frontera de seguridad y describe **acceso programático de solo
lectura en nombre de un usuario**. MCP resultó ser su primer consumidor, no su
definición: la MISMA key funciona como API key común contra cualquier endpoint
que optó por el realm —

```bash
curl -H "Authorization: Bearer <key>" https://api.punto.la/v1/settings
```

Dejarlo como `mcp` significaba que un comercio integrando su propio dashboard, o
el sistema de su contador (D9), iba a autenticar contra un realm llamado "mcp"
que no tiene nada que ver con MCP.

Se renombró **ahora** porque las únicas keys existentes eran de prueba: cada
semana que pasara, esto se volvía una migración sobre credenciales en uso — la
clase de rename que después nadie hace. La mig 182 cubre `auth_session` y
también `tenant_audit`, para que el historial de auditoría no quede partido en
dos realms sin explicación.

El **route MCP conserva su nombre** (`/api/mcp`): eso sí es MCP. Lo que se
generalizó es la credencial, no el transporte.

## La UI de Connectors reserva `Authorization` (hallazgo 2026-08-30)

Al conectar desde **Claude Desktop → Connectors → conector propio**, el diálogo
NO ofrece dónde pegar una API key: para servers remotos asume OAuth e intenta
registrarse solo (dynamic client registration). Con nuestro server —Bearer
estático, sin OAuth— falla con *"Couldn't register with Punto's sign-in
service"*.

Tiene una sección "Additional request headers", pero ahí `authorization`
aparece **deshabilitado**: Claude lo reserva para su propio bearer de OAuth. Los
seleccionables son `x-api-key`, `api-key`, `apikey`, `x-apikey`, `x-api-token`,
`api-token`, `x-auth-token`, `x-access-token`.

**Por eso el route acepta la key por cualquiera de esos headers**, no solo por
`Authorization` (`KEY_HEADERS` en `app/api/mcp/route.ts`, normalizado a
`Bearer <key>` antes de salir hacia la API). Son varios y no uno porque el menú
lo dicta el cliente: el usuario elige cualquiera y todos tienen que andar, o el
éxito depende de cuál haya tocado.

Sin esto la ÚNICA instalación posible era editar `claude_desktop_config.json` a
mano con el puente `mcp-remote` — aceptable para un técnico, imposible de pedirle
a un comercio, que es justamente el caso de uso de D9 (el contador como canal) y
del upsell de plan alto.

**OAuth sigue siendo el camino prolijo** —clic en "Conectar", login en Punto,
consentimiento, sin copiar keys— pero ya NO bloquea la instalación. Queda como
mejora, no como prerequisito.

## El GET no puede colgar (hallazgo 2026-08-30)

Segundo síntoma al conectar desde la UI de Connectors, ya con el `x-api-key`
resuelto: *"Couldn't connect to the server. Check that the URL points to a valid
MCP server."*

La URL estaba bien y el POST respondía 200. Lo que fallaba era el **GET**:
delegado al transporte en modo stateless, abría un stream SSE que nunca emitía
ni cerraba, así que la request quedaba COLGADA hasta el timeout del cliente
(`curl` da `HTTP 000` a los 15s). Claude sondea con GET al agregar el conector,
espera, y reporta un error que apunta a la URL — que está bien.

**Colgar es peor que rechazar**: manda a investigar el lugar equivocado. Ahora
GET y DELETE devuelven 405 inmediato con `Allow: POST`, sin pasar por el
transporte — los dos presuponen una sesión, y en stateless no hay ninguna.

Guard en `lib/__tests__/mcp-route.test.ts`: el caso falla si el GET tarda más de
2 segundos.

## RESUELTO — el conector conecta de punta a punta (2026-08-31)

Los dos hallazgos de arriba no eran el bloqueo final. Hubo dos causas
encadenadas, la segunda tapando a la primera:

1. **El 401 disparaba OAuth, no solo el header reservado.** El handshake
   (`initialize`/`tools/list`) devolvía 401 sin credencial, y CUALQUIER 401 en
   un server remoto hace que el cliente MCP de Claude arranque el flujo OAuth
   (dynamic client registration), sin importar qué headers alternativos
   ofrezca el menú. Confirmado contra prod antes de tocar código: el 401 no
   traía `WWW-Authenticate`, y `/.well-known/oauth-protected-resource`,
   `/.well-known/oauth-authorization-server`, `/.well-known/openid-configuration`
   y `POST /register` daban 404 HTML — ese 404 en `/register` ES el "Couldn't
   register with Punto's sign-in service". Fix (`ff66e624`): `initialize` y
   `tools/list` responden 200 sin credencial; la key se exige recién en la
   ejecución de cada tool, como error de tool (`isError: true`), no como error
   de protocolo. Sin baja de seguridad real: la validez de la key siempre la
   resolvió la API (`authResolve`), y el catálogo ya salía con cualquier key
   inventada.
2. **Cloudflare bloqueaba a los user-agents de Anthropic.** Con el 401 fuera
   del camino, el error cambió a "Couldn't reach Punto" (ref
   `ofid_0fd21e04c1729a39`). Probe decisivo: `Claude-User/1.0`,
   `Claude-Web/1.0` y `anthropic-ai/1.0` daban 403 "Your request was blocked"
   en ~50ms — ni llegaban al origin — mientras curl/node/Mozilla daban 200.
   Causa: en la zona `punto.la` la tarjeta legacy **"Block AI bots
   [Deprecating on September 15]"** estaba activa con scope **"Block on all
   pages"**, sin opción de excluir por path. **El owner la desactivó a mano en
   el dashboard de Cloudflare** ("Do not block — allow crawlers"). Esto es
   infra, NO vive en el repo: si alguien la vuelve a prender, el conector
   muere con "Couldn't reach" y el síntoma no apunta a Cloudflare.

Verificación final: los tres user-agents de Anthropic pasaron a 200,
`tools/list` devolvió las 19 tools, el owner conectó el conector desde la UI
de Claude y se ejecutaron 4 tools con datos reales del tenant ICAS
(`get_settings`, `get_outlets`, `get_sales_summary`, `get_top_products`).

**Investigación para el camino 2 (OAuth), cuando se encare:** Anthropic
soporta 6 métodos de auth para conectores remotos (`oauth_dcr`, `oauth_cimd`
—RFC 9728, recomendado—, `oauth_anthropic_creds`, `static_headers` en beta,
`custom_connection`, `none`). Los conectores reales (Sentry, Linear, Asana)
encadenan OAuth 2.1 + PKCE con RFC 9728 + 8414 + 7591 + 8707 + 9207: 401 con
`WWW-Authenticate: Bearer resource_metadata=...` → protected resource
metadata → AS metadata → CIMD o DCR → authorize con consentimiento → token
(el `resource` del token request debe matchear exacto la URL del MCP).
Timeouts del cliente: ≤10s discovery, ≤30s refresh. Librerías candidatas:
`mcp-auth`, `fastmcp-oauth`, `@cloudflare/workers-oauth-provider` — ninguna
lista out-of-box para Next.js App Router + PHP.

## El WAF de Cloudflare bloquea al cliente MCP por USER-AGENT (2026-08-31)

Paso de infra que NO se deduce del repo, y cuyo modo de falla manda a buscar el
problema en el lugar equivocado: el server responde perfecto a mano y el conector
no conecta.

`app.punto.la` está detrás de Cloudflare (`server: cloudflare`, `cf-ray`). Sus
reglas de bots pueden matar el request ANTES de llegar al origen, con un 403 de
`text/plain`.

**El filtro es por user-agent declarado**, no por fingerprint TLS ni reputación
de IP — medido por la sesión de Fish sobre su propio dominio, mismo proveedor.
Eso hace la prueba trivial:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://app.punto.la/api/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -A "Claude-User/1.0" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

403 → es el WAF. 200 → el UA no es el problema, ir a los eventos de seguridad.

**Estado en Punto: RESUELTO.** El owner sacó la regla antes de que se construyera
el MCP, verificado 2026-08-31: `Claude-User/1.0` y `ClaudeBot/1.0` dan 200 acá y
403 en el dominio de Fish. Por eso las pruebas con `curl` de esta sesión nunca
vieron el bloqueo — llegaron después del fix, y `curl/8.7.1` no matchea la regla
igual.

**Si vuelve a aparecer**: la excepción va acotada a la ruta del MCP, no bajando la
seguridad del sitio. Y ojo con **Bot Fight Mode**: no admite excepción por path,
así que si el bloqueo viene de ahí hay que apagarlo y poner una regla WAF
equivalente que sí la acepte.

## La identidad del conector viaja en el handshake (2026-08-31)

El cliente NO deduce el logo del favicon del dominio: dibuja la tarjeta del
conector con lo que venga en el `Implementation` del handshake. Sin `title` e
`icons`, el conector aparece con el `name` crudo y sin marca.

El SDK (1.30) acepta `title`, `websiteUrl`, `description` e `icons` con
`{src, mimeType, theme}`. Se mandan las dos variantes de tema —verificado por
hash que `icon_bg_light.png` y `icon_bg_dark.png` son archivos DISTINTOS;
declarar dos temas apuntando al mismo archivo sería afirmar algo falso— y los
`src` van ABSOLUTOS: el cliente los busca desde su propio proceso, no desde el
navegador del usuario, así que una ruta relativa no resuelve.

**El host se deriva del REQUEST** (`x-forwarded-host` → `host`), con `APP_URL`
ganando si alguna vez se define. La primera versión caía a un literal
`https://app.punto.la` y —verificado contra Coolify— **`APP_URL` NO existe en el
env del Front**: producción funcionaba por casualidad, no por configuración, y un
contenedor de dev habría anunciado los iconos de PRODUCCIÓN. Sin origen
resuelto se OMITEN los iconos: un conector sin logo es un detalle, uno que
apunta al dominio equivocado es una mentira.

Dato aportado por la sesión de Fish, que se topó con lo mismo en su propio
server MCP.

**Resuelto de paso**: `/favicon.ico` devolvía 404 con el HTML del panel
(`text/html`, 10 KB) porque el proyecto sirve `app/icon.png` y no hay `.ico`. Se
REESCRIBE al PNG (`next.config.ts` → `rewrites`) en vez de generar un binario:
los clientes miran el `Content-Type`, no la extensión, y así no entra al repo un
`.ico` que nadie puede revisar en un diff. Rewrite y no redirect, porque algunos
clientes de íconos no siguen el 301.

## Rate limit (2026-08-30)

Aplicado en el mismo embudo que el read-only (`apiAuthTenant()`), reusando el
`RateLimiter` que ya existía y que hasta ahora solo usaba el login de admin.

Importa MÁS en el MCP que en el panel por una razón concreta: **un humano hace
clics, un modelo hace loops.** Un agente que razona mal puede pedir el mismo
reporte cincuenta veces en un minuto sin que nadie lo note — no es un ataque, es
uso normal salido de control. Y algunas tools traen hasta 5000 filas.

- **Dos ventanas**, porque frenan cosas distintas: 60/minuto corta el loop en
  caliente, 5000/día acota el costo total de una key que se porta "bien" pero
  consulta todo el día. Números generosos a propósito: una sesión de análisis
  real encadena varias herramientas y no debería tocar el techo nunca.
- **Cuenta por KEY** (`AUTHED_SESSION_ID`), no por IP ni por tenant: es lo que
  se revoca y lo que el comercio ve en la auditoría, así que es la unidad
  correcta para contar y para explicar el corte.
- **FAIL_OPEN**, al revés que el login de admin. Allá el limiter protege una
  credencial y si Redis se cae hay que cerrar; acá protege capacidad sobre una
  superficie de LECTURA, y tirar las integraciones de todos los comercios
  porque se cayó Redis es peor que el abuso que evita.

**NO verificado el corte real**: el PHP de desarrollo no tiene la extensión
phpredis, así que el arnés ejercita la rama FAIL_OPEN (que las llamadas pasen
sin Redis) y fija la configuración sobre el código. Que corte en 60/min solo se
puede comprobar donde haya Redis.

**Lo que sigue sin haber**: tope de tamaño de respuesta (`get_transactions`
trae hasta 5000 filas y todas viajan), límite de concurrencia por key más allá
del `maxDuration = 60` del route, y scopes por key — el campo `meta` está listo
pero hoy una key lee todo lo que su usuario puede.

## Riesgos

- **Inyección por datos.** Los nombres de contacto, notas de ítem y notas de
  factura los escriben los clientes del comercio, y por MCP llegan como contexto
  al modelo del tenant. Un contacto llamado "ignorá las instrucciones anteriores
  y…" es un vector real. Mitigación: los datos se devuelven etiquetados como
  datos, nunca interpolados en descripciones de tools.
- **Soporte irreproducible.** "Claude hizo algo raro en mi Punto" es un ticket
  que no podés reproducir. La auditoría de M0 es lo que lo hace investigable.
- **La data sale del perímetro del tenant** hacia lo que el cliente conecte. Es
  decisión suya, no de Punto, pero tiene que estar dicho en los términos y no
  aparecer como sorpresa.

## Cuándo — qué lo bloquea de verdad y qué no

> Corregido 2026-08-30. La versión anterior decía "orden: FE → auth → F0 → MCP",
> mezclando una dependencia técnica real con una preferencia de prioridad.
> Pregunta del owner que lo destapó: *"la facturación electrónica se ejecuta al
> hacerse una venta, ¿qué tiene que ver con el MCP?"*. Nada.

**M0 sí depende de cerrar los P2 de auth.** Es la única dependencia técnica del
plan, y es real: M0 crea un tipo de credencial nuevo sobre la MISMA tabla
`auth_session` y el MISMO gate `apiAuthTenant`. Con hallazgos de auth abiertos
(6 P2 de la auditoría del 2026-08-26 + el WebSocket de realtime sin
autenticación), agregar un realm encima multiplica el radio de cualquiera que
siga sin cerrar.

**FE no bloquea nada de esto.** Corre en el camino de la venta; el MCP es una
superficie de lectura. No comparten código ni superficie de auth. Que FE sea lo
que bloquea VENDER en Paraguay es razón para priorizarla — no para escribir que
traba al MCP, que es lo que decía este doc.

**M1 en adelante** no depende de FE ni de la F0 de `context/47` (ver la sección
anterior: el catálogo ya existe). Podría ir en paralelo si hubiera capacidad.

## Relacionados

- `context/17` — agente IA propio: alcance, OpenRouter, créditos. La otra mitad
  de D1.
- `context/47-reportes-personalizados-y-export.md` — **F0 es el prerequisito
  real** (D8).
- `context/55-franquicias.md` — el franquiciador es el perfil power user donde
  el plan alto se justifica solo. Su acceso por MCP vive ALLÁ (D8 + F6, 
  2026-09-03), no acá: la key sigue siendo mono-tenant y de realm `api`, y lo
  que cambia son tools `punto_franchise_*` propias sobre el servicio de
  supervisión. Las `punto_get_*` de este doc NO se le exponen — son lectura
  completa del tenant y contradicen el D3 de aquel.
- `context/09-costos-y-creditos.md` — qué absorbe Punto y qué se factura.
- `context/08-convenciones-criticas.md` §60 — un cliente HTTP = un realm; la
  disciplina que D4 hereda.
