const KEY = "punto.device.localId"

export function getOrCreateDeviceLocalId(): string {
  if (typeof window === "undefined") return ""
  let id = window.localStorage.getItem(KEY)
  if (!id || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) {
    id = crypto.randomUUID()
    window.localStorage.setItem(KEY, id)
  }
  return id
}

export function clearDeviceLocalId(): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(KEY)
}
