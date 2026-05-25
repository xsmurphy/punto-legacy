<!-- REGLA: Actualizar cuando se agregue un módulo/servicio nuevo, cambie la responsabilidad
     de uno existente, o se agreguen endpoints relevantes. NO actualizar por bug fixes internos. -->

# 05 — Módulos Clave

## /app — Módulo Operativo (POS)

**Propósito**: Interfaz principal de operación diaria. Caja, facturación, mesas, órdenes,
delivery, calendario de citas, agendamientos.

**Entry point**: `app/index.php` (HTML + PHP template)

**Dispatcher principal**: `app/action.php` (~143KB)
- Decodifica parámetro `l=` (base64) para extraer acción + metadata
- Valida JWT (cookie `_jwt`), fallback a legacy Hashids
- 80+ acciones: clockIn, setCurrencies, addSale, getItems, etc.
- Rate limiting: 80 requests/minuto por register

**API endpoints** (`app/API/`):
- `auth.php` — Login, emite JWT
- `config.php` — Configuración del tenant para el POS
- `refresh.php` — Refresh token

**Archivos clave**:
- `app/includes/functions.php` — Utilidades (pagos, formateo, roles)
- `app/includes/jwt.php` — JWT HS256 encode/decode
- `app/includes/jwt_middleware.php` — Validación de JWT
- `app/includes/ws_publish.php` — Publica eventos a Redis
- `app/includes/db.postgres.php` — Conexión a PostgreSQL
- `app/scripts/ncm-ws.js` — Cliente WebSocket

**Frontend**: Bootstrap 3 + jQuery, service worker para offline.

---

## /panel — Panel de Control Admin

**Propósito**: Gestión del negocio. Dashboard, inventario, clientes, facturación,
reportes, configuración de módulos, usuarios.

**Entry point**: `panel/index.php` (SPA con sesión PHP)

**Páginas** (`panel/a_*.php`, 80+ archivos):
- `a_dashboard.php` (91KB) — Analytics, resúmenes, datos real-time
- `a_items.php` (201KB) — Inventario/productos
- `a_contacts.php` (140KB) — Clientes y proveedores [backend modernizado 2026-05-25: `lib/contacts/` + `API/v1/contacts.php` + `scripts/api/contacts.js`; front legacy aún activo como fallback; pendiente cablear UI + custom records]
- `a_billing.php` (23KB) — Facturación
- `a_modules.php` — Feature toggles por rubro
- `a_reports.php` — Reportes
- Otros: purchase, registers, outlets, users, settings...

**API** (`panel/API/`, ~93 endpoints):
- Lib: `panel/API/lib/response.php` (envelope canónico)
- Lib: `panel/API/lib/api_middleware.php` (JWT + fallback)
- Auth: `panel/API/auth.php` (login panel)
- CRUD: add/edit/delete/get para cada entidad
- Estado: 10/93 migrados a envelope canónico, 83 legacy

**Archivos clave**:
- `panel/includes/functions.php` (~282KB) — Mega-utilidades
- `panel/includes/simple.config.php` — Constantes globales (WS_URL, etc.)
- `panel/includes/jwt.php` — JWT para panel
- `panel/includes/ws_publish.php` — Publica a Redis
- `panel/includes/db.php` → `db.postgres.php` — Conexión BD
- `panel/includes/secure.php` — CORS, headers de seguridad

---

## /panel/standalone — Pantallas independientes

**Propósito**: Vistas que corren en dispositivos dedicados (cocina, mostrador).

| Pantalla | Archivo | Canal WS | Uso |
|----------|---------|----------|-----|
| KDS (Kitchen Display) | `kds.php` + `kds.js` | `{outletId}-KDS` | Pantalla de cocina |
| KDS v2 | `kds2.php` | `{outletId}-KDS` | Variante |
| CDS (Customer Display) | `cds.php` + `cds.js` | `{outletId}-KDS` | Pantalla cliente |
| Checkout Screen | `checkoutScreen.php` | `{companyId}-{regId}-register` | Display de caja |

---

## /ws-server — WebSocket Microservice

**Propósito**: Reemplaza Pusher. Bridge real-time PHP → Browser.

**Stack**: Node.js 20 + ws@8.17 + ioredis@5.3

**Archivo único**: `ws-server/index.js` (229 líneas)

**Protocolo**:
```
Client → WS: { action: "subscribe", channel: "outlet123-KDS" }
PHP → Redis: PUBLISH punto:channel:outlet123-KDS '{...}'
WS → Client: { event: "order", channel: "outlet123-KDS", data: {...} }
```

**Canales**:
- `{outletId}-KDS` — Órdenes para cocina
- `{companyId}-{regId}-register` — Eventos de caja
- `ncm-ePOS` — Broadcasts del panel

**Config**: Puerto 6001, heartbeat 30s, auto-reconnect con backoff exponencial.

---

## /database — Migraciones y Seeds

**Migraciones**: `database/migrations/postgres/`
- `03_push_subscriptions.sql` — Web Push suscripciones

**Seeds**: `database/seeds/`
- `01_base.sql` — Planes, bancos, catálogo base
- `02_panel_user.sql` — Super admin
- `03_catalog.sql` — Catálogo de productos demo
- `04_sample_items.sql` — Items de ejemplo
- `run_seeds.sh` — Runner de seeds

---

## /scripts — Utilidades

| Script | Propósito |
|--------|-----------|
| `postgres-init.sql` | Extensiones + timezone al crear BD |
| `convert-schema.py` | Conversión MySQL → PostgreSQL |
| `migrate-mysql-to-postgres.sh` | Migración de datos |
| `mysql-to-postgres.sh` | Variante de migración |
| `setup-local.sh` | Setup del entorno local |
