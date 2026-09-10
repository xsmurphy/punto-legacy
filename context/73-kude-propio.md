# 73 — KuDE propio: Punto renderiza su PDF de factura electrónica

> ## SUPERSEDED — 2026-09-10, por decisión del owner
>
> **El KuDE propio se ELIMINÓ. El PDF vuelve a salir siempre del motor de
> facturación electrónica.** El renderer (`/api/kude/render`, el template
> `@react-pdf`, el contrato del payload, el caché S3 por CDC y
> `INTERNAL_RENDER_KEY`) ya no existe en el repo.
>
> **Por qué se deshizo.** El motivo estratégico de todo este plan era el D5:
> no depender de un TERCERO, ir armando la maquinaria propia "para algún día
> tener sistema de facturación electrónica propio". Ese tercero era Factomate.
> El mismo día en que Factomate quedó fuera y FE-PY —motor del mismo dueño—
> pasó a ser el único, el objetivo ya estaba cumplido: el KuDE lo dibuja
> software propio, solo que del otro lado de la API. Lo único que quedaba del
> renderer era un SEGUNDO dibujo del mismo documento fiscal, con su propia
> forma de divergir del que se firmó.
>
> **Y divergió, en producción.** `KudeService::payload()` se escribió contra el
> payload de Factomate (`electronicDocumentItems`, `client`,
> `operationCondition`, `DCarQR`) y nunca se adaptó a FE-PY. Al habilitarse el
> renderer salió un KuDE VACÍO —sin líneas, totales en cero, sin QR, receptor
> "Sin nombre", "Contado" en una factura a crédito— y le llegó a un cliente
> real. Se revirtió en `47f975ae`. El mapeo NO se arregló: se borró.
>
> **Qué SOBREVIVE de este plan** (y por eso el doc no se borra):
>
> - **D4 — el envío lo hace Punto** (Resend, `context/57`). `KudeEmailBuilder`
>   sigue existiendo; lo único que cambió es que el PDF adjunto sale del motor.
> - **K3 — el archivo del XML firmado a S3.** Es custodia del documento fiscal
>   de verdad y no tiene nada que ver con el render. Vive ahora en
>   `EInvoiceService::archiveSignedXml()`. Se corrigió al pasarlo: leía un
>   `XmlUrl` que solo devolvía Factomate, así que desde el cambio de motor NO
>   ESTABA ARCHIVANDO NADA; ahora baja el XML por CDC.
> - Los predicados de entrega (`kudeDeliveryBlocker`) y todo el flujo de
>   portal / email / POS, que nunca fueron de este plan.
>
> **Lo que reemplaza al D2/D3** (diseño fijo y pie con marca de Punto): el
> diseño del KuDE lo decide el motor. Lo que Punto sí le manda es el LOGO del
> comercio (`EInvoiceService::syncEmitterLogo()` → `logoUrl` del tenant, que se
> sincroniza al subir o borrar el logo y en cada verificación de la cuenta).
>
> **Antes de reabrir esto**, leé el párrafo de arriba: un renderer propio del
> KuDE se justifica el día que el motor deje de ser nuestro, no antes.

Plan 2026-09-07. D1-D5 CERRADAS por el owner (pedido textual de esta fecha);
K1-K5 propuestas. Relacionados: `context/49` (norma del KuDE y qué devuelve
el motor — LEERLO antes de tocar esto), `context/28` (outbox FE),
`context/57` (entrega por email), `context/56` (patrón @react-pdf/renderer).

## Qué pidió el owner (2026-09-07)

Punto genera SU PROPIO PDF de la factura electrónica y descarta el render de
Factomate. Motivos: control total del diseño, marca de agua de marketing en el
pie, y — el estratégico — ir armando la maquinaria propia para algún día tener
sistema de facturación electrónica propio y no depender de un tercero. "Todo
lo que podamos hacer nosotros, lo hacemos."

## Decisiones cerradas (owner)

- **D1 — El PDF lo renderiza Punto.** El de `getkude` deja de ser lo que se
  entrega (portal + email). Queda como FALLBACK mientras dure la paridad.
- **D2 — Diseño FIJO, no personalizable.** Un solo template; el tenant cambia
  CONTENIDO (logo, datos), jamás el diseño. No es el document builder del
  panel y no comparte motor con él — explícito del owner.
- **D3 — Pie con marca de Punto** (marketing). Va en el diseño fijo, todos los
  tenants.
- **D4 — El envío lo hace Punto.** Ya cerrado en `context/57` (Resend) — esta
  decisión lo reafirma: nada de Factomate en el camino al cliente.
- **D5 — Estrategia: acumular piezas propias.** Cada artefacto fiscal que
  Factomate genere y podamos generar/custodiar nosotros, se hace propio o se
  archiva (ver K3, XML firmado). Mismo espíritu que la custodia del P12/CSC
  (mig 195): el traspaso futuro no puede ser caótico.

## Aclaración técnica que acota el "descartamos todo"

El CDC y el contenido del QR **no se descartan ni se inventan**: el CDC es la
identidad del documento (lo asigna la emisión) y el QR del KuDE es el campo
J002 `dCarQR` — URL de consulta e-Kuatia + DigestValue de la firma del XML +
IdCSC + `cHashQR` SHA256 sellado con el CSC (MT §13.8; investigado en
`context/49` §1.1). Lo que se descarta es el RENDER de Factomate. Los datos
fiscales del QR vienen con nosotros:

- `/Bulk` ya devuelve `DCarQR` y `XmlUrl` por documento, persistidos en
  `einvoice_document.provider_response` (`context/49` §1.6, verificado).
- La pregunta F1 de `context/49` sigue abierta: si `DCarQR` viene COMPLETO
  (con cHashQR e IdCSC) se imprime tal cual; si no, Punto lo completa — puede:
  tiene el CSC en custodia (mig 195) y el DigestValue sale del XML de
  `XmlUrl`. En ambos casos el QR es materialmente correcto.

## Fases propuestas (K) — sin OK de detalle todavía

| Fase | Qué |
|---|---|
| **K1 [?]** | Renderer del KuDE: template FIJO A4 con @react-pdf/renderer (patrón `context/56`: bajo demanda + caché S3, keyed por CDC + versión del template). Campos obligatorios del KuDE (§13 del MT, checklist en `context/49` §1.1), QR ≥25 mm desde `dCarQR`, logo del tenant, pie de marketing. Diseño de referencia: archivo del owner (PENDIENTE — pedir el PDF/imagen de la factura modelo). |
| **K2 [?]** | Swap de superficies: `portalKude()` y el `KudeEmailBuilder` sirven el PDF PROPIO; `getkude` queda de fallback si el renderer falla (nunca al revés). El gate fiscal no cambia: solo Aprobado. |
| **K3 [?]** | Archivo del XML firmado: al reconciliar Aprobado, bajar `XmlUrl` y guardarlo en S3 del tenant. Es la pieza más valiosa para la independencia futura Y una obligación de conservación del emisor — hoy vive solo en Factomate. |
| **K4 [?]** | Verificación de paridad: para N documentos reales, comparar campo a campo nuestro KuDE contra el de `getkude` antes de flipear K2 (mismo espíritu que el P0 de `context/34` F7: medir antes de apagar). |
| **K5 [futuro]** | Emisión propia (firmar XML, transmitir a SIFEN). FUERA de este plan — se decide cuando K1-K4 estén maduras. Este doc solo deja las piezas listas. |

## Arquitecturas rechazadas — no reintroducir

- **QR "propio" sin los datos fiscales** (una URL nuestra, o un QR al portal):
  el QR del KuDE es J002 por norma; otro QR = KuDE inválido.
- **Diseño personalizable por tenant** — D2 explícita. Ni themes, ni toggles.
- **Compartir motor con el document builder del panel** — explícito del owner;
  además el builder no pagina (`context/56`).
- **Renderizar en el navegador del cliente** — el PDF es un artefacto que se
  archiva y se adjunta por email; se genera server-side y se cachea.
- **Tirar el fallback de `getkude` antes de K4** — paridad primero.

## Preguntas abiertas

- F1 de `context/49` (¿`DCarQR` completo?) — reafirmada, para Automate.
- Diseño de referencia: el owner mencionó un archivo enviado; el último
  adjunto de esa sesión fue la constancia de RUC, no una factura. Pedirlo.
- ¿El KuDE propio también reemplaza al de la NC y (a futuro) remisión
  electrónica? Asumo que sí (mismo template, doctype variable) salvo que el
  owner diga otra cosa.
