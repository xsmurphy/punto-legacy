export function triggerWindowPrint(html: string): void {
  const win = window.open("", "_blank", "width=800,height=600")
  if (!win) {
    throw new Error(
      "No se pudo abrir la ventana de impresión. Permitir popups para este sitio.",
    )
  }
  win.document.write(html)
  win.document.close()
  win.focus()
  win.print()
  setTimeout(() => win.close(), 1000)
}
