# KuDE (representación gráfica) y portal del comprador por RUC

> Estado: **investigación + plan. Nada implementado.** D1–D8 son propuestas,
> ninguna cerrada con el owner. Complementa `context/28-facturacion-electronica-plan.md`
> (F6 ya implementó el portal por comprobante), no lo reemplaza.
>
> Marcas: **[V]** verificado contra código o fuente oficial, **[S]** suposición,
> **[?]** hay que preguntar. Fuentes al final.

## La pregunta que originó esto (owner, 2026-08-23)

El ticket impreso no es un KuDE; hay que ver cómo se arma online. Y hace falta
un portal donde el comprador ingrese su RUC y liste sus facturas, con la URL
impresa en el ticket.

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

## 1.2 80 mm: ¿entra?

**[V] Sí, y es el formato recomendado por la propia norma.** MT v150 §13.6
define el "KuDE Formato 2 (cinta de papel)" y lo llama *"el más adecuado para
ventas al consumidor final (supermercados, farmacias, restaurantes, estaciones
de servicio)"*. RG 05/18 art. 11 permite el tamaño de mini impresora POS para
B2C; MT §13.5 admite cualquier formato y tamaño de papel estándar.

**[V]** Existe además el **KuDE resumen** (§13.7): a pedido del consumidor, sin
detalle de ítems ni de impuestos — solo cantidad de ítems y monto total. El
detalle queda en la consulta pública por CDC o QR.

**Conclusión: el ticket de 80 mm se convierte en KuDE; no hace falta un PDF A4
paralelo para el consumidor final.** El PDF de Factomate sirve para el panel y
para el envío digital, no para reemplazar al ticket.

## 1.3 Qué devuelve Factomate

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

## 1.4 Qué le falta HOY al ticket — contra el código, no contra el backlog

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

## 1.5 Decisiones propuestas

**D1 — El ticket 80 mm se convierte en KuDE; no se crea un documento aparte**
(MT §13.6, RG 05/18 art. 11). El PDF de `getkude` queda para el panel y el
envío digital.

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
es sincrónico y devuelve CDC + `DCarQR` en la misma llamada (`context/28`), así
que el ticket puede salir siendo KuDE completo. Si falla o la caja está
offline, cae al outbox actual y el ticket sale **sin** bloques fiscales, solo
con el link del portal — el comportamiento de hoy. **No se espera
`sifen_status`** (validación posterior, MT cap. 6). Costo: latencia en caja (O2).

**D6 — Reimpresión.** Los campos fiscales salen del detalle de transacción
persistido, no de la respuesta de la venta: se enganchan al resolver canónico
de `context/39`. Hoy la reimpresión ni siquiera lleva el QR del portal (hueco
ya documentado en `context/28`).

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

En 80 mm hay un solo QR obligatorio: el fiscal (≥ 25 mm). Una térmica imprime
por líneas, así que dos QR se apilan — el segundo cuesta ~3 cm de papel por
ticket y compite visualmente con el que la SET exige.

**Propuesta: QR fiscal (obligatorio) + URL corta del portal en texto**, no un
segundo QR. Requiere un acortador propio con código de 6–8 caracteres
(`punto.la/f/AB3K9Q`), tipeable a mano. **[V]** El acortador legacy
(`getShortURL()`, `api/includes/functions.php:83`) hace `file_get_contents`
contra un PHP de `/screens`: no se reusa, se hace tabla propia.

Los dos son bloques independientes — el comercio decide, y el QR del portal
sigue disponible para quien prefiera gastar el papel. **[V]** La URL del portal
es información ajena al XML y cabe legalmente en el espacio del campo J003
(MT §13.4.5).

---

# Fases

| Fase | Qué | Esfuerzo | ¿Depende de Factomate? |
|---|---|---|---|
| **K1** | Bloques fiscales nuevos (D3) + plantilla de fábrica KuDE 80 mm | M | No |
| **K2** | Emisión sincrónica online (D5): CDC, `DCarQR`, nº fiscal y timbrado FE en `SaleResult` | M | **Sí** — latencia de `/Bulk` (F2) |
| **K3** | Reimpresión: campos fiscales desde el detalle de transacción (`context/39`) | S | No |
| **K4** | KuDE resumen (§13.7) como variante de plantilla | S | No |
| **P1** | Acortador propio + bloque de URL corta del portal | S | No |
| **P2** | Portal de listado: RUC + OTP, sesión de comprador, rate limit (D7/D8) | L | No |
| **P3** | Envío digital del KuDE (email/WhatsApp) al cerrar la venta | M | Sí (PDF de `getkude`) |

Orden sugerido: K2 → K1 → K3 → P1 → P2 (K1 sin K2 produce una plantilla con
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

## Para el owner (O)

1. **O1 — ¿El listado por RUC es por comercio o cross-tenant?** (D8). Decisión
   estructural de la Parte 2.
2. **O2 — ¿Se acepta agregar 1–3 s a la venta** para que el ticket salga como
   KuDE completo (D5), o se prefiere ticket sin datos fiscales + link?
3. **O3 — Canal del OTP**: SMS, WhatsApp (ya hay Evolution) o email. Tiene
   costo por envío; hay que definir quién lo paga.
4. **O4 — Dominio corto** (`punto.la/f/...`) o `APP_URL` con path corto.
5. **O5 — Si el comercio borra el bloque CDC o el QR fiscal de su plantilla**,
   ¿Punto avisa, bloquea el guardado, o es problema suyo? La decisión del
   2026-07-29 sugiere no bloquear, pero acá falta un requisito de la SET, no un
   disclaimer.

---

# Fuentes

- Manual Técnico SIFEN v150 (DNIT), cap. 6 y cap. 13 — https://www.dnit.gov.py/documents/20123/420592/Manual+T%C3%A9cnico+Versi%C3%B3n+150.pdf
- RG N° 05/2018, art. 1 y art. 11 — https://ekuatia.set.gov.py/en/web/portal-institucional/w/resolucion-general-n-05-18-1
- FAQ e-Kuatia (DNIT), categoría KuDE — https://www.dnit.gov.py/web/e-kuatia/preguntas-frecuentes/-/categories/2705546
- Consulta pública de DTE — https://ekuatia.set.gov.py/consultas/
- `context/28-facturacion-electronica-plan.md` (§Portal, §Verificado contra la API real, F2/F6)
