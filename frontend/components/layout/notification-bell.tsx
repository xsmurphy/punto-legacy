"use client"

import * as React from "react"
import Link from "next/link"
import { Bell } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { SidebarMenuButton, SidebarMenuItem } from "@/components/ui/sidebar"
import { formatDate, formatRelativeShort } from "@/lib/format-date"
import { cn } from "@/lib/utils"
import {
  useMarkAllNotificationsRead,
  useMarkNotificationsRead,
  useNotificationsFeed,
  type NotificationItem,
} from "@/hooks/use-notifications"

const PREVIEW_LIMIT = 10

/**
 * Campanita del centro de notificaciones (context/31). Vive como una fila
 * más del sidebar (mismo patrón que el trigger de "Buscar") — SIEMPRE
 * presente, nunca insertada/quitada condicionalmente, para no desplazar el
 * resto de la navegación. Click → Popover (NO Sheet/Drawer, regla 2.2 de
 * context/14) con los últimos ~10 items + "Marcar todo leído" + link a la
 * página completa.
 */
export function NotificationBell() {
  const [open, setOpen] = React.useState(false)
  const { data } = useNotificationsFeed()
  const markRead = useMarkNotificationsRead()
  const markAllRead = useMarkAllNotificationsRead()

  const items = data?.items ?? []
  const unreadCount = data?.unreadCount ?? 0
  const preview = items.slice(0, PREVIEW_LIMIT)

  return (
    <SidebarMenuItem>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <SidebarMenuButton
            tooltip="Notificaciones"
            className="h-10 cursor-pointer text-base [&>svg]:size-5 hover:!bg-[#E3E5E9] dark:hover:!bg-[#1A1D1F] md:h-9 md:text-sm md:[&>svg]:size-4"
          >
            <Bell />
            <span>Notificaciones</span>
            {unreadCount > 0 && (
              <Badge variant="secondary" className="ml-auto tabular-nums">
                {unreadCount > 99 ? "99+" : unreadCount}
              </Badge>
            )}
          </SidebarMenuButton>
        </PopoverTrigger>
        <PopoverContent side="right" align="start" sideOffset={8} className="w-96 gap-0 p-0">
          <div className="flex items-center justify-between border-b px-4 py-3">
            <p className="text-sm font-semibold">Notificaciones</p>
            {unreadCount > 0 && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs"
                disabled={markAllRead.isPending}
                onClick={() => markAllRead.mutate()}
              >
                Marcar todo leído
              </Button>
            )}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {preview.length === 0 ? (
              <EmptyState
                icon={Bell}
                title="Sin notificaciones"
                description="Te avisamos acá sobre vencimientos y eventos nuevos."
                ghost={false}
                className="px-4 py-8"
              />
            ) : (
              <ul className="divide-y">
                {preview.map((item) => (
                  <NotificationRow
                    key={item.alertKey}
                    item={item}
                    onOpen={() => {
                      if (!item.read) markRead.mutate([item.alertKey])
                      setOpen(false)
                    }}
                  />
                ))}
              </ul>
            )}
          </div>

          <div className="border-t p-2">
            <Button variant="ghost" size="sm" className="w-full justify-center text-xs" asChild>
              <Link href="/notificaciones" onClick={() => setOpen(false)}>
                Ver todas
              </Link>
            </Button>
          </div>
        </PopoverContent>
      </Popover>
    </SidebarMenuItem>
  )
}

function NotificationRow({ item, onOpen }: { item: NotificationItem; onOpen: () => void }) {
  const dateLabel = item.dueDate
    ? formatDate(item.dueDate)
    : item.date
      ? formatRelativeShort(item.date)
      : null
  const overdue = item.severity === "overdue"

  const content = (
    <div
      className={cn(
        "flex flex-col gap-0.5 px-4 py-3 text-sm transition-colors hover:bg-muted/50",
        !item.read && "bg-muted/30",
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className={cn("truncate font-medium", overdue && "text-destructive")}>{item.title}</span>
        {dateLabel && (
          <span
            className={cn(
              "shrink-0 text-xs text-muted-foreground",
              overdue && "font-medium text-destructive",
            )}
          >
            {dateLabel}
          </span>
        )}
      </div>
      {item.message && <span className="truncate text-xs text-muted-foreground">{item.message}</span>}
    </div>
  )

  if (!item.link) {
    return (
      <li>
        <button type="button" onClick={onOpen} className="block w-full text-left">
          {content}
        </button>
      </li>
    )
  }

  return (
    <li>
      <Link href={item.link} onClick={onOpen} className="block">
        {content}
      </Link>
    </li>
  )
}
