# Cotización en PDF — el documento que el comercio le manda a su cliente

> **Estado:** plan cerrado 2026-08-28 (D1 decidida por el owner; D2–D7 cerradas
> con fundamento abajo). Sin implementar.

## 1. El pedido

Que al crear una cotización se genere un PDF con buen diseño, tipo "Quotation",
que el comercio pueda mandarle a su cliente (owner, 2026-08-28).

Hoy la cotización se puede IMPRIMIR (docType `quote` del document builder, o el
ticket de fallback) y se puede ver en pantalla
(`components/domain/transactions/quote-print-view.tsx`), pero **no existe un
archivo** que el comercio pueda adjuntar a un mail o a un WhatsApp.

## 2. Por qué no sale del document builder (D1 — decisión del owner)

El motor de HOJA del builder pinta bloques en **posición absoluta en
milímetros** (`renderSheetBody` en `lib/hardware/printers/html-renderer.ts`): lo
que se ve en el canvas del editor es exactamente lo que sale en el papel. Eso es
correcto para lo que ese motor existe —el ticket térmico y los formularios
fiscales preimpresos, donde cada dato va en la casilla que la imprenta dejó— y es
justo lo que NO sirve acá: **no fluye y no pagina**. Una cotización de 40 ítems
se corta al pie de la hoja y los totales quedan afuera, sin aviso.

En prod hay hoy exactamente UNA plantilla `quote` (tamaño carta, armada a mano
por un tenant), así que tampoco hay una base instalada que preservar.

**Decisión (owner, 2026-08-28):** la cotización es un **documento propio con
diseño fijo**, en un layout que fluye y pagina solo. El tenant configura su
marca y sus textos —no posiciones—: logo, datos, validez, condiciones, nota.

Esto NO contradice la regla "lo que se imprime lo decide la plantilla"
(context/20): esa regla gobierna el TICKET, donde el comercio elige qué bloques
salen. La cotización comercial no es un comprobante fiscal ni un formulario
preimpreso; es una pieza de comunicación con estructura fija.

## 3. Decisiones

### D2 — Se renderiza con `@react-pdf/renderer`, no con Chromium ni con HTML→PDF

- **`@react-pdf/renderer`** (elegida): corre en un route handler de Next, sin
  binarios extra. Pagina de verdad (`break`, `fixed` para encabezado/pie
  repetidos, `render={({pageNumber, totalPages}) => …}` para "Página 1 de 3") y
  produce **texto seleccionable**, que es lo que espera quien recibe un
  presupuesto. Costo: su propio subconjunto de estilos — no es Tailwind ni
  shadcn, el layout se escribe aparte.
- **Chromium headless (puppeteer)** — fidelidad total con el HTML que ya
  sabemos escribir, pero mete ~300 MB de navegador en la imagen y un proceso
  más que puede colgarse. Se reevalúa solo si el diseño necesita algo que
  react-pdf no da.
- **dompdf/mpdf (PHP)** — sin Chromium, pero soporte de CSS pobre (nada de
  flex/grid): obliga a maquetar con tablas, que es exactamente el diseño que
  el owner NO quiere.
- **jsPDF/html2canvas en el browser** — rasteriza: texto no seleccionable, se
  ve borroso al imprimir. Descartada.

### D3 — Se genera bajo demanda y se cachea, no en cada alta

El pedido dice "al crear la cotización". Generarlo ahí, siempre, gasta trabajo
en cotizaciones que nadie manda — y, peor, deja un archivo desactualizado apenas
la cotización se edita.

Se genera la primera vez que alguien lo pide (descargar/enviar/ver) y se guarda
en S3 (`Punto\Api\Storage\S3Client`, el mismo que ya usa
`PurchaseDraftService`), con la **versión del documento** en la clave. Si la
cotización cambia, la clave cambia y el PDF se regenera. Nunca se sirve un PDF
que no corresponde a lo que dice la cotización hoy.

Desde la UI es indistinguible de "se generó al crearla": el botón está siempre y
el primer click tarda un segundo más.

### D4 — Offline: la cotización se crea sin red, el PDF no

El POS es offline-first para lo que EMITE (`project_offline_scope`), y una
cotización se puede armar sin internet. El PDF, en cambio, necesita el server:
se genera cuando la cotización sincroniza. El botón de PDF, sin red, se
deshabilita con su motivo — nunca una banda (regla de alertas del POS: el
impedimento se muestra en el control de la acción).

### D5 — Todo dato del documento sale del tenant, nada cableado

Moneda, separadores, formato de fecha, idioma y RUC salen del bootstrap del
tenant (`resolveCurrencyLabel`, `resolveNumberLocale`, `resolveDateLocale` en
`lib/tenant-locale.ts`). Nada de "Gs", `es-PY` ni `America/Asuncion` en el
layout — la regla vale igual acá que en el ticket, y el guard
`no-hardcoded-paraguay` la va a hacer cumplir.

El desglose de IVA sale del impuesto **congelado por línea** (context/38), nunca
del catálogo actual: una cotización vieja tiene que seguir mostrando la tasa con
la que se cotizó.

### D6 — El número de la cotización es el del sistema de numeración

No se inventa un correlativo para el PDF: sale de la numeración de documentos ya
implementada (context/37). Si la cotización no tiene número asignado, el PDF lo
dice como "sin numerar" — no fabrica uno.

### D7 — Qué lleva el documento

Fijo: logo y datos del comercio (razón social, RUC, dirección, teléfono, mail),
"COTIZACIÓN" + número + fecha, datos del cliente, tabla de ítems (cantidad,
descripción, precio unitario, descuento si hay, total de línea), subtotal,
descuento, desglose de IVA, TOTAL, validez, condiciones, nota, y pie con
"Página N de M".

Configurable por el tenant (Ajustes → Cotizaciones): validez por defecto en
días, condiciones comerciales, nota al pie, y si muestra o no el desglose de
impuestos.

Fuera de alcance: firma digital, aceptación online del cliente, y conversión a
venta desde el PDF (eso ya existe en el panel).

## 4. Fases

| Fase | Qué entrega |
|------|-------------|
| **F0** | Ajustes del tenant: validez por defecto, condiciones, nota, toggle de desglose. |
| **F1** | Layout `<QuotationPdf>` + route handler que devuelve el PDF de una cotización. Descarga desde el panel y desde el POS. |
| **F2** | Cache en S3 con versión del documento (D3) + invalidación al editar. |
| **F3** | Envío: adjuntar por mail / WhatsApp desde la ficha de la cotización. Depende de que exista un canal de envío de documentos — hoy Evolution solo se usa para OTP. |

## 5. Trampas conocidas

- **`@react-pdf/renderer` no usa Tailwind.** El layout se escribe con su propio
  sistema de estilos; no intentar compartir componentes con la UI.
- **Fuentes.** El default de react-pdf (Helvetica) no trae `ñ`/tildes en todos
  los casos y no es la tipografía de la marca: hay que registrar la fuente del
  manual de marca (context/11) como archivo, y eso pesa en el bundle del server.
- **Listas largas.** El caso de prueba no es una cotización de 3 ítems: hay que
  probar con 60 y con descripciones de dos líneas, que es donde el encabezado
  repetido y el "Página N de M" se rompen.
- **Un ítem con nombre larguísimo** no puede empujar la columna de importes
  fuera de la hoja — la tabla se define con anchos fijos y el nombre wrapea.
