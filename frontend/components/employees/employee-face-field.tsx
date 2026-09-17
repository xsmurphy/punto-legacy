"use client"

/**
 * El rostro de una persona, en su legajo (RRHH F2, context/83 D5).
 *
 * ── Desde acá se HABILITA; no se registra ──────────────────────────────────
 *
 * El panel no tiene la cara de nadie y no la va a tener: habilita a la caja de
 * la sucursal de esa persona a capturarla, por unos minutos. La captura ocurre
 * allá, con la persona parada enfrente.
 *
 * Esa separación no es un detalle de implementación: es lo único que impide que
 * quien sabe un código deje SU cara bajo el nombre de otro y después la cara lo
 * confirme todos los días. Por eso acá no hay —y no debe haber— ninguna forma de
 * subir una foto.
 *
 * ── La autorización va primero, y se guarda ────────────────────────────────
 *
 * La casilla es parte del legajo y se guarda con él. Habilitar la caja exige que
 * ya esté guardada, no tildada en pantalla: el servidor la verifica al habilitar
 * y otra vez al recibir la captura, porque entre esos dos momentos hay minutos
 * en los que alguien puede cambiar de opinión.
 */

import * as React from "react"
import { ScanFace, Trash2 } from "lucide-react"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { formatDate } from "@/lib/format-date"
import {
  useCancelFaceEnrollment,
  useDeleteFace,
  useStartFaceEnrollment,
  type Employee,
} from "@/hooks/use-employees"

export interface EmployeeFaceFieldProps {
  /** `undefined` mientras se está creando el legajo: todavía no hay a quién habilitar. */
  employee: Employee | undefined
  /** La casilla de autorización, tal como está EN EL FORMULARIO (puede no estar guardada). */
  consentChecked: boolean
}

export function EmployeeFaceField({ employee, consentChecked }: EmployeeFaceFieldProps) {
  const start = useStartFaceEnrollment()
  const cancel = useCancelFaceEnrollment()
  const remove = useDeleteFace()

  /**
   * La ventana que se acaba de abrir, para mostrarla sin recargar.
   *
   * Solo vive en esta pantalla y se pierde al cerrar el legajo: la ventana en sí
   * vive en el servidor y vence sola en minutos, así que no vale la pena
   * sincronizar un estado que se apaga solo antes de que a nadie le importe.
   */
  const [openUntil, setOpenUntil] = React.useState<string | null>(null)

  if (!employee) {
    // Legajo nuevo: no hay a quién habilitar todavía. Sin leyendas explicando
    // por qué (regla §8 de context/14) — el bloque simplemente no está, y
    // aparece cuando la persona existe.
    return null
  }

  const enrolled = employee.face
  // La autorización tiene que estar GUARDADA. Tildarla y no guardar deja el
  // botón deshabilitado, con el motivo en el propio control — nunca en una banda.
  const consentSaved = employee.biometricConsentAt !== null
  const active = employee.active && employee.status === 1

  const blocked = !consentSaved
    ? consentChecked
      ? "Guardá el legajo para que quede registrada la autorización"
      : "Primero marcá que la persona aceptó identificarse con su rostro"
    : !active
      ? "El legajo no está vigente"
      : null

  async function handleStart() {
    try {
      const res = await start.mutateAsync(employee!.id)
      setOpenUntil(res.enrollment.expiresAt)
      toast.success("Listo para registrar en la caja", {
        description: "La persona tiene que pararse frente a la caja de su sucursal.",
      })
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo habilitar")
    }
  }

  async function handleCancel() {
    try {
      await cancel.mutateAsync(employee!.id)
      setOpenUntil(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo cancelar")
    }
  }

  async function handleDelete() {
    try {
      await remove.mutateAsync(employee!.id)
      setOpenUntil(null)
      toast.success("Rostro borrado")
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo borrar")
    }
  }

  const busy = start.isPending || cancel.isPending || remove.isPending

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        {enrolled ? (
          <Badge variant="secondary">
            Registrado{enrolled.enrolledAt ? ` el ${formatDate(enrolled.enrolledAt)}` : ""}
          </Badge>
        ) : (
          <Badge variant="outline">Sin rostro registrado</Badge>
        )}
        {openUntil && <Badge variant="outline">Esperando a la caja</Badge>}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {/* El impedimento se dice en el CONTROL que impide, con el motivo en el
            tooltip: es la regla del POS y vale igual acá. El `span` envolvente
            existe porque un botón deshabilitado no dispara eventos de mouse y el
            tooltip no aparecería. */}
        <Tooltip>
          <TooltipTrigger asChild>
            <span>
              <Button
                type="button"
                variant="outline"
                disabled={busy || blocked !== null}
                onClick={() => void handleStart()}
              >
                <ScanFace className="size-4" />
                {enrolled ? "Volver a registrar" : "Registrar el rostro"}
              </Button>
            </span>
          </TooltipTrigger>
          <TooltipContent>
            {blocked ?? "La persona se para frente a la caja de su sucursal y se registra ahí."}
          </TooltipContent>
        </Tooltip>

        {openUntil && (
          <Button type="button" variant="ghost" disabled={busy} onClick={() => void handleCancel()}>
            Cancelar
          </Button>
        )}

        {enrolled && (
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                disabled={busy}
                className="text-muted-foreground"
              >
                <Trash2 className="size-4" />
                Borrar el rostro
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent className="sm:max-w-md">
              <AlertDialogHeader>
                <AlertDialogTitle>¿Borrar el rostro de {employee.fullName}?</AlertDialogTitle>
                <AlertDialogDescription>
                  Va a seguir marcando con su código. Para volver a usar el rostro hay que
                  registrarlo de nuevo.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Volver</AlertDialogCancel>
                <AlertDialogAction onClick={() => void handleDelete()}>Borrar</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        )}
      </div>
    </div>
  )
}
