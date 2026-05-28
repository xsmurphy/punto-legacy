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

**Regla actual**: Bootstrap 3 + jQuery. No agregar otro framework en runtime sin decisión explícita.

**Observaciones**:
- No hay build step para JS del frontend (solo concat + minify)
- Los `a_*.php` del panel mezclan HTML + PHP + JS inline
- Se está migrando a separar: data vía API, presentación en template

**Decisión sobre framework moderno**: pendiente. No decidir hasta que:
1. Phase 2 esté completa (API limpia, predecible)
2. Phase AI.1 haya validado el widget conversacional (si el agente termina siendo
   la interfaz principal, puede reducir la urgencia de modernizar el panel)

**Mientras tanto**: cambios en frontend siguen el estilo de Bootstrap 3 + jQuery del
archivo que se modifica. No introducir Vue/React/Svelte/HTMX en commits aislados.

---

## §12 — Seguridad

**Reglas activas**:
- CORS: allowlist explícita (no `*`)
- Headers: X-Content-Type-Options, X-Frame-Options
- Debug: gateado por `APP_DEBUG=true`
- JWT: HttpOnly cookies, no localStorage
- SQL: queries parametrizadas siempre. Concatenación directa = bug de seguridad.

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

**Regla**: Toda corrección o mejora pasa por el flujo `edit → code-reviewer → commit → context-updater → push`. El push es inmediato, no se acumulan commits locales.

**Por qué**: el kit incluye agentes especialistas (`code-reviewer`, `context-updater`) precisamente para que cada cambio sea auditado y para mantener `/context/` sincronizado con el código. En sesiones previas se acumularon 10+ commits sin push y sin reviewer; esta regla cierra ese gap.

**Diagrama**:

```
edit/escribir código
   ↓
Agent(subagent_type="code-reviewer")     ← ANTES de commit
   ↓ (si P0/P1 OK)
git commit
   ↓
Agent(subagent_type="context-updater")   ← ANTES de push (si el cambio amerita update de /context/)
   ↓
git push                                  ← INMEDIATAMENTE después del commit
   ↓
(opcional) gh pr create                   ← si es PR-worthy
```

**Reglas no negociables**:

1. **NO acumular commits sin pushear.** Cada commit lógico se pushea inmediatamente.
2. **NO commitear sin `code-reviewer`.** El agente devuelve P0/P1/P2. Si hay P0, parar y arreglar. Si hay P1, justificar.
3. **NO pushear sin verificar context.** Si el cambio amerita update de `/context/` (ver §1-§12 + REGLA #2 en CLAUDE.md), correr `context-updater` antes del push.
4. **Excepción única**: commits de WIP marcados explícitamente (`wip:` prefix). Pueden saltarse el reviewer pero NO el push.

**Refuerzo automático**: `.claude/settings.json` tiene un hook `PreToolUse:Bash` que detecta `git commit` y `git push` (regex anclada al inicio del comando, no matchea greps/edits) y emite un recordatorio. Si aparece el recordatorio y no corriste el agente, parar y correrlo antes de seguir.

**Sobre `code-reviewer`**: acepta 3 modos de diff según contexto — working tree (`git diff`), staged (`git diff --cached`), o post-commit (`git diff HEAD~1`). Por defecto revisa lo que esté pendiente; en post-commit (este flujo lo invoca después de `git commit`) usa `HEAD~1`.

**Fuente canónica corta**: REGLA OBLIGATORIA #3 de `CLAUDE.md`. Este §13 es la versión detallada.

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

### §22.3 — Fixes PG obligatorios en cada slice /app

El legacy de /app tenía bugs latentes generalizados que DEBEN corregirse al migrar cada concern:

| Bug legacy | Fix correcto |
|-----------|-------------|
| UUID interpolado sin comillas en WHERE (`WHERE id = $uuid`) | Bound param: `WHERE id = ?`, bind `[$uuid]` |
| `DELETE ... LIMIT 1` | `DELETE ... WHERE id = ? AND companyId = ?` (sin LIMIT, con scope) |
| Booleano comparado con `1` (`WHERE flag = 1`) | `WHERE flag = true` |
| Booleano seteado con `1` (INSERT/UPDATE `flag = 1`) | `flag = true` (o `null` para default) |
| Escritura sin scope de `companyId` | Agregar `AND companyId = ?` en UPDATE/DELETE siempre |

### §22.4 — Bootstrap del contexto POS en la API de /app

Los endpoints `app/API/v1/` no tienen el contexto global que carga `head.php`/`data.php` en el flujo legacy. El workaround adoptado en slice 1: hacer `chdir(__DIR__ . '/../../..')` y luego `require_once 'head.php'` + `require_once 'data.php'` para bootstrapear el contexto POS (constantes, DB, companyId del JWT) antes de llamar al service.

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
