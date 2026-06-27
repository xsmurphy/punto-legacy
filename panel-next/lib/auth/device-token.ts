const KEY = "punto.device.token"

export function getDeviceToken(): string | null {
  if (typeof window === "undefined") return null
  return window.localStorage.getItem(KEY)
}

export function setDeviceToken(token: string): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(KEY, token)
}

export function clearDeviceToken(): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(KEY)
}
