/**
 * Persistencia de la configuración del dispositivo POS en localStorage.
 *
 * Guarda la última sucursal + caja elegida para este dispositivo, de forma
 * que al entrar al POS no se muestre el modal de selección si ya hay una
 * combinación guardada. El guard del layout lee esto antes de mostrar el modal.
 *
 * Clave: `punto.pos.device`
 *
 * Guard SSR: todas las funciones verifican `typeof window` antes de acceder
 * a localStorage (Next.js ejecuta componentes en el servidor también).
 */

const STORAGE_KEY = "punto.pos.device"

export interface DeviceDefault {
  /** UUID de la sucursal elegida por el usuario en este dispositivo. */
  outletId: string
  /** UUID de la caja elegida por el usuario en este dispositivo. */
  registerId: string
}

/**
 * Lee el default del dispositivo desde localStorage.
 * Retorna null si no hay nada guardado, el JSON está malformado o es SSR.
 */
export function getDeviceDefault(): DeviceDefault | null {
  if (typeof window === "undefined") return null
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as unknown
    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "outletId" in parsed &&
      "registerId" in parsed &&
      typeof (parsed as DeviceDefault).outletId === "string" &&
      typeof (parsed as DeviceDefault).registerId === "string"
    ) {
      return parsed as DeviceDefault
    }
    return null
  } catch {
    return null
  }
}

/**
 * Guarda el default del dispositivo en localStorage.
 * Se llama al completar ambos pasos del modal de setup (sucursal + caja).
 */
export function setDeviceDefault(value: DeviceDefault): void {
  if (typeof window === "undefined") return
  localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
}

/**
 * Borra el default guardado, forzando que el modal de selección vuelva
 * a mostrarse la próxima vez que el guard detecte sin caja activa.
 * Llamado por la acción "Cambiar caja/sucursal" del menú del POS.
 */
export function clearDeviceDefault(): void {
  if (typeof window === "undefined") return
  localStorage.removeItem(STORAGE_KEY)
}
