"use client"

/**
 * Diálogo de alta de sucursal con PAYWALL.
 *
 * Cada sucursal se factura al PRECIO DEL PLAN del tenant por mes (owner,
 * 2026-09-11), así que este diálogo no crea nada: registra una SOLICITUD que
 * Punto aprueba desde /admin. Lo que sí hace —y es su razón de existir— es
 * decirle al comercio, ANTES de pedir, cuánto va a pasar a pagar.
 *
 * Es el único punto de entrada del alta desde la UI del panel: lo montan el
 * switcher de sucursales del sidebar y la página `/outlets`. Un segundo
 * formulario de alta en paralelo volvería el paywall decorativo.
 *
 * Convenciones aplicadas (context/14): `sm:max-w-2xl` (bucket `m`, el default
 * del proyecto), `<Input>` sin override de alto, montos por
 * `formatMoney()` con la config del tenant, sin emojis, sin hex.
 */

import * as React from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { CheckCircle2, Loader2 } from "lucide-react"
import { toast } from "sonner"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { formatMoney } from "@/lib/format-money"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useCreateOutletRequest,
  useOutletRequestStatus,
} from "@/hooks/use-outlet-request"

const schema = z.object({
  // El backend exige el nombre (`OutletsService::create()` lo usa para el
  // outlet y para su depósito por defecto). No hay alta anónima.
  name: z
    .string()
    .trim()
    .min(1, "El nombre de la sucursal es requerido")
    .max(255),
  address: z.string().trim().max(1000).optional(),
})

type FormValues = z.infer<typeof schema>

export function OutletRequestDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        {/* El cuerpo se monta con el diálogo: cada apertura arranca con el
            form limpio y sin el estado "enviado" de la vez anterior, sin un
            efecto que resetee (que además dispara renders en cascada). */}
        {open && <OutletRequestBody onClose={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  )
}

function OutletRequestBody({ onClose }: { onClose: () => void }) {
  const { data: bootstrap } = useBootstrap()
  // El diálogo solo se abre desde controles que ya exigen el permiso, así que
  // acá la query va habilitada sin volver a preguntarlo.
  const { data: status } = useOutletRequestStatus(true)
  const createRequest = useCreateOutletRequest()

  const [sent, setSent] = React.useState(false)

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", address: "" },
  })

  const price = status?.plan.price ?? null
  const nextMonthly = status?.nextMonthly ?? null

  function onSubmit(values: FormValues) {
    createRequest.mutate(
      { name: values.name, address: values.address || undefined },
      {
        onSuccess: () => {
          setSent(true)
          toast.success("Solicitud enviada")
        },
        onError: (err) =>
          toast.error(err.message || "No se pudo enviar la solicitud"),
      }
    )
  }

  return (
    <>
      {sent ? (
        <>
          <DialogHeader>
            <DialogTitle>Solicitud enviada</DialogTitle>
            <DialogDescription>
              La revisamos y te avisamos por correo cuando esté resuelta.
              Mientras tanto vas a ver el estado en el selector de sucursales.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-start gap-3 rounded-md border p-4">
            <CheckCircle2 className="mt-0.5 size-5 text-muted-foreground" />
            <div className="flex flex-col gap-1">
              <p className="text-sm font-medium">
                {status?.pending?.name ?? form.getValues("name")}
              </p>
              <p className="text-sm text-muted-foreground">
                La sucursal se crea recién cuando se aprueba la solicitud.
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button onClick={onClose}>Listo</Button>
          </DialogFooter>
        </>
      ) : (
        <Form {...form}>
          <form
            onSubmit={form.handleSubmit(onSubmit)}
            className="flex flex-col gap-6"
          >
            <DialogHeader>
              <DialogTitle>Crear sucursal</DialogTitle>
              <DialogDescription>
                Pedí la sucursal y la habilitamos con su depósito y su caja.
              </DialogDescription>
            </DialogHeader>

            <div className="flex flex-col gap-4">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre de la sucursal</FormLabel>
                    <FormControl>
                      <Input
                        autoFocus
                        placeholder="Ej: Sucursal Centro"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="address"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Dirección (opcional)</FormLabel>
                    <FormControl>
                      <Textarea
                        rows={2}
                        placeholder="Calle, número, ciudad"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            {/* Bloque de costo. Con precio dice la cifra exacta; sin precio
                  NO inventa un monto — ver `plan.price === null` en el tipo. */}
            <div className="flex flex-col gap-1 rounded-md border bg-muted/40 p-4">
              <p className="text-sm font-medium">Costo</p>
              {price !== null && bootstrap ? (
                <p className="text-sm text-muted-foreground">
                  Cada sucursal se factura al precio de tu plan:{" "}
                  <span className="font-medium text-foreground">
                    +{formatMoney(price, bootstrap)}/mes
                  </span>
                  .{" "}
                  {nextMonthly !== null && (
                    <>
                      Al aprobarse, tu facturación mensual pasa a{" "}
                      <span className="font-medium text-foreground">
                        {formatMoney(nextMonthly, bootstrap)}
                      </span>
                      .
                    </>
                  )}
                </p>
              ) : (
                <p className="text-sm text-muted-foreground">
                  El costo por sucursal depende de tu plan; soporte te confirma
                  al aprobar.
                </p>
              )}
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={onClose}
                disabled={createRequest.isPending}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={createRequest.isPending}>
                {createRequest.isPending && (
                  <Loader2 className="size-4 animate-spin" />
                )}
                Enviar solicitud
              </Button>
            </DialogFooter>
          </form>
        </Form>
      )}
    </>
  )
}
