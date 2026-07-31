"use client"

import * as React from "react"
import Link from "next/link"
import { Bell, CheckCheck, X } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { formatDate, formatDateTime } from "@/lib/format-date"
import { cn } from "@/lib/utils"
import {
  useDismissNotification,
  useMarkAllNotificationsRead,
  useMarkNotificationsRead,
  useNotificationsFeed,
  type NotificationItem,
  type NotificationKind,
} from "@/hooks/use-notifications"

const KIND_LABELS: Record<NotificationKind, string> = {
  check_due: "Cheque",
  loan_due: "Cuota",
  purchase_due: "Compra",
  event: "Evento",
}

const KIND_BADGE_VARIANT: Record<NotificationKind, "outline" | "secondary" | "default"> = {
  check_due: "outline",
  loan_due: "secondary",
  purchase_due: "default",
  event: "outline",
}

type KindFilter = "all" | NotificationKind

export default function NotificacionesPage() {
  const [kindFilter, setKindFilter] = React.useState<KindFilter>("all")
  const { data, isLoading } = useNotificationsFeed()
  const dismiss = useDismissNotification()
  const markRead = useMarkNotificationsRead()
  const markAllRead = useMarkAllNotificationsRead()

  const items = data?.items ?? []
  const filtered = kindFilter === "all" ? items : items.filter((i) => i.kind === kindFilter)
  const unreadCount = data?.unreadCount ?? 0

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Notificaciones</h1>
          <p className="text-sm text-muted-foreground">
            Vencimientos financieros y eventos del negocio. Descartá los que ya resolviste.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={kindFilter} onValueChange={(v) => setKindFilter(v as KindFilter)}>
            <SelectTrigger className="w-48">
              <SelectValue placeholder="Todos los tipos" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todos los tipos</SelectItem>
              <SelectItem value="check_due">Cheques</SelectItem>
              <SelectItem value="loan_due">Cuotas de crédito</SelectItem>
              <SelectItem value="purchase_due">Compras</SelectItem>
              <SelectItem value="event">Eventos</SelectItem>
            </SelectContent>
          </Select>
          {unreadCount > 0 && (
            <Button
              variant="outline"
              size="sm"
              disabled={markAllRead.isPending}
              onClick={() => markAllRead.mutate()}
            >
              <CheckCheck />
              Marcar todo leído
            </Button>
          )}
        </div>
      </header>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base font-semibold tracking-tight">
            {filtered.length} notificaci{filtered.length === 1 ? "ón" : "ones"}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex flex-col gap-2 p-6">
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-14 w-full" />
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="p-6">
              <EmptyState
                icon={Bell}
                title="Sin notificaciones"
                description="No hay vencimientos ni eventos pendientes en este filtro."
              />
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-32">Tipo</TableHead>
                  <TableHead>Descripción</TableHead>
                  <TableHead className="w-40">Fecha</TableHead>
                  <TableHead className="w-16" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((item) => (
                  <NotificationTableRow
                    key={item.alertKey}
                    item={item}
                    onOpen={() => {
                      if (!item.read) markRead.mutate([item.alertKey])
                    }}
                    onDismiss={() => dismiss.mutate(item.alertKey)}
                  />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function NotificationTableRow({
  item,
  onOpen,
  onDismiss,
}: {
  item: NotificationItem
  onOpen: () => void
  onDismiss: () => void
}) {
  const dateLabel = item.dueDate ? formatDate(item.dueDate) : item.date ? formatDateTime(item.date) : "—"
  const overdue = item.severity === "overdue"

  const label = (
    <div className="flex flex-col">
      <span className={cn("font-medium", !item.read ? "text-foreground" : "text-muted-foreground")}>
        {item.title}
      </span>
      {item.message && <span className="text-xs text-muted-foreground">{item.message}</span>}
    </div>
  )

  return (
    <TableRow className={cn(!item.read && "bg-muted/30")}>
      <TableCell>
        <Badge variant={KIND_BADGE_VARIANT[item.kind]}>{KIND_LABELS[item.kind]}</Badge>
      </TableCell>
      <TableCell>
        {item.link ? (
          <Link href={item.link} onClick={onOpen} className="hover:underline">
            {label}
          </Link>
        ) : (
          <button type="button" onClick={onOpen} className="text-left">
            {label}
          </button>
        )}
      </TableCell>
      <TableCell
        className={cn(
          "tabular-nums whitespace-nowrap text-sm",
          overdue && "font-semibold text-destructive",
        )}
      >
        {dateLabel}
        {overdue && <span className="ml-1.5 text-xs">(vencido)</span>}
      </TableCell>
      <TableCell>
        <Button variant="ghost" size="icon" className="size-7" aria-label="Descartar" onClick={onDismiss}>
          <X className="size-4" />
        </Button>
      </TableCell>
    </TableRow>
  )
}
