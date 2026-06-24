<!-- REGLA: solo invariantes que ROMPEN el sistema o filtran datos entre tenants.
     El resto (detalle, contexto histórico, recetas) vive en _archive-convenciones-detalladas.md.
     Actualizar cuando se establezca un invariante nuevo del mismo calibre. -->

# 08 — Convenciones críticas (invariantes)

Solo lo que, si se ignora, rompe el sistema. Detalle expandido y convenciones
no-críticas → `_archive-convenciones-detalladas.md`.

---

## §1 — Aislamiento de tenants (REGLA ABSOLUTA)

Todo query que toque datos de tenant DEBE filtrar por `companyId`. Un leak entre tenants es incidente de seguridad crítico.

- SELECT: `WHERE companyId = $companyId` siempre.
- INSERT: `companyId` campo obligatorio.
- UPDATE/DELETE: `WHERE companyId = $companyId AND ...`.
- En APIs: `COMPANY_ID` viene del JWT, **nunca** del request body.
- Excepción: queries super-admin SaaS cross-tenant — DEBEN estar claramente marcadas y separadas del código de tenant.

---

## §7 — UUID v7 y PKs

Toda tabla nueva usa UUID como PK. Nunca auto-increment.

- Schema: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`.
- PHP: `ncmInsert()` auto-genera el UUID.
- APIs: IDs como strings UUID.

---

## §8 — JSONB para campos extensibles

Campos que no necesitan índice ni WHERE van a JSONB. No crear columnas para todo.

- Columna real: se filtra, indexa, o tiene constraint (FK, UNIQUE, NOT NULL crítico).
- JSONB: metadata, configuración, datos que varían por rubro, campos opcionales.

---

## §12 — Seguridad

Reglas activas:
- CORS: allowlist explícita (no `*`).
- Headers: `X-Content-Type-Options`, `X-Frame-Options`.
- Debug: gateado por `APP_DEBUG=true`.
- JWT: HttpOnly cookies, no localStorage.
- SQL: queries parametrizadas SIEMPRE. Concatenación directa = bug de seguridad.

### §12.1 — JWT: claim `iss` obligatorio

Todo JWT emitido DEBE incluir `iss` (issuer) con uno de los tres valores canónicos. Es la barrera contra privilege-confusion entre realms que comparten `JWT_SECRET`.

| Valor `iss` | Emite | Valida |
|-------------|-------|--------|
| `'pos-app'` | `app/API/auth.php`, `app/API/refresh.php`, `app/login.php`, cron service tokens | `app/includes/jwt_middleware.php` |
| `'panel'` | `panel/includes/functions.php` (login de tenant) | `panel/API/lib/api_middleware.php`, `panel/upload.php` |
| `'admin'` | `panel/API/lib/admin_auth.php` (login de admin) | `panel/API/lib/admin_auth.php` (suma al `aud='admin'`) |

Tokens sin `iss` → rechazo 401. `refresh.php` valida `iss === 'pos-app'` del token ENTRANTE antes de re-emitir. Nunca crear un nuevo emisor de JWT sin agregar el valor a esta tabla y al middleware correspondiente.

---

## §16 — Self-heal write en GET: eliminar, nunca portar

Si el código legacy ejecuta `UPDATE`/`INSERT`/`DELETE` dentro de un handler GET ("self-heal"), ese write se **elimina** en la migración. Se recomputa el valor para display en el Service/BFF. **Jamás portar un write a una ruta GET en el API v1.**

Un GET que escribe rompe idempotencia, hace imposible caching, y `LIMIT` en DELETE es inválido en PG.

---

## §18 — TRAP JSONB partial-update (preservar keys no gestionadas)

Cuando un `update()` hace UPDATE parcial sobre tabla con columna `data` JSONB con keys que el form NO gestiona, leer el `data` JSONB raw ANTES de mergear.

`ncmExecute` single-row aplana via `_flattenJsonb` Y hace `unset($row['data'])` → `$cur['data']` queda `null` → si re-escribís sin mergear, **wipeás el blob completo**.

```php
// BIEN — forceObj devuelve objeto ADOdb sin aplanar
$res = ncmExecute($db, "SELECT data FROM outlet WHERE outletId=?", [$id], forceObj: true);
$existingData = json_decode($res->fields['data'] ?? '{}', true) ?: [];
// merge: $newData = array_merge($existingData, $formFields)
```

Aplica a items, contacts, company config, y cualquier entidad con `data`/`meta` JSONB extensible.

---

## §24 — Templating: Alpine.js, no Mustache

Templates nuevos = Alpine. Mustache es legacy en deprecación incremental. **No crear templates Mustache nuevos.**

Receta canónica (`/app` POS offline):
- Template en `<template id="...">`; registro vía `Alpine.data('<nombre>', fn)` dentro de `document.addEventListener('alpine:init', ...)`.
- Fetch con `ncmHttp.getit()` (no fetch nativo — preserva auth/plumbing offline).
- El markup NO declara `x-data` (evita carrera observer↔script); el `<script>` clona `#root` detached, `setAttribute('x-data',...)`, `Alpine.initTree(fresh)` y `replaceChild`.
- `init()` (Alpine lo llama) corre detached — NUNCA toca el DOM del documento. Setup que necesita el nodo en doc (datePicker, tooltips) va en `mountUI()` / `$nextTick`.

**Frontera Alpine ↔ jQuery**: Alpine es dueño del estado reactivo y visibilidad (`x-text`/`x-show`/`x-model`); los plugins jQuery (DataTables, select2, Chart.js, datetimepicker) son dueños del DOM de su widget. **Nunca** mutar el mismo nodo desde ambos.

---

## §26 — Código nuevo en `/app`: namespace `Punto\App\*`

Todo PHP nuevo en `/app` (fuera de includes legacy y BFFs existentes) vive bajo `Punto\App\*` con estructura PSR-4 del Slice 0.

Namespaces canónicos: `Helpers\`, `Domain\{Customer,Money,Inventory,Document,Store,Taxonomy,GiftCard}\`, `Http\Response\`, `Services\Notification\`, `Database\` (`Query`). Convenciones por archivo: `declare(strict_types=1)`, `final class`, `readonly` properties + constructor promotion, PascalCase clases / camelCase métodos.

Patrón "wrapper → clase namespaced": la clase namespaced es la fuente; la función global queda como wrapper de 1 línea con `@deprecated`. Los callers legacy NO se tocan.

`Query::insert`/`Query::update` DEBEN delegar a `ncmInsert`/`ncmUpdate` (ver §34) — nunca reimplementar el routing JSONB.

---

## §28 — Modelo de auth de /app: device pairing ≠ sesión

El JWT de `/app` (`_jwt`) NO es sesión de usuario. Es device pairing: certifica que "este dispositivo está autorizado a operar como caja de esta empresa". `JWT_TTL=10 años` es intencional — la revocación es per-device vía tabla `device`.

| Nivel | Mecanismo | TTL |
|-------|-----------|-----|
| Device pairing | JWT `_jwt` + claim `did` (deviceId) | 10 años (revocable per-device) |
| Sesión de turno | PIN 4 dígitos → `ncmAuth.activeUser` + `lockPad` | por turno |

Nunca ajustar `JWT_TTL` "por seguridad de sesión" — la seguridad por turno la provee el PIN. Contraste: `/admin` tiene `ADMIN_JWT_TTL=8h` porque sí es sesión real.

---

## §31 — Teléfonos: E.164 storage, libphonenumber para conversión (REGLA ABSOLUTA)

1. **Storage en DB y queries**: SIEMPRE E.164 con `+` y código de país (`+595991234567`). Nunca `0991234567`, nunca sin `+`.
2. **Display al usuario**: SIEMPRE nacional vía `phoneFormatNational()`.
3. **Validación/parsing**: SIEMPRE libphonenumber. Backend: `phoneToE164($input, $iso)` en `panel/includes/phone.php` y `app/includes/phone.php`. Frontend: `window.libphonenumber.parsePhoneNumberFromString(input, iso)`.
4. **PROHIBIDO** concatenar `+`, `0` o código de país a mano (`'+595' + phone`).
5. El ISO viaja con el phone: `{phone: '+595991234567', iso: 'PY'}`. El backend re-valida con `phoneToE164()`.

Schema invariante: índice UNIQUE parcial `idx_contact_phone_tenant_unique` sobre `contactPhone` (role IN (0,1,2,7) AND type=0).

---

## §33 — Declarar realm por endpoint en /api; prohibido `$SQLcompanyId` global

### §33.1 — `apiAuthTenant(array $realms)` explícito

Todo endpoint nuevo en `api/v1/` DEBE declarar el realm aceptado. No usar default `['pos-app']` en silencio.

| Endpoint | Declaración |
|----------|-------------|
| Solo panel | `apiAuthTenant(['panel'])` |
| Solo POS | `apiAuthTenant(['pos-app'])` |
| Mixto | `apiAuthTenant(['pos-app', 'panel'])` |

Los tokens POS son **eternos** (device pairing). Si un endpoint de panel no declara `['panel']`, cualquier token de caja podría autenticar en él — privilege escalation a dispositivos de cajero.

BFF panel → /api: base `'shared'` + `Authorization: Bearer <_jwt_panel>` (no cookie — coexiste con `_jwt` del POS en el mismo browser).

### §33.2 — PROHIBIDO `$SQLcompanyId` global en services /api

Services en `api/lib/` NO deben leer `$SQLcompanyId`/`$COMPANY_ID`/`$SQLoutletId` como global. Deben recibir `$companyId` por parámetro (del JWT vía `TenantContext` o argumento explícito). En el contexto de /api esos globals no están inicializados → SQL roto silencioso (`WHERE companyId = AND ...` → null → display silencioso de `'None'`).

---

## §34 — Write path canónico con JSONB: `ncmInsert`/`ncmUpdate` single source of truth

En el write path, `ncmInsert` y `ncmUpdate` son la ÚNICA implementación de tres responsabilidades críticas:

1. `_routeToJsonb` — rutea campos demoted (`itemTaxExcluded`, `contactAddress`, `settingName`, etc.) al JSONB correspondiente (`data`/`config`/`meta`).
2. `generateUuidV7` — auto-genera la PK UUID si no la trae.
3. Merge no-destructivo en UPDATE — `COALESCE(col, '{}') || ?::jsonb` en vez de pisar el blob.

`Punto\App\Database\Query::insert`/`update` DEBEN delegar a `ncmInsert`/`ncmUpdate`. **Nunca reimplementar el routing JSONB en la capa PSR-4** — divergencia silenciosa = `column "fieldname" does not exist` en runtime.

**TRAMPA — tabla nueva debe registrarse en `_getTableSchema()`**: `ncmInsert` llama a `_getTableSchema($table)` para saber la PK y la columna JSONB de cada tabla. Si una tabla nueva NO está en ese mapa, `ncmInsert` defaultea PK a `'id'` (inexistente) y no rutea campos al JSONB → 422 con body vacío en runtime. Al crear una tabla nueva (migración), agregar su entrada en `_getTableSchema()` en el mismo commit. Ejemplo: `itemSold` faltaba → `/purchase` rompía con error vacío (fix en commit `e91be08`).

---

## §37 — BFF same-origin en panel-next

El `api-client.ts` del browser usa baseURL `/api` (same-origin). NUNCA configura URL externa del backend PHP directamente desde el browser — eso requeriría CORS y expone la URL interna.

`panel-next/app/api/v1/[...path]/route.ts` es el catch-all que actúa como BFF: agrega cookie `_jwt_panel` y forwardea a `NEXT_PUBLIC_API_URL`/`API_URL`. Cálculo/reshape va en los route handlers de Next.js; la API PHP devuelve datos crudos.

Ante errores de mutación en panel-next, primer lugar a revisar: el catch-all (path mal reescrito o cookie no propagada).

---

## Convenciones obligatorias del rewrite panel-next

### shadcn obligatorio (sobre HTML nativo)

Prohibido `<table>`, `<img>`, `<label>`, `<button>`, `<input>` etc. cuando hay primitive shadcn equivalente. Aplica a TODA la UI nueva. Date range = `Calendar`+`Popover`. Phone = `InputGroup`+`DropdownMenu`+libphonenumber. Nunca custom widgets ni libs alternativas.

### MoneyInput obligatorio para campos de moneda

Todo monto `$` usa `<MoneyInput>` (`panel-next/components/ui/money-input.tsx`) — respeta `bootstrap.thousand`/`decimal` del tenant. NUNCA `<Input type="number">` para precio/costo/descuento/total/monto.

### Listados = DataTable

Todo listado usa el `<DataTable>` reusable (TanStack Table + shadcn) con search/sort/date-range/export XLSX/column-toggle. Nunca tablas one-off.

### Forms = react-hook-form + Zod

Estado y validación con react-hook-form + Zod. No estado ad-hoc.

### §36 — Jerarquía visual de formularios

Títulos de sección: `text-base font-semibold tracking-tight` + `border-b`. `FormLabel` individual: `text-sm font-medium`. **No invertir**. Componente canónico: `<FormSection>` en `panel-next/components/forms/form-section.tsx` — todo agrupamiento de campos lo usa, nunca markup ad-hoc.

### §38 — Anti-patrón: gate `status !== "idle"` en TanStack Query + Zustand

En hooks que combinan TanStack Query + store Zustand, poner `if (status !== "idle") return` bloquea re-hidratación al cambiar de contexto (ej. cambiar de caja) — el store queda con datos stale.

```tsx
// BIEN — re-hidrata cuando data cambia
useEffect(() => {
  if (!data) return
  seed(data)
}, [data])
```

El gate `status !== "idle"` solo aplica a fixtures/mocks (evitar que el mock sobreescriba datos reales).

### §39 — Patrón `CustomContent` + `onSelect` para sidebar+content

Para UIs "sidebar de items + area de contenido a la derecha" (Settings macOS / menú POS), cada sección del tipo acepta dos extensiones opcionales:

```tsx
interface MenuSection {
  id: string
  label: string
  icon: React.ComponentType
  CustomContent?: React.ComponentType<{ onClose: () => void }>
  onSelect?: (ctx: { setOpen, router, activeRegisterId }) => void
}
```

- `onSelect` presente → click ejecuta acción, NO muestra content area.
- `CustomContent` presente (sin `onSelect`) → click muestra `<CustomContent>` en lugar del panel default.
- Ninguno → default (descripción + CTA).
- Sin sección seleccionada → empty state. Nunca auto-seleccionar la primera.

Primer uso: `panel-next/components/register/pos-main-menu.tsx`.

---

## §40 — PostgreSQL fold-to-lowercase y el wrapper DB

### §40.1 — PG lowercasea identificadores sin quotes

Todo identificador SQL sin comillas dobles (columnas, tablas, aliases) es lowercaseado por PG antes de ejecutar. Las migraciones y queries deben usar lowercase o quotes explícitas — nunca camelCase sin quotes en DDL.

Correcto:
```sql
CREATE UNIQUE INDEX uidx_drawer_register_open ON drawer (registerid) WHERE status = 'open';
```
Incorrecto (PG lo lowercasea silenciosamente):
```sql
CREATE UNIQUE INDEX uidx_drawer_register_open ON drawer (registerId) WHERE status = 'open';
```

### §40.2 — NO usamos ADOdb — solo wrapper PDO propio

El proyecto usa `class DB` en `app/includes/lib/DB.php` (wrapper PDO con API inspirada en ADOdb). **No es ADOdb**. No atribuirle comportamiento de ADOdb ni parchear ADOdb.

La única superficie de DB válida es:
- `ncmExecute` / `ncmInsert` / `ncmUpdate` (helpers globales del legacy)
- `Punto\App\Database\Query` (PSR-4, delega a los helpers)

### §40.3 — `CaseInsensitiveArray` resuelve el case-mismatch PG

El wrapper devuelve filas como `CaseInsensitiveArray` para que accesos camelCase (`$row['contactId']`) funcionen aunque PG retorne `contactid` lowercase.

**TRAMPA**: si un service hace `foreach ($rs->fields as $k => $v) { $arr[$k] = $v; }` para construir un array plano, **pierde `CaseInsensitiveArray`** y los accesos camelCase rompen silenciosamente.

Correcto:
```php
// Preservar CaseInsensitiveArray al aplanar
$arr = new CaseInsensitiveArray(iterator_to_array($rs->fields));
```

Este bug afectó `ContactAnalyticsService::fetchAll` (fix en commit `85a28fd`).

---

## §42 — Dos tokens JWT en el browser del operador (sprint 2026-06-20)

El browser de un operador logueado lleva **dos** cookies JWT simultáneas con propósitos distintos:

| Cookie | Emite | TTL | Realm / claim `iss` | Propósito |
|--------|-------|-----|---------------------|-----------|
| `_jwt_panel` | `panel/includes/functions.php` | 24h | `'panel'` | Sesión del operador en el panel React |
| `_jwt` | `app/API/auth.php` | 10 años | `'pos-app'` | Device pairing de la caja POS |

El catch-all BFF (`panel-next/app/api/v1/[...path]/route.ts`) forwardea **solo** `_jwt_panel`. Los endpoints `apiAuthTenant(['pos-app'])` leen `_jwt`. Un `POST /v1/logout` del panel borra solo `_jwt_panel` (cookie `HttpOnly`, borrado server-side); la sesión POS no se toca. Ver §28 y context/16.

---

## §43 — Agente IA: alcance acotado, confirmToken y débito atómico (sprint 2026-06-21)

El agente IA usa **OpenRouter** como gateway (no SDK Anthropic). Modelo default: `deepseek/deepseek-chat-v3-0324`.

- **13 tools**: 5 de lectura (sin confirmación) + 8 de escritura con `confirmToken` UUID de 60s de vida real (3 capas de defense-in-depth: generación server-side → persistencia Zustand → revalidación antes del execute).
- **Débito de créditos**: `/v1/ai/debit` atómico con `SELECT FOR UPDATE` sobre `company.aiCreditsBalance`. Gate 402 si saldo insuficiente antes de llamar al modelo.
- **Historial de chat**: Zustand persist en localStorage. El hidratador usa patrón `useChatHistoryHydrated` con `onFinishHydration` porque la hidratación de Zustand persist es async y no puede bloquearse con el render síncrono del componente.
- **Prohibido** invocar la AI SDK de Anthropic para este agente — toda la lógica del agente pasa por OpenRouter (`api/agent/chat` route handler con AI SDK v6 OpenAI-compatible).

---

## §44 — PG column casing: tablas legacy lowercase vs tablas nuevas camelCase quoted

Las tablas del schema legacy (`outlet`, `item`, `stock`, `transaction`, `taxonomy`, `contact`, `device`, `register`, `company`, `cpayments`, `itemSold`, `itemLocation`, `toLocation`, etc.) tienen sus columnas almacenadas en **lowercase sin quotes** en el catálogo PG (`outletid`, `companyid`, `itemid`, etc.).

**Regla para services nuevos que tocan tablas legacy**: usar las columnas **sin quotes y en lowercase** en las queries.

```php
// Correcto — tabla legacy, columna sin quotes
WHERE outletid = :outletId AND companyid = :companyId

// Incorrecto — PG falla con "column outletId does not exist"
WHERE "outletId" = :outletId AND "companyId" = :companyId
```

**Tablas nuevas** (creadas desde 2026-06-23: `inventory_count`, `inventory_count_item`, `stock_transfer`, `stock_transfer_item`) usan **camelCase con quotes** porque el DDL las define así explícitamente.

Causó bug en prod con 7 services nuevos (fix en commit `7a59ac2`): `StockAdjustmentService`, `StockTransferService`, `InventoryCountService`, `ReturnService`, `LocationTaxonomyService`, `VariantService`, `ItemService::applyVariantRules`. Las validaciones de tenant fallaban silenciosamente devolviendo "outletId inválido".

## §46 — PG BOOLEAN vs int/string — cluster de bugs 2026-06-24

Las columnas BOOLEAN de tablas legacy (`transactioncomplete`, `itemcansale`, `itemtrackinventory`, etc.) **no aceptan `0/1` ni `"0"/"1"` en ningún contexto**. El error es silencioso en WHERE (devuelve 0 filas) o explícito en SET/INSERT (type mismatch). Reglas:

- **SQL inline**: usar `TRUE` / `FALSE` o `IS TRUE` / `IS NOT TRUE`.
  ```sql
  WHERE itemtrackinventory IS TRUE   -- correcto
  WHERE itemtrackinventory >= 1      -- incorrecto — SQL error silente
  WHERE transactioncomplete = TRUE   -- correcto
  WHERE transactioncomplete = 1      -- incorrecto — pg error
  ```
- **PHP AutoExecute / ncmInsert arrays**: usar `true` / `false` PHP (el wrapper los serializa a `TRUE`/`FALSE`).
  ```php
  'itemCanSale' => true   // correcto
  'itemCanSale' => 1      // incorrecto — falla en INSERT
  ```
- **`UPDATE … SET col = ?`**: el bind de PDO con `1` sobre columna BOOLEAN puede fallar según driver. Usar `TRUE`/`FALSE` inline en el string SQL.

Causó 7 bugs en sprint retail 2026-06-23/24 (CreditPayment, VPayment, reports, PurchasesService, TransactionsService, Customer.php, functions.php). Fix en commits `5044ecf`, `df66e37`.

---

## §45 — Redis AUTH en `wsPublish` (infra crítica)

`app/includes/ws_publish.php` parsea el `REDIS_URL` para extraer `user` y `pass`, y pipelinea `AUTH <pass>` antes de `PUBLISH`. Sin esto, prod tiene realtime completamente mudo porque el Redis de Coolify requiere autenticación.

Si el realtime deja de funcionar en prod, verificar primero: (a) que `REDIS_URL` incluya el password (`redis://:pass@host:port`), (b) que `ws_publish.php` esté parseando correctamente con `parse_url`. Fix aplicado en sprint 2026-06-23.

---

## §41 — `ncmExecute` con `forceObj: true` devuelve RECORDSET, no array

Cuando `ncmExecute($db, $sql, $params, forceObj: true)` se usa para evitar el aplanado JSONB de `§18`, el valor de retorno es un **objeto RECORDSET** (DB wrapper interno), NO un array PHP.

- Acceder con `$rs->fields['columna']` para la fila actual.
- Iterar con `while (!$rs->EOF) { ... $rs->MoveNext(); }`.
- Nunca tratar ese retorno como `array` ni hacer `foreach ($rs as ...)` directamente.

Patrón correcto de referencia: `api/lib/Reports/UsersService.php:39`.

Infringir esta convención produce output vacío silencioso (ítems/asociaciones que no aparecen). Causó regresiones en dos sub-agentes durante el detalle de venta (fix 8cc54e7).
