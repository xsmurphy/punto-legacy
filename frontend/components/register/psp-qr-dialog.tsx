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
import { useOnlineStatus } from "@/hooks/use-online-status"
import { confirmPspPaymentByUid, type PspQr, type PspQrAdapter } from "@/lib/payments/psp-qr"
import type { PosConfig } from "@/lib/types/pos-bootstrap"

/** Cada cuánto se le pregunta al backend si el pago ya entró. */
const POLL_INTERVAL = 3_000
/** Corte de la espera: un QR de pasarela no vive para siempre. */
const MAX_POLL_MS = 5 * 60_000

interface Props {
  open: boolean
  /** Pasarela con la que se cobra. null cierra el diálogo (no hay cobro en curso). */
  adapter: PspQrAdapter | null
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
 * Cobro con QR de una pasarela de pago (PSP) — genérico.
 *
 * El ciclo es el mismo para cualquier pasarela; lo específico entra por el
 * `adapter` (ver `frontend/lib/payments/psp-qr.ts`):
 *
 *   1. `adapter.create({ uid, amount, saleAmount })` → QR normalizado.
 *   2. El QR se pinta acá Y se publica a la pantalla del cliente
 *      (`/v1/screens?resource=publish` type `qr-show`) — el cliente escanea
 *      de la pantalla que mira, no del monitor del cajero.
 *   3. Polling de la confirmación (default: la fila que el webhook del PSP
 *      deja en `vPayments`, `GET /v1/vpayments?resource=byUID`).
 *   4. Al cerrar (cancelado, pagado o vencido) se manda `qr-hide` y, si el QR
 *      sigue pendiente, `adapter.cancel(id)`.
 *
 * El UID lo genera el cliente (crypto.randomUUID) y viaja al PSP dentro del
 * `identifier`: es la única llave que enlaza el QR con la fila de vPayments.
 *
 * ── Sin red ─────────────────────────────────────────────────────────────────
 *
 * Un cobro por QR es intrínsecamente online: sin red no hay QR ni
 * confirmación posible. El diálogo lo dice con un mensaje LOCAL (no depende
 * de que el server conteste) y deja Cancelar disponible; la caja sigue
 * cobrando en efectivo por el camino normal, que sí se encola offline.
 */
export function PspQrDialog({
  open,
  adapter,
  amount,
  saleAmount,
  config,
  onPaid,
  onCancel,
}: Props) {
  const [qr, setQr] = React.useState<PspQr | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const [expired, setExpired] = React.useState(false)
  const isOnline = useOnlineStatus()

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
    if (!open || adapter === null) return

    let cancelled = false
    const uid = crypto.randomUUID()
    uidRef.current = uid
    qrIdRef.current = ""
    paidRef.current = false
    setQr(null)
    setError(null)
    setExpired(false)

    // Aviso local, sin salir a la red: pedir el QR sin conexión solo agrega
    // un timeout antes del mismo mensaje.
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      setError(
        `Sin conexión — el cobro con ${adapter.title} necesita internet. Cobrá en efectivo o reintentá al volver la señal.`,
      )
      return
    }

    void (async () => {
      try {
        const parsed = await adapter.create({ uid, amount, saleAmount })
        if (cancelled) return
        if (!parsed) {
          setError(`${adapter.title} no devolvió un QR válido. Cobrá por otro medio.`)
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
  }, [open, adapter, amount, saleAmount, config, publishToScreen])

  // ── 2. Polling del pago ────────────────────────────────────────────────────
  React.useEffect(() => {
    if (!open || adapter === null || qr === null || expired) return

    const confirm = adapter.confirm ?? confirmPspPaymentByUid
    const startedAt = Date.now()
    const timer = setInterval(() => {
      if (Date.now() - startedAt > MAX_POLL_MS) {
        setExpired(true)
        return
      }
      void (async () => {
        // El confirm devuelve null mientras el pago no entró (un 404 del
        // backend es el "todavía no" esperado, no un error de la caja).
        const res = await confirm(uidRef.current)
        if (res === null || paidRef.current) return
        paidRef.current = true
        // El monto acreditado manda sobre el pedido: si el PSP cobró otra
        // cifra, la venta tiene que registrar la real.
        const paidAmount = res.amount ?? amount
        publishToScreen("qr-hide")
        onPaid(uidRef.current, paidAmount)
      })()
    }, POLL_INTERVAL)

    return () => clearInterval(timer)
  }, [open, adapter, qr, expired, amount, onPaid, publishToScreen])

  /** Cierre por cancelación/vencimiento: baja el QR de la pantalla del cliente
   *  y lo revierte en el PSP para que no quede cobrable. */
  const handleCancel = React.useCallback(() => {
    publishToScreen("qr-hide")
    if (adapter !== null && qrIdRef.current !== "" && !paidRef.current) {
      void adapter.cancel(qrIdRef.current)
    }
    onCancel()
  }, [adapter, onCancel, publishToScreen])

  return (
    <Dialog open={open && adapter !== null} onOpenChange={(o) => { if (!o) handleCancel() }}>
      {/* razón: el contenido es un QR de 176px + monto; el `m` del proyecto
          (sm:max-w-2xl) dejaría el QR flotando en una caja vacía. */}
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>{adapter?.title ?? "Cobro con QR"}</DialogTitle>
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
                  <img src={qr.imageUrl} alt={`QR de pago ${adapter?.title ?? ""}`} className="size-44" />
                ) : (
                  <QRCodeSVG value={qr.payload ?? ""} size={176} />
                )}
              </div>
              <p className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                {isOnline
                  ? "Esperando el pago…"
                  : "Se cortó la conexión — no se puede confirmar el pago. Cancelá y cobrá por otro medio."}
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
