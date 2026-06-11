"use client"

import * as React from "react"
import { toast } from "sonner"
import { Camera, Loader2, Trash2, ImagePlus } from "lucide-react"

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
}

/**
 * Foto principal del producto: dropzone redondo tipo avatar.
 * Muestra image[0] (la portada). Click → file picker; hover → opciones.
 * Para gestionar las otras 4 imágenes se usa la pestaña Imágenes.
 */
export function ProductPhoto({ itemId, images, disabled }: Props) {
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

  return (
    <div className="flex items-center gap-4">
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        aria-label={cover ? "Cambiar foto del producto" : "Subir foto del producto"}
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
        className={cn(
          "group relative flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-dashed transition",
          disabled
            ? "border-muted-foreground/15 bg-muted/30 cursor-not-allowed"
            : dragOver
            ? "border-primary bg-primary/10 cursor-pointer"
            : "border-muted-foreground/30 bg-muted/40 hover:border-primary hover:bg-primary/5 cursor-pointer",
        )}
      >
        {cover ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={cover.url}
            alt=""
            className="size-full rounded-full object-cover"
          />
        ) : (
          <div className="flex flex-col items-center gap-1 text-muted-foreground">
            <ImagePlus className="size-7 opacity-60" />
          </div>
        )}

        {/* Overlay hover */}
        {!disabled && cover && (
          <div className="absolute inset-0 flex items-center justify-center rounded-full bg-black/0 opacity-0 transition group-hover:bg-black/45 group-hover:opacity-100">
            <Camera className="size-5 text-white" />
          </div>
        )}

        {busy && (
          <div className="absolute inset-0 flex items-center justify-center rounded-full bg-black/50">
            <Loader2 className="size-5 animate-spin text-white" />
          </div>
        )}
      </div>

      <div className="flex flex-col gap-1">
        <p className="text-sm font-medium">Foto del producto</p>
        <p className="text-xs text-muted-foreground">
          {disabled
            ? "Guardá el artículo primero para poder subir una foto."
            : cover
            ? "Click para reemplazar. Para más imágenes andá a la pestaña Imágenes."
            : "Click o arrastrá una imagen. JPG / PNG / WEBP."}
        </p>
        {cover && !disabled && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="w-fit gap-1.5 text-xs text-destructive hover:text-destructive"
            onClick={onRemove}
            disabled={busy}
          >
            <Trash2 className="size-3.5" />
            Eliminar foto
          </Button>
        )}
      </div>

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
