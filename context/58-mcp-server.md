# MCP Server de Punto — plan del módulo

> Estado: **plan sin implementar** (2026-08-29). D1–D3 las cerró el owner en la
> conversación que originó este doc; D4–D10 son PROPUESTAS y necesitan su OK
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

## Fases

| Fase | Qué | Depende de |
|---|---|---|
| **M0** | Realm `mcp` + emisión/revocación de API keys desde `/settings/sessions` + scopes en `meta` + auditoría de cada llamada (el tenant tiene que poder ver qué hizo su IA) | — |
| **M1** | MCP server mínimo: handshake, listado de tools, 3-5 herramientas de lectura sobre el catálogo (ventas por período/sucursal, stock, cuentas por cobrar) | M0, `context/47` F0 |
| **M2** | Redacción del catálogo de tools: nombres, descripciones, etiquetas de campo. Prueba real con Claude Desktop contra un tenant de prueba | M1 |
| **M3** | Invitación a terceros (contador) con scope de lectura contable (D9) | M0 |
| **M4** | Gating por plan + rate limit por key (D10) | M1 |

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
