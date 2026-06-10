<!-- REGLA: Actualizar cuando cambie el deploy, se agreguen servicios Docker, cambien
     env vars requeridas, o se modifique el pipeline de CI/CD. NO actualizar por cambios de código. -->

# 06 — Infraestructura

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
Redis 7       — sessions + Pub/Sub (managed o container)
```

**Entrypoint de producción:** `docker-entrypoint.sh` (raíz) configura `session.save_handler=redis` parseando `REDIS_URL` y luego lanza `php -S 0.0.0.0:3000 router.php`.

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
| `JWT_SECRET` | Secret para JWT HS256 (compartido entre /app y /panel) | (random 64 chars) |
| `JWT_TTL` | TTL del JWT de /app en segundos — **modelo "device pairing"**. `0` = token eterno sin claim `exp` (recomendado para POS). | `0` (eterno, recomendado) o `315360000` (10 años) |
| `PANEL_JWT_TTL` | TTL del JWT de /panel en segundos — sesión real del tenant. Separado de `JWT_TTL` para evitar que cambiar el TTL del POS afecte el panel. | `86400` (24h) |
| `ADMIN_JWT_TTL` | TTL del JWT de /admin en segundos — sesión real del super-admin | `28800` (8h) |
| `MASTER_COMPANY_ID` | UUID de la company maestra (plataforma). Post-F4 ya no es gate de identidad — su rol es scope de billing/plataforma. | `00000000-0000-0000-0000-000000000001` |
| `CORS_ALLOWED_ORIGINS` | Lista de origins permitidos por CORS, separados por coma. Parametriza el allowlist que antes estaba hardcodeado en `cors.php`. | `https://panel.punto.la,https://app.punto.la,...` |
| `HASHIDS_SALT` | Salt legacy (todavía referenciado) | (random) |
| `APP_ENV` | Entorno | `local` / `production` |
| `APP_DEBUG` | Debug mode | `true` / `false` |

**Nota — modelo "device pairing" de /app (actualizado 2026-06-09):**
El `JWT_TTL=0` (token eterno sin `exp`) es el valor recomendado para producción POS. El JWT de /app NO es una sesión de usuario — es un *device pairing*: el admin activa la caja una sola vez con user+password (cookie `_jwt`) y queda permanentemente asociada a esa empresa/outlet. Los cajeros no tocan ese JWT; entran y salen con un PIN de 4 dígitos (mecanismo separado: `ncmAuth.activeUser` + `lockPad` en el front).

Con TTL corto (ej. 8h), una caja apagada un fin de semana queda inutilizable el lunes hasta que un admin re-loguee — en cadenas con muchos locales esto para ventas. Con `JWT_TTL=0`, el pareamiento es permanente hasta que se revoque explícitamente.

**Revocación per-device**: `UPDATE device SET status=0` (tabla `device`, migración 11) + llamar `jwtInvalidateDeviceCache($did)`. La revocación masiva se logra rotando `JWT_SECRET`.

**PANEL_JWT_TTL** (86400 = 24h): sesión real del tenant. El panel tiene TTL separado del POS.

**ADMIN_JWT_TTL** (28800 = 8h): sesión del super-admin. El /admin tiene su propio JWT.

### APIs externas

| Variable | Servicio |
|----------|----------|
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
cd database/seeds && ./run_seeds.sh
```

Ejecuta en orden: base → panel_user → **03_dev_plan** → catalog → sample_items

| Seed | Archivo | Qué inserta |
|------|---------|-------------|
| 01 | `01_base.sql` (o equivalente) | Company demo, outlet, register, usuarios base |
| 02 | `02_panel_user.sql` | Usuario panel |
| 03 | `postgres/03_dev_plan.sql` | "Local Dev Plan" — `plan_code=1`, todos los límites en 99999. Requerido para que el POS bootee sobre PG dev (company.plan=1 → matchea plans.plan_code=1) |

**Nota**: el seed `03_dev_plan.sql` se agrega en commit 5acea95 junto a la migración 10 (`10_plans_code.sql`). Correr la migración 10 ANTES del seed (el seed depende de la columna `plan_code`).
