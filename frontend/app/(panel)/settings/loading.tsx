import { Loader2 } from "lucide-react"

/**
 * Loading UI nativa de Next App Router. Se renderea automáticamente mientras
 * el chunk JS de `/settings/page.tsx` carga (~1200 LOC + ThemePicker +
 * DocumentsTab + varios hooks). Cubre el gap visible que había entre el
 * click "Configuración" en el dropdown del user y el primer paint del
 * <Dialog> real — el usuario veía 3-4s en blanco creyendo que no funcionó.
 *
 * Mimea el overlay del Dialog real (bg/40 + spinner centrado) sin montar
 * radix-dialog, así Next no tiene que hidratar nada — render server-side
 * puro, aparece instantáneo.
 *
 * Cuando el chunk termina de cargar, el page.tsx monta su propio Dialog
 * con skeleton interno (controlled por isLoading de useSettings) y este
 * loading se desmonta automáticamente. Continuidad visual: el overlay y
 * el spinner siguen visibles hasta que el modal real aparezca encima.
 */
export default function SettingsLoading() {
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
