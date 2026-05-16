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
