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

### §12.2 — Toda mutación del tenant se gatea con `hasPermission()` (REGLA ABSOLUTA)

**Una clave en `PermissionCatalog` sin gate es un bug de seguridad**, no una
feature pendiente. El panel muestra el catálogo completo en la pantalla de
roles: una clave sin enforcement le miente al admin — cree que revocó algo y
no revocó nada.

Todo endpoint de `/v1` que MUTE datos del tenant chequea permiso antes de
mutar. Las lecturas se gatean con la clave `.view` de su familia cuando
existe; cuando no existe (impuestos, plantillas, cajas) la lectura queda
abierta a cualquier sesión del tenant y se documenta en el archivo por qué.

Forma canónica, idéntica en todos lados — mismo texto, mismo código:

```php
if (!hasPermission('x.y.z')) {
    apiError('No tenés permiso para esta acción (requiere: x.y.z)', 403);
}
```

Cuatro cosas que se aprendieron cerrando el agujero de 25 claves sin gate
(2026-08-22) y que valen para el próximo endpoint:

1. **El gate va ANTES de todo lo demás, incluido el 404.** Si el "no existe"
   sale primero, el endpoint es un oráculo de existencia: sin permiso, un id
   real devuelve 404 y uno inventado 403. Cuando el permiso depende de la
   fila (ej. `/v1/contacts`, donde la clave sale del `type` del contacto), se
   resuelve el type, se gatea con el default si no hay fila, y recién
   después se responde 404.
2. **Un endpoint que despacha por sub-recurso gatea arriba, una sola vez**,
   con un resolver `(método, recurso) → clave` — no con un `if` por rama.
   `items.php` tiene ~20 ramas en 800 líneas: con un gate por rama, la
   próxima rama nace sin gate. Ver `itemsRequiredPermission()`.
3. **El permiso pertenece al RECURSO, no al endpoint.** Si dos endpoints
   tocan la misma fila, exigen la misma clave. `/v1/contacts` no filtra por
   `type` en `getById`/`update`/`archive`, así que edita empleados igual que
   clientes: sin exigir `contacts.user.*` para `type=0` era un bypass del
   gate de `/v1/users`.
4. **Gestionar usuarios no es poder escalar.** Un permiso de gestión de
   equipo sin regla anti-escalación no significa nada: el que lo tiene se
   asigna el rol de arriba. La regla es comparar SETS de permisos (no
   nombres ni slugs, así vale para roles custom): nadie crea, asigna, edita
   ni desactiva un rol con permisos que él no tiene, y nadie cambia su
   propio rol.

**Realm `pos-app`:** hoy la sesión del device se emite con `roleId = '1'`
(`DeviceAuth::buildToken`), que `RoleService` resuelve al seed `owner` — o
sea que en ese realm `hasPermission()` devuelve true para TODO. Los gates de
endpoints multi-realm son por eso efectivos solo para el realm `panel`.
Ponerlos igual es correcto y no rompe la caja; empiezan a discriminar solos
cuando el POS tenga sesión de operador. Lo que NO se gatea nunca es la venta
emitida (`pos.sale.create`, `pos.discount.apply`): offline-first manda y el
back no rechaza un documento que la caja ya imprimió.

El arnés `api/tests/permission_enforcement_test.php` falla si aparece una
clave del catálogo sin gate, así que esto se sostiene solo.

---

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
// BIEN — forceObj devuelve el recordset del wrapper sin aplanar
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

## §31 — Teléfonos: storage SIN `+`, libphonenumber para conversión (REGLA ABSOLUTA)

1. **Storage en DB**: SIN `+` (`595991234567`, no `+595991234567`). Helpers canónicos: `phoneValidateForStorage($input, $iso)` valida + normaliza; `normalizePhoneForStorage($e164)` strip `+`. Ambos en `app/includes/phone.php`. Mig 67 hizo cleanup de phones legacy con `+` en `contact.contactPhone` y `outlet.data`. **Anterior §31 decía "E.164 con +"** — esa regla fue revertida (2026-06-29).
2. **Display al usuario**: SIEMPRE nacional vía `phoneFormatNational()`.
3. **Validación/parsing**: SIEMPRE libphonenumber. Backend: `phoneToE164($input, $iso)` en `panel/includes/phone.php` y `app/includes/phone.php`. Frontend: `window.libphonenumber.parsePhoneNumberFromString(input, iso)`.
4. **PROHIBIDO** concatenar `+`, `0` o código de país a mano (`'+595' + phone`).
5. El ISO viaja con el phone en el wire: `{phone: '595991234567', iso: 'PY'}` (sin `+`). El backend normaliza con `phoneValidateForStorage()`.

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

**Ejemplo mixto vigente**: `GET /v1/price_list` acepta `['pos-app', 'panel']` (el POS usa este endpoint con su JWT de device para mostrar el dialog "Lista de precios"). Las mutaciones (`POST`, `PUT`, `DELETE`) de price_list siguen siendo solo `['panel']`.

BFF panel → /api: base `'shared'` + `Authorization: Bearer <_jwt_panel>` (no cookie — coexiste con `_jwt` del POS en el mismo browser).

Resolución de outlet por realm (view-scope panel vs `outletScope` device) y qué datos son por-sucursal vs por-tenant: doc canónico `context/25-sucursales-y-scopes.md`.

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

## §37 — BFF same-origin en frontend

El `api-client.ts` del browser usa baseURL `/api` (same-origin). NUNCA configura URL externa del backend PHP directamente desde el browser — eso requeriría CORS y expone la URL interna.

`frontend/app/api/v1/[...path]/route.ts` es el catch-all que actúa como BFF: agrega cookie `_jwt_panel` y forwardea a `NEXT_PUBLIC_API_URL`/`API_URL`. Cálculo/reshape va en los route handlers de Next.js; la API PHP devuelve datos crudos.

Ante errores de mutación en frontend, primer lugar a revisar: el catch-all (path mal reescrito o cookie no propagada).

---

## Convenciones obligatorias del rewrite frontend

### shadcn obligatorio (sobre HTML nativo)

Prohibido `<table>`, `<img>`, `<label>`, `<button>`, `<input>` etc. cuando hay primitive shadcn equivalente. Aplica a TODA la UI nueva. Date range = `Calendar`+`Popover`. Phone = `InputGroup`+`DropdownMenu`+libphonenumber. Nunca custom widgets ni libs alternativas.

### MoneyInput obligatorio para campos de moneda

Todo monto `$` usa `<MoneyInput>` (`frontend/components/ui/money-input.tsx`) — respeta `bootstrap.thousand`/`decimal` del tenant. NUNCA `<Input type="number">` para precio/costo/descuento/total/monto.

### Listados = DataTable

Todo listado usa el `<DataTable>` reusable (TanStack Table + shadcn) con search/sort/date-range/export XLSX/column-toggle. Nunca tablas one-off.

### Forms = react-hook-form + Zod

Estado y validación con react-hook-form + Zod. No estado ad-hoc.

### §36 — Jerarquía visual de formularios

Títulos de sección: `text-base font-semibold tracking-tight` + `border-b`. `FormLabel` individual: `text-sm font-medium`. **No invertir**. Componente canónico: `<FormSection>` en `frontend/components/forms/form-section.tsx` — todo agrupamiento de campos lo usa, nunca markup ad-hoc.

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

Primer uso: `frontend/components/register/pos-main-menu.tsx`.

---

## §40 — BFF routes POS usan `bffProxy()` con `requireBearer:true` (2026-06-28)

Todo route handler de Next.js bajo `/api/pos/*` DEBE usar el wrapper `lib/bff/proxy.ts`:

```ts
import { bffProxy } from "@/lib/bff/proxy"
export const GET = bffProxy({ requireBearer: true })
```

`requireBearer:true` extrae el token de `punto.device.token.pos` del header `Authorization` y lo reenvía al backend PHP. Sin este wrapper, la llamada llega sin token y la API retorna 401. **Nunca reimplementar el forward de Bearer en un route handler individual.**

---

## §41 — `RecordsetIterator` garantiza `fields` siempre como array (2026-06-28)

`ncmExecute(forceObj:true)` retorna un `DBResult` (no `\ADORecordSet`). Siempre iterar con `RecordsetIterator`:

```php
use Punto\App\Database\RecordsetIterator;
$rs = ncmExecute($sql, $params, true);
foreach (new RecordsetIterator($rs) as $row) { ... }
```

Nunca usar `while(!$rs->EOF)` directo ni castear `(array)$rs` — serializa propiedades privadas. `iterator_to_array($rs)` tampoco aplana correctamente.

---

## §42 — `flattenJsonb` + `ncmExecute` retornan `CaseInsensitiveArray`; CIA canónica = `api/includes/lib/DB.php` (2026-06-30)

`Query::flattenJsonb` y `ncmExecute` retornan `CaseInsensitiveArray` — **no** un array plano. Fix arquitectónico 2026-06-30 (`c9964dd6`): el return type de `_flattenJsonb` se corrigió a `CaseInsensitiveArray`; los 9 métodos `present`/`shape`/`pick` se ampliaron a `array|\CaseInsensitiveArray`.

**CIA canónica** = la clase definida en `api/includes/lib/DB.php`. **PROHIBIDO** duplicarla en otro archivo — el sub-agente de 2026-06-30 creó una clase homónima que no satisfacía los typehints existentes → `TypeError` en prod.

**`GetRow` / `GetOne`** — no existían en el wrapper; se agregaron en commit `671d6a41`. Callers que hacían `$db->GetRow(...)` recibían un 500 silente (undefined method). Si un service llama a un método de DB que no existe, verificar `api/includes/lib/DB.php` antes de parchear el caller.

Los SELECTs en tablas legacy deben usar aliases con quotes para preservar camelCase (PG lowercasea sin quotes):

```sql
SELECT "itemName" AS "itemName", "priceListId" AS "priceListId" FROM item ...
```

**Primer caso afectado por el patrón sin quotes**: `PriceListService` (commit `223b39ac`).

---

## §43 — localStorage device token namespaced por module (2026-06-28)

Los tokens de device se guardan en localStorage con namespace por module:

| Module | Key |
|--------|-----|
| POS (cajero) | `punto.device.token.pos` |
| Screen (pantalla cliente) | `punto.device.token.screen` |

Antes había un solo key `punto.device.token` — si el mismo browser tenía dos devices de tipos distintos, se pisaban. **Nunca leer/escribir el key sin el sufijo de module.**

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

### §40.2 — Capa de DB: wrapper PDO propio, sin librerías externas

El proyecto tiene UNA sola capa de acceso a DB: `class DB` en
`api/includes/lib/DB.php`, wrapper PDO propio sobre PostgreSQL. **No hay
ORM, no hay librería externa** — la API pública (Execute/AutoExecute/
GetAssoc/StartTrans/...) son nombres heredados del wrapper legacy que
precedió a esta clase, no de ninguna dependencia. Cualquier comportamiento
raro de la DB se diagnostica y se arregla en este wrapper, no atribuyéndolo
a una librería que no está.

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
| `_jwt_pos-device` | `app/Api/DeviceAuth.php` (PSR-4) | 10 años | `'pos-app'` + claim `did` | Device pairing de la caja POS (frontend) |

El catch-all BFF (`frontend/app/api/v1/[...path]/route.ts`) forwardea **solo** `_jwt_panel`. Los endpoints `apiAuthTenant(['pos-app'])` leen `_jwt_pos-device`. Un `POST /v1/logout` del panel borra solo `_jwt_panel` (cookie `HttpOnly`, borrado server-side); la sesión POS no se toca. Logout del POS solo desde Ajustes → "Eliminar dispositivo del comercio". Ver §28 y context/16.

**PIN del cajero (2026-06-25):** el lockscreen del POS usa SHA-256 del PIN en `localStorage`, **no bcrypt**. Razón: el PIN es identificación del cajero (quién vendió qué), no seguridad de acceso — un cajero que piratea su propio hash de PIN tiene acceso físico a la caja de todas formas. `Web Crypto API` (`crypto.subtle.digest("SHA-256", ...)`) en el cliente; comparación local sin roundtrip. No cambiar a bcrypt sin decisión explícita del owner.

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

## §52 — Realtime: todo mutante bajo `/v1/` publica por default (2026-08-15)

`realtimeAfterMutation()` (`api/bootstrap.php`) es INVERTIDO: cualquier POST/PUT/PATCH/DELETE bajo `/v1/` que pase por `apiAuthTenant()` publica un evento de invalidación, salvo que el endpoint esté en la allowlist explícita `$excluded`. El nombre de la `entity` sale del primer segmento del path, singularizado (`deriveEntityFromPath`/`singularizeSegment`) — el array `$overrides` es solo para los casos donde el path no alcanza (alias semántico, scope distinto de `'all'`, `skipResources`).

**No agregues un endpoint nuevo a ninguna lista para que publique — ya publica solo.** Solo tocás el mapa si necesitás un override (entity/scope) o una exclusión explícita.

- Match de prefijo por SEGMENTO completo (`endpointMatches()`), no `str_starts_with` crudo — evita colisiones tipo `/v1/orders` vs `/v1/orders-core`.
- Endpoints POS que autentican con `apiAuthPosContext()` (no `apiAuthTenant()`) — `sales.php`, `transactions.php`, `parked-sales.php`, `screens.php`, `offline-sync.php`, `numbering/lease.php`, `unpair-pos-device.php` — NUNCA pasan por `realtimeAfterMutation()`. Si mutan algo que el resto del tenant necesita saber, el publish tiene que ser explícito en el endpoint o el Service (patrón `CreditPaymentService::allocate()` / `SaleService::save()` / `transactions.php` void-status-reject-delete-itemDeletion).
- Stock: el publish de `item` vive ÚNICO en `Inventory::manageStock()` (`api/lib/App/Domain/Inventory.php`), con dedup por request (`static bool $stockEventPublished`) — un caller de `manageStock()` NUNCA debe publicar `item` por su cuenta.
- `realtimePublish()` acepta `$companyId` explícito (5º parámetro) — usalo cuando el caller ya resolvió su companyId por otra vía (`manageStock()` con `ops['companyId']` para jobs/CLI), la constante global `COMPANY_ID` puede estar vacía o ser de otro tenant en ese contexto.
- Frontend: `ENTITY_TO_QUERY_KEYS` (`frontend/hooks/use-realtime-sync.ts`) es best-effort — un `entity` sin mapear no rompe nada, solo tira `console.warn` en dev. Sumale el queryKey cuando exista un hook que lo consuma.
- Ver `context/15-realtime-sync-plan.md` y el arnés `api/lib/Sales/verify_chain/verify_realtime.php`.

---

## §41 — `ncmExecute` con `forceObj: true` devuelve RECORDSET, no array

Cuando `ncmExecute($db, $sql, $params, forceObj: true)` se usa para evitar el aplanado JSONB de `§18`, el valor de retorno es un **objeto RECORDSET** (DB wrapper interno), NO un array PHP.

- Acceder con `$rs->fields['columna']` para la fila actual.
- Iterar con `while (!$rs->EOF) { ... $rs->MoveNext(); }`.
- Nunca tratar ese retorno como `array` ni hacer `foreach ($rs as ...)` directamente.

Patrón correcto de referencia: `api/lib/Reports/UsersService.php:39`.

Infringir esta convención produce output vacío silencioso (ítems/asociaciones que no aparecen). Causó regresiones en dos sub-agentes durante el detalle de venta (fix 8cc54e7).

---

## §47 — Migraciones con índices parciales o CHECK constraints sobre la columna alterada

Si una migración hace `ALTER COLUMN <col> TYPE ...` sobre una columna que tiene **índices parciales** o **CHECK constraints** que la referencian en su predicado, PG falla con `operator does not exist` al arrancar el container — el índice sigue intentando comparar el valor con el tipo antiguo.

**Regla:** toda migración que cambie el tipo de una columna DEBE buscar y dropear+recrear cualquier índice parcial o CHECK que mencione esa columna en su predicado o expresión.

```sql
-- Antes del ALTER
DROP INDEX IF EXISTS idx_contact_phone_tenant_unique;

-- El ALTER
ALTER TABLE contact ALTER COLUMN role TYPE varchar(64);

-- Recrear con el tipo nuevo
CREATE UNIQUE INDEX idx_contact_phone_tenant_unique
  ON contact ("contactPhone", companyid)
  WHERE type = 0
    AND role IN ('0','1','2','7')    -- ← strings, no ints
    AND "contactPhone" <> '';
```

Causó incidente prod 2026-06-25 (commit `14d5347`): `idx_contact_phone_tenant_unique` tenía predicado `role = ANY(ARRAY[0,1,2,7]::int[])`. El container crasheaba al arrancar → Coolify rollback silencioso → 3h de fixes backend sin deployar. **Siempre buscar con** `\d <table>` o `pg_indexes WHERE tablename=...` antes de alterar una columna.

---

## §48 — `getPaymentMethodName()` acepta keys de ambos sistemas

La función `getPaymentMethodName(key)` en `frontend/lib/pos/payments.ts` maneja dos formatos de keys simultáneamente — legacy (`cash`, `creditcard`, `debitcard`) y frontend POS (`efectivo`, `tcredito`, `tdebito`) — vía tabla de aliases interna. También tiene guard para string vacío (devuelve `''` en vez de undefined).

Del mismo modo, `getSingle` de payments acepta dos shapes: `{type, price, extra, UID}` (legacy) y `{name, total}` (POS frontend). No asumir que los payments vienen en un solo formato — la BD mezcla ambos hasta que el POS legacy sea deprecado.

---

## §59 — Los hotkeys del POS se configuran en la caja, nunca en el panel

La grilla de accesos rápidos es de la caja y se edita ahí. El panel **no** la
edita: solo muestra una columna de diagnóstico con los hotkeys huérfanos
(`registers-tab.tsx`, a raíz del incidente del 2026-08-18).

Decisión del owner (2026-08-23), al zanjar la pregunta de qué pasa si el panel
y la caja editan los hotkeys a la vez estando la caja sin conexión: **el
conflicto no debe existir**. Quien arma la grilla es quien atiende con ella
delante, y la edición offline con sincronización posterior solo es coherente si
hay un único editor.

Corolario: no agregar edición de hotkeys al panel. Si aparece el pedido,
primero hay que resolver el modelo de merge de una grilla completa editada en
dos lugares — que es exactamente el problema que esta decisión evita.

---

## §58 — Toda regla que bloquea es opcional y configurable por comercio

Punto lo usan negocios muy distintos. Una regla imprescindible para un
restaurante con diez mozos es, para un kiosco de una persona, una molestia que
le hace perder ventas. **Ninguna restricción operativa se activa sola para
todos: nace apagada, o con un interruptor visible.**

Decisión del owner (2026-08-23), a raíz de la exclusividad de mesas por mozo,
que se encendía automáticamente al asignar un mozo — un local que solo quería
el dato para reportes se comía el bloqueo.

Ya siguen la regla, y son el patrón a copiar: `controlCaja` (no todos hacen
arqueo), `blindControl`, `modoSoloOrdenes`, `settingReturnRefund`,
`settingReturnAllowIngredientReversal`, `settingPeriodCloseMonths`.

Deuda conocida, a saldar cuando se toquen: la **exclusividad de mesas por
mozo** y el **lockscreen del POS** (un negocio de un solo usuario no necesita
PIN).

Cómo aplicarla:

- El default es el comportamiento MENOS restrictivo. Excepción: cuando lo
  exige la ley o la integridad del dato — el guard de cierre de período o la
  cuadratura de una venta no bloquean por política, impiden un dato inválido, y
  esos no se apagan.
- El interruptor vive donde ya viven sus hermanos: config de la caja
  (`register.data`, vía `RegisterAdminService`) si es por caja, o
  `company.config` si es por comercio. No inventar un tercer lugar.
- Si la regla se enforcea en el backend, el flag también se lee en el backend.
  Un interruptor que solo esconde el botón no es un interruptor.

---

## §56 — El timbrado NO es obligatorio para operar; sí lo es con facturación electrónica

Una caja puede vender sin timbrado cargado. Hay comercios que facturan con
talonario manual o con preimpresos, y el POS tiene que dejarlos operar: nunca
se bloquea una venta por falta de configuración fiscal.

En cambio, **si el módulo de facturación electrónica está activo, el timbrado y
toda la configuración fiscal de la caja pasan a ser obligatorios** — sin eso no
se puede emitir un documento válido ante la SET.

Estado del código (verificado 2026-08-22, es el comportamiento correcto — no
"arreglarlo"):

- `SaleService::resolveFrozenInvoiceAuth()` devuelve `[null, null, null]` cuando
  la caja no tiene timbrado y la venta sigue su curso.
- El form de cajas no marca el timbrado como campo requerido.
- `EInvoiceProvisioningService` aborta si ninguna caja activa tiene el timbrado
  completo, y `SaleToInvoiceMapper` exige timbrado vigente cacheado.

Regla del owner (2026-08-22). Si alguna vez aparece un pedido de "validar
timbrado al abrir caja" o "no dejar vender sin timbrado", va contra esto salvo
que el tenant tenga facturación electrónica activa.

---

## §57 — El formato de impresión se configura en Ajustes, nunca se elige en Caja

Tamaño de papel (A4, oficio, rollo 80mm), márgenes y posicionamiento son
configuración de la **plantilla**, y se definen en Ajustes. En Caja se imprime
y nada más: no hay selector de formato antes de imprimir.

Decisión del owner (2026-08-22), al rechazar el pedido "formatos A4 y Oficio
elegibles en Caja" (`10-roadmap.md`, tanda 2026-08-18 punto 5). Dos razones que
ya son invariantes del proyecto:

- **Lo que se imprime lo decide la plantilla.** Si el bloque está en la
  plantilla sale; si no, no. Nada de decidir el documento en el momento de
  imprimir.
- **El POS tiene posiciones estables.** Un paso extra en el camino de impresión
  rompe la memoria muscular del cajero, que es el criterio con el que se diseña
  la caja.

Lo que sí es trabajo válido es el editor de layout para hoja completa
(distinto del ticket de 80mm) — pero vive en Ajustes, no en Caja.

---

## §49 — Print previews: hoja blanca hardcoded (excepción al design system)

Los previews de impresión (cotización, factura, recibo) usan `background: #ffffff` y texto oscuro **hardcoded**, no tokens del design system. Esto es una excepción válida documentada en `context/20-design-system.md §4.12`:

- El preview debe verse igual en dark mode y light mode (simula papel físico).
- Tokens CSS varían según el tema del usuario; un preview oscuro sobre modal oscuro es ilegible.
- No "arreglar" esto aplicando `bg-background` o `dark:bg-gray-900` — es correcto por diseño.

El patrón canónico es `className="bg-white text-gray-900 p-5"` dentro del dialog.

---

## §50 — Roles y permisos: `RoleService` + `PermissionCatalog` (sprint 2026-06-25)

El sistema de roles vive en la tabla `taxonomy` con `type = 'role'` (metadata del rol) y `type = 'roleData'` (asignaciones usuario→rol). No hay tabla separada `role`.

- **`PermissionCatalog`** (`api/lib/Auth/PermissionCatalog.php`): source of truth de los 43 permisos esenciales agrupados por módulo. Cada permiso es un string `'module.action'` (ej. `'pos.view'`, `'contacts.edit'`).
- **`RoleService`** (`api/lib/Auth/RoleService.php`): CRUD + `getPermissions(companyId, roleId)` con cache por-request. Resuelve tanto role IDs legacy (int) como UUID.
- **`hasPermission(user, 'module.action')`** (`frontend/lib/auth/permissions.ts`): helper global para UI; lee `user.permissions[]` expuesto por `/v1/bootstrap`.
- **3 seed roles por tenant** al crear empresa: `Dueño` (todos los permisos), `Encargado` (sin billing/admin), `Cajero` (pos + operación básica). Custom roles disponibles vía UI `/settings/roles`.
- **Sidebar filtering**: el sidebar del panel filtra links por `hasPermission()`. Si `user.permissions[]` llega vacío, el sidebar queda en blanco — verificar que bootstrap exponga el array correctamente.

---

## §51 — Convención de timestamps: tenant-local naive, NO UTC (2026-06-30)

Los timestamps se guardan en la TZ del tenant (`America/Asuncion`) pero **sin información de offset** — son "naive" (el servidor no los convierte a UTC). **No** aplica "server siempre UTC".

- **Writes** de venta/caja: usar helper `tenantNow($timezone)` (obtiene la TZ desde bootstrap). La `timezone` se expone en `/v1/bootstrap` desde el commit `39754189`.
- **Display**: usar helper `parseNaive` en el front — trata el valor como wall-clock sin re-convertir TZ. NO usar `new Date(str)` ni `toLocaleDateString` que asumen UTC o la TZ del navegador.
- **Consecuencia**: `ORDER BY createdAt` funciona correctamente (naive local es monotónico dentro del tenant). Comparar timestamps entre tenants de distintas TZ requiere normalización explícita.

Bug que originó la decisión: `drawerOpenDate` se guardaba en hora local pero se comparaba contra timestamps UTC → las ventas de la sesión quedaban fuera del resumen de caja (commit `29439221`).

---

## §53 — El backend NUNCA rechaza una venta ya emitida; valida al emitir, no al recibir (regla base, owner 2026-08-16)

Textual del owner: *"No podés rechazar una venta en el backend. Esa venta ya se emitió, se validó y se imprimió; el backend solo la guarda a ese punto. El POS tiene que tener el 100% de la data básica almacenada localmente y esa es la fuente única de verdad para el POS."*

**La distinción que importa — emisión vs. estado compartido:**

- **Emisión** (facturas, recibos, remisiones, órdenes, comandas) depende SOLO del dispositivo y la impresora → tiene que funcionar 100% offline, y su validación de reglas de negocio ocurre **al emitir, contra el cache local del POS** (bootstrap + deltas de `context/43-sync-incremental.md`). Ejemplo: `isCreditable` de un cliente viaja en el cache (`reshapeCustomer`, `frontend/lib/pos-bff/reshape.ts`) y el gate real vive en `pay-dialog.tsx` — bloquea el CTA antes del round-trip, funcione o no la conexión.
- **Estado compartido** (mesas, órdenes entre cajas, numeración exclusiva) requiere sincronización con otras cajas/el server → ahí sí se puede bloquear sin internet, porque la verdad no vive solo en este dispositivo.

**Consecuencia directa para el backend:** `SaleService::save()` (y cualquier endpoint POS que reciba una venta ya emitida — `sales.php`, `offline-sync.php`) **guarda, no rechaza** por reglas de negocio que el POS ya debió validar al emitir. Si el backend tira una excepción de negocio ahí, una venta encolada offline (mercadería ya entregada, comprobante ya impreso) se pierde al reconectar — el cliente se fue, no hay forma de deshacer la entrega. Eso es peor que el bug que se intentaba cerrar.

- **Lo que SÍ valida el backend siempre:** integridad y seguridad — que el `clientId`/`itemId`/etc. exista y pertenezca al tenant (anti-IDOR), que la sesión/token sea válida, que no haya duplicado (`transactionUID`). Eso no es una regla de negocio sobre la venta, es que el payload no mienta sobre a quién pertenece.
- **Lo que el backend NUNCA valida como motivo de rechazo:** reglas de negocio que dependen de configuración que el POS ya tiene cacheada (crédito habilitado, stock, límites, etc.) — esas se resuelven en el POS al emitir. Caso cerrado 2026-08-16: `SaleService::save()` validaba `contactCreditable` y tiraba `InvalidSaleInputException` en una venta type=3 — la sacamos. El comentario en el código (`api/lib/Sales/SaleService.php`, bloque anti-IDOR) explica por qué no vuelve.
- **Pregunta abierta (el owner no la resolvió):** si la caja está offline y el cache local dice que el cliente NO es creditable, ¿el POS bloquea la venta a crédito o la permite y la marca para revisión? Hoy `pay-dialog.tsx` **bloquea** (default conservador) — la alternativa de "permitir y marcar" queda sin implementar hasta que el owner decida.

**Cola offline — que un rechazo nunca muera en silencio:** esto aplica a CUALQUIER motivo de fallo al sincronizar (red, error del server, dato inválido), no solo al caso de crédito. `frontend/lib/pos/offline-queue.ts` (`markFailed`) deja la venta en IndexedDB con status `'failed'` — TERMINAL, no se reintenta sola. La superficie de visibilidad (2026-08-16):

- `useOfflineSyncStore.failedCount` (`frontend/lib/pos/offline-sync-store.ts`) — separado de `pendingCount` (que mezcla `pending`/`syncing`/`failed`) porque solo `failed` requiere acción humana.
- `OfflineBanner` (`frontend/components/pos/offline-banner.tsx`) se muestra en rojo/destructivo SIEMPRE que `failedCount > 0` — incluso online y sin sync en curso, para que no dependa de que el cajero mire el carrito. Clickeable, abre `SyncQueueDialog`.
- El indicador del carrito (`frontend/components/register/cart-panel.tsx`) escala a estilo destructivo cuando hay fallidas (antes se veía igual que "sincronizando", ambar en ambos casos).
- `SyncQueueDialog` (`frontend/components/pos/sync-queue-dialog.tsx`) ya existía — lista cada venta fallida con el motivo, reintentar (si el código de error no es permanente) o descartar. `useOfflineSyncStore.queueDialogOpen` es ahora la fuente de verdad del open-state, para que banner e indicador del carrito abran el mismo diálogo.

**Carrito en curso: NO se persiste, decisión del owner (2026-08-16).** `frontend/lib/cart/store.ts`
es Zustand en memoria sin `persist()`: cualquier reload —con o sin internet— borra la venta que se
estaba armando. Una auditoría lo señaló como hueco y el owner respondió "esto está bien, dejémoslo
así". No agregar persistencia del carrito ni "recuperar venta en curso" sin pedido explícito: es
comportamiento buscado, no deuda. Lo que SÍ se persiste es la venta ya CONFIRMADA (cola offline en
IndexedDB) — que es lo que la regla de §53 exige, porque esa ya se emitió e imprimió.

**Estación de impresión: depende de internet por diseño, no es bug (owner, 2026-08-16).** El
transport `station` (`frontend/lib/hardware/printers/index.ts:39-54`) manda el payload ya renderizado
a `/v1/print-jobs?action=enqueue` vía la API remota, y la estación lo retira de ahí. Sin internet no
sale la comanda — aunque la venta sí se emita y encole. Una auditoría de preparación offline lo marcó
como P0; el owner lo cerró: *"esto solo pasa si se usa el servidor de impresión; si la computadora
está conectada localmente a la impresora no hay problemas... y si es así tienen que solucionar su
conexión a internet o su red, no es un error nuestro"*.

La línea, entonces:
- Impresora conectada al dispositivo (`native` / `escpos`) → 100% local, tiene que funcionar sin
  internet. Ahí sí cualquier dependencia de red es bug (la plantilla se resuelve del cache local).
- Estación de impresión (`station`) → dependencia de internet aceptada por quien elige ese modo.

NO "arreglar" el transport `station` para que funcione offline sin pedido explícito del owner.

---

## §54 — El wrapper DB lanza, ya no devuelve `false` en error SQL (2026-08-22)

`DB.php` (`api/includes/lib/DB.php`) dejó de tragarse errores SQL. `Execute/AutoExecute/GetOne/GetRow/SelectLimit/GetAssoc/Insert` ahora lanzan `Punto\Api\Support\DbQueryException` (`api/lib/Support/DbQueryException.php`) por el choke point `DB::handleQueryFailure()`. De 1.602 call-sites de `Execute(` solo 4 chequeaban `=== false`, así que un error SQL se degradaba a "recordset vacío" con HTTP 200.

- **Código nuevo: nadie chequea `=== false` para detectar error SQL.** Si el error puede propagar (caso normal), no envolver — deja que suba y lo atrape el handler global (`api/includes/error_handlers.php` → HTTP 500 genérico, SQL/SQLSTATE solo a `error_log`/GlitchTip).
- `false` de `GetRow()`/`GetOne()` sigue significando **"sin filas"**, no error — no cambia. `Connect()` también sigue devolviendo `false` en fallo de conexión.
- Si un camino TOLERA el fallo (feature/tabla opcional, side-effect no crítico de una venta ya emitida: telemetría, marcado de rollup, notificación, impresión, avance de correlativo post-commit) va `try/catch (DbQueryException)` EXPLÍCITO con su propio `error_log` — nunca un catch mudo.
- Detección de duplicados/FK en el catch: usar `$e->sqlState()` (getter, string SQLSTATE), no leer `ErrorMsg()` después de un `false`.
- `PeriodClosedException` no cambió: `handleQueryFailure()` la chequea PRIMERO (`period_closed`/PC001) y siempre la lanza → HTTP 409, antes de considerar `DbQueryException`.
- Kill-switch transitorio `DB_THROW_ON_ERROR` (ver `context/06-infraestructura.md`) — apagar solo para incendio en prod, no para vivir apagado.

## §55 — La API es stateless: prohibido `session_start()`/`$_SESSION` (2026-08-22)

`api/bootstrap.php` ya no arranca sesión PHP. La API es 100% stateless — auth
son tokens opacos resueltos contra `auth_session` (`context/21`), nunca
cookies de sesión del lado servidor.

- Prohibido introducir `session_start()`/`$_SESSION` en código nuevo de `/api`.
- Contadores o estado efímero compartido entre requests (rate limiting,
  locks cortos, etc.) → `Punto\Api\Cache\RedisClient`
  (`api/lib/Cache/RedisClient.php`), no `$_SESSION` (que además no compartía
  nada entre requests sin cookie — era estado decorativo).
- IP del cliente → `Punto\Api\Http\ClientIp::resolve()`
  (`api/lib/Http/ClientIp.php`), nunca `REMOTE_ADDR` pelado: detrás de
  Traefik `REMOTE_ADDR` es siempre la IP del proxy, no la del cliente real.

## §56 — El realm `pos-app` NO identifica personas: la identidad del operador es `X-Operator-Token` (2026-08-23)

Bajo realm `pos-app` el token autentica una **terminal**, no a alguien. Se
emite al parear el dispositivo, es eterno, y `apiAuthTenant()` resuelve su
`userId` como el contacto que hizo el pareo (`api/bootstrap.php:169-171`) y su
rol como el rol `device` del tenant. Los tres mozos que comparten esa tablet
mandan requests idénticas.

Consecuencias que hay que tener presentes SIEMPRE que se escriba código
`pos-app`:

- **`AUTHED_USER_ID` no es "quién hizo esto"** en el POS: es quien pareó el
  dispositivo hace meses. Usarlo para atribuir una acción a una persona es un
  bug silencioso.
- **`hasPermission()` responde por el DEVICE**, no por el operador. Sirve para
  "¿puede una terminal hacer esto?"; no sirve para distinguir a un encargado de
  un cajero parados frente a la misma caja. Darle una clave al rol `device`
  para habilitar a una persona se la da a **todas** las personas de **todas**
  las tablets.

Cuando una regla es sobre PERSONAS, la identidad se resuelve con
`Punto\Api\Auth\OperatorContext::resolve($ctx)`:

- Realm `panel` → la credencial ES la persona (`AUTHED_USER_ID` + `ROLE_ID`).
- Realm `pos-app` → sale de `X-Operator-Token`, una afirmación firmada con
  HMAC (`Punto\Api\Auth\OperatorAssertion`) que emite **solo**
  `/v1/unlock-pin.php` tras validar el PIN contra `contact.pinhash`. Es la
  única fuente legítima: emitirla en otro lado la convierte en un dato que el
  cliente elige, que es exactamente lo que no autoriza nada.
- Los permisos de esa persona se evalúan con `OperatorContext::can()`, que
  resuelve contra `contact.role` — NO con el `hasPermission()` global.
- Sin token → `identified: false`. **Fail-closed**: "no sé quién sos" nunca se
  resuelve a favor, porque no mandar el header sería el bypass trivial.

El front lo adjunta en `lib/api/pos-fetch.ts` (wrapper compartido, igual que el
Bearer) — nunca en el call-site. El BFF lo reenvía solo porque copia todos los
headers no hop-by-hop.

**No es una sesión** y no reemplaza al token del device: no hay fila en
`auth_session`, no se revoca de a una, y sin Bearer válido no vale nada
(`apiAuthTenant()` corta antes). La sesión de operador real es el rewrite de
`context/21-auth-rewrite.md`.

## §57 — Exclusividad de mesa: `space_session.waiterid` es autorización, no una etiqueta (2026-08-23)

Asignarle un mozo a una mesa **restringe quién puede operarla**. No es un campo
informativo.

- Regla: si `space_session.waiterid` no es NULL, solo ese operador puede
  cancelar, editar (alias/comensales/mozo), mover, unir, pedir la cuenta y
  agregarle órdenes. Cualquier otro recibe **403**.
- Válvula de escape: la clave `pos.space.override` (seed de `manager`),
  evaluada contra el rol del OPERADOR (§56), nunca del device.
- `waiterid` NULL = mesa de todos. Es lo que hace el cambio retrocompatible:
  todo lo abierto sin mozo sigue funcionando igual.
- **Cobrar y cerrar quedan FUERA de la regla, a propósito**: quien cobra es la
  caja, no el mozo. Bloquear `close()` dejaría al cajero sin poder cerrar la
  cuenta de una mesa ajena, que es su trabajo (y `close()` ya tiene su propio
  invariante duro: no cierra con saldo pendiente).
- El enforcement vive en el **service** (`SpaceOwnershipGuard`, llamado desde
  `SpaceSessionService` y `OrderCoreService::create`), no en el endpoint:
  `SpaceSessionService` tiene tres callers y agregar una orden es otra puerta a
  la misma mesa. Un guard por endpoint deja las otras abiertas.
- `SpaceOwnershipException` tiene archivo propio porque el autoloader resuelve
  una clase por archivo, y un `instanceof` contra una clase no cargada devuelve
  `false` en silencio — degradaría el 403 a 422 justo en el camino de
  autorización.

Arnés: `api/tests/space_exclusivity_test.php` (403 contra el endpoint real, con
device token real, incluidos los bypass: sin header, token de otra empresa,
firma manipulada).

---

## §58 — Cadena de alta obligatoria: Company > Sucursal > (Depósito | Caja) (owner, 2026-08-24)

Palabras del owner:

> "Cuando un tenant crea una nueva cuenta en el sistema (signup), se crea
> automáticamente por defecto la empresa > una sucursal (Central) > un depósito
> > una caja. Estos van encadenados obligatoriamente. Cuando creo una sucursal
> nueva también se crea la Sucursal > depósito > caja. El depósito y la caja
> están al mismo nivel, ambos son hijos directos de la sucursal."

```
Company
└── Outlet (sucursal)          ← el signup crea una llamada "Central"
    ├── Location (depósito)    ← hermanos, hijos DIRECTOS del outlet
    └── Register (caja)
```

**Ninguna sucursal puede existir sin su depósito Y su caja.** No es "se crean
si el usuario quiere": es una cadena obligatoria, y ningún camino de alta puede
saltearse un eslabón. Todo eslabón se crea en la MISMA transacción que la
sucursal — si falla uno, no queda una sucursal a medias.

### Los creadores canónicos

| Eslabón | Único creador | Lo llaman |
|---|---|---|
| Depósito | `LocationTaxonomyService::ensureDefault()` | `SignupService`, `OutletsService::create()` |
| Caja | `ncmInsert(table: 'register')` dentro de la transacción del outlet | idem |

Un camino de alta de sucursal NUEVO obliga a llamar a los dos. El escaneo del
arnés (abajo) es lo que lo hace visible, no la disciplina.

### No se puede romper por borrado

- `LocationTaxonomyService::delete()` bloquea SIEMPRE el depósito por defecto.
- `RegisterAdminService::assertNoEsLaUltimaCajaActiva()` bloquea la ÚLTIMA caja
  activa de la sucursal (409). Cuenta activas y no totales: una caja dada de
  baja conserva su historial fiscal pero no emite, así que no es el eslabón.

**El guard de la caja vive en un método compartido, no en el call-site**, porque
hay DOS puertas al mismo estado y la segunda se escapa siempre:

- `delete()` → hard delete, o soft delete (`registerStatus = FALSE`) si la caja
  tiene transacciones.
- `update(['status' => false])` → el toggle del panel (`/v1/register.php`
  action=update). Mismo efecto exacto: cero cajas operables.

Ambos guards son fail-CLOSED: el wrapper de DB lanza ante error SQL (§54), así
que una verificación que no responde aborta el request en vez de dejar pasar la
baja.

### Crear una caja NO es asignar un punto de expedición

La caja por defecto nace con `data = '{}'`: sin timbrado y sin `EEE-PPP`. El
índice `uq_register_expedition_point_by_auth` (mig 143) es PARCIAL y exige
ambos NOT NULL, así que una caja sin talonario queda fuera y no puede colisionar
con nada. El invariante fiscal de `context/29` §2 ("por timbrado, punto de
expedición + correlativo es único") queda intacto: esa caja no emite un solo
documento fiscal hasta que un humano le asigne timbrado y prefix desde
Sucursal › Cajas, que es lo único que escribe esos campos
(`RegisterAdminService::assertExpeditionPointFree()`).

### Historial de roturas (las dos por el mismo motivo)

- `Auth\SignupService` nunca creó depósito: TODO tenant nacía sin uno. Backfill
  mig 165.
- `01_master_admin.sql` crea la sucursal master con SQL crudo y nunca insertó
  caja: única sucursal sin caja en producción. Backfill mig 166.

Los seeds son caminos de alta y cuentan: `01_master_admin.sql`,
`02_sample_company.sql` y `verify_chain/seed.sql` ya traen su cadena completa.

Arnés: `api/tests/outlet_chain_invariant_test.php` — escanea TODA la base
(ninguna sucursal de ningún tenant sin depósito, sin default o sin caja activa)
y además ejercita `OutletsService::create()` con payload, el camino legacy blank
(`$fields = null`), los dos lados del guard de borrado, y el toggle
`update(status:false)`. Correr con
`bash api/tests/run_outlet_chain_invariant_test.sh`.
