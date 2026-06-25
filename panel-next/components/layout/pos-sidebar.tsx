"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  ScanBarcode,
  Bookmark,
  Settings2,
  Lock,
} from "lucide-react"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { useLockStore } from "@/lib/pos/lock-store"

/**
 * Sidebar mínimo exclusivo del POS. Muestra SOLO las rutas del workspace
 * de caja — sin links al panel (Artículos, Contactos, Reportes, etc.).
 *
 * Siempre renderizado collapsed/icon en desktop (PosSidebarProvider lo fuerza).
 * En mobile aparece como Sheet al abrirse.
 */
export function PosSidebar() {
  const pathname = usePathname()
  const { data: parkedSales } = useParkedSales()
  const lock = useLockStore((s) => s.lock)
  const parkedCount = parkedSales?.length ?? 0

  return (
    <Sidebar collapsible="icon" variant="inset">
      <SidebarHeader className="pt-[calc(0.5rem+env(safe-area-inset-top))]">
        <div className="flex items-center gap-2 px-2 py-1.5 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:justify-center">
          <Link
            href="/"
            aria-label="Ir al panel"
            className={cn(
              "hidden size-8 aspect-square shrink-0 items-center justify-center group-data-[collapsible=icon]:flex",
              "cursor-pointer transition-opacity hover:opacity-90",
            )}
          >
            <PuntoLogo variant="mark" className="size-8" />
          </Link>
          <div className="grid group-data-[collapsible=icon]:hidden min-w-0">
            <span className="truncate text-sm font-semibold leading-tight">Punto</span>
          </div>
        </div>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu className="gap-1">
              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  isActive={pathname === "/pos"}
                  tooltip="Caja"
                  className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                >
                  <Link href="/pos">
                    <ScanBarcode />
                    <span>Caja</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>

              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  isActive={pathname.startsWith("/pos/guardadas")}
                  tooltip="Guardadas"
                  className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                >
                  <Link href="/pos/guardadas">
                    <Bookmark />
                    <span>Guardadas</span>
                  </Link>
                </SidebarMenuButton>
                {parkedCount > 0 && (
                  <SidebarMenuBadge>{parkedCount}</SidebarMenuBadge>
                )}
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter className="pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Bloquear"
              onClick={lock}
              className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 [&:hover]:!bg-[#E3E5E9] dark:[&:hover]:!bg-[#1A1D1F]"
            >
              <Lock />
              <span>Bloquear</span>
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              asChild
              isActive={pathname.startsWith("/pos/ajustes")}
              tooltip="Ajustes del dispositivo"
              className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
            >
              <Link href="/pos/ajustes">
                <Settings2 />
                <span>Ajustes del dispositivo</span>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}
