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

{ supplier: { name, ruc }, invoice: { number, timbrado, date,
  condition: 'contado'|'credito', dueDate? }, items: [ { description,
  quantity, unitPrice, total, ivaRate: 0|5|10 } ], totals: { subtotal,
  iva5, iva10, total }, confidence: 0..1 }

Campos que la IA no pudo leer → null (nunca inventar). `confidence` bajo →
banner de advertencia en la UI de revisión.

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

## Fuera de alcance v1

PDF multipágina, detección de duplicados por nro+timbrado (solo warning si
ya existe una compra con mismo nro de factura del mismo RUC), auto-aprobar
por confidence alta, facturas de venta (esto es SOLO compras).
