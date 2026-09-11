# Hand-off — 2026-09-10

Dos sesiones paralelas cerraron hoy. Dos frentes, sin overlap de archivos.

## Objetivo

**Frente A — Facturación/FE-PY** (sesión "Punto"): cerrar pendientes de
facturación electrónica (badge anulada, serie NC, `INTERNAL_RENDER_KEY`).
Terminó en dos incidentes reales en prod que forzaron al owner a eliminar
Factomate entero y dar de baja el KuDE propio.

**Frente B — Panel: reportes y UI** (esta sesión): reorganizar los reportes
del panel (Artículos/Finanzas), corregir 2 bugs de backend en reportes
(medios de pago 500, control de cajas), y arrancar Producción F1.

## Estado al cerrar

Backend HEAD `25f97d6a` — deploy `udejiig95n2nyzkkabkpbjda` **finished**,
verificado (`running:healthy`). Front HEAD `25f97d6a` — deploy
`f1hnoo5n92h2gj0tfhdmj1eh` **in_progress** al cerrar esta sesión: confirmar
`finished` (`mcp__coolify__get_deployment`) antes de asumir que estos
cambios (de los dos frentes) están en prod. Migs 214-217 (Frente A) y
209/212/213 (Frente B) aplicadas y verificadas.

## Archivos y cambios

### Frente A — Facturación / FE-PY
- Factomate eliminado, FE-PY único motor (`eccf3d1b`, mig 214).
- NC con serie propia heredando la caja de la factura (`418b679c`, mig 215).
- `provider_txn_id` propio + reconsulta antes de reintentar (`f02477c8`, mig 217).
- `KudeService::payload()` vuelve a leer del proveedor; render propio apagado (`context/73` SUPERSEDED).
- `printableDocumentFor()` excluye rechazados por `sifen_status` (`0a35e91a`).

### Frente B — Panel: reportes y UI
- Design system: `--table-band` literal en `TableHeader`, `StatTile.delta`, `FormSectionColumns`, fix scroll modal settings (`context/20` changelog).
- `/reports/products`→"Artículos" absorbe categorías/marcas por tab; Servicios se separa por `item.itemKind` (`9c56cae9`).
- Charts reusables `RankingBarChart` (utilidad superpuesta) + `CompositionDonutChart` (cola en "Otras").
- `/finanzas/reportes` → `/reports/finance-breakdown` (redirect); medios de pago pasa a "Finanzas y caja".
- Fix 500 medios de pago: prefijo CONGELADO de la transacción, no `register.registerinvoiceprefix` (mig 209).
- `DrawersService` asigna ventas por `drawerid` como el cierre en vivo, no solo por fecha.
- Producción F1 (`context/76`): `/reports/production` tabs Dashboard·Productos·Consumos·Mermas·Órdenes.
- Conteo de stock: cajero lo genera desde la caja (D10 en `context/63`, mig 213).
- Impresión: mig 212 limpia títulos duplicados de HOJA; `nums_to_words` (`frontend/lib/number-to-words-es.ts`).

## Callejones sin salida

### Frente A
- KuDE propio: entregó un comprobante VACÍO a un cliente real (payload nunca migrado de Factomate a FE-PY). No reabrir.
- Deployar un fix sin abrir lo que produce — causó el incidente de arriba.
- Idempotency-Key estable + payload que cambia en un deploy = reintento envenenado. La garantía real es el índice único de FE-PY.
- Un RECHAZADO tiene CDC y QR igual (se calculan antes de SIFEN) — filtrar por "tiene CDC" no sirve.
- DB prod: container `w6rtfxm2n6l45r4r9melj3hl` (Postgres 18); `docker exec ... psql -c` con comillas anidadas falla, usar heredoc.

### Frente B
- Deployar SQL de reporte sin correrlo contra Postgres real: `e7ee53d2` salió con columnas inexistentes (`pos_order.spaceid`, `space.tablename`) → 500 en prod; arnés propio dio 14/24 fallas. Arnés ANTES de commitear SQL nuevo.
- Separar Productos/Servicios por `itemTrackInventory`: metía combos y producción en Servicios. El dato correcto es `item.itemKind`.
- Unificar "Más facturado"/"Más vendido" en un chart de eje dual: descartado, no comparten unidad y el eje dual engaña.
- `--table-band` con `color-mix(oklch)`: daba gris correcto, se pasó a literal por legibilidad; el tinte cálido que vio el owner en dark no quedó explicado.

## Próximo paso

**Frente A**: probar una devolución con la serie nueva — confirmar NC
`001-002-0000001` coincide en KuDE y email. Después confirmar que el Front
terminó de deployar, texto de `leyendaDocumento`, limpiar worktrees.

**Frente B**: el owner tiene que elegir la clave de permiso para
`GET /v1/orders-core` en el realm panel (propuestas: `orders.view` nueva —
**el owner dijo "no"** — o reusar `reports.sales.view`, que rompe la pestaña
Órdenes de la ficha del cliente). Con eso, gatear el endpoint en la branch
`frontend/orders-dashboard` (@ `6d943ecb`, worktree
`.claude/worktrees/agent-abca48ff55d9b3740`, P0 de `code-reviewer`: hoy
CUALQUIER usuario del panel lee cualquier orden del tenant), correr
`bash api/tests/run_operations_report_test.sh`, review, merge y deploy.

## Trampas conocidas

1. (Frente A) `logoUrl` de Balloon Party cargado a mano en FE-PY; facturas 615/617/619 siguen `issued` sin anular (no es olvido de esta sesión); disco lleno 98% durante la sesión, worktrees viejos siguen ocupando ~9GB.
2. (Frente B) El bootstrap del POS sigue mandando `stockCountLists` (deprecado) a propósito, para devices con JS viejo en caché. Stack Docker local `api-api-1` en crash-loop — es entorno local, no prod.
3. (Ambos) Worktree `.claude/worktrees/agent-a90674f18f266ded5` figura `locked` pero su proceso (pid 18561) está muerto — huérfano, se puede limpiar. `context/72-app-del-duenio.md` tiene cambios sin commitear de OTRA sesión — no tocar.
