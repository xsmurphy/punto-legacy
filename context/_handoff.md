# Hand-off — 2026-09-15

## Objetivo

Migrador ENCOM: histórico completo (F2, ventas/compras/gastos). Incidente
real en prod (venta trabada por etiqueta a mano). Auditoría fiscal (Libro
Ventas/RG90, KuDE, anuladas en panel). Persistencia de preferencias de
listados por usuario.

## Estado al cerrar

Todo mergeado, pusheado y deployado: Backend en `bda10aa2`, Front en
`54a9323d`, ambos `running:healthy`. Branches/worktrees de agentes de esta
sesión limpiados.

**EN CURSO, sin mergear**: agente en branch `api/planes-sin-versionado`
(planes SaaS editan en el lugar + re-proyección a tenants + mig que mueve 6
tenants de plan_code 3→5). Necesita `code-reviewer` (billing + realm admin)
antes de mergear.

## Archivos y cambios

- Migrador ENCOM (`context/77`): export paginado (`part=true&offset&limit=1000`,
  fail-closed al tope 100), líneas de itemSold desde el log en bloque
  `a_report_products?action=detailTable` (no 1 request/venta), COGS real
  dividido por cantidad, reaper por `updated_at`. F2 histórico (ventas+compras+
  gastos) reimportó 6.969 ventas + 248 compras en prod, tenant Don Ramon
  (`01a081dd-742b-7847-9b66-b19670f9f4ed`).
- `d50227f3`/`786f8542` — `SaleService::persistSaleTags` valida forma uuid y
  resuelve-o-crea tag por nombre; `GET /v1/tags` acepta pos-app.
- `bb021755` — `TransactionsService` lee etiquetas de la relación `toTag`,
  no de `meta->'tags'` (mismo bug, en lectura).
- `6753fe7b` — "Ver KuDE" del panel usa `api.getBlob`+`triggerDownload` en
  vez de navegar al BFF token-only (daba 401).
- `b617ed6b`/`8861a31a`/`d71942ba` — anuladas: no cuentan como contado en
  listado, no suman en totales, detalle muestra motivo/autor/fecha.
- `bda10aa2` — Libro Ventas/RG90 leen `transaction.invoiceauth` (congelado,
  mig 145) en vez de reconstruir desde `register.data` vigente; arnés fiscal
  34/34.
- `54a9323d` (+ `346936c7`,`6e31e1f5`) — `lib/table-state`,
  `hooks/use-persisted-table-state.ts`: orden/buscador/filtros por
  columna/columnas visibles/filtros de dominio en localStorage por
  empresa+usuario (caja: operador del PIN), "Restablecer vista", 16 listados
  migrados.
- Docs: `context/28-facturacion-electronica-plan.md` §F8 (serie SIFEN
  `dSerieNum`), `context/46-reportes-fiscales-plan.md` punto 6 marcado
  RESUELTO.

## Callejones sin salida

- Atribuir la serie `AA` al default de Factomate: error — Factomate está
  descartado, la 837 la emitió OTRO sistema del cliente en el mismo punto.
- Endpoint en bloque de líneas de venta en `a_report_transactions`: no
  existe (HTML entero); el log en bloque es de `a_report_products`.
- curl_multi para 6.927 requests de detalle: descartado, no hacía falta con
  el log en bloque.
- Parche de `tagNames()` tratando no-uuid como nombre: revertido, las
  etiquetas tienen id — hay que leer la relación.

## Próximo paso

Revisar el diff de `api/planes-sin-versionado` cuando el agente termine
(`code-reviewer`, foco en la mig que mueve los 6 tenants de plan_code 3→5
contra prod), mergear a `main` y deployar Backend + Front.

## Trampas conocidas

1. Balloon Party, punto 001-001: facturas 838-840 rechazadas SIFEN "1110
   Serie informada incorrecta". `dSerieNum='AA'` confirmado consultando el
   CDC de la 837 (endpoint nuevo FE-PY `GET /v1/tenants/{id}/consulta/de/{cdc}`).
   NO cargar serie ni reintentar: el 001-001 lo comparten DOS emisores, 838-840
   pueden estar ocupados por el otro sistema. Esperar confirmación del
   cliente. El 001-002 (exclusivo Punto) aprueba sin problema.
2. Otros 3 docs FE en error de Balloon Party (parqueados, `MAX_RETRY_ATTEMPTS=8`):
   2 por `dTelEmi` vacío (cargar teléfono del emisor en `einvoice_account`),
   1 NC por Idempotency-Key reusada.
3. KuDE del motor FE-PY recorta los últimos 8 dígitos del CDC en el PDF (bug
   del motor, otro repo); el QR sí lleva el CDC completo. Probablemente
   afecta a todos los KuDE, no solo Balloon Party.
4. NC en Libro Ventas/RG90: NO implementadas a propósito — falta corregir
   signo del desglose de IVA en devoluciones (`context/46` F5.0) y validar
   contra un RG90 real aceptado por Marangatu que incluya una NC.
5. P1 sin hacer: `SaleService::persistSaleTags` atrapa `\Throwable` y
   enmascara la causa real si falla el insert.
6. Sin idempotencia de ventas: `transactionUID` sin índice único ni dedupe
   en offline-sync.
7. Persistencia de listados (`54a9323d`) sin probar en navegador todavía.
