# 40 — Reportes fiscales Paraguay (RG90 — Registro de Comprobantes Marangatu)

> Estado: **PLAN, sin implementar** (2026-08-08). Es la fase **F5** de
> `context/38-impuestos-multi-pais.md`.
>
> **Corrección de rumbo #1 (durante esta sesión)**: la primera versión de
> este plan asumía "RG90/Libro Ventas/Libro Compras" como 3 reportes de
> lectura con export XLSX. El owner aportó la especificación técnica OFICIAL
> de la SET (`Especificación Técnica para Importación — Registro de
> Comprobantes de Ventas, Compras, Ingresos y/o Egresos`, JUNIO 2021) y la
> planilla modelo RG-90. Con eso, el entregable real es **un generador de
> archivo de importación** para el Sistema Marangatu — no un reporte para
> mirar en pantalla.
>
> **Corrección de rumbo #2**: el owner después consiguió un archivo REAL
> generado por un contribuyente en producción (`805647_REG_082026_20183.csv`,
> 49 filas de ventas de agosto 2026) y decisiones cerradas. El archivo real
> **corrige el PDF de 2021 en varios puntos** (verificado byte a byte por
> esta sesión, no solo de oídas — ver §2). Regla aplicada en todo este doc:
> **el archivo real de producción manda sobre el PDF cuando difieren.**
>
> **Corrección a `context/38`**: ese doc lista F5 como dependiente de F4
> (`rollup_tax`, agregado día×tasa). Es incorrecto — el archivo de Marangatu
> es **un renglón por comprobante**, no un agregado diario; un rollup por día
> destruye exactamente el dato que hace falta (número de comprobante,
> timbrado, RUC de la contraparte). La dependencia real es **F2 completo en
> COMPRAS** (hoy solo existe en ventas) — ver §3.
>
> Verificado: el docblock de `PurchasesService.php:20-21` que dice "los
> reportes fiscales siguen en panel legacy" está desactualizado — `panel/`
> no existe en este worktree. **Alcance final cerrado por el owner: VENTAS y
> COMPRAS, solo documentos con timbrado y número fiscal, excluyendo lo ya
> emitido por facturación electrónica. INGRESOS/EGRESOS quedan fuera.**

## 1. Diagnóstico

Todo verificado directamente contra este worktree (no contra la copia
principal del repo — difieren en varios commits recientes; ver nota al pie
de la tabla).

| Campo necesario | Estado | Evidencia |
|---|---|---|
| Tipo de transacción (venta/compra/NC/etc.) | **Existe, completo** — enum `SaleType` (`api/lib/Sales/SaleType.php:14-28`): `0`=Cashsale, `1`=CashPurchase, `2`=Saved, `3`=Creditsale, `4`=CreditPurchase, `5`=CreditPayment, `6`=Return, `7`=Canceled, `8`=Recurring, `9`=Quote, `10`=Delivery, `11`=OpenTable, `12`=Order, `13`=Schedule, `14`=PurchaseCreditNote (mig `122_purchase_credit_note.sql`) |
| Anulado | El header de `db-schema-postgres.sql` documenta "6=other" para `transactionStatus`, desactualizado — el código real usa `transactionStatus=6` como "Cancelado"/anulado consistentemente (`api/lib/Purchases/PurchasesService.php:560,566,611`, `PurchaseCreditNoteService.php:340,393`) |
| Condición contado/crédito | Se deriva de `transactionType` (0/1=contado, 3/4=crédito), no de `transactionPaymentType` (JSON de medios de pago) — `SaleService.php:605` |
| **¿Una venta tiene numeración fiscal (timbrado+número)?** | **Se determina hoy en `registerInfo()`, no en la transacción.** `TransactionsService.php:114-129`: `invoiceAuth`/`invoicePrefix` salen de `register.data` JSONB de LA CAJA (no de la transacción); si están vacíos, la venta no tiene timbrado. Hay además un tag especial (`166227`) que fuerza a una transacción puntual a NO tener numeración aunque su caja sí tenga timbrado configurado (`TransactionsService.php:126-129`: `if (in_array('166227', $tagsArr)) { $invoicePrefix=''; $invoiceAuth=''; ... }`). Mismo criterio reusado en `TransactionDetailService.php:78-92`. **Esta es la señal que el generador debe usar para filtrar "solo documentos con timbrado"** (decisión cerrada del owner, §6) |
| N° de comprobante estructurado (EEE-PPP-NNNNNNN) | **No persiste por transacción.** `transaction.invoicePrefix` queda VACÍO para ventas normales — `SaleService::buildTransactionRecord()` no lo incluye en el INSERT. El EEE-PPP se reconstruye en el momento de leer desde `register.data` (ver fila anterior). Consecuencia: si el timbrado de una caja cambió después de una venta vieja, el reporte de esa venta muestra el timbrado ACTUAL, no el vigente al vender — mismo patrón de riesgo que "tasa vigente vs congelada" en EInvoice (`context/28`). Ver Decisión abierta #2 — **desactualizado desde 2026-08-18**: la mig 145 congela el timbrado en la transacción (`invoiceauth`/`invoiceauthstart`/`invoiceauthexpiration`); el reconstruido desde `register.data` queda solo como fallback para filas previas a esa migración |
| IVA por línea, congelado — **VENTAS** | **Existe desde F2a** — `groupTaxByRate()` agrupa por tasa sobre las líneas ya congeladas y persiste `[{taxId,rate,kind,base,amount}]` en la tabla `toTaxObj` (`SaleService.php:1995-2044`, INSERT en `SaleService.php:671-675`) |
| IVA por línea, congelado — **COMPRAS** | **No existe agregado por tasa.** Grep de `toTaxObj` en `api/lib/Purchases/` (incluye `PurchaseCreditNoteService.php`) es vacío. Solo hay `itemSold.taxId`/`itemSoldTax` por línea, sin agregación |
| `toTaxObjText` — límite de tamaño | **Bug conocido, bloqueante para F5**: `VARCHAR(255)` (`db-schema-postgres.sql:532`, en la raíz del repo). Con ~6+ tasas el JSON excede 255 chars → Postgres aborta por truncación (22001) y la venta entera falla (`SaleAbortedException`, comentado en `SaleService.php:664-670`) |
| RUC / razón social contraparte | `contact.contactTIN` (RUC), `contact.contactCI` (cédula) — confirmados (`db-schema-postgres.sql:219-220`). No existe columna de "tipo de documento" (pasaporte/cédula extranjera/diplomático) ni de "innominado" en `contact` |
| Timbrado por caja | Vive en `register.data` JSONB, leído en `EInvoiceProvisioningService::registerStamps()` (query en línea 458-460) |
| `einvoice_document` (CDC, número fiscal real, estado SIFEN) | `cdc`/`doctype` nacen en mig `92_einvoice.sql:63-83`; `document_number`/`sifen_status`/`sifen_result` se agregan en mig `95_einvoice_factomate.sql` (ALTER TABLE, líneas 93/96/99) |
| **Criterio "SIFEN ya aceptó este documento"** | `sifen_status NOT IN ('Aprobado','Rechazado')` es la comparación real (`EInvoiceService.php:830`), con comentario explícito de que `sifen_status='Aprobado'` es el ÚNICO campo que prueba validez fiscal. Lo único expuesto hoy a reportes es el `status` del OUTBOX (`TransactionsService.php:499-524`), no `sifen_status`. **Falta agregar este filtro** — ver §2.1.1 |
| Timbrado/N° del documento ORIGINAL desde una Nota de Crédito | **Resuelto, patrón reusable**: `TransactionLinkService::listOriginIds($companyId, $derivedId, $kind)` (`api/lib/services/TransactionLinkService.php:82`) ya se usa en `FinanceLedger.php:210` (kind=`'return'`, tipo 6) y `:261` (kind=`'purchase_credit_note'`, tipo 14) |
| Régimen tributario del contribuyente (IVA General / IRE / IRP-RSP) | No modelado — pero **el owner cerró que no hace falta modelarlo como config persistente** (§6, decisión cerrada #2): se elige en el momento de generar el archivo |
| Rollup con dimensión por tasa (F4 de context/38) | No existe todavía (mig `41`/`42`: `tax` es un total único, sin tasa). Confirmado que no es lo que bloquea a F5 |
| Notas de crédito ↔ documento origen | Mismo mecanismo para venta y compra — `transaction_link` con `kind='return'` (mig `115_transaction_link.sql:32,95`) y `kind='purchase_credit_note'` (mig `122_purchase_credit_note.sql:41-46`) |
| Finanzas — nómina / extractos bancarios | No existe ninguna fuente de datos (`api/lib/Finance/`). Confirma la decisión cerrada de dejar INGRESOS/EGRESOS fuera (§6) |
| Migraciones | 121 archivos en `api/database/migrations/postgres/`, el más alto es `123_transaction_link_amount.sql` |

> **Nota sobre la fuente de verificación**: este worktree tiene commits que
> la copia principal del repo (usada al inicio de esta sesión) no tenía —
> incluye un fix de formato de `docNo` y una feature de detalle de
> transacción (`context/39`, tema distinto, ya ocupa ese número — por eso
> este doc es el 40, no el 39 pedido originalmente). Todas las citas están
> re-verificadas contra el worktree.

## 2. Qué exige la SET — Especificación Técnica + archivo real de producción

**Fuentes**: (a) `Especificación Técnica para Importación — Registro de
Comprobantes de Ventas, Compras, Ingresos y/o Egresos` (SET Tributación,
JUNIO 2021, v1) — PDF provisto por el owner, verificado página por página;
(b) `Modelo-planilla-en-blanco-RG-90.xlsx` (mismas columnas); (c)
**`805647_REG_082026_20183.csv`, un archivo REAL de un contribuyente en
producción (agosto 2026), verificado byte a byte por esta sesión** — es la
fuente de mayor confianza porque prueba lo que Marangatu efectivamente
acepta hoy, más allá de lo que dice un PDF de 2021.

### 2.0 Qué es, en una frase

**No es un libro para mirar ni una planilla para imprimir**: es la
especificación de un **archivo de importación** que se sube al Sistema
Marangatu para cumplir la obligación 955 (Registro Mensual) o 956 (Registro
Anual). "RG90" es el nombre de la resolución — no hay tres artefactos
distintos: hay **un formato de archivo con hasta 4 tipos de registro**
(VENTAS / COMPRAS / INGRESOS / EGRESOS). A Punto le interesan 2 (§2.5,
decisión cerrada).

### 2.1 Formato del archivo — verificado contra el archivo real, no solo el PDF

- **Delimitador: TABULACIÓN, no coma ni punto y coma** — el PDF dice
  "CSV delimitado por comas" y su propio anexo de ejemplo usa `;`; el
  archivo real de producción resuelve la contradicción: es **tab-delimited**
  pese a llevar extensión `.csv` (verificado con `awk -F'\t'` → 20 columnas
  consistentes; `awk -F','` → 1 columna). **Terminadores de línea CRLF**
  (verificado byte a byte, 49/49 líneas). Al implementar el generador: usar
  TAB como separador y CRLF como terminador — no seguir el texto del PDF acá.
- **Extensión `.csv` con contenido tab-delimited** es lo real, aunque
  Marangatu también acepta `.txt`. No asumir que la extensión implica el
  delimitador.
- Comprimido en **ZIP** con el mismo nombre del archivo contenido.
- **Nombre normado**, confirmado con un caso real:
  `805647_REG_082026_20183.csv` → RUC sin DV `805647`, período `082026`
  (`MMAAAA` = agosto 2026), identificador propio `20183`. Formato:
  `<RUC>_REG_MMAAAA_XXXXX` (mensual, obl. 955) / `<RUC>_REG_AAAA_XXXXX`
  (anual, obl. 956).
- **Máximo 5.000 filas por archivo** (PDF) — requisito de diseño: un
  comercio de alto volumen necesita batching por período.
- **Encabezado**: el archivo REAL de producción **SÍ trae una fila de
  encabezado** (`CODIGO TIPO DE REGISTRO`, `CODIGO TIPO DE IDENTIFICACION
  DEL COMPRADOR`, ... — sin acentos, a diferencia del XLSX modelo que sí los
  lleva), aunque el PDF dice que el archivo final NO debe llevarlo.
  Interpretación: hay dos estados — un archivo de trabajo/revisión CON
  encabezado, y el que efectivamente se sube a Marangatu SIN él. El
  generador de Punto debe producir ambos casos (preview con encabezado,
  descarga final sin él) o al menos documentar cuál es cuál.
- **Montos: enteros, SIN decimales, con punto como separador de miles** —
  confirmado con datos reales: `50.000` en el archivo significa **50.000
  guaraníes** (no 50,000 ni 50.00). Es el mismo patrón que un monto en
  guaraníes sin decimales en el resto del sistema, pero el separador de
  miles con punto es fácil de confundir con un decimal al implementar —
  advertencia explícita para quien codee el generador: **no tratar el `.`
  como separador decimal.**
- Fechas `dd/mm/aaaa`; no antes de `01/01/2021` salvo condición Crédito.
- RUC en cualquier campo: sin dígito verificador.
- Un comprobante debe imputarse a al menos una obligación vigente (IVA
  General, IRE, o IRP-RSP) a la fecha de emisión.

### 2.1.1 Universo de documentos que entran al archivo (decisión cerrada del owner)

Dos filtros, ambos obligatorios:

1. **Solo documentos CON timbrado y número de comprobante fiscal.** Una
   venta sin numeración fiscal (ticket interno, comanda, etc.) NO se declara
   en este archivo — el owner cerró que "lo que no lleva factura no va al
   RG90". En el archivo real de referencia, el 100% de las 49 filas son tipo
   `109` (FACTURA); no hay ningún `103`/`112`. **Esto elimina la necesidad de
   mapear un código SET para ventas sin factura** (ya no hace falta decidir
   entre 103 Boleta de Venta / 112 Ticket Máquina Registradora — esas ventas
   directamente quedan afuera). El criterio de "tiene timbrado" en código es
   el de la fila de diagnóstico §1: `invoiceAuth`/`invoicePrefix` no vacíos
   en `registerInfo()`, sin el tag `166227` de exclusión explícita.
2. **Excluir lo ya emitido por facturación electrónica**:
   > *"Este archivo no deberá incluir comprobantes emitidos a través del
   > Sistema de Facturación Electrónica Nacional (E-kuatia) ni del Sistema
   > de Comprobantes Virtuales, los cuales el contribuyente obtendrá
   > directamente del Sistema Marangatu."* — Consideraciones Generales, pág.
   > 5 del PDF.
   El único campo que prueba esa aceptación es `einvoice_document.sifen_status
   = 'Aprobado'` (§1). Hoy no existe ningún filtro que exponga eso a un
   reporte — hay que agregarlo (F5.1, §5).

Casos borde:

- Venta con timbrado, pero documento electrónico en estado
  `sending`/`error`/`pending` del outbox (nunca llegó a SIFEN o fue
  rechazado): **sí** entra al archivo — no fue tomada por E-kuatia.
- Venta con `sifen_status = 'Pendiente'` (SIFEN todavía no resolvió) al
  generar el archivo del mes: zona gris, ver Decisión abierta #3.
- **Compras**: en teoría la misma restricción aplicaría si el PROVEEDOR
  emitió electrónicamente — pero Punto no captura el CDC de facturas de
  terceros al registrar una compra. Ver Decisión abierta #1.

### 2.2 REGISTRO DE VENTAS — 20 campos (corregido contra el archivo real)

El PDF de 2021 (v1) lista 19 campos sin `NO IMPUTA` para Ventas. **El
archivo real de producción trae 20 campos, con `NO IMPUTA` en la posición
18** — igual que Compras. Se prioriza el archivo real (refleja el formato
vigente que Marangatu acepta hoy; el PDF quedó desactualizado en este
punto, sin que exista una v2 documentada).

| # | Campo | Tipo | Long. | Notas | Ejemplo real |
|---|---|---|---|---|---|
| 1 | Código tipo de registro | NUM | 1 | Siempre `1` | `1` |
| 2 | Código tipo identificación del comprador | NUM | 2 | Tabla 3 | `11` (RUC) / `15` (Sin Nombre) |
| 3 | Número de identificación del comprador | ALF | 20 | Para tipo 15, el archivo real usa el literal `X`, no vacío | `3209038` / `X` |
| 4 | Nombre o razón social del comprador | ALF | 250 | El PDF dice "no requerido" para 11/12/15 — el archivo real **igual lo completa siempre** (para 15, literal `SIN NOMBRE`); el generador debe replicar ese comportamiento, no omitirlo | `GALEANO GRASSI, JUAN DOMINGO` / `SIN NOMBRE` |
| 5 | Código tipo de comprobante | NUM | 3 | Tabla 4 — en el archivo real, **siempre `109`** (Factura), consistente con el filtro §2.1.1 | `109` |
| 6 | Fecha de emisión | ALF | 10 | `dd/mm/aaaa` | `06/08/2026` |
| 7 | Número de timbrado | NUM | 8 | — | `17665105` |
| 8 | Número del comprobante | ALF | 20 | Formato `###-###-#######` | `001-002-0003728` |
| 9 | Monto gravado al 10% (IVA incluido) | NUM | 20 | Entero, sin decimales, punto de miles | `50.000` (= Gs. 50.000) |
| 10 | Monto gravado al 5% (IVA incluido) | NUM | 20 | Igual formato | `0` |
| 11 | Monto no gravado o exento | NUM | 20 | Igual formato | `0` |
| 12 | Monto total del comprobante | NUM | 20 | Suma exacta de 9+10+11 | `50.000` |
| 13 | Código condición de venta | NUM | 1 | Tabla 2 — el archivo real mezcla `1` (Contado) y `2` (Crédito) en filas reales | `1` / `2` |
| 14 | Operación en moneda extranjera | ALF | 1 | S/N | `N` |
| 15 | Imputa al IVA | ALF | 1 | S/N | `S` |
| 16 | Imputa al IRE | ALF | 1 | S/N | `N` |
| 17 | Imputa al IRP-RSP | ALF | 1 | S/N | `N` |
| 18 | **No imputa** | ALF | 1 | **No estaba en el PDF v1 para Ventas — confirmado por el archivo real** | `N` |
| 19 | N° del comprobante de venta asociado | ALF | 20 | Solo NC/ND (110/111) | vacío |
| 20 | Timbrado del comprobante de venta asociado | NUM | 8 | Solo NC/ND | vacío |

### 2.3 REGISTRO DE COMPRAS — 20 campos (el PDF ya los tenía bien acá)

Mismo orden que Ventas §2.2 con estas diferencias: campo 1 = `2`; campos 2-4
son del **Proveedor/Vendedor** (RUC obligatorio salvo tipos 101 Autofactura
y 107 Despacho de Importación); campo 7 (timbrado) = `0` para tipo 107;
campo 13 = **Código condición de compra**; campo 18 = **No imputa** (si "S",
el comprobante igual debe estar imputado adicionalmente a otra obligación —
esto ya estaba correcto en el PDF de 2021 para Compras, la corrección de
§2.2 solo aplicaba a Ventas). Para tipos 101, 104, 105 y 112 los montos
gravados/exento van en 0 y solo se completa el Monto Total.

**No se pudo validar Compras contra un archivo real en esta sesión** (el
archivo aportado por el owner solo tiene Ventas) — se mantiene la
especificación del PDF para esta sección, marcada como no confirmada contra
producción. Recomendación: pedir un archivo de ejemplo de Compras antes de
implementar F5.4 (§5), por si el PDF también quedó desactualizado ahí.

### 2.4 INGRESOS y EGRESOS — CERRADO fuera de alcance (decisión del owner)

Existen en la especificación (Liquidación de Salario, Extracto IPS/TC-TD,
Transferencias bancarias) pero **son registros del IRP de la persona física
que el contribuyente carga a mano en Marangatu** (decisión del owner,
explícita). Punto no modela nómina ni extractos bancarios (`api/lib/Finance/`
confirmado sin esas fuentes) — coincide con la decisión de negocio, no solo
con una limitación técnica. **No reabrir sin pedido explícito nuevo.**

### 2.5 Tablas de código (del PDF, completas)

**Tabla 3 — Identificación**: `11` RUC · `12` Cédula de Identidad · `13`
Pasaporte · `14` Cédula Extranjero · `15` Sin Nombre · `16` Diplomático ·
`17` Identificación Tributaria.

**Tabla 4 — Tipos de comprobante** (relevantes para Punto): `101` Autofactura
(compras) · `102` Boleta transporte público (ambos) · `103` Boleta de Venta
(ambos) · `104` Boleta Resimple (compras) · `105` Boletos lotería/juegos
(ambos) · `106` Boleto/Ticket transporte aéreo (ambos) · `107` Despacho de
Importación (compras) · `108` Entrada a espectáculos (ambos) · `109` Factura
(ambos) · `110` Nota de Crédito (ambos) · `111` Nota de Débito (ambos) ·
`112` Ticket Máquina Registradora (ambos) — nota: dado el filtro de §2.1.1,
Punto nunca genera `103`/`112` en la práctica, aunque la tabla los permita.

**Tabla 2 — Condición**: `1` Contado · `2` Crédito. **Tabla 5**: `S`/`N`.

### 2.6 Patrón de mapeo comprador — consumidor final (verificado en el archivo real)

El archivo real confirma el mapeo exacto para el "Consumidor final" de
Punto: cuando la venta no tiene cliente identificado, el comprobante SET
lleva tipo identificación `15` (SIN NOMBRE), número de identificación
literal `X`, y nombre literal `SIN NOMBRE`. Es el patrón directo a implementar
en el generador para cualquier venta sin `customerId` (o con el contacto
genérico de consumidor final, si Punto usa uno). Cuando sí hay cliente
(`11` RUC o `12` Cédula), el archivo real completa el nombre igual, aunque
el PDF diga que no es requerido para esos tipos — replicar ese
comportamiento observado, no la letra estricta del PDF.

## 3. Modelo de datos

### 3.1 Por qué NO hace falta el rollup (F4) para esto

El archivo de Marangatu es una fila por comprobante, con su timbrado y
número propios — un agregado diario (`rollup_tax`, F4 de `context/38`)
destruye exactamente esos datos. El campo "Monto Gravado al 10% (IVA
incluido)" coincide 1:1 con lo que `toTaxObj` ya calcula y congela por
transacción — una vez que existe para compras (§3.2), no hace falta ninguna
tabla de agregación nueva.

### 3.2 Qué falta para que un SELECT alcance

1. **Compras necesitan el mismo congelado por tasa que ventas** (extender
   `groupTaxByRate`/`toTaxObj` a `PurchasesService`/`PurchaseCreditNoteService`).
   Continuación directa de F2 de `context/38`, no trabajo nuevo inventado acá.
2. **`toTaxObjText` deja de ser `VARCHAR(255)`** — ampliar a `TEXT`.
3. **Corregir el signo de `toTaxObj` en devoluciones** (hoy positivo, cuando
   `itemSoldTax`/`transactionTax` van negativos) antes de sumarlo en un
   período.
4. **Exponer `sifen_status='Aprobado'` como filtro de exclusión** — hoy no
   existe en ningún service de reportes.
5. **Filtro "tiene numeración fiscal"** (§2.1.1, decisión cerrada): reusar
   exactamente `registerInfo()` (`TransactionsService.php:114-129`) —
   `invoiceAuth`/`invoicePrefix` no vacíos y sin el tag de exclusión
   `166227`. Es lectura de código ya existente, no una columna nueva.
6. **Timbrado no congelado por transacción** (hallazgo §1): se reconstruye
   desde `register.data` VIGENTE, no desde el que regía al momento de la
   venta. Para el archivo de un mes recién cerrado no debería importar, pero
   para regenerar un período viejo tras un cambio de timbrado sí puede
   declarar el número equivocado. Ver Decisión abierta #2 — no se resuelve
   inventando una congelación nueva sin decisión del owner (mismo criterio
   de riesgo aceptado ya en EInvoice, `context/28`, para la tasa de
   impuesto vigente al facturar ventas viejas).

Con los puntos 1-5 resueltos, el generador es un `SELECT` sobre `transaction`
(filtrado por tipo/fecha/estado/numeración fiscal) `JOIN toTaxObj`
(desglose por tasa) `JOIN contact` (RUC/razón social) `LEFT JOIN
einvoice_document` (excluir aprobados) `JOIN register` (timbrado, con la
salvedad del punto 6) — mismo patrón de service que
`TransactionsService.php`/`PurchasesService.php` ya usan. **No hace falta
tabla nueva** para el volumen esperado.

## 4. Dónde vive en la UI

Un generador de archivo por período, no 3 páginas de solo-lectura.

- **Sección nueva** `/reports/fiscal` (convención plana existente,
  `frontend/app/(panel)/reports/<nombre>/page.tsx`) con **una** página:
  "Registro de Comprobantes (RG90)". VENTAS y COMPRAS van en el mismo flujo
  de generación (pueden ir en el mismo archivo, §2.1).
- **Filtros**: período (mes o año según obligación 955/956, obligatorio). No
  hay campo de sucursal en la especificación — el archivo se presenta
  consolidado por RUC del contribuyente completo (Decisión abierta #4 sobre
  si la preview admite filtrar por outlet).
- **Selectores de imputación** (`Imputa IVA` / `Imputa IRE` / `Imputa
  IRP-RSP`), **elegidos en el momento de generar, sin persistir** — decisión
  cerrada del owner: no es config de tenant en Ajustes, es un input de la
  pantalla de generación que se aplica a todas las filas de esa corrida
  (en el archivo real: IVA=`S`, IRE=`N`, IRP-RSP=`N`, No Imputa=`N` en
  el 100% de las filas).
- **Preview**: `<DataTable>` (patrón existente) con dos tabs Ventas/Compras,
  para revisar antes de descargar.
- **Acción principal**: botón "Descargar archivo para Marangatu" que arma el
  archivo tab-delimited con CRLF (§2.1), sin encabezado, lo zipea con el
  nombre normado y lo entrega. Batching automático si el período supera
  5.000 filas.

## 5. Fases

| Fase | Contenido | Depende de |
|---|---|---|
| **F5.0** | Fix de datos: `toTaxObjText` a `TEXT`; corregir signo en devoluciones; extender `groupTaxByRate`/`toTaxObj` a compras y NC de compra | F2 de context/38 (cerrado en ventas, se completa acá para compras) |
| **F5.1** | Exponer `sifen_status='Aprobado'` como filtro reusable de exclusión | Nada nuevo — dato ya existe (mig 95) |
| **F5.2** | Generador de archivo VENTAS: service (filtro numeración fiscal + exclusión e-invoice + selectores de imputación en tiempo de generación) + página de preview + descarga ZIP (tab-delimited, CRLF, sin encabezado, batching 5.000) | F5.0 + F5.1 |
| **F5.3** | Generador de archivo COMPRAS: mismo patrón, incluye NC de compra (tipo 14). **Validar contra un archivo real de Compras antes de dar por buena la spec del PDF** (§2.3) | F5.0 |
| **F5.4** (descartado, no reabrir) | INGRESOS/EGRESOS | Decisión cerrada del owner — fuera de alcance |

F5.0 es el corte delicado: toca `PurchasesService`/`PurchaseCreditNoteService`
en producción, mismo tipo de riesgo que F2 de `context/38` cuando tocó
`SaleService`. F5.1 es aditivo, bajo riesgo. F5.2/F5.3 son de solo lectura
sobre datos ya congelados.

## 6. Decisiones cerradas por el owner (no reabrir)

1. **Solo documentos con timbrado y número van al archivo.** Ventas sin
   numeración fiscal quedan afuera — elimina la pregunta de código SET para
   tickets sin factura (103/112 no aplican en la práctica).
2. **El régimen tributario (Imputa IVA/IRE/IRP-RSP) se elige al generar**,
   no se configura por tenant — 3 selectores en la pantalla de generación,
   aplicados a todas las filas de esa corrida.
3. **INGRESOS y EGRESOS quedan fuera de alcance** — son registros del IRP de
   la persona física que el contribuyente carga a mano en Marangatu; Punto
   no modela nómina ni extractos bancarios.
4. **Delimitador: TAB, no coma ni `;`** — resuelto contra el archivo real de
   producción, no por interpretación del PDF.

## 7. Decisiones abiertas para el owner

> Cerradas por el owner 2026-08-08 (las 1-4 originales). Se dejan acá con su
> respuesta en vez de borrarlas: el razonamiento de por qué NO se validan
> ciertas cosas es tan parte del diseño como lo que sí se hace.
>
> 1. **Compras ya declaradas por el proveedor** → **no las validamos**. La
>    deduplicación es responsabilidad del contador. NO se agrega campo de CDC
>    al registrar compras ni lógica de dedup.
> 2. **Timbrado** → sigue configurándose por caja, como hoy. Pero se agrega un
>    requisito NUEVO, que excede este plan: el timbrado tiene fecha de
>    vencimiento y **no se debe poder facturar con el timbrado vencido**. Es
>    una validación en la emisión, no en el reporte — implementada aparte.
>
>    ⚠ **ACTUALIZACIÓN 2026-08-18 — el riesgo de esta decisión ya no existe.**
>    El timbrado ahora SÍ se congela por transacción: la mig 145 agregó
>    `transaction.invoiceauth` / `invoiceauthstart` / `invoiceauthexpiration`,
>    que `SaleService::save()` puebla al emitir (`resolveFrozenInvoiceAuth()`,
>    mismo patrón con el que ya se congela el impuesto por línea). Regenerar un
>    período viejo después de un cambio de timbrado **ya no declara el número
>    equivocado**: el reporte debe leer el timbrado CONGELADO en la
>    transacción, no reconstruirlo desde `register.data`. Las filas anteriores
>    a la migración quedan con esas columnas en NULL (no hubo backfill, por
>    decisión del owner de no tocar datos retroactivamente), así que el
>    generador necesita un fallback al comportamiento viejo para el histórico.
>    Ese mismo timbrado congelado participa del índice único
>    `uq_transaction_expedition_invoiceno` (mig 145), que hace cumplir en la
>    base que `punto de expedición + timbrado + correlativo` no se repita.
> 3. **Documentos en `sifen_status='Pendiente'`** → **no los validamos**,
>    problema del contador. El generador no intenta adivinar el estado final.
> 4. **Filtro por sucursal** → sí, se puede filtrar por sucursal. Ojo: el
>    archivo final igual consolida por RUC del contribuyente (el RG90 es por
>    contribuyente, no por establecimiento); el filtro aplica a la vista
>    previa. Si se quisiera partir el archivo por sucursal habría que
>    revisarlo con el contador, porque sería una declaración incompleta.

> 5. **Tipo de comprobante en compras** → el comercio solo opera con
>    facturas. Se asume `109` (FACTURA) fijo, sin campo nuevo al registrar la
>    compra. Límite conocido y aceptado: si alguna vez se registra una
>    autofactura (`101`), una boleta (`103`/`104`) o un despacho de
>    importación (`107`), el archivo la declararía como factura. Reabrir solo
>    si aparece ese caso.

> 6. **Identificaciones no paraguayas** → **sí se implementan**, pero como
>    feature propia, no como parte de este reporte: el cajero tiene que poder
>    cargar un cliente extranjero (pasaporte, cédula extranjera, diplomático,
>    identificación tributaria — códigos 13/14/16/17 de la Tabla 3), y el tipo
>    de identificación tiene que estar hilado con facturación electrónica, no
>    solo con el RG90. Es configuración EXCLUSIVA de Paraguay: se carga y se
>    habilita solo si el país del tenant es PY. Este plan la consume como dato
>    ya existente.

Ninguna decisión sigue abierta. El alcance quedó cerrado.

Notas de implementación derivadas de las respuestas:
6. **Identificaciones fuera de RUC/Cédula paraguaya** (Pasaporte, Cédula
   Extranjero, Diplomático — códigos 13/14/16 de la Tabla 3): `contact` no
   distingue estos tipos hoy. Propuesta: fuera de alcance de v1 (se asume
   contraparte local), reabrir si un tenant lo necesita.
