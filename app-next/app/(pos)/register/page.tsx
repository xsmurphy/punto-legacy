/**
 * Pantalla de caja principal — placeholder Sprint 0.
 *
 * Esta es la ruta central del POS: `/(pos)/register`.
 *
 * En Slice A se reemplaza con la pantalla real que incluye:
 *   ┌──────────────────────────────────────────────┐
 *   │  [Header: empresa | cajero | caja | reloj]   │
 *   ├─────────────────────┬────────────────────────┤
 *   │  Carrito de venta   │  Catálogo de items     │
 *   │  (izquierda ~40%)   │  (derecha ~60%)        │
 *   │                     │  ┌──────────────────┐  │
 *   │  Lista de items     │  │ Búsqueda + filtro│  │
 *   │  agregados          │  └──────────────────┘  │
 *   │                     │  [Categorías grid]     │
 *   │                     │  [Items grid]          │
 *   │  ─────────────────  │  [Hotkeys grid]        │
 *   │  Totales / cliente  │                        │
 *   │  Numpad / acciones  │                        │
 *   └─────────────────────┴────────────────────────┘
 *
 * Fidelidad visual: espejo 1:1 de la pantalla legacy `app/index.php`
 * en modo `register`. Ver context/16 §2 y el render real de la caja legacy.
 *
 * Referencia legacy:
 *   app/index.php → estructura HTML del POS
 *   app/scripts/app.js → namespace `ncmTransactions` (lógica del carrito)
 *   app/scripts/app.js → namespace `ncmPayments` (cobro)
 *
 * TODO (Slice A):
 *   - Carrito: componente `<Cart>` con lista, cantidades, totales.
 *   - Catálogo: grilla de categorías + items con `searchItems()`.
 *   - Numpad: input de cantidad y monto.
 *   - Botón "Cobrar" → navega a `/pay` o abre modal de cobro.
 *   - Barcode scanner: `useBarcodeScanner()` + `findItemBySku()`.
 *   - Cliente: búsqueda inline con `searchCustomers()`.
 */

export default function RegisterPage() {
  return (
    <div className="flex h-full items-center justify-center">
      <div className="text-center">
        <p className="text-lg font-semibold text-foreground">Caja — Slice A</p>
        <p className="mt-2 text-sm text-muted-foreground">
          Sprint 0 scaffold. La pantalla de caja se implementa en Slice A.
        </p>
        <p className="mt-1 text-xs text-muted-foreground">
          Ver context/16-app-next-rewrite.md §7 Slice A.
        </p>
      </div>
    </div>
  )
}
