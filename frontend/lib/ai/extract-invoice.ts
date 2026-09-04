import { createOpenRouter } from "@openrouter/ai-sdk-provider"
import { generateObject } from "ai"
import { z } from "zod"
import { debitAiUsage } from "@/lib/ai/billing-gate"

/**
 * Extracción IA de facturas de compra — schema, prompt y llamada al modelo.
 *
 * Vive fuera del route handler porque tiene DOS consumidores: el upload
 * (`/api/ocr-invoice`, que procesa en `after()`) y el drain
 * (`/api/ocr-invoice/drain`, que recupera los borradores que quedaron en cola).
 * Duplicar el prompt entre los dos los haría divergir sin que nadie lo note.
 */

export const ExtractionSchema = z.object({
  supplier: z.object({
    name: z.string().nullable(),
    ruc: z.string().nullable(),
    // Dirección y teléfono del EMISOR: los usa el backend para dar de alta
    // al proveedor (o completarle los huecos) cuando el RUC no está en la
    // agenda — ver `PurchaseDraftService::ensureSupplierFromExtraction()`.
    address: z.string().nullable(),
    phone: z.string().nullable(),
  }),
  receiver: z.object({
    ruc: z.string().nullable(),
    name: z.string().nullable(),
  }),
  invoice: z.object({
    number: z.string().nullable(),
    timbrado: z.string().nullable(),
    timbradoStart: z.string().nullable(),
    timbradoEnd: z.string().nullable(),
    date: z.string().nullable(),
    condition: z.enum(["contado", "credito"]).nullable(),
    dueDate: z.string().nullable(),
    isElectronic: z.boolean().nullable(),
    cdc: z.string().nullable(),
  }),
  items: z.array(
    z.object({
      description: z.string().nullable(),
      quantity: z.number().nullable(),
      unitPrice: z.number().nullable(),
      total: z.number().nullable(),
      ivaRate: z.union([z.literal(0), z.literal(5), z.literal(10)]).nullable(),
    }),
  ),
  totals: z.object({
    subtotal: z.number().nullable(),
    exempt: z.number().nullable(),
    discount: z.number().nullable(),
    iva5: z.number().nullable(),
    iva10: z.number().nullable(),
    total: z.number().nullable(),
  }),
  currency: z.string().nullable(),
  isInvoice: z.boolean(),
  // El modelo intenta la comparación (se lo pedimos en el prompt cuando hay
  // RUC de tenant), pero el valor final que se persiste SIEMPRE se recalcula
  // en código (ver `rucsMatch` abajo) — no confiamos en que el LLM compare
  // bien dos strings de RUC, solo en que extraiga `receiver.ruc` con fidelidad.
  receiverMatchesTenant: z.boolean().nullable(),
  confidence: z.number().min(0).max(1),
})

/**
 * REGLA CRÍTICA DE NULLS — NO TOCAR sin releer context/32:
 * el borrador lo aprueba un humano; un default inventado (timbrado
 * "11111111", descripción "SERVICIOS PRESTADOS", etc.) se cuela como si
 * fuera un dato real leído de la factura. Para Punto eso es inaceptable —
 * campo ilegible SIEMPRE es `null`, nunca un placeholder. Ya no hay ninguna
 * excepción: `currency` también queda `null` cuando no se detecta. Existía
 * como excepción ("es la moneda del tenant"), pero eso asumía que el tenant
 * era paraguayo — y la moneda del tenant tampoco se puede derivar acá,
 * porque la config guarda el símbolo, no el código ISO.
 *
 * ALCANCE: el prompt de abajo está escrito para facturas PARAGUAYAS (pide
 * timbrado, RUC con dígito verificador, tasas de IVA 0/5/10, formato de
 * número XXX-XXX-XXXXXXX). Eso es una decisión de producto, no un descuido,
 * pero HOY NO ESTÁ GATEADA por país: un tenant de otro país que suba una
 * factura local va a recibir una extracción mala. Antes de habilitar el
 * módulo fuera de Paraguay hay que gatearlo por `settingCountry` o escribir
 * variantes del prompt por país.
 */
export function buildExtractionPrompt(todayISO: string, tenantRuc: string | null, isPdf: boolean): string {
  const pdfSection = isPdf
    ? `
# Documento PDF

Te paso un PDF (no una foto). Si el PDF trae texto seleccionable (la
mayoría de las facturas que llegan por correo son digitales, no
escaneadas), TRANSCRIBÍ los valores EXACTOS del texto — no los
reinterpretes, no los redondees, no los infieras visualmente. Si el PDF
tiene varias páginas, es UNA sola factura — leé todas las páginas
necesarias (los ítems suelen continuar en la página siguiente) y devolvé
un único JSON con todo consolidado.
`
    : ""

  const receiverSection = tenantRuc
    ? `
# Verificación de destinatario

El RUC de la empresa que sube esta factura es ${tenantRuc}. Extraé
"receiver.ruc" con el RUC del receptor tal como figura impreso en la
factura — NO lo fuerces a coincidir con ${tenantRuc}, reportá lo que
realmente dice el documento. Después comparalo: si "receiver.ruc" coincide
con ${tenantRuc}, "receiverMatchesTenant": true; si no coincide,
"receiverMatchesTenant": false; si el RUC del receptor no se puede leer,
"receiverMatchesTenant": null.
`
    : ""

  return `Sos un especialista en OCR de facturas de compra de proveedores
paraguayos. Te paso el documento de UNA factura (el negocio que la recibe es
el comprador — extraé los datos del EMISOR/proveedor Y del receptor/cliente,
ambos figuran impresos en el documento).

REGLA CRÍTICA — NUNCA INVENTES: si un dato no se lee con claridad, devolvé
null en ese campo. Es preferible null a un dato incorrecto: un humano revisa
y completa cada borrador antes de que se registre nada. NO uses valores por
defecto inventados (timbrados de relleno, descripciones genéricas tipo
"SERVICIOS PRESTADOS", ítems que no están impresos) para tapar huecos — eso
es exactamente lo que NO tenés que hacer.
${pdfSection}

# Dónde mirar (guía espacial)

Las facturas paraguayas siguen un layout consistente en bloques — usalo para
ubicar cada dato y para decidir cuál usar si un valor aparece repetido en
más de un lugar (priorizá siempre el bloque que le corresponde):

- Bloque A (superior): tipo de documento, timbrado, fecha de inicio y de
  vencimiento del timbrado, número de factura, RUC y razón social del
  EMISOR (proveedor), su dirección y su teléfono, actividad comercial.
- Bloque B (centro — en formatos tipo ticket puede estar al pie): fecha de
  emisión, fecha de vencimiento, RUC/razón social/dirección del CLIENTE
  (receptor), condición de venta.
- Bloque C (centro): detalle de ítems — descripción, cantidad, precio
  unitario, IVA, total por línea.
- Bloque D (inferior): subtotal, descuentos, total general, total en
  letras, exentas, IVA 5%, IVA 10%, suma de IVAs, moneda.

# Formato de campos críticos

- "invoice.number": XXX-XXX-XXXXXXX (3-3-7). Si viene sin guiones, agregalos
  (ej. "1234567890123" → "123-456-7890123").
- "invoice.timbrado": 8 dígitos exactos, sin letras ni símbolos.
- RUC del emisor y del receptor: hasta 8 dígitos + guion + 1 dígito
  verificador (ej. "80012345-0", "7659194-1").
- Números: convertí a punto decimal (ej. "1.234,56" → 1234.56). Montos en
  guaraníes (PYG): enteros, sin separadores ni decimales (ej. "10.000,00" →
  10000). Montos en otras monedas: punto decimal.
- Fechas: siempre YYYY-MM-DD.
- "invoice.condition": buscá CONTADO o CRÉDITO — puede estar marcado con una
  X o un check en una casilla en vez de estar escrito como texto.

# Identificación del documento

Para que el documento sea una factura legal paraguaya válida tiene que
contener la palabra "factura" o "timbrado" (o variantes: FACT., FACTURA
ELECTRÓNICA, TIMBRADO). Si la contiene, "isInvoice": true. Si no la
contiene (foto de otra cosa, remito, comprobante no fiscal), "isInvoice":
false — igual completá el resto de los campos con lo que se alcance a leer.

# Fechas relativas

Hoy es ${todayISO}. Si la condición de venta es CRÉDITO y no hay fecha de
vencimiento legible, copiá la fecha de emisión como vencimiento.
${receiverSection}
# Reglas de mapeo

- "supplier": RUC y razón social del EMISOR (proveedor) — nunca del cliente.
- "supplier.address": dirección del EMISOR tal como figura impresa en la
  cabecera (Bloque A). NO uses la dirección del cliente/receptor, que suele
  estar impresa más abajo y es la del negocio que recibe la factura. null si
  no figura o no se lee.
- "supplier.phone": teléfono del EMISOR tal como figura impreso en la
  cabecera (Bloque A), con el prefijo o los códigos de área que aparezcan.
  NO uses el teléfono del cliente/receptor. Si hay varios, devolvé el
  primero. null si no figura o no se lee.
- "receiver": RUC y razón social del CLIENTE (receptor) — nunca del emisor.
- "invoice.isElectronic": true si el documento se identifica como factura
  electrónica (KuDE, CDC visible, texto "factura electrónica" o similar);
  false si es una factura preimpresa común; null si no se puede determinar.
- "invoice.cdc": código de control de 44 dígitos de la factura electrónica,
  si figura (usualmente al pie, cerca del código QR).
- "items": una entrada por cada línea de producto/servicio facturado.
- "items[].ivaRate": 0, 5 o 10 (tasas de IVA vigentes en Paraguay) según lo
  que indique la línea; null si no se puede determinar.
- "totals": subtotal, exentas, descuento, IVA discriminado (5% y 10%) y
  total general, tal como figuran impresos.
- "currency": código ISO de la moneda de la factura si figura (ej. "USD",
  "PYG", "BRL"); null si el documento no la especifica. No adivines: si no
  está escrita, dejalo null.
- "confidence": tu confianza global en la lectura completa, de 0 (nada
  legible) a 1 (perfectamente legible).

Devolvé ÚNICAMENTE el JSON con el schema pedido.`
}

/** Normaliza un RUC a solo dígitos para comparar sin importar guiones/espacios. */
function normalizeRuc(raw: string | null | undefined): string {
  return (raw ?? "").replace(/[^0-9]/g, "")
}

/** Fuente única de la comparación receptor↔tenant — nunca se confía en el juicio del LLM. */
export function rucsMatch(receiverRuc: string | null, tenantRuc: string | null): boolean | null {
  if (!tenantRuc) return null
  const a = normalizeRuc(receiverRuc)
  const b = normalizeRuc(tenantRuc)
  if (a === "" || b === "") return null
  return a === b
}


export async function extractInvoice({
  modelId,
  apiKey,
  buffer,
  mediaType,
  isPdf,
  tenantRuc,
}: {
  apiUrl: string
  authHeader: string
  modelId: string
  apiKey: string
  buffer: Buffer
  mediaType: string
  isPdf: boolean
  tenantRuc: string | null
}): Promise<{
  extracted: z.infer<typeof ExtractionSchema> | null
  extractError: string | null
  tokensIn: number
  tokensOut: number
}> {
  const dataUrl = `data:${mediaType};base64,${buffer.toString("base64")}`
  const openrouter = createOpenRouter({ apiKey })
  const model = openrouter(modelId)
  const todayISO = new Date().toISOString().slice(0, 10)
  const extractionPrompt = buildExtractionPrompt(todayISO, tenantRuc, isPdf)

  let extracted: z.infer<typeof ExtractionSchema> | null = null
  let extractError: string | null = null
  let tokensIn = 0
  let tokensOut = 0

  try {
    const result = await generateObject({
      model,
      schema: ExtractionSchema,
      messages: [
        {
          role: "user",
          content: [
            { type: "text", text: extractionPrompt },
            // PDF: parte `file` (AI SDK `FilePart`) — el modelo lee el PDF
            // nativo, incluido multipágina. NO se convierte a imagen ni se
            // parte por página: un PDF (todas sus páginas) = un borrador.
            isPdf
              ? { type: "file", data: dataUrl, mediaType }
              : { type: "image", image: dataUrl },
          ],
        },
      ],
      // 8000 (antes 2000): una factura con muchos ítems o un PDF multipágina
      // trunca la respuesta con el límite viejo y la extracción falla entera.
      maxOutputTokens: 8000,
      temperature: 0.4,
    })
    extracted = result.object
    // `currency` y `receiverMatchesTenant` finales: código, no el LLM.
    // - currency: si el modelo no detectó divisa, queda `null` = "no se sabe".
    //   Rellenar con PYG le cargaba guaraníes a un comercio brasileño sin que
    //   nadie lo notara (la config guarda el SÍMBOLO, no el código ISO).
    // - receiverMatchesTenant: recalculado siempre con `rucsMatch` (fuente
    //   única), pisa lo que haya puesto el modelo.
    extracted = {
      ...extracted,
      currency: extracted.currency && extracted.currency.trim() !== "" ? extracted.currency : null,
      receiverMatchesTenant: rucsMatch(extracted.receiver?.ruc ?? null, tenantRuc),
    }
    tokensIn = Number(result.usage?.inputTokens ?? 0)
    tokensOut = Number(result.usage?.outputTokens ?? 0)
  } catch (e) {
    console.error("[ocr-invoice] fallo la extracción IA", e)
    extractError = e instanceof Error ? e.message : "No se pudo leer la factura"
  }

  return { extracted, extractError, tokensIn, tokensOut }
}


/**
 * Cierra un borrador: guarda la extracción y, SOLO si el guardado entró,
 * debita los créditos consumidos.
 *
 * ── Por qué es una función compartida ───────────────────────────────────────
 * La secuencia "extraje → guardo → cobro" estaba copiada en los dos
 * consumidores (`/api/ocr-invoice` y `/api/ocr-invoice/drain`) y las dos
 * copias cobraban de más, cada una a su manera: el upload miraba la respuesta
 * del `complete` pero debitaba igual, y el drain ni siquiera la miraba. Con el
 * `complete` roto por un 500, cada reintento pagaba una lectura del modelo que
 * nunca llegaba al borrador — dos facturas de prueba quemaron seis lecturas
 * entre las dos antes de terminar en 'failed' (2026-09-03).
 *
 * El orden es deliberado y NO se invierte: primero persistir, después cobrar.
 * El peor caso aceptable es una lectura entregada y no cobrada, que se
 * reconcilia por `requestId`; cobrar una lectura que el comercio nunca recibe
 * no tiene reconciliación posible.
 *
 * @returns `true` si la extracción quedó guardada en el borrador.
 */
export async function completeAndBill({
  apiUrl,
  authHeader,
  draftId,
  extracted,
  extractError,
  tokensIn,
  tokensOut,
  modelId,
  requestId,
  logPrefix,
}: {
  apiUrl: string
  authHeader: string
  draftId: string
  extracted: unknown
  extractError: string | null
  tokensIn: number
  tokensOut: number
  modelId: string
  requestId: string
  logPrefix: string
}): Promise<boolean> {
  const doneForm = new FormData()
  doneForm.set("extracted", JSON.stringify(extracted ?? {}))
  if (extractError) doneForm.set("error", extractError)

  const doneRes = await fetch(
    `${apiUrl}/v1/purchase-drafts?id=${encodeURIComponent(draftId)}&resource=complete`,
    { method: "POST", headers: { Authorization: authHeader }, body: doneForm },
  )

  if (!doneRes.ok) {
    // El cuerpo del error entra al log: sin esto un 500 del backend se veía
    // solo como un número, y el borrador terminaba en 'failed' con el mensaje
    // genérico de `requeueStale`, que no nombra la causa. Fue exactamente lo
    // que tapó el `SQLSTATE[42703] contacttype` durante toda una tarde.
    const detail = await doneRes.text().catch(() => "")
    console.error(
      `${logPrefix} no se pudo guardar la extracción del borrador ${draftId} ` +
        `(${doneRes.status}) ${detail.slice(0, 300)}`,
    )
    return false
  }

  await debitAiUsage({
    apiUrl,
    authHeader,
    tokensIn,
    tokensOut,
    capability: "vision",
    model: modelId,
    requestId,
    logPrefix,
  })
  return true
}
