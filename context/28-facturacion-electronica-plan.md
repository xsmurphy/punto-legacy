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
`https://facturadordev.automate.com.py` en test).

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

## Onboarding del emisor desde Punto — white-label (dirección 2026-07-29)

Objetivo del owner: **el tenant nunca se entera de que hay un tercero detrás.**
Registro, configuración y emisión, todo desde Punto.

Fuente: manual "Editar Tenant y ABM de Actividad Económica, Timbrados y
Sucursales" que pasó Factomate. **Vive fuera del repo**
(`~/Downloads/manual-tenant-abm.md`) — si se va a implementar F7, copiarlo a
`context/` primero para que no dependa de la máquina de quien lo bajó.

### Qué habilita el ABM

| Entidad Factomate | Endpoint | Equivalente en Punto |
|---|---|---|
| `Tenant` | `PUT /api/Tenant` | Datos fiscales de la company (RUC, razón social, tipo de contribuyente, régimen, CSC de SIFEN, textos adicionales por tipo de documento) |
| `Branch` | `POST/PUT/DELETE /api/Branch` | **Outlet** |
| `BranchDocumentType` (timbrado) | `POST/PUT/DELETE /api/BranchDocumentType` | Timbrado + `Stablishment`/`ExpeditionPoint` = **punto de expedición de la caja** |
| `Activity` | `POST/PUT/DELETE /api/Activity` | Actividad económica (no existe hoy en Punto) |
| Certificado de firma | `POST /api/Tenant/{id}/UploadCert` | Carga del `.pfx` + contraseña |

Dos cosas que este manual resuelve y que estaban abiertas:

1. **El drift de `EEE-PPP` desaparece.** Punto puede crear el timbrado en
   Factomate con el mismo establecimiento/punto de expedición que ya tiene
   configurado en la caja, en vez de tener que mantener dos configuraciones
   en sincronía a mano.
2. **`CurrentNumber` vive en el timbrado de Factomate**, o sea que el
   correlativo lo lleva ellos. Confirma que la decisión de `number: -1` no era
   solo prudencia: Factomate es estructuralmente el único escritor.

### Modelo de provisioning (confirmado por Factomate, 2026-07-29)

El manual de ABM no incluía creación de `Tenant` ni de `User`, pero Factomate
confirmó que **sí existe por API** y cómo funciona:

1. Punto tiene un **usuario admin propio** en Factomate. Ese usuario genera un
   token que habilita la creación de cuentas.
2. El comerciante completa en Punto un formulario con sus datos de emisor.
3. Al hacer submit, Punto — **con su credencial admin** — crea el tenant y un
   usuario asignado a ese tenant, por API.
4. Factomate devuelve `tenantId`, `userId` y el **usuario/contraseña de API de
   ese tenant**. Punto los guarda y opera con ellos de ahí en adelante.

El comerciante **nunca ve ni tipea una credencial de Factomate**: es lo que
hace posible el white-label.

#### El endpoint de alta — `POST /api/Tenant/CreateExternal`

Factomate mandó el manual actualizado con el contrato (versión con §2 "Alta
autoservicio"). Crea **usuario + tenant + rol + vínculo** en un solo POST.

- **Auth: usuario autenticado SIN tenant** (`user.TenantId == null`). Un
  usuario ya ligado a un emisor recibe 403 — es exactamente para que un emisor
  no pueda crear otros. **Confirma que la credencial admin de Punto es un
  usuario global de Factomate**, sin tenant asignado.
- Request: `RazonSocial` (req), `NombreFantasia`, `Email` (req, **único** en
  todo Factomate — es el `UserName` del admin creado), `Ruc` (req).
- Response: `{ Success, Error, Id: tenantId, UserId, Email, Password }`.

Ciclo de vida completo: `CreateExternal` → `PUT /api/Tenant` (datos fiscales)
→ ABM de actividad/sucursales/timbrados → `UploadCert` → (opcional) probar
contra SIFEN.

#### Riesgos del alta, a resolver en el diseño de F7

1. **La contraseña se devuelve UNA sola vez, en claro, y no se persiste del
   lado de ellos.** Si Punto la pierde entre la respuesta de Factomate y su
   propia escritura, queda un emisor creado al que no se puede entrar. La
   escritura al vault tiene que ser lo inmediato siguiente a la respuesta; si
   falla, error ruidoso con `tenantId`/`userId` (nunca la contraseña) para
   poder resetear. **Falta saber si existe endpoint de reseteo.**
2. **Sin transacción del lado de ellos** (§2.6: compensación manual). El alta
   puede dejar huérfanos. Nuestro lado debe ser idempotente: no reintentar
   `CreateExternal` a ciegas tras un timeout — puede haber creado el emisor.
   Chequear por RUC antes de reintentar.
3. **`Email` es único en todo Factomate.** Dos companies de Punto con el mismo
   email de contacto colisionan, y un comercio que ya tenga cuenta en
   Factomate también. El error `Ya existe un usuario con el email indicado`
   tiene que ofrecer conectar la cuenta existente, no morir.
4. **El teléfono no aparece en el alta.** `CreateExternal` no recibe teléfono y
   el usuario queda con `UserName = Email`, pero `PhoneLogin` necesita el
   header `phonenumber`. Sin resolver esto la cadena de auth post-alta queda
   incompleta — ver pregunta 5.

#### Agujero en la API de Factomate, a reportarles

El manual (§7.5) documenta `GET /api/Consulta/Get?tenantId=&description=`
—"Probar respuesta de la SET"— como **`[AllowAnonymous]` y sin aislamiento**:
acepta cualquier `tenantId` sin verificar que pertenezca al usuario. Tal como
está descripto, **un anónimo puede usar el certificado de cualquier emisor
para consultar SIFEN**. Es del lado de ellos, pero son los certificados de
nuestros tenants. El propio manual recomienda no replicarlo así al portar.

Nos sirve igual como paso final del wizard de F7 (prueba de humo real del
certificado, no solo que la contraseña lo abre) mandando nuestro propio
`tenantId`.

**Lo único que falta para implementar F7** es la credencial admin de Punto.

#### Qué cambia en la arquitectura actual

- **Nivel de credencial nuevo: el admin de Punto.** Es un secreto **global**,
  no por tenant, y el más poderoso del módulo — crea emisores. Va en env
  (`FACTOMATE_ADMIN_USERNAME` / `FACTOMATE_ADMIN_PASSWORD`, uno por entorno),
  **nunca en BD** y nunca alcanzable desde un endpoint con auth de tenant.
  Mismo criterio que el resto de los secretos de bootstrap (`env_to_admin_panel`
  explícitamente deja bootstrap+secretos en env).
- **La credencial por comercio deja de ser input del usuario.** Hoy
  `einvoice-manager.tsx` pide usuario/contraseña de Factomate; bajo este modelo
  el comerciante llena datos fiscales y Punto recibe la credencial de vuelta y
  la guarda en el vault. **El vault, el schema y `FactomateSession` sobreviven
  sin cambios** — cambian el formulario y `EInvoiceService::saveAccount`.
- **Los campos manuales de F0 son andamio**, no producto final: sirven para
  probar F1 contra una cuenta provisionada a mano. **Se retiran cuando entre
  F7** — no deben quedar dos caminos de alta conviviendo.
- **Columnas nuevas** (migración de F7): `factomate_tenant_id`,
  `factomate_user_id` en `einvoice_account`.

#### Consecuencia a no perder de vista

Punto termina siendo el único que conoce la credencial de Factomate de cada
comercio. Si un tenant se va de Punto o quiere operar directo contra
Factomate, necesita esa credencial — hace falta una forma de revelarla o
resetearla. No es urgente para F7, pero es deuda si no se anota.

### Certificado de firma — reglas no negociables

El `.pfx` es la **identidad de firma digital del contribuyente**: quien lo
tenga puede firmar documentos como él. Si el comercio lo sube desde Punto:

- Punto lo **reenvía a Factomate y lo descarta**. No se persiste en BD, ni en
  disco, ni en caché, ni en un temporal. La contraseña tampoco.
- **Nunca** al log — ni el archivo, ni la contraseña, ni un hash de ninguno de
  los dos.
- El upload va por el backend, nunca del browser a Factomate.

Aparte, dato de ellos que conviene tener registrado: Factomate guarda el
certificado cifrado (AES-256-CBC) pero **la contraseña solo en Base64, sin
cifrar** (`CertificateAcc`) — el propio manual lo señala y explica que es por
un proceso de firma externo en Java que la lee así. No lo controlamos, pero
son los certificados de nuestros tenants.

Mismo criterio para `CSCProduccion` (secreto de SIFEN): pasa, no se guarda.

### Riesgo en la API de ellos, a no replicar

El manual (§3) avisa que en `Activity` el `tenantId` viaja **desde el cliente
sin validar contra el usuario autenticado**. Si eso es así en producción, su
API permite cruzar tenants mandando otro id. Punto manda siempre el suyo y
nunca expone ese parámetro al frontend — pero conviene saber que la barrera
no está garantizada del otro lado.

## F1 — lo que hay que saber antes de tocarla

### La tasa de IVA sale del ítem, NO de la línea vendida

**No derivar el `taxRate` del cociente `tax / neto` de la línea.** El POS
moderno no persiste impuesto por línea: `buildSalePayload`
(`frontend/lib/commands/create-sale.ts`) arma cada ítem con `total` bruto y
manda `tax: 0` a nivel transacción, sin `taxObj`. Esa derivación daba 0 en
todas las líneas, o sea **todas las facturas emitidas con todo exento** —
sub-declaración sistemática de IVA que SIFEN acepta sin error, porque exento
es una categoría válida. Silenciosa y masiva.

La fuente correcta es la definición de impuesto del ítem: `item.taxId` →
`tax.name`, que guarda el porcentaje como texto (`'10'`, `'5'`, `'0'` — ver
mig 23). Resuelto en `EInvoiceService::resolveTaxRatesForItems()`, una sola
query para toda la venta.

**Si la tasa de un ítem no se puede resolver, el documento falla.** Nunca se
asume 10 ni 0 ni se saltea la línea: mejor un documento en `error` que alguien
mira, que un documento fiscal con una tasa inventada.

Trade-off documentado en el código: se usa la tasa **vigente** del ítem para
facturar una venta pasada, porque la de la línea no se persiste. Si un ítem
cambió de tasa entre la venta y la emisión, se factura con la nueva.

### `transaction.transactionTotal` es BRUTO

Guarda `SaleInput::$subtotal`, que es la suma de los totales de línea **con
IVA incluido** (`create-sale.ts`: "`total` sigue siendo el BRUTO"). No sumarle
`transactionTax` — eso inflaría cada factura.

### Solo PYG

El payload sale con `currencyTypeCode: 'PYG'` y `exchangeRate: 0`. Una venta en
otra moneda **aborta la emisión** en vez de declarar los montos como guaraníes.
Facturar en otra moneda requiere `exchangeRate > 0` e `itemExchangeRate`
consistente — no implementado.

### Suposiciones SIN VERIFICAR contra la API real

Todas flageadas en comentarios; son los primeros sospechosos ante un rechazo:

- `taxRate: 0` para líneas exentas — la guía solo documenta 10 y 5.
- `contributorType` física/jurídica por heurística de longitud de RUC.
- `creditDeadline` por defecto a +30 días cuando la venta no tiene
  `transactionDueDate`.
- ~~Un solo `paymentMethodCode` por documento~~ — resuelto en F3: una entrada
  de `payments[]` por medio de pago real. Sigue SIN VERIFICAR contra la API
  real que Factomate acepte más de una.
- (Heredadas de F0) el content-type de `POST /Token` y el shape de `stamps[0]`.

### Hueco conocido: documentos trabados en `sending`

El drainer reclama con CAS `pending`/`error` → `sending`. Si el proceso muere
entre el reclamo y la persistencia del resultado, **el documento queda en
`sending` para siempre**: no lo toma nadie más y no aparece como error.

Es deliberado que no se auto-reintenten: la emisión no es idempotente del lado
de Factomate, así que reintentar un `sending` arriesga **emitir dos veces** el
mismo documento fiscal. Pero hoy quedan invisibles, y eso sí hay que
arreglarlo: **F2 tiene que exponerlos** para revisión manual (contra
`GET /api/ElectronicDocument/GetAll` se puede confirmar si llegaron a emitirse
antes de decidir).

## F2 — decisiones e invariantes

- **Cancelar llama a Factomate PRIMERO** y solo marca el documento como
  cancelado si la API confirma. Al revés mostraríamos como anulado algo que
  sigue vigente en SIFEN. El fallo inverso (Factomate cancela y falla nuestro
  UPDATE) deja el documento como `issued` — menos peligroso, y un segundo
  intento de cancelar lo resuelve.
- **`signDate` en hora local de Asunción, naive y sin zona.** En UTC el parser
  de SIFEN lo lee 3-4 h en el futuro y arriesga rechazo.
- **El KuDE es opcional**: falla ≠ factura no emitida. Retry 3× con backoff
  lineal y **solo ante 5xx**; un 4xx falla de una. El documento nunca se marca
  como error por no poder bajar el PDF.
- **Reintento manual solo desde `error`.** Reintentar un `issued` emitiría dos
  veces el mismo documento fiscal.
- **`status` ≠ `sifen_status`.** El primero es el estado del outbox de Punto
  (¿se mandó?), el segundo el estado fiscal real (¿SIFEN lo aceptó?). Se
  pueblan en momentos distintos. Si un documento no aparece en `GetAll`, se
  actualiza solo `sifen_checked_at` — no se le inventa un estado.

Suposiciones nuevas SIN VERIFICAR (flageadas en el código):

- La clave del motivo en el body de `/event` (se asumió `reason`) y el shape de
  la respuesta de éxito.
- `GetAll` no documenta filtros ni paginación: se pide sin parámetros y se
  matchea por CDC en memoria. **Escala mal** con muchos documentos — revisar
  cuando haya volumen real.
- Largo mínimo/máximo del motivo y **ventana legal para anular**: solo se valida
  que no venga vacío.

## Verificado contra la API real (DEV, 2026-07-30)

Primera corrida contra `https://facturadordev.automate.com.py` con la cuenta
de prueba. **Cinco cosas estaban mal y solo se veían así.**

### Confirmado

- `POST /Token` es **form-urlencoded** con `grant_type=password` — la
  suposición era correcta. Con `Content-Type: application/json` responde
  `400 unsupported_grant_type`. Devuelve `access_token` + `expires_in: 899`
  (~15 min), como decía la guía.
- `PhoneLogin` devuelve `expires_in: 86400` (24 h).
- El header `phonenumber` es la **identidad de login** (vuelve como `userName`),
  distinto del campo `PhoneNumber` del usuario.
- `PaymentMethod/get`: el código que espera SIFEN es **`Identifier`**, no `Id`.
  1=Efectivo, 2=Cheque, 3=Tarjeta de crédito, 4=Tarjeta de débito,
  5=Transferencia, 6=Giro, 7=Billetera electrónica, 8=Tarjeta empresarial.
- El timbrado de prueba tiene `CurrentNumber: 53` y `Serie: "AA"` — confirma
  que Factomate lleva el correlativo y valida la decisión de `number: -1`.

### Corregido

1. **Base URL equivocada.** Era `factomatedev.tech-precision.com` (la de la
   guía); la real es `facturadordev.automate.com.py`.
2. **`PhoneLogin` sin body → `411 Length Required`.** IIS rechaza antes de
   llegar a la aplicación. Se manda `{}` explícito.
3. **La respuesta de `PhoneLogin` viene DOBLE-CODIFICADA**: un string JSON que
   contiene el JSON útil. Un solo `json_decode` devolvía un string, el parseo
   encontraba `[]` y fallaba con "no devolvió un token reconocible". `exec()`
   ahora desenvuelve una vez más.
4. **El timbrado NO sale de `sincro/config`.** Ese endpoint devuelve
   `{tenantId, stamps: []}` — **vacío aun con timbrado vigente cargado**. La
   fuente real es `GET /api/BranchDocumentType/Get`, que además trae todo lo
   necesario: `Id` (el de `branch.branchDocumentTypes[0].id` al emitir),
   `Stablishment`, `ExpeditionPoint`, `StampNumber`, `CurrentNumber`, `Serie`.
   Se sigue consultando `sincro/config` primero por si algún emisor lo trae
   poblado, pero ya no se depende de él.
5. **Timbrados dados de baja**: Factomate usa borrado lógico (`Deleted`), así
   que `extractStamp` los saltea — facturar contra un timbrado de baja es
   rechazo seguro de SIFEN.

Los tres primeros hacían que **ninguna cuenta pudiera conectarse**; el cuarto,
que ninguna pudiera emitir.

### Emisión verificada — el payload de la guía estaba mal

Se emitió una factura de prueba real. **El shape de la guía no alcanzaba**: la
guía nombra la mayoría de los campos de cabecera pero no el envoltorio, ni el
nombre del array de ítems, ni el de la razón social, ni la fecha. Todo eso
salió de la implementación real de otro cliente de Factomate, que vive en
`/Users/xstian/Dropbox/Automate/Agent/src/` (`integrations/efatech/efatech.types.ts`
y `services/billing/document-builder.ts`) — **es la fuente de verdad del payload**.

Correcciones:

- El body va envuelto en **`{"ElectronicDocuments": [ … ]}`**. Suelto responde
  `400 "La propiedad ElectronicDocuments se encuentra vacia"`.
- **`issuedDate`** (no `issueDate`), naive `YYYY-MM-DDTHH:MM:SS` hora Asunción.
- **`electronicDocumentItems`** (no `items` ni `details`). Sin campo `total` por
  ítem.
- **`securityCode`** obligatorio: 9 dígitos aleatorios.
- **`aditionalInformation`** obligatorio (el typo de una sola `d` es de la API).
- Cliente: **`businessName`** + `fantasyName` (no `name`), más `operationType: 2`
  (B2C), `address`, `email`, `phoneNumber`.
- `contributorType`: **con RUC → 1, sin RUC → 2**.
- `measurementUnitCode: 0`. Otros valores rompen la serialización XML del lado
  de Factomate con un error de `LxSerializationException`.

Respuesta de `/Bulk`: `Items[0]` trae `CDC`, `Success`, `StatusId`,
`StatusString`, `Error`, `SecurityCode`, `SignDate`, **`DCarQR`** (link del QR
de ekuatia, el que va impreso) y **`XmlUrl`**; a nivel raíz un `Id` que es la
llave de reconciliación. **No existe `DocumentNumber` ni `StatusMessage`** — el
código los leía y siempre obtenía null.

El número lo asignó la SET: `CurrentNumber` estaba en 53 y el CDC emitido
terminó en `…0000054`. `number: -1` funciona.

### CRÍTICO — un CDC con `Success: true` NO significa que SIFEN aceptó

Comprobado: la factura de prueba volvió con CDC válido y `Success: true`, y
**SIFEN la rechazó** — `getBulk` la muestra como `FinalizadoERROR` /
`Rechazado`, código **1002 (documento electrónico duplicado)**.

Peor: **el KuDE se descargó igual** (PDF válido de 33 KB) para ese documento
rechazado. Ni el CDC, ni el `Success`, ni el PDF prueban que la factura valga.
**El único campo que lo dice es `sifen_status`.**

### `GetAll` no sirve — la reconciliación va por `getBulk/{id}`

`GET /api/ElectronicDocument/GetAll` devuelve `Items: []` incluso después de
emitir (probado también con `?id=0` y con `?offset/size`). La reconciliación
sobre `GetAll` era un **no-op silencioso**: nunca habría actualizado un
`sifen_status`.

La fuente real es `GET /api/electronicDocument/getBulk/{id}` con el `Id` raíz
de la respuesta de `/Bulk`, que por eso ahora se persiste en
`einvoice_document.provider_number`. El resultado de SIFEN se lee en
`Items[0].SifenResult.rRetEnviDe.rProtDeField` → `dEstResField` y
`gResProcField[].dCodResField/dMsgResField`.

### Segunda emisión: SIFEN APROBÓ — el payload es correcto

Se emitió una segunda factura de prueba **generada por nuestro propio mapper**
(payload idéntico campo por campo al que la API aceptó). Resultado:
`FinalizadoOK`, `dEstResField: Aprobado`, código **0260**. El rechazo 1002 de la
primera era estado del entorno de DEV, no un problema del payload.

**Estado transitorio `Pendiente`**: entre la emisión y el veredicto de SIFEN
pasaron varios segundos en los que `getBulk` devuelve
`StatusString: "Pendiente"` **con `Success: false`**. Ese `false` **NO es un
rechazo**. La reconciliación lo guarda como `Pendiente` y lo vuelve a consultar;
`Aprobado`/`Rechazado` son los únicos estados finales y ya no se re-consultan.

### Cancelación (`/event`) — envoltorio `eventDetails`

Igual que la emisión, el body va envuelto:
`{"eventDetails": [{ typeCode: 1, documentId: <CDC>, reason, signDate }]}`.
Nuestro código lo mandaba suelto y no habría funcionado.

Probado contra la primera factura (la que SIFEN rechazó): respondió
`4002 - CDC no existente en el SIFEN`, que es el rechazo correcto — no se puede
anular un documento que SIFEN nunca aceptó. El shape del request quedó validado
igual, porque el pedido llegó hasta SIFEN y volvió con un error de negocio, no
de validación.

**Sin verificar todavía**: una cancelación exitosa sobre un documento aprobado.

## F3 — decisiones e invariantes

- **El documento declara el NETO, no el bruto.** `transactionTotal` es la suma
  de las líneas antes de descuento y `transactionDiscount` la suma de los
  descuentos: el neto es la resta (ver `create-sale.ts`). Facturar el bruto
  declaraba de más en toda venta con descuento — más IVA del que se cobró — y
  el total no cerraba contra los medios de pago. El descuento se absorbe en el
  precio unitario en vez de declararse en `itemUnitPriceDiscountWithTax`: la
  guía documenta ese campo pero no cómo afecta a `total`/`subTotal`, y el único
  flujo verificado contra la API real cumple `total = Σ(quantity ×
  unitPriceWithTax)`. **Trade-off**: el KuDE no desglosa el descuento.
- **Un `payments[]` por medio de pago real**, agrupando por código y validando
  que la suma cierre contra el total (absorbe hasta 1 Gs de redondeo por línea;
  una diferencia mayor aborta la emisión). En crédito las líneas son la
  **entrega inicial**, no el total, y alimentan `credit.initialDeliveryAmmount`
  (iba fijo en 0).
- **Método sin mapear cae en `defaultPaymentMethodCode`**, no aborta: declarar
  un medio de pago accesorio equivocado es menos grave que dejar al comercio
  sin factura por un detalle de configuración.
- **La resolución de la clave del pago es compartida**
  (`PaymentMethods\PaymentMethodResolver`): `transactionPaymentType[].type`
  puede ser el taxonomyId (ventas nuevas) o un slug legacy. Estaba embebida en
  `Finance\ConfigService::resolveAccountId`; se extrajo en vez de duplicarla.
- **`saveAccount` mergea la config clave por clave.** Antes cada sección de la
  pantalla mandaba la config entera, así que tocar un switch de emisión borraba
  el `paymentMethodMap`.
- **Lookup de RUC en el backend** (`GET /v1/contacts?resource=taxpayer`):
  primero el padrón del emisor vía Factomate (autoritativo — es el que valida
  SIFEN), y si el comercio no tiene FE conectada cae al padrón público
  (`TAXPAYER_LOOKUP_URL`). Antes lo consultaba el navegador del cajero contra
  turuc.com.py, con el dominio hardcodeado en el bundle y sin reuso desde el
  panel.
- **Nota de crédito = devolución.** Una devolución (`transactionType = 6`)
  encola su NC en la misma transacción y se emite inline post-commit. Los ítems
  salen de `itemSold` (la devolución guarda `meta = '{}'`), en valor absoluto y
  netos. `documentTypeCode: 5` + `associatedDocuments: [{associatedDocumentType:
  0, cdc}]` de la factura original; sin CDC no se emite. **Sin bloque
  `payments`**: una NC no cobra — la devolución del dinero es un movimiento de
  caja, no una forma de pago del documento.
- **Sin factura original emitida no se encola la NC.** Dejarla encolada la
  mandaría a `error` en cada pasada del drainer sin salida posible.

Suposiciones nuevas SIN VERIFICAR (flageadas en el código):

- Que Factomate acepte más de una entrada en `payments[]`.
- El bloque de pagos y `initialDeliveryAmmount` de una venta a crédito.
- El payload completo de la nota de crédito (documentado en la guía, nunca
  emitido) y **el motivo de emisión**: la guía expone `CreditNoteReason/get`
  pero no nombra el campo del cuerpo donde va.
- La ruta y el shape de `GET /api/Client/getbyruc/{ruc}` — el parseo es
  defensivo y cae al padrón público ante cualquier respuesta inesperada.

## Preguntas abiertas para Factomate

Las tres primeras bloquean verificación; la cuarta bloquea el white-label.

1. Content-type y body exacto de `POST /Token` — se asumió form-urlencoded
   OAuth (`grant_type=password`).
2. Shape de la respuesta de `POST /api/sincro/config`, en particular dónde
   vive el timbrado vigente (`stamps[0]`).
3. **Fecha de emisión diferida**: qué pasa con un DE enviado días después de
   la venta. ¿Ventana de tolerancia de SIFEN? ¿Qué pasa fuera de ella?
   Decide si F5 es viable.
4. ~~Endpoints de alta~~ — **RESUELTA**: `POST /api/Tenant/CreateExternal`.
   Falta solo el **usuario/contraseña admin de Punto**, que es lo último que
   bloquea F7.
5. **¿De dónde sale el `phonenumber`?** `CreateExternal` no recibe teléfono y
   deja al usuario con `UserName = Email`, pero `PhoneLogin` exige el header.
   ¿Se setea después por otro endpoint, es el de la sucursal (`Branch.Phone`),
   o `PhoneLogin` resuelve distinto para un usuario-email? **Bloquea la cadena
   de auth de los emisores dados de alta por Punto.**
6. **El admin, ¿autentica igual que un usuario de tenant** (`/Token` →
   `PhoneLogin`) o solo con `/Token`? Ahora sabemos que el admin es un usuario
   **sin tenant**, así que el segundo paso probablemente no le aplique.
7. **¿Hay endpoint de reseteo de contraseña de un usuario de tenant?** La de
   `CreateExternal` se devuelve una sola vez; sin reseteo, perderla deja al
   emisor inaccesible.
8. **Cancelación**: ¿cuál es la ventana legal para anular un DE, y el motivo
   tiene largo mínimo/máximo? Hoy solo se valida que no venga vacío.
9. **`GetAll`**: ¿acepta filtros o paginación? Sin eso, la reconciliación baja
   todos los documentos del emisor y matchea en memoria.
10. **¿Cómo se marca una línea exenta de IVA?** La guía solo documenta
    `taxRate` 10 y 5; hoy se manda 0 sin verificar, y Punto tiene ítems exentos.
11. **Nota de crédito: ¿dónde va el motivo de emisión?** La guía expone el
    catálogo `CreditNoteReason/get` pero el cuerpo documentado de la NC solo
    cambia `documentTypeCode` y agrega `associatedDocuments` — no nombra el
    campo del motivo, que SIFEN exige (iMotEmi). Hoy no se manda ninguno.
12. **¿`payments[]` acepta más de una entrada?** Una venta con pago dividido
    declara una línea por medio de pago; nunca se probó contra la API real.
13. **Descuentos**: ¿cómo afectan `itemUnitPriceDiscountWithTax` y
    `itemDiscountPercentage` a `total`/`subTotal`? Hoy el descuento se absorbe
    en el precio unitario y esos campos van en 0, así que el KuDE no lo
    desglosa.

## Fases

| Fase | Alcance | Estado |
|---|---|---|
| **F0** | Migs 92/93/95, vault, provider/session Factomate, módulo + página de config con teléfono/entorno/timbrado + *Probar conexión* | **Hecha** (pivot 2026-07-28) |
| **F1** | Mapper, outbox transaccional, drainer con CAS, enqueue en `SaleService`, endpoint de drain, badge en transacciones | **Hecha** (2026-07-30) |
| **F2** | DataTable de documentos, KuDE PDF (retry 5xx), cancelación, reintento manual, reconciliación SIFEN (`GetAll`), trabados en `sending` expuestos | **Hecha** (2026-07-30) |
| **F3** | Mapping de medios de pago (UI + `payments[]` por medio real), lookup de RUC en el backend (`clientByRuc` + padrón público), notas de crédito por devolución, factura por el neto | **Hecha** (2026-07-30) — sin verificar contra la API real |
| **F4** | Rip-out del FE legacy (`sendFE`/`consultFE`, `FACTURACION_ELECTRONICA_*`, `dispatchElectronicInvoice`, `ElectronicInvoiceService`, `api/v1/electronic_invoice.php`, `SaleInput::electronicInvoicePY`) | Pendiente |
| **F5** | Emisión diferida offline: la venta offline entra al outbox y se emite una por vez al volver la conexión. **Bloqueada** hasta que Factomate responda qué pasa con la fecha de emisión diferida | Pendiente |
| **F6** | Portal de consulta del cliente final: link firmado por venta impreso en el comprobante + listado por RUC con segundo factor | Pendiente |
| **F7** | Onboarding white-label: `CreateExternal` con la credencial admin → persistir `tenantId`/`userId`/credencial en el vault → `PUT /api/Tenant` (datos fiscales) → actividad, sucursales↔outlets, timbrados↔puntos de expedición → `UploadCert` → prueba contra SIFEN. Retira los campos manuales de F0. **Bloqueada** por la credencial admin y por el origen del `phonenumber` | Pendiente |

## Infra

- `APP_ENCRYPTION_KEY` — base64 de 32 bytes. **Sin ella el vault no arranca** y el
  módulo queda `unconfigured`. Generar con `openssl rand -base64 32`.
- `FACTOMATE_BASE_URL_TEST` — default `https://facturadordev.automate.com.py` (el de la guía).
- `FACTOMATE_BASE_URL_PROD` — default vacío. Sin configurar, cualquier cuenta en
  `environment='prod'` falla explícito (nunca cae a test).
- F1: `EINVOICE_DRAIN_SECRET` + entrada de cron en el server de producción.
- F3: `TAXPAYER_LOOKUP_URL` — padrón público para el lookup de RUC
  (`GET {url}/{documento sin DV}`), default `https://turuc.com.py/api/contribuyente`.
  Vacía → el lookup solo responde con la fuente del proveedor de FE.
