<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-05-16 (sábado, micro-sesión: flujo commit+push con agentes)

- Introducida **REGLA OBLIGATORIA #3** en `CLAUDE.md`: flujo `edit → code-reviewer → commit → context-updater → push` (push inmediato). Motivación: sesiones previas acumulaban 10+ commits sin push y sin reviewer; el kit tenía los agentes pero no se invocaban
- Hook `PreToolUse:Bash` agregado en `.claude/settings.json` que detecta `git commit` y `git push` (regex anclada al inicio del comando para evitar falsos positivos en greps/edits) y emite recordatorio del flujo
- `.claude/agents/code-reviewer.md` ahora acepta 3 modos de diff: working tree, staged (`--cached`), o post-commit (`HEAD~1`)
- Commit `66284cb` (`feat(workflow): regla obligatoria flujo commit+push con agentes`) — reviewed por `code-reviewer` (2 passes, limpio)
- `context/08-convenciones.md` actualizado: §13 nuevo con detalle completo del flujo, §2 ahora apunta a §13
- **Para próxima sesión**: si aparece el recordatorio del hook al commitear o pushear, correr el agente antes de seguir. La regla NO es opcional excepto para commits `wip:` marcados explícitamente

---

## 2026-05-16 (viernes, bootstrap meta-estructura + graphify)

- Creado kit completo de contexto: CLAUDE.md + /context/ (12 docs) + .claude/agents/ (6 agentes) — commit `1a1acb2`
- Decisiones tomadas: idioma español, nombre graphify `punto-pos`, convenciones base aprobadas
- Graphify ya estaba instalado en el repo principal (`/Users/xstian/Dropbox/Punto/system/.venv` + `graphify-out/` con grafo enriquecido por LLM). Inicialmente dupliqué 240MB en este worktree — corregido: `.venv` del worktree ahora es symlink al del repo principal
- `graphify-out/` queda local en cada worktree (solo AST) para no pisar el grafo bueno del repo principal cuando se regenera en una rama
- God nodes medidos (actualizado `02-arquitectura.md`): `ncmExecute()` (124 edges) lidera, seguido de `make_xlsx_lib()`, `validity()`, `iftn()`, `toUTF8()` — coinciden entre worktree y repo principal, confirma que son god nodes estables
- Insight: hay cross-coupling fuerte entre `app/includes/functions.php` y `panel/includes/functions.php` — no son módulos independientes
- TO-CONFIRMs de convenciones resueltos:
  - Envelope canónico: migrar TODOS los endpoints progresivamente (Phase 2.A confirmada ALTA)
  - Estilo PHP: legacy en archivos existentes, PSR-12 en archivos nuevos
  - Frontend: jQuery por ahora, decisión post Phase 2 + AI.1
  - SQL legacy: auditoría + batch P0 (item nuevo agregado al roadmap como prioridad ALTA)
- `code-reviewer` actualizado: SQL injection ahora es P0 estricto (no solo con input de usuario)
- SQL Audit ejecutado (Batch 0 lectura, sin tocar código): el riesgo SQL resultó ser MÍNIMO (5 dead code + 7 mitigados + 2 a parametrizar). Pero la auditoría destapó **3 hallazgos más graves**:
  - 🚨 **P0 secrets leak**: 19 archivos con credenciales MySQL hardcoded (`incomepo_905user`/`incomepo_manager`). Apuntan a BDs que ya no existen post Phase PG, pero las credenciales están en el repo Git e historial. Son endpoints API pública (`validateAPIAccess` con api_key) — no llamados internamente pero potencialmente accesibles desde internet
  - 🟡 **IDOR potencial** en `panel/screens/scheduleConfirm.php:6`: `COMPANY_ID` se define desde URL base64 sin verificar JWT. Rompe regla §1 (aislamiento tenant)
  - 🐛 **Query rota** en `app/includes/functions.php:4568`: SQL tiene 2 placeholders pero pasa 3 valores. Bug funcional, no SQL injection
- **Decisión del usuario sobre los 19 archivos: NO BORRAR.** Son endpoints VIVOS. Acción correcta: actualizar la referencia de BD (MySQL legacy → PostgreSQL via .env). El comportamiento debe preservarse, solo se cambia la capa de conexión + se sacan las credenciales hardcoded
- Auditoría de configuración completa (2 Explore agents en paralelo). Resultado: 5 EASY / 9 MEDIUM / 5 HARD. Plan completo en `10-roadmap.md` "Migración endpoints legacy MySQL → PostgreSQL"
- 3 bugs preexistentes descubiertos durante la auditoría (no SQL injection):
  - `delete_inventory.php:69` llama función inexistente `createInventory()` → endpoint nunca ejecuta DELETE
  - `delete_items.php` función mal nombrada `editItem()` (debería ser `deleteItem()`)
  - `add_inventory_test.php:42` `outletId = 2446` hardcodeado
- Lista completa de archivos con credenciales hardcoded (a remediar, NO a borrar):
  - `panel/includes/dbcreator.php`, `dbcopier.php` (admin user `incomepo_manager`)
  - `panel/API/`: `add_items.php`, `add_items_test.php`, `add_inventory.php`, `add_inventory_test.php`, `add_customers_test.php`, `edit_items.php`, `edit_inventory.php`, `edit_customers_test.php`, `delete_items.php`, `delete_inventory.php`, `get_inventory.php`, `get_payment_methods.php`, `get_check_issuing.php`
  - `panel/crons/cronTrialAboutToExpire.php`, `cronCreateInvoices.php`
  - `app/tin.php`, `app/rucs.php` (BD `incomepo_rucpy`)
- `panel/API/get_tin.php` (endpoint VIVO con apiMiddleware) tiene 3 líneas que referencian la BD MySQL muerta: línea 39 (`$urlNcm` var muerta), 55-57 (`selectDb('ruc_py')` + query a tabla `rucs`). Estas también necesitan migrarse a PG, no borrarse
- **MODERNIZATION.md consolidado a `10-roadmap.md`**: tener dos fuentes de verdad causó mi desvío de plan a mitad de sesión. Ahora hay una sola fuente dentro del kit. MODERNIZATION.md queda como puntero corto (preserva URL).
- **B1 ejecutado**: creado helper `panel/API/lib/legacy_db.php` que reemplaza el bloque MySQL hardcoded por bootstrap a PG (via `includes/db.php`) + carga config/functions + define `enc/dec` defensivamente. Lint OK
- **B2a ejecutado**: 2 endpoints migrados como prueba de concepto:
  - `panel/API/get_payment_methods.php` (commit `8d31dc4`)
  - `panel/API/get_check_issuing.php` (commit `8d31dc4`)
- **Patrón validado**: bloque 9 líneas → 1 línea (require helper), eliminar stub enc/dec local, parametrizar concat de UUIDs en queries (`companyId = ".COMPANY_ID` → `companyId = ?` en array)
- **Decisión sobre roadmap**: el orden de ejecución actual prioriza la migración MySQL→PG (emergente) ANTES que Phase 2.A. Documentado en `10-roadmap.md` (que ahora es la fuente única — MODERNIZATION.md fue consolidado acá y queda como puntero)
- **Pendientes próxima sesión** (en este orden):
  - B2b: 3 endpoints "MEDIUM disfrazados de EASY" — `edit_inventory.php` (AutoExecute UPDATE con WHERE concat), `edit_customers_test.php` (idem), `add_customers_test.php` (cambiar `generateUID($i)` por `generateUuidV7()`)
  - B3: 5 endpoints MEDIUM con crons + APIs (`add_items[_test]`, `add_inventory[_test]`, `edit_items`, `cronTrialAboutToExpire`)
  - B4: 5 endpoints HARD con bugs reales (`delete_items` función mal nombrada, `delete_inventory` llama función inexistente, `cronCreateInvoices` con die(), `get_inventory` con die())
  - B5: decisión separada para `tin.php`/`rucs.php` (recrear `incomepo_rucpy` en PG o descontinuar fallback), `dbcreator.php`/`dbcopier.php` (deprecar o reescribir)
  - Después de B2-B5: arrancar Phase 2.A (envelope canónico, 54 endpoints) — ver `10-roadmap.md` sección "Phase 2.A"
  - Revisar hallazgos separados: IDOR en `scheduleConfirm.php`, query rota en `app/includes/functions.php:4568`
- **Estado para push/merge**: 8 commits sin pushear. Branch `claude/keen-wilson-f801e3`. Listo para merge a `main` para que el kit esté disponible globalmente
