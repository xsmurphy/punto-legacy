<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

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
