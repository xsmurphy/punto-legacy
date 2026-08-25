"use client"

/**
 * Install prompt del POS — muestra un banner en la parte inferior invitando
 * a instalar "Punto" como PWA cuando el navegador emite beforeinstallprompt.
 *
 * Condiciones para mostrar:
 *   - El evento beforeinstallprompt fue diferido (el navegador lo ofrece).
 *   - La app no está ya instalada (standalone / display-mode standalone).
 *   - El usuario no la descartó en los últimos 7 días.
 */

import * as React from "react"
import { Button } from "@/components/ui/button"
import { usePosUIStore } from "@/lib/ui/store"

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>
}

const DISMISS_KEY = "pos:install-dismissed"
const DISMISS_TTL_MS = 7 * 24 * 60 * 60 * 1000

function isAlreadyInstalled(): boolean {
  if (typeof window === "undefined") return false
  // navigator.standalone es true en iOS cuando se abre desde la home screen.
  if ("standalone" in navigator && (navigator as { standalone?: boolean }).standalone) return true
  return window.matchMedia("(display-mode: standalone)").matches
}

function isDismissed(): boolean {
  try {
    const raw = localStorage.getItem(DISMISS_KEY)
    if (!raw) return false
    const ts = Number(raw)
    return Date.now() - ts < DISMISS_TTL_MS
  } catch {
    return false
  }
}

export function InstallPrompt() {
  const [show, setShow] = React.useState(false)
  const deferredPrompt = React.useRef<BeforeInstallPromptEvent | null>(null)
  // El event handler captura beforeinstallprompt al inicio y queda en background;
  // la visibilidad del banner se gatea por menuOpen para no tapar el botón de
  // cobrar — solo aparece cuando el cajero abre el menú principal del POS.
  const menuOpen = usePosUIStore((s) => s.menuOpen)

  React.useEffect(() => {
    if (isAlreadyInstalled() || isDismissed()) return

    const handler = (e: Event) => {
      e.preventDefault()
      deferredPrompt.current = e as BeforeInstallPromptEvent
      setShow(true)
    }

    window.addEventListener("beforeinstallprompt", handler)
    return () => window.removeEventListener("beforeinstallprompt", handler)
  }, [])

  if (!show || !menuOpen) return null

  async function handleInstall() {
    const prompt = deferredPrompt.current
    if (!prompt) return
    await prompt.prompt()
    const { outcome } = await prompt.userChoice
    if (outcome === "accepted") {
      deferredPrompt.current = null
      setShow(false)
    }
  }

  function handleDismiss() {
    try {
      localStorage.setItem(DISMISS_KEY, String(Date.now()))
    } catch {
      // localStorage no disponible — ignorar
    }
    setShow(false)
  }

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 flex items-center justify-between gap-2 border-t bg-card p-3 shadow-lg md:bottom-4 md:left-auto md:right-4 md:rounded-lg md:border">
      <p className="text-sm">Instalar Punto como app</p>
      <div className="flex shrink-0 items-center gap-2">
        <Button variant="outline" size="sm" onClick={handleDismiss}>
          Ahora no
        </Button>
        <Button size="sm" onClick={() => void handleInstall()}>
          Instalar
        </Button>
      </div>
    </div>
  )
}
