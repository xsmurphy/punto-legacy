"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  LayoutDashboard,
  Building2,
  Users,
  FileText,
  BarChart3,
  LogOut,
  ChevronsUpDown,
} from "lucide-react"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarTrigger,
  useSidebar,
} from "@/components/ui/sidebar"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { cn } from "@/lib/utils"
import { useAdminContext } from "@/components/admin/admin-auth-guard"
import { useAdminLogout } from "@/hooks/use-admin"

const adminNav = [
  { title: "Dashboard", to: "/admin", icon: LayoutDashboard },
  { title: "Empresas", to: "/admin/companies", icon: Building2 },
  { title: "Administradores", to: "/admin/users", icon: Users },
  { title: "Solicitudes", to: "/admin/requests", icon: FileText },
  { title: "Reportes", to: "/admin/reports", icon: BarChart3 },
]

function isActive(to: string, pathname: string): boolean {
  if (to === "/admin") return pathname === "/admin"
  return pathname === to || pathname.startsWith(to + "/")
}

export function AdminSidebar() {
  const pathname = usePathname()
  const { state, isMobile, setOpenMobile } = useSidebar()
  const isCollapsed = state === "collapsed"
  const admin = useAdminContext()
  const logout = useAdminLogout()

  const initials = admin.name
    .split(" ")
    .map((s) => s[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase()

  const handleNavClick = () => {
    if (isMobile) setOpenMobile(false)
  }

  return (
    <Sidebar collapsible="icon" variant="inset">
      {/* Borde superior de acento — diferenciador visual del panel tenant */}
      <div className="absolute inset-x-0 top-0 h-[3px] bg-destructive rounded-t-lg z-10" />

      <SidebarHeader className="pt-[calc(0.75rem+env(safe-area-inset-top))]">
        <div className="flex items-center gap-2 px-2 py-1.5 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:justify-center">
          {/* Mark — solo cuando collapsed */}
          <button
            type="button"
            aria-label="Expandir menú"
            className={cn(
              "hidden size-8 aspect-square shrink-0 items-center justify-center group-data-[collapsible=icon]:flex",
              "cursor-pointer transition-opacity hover:opacity-90",
            )}
          >
            <PuntoLogo variant="mark" className="size-8" />
          </button>

          {/* Wordmark + Badge ADMIN */}
          <div className="flex items-center gap-2 group-data-[collapsible=icon]:hidden">
            <Link
              href="/admin"
              aria-label="Admin dashboard"
              className="inline-flex items-center rounded-md transition-opacity hover:opacity-90"
            >
              <PuntoLogo variant="wordmark" />
            </Link>
            <Badge
              className="text-[10px] font-semibold bg-destructive text-destructive-foreground border-0 px-1.5"
            >
              ADMIN
            </Badge>
          </div>

          <div className="flex-1 group-data-[collapsible=icon]:hidden" />
          <SidebarTrigger className="size-7 cursor-pointer hover:!bg-[#E3E5E9] dark:hover:!bg-[#1A1D1F] group-data-[collapsible=icon]:hidden" />
        </div>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu className="gap-2">
              {adminNav.map((item) => {
                const Icon = item.icon
                const active = isActive(item.to, pathname)
                return (
                  <SidebarMenuItem key={item.to}>
                    <SidebarMenuButton
                      asChild
                      isActive={active}
                      tooltip={item.title}
                      className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                    >
                      <Link href={item.to} onClick={handleNavClick}>
                        <Icon />
                        <span>{item.title}</span>
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                )
              })}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter className="pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton
                  size="lg"
                  tooltip={admin.name}
                  className="cursor-pointer hover:!bg-[#E3E5E9] dark:hover:!bg-[#1A1D1F] data-[state=open]:!bg-[#EAEEF1] dark:data-[state=open]:!bg-[#1A1D1F] group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:!p-0"
                >
                  <Avatar className="size-8 rounded-lg">
                    <AvatarFallback className="rounded-lg text-xs bg-destructive text-destructive-foreground">
                      {initials || "A"}
                    </AvatarFallback>
                  </Avatar>
                  <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                    <span className="truncate font-semibold">{admin.name}</span>
                    <span className="truncate text-xs text-muted-foreground">{admin.email}</span>
                  </div>
                  <ChevronsUpDown className="ml-auto size-4 group-data-[collapsible=icon]:hidden" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                className="w-[--radix-popper-anchor-width] min-w-56 rounded-lg"
                side={isMobile ? "bottom" : "right"}
                align="end"
                sideOffset={4}
              >
                <DropdownMenuLabel className="font-normal">
                  <div className="flex flex-col gap-0.5 text-sm">
                    <span className="font-semibold">{admin.name}</span>
                    <span className="text-xs text-muted-foreground">{admin.email}</span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => logout.mutate()}
                  className="text-destructive focus:text-destructive cursor-pointer"
                  disabled={logout.isPending}
                >
                  <LogOut className="size-4" />
                  Cerrar sesión
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}
