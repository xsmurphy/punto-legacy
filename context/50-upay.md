# uPay (ueno bank) — cobro con QR / link / terminal desde el POS

> Estado: **F1a IMPLEMENTADA** (2026-08-23, branch `frontend/psp-generico`) —
> el refactor de raíz que desbloquea la integración: medio de pago POR
> pasarela (`ensurePspMethod` + `PspCatalog`) y ciclo de cobro genérico
> (`<PspQrDialog>` + adapter). El resto del doc sigue siendo **plan**: D1–D10
> son propuestas, ninguna cerrada con el owner, y **uPay no está cableado**
> (falta F0: credenciales y documentación real). Origen: pedido del owner
> 2026-08-23 — "falta en el roadmap la integración con uPay de Ueno Bank".
>
> **Qué quedó desbloqueado y cómo se suma una pasarela** — ver §2.4.
>
> **Bloqueante de arranque:** la documentación técnica de uPay
> (`desarrolladores.upay.com.py`) está **detrás de login**. Lo público es la
> cáscara del SPA. Todo lo que sigue sobre el protocolo concreto sale de la
> documentación **Pagopar** (la plataforma que uPay absorbió), que es pública
> pero vieja (rev. 2017) y describe un flujo de e-commerce, no de mostrador.
> Hasta tener las credenciales y el doc real, **no se cablea un cobro**.
>
> Marcas: **[V]** verificado contra código o fuente oficial, **[S]** suposición,
> **[I]** interpretación propia sobre fuente verificada, **[?]** hay que preguntar.
> Fuentes al final.

---

## 0. Por qué este doc existe (y qué ya estaba parqueado)

**[V]** uPay **ya está en el catálogo de módulos del panel**, como
`status: 'soon'`:

- `frontend/lib/modules-catalog.ts:193-196` — `key: "upay"`,
  `title: "uPay (ueno bank)"`, descripción *"Cobros con QR de uPay —
  interoperable con billeteras de Paraguay, Brasil y Argentina."*

**[V]** Lo puso el commit `0565da2f` (2026-08-08, el mismo que implementó el
módulo Bancard), con esta justificación textual en el mensaje: *"uPay (ueno
bank) entra al catálogo como «Próximamente»: sin documentación de su API ni
credenciales no se cablea un cobro real."*

**[V]** Lo que **no** existe: ni una línea en `context/`. Ni en
`10-roadmap.md`, ni en `_feature-requests.md`, ni en los docs de módulos. Es
exactamente el agujero que el owner señala — una card muerta en el panel sin
nada detrás que diga qué falta para prenderla.

**[V]** Los módulos `soon` **no van al backend**: `ModulesService::NATIVE_KEYS`
no incluye `upay`, así que el toggle ni se ofrece. La card es cosmética hoy.

---

# Parte 1 — Qué es uPay y qué ofrece

## 1.1 El producto

**[V]** uPay es la plataforma de cobros del ecosistema de **ueno bank**
(Paraguay). No es un banco aparte: es la marca de cobros para comercios.

**[V]** **Pagopar se integró a uPay** — la pasarela de e-commerce paraguaya
más conocida del mercado pasó a ser parte de uPay. La comunicación oficial de
uPay dice que las cuentas Pagopar existentes siguen funcionando *"como
siempre"*, sin recrear cuentas ni perder historial. **[?]** No aclara si la
API `api.pagopar.com` sigue siendo la superficie soportada para integraciones
nuevas o si hay una API uPay distinta que la reemplaza. **Esta es la pregunta
número uno del doc** — de la respuesta depende todo el diseño técnico.

## 1.2 Modalidades de cobro

**[V]** De la comunicación pública de uPay/ueno:

| Modalidad | Qué es | ¿Sirve en mostrador? |
|---|---|---|
| **QR** | QR interoperable. Históricamente cuenta-a-cuenta; con uPay también acepta tarjetas nacionales e internacionales | **Sí — es la que aplica** |
| **Link de Pagos** | El comercio define monto + descripción y genera un link para mandar por WhatsApp/redes/mail. Vigencia **48 h** | Marginal (venta remota, delivery, seña) |
| **uPOS** | Terminal física propia de uPay: touchscreen, con o sin impresora, wifi + móvil. Acepta Mastercard, Visa, débito, crédito, QR y billeteras | Sí, pero **fuera de banda** (ver §3.3) |
| **API / plugins** | Integración por API, o plugins WooCommerce / PrestaShop / Shopify / Wix | La API es el vehículo; los plugins no aplican |

**[V]** Comisiones publicadas: **3% + IVA** para débito, crédito nacional y
billeteras digitales; **3,6% + IVA** para tarjetas de crédito del exterior y
**QR**.

**[V]** Acreditación: **24 h** si el comercio cobra a cuenta ueno; **día
siguiente** para otros bancos o billeteras.

**[I]** Que el QR sea la banda más cara (3,6%) y no la más barata es
contraintuitivo y conviene confirmarlo con el ejecutivo de cuenta antes de
empujar QR como default del mostrador — un comercio de alto volumen va a
comparar contra Bancard.

## 1.3 El portal de desarrolladores está cerrado

**[V]** `https://desarrolladores.upay.com.py/` responde 200 pero sirve una
SPA (Vite) cuyo bundle **no contiene la documentación**: el string `webhook`
aparece **cero** veces en `assets/index-DFds4fKi.js`. Las únicas rutas del
router son `/docs`, `/login` y `/maintainer`; `/docs` devuelve el mismo shell
(fallback SPA) con `<title>API Documentation</title>` y nada más.

**[I]** Conclusión: el contenido de `/docs` se pide en runtime a un backend
que exige sesión. **La documentación de la API de uPay no es pública** — hay
que registrarse como desarrollador/comercio para leerla.

**[V]** No hay `llms.txt`, `openapi.json`, `sitemap.xml` ni índice de búsqueda
expuesto.

## 1.4 Lo que sí es público: la API Pagopar (el ancestro)

**[V]** Documentación técnica oficial de Pagopar
(`cdn.pagopar.com/assets/documentos/Documentacion_Pagopar.pdf`, rev.
2017-06-20). Es la especificación concreta más cercana que existe hoy.

**Autenticación** — no hay OAuth ni Bearer. Par de claves por comercio
(*clave pública* / *clave privada*) y un token por operación:

```php
$token = sha1('clave_privada' . 'tipo_de_token');
```

donde `tipo_de_token` es una constante por operación: `VENTA-COMERCIO` para
crear, `CONSULTA` para consultar, `CATEGORIAS`, etc. La clave pública viaja en
claro en el payload; la privada **nunca** viaja — solo sellando el SHA-1.

**Endpoints relevantes:**

| Operación | Método | URL | Token |
|---|---|---|---|
| Generar transacción | POST | `api.pagopar.com/api/comercios/1.1/iniciar-transaccion` | `sha1(priv . 'VENTA-COMERCIO')` |
| Consultar transacción | POST | `api.pagopar.com/api/pedidos/1.1/traer` | `sha1(priv . 'CONSULTA')` |
| Categorías | POST | `api.pagopar.com/api/categorias/1.1/traer` | `sha1(priv . 'CATEGORIAS')` |
| Ciudades | POST | `api.pagopar.com/api/ciudades/1.1/traer` | `sha1(priv . 'CIUDADES')` |

**Respuesta de creación:** `{ respuesta: bool, resultado: { data: <hash de 64
chars> } }`. Ese *hash del pedido* es la llave de todo el ciclo de vida.

**Confirmación — solo polling.** `pedidos/1.1/traer` con el `hash_pedido`
devuelve:

- `pagado` (booleano) ← **esto es la confirmación**
- `forma_pago` (cadena, nombre del método con el que efectivamente pagó)
- `fecha_pago` (ISO 8601 o `null` si todavía no pagó)
- `monto`
- `fecha_maxima_pago` (pasada esa fecha, `resultado` vale `false`)

**[V]** El PDF **no documenta webhook alguno**. **[V]** Sí existe una "URL de
respuesta" configurable en el panel del comercio (aparece en guías de
terceros que integran Pagopar), pero **su contrato —payload, firma, reintentos—
no está en la documentación pública**. **[?]** Pregunta abierta: ¿hay webhook
firmado y con reintentos, o el modelo soportado es polling?

**[V]** El PDF **no documenta** anulación, reversa, devolución, ni endpoint de
liquidación/conciliación. Nada de settlement reports.

## 1.5 El modelo de datos de Pagopar no es de mostrador

**[V]** Crear un pedido exige, además del monto:

- `comprador`: `nombre`, `email` y `ciudad_id` son **obligatorios**
- `compras_items[]`: por cada ítem, `nombre`, `cantidad`, `precio_total`,
  `ciudad_id`, **`categoria`** (id del catálogo de categorías de Pagopar) y
  **`producto_id`**, todos obligatorios
- `fecha_maxima_pago`, `descripcion_resumen`, `tipo_pedido`

**[I]** Eso es un carrito de e-commerce con envío, no un cobro de caja. En un
mostrador no hay email del comprador, no hay ciudad de entrega, y mapear cada
ítem del POS a una categoría del catálogo de Pagopar es una taxonomía paralela
que no queremos mantener. Si la API de uPay para QR presencial es literalmente
ésta, hay que negociar un flujo "cobro simple" (monto + referencia) o llenar
los obligatorios con valores sintéticos —lo cual ensucia la conciliación del
lado del PSP—. **[?]** Pregunta directa a uPay.

---

# Parte 2 — El precedente Bancard, y si uPay entra igual

## 2.1 Cómo está armado el módulo Bancard hoy

**[V]** Todo verificado contra código:

- `api/lib/Modules/ModulesService.php:55` — `'bancard'` en `NATIVE_KEYS`
  (allowlist única, la reusa también el realm `/admin`).
- `:59` — `'bancard'` en `CONFIG_KEYS` (admite `action=config`).
- **Double-write** en `toggle()`: el estado va al **flat key** `bancard` de
  `company.config` (lo que lee el POS) **y** a `company.moduleData.bancard`.
  Si cualquiera de los dos writes falla, tira `RuntimeException` — no se acepta
  split-brain silencioso.
- **Canales** en `moduleData.bancard`: `{ status, qr, pos }`, ambos canales
  **default ON** al prender el módulo; el apagado es explícito por canal.
- `toggle()` hace `array_merge` sobre el entry previo — pisar con
  `['status' => x]` borraba la config, bug de raíz que ese mismo commit arregló.
- Prender el canal `qr` **provisiona el medio de pago** vía
  `PaymentMethodService::ensureQrMethod()` (idempotente, `systemKey='qr'`,
  atajo `Q`, `requiresIdentifier=false`, al final del `sortOrder` para no pisar
  el orden del comercio). Es best-effort: si falla, el módulo queda activo y el
  POS avisa.
- `api/v1/bootstrap.php:174-191` — el backend resuelve los dos booleans
  (`bancardQr`, `bancardPos`) **server-side**; el POS recibe dos flags y no
  recombina nada.
- `frontend/components/register/pay-dialog.tsx:206-218` — el método con
  `systemKey === 'qr'` se **filtra de la lista** si `bancardQrEnabled` es false.

## 2.2 Veredicto: sí, mismo patrón — con una corrección de raíz

**El patrón de módulo aplica tal cual.** uPay es conceptualmente idéntico a
Bancard: un PSP activable por tenant, con canales (`qr`, `link`, `pos`), que
provisiona medios de pago y expone un cobro desde la caja. Reusar esto vale
mucho más que inventar un módulo nuevo:

- `upay` entra a `NATIVE_KEYS` y `CONFIG_KEYS`.
- `moduleData.upay = { status, qr, link }` + flat key `upay`.
- `bootstrap.php` resuelve `upayQrEnabled` / `upayLinkEnabled` server-side.

**Pero hay dos cosas que Bancard resolvió de forma que no escala a dos PSP, y
la solución correcta es arreglar la raíz, no copiar el parche:**

### (a) `ensureQrMethod` es específica de un PSP y colisiona

**[V]** `PaymentMethodService::ensureQrMethod()` (`:254-278`) busca un método
llamado literalmente **"QR"** y lo **adopta**, marcándolo `systemKey='qr'`.
Hay un solo bucket "QR" para todo el tenant.

**[I]** Con Bancard **y** uPay prendidos, los dos cobros caen en el mismo medio
de pago. Consecuencias reales, no teóricas: el arqueo no puede separar por PSP,
y las liquidaciones **no cuadran** — son dos ventanas de acreditación distintas
y dos escalas de comisión distintas (Bancard vs. 3,6% + IVA de uPay). El cajero
tampoco sabe qué QR está mostrando.

**Solución de raíz — IMPLEMENTADA (F1a).**
`PaymentMethodService::ensurePspMethod($companyId, $systemKey, $name, $code,
$color)`: cada PSP provisiona **su propio** medio de pago. `systemKey='qr'`
sigue siendo Bancard por retrocompatibilidad; `ensureQrMethod()` quedó como
wrapper delgado que lee la entrada `bancard` del catálogo, así ningún
call-site existente cambió. **Sin migración de datos**, ni de esquema ni de
filas: el `systemKey` vive en `taxonomyExtra` (JSONB) y los tenants existentes
ya tienen `'qr'`.

**Qué pasa con las ventas históricas del método "QR" viejo: NADA, a
propósito.** La separación por pasarela la da la FILA de taxonomía, no el
systemKey — la venta persiste `transactionPaymentType[].type = taxonomyId` y
el rollup agrupa por `COALESCE(type, name)`. Renombrar la fila de Bancard (a
"QR Bancard", por ejemplo) o cambiarle el systemKey partiría la serie del
reporte en dos buckets a mitad de la historia, y las ventas MUY viejas que
guardaron solo `name` quedarían huérfanas de su bucket. Por eso Bancard
conserva nombre "QR" y `systemKey='qr'` para siempre, y la pasarela nueva
entra con fila propia.

Dos comportamientos afinados en el camino, con arnés que los cubre:
adoptar un método homónimo solo pasa si esa fila **no tiene systemKey** (una
pasarela no se roba el medio de otro flujo por coincidencia de nombre), y si
el nombre está tomado por un medio del sistema la provisión **falla explícito**
en vez de reventar contra el UNIQUE `uq_taxonomy_company_type_name` (el caller
lo loguea y el módulo queda activo, como siempre).

### (b) El flujo de cobro con QR está soldado a Bancard

**[V]** `frontend/components/register/bancard-qr-dialog.tsx` implementa el
ciclo completo (crear → pintar → publicar a la pantalla del cliente → pollear →
cancelar/vencer). El ciclo es **idéntico** para cualquier PSP de QR; lo único
específico es el endpoint de creación y el parseo de la respuesta cruda
(ya aislado en `frontend/lib/payments/bancard-qr.ts`).

**Solución de raíz — IMPLEMENTADA (F1a).**
`frontend/components/register/psp-qr-dialog.tsx` es el ciclo completo
(crear → pintar → publicar a la pantalla del cliente → pollear → cancelar/
vencer → degradar sin red), parametrizado por un *adapter*. Bancard pasó a ser
`frontend/lib/payments/psp/bancard.ts`, sin cambio de comportamiento
observable: mismo endpoint, mismo payload, mismo parseo, mismo cancel.

## 2.4 El punto de extensión (lo que queda por hacer para sumar una pasarela)

**[V]** Estado tras F1a. Sumar una pasarela de QR son **tres archivos**, y
ninguno es un copy-paste del módulo Bancard:

| # | Dónde | Qué |
|---|---|---|
| 1 | `api/lib/PaymentMethods/PspCatalog.php` | Una entrada en `QR_PROVIDERS`: `module`, `channel`, `channelDefault`, `systemKey`, `methodName`, `code`, `color`, `label`. Hay un ejemplo comentado con los valores propuestos para uPay. |
| 2 | `frontend/lib/payments/psp/<provider>.ts` | El adapter: `provider`, `systemKey`, `title` + `create()`, `cancel()`, y opcionalmente `confirm()` (el default lee la fila que el webhook deja en `vPayments`). |
| 3 | `frontend/lib/payments/psp/index.ts` | Una línea en `ADAPTERS`. |

Más lo del módulo en sí, que ya era el patrón conocido: la key en
`ModulesService::NATIVE_KEYS` / `CONFIG_KEYS` y su canal en `updateConfig()`.

Lo que **ya no hay que tocar**, porque dejó de nombrar pasarelas:

- La provisión del medio de pago (`ensurePspMethod`, recorrida desde el toggle
  y desde el guardado de config del módulo).
- `api/v1/bootstrap.php`, que ahora emite el mapa genérico
  `pspQr: { provider: bool }` derivado del catálogo (los flags
  `bancardQr`/`bancardPos` se conservan intactos: son el contrato que ya leen
  los POS desplegados, incluido uno con la config cacheada offline).
- El filtrado del botón en la grilla del cobro, el dialog, el polling, la
  pantalla del cliente y la degradación sin red.

**[V]** Arnés: `api/tests/psp_payment_methods_test.php` (runner
`run_psp_payment_methods_test.sh`, modo `PSP_TEST_IN_DOCKER=1` para el server).
21 checks contra Postgres real — regresión de Bancard, dos pasarelas
separables, ventas históricas intactas, grano del rollup, y guardas estáticas
que fallan si una pasarela del catálogo se queda sin adapter en el front.

**[I]** Lo que sigue pendiente de F1a y NO se hizo por falta de un segundo
consumidor real: mover `CredentialVault` de `EInvoice\` a un namespace
compartido (D4). Se hace cuando uPay traiga credenciales por comercio — mover
una clase sin su segundo caller es refactor especulativo, y hoy nadie más la
usa.

---

## 2.3 Lo que uPay tiene y Bancard no: credenciales por comercio

**[V]** Bancard hoy se configura por env/infra — el módulo solo tiene switches
`qr`/`pos`, no hay campos de credenciales por tenant en `moduleData.bancard`.

uPay necesita **clave pública + clave privada por comercio**. La clave privada
sella cada request: es un secreto de verdad y **no puede ir en claro en
`company.moduleData`**, que se lee entero en el bootstrap.

**[V]** Ya existe el precedente correcto: `api/lib/EInvoice/CredentialVault.php`
— AES-256-GCM (`aes-256-gcm`, IV de 12 bytes, tag de 16), clave maestra en
`APP_ENCRYPTION_KEY` (base64 de exactamente 32 bytes, valida y explota si
falta). Es genérico: `encrypt(string): string` / `decrypt(string): string`, sin
nada atado a facturación electrónica salvo el mensaje de error y el namespace.

**[I]** La clase debería salir de `EInvoice\` a un namespace compartido
(`Punto\Api\Security\CredentialVault`), con alias en el lugar viejo para no
romper facturación electrónica. Segundo consumidor = momento de mover, no de
copiar.

---

# Parte 3 — Los puntos duros del proyecto

## 3.1 Offline-first: uPay nunca puede bloquear la caja

**[V]** Regla del proyecto: lo que se **emite** funciona sin internet; lo que
necesita estado compartido puede bloquearse. **[V]** El scope offline del POS
es **solo ventas simples** — `pay-dialog.tsx:560` es explícito: *"Online-only:
el cobro de un espacio/orden NO se encola offline"*, y degrada con un mensaje
local: *"Sin conexión con el servidor — el cobro de espacios/órdenes necesita
estar online. Reintentá."*

**Un cobro por PSP es intrínsecamente online**: sin red no hay QR ni
confirmación posible. No hay diseño que lo evite. Lo que sí se puede garantizar
es que **la caja no se traba**:

1. **El medio de pago uPay se filtra de la grilla cuando no hay red**, igual
   que hoy `bancardQrEnabled` lo filtra cuando el canal está apagado
   (`pay-dialog.tsx:211`). El cajero no llega a tocar un botón que no puede
   funcionar.
2. **Las posiciones no se mueven.** **[V]** Regla del POS: nada de bloques que
   desplacen botones — memoria muscular del cajero. El método se pinta
   deshabilitado **en su lugar**, no se remueve de la grilla.
3. **La venta sigue disponible** con efectivo o cualquier otro método, y se
   encola offline por el camino que ya existe.
4. Si la red se cae **con el QR ya en pantalla**, el polling falla → el dialog
   corta y avisa; el cobro **no** se da por bueno. El QR queda potencialmente
   pagado del lado del PSP → cae en la reconciliación de huérfanos (§3.2).

**[I]** Consecuencia de producto que el owner debería avalar: un comercio que
opera mucho sin red va a ver el botón uPay apagado seguido. Eso es correcto,
pero hay que decirlo en la config del módulo, no descubrirlo en el mostrador.

## 3.2 Confirmación sin colgar la caja

**[V]** El patrón ya está resuelto y probado con Bancard, y es el que hay que
reusar — **el webhook nunca habla con el POS**:

```
Cajero toca uPay
  → POST /v1/upay {type:'create'}  con un UID nuevo (crypto.randomUUID)
  → QR se pinta en el dialog Y se publica a la pantalla del cliente (qr-show)
  → POS pollea GET /v1/vpayments?resource=byUID cada 3 s
       ⋮  (mientras tanto, el webhook del PSP deja la fila en vPayments)
  → aparece la fila  ⇒ pago confirmado ⇒ applyPayment() ⇒ la venta se emite
  → cancelar / vencer ⇒ qr-hide + POST {type:'cancel'} al PSP
```

**[V]** Constantes actuales de Bancard: `POLL_INTERVAL = 3_000`,
`MAX_POLL_MS = 5 * 60_000`. **[V]** El UID lo genera el cliente y viaja dentro
del `identifier`: es la única llave que enlaza el QR con la fila de `vPayments`.

Por qué esto no cuelga la caja:

- **La espera está acotada** (5 min) y el botón **Cancelar está siempre
  disponible** — el cajero corta cuando quiere y cobra de otra forma.
- **La venta no se crea antes de la confirmación.** El dialog es un paso
  *previo*: recién cuando `applyPayment` recibe el monto se arma la venta. No
  hay venta a medias esperando un webhook.
- **El backend absorbe el webhook de forma asíncrona.** El POS nunca espera
  una conexión entrante; solo lee una fila. Un webhook que llega tarde igual
  aterriza en `vPayments`.

**Estados que ve el cajero:** `generando QR` → `esperando pago` (QR visible,
Cancelar activo) → `pagado` | `cancelado` | `vencido` | `error`. Los cinco ya
existen en el dialog de Bancard.

**[I]** **El agujero que Bancard ya tiene y que uPay hereda** — hay que
resolverlo en la capa compartida, no por PSP: si el cliente paga en el mismo
instante en que el cajero cancela, queda un **pago huérfano** (plata acreditada
sin venta). Hoy no hay reconciliación. Con dos PSP el riesgo se duplica.
Propuesta en D8.

## 3.3 El uPOS (terminal física) es otro problema

**[I]** El uPOS es una terminal **autónoma**: cobra por su cuenta, con su
propia pantalla y su propio comprobante. El canal `pos` de Bancard es distinto
—es una Caja POS Android que el POS maneja **por LAN**—.

Salvo que uPay exponga una API de integración de terminal (**[?]**), el uPOS es
un cobro **fuera de banda**: el cajero cobra en el terminal y después registra
el monto en el POS a mano, como cualquier método externo. Eso **no requiere
integración**, solo un medio de pago no-automático. **Recomendación: dejar el
canal `pos` fuera del alcance de la F1** y no prometerlo en el panel.

## 3.4 Conciliación con el arqueo — encaja sin tocar el grano

**[V]** Verificado contra `api/database/migrations/postgres/160_rollup_daily_grain.sql`:

- PK de `rollup_payments_day`: `(companyid, day, outletid, registerid, method, kind)`.
- `method` se deriva en el LATERAL como
  `COALESCE(NULLIF(lower(trim(elem->>'type')),''), NULLIF(lower(trim(elem->>'name')),''))`
  sobre los elementos de `transaction.transactionpaymenttype` (JSONB).
- `kind ∈ ('contado','cobro','devolucion')`; anuladas (`voidedat`) excluidas.

**Veredicto: encaja sin cambiar el grano, y sin migración.** Un cobro uPay se
registra como un elemento más de `transactionPaymentType` con
`type`/`name` = `"uPay"` → el rollup lo agrupa solo en `method='upay'`. No hace
falta columna nueva ni bucket nuevo: el grano ya es "por método", y el método
es texto libre normalizado.

**La condición** es la de §2.2(a): que uPay tenga **su propio medio de pago**.
Si comparte el método "QR" con Bancard, los dos PSP caen en `method='qr'` y el
arqueo deja de poder separarlos — ahí sí se rompe, y no por el grano sino por
la provisión del método.

**[I]** Lo que el rollup **no** resuelve es cuadrar contra la **liquidación**
del PSP (bruto vs. neto de comisión, T+1). Eso es un problema de conciliación
bancaria, vive en el módulo Finanzas (`fin_reconciliation`, mig 72) y está
fuera del alcance de este plan. Anotarlo, no mezclarlo.

---

# Parte 4 — Decisiones propuestas (ninguna cerrada)

| # | Decisión | Propuesta |
|---|---|---|
| **D1** | ¿Módulo propio o canal de otro? | **Módulo propio `upay`**, patrón Bancard: `NATIVE_KEYS` + `CONFIG_KEYS`, flat key + `moduleData.upay`, canales resueltos en `bootstrap.php` |
| **D2** | Canales del módulo | `qr` (F1) y `link` (F2). **`pos` (uPOS) fuera de alcance** — cobro fuera de banda (§3.3) |
| **D3** | Default de los canales | **OFF**, al revés que Bancard: uPay no puede operar sin credenciales cargadas, prenderlo "usable" por default sería mentir |
| **D4** | Credenciales | `CredentialVault` (AES-256-GCM). **Mover** de `EInvoice\` a `Punto\Api\Security\` con alias. Clave privada **nunca** en el bootstrap ni en ninguna respuesta de la API |
| **D5** | Medio de pago | `systemKey='upayQr'`, nombre "uPay". **Generalizar** `ensureQrMethod` → `ensurePspMethod`; `ensureQrMethod` queda como wrapper |
| **D6** | Confirmación | Webhook → `vPayments`; el POS **poll** `byUID`. Idéntico a Bancard. El POS nunca espera una conexión entrante |
| **D7** | Dialog de cobro | **Extraer `<PspQrDialog>` genérico** con adapter por PSP. No duplicar `bancard-qr-dialog.tsx` |
| **D8** | Pagos huérfanos | Job de reconciliación en la capa compartida: pago acreditado sin venta en N minutos → alerta en el centro de notificaciones (`context/31`). **Arregla también Bancard** |
| **D9** | Offline | Método deshabilitado **en su lugar** (sin mover la grilla) + mensaje local. La venta sigue por efectivo. Nunca bloquea la caja |
| **D10** | Sandbox | No arrancar F1 sin sandbox. Si uPay no lo ofrece, exigir un comercio de prueba con montos mínimos |

---

# Parte 5 — Fases

| Fase | Alcance | Esfuerzo | Depende de |
|---|---|---|---|
| **F0** | **Conseguir acceso.** Alta como comercio + desarrollador, leer el doc real de `desarrolladores.upay.com.py`, confirmar §6. **Sin esto no arranca nada** | — (owner) | ueno bank |
| **F1a** | ~~Refactor de raíz, **sin uPay todavía**: `ensurePspMethod` genérica, `<PspQrDialog>` + adapter Bancard. Regresión cero en Bancard~~ **HECHA 2026-08-23** (ver §2.4). `CredentialVault` al namespace compartido queda para F1b, con su segundo consumidor | M | — |
| **F1b** | Módulo `upay` (backend): allowlist, toggle, canales, `bootstrap.php`, credenciales cifradas, UI de config en el panel | M | F0, F1a |
| **F1c** | Canal QR: `POST /v1/upay` (create/cancel), webhook → `vPayments`, adapter uPay del dialog, medio de pago `upayQr` | L | F1b |
| **F2** | Canal Link de Pagos: generar link desde caja/panel, compartir, vigencia 48 h, estado del link | M | F1c |
| **F3** | Reconciliación de huérfanos (D8) — cubre Bancard y uPay | M | F1c |
| **F4** | Conciliación de liquidación (bruto/neto/comisión) contra Finanzas | L | F3, `context/22` |

**[V]** F1a se hizo el 2026-08-23, sin credenciales y sin uPay. A partir de
acá **todo lo que falta depende de F0** (acceso a la documentación y
credenciales de ueno): no hay más trabajo de código desbloqueado.

---

# Parte 6 — Preguntas abiertas

**Para uPay / ueno bank (F0):**

1. **¿La API para integrar es `api.pagopar.com` o hay una API uPay nueva?**
   De esto depende todo el diseño. Si es Pagopar, ¿la v1.1 sigue soportada?
2. **¿Hay un flujo de cobro presencial** (monto + referencia) o el único
   modelo es el pedido de e-commerce con `comprador.email`, `ciudad_id` y
   `categoria` por ítem obligatorios? (§1.5)
3. **¿Hay webhook con contrato documentado** —payload, firma/HMAC,
   reintentos, idempotencia— o el modelo soportado es polling? ¿Cuál es la
   latencia típica de confirmación de un QR?
4. **¿Existe sandbox?** ¿Con qué credenciales y qué hace falta para obtenerlas?
5. **¿Hay endpoint de anulación/reversa** de un cobro QR ya acreditado?
6. **¿Hay endpoint de liquidación/settlement** (lote diario, bruto, comisión,
   neto) para cuadrar el arqueo contra lo acreditado?
7. **¿El uPOS expone API de integración**, o es cobro fuera de banda? (§3.3)
8. **Autenticación:** ¿sigue siendo `sha1(clave_privada . tipo_operacion)`?
   ¿Hay rotación de claves? ¿Allowlist de IP?
9. **¿El QR de uPay es interoperable con billeteras de PY/BR/AR** como dice la
   card del panel, o eso es marketing del QR bancario genérico?
10. **Comisión del QR:** ¿3,6% + IVA es correcto para QR presencial de comercio?

**Para el owner:**

11. ¿uPay **convive** con Bancard en el mismo comercio (el cajero elige) o son
    excluyentes? Asumo que conviven — y por eso D5 es obligatorio.
12. ¿Hay ya relación comercial con ueno bank, o hay que abrirla? ¿Contrato
    único uPay firmado? (`upay.com.py/pdfs/contrato-unico-upay/`)
13. ¿Link de Pagos (F2) vale la pena, o el foco es solo QR de mostrador?

---

## Fuentes

- **[V]** `frontend/lib/modules-catalog.ts:193-196` — card `upay` "Próximamente"
- **[V]** commit `0565da2f` — módulo Bancard; parquea uPay explícitamente
- **[V]** `api/lib/Modules/ModulesService.php` — patrón de módulo (allowlist,
  double-write, canales, provisión del medio de pago)
- **[V]** `api/lib/PaymentMethods/PaymentMethodService.php:254-294` — `ensureQrMethod`
- **[V]** `api/v1/bootstrap.php:174-191` — resolución server-side de canales
- **[V]** `frontend/components/register/bancard-qr-dialog.tsx` — ciclo de cobro QR
- **[V]** `frontend/components/register/pay-dialog.tsx:206-218, :560-577` —
  filtrado del método y degradación online-only
- **[V]** `api/lib/EInvoice/CredentialVault.php` — AES-256-GCM, `APP_ENCRYPTION_KEY`
- **[V]** `api/database/migrations/postgres/160_rollup_daily_grain.sql:356-385, :511-545`
  — grano y derivación de `method`
- **[V]** Documentación técnica Pagopar —
  `https://cdn.pagopar.com/assets/documentos/Documentacion_Pagopar.pdf` (rev. 2017-06-20)
- **[V]** `https://desarrolladores.upay.com.py/` — SPA, docs detrás de login
- **[V]** `https://upay.com.py/pagopar/` — integración Pagopar → uPay
- **[V]** `https://ayuda.ueno.com.py/section/upay` — secciones de ayuda (uPOS, adhesión, planes)
- **[V]** Prensa 2026-08 (El Nacional, Revista Plus, Unicanal, FOCO) —
  lanzamiento de Link de Pagos, comisiones, acreditación, specs del uPOS
