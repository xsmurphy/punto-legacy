# Roadmap: Agente IA como capa transversal del ERP

> Norte de largo plazo para el agente (2026-07-30). Extiende
> [17-ai-agent-plan.md](17-ai-agent-plan.md) (chat + tools básicas, implementado)
> hacia un agente que opera sobre TODO el ERP: OCR de facturas, carga de menús,
> CRUDs generales, análisis y web search. Este doc fija arquitectura y fases;
> cada fase se refina en su momento, no se implementa nada de acá sin plan
> propio del slice.

## Por qué ahora

Hoy el route handler (`frontend/app/api/agent/chat/route.ts`, ~15 tools inline)
manda TODAS las tools en cada request. Con 15 schemas chicos funciona; con los
módulos que vienen (compras con OCR, catálogo masivo, recetas, finanzas, web
search) el catálogo va a superar las 40-60 tools y eso rompe tres cosas a la
vez: prompt inflado (costo + latencia), peor selección del modelo (más tools =
más confusión con modelos económicos), y un archivo route.ts inmantenible.

## Principios (cerrar con el owner antes de la F1)

1. **Floor tools + dominios lazy.** Un set fijo SIEMPRE presente (piso) y el
   resto agrupado por dominio, cargado solo cuando la tarea lo pide.
2. **El agente no inventa permisos.** Toda tool ejecuta con la sesión del
   usuario (cookie panel) contra los endpoints REST existentes — el agente
   NUNCA tiene un camino a datos que el usuario no podría ver a mano. Los
   guardrails de AI-3 (scope acotado, confirmToken para escrituras, sin
   destructivos) siguen vigentes tal cual.
3. **Tools = wrappers finos de endpoints.** Prohibido meter lógica de negocio
   en la tool; si falta un endpoint, se crea el endpoint (regla global
   arquitectura-no-parches). Así el agente crece al ritmo del API y no en
   paralelo.
4. **Model-agnostic vía OpenRouter** (sin cambios): capabilities por tarea en
   `ai_model_config` (chat / vision / futuro: extraction, search).
5. **Billing único**: todo débito pasa por `ai_credit_ledger` con `reason`
   distinguible (`agent_chat`, `doc_ocr`, `web_search`, …) para poder pricear
   distinto por capability.

## Arquitectura de tools — dos etapas (router → dominio)

### Registry por dominio

`frontend/lib/agent/tools/` — un archivo por dominio, cada uno exporta su set:

| Dominio | Tools (ejemplos) | Estado |
|---|---|---|
| `core` (floor) | get_page_context, search_entities (búsqueda unificada cliente/producto/venta), register_action, execute_action | existe disperso |
| `sales` | get_sales_summary, get_transactions, get_open_invoices | existe |
| `catalog` | get/create/update items, categorías, marcas, recetas, variantes | parcial |
| `contacts` | CRUD contactos, deuda, historial | parcial |
| `finance` | summary, movements, checks, cuentas | existe |
| `inventory` | stock, ajustes, transferencias, conteos | no |
| `purchases` | compras, OCR de factura (ver F3), proveedores | no |
| `analysis` | rollups, comparativas por período, top-N | parcial |
| `web` | web_search (ver F4) | no |

### Router (etapa 1)

Primera llamada barata (mismo modelo chat, `max_tokens` chico, sin tools o con
una única tool `select_domains`): clasifica el mensaje + últimos N turnos en
1-3 dominios. La etapa 2 arma `tools = floor + dominios elegidos` y corre la
conversación real. Reglas:

- El router NO responde al usuario; si la clasificación es ambigua, la etapa 2
  sale con floor + los 2 dominios más probables (nunca preguntar "¿de qué
  dominio es tu consulta?").
- Los dominios elegidos se persisten en el hilo (`chat-history-store`) y se
  acumulan: una conversación que ya tocó `catalog` mantiene `catalog` cargado
  hasta el clear. Evita re-clasificar cada turno y el ping-pong de sets.
- Escape hatch: una floor tool `request_domain(domain)` — si el modelo nota que
  le falta un dominio (el usuario cambió de tema), lo pide y el handler reintenta
  con el set ampliado. Cubre el error de clasificación sin round-trip al user.
- Cache: DeepSeek/OpenRouter cachean prefijo — mantener las tools ORDENADAS
  determinísticamente (floor primero, dominios alfabéticos) para maximizar hits.

### Umbral de activación

El router se activa recién cuando el catálogo supere ~20 tools o al sumar el
primer dominio nuevo grande (purchases/inventory). Hasta entonces, mandar todo
sigue siendo lo correcto — no pagar complejidad antes de necesitarla.

## Fases

### F0 — Registry (refactor sin cambio de comportamiento)

Partir `route.ts` (655 líneas) en `lib/agent/tools/<dominio>.ts` + un
`buildToolset(domains)` que hoy devuelve todo. Deja el terreno listo para el
router sin tocar UX. Incluye mover el system prompt a un builder por secciones.

### F1 — Router de dominios

Lo de arriba. Métrica de éxito: tokens de prompt por request ~constantes al
crecer el catálogo de tools; cero regresiones en los flujos de AI-3
(confirmToken batch intacto).

### F2 — Documentos entrantes (OCR / vision)

Caso 1: **factura de compra** (foto/PDF) → capability `vision` extrae
{proveedor, timbrado, items[], totales} → tool `register_action` con
`purchase_import` → confirmación → POST al endpoint de compras.
Caso 2: **menú en PDF** → extracción de items {nombre, precio, categoría} →
`tabular_import` (ya existe el patrón con sessionId para XLSX/CSV — reusar ese
pipeline, la vision solo produce las filas).
Regla: la extracción SIEMPRE pasa por la card de confirmación con preview
editable; nunca insert directo desde vision.

### F3 — CRUDs por sectores

Expandir dominios con el mismo patrón AI-3 (register/execute + allowlist).
Orden sugerido por valor: purchases → inventory (ajustes con motivo) →
recetas/producción → finanzas (movimientos manuales). Sigue prohibido:
ventas/caja, permisos, bulk-delete, hard-delete (guardrails AI-3).

### F4 — web_search

Server-side en el route handler (nunca browser): proveedor con API simple
(Brave/Tavily/Exa — decidir por precio; OpenRouter también ofrece `:online`
como atajo, evaluarlo primero porque no suma proveedor nuevo). Gate por
créditos con `reason='web_search'`. Uso: precios de referencia, datos de
proveedores, normativa. El system prompt debe exigir citar la fuente.

### F5 — Análisis profundo

Tools sobre los rollups (context/18) para comparativas largas sin reventar el
contexto: `compare_periods`, `top_movers`, `trend`. El agente consume agregados,
no filas crudas — límite duro de filas por tool result (hoy ya hay límite de
100 en movements; formalizarlo en todas).

## Qué NO va a ser el agente

- No opera la caja (vender/cobrar/anular es del cajero, no del chat).
- No toca permisos/roles/billing.
- No es un workflow engine: una tarea = una conversación con confirmaciones;
  nada de "corré esto todas las noches" (eso es de crons/rollups).

## Preguntas abiertas (cerrar antes de cada fase)

1. F2: ¿la extracción de facturas arranca por compras o por gastos de caja?
2. F4: ¿web search visible para todos los tenants o flag por plan?
3. ¿Créditos: precio distinto por capability (vision/search más caros) — tabla
   `ai_model_config.creditsperktoken` ya lo soporta, falta decidir valores?
4. ¿El router usa el mismo modelo de chat o uno aún más barato dedicado?
