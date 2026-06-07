<!-- REGLA: Actualizar cuando se agregue una convención nueva, se modifique una existente,
     o el usuario indique que una regla cambió. Marcar TO-CONFIRM las no validadas aún. -->

# 08 — Convenciones de Colaboración

Versión detallada de las reglas listadas en CLAUDE.md.

---

## §1 — Aislamiento de tenants (REGLA ABSOLUTA)

**Regla**: Todo query que toque datos de tenant DEBE filtrar por `companyId`.

**Por qué**: Un leak entre tenants es un incidente de seguridad crítico. No hay excepción.

**Cómo aplicar**:
- En SELECT: `WHERE companyId = $companyId` siempre presente
- En INSERT: `companyId` es campo obligatorio
- En UPDATE/DELETE: `WHERE companyId = $companyId AND ...`
- En JOINs: verificar que no se crucen datos entre tenants
- En APIs: `COMPANY_ID` viene del JWT, nunca del request body del cliente

**Excepción**: Queries de super-admin SaaS que operan cross-tenant (ej: billing, analytics globales). Estas DEBEN estar claramente marcadas y separadas del código de tenant.

---

## §2 — Commits y flujo de trabajo

**Regla**: Commit + push siempre al terminar una unidad de trabajo.

**Por qué**: El usuario necesita poder probar los cambios sin depender de estado local.

**Cómo aplicar**:
- Un commit por unidad lógica de cambio (no mega-commits)
- Push **inmediato** después de cada commit (ver §13 para el flujo completo con agentes)
- Mensajes de commit en inglés (por consistencia con git log existente)
- Formato: `tipo: descripción corta` (ej: `fix: tenant leak in get_orders`)
- Tipos válidos: `feat`, `fix`, `refactor`, `docs`, `chore`, `test`, `perf`, `style`, `wip` (este último marca commits que pueden saltarse el reviewer, ver §13)

**Flujo con agentes**: ver §13. Resumido: `edit → code-reviewer → commit → context-updater → push` (push inmediato).

---

## §3 — Migraciones idempotentes

**Regla**: Toda migración SQL debe poder ejecutarse múltiples veces sin error.

**Por qué**: Sin runner automático (TO-DO), las migraciones pueden re-ejecutarse accidentalmente.

**Cómo aplicar**:
```sql
-- Crear tabla
CREATE TABLE IF NOT EXISTS ...

-- Agregar columna
DO $$ BEGIN
  ALTER TABLE x ADD COLUMN y TYPE;
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;

-- Crear índice
CREATE INDEX IF NOT EXISTS ...
```

**Naming**: `NN_descripcion.sql` en `database/migrations/postgres/`
- NN = número secuencial (siguiente al último existente)
- Descripción en snake_case, corta y descriptiva

---

## §4 — Git y Claude Code

**Regla**: No usar flags interactivos de git.

**Prohibido**: `git rebase -i`, `git add -i`, `git commit --amend` (sin confirmación)

**Por qué**: Claude Code se cuelga con prompts interactivos de terminal.

**Cómo aplicar**: Usar siempre la forma no-interactiva. Para rebase usar `git rebase <branch>` sin `-i`.

---

## §5 — Dependencias externas

**Regla**: No agregar dependencias de Composer ni npm (runtime) sin aprobación explícita.

**Por qué**: El proyecto minimiza deps externas intencionalmente. JWT, WebSocket, JSONB routing — todo es custom.

**Permitido sin preguntar**:
- devDependencies de npm para build (terser, csso-cli, etc.)
- Scripts de utilidad en Python para tooling

**Requiere aprobación**:
- Cualquier `composer require`
- Cualquier `npm install` que no sea devDependency
- Cualquier SDK/library que se cargue en runtime PHP

---

## §6 — API del panel (envelope canónico)

**Regla**: Todos los endpoints van al envelope canónico, progresivamente. No solo los nuevos.

**Formato éxito**:
```json
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }
```

**Formato error**:
```json
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

**Cómo aplicar**:
```php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// ... lógica ...
apiOk($data);       // éxito
apiError('msg', 422); // error
```

**Endpoints legacy** (`api_head.php`): se migran progresivamente en batches mecánicos
(reemplazar `include('api_head.php')` por `require_once __DIR__ . '/lib/api_middleware.php'; apiMiddleware();`).
Estado actual: 10/93 migrados. Pendientes: 83 (ver `10-roadmap.md` Phase 2.A — prioridad ALTA).

**Cuándo es OK no migrar todavía**: si el endpoint tiene lógica idiosincrática que no
se mapea limpio al envelope. Marcar como "legacy retained" en el batch y seguir.

---

## §7 — UUID v7 y PKs

**Regla**: Toda tabla nueva usa UUID v7 como PK. Nunca auto-increment.

**Cómo aplicar**:
- En schema: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
- En PHP: `ncmInsert()` auto-genera el UUID
- En APIs: los IDs se pasan como strings UUID

---

## §8 — JSONB para campos extensibles

**Regla**: Campos que no necesitan índice ni WHERE van a JSONB. No crear columnas para todo.

**Cuándo columna real**: se necesita filtrar, indexar, o tiene constraint (FK, UNIQUE, NOT NULL crítico)

**Cuándo JSONB**: metadata, configuración, datos que varían por rubro, campos opcionales

---

## §9 — WebSocket y eventos real-time

**Regla**: PHP nunca envía directo al browser. Siempre: PHP → Redis → ws-server → Browser.

**Cómo publicar desde PHP**:
```php
require_once 'includes/ws_publish.php';
wsPublish($channel, $event, $data);
```

**Canales**: `{outletId}-KDS`, `{companyId}-{regId}-register`, `ncm-ePOS`

---

## §10 — Estilo de código PHP

**Regla**:
- **Archivos legacy** (todo lo existente): seguir el estilo del archivo que se modifica.
- **Archivos nuevos**: PSR-12.

**Estilo legacy** (al modificar archivos existentes):
- Variables en camelCase: `$companyId`, `$outletId`
- Funciones en camelCase: `ncmInsert()`, `wsPublish()`
- Sin namespaces
- Includes con `require_once` y paths con `__DIR__`
- Queries SQL inline (no query builder)

**Estilo PSR-12** (archivos nuevos):
- `declare(strict_types=1);` en la primera línea
- Namespaces (ej: `namespace Punto\API;`)
- 4 espacios de indentación, sin tabs
- `{` en la misma línea para funciones/clases
- Una clase por archivo
- Tipos en parámetros y retorno cuando sea posible

**Excepción para naming**: aunque PSR-12 no manda, mantener `camelCase` en variables y
funciones nuevas por consistencia visual con el resto del proyecto (todo el dominio
usa camelCase). Solo las clases en PascalCase como manda PSR.

---

## §11 — Frontend

**Regla actual**: Bootstrap 3 + jQuery + Alpine.js. No agregar otro framework en runtime sin decisión explícita.

**Observaciones**:
- No hay build step para JS del frontend (solo concat + minify)
- Los `a_*.php` del panel mezclan HTML + PHP + JS inline (legado; se migra al modelo BFF)
- Se está migrando a separar: data vía API, presentación en Alpine/template

**Alpine.js (decisión tomada — vigente)**: Alpine 3.14.1 está vendoreado en `/app` (offline POS) y en el panel. Es la dirección para templates nuevos y componentes reactivos en ambos entornos. Ver §17 para el patrón de integración completo.

**Mustache**: legacy en deprecación incremental en `/app`. Los templates existentes se migran a Alpine cuando se toquen. No crear templates Mustache nuevos.

**Frameworks no-bienvenidos**: Vue, React, Svelte, HTMX — no introducir en commits aislados. La decisión de modernizar más allá de Alpine se toma cuando se reemplacen DataTables y Bootstrap 3 (proyecto aparte).

---

## §12 — Seguridad

**Reglas activas**:
- CORS: allowlist explícita (no `*`)
- Headers: X-Content-Type-Options, X-Frame-Options
- Debug: gateado por `APP_DEBUG=true`
- JWT: HttpOnly cookies, no localStorage
- SQL: queries parametrizadas siempre. Concatenación directa = bug de seguridad.

### §12.1 — JWT: claim `iss` obligatorio (establecido commit 2de4231, 2026-05-31)

**Regla**: todo JWT emitido en este proyecto DEBE incluir el claim `iss` (issuer) con uno de los tres valores canónicos. Es la barrera que previene privilege-confusion entre realms cuando dos de ellos comparten `JWT_SECRET`.

**Problema resuelto**: `JWT_SECRET` es compartido entre POS (`_jwt`, validado en `app/includes/jwt_middleware.php`) y panel (`_jwt_panel`, validado en `panel/API/lib/api_middleware.php`). Sin `iss`, un token panel válido podía autenticar contra el POS poniendo la cookie `_jwt` manualmente (y viceversa).

**Valores canónicos de `iss`**:

| Valor | Dónde se emite | Dónde se valida |
|-------|----------------|-----------------|
| `'pos-app'` | `app/API/auth.php`, `app/API/refresh.php`, `app/login.php`, cron service tokens (`cronCreateRecurringInvoice.php`) | `app/includes/jwt_middleware.php` |
| `'panel'` | `panel/includes/functions.php` (login de tenant) | `panel/API/lib/api_middleware.php`, `panel/upload.php` |
| `'admin'` | `panel/API/lib/admin_auth.php` (login de admin) | `panel/API/lib/admin_auth.php` (suma al `aud='admin'` ya existente) |

**Cómo aplicar** (al emitir un nuevo JWT):
```php
// BIEN — incluir siempre el claim iss
$payload = [
    'iss'       => 'pos-app',   // o 'panel' o 'admin' según el realm
    'sub'       => $userId,
    'cid'       => $companyId,
    'exp'       => time() + JWT_TTL,
    // ... otros claims
];
```

**Cómo validar** (ya implementado en cada middleware — NO duplicar lógica):
```php
// En jwt_middleware.php (realm pos-app)
if (($payload['iss'] ?? '') !== 'pos-app') {
    http_response_code(401);
    die(json_encode(['error' => 'Token de otro realm']));
}
```

**Tokens sin `iss`**: son rechazados con 401. Tokens pre-fix (anteriores al commit 2de4231) no tienen `iss` → forzar re-login. Pre-producción, esto es aceptable.

**refresh.php — regla adicional**: valida `iss === 'pos-app'` del token ENTRANTE antes de re-emitir. Un token `iss=panel` enviado a `refresh.php` recibe 401 — cierra el privilege-escalation por refresh.

**Nunca crear un nuevo emisor de JWT** sin agregar el valor de `iss` a esta tabla y al middleware correspondiente.

**Política SQL legacy** (queries con concatenación directa):
- Tratamiento P0: auditoría dedicada + batch de remediación (ver `10-roadmap.md` —
  "SQL Injection Audit")
- Mientras no se complete: cualquier query con concatenación detectada en una sesión
  DEBE parametrizarse antes de mergear, aunque la tarea principal sea otra
- Tooling: `grep -rn "\$db->.*\\.\\.\\$" panel/ app/` para detectar candidatos
- Patrón seguro:
  ```php
  // MAL
  $db->GetAll("SELECT * FROM x WHERE id = '$id'");
  // BIEN
  $db->GetAll("SELECT * FROM x WHERE id = ?", [$id]);
  ```

---

## §14 — Convenciones del middleware API v1 (`panel/API/v1/`)

Reglas que aplican a TODO código nuevo dentro de `panel/API/v1/` y sus services.

### §14.1 — Constante de usuario autenticado

**Usar `PANEL_AUTHED_USER`**, nunca `USER_ID`.

`PANEL_AUTHED_USER` = claim `sub` del JWT (UUID de usuario). En el path de `api_key` legacy vale `0`.
`USER_ID` no está definida en el contexto de `apiMiddleware()` y genera un error silencioso.

```php
// MAL
$userId = USER_ID;

// BIEN
$userId = PANEL_AUTHED_USER; // UUID o "0"
// Si se necesita escribir en FK nullable de usuario:
$userUuid = isValidUuid(PANEL_AUTHED_USER) ? PANEL_AUTHED_USER : null;
```

### §14.2 — `ncmExecute` single-row: nunca usar `is_array()`

`ncmExecute` para un SELECT de una sola fila (sin `forceObj`, sin `getAssoc=true`) devuelve un **objeto `CaseInsensitiveArray`**, no un array PHP. Entonces `is_array($result) === false` aunque haya un resultado, silenciando el valor.

**Patrón correcto** para single-row:
```php
$row = ncmExecute($db, $sql, $params);
$valor = $row ? ($row['columna'] ?? $default) : $default;
// NO: if (is_array($row)) { ... }  ← TRAP: siempre falso
```

`is_array()` **sí** es correcto para resultados de `getAssoc=true` (arrays PHP reales de múltiples filas).

---

## §15 — Patrón heavy-report: computar financials en el Service

**Regla**: En reportes financieros con múltiples vistas (tabs) o fórmulas por fila, las fórmulas exactas (utilidad por fila, agregados) se calculan en el **Service** (motor ERP — fuente única de verdad). El BFF solo suma/deriva KPIs + gráfico. El front solo formatea.

**Por qué**: Si la fórmula se divide entre capas (BFF + Service), cualquier corrección en un lugar deja la otra capa divergida. Un error de un centavo en utilidad = el dueño decide con datos falsos.

**Reglas derivadas**:
- Verificar cada fórmula financiera contra la línea legacy original antes de commitear (P0 si diverge).
- Las fórmulas pueden diferir por vista/modo de filtro — esta asimetría es intencional, no normalizarla.
- Los branches `cusId`/`usrId` típicamente suman sin `*units`; los branches default/`itmId`/`month` suman con `*units`. Preservar.

**Aplica a**: cualquier reporte con fórmula financiera por fila: products ✅, compras, transacciones, y futuros.

---

## §16 — Self-heal write en GET: eliminar, nunca portar

**Regla**: Si el código legacy ejecuta un `UPDATE`/`INSERT`/`DELETE` dentro de un handler GET (patrón "self-heal"), ese write se **elimina** en la migración. Se recomputa el valor para display en el Service/BFF. **Jamás portar un write a una ruta GET en el API v1.**

**Por qué**: Un endpoint GET que escribe en la BD viola el principio de idempotencia, hace imposible el caching, y puede generar side effects invisibles. En PG el `LIMIT` en DELETE es inválido además.

**Ejemplo**: el legacy de `a_report_products` ejecutaba `ncmUpdate` de `itemSoldTax` sin scope de companyId + `DELETE ... LIMIT 1` (inválido en PG) dentro del handler read-only. Correcto: recomputar `itemSoldTax` en el service para display únicamente.

---

## §17 — Migración de fragmentos a Alpine (reemplazo de Mustache)

**Regla**: Al migrar/tocar el front de un módulo, su templating se hace en **Alpine** (no Mustache). "Reusar el HTML" = mantener el resultado VISUAL idéntico (mismas clases BS3/cards/charts), NO la plomería de templating. Migrar Alpine en el momento de tocar el archivo (evita doble trabajo). Charts Chart.js quedan **imperativos** (Alpine no dibuja canvas); Alpine cubre lo declarativo (text/cards/tablas/show-hide).

> **La identidad visual está en el manual de marca** (§21 + `context/11-design-system.md` + skill `brand-manual`). "Mantener el visual idéntico" = reutilizar las clases/colores existentes (BS3 + `app.css`), **no importa el framework**. Para UI net-new usar SIEMPRE las clases del manual — nunca inventar estilos ad-hoc ni rediseñar.

**Receta de init determinista** (crítica — el shell inyecta el fragmento lazy y el `<script>` del módulo carga DESPUÉS de que el MutationObserver de Alpine ya "visitó" el subtree):
1. El markup del fragmento **NO** declara `x-data` (evita la carrera observer-vs-script).
2. El `<script>` define `window.<componente> = function(){...}` y en `$(ready)`: **clonar** `#root` a un nodo fresco (los expandos internos `_x_` no se clonan), `setAttribute('x-data','<componente>()')`, `Alpine.initTree(fresh)` **mientras está DETACHED**, luego `replaceChild` (el observer saltea el nodo ya marcado → init exactamente 1×), y por último `Alpine.$data(fresh).mountUI()`.
3. **`init()` (que Alpine llama solo) NUNCA toca el DOM del documento** (corre detached) — date-picker, tooltips y demás setup que requiere el nodo en el documento van en `mountUI()`.

**Footguns**:
- **`<template x-for>`/`x-if` directo dentro de `<tbody>`**: el parser HTML foster-parentea las filas fuera del `<template>` al inyectar por innerHTML → filas fantasma. Solución: hidratar el `<tbody x-html="filas()">` con un método que arma el string (escapar SIEMPRE el dato de BD con un `esc()` local — XSS).
- **Charts**: dibujar en `$nextTick` después de prender el `x-show` (el canvas necesita estar visible/dimensionado), trackear instancias y `destroy()` antes de redibujar (date re-fetch).
- **Gates de módulo** (`showX`): resetear a `false` al inicio de `loadAll()` y revelar sólo al traer datos — si no, un re-fetch sin datos deja visible el dato viejo.

**Aplica a**: dashboard ✅ (1er fragmento Alpine). Los reportes ya migrados con jQuery+Mustache se reescriben a Alpine cuando se vuelvan a tocar, no de forma preventiva.

### §17.1 — Alpine es más que un reemplazo de Mustache

Alpine no se usa solo para templating; conviene aprovecharlo donde el estado reactivo elimina jQuery propenso a bugs:

- **Formularios CRUD / modales de edición** (items, contacts, settings, editSale): `x-model` (two-way binding) reemplaza el leer/escribir campos a mano. **Mayor ahorro** vs. el patrón actual.
- **POS (`/app`)**: `Alpine.store()` para el carrito (líneas, totales, medio de pago) — estado compartido reactivo; totales/descuentos se recalculan con getters.
- **UI**: `x-show`/`x-if` para gates y secciones condicionales, `@click`/`@submit` en vez de `onClickWrap`, `x-transition` para animaciones.
- **Filtros/búsqueda client-side** en listados (sin re-fetch).

**NO migrar a Alpine (seguir jQuery)**: DataTables (`ncmDataTables`, jQuery-nativo + el bug de `<template>` en `<tbody>`), Chart.js, select2, datetimepicker. Son imperativos; Alpine los **orquesta** (los invoca desde un método), no los reemplaza.

### §17.2 — Frontera de ownership Alpine ↔ jQuery (REGLA)

**Regla**: Alpine y jQuery **nunca** mutan el mismo nodo. Alpine es dueño del **estado reactivo y la visibilidad** (`x-text`/`x-html`/`x-show`/`x-model`/`:attr`); los plugins jQuery (DataTables, select2, Chart.js, datetimepicker) son dueños del **DOM de su widget** y se inicializan imperativamente en `$nextTick`/`mountUI()`, **nunca** sobre nodos que Alpine bindea.

**Por qué**: si ambos tocan el mismo nodo, jQuery muta el DOM y Alpine no se entera (desync), o el próximo render de Alpine pisa lo que hizo jQuery. Ejemplo rozado: `drawSparkline` reescribe el `style` de `#totalIncome` por jQuery — funciona sólo porque ningún `:style` de Alpine bindea ese nodo. Si en el futuro algo bindea ese atributo, se pisan.

**Cómo aplicar**: el canvas/tabla/select que maneja un plugin va en un nodo SIN directivas Alpine (a lo sumo un `x-show` en un wrapper contenedor, no en el nodo del plugin). El método imperativo (drawChart, ncmDataTables, select2Ajax) corre tras el `$nextTick` que sigue a prender el `x-show`.

---

## §18 — TRAP: JSONB partial-update (preservar keys no gestionadas por el form)

**Regla**: Cuando un `update()` en el Service hace un UPDATE parcial sobre una tabla que tiene una columna `data` JSONB con keys que el form NO gestiona (businessHours, customFields, etc.), se debe leer el `data` JSONB raw ANTES de mergear y guardar, preservando esas keys.

**El trap**: `ncmExecute` single-row aplana el resultado via `_flattenJsonb` Y hace `unset($row['data'])` internamente → si intentás leer `$cur['data']` después de un `ncmExecute` sin flags, obtenés `null`/vacío → al hacer `ncmUpdate` con el nuevo `data = {}` + campos del form, se **wipe** el blob completo, destruyendo silenciosamente todas las keys diferidas.

**Fix correcto**:
```php
// MAL — _flattenJsonb hace unset($row['data']), se pierde el blob
$cur = ncmExecute($db, "SELECT * FROM outlet WHERE outletId=?", [$id]);
$existingData = $cur['data']; // siempre null/vacío

// BIEN — forceObj devuelve objeto ADOdb sin aplanar; fields['data'] es el JSON string crudo
$res = ncmExecute($db, "SELECT data FROM outlet WHERE outletId=?", [$id], forceObj: true);
$existingData = json_decode($res->fields['data'] ?? '{}', true) ?: [];
// merge: $newData = array_merge($existingData, $formFields)
// luego ncmUpdate con $newData
```

**Aplica a**: cualquier módulo que haga un UPDATE parcial sobre una tabla con `data` JSONB que contiene keys no enviadas por el form actual (outlets ✅ — pilot; aplica también a items, contacts, company config, y cualquier entidad futura con `data`/`meta` JSONB extensible).

**Detectado en**: `a_outlets` update (commit 99d1286). P0 identificado por code-reviewer, verificado E2E: key `outletBusinessHours` + custom key semilladas sobreviven un save del form que no las envía.

---

## §19 — Router pattern para módulos CRUD no-reporte (`$bffPartialModules`)

**Regla**: Los módulos CRUD del panel que se migran parcialmente al BFF (lecturas + update, pero writes pesados/legacy aún en PHP) usan el mapa `$bffPartialModules` en `panel/router.php`. Los fronts estáticos de estos módulos viven en `panel/views/` (no en `panel/reports/`).

**Comportamiento del router**:
- `empty($_GET['action'])` → sirve el `.html` estático desde `panel/views/`
- `?action=algo` presente → cae al PHP legacy `a_<modulo>.php`

**Distinción con reportes**:
- `$bffStaticReports` → reportes completamente migrados, sin legacy PHP
- `$bffPartialReports` → reportes parcialmente migrados (algunas acciones legacy)
- `$bffPartialModules` → módulos CRUD no-reporte (outlets ✅ — pilot; pendientes: contacts, items, registers, settings, …)

**Directorio de fronts**: `panel/views/<modulo>.html` (módulos CRUD) vs. `panel/reports/<modulo>.html` (reportes). Ambos son `.html` estáticos, cero PHP, per la REGLA RAÍZ de `02-arquitectura.md`.

---

## §20 — Port verbatim de widget jQuery pesado

**Regla**: Para widgets jQuery grandes y autónomos (canvas drag/drop, builders, etc.) que sería arriesgado transcribir manualmente, el widget se extrae VERBATIM al motor de la sesión (ej. `sed -n '1610,2428p'`), se mueve a su propio archivo `scripts/<modulo>_<widget>.js`, y se recablean SOLO sus llamadas de datos al BFF. La lógica interna del widget NO se toca.

**Por qué**: La transcripción manual de 800+ líneas de widget jQuery introduce errores de transcripción difíciles de detectar. La extracción verbatim elimina ese riesgo y hace que el diff sea mínimo y auditable.

**Cómo aplicar**:
1. Extraer el bloque del widget del `.php` legacy con `sed -n '<línea-inicio>,<línea-fin>p'` → `scripts/<modulo>_<widget>.js`.
2. En el nuevo archivo, identificar y recablear SOLO las llamadas de datos que iban al backend legacy (cambiar URLs o `action=` por endpoints del BFF).
3. Los datos server-rendered del legacy (ej. paletas, listas de opciones) se hidratan client-side desde un endpoint dedicado de la API (ej. `?view=templateFields`) — el front llama ese endpoint y pasa los datos al widget.
4. El widget jQuery sigue siendo dueño de su DOM (§17.2 aplica: Alpine y jQuery nunca mutan el mismo nodo).
5. La inicialización del widget se dispara en `shown.bs.tab` (o evento equivalente) del tab que lo contiene — no en `$(ready)`, porque el DOM del tab puede no estar visible aún.

**Detectar recableado necesario**: buscar en el widget extraído cualquier llamada a `?action=`, `$.ajax/$.post/$.get` con rutas PHP legacy, o variables globales PHP interpoladas (`<?= $var ?>`). Cada una debe resolverse a un endpoint BFF o a un valor del array de hidratación.

**Primer uso**: `a_settings_templates.js` (templateBuilder de Plantillas de Impresión, ~820 líneas), portado verbatim de `a_settings.php:1610-2428`. 3 llamadas de datos recableadas al BFF (`?view=templates`, `?view=templateFields`, BFF POST `saveTemplate`/`removeTemplate`). Paleta hidratada client-side desde `templateFields` en `a_settings.js:hydratePalette()`. Init en `shown.bs.tab '#printTemplates'`.

**Aplica a**: cualquier widget jQuery de canvas / drag-drop / builder embebido en un `.php` legacy que supere ~200 líneas y tenga lógica de interacción visual no trivial.

---

## §13 — Flujo commit + push con agentes (REGLA OBLIGATORIA)

**Regla**: Toda corrección o mejora pasa por el flujo `edit → (code-reviewer si alto riesgo) → commit → push`. El context-updater se corre al CIERRE via `/end-session`, no por commit. El push es inmediato, no se acumulan commits locales.

**Por qué**: el code-reviewer tiene mayor ROI en commits de alto riesgo (auth/BD/dinero). Correrlo en cada commit trivial es overhead sin beneficio. El context-updater al cierre consolida todos los cambios de la sesión en una sola pasada, reduciendo ruido y actualizaciones parciales.

**Diagrama**:

```
edit/escribir código
   ↓
Agent(subagent_type="code-reviewer")     ← ANTES de commit, SOLO si es alto riesgo (ver §30.1)
   ↓ (si P0/P1 OK, o si se omitió por ser trivial)
git commit
   ↓
git push                                  ← INMEDIATAMENTE después del commit
   ↓
(al cerrar la sesión)
/end-session  →  context-updater          ← UNA sola vez al cierre
   ↓
(opcional) gh pr create                   ← si es PR-worthy
```

**Reglas no negociables**:

1. **NO acumular commits sin pushear.** Cada commit lógico se pushea inmediatamente.
2. **code-reviewer solo en alto riesgo.** Ver §30.1 para la lista de categorías. Commits triviales o `wip:` lo omiten explícitamente.
3. **context-updater al cierre, no por commit.** Correrlo vía `/end-session`. Ver §30.3.
4. **Excepción única**: commits de WIP marcados explícitamente (`wip:` prefix). Pueden saltarse el reviewer pero NO el push.

**Refuerzo automático**: `.claude/settings.json` tiene un hook `PreToolUse:Bash` que detecta `git commit` y `git push` (regex anclada al inicio del comando, no matchea greps/edits) y emite un recordatorio. Si aparece el recordatorio y no corriste el agente, parar y correrlo antes de seguir.

**Sobre `code-reviewer`**: acepta 3 modos de diff según contexto — working tree (`git diff`), staged (`git diff --cached`), o post-commit (`git diff HEAD~1`). Por defecto revisa lo que esté pendiente; en post-commit (este flujo lo invoca después de `git commit`) usa `HEAD~1`.

**Fuente canónica corta**: REGLA OBLIGATORIA #3 de `CLAUDE.md`. Este §13 es la versión detallada.

---

## §23 — Home canónico para nuevos endpoints de desacople (establecido 2026-05-28, commit d75dd0b)

**Regla**: Todo endpoint nuevo del desacople (de /app o de /panel) va en `/api/v1/<x>.php` y su service en `/api/lib/services/<x>Service.php`. **NUNCA en `/app/API/v1/`** (quedó vacío de slices tras d75dd0b) ni directo en `panel/API/` (migra gradualmente).

**El BFF cliente** (en `/app/bff/<x>.php` o `/panel/bff/<x>.php`) apunta a la API compartida vía `PUNTO_API_BASE`. Reenviar la cookie `_jwt` al hacer la llamada (ver `app/bff/lib/api_client.php`).

**Auth en /api**: usar `apiAuthTenant()` de `api/bootstrap.php` — valida JWT de tenant (cookie `_jwt` | Bearer | POST, `JWT_SECRET`, claim `cid`). No usar `apiMiddleware()` del panel (es para `_jwt_panel`).

**Por qué**: /api es el backend único del sistema y se moverá a un server dedicado. Poner endpoints en /app o /panel los acopla al módulo cliente, impidiendo el split de servers.

---

## §22 — Slices de desacople /app (POS): gotchas obligatorios (establecido 2026-05-28)

Al migrar cualquier concern de `app/action.php`/`app/load.php` al patrón Front→BFF→API→Service, aplicar SIEMPRE estas reglas derivadas de slice 1 (`customerAddress`):

### §22.1 — `ncmInsert`/`ncmUpdate` PROHIBIDOS en /app

`app/includes/lib/DB.php` no tiene `Insert_ID()` → `ncmInsert`/`ncmUpdate` son PHP fatal errors en /app. **Para escrituras**:

```php
// MAL — fatal en /app (ncmInsert llama $db->Insert_ID() que no existe)
ncmInsert($db, 'customer_address', $data);

// BIEN — escritura directa parametrizada
$db->Execute("INSERT INTO customer_address (...) VALUES (?,?,?,?)", [...]);

// BIEN — multi-step atómico
$db->StartTrans();
$db->Execute($sql1, $p1);
$db->Execute($sql2, $p2);
$db->CompleteTrans();
```

`ncmExecute` (lecturas) sigue OK en /app.

### §22.2 — El payload `?l=` se decodifica en el BFF, no en el front

El front de /app ya construye y envía el `?l=` base64 (con acción + metadata) sin cambios. El BFF es el responsable de decodificar ese payload y extraer la acción + los IDs de contexto (companyId, outletId, etc.). El payload llega idéntico al BFF; el BFF lo decodifica y rutea.

```php
// En el BFF de /app:
$payload = json_decode(base64_decode($_GET['l'] ?? ''), true);
$action  = $payload['action'] ?? '';
// ... rutear a la API
```

### §22.2b — El front de /app es `app/scripts/app.js` (única fuente — debug.js ELIMINADO)

> **Resuelta (commit e97aed7, 2026-05-30) — §22.2b ya NO aplica en su forma original.**

**Historia** (para entender referencias antiguas): hasta el commit e97aed7, el front de /app
tenía DOS archivos: `globalv2.js` (producción) y `debug.js` (copia byte-idéntica para modo
debug/mobile). La convención obligaba a editar ambos en sync a mano — dolor de mantenimiento
documentado aquí como "§22.2b". Los slices 1-13 accidentalmente sólo editaron `debug.js`,
dejando producción en legacy hasta el backfill del commit `5f1b367`.

**Estado actual (única fuente)**: `globalv2.js` fue renombrado a **`app/scripts/app.js`**
(nombre con sentido, sin sufijo de versión) y `debug.js` fue **eliminado** (era un duplicado
byte-idéntico). `app/includes/assets.php` ya no tiene el selector debug/mobile/normal —
sirve siempre `/scripts/app.js`. **La convención "editar globalv2.js Y debug.js de forma
idéntica" ya no existe: sólo hay `app.js`.**

**Regla vigente**: al repuntar cualquier call-site del front de /app de `/action?l=`
(o `/load?l=`) a `/bff/<concern>?l=`, editar **`app/scripts/app.js`** — es el único archivo.
Verificar con `node --check app/scripts/app.js` tras editar.

**Referencias cruzadas que mencionan `globalv2.js` o `debug.js`**: son históricas (commits
anteriores a e97aed7). `app.js` es el sucesor de ambos. APP_VERSION → 2.0.9.6 al momento
de la unificación (invalida el SW cache).

### §22.3 — Fixes PG obligatorios en cada slice /app

El legacy de /app tenía bugs latentes generalizados que DEBEN corregirse al migrar cada concern:

| Bug legacy | Fix correcto |
|-----------|-------------|
| UUID interpolado sin comillas en WHERE (`WHERE id = $uuid`) | Bound param: `WHERE id = ?`, bind `[$uuid]` |
| `DELETE ... LIMIT 1` | `DELETE ... WHERE id = ? AND companyId = ?` (sin LIMIT, con scope) |
| Booleano comparado con `1` (`WHERE flag = 1`) | `WHERE flag = true` |
| Booleano seteado con `1` (INSERT/UPDATE `flag = 1`) | `flag = true` (o `null` para default) |
| Escritura sin scope de `companyId` | Agregar `AND companyId = ?` en UPDATE/DELETE siempre |
| Identificadores SQL **entre comillas dobles** (`"transactionId"`, `"printServer"`) | **SIN comillas** (`transactionId`, `printServer`). Ver §22.5 |
| Escribir `transactionDetails`/`tags` como columna | Esas columnas viven en `meta` (jsonb) post-migración. Ver §22.6 |

### §22.5 — Identificadores SQL: SIEMPRE sin comillas (las columnas reales son lowercase)

**REGLA**: Nunca poner identificadores (tablas/columnas) entre comillas dobles en SQL.
Las migraciones PG definieron las columnas en camelCase **sin comillas**, y PostgreSQL
**pliega a lowercase** todo identificador sin comillas. Las columnas reales en la DB son
`transactionid`, `companyid`, `transactiondetails`, etc. (todo minúscula).

- ✅ `SELECT customerId, invoiceNo FROM transaction WHERE transactionId = ?` — el camelCase
  se pliega a lowercase y matchea la columna real. Legible y correcto.
- ❌ `SELECT "customerId" FROM transaction WHERE "transactionId" = ?` — busca una columna
  literal `customerId` (mixed-case) que **NO existe** → error en runtime.

**Por qué importa**: los slices 6/7 se commitearon con identificadores entre comillas y
fallaban en runtime (commit fix `3b81914`). El lint PHP NO lo detecta; sólo se ve al correr
contra la DB. Verificar cada service con un UUID falso antes de commitear:
`php -r 'require "includes/db.php"; ...$svc->metodo($fakeId,$fakeCo);'` (cwd `/app`).

### §22.6 — `transactionDetails`/`tags` viven en `meta` (jsonb), no como columna

La migración PG movió `transactionDetails`, `tags`, `transactionLocation` y otros campos
extensibles de `transaction` dentro de la columna **`meta` (jsonb)**. En **lectura**,
`_flattenJsonb` (en `ncmExecute`) mergea las keys de `meta` en la fila → `$row['transactionDetails']`
funciona, **pero el SELECT debe pedir `meta`** (no `transactionDetails`). En **escritura**,
`AutoExecute`/`ncmUpdate` **NO mapean** columnas virtuales → `meta`: hay que usar
`jsonb_set(COALESCE(meta,'{}'::jsonb), '{transactionDetails}', ?::jsonb)`.

**Consecuencia**: todos los handlers legacy que escriben `transactionDetails` están **rotos
post-migración** (setUserToOrder, removeItemfromOrder, processOrderItems*, moveOrderItems,
updateSchedule, scheduleSession, processData). Se difieren a un **slice dedicado de meta-JSONB**
que necesita: (1) entender el formato de storage (string vs objeto anidado), (2) datos de prueba
type-12, (3) patrón `jsonb_set` para el write. No portar estos handlers "como están".

### §22.8 — Columnas demoted a JSONB: SELECT * + _flattenJsonb + filtro PHP (establecido 2026-05-30, commit b45684f)

**Contexto**: las migraciones PG 06/07 movieron varios campos de `contact`, `item` y otros
a la columna `data` JSONB de la entidad correspondiente (ej.: `contactFixedComission`,
`itemComissionPercent`, `itemComissionType`, `itemSessions`). Esas columnas **ya no existen**
en el schema relacional.

**El bug**: si una query hace `SELECT contactFixedComission ... WHERE contactFixedComission > 0`
(o cualquier referencia directa a la columna demoted), PostgreSQL devuelve
**"column X does not exist"** y **aborta la transacción activa** (SQLSTATE 25P02).
Todos los INSERTs/UPDATEs posteriores en esa misma transacción fallan con
"current transaction is aborted" — pero si el handler final llama `jsonDieMsg('true',200,'success')`
sin verificar el estado de la tx, devuelve un **falso positivo**: la UI ve "success" y los datos
**no se guardaron**.

**Patrón de fix** (para queries SELECT sobre entidades con campos demoted):

```php
// MAL — "column contactFixedComission does not exist" → aborta la tx
$rows = ncmExecute($db, "SELECT contactFixedComission FROM contact
                          WHERE companyId=? AND contactFixedComission > 0", [$cid]);

// BIEN — SELECT * deja que _flattenJsonb re-exponga las keys del JSONB como columnas virtuales;
//         filtrar en PHP, no en WHERE con el campo demoted
$rows = ncmExecute($db, "SELECT * FROM contact WHERE companyId=?", [$cid], getAssoc: true);
$rows = array_filter($rows, fn($r) => ($r['contactFixedComission'] ?? 0) > 0);
```

**Sub-regla §22.8a — leer columnas demoted SIEMPRE con `ncmExecute`, NUNCA con `$db->Execute` crudo** (reforzado 2026-05-31, commit 6ea1e5a):

`$db->Execute` crudo NO aplica `_flattenJsonb` sobre el resultado → las columnas demoted a JSONB (ej. `itemSessions`, `contactFixedComission`) simplemente no aparecen en la fila → el código cae al valor default silenciosamente (guard de sesiones muerto, comisión fija de usuario nunca aplicada = divergencia financiera). Usar SIEMPRE `ncmExecute` para SELECTs que necesiten columnas demoted.

```php
// MAL — $db->Execute no aplana JSONB; itemSessions no existe en la fila
$row = $db->Execute("SELECT * FROM item WHERE itemId=?", [$id]);
$sessions = $row->fields['itemSessions']; // silenciosamente null/0

// BIEN — ncmExecute aplica _flattenJsonb; columnas demoted re-expuestas
$row = ncmExecute($db, "SELECT * FROM item WHERE itemId=?", [$id]);
$sessions = $row['itemSessions'] ?? 0;
```

**Excepción**: para read-modify-write de un blob JSONB completo (patrón RMW), usar `$db->Execute` directo para preservar el raw (ver §22.8.1).

**Regla derivada — validar estado de tx antes de devolver success**: si el handler usa
`StartTrans`/`CompleteTrans` (o si hay riesgo de aborto silencioso), NO llamar
`jsonDieMsg('true',200,'success')` ciegamente al final. Verificar que la tx completó sin
errores:

```php
// Patrón seguro post-tx
if (!$db->CompleteTrans()) {
    jsonDieMsg('false', 500, 'transaction_failed');
}
jsonDieMsg('true', 200, 'success');
```

**Dónde aplica**: cualquier query del legacy de `action.php`/`load.php` que seleccione
columnas que las migraciones 06/07 demotearon a JSONB. Hay docenas de estos en el
monstruo `processData` y en helpers de `app/includes/functions.php`. Cada slice
que toque una de estas queries **debe** aplicar este patrón.

**Detectado en** (commit b45684f): `getItemComsissionTotal()` + query de `userComission`
en `functions.php` (columnas `itemComissionPercent`, `itemComissionType`, `itemSessions`,
`contactFixedComission`). Causaban que `processData` devolviera success con la transacción
abortada → ventas no persistidas.

#### §22.8.1 — Sub-regla: NUNCA usar `ncmExecute` para read-modify-write de JSONB (establecido 2026-05-30, commit b0617ea)

**Regla**: Para leer un blob JSONB completo con intención de modificarlo y re-escribirlo
(patrón read-modify-write), usar **`$db->Execute` directo**, NUNCA `ncmExecute`.

**Por qué**: `ncmExecute` pasa el resultado por `_flattenJsonb`, que desempaqueta las keys del
JSONB dentro de la fila **y hace `unset($row['config'])` (o `$row['data']`/`$row['meta']`)**
internamente. El resultado: `$row['config']` devuelve `null`, perdés el blob crudo. Si después
hacés `UPDATE config = json_encode($result)::jsonb`, estás escribiendo `{}` o solo las keys del
form, destruyendo silenciosamente TODAS las settings que no formaban parte de ese update.

**Incidente real (commit b0617ea)**: `updateLastTimeEdit()` leía `company.config` con
`ncmExecute` → flatten → perdía el raw → re-escribía `config` con solo el campo
`*LastUpdate` → destruía `settingTimeZone`, `settingName`, etc. →
`date_default_timezone_set('')` con string vacío en `data.php:54` → **auth de todo el sistema
rota** hasta que se re-ejecutó el seed `02_sample_company.sql`.

**Patrón correcto para RMW de JSONB**:

```php
// MAL — ncmExecute aplica _flattenJsonb, config queda null → se pierde el blob
$row = ncmExecute($db, "SELECT config FROM company WHERE companyId=?", [$cid]);
$cfg = $row['config']; // null → json_encode([]) → destruís todas las settings

// BIEN — $db->Execute devuelve el resultado ADOdb sin flatten; fields['config'] es el JSON string crudo
$res = $db->Execute("SELECT config FROM company WHERE companyId=?", [$cid]);
$cfg = json_decode($res->fields['config'] ?? '{}', true) ?: [];

// Mergear solo los campos a actualizar
$cfg['companyLastUpdate'] = date('Y-m-d H:i:s');

// Re-escribir el blob completo (preserva todas las otras keys)
$db->Execute(
    "UPDATE company SET config = ?::jsonb WHERE companyId=?",
    [json_encode($cfg), $cid]
);
```

**Aplica a**: cualquier lectura de `data`/`meta`/`config` JSONB seguida de un UPDATE sobre ese
mismo campo. Usar `$db->Execute` (sin flatten) para el SELECT; `json_decode` en PHP para parsear;
`array_merge`/spread para combinar; `json_encode + ::jsonb cast` para el UPDATE. Ver §18 para
el caso análogo en el panel (mismo trap, misma solución con `forceObj`).

#### §22.8.2 — Verificación post-commit en money path + footgun de iftn(x, NULL) (establecido 2026-05-31, commit 6ea1e5a)

**Regla — verificación post-commit (escrituras críticas)**: el wrapper DB (`app/includes/lib/DB.php`) llama a `pdo->commit()` aunque la transacción PG esté abortada (SQLSTATE 25P02). El resultado es un **rollback silencioso que devuelve `true`**. `HasFailedTrans()` no lo detecta (solo refleja `FailTrans()` explícito). Para el money path y cualquier escritura crítica, confirmar con un SELECT post-commit que la fila realmente persistió:

```php
$db->CompleteTrans();

// Verificación post-commit: el wrapper puede hacer commit sobre tx abortada → rollback silencioso
$check = $db->Execute(
    "SELECT transactionId FROM transaction WHERE transactionId = ?",
    [$transactionId]
);
if (!$check || $check->RecordCount() === 0) {
    throw new SaleAbortedException("Transaction not persisted after commit");
}
```

Sin este guard, una venta que rolleó (por cualquiera de los bugs §22.8 / §22.3 / etc.) reportaría success al front → plata/inventario fantasma.

**Footgun de `iftn($x, NULL)` — NUNCA para NULL-coalesce de columnas UUID/timestamp**: la función `iftn()` en `app/includes/functions.php` tiene como primera línea `$else = validity($else) ? $else : ''` → convierte el argumento `NULL` a `''` (string vacío) antes de cualquier evaluación. Resultado: `iftn($x, NULL)` **nunca puede devolver NULL** — si `$x` es falsy, devuelve `''`, que rompe columnas UUID y timestamp en PostgreSQL.

```php
// MAL — iftn nunca devuelve NULL; devuelve '' que rompe FK de tipo UUID
$supplierId = iftn($raw['supplierId'], NULL);

// BIEN — PHP null-coalesce
$supplierId = $raw['supplierId'] ?: null;
```

**Aplica a**: cualquier campo nullable de tipo UUID, timestamp, o entero en columnas que deben ser NULL (no vacío) — en particular `supplierId`, `transactionId`, `locationId` en `manageStock` y similares en helpers de `app/includes/functions.php`.

---

### §22.10 — Footgun de `$db->Prepare()` + patrón side-effects POST-COMMIT BEST-EFFORT (establecido 2026-05-31, commits 1a8d539 + a52ecf6)

#### §22.10.1 — `$db->Prepare()` qstr-quotea TODOS los valores, incluyendo números

**Regla**: NUNCA usar `$db->Prepare($sql)` cuando el valor va a usarse también para lógica de negocio (comparaciones, aritmética). `$db->Prepare()` internamente llama `qstr()` sobre los argumentos, que convierte **cualquier valor a string SQL-quoted** — incluso números: `8000` → `'8000'`. El resultado se guarda en la variable como string, rompiendo todas las comparaciones numéricas y cálculos posteriores.

```php
// MAL — $db->Prepare quoteea el monto: $amount = "'8000'" (string), no 8000 (int)
$amount = $db->Prepare($rawAmount);
if ($amount >= $loyaltyMin) { ... }   // comparación rota: "'8000'" >= 5000 → comportamiento indefinido

// BIEN — mantener el valor numérico para lógica; parametrizar el SQL con ?
$amount = (float) $rawAmount;          // tipo correcto para lógica
// en el SQL:
$db->Execute("INSERT INTO loyalty ... VALUES (?, ...)", [$amount, ...]);
```

**Dónde aplica**: cualquier god-helper o código legacy que llame `$db->Prepare($valor)` antes de usar ese valor en una comparación. Detectado en `manageCustomerLoyalty()` (commit 1a8d539): el monto quoteado nunca superaba `loyaltyMin` → ningún cliente acumulaba puntos.

**Estado actual (commit 2b37d26, 2026-05-31):** los 5 usos restantes de `$db->Prepare()` en `app/includes/functions.php` fueron eliminados y reemplazados por queries parametrizadas (`?` bind). `$db->Prepare()` **no tiene usos válidos en código nuevo** — siempre usar parametrización directa. FIX P0 incluido: en `voidSale`, el Prepare quoteaba el UUID del `$trId` → los 3 sitios bind recibían el UUID con comillas literales → no matcheaban → toda la restauración (loyalty/storeCredit/giftcard/inventario al anular) se saltaba en silencio. Ahora `$trId` es UUID crudo con bind correcto.

#### §22.10.2 — Side effects post-commit: BEST-EFFORT, nunca lanzan

**Regla**: Los side effects externos (email/SMS al cliente, `sendAuditoria`, webhooks, WS) deben ejecutarse **DESPUÉS del commit confirmado** y cada uno envuelto en `try/catch \Throwable` independiente. Ninguno debe lanzar ni revertir la operación ya persistida.

```php
// Patrón canónico (SaleService::save())
$db->CompleteTrans();
// verificación post-commit §22.8.2 ...

// Side effects: best-effort, orden no crítico
try { sendEmailConfirmation($ctx, $result); } catch (\Throwable) { /* log; no lanzar */ }
try { sendSmsConfirmation($ctx, $result); } catch (\Throwable) { /* log; no lanzar */ }
try { sendAuditoria($ctx, $result); } catch (\Throwable) { /* log; no lanzar */ }
```

**Por qué**: un fallo de infra externa (SMTP caído, SMS timeout) NO debe revertir una venta que el cliente ya pagó y el inventario ya descontó. El contrato es: la venta persistió → side effects son consecuencias opcionales. Si un side effect es crítico (ej: el cliente necesita el comprobante antes de salir), modelarlo como parte del path principal, no como best-effort.

**Detectado en**: slice 35a.6 (commit a52ecf6) — `getContactData()` roto en PG hacía que el bloque de notificaciones tirara excepción; sin el catch, abortaba un path que ya había commiteado.

---

### §22.11 — Patrón strangler try-fallback para migración de handlers críticos (establecido 2026-05-31, commit 89b980e)

**Contexto**: al migrar un handler del money-path (o cualquier handler con alto riesgo de regresión) al patrón BFF→API→Service, el front puede adoptar un modo de transición donde intenta el endpoint nuevo y cae al legacy ante error o rechazo. Este patrón se llama **try-fallback** y es el corazón del strangler-fig.

**Reglas del patrón**:

1. **El backend nuevo es la única fuente de verdad de elegibilidad**. Rechaza con `422` lo que no cubre (paths no migrados: gift cards, EI, sesiones, recurrentes, etc.). El front no decide si un payload es elegible; solo interpreta el 422 como "no elegible → usar legacy".

2. **El fallback va ante `422` Y ante error/timeout**. Un error de infra (timeout de red, 500) no debe bloquear la operación — el fallback garantiza que la venta nunca se pierda.

3. **Idempotencia por business-key en AMBOS paths**. Ambos paths (nuevo y legacy) deben tener un dupli check por la misma business-key (ej. `transactionUID` + UNIQUE constraint en la tabla) para que el fallback nunca duplique. En el race condition (nuevo persiste tras timeout, fallback intenta INSERT → UNIQUE 23505 → `{success:"Duplicated Entry"}`), el resultado se trata como success (la fila ya existe, correctamente).

4. **El legacy no se borra hasta estabilidad confirmada en producción**. El cleanup (35a.8) se hace solo cuando hay certeza de que el nuevo path no necesita el legacy como safety-net.

```javascript
// Patrón canónico (app/scripts/app.js — ncmHttp.postSale)
async function postSale(payload, legacyFallback) {
    try {
        const res = await fetch('bff/sales', { ... timeout: 2500 ... });
        if (res.status === 422) throw new Error('not_eligible'); // → fallback
        if (!res.ok)            throw new Error('api_error');    // → fallback
        return await res.json();
    } catch (e) {
        return legacyFallback(payload); // always: postToServer(legacy)
    }
}
```

**Primer uso**: `ncmHttp.postSale()` en `app/scripts/app.js` (commit 89b980e, slice 35a.7). Las ventas simples (cashsale/creditsale type 0/3) van al SaleService; el legacy sigue activo para el fallback y para los paths no migrados (EI, gift cards, sesiones, recurrentes).

**Complementa**: §22.8.2 (idempotencia post-commit), §22.10.2 (side effects best-effort), §23 (home canónico de endpoints).

---

### §22.11.1 — Fuente única de verdad de elegibilidad compartida entre tiers (establecido 2026-05-31, commit dbf2866, slice 35a.8)

**Regla**: cuando una condición de eligibilidad (o cualquier regla de negocio) debe ser evaluada en **dos tiers distintos** (ej. el nuevo Service y el legacy), la regla vive en UNA SOLA función que ambos invocan. No se duplica — ni siquiera "por claridad".

**Contexto**: `saleIsSimplePathEligible($payload, $sale): ?string` en `app/includes/functions.php` determina si un payload de venta es elegible para el path simple del SaleService. Retorna `null` si es simple; retorna un string-motivo si no lo es (giftcard, EI, puntos, storeCredit, recurrente, parent, etc.).

Dos consumidores, dos usos distintos:
- **`SaleInput::assertSimplePathEligible`** (API, tier nuevo): llama a la función global y, si devuelve motivo, lanza `InvalidSaleInputException` → HTTP 422. El front interpreta 422 como "no elegible → fallback legacy".
- **`processData` guard** (legacy, tier viejo): llama a la misma función. Si devuelve `null` (es simple) → `jsonDieMsg` 409 (`ifIstrue=false` en el front → orphans → reintenta SaleService). Si devuelve motivo (no es simple) → la deja pasar al legacy.

**Por qué**: sin esta función compartida, la misma lógica viviría en dos lugares. Cualquier sub-slice futuro (35b-giftcard, 35c-EI, etc.) que migre un path al SaleService solo necesita actualizar `saleIsSimplePathEligible` — ambos tiers lo recogen automáticamente.

**Dónde aplica**: cualquier regla de routing/elegibilidad que deba ser consistente entre el nuevo Service y el legacy. En el patrón strangler-fig, este es el mecanismo de coordinación preferido sobre duplicar la condición en cada tier.

---

### §22.12 — Patrón "API granular + BFF compone" (establecido 2026-05-31, commit c4edef9)

**Decisión del arquitecto**: la API expone **recursos granulares reusables** (un concepto de dominio por endpoint); el BFF los **compone en paralelo** para armar el view-model que necesita el front. La API NO arma endpoints con forma de pantalla. Los endpoints fat (que devuelven 13 queries en un JSON plano pensado para UNA pantalla) son deuda a refactorizar al patrón granular cuando se toquen.

**Plomería canónica**: `bffApiGetMulti(array $endpoints): array` en `app/bff/lib/api_client.php` — curl_multi paralelo; el wall-clock es el del recurso más lento, no la suma. `bffDecodeEnvelope(string $raw): mixed` — decode de envelope factorizado, reutilizable.

**Cuándo usar**:
- Lecturas compuestas donde el front necesita N conceptos independientes de una misma entidad (profile + deuda + ítems + gift cards…).
- Cuando los recursos son reusables por otras pantallas o integraciones.

**Cuándo NO usar**:
- Escrituras: los N calls son N snapshots de DB independientes → un invariante cross-recurso (ej. "debitar y acreditar atómicamente") **no puede verificarse** entre calls paralelos. Para escrituras, usar un único endpoint transaccional.
- Cuando el costo de latencia sea inaceptable y `/api/includes` canónico no esté implementado (ver mitigación abajo).

**Trade-off medido (piloto customerInfo, 2026-05-31)**: composed 95ms vs composite legacy 37ms (~2.5×). Cada call paralelo paga el bootstrap completo (`chdir + head.php + data.php + JWT` por call). Este overhead es el bottleneck — **no** la cantidad de queries.

**Enabler/mitigación**: consolidar `/api/includes` canónico (independiente de /app, sin `chdir`) reduce el overhead de bootstrap a milisegundos → el patrón se vuelve barato. Hasta entonces, usar con criterio (reads no críticas de latencia, pantallas de detalle, no loops).

**Pilot 1 verificado**: `customerInfo` en `app/bff/customers.php` — 5 recursos GET (`profile/recentItems/debt/giftcards/address`) compuestos vía `bffApiGetMulti`. Output BYTE-IDÉNTICO al composite legacy `getInfo()` (diffCount=0 sobre cliente real). `getInfo()` + `?resource=info` quedan como composite legacy/backward-compat.

**Pilot 2 verificado**: `getSummary` (cierre de caja) en `app/bff/drawer.php` — 4 recursos granulares en `api/v1/drawer.php` (`?resource=open|expenses|income|salesByPayment`). El BFF fetch `open` → 3 hijos EN PARALELO con `since=drawerOpenDate` → `drawerComposeSummary()` (rollup financiero). Output BYTE-IDÉNTICO al composite legacy `getSummary()` (drawer vacío + con extracción/propina/ingreso no-cero, tips/subtotal/total correctos). `getSummary()` queda como composite legacy/backward-compat. Ver nota de deuda §22.12.1 abajo.

**Pilot 3 verificado**: `items/getInfo` en `app/bff/items.php` — 2 recursos granulares en `api/v1/items.php` (`?resource=core|inventory`). `core` = campos del ítem + nombres de FK (category/brand/tax/type); dependencia DURA (lleva el 404). `inventory` = stock por outlet/depósito (sólo si `itemTrackInventory`; `[]` si no); informativo → **degradación graceful** (igual que `customerInfo`, NO fail-closed). BFF pide ambos EN PARALELO (`bffApiGetMulti`) y mergea. Ensamblaje PURO — sin cómputo de rollup (sin deuda §22.12.2). `getInfo()` queda como composite backward-compat. Output verificado BYTE-IDÉNTICO al composite legacy (ítem con tracking, sin tracking, y 404). Segundo caso de ensamblaje-puro-graceful junto a `customerInfo`; contrasta con el fail-closed financiero de `drawer`. (commit 2bca565, 2026-05-31)

### §22.12.1 — Sub-regla: FAIL-CLOSED para datasets financieros (establecido 2026-05-31, commit 8aff931)

**Regla**: cuando el dataset compuesto por el BFF es un **rollup financiero** (cierre de caja, totales de dinero, saldos), el BFF debe ser **FAIL-CLOSED** — si cualquier recurso hijo falla, cortar con su error en vez de degradar a cero o parcial.

**Por qué**: un total sub-reportado en silencio (income=0 porque el hijo falló) es peor que un error explícito. El operador necesita saber que el cierre está incompleto, no ver un cierre falso. Contrasta con datasets informativos (perfil de cliente, historial de ítems recientes) donde la degradación graceful (mostrar lo que se pudo obtener) es aceptable.

**Tabla de decisión**:

| Tipo de dataset | Estrategia | Ejemplo |
|-----------------|-----------|---------|
| **Rollup financiero** (dinero, totales, saldos) | **FAIL-CLOSED** — cualquier hijo que falla → error al front | `getSummary` (cierre de caja) |
| **Informativo** (perfil, historial, metadatos) | **Degradación graceful** — partes opcionales ausentes → mostrar con defaults/null | `customerInfo` (profile es duro; debt/giftcards son opcionales) |

**Cómo aplicar en el BFF**:
```php
// FAIL-CLOSED (rollup financiero)
$results = bffApiGetMulti([...]);
foreach ($results as $key => $raw) {
    $decoded = bffDecodeEnvelope($raw);
    if ($decoded === null || !isset($decoded['ok']) || !$decoded['ok']) {
        // cortar con el error del hijo — no degradar
        http_response_code(502);
        echo json_encode(['error' => "Resource $key failed"]);
        exit;
    }
}
```

### §22.12.2 — Deuda: fórmula de rollup duplicada entre /api y /app/bff (establecido 2026-05-31, commit 8aff931)

**Deuda registrada**: cuando el endpoint BFF-compone ADEMÁS COMPUTA un rollup (no solo ensambla datos independientes), la fórmula queda duplicada — una vez en `/api` (ej. `DrawerService::composeSummary()`) y otra en `/app/bff` (ej. `drawerComposeSummary()`). Este es el **costo inherente del modelo BFF-compone para endpoints con cómputo**, vs el ensamble puro como `customerInfo`.

**Mitigación actual**: comentario cruzado `// MANTENER EN SYNC con <contraparte>` en ambos lados (commit 8aff931). No hay test golden que ate ambas salidas; cualquier divergencia solo se detecta manualmente.

**Mitigación futura**: cuando se consolide `/api/includes` canónico, considerar extraer la fórmula a un módulo compartido sin duplicar. Por ahora el comentario cruzado es suficiente — no bloquea ningún slice.

**Dónde aplica**: cualquier BFF-compone donde el rollup requiera matemática cross-recurso (sumar expenses + income + salesByPayment para derivar neto). Si el BFF solo mergea datos sin calcular, no hay duplicación.

---

### §22.13 — Dos roles del BFF de /app: Traductor de protocolo vs Compositor multi-fuente (decisión P1.5, commit 9f30891, 2026-06-01)

**Contexto:** al analizar los 19 BFFs de `/app/bff/`, se los había etiquetado preliminarmente como "pass-through redundantes". Esa etiqueta era **incorrecta**. Los BFFs hacen trabajo real y tienen dos roles bien diferenciados:

#### Rol 1 — Traductor de protocolo `?l=` → REST (16 de los 19 BFFs)

El front del POS construye y envía un sobre `?l=base64(json)` legacy (`masterUrlParams`) para TODA su comunicación. El BFF es el responsable de:

1. Decodificar ese sobre (`json_decode(base64_decode($_GET['l']))`).
2. Mapear la acción legacy (`$action`) al verbo HTTP + resource params del API.
3. Shapear datos entre el formato legacy del front y el envelope canónico de la API.

Cada BFF es un **traductor de dominio**: entiende las acciones de su concern (mesas, órdenes, clientes, etc.) y las mapea al REST canónico. No son redirectores 1:1 — son traductores de protocolo.

**Los 16 BFFs traductores**: `attendance`, `currencies`, `customer_address`, `customer_note`, `electronic_invoice`, `giftcards`, `notifications`, `order_items`, `orders`, `register`, `sales`, `schedule`, `sync`, `tables`, `transactions`, `vpayments`.

#### Rol 2 — Compositor multi-fuente (3 BFFs — §22.12)

Los tres BFFs restantes ADEMÁS del rol de traducción hacen composición: piden múltiples recursos del API EN PARALELO y ensamblan el dataset del view-model.

| BFF | Recursos que compone |
|-----|---------------------|
| `customers.php` | `profile + recentItems + debt + giftcards + address` (5 recursos, `bffApiGetMulti`) |
| `drawer.php` | `open + expenses + income + salesByPayment` (4 recursos, `bffApiGetMulti`) |
| `items.php` | `core + inventory` (2 recursos, `bffApiGetMulti`) |

Ver §22.12 para las reglas de composición (cuándo usar `bffApiGetMulti`, fail-closed vs degradación graceful, deuda de fórmula duplicada).

#### Por qué NO se consolidó en un router único

La decisión explícita (P1.5) fue **mantener la estructura por dominio** (un archivo por concern). Un router único habría creado un `switch` de ~400 líneas, menos debuggable y más frágil de mantener. La estructura por archivo preserva la cohesión y hace que cada cambio en un dominio quede en su propio archivo.

#### Bootstrap común: `app/bff/lib/bff_init.php`

El boilerplate repetido en los 19 BFFs fue extraído a `app/bff/lib/bff_init.php` (commit 9f30891, -104 líneas netas). Cada BFF lo incluye con una línea:

```php
require_once __DIR__ . '/lib/bff_init.php';
```

El archivo hace exactamente:
1. `require_once` de `api_client.php`
2. Verifica `$_COOKIE['_jwt']` — devuelve 401 si ausente
3. Decodifica `$_GET['l']` → `$get` (array del sobre legacy)
4. Setea `$action` desde `$get['action']`

Los BFFs no duplican más ese bloque. Solo agregan su lógica de dominio.

#### Cuándo crear un BFF nuevo para /app

- Si el concern es un **traductor de protocolo** (acciones del front → REST): crear `app/bff/<concern>.php`, incluir `bff_init.php`, rutear `$action`.
- Si además **compone múltiples recursos**: agregar `bffApiGetMulti` según §22.12, aplicando fail-closed o degradación graceful según el tipo de dataset.
- Si es una **escritura** o **invariante cross-recurso**: usar un único endpoint transaccional, no composición paralela.

---

### §22.14 — Patrón canónico para Services en `api/lib/services/` (P1.5, commit 23cdd76, 2026-06-01)

**Contexto**: el commit P1.5 modernizó los 18 Services legacy de `api/lib/services/` que existían sin namespace ni DI (arrastrados desde el inicio del desacople). A partir de este commit, todos tienen el mismo header estándar.

**Patrón obligatorio** para cualquier Service en `api/lib/services/*.php`:

```php
<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

final class XxxService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}
    // ...
}
```

**Los dos servicios que además reciben `\DB $db`** (porque usan `$db->Execute()` directo para operaciones que `ncmExecute` no cubre):

```php
final class NotificationService   // y TransactionService
{
    public function __construct(
        public readonly TenantContext $ctx,
        public readonly \DB $db,
    ) {}
}
```

Los 16 servicios restantes reciben **solo `TenantContext $ctx`**.

**Inyección desde el endpoint** (`api/v1/*.php`):

```php
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\XxxService;

$ctx = apiAuthTenant();
$svc = new XxxService(TenantContext::fromAuth($ctx));

// Para los dos con \DB:
global $db;
$svc = new NotificationService(TenantContext::fromAuth($ctx), $db);
```

`TenantContext` es el DTO inmutable de identidad de tenant. Su fuente es `api/lib/Context/TenantContext.php` (`namespace Punto\Api\Context`). El `global $db` queda ÚNICAMENTE en el endpoint; dentro del service siempre se usa `$this->ctx` y `$this->db`.

**Distinción con módulos nuevos** (ej. `api/lib/Sales/`): los servicios en `api/lib/services/` usan el mismo namespace `Punto\Api\Services` y siguen el patrón §22.14; los módulos nuevos de dominio en subdirectorios PascalCase (`api/lib/Sales/`, etc.) usan `Punto\Api\<Module>` y aplican el estándar completo §22.9 (DTOs, enums, excepciones custom).

---

### §22.9 — Estilo PHP para código nuevo en `/api` (establecido 2026-05-30, slice 35a.1)

Para módulos NUEVOS bajo `api/lib/` (no aplica al `api/lib/services/*` existente, que sigue
sin namespace por inercia): aplicar el set de estándares modernos PHP 8.1+.

**Obligatorios** en cada archivo nuevo:

1. **`declare(strict_types=1)`** — primer statement, fuerza chequeo de tipos en boundaries.
2. **`namespace Punto\Api\<Module>`** — autoloader PSR-4 mínimo en `api/bootstrap.php` mapea
   `Punto\Api\Foo\Bar` → `api/lib/Foo/Bar.php`. Sin Composer.
3. **`final class`** por default — cerrar a herencia accidental. Si se necesita extender,
   considerar interfaz o composición primero.
4. **`readonly` properties + constructor promotion** — dependencias inmutables:
   ```php
   public function __construct(
       private readonly TenantContext $ctx,
       private readonly DB $db,
   ) {}
   ```
5. **Type hints** en parámetros y returns. NO `array` sin shape — preferir DTO tipado.
6. **DTOs de entrada/salida** — validan en construcción, arrojan excepción custom si el
   shape no calza. Ver `Punto\Api\Sales\SaleInput::fromPayload()`.
7. **Excepciones custom** por dominio — no devolver `['error' => ...]` desde el servicio.
   El endpoint las captura y mapea a HTTP status apropiado (422/409/500).
8. **Enums** para magic numbers — `SaleType: int` en vez de `(int) 0`.
9. **Sin `global $db`** dentro de métodos del servicio. El `global $db` queda contenido en
   el endpoint (`api/v1/*.php`), que lo lee del bootstrap y lo pasa al constructor del
   servicio por DI. Adentro del servicio: `$this->db->Execute(...)`, nunca `global $db`.
   Para helpers del legacy (`manageStock`, `sendAuditoria`, etc.) sí se acepta llamada
   global por ahora — wrappear en interfaces queda como deuda registrada (no bloquea
   slices).

**NO obligatorio aún** (deuda registrada):

- **Composer / PSR-4 completo**: el autoloader de `bootstrap.php` es manual, ~15 líneas.
  Funciona pero no escala a deps externas con namespaces colisionables. Migrar a Composer
  va con otro slice.
- **PHPUnit / Pest**: no hay infra de tests unitarios. Hasta que la haya, los tests son
  smoke E2E via curl + verificación de DB. Los DTOs y excepciones custom hacen el código
  testeable sin reescribirlo cuando se monte la infra.
- **Wrapping de helpers del legacy en interfaces**: idealmente
  `interface StockManager { manage(...) }` con implementación default que llama al global,
  permite mockear en tests. Por ahora cada slice los llama directo. Cuando aparezca el
  primer test unitario que necesite mockear → se introduce la interfaz.

**Coexistencia con código viejo**: los servicios en `api/lib/services/*` ya tienen namespace
y DI desde el commit 23cdd76 (P1.5 — ver §22.14). NO se migran más atributos preventivamente;
si se toca un service por razón funcional, se aplica el patrón §22.14 completo en el mismo commit.

**Estructura de directorios** (ejemplo de `api/lib/Sales/`):
```
api/lib/
├── services/                      ← legacy (CustomerNoteService, etc.) — no se mueven
├── Context/
│   └── TenantContext.php          ← Punto\Api\Context\TenantContext (DTO inmutable)
└── Sales/
    ├── SaleService.php            ← Punto\Api\Sales\SaleService
    ├── SaleInput.php              ← DTO input
    ├── SaleResult.php             ← DTO output
    ├── SaleType.php               ← enum
    └── Exceptions/
        ├── InvalidSaleInputException.php
        ├── DuplicateSaleException.php
        └── SaleAbortedException.php
```

---

### §22.4 — Bootstrap del contexto POS en los endpoints de /api

Los endpoints en `api/v1/` no tienen el contexto global que carga `head.php`/`data.php` en el flujo legacy. El bootstrap adoptado: `api/bootstrap.php` hace `chdir(/app)` y requiere `head.php` + `data.php` vía rutas absolutas (`API_APP_DIR`). Los endpoints llaman `apiAuthTenant()` (que internamente hace ese bootstrap). Este es el approach transitorio — la deuda es consolidar un `/api/includes` propio (ver `10-roadmap.md § Consolidar /api/includes`).

### §22.7 — Verbos REST en `/api/v1` (alineado con panel/API/v1)

**REGLA**: los endpoints de `/api/v1` usan **verbos HTTP REST** vía `switch($_SERVER['REQUEST_METHOD'])`,
igual que `panel/API/v1/` (contacts/items/outlets). NO usar el patrón POST+`op` (RPC) — fue un
error de los slices 6-15 (estilo espejo del sobre `?l=` legacy), retrofiteado al estándar REST.

Convención de verbos:
| Verbo | Uso | Recurso |
|-------|-----|---------|
| `GET` | lectura pura (sin mutación) | filtros/ids por query (`?id=`, `?customerId=`) |
| `POST` | crear recurso nuevo · acción no-idempotente · lectura-con-payload-grande (search) | body |
| `PUT` | update idempotente, **incluye transiciones de estado** (accept/reject/reschedule/rename/setSession) | `?id=` + body |
| `DELETE` | eliminar recurso | `?id=` |
| Sub-recurso / sub-acción | calificar con `?resource=<nombre>` | (panel convention) |

- **Recursos por `?id=` query param**, NO path params (`/v1/orders/123/accept`): el router mapea
  path→archivo, no parsea segmentos. Sub-recursos vía `?resource=` (ej: `?id=X&resource=default`).
- **Body de PUT/DELETE**: PHP NO puebla `$_POST` salvo en POST form-encoded. `api/bootstrap.php`
  tiene un shim que parsea `php://input` (JSON o form-encoded) → `$_POST` para PUT/DELETE/PATCH,
  así los endpoints leen `$_POST` uniforme.
- **BFF**: usa `bffApiGet/bffApiPost/bffApiPut/bffApiDelete` (`app/bff/lib/api_client.php`). El BFF
  traduce el `action`/`load` del sobre `?l=` legacy al verbo + query correctos; el front no cambia.
- **Acciones POS que no son CRUD puro** (accept/reject/toggle/closeTable): se modelan como `PUT`
  (transición de estado del recurso) o `DELETE` (si el efecto neto es borrar), con `?resource=` si
  hace falta distinguir la sub-acción. Sólo usar `POST` para crear o para reads con payload grande
  (ej: `sync` recibe una lista de IDs).

> **⚠️ BUG HISTÓRICO CERRADO (commit e3d02cc, 2026-05-31):** `bffApiGet` llamaba a `bffApiSend` con 3 args → el cuarto parámetro (método) defaulteaba a `'POST'`. Todos los reads GET llegaban a la API como POST → "Operación no reconocida" en cualquier endpoint gateado por `$method === 'GET'`. Fix: `bffApiGet` pasa `'GET'` explícito como cuarto arg. **Regla derivada**: al agregar o modificar funciones wrapper en `api_client.php`, siempre verificar que el método HTTP correcto se propague explícitamente; no depender de defaults. Al verificar un slice de lectura, hacerlo **a través del BFF** (no solo con curl directo) para que este tipo de bug sea visible.

---

## §24 — Migración de handlers HTML server-rendered a Alpine (decisión definitiva 2026-05-29, commit 3d62191)

**Contexto histórico**: en el Slice 33 original (commit b0fbec3) se estableció un patrón intermedio con Mustache para `customerRecord`, argumentando que el guardado legacy (`recordsEdit`) lee el DOM por ids/clases y reescribirlo junto con el render era de scope mayor. Ese patrón fue **reemplazado** en el mismo ciclo (commit 3d62191): el template Mustache `#customerRecordTpl` se reescribió con Alpine.

> **§24 ya NO recomienda Mustache para handlers HTML server-rendered. La convención vigente es Alpine (§17).**

**Por qué se migró a Alpine y no se quedó en Mustache**:
- El guardado (`recordsEdit`, `switchit()`) opera sobre atributos del DOM (`checked`, `contenteditable`). Alpine reproduce esos mismos atributos con `x-if` de dos ramas (una con `checked` literal, una sin), alineándose con `switchit()` que lee/escribe el atributo (no la propiedad).
- Alpine elimina la fase de `renderTemplate()` + callback post-render: `x-init` / `$nextTick` hace el setup de datePicker/Dropbox declarativamente.
- Mustache cargado como legacy sigue presente para los ~22 templates que aún no se migraron, pero **no se crean templates Mustache nuevos**.

**Patrón de integración Alpine para `/app` (POS offline) — receta canónica**:

```
Template markup  →  <template id="<nombre>"> con x-data/x-for/x-if/x-text/x-html
Registro         →  Alpine.data('<nombre>', fn) dentro de document.addEventListener('alpine:init', ...)
                    en app/scripts/app.js (única fuente — ver §22.2b)
Fetch            →  ncmHttp.getit() (cliente HTTP del POS) — NO fetch nativo, preserva auth/plumbing offline
Render dinámico  →  clonar el <template>, fijar data-attrs (ej. cid), Alpine.initTree(el) con el nodo
                    DETACHED, luego insertar en el DOM
x-for            →  exige raíz única → usar wrapper <div style="display:contents"> cuando hay
                    columnas Bootstrap múltiples side-by-side dentro del loop
Switch           →  dos ramas x-if (con checked / sin checked) para reproducir el atributo literal
                    que switchit() y recordsEdit leen del DOM
Orden de carga   →  app.js (no-defer) corre antes de que Alpine (auto-start en DOMContentLoaded)
                    dispare alpine:init → el listener queda registrado en tiempo
```

**Reglas vigentes (reemplaza la receta Mustache anterior)**:
1. El template vive en `<template id="...">` en `app/index.php` **y** en `app/index.html`. Mantener ambos en sync.
2. El template reproduce EXACTAMENTE las clases/ids que el guardado espera. No renombrar atributos.
3. La API devuelve datos crudos tipados. El BFF traduce al shape que el componente Alpine consume — nunca genera HTML.
4. Los comportamientos que requieren el nodo en el documento (datePicker, uploaders Dropbox) van en `$nextTick` o en el callback posterior a `Alpine.initTree`, no en el `init()` del componente (que corre detached).
5. La SQL injection se corrige en el Service (queries parametrizadas), no se porta el bug legacy.

**Estado de Mustache en /app (2026-05-29)**:
- Alpine.js 3.14.1 vendoreado en `assets/vendor/js/alpinejs-3.14.1.min.js` (local — el POS es offline). Cargado en `app/index.html` (script defer), `app/cache-sw.php` (precache), `app/filesCompiler.php` (bundle vendor). `APP_VERSION` llegó a 2.0.9.6 al unificar el front en `app.js` (ver §22.2b).
- Mustache 4.0.1 sigue cargado (los demás templates existentes lo usan). Es legacy en deprecación incremental.
- **No crear templates Mustache nuevos en `/app`**. Los existentes se migran a Alpine cuando se toquen (migración incremental, no preventiva).

**Primer uso de Alpine en /app**: `customerRecord` (Slice 33 reescrito, commit 3d62191). 7 tipos de campo: text, number, date, phone, switch, progress, image.

**Aplica a**: cualquier handler que el legacy renderizaba como HTML server-rendered en `/app`.

---

## §25 — Calidad de código: CI, editorconfig, composer validate (establecido 2026-06-04)

### §25.1 — CI valida solo diff (no el repo entero)

El workflow `.github/workflows/ci.yml` corre `php -l` y `node --check` sobre los archivos **cambiados** en cada PR/push, no sobre todo el codebase. Razón: el repo tiene 3 archivos PHP con sintaxis rota (deuda histórica — 0.8%). Ver `06-infraestructura.md § CI` para la lista exacta y los comandos para reproducir localmente.

**Consecuencia práctica**: si tocás `panel/a_report_schedule.php`, `panel/a_report_production.php` o `panel/languages/en.php`, el CI los va a lintear y va a fallar hasta que se corrija la sintaxis rota. Es intencional — estos archivos son el target de cleanup progresivo.

### §25.2 — `.editorconfig` estándar

El archivo `.editorconfig` en la raíz del repo fija el estilo de formateo:

| Contexto | Indentación | Otros |
|----------|-------------|-------|
| General (default) | 2 espacios | UTF-8, LF, final newline, trim trailing whitespace |
| `*.php` | 4 espacios | — |
| `Makefile` | tab | — |
| `vendor/**`, `cach/**`, `*.min.js`, `*.min.css` | (excluidos) | No aplicar |

Los editores compatibles con EditorConfig respetan esto automáticamente. No necesita configuración manual por desarrollador.

### §25.3 — `composer validate --strict` requiere `license`

El job `composer-validate` del CI corre con el flag `--strict`. Este modo falla si falta el campo `license` en `composer.json`. El código es propietario → ambos `app/composer.json` y `panel/composer.json` declaran `"license": "proprietary"`. Si se agrega un nuevo `composer.json` en el repo, incluir `"license": "proprietary"` desde el inicio.

### §25.4 — Versiones de runtime usadas en CI (fuente canónica para compatibilidad)

| Runtime | Versión | Usado en |
|---------|---------|---------|
| PHP | 8.4 | job `php-lint` |
| Node.js | 20 | job `js-syntax` |

Estas son las versiones contra las que se valida el código en CI. Si se necesita reproducir localmente con exactitud, usar PHP 8.4 y Node 20. La versión PHP de prod puede diferir; confirmar en `context/03-stack.md`.

---

## §26 — Código nuevo en `/app`: usar namespace `Punto\App\*` (establecido 2026-06-04, commit 8a7819c)

**Regla**: todo código PHP nuevo que se agregue en `/app` (fuera de los includes legacy y BFFs existentes) debe vivir bajo el namespace `Punto\App\*` y seguir la estructura de directorios PSR-4 establecida en el Slice 0.

**Estructura de namespaces disponibles en `/app`:**

| Namespace | Directorio | Para qué | Clases existentes |
|-----------|-----------|---------|------------------|
| `Punto\App\Helpers\` | `app/Helpers/` | Utility puras — validity, iftn, toUTF8, niceDate y similares | **`Validation`** (S3), **`Str`** (S4), **`Date`** (S5), **`Math`**, **`Arr`**, **`Cond`** (S6) |
| `Punto\App\Domain\Customer\` | `app/Domain/Customer/` | Lógica de clientes y contactos | **`Customer`** (Slice 9, commit 51d600b) — 11 métodos estáticos; 139 callsites. Fix P0 en `getName(mixed $data)`: tipado `mixed` + early-return on false (legacy toleraba false sin fatal). |
| `Punto\App\Domain\Money\` | `app/Domain/Money/` | Cálculos monetarios, comisiones, impuestos | **`Money`** (Slice 12) — 8 métodos, 702 callers: `formatNumber` (530), `formatQty` (85), `formatForDB` (73), `addTax` (7) y otros. Hogar canónico para formateo monetario. |
| `Punto\App\Domain\Inventory\` | `app/Domain/Inventory/` | Stock, movimientos, depósitos | **`Inventory`** (Slice 13) — 11 métodos, 116 callers: `manageStock` (27 CRÍTICO), `getCompoundsArray` (23), `getItemStock` (16), COGS y capacidad de producción. Hogar canónico para stock. |
| `Punto\App\Domain\Document\` | `app/Domain/Document/` | Facturas, comprobantes, numeración | **`Document`** (Slice 11) — `getNextDocNumber` (12 callers). |
| `Punto\App\Domain\Store\` | `app/Domain/Store/` | Mesas, órdenes, registros, outlets | **`Store`** (Slice 8) |
| `Punto\App\Domain\Taxonomy\` | `app/Domain/Taxonomy/` | Categorías, marcas, impuestos | **`Taxonomy`** (Slice 7) |
| `Punto\App\Domain\GiftCard\` | `app/Domain/GiftCard/` | Gift cards | **`GiftCard`** (Slice 14) — `insertNew` (1 caller). |
| `Punto\App\Http\Response\` | `app/Http/Response/` | Helpers de respuesta HTTP | **`Json`**, **`Output`** (Slice 2) |
| `Punto\App\Services\Notification\` | `app/Services/Notification/` | Email, SMS, Push, FE | **`Notification`** (Slice 15) — 7 métodos, 76 callers: `sendEmails` (23), `sendSMS` (17), `sendWS` (11), `sendPush` (10), `sendEmail` (9), `sendSMTP` (5), `sendNCMSMS` (1). |
| `Punto\App\Database\` | `app/Database/` | Query wrapper (reemplaza ncmExecute/Insert/Update) | **`Query`** (Slice 10, commit 51d600b) — 7 métodos; wrappea el god node `ncmExecute` (1035 callers). `execute()` llama `self::flattenJsonb()` directo; `getValue()` llama `self::execute()` directo. Ver §26.2 abajo. |

### §26.1 — Patrón "Wrapper → Clase namespaced" (Approach C, establecido en Slice 2, commit ceed82d)

**El patrón canónico** para migrar funciones globales de `functions.php` a PSR-4 sin tocar los callers existentes:

1. **Crear la clase** `final` en el namespace `Punto\App\*` con métodos estáticos (para utility) o instancia con DI (para services).
2. **Convertir la función global** en un wrapper de 1 línea con docblock `@deprecated` apuntando a la clase nueva.
3. **Los callers existentes NO se tocan** — siguen funcionando transparentemente vía el wrapper.
4. **Código nuevo** usa la clase directamente.
5. **Los wrappers se mantienen ≥2 releases** antes de eliminarse; no remover hasta que no quede ningún callsite del legacy usándolo.

**Ejemplo canónico (Slice 2 — `app/Http/Response/Json.php`):**

```php
// app/Http/Response/Json.php (NUEVO — Punto\App\Http\Response)
namespace Punto\App\Http\Response;
final class Json {
    public static function send(mixed $payload, int $code = 200): never { ... }
    public static function die(string $msg = 'true', int $code = 401, string $type = 'error'): never { ... }
}

// app/includes/functions.php (wrapper — mantiene los 61 callers legacy intactos)
/**
 * @deprecated Slice 2 (PSR-4). Usar `\Punto\App\Http\Response\Json::die()` en código nuevo.
 *             Este wrapper se mantiene para los ~61 callers legacy.
 */
function jsonDieMsg($msg='true',$code=401,$type='error'){
    \Punto\App\Http\Response\Json::die($msg, $code, $type);
}
```

**Clases en `app/Http/Response/` (POBLADAS — Slice 2, commit ceed82d):**

| Clase | Reemplaza a | Callers legacy preservados |
|-------|------------|---------------------------|
| `Json::send($payload, $code=200)` | `jsonDieResult($array, $code)` | 158 |
| `Json::die($msg, $code=401, $type='error')` | `jsonDieMsg($msg, $code, $type)` | 61 |
| `Output::dai($val, $noclose=false)` | `dai($val, $noclose)` | 542 |

`Output::dai()` cierra `$GLOBALS['db']` antes de `die()` (comportamiento idéntico al legacy). **Total: 761 callsites legacy intactos** — el wrapper es completamente transparente.

**Convenciones de estilo** (igual que §22.9 para `/api`, adaptado al contexto `/app`):

1. `declare(strict_types=1)` en la primera línea.
2. Namespace canónico del directorio (`namespace Punto\App\Helpers;`, etc.).
3. `final class` por default.
4. `readonly` properties + constructor promotion para DI.
5. PascalCase para clases, camelCase para métodos y variables (consistente con el resto del proyecto).
6. Métodos **estáticos** para utility puras sin estado (`Helpers\*`); instancia con DI para services y domain objects.

**Autoloader**: `app/composer.json` gestiona el autoload vía `composer dump-autoload --optimize`. El map PSR-4 ya está configurado con los 5 prefijos. No usar `require`/`include` para clases bajo `Punto\App\*`.

**Convivencia con el legacy**:

- Las funciones globales de `app/includes/functions.php` **siguen funcionando** y son la fuente viva mientras existan los wrappers. NO se deprecan sin tener el reemplazo PSR-4 verificado.
- Al migrar una función global a PSR-4, dejar el wrapper en `functions.php` que llame a la clase nueva. El wrapper se elimina solo cuando no quede ningún callsite del legacy usándolo.
- **NO usar funciones globales de `functions.php` desde código nuevo** bajo `Punto\App\*` — crear el equivalente PSR-4 o inyectarlo. Excepciones documentadas en el plan `docs/PLAN_functions_php_PSR4.md`.

**`app/Helpers/SmokeTest.php`** (transitoria — se elimina en Slice 1): clase de prueba que verifica que el autoloader resuelve. No depender de ella en ningún otro código.

### §26.2 — `App\Database\Query` — el god node `ncmExecute` tiene hogar namespaced (Slice 10, commit 51d600b)

`Punto\App\Database\Query` es la clase PSR-4 que wrappea los 7 helpers del core DB de `/app`:

| Método | Reemplaza | Callers |
|--------|-----------|---------|
| `Query::execute()` | `ncmExecute()` | 1035 (god node) |
| `Query::getValue()` | `getValue()` | 99 |
| `Query::update()` | `ncmUpdate()` | 69 |
| `Query::insert()` | `ncmInsert()` | 47 |
| `Query::flattenJsonb()` | `_flattenJsonb()` | 23 |
| `Query::delete()` | `ncmDelete()` | 3 |
| `Query::while()` | `ncmWhile()` | 1 |

**Auto-referencia interna**: `execute()` invoca `self::flattenJsonb()` directamente (no la función global); `getValue()` invoca `self::execute()` directamente. Las dependencias están encapsuladas dentro de la clase.

**Implicación arquitectónica**: el god node `ncmExecute` — que en el grafo de dependencias tenía 124 edges — ahora tiene una representación namespaced en `Punto\App\Database\Query::execute()`. El alias global `ncmExecute()` sigue vivo como wrapper para los 1035 callers legacy; código nuevo usa `Query::execute()` directamente.

**Ver también**: `02-arquitectura.md § God nodes` y `docs/PLAN_functions_php_PSR4.md`.

**Ver también**: `02-arquitectura.md § Modernización PSR-4 de /app`, `10-roadmap.md § Top-5 mejoras estructurales`, `docs/PLAN_functions_php_PSR4.md`.

---

## §27 — Servicios del realm `/admin`: `mergeConfig()` inline, sin importar `functions.php` (establecido F3.1, commit 747384d, 2026-06-05)

**Regla**: Los services de `panel/lib/admin/` (realm de super-admins de plataforma) **NO deben importar** `panel/includes/functions.php` ni `app/includes/functions.php`. El realm `/admin` está criptográficamente aislado del realm tenant; importar el mega-file de funciones del realm tenant lo contamina y genera acoplamiento indeseable.

**Problema concreto**: `_flattenJsonb()` (la función que aplana JSONB de columnas como `company.config`) vive en `panel/includes/functions.php`. Los services de `/admin` necesitan aplanar ese JSONB para exponer `settingName`, `companyName`, `eposData`, `moduleData`, etc. de las companies.

**Solución — `mergeConfig()` inline**: cada service de `/admin` que necesite aplanar JSONB implementa su propia función `mergeConfig()` privada (o equivalente). La implementación replica la lógica de `_flattenJsonb` en ~15 líneas, sin dependencia externa:

```php
private function mergeConfig(array $row): array {
    $config = json_decode($row['config'] ?? '{}', true) ?: [];
    unset($row['config']);
    return array_merge($row, $config);
}
```

**Por qué no extraer a un shared helper**: la función es mínima y el costo de duplicación es menor que el riesgo de crear una dependencia entre `panel/lib/admin/` y los includes del realm tenant. Si en el futuro `/admin` tiene ≥3 services que la necesiten, considerar `panel/lib/admin/lib/JsonbHelper.php` como shared interno del realm.

**Detectado en**: `CompanyAdminService` (F3.1) — aplana `company.config` JSONB post-migración PG (§22.8). El mismo patrón debe seguirse en cualquier service futuro del realm `/admin` que necesite columnas demoted a JSONB.

---

## §28 — Modelo de auth de /app: device pairing ≠ sesión (establecido commit 7e1b26f, completado a3fefb4, 2026-06-06)

**Regla**: el JWT de `/app` (`_jwt`) NO es una sesión de usuario. Es un **device pairing**: certifica que "este dispositivo está autorizado a operar como caja de esta empresa". No acortar `JWT_TTL` (actualmente 10 años) — la revocación per-device ya está implementada vía la tabla `device` (ver §29 abajo).

**Los dos niveles de auth de /app — distinción crítica:**

| Nivel | Nombre | Mecanismo | Quién lo activa | TTL | Cuándo caduca |
|-------|--------|-----------|-----------------|-----|--------------|
| **Capa dispositivo** | Device pairing | JWT `_jwt` + claim `did` (deviceId) | Admin (user+password, una vez por dispositivo) | `JWT_TTL` = 10 años | Al revocar el device (status=0) o al rotar `JWT_SECRET` |
| **Capa cajero** | Sesión de turno | PIN de 4 dígitos → `ncmAuth.activeUser` + `lockPad` en JS | Cajero (entrada/salida por turno) | Por turno — no persiste | Al salir del turno / bloquear la pantalla |

**Por qué `JWT_TTL=10y`**: una caja apagada un fin de semana o en una sucursal sin admin presente no debe quedar inutilizable el lunes. El PIN maneja el acceso por turno; el JWT maneja el emparejamiento del dispositivo. Son concerns distintos y no se deben colapsar.

**Revocación per-device (IMPLEMENTADA — commit a3fefb4, 2026-06-06)**: la tabla `device` permite revocar un dispositivo individual sin afectar al resto. Ver §29 para el procedimiento. La revocación global (rotar `JWT_SECRET`) sigue disponible como medida de emergencia masiva.

**Contraste con /admin**: `ADMIN_JWT_TTL=8h` porque el super-admin SÍ tiene una sesión real: abre el panel de administración desde su browser personal y espera que expire al cabo del día. El modelo de su auth es el modelo clásico de sesión web.

**Anti-patrón a evitar**: nunca ajustar `JWT_TTL` de /app pensando en "seguridad de sesión". La seguridad de acceso por turno la provee el PIN. El JWT de /app es análogo a un certificado de dispositivo — su vigencia no cambia el riesgo de acceso no autorizado por un cajero.

---

## §29 — Revocación per-device en /app (implementado commit a3fefb4, 2026-06-06)

**Contexto**: cierra el escenario "empleado que renuncia se lleva /app logueada en su celular". El modelo anterior solo permitía revocación global (rotar JWT_SECRET, afecta a TODOS los devices). El modelo nuevo permite revocar un device específico sin interrumpir las demás cajas.

**Componentes**:

| Componente | Archivo | Responsabilidad |
|-----------|---------|----------------|
| Migración | `database/migrations/postgres/11_device.sql` | Tabla `device` (UUID PK, companyId FK ON DELETE CASCADE, userId, outletId, registerId, deviceName, userAgent, ipFirst INET, ipLast INET, lastSeenAt, status SMALLINT 0/1, revokedAt, revokedBy, createdAt) + 3 índices |
| Helper | `app/includes/device.php` | `deviceRegister($companyId, $userId, $outletId, $registerId): ?string` — INSERT row + retorna deviceId UUID |
| Middleware | `app/includes/jwt_middleware.php` | Valida `device.status` si JWT trae claim `did`. Cache file 60s. Nuevas: `jwtIsDeviceRevoked()`, `jwtInvalidateDeviceCache()`, constante `AUTHED_DEVICE_ID` |
| Login | `app/login.php`, `app/API/auth.php` | Llaman `deviceRegister()` antes de emitir JWT; agregan `did` al payload |
| Refresh | `app/API/refresh.php` | Chequea device.status antes de emitir token nuevo; preserva `did` en el payload renovado |

**Dos vías de revocación**:

#### A — Admin-initiated (SQL directo o futura UI panel)

```sql
-- 1. Revocar en BD
UPDATE device SET status=0, revokedAt=NOW(), revokedBy='<adminUUID>'
WHERE deviceId='<deviceId>' AND companyId='<companyId>';
```

```php
// 2. Forzar invalidación inmediata del cache (opcional — sin esto, el TTL de 60s corre)
jwtInvalidateDeviceCache($deviceId); // 1 arg — glob {deviceId}_*.dat, cubre todos los tenants
```

Si no se llama `jwtInvalidateDeviceCache()`, el device sigue pasando hasta que el cache de 60s expire. Para revocaciones urgentes (robo), invalidar el cache.

#### B — User-initiated: "Eliminar Punto de este dispositivo" (commit 70dbc22, 2026-06-06)

El propio usuario puede revocar su device desde el menú del POS. El handler `#reset` en `app/scripts/app.js` hace POST a `/API/logout` con timeout 5s y `withCredentials`. El callback `complete` corre `cleanupLocal` **siempre** (success o fail) — los devices offline también pueden "desinstalar" localmente.

`app/API/logout.php` (POST-only):
1. Decode JWT del cookie/header/POST.
2. Si tiene `did`+`cid` UUID válidos: `UPDATE device SET status=0, revokedAt=NOW(), revokedBy=<userId del propio JWT>` (doble guard tenant — el JWT está firmado pero defense-in-depth igualmente).
3. `jwtInvalidateDeviceCache($did)` → efecto inmediato.
4. Mata cookie `_jwt` (setcookie con expires=1970, mismos flags que `jwtSetCookie`).
5. Responde `{ok:true}` incluso sin token (no leakea estado).

`cleanupLocal` (ejecuta siempre, incluso ante fallo de red): `ncmStorage.nuke + localStorage.clear + sessionStorage.clear + barrer cookies JS-visibles + unregister SW + caches.delete + reload`.

**String canónico UI**: "Eliminar Punto de este dispositivo" (rebrand de "ENCOM" → "Punto", commit 70dbc22, aplica en `app/index.html` L2490, `app/index.php` L2515 y el alert title de `app.js`).

**Cache de validación**: archivo en `sys_get_temp_dir()/punto_device_status/{deviceId}_{companyId}.dat`. El `companyId` en el nombre del archivo es defense-in-depth (evita que un deviceId de otro tenant colisione). TTL 60s. Si el archivo no existe o expiró → SELECT a BD + regeneración del cache.

**Modo conservador**: si la BD no está disponible al validar, el middleware usa el cache previo si existe, o deja pasar el request (para no paralizar el POS ante un flap de BD). Ante BD caída, la revocación no surte efecto hasta que la BD vuelva.

**Backwards compat**: tokens JWT sin claim `did` (emitidos antes del feat a3fefb4) siguen pasando sin validar `device.status`. No hay breaking change para devices existentes logueados antes del feat.

**Deuda pendiente**:
- **UI panel del tenant**: pantalla para listar y revocar devices (ver `10-roadmap.md`). Diferida al ciclo de React del panel.
- **Migration runner**: `11_device.sql` se aplicó manualmente. La deuda del runner automático está en `06-infraestructura.md`.

---

## §30 — Workflow de commits: code-reviewer selectivo + context-updater al cierre (establecido commit ba385cb, 2026-06-07)

**Regla**: el agente `code-reviewer` se corre ANTES de commit **solo en commits de alto riesgo**. El agente `context-updater` se corre UNA SOLA VEZ al cierre de la sesión (vía `/end-session`), no por commit.

### §30.1 — Commits de alto riesgo (code-reviewer OBLIGATORIO)

| Categoría | Ejemplos |
|-----------|---------|
| Schema / migrations | Nuevas tablas, ALTER TABLE, índices |
| Auth / JWT | Emisión de tokens, middlewares de auth, claims |
| Admin realm | Cualquier cambio en `panel/API/v1/admin/`, `panel/lib/admin/`, `panel/bff/admin/` |
| Aislamiento multi-tenant | Queries cross-tenant, cambios en companyId scoping |
| Billing / pagos | Money path, transacciones financieras, cpayments |
| Hard-delete | DELETEs irreversibles en cascada |
| CORS / permisos | Cambios en allowlists, headers de seguridad |

### §30.2 — Commits que OMITEN el code-reviewer (skip explícito)

- UI / copy / estilos sin lógica de negocio
- Bug fix de 1 archivo sin lógica de negocio (ej: texto de label, color de botón)
- Comentarios, refactors de estilo, renombrado de variable local
- Commits con prefix `wip:`
- Actualizaciones de documentación (`docs:` prefix)

**Criterio práctico**: si el commit toca auth, BD, dinero o aislamiento de tenant → siempre reviewer. Si es visual o trivial → skip con justificación explícita en el commit message.

### §30.3 — context-updater al cierre, no por commit

Durante la sesión: tomar nota mentalmente de qué cambios califican como relevantes (ver tabla en CLAUDE.md § Mantenimiento del vault). Al cerrar con `/end-session`, el skill `end-session` consolida y corre context-updater UNA sola vez. Esto evita el overhead de corridas intermedias por cada commit y reduce el riesgo de actualizaciones parciales.

**Caso borde**: si la sesión cierra sin `/end-session`, al arrancar la próxima sesión correr `git log` desde el último entry del `_session-log.md` e invocar el context-updater manualmente si hay cambios relevantes no documentados.

**Fuente canónica**: CLAUDE.md § Workflow de Git, reglas 1 (code-reviewer selectivo) y 5 (context-updater al cierre).

---

## §21 — Manual de marca (identidad visual)

**Regla**: La identidad visual es un **manual de referencia** (`context/11-design-system.md`)
+ el **skill `brand-manual`** — **NO un CSS nuevo ni un framework**. Al construir/tocar UI,
**reutilizar las clases y colores que el proyecto ya usa** (Bootstrap 3 + `panel/css/app.css`):
`btn-info btn-rounded btn-lg text-u-c font-bold`, `form-control no-border no-bg b-b`, etc.
**Nunca** inventar estilos ad-hoc, paletas nuevas, ni rediseñar pantallas existentes.

**Por qué**: Un intento previo creó un CSS paralelo (`assets/design/*.css` con tokens `.ds-*`)
y re-skineó `/admin` — el usuario lo rechazó: el objetivo NO era rediseñar sino tener un
**manual de marca** que documente lo existente para mantener consistencia. Ese CSS se revirtió
(commit del revert); la fuente de verdad es el manual + el skill.

**Colores canónicos** (de `app.css`): primario `#545ca6`, success `#1ab667`, info/teal
`#4cb6cb` (CTA clásico), warning `#fad733`, danger `#f05050`, texto `#788188`, links `#545a5f`,
bg `#f7f7f7`, superficies dark `#232c32`/`#3b464d`/`#5a6a7a`, fuente Source Sans Pro 14px.

**Cómo aplicar**: (1) Antes de UI no trivial, leer `context/11-design-system.md` (el skill
`brand-manual` lo dispara). (2) Clonar el markup del componente legacy si existe. (3) Usar las
clases BS3/app.css del manual; si falta un patrón, **documentarlo en el manual**, no inline.
(4) Frontend nuevo = Bootstrap 3 + jQuery (§11).
