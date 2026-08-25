# Hand-off — 2026-08-25 (sesión /admin + POS móvil)

## Objetivo
Corría en paralelo a la sesión "POS token-only" (ver entry de arriba en la
bitácora, que ya cerró). Esta sesión atacó: (1) tres lecturas de `/admin`
caídas en prod a la vez (tenants, semáforo de salud, planes), (2) el bug
real de impersonación que las destapó, y (3) un pedido urgente del owner
de fixes de POS móvil (12 items, 2 agentes Opus en worktrees paralelos).

## Estado al cerrar
Todo commiteado, pusheado a `main` y DEPLOYADO en prod (verificado con
`curl`: API corre `a792623b`, frontend `33c4dacc` con el resto llegando).
Los commits de esta sesión están entreverados con los de la sesión paralela
ya cerrada — no son un rango limpio, ver lista de hashes en el entry de
bitácora `## 2026-08-25 — /admin...`.

## Archivos y cambios
- `api/lib/Services/RoleService.php` — `ownerRoleSql()` (login, sin `main`)
  y `ownerContactSql()` (fichas, con `main='true'`), predicado owner
  unificado, reemplaza 5 copias locales de `role = 1`.
- `api/lib/Services/TenantHealthService.php`, `ModulesService.php`,
  `api/lib/Modules/ModuleState.php` (nuevo) — resolver único de
  `ordersPanel/tables/production/moduleData`, viven en `company.config`
  JSONB, no como columnas propias. `Query::flattenJsonb` nuevo en el wrapper.
- `api/lib/Services/PlanAdminService.php` — `rowToPlan()` usa `ncmRow()`
  en vez de asumir `array` (recibía `CaseInsensitiveArray`).
- `api/database/migrations/postgres/172_*.sql`, `173_*.sql` — normaliza
  `contact.main='admin'`→`'true'` (2 filas); marca la empresa master
  (`00000000-...-0001`) `isInternal=1`. El seed `01_master_admin.sql`
  también se corrigió (seguía escribiendo `main='admin'`).
- `api/lib/Auth/PanelAuth.php` — `issuePanelSession()` es el emisor único
  de sesión de panel; `getEnterToken()` (impersonación) delega ahí; dejó
  de depender de `ncmExecute()` (global que no existe fuera del bootstrap
  del panel).
- `frontend/lib/admin/__tests__/impersonation-contract.test.ts` — fija el
  contrato de 3 piezas: PHP `{token}` → BFF admin (`app/api/admin/
  [...path]/route.ts`) convierte a cookie+`{redirectUrl}` → front navega.
- `frontend/components/app-sidebar.tsx` (o equivalente) + `PanelAuthGuard`
  — botón "Salir de impersonación" cableado: BFF setea `_imp_panel=1`
  (no HttpOnly) junto a `_jwt_panel`; el guard lo lee y sale con logout +
  borrar marca + redirect a `/admin`.
- `api/tests/admin_tenant_reads_test.php` + `run_admin_tenant_reads_test.sh`
  — arnés nuevo, 44 checks, Postgres descartable. Incluye caso L7 en
  subproceso que carga SOLO los includes exactos del endpoint admin (para
  no repetir el falso-verde de la regresión 2).
- POS móvil: `components/ui/action-menu.tsx` (nuevo, Dropdown desktop ↔
  Drawer bottom móvil), `NumericField`→ vuelta a `NumericPad`, Textarea de
  comentarios (`field-sizing-content` anulaba `rows`), `--kb-inset` medido
  con `visualViewport` en `dialog.tsx`, `SettingRow` apilado, switches
  28×48, fullscreen `grid`→`flex flex-col`.
- `context/14-ui-conventions.md` §11 — regla nueva: pad numérico siempre,
  nunca input nativo para montos; ActionMenu para menús de fila. (Ya
  commiteada en `0303b4d9`, no la retoques.)
- `context/34-admin-saas-plan.md` — evaluar si necesita una línea sobre
  el estado real (ver Próximo paso).

## Callejones sin salida
- **Impersonación, regresión propia 1**: se vio PHP devolviendo `{token}`
  y el front leyendo `redirectUrl` y se concluyó "contrato desalineado" —
  sin ver que el BFF de admin YA hacía esa conversión token→cookie+
  redirectUrl en el medio. Alinear las puntas rompió con 502 "Backend no
  devolvió token". Por eso el test de contrato nuevo mira las 3 piezas.
- **Impersonación, regresión propia 2**: pasar `getEnterToken()` al emisor
  compartido rompió con 500 "Call to undefined function ncmExecute" — esa
  global vive en `includes/functions.php`, que solo carga el bootstrap del
  PANEL; el realm admin arranca con `includes/db.php` solo.
  `Query::execute()` tampoco servía (arrastra `validateResultFromDB`, otra
  global del mismo archivo). El arnés daba VERDE con el bug adentro porque
  cargaba el bootstrap completo — de ahí el caso L7 en subproceso.
- **`ownerContactSql` con `main`** casi rompe el login: `findPhoneLogin`
  nunca filtró por `main`, y 2 cuentas de prod tienen `main='admin'`. Por
  eso son DOS funciones separadas (login sin `main`, fichas con `main`).
- **Checkout de la sesión paralela pisó ediciones sin commitear** de
  `PanelAuth.php` y del arnés (git status quedó limpio, se habían
  revertido) — se rehicieron a mano. Confirma: sesiones paralelas sobre
  el mismo árbol → worktrees, commit inmediato, no acumular.
- **Primer `ownerContactSql` sin alias** dejaba `companyid` sin calificar
  en el EXISTS → se resolvía contra `taxonomy` (tautología silenciosa,
  aceptaba el rol owner de CUALQUIER tenant). Columnas SIEMPRE calificadas
  + check de texto A1-A4 en el arnés.
- **`NumericField` decía en su propio docblock** "decisión del owner
  2026-08-25" — el owner lo declaró regresión ese mismo día. Los briefs
  pueden fabricar decisiones que no existieron; regla + guard test en
  `context/14` §11.

## Próximo paso
Confirmar con el owner si `context/34-admin-saas-plan.md` necesita una
línea sobre el estado real (F1-F6 implementadas pero las 3 lecturas
estuvieron caídas en prod y ahora hay arnés `admin_tenant_reads_test.php`
cubriéndolas) — si no se hizo en esta sesión, es la única acción de docs
que quedó pendiente de evaluar.

## Trampas conocidas
- Las 8 sesiones de device duplicadas en prod (pairing) siguen sin
  revocar — heredado de la sesión paralela, decisión del owner.
- Migs 157/160 y `period-close.php` siguen truncando con TZ "Asunción"
  literal — heredado, crítico antes del primer tenant no-PY.
- El owner todavía no probó el lote móvil en su iPhone (deploy recién
  salió; necesita reinstalar la PWA — storage separado de Safari).
- "Bloquear sesión luego de" en Ajustes POS sigue siendo mock con TODO
  backend (heredado, no tocado esta sesión).
- `RowActions` del panel (no-POS) sigue sin rama drawer — a propósito,
  el pedido era específico del POS.
- Las sesiones de prueba usadas para verificar impersonación end-to-end
  en prod (curl) fueron revocadas después — no quedan credenciales vivas
  de esa verificación.
