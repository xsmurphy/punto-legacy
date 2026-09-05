<!-- REGLA: Actualizar cuando cambie el deploy, se agreguen servicios Docker, cambien
     env vars requeridas, o se modifique el pipeline de CI/CD. NO actualizar por cambios de código. -->

# 06 — Infraestructura

## Cómo identificar los contenedores de Punto en el servidor

En el droplet corren varias apps. **Nombrar el contenedor equivocado ya produjo
dos diagnósticos falsos** (2026-08-21 y 2026-08-22): se concluyó "no hay env
vars de facturación electrónica en prod" y "`APP_ENCRYPTION_KEY` no está
cargada" mirando `api-asqhqb6vb5yerc532ls0vql9`, que **no es Punto** — es otra
app (Node/Prisma). Los nombres llevan hash y cambian en cada deploy, así que no
se memorizan: se identifican.

| Servicio | Cómo encontrarlo | Al 2026-08-23 |
|---|---|---|
| API + front de Punto | El único con PHP: `for c in $(docker ps --format '{{.Names}}'); do docker exec $c sh -c 'command -v php' >/dev/null 2>&1 && echo $c; done` | `z645wx54kwtcciczaeoldwvc-*` |
| Postgres de Punto | `w6rtfxm2n6l45r4r9melj3hl` (NO `postgres-asqhqb*`, es de la otra app) | igual |

Dentro del contenedor de la API el layout **no** espeja el repo: el código vive
en `/var/www/api`, pero el Dockerfile copia `database/` aparte y las
migraciones quedan en `/var/www/database/migrations/postgres`. Una migración
PHP que resuelva rutas contando niveles con `dirname(__DIR__, N)` acierta en el
repo y falla en el contenedor — pasó con `161_repair_missing_roledata.php` y,
como el entrypoint es fail-fast, **tiró el deploy entero** (2026-08-22).

Antes de afirmar "en producción no está X", verificá contra el contenedor
correcto. Es la trampa que más veces mordió en este proyecto.

---

## Arquitectura de deploy (actualizada 2026-06-09 — Coolify single-container)

```
GitHub (repo: xsmurphy/punto-legacy)
    │
    ▼ push a main
Coolify (PaaS en DigitalOcean Droplet)
    │
    ▼ auto-deploy (Dockerfile raíz)
Container PHP único (panel + admin + app + api)
    │  puerto 3000 expuesto a Traefik (Coolify default)
    │  router.php raíz despacha por Host: header
    ├── panel.punto.la → /panel
    ├── admin.punto.la → /panel (path /admin)
    ├── app.punto.la   → /app
    └── api.punto.la   → /api

Container Node.js (ws-server) — recurso separado en Coolify
    │  ws.punto.la → port 6001
    └── Redis (managed o container)

PostgreSQL 16 — base de datos (managed o container)
Redis 7       — rate limiting + Pub/Sub (managed o container)
```

**Entrypoint de producción:** `docker-entrypoint.sh` (raíz) lanza `php -S 0.0.0.0:3000 router.php`. La API es stateless (2026-08-22): no hay sesiones PHP (`session_start()`/`$_SESSION` eliminados de `api/bootstrap.php`), así que el entrypoint ya NO configura `session.save_handler=redis`. `REDIS_URL` la consume `Punto\Api\Cache\RedisClient` (`api/lib/Cache/RedisClient.php`), usado por el rate limiter (`api/lib/RateLimit/RateLimiter.php`) — no se agregó ninguna env var nueva.

**Nota importante:** el puerto expuesto es **3000** (commit 347aa88) — Coolify lo usa como upstream de Traefik por default. Si se cambia, actualizar en la config de Coolify también.

**Deploy local (dev):** sigue igual — 4 servidores PHP independientes en puertos distintos. `router.php` raíz es solo para prod.

## Docker Compose — Servicios (solo para dev local)

| Servicio | Imagen | Puerto | Container |
|----------|--------|--------|-----------|
| postgres | postgres:16-alpine | 5432 | punto_postgres |
| pgadmin | dpage/pgadmin4:latest | 5050 | punto_pgadmin |
| redis | redis:7-alpine | 6379 | punto_redis |
| ws | ./ws-server (build) | 6001 | punto_ws |

**Red**: `punto_network` (bridge)

**Volúmenes persistentes**: `postgres_data`, `pgadmin_data`, `redis_data`

## Variables de entorno

Archivo: `.env` (no commiteado). Template: `.env.example`

### Requeridas

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `POSTGRES_HOST` | Host de BD | `localhost` / `postgres` (Docker) |
| `POSTGRES_PORT` | Puerto BD | `5432` |
| `POSTGRES_DB` | Nombre BD | `puntoDB` |
| `POSTGRES_USER` | Usuario BD | `punto` |
| `POSTGRES_PASSWORD` | Password BD | (secret) |
| `REDIS_HOST` | Host Redis | `127.0.0.1` / `redis` (Docker) |
| `REDIS_PORT` | Puerto Redis | `6379` |
| `JWT_SECRET` | Secret para JWT HS256 (compartido entre /app, /panel y /api — `_jwt_panel`, `_jwt` device, `_jwt_screen`) | (random 64 chars) |
| `JWT_TTL` | TTL del JWT de /app en segundos — **modelo "device pairing"**. `0` = token eterno sin claim `exp` (recomendado para POS). | `0` (eterno, recomendado) o `315360000` (10 años) |
| `PANEL_JWT_TTL` | TTL del JWT de /panel en segundos — sesión real del tenant. Separado de `JWT_TTL` para evitar que cambiar el TTL del POS afecte el panel. | `86400` (24h) |
| `ADMIN_JWT_TTL` | TTL del JWT de /admin en segundos — sesión real del super-admin | `28800` (8h) |
| `COOKIE_DOMAIN` | **Solo se usa para BORRAR cookies** desde 2026-08-27 (context/54 F4): PHP ya no emite ninguna cookie de sesión (`authSetOpaqueCookie` eliminada). `authClearCookie()` lo lee para poder borrar las `_jwt_panel` legacy con el mismo scope con que se emitieron. `_jwt_admin` la emite el BFF de Next, host-only, sin pasar por esta variable. | `.punto.la` |
| `MASTER_COMPANY_ID` | UUID de la company maestra (plataforma). Post-F4 ya no es gate de identidad — su rol es scope de billing/plataforma. | `00000000-0000-0000-0000-000000000001` |
| `CORS_ALLOWED_ORIGINS` | Lista de origins permitidos por CORS, separados por coma. Parametriza el allowlist que antes estaba hardcodeado en `cors.php`. | `https://panel.punto.la,https://app.punto.la,...` |
| `HASHIDS_SALT` | Salt legacy (todavía referenciado) | (random) |
| `APP_ENV` | Entorno | `local` / `production` |
| `APP_DEBUG` | Debug mode | `true` / `false` |
| `SIGNUP_OTP` | Modo de verificación OTP del signup (`api/lib/Auth/SignupOtp.php`). Default `off` si no está seteada: el registro funciona sin validar el código real (rearmado post-limpieza de `2fapin.php`/`phonevalidator.php`, mig 106 `signup_otp`). Setear `on` + `EVOLUTION_API_URL`/`EVOLUTION_INSTANCE`/`EVOLUTION_API_KEY` para activar el envío/validación real por WhatsApp. | `off` / `on` |
| `DB_THROW_ON_ERROR` | Kill-switch del wrapper DB (`api/includes/lib/DB.php`, ver `context/08-convenciones-criticas.md` §54). Default `true`: un error SQL lanza `DbQueryException` (HTTP 500) en vez de devolver `false` silencioso. Solo un valor falsy explícito (`0`/`false`/`off`/`no`) lo apaga y vuelve al `return false` histórico sin redeploy; un typo deja el default seguro. TRANSITORIA — sirve para apagar un incendio en prod si un camino no auditado revienta, NO para vivir apagada. No afecta el guard de `period_closed` (se chequea antes y siempre lanza). | `true` (default) / `false` |

**Nota — modelo "device pairing" de /app (actualizado 2026-06-09):**
El `JWT_TTL=0` (token eterno sin `exp`) es el valor recomendado para producción POS. El JWT de /app NO es una sesión de usuario — es un *device pairing*: el admin activa la caja una sola vez con user+password (cookie `_jwt`) y queda permanentemente asociada a esa empresa/outlet. Los cajeros no tocan ese JWT; entran y salen con un PIN de 4 dígitos (mecanismo separado: `ncmAuth.activeUser` + `lockPad` en el front).

Con TTL corto (ej. 8h), una caja apagada un fin de semana queda inutilizable el lunes hasta que un admin re-loguee — en cadenas con muchos locales esto para ventas. Con `JWT_TTL=0`, el pareamiento es permanente hasta que se revoque explícitamente.

**Revocación per-device**: `UPDATE device SET status=0` (tabla `device`, migración 11) + llamar `jwtInvalidateDeviceCache($did)`. La revocación masiva se logra rotando `JWT_SECRET`.

**PANEL_JWT_TTL** (86400 = 24h): sesión real del tenant. El panel tiene TTL separado del POS.

**ADMIN_JWT_TTL** (28800 = 8h): sesión del super-admin. El /admin tiene su propio JWT.

### APIs externas

| Variable | Servicio |
|----------|----------|
| `EVOLUTION_API_URL` + `EVOLUTION_INSTANCE` + `EVOLUTION_API_KEY` | WhatsApp via Evolution API — único punto de salida: `api/lib/Notify/WhatsAppSender.php`. Consumidores: el OTP del signup (`SIGNUP_OTP=on`) y los avisos de vencimiento del job `plan-lifecycle` |
| `PLAN_LIFECYCLE_NOTIFY` | ¿El job `plan-lifecycle` MANDA los avisos de vencimiento por WhatsApp, o solo loguea a quién avisaría? Vacío/ausente = dry-run (default deliberado: son mensajes a comercios reales). `1`/`on`/`true` = envía. Las ramas de vencimiento, bloqueo y recarga de créditos NO dependen de esta var — corren siempre |
| `TWILIO_SID` + `TWILIO_AUTH_TOKEN` | SMS via Twilio |
| `SENDGRID_API_KEY` | Email via SendGrid (API key) |
| `SENDGRID_SMTP_USER` + `SENDGRID_SMTP_PASS` | Email via SendGrid SMTP (`Notification::sendSMTP`). Definidos en `app/` y `panel/includes/simple.config.php`. (agregado commit e51d5e7, 2026-06-05) |
| `NCM_SMS_API_KEY` + `NCM_SMS_COMPANY_ID` | SMS via NCM (`Notification::sendNCMSMS`). Definidos en `app/` y `panel/includes/simple.config.php`. (agregado commit e51d5e7, 2026-06-05) |
| `MAILGUN_TOKEN` | Email via Mailgun |
| `INFOBIP_AUTH` | SMS/RCS via Infobip |
| `BANCARD_CARD_API_TOKEN` | Pagos tarjeta |
| `BANCARD_QR_API_TOKEN` | Pagos QR |
| `FACTURACION_ELECTRONICA_TOKEN` | SIFEN (EFATech) |
| `DO_SPACES_ACCESS` + `DO_SPACES_SECRET` | File storage |
| `PDF_API_KEY` | Generación de PDFs |
| `NCM_SECRET` | Secret interno |

### dLocal Go — pasarela de pagos SaaS (commit ca6a030, 2026-06-14)

Variables requeridas para el flujo de compra de packs de créditos IA. Definidas en `app/includes/simple.config.php` + `.env.example`.

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `DLOCAL_GO_API_KEY` | API Key pública de dLocal Go | (secret) |
| `DLOCAL_GO_SECRET_KEY` | Secret Key para firma HMAC-SHA256 de checkouts | (secret) |
| `DLOCAL_GO_WEBHOOK_SECRET` | Secret para verificar firma de webhooks entrantes | (secret) |
| `DLOCAL_GO_ENVIRONMENT` | Entorno de dLocal | `sandbox` / `production` |
| `DLOCAL_GO_BASE_URL` | URL base de la API de dLocal Go | `https://api.dlocalgo.com` |
| `DLOCAL_GO_SUCCESS_URL` | URL de retorno tras pago exitoso | `https://panel.punto.la/history-billing?checkout=success` |
| `DLOCAL_GO_BACK_URL` | URL de retorno si el usuario cancela | `https://panel.punto.la/history-billing` |
| `DLOCAL_GO_NOTIFICATION_URL` | URL del webhook para notificaciones de pago | `https://api.punto.la/v1/billing-webhook.php` |

**Setup en Coolify**: agregar todas las vars en el proyecto + configurar `DLOCAL_GO_NOTIFICATION_URL` en el dashboard de dLocal Go apuntando a `https://api.punto.la/v1/billing-webhook.php`.

**Endpoint webhook** (`api/v1/billing-webhook.php`): público, sin JWT. Lee `php://input` ANTES del bootstrap. Verifica firma HMAC-SHA256 (`DlocalGoProvider::verifyWebhookSignature`), devuelve 401 si firma inválida y 200 en otros casos (para evitar loops de reintento del proveedor). La acreditación de créditos es atómica e idempotente — ver `04-modelo-de-dominio.md § billing_invoice` y el índice `uq_ai_credit_ledger_invoice_grant`.

### WebSocket

| Variable | Contexto |
|----------|----------|
| `WS_URL` | URL pública del WS (`ws://localhost:6001` local, `wss://ws.dominio.com` prod) |
| `WS_PORT` | Puerto del ws-server (6001) |
| `REDIS_URL` | URL completa para ioredis (`redis://redis:6379` en Docker) |

### API compartida /api (desde 2026-05-28)

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `PUNTO_API_BASE` | URL base de la API local del panel; los BFFs del **panel** apuntan acá. En single-container apunta a `http://localhost:3000/API` (el propio proceso). | `http://localhost:8000` (dev) / `http://localhost:3000/API` (prod single-container) |
| `PUNTO_SHARED_API_BASE` | URL de la API compartida para los BFFs de **app**. Apunta a `api.punto.la` (subdominio de la API compartida). Separado de `PUNTO_API_BASE` para evitar confusión entre la API local del panel y la API compartida. (commit 78ce497) | `https://api.punto.la` |

**Distinción crítica (commit 78ce497, 2026-06-09):**
- `PUNTO_API_BASE` → panel BFF → API local `/panel/API/v1/` (in-process en single-container)
- `PUNTO_SHARED_API_BASE` → app BFF → API compartida `/api/v1/` (`api.punto.la`)

Antes había una sola variable `PUNTO_API_BASE` que servía para ambos, lo que causaba confusión sobre qué API consumía cada BFF.

## Desarrollo local

**Servidores PHP** (via `.claude/launch.json`):
- App: `php -S localhost:8002 router.php` (cwd: /app)
- Panel: `php -S localhost:8001 router.php` (cwd: /panel)
- **API compartida (nueva, commit d75dd0b)**: `PHP_CLI_SERVER_WORKERS=8 php -S localhost:8000 router.php` (cwd: /api) — "Punto API (compartida)" en `launch.json`

**Docker**: `docker compose up -d` levanta PG + Redis + pgAdmin + ws-server

**pgAdmin**: http://localhost:5050 (admin@punto.local / admin123)

## Migraciones

**Estado actual**: SQL manuales en `database/migrations/postgres/`

**Naming**: `NN_descripcion.sql` (secuencial)

**Ejecución**: manual contra la BD (`psql` o pgAdmin)

**TO-DO**: Implementar runner automático que corra migraciones en deploy.
Propuesta: script bash que checkee `schema_migrations` table y ejecute pendientes.

### Privilegio de owner para DDL (hallazgo 2026-05-25)

`ALTER TABLE DROP COLUMN` (y cualquier DDL que modifique estructura) requiere ser **OWNER** de la tabla. El usuario de app (`POSTGRES_USER=punto`) NO es owner — es el usuario de conexión de la aplicación y solo tiene privilegios DML (INSERT/UPDATE/SELECT/DELETE).

**Regla operativa**: los scripts de migración con DDL (DROP COLUMN, ADD COLUMN, CREATE INDEX CONCURRENTLY, etc.) deben ejecutarse con el usuario superuser/owner de PG. En local: el usuario del OS (Postgres.app corre como el usuario macOS, ej: `xstian`). En producción: el usuario `postgres` superuser o el owner explícito de las tablas.

El backfill UPDATE previo al DROP puede correr perfectamente con el usuario `punto` de la app.

Ejemplo: migración `06_contact_jsonb_demote.sql` (backfill → DROP de 6 columnas) requirió ejecución como usuario owner, no como `punto`.

## Poda del cache de BuildKit (cron en el HOST, 2026-08-25)

Coolify buildea DOS apps (API + frontend) en cada push a `main` y BuildKit no
poda su cache solo: el 2026-08-25, tras ~12 pushes en el día, el cache llegó a
81GB / 642 entradas, el disco a 72%, y cada deploy tardaba casi el doble que
el anterior (de 3-5 min a >20 — BuildKit degrada la gestión del cache a medida
que el store crece). La poda manual liberó 66GB y el disco volvió a 32%.

Fix permanente: `/etc/cron.weekly/docker-builder-prune` en el HOST del server
de producción (167.71.165.221) — NO viaja en el repo porque poda el builder
del host, no algo de la app. `docker builder prune --keep-storage=15GB` (los
layers calientes se conservan: el build post-poda no arranca en frío) +
`docker image prune -f`. Se saltea si hay un `coolify-helper` corriendo.
Si los deploys vuelven a ponerse lentos, mirar `docker system df` ANTES de
sospechar del código.

## Jobs de mantenimiento (cron en la imagen del API, 2026-08-21)

**Hallazgo que originó esto**: tres jobs periódicos existían en código pero
nadie los ejecutaba en producción. Las migraciones 36 y 138 programaban su
propia purga con `pg_cron`, pero `pg_cron` NO está instalado en la imagen
`postgres:18-alpine` de producción — ambas migraciones tienen un fallback
tolerante (`RAISE NOTICE` en vez de fallar el deploy), así que el `NOTICE`
se perdió en los logs de una migración y nadie lo notó. El drainer de FE
(F1) dependía de un cron externo que tampoco se configuró nunca. Resultado
verificado en prod: `report_rollup` con 0 filas y `rollup_dirty` con 134
períodos pendientes.

**Decisión (cerrada, no relitigar)**: el scheduler vive DENTRO de la imagen
del API — Alpine ya trae `crond` de BusyBox. Todo queda versionado en el
repo y viaja con cada deploy, sin configurar nada aparte en Coolify.
`pg_cron` queda descartado como requisito (no está en la imagen managed de
Postgres y no va a estarlo). Redis NO se usa como scheduler.

**Piezas**:
- `api/v1/maintenance.php` — `POST /v1/maintenance?job=<nombre>`, SIN
  `apiAuthTenant` (lo llama el cron, no un operador). Gateado por el mismo
  secreto que el drainer de FE: header `X-Maintenance-Secret`, comparado con
  `hash_equals` contra la env var `EINVOICE_DRAIN_SECRET` (reusada — ver
  comentario en `simple.config.php`), 503 si no está configurada.
- Cada job corre bajo `pg_try_advisory_lock(hashtext('maintenance:'||job))` —
  si no consigue el lock (otro tick del cron todavía adentro, o el día de
  mañana N réplicas del API pegándole al mismo Postgres) responde 200
  `{skipped:true}` en vez de pisar la corrida en curso.
- `api/docker/cron/maintenance.sh` — script `sh` (BusyBox) que hace
  `curl -X POST` a `http://localhost:3000/v1/maintenance?job=...` (mismo
  container, mismo `php -S` que sirve el tráfico externo) y loguea la
  respuesta a stdout. Sale 0 sin pegarle a nada si `EINVOICE_DRAIN_SECRET`
  no está seteada (evita spam de error logs).
- `api/docker/cron/crontab` — instalado en `/etc/crontabs/root` (formato
  BusyBox).
- `docker-entrypoint.sh` — levanta `crond -b -l 8` al boot, SOLO si
  `EINVOICE_DRAIN_SECRET` está seteada (mismo criterio best-effort que el
  seed de admin). `tini` sigue siendo PID 1, así que `crond` no queda
  huérfano.

**Jobs y frecuencia**:

| Job | Frecuencia | Qué hace |
|---|---|---|
| `einvoice-drain` | cada 5 min | delega en `EInvoiceService::drain()` — drena `pending`/`error` vencidos del outbox de FE |
| `rollup-reconcile` | cada 10 min | `SELECT rollup_reconcile(500)` — drena `rollup_dirty`, recompute day→month→year |
| `purge-tenant-audit` | diario 03:00 | `DELETE FROM tenant_audit WHERE createdat < now() - interval '2 months'` (mig 36; columna normalizada a lowercase por mig 150 — NO citar `"createdAt"`) |
| `purge-deleted-row` | diario 04:00 | `DELETE FROM deleted_row WHERE deleted_at < now() - interval '90 days'` (mig 138; `deleted_at` siempre fue lowercase) |
| `partition-ensure` | diario 02:30 | `ensure_month_partitions()` (mig 156, E1 de `context/48`) para `transaction`/`itemsold` — crea particiones mensuales con 12 meses de margen; si a alguna le faltan particiones para los próximos 3 meses (`partition_health()`), alerta a GlitchTip |
| `period-close` | mensual, día 2 05:00 | `SELECT * FROM period_close_due()` (mig 157, D7/E1b de `context/48`) — por cada tenant con un mes vencido de su ventana abierta (`settingPeriodCloseMonths`, default 1), `SELECT period_close_run(companyid, period, NULL, 'job')`: inserta el cierre y re-encola el mes en `rollup_dirty`. Cierre manual (fuera del cron) vía `POST /v1/period-close`, permiso `settings.periodClose` |
| `plan-lifecycle` | diario 06:00 | `PlanLifecycleService::run()` (mig 189, P2 de `context/34` §F7), cross-tenant. 4 ramas: vence (`expiresAt` pasado → `planExpired=true` + `planExpiredAt=now()`), bloquea (5 días de gracia desde `planExpiredAt`, D5), avisa (7/3 días y entrada en gracia, D7) y recarga los créditos IA del plan. **Diario aunque la recarga sea mensual**: vencimiento y gracia son transiciones por día. La gracia arranca en `planExpiredAt`, NO en `expiresAt`: es lo que evita que la primera corrida bloquee de golpe a los tenants vencidos hace meses. **NO es un marcador — MUERDE**: `companyAccessDenial()` (`api/includes/functions.php`) enforcea `blocked` desde `api/bootstrap.php` y `api/lib/Auth/apiAuthPosContext.php`, así que la rama de bloqueo deja al tenant afuera de la API entera 5 días después de ser marcado — y con `PLAN_LIFECYCLE_NOTIFY` apagado no recibió ningún aviso antes. Lo que SÍ falta de la D5 es el modo **solo lectura** de la gracia: no existe (P3, gate de sesión), así que hoy la gracia es acceso completo y después nada. **D8**: ese 403 sale con `error.details.reason='account_blocked'` y el POS lo traduce a ESPERA (`frontend/lib/pos/account-block.ts`) — una venta encolada nunca queda `failed` por falta de pago. Los avisos usan VENTANAS de días restantes con catch-up (d7 = (3,7], d3 = [0,3]) e idempotencia por `company.config->planLifecycleNotices`, así que un día sin corrida no pierde el aviso. Desbloqueo: `update()` con `expiresAt` futuro y `extendTrial()` limpian `blocked`/`planExpired`/`planExpiredAt` juntos — sin ese reset el job re-bloqueaba al día siguiente |

**Cómo verificar que corren**: `docker logs <container-api> | grep maintenance`
— el entrypoint loguea si `crond` arrancó o no, `maintenance.sh` loguea cada
disparo (`[maintenance-cron] job=... ok/FAILED`), y el endpoint loguea cada
corrida (`[maintenance] job=... result=...`).

## CI — GitHub Actions (establecido 2026-06-04, commits 17a2293 + 7ab230a)

**Workflow**: `.github/workflows/ci.yml` — dispara en `push` a main y en `pull_request` a main. Cancel-in-progress activado.

**3 jobs paralelos:**

| Job | Herramienta | Qué valida |
|-----|-------------|-----------|
| `php-lint` | `php -l` (PHP 8.4) | Sintaxis PHP de archivos cambiados vs base branch (PR) o HEAD~ (push). Excluye vendor/, cach/, node_modules/. |
| `js-syntax` | `node --check` (Node 20) | Sintaxis JS de archivos cambiados. Excluye vendor/, cach/, node_modules/, *.min.js. |
| `composer-validate` | `composer validate --strict` | `app/composer.json` y `panel/composer.json`. Requiere `"license"` declarado — ambos tienen `"license": "proprietary"`. |

**Diseño clave — valida solo diff, no el repo entero**: el repo tiene 3 archivos PHP con sintaxis rota (0.8%, deuda histórica en panel/). Si el CI validara todo, bloquearía cada PR. Con la estrategia de diff: bugs nuevos bloquean; bugs viejos no bloquean hasta que se toque el archivo.

**Deuda histórica de sintaxis detectada** (documentada en `docs/CI.md`):
- `panel/a_report_schedule.php:449` — Unclosed `{`
- `panel/a_report_production.php:421` — Unclosed `{`
- `panel/languages/en.php:45` — syntax error, unexpected `,`
- 3/378 archivos PHP (0.8%). Quien toque uno de estos archivos debe arreglarlo antes de commitear.

**Reproducir CI localmente:**

```bash
# PHP lint del diff vs main
git diff --name-only main... | grep '\.php$' | xargs -I{} php -l {}

# JS syntax del diff vs main
git diff --name-only main... | grep '\.js$' | grep -v '\.min\.js$' | xargs -I{} node --check {}

# Composer validate (cada subproyecto)
cd app && composer validate --strict
cd panel && composer validate --strict

# Scripts npm convenientes (raíz)
npm run lint:php   # equivalente al job php-lint
npm run lint:js    # equivalente al job js-syntax
npm run lint       # ambos
```

**Ver runs**: https://github.com/xsmurphy/punto-legacy/actions

---

## Build pipeline

```bash
./build.sh          # Build completo (app + panel)
./build.sh app      # Solo app
./build.sh panel    # Solo panel
```

**Qué hace**:
1. `scripts/vendor-sync.sh` — sincroniza las libs vendoreables desde `node_modules` (fuente de verdad) → `assets/vendor/js/`
2. Concatena archivos JS/CSS según manifesto (lista en `app/filesCompiler.php` / `build.sh`)
3. Minifica con Terser (JS) y CSSO (CSS)
4. Genera nombres con hash SHA-1 para cache-busting
5. Output en directorios de cache (`app/cach/`, panel equivalente)

### Vendoreo de libs JS (modelo híbrido, establecido 2026-05-28)

Las libs front se sirven desde `assets/vendor/js/*.min.js` (las concatena el build). El origen es **híbrido**:

- **Sourceadas desde npm** (19 libs, `npm run vendor` / `scripts/vendor-sync.sh`):
  - Fase A (2026-05-28): jquery, moment, ismobilejs, mousetrap, jquery.actual, lz-string, chart.js, sweetalert2, mustache, leaflet, qrious.
  - Fase B (2026-05-29): bootstrap@3.4.1 (alias `bootstrap3`), bootstrap@4.5.2 (alias `bootstrap4`), eonasdan-bootstrap-datetimepicker@4.17.47, leaflet-routing-machine@3.2.12, libphonenumber-js@1.6.8, offline-js@0.7.19, pouchdb@7.2.1, push.js@1.0.8.
  - `package.json` pinea la versión; el script copia el dist oficial al nombre versionado. Todas verificadas **byte-idénticas** al archivo ya commiteado → migración sin riesgo. Tras `npm update`, correr `npm run vendor` para refrescar. `build.sh` lo corre automáticamente.
  - **Nota pouchdb**: si `npm install` falla en sistemas donde Dropbox strips execute bits (leveldown), usar `npm install --ignore-scripts` — el dist browser no requiere compilación nativa.
- **Manuales permanentes** (dist difiere del npm o no tiene paquete limpio): fastclick, datatables.net, fingerprintjs. Más plugins jQuery custom (chosen, jquery.number, jquery.geolocation, jquery.toast, jquery.fullscreen, simpleStorage, rsvp, jsrsasign, qz-tray, moment-locale-es, select2, snap, chartjs-chart-treemap, chartjs-plugin-annotation y otros). Quedan versionados en el repo; NO los toca vendor-sync.

## Seeds (datos iniciales)

```bash
cd database/seeds/postgres && ./run_seeds.sh
```

Ejecuta en orden: master_admin → sample_company → dev_plan → dev_register_and_items.
El runner MySQL legacy (`database/seeds/run_seeds.sh`, en la raíz de seeds) se eliminó —
era el flujo pre-migración a Postgres, no aplicaba a este schema.

| Seed | Archivo | Qué inserta |
|------|---------|-------------|
| 01 | `01_base.sql` (o equivalente) | Company demo, outlet, register, usuarios base |
| 02 | `02_panel_user.sql` | Usuario panel |
| 03 | `postgres/03_dev_plan.sql` | "Local Dev Plan" — `plan_code=1`, todos los límites en 99999. Requerido para que el POS bootee sobre PG dev (company.plan=1 → matchea plans.plan_code=1) |

**Nota**: el seed `03_dev_plan.sql` se agrega en commit 5acea95 junto a la migración 10 (`10_plans_code.sql`). Correr la migración 10 ANTES del seed (el seed depende de la columna `plan_code`).
