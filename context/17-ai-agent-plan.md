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
┌─ Browser (panel-next) ───────────────────────┐
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

- El **route handler vive en panel-next** (`app/api/agent/chat/route.ts`), NO en PHP. Tiene la `OPENROUTER_API_KEY` (server-side, nunca al browser). Reusa el `_jwt_panel` del request para que las tools hereden los permisos del operador.
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
| AI-3 | **Tools de escritura** — `create_item`, `find_contact`, `create_contact`, con confirmación inline | El agente opera datos |
| AI-4 | **Config /admin** — UI de `ai_model_config`, selección por capability | Owner ajusta modelos sin deploy |
| AI-5 | **Capabilities extra** — OCR (subir foto de factura → Gemini extrae items), análisis (queries libres sobre el rollup) | Casos de alto valor |

### Detalle AI-1 (primer slice) — refinado 2026-06-21

Stack confirmado: Next 16.2.6, React 19.2.4, npm. AI SDK **v5**.

- **Deps** (npm): `ai`, `@ai-sdk/react`, `@openrouter/ai-sdk-provider`. **NO assistant-ui en AI-1** — el chat se arma hand-rolled con `useChat` de `@ai-sdk/react` + shadcn `Sheet`, para evitar el churn de API de assistant-ui en el slice fundacional. assistant-ui queda como mejora futura si se quiere UI más rica.
- **Env**: `OPENROUTER_API_KEY` (Coolify, servicio panel-next, server-side — nunca al browser).
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

## Riesgos

- **Tool con permisos del operador**: las tools heredan `_jwt_panel` → el agente NO puede hacer nada que el operador no pueda. Bien. Pero un operador podría pedirle al agente acciones destructivas — por eso confirmación inline en mutaciones (AI-3).
- **Costo runaway**: un loop de tools mal diseñado quema créditos. Mitigación: `maxSteps` en streamText, hard gate por balance, y el débito ocurre por uso real (no estimado).
- **OpenRouter down**: el chat cae pero el panel sigue. Best-effort, mensaje claro.
- **Pricing drift**: `creditsPerKToken` en `ai_model_config` debe mantenerse alineado con el costo real de OpenRouter o el margen se rompe. Owner lo ajusta desde /admin.
- **Prompt injection vía datos**: si el agente lee datos del tenant que contienen instrucciones, podría confundirse. Mitigación: las tools devuelven datos estructurados, no se inyecta texto libre del tenant en el system prompt.

## Cronología de commits

(Se completa a medida que se ejecuta cada slice.)
