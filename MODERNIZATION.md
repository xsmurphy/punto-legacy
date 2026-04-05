# Punto POS — Roadmap de Modernización

Sistema POS legacy en PHP. El objetivo es modernizar progresivamente sin big-bang rewrites, manteniendo el sistema funcional en cada etapa.

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.1, sin framework, archivos monolíticos |
| DB | MySQL 8 vía ADOdb |
| Frontend | Bootstrap 3 + jQuery, HTML mezclado con PHP |
| Auth (app) | `companyId`/`outletId` en localStorage — sin tokens, 100% falsificable |
| Auth (panel) | Sesiones PHP + API key |
| IDs | Integer PKs codificados con Hashids (SALT = PHP_INT_MAX, predecible) |
| API | 89 endpoints en `panel/API/*.php`, respuestas inconsistentes |
| Seguridad | Bypass key abierta, CORS wildcard, `?debug` público |

---

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Fases

```
Phase 0 → Phase 1 → Phase 2 → Phase 4 → Phase 5 → Phase 6
                         ↓
                     Phase 3 (paralelo con Phase 4)
```

---

## Phase 0 — Security Hotfixes ✅
> **Duración estimada:** 1 semana | **Complejidad:** Fácil | **Deps:** Ninguna

Cambios quirúrgicos sin tocar arquitectura. Elimina las vulnerabilidades más críticas.

### Tareas

| # | Qué | Archivo | Estado |
|---|-----|---------|--------|
| 0.1 | Eliminar bypass key `d41d8cd98f00b204e984320998ecf8427e` | `panel/API/api_head.php:142` | ✅ |
| 0.2 | Reemplazar `Access-Control-Allow-Origin: *` con allowlist | `panel/API/api_head.php:2` | ✅ |
| 0.3 | Gatear `?debug` con `APP_DEBUG=true` en `.env` | `panel/includes/config.php`, `app/includes/simple.config.php` | ✅ |
| 0.4 | Mover `SALT` a `.env` como `HASHIDS_SALT` | Ambos `simple.config.php` | ✅ |
| 0.5 | Agregar `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` | `panel/includes/config.php`, `panel/API/api_head.php` | ✅ |

### Done when
- No existe el bypass key en ningún archivo tracked
- CORS usa allowlist en todos los endpoints del panel API
- `?debug` sin `APP_DEBUG=true` en producción no expone stack traces
- `SALT` no es `2147483647` en ningún archivo tracked

---

## Phase 1 — Auth JWT para el módulo `/app`
> **Duración estimada:** 3 semanas | **Complejidad:** Media | **Deps:** Phase 0

Reemplaza el mecanismo de identidad falsificable (base64 JSON en `$_GET['l']`) con JWT firmados emitidos por el servidor.

### Problema concreto

`app/action.php` decodifica un JSON en base64 desde `$_GET['l']` con `companyId`, `userId`, `registerId`, etc. **Es 100% falsificable.** Cualquiera que conozca la codificación Hashids (trivial con SALT público) puede impersonar cualquier usuario en cualquier caja.

`app/fetchs.php` acepta `$_POST['companyId']` y `$_POST['outletId']` sin validar identidad de usuario.

### Tareas

| # | Qué | Archivo |
|---|-----|---------|
| 1.1 | Instalar `firebase/php-jwt` vía Composer | `app/composer/composer.json` |
| 1.2 | Crear endpoint de login que emite JWT | `app/API/auth.php` (nuevo) |
| 1.3 | Crear middleware JWT | `app/includes/jwt_middleware.php` (nuevo) |
| 1.4 | Modificar `action.php`: JWT primero, fallback a legacy | `app/action.php` |
| 1.5 | Modificar `fetchs.php`: validar user-level con JWT | `app/fetchs.php` |
| 1.6 | Endpoint de refresh de token | `app/API/refresh.php` (nuevo) |
| 1.7 | Actualizar JS del POS para usar JWT | `app/index.php` (JS central) |

### Diseño del JWT

```json
{
  "sub": "<userId>",
  "companyId": "<hashid>",
  "outletId": "<hashid>",
  "registerId": "<hashid>",
  "roleId": 1,
  "iat": 1234567890,
  "exp": 1234567890
}
```

- Algoritmo: HS256
- Expiración: 8 horas (duración de un turno)
- Secret: `JWT_SECRET` desde `.env`
- Refresh: antes de 30 min de expirar, el cliente puede llamar `/app/API/refresh.php`

### Estrategia de compatibilidad

- `jwt_middleware.php` busca primero `Authorization: Bearer <token>`, luego `_jwt` en POST.
- Si no hay JWT: cae al mecanismo legacy `$_GET['l']` y agrega header `X-Legacy-Auth: 1` en la respuesta (para monitoreo).
- La remoción del legacy se hace en Phase 2 cuando el 100% de los clientes ya usen JWT.

### Done when
- Login en `/app` emite JWT firmado
- `action.php` y `fetchs.php` validan JWT server-side
- El mecanismo legacy sigue funcionando pero es monitoreado
- Un cliente externo puede autenticarse y hacer requests usando solo el JWT

---

## Phase 2 — Formalizar la capa API del panel
> **Duración estimada:** 4 semanas | **Complejidad:** Media | **Deps:** Phase 1

Normaliza los 89 endpoints del panel sin cambiar funcionalidad. Introduce contrato API estable.

### Tareas

| # | Qué | Archivo |
|---|-----|---------|
| 2.1 | Definir envelope canónico de respuesta | `panel/API/lib/response.php` (nuevo) |
| 2.2 | Crear middleware centralizado | `panel/API/lib/api_middleware.php` (nuevo) |
| 2.3 | Extender JWT al panel API | `panel/API/auth.php`, `api_middleware.php` |
| 2.4 | OpenAPI spec para los 10 endpoints más usados | `panel/API/openapi.yaml` (nuevo) |
| 2.5 | Helpers de validación de request | `panel/API/lib/validate.php` (nuevo) |
| 2.6 | Auditoría de HTTP status codes | `scripts/audit_status_codes.sh` (nuevo) |

### Envelope canónico

```json
// Éxito
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }

// Error
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

### Done when
- Nuevos endpoints usan el envelope
- `api_head.php` es un shim fino sobre el nuevo middleware
- JWT funciona end-to-end en el panel API
- OpenAPI spec existe para 10 endpoints

---

## Phase 3 — Desacople HTML/PHP/JS en el panel
> **Duración estimada:** 6 semanas | **Complejidad:** Media-Difícil | **Deps:** Phase 2

Separa data-fetching (PHP) de presentación (HTML/JS) en los archivos `panel/a_*.php`.

### Patrón objetivo

**Antes**: `a_items.php` = auth + queries SQL + template HTML (todo junto)

**Después**:
- `a_items.php` = solo `include('secure.php')` + `$pageData` mínimo + template HTML
- Data del catálogo → `panel/API/get_items.php` vía AJAX desde el frontend

### Tareas

| # | Qué | Archivo |
|---|-----|---------|
| 3.1 | Piloto: refactorizar `a_items.php` al nuevo patrón | `panel/a_items.php` |
| 3.2 | Extraer componentes de layout reutilizables | `panel/layout/*.php` (nuevo) |
| 3.3 | Introducir pipeline JS con `esbuild` | `panel/package.json` (nuevo) |
| 3.4 | Documentar el patrón | `panel/ARCHITECTURE.md` (nuevo) |
| 3.5 | Migrar 4 páginas más: contacts, dashboard, settings, reports | `panel/a_*.php` |

### Done when
- 5+ páginas del panel siguen el patrón "thin shell + API-fed JS"
- Sistema de layouts existe y es usado
- Pipeline de build JS funciona junto al código jQuery legacy

---

## Phase 4 — Migración a UUIDs
> **Duración estimada:** 8 semanas | **Complejidad:** Difícil | **Deps:** Phase 2

Reemplaza integer PKs + Hashids por UUID v7 (time-ordered, no fragmenta índices).

### Por qué UUID v7

UUID v7 es time-ordered (similar a ULID): funciona bien como clustered PK en MySQL y PostgreSQL sin causar fragmentación de índices. Preferible a v4 para workloads DB-heavy.

### Estrategia dual-write

```
[Fase A] Agregar columna uuid a cada tabla → backfill → dual-write
[Fase B] API devuelve UUIDs, acepta ambos formatos (backward-compat)
[Fase C] Monitorear via X-Legacy-Id header → confirmar cero uso legacy
[Fase D] Remover columnas integer y biblioteca Hashids
```

### Tablas críticas (orden de migración)

1. `company` → `outlet` → `register` (jerarquía raíz)
2. `contact` (usuarios)
3. `item` → `inventory`
4. `sale` → `saleitem`
5. `customer`

### Done when
- Todos los nuevos registros tienen UUID
- API devuelve UUIDs en todos los responses
- Shim `dec()` acepta ambos formatos
- Hashids removido cuando monitoreo confirma cero uso legacy

---

## Phase 5 — MySQL → PostgreSQL
> **Duración estimada:** 12 semanas | **Complejidad:** Difícil | **Deps:** Phase 4

Migra la DB a PostgreSQL 16. *El Docker Compose ya tiene PostgreSQL provisionado.*

### Diferencias de dialecto a resolver

| MySQL | PostgreSQL |
|-------|-----------|
| Backticks `` `tabla` `` | Comillas dobles `"tabla"` |
| `AUTO_INCREMENT` | `SERIAL` / `IDENTITY` |
| `INSERT IGNORE` | `INSERT ... ON CONFLICT DO NOTHING` |
| `ON DUPLICATE KEY UPDATE` | `INSERT ... ON CONFLICT ... DO UPDATE` |
| `UNIX_TIMESTAMP()` | `EXTRACT(EPOCH FROM NOW())` |
| `IFNULL()` | `COALESCE()` |
| GROUP BY permisivo | GROUP BY estricto |

### Estrategia

```
[1] Auditoría SQL (grep de dialectos MySQL-specific)
[2] Wrapper de compatibilidad panel/includes/db_compat.php
[3] pgloader para migración inicial de datos
[4] Período dual-write: MySQL primario, PostgreSQL secundario
[5] Flip reads a PostgreSQL
[6] Flip writes a PostgreSQL
[7] MySQL → cold backup
```

### Done when
- Producción lee y escribe en PostgreSQL
- MySQL es backup frío
- Todos los dialectos SQL resueltos

---

## Phase 6 — Arquitectura moderna (Ongoing)
> **Duración estimada:** Ongoing | **Complejidad:** Difícil | **Deps:** Phases 1-5

Introduce Slim 4 como aplicación paralela. Sin tocar el código legacy. Un endpoint a la vez.

### Estructura objetivo

```
system/
├── app/          # POS legacy (PHP puro)
├── panel/        # Admin legacy (PHP puro)
├── api/          # Nueva app Slim 4 (v2 endpoints)
│   ├── src/
│   │   ├── Controllers/
│   │   ├── Repositories/
│   │   └── Middleware/
│   └── composer.json
└── ...
```

### Estrategia

- Nuevos endpoints viven en `api/` bajo `GET /v2/...`
- Feature flag en `.env`: `USE_V2_ITEMS=true` → el panel JS llama `/v2/items`
- El endpoint legacy queda como stub que delega al nuevo
- Bootstrap 3 → Bootstrap 5 (en paralelo, página por página)

### Done when
- 20+ endpoints migrados a Slim 4
- Panel puede usar endpoints v2 con feature flags
- UI actualizable sin tocar PHP

---

## Variables de entorno por fase

```ini
# Phase 0
APP_DEBUG=false
HASHIDS_SALT=<random-64-char>

# Phase 1
JWT_SECRET=<random-64-char>
JWT_TTL=28800  # 8 horas en segundos

# Phase 6
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```
