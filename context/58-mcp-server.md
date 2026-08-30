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

### D4 [?] — Realm propio `mcp`, no reusar `panel`

`auth_session` (mig 69) ya modela todo lo necesario y **no cuesta migración**:
`realm` es `varchar(16)` sin CHECK, `tokenHash` es sha256 del token crudo (que
nunca se guarda), `status`/`revokedAt`/`revokedBy` dan revocación, el índice
`(companyId, realm, status)` es el que alimenta la UI de sesiones que ya existe
en `/settings/sessions`, y `meta jsonb` es donde viven los scopes.

El realm tiene que ser **propio** por la razón contraria al costo: como
`apiAuthTenant(['panel'])` es el allowlist endpoint por endpoint, reusar
`panel` le daría al MCP TODO lo que el panel puede hacer, incluidas las
escrituras, sin que nadie lo haya decidido. Con `mcp`, cada endpoint opta
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
| **M0** | Realm `mcp` + emisión/revocación de API keys desde `/settings/sessions` + scopes en `meta` + auditoría de cada llamada (el tenant tiene que poder ver qué hizo su IA) | — |
| **M1** | MCP server mínimo: handshake + listado de tools sobre `lib/agent/read-tools.ts` (`buildReadOnlyFetchTools`, que ya excluye `render_chart`) | M0 |
| **M2** | Redacción del catálogo de tools: nombres, descripciones, etiquetas de campo. Prueba real con Claude Desktop contra un tenant de prueba | M1 |
| **M3** | Invitación a terceros (contador) con scope de lectura contable (D9) | M0 |
| **M4** | Gating por plan + rate limit por key (D10) | M1 |
| **M5** | Telemetría de uso de tools, mismo esquema para MCP y agente propio (D12) | M1, `context/17` |

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

## Cuándo — NO es lo próximo

Compite contra cosas que bloquean vender: los caminos de facturación electrónica
sin probar (`context/28` — línea exenta, multi-pago, NC), los 6 P2 de la
auditoría de auth del 2026-08-26, y el WebSocket de realtime sin autenticación.

Abrir una superficie programática con hallazgos de auth conocidos multiplica el
radio de cualquier cosa que quede abierta. Orden: **FE → auth → `context/47` F0
→ MCP**. Contra F0 ya hecha, M1 es barato.

## Relacionados

- `context/17` — agente IA propio: alcance, OpenRouter, créditos. La otra mitad
  de D1.
- `context/47-reportes-personalizados-y-export.md` — **F0 es el prerequisito
  real** (D8).
- `context/55-franquicias.md` — el franquiciador es el perfil power user donde
  el plan alto se justifica solo.
- `context/09-costos-y-creditos.md` — qué absorbe Punto y qué se factura.
- `context/08-convenciones-criticas.md` §60 — un cliente HTTP = un realm; la
  disciplina que D4 hereda.
