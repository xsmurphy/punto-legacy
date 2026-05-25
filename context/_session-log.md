<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-05-25 (contacts: front/back split completo — listado 3 roles + editform v2 — commit bae21fa)

- **Listado data-driven para los 3 roles**: `a_contacts.php` handler `generalTable`, el bloque `&format=json` ahora cubre user (10 cols), supplier (9 cols) y customer (19 cols, ya existente), todos bajo el mismo gate `$allow`. El path HTML legacy queda intacto como fallback.
- **`render.js` ampliado**: `renderUserRow` / `renderSupplierRow` + `table(contacts, rol)` con thead/tfoot por rol — todo escapado con `esc()`. `a_contacts.js` quitó el gate `_rol=='customer'`: los 3 roles usan `&format=json`.
- **`contactFormV2` (nuevo `scripts/contacts/form.js`)**: form Mustache hidratado desde `contactsApi.get(id)` (API v1), submit/archive vía `contactsApi.create/update/archive`, modo crear (`id=null`). Tabs: básico, dirección, nota. Templates en `panel/contacts/templates/`: `shell.html`, `header.html`, `basicTab.html`, `addressTab.html`, `notesTab.html`. Cableado desde `a_contacts.js`: editar/crear customer → `contactFormV2` con fallback `onError` al form legacy; `reloadList()` re-fetchea JSON y redibuja DataTable tras guardar.
- **ContactService**: round-trip de `address2` agregado en `mapToColumns` + `presentRow` (completaba el campo que faltaba en el commit dc9ce01).
- **Scope del v2**: cubre SOLO rol **customer** (API v1 type=1). user/supplier siguen con form legacy. Tabs "fichas/custom records" e "historial detallado" diferidos.
- **Pendiente conocido (NO bug nuevo)**: listado customer muestra note/address/city/location vacíos porque `ncmExecute(...,forceObj=true)` NO aplana JSONB en el loop de `generalTable`. El v1 API (get/presentRow) sí trae esos campos via `_flattenJsonb`. Pre-existente de dc9ce01.
- **Entorno local**: `$plansValues`/`PLAN` no definidos en ningún .php → gate `$allow` deja listados user/supplier vacíos. No es regresión — afecta legacy y JSON por igual.
- **Verificado E2E**: create/update vía v1 API persisten a data JSONB; get(id) hidrata el form; render.js produce columnas 1:1 con cada thead; templates Mustache compilan en modo crear/editar; Cliente Prueba SRL (id 019e6018-…) y supplier de prueba creados en company 0001.

## 2026-05-25 (contacts: listado rol customer data-driven — commit dc9ce01)

- **Front/back separados en el listado de clientes** (patrón Items): `a_contacts.php` handler `generalTable` ahora soporta `&format=json` para rol `customer` — emite un array de objetos JSON (reusando cómputos existentes: `lastTransaction`, `scoring`, `distance`, `color`, mapa de direcciones) en vez de concatenar `<tr>`. El camino legacy HTML (roles user/supplier + fallback) queda intacto.
- **`scripts/contacts/render.js` (nuevo)**: `window.contactsRender.table()` pinta thead+tbody+tfoot (19 cols) desde el JSON, espejo de `scripts/items/render.js`. Escapa todo con `esc()` (el path legacy NO escapaba → más seguro).
- **`a_contacts.js`**: rol customer hace `fetch &format=json` y usa `contactsRender` como `iniData` de `ncmDataTables`; user/supplier siguen legacy. Búsqueda confirmada client-side (`ncmDataTables.feedData` usa iniData sin re-fetch cuando se le pasa data).
- **Verificado E2E**: endpoint `format=json` → HTTP 200 shape correcto; `render.js` corrido en node sobre data real → tabla 19 cols bien formada; `a_contacts.php` acepta cookie `_jwt_panel`.
- **Pendiente (mismo patrón)**: portar roles **user/supplier** del listado + el **editform** (`scripts/contacts/form.js` + `panel/contacts/templates/`) como se hizo con Items.

## 2026-05-25 (items: JSONB demotion migración 07 + writers ncm + readers flatten — commit ea48a32)

- **Schema**: 4 columnas demotadas de `item` a `item.data` JSONB vía migración `07_item_jsonb_demote.sql` (atómica: backfill UPDATE + DROP). Columnas: `itemImage` (bool → JSON boolean), `itemTaxExcluded` (era columna fantasma: 0 lecturas/escrituras en todo el repo), `itemDiscount`, `itemUOM`. Criterio: 0 apariciones en WHERE/ORDER/JOIN/GROUP/SUM (auditado por grep). `itemPrice`/`itemCost` NO se movieron (usados en SUM/AVG). `itemName`/`itemSKU`/`itemSort`/`itemStatus`/`itemType` NO se movieron (indexados).
- **Whitelist actualizada**: 4 columnas quitadas de `_getTableSchema()['item']['columns']` en el mismo commit del DROP (regla: si se hace después, `_flattenJsonb` hace que la columna real gane sobre JSONB y se producen lecturas stale).
- **Writers migrados**: `a_items.php:2986` (bulkUpdate de hijos) de AutoExecute crudo → `ncmUpdate`. Seed de signup en `app/includes/functions.php` dejó de escribir `itemImage` (CRÍTICO: el `ncm` de `app/` no tiene `_routeToJsonb` — no rutea a JSONB; writers de app/ no pueden pasar columnas demotadas).
- **Readers arreglados**: (a) Explícitos con hard SQL error post-DROP: `a_items.php:23,67` (búsqueda), `:457` (compound), `a_bulk_production.php:108`, `ItemRepository::searchByName`, `get_items.php:116` → alias `data->>'col' AS col` o + columna `data`. (b) SELECT*/forceObj/raw-Execute sin flatten: `a_items.php:3752` (render lista), `inventory.php:51` (editform), loop principal de `get_items.php`, `app/fetch.php:584`, `app/fetchs.php:725` → ahora aplican `_flattenJsonb()`. Insight: readers via `ncmExecute` single-row (incl. `getItemData()`) YA aplanaban — no requirieron cambio.
- **Verificado E2E**: migración aplicada local (1 fila backfilleada, 4 columnas dropeadas); round-trip v1 PUT/GET confirmó itemUOM/itemDiscount/itemImage escritos a `data` y leídos vía flatten; `itemImage` devuelto como boolean PHP (no string 'true'); search y showTable del panel renderizan UOM desde data sin error SQL.
- **Bug legacy descubierto (fuera de scope, NO arreglado)**: `panel/API/get_items.php` devuelve 404 porque su query tiene `itemIsParent > 0` (boolean vs int → PG error) y `itemParentId = 0` (UUID vs int). Los cambios de flatten en ese endpoint son correctos pero inalcanzables. Mismo patrón de bugs legacy ya trackeado en invariante #7.

## 2026-05-25 (contacts: JSONB demotion migración 06 + writers ncm + PG fixes — commits 53c5dae, 01d6eba)

- **Schema**: 6 columnas descriptivas eliminadas de `contact` y movidas a `contact.data` JSONB (migración `06_contact_jsonb_demote.sql`, atómica: backfill UPDATE + DROP). Columnas demotadas: `contactNote`, `contactCity`, `contactLocation`, `contactCountry`, `contactAddress`, `contactAddress2`. Keys en camelCase consistente con convención existente.
- **Regla de diseño establecida**: solo van como columnas reales los campos indexables o calculados por SQL; todo lo descriptivo/estático va a `data` JSONB. Aplica a `contact` ahora y a `item` (diferido). Documentado como invariante #6 en `04-modelo-de-dominio.md`.
- **Writers migrados a ncm**: `add_customer.php`, `add_customers.php`, `edit_customer.php`, `edit_customers.php`, `a_contacts.php` — de `$db->AutoExecute` / bulk INSERT raw a `ncmInsert`/`ncmUpdate` (ADOdb AutoExecute hace hard-crash en columna inexistente; ncm rutea a JSONB automáticamente).
- **`customerAddress` registrada en `_getTableSchema()`**: su ausencia inyectaba una columna `id` espuria y fallaba silenciosamente en cada INSERT de dirección.
- **PG bugs corregidos**: (a) `generateUID()` devuelve INT — inválido para UUID PK; ahora `ncmInsert` genera UUID v7. (b) `customerAddressDefault = 1` crasheaba PG (`operator does not exist: boolean = integer`); corregido a `= true` en `ContactRepository` y `edit_customer.php`.
- **Pendientes — 5 sitios `= 1` legacy** (rutas `/app` sin verificar): `panel/includes/functions.php:3464,3790`, `app/action.php`, `app/load.php`, `app/fetch.php`, `app/fetchs.php`. Documentado en invariante #7 de `04-modelo-de-dominio.md`.
- **Pendiente — reader de descarga en `a_contacts.php`**: el CSV export lee columnas que ya no existen como columnas reales; necesita actualización para leer desde `data` JSONB.
- **Pendiente — JSONB demotion para `item`**: mismo patrón, diferido para próxima sesión.
- **Infra**: DDL (`ALTER TABLE DROP COLUMN`) requiere ser owner de la tabla; el usuario `punto` de la app no lo es. Documentado en `06-infraestructura.md §Privilegio de owner para DDL`.

## 2026-05-25 (backend-first módulo Contacts — commit e0d3fbd)

- **Hecho — backend Contacts implementado** (4 archivos, 696 inserciones): `panel/lib/contacts/ContactRepository.php` (SQL parametrizado sobre `contact` + `customerAddress`), `panel/lib/contacts/ContactService.php` (mapeo de API pública → columnas, validación, sync de dirección por defecto), `panel/API/v1/contacts.php` (REST GET/POST/PUT/DELETE + sub-recurso `?resource=addresses`), `panel/scripts/api/contacts.js` (`window.contactsApi`: list/get/create/update/archive/unarchive/bulkArchive + addresses.list).
- **Additive, no destructivo**: los endpoints legacy (`get_customers.php`, `get_customer.php`, `add_customer.php`, `edit_customer.php`, `delete_customers.php`, `get_customer_addresses.php`) NO se tocaron; quedan como fallback.
- **Bugs corregidos en el código nuevo** (no afectan legacy): `ncmUpdate` devuelve `['error'=>false,...]` en éxito, nunca bare `false` → el repo verifica `is_array($ok) && empty($ok['error'])`; `ci` ahora se escribe en `contactCI` (legacy `edit_customer.php` lo escribía erróneamente en `contactTIN`); UUIDs siempre bound como param, nunca concatenados (legacy `get_customers.php` concatenaba `COMPANY_ID` sin comillas).
- **Diferido para follow-up**: custom records (`customerRecord`/`cRecordField`/`cRecordValue`), matriz de roles/permisos, y CSV import — todavía solo en `panel/a_contacts.php` (3.787 líneas).
- **Próximo paso**: cablear `a_contacts.php` (listado + form) para consumir `contactsApi`; luego abordar los sub-dominios diferidos. Contacts es el 2º CRUD pesado del molde backend-first confirmado.

## 2026-05-24 (refactor completo módulo Items + estrategia de modernización)

- **Hecho — módulo Items refactorizado punta a punta** (commits `d4e5a49`..`886abcd`): Fase 0 (dedup ~8K líneas, fix SQLi, `itemImage`→bool) · Fase 1 (extracción de dominio: `ItemRepository` + `ItemService`/`Compound`/`Stock`/`Upsell`/`Location` en `lib/items/`) · Fase 1D multi-depósito (tabla `itemLocation` + `LocationService` + `resolveItemLocation()` en venta/producción) · Fase 2 (API REST `/API/v1/items/*` con envelope `apiOk`, `apiMiddleware` ahora acepta sesión PHP) · Fase 4 (listado **data-driven** backend→JSON→render JS, y **editform-v2** reconstruido con templates Mustache: shell + 6 tabs + 3 shells por tipo, cableado al click/crear con fallback al legacy, **guardando OK**).
- **Decisión — frontend**: se probó React+shadcn (scaffold `69cb299`) y se **revirtió** (`8b2563b`). Stack se queda jQuery+BS3+CSS. El editform-v2 usa Mustache + hidratación JSON (`scripts/items/form.js` + `panel/items/templates/`).
- **Decisión estratégica (`08ed731`) — modernización del monolito**: con 48 módulos/~45K líneas, modernizar todo como Items tomaría meses. Rumbo aprobado: **(1) backend primero en TODOS los módulos** (Services+API = el desacople de mayor valor), **(2) frontend = vista PHP pura por defecto**, **(3) Alpine.js (no Mustache) solo donde la UX lo amerite**. Molde backend replicable + priorización por tipo documentados en `02-arquitectura.md § Estrategia de modernización`.
- **Pendiente**: aplicar el molde a **Contacts** (2º CRUD más grande, 3.787 líneas; ya tiene endpoints sueltos `get_customers`/`edit_customer` para consolidar). Luego reportes (backend→API + listado data-driven) y POS (`app/action.php`, análisis aparte). Recomendado arrancar Contacts en sesión fresca.
- **Atención**: el editform PHP legacy de items sigue como **fallback** (no se eliminó) hasta validar el v2 en uso real. `productionTab` portado pero NO verificado (módulo `production` deshabilitado en la company de prueba). Bugs PG recurrentes en otros módulos: `id > 0`/`= 0` sobre UUID, `db_prepare(dec())` en WHERE, máscaras con separador de miles en columnas INTEGER (`itemSort`).

## 2026-05-19 (martes, smoke test E2E del refactor)

- **Decisión clave**: antes de arrancar Phase AI hay que validar que la modernización (PG, JWT, screens, no-ADOdb) funciona end-to-end. El usuario lo planteó: "no sabemos ni si la refactorización funciona". Phase AI quedó **pospuesto** hasta cerrar el smoke test.
- **Hallazgo crítico**: hay **2 postgres conviviendo en localhost:5432**. Uno del host (xstian, corriendo desde 15-abr) y otro de docker. PHP tomaba el del host (con seed completo: 5 outlets, 2 registers, 3 companies). Decisión del usuario: **usar el host postgres, detener el de docker** (`docker compose stop postgres pgadmin`). Stack actual = host PG + docker (redis + ws-server).
- **6 smoke tests ejecutados, 6 bugs reales encontrados y arreglados** (commit `c485eae`):
  1. `app/API/auth.php` — falta `rtrim()` en password compare (CHAR(68) padded). Login `/app` daba 401 silencioso
  2. `app/API/auth.php` — resolución de outlet usaba `ORDER BY outletId ASC LIMIT 1` ignorando `contact.outletId`. Si el primer outlet no tenía register → 500. Fix: respetar `contact.outletId` (mismo patrón que `loginPart()` del panel)
  3. `app/includes/functions.php` — `ncmExecute()` del /app **no aplicaba** `_flattenJsonb()`. Por eso `SELECT * FROM company` devolvía `settingTimeZone = NULL` (vive en `config` JSONB tras Phase PG). `data.php:52` crasheaba. Fix: agregar `_flattenJsonb` copia del panel + invocarla en ncmExecute
  4. `app/includes/functions.php::getCustomTemplates()` — SQL injection (concat), comparación legacy `companyId = 1` (ahora UUID), y while sin nil check. Crasheaba con `MoveNext on false` cuando `taxonomy` vacía. Fix: parametrizar + `IS NULL` para templates globales + guard
  5. `panel/API/kds.php` y `panel/API/cds.php` — llamaban `validateHttp('s')` ANTES de `apiMiddlewarePublic()` (que es donde se carga `functions.php`). Fatal "Call to undefined function". Fix: leer raw `$_GET['s']`
- **WebSocket bridge E2E confirmado**: `wsPublish()` desde PHP → Redis pub → ws-server → cliente recibe `{event, channel, data}` con payload correcto. Protocolo: cliente envía `{action: subscribe, channel: ...}`, server responde con `{event: ...}`.
- **Estado final**: login panel ✅, login app ✅, fetch settings ✅, KDS/CDS HTML + API ✅, WS ✅. **La base del refactor funciona**.
- **Pendiente próxima sesión**:
  - Notar que `fetch.php` devuelve `outlets:[], registers:[], users:[]` vacíos para admin@local.test → puede ser filtro `outletStatus = 1` (verificar) o un bug en queries específicas. NO crítico para el agente pero hay que entenderlo
  - Cuando tengamos confianza de que la base es estable: arrancar Phase AI.1 (el design doc ya está en mi memoria, falta volcarlo a `punto-agent/README.md` cuando arranquemos)
  - Migración endpoints legacy MySQL (B2-B5) sigue pendiente

---

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
