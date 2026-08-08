"use client"

import * as React from "react"
import { QRCodeSVG } from "qrcode.react"
import { Loader2 } from "lucide-react"

import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { formatMoney } from "@/lib/format-money"
import { posApi as api } from "@/lib/api/pos-client"
import { parseBancardQr, type BancardQr } from "@/lib/payments/bancard-qr"
import type { PosConfig } from "@/lib/types/pos-bootstrap"

/** Cada cuánto se le pregunta al backend si el pago ya entró. */
const POLL_INTERVAL = 3_000
/** Corte de la espera: el QR de Bancard no vive para siempre. */
const MAX_POLL_MS = 5 * 60_000

interface Props {
  open: boolean
  /** Monto a cobrar con el QR (el restante del cobro, no el total de la venta). */
  amount: number
  /** Total de la venta — el PSP lo recibe aparte del monto cobrado. */
  saleAmount: number
  config: PosConfig | null
  /** Se llama con el monto cobrado cuando el pago se confirma. */
  onPaid: (uid: string, amount: number) => void
  onCancel: () => void
}

/**
 * Cobro con QR de Bancard.
 *
 * Flujo (todo contra endpoints que ya existían — este dialog no inventa
 * protocolo, ver api/v1/bancard.php y api/v1/vpayments.php):
 *
 *   1. `POST /v1/bancard { type: 'create' }` con un UID nuevo → QR.
 *   2. El QR se pinta acá Y se publica a la pantalla del cliente
 *      (`/v1/screens?resource=publish` type `qr-show`) — el cliente escanea
 *      de la pantalla que mira, no del monitor del cajero.
 *   3. Polling a `GET /v1/vpayments?resource=byUID` hasta que el webhook del
 *      PSP deje la fila: eso ES la confirmación de pago.
 *   4. Al cerrar (cancelado, pagado o vencido) se manda `qr-hide` y, si el QR
 *      sigue pendiente, `type: 'cancel'` al PSP.
 *
 * El UID lo genera el cliente (crypto.randomUUID) y viaja al PSP dentro del
 * `identifier`: es la única llave que enlaza el QR con la fila de vPayments.
 */
export function BancardQrDialog({ open, amount, saleAmount, config, onPaid, onCancel }: Props) {
  const [qr, setQr] = React.useState<BancardQr | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const [expired, setExpired] = React.useState(false)

  // UID de ESTA operación. En una ref porque el polling y el cleanup lo
  // necesitan sin re-suscribirse; se regenera en cada apertura.
  const uidRef = React.useRef<string>("")
  const qrIdRef = React.useRef<string>("")
  const paidRef = React.useRef(false)

  /** Publica a la pantalla del cliente. Best-effort: si no hay pantalla
   *  pareada el publish no falla, simplemente nadie lo escucha. */
  const publishToScreen = React.useCallback(
    (type: "qr-show" | "qr-hide", data: Record<string, unknown> = {}) => {
      void api
        .post("/v1/screens?resource=publish", { type, data })
        .catch(() => {})
    },
    [],
  )

  // ── 1. Crear el QR al abrir ────────────────────────────────────────────────
  React.useEffect(() => {
    if (!open) return

    let cancelled = false
    const uid = crypto.randomUUID()
    uidRef.current = uid
    qrIdRef.current = ""
    paidRef.current = false
    setQr(null)
    setError(null)
    setExpired(false)

    void (async () => {
      try {
        const raw = await api.post<unknown>("/v1/bancard", {
          type: "create",
          QRAmount: amount,
          saleAmount,
          UID: uid,
        })
        if (cancelled) return
        const parsed = parseBancardQr(raw)
        if (!parsed) {
          setError("Bancard no devolvió un QR válido. Cobrá por otro medio.")
          return
        }
        qrIdRef.current = parsed.id
        setQr(parsed)
        // El monto va YA formateado: la pantalla del cliente no tiene la
        // config del tenant (separadores/moneda), el POS sí.
        publishToScreen("qr-show", {
          payload: parsed.payload,
          imageUrl: parsed.imageUrl,
          amountLabel: formatMoney(amount, config),
          label: "Escaneá para pagar",
        })
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "No se pudo generar el QR")
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [open, amount, saleAmount, config, publishToScreen])

  // ── 2. Polling del pago ────────────────────────────────────────────────────
  React.useEffect(() => {
    if (!open || qr === null || expired) return

    const startedAt = Date.now()
    const timer = setInterval(() => {
      if (Date.now() - startedAt > MAX_POLL_MS) {
        setExpired(true)
        return
      }
      void (async () => {
        try {
          // 404 mientras el pago no entró — el api client lo tira como error,
          // así que el catch vacío ES el "todavía no" esperado.
          const res = await api.get<{ success?: { amount?: number | string } }>(
            `/v1/vpayments?resource=byUID&uid=${encodeURIComponent(uidRef.current)}`,
          )
          if (!res?.success || paidRef.current) return
          paidRef.current = true
          // El monto acreditado manda sobre el pedido: si el PSP cobró otra
          // cifra, la venta tiene que registrar la real.
          const paidAmount = Number(res.success.amount ?? amount) || amount
          publishToScreen("qr-hide")
          onPaid(uidRef.current, paidAmount)
        } catch {
          // pago todavía no acreditado
        }
      })()
    }, POLL_INTERVAL)

    return () => clearInterval(timer)
  }, [open, qr, expired, amount, onPaid, publishToScreen])

  /** Cierre por cancelación/vencimiento: baja el QR de la pantalla del cliente
   *  y lo revierte en el PSP para que no quede cobrable. */
  const handleCancel = React.useCallback(() => {
    publishToScreen("qr-hide")
    if (qrIdRef.current !== "" && !paidRef.current) {
      void api.post("/v1/bancard", { type: "cancel", id: qrIdRef.current }).catch(() => {})
    }
    onCancel()
  }, [onCancel, publishToScreen])

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) handleCancel() }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>QR Bancard</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col items-center gap-3 py-2">
          <p className="text-2xl font-bold tabular-nums">{formatMoney(amount, config)}</p>

          {error !== null ? (
            <p className="py-8 text-center text-sm text-destructive">{error}</p>
          ) : expired ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              El QR venció sin acreditarse. Generá uno nuevo o cobrá por otro medio.
            </p>
          ) : qr === null ? (
            <div className="flex h-52 items-center justify-center">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              {/* Fondo blanco fijo: un QR sobre superficie oscura no escanea. */}
              <div className="rounded-lg bg-white p-3">
                {qr.imageUrl !== null ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={qr.imageUrl} alt="QR de pago Bancard" className="size-44" />
                ) : (
                  <QRCodeSVG value={qr.payload ?? ""} size={176} />
                )}
              </div>
              <p className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                Esperando el pago…
              </p>
            </>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" className="w-full" onClick={handleCancel}>
            Cancelar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
