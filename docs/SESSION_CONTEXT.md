# Punto POS — Contexto de Sesión para Claude

> Leer este archivo al inicio de cada sesión antes de hacer cualquier cambio.
> Complementa `MODERNIZATION.md` (roadmap estratégico) con estado operacional y decisiones técnicas recientes.

---

## Qué es este sistema

**Punto** (antes ENCOM) — SaaS POS/ERP para restaurantes y comercios.
- **Backend:** PHP 8.x sin framework, PostgreSQL 16
- **Frontend app:** HTML estático (`app/index.html`) + API REST
- **Frontend panel:** Bootstrap 3 + jQuery, HTML mezclado con PHP (en proceso de desacople)
- **WebSockets:** ws-server propio (Node.js + Redis) — Pusher eliminado
- **No está en producción todavía** — desarrollo puro, sin cuentas reales

---

## Estructura de directorios

```
system/
├── app/                    # Módulo POS (caja registradora)
│   ├── index.html          # SPA estática ✅
│   ├── API/                # Endpoints REST del módulo app
│   ├── includes/           # db.php, functions.php, jwt_middleware.php, cors.php
│   └── scripts/            # app.js — única fuente del front POS (globalv2.js renombrado + debug.js eliminado — Tier 3, 2026-05-30)
├── panel/                  # Módulo admin (back-office)
│   ├── API/                # 65+ endpoints REST con envelope canónico
│   │   ├── lib/
│   │   │   ├── api_middleware.php   # JWT + api_key + apiMiddlewarePublic()
│   │   │   └── response.php         # apiOk(), apiError(), etc.
│   │   ├── auth.php                 # Login/JWT panel
│   │   ├── kds.php                  # ← NUEVO: API pública KDS (Sprint D)
│   │   └── cds.php                  # ← NUEVO: API pública CDS (Sprint D)
│   ├── includes/
│   │   ├── db.php                   # Bootstrap PDO directo → lib/DB.php
│   │   ├── functions.php            # issueJwtPanel(), loginPart(), getPaymentMethodName()
│   │   ├── cors.php                 # Allowlist centralizada con Allow-Credentials
│   │   ├── jwt.php                  # jwtEncode/jwtDecode HS256
│   │   └── lib/DB.php               # Wrapper PDO emulando ADOdb API
│   └── scripts/
│       └── common.js                # $.ajaxSetup con withCredentials: true
├── screens/                # ← MOVIDO desde panel/screens/ (Sprint D)
│   ├── sa_head.php         # Bootstrap compartido: CORS + rate-limit + DB
│   ├── kds.php             # Solo HTML + manifest — datos → /API/kds
│   ├── cds.php             # Solo HTML + manifest — datos → /API/cds
│   ├── scripts/
│   │   ├── kds.js          # Compilado — llama /API/kds?s=...
│   │   ├── cds.js          # Compilado — llama /API/cds?s=...
│   │   └── ncm-ws.js       # WebSocket client (drop-in de Pusher)
│   └── .htaccess           # RewriteRule para extensiones .php
├── assets/                 # Vendor libs locales (sin CDN)
├── ws-server/              # Node.js WebSocket server
└── database/               # Migrations PostgreSQL
```

---

## Auth: cómo funciona

### `/app` (módulo POS)
- Cookie: `_jwt` (HttpOnly, SameSite=Strict)
- Endpoint login: `app/API/auth.php` → POST email+pass → JWT
- Middleware: `app/includes/jwt_middleware.php` → define `AUTHED_USER_ID`, `AUTHED_COMPANY_ID`, etc.
- Constantes son **string** (UUID), excepto `AUTHED_ROLE_ID` que es int
- Fallback legacy activo: si no hay JWT, cae a `$_POST` params con header `X-Legacy-Auth: 1`

### `/panel` (módulo admin)
- Cookie: `_jwt_panel` (HttpOnly, SameSite=Strict)
- Endpoint login: `panel/API/auth.php` → POST email+pass → JWT
- Middleware: `panel/API/lib/api_middleware.php` → `apiMiddleware()` → define `PANEL_AUTHED_*`
- Helper: `issueJwtPanel($user)` en `panel/includes/functions.php`
- `loginPart($user)` emite JWT + sesión PHP — punto único de login/signup
- Token disponible vía `$GLOBALS['_last_jwt_panel']` para API callers

### `/screens` (pantallas públicas — KDS, CDS, recibos, etc.)
- Sin JWT ni api_key — auth por **slug opaco** (`?s=base64(companyId,outletId)`)
- Middleware: `apiMiddlewarePublic($slug)` en `panel/API/lib/api_middleware.php`
- Rate limiting por slug, sin autenticación de usuario

### JWT Payload
```json
{ "sub": "uuid-user", "cid": "uuid-company", "oid": "uuid-outlet",
  "rid": "uuid-register", "role": 1, "iat": 1234, "exp": 5678 }
```
Todos los IDs son **strings UUID**, `role` es int.

---

## Base de datos

- **PostgreSQL 16** (antes MySQL)
- Wrapper: `panel/includes/lib/DB.php` emula API ADOdb sobre PDO
- Entry point: `panel/includes/db.php` y `app/includes/db.php` (bootstrap directo)
- **ADOdb eliminado** completamente — no usar
- `ncmExecute($sql, $params, $singleRow, $allRows)` — función universal de queries
- `ncmInsert($table, $data)` — auto-genera UUID v7 como PK
- `_flattenJsonb()` — transparenta lectura de columnas JSONB
- IDs son UUIDs — `enc()`/`dec()` son identity functions (ya no Hashids)

---

## Patrones de código

### Endpoint API canónico (panel/API/)
```php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// COMPANY_ID, OUTLET_ID, TODAY ya disponibles
$data = ncmExecute("SELECT ...", [COMPANY_ID]);
apiOk($data);
```

### Endpoint API público (screens sin auth)
```php
require_once __DIR__ . '/lib/api_middleware.php';
$slug = validateHttp('s') ?: ($_GET['s'] ?? '');
apiMiddlewarePublic($slug);
$parts = explode(',', base64_decode($slug));
define('COMPANY_ID', dec(trim($parts[0] ?? '')));
define('OUTLET_ID',  dec(trim($parts[1] ?? '')));
// respuesta sin envelope (compat con JS existente):
header('Content-Type: application/json');
echo json_encode($result); exit;
```

### Envelope canónico
```json
{ "ok": true,  "data": { ... }, "meta": { "ts": 1234, "v": "1" } }
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```
> **Nota:** Endpoints de screens (`kds.php`, `cds.php`) devuelven JSON crudo sin envelope para compatibilidad con el JS compilado existente.

---

## Sprint D — Desacople de /screens (en curso)

### Lo que ya se hizo
| Screen | Estado | Notas |
|--------|--------|-------|
| `panel/screens/` → `screens/` | ✅ | `git mv` preservó historial |
| `sa_head.php` paths | ✅ | `../includes/` → `__DIR__/../panel/includes/` |
| `kds.php` | ✅ | Solo HTML + manifest; datos → `panel/API/kds.php` |
| `cds.php` | ✅ | Solo HTML + manifest; datos → `panel/API/cds.php` |
| `kdss.php` | ✅ eliminado | Clon huérfano sin referencias |
| `kdsDate.php` | ✅ eliminado | Experimento long-polling huérfano |
| `screens/scripts/kds.js` | ✅ | URLs `/kds.php?s=` → `/API/kds?s=` |
| `screens/scripts/cds.js` | ✅ | URLs `/cds.php?s=` y `/kds.php?s=` actualizadas |

### Pendiente
- `receipt.php`, `digitalInvoice.php`, `quoteView.php`, `orderView.php` — más complejos
- `feedback.php`, `giftCardRedeem.php`, `scheduleConfirm.php` — más simples
- **Config de servidor pendiente**: `panel.encom.app/screens/` debe apuntar al nuevo directorio raíz `screens/`. Si el document root de `panel.encom.app` es `panel/`, hay que agregar un alias nginx:
  ```nginx
  location /screens/ { alias /home/encom/public_html/screens/; }
  ```

### Patrón para desacoplar un screen
1. Crear `panel/API/<screen>.php` con `apiMiddlewarePublic($slug)`
2. Mover toda la lógica de datos al endpoint API
3. Dejar en `screens/<screen>.php` solo: sa_head.php + slug decode + HTML + inyección de `window.ese`/`WS_URL`
4. Actualizar JS para llamar `/API/<screen>?s=...` en vez de `/<screen>.php?s=...`

---

## Bugs conocidos / deuda técnica

### Críticos
- `explodes()` en varios screens (`kds.php`, `cds.php`, etc.) — typo, debería ser `explode()`. No rompe porque PHP lo resuelve via `functions.php` que lo define como wrapper, pero es deuda.

### Deuda de arquitectura
- `app/includes/functions.php` y `panel/includes/functions.php` tienen `getPaymentMethodName()` duplicado con divergencia:
  - App: le falta el case `storeCredit` (ya corregido en esta sesión)
  - App: query taxonomy sin filtro `companyId` (ya corregido en esta sesión)
  - La deuda real es que deberían ser una sola función compartida
- Los `a_*.php` del panel mezclan auth + queries + HTML — Phase 3b pendiente
- `app/load.php` (3873 líneas) y `app/action.php` (3604 líneas) son mega-routers — Sprint E pendiente

### Rutas legacy activas (a eliminar eventualmente)
- `app/fetch.php`, `app/load.php` — fallback legacy con `X-Legacy-Auth: 1`
- 2 endpoints panel/API que aún usan `api_head.php` en vez de `apiMiddleware()`
- 4 endpoints públicos panel/API sin middleware: `2fapin.php`, `check_verification.php`, `send_verification.php`, `phonevalidator.php` — necesitan `apiMiddlewarePublic()` o modo público

---

## Variables de entorno requeridas

```ini
POSTGRES_HOST=localhost
POSTGRES_PORT=5432
POSTGRES_USER=punto
POSTGRES_PASS=...
POSTGRES_DB=punto

JWT_SECRET=<random-64-char>
JWT_TTL=28800

HASHIDS_SALT=<random-64-char>
APP_DEBUG=false

WS_URL=wss://ws.encom.app
REDIS_URL=redis://redis:6379
```

---

## Próximos pasos recomendados

En orden de prioridad:

1. **Config de servidor** — alias nginx para `screens/` fuera de `panel/`
2. **Sprint D continuación** — desacoplar `receipt.php` (el más usado por clientes)
3. **Sprint E** — catalogar operaciones de `app/load.php` y `app/action.php`
4. **Phase 3b** — piloto desacople panel: `a_items.php` → HTML + API
5. **4 endpoints públicos** — agregar `apiMiddlewarePublic()` a `2fapin.php`, etc.
6. **Phase AI** — agente con Claude tool use (deps: Phase 2 completa ✅)

---

## Convenciones del proyecto

- **Siempre usar `ncmExecute()`** — nunca `$db->Execute()` directo en código nuevo
- **`enc()`/`dec()` son identity** — solo existen para no romper código viejo
- **JSON columns:** `config->>'settingName'` en PostgreSQL (no `->`)
- **No usar ADOdb** — está eliminado; si aparece código con `$ADODB_*`, eliminarlo
- **Responses API:** `apiOk($data)` para panel/API; `jsonDieResult($data)` solo en screens legacy
- **Paths en includes:** usar siempre `__DIR__ . '/...'`, nunca rutas relativas `'../...'`
