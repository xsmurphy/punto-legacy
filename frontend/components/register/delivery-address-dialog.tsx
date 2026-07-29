"use client"

/**
 * Diálogo de dirección de envío — flujo "Envío" del selector de fulfillment
 * (context/27-delivery-sla-plan.md §B.4/§D.4/§D.5).
 *
 * Lista las direcciones cargadas del cliente (libreta `customerAddress`, mig
 * 87) para elegir una, o el alta inline de una nueva ("Nueva dirección").
 * Confirmar setea `fulfillment="delivery"` + la dirección elegida en el
 * carrito (vía `onConfirm`, que el caller conecta a
 * `setFulfillment`/`setDeliveryAddress`). Cancelar NO toca el store — el
 * caller no llama `onConfirm`, así que el fulfillment queda como estaba
 * antes de abrir el diálogo (Regla del brief: nunca optimista).
 *
 * Dialog centrado (Regla #2.2 de context/14-ui-conventions.md) — PROHIBIDO
 * Sheet/Drawer. Tamaño `m` (sm:max-w-2xl, Regla #2.1): formulario con varios
 * campos + listado corto.
 *
 * Listado corto embebido (Regla #3): grilla de `<Button variant="outline">`,
 * sin `<DataTable>` — una libreta de direcciones de un cliente tiene pocas
 * filas.
 */

import * as React from "react"
import { MapPin, Plus, Loader2, X } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { EmptyState } from "@/components/empty-state"
import { toast } from "sonner"
import {
  useCustomerAddressesPos,
  useCreateCustomerAddressPos,
} from "@/hooks/use-pos-customer-addresses"
import type { CustomerAddress } from "@/lib/types/contact"
import { AddressMapParser } from "@/components/geo/address-map-parser"
import { splitPlaceAddress } from "@/lib/geo/parse-coordinates"

interface NewAddressForm {
  name: string
  city: string
  address: string
  reference: string
  location: string
  lat: number | null
  lng: number | null
}

const EMPTY_FORM: NewAddressForm = {
  name: "",
  city: "",
  address: "",
  reference: "",
  location: "",
  lat: null,
  lng: null,
}

export function DeliveryAddressDialog({
  open,
  onOpenChange,
  customerId,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  customerId: string
  onConfirm: (address: CustomerAddress) => void
}) {
  const { data: addresses, isLoading, refetch } = useCustomerAddressesPos(customerId)
  const createAddress = useCreateCustomerAddressPos()

  const [showForm, setShowForm] = React.useState(false)
  const [form, setForm] = React.useState<NewAddressForm>(EMPTY_FORM)

  // Vuelve al listado (y limpia el form) cada vez que el diálogo se reabre.
  React.useEffect(() => {
    if (open) {
      setShowForm(false)
      setForm(EMPTY_FORM)
    }
  }, [open])

  const set = <K extends keyof NewAddressForm>(key: K, value: NewAddressForm[K]) =>
    setForm((f) => ({ ...f, [key]: value }))

  const handlePick = (address: CustomerAddress) => {
    onConfirm(address)
    onOpenChange(false)
  }

  const handleCreate = async () => {
    if (form.name.trim() === "" || form.address.trim() === "") {
      toast.error("Nombre y dirección son obligatorios")
      return
    }
    try {
      const { id } = await createAddress.mutateAsync({
        customerId,
        name: form.name.trim(),
        address: form.address.trim(),
        reference: form.reference.trim() || undefined,
        city: form.city.trim() || undefined,
        location: form.location.trim() || undefined,
        lat: form.lat,
        lng: form.lng,
      })
      // El id viene del propio INSERT (RETURNING customerAddressId). No se
      // deduce releyendo la libreta: la dirección nueva queda como default,
      // pero dos altas concurrentes del mismo cliente harían que el "default"
      // sea la del otro y el pedido saldría a la dirección equivocada.
      const created = (await refetch()).data?.find((a) => a.id === id) ?? null
      if (!created) {
        toast.error("La dirección se creó pero no se pudo seleccionar automáticamente")
        return
      }
      handlePick(created)
    } catch (err) {
      toast.error("No se pudo guardar la dirección", {
        description: err instanceof Error ? err.message : String(err),
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Dirección de envío</DialogTitle>
          <DialogDescription>
            Elegí una dirección guardada del cliente o cargá una nueva para esta orden.
          </DialogDescription>
        </DialogHeader>

        {showForm ? (
          <div className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="da-name">Nombre</Label>
                <Input
                  id="da-name"
                  value={form.name}
                  onChange={(e) => set("name", e.target.value)}
                  placeholder="Casa, Trabajo, ..."
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="da-city">Ciudad</Label>
                <Input
                  id="da-city"
                  value={form.city}
                  onChange={(e) => set("city", e.target.value)}
                />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="da-address">Dirección</Label>
              <Input
                id="da-address"
                value={form.address}
                onChange={(e) => set("address", e.target.value)}
                placeholder="Calle y número"
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="da-reference">Referencia</Label>
              <Input
                id="da-reference"
                value={form.reference}
                onChange={(e) => set("reference", e.target.value)}
                placeholder="Portón negro, timbre 2..."
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="da-location">Barrio / zona</Label>
              <Input
                id="da-location"
                value={form.location}
                onChange={(e) => set("location", e.target.value)}
                placeholder="Carmelitas, San Lorenzo..."
              />
            </div>
            <AddressMapParser
              onParsed={({ lat, lng, address }) => {
                // Conservador: el link solo completa Ciudad/Dirección si el
                // cajero todavía no escribió nada ahí. Barrio/zona nunca sale
                // de acá — Google no lo trae en el link.
                const placeFields = address ? splitPlaceAddress(address) : null
                setForm((f) => ({
                  ...f,
                  lat,
                  lng,
                  address: !f.address.trim() && placeFields?.address ? placeFields.address : f.address,
                  city: !f.city.trim() && placeFields?.city ? placeFields.city : f.city,
                }))
                toast.success(`Coordenadas: ${lat}, ${lng}`)
              }}
            />
            {form.lat !== null && form.lng !== null ? (
              <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                <span className="tabular-nums text-muted-foreground">
                  Lat {form.lat.toFixed(6)}, Lng {form.lng.toFixed(6)}
                </span>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="gap-1.5 text-muted-foreground"
                  onClick={() => setForm((f) => ({ ...f, lat: null, lng: null }))}
                >
                  <X className="size-3.5" aria-hidden />
                  Limpiar
                </Button>
              </div>
            ) : (
              <p className="text-xs text-muted-foreground">
                Sin coordenadas — pegá un link de Maps arriba para extraerlas.
              </p>
            )}
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowForm(false)}>
                Volver
              </Button>
              <Button onClick={handleCreate} disabled={createAddress.isPending}>
                {createAddress.isPending ? (
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                ) : null}
                Guardar y usar
              </Button>
            </DialogFooter>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            {isLoading ? (
              <p className="py-6 text-center text-sm text-muted-foreground">Cargando direcciones...</p>
            ) : !addresses || addresses.length === 0 ? (
              <EmptyState
                ghost
                icon={MapPin}
                title="Sin direcciones guardadas"
                description="Cargá la primera dirección de envío para este cliente."
              />
            ) : (
              <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
                {addresses.map((a) => (
                  <Button
                    key={a.id}
                    variant="outline"
                    onClick={() => handlePick(a)}
                    className="h-auto w-full justify-start gap-2 px-3 py-2.5 text-left"
                  >
                    <MapPin className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="flex min-w-0 flex-col">
                      <span className="truncate text-sm font-medium">{a.name}</span>
                      <span className="truncate text-xs text-muted-foreground">
                        {a.address}
                        {a.reference ? ` — ${a.reference}` : ""}
                      </span>
                    </span>
                  </Button>
                ))}
              </div>
            )}
            <DialogFooter className="sm:justify-between">
              <Button variant="outline" onClick={() => setShowForm(true)} className="gap-1.5">
                <Plus className="size-4" aria-hidden />
                Nueva dirección
              </Button>
              <Button variant="ghost" onClick={() => onOpenChange(false)}>
                Cancelar
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
