"use client"

import * as React from "react"
import { toast } from "sonner"
import { Camera, Loader2, Trash2, ImagePlus } from "lucide-react"

import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { useUploadCompanyLogo, useDeleteCompanyLogo } from "@/hooks/use-settings"
import { cn } from "@/lib/utils"

interface Props {
  /** URL del logo actual (con cache-bust). null si la empresa aún no subió. */
  logoUrl: string | null
  hasLogo: boolean
  /** Tamaño del círculo en px. Default 96 (más grande que el de productos
   *  porque el logo es la identidad principal del tab Empresa). */
  size?: number
}

/**
 * Logo de la empresa: dropzone redondo tipo avatar.
 * Una sola imagen — sin galería. Click → file picker; hover → opciones.
 * Espejo simplificado de <ProductPhoto> de items.
 */
export function CompanyLogo({ logoUrl, hasLogo, size = 96 }: Props) {
  const inputRef = React.useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = React.useState(false)
  const upload = useUploadCompanyLogo()
  const remove = useDeleteCompanyLogo()

  const onPick = async (file: File | null) => {
    if (!file) return
    if (!/^image\//.test(file.type)) {
      toast.error("Solo imágenes (JPG, PNG, WEBP, GIF)")
      return
    }
    try {
      await upload.mutateAsync(file)
      toast.success("Logo actualizado")
    } catch (e) {
      toast.error("No se pudo subir el logo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    if (inputRef.current) inputRef.current.value = ""
  }

  const onRemove = async () => {
    try {
      await remove.mutateAsync()
      toast.success("Logo eliminado")
    } catch (e) {
      toast.error("No se pudo eliminar el logo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const busy = upload.isPending || remove.isPending
  const dimension = { width: size, height: size }
  const tooltip = hasLogo
    ? "Click para reemplazar el logo"
    : "Click o arrastrá una imagen (JPG / PNG / WEBP)"

  return (
    // `group` en el wrapper externo para que el botón de eliminar (hermano
    // del dropzone, posicionado absolute) reaccione al hover sobre el círculo.
    <div className="group relative">
      <div
        role="button"
        tabIndex={0}
        aria-label={hasLogo ? "Cambiar logo de la empresa" : "Subir logo de la empresa"}
        title={tooltip}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault()
            inputRef.current?.click()
          }
        }}
        onDragOver={(e) => {
          e.preventDefault()
          setDragOver(true)
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault()
          setDragOver(false)
          onPick(e.dataTransfer.files?.[0] ?? null)
        }}
        style={dimension}
        className={cn(
          "group relative flex shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-dashed transition",
          dragOver
            ? "border-primary bg-primary/10 cursor-pointer"
            : "border-muted-foreground/30 bg-muted/40 hover:border-primary hover:bg-primary/5 cursor-pointer",
        )}
      >
        {hasLogo && logoUrl ? (
          <Avatar className="size-full">
            <AvatarImage src={logoUrl} alt="Logo de la empresa" />
            <AvatarFallback className="rounded-full bg-transparent">
              <ImagePlus className="size-7 text-muted-foreground/60" />
            </AvatarFallback>
          </Avatar>
        ) : (
          <ImagePlus className="size-7 text-muted-foreground/60" />
        )}

        {hasLogo && (
          <div className="absolute inset-0 flex items-center justify-center rounded-full bg-black/0 opacity-0 transition group-hover:bg-black/50 group-hover:opacity-100">
            <Camera className="size-5 text-white" />
          </div>
        )}

        {busy && (
          <div className="absolute inset-0 flex items-center justify-center rounded-full bg-black/50">
            <Loader2 className="size-5 animate-spin text-white" />
          </div>
        )}
      </div>

      {hasLogo && (
        <Button
          type="button"
          variant="destructive"
          size="icon"
          aria-label="Eliminar logo"
          title="Eliminar logo"
          className="absolute -right-1 -top-1 size-6 rounded-full opacity-0 shadow transition group-hover:opacity-100"
          onClick={(e) => {
            e.stopPropagation()
            onRemove()
          }}
          disabled={busy}
        >
          <Trash2 className="size-3" />
        </Button>
      )}

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp,image/gif"
        className="hidden"
        onChange={(e) => onPick(e.target.files?.[0] ?? null)}
      />
    </div>
  )
}
