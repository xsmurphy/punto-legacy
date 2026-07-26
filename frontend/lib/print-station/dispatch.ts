/**
 * Vinculación física + despacho de bytes de la Estación de Impresión
 * (P1, context/26-print-station-plan.md).
 *
 * La estación es un ROUTER TONTO: el payload llega YA renderizado desde el
 * POS/panel y acá solo se decide por qué transport sale. Reusa exactamente los
 * transports de `lib/hardware/printers/transports/*` (mismo camino que
 * `dispatchBytes()` de `lib/hardware/printers/index.ts`).
 *
 * Persistencia del handle físico: WebUSB/Web Bluetooth NO permiten reabrir un
 * device sin permiso del usuario, pero `navigator.usb.getDevices()` /
 * `navigator.bluetooth.getDevices()` devuelven los YA autorizados. Al montar,
 * matcheamos cada `station_printer` con su device autorizado por
 * vendorId/productId (USB) o id (BT). Si no matchea, la impresora queda "sin
 * vincular" y hay que re-autorizarla — no hay forma de persistir el handle.
 */

import { getAuthorizedPrinters, sendBytes as sendBytesUsb } from "@/lib/hardware/printers/transports/usb"
import { getAuthorizedBluetoothPrinters, sendBytesViaBluetooth } from "@/lib/hardware/printers/transports/bluetooth"
import { sendBytesViaNetwork } from "@/lib/hardware/printers/transports/network"
import { triggerWindowPrint } from "@/lib/hardware/printers/transports/window-print"
import type { PrintJob, StationPrinter } from "./types"

/** Devices USB autorizados, indexados por printer.id. */
export type UsbHandleMap = Map<string, USBDevice>

export interface LinkState {
  /** ids de station_printer que esta PC puede usar ahora mismo. */
  linked: Set<string>
  usbHandles: UsbHandleMap
}

/**
 * Resuelve qué impresoras de la lista están realmente disponibles en esta PC.
 * `network` y `native` siempre lo están (no dependen de un permiso del browser).
 */
export async function resolveLinks(printers: StationPrinter[]): Promise<LinkState> {
  const linked = new Set<string>()
  const usbHandles: UsbHandleMap = new Map()

  const needsUsb = printers.some((p) => p.transport === "usb")
  const needsBt = printers.some((p) => p.transport === "bluetooth")
  const usbDevices = needsUsb ? await getAuthorizedPrinters() : []
  const btDevices = needsBt ? await getAuthorizedBluetoothPrinters() : []

  for (const p of printers) {
    switch (p.transport) {
      case "usb": {
        const { vendorId, productId } = p.transportConfig
        const match = usbDevices.find((d) => d.vendorId === vendorId && d.productId === productId)
        if (match) {
          usbHandles.set(p.id, match)
          linked.add(p.id)
        }
        break
      }
      case "bluetooth": {
        const id = p.transportConfig.bluetoothDeviceId
        if (id && btDevices.some((d) => d.id === id)) linked.add(p.id)
        break
      }
      case "network":
        if (p.transportConfig.networkHost) linked.add(p.id)
        break
      case "native":
        linked.add(p.id)
        break
    }
  }

  return { linked, usbHandles }
}

/** base64 → bytes. El payload de `escpos`/`raw` viaja así en `print_job`. */
export function base64ToBytes(b64: string): Uint8Array {
  const binary = atob(b64)
  const out = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i)
  return out
}

/**
 * Envía bytes crudos por el transport de la impresora. Espejo de
 * `dispatchBytes()` de `lib/hardware/printers/index.ts`, pero resolviendo el
 * handle USB desde el mapa de la estación en vez de re-enumerar en cada envío
 * (la estación imprime en loop; re-enumerar por job es caro y ruidoso).
 */
export async function sendToPrinter(
  printer: StationPrinter,
  bytes: Uint8Array,
  usbHandles: UsbHandleMap,
): Promise<void> {
  switch (printer.transport) {
    case "usb": {
      const device = usbHandles.get(printer.id)
      if (!device) {
        throw new Error(`${printer.name}: dispositivo USB no autorizado en esta PC`)
      }
      await sendBytesUsb(device, bytes)
      break
    }
    case "bluetooth": {
      const id = printer.transportConfig.bluetoothDeviceId
      if (!id) throw new Error(`${printer.name}: sin dispositivo Bluetooth asociado`)
      await sendBytesViaBluetooth(id, bytes)
      break
    }
    case "network": {
      const host = printer.transportConfig.networkHost
      if (!host) throw new Error(`${printer.name}: sin host de red configurado`)
      await sendBytesViaNetwork(host, printer.transportConfig.networkPort ?? 9100, bytes, "print")
      break
    }
    case "native":
      throw new Error(`${printer.name}: el transport nativo solo acepta formato HTML`)
  }
}

/**
 * Despacha un job completo. `copies > 1` repite el envío N veces de forma
 * SERIALIZADA (no paralela: dos transferOut simultáneos sobre el mismo
 * endpoint USB se pisan).
 */
export async function dispatchJob(
  job: PrintJob,
  printer: StationPrinter,
  usbHandles: UsbHandleMap,
  drawerPulse: Uint8Array,
): Promise<void> {
  const copies = Math.max(1, job.copies)

  if (job.format === "html") {
    for (let i = 0; i < copies; i++) triggerWindowPrint(job.payload)
    return
  }

  const bytes = base64ToBytes(job.payload)
  for (let i = 0; i < copies; i++) {
    await sendToPrinter(printer, bytes, usbHandles)
  }
  if (job.openDrawer) {
    await sendToPrinter(printer, drawerPulse, usbHandles)
  }
}
