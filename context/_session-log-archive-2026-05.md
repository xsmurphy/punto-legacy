<!-- Archivo de sesiones de 2026-05 (movidas desde _session-log.md al superar el cap de 200 líneas). -->

# Bitácora de Sesiones — Archivo 2026-05

## 2026-05-26 (arquitectura: modelo BFF canónico + editform de contacts por el BFF — commits 8bacb5a, cd736f2)

- **Course-correction del usuario**: el roadmap real es **HTML+JS → PHP (BFF) → API → BD**, donde la **API es un motor ERP genérico/raw** reusable por otras apps (ecommerce, billetera) y el **BFF (PHP)** procesa para la App Punto (push, WS, cálculos, cross-analysis, formateo). El front solo pinta. **Constraint clave: App y API irán a servidores separados** → el BFF nunca toca BD/`lib/` directo; pide todo a la API; la API expone datasets crudos y el BFF los cruza.
- **Desvío identificado y documentado** (`02-arquitectura.md` reescrito): el modelo previo (4 capas, front→API directo) hizo que el editform pegara a `/API/v1` y que la API devolviera data formateada (`presentRow`) — acoplándola a Punto. Items y Contacts quedaron con ese desvío.
- **Fix aplicado a Contacts (editform)**: el front (`form.js`/`contactFormV2`) ahora habla SOLO con el **BFF** (`a_contacts.php?action=getContact/saveContact/archiveContact&format=json`), que usa `ContactService` **in-process**. Verificado en browser (GET/POST al BFF 200, ninguno toca `/API/v1`). El listado ya era BFF (`generalTable&format=json`).
- **Decisión de secuenciamiento**: el **boundary HTTP real** (cliente `PuntoApi`, adelgazar `/API/v1` a raw, separación de servidores) se **difiere** a una fase explícita futura. Por ahora el BFF usa `lib/` in-process. Prioridad: ir **a lo ancho** (extraer front/back en más módulos) antes que profundizar.
- **Pendientes**: PuntoApi + adelgazar API (fase boundary HTTP); editform v2 para user/supplier; replicar el split front/back en otros módulos (reportes read-only = bajo esfuerzo; purchase = CRUD pesado).

## 2026-05-26 (reportes: piloto a_report_summary + estrategia del módulo — commit d6bcfef)

- **Hecho**: piloto del módulo Reportes — split front/back en `a_report_summary`. Se extrajo el `<script>` inline (~487 líneas) a `scripts/a_report_summary.js` (IIFE); las 7 vars que se inyectaban con tags PHP (startDate/endDate/baseUrl/TAX_NAME/CURRENCY/offset/limit) pasan por `window.reportSummary` en un shell chico. El back (`action=getSales/getTypeSales/getGiftcards/getChartSales/topHours/salesListByDay`) ya devolvía JSON y quedó intacto. `a_report_summary.php` 1376 → 900 líneas. Verificado en browser (date-picker, chart, KPIs; cero errores de consola).
- **Patrón establecido (repetible para reportes)**: si el back ya devuelve JSON → extraer JS inline a `scripts/<reporte>.js` + shell `window.<reporte>` con las vars PHP. El `.php` queda como back (handlers `action=`) + shell.
- **Hallazgo (corrige supuesto del roadmap)**: "Reportes = read-only fácil" es solo parcial. Los 5 grandes (`a_report_transactions`/`purchases`/`products`/`production`) tienen **escrituras enterradas** (update/delete/insert) → su split es tipo CRUD (front→BFF lectura *y* escritura), no el fácil. Los genuinamente limpios: `summary`✓, `p_methods`, `inventory`.
- **Pendiente**: replicar el patrón a `a_report_p_methods`/`a_report_inventory` y demás chicos; **fix `a_report_customers`** (lee columnas de contact ya degradadas a JSONB → probablemente roto); los reportes grandes con escritura = fase aparte. Siguen diferidos: `PuntoApi` + adelgazar `/API/v1` (fase servidores separados).

## 2026-05-26 (course-correction arquitectura + piloto BFF de 3 capas en reportes — commits 5cb9912..24ccbd8)

- **Decisión grande (course-correction del usuario, NORTE de TODO el sistema)**: la estructura canónica es **front.html (estático, HTML+JS, CERO PHP) → bff.php (PHP, NO toca BD, solo llama a la API por HTTP) → api.php (PHP + Postgres, única capa con queries)**. **PHP NUNCA sirve HTML**; auth, chrome (menú/título/currency) y **formateo** se resuelven en el front (JS). Esto **revierte** el diferimiento del boundary HTTP de la sesión previa (BFF in-process). Lockeado en `02-arquitectura.md` (REGLA RAÍZ) + `10-roadmap.md` (Phase 3 reescrita). Commit `f8922b1`.
- **Hecho — piloto backend de `a_report_summary` (slices 1+2, verificado E2E)**: **API** `API/v1/reports/sales.php` + `lib/reports/ReportSalesService` devuelve datos CRUDOS (sin formatear); **BFF** `bff/reports/summary.php` + `bff/bootstrap.php` + cliente HTTP `bff/lib/api_client.php` compone KPIs llamando a la API por HTTP (forward del JWT), **sin tocar BD**. Cadena front→BFF→API→Postgres confirmada en browser (config PYG/Demo Company, KPIs, auth 401→login). Commits `7fdb97f`, `64787c4`.
- **Corrección clave (regla durable, guardada en memoria)**: mi primer front estático inventó un **diseño nuevo** → RECHAZADO. El front DEBE usar **exactamente el HTML/diseño actual** del reporte (BS3, sus cards/charts/tabs/tablas); el refactor cambia solo la plomería de datos. Los archivos del diseño nuevo se eliminaron (no commiteados).
- **Infra/atención**: el push HTTPS se rompió a mitad de sesión (credencial osxkeychain dejó de resolverse en shell no-interactivo). **Solución permanente: remote cambiado a SSH** (`git@github.com:xsmurphy/punto-legacy.git`) — push automático OK de acá en más.

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

## 2026-05-27 (cont.) — reportes BFF: expenses + drawers + products (1er pesado) — commits 9b55d70..9da9f2a

- **3 reportes migrados al modelo BFF de 3 capas** (13º–15º): `expenses` (Movimientos de Caja, 9b55d70 — 3er WRITE: edit+delete), `drawers` (Cierres de Caja, 27b6452 — 4º WRITE: cerrar/corregir/eliminar + detalle por id), `products` (Reporte de Artículos, f7ff2de — **1er PESADO**, ~1750 líneas, 3 tabs general/detail/combos + KPIs + chart). Todos verificados E2E (data vía curl + render en browser) con `code-reviewer` P0/P1 limpio.
- **Decisión arquitectónica (convención §15)**: en reportes pesados/financieros, los números exactos (utilidad por fila + agregados) se computan en el **Service** (motor ERP = fuente única), el BFF solo agrega KPIs/chart, el front formatea.
- **Convenciones nuevas**: §14 (`PANEL_AUTHED_USER` no `USER_ID` en contexto API; `ncmExecute` single-row devuelve `CaseInsensitiveArray` → nunca `is_array()`, usar truthy+`['col']??`), §16 (self-heal write durante un GET → eliminar, nunca portar).
- **Endurecimientos de seguridad** (drawers): detalle re-consultado por id (no blob base64 del cliente), `delete` con scope companyId (era IDOR + LIMIT inválido en PG), sumas de expenses por companyId.
- **Diferidos con wrinkle**: `cashflow` (semántica financiera MySQL `itemId=0`), `open_invoices` (WRITE en read + dep purchases), `vpayments` (gateway externo Bancard/Dinelco, no verificable en dev).

## 2026-05-27 (Phase 3.5 reportes BFF + fix main.php + familia god-functions PG cerrada — commits 676fe6a..e3f644d)

- **Reportes migrados al BFF de 3 capas (Phase 3.5 → 12 reportes + 1 alias)**: `stock`, `recurring` (2º WRITE), `summary_year`, `customers` núcleo (extras diferidos a Phase AI). `by_brands` = alias de `brands`.
- **Fix `main.php`**: banner T&C eliminado; listado de empresas reparado (permisos encom en demo). `main.php` usa SESIÓN PHP legacy para `COMPANY_ID` — refuerza ADR-001.
- **Familia de god-functions PG CERRADA (100%)**: `getCustomerData`/`getContactData`/`getAllItems`/`getAllItemsRaw`/`getAllContacts`/`getAllContactsRaw`/`lessInternalTotals` — todos con bound params + `_flattenJsonb` para columnas demotadas a JSONB.
- **Verificación**: todos los reportes E2E en browser; `code-reviewer` P0/P1 limpio en cada commit. Roadmap + 04-modelo + graphify sincronizados.

## 2026-05-29 — Slices 21-28: tablesJson / docsNum / customerHasOrders / ordersList / quotesList+savedList migrados a BFF/API/Services (commits dd1dee1, 3d222b9, 3fd615b, cdd7483, 57b9bcf)

- **Slice 21 (tablesJson)**: `TableService::listTables()` + `api/v1/tables.php` GET sin acción + `app/bff/tables.php` handler no-action. 4 bugs PG del legacy corregidos (VARCHAR/int, UUID intval, god-function, sin companyId scope).
- **Slice 22 (docsNum)**: `RegisterService::docNumbers()` — 7 contadores de doc por registro. Bug PG corregido: `companyId` UUID sin comillas en WHERE → bindeado. Handler muerto `chkInvoiceNo` eliminado.
- **Slice 23 (customerHasOrders)**: `OrderService::customerHasOpenOrders(): bool` — type 12 status!=4, multi-tenant. Patrón HTTP-401-as-signal → booleano limpio.
- **Slice 27 (ordersList)**: `OrderService::getTableClose/getTableDetail/getList/queryOrderRows`. SQL injection cerrada (cuid/COMPANY_ID/fechas concatenados → params). Meta JSONB leído correctamente.
- **Slice 28 (quotesList+savedList)**: `TransactionService::getTransactionList(listType=quotes|saved)`. `_bffListMap` en globalv2.js + debug.js reemplaza el condicional simple de slice 27.

## 2026-05-27 (cont. 2) — `purchases` migrado (16º): 1ª MIGRACIÓN PARCIAL (3 lecturas al BFF, CRUD+fiscales legacy)

- **`a_report_purchases` (Compras y Gastos, ~2632 líneas) migrado al BFF — pero PARCIAL.** Es un CRUD pesado + 2 fiscales, no un reporte limpio. Se migraron al BFF de 3 capas SOLO las **3 vistas de lectura**: `general` (cabeceras tipo 1,4 + proveedor/usuario/medios de pago resueltos + **deuda** batch), `cobros` (pagos tipo 5 + comprobante padre tipo 4), `detail` (transaction⋈itemSold + costo unitario + búsqueda `src`). El CRUD de edición (`edit`/`update`/`paymentForm`/`addPayment`/`delete`) y los fiscales (`rg90`, `libro-compra`) **quedan en el PHP legacy** `a_report_purchases.php`.
- **Patrón NUEVO — migración parcial vía router** (reutilizable para transactions/giftCards): `panel/router.php` sirve el front estático cuando NO hay `?action=`, y cae al PHP legacy cuando SÍ lo hay. El front (`scripts/a_report_purchases.js`) recablea las 3 lecturas al BFF y carga los writes/fiscales en los modales del shell (`#modalXLarge`/`#modalTiny`) o ventanas nuevas vía `/a_report_purchases?action=…`. En prod: `RewriteCond %{QUERY_STRING} !(^|&)action=`.
- **Hallazgos** (en `10-roadmap.md` §3.5): (1) `transaction.transactionDetails` absorbido a `meta` JSONB → leer con `meta->>'transactionDetails'` + json_decode. (2) BOOLEAN PG `transactionComplete`: ADOdb devuelve 1/0 (cast int OK) pero se blindó con helper `isComplete()` robusto al driver ('t'/'f'/true). (3) `country` agregado al bootstrap (`config->>'settingCountry'`) para gatear fiscales PY. (4) Fixes PG: typo `transagction`, `AND transactionDate` colgante en cobros/supId, deuda con un solo `SUM ... GROUP BY` (legacy N+1).
- **Verificado E2E**: API+BFF de las 3 vistas vía curl con JWT minteado (company 0001); seed de demo (1 compra a crédito con 2 líneas + 1 pago, tag `meta.seed=bff-purchases-verify`, outlet ...002); render en browser vía harness → 3 tabs OK, badge Crédito/Contado, deuda 150000 + botón de pago, total al pie PYG 320.000, RG90/Libro Compra (PY), cero errores de consola.

## 2026-05-27 (cont. 3) — `transactions` migrado (17º, EL MÁS GRANDE ~3987 líneas): 2ª migración parcial

- **`a_report_transactions` (Pagos y Transacciones) migrado al BFF — parcial.** 3 vistas de lectura de BD: `detail` (ventas tipos 0,3,6,7,8 + deuda + tags + totales), `cobros` (pagos tipo 5 + comprobante padre), `quotes` (cotizaciones tipo 9 + estado). `feTable` NO migrada (gateway FE externa). CRUD/export/fiscales quedan legacy. Router: `transactions` sumado al mapa `$bffPartialReports`.
- **TRAP**: `array_map(fn($r)=>$r['col'], $res)` sobre filas getAssoc de `ncmExecute` (CaseInsensitiveArray) lee mal algunas columnas cuando el SELECT tiene computadas (`meta->>'x' AS x`). Solución: recolectar ids con `foreach`, no `array_map`.
- **Otros hallazgos**: `transaction.tags` en `meta` JSONB; `register.registerReturnPrefix` en `data` JSONB de la caja; datos de caja N+1 → `registerInfo()` batch.
- **Verificado E2E**: seed ventas en company 0001; 3 vistas BFF correctas; render de 4 tabs, badges con color, RG90/Libro Ventas; cero errores de consola.

## 2026-05-27 (cont. 4) — `giftcards` migrado (18º, mediano): 1 lectura al BFF + KPIs

- **`a_report_giftcards` (Gift Cards, ~531 líneas) migrado al BFF — parcial.** 1 vista de lectura (`detail` = giftCardSold activadas) + 4 KPIs (vencidas/por-vencer/canjeadas/vigentes + valor vigente). Form de edición y writes quedan legacy vía `?action=`.
- **Verificado E2E**: seed de 2 gift cards (1 vigente 150k / 1 vencida saldo 0) en company 0001; render correcto (4 KPIs, íconos coloreados, código único en badges, TOTALES); cero errores de consola.
- **Pendiente — medianos restantes**: `schedule` (~907 líneas), `production` (~1068, módulo deshabilitado en la company de prueba); diferidos con wrinkle: `cashflow`, `open_invoices`, `vpayments`.

## 2026-05-27 (cont. 5) — `schedule` migrado (19º, mediano): 3 lecturas al BFF + donut/KPIs

- **`a_report_schedule` (Agendamientos, ~907 líneas) migrado al BFF — parcial.** 3 vistas de lectura: `detail` (citas tipo 13 + summary por estado + donut), `stats` (conteos por contacto, usuarios/clientes), `sessions` (paquetes con itemSessions). El modal de sesiones y el write (`delete`) quedan legacy vía `?action=`. Router: `schedule` en `$bffPartialReports`.
- **Hallazgo CRÍTICO:** `ncmExecute(getAssoc=true)` keyea por la 1ª columna → en agregado GROUP BY (contacto repetido) cada fila sobrescribe → se pierde data. Fix: iterar con `forceObj=true` cuando la 1ª columna se repite.
- **Otros hallazgos**: `contactInCalendar` e `itemSessions` demovidos a `data` JSONB; `getTotalScheduleByStatus()` interpola contactId sin comillas (PG) + N+1 → reemplazado por agregados parametrizados.
- **Verificado E2E**: seed de 5 citas (estados 0/6/6/4/5) + paquete de sesiones en company 0001; render correcto; cero errores de consola. `code-reviewer`: sin P0/P1, 2 P2 corregidos.

## 2026-05-27 (cont. 6) — `production` migrado (20º, mediano): 3 lecturas al BFF (módulo deshabilitado → data-layer verificado)

- **`a_report_production` (Producción, ~1068 líneas) migrado al BFF — parcial.** 3 vistas: `general`, `detail`, `compound`. recipe/export/delete quedan legacy vía `?action=`. El módulo está DESHABILITADO en la empresa de prueba → verificado data-layer + general con seed mínimo.
- **Fixes PG (legacy MUY roto):** `productionType` es BOOLEAN (no int); compuestos: `$db->GetAssoc()` keyea por 1ª columna + `\'production\'` con backslashes literales; roc ambiguo en JOIN; meta vía getItemData. code-reviewer P1: `GROUP BY itemId,userId` → `GROUP BY itemId` + MAX(userId).
- **TODOS los reportes "simples/medianos/pesados" designados migrados (20 + 1 alias).**

## 2026-05-27 (cont. 7) — `cashflow` + `open_invoices` + `vpayments` (21º–23º): los 3 "con wrinkle" → MIGRACIÓN DE REPORTES COMPLETA

- **Los 3 reportes diferidos migrados al BFF** (read-only, en `$bffStaticReports`): `cashflow` (wrinkle `itemId IS NOT NULL`/NULL vs MySQL `>0`/`=0`), `open_invoices` (se eliminó el self-heal write en GET — §16; fix PG boolean), `vpayments` (gateway Bancard/Dinelco; `api_key` computado en el service porque el middleware no carga `config.php`).
- **HITO: TODOS los reportes designados están migrados al BFF de 3 capas (23 + 1 alias).** `code-reviewer` sin P0/P1 en los 3.

## 2026-05-27 (cont. 8) — fuera de reportes: borrado a_settingsActual + capa de datos BFF del DASHBOARD (17 widgets)

- **Cleanup**: eliminado `panel/a_settingsActual.php` (duplicado huérfano).
- **Dashboard del panel — CAPA DE DATOS migrada al BFF** (commit `bfdece5`): service + API + BFF para 17 widgets. Hallazgo clave: acoplamiento a globals de `config.php` (`$_modules`, `$plansValues`, etc.) → resueltos con `companyMeta()` + fallbacks.
- **Fixes PG**: `FORCE INDEX`/`HOUR()`→`EXTRACT`; columnas demovidas a JSONB; `schedule` itera recordset (no getAssoc, que keyea por `fromDate`). `code-reviewer`: 1 P0 corregido.

## 2026-05-27 (cont. 9) — npm vendoring (17 libs) + Dashboard del panel FRONT migrado (16º módulo BFF, completa el dashboard)

- **Vendoring npm batch** (commits `310662a`/`78c9930`): 17 libs de `assets/vendor/js/` gestionadas por `package.json` con versiones EXACTAS pineadas. `vendor-sync.sh` copia desde `node_modules/` y verifica byte-identidad.
- **Dashboard del panel — FRONT migrado** (commit `bedd81c`): `panel/reports/dashboard.html` (HTML+Mustache verbatim del legacy) + `panel/scripts/a_report_dashboard.js` (13 widgets via `/bff/reports/dashboard.php?widget=…`). Router: `/a_dashboard → /reports/dashboard.html` en `$bffStaticReports`. Verificado E2E con JWT real.
- **HITO: Dashboard completamente migrado al modelo Front→BFF→API→Postgres** (1er módulo NO-reporte en el modelo completo, 16º total).

## 2026-05-27 (cont. 10) — Dashboard front: templating migrado de Mustache → Alpine (1er fragmento Alpine)

- **`dashboard.html` + `a_report_dashboard.js` refactorizados** (commit `a7790f9`): bindings Mustache+jQuery reemplazados por Alpine (`x-text`/`x-html`/`x-show`/`x-for`). Charts Chart.js siguen imperativos.
- **Receta de init determinista documentada en convenciones §17**: markup sin `x-data` → script clona, pone `x-data`, `Alpine.initTree` DETACHED, reinserta, ejecuta `mountUI()`.
- **Footgun `<template>` en `<tbody>` documentado**: el parser foster-parentea las `<tr>` fuera del `<tbody>` → usar `x-html` + `esc()` en vez de `x-for` dentro de `<tbody>`.

## 2026-05-27 (cont. 11) — `a_outlets` (Sucursales): 1er CRUD del panel migrado al BFF/Alpine

- **`a_outlets` migrado al modelo Front→BFF→API→Postgres** (commit 99d1286): `OutletsService.php` (list/get/update) + API + BFF + `views/outlets.html` (lista ncmDataTables + form Alpine x-model en modal) + `scripts/a_outlets.js`. **HITO: 1er módulo CRUD no-reporte del panel en el BFF**.
- **Migración PARCIAL**: list/get/update al BFF. Create (cascada) y delete (cascadeante) quedan legacy.
- **Nuevo router pattern `$bffPartialModules`**: sirve el front estático cuando `empty($_GET['action'])`; fronts en `panel/views/`.
- **TRAP crítico — JSONB partial-update**: usar `ncmExecute(..., forceObj=true)` + leer `$res->fields['data']` para no wipe el blob al guardar campos parciales. Aplica a cualquier tabla con `data` JSONB.

## 2026-05-27 (cont. 12) — `a_settings` (Ajustes): migración parcial al BFF/Alpine + FIX de guardado roto en PG

- **Hecho — incr. 1-2**: `SettingsService` + `API/v1/settings.php` + `bff/settings.php`. Tabs Perfil + Visualización con Alpine x-model. Router `/a_settings → /views/settings.html`.
- **Hallazgo crítico**: guardado de Ajustes estaba ROTO en PG (tabla `setting` eliminada en Phase PG) → ahora merge `||` no-destructivo a `company.config` JSONB.
- **Pendiente — incr. 3**: diseñador de plantillas de impresión + monedas + logo upload.

<!-- Entradas cont. 4-12 del 2026-05-27 archivadas desde _session-log.md el 2026-05-30 (al superar el cap de 200 líneas). -->

## 2026-05-29 — Slice 33 reescrito en Alpine.js + vendoreo alpinejs-3.14.1 en /app (commit 3d62191)

- **Decisión de convención**: los templates nuevos en `/app` van en **Alpine.js**, NO en Mustache. Migración incremental — Mustache sigue cargado para los ~22 templates existentes pero no se crean templates Mustache nuevos. Convención §24 en `08-convenciones.md` actualizada (antes documentaba Mustache, ahora documenta Alpine + el patrón de integración completo).
- **customerRecord reescrito**: el template Mustache `#customerRecordTpl` del Slice 33 (commit b0fbec3, mismo día) fue reemplazado por markup Alpine (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`). Primer componente Alpine del POS `/app`. Componente `customerRecord` registrado con `Alpine.data()` en `alpine:init` en `globalv2.js` + `debug.js`. Render: clonar `<template>`, `Alpine.initTree(el)` detached, luego insertar. Switch: dos ramas `x-if` (con `checked` / sin) para alinear con `switchit()`/`recordsEdit`. `x-for` con wrapper `display:contents` por la restricción de raíz única de Alpine.
- **INFRA**: `assets/vendor/js/alpinejs-3.14.1.min.js` vendoreado (local — POS es offline). Agregado a `app/index.html` (script defer), `app/cache-sw.php` (precache), `app/filesCompiler.php` (bundle vendor; inserción en medio del array para sobrevivir el `array_slice(1,-1)` del bundle debug). `APP_VERSION` 2.0.9.3 → 2.0.9.4 para invalidar el SW cache.
- **Vault actualizado**: `08-convenciones.md` §24 (reemplaza Mustache por Alpine + patrón de integración) + §11 (agrega Alpine como parte del stack vigente). `03-stack.md` (Alpine 3.14.1 en /app + estado de Mustache como legacy en deprecación). `02-arquitectura.md` (nota Slice 33 corregida de Mustache a Alpine). `10-roadmap.md` (Slice 33 actualizado + nota de deuda de migración ~22 templates).
- **Nota QA pendiente**: verificación manual en browser del modal de fichas (render de los 7 tipos de campo, guardado, switch toggle, subida de imagen Dropbox).

## 2026-05-29 — Slice 33: customerRecord migrado a BFF/API/CustomerService — CIERRE desacople load.php (commit b0fbec3)

- **Hecho**: el handler `customerRecord` de `app/load.php` (~300 líneas de HTML server-rendered, el ÚLTIMO del desacople de listas/fichas) extraído al patrón BFF→API→Service con contrato JSON + Mustache.
- **`CustomerService::getRecords(companyId, customerId)`**: devuelve datos estructurados de fichas personalizadas (`customerRecord` + `cRecordField` + `cRecordValue`). `api/v1/customers.php` GET `?resource=records&id=`. `app/bff/customers.php` handler `action=customerRecord`. `CustomerService` queda con `getInfo()` (slice 32) + `getRecords()` (slice 33).
- **Template Mustache `#customerRecordTpl`**: nuevo en `app/index.php` + `app/index.html` (idénticos). Reproduce los 7 tipos de campo del legacy con las mismas clases/ids que usa el guardado (`recordsEdit`, lee del DOM). Post-render: conecta datePicker + uploaders Dropbox por campo imagen.
- **JS**: `ncmCustomer.recordsList` en `globalv2.js` + `debug.js` reescrito — antes inyectaba HTML de load.php, ahora fetch JSON a `bff/customers` + render Mustache.
- **SQL injection corregida**: `getValue()` del legacy concatenaba `cRecordFieldId`/`customerId` en el SQL → queries parametrizadas; scope `companyId` agregado.
- **PATRÓN NUEVO documentado (§24 en 08-convenciones.md)**: para handlers HTML server-rendered → API devuelve datos estructurados + front renderiza con template Mustache estático. Razón: el guardado (`recordsEdit`) lee el DOM por ids/clases; Mustache los reproduce exactamente sin cambiar el guardado. Aplica cuando el DOM-coupling del guardado haría costoso migrar a Alpine en el mismo paso.
- **CIERRE**: el desacople de listas/fichas de `load.php` está COMPLETO. Lo que queda en `load.php` es dead code (`tweet`, `orders`, `ordersPanel`, `calendar_*`, `customerProgress`, `walink`, `printServer`, `ordersPanelAPI`) y APIs externas diferidas (`bancardQR`, `pixQR`, `verifyTransactionPix`, `ePOSPending`, `verifyTransactionEPOS`, `userLocation`, `tin`).
- **Nota QA pendiente**: verificación manual del modal de fichas en browser (render + guardado + subida de imagen por campo tipo "image").

## 2026-05-29 — Slice 32: customerInfo migrado a BFF/API/CustomerService (commit 0e185f4)

- **Hecho**: el handler `customerInfo` de `app/load.php` (~272 líneas) extraído a un nuevo servicio del dominio cliente. `CustomerService::getInfo(companyId, outletId, customerId)` — resumen del cliente: contacto + últimos ítems vendidos + deuda corriente/vencida + gift cards activas + dirección default. Read-only salvo backfill lazy de `customerAddress`.
- **Nuevos archivos**: `api/lib/services/CustomerService.php` (nuevo servicio, no confundir con `CustomerAddressService`/`CustomerNoteService`) + `api/v1/customers.php` GET `?resource=info&id=` + `app/bff/customers.php` handler `action=customerInfo`.
- **Correcciones al legacy**: SQL injection (STRING_AGG de transactionIds concatenados en IN() con UUIDs sin comillas → IN(?) parametrizado); scope `companyId` agregado en queries de transaction/itemSold/giftCardSold; booleanos PG correctos (`transactionComplete = false`, `customerAddressDefault = true`).
- **JS**: `ncmCustomer.infoModal` en `globalv2.js` + `debug.js` repuntado de `load?l=…load:customerInfo` a `bff/customers`.
- **Nota QA**: preserva bug del legacy — deuda vencida usa `$totalRetrns` (de la deuda corriente) en vez de `$totalRetrnsV` (dead code). Decisión consciente: port fiel.
- **Deuda técnica P2**: `getDebtListByTransaction()` en `app/includes/functions.php` sigue con `IN()` sin parametrizar y sin scope `companyId`; ahora lo invoca un endpoint tenant-facing → prioritizar limpieza.
- **Estado de load.php**: queda SOLO `customerRecord` (HTML server-rendered, decisión de contrato pendiente). Todos los demás handlers del desacople están migrados.

## 2026-05-29 — Slices 29-31: agendaList, sessionsList, transactions migrados (commits 66da236, 1d02620, 74fed79)

- **Slice 29**: `TransactionService::getMainList()` — lista principal de ventas del POS. Roles 4/5 solo ven type 2/10 por userId; rest ve todo el tenant. Batch credit IN(?). `api/v1/transactions.php GET ?resource=mainList`. `_bffListMap` ahora incluye `transactions: 'transactions'`.
- **Slice 30**: `ScheduleService::getSessionsList()` — paquetes de sesiones (itemSessions>0) + sesiones agendadas type 13 del cliente en el outlet. SQL injection cerrada. `_bffListMap` incluye `sessionsList: 'schedule'`.
- **Slice 31**: `ScheduleService::getAgendaList()` — citas/turnos (type 13, status!=7, fromDate/toDate no nulos). Lee `transactionDetails` desde `meta` JSONB. `footBtn` replica comportamiento legacy. `_bffListMap` incluye `agendaList: 'schedule'`.

## 2026-05-28 (cierre) — Vendoreo npm de /app, Fase A (commit 2bac879)

- **Hecho**: `scripts/vendor-sync.sh` + `npm run vendor` — 11 libs JS sourced desde `node_modules` (fuente de verdad): jquery, moment, ismobilejs, mousetrap, jquery.actual, lz-string, chart.js, sweetalert2, mustache, leaflet, qrious. Verificadas byte-idénticas al `.min` commiteado.
- **Pendiente — Fase B** (diferida): ~10 libs en npm pero manuales (bootstrap, pouchdb, datatables.net, fastclick, push.js, etc.). Manual permanente (sin npm limpio): chosen, jquery.{number,geolocation,toast,fullscreen}, simpleStorage, rsvp, jsrsasign, qz-tray, moment-locale-es.

## 2026-05-28 (tarde/noche) — Desacople /app: 15 slices + fix crítico de ventas (commits 866052b..53ccd6e)

- **Hecho (slices 6-20):** migrados ~24 handlers de `action.php` a BFF→API→Service. Servicios nuevos en `api/lib/services/`: Transaction, Order (accept/transfer/assignUser), Sync, Register, Table.closeTable, Currency, Attendance, Notification, VPayment (money path, port fiel de add_vpayment), ElectronicInvoice, GiftCard, OrderItems, + Schedule extendido. Clusters **ENCOM→Punto** (attendance/notifications/vpayments) y **meta-JSONB** (8 handlers) COMPLETOS. Front repuntado a `/bff/*`.
- **CRITICO — guardado de ventas ROTO:** `processData` escribía `transactionDetails`/`tags` como columnas inexistentes en PG → el INSERT fallaba. Fix (commit e7c04fb): guardar en `meta` JSONB.
- **Convenciones PG nuevos:** §22.5 (identificadores sin comillas), §22.6 (transactionDetails/tags en meta jsonb), §22.7 (verbos REST). Helper `api/lib/meta_transaction.php` (RMW de transactionDetails en meta).
- **Gap de producción cerrado (commit 5f1b367):** `globalv2.js` era el front de PRODUCCIÓN (debug.js era solo para pruebas); los slices 1-13 solo habían tocado debug.js. Backfill de globalv2.js con los repoints + cutover.

## 2026-05-28 — Extracción de la API compartida a /api top-level (commit d75dd0b)

- **Decisión arquitectónica clave:** los endpoints de los slices de desacople movidos de `/app/API/v1/` a `/api` top-level (hermano de /panel y /app). La API es el backend único del sistema, destinado a correr en server dedicado.
- **Qué se movió:** `api/router.php` + `api/bootstrap.php` + `apiAuthTenant()` + `api/lib/response.php` + 4 servicios + 4 endpoints `/api/v1/`. Borrados los viejos `/app/API/v1/*`.
- **Clientes:** `app/bff/lib/api_client.php` apunta a `PUNTO_API_BASE` (dev: `http://localhost:8000`). `.claude/launch.json` tiene server :8000.
- **Deuda transitoria:** `api/bootstrap.php` hace `chdir(/app)` y reutiliza includes de /app — acoplamiento a resolver antes del SERVER-SPLIT.

## 2026-05-28 — Admin realm F0+F1+F2 + a_settings COMPLETO (commits 01a8929, 96f8b8f, 89e7388)

- **F0**: tabla `admin_user` (bcrypt, sin companyId, email único case-insensitive) + `bootstrap_seed.php` (CLI idempotente). Vars `.env.example`. Verificado E2E.
- **F1**: auth propia `/admin` — `v1/admin/login.php` (rate-limit) + `v1/admin/me.php` (gated) + `adminMiddleware()` + BFF `bff/admin/{login,me,logout}.php` + front estático standalone `admin/login.html` + `admin/home.html`. Cookie `_jwt_admin` HttpOnly. Aislamiento de realms verificado E2E (cruce → 401 en ambas direcciones).
- **F2**: CRUD de admins en `/admin` — `AdminUserService.php` (list/get/create/update/setStatus; email único, pass >=8, no desactivar último activo ni a uno mismo) + `panel/API/v1/admin/users.php` + `panel/bff/admin/users.php` + `panel/admin/users.html` + `scripts/users.js`. Router `/admin/users`. Verificado E2E.
- **a_settings COMPLETO**: SettingsService + Perfil/Visualización/Monedas/Logo/Plantillas al BFF + front Alpine. **HITO: 2º módulo CRUD del panel en BFF/Alpine.**
- **upload.php IDOR cerrado (commit 214666b).** `ENCOM_COMPANY_ID` → `MASTER_COMPANY_ID` en toda la base de código.

<!-- Entradas slices 29-33 y 2026-05-28 archivadas desde _session-log.md el 2026-05-31 (al superar cap de 200 líneas). -->
