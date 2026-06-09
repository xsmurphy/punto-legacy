# Deploy a Coolify

Guía para levantar Punto en un servidor Coolify (staging / beta). El target son
los 4 subdominios:

| Subdomain          | Servicio | Build                |
| ------------------ | -------- | -------------------- |
| `panel.punto.la`   | panel    | `panel/Dockerfile`   |
| `admin.punto.la`   | panel*   | `panel/Dockerfile`   |
| `app.punto.la`     | app      | `app/Dockerfile`     |
| `api.punto.la`     | api      | `api/Dockerfile`     |
| `ws.punto.la`      | ws       | `ws-server/Dockerfile` |

\* `admin.*` no es un container aparte — apunta al mismo container del panel y
Traefik prefija `/admin` para todas sus requests (ver `docker-compose.coolify.yml`).

---

## 1 · Pre-requisitos

- **Coolify v4** corriendo en un VPS (DigitalOcean Droplet, Hetzner, etc.) con Docker.
- **Dominio `punto.la`** con DNS apuntando al server. Crear los 5 A records:
  - `panel.punto.la` → IP del server
  - `admin.punto.la` → IP del server
  - `app.punto.la` → IP del server
  - `api.punto.la` → IP del server
  - `ws.punto.la` → IP del server
- **Postgres managed** provisionado en Coolify (recomendado) o un PG accesible
  desde el server. Tomar nota del **host interno** que Coolify expone.

---

## 2 · Configurar el proyecto en Coolify

1. **New Resource → Docker Compose**.
2. **Repository**: `https://github.com/xsmurphy/punto-legacy.git`, branch `main`.
3. **Compose file**: `docker-compose.coolify.yml`.
4. **Auto-deploy on push**: ✅ (recomendado para iterar rápido en staging).

### 2.1 · Variables de entorno

Pegar el contenido de `.env.example` en el panel de Coolify y completar los
valores. Las **críticas** para arrancar:

| Variable | Valor staging | Notas |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `true` | shortcircuita el PIN del signup a `0000`. Cambiar a `false` cuando se configure Evolution API real |
| `POSTGRES_HOST` | host interno de Coolify | NO `localhost` |
| `POSTGRES_DB` | `puntoDB` | |
| `POSTGRES_USER` / `POSTGRES_PASSWORD` | (de Coolify) | |
| `JWT_SECRET` | **rotar** — `openssl rand -hex 32` | |
| `ADMIN_JWT_SECRET` | **rotar** — `openssl rand -hex 32` | distinto del tenant |
| `HASHIDS_SALT` | rotar | |
| `NCM_SECRET` | rotar | |
| `ADMIN_BOOTSTRAP_EMAIL` | tu email | |
| `ADMIN_BOOTSTRAP_PASSWORD` | password fuerte | usado solo la primera vez |
| `PUNTO_API_BASE` | `https://api.punto.la` | el panel/app llaman a este host |
| `WS_URL` | `wss://ws.punto.la` | |
| `PANEL_URL` | `https://panel.punto.la` | |
| `APP_URL` | `https://app.punto.la` | |
| `ADMIN_URL` | `https://admin.punto.la` | |
| `API_URL` | `https://api.punto.la` | |
| `CORS_ALLOWED_ORIGINS` | `https://panel.punto.la,https://app.punto.la,https://admin.punto.la,https://api.punto.la` | comma-separated |
| `REDIS_HOST` | `redis` | usa el container interno |
| `REDIS_PORT` | `6379` | |

**Críticas para mensajería** (si querés WhatsApp/SMS funcional):

| Variable | Servicio |
|---|---|
| `EVOLUTION_API_URL` + `EVOLUTION_API_KEY` + `EVOLUTION_INSTANCE` | WhatsApp (signup PIN, notificaciones) |
| `MAILGUN_TOKEN` o `SENDGRID_API_KEY` | Email |

Sin estas, signup sigue funcionando en debug (`PIN=0000`) pero no manda mensajes reales.

---

## 3 · Bootstrap inicial de la BD (única vez)

### 3.1 · Schema

Desde tu máquina, contra el PG de Coolify:

```bash
# Anotar host:port y password del PG managed
psql "postgresql://punto:PASSWORD@PG_HOST:5432/puntoDB" < db-schema-postgres.sql
```

### 3.2 · Migraciones

Hoy se aplican manualmente (deuda — runner automático pendiente en roadmap).
Aplicar en orden:

```bash
PG_URL="postgresql://punto:PASSWORD@PG_HOST:5432/puntoDB"
for f in database/migrations/postgres/*.sql; do
  echo ">>> $f"
  psql "$PG_URL" < "$f"
done
```

> ⚠️ Algunas migraciones con DDL (`DROP COLUMN`, `ALTER TABLE`) requieren
> usuario **OWNER** de las tablas, no el user de app. Si Coolify provisiona
> con un user "superuser" extra usalo para estas — sino, ejecutarlas directo
> contra el host PG con el user `postgres` superuser.

### 3.3 · Seeds (opcional para staging)

```bash
cd database/seeds && PG_URL="..." ./run_seeds.sh
```

Los seeds insertan: master company, plan de dev, catálogos, sample items.

### 3.4 · Super-admin

El super-admin se siembra automáticamente al primer hit a
`/admin/bootstrap_seed.php` (idempotente) usando `ADMIN_BOOTSTRAP_EMAIL` y
`ADMIN_BOOTSTRAP_PASSWORD` del `.env`. Después del primer login, **cambiá el
password** desde `/admin/users` y limpiá las dos env vars de Coolify.

---

## 4 · Verificación post-deploy

Una vez que Coolify reporta los 4 containers running:

```bash
# 1. Healthchecks Docker (Coolify los muestra en la UI)
# 2. Smoke tests HTTP
curl -fsS https://api.punto.la/v1/bootstrap         # → 401 (auth required) = OK
curl -fsS https://panel.punto.la/login              # → 200
curl -fsS https://admin.punto.la/login              # → 200 (admin login)
curl -fsS https://app.punto.la/login                # → 200
```

Si `panel.punto.la/bff/bootstrap.php` cuelga 15s y devuelve timeout → el panel
no puede llegar al `api` container. Revisar `PUNTO_API_BASE` y que el container
api esté corriendo (no se pueden hacer self-HTTP entre containers a través de
domain externo si el DNS aún no propagó — usar la IP del network interno o
esperar propagación).

---

## 5 · Aplicar migraciones nuevas (después del deploy inicial)

Cada nuevo archivo en `database/migrations/postgres/NN_*.sql`:

```bash
psql "$PG_URL" < database/migrations/postgres/12_new_thing.sql
```

> **TO-DO infra**: agregar runner automático que checkee `schema_migrations`
> y aplique pendientes en el entrypoint del container `panel` o `api`. Por
> ahora es manual.

---

## 6 · Rebuild + redeploy

Coolify rebuildea + redeploya automáticamente en cada push a `main` si está
configurado. Sino, manualmente:

```
Coolify UI → Project → Redeploy
```

El multi-stage de Docker cachea las layers pesadas (extensiones PHP, composer
deps, node_modules) — un rebuild típico tarda 1-2 min sobre el primer build
(~5-8 min).

---

## 7 · Troubleshooting

### `502 Bad Gateway` en panel.punto.la

- Container no arrancó. `docker logs <container>` en el server.
- `php -S` necesita `PHP_CLI_SERVER_WORKERS=8` — verificar la env.

### Signup falla con "No se pudo conectar"

- `APP_DEBUG=false` y `EVOLUTION_API_URL` no configurado. Setear `APP_DEBUG=true`
  o configurar Evolution.

### CORS errors en el browser

- `CORS_ALLOWED_ORIGINS` no incluye el origen real. Verificar exact match
  (incluyendo `https://` y sin trailing slash).

### Admin landing page muestra el dashboard del panel

- El middleware Traefik `admin-strip+admin-add` no aplicó. Verificar que las
  labels del servicio `panel` están bien en `docker-compose.coolify.yml`.

### "Operation timed out" en `bff/bootstrap.php`

- `PUNTO_API_BASE` apunta al mismo host que sirve el BFF — self-HTTP. Hay que
  apuntar al container `api` (vía `https://api.punto.la` o vía network interno
  `http://api`).

---

## 8 · TODO infra

- [ ] Runner automático de migraciones (entrypoint del container o init job)
- [ ] Migrar `php -S` → nginx + php-fpm cuando carga >10 reqs/s concurrentes
- [ ] Healthcheck endpoint dedicado (`/health` con status JSON) en lugar de `/login`
- [ ] Centralizar logs (Loki o similar — Coolify lo facilita)
- [ ] Backup automático del PG (si Coolify no lo provee con el plan)
- [ ] Asset CDN (DigitalOcean Spaces ya está configurado, falta wirearlo)
