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
| `contact` | `data` | Campos extensibles del cliente/proveedor |
| `transaction` | `meta` | Metadata de la venta: canal, device, notas |
| `itemSold` | `meta` | Metadata de la línea: descuentos aplicados, promo |
| `outlet` | `data` | Config específica de sucursal |
| `register` | `data` | Config específica de caja |

### Funciones PHP de routing JSONB

| Función | Qué hace |
|---------|----------|
| `_flattenJsonb($row, $jsonCol)` | Lee: aplana columna JSONB al row PHP |
| `_getTableSchema($table)` | Introspección: devuelve columnas reales de la tabla |
| `_routeToJsonb($table, $data)` | Escritura: separa campos reales vs JSONB automáticamente |
| `ncmInsert($table, $data)` | INSERT con UUID auto + routing JSONB |
| `ncmUpdate($table, $data, $where)` | UPDATE con routing JSONB |

### Invariantes del schema

1. **companyId es NOT NULL** en toda tabla de datos de tenant
2. **UUID v7 ordenable por tiempo** — permite ORDER BY id para orden cronológico
3. **config JSONB en company** — acceso via `config->>'settingName'`, `config->>'settingRUC'`, etc.
4. **No hay CASCADE DELETE** en FKs principales — las eliminaciones son soft (status/flag)
5. **Timestamps**: `createdAt` (auto), `updatedAt` (trigger), timezone: `America/Asuncion`

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
