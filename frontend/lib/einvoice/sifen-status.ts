/**
 * Veredicto FISCAL de SIFEN sobre un documento electrónico.
 *
 * `einvoice_document` tiene DOS estados y no son lo mismo (ver el docblock de
 * `EInvoiceService::reconcile`):
 *
 *  - `status` — el outbox de Punto: ¿se mandó al proveedor?
 *  - `sifen_status` — la verdad fiscal: ¿SIFEN lo aceptó?
 *
 * SIFEN puede rechazar un DE minutos después de que el proveedor ya devolvió
 * un CDC válido y `Success: true` (caso real 2026-07-30, código 1002 por
 * documento duplicado — y el KuDE se descargaba igual). Ni el CDC ni el PDF
 * prueban validez fiscal.
 *
 * REGLA ÚNICA, la misma en todas las pantallas: **`sifen_status` MANDA sobre
 * `status`**. Un documento con `status='issued'` y `sifen_status='Rechazado'`
 * es un documento RECHAZADO y se pinta destructivo, nunca "Emitido".
 *
 * Vive acá y no en un componente porque son dos superficies (la tabla de FE en
 * Ajustes y la columna del listado de ventas) aplicando la misma regla: una
 * copia en cada una es una divergencia esperando a pasar.
 */

/**
 * `approved` = SIFEN lo aceptó. `rejected` = lo rechazó (el documento NO vale).
 * `pending` = todavía no se sabe — incluye el `sifen_status: null` de un
 * documento recién emitido y los estados transitorios ('Pendiente', que viene
 * con `Success:false` y NO es un rechazo). "Todavía no confirmado" nunca se
 * pinta como rechazo.
 */
export type SifenVerdict = "approved" | "rejected" | "pending"

/**
 * `sifen_status` no es un enum cerrado: la reconciliación guarda el
 * `dEstResField` de SIFEN ("Aprobado"/"Rechazado") cuando está, y si no cae al
 * `StatusString` del proveedor ("Exitoso", "FinalizadoERROR"). Por eso se
 * clasifica por contenido y no por igualdad exacta.
 */
export function sifenVerdict(sifenStatus: string | null | undefined): SifenVerdict {
  const s = (sifenStatus ?? "").trim().toLowerCase()
  if (s === "") return "pending"
  if (s.includes("rechaz") || s.includes("error")) return "rejected"
  if (s.includes("aprobad") || s.includes("exitoso")) return "approved"
  return "pending"
}

/** Etiqueta corta del veredicto para badges. */
export const SIFEN_VERDICT_LABEL: Record<SifenVerdict, string> = {
  approved: "Aprobado por SIFEN",
  rejected: "Rechazado por SIFEN",
  pending: "Sin confirmar",
}
