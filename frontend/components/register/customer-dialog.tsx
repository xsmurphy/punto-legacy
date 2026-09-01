"use client"

/**
 * Modal de buscar / crear cliente — Slice A2.
 *
 * Layout §6.1 (Buscar / Crear cliente):
 *   - Barra "Buscar clientes" arriba → searchCustomers() local.
 *   - Lista de resultados (nombre + doc); click → setCustomer + cierra.
 *   - Panel "CREAR CLIENTE" con 2 secciones:
 *       DATOS DE FACTURACIÓN: Razón Social, RUC/N° doc + lupa del padrón, Tipo ID,
 *                             botón CREAR CLIENTE, link Borrar Formulario.
 *       DATOS PERSONALES: Nombre y Apellido, Doc. Identidad, E-mail,
 *                         Teléfono (PhoneInput → E.164), Dirección,
 *                         Fecha de Nacimiento. 2 columnas.
 *   - Form: react-hook-form + Zod.
 *   - Al crear: POST al backend via executeCreateCustomer → patchCustomer + setCustomer
 *     + cierra. Si falla: toast de error, dialog permanece abierto.
 *
 * Ver context/16-app-next-rewrite.md §6.1 y §7 Slice A2.
 */

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { SearchCode, ChevronDown, Loader2, MoreVertical } from "lucide-react"
import { toast } from "sonner"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import { Input } from "@/components/ui/input"
import { useIsCoarsePointer } from "@/hooks/use-mobile"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Separator } from "@/components/ui/separator"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { PhoneInput } from "@/components/forms/phone-input"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import type { CountryCode } from "libphonenumber-js"
import { DatePicker } from "@/components/date-picker"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { usePosUIStore } from "@/lib/ui/store"
import { searchCustomers } from "@/lib/catalog/search"
import { executeCreateCustomer } from "@/lib/commands/create-customer"
import {
  contactIdTypesFor,
  personalIdFieldCopy,
  taxIdFieldCopy,
} from "@/lib/contact-id-types"
import { useTaxpayerLookup } from "@/hooks/use-contacts"
import { usePosContactById, usePosContactSearch } from "@/hooks/use-pos-contact-lookup"
import { ApiError } from "@/lib/api-client"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"
import { EmptyState } from "@/components/empty-state"
import { SearchX } from "lucide-react"
import {
} from "@/components/ui/sheet"
import { ContactDetailView } from "@/components/domain/contacts/contact-detail-view"

// ── Zod schema ────────────────────────────────────────────────────────────────

const customerFormSchema = z.object({
  // DATOS DE FACTURACIÓN
  fiscalName: z.string().min(1, "Requerido"),
  tin: z.string().optional(),

  // DATOS PERSONALES (opcionales — sección colapsada por default)
  firstName: z.string().optional(),
  lastName: z.string().optional(),
  ci: z.string().optional(),
  // Tipo de documento (Tabla 3 SET) — exclusivo de Paraguay. `null` = no
  // elegido (tenant no-PY, o cajero no tocó el selector → backend infiere).
  idType: z.number().nullable().optional(),
  email: z.string().email("Email inválido").optional().or(z.literal("")),
  // phoneValue: valor nacional visible (solo para el input),
  // phoneE164: E.164 guardado en el store / enviado al backend.
  phoneValue: z.string().optional(),
  phoneE164: z.string().nullable().optional(),
  phoneCountry: z.string().optional(),
  birthdate: z.string().optional(), // ISO yyyy-MM-dd
})

type CustomerFormValues = z.infer<typeof customerFormSchema>

// ── Props ─────────────────────────────────────────────────────────────────────

interface CustomerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// ── Component ─────────────────────────────────────────────────────────────────

export function CustomerDialog({ open, onOpenChange }: CustomerDialogProps) {
  const searchInputRef = React.useRef<HTMLInputElement>(null)
  const [detailCustomerId, setDetailCustomerId] = React.useState<string | null>(null)

  const customers = useCatalogStore((s) => s.customers)
  const patchCustomer = useCatalogStore((s) => s.patchCustomer)
  const setCustomer = useCartStore((s) => s.setCustomer)
  // Query en el store para que persista al cerrar y reabrir el modal.
  const searchQuery = usePosUIStore((s) => s.customerSearchQuery)
  const setSearchQuery = usePosUIStore((s) => s.setCustomerSearchQuery)
  const clearSearchQuery = usePosUIStore((s) => s.clearCustomerSearchQuery)

  // Vacío = mostrar el form de crear cliente; con texto = listar clientes.
  const trimmed = searchQuery.trim()
  const searchResults = React.useMemo(
    () => (trimmed ? searchCustomers(customers, trimmed, 20) : []),
    [customers, trimmed],
  )
  const isSearching = trimmed.length > 0

  // Autofocus solo con puntero fino (desktop). En táctil subiría el teclado
  // del OS sin que nadie lo pida e iOS scrollea el documento para revelar el
  // campo — la app queda corrida (reporte del owner 2026-08-25). El cajero
  // toca el buscador cuando quiere tipear.
  const coarse = useIsCoarsePointer()
  React.useEffect(() => {
    if (open) {
      setDetailCustomerId(null)
      if (coarse) return
      const id = setTimeout(() => searchInputRef.current?.focus(), 50)
      return () => clearTimeout(id)
    }
  }, [open, coarse])

  function handleSelectCustomer(c: PosCustomer) {
    setCustomer(c)
    clearSearchQuery()
    onOpenChange(false)
  }

  function handleCustomerCreated(c: PosCustomer) {
    // Parchar el catálogo local y seleccionar el cliente recién creado.
    patchCustomer(c)
    setCustomer(c)
    clearSearchQuery()
    onOpenChange(false)
  }

  return (
    <>
    <Dialog
      open={!!detailCustomerId}
      onOpenChange={(o) => { if (!o) setDetailCustomerId(null) }}
    >
      {/*
        Ficha del cliente: contenido DENSO (nav de 9 secciones + gráficos +
        formulario). En móvil no entra centrada — va fullscreen, que es lo que
        el primitive ya resuelve. `p-0` porque la ficha trae chrome propio
        (header, nav, breadcrumb, footer "Añadir"); el `max-sm:p-0` es el reset
        obligatorio del gutter+insets que `mobileFullscreen` pone por default
        (ver docblock de `DialogContent`) — los insets los descuenta
        `ContactDetailView` en SU chrome.

        `sm:h-[85dvh]` sin `max-h`: el `max-h` safe-aware del primitive queda
        vigente y gana sobre el `height` si el dispositivo tiene notch. Antes el
        call-site ponía `max-h-[85vh]`, que lo pisaba.
      */}
      <DialogContent
        showCloseButton={false}
        mobileFullscreen
        className="flex flex-col gap-0 overflow-hidden p-0 max-sm:p-0 sm:h-[85dvh] sm:w-full sm:max-w-5xl"
      >
        {detailCustomerId && (
          <ContactDetailView
            customerId={detailCustomerId}
            variant="pos"
            nav="sidebar"
            onClose={() => setDetailCustomerId(null)}
            onSelectForSale={(contact) => {
              setCustomer(contact)
              setDetailCustomerId(null)
              onOpenChange(false)
            }}
          />
        )}
      </DialogContent>
    </Dialog>
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Command palette top-aligned: el campo de búsqueda arranca cerca del
          borde de arriba y la lista cuelga abajo. `max-h` en `dvh` y menos
          `--kb-inset`: con el teclado abierto la lista se recorta a lo que
          queda visible en vez de extenderse por detrás del teclado (el `86vh`
          anterior medía el viewport de layout, que en iOS no se entera de que
          el teclado subió).

          El `top` SÍ se toca (2026-09-01). Este diálogo pisa el centrado del
          primitive con `top-[7dvh] translate-y-0`, y ese `7dvh` es un offset
          desde el borde del viewport de LAYOUT: con el teclado abierto en el
          iPhone lo visible es [356, 797] de 797, así que el modal se dibujaba
          en 56 — 300px por encima de la pantalla. Sumarle `--kb-top` lo ancla
          al borde de arriba de lo VISIBLE conservando el mismo margen, que es
          lo que el cajero ya conoce; sin teclado la variable vale 0 y queda
          exactamente donde estaba. Es la contracara de la nota vieja: lo que
          no se toca es el MARGEN, no la coordenada. */}
      <DialogContent
        className={cn(
          "top-[calc(var(--kb-top)+7dvh)] flex max-h-[calc(86dvh-var(--kb-inset))] translate-y-0 flex-col gap-3 border-none bg-transparent p-0 shadow-none ring-0 sm:max-w-xl",
          // Animación de entrada/salida propia — ver context/20 §Overlays.
          // El `DialogContent` de este modal es TRANSPARENTE (command
          // palette: la superficie visible son la pastilla y la lista, no el
          // content), así que el `fade-in-0`+`zoom-in-95` del primitive
          // anima un contenedor sin pintura propia sobre un área diminuta y
          // en 100ms se lee como instantáneo (reporte del owner 2026-08-29:
          // "en el menú del POS sí se nota el fade, en los buscadores no").
          // `slide-in-from-top` le da recorrido real: setea
          // `--tw-enter-translate-y`, la variable que el keyframe `enter` ya
          // lee. Compone limpio con el centrado porque en Tailwind v4 las
          // utilidades de translate escriben la propiedad `translate`, NO
          // `transform` — son propiedades distintas y no se pisan.
          // Bajar desde arriba es además el idioma de la familia
          // command-palette (Spotlight, ⌘K).
          "data-open:slide-in-from-top-4 data-closed:slide-out-to-top-4",
        )}
        showCloseButton={false}
        // Los Select/DatePicker del form de alta se portalean al <body>, FUERA
        // de este content. En táctil, elegir una opción podía leerse como
        // "interacción afuera" y cerraba el diálogo entero con el form a medio
        // llenar (reporte del owner 2026-08-25, "Tipo de documento"). Un toque
        // dentro de cualquier portal de Radix no es un cierre.
        onInteractOutside={(e) => {
          const t = e.target as HTMLElement | null
          if (
            t?.closest?.(
              "[data-radix-popper-content-wrapper], [data-slot=select-content], [data-slot=popover-content]",
            )
          ) {
            e.preventDefault()
          }
        }}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Buscar o crear cliente</DialogTitle>
          <DialogDescription>
            Buscá un cliente existente o completá el formulario para crear uno nuevo.
          </DialogDescription>
        </DialogHeader>

        {/* ── Pill del input (separado del panel) ── */}
        <div className="shrink-0 rounded-full bg-popover px-6 py-1.5 shadow-lg">
          <Input
            ref={searchInputRef}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar clientes"
            className="h-auto border-0 bg-transparent px-0 text-center text-lg font-semibold shadow-none lg:text-xl placeholder:font-semibold placeholder:text-muted-foreground focus-visible:ring-0"
            autoComplete="off"
            aria-label="Buscar clientes"
          />
        </div>

        {/* Vacío → form de crear cliente. Con texto → lista de clientes. */}
        {isSearching ? (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-popover shadow-lg">
            {searchResults.length === 0 ? (
              <EmptyState
                icon={SearchX}
                title="Sin resultados"
                description={`Ningún cliente coincide con "${trimmed}".`}
                showMarquee={false}
              />
            ) : (
              <ul role="listbox" aria-label="Resultados de clientes" className="overflow-y-auto py-1">
                {searchResults.map((c) => (
                  <CustomerResultRow
                    key={c.id}
                    customer={c}
                    onSelect={() => handleSelectCustomer(c)}
                    onDetail={() => setDetailCustomerId(c.id)}
                  />
                ))}
              </ul>
            )}
          </div>
        ) : (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-popover shadow-lg">
            <CreateCustomerForm
              onCreated={handleCustomerCreated}
              onSelectExisting={handleSelectCustomer}
            />
          </div>
        )}
      </DialogContent>
    </Dialog>
    </>
  )
}

// ── Fila de resultado de búsqueda ─────────────────────────────────────────────

function CustomerResultRow({
  customer,
  onSelect,
  onDetail,
}: {
  customer: PosCustomer
  onSelect: () => void
  onDetail: () => void
}) {
  return (
    <li className="flex items-center">
      <button
        onClick={onSelect}
        className={cn(
          "flex min-w-0 flex-1 items-center gap-3 px-6 py-2.5 text-left",
          "transition-colors hover:bg-muted/50 active:bg-muted",
          "focus-visible:outline-none focus-visible:bg-muted/50",
        )}
      >
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium text-foreground lg:text-base">
            {customer.name}
          </p>
          {customer.tin && (
            <p className="text-xs text-muted-foreground lg:text-sm">{customer.tin}</p>
          )}
        </div>
      </button>
      <Button
        variant="ghost"
        size="icon"
        className="mr-2 shrink-0 text-muted-foreground"
        aria-label="Ver detalle del cliente"
        onClick={(e) => {
          e.stopPropagation()
          onDetail()
        }}
      >
        <MoreVertical className="size-4" />
      </Button>
    </li>
  )
}

// ── Formulario de creación de cliente ─────────────────────────────────────────

/**
 * Choque de unicidad devuelto por el backend (409 de `/v1/contacts`).
 * `field` dice en qué campo pintarlo; el resto identifica al contacto que ya
 * tiene ese número, para poder usarlo en vez de crear un duplicado.
 * Ver `ContactService::assertIdentityIsFree()`.
 */
interface DuplicateConflict {
  field: "ci" | "phone"
  contactId: string
  contactName: string
  message: string
}

/**
 * Lee el `error.details` del 409. Devuelve null ante cualquier otra cosa —
 * un error de red o un 500 no se pintan como choque de unicidad.
 */
function parseDuplicateConflict(err: unknown): DuplicateConflict | null {
  if (!(err instanceof ApiError) || err.status !== 409) return null
  const details = (err.payload as { error?: { details?: Record<string, unknown> } } | null)
    ?.error?.details
  const field = details?.field
  const contactId = details?.contactId
  if ((field !== "ci" && field !== "phone") || typeof contactId !== "string") return null
  return {
    field,
    contactId,
    contactName: typeof details?.contactName === "string" ? details.contactName : "",
    message: err.message,
  }
}

/**
 * El choque, pintado EN el campo que lo causó (no en un toast ni en una
 * banda): dice qué número está repetido, de quién es, y ofrece pasar a ese
 * cliente con un toque. Sin el atajo, el cajero tendría que cerrar el alta a
 * medio llenar y buscar el nombre a mano — que es justo lo que el bloqueo
 * pretende evitar.
 */
function ConflictNotice({
  conflict,
  onUse,
  isPending,
}: {
  conflict: DuplicateConflict
  onUse: (contactId: string) => void
  isPending: boolean
}) {
  return (
    <div role="alert" className="flex flex-col items-start gap-1">
      <p className="text-xs text-destructive">{conflict.message}</p>
      <Button
        type="button"
        variant="link"
        onClick={() => onUse(conflict.contactId)}
        disabled={isPending}
        className="h-auto p-0 text-xs"
      >
        {isPending && <Loader2 className="mr-1 size-3 animate-spin" />}
        Usar ese cliente
      </Button>
    </div>
  )
}

function CreateCustomerForm({
  onCreated,
  onSelectExisting,
}: {
  onCreated: (c: PosCustomer) => void
  /** Usar un cliente que YA existe: lo pone en el carrito y cierra el modal. */
  onSelectExisting: (c: PosCustomer) => void
}) {
  // Config del tenant (PosConfig), ya hidratada en el store del POS: de acá
  // salen el país Y la etiqueta del documento fiscal, sin round-trip. El alta
  // de cliente es offline-first, así que el copy tiene que resolverse con el
  // snapshot del bootstrap y nada más.
  const tenantConfig = useCatalogStore((s) => s.config)
  const tenantCountry = tenantConfig?.country
  // Mismo dato, tipado como CountryCode para el selector de teléfono.
  const tenantPhoneCountry = (tenantCountry || DEFAULT_COUNTRY) as CountryCode
  // Tipos de documento persistibles del país (hoy solo PY tiene taxonomía).
  // Antes era `country === "PY"`: ver `lib/contact-id-types.ts`.
  const idTypes = contactIdTypesFor(tenantConfig)
  const taxCopy = taxIdFieldCopy(tenantConfig)

  const {
    register,
    handleSubmit,
    control,
    reset,
    setValue,
    getValues,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<CustomerFormValues>({
    resolver: zodResolver(customerFormSchema),
    defaultValues: {
      fiscalName: "",
      tin: "",
      firstName: "",
      lastName: "",
      ci: "",
      // 12 explícito (no null): el Select pintaba "Cédula" por fallback
      // cosmético sin llamar nunca a onChange, así que lo visible y lo
      // enviado podían diferir.
      idType: 12,
      email: "",
      phoneValue: "",
      phoneE164: null,
      // País del selector de teléfono: el del TENANT (viaja en el snapshot del
      // bootstrap del POS, así que no agrega ni un fetch al alta offline).
      // Antes era "PY" fijo — un comercio brasileño daba de alta clientes con
      // el prefijo paraguayo preseleccionado.
      phoneCountry: tenantCountry || DEFAULT_COUNTRY,
      birthdate: "",
    },
  })

  const birthdateValue = watch("birthdate") ?? ""
  const idType = watch("idType")
  const personalCopy = personalIdFieldCopy(tenantConfig, idType)

  // Clientes del tenant que ya tienen ese identificador fiscal. Se llenan al
  // tocar la lupa, junto con el padrón — ver handleLookupTaxId().
  const [localMatches, setLocalMatches] = React.useState<PosCustomer[]>([])
  // Choque de documento personal / teléfono devuelto por el backend al guardar.
  const [conflict, setConflict] = React.useState<DuplicateConflict | null>(null)

  // "Datos personales" arranca colapsada, pero los dos campos que pueden
  // chocar (documento y teléfono) viven ahí adentro. Un error pintado dentro
  // de una sección cerrada es un error invisible, así que la sección pasa a
  // ser controlada y se abre sola cuando el backend rechaza el guardado.
  const [personalOpen, setPersonalOpen] = React.useState(false)

  const searchLocalContacts = usePosContactSearch()
  const fetchContactById = usePosContactById()

  // El choque deja de aplicar en cuanto el cajero toca el número: el mensaje
  // nombra a un cliente concreto y sostenerlo sobre un valor que ya cambió
  // sería mentir sobre qué está mal. Va en el onChange de los dos campos y NO
  // en un `useEffect` sobre `watch(...)`: un setState dentro de un efecto
  // encadena un render extra por tecla (`react-hooks/set-state-in-effect`).
  const clearConflict = React.useCallback(() => setConflict(null), [])

  /** Pasa a usar el cliente en conflicto en vez de crear un duplicado. */
  function handleUseConflictingContact(contactId: string) {
    fetchContactById.mutate(contactId, {
      onSuccess: (c) => {
        reset()
        onSelectExisting(c)
      },
      onError: () => toast.error("No se pudo abrir ese cliente"),
    })
  }

  async function onSubmit(values: CustomerFormValues) {
    // Nombre display: razón social tiene prioridad; si no, nombre + apellido.
    const displayName =
      values.fiscalName.trim() ||
      `${values.firstName ?? ""} ${values.lastName ?? ""}`.trim()

    setConflict(null)
    try {
      const customer = await executeCreateCustomer({
        name: displayName,
        fiscalName: values.fiscalName.trim() || undefined,
        phone: values.phoneE164 ?? null,
        tin: values.tin?.trim() || undefined,
        ci: values.ci?.trim() || undefined,
        // Solo se manda si el país tiene taxonomía persistible: el backend
        // igual lo descarta (ContactService::isPyTenant), esto es no mandar ruido.
        idType: idTypes.length > 0 ? values.idType ?? undefined : undefined,
        email: values.email?.trim() || undefined,
      })
      reset()
      onCreated(customer)
    } catch (err) {
      // Choque de documento personal / teléfono: NO va a un toast. El toast se
      // desvanece y no dice en qué campo está el problema; el cajero necesita
      // verlo pegado al número que tiene que corregir, con el nombre del
      // cliente que ya lo usa y el atajo para pasar a ese cliente.
      const dup = parseDuplicateConflict(err)
      if (dup) {
        setConflict(dup)
        setPersonalOpen(true)
        return
      }
      // Mostrar el mensaje del backend si está disponible, sino genérico.
      const msg =
        err instanceof ApiError
          ? err.message
          : "No se pudo crear el cliente. Intentá de nuevo."
      toast.error(msg)
      // NO cerrar el dialog — el cajero corrige y reintenta.
    }
  }

  function handleClear() {
    reset()
    setLocalMatches([])
    setConflict(null)
  }

  // Lookup del identificador fiscal en el padrón — lo resuelve el BACKEND
  // (`/v1/contacts?resource=taxpayer`), que consulta el padrón del emisor de
  // facturación electrónica si el comercio lo tiene conectado y cae al padrón
  // público si no. Antes se pegaba desde acá contra turuc.com.py: el alta de
  // clientes dependía de que el navegador del cajero alcanzara a un tercero.
  //
  // Al recibir los datos auto-completa Razón Social y reemplaza el campo TIN
  // con el identificador formateado (ej. "7659394" → "7659394-0").
  const lookupTaxpayer = useTaxpayerLookup()

  /**
   * La lupa consulta DOS cosas distintas con el mismo número, en paralelo:
   *
   *   1. Los clientes del TENANT — "¿este ya es cliente mío?". Si lo es, no
   *      hace falta crearlo de nuevo (pedido del owner 2026-08-31).
   *   2. El padrón fiscal — "¿existe en el registro del fisco?", que es lo
   *      que autocompleta la razón social.
   *
   * Se muestran separados porque responden preguntas distintas, y los del
   * tenant van PRIMERO: si el cliente ya existe, el alta sobra. Ninguno de
   * los dos bloquea nada — un mismo identificador fiscal puede tener varios
   * contactos (varias personas facturando a nombre de la misma empresa es un
   * caso legítimo), así que el cajero elige: usar el existente o seguir de
   * largo y crear uno nuevo.
   */
  function handleLookupTaxId() {
    const raw = (getValues("tin") || "").trim()
    if (!raw) {
      toast.warning(`Ingresá un ${taxCopy.label} para buscar`)
      return
    }

    setLocalMatches([])
    searchLocalContacts.mutate(raw, {
      onSuccess: setLocalMatches,
      // Silencioso a propósito: esto es una AYUDA. Si el POS está sin red, el
      // alta tiene que seguir funcionando igual — un error acá no puede
      // frenar al cajero ni robarle la atención con un toast.
      onError: () => setLocalMatches([]),
    })

    lookupTaxpayer.mutate(raw, {
      onSuccess: (data) => {
        setValue("fiscalName", data.name, { shouldValidate: true, shouldDirty: true })
        setValue("tin", data.ruc, { shouldValidate: true, shouldDirty: true })
        toast.success(data.status ? `${data.name} · ${data.status}` : data.name)
      },
      onError: (err) =>
        toast.error(
          err instanceof ApiError && err.status === 404
            ? `${taxCopy.label} no encontrado en el padrón`
            : `No se pudo consultar el ${taxCopy.label}`,
        ),
    })
  }

  const isLookingUp = lookupTaxpayer.isPending || searchLocalContacts.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex min-h-0 flex-1 flex-col">
      {/* ── Barra de acciones ARRIBA — guardar sin scrollear ── */}
      <div className="flex shrink-0 items-center justify-between border-b border-border bg-background px-6 py-3">
        <p className="text-[11px] font-bold uppercase tracking-widest text-muted-foreground">
          Crear cliente
        </p>
        <div className="flex items-center gap-3">
          <Button
            type="button"
            variant="link"
            onClick={handleClear}
            className="h-auto p-0 text-xs text-muted-foreground hover:text-foreground"
          >
            Borrar
          </Button>
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
            Crear cliente
          </Button>
        </div>
      </div>

      {/* ── Campos (scrolleables) ── */}
      <div className="min-h-0 flex-1 overflow-y-auto">
        {/* ── Sección DATOS DE FACTURACIÓN ── */}
        <div className="px-6 pb-4 pt-4">
          <p className="mb-3 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
            Datos de Facturación
          </p>

          <div className="flex flex-col gap-3">
            {/* Razón Social */}
            <div className="flex flex-col gap-1">
              <Label htmlFor="fiscalName" className="text-xs">
                Razón Social
              </Label>
              <Input
                id="fiscalName"
                aria-invalid={!!errors.fiscalName}
                {...register("fiscalName")}
              />
              {errors.fiscalName && (
                <p className="text-xs text-destructive">{errors.fiscalName.message}</p>
              )}
            </div>

            {/* RUC / N° documento + lupa del padrón (backend) */}
            <div className="flex flex-col gap-1">
              <Label htmlFor="tin" className="text-xs">
                {taxCopy.label} / N° Documento
              </Label>
              <div className="flex gap-2">
                <Input
                  id="tin"
                  className="flex-1"
                  placeholder={taxCopy.placeholder}
                  {...register("tin")}
                />
                {/* Lupa: busca el número EN LOS CLIENTES DEL COMERCIO y en el
                    padrón fiscal, las dos cosas — ver handleLookupTaxId() */}
                <Button
                  type="button"
                  variant="outline"
                  size="icon-sm"
                  onClick={handleLookupTaxId}
                  disabled={isLookingUp}
                  title={`Buscar ${taxCopy.label} en tus clientes y en el padrón`}
                  aria-label={`Buscar ${taxCopy.label} en tus clientes y en el padrón`}
                  className="shrink-0"
                >
                  {isLookingUp ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <SearchCode className="size-4" />
                  )}
                </Button>
              </div>
            </div>

            {/* ── Clientes del comercio que ya tienen ese identificador ──
                Van pegados al campo que los produjo y ARRIBA de cualquier
                dato del padrón: responden "ya es tu cliente", que es la
                pregunta que hace innecesaria el alta entera. El padrón es
                otra cosa ("existe en el registro fiscal") y sigue llenando el
                formulario como siempre. */}
            {localMatches.length > 0 && (
              <div className="rounded-md border border-border">
                <p className="border-b border-border px-3 py-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                  Ya es tu cliente
                </p>
                <ul role="listbox" aria-label="Clientes existentes con ese documento">
                  {localMatches.map((c) => (
                    <li key={c.id}>
                      <button
                        type="button"
                        onClick={() => onSelectExisting(c)}
                        className={cn(
                          "flex w-full min-w-0 items-center px-3 py-2 text-left",
                          "transition-colors hover:bg-muted/50 active:bg-muted",
                          "focus-visible:bg-muted/50 focus-visible:outline-none",
                          "max-sm:min-h-(--pos-touch-min)",
                        )}
                      >
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium text-foreground">
                            {c.name}
                          </span>
                          {c.tin && (
                            <span className="block text-xs text-muted-foreground">{c.tin}</span>
                          )}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
                <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
                  Tocá uno para usarlo, o seguí completando el formulario para
                  crear otro cliente con el mismo {taxCopy.label}.
                </p>
              </div>
            )}
          </div>
        </div>

        <Separator />

        {/* ── Sección DATOS PERSONALES — colapsada por default ── */}
        <Collapsible open={personalOpen} onOpenChange={setPersonalOpen}>
          <div className="px-6 pt-4 pb-4">
            <CollapsibleTrigger asChild>
              <button
                type="button"
                className="flex w-full items-center justify-between text-[10px] font-bold tracking-widest text-muted-foreground uppercase hover:text-foreground transition-colors [&[data-state=open]>svg]:rotate-180"
              >
                Datos personales (opcional)
                <ChevronDown className="size-3 transition-transform duration-200" />
              </button>
            </CollapsibleTrigger>
          </div>

          <CollapsibleContent>
            <div className="px-6 pb-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {/* Nombre */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="firstName" className="text-xs">
                    Nombre
                  </Label>
                  <Input
                    id="firstName"
                    aria-invalid={!!errors.firstName}
                    {...register("firstName")}
                  />
                  {errors.firstName && (
                    <p className="text-xs text-destructive">{errors.firstName.message}</p>
                  )}
                </div>

                {/* Apellido */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="lastName" className="text-xs">
                    Apellido
                  </Label>
                  <Input
                    id="lastName"
                    {...register("lastName")}
                  />
                </div>

                {/* Teléfono */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="phone" className="text-xs">
                    Teléfono
                  </Label>
                  <Controller
                    name="phoneValue"
                    control={control}
                    render={({ field }) => (
                      <PhoneInput
                        id="phone"
                        value={field.value ?? ""}
                        country={tenantPhoneCountry}
                        onChange={(v) => {
                          field.onChange(v.value)
                          setValue("phoneE164", v.e164)
                          setValue("phoneCountry", v.country)
                          clearConflict()
                        }}
                      />
                    )}
                  />
                  {conflict?.field === "phone" && (
                    <ConflictNotice
                      conflict={conflict}
                      onUse={handleUseConflictingContact}
                      isPending={fetchContactById.isPending}
                    />
                  )}
                </div>

                {/* Tipo de documento — solo en países con taxonomía propia
                    (hoy PY: Tabla 3 de la SET). Ver lib/contact-id-types.ts */}
                {idTypes.length > 0 && (
                  <div className="flex flex-col gap-1">
                    <Label htmlFor="idType" className="text-xs">
                      Tipo de documento
                    </Label>
                    <Controller
                      name="idType"
                      control={control}
                      render={({ field }) => (
                        <Select
                          value={String(field.value ?? 12)}
                          onValueChange={(v) => field.onChange(Number(v))}
                        >
                          <SelectTrigger id="idType">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {idTypes.map((t) => (
                              <SelectItem key={t.code} value={String(t.code)}>
                                {t.label}
                                {t.noEinvoice && (
                                  <span className="text-xs text-muted-foreground">
                                    · sin factura electrónica
                                  </span>
                                )}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </div>
                )}

                {/* Doc. de Identidad — label/placeholder acompañan el tipo elegido */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="ci" className="text-xs">
                    {personalCopy.label}
                  </Label>
                  <Input
                    id="ci"
                    placeholder={personalCopy.placeholder}
                    aria-invalid={conflict?.field === "ci"}
                    {...register("ci", { onChange: clearConflict })}
                  />
                  {conflict?.field === "ci" && (
                    <ConflictNotice
                      conflict={conflict}
                      onUse={handleUseConflictingContact}
                      isPending={fetchContactById.isPending}
                    />
                  )}
                </div>

                {/* E-mail */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="email" className="text-xs">
                    E-mail
                  </Label>
                  <Input
                    id="email"
                    type="email"
                    aria-invalid={!!errors.email}
                    {...register("email")}
                  />
                  {errors.email && (
                    <p className="text-xs text-destructive">{errors.email.message}</p>
                  )}
                </div>

                {/* Fecha de Nacimiento — DatePicker shadcn, no input nativo */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="birthdate" className="text-xs">
                    Fecha de Nacimiento
                  </Label>
                  <DatePicker
                    id="birthdate"
                    // Mismo alto táctil que los inputs de al lado: el trigger
                    // del DatePicker no es [data-slot=input] y el mínimo de 44
                    // no lo alcanzaba — quedaba visiblemente más fino.
                    className="max-sm:min-h-(--pos-touch-min)"
                    value={birthdateValue}
                    onChange={(v) => setValue("birthdate", v, { shouldDirty: true })}
                    placeholder="Seleccionar fecha"
                    captionLayout="dropdown"
                    startMonth={new Date(1920, 0)}
                    endMonth={new Date()}
                    defaultMonth={new Date(1990, 0)}
                  />
                </div>
              </div>
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>
    </form>
  )
}
