"use client"

/**
 * Pantalla de cobro — Slice A3 (visor unificado + auto-confirm).
 *
 * UX: UN visor central grande que funciona simultáneamente como display del
 * remaining y como input numérico editable. Al cubrir el total con un pago,
 * la venta se confirma automáticamente sin pedir segundo click.
 *
 * Fases:
 *   pay     → visor + métodos aplicados + botones
 *   success → pantalla verde de confirmación con vuelto
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A3.
 */

import * as React from "react"
import { X, Lock } from "lucide-react"
import { useQueryClient } from "@tanstack/react-query"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { DatePicker } from "@/components/date-picker"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { useCartStore, selectCartTotal } from "@/lib/cart/store"
import { allocateLineDiscounts, lineGross } from "@/lib/cart/allocate-discounts"
import { withLineTax } from "@/lib/cart/line-tax"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney, formatCurrencyAmount } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import { buildSalePayload, buildApiPayload } from "@/lib/commands/create-sale"
import type {
  SalePaymentMethod,
  CreateSaleResult,
  CreateSalePayload,
} from "@/lib/commands/create-sale"
import { ApiError } from "@/lib/api-client"
import { getNextInvoiceNo } from "@/lib/pos/invoice-numbering"
import { resolvePaymentAmount } from "@/lib/pos/payment-amount"
import {
  extractRegisterConflictInfo,
  registerConflictMessage,
  tenancyBlock,
  type RegisterConflictInfo,
} from "@/lib/pos/register-conflict"
import { enqueue, getCount } from "@/lib/pos/offline-queue"
import { recordSale } from "@/lib/pos/shift-journal"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import { refreshTenancy, type TenancyVerdictKind } from "@/lib/pos/register-tenancy"
import { useTenancyStore } from "@/lib/pos/tenancy-store"
import { useDrawerStatus } from "@/hooks/use-drawer"
import type { PaymentMethodConfig } from "@/lib/types/pos-bootstrap"
import { resolveColorBg } from "@/lib/ui/color-palette"
import { PaymentIdentifierDialog } from "./payment-identifier-dialog"
import { GiftcardValidationDialog } from "./giftcard-validation-dialog"
import { PspQrDialog } from "./psp-qr-dialog"
import {
  isPspQrChannelEnabled,
  isPspQrSystemKey,
  pspQrAdapterForSystemKey,
  type PspQrAdapter,
} from "@/lib/payments/psp"
import { useOnlineStatus } from "@/hooks/use-online-status"
import { posApi } from "@/lib/api/pos-client"
import { useSettingsCurrencies } from "@/hooks/use-settings"
import { printSale } from "@/lib/hardware/printers"
import { getBindingsForSale } from "@/lib/hardware/printers/binding"
import type { PrinterDocType } from "@/lib/hardware/printers/binding"
import { buildTicketData } from "@/lib/hardware/printers/build-ticket-data"
import { usePrintWithPicker } from "@/lib/hardware/printers/print-with-fallback"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"
import { useCreateOrder, useMarkOrderPaid } from "@/hooks/use-orders"
import { TransactionSuccessView } from "./transaction-success-dialog"
import { useClearCart } from "@/hooks/use-clear-cart"
import { useCloseSpaceSession } from "@/hooks/use-pos-spaces"
import { useRegisterSessionPayment, validateSessionPayment } from "@/hooks/use-space-settlement"
import { parseDisplay, formatDisplayInput } from "./money-visor"

// ── Fallback local (mismos datos que el BFF, por si el store aún no hidrata) ──

const FALLBACK_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false, isDefault: true, systemKey: "cash" },
  {
    id: "tcredito",
    name: "T. Crédito",
    code: "S",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
  {
    id: "tdebito",
    name: "T. Débito",
    code: "D",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
  {
    id: "giftcard",
    name: "Giftcard",
    code: "G",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Código de giftcard",
    identifierPlaceholder: "Ej. GC-1234-5678",
    isDefault: true,
    systemKey: "giftcard",
  },
]

// ── Métodos secundarios — línea separada debajo de la grilla principal ────────
// Discriminante: systemKey (estable, viene del backend), no el id (taxonomyId
// que varía por tenant). "interno" no tiene hoy un método real en el backend
// (es un flag del carrito, no un medio de pago) — se mantiene por si se
// materializa como taxonomy row con systemKey='internal' a futuro.
const SECONDARY_SYSTEM_KEYS = ["internal", "giftcard"]

// ── Tipo de pago aplicado ─────────────────────────────────────────────────────

interface AppliedPayment {
  rowId: string
  method: PaymentMethodConfig
  amount: number
  identifier: string | null
  /**
   * Vuelto que generó ESTE pago (el cliente entregó de más y se le devolvió la
   * diferencia). Vive en la fila y no en un estado aparte del diálogo porque
   * el vuelto es un atributo del pago: quitando la fila con la X tiene que
   * irse con ella. Como estado suelto quedaba pegado — se aplicaba un pago con
   * vuelto, se lo quitaba, y la venta se emitía igual con ese vuelto fantasma
   * en la pantalla de éxito y en la pantalla del cliente.
   */
  change: number
}

// ── Helpers de display numérico ───────────────────────────────────────────────
//
// `parseDisplay` y `formatDisplayInput` viven en `money-visor.tsx` (el visor
// que los usa). Acá había una copia literal de los dos, con el separador de
// miles clavado en "es-PY": el mismo bug había que arreglarlo en dos lugares y
// el visor del PayDialog podía divergir del de CreditPaymentDialog. Se importan
// del módulo del visor, que es el dueño del formato del display.

/**
 * Default de vencimiento para venta a crédito: hoy + 30 días, "YYYY-MM-DD".
 * 30 días es el plazo de crédito más común del rubro (razonable como default
 * editable, no una regla de negocio — el cajero lo puede cambiar o vaciar).
 */
function defaultDueDate(): string {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface PayDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// ── Estados del diálogo ───────────────────────────────────────────────────────

// "register-taken" — F5 (context/29-numeracion-y-exclusividad-de-caja.md):
// el backend rechazó la venta con 409 porque la tenencia de esta caja
// (`register_lease`) no es de este dispositivo — otro device la tiene
// tomada, o la propia tenencia venció/se anuló. Bloquea igual que "pay" con
// error, pero con la info real del tenedor + un CTA de reintentar explícito,
// en vez de dejar al cajero con el error genérico y sin poder reintentar
// (con el total ya cubierto los botones de pago quedan apagados y solo
// explican el motivo — ver `blockedHint` en PayPhase).
type DialogPhase = "pay" | "success" | "register-taken"

// `RegisterConflictInfo` + `extractRegisterConflictInfo()` — ver
// `lib/pos/register-conflict.ts`, compartido con `useRegisterClaim`
// (bootstrap del POS). `expiresAt` siempre llega `null` desde que la
// tenencia dejó de vencer por fecha (context/29 §4, 2026-08-17) — se
// mantiene en el shape por compatibilidad, el render de abajo ya lo trata
// como opcional.

/**
 * Anota la venta en el registro del turno de este dispositivo.
 *
 * Se llama en las DOS ramas de la emisión —la que posteó con red y la que
 * encoló sin ella— porque para el arqueo son el mismo hecho: una venta que
 * salió de esta caja. Es lo que le permite a Control de Caja mostrar un total
 * sin conexión sin inventarlo ni pedirlo prestado a un cache del servidor.
 *
 * Fire-and-forget por diseño: `recordSale` no tira (todo su cuerpo es
 * best-effort), y aunque tirara, la venta ya está emitida e impresa. Nada de
 * lo que pase acá puede tocarla.
 */
async function journalSale(payload: CreateSalePayload): Promise<void> {
  await recordSale({
    registerId: useCatalogStore.getState().activeRegisterId,
    uid: payload.uid,
    date: payload.date,
    payments: (payload.payment ?? []).map((p) => ({
      name: p.name,
      type: p.type,
      total: p.total,
    })),
    internal: payload.interno === true,
  })
}

// ── Componente principal ──────────────────────────────────────────────────────

export function PayDialog({ open, onOpenChange }: PayDialogProps) {
  const lines = useCartStore((s) => s.lines)
  const customer = useCartStore((s) => s.customer)
  const credito = useCartStore((s) => s.credito)
  const interno = useCartStore((s) => s.interno)
  const tags = useCartStore((s) => s.tags)
  const quoteParentId = useCartStore((s) => s.quoteParentId)
  const orderParentId = useCartStore((s) => s.orderParentId)
  const sessionParentId = useCartStore((s) => s.sessionParentId)
  const sessionOrderIds = useCartStore((s) => s.sessionOrderIds)
  const settlementIntent = useCartStore((s) => s.settlementIntent)
  const saleDiscount = useCartStore((s) => s.saleDiscount)
  // Venta sin IVA: viaja al payload (importes netos + flag) — mig 101.
  const ivaRemoved = useCartStore((s) => s.ivaRemoved)
  const setQuoteParent = useCartStore((s) => s.setQuoteParent)
  const clearCart = useClearCart()
  const total = useCartStore(selectCartTotal)
  const config = useCatalogStore((s) => s.config)
  const storedMethods = useCatalogStore((s) => s.paymentMethods)

  // Canal QR de cada pasarela (panel → Módulos). El medio de pago lo
  // provisiona el backend al habilitar el canal, pero la fila sobrevive si el
  // módulo se apaga después — sin este filtro el botón seguiría cobrable.
  // El gate es por pasarela: Bancard apagado no puede esconder el medio de
  // otra pasarela que sí está activa (ver lib/payments/psp).
  const paymentMethods = React.useMemo(() => {
    const list = storedMethods.length > 0 ? storedMethods : FALLBACK_METHODS
    return [...list]
      .filter((m) => isPspQrChannelEnabled(m.systemKey, config))
      // Orden por sortOrder (drag&drop del panel); sin valor cae al final estable.
      .sort((a, b) => {
        const sa = a.sortOrder ?? Number.MAX_SAFE_INTEGER
        const sb = b.sortOrder ?? Number.MAX_SAFE_INTEGER
        return sa - sb
      })
  }, [storedMethods, config])

  const { data: currenciesData } = useSettingsCurrencies()
  const currencies = currenciesData?.rows ?? []

  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  // Selector de impresora para el botón manual del modal de éxito — mismo
  // wrapper que usan el diálogo de transacciones y el drawer de opciones.
  const { requestPrint, pickerDialog } = usePrintWithPicker()

  // Guard de caja
  const { data: drawerStatus } = useDrawerStatus()
  const { data: configData } = usePosRegisterConfig(activeRegisterId)
  const controlCaja = configData?.config?.controlCaja ?? true
  const ordenEnVenta = configData?.config?.ordenEnVenta ?? false
  const drawerClosed = controlCaja ? (drawerStatus !== undefined && !drawerStatus.isOpen) : false

  const createOrder = useCreateOrder()
  const markOrderPaid = useMarkOrderPaid()
  const closeSpaceSession = useCloseSpaceSession()
  const registerSessionPayment = useRegisterSessionPayment()

  const qc = useQueryClient()

  // ── Estado ────────────────────────────────────────────────────────────────
  const [display, setDisplay] = React.useState("")
  const [applied, setApplied] = React.useState<AppliedPayment[]>([])
  const [pendingIdentifier, setPendingIdentifier] = React.useState<{
    method: PaymentMethodConfig
    amount: number
    changeOverride?: number
  } | null>(null)
  const [pendingGiftcard, setPendingGiftcard] = React.useState(false)
  /** Cobro con QR de pasarela en curso: con qué pasarela y por cuánto. */
  const [pendingQr, setPendingQr] = React.useState<{
    adapter: PspQrAdapter
    amount: number
  } | null>(null)
  const [dueDate, setDueDate] = React.useState(defaultDueDate())
  const [phase, setPhase] = React.useState<DialogPhase>("pay")
  const [saleResult, setSaleResult] = React.useState<CreateSaleResult | null>(null)
  const [submitting, setSubmitting] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  // F5 — 409 de tenencia de caja (register_lease). Ver DialogPhase arriba.
  const [registerTakenInfo, setRegisterTakenInfo] = React.useState<RegisterConflictInfo | null>(null)
  // Veredicto LOCAL que disparó el bloqueo, cuando el bloqueo no vino de un
  // 409 sino del grant persistido (caso offline). Es lo que distingue "nunca
  // tomé esta caja" de "la confirmación venció" — el 409 no puede decir eso
  // porque no hubo 409.
  const [registerTakenKind, setRegisterTakenKind] = React.useState<TenancyVerdictKind | null>(null)
  // `true` cuando el diálogo ABRIÓ ya bloqueado (el cajero tocó un CTA de
  // cobro que no puede emitir), distinto de bloquearse al confirmar una venta
  // ya cargada. Cambia qué hace el CTA de reintento: en el primer caso no hay
  // pagos que reconfirmar, solo hay que devolverlo al teclado de cobro.
  const [blockedBeforePay, setBlockedBeforePay] = React.useState(false)
  // Snapshot para el botón manual "Ordenar" del modal de éxito (ordenEnVenta).
  // Capturado ANTES del clearCart (que solo corre en handleClose) — lines/
  // customer siguen siendo los de la venta recién facturada mientras el
  // modal de éxito está en pantalla.
  const [orderDraft, setOrderDraft] = React.useState<{
    lines: typeof lines
    customerId: string | undefined
    note: string | null | undefined
    transactionId: string
  } | null>(null)

  const displayRef = React.useRef<HTMLInputElement>(null)

  // uid idempotente ESTABLE por apertura del dialog: un reintento del mismo
  // cobro (ej. timeout que en realidad sí llegó al server) reusa el mismo uid
  // y la dedupe server-side (columna UNIQUE) lo atrapa. Generar un uid nuevo
  // por intento —lo que hacía buildSalePayload solo— duplicaba la venta en
  // ese escenario.
  const saleUidRef = React.useRef<string>(crypto.randomUUID())

  // Resetear al abrir
  React.useEffect(() => {
    if (open) {
      setDisplay("")
      setApplied([])
      setPendingIdentifier(null)
      setDueDate(defaultDueDate())
      setSaleResult(null)
      setErrorMsg(null)
      // Gate de tenencia AL ABRIR, no solo al confirmar: el botón de cobrar
      // ya se muestra deshabilitado con el motivo (`PayCta` en
      // `cart-panel.tsx`), y quien igual lo toca tiene que aterrizar en la
      // explicación —con el CTA para volver a tomar la caja— en vez de en un
      // teclado que va a rechazarle la venta al final. Mismo veredicto, mismo
      // fail-closed que el gate de `handleConfirm`.
      const block = tenancyBlock(useTenancyStore.getState().verdict)
      setRegisterTakenInfo(block?.info ?? null)
      setRegisterTakenKind(block?.kind ?? null)
      setBlockedBeforePay(block !== null)
      setPhase(block ? "register-taken" : "pay")
      // Acá NO se toca el modo del carrito, a propósito. Abrir el cobro no es
      // facturar: es un gesto reversible —Esc y listo— y encima el hotkey
      // Enter lo dispara desde CUALQUIER modo sin mirar `posMode`
      // (`use-pos-hotkeys.ts`). Un `beginSale()` acá convertía ese Enter+Esc
      // accidental en una mutación sin vuelta atrás: la cotización amarilla
      // quedaba en modo venta —y el próximo Enter emitía Factura en vez de
      // guardar la cotización— y una orden con envío perdía su dirección.
      //
      // El modo lo cambia quien TOMA la acción de facturar, que es donde el
      // cajero sí se comprometió: `loadFromOrder` / `loadFromSession` /
      // `loadForSettlement` (nacen en venta por `initialState`) y los dos
      // "Facturar cotización", que llaman `beginSale()` explícito. Y la venta
      // confirmada termina en venta igual, porque `handleClose` limpia el
      // carrito. Ver `beginSale` en lib/cart/store.ts.

      // Revalidación de tenencia al ABRIR, no al confirmar. El veredicto local
      // se refresca por latido (5 min) y por realtime; entre latido y latido
      // otro dispositivo pudo tomar la caja y el gate síncrono de arriba deja
      // pasar — el rechazo llegaría recién en el 409 del confirm, con el medio
      // de pago ya cargado (exactamente lo que reportó el owner). Sin `await`
      // a propósito: un round-trip bloqueante antes de cada cobro rompería el
      // POS táctil y contradice offline-first. El watcher de abajo levanta el
      // veredicto nuevo apenas llega y corta antes del teclado numérico.
      if (
        !block &&
        activeRegisterId &&
        typeof navigator !== "undefined" &&
        navigator.onLine
      ) {
        void refreshTenancy(activeRegisterId)
      }
      saleUidRef.current = crypto.randomUUID()
      // autofocus al visor
      setTimeout(() => displayRef.current?.focus(), 50)
    }
    // `activeRegisterId` afuera a propósito: el efecto es "al abrir", y
    // reejecutarlo por un cambio de caja resetearía el cobro en curso.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  // ── Derivados ─────────────────────────────────────────────────────────────
  const appliedTotal = applied.reduce((s, r) => s + r.amount, 0)
  const remaining = total - appliedTotal
  // Vuelto DERIVADO de los pagos, nunca un estado propio: quitar el pago con
  // la X (o con Backspace) tiene que llevarse su vuelto, y un estado paralelo
  // se quedaba pegado — la venta salía con un vuelto que ya nadie entregó.
  const change = applied.reduce((s, r) => s + r.change, 0)

  // Perder la tenencia MIENTRAS el diálogo está abierto tiene que cortar acá,
  // antes de que el cajero termine de cargar el medio de pago — no en el 409
  // del confirm. Dos cosas mueven el veredicto con el cobro abierto: la
  // revalidación que dispara la apertura (arriba) y el evento realtime
  // `register-lease` cuando un admin libera o reasigna la caja
  // (`use-realtime-sync.ts`). Una sola suscripción cubre las dos.
  //
  // Es una SUSCRIPCIÓN al store y no una lectura reactiva: al diálogo no le
  // interesa el veredicto vigente —ese ya lo evaluó el gate de la apertura—,
  // le interesa el momento en que CAMBIA. Se rearma cuando cambian
  // phase/submitting/remaining: barato, y evita arrastrar refs para leer
  // valores frescos dentro del callback.
  //
  // `submitting` queda excluido: una venta ya en vuelo la resuelve el gate de
  // `handleConfirm` (que corre antes de numerar), y cambiar de fase encima
  // taparía su resultado.
  React.useEffect(() => {
    if (!open || phase !== "pay" || submitting) return
    return useTenancyStore.subscribe((state) => {
      const block = tenancyBlock(state.verdict)
      if (!block) return
      setRegisterTakenInfo(block.info)
      setRegisterTakenKind(block.kind)
      // El reintento reanuda la emisión SOLO si la venta estaba lista para
      // emitirse (total cubierto). Con pagos a medio cargar vuelve al teclado
      // con lo aplicado intacto — reanudar ahí emitiría una venta cobrada por
      // menos de lo que vale.
      setBlockedBeforePay(remaining > 0)
      setPhase("register-taken")
    })
  }, [open, phase, submitting, remaining])

  // ── Confirmar venta ───────────────────────────────────────────────────────
  /**
   * Auto-print ESC/POS de la venta recién confirmada.
   *
   * Se llama en LAS DOS ramas —online y offline—: la impresión es browser-side
   * y no necesita al servidor (decisión del owner), así que una venta encolada
   * también tiene que salir por la impresora. Antes vivía solo en la rama
   * online y la offline hacía `return` antes de llegar: con el server caído,
   * TODA venta se encolaba y ninguna imprimía sola, mientras el botón manual sí
   * funcionaba — exactamente el síntoma reportado.
   *
   * Venta al contado/crédito SIEMPRE emite Factura — el recibo es el documento
   * del pago de una factura a crédito (regla fiscal), NO un fallback cuando
   * falta el binding "factura". EXCEPCIÓN fiscal (F2 giftcard-issue-flow, PY):
   * vender una gift card es un ADELANTO → Recibo. v1: si el carrito mezcla gift
   * card(s) + productos, emitimos Recibo para TODA la venta (no partimos el
   * documento) — TODO partirlo cuando el owner lo priorice.
   */
  function runAutoPrint(
    payload: import("@/lib/commands/create-sale").CreateSalePayload,
    result: CreateSaleResult,
  ) {
    const hasGiftcardIssuance = lines.some((l) => !!l.giftcard)
    const ticketData = buildTicketData({ payload, result, config })
    const saleCategoryIds = [
      ...new Set(ticketData.items.map((i) => i.categoryId).filter((id): id is string => id !== null)),
    ]
    const autoDocType: PrinterDocType = hasGiftcardIssuance ? "receipt" : "factura"
    const matchedBindings = getBindingsForSale(allBindings, autoDocType, saleCategoryIds)
    const autoBindings = matchedBindings.filter((b) => b.autoPrint)
    // Sin binding para este documento el auto-print no dispara, y antes no
    // decía nada. Solo avisamos cuando NO hay binding: si lo hay pero ninguno
    // tiene auto-print, es una elección del operador y un toast por venta sería
    // ruido.
    if (matchedBindings.length === 0 && allBindings.length > 0) {
      const docLabel = autoDocType === "receipt" ? "Recibo" : "Factura"
      toast.warning(
        `Ninguna impresora tiene asignado el documento ${docLabel} — asignáselo en Ajustes → Impresoras`,
      )
    }
    if (autoBindings.length === 0) return
    printSale({ docType: autoDocType, data: ticketData, bindings: allBindings })
      .then((r) => {
        if (r.failed > 0) {
          toast.warning(
            `${r.failed} impresora(s) fallaron al imprimir${r.errors[0] ? `: ${r.errors[0]}` : ""}`,
          )
        } else if (r.printed > 0) {
          toast.success(`${r.printed} impresora(s) imprimieron`)
        }
      })
      .catch((err) => console.error("[auto-print] Error:", err))
  }

  async function handleConfirm(
    appliedPayments: AppliedPayment[],
    changeAmount: number,
  ) {
    setSubmitting(true)
    setErrorMsg(null)
    setOrderDraft(null) // limpiar snapshot de "Ordenar" de una venta previa en el mismo mount

    try {
      // Bug Control de Caja: se mandaba `name: r.method.id` — el UUID de
      // taxonomía (o el slug nativo) quedaba persistido como "nombre" del
      // medio de pago en `transactionPaymentType`, y el resumen de caja lo
      // mostraba crudo. `name` es SIEMPRE el label legible; `type` lleva el
      // id/slug para agrupar de forma estable.
      const payments: SalePaymentMethod[] = appliedPayments.map((r) => ({
        name: r.method.name,
        type: r.method.id,
        total: r.amount,
        ...(r.identifier ? { identifier: r.identifier } : {}),
      }))

      // Venta a crédito sin ningún pago inicial → se registra como crédito total
      // TODO crédito 100%: revisar con el owner si esto aplica cuando se abre
      // el dialog en modo crédito pero el cajero no aplica ningún pago.
      const effectivePayments =
        payments.length === 0 && credito
          ? [{ name: "Crédito", type: "credito", total: 0 }]
          : payments

      if (lines.length === 0) {
        throw new Error("El carrito está vacío")
      }
      if (credito && !customer) {
        throw new Error("Venta a crédito requiere un cliente seleccionado")
      }
      // Gate real (no solo UI): antes `reshapeCustomer` mandaba
      // `isCreditable: true` hardcodeado para TODO cliente, así que este
      // chequeo nunca bloqueaba a nadie. El backend (SaleService::save) YA
      // valida esto server-side — este throw evita el round-trip y le da al
      // cajero el motivo sin esperar el 422.
      if (credito && customer && !customer.isCreditable) {
        throw new Error(`${customer.name} no tiene crédito habilitado`)
      }
      if (effectivePayments.length === 0) {
        throw new Error("Debe agregar al menos un método de pago")
      }

      // Preflight del cobro parcial de mesa — ANTES de crear la venta.
      // Bug T1 (2026-08-03): antes esto se validaba DESPUÉS de crear la
      // venta (más abajo, rama `settlementIntent && result?.transactionId`).
      // Si `registerSessionPayment` rechazaba (mesa ya cobrada por otra
      // familia, ítem ya saldado, etc.), la plata ya había entrado a la caja
      // y la mesa seguía debiendo lo mismo — descuadre, y el cajero solo veía
      // "avisá al soporte" sin poder resolverlo él mismo. Corriendo la MISMA
      // validación (SpaceSettlementService::preflightPayment, sin escribir)
      // antes de la venta, un rechazo aborta ACÁ y la venta nunca se crea —
      // el cajero lee el motivo real y corrige (ej. cobrar por ítems en vez
      // de monto libre). No cierra la ventana entera (otro dispositivo puede
      // cobrar en el medio) pero cubre el caso común sin comprometer plata.
      if (settlementIntent) {
        try {
          await validateSessionPayment(
            settlementIntent.kind === "items"
              ? {
                  sessionId: settlementIntent.sessionId,
                  transactionId: "",
                  kind: "items",
                  orderItemIds: settlementIntent.orderItemIds,
                }
              : settlementIntent.kind === "amount"
                ? {
                    sessionId: settlementIntent.sessionId,
                    transactionId: "",
                    kind: "amount",
                    amount: settlementIntent.amount,
                  }
                : {
                    sessionId: settlementIntent.sessionId,
                    transactionId: "",
                    kind: "share",
                    shareCount: settlementIntent.shareCount,
                    shareIndex: settlementIntent.shareIndex,
                  },
          )
        } catch (preflightErr) {
          throw new Error(
            preflightErr instanceof Error
              ? preflightErr.message
              : "No se pudo validar el cobro parcial de la mesa",
          )
        }
      }

      // Número de comprobante — se consume UNA SOLA VEZ acá, ANTES de
      // intentar el POST, y sirve a las DOS ramas (online y offline) que
      // siguen. El número sale SIEMPRE del contador local del POS ("último
      // correlativo de mi caja + 1", `lib/pos/invoice-numbering.ts` —
      // context/29-numeracion-y-exclusividad-de-caja.md) — nunca de un
      // `DocumentNumber::allocate()` server-side en el camino online: un
      // allocate() ahí devolvería números por encima de lo que el device ya
      // emitió offline, y el device emitiría después, offline, un número
      // MENOR con fecha posterior — viola "orden de números = orden de
      // fechas". El backend (`api/v1/sales.php`) valida que este device siga
      // siendo el tenedor de `register_lease` antes de guardar y la rechaza
      // con 409 si no lo es — ver el catch de abajo (`registerTakenInfo`).
      //
      // Regla del owner ("no puede salir una venta sin número de factura",
      // context/08 §53) — sin número no hay documento válido para entregar,
      // en NINGUNA rama (online u offline). `interno` incluido — el doctype
      // 'comprobante' sin valor fiscal todavía no existe. Cotización queda
      // afuera — no pasa por acá (create-quote.ts es un comando aparte,
      // nunca llega a invoice-numbering).
      //
      // Trade-off aceptado: un intento que falla DESPUÉS de este punto (4xx
      // de negocio, o el cobro online-only de sesión/orden/settlement que no
      // encola) quema este número sin usarlo — mismo criterio que un hueco
      // de numeración, aceptado por diseño en modo offline. La alternativa
      // (pedir el número recién si el POST fuera a tener éxito) no es
      // posible: hace falta MANDARLO en el payload para que el backend
      // valide tenencia.
      // ── Gate de tenencia, ANTES de numerar ──────────────────────────────
      // El fix del incidente 2026-08-23. Hasta acá el único gate de tenencia
      // era el 409 de `sales.php`, o sea que existía SOLO online: sin red no
      // había POST, el POS numeraba, imprimía y el rechazo llegaba al
      // sincronizar, con el ticket ya en la mano del cliente. Ahora el device
      // decide con lo último que el servidor le confirmó (grant persistido,
      // `lib/pos/register-tenancy.ts`) — que es la única información que puede
      // tener sin conexión.
      //
      // Va ANTES de `getNextInvoiceNo()` a propósito: consumir el número es el
      // punto de no retorno de la numeración (deja un hueco aunque la venta no
      // salga). Sin derecho a emitir, no se toca el contador.
      //
      // Fail-closed: `verdict === null` (todavía no hidratado) tampoco emite.
      // El costo de equivocarse hacia el otro lado es un comprobante duplicado
      // que el sistema después repudia.
      const block = tenancyBlock(useTenancyStore.getState().verdict)
      if (block) {
        setRegisterTakenInfo(block.info)
        setRegisterTakenKind(block.kind)
        setBlockedBeforePay(false)
        setPhase("register-taken")
        return
      }

      let invoiceNo: number
      try {
        invoiceNo = getNextInvoiceNo(activeRegisterId)
      } catch {
        throw new Error(
          'No se pudo determinar el próximo número de comprobante de esta caja — conectate a internet e intentá de nuevo.',
        )
      }

      // Construir payload para tenerlo disponible tanto para el POST como para el enqueue
      const payload = buildSalePayload({
        lines,
        payments: effectivePayments,
        credito,
        interno,
        customer,
        userId: null,
        tags,
        quoteParentId,
        saleDiscount,
        ivaRemoved,
        timezone: config?.timezone,
        dueDate: credito ? (dueDate || null) : null,
        uid: saleUidRef.current,
        invoiceno: invoiceNo,
      })

      let result: CreateSaleResult

      try {
        const apiPayload = buildApiPayload(payload)
        // El timeout existe para el modo offline: si la red no responde, se
        // encola rápido y el cajero sigue vendiendo. Pero el cobro de un
        // espacio/orden es ONLINE-ONLY (abajo), así que cortar a los 5s solo
        // sirve para abortar una venta que el servidor quizás estaba
        // procesando — con la mesa cargada y el servidor remoto, 5s se cumplen
        // seguido. Para esos cobros se da margen real.
        const isOnlineOnlyCharge = Boolean(sessionParentId || orderParentId || settlementIntent)
        const timeoutMs = isOnlineOnlyCharge ? 20_000 : 5_000
        const timeoutPromise = new Promise<never>((_, reject) =>
          setTimeout(() => reject(new Error('fetch timeout')), timeoutMs)
        )
        const raw = await Promise.race([
          posApi.postLegacy<{
            success: boolean
            transactionId: string
            uid: string
            duplicated: boolean
            einvoicePortalUrl?: string | null
          }>(
            '/v1/sales',
            apiPayload,
          ),
          timeoutPromise,
        ])
        result = {
          transactionId: raw.transactionId,
          transactionUID: raw.uid,
          // Mismo número consumido arriba, ANTES del POST — el backend lo
          // persistió tal cual (SaleInput.php:157 → SaleService.php:663).
          // Antes esto era siempre `null`: la venta online nunca mandaba
          // invoiceno y el ticket nunca mostraba comprobante (P0 fiscal).
          invoiceNumber: String(invoiceNo),
          total: payload.subtotal,
          duplicated: raw.duplicated === true,
          einvoicePortalUrl: raw.einvoicePortalUrl ?? null,
        }
      } catch (fetchErr) {
        // TypeError (network) o timeout → encolar offline
        // ApiError 4xx → NO encolar (error de negocio), relanzar
        // ApiError 5xx → encolar también
        const isNetworkOrTimeout =
          fetchErr instanceof TypeError ||
          (fetchErr instanceof Error && fetchErr.message === 'fetch timeout') ||
          (fetchErr instanceof ApiError && fetchErr.status >= 500)

        if (!isNetworkOrTimeout) {
          // 4xx o error de negocio — mostrar error normal
          throw fetchErr
        }

        // Online-only: el cobro de un espacio/orden NO se encola offline —
        // el scope offline es SOLO ventas simples (memoria/roadmap): encolar
        // acá dejaría la sesión/orden sin markPaid ni close en el server.
        // El cobro PARCIAL (split, context/15 §F3) es aún más estricto: sin
        // transactionId no hay renglón de ledger, y el saldo de la mesa
        // quedaría intacto con la plata ya en la caja.
        // El cajero ve el error y reintenta con conexión.
        if (sessionParentId || orderParentId || settlementIntent) {
          // Un 5xx NO es falta de conexión: es un error DEL SERVIDOR, y
          // decirle "sin conexión" al cajero lo manda a reintentar para
          // siempre contra un bug. Se propaga el error real para que se vea
          // qué falló. Solo la caída de red y el timeout se reportan como
          // falta de conexión.
          if (fetchErr instanceof ApiError) {
            throw fetchErr
          }
          throw new Error(
            "Sin conexión con el servidor — el cobro de espacios/órdenes necesita estar online. Reintentá.",
          )
        }

        // El número ya se consumió UNA sola vez arriba, antes del try/POST
        // — acá solo se usa para el enqueue, no se vuelve a pedir (ver
        // comentario grande más arriba, antes de `buildSalePayload`).

        // Encolar en IndexedDB
        await enqueue({ clientTempId: payload.uid, invoiceNo, sale: payload })
        await journalSale(payload)

        // Stock optimistic
        const catalogItems = useCatalogStore.getState().items
        for (const line of lines) {
          const item = catalogItems.find((i) => i.id === line.itemId)
          if (item && item.stock !== null) {
            useCatalogStore.getState().patchItem({ ...item, stock: item.stock - line.qty })
          }
        }

        // Actualizar contador de pendientes
        const count = await getCount()
        useOfflineSyncStore.getState().setPendingCount(count)

        // Misma pantalla de confirmación que la venta online (decisión owner:
        // TODA transacción termina en el modal de éxito, ahí se decide si
        // imprimir — la impresión es browser-side y no necesita el server).
        // Antes: clearCart + toast + return dejaban el dialog colgado en fase
        // "pay" con total Gs. 0 y sin confirmación. El clearCart ahora ocurre
        // al cerrar (handleClose, fase success), igual que el flujo online.
        toast.info('Sin conexión — la venta se enviará al volver online')
        const offlineResult: CreateSaleResult = {
          transactionId: "",
          transactionUID: payload.uid,
          // El número que emitió el device es el que va impreso: es el mismo
          // que el server va a confirmar al sincronizar (offline-sync.php).
          invoiceNumber: String(invoiceNo),
          total: payload.subtotal,
          duplicated: false,
          // Venta offline: el documento electrónico todavía no existe (se
          // encola al sincronizar), así que no hay link del portal que imprimir.
          einvoicePortalUrl: null,
        }
        setSaleResult(offlineResult)
        runAutoPrint(payload, offlineResult)
        setPhase("success")
        return
      }

      setSaleResult(result)
      // Anotar la venta en el registro del turno de este dispositivo. La venta
      // ONLINE se anota igual que la offline: el total del turno que se muestra
      // sin red es la suma de lo que esta caja emitió, y una venta hecha con
      // conexión no deja de haber ocurrido en esta caja. Ver `shift-journal.ts`.
      //
      // Sin `await`: la venta ya está confirmada y lo que sigue es imprimir el
      // comprobante. Una escritura de contabilidad interna no se pone delante
      // del ticket del cliente.
      void journalSale(payload)

      runAutoPrint(payload, result)

      // Si hubo pago con giftcard, consumirla (fire-and-forget: la venta ya está confirmada)
      const gcPayment = appliedPayments.find((r) => r.method.systemKey === "giftcard")
      if (gcPayment?.identifier && result?.transactionId) {
        void posApi.post("/v1/giftcards?resource=consume", {
          code: gcPayment.identifier,
          transactionId: result.transactionId,
        }).catch((err) => {
          // El endpoint distingue no-encontrada/vencida/ya-consumida/conflicto
          // (api/v1/giftcards.php resource=consume) — mostrar el motivo real,
          // no un genérico: la venta YA está confirmada, así que soporte
          // necesita saber SI HAY que reconciliar el saldo a mano.
          const reason = err instanceof Error ? err.message : "error desconocido"
          toast.error(`Venta confirmada — giftcard no se pudo canjear (${reason}). Avisá al soporte.`)
        })
      }

      // Módulo de Órdenes/Espacios (O1 + context/15 F2/F3) — cuatro casos,
      // mutuamente excluyentes, ninguno bloquea el éxito de la venta ya
      // confirmada:
      //
      // 0) settlementIntent presente: esta venta es un cobro PARCIAL de una
      //    mesa (split de cuenta, context/15 §F3). Se registra en el ledger
      //    (`space_session_payment`) y NADA MÁS: markPaid de las órdenes y
      //    close de la sesión los decide el SERVIDOR en la misma transacción
      //    (`settleIfCovered`) cuando el saldo llega a 0. Si la UI cerrara
      //    acá, la primera persona en pagar liberaría la mesa con saldo
      //    pendiente. Va primero porque es excluyente con sessionParentId.
      // 1) sessionParentId presente: esta venta viene de "Cobrar" un espacio
      //    completo (loadFromSession en /pos/espacios) — cerrar el rastro de
      //    CADA orden de la sesión con markPaid y, al terminar, cerrar la
      //    sesión (SpaceSessionService::close) con el transactionId — el
      //    espacio vuelve a 'free'. NO reusa el flujo de orderParentId (una
      //    sola orden) para no perder el resto de las órdenes de la sesión.
      // 2) orderParentId presente: esta venta viene de "Cobrar" una orden
      //    existente (loadFromOrder en /pos/ordenes) — cerrar el rastro con
      //    markPaid usando el transactionId recién creado.
      // 3) Sin ninguno de los dos, con ordenEnVenta=true: venta normal en
      //    modo venta — generar una orden espejo (sendNow=true) y cobrarla
      //    inmediatamente, para que quede el mismo registro operativo que si
      //    el mozo la hubiera tomado como orden primero.
      if (settlementIntent && result?.transactionId) {
        const txId = result.transactionId
        // La venta YA está confirmada: si el registro falla no se revierte
        // nada — se avisa para que soporte concilie el ledger a mano. El
        // backend es idempotente por transactionId, así que un reintento del
        // mismo cobro nunca cuenta doble.
        void registerSessionPayment
          .mutateAsync(
            settlementIntent.kind === "items"
              ? {
                  sessionId: settlementIntent.sessionId,
                  transactionId: txId,
                  kind: "items",
                  orderItemIds: settlementIntent.orderItemIds,
                }
              : settlementIntent.kind === "amount"
                ? {
                    sessionId: settlementIntent.sessionId,
                    transactionId: txId,
                    kind: "amount",
                    amount: settlementIntent.amount,
                  }
                : {
                    sessionId: settlementIntent.sessionId,
                    transactionId: txId,
                    kind: "share",
                    shareCount: settlementIntent.shareCount,
                    shareIndex: settlementIntent.shareIndex,
                  },
          )
          .catch((e: unknown) => {
            // El preflight de arriba ya cubrió el caso común (mesa cobrada
            // por otra familia, ítem ya saldado) — esto solo dispara en la
            // ventana chica que el preflight NO cierra (ver docblock de
            // `SpaceSettlementService::preflightPayment`): otro dispositivo
            // cobró justo entre el preflight y este registro real. Mismo
            // criterio que la rama `sessionParentId` de abajo — mostrar el
            // motivo real, no un "avisá al soporte" que no dice qué pasó.
            const reason = e instanceof Error ? e.message : ""
            toast.error(
              reason
                ? `Venta confirmada — no se pudo registrar el pago parcial en la cuenta de la mesa: ${reason}`
                : "Venta confirmada — no se pudo registrar el pago parcial en la cuenta de la mesa. Avisá al soporte.",
            )
          })
      } else if (sessionParentId && result?.transactionId) {
        const txId = result.transactionId
        void Promise.all(
          sessionOrderIds.map((orderId) => markOrderPaid.mutateAsync({ orderId, transactionId: txId })),
        )
          .then(() => closeSpaceSession.mutateAsync({ sessionId: sessionParentId, transactionId: txId }))
          .catch((e: unknown) => {
            // El motivo importa: el servidor NO cierra una mesa con saldo
            // pendiente (SpaceSessionService::close), y el caso realista es
            // que otro mozo haya mandado una orden entre que se cargó el
            // carrito y se confirmó el cobro — esa comida quedó sin facturar.
            // "Avisá al soporte" a secas mandaba a mirar logs algo que el
            // cajero resuelve cobrando lo que falta.
            const reason = e instanceof Error ? e.message : ""
            toast.error(
              reason
                ? `Venta confirmada — no se pudo cerrar el espacio: ${reason}`
                : "Venta confirmada — no se pudo cerrar el espacio. Avisá al soporte.",
            )
          })
      } else if (orderParentId && result?.transactionId) {
        void markOrderPaid.mutateAsync({ orderId: orderParentId, transactionId: result.transactionId })
          .catch(() => {
            toast.error("Venta confirmada — no se pudo cerrar la orden vinculada. Avisá al soporte.")
          })
      } else if (ordenEnVenta && result?.transactionId) {
        // Manual (spec owner 2026-07-31): ya no se genera la orden espejo
        // automáticamente. Se deja el snapshot listo para el botón "Ordenar"
        // del modal de éxito — el cajero decide si la genera.
        setOrderDraft({
          lines,
          customerId: customer?.id,
          note: payload.note,
          transactionId: result.transactionId,
        })
      }

      setPhase("success")
      void posApi.post("/v1/screens?resource=publish", {
        type: "sale-confirmed",
        data: { total, change: changeAmount },
      }).catch(() => {})
      // Invalidar caches afectadas por la venta: dashboard (KPIs/widgets),
      // listado de transacciones, status de caja (montos efectivo).
      void qc.invalidateQueries({ queryKey: ["dashboard-widget"] })
      void qc.invalidateQueries({ queryKey: ["reports", "transactions"] })
      void qc.invalidateQueries({ queryKey: ["bff", "income-chart"] })
      void qc.invalidateQueries({ queryKey: ["drawer", "status"] })
      void qc.invalidateQueries({ queryKey: ["drawer", "summary"] })
    } catch (err) {
      // F5 (context/29 §5.6) — 409 de tenencia de caja: el backend
      // (`api/v1/sales.php`, F3 online) rechazó ESTE número porque la
      // tenencia de `register_lease` no es de este dispositivo. Distinto de
      // un error de negocio genérico: el cajero necesita saber QUIÉN tiene
      // la caja y CUÁNDO se libera, con un CTA de reintentar — no solo el
      // texto plano de `errorMsg`.
      if (err instanceof ApiError && err.status === 409) {
        // El 409 es información MÁS fresca que el grant local: persistirlo
        // deja al device sabiendo que perdió la caja aunque la red se corte
        // el segundo siguiente. Sin esto, el próximo intento (ya offline)
        // volvería a dejar vender.
        const info = extractRegisterConflictInfo(err)
        setRegisterTakenInfo(info)
        setRegisterTakenKind(null)
        setPhase("register-taken")
        if (activeRegisterId) void refreshTenancy(activeRegisterId)
      } else {
        setErrorMsg(
          err instanceof Error ? err.message : "Error al confirmar la venta",
        )
      }
    } finally {
      setSubmitting(false)
    }
  }

  // ── Aplicar un pago ───────────────────────────────────────────────────────
  /**
   * Aplica un pago y, si el remaining llega a 0, confirma la venta
   * automáticamente sin pedir un segundo click.
   *
   * `changeOverride` permite pasar el vuelto calculado externamente (Caso C
   * con hasChange), porque el pago se registra por `remaining` aunque el
   * cajero entregó `parsed` — la diferencia es el vuelto informativo.
   */
  async function applyPayment(
    method: PaymentMethodConfig,
    amount: number,
    identifier: string | null,
    changeOverride?: number,
  ) {
    // changeOverride tiene prioridad: permite registrar el vuelto cuando el
    // pago entra por `remaining` pero el cajero recibió más (medio con vuelto).
    const rowChange = changeOverride ?? 0
    const newApplied: AppliedPayment[] = [
      ...applied,
      { rowId: crypto.randomUUID(), method, amount, identifier, change: rowChange },
    ]
    const newAppliedTotal = newApplied.reduce((s, r) => s + r.amount, 0)
    const newRemaining = total - newAppliedTotal
    const newChange = newApplied.reduce((s, r) => s + r.change, 0)

    setApplied(newApplied)
    setDisplay("")

    // Auto-confirm SOLO en contado. En crédito la venta se cierra siempre con
    // "Confirmar venta" (el cajero puede querer revisar el vencimiento, o
    // sacar el pago que acaba de aplicar) — el comportamiento que el footer
    // del diálogo ya declaraba y que el auto-confirm contradecía cuando el
    // monto tipeado cubría el total.
    if (newRemaining <= 0 && !credito) {
      await handleConfirm(newApplied, newChange)
    }
    // Si newRemaining > 0: el cajero sigue agregando pagos
  }

  function tryApplyPayment(
    method: PaymentMethodConfig,
    amount: number,
    changeOverride?: number,
  ) {
    if (method.requiresIdentifier) {
      setPendingIdentifier({ method, amount, changeOverride })
    } else {
      void applyPayment(method, amount, null, changeOverride)
    }
  }

  function handleMethodClick(method: PaymentMethodConfig) {
    // Confirmación en vuelo: `handleConfirm` ya capturó los pagos, así que un
    // pago más acá se descartaría en silencio.
    if (submitting) return

    // Caja cerrada: frena TODOS los medios y los dos modos. El aviso vive en
    // este camino —el que comparten el toque y el hotkey— y no en una banda
    // del header que además solo se leía en crédito.
    if (drawerClosed) {
      toast.error("Abrí la caja antes de cobrar")
      return
    }

    // Total ya cubierto. Va ANTES de giftcard y de QR: los dos aplican plata y
    // sin este corte una giftcard sumaba pagos por encima del total de la
    // venta, y un medio QR devolvía sin decir nada.
    if (remaining <= 0) {
      if (!credito) {
        // Contado: la única forma de llegar acá con el total cubierto es un
        // confirm que falló (el que sale bien cierra el teclado). En contado
        // no hay CTA de confirmar, así que el toque REINTENTA la emisión — si
        // solo avisara, al cajero le quedaría cancelar y perder los pagos.
        void handleConfirm(applied, change)
        return
      }
      toast.info("El total ya está cubierto — confirmá la venta")
      return
    }

    if (method.systemKey === "giftcard") {
      setPendingGiftcard(true)
      return
    }

    // QR de pasarela: no se aplica el pago acá — se genera el QR, se muestra
    // (y se espeja en la pantalla del cliente) y el pago se aplica recién
    // cuando el PSP lo acredita. El monto sale de lo tipeado, o el restante.
    const pspAdapter = pspQrAdapterForSystemKey(method.systemKey)
    if (pspAdapter) {
      // Sin red no hay QR ni confirmación posible. Se avisa localmente y la
      // caja sigue: el efectivo (y cualquier medio manual) se encola offline
      // por el camino de siempre.
      if (typeof navigator !== "undefined" && navigator.onLine === false) {
        toast.error(
          `Sin conexión — el cobro con ${pspAdapter.title} necesita internet. Cobrá en efectivo o reintentá al volver la señal.`,
        )
        return
      }
      // Misma regla del visor que el resto de los medios: lo tipeado, o el
      // restante si no se tipeó nada. Antes acá se pedía el QR por lo TIPEADO
      // aunque excediera el total — un QR cobra de verdad, así que ese
      // excedente lo pagaba el cliente y había que devolverlo a mano.
      const { amount: qrAmount } = resolvePaymentAmount(parseDisplay(display), remaining)
      if (qrAmount <= 0) return
      setPendingQr({ adapter: pspAdapter, amount: qrAmount })
      return
    }

    // El monto SIEMPRE sale del visor, en contado y en crédito por igual —
    // incluido el visor vacío, que muestra el restante. La regla vive en
    // `resolvePaymentAmount` (lib/pos/payment-amount.ts), que explica por qué
    // dejó de estar duplicada por modo. El modo solo decide si la venta se
    // confirma sola al quedar cubierta (ver `applyPayment`).
    const { amount, change: overpay } = resolvePaymentAmount(
      parseDisplay(display),
      remaining,
    )

    // El vuelto solo se propaga si el medio lo acepta: con tarjeta, el
    // excedente no existe (se cobra el monto exacto) y avisarlo evita que el
    // cajero crea que tiene que devolver algo.
    if (overpay > 0 && !method.hasChange) {
      toast.info(`${method.name} no acepta vuelto — se aplicó el monto exacto`)
    }
    tryApplyPayment(
      method,
      amount,
      method.hasChange && overpay > 0 ? overpay : undefined,
    )
    setDisplay("")
  }

  // ── Captura global de keystrokes ──────────────────────────────────────────
  // Cualquier dígito / Backspace / letra de hotkey edita el visor o dispara
  // el método correspondiente sin importar si el focus está en un botón, el
  // body, etc. El input del visor ya maneja sus propios eventos cuando tiene
  // focus — el check de target evita doble disparo.
  React.useEffect(() => {
    if (!open || phase !== "pay") return

    function handleGlobalKey(e: KeyboardEvent) {
      const target = e.target as HTMLElement | null
      // Si el target ya es un input/textarea editable (incluido el visor),
      // dejá que ese elemento maneje el evento normalmente.
      if (target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA")) {
        return
      }

      // Dígito → append al display
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setDisplay((prev) => formatDisplayInput(prev + e.key, config))
        return
      }

      // Backspace: si hay dígitos en el visor, borra uno; si visor vacío,
      // elimina el último pago aplicado.
      if (e.key === "Backspace") {
        e.preventDefault()
        if (display === "") {
          setApplied((prev) => prev.slice(0, -1))
        } else {
          setDisplay((prev) => formatDisplayInput(prev.slice(0, -1), config))
        }
        return
      }

      // Hotkey letras (A/S/D…) → dispara el método correspondiente
      const key = e.key.toUpperCase()
      const matched = paymentMethods.find(
        (m) => m.code && m.code.toUpperCase() === key,
      )
      if (matched) {
        e.preventDefault()
        handleMethodClick(matched)
      }
    }

    window.addEventListener("keydown", handleGlobalKey)
    return () => window.removeEventListener("keydown", handleGlobalKey)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, phase, display, paymentMethods, config])

  // ── Input del visor ───────────────────────────────────────────────────────
  function handleDisplayChange(raw: string) {
    setDisplay(formatDisplayInput(raw, config))
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    // Backspace con visor vacío → elimina el último applied
    if (e.key === "Backspace" && display === "") {
      e.preventDefault()
      setApplied((prev) => prev.slice(0, -1))
      return
    }

    // Hotkeys de métodos (A/S/D/F…)
    const key = e.key.toUpperCase()
    const matched = paymentMethods.find(
      (m) => m.code && m.code.toUpperCase() === key,
    )
    if (matched) {
      e.preventDefault()
      handleMethodClick(matched)
    }
  }

  // ── Validación (solo para crédito, que no tiene auto-confirm) ────────────
  /**
   * Motivo por el que ESTA venta a crédito todavía no se puede confirmar, o
   * `null` si se puede.
   *
   * Es un texto y no tres booleanos porque su destino es el tooltip del propio
   * CTA: un impedimento se informa en el control que impide, no en una banda
   * que además empuja el layout del diálogo (regla del owner, `context/14`
   * R10). Las causas están ordenadas por lo que el cajero tiene que resolver
   * primero — sin cliente no tiene sentido hablar de si ese cliente tiene
   * crédito.
   *
   * `isCreditable` es un gate real, no cosmético: el backend
   * (`SaleService::save`) lo valida igual; decirlo acá evita el round-trip.
   */
  const creditBlockedReason: string | null = !credito
    ? null
    : !customer
      ? "Elegí un cliente para vender a crédito"
      : !customer.isCreditable
        ? `${customer.name} no tiene crédito habilitado`
        : drawerClosed
          ? "Abrí la caja antes de cobrar"
          : null
  const creditSaleReady = credito && creditBlockedReason === null && !submitting

  /**
   * Motivo por el que los medios de pago no pueden cargar plata ahora — para
   * pintarlos apagados con la explicación en vez de dejarlos mudos.
   *
   * Contado con el total cubierto NO figura: ese toque no está impedido, es el
   * reintento de una emisión que falló (ver `handleMethodClick`).
   */
  const payBlockedHint: string | null = drawerClosed
    ? "Abrí la caja antes de cobrar"
    : credito && remaining <= 0
      ? "El total ya está cubierto"
      : null

  function handleCreditConfirm() {
    if (!creditSaleReady) return
    // `change` y no 0: en crédito el vuelto de un cobro inicial se calculó al
    // aplicar el pago (ver `applyPayment`), no acá.
    void handleConfirm(applied, change)
  }

  async function handlePrint() {
    if (!saleResult) return
    // Rebuild minimal payload for reprint — real payload is local to handleConfirm.
    // Los descuentos se reparten igual que en la venta original (misma función):
    // con `discount: 0` fijo, el ticket reimpreso no mostraba ningún descuento.
    const reprLines = withLineTax(lines)
    const reprAllocations = allocateLineDiscounts(reprLines, saleDiscount, ivaRemoved)
    const reprPayload = {
      uid: "",
      type: credito ? 3 : 0,
      sale: reprLines.map((line, i) => ({
        itemId: line.itemId,
        name: line.name,
        count: line.qty,
        // Mismo neteo que la venta original: con `ivaRemoved` el total de la
        // línea va sin IVA, así que el unitario también — si no, el ticket
        // reimpreso muestra un precio con IVA y un total sin él.
        price: lineGross(line.unitPrice, ivaRemoved, line.tax),
        total: reprAllocations[i].gross,
        discount: reprAllocations[i].effectivePercent,
        totalDiscount: reprAllocations[i].totalDiscount,
        note: line.note ?? null,
        tags: line.tags ?? [],
      })),
      payment: [] as import("@/lib/commands/create-sale").SalePaymentMethod[],
      subtotal: saleResult.total,
      tax: 0,
      discount: reprAllocations.reduce((s, a) => s + a.totalDiscount, 0),
      ivaRemoved,
      client: customer?.id ?? null,
      user: null,
      note: null,
      interno: false,
      tags: [] as string[],
      date: new Date().toISOString(),
      timestamp: Math.floor(Date.now() / 1000),
      // Reimpresión: el número real ya está en `saleResult.invoiceNumber`
      // (buildTicketData lo lee de `result`, no de `payload` — ver piece 4).
      // Este campo solo existe para satisfacer el tipo del payload de venta.
      invoiceno: saleResult.invoiceNumber ? Number(saleResult.invoiceNumber) : 0,
    } satisfies import("@/lib/commands/create-sale").CreateSalePayload
    const ticketData = buildTicketData({ payload: reprPayload, result: saleResult, config })
    // Venta al contado/crédito SIEMPRE emite Factura — el recibo es el
    // documento del pago de una factura a crédito (regla fiscal), NO un
    // fallback cuando falta el binding "factura". EXCEPCIÓN: emisión de gift
    // card = adelanto → Recibo (ver comentario espejo en handleConfirm arriba).
    const hasGiftcardIssuance = lines.some((l) => !!l.giftcard)
    const printDocType: PrinterDocType = hasGiftcardIssuance ? "receipt" : "factura"
    // `requestPrint` es el wrapper compartido (print-with-fallback.ts) que ya
    // usan el diálogo de transacciones y el drawer de opciones: si hay binding
    // para el documento imprime derecho; si NO hay pero sí hay impresoras
    // configuradas abre el SELECTOR para elegir a mano; y sin impresoras cae al
    // diálogo nativo del browser. Antes acá solo se tiraba un toast de "ninguna
    // impresora tiene asignado el documento" y el cajero quedaba sin forma de
    // imprimir — teniendo la impresora enchufada al lado.
    requestPrint(printDocType, ticketData, allBindings)
  }

  // Botón manual "Ordenar" del modal de éxito (ordenEnVenta) — genera la
  // orden espejo de la venta recién facturada a pedido del cajero. La orden
  // nace YA PAGADA (transactionId en el create) en vez de nacer 'open' y
  // cerrarse después con markOrderPaid(): ese segundo paso forzaba
  // status='closed', y el listado activo del POS (ACTIVE_ORDER_STATUSES)
  // excluye 'closed' — la orden quedaba pagada pero invisible para caja y
  // cocina. Un solo write, sin ventana donde exista impaga NI ventana donde
  // exista cerrada-antes-de-producir. La venta ya está cerrada — un fallo acá
  // no la afecta.
  async function handleOrdenar() {
    if (!orderDraft) return
    try {
      await createOrder.mutateAsync({
        source: "counter",
        items: orderDraft.lines.map((l) => ({
          itemId: l.itemId,
          qty: l.qty,
          price: l.unitPrice,
          note: l.note,
          tags: l.tags,
        })),
        customerId: orderDraft.customerId,
        note: orderDraft.note ?? undefined,
        sendNow: true,
        transactionId: orderDraft.transactionId,
      })
      toast.success("Orden generada")
    } catch (e) {
      toast.error("No se pudo generar la orden", {
        description: e instanceof Error ? e.message : undefined,
      })
      throw e
    }
  }

  function handleClose() {
    if (phase === "success") {
      setOrderDraft(null)
      clearCart() // clear() ya resetea quoteParentId/orderParentId via initialState (+ relock modoSoloOrdenes)
      void posApi.post("/v1/screens?resource=publish", {
        type: "cart-cleared",
        data: {},
      }).catch(() => {})
    } else {
      // Venta abandonada — limpiar quoteParentId para que la próxima venta no herede el parent
      setQuoteParent(null)
    }
    onOpenChange(false)
  }

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <>
      {/* Selector de impresora del botón manual — sin montarlo acá, requestPrint
          no tendría dónde mostrarlo cuando no hay binding para el documento. */}
      {pickerDialog}
      <Dialog open={open} onOpenChange={(o) => { if (!o) handleClose() }}>
        <DialogContent
          sectioned
          className={cn(
            // max-w-lg también en la fase de pago: la grilla de métodos es de
            // 3 columnas (2026-08-08) y con max-w-md los nombres se truncaban.
            "max-h-[90vh] sm:max-w-lg",
          )}
        >
          {phase === "pay" ? (
            <PayPhase
              total={total}
              credito={credito}
              customer={customer}
              creditBlockedReason={creditBlockedReason}
              payBlockedHint={payBlockedHint}
              applied={applied}
              display={display}
              displayRef={displayRef}
              remaining={remaining}
              appliedTotal={appliedTotal}
              submitting={submitting}
              errorMsg={errorMsg}
              config={config}
              paymentMethods={paymentMethods}
              currencies={currencies}
              dueDate={dueDate}
              onDueDateChange={setDueDate}
              onDisplayChange={handleDisplayChange}
              onKeyDown={handleKeyDown}
              onMethodClick={handleMethodClick}
              onRemoveApplied={(rowId) =>
                setApplied((prev) => prev.filter((r) => r.rowId !== rowId))
              }
              onCreditConfirm={handleCreditConfirm}
              onCancel={handleClose}
            />
          ) : phase === "register-taken" ? (
            <RegisterTakenPhase
              info={registerTakenInfo}
              kind={registerTakenKind}
              registerId={activeRegisterId}
              onRetry={() => {
                // El gate se re-evalúa ACÁ, después de que la pantalla pidió
                // la tenencia: si el servidor volvió a decir que no, el cajero
                // se queda donde está con el motivo actualizado (pudo pasar de
                // "sin confirmar" a "tomada por otro"). Antes el reintento
                // devolvía al teclado igual y el rechazo reaparecía recién al
                // confirmar, con el medio de pago ya cargado.
                const stillBlocked = tenancyBlock(useTenancyStore.getState().verdict)
                if (stillBlocked) {
                  setRegisterTakenInfo(stillBlocked.info)
                  setRegisterTakenKind(stillBlocked.kind)
                  return
                }
                setPhase("pay")
                setRegisterTakenInfo(null)
                setRegisterTakenKind(null)
                // Abrió bloqueado: no hay pagos cargados todavía, así que el
                // reintento es simplemente entrar al teclado de cobro.
                // Confirmar acá emitiría una venta sin pagos.
                if (blockedBeforePay) {
                  setBlockedBeforePay(false)
                  return
                }
                void handleConfirm(applied, change)
              }}
              onCancel={handleClose}
            />
          ) : (
            <TransactionSuccessView
              title="¡Venta confirmada!"
              amount={formatMoney(total, config)}
              changeAmount={change > 0 ? formatMoney(change, config) : undefined}
              badge={
                saleResult?.duplicated ? (
                  <Badge variant="outline" className="text-[10px] opacity-70">
                    ya guardada previamente — uid idempotente
                  </Badge>
                ) : undefined
              }
              closeLabel="Nueva venta"
              onPrint={handlePrint}
              onClose={handleClose}
              secondaryAction={
                orderDraft ? { label: "Ordenar", onAction: handleOrdenar } : undefined
              }
            />
          )}
        </DialogContent>
      </Dialog>

      {/* Sub-diálogo de identificador (fuera del diálogo principal para evitar nesting) */}
      <PaymentIdentifierDialog
        open={pendingIdentifier !== null}
        method={pendingIdentifier?.method ?? null}
        amount={pendingIdentifier?.amount ?? 0}
        config={config}
        onConfirm={(identifier) => {
          if (pendingIdentifier) {
            void applyPayment(
              pendingIdentifier.method,
              pendingIdentifier.amount,
              identifier,
              pendingIdentifier.changeOverride,
            )
          }
          setPendingIdentifier(null)
        }}
        onCancel={() => setPendingIdentifier(null)}
      />

      {/* QR de pasarela — el pago se aplica cuando el PSP lo acredita, no al
          abrir el diálogo (ver PspQrDialog). */}
      <PspQrDialog
        open={pendingQr !== null}
        adapter={pendingQr?.adapter ?? null}
        amount={pendingQr?.amount ?? 0}
        saleAmount={total}
        config={config}
        onPaid={(uid, paidAmount) => {
          const systemKey = pendingQr?.adapter.systemKey
          const qrMethod = paymentMethods.find((m) => m.systemKey === systemKey)
          setPendingQr(null)
          if (!qrMethod) {
            toast.error("Falta el medio de pago del QR en el catálogo")
            return
          }
          // El UID queda como identificador del pago: es la llave con la que
          // se concilia contra vPayments.
          void applyPayment(qrMethod, paidAmount, uid)
          setDisplay("")
        }}
        onCancel={() => setPendingQr(null)}
      />

      <GiftcardValidationDialog
        open={pendingGiftcard}
        total={total}
        config={config}
        onApply={(code, amount) => {
          const gcMethod = paymentMethods.find((m) => m.systemKey === "giftcard")
          if (gcMethod) {
            // Tope en el restante: el saldo de la giftcard puede superar lo
            // que falta cobrar, y una giftcard no da vuelto — el excedente
            // sigue siendo saldo de la tarjeta, no plata que entra a la venta.
            const { amount: gcAmount } = resolvePaymentAmount(amount, remaining)
            void applyPayment(gcMethod, gcAmount, code)
          }
          setPendingGiftcard(false)
        }}
        onCancel={() => setPendingGiftcard(false)}
      />
    </>
  )
}

// ── Fase de pago ──────────────────────────────────────────────────────────────

interface PayPhaseProps {
  total: number
  credito: boolean
  customer: ReturnType<typeof useCartStore.getState>["customer"]
  /** Por qué no se puede confirmar la venta a crédito, o `null` si se puede. */
  creditBlockedReason: string | null
  /**
   * Por qué los medios de pago no pueden cargar plata ahora, o `null`. Se
   * pinta en cada botón (apagado + motivo). En CONTADO con el total ya
   * cubierto es `null` a propósito: ahí el toque no está bloqueado, reintenta
   * la emisión que falló.
   */
  payBlockedHint: string | null
  applied: AppliedPayment[]
  display: string
  displayRef: React.RefObject<HTMLInputElement | null>
  remaining: number
  appliedTotal: number
  submitting: boolean
  errorMsg: string | null
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  paymentMethods: PaymentMethodConfig[]
  currencies: Array<{ ccode: string; code: string; value: number }>
  dueDate: string
  onDueDateChange: (value: string) => void
  onDisplayChange: (raw: string) => void
  onKeyDown: (e: React.KeyboardEvent<HTMLInputElement>) => void
  onMethodClick: (method: PaymentMethodConfig) => void
  onRemoveApplied: (rowId: string) => void
  onCreditConfirm: () => void
  onCancel: () => void
}

function PayPhase({
  total,
  credito,
  customer,
  creditBlockedReason,
  payBlockedHint,
  applied,
  display,
  displayRef,
  remaining,
  appliedTotal,
  submitting,
  errorMsg,
  config,
  paymentMethods,
  currencies,
  dueDate,
  onDueDateChange,
  onDisplayChange,
  onKeyDown,
  onMethodClick,
  onRemoveApplied,
  onCreditConfirm,
  onCancel,
}: PayPhaseProps) {
  const primaryMethods = paymentMethods.filter(
    (m) => !m.systemKey || !SECONDARY_SYSTEM_KEYS.includes(m.systemKey),
  )
  const secondaryMethods = paymentMethods.filter(
    (m) => m.systemKey && SECONDARY_SYSTEM_KEYS.includes(m.systemKey),
  )
  const activeCurrencies = currencies.filter((c) => c.value > 0)
  // Estado de red: los medios de pasarela (QR) no pueden operar sin internet.
  const isOnline = useOnlineStatus()
  // El visor muestra lo tipeado si hay algo; si no, muestra el remaining.
  // Esto se logra con placeholder: el input está vacío y el placeholder
  // es el remaining formateado — visualmente se lee como el monto a cobrar.
  const placeholderAmount = remaining > 0 ? remaining : 0
  const placeholderText = formatMoney(placeholderAmount, config)

  return (
    <>
      {/* Header — label + badge de modo */}
      <DialogHeader className="pb-3">
        <DialogTitle className="sr-only">Cobro</DialogTitle>

        <span className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
          {credito ? "Total a pagar · Crédito" : "Total a pagar · Contado"}
        </span>

        {/* Los impedimentos de la venta a crédito (sin cliente, cliente sin
            crédito, caja cerrada) NO se pintan acá: viajan al tooltip del CTA
            "Confirmar venta", que es el control que impide. Dos bandas
            condicionales en el header además movían el visor y los métodos de
            pago hacia abajo según el estado — justo lo que la Regla #10
            prohíbe (memoria muscular del cajero). */}

        {credito && customer && (
          <div className="mt-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">{customer.name}</span>
            {customer.tin && (
              <span className="ml-1.5 text-muted-foreground">{customer.tin}</span>
            )}
          </div>
        )}

        {credito && (
          <div className="mt-2">
            <span className="mb-1 block text-[11px] font-medium text-muted-foreground">
              Vencimiento (opcional)
            </span>
            <DatePicker
              value={dueDate}
              onChange={onDueDateChange}
              placeholder="Sin vencimiento"
              className="h-9"
            />
          </div>
        )}

      </DialogHeader>

      {/* Visor unificado — display del remaining Y input numérico editable.
          Cuando el cajero no tipeó nada, el placeholder muestra el remaining
          formateado. Al tipear, el visor cambia al monto ingresado en tiempo real.
          Autofocus garantiza que el teclado físico funcione desde el primer gesto. */}
      <div className="shrink-0 px-6 py-4">
        <input
          ref={displayRef}
          type="text"
          inputMode="numeric"
          value={display}
          onChange={(e) => onDisplayChange(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder={placeholderText}
          className={cn(
            "w-full bg-transparent text-center tabular-nums outline-none caret-transparent",
            "text-5xl font-black text-foreground",
            // Placeholder muestra el remaining con el mismo estilo que el texto
            // tipeado — visualmente es un solo visor (no parece un campo vacío)
            "placeholder:text-foreground",
          )}
          aria-label="Monto a cobrar"
        />
      </div>

      {/* Conversión multi-moneda — read-only, debajo del total */}
      {activeCurrencies.length > 0 && (
        <div className="mt-1 flex flex-wrap items-center justify-center gap-x-3 gap-y-0.5 pb-3 text-xs text-muted-foreground">
          {activeCurrencies.map((c) => (
            <span key={c.code} className="tabular-nums">
              {c.code} {formatCurrencyAmount(total / c.value, c.code)}
            </span>
          ))}
        </div>
      )}

      <Separator />

      {/* Cuerpo scrolleable */}
      <div className="flex-1 overflow-y-auto px-6 py-4 space-y-3">

        {/* Pagos ya aplicados */}
        {applied.length > 0 && (
          <div className="space-y-1.5">
            {applied.map((r) => (
              <div
                key={r.rowId}
                className="flex items-center gap-2 rounded-md bg-muted px-3 py-1.5 text-sm"
              >
                <span className="font-medium">{r.method.name}</span>
                {r.identifier && (
                  <span className="text-xs text-muted-foreground">· {r.identifier}</span>
                )}
                <span className="ml-auto tabular-nums font-semibold">
                  {formatMoney(r.amount, config)}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-6 text-muted-foreground hover:text-destructive"
                  onClick={() => onRemoveApplied(r.rowId)}
                  aria-label="Quitar pago"
                >
                  <X className="size-3" />
                </Button>
              </div>
            ))}
          </div>
        )}

        {/* Faltante (visible solo cuando hay pagos parciales en contado) */}
        {!credito && remaining > 0 && appliedTotal > 0 && (
          <div className="flex items-center justify-between rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
            <span className="text-xs font-semibold text-destructive">Faltante</span>
            <span className="text-sm font-bold tabular-nums text-destructive">
              {formatMoney(remaining, config)}
            </span>
          </div>
        )}

        {/* Queda a cuenta (crédito) */}
        {credito && customer && (
          <div className="flex items-center justify-between rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">
            <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">
              Queda a cuenta
            </span>
            <span className="text-sm font-bold tabular-nums text-amber-600 dark:text-amber-400">
              {remaining > 0 ? formatMoney(remaining, config) : "—"}
            </span>
          </div>
        )}

        {/* Grilla ÚNICA de métodos — 3 columnas (modal max-w-lg desde
            2026-08-08), botones de alto táctil uniforme. TODOS outline: el
            variant "default" por isDefault pintaba de oscuro los métodos con
            systemKey (Efectivo/Cheque/Giftcard) y parecía un color elegido —
            el único acento de color es el borde izquierdo, que SÍ viene del
            color configurado en el panel. Un solo bloque integrado (sin
            Separator, reporte del owner 2026-08-01): los secundarios
            (Interno/Giftcard) al final con texto atenuado. */}
        <div className="grid grid-cols-3 gap-2">
          {[...primaryMethods, ...secondaryMethods].map((m) => {
            const accent = resolveColorBg(m.color)
            const secondary = !!(m.systemKey && SECONDARY_SYSTEM_KEYS.includes(m.systemKey))
            // Cobro por QR sin red: el botón se pinta apagado EN SU LUGAR —
            // no se saca de la grilla, que movería los demás y rompería la
            // memoria muscular del cajero. Sigue siendo clickeable a
            // propósito (`aria-disabled`, no `disabled`): el click explica el
            // motivo con un aviso local en vez de no hacer nada.
            const pspOffline = !isOnline && isPspQrSystemKey(m.systemKey)
            // El botón que no puede cargar plata se apaga EN SU LUGAR y dice
            // por qué, sin `disabled` real: un `disabled` de verdad no recibe
            // el toque, y en tablet no hay hover — el cajero se quedaba con un
            // botón muerto que no explica nada. El motivo definitivo lo da
            // `onMethodClick`, que es también el camino del hotkey.
            const blockedHint = pspOffline
              ? "Necesita conexión a internet"
              : (payBlockedHint ?? undefined)
            // Contado con el total cubierto: el botón NO está impedido (no se
            // apaga), pero tampoco hace lo de siempre — reintenta la emisión
            // que falló. El tooltip lo dice en vez de dejar que el cajero
            // toque Efectivo esperando cargar plata.
            const retryHint =
              !blockedHint && !credito && remaining <= 0
                ? "Reintentar la emisión"
                : undefined
            return (
            <Button
              key={m.id}
              variant="outline"
              className={cn(
                "h-10 justify-center gap-1.5 border-l-4 px-2 text-xs font-medium",
                secondary && "text-muted-foreground",
                blockedHint && "opacity-50",
              )}
              style={accent ? { borderLeftColor: accent } : undefined}
              onClick={() => onMethodClick(m)}
              aria-disabled={blockedHint ? true : undefined}
              title={blockedHint ?? retryHint}
            >
              <span className="truncate">{m.name}</span>
              {m.code && (
                <kbd className="pointer-events-none inline-flex h-4 select-none items-center rounded border border-border/60 bg-background/60 px-1 font-mono text-[10px] font-medium text-muted-foreground">
                  {m.code}
                </kbd>
              )}
            </Button>
            )
          })}
        </div>

        {/* Error */}
        {errorMsg && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {errorMsg}
          </div>
        )}
      </div>

      <Separator />

      {/* Footer — solo Cancelar para contado (auto-confirm elimina el segundo click)
          Para crédito, se mantiene "Confirmar venta" porque no hay auto-confirm
          (el cajero puede querer registrar sin cobro inicial). */}
      <div className="shrink-0 flex gap-2 px-6 py-4">
        <Button
          variant="outline"
          className={cn("flex-1", credito ? "flex-1" : "flex-[1]")}
          onClick={onCancel}
          disabled={submitting}
        >
          Cancelar
        </Button>
        {credito && (
          <CreditConfirmCta
            blockedReason={creditBlockedReason}
            submitting={submitting}
            onClick={onCreditConfirm}
          />
        )}
      </div>
    </>
  )
}

/**
 * CTA "Confirmar venta" del cobro a crédito.
 *
 * Mismo patrón que `PayCta` (cart-panel.tsx): cuando la venta no se puede
 * confirmar, el botón se pinta apagado EN SU LUGAR y el tooltip dice por qué,
 * en vez de una banda arriba que empuja el layout (regla del owner, pedida
 * tres veces). `aria-disabled` y no `disabled`: un botón realmente
 * deshabilitado no recibe eventos de puntero, así que ni dispararía el
 * tooltip; queda inerte para lo que importa —no confirma— porque el click
 * pasa por `handleCreditConfirm`, que corta con `creditSaleReady`.
 *
 * El estado "Procesando..." sigue siendo un `disabled` real: ahí no hay nada
 * que explicar y el segundo tap tiene que rebotar.
 */
function CreditConfirmCta({
  blockedReason,
  submitting,
  onClick,
}: {
  blockedReason: string | null
  submitting: boolean
  onClick: () => void
}) {
  const button = (
    <Button
      disabled={submitting}
      aria-disabled={blockedReason ? true : undefined}
      // En tablet no hay hover, así que el toque tiene que decir lo mismo que
      // el tooltip — si no, el CTA vuelve a ser un botón mudo.
      onClick={() => {
        if (blockedReason) {
          toast.info(blockedReason)
          return
        }
        onClick()
      }}
      className={cn(
        "flex-[2] font-bold transition-all active:scale-[0.98]",
        blockedReason &&
          "bg-muted text-muted-foreground hover:bg-muted hover:text-muted-foreground active:scale-100",
      )}
      aria-label={blockedReason ? `Confirmar venta — ${blockedReason}` : "Confirmar venta"}
    >
      {submitting ? "Procesando..." : "Confirmar venta"}
    </Button>
  )

  if (!blockedReason) return button

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>{button}</TooltipTrigger>
        <TooltipContent side="top">{blockedReason}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

// ── Fase "caja tomada" ────────────────────────────────────────────────────────
// F5 (context/29-numeracion-y-exclusividad-de-caja.md §5.6): pantalla
// bloqueante cuando el backend rechaza el número con 409 porque la tenencia
// de `register_lease` no es de este dispositivo. Reemplaza el contenido del
// Dialog entero (mismo patrón que la fase "success") en vez de un aviso
// chico: "nunca permitir vender sin numeración válida" pide algo más difícil
// de ignorar que el bloque de `errorMsg`, con el motivo real y un CTA de
// reintentar explícito (con el total ya cubierto los botones de pago no
// vuelven a cargar plata, así que sin este CTA el cajero no tendría forma de
// reintentar sin cerrar el dialog entero y perder los pagos).

interface RegisterTakenPhaseProps {
  info: RegisterConflictInfo | null
  /** Veredicto local cuando el bloqueo no vino de un 409 (caso offline). */
  kind: TenancyVerdictKind | null
  registerId: string
  onRetry: () => void
  onCancel: () => void
}

function RegisterTakenPhase({ info, kind, registerId, onRetry, onCancel }: RegisterTakenPhaseProps) {
  const expiresLabel = info?.expiresAt ? formatDateTime(info.expiresAt) : null
  // Una causa, un mensaje: "tomada por otro" / "la liberaron" / "se cerró" /
  // "sin confirmar" son cuatro situaciones con remedios distintos, y el cajero
  // necesita saber cuál le tocó (ver lib/pos/register-conflict.ts).
  const { title, body } = registerConflictMessage(info, expiresLabel, kind ?? undefined)
  const refreshing = useTenancyStore((s) => s.refreshing)

  const isOnline = React.useSyncExternalStore(
    subscribeOnlineStatus,
    () => navigator.onLine,
    () => true,
  )

  // "Tomada por otro" es el único caso que el device no puede resolver solo:
  // no hay nada que reintentar hasta que alguien la libere. Los demás se
  // arreglan reconfirmando la tenencia, así que el CTA primero la reconfirma y
  // recién entonces reintenta el cobro — y sin conexión ni eso es posible.
  const takenByOther = Boolean(info?.holderDeviceId) || info?.reason === "taken_by_other"
  const canRetry = !takenByOther && isOnline

  async function handleRetry() {
    // Reconfirmar ANTES de reintentar: el gate lee el grant local, así que sin
    // esto el reintento chocaría contra el mismo veredicto viejo.
    await refreshTenancy(registerId)
    onRetry()
  }

  return (
    <div className="flex flex-col items-center gap-5 px-6 py-8 text-center">
      <DialogTitle className="sr-only">{title}</DialogTitle>
      <Lock className="size-16 text-destructive" strokeWidth={1.5} />
      <div className="flex flex-col items-center gap-2">
        <h2 className="text-xl font-bold text-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{body}</p>
        {!isOnline && !takenByOther && (
          <p className="text-sm text-muted-foreground">
            Este dispositivo está sin conexión, así que no puede confirmar la
            caja ahora. El carrito queda como está.
          </p>
        )}
      </div>
      <div className="flex w-full gap-3">
        <Button variant="outline" className="flex-1" onClick={onCancel}>
          Cancelar
        </Button>
        <Button
          className="flex-1 font-bold"
          onClick={() => void handleRetry()}
          disabled={!canRetry || refreshing}
        >
          {refreshing ? "Confirmando..." : "Tomar caja y reintentar"}
        </Button>
      </div>
    </div>
  )
}

/** `navigator.onLine` como external store — misma primitiva que usa
 *  `OfflineStatusPill`: el valor vive afuera de React y suscribirse da el
 *  estado real en el primer paint, sin un frame intermedio en "online". */
function subscribeOnlineStatus(onChange: () => void): () => void {
  window.addEventListener("online", onChange)
  window.addEventListener("offline", onChange)
  return () => {
    window.removeEventListener("online", onChange)
    window.removeEventListener("offline", onChange)
  }
}

