<!-- Plan de limpieza del legacy. Proyecto en DESARROLLO (sin prod, sin clientes, sin datos):
     se pueden borrar las UIs legacy. PERO /api depende en disco de un subset de /app, y el
     admin depende de un subset de /panel — eso NO se toca hasta la extracción (Fase 2). -->

# 22 — Limpieza de legacy (Fase 1 borrado + Fase 2 extracción /api/includes)

> Decisión owner 2026-06-29: el proyecto NO está en producción (cero clientes/cuentas/usuarios).
> Las UIs legacy (POS `app.*`, panel BS3 `panel.*`) se borran. Objetivo final: eliminar `/app`
> y el legacy de `/panel` por completo. Branch: `legacy-cleanup` (off `auth-rewrite`).

## Entry points VIVOS (todo lo demás es candidato a borrar)

1. `api/bootstrap.php` (backend que frontend consume vía catch-all) — hace `chdir(/app)` y
   requiere un subset de `/app`.
2. Path admin de panel — frontend BFF admin pega a `${PANEL_URL}/API/v1/admin/*`.
3. frontend (Node, app aparte) — no requiere PHP en disco, los consume por HTTP.

## Cierre KEEP — NO BORRAR (probado por trace de `require`)

### /app (deps de `/api/bootstrap.php`)
- `head.php`, `data.php`, `app_version.php`, `composer.json/lock`, `vendor/`
- `includes/`: `cors.php`, `jwt_middleware.php`, `realtime.php`, `rollup.php`, `db.php`,
  `simple.config.php`, `functions.php`, `phone.php`, `jwt.php`, `auth_session.php`,
  `ws_publish.php`, `errorPage.inc.php`, `lib/` (DB.php)
- `libraries/`: `rateLimiter.php`, `countries.php` (los únicos que `head.php` incluye)
- `languages/` (si existe; `data.php` L47 lo incluye)
- PSR-4 `Punto\App\*`: `Domain/`, `Helpers/`, `Http/`, `Services/`, `Database/`
  (9 services de `/api` los usan — Inventory/Taxonomy confirmados, el resto por seguridad)

> Trampa confirmada NO-bloqueante: `functions.php:1863 include_once("a_stand_by_page.php")` →
> `app/a_stand_by_page.php` NO existe → es referencia muerta latente, no dep viva.

### /panel (deps del path admin)
- `router.php` (sirve `/API/v1/admin/*`)
- `API/v1/admin/*`, `API/lib/admin_auth.php`, `API/lib/response.php`
- `lib/admin/*` (AdminUserService, AdminReportsService, CompanyAdminService)
- `includes/` subset que el admin requiere: `db.php`, `jwt.php` (+ trazar `functions.php`/
  `simple.config.php`/`cors.php`/`phone.php` si `lib/admin/*` usa `ncmExecute`)
- `libraries/rateLimiter.php`
- **TRAZAR antes de borrar `panel/includes/*` y `panel/API/lib/*`**: grep recursivo de
  `require/include` desde `API/v1/admin/*.php` + `lib/admin/*.php`.

## DELETE — Fase 1 (UIs legacy)

### Batch A — estáticos (riesgo CERO; ningún PHP hace `require` de .html/.js/.css/img)
- /app: `index.html`, `assets/`, `cach/`, `css/`, `fonts/`, `images/`, `scripts/`, `sections/`,
  `*.shtml`, `*.png`, `favicon.ico`, `manifest.json`, `browserconfig.xml`
- /panel: `index.html`, `css/`, `fonts/`, `images/`, `assets/`, `sounds/`, `scripts/`,
  `reports/`, `views/`, `*.shtml`, `favicon.ico`, `manifest.json`, `browserconfig.xml`, `cgi-bin/`
  (excluir `contacts/`, `items/` hasta verificar si sus templates son `.php` requeridos)

### Batch B — /app PHP web legacy (grep-verify cero refs desde KEEP)
- `router.php`, `index.php`, `action.php`, `fetchs.php`, `login.php`, `handoff.php`, `map.php`,
  `orders_design.php`, `postrequest.php`, `schedule_calendar.php`, `verifySMS.php`, `appMobile.php`,
  `image-preview.php`, `cache-sw.php`, `ping.php`, `filesCompiler.php`, `htaccess.php`
- `API/` (auth.php, config.php, countries.php, logout.php, refresh.php) — POS legacy API
- `bff/` (entero)
- `includes/`: `device.php` (reemplazado por DeviceAuth), `assets.php`, `ai_confirm_store.php`
  — verificar cero refs primero
- `libraries/` no-KEEP: `OAuth.php`, `twitter.php`, `simple_html_dom.php`, `timezone.php`,
  `pseudocrypt.class.php` — grep-verify
- `error_log` (basura)

### Batch D — /screens (legacy, NO ruteado, solo referenciado por URL-strings)
`/screens/*.php` (cds, kds, checkoutScreen, receipt, digitalInvoice, giftCardRedeem, orderView,
scheduleConfirm, quoteView, etc.) son las pantallas customer-facing VIEJAS. `router.php` NO las
sirve (cero refs). `/api` las referencia SOLO como strings de URL (`getShortURL('/screens/...')`,
`href`), nunca con `require`/`include` → borrar los archivos NO rompe `/api`. frontend solo
migró `(screen)/checkout`; el resto no está reconstruido.
- **Borrable**: toda la carpeta `/screens/` (verificar primero cero `require/include` de
  `screens/*` desde KEEP/api — confirmado: solo URL-strings).
- **FOLLOW-UP (roadmap, no bloquea)**: reconstruir en frontend las páginas customer-facing que
  `/api` linkea (receipt, digitalInvoice, giftCardRedeem, orderView, scheduleConfirm) y limpiar/
  repuntar la generación de esos links en `api/lib/Sales/SaleService.php` (L334/388/394),
  `api/v1/{transactions,schedule,orders}.php`, `api/lib/services/GiftCardService.php`.

### Batch E — otros top-level a auditar
`cache/`, `scripts/` (raíz) — posibles legacy. Trazar reachability (router.php,
api/bootstrap, frontend) antes de borrar.

**`/assets` (raíz) — BORRADO 2026-06-29 (manual por owner).** NO era puro estático: lo
referenciaba código vivo. FOLLOW-UP (no bloquea, no hay datos/prod):
- `api/lib/services/ItemService.php:63` genera URLs de imágenes de ítems `/assets/250-250/...jpg`.
- `app/includes/functions.php` (lo usa `/api`) hace `the_file_exists(ASSETS_URL.$img)` + arma
  `/assets/src.php?src=...` → el redimensionador `/assets/src.php` ya no existe.
- `frontend/app/(auth)/signup/page.tsx:432` linkea `/assets/terminos.pdf`.
Pendiente: rehacer/repuntar manejo de imágenes de ítems + resizer + PDF de términos en la
arquitectura nueva (subir a S3 / servir desde frontend o /api), y limpiar la generación de
esas URLs en `ItemService` y `functions.php`.

### Batch C — /panel PHP legacy (DESPUÉS de trazar cierre admin)
- todos los `a_*.php`, `account_payments.php`, `billing.php`, `digitalInvoice.php`,
  `empty_page.php`, `franchiser.php`, `get2COrecurring.php`, `index.php`, `inventory*.php`,
  `login.php`, `logout.php`, `main.php`, `mainFranchiser.php`, `orders.php`, `report_*.php`,
  `signup.php`, `user-register.php`, `@.php`, `barcode.php`, `new.cache.*.php`
- `bff/` EXCEPTO lo que el admin use (verificar; frontend admin pega a API/v1/admin directo)
- `API/` EXCEPTO `API/v1/admin/`, `API/lib/{admin_auth,response}.php` (+ lo que esos requieran)

## Protocolo de verificación (cada batch)
1. Borrar candidatos con `git rm`.
2. **Scan de integridad**: para cada `require_once`/`include_once`/`require`/`include` en los
   archivos PHP SOBREVIVIENTES de `api/`, `app/`, `panel/`, resolver el path target y confirmar
   que el archivo existe. Cualquier dangling → revertir ese borrado. (cwd de `/api` = `/app` por
   el chdir → los includes relativos de `functions.php`/`head.php` resuelven contra `/app`.)
3. `cd frontend && npm run build` debe pasar.
4. Commit del batch + push.

## Fase 2 — Extracción `/api/includes` (elimina `/app`)
Mover el cierre KEEP de `/app` a `/api` para cortar la dependencia `/api → /app`:
1. Mover `app/includes/*` (db, functions, simple.config, cors, realtime, rollup, ws_publish,
   phone, jwt, auth_session, errorPage, lib/DB.php) → `api/includes/`.
2. Mover `app/{head,data,app_version}.php` → `api/` (o inline en bootstrap).
3. Mover PSR-4 `Punto\App\*` → `Punto\Api\*` (rename namespace + 9 callers de `/api/lib/*`).
4. Mover `app/languages/`, `app/libraries/{rateLimiter,countries}.php` → `api/`.
5. Sacar `chdir(/app)` + `API_APP_DIR` de `bootstrap.php`; reapuntar todos los `require`.
6. Idéntico para el cierre admin de `/panel` → mover a un home propio si se quiere eliminar
   `/panel` también (o dejar `/panel` solo con el realm admin).
7. Borrar `/app` entero. Verificar `/api` + frontend + admin.

> Fase 2 es delicada (functions.php 26k L). Hacer en pasos chicos, verificando el scan de
> integridad + un boot real de `/api` (curl a un endpoint) tras cada movimiento.

## Changelog
- 2026-06-29 — plan creado. Pendiente ejecución Batch A.
