"use client"

import * as React from "react"
import { toast } from "sonner"
import { Camera, Loader2, Trash2, ImagePlus } from "lucide-react"

import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import {
  useDeleteItemImage,
  useReplaceCoverImage,
} from "@/hooks/use-items"
import type { ItemImage } from "@/lib/types/item"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
  images: ItemImage[]
  /** Si está en modo creación (sin id real), deshabilita uploads. */
  disabled?: boolean
  /** Tamaño del cuadrado en px. Default 80. */
  size?: number
  /** Clase extra del wrapper externo — útil para offset/margen del perfil. */
  className?: string
}

/**
 * Foto principal del producto: dropzone cuadrado con esquinas redondeadas
 * (rounded-lg). Muestra image[0] (la portada). Click → file picker; hover →
 * opciones. Para gestionar las otras 4 imágenes se usa la pestaña Imágenes.
 *
 * Compacto — sin texto descriptivo al lado. Pensado para vivir al lado del
 * Nombre del producto dentro del card 'Datos básicos'. Hover muestra acciones.
 */
export function ProductPhoto({ itemId, images, disabled, size = 80, className }: Props) {
  const inputRef = React.useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = React.useState(false)
  const replace = useReplaceCoverImage()
  const remove = useDeleteItemImage()

  const cover = React.useMemo(
    () => [...images].sort((a, b) => a.sort - b.sort)[0] ?? null,
    [images],
  )

  const onPick = async (file: File | null) => {
    if (!file || disabled) return
    if (!/^image\//.test(file.type)) {
      toast.error("Solo imágenes (JPG, PNG, WEBP, GIF)")
      return
    }
    try {
      await replace.mutateAsync({ itemId, file, current: images })
      toast.success("Foto actualizada")
    } catch (e) {
      toast.error("No se pudo subir la foto", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    if (inputRef.current) inputRef.current.value = ""
  }

  const onRemove = async () => {
    if (!cover) return
    try {
      await remove.mutateAsync({ itemId, imageId: cover.imageId })
    } catch (e) {
      toast.error("No se pudo eliminar la foto", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const busy = replace.isPending || remove.isPending

  const dimension = { width: size, height: size }
  const tooltip = disabled
    ? "Guardá el artículo primero para subir una foto"
    : cover
    ? "Click para reemplazar la foto"
    : "Click o arrastrá una imagen (JPG / PNG / WEBP)"

  return (
    <div className={cn("relative", className)}>
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        aria-label={cover ? "Cambiar foto del producto" : "Subir foto del producto"}
        title={tooltip}
        onClick={() => !disabled && inputRef.current?.click()}
        onKeyDown={(e) => {
          if (disabled) return
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault()
            inputRef.current?.click()
          }
        }}
        onDragOver={(e) => {
          if (disabled) return
          e.preventDefault()
          setDragOver(true)
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          if (disabled) return
          e.preventDefault()
          setDragOver(false)
          onPick(e.dataTransfer.files?.[0] ?? null)
        }}
        style={dimension}
        className={cn(
          "group relative flex shrink-0 items-center justify-center overflow-hidden rounded-lg border-2 border-dashed transition",
          disabled
            ? "border-muted-foreground/15 bg-muted/30 cursor-not-allowed"
            : dragOver
            ? "border-primary bg-primary/10 cursor-pointer"
            : "border-muted-foreground/30 bg-muted/40 hover:border-primary hover:bg-primary/5 cursor-pointer",
        )}
      >
        {cover ? (
          <Avatar className="size-full">
            <AvatarImage src={cover.url} alt="Foto del producto" />
            <AvatarFallback className="rounded-lg bg-transparent">
              <ImagePlus className="size-6 text-muted-foreground/60" />
            </AvatarFallback>
          </Avatar>
        ) : (
          <ImagePlus className="size-6 text-muted-foreground/60" />
        )}

        {/* Overlay hover con icono de cámara */}
        {!disabled && cover && (
          <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-black/0 opacity-0 transition group-hover:bg-black/50 group-hover:opacity-100">
            <Camera className="size-4 text-white" />
          </div>
        )}

        {busy && (
          <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-black/50">
            <Loader2 className="size-4 animate-spin text-white" />
          </div>
        )}
      </div>

      {/* Botón eliminar — esquina superior derecha, solo si hay foto y no está disabled */}
      {cover && !disabled && (
        <Button
          type="button"
          variant="destructive"
          size="icon"
          aria-label="Eliminar foto"
          title="Eliminar foto"
          className="absolute -right-1 -top-1 size-6 rounded-lg opacity-0 shadow transition group-hover:opacity-100"
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
