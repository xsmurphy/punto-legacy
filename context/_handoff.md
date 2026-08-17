# Hand-off — 2026-08-17

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar F2/F3 de numeración (`context/37`), resolver dos P0s de costeo
detectados en producción (costo promedio en cero, explosión de receta de un
solo nivel), atacar la raíz del wrapper de DB (`_getTableSchema()` a mano),
cerrar F1-F5 de add-ons/combos (`context/41`) y arreglar un bug reportado de
módulos que desaparecían del sidebar del POS.

## Estado al cerrar

Todo commiteado y pusheado a `main` (`721cf0f1..f0e7c423`, entreverado
numéricamente con la sesión paralela de sync/detalle-transacción — ver
bitácora, entry de arriba). **Migraciones sin correr en prod**: verificado
por SSH que 127/128/129/130/133 sí corrieron; faltan al menos **131, 132,
134, 136, 140** — confirmar con el comando de "Trampas" antes de dar nada
por deployado.

## Archivos y cambios

- `api/lib/Numbering/DocumentNumber.php` — `allocate()`/`allocateBlock()`
  reemplaza `MAX(ordernumber)+1` + advisory lock en `OrderCoreService`.
- Migs `127` (re-seed `document_sequence`), `129` (documentos de stock),
  `131` (rebuild de costo), `132` (rescate JSONB), `134/136/140` (add-ons).
- `api/lib/Database/Schema.php` (nuevo) — reemplaza el mapa a mano de
  `_getTableSchema()`; lee el catálogo de PG, cachea con huella del catálogo.
- `api/lib/Inventory/InventoryService.php` — `explodeRecipe()` recursivo.
- `frontend/hooks/use-pos-modules.ts`, `api/v1/modules.php`,
  `frontend/app/api/pos/modules/route.ts` — fix del sidebar + regla ESLint
  `no-restricted-imports` (impide reintroducir el cliente del panel en POS).
- `context/37-numeracion-documentos.md`, `41-addons-y-combos.md` — F2/F3 y
  F1-F5 documentadas adentro.

## Callejones sin salida

1. Dije que el costo histórico NO se podía reconstruir — falso, las 47 filas
   de ingreso tenían `stockCOGS`; la mig 131 reconstruye replayeando.
2. Mi relevamiento de add-ons dijo "no existe nada" — existía `combo_group`
   + `ComboGroupService` + editor (panel-only, la venta nunca lo leía).
3. El plan asumía `itemSold.itemsoldparent` como link padre-hija: tiene FK a
   `item`, no a `itemSold`. Hubo que usar `itemSold.meta.addon` (F3); en
   `pos_order_item` (mig 140) sí hay FK propia.
4. Brief de F4 mandaba interceptar en `handleProductClick`; el agente lo
   movió a `addCatalogItem` porque el scanner de código de barras no pasa
   por el click — correcto, quedó documentado.
5. Un agente borró `frontend/public/sw.js` y `swe-worker-*.js` dentro de un
   diff de add-ons — atajado en review, no llegó a commit.
6. Colisiones de número de migración con sesiones paralelas (139 pisada dos
   veces → renumerada a 140; 118 original → 127). Verificar SIEMPRE con
   `ls ... | sort -t_ -k1 -n | tail -3` justo antes de commitear.

## Próximo paso

Deployar y correr las migraciones pendientes (ver "Estado al cerrar"). Después
resolver el punto de expedición duplicado (ver Trampas) y re-correr la 128.

## Trampas conocidas

- Confirmar migraciones corridas: `ssh root@167.71.165.221 'docker exec
  w6rtfxm2n6l45r4r9melj3hl psql -U postgres -d postgres -tAc "SELECT
  filename FROM schema_migrations ORDER BY 1"'`.
- **Mig 128 NO creó `uq_register_expedition_point`**: "Caja Mariano" y
  "Nueva Caja" comparten punto de expedición `001-001`. Asignarle otro punto
  a una y re-correr la 128. El guard del servicio ya bloquea casos nuevos.
- **Caja Mariano tiene próxima factura = 1** con timbrado cargado — si
  facturaba con otro sistema, cargar el número real antes de cobrar.
- 5 cajas "Caja Principal" y 3 "New Register" activas sin timbrado.
- `transactionTotal` sigue saliendo del subtotal que informa el cliente, no
  se deriva del detalle (`SaleService::expandAddonSelections`) — cerrarlo es
  cambio de contrato propio.
- Gaps de add-ons: reimpresión desde el panel no indenta hijas
  (`TxDetailFull` no expone `meta.addon`); F6 (reportes por add-on) sin
  empezar; D4 (variantes de bloque de impresión) desbloqueada pero sin
  implementar.
- `frontend/public/sw.js` modificado sin commitear — artefacto de build, no
  requiere acción (mismo estado que reportó la sesión paralela).
- **Trabajo sin commitear de OTRA sesión, no tocar sin coordinar**:
  `api/database/migrations/postgres/141_register_lease.sql` y
  `142_register_lease_backfill.php` (untracked) — parecen ser F0 de
  `context/29-numeracion-y-exclusividad-de-caja.md`, en progreso en paralelo.
- Plan `context/40` (anulación y NC) sigue con D1-D4 cerradas y NADA
  implementado; `context/42` (multi-moneda) es feature request sin planificar.
