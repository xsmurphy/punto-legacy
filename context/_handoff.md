# Hand-off — 2026-08-26 (auditoría de seguridad de auth cerrada)

## Objetivo
Auditoría de seguridad COMPLETA del mecanismo de autenticación de Punto,
disparada por un leak cross-tenant real que vio el owner (veía en el panel
de una empresa el gráfico de ingresos de OTRA). Alcance: todo `/v1/*`,
`/admin/*`, `frontend/app/api/**` (BFF) y `api/lib/**`.

## Estado al cerrar
Mergeado a `main` en `7a16fc96`, pusheado, Coolify deployando. Branch
`api/auth-security-audit` borrada (local + remota), worktree removido.
4 hallazgos P1 arreglados, cero P0. Suite vitest completa 29 archivos/384
tests verde; 2 arneses PHP nuevos verdes (ver abajo).

## Archivos y cambios
- `api/v1/items.php` — guard único de pertenencia justo tras `$id =
  $_GET['id']` (mismo espíritu que el gate de permisos que ya vivía
  arriba): cubre `PUT ?resource=categories|brands|tags|locations` y el GET
  detalle. Antes, un operador del tenant A con solo el UUID de un ítem del
  tenant B borraba/reescribía su clasificación y depósitos (esas tablas
  m2m no tienen `companyId` propio).
- `api/lib/Items/LocationService.php` — `syncForItem`/`detach`/
  `listForItem`/`setDefault` ahora scopean por `companyId` (defense in
  depth debajo del guard de arriba).
- `frontend/app/api/dashboard/income-chart/route.ts` — dejó de extraer
  `_jwt_panel` por nombre y reenviarla como `Authorization: Bearer`; ahora
  reenvía el header `cookie` crudo, igual que el catch-all `/api/v1`,
  agent/chat, ocr y geo. Era la superficie VIVA del leak (con dos cookies
  homónimas en scopes distintos, `req.cookies.get()` de Next devuelve la
  PRIMERA, PHP parsea la ÚLTIMA, y Bearer le gana precedencia en
  `authResolve`).
- `api/v1/imports/run.php` — exige `inventory.item.{create,edit}` según
  mode; para contactos el gate es por tipo permitido, enforceado POR FILA
  en `ContactImporter` (param opcional `allowedTypes`, null = compat con
  `ai/execute` y el harness `verify_pg_identifiers`).
- `api/v1/imports/upload.php` — exige al menos una capacidad de import
  para stagear.
- `api/lib/services/BancardService.php` — `persistOwnership()` guarda los
  ids candidatos de la respuesta de Bancard (espeja `ID_KEYS`/
  `WRAPPER_KEYS` de `frontend/lib/payments/psp-qr.ts`) apuntando al
  `companyId` emisor; `ownerCompanyOf()` valida antes de refresh/cancel.
- `api/migrations/175_bancard_qr_tenant_binding.sql` — tabla `bancard_qr`
  (renumerada de 174 a 175 por colisión con la mig de la sesión paralela
  de impresión, ya corrida en prod con ese número).
- `context/54-panel-auth-cookie-vs-bearer.md` — doc nuevo: plan
  cookie-vs-Bearer pedido por el owner. Conclusión: la causa NO es
  "cookies vs Bearer", es `COOKIE_DOMAIN=.punto.la` wildcard + emisores
  divergentes. Recomienda Opción A (cookie host-only + emisor único)
  sobre Opción B (Bearer, que sería downgrade de XSS para una app web).
  Decisión abierta del owner; prerrequisito a confirmar con infra: que
  ningún browser cruce subdominio.
- `api/tests/items_tenant_isolation_test.php` +
  `run_items_tenant_isolation_test.sh` — 6/6.
- `api/tests/bancard_qr_tenant_isolation_test.php` +
  `run_bancard_qr_tenant_isolation_test.sh` — 7/7, aplica mig 175.
- `frontend/lib/bff/__tests__/panel-cookie-no-bearer.test.ts` — guard
  estructural: ninguna route del panel puede volver a re-acuñar cookie
  como Bearer.
- Impersonación desde `/admin`: verificada, NO conflictúa con nada de
  esto (`context/54` §4b) — se CONVIERTE en el tenant vía
  `PanelAuth::issuePanelSession()`, no es override cross-tenant por
  header, así que todo guard scopeado por `AUTHED_COMPANY_ID` opera
  correcto bajo impersonación.

## Callejones sin salida
- El check estático del arnés de items se auto-engañaba: buscaba `DELETE
  FROM item_category` con `str_contains` y matcheaba el COMENTARIO del
  guard (que cita ese DELETE como ejemplo) antes que el statement real —
  daba rojo con el fix puesto. Corregido a buscar statements EJECUTABLES
  (`->Execute('...`). Lección: guards estáticos que buscan SQL por texto
  deben excluir la prosa.
- `ownerCompanyOf` usaba `ncmExecute` tratándolo como recordset
  (`->EOF`/`->fields`) y devolvía siempre null. `ncmExecute` devuelve una
  FILA directa; para recordset hay que usar `$db->Execute`. Trampa
  conocida del wrapper, volvió a morder.
- Colisión de numeración de migraciones: la sesión paralela de impresión
  mergeó `174_block_labels_backfill.php` (ya corrida en prod) mientras
  esta branch traía `174_bancard_qr_tenant_binding.sql`. Se renumeró a
  175. Al abrir una branch larga, chequear el número de migración contra
  `main` ANTES de mergear, no al final.
- El worktree no tenía `node_modules`; se symlinkeó el del árbol
  principal para poder correr vitest.

## Próximo paso
Confirmar que el deploy de Coolify salió y que la **mig 175 corrió en
prod** (`bancard_qr` existe) — es lo único de esta sesión que toca schema.
Después: decisión del owner sobre `context/54` Opción A vs B, y arrancar
por el P2 más directo (`api/v1/modules.php` sin `hasPermission()` en
`action=toggle`/`config`).

## Trampas conocidas
- **7 P2 de seguridad reportados, NO arreglados** (todos intra-tenant,
  ninguno cross-tenant, ver `context/10-roadmap.md`):
  `api/v1/modules.php:44-83` (toggle/config sin permiso, el más directo);
  `api/bootstrap.php:226-244` (`X-Outlet-Id: all` sin chequeo de rol);
  `api/v1/attendance.php:25-40` (control de presencia = `md5` derivable);
  `api/v1/devices.php:35` (403 vs 404 = oráculo de existencia);
  `api/lib/services/OrderService.php:196-217,349` y
  `api/lib/Reports/DashboardService.php:592` (scope incompleto, no
  explotable hoy); `api/lib/services/DrawerService.php` (expenses sin
  companyId).
- Bug PRE-EXISTENTE ajeno a esta auditoría: `api/v1/items.php` bloque
  group/ungroup (~L471-485) usa `$id` antes de asignarlo, `ungroup`
  siempre corta 422. No lo introdujo este diff.
- Verificado LIMPIO (no re-auditar): realm admin completo, precedencia
  Bearer de `authResolve`, `X-Outlet-Id` fail-closed, toda la mitad N-Z de
  `api/v1`, ~240 archivos de `api/lib/**`, todos los BFF salvo
  income-chart (ya arreglado).
- Símbolo de moneda imprime `?` en la térmica — `UNKNOWN_CURRENCY_SIGN =
  "¤"` (`frontend/lib/tenant-locale.ts:135`) no existe en CP437. Heredado
  de la sesión de impresión, sin confirmar si se tocó.
- TZ "Asunción" literal en migs 157/160 + `period-close.php` — crítico
  antes del primer tenant no-PY. Heredado, sin tocar.
- 8 sesiones de device duplicadas en prod esperando decisión de revocar
  (heredado).
- `SaleToInvoiceMapper.php:195` — venta con vale no factura (heredado).
- "Bloquear sesión luego de" en Ajustes POS sigue mock con TODO backend.
- Cron semanal de poda de BuildKit vive en el HOST de prod
  (`/etc/cron.weekly/docker-builder-prune`), NO viaja en el repo.
