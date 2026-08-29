# Hand-off — 2026-08-29 (3)

## Objetivo
Responder al informe de bugs del tester (documento del 2026-08-28, 7 ítems) y
diseñar cómo le llega al cliente el PDF de la factura electrónica. Reacción a lo
que se rompió en uso real, no roadmap planificado.

## Estado al cerrar
`origin/main` en `ebef4373`, todo pusheado y **deployado** — Front y Backend
corren ese commit, `running:healthy` (verificado por MCP de Coolify). Sin
migraciones nuevas. De los 7 ítems: 4 resueltos, 1 ya lo estaba, 2 abiertos.

**Ningún commit lo vio un humano todavía.**

⚠ El auto-deploy está APAGADO: el push no deploya, lo dispara la sesión con
`mcp__coolify__deploy` (ver `CLAUDE.md` §Deploy). Ojo que ahí el tool figura como
`mcp__Coolify_MCP__deploy` con `tag_or_uuid` y **es incorrecto**: es
`mcp__coolify__deploy` con `uuid`. `list_deployments` + `application_uuid` sí
devuelve historial (la doc dice que no) — así se verifica un deploy sin SSH.

## Archivos y cambios
- `frontend/lib/hardware/printers/html-renderer.ts` — `pushDown` fuera del
  camino de HOJA; `sheetRowHeightPx()`; `positioned()` acepta `lineHeightPx`.
- `frontend/lib/hardware/printers/blocks.ts` — `item_total_if_rate`;
  `ItemFieldResolver` recibe el bloque.
- `frontend/lib/print-template-palette.ts` — "Logo (B&W)" eliminado;
  `TAX_RATE_BLOCK_TYPES`.
- `frontend/hooks/use-transactions.ts` — fetchers por `posApi`, exportados.
- `frontend/hooks/use-pos-transactions.ts` — delega en `useTransaction`.
- `api/lib/Sales/SaleInput.php` + `SaleService.php` — `quoteParentId` y escritura
  de `transaction_link` kind `quote_to_sale`.
- `api/lib/Reports/TransactionsService.php` — `quoteStatus()`/`billedQuoteIds()`.
- `api/lib/Reports/OpenInvoicesService.php` + `api/v1/reports/open_invoices.php`
  — scope por sucursal, opt-in, solo en `general()`.
- `context/57-entrega-digital-del-kude.md` — NUEVO, plan cerrado sin implementar.
- Arneses: `api/tests/{open_invoices_outlet_scope,quote_status}_test.php` + sus
  `run_*.sh` (6/6 y 9/9).

## Callejones sin salida
- **`psql` contra prod lo bloquea el classifier.** No insistir: verificar por MCP
  de Coolify, o con arnés local (los `api/tests/run_*.sh` levantan Postgres en
  Docker solos — usarlos de plantilla).
- **`verify_chain` de impresión no corre acá**: pide Postgres local y su `run.sh`
  no existe, aunque el docblock de `run.mjs` lo referencia.
- **Tipar una fila del DB layer como `array` revienta**: son
  `CaseInsensitiveArray`. Se resolvió pasando escalares, no aflojando el hint.
- **`vitest` ignoraba `hooks/`** — un test ahí daba "No test files found" sin
  decir por qué. Se amplió el `include`.
- Diagnóstico errado del detalle vacío: se culpó al cast sin validar. Era el
  síntoma; la causa es que DOS hooks escribían la queryKey
  `["pos-transaction", id]` con formas distintas.

## Próximo paso
**Facturación electrónica: cerrar los caminos sin probar** — es lo que bloquea
vender en Paraguay. Los dos más baratos van contra la cuenta DEV que YA funciona
(emisión verificada 2026-07-30, SIFEN aprobó): una venta con **línea exenta** y
otra con **pago dividido** (preguntas 10 y 12 de `context/28`). Leer
`context/28` §"Verificado contra la API real" antes de tocar.

Antes: confirmar con el owner que hay un tenant conectado a la cuenta DEV. Las 4
env vars del backend están cargadas (verificado), pero no se pudo consultar
`einvoice_account` en prod.

## Trampas conocidas
- **Cambió el render de TODA plantilla A4/Carta/Legal guardada.** Es la
  corrección —ninguna imprimía bien con más de un ítem— pero se ve distinto.
- **Los ítems de hoja que no entran DESBORDAN**, no se recortan. Decisión
  explícita; la paginación real es `context/56`.
- **Las cotizaciones viejas figuran todas "Pendiente"**: el vínculo se escribe
  desde `ebef4373`, lo anterior no se recupera. No es bug.
- **"Vencida" no la pidió el owner**, se agregó porque sale del mismo
  `transactionDueDate`. Se saca en una línea de `quoteStatus()`.
- **Ítem 5 del tester (stock) NO resuelto y puede no ser bug**: el endpoint
  scopea saldos/ubicaciones/desglose; company-wide es solo la query de `item`,
  que lista los 2000 ítems aunque no tengan movimiento (aportan `onHand=0`, así
  que el costo probablemente ya salga bien). **Necesita que el owner mire datos
  reales** — el fix cambia según si el total está mal o es solo ruido.
- **Ítem 3 del tester ("Ver PDF" del presupuesto)** = `context/56`, proyecto de
  varias horas, no un fix.
- Pendiente de antes: 6 P2 de la auditoría del 2026-08-26, WebSocket de realtime
  sin auth, TZ `America/Asuncion` literal en migs 157/160 y `period-close.php`,
  y el owner imprimiendo un ticket con logo en térmica FÍSICA (todo el pipeline
  se verificó solo en browser).
