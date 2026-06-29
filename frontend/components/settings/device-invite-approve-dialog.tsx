"use client"
import * as React from "react"
import { CheckCircle } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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
import { useOutlets } from "@/hooks/use-outlets"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import {
  useApproveDeviceInvitation,
  useDenyDeviceInvitation,
  type DeviceInvitation,
} from "@/hooks/use-device-invitations"

interface Props {
  invitation: DeviceInvitation | null
  onOpenChange: (v: boolean) => void
}

function minutesAgo(iso: string | null): string {
  if (!iso) return "—"
  const diff = Math.round((Date.now() - new Date(iso).getTime()) / 1000 / 60)
  if (diff < 1) return "hace menos de 1 min"
  return `hace ${diff} min`
}

export function DeviceInviteApproveDialog({ invitation, onOpenChange }: Props) {
  const [userCodeConfirm, setUserCodeConfirm] = React.useState("")
  const [denyOpen, setDenyOpen] = React.useState(false)

  const { data: outletsData } = useOutlets()
  const { data: registersData } = useRegistersAdmin()
  const approve = useApproveDeviceInvitation()
  const deny = useDenyDeviceInvitation()

  const outlets = outletsData?.rows ?? []
  const registers = registersData?.registers ?? []

  React.useEffect(() => {
    if (!invitation) setUserCodeConfirm("")
  }, [invitation])

  if (!invitation) return null

  const outletName = outlets.find((o) => o.id === invitation.outletId)?.name ?? "—"
  const registerName = registers.find((r) => r.id === invitation.registerId)?.name ?? "—"

  // Normalizar para tolerar paste con/sin guión y espacios accidentales.
  // El admin a veces copia el código de la pantalla del device a ojo y
  // omite el guión, o el clipboard agrega un trailing space.
  const normalizeCode = (s: string) =>
    s.toUpperCase().replace(/[\s-]/g, "")
  const codeMatch =
    invitation.userCode !== null &&
    normalizeCode(userCodeConfirm) === normalizeCode(invitation.userCode)

  function handleApprove() {
    approve.mutate(
      { id: invitation!.id, userCodeConfirm: userCodeConfirm.toUpperCase() },
      {
        onSuccess: () => {
          toast.success("Dispositivo aprobado")
          onOpenChange(false)
        },
        onError: (err) => toast.error(err.message),
      }
    )
  }

  function handleDeny() {
    deny.mutate(invitation!.id, {
      onSuccess: () => {
        toast.success("Solicitud rechazada")
        setDenyOpen(false)
        onOpenChange(false)
      },
      onError: (err) => toast.error(err.message),
    })
  }

  const ua = invitation.deviceUa
  const uaTruncated = ua && ua.length > 80 ? ua.slice(0, 80) + "…" : (ua ?? "—")

  return (
    <>
      <Dialog open={invitation !== null} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Aprobar dispositivo</DialogTitle>
          </DialogHeader>

          <div className="flex flex-col gap-4 py-2">
            <Card>
              <CardContent className="pt-4 space-y-2 text-sm">
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Dispositivo (UA)</span>
                  <span className="font-mono text-xs break-all">{uaTruncated}</span>
                </div>
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">IP</span>
                  <span className="font-mono text-xs">{invitation.deviceIp ?? "—"}</span>
                </div>
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Abierto</span>
                  <span>{minutesAgo(invitation.openedAt)}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="pt-4 space-y-2 text-sm">
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Sucursal</span>
                  <span>{outletName}</span>
                </div>
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Caja</span>
                  <span>{registerName}</span>
                </div>
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Nombre</span>
                  <span>{invitation.deviceName ?? "Sin nombre"}</span>
                </div>
                <div className="flex gap-2">
                  <span className="text-muted-foreground w-28 shrink-0">Módulo</span>
                  <Badge variant="secondary">POS</Badge>
                </div>
              </CardContent>
            </Card>

            <div className="space-y-1.5">
              <Label htmlFor="user-code">
                Pedile al usuario que te diga el código que ve en pantalla
              </Label>
              {invitation.userCode === null ? (
                <p className="text-sm text-muted-foreground">El dispositivo aún no abrió el link.</p>
              ) : (
                <>
                  <Input
                    id="user-code"
                    value={userCodeConfirm}
                    onChange={(e) => setUserCodeConfirm(e.target.value.toUpperCase())}
                    placeholder="XXX-XXXX"
                    className="font-mono uppercase"
                  />
                  {codeMatch && (
                    <p className="flex items-center gap-1.5 text-sm text-green-600">
                      <CheckCircle className="size-4" />
                      Código correcto
                    </p>
                  )}
                </>
              )}
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setDenyOpen(true)}>
              Rechazar
            </Button>
            <Button
              disabled={!codeMatch || approve.isPending}
              onClick={handleApprove}
            >
              Aprobar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={denyOpen} onOpenChange={setDenyOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Rechazar solicitud</AlertDialogTitle>
            <AlertDialogDescription>
              El dispositivo no podrá conectarse con esta invitación. No se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={handleDeny}
            >
              Rechazar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
