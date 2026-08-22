# Hand-off — 2026-08-22

## Objetivo
Cerrar el reporte del tester "Actualización 21" (6 items) contrastándolo contra el
código real y resolviendo cada uno, en paralelo con el eje de escalamiento de datos
(E1/E1b/D8, ya logueado en la entry de arriba). De paso, correr el arnés `verify_chain`
en el server contra `main` para destapar bugs silenciosos antes de que los reporte
un tester.

## Estado al cerrar
Todo commiteado, pusheado y en `main` (Coolify deploya desde ahí). No verificado en
prod smoke-test todavía (ver Próximo paso).

- **6/6 items del tester resueltos y mergeados**: costo de receta unificado
  (`5d964d83`), padwidth de numeración (`0e681eb8`, mig 159), líneas de plantilla
  en impresión (`d7faceef`), alcance de conteo por sucursal/categoría (`a4af1397`,
  mig 158), filtros de Transacciones (`96aa9316`), búsqueda de Artículos por
  categoría (`768895fa`).
- **3 bugs adicionales** encontrados por el arnés `verify_chain` corrido en el
  server (167.71.165.221), arreglados y mergeados (`e79bbeaf`, mig 160
  `160_repair_missing_roledata.php`): permisos de rol nunca persistían, pago a
  proveedor daba 500, tipo de retorno `CaseInsensitiveArray` en un verificador.
- **De la sesión de escalamiento (paralela, ya en main)**: mig 156 particionado,
  mig 157 cierre de período, mig 160 rollup diario (`.sql`, distinto del 160 `.php`
  de arriba — ver Trampas). También en main: fixes de SEO/precio del sitio
  marketing (commits previos al rango de esta sesión).

## Archivos y cambios
- `api/lib/App/Domain/RecipeCosting.php` — fórmula única de costo, consumida por
  `getProductionCOGS`/`getComboCOGS`/`ProductionService::complete`.
- `api/database/migrations/postgres/159_document_sequence_padwidth.sql` —
  columna `padwidth`; `DocumentNumber::format()` (PHP) +
  `frontend/lib/documents/format-document-number.ts` (TS) formateador único.
- `frontend/lib/hardware/printers/blocks.ts` — `lineGeometry()` compartida por
  canvas/html-renderer/ESC-POS.
- `api/database/migrations/postgres/158_inventory_count_scope.sql` +
  `api/lib/services/InventoryCountScope.php` — armador único del alcance.
- `frontend/components/data-table/active-filters.tsx` — chips de filtro reusables.
- `frontend/hooks/use-debounce.ts` — nuevo, usado por búsqueda server-side de `/items`.
- `api/database/migrations/postgres/160_repair_missing_roledata.php` — repara
  companies sin `roleData` (no re-corre en prod si ya tienen datos).
- `api/lib/services/RoleService.php` — `_savePermissions()` ahora atómico y lanza.
- `api/lib/services/DrawerService.php` — `registerIdOrNull()` extraído.
- `context/10-roadmap.md` — sección "Reporte del tester — Actualización 21" con
  el contraste original + los 6 ítems marcados resueltos.
- `context/04-modelo-de-dominio.md` — agregadas migs 158/159 (160 rollup ya estaba).
- `frontend/public/sw.js` — modificado en el working tree como artefacto de
  build local; NO commitear (regla del repo).

## Callejones sin salida
- Dos agentes paralelos habían elegido mig 158 para conteo Y para padwidth —
  se renumeró la del padwidth a 159 con sed sobre comentarios al mergear.
  **Con agentes paralelos que crean migraciones, asignar el número en el brief
  de antemano**, no confiar en que cada uno lea el `HEAD` del otro.
- `npm run build` no termina si dos sesiones lo corren a la vez en la máquina
  del owner (quedó colgado a 0.7% CPU 45 min). Usar `tsc --noEmit` como gate por
  agente y reservar el build completo para una sola corrida desde `main`.
- Docker no levanta local — el arnés `verify_chain` SOLO corre en el server
  (167.71.165.221, directorios `/root/verify-rc*`, imagen `punto-php-test`,
  Postgres descartable `verify_rc_pg` en :55432). Dos agentes compartiendo el
  mismo directorio se pisan el rsync — un directorio por agente, mismo Postgres.
- `php -l` y un arnés sin Postgres real NO detectan `ncmInsert`/`DB::Execute()`
  devolviendo `false` en silencio — los 3 bugs del arnés solo salieron corriendo
  contra una BD real con datos reales.

## Próximo paso
Smoke test en prod de los 6 items + 3 bugs, no hecho todavía en esta sesión:
crear un conteo con categorías filtradas, editar permisos de un rol y confirmar
que persisten tras refrescar, pago a proveedor desde el panel, imprimir una
plantilla con líneas horizontales/verticales, cambiar el ancho de dígitos de una
secuencia de factura en Ajustes → Cajas. Confirmar que mig 158/159/160(ambas)
corrieron: `SELECT filename FROM schema_migrations WHERE filename LIKE '15%' OR filename LIKE '160%' ORDER BY filename`.

## Trampas conocidas
- **Dos migraciones con el mismo número 160**: `160_rollup_daily_grain.sql` (D8,
  escalamiento) y `160_repair_missing_roledata.php` (bug del arnés, esta sesión).
  No es un bug funcional — `migrate.php` hace `usort` estable y arma la lista con
  `array_merge($sqlFiles, $phpFiles)`, así que el `.sql` corre antes que el `.php`
  siempre, determinístico. Pero es confuso: **no renombrar ninguno de los dos**
  si ya corrieron en prod (el tracking es por filename exacto en
  `schema_migrations`, renombrar los haría re-ejecutar). Para la próxima
  migración nueva, usar 161+.
- `DB::Execute()` (y `ncmInsert`) tragan errores SQL silenciosamente (log a
  `error_log`, devuelven `false`) — es la causa raíz que escondió los 2 bugs de
  permisos/pago-a-proveedor durante meses. Anotado en `context/10-roadmap.md`
  (`c4c21e3a`) como hallazgo; decisión de si `DB::Execute()` debe lanzar en
  escrituras queda pendiente del owner.
- Del reporte del tester, quedan abiertos (no tocados esta sesión): roadmap
  item 8 "habilitar costos en el conteo"; cotizaciones del listado no exponen
  `docNo` formateado; `padStart(7)` de documentos de proveedor en compras no usa
  el formateador nuevo (dominio distinto, no se tocó).
- Postgres `verify_rc_pg` sigue corriendo en el server — borrar con
  `docker rm -f verify_rc_pg` cuando ya no haga falta para otra corrida del arnés.
- `frontend/public/sw.js` reaparece modificado tras cada build local — es
  artefacto, no commitear.
