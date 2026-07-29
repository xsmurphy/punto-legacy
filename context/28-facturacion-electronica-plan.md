# Facturación Electrónica (Factomate / SIFEN) — plan del módulo

> Estado: **F0 implementada** (2026-07-28, sobre Factomate — ver pivot abajo). F1–F4 pendientes.
> Decisiones cerradas con el owner el 2026-07-27 (numeración: ver nota, quedó reabierta el 2026-07-28) — no relitigar sin motivo nuevo.

## Por qué

Punto no emite facturación electrónica. Lo único que existía era un resto del
legacy: `sendFE`/`consultFE` (`api/includes/functions.php:2229,2273`),
`SaleService::dispatchElectronicInvoice`, `api/lib/services/ElectronicInvoiceService.php`
y `api/v1/electronic_invoice.php`, todo contra un proveedor genérico con un **token
global de entorno** (`FACTURACION_ELECTRONICA_TOKEN`) y disparado sólo si la venta
traía `electronicInvoicePY`. Ese campo **no lo produce ningún cliente del stack
moderno** (grep en `frontend/` = 0): el camino está muerto desde la fusión del POS.

## Pivot de proveedor (2026-07-28) — leer antes de tocar este módulo

La F0 original (2026-07-27) se implementó contra **Automate**
(`https://app.automate.com.py`), asumiendo que era el motor real de
facturación electrónica para Paraguay. **Es un error**: Automate es OTRO
cliente de Factomate, exactamente igual que Punto va a serlo. El motor real
— el que firma, timbra y manda a SIFEN — es **Factomate** (a.k.a. efatech,
`https://factomatedev.tech-precision.com` en test).

El error se detectó antes de implementar emisión real (F0 solo conecta la
cuenta y lee el timbrado), así que el costo del pivot se limitó a: reemplazar
el cliente HTTP (`AutomateProvider`/`AutomateSession` → `FactomateProvider`/
`FactomateSession`), extender el schema (mig 95, correctiva sobre la 92) y
reescribir este documento. Lo agnóstico del proveedor —
`CredentialVault`, la interfaz `EInvoiceProvider`, el módulo del catálogo, el
permiso `einvoice.manage`, la estructura de `EInvoiceService`, el endpoint
`api/v1/einvoice.php`, el hook y la página de settings — se conservó sin
tocar el diseño, solo los tipos/firmas que dependían del shape real de la
API.

La guía real de integración (escrita desde una implementación en
producción, con las minas ya pisadas) vive fuera de este repo:
`/Users/xstian/Dropbox/Automate/Agent/context/09-integracion-factomate.md`
+ `factomate-endpoints.md` en la misma carpeta. Es la fuente de verdad para
todo lo que sigue — este doc resume lo relevante para Punto, no la
reemplaza.

## Decisiones cerradas

| Decisión | Elegido |
|---|---|
| Proveedor | **Factomate** (motor real de SIFEN) — no Automate |
| Credenciales | **Cuenta propia por comercio** — usuario/contraseña/teléfono por tenant, cifrados |
| Disparo | **Automática en toda venta**, con outbox + reintentos (no fire-and-forget) — sin cambios por el pivot |
| Numeración | **La asigna la SET** (`number: -1`) — un solo dueño del correlativo; ver §Numeración |
| Ítems fuera de contrato | **Cae la regla de "colapsar a 1 línea"** (ver §Armado del documento) — Factomate no tiene el límite de qty/price entero que tenía Automate |
| Config | Sector propio dentro de **Módulos** (`einvoicePy` → `/settings/facturacion-electronica`) — sin cambios |
| Offline | **Se vende y se emite diferido** al recuperar conexión, una venta por vez (ver §Offline). Bloquear la venta quedó descartado |
| Impresión | **No se toca.** Aclarar que el impreso no es fiscal es obligación legal del comercio, no de Punto |
| Portal del cliente | Link firmado **por venta** impreso en el comprobante; el listado por RUC exige segundo factor (ver §Portal) |

## La API de Factomate

### Autenticación — dos pasos, no uno

```
POST /Token                    usuario + contraseña        → bearer 15 min, 1 uso
POST /api/account/PhoneLogin   bearer + header phonenumber → bearer 24 h (éste se cachea)
```

El bearer de `/Token` **solo sirve para probar que se conoce la contraseña**:
se usa una vez para pedir el bearer real vía `PhoneLogin` y se descarta. El
de `PhoneLogin` es el que se cachea (`token_enc`/`token_expires_at`, igual
patrón que la F0 original) y se usa para todo el resto.

`/Token` es quisquilloso con el formato del usuario: emails tal cual,
teléfonos en formato local **sin `+`** — coincide con la convención de
teléfonos del proyecto (storage sin `+`, `api/includes/phone.php`), se
reusa esa convención en vez de inventar otra.

### Dos reglas de headers que rompen todo (costaron horas de debugging ajeno)

1. **El header `phonenumber` va en TODAS las llamadas autenticadas.** Sin
   él, `/api/sincro/config` revienta con `The given header was not found`.
   Es el teléfono del titular de la cuenta, cifrado en
   `einvoice_account.phone_enc` igual que la contraseña (es tan "factor de
   auth" como ella).
2. **Nunca mandar `Content-Type` en un GET, ni en un POST sin body.**
   Varios endpoints — sobre todo los que devuelven binario (`getkude`) —
   responden `500 An error has occurred`: ASP.NET Web API intenta
   deserializar un body inexistente y explota.

### La emisión es SINCRÓNICA — sin preview/confirm, sin borrador en Redis

Automate tenía un flujo en dos pasos (`preview` → `confirm`) con el
borrador viviendo en Redis del lado de Automate — eso fue lo que motivó,
en el plan viejo, la idea de serializar con `pg_advisory_xact_lock` por
companyId (dos cajas emitiendo a la vez se pisarían el borrador
compartido).

**Factomate no tiene ese problema**: `POST /api/electronicDocument/Bulk` es
una sola llamada que devuelve el documento firmado, con el CDC en
`Items[0].CDC`, en la misma respuesta. No hay estado intermedio compartido
entre dos requests — **cae la necesidad del advisory lock por borrador
compartido**. Lo que SÍ se mantiene sin cambios es la idempotencia dura:
`UNIQUE(companyid, transactionid, doctype)` en `einvoice_document` (mig 92)
sigue siendo la garantía de que una venta no se factura dos veces, ahora
simplemente porque dos intentos concurrentes del mismo `transactionid`
van a chocar contra el constraint, no porque haga falta serializar un
borrador.

Chequear igual `Items[0].Success === false` → usar `StatusMessage` como
motivo del rechazo y no intentar bajar el PDF.

**Riesgo conocido — rechazo asíncrono de SIFEN**: un CDC devuelto por
`/Bulk` no garantiza aceptación definitiva. SIFEN puede rechazar el DE
minutos después (ej. código 1002, duplicado) y recién ahí poblar el
resultado real. `einvoice_document.sifen_status`/`sifen_result`/
`sifen_checked_at` (mig 95) son donde F1/F2 van a persistir esa
reconciliación, consultando `GET /api/ElectronicDocument/GetAll` (o
`getBulk/{id}`) más tarde — el `status` de mig 92 (pending/sending/
issued/...) sigue siendo el estado del OUTBOX de Punto (¿se mandó?),
`sifen_status` es el estado FISCAL real (¿SIFEN lo aceptó?), son dos cosas
distintas que se completan en momentos distintos.

**El KuDE llega tarde**: Factomate tarda 3–8 s entre aceptar el `/Bulk` y
terminar de generar el XML firmado + el KuDE. Llamar `getkude` de
inmediato devuelve `500`. F1/F2 deben reintentar con backoff lineal
(1 s, 2 s, 3 s) y **solo ante 5xx** — un 4xx (CDC mal formado) se tira de
una. El PDF es **opcional**: si `getkude` falla, la factura ya se emitió
igual; ofrecer un botón "descargar PDF" que reintente después.

### Numeración — REABIERTA (revierte la decisión de la F0 original)

La F0 original cerró "Automate es el emisor fiscal" porque Automate no
exponía ningún campo de número/establecimiento/punto de expedición en su
`preview`/`confirm`. **Esa premisa no aplica a Factomate**: el campo
`number` de `POST /api/electronicDocument/Bulk` SÍ acepta:

- Un correlativo propio — pero **solo la parte `NNNNNNN`** de
  `EEE-PPP-NNNNNNN`; establecimiento y punto de expedición salen siempre
  del timbrado (`stamp.Id` de sincro/config). Si Punto manda numeración
  propia, tiene que coincidir en `EEE-PPP` con el timbrado o SIFEN
  rechaza — y los duplicados también los rechaza SIFEN.
- `-1` — para que la SET asigne el número.

**Decisión cerrada con el owner (2026-07-28): numera la SET — `number: -1`.**

El motivo no es comodidad, es que el correlativo tiene **un solo dueño
legítimo**: el titular del timbrado. Si Punto también numera hay dos
escritores del mismo correlativo, sin forma de garantizar que no se pisen —
varias cajas emitiendo, ventas que fallan y dejan huecos que después hay que
justificar ante la SET, y el punto de expedición de cada caja teniendo que
mantenerse en sincronía con el `EEE-PPP` del timbrado de Factomate (una
segunda fuente de verdad que va a driftear). SIFEN rechaza duplicados, así
que el error no sería silencioso, pero **la multa la paga el tenant**.

Consecuencias:

1. En un comercio con el módulo activo, Punto **deja de asignar timbrado
   propio** a esas ventas. `punto_number` (mig 92) queda como correlativo
   interno de referencia operativa, nunca como número fiscal. Dos
   numeraciones sobre el mismo hecho imponible es exactamente lo que se está
   evitando.
2. El número fiscal viene de vuelta en la misma llamada que el CDC
   (`/Bulk` es sincrónico), así que el ticket puede imprimirse con el número
   real casi en el acto.
3. Sin número fiscal disponible offline — resuelto bloqueando la venta, ver
   §Offline.

### Regla fiscal PY que encuadra todo esto

**Para un comercio habilitado como facturador electrónico, el comprobante
impreso deja de ser un documento fiscal válido.** El documento fiscal es el
electrónico (el DE firmado, identificado por su CDC); lo que se imprime es la
representación gráfica (KuDE). El comprobante impreso solo tiene validez
fiscal en comercios que **no** son facturadores electrónicos.

Consecuencia concreta: no existe "imprimir un ticket fiscal mientras esperamos
a Factomate". O hay DE emitido, o no hay documento.

**Lo que NO es responsabilidad de Punto** (decisión del owner, 2026-07-29):
aclarar en el impreso que no es un comprobante fiscal válido es obligación
legal del comercio facturador electrónico, no del software. **No se toca el
flujo de impresión ni las plantillas de Factura por este motivo** — no
agregar disclaimers automáticos ni bloquear plantillas. Si en el futuro
alguien reabre esto, que sea por un pedido explícito, no por prolijidad.

### Offline — emisión diferida (dirección definida 2026-07-29)

Estado: la venta offline **se permite** y su DE se emite cuando vuelve la
conexión. La opción de bloquear la venta sin internet quedó descartada.

Mecánica: la venta offline entra al mismo outbox (`einvoice_document` en
`pending`) que una venta online; al recuperar conexión el drainer las emite
**una por una**, no en un único request masivo — el orden importa y un fallo
individual no puede arrastrar al lote.

Esto encaja con la numeración por SET (`number: -1`): los números se asignan
al momento de emitir, no al de vender, así que el correlativo fiscal queda
consistente aunque el orden de emisión no coincida con el cronológico de las
ventas. Con numeración propia esto habría sido un problema.

**PREGUNTA BLOQUEANTE PARA FACTOMATE — decide si el modelo es viable:**
qué pasa con la **fecha de emisión** de un DE enviado días después de la
venta. Si SIFEN tiene ventana de tolerancia, el diseño funciona dentro de esa
ventana y hay que definir el comportamiento fuera de ella; si exige emisión
del día, el modelo diferido no cierra y hay que replantearlo. **No
implementar la emisión diferida antes de tener esta respuesta.**

Riesgo a cubrir en el diseño: una venta diferida que SIFEN rechace (datos del
receptor inválidos, por ejemplo) deja un cobro ya realizado sin documento
fiscal. El outbox tiene que exponer esos casos como cola de error accionable
—corregir y reemitir—, no como un `error` mudo en una tabla.

### Portal de consulta del cliente final (dirección definida 2026-07-29)

Objetivo: que el comprador acceda a sus facturas electrónicas sin depender de
que el comercio se las mande.

**Link firmado por venta — es el mecanismo principal.** Se imprime en el
comprobante un identificador opaco (mismo patrón que las pantallas públicas
existentes en `/screens/*`): el cliente escanea y ve *su* documento, sin
tipear nada y sin poder enumerar los de otros.

**El listado por RUC necesita un segundo factor.** Los RUC en Paraguay son
públicos, así que "ingresá tu RUC y te listo todas tus facturas" permite a
cualquiera reconstruir el historial de compras de un tercero contra ese
comercio — es exposición de datos, no solo de un comprobante. Si se hace el
portal de listado, pedir RUC **más** un dato que solo tenga el titular (el
número de uno de sus documentos, por ejemplo) antes de listar el resto.

Fase: va después de la emisión online funcionando. No condiciona F1.

### Armado del documento electrónico (F1) — cae la regla de colapso

La F0 original planteaba "colapsar la línea a 1 × total" porque Automate
exigía `items[].qty` entero ≥ 1 y `items[].price` entero ≥ 1 (kilos/
decimales y líneas a precio 0 no eran representables). **Factomate no
tiene esa restricción**: toma precios **con IVA incluido**
(`unitPriceWithTax`) y `taxRate` por ítem (`10` general, `5` reducido).
F1 puede mandar los ítems de la venta tal cual, sin colapsar — la regla de
colapso queda retirada del plan.

Puntos del armado que sí hay que respetar (guía §5):

- **No mandar el bloque `tenant: { ruc, taxpayerType, name }`** — el emisor
  del timbrado ya es autoritativo, esa sección fue rechazada/ignorada en
  producción.
- Receptor (`client`): tres casos — contribuyente con RUC
  (`nature=1`, `identityDocumentTypeCode=1`, RUC con DV), persona física
  sin RUC (`nature=2`, CI **obligatorio**), innominado/consumidor final
  (`nature=2`, `identityDocumentTypeCode=5`, sin RUC ni CI) — **el
  innominado solo aplica a montos menores a 1.000.000 Gs**.
- Condición de venta: `operationCondition` 0=contado, 1=crédito. **Crédito
  exige el bloque `credit` completo** (`creditOperationCondition`,
  `creditDeadline` o `feeNumbers`/`fees[]`) — sin él, SIFEN rechaza con un
  mensaje que no menciona la causa real. Regla fiscal aparte: **una
  factura a crédito exige receptor identificado** (RUC o CI), nunca a un
  innominado.
- Totales: `tax = total / 11`, `subTotal = total` (con IVA incluido, pese a
  que el PDF de Factomate describe `subTotal` como "sin IVA" — el flujo
  probado en producción manda `subTotal === total`; SIFEN deriva el
  desglose real de `taxRate` + `taxedProportion` por ítem).
  **`total / 11` asume IVA 10% en TODOS los ítems.** Punto tiene ítems al
  10%, al 5% y exentos — **F1 debe implementar redondeo per-item real**
  (no la fórmula de la guía tal cual), con el mismo invariante que ya
  regía en el plan viejo: `sum(qty*price) === totalAmount`, ajustando el
  residuo de redondeo en la última línea. Esto es un **requisito
  explícito de F1**, no una nota al pie — la fórmula simple de la guía
  produce IVA incorrecto para canasta básica/medicamentos (5%) y exentas.
- Pagos: `payments: [{ paymentMethodCode, ammount: total }]` — nótese el
  typo `ammount` (doble m), es de la API real, no un error de tipeo
  nuestro.

## Arquitectura

### `api/lib/EInvoice/`

| Archivo | Rol |
|---|---|
| `EInvoiceProvider.php` | Interfaz. F0 usa `token`/`phoneLogin`/`userInfo`/`sincroConfig`/`paymentMethods`; `issue`/`cancel`/`kude`/`clientByRuc` declaradas y tirando `LogicException` hasta F1/F2/F3 |
| `FactomateProvider.php` | Cliente cURL contra `FACTOMATE_BASE_URL_TEST`/`FACTOMATE_BASE_URL_PROD` (según `environment` de la cuenta). Sin estado — `environment`/`phone`/`bearer` van explícitos en cada llamada. Timeouts explícitos, parseo defensivo |
| `FactomateSession.php` | Resuelve un bearer de 24 h válido por company: reusa el cacheado si le quedan > 5 min, si no encadena `token()` → `phoneLogin()` con la credencial del vault |
| `CredentialVault.php` | AES-256-GCM con `APP_ENCRYPTION_KEY`. Formato `base64(iv[12] ‖ tag[16] ‖ ciphertext)` — sin cambios por el pivot, cifra password/phone/token por igual |
| `EInvoiceService.php` | F0: `getAccount`/`saveAccount`/`testConnection`/`paymentMethods`. F1 suma enqueue/drain/cancel/retry |

El proveedor va detrás de una interfaz para no casar el módulo con
Factomate y para poder retirar el camino legacy entero en F4.

### Schema (mig 92 + mig 95)

- **`einvoice_account`** — 1 fila por company. Mig 92: credenciales
  cifradas, `status` (`unconfigured`/`ok`/`auth_error`), `emitter` jsonb
  (respuesta cruda de `GetUserInfo`), `config` jsonb (`autoIssue`,
  `onlyWithTaxId`, `paymentMethodMap`, …). Mig 95 (correctiva del pivot):
  `phone_enc` (teléfono del titular, cifrado — header obligatorio en todas
  las llamadas), `environment` (`test`/`prod` — Factomate tiene HOSTS
  DISTINTOS, a diferencia de Automate que no distinguía esto), `stamp` +
  `stamp_synced_at` (timbrado vigente cacheado de `sincro/config`, se lee
  no se crea).
- **`einvoice_document`** — outbox + libro de documentos. `UNIQUE
  (companyid, transactionid, doctype)` sigue siendo la idempotencia dura
  (ver §La emisión es sincrónica). Mig 95 agrega `document_number` (el
  `DocumentNumber` de `/Bulk`) y `sifen_status`/`sifen_result`/
  `sifen_checked_at` (reconciliación asíncrona contra SIFEN — ver arriba).

> **Trampa conocida**: `Query::flattenJsonb()` (`api/lib/App/Database/Query.php:52`)
> aplana automáticamente toda columna llamada `data`/`meta`/`config` en cualquier fila
> leída por `ncmExecute`. Todo `SELECT` que traiga `einvoice_account.config` **debe**
> alias-earla (`config AS account_config`) — `stamp`/`emitter` no colisionan con esos
> nombres mágicos, no necesitan alias.

### Flujo de emisión (F1)

1. **Enqueue transaccional** — la fila `einvoice_document` en `pending` se inserta
   dentro de la transacción de la venta. Reemplaza el hook best-effort
   `dispatchElectronicInvoice`.
2. **Intento inline post-commit** — best-effort, para que la venta normal se facture
   en el acto (la emisión es sincrónica del lado de Factomate — un solo
   `/Bulk` y se sabe el resultado, no hace falta polling).
3. **Drainer con reintentos** — `POST /v1/einvoice?action=drain` protegido por secreto
   compartido, invocado por cron del sistema en el server. Backoff exponencial sobre
   `next_retry_at`. **Sin advisory lock por borrador compartido** (eso era
   necesidad de Automate, no de Factomate — ver arriba); la idempotencia
   la da el `UNIQUE` constraint.
4. **Reconciliación SIFEN** — proceso separado (F2) que consulta
   `GET /api/ElectronicDocument/GetAll` para documentos `issued` recientes
   y actualiza `sifen_status`/`sifen_result`/`sifen_checked_at`.

### Endpoints — `api/v1/einvoice.php`

Realm `panel`, escritura gateada por `einvoice.manage`. Query params `resource`/`action`
(patrón de `api/v1/production.php`).

- `GET ?resource=account` (incluye `stamp`/`stampSyncedAt`) · `POST ?action=account` · `POST ?action=test`
- `GET ?resource=paymentMethods` (proxy de los códigos de Factomate)
- F1: `?resource=documents`, `?action=retry|cancel|drain`, `?resource=pdf`

**El frontend nunca habla con Factomate**: la credencial no sale del backend y no hay
CORS de terceros en el navegador del cajero.

### Frontend

`einvoicePy` en `frontend/lib/modules-catalog.ts` (categoría Facturación) con
`configHref`. Página en `settings/facturacion-electronica` + componente
`components/settings/einvoice-manager.tsx` + hook `hooks/use-einvoice.ts`.

Campo de teléfono con el `PhoneInput` compartido del proyecto
(`components/forms/phone-input.tsx`, libphonenumber-js, UI nacional →
storage E.164). Selector de entorno (Prueba/Producción) con `Select` de
shadcn. El timbrado y los datos del emisor se muestran listando las claves
crudas que devuelve Factomate (mismo criterio que el emisor en la F0
original) — no se asumen nombres de campo fijos porque el spec no los tipa.

## Fases

| Fase | Alcance | Estado |
|---|---|---|
| **F0** | Migs 92/93/95, vault, provider/session Factomate, módulo + página de config con teléfono/entorno/timbrado + *Probar conexión* | **Hecha** (pivot 2026-07-28) |
| **F1** | Mapper con redondeo per-item real, outbox, drainer, enqueue en `SaleService`, cron, estado en transacciones | Pendiente |
| **F2** | DataTable de documentos, KuDE PDF (con retry 5xx), cancelación, reintento manual, reconciliación SIFEN (`GetAll`) | Pendiente |
| **F3** | Mapping de medios de pago, lookup de RUC/CI en Contactos (`clientByRuc`), notas de crédito | Pendiente |
| **F4** | Rip-out del FE legacy (`sendFE`/`consultFE`, `FACTURACION_ELECTRONICA_*`, `dispatchElectronicInvoice`, `ElectronicInvoiceService`, `api/v1/electronic_invoice.php`, `SaleInput::electronicInvoicePY`) | Pendiente |
| **F5** | Emisión diferida offline: la venta offline entra al outbox y se emite una por vez al volver la conexión. **Bloqueada** hasta que Factomate responda qué pasa con la fecha de emisión diferida | Pendiente |
| **F6** | Portal de consulta del cliente final: link firmado por venta impreso en el comprobante + listado por RUC con segundo factor | Pendiente |

## Infra

- `APP_ENCRYPTION_KEY` — base64 de 32 bytes. **Sin ella el vault no arranca** y el
  módulo queda `unconfigured`. Generar con `openssl rand -base64 32`.
- `FACTOMATE_BASE_URL_TEST` — default `https://factomatedev.tech-precision.com` (el de la guía).
- `FACTOMATE_BASE_URL_PROD` — default vacío. Sin configurar, cualquier cuenta en
  `environment='prod'` falla explícito (nunca cae a test).
- F1: `EINVOICE_DRAIN_SECRET` + entrada de cron en el server de producción.
