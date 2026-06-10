# Plan maestro — Desacople de /panel al patrón Front → BFF → /api compartida

> **Creado:** 2026-06-09. **Decisiones del usuario:** alcance completo (todo `a_*.php` se parte en
> `.html` estático + BFF + /api), auth multi-realm con allowlist por endpoint, plumbing primero.
> **Modelo de referencia:** el desacople ya completado de `/app` (ver `context/02-arquitectura.md
> § Arquitectura objetivo: BFF de 3 niveles` y `context/10-roadmap.md § Desacople /app`).

## Contexto

- **Producción**: `panel.punto.la` → /panel, `app.punto.la` → /app, `api.punto.la` → /api.
  Panel y App dependen de la API compartida. La API sirve **data cruda** de BD; el BFF de cada
  app la procesa/shapea según lo que su front necesita (el formateo de *display* vive en el
  front — regla canónica §). Esto permite añadir más sistemas que consuman la misma API.
- **Estado de partida** (~40-50% desacoplado): 21 reportes con front estático + BFF, PERO el
  BFF del panel consume `panel/API/v1` **local** (:8001), no la `/api` compartida (:8000).
- **Bloqueo técnico**: `apiAuthTenant()` solo acepta `iss='pos-app'` — un `_jwt_panel`
  (`iss='panel'`) no autentica contra /api. Y los tokens POS son **eternos** (device pairing):
  abrir /api sin scoping dejaría que un token de caja pegue a endpoints administrativos.
- Legacy restante: ~20K líneas en ~30 archivos `a_*.php` (items 5.8K, contacts 3.9K,
  transactions-writes 4K, purchases-writes 2.6K, settings 2.4K, modules 1.6K, …).

---

## Fase 0 — Plumbing multi-realm (P0 security; code-reviewer obligatorio)

### 0.1 `jwtAuthenticate(array $allowedRealms = ['pos-app'])` — `app/includes/jwt_middleware.php`

- El gate de realm vive HOY ahí (`iss !== 'pos-app'` → 401); el cambio va ahí.
- `in_array($payload['iss'] ?? '', $allowedRealms, true)` → 401 si falla. Tokens sin `iss`
  siguen rechazados.
- Device revocation (`did`) ya es condicional — tokens panel sin `did` pasan limpio.
- Nuevo `define('AUTHED_REALM', ...)` junto a las demás constantes.
- Backwards-compat: callers actuales (`app/action.php`, `app/fetchs.php`, `api/bootstrap.php`)
  llaman sin args → default `['pos-app']`, cero cambio de comportamiento.
- NO tocar `_jwtExtractToken()`: el BFF del panel manda el token como `Authorization: Bearer`
  (prioridad 1 del extractor) — evita colisión con la cookie `_jwt` de un POS en el mismo browser.

### 0.2 `apiAuthTenant(array $realms = ['pos-app'])` — `api/bootstrap.php`

- Pasa `$realms` a `jwtAuthenticate($realms)`. Los endpoints existentes de `api/v1/*` no cambian.
- **NO crear `apiAuthPanel()`** — un solo helper con allowlist; cada endpoint declara intención:
  `apiAuthTenant(['panel'])`, `apiAuthTenant(['panel','pos-app'])` para recursos compartidos.
- **`oid` vacío del panel**: si `$outletId === ''`, resolver el outlet principal
  (`SELECT … FROM outlet WHERE companyId = ? AND outletStatus = 1 … LIMIT 1`, bindeado)
  ANTES de `require data.php`. `rid=''` es tolerable (los reportes scopean por parámetros).
- Devolver `realm` en el array de contexto.

### 0.3 Cliente BFF del panel → API compartida — `panel/bff/lib/api_client.php`

- `bffSharedApiBase()`: `PUNTO_SHARED_API_BASE` → `PUNTO_API_BASE` → `http://localhost:8000`
  (misma cascada que `app/bff/lib/api_client.php`). La base actual queda como `bffPanelApiBase()`.
- Core `_bffApiRequest($method, $path, …, $opts)` con `$opts['base'] = 'panel'|'shared'`
  (default `'panel'`). Los BFF migrados pasan `['base'=>'shared']`. Cuando el último migre,
  el default flipea y `bffPanelApiBase()` se borra.
- Hacia la shared API: `Authorization: Bearer <$_COOKIE['_jwt_panel']>` (header, no cookie).
- Portar `bffApiGetMulti` + `bffApiPut/Delete` desde el cliente de /app.
- NO arreglar `bffFailFromApi()` (502 colapsado) acá — deuda P1 transversal, slice aparte.

### 0.4 Verificación Fase 0

`php -l`; curl regresión POS (token POS → 200, token panel → 401 en endpoints POS-only);
smoke /app completo (login → venta); **code-reviewer** (auth/JWT/multi-tenant).

---

## Fase 1 — Piloto end-to-end: `a_banks.php` (211 líneas)

**Por qué banks**: CRUD completo (valida lectura Y escritura), greenfield en la capa API
(valida el camino de las oleadas grandes), su legacy ya es proxy fino a 5 endpoints de
`panel/API/` cuyo único consumer es `a_banks.php` (se borran sin riesgo KDS/CDS/crons),
financiero pero de mínimo blast radius (1 tabla `banks`).

| Capa | Archivo | Notas |
|------|---------|-------|
| Service | `api/lib/Banks/BankService.php` | `Punto\Api\Banks`, `final`, `TenantContext`; `list/get/create/update/updateBalance/delete`; `companyId` SIEMPRE del contexto |
| API | `api/v1/banks.php` | `apiAuthTenant(['panel'])` — primer uso de la allowlist; REST por método; role 7 → 403 en writes |
| BFF | `panel/bff/banks.php` | guard `_jwt_panel`; `['base'=>'shared']`; writes FAIL-CLOSED |
| Front | `panel/views/banks.html` | Alpine.js (los templates Mustache del legacy se reescriben); formateo con `window.currency/decimal/…`; skill brand-manual |
| Router | `panel/router.php` | `/a_banks` → `/views/banks.html` SIN condición `?action=` (migración total) |
| Borrado | `panel/a_banks.php` + `panel/API/{get_banks,get_bank,add_bank,edit_bank,edit_bank_balance,delete_bank}.php` | grep de consumers antes de cada rm |

**Verificación (plantilla para todas las oleadas)**: `php -l`; curl 3 capas (API directa con
Bearer ambos realms — uno 200 / otro 401; BFF con cookie; front por router); shape-diff JSON
nuevo vs legacy ANTES de borrar (cambio esperado: `balance` crudo); smoke browser CRUD completo;
test multi-tenant (`id` de otro company → 404); code-reviewer en el commit del endpoint API.

---

## Fase 2 — Mover `panel/API/v1` (33) + `panel/lib` services (38) a `/api`

**Estrategia: copy + namespace, NO reescritura** (ya son la generación moderna). Por servicio:

- Namespace: `Punto\Api\Reports\ExpensesService` (cae el prefijo `Report`),
  `Punto\Api\Contacts\*`, `Punto\Api\Items\*`, `Punto\Api\Outlets\*`, `Punto\Api\Settings\*`.
  Destino: `api/lib/Reports/<X>Service.php` etc. (el autoloader PSR-4 ya resuelve).
- Endpoint: `panel/API/v1/<x>.php` → `api/v1/<x>.php`; `apiMiddleware()` → `apiAuthTenant(['panel'])`;
  `PANEL_AUTHED_ROLE` → roleId del contexto. Verificar constantes por endpoint
  (`OUTLETS_COUNT` no existe en /api — se calcula o se pasa como parámetro).
- El fallback legacy de `apiMiddleware` (api_key, `$_SESSION`) NO se porta — /api es JWT-only.
- BFF repunta **endpoint-por-endpoint** (flip `['base'=>'shared']` + path); el endpoint viejo
  se borra en el MISMO commit (su único consumer es el BFF).

**Orden** (riesgo ascendente): (1) reportes read-only en batches de 3-5; (2) reportes con
writes de a uno (FAIL-CLOSED + smoke de write); (3) contacts, items, outlets, settings, bootstrap.
(4) **`panel/API/v1/admin/*` NO se mueve**: realm `admin` es plataforma, no tenant — queda en
panel/API/v1 hasta una fase posterior con su propio `apiAuthAdmin()` en /api.

**Done**: `panel/API/v1/` solo contiene `admin/`; el BFF del panel pega 100% (menos admin) a /api;
default del cliente flipeado a `'shared'`.

---

## Fase 3 — Oleadas del legacy (~20K líneas)

**Patrón por módulo** (aplica a todos): inventariar `?action=` del `a_X.php` → servicio(s) en
`api/lib/<Modulo>/` → endpoint(s) granulares `api/v1/` con `apiAuthTenant(['panel'])` (o
`['panel','pos-app']` si /app lo consumirá: items, contacts) → BFF que compone con
`bffApiGetMulti` → `.html` Alpine → router → borrar legacy + endpoints `panel/API/` huérfanos.

**Oleada A — cerrar parciales** (elimina la dualidad `?action=` del router):
giftcards-writes (S), schedule-writes (M), production (M), outlets-resto (M),
purchases-writes (L — financiero, reviewer), transactions-writes (XL — anulaciones/pagos,
de a un action por commit), settings-resto (L).

**Oleada B — huérfanos chicos** (volumen rápido): notifications (S), working_hours (S),
user_comissions (S), orders (S), inventory_count (M), registers (M), history_billing (M),
customers-resto (M), products-resto (M/L — exports CSV viven en BFF, excepción documentada
al envelope). `a_report_usersOld` (390) se borra tras orphan-check, sin migrar.

**Oleada C — CRUDs grandes** (al final, patrón validado N veces): billing (M), bulk_* x3 (M),
purchase (L), modules (L), contacts (XL — `['panel','pos-app']`), items (XL — partir en slices:
base / stock / compounds / upsell, espejando los 6 services de `panel/lib/items`).

**Riesgo transversal**: el legacy confía en `$_SESSION`/constantes de página — CADA query
portada bindea `companyId` del TenantContext, y los writes verifican ownership del row antes
de mutar. Checklist obligatorio por slice.

---

## Fase 4 — Shell `@.php` (al final, recomendado)

NO tocar el shell hasta terminar las oleadas: los módulos cargan como fragmentos en
`#bodyContent` (agnósticos al chrome) y `@.php` es cross-cutting sobre TODAS las páginas
incluidas las legacy vivas. Fase 4 = shell estático + Alpine con datos de `/bff/bootstrap.php`,
eliminación de `$_SESSION` (F-auth-jwt-only fase 2 del roadmap), borrar `a_dashboard.php`
(2194 — shell viejo, confirmar muerto) y `a_reports.php` (85 — nav al front).

---

## Fase 5 — Los 73 endpoints legacy `panel/API/`

**Mantener y congelar** (consumers externos: panel/screens KDS/CDS, crons, posibles terceros
con api_key). Dentro de este plan: (1) al cerrar cada slice, borrar los endpoints legacy cuyo
único consumer era el módulo migrado; (2) inventario de consumers reales → doc en `context/`;
(3) deprecación en `api_head.php` + PROHIBIDO nuevo consumer; (4) migración de KDS/CDS/crons
a /api = plan separado post-Fase 4 (registrar en roadmap).

---

## Riesgos por fase

| Fase | Riesgo | Mitigación |
|---|---|---|
| 0 | Cross-realm: token panel entra a endpoint POS o viceversa | Default `['pos-app']`; negative tests curl ambas direcciones; code-reviewer |
| 0 | `data.php` con `oid` vacío → contexto roto | Fallback outlet principal bindeado por companyId antes de data.php |
| 1-3 | Writes financieros por cañería nueva | FAIL-CLOSED en BFF; shape-diff vs legacy antes de borrar; transactions/purchases de a un action por commit |
| 2-3 | Pérdida de `allowUser()` granular | Patrón rol-claim (role 7 read-only) como en v1; gap = deuda documentada |
| 3 | SQL portado sin scoping de tenant | Bind de companyId del contexto en TODA query; ownership-check en writes |
| 2-3 | `bffFailFromApi` 502 enmascara 422 | Slice independiente (deuda P1) — no bloquea oleadas |
| 5 | Borrar endpoint legacy con consumer oculto | grep + inventario antes de cada rm; KDS/CDS/crons intocables |

## Verificación estándar por slice

1. `php -l` de todo archivo tocado.
2. curl 3 capas: API directa (Bearer, ambos realms — uno 200 / otro 401), BFF (`_jwt_panel`),
   front (HTML por router).
3. Shape-diff JSON nuevo vs legacy ANTES de borrar el legacy.
4. Smoke browser del flujo (read + 1 write si aplica).
5. Multi-tenant: `id` de otro company → 404/403, nunca data.
6. Commit chico + push; code-reviewer si toca auth/writes financieros/SQL portado.
