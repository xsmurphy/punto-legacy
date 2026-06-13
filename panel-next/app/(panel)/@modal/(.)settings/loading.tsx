import { Loader2 } from "lucide-react"

/**
 * Loading del slot `@modal` para el intercept de `/settings`. Se renderea
 * mientras Next descarga el chunk del SettingsPage. Idéntico look al
 * `app/(panel)/settings/loading.tsx` para que la transición sea idéntica
 * en ambos casos (deep-link / intercept).
 */
export default function ModalSettingsLoading() {
  return (
    <div
      role="status"
      aria-label="Cargando Configuración"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
    >
      <div className="flex flex-col items-center gap-3">
        <Loader2 className="size-7 animate-spin text-white" />
        <p className="text-sm text-white/80">Cargando Configuración…</p>
      </div>
    </div>
  )
}
