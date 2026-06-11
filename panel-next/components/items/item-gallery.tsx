"use client"

import * as React from "react"
import Image from "next/image"
import { toast } from "sonner"
import { Plus, X, Star, Loader2, ChevronLeft, ChevronRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { useDeleteItemImage, useReorderItemImages, useUploadItemImage } from "@/hooks/use-items"
import type { ItemImage } from "@/lib/types/item"
import { cn } from "@/lib/utils"

const MAX_IMAGES = 5

interface Props {
  itemId: string
  images: ItemImage[]
  /** Si está en modo creación (sin id real), deshabilita uploads. */
  disabled?: boolean
}

export function ItemGallery({ itemId, images, disabled }: Props) {
  const inputRef = React.useRef<HTMLInputElement>(null)
  const upload = useUploadItemImage()
  const remove = useDeleteItemImage()
  const reorder = useReorderItemImages()

  const sorted = React.useMemo(
    () => [...images].sort((a, b) => a.sort - b.sort),
    [images],
  )

  const onPick = async (files: FileList | null) => {
    if (!files || files.length === 0) return
    const slots = MAX_IMAGES - sorted.length
    if (slots <= 0) {
      toast.error(`Máximo ${MAX_IMAGES} imágenes por artículo`)
      return
    }
    const toUpload = Array.from(files).slice(0, slots)
    if (files.length > slots) {
      toast.warning(`Solo se subirán ${slots} de ${files.length} archivos (máx ${MAX_IMAGES} por artículo)`)
    }
    for (const f of toUpload) {
      try {
        await upload.mutateAsync({ itemId, file: f })
      } catch (e) {
        toast.error("No se pudo subir la imagen", {
          description: e instanceof Error ? e.message : undefined,
        })
      }
    }
    if (inputRef.current) inputRef.current.value = ""
  }

  const onRemove = async (imageId: string) => {
    try {
      await remove.mutateAsync({ itemId, imageId })
    } catch (e) {
      toast.error("No se pudo eliminar la imagen", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const moveTo = async (from: number, to: number) => {
    if (to < 0 || to >= sorted.length || from === to) return
    const order = sorted.map((i) => i.imageId)
    const [moved] = order.splice(from, 1)
    order.splice(to, 0, moved)
    try {
      await reorder.mutateAsync({ itemId, order })
    } catch (e) {
      toast.error("No se pudo reordenar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const setAsCover = async (imageId: string) => {
    const order = [imageId, ...sorted.filter((i) => i.imageId !== imageId).map((i) => i.imageId)]
    try {
      await reorder.mutateAsync({ itemId, order })
    } catch (e) {
      toast.error("No se pudo cambiar la portada", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const canAdd = !disabled && sorted.length < MAX_IMAGES
  const isBusy = upload.isPending || remove.isPending || reorder.isPending

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-center justify-between gap-2">
        <div className="flex flex-col gap-0.5">
          <p className="text-sm font-medium">Galería</p>
          <p className="text-xs text-muted-foreground">
            Hasta {MAX_IMAGES} imágenes. La primera es la portada.
          </p>
        </div>
        <span className="text-xs tabular-nums text-muted-foreground">
          {sorted.length} / {MAX_IMAGES}
        </span>
      </div>

      <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
        {sorted.map((img, idx) => (
          <ImageTile
            key={img.imageId}
            img={img}
            isCover={idx === 0}
            isFirst={idx === 0}
            isLast={idx === sorted.length - 1}
            disabled={disabled || isBusy}
            onRemove={() => onRemove(img.imageId)}
            onMoveLeft={() => moveTo(idx, idx - 1)}
            onMoveRight={() => moveTo(idx, idx + 1)}
            onSetCover={() => setAsCover(img.imageId)}
          />
        ))}
        {canAdd && (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            disabled={isBusy}
            className={cn(
              "flex aspect-square flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-muted-foreground/20 text-xs text-muted-foreground transition hover:border-primary hover:bg-primary/5 hover:text-primary",
              isBusy && "opacity-50 pointer-events-none",
            )}
          >
            {upload.isPending ? (
              <Loader2 className="size-5 animate-spin" />
            ) : (
              <>
                <Plus className="size-5" />
                <span>Agregar</span>
              </>
            )}
          </button>
        )}
      </div>

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp,image/gif"
        multiple
        className="hidden"
        onChange={(e) => onPick(e.target.files)}
      />

      {disabled && (
        <p className="text-xs text-muted-foreground">
          Guardá el artículo primero para poder subir imágenes.
        </p>
      )}
    </Card>
  )
}

function ImageTile({
  img,
  isCover,
  isFirst,
  isLast,
  disabled,
  onRemove,
  onMoveLeft,
  onMoveRight,
  onSetCover,
}: {
  img: ItemImage
  isCover: boolean
  isFirst: boolean
  isLast: boolean
  disabled?: boolean
  onRemove: () => void
  onMoveLeft: () => void
  onMoveRight: () => void
  onSetCover: () => void
}) {
  return (
    <div className="group relative aspect-square overflow-hidden rounded-md border bg-muted">
      <Image
        src={img.url}
        alt=""
        fill
        sizes="200px"
        className="object-cover"
        unoptimized
      />
      {isCover && (
        <div className="absolute left-1 top-1 flex items-center gap-1 rounded-sm bg-primary/90 px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
          <Star className="size-3 fill-current" />
          Portada
        </div>
      )}
      {!disabled && (
        <div className="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover:bg-black/40 group-hover:opacity-100">
          <div className="flex items-center gap-1">
            {!isCover && (
              <Button
                type="button"
                size="icon"
                variant="secondary"
                className="size-7"
                onClick={onSetCover}
                title="Marcar como portada"
              >
                <Star className="size-3.5" />
              </Button>
            )}
            <Button
              type="button"
              size="icon"
              variant="secondary"
              className="size-7"
              onClick={onMoveLeft}
              disabled={isFirst}
              title="Mover ←"
            >
              <ChevronLeft className="size-3.5" />
            </Button>
            <Button
              type="button"
              size="icon"
              variant="secondary"
              className="size-7"
              onClick={onMoveRight}
              disabled={isLast}
              title="Mover →"
            >
              <ChevronRight className="size-3.5" />
            </Button>
            <Button
              type="button"
              size="icon"
              variant="destructive"
              className="size-7"
              onClick={onRemove}
              title="Eliminar"
            >
              <X className="size-3.5" />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
