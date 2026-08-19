/**
 * Contenido de los mini-sitios por rubro (`/para/[rubro]`).
 *
 * Cada rubro es config pura: el template (`app/(site)/para/[rubro]/page.tsx`)
 * renderiza siempre la misma estructura. Un rubro "stub" solo tiene hero +
 * CTA; uno "full" agrega bullets de 30 segundos y secciones alternadas.
 */

export type RubroMockupRow = {
  left: string
  right?: string
  sub?: string[]
}

export type RubroMockup = {
  /** Chip superior del card (ej. "VIERNES 21:40"). */
  label: string
  title: string
  caption?: string
  rows: RubroMockupRow[]
  footer?: { left: string; right: string }
}

export type RubroSection = {
  kicker: string
  title: string
  paragraphs: string[]
  /** Link-flecha al final del texto. */
  linkLabel: string
  mockup: RubroMockup
}

export type Rubro = {
  slug: string
  /** Nombre para listas ("Restaurantes"). */
  label: string
  /** Con artículo, para frases ("tu restaurante"). */
  posesivo: string
  eyebrow: string
  heroTitle: string
  heroDescription: string
  thirtySeconds?: string[]
  sections?: RubroSection[]
}

export const RUBROS: Rubro[] = [
  {
    slug: "restaurantes",
    label: "Restaurantes",
    posesivo: "tu restaurante",
    eyebrow: "Para restaurantes",
    heroTitle: "El salón, la cocina y la caja, en sintonía",
    heroDescription:
      "La comanda entra sola a cocina, cada mesa muestra su cuenta abierta y la factura sale al cerrar, con mitades y agregados escritos como los pidió el cliente. Sin cuaderno y sin gritos al pasaplatos.",
    thirtySeconds: [
      "El pedido de cada mesa entra a la cocina en el momento, con agregados y aclaraciones literales.",
      "La cuenta se divide en partes iguales o por lo que consumió cada uno, desde la misma pantalla.",
      "La factura electrónica sale al cerrar la mesa y viaja a SIFEN sola.",
      "Si se corta internet, la caja sigue emitiendo: al volver la conexión todo se sincroniza.",
    ],
    sections: [
      {
        kicker: "Sin gritos al pasaplatos",
        title: "La comanda entra sola, escrita como se pidió",
        paragraphs: [
          "El viernes a la noche el salón no tiene tiempo para traducciones: el mozo carga el pedido en la mesa y la comanda aparece en cocina con su hora de entrada, sus agregados y sus aclaraciones, sin pasar por un papel que se pierde entre la barra y la plancha.",
          "La cocina arma las tandas por orden de llegada, no por quién reclamó más fuerte. Y cuando el plato cambia — sin cebolla, punto jugoso, para llevar — eso baja literal, no interpretado.",
        ],
        linkLabel: "Ver cómo trabaja la cocina",
        mockup: {
          label: "Viernes 21:40",
          title: "Cocina · cola de comandas",
          caption: "Cada comanda con su hora de entrada.",
          rows: [
            {
              left: "Mesa 12 · hace 4 min",
              right: "2 platos",
              sub: ["1× Lomito completo · sin cebolla", "1× Milanesa napolitana"],
            },
            {
              left: "Mesa 3 · hace 1 min",
              right: "1 plato",
              sub: ["1× Pizza muzzarella · mitad fugazzeta"],
            },
          ],
        },
      },
      {
        kicker: "La cuenta sin drama",
        title: "Dividir, cobrar y facturar en el mismo paso",
        paragraphs: [
          "Al final de la comida cada uno sabe cuánto le toca: en partes iguales o por lo que pidió. La mesa se cobra en efectivo, QR o tarjeta — o mezclado — y la factura electrónica sale en ese mismo toque, con el RUC que el cliente diga.",
          "Nada de reconstruir la mesa desde tres papeles: la cuenta vivió en el sistema desde el primer pedido.",
        ],
        linkLabel: "Ver el cobro de una mesa",
        mockup: {
          label: "Mesa 12 · 3 personas",
          title: "Dividir la cuenta",
          rows: [
            { left: "Lucía", right: "Gs. 68.000" },
            { left: "Marcos", right: "Gs. 72.000" },
            { left: "Sofía", right: "Gs. 53.000" },
          ],
          footer: { left: "Total", right: "Gs. 193.000" },
        },
      },
      {
        kicker: "El día cierra en números",
        title: "Caja por turno y reportes que no piden planilla",
        paragraphs: [
          "Cada turno abre y cierra su caja: lo esperado contra lo contado, con cada movimiento anotado. El dueño ve el día por sucursal — qué se vendió, a qué hora, con qué margen — sin esperar a que alguien pase todo a una planilla el lunes.",
        ],
        linkLabel: "Ver el arqueo del turno",
        mockup: {
          label: "Turno noche",
          title: "Arqueo de caja",
          rows: [
            { left: "Apertura", right: "Gs. 500.000" },
            { left: "Ventas en efectivo", right: "Gs. 2.140.000" },
            { left: "Esperado", right: "Gs. 2.640.000" },
          ],
          footer: { left: "Contado", right: "Gs. 2.640.000" },
        },
      },
    ],
  },
  {
    slug: "minimarkets",
    label: "Minimarkets",
    posesivo: "tu minimarket",
    eyebrow: "Para minimarkets",
    heroTitle: "La fila avanza y el stock se cuida solo",
    heroDescription:
      "Escanear, cobrar y facturar en segundos, con el stock descontándose en cada ticket. Los mínimos avisan antes de que falte y la compra al proveedor deja el costo al día. La caja rinde por turno, no por confianza.",
    thirtySeconds: [
      "El código de barras arma el ticket: escanear, cobrar, siguiente cliente.",
      "Cada venta descuenta stock al instante; el mínimo avisa antes del quiebre.",
      "La factura electrónica sale del mismo ticket cuando el cliente la pide.",
      "El cierre de turno compara lo esperado contra lo contado, cajero por cajero.",
    ],
    sections: [
      {
        kicker: "La fila no espera",
        title: "Cobrar al ritmo del escáner",
        paragraphs: [
          "En hora pico el mostrador se mide en segundos por cliente. El ticket se arma escaneando, el total se hace solo y el cobro acepta efectivo, QR o tarjeta sin cambiar de pantalla. Si el cliente pide factura, sale con su RUC en el mismo paso.",
          "El teclado alcanza para todo el flujo — la caja de alto volumen no depende del mouse ni de menús escondidos.",
        ],
        linkLabel: "Ver la caja rápida",
        mockup: {
          label: "Caja 1",
          title: "Ticket en curso",
          rows: [
            { left: "2× Gaseosa 2L", right: "Gs. 30.000" },
            { left: "1× Pan lactal", right: "Gs. 18.000" },
            { left: "3× Yogur bebible", right: "Gs. 21.000" },
          ],
          footer: { left: "Cobrar", right: "Gs. 69.000" },
        },
      },
      {
        kicker: "Antes de que falte",
        title: "El stock avisa, no sorprende",
        paragraphs: [
          "Cada ticket descuenta stock en el momento, por depósito. Cuando un producto toca su mínimo, aparece en la lista de reposición — y la compra al proveedor carga la mercadería y actualiza el costo sin doble tipeo.",
          "El inventario deja de ser un fin de semana de conteo: los ajustes quedan con fecha, usuario y motivo.",
        ],
        linkLabel: "Ver la reposición",
        mockup: {
          label: "Depósito central",
          title: "Por reponer",
          rows: [
            { left: "Aceite 900ml", right: "quedan 4", sub: ["mínimo 12"] },
            { left: "Arroz 1kg", right: "quedan 7", sub: ["mínimo 20"] },
            { left: "Azúcar 1kg", right: "quedan 2", sub: ["mínimo 15"] },
          ],
        },
      },
      {
        kicker: "El turno rinde cuentas",
        title: "Caja por cajero, diferencia con nombre",
        paragraphs: [
          "Cada cajero abre su turno y lo cierra con arqueo: lo que el sistema esperaba contra lo que se contó. Los retiros y gastos del día quedan anotados en el momento, no reconstruidos de memoria a las diez de la noche.",
        ],
        linkLabel: "Ver el cierre de turno",
        mockup: {
          label: "Turno mañana",
          title: "Cierre de caja",
          rows: [
            { left: "Esperado", right: "Gs. 3.480.000" },
            { left: "Contado", right: "Gs. 3.465.000" },
            { left: "Diferencia", right: "Gs. -15.000" },
          ],
        },
      },
    ],
  },
  {
    slug: "farmacias",
    label: "Farmacias",
    posesivo: "tu farmacia",
    eyebrow: "Para farmacias",
    heroTitle: "Vender con receta, vencimiento y crédito bajo control",
    heroDescription:
      "El mostrador cobra rápido, el stock vigila vencimientos y cada cliente con cuenta corriente tiene su límite y su historia. La factura electrónica sale en el mismo paso, como exige SIFEN.",
    thirtySeconds: [
      "Búsqueda por nombre, droga o código de barras, con el precio de cada lista.",
      "El lote y el vencimiento se controlan al vender, no al descubrir la caja vencida.",
      "La cuenta corriente del cliente lleva límite, saldo y recibos de cada pago.",
      "La factura electrónica con RUC sale del mismo ticket, sin trámite aparte.",
    ],
    sections: [
      {
        kicker: "El mostrador no adivina",
        title: "Encontrar el producto como lo pida el cliente",
        paragraphs: [
          "Por marca, por droga o escaneando la caja: el buscador responde al primer intento y muestra existencia por sucursal. Si en esta sucursal no hay, se ve dónde sí — y la venta no se pierde por no saber.",
        ],
        linkLabel: "Ver la búsqueda",
        mockup: {
          label: "Mostrador",
          title: "Buscar: ibuprofeno",
          rows: [
            { left: "Ibuprofeno 400mg × 10", right: "Gs. 15.000", sub: ["Centro: 24 · Villa Morra: 8"] },
            { left: "Ibuprofeno 600mg × 10", right: "Gs. 22.000", sub: ["Centro: 11 · Villa Morra: 0"] },
          ],
        },
      },
      {
        kicker: "Primero en vencer, primero en salir",
        title: "Vencimientos vigilados por el sistema",
        paragraphs: [
          "Cada lote entra con su vencimiento y el reporte de próximos a vencer ordena la góndola antes de que sea pérdida. Lo vencido no se vende: la caja lo frena, no el ojo del cajero.",
        ],
        linkLabel: "Ver próximos a vencer",
        mockup: {
          label: "Este mes",
          title: "Próximos a vencer",
          rows: [
            { left: "Amoxicilina susp.", right: "12 días", sub: ["Lote A-1042 · 6 unidades"] },
            { left: "Vitamina C 500", right: "28 días", sub: ["Lote C-2210 · 14 unidades"] },
          ],
        },
      },
      {
        kicker: "La libreta, jubilada",
        title: "Cuenta corriente con límite y recibo",
        paragraphs: [
          "El cliente de siempre compra a crédito con un límite definido, y cada pago queda con su recibo. La cobranza del mes sale de un listado, no de una libreta que solo entiende quien la escribió.",
        ],
        linkLabel: "Ver cuentas corrientes",
        mockup: {
          label: "Cuentas corrientes",
          title: "Saldos al día",
          rows: [
            { left: "Elvira Ruiz", right: "Gs. 180.000", sub: ["límite Gs. 500.000"] },
            { left: "Ramón Ortiz", right: "Gs. 65.000", sub: ["último pago hace 8 días"] },
          ],
        },
      },
    ],
  },
  // Stubs — hero + CTA; el contenido completo se escribe cuando el rubro se lance.
  {
    slug: "ferreterias",
    label: "Ferreterías",
    posesivo: "tu ferretería",
    eyebrow: "Para ferreterías",
    heroTitle: "Miles de artículos, un mostrador que no duda",
    heroDescription:
      "Buscar entre miles de códigos, vender fraccionado, cotizar obras y llevar cuenta corriente de los clientes de siempre. El stock por depósito y los precios por lista, sin planillas paralelas.",
  },
  {
    slug: "cafeterias",
    label: "Cafeterías",
    posesivo: "tu cafetería",
    eyebrow: "Para cafeterías",
    heroTitle: "El mostrador rápido y la clientela que vuelve",
    heroDescription:
      "Cobrar el café en segundos, con combos y agregados que bajan claros a la barra. Gift cards para regalar y la historia de cada cliente para hacer que vuelva.",
  },
  {
    slug: "panaderias",
    label: "Panaderías",
    posesivo: "tu panadería",
    eyebrow: "Para panaderías",
    heroTitle: "Producción de madrugada, caja sin fila",
    heroDescription:
      "La receta descuenta harina y calcula el costo de cada horneada. En el mostrador se vende por unidad o al peso, rápido, con la caja rindiendo por turno.",
  },
  {
    slug: "tiendas-de-ropa",
    label: "Tiendas de ropa",
    posesivo: "tu tienda",
    eyebrow: "Para tiendas de ropa",
    heroTitle: "Talles, colores y temporadas en orden",
    heroDescription:
      "Variantes por talle y color sin duplicar artículos, cambios y devoluciones con nota de crédito, y el reporte de qué se mueve antes de recomprar la temporada.",
  },
]

export function getRubro(slug: string): Rubro | undefined {
  return RUBROS.find((r) => r.slug === slug)
}
