<!-- REGLA: Actualizar cuando se agregue una dependencia nueva, se haga upgrade de versión,
     o cambie una herramienta del toolchain. NO actualizar por cambios de código. -->

# 03 — Stack Tecnológico

## Backend

| Componente | Versión | Notas |
|-----------|---------|-------|
| PHP | 8.x (homebrew: 8.1) | Sin framework. Monolítico. |
| PostgreSQL | 16 (Alpine, Docker) | Motor principal. UUID PKs, JSONB. |
| Redis | 7 (Alpine, Docker) | Cache + Pub/Sub para WebSocket bridge |
| Node.js | 20 (Alpine, Docker) | Solo para ws-server |
| ADOdb | 5.x (vendored) | ORM/abstracción de BD |

## Frontend

| Componente | Versión | Notas |
|-----------|---------|-------|
| Bootstrap | 3.x | CSS framework (legacy) |
| jQuery | 3.x | DOM + AJAX |
| Alpine.js | 3.15.12 (panel) / 3.14.1 (POS /app) | Reactividad declarativa (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`) en el front nuevo. Reemplaza a Mustache en lo nuevo; convive con jQuery. Panel: vendoreado en `assets/vendor/js/`, en `package.json`, cargado en `@.php`. POS /app: vendoreado como `assets/vendor/js/alpinejs-3.14.1.min.js` (local — el POS es offline), cargado en `app/index.html` (defer), en `app/cache-sw.php` (precache) y en `app/filesCompiler.php` (bundle vendor). Componentes Alpine del POS se registran con `Alpine.data()` dentro de `alpine:init` en `globalv2.js` + `debug.js`. Ver §24 en `08-convenciones.md` para el patrón completo. |
| Mustache | 4.0.1 | Templating legacy (/app POS: ~22 templates existentes; panel: items/contacts editform). **Deprecación incremental** — NO crear templates Mustache nuevos. Los existentes migran a Alpine cuando se toquen. |
| ncm-ws.js | custom | Drop-in de Pusher para WebSocket |

## Stack en plan — `panel-next/` (rewrite del panel, desde 2026-06-10)

> Ver `context/12-panel-rewrite.md` para el plan completo. F3/F4/F5 del desacople original están CANCELADOS; el panel legacy se reemplaza greenfield.

| Componente | Versión | Notas |
|-----------|---------|-------|
| Next.js | 15 (App Router) | Framework del nuevo panel. `panel-next/` como directorio raíz del proyecto React. |
| TypeScript | strict | `strict: true`, `noUncheckedIndexedAccess: true`. |
| shadcn/ui | latest | Componentes copy-paste sobre Radix UI. Tema: New York vs Default — pendiente decidir en Sprint 0. |
| Tailwind CSS | 4 | Utilitario, convive con shadcn. |
| TanStack Query | 5 | Server state + cache. Reemplaza fetch manual y polling. |
| react-hook-form | 7 | Formularios con schema Zod. |
| Zod | 3 | Validación de schemas client+server. |

**Coexistencia temporal**: mientras el nuevo panel no cubra el 100% de la funcionalidad, los dos paneles corren en paralelo en subdominios distintos:
- `panel.punto.la` → nuevo panel React (`panel-next/`)
- `panel-legacy.punto.la` → panel PHP actual (`/panel`)

La cookie `_jwt_panel` se emite sobre `.punto.la` (sin subdominio) para que ambos la compartan sin SSO intermedio.

**Lo que NO cambia**: `/api` compartida sigue en PHP — no se reescribe. `/app` (POS) sigue en PHP + Bootstrap 3 + jQuery — decisión separada.

## Build & Tooling

| Herramienta | Versión | Uso |
|-------------|---------|-----|
| Terser | ^5.46.1 | Minificación JS |
| csso-cli | ^4.0.2 | Minificación CSS |
| build.sh | custom | Orquesta build (concat + minify + hash) |
| vendor-sync.sh | custom | Reproduce vendor JS desde npm; `--check` verifica byte-identidad (CI gate) |
| npm | — | devDependencies de build (terser/csso) + vendor libs pineadas exactas |

**Modelo de assets (no hay "miles de refs" en `@.php`):** `@.php` referencia solo ~4 bundles
(`initials.js`, `tdp.js`, `ncm.js`, `at.js`) + Alpine. Cada bundle es una **lista de vendor files**
declarada en `minifyJS([...])` (dev: concatena al vuelo) y en `build.sh` (prod: `terser`/`csso` →
`app/cach/`, servido por `filesCompiler.php`). Los scripts **por módulo** (`scripts/a_report_*.js`,
etc.) cargan **lazy por fragmento** (cada `.html` trae su `<script src>`), NO en `@.php` → el shell
no crece al agregar módulos. Las deps nuevas se gestionan vía `package.json` + se vendorean a
`assets/vendor/js/`. ⚠️ `minifyJS()` legacy minifica vía API externa (javascript-minifier.com) —
preferir `npm run build` (terser local).

**Vendoring vía npm (`vendor-sync.sh`):** 17 libs vendoreadas en `assets/vendor/js/`
se gestionan ahora por `package.json` con versiones **EXACTAS** (sin `^`/`~`): jquery 3.6.3,
chart.js 2.9.4, moment 2.24.0, sweetalert2 7.33.1, mustache 4.0.1, handlebars 4.7.7,
leaflet 1.7.1, lz-string 1.4.4, mousetrap 1.6.3, xlsx 0.16.2, html2canvas 1.3.2,
jsbarcode 3.11.0, qrious 4.0.2, jspdf 2.4.0, ismobilejs 0.4.1, jquery-ui-dist 1.12.1,
jquery.actual 1.0.19. El dist de npm es **byte-idéntico** al archivo vendoreado (verificado
con `cmp -s`), así que el bundle servido no cambia. ⚠️ NO bumpear estas versiones —
el front legacy depende de comportamiento congelado. `select2`/`fastclick` y libs no-npmeables
(bootstrap, daterangepicker, snap, jsrsasign, qz-tray, fingerprintjs, etc.) quedan como archivos
versionados. Alpine queda con `^` (fuera del freeze legacy).

## Dependencias PHP (Composer)

| Paquete | Versión | Dónde | Propósito |
|---------|---------|-------|-----------|
| `giggsey/libphonenumber-for-php` | `^8.13` | `app/composer.json` + `panel/composer.json` | Parseo y normalización de números de teléfono a E.164. Ver §31 en `08-convenciones.md`. |

**Bundle JS de libphonenumber**: `assets/vendor/js/libphonenumber-1.6.8.min.js` (versión 1.6.8 exacta, vendoreada via npm Fase B). La API pública de la 1.6.8 usa `parsePhoneNumber(input, iso)` — distinta a la 1.7+ (`parsePhoneNumberFromString`). No confundir al actualizar.

## Infraestructura

| Servicio | Detalle |
|----------|---------|
| Hosting | DigitalOcean Droplet |
| PaaS | Coolify |
| Containers | **Single container PHP** (panel+app+api) + container Node.js (ws-server). Deploy via Dockerfile raíz, no Docker Compose. Ver `docs/DEPLOY.md`. |
| CI/CD | Coolify auto-deploy desde git (push a main) |
| Build PHP | `install-php-extensions` de mlocati (commit 9bf9c68) — reemplaza compilación manual de extensiones (gd/intl/pdo_pgsql/etc). Mucho más rápido en droplets pequeños. |

## Auth & Seguridad

| Mecanismo | Detalle |
|-----------|---------|
| JWT | HS256, implementación custom en PHP |
| Cookies | `_jwt` (app), `_jwt_panel` (panel), HttpOnly |
| IDs | UUID v7 vía `ncmInsert()` (`generateUuidV7()`); UUID v4 random cuando cae al `DEFAULT gen_random_uuid()` de PG16 (tablas insertadas con `AutoExecute` sin PK explícito — NO ordenables por tiempo) |
| enc()/dec() | Identity passthrough (legacy Hashids eliminado) |
| CORS | Allowlist explícita |

## APIs Externas

| API | Propósito | Costo |
|-----|-----------|-------|
| EFATech / TaxPro | Facturación electrónica (SIFEN) | Absorbe empresa |
| Bancard | Pagos tarjeta + QR | Absorbe empresa |
| Twilio | SMS | Absorbe empresa |
| Infobip | SMS/RCS | Absorbe empresa |
| Resend / Mailgun | Email | Absorbe empresa |
| DigitalOcean Spaces | File storage | Absorbe empresa |
| Anthropic (Claude) | IA / Agente | Créditos al cliente |
| **dLocal Go** | Pasarela de pagos SaaS — cobro de packs de créditos IA a tenants. Checkout tipo REDIRECT. Verificación de webhook por HMAC-SHA256 fail-closed. Provider: `Punto\Api\Billing\Payments\DlocalGoProvider`. (commit ca6a030, 2026-06-14) | Absorbe plataforma (fee por transacción) |

## Archivos de configuración clave

| Archivo | Qué configura |
|---------|--------------|
| `docker-compose.yml` | Stack completo (PG, Redis, pgAdmin, ws) |
| `.env` / `.env.example` | Secrets y config de entorno |
| `package.json` | Scripts de build + devDeps |
| `.claude/launch.json` | Servidores de desarrollo locales |
| `build.sh` | Pipeline de build (JS + CSS) |
