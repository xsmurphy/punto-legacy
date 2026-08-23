/**
 * Adapter de la pasarela Bancard (QR de pago, ePagos/BANCARD_QR_API).
 *
 * Primer adapter del contrato `PspQrAdapter`. Es EXACTAMENTE lo que hacía el
 * viejo `bancard-qr-dialog.tsx` antes de extraer el dialog genérico: mismo
 * endpoint, mismo payload, mismo parseo, mismo cancel. Nada de comportamiento
 * observable cambió — lo que cambió es dónde vive.
 *
 * El endpoint devuelve el JSON crudo del proveedor: la normalización es
 * `parsePspQrResponse`, compartida por todas las pasarelas.
 */

import { posApi as api } from "@/lib/api/pos-client"
import { parsePspQrResponse, type PspQr, type PspQrAdapter, type PspQrCreateInput } from "../psp-qr"

export const bancardQrAdapter: PspQrAdapter = {
  provider: "bancard",
  // Identidad histórica del medio de pago de Bancard. NO se renombra: las
  // ventas viejas guardaron el taxonomyId de esa fila y el reporte agrupa por
  // ahí (ver PaymentMethodService::ensurePspMethod).
  systemKey: "qr",
  title: "QR Bancard",

  async create({ uid, amount, saleAmount }: PspQrCreateInput): Promise<PspQr | null> {
    const raw = await api.post<unknown>("/v1/bancard", {
      type: "create",
      QRAmount: amount,
      saleAmount,
      UID: uid,
    })
    return parsePspQrResponse(raw)
  },

  async cancel(qrId: string): Promise<void> {
    await api.post("/v1/bancard", { type: "cancel", id: qrId }).catch(() => {})
  },
}
