<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-06-16 (tarde) — fixes panel-next + cleanup masivo de contexto + workflow
Commits `1ce7a08..1ec8880` (9). Highlights: fix `itemSold` en `_getTableSchema()` (422 vacío en /purchase); 5 fixes UX panel-next (phone flags, Tab→nueva línea en /purchase, favicon, menú settings); poda agresiva context/ (-44.5%, archives + split convenciones); `context-updater` apagado definitivamente; nueva `_feature-requests.md` con 32 pedidos del batch comercial.

## 2026-06-16 — POS post-fusión: pulido masivo, lock screen y módulos
Commits `556789c..5220d63` (~74). Highlights: fusión POS dentro de panel-next y eliminación de app-next; slices A6/A7 (BFF bootstrap, caja activa, JWT con `rid`); lock screen scoped + IVA real + rework UX del menú principal.

## 2026-06-15 — app-next Slice A3 cobro, correcciones UI y salida de Dropbox
Commits `9781463..77518ee` (31). Highlights: módulos Packs de Servicios y Listas de Precios (migs 31/32); tab Direcciones contactos; Slices A1/A2/A3 del POS rewrite + Dockerfile Coolify.

## 2026-06-14 — /modules, billing tenant+admin, /admin greenfield y dLocal Go
Commits `1edc674..780ec4b` (63). Highlights: marketplace /modules + billing completo + /admin reescrito en panel-next; dLocal Go con webhook anti-doble-acreditación; 12 reportes nuevos; módulo Compras y Gastos slice 1 + view-scope "Todas las sucursales" + MoneyInput/DatePicker convencional + revert Intercepting Routes.

## 2026-06-13 — Sprint mayor panel-next: selector sucursal, /settings, dashboard
Commits `fd5e5b3..580d79a` (15). Highlights: selector de sucursal + /settings modal Alfred + dashboard 2-col legacy; JSONB demote slice II (migs 25/26/27); 10/24 reportes implementados; patrón canónico Front → BFF → API reafirmado.

## 2026-06-12 — Editor de plantillas, refactor theme y refactor taxonomy
Commits `1c2055b..7d52335` (25). Highlights: editor visual de plantillas de impresión (`/settings/print-templates`); refactor theme tweakcn b5eYG4A9A + multi-category; refactor `taxonomy` → tablas dedicadas en 4 slices con triggers PG bidireccionales.

## 2026-06-11 — panel-next CRUDs y auditoría de tokens
Commits `772f12b..df3cf03`. Highlights: build-out CRUDs reales (auth+outlets+contacts+items+settings); plan refactor profundo de Items en `context/13`; auditoría de consumo de tokens (agents Opus→Sonnet, CLAUDE.md reescrito).

## 2026-06-10 — F2 cierre técnico y PIVOTE panel legacy → React
Commits `2f68193..c4978c1`. Highlights: F2 cierre técnico 100% (outlets/settings/bootstrap/contacts/items/vpayments); rebrand visual Encom→Punto; PIVOTE arquitectónico — panel legacy se reescribe greenfield en panel-next con plan en `context/12`; desacople /panel → /api fases 0/1/2 completas (21/23 reportes migrados).

## 2026-06-09 — Deploy a Coolify single-container y onboarding production
Commits `ea7b67f..5ea3a2d`. Highlights: pasaje de 4-services a container único con `router.php` por Host header; auth phone-first system-wide con libphonenumber + migración 12; SSO panel→app con JWT pos-app de 15s + Redis sessions.

## 2026-06-07 — F3.5 impersonación JWT, F3.4 fix y workflow optimization
Commits `fb4a691..456092f`. Highlights: F3.5 "Ingresar como empresa" con JWT panel; F3.4 fix saveCompany balance; code-reviewer solo en commits alto riesgo + context-updater consolidado en /end-session.

## 2026-06-07 — F3.3 delete cascade y F3.2 update company
Commit `5a6e4ab` + `5fe4b39`. Highlights: softDelete/hardDelete de company (~57 DELETEs ordenados); PATCH endpoint con whitelist + JSONB config merge; UI danger zone + form edición 10 campos.

## 2026-06-06 — Bug bashing post-PSR-4, device pairing y JWT_TTL 10 años
Commits `4f28aa3..70dbc22`. Highlights: 5 bugs cazados (PHP 8 hardening + JSONB-demote); device pairing backend completo con tabla `device` + migración 11; "Eliminar Punto de este dispositivo" auto-revoke; JWT_TTL /app a 10 años como modelo device pairing.

## 2026-06-05 — F3.1 Companies + PSR-4 Slices 2-15 mega-sesión
Commits `ceed82d..747384d`. Highlights: F3.1 Admin realm deployable con `CompanyAdminService`; SMTP/NCM creds → env; PSR-4 Slices 2-15 (~7573 callsites migrados acumulados, functions.php 5117 → 2560 líneas); 8 clases en `Punto\App\*` (Json, Output, Validation, Str, Date, Math, Arr, Cond, Taxonomy, Store, Customer, Query, Document, Money, Inventory, GiftCard, Notification).

## 2026-06-04 — PSR-4 Slice 0, CI mínimo y consolidación fetch handlers
Commits `2aa149f..8a7819c`. Highlights: estructura `Punto\App\*` con composer PSR-4 (3172 clases) + plan completo en `docs/PLAN_functions_php_PSR4.md`; CI GitHub Actions 3 jobs paralelos + `.editorconfig`; `app/fetch.php` eliminado y fallback Hashids cerrado (deuda de seguridad).
