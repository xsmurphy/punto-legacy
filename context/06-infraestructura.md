<!-- REGLA: Actualizar cuando cambie el deploy, se agreguen servicios Docker, cambien
     env vars requeridas, o se modifique el pipeline de CI/CD. NO actualizar por cambios de código. -->

# 06 — Infraestructura

## Arquitectura de deploy

```
GitHub (repo: xsmurphy/punto-legacy)
    │
    ▼ push a main
Coolify (PaaS en DigitalOcean Droplet)
    │
    ▼ auto-deploy
Docker Compose (4 servicios)
    ├── PHP (app + panel) — servido por PHP built-in server o nginx
    ├── PostgreSQL 16
    ├── Redis 7
    └── ws-server (Node.js 20)
```

## Docker Compose — Servicios

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
| `JWT_SECRET` | Secret para JWT HS256 | (random 64 chars) |
| `JWT_TTL` | TTL del token en segundos | `28800` (8h) |
| `HASHIDS_SALT` | Salt legacy (todavía referenciado) | (random) |
| `APP_ENV` | Entorno | `local` / `production` |
| `APP_DEBUG` | Debug mode | `true` / `false` |

### APIs externas

| Variable | Servicio |
|----------|----------|
| `TWILIO_SID` + `TWILIO_AUTH_TOKEN` | SMS via Twilio |
| `SENDGRID_API_KEY` | Email via SendGrid |
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
| `PUNTO_API_BASE` | URL base de la API compartida; los BFFs de /app y /panel apuntan acá | `http://localhost:8000` (dev) / `https://api.punto.com` (prod) |

**Dirección futura**: la API compartida (`/api`) se moverá a un server dedicado. En ese momento cambiar `PUNTO_API_BASE` en /app y /panel es suficiente — sin cambios de código. Los BFFs de /app ya usan `PUNTO_API_BASE` desde `app/bff/lib/api_client.php`.

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

## Build pipeline

```bash
./build.sh          # Build completo (app + panel)
./build.sh app      # Solo app
./build.sh panel    # Solo panel
```

**Qué hace**:
1. Concatena archivos JS/CSS según manifesto
2. Minifica con Terser (JS) y CSSO (CSS)
3. Genera nombres con hash SHA-1 para cache-busting
4. Output en directorios de cache (`app/cach/`, panel equivalente)

## Seeds (datos iniciales)

```bash
cd database/seeds && ./run_seeds.sh
```

Ejecuta en orden: base → panel_user → catalog → sample_items
