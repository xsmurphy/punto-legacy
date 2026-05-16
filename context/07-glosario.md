<!-- REGLA: Actualizar cuando se introduzca un término nuevo que tenga significado específico
     en el proyecto, o cuando un término existente cambie de significado. -->

# 07 — Glosario

## Términos de negocio

| Término | Significado en Punto |
|---------|---------------------|
| **Tenant** | Una empresa/negocio cliente. Identificado por `companyId`. |
| **Outlet** | Sucursal física del tenant. Un tenant puede tener múltiples outlets. |
| **Register** | Caja/terminal de punto de venta dentro de un outlet. |
| **Transaction** | Cualquier operación financiera: venta, compra, nota de crédito, devolución. |
| **Item** | Producto o servicio que se vende. |
| **Contact** | Cliente o proveedor del tenant (dual-purpose). |
| **KDS** | Kitchen Display System — pantalla de cocina que muestra órdenes. |
| **CDS** | Customer Display System — pantalla que ve el cliente en caja. |
| **DE** | Documento Electrónico (factura electrónica SIFEN). |
| **SIFEN** | Sistema Integrado de Facturación Electrónica Nacional (Paraguay). |
| **Crédito IA** | Unidad de consumo de IA del cliente. 1 crédito = 1,000 tokens internos. |

## Términos técnicos del código

| Término | Significado |
|---------|-------------|
| **ncmInsert** | Función de INSERT universal. Auto-genera UUID v7, routea campos a JSONB. |
| **ncmUpdate** | Función de UPDATE universal con routing JSONB. |
| **enc()/dec()** | Identity functions (ex-Hashids). Reciben y devuelven el mismo valor. Legacy. |
| **wsPublish()** | Publica evento a Redis via raw RESP (fsockopen). No usa extensión Redis de PHP. |
| **NcmWS** | Clase JS cliente WebSocket. Drop-in replacement de Pusher. |
| **apiMiddleware()** | Middleware JWT del panel. Define constantes: COMPANY_ID, USER_ID, OUTLET_ID. |
| **apiOk() / apiError()** | Helpers del envelope canónico de respuesta API. |
| **_flattenJsonb()** | Lee columna JSONB y aplana sus keys al row PHP como si fueran columnas. |
| **_routeToJsonb()** | Separa automáticamente campos reales vs campos que van a JSONB. |
| **_getTableSchema()** | Introspección: devuelve lista de columnas reales de una tabla PG. |
| **config JSONB** | Columna en `company` que absorbe toda la configuración del tenant. |
| **action.php** | Dispatcher de /app. Recibe `l=` (base64 encoded), despacha la acción. |
| **api_head.php** | Include legacy de endpoints del panel. Reemplazado por `api_middleware.php`. |
| **standalone** | Vistas que corren independientes del panel (KDS, CDS, checkout). |
| **envelope canónico** | `{ ok: true, data, meta }` o `{ ok: false, error }` — formato estándar API. |
| **god node** | Archivo con muchas dependencias. Cambios ahí tienen alto riesgo de ruptura. |

## Abreviaturas frecuentes

| Abrev | Significado |
|-------|-------------|
| PG | PostgreSQL |
| WS | WebSocket |
| RT | Real-time |
| FE | Facturación electrónica |
| JWT | JSON Web Token |
| PK | Primary Key |
| FK | Foreign Key |
| CRUD | Create, Read, Update, Delete |
| KDS | Kitchen Display System |
| CDS | Customer Display System |
| POS | Point of Sale |
