<!-- REGLA: Este es el roadmap único del proyecto. Actualizar cuando:
     - Se completa un item (marcar ✅)
     - Se agrega un item nuevo
     - Cambian las prioridades
     - Se cierra una fase o se abre una nueva
     Antes era MODERNIZATION.md (consolidado acá el 2026-05-16). -->

# 10 — Roadmap Técnico

Roadmap único del proyecto Punto POS. Objetivo: modernizar progresivamente sin
big-bang rewrites, manteniendo el sistema funcional en cada etapa.

> **Última actualización:** 2026-05-28
> **Fuente histórica:** consolidado desde `MODERNIZATION.md` (eliminado)

---

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.x, sin framework, archivos monolíticos |
| DB | PostgreSQL 16 vía ADOdb + Docker ✅ |
| Frontend | Bootstrap 3 + jQuery, HTML mezclado con PHP |
| Auth (app) | JWT HS256 ✅ — cookie `_jwt` HttpOnly + fallback legacy activo |
| Auth (panel) | JWT HS256 ✅ — 68/68 endpoints con apiMiddleware() |
| IDs | UUID v7 ✅ — enc()/dec() identity, ncmInsert auto-genera PK |
| API | ~68 endpoints en `panel/API/*.php`, todos con envelope canónico ✅ |
| WebSockets | ~~Pusher~~ → ws-server propio (Node.js + Redis Pub/Sub) ✅ |
| Seguridad | Bypass key eliminado, CORS allowlist, debug gateado |

---

## Vista general de fases

```
Phase 0 ✅ → Phase 1 ✅ → Phase 2 ✅ → Phase 3 → Phase 6
                                  ↓
                       Phase WS ✅ (completado durante Phase 2)
                                  ↓
                       Phase UUID ✅ (enc/dec identity + UUID v7 en ncmInsert)
                                  ↓
                       Phase PG ✅ (PostgreSQL: schema v2 + JSONB + migrations PHP)
                                  ↓
                             Phase AI ← SIGUIENTE
```

---

## Orden de ejecución actual (prioridad)

1. **AHORA**: Phase AI.1 — agente básico + widget web + 5 tools solo lectura
2. **Luego**: Phase AI.2 — bot de Telegram
3. **Paralelo**: Migración endpoints legacy MySQL → PG (B2-B5), Phase 3, Phase 6

---

# Prioridad ALTA (próximas 4-8 semanas)

## Migración endpoints legacy MySQL → PostgreSQL (P0 seguridad + funcional)

**Problema**: 19 archivos PHP (endpoints API públicos + crons + utilities) usan conexión MySQL hardcoded a una BD que ya no existe (`incomepo_905`, `incomepo_rucpy`). Las credenciales están en el repo Git e historial. **Los endpoints están desplegados y se usan, pero todos fallan al conectar.**

**Decisión del usuario (2026-05-16)**: NO BORRAR los archivos — son endpoints vivos. Migrar la referencia de BD.

**Lista exacta** (auditada 2026-05-16):
- EASY (5): `panel/API/get_payment_methods.php` ✅, `get_check_issuing.php` ✅, `edit_inventory.php`, `edit_customers_test.php`, `add_customers_test.php`
- MEDIUM (9): `panel/API/add_items[_test].php`, `add_inventory[_test].php`, `edit_items.php`, `panel/crons/cronTrialAboutToExpire.php`, `cronCreateInvoices.php`, `app/tin.php`, `app/rucs.php`
- HARD (5): `panel/API/delete_items.php` (función mal nombrada), `delete_inventory.php` (llama `createInventory()` que NO existe — bug fatal), `dbcreator.php`/`dbcopier.php` (DDL MySQL hardcoded), `panel/API/get_inventory.php` (`die()` arriba)

**Bugs preexistentes detectados durante auditoría**:
- `delete_inventory.php:69`: llama función inexistente `createInventory()` → endpoint nunca ejecuta DELETE
- `delete_items.php`: función interna mal nombrada `editItem()` (debería ser `deleteItem()`)
- `add_inventory_test.php:42`: `outletId = 2446` hardcodeado

**Plan — 5 batches**:
1. **B1** ✅ COMPLETO: Crear `panel/API/lib/legacy_db.php` helper que reemplaza el bloque MySQL hardcoded
2. **B2 EASY**: Aplicar helper a los 5 EASY. **Estado: 2/5 migrados** (`get_payment_methods.php`, `get_check_issuing.php`)
3. **B3 MEDIUM**: Crons + APIs simples (5 archivos)
4. **B4 MEDIUM+HARD**: Inventory APIs + arreglar los 2 bugs en delete_*
5. **B5 Decisión separada**: `tin.php`/`rucs.php` (recrear `incomepo_rucpy` en PG o descontinuar fallback), `dbcreator/dbcopier` (deprecar o reescribir), endpoints con `die()` (reactivar o limpiar)

**Esfuerzo total**: ~12-15 horas (sin B5)

**Riesgos**: Estos endpoints son API pública para integraciones externas. Cualquier cambio de comportamiento puede romper apps cliente. Mitigar: tests con curl + payloads ejemplo antes/después.

---

## Admin realm — super-admins de plataforma separados (iniciado 2026-05-28)

**Decisión**: los super-admins de plataforma dejan de ser un "tenant especial" (flag `SAAS_ADM` sobre `MASTER_COMPANY_ID`) y pasan a ser usuarios propios en `admin_user`, con login en `/admin` y JWT criptográficamente separado del realm tenant. Ver [ADR-002](context/adr/ADR-002-admin-realm-separado.md) y `02-arquitectura.md § Admin realm`.

**Dos realms aislados:** `_jwt_panel` (tenant, `JWT_SECRET`) nunca valida en `/admin`; `_jwt_admin` (`ADMIN_JWT_SECRET`, `aud:"admin"`) nunca valida en el panel tenant. Secrets + cookies + audience distintos.

**Login de tenant:** los tenants mantienen su login pero por **TELÉFONO** (no email). `findEmailOrPhoneLogin` ya tiene el branch de phone. El login de tenant NO se depreca, solo cambia el identificador.

**Franchiser:** sigue como realm tenant (`/panel/franchiser.php`, gateado por `isParent`) — NO va a `/admin`.

### Plan de fases (6 fases, no big-bang — cada una deployable)

| Fase | Qué | Estado |
|------|-----|--------|
| **F0** | Tabla `admin_user` (migración 09) + `bootstrap_seed.php` (CLI idempotente, bcrypt) + vars `.env` (`ADMIN_JWT_SECRET/TTL`, `ADMIN_BOOTSTRAP_EMAIL/PASSWORD`). Verificado E2E en DB local. | ✅ HECHA (commit 01a8929, 2026-05-28) |
| **F1** | Auth del realm `/admin`: login email+pass, JWT propio (`_jwt_admin`, `aud:"admin"`), `adminMiddleware`, `login.html` estático + BFF, rate-limit. | ✅ HECHA (commit 96f8b8f, 2026-05-28) |
| **F2** | CRUD de admins en `/admin` (modelo BFF 3 capas). No permitir desactivar el último admin activo. | ✅ HECHA (commit 89e7388, 2026-05-28) |
| **F3** | Home `/admin` + migrar gestión de companies + billing desde `main.php` (queries cross-tenant aisladas en `lib/admin`). | **SIGUIENTE** |
| **F4** | ⚠️ RIESGO ALTO — desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant (quitar redirect `@.php:11`, limpiar `config.php`). Va ÚLTIMO porque rompe el gate de identidad legacy. | Pendiente |
| **F5** | Login de tenant por teléfono (no email) — independiente de F1–F4. | Pendiente |
| **F6** | Decommission de `main.php` como admin + hardening + verificar aislamiento de realms E2E. | Pendiente |

**Notas técnicas F0:**
- `admin_user`: UUID PK, email único case-insensitive (`lower(email)`), `passwordHash` bcrypt, `status` 1/0, `createdBy` self-FK, `lastLoginAt`, timestamps. Sin `companyId`.
- `bootstrap_seed.php`: CLI-only (`PHP_SAPI === 'cli'`), no loguea el password, no-op si el admin ya existe.
- `MASTER_COMPANY_ID` **no es más gate de identidad** post-F4, pero sigue intacto hasta entonces. No tocar el redirect de `@.php:11` ni `SAAS_ADM` hasta F4.

**Notas técnicas F2:**
- Stack BFF 3 capas completo: `panel/lib/admin/AdminUserService.php` (data layer — list/get/create/update/setStatus) + `panel/API/v1/admin/users.php` (gateado por `adminMiddleware()`, `_jwt_admin`, `aud:"admin"`) + `panel/bff/admin/users.php` (proxy cookie) + `panel/admin/users.html` + `panel/admin/scripts/users.js` (front standalone, todo escapado con `esc()`). Router: `/admin/users → /admin/users.html`. `home.html` linkea al CRUD.
- **Gotcha DB::Execute / INSERT…RETURNING**: `create()` usa `$db->Insert() + Insert_ID()` en vez de `INSERT…RETURNING` porque `DB::Execute()` solo materializa filas para sentencias que empiezan con `SELECT` o `WITH`. Un `INSERT…RETURNING` devuelve recordset vacío aunque inserte la fila, causando falso fallo. Aplica a todo insert que necesite el UUID generado.
- **Follow-up P1 (no bloqueante, diferido a F3+):** `bffFailFromApi()` en `panel/bff/lib/api_client.php` colapsa todo status !=401/403 a 502 — los 422 de negocio del service llegan al browser como 502 (el texto del error sí surface). Afecta toda la infra BFF compartida del realm tenant; recablear 422/404/409 verbatim queda fuera de scope de F2 para no arriesgar regresión.

---

## Desacople del monolito /app (POS) — patrón Front→BFF→API→Service (iniciado 2026-05-28)

El mismo patrón de 3 capas del panel se aplica al POS `/app`. El dispatcher monolítico `action.php`/`load.php` (con ~43+ concerns) se migra concern-por-concern. `action.php`/`load.php` siguen sirviendo los concerns no migrados (migración incremental, no big-bang).

**Slice 1 COMPLETO — `customerAddress` (commit d79cfa4, 2026-05-28)**:
- Front: 5 call-sites de `ncmCustomer.address.*` en `app/scripts/debug.js` repuntados a `/bff/customer_address?l=` (solo cambia el path; el payload `?l=` base64 es idéntico).
- BFF: `app/bff/customer_address.php` (decodifica `?l=`, rutea a la API, traduce al shape legacy del front) + `app/bff/lib/api_client.php` (cliente curl, reenvía `_jwt`).
- API: `app/API/v1/customer_address.php` (JWT-gated, bootstrapea contexto POS) + `app/API/lib/response.php` (envelope canónico de /app).
- Service: `app/lib/CustomerAddressService.php` (list/add/update/delete/setDefault; tenant-scoped; transacciones atómicas).
- Verificado E2E (curl, server :8002, JWT real): list/add/update/delete/setDefault OK; default correcto en clear-then-insert; inyección rechazada.

**Slices pendientes**: los ~42+ concerns restantes de `action.php` + los de `load.php`. Orden a definir por prioridad de negocio.

### Conocimiento issue crítico — `app/DB.php` sin `Insert_ID()` (afecta TODOS los slices futuros)

`app/includes/lib/DB.php` **divergió del panel** y no tiene el método `Insert_ID()`. Consecuencia: `ncmInsert()` y `ncmUpdate()` son **FATALES en /app** (llaman a `$db->Insert_ID()` que no existe → PHP fatal error).

**Impacto latente**: todo el legacy de /app que usa `ncmInsert`/`ncmUpdate` (para escrituras) está silenciosamente roto en PG. El runtime no explota porque la mayoría de los handlers de `action.php` probablemente nunca ejecutaron contra PG en producción, o fallaron silenciosamente.

**Regla para cada slice de /app que incluya escrituras**:
- `ncmExecute` (lecturas) → sigue funcionando, OK.
- `ncmInsert`/`ncmUpdate` → **PROHIBIDOS en /app**. Usar `$db->Execute($sql, $params)` parametrizados.
- Multi-step → `$db->StartTrans()` / `$db->CompleteTrans()` para atomicidad.

**Follow-up recomendado (no bloqueante)**: sincronizar `app/includes/lib/DB.php` con el panel agregando `Insert_ID()`. Eliminaría la divergencia y habilitaría `ncmInsert` en /app en el futuro.

---

## Phase 2.A — Retrofit envelope canónico ✅ COMPLETO

**68/68 endpoints** ahora usan `apiMiddleware()` con envelope canónico. Incluye `auth.php` (ruta legacy migrada 2026-05-16).

**Bugs arreglados**:
- ~~2.B — `get_company` 500~~ → `getTagsDefaults()` tiene guard `!is_object($result)` ✅
- ~~2.C — `get_orders` 500~~ → resuelto implícitamente por Phase UUID (`dec()` es identity) ✅

**Hardenings post-Phase 2 (2026-05-16)**:
- ✅ HMAC write token en `panel/API/kds.php` action=update — slug ya no permite mutar órdenes
- ✅ `.env` parser robusto — soporta valores con quotes
- ✅ Logging del fallback legacy `?l=` en `app/load.php`, `fetch.php`, `action.php` via `app/includes/legacy_auth_log.php`

**Nice-to-have (no bloquean Phase AI)**:

| # | Qué | Detalle | Prioridad |
|---|-----|---------|-----------|
| 2.D | OpenAPI spec para los 68 endpoints | `panel/API/openapi.yaml` | Baja |
| 2.E | Helpers de validación de request | `panel/API/lib/validate.php` | Baja |

---

## SQL Injection Audit (deflated — riesgo bajo)

**Resultado de auditoría 2026-05-16**: el "SQL injection audit" original resultó tener riesgo
mucho menor del esperado. De 14 casos candidatos:
- 5 = dead code
- 7 = mitigados (validateHttp + db_prepare, vars internas como $SQLcompanyId)
- 2 = a parametrizar (legitimate findings)

**Pendiente**: parametrizar 2 casos restantes en `app/includes/functions.php` (líneas 3529, 4561, 4563) — esfuerzo ~1h. Baja prioridad ahora que el riesgo real está documentado.

**Hallazgos separados** detectados durante el audit (no son SQL injection, pero importantes):
- 🟡 IDOR potencial en `panel/screens/scheduleConfirm.php:6` — `COMPANY_ID` definido desde URL base64 sin verificar JWT. Rompe regla §1 (aislamiento tenant)
- 🐛 Query rota en `app/includes/functions.php:4568` — SQL tiene 2 placeholders pero pasa 3 valores
- 🧹 Dead code en `panel/API/get_tin.php` líneas 39, 55-57 que referencian la BD muerta `ruc_py`

---

## Phase PG — queries con schema viejo (deuda real descubierta 2026-05-19)

**Problema descubierto al cargar el dashboard franchiser visualmente**:
Phase PG migró ~95 archivos pero quedaron queries en el panel que aún
referencian schema viejo eliminado/refactoreado:

| Tipo de bug | Ejemplo | Cantidad estimada |
|------------|---------|-------------------|
| `FROM setting` | `FROM company a, setting b` | varias (eliminada Phase PG.2) |
| Columnas que ahora viven en JSONB | `SELECT settingTimeZone FROM company` | docenas — debe ser `config->>'settingTimeZone'` |
| Columnas que no existen | `SELECT accountId FROM company` | TBD |
| UUIDs concatenados con doble-quoting | `outletId = ''019e4075-...''` | varias |
| `companyId IN()` sin null check | `IN(implode(',', $ids))` | varias |

**Cómo encontrar más**: arrancar preview server con `display_errors=on`,
ejercer el flujo end-to-end del dashboard/listados/reportes del panel,
y leer los logs PHP. Cada error revela una query rota.

**Estrategia recomendada**: agente `postgres-pro` puede mapear todas las
queries del panel que referencien `settingX FROM company` o tabla `setting`
y generar un patch masivo cambiando a `config->>'X'`. Tiempo estimado: 4-6h.

**Bloquea**: navegación completa del panel admin (dashboard, reports).
NO bloquea: login, API endpoints `panel/API/` migrados (todos usan
`_flattenJsonb` ya), `/app` POS, KDS/CDS, WebSocket — todos validados OK.

---

## Deprecation del fallback legacy `?l=` en /app ✅ COMPLETO (server)

**Problema (resuelto 2026-05-16)**: `app/load.php`, `app/fetch.php` y
`app/action.php` aceptaban identidad client-supplied via
`?l=base64(companyId,outletId,userId,roleId,registerId)` cuando el JWT
fallaba. Cualquier request sin cookie `_jwt` podía impersonar cualquier
tenant.

**Fix aplicado (proyecto pre-producción → hard-disable directo)**:
- Server: load.php / fetch.php / action.php retornan 401 si JWT falla,
  sin fallback a IDs del request.
- `?l=` se mantiene como sobre base64 pero SOLO para extraer la
  operación (`load`, `action`) — los IDs vienen del JWT.

**Pendiente menor (cleanup, no seguridad)**:
- `app/scripts/globalv2.js` aún construye el payload completo en `?l=`
  con IDs que el server ya ignora. Limpiar en una sesión futura: el
  cliente debería mandar solo `?l=base64({action})` o pasar directo a
  query params planos (`?action=...`).

---

## Migration Runner

**Problema**: Las migraciones se corren a mano. En deploy con Coolify no hay step automático.

**Propuesta**: Script bash que:
1. Lee tabla `schema_migrations` (crear si no existe)
2. Compara con archivos en `database/migrations/postgres/`
3. Ejecuta los pendientes en orden
4. Registra en `schema_migrations`

**Esfuerzo**: ~3 horas

**Riesgos**: Bajos. Idempotencia ya requerida por convención (§3 de `08-convenciones.md`).

---

# Prioridad MEDIA (2-4 meses)

## Phase AI.1 — Agente IA básico

**Problema**: El valor diferencial del producto es ser AI-first. Sin agente funcional
no hay diferenciación.

**Dependencia**: backend de los módulos consultados expuesto como Services/API limpia (las tools del agente leen de ahí). Refuerza la estrategia backend-first de `02-arquitectura.md`: cada módulo modernizado le da más superficie de datos al agente. NO requiere modernizar el frontend de los reportes — el agente los reemplaza.

**Cliente LLM**: OpenRouter (no Anthropic directo), SDK `openai` apuntando a OpenRouter. El "tool use" es function calling estándar (formato OpenAI).

### Visión

Un agente autónomo que habla con la API de Punto via JWT. Los usuarios interactúan con el sistema por chat (widget web, Telegram, WhatsApp) en lenguaje natural. El agente interpreta la intención, llama los endpoints correctos y devuelve respuestas formateadas.

**El agente tiene dos facetas que comparten la misma base** (tools deterministas + LLM orquestador):

1. **Chatbot / asistente genérico** — el usuario pregunta sobre sus datos ("¿cómo van las ventas?", "¿qué producto se vende menos?"), pide recomendaciones ("¿qué debería reponer?", "¿conviene este combo?") y obtiene ayuda operativa. Conversacional, libre.

2. **Analista de datos** — reemplaza la proliferación de reportes hardcodeados (~13K líneas en `report_*`). El usuario pide reportes ad-hoc en NL y **dashboards customizados**: describe qué quiere ver, la IA arma la estructura, se guarda, y se renderiza en vivo. Ver "Reportes y dashboards por IA" abajo.

**Decisión (2026-05-24)**: el agente es el reemplazo de los reportes exploratorios, no un chatbot decorativo. Los reportes legales/contables (facturación, libro de ventas, impositivos) se quedan hardcodeados — formato exacto y auditable, la IA no aporta ahí.

### Regla de oro — correctitud y seguridad (NO negociable)

En finanzas/inventario los números deben ser **exactos**; un dato mal calculado hace que el dueño decida con info falsa.

- **Tool calling determinista, NUNCA text-to-SQL libre.** El LLM elige entre funciones acotadas y parametrizadas (`ventas_periodo(desde, hasta, agrupar_por)`, `top_productos(n)`, `stock_bajo()`). Cada tool ejecuta SQL fija filtrada por `companyId`. El LLM **no escribe SQL ni hace aritmética** — solo decide qué preguntar y presenta el resultado.
- **Por qué**: text-to-SQL libre = fuga multi-tenant (leer otra company), queries que tumban la DB, y JOINs mal hechos = números errados con cara de correctos.
- **Aislamiento de tenant**: el JWT del usuario fija `companyId`; toda tool lo aplica en el WHERE. El LLM nunca recibe ni elige el `companyId`.

### Reportes y dashboards por IA

- **Reporte ad-hoc**: pregunta NL → el LLM elige tool(s) → datos exactos → respuesta formateada (texto + tabla/gráfico).
- **Dashboard custom**: el usuario describe ("ventas diarias del mes, top 5 productos, alertas de stock bajo") → la IA genera una **config** (JSON: lista de widgets, cada uno = una tool + params) → se guarda la config (`dashboard.config` JSONB por usuario/company) → el dashboard se renderiza **en vivo**, cada widget llama su tool.
- **KPIs guardados = DEFINICIONES, no valores.** Se persiste la fórmula/tool/params del KPI, nunca el número calculado (quedaría viejo y, si lo calculó la IA, podría estar mal). Los datos son siempre frescos y deterministas.
- La IA hace el trabajo creativo (estructurar) **una vez**; los números vienen de queries cada vez.

### Arquitectura

```
Telegram / WhatsApp / Widget Web
         ↓
    punto-agent/  (microservicio Python + FastAPI)
    ├── Interpreta intención (LLM via OpenRouter — function calling)
    ├── Llama tools deterministas → panel/API/* con JWT del usuario
    └── Formatea y devuelve respuesta (texto / tabla / config de dashboard)
         ↓
    panel/API/  (los endpoints existentes, sin modificar)
```

### Por qué esto funciona sin tocar el monolito

El agente solo necesita el JWT del usuario y los endpoints. No sabe nada de PHP ni de la base de datos. La API de Punto es su única interfaz.

### Tools de Claude (cada tool = un endpoint)

```python
tools = [
    {
        "name": "get_sales_report",
        "description": "Obtiene ventas de un período",
        "input_schema": {
            "properties": {
                "date_from": {"type": "string"},
                "date_to": {"type": "string"}
            }
        }
    },
    { "name": "get_stock_level", ... },
    { "name": "create_order", ... },
    { "name": "get_customers", ... },
    # ~20 tools para los casos de uso más frecuentes
]
```

### Casos de uso iniciales

| Faceta | Ejemplo de input | Action |
|--------|-----------------|--------|
| Chatbot | "mandame el cierre de hoy" | `ventas_periodo(hoy)` → resumen formateado |
| Chatbot | "cuánto stock me queda de Coca Cola" | `stock_nivel` con filtro |
| Chatbot (recomendación) | "¿qué debería reponer?" | `stock_bajo` + razonamiento sobre el resultado |
| Analista (reporte ad-hoc) | "ventas del mes pasado por categoría" | `ventas_periodo(..., agrupar_por=categoria)` → tabla/gráfico |
| Analista (dashboard) | "armame un dashboard con ventas diarias, top 5 productos y stock bajo" | genera config JSON → se guarda → render en vivo |
| Escritura (AI.3+) | "registrá una venta de 2 hamburguesas" | `create_order` |
| Proactivo (AI.5) | (sin trigger) stock bajo detectado | Alerta automática |

### Stack técnico

```
punto-agent/
├── main.py              # FastAPI app
├── agent.py             # Lógica del agente (Claude tool use)
├── tools/
│   ├── sales.py         # Wrappers para endpoints de ventas
│   ├── inventory.py     # Wrappers para items/stock
│   └── orders.py        # Wrappers para órdenes
├── channels/
│   ├── telegram.py      # python-telegram-bot
│   └── whatsapp.py      # Meta Cloud API o Twilio
└── auth.py              # Vincula usuario Telegram/WA → JWT de Punto
```

### Auth del agente

```
Usuario envía /start en Telegram
    → Bot genera código de vinculación de 6 dígitos
    → Usuario ingresa el código en el panel de Punto
    → Panel registra: telegram_id ↔ companyId + JWT
    → El agente usa ese JWT para todas las llamadas futuras
```

### Fases de implementación

| Fase | Scope | Prioridad |
|------|-------|-----------|
| AI.1 | Agente básico (OpenRouter) + widget web chat + 5 tools de solo lectura (ventas, items, stock, clientes) | Alta |
| AI.2 | **Reportes ad-hoc por NL** — el chatbot responde preguntas de datos con tablas/gráficos (reemplaza reportes exploratorios) | Alta |
| AI.3 | **Dashboards customizados** — IA genera config JSON, se guarda en `dashboard.config`, render en vivo. KPIs = definiciones | Alta |
| AI.4 | Recomendaciones (reponer stock, combos, productos lentos) sobre los datos de las tools | Media |
| AI.5 | Integración Telegram + bot de reportes | Media |
| AI.6 | Tools de escritura (crear órdenes, registrar ventas) | Media |
| AI.7 | WhatsApp (Meta Cloud API) | Media |
| AI.8 | Alertas proactivas (cron que monitorea + notifica) | Media |
| AI.9 | Contexto persistente por usuario (memoria conversacional) | Baja |

**Esfuerzo MVP (AI.1)**: ~2 semanas. AI.2/AI.3 reusan las mismas tools — el costo es el frontend (chat + render de tablas/dashboards), no nuevo backend.

---

## Phase 3 — Separación de capas: front.html → bff.php → api.php (ACTIVO 2026-05-26)

> **⚠️ Reescrito 2026-05-26.** La propuesta vieja (page controller PHP + template HTML)
> quedó superseded por la **REGLA RAÍZ: PHP nunca sirve HTML**. Ver
> [02-arquitectura.md](02-arquitectura.md) § "Arquitectura objetivo: BFF de 3 niveles".

**Estructura canónica para TODO el sistema** (decisión del usuario 2026-05-26):

```
front.html (HTML+JS, cero PHP)  →  bff.php (PHP, sin BD)  →  api.php (PHP + Postgres)
auth + chrome client-side          intermedia + formatea      única capa con queries
```

**Esto revierte** el diferimiento del boundary HTTP de la sesión 2026-05-26 (que dejaba
el BFF llamando a `lib/` in-process). Ahora el BFF llama a la API por HTTP desde el día 1.

**Piloto COMPLETO E2E (commit 051dd59, 2026-05-26): módulo Reportes, reporte `a_report_summary`** (read-only, sin escrituras):

| # | Qué | Archivo | Estado |
|---|-----|---------|--------|
| 3.1 | API: SQL de agregación de ventas (6 handlers; SQL portado MySQL→PG: `HOUR()`→`EXTRACT`, sin `USE INDEX`) | `panel/API/v1/reports/sales.php` + `panel/lib/reports/ReportSalesService.php` | ✅ |
| 3.2 | BFF: llama a la API por HTTP, compone derivados (netSales, margin, byweek, período anterior), helpers de fecha puros (sin `functions.php`) | `panel/bff/reports/summary.php` | ✅ |
| 3.3 | Front estático + JS recableado al BFF; formateo de números y flechas de comparación client-side; `drawChart`/`chartByHours` intactos | `panel/reports/summary.html` + `panel/scripts/a_report_summary.js` | ✅ |
| 3.4 | Bootstrap del chrome (`panel/API/v1/bootstrap.php` devuelve `thousand` como `'comma'/'dot'`; `panel/bff/bootstrap.php` lo expone al front) | `panel/API/v1/bootstrap.php` + `panel/bff/bootstrap.php` | ✅ |
| 3.5 | Replicar a los demás reportes + módulos (patrón YA fijado y verificado E2E con datos) | — | EN CURSO — 15 hechos + 1 alias: `summary`✅ `p_methods`✅ `inventory`✅ `users`✅ `categories`✅ `brands`✅ `stock_day`✅ `satisfaction`✅ (1er WRITE) `stock`✅ `recurring`✅ (2º WRITE) `summary_year`✅ `customers`✅ (núcleo; extras diferidos) `expenses`✅ (3er WRITE) + `by_brands`↪alias `drawers`✅ (4º WRITE) `products`✅ (1er HEAVY, ~1750 líneas legacy) `purchases`✅ (16º; 1ª MIGRACIÓN PARCIAL: solo las 3 lecturas al BFF, CRUD+fiscales quedan legacy vía `?action=`) `transactions`✅ (17º; el MÁS GRANDE ~3987 líneas; 2ª parcial: 3 lecturas BFF + FE-tab legacy + CRUD/fiscales legacy) `giftcards`✅ (18º, mediano; 1 lectura BFF + KPIs; form edición + writes legacy) `schedule`✅ (19º, mediano ~907; 3 lecturas BFF + donut/KPIs; modal sesiones + delete legacy) `production`✅ (20º, mediano ~1068; 3 lecturas BFF + KPIs; recipe/export/delete legacy; módulo deshabilitado → data-layer verificado, no E2E con datos reales) `cashflow`✅ (21º) `open_invoices`✅ (22º) `vpayments`✅ (23º, gateway externo) — **TODOS los reportes migrados** — `dashboard`✅ (1er módulo NO-reporte en el modelo BFF completo; front commit `bedd81c`: 13 widgets recableados a `/bff/reports/dashboard.php?widget=…`, HTML+Mustache verbatim del legacy, widgets gateados por módulo satisfaction/tables/schedule; tour iguider deferido — ver §"Follow-up: reemplazar iguider") |

**Notas del piloto (commits 051dd59, 973c9c5)**:
- **División de labor (canónica, ver 02-arquitectura.md § REGLA RAÍZ 2)**: el PHP (API+BFF) NUNCA genera markup NI formatea para display. El **BFF devuelve datos CRUDOS** (números `1395000`, fechas ISO, comparaciones como datos `{dir,pct,positive,prev:<crudo>}`, promedio crudo) + hace **cálculos/cross-data** (netSales, totales, byweek, margin, alineación período anterior). El **front formatea TODO** lo presentacional (números via `formatNumber`, fechas, %, textos) **y arma el markup**; en DataTables el `data-order` usa el valor crudo. Anti-patrones a corregir al migrar: (a) BFF que arma `<table>` HTML (lo que hacen `a_report_orders` y los legacy); (b) BFF que pre-formatea números/fechas a strings (corregido en commit `3bb636c` tras haberlo hecho mal una vuelta). Esto es lo que hay que replicar en 3.5.
- Verificado E2E en browser: KPIs, charts, tabs, date-picker con re-fetch sin reload, cero errores de consola.
- **Data-path verificado CON datos** (commit `973c9c5`+): se sembraron ~12 transacciones de prueba en company 0001 (tag `meta.seed=bff-pilot-verify`, quedaron como demo) → KPIs, flechas de comparación con color, charts, Medios de Pago y Por Día pueblan correctamente.
- **2º reporte migrado: `a_report_p_methods`** (commit `e35f8c7`, ajustado en `3bb636c`): mismo molde — API raw → BFF gateway/cálculos (crudo) → front formatea + arma 2 tablas + chart. Confirma que el patrón es mecánico/replicable.
- **Hallazgo (impersonalización/JWT)**: al "entrar" a una empresa hija el JWT `_jwt_panel` NO se reemite (solo cambia la sesión PHP), así que el BFF/API scopean por la empresa del LOGIN. Trackeado en `adr/ADR-001` (re-emit del JWT + `franchiser_to_tenant`).
- **Bugs PG-port arreglados de paso** (al verificar con datos): `getSalesByPayment` y `getAllSalesByDrawerPeriod` leían la columna `tags` (movida a `meta` JSONB) + `USE INDEX` de MySQL (commit `b796b3c`).
- **3er reporte: `a_report_inventory`** (commit `679c52e`): mismo molde + fix PG (legacy usaba `BETWEEN "..."` con comillas dobles). El service hace lookup de items parametrizado (no `getAllItems`).
- **4º reporte: `a_report_users`** (commit `356efc2`): agregados por usuario (itemSold⋈transaction⋈contact). bootstrap expone `companyId` + `publicUrl`. El filtro de outlet del legacy era no-op (UUID>int).
- **Cola de reportes "simples" (lista del usuario)** — batch por fuente de datos (opción 2):
  - **Batch A (itemSold)** ✅: `users`, `categories`, `brands`. Verificados con seed de `itemSold` (commits 356efc2, 474d4b5, 4888788). El `SELECT itemId … GROUP BY` se resolvió quitando el itemId del SELECT (PG no tiene `MIN(uuid)`); la resta de combos (getAllCombosCompoundsDiscount) se omite (helper roto en PG). El bloque ad-hoc `?doit=beibe` de brands no se migró.
  - **Batch B (stock/item)** ✅ COMPLETO: `stock_day`✅ (commit 75b3851; 3 fixes PG: bool=int, DATE(), y `ORDER BY stockId DESC`→`stockDate DESC` porque stockId es uuid aleatorio en PG). `stock`✅ (commit 676fe6a; ver notas PG abajo). `satisfaction`✅ (commit ce5d60a).
  - **`satisfaction`** ✅ (commit ce5d60a): **primer WRITE por el BFF**. Patrón de escritura establecido: front POST → `bff/reports/x.php` (action=...) → `bffApiPost()` (NUEVO en bff/lib/api_client.php, forwardea el JWT) → API `POST /API/v1/...` que ejecuta con `$db->Execute` (ncmExecute devuelve false para DELETE) scopeado por `companyId`. Fix de seguridad: el DELETE legacy no scopeaba por companyId (IDOR) → ahora sí. **Follow-up**: el gate de permiso fino `allowUser('sales','delete')` no se puede usar en el API porque usa `ROLE_ID` y apiMiddleware solo define `PANEL_AUTHED_ROLE` → por ahora se bloquea el rol read-only (7); wirear `ROLE_ID` en apiMiddleware habilitaría allowUser en TODOS los writes del API v1.
- **Cola "simples" — estado final** ✅ COMPLETA (6 de 6): `users`✅ `categories`✅ `brands`✅ `stock_day`✅ `satisfaction`✅ `stock`✅. Todos los reportes "simples" designados por el usuario están migrados. Batch A ✅ + Batch B ✅.
- **10º reporte: `a_report_recurring`** (commit 980f908, 2026-05-27): Facturas Recurrentes. 2º reporte con WRITE por el BFF (pause→status 2, activate→status 1, remove→DELETE). Reusa el patrón satisfaction (front POST → bff bffApiPost → API POST con `$db->Execute`, gate rol 7). Tenant scoping SOLO por `companyId` (sin `getROC()`/`$roc`) porque la tabla `recurring` **no tiene columna `outletId`** — igual que el legacy. **Hallazgo de data shape (reutilizable):** `_routeToJsonb` almacenó `recurringSaleData` como un **STRING-valued key** en `data` JSONB: los writers originales (`cronCreateRecurringInvoice.php:103`, `app/action.php:2502`) hacen `json_encode($sale)` antes de pasar a `ncmInsert`, así que `data->'recurringSaleData'` tiene `jsonb_typeof = string` (no es un objeto anidado). El service lee con `->>` y `json_decode` en PHP → robusto a string-de-json y objeto anidado. **Lección generalizable:** cualquier otro reporte que lea una columna legacy "absorbida" a JSONB debe chequear si el writer usa `json_encode` antes del insert — si lo hace, el valor es un string y hay que usar `->>'columna'` + `json_decode`, NO `->` directo. Archivos: `panel/lib/reports/ReportRecurringService.php`, `panel/API/v1/reports/recurring.php`, `panel/bff/reports/recurring.php`, `panel/reports/recurring.html`, `panel/scripts/a_report_recurring.js`; routing: `/a_report_recurring` en `panel/router.php`. Seed: 2 filas en company 0001 (1 activa/mes, 1 pausada/sem, cliente "Cliente Prueba SRL") en la forma real del writer.
- **Notas PG de `a_report_stock` (commit 676fe6a, 2026-05-27)**: (a) `itemTrackInventory = true` (legacy usaba `= 1`, roto en PG). (b) Gate de outlet reemplazado: `OUTLET_ID < 2` siempre era true en PG (UUID<int → siempre 0<2 → "seleccione sucursal") → reemplazado por check de validez de UUID que devuelve flag `needsOutlet`. (c) Stock total/costo calculado desde la fila MÁS RECIENTE por `stockDate DESC` (no `getAllItemStock` que ordena por `stockId` — uuid aleatorio, no cronológico). (d) `principal.count` = onHand − Σ(depósitos) — corrección de bug legacy (el legacy restaba solo el último depósito). (e) Patrón N+1 (items × locations) mantenido deliberadamente, igual al legacy; flagged para optimización futura (`itemId IN (...)`). Archivos: `panel/lib/reports/ReportStockService.php`, `panel/API/v1/reports/stock.php`, `panel/bff/reports/stock.php`, `panel/reports/stock.html`, `panel/scripts/a_report_stock.js`; routing: `/a_report_stock` → `$bffStaticReports` en `panel/router.php`.
- **11º reporte: `a_report_summary_year`** (commit 844a436, 2026-05-27): Resumen Anual de Ingresos y Egresos. Read-only. El BFF hace los cálculos cross-data por mes: `netTotal = salesTotal − discount − returnsTotal − nonAddingTotal`; `revenue = netTotal − expenses`; `margin`; promedio anual. La API/service devuelve agregados mensuales crudos (EXTRACT en vez de MONTH(), sin USE INDEX, sin columna `transactionDate as date` no agrupada — las fronteras mes se derivan en PHP). El front formatea, mapea mes-número→nombre en español, arma tabla + chart Chart.js (barras Ingresos, líneas Egresos/Margen, anotaciones: línea de promedio, COVID-2020, fin de año) y selector de año derivado de `company.createdAt` (`years[]` del service). Reusa `getNonAddingToSales()` (ya probado en `summary`). Verificado E2E: 2026 Mayo netTotal 1.335.000, revenue 1.215.000, margin 91%; cero errores de consola. Archivos: `panel/lib/reports/ReportSummaryYearService.php`, `panel/API/v1/reports/summary_year.php`, `panel/bff/reports/summary_year.php`, `panel/reports/summary-year.html`, `panel/scripts/a_report_summary_year.js`; routing: `/a_report_summary_year` en `panel/router.php`.
- **12º reporte: `a_report_customers`** (commit 4c0ad35, 2026-05-27): Reporte de Clientes. Alcance NÚCLEO: KPIs + tabla de ranking de 19 columnas + gráfico de barras top-15 clientes + date-picker. Los extras exploratorios del legacy (mapa de localidades con lat/lng, gráfico de recurrentes/nuevos, analítica de comportamiento via `getCustomersRate`) están **intencionalmente diferidos como candidatos a Phase AI** — el agente los reemplazará con reportes ad-hoc. Archivos: `panel/lib/reports/ReportCustomersService.php`, `panel/API/v1/reports/customers.php`, `panel/bff/reports/customers.php`, `panel/reports/customers.html`, `panel/scripts/a_report_customers.js`; routing: `/a_report_customers`. Seed: 4 transacciones de company 0001 vinculadas al contacto "Cliente Prueba SRL" (demo).
- **`by_brands` — alias de ruta** (commit 4c0ad35): `a_report_by_brands` era un duplicado huérfano/no-linkeado de `brands` (misma lógica "Ventas por Marca"). Resuelto como router alias `/a_report_by_brands` → `/reports/brands.html`. Sin código nuevo. **Primer caso de "duplicado legacy consolidado vía alias"** — patrón reutilizable para otros reportes duplicados que aparezcan.
- **13º reporte: `a_report_expenses`** (commit 9b55d70, 2026-05-27): Movimientos de Caja. **3er WRITE por el BFF** (update + delete, ambos con `$db->Execute`, bound params, scopeados por `companyId`). El GET devuelve `{rows, users}` en un solo request: `rows` = movimientos con batch lookups para nombres de outlet/register/user (sin usar god-functions `getAllRegisters`/`getAllUsers`/`getCurrentOutletName`); `users` = contactos `type=0` para el dropdown del modal de edición. Outlet scope: `getROC(1)` filtra genuinamente por `OUTLET_ID` del JWT (UUID-strict equality) — a diferencia de users/stock donde el filtro era no-op (UUID>int). La semilla de verificación debía estar en el outlet ...002 (master), no en ...010. **Hallazgos generalizables de esta migración**: ① `formatNumberToInsertDB()` depende de las constantes `DECIMAL/THOUSAND_SEPARATOR` del contexto de página — fatales en el middleware de la API. Patrón correcto: el PARSING de amounts es presentacional → hacerlo en el FRONT (helper JS `parseAmount` que invierte la máscara), y la API solo valida `is_numeric` + castea a float. Aplica a cualquier reporte con escritura de monto dinero. ② `masksCurrency` (máscara JS compartida) strip-ea separadores y trata los dígitos restantes como unidades enteras → pre-alimentar un input de moneda con "150.000,00" da "15.000.000". Al pre-rellenar para edición, pasar el valor en unidades enteras (`String(Math.round(amount))`), nunca el string formateado. Footgun latente compartido por los forms de edición legacy. Archivos: `panel/lib/reports/ReportExpensesService.php`, `panel/API/v1/reports/expenses.php`, `panel/bff/reports/expenses.php`, `panel/reports/expenses.html`, `panel/scripts/a_report_expenses.js`; routing: `/a_report_expenses` en `panel/router.php`.
- **14º reporte: `a_report_drawers`** (commit 27b6452, 2026-05-27): Cierres de Caja. **4º WRITE por el BFF** (close + correct + remove). CRUD completo con re-query del DETAIL: el cliente envía solo el `drawerId`; el service re-fetch todo scopeado a `companyId` (el legacy pasaba un blob base64 desde el cliente → `?d=` confiado sin verificar). Totales (sold/expense/income/return) via helpers PG-safe `getAllSalesByDrawerPeriod`/`sumTotalBetweenDateRanges`; desglose por medio de pago via `getSalesByPayment`; lookups de nombre (outlet/register/user) batch parametrizados. Fix de seguridad: `remove()` legacy era `DELETE ... WHERE drawerId=? LIMIT 1` → IDOR (sin companyId) + `LIMIT` inválido en PG DELETE; ahora `WHERE drawerId=? AND companyId=?`. El cierre (`close()`) usa `PANEL_AUTHED_USER` (sub del JWT, constante correcta en el middleware API) validado como UUID para `drawerUserClose` (nullable FK) — `USER_ID` NO existe en el contexto API. Sumas de expense/income ahora filtran por `companyId` además de `registerId`. Archivos: `panel/lib/reports/ReportDrawersService.php`, `panel/API/v1/reports/drawers.php`, `panel/bff/reports/drawers.php`, `panel/reports/drawers.html`, `panel/scripts/a_report_drawers.js`; routing: `/a_report_drawers` en `panel/router.php`. **Hallazgos generalizables de esta migración** (críticos para reportes futuros): ① **TRAP `ncmExecute` single-row / `is_array`**: `ncmExecute` para un SELECT de una sola fila (sin `forceObj`, sin `getAssoc`) devuelve un objeto `CaseInsensitiveArray`, NO un array PHP — por eso `is_array($result)` es `FALSE` y silencia el valor (todo queda en 0 / null). Patrón correcto: validar con check truthy + acceso por clave (`$result['col'] ?? $default`). `is_array()` SÍ es correcto para resultados de `getAssoc=true` (que devuelven arrays PHP reales). Afecta cualquier helper que haga `ncmExecute` sin flags. ② **`PANEL_AUTHED_USER` vs `USER_ID`**: en el contexto del middleware API (`apiMiddleware()`), la constante de usuario autenticado es `PANEL_AUTHED_USER` (= claim `sub` del JWT, un UUID de usuario; = 0 en el path legacy `api_key`). `USER_ID` no está definido ahí y causa un error silencioso. Siempre usar `PANEL_AUTHED_USER` en código nuevo dentro de `panel/API/v1/`.
- **Bugs PG latentes descubiertos en customers**:
  - **`getCustomerData()` / `getContactData()` interpolación sin comillas** ✅ **ARREGLADO (commit cbe22cd, 2026-05-27)**: en la branch `'uid'`/`'contactId'`, construía `WHERE contactId = $id` interpolando el UUID via `$db->Prepare($id)`, pero `$db->Prepare` devuelve UUIDs **sin comillas** → error de sintaxis PG → la función caía al fallback `'Sin Nombre'` vacío en todos sus ~72 call-sites (incluyendo `ReportSatisfactionService`). Ahora usa bound params: `WHERE contactId = ? AND companyId = ?`. Tenant scope y flag `$isCustomer` preservados. Verificado E2E: el reporte de satisfaction ya resuelve el nombre del cliente.
  - **Columnas demotadas de `contact` seleccionadas explícitamente** (migración `06_contact_jsonb_demote.sql`): `contactAddress`, `contactAddress2`, `contactLocation`, `contactCity` ya no existen como columnas reales — están en `contact.data` JSONB. Cualquier query que las seleccione por nombre explícito falla con "undefined column" en PG. Requiere `SELECT *` + `_flattenJsonb` (o `data->>'...'`). Generalizable: ante cualquier query que nombre columnas demotadas de `contact`, reemplazar por `SELECT *` + flatten. Ver también nota homóloga en `04-modelo-de-dominio.md`.
- **Bug latente `lessInternalTotals()` + `USE INDEX` + columna `tags`** ✅ **ARREGLADO (commit 50acccb, 2026-05-27)**: quitado `USE INDEX` (MySQL), `tags` reemplazado por `meta->>'tags' AS tags`, y `transactionType IN($tTypes)` parametrizado (split/intval/bind; `SMALLINT` → `intval` correcto). Verificado vía PDO (9 filas, tags vía meta->>).
- **`getAllContacts` / `getAllContactsRaw` — parametrizar companyId/type/IN** ✅ **ARREGLADO (commit 1c2af24, 2026-05-27)**: `getAllContactsRaw` tenía companyId interpolado sin comillas (UUID → error sintaxis PG) y su field-list por defecto seleccionaba columnas demotadas a `data` JSONB por migración 06 → "undefined column". Reescrito a `SELECT *` vía `ncmExecute` getAssoc (aplica `_flattenJsonb`) con companyId/type como bound params; ignora la lista legacy `$fields`/`$index`. `getAllContacts` (wrapper): type interpolado e `IN($in)` con `db_prepare` no-op (UUIDs sin comillas) → ahora type e IN parametrizados (split+bind); `IN` filtra por `contactId`. ~~**Residuales pre-existentes fuera de scope**~~ ✅ **CERRADOS (commit 1a1beb9)**: (1) `get_sales.php:48` ya no interpola el IN en `$where` — rutea por el wrapper `getAllContacts` (path `$in` parametrizado, `realKeys=true`, guard si no hay ids). (2) El wrapper ahora hace `_flattenJsonb($result->fields)` por fila en el loop hand-rolled (forceObj no aplana solo) → los campos demovidos a JSONB (`contactAddress`/`City`/`Note`) se exponen. Verificado E2E: `/API/get_sales.php` devuelve `customer_address` "Calle Falsa 123" (columna demotada). **Familia de god-functions PG 100% cerrada, sin residuales.**
- **Familia de god-functions PG CERRADA**: `getCustomerData`/`getContactData` ✅, `getAllItems`/`getAllItemsRaw` ✅, `getAllContacts`/`getAllContactsRaw` ✅, `lessInternalTotals` ✅ — todas con bound params.
- **Seed de verificación** (company 0001, dejado como demo): ~12 transacciones (`meta.seed=bff-pilot-verify`), 6 movimientos de stock (`stockNote=seed:bff-inv-verify`), 9 líneas itemSold + 2 categorías/2 marcas asignadas a 2 ítems (`meta.seed=bff-itemsold-verify`).
- **`getAllItems()` / `getAllItemsRaw()` interpolación sin comillas** ✅ **ARREGLADO (commit 179208c, 2026-05-27)**: ambas interpolaban `AND companyId = " . COMPANY_ID` (UUID sin comillas → error sintaxis PG → query vacía; el widget de KPIs de inventory via `getAllInventoryAndItemsModule` daba 0). `getAllItems` además interpolaba `itemId IN($in)` con `db_prepare` (no-op) → roto + SQL-injectable. Ahora: `companyId = ?` como bound param; la lista `$in` ("uuid1,uuid2,…") se split/trim/empty-filtra y se bindea en `IN (?,?,…)`. Tenant scope y semántica preservados. Vector de inyección cerrado. Verificado vía PDO (raw → 4 items, IN(2) → 2). El widget de inventory KPIs (`getAllInventoryAndItemsModule`) debería ahora poblar correctamente en vez de dar 0.
- **Fix `main.php` — banner T&C + listado de empresas (impersonación) (commit 0102d0f + data, 2026-05-27)**:
  - **Banner "Términos y Condiciones" eliminado**: se quitó el bloque de `mainAlerts()` (`functions.php`) que mostraba "Hemos actualizado nuestros Términos y Condiciones / Acepto". Se mantiene la alerta de facturas vencidas (EXPIRED). El handler `?acceptTerms=true` queda inalcanzable pero válido.
  - **Listado de empresas vacío (no se podía impersonar)**: el handler `?action=showTable` (main.php:629) gatea con `$userPermission['companyList']` (= `data[0].permissions.encom.companyList` del contacto). El usuario `admin@local.test` ("Administrador") tenía `data={}` (sin permisos) → "No permissions" → tabla vacía. Fix (autorizado por el usuario): se le copió el set de permisos `encom` del usuario `master@local.test` (privilege grant, **dato de demo en company 0001**, no en git). El código de `showTable` es PG-limpio (usa `company`/`contact`/`outlet` + campos flatten). Verificado E2E: tabla con 4 empresas + enter-links `?url=true&companyId=`.
  - **Hallazgo (ADR-001 reforzado): `main.php` usa la SESIÓN PHP legacy para `COMPANY_ID`, no el JWT.** Si la sesión PHP está impersonando otra empresa (vía `getCompanyLoginSession`), `main.php` rebota a `/login`→`/@` (gate `COMPANY_ID != ENCOM_COMPANY_ID`, línea 12) aunque el JWT/BFF estén en la master (0001). **Reset sin password:** `?backToSaaS=true` (config.php:146, sólo si `SAAS_ADM`) hace `getCompanyLoginSession(MASTER_COMPANY_ID)` y redirige a `/main`. Refuerza el follow-up de ADR-001: unificar la identidad en el JWT (re-emitir al impersonar) en vez de la sesión PHP divergente.
- **15º reporte: `a_report_products`** (commit f7ff2de, 2026-05-27): Reporte de Artículos. **PRIMER REPORTE "HEAVY"** (~1750 líneas legacy). Read-only con 3 vistas en tabs (Resumen — agregado por producto, Detallado — líneas de venta, Combos) + 4 KPIs con comparación período anterior + gráfico de barras + date-picker + búsqueda de detalle + drill-down por URL (itmId/cusId/usrId/month/year). Archivos: `panel/lib/reports/ReportProductsService.php`, `panel/API/v1/reports/products.php`, `panel/bff/reports/products.php`, `panel/reports/products.html`, `panel/scripts/a_report_products.js`; routing: `/a_report_products` en `panel/router.php`.
  **Hallazgos críticos (generalizables a los demás reportes heavy):**
  1. **Patrón heavy-report — motor ERP en el service**: para reportes financieros con múltiples vistas, las fórmulas exactas (utilidad por fila + agregados) se computan en el Service (fuente única de verdad), no en el BFF. El BFF solo suma/deriva KPIs y el gráfico. El front formatea. Reduce el riesgo de que una fórmula diverja entre capas. Aplica a compras, transacciones y cualquier reporte con fórmula financiera por fila.
  2. **Fórmulas financieras difieren por vista y son P0**: general/detail: `(total − COGS) − comisión` (sin restar impuesto); combos: `((total − impuesto) − COGS) − comisión`. Un primer pass del code-reviewer detectó la fórmula de detail restando impuesto indebidamente (P0 financiero). Verificar cada fórmula contra la línea legacy original.
  3. **Asimetría de SUM por modo de filtro (P1 si se normaliza)**: los branches `cusId`/`usrId` suman COGS/descuento **sin** multiplicar por unidades; los branches default/`itmId`/`month` suman **con** `*units`. Hay que preservar esta asimetría — un primer pass la normalizó incorrectamente.
  4. **Bugs PG recurrentes en heavy reports**: `USE INDEX` (MySQL) → eliminar; `MONTH()`/`YEAR()` → `EXTRACT`; columnas movidas a JSONB (ej. `transaction.tags → meta`) no se pueden SELECT por nombre; `getAllCombosCompoundsDiscount` roto en PG (`itemSoldParent != 0` UUID-vs-int) → se omite consistentemente (igual que en brands/categories); "self-heal write en GET" (ej. ncmUpdate de `itemSoldTax` sin scope + LIMIT inválido PG en DELETE) → **eliminar, nunca portar** (recomputar para display, jamás escribir en un GET).
  5. **Bug de interpolación literal en búsqueda `src`**: el término estaba LITERALMENTE dentro de un string PHP single-quoted (`'... LIKE \'%\' . $word . \'%\''`) — nunca interpolaba. Reescribir como ILIKE parametrizado. Patrón a vigilar en otros reportes con search.
- **16º reporte: `a_report_purchases`** (Compras y Gastos): **PRIMERA MIGRACIÓN PARCIAL**. El módulo legacy (~2632 líneas) es un CRUD pesado + 2 fiscales, no un reporte limpio. Se migraron al BFF SOLO las 3 vistas de LECTURA (`general` tipo 1,4 + deuda; `cobros` tipo 5 + comprobante padre; `detail` transaction⋈itemSold). El CRUD de edición (`edit`/`update`/`paymentForm`/`addPayment`/`delete`) y los fiscales (`rg90`, `libro-compra`) **siguen en el PHP legacy `a_report_purchases.php`**. Archivos: `panel/lib/reports/ReportPurchasesService.php`, `panel/API/v1/reports/purchases.php`, `panel/bff/reports/purchases.php`, `panel/reports/purchases.html`, `panel/scripts/a_report_purchases.js`.
  **Patrón nuevo — migración parcial vía router** (reutilizable para los otros pesados CRUD: transactions, giftCards): `panel/router.php` sirve el front estático cuando NO hay `?action=`, y cae al PHP legacy cuando SÍ lo hay (`if ($path === '/a_report_x' && empty($_GET['action']))`). El front recablea las 3 lecturas al BFF; los writes/fiscales se cargan en los modales globales del shell (`#modalXLarge` editar, `#modalTiny` pago) o ventanas nuevas, apuntando a `/a_report_purchases?action=…`. En prod replicar con `RewriteCond %{QUERY_STRING} !(^|&)action=` en `.htaccess`.
  **Hallazgos generalizables:**
  1. **`transaction.transactionDetails` fue absorbido a `meta` JSONB** (Phase PG) — no se puede SELECT por nombre. Leer con `a.meta->>'transactionDetails'` + `json_decode`. Mismo patrón que `tags`→`meta`.
  2. **BOOLEAN PG `transactionComplete`**: ADOdb (el driver real de `ncmExecute`) lo devuelve como **1/0**, así que `(int)$v` funciona; pero PDO/otros pueden devolver `'t'/'f'` (y `(int)'t'===0` silenciaría los completos). Se agregó un helper `isComplete()` robusto al driver (`is_bool` || `'1'/'t'/'true'`). Aplicar a cualquier lectura de columna boolean PG.
  3. **`country` agregado al bootstrap** (`/API/v1/bootstrap.php` → `config->>'settingCountry'`): para gatear reportes fiscales locales (RG90/Libro Compra, PY-only) desde el front. Reutilizable por otros fiscales.
  4. **Fixes PG de paso**: `FROM transagction` (typo legacy en la rama `src` de detail) → `transaction`; rama `cobros`/supId tenía un `AND transactionDate` colgante (sin BETWEEN) seguido del `$roc` → SQL roto en PG, eliminado; deuda calculada con un único `SUM ... GROUP BY transactionParentId` (el legacy hacía N+1).
  5. **Seguridad**: el service es read-only; el `delete` de pagos legacy (que era IDOR sin companyId) sigue sirviéndose por `?action=delete` legacy (que SÍ scopea por companyId). Búsqueda `src` parametrizada (ILIKE), lookups batch con `IN (?,?…)`, roc por companyId/outlet en cada query.
- **17º reporte: `a_report_transactions`** (Pagos y Transacciones): **EL MÁS GRANDE (~3987 líneas)**. 2ª migración parcial. Se migraron al BFF 3 vistas de lectura de BD: `detail` (ventas tipos 0,3,6,7,8 con deuda de crédito + auth/prefijo/padding del comprobante de la caja + tags + totales calculables por tipo), `cobros` (pagos tipo 5 + comprobante padre tipo 0,3), `quotes` (cotizaciones tipo 9 + estado). **La vista `feTable` NO se migró**: es un gateway a una API externa de Facturación Electrónica con token hardcodeado (no verificable en dev, análogo a vpayments) → el front carga su HTML directo del legacy `?action=feTable`. CRUD/export/fiscales (rg90/libro-ventas/mcal/tusFacturas) quedan legacy. Router: `transactions` agregado al mapa `$bffPartialReports` (mismo patrón que purchases). Archivos: `panel/lib/reports/ReportTransactionsService.php`, `panel/API/v1/reports/transactions.php`, `panel/bff/reports/transactions.php`, `panel/reports/transactions.html`, `panel/scripts/a_report_transactions.js`.
  **Hallazgos generalizables:**
  1. **TRAP `array_map` sobre filas getAssoc de `ncmExecute`**: extraer ids con `array_map(fn($r)=>$r['col'], $res)` sobre el resultado de `ncmExecute(...,getAssoc=true)` (filas `CaseInsensitiveArray`) **lee mal algunas columnas** (devuelve vacío para `customerId` mientras `userId`/`transactionId` sí funcionan, dependiendo del SELECT — se observó con SELECT explícito de columnas + una columna computada `meta->>'x' AS x`). El `foreach($res as $f){ $ids[]=$f['col']; }` es FIABLE. Regla: recolectar ids/valores de filas getAssoc con `foreach`, NO `array_map`. (En purchases el array_map andaba porque era sobre filas `->fields` recolectadas con `while`, no sobre el array getAssoc directo.)
  2. **`transaction.tags` absorbido a `meta` JSONB** → leer con `meta->>'tags'` (JSON string → `json_decode`). Mismo patrón que `transactionDetails`.
  3. **`register.registerReturnPrefix` vive DENTRO del `data` JSONB de la caja** (no flatten) → `json_decode($r['data'])`. Distinguir clave-ausente (null, no override) de clave-vacía ('' limpia el prefijo) con `array_key_exists` para devolución (tipo 6), igual que el legacy.
  4. **Datos de caja por fila eran N+1** en el legacy (un `SELECT * FROM register` por fila) → un solo lookup batch `registerInfo()`.
  5. **Correctitud financiera**: deuda de crédito `topay = netTotal − SUM(ABS pagos+devoluciones tipo 5,6)`; tipo 7 (anulado) → todos los totales calculables en 0; padding del comprobante con `registerDocsLeadingZeros`. Verificado E2E con seed (contado/crédito+pago/devolución/anulada/cotización).
- **18º reporte: `a_report_giftcards`** (Gift Cards, mediano ~531 líneas): 1 vista de lectura (`detail` = giftCardSold activadas con beneficiario/sucursal/documento resueltos) + 4 KPIs (vencidas/por-vencer/canjeadas/vigentes + valor vigente). El form de edición (`giftcard`) y los writes (`update`/`delete`) quedan legacy vía `?action=`. El BFF computa los KPIs sobre las filas (cross-data). **Edge case faithful (code-reviewer P1):** una gift card sin `giftCardSoldExpires` cuenta como VENCIDA, igual que el legacy (`strtotime('')=false → 0 < hoy`); el guard `!$exp || $exp < $now` lo preserva. **P2 hardening:** el `giftCardSoldColor` se valida como hex (`/^[0-9a-fA-F]{3,8}$/`) antes de interpolarlo en `style="color:#..."` (esc() no cubre `;`/`:` → inyección CSS). Archivos: `panel/lib/reports/ReportGiftcardsService.php`, `panel/API/v1/reports/giftcards.php`, `panel/bff/reports/giftcards.php`, `panel/reports/giftcards.html`, `panel/scripts/a_report_giftcards.js`; router: `giftcards` en `$bffPartialReports`.
- **19º reporte: `a_report_schedule`** (Agendamientos, mediano ~907 líneas): 3 vistas de lectura — `detail` (citas tipo 13 + summary de conteos por estado + donut chart + KPIs), `stats` (conteos por contacto: `uit=usr` usuarios / `uit=cus` clientes), `sessions` (paquetes con `itemSessions` > 1, realizadas/pendientes). El modal de sesiones (`detail` por id) y el write (`delete`) quedan legacy vía `?action=`; el click en una fila abre el form de TRANSACTIONS legacy (cross-módulo). Router: `schedule` en `$bffPartialReports`.
  **Hallazgo CRÍTICO (TRAP, generalizable):** `ncmExecute(..., getAssoc=true)` usa ADOdb `GetAssoc` que **keyea el resultado por la PRIMERA columna del SELECT**. Si esa columna se REPITE entre filas (ej. un agregado `GROUP BY contacto, estado` donde el contacto aparece en varias filas), cada fila sobrescribe a la anterior → **se pierde data silenciosamente** (sólo sobrevive 1 fila por valor de la 1ª columna). Síntoma observado: stats mostraba sólo 1 estado por contacto. **Regla:** usar getAssoc SOLO cuando la 1ª columna es única por fila (id, transactionId, etc.); para agregados con primera-columna repetida, iterar el recordset con `forceObj=true` + `while(!$res->EOF)`. (Distinto del TRAP de `array_map` de transactions, que es sobre cómo se leen las filas; este es sobre cómo `GetAssoc` las indexa.)
  **Otros hallazgos:** `contactInCalendar` (filtro de usuarios en calendario) e `itemSessions` (sesiones por ítem) fueron demovidos a `data` JSONB → leer con `data->>'...'` (cast `::int` con `COALESCE(NULLIF(...,''),'0')` para itemSessions). `getTotalScheduleByStatus()` interpola el contactId SIN comillas (UUID → error PG) y es N+1 → reemplazado por agregados parametrizados (`statusTotals` GROUP BY estado, `countsByContact` GROUP BY contacto+estado). `nombre de contacto`: el legacy usa contactName (no concatenar secondName, que duplicaba el nombre cuando coinciden). Archivos: `panel/lib/reports/ReportScheduleService.php`, `panel/API/v1/reports/schedule.php`, `panel/bff/reports/schedule.php`, `panel/reports/schedule.html`, `panel/scripts/a_report_schedule.js`.
- **20º reporte: `a_report_production`** (Producción, mediano ~1068 líneas): 3 vistas de lectura — `general` (producción agregada por ítem = tabla `production` tipo 1 + ventas de ítems `direct_production`, con utilidad por fila), `detail` (ventas direct_production línea por línea), `compound` (compuestos de `productionRecipe` + stock `stockSource='production'`, toggle día). El modal de receta (`recipe`), el export XLSX y el write (`delete`) quedan legacy vía `?action=`. **El módulo de producción está DESHABILITADO en la empresa de prueba** → verificado a nivel data-layer (las 3 vistas ejecutan sin error PG) + `general` con un seed mínimo (1 fila `production` → utilidad 50000 computada correcto) + render estructural; `detail`/`compound` ejecutan limpio pero sin datos reales que validen sus números (limitación aceptada). Fórmula de utilidad replicada FIEL de `buildTableList` legacy: `average=cogs/units; utility=((price−average)−comisión)−impuesto; total=utility*units`.
  **Fixes PG (el legacy estaba MUY roto en este módulo):** ① **`productionType` es BOOLEAN en PG** (no int) → el filtro legacy `productionType = 1` da error → usar `= true`. ② compuestos: `$db->GetAssoc()` keyea por la 1ª columna (itemId) dejándolo FUERA del value → el `IN()` quedaba vacío; + `stockSource = \'production\'` en string PHP doble-comilla deja backslashes literales (`\'production\'`) → error PG. Ahora itemIds bindeados parametrizados + `stockSource = ?`. ③ `$roc` sin calificar era ambiguo en los JOIN (transaction e item ambos tienen companyId) → calificado por alias `b.`. ④ meta de ítem vía `getItemData()` (aplana JSONB; `itemTax`/`itemComission`/`categoryId` demovidos). ⑤ **(code-reviewer P1)** general agregaba `GROUP BY itemId, userId` + asignación con overwrite → sub-contaba ítems producidos por >1 usuario; corregido a `GROUP BY itemId` + `MAX(userId)`. Archivos: `panel/lib/reports/ReportProductionService.php`, `panel/API/v1/reports/production.php`, `panel/bff/reports/production.php`, `panel/reports/production.html`, `panel/scripts/a_report_production.js`.
- **21º–23º reportes: `cashflow` + `open_invoices` + `vpayments`** (los 3 "con wrinkle", todos read-only, en `$bffStaticReports`):
  - **`a_report_cashflow`** (Flujo de Caja): una vista (`getCashFlow`) — ingresos (ventas contado 0,6 + cobros) − egresos (compra mercadería + gastos + pagos) = saldo; saldo inicial del período anterior + acumulado. **Wrinkle resuelto:** el split mercadería/servicios usaba `itemId > 0`/`= 0` (MySQL: 0 = sin ítem) → en PG itemId es UUID: mercadería = `itemId IS NOT NULL`, gasto = `itemId IS NULL`. `getChartSales` legacy era código muerto (`$sql` indefinido, front no lo llamaba) → no migrado.
  - **`a_report_open_invoices`** (Cuentas por Cobrar/Pagar): una vista (`general`, `state=income` tipo3 / `outcome` tipo4), agrupada por contacto con facturas + deuda + KPIs. **Se ELIMINÓ el self-heal write** (marcaba `transactionComplete=1` en el GET — §16). **Fix PG:** `transactionComplete < 1` rompe en boolean → `= false`. Estado de vencimiento fiel (incl. rareza legacy timestamp-vs-duración → toda no-vencida es "por vencer"). `getRowPaid` legacy era branch muerto.
  - **`a_report_vpayments`** (Pagos ePOS): **GATEWAY** — la API hace `curlContents(API_URL/get_vpayments)` (Bancard/Dinelco), devuelve registros + KPIs; BFF proxea; front arma donut+tabla. **`api_key` computado en el service** (`sha1(config->>'accountId')`) porque el middleware API no carga `config.php` (donde se define `API_KEY`) → la constante no existe ahí (causaba 500). **No verificable en dev** (sin Bancard) → estructural: la cadena devuelve `rows:[]` sin romper.
  **TODOS los reportes designados están migrados al BFF (23 + 1 alias).** La migración de reportes está COMPLETA. Ahora en curso: módulos CRUD del panel (no-reportes).

- **`a_outlets` (Sucursales) — 1er módulo CRUD del panel migrado (commit 99d1286, 2026-05-27)**: `OutletsService.php` (list/get/update) + `API/v1/outlets.php` (GET list|single, POST update, gate rol 7) + `bff/outlets.php` (proxy) + `views/outlets.html` (lista ncmDataTables + form Alpine x-model en modal) + `scripts/a_outlets.js` (§17 detached-initTree). **Migración PARCIAL**: list/get/update BFF; create/delete legacy vía `?action=`; businessHours (jQuery widget) y depósitos diferidos. Router: `$bffPartialModules` (nuevo mapa, paralelo a `$bffPartialReports`; fronts en `panel/views/`). TRAP data-JSONB partial-update resuelto (ver `08-convenciones.md §18`). 1er uso Alpine en CRUD con modal (§17.2).

- **`a_settings` (Ajustes) — 2º módulo CRUD del panel migrado (commits 1d8fd03..63435b0, 2026-05-28)**: migración COMPLETA de las 4 tabs reales del legacy (Perfil + Visualización + Monedas + Plantillas de Impresión). Archivos clave: `SettingsService.php` (general/options/taxonomies/currencies/templates) · `API/v1/settings.php` (views + POST types) · `bff/settings.php` (proxy+composición) · `views/settings.html` (tabs Alpine) · `scripts/a_settings.js` (Alpine §17) · `scripts/a_settings_templates.js` (widget jQuery portado verbatim — ver §17.2 + nuevo §20). FIX crítico de prod: el save de Ajustes estaba ROTO en PG (legacy hacía `AutoExecute('setting',…)` pero la tabla `setting` fue eliminada en Phase PG → ahora rutea a `company.config` JSONB vía `ncmUpdate` merge `||`). Fixes de seguridad en templates: quitado `OR companyId=1` (rompe con UUID), quitado `LIMIT 1` en DELETE (inválido PG), agregado `AND companyId=?` en UPDATE (era IDOR). Nota sobre logo: devuelve URL + uploadUrl pero NO verificable en dev (resize/sysimages en infra prod).
  - **Ecommerce N/A (UI MUERTA)**: el legacy `a_settings.php` tiene 4 tabs reales (Perfil/App/Sucursales-link/Plantillas); ecommerce NO existe como tab. Solo queda un handler backend `type=ecommerce` + un `a_settings2.js` huérfano (ruta `/a_settings2` inexistente). La tabla `ecommerce` la consume `franchiser.php` (otro módulo). No se migró — migrar fielmente significa no inventar UI que el legacy no muestra.
  - **Gap de verificación pendiente**: el drag/drop visual del template builder NO se verificó en browser (el shell `@.php` requiere sesión PHP legacy, no JWT — refuerza ADR-001 sobre unificar identidad). Pendiente smoke test visual en el entorno de prod/staging.
  - **Follow-up de seguridad**: `upload.php` confía en `?id=` del cliente (IDOR preexistente) → gatear `companyId` server-side.

**Módulos CRUD pendientes** (orden tentativo, pueden variar por prioridad de negocio):
`a_contacts` · `a_items` · `a_registers` · `a_banks` · `a_billing` · `a_history_billing` · `a_purchase` · `a_modules` · `a_inventory_count` · `a_bulk_*`
- **Integración**: el front corre como **fragmento dentro del shell existente** (`@.php` inyecta `reports/summary.html` en `#bodyContent` por hash-nav). El shell provee head/menú/jQuery/Chart.js/BS3/globals; el modo "standalone 100% autónomo con auth+chrome propio" queda DIFERIDO.
- Redirect a `/login` ante 401 del BFF NO implementado todavía (el shell ya gatea auth; se implementa cuando el front sea standalone).
- **Routing dev**: `panel/router.php` mapea `/a_report_summary` → sirve `reports/summary.html` estático. Prod: replicar con `RewriteRule` en `.htaccess`.

**Nota sobre el "split" previo (commits 5adfc79, d6bcfef)**: summary e inventory solo
tienen el JS extraído a `scripts/` — siguen siendo `.php` que mezclan back+vista HTML.
Es un paso intermedio, NO la estructura objetivo. El piloto los lleva al modelo real.

**Esfuerzo**: el piloto fija el patrón (incl. cómo el `.html` resuelve auth+chrome sin PHP);
replicar a los demás es mecánico una vez probado.

---

## CDN Local completo

**Problema**: Algunos assets todavía referencian CDNs externos. Offline-first requiere todo local.

**Propuesta**: Mover todo a `/assets/vendor/`. Ya hay avance parcial.

**Esfuerzo**: ~4 horas para completar

---

## Higiene de assets — vendoring vía npm (EN CURSO 2026-05-27)

**Objetivo**: gestionar los ~55 vendor JS de `assets/vendor/js/` vía `package.json` (provenance,
versión pineada, `npm audit`) en vez de archivos "misteriosos" commiteados. Alpine ya entró así.

**Plan**: `npm i --save-exact pkg@<versión-vendoreada>` para los npm-ables; `build.sh` (o un
`vendor-sync`) copia el dist canónico de `node_modules/` a `assets/vendor/js/`. **Pinear EXACTO**
(no bumpear — jQuery 3.6.3 / Chart 2.9.4 / Bootstrap 3.4.1 están congelados a propósito).

**Quedan como archivo** (no en npm / custom): `iguider`, `jquery.businessHours-1.0.1` (npm
`business-hours` es otro paquete) + el código propio del proyecto (`ncm.js`, `common.js`,
`documentPrintBuilder`, `ncmMaps`, etc. — no son vendor).

**Bumps de major a testear** (npm no tiene la versión vieja o difiere): `@fingerprintjs/fingerprintjs`
v3→v5, `jsrsasign`, `snap` (verificar snap.svg vs el "snap-1.9.3" vendoreado).

### Follow-up: reemplazar iguider (tour de onboarding)

`iguider` (~107 refs en dashboard/purchase/app POS) no está en npm y parece medio abandonado (hay
un `iguider.stub.js` → puede estar ya stubbeado). **Reemplazo recomendado: `driver.js` 1.4.0 (MIT,
~5KB, sin deps, vanilla).** Shepherd/intro.js son **AGPL** → descartados para SaaS comercial cerrado.
**Antes de migrar: verificar si el tour está activo** — si está muerto, ELIMINAR iguider en vez de
reemplazarlo. Es scope aparte (cambio de comportamiento del tour).

---

# Prioridad BAJA (largo plazo)

## Phase 6 — Arquitectura moderna (Slim 4)

**Dependencia**: Phases 1-5

**Problema**: El monolito PHP sin framework dificulta testing, middleware chains, DI.

**Propuesta**: Introducir Slim 4 como app paralela bajo `/v2/...`. Un endpoint a la vez.

**Esfuerzo**: Setup inicial ~2 días. Migración gradual.

**Riesgos**: Complejidad de mantener dos stacks. Solo hacer cuando haya masa crítica de endpoints nuevos.

---

## Phase AI.4+ — WhatsApp, alertas proactivas, memoria

Items futuros del agente IA. Ver sección "Phase AI.1" arriba para detalle completo.

---

# Items completados (referencia histórica)

## Phase 0 — Security Hotfixes ✅

| # | Qué | Estado |
|---|-----|--------|
| 0.1 | Eliminar bypass key `d41d8cd98f...` | ✅ |
| 0.2 | Reemplazar `Access-Control-Allow-Origin: *` con allowlist | ✅ |
| 0.3 | Gatear `?debug` con `APP_DEBUG=true` en `.env` | ✅ |
| 0.4 | Mover `SALT` a `.env` como `HASHIDS_SALT` | ✅ |
| 0.5 | Headers de seguridad (`X-Content-Type-Options`, `X-Frame-Options`, etc.) | ✅ |

## Phase WS — WebSocket Microservice ✅

Reemplaza Pusher (tercero de alto costo) con infraestructura propia.

### Arquitectura

```
PHP (wsPublish)  →  Redis Pub/Sub  →  ws-server (Node.js)  →  Browser
```

### Archivos creados

| Archivo | Descripción |
|---------|-------------|
| `ws-server/index.js` | Servidor WebSocket Node.js con ioredis |
| `ws-server/package.json` | deps: `ws@^8.17.0`, `ioredis@^5.3.2` |
| `ws-server/Dockerfile` | Node 20 Alpine, non-root |
| `panel/includes/ws_publish.php` | Publica a Redis sin extensión (raw RESP via fsockopen) |
| `app/includes/ws_publish.php` | Idem para el módulo app |
| `panel/standalone/scripts/ncm-ws.js` | Wrapper JS compatible con API de Pusher |

### Archivos migrados (Pusher → NcmWS)

| Archivo | Canal | Evento |
|---------|-------|--------|
| `panel/standalone/kds.php` + `kds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/kds2.php` | `{outletId}-KDS` | `order` |
| `panel/standalone/cds.php` + `cds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/checkoutScreen.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/standalone/checkoutScreen2.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/main.php` | `ncm-ePOS` | `payoutNow` |
| `panel/API/send_webSocket.php` | dinámico | dinámico |

### Protocolo del cliente (`ncm-ws.js`)

```js
// Drop-in replacement de Pusher:
var pusher = new NcmWS(WS_URL);
var ch = pusher.subscribe('outlet123-KDS');
ch.bind('order', (data) => { ... });
ch.unbind('order');
```

## Phase 1 — Auth JWT para `/app` ✅

Reemplazó el mecanismo de identidad falsificable (`$_GET['l']` base64) con JWT.

| # | Qué | Archivo | Estado |
|---|-----|---------|--------|
| 1.1 | Endpoint de login | `app/API/auth.php` | ✅ |
| 1.2 | Middleware JWT | `app/includes/jwt_middleware.php` | ✅ |
| 1.3 | JWT primero, fallback legacy + header `X-Legacy-Auth` | `app/action.php` | ✅ |
| 1.4 | Validar con JWT, mismatch check en POST | `app/fetchs.php` | ✅ |
| 1.5 | Refresh token | `app/API/refresh.php` | ✅ |
| 1.6 | Actualizar JS del POS | `app/index.php` | ⚠️ pendiente verificar |

### Notas de implementación

- Cookie HttpOnly `_jwt` (browser) + header `Authorization: Bearer` (clientes programáticos)
- Payload: `sub` (userId), `cid` (companyId), `oid` (outletId), `rid` (registerId), `role`, `iat`, `exp`
- Fallback legacy activo: si no hay JWT, sigue funcionando con Hashids en `?l=`
- `X-Legacy-Auth: 1` header para monitorear adopción

## Phase 2 (parcial) — Formalizar API del panel

### Archivos creados ✅

| Archivo | Descripción |
|---------|-------------|
| `panel/API/lib/response.php` | Envelope canónico: `apiOk()`, `apiError()`, `apiNotFound()`, etc. |
| `panel/API/lib/api_middleware.php` | Middleware centralizado: JWT + fallback api_key, define constantes |
| `panel/API/auth.php` | Login JWT para el panel (POST email+pass → JWT + HttpOnly cookie) |
| `panel/includes/jwt.php` | Copiado de `app/includes/jwt.php` |
| `panel/API/lib/legacy_db.php` | Helper conexión legacy → PG (creado durante migración MySQL→PG) ✅ |

### Envelope canónico

```json
// Éxito
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }

// Error
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

> Phase 2 sigue parcial. Ver sección "Prioridad ALTA" para items pendientes (2.A, 2.B, 2.C).

## Phase UUID — Migración a UUIDs ✅

enc()/dec() convertidas a identity functions (sin Hashids). UUID v7 auto-generado en ncmInsert.

| # | Qué | Estado |
|---|-----|--------|
| U.1 | `enc()`/`dec()` → identity passthrough (panel, app, crons) | ✅ |
| U.2 | `generateUuidV7()` en `functions.php` | ✅ |
| U.3 | `ncmInsert` auto-genera UUID v7 en la PK correcta por tabla | ✅ |
| U.4 | JWT payload cambiado de int a string para cid/oid/rid/sub | ✅ |
| U.5 | `contactUID` eliminado — reemplazado por `contactId` en ~103 archivos | ✅ |

## Phase PG — MySQL → PostgreSQL ✅

**Dependencia:** Phase UUID completa

| # | Qué | Estado |
|---|-----|--------|
| PG.1 | `db-schema-postgres.sql` v2 — 47 tablas, UUIDs, JSONB, todos los FKs | ✅ |
| PG.2 | `company` mergeada con `setting` + `module` + `companyHours` → `config JSONB` | ✅ |
| PG.3 | `item.data`, `contact.data`, `transaction.meta`, `itemSold.meta` JSONB | ✅ |
| PG.4 | `_flattenJsonb()` — lectura transparente de JSONB en PHP | ✅ |
| PG.5 | `_getTableSchema()` + `_routeToJsonb()` — escritura automática a JSONB | ✅ |
| PG.6 | `ncmInsert` y `ncmUpdate` usan routing JSONB | ✅ |
| PG.7 | `docker-compose.yml` — PostgreSQL 16, sin MySQL | ✅ |
| PG.8 | `panel/includes/db.php` + `app/includes/db.php` → `db.postgres.php` | ✅ |
| PG.9 | Queries a `FROM setting`/`FROM module` → `FROM company` (~95 archivos) | ✅ |
| PG.10 | `settingBlocked`→`blocked`, `settingPlanExpired`→`planExpired`, `settingSlug`→`slug` | ✅ |
| PG.11 | Campos JSONB en SQL: `config->>'settingName'`, `config->>'settingRUC'`, etc. | ✅ |

## Web Push ✅

- VAPID nativo (reemplaza OneSignal)
- Tabla `push_subscriptions` (migración `database/migrations/postgres/03_push_subscriptions.sql`)

---

# Decisiones técnicas vigentes

| Decisión | Elección | Razón |
|----------|----------|-------|
| Lenguaje backend | PHP (mantener) | Sin capacidad de rewrite completo |
| WebSockets | ws-server propio (Node.js) | Eliminar costo de Pusher |
| Pub/Sub | Redis | Ya en el stack, sin dependencia extra |
| JWT | HS256 custom PHP | Sin composer dependency adicional |
| IDs en API | UUID v7 (post Phase UUID) | Hashids deprecados, enc/dec son identity |
| API location | Dentro de panel/ (mantener) | No vale la pena separar aún |
| AI Agent | Microservicio Python separado | No tocar el monolito |
| Conexión BD legacy | `panel/API/lib/legacy_db.php` helper | Centraliza migración MySQL→PG sin tocar lógica |

## Decisiones de convenciones (resueltas 2026-05-16)

- Envelope canónico: migrar TODOS los endpoints progresivamente (Phase 2.A confirmada)
- Estilo PHP: legacy en archivos existentes, PSR-12 en archivos nuevos
- Frontend: jQuery por ahora, decisión post Phase 2 + AI.1
- SQL legacy: auditoría + batch P0 (resultó tener riesgo bajo tras auditar)

---

# Variables de entorno completas

```ini
# Seguridad (Phase 0)
APP_DEBUG=false
HASHIDS_SALT=<random-64-char>

# JWT (Phase 1 + 2)
JWT_SECRET=<random-64-char>
JWT_TTL=28800

# WebSocket (Phase WS)
WS_URL=wss://ws.tudominio.com
REDIS_URL=redis://redis:6379

# AI Agent (Phase AI — pendiente)
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...
PUNTO_API_BASE=https://panel.tudominio.com/API
AGENT_JWT_SECRET=...

# Feature flags (Phase 6 — pendiente)
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```

---

# Notas técnicas importantes

## Patrón de migración endpoints `api_head.php` → envelope canónico

```php
// ANTES
include_once('api_head.php');
// ... lógica ...
jsonDieResult($data, 200);

// DESPUÉS
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// ... lógica sin cambios ...
apiOk($data);
```

## Notas de `api_middleware.php`

```php
// CRÍTICO: $db debe ser global antes de incluir db.php y functions.php
// porque functions.php llama getAllPlans() en scope global en línea 3
global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

// enc()/dec() no están en functions.php, están redefinidos en el middleware
// (en post-Phase UUID, son identity passthrough)
```

## Helper `legacy_db.php` (para endpoints MySQL→PG)

Reemplaza el bloque MySQL hardcoded por:

```php
require_once __DIR__ . '/lib/legacy_db.php';
```

Que internamente:
- Carga `cors.php`
- Conecta a PG via `includes/db.php` (creds desde `.env`)
- Carga `simple.config.php` + `functions.php`
- Define `enc/dec` defensivamente como identity

## Estructura del proyecto

```
system/
├── app/                    # POS — módulo de caja (PHP legacy)
├── panel/                  # Admin/ERP (PHP legacy)
│   ├── API/                # ~93 endpoints REST
│   │   ├── lib/
│   │   │   ├── response.php       # Envelope canónico ✅
│   │   │   ├── api_middleware.php # Middleware JWT ✅
│   │   │   └── legacy_db.php      # Helper conexión PG para endpoints legacy ✅
│   │   └── auth.php               # Login JWT ✅
│   ├── includes/
│   │   ├── simple.config.php      # Constantes globales
│   │   ├── jwt.php                # JWT HS256 puro PHP
│   │   ├── db.postgres.php        # Conexión PG (ADOdb postgres9)
│   │   ├── db.pdo.php             # Conexión PG (PDO drop-in)
│   │   └── ws_publish.php         # Publica eventos a Redis
│   └── standalone/
│       └── scripts/
│           └── ncm-ws.js          # Cliente WebSocket (drop-in Pusher) ✅
├── ws-server/              # Microservicio Node.js WebSocket ✅
├── database/
│   ├── migrations/postgres/
│   └── seeds/
├── docker-compose.yml      # PostgreSQL + Redis + pgAdmin + ws-server
└── context/                # Kit de contexto para Claude (este roadmap está acá)
```
