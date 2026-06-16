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
import { SearchCode, ChevronDown } from "lucide-react"
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

  // DATOS PERSONALES (opcionales — sección colapsada por default)
  firstName: z.string().min(1, "Requerido"),
  lastName: z.string().optional(),
  ci: z.string().optional(),
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
  const [searchQuery, setSearchQuery] = React.useState("")
  const searchInputRef = React.useRef<HTMLInputElement>(null)

  const customers = useCatalogStore((s) => s.customers)
  const patchCustomer = useCatalogStore((s) => s.patchCustomer)
  const setCustomer = useCartStore((s) => s.setCustomer)

  // Vacío = mostrar el form de crear cliente; con texto = listar clientes.
  const trimmed = searchQuery.trim()
  const searchResults = React.useMemo(
    () => (trimmed ? searchCustomers(customers, trimmed, 20) : []),
    [customers, trimmed],
  )
  const isSearching = trimmed.length > 0

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
              <p className="py-10 text-center text-sm text-muted-foreground">
                Sin resultados para “{trimmed}”.
              </p>
            ) : (
              <ul role="listbox" aria-label="Resultados de clientes" className="overflow-y-auto py-1">
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
        ) : (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-popover shadow-lg">
            <CreateCustomerForm onCreated={handleCustomerCreated} />
          </div>
        )}
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
      firstName: "",
      lastName: "",
      ci: "",
      email: "",
      phoneValue: "",
      phoneE164: null,
      phoneCountry: "PY",
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
    <form onSubmit={handleSubmit(onSubmit)} className="flex min-h-0 flex-1 flex-col">
      {/* ── Barra de acciones ARRIBA — guardar sin scrollear ── */}
      <div className="flex shrink-0 items-center justify-between border-b border-border bg-background px-4 py-3">
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
            Crear cliente
          </Button>
        </div>
      </div>

      {/* ── Campos (scrolleables) ── */}
      <div className="min-h-0 flex-1 overflow-y-auto">
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

        </div>
      </div>

      <Separator />

      {/* ── Sección DATOS PERSONALES — colapsada por default ── */}
      <Collapsible defaultOpen={false}>
        <div className="px-4 pt-4 pb-2">
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
          <div className="px-4 pb-4">
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

              {/* Teléfono — mismo row que Fecha de Nacimiento */}
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

              {/* Fecha de Nacimiento — mismo row que Teléfono */}
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
        </CollapsibleContent>
      </Collapsible>

      </div>
    </form>
  )
}
