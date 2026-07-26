"use client"

import * as React from "react"
import { Loader2, Usb, Bluetooth, Network, Check } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { requestUsbPrinter, isWebUsbSupported } from "@/lib/hardware/printers/transports/usb"
import { requestBluetoothPrinter, isWebBluetoothSupported } from "@/lib/hardware/printers/transports/bluetooth"
import { registerStationPrinter } from "@/lib/print-station/api"
import type { PrinterKind, PrinterTransport, StationPrinter, TransportConfig } from "@/lib/print-station/types"

/**
 * Alta de una impresora física en la Estación de Impresión (P1,
 * context/26-print-station-plan.md). La estación es la ÚNICA que puede
 * registrar impresoras porque es la única que ve el hardware: el panel solo
 * renombra/borra.
 *
 * Acá NO se configura nada de negocio (ni plantillas, ni ruteo por categoría,
 * ni docTypes) — solo la conexión física.
 */

const TRANSPORTS: { value: PrinterTransport; label: string; icon: typeof Usb }[] = [
  { value: "usb", label: "USB", icon: Usb },
  { value: "bluetooth", label: "Bluetooth", icon: Bluetooth },
  { value: "network", label: "Red", icon: Network },
]

const KINDS: { value: PrinterKind; label: string }[] = [
  { value: "thermal", label: "Térmica" },
  { value: "inkjet", label: "Inyección de tinta" },
  { value: "matrix", label: "Matricial" },
  { value: "generic", label: "Genérica" },
]

interface AddPrinterDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onRegistered: (printer: StationPrinter) => void
}

export function AddPrinterDialog({ open, onOpenChange, onRegistered }: AddPrinterDialogProps) {
  const [transport, setTransport] = React.useState<PrinterTransport>("usb")
  const [name, setName] = React.useState("")
  const [kind, setKind] = React.useState<PrinterKind>("thermal")
  const [config, setConfig] = React.useState<TransportConfig>({})
  const [host, setHost] = React.useState("")
  const [port, setPort] = React.useState("9100")
  const [linking, setLinking] = React.useState(false)
  const [saving, setSaving] = React.useState(false)

  function reset() {
    setTransport("usb"); setName(""); setKind("thermal")
    setConfig({}); setHost(""); setPort("9100")
    setLinking(false); setSaving(false)
  }

  function handleOpenChange(next: boolean) {
    if (!next) reset()
    onOpenChange(next)
  }

  function selectTransport(next: PrinterTransport) {
    setTransport(next)
    setConfig({}) // el handle vinculado no aplica a otro transport
  }

  async function linkUsb() {
    setLinking(true)
    try {
      const device = await requestUsbPrinter()
      const label = [device.manufacturerName, device.productName].filter(Boolean).join(" ")
      setConfig({ vendorId: device.vendorId, productId: device.productId, deviceLabel: label })
      if (!name.trim()) setName(label || "Impresora USB")
    } catch (err) {
      // El usuario cerrando el selector del browser también entra acá — sin toast ruidoso.
      const msg = err instanceof Error ? err.message : String(err)
      if (!/cancel|no device selected/i.test(msg)) toast.error(msg)
    } finally {
      setLinking(false)
    }
  }

  async function linkBluetooth() {
    setLinking(true)
    try {
      const device = await requestBluetoothPrinter()
      setConfig({ bluetoothDeviceId: device.id, deviceLabel: device.name ?? "" })
      if (!name.trim()) setName(device.name || "Impresora Bluetooth")
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      if (!/cancel|no device selected/i.test(msg)) toast.error(msg)
    } finally {
      setLinking(false)
    }
  }

  const ready = (() => {
    if (!name.trim()) return false
    if (transport === "usb") return config.vendorId != null && config.productId != null
    if (transport === "bluetooth") return Boolean(config.bluetoothDeviceId)
    return host.trim() !== "" && Number(port) > 0 && Number(port) < 65536
  })()

  async function submit() {
    if (!ready || saving) return
    setSaving(true)
    try {
      const transportConfig: TransportConfig =
        transport === "network"
          ? { networkHost: host.trim(), networkPort: Number(port) }
          : config
      const printer = await registerStationPrinter({
        name: name.trim(), kind, transport, transportConfig,
      })
      onRegistered(printer)
      toast.success(`${printer.name} agregada`)
      handleOpenChange(false)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo agregar la impresora")
    } finally {
      setSaving(false)
    }
  }

  const linkedLabel = config.deviceLabel || (config.vendorId != null
    ? `USB ${config.vendorId}:${config.productId}`
    : config.bluetoothDeviceId ?? "")

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">Agregar impresora</DialogTitle>
          <DialogDescription>
            Vinculá una impresora conectada a esta computadora. La configuración de qué se
            imprime en cada una se hace desde el panel.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          <div className="space-y-1.5">
            <Label>Conexión</Label>
            <div className="grid grid-cols-3 gap-2">
              {TRANSPORTS.map(({ value, label, icon: Icon }) => (
                <Button
                  key={value}
                  type="button"
                  variant={transport === value ? "default" : "outline"}
                  onClick={() => selectTransport(value)}
                >
                  <Icon className="size-4" />
                  {label}
                </Button>
              ))}
            </div>
          </div>

          {transport === "network" ? (
            <div className="grid grid-cols-[1fr_8rem] gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="printer-host">Host o IP</Label>
                <Input
                  id="printer-host"
                  value={host}
                  onChange={(e) => setHost(e.target.value)}
                  placeholder="192.168.1.50"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="printer-port">Puerto</Label>
                <Input
                  id="printer-port"
                  inputMode="numeric"
                  value={port}
                  onChange={(e) => setPort(e.target.value.replace(/\D/g, ""))}
                />
              </div>
            </div>
          ) : (
            <div className="space-y-1.5">
              <Label>Dispositivo</Label>
              <div className="flex items-center gap-3">
                <Button
                  type="button"
                  variant="outline"
                  disabled={
                    linking ||
                    (transport === "usb" ? !isWebUsbSupported() : !isWebBluetoothSupported())
                  }
                  onClick={transport === "usb" ? linkUsb : linkBluetooth}
                >
                  {linking ? <Loader2 className="size-4 animate-spin" /> : null}
                  {linkedLabel ? "Cambiar dispositivo" : "Elegir dispositivo"}
                </Button>
                {linkedLabel ? (
                  <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Check className="size-3.5" />
                    {linkedLabel}
                  </span>
                ) : null}
              </div>
              {(transport === "usb" ? !isWebUsbSupported() : !isWebBluetoothSupported()) ? (
                <p className="text-xs text-muted-foreground">
                  Este navegador no soporta {transport === "usb" ? "WebUSB" : "Web Bluetooth"}.
                  Usá Chrome o Edge en escritorio.
                </p>
              ) : null}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="printer-name">Nombre</Label>
              <Input
                id="printer-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Cocina, Barra, Caja..."
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="printer-kind">Tipo</Label>
              <Select value={kind} onValueChange={(v) => setKind(v as PrinterKind)}>
                <SelectTrigger id="printer-kind">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {KINDS.map((k) => (
                    <SelectItem key={k.value} value={k.value}>{k.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)}>Cancelar</Button>
          <Button onClick={submit} disabled={!ready || saving}>
            {saving ? <Loader2 className="size-4 animate-spin" /> : null}
            Agregar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
