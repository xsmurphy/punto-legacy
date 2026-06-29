import type { DeviceModule } from "@/lib/auth/device-token"

export interface DeviceClaims {
  companyId: string
  registerId: string
  deviceId: string
}

const KEY = "punto.device.claims"

export function getDeviceClaims(module: DeviceModule = "pos"): DeviceClaims | null {
  if (typeof window === "undefined") return null
  const raw = window.localStorage.getItem(`${KEY}.${module}`)
  try { return raw ? (JSON.parse(raw) as DeviceClaims) : null } catch { return null }
}
export function setDeviceClaims(claims: DeviceClaims, module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(`${KEY}.${module}`, JSON.stringify(claims))
}
export function clearDeviceClaims(module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(`${KEY}.${module}`)
}
