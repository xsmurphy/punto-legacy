"use client"

/**
 * Diálogo para asignar un artículo o categoría a un slot vacío del POS.
 *
 * Se abre al tocar el botón "+" de un EmptySlot en modo edición.
 * Dos tabs: "Artículo" (buscador sobre el catálogo) y "Categoría"
 * (lista de categorías derivadas de los items en memoria).
 *
 * onSelect llama a addHotkey(position, id, isCategory) en el store.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import { Input } from "@/components/ui/input"
import { Avatar, AvatarImage, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { searchItems } from "@/lib/catalog/search"
import type { PosItem } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"

// ── Props ─────────────────────────────────────────────────────────────────────

interface HotkeyAssignDialogProps {
  /** Posición del slot que se está asignando. */
  position: number | null
  onClose: () => void
}

// ── Componente principal ──────────────────────────────────────────────────────

export function HotkeyAssignDialog({ position, onClose }: HotkeyAssignDialogProps) {
  const open = position !== null
  const [query, setQuery] = React.useState("")
  const inputRef = React.useRef<HTMLInputElement>(null)

  const items = useCatalogStore((s) => s.items)
  const addHotkey = useHotkeysStore((s) => s.addHotkey)

  // Categorías únicas derivadas de los items.
  const categories = React.useMemo(() => {
    const seen = new Map<string, string>()
    for (const i of items) {
      if (i.categoryId && !seen.has(i.categoryId)) {
        seen.set(i.categoryId, i.categoryName ?? "Categoría")
      }
    }
    return Array.from(seen.entries()).map(([id, name]) => ({ id, name }))
  }, [items])

  // Reset query al abrir.
  React.useEffect(() => {
    if (open) {
      setQuery("")
      const id = setTimeout(() => inputRef.current?.focus(), 80)
      return () => clearTimeout(id)
    }
  }, [open])

  const trimmed = query.trim()
  const itemResults = React.useMemo(
    () => (trimmed ? searchItems(items, trimmed, 50) : items.slice(0, 50)),
    [items, trimmed],
  )

  const categoryResults = React.useMemo(() => {
    if (!trimmed) return categories
    const q = trimmed.toLowerCase()
    return categories.filter((c) => c.name.toLowerCase().includes(q))
  }, [categories, trimmed])

  function handleSelectItem(item: PosItem) {
    if (position === null) return
    addHotkey(position, item.id, false)
    onClose()
  }

  function handleSelectCategory(id: string) {
    if (position === null) return
    addHotkey(position, id, true)
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="flex max-h-[80vh] flex-col gap-0 p-0 sm:max-w-lg">
        <DialogHeader className="sr-only">
          <DialogTitle>Asignar hotkey</DialogTitle>
          <DialogDescription>
            Elegí un artículo o una categoría para el slot.
          </DialogDescription>
        </DialogHeader>

        <Tabs defaultValue="item" className="flex h-full flex-col overflow-hidden">
          {/* Tabs + buscador en el header */}
          <div className="space-y-3 px-5 pb-3 pt-5">
            <TabsList className="w-full">
              <TabsTrigger value="item" className="flex-1">
                Artículo
              </TabsTrigger>
              <TabsTrigger value="category" className="flex-1">
                Categoría
              </TabsTrigger>
            </TabsList>
            <Input
              ref={inputRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Buscar…"
              className="h-auto rounded-full border-0 bg-muted/60 px-4 py-2 text-sm shadow-none focus-visible:ring-0"
              autoComplete="off"
              aria-label="Buscar"
            />
          </div>

          {/* ── Tab Artículo ── */}
          <TabsContent value="item" className="m-0 flex-1 overflow-y-auto border-t border-border">
            {itemResults.length === 0 ? (
              <p className="py-10 text-center text-sm text-muted-foreground">
                Sin resultados.
              </p>
            ) : (
              <ul role="listbox" aria-label="Artículos">
                {itemResults.map((item) => (
                  <ItemRow key={item.id} item={item} onSelect={() => handleSelectItem(item)} />
                ))}
              </ul>
            )}
          </TabsContent>

          {/* ── Tab Categoría ── */}
          <TabsContent value="category" className="m-0 flex-1 overflow-y-auto border-t border-border">
            {categoryResults.length === 0 ? (
              <p className="py-10 text-center text-sm text-muted-foreground">
                Sin categorías.
              </p>
            ) : (
              <ul role="listbox" aria-label="Categorías">
                {categoryResults.map((cat) => (
                  <li key={cat.id} role="option" aria-selected={false}>
                    <Button
                      variant="ghost"
                      onClick={() => handleSelectCategory(cat.id)}
                      className={cn(
                        "flex h-auto w-full items-center justify-start gap-3 rounded-none px-4 py-2.5",
                        "font-normal hover:bg-muted/50",
                      )}
                    >
                      {/* Ícono de carpeta estilizado */}
                      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-bold text-foreground">
                        {cat.name.trim()[0]?.toUpperCase() ?? "?"}
                      </span>
                      <span className="truncate text-sm text-foreground">{cat.name}</span>
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  )
}

// ── Fila de artículo ──────────────────────────────────────────────────────────

function ItemRow({ item, onSelect }: { item: PosItem; onSelect: () => void }) {
  const initial = item.name.trim()[0]?.toUpperCase() ?? "?"
  return (
    <li role="option" aria-selected={false}>
      <button
        onClick={onSelect}
        className={cn(
          "flex w-full items-center gap-3 px-4 py-2.5 text-left",
          "transition-colors hover:bg-muted/50 active:bg-muted",
          "focus-visible:outline-none focus-visible:bg-muted/50",
        )}
      >
        <Avatar size="default" className="shrink-0">
          {item.imageUrl && <AvatarImage src={item.imageUrl} alt={item.name} />}
          <AvatarFallback className="text-sm font-semibold">{initial}</AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium text-foreground">{item.name}</p>
          {item.categoryName && (
            <p className="truncate text-xs text-muted-foreground">› {item.categoryName}</p>
          )}
        </div>
      </button>
    </li>
  )
}
