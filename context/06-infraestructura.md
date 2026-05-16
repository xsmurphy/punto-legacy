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

## Desarrollo local

**Servidores PHP** (via `.claude/launch.json`):
- App: `php -S localhost:8000 router.php` (cwd: /app)
- Panel: `php -S localhost:8001 router.php` (cwd: /panel)

**Docker**: `docker compose up -d` levanta PG + Redis + pgAdmin + ws-server

**pgAdmin**: http://localhost:5050 (admin@punto.local / admin123)

## Migraciones

**Estado actual**: SQL manuales en `database/migrations/postgres/`

**Naming**: `NN_descripcion.sql` (secuencial)

**Ejecución**: manual contra la BD (`psql` o pgAdmin)

**TO-DO**: Implementar runner automático que corra migraciones en deploy.
Propuesta: script bash que checkee `schema_migrations` table y ejecute pendientes.

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
