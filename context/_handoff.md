# Hand-off — 2026-08-22

## Objetivo
Ejecutar `context/48-escalamiento-de-datos.md`: el histórico de `transaction`/`itemsold`
iba a inflar índices de lo activo y volver lento el reporting a medida que crece la
base instalada. Se ejecutó E1 (particionado mensual), E1b (cierre de período) y D8
(rollup con grano diario), las tres etapas que el owner ya había cerrado en el plan.

## Estado al cerrar
- **Mig 156 (E1, particionado)**: mergeada (`3a6224cf`), pusheada, **verificada en prod**
  (723/723 filas en `transaction_registry`, 21 particiones, 0 `itemsold` sin `companyid`).
- **Mig 157 (E1b, cierre de período)**: mergeada (`84e895c0`), pusheada, **verificada en
  prod** (`period_close` vacía, sin períodos cerrados todavía — funcionalidad lista pero
  nadie cerró un período aún).
- **Mig 160 (D8, rollup diario)**: mergeada (`99520b53`), pusheada. Deploy de Coolify
  estaba EN CURSO al cierre de esta sesión — falta verificar en prod.

## Archivos y cambios
- `api/migrations/156_partition_transaction_itemsold.sql` — particionado + `transaction_registry` + triggers de sync + `ensure_month_partitions`/`partition_health`.
- `api/migrations/157_period_close.sql` — `fn_period_guard`, `period_close_run`/`due`, tabla `period_close`.
- `api/migrations/160_rollup_daily_grain.sql` — `rollup_sales_day`/`rollup_item_sales_day`/`rollup_payments_day`, `rollup_recompute_period` reescrita.
- `api/v1/period-close.php` + `frontend/app/(panel)/settings/cierre-de-periodo` — endpoint y UI (permiso `settings.periodClose`, solo owner).
- `api/lib/Database/Schema.php` — acepta `relkind='p'` y PK compuesta.
- `api/lib/Sales/verify_chain/seed.sql` — fix colateral (roto desde mig 15, bloqueaba tests de void/return/credit-payment).
- `context/48-escalamiento-de-datos.md`, `context/04-modelo-de-dominio.md`, `context/06-infraestructura.md` — ya actualizados por los commits de esta sesión (no duplicar).
- `frontend/public/sw.js` — modificado en el working tree como artefacto de build; NO commitear.

## Callejones sin salida
- El plan original (D3) proponía dropear las FKs entrantes al particionar. No sirve:
  el particionado también rompe las UNIQUE globales (`transactionuid`, número fiscal),
  por eso se creó `transaction_registry` sin particionar como ancla.
- `DETACH PARTITION CONCURRENTLY` no puede correr dentro de una función — por eso el
  DETACH de la partición DEFAULT usa `lock_timeout 5s` a nivel de sesión, no en la función.
- Agentes verificando en el server: si dos comparten el mismo nombre de contenedor
  Docker (ej. `e1test`) se pisan — cada verificación necesita contenedor + red propios
  (`e1test`, `e1btest`, `d8fix`, etc). Procedimiento completo en el mensaje del commit
  `203b69c1`. El server de prod NO tiene `php`; la máquina del owner NO tiene Docker —
  la verificación se hace con `pg_dump` completo a un `postgres:18-alpine` descartable.
- Backfills de migraciones nuevas sobre `itemsold`/`transaction`/`stock`/`cpayments`/
  `expenses` disparan `fn_period_guard` si hay períodos cerrados en el rango — hay que
  envolver el backfill con `SET LOCAL session_replication_role = replica` y redefinir
  el trigger antes (P0 que encontró el review de Opus en mig 160).

## Próximo paso
Verificar mig 160 en prod: `SELECT filename FROM schema_migrations WHERE filename LIKE '160%'`,
y confirmar `rollup_dirty = 0` y `rollup_sales_day` con filas. Si OK, seguir con E2
(RB-3: rollups de compras/producción/stock sobre el grano nuevo) o arrancar
`context/47` F0 (catálogo de reportes), que ya tiene `RollupReader::salesDaily()` como base.

## Trampas conocidas
- `fn_period_guard` en la rama de `transaction` solo permite mutar `transactioncomplete`,
  `updated_at` y `channel` — cualquier columna nueva "de estado" que se agregue después
  hay que sumarla ahí explícitamente o el trigger la va a bloquear.
- Anular recibos o compras de un período ya cerrado queda bloqueado por diseño (pre-check
  409 en los 3 `void()`) — no es un bug si un tester lo reporta.
- Numeración de migraciones colisionó dos veces con sesiones paralelas (158→159→160) —
  antes de mergear, correr `ls api/migrations | sort -n | tail` contra `origin/main`.
- Gaps anotados a propósito en `context/48` como pendientes, no arreglados esta sesión:
  `kind='cobro'` mezcla cobro a cliente y pago a proveedor (ambos type 5); `CategoriesService`
  agrupa por `item.categoryid` actual, no por la categoría congelada; anulación de recibo
  (`transactionStatus=6`) no se excluye de Medios de Pago.
- Branches `api/particionado-e1`, `api/cierre-periodo-e1b`, `api/rollup-d8` siguen en
  worktrees de agentes (`.claude/worktrees/agent-*`); las remotas ya se borraron.
- `frontend/public/sw.js` aparece modificado en el checkout — es artefacto de build local,
  no un cambio real; no commitear.
