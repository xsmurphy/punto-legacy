<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

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

## 2026-06-03 — HITO MÁXIMO: app/load.php COMPLETAMENTE ELIMINADO — Slice 43 (commit cc02762)

- **HITO HISTÓRICO: `app/load.php` ya no existe.** El dispatcher legacy de reads del POS que tenía 1714 líneas al inicio del trabajo fue vaciado progresivamente en Slices 1-43 y eliminado con `git rm` en este commit. 1714 → 0 líneas (-100%). ~44 handlers migrados durante múltiples sesiones.
- **Slice 43 — Bancard + Pix (los últimos 3 handlers)**: `BancardService::createQR/refreshQR/cancelQR` (llama `BANCARD_QR_API` con Bearer; construye `identifier` JSON con IDs del JWT). `PixService::getToken` (OAuth2 client_credentials) + `createQR` + `verifyTransaction` (paridad con legacy; deuda de diseño: token Pix viaja cliente↔servidor — documentada en docblock, mejora futura). Endpoints: `api/v1/bancard.php` + `api/v1/pix.php` (ambos con `apiOk` envelope). BFFs: `app/bff/bancard.php` + `app/bff/pix.php`. 8 callsites en `app/scripts/app.js` repunteados.
- **Fix P0 pre-commit**: endpoints devolvían JSON crudo (sin `{ok,data}`) → `bffDecodeEnvelope` siempre `ok=false` → todo el money path Bancard/Pix muerto. Corregido envolviendo en `apiOk`.
- **Resumen del trabajo total load.php (Slices 1-43)**: 1714 → 0 líneas, ~44 handlers, patrón BFF→API→Service establecido y validado en cada slice.
- **Vault actualizado**: `10-roadmap.md` (hito Slice 43, servicios nuevos, reducción -100%), `02-arquitectura.md` (god nodes: load.php eliminado), `05-modulos-clave.md` (BancardService + PixService + endpoints bancard/pix agregados, load.php eliminado documentado).


## 2026-06-03 — Slice 42: ePOSPending + verifyTransactionEPOS → VPaymentService (commit 7eea7d0)

- **`VPaymentService::getPending` + `getByUID` (NUEVO)**: 2 métodos read-only. `getPending`: SELECT FROM vPayments WHERE UID IS NULL OR UID='' (query directa en vez del proxy legacy a panel/API/get_vpayments). `getByUID`: busca pago por UID. Fix P1 detectado en code-review: campo `source` omitido inicialmente (el front lo usa para discriminar 'dinelcoPOS'→ePOS Card vs ePOS genérico). Endpoint `api/v1/vpayments.php?resource=pending|byUID`. BFF cases en `bff/vpayments.php`. 3 callsites repuntados en app.js (L2121/2387/2677).
- **load.php**: 250 → 216 líneas (-87% total). Quedan solo 3 handlers: bancardQR, pixQR, verifyTransactionPix (money path Bancard — requieren credenciales sandbox).
- **Para eliminar load.php definitivamente**: solo faltan los 3 handlers de Bancard/Pix. Una vez que estén configuradas las credenciales, es trabajo de ~2h más + borrar el archivo.

## 2026-06-03 — Slice 41: `calendar_*` → ScheduleService, cierre del Cluster A (commit 9306d30)

- **3 métodos nuevos en ScheduleService**: `getCalendarSlots(mode, date, weekRange?, resource?, companyId, outletId)` — resources/week views; fix PG: IN($csv UUIDs interpolados) → IN(?,?,...) bindeados. `getCalendarAgenda(date, companyId, outletId)` — agenda mensual agrupada por día; mejora: JOIN a `contact` en vez del N+1 SELECT del legacy. `getCalendarMonthCounts(date, companyId, outletId)` — counts por día. HTML del calendar_month se construye en el endpoint compositor (presentation layer separado del Service). BFF detecta prefix `calendar_` en el `load` y mapea a los 4 `mode`s.
- **HITO — Cluster A 100% COMPLETO**: los 5 handlers de load.php que proxyaban a `panel/API/*` vía `curlContents` migrados en 4 slices: Slice 38 (tin→TinService), Slice 39 (userLocation→OrderService), Slice 40 (ordersPanelAPI→OrderService), Slice 41 (calendar_*→ScheduleService). load.php: 519 → 250 líneas (-270 en este slice). Reducción total desde inicio: 1714 → 250 líneas (-85%).
- **Estado final de load.php (250 líneas, 5 handlers)**: solo APIs externas diferidas (Bancard ×3, ePOS ×2) — requieren sandbox de proveedores (money path real). Decisión documentada: no migrar hasta tener sandbox.
- **Archivos cambiados**: `api/lib/services/ScheduleService.php` (+213), `api/v1/schedule.php` (+83), `app/bff/schedule.php` (+47), `app/scripts/app.js` (3 callsites repunteados líneas 8697/9447/9475), `app/load.php` (-270).

## 2026-06-02 — Slice 39: `load=userLocation` → OrderService granular (commit c052fe5)

- **`OrderService::getNextDeliveryForUser` (NUEVO)**: query única tenant-scoped que trae la próxima orden status=5 ("en camino") del usuario. JOINs: `contact` (customerName con fallback contactSecondName), `toAddress` + `customerAddress` (delivery address per-orden, NO contact.contactLatLng default).
- **Endpoint compositor**: `api/v1/orders.php?resource=userLocation&id=<userId>` — lookup contact (tracking activado) + parseo de coords + delivery. Reproduce paridad con `panel/API/get_orders.php:163-256` sin migrar las 281 líneas del endpoint fat.
- **Patrón validado**: "API granular + BFF compone" (commit c4edef9, slice 32) aplicado al Cluster A de load.php — en vez de migrar endpoints fat de panel/API, exponer queries granulares específicas para cada callsite del front. Plantilla para los 4 calendarios + ordersPanelAPI restantes.
- **load.php**: 662 → 611 líneas, 10 → 9 handlers vivos. Cluster A: 5 → 4 handlers (~525 líneas restantes).
- **P0 atrapado en code-review**: shape inicial leía lat/lng/address de `contact` (default del cliente) en vez de `customerAddress` per-orden. Corregido antes del commit.

## 2026-06-02 — Slice 38: migración de `load=tin` a TinService vía Marangatu (commit dc33d7e)

- **TinService (NUEVO)**: `api/lib/services/TinService.php` — `lookup($id, $country): ?array`. Llama directo a `https://marangatu.set.gov.py/eset-restful/contribuyentes/...`, descarta DV si el RUC viene con `-DV`. Solo PY soportado. Shape `{id, tin, name, fullName, address, phone}` en paridad con el legacy `panel/API/get_tin.php` (campo `bday` siempre vacío en legacy → omitido). Sigue patrón §22.14 (`namespace Punto\Api\Services`, `final class`, DI con `TenantContext`).
- **Path nuevo**: `/app/scripts/app.js:20987` → `bff/tin.php` → `api/v1/tin.php` → `TinService` → Marangatu. Eliminado hop intermedio legacy `/app → panel/API/get_tin.php → Marangatu`.
- **Fallbacks eliminados por decisión explícita**: BD `ruc_py` (tabla `incomepo_rucpy` ya no existe) y búsqueda por CI vía `eas.suace.gov.py` (fuera de scope del ítem "RUC"). Decisión del usuario: "Solo Marangatu — limpio".
- **load.php**: 665 → 662 líneas, 11 → 10 handlers vivos. `tin` sale de "APIs externas diferidas"; quedan 5 (Bancard ×3, ePOS ×2).
- **Deuda anotada**: `panel/API/get_tin.php` sigue vivo (otros consumers posibles — no tocar). `app/tin.php` / `app/rucs.php` en `/app` raíz: verificar callsites; si 0, borrar en slice futuro.
- **Patrón nuevo**: primer slice donde `/api` llama directo a una API externa pública sin proxy a `panel/API`. No se agrega como convención aún (esperar 2-3 ejemplos más).
