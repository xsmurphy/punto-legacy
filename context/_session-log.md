<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-06-09 — Deploy a Coolify single-container + onboarding production (commits ea7b67f..5ea3a2d)

- **infra(deploy)**: pasaje de docker-compose 4-services a **container ÚNICO** con `router.php` raíz que despacha por `Host:` header (`panel.*`→`/panel`, `admin.*`→`/panel` con `/admin` prefix forzado, `app.*`→`/app`, `api.*`→`/api`). Puerto 3000 (default de Coolify para Traefik upstream). `install-php-extensions` de mlocati reemplaza compilación de extensiones (8min→30s, OOM eliminado). `ws-server/` queda como container Node aparte.
- **auth(phone-first system-wide)**: convención §31 — `contactPhone` SIEMPRE en E.164, conversión vía `libphonenumber` (giggsey/PHP + bundle JS 1.6.8). Helpers `phoneToE164/phoneFormatNational/phoneIsMobile` en `panel/includes/phone.php` y `app/includes/phone.php`. `findEmailOrPhoneLogin` → `findPhoneLogin` (solo phone). Migration 12: UNIQUE INDEX parcial sobre `contactPhone` para tenants. `signUp` + `send_verification` normalizan server-side. `iso` viaja con phone en payloads.
- **sso(panel→app)**: link "Caja" del sidebar emite JWT pos-app de 15s firmado con `JWT_SECRET` → `app.punto.la/handoff.php` (módulo nuevo) valida `iss`+`exp`+identidad no-vacía, reemite cookie HttpOnly `_jwt` con TTL real, redirige a `/?i=<base64>`. Resuelve no-cross-subdomain cookies entre panel y app.
- **auth(TTLs)**: JWT del POS **eterno** por default (`JWT_TTL=0` → sin claim `exp`, modelo device-pairing §28). JWT del panel separado en `PANEL_JWT_TTL=86400` (24h). Handler global `$(document).ajaxError` en panel detecta 401 y muestra dialog "sesión expirada" antes de hacer logout (evita pérdida silenciosa de trabajo).
- **infra(sessions)**: `docker-entrypoint.sh` nuevo parsea `REDIS_URL` y configura PHP `session.save_handler=redis` en boot → sesiones sobreviven deploys (sino `/tmp` se borra y todos se des-loguean). **Próximo: refactor a JWT-only auth** — empezando por `app/` (26 usos en 3 archivos), después `panel/` (165 usos en 26 archivos). Decisión: NO meter caché al JWT, mover a Redis directo.
- **Bugs notables**: P0 security — router raíz servía `.php` como estático leakeando source (fix: detectar extension PHP y `require` en lugar de `readfile`). `compression_end.php` llamaba `ob_end_flush` sobre buffer ya cerrado → "Oops algo sucedió" en panel. Dashboard Alpine init nunca corría con hash navigation → MutationObserver pattern. Migration 13: seed `plan_code=0` (free) y `=3` (trial) que faltaban → POS quedaba con `users=[]` post-signup.
- **Env vars nuevas / críticas en Coolify**: `PUNTO_API_BASE=http://localhost:3000/API` (panel BFF), `PUNTO_SHARED_API_BASE=https://api.punto.la` (app BFF — distinta API), `JWT_TTL=0` (POS eterno), `PANEL_JWT_TTL=86400`, `MASTER_COMPANY_ID`. Cookies con `SameSite=Lax` y detección HTTPS via `X-Forwarded-Proto` (no `$_SERVER['HTTPS']`).

## 2026-06-07 — F3.4 fix + F3.5 impersonación JWT + workflow optimization (commits fb4a691..456092f)

- **feat(F3.4 fix)**: `saveCompany()` no incluía `balance` en el payload PATCH — agregado. F3.4 queda completo.
- **feat(F3.5)**: "Ingresar como empresa" — `CompanyAdminService::getEnterToken()` genera `_jwt_panel` (JWT_SECRET, iss=panel) para el contacto principal (role=1, main=true, type=0); BFF lo inyecta como cookie HttpOnly y retorna `redirectUrl='/@#dashboard'`; UI abre nueva pestaña con `noopener`. Fixes pre-commit (code-reviewer): empty-token → 502 en BFF, UUID regex validation, `noopener`.
- **docs(workflow)**: code-reviewer ahora solo en commits de alto riesgo (auth/JWT/schema/multi-tenant/billing/CORS). `context-updater` consolidado en `/end-session`, no por commit (~580k tokens/sesión ahorrados). PostToolUse hook y skill end-session actualizados.
- **Estado F3**: completo (F3.1–F3.5 en main). **Próximo**: F4 (desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant — alto riesgo) o F5 (login por teléfono).

## 2026-06-07 — F3.3 delete cascade de company (commit 5a6e4ab)

- **feat(admin/F3.3)**: `CompanyAdminService::softDelete()` (status='cancelled'+blocked=1, reversible) y `hardDelete()` (~57 DELETEs ordenados en una única TX PG, ROLLBACK on any error, cubre 38+ tablas; self-referential FKs NULLed en preámbulo; `device` auto-cascade).
- **API DELETE**: `?type=soft` → softDelete; `?type=hard` requiere body `{"confirm":"<company-name>"}` con name-match antes de ejecutar. BFF con timeout 60s (hardDelete lento en tenants grandes).
- **UI**: "danger zone" al final del drawer — botón "Suspender" (confirm() dialog) + botón "Eliminar" (renderDeleteConfirm: type-name-to-confirm inline). CSS: `.danger-zone`, `.btn-warn`, `.btn-danger`, `.btn-danger-primary`, `.danger-notice`.
- **Próximo**: F3.4 — Billing (view/edit de plan y créditos por company, hoy en main.php).

## 2026-06-07 — F3.2 update company — PATCH endpoint + form de edición (commit 5fe4b39)

- **feat(admin/F3.2)**: `CompanyAdminService::update()` — PATCH semántico con whitelist de columnas directas (status/plan/blocked/smsCredit/discount/planExpired/isTrial/expiresAt) + JSONB config merge (`settingName`/`settingCountry` vía `config || ?::jsonb`). Devuelve `['ok'=>true]` o `['ok'=>false,'error','code']` (404/422/500). No-op seguro si el payload no trae campos reconocidos.
- **API/BFF**: `panel/API/v1/admin/companies.php` agrega PATCH (lee `php://input` JSON, gateado por `adminMiddleware()`). `panel/bff/admin/companies.php` agrega PATCH con curl inline + pass-through 4xx (evita colapso a 502 y facilita feedback en el form).
- **UI**: botón "Editar" en el drawer de detalle → `renderEdit()` con form de 10 campos. `currentCompany` en closure (re-render sin fetch). Save PATCH → reload detalle + tabla. 401/403 → `redirectToLogin()`.
- **MySQL migration**: marcada como completa en el roadmap (eliminación real fue en commit 5e48ba7).
- **Próximo**: F3.3 — delete cascade de company (soft/hard, cascade a contacts/transactions).

## 2026-06-06 — Bug bashing post-PSR-4 + DX en localhost (commits 4f28aa3..46468e2)

- **5 bugs cazados durante un debug session del POS** (PHP 8 hardening + JSONB-demote + UX) — ninguno individual ameritó context-updater pero el cumulativo da contexto del por qué del día:
  - `4f28aa3` action.php processData: PHP 8 endureció `array_key_exists()` (rechaza null) → 500 en cada sync vacío del front. Guard `is_array($data)` antes del chain.
  - `2c3eb49` fetchs.php pings spammeaban 401 "nnd": el query SELECTeaba columnas (`companyLastUpdate`, `itemsLastUpdate`, `customersLastUpdate`) **demoted a JSONB** (§22.8) → silent fail → `strtotime(null)=0` → siempre rejected. Fix: `SELECT *` para que `_flattenJsonb` exponga las keys.
  - `7ac6de9` `Arr::sizeOf()` TypeError con strings numéricos — el return type `int|float` (introducido en Slice 6) rechazaba el string crudo que el legacy `counts()` devolvía. Fix: `$val + 0` (coerción que preserva int/float).
  - `789c4a1` UI bloqueante "Puede añadir hasta artículos" cuando `settingItemsSaleLimit` venía `""` (demoted a JSONB sin valor) → `&&` short-circuitaba a falsy → spam de alert vacío en cada add. Fix: `parseInt + !(limit > 0)` para tratar vacío como "sin límite".
  - `46468e2` localhost dev: deshabilitar SW registration en `checkIfUrlDebug()` + emitir `Cache-Control: no-store` en TODAS las respuestas (`router.php` sirve estáticos manualmente con MIME map). Adiós cache hell entre reloads.
- **Causa común**: el endurecimiento PHP 8 + return types del refactor PSR-4 (Slices 3/6/15) expusieron varios bugs latentes que el legacy disimulaba con warnings silenciosos.
- **DX adicional descubierta**: el server `:8002` se moría repetido por `getFileContent()` (cerrado después en `7bfb800`). Workaround temporal hasta ese commit: `php -d max_execution_time=0 -S localhost:8002 router.php`.

## 2026-06-06 — "Eliminar Punto de este dispositivo" — auto-revoke device + rebrand (commit 70dbc22)

- **feat(auth): user-initiated revoke (70dbc22):** cierra el flow del device pairing del lado del usuario. `app/API/logout.php` (POST-only, evita CSRF hot-link por GET): decode JWT, UPDATE device status=0 + revokedBy=userId propio, `jwtInvalidateDeviceCache()` (efecto inmediato), mata cookie `_jwt` (expires=1970), responde `{ok:true}` aun sin token. GET → 405. Handler `#reset` en `app.js`: POST con timeout 5s; callback `complete` corre `cleanupLocal` siempre (offline también puede desinstalar) — `ncmStorage.nuke + localStorage.clear + sessionStorage.clear + barrer cookies JS + unregister SW + caches.delete + reload`. E2E: 6 escenarios.
- **Rebrand ENCOM→Punto** en 3 puntos de UI: `app/index.html` L2490, `app/index.php` L2515, alert title en `app.js`. String canónico: "Eliminar Punto de este dispositivo".
- **Deuda que cierra:** user-initiated revoke del slice device pairing. Queda pendiente: UI panel del tenant (admin-initiated, diferida a React).
- **Docs actualizados:** `05-modulos-clave.md` (logout.php en app/API/), `08-convenciones.md` (§29.B — flow user-initiated + string canónico), `10-roadmap.md` (✅ user-initiated revoke en entrada device pairing).

## 2026-06-06 — Device pairing backend completo + fix getFileContent timeout (commits 7bfb800 + a3fefb4)

- **feat(auth): device pairing — revocación per-dispositivo (a3fefb4):** cierra la deuda enunciada en §28 post-JWT_TTL=10y. Tabla `device` (migración 11, aplicada manual), helper `app/includes/device.php`, `deviceRegister()` llamado desde login + auth.php → claim `did` en JWT. `jwt_middleware.php` valida `device.status` con cache file 60s + modo conservador si BD no disponible. `refresh.php` chequea device antes de re-emitir. Backwards compat: tokens sin `did` siguen pasando. E2E: 7 escenarios.
- **fix(app): getFileContent timeout 5s + ignore_errors (7bfb800):** `file_get_contents` en `getFileContent()` esperaba hasta `max_execution_time` (30s) si la URL externa no respondía → mataba el server built-in single-thread. Fix: stream context `http.timeout=5` + `ignore_errors=true`. Callers: login.php (validación SMS 2FA). Bug fix puro, no cambia arquitectura.
- **Deuda pendiente de este slice:** UI panel para listar/revocar devices (diferida a React); migration runner automático sigue como deuda abierta.
- **Docs actualizados:** `02-arquitectura.md` (device pairing completado), `04-modelo-de-dominio.md` (tabla device + migración 11), `05-modulos-clave.md` (device.php + jwt_middleware actualizado), `08-convenciones.md` (§28 actualizado + §29 nuevo sobre revocación), `10-roadmap.md` (entrada ✅ device pairing backend).

## 2026-06-06 — JWT_TTL /app subido a 10 años — modelo device pairing (commit 7e1b26f)

- **Cambio**: `JWT_TTL` en `.env.example` pasa de `28800` (8h) a `315360000` (10 años). `ADMIN_JWT_TTL` queda en 8h.
- **Decisión de arquitectura**: el JWT de /app NO es una sesión — es un *device pairing* (análogo a Apple TV pareado a una cuenta). El cajero entra/sale con PIN; el JWT representa el dispositivo pareado. TTL corto paraliza cajas apagadas un fin de semana.
- **Docs actualizados**: `06-infraestructura.md` (tabla env vars + nota modelo), `02-arquitectura.md` (tabla realms + nota distinción capa dispositivo/cajero), `08-convenciones.md` (§28 nuevo).

## 2026-06-05 — F3.1 Companies read-only + SMTP/NCM creds → env (commits e51d5e7..747384d)

- **F3.1 Admin realm deployable**: `CompanyAdminService` (listAll/get/getCounts — owners+counts batched con IN(), filtro+total post-fetch en PHP) + `panel/API/v1/admin/companies.php` + BFF + `panel/admin/companies.html` + `companies.js` (dark theme, drawer detalle role=dialog, vanilla JS, todo `esc()`). Router `/admin/companies`. `home.html` card "Empresas". 884 LOC netas.
- **Decisión de marca**: campo API `externalCustomerId` (no `encomCustomerId`) — CLAUDE.md regla #2.
- **Patrón nuevo §27**: `mergeConfig()` inline en services de `/admin` para aplanar JSONB sin importar `functions.php` del realm tenant.
- **Fix P1 Slice 15**: credenciales SMTP SendGrid (`SENDGRID_SMTP_USER/PASS`) y NCM SMS (`NCM_SMS_API_KEY/COMPANY_ID`) movidas de literales hardcodeados a env vars. Definidas en `.env.example` + `app/` y `panel/includes/simple.config.php`.
- **Próximo**: F3.2 — update company (nombre/config/settings/módulos en TX).

## 2026-06-05 — PSR-4 Slices 11-15: Document + Money + Inventory + GiftCard + Notification (commits 2cae098..532be24)

- **5 clases nuevas** bajo `Punto\App\Domain\` y `Punto\App\Services\`: `Document` (12 callers), `Money` (702 callers — hogar canónico de todo el formateo monetario), `Inventory` (116 callers — hogar canónico de stock y COGS, incluye `manageStock` crítico con 27 callers), `GiftCard` (1 caller), `Notification` (76 callers). Total nuevos callsites cubiertos: **~907**.
- **Métricas post-Slices 11-15**: `functions.php` 3203 → **2560 líneas** (−643). autoload: **3188 clases**. Callsites migrados acumulados: **~7573**.
- **Progreso PSR-4**: **15/16** sub-slices completos. El money path, inventory, y notificaciones tienen hogar namespaced definitivo. Slice 16 (eliminación de wrappers deprecated) **DIFERIDO post-release** (≥2 releases en prod antes de remover).
- **Deuda P1 anotada**: credenciales de cron JWT — `cronCreateRecurringInvoice.php` re-somete a `action.php` sin JWT → 401 (pre-existente, no causada por estos slices). Registrada como tarea.
- **Próximo**: Phase AI.1 (sin bloqueos del refactor PSR-4).

## 2026-06-05 — PSR-4 Slices 9-10: App\Domain\Customer + App\Database\Query (commit 51d600b)

- **Slice 9** — `app/Domain/Customer.php` — `final class Customer`, 11 métodos estáticos, 139 callsites. Fix P0: `getName(mixed $data)` — tipado relajado de `array` a `mixed` + early-return on false (el legacy toleraba `false` sin fatal). Métodos clave: `getCustomerData`/`getContactData` (38+36), `getCustomerName` (36), `getAllContacts` (15), `manageLoyalty` (4).
- **Slice 10** — `app/Database/Query.php` — `final class Query`, 7 métodos, 1273 callsites. Wrappea el god node `ncmExecute` (1035 callers): `execute()` llama `self::flattenJsonb()` directo; `getValue()` llama `self::execute()` directo. Hito arquitectónico: el god node DB de /app tiene hogar namespaced.
- **Métricas**: `functions.php` 3599 → 3203 líneas (−396). autoload: 3183 clases. PHP lint 0 regresiones · App :8002 HTTP 200.
- **Progreso plan PSR-4**: 11/16 sub-slices ✅. ~6139 callsites migrados acumulados.
- **Próximo**: Slice 11 — `App\Domain\Document` (docNumber, 16h, riesgo crítico — comprobante audit).

## 2026-06-05 — PSR-4 Slice 8: App\Domain\Store — 67 callsites, 5 funciones (commit 7545b02)

- **Segunda clase en `Punto\App\Domain\`** — `app/Domain/Store.php` — `final class Store` con 5 métodos estáticos que reemplazan 5 funciones globales de outlets/store en `functions.php`. Mismo patrón Wrapper §26.1 del Approach C.
- **67 callsites preservados** sin breaking changes: `getCurrentOutletName` 41 (la más usada), `selectInputOutlet` 19, `getOperatingCost` 3, `getAllOutletData` 2, `getOutletCount` 2. 5 wrappers `@deprecated Slice 8` en `functions.php`.
- **Métricas acumuladas post Slice 8**: `functions.php` 3658 → 3599 líneas (−59). autoload: 3181 clases. PHP lint 0 regresiones. App :8002 HTTP 200.
- **Progreso plan PSR-4**: 9/16 sub-slices ✅. **4727 callsites migrados** acumulados (4660 prev + 67). `Punto\App\Domain\` tiene 2 clases: `Taxonomy` (Slice 7) + `Store` (Slice 8).
- **Próximo**: Slice 9 — `App\Domain\Customer` (getData, loyalty, 20h, riesgo alto — 60 callers, GDPR).

## 2026-06-05 — PSR-4 Slice 7: App\Domain\Taxonomy — 112 callers, 12 funciones (commit 416f4e9)

- **Primer clase en `Punto\App\Domain\`** (cruce de namespace Helpers/ → Domain/: utilities puras → lógica de negocio con acceso a BD). `app/Domain/Taxonomy.php` — `final class Taxonomy` con 12 métodos estáticos que reemplazan las 12 funciones globales de taxonomy/payment en `functions.php`.
- **112 callsites preservados** sin breaking changes: getTaxonomyName 28, getPaymentMethodName 25, printOutTags 12, getAllItemCategories 13, getCustomTemplates 9, getTaxValue 8, getTaxonomyArray 6, selectInputTaxonomy 4, getTaxonomyIdOrInsert 3, getTagsDefaults 2, getAllTaxonomyNames 1, getCategoriesIds 1. 12 wrappers `@deprecated Slice 7` en functions.php.
- **Cache layer en `getName()`**: mapa estático `$cache[companyId][id] = name` para evitar N+1 en loops de `printOutTags`. No existía en el legacy.
- **functions.php**: 3777 → 3658 líneas (-119). autoload: 3180 clases. PHP lint 0 regresiones · App :8002 HTTP 200.
- **Progreso plan PSR-4**: 8/16 sub-slices ✅. **4660 callsites migrados** acumulados (4548 prev + 112). Próximo: Slice 8 — `App\Domain\Store` (12h, riesgo bajo).

## 2026-06-05 (cierre de jornada) — PSR-4 mega-sesión: 5 slices, 4548 callsites migrados

- **Hecho**: jornada masiva del refactor PSR-4 (ítem #4 top-5 estructurales de /app). Slices 2-6 ejecutados consecutivamente con el patrón Wrapper §26.1 (Approach C "Híbrido Gradual"). 8 clases nuevas en `Punto\App\*` (Json, Output, Validation, Str, Date, Math, Arr, Cond). Commits ceed82d..6167c20. Ver entries individuales abajo para detalles por slice.
- **Métricas acumuladas**: **4548 callsites legacy preservados** sin breaking changes (761 + 2298 + 268 + 185 + 1036). `functions.php`: 5117 → 3777 líneas (-26.2%). CI verde en cada commit. Smoke unitario sobre cada slice (35-22-16-13-22 tests).
- **Decisión validada**: el patrón Wrapper transparente funciona independientemente del conteo real de callers — estimación original tendió a quedarse 5-6× corta (validity estimado 130 → real 716; toUTF8 estimado 39 → real 238). Riesgo cero confirmado en cluster utilities. **Sigue valiendo para slices restantes**.
- **Pendiente**: Slice 7 — `App\Domain\Taxonomy` (12h, riesgo medio). Marca el cruce de namespace `Helpers/` → `Domain/` (utilities puras → lógica de negocio con DB). 9 sub-slices restantes (~183h).
- **Atención**: en Slice 6 los tests de Math "fallaron" 4 casos por `floor/ceil/round` que retornan `float` en PHP 8.x (no `int`). Verificado: es paridad verbatim del legacy, no bug. Mismo análisis para `Arr::getKey([], 'a')` → `''` (no `false`) por `iftn(false, false)` semantics. Documentado en commits.

## 2026-06-05 — PSR-4 Slice 6: Math + Arr + Cond — 1036 callers en 3 clases (commit 6167c20)

- **Slice 6 del plan PSR-4** (sub-slice 7/16). Migra 8 funciones utility a 3 clases cohesivas siguiendo §26.1. Slice más grande hasta ahora pero riesgo bajo (utilities puras sin DB).
- **3 archivos nuevos en `app/Helpers/`**: `Math` (divide/round/diff), `Arr` (sizeOf/getKey/safeExplode/safeImplode), `Cond` (iftn — la 3a función más usada del POS con 778 callers).
- **1036 callsites preservados**: iftn 778, explodes 134, divider 50, implodes 36, counts 34, rester 3, arrKey 1, rounder 0 ext. 8 wrappers de 1 línea en functions.php con `@deprecated`.
- **Bonus refactor interno**: `Validation::isValid` ahora usa `Arr::sizeOf` directo (en vez de hop a global `counts()`). Cierra el ciclo: namespace puro sin depender de globals. `Math::diff` simplificado a `abs($a-$b)` (vs if/elseif legacy, misma semántica).
- **Smoke unitario 41 tests**: 35 pasan + 6 confirman paridad VERBATIM con legacy (floor/ceil/round retornan float en PHP 8.x, Arr::getKey con default `false` → `''` por iftn semantics). PHP lint 0 regresiones · App :8002 HTTP 200 · CI verde.
- **Progreso del plan PSR-4**: 7/16 sub-slices ✅ (41h de 220h, 18.6%). functions.php: 3895 → 3777 líneas. Acumulado: **4548 callsites migrados** sin breaking changes (3512 prev + 1036).
- **Próximo**: Slice 7 — `App\Domain\Taxonomy` (12h, riesgo medio, primer dominio en `Domain/`).

## 2026-06-05 — PSR-4 Slice 5: App\Helpers\Date — fechas/tiempo 185 callers (commit c098728)

- **Slice 5 del plan PSR-4** (sub-slice 6/16). Migra 5 funciones de fecha/tiempo siguiendo §26.1. Riesgo bajo confirmado.
- **`app/Helpers/Date.php` (NUEVO, 175 líneas)**: `Date::nice` (formato "Domingo 03 de Junio, 2026"), `Date::niceAgo` ("Hace 2 horas"), `Date::nextPeriod` (cron recurrentes), `Date::startEndTime` (split rango horario), `Date::translateWeekName` (Monday→Lunes). Acceso a `$GLOBALS['meses']` con fallback `[]` para mock-friendly testing.
- **185 callsites preservados**: niceDate 166, getNextDatePeriod 9, niceDate2 5, dateStartEndTime 5, translateNamesOfWeek 0 externos. 5 wrappers de 1 línea en functions.php con `@deprecated`.
- **Quirks legacy preservados verbatim**: rama 'fortnight' vacía en nextPeriod (no-op), arg `$lang` ignorado en translateWeekName (rama 'br' comentada en legacy), '0000-00-00 00:00:00' → 'Sin fecha'.
- **`strToDate` NO se migra** — vive en `panel/includes/functions.php` (fuera de scope del refactor /app).
- **Smoke unitario 22/22 tests OK**: nice (8 casos: mes/año/hora/weekDay/noDay), niceAgo edge case, translateWeekName, nextPeriod (daily/weekly/monthly/quarterly/yearly + unknown), startEndTime, 4 wrappers delegan. PHP lint 0 regresiones · App :8002 HTTP 200 · CI verde.
- **Progreso del plan PSR-4**: 6/16 sub-slices ✅ (37h hechas de 220h, 16.8%). functions.php: 3957 → 3895 líneas.
- **Próximo**: Slice 6 — `App\Helpers\Utils` (divider, counts, otros utilities — ~60+ callers, riesgo bajo).

## 2026-06-05 — PSR-4 Slice 4: App\Helpers\Str — texto/encoding 268 callers (commit fc213f4)

- **Slice 4 del plan PSR-4** (sub-slice 5/16 del ítem #4). Migra 4 funciones de texto/encoding siguiendo §26.1. Riesgo bajo confirmado.
- **`app/Helpers/Str.php` (NUEVO, 162 líneas)**: `Str::toUtf8` (corrige mojibake Ã¡→á + mb_convert), `Str::isHtml` (strip_tags compare), `Str::markupHtml` (bidireccional WhatsApp markup ↔ HTML), `Str::tryBase64Decode` (decode + html_entity_decode si válido). Nombre `Str` (no `String`) por convención Laravel/built-in.
- **268 callsites preservados** sin modificar: toUTF8 238 (estimado 39 → 6× off), markupt2HTML 19, isBase64Decode 9, isHTML 2. 4 wrappers de 1 línea en functions.php con `@deprecated`.
- **Semántica VERBATIM**: conserva `</i>` duplicado en HtMrules (paridad legacy, posible bug histórico inocuo) + retorno '-' cuando mb_convert falla + aceptación de mixed para null/array → ''.
- **Smoke unitario 16/16 tests OK**: mojibake fix, null/empty handling, markup bidireccional con detección automática (MtH default, HtM si tags), base64 valid/invalid, todos los wrappers delegan correctamente. PHP lint 0 regresiones · App :8002 HTTP 200 · CI verde.
- **Progreso del plan PSR-4**: 5/16 sub-slices ✅ (29h hechas de 220h, 13.2%). functions.php: 4022 → 3957 líneas (-65 al colapsar markupHtml de 77 líneas a wrapper).
- **Próximo**: Slice 5 — `App\Helpers\Date` (niceDate, getNextDatePeriod, ~30 callers reales TBD, riesgo bajo).

## 2026-06-05 — PSR-4 Slice 3: App\Helpers\Validation — linchpin 2298 callers (commit 3fdeeb5)

- **Slice 3 del plan PSR-4** (sub-slice 4/16 del ítem #4 top-5). Migra las 4 funciones de validación de `functions.php` siguiendo el patrón canónico §26.1 (Wrapper → Clase namespaced).
- **Linchpin del refactor**: `validity()` tiene **716 callers** vs los 130 estimados en el plan original (5.5× off). El patrón funcionó igual gracias al wrapper transparente. Total callsites preservados: **2298** (validity 716 + validateHttp 1524 + validateBool 58 + validateResultFromDB n).
- **`app/Helpers/Validation.php` (NUEVO, 111 líneas)**: `Validation::isValid` (núcleo, preserva quirk del 'undefined' literal del front JS), `::fromRequest` (lectura $_GET/$_POST), `::http` (alias con `db_prepare`), `::fromDbResult` (RecordCount check). 4 wrappers de 1 línea en functions.php con `@deprecated`.
- **Smoke unitario 13/13 tests OK**: edge cases isValid(null/""/"undefined"/0/[]/email válido/email inválido) + delegación wrapper. PHP lint 0 regresiones · App :8002 HTTP 200 · CI verde.
- **Progreso del plan PSR-4**: 4/16 sub-slices ✅ (21h hechas de 220h, 9.5%). functions.php: 4062 → 4022 líneas.
- **Próximo**: Slice 4 — `App\Helpers\String` (toUTF8, markupt2HTML, ~80+ callers, riesgo bajo).

## 2026-06-05 — PSR-4 Slice 2: App\Http\Response poblada — 761 callers legacy intactos (commit ceed82d)

- **Slice 2 del plan de migración `functions.php` → PSR-4** (sub-slice 2 del ítem #4 top-5). Establece el **patrón canónico "Wrapper → Clase namespaced"** (Approach C) que guiará los 13 sub-slices restantes. Primer código REAL en `Punto\App\*`.
- **2 clases nuevas en `app/Http/Response/`**: `Json` (`::send` reemplaza `jsonDieResult`; `::die` reemplaza `jsonDieMsg`) y `Output` (`::dai` reemplaza `dai`). 761 callers legacy (61+158+542) preservados sin modificar — los 3 wrappers de `functions.php` delegan a las clases nuevas en 1 línea con `@deprecated`.
- **`app/Helpers/SmokeTest.php` ELIMINADA** (clase transitoria del Slice 0, cumplida su función de verificar el autoloader). `functions.php`: 4068 → 4062 líneas.
- **Validación end-to-end**: `composer dump-autoload` 3173 clases · PHP lint 0 regresiones · App :8002 HTTP 200 · `GET /fetchs.php` sin JWT → `{"error":"Invalid data"}` 401 (shape idéntico pre-slice) · CI verde (3 jobs paralelos).
- **Vault actualizado**: `08-convenciones.md §26.1` (patrón wrapper + tabla clases existentes) · `05-modulos-clave.md` (tabla PSR-4 con clases) · `10-roadmap.md` (Slice 2 ✅, progreso 3/16, plan sub-slices) · `docs/PLAN_functions_php_PSR4.md` (estado actualizado). **Próximo**: Slice 3 — `App\Helpers\Validation` (validity, 130 callers, linchpin, 8h).

## 2026-06-04 — PSR-4 Slice 0: estructura `Punto\App\*` en /app (commit 8a7819c)

- **Slice 0 del plan de migración `functions.php` → PSR-4** (ítem #4 del top-5 mejoras estructurales de /app). ZERO breaking changes: ningún archivo PHP existente tocado. PHP lint 0 errores, CI verde, app :8002 → HTTP 200.
- **`app/composer.json` (NUEVO)**: autoload PSR-4 con 5 prefijos: `Punto\App\Helpers\`, `Punto\App\Domain\*`, `Punto\App\Http\`, `Punto\App\Services\`, `Punto\App\Database\`. `composer dump-autoload --optimize` → 3172 clases registradas.
- **Estructura de directorios creada**: `app/{Helpers,Domain/{Customer,Money,Inventory,Document,Store,Taxonomy,GiftCard},Http/Response,Services/Notification,Database}/` con READMEs cortos en cada nivel. `SmokeTest.php` transitoria verificada (se elimina en Slice 1).
- **Decisión arquitectónica**: Approach C (Híbrido Gradual) — wrappers en `functions.php` se mantienen 2+ releases; código nuevo usa PSR-4; migración función-por-función sin big-bang. Namespace `Punto\App\*` espeja `Punto\Api\*` (ya en `/api/lib/`).
- **Plan completo**: `docs/PLAN_functions_php_PSR4.md` — 180 funciones auditadas, 32 dead code candidates (Slice 1, 4h), 16 sub-slices, estimación 220h (11 sem FTE, 7 con 2 devs). Top 5 riesgos documentados.
- **Vault actualizado**: `10-roadmap.md` (sección top-5 + Slice 0), `02-arquitectura.md` (namespace dual), `05-modulos-clave.md` (tabla PSR-4), `08-convenciones.md` (§26 — reglas de código nuevo en /app).

## 2026-06-04 — CI mínimo GitHub Actions + .editorconfig (commits 17a2293 + 7ab230a)

- **`.github/workflows/ci.yml` (NUEVO)**: 3 jobs paralelos: `php-lint` (`php -l` sobre diff PHP 8.4), `js-syntax` (`node --check` sobre diff JS Node 20), `composer-validate` (`composer validate --strict` en app/ y panel/). Cancel-in-progress activado. Dispara en push y PR a main.
- **Diseño clave**: CI valida SOLO archivos cambiados (no el repo entero). Deuda histórica (3 archivos PHP rotos en panel/ — 0.8%) no bloquea PRs existentes; archivos nuevos/tocados sí se validan al instante.
- **`.editorconfig` (NUEVO)**: UTF-8, LF, 2 espacios general (4 en PHP, tab en Makefile), final newline, trim trailing whitespace. Excepciones vendor/cach y *.min.{js,css}.
- **Fix primer run (7ab230a)**: `composer validate --strict` fallaba sin `license` declarada — agregado `"license": "proprietary"` a app/ y panel/ `composer.json`.
- **Scripts nuevos**: `package.json` raíz agrega `lint:js`, `lint:php`, `lint`; cada `composer.json` agrega scripts `lint` y `lint:strict` para reproducir CI localmente.
- **Deuda detectada y documentada en `docs/CI.md`**: `panel/a_report_schedule.php:449`, `panel/a_report_production.php:421`, `panel/languages/en.php:45` — 3/378 archivos PHP (0.8%). Quien toque esos archivos debe arreglarlos antes de commitear.

## 2026-06-04 — Consolidación fetch handlers + cierre de deuda de seguridad Hashids (commit 2aa149f)

- **`app/fetch.php` ELIMINADO** (`git rm`, -725 líneas): versión "moderna" con JWT obligatorio pero sin callsites en el front (auditado en app.js / panel/ / cache-sw.php / .htaccess / router.php). Código muerto — 0 regresiones.
- **`app/fetchs.php` simplificado** (-39 líneas netas): fallback Hashids legacy eliminado del branch `else`. Ahora `jwtAuthenticate()` falla → 401 directo. Header `X-Legacy-Auth: 1` ya no se emite. `$rateLimiterId` = `$_SERVER['REMOTE_ADDR']` (era `$_POST['outletId']`, consistente con action.php). Check de mismatch `$postedCompanyId` ahora se hace SIEMPRE.
- **Deuda de seguridad histórica cerrada**: el fallback Hashids era la única superficie que aceptaba identidad del request sin firma JWT. Eliminado definitivamente. Sin cambios funcionales para clientes legítimos.
- **Vault actualizado**: `10-roadmap.md` (estado auth app, Phase 1 notas, sección deprecation fallback, pendientes reestructura), `docs/SESSION_CONTEXT.md` (refs a `fetch.php` y `X-Legacy-Auth` obsoletas marcadas).

