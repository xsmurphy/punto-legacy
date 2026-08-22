/** Tab grande de la sección "Tu negocio puede ser mucho más". */
export type ModuleTab = {
  key: string
  label: string
  title: string
  description: string
  idealFor: string
  mockup:
    | "ticket"
    | "arqueo"
    | "factura"
    | "stock"
    | "clientes"
    | "reporte"
    | "mesas"
}

export const MODULE_TABS: ModuleTab[] = [
  {
    key: "ventas",
    label: "Ventas",
    title: "La venta, en segundos",
    description:
      "La venta se arma tocando el catálogo o escaneando, y el total sale solo. Contado, crédito o mixto, en la misma pantalla.",
    idealFor: "minimarkets · restaurantes · tiendas",
    mockup: "ticket",
  },
  {
    key: "caja",
    label: "Caja y turnos",
    title: "El turno cierra con números, no con memoria",
    description:
      "Apertura, movimientos y arqueo por turno y por caja. Lo esperado contra lo contado, y cada diferencia con nombre y hora.",
    idealFor: "locales con turnos · más de un cajero",
    mockup: "arqueo",
  },
  {
    key: "mesas",
    label: "Mesas",
    title: "El salón, mesa por mesa",
    description:
      "Cada mesa con su cuenta abierta y su pedido en cocina. Se agrega, se une, se divide o se cobra desde cualquier caja del local.",
    idealFor: "restaurantes · bares · patios de comida",
    mockup: "mesas",
  },
  {
    key: "facturacion",
    label: "Facturación",
    title: "Factura electrónica sin trámite aparte",
    description:
      "La factura sale de la misma venta y se envía sola al organismo fiscal. Numeración y series controladas por el sistema, siempre en regla.",
    idealFor: "todo negocio que emite comprobantes",
    mockup: "factura",
  },
  {
    key: "stock",
    label: "Stock",
    title: "Saber qué hay, antes de que falte",
    description:
      "Cada venta descuenta stock al instante, por depósito y por sucursal. Mínimos con aviso y ajustes con historia.",
    idealFor: "minimarkets · farmacias · ferreterías",
    mockup: "stock",
  },
  {
    key: "clientes",
    label: "Clientes",
    title: "Saber quién te compra",
    description:
      "Quién compró, qué compró y cuánto debe. Crédito con límite, cobranzas al día y la historia completa de cada cliente.",
    idealFor: "almacenes · farmacias · locales de barrio",
    mockup: "clientes",
  },
  {
    key: "reportes",
    label: "Reportes",
    title: "El negocio en números, sin planillas",
    description:
      "Qué se vende, a qué hora y en qué sucursal. Ventas, márgenes y ranking de productos, listos al abrir el panel.",
    idealFor: "dueños que deciden con datos",
    mockup: "reporte",
  },
]

/** Mini-card de la sección "Todo en un solo lugar". */
export type FeatureCard = {
  key: string
  title: string
  description: string
}

export const FEATURE_CARDS: FeatureCard[] = [
  {
    key: "ai",
    title: "Punto AI",
    description:
      "Preguntale por tus números y responde con los datos del negocio.",
  },
  {
    key: "efactura",
    title: "Factura electrónica",
    description:
      "El comprobante se emite y se envía solo, con su estado siempre a la vista.",
  },
  {
    key: "sucursales",
    title: "Multi-sucursal",
    description:
      "Catálogo, precios y reportes por sucursal, bajo una sola marca.",
  },
  {
    key: "ordenes",
    title: "Órdenes y mesas",
    description:
      "El pedido viaja a cocina sin papeles y cada mesa muestra su cuenta abierta.",
  },
  {
    key: "cotizaciones",
    title: "Cotizaciones",
    description:
      "El presupuesto se arma como una venta y se convierte en una con un toque.",
  },
  {
    key: "vales",
    title: "Gift cards y vales",
    description:
      "Se venden por adelantado y se canjean en caja, sin papelitos.",
  },
  {
    key: "credito",
    title: "Crédito y cobranzas",
    description:
      "Venta a crédito con límite por cliente y recibos de cada pago.",
  },
  {
    key: "compras",
    title: "Compras y proveedores",
    description: "La compra carga el stock y deja el costo actualizado.",
  },
  {
    key: "produccion",
    title: "Producción y recetas",
    description:
      "La receta descuenta insumos y calcula el costo del plato sola.",
  },
  {
    key: "combos",
    title: "Combos y agregados",
    description:
      "Mitades, adicionales y combos que bajan literales a la comanda.",
  },
  {
    key: "precios",
    title: "Listas de precio",
    description: "Mayorista, mostrador o delivery: cada canal con su precio.",
  },
  {
    key: "offline",
    title: "Modo offline",
    description:
      "Se corta internet y la caja sigue vendiendo. Al volver, todo se sincroniza.",
  },
  {
    key: "sync",
    title: "Sync en tiempo real",
    description: "Lo que pasa en una caja se ve en todas, al instante.",
  },
  {
    key: "remision",
    title: "Remisiones",
    description: "El traslado entre depósitos sale documentado, no anotado.",
  },
  {
    key: "fiscales",
    title: "Reportes fiscales",
    description:
      "Los libros de venta y de compra salen del sistema, no del contador apurado.",
  },
  {
    key: "impresion",
    title: "Plantillas de impresión",
    description: "Ticket, factura y comanda con tu logo, tal como los querés.",
  },
  {
    key: "dispositivos",
    title: "Dispositivos y cajas",
    description:
      "Cada caja con su sesión, sus permisos y su numeración propia.",
  },
]

/** Rubros listados en el home (sección "Hecho para tu tipo de negocio"). */
export const HOME_RUBROS: { slug: string; label: string }[] = [
  { slug: "restaurantes", label: "Restaurantes" },
  { slug: "bares-y-pubs", label: "Bares y pubs" },
  { slug: "minimarkets", label: "Minimarkets" },
  { slug: "farmacias", label: "Farmacias" },
  { slug: "ferreterias", label: "Ferreterías" },
  { slug: "cafeterias", label: "Cafeterías" },
  { slug: "panaderias", label: "Panaderías" },
  { slug: "heladerias", label: "Heladerías" },
  { slug: "tiendas-de-ropa", label: "Tiendas de ropa" },
]
