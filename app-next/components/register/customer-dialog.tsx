"use client"

/**
 * Modal de buscar / crear cliente — Slice A2.
 *
 * Layout §6.1 (Buscar / Crear cliente):
 *   - Barra "Buscar clientes" arriba → searchCustomers() local.
 *   - Lista de resultados (nombre + doc); click → setCustomer + cierra.
 *   - Panel "CREAR CLIENTE" con 2 secciones:
 *       DATOS DE FACTURACIÓN: Razón Social, RUC/N° doc + lupa stub, Tipo ID,
 *                             botón CREAR CLIENTE, link Borrar Formulario.
 *       DATOS PERSONALES: Nombre y Apellido, Doc. Identidad, E-mail,
 *                         Teléfono (PhoneInput → E.164), Dirección,
 *                         Fecha de Nacimiento. 2 columnas.
 *   - Form: react-hook-form + Zod.
 *   - Al crear: agrega al catalog store local (patchCustomer) + setCustomer
 *     + cierra. TODO cablear executeCreateCustomer (ver lib/commands/create-customer.ts).
 *
 * Ver context/16-app-next-rewrite.md §6.1 y §7 Slice A2.
 */

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Search, SearchCode, X } from "lucide-react"
import type { CountryCode } from "libphonenumber-js"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { PhoneInput } from "@/components/forms/phone-input"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { searchCustomers } from "@/lib/catalog/search"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"

// ── Zod schema ────────────────────────────────────────────────────────────────

const customerFormSchema = z.object({
  // DATOS DE FACTURACIÓN
  fiscalName: z.string().min(1, "Requerido"),
  tin: z.string().optional(),
  tinType: z.enum(["RUC", "CI", "PASS", "OTRO"]),

  // DATOS PERSONALES
  firstName: z.string().min(1, "Requerido"),
  lastName: z.string().optional(),
  ci: z.string().optional(),
  email: z.string().email("Email inválido").optional().or(z.literal("")),
  // phoneValue: valor nacional visible (solo para el input),
  // phoneE164: E.164 guardado en el store / enviado al backend.
  phoneValue: z.string().optional(),
  phoneE164: z.string().nullable().optional(),
  phoneCountry: z.string().optional(),
  address: z.string().optional(),
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
  const [searchQuery, setSearchQuery] = React.useState("")
  const searchInputRef = React.useRef<HTMLInputElement>(null)

  const customers = useCatalogStore((s) => s.customers)
  const patchCustomer = useCatalogStore((s) => s.patchCustomer)
  const setCustomer = useCartStore((s) => s.setCustomer)

  const searchResults = React.useMemo(
    () => searchCustomers(customers, searchQuery, 20),
    [customers, searchQuery],
  )

  // Reset on open / close.
  React.useEffect(() => {
    if (open) {
      setSearchQuery("")
      const id = setTimeout(() => searchInputRef.current?.focus(), 50)
      return () => clearTimeout(id)
    }
  }, [open])

  function handleSelectCustomer(c: PosCustomer) {
    setCustomer(c)
    onOpenChange(false)
  }

  function handleCustomerCreated(c: PosCustomer) {
    // Parchar el catálogo local y seleccionar el cliente recién creado.
    patchCustomer(c)
    setCustomer(c)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="flex max-h-[90vh] flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
        showCloseButton={true}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Buscar o crear cliente</DialogTitle>
          <DialogDescription>
            Buscá un cliente existente o completá el formulario para crear uno nuevo.
          </DialogDescription>
        </DialogHeader>

        {/* ── Barra de búsqueda ── */}
        <div className="flex items-center gap-3 border-b border-border px-4 py-3">
          <Search className="size-5 shrink-0 text-muted-foreground" />
          <Input
            ref={searchInputRef}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar clientes…"
            className="h-auto flex-1 rounded-none border-0 bg-transparent px-0 text-base shadow-none focus-visible:ring-0"
            autoComplete="off"
            aria-label="Buscar clientes"
          />
        </div>

        {/* ── Resultados de búsqueda (solo si hay query o hay resultados) ── */}
        {(searchQuery.length > 0 || searchResults.length > 0) && (
          <div className="border-b border-border">
            {searchResults.length === 0 ? (
              <p className="px-4 py-3 text-sm text-muted-foreground">
                Sin resultados.
              </p>
            ) : (
              <ul>
                {searchResults.map((c) => (
                  <CustomerResultRow
                    key={c.id}
                    customer={c}
                    onSelect={() => handleSelectCustomer(c)}
                  />
                ))}
              </ul>
            )}
          </div>
        )}

        {/* ── Formulario de creación ── */}
        <CreateCustomerForm onCreated={handleCustomerCreated} />
      </DialogContent>
    </Dialog>
  )
}

// ── Fila de resultado de búsqueda ─────────────────────────────────────────────

function CustomerResultRow({
  customer,
  onSelect,
}: {
  customer: PosCustomer
  onSelect: () => void
}) {
  return (
    <li>
      <button
        onClick={onSelect}
        className={cn(
          "flex w-full items-center gap-3 px-4 py-2.5 text-left",
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
    </li>
  )
}

// ── Formulario de creación de cliente ─────────────────────────────────────────

function CreateCustomerForm({
  onCreated,
}: {
  onCreated: (c: PosCustomer) => void
}) {
  const {
    register,
    handleSubmit,
    control,
    reset,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<CustomerFormValues>({
    resolver: zodResolver(customerFormSchema),
    defaultValues: {
      fiscalName: "",
      tin: "",
      tinType: "RUC",
      firstName: "",
      lastName: "",
      ci: "",
      email: "",
      phoneValue: "",
      phoneE164: null,
      phoneCountry: "PY",
      address: "",
      birthdate: "",
    },
  })

  async function onSubmit(values: CustomerFormValues) {
    /**
     * TODO (próximo chunk): cablear executeCreateCustomer desde
     * lib/commands/create-customer.ts con el payload real al backend.
     * Por ahora: crear el PosCustomer localmente con un UUID client-side
     * y parcharlo en el catalog store. Esto permite probar el flujo UX
     * completo sin necesidad del backend.
     *
     * Cuando se implemente: llamar `executeCreateCustomer({ name, fiscalName,
     * phone: values.phoneE164, tin: values.tin, ci: values.ci,
     * email: values.email })` y usar el PosCustomer retornado.
     */
    const displayName =
      values.fiscalName.trim() ||
      `${values.firstName} ${values.lastName ?? ""}`.trim()
    const newCustomer: PosCustomer = {
      id: crypto.randomUUID(),
      name: displayName,
      phone: values.phoneE164 ?? null,
      tin: values.tin?.trim() || null,
      storeCredit: 0,
      isCreditable: false,
    }
    onCreated(newCustomer)
    reset()
  }

  function handleClear() {
    reset()
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-0">
      {/* ── Sección DATOS DE FACTURACIÓN ── */}
      <div className="px-4 pb-4 pt-4">
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
              placeholder="Nombre o razón social…"
              aria-invalid={!!errors.fiscalName}
              {...register("fiscalName")}
            />
            {errors.fiscalName && (
              <p className="text-xs text-destructive">{errors.fiscalName.message}</p>
            )}
          </div>

          {/* RUC / N° documento + lupa stub */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="tin" className="text-xs">
              RUC / N° Documento
            </Label>
            <div className="flex gap-2">
              <Input
                id="tin"
                placeholder="ej. 80012345-6"
                className="flex-1"
                {...register("tin")}
              />
              {/* Lupa de búsqueda SET — stub (Slice siguiente) */}
              <Button
                type="button"
                variant="outline"
                size="icon-sm"
                disabled
                title="Búsqueda SET (próximamente)"
                aria-label="Buscar en el SET"
                className="shrink-0"
              >
                <SearchCode className="size-4" />
              </Button>
            </div>
          </div>

          {/* Tipo de identificación */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="tinType" className="text-xs">
              Tipo de Identificación
            </Label>
            <Controller
              name="tinType"
              control={control}
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="tinType" className="w-full">
                    <SelectValue placeholder="Seleccioná…" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="RUC">RUC</SelectItem>
                    <SelectItem value="CI">Cédula de Identidad</SelectItem>
                    <SelectItem value="PASS">Pasaporte</SelectItem>
                    <SelectItem value="OTRO">Otro</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </div>
        </div>
      </div>

      <Separator />

      {/* ── Sección DATOS PERSONALES ── */}
      <div className="px-4 pb-4 pt-4">
        <p className="mb-3 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
          Datos Personales
        </p>

        <div className="grid grid-cols-2 gap-3">
          {/* Nombre */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="firstName" className="text-xs">
              Nombre
            </Label>
            <Input
              id="firstName"
              placeholder="Nombre…"
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
              placeholder="Apellido…"
              {...register("lastName")}
            />
          </div>

          {/* Doc. de Identidad */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="ci" className="text-xs">
              Doc. de Identidad
            </Label>
            <Input
              id="ci"
              placeholder="N° CI…"
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
              placeholder="ejemplo@mail.com"
              aria-invalid={!!errors.email}
              {...register("email")}
            />
            {errors.email && (
              <p className="text-xs text-destructive">{errors.email.message}</p>
            )}
          </div>

          {/* Teléfono — span 2 cols */}
          <div className="col-span-2 flex flex-col gap-1">
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
                  country="PY"
                  onChange={(v) => {
                    field.onChange(v.value)
                    setValue("phoneE164", v.e164)
                    setValue("phoneCountry", v.country)
                  }}
                />
              )}
            />
          </div>

          {/* Dirección */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="address" className="text-xs">
              Dirección
            </Label>
            <Input
              id="address"
              placeholder="Calle, número…"
              {...register("address")}
            />
          </div>

          {/* Fecha de nacimiento */}
          <div className="flex flex-col gap-1">
            <Label htmlFor="birthdate" className="text-xs">
              Fecha de Nacimiento
            </Label>
            <Input
              id="birthdate"
              type="date"
              {...register("birthdate")}
            />
          </div>
        </div>
      </div>

      {/* ── Acciones ── */}
      <div className="flex items-center justify-between border-t border-border px-4 py-3">
        <Button
          type="button"
          variant="link"
          onClick={handleClear}
          className="h-auto p-0 text-xs text-muted-foreground hover:text-foreground"
        >
          Borrar Formulario
        </Button>

        <Button
          type="submit"
          disabled={isSubmitting}
          className="bg-[#01D7A1] text-[#060A0E] hover:bg-[#01D7A1]/90 font-bold"
        >
          CREAR CLIENTE
        </Button>
      </div>
    </form>
  )
}
