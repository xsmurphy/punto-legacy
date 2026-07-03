# Plan: Agente IA embebido (chat con tools sobre Punto)

**Estado:** Planificado (arranca después de RB-1 del rollup).
**Objetivo:** un chat flotante en el panel donde el operador conversa con un agente IA que puede crear/modificar datos, generar reportes y analizar información de Punto vía tools. Model-agnostic vía OpenRouter, modelos configurables desde /admin, billing con el `ai_credit_ledger` existente.

## Decisiones (cerradas con el owner)

1. **Gateway OpenRouter** — un endpoint, multi-proveedor. AI SDK de Vercel (`ai`) + `@openrouter/ai-sdk-provider`. Frontend con `@assistant-ui/react` (chat shadcn-style con tool calls, streaming, dark mode).
2. **Model-agnostic, por capability**: distintos modelos por tipo de acción. OCR/imágenes → Gemini Flash; texto/tools → gpt-4o-mini o DeepSeek V4 flash. Defaults MVP: DeepSeek + Gemini.
3. **Configurable desde /admin**: el super-admin asigna modelo a cada capability. Config DB-backed.
4. **Billing**: debita del `ai_credit_ledger` / `company.aiCreditsBalance` (mig 28, ya existen). Registra `tokensIn`/`tokensOut`. Hard gate: balance 0 → chat deshabilitado con CTA a comprar créditos.
5. **Ubicación**: global flotante (botón siempre visible + Sheet lateral) en todo el panel.
6. **Tools incrementales**: cada feature nueva agrega su tool. Mutaciones piden confirmación inline.

Ver memoria [[ai-agent-openrouter-direction]].

## Arquitectura

```
┌─ Browser (frontend) ───────────────────────┐
│  <AssistantChat> (assistant-ui, Sheet)       │
│  useChat() → POST /api/agent/chat (BFF)       │──┐
└───────────────────────────────────────────────┘  │
                                                   ▼
┌─ BFF Next route handler: app/api/agent/chat ──┐
│  streamText({                                  │
│    model: openrouter(modelForCapability),      │
│    messages, tools,                            │
│    onFinish: debitCredits(usage)               │
│  })                                            │
│  tools ejecutan → llaman a /v1/* con el JWT    │──┐
└───────────────────────────────────────────────┘  │
                                                   ▼
                                          API Punto /v1/* (PHP)
```

- El **route handler vive en frontend** (`app/api/agent/chat/route.ts`), NO en PHP. Tiene la `OPENROUTER_API_KEY` (server-side, nunca al browser). Reusa el `_jwt_panel` del request para que las tools hereden los permisos del operador.
- Las **tools** son funciones TS que pegan a `/v1/*` (mismo BFF que ya usa el panel). Una tool = un wrapper tipado sobre un endpoint existente.
- **Débito de créditos**: en `onFinish`, con `usage.promptTokens`/`completionTokens`, calcular el costo (tabla de pricing por modelo) → POST `/v1/ai/debit` que inserta en `ai_credit_ledger` (delta negativo) y baja `aiCreditsBalance`. Atómico, con lock (mismo patrón que `PaymentsService::creditInvoice`).

## Config de modelos (/admin)

Tabla `ai_model_config` (DB-backed, editable desde /admin):

```sql
CREATE TABLE ai_model_config (
  capability  TEXT PRIMARY KEY,   -- 'chat' | 'ocr' | 'analysis' | 'vision'
  model       TEXT NOT NULL,      -- 'deepseek/deepseek-chat' | 'google/gemini-flash-1.5' | ...
  enabled     BOOLEAN NOT NULL DEFAULT true,
  creditsPerKToken NUMERIC(10,4) NOT NULL DEFAULT 1,  -- pricing en créditos por 1k tokens
  updatedAt   TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- seeds: chat→deepseek, ocr/vision→gemini-flash
```

`/admin` UI: una tabla simple (capability · modelo · habilitado · créditos/1k) editable. El route handler lee `ai_model_config` por capability antes de cada llamada.

## Slices

| # | Slice | Resultado |
|---|---|---|
| AI-1 | **Plomería** — deps (`ai`, `@openrouter/ai-sdk-provider`, `@assistant-ui/react`), route handler /api/agent/chat, config tabla + seeds, 1 tool read-only (`get_sales_summary` leyendo del rollup), chat flotante mínimo | Chat habla y puede leer 1 reporte |
| AI-2 | **Billing loop** — débito en onFinish, `/v1/ai/debit`, hard gate por balance, indicador de créditos en el chat | El uso descuenta créditos correctamente |
| AI-3 | **Tools de escritura acotadas** — alcance fijo, patrón confirmToken (ver §AI-3 detalle) | El agente opera datos básicos |
| AI-3b | **Refinement UI** — FAB neutro, textarea ChatGPT-style, página /chat dedicada con sugerencias, fix z-index POS via integración al menú principal | Agente con UX de producto, no MVP demo |
| AI-4 | **Config /admin** — UI de `ai_model_config`, selección por capability | Owner ajusta modelos sin deploy |
| AI-5 | **Capabilities extra** — OCR (subir foto de factura → Gemini extrae items), análisis (queries libres sobre el rollup) | Casos de alto valor |

### Detalle AI-1 (primer slice) — refinado 2026-06-21

Stack confirmado: Next 16.2.6, React 19.2.4, npm. AI SDK **v5**.

- **Deps** (npm): `ai`, `@ai-sdk/react`, `@openrouter/ai-sdk-provider`. **NO assistant-ui en AI-1** — el chat se arma hand-rolled con `useChat` de `@ai-sdk/react` + shadcn `Sheet`, para evitar el churn de API de assistant-ui en el slice fundacional. assistant-ui queda como mejora futura si se quiere UI más rica.
- **Env**: `OPENROUTER_API_KEY` (Coolify, servicio frontend, server-side — nunca al browser).
- **Mig `43_ai_model_config.sql`**: tabla `ai_model_config(capability PK, model, enabled, creditsPerKToken, updatedAt)` + seeds (chat→`deepseek/deepseek-chat`, vision→`google/gemini-flash-1.5` — placeholders que el owner confirma). `creditsPerKToken` ya incluido para que AI-2 (billing) solo lea.
- **Endpoint PHP `/v1/ai/config`** (GET, `apiAuthTenant(['panel'])`): devuelve `{ chat: {model, creditsPerKToken}, vision: {...} }` desde `ai_model_config WHERE enabled`. El route handler lo consulta para elegir modelo. La UI de edición en /admin es AI-4.
- **Route handler `app/api/agent/chat/route.ts`** (Node runtime):
  - Lee `OPENROUTER_API_KEY`, instancia `createOpenRouter`.
  - Fetch a `${API_URL}/v1/ai/config` forwardeando la cookie del request → modelo de capability=chat.
  - `streamText({ model, system, messages, tools, maxSteps: 5 })` → `.toUIMessageStreamResponse()`.
  - System prompt: asistente de Punto, responde en español, conciso; contexto (companyName, activeOutletName, fecha de hoy) que el client manda en el body (no es security-sensitive — las tools son JWT-scoped server-side).
  - 1 tool `get_sales_summary({ year })`: corre server-side, pega a `${API_URL}/v1/reports/summary_year?y=<year>` forwardeando el `_jwt_panel` del request → hereda permisos del operador. Devuelve los months.
  - SIN débito de créditos (eso es AI-2). Tampoco gate por balance todavía.
- **`components/agent/agent-chat.tsx`** (client): FAB fijo bottom-right + `<Sheet side="right">` con la conversación (mensajes + input), `useChat({ api: "/api/agent/chat", body: { companyName, outletName } })`. Render simple de tool calls (estado "consultando ventas…" + resultado). Wire en `PanelAuthGuard` (junto a RealtimeWire), visible solo con `companyId` presente.
- **Riesgo principal**: API de AI SDK v5 (UIMessage parts, `toUIMessageStreamResponse`, `tool({inputSchema})`). El ejecutor debe lograr streaming mínimo SIN tools primero, confirmar, y recién ahí agregar la tool. Verificar la versión instalada (`node_modules/ai/package.json`) y seguir ESA API.
- **Design system**: tokens del tema; brand verde solo en el FAB y el avatar del agente.

## Detalle AI-3 — alcance acotado + confirmToken

Decisión cerrada (2026-06-21): el agente NO hace todo lo que un humano hace. Alcance MVP fijo (ver memoria [[ai-agent-scope-limits]] con la justificación de cada inclusión/exclusión).

### Tools (13 total)

**Lecturas (5)** — sin gate, ejecución directa:

| Tool | Endpoint backing |
|---|---|
| `find_contact({query, type?})` | `GET /v1/contacts?q=...&type=1\|2` |
| `find_item({query})` | `GET /v1/items?q=...` |
| `get_outlets()` | `GET /v1/outlets` |
| `get_team_members()` | `GET /v1/users` |
| `get_stock({itemQuery})` | `GET /v1/reports/stock?itemQuery=...` (o equivalente — el ejecutor verifica) |

**Escrituras (8)** — patrón confirmToken:

| Tool | Backing | Notas |
|---|---|---|
| `create_contact({type:1\|2, name, phone?, email?, ruc?, ci?})` | `POST /v1/contacts` | type 1=cliente, 2=proveedor |
| `update_contact({id, ...changes})` | `PUT /v1/contacts?id=` | solo campos básicos en `changes` (whitelist) |
| `create_item({kind, name, price, cost?, sku?, categoryName?, brandName?, taxName?})` | `POST /v1/items` | kind ∈ {producto, servicio} ÚNICAMENTE; cat/brand/tax resueltos por nombre vía `getTaxonomyIdOrInsert` (case-insensitive) |
| `update_item_price({id, newPrice})` | `PUT /v1/items?id=` | solo `itemPrice` |
| `create_user({name, phone, roleName})` | `POST /v1/users` | roleName ∈ roles operativos (admin EXCLUIDO) |
| `create_category({name})` | `POST /v1/categories` | UNIQUE protege dups |
| `create_brand({name})` | `POST /v1/brands` | idem |
| `create_tag({name})` | `POST /v1/tags` | idem |

### Patrón confirmToken (server-side) — BATCH desde 2026-07-02

**Actualizado (2026-07-02)**: el shape pasó de "una acción por token" a "un LOTE
de 1+ acciones por token". Motivo: pedir varios ítems en un mismo mensaje
("creá Sprite, Coca Zero y Coca Cola") generaba una llamada a `register_action`
por ítem → 3 confirmaciones separadas en la UI. Ahora el modelo agrupa TODO el
pedido en un solo array `actions` → un solo `confirmToken` → una sola
confirmación → `execute_action` ejecuta el lote completo.

Endpoint `api/v1/ai/confirm.php` (POST, auth panel):
- Body (preferido): `{actions: [{action, payload}, ...], summary}`. Cada
  `action` ∈ las 9 acciones de escritura (agregado `tabular_import`).
  Compat: el shape legacy `{action, payload, summary}` se envuelve
  automáticamente en `actions:[{action,payload}]`.
- Valida CADA elemento del array con `aiConfirmValidateAction()` (función
  reusable, ya no un switch inline de un solo uso).
- Genera `confirmToken`, guarda en Redis `ai:confirm:<token>` el lote completo
  `{actions: [...]}` con TTL 300s.
- Devuelve `{confirmToken, summary, count}`.

Endpoint `api/v1/ai/execute.php` (POST, auth panel):
- Body: `{confirmToken}`.
- Consume el token (GET+DEL atómico, uso único — sin cambios ahí), valida
  `companyId === ctx['companyId']`, itera las acciones del lote y ejecuta
  CADA una vía `aiExecuteRunAction()` (permiso específico por-acción +
  ejecución, refactorizados del switch monolítico que existía antes).
- **Fallo parcial no aborta el resto**: cada acción se ejecuta en su propio
  try/catch: si una falla, las siguientes del lote igual se ejecutan.
- Devuelve `{results: [{action, ok, error?, data?}], okCount, failCount}`.

El front (`frontend/lib/agent/confirm-tool.ts`) refleja el mismo cambio:
`register_action` recibe `actions: z.array({action, payload}).min(1)` en vez
de `action`/`payload` sueltos. `use-agent-chat.ts` adapta la invalidación de
queries (`tokenToActions` como array, invalida por cada acción del lote).

**Render determinístico** (`frontend/components/agent/agent-action-card.tsx`,
nuevo): el front dejó de descartar (`return null`) las tool-parts de
`register_action`/`execute_action` — se renderizan como tarjetas shadcn
(lista de acciones + botones Confirmar/Cancelar; resumen de creados/fallidos).
Antes, el resumen de confirmación dependía 100% de que el modelo lo narrara en
texto libre — con DeepSeek eso degeneraba (texto repetido, fences de código
vacíos `{}` alucinados). La UI ahora es la fuente de verdad del resumen; el
system prompt le pide al modelo no narrarlo. Incluye dedupe de text-parts
consecutivos + strip de fences vacíos como defensa adicional.

**Comportamiento del agente** (en el route handler chat):
1. Tool de escritura → llama `/v1/ai/confirm` con el payload, devuelve `{requiresConfirmation:true, summary, confirmToken}` al modelo.
2. El modelo muestra `summary` al user.
3. User dice "sí" / "ok" / "dale" → el modelo re-llama la MISMA tool con `{confirmToken}` en lugar del payload.
4. La tool detecta que viene `{confirmToken}` (no payload) y llama `/v1/ai/execute`.
5. Resultado: `{success, id, ...}` que el modelo verbaliza.

Si user dice "no" → el modelo descarta. El token expira solo a los 5 min.

### Helper `lib/ai-tools-utils.ts` en frontend

Funciones reusables para las 8 tools de escritura:
- `requestConfirm(action, payload, cookie)` → fetch a `/v1/ai/confirm`
- `executeConfirmed(confirmToken, cookie)` → fetch a `/v1/ai/execute`
- `buildWriteTool(action, schemaCreate, schemaExecute)` → devuelve un `tool({...})` que en el `execute` decide qué endpoint llamar según presencia de `confirmToken`.

Esto evita duplicar boilerplate por las 8 tools.

### Roles operativos (para create_user)

`roleName` debe matchear los roles del tenant que NO son admin. El backend (PHP) valida — si el role pasado es admin o no existe, devuelve 422. El catálogo de roles vive en `taxonomy WHERE taxonomyType='role'` (verificar). El agente puede listar roles con una tool extra `get_roles()` si hace falta, o asumir nombres comunes ("Cajero", "Vendedor") y dejar que el backend valide.

## Detalle AI-3b — Refinement UI del agente (2026-06-21)

Feedback del owner sobre AI-3 entregado: el FAB verde es agresivo (el brand verde es solo para acentos puntuales — ver context/11), el ícono `Bot` es genérico (debe ser burbuja de chat), el input es básico (debería ser estilo ChatGPT con auto-grow + footer de botones), el FAB queda detrás del backdrop blur del modal del menú POS, y falta una página dedicada `/chat` con sugerencias para que el agente sea un destino "first class" del sidebar (no solo overlay).

**Cambios:**

1. **Refactor en 3 piezas** (separar lógica del montaje):
   - `components/agent/agent-chat-content.tsx` — el thread completo (mensajes + input + estados balance/error/sin créditos). Recibe `companyName, outletName` por props. Una sola fuente de UI para FAB y página.
   - `components/agent/agent-chat-floating.tsx` — wrapper FAB + Sheet (lo que hoy es `AgentChat`). Usa `<AgentChatContent>` dentro del Sheet.
   - `app/(panel)/chat/page.tsx` — página fullscreen del panel. Usa `<AgentChatContent>` directo, sin Sheet, con max-w-3xl mx-auto.

2. **Restyling FAB + avatar header:**
   - Color: `bg-foreground text-background hover:bg-foreground/90` (neutro fuerte, contraste óptimo en light/dark). NO `bg-brand`.
   - Ícono: `MessageCircle` de lucide (NO `Bot`).
   - Mismo tratamiento al avatar circular del header del chat.

3. **Input box estilo ChatGPT:**
   - Container: `rounded-2xl border bg-card shadow-sm` con padding interno generoso.
   - `<Textarea>` (de `components/ui/textarea.tsx`) con auto-resize. `rows={1}` y un handler `onInput` que ajusta `el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'` con cap (ej. max-h-40 + scroll).
   - Footer dentro del mismo container, separado del textarea: 3 botones inline:
     - `+` (Plus icon) — placeholder "Adjuntar (próximamente)", disabled con tooltip
     - Micrófono (Mic icon) — placeholder idem
     - Send (Send o ArrowUp icon) — circular `size-9 bg-foreground text-background rounded-full`
   - Enter envía. Shift+Enter agrega salto de línea.
   - Placeholder: "Preguntale al asistente…"

4. **Fix z-index POS — integración al menú principal:**
   - Quitar el FAB completamente cuando `isPos` (no más `fabVisible = !isPos || menuOpen`). El FAB nunca aparece en /pos.
   - Agregar item "Asistente" al array `SECTIONS` de `pos-main-menu.tsx`, con `icon: MessageCircle` y `onSelect: ({setOpen}) => { setOpen(false); useAgentChatStore.getState().open() }`. Cierra el menú del POS Y abre el Sheet del chat (que entonces se monta sobre la pantalla del POS sin conflicto de overlay).
   - Necesita un store mínimo `lib/agent/store.ts`: `{ open: boolean, setOpen, toggle }` (Zustand, igual patrón que `lib/ui/store.ts`).
   - `AgentChatFloating` lee `useAgentChatStore.open` para controlar el Sheet (en lugar de su propio useState).

5. **Sidebar item en frontend:**
   - En `panel-auth-guard.tsx`, `panelNav` array: agregar entre Dashboard y Artículos: `{ title: "Asistente", to: "/chat", icon: MessageCircle }`.

6. **Página `/chat`** (`app/(panel)/chat/page.tsx`):
   - Layout: `flex flex-col h-[calc(100vh-N)]` (N = alto del header del shell), max-w-3xl mx-auto, padding.
   - Si `messages.length === 0`:
     - Header centrado: `<h1 className="text-2xl font-semibold">¿En qué te puedo ayudar?</h1>` con subtítulo discreto.
     - Grid 2-col responsive de chips outline con sugerencias hardcoded (lista abajo). Click → setea el text del input, NO envía. Permite al user editar antes de enviar.
   - Si `messages.length > 0`: la conversación normal, scroll arriba, input abajo.
   - Sugerencias MVP:
     - "¿Cuánto vendí este mes?"
     - "Buscame el cliente Juan"
     - "Creá el producto Café Espresso a 12.000 Gs, categoría Bebidas"
     - "¿Cuánto stock queda del producto X?"
     - "Mostrame las sucursales"
     - "Listame los usuarios del equipo"
     - "Resumen del año pasado"
     - "Creá una categoría llamada Promociones"

7. **FAB visibility refinado** (PanelAuthGuard):
   - Visible: !isPos && pathname !== "/chat"
   - Oculto: isPos (el menú POS expone el chat como item) o pathname === "/chat" (la página ES el chat)

**Trade-off conversación compartida vs independiente:** MVP usa conversaciones INDEPENDIENTES (cada `useChat` en cada montaje tiene su propio state). Significa que si el user arranca en el FAB y abre la página, no continúa la misma conversación. Para v2 se puede mover el state a un store global. Documentar.



- **Tool con permisos del operador**: las tools heredan `_jwt_panel` → el agente NO puede hacer nada que el operador no pueda. Bien. Pero un operador podría pedirle al agente acciones destructivas — por eso confirmación inline en mutaciones (AI-3).
- **Costo runaway**: un loop de tools mal diseñado quema créditos. Mitigación: `maxSteps` en streamText, hard gate por balance, y el débito ocurre por uso real (no estimado).
- **OpenRouter down**: el chat cae pero el panel sigue. Best-effort, mensaje claro.
- **Pricing drift**: `creditsPerKToken` en `ai_model_config` debe mantenerse alineado con el costo real de OpenRouter o el margen se rompe. Owner lo ajusta desde /admin.
- **Prompt injection vía datos**: si el agente lee datos del tenant que contienen instrucciones, podría confundirse. Mitigación: las tools devuelven datos estructurados, no se inyecta texto libre del tenant en el system prompt.

## Próximos slices

### AI-6 — Invalidar caches react-query post-tool del agente

**Problema:** hoy si el agente crea un contacto/item/usuario/etc, el listado abierto en otra ruta o en background NO se refresca. El operador tiene que F5.

**Causa:** `useChat` no le avisa a `queryClient` qué query keys quedaron stale al confirmarse una mutation.

**Solución (cliente-only, sin servidor):**
- En `lib/agent/use-agent-chat.ts`, hook `onFinish` o `onToolResult` del `useChat`.
- Mapeo `action → queryKeys[]`. Whitelist actual de `confirm_action`:
  - `create_contact` / `update_contact` → `[["contacts"]]`
  - `create_item` / `update_item_price` → `[["items"]]`
  - `create_user` → `[["users"]]`
  - `create_category` / `create_brand` / `create_tag` → `[["taxonomies"]]`
- Cubre el 100% de las mutaciones porque todas pasan por `confirm_action`.
- ~40 LOC. Cero impacto servidor (es solo refetch local).

**Trigger:** detectar tool-result del part `confirm_action` con success+action, leer el mapeo, llamar `qc.invalidateQueries({queryKey: k})` para cada key.

### AI-7 — Realtime invalidation tenant-wide (decisión separada)

**Casuística:** si dos usuarios del mismo tenant editan en paralelo (cajero + admin desde panel; o dos cajeros en mesas distintas) y querés que cualquier mutation se vea en vivo en todas las pestañas/usuarios sin F5.

**Aprovecha infra existente:** el sprint 2026-06-21 dejó `useRealtimeSync` + WS singleton + Redis Pub/Sub (canal `<companyId>:checkout:<registerId>` para POS↔checkout-screen). Se generaliza a canal `tenant:<companyId>:invalidate`:

- **Servidor**: cada endpoint de mutation (Items/Contacts/Users/...) publica `wsPublish('tenant:' . COMPANY_ID . ':invalidate', $entityType)` post-success. ~1 línea por endpoint.
- **Cliente**: `useTenantInvalidator()` hook montado en el layout, escucha el canal, mapea entityType → queryKeys, invalida.
- **Costo**: 1 WS conexión por tab + 1 `PUBLISH` Redis por mutation. Manejable (Linear/Notion/Figma operan así con densidad mucho mayor).

**Diferencia con CopilotKit-style**: ellos además streamean el state del agente mid-action (UI parcial mientras la tool corre). Es otra capa de UX, no requiere infra distinta. Slice futuro AI-8 si se quiere ese efecto.

**Decisión pendiente**: hacer AI-7 solo si hay demanda real de multi-usuario concurrente en el mismo tenant. Sin demanda, AI-6 alcanza para el 90% de los casos.

## Cronología de commits

- 2026-06-23 AI-8+AI-9: 200363a — chat attachments infra + tabular import items/contacts
