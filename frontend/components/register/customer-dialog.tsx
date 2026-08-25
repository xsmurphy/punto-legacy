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
import { CONTACT_ID_TYPES, ciFieldCopyForIdType } from "@/lib/contact-id-types"
import { useTaxpayerLookup } from "@/hooks/use-contacts"
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

  // Solo autofocus al abrir — no limpiamos el query para preservar la búsqueda.
  React.useEffect(() => {
    if (open) {
      setDetailCustomerId(null)
      const id = setTimeout(() => searchInputRef.current?.focus(), 50)
      return () => clearTimeout(id)
    }
  }, [open])

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
      <DialogContent
        showCloseButton={false}
        className="flex h-[85vh] max-h-[85vh] w-full max-w-5xl flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl"
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
      <DialogContent
        className="top-[7vh] flex max-h-[86vh] translate-y-0 flex-col gap-3 border-none bg-transparent p-0 shadow-none ring-0 sm:max-w-xl"
        showCloseButton={false}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Buscar o crear cliente</DialogTitle>
          <DialogDescription>
            Buscá un cliente existente o completá el formulario para crear uno nuevo.
          </DialogDescription>
        </DialogHeader>

        {/* ── Pill del input (separado del panel) ── */}
        <div className="shrink-0 rounded-full bg-popover px-6 py-4 shadow-lg">
          <Input
            ref={searchInputRef}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar clientes"
            className="h-auto border-0 bg-transparent px-0 text-center text-lg font-semibold shadow-none placeholder:font-semibold placeholder:text-muted-foreground focus-visible:ring-0"
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
            <CreateCustomerForm onCreated={handleCustomerCreated} />
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
          <p className="truncate text-sm font-medium text-foreground">
            {customer.name}
          </p>
          {customer.tin && (
            <p className="text-xs text-muted-foreground">{customer.tin}</p>
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

function CreateCustomerForm({
  onCreated,
}: {
  onCreated: (c: PosCustomer) => void
}) {
  // Gate exclusivo de Paraguay — mismo dato que el bootstrap del panel
  // (PosConfig.country), ya hidratado en el store del POS (sin round-trip
  // adicional). Ver ContactService::isPyTenant() del lado del backend.
  const tenantCountry = useCatalogStore((s) => s.config?.country)
  // Mismo dato, tipado como CountryCode para el selector de teléfono.
  const tenantPhoneCountry = (tenantCountry || DEFAULT_COUNTRY) as CountryCode
  const isPyTenant = tenantCountry === "PY"

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
  const ciCopy = ciFieldCopyForIdType(idType)

  async function onSubmit(values: CustomerFormValues) {
    // Nombre display: razón social tiene prioridad; si no, nombre + apellido.
    const displayName =
      values.fiscalName.trim() ||
      `${values.firstName ?? ""} ${values.lastName ?? ""}`.trim()

    try {
      const customer = await executeCreateCustomer({
        name: displayName,
        fiscalName: values.fiscalName.trim() || undefined,
        phone: values.phoneE164 ?? null,
        tin: values.tin?.trim() || undefined,
        ci: values.ci?.trim() || undefined,
        idType: isPyTenant ? values.idType ?? undefined : undefined,
        email: values.email?.trim() || undefined,
      })
      reset()
      onCreated(customer)
    } catch (err) {
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
  }

  // Lookup del RUC en el padrón — lo resuelve el BACKEND
  // (`/v1/contacts?resource=taxpayer`), que consulta el padrón del emisor de
  // facturación electrónica si el comercio lo tiene conectado y cae al padrón
  // público si no. Antes se pegaba desde acá contra turuc.com.py: el alta de
  // clientes dependía de que el navegador del cajero alcanzara a un tercero.
  //
  // Al recibir los datos auto-completa Razón Social y reemplaza el campo TIN
  // con el RUC formateado (ej. "7659394" → "7659394-0").
  const lookupTaxpayer = useTaxpayerLookup()
  function handleLookupRuc() {
    const raw = (getValues("tin") || "").trim()
    if (!raw) {
      toast.warning("Ingresá un RUC para buscar")
      return
    }
    lookupTaxpayer.mutate(raw, {
      onSuccess: (data) => {
        setValue("fiscalName", data.name, { shouldValidate: true, shouldDirty: true })
        setValue("tin", data.ruc, { shouldValidate: true, shouldDirty: true })
        toast.success(data.status ? `${data.name} · ${data.status}` : data.name)
      },
      onError: (err) =>
        toast.error(
          err instanceof ApiError && err.status === 404
            ? "RUC no encontrado"
            : "No se pudo consultar el RUC",
        ),
    })
  }

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
                RUC / N° Documento
              </Label>
              <div className="flex gap-2">
                <Input
                  id="tin"
                  className="flex-1"
                  {...register("tin")}
                />
                {/* Lupa: lookup del RUC en el padrón, resuelto por el backend */}
                <Button
                  type="button"
                  variant="outline"
                  size="icon-sm"
                  onClick={handleLookupRuc}
                  disabled={lookupTaxpayer.isPending}
                  title="Buscar datos del RUC"
                  aria-label="Buscar datos del RUC"
                  className="shrink-0"
                >
                  {lookupTaxpayer.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <SearchCode className="size-4" />
                  )}
                </Button>
              </div>
            </div>
          </div>
        </div>

        <Separator />

        {/* ── Sección DATOS PERSONALES — colapsada por default ── */}
        <Collapsible defaultOpen={false}>
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

                {/* Tipo de documento — exclusivo de Paraguay (Tabla 3 SET) */}
                {isPyTenant && (
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
                            {CONTACT_ID_TYPES.map((t) => (
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
                    {isPyTenant ? ciCopy.label : "Doc. de Identidad"}
                  </Label>
                  <Input
                    id="ci"
                    placeholder={isPyTenant ? ciCopy.placeholder : undefined}
                    {...register("ci")}
                  />
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
                        }}
                      />
                    )}
                  />
                </div>

                {/* Fecha de Nacimiento — DatePicker shadcn, no input nativo */}
                <div className="flex flex-col gap-1">
                  <Label htmlFor="birthdate" className="text-xs">
                    Fecha de Nacimiento
                  </Label>
                  <DatePicker
                    id="birthdate"
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
