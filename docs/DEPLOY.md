# Deploy a Coolify

Guía para levantar Punto en un servidor Coolify (staging / beta).

**Arquitectura del deploy**: **un solo container PHP** (`Dockerfile` al root) atiende los 4 subdominios + un container Node para WS. Postgres y Redis son recursos managed de Coolify.

| Subdomain          | Servicio | Container       |
| ------------------ | -------- | --------------- |
| `panel.punto.la`   | panel    | `punto-php`     |
| `admin.punto.la`   | admin*   | `punto-php`     |
| `app.punto.la`     | app      | `punto-php`     |
| `api.punto.la`     | api      | `punto-php`     |
| `ws.punto.la`      | ws       | `punto-ws`      |

\* `admin.*` no es un container aparte — es el mismo `punto-php`. El `router.php` raíz despacha por `Host:` header: si es `admin.*` → carpeta `/panel` con `/admin` path prefix forzado.

Hay también Dockerfiles por módulo (`panel/Dockerfile`, `app/Dockerfile`, `api/Dockerfile`) para deploy split — quedaron en el repo como alternativa si en el futuro se necesita escalar módulos por separado, pero el deploy default es el **container único** del Dockerfile root.

---

## 1 · Pre-requisitos

- **Coolify v4** corriendo en un VPS (DigitalOcean Droplet, Hetzner, etc.) con Docker.
- **Dominio `punto.la`** con DNS apuntando al server. Crear los 5 A records:
  - `panel.punto.la` → IP del server
  - `admin.punto.la` → IP del server
  - `app.punto.la` → IP del server
  - `api.punto.la` → IP del server
  - `ws.punto.la` → IP del server
- **Postgres y Redis** provisionados desde Coolify como recursos managed (`+ New Database → PostgreSQL` y `Redis`). Coolify expone los hosts internos vía env vars; ambos viven en la misma red Docker que las apps.

---

## 2 · Configurar el container PHP en Coolify

### 2.1 · Crear el recurso

1. **Project → + New Resource → Dockerfile**.
2. **Repository**: `https://github.com/xsmurphy/punto-legacy.git`, branch `main`.
3. **Build pack**: Dockerfile (auto-detectado del root del repo).
4. **Dockerfile location**: `/Dockerfile` (root).
5. **Auto-deploy on push**: ✅
6. **Domains** (asignar los 4 al mismo recurso):
   - `https://panel.punto.la`
   - `https://admin.punto.la`
   - `https://app.punto.la`
   - `https://api.punto.la`

Coolify configura Traefik automáticamente: los 4 hosts apuntan al mismo container y el `router.php` raíz despacha internamente.

### 2.2 · Container WS (aparte)

1. **+ New Resource → Dockerfile**.
2. **Dockerfile location**: `ws-server/Dockerfile`.
3. **Domain**: `https://ws.punto.la` (Coolify configura el upgrade WebSocket automático).
4. **Env vars**: `WS_PORT=6001`, `REDIS_URL=redis://<host>:6379` (apuntar al Redis managed).

### 2.3 · Variables de entorno

Pegar en el panel del recurso `punto-php` (las críticas para arrancar):

| Variable | Valor staging | Notas |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `true` | shortcircuita el PIN del signup a `0000`. Cambiar a `false` cuando se configure Evolution API real |
| `DATABASE_URL` | `postgresql://user:pass@host:5432/puntoDB` | Coolify la inyecta automático si linkeás el Postgres managed |
| `REDIS_URL` | `redis://host:6379` | idem — desde Coolify Redis managed |
| `JWT_SECRET` | **rotar** — `openssl rand -hex 32` | |
| `ADMIN_JWT_SECRET` | **rotar** — `openssl rand -hex 32` | distinto del tenant |
| `HASHIDS_SALT` | rotar | |
| `NCM_SECRET` | rotar | |
| `ADMIN_BOOTSTRAP_EMAIL` | tu email | |
| `ADMIN_BOOTSTRAP_PASSWORD` | password fuerte | usado solo la primera vez |
| `PUNTO_API_BASE` | `http://localhost:3000/API` | API local del panel (mismo container) |
| `PUNTO_SHARED_API_BASE` | `http://localhost:3000` | API compartida — loopback IN-container. ⚠️ NO usar `https://api.punto.la`: saldría a Cloudflare y volvería (round-trip + CF como punto de fallo + 2º worker por venta → 502 bajo carga) |
| `PUNTO_SHARED_API_HOST` | `api.punto.la` | Host header para rutear el loopback localhost al `/api` (router despacha por Host). Obligatorio si SHARED_API_BASE es localhost |
| `WS_URL` | `wss://ws.punto.la` | |
| `PANEL_URL` | `https://panel.punto.la` | |
| `APP_URL` | `https://app.punto.la` | |
| `ADMIN_URL` | `https://admin.punto.la` | |
| `API_URL` | `https://api.punto.la` | |
| `CORS_ALLOWED_ORIGINS` | `https://panel.punto.la,https://app.punto.la,https://admin.punto.la,https://api.punto.la` | comma-separated |
| `PHP_CLI_SERVER_WORKERS` | `28` | el self-HTTP entre módulos consume 2 workers por request; 8 se satura bajo carga → 502 |

**Compatibilidad con vars legacy**: si en vez de `DATABASE_URL` / `REDIS_URL` querés usar vars individuales, también funcionan:
- `POSTGRES_HOST`, `POSTGRES_PORT`, `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`
- `REDIS_HOST`, `REDIS_PORT`

Las URLs tienen prioridad si están seteadas.

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
> usuario **OWNER** de las tablas, no el user de app.

### 3.3 · Seeds (opcional para staging)

```bash
cd database/seeds && PG_URL="..." ./run_seeds.sh
```

### 3.4 · Super-admin

El super-admin se siembra automáticamente al primer hit a
`https://admin.punto.la/bootstrap_seed` (idempotente) usando
`ADMIN_BOOTSTRAP_EMAIL` y `ADMIN_BOOTSTRAP_PASSWORD` del `.env`. Después del
primer login, **cambiá el password** y limpiá las dos env vars.

---

## 4 · Verificación post-deploy

```bash
# Smoke tests HTTP
curl -fsS https://api.punto.la/v1/bootstrap         # → 401 (auth required) = OK
curl -fsS https://panel.punto.la/login              # → 200
curl -fsS https://admin.punto.la/login              # → 200 (admin login)
curl -fsS https://app.punto.la/login                # → 200
```

Si alguno devuelve 404 → revisar `router.php` raíz: el match por Host debe
incluir el subdomain exacto.

---

## 5 · Aplicar migraciones nuevas (después del deploy inicial)

```bash
psql "$PG_URL" < database/migrations/postgres/12_new_thing.sql
```

> **TO-DO infra**: agregar runner automático que checkee `schema_migrations`
> y aplique pendientes en el entrypoint del container.

---

## 6 · Rebuild + redeploy

Coolify rebuildea + redeploya automáticamente en cada push a `main`. Sino,
manual: `Coolify UI → Project → Redeploy`.

El multi-stage de Docker cachea las layers pesadas (extensiones PHP, composer
deps, node_modules) — un rebuild típico tarda 1-2 min sobre el primer build
(~5-8 min).

---

## 7 · Troubleshooting

### `502 Bad Gateway` en cualquiera de los 4 dominios

- Container no arrancó. `docker logs <container>` en el server.
- `PHP_CLI_SERVER_WORKERS=8` debe estar en env vars (sin esto: deadlock).

### Subdomain `admin.*` me lleva al dashboard del panel

- El `router.php` raíz no detectó el host. Verificar que `HTTP_HOST` llegue
  correctamente (Traefik default lo preserva). En Coolify, el dominio debe
  estar en el mismo recurso que `panel.*`.

### CORS errors en el browser

- `CORS_ALLOWED_ORIGINS` no incluye el origen real. Verificar exact match
  (incluyendo `https://` y sin trailing slash).

### Signup falla con "No se pudo conectar"

- `APP_DEBUG=false` y `EVOLUTION_API_URL` no configurado. Setear
  `APP_DEBUG=true` o configurar Evolution.

### `php -S` deadlock / saturación en self-HTTP

- BFF panel/app → API self-HTTP necesita workers > 1. `PHP_CLI_SERVER_WORKERS=28`.
- Cada request del BFF que pega al `/api` consume **2 workers** (el del BFF + el del
  `/api`). Con pocos workers + carga (varias cajas, fetchs de arranque), se satura
  y Traefik/CF devuelven **502** ("origin bad gateway").
- El BFF de `/app` debe pegar al `/api` por **loopback in-container**
  (`PUNTO_SHARED_API_BASE=http://localhost:3000` + `PUNTO_SHARED_API_HOST=api.punto.la`),
  NO a `https://api.punto.la` (eso sale a Cloudflare y vuelve → latencia + CF como
  punto de fallo en el path de venta).

---

## 8 · Cuando escalar: split por módulo

Si en el futuro un módulo (típicamente `/app` por carga POS) necesita escalar
solo, los Dockerfiles por módulo siguen en el repo:

- `panel/Dockerfile` → solo panel + admin
- `app/Dockerfile` → solo app POS
- `api/Dockerfile` → solo API

Pasar de "1 container" a "N containers" es solo:
1. Crear N recursos Dockerfile en Coolify apuntando a cada path.
2. Reasignar los dominios a cada recurso.
3. El `router.php` raíz queda en desuso (no se ejecuta porque cada container
   atiende un solo Host).

Ningún código de los módulos cambia.

---

## 9 · TODO infra

- [ ] Runner automático de migraciones (entrypoint del container)
- [ ] Migrar `php -S` → nginx + php-fpm cuando carga >10 reqs/s concurrentes
- [ ] Healthcheck endpoint dedicado (`/health` con status JSON)
- [ ] Centralizar logs (Loki o similar)
- [ ] Backup automático del PG
- [ ] Asset CDN (DigitalOcean Spaces ya está configurado, falta wirearlo)
