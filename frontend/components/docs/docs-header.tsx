"use client"

import * as React from "react"
import Link from "next/link"
import { Menu } from "lucide-react"

import { DocsNav, type DocsNavBlock } from "@/components/docs/docs-nav"
import { DocsSearch } from "@/components/docs/docs-search"
import { DocsThemeToggle } from "@/components/docs/docs-theme-toggle"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { Button } from "@/components/ui/button"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import type { DocSearchEntry } from "@/lib/docs/content"

export function DocsHeader({
  blocks,
  searchEntries,
}: {
  blocks: DocsNavBlock[]
  searchEntries: DocSearchEntry[]
}) {
  const [menuOpen, setMenuOpen] = React.useState(false)

  return (
    <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <div className="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 lg:px-6">
        <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
          <SheetTrigger asChild>
            <Button variant="ghost" size="icon" className="lg:hidden" aria-label="Menú">
              <Menu />
            </Button>
          </SheetTrigger>
          <SheetContent side="left" className="w-80 overflow-y-auto">
            <SheetHeader>
              <SheetTitle>Ayuda</SheetTitle>
            </SheetHeader>
            <div className="px-4 pb-6">
              <DocsNav blocks={blocks} onNavigate={() => setMenuOpen(false)} />
            </div>
          </SheetContent>
        </Sheet>

        <Link href="/" className="flex items-center gap-2">
          <PuntoLogo />
          <span className="text-sm font-medium text-muted-foreground">Ayuda</span>
        </Link>

        <div className="ml-auto flex items-center gap-2">
          <DocsSearch entries={searchEntries} className="w-40 sm:w-64" />
          <DocsThemeToggle />
        </div>
      </div>
    </header>
  )
}
