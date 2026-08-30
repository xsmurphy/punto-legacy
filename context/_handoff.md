# Hand-off — 2026-08-29 (3)

## Objetivo
Responder al informe de bugs del tester (documento del 2026-08-28, 7 ítems),
diseñar cómo le llega al cliente el PDF de la factura electrónica, y planificar
un MCP server para que el tenant conecte Claude u otro cliente a sus datos.

## Estado al cerrar
`origin/main` en `d56d8ed2`. Todo pusheado. **Deployado hasta `03b6527a`** —
Front y Backend corren ese commit, `running:healthy` (verificado por MCP de
Coolify, no asumido). `d56d8ed2` es solo markdown: no necesita deploy.

Sin migraciones nuevas en toda la sesión.

**Ningún commit lo vio un humano todavía.**

De los 7 ítems del tester: 4 resueltos, 1 ya lo estaba, 2 abiertos (ver Trampas).

⚠ El auto-deploy está APAGADO: el push no deploya, lo dispara la sesión con
`mcp__coolify__deploy` (ver `CLAUDE.md` §Deploy, corregido esta sesión — antes
nombraba un tool inexistente).

| App | UUID |
|---|---|
| Punto Front | `nzmay2ytcdup3sgylspq39z6` |
| Punto Backend | `z645wx54kwtcciczaeoldwvc` |

## Archivos y cambios
**Impresión / hoja A4** (`939341ee`, `a72dbd68`)
- `frontend/lib/hardware/printers/html-renderer.ts` — `pushDown` ELIMINADO del
  camino de hoja; `sheetRowHeightPx()`; `positioned()` acepta `lineHeightPx`.
- `frontend/lib/hardware/printers/blocks.ts` — `item_total_if_rate` (resolver +
  `ITEM_LINE_TYPES`); `ItemFieldResolver` recibe el bloque.
- `frontend/lib/print-template-palette.ts` — "Logo (B&W)" eliminado;
  `TAX_RATE_BLOCK_TYPES`.

**POS** (`9ebbaf15`, `03b6527a`)
- `frontend/hooks/use-transactions.ts` — los dos fetchers por `posApi`.
  **Exportados** para el guard.
- `frontend/hooks/use-pos-transactions.ts` — `usePosTransactionDetail` delega en
  `useTransaction`; ya no tiene fetcher propio.
- `frontend/components/register/{product-search-dialog,customer-dialog}.tsx` —
  `slide-in-from-top-4` en el `DialogContent`.
- `frontend/vitest.config.ts` — `include` suma `hooks/**/__tests__/**`.

**Reportes / cotizaciones** (`6ad25670`, `ebef4373`)
- `api/lib/Sales/SaleInput.php` + `SaleService.php` — `quoteParentId` y
  escritura de `transaction_link` kind `quote_to_sale`.
- `api/lib/Reports/TransactionsService.php` — `quoteStatus()`/`billedQuoteIds()`.
- `api/lib/Reports/OpenInvoicesService.php` + `api/v1/reports/open_invoices.php`
  — scope por sucursal, opt-in y solo en `general()`.

**Planes nuevos** (`6785fe88`, `3aa59041`, `d56d8ed2`)
- `context/57-entrega-digital-del-kude.md` — email ahora, WhatsApp diferido.
- `context/58-mcp-server.md` — D1-D3 cerradas por el owner, **D4-D13 propuestas
  SIN su OK**.
- `context/20-design-system.md` §Overlays — duraciones y la trampa del content
  transparente.

**Arneses nuevos**: `api/tests/{open_invoices_outlet_scope,quote_status}_test.php`
con sus `run_*.sh` (6/6 y 9/9).

## Callejones sin salida
- **El `psql` contra la BD de prod lo bloquea el classifier.** No insistir:
  verificar por MCP de Coolify, o con arnés local (los `api/tests/run_*.sh`
  levantan Postgres en Docker solos — usarlos de plantilla).
- **El arnés `verify_chain` de impresión no corre acá**: pide Postgres local Y
  su `run.sh` no existe, aunque el docblock de `run.mjs` lo referencia.
- **Tipar una fila del DB layer como `array` revienta en runtime**: son
  `CaseInsensitiveArray` (`Query.php`). Lo cazó el arnés con un TypeError. La
  salida fue pasarle escalares, no aflojar el hint.
- **En Tailwind v4 las utilidades de translate escriben la propiedad
  `translate`, NO `transform`.** Verificado en el CSS compilado. El keyframe
  `enter` de las animaciones anima `transform`, así que NO pisa el centrado de
  los diálogos: ningún modal se desplaza al abrir, solo hay fade + scale. Dos
  hipótesis se cayeron por esto — no perder tiempo re-derivándolo.
- **`vitest` ignoraba todo fuera de `lib/**`** — un test en `hooks/__tests__/`
  daba "No test files found" sin decir por qué.
- Diagnóstico errado del detalle vacío: se culpó al cast sin validar. Era el
  síntoma; la causa es que DOS hooks escribían la queryKey
  `["pos-transaction", id]` con formas distintas.

## Próximo paso
**Facturación electrónica: cerrar los caminos sin probar** — es lo que bloquea
vender en Paraguay. Los dos más baratos van contra la cuenta DEV que YA funciona
(emisión verificada 2026-07-30, SIFEN aprobó): una venta con **línea exenta** y
otra con **pago dividido** (preguntas 10 y 12 de `context/28`). Leer
`context/28` §"Verificado contra la API real" antes de tocar.

Antes: **confirmar con el owner que hay un tenant conectado a la cuenta DEV.**
Las 4 env vars del backend están cargadas (verificado por MCP), pero no se pudo
consultar `einvoice_account` en prod.

## Trampas conocidas
- **Cambió el render de TODA plantilla A4/Carta/Legal guardada.** Es la
  corrección —ninguna imprimía bien con más de un ítem— pero se ve distinto.
- **Los ítems de hoja que no entran DESBORDAN**, no se recortan. Decisión
  explícita; la paginación real es `context/56`.
- **La animación de los buscadores NO se verificó visualmente** — el análisis
  salió de leer el CSS compilado; en esta máquina no se levantan dev servers. Si
  el recorrido queda corto, la palanca es el `-4` (subir a `-6`/`-8`), no la
  duración: `duration-100` es convención de la familia de overlays y está
  documentada en `context/20`.
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
- **`context/58` (MCP) NO es lo próximo**: su prerequisito real es la F0 de
  `context/47` (catálogo + ejecutor), y va después de FE y de cerrar auth.
- Pendiente de antes: 6 P2 de la auditoría del 2026-08-26, WebSocket de realtime
  sin auth, TZ `America/Asuncion` literal en migs 157/160 y `period-close.php`,
  y el owner imprimiendo un ticket con logo en térmica FÍSICA (todo el pipeline
  se verificó solo en browser).
