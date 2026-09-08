"use client"

import * as React from "react"
import { Check, ChevronsUpDown, Loader2, RefreshCw } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Label } from "@/components/ui/label"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { useDebounce } from "@/hooks/use-debounce"
import {
  useGeoCities,
  useGeoDepartments,
  useGeoDistricts,
  useGeoStatus,
  useSyncGeoCatalog,
} from "@/hooks/use-geo-catalog"
import { usePermission } from "@/hooks/use-permissions"
import type { GeoNode } from "@/lib/types/geo"
import type { EInvoiceEstablishment } from "@/lib/types/einvoice"

/**
 * El domicilio geográfico de un establecimiento fiscal, elegido de una lista
 * en CASCADA: departamento → distrito → ciudad.
 *
 * ── Qué reemplaza y por qué ──────────────────────────────────────────────
 *
 * Antes eran seis inputs sueltos: tres códigos numéricos tipeados de memoria
 * y tres descripciones tipeadas al lado. Nadie sabe que CAPITAL es el 1, y el
 * código y la descripción podían quedar contradictorios sin que nada avisara
 * — un domicilio declarado ante la autoridad tributaria con el código de una
 * ciudad y el nombre de otra.
 *
 * Acá el código Y la descripción se completan JUNTOS, de una sola elección:
 * son un solo dato con dos representaciones, no dos campos.
 *
 * ── La cascada limpia hacia abajo, a propósito ───────────────────────────
 *
 * Cambiar el departamento borra distrito y ciudad; cambiar el distrito borra
 * la ciudad. Conservarlos sería dejar en el formulario una combinación que la
 * jerarquía real no admite, y el alta fiscal la rechazaría (o peor: la
 * aceptaría).
 *
 * ── Un código guardado que ya no está en el catálogo igual se muestra ────
 *
 * Si el comercio había cargado un código a mano, o el proveedor dio de baja
 * esa ciudad, la opción se inyecta en la lista con lo que haya guardado. La
 * pantalla nueva nunca puede hacer desaparecer un dato que la vieja mostraba.
 */
export function EstablishmentGeoFields({
  idPrefix,
  establishment,
  onChange,
  disabled,
  fallback,
}: {
  idPrefix: string
  establishment: EInvoiceEstablishment
  onChange: (patch: Partial<EInvoiceEstablishment>) => void
  disabled: boolean
  /** Qué mostrar mientras el catálogo esté vacío (ver `GeoCatalogEmpty`). */
  fallback: React.ReactNode
}) {
  const { data: status, isLoading } = useGeoStatus()

  // Un solo país cargado ⇒ se filtra por ése. Varios (o ninguno) ⇒ sin
  // filtro: elegir uno por default sería hardcodear un país en el código.
  const country = status?.countries.length === 1 ? status.countries[0] : undefined

  const departmentCode = toCode(establishment.departamento)
  const districtCode = toCode(establishment.distrito)
  const cityCode = toCode(establishment.ciudad)

  const departments = useGeoDepartments(country)
  const districts = useGeoDistricts(departmentCode, country)

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
      </div>
    )
  }

  // Catálogo sin sincronizar: el formulario NO puede quedar inutilizable —
  // el alta de facturación electrónica es lo que está esperando esta
  // pantalla. Se degrada a la carga manual, con el aviso arriba.
  if (!status || status.departments === 0) {
    return <>{fallback}</>
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <GeoSelect
        id={`${idPrefix}-departamento`}
        label="Departamento"
        placeholder="Elegí el departamento"
        options={departments.data ?? []}
        loading={departments.isLoading}
        code={departmentCode}
        description={establishment.departamentoDescripcion}
        disabled={disabled}
        onSelect={(node) =>
          onChange({
            departamento: node.code,
            departamentoDescripcion: node.name,
            // La cascada se limpia hacia abajo: el distrito y la ciudad
            // anteriores pertenecen a otro departamento.
            distrito: "",
            distritoDescripcion: "",
            ciudad: "",
            ciudadDescripcion: "",
          })
        }
      />

      <GeoSelect
        id={`${idPrefix}-distrito`}
        label="Distrito"
        placeholder={departmentCode === null ? "Elegí primero el departamento" : "Elegí el distrito"}
        options={districts.data ?? []}
        loading={districts.isLoading}
        code={districtCode}
        description={establishment.distritoDescripcion}
        disabled={disabled || departmentCode === null}
        onSelect={(node) =>
          onChange({
            distrito: node.code,
            distritoDescripcion: node.name,
            ciudad: "",
            ciudadDescripcion: "",
          })
        }
      />

      <CityCombobox
        id={`${idPrefix}-ciudad`}
        districtCode={districtCode}
        departmentCode={departmentCode}
        country={country}
        code={cityCode}
        description={establishment.ciudadDescripcion}
        disabled={disabled || districtCode === null}
        onSelect={(node) => onChange({ ciudad: node.code, ciudadDescripcion: node.name })}
      />
    </div>
  )
}

/**
 * Aviso de catálogo vacío, con el botón para sincronizarlo. Va ARRIBA de los
 * campos manuales: el comercio puede cargar los códigos a mano igual que
 * antes, o traer el catálogo y elegirlos de una lista.
 *
 * El botón solo aparece con `einvoice.manage`. Sin el permiso, el aviso dice
 * qué falta y quién puede resolverlo, en vez de ofrecer un botón que va a dar
 * 403.
 */
export function GeoCatalogEmptyNotice() {
  const { data: status, isLoading } = useGeoStatus()
  const canManage = usePermission("einvoice.manage")
  const sync = useSyncGeoCatalog()

  if (isLoading || (status && status.departments > 0)) {
    return null
  }

  return (
    <Alert>
      <AlertTitle>El catálogo de departamentos y ciudades todavía no se descargó</AlertTitle>
      <AlertDescription>
        <p>
          Mientras tanto, cargá los códigos a mano: figuran en tu constancia del Marangatu, junto
          con la dirección que declaraste. Con el catálogo descargado los elegís de una lista y el
          código y la descripción se completan solos.
        </p>
        {canManage ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={sync.isPending}
            onClick={() =>
              sync.mutate(undefined, {
                onSuccess: (r) => toast.success(`Catálogo descargado: ${r.cities} ciudades`),
                onError: (e) => toast.error(e.message),
              })
            }
          >
            {sync.isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <RefreshCw className="size-4" />
            )}
            Descargar catálogo
          </Button>
        ) : (
          <p>Pedile a un administrador que lo descargue desde esta misma pantalla.</p>
        )}
      </AlertDescription>
    </Alert>
  )
}

/**
 * Un nivel de la cascada con lista corta (departamentos, distritos).
 *
 * Guarda el CÓDIGO y la DESCRIPCIÓN de una sola elección — nunca uno sin el
 * otro.
 */
function GeoSelect({
  id,
  label,
  placeholder,
  options,
  loading,
  code,
  description,
  disabled,
  onSelect,
}: {
  id: string
  label: string
  placeholder: string
  options: GeoNode[]
  loading: boolean
  code: number | null
  description: string
  disabled: boolean
  onSelect: (node: GeoNode) => void
}) {
  const items = withSavedOption(options, code, description)

  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Select
        value={code === null ? undefined : String(code)}
        disabled={disabled || loading}
        onValueChange={(v) => {
          const found = items.find((o) => String(o.code) === v)
          if (found) onSelect(found)
        }}
      >
        <SelectTrigger id={id} className="w-full">
          <SelectValue placeholder={loading ? "Cargando…" : placeholder} />
        </SelectTrigger>
        <SelectContent>
          {items.map((o) => (
            <SelectItem key={o.code} value={String(o.code)}>
              {o.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}

/**
 * La ciudad va con buscador y no con un select pelado: son ~6.400 en el
 * catálogo del país y, aunque acá estén acotadas al distrito elegido, el
 * patrón de lista larga del proyecto (mismo que `ProductPicker`) es el que
 * corresponde. La búsqueda la resuelve el servidor, sin acentos.
 */
function CityCombobox({
  id,
  districtCode,
  departmentCode,
  country,
  code,
  description,
  disabled,
  onSelect,
}: {
  id: string
  districtCode: number | null
  departmentCode: number | null
  country?: string
  code: number | null
  description: string
  disabled: boolean
  onSelect: (node: GeoNode) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState("")
  const search = useDebounce(query, 250)
  const cities = useGeoCities(districtCode, departmentCode, search, country)

  const items = withSavedOption(cities.data ?? [], code, description)
  const selected = items.find((o) => o.code === code)

  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>Ciudad</Label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            role="combobox"
            disabled={disabled}
            className="w-full justify-between font-normal"
          >
            {selected ? (
              <span className="truncate">{selected.name}</span>
            ) : (
              <span className="text-muted-foreground">
                {districtCode === null ? "Elegí primero el distrito" : "Buscá tu ciudad"}
              </span>
            )}
            <ChevronsUpDown className="ml-2 size-4 text-muted-foreground" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[260px] p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput placeholder="Buscar ciudad…" value={query} onValueChange={setQuery} />
            <CommandList>
              <CommandEmpty>
                {cities.isLoading ? (
                  <div className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> Buscando…
                  </div>
                ) : (
                  <div className="py-4 text-sm text-muted-foreground">
                    Ninguna ciudad de este distrito coincide
                  </div>
                )}
              </CommandEmpty>
              <CommandGroup>
                {items.map((o) => (
                  <CommandItem
                    key={o.code}
                    value={String(o.code)}
                    onSelect={() => {
                      onSelect(o)
                      setOpen(false)
                      setQuery("")
                    }}
                  >
                    <span className="flex-1 truncate">{o.name}</span>
                    {o.code === code ? <Check className="size-4" /> : null}
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

/** `number | ""` del formulario → código o null. */
function toCode(value: number | "" | null | undefined): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null
}

/**
 * Garantiza que el valor GUARDADO esté en la lista, aunque el catálogo no lo
 * traiga (código cargado a mano antes de que existiera el catálogo, o ciudad
 * que el proveedor dio de baja). Sin esto, el select se vería vacío y el
 * primer cambio de otro campo lo habría dejado así — la pantalla nueva
 * borrando un dato fiscal que la vieja mostraba.
 */
function withSavedOption(options: GeoNode[], code: number | null, description: string): GeoNode[] {
  if (code === null || options.some((o) => o.code === code)) {
    return options
  }
  const name = description.trim() !== "" ? description : `Código ${code}`
  return [{ code, name, countryCode: "" }, ...options]
}
