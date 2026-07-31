# OCR de facturas de compra (foto → borrador → aprobar)

> Plan cerrado con el owner el 2026-07-31. Decisión NO relitigable:
> v1 = foto(s) → extracción IA → **borrador pendiente de aprobación** →
> revisión/corrección → aprobar crea la compra real (stock + finanzas).
> Nada entra a stock/finanzas sin aprobación humana.

## Arquitectura (sigue el patrón del agente IA existente)

- **Extracción**: ruta BFF Next (`frontend/app/api/ocr-invoice/route.ts`),
  patrón de `app/api/agent/chat/route.ts` — llama OpenRouter con el modelo de
  capability **`vision`** de `ai_model_config` (ya existe, mig 98). Prompt
  con salida JSON estricta (schema abajo). NO Anthropic SDK.
- **Billing**: debita créditos vía `/v1/ai/debit` con capability `vision`,
  mismo flujo que el chat. Sin créditos → error claro antes de subir.
- **Storage**: la imagen se guarda como las imágenes de items (mismo
  mecanismo de `api/v1/items.php`) — referencia en el draft.
- **Borradores**: tabla nueva `purchase_draft` (mig 105):
  draftid uuid PK, companyid, outletid, userid, status
  ('pending'|'approved'|'rejected'), imageref text, extracted jsonb (el JSON
  crudo de la IA), edited jsonb (correcciones del usuario), contactid uuid
  NULL (proveedor matcheado), transactionid uuid NULL (compra creada al
  aprobar), error text, created_at, approved_at. Índice (companyid, status).
- **Aprobación**: endpoint PHP `/v1/purchase-drafts.php` (list/get/update/
  approve/reject, permiso el mismo del registro de compras). `approve`
  reusa `PurchasesService::create` con el payload editado — el draft NUNCA
  escribe stock/finanzas por su cuenta; al crear guarda `transactionid` y
  pasa a `approved` (idempotente: draft aprobado no se re-aprueba).

## JSON de extracción

{ supplier: { name, ruc }, receiver: { ruc, name }, invoice: { number,
  timbrado, timbradoStart, timbradoEnd, date, condition: 'contado'|'credito',
  dueDate?, isElectronic, cdc }, items: [ { description, quantity,
  unitPrice, total, ivaRate: 0|5|10 } ], totals: { subtotal, exempt,
  discount, iva5, iva10, total }, currency, isInvoice, receiverMatchesTenant,
  confidence: 0..1 }

Campos que la IA no pudo leer → null (nunca inventar). `confidence` bajo →
banner de advertencia en la UI de revisión.

## Criterios de extracción

Técnicas tomadas del pipeline de facturas de Urban Domus (proyecto hermano
del owner, mismo dominio: facturas de proveedores paraguayos), adaptadas a
multi-tenant. Implementado en `EXTRACTION_PROMPT` / `buildExtractionPrompt`
(`frontend/app/api/ocr-invoice/route.ts`).

- **Guía espacial por bloques** — el prompt le dice al modelo dónde suele
  estar cada dato, que es lo que más sube la precisión: Bloque A (superior,
  timbrado + nro factura + RUC/razón social emisor), Bloque B (centro o pie
  en tickets: fechas, RUC/razón social/dirección del cliente, condición de
  venta), Bloque C (centro, detalle de ítems), Bloque D (inferior, totales/
  IVA/moneda). Si un dato se repite, prioriza el bloque que le corresponde.
- **Formatos PY**: nro factura `XXX-XXX-XXXXXXX` (3-3-7, agregar guiones si
  vienen corridos), timbrado 8 dígitos exactos, RUC hasta 8 dígitos + guion
  + dígito verificador, números con punto decimal, montos PYG enteros sin
  separadores, fechas `YYYY-MM-DD`.
- **Identificación del documento**: válida solo si contiene "factura" o
  "timbrado" (o variantes) → `isInvoice`. No bloquea el resto de la
  extracción si es `false`.
- **Verificación de destinatario multi-tenant**: el prompt de referencia
  hardcodea una allowlist de 3 RUCs — acá eso NO existe. Se resuelve el RUC
  de la sucursal (`outlet.ruc`, vía `GET /v1/outlets?id=`) por request; si no
  hay RUC cargado, se omite la sección del prompt entera. `receiver.ruc` lo
  extrae el modelo, pero `receiverMatchesTenant` SIEMPRE se recalcula en
  código (`rucsMatch` en route.ts) — nunca se confía en la comparación del
  LLM. `false` → warning no bloqueante en la UI, nunca invalida la factura.
- **Regla de nulls (NO se copia del proyecto de referencia)**: Urban Domus
  rellena defaults inventados (timbrado `11111111`, descripción `SERVICIOS
  PRESTADOS`, precision fija 95) para nunca devolver vacío. Para Punto es
  inaceptable — el borrador lo aprueba un humano y un default inventado se
  cuela como si fuera un dato real leído de la factura. Campo ilegible →
  `null`, siempre. Único default real: `currency` = "PYG" cuando no se
  detecta explícitamente (es la moneda del tenant, no un dato de la
  factura), aplicado en código, no pedido al modelo.
- **Validación aritmética post-extracción** (código, no prompt — la IA no
  valida sus propias sumas): `PurchaseDraftService::computeWarnings()`
  compara suma de `items[].total` vs `totals.subtotal`, y `subtotal + iva5 +
  iva10 - discount` vs `totals.total`, tolerancia ±1 (redondeos). Se
  recalcula en cada lectura a partir de `extracted` (nunca se persiste, una
  sola fuente). Discrepancia → `warnings: string[]` en el draft, banner no
  bloqueante en la pantalla de revisión — el humano decide igual.

**Fuera de alcance (decisión pendiente del owner)**: el preprocesado de
imagen que usa Urban Domus (servicio externo `invoice-cleaner.actuo.app`,
API key propia) no se adoptó — requiere decisión de costo/credenciales.

## UI

- En el registro de compras: botón "Subir factura" (foto/imagen, múltiple)
  junto al alta manual. Cada upload crea un draft y dispara la extracción.
- Lista de borradores pendientes (badge con count) — cada uno abre la
  pantalla de revisión: imagen al lado del form prellenado (form de compra
  existente como base), match de proveedor por RUC contra contacts
  (sugerencia automática, corregible), condición contado/crédito, items
  editables. Acciones: Aprobar (crea la compra) / Rechazar.
- Ítems: v1 NO exige match contra el catálogo — la compra acepta líneas
  libres igual que el form manual actual. (Match item→catálogo para stock:
  igual que lo que haga hoy el form manual; no inventar comportamiento nuevo.)

## PDF (2026-07-31)

Los proveedores mandan la mayoría de las facturas por correo en PDF — v1
solo aceptaba imagen, esto lo cierra:

- **UI**: input de subida acepta `image/*,application/pdf`
  (`frontend/app/(panel)/purchase/page.tsx`).
- **Extracción**: el BFF (`frontend/app/api/ocr-invoice/route.ts`) manda el
  PDF como parte `{ type: "file", data: dataUrl, mediaType: "application/
  pdf" }` (AI SDK `FilePart`, `@ai-sdk/provider-utils`) en vez de `{ type:
  "image" }`. El modelo (`google/gemini-3.5-flash` vía OpenRouter) lo lee
  nativo, **incluido multipágina** — NO se convierte a imagen ni se parte
  por página, un PDF entero = una parte = un borrador. `maxOutputTokens`
  subido de 2000 a 8000 (una factura con muchos ítems, o un PDF
  multipágina, truncaba la respuesta con el límite viejo). El prompt
  (`buildExtractionPrompt`) agrega una sección condicional para PDF: si
  trae texto seleccionable (la mayoría de los PDF de correo son digitales,
  no escaneados), transcribir los valores EXACTOS sin reinterpretar ni
  redondear.
- **Storage**: `PurchaseDraftService::uploadImage()` (api/lib/Purchases/) es
  un dispatcher por mime real (`finfo`, nunca el Content-Type declarado):
  imagen → pipeline de siempre (resize 2000px + recompresión JPEG q90); PDF
  → passthrough, se guarda tal cual sin GD/resize/recompresión. Límite de
  8 MB para ambos tipos. `imageref` (columna) y el campo `image` del
  multipart siguen llamándose así aunque ahora puedan contener un PDF — no
  se renombró para evitar un refactor de schema innecesario.
- **UI de revisión**: `/purchase/drafts/[id]` detecta PDF por extensión del
  object key (`.pdf` — el backend fuerza esa extensión al subir) y muestra
  un `<iframe>` + link "Abrir en pestaña nueva" en vez de `<img>`. La lista
  `/purchase/drafts` muestra un ícono placeholder para PDF en la columna de
  thumbnail en vez de un `<img>` roto.

## Fuera de alcance v1

Detección de duplicados por nro+timbrado (solo warning si ya existe una
compra con mismo nro de factura del mismo RUC), auto-aprobar por confidence
alta, facturas de venta (esto es SOLO compras).
