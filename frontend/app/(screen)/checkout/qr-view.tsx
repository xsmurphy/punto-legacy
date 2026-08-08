"use client"

import { QRCodeSVG } from "qrcode.react"
import type { ScreenContext } from "./page"

export interface QrPayload {
  /** Payload EMV para renderizar local. null si el PSP mandó imagen. */
  payload: string | null
  /** Imagen ya renderizada por el PSP (data URI o URL). */
  imageUrl: string | null
  /** Monto a cobrar, ya formateado por el POS (que conoce la config del tenant). */
  amountLabel: string
  label: string
}

/**
 * QR de pago a pantalla completa en el display del cliente.
 *
 * Es la pantalla que el cliente MIRA, así que el QR va lo más grande que el
 * viewport permita y con fondo blanco fijo (un QR sobre superficie oscura no
 * escanea, sin importar el tono elegido para la pantalla).
 */
export function QrView({ qr, ctx }: { qr: QrPayload; ctx: ScreenContext | null }) {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-6 px-6 py-10">
      <div className="text-center">
        <p className="text-lg text-muted-foreground">{qr.label}</p>
        <p className="text-5xl font-bold tabular-nums">{qr.amountLabel}</p>
      </div>

      <div className="rounded-2xl bg-white p-6 shadow-sm">
        {qr.imageUrl !== null ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={qr.imageUrl}
            alt="QR de pago"
            className="size-[min(60vh,26rem)] object-contain"
          />
        ) : (
          <QRCodeSVG value={qr.payload ?? ""} className="size-[min(60vh,26rem)]" />
        )}
      </div>

      {ctx && <p className="text-sm text-muted-foreground">{ctx.companyName}</p>}
    </div>
  )
}
