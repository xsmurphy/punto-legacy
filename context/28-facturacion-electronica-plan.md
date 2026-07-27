# Facturación Electrónica (Automate / SIFEN) — plan del módulo

> Estado: **F0 implementada** (2026-07-27). F1–F4 pendientes.
> Decisiones cerradas con el owner el 2026-07-27 — no relitigar sin motivo nuevo.

## Por qué

Punto no emite facturación electrónica. Lo único que existía era un resto del
legacy: `sendFE`/`consultFE` (`api/includes/functions.php:2229,2273`),
`SaleService::dispatchElectronicInvoice`, `api/lib/services/ElectronicInvoiceService.php`
y `api/v1/electronic_invoice.php`, todo contra un proveedor genérico con un **token
global de entorno** (`FACTURACION_ELECTRONICA_TOKEN`) y disparado sólo si la venta
traía `electronicInvoicePY`. Ese campo **no lo produce ningún cliente del stack
moderno** (grep en `frontend/` = 0): el camino está muerto desde la fusión del POS.

Se integra **Automate** (`https://app.automate.com.py`) como proveedor real de FE
para Paraguay.

## Decisiones cerradas

| Decisión | Elegido |
|---|---|
| Credenciales | **Cuenta propia por comercio** — usuario/contraseña por tenant, cifrados |
| Disparo | **Automática en toda venta**, con outbox + reintentos (no fire-and-forget) |
| Numeración | **Automate es el emisor fiscal** — su número + CDC SON el documento |
| Ítems fuera de contrato | **Colapsar la línea a 1 × total** |
| Config | Sector propio dentro de **Módulos** (`einvoicePy` → `/settings/facturacion-electronica`) |

## La API de Automate (spec en `GET /api/docs/json`)

| Hallazgo | Consecuencia |
|---|---|
| `POST /api/v1/auth/login` (user/pass) → JWT 24 h; `refresh-credentials` **vuelve a pedir la contraseña** | La contraseña se guarda **reversible** (AES-256-GCM), no hasheada. Un re-`login` es equivalente al refresh y más simple |
| Emisión en 2 pasos: `POST /invoices/preview` (borrador **en Redis del lado de Automate**) → `POST /invoices/confirm` **sin body** | El borrador es estado de sesión por credencial: dos cajas emitiendo a la vez se pisan. **Serializar por companyId** con `pg_advisory_xact_lock` (patrón de `OrderCoreService.php:168`) |
| `items[].qty` entero ≥ 1 y `items[].price` entero ≥ 1 | Kilos/decimales y líneas a precio 0 no son representables → regla de colapso |
| El payload no lleva IVA por ítem, ni establecimiento, ni punto de expedición, ni número | Automate los deriva de la cuenta del emisor |
| `GET /invoices/{cdc}/pdf` (KuDE); `POST /invoices/{cdc}/cancel` (motivo 10–500 chars); CDC = 44 dígitos | El CDC es el identificador fiscal a persistir |
| `POST /customers/lookup` resuelve RUC/CI **con fallback al registro de la SET** | Reusable para autocompletar razón social en Contactos (F3) |

Las respuestas **no están tipadas en el spec**: se parsea defensivamente y se guarda
la respuesta cruda (`einvoice_account.emitter`, `einvoice_document.provider_response`)
para poder ajustar sin adivinar.

### Numeración — decisión (revisada 2026-07-27)

La primera decisión fue *"Punto numera, Automate espeja"*, pero `preview` **no expone**
campo de número, establecimiento ni punto de expedición, y `confirm` no lleva body. Sin
campo, no hay soporte. **Decisión final: Automate es el emisor fiscal.**

Consecuencias para F1/F2:

1. En un comercio con el módulo activo, **el número y el CDC de Automate son el
   documento fiscal**. Punto deja de asignar timbrado propio a esas ventas.
2. El correlativo interno (`transaction.authNo`, `registerInvoiceNumber`) sigue
   existiendo como **referencia operativa** y se guarda en
   `einvoice_document.punto_number` para trazabilidad — no es el número fiscal.
3. **El ticket fiscal no se puede imprimir hasta que Automate responda.** Eso convierte
   la latencia del proveedor en parte del flujo de caja: F1 tiene que definir qué se
   imprime en el momento (comprobante no fiscal / "factura en camino") y cómo se
   reimprime cuando llega el CDC. Es el punto más delicado del módulo — se cierra con
   el owner antes de implementar F1.
4. La impresión del comprobante fiscal usa los datos que devuelve Automate (o el KuDE
   vía `GET /invoices/{cdc}/pdf`), no la plantilla de factura propia de Punto.

## Arquitectura

### `api/lib/EInvoice/`

| Archivo | Rol |
|---|---|
| `EInvoiceProvider.php` | Interfaz. F0 usa `login`/`me`/`paymentMethods`; `issue`/`cancel`/`pdf`/`lookupCustomer` declaradas y tirando `LogicException` hasta F1 |
| `AutomateProvider.php` | Cliente cURL contra `AUTOMATE_BASE_URL`. Timeouts explícitos, parseo defensivo del token |
| `AutomateSession.php` | Resuelve un bearer válido por company: reusa el token cacheado si le quedan > 5 min, si no re-loguea con la credencial del vault |
| `CredentialVault.php` | AES-256-GCM con `APP_ENCRYPTION_KEY`. Formato `base64(iv[12] ‖ tag[16] ‖ ciphertext)` |
| `EInvoiceService.php` | F0: `getAccount`/`saveAccount`/`testConnection`/`paymentMethods`. F1 suma enqueue/drain/cancel/retry |

El proveedor va detrás de una interfaz para no casar el módulo con Automate y para
poder retirar el camino legacy entero en F4.

### Schema (mig 92)

- **`einvoice_account`** — 1 fila por company: credenciales cifradas, `status`
  (`unconfigured`/`ok`/`auth_error`), `emitter` jsonb (respuesta cruda de `/auth/me`),
  `config` jsonb (`autoIssue`, `onlyWithTaxId`, `paymentMethodMap`, …).
- **`einvoice_document`** — outbox + libro de documentos. `UNIQUE (companyid,
  transactionid, doctype)` es la idempotencia dura: una venta no se factura dos veces
  aunque el drainer corra en paralelo. Índice parcial sobre `next_retry_at` para el
  drainer.

> **Trampa conocida**: `Query::flattenJsonb()` (`api/lib/App/Database/Query.php:52`)
> aplana automáticamente toda columna llamada `data`/`meta`/`config` en cualquier fila
> leída por `ncmExecute`. Todo `SELECT` que traiga `einvoice_account.config` **debe**
> alias-earla (`config AS account_config`) — no se toca el helper compartido, tiene
> 1035+ callers.

### Flujo de emisión (F1)

1. **Enqueue transaccional** — la fila `einvoice_document` en `pending` se inserta
   dentro de la transacción de la venta. Es escritura local: si la venta commitea, el
   documento a emitir existe sí o sí. Reemplaza el hook best-effort
   `dispatchElectronicInvoice`.
2. **Intento inline post-commit** — best-effort, para que la venta normal se facture
   en el acto.
3. **Drainer con reintentos** — `POST /v1/einvoice?action=drain` protegido por secreto
   compartido, invocado por cron del sistema en el server (no hay infraestructura de
   jobs en el repo y `pg_cron` no hace HTTP). Toma el advisory lock por companyId
   **antes** de `preview` y lo suelta después de `confirm`. Backoff exponencial sobre
   `next_retry_at`.

### Regla de colapso (`SaleToInvoiceMapper`, F1)

- Línea con `qty` decimal, precio no entero o precio < 1 → `qty:1`,
  `name: "<item> (1,35 kg)"`, `price: round(totalDeLínea)`; descuentos de línea
  plegados en el precio.
- **Invariante**: `sum(qty*price) === totalAmount`. El residuo de redondeo se ajusta en
  la última línea. Si aun así no cierra → documento en `error` con motivo explícito.
  **Nunca** se emite un documento cuyo total difiera de la venta.

### Endpoints — `api/v1/einvoice.php`

Realm `panel`, escritura gateada por `einvoice.manage`. Query params `resource`/`action`
(patrón de `api/v1/production.php`).

- `GET ?resource=account` · `POST ?action=account` · `POST ?action=test`
- `GET ?resource=paymentMethods` (proxy de los códigos de Automate)
- F1: `?resource=documents`, `?action=retry|cancel|drain`, `?resource=pdf`

**El frontend nunca habla con Automate**: la credencial no sale del backend y no hay
CORS de terceros en el navegador del cajero.

### Frontend

`einvoicePy` en `frontend/lib/modules-catalog.ts` (categoría Facturación) con
`configHref` — campo nuevo del catálogo: si está seteado, *Configurar* navega en vez de
abrir `ModuleConfigDialog`. Es la salida limpia para módulos cuya config es una página,
no un form chico. Página en `settings/facturacion-electronica` + componente
`components/settings/einvoice-manager.tsx` + hook `hooks/use-einvoice.ts`.

## Fases

| Fase | Alcance | Estado |
|---|---|---|
| **F0** | Migs 92/93, vault, provider/session, módulo + página de config con *Probar conexión* | **Hecha** |
| **F1** | Mapper, outbox, drainer, enqueue en `SaleService`, cron, estado en transacciones | Pendiente |
| **F2** | DataTable de documentos, KuDE PDF, cancelación, reintento manual | Pendiente |
| **F3** | Mapping de medios de pago, TC preferidos (`/codes/fx-prefs`), lookup de RUC en Contactos, notas de crédito | Pendiente |
| **F4** | Rip-out del FE legacy (`sendFE`/`consultFE`, `FACTURACION_ELECTRONICA_*`, `dispatchElectronicInvoice`, `ElectronicInvoiceService`, `api/v1/electronic_invoice.php`, `SaleInput::electronicInvoicePY`) | Pendiente |

## Infra

- `APP_ENCRYPTION_KEY` — base64 de 32 bytes. **Sin ella el vault no arranca** y el
  módulo queda `unconfigured`. Generar con `openssl rand -base64 32`.
- `AUTOMATE_BASE_URL` — default `https://automate.com.py`, override para staging.
- F1: `EINVOICE_DRAIN_SECRET` + entrada de cron en el server de producción.
