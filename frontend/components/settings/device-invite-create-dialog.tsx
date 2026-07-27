"use client"
import * as React from "react"
import { QRCodeSVG } from "qrcode.react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { useOutlets } from "@/hooks/use-outlets"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import {
  useCreateDeviceInvitation,
  type CreateInvitationResponse,
} from "@/hooks/use-device-invitations"

interface Props {
  open: boolean
  onOpenChange: (v: boolean) => void
}

function hoursUntil(iso: string): number {
  const diff = new Date(iso).getTime() - Date.now()
  return Math.max(0, Math.round(diff / 1000 / 60 / 60))
}

export function DeviceInviteCreateDialog({ open, onOpenChange }: Props) {
  // Default vacío: obliga al admin a elegir conscientemente el tipo del
  // dispositivo. Antes era "pos" y solicitudes para pantalla terminaban
  // creadas como POS cuando el admin no abría el Select.
  const [module, setModule] = React.useState("")
  const [outletId, setOutletId] = React.useState("")
  const [registerId, setRegisterId] = React.useState("")
  const [deviceName, setDeviceName] = React.useState("")
  const [ttlHours, setTtlHours] = React.useState(24)
  const [result, setResult] = React.useState<CreateInvitationResponse | null>(null)
  const [canShare, setCanShare] = React.useState(false)

  React.useEffect(() => {
    setCanShare(typeof navigator !== "undefined" && "share" in navigator)
  }, [])

  const { data: outletsData } = useOutlets()
  const { data: registersData } = useRegistersAdmin()
  const create = useCreateDeviceInvitation()

  const outlets = outletsData?.rows ?? []
  const allRegisters = registersData?.registers ?? []
  const filteredRegisters = outletId
    ? allRegisters.filter((r) => r.outletId === outletId)
    : []

  function reset() {
    setModule("")
    setOutletId("")
    setRegisterId("")
    setDeviceName("")
    setTtlHours(24)
    setResult(null)
  }

  function handleOpenChange(v: boolean) {
    if (!v) reset()
    onOpenChange(v)
  }

  // Solo la caja POS y la pantalla de cliente pertenecen a una CAJA: la
  // primera ES una caja, la segunda espeja el carrito de una caja concreta.
  // KDS, pantalla de despacho y estación de impresión son de SUCURSAL — pedirles
  // una caja es pedir un dato que no significa nada (el backend ya aceptaba
  // registerId nulo; la exigencia era solo de esta UI).
  const needsRegister = module === "pos" || module === "screen"
  const canSubmit =
    module !== "" && outletId !== "" && (!needsRegister || registerId !== "")

  function handleSubmit() {
    create.mutate(
      {
        module,
        outletId,
        // Sin caja para los dispositivos de sucursal: si el operador eligió
        // una y después cambió a KDS, no se manda igual un dato que no aplica.
        registerId: needsRegister ? registerId : "",
        deviceName: deviceName.trim() || undefined,
        ttlHours,
      },
      {
        onSuccess: (res) => setResult(res),
        onError: (err) => toast.error(err.message),
      }
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">Conectar dispositivo</DialogTitle>
        </DialogHeader>

        {result === null ? (
          <>
            <div className="flex flex-col gap-4 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="inv-module">Módulo</Label>
                <Select value={module} onValueChange={setModule}>
                  <SelectTrigger id="inv-module">
                    <SelectValue placeholder="Seleccioná el tipo de dispositivo..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pos">Caja POS</SelectItem>
                    <SelectItem value="screen">Pantalla cliente</SelectItem>
                    <SelectItem value="kds">KDS (preparación)</SelectItem>
                    <SelectItem value="display">Pantalla de despacho</SelectItem>
                    <SelectItem value="print">Estación de impresión</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="inv-outlet">Sucursal</Label>
                <Select
                  value={outletId}
                  onValueChange={(v) => { setOutletId(v); setRegisterId("") }}
                >
                  <SelectTrigger id="inv-outlet">
                    <SelectValue placeholder="Seleccioná una sucursal..." />
                  </SelectTrigger>
                  <SelectContent>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* La caja solo aplica a los dispositivos que pertenecen a una:
                  KDS, pantalla de despacho y estación de impresión son de
                  sucursal. */}
              {needsRegister && (
                <div className="space-y-1.5">
                  <Label htmlFor="inv-register">Caja</Label>
                  <Select
                    value={registerId}
                    onValueChange={setRegisterId}
                    disabled={!outletId}
                  >
                    <SelectTrigger id="inv-register">
                      <SelectValue
                        placeholder={outletId ? "Seleccioná una caja..." : "Primero seleccioná una sucursal"}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {filteredRegisters.map((r) => (
                        <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="inv-name">Nombre del dispositivo (opcional)</Label>
                <Input
                  id="inv-name"
                  value={deviceName}
                  onChange={(e) => setDeviceName(e.target.value)}
                  placeholder="iPad Caja Barra"
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="inv-ttl">Validez del link (horas)</Label>
                <Input
                  id="inv-ttl"
                  type="number"
                  min={1}
                  max={168}
                  value={ttlHours}
                  onChange={(e) => setTtlHours(Number(e.target.value))}
                />
                <p className="text-xs text-muted-foreground">
                  Tiempo durante el cual el link sigue activo para conectar el dispositivo. Una vez conectado, el dispositivo no expira (hasta que lo revoques desde Dispositivos POS).
                </p>
              </div>

              {create.error && (
                <Alert variant="destructive">
                  <AlertDescription>{create.error.message}</AlertDescription>
                </Alert>
              )}
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => handleOpenChange(false)}>
                Cancelar
              </Button>
              <Button
                disabled={!canSubmit || create.isPending}
                onClick={handleSubmit}
              >
                Crear
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <div className="flex flex-col items-center gap-4 py-2">
              <p className="text-sm text-muted-foreground text-center">
                Mandale este link al usuario del dispositivo. Solo se puede usar una vez.
              </p>

              <div className="bg-muted p-4 rounded-lg">
                <QRCodeSVG value={result.url} size={192} />
              </div>
              <p className="text-sm text-muted-foreground text-center">
                Escaneá con el dispositivo
              </p>

              <div className="flex w-full gap-2">
                <Input readOnly value={result.url} className="font-mono text-xs" />
                <Button
                  variant="outline"
                  onClick={() => {
                    navigator.clipboard.writeText(result.url)
                    toast.success("Copiado")
                  }}
                >
                  Copiar
                </Button>
              </div>

              {canShare && (
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={async () => {
                    try {
                      await navigator.share({ title: "Conectar dispositivo", url: result.url })
                    } catch (err) {
                      if (err instanceof Error && err.name !== "AbortError") {
                        toast.error("No se pudo compartir")
                      }
                    }
                  }}
                >
                  Compartir
                </Button>
              )}

              <p className="text-xs text-muted-foreground">
                Vence en {hoursUntil(result.expiresAt)} horas. Cuando se conecte, aparecerá en la tab Dispositivos.
              </p>
            </div>

            <DialogFooter>
              <Button onClick={() => handleOpenChange(false)}>Cerrar</Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
