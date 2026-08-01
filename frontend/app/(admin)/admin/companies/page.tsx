"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { ArrowUpDown, Building2, ChevronLeft, ChevronRight, Search } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  useAdminCompanies,
  useAdminPlans,
  useAdminHealthList,
  type AdminCompanyRow,
  type TenantHealthLevel,
} from "@/hooks/use-admin"

function statusBadge(status: string, blocked: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white border-0">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  if (status === "suspended")
    return (
      <Badge variant="outline" className="text-amber-600 border-amber-600">
        Suspendida
      </Badge>
    )
  return <Badge variant="secondary">{status || "—"}</Badge>
}

const PAGE_SIZE_OPTIONS = [25, 50, 100]

function healthBadge(level: TenantHealthLevel, score: number) {
  const cls =
    level === "green"
      ? "bg-emerald-600 text-white border-0"
      : level === "yellow"
        ? "bg-amber-500 text-white border-0"
        : "bg-destructive text-destructive-foreground border-0"
  return (
    <Badge className={cls}>
      <span className="tabular-nums">{score}</span>
    </Badge>
  )
}

function useDebounce<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = React.useState(value)
  React.useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return debounced
}

export default function AdminCompaniesPage() {
  const router = useRouter()

  const [searchInput, setSearchInput] = React.useState("")
  const [statusFilter, setStatusFilter] = React.useState<string>("all")
  const [planFilter, setPlanFilter] = React.useState<string>("all")
  const [page, setPage] = React.useState(1)
  const [pageSize, setPageSize] = React.useState(50)

  const { data: plansData } = useAdminPlans()
  const { data: healthList } = useAdminHealthList()
  const [healthSort, setHealthSort] = React.useState<"none" | "asc" | "desc">("none")

  const healthByCompany = React.useMemo(() => {
    const map = new Map<string, { score: number; level: TenantHealthLevel }>()
    for (const h of healthList ?? []) {
      map.set(h.companyId, { score: h.score, level: h.level })
    }
    return map
  }, [healthList])

  const q = useDebounce(searchInput, 300)

  // Reset page when filters change.
  React.useEffect(() => { setPage(1) }, [q, statusFilter, planFilter, pageSize])

  const { data, isLoading } = useAdminCompanies({
    q: q || undefined,
    status: statusFilter !== "all" ? statusFilter : undefined,
    plan: planFilter !== "all" ? planFilter : undefined,
    page,
    pageSize,
  })

  const baseRows: AdminCompanyRow[] = data?.rows ?? []
  // Sort riesgo-primero es sobre la página actual (el listado de empresas
  // es server-paginado; el score de salud viene de un endpoint aparte que
  // sí trae TODOS los tenants, pero re-paginar por score requeriría fusionar
  // ambos server-side — fuera de alcance de F2, ver context/34).
  const rows: AdminCompanyRow[] = React.useMemo(() => {
    if (healthSort === "none") return baseRows
    const withHealth = [...baseRows]
    withHealth.sort((a, b) => {
      const sa = healthByCompany.get(a.id)?.score
      const sb = healthByCompany.get(b.id)?.score
      if (sa == null && sb == null) return 0
      if (sa == null) return 1
      if (sb == null) return -1
      return healthSort === "asc" ? sa - sb : sb - sa
    })
    return withHealth
  }, [baseRows, healthByCompany, healthSort])
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / pageSize))

  const handlePageSizeChange = (v: string) => {
    setPageSize(Number(v))
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Building2 className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Empresas</h1>
        {total > 0 && (
          <span className="text-sm text-muted-foreground">({total} total)</span>
        )}
      </div>

      {/* Filtros */}
      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Buscar por nombre, RUC, slug…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
          />
        </div>

        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder="Estado" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todos los estados</SelectItem>
            <SelectItem value="active">Activas</SelectItem>
            <SelectItem value="suspended">Suspendidas</SelectItem>
            <SelectItem value="cancelled">Canceladas</SelectItem>
          </SelectContent>
        </Select>

        <Select value={planFilter} onValueChange={setPlanFilter}>
          <SelectTrigger className="w-36">
            <SelectValue placeholder="Plan" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todos los planes</SelectItem>
            {(plansData?.rows ?? []).map((p) => (
              <SelectItem key={p.code} value={String(p.code)}>
                {p.name || `Plan ${p.code}`}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="ml-auto flex items-center gap-2">
          <Select value={String(pageSize)} onValueChange={handlePageSizeChange}>
            <SelectTrigger className="w-28">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PAGE_SIZE_OPTIONS.map((n) => (
                <SelectItem key={n} value={String(n)}>{n} / pág.</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Tabla */}
      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Empresa</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead>
                <button
                  type="button"
                  className="flex items-center gap-1 hover:text-foreground"
                  onClick={() =>
                    setHealthSort((s) => (s === "asc" ? "desc" : s === "desc" ? "none" : "asc"))
                  }
                >
                  Salud
                  <ArrowUpDown className="size-3" />
                </button>
              </TableHead>
              <TableHead>Plan</TableHead>
              <TableHead>País</TableHead>
              <TableHead>Creada</TableHead>
              <TableHead>Sucursales</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              [...Array(pageSize > 10 ? 10 : pageSize)].map((_, i) => (
                <TableRow key={i}>
                  {[...Array(7)].map((__, j) => (
                    <TableCell key={j}>
                      <Skeleton className="h-5 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="py-12 text-center text-sm text-muted-foreground">
                  Sin empresas que coincidan
                </TableCell>
              </TableRow>
            ) : (
              rows.map((c) => (
                <TableRow
                  key={c.id}
                  className="cursor-pointer hover:bg-accent/50"
                  onClick={() => router.push(`/admin/companies/${c.id}`)}
                >
                  <TableCell>
                    <div className="flex flex-col">
                      <span className="font-medium">{c.name || c.companyName || "(sin nombre)"}</span>
                      {c.owner && (
                        <span className="text-xs text-muted-foreground truncate">
                          {[c.owner.name, c.owner.secondName].filter(Boolean).join(" ")}
                          {c.owner.email ? ` · ${c.owner.email}` : ""}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>{statusBadge(c.status, c.blocked)}</TableCell>
                  <TableCell>
                    {healthByCompany.has(c.id) ? (
                      healthBadge(healthByCompany.get(c.id)!.level, healthByCompany.get(c.id)!.score)
                    ) : (
                      <span className="opacity-40">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {c.plan != null ? (
                      <span className="text-sm tabular-nums">{c.plan}</span>
                    ) : (
                      <span className="opacity-40">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {c.country ? (
                      <span className="text-sm">{c.country}</span>
                    ) : (
                      <span className="opacity-40">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {c.createdAt ? (
                      <span className="text-sm tabular-nums text-muted-foreground">
                        {new Date(c.createdAt).toLocaleDateString("es-PY")}
                      </span>
                    ) : (
                      <span className="opacity-40">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {c.counts ? (
                      <span className="text-sm tabular-nums text-muted-foreground">
                        {c.counts.outlets} suc · {c.counts.registers} cajas
                      </span>
                    ) : (
                      <span className="opacity-40">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Paginación */}
      <div className="flex items-center justify-between">
        <p className="text-xs text-muted-foreground">
          Página {page} de {totalPages} ({total} empresas)
        </p>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            disabled={page <= 1 || isLoading}
          >
            <ChevronLeft className="size-4" />
            Anterior
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            disabled={page >= totalPages || isLoading}
          >
            Siguiente
            <ChevronRight className="size-4" />
          </Button>
        </div>
      </div>
    </div>
  )
}
