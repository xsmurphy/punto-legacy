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
| ncm-ws.js | custom | Drop-in de Pusher para WebSocket |

## Build & Tooling

| Herramienta | Versión | Uso |
|-------------|---------|-----|
| Terser | ^5.46.1 | Minificación JS |
| csso-cli | ^4.0.2 | Minificación CSS |
| build.sh | custom | Orquesta build (concat + minify + hash) |
| npm | — | Solo para devDependencies de build |

## Infraestructura

| Servicio | Detalle |
|----------|---------|
| Hosting | DigitalOcean Droplet |
| PaaS | Coolify |
| Containers | Docker Compose (4 servicios) |
| CI/CD | Coolify auto-deploy desde git |

## Auth & Seguridad

| Mecanismo | Detalle |
|-----------|---------|
| JWT | HS256, implementación custom en PHP |
| Cookies | `_jwt` (app), `_jwt_panel` (panel), HttpOnly |
| IDs | UUID v7 (gen_random_uuid() + ncmInsert) |
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

## Archivos de configuración clave

| Archivo | Qué configura |
|---------|--------------|
| `docker-compose.yml` | Stack completo (PG, Redis, pgAdmin, ws) |
| `.env` / `.env.example` | Secrets y config de entorno |
| `package.json` | Scripts de build + devDeps |
| `.claude/launch.json` | Servidores de desarrollo locales |
| `build.sh` | Pipeline de build (JS + CSS) |
| `.graphifyignore` | Exclusiones para el grafo de código |
