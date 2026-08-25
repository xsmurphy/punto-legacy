"use client"

/**
 * Panel derecho del register — carrito de venta.
 *
 * Fusión 2026-06-16 (POS dentro de frontend):
 *   - El sidebar izquierdo es el AppSidebar del panel (NO un icon-rail propio).
 *   - La toolbar superior con las acciones propias de la caja (buscar
 *     producto / cliente / menú de acciones de venta) vuelve a vivir DENTRO
 *     del CartPanel — es lo único "propio de la caja".
 *   - Cart row expandido NO copia la grilla 4+5 cols del legacy. Usa
 *     stepper [−][qty][+] + acciones rápidas (vendedor / quitar / más).
 *   - Botón cobrar: `rounded-full` verde brand `bg-brand`.
 *   - Apertura de dialogs vía `usePosUIStore`.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  User,
  X,
  Plus,
  Minus,
  Percent,
  DollarSign,
  Tag,
  MessageSquare,
  MoreHorizontal,
  Search,
  LayoutGrid,
  Move,
  Palette,
  Check,
  StickyNote,
  UserCircle2,
  Ticket,
  ListPlus,
} from "lucide-react"
import { MODE_VISUALS, resolveCartMode, type CartModeKey } from "@/lib/pos/mode-visuals"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { SidebarTrigger } from "@/components/ui/sidebar"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Drawer,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
} from "@/components/ui/drawer"
import { QtyEditDialog } from "@/components/register/qty-edit-dialog"
import { LinePriceDialog } from "@/components/register/line-price-dialog"
import { LineDiscountDialog } from "@/components/register/line-discount-dialog"
import { LineNoteDialog } from "@/components/register/line-note-dialog"
import { LineTagsDialog } from "@/components/register/line-tags-dialog"
import { LineSellerDialog } from "@/components/register/line-seller-dialog"
import { cn } from "@/lib/utils"
import {
  useCartStore,
  selectCartTotal,
  selectCartIva,
  selectSaleDiscountAmount,
  lineSubtotal,
  type CartLine,
} from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { addCatalogItem } from "@/lib/cart/add-catalog-item"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useBarcodeScanner } from "@/hooks/use-barcode-scanner"
import { formatMoney, formatAmount } from "@/lib/format-money"
import { usePosUIStore } from "@/lib/ui/store"
import { ProductSearchDialog } from "@/components/register/product-search-dialog"
import { CustomerDialog } from "@/components/register/customer-dialog"
import { PayDialog } from "@/components/register/pay-dialog"
import { GiftcardIssueDialog } from "@/components/register/giftcard-issue-dialog"
import { AddonPickerDialog } from "@/components/register/addon-picker-dialog"
import { useAddonPickerStore } from "@/lib/cart/addon-picker-store"
import { SaleOptionsDrawer } from "@/components/register/sale-options-drawer"
import { TransactionSuccessDialog } from "@/components/register/transaction-success-dialog"
import { PosMainMenu } from "@/components/register/pos-main-menu"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { toast } from "sonner"
import { useCartPublisher } from "@/hooks/use-cart-publisher"
import { useDrawerStatus } from "@/hooks/use-drawer"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"
import { DrawerOpenDialog } from "@/components/register/drawer-open-dialog"
import { OfflineStatusPill } from "@/components/pos/offline-status-pill"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { useTenancyStore } from "@/lib/pos/tenancy-store"
import { registerBlockShortReason } from "@/lib/pos/register-conflict"
import { useCreateOrder, type Order, type Fulfillment } from "@/hooks/use-orders"
import { useClearCart } from "@/hooks/use-clear-cart"
import { useOutsidePointerDown } from "@/hooks/use-outside-pointerdown"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { posApi } from "@/lib/api/pos-client"
import { printOrderComandas } from "@/lib/orders/print-comandas"
import { FulfillmentSelector } from "@/components/register/fulfillment-selector"
import { ToggleChip } from "@/components/register/toggle-chip"
import { DeliveryAddressDialog } from "@/components/register/delivery-address-dialog"

// ── CartPanel raíz ────────────────────────────────────────────────────────────

export function CartPanel() {
  const router = useRouter()
  const lines = useCartStore((s) => s.lines)
  const selectedLineId = useCartStore((s) => s.selectedLineId)
  const customer = useCartStore((s) => s.customer)
  const priceListName = useCartStore((s) => s.priceListName)
  const note = useCartStore((s) => s.note)
  const spaceSessionId = useCartStore((s) => s.spaceSessionId)
  const spaceName = useCartStore((s) => s.spaceName)
  const clearSelectedSpace = useCartStore((s) => s.clearSelectedSpace)
  const fulfillment = useCartStore((s) => s.fulfillment)
  const deliveryAddress = useCartStore((s) => s.deliveryAddress)
  const setFulfillment = useCartStore((s) => s.setFulfillment)
  const setDeliveryAddress = useCartStore((s) => s.setDeliveryAddress)
  const credito = useCartStore((s) => s.credito)
  const interno = useCartStore((s) => s.interno)
  const ivaRemoved = useCartStore((s) => s.ivaRemoved)
  const total = useCartStore(selectCartTotal)
  const iva = useCartStore(selectCartIva)
  const posMode = useCartStore((s) => s.posMode)
  const setPosMode = useCartStore((s) => s.setPosMode)
  const clearCart = useClearCart()
  const toggleCredito = useCartStore((s) => s.toggleCredito)
  const toggleInterno = useCartStore((s) => s.toggleInterno)
  const toggleIva = useCartStore((s) => s.toggleIva)
  const selectLine = useCartStore((s) => s.selectLine)
  const removeLine = useCartStore((s) => s.removeLine)
  const incQty = useCartStore((s) => s.incQty)
  const decQty = useCartStore((s) => s.decQty)
  const setQty = useCartStore((s) => s.setQty)
  useCartPublisher()

  const config = useCatalogStore((s) => s.config)
  const catalogItems = useCatalogStore((s) => s.items)

  // Gate de apertura de caja — se abre si la caja está cerrada al intentar cobrar.
  const { data: drawerStatus } = useDrawerStatus()
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: configData } = usePosRegisterConfig(activeRegisterId)
  const controlCaja = configData?.config?.controlCaja ?? true
  const modoSoloOrdenes = configData?.config?.modoSoloOrdenes ?? false
  const ordenAImpresion = configData?.config?.ordenAImpresion ?? false
  const [drawerOpenDialogOpen, setDrawerOpenDialogOpen] = React.useState(false)

  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  const createOrder = useCreateOrder()

  // Modo edición de hotkeys: el panel de venta muestra una guía en su lugar.
  const editingHotkeys = useHotkeysStore((s) => s.editing)

  // Lock screen: cuando está activo el scanner debe estar pausado.
  const locked = useLockStore((s) => s.locked)

  // Estado de dialogs — compartido con el PosSidebar via store global.
  const searchOpen = usePosUIStore((s) => s.searchOpen)
  const setSearchOpen = usePosUIStore((s) => s.setSearchOpen)
  const customerOpen = usePosUIStore((s) => s.customerOpen)
  const setCustomerOpen = usePosUIStore((s) => s.setCustomerOpen)
  const payOpen = usePosUIStore((s) => s.payOpen)
  const setPayOpen = usePosUIStore((s) => s.setPayOpen)
  // Cotización en vuelo — única ventana en la que ese "modo" existe (no es un
  // posMode sticky, ver lib/pos/mode-visuals.ts). Pinta CTA + banda amber.
  const savingQuote = usePosUIStore((s) => s.savingQuote)

  // modoSoloOrdenes: el POS arranca y queda lockeado en modo orden — si el
  // toggle está prendido y el carrito no está ya en "orden" (arranque, o el
  // toggle se activó mid-shift), lo forzamos. clear() con el toggle activo ya
  // re-lockea vía useClearCart — este effect cubre el caso inicial/reactivo.
  //
  // El lock NO pisa un cobro en curso: con el diálogo de pago abierto la caja
  // está facturando y el modo tiene que quedarse en venta hasta que termine,
  // en vez de pintar el carrito de verde con el CTA "Ordenar" detrás del
  // diálogo. El re-lock vuelve a correr al cerrar el cobro (o vía
  // useClearCart cuando la venta se confirma).
  //
  // Ojo: esto NO habilita facturar con `modoSoloOrdenes` prendido. Ese flag
  // oculta facturación por diseño y el lock gana antes de que el cobro se
  // pueda abrir — cobrar una comanda desde ese POS sigue sin ser posible, que
  // es lo que el flag promete.
  React.useEffect(() => {
    if (modoSoloOrdenes && posMode !== "orden" && !payOpen) {
      setPosMode("orden")
    }
  }, [modoSoloOrdenes, posMode, setPosMode, payOpen])

  const cartMode: CartModeKey = resolveCartMode(posMode, spaceName, savingQuote)

  // ── Flujo "Envío" del selector de fulfillment (context/27 §D.4) ────────────
  // Elegir "Envío" sin cliente en el carrito abre PRIMERO CustomerDialog; una
  // vez elegido el cliente (o si ya había uno), se abre DeliveryAddressDialog.
  // `pendingDeliveryFlow` recuerda que el CustomerDialog se abrió como parte
  // de este flujo (no por el botón normal de cliente) — si el cajero lo
  // cierra sin elegir cliente, el intento de "Envío" se descarta sin tocar
  // el fulfillment (nunca optimista).
  const [deliveryDialogOpen, setDeliveryDialogOpen] = React.useState(false)
  const [pendingDeliveryFlow, setPendingDeliveryFlow] = React.useState(false)

  const handleSelectFulfillment = React.useCallback(
    (f: Fulfillment) => {
      if (f !== "delivery") {
        setFulfillment(f)
        return
      }
      if (!customer) {
        setPendingDeliveryFlow(true)
        setCustomerOpen(true)
        return
      }
      setDeliveryDialogOpen(true)
    },
    [customer, setFulfillment, setCustomerOpen],
  )

  React.useEffect(() => {
    if (customerOpen || !pendingDeliveryFlow) return
    // CustomerDialog se acaba de cerrar mientras había un intento de "Envío"
    // pendiente: si quedó un cliente cargado, continuar al selector de
    // dirección; si no, el cajero canceló — se descarta el intento sin tocar
    // el fulfillment.
    setPendingDeliveryFlow(false)
    if (customer) setDeliveryDialogOpen(true)
  }, [customerOpen, pendingDeliveryFlow, customer])

  // Barcode scanner keyboard-wedge. Pausado cuando: lock activo, PayDialog
  // abierto, SearchDialog abierto (el cajero está tipeando ahí).
  // El scanner ignora teclas cuando el foco está en un input (ver hook),
  // pero pausarlo explícitamente es la defensa extra.
  const scannerDisabled = locked || payOpen || searchOpen

  useBarcodeScanner({
    enabled: !scannerDisabled,
    minLength: 3,
    maxTimeBetweenKeys: 100,
    parseWeightBarcode: false,
    onScan: ({ code }) => {
      const match = catalogItems.find(
        (item) => item.sku === code || item.id === code,
      )
      if (match) {
        addCatalogItem(match)
      } else {
        // Sin match: abrir búsqueda con el código pre-cargado no está disponible
        // en la API actual del ProductSearchDialog, así que mostramos el toast.
        toast.error(`Código no encontrado: ${code}`)
      }
    },
  })

  const totalValue = total

  const setLinePrice = useCartStore((s) => s.setLinePrice)
  const setLineDiscount = useCartStore((s) => s.setLineDiscount)
  const setLineNote = useCartStore((s) => s.setLineNote)
  const setLineTags = useCartStore((s) => s.setLineTags)

  // Dialogs de precio y descuento por línea.
  const [priceLine, setPriceLine] = React.useState<CartLine | null>(null)
  const [discountLine, setDiscountLine] = React.useState<CartLine | null>(null)
  const [noteLine, setNoteLine] = React.useState<CartLine | null>(null)
  const [tagsLine, setTagsLine] = React.useState<CartLine | null>(null)

  // Confirm para vaciar la venta — acción destructiva. Tanto el chip VACIAR
  // del bottom como el "Cancelar venta" del drawer de opciones pasan por acá.
  const [confirmClearOpen, setConfirmClearOpen] = React.useState(false)
  const askClear = React.useCallback(() => {
    if (lines.length === 0) return // nada que limpiar
    setConfirmClearOpen(true)
  }, [lines.length])
  const doClear = React.useCallback(() => {
    clearCart()
    setConfirmClearOpen(false)
  }, [clearCart])

  // Quitar IVA modifica el total de la venta. Confirmamos antes de quitarlo.
  // Reactivar (devolver el IVA) es no-destructivo → directo.
  const [confirmIvaOpen, setConfirmIvaOpen] = React.useState(false)
  const askToggleIva = React.useCallback(() => {
    if (ivaRemoved) {
      toggleIva() // restaurar IVA, sin confirm
      return
    }
    setConfirmIvaOpen(true)
  }, [ivaRemoved, toggleIva])
  const doToggleIva = React.useCallback(() => {
    toggleIva()
    setConfirmIvaOpen(false)
  }, [toggleIva])

  // Gate de apertura de caja: si la caja está cerrada, mostrar el modal antes
  // de abrir el flujo de pago. Si drawerStatus es undefined (cargando o error),
  // dejamos pasar — el modal de pago tiene su propio guard interno.
  // Cuando controlCaja === false: el guard no aplica y se abre el pago directo.
  // Motivo por el que esta caja NO puede emitir, si lo hay. Se lee acá y en
  // `CartBottom` (el control que lo muestra) del mismo veredicto en memoria.
  const payBlockedReason = useTenancyStore((s) => registerBlockShortReason(s.verdict))

  const handlePayClick = React.useCallback(() => {
    // Sin derecho a emitir, el gate de tenencia manda sobre el de apertura de
    // caja: no tiene sentido pedirle al cajero que abra el cajón para una
    // venta que después no va a poder numerar. `PayDialog` abre directo en la
    // pantalla que explica el motivo y ofrece volver a tomar la caja.
    if (payBlockedReason) {
      setPayOpen(true)
      return
    }
    if (controlCaja && drawerStatus !== undefined && !drawerStatus.isOpen) {
      setDrawerOpenDialogOpen(true)
    } else {
      setPayOpen(true)
    }
  }, [controlCaja, drawerStatus, payBlockedReason, setPayOpen])

  // Modo orden (O1): "Ordenar" envía a cocina — sin caja/stock, sin gate de
  // drawer. Crea la orden con sendNow=true (status "sent" directo) y, si
  // ordenAImpresion está activo, imprime las comandas por estación
  // best-effort (falla de impresión NUNCA bloquea el éxito del envío).
  const [submittingOrder, setSubmittingOrder] = React.useState(false)
  // Payload del modal de confirmación unificado (context/20 §7). Estado local
  // del componente que lo dispara — NO en el cart store. El push a /pos/espacios
  // (orden de espacio) se difiere al cierre del modal para que se vea primero.
  const [orderSuccess, setOrderSuccess] = React.useState<{
    order: Order
    amount: string
    spaceName: string | null
    wasSpaceOrder: boolean
  } | null>(null)
  const handleOrderClick = React.useCallback(async () => {
    if (lines.length === 0 || submittingOrder) return
    setSubmittingOrder(true)
    try {
      const created = await createOrder.mutateAsync({
        source: spaceSessionId ? "table" : "counter",
        spaceSessionId: spaceSessionId ?? undefined,
        fulfillment,
        deliveryAddressId: fulfillment === "delivery" ? (deliveryAddress?.id ?? undefined) : undefined,
        items: lines.map((l) => ({
          itemId: l.itemId,
          qty: l.qty,
          // `unitPrice` ya incluye el recargo de los add-ons (ver
          // `CartLine.selections`) — sigue siendo el precio de la línea y la
          // ÚNICA plata de la orden. Las hijas que persiste el backend van con
          // precio 0: son el desglose para la cocina, no un segundo cobro.
          price: l.unitPrice,
          note: l.note,
          tags: l.tags,
          // Add-ons elegidos (context/41): solo optionId + qty, igual que la
          // venta (create-sale.ts). Sin add-ons, la línea NO lleva la key.
          ...(l.selections && l.selections.length > 0
            ? {
                selections: l.selections.map((s) => ({
                  optionId: s.optionId,
                  qty: s.qty,
                })),
              }
            : {}),
        })),
        customerId: customer?.id,
        note: note ?? undefined,
        sendNow: true,
      })

      const wasSpaceOrder = spaceSessionId !== null
      // Total de la orden leído del store (evita closure stale del selector).
      const orderTotal = selectCartTotal(useCartStore.getState())
      const capturedSpaceName = spaceName
      clearCart()
      // Modal de confirmación unificado (reemplaza el toast de éxito). El
      // clearCart es inmediato; el router.push de órdenes de espacio se difiere
      // al onClose del modal (Espacios F2, context/15).
      setOrderSuccess({
        order: created,
        amount: formatMoney(orderTotal, config),
        spaceName: capturedSpaceName,
        wasSpaceOrder,
      })

      if (ordenAImpresion) {
        printOrderComandas(created, allBindings, config)
          .then((r) => {
            if (r.failed > 0) {
              toast.warning(
                `${r.failed} impresora(s) fallaron al imprimir la comanda${r.errors[0] ? `: ${r.errors[0]}` : ""}`,
              )
            }
          })
          .catch((err) => console.error("[printOrderComandas]", err))
      }
    } catch (err) {
      toast.error("No se pudo enviar la orden", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      setSubmittingOrder(false)
    }
  }, [lines, submittingOrder, createOrder, customer, note, clearCart, ordenAImpresion, allBindings, config, spaceSessionId, spaceName, router, fulfillment, deliveryAddress])

  // Click afuera de la línea activa → deseleccionar (vuelve al detalle default).
  // Vía `useOutsidePointerDown`: los modales de la línea (cantidad, vendedor,
  // más opciones) se portalan a `document.body`, así que un `contains()` pelado
  // los tomaba como "afuera" y deseleccionaba la línea al primer click adentro
  // del modal — desmontando el modal con ella. El hook ignora las capas
  // flotantes; ver su docstring.
  const activeRef = React.useRef<HTMLDivElement>(null)
  const deselectLine = React.useCallback(() => selectLine(null), [selectLine])
  useOutsidePointerDown(activeRef, deselectLine, Boolean(selectedLineId))

  return (
    <div className="flex h-full flex-col border-l border-border bg-background">
      {/* Sin banda de modo (removida 2026-07-19 por decisión del owner): la
          identificación del tipo de transacción la da el CTA de cobro/orden
          (CartBottom) — un solo canal de color, sin slot extra arriba del
          toolbar. El estado de sincronización tampoco va acá: es UN solo
          aviso, arriba de la toolbar (`OfflineStatusPill`). */}
      {/* ── Modales ── */}
      <ProductSearchDialog open={searchOpen} onOpenChange={setSearchOpen} />
      <CustomerDialog open={customerOpen} onOpenChange={setCustomerOpen} />
      {customer && (
        <DeliveryAddressDialog
          open={deliveryDialogOpen}
          onOpenChange={setDeliveryDialogOpen}
          customerId={customer.id}
          onConfirm={(address) => {
            setFulfillment("delivery")
            setDeliveryAddress(address)
          }}
        />
      )}
      <PayDialog open={payOpen} onOpenChange={setPayOpen} />
      {orderSuccess && (
        <TransactionSuccessDialog
          open
          title="¡Orden enviada!"
          amount={orderSuccess.amount}
          badge={
            orderSuccess.order.orderNumber != null ? (
              <Badge variant="outline" className="border-black/20 text-[10px] opacity-80">
                #{orderSuccess.order.orderNumber}
                {orderSuccess.spaceName ? ` — ${orderSuccess.spaceName}` : ""}
              </Badge>
            ) : orderSuccess.spaceName ? (
              <Badge variant="outline" className="border-black/20 text-[10px] opacity-80">
                {orderSuccess.spaceName}
              </Badge>
            ) : undefined
          }
          closeLabel="Continuar"
          onPrint={() => {
            printOrderComandas(orderSuccess.order, allBindings, config)
              .then((r) => {
                if (r.failed > 0) {
                  toast.warning(
                    `${r.failed} impresora(s) fallaron al imprimir la comanda${r.errors[0] ? `: ${r.errors[0]}` : ""}`,
                  )
                } else if (r.printed > 0) {
                  toast.success(`${r.printed} impresora(s) imprimieron la comanda`)
                }
              })
              .catch((err) => console.error("[printOrderComandas]", err))
          }}
          onClose={() => {
            const wasSpace = orderSuccess.wasSpaceOrder
            setOrderSuccess(null)
            if (wasSpace) router.push("/pos/espacios")
          }}
        />
      )}
      <GiftcardIssueDialog />
      {/* Selección de add-ons (F4, context/41) — alta desde el catálogo y
          edición de una línea existente. Montado acá por el mismo motivo que
          GiftcardIssueDialog: el carrito vive montado en todo el workspace. */}
      <AddonPickerDialog />
      <DrawerOpenDialog
        open={drawerOpenDialogOpen}
        onOpenChange={setDrawerOpenDialogOpen}
        onSuccess={() => setPayOpen(true)}
      />

      {/* Edición de precio por línea */}
      <LinePriceDialog
        open={priceLine !== null}
        line={priceLine}
        onConfirm={(lineId, price) => {
          setLinePrice(lineId, price)
          setPriceLine(null)
        }}
        onClose={() => setPriceLine(null)}
      />

      {/* Descuento por línea */}
      <LineDiscountDialog
        open={discountLine !== null}
        line={discountLine}
        onConfirm={(lineId, pct) => {
          setLineDiscount(lineId, pct)
          setDiscountLine(null)
        }}
        onClose={() => setDiscountLine(null)}
      />

      {/* Comentario por línea */}
      <LineNoteDialog
        open={noteLine !== null}
        line={noteLine}
        onConfirm={(lineId, note) => {
          setLineNote(lineId, note)
          setNoteLine(null)
        }}
        onClose={() => setNoteLine(null)}
      />

      {/* Etiquetas por línea */}
      <LineTagsDialog
        open={tagsLine !== null}
        line={tagsLine}
        onConfirm={(lineId, tags) => {
          setLineTags(lineId, tags)
          setTagsLine(null)
        }}
        onClose={() => setTagsLine(null)}
      />

      {/* Confirm de quitar IVA — modifica el total de la venta. */}
      <AlertDialog open={confirmIvaOpen} onOpenChange={setConfirmIvaOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Quitar el IVA de la venta?</AlertDialogTitle>
            <AlertDialogDescription>
              Los precios de los ítems con IVA se recalcularán sin el impuesto,
              modificando el total. Podés reactivarlo para restaurarlo.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction autoFocus onClick={doToggleIva}>
              Quitar IVA
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Confirm de vaciar — acción destructiva. */}
      <AlertDialog open={confirmClearOpen} onOpenChange={setConfirmClearOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Vaciar la venta?</AlertDialogTitle>
            <AlertDialogDescription>
              Se quitarán todos los ítems de la venta en curso. Esta acción no se
              puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={doClear}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Vaciar venta
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {editingHotkeys ? (
        <HotkeyEditGuide />
      ) : (
        <>
      {/* Estado de conexión: SIEMPRE acá, pegado arriba de la toolbar del
          carrito (pedido del owner 2026-08-23). Antes flotaba abajo a la
          izquierda del workspace y además se montaba una segunda vez en el
          layout: dos avisos del mismo estado en pantalla. Un solo lugar, el
          que el cajero ya mira para cobrar. */}
      <OfflineStatusPill />
      {/* ── Toolbar propia de la caja (buscar / cliente / acciones) ── */}
      <CartToolbar
        onSearch={() => setSearchOpen(true)}
        onCustomer={() => setCustomerOpen(true)}
        onCancelSale={askClear}
      />

      {/* ── Chip de espacio seleccionado (context/15 F2) ── */}
      {/* Con la banda de modo removida, este chip vuelve a ser el único lugar
          con el nombre del espacio + la X para deseleccionarlo (modo
          orden-espacio) y también cubre el caso "venta" (loadFromSession:
          cobrar el espacio completo, sin X). */}
      <SpaceChip spaceName={spaceName} onClear={clearSelectedSpace} />

      {/* ── Chip de cliente (con lista de precios activa, si hay) ── */}
      <CustomerChip customer={customer} priceListName={priceListName} />

      {/* ── Strip de dirección de envío elegida (context/27 §D.4) ── */}
      <DeliveryAddressChip
        fulfillment={fulfillment}
        address={deliveryAddress}
        onClear={() => setFulfillment("dine_in")}
      />

      {/* ── Lista de líneas ── */}
      <div className="flex-1 overflow-y-auto">
        {lines.length === 0 ? (
          <EmptyCart />
        ) : (
          <div className="flex flex-col">
            {lines.map((line) => {
              const isActive = line.lineId === selectedLineId
              return (
                <div key={line.lineId} ref={isActive ? activeRef : undefined}>
                  {isActive ? (
                    <CartRowExpanded
                      line={line}
                      onInc={() => incQty(line.lineId)}
                      onDec={() => decQty(line.lineId)}
                      onSetQty={(q) => setQty(line.lineId, q)}
                      onRemove={() => removeLine(line.lineId)}
                      onEditPrice={() => setPriceLine(line)}
                      onApplyDiscount={() => setDiscountLine(line)}
                      onEditNote={() => setNoteLine(line)}
                      onEditTags={() => setTagsLine(line)}
                    />
                  ) : (
                    <CartRowCollapsed
                      line={line}
                      config={config}
                      onSelect={() => selectLine(line.lineId)}
                    />
                  )}
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* ── Extras de la venta (nota / descuento / usuario) ── */}
      <CartExtras />

      {/* ── Bottom: toggles + botón cobrar/ordenar ── */}
      <CartBottom
        credito={credito}
        interno={interno}
        ivaRemoved={ivaRemoved}
        iva={iva}
        onToggleCredito={toggleCredito}
        onToggleInterno={toggleInterno}
        onToggleIva={askToggleIva}
        onClear={askClear}
        total={totalValue}
        lineCount={lines.length}
        config={config}
        cartMode={cartMode}
        spaceName={spaceName}
        savingQuote={savingQuote}
        onPayClick={handlePayClick}
        payBlockedReason={payBlockedReason}
        onOrderClick={handleOrderClick}
        orderSubmitting={submittingOrder}
        fulfillment={fulfillment}
        onSelectFulfillment={handleSelectFulfillment}
      />
        </>
      )}
    </div>
  )
}

// ── Guía del modo edición de hotkeys ──────────────────────────────────────────

function HotkeyEditGuide() {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-7 px-6 text-center">
      <div className="flex flex-col items-center gap-2">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <LayoutGrid className="size-6 text-muted-foreground" />
        </span>
        <h2 className="text-base font-semibold text-foreground">
          Editando Hotkeys
        </h2>
        <p className="max-w-[15rem] text-sm text-muted-foreground">
          Configurá la grilla de la caja. Tus cambios se guardan al tocar “Listo”.
        </p>
      </div>

      <ul className="flex w-full max-w-xs flex-col gap-3.5 text-left">
        <GuideStep icon={Plus} text="Tocá un slot vacío para agregar un artículo o categoría." />
        <GuideStep icon={Move} text="Arrastrá un hotkey para moverlo de lugar." />
        <GuideStep icon={Palette} text="Elegí un color para los hotkeys sin imagen." />
        <GuideStep icon={X} text="Tocá la ✕ de un hotkey para quitarlo." />
        <GuideStep icon={Check} text="Tocá “Listo”, arriba a la izquierda, para guardar." />
      </ul>
    </div>
  )
}

function GuideStep({
  icon: Icon,
  text,
}: {
  icon: React.ComponentType<{ className?: string }>
  text: string
}) {
  return (
    <li className="flex items-start gap-3">
      <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Icon className="size-4 text-muted-foreground" />
      </span>
      <span className="text-sm text-foreground">{text}</span>
    </li>
  )
}

// ── Toolbar de la caja ────────────────────────────────────────────────────────
//
// Única UI "propia de la caja" en el shell del panel: buscar producto, asignar
// cliente y un menú "..." con acciones de venta. Los iconos van alineados a la
// derecha, estilo sobrio (ghost), consistente con el panel.

function CartToolbar({
  onSearch,
  onCustomer,
  onCancelSale,
}: {
  onSearch: () => void
  onCustomer: () => void
  onCancelSale: () => void
}) {
  // 4 botones distribuidos proporcionalmente a lo largo del toolbar (cada uno
  // ocupa un cuarto, centrado) — espejo del col-xs-3 del legacy.
  return (
    <div className="flex h-14 shrink-0 items-center px-1">
      <div className="flex flex-1 justify-center">
        <PosMainMenu />
      </div>
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-9"
          onClick={onSearch}
          aria-label="Buscar producto"
        >
          <Search className="size-5" />
        </Button>
      </div>
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-9"
          onClick={onCustomer}
          aria-label="Cliente"
        >
          <User className="size-5" />
        </Button>
      </div>
      <div className="flex flex-1 justify-center">
        <SaleOptionsDrawer onCancelSale={onCancelSale} />
      </div>
    </div>
  )
}

// ── Chip de espacio seleccionado ──────────────────────────────────────────────
//
// Visible en dos casos (context/15 F2): (1) el carrito está armando una
// orden para un espacio (`spaceSessionId` set, con X para deseleccionar); (2)
// el carrito viene de "Cobrar" un espacio completo (`loadFromSession`, solo
// `spaceName` — sin X, cambiar de opinión implica cancelar la venta entera
// desde el menú de Opciones, mismo mecanismo que cualquier otra venta armada).

function SpaceChip({
  spaceName,
  onClear,
}: {
  spaceName: string | null
  onClear: () => void
}) {
  const spaceSessionId = useCartStore((s) => s.spaceSessionId)
  if (!spaceName) return null

  // Ícono canónico de Espacios (LayoutGrid, MODE_VISUALS) + la palabra
  // "Espacio": el nombre solo suele ser un número ("10") y arriba del chip de
  // cliente se leía como un dato suelto, no como el espacio que se está
  // cobrando. Badge y no texto plano por lo mismo — crudo parecía un error.
  // Sin tinte de color a propósito: al cobrar, el carrito está en modo venta y
  // la regla del POS es que cualquier color significa "no estás cobrando"
  // (context/20 §"Colores de modo del POS").
  const SpaceIcon = MODE_VISUALS["orden-espacio"].icon

  return (
    <div className="flex items-center gap-2 px-3 py-1.5">
      <div className="flex min-w-0 flex-1">
        <Badge variant="secondary" className="min-w-0 gap-1.5">
          {SpaceIcon && <SpaceIcon className="size-3 shrink-0" aria-hidden />}
          <span className="shrink-0 text-muted-foreground">Espacio</span>
          <span className="truncate font-medium text-foreground">{spaceName}</span>
        </Badge>
      </div>
      {spaceSessionId && (
        <Button
          variant="ghost"
          size="icon-xs"
          onClick={onClear}
          aria-label="Quitar espacio seleccionado"
        >
          <X className="size-3" />
        </Button>
      )}
    </div>
  )
}

// ── Chip de cliente ───────────────────────────────────────────────────────────

function CustomerChip({
  customer,
  priceListName,
}: {
  customer: ReturnType<typeof useCartStore.getState>["customer"]
  /**
   * Lista de precios efectivamente aplicada por `usePriceContext` (puede ser
   * la del cliente, no necesariamente una elegida a mano). Se muestra acá
   * como texto secundario — mismo lugar donde ya se muestra el contexto de
   * la venta, sin banner nuevo (posiciones estables del POS, context/08).
   */
  priceListName: string | null
}) {
  const setCustomer = useCartStore((s) => s.setCustomer)

  if (!customer) {
    // Sin cliente pero con lista elegida a mano (sale-options-drawer.tsx):
    // el chip de cliente no existe, así que la señal de lista va sola acá
    // mismo — mismo slot, sin desplazar nada de lo que ya está debajo.
    if (!priceListName) return null
    return (
      <div className="px-3 py-1.5">
        <p className="truncate text-[10px] text-muted-foreground">Lista: {priceListName}</p>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2 px-3 py-1.5">
      <div className="flex-1 min-w-0">
        <p className="truncate text-xs font-medium text-foreground">{customer.name}</p>
        {customer.tin && (
          <p className="text-[10px] text-muted-foreground">{customer.tin}</p>
        )}
        {priceListName && (
          <p className="truncate text-[10px] text-muted-foreground">Lista: {priceListName}</p>
        )}
      </div>
      <Button
        variant="ghost"
        size="icon-xs"
        onClick={() => setCustomer(null)}
        aria-label="Quitar cliente"
      >
        <X className="size-3" />
      </Button>
    </div>
  )
}

// ── Strip de dirección de envío elegida ───────────────────────────────────────
//
// Mismo patrón visual que SpaceChip/CustomerChip arriba — solo visible cuando
// fulfillment==="delivery" y hay una dirección elegida (context/27 §D.4). La
// X vuelve el fulfillment a "dine_in" ("Mostrador"), que a su vez limpia
// deliveryAddress vía la invariante de setFulfillment (lib/cart/store.ts).

function DeliveryAddressChip({
  fulfillment,
  address,
  onClear,
}: {
  fulfillment: Fulfillment
  address: ReturnType<typeof useCartStore.getState>["deliveryAddress"]
  onClear: () => void
}) {
  if (fulfillment !== "delivery" || !address) return null

  return (
    <div className="flex items-center gap-2 px-3 py-1.5">
      <div className="flex-1 min-w-0">
        <p className="truncate text-xs font-medium text-foreground">{address.name}</p>
        <p className="truncate text-[10px] text-muted-foreground">
          {address.address}
          {address.reference ? ` — ${address.reference}` : ""}
        </p>
      </div>
      <Button
        variant="ghost"
        size="icon-xs"
        onClick={onClear}
        aria-label="Quitar dirección de envío"
      >
        <X className="size-3" />
      </Button>
    </div>
  )
}

// ── Fila colapsada (no seleccionada) ──────────────────────────────────────────

/**
 * Add-ons elegidos de una línea (F4, context/41) — se listan indentados debajo
 * del nombre del producto, con la misma escala tipográfica que la nota de
 * línea. El recargo se muestra solo si suma (D2: `priceDelta = 0` es una
 * opción sin costo, un "sin cebolla"; imprimirle "+0" sería ruido).
 *
 * Renderiza desde la COPIA guardada en la línea (`name`/`priceDelta`), no de la
 * red: el carrito tiene que verse igual con el device offline. El importe de
 * autoridad lo recalcula el server al vender.
 */
function LineAddons({
  line,
  config,
}: {
  line: CartLine
  config: ReturnType<typeof useCatalogStore.getState>["config"]
}) {
  if (!line.selections || line.selections.length === 0) return null
  return (
    <div className="mt-0.5 flex flex-col gap-px pl-2 text-[10px] text-muted-foreground">
      {line.selections.map((addon) => (
        <span key={addon.optionId} className="truncate">
          {"· "}
          {addon.qty > 1 ? `${addon.qty}× ` : ""}
          {addon.name}
          {addon.priceDelta > 0 ? ` +${formatAmount(addon.priceDelta * addon.qty, config)}` : ""}
        </span>
      ))}
    </div>
  )
}

function CartRowCollapsed({
  line,
  config,
  onSelect,
}: {
  line: CartLine
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onSelect: () => void
}) {
  // Subtotal mostrado respeta el flag ivaRemoved — la suma de las líneas
  // debe coincidir con el total del botón cobrar.
  const ivaRemoved = useCartStore((s) => s.ivaRemoved)
  const subtotal = lineSubtotal(line, ivaRemoved)
  // Una línea está descontada si tiene descuento PROPIO o si la alcanza el
  // descuento de venta (que congela su alcance al aplicarse — ver el store).
  // El cajero tiene que poder ver de un vistazo qué se descontó y qué no:
  // desde que el global dejó de alcanzar todo el carrito, el borde amarillo es
  // la única señal de la diferencia (pedido del owner, 2026-07-30).
  const coveredBySaleDiscount = useCartStore((s) =>
    s.saleDiscount ? s.saleDiscount.lineIds.includes(line.lineId) : false,
  )
  const hasDiscount = (line.discount ?? 0) > 0 || coveredBySaleDiscount
  // Línea de vale (F2, context/36): qty/precio bloqueados, aporta 0 al total
  // (lineSubtotal) — se marca con borde azul, distinto del amarillo de
  // descuento, para que el cajero nunca confunda "cubierto por vale" con
  // "tiene descuento".
  const isVoucher = Boolean(line.voucher)
  const hasSeller = Boolean(line.sellerId)
  const hasTags = (line.tags?.length ?? 0) > 0
  const hasNote = Boolean(line.note && line.note.trim().length > 0)
  const showSubtitle = hasSeller || hasTags || hasNote || isVoucher
  const users = useCatalogStore((s) => s.users)
  const sellerName = hasSeller ? (users.find((u) => u.id === line.sellerId)?.name ?? null) : null

  return (
    <button
      onClick={onSelect}
      className={cn(
        "flex w-full items-center gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-muted/50",
        isVoucher ? "border-l-[3px] border-l-blue-500" : hasDiscount && "border-l-[3px] border-l-yellow-500",
      )}
    >
      <span
        className={cn(
          "flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold tabular-nums",
          isVoucher
            ? "border-blue-500/40 bg-blue-500/15 text-blue-500"
            : hasDiscount
              ? "border-yellow-500/40 bg-yellow-500/15 text-yellow-500"
              : "border-border bg-muted/40 text-muted-foreground",
        )}
      >
        {line.qty}
      </span>

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{line.name}</p>
        <LineAddons line={line} config={config} />
        {showSubtitle && (
          <div className="mt-0.5 flex items-center gap-2 text-[10px] text-muted-foreground">
            {isVoucher && (
              <span className="inline-flex items-center gap-1 text-blue-500" title={`Vale ${line.voucher?.code}`}>
                <Ticket className="size-3" aria-hidden />
                <span className="font-mono">{line.voucher?.code}</span>
              </span>
            )}
            {hasSeller && (
              <span className="inline-flex items-center gap-1" title="Vendedor asignado">
                <User className="size-3" aria-hidden />
                {sellerName && <span>{sellerName}</span>}
              </span>
            )}
            {hasTags && (
              <span className="inline-flex items-center gap-1" title="Etiquetas">
                <Tag className="size-3" aria-hidden />
              </span>
            )}
            {hasNote && (
              <span className="inline-flex items-center gap-1 truncate" title={line.note}>
                <MessageSquare className="size-3 shrink-0" aria-hidden />
                <span className="truncate">{line.note}</span>
              </span>
            )}
          </div>
        )}
      </div>

      <div className="flex shrink-0 flex-col items-end gap-0.5">
        {isVoucher ? (
          // Vale: no se muestra el monto (siempre 0, ver lineSubtotal) — un
          // "Gs 0" leería como un bug. La etiqueta dice explícitamente por qué.
          <span className="rounded border border-blue-500/40 bg-blue-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-blue-500">
            No suma al total
          </span>
        ) : (
          <span className="text-sm font-semibold tabular-nums text-foreground">
            {formatAmount(subtotal, config)}
          </span>
        )}
        {/* El % solo se muestra si es de la LÍNEA. Una línea alcanzada por el
            descuento de venta se marca con el borde amarillo, pero su % no se
            imprime acá: el monto de ese descuento se prorratea entre todas las
            líneas alcanzadas y ponerle un número por línea daría a entender un
            descuento propio que no tiene. */}
        {!isVoucher && (line.discount ?? 0) > 0 && (
          <span className="text-[10px] font-medium text-yellow-500">
            -{Math.round(line.discount ?? 0)}%
          </span>
        )}
      </div>
    </button>
  )
}

// ── Fila expandida (seleccionada) ─────────────────────────────────────────────
//
// Diseño post-pivote (2026-06-16): NO grilla 4+5 del legacy.
// Header con nombre + stepper [−][qty][+] + acciones rápidas (vendedor/quitar/más).
// "más" abre Drawer (no Dropdown). Qty es clickable → numpad.

function CartRowExpanded({
  line,
  onInc,
  onDec,
  onSetQty,
  onRemove,
  onEditPrice,
  onApplyDiscount,
  onEditNote,
  onEditTags,
}: {
  line: CartLine
  onInc: () => void
  onDec: () => void
  onSetQty: (qty: number) => void
  onRemove: () => void
  onEditPrice: () => void
  onApplyDiscount: () => void
  onEditNote: () => void
  onEditTags: () => void
}) {
  const [qtyOpen, setQtyOpen] = React.useState(false)
  const [moreOpen, setMoreOpen] = React.useState(false)
  const [sellerOpen, setSellerOpen] = React.useState(false)

  // Líneas de emisión de gift card SIEMPRE qty=1 — el código/beneficiario/
  // monto son de UNA sola card. Subir qty crearía itemSold/stock con count=2
  // pero SaleService::issueGiftCard() solo lee sD['total'] (una fila con el
  // doble de saldo bajo un único código) — inconsistencia de plata. Bloqueado
  // acá (UI) y con backstop server-side (SaleService rechaza count != 1).
  //
  // Líneas de vale (F2, context/36): mismo bloqueo — qty/precio los fija el
  // vale al emitirse, no el cajero. Deshacer es "quitar vale" (todas sus
  // líneas juntas), nunca ajustar una de a una.
  const isVoucher = Boolean(line.voucher)
  const qtyLocked = !!line.giftcard || isVoucher

  // Misma señal que la fila compacta: toda línea con descuento —propio o
  // alcanzada por el descuento de venta— lleva el borde izquierdo amarillo.
  // La fila expandida no lo tenía, así que al seleccionar una línea descontada
  // la marca desaparecía (regla del owner: SIEMPRE marcada).
  const coveredBySaleDiscount = useCartStore((s) =>
    s.saleDiscount ? s.saleDiscount.lineIds.includes(line.lineId) : false,
  )
  const hasDiscount = (line.discount ?? 0) > 0 || coveredBySaleDiscount
  const removeVoucher = useCartStore((s) => s.removeVoucher)

  // F4 (context/41): el modal de add-ons necesita el PosItem del catálogo (el
  // precio base y el flag). Sin el ítem en el catálogo del device no hay nada
  // que reabrir; con selecciones guardadas pero sin grupos vigentes tampoco.
  const catalogItem = useCatalogStore((s) => s.items.find((i) => i.id === line.itemId))
  const canEditAddons = Boolean(catalogItem?.hasAddons) && !isVoucher && !line.giftcard

  return (
    <div
      className={cn(
        "bg-accent/40 px-3 py-3",
        isVoucher ? "border-l-[3px] border-l-blue-500" : hasDiscount && "border-l-[3px] border-l-yellow-500",
      )}
    >
      {/* Header — nombre del item en negrita + código del vale, si aplica. */}
      <div className="mb-2 flex flex-col items-center gap-0.5 text-center">
        <span className="truncate text-sm font-bold text-foreground">
          {line.name}
        </span>
        {(line.selections?.length ?? 0) > 0 && (
          <span className="text-[10px] text-muted-foreground">
            {line.selections
              ?.map((a) => (a.qty > 1 ? `${a.qty}× ${a.name}` : a.name))
              .join(" · ")}
          </span>
        )}
        {isVoucher && (
          <span className="inline-flex items-center gap-1 text-[11px] font-medium text-blue-500">
            <Ticket className="size-3" aria-hidden />
            Vale {line.voucher?.code} — no suma al total
          </span>
        )}
      </div>

      {/* Layout: [−][qty][+]  ......  [Vendedor] [Quitar] [⋯]
          Botones cuadrados con fondo levemente más oscuro que el panel. */}
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-1.5">
          <LineToolButton onClick={onDec} aria-label="Disminuir cantidad" disabled={qtyLocked}>
            <Minus className="size-4" />
          </LineToolButton>
          {/* Cantidad clickable → numpad para tipear cantidades grandes.
              Gift card: fija en 1, no clickable (ver qtyLocked arriba). */}
          <button
            type="button"
            onClick={() => { if (!qtyLocked) setQtyOpen(true) }}
            disabled={qtyLocked}
            aria-label="Editar cantidad"
            className={cn(
              // `h-8` + `text-sm`: misma altura y escala tipográfica que los
              // LineToolButton de al lado. Con `text-lg`/`py-0.5` el pill quedaba
              // 4px más alto que los iconos y el número desentonaba con la fila.
              "h-8 min-w-[2.5rem] rounded-md border border-border bg-muted px-2 text-center text-sm font-semibold tabular-nums",
              "transition-colors hover:bg-muted/70 active:bg-muted/60",
              "disabled:pointer-events-none disabled:opacity-50",
            )}
          >
            {line.qty}
          </button>
          <LineToolButton onClick={onInc} aria-label="Aumentar cantidad" disabled={qtyLocked}>
            <Plus className="size-4" />
          </LineToolButton>
        </div>

        <div className="flex items-center gap-1.5">
          <LineToolButton
            onClick={() => setSellerOpen(true)}
            aria-label="Asignar vendedor"
          >
            <User className="size-4" />
          </LineToolButton>
          <LineToolButton
            onClick={() => {
              // Vale: no hay borrado de línea individual — quitar SIEMPRE se
              // lleva las N líneas del mismo vale juntas (context/36, "Quitar
              // el vale es lo que desbloquea"). `onRemove` (removeLine de una
              // sola línea) rompería esa garantía.
              if (isVoucher && line.voucher) {
                removeVoucher(line.voucher.voucherId)
              } else {
                onRemove()
              }
            }}
            aria-label={isVoucher ? "Quitar vale de la venta" : "Quitar de la venta"}
            className="text-muted-foreground hover:border-destructive hover:text-destructive"
          >
            <X className="size-4" />
          </LineToolButton>
          <LineToolButton
            onClick={() => setMoreOpen(true)}
            aria-label="Más opciones"
          >
            <MoreHorizontal className="size-4" />
          </LineToolButton>
        </div>
      </div>

      {/* Numpad de cantidad. */}
      <QtyEditDialog
        open={qtyOpen}
        initialQty={line.qty}
        itemName={line.name}
        onConfirm={(q) => {
          onSetQty(q)
          setQtyOpen(false)
        }}
        onClose={() => setQtyOpen(false)}
      />

      <LineSellerDialog
        open={sellerOpen}
        currentSellerId={line.sellerId}
        onSelect={(uid) => useCartStore.getState().setLineSeller(line.lineId, uid)}
        onClose={() => setSellerOpen(false)}
      />

      {/* Más opciones — drawer inferior. data-vaul-no-drag en el contenedor
          de botones: Vaul interpreta cualquier touch/click dentro del
          DrawerContent como inicio de drag y al soltarlo cierra el drawer,
          comiéndose el onClick del button. El attribute marca esa zona como
          "no draggable" → los clicks llegan al button normalmente.
          (handleOnly anterior no servía: requiere un DrawerHandle explícito
          con data-vaul-handle que shadcn no expone.) */}
      <Drawer open={moreOpen} onOpenChange={setMoreOpen}>
        <DrawerContent className="mx-auto max-w-lg">
          <DrawerHeader className="pb-2">
            <DrawerTitle className="truncate">{line.name}</DrawerTitle>
          </DrawerHeader>
          <div
            data-vaul-no-drag
            className="grid grid-cols-2 gap-2 p-4 pt-2 sm:grid-cols-3"
          >
            {/* Vale: precio congelado al emitir y sin descuento propio (no
                tiene sentido descontar una línea que ya aporta 0) — mismo
                bloqueo que qtyLocked arriba, backstop en el store
                (setLinePrice/setLineDiscount) por si algo más los dispara. */}
            <LineActionTile
              icon={DollarSign}
              label="Modificar precio"
              onClick={() => { onEditPrice(); setMoreOpen(false) }}
              disabled={isVoucher}
            />
            <LineActionTile
              icon={Percent}
              label="Aplicar descuento"
              onClick={() => { onApplyDiscount(); setMoreOpen(false) }}
              disabled={isVoucher}
            />
            {/* Etiquetas y Comentario: a diferencia de precio/descuento, no
                dependen de isVoucher/qtyLocked — esos bloqueos existen
                porque el vale congela precio/cantidad al emitirse (regla de
                negocio, context/36). Etiquetas/comentario son metadata de
                texto libre sin ningún efecto en el total ni el stock: no hay
                razón para impedir anotar una línea de vale o de gift card. */}
            <LineActionTile
              icon={Tag}
              label="Etiquetas"
              onClick={() => { onEditTags(); setMoreOpen(false) }}
            />
            <LineActionTile
              icon={MessageSquare}
              label="Comentario"
              onClick={() => { onEditNote(); setMoreOpen(false) }}
            />
            {/* Add-ons (F4, context/41): reabre el modal con lo elegido y
                reemplaza la selección de ESTA línea. El tile existe siempre —
                deshabilitado cuando el producto no tiene grupos— para que los
                otros no cambien de posición entre productos (§14 §10). */}
            <LineActionTile
              icon={ListPlus}
              label="Add-ons"
              disabled={!canEditAddons}
              onClick={() => {
                if (catalogItem) {
                  useAddonPickerStore
                    .getState()
                    .openForLine(catalogItem, line.lineId, line.selections ?? [])
                }
                setMoreOpen(false)
              }}
            />
          </div>
        </DrawerContent>
      </Drawer>
    </div>
  )
}

function LineToolButton({
  children,
  className,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type="button"
      className={cn(
        "flex size-8 items-center justify-center rounded-md border border-border bg-muted",
        "text-foreground transition-colors hover:bg-muted/70 active:bg-muted/60",
        "disabled:pointer-events-none disabled:opacity-50",
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  )
}

function LineActionTile({
  icon: Icon,
  label,
  onClick,
  disabled,
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  onClick: () => void
  disabled?: boolean
}) {
  // data-vaul-no-drag también en el button (no solo en la grid padre): el
  // active:scale puede confundir el detector de drag de vaul y comer el click.
  // Aplicar el attr al elemento que recibe el touch garantiza que se preserve.
  // onPointerDownCapture stopPropagation por las dudas (toque mobile + scale).
  return (
    <button
      type="button"
      data-vaul-no-drag
      onPointerDownCapture={(e) => e.stopPropagation()}
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      title={disabled ? "Próximamente" : undefined}
      className={cn(
        "flex flex-col items-center justify-center gap-2 rounded-xl border border-border bg-muted/30 px-3 py-4",
        "text-center transition-colors",
        disabled
          ? "cursor-not-allowed opacity-40"
          : "hover:bg-muted active:scale-[0.98]",
      )}
    >
      <Icon className="size-5 text-foreground" />
      <span className="text-xs font-medium text-foreground">{label}</span>
      {disabled && (
        <span className="text-[10px] text-muted-foreground">Próximamente</span>
      )}
    </button>
  )
}

// ── Carrito vacío ─────────────────────────────────────────────────────────────

function EmptyCart() {
  return (
    <div className="flex h-full flex-col items-center justify-center select-none">
      {/* Logo centrado — 10px menos que el original (h-14 = 56px → h-[46px]).
          width explícito (proporción 100:28 del wordmark) porque el SVG
          interno usa `fill` y w-auto colapsa a 0. */}
      <span aria-hidden className="flex justify-center opacity-[0.12]">
        <PuntoLogo variant="wordmark" className="h-[46px] w-[165px]" />
      </span>
    </div>
  )
}

// ── Extras de la venta ────────────────────────────────────────────────────────
//
// Muestra nota, descuento de venta, descuento por línea y usuario cuando
// tienen valor. Orden fijo: nota → descuento de venta → descuento por línea →
// usuario. Customer ya se muestra en CustomerChip (arriba de las líneas), no
// se duplica.
//
// Descuento de venta (`saleDiscount`, sale-options-drawer.tsx) es DISTINTO
// del descuento por línea de acá abajo (`globalDiscount`, bakeado en cada
// línea vía `applyGlobalDiscount`/`setLineDiscount`) — dos mecanismos
// distintos que pueden convivir. Antes solo se mostraba el de línea; el de
// venta ya se calculaba (`selectSaleDiscountAmount`) pero era invisible en
// el carrito.

function CartExtras() {
  const note = useCartStore((s) => s.note)
  const lines = useCartStore((s) => s.lines)
  const tags = useCartStore((s) => s.tags)
  const setNote = useCartStore((s) => s.setNote)
  const clearTags = useCartStore((s) => s.clearTags)
  const users = useCatalogStore((s) => s.users)
  const config = useCatalogStore((s) => s.config)
  const saleDiscount = useCartStore((s) => s.saleDiscount)
  const saleDiscountAmount = useCartStore(selectSaleDiscountAmount)
  const clearSaleDiscount = useCartStore((s) => s.clearSaleDiscount)

  const saleDiscountLabel = saleDiscount
    ? saleDiscount.mode === "percent"
      ? `Descuento de venta: ${saleDiscount.value}% (-${formatMoney(saleDiscountAmount, config)})`
      : `Descuento de venta: -${formatMoney(saleDiscountAmount, config)}`
    : null

  // Descuento global: todas las líneas aplicables comparten el mismo valor.
  // applyGlobalDiscount bake el % en cada línea sin discount previo.
  const globalDiscount = React.useMemo(() => {
    if (lines.length === 0) return null
    const discounts = lines.map((l) => l.discount ?? 0).filter((d) => d > 0)
    if (discounts.length === 0) return null
    const first = discounts[0]
    return discounts.every((d) => d === first) ? first : null
  }, [lines])

  const clearGlobalDiscount = React.useCallback(() => {
    const { lines: ls } = useCartStore.getState()
    ls.forEach((l) => useCartStore.getState().setLineDiscount(l.lineId, 0))
  }, [])

  // Usuario global: todas las líneas comparten el mismo sellerId.
  const globalSellerId = React.useMemo(() => {
    if (lines.length === 0) return null
    const sellers = lines.map((l) => l.sellerId ?? null)
    if (sellers.some((s) => s === null)) return null
    const first = sellers[0]
    return sellers.every((s) => s === first) ? first : null
  }, [lines])

  const clearGlobalSeller = React.useCallback(() => {
    const { lines: ls } = useCartStore.getState()
    ls.forEach((l) => useCartStore.getState().setLineSeller(l.lineId, null))
  }, [])

  const sellerName = globalSellerId
    ? (users.find((u) => u.id === globalSellerId)?.name ?? null)
    : null

  const hasExtras =
    note || saleDiscountLabel !== null || globalDiscount !== null || globalSellerId !== null || tags.length > 0
  if (!hasExtras) return null

  return (
    <div className="shrink-0 border-t border-border/50">
      {note && (
        <ExtraRow
          icon={StickyNote}
          label={`Nota: ${note}`}
          onClear={() => setNote(null)}
        />
      )}
      {saleDiscountLabel !== null && (
        <ExtraRow
          icon={Percent}
          label={saleDiscountLabel}
          onClear={clearSaleDiscount}
        />
      )}
      {globalDiscount !== null && (
        <ExtraRow
          icon={Percent}
          label={`Descuento por línea: ${Math.round(globalDiscount)}%`}
          onClear={clearGlobalDiscount}
        />
      )}
      {globalSellerId !== null && (
        <ExtraRow
          icon={UserCircle2}
          label={`Usuario: ${sellerName ?? globalSellerId}`}
          onClear={clearGlobalSeller}
        />
      )}
      {tags.length > 0 && (
        <ExtraRow
          icon={Tag}
          label={`Etiquetas: ${tags.join(", ")}`}
          onClear={clearTags}
        />
      )}
    </div>
  )
}

function ExtraRow({
  icon: Icon,
  label,
  onClear,
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  onClear: () => void
}) {
  return (
    <div className="flex items-center gap-2 bg-muted/50 px-3 py-1.5">
      <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
        {label}
      </span>
      <Button
        variant="ghost"
        size="icon-xs"
        onClick={onClear}
        aria-label="Quitar"
      >
        <X className="size-3" />
      </Button>
    </div>
  )
}

// ── Bottom: toggles + cobrar ──────────────────────────────────────────────────

function CartBottom({
  credito,
  interno,
  ivaRemoved,
  iva,
  onToggleCredito,
  onToggleInterno,
  onToggleIva,
  onClear,
  total,
  lineCount,
  config,
  cartMode,
  spaceName,
  savingQuote,
  onPayClick,
  payBlockedReason,
  onOrderClick,
  orderSubmitting,
  fulfillment,
  onSelectFulfillment,
}: {
  credito: boolean
  interno: boolean
  ivaRemoved: boolean
  iva: number
  onToggleCredito: () => void
  onToggleInterno: () => void
  onToggleIva: () => void
  onClear: () => void
  total: number
  lineCount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  cartMode: CartModeKey
  spaceName: string | null
  savingQuote: boolean
  onPayClick: () => void
  /** Motivo por el que esta caja no puede emitir, o `null` si puede. */
  payBlockedReason: string | null
  onOrderClick: () => void
  orderSubmitting: boolean
  fulfillment: Fulfillment
  onSelectFulfillment: (f: Fulfillment) => void
}) {
  const totalFormatted = formatMoney(total, config)
  const ivaFormatted = formatMoney(iva, config)
  const isOrderMode = cartMode === "orden-mostrador" || cartMode === "orden-espacio"
  const visual = MODE_VISUALS[cartMode]

  // Label + acción del CTA por modo. Venta: el monto formateado ES el label
  // centrado (comportamiento histórico — el cajero mira el botón para saber
  // cuánto cobrar). Modos con color (orden/espacio/cotización): label de la
  // acción a la IZQUIERDA + monto a la DERECHA con `justify-between` (patrón de
  // botón POS), monto en tabular-nums sin opacity para que no quede lavado. En
  // pantallas angostas el monto baja debajo del label (flex-wrap) — nunca
  // concatenado inline. Cotización es un modo sticky (2026-07-30): el CTA amber
  // ES el que genera la cotización (requestQuoteSave → SaleOptionsDrawer).
  const amountRight = lineCount > 0 && (
    <span className="shrink-0 text-2xl font-semibold tabular-nums">{totalFormatted}</span>
  )
  let ctaLabel: React.ReactNode
  let ctaAction: (() => void) | undefined
  let ctaAriaLabel: string
  // El impedimento se pinta en ESTE control y en ningún otro lado (owner,
  // 2026-08-23): el cajero descubre que no puede cobrar cuando va a cobrar, y
  // ahí tiene que estar la explicación. Solo aplica al CTA de venta —
  // "Ordenar" no emite comprobante ni consume numeración, así que la tenencia
  // de caja no lo bloquea (mismo criterio que el gate de `PayDialog`).
  let ctaBlockedReason: string | null = null
  if (savingQuote) {
    ctaLabel = "Guardando cotización..."
    ctaAction = undefined
    ctaAriaLabel = "Guardando cotización"
  } else if (cartMode === "cotizacion") {
    ctaLabel = (
      <span className="flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span>Cotizar</span>
        {amountRight}
      </span>
    )
    ctaAction = () => usePosUIStore.getState().requestQuoteSave()
    ctaAriaLabel = "Generar cotización"
  } else if (isOrderMode) {
    const SpaceIcon = MODE_VISUALS["orden-espacio"].icon
    ctaLabel = orderSubmitting ? (
      "Enviando..."
    ) : cartMode === "orden-espacio" && spaceName ? (
      <span className="flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span className="flex min-w-0 items-center gap-2">
          {SpaceIcon && <SpaceIcon className="size-6 shrink-0" aria-hidden />}
          <span className="truncate">{spaceName}</span>
        </span>
        {amountRight}
      </span>
    ) : (
      <span className="flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span>Ordenar</span>
        {amountRight}
      </span>
    )
    ctaAction = onOrderClick
    ctaAriaLabel = cartMode === "orden-espacio" && spaceName ? `Ordenar — ${spaceName}` : "Ordenar"
  } else {
    ctaLabel = totalFormatted
    ctaAction = onPayClick
    ctaAriaLabel = `Cobrar ${totalFormatted}`
    ctaBlockedReason = payBlockedReason
  }

  return (
    <div className="shrink-0 bg-background p-2 pt-2">
      {/* Toggles CRÉDITO / INTERNO / IVA — atributos de la VENTA, no de la
          orden: no existe orden a crédito, "interno" es una venta sin valor
          fiscal y el IVA se define recién al cobrar. En modo orden-mostrador
          esta fila lleva el selector de fulfillment (Mostrador/Retiro/
          Envío, context/27 §D.4) en vez de los toggles — orden-espacio (dine_in
          por construcción) no muestra nada acá salvo VACIAR.
          Los tres son el MISMO chip (`ToggleChip`) que los toggles de venta —
          la fila es un patrón cerrado, no admite un control con forma propia.
          `min-h-6`: conserva su alto aunque quede vacía en cualquier modo, así
          el CTA de abajo NO se mueve ni un pixel al cambiar de modo — memoria
          muscular del cajero (Regla #10, context/14-ui-conventions.md). */}
      <div className="mb-2 flex min-h-6 items-center justify-center gap-2">
        {/* Nav de módulos (HotKeys / Órdenes / Espacios / Guardadas), solo
            mobile — el owner lo pidió acá, a la izquierda de CRÉDITO
            (2026-08-01). Va FUERA del condicional de los toggles: en modo
            orden/cotización esa rama no renderiza y la navegación desaparecía.
            Tamaño `size-6` para no crecer el `min-h-6` de la fila (§14 R10: el
            CTA de abajo no se mueve); el target táctil se agranda con un
            pseudo-elemento `-inset-2` (~40px) que no ocupa layout. En desktop
            el rail del sidebar sigue visible y este trigger no existe. */}
        <SidebarTrigger
          aria-label="Módulos"
          className="relative size-6 shrink-0 after:absolute after:-inset-2 after:content-[''] md:hidden"
        />
        {/* Los toggles son atributos de la VENTA: tampoco aplican en modo
            cotización (setPosMode ya los resetea al entrar). */}
        {!isOrderMode && cartMode !== "cotizacion" && (
          <>
            <ToggleChip
              label="CRÉDITO"
              active={credito}
              onClick={onToggleCredito}
            />
            <ToggleChip
              label="INTERNO"
              active={interno}
              onClick={onToggleInterno}
            />
            <button
              onClick={onToggleIva}
              aria-label={ivaRemoved ? "Restaurar IVA" : "Eliminar IVA"}
              className={cn(
                "rounded-full border px-2.5 py-0.5 text-[10px] font-bold tracking-wide transition-colors",
                ivaRemoved
                  ? "border-border bg-transparent text-muted-foreground/50"
                  : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground hover:text-foreground",
              )}
            >
              {ivaFormatted}
            </button>
          </>
        )}
        {cartMode === "orden-mostrador" && (
          <FulfillmentSelector value={fulfillment} onSelect={onSelectFulfillment} />
        )}
        {lineCount > 0 && (
          <button
            onClick={onClear}
            aria-label="Vaciar carrito"
            className="rounded-full border border-destructive/40 bg-transparent px-2.5 py-0.5 text-[10px] font-bold tracking-wide text-destructive transition-colors hover:bg-destructive/10"
          >
            VACIAR
          </button>
        )}
      </div>

      {/* Botón cobrar/ordenar — mismo slot, mismo color que la ModeBanner
          (context/20 "Colores de modo del POS"): venta = --primary sin
          tinte, orden = emerald, cotización (en vuelo) = amber. Modo orden
          (O1): "Ordenar" envía a cocina, no cobra — sin gate de caja/stock,
          mismo lugar que Pagar en modo venta. */}
      <PayCta
        label={ctaLabel}
        ariaLabel={ctaAriaLabel}
        onClick={lineCount > 0 ? ctaAction : undefined}
        disabled={lineCount === 0 || (isOrderMode && orderSubmitting) || savingQuote}
        blockedReason={ctaBlockedReason}
        color={visual.color}
      />
    </div>
  )
}

/**
 * CTA de cobro/orden del carrito.
 *
 * Cuando la caja NO puede emitir, el botón se muestra deshabilitado y el
 * tooltip dice por qué ("Caja tomada por Jose Benitez"). Antes eso era una
 * banda arriba de la toolbar; el owner la rechazó tres veces (2026-08-23) y la
 * regla quedó escrita: un impedimento se informa en el control que impide, no
 * en un cartel que además empuja el layout.
 *
 * `aria-disabled` y no `disabled`: un botón realmente deshabilitado no recibe
 * eventos de puntero, así que ni dispararía el tooltip ni dejaría "intentar
 * pagar" — y el cajero se quedaría sin la pantalla que explica el motivo y
 * ofrece volver a tomar la caja (`RegisterTakenPhase` en `pay-dialog.tsx`, a
 * donde lleva este click). Queda inerte para lo que importa: no cobra.
 *
 * El tooltip cubre el desktop; en tablet —donde no hay hover— el mismo texto
 * llega por el toque, que abre el diálogo con el motivo completo.
 */
function PayCta({
  label,
  ariaLabel,
  onClick,
  disabled,
  blockedReason,
  color,
}: {
  label: React.ReactNode
  ariaLabel: string
  onClick: (() => void) | undefined
  disabled: boolean
  blockedReason: string | null
  color: string | null
}) {
  const button = (
    <Button
      disabled={disabled}
      aria-disabled={blockedReason ? true : undefined}
      onClick={onClick}
      className={cn(
        "h-auto w-full rounded-full px-4 py-3 text-3xl font-bold active:scale-[0.98]",
        color && "text-black hover:text-black",
        // Mismo alto y mismo lugar que el CTA habilitado (Regla #10): el
        // bloqueo cambia el color, nunca la geometría.
        blockedReason &&
          "bg-muted text-muted-foreground hover:bg-muted hover:text-muted-foreground active:scale-100",
      )}
      style={color && !blockedReason ? { backgroundColor: color } : undefined}
      aria-label={blockedReason ? `${ariaLabel} — ${blockedReason}` : ariaLabel}
    >
      {label}
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

// `ToggleChip` vive ahora en components/register/toggle-chip.tsx — lo comparten
// esta fila y el selector de fulfillment, que necesitaba el chip idéntico.
