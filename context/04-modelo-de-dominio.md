<!-- REGLA: Actualizar cuando se cree/elimine una tabla, se agregue un campo indexado,
     o cambie una invariante del schema. NO actualizar por cambios a campos JSONB internos. -->

# 04 — Modelo de Dominio

## Schema: PostgreSQL v2

Diseñado para PostgreSQL 16+. Archivo fuente: `db-schema-postgres.sql` (54KB).

### Principios de diseño

1. **UUID como PK** en todas las tablas — v7 (ordenable por tiempo) vía `ncmInsert()` que llama `generateUuidV7()`; v4 random (no ordenable) cuando la fila la inserta `$db->AutoExecute()` directamente y cae al `DEFAULT gen_random_uuid()` de PG16 (ej. `stock`, `toTag`)
2. **JSONB para extensibilidad** — campos que no necesitan índice van a `config`, `data`, o `meta`
3. **Multi-tenant por `companyId`** — toda tabla con datos de tenant tiene FK a company
4. **Merged tables** — `company` absorbe lo que antes era `setting` + `module` + `companyHours`

### Entidades principales

```
company (tenant)
├── users (empleados del tenant)
├── outlets (sucursales)
│   └── registers (cajas/terminales)
├── contacts (clientes + proveedores)
├── items (productos/servicios)
│   ├── categories
│   └── brands
├── transactions (ventas, compras, notas de crédito)
│   └── itemSold (líneas de la transacción)
├── suppliers (proveedores)
├── recurring (suscripciones/recurrentes)
└── tasks (tareas internas)

franchiser_to_tenant (acceso N→N franquiciador→tenant — NO propiedad/billing)
└── franchiserId, tenantId  → ambos FK a company(companyId)
```

### Columnas JSONB por tabla

| Tabla | Columna JSONB | Qué guarda |
|-------|--------------|------------|
| `company` | `config` | Toda config del tenant: nombre, RUC, moneda, horarios, módulos activos, datos SIFEN |
| `item` | `data` | Campos no-indexables: descripción larga, variantes, metadata. **Desde migración 07 (2026-05-25)** contiene también los 4 campos demotados: `itemImage` (bool), `itemTaxExcluded`, `itemDiscount`, `itemUOM`. Keys en camelCase. |
| `contact` | `data` | Campos descriptivos del cliente/proveedor. **Desde migración 06 (2026-05-25)** contiene también los 6 campos demotados: `contactNote`, `contactCity`, `contactLocation`, `contactCountry`, `contactAddress`, `contactAddress2`. Keys en camelCase. |
| `transaction` | `meta` | Metadata de la venta: canal, device, notas |
| `itemSold` | `meta` | Metadata de la línea: descuentos aplicados, promo |
| `outlet` | `data` | Config específica de sucursal |
| `register` | `data` | Config específica de caja |

**Regla de diseño JSONB (decisión 2026-05-25):** solo van como columnas reales los campos que necesitan índice o cálculo SQL. Lo estrictamente descriptivo/estático va a `data` JSONB. Aplicado en ambas entidades principales:

- **`contact`** — quedan como columnas: `contactName`, `contactEmail`, `contactPhone`, `contactTIN`, `contactCI`, `contactStatus`, `contactType`, `contactStoreCredit`, `contactLoyalty`, financieros indexados. Van a `contact.data`: note, city, location, country, address, address2.
- **`item`** (migración 07, 2026-05-25) — quedan como columnas: `itemName`, `itemSKU`, `itemSort`, `itemStatus`, `itemType` (indexados), `itemPrice`, `itemCost` (usados en SUM/AVG SQL). Van a `item.data`: `itemImage`, `itemTaxExcluded`, `itemDiscount`, `itemUOM`. Criterio de auditoría: 0 apariciones en WHERE/ORDER/JOIN/GROUP/SUM. `itemTaxExcluded` era además columna fantasma (0 lecturas y 0 escrituras en todo el repo).

**Nota sobre columnas legacy "absorbidas" a JSONB con valor STRING (hallazgo 2026-05-27):** cuando un writer legacy hacía `json_encode($valor)` *antes* de pasar al insert (ej.: `data['recurringSaleData'] = json_encode($sale)`), `_routeToJsonb` almacena esa string JSON como un string dentro del JSONB. El resultado: `data->'recurringSaleData'` tiene `jsonb_typeof = string`, no `object`. Para leerlo hay que usar el operador `->>'recurringSaleData'` (texto) + `json_decode` en PHP — usar `->` directo devuelve la string JSON con comillas y escapes. Caso conocido: tabla `recurring`, columna `data->>'recurringSaleData'` (writers: `cronCreateRecurringInvoice.php:103`, `app/action.php:2502`). Generalización: ante cualquier columna JSONB absorbida desde el POS/crons, verificar si el writer llamaba `json_encode` antes de pasar al `ncm`; si lo hacía, el valor es un string serializado.

**Nota sobre columnas `contact` demotadas — acceso desde queries (hallazgo 2026-05-27):** las columnas `contactAddress`, `contactAddress2`, `contactLocation`, `contactCity` fueron demotadas a `contact.data` JSONB por `06_contact_jsonb_demote.sql`. Cualquier query que las seleccione explícitamente (`SELECT contactAddress FROM contact`) falla en PG con "undefined column". Para leerlas: `SELECT *` + `_flattenJsonb($row, 'data')` en PHP, o `data->>'contactAddress'` en SQL. Generalización: toda query que nombre una columna demotada de `contact` por nombre explícito está rota en PG; la detección es simple (buscar los 4 nombres en queries de `contact`). Primer call-site encontrado durante migración de customers (commit 4c0ad35); ver también la nota de `getCustomerData()` en `10-roadmap.md`. **`getAllContactsRaw` arreglado (commit 1c2af24, 2026-05-27):** reescrito a `SELECT *` vía `ncmExecute getAssoc` (aplica `_flattenJsonb` automáticamente) — ya no selecciona columnas demotadas por nombre explícito. La lista legacy `$fields`/`$index` es ignorada.

**Nota sobre `app/` vs `panel/` en writers JSONB:** el `ncmInsert`/`ncmUpdate` de `app/includes/functions.php` **NO tiene `_routeToJsonb()`** — no rutea a JSONB automáticamente. Los writers en `app/` no pueden pasar columnas demotadas; si lo hacen, AutoExecute crashea con "column does not exist". Solo el `ncm` del panel rutea. Tenerlo en cuenta al agregar campos a `item.data` o `contact.data` que también se escriben desde `app/`.

### Funciones PHP de routing JSONB

| Función | Qué hace |
|---------|----------|
| `_flattenJsonb($row, $jsonCol)` | Lee: aplana columna JSONB al row PHP |
| `_getTableSchema($table)` | Introspección: devuelve columnas reales de la tabla |
| `_routeToJsonb($table, $data)` | Escritura: separa campos reales vs JSONB automáticamente |
| `ncmInsert($table, $data)` | INSERT con UUID v7 auto + routing JSONB |
| `ncmUpdate($table, $data, $where)` | UPDATE con routing JSONB |

**Tablas registradas en `_getTableSchema()` (whitelist):** incluye `contact` (sin las 6 columnas demotadas en migración 06), `item` (sin las 4 columnas demotadas en migración 07), y `customerAddress` (pk=`customerAddressId`, agregada en commit 01d6eba — su ausencia causaba que `ncmInsert` inyectara una columna `id` inexistente y silenciara el error). Regla: la whitelist debe actualizarse en el mismo commit del DROP; si no, `_flattenJsonb` hace que la columna real gane sobre el JSONB → lecturas stale.

### Invariantes del schema

1. **companyId es NOT NULL** en toda tabla de datos de tenant
2. **UUID v7 solo cuando se usa `ncmInsert()`** — `gen_random_uuid()` de PG16 es UUID v4 random, NO v7. `ORDER BY <tabla>Id` NO es cronológico en tablas que insertan vía `AutoExecute()` sin PK explícito. Para "fila más reciente" siempre usar columna timestamp (`stockDate`, `createdAt`, `transactionDate`). Bug real: `getItemStock`/`getAllItemStock` ordenaban por `stockId` → stock arbitrario; fijo en commit f02e7ac (2026-06-15). Auditar cualquier `ORDER BY *Id`/`max(*Id)` que asuma monotonía.
3. **config JSONB en company** — acceso via `config->>'settingName'`, `config->>'settingRUC'`, etc.
4. **No hay CASCADE DELETE** en FKs principales — las eliminaciones son soft (status/flag)
5. **Timestamps**: `createdAt` (auto), `updatedAt` (trigger), timezone: `America/Asuncion` — guardados como tenant-local naive (sin offset UTC). Ver §51 de `08-convenciones-criticas.md`.
6. **JSONB vs columna real** — campos indexables, buscados, o usados en cálculos SQL son columnas reales; campos descriptivos/estáticos van a `data` JSONB. Violaciones se corrigen con migraciones. Ejemplos aplicados: `06_contact_jsonb_demote.sql` (6 campos) y `07_item_jsonb_demote.sql` (4 campos: itemImage/itemTaxExcluded/itemDiscount/itemUOM). Criterio de auditoría para decidir: 0 apariciones en WHERE/ORDER/JOIN/GROUP/SUM en todo el repo (grep).
7. **Columnas BOOLEAN en PG** — comparar con `= true` / `= false`, nunca `= 1` / `= 0`. Error PG: `operator does not exist: boolean = integer`. Sitios pendientes de corregir: `panel/includes/functions.php:3464,3790` y `app/action.php`, `app/load.php`, `app/fetch.php`, `app/fetchs.php`.
9. **`contactPhone` único por tenant-usuario (migración 12, 2026-06-09):** UNIQUE INDEX parcial sobre `contactPhone` para `type=0 AND role IN (0,1,2,7) AND contactPhone <> ''`. El teléfono en E.164 es la identidad de login de los tenants — duplicados romperían el lookup de auth. Proveedores/otros roles no entran en la constraint.
10. **Login de tenant SOLO por teléfono (commit b90dede, 2026-06-07):** el campo `contactPhone` (E.164) es el identificador canónico de login para empleados/dueños del tenant. `contactEmail` es opcional (perfil, no login). `panel/API/auth.php:findEmailOrPhoneLogin()` acepta ambos por backwards-compat, pero el campo canónico es `phone`+`iso`. Admin realm (`admin_user`) sigue por email (decisión por separado).
8. **Acceso ≠ propiedad** — la pertenencia de un tenant a un franquiciador (`franchiser_to_tenant`) es solo una capa de acceso/gestión; NO afecta dueño, plan ni facturación, que son siempre per-tenant en `company`. Ver ADR-001.

### Tabla `giftCardSold` — gift cards vendidas/emitidas

Cada fila representa un saldo de gift card emitido al momento de la venta. Leída también desde `CustomerService::getGiftcards()` (resumen de cliente) y el reporte `a_report_giftcards`.

| Columna | Tipo | Notas |
|---------|------|-------|
| `giftCardSoldId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `giftCardSoldValue` | NUMERIC | Saldo actual (decrementado atómicamente en cada redención: `GREATEST(value - ?, 0)`) |
| `giftCardSoldExpires` | TIMESTAMPTZ NULL | Vencimiento; NULL = sin vencimiento. Si está vacío/NULL cuenta como vencida en el reporte (guard `!$exp || $exp < $now`). |
| `giftCardSoldStatus` | BOOLEAN | Activa/inactiva |
| `giftCardSoldCode` | INTEGER | Código numérico de la tarjeta (dedup por `timestamp+companyId` — evita duplicados en reenvío de cola offline) |
| `giftCardSoldNote` | TEXT NULL | Nota libre |
| `giftCardSoldLastUsed` | TIMESTAMPTZ NULL | Última redención |
| `giftCardSoldSendDate` | DATE NULL | Fecha programada de envío e-gift |
| `giftCardSoldBeneficiaryId` | UUID NULL | FK → `contact(contactId)` ON DELETE SET NULL. El beneficiario recibe el email/SMS de e-gift. `SaleService::sellGiftCard` valida formato UUID Y existencia en el tenant (evita FK violation con id externo inválido). |
| `giftCardSoldColor` | TEXT NULL | Color hex del diseño de la tarjeta (validado `/^[0-9a-fA-F]{3,8}$/` antes de interpolarlo en CSS) |
| `timestamp` | BIGINT | Marca de tiempo del cliente usada como dedup key (UNIQUE por `timestamp+companyId`). Permite reenvío idempotente desde la cola offline. |
| `transactionId` | UUID NULL | FK → `transaction(transactionId)` — venta en que se emitió la tarjeta |
| `outletId` | UUID | FK → `outlet` |
| `companyId` | UUID NOT NULL | Multi-tenant scope |

**Invariantes operativas:**
- El decremento de saldo es atómico en SQL: `UPDATE giftCardSold SET giftCardSoldValue = GREATEST(giftCardSoldValue - ?, 0)` — sin lost-update bajo concurrencia.
- `SELECT` para redimir filtra por `companyId` (tenant-scoped, evita redimir tarjetas de otro tenant).
- Card no encontrada o `giftCardSoldCode` no numérico → `log + skip`, no throw (para no abortar la tx de la venta).
- `SaleService::notifyGiftCardBeneficiaries` envía el email/SMS **post-commit** best-effort (no bloquea la transacción; el legacy lo hacía inline en la tx con curl síncrono).

**Mejoras de SaleService vs legacy `insertNewGiftCard`/`manageGiftCard` (commits 0e3c7bf + d099019, 2026-05-31):**
- Dedup por `timestamp` PARAMETRIZADO + tenant-scoped (el legacy concatenaba el id en el SQL — SQLi).
- `beneficiaryId`: check de formato UUID antes del SELECT (un valor no-uuid abortaría la tx por `invalid uuid syntax`); validado contra contact del tenant → null si no resuelve.
- Decremento ATÓMICO en SQL (el legacy calculaba el nuevo valor en PHP y luego hacía el UPDATE — race condition).

### Tabla `plans` — planes de suscripción

La tabla `plans` usa **UUID v7 como PK** (`id`), pero `company.plan` es un `smallint` legacy.
Para bridgear este mismatch, **migración 10** (`10_plans_code.sql`, 2026-05-30) agrega:

- `plan_code smallint NOT NULL DEFAULT 0` — entero semántico que matchea `company.plan`
- Índice único parcial `UNIQUE (plan_code) WHERE plan_code > 0` — evita duplicados entre planes reales (0 = valor de relleno para planes legacy sin código)

**Patrón de lookup:** `getAllPlans()` en `app/includes/functions.php` indexa el resultado por `plan_code ?? id`.
Para resolver el plan de una company: `$plans[$company->plan]` → obtiene el row del plan.
Sin este campo, `getAllPlans(1)` nunca matcheaba (UUID vs int) → arrays de límites vacíos → LIMIT 0 en todas las queries del POS.

**Seed de desarrollo:** `database/seeds/postgres/03_dev_plan.sql` inserta el "Local Dev Plan" con `plan_code=1` y todos los límites en 99999. Incluido en `run_seeds.sh` como tercer seed.

### Relación franquiciador→tenant (acceso, N→N)

Ver [adr/ADR-001-franchiser-tenant-acceso.md](adr/ADR-001-franchiser-tenant-acceso.md).

- **`franchiser_to_tenant`** (migración 08, 2026-05-26): tabla puente que define qué tenant
  *franquiciador* puede **gestionar/impersonar** a qué tenant hijo. Columnas: `franchiserTenantId`
  (pk), `franchiserId` + `tenantId` (FK a `company`, `ON DELETE CASCADE`), `relationType`
  (default `'manager'`), `status`, `created_at`. `UNIQUE(franchiserId, tenantId)` + `CHECK(franchiserId <> tenantId)`.
- **Es PURAMENTE acceso, no propiedad ni billing.** Cada `company` (padre o hijo) tiene su
  propio dueño, plan y facturación, independientes. Un franquiciador con 5 hijos = 6 cuentas
  facturadas por separado. La pertenencia a un franquiciador no cambia de quién es el tenant.
- **Reemplaza** a `company.parentId` (1→N), que no soportaba que un hijo tenga varios
  franquiciadores. `parentId` queda deprecado para acceso (backfilleado a la junction); se
  eliminará cuando nada lo lea.
- **Autorización de impersonalización del franquiciador**:
  `EXISTS(SELECT 1 FROM franchiser_to_tenant WHERE franchiserId = COMPANY_ID AND tenantId = ?)`.
  El SaaS super-admin (encom, `COMPANY_ID == ENCOM_COMPANY_ID`) entra a cualquiera sin pasar por la tabla.
- El `ON DELETE CASCADE` aplica solo a esta tabla de *relación* (borrar una company quita sus
  links de acceso) — no contradice el invariante #4, que es sobre datos de tenant (soft-delete).

### Tabla `admin_user` — super-admins de plataforma (admin realm)

Ver [adr/ADR-002-admin-realm-separado.md](adr/ADR-002-admin-realm-separado.md) para el razonamiento completo.

Creada en **migración 09** (`09_admin_user.sql`, 2026-05-28). Es la base del *admin realm*: los super-admins de plataforma dejan de ser un tenant especial (flag `SAAS_ADM` sobre `MASTER_COMPANY_ID`) y pasan a tener identidad propia en esta tabla.

| Columna | Tipo | Notas |
|---------|------|-------|
| `adminId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `email` | TEXT NOT NULL | único case-insensitive (índice sobre `lower(email)`) |
| `passwordHash` | TEXT NOT NULL | bcrypt (`password_hash` / `PASSWORD_DEFAULT`) — **distinto** al sha256+salt+HASH\_TIMES de la tabla `contact` |
| `name` | TEXT NOT NULL | default `''` |
| `status` | SMALLINT NOT NULL | 1 activo, 0 desactivado (soft-disable — no borrar físicamente para auditoría) |
| `createdBy` | UUID NULL | self-FK → `admin_user(adminId) ON DELETE SET NULL` — auditoría de quién creó a quién |
| `lastLoginAt` | TIMESTAMPTZ NULL | — |
| `created_at` / `updated_at` | TIMESTAMPTZ | — |

**CHECKs:** `length(trim(email)) > 0` + `status IN (0, 1)`.
**Índices:** `idx_admin_user_email` UNIQUE sobre `lower(email)` + `idx_admin_user_status`.

**Invariantes clave:**
- `admin_user` **no tiene `companyId`**: un admin de plataforma no pertenece a ninguna empresa.
- El password usa bcrypt — no el esquema sha256+salt+HASH\_TIMES que usan los users de tenant en `contact`. No son intercambiables.
- El admin inicial se siembra desde `.env` vía `panel/admin/bootstrap_seed.php` (CLI idempotente — no-op si ya existe, no pisa password cambiado).
- Env vars: `ADMIN_BOOTSTRAP_EMAIL`, `ADMIN_BOOTSTRAP_PASSWORD`, `ADMIN_JWT_SECRET`, `ADMIN_JWT_TTL`.

**Qué NO hace esta tabla todavía (F0):** el login `/admin`, el BFF, el CRUD de admins y la auth JWT del realm están en fases F1–F2 (ver `10-roadmap.md § Admin realm`).

### Tabla `device` — dispositivos del POS (device pairing, commit a3fefb4, 2026-06-06)

Creada en **migración 11** (`11_device.sql`). Es el mecanismo de revocación per-device del realm POS: el admin puede deshabilitar una caja específica sin afectar otras ni rotar el `JWT_SECRET`.

| Columna | Tipo | Notas |
|---------|------|-------|
| `deviceId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `companyId` | UUID NOT NULL | FK → `company(companyId) ON DELETE CASCADE`. Multi-tenant scope. |
| `userId` | UUID NOT NULL | Admin que activó el device (sin FK — `contact` puede borrarse) |
| `outletId` | UUID NULL | Outlet al que está asignado |
| `registerId` | UUID NULL | Caja/terminal asociada |
| `deviceName` | TEXT NOT NULL DEFAULT '' | Nombre editable por el admin |
| `userAgent` | TEXT NULL | User-Agent del browser al momento del login |
| `ipFirst` | INET NULL | IP del primer login (validada con `filter_var` antes del INSERT) |
| `ipLast` | INET NULL | IP del último ping/refresh |
| `lastSeenAt` | TIMESTAMPTZ NULL | Última vez que el device fue validado |
| `status` | SMALLINT NOT NULL DEFAULT 1 | 1 = activo, 0 = revocado. CHECK (status IN (0,1)) |
| `revokedAt` | TIMESTAMPTZ NULL | Timestamp de revocación |
| `revokedBy` | UUID NULL | Admin que revocó (sin FK — `contact` puede borrarse) |
| `createdAt` | TIMESTAMPTZ | Timestamp de creación |

**Índices**: `idx_device_company` (companyId), `idx_device_company_status` (companyId, status), `idx_device_user` (userId).

**Soft-delete**: revocación por `status=0` — no DELETE físico. Preserva historial forense (quién/cuándo/desde dónde).

**Flujo de uso**:
1. Al login (`app/login.php` o `app/API/auth.php`), se llama `deviceRegister($companyId, $userId, $outletId, $registerId)` → INSERT row → retorna `deviceId` UUID.
2. El `deviceId` se incluye como claim `did` en el JWT emitido.
3. En cada request, `jwtAuthenticate()` en `app/includes/jwt_middleware.php` verifica `device.status` si el JWT trae `did`. Cache file 60s en `sys_get_temp_dir/punto_device_status/{deviceId}_{companyId}.dat`. Si status=0 → 401 `device_revoked`.
4. `app/API/refresh.php` también chequea device antes de emitir token nuevo y preserva `did` en el payload renovado.

**Revocación per-device**: `UPDATE device SET status=0, revokedAt=NOW(), revokedBy=? WHERE deviceId=? AND companyId=?`. Llamar `jwtInvalidateDeviceCache($deviceId)` (1 arg) para forzar invalidación inmediata del cache de archivo (sin esperar el TTL de 60s).

**Backwards compat**: tokens sin claim `did` (emitidos antes del feat) siguen pasando sin validar `device.status`.

**Columnas adicionales** (migs posteriores):
- `browserLocalId TEXT NULL` (mig 60): UUID en localStorage del browser para idempotencia de pairing.
- `module TEXT NULL DEFAULT 'pos'` (mig 63): identifica el módulo del device (pos/kds/display).

**UI implementada**: `/settings/devices` en frontend — listado con tab "Solicitudes" (invitaciones) + tab "Dispositivos" (activos). Revocados ocultos por default; hard-delete físico disponible.

**Nota migration runner**: desde mig 59 en adelante, el runner automático `database/migrate.php` aplica las migraciones en deploy (ver `06-infraestructura.md`).

### Extensiones PostgreSQL activas

- `pgcrypto` — gen_random_uuid()
- `uuid-ossp` — funciones UUID adicionales
- `pg_trgm` — búsqueda fuzzy por trigramas
- `unaccent` — búsqueda sin acentos

### Migraciones

- **Ubicación**: `database/migrations/postgres/`
- **Naming**: `NN_descripcion.sql` (numérico secuencial)
- **Runner**: TO-DO — actualmente se corren manual. Se planea un runner automático en deploy.
- **Seeds**: `database/seeds/` — datos iniciales (admin, company demo, catálogo, items)

| # | Archivo | Qué hace | Estado |
|---|---------|----------|--------|
| 06 | `06_contact_jsonb_demote.sql` | Demota 6 columnas de `contact` a `contact.data` JSONB | Aplicada local 2026-05-25 |
| 07 | `07_item_jsonb_demote.sql` | Demota 4 columnas de `item` a `item.data` JSONB (itemImage/itemTaxExcluded/itemDiscount/itemUOM) | Aplicada local 2026-05-25 |
| 08 | `08_franchiser_to_tenant.sql` | Crea tabla puente de acceso N→N franquiciador→tenant + backfill desde `company.parentId` | Aplicada local 2026-05-26 |
| 09 | `09_admin_user.sql` | Crea tabla `admin_user` para super-admins de plataforma (admin realm separado, bcrypt, sin companyId) | Aplicada local 2026-05-28 |
| 10 | `10_plans_code.sql` | Agrega columna `plan_code smallint NOT NULL DEFAULT 0` a tabla `plans` + índice único parcial `WHERE plan_code > 0`. Resuelve el mismatch `company.plan smallint` → `plans.id UUID` que hacía que `getAllPlans(1)` nunca matcheara (LIMIT 0) | Aplicada local 2026-05-30 |
| 11 | `11_device.sql` | Crea tabla `device` para el modelo device pairing / revocación per-dispositivo (ver §device abajo). Aplicada manualmente (`php -r` parseando por `;`). | Aplicada local 2026-06-06 |
| 12 | `12_contact_phone_unique.sql` | Drop de `idx_contact_phone` (no-unique) + crea UNIQUE INDEX parcial `idx_contact_phone_tenant_unique` sobre `contactPhone` para `type=0 AND role IN (0,1,2,7) AND contactPhone <> ''`. **Invariante nuevo**: un número de teléfono E.164 es único entre tenants para los roles de usuario-cliente. P0 fix del reviewer. | Aplicada en deploy Coolify 2026-06-09 |
| 13 | `13_seed_plans_zero_and_trial.sql` | INSERT de `plan_code=0` (free) y `plan_code=3` (trial) en tabla `plans`. Faltaban en seeds anteriores → POS no podía bootar si `company.plan IN (0,3)`. | Aplicada en deploy Coolify 2026-06-09 |

**Patrón atómico de demotion:** backfill UPDATE no-destructivo (NULLIF para no inflar con defaults; booleans como JSON booleans, no strings) + DROP atómico en el mismo script. Requiere ser owner de la tabla en PG (el usuario `punto` de la app no lo es — ver `06-infraestructura.md §Privilegio de owner para DDL`).

| # | Archivo | Qué hace | Estado |
|---|---------|----------|--------|
| 25 | `25_contact_jsonb_demote2.sql` | Demota 9 columnas de `contact` a `contact.data` JSONB: `contactSecondName`, `contactAddress`, `contactAddress2`, `contactNote`, `contactCity`, `contactLocation`, `contactCountry`, `contactCI`, `contactBirthDay`. **DROP de `contactPhone2`** (eliminado por completo — decisión de producto). | Aplicada local 2026-06-12 |
| 26 | `26_register_jsonb_demote.sql` | Demota 5 columnas de config fiscal de `register` a `register.data` JSONB: `registerInvoiceAuth`, `AuthExpiration`, `Prefix`, `Sufix`, `DocsLeadingZeros`. Los counters atómicos del POS (`registerDocNumber`, etc.) quedan como columnas reales (invariante: counters NO se demotan, ver §22.8 en `08-convenciones.md`). | Aplicada local 2026-06-12 |
| 27 | `27_outlet_cleanup.sql` | Demota `outletNextExpirationDate` de `outlet` a `outlet.data` JSONB. **DROP TABLE IF EXISTS** de `setting`, `module`, `companyHours` (limpieza para instalaciones MySQL crudas que aún las tuvieran). | Aplicada local 2026-06-12 |
| 28 | `28_billing.sql` | Crea tablas `ai_credit_ledger` y `billing_request`. Agrega columnas `company.aiCreditsBalance INT` y `plans.ai_credits_monthly INT`. | Aplicada 2026-06-14 |
| 29 | `29_admin_audit.sql` | Crea tabla `admin_audit` con índices. Agrega helper `adminAudit()` en `panel/API/lib/admin_auth.php`. | Aplicada 2026-06-14 |
| 30 | `30_billing_dlocal.sql` | Crea tablas `credit_pack` (catálogo + seed 3 packs) y `billing_invoice`. Agrega columna `ai_credit_ledger.relatedInvoiceId` + índice único parcial `uq_ai_credit_ledger_invoice_grant` (idempotencia anti doble-acreditación). | Aplicada 2026-06-14 |
| 31 | `31_pack_services.sql` | Crea tablas `pack_component`, `sold_pack`, `sold_pack_usage` para el módulo de Packs de Servicios. | Aplicada 2026-06-15 |
| 32 | `32_price_lists.sql` | Crea tablas `price_list` y `price_list_item` para el módulo de Listas de Precios. | Aplicada 2026-06-15 |
| 33–36 | `33_tenant_audit.sql`..`36_tenant_audit_pgcron.sql` | Tabla `tenant_audit` + pg_cron retención 2 meses (fail-tolerant). | Aplicada 2026-06-19 |
| 37 | `37_taxonomy_dedup.sql` | Dedup duplicados en `taxonomy` + UNIQUE case-insensitive (self-heal con information_schema check para columnas opcionales que no existen en BD real). | Aplicada 2026-06-19 |
| 38 | `38_tag.sql` | Crea tabla `tag` + tabla de join `item_tag` + triggers bidireccionales que sincronizan tags ↔ taxonomy. | Aplicada 2026-06-19 |
| 39 | `39_item_tag_unique.sql` | Índice UNIQUE sobre `item_tag(itemId, tagId)`. | Aplicada 2026-06-19 |
| 40 | `40_customer_display.sql` | Crea tabla `customer_display` para el módulo Checkout Screen (pantalla secundaria del POS). Ver §customer_display abajo. | Aplicada 2026-06-20 |
| 41 | `41_report_rollup.sql` | Crea tablas `report_rollup` y `rollup_dirty`; funciones `reconcile_rollup` + `backfill_rollup`; pg_cron incremental cada 5 min. Gateado por `REPORTS_ROLLUP_ENABLED`. | Aplicada 2026-06-20 |
| 42 | `42_rollup_item_payments.sql` | Extiende `report_rollup` con tablas `item_sales`, `item_returns`, `payments` para rollup de categorías/marcas/métodos de pago. | Aplicada 2026-06-20 |
| 43 | `43_ai_model_config.sql` | Crea tabla `ai_model_config` — configuración por tenant del modelo de IA (provider, model, creditsPerKToken). | Aplicada 2026-06-21 |
| 44 | `44_parked_sales.sql` | Crea tabla `parked_sale` + `parked_sale_item` para ventas guardadas del POS. | Aplicada 2026-06-23 |
| 45 | `45_tenant_audit_pgcron.sql` | Scheduling pg_cron para purge de `tenant_audit` (retry de mig 36 fail-tolerant). | Aplicada 2026-06-23 |
| 46 | `46_inventory_count.sql` | Crea tabla `inventory_count` (sesiones de conteo: `status open/finished/cancelled`, `outletId`, `companyId`) + `inventory_count_item` (por ítem: `expected`, `counted`, `difference`). Columnas en **camelCase quoted** (tabla nueva). | Aplicada 2026-06-23 |
| 47 | `47_stock_transfer.sql` | Crea tabla `stock_transfer` (cabecera: `fromOutletId`, `toOutletId`, `status pending/completed/cancelled`) + `stock_transfer_item` (por ítem: `quantity`). Columnas en **camelCase quoted** (tabla nueva). | Aplicada 2026-06-23 |
| 48 | `48_item_variants.sql` | Agrega a tabla legacy `item`: `"variantParentId"` UUID FK nullable (ítem padre que tiene variantes), `"hasVariants"` BOOLEAN DEFAULT false, `"variantAttributes"` JSONB (array de objetos `{name, value}`). Las 3 columnas usan **quotes** porque `item` es tabla legacy (PG la tiene en lowercase). | Aplicada 2026-06-23 |
| 49 | `49_lockpass_hash.sql` | Agrega columna `lockPassHash VARCHAR(255)` a `contact` para hash bcrypt del PIN del POS (fase de transición). `lockPass` plano se conserva temporalmente. | Aplicada 2026-06-25 |
| 50 | `50_lockpass_backfill.php` | Backfill: hashea los `lockPass` planos existentes a `lockPassHash` bcrypt. Script PHP, no SQL puro. | Aplicada 2026-06-25 |
| 51 | `51_lockpasshash_rename.sql` | Rename `"lockPassHash"` (quoted camelCase) → `lockpasshash` (lowercase sin quotes) para alinear con §44 (contact es tabla legacy). AutoExecute rompía porque buscaba `lockpasshash` lowercase en catalog. | Aplicada 2026-06-25 |
| 52 | `52_seed_roles.php` | Seed inicial de 3 roles por tenant: Dueño (todos los permisos), Encargado (sin billing/admin), Cajero (pos + operación básica). Viven en `taxonomy` con `type='role'` y `type='roleData'`. | Aplicada 2026-06-25 |
| 54 | `54_pos_offline_numbering.sql` | Crea tabla `numbering_lease` para el sistema de numeración offline del POS: `leaseId UUID PK`, `companyId`, `outletId`, `registerId`, `invoiceNo INT`, `leasedAt`, `consumedAt`, `expiresAt`. Permite que el POS reserve bloques de números de comprobante para operar offline. | Aplicada 2026-06-25 |
| 55 | `55_pin_sha256.sql` | Agrega columna `pinhash VARCHAR(64)` a `contact` (tabla legacy → lowercase sin quotes) + índice parcial `idx_contact_pinhash`. Reemplaza bcrypt por SHA-256 para el PIN del POS (browser-side via Web Crypto API). Ver §47 en `08-convenciones-criticas.md`. | Aplicada 2026-06-25 |
| 56 | `56_pin_sha256_backfill.php` | Backfill: hashea PINs existentes de bcrypt a SHA-256. Script PHP. | Aplicada 2026-06-25 |
| 57 | `57_simplify_seed_roles.php` | Simplifica los seed roles de 5 a 3 (drop Supervisor y Vendedor — PYMEs no necesitan jerarquía profunda). Idempotente. | Aplicada 2026-06-25 |
| 58 | `58_contact_role_varchar.sql` | `contact.role` smallint → varchar(64) para soportar UUIDs de roles custom (RoleService guarda roleId en `contact.role`; smallint no admite UUIDs). **BLOQUEANTE resuelto**: debía dropear+recrear `idx_contact_phone_tenant_unique` (predicado con `role = ANY(int[])` era incompatible con el ALTER TYPE). Ver §47 en `08-convenciones-criticas.md` sobre este patrón. Migración aplicada en prod tras fix del índice. | Aplicada 2026-06-25 |
| 59 | `59_printer_bindings.sql` | Crea tabla `printer_binding` para mapear impresoras a cajas por tenant. Mueve PrinterBinding de localStorage a BD. Columnas: `id UUID PK`, `companyId`, `registerId`, `printerName TEXT`, `transport SMALLINT` (0=USB/1=BT/2=Network/3=window.print), `config JSONB` (vendorId/productId/host/etc), `docTypes JSONB` (array de tipos habilitados), `categoryIds JSONB` (array de categorías), `createdAt`. Índice: `idx_printer_binding_company_register (companyId, registerId)`. | Aplicada 2026-06-27 |
| 60 | `60_device_browser_local_id.sql` | Agrega `browserLocalId TEXT NULL` a tabla `device` + índice único parcial `uq_device_browser_local_id (companyId, registerId, browserLocalId) WHERE status=1`. Garantiza idempotencia de pairing: mismo browser + misma caja = 1 fila activa (auto-dedup en invitation flow). | Aplicada 2026-06-27 |
| 62 | `62_device_invitation.sql` | Crea tabla `device_invitation` para el invitation-based device flow. Columnas: `id UUID PK`, `companyId`, `outletId`, `registerId`, `deviceName TEXT`, `status SMALLINT` (0=pending/1=approved/2=expired/3=cancelled), `token TEXT NULL` (JWT emitido al aprobar), `expiresAt TIMESTAMPTZ`, `createdBy UUID`, `approvedBy UUID NULL`, `approvedAt TIMESTAMPTZ NULL`, `createdAt`. Índice: `idx_device_invitation_company_status (companyId, status)`. | Aplicada 2026-06-27 |
| 63 | `63_device_module.sql` | Agrega columna `module TEXT NULL DEFAULT 'pos'` a tabla `device` para soportar futuros módulos de device (KDS, Display, etc.) con el mismo mecanismo de pairing. | Aplicada 2026-06-27 |
| 64 | `64_drop_customer_display.sql` | DROP TABLE `customer_display`. La pantalla cliente migra al device flow: ahora es un `device` con `module='screen'`. Ver §devices-screen abajo. | Aplicada 2026-06-28 |
| 65 | `65_device_invitation_user_code_idempotent.sql` | Índice único en `device_invitation(companyId, user_code)` WHERE `status=0` — garantiza idempotencia de `user_code` por tenant. | Aplicada 2026-06-28 |
| 66 | `66_contact_outlet.sql` | Crea tabla M2M `contact_outlet` (`contactId UUID FK`, `outletId UUID FK`, `companyId UUID`, PK compuesta). Permite que un usuario tenga acceso a múltiples sucursales. `contact.outletid` legacy se mantiene durante transición. | Aplicada 2026-06-28 |
| 67 | `67_strip_plus_phones.sql` | Cleanup phones legacy: strip `+` de `contact.contactPhone` y del JSONB `outlet.data` para alinear con la nueva convención de storage SIN `+` (ver §31 en context/08). | Aplicada 2026-06-29 |
| 68 | `68_device_invitation_auto_approve.sql` | Agrega columna `auto_approve BOOLEAN DEFAULT false` a `device_invitation`. Permite el reconnect flow: admin genera invitación auto-aprobada → device obtiene nuevo Bearer sin re-pair manual. `DeviceAuth::issueJwtForExistingDevice` + `DeviceInvitationService::createReconnect`. | Aplicada 2026-06-29 |

### Tabla `ai_credit_ledger` — ledger de créditos de IA (migración 28)

Registro inmutable de movimientos de créditos de IA por tenant. Cada fila es un delta (entrada o salida).

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | UUID PK | `DEFAULT gen_random_uuid()` |
| `companyId` | UUID NOT NULL | FK → `company(companyId)`. Multi-tenant scope. |
| `delta` | INT NOT NULL | Positivo = acreditación; negativo = débito |
| `balanceAfter` | INT NOT NULL | Saldo de `company.aiCreditsBalance` tras el movimiento |
| `reason` | TEXT NOT NULL | Código de motivo: `'monthly_grant'`, `'pack_purchase'`, `'api_usage'`, etc. |
| `tokensIn` | INT NULL | Tokens de input consumidos (para débitos de uso) |
| `tokensOut` | INT NULL | Tokens de output consumidos (para débitos de uso) |
| `meta` | JSONB NULL | Metadata libre por tipo de movimiento |
| `relatedInvoiceId` | UUID NULL | FK → `billing_invoice(id)` — vincula el ledger a la factura de compra de pack |
| `createdAt` | TIMESTAMPTZ | Timestamp de creación |

**Índice único parcial** `uq_ai_credit_ledger_invoice_grant`: `UNIQUE (relatedInvoiceId) WHERE relatedInvoiceId IS NOT NULL` — garantiza que cada factura acredite exactamente una vez. Idempotencia de tercer nivel (además del lock de tx y el chequeo de `Affected_Rows` en el handler del webhook).

### Tabla `billing_request` — solicitudes de cambio de plan (migración 28)

Registro de pedidos de cambio de plan del tenant al equipo de plataforma. El admin los resuelve desde el panel admin.

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | UUID PK | `DEFAULT gen_random_uuid()` |
| `companyId` | UUID NOT NULL | FK → `company(companyId)` |
| `requestedPlanCode` | SMALLINT NOT NULL | Plan al que el tenant quiere migrar |
| `currentPlanCode` | SMALLINT NOT NULL | Plan actual al momento del pedido |
| `status` | TEXT NOT NULL | `'pending'` / `'approved'` / `'rejected'` |
| `note` | TEXT NULL | Nota del admin al resolver |
| `createdAt` | TIMESTAMPTZ | — |
| `resolvedAt` | TIMESTAMPTZ NULL | Timestamp de resolución |
| `resolvedBy` | UUID NULL | FK loose → `admin_user(adminId)` |

**Invariante operativa**: `resolveRequest()` en `CompanyAdminService` usa transacción con guard 409 en doble-resolución (SELECT FOR UPDATE sobre el `billing_request` antes de actualizar status).

### Tabla `credit_pack` — catálogo de packs de créditos (migración 30)

Catálogo de packs que el tenant puede comprar. La migración incluye seed de 3 packs iniciales.

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | UUID PK | — |
| `name` | TEXT NOT NULL | Nombre del pack (display) |
| `credits` | INT NOT NULL | Créditos que acredita este pack |
| `priceUsd` | NUMERIC NOT NULL | Precio en USD |
| `active` | BOOLEAN NOT NULL DEFAULT TRUE | Soft-disable para ocultar packs sin eliminarlos |
| `createdAt` | TIMESTAMPTZ | — |

### Tabla `billing_invoice` — facturas SaaS de compra de créditos (migración 30)

Registro de facturas emitidas por compra de packs de créditos. Estado del ciclo de vida del pago.

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | UUID PK | — |
| `companyId` | UUID NOT NULL | FK → `company(companyId)` |
| `packId` | UUID NOT NULL | FK → `credit_pack(id)` |
| `credits` | INT NOT NULL | Créditos del pack al momento de la compra (snapshot) |
| `amountUsd` | NUMERIC NOT NULL | Monto cobrado (snapshot del precio del pack) |
| `status` | TEXT NOT NULL | `'pending'` / `'paid'` / `'failed'` / `'refunded'` / `'cancelled'` |
| `providerInvoiceId` | TEXT NULL | ID de la factura en dLocal Go |
| `providerMetadata` | JSONB NULL | Payload raw de la respuesta del proveedor |
| `createdAt` | TIMESTAMPTZ | — |
| `updatedAt` | TIMESTAMPTZ | — |

### Tabla `admin_audit` — log de auditoría de acciones admin (migración 29)

Registro inmutable de cada mutación ejecutada por un super-admin. El helper `adminAudit()` en `panel/API/lib/admin_auth.php` hace el INSERT de forma best-effort (nunca lanza excepción para no romper el flujo principal).

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | UUID PK | — |
| `adminId` | UUID NOT NULL | FK loose → `admin_user(adminId)` (sin CASCADE — auditoría no se borra si se borra el admin) |
| `adminEmail` | TEXT NOT NULL | Email del admin al momento de la acción (snapshot) |
| `action` | TEXT NOT NULL | Código de acción: `'updateCompany'`, `'grantAiCredits'`, `'setAddons'`, `'resolveRequest'`, `'suspendCompany'`, `'deleteCompany'`, `'impersonate'`, `'createAdmin'`, `'updateAdmin'`, `'setAdminStatus'` |
| `targetType` | TEXT NOT NULL | Tipo de entidad afectada: `'company'`, `'admin_user'`, `'billing_request'`, etc. |
| `targetId` | UUID NULL | ID de la entidad afectada |
| `targetName` | TEXT NULL | Nombre/label de la entidad al momento de la acción (snapshot) |
| `meta` | JSONB NULL | Payload del cambio (qué campos se cambiaron y a qué valores) |
| `ip` | TEXT NULL | IP del admin |
| `createdAt` | TIMESTAMPTZ | — |

**Índices**: `idx_admin_audit_admin` (adminId), `idx_admin_audit_target` (targetType, targetId), `idx_admin_audit_created` (createdAt DESC).

### Nuevas columnas en tablas existentes (migraciones 28, 30)

| Tabla | Columna nueva | Tipo | Descripción |
|-------|---------------|------|-------------|
| `company` | `aiCreditsBalance` | INT NOT NULL DEFAULT 0 | Saldo actual de créditos IA del tenant. Escribir SIEMPRE con `SELECT FOR UPDATE` + `Affected_Rows` (atomicidad). |
| `plans` | `ai_credits_monthly` | INT NOT NULL DEFAULT 0 | Créditos IA que el plan otorga mensualmente (para el cron de grant mensual). |

### Endpoint `/v1/bootstrap` — campos de sucursal (commit fd5e5b3, 2026-06-12)

El bootstrap del frontend ahora incluye campos de selector de sucursal:

| Campo | Tipo | Notas |
|-------|------|-------|
| `activeOutletId` | UUID string | Outlet activo en el JWT actual (claim `oid`) |
| `activeOutletName` | string | Nombre de la sucursal activa |
| `outlets` | array `{id, name}` | Lista de todas las sucursales activas del tenant |

**Endpoint `POST /v1/active-outlet`** (commit fd5e5b3, 2026-06-12): cambia la sucursal activa re-emitiendo un JWT panel con nuevo claim `oid`. `PanelAuth::issueJwt` acepta `?string $outletIdOverride`. El nuevo token reemplaza la cookie `_jwt_panel` en la respuesta.

### Endpoint `/v1/bootstrap` — campo `userCount` en realm POS (commit 5220d63, 2026-06-16)

El bootstrap del realm `pos-app` (`api/v1/bootstrap.php`) ahora incluye:

| Campo | Tipo | Notas |
|-------|------|-------|
| `userCount` | int | COUNT de `contact` con `type=0 AND status > 0` para el tenant. Consumido por `lock-store.ts` para activar auto-lock cuando hay más de un usuario. |

**Campos pendientes** (TODO F2 — anotados en `frontend/lib/types/bootstrap.ts`):
- `user.name` — nombre del operador logueado (para el toast de bienvenida del lock screen).
- `user.roleName` — rol del operador. La columna `roleName` ya existe en `UsersService` del backend; solo falta agregarlo al SELECT del bootstrap.

### Columnas JSONB `register.data` — hotkeys y mergeRepeated (2026-06-16)

La migración 26 demotó los campos fiscales de `register` a `register.data` JSONB. Los campos del POS React se persisten también en `register.data`:

| Key | Tipo | Notas |
|-----|------|-------|
| `hotkeys` | `Array<{itemId, position, color, isCategory}>` | Layout de acceso rápido de la caja. Persistido vía `GET/PUT /v1/register?resource=hotkeys`. Validación server-side: descarta entradas sin `itemId`. |
| `mergeRepeated` | boolean | Si `true` (default), agregar el mismo ítem consecutivamente suma qty en la última línea del carrito. Si `false`, siempre crea línea nueva. **TODO F2**: persistir en backend — hoy solo vive en memoria Zustand. |

### Regla de IVA incluido — cálculo por línea en el POS (commits b06d029 + 3ee2adb, 2026-06-16)

**Modelo Paraguay (único implementado)**: el IVA está incluido en el precio final (`itemPrice` ya incluye IVA). `TAX_RATE` = 10% hardcodeado.

Cuando el usuario activa `ivaRemoved=true` en la venta:
- Por línea: `Math.round(qty × unitPrice / 1.10)` — precio neto sin IVA.
- El total de la venta = suma de los subtotales por línea (consistencia visual: 25k + 10k + 32k = 67k, no el 60.909 que sale de dividir el total bruto).

**TODO multi-tax / multi-país**: derivar `taxRate` del campo `taxRate` por ítem del catálogo + modo (incluido/no incluido) de la config del tenant. Hoy el 10% está hardcodeado en el componente `sale-options-drawer.tsx`.

### Tablas de Packs de Servicios (migración 31, 2026-06-15)

Módulo de suscripciones/combos de servicios: define los componentes de un pack, registra las ventas de packs y los canjes individuales.

#### Tabla `pack_component` — componentes de un pack

| Columna | Tipo | Notas |
|---------|------|-------|
| `packComponentId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `packItemId` | UUID NOT NULL | FK → `item(itemId) ON DELETE CASCADE`. El ítem raíz de tipo pack. |
| `componentItemId` | UUID NOT NULL | FK → `item(itemId)`. El servicio/ítem incluido en el pack. |
| `componentQty` | SMALLINT NOT NULL DEFAULT 1 | Cantidad de este componente incluida en el pack. |
| `sort` | SMALLINT NOT NULL DEFAULT 0 | Orden de visualización. |
| `companyId` | UUID NOT NULL | Multi-tenant scope. |
| `createdAt` | TIMESTAMPTZ | — |

**Índices**: `idx_pack_component_pack` (packItemId, companyId), `idx_pack_component_company` (companyId).

#### Tabla `sold_pack` — instancia de pack vendida a un cliente

| Columna | Tipo | Notas |
|---------|------|-------|
| `soldPackId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `packItemId` | UUID NOT NULL | FK → `item(itemId)`. El ítem pack vendido. |
| `contactId` | UUID NOT NULL | FK → `contact(contactId)`. Titular del pack. |
| `transactionId` | UUID NULL | FK → `transaction(transactionId)`. Venta en que se emitió. |
| `outletId` | UUID NULL | FK → `outlet(outletId)`. Sucursal donde se vendió. |
| `companyId` | UUID NOT NULL | Multi-tenant scope. |
| `expiresAt` | TIMESTAMPTZ NOT NULL | Vencimiento del pack. |
| `status` | SMALLINT NOT NULL DEFAULT 1 | 1=activo, 0=bloqueado/vencido, 2=consumido completamente. |
| `createdAt` / `updatedAt` | TIMESTAMPTZ | — |

**Índices**: `idx_sold_pack_contact` (contactId, companyId, status), `idx_sold_pack_company` (companyId, status), `idx_sold_pack_tx` (transactionId).

**Invariantes operativas**:
- El **balance por componente** (canjes usados / total disponible) se computa en query vía `sold_pack_usage` — **nunca persistido** como columna.
- **Lazy expiry on read**: `expiresAt < NOW()` se evalúa al consultar, no hay cron que cambie status.
- Al vender un ítem tipo pack, `SaleService` crea automáticamente el `sold_pack` correspondiente.

#### Tabla `sold_pack_usage` — cada canje individual de un servicio del pack

| Columna | Tipo | Notas |
|---------|------|-------|
| `usageId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `soldPackId` | UUID NOT NULL | FK → `sold_pack(soldPackId) ON DELETE CASCADE`. |
| `packComponentId` | UUID NOT NULL | FK → `pack_component(packComponentId)`. El componente canjeado. |
| `qty` | SMALLINT NOT NULL DEFAULT 1 | Cantidad canjeada en esta operación. |
| `performedBy` | UUID NULL | FK → `contact(contactId)`. Empleado que realizó el canje. |
| `outletId` | UUID NULL | FK → `outlet(outletId)`. |
| `companyId` | UUID NOT NULL | Multi-tenant scope. |
| `notes` | TEXT NULL | Nota libre del canje. |
| `performedAt` | TIMESTAMPTZ NOT NULL DEFAULT now() | — |

**Índices**: `idx_pack_usage_sold` (soldPackId), `idx_pack_usage_company` (companyId), `idx_pack_usage_by` (performedBy WHERE NOT NULL).

---

### Tablas de Listas de Precios (migración 32, 2026-06-15)

Módulo de listas con ajuste porcentual (o precio fijo) sobre el precio base de los ítems. Soporta recargos positivos (ej. plataformas delivery +15%) y descuentos negativos (ej. lista mayorista −20%).

#### Tabla `price_list` — encabezado de la lista

| Columna | Tipo | Notas |
|---------|------|-------|
| `priceListId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `priceListName` | VARCHAR(120) NOT NULL | Nombre de la lista (display). |
| `defaultAdjustment` | DECIMAL(6,2) NOT NULL DEFAULT 0 | % de ajuste global. Negativo = descuento (ej. −10.00 = 10% off); positivo = recargo (ej. +15.00 = 15% de recargo para delivery). |
| `validFrom` | TIMESTAMPTZ NULL | Inicio de vigencia. NULL = sin restricción. |
| `validTo` | TIMESTAMPTZ NULL | Fin de vigencia. NULL = sin restricción. |
| `status` | BOOLEAN NOT NULL DEFAULT true | Activa/inactiva. |
| `companyId` | UUID NOT NULL | Multi-tenant scope. |
| `createdAt` / `updatedAt` | TIMESTAMPTZ | — |

**Índices**: `idx_price_list_company` (companyId, status).

**Asignación**: la lista se asigna a un contacto vía `contact.data->>'priceListId'` o a una sucursal vía `outlet.data->>'priceListId'` (JSONB — no columna real, no indexada).

#### Tabla `price_list_item` — override por ítem

| Columna | Tipo | Notas |
|---------|------|-------|
| `priceListItemId` | UUID PK | `DEFAULT gen_random_uuid()` |
| `priceListId` | UUID NOT NULL | FK → `price_list(priceListId) ON DELETE CASCADE`. |
| `itemId` | UUID NOT NULL | FK → `item(itemId) ON DELETE CASCADE`. |
| `fixedPrice` | DECIMAL(15,2) NULL | Precio absoluto para este ítem. Ignora `defaultAdjustment` e `itemAdjustment`. |
| `itemAdjustment` | DECIMAL(6,2) NULL | % override solo para este ítem (reemplaza `defaultAdjustment`). |
| `companyId` | UUID NOT NULL | Multi-tenant scope. |
| `createdAt` | TIMESTAMPTZ | — |

**Constraints**: `UNIQUE (priceListId, itemId)` — un ítem tiene como mucho un override por lista. `fixedPrice` e `itemAdjustment` son **mutuamente excluyentes** (validado a nivel de aplicación).

**Índices**: `idx_price_list_item_list` (priceListId, companyId), `idx_price_list_item_item` (itemId, companyId).

**Resolución de precio por prioridad** (mayor a menor precedencia):
1. Override por ítem con `fixedPrice` → precio absoluto.
2. Override por ítem con `itemAdjustment` → `itemPrice * (1 + itemAdjustment/100)`.
3. `defaultAdjustment` de la lista → `itemPrice * (1 + defaultAdjustment/100)`.
4. Lista del contacto (`contact.data->>'priceListId'`) tiene precedencia sobre lista de sucursal (`outlet.data->>'priceListId'`).
5. Sin lista asignada → precio base del ítem (`itemPrice`).

**Resolución en el POS**: el POS carga la lista completa de la lista activa al seleccionar cliente/sucursal y resuelve el precio localmente (sin round-trip por ítem).

---

### Tablas del sprint 2026-06-19..21

#### Catálogo M2M — tablas `tag` e `item_tag` (migraciones 38–39)

- **`tag`**: `tagId UUID PK`, `tagName TEXT NOT NULL`, `tagSlug TEXT`, `companyId UUID NOT NULL`. Triggers bidireccionales sincronizan con `taxonomy` (legacy). `tagName` UNIQUE case-insensitive por tenant.
- **`item_tag`**: `itemTagId UUID PK`, `itemId UUID NOT NULL FK`, `tagId UUID NOT NULL FK`, `companyId UUID NOT NULL`. UNIQUE `(itemId, tagId)`. Es el lado M2M del catálogo; el lado de marcas sigue en `taxonomy` (1→N por ítem).

#### Checkout Screen — unificada al Device Authorization Grant (migración 64, 2026-06-28)

**`customer_display` fue droppada en mig 64.** La pantalla cliente ahora es un `device` con `module='screen'` — mismo mecanismo de pairing invitation-based que el POS. El token vive en `localStorage['punto.device.token.screen']`. Endpoints de publicación (`/v1/screens/publish`, `/v1/screens?resource=context`) conviven; el pairing por PIN fue eliminado. Ver §devices-screen en `context/05-modulos-clave.md`.

#### Reports Rollup — tablas `report_rollup`, `rollup_dirty`, `item_sales`, `item_returns`, `payments` (migraciones 41–42)

Pre-agregado incremental de reportes de ventas/pagos. Estrategia: pg_cron corre `reconcile_rollup()` cada 5 min sobre la tabla `rollup_dirty`; backfill histórico vía `backfill_rollup()`. **Gateado por env var `REPORTS_ROLLUP_ENABLED`** (default OFF en prod hasta verificación numérica con `?verify=1`). Cutover de 5 reportes: `SummaryYearService`, `CategoriesService`, `BrandsService`, `PaymentMethodsService`, + `item_sales`/`item_returns`. Pendiente: RB-3 (stock/production/commissions/vpayments).

#### Agente IA — tabla `ai_model_config` (migración 43)

Config por tenant del agente IA. Columnas clave: `companyId`, `provider` (default `'openrouter'`), `model` (default `'deepseek/deepseek-chat-v3-0324'`), `creditsPerKToken INT`. El agente usa **OpenRouter** (no SDK Anthropic). 13 tools (5 lecturas + 8 escrituras con `confirmToken` de expiración real 60s). Créditos debitados atómicamente en `/v1/ai/debit` con `SELECT FOR UPDATE` sobre `company.aiCreditsBalance`. Gate 402 si saldo insuficiente. Ver context/17 para el plan completo.

### Tablas del sprint 2026-06-23

#### Módulos retail — `inventory_count`, `stock_transfer` (migraciones 46/47)

- **`inventory_count`**: sesiones de conteo físico de inventario. `status` enum `open/finished/cancelled`. Al hacer `finish()`, `InventoryCountService` llama `Inventory::manageStock` por cada ítem con `difference ≠ 0`, generando movimiento `source='inventory_count'` en el ledger.
- **`inventory_count_item`**: un row por ítem en la sesión. Campos `expected` (stock actual al abrir), `counted` (conteo ingresado por el usuario), `difference` calculado.
- **`stock_transfer`**: cabecera de transferencia entre depósitos (`fromOutletId`, `toOutletId`, `status pending/completed/cancelled`). Al completar, `StockTransferService::create` abre TX atómica y hace doble `manageStock`: egreso en origen + ingreso en destino con `source='transfer'`. Cancel reversa con `source='transfer-cancel'`.
- **`stock_transfer_item`**: ítem + quantity por transferencia.

Todas estas tablas tienen columnas en **camelCase quoted** (tablas nuevas, no legacy).

#### Variantes de producto — columnas en `item` (migración 48)

Tres columnas nuevas en la tabla legacy `item` (con quotes porque la tabla es legacy/lowercase):

| Columna | Tipo | Propósito |
|---------|------|-----------|
| `"variantParentId"` | UUID FK nullable → `item.id` | FK al ítem padre cuando este es una variante |
| `"hasVariants"` | BOOLEAN DEFAULT false | true en el ítem padre que tiene variantes |
| `"variantAttributes"` | JSONB | Array de `{name: string, value: string}` — combinación de atributos de esta variante |

**Invariantes**: un ítem padre fuerza `price=0, cost=0, stock=0`; no se puede anidar variantes (variante de una variante es 409). Bulk upsert de variantes en TX única vía `VariantService::bulkUpsertVariants`. Stock inicial de cada variante vía `Inventory::manageStock`. Hard-delete del padre devuelve 409 si tiene variantes activas.

#### `transaction`/`itemsold` particionadas por mes + `transaction_registry` (migración 156, E1 de `context/48`)

`transaction` e `itemsold` son tablas particionadas (`PARTITION BY RANGE`) por `transactiondate`/`itemsolddate` respectivamente: `<tabla>_yYYYYmMM` por mes + `<tabla>_default` como red de seguridad offline-first. `ensure_month_partitions()`/`partition_health()` (funciones PL/pgSQL genéricas) mantienen 12 meses de margen vía el job `partition-ensure` (`context/06`). La PK de ambas pasó a ser compuesta (`transactionid, transactiondate` / `itemsoldid, itemsolddate`) porque Postgres exige que la clave de partición forme parte de la PK.

**`transaction_registry`** (tabla nueva, chica, NO particionada) sostiene lo que el particionado rompe: la unicidad GLOBAL de `transactionuid` (dedup offline del POS) y del número fiscal (`uq_transaction_expedition_invoiceno`), y es el destino de las 20 FK entrantes que antes apuntaban a `transaction(transactionid)` (`itemsold`, `stock`, `totransaction`, `vpayments`, `voucher`, etc. — evita denormalizar `transactiondate` en cada una). Se sincroniza sola via triggers `AFTER INSERT`/`UPDATE` en `transaction`; el borrado usa una FK de vuelta `ON DELETE CASCADE`/`ON UPDATE CASCADE` (no un trigger a mano) — confirmado que Postgres trata el "row movement" entre particiones (UPDATE que cruza de mes) como UPDATE para la integridad referencial, no como delete+insert.

`itemsold` ganó `companyid`/`outletid`/`registerid` (denormalizadas de `transaction`, D4 de `context/48`) + índice `(companyid, itemid, itemsolddate)`, con un trigger `BEFORE INSERT` que las completa solo desde `transaction_registry` si algún insert-path no las manda explícitas. `Schema.php` (`api/lib/App/Database/Schema.php`) reconoce `relkind='p'` y expone la primera columna de una PK compuesta como la identidad de la tabla, para que `ncmInsert`/`AutoExecute` sigan generando UUID v7 igual que en una tabla no particionada.
