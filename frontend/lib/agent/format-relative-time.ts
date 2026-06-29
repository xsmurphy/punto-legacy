export function formatRelativeTime(ms: number): string {
  const diff = Date.now() - ms
  const seconds = Math.floor(diff / 1_000)
  if (seconds < 60) return "ahora"
  const minutes = Math.floor(diff / 60_000)
  if (minutes < 60) return `Hace ${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `Hace ${hours} h`
  return `Hace ${Math.floor(hours / 24)} d`
}
