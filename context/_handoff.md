# Hand-off — 2026-08-18

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Corregir un invariante fiscal mal implementado (unicidad del punto de
expedición), completar la documentación de `context/modules/` (quedaban 12
de 25), arreglar un bug de plata donde el `drawerId` de una venta offline
caía en el turno de caja equivocado, asentar por escrito 6 reglas de negocio
que el owner confirmó verbalmente, y resolver un bug de `/settings` que
borraba configuración no cargada en el form al guardar.

## Estado al cerrar

Todo commiteado en `main` (`be12977a..f2e48b70`, 11 commits) y **ya
pusheado a `origin/main`** — verificado con `git merge-base --is-ancestor
HEAD origin/main` (YES). `origin/main` sigue 93 commits por delante por
sesiones paralelas.

Migraciones verificadas corridas en prod por SSH (ver "Trampas" para el
comando): **143** (`uq_register_expedition_point_by_auth` existe) y la
migración de backfill de `drawerId` — en el repo local es `144_transaction_
drawerid_backfill_by_date.sql`, pero en prod corrió renumerada a
**`147_transaction_drawerid_backfill_by_date.sql`** (colisión con sesiones
paralelas, mismo patrón que ya pasó antes — ver Trampas).

## Archivos y cambios

- `api/database/migrations/postgres/143_register_expedition_point_unique_by_auth.sql`
  — dropea el índice mal formado de la mig 128, crea
  `uq_register_expedition_point_by_auth` sobre `(companyId,
  registerInvoiceAuth, registerInvoicePrefix)`.
- `api/lib/services/RegisterAdminService.php` — `assertPrefixFree` →
  `assertExpeditionPointFree` (compara timbrado+prefijo efectivos).
- `context/modules/*` — 12 docs nuevos (`01-catalogo-items`, `08-compras`,
  `09-notas-credito-compra`, `13-cotizaciones`, `14-caja`,
  `15-credito-y-cobranzas`, `16-giftcards-y-vales`, `21-contactos`,
  `22-sincronizacion`, `23-auth-y-permisos`, `24-sucursales-y-scopes`,
  `25-reportes`) + `_index.md` con 10 filas nuevas de interacciones.
  24/25 escritos, falta solo `06-produccion.md` (🟡).
- `api/lib/services/DrawerService.php` — `resolveDrawerIdForDate($registerId,
  $companyId, $operationDate)`, resuelve por contención de fecha en vez de
  "¿qué caja está abierta ahora?". Cableado en `api/lib/Sales/SaleService.php`
  y `api/lib/services/CreditPaymentService.php`. `resolveOpenDrawerId` queda
  solo para guards de caja abierta (abrir/cerrar turno).
- `context/modules/10-pos-venta.md`, `14-caja.md`, `17-numeracion.md`,
  `22-sincronizacion.md` — 6 reglas de negocio del owner (R1-R6, ver commit
  `4cc543db`); R3 (turno offline) documentada como NO cumplida.
- `frontend/app/(panel)/settings/page.tsx` — buscador de secciones en el
  sidebar del modal (desktop only) + guard `!data` en los botones Guardar +
  resolver de zod acotado a `SECTION_FIELDS` de la sección activa (antes
  validaba el schema entero y un campo legacy invisible bloqueaba
  cualquier guardado) + sale "Apariencia" de `FORM_SECTIONS` (submit sin
  campos, guardaba nada con toast de éxito falso).
- `api/lib/Settings/SettingsService.php`, `api/v1/settings.php`,
  `frontend/hooks/use-settings.ts` — merge parcial vía `array_key_exists`
  (distingue "campo ausente" de "campo presente vacío"), en vez de
  sobreescribir las ~30 columnas de `company.config` con `''` en cada
  guardado parcial.
- `CLAUDE.md` — corregida la fila de `29-numeracion-y-exclusividad-de-caja.md`:
  ya no dice "nada implementado" (F0/F1 están en `main`, F2/F3 existen en
  la branch `api/numeracion-exclusividad` sin mergear).

## Callejones sin salida

1. El hand-off anterior decía que faltaban correr las migraciones
   131/132/134/136/140. Era falso — todas habían corrido. El error vino de
   ordenar `schema_migrations` lexicográficamente (`ORDER BY filename`,
   donde "99" > "142") en vez de por
   `(split_part(filename,'_',1))::int`. Usar siempre esa expresión para
   verificar contra prod.
2. Se afirmó que la exclusividad de caja ya estaba en prod mirando el
   índice único `uq_register_lease_active`. Falso: el schema está
   deployado pero el código que ESCRIBE la tabla (F2/F3) vive en la branch
   `api/numeracion-exclusividad`, no en `main`. Un índice único no protege
   nada sobre una tabla que nadie escribe todavía en el camino live.
3. Bug del Rubro en `/settings` — 4 hipótesis descartadas antes de dar con
   la causa real (payload parcial del dialog, values duplicados en
   `COMPANY_CATEGORIES`, zod stripping `category`, `settings.php` pasando
   por `validateHttp`). La causa real: comparar el JSONB `config`
   antes/después de un guardado real mostró que el form mandaba
   `settingThousandSeparator: ""` — el backend guardaba bien todo el
   tiempo, el bug era de hidratación en el front.
4. Un sub-agente commiteó por error en la branch de la sesión paralela
   (asumió `main` porque el brief lo decía) y se recuperó con `git reset
   --hard` sobre el checkout COMPARTIDO. No se perdió nada, pero es el
   antipatrón que ya causó un P0 en este repo — reforzado en memoria
   (`feedback_parallel_agents_need_worktrees.md`): agentes que commitean
   van en worktree propio, nunca sobre el árbol que otra sesión puede
   estar usando.

## Próximo paso

Nada quedó abierto de esta sesión en particular — el trabajo de acá está
cerrado y pusheado. El próximo hilo natural es retomar `context/29`: mergear
`api/numeracion-exclusividad` (F2/F3) a `main` para que `register_lease`
empiece a recibir escrituras reales, coordinado con la sesión que la tiene
en curso.

## Trampas conocidas

- Comando para verificar migraciones corridas en prod (ordenado
  correctamente, NO por filename lexicográfico):
  `ssh root@167.71.165.221 "docker exec w6rtfxm2n6l45r4r9melj3hl psql -U
  postgres -d postgres -tAc \"SELECT filename FROM schema_migrations ORDER
  BY (split_part(filename,'_',1))::int DESC LIMIT 10\""`.
- **La numeración de migraciones en prod NO coincide 1:1 con la de este
  checkout** en las últimas filas: `origin/main` va 93 commits por delante
  y renumeró para evitar colisiones (ej. la mig local `144_transaction_
  drawerid_backfill_by_date.sql` corrió en prod como `147_...`). Verificar
  siempre por contenido/nombre de archivo sin el prefijo numérico, no por
  el número.
- **`register_lease` ya tiene 1 fila en prod** (no 0 como decía el hand-off
  anterior) — pero es el backfill de F1 (mig 142, cajas con lease vivo al
  momento del backfill), no escritura en vivo de F2/F3 (esas siguen sin
  mergear). No confundir "hay una fila" con "el flujo F2/F3 está activo".
- `frontend/public/sw.js` sigue modificado sin commitear en el checkout
  compartido — artefacto de build, no requiere acción (arrastrado de
  sesiones anteriores).
- Hallazgos de los 12 docs de módulo nuevos, ninguno arreglado todavía —
  quedan documentados con `path:line` en sus respectivos `context/modules/*`:
  12 endpoints de escritura sin chequeo de permiso backend; canje de gift
  card fire-and-forget (venta puede cobrar sin debitar saldo si falla
  después); `kind=pack` rompe siempre con 422 (`api/v1/items.php:141`);
  importador CSV de contactos duplica en cada reimportación
  (`ContactImporter.php:109,128-131`); `kind='quote_to_sale'` nunca se
  persiste (`SaleService.php:652`); `rollup_reconcile()` sin caller y
  `pg_cron` no instalado en prod; `parked-sales.php` y `numbering/lease.php`
  mutan sin emitir evento realtime; control de caja a ciegas es solo UI.
- R3 (turno de caja offline) NO se cumple: el backend soporta `date` en el
  body de `/v1/drawer.php:116`, pero el POS bloquea con `openDrawer:
  offlineEligible: false` en `frontend/lib/commands/registry.ts` — revertir
  esa decisión de producto de `context/16 §5` para cerrar R3.
