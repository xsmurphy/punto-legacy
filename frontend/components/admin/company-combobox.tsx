"use client"

import * as React from "react"
import { Check, ChevronsUpDown } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"
import { useDebounce } from "@/hooks/use-debounce"
import { useAdminCompanies, type AdminCompanyRow } from "@/hooks/use-admin"

/**
 * Selector de empresa de /admin con buscador.
 *
 * La búsqueda va al SERVIDOR (`companies.php?q=`, que busca por nombre, RUC y
 * slug y pagina): con cientos de clientes, traer la lista entera y filtrar en
 * el navegador no escala, y un select sin buscador es inusable.
 */
export function CompanyCombobox({
  id,
  value,
  valueName,
  onPick,
  placeholder = "Buscar empresa…",
}: {
  id?: string
  value: string
  valueName: string
  onPick: (company: AdminCompanyRow, name: string) => void
  placeholder?: string
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const q = useDebounce(search.trim(), 250)
  const { data, isLoading } = useAdminCompanies({ q: q || undefined, pageSize: 20 })
  const rows = data?.rows ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full justify-between font-normal"
        >
          <span className={cn("truncate", !value && "text-muted-foreground")}>
            {value ? valueName || value : placeholder}
          </span>
          <ChevronsUpDown className="size-4 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput placeholder="Buscar por nombre o RUC…" value={search} onValueChange={setSearch} />
          <CommandList>
            <CommandEmpty>{isLoading ? "Buscando…" : "Sin resultados."}</CommandEmpty>
            <CommandGroup>
              {rows.map((c) => {
                const name = c.name || c.companyName || "(sin nombre)"
                return (
                  <CommandItem
                    key={c.id}
                    value={c.id}
                    onSelect={() => {
                      onPick(c, name)
                      setOpen(false)
                    }}
                  >
                    <Check className={cn("size-4", c.id === value ? "opacity-100" : "opacity-0")} />
                    <span className="truncate">{name}</span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
