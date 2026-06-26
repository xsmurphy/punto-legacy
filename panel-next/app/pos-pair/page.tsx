"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { api } from "@/lib/api-client"
import { getOrCreateDeviceLocalId } from "@/lib/pos/device-local-id"

export default function PosPairPage() {
  const router = useRouter()
  const { data: bootstrap, isLoading, isError } = useBootstrap()

  const [outletId, setOutletId] = React.useState("")
  const [registerId, setRegisterId] = React.useState("")
  const [registers, setRegisters] = React.useState<{ id: string; name: string }[]>([])
  const [registersLoading, setRegistersLoading] = React.useState(false)
  const [password, setPassword] = React.useState("")
  const [deviceName, setDeviceName] = React.useState("")
  const [isPending, setIsPending] = React.useState(false)

  React.useEffect(() => {
    if (isError) {
      router.replace("/login?next=/pos-pair")
    }
  }, [isError, router])

  React.useEffect(() => {
    if (!outletId) { setRegisters([]); setRegisterId(""); return }
    setRegistersLoading(true)
    setRegisterId("")
    api.get<{ registers: { id: string; name: string; outletId: string }[] }>(
      `/v1/register?resource=listAll`
    )
      .then((res) => {
        setRegisters(res.registers.filter((r) => r.outletId === outletId))
      })
      .catch(() => setRegisters([]))
      .finally(() => setRegistersLoading(false))
  }, [outletId])

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!password || !outletId || !registerId) return
    setIsPending(true)
    try {
      const browserLocalId = getOrCreateDeviceLocalId()
      const result = await api.post<{ deviceId: string; reused?: boolean }>("/auth/pair-pos-device", {
        password,
        outletId,
        registerId,
        deviceName: deviceName.trim() || undefined,
        browserLocalId,
      })
      if (result.reused) {
        toast.success("Dispositivo re-vinculado a esta caja")
      } else {
        toast.success("Dispositivo vinculado")
      }
      router.replace("/pos")
    } catch (err) {
      const e = err as { status?: number; message?: string }
      if (e.status === 401) {
        toast.error("Contraseña incorrecta")
      } else {
        toast.error(e.message ?? "Error al parear el dispositivo")
      }
    } finally {
      setIsPending(false)
    }
  }

  if (isLoading) return null

  const outlets = bootstrap?.outlets ?? []

  return (
    <div className="max-w-md mx-auto mt-16 p-6">
      <h1 className="text-2xl font-semibold">Parear dispositivo</h1>
      <p className="text-sm text-muted-foreground mt-1 mb-6">
        Vinculá esta pantalla a una caja registradora. Solo se necesita hacerlo una vez por dispositivo.
      </p>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="outlet">Sucursal</Label>
          <Select value={outletId} onValueChange={setOutletId}>
            <SelectTrigger id="outlet">
              <SelectValue placeholder="Seleccionar sucursal..." />
            </SelectTrigger>
            <SelectContent>
              {outlets.map((o) => (
                <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="register">Caja</Label>
          <Select
            value={registerId}
            onValueChange={setRegisterId}
            disabled={!outletId || registersLoading}
          >
            <SelectTrigger id="register">
              <SelectValue placeholder={registersLoading ? "Cargando..." : "Seleccionar caja..."} />
            </SelectTrigger>
            <SelectContent>
              {registers.map((r) => (
                <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="device-name">Nombre del dispositivo (opcional)</Label>
          <Input
            id="device-name"
            value={deviceName}
            onChange={(e) => setDeviceName(e.target.value)}
            placeholder="iPad Caja 1"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="password">Contraseña de administrador</Label>
          <Input
            id="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoFocus
          />
        </div>

        <Button
          type="submit"
          className="w-full"
          disabled={!password || !outletId || !registerId || isPending}
        >
          {isPending ? "Pareando..." : "Parear dispositivo"}
        </Button>
      </form>
    </div>
  )
}
