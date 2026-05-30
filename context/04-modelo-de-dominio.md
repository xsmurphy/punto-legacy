<!-- REGLA: Actualizar cuando se cree/elimine una tabla, se agregue un campo indexado,
     o cambie una invariante del schema. NO actualizar por cambios a campos JSONB internos. -->

# 04 — Modelo de Dominio

## Schema: PostgreSQL v2

Diseñado para PostgreSQL 16+. Archivo fuente: `db-schema-postgres.sql` (54KB).

### Principios de diseño

1. **UUID v7 como PK** en todas las tablas (via `gen_random_uuid()`)
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
2. **UUID v7 ordenable por tiempo** — permite ORDER BY id para orden cronológico
3. **config JSONB en company** — acceso via `config->>'settingName'`, `config->>'settingRUC'`, etc.
4. **No hay CASCADE DELETE** en FKs principales — las eliminaciones son soft (status/flag)
5. **Timestamps**: `createdAt` (auto), `updatedAt` (trigger), timezone: `America/Asuncion`
6. **JSONB vs columna real** — campos indexables, buscados, o usados en cálculos SQL son columnas reales; campos descriptivos/estáticos van a `data` JSONB. Violaciones se corrigen con migraciones. Ejemplos aplicados: `06_contact_jsonb_demote.sql` (6 campos) y `07_item_jsonb_demote.sql` (4 campos: itemImage/itemTaxExcluded/itemDiscount/itemUOM). Criterio de auditoría para decidir: 0 apariciones en WHERE/ORDER/JOIN/GROUP/SUM en todo el repo (grep).
7. **Columnas BOOLEAN en PG** — comparar con `= true` / `= false`, nunca `= 1` / `= 0`. Error PG: `operator does not exist: boolean = integer`. Sitios pendientes de corregir: `panel/includes/functions.php:3464,3790` y `app/action.php`, `app/load.php`, `app/fetch.php`, `app/fetchs.php`.
8. **Acceso ≠ propiedad** — la pertenencia de un tenant a un franquiciador (`franchiser_to_tenant`) es solo una capa de acceso/gestión; NO afecta dueño, plan ni facturación, que son siempre per-tenant en `company`. Ver ADR-001.

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

**Patrón atómico de demotion:** backfill UPDATE no-destructivo (NULLIF para no inflar con defaults; booleans como JSON booleans, no strings) + DROP atómico en el mismo script. Requiere ser owner de la tabla en PG (el usuario `punto` de la app no lo es — ver `06-infraestructura.md §Privilegio de owner para DDL`).
