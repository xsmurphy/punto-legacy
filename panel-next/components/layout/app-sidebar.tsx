"use client"

import * as React from "react"
import { useState, type ComponentType } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  ChevronsUpDown,
  LogOut,
  Settings,
  ShieldCheck,
  Search,
  ChevronRight,
  ReceiptText,
  ShoppingCart,
  Component,
  Building2,
  Check,
} from "lucide-react"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { AppCommandPalette } from "@/components/layout/app-command-palette"

export interface NavItem {
  title: string
  to: string
  icon: ComponentType<{ className?: string }>
  badge?: string
}

export interface NavGroup {
  title: string
  icon: ComponentType<{ className?: string }>
  items: NavItem[]
}

export type NavEntry = NavItem | NavGroup

function isGroup(entry: NavEntry): entry is NavGroup {
  return (entry as NavGroup).items !== undefined
}

interface AppSidebarProps {
  scope: "Admin" | "Panel"
  items: NavEntry[]
  user: { name: string; subtitle: string }
  isImpersonating?: boolean
  onExitImpersonation?: () => void
  onLogout?: () => void
  /** Sucursales activas del tenant. Solo se pinta el selector cuando hay ≥2. */
  outlets?: Array<{ id: string; name: string }>
  activeOutletId?: string
  onSelectOutlet?: (outletId: string) => void
  /** Mientras la mutation de cambio de sucursal está en vuelo, deshabilitar items. */
  isSwitchingOutlet?: boolean
  /**
   * View-scope del dropdown: `null` = sigue `activeOutletId`, `"all"` = Todas
   * las sucursales, UUID = override explícito. Define cuál item lleva el ✓.
   */
  viewScope?: string | "all" | null
  /** Handler para "Todas las sucursales". Si no se pasa, no se renderea la opción. */
  onSelectAllOutlets?: () => void
}

export function AppSidebar({
  scope,
  items,
  user,
  isImpersonating = false,
  onExitImpersonation,
  onLogout,
  outlets = [],
  activeOutletId = "",
  onSelectOutlet,
  isSwitchingOutlet = false,
  viewScope = null,
  onSelectAllOutlets,
}: AppSidebarProps) {
  const pathname = usePathname()
  const { toggleSidebar, state, isMobile } = useSidebar()
  const isCollapsed = state === "collapsed"
  const [commandOpen, setCommandOpen] = useState(false)

  // Atajo ⌘K / Ctrl+K para abrir el palette. (⌘B sigue siendo el toggle del
  // sidebar — viene del SidebarProvider.)
  React.useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault()
        setCommandOpen((prev) => !prev)
      }
    }
    window.addEventListener("keydown", handler)
    return () => window.removeEventListener("keydown", handler)
  }, [])

  const userInitials = user.name
    .split(" ")
    .map((s) => s[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase()

  return (
    <Sidebar collapsible="icon" variant="inset">
      {/* pt safe-area: en PWA iOS el header se superpone con status bar sin esto */}
      <SidebarHeader className="pt-[calc(0.5rem+env(safe-area-inset-top))]">
        <div className="flex items-center gap-2 px-2 py-1.5 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:justify-center">
          {/* MARK — solo cuando collapsed. Click = re-abre el sidebar. */}
          <button
            type="button"
            onClick={isCollapsed ? toggleSidebar : undefined}
            aria-label={isCollapsed ? "Expandir menú" : "Punto"}
            className={cn(
              "hidden size-8 aspect-square shrink-0 items-center justify-center group-data-[collapsible=icon]:flex",
              "cursor-pointer transition-opacity hover:opacity-90",
            )}
            tabIndex={isCollapsed ? 0 : -1}
          >
            <PuntoLogo variant="mark" className="size-8" />
          </button>

          {/* WORDMARK — siempre Link al dashboard (comportamiento histórico
              del logo). El selector de sucursal vive en su propio chevron
              a la derecha, junto al SidebarTrigger. El badge ADMIN si
              existe se renderea inline al lado del logo. */}
          <div className="flex items-center gap-2 group-data-[collapsible=icon]:hidden">
            <Link
              href="/"
              aria-label="Ir al dashboard"
              className="inline-flex items-center rounded-md transition-opacity hover:opacity-90"
            >
              <PuntoLogo variant="wordmark" />
            </Link>
            {scope === "Admin" && (
              <Badge variant="outline" className="text-[10px] font-medium">
                ADMIN
              </Badge>
            )}
          </div>

          {/* Spacer — empuja el chevron + trigger al borde derecho del
              header. Se esconde collapsed (todo el bloque se centra). */}
          <div className="flex-1 group-data-[collapsible=icon]:hidden" />

          {/* CHEVRON selector de sucursal — solo si hay >1 outlets. Vive
              pegado al SidebarTrigger a la derecha. Abre el DropdownMenu
              alineado al borde derecho (align="end") para no salir del
              sidebar. */}
          {outlets.length > 1 && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button
                  type="button"
                  aria-label="Cambiar sucursal"
                  className="inline-flex size-7 items-center justify-center rounded-md transition-colors hover:bg-accent/50 cursor-pointer group-data-[collapsible=icon]:hidden"
                >
                  <ChevronsUpDown className="size-4 text-muted-foreground" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                align="end"
                className="min-w-[18rem] max-w-sm"
              >
                <DropdownMenuLabel className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                  Cambiar sucursal
                </DropdownMenuLabel>
                {/* "Todas las sucursales" — primero. El check sigue el
                    `viewScope`: si es 'all' va acá; si es UUID o null
                    cae en el item correspondiente más abajo. */}
                {onSelectAllOutlets && (
                  <>
                    <DropdownMenuItem
                      disabled={isSwitchingOutlet}
                      onSelect={(e) => {
                        if (viewScope === "all") {
                          e.preventDefault()
                          return
                        }
                        onSelectAllOutlets()
                      }}
                    >
                      <span className="flex-1 truncate">Todas las sucursales</span>
                      {viewScope === "all" && <Check className="size-4 opacity-70" />}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                  </>
                )}
                {outlets.map((o) => {
                  const isChecked =
                    viewScope === "all"
                      ? false
                      : viewScope
                        ? o.id === viewScope
                        : o.id === activeOutletId
                  return (
                    <DropdownMenuItem
                      key={o.id}
                      disabled={isSwitchingOutlet}
                      onSelect={(e) => {
                        if (isChecked) {
                          e.preventDefault()
                          return
                        }
                        onSelectOutlet?.(o.id)
                      }}
                    >
                      <span className="flex-1 truncate">{o.name}</span>
                      {isChecked && <Check className="size-4 opacity-70" />}
                    </DropdownMenuItem>
                  )
                })}
              </DropdownMenuContent>
            </DropdownMenu>
          )}

          <SidebarTrigger className="size-7 group-data-[collapsible=icon]:hidden" />
        </div>
      </SidebarHeader>

      <SidebarContent>
        {/* Searchbox-trigger del Command Palette (⌘K). Por ahora solo visual. */}
        <SidebarGroup className="pt-1 pb-0">
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton
                  tooltip="Buscar (⌘K)"
                  onClick={() => setCommandOpen(true)}
                  // Solo border, sin bg ni rounded override — hereda
                  // `rounded-xl` y el bg transparente del SidebarMenuButton
                  // (cva del primitive shadcn).
                  className="h-10 border border-border text-base text-muted-foreground [&>svg]:size-5 md:h-9 md:text-sm md:[&>svg]:size-4"
                >
                  <Search />
                  <span>Buscar…</span>
                  <kbd className="ml-auto hidden items-center gap-0.5 rounded border bg-muted px-1.5 text-[10px] font-medium text-muted-foreground sm:inline-flex">
                    <span className="text-xs">⌘</span>K
                  </kbd>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>

        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu className="gap-2">
              {items.map((entry) =>
                isGroup(entry) ? (
                  <NavGroupRender key={`g:${entry.title}`} group={entry} pathname={pathname} />
                ) : (
                  <NavItemRender key={entry.to} item={entry} pathname={pathname} />
                ),
              )}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter className="pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
        <SidebarMenu>
          {isImpersonating && onExitImpersonation && (
            <SidebarMenuItem>
              <SidebarMenuButton
                onClick={onExitImpersonation}
                tooltip="Salir de impersonación"
                className="text-muted-foreground hover:text-foreground"
              >
                <ShieldCheck className="size-4" />
                <span className="text-xs">Salir de impersonación</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )}
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton
                  size="lg"
                  tooltip={user.name}
                  className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:!p-0"
                >
                  <Avatar className="size-8 rounded-lg">
                    <AvatarFallback className="rounded-lg text-xs">
                      {userInitials || "?"}
                    </AvatarFallback>
                  </Avatar>
                  <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                    <span className="truncate font-semibold">{user.name}</span>
                    {user.subtitle && (
                      <span className="truncate text-xs text-muted-foreground">{user.subtitle}</span>
                    )}
                  </div>
                  <ChevronsUpDown className="ml-auto size-4 group-data-[collapsible=icon]:hidden" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                className="w-[--radix-popper-anchor-width] min-w-56 rounded-lg p-1.5 [&_[role=menuitem]]:py-2 [&_[role=menuitem]]:gap-3"
                side={isMobile ? "bottom" : "right"}
                align="end"
                sideOffset={4}
              >
                <DropdownMenuLabel className="p-0 font-normal">
                  <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <Avatar className="size-8 rounded-lg">
                      <AvatarFallback className="rounded-lg text-xs">
                        {userInitials || "?"}
                      </AvatarFallback>
                    </Avatar>
                    <div className="grid flex-1 text-left text-sm leading-tight">
                      <span className="truncate font-semibold">{user.name}</span>
                      {user.subtitle && (
                        <span className="truncate text-xs text-muted-foreground">{user.subtitle}</span>
                      )}
                    </div>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {/* Items del dropdown legacy (panel/includes/functions.php
                    leftMenu():5722-5726). En el orden exacto del legacy. */}
                <DropdownMenuGroup>
                  <DropdownMenuItem asChild>
                    <Link href="/history-billing">
                      <ReceiptText />
                      Mi Plan
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/purchase">
                      <ShoppingCart />
                      Compras y Gastos
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/outlets">
                      <Building2 />
                      Sucursales
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/modules">
                      <Component />
                      Módulos
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/settings">
                      <Settings />
                      Configuración
                    </Link>
                  </DropdownMenuItem>
                </DropdownMenuGroup>
                {/* Selector de sucursal: vive arriba en el chevron al lado
                    del logo. No se duplica acá para evitar dos puntos de
                    entrada inconsistentes. */}
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={onLogout} className="text-destructive focus:text-destructive">
                  <LogOut />
                  Cerrar Sesión
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
      <AppCommandPalette
        open={commandOpen}
        onOpenChange={setCommandOpen}
        nav={items}
      />
    </Sidebar>
  )
}

// ── helpers de render ────────────────────────────────────────────────────

function isItemActive(to: string, pathname: string): boolean {
  const isHomeRoute = to === "/" || to === "/admin"
  return isHomeRoute
    ? pathname === to
    : pathname === to || pathname.startsWith(to + "/")
}

function NavItemRender({ item, pathname }: { item: NavItem; pathname: string }) {
  const Icon = item.icon
  const isActive = isItemActive(item.to, pathname)
  // En mobile el sidebar es un Sheet; al navegar a una sección, lo cerramos
  // automáticamente para no tener que tappar afuera + ver el contenido.
  // En desktop no hace nada (el sidebar es persistente).
  const { isMobile, setOpenMobile } = useSidebar()
  const onNavClick = () => {
    if (isMobile) setOpenMobile(false)
  }
  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        asChild
        isActive={isActive}
        tooltip={item.title}
        className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9]"
      >
        <Link href={item.to} onClick={onNavClick}>
          <Icon />
          <span>{item.title}</span>
          {item.badge ? (
            <Badge variant="secondary" className="ml-auto">
              {item.badge}
            </Badge>
          ) : null}
        </Link>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

function NavGroupRender({ group, pathname }: { group: NavGroup; pathname: string }) {
  const Icon = group.icon
  const childActive = group.items.some((c) => isItemActive(c.to, pathname))
  const [open, setOpen] = useState<boolean>(childActive)
  const wantOpen = open || childActive
  const { state: sidebarState, isMobile, setOpenMobile } = useSidebar()
  const isCollapsedSidebar = sidebarState === "collapsed"
  // Mismo autoclose mobile que NavItemRender (al navegar a un sub-item).
  const onSubNavClick = () => {
    if (isMobile) setOpenMobile(false)
  }

  if (isCollapsedSidebar) {
    return (
      <SidebarMenuItem>
        <SidebarMenuButton
          isActive={childActive}
          tooltip={group.title}
          onClick={() => setOpen(true)}
          className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9]"
        >
          <Icon />
          <span>{group.title}</span>
        </SidebarMenuButton>
      </SidebarMenuItem>
    )
  }

  return (
    <>
      <SidebarMenuItem>
        <SidebarMenuButton
          isActive={childActive && !wantOpen}
          tooltip={group.title}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={wantOpen}
          className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9]"
        >
          <Icon />
          <span className="flex-1 text-left">{group.title}</span>
          <ChevronRight
            className={cn("ml-auto transition-transform duration-200", wantOpen && "rotate-90")}
          />
        </SidebarMenuButton>
      </SidebarMenuItem>
      {wantOpen &&
        group.items.map((sub) => {
          const SubIcon = sub.icon
          const isActive = isItemActive(sub.to, pathname)
          return (
            <SidebarMenuItem key={sub.to}>
              <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={sub.title}
                className="h-9 pl-7 text-sm [&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9]"
              >
                <Link href={sub.to} onClick={onSubNavClick}>
                  <SubIcon />
                  <span>{sub.title}</span>
                  {sub.badge ? (
                    <Badge variant="secondary" className="ml-auto">
                      {sub.badge}
                    </Badge>
                  ) : null}
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )
        })}
    </>
  )
}
