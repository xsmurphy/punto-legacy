# KuDE (representación gráfica) y portal del comprador por RUC

> Estado: **investigación + plan. Nada implementado.** D1–D11 son propuestas,
> ninguna cerrada con el owner. Revisado 2026-08-23 tras la aclaración del owner
> sobre el valor fiscal del ticket: se reescribieron D1, D5 y §2.4, y se agregaron
> D9–D11. Complementa `context/28-facturacion-electronica-plan.md`
> (F6 ya implementó el portal por comprobante), no lo reemplaza.
>
> Marcas: **[V]** verificado contra código o fuente oficial, **[S]** suposición,
> **[I]** interpretación propia sobre fuente verificada, **[?]** hay que preguntar.
> Fuentes al final.

## La pregunta que originó esto (owner, 2026-08-23)

El ticket impreso no es un KuDE; hay que ver cómo se arma online. Y hace falta
un portal donde el comprador ingrese su RUC y liste sus facturas, con la URL
impresa en el ticket.

**Aclaración del owner, mismo día, que cambia el diseño:** *"El ticket no es un
comprobante obligatorio cuando ya emitís factura electrónica, por ende el papel
impreso no tiene valor fiscal y debe tener la leyenda que lo aclare."* Es decir:
**KuDE** (representación gráfica oficial, requisitos del MT §13.4) y **ticket
interno** (comprobante de la operación, sin valor fiscal, con leyenda + QR/URL
al portal) son dos documentos distintos, y lo que la caja imprime por defecto es
el segundo. Las secciones 1.2 a 1.4 verifican esa postura contra la norma; el
veredicto es que **se sostiene**, con una excepción dura (D9).

---

# Parte 1 — KuDE

## 1.1 Qué es y qué exige la norma

**[V]** El KuDE (*Kuatia Documento Electrónico*) es la representación gráfica
del DE, entregable al receptor no electrónico o consumidor final en formato
físico o digital. Es un documento tributario **auxiliar**: el documento fiscal
es el XML firmado. Manual Técnico SIFEN v150, cap. 13.1.

**[V]** Contenido mínimo — MT v150 §13.4, estructura obligatoria:

| Sección | Campos |
|---|---|
| Encabezado | Denominación ("KuDE de Factura Electrónica"), emisor (razón social D105, fantasía D106, **actividad económica D131**, dirección D107, ciudad D116), timbrado (RUC D101, timbrado C004, vigencia C008/C009, **número de documento C007**), fecha/hora emisión D002, condición de venta E602, **cuotas E644**, **moneda D016 y tipo de cambio D018**, receptor (D206/D210, D211, D213, D214, D216), tipo de operación D012 |
| Ítems | Código E701-E707, descripción E708, unidad E710, cantidad E711, precio unitario E721, descuento EA002, valor de venta desglosado por afectación IVA E732 (0% / 5% / 10%) |
| Totales | Subtotales por tasa F002/F004/F005, total F007, total en guaraníes F022, liquidación IVA F014/F015/F016 |
| Consulta SIFEN | URL de consulta + **CDC en once grupos de 4 posiciones** (§13.4.4) |
| QR | Código bidimensional §13.8 |

**[V]** El QR: mínimo **25 mm de ancho** (22 de contenido + 3 de quiet zone),
ISO/IEC 18004. Su contenido es el campo **J002 (`dCarQR`)**, armado con
nVersion, CDC, dFeEmiDE, ID del receptor, total, IVA, cantidad de ítems,
DigestValue de la firma, IdCSC y un `cHashQR` SHA256 sellado con el **CSC** (32
alfanuméricos que la SET entrega al facturador). MT v150 §13.8.1–§13.8.4.

**[V]** Papel legible **6 meses mínimo**; prohibida la matricial (MT §13.2, RG
05/18 art. 11).

**[V]** No puede haber en el KuDE información que no forme parte del XML
firmado, **salvo el campo J003** — "información adicional de interés para el
emisor": espacio libre para información comercial o mensajes al receptor, que
**no se envía en el XML a SIFEN** (MT §13.4.5). Es exactamente el hueco legal
donde entra la URL del portal de Punto.

**[V]** El KuDE puede generarse **antes** de la aprobación de SIFEN (modelo de
validación posterior, MT v150 cap. 6). No hay que esperar el `sifen_status` para
imprimir — alcanza con el DE firmado.

## 1.2 Entrega del KuDE: la obligación es de canal, no de papel

Sección escrita para verificar la aclaración del owner del 2026-08-23. **La
norma la respalda**, con una sola excepción dura.

### La norma que manda hoy es el Decreto 872/2023

**[V]** El **Decreto N° 872/2023** reglamenta la emisión electrónica de
comprobantes de venta y demás documentos tributarios a través del SIFEN, y rige
desde el **2024-01-02**. Es el escalón normativo vigente: la RG 05/18 es del
Plan Piloto y el MT v150 es de septiembre de 2019. Los tres concuerdan en este
punto, pero se cita el decreto. **[?]** Si la RG 05/18 sigue formalmente vigente
tras el 872/2023 no surge de las fuentes públicas — C2.

### Art. 25 — Envío y entrega del Documento Electrónico

**[V]** Encabezado, literal: *"El facturador electrónico está obligado a enviar
o poner a disposición del receptor el archivo XML del Documento Electrónico y/o
a entregar el KuDE del mismo, conforme a lo siguiente"*. **Enviar, poner a
disposición y entregar son alternativas, no obligaciones acumulativas.**

**[V]** El decreto reconoce **dos** casos, no tres: el consumidor final no tiene
régimen propio, cae dentro de "receptor que no sea facturador electrónico".

| Caso | Texto del art. 25 | ¿Papel obligatorio? |
|---|---|---|
| Receptor **facturador electrónico** | *"deberá enviar el archivo XML y el KuDE del Documento Electrónico por web service, mensajería de datos, correo electrónico o ponerlo a disposición en su sistema de información o portal web para que el receptor lo descargue y consulte"* | **No.** Nunca. |
| Receptor **no electrónico** — con RUC **o** consumidor final | *"podrá enviar o entregar el KuDE por cualquiera de los siguientes medios: a. Impreso. b. Por correo electrónico, en archivo electrónico con formato de documento portátil. c. Disponerlo para su descarga, consulta o impresión, en su sistema de información o portal web"* | **No.** El emisor elige el medio. |
| **A solicitud del comprador** (cualquier caso) | *"En todos los casos, a solicitud del comprador, el facturador electrónico deberá entregar el KuDE de manera impresa."* | **Sí. Obligatorio.** |

**[V]** El inciso **c** describe literalmente el portal de Punto: *"disponerlo
para su descarga, consulta o impresión, en su sistema de información o portal
web"*. Poner el KuDE a disposición en el portal es **cumplimiento pleno**, al
mismo nivel que el papel — no un sustituto degradado ni un régimen de excepción.

**[V]** Concordancias: MT v150 cap. 2 — *"si el comprador o receptor no es
facturador electrónico, el emisor deberá enviar o disponibilizar una
representación gráfica del documento (KuDE) que soporta la transacción en
formato físico o digital"*; MT §13.1 — el KuDE *"puede ser entregada al receptor
no electrónico o consumidor final en formato físico o digitalizado"*; RG 05/18
art. 10 b), que lista los mismos tres canales.

**[V]** Art. 26: *"Para todos los fines en que se requiere el uso o presentación
de la representación gráfica del Documento Tributario Electrónico tendrá validez
la presentación del KuDE en formato digital."* El KuDE digital no es una versión
de segunda de nada.

### Veredicto

**El owner tiene razón.** Emitido el DE y disponible el KuDE en el portal, el
papel que sale de la caja no es el comprobante y no tiene por qué serlo. Pero la
decisión **no puede ser "Punto nunca imprime el KuDE"**: el art. 25 *in fine* lo
vuelve obligatorio a pedido del comprador, sin excepciones ni umbral de monto.
Eso es una funcionalidad que hay que construir, no un caso de borde — ver D9.

## 1.3 El ticket interno no fiscal: qué dice la norma, y qué no dice

### ¿Se puede imprimir otro papel en lugar del KuDE?

**[V] Sí, y la norma no lo regula.** El Decreto 872/2023 (arts. 25–26), la RG
05/18 (arts. 10–11) y el MT v150 cap. 13 regulan **el KuDE**: qué campos lleva,
cómo se imprime, cuánto tiene que durar el papel. Ninguno prohíbe ni condiciona
que el comercio imprima además un papel propio. Mientras el KuDE esté disponible
por alguno de los canales del art. 25.2, el resto es papelería comercial.

### ¿Hay una leyenda obligatoria? No.

**[V] No existe redacción obligatoria ni sugerida en la norma paraguaya de FE.**
Verificado por ausencia: la palabra "leyenda" **no aparece ni una vez** en el
Manual Técnico v150 (texto completo extraído, 10.360 líneas). Tampoco hay texto
prescripto en el Decreto 872/2023 ni en la RG 05/18.

**[V]** La única leyenda que el régimen paraguayo de documentación sí impone es
**"No válido para crédito fiscal"**, exigida a partir de la segunda copia de un
Comprobante de Venta (régimen del Decreto N° 6539/2005 y sus modificaciones). No
aplica a este caso, pero es el único giro consagrado que existe: conviene
parecerse a él antes que inventar una frase nueva.

**[I]** La leyenda es **prudencia, no cumplimiento**. Se elige por lo que evita
(1.4), no porque una norma la pida.

### Por qué tampoco alcanza con el "KuDE resumen"

**[V]** El KuDE resumen parecía la salida obvia — CDC, cantidad de ítems, total
y QR: tres líneas más que un ticket interno — pero **no es un default legítimo**.
MT §13.7: *"Si el consumidor pide se permite la impresión de un KuDE resumen"*.
RG 05/18 art. 11: *"con previo consentimiento del consumidor"*. Imprimirlo
siempre, sin pedido ni consentimiento, queda **peor parado** que el camino del
owner, que el art. 25.2.c habilita sin condición alguna. De las dos rutas, la
del owner es la más limpia.

**[V]** El Decreto 872/2023 art. 26 además faculta a la DNIT a definir un "KuDE
simplificado" con mínimo *"el Código de Control (CDC) del Documento Electrónico,
la cantidad de ítems, la fecha de emisión, su monto total, y el Código QR"*.
**[?]** No consta que la DNIT lo haya definido — F6.

## 1.4 ¿Riesgo real de que el papel se lea como comprobante irregular?

**Sí, pero es riesgo de *caracterización*, no una prohibición expresa.** El owner
no está sobreactuando. Tampoco es el riesgo más grande de este diseño.

**[V]** La RG 13/19 reúne en un solo cuerpo los incumplimientos formales que
configuran contravención (art. 176 Ley 125/91). Anexo §2, sobre comprobantes:

| Ítem | Texto literal | Multa |
|---|---|---|
| a) | *"Expedir Comprobantes de Venta, Notas de Remisión y Documentos Complementarios con errores u omisiones de requisitos preimpresos y no preimpresos previstos en el Decreto N° 6539/2005 y sus modificaciones."* | ₲ 50.000 |
| b) | *"Expedir Comprobantes de Venta, Notas de Remisión, Documentos Complementarios y Comprobantes de Retención a través de medios distintos a los autorizados."* | ₲ 50.000 |
| f) | *"No expedir comprobantes de venta, documentos complementarios, notas de remisión y comprobantes de retención."* | ₲ 50.000 |

**[I]** Los tres tipos enganchan sobre **un Comprobante de Venta**. Un papel que
no se presenta como tal no cae en ninguno por su propio texto. La exposición es
que un fiscalizador — o el propio comprador — **caracterice** el ticket como el
comprobante que el comercio expidió: ahí el inciso a) sí muerde, porque ese papel
no tiene timbrado, ni CDC, ni QR fiscal. **La leyenda es exactamente lo que
derriba esa caracterización**, y cuesta una línea de papel. Barata, entonces se
pone; pero se pone por eso, no porque la norma la exija.

**[V] El riesgo grande es otro, y este no es interpretativo:** si el ticket no
lleva una ruta que funcione hacia el KuDE, el comercio **no puso nada a
disposición** y está incumpliendo el art. 25 — inciso f) más incumplimiento del
decreto. **La pieza que sostiene el diseño es el QR/URL al portal, no la
leyenda.** Un ticket sin leyenda y con QR está mucho mejor parado que uno con
leyenda y sin QR.

## 1.5 80 mm: ¿entra?

**[V] Sí, y es el formato recomendado por la propia norma.** MT v150 §13.6
define el "KuDE Formato 2 (cinta de papel)" y lo llama *"el más adecuado para
ventas al consumidor final (supermercados, farmacias, restaurantes, estaciones
de servicio)"*. RG 05/18 art. 11 permite el tamaño de mini impresora POS para
B2C; MT §13.5 admite cualquier formato y tamaño de papel estándar.

**[V]** Existe además el **KuDE resumen** (§13.7): a pedido del consumidor, sin
detalle de ítems ni de impuestos — solo cantidad de ítems y monto total. El
detalle queda en la consulta pública por CDC o QR.

**Conclusión: la térmica de 80 mm *puede* imprimir un KuDE válido** — no hace
falta un PDF A4 para el consumidor final, y el KuDE a demanda (D9) sale por la
misma impresora que el ticket. Que *pueda* no significa que *deba*: el impreso
por defecto es el ticket interno (D1), y esta sección es lo que habilita que el
KuDE a demanda no requiera hardware distinto.

## 1.6 Qué devuelve Factomate

**[V]** `POST /api/electronicDocument/Bulk` devuelve en `Items[0]`: `CDC`,
`Success`, `SignDate`, **`DCarQR`** (el campo J002, la cadena del QR fiscal) y
`XmlUrl`. Ya está persistido en `einvoice_document.provider_response` y se
extrae en `EInvoiceService::portalDocument()` como `qrUrl`
(`api/lib/EInvoice/EInvoiceService.php:609-619`).

**[V]** `GET /api/electronicDocument/getkude/{cdc}` devuelve el **KuDE ya
renderizado como PDF binario** (probado: 33 KB, corrida real del 2026-07-30,
`context/28`). Tarda 3–8 s tras el `/Bulk`; retry 3× solo ante 5xx
(`FactomateProvider::kude()`).

**Respuesta a la pregunta del owner: las dos cosas.** Factomate entrega el KuDE
renderizado *y* los datos crudos para armarlo (CDC + `DCarQR`). Para 80 mm hay
que armarlo nosotros — **[S]** el PDF de `getkude` es casi con seguridad A4 y
no sirve para una térmica de líneas.

**[?]** Sin confirmar: si `DCarQR` es la cadena completa lista para el QR (con
`cHashQR` e `IdCSC`) o solo la URL base; si `getkude` acepta parámetro de
formato; quién administra el CSC del tenant. Ver preguntas abiertas.

## 1.7 Qué le falta HOY al ticket — contra el código, no contra el backlog

**[V]** Lo único que imprime hoy el bloque `fe_py` es el QR del **portal de
Punto**, no el de ekuatia (`render-template.ts:118-131`, `blocks.ts:369`, en
`frontend/lib/hardware/printers/`).

| Falta | Evidencia |
|---|---|
| **CDC** (44 dígitos, 11 grupos de 4) | No existe el `BlockType` — lista completa en `frontend/lib/types/print-template.ts:75-129` |
| **QR fiscal (`DCarQR`)** | Idem. `fe_py` imprime el link del portal |
| Denominación "KuDE de Factura Electrónica" | `document_type` imprime el nombre del doctype del tenant, no la denominación SET |
| Actividad económica del emisor (D131) | Sin bloque |
| Moneda (D016) y tipo de cambio (D018) | Sin bloque |
| Cuotas (E644) | Sin bloque (`sale_type_credit` existe, la cantidad de cuotas no) |
| Leyenda/URL de consulta SIFEN | Sin bloque |
| **Timbrado equivocado** | `auth_number`/`auth_start_date`/`auth_expiration` salen de `activeRegister` (`build-ticket-data.ts:354-356`) — la config local de la caja, **no** el timbrado del `BranchDocumentType` con el que Factomate emitió |
| **Número equivocado** | `documentNumber` sale de `result.invoiceNumber` (`build-ticket-data.ts:347`) — el correlativo interno de Punto. El número fiscal lo asigna la SET (`number: -1`, `context/28`) |
| **No hay CDC en el momento de imprimir** | La emisión es asíncrona: `enqueueForSale()` encola y `drain()` emite después (`EInvoiceService.php:994,1113`). `SaleResult` devuelve **solo** `einvoicePortalUrl` (`api/lib/Sales/SaleResult.php:26`) |

Los bloques por tasa (`subtotal_by_rate`, `iva_by_rate`, `iva_total`,
`item_total_by_rate`) ya existen desde F3c de `context/38`: esa parte del §13.4
está cubierta.

## 1.8 Decisiones propuestas

> **Revisión 2026-08-23.** D1 y D5 se reescribieron y se agregaron D9–D11 tras
> verificar la entrega del KuDE contra el Decreto 872/2023 (secciones 1.2–1.4).
> La versión anterior de D1 — *"el ticket 80 mm se convierte en KuDE"* — era
> correcta como posibilidad técnica pero innecesaria como default.

**D1 — El impreso por defecto de la caja es un ticket interno SIN valor fiscal,
no un KuDE.** Lleva la operación (ítems, totales, forma de pago), la leyenda que
aclara que no es comprobante fiscal (D10) y el QR/URL al portal donde vive la
factura real (D11). Fundamento: Decreto 872/2023 art. 25.2.c — poner el KuDE a
disposición para descarga, consulta o impresión en el portal web del emisor es
cumplimiento pleno de la obligación de entrega, para receptor no electrónico y
para consumidor final por igual (1.2). No hay caso en que la norma impida este
diseño; el único límite es el pedido del comprador, que D9 cubre.

**D2 — Lo que se imprime lo sigue decidiendo la plantilla** (`context/08`). No
se hardcodea un layout KuDE en el renderer: se **agregan bloques al catálogo**
(D3) y se publica una **plantilla de fábrica "Factura Electrónica — KuDE
80 mm"** editable. Coherente con la decisión del 2026-07-29: la responsabilidad
legal del impreso es del comercio, Punto da las piezas correctas.

**D3 — Bloques nuevos**: `cdc` (11 grupos de 4), `qr_fiscal` (`DCarQR`,
≥ 25 mm efectivos), `einvoice_denomination`, `outlet_activity`, `currency`,
`exchange_rate`, `installments`, `sifen_consult_url`, y el timbrado/número
fiscales aparte (`einvoice_auth_number` / `_auth_start` / `_auth_end` /
`_document_number`) para no pisar los bloques del timbrado local — un comercio
sin FE sigue imprimiendo con su numeración interna.

**D4 — El QR fiscal no se genera en Punto.** Viene en `DCarQR`; Punto no
necesita el CSC ni el hash del §13.8.3. Reduce mucho el alcance — confirmar con
Factomate antes de comprometerlo (F1).

**D5 — Emisión sincrónica en la venta online, outbox como degradado.** `/Bulk`
es sincrónico y devuelve CDC + `DCarQR` en la misma llamada (`context/28`). **Con
D1, esto deja de ser prerequisito del impreso por defecto** — el ticket interno
solo necesita el link del portal, que ya existe hoy. Sigue haciendo falta para
dos cosas: poder imprimir el KuDE en el acto cuando el comprador lo pide (D9), y
poner el CDC en el ticket interno si se decide incluirlo como dato de consulta.
Si falla o la caja está offline, cae al outbox actual. **No se espera
`sifen_status`** (validación posterior, MT cap. 6). Costo: latencia en caja (O2).

**D6 — Reimpresión.** Los campos fiscales salen del detalle de transacción
persistido, no de la respuesta de la venta: se enganchan al resolver canónico
de `context/39`. Hoy la reimpresión ni siquiera lleva el QR del portal (hueco
ya documentado en `context/28`) — con D1 ese hueco pasa de molestia a
incumplimiento: un ticket reimpreso sin ruta al KuDE no pone nada a disposición.

**D9 — El KuDE completo es un documento aparte, y a pedido del comprador es
OBLIGATORIO imprimirlo.** Decreto 872/2023 art. 25 *in fine*, sin excepciones ni
umbral de monto (1.2). El POS necesita una acción explícita **"Imprimir KuDE"**
sobre la venta — recién cerrada o buscada después — además del envío digital
(email/WhatsApp) y de la descarga desde el portal. **Es un requisito normativo,
no una mejora de UX**: sin esa acción el comercio no puede cumplir cuando se lo
piden en el mostrador. Sale por la misma térmica de 80 mm (1.5), así que no
introduce hardware nuevo. **[?]** Falta resolver el caso offline: si no hay CDC
todavía, no hay KuDE que imprimir — ver F5 y O6.

**D10 — El texto de la leyenda lo decide Punto; la norma no lo prescribe.** No
hay redacción obligatoria ni sugerida (1.3). Propuesta, calcada del único giro
que el régimen paraguayo sí consagra (*"No válido para crédito fiscal"*,
Decreto 6539/05):

> **Documento no válido como comprobante fiscal.**
> Su Factura Electrónica está disponible en `punto.la/f/AB3K9Q`

Va como bloque de plantilla propio (`non_fiscal_notice`), presente en la
plantilla de fábrica del ticket interno. **[?]** Confirmar la redacción con el
contador antes de publicarla como plantilla de fábrica (C1): cambiarla después
implica migrar las plantillas ya personalizadas de cada comercio.

**D11 — El portal tiene que ENTREGAR el KuDE, no solo mostrar datos.** El art.
25.2.c exige disponerlo *"para su descarga, consulta o impresión"*. El F6 actual
ya streamea el PDF (`?resource=kude&t=`), así que el canal existe — pero con D1
pasa a ser **la pieza que sostiene el cumplimiento del comercio**, no una
comodidad. Consecuencias concretas: el QR/URL del ticket no puede ser opcional
en la plantilla de fábrica (O5); la reimpresión tiene que llevarlo (D6); y una
caída del portal deja de ser un problema de UX para ser uno normativo, con lo
que implica en monitoreo y SLA.

---

# Parte 2 — Portal del comprador

## 2.1 Lo que ya existe (F6, verificado)

**[V]** `EInvoice\PortalToken` firma `base64url(uuid_bin(company) ‖
uuid_bin(tx) ‖ hmac[12])` = 59 caracteres. `GET /v1/einvoice-public?t=` da los
datos y `?resource=kude&t=` streamea el PDF, **sin autenticación**: el
aislamiento multi-tenant sale del `companyId` firmado dentro del token, y token
inválido / venta inexistente / documento ausente responden lo mismo (404).
Página pública `/factura/<token>`. La URL resultante mide ≈ 88 caracteres
(`EInvoiceService::portalUrl()`): se escanea, no se tipea.

## 2.2 El problema: el RUC no es un secreto

Los RUC paraguayos son públicos. "Ingresá tu RUC y te listo tus facturas"
permite reconstruir el historial de compras de un tercero contra ese comercio:
proveedores, volúmenes, frecuencia. No es exponer un comprobante, es exponer
una relación comercial. **El RUC es un identificador, nunca un factor de
autenticación.**

## 2.3 Opciones evaluadas

| Opción | Veredicto |
|---|---|
| RUC solo | **Rechazada.** Enumerable con una lista de RUC. |
| RUC + nº de un comprobante suyo | **Rechazada como factor único.** Prueba posesión de *un* ticket, y abre *todo* el historial. Cualquiera que vio un ticket (mozo, cajero, quien lo encontró en la basura) escala a historial completo. |
| Token por comprobante (F6) | **Se mantiene como mecanismo principal.** Fricción cero, alcance mínimo: un documento. |
| RUC + OTP a un canal registrado | **Elegida para el listado.** |

**D7 — El listado por RUC exige OTP a un canal ya registrado en el contacto de
ese comercio.** El comprador escribe su RUC; si hay un `contact` con ese RUC y
con teléfono o email cargado, se manda un código de 6 dígitos **a ese canal**
(nunca a uno que el visitante elija) y recién ahí se abre una sesión corta. El
OTP prueba **control del canal**, que es lo único que el atacante no obtiene
del padrón público. Sin canal cargado no hay listado: el comprador le pide el
comprobante al comercio.

Reglas que la sostienen: respuesta idéntica exista o no el RUC y tenga o no
canal (sin enumeración); rate limit por RUC y por IP, OTP de vida corta e
intentos limitados; sesión de 30 min sin "recordarme"; y el listado expone lo
mismo que ya expone `portalDocument` (emisor, fecha, total, CDC, estado fiscal,
link a ekuatia, KuDE) — nunca `error_message`, `attempts` ni datos de otro
contacto.

**D8 — Alcance del listado: por comercio, no cross-tenant.** Agregar las
compras de un RUC en *todos* los comercios Punto convierte a Punto en
depositario del historial de compras del comprador — blast radius mucho mayor y
discusión legal propia. El scope es `companyId`. **[?]** Requiere confirmación
del owner (O1): es la única decisión de esta parte que cambia la arquitectura.

## 2.4 Qué se imprime en el ticket

> **Revisado 2026-08-23.** D1 invierte la conclusión de esta sección. La versión
> anterior daba por hecho que el ticket llevaba el QR fiscal y discutía si valía
> la pena un segundo QR para el portal. Con el ticket interno no fiscal, **el QR
> del portal es el único QR del impreso por defecto, y no es opcional.**

**Ticket interno (impreso por defecto, D1).** No lleva QR fiscal: no es un KuDE.
Lleva **el QR del portal, obligatorio por D11** — es la ruta que materializa la
puesta a disposición del art. 25.2.c — más la URL corta en texto debajo, para
quien no puede escanear. Ese par (QR + URL tipeable) es lo que sostiene el
cumplimiento; sin él, el ticket interno deja al comercio en infracción (1.4).

**KuDE a demanda (D9).** Ahí sí manda el QR fiscal del §13.8 (≥ 25 mm), y es el
único obligatorio. La URL del portal puede acompañarlo: **[V]** es información
ajena al XML y cabe legalmente en el espacio del campo J003 (MT §13.4.5). Los
dos QR nunca conviven en el mismo papel, así que el problema de apilarlos —
~3 cm de papel por ticket — desaparece.

**URL corta, en los dos casos.** Requiere un acortador propio con código de 6–8
caracteres (`punto.la/f/AB3K9Q`), tipeable a mano. **[V]** El acortador legacy
(`getShortURL()`, `api/includes/functions.php:83`) hace `file_get_contents`
contra un PHP de `/screens`: no se reusa, se hace tabla propia. Con D1 sube de
prioridad: la URL de 88 caracteres de F6 se escanea pero no se tipea, y el
ticket interno necesita un fallback legible cuando el QR no se puede leer.

---

# Fases

| Fase | Qué | Esfuerzo | ¿Depende de Factomate? |
|---|---|---|---|
| **K0** | Ticket interno (D1): bloque `non_fiscal_notice` + QR/URL del portal obligatorios en la plantilla de fábrica | S | No |
| **K1** | Bloques fiscales nuevos (D3) + plantilla de fábrica KuDE 80 mm | M | No |
| **K2** | Emisión sincrónica online (D5): CDC, `DCarQR`, nº fiscal y timbrado FE en `SaleResult` | M | **Sí** — latencia de `/Bulk` (F2) |
| **K3** | Reimpresión: campos fiscales y ruta al portal desde el detalle de transacción (`context/39`) | S | No |
| **K4** | KuDE resumen (§13.7) como variante de plantilla, **a pedido del consumidor** | S | No |
| **K5** | Acción "Imprimir KuDE" en POS y panel (D9) — **requisito normativo** | M | Sí (`getkude` o bloques propios, F3) |
| **P1** | Acortador propio + bloque de URL corta del portal | S | No |
| **P2** | Portal de listado: RUC + OTP, sesión de comprador, rate limit (D7/D8) | L | No |
| **P3** | Envío digital del KuDE (email/WhatsApp) al cerrar la venta | M | Sí (PDF de `getkude`) |

Orden sugerido: **K0 → P1 → K2 → K5 → K1 → K3 → P2**. D1 reordena el plan: K0 y
P1 son chicos, no dependen de Factomate y son los que ponen al comercio en
cumplimiento — antes eran adorno. K5 es lo siguiente porque es obligación, no
mejora. K1 baja de prioridad: la plantilla KuDE completa ya no es el impreso por
defecto, es el insumo de K5. K1 sigue sin tener sentido antes de K2 (produciría
bloques que siempre salen en blanco).

---

# Preguntas abiertas

## Para Factomate (F)

1. **F1 — ¿`DCarQR` es la cadena completa del QR según MT §13.8 (con `cHashQR`
   e `IdCSC`), lista para imprimir tal cual?** Si no, Punto necesita el CSC del
   tenant e implementar el hash, y D4 cae. Es la que más cambia el alcance.
2. **F2 — Latencia p95 de `POST /Bulk`.** Define si D5 es tolerable en caja.
3. **F3 — ¿`getkude` acepta parámetro de formato (A4 / cinta 80 mm)?** ¿Qué
   tamaño devuelve hoy?
4. **F4 — ¿Quién administra el CSC del tenant** — el comercio en Factomate, o
   Factomate?
5. **F5 — Ventana de tolerancia de la fecha de emisión de un DE diferido.** Ya
   abierta en `context/28` §Offline; bloquea el KuDE de las ventas offline.
6. **F6 — ¿La DNIT definió el "KuDE simplificado" del art. 26 del Decreto
   872/2023?** El decreto la faculta a hacerlo (mínimo: CDC, cantidad de ítems,
   fecha, total y QR) pero no consta que lo haya hecho. Si existe y Factomate lo
   soporta, K4 cambia de forma.

## Para el owner (O)

1. **O1 — ¿El listado por RUC es por comercio o cross-tenant?** (D8). Decisión
   estructural de la Parte 2.
2. **O2 — ¿Se acepta agregar 1–3 s a la venta** para tener CDC en el momento de
   imprimir (D5)? **Reformulada por D1**: ya no decide si el ticket es KuDE o no
   — el ticket interno no necesita CDC. Ahora decide dos cosas menores: si el CDC
   aparece como dato de consulta en el ticket interno, y si el KuDE a demanda
   (D9) se puede imprimir en el acto o hay que esperar el drain del outbox.
3. **O3 — Canal del OTP**: SMS, WhatsApp (ya hay Evolution) o email. Tiene
   costo por envío; hay que definir quién lo paga.
4. **O4 — Dominio corto** (`punto.la/f/...`) o `APP_URL` con path corto. **Sube
   de prioridad con D1**: la URL corta pasa a ser el fallback legible del canal
   de cumplimiento, no una comodidad.
5. **O5 — ¿Se puede borrar de la plantilla el bloque del QR/URL del portal?**
   Reformulada: el bloque crítico ya no es el CDC ni el QR fiscal (que en el
   ticket interno no van), es la ruta al portal. Sin ella el comercio incumple el
   art. 25 (1.4). La decisión del 2026-07-29 dice no bloquear el guardado, pero
   acá lo que falta no es un disclaimer: es el único canal de entrega. Misma
   pregunta para el bloque `non_fiscal_notice` (D10), donde el argumento es más
   débil porque la leyenda no la exige ninguna norma.
6. **O6 — Venta offline: ¿qué se imprime si el comprador pide el KuDE?** Sin CDC
   no hay KuDE que imprimir y el art. 25 *in fine* no admite excepciones. Las
   opciones son diferir la entrega (avisar que llegará por el portal/email) o
   bloquear. Depende de F5.

## Para el contador o la DNIT (C)

Cosas que **no se pudieron determinar con las fuentes públicas** y que no se van
a resolver interpretando más:

1. **C1 — Redacción de la leyenda del ticket interno (D10).** No existe texto
   obligatorio ni sugerido en la norma de FE (verificado, 1.3). ¿La propuesta
   (*"Documento no válido como comprobante fiscal"*) es la formulación que un
   fiscalizador espera, o conviene calcar el giro consagrado *"No válido para
   crédito fiscal"* del Decreto 6539/05?
2. **C2 — ¿La RG 05/18 sigue vigente tras el Decreto 872/2023?** El decreto rige
   desde el 2024-01-02 y la RG es del Plan Piloto. No surge de las fuentes
   públicas si fue derogada, sustituida por una RG reglamentaria posterior o si
   convive. Importa para K4: la condición de "previo consentimiento del
   consumidor" del KuDE resumen sale de la RG, no del decreto.
3. **C3 — ¿Un QR impreso en un ticket satisface "disponerlo para su descarga,
   consulta o impresión" del art. 25.2.c?** La norma no dice cómo el receptor
   tiene que enterarse de dónde está el KuDE. **[I]** Nuestra lectura es que el
   QR/URL en el ticket es justamente eso, y es una lectura razonable — pero es
   *nuestra*, no está escrita en ningún lado, y es el supuesto sobre el que se
   apoya todo el diseño de D1. Vale la pena una consulta vinculante.
4. **C4 — ¿Cambia algo si el receptor tiene RUC** (no electrónico) en vez de ser
   consumidor final? El art. 25 no los distingue (1.2), pero un receptor con RUC
   necesita el KuDE para respaldar crédito fiscal (MT §13.1) y depende del portal
   de un tercero para obtenerlo. **[I]** Interpretamos que la norma se cumple
   igual; en la práctica conviene ofrecerle el KuDE por email en el acto.

---

# Fuentes

- **Decreto N° 872/2023**, arts. 25 y 26 (vigente desde 2024-01-02) — reglamenta la emisión electrónica vía SIFEN; es la norma que rige la entrega del KuDE — https://lexparaguaya.com/docs/decreto-n-872-2023
- Manual Técnico SIFEN v150 (DNIT, 10/09/2019 — versión vigente), cap. 2, cap. 6 y cap. 13 — https://www.dnit.gov.py/documents/20123/420592/Manual+T%C3%A9cnico+Versi%C3%B3n+150.pdf
- RG N° 05/2018, art. 1 y art. 11 — https://ekuatia.set.gov.py/en/web/portal-institucional/w/resolucion-general-n-05-18-1
- FAQ e-Kuatia (DNIT), categoría KuDE — https://www.dnit.gov.py/web/e-kuatia/preguntas-frecuentes/-/categories/2705546
- Consulta pública de DTE — https://ekuatia.set.gov.py/consultas/
- RG N° 13/19 y su Anexo (incumplimientos formales que configuran contravención, art. 176 Ley 125/91) — https://www.dnit.gov.py/en/web/portal-institucional/w/resolucion-general-n-13-19-anexo
- Decreto N° 6539/2005 (reglamento general de timbrado y uso de comprobantes de venta) y sus modificaciones — origen de la leyenda "No válido para crédito fiscal"
- `context/28-facturacion-electronica-plan.md` (§Portal, §Verificado contra la API real, F2/F6)
