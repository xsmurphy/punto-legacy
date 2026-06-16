"use client"

/**
 * Sidebar del POS — icon-rail vertical fijo a la izquierda.
 *
 * Diseño según feedback del owner (2026-06-16):
 *   - Logo Punto arriba (mark icon).
 *   - Iconos verticales: Search / Modules / Cart (activa) / Customers / Reports / Barcode.
 *   - Avatar abajo (placeholder, sin dropdown por ahora).
 *
 * Implementación:
 *   - Reusa el primitive shadcn `Sidebar` con `collapsible="icon"` y
 *     `defaultOpen={false}` en el provider → estado siempre `collapsed`,
 *     lo que activa los tooltips nativos del SidebarMenuButton.
 *   - Width del rail overrideado vía `--sidebar-width-icon` a 4rem (~64px).
 *
 * NO copia el AppSidebar del panel-next (que tiene grupos, badges, etc.).
 * El POS es mucho más simple: 6 iconos + avatar.
 *
 * Las acciones (Search/Customer) abren dialogs vía `usePosUIStore`.
 * El CartPanel lee del mismo store para renderizar los dialogs.
 */

import * as React from "react"
import {
  Search,
  LayoutGrid,
  ShoppingCart,
  Users,
  PieChart,
  ScanBarcode,
} from "lucide-react"

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { usePosUIStore } from "@/lib/ui/store"

export function PosSidebar() {
  const setSearchOpen = usePosUIStore((s) => s.setSearchOpen)
  const setCustomerOpen = usePosUIStore((s) => s.setCustomerOpen)

  return (
    <Sidebar collapsible="icon" variant="sidebar">
      <SidebarHeader className="items-center py-3">
        {/* Logo mark + dot brand verde (estado de sesión activa). */}
        <div className="relative">
          <PuntoLogo variant="mark" className="size-8" />
          <span
            aria-hidden
            className="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-brand ring-2 ring-sidebar"
          />
        </div>
      </SidebarHeader>

      <SidebarContent>
        <SidebarMenu className="items-center gap-1">
          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Buscar producto"
              onClick={() => setSearchOpen(true)}
              aria-label="Buscar producto"
            >
              <Search />
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Módulos"
              aria-label="Módulos"
              onClick={() => {
                // TODO: menú de módulos del POS (mesas, reservas, etc.)
              }}
            >
              <LayoutGrid />
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Caja"
              aria-label="Caja"
              isActive
              onClick={() => {
                // Ya estamos en /register — no-op.
              }}
            >
              <ShoppingCart />
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Clientes"
              onClick={() => setCustomerOpen(true)}
              aria-label="Clientes"
            >
              <Users />
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Reportes"
              aria-label="Reportes"
              onClick={() => {
                // TODO: navegación a reportes del POS.
              }}
            >
              <PieChart />
            </SidebarMenuButton>
          </SidebarMenuItem>

          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Escanear código"
              aria-label="Escanear código"
              onClick={() => {
                // TODO: activar scanner / focus en input de código.
              }}
            >
              <ScanBarcode />
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarContent>

      <SidebarFooter className="items-center pb-3">
        {/* Avatar del usuario — placeholder por ahora.
            TODO: leer iniciales del bootstrap (user.name del JWT pos-app)
            y agregar DropdownMenu con cerrar sesión / cambiar caja. */}
        <Avatar className="size-8">
          <AvatarFallback className="text-xs">—</AvatarFallback>
        </Avatar>
      </SidebarFooter>
    </Sidebar>
  )
}
