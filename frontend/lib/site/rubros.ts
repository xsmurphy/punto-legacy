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

/** Familia comercial a la que pertenece el rubro. */
export type RubroGrupo = "gastronomia" | "retail" | "salud-y-belleza"

export const RUBRO_GRUPOS: { key: RubroGrupo; label: string }[] = [
  { key: "gastronomia", label: "Gastronomía" },
  { key: "retail", label: "Retail" },
  { key: "salud-y-belleza", label: "Salud y belleza" },
]

export type Rubro = {
  slug: string
  grupo: RubroGrupo
  /** Nombre para listas ("Restaurantes"). */
  label: string
  /** Con artículo, para frases ("tu restaurante"). */
  posesivo: string
  eyebrow: string
  heroTitle: string
  heroDescription: string
  /** Foto de fondo del hero (public/site/...). Sin ella, el hero va claro. */
  heroImage?: string
  thirtySeconds?: string[]
  sections?: RubroSection[]
}

export const RUBROS: Rubro[] = [
  {
    slug: "restaurantes",
    grupo: "gastronomia",
    label: "Restaurantes",
    posesivo: "tu restaurante",
    eyebrow: "Para restaurantes",
    heroImage: "/site/rubro-restaurantes.jpg",
    heroTitle: "El salón, la cocina y la caja, en sintonía",
    heroDescription:
      "El pedido llega directo a cocina, cada mesa muestra su cuenta abierta y la factura sale al cerrar, con mitades y agregados escritos tal como los pidió el cliente. Sin cuaderno y sin gritos al pasaplatos.",
    thirtySeconds: [
      "El pedido de cada mesa entra a la cocina en el momento, con agregados y aclaraciones literales.",
      "La cuenta se divide en partes iguales o por lo que consumió cada uno, desde la misma pantalla.",
      "La factura electrónica sale al cerrar la mesa y se envía sola.",
      "Si se corta internet, la caja sigue emitiendo: al volver la conexión todo se sincroniza.",
    ],
    sections: [
      {
        kicker: "Sin gritos al pasaplatos",
        title: "El pedido llega a cocina tal como se pidió",
        paragraphs: [
          "En hora pico el salón no tiene tiempo que perder: el mozo carga el pedido en la mesa y la comanda aparece en cocina con su hora de entrada, sus agregados y sus aclaraciones, sin pasar por un papel que se pierde entre la barra y la plancha.",
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
              sub: ["1× Lomito completo · sin cebolla", "1× Costilla al horno"],
            },
            {
              left: "Mesa 3 · hace 1 min",
              right: "1 plato",
              sub: ["1× Pizza cuatro quesos · sin aceitunas"],
            },
          ],
        },
      },
      {
        kicker: "La cuenta sin drama",
        title: "Dividir, cobrar y facturar en el mismo paso",
        paragraphs: [
          "Al final de la comida cada uno sabe cuánto le toca: en partes iguales o por lo que pidió. La mesa se cobra en efectivo, QR o tarjeta — o mezclado — y la factura electrónica sale en ese mismo toque, con el {docFiscal} que el cliente diga.",
          "Nada de reconstruir la mesa desde tres papeles: la cuenta vivió en el sistema desde el primer pedido.",
        ],
        linkLabel: "Ver el cobro de una mesa",
        mockup: {
          label: "Mesa 12 · 3 personas",
          title: "Dividir la cuenta",
          rows: [
            { left: "Gladys", right: "{money:75000}" },
            { left: "Osvaldo", right: "{money:80000}" },
            { left: "Rocío", right: "{money:60000}" },
          ],
          footer: { left: "Total", right: "{money:215000}" },
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
            { left: "Apertura", right: "{money:500000}" },
            { left: "Ventas en efectivo", right: "{money:2140000}" },
            { left: "Esperado", right: "{money:2640000}" },
          ],
          footer: { left: "Contado", right: "{money:2640000}" },
        },
      },
    ],
  },
  {
    slug: "minimarkets",
    grupo: "retail",
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
          "En hora pico el mostrador se mide en segundos por cliente. El ticket se arma escaneando, el total se hace solo y el cobro acepta efectivo, QR o tarjeta sin cambiar de pantalla. Si el cliente pide factura, sale con su {docFiscal} en el mismo paso.",
          "El teclado alcanza para todo el flujo — la caja de alto volumen no depende del mouse ni de menús escondidos.",
        ],
        linkLabel: "Ver la caja rápida",
        mockup: {
          label: "Caja 1",
          title: "Ticket en curso",
          rows: [
            { left: "2× Gaseosa 2L", right: "{money:30000}" },
            { left: "1× Pan lactal", right: "{money:18000}" },
            { left: "3× Yogur bebible", right: "{money:21000}" },
          ],
          footer: { left: "Cobrar", right: "{money:69000}" },
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
            { left: "Esperado", right: "{money:3480000}" },
            { left: "Contado", right: "{money:3465000}" },
            { left: "Diferencia", right: "{money:-15000}" },
          ],
        },
      },
    ],
  },
  {
    slug: "farmacias",
    grupo: "retail",
    label: "Farmacias",
    posesivo: "tu farmacia",
    eyebrow: "Para farmacias",
    heroTitle: "Vender con receta, vencimiento y crédito bajo control",
    heroDescription:
      "El mostrador cobra rápido, el stock vigila vencimientos y cada cliente con cuenta corriente tiene su límite y su historia. La factura electrónica sale en el mismo paso, ya lista para el organismo fiscal.",
    thirtySeconds: [
      "Búsqueda por nombre, droga o código de barras, con el precio de cada lista.",
      "El lote y el vencimiento se controlan al vender, no al descubrir la caja vencida.",
      "La cuenta corriente del cliente lleva límite, saldo y recibos de cada pago.",
      "La factura electrónica del cliente sale del mismo ticket, sin trámite aparte.",
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
            {
              left: "Ibuprofeno 400mg × 10",
              right: "{money:15000}",
              sub: ["Centro: 24 · Villa Morra: 8"],
            },
            {
              left: "Ibuprofeno 600mg × 10",
              right: "{money:22000}",
              sub: ["Centro: 11 · Villa Morra: 0"],
            },
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
            {
              left: "Amoxicilina susp.",
              right: "12 días",
              sub: ["Lote A-1042 · 6 unidades"],
            },
            {
              left: "Vitamina C 500",
              right: "28 días",
              sub: ["Lote C-2210 · 14 unidades"],
            },
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
            {
              left: "Elvira Ruiz",
              right: "{money:180000}",
              sub: ["límite {money:500000}"],
            },
            {
              left: "Ramón Ortiz",
              right: "{money:65000}",
              sub: ["último pago hace 8 días"],
            },
          ],
        },
      },
    ],
  },
  {
    slug: "ferreterias",
    grupo: "retail",
    label: "Ferreterías",
    posesivo: "tu ferretería",
    eyebrow: "Para ferreterías",
    heroTitle: "Miles de artículos, un mostrador que no duda",
    heroDescription:
      "Buscar entre miles de códigos, vender fraccionado, cotizar obras y llevar cuenta corriente de los clientes de siempre. El stock por depósito y los precios por lista, sin planillas paralelas.",
    thirtySeconds: [
      "El buscador encuentra el código entre miles de artículos con una marca, una medida o el nombre a medias.",
      "Los tornillos, caños y todo lo que se vende suelto se cobra por unidad, metro o kilo, no por bulto cerrado.",
      "La cotización para una obra se arma en minutos y se convierte en venta sin cargar todo de nuevo.",
      "El cliente de cuenta corriente compra fiado dentro de su límite y paga cuando cobra la obra.",
    ],
    sections: [
      {
        kicker: "Miles de códigos, un solo buscador",
        title: "El mostrador encuentra el artículo al primer intento",
        paragraphs: [
          'El cliente pide "un caño de media" o "el tornillo autoperforante de una pulgada" y el buscador responde por nombre, medida o código, sin que el vendedor tenga que memorizar dónde está cada cosa entre miles de artículos.',
          "Si en el depósito de esta sucursal no queda, se ve al toque dónde sí hay stock, antes de mandar al cliente a buscar en otro lado.",
        ],
        linkLabel: "Ver el buscador de artículos",
        mockup: {
          label: "Mostrador",
          title: "Buscar: caño PVC",
          rows: [
            {
              left: 'Caño PVC 1/2" × 6m',
              right: "{money:38000}",
              sub: ["Depósito central: 42 · Sucursal Ñemby: 6"],
            },
            {
              left: 'Codo PVC 1/2"',
              right: "{money:3500}",
              sub: ["Depósito central: 210"],
            },
          ],
        },
      },
      {
        kicker: "Se vende suelto",
        title: "Fraccionado, por metro o por kilo, sin perder margen",
        paragraphs: [
          "No todo se vende en su envase cerrado: el tornillo se cobra por unidad, el caño por metro y el cemento a veces por bolsa partida. Cada artículo tiene su unidad de venta real, y el margen se calcula sobre eso, no sobre el bulto entero.",
          "La cotización para una obra junta materiales de rubros distintos — caños, cables, cemento — y cuando el cliente confirma, se convierte en venta con un solo toque, sin recargar cada ítem de nuevo.",
        ],
        linkLabel: "Ver una cotización de obra",
        mockup: {
          label: "Cotización #084",
          title: "Obra Sosa · baño",
          rows: [
            { left: '18× Caño PVC 1/2" (m)', right: "{money:216000}" },
            { left: "2× Bolsa cemento 50kg", right: "{money:130000}" },
            { left: "1× Kit grifería", right: "{money:380000}" },
          ],
          footer: { left: "Total cotizado", right: "{money:726000}" },
        },
      },
      {
        kicker: "El cliente de siempre",
        title: "Cuenta corriente y stock por depósito, sin planillas paralelas",
        paragraphs: [
          "El maestro de obra o el cliente frecuente compra fiado dentro de un límite, y cada pago queda registrado con su recibo — la cobranza del mes sale de un listado, no de una libreta atrás del mostrador.",
          "El stock se lleva por depósito: lo que hay en el local no es lo mismo que lo que hay en el galpón de atrás, y cada venta descuenta del lugar correcto.",
        ],
        linkLabel: "Ver cuentas corrientes",
        mockup: {
          label: "Cuentas corrientes",
          title: "Saldos al día",
          rows: [
            {
              left: "Construcciones Ayala",
              right: "{money:1240000}",
              sub: ["límite {money:3000000}"],
            },
            {
              left: "Don Feliciano",
              right: "{money:95000}",
              sub: ["último pago hace 12 días"],
            },
          ],
        },
      },
    ],
  },
  {
    slug: "ropa-y-accesorios",
    grupo: "retail",
    label: "Ropa y accesorios",
    posesivo: "tu tienda",
    eyebrow: "Para tiendas de ropa y accesorios",
    heroTitle: "Talles, colores y temporadas en orden",
    heroDescription:
      "Variantes por talle y color sin duplicar artículos, cambios y devoluciones con nota de crédito, y el reporte de qué se mueve antes de recomprar la temporada.",
    thirtySeconds: [
      "Cada talle y color del mismo modelo es una variante, no un artículo nuevo que duplicar.",
      "Un cambio o una devolución se resuelve con nota de crédito, sin romper la caja del día.",
      "El reporte de lo más vendido dice qué reponer antes de recomprar la temporada.",
      "El mostrador cobra a un precio y el mayorista a otro, desde la misma lista de precios.",
    ],
    sections: [
      {
        kicker: "Un modelo, todas sus variantes",
        title: "Talle y color sin multiplicar artículos",
        paragraphs: [
          'El vestido "floreado corto" es un solo artículo con sus variantes de talle y color: buscarlo en el mostrador muestra de una el stock de cada combinación, sin tener que adivinar entre veinte códigos parecidos.',
          "Cuando un talle se agota, se ve al instante — y el vendedor puede ofrecer el color que sí queda antes de perder la venta.",
        ],
        linkLabel: "Ver las variantes de un modelo",
        mockup: {
          label: "Mostrador",
          title: "Vestido floreado corto",
          rows: [
            { left: "Talle S · Celeste", right: "quedan 3" },
            { left: "Talle M · Celeste", right: "quedan 0", sub: ["agotado"] },
            { left: "Talle M · Terracota", right: "quedan 5" },
          ],
        },
      },
      {
        kicker: "El cambio no rompe la caja",
        title: "Cambios y devoluciones con nota de crédito",
        paragraphs: [
          "Cuando la clienta vuelve con la prenda porque no le entró el talle, el cambio se resuelve con nota de crédito: la prenda vuelve al stock y el saldo queda a favor para la próxima compra, sin que el cierre de caja del día quede descuadrado.",
        ],
        linkLabel: "Ver una nota de crédito",
        mockup: {
          label: "Nota de crédito NC-0231",
          title: "Cambio de talle",
          rows: [
            { left: "Devuelve: Blusa lino Talle M", right: "{money:145000}" },
            { left: "Lleva: Blusa lino Talle S", right: "{money:145000}" },
          ],
          footer: { left: "Saldo a favor", right: "{money:0}" },
        },
      },
      {
        kicker: "Antes de recomprar",
        title: "Qué se movió y a qué precio venderlo",
        paragraphs: [
          "El reporte de ventas por temporada dice qué modelos y talles se movieron y cuáles quedaron colgados, para no recomprar de nuevo lo que no salió. Y el mostrador vende a un precio mientras el cliente mayorista compra a otro, desde la misma lista de precios sin duplicar catálogo.",
        ],
        linkLabel: "Ver lo más vendido de la temporada",
        mockup: {
          label: "Temporada invierno",
          title: "Más vendido",
          rows: [
            { left: "Campera de jean", right: "42 unidades" },
            { left: "Sweater oversize", right: "37 unidades" },
            {
              left: "Pantalón cargo",
              right: "9 unidades",
              sub: ["quedó stock"],
            },
          ],
        },
      },
    ],
  },
]

export function getRubro(slug: string): Rubro | undefined {
  return RUBROS.find((r) => r.slug === slug)
}
