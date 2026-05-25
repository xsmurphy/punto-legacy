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
```

### Columnas JSONB por tabla

| Tabla | Columna JSONB | Qué guarda |
|-------|--------------|------------|
| `company` | `config` | Toda config del tenant: nombre, RUC, moneda, horarios, módulos activos, datos SIFEN |
| `item` | `data` | Campos no-indexables: descripción larga, variantes, metadata |
| `contact` | `data` | Campos descriptivos del cliente/proveedor. **Desde migración 06 (2026-05-25)** contiene también los 6 campos demotados: `contactNote`, `contactCity`, `contactLocation`, `contactCountry`, `contactAddress`, `contactAddress2`. Keys en camelCase, consistente con `item.data` (ej: `itemTaxIncluded`). |
| `transaction` | `meta` | Metadata de la venta: canal, device, notas |
| `itemSold` | `meta` | Metadata de la línea: descuentos aplicados, promo |
| `outlet` | `data` | Config específica de sucursal |
| `register` | `data` | Config específica de caja |

**Regla de diseño JSONB (decisión 2026-05-25):** solo van como columnas reales los campos que necesitan índice o cálculo SQL. Lo estrictamente descriptivo/estático va a `data` JSONB. Aplicado:
- **Quedan como columnas** en `contact`: `contactName`, `contactEmail`, `contactPhone`, `contactTIN`, `contactCI`, `contactStatus`, `contactType`, `contactStoreCredit`, `contactLoyalty`, financieros indexados.
- **Van a `contact.data`**: note, city, location, country, address, address2 (y cualquier campo descriptivo futuro).
- El mismo patrón aplica a `item` (demotion de items pendiente, diferida).

### Funciones PHP de routing JSONB

| Función | Qué hace |
|---------|----------|
| `_flattenJsonb($row, $jsonCol)` | Lee: aplana columna JSONB al row PHP |
| `_getTableSchema($table)` | Introspección: devuelve columnas reales de la tabla |
| `_routeToJsonb($table, $data)` | Escritura: separa campos reales vs JSONB automáticamente |
| `ncmInsert($table, $data)` | INSERT con UUID v7 auto + routing JSONB |
| `ncmUpdate($table, $data, $where)` | UPDATE con routing JSONB |

**Tablas registradas en `_getTableSchema()` (whitelist):** incluye `contact` (sin las 6 columnas demotadas en migración 06) y `customerAddress` (pk=`customerAddressId`, agregada en commit 01d6eba — su ausencia causaba que `ncmInsert` inyectara una columna `id` inexistente y silenciara el error).

### Invariantes del schema

1. **companyId es NOT NULL** en toda tabla de datos de tenant
2. **UUID v7 ordenable por tiempo** — permite ORDER BY id para orden cronológico
3. **config JSONB en company** — acceso via `config->>'settingName'`, `config->>'settingRUC'`, etc.
4. **No hay CASCADE DELETE** en FKs principales — las eliminaciones son soft (status/flag)
5. **Timestamps**: `createdAt` (auto), `updatedAt` (trigger), timezone: `America/Asuncion`
6. **JSONB vs columna real** — campos indexables, buscados, o usados en cálculos SQL son columnas reales; campos descriptivos/estáticos van a `data` JSONB. Violaciones se corrigen con migraciones (ej: `06_contact_jsonb_demote.sql`).
7. **Columnas BOOLEAN en PG** — comparar con `= true` / `= false`, nunca `= 1` / `= 0`. Error PG: `operator does not exist: boolean = integer`. Sitios pendientes de corregir: `panel/includes/functions.php:3464,3790` y `app/action.php`, `app/load.php`, `app/fetch.php`, `app/fetchs.php`.

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
