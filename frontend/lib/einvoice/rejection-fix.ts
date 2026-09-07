/**
 * A DÓNDE manda un rechazo de SIFEN — N2 de `context/28-facturacion-electronica-plan.md` §F7.
 *
 * La regla de producto: *"el motivo del rechazo debe RUTEAR al arreglo. Un
 * botón genérico de reintentar sobre una causa que no se corrigió solo produce
 * un segundo rechazo."* Reemitir sin corregir nada emite otro documento fiscal
 * que va a ser rechazado igual, así que la pantalla no ofrece "emitir de nuevo"
 * a secas: ofrece primero DÓNDE se corrige.
 *
 * Se clasifica por CONTENIDO del texto (mismo criterio que `sifenVerdict`):
 * `sifenReason` es `"<código> — <mensaje>"` armado server-side desde el
 * `dMsgRes` de SIFEN, y el catálogo completo de códigos NO está documentado en
 * la guía del proveedor. Por eso los patrones son pocos y anchos, y el fallback
 * (`unknown`) es un destino de primera clase, no un error: dice qué mirar sin
 * afirmar de más.
 *
 * Lo que este módulo NO hace: tocar montos ni ítems. La corrección siempre
 * ocurre en la pantalla del dato (ficha del cliente, caja, emisor) y la
 * reemisión reconstruye el documento desde ahí — la venta ya se cobró y editar
 * su contenido económico para que SIFEN acepte es falsear un comprobante.
 */

export type RejectionFixTarget = "client" | "stamp" | "issuer" | "duplicate" | "unknown"

export interface RejectionFix {
  target: RejectionFixTarget
  /** Qué hay que corregir. Título del bloque "Qué corregir". */
  title: string
  /** Dónde se corrige y qué hacer después. */
  description: string
  /**
   * Motivo por el que reemitir puede ser PEOR que no hacerlo. Solo se llena
   * cuando existe: un aviso en cada rechazo se vuelve ruido y se ignora.
   */
  warning?: string
}

/** Sin acentos y en minúscula — SIFEN los manda con tilde y el proveedor no siempre. */
function normalize(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
}

/**
 * El orden importa: se evalúa de lo más específico a lo más ancho. "documento
 * duplicado" (el rechazo más observado en producción, código 1002) va primero
 * porque menciona "documento" y caería en cualquier otra rama.
 */
export function rejectionFix(reason: string | null | undefined): RejectionFix {
  const r = normalize((reason ?? "").trim())

  if (r === "") {
    return {
      target: "unknown",
      title: "SIFEN no informó el motivo",
      description:
        "Revisá los datos fiscales del cliente y el timbrado de la caja antes de emitir de nuevo: si la causa sigue ahí, el documento nuevo se rechaza igual.",
    }
  }

  if (r.includes("duplicad")) {
    return {
      target: "duplicate",
      title: "SIFEN dice que el documento está duplicado",
      description:
        "El documento nuevo sale con número propio (lo asigna la SET), así que el duplicado no se repite por el número.",
      // Este es el caso donde reemitir a ciegas hace daño de verdad: si el
      // duplicado se debe a que ya hay un documento APROBADO para la misma
      // venta, emitir otro factura dos veces algo que se cobró una.
      warning:
        "Antes de emitir, revisá si esta venta ya tiene un documento aprobado. Si lo tiene, la venta ya está facturada y no hay que emitir otro.",
    }
  }

  if (
    r.includes("timbrado") ||
    r.includes("punto de expedicion") ||
    r.includes("establecimiento") ||
    r.includes("vigencia") ||
    r.includes("numeracion")
  ) {
    return {
      target: "stamp",
      title: "El timbrado de la caja",
      description:
        "Cada caja es un punto de expedición y factura con SU timbrado. Revisalo en la sucursal de esta venta, pestaña Cajas, y volvé acá a emitir de nuevo.",
    }
  }

  if (
    r.includes("ruc") ||
    r.includes("receptor") ||
    r.includes("cliente") ||
    r.includes("comprador") ||
    r.includes("cedula") ||
    r.includes("documento de identidad") ||
    r.includes("contribuyente")
  ) {
    return {
      target: "client",
      title: "Los datos fiscales del cliente",
      description:
        "Corregí el RUC o el documento de identidad en la ficha del cliente y volvé acá a emitir de nuevo. El documento nuevo se arma con los datos ya corregidos.",
    }
  }

  if (
    r.includes("emisor") ||
    r.includes("certificado") ||
    r.includes("firma") ||
    r.includes("csc") ||
    r.includes("actividad economica") ||
    r.includes("habilitado")
  ) {
    return {
      target: "issuer",
      title: "Los datos del emisor",
      description:
        "El rechazo es por la configuración fiscal del comercio, no por esta venta: revisá el emisor, el certificado y el CSC en la card Conexión de esta misma pantalla antes de emitir de nuevo.",
    }
  }

  return {
    target: "unknown",
    title: "El motivo no apunta a una pantalla concreta",
    description:
      "Leé el motivo de SIFEN de arriba y corregí lo que señala —datos del cliente, timbrado de la caja o configuración del emisor— antes de emitir de nuevo.",
  }
}
