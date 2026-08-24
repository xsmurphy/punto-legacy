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
  /** Link-flecha al final del texto. Sin `linkHref` no se dibuja. */
  linkLabel: string
  /** Módulo del que habla la sección. */
  linkHref?: string
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
  /**
   * Aparece en el menú principal. Se destacan CUATRO por grupo para que las
   * columnas queden parejas; el resto vive en el pie y en el bloque de
   * "otros rubros" de cada página.
   */
  destacado?: boolean
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
    destacado: true,
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
        linkHref: "/modulos/pantalla-de-cocina",
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
        linkHref: "/modulos/mesas-y-ordenes",
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
        linkHref: "/modulos/punto-de-venta",
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
    destacado: true,
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
        linkHref: "/modulos/punto-de-venta",
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
        linkHref: "/modulos/stock-y-compras",
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
        linkHref: "/modulos/punto-de-venta",
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
    destacado: true,
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
        linkHref: "/modulos/punto-de-venta",
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
        linkHref: "/modulos/stock-y-compras",
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
        linkHref: "/modulos/clientes-y-credito",
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
        linkHref: "/modulos/punto-de-venta",
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
        linkHref: "/modulos/punto-de-venta",
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
    destacado: true,
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
        linkHref: "/modulos/punto-de-venta",
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
        linkHref: "/modulos/clientes-y-credito",
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
        linkHref: "/modulos/punto-de-venta",
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
  {
    slug: "bares-y-cafes",
    destacado: true,
    grupo: "gastronomia",
    label: "Bares y cafés",
    posesivo: "tu bar",
    eyebrow: "Para bares y cafés",
    heroTitle: "La barra no para y la cuenta no se pierde",
    heroDescription:
      "Sirve para bares, cafeterías, heladerías, panaderías y confiterías: la cuenta queda abierta por mesa o por cliente, los agregados bajan claros a la barra o al mostrador, y la noche fuerte cierra en un arqueo que no deja dudas.",
    thirtySeconds: [
      "La cuenta se abre por mesa o por cliente y queda ahí hasta que alguien pide cerrarla.",
      "Un café con leche de almendra, un helado con dos toppings o una docena mixta de facturas: el agregado baja claro a la barra o al mostrador.",
      "Vender por peso, por unidad o por docena — el helado al kilo, la factura por unidad — desde la misma pantalla.",
      "El gift card se vende, se carga y se descuenta como un medio de pago más.",
    ],
    sections: [
      {
        kicker: "La barra en hora pico",
        title: "Cada mesa con su cuenta, cada pedido en orden",
        paragraphs: [
          "El sábado a la noche la barra recibe pedidos de diez mesas a la vez, más los que piden parado. Cada mesa tiene su cuenta abierta desde el primer pedido, y sumar un café más o una porción de torta no obliga a recontar toda la mesa desde cero.",
          "Lo mismo para el cliente que se sienta solo en la barra: su cuenta se abre a su nombre y se cobra cuando él lo pide, sin mezclarla con la mesa de al lado.",
        ],
        linkLabel: "Ver la barra en hora pico",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Sábado 22:15",
          title: "Mesa 6 · cuenta abierta",
          caption: "Abierta hace 40 minutos.",
          rows: [
            { left: "2× Café con leche", right: "{money:24000}" },
            { left: "1× Torta de chocolate", right: "{money:18000}" },
            { left: "1× Helado 1/4 kg · 2 gustos", right: "{money:22000}" },
          ],
          footer: { left: "Acumulado", right: "{money:64000}" },
        },
      },
      {
        kicker: "El agregado que cambia todo",
        title: "Leche, toppings o docena: el pedido baja claro",
        paragraphs: [
          "En la cafetería el agregado es la leche o el shot de más; en la heladería, el segundo topping o la crema; en la panadería, si la docena es surtida o de un solo tipo. Cada rubro tiene su propio agregado y Punto lo deja elegir sin inventar un artículo nuevo por cada combinación.",
          "El mostrador vende por unidad, por kilo o por docena según el producto — la facturería no se pesa, el helado sí, y el sistema sabe la diferencia sin que el vendedor tenga que acordarse.",
        ],
        linkLabel: "Ver los agregados por producto",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Mostrador",
          title: "Helado · 1/2 kg",
          rows: [
            { left: "Gusto 1: Dulce de leche", right: "" },
            { left: "Gusto 2: Chocolate amargo", right: "" },
            { left: "Topping: Chips de chocolate", right: "{money:5000}" },
          ],
          footer: { left: "Total", right: "{money:35000}" },
        },
      },
      {
        kicker: "La noche fuerte cierra en números",
        title: "Dividir la cuenta, cobrar con gift card, arquear al final",
        paragraphs: [
          "Cuando el grupo pide dividir la cuenta, cada uno paga lo suyo desde la misma pantalla — en efectivo, QR, tarjeta o con un gift card que ya tiene cargado. El gift card se vende como cualquier producto y se descuenta solo cuando el cliente lo usa.",
          "Al cerrar la noche, el arqueo compara lo esperado contra lo contado sin depender de que alguien se acuerde de cada movimiento. La noche más fuerte del mes queda tan clara como cualquier martes tranquilo.",
        ],
        linkLabel: "Ver el arqueo de la noche",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Cierre 01:30",
          title: "Arqueo de caja",
          rows: [
            { left: "Apertura", right: "{money:400000}" },
            { left: "Ventas totales", right: "{money:3180000}" },
            { left: "Gift cards vendidos", right: "{money:250000}" },
          ],
          footer: { left: "Esperado en caja", right: "{money:2850000}" },
        },
      },
    ],
  },
  {
    slug: "comida-rapida",
    destacado: true,
    grupo: "gastronomia",
    label: "Comida rápida",
    posesivo: "tu local",
    eyebrow: "Para comida rápida",
    heroTitle: "El mostrador no frena y la comanda llega clara",
    heroDescription:
      "El pedido se arma en segundos con sus combos y agregados, la pantalla de cocina lo reparte por estación y cada cliente sabe si es para el salón o para llevar. El pico de la noche no descontrola nada.",
    thirtySeconds: [
      "El combo se arma con un toque y el agregado — doble carne, sin cebolla, extra queso — baja literal a la plancha.",
      "La pantalla de cocina separa el pedido por estación: plancha, freidora, armado.",
      "Cada pedido queda marcado como para el salón o para llevar, sin confundirse en el mostrador.",
      "En el pico de la noche el mostrador sigue cobrando al mismo ritmo, con el teclado alcanzando para todo.",
    ],
    sections: [
      {
        kicker: "El mostrador no puede frenar",
        title: "Combos y agregados que bajan claros a la plancha",
        paragraphs: [
          "El cliente pide una hamburguesa doble, sin cebolla, con papas grandes y una gaseosa — todo en un combo armado con un toque. El pedido baja a la plancha exactamente así, sin que el cocinero tenga que adivinar qué significa 'la de siempre pero distinta'.",
          "Cada agregado tiene su precio propio, así que el combo se cobra completo sin que el cajero tenga que sumar a mano lo que cambia respecto al de la carta.",
        ],
        linkLabel: "Ver un combo armado",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Mostrador",
          title: "Combo doble",
          rows: [
            { left: "1× Hamburguesa doble · sin cebolla", right: "" },
            { left: "1× Papas grandes", right: "" },
            { left: "1× Gaseosa 500ml", right: "" },
          ],
          footer: { left: "Total combo", right: "{money:42000}" },
        },
      },
      {
        kicker: "Cada estación con lo suyo",
        title: "La pantalla de cocina reparte el pedido, no lo amontona",
        paragraphs: [
          "En vez de un solo papel con todo mezclado, la pantalla de cocina muestra a la plancha lo que le toca a la plancha y a la freidora lo que le toca a la freidora. Cada estación ve su parte del pedido y lo marca listo cuando termina.",
          "El pedido completo se arma solo cuando todas las estaciones terminaron la suya — así nada sale a medias ni se enfría esperando la papa.",
        ],
        linkLabel: "Ver la pantalla de cocina",
        linkHref: "/modulos/pantalla-de-cocina",
        mockup: {
          label: "Cocina · plancha",
          title: "Pedido #114",
          rows: [
            { left: "1× Hamburguesa doble · sin cebolla", right: "en curso" },
            { left: "1× Lomito completo", right: "pendiente" },
          ],
        },
      },
      {
        kicker: "Salón o para llevar",
        title: "El pico de la noche sin perder el hilo",
        paragraphs: [
          "Cada pedido queda marcado desde que se toma: para el salón, con su número de mesa, o para llevar, con el nombre de quien lo espera. En el pico de la noche eso evita que un pedido para llevar se sirva en una bandeja o que uno de salón se quede armado en el mostrador.",
          "El cierre del turno junta todo lo cobrado — salón y para llevar — en un solo arqueo, sin planillas separadas por tipo de pedido.",
        ],
        linkLabel: "Ver el pico de la noche",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Viernes 21:00",
          title: "Pedidos en curso",
          rows: [
            { left: "Mesa 4", right: "en cocina" },
            { left: "Para llevar · Iván", right: "listo" },
            { left: "Para llevar · Noelia", right: "en cocina" },
          ],
        },
      },
    ],
  },
  {
    slug: "dark-kitchen",
    destacado: true,
    grupo: "gastronomia",
    label: "Dark kitchen",
    posesivo: "tu cocina",
    eyebrow: "Para dark kitchens",
    heroTitle: "Una cocina, varias marcas, un solo control",
    heroDescription:
      "Sin salón que atender, todo pasa por la comanda: varias marcas operando desde la misma cocina, cada pedido por estación, el tiempo de preparación medido y el costo de cada plato bajo control.",
    thirtySeconds: [
      "Cada marca que opera desde la cocina tiene su propio menú y su propia numeración, aunque compartan el mismo espacio.",
      "La comanda llega por estación: armado, plancha, frituras, cada una ve solo lo suyo.",
      "El tiempo entre que entra el pedido y sale listo queda medido, plato por plato.",
      "La receta de cada plato fija el costo real, y el margen se ve sin recalcular a mano.",
    ],
    sections: [
      {
        kicker: "Varias marcas, una sola cocina",
        title: "Cada marca con su menú, todas con la misma comanda",
        paragraphs: [
          "Una cocina puede operar dos o tres marcas distintas al mismo tiempo — cada una con su propio menú, sus propios precios y su propia numeración de pedidos — sin que eso signifique manejar tres sistemas separados.",
          "El pedido entra identificado con su marca desde el primer momento, así la cocina sabe para cuál de las tres está armando cada plato.",
        ],
        linkLabel: "Ver el pedido por marca",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Cocina central",
          title: "Pedidos en curso",
          rows: [
            { left: "Pedido #041 · Marca Wok Go", right: "en cocina" },
            { left: "Pedido #042 · Marca Burger Lab", right: "armado" },
            { left: "Pedido #043 · Marca Wok Go", right: "en cocina" },
          ],
        },
      },
      {
        kicker: "Sin salón, con orden",
        title: "La comanda por estación, sin amontonar",
        paragraphs: [
          "Sin mozos ni mesas, todo el ritmo de la cocina depende de la comanda: cada estación — armado, plancha, frituras — ve solo los pasos que le tocan, y el pedido completo se arma cuando todas terminaron.",
          "El tiempo entre que el pedido entra y sale listo queda registrado por plato, así se ve qué preparación se está atrasando antes de que se acumulen diez pedidos esperando lo mismo.",
        ],
        linkLabel: "Ver el tiempo de preparación",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Últimos 30 minutos",
          title: "Tiempo de preparación",
          rows: [
            { left: "Wok de pollo", right: "6 min prom." },
            { left: "Burger doble", right: "9 min prom." },
            { left: "Papas fritas", right: "4 min prom." },
          ],
        },
      },
      {
        kicker: "El costo, plato por plato",
        title: "La receta dice cuánto cuesta cada plato",
        paragraphs: [
          "Cada plato tiene su receta cargada con los insumos exactos que lleva, y el costo se recalcula solo cuando cambia el precio de un ingrediente. Así el margen de cada plato de cada marca se ve al toque, sin planilla aparte.",
          "El reporte del día junta lo que vendió cada marca y a qué costo, para saber cuál plato conviene empujar y cuál está perdiendo margen sin que nadie lo note.",
        ],
        linkLabel: "Ver el costo por plato",
        linkHref: "/modulos/produccion-y-recetas",
        mockup: {
          label: "Marca Wok Go",
          title: "Costo · Wok de pollo",
          rows: [
            { left: "Precio de venta", right: "{money:38000}" },
            { left: "Costo de insumos", right: "{money:14200}" },
          ],
          footer: { left: "Margen", right: "{money:23800}" },
        },
      },
    ],
  },
  {
    slug: "decoracion-y-hogar",
    destacado: true,
    grupo: "retail",
    label: "Decoración y hogar",
    posesivo: "tu tienda",
    eyebrow: "Para decoración y hogar",
    heroTitle: "Del catálogo a la entrega, sin perder el hilo",
    heroDescription:
      "Un catálogo grande con fotos, variantes de color y medida, cotizaciones para amueblar un ambiente entero y entregas que se pactan para más adelante con una seña. El stock se lleva por depósito, no de memoria.",
    thirtySeconds: [
      "Cada artículo puede tener foto, y sus variantes de color o medida se buscan sin duplicar el catálogo.",
      "Los artículos de bajo movimiento y alto valor — un sillón, una mesa de diseño — se controlan igual que los de rotación diaria.",
      "Una cotización para amueblar un ambiente se arma en un documento y se convierte en venta cuando el cliente confirma.",
      "La entrega diferida se pacta con seña, y el saldo se cobra el día que el mueble sale del depósito.",
    ],
    sections: [
      {
        kicker: "El catálogo se ve, no se adivina",
        title: "Fotos, variantes y stock por depósito",
        paragraphs: [
          "Un sillón de tres cuerpos en tres colores no son tres artículos distintos: es un modelo con sus variantes, cada una con su foto y su stock propio. El vendedor busca el modelo y muestra al cliente lo que hay en cada color sin ir hasta el depósito a confirmar.",
          "El stock se lleva por depósito — lo que está en el local de exhibición no es lo mismo que lo que espera en el galpón — y cada venta descuenta del lugar correcto.",
        ],
        linkLabel: "Ver el catálogo con variantes",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Catálogo",
          title: "Sillón Milán 3 cuerpos",
          rows: [
            { left: "Color Gris", right: "quedan 2", sub: ["exhibición"] },
            { left: "Color Beige", right: "quedan 5", sub: ["depósito"] },
            { left: "Color Verde", right: "quedan 0", sub: ["agotado"] },
          ],
        },
      },
      {
        kicker: "Lo que se vende poco pero vale mucho",
        title: "Alto valor, bajo movimiento, mismo control",
        paragraphs: [
          "Una lámpara de diseño o una mesa importada no se venden todos los días, pero cuando se venden el margen importa. Cada unidad queda identificada, así no hay que confiar en la memoria de quién la vio pasar por el depósito la semana pasada.",
          "El reporte de rotación separa lo que se mueve rápido de lo que espera meses, para no recomprar por reflejo lo que ya sobra en el depósito.",
        ],
        linkLabel: "Ver la rotación por artículo",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Este trimestre",
          title: "Rotación de stock",
          rows: [
            { left: "Set de vasos 6u", right: "48 unidades" },
            { left: "Mesa ratona roble", right: "3 unidades" },
            {
              left: "Espejo redondo XL",
              right: "1 unidad",
              sub: ["hace 4 meses"],
            },
          ],
        },
      },
      {
        kicker: "El proyecto entero, en un documento",
        title: "Cotizar el ambiente, entregar cuando esté listo",
        paragraphs: [
          "Cuando el cliente quiere amueblar un living completo, la cotización junta sillón, mesa, lámpara y alfombra en un solo documento con un total — y si el cliente confirma, se convierte en venta sin cargar todo de nuevo.",
          "Si la entrega es para dentro de tres semanas porque el mueble llega de otra sucursal, se cobra una seña ahora y el saldo el día que sale del depósito, con la fecha de entrega pactada quedando escrita, no prometida de palabra.",
        ],
        linkLabel: "Ver una cotización con entrega diferida",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Cotización #212",
          title: "Living completo",
          rows: [
            { left: "1× Sillón Milán · Beige", right: "{money:2400000}" },
            { left: "1× Mesa ratona roble", right: "{money:680000}" },
            { left: "Seña recibida", right: "{money:1000000}" },
          ],
          footer: { left: "Saldo a la entrega", right: "{money:2080000}" },
        },
      },
    ],
  },
  {
    slug: "barberias",
    destacado: true,
    grupo: "salud-y-belleza",
    label: "Barberías",
    posesivo: "tu barbería",
    eyebrow: "Para barberías",
    heroTitle: "Turnos cortos, sillones llenos, nadie esperando de más",
    heroDescription:
      "La agenda encadena los turnos de cada barbero sin espacios muertos, el cliente recibe su recordatorio antes de venir y el cobro se hace en el mismo turno, con lo que compró de producto sumado al servicio.",
    thirtySeconds: [
      "La agenda muestra los sillones en simultáneo, con cada turno encadenado al siguiente sin espacios muertos.",
      "El cliente recibe la confirmación y el recordatorio del turno antes de venir, sin llamada de por medio.",
      "Cada turno pasa por sus estados — confirmado, atendido, ausente — así se ve quién no vino sin revisar la agenda entera.",
      "El corte se cobra en el momento, con la cera o el aceite que el cliente se lleva sumado al mismo ticket.",
    ],
    sections: [
      {
        kicker: "El sillón no puede quedar vacío",
        title: "Turnos encadenados, sillón por sillón",
        paragraphs: [
          "Un corte dura veinte minutos y el siguiente cliente ya está sentado esperando: la agenda muestra los sillones en simultáneo, uno por barbero, y cada turno nuevo se encadena al anterior sin dejar huecos que nadie llena.",
          "Si un barbero atiende corte y barba en el mismo turno, el tiempo se ajusta solo — la agenda no trata todos los servicios como si duraran lo mismo.",
        ],
        linkLabel: "Ver la agenda de sillones",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Sábado · mañana",
          title: "Agenda por sillón",
          rows: [
            {
              left: "Sillón 1 · Braulio",
              right: "09:00 – 09:20",
              sub: ["Corte clásico"],
            },
            {
              left: "Sillón 2 · Nilo",
              right: "09:00 – 09:35",
              sub: ["Corte + barba"],
            },
            {
              left: "Sillón 1 · Braulio",
              right: "09:20 – 09:40",
              sub: ["Corte clásico"],
            },
          ],
        },
      },
      {
        kicker: "El cliente no se olvida",
        title: "Confirmación y recordatorio, sin llamar a nadie",
        paragraphs: [
          "Apenas se agenda el turno, el cliente recibe la confirmación; el día anterior, el recordatorio. Nadie de la barbería tiene que llamar uno por uno para asegurarse de que se acuerden.",
          "Cada turno queda marcado como confirmado, atendido o ausente, así al final del día se ve de un vistazo cuántos turnos se perdieron y de quién — sin repasar la agenda completa buscando huecos.",
        ],
        linkLabel: "Ver los estados del turno",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Hoy",
          title: "Turnos del día",
          rows: [
            { left: "10:00 · Marcelo Duarte", right: "confirmado" },
            { left: "10:20 · Hugo Benítez", right: "atendido" },
            { left: "10:40 · Ariel Cabrera", right: "ausente" },
          ],
        },
      },
      {
        kicker: "El corte y el producto, un solo cobro",
        title: "Cobrar desde el turno, con lo que se lleva sumado",
        paragraphs: [
          "Cuando el cliente termina, el cobro se hace desde el mismo turno: el corte, la barba y la cera que se lleva quedan en un solo ticket, sin pasar por una caja aparte a recalcular todo.",
          "La ficha del cliente guarda su historial de cortes, así el próximo barbero que lo atienda sabe qué le hicieron la última vez sin tener que preguntar.",
        ],
        linkLabel: "Ver el cobro desde el turno",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Turno 10:20 · Hugo Benítez",
          title: "Cobrar turno",
          rows: [
            { left: "Corte + barba", right: "{money:65000}" },
            { left: "1× Cera modeladora", right: "{money:35000}" },
          ],
          footer: { left: "Total", right: "{money:100000}" },
        },
      },
    ],
  },
  {
    slug: "peluquerias",
    destacado: true,
    grupo: "salud-y-belleza",
    label: "Peluquerías",
    posesivo: "tu peluquería",
    eyebrow: "Para peluquerías",
    heroTitle: "Cada servicio con su tiempo real, cada clienta con su historia",
    heroDescription:
      "Un corte no dura lo mismo que una coloración: la agenda respeta el tiempo real de cada servicio, la ficha de la clienta guarda qué tono se usó la última vez, y el producto que se lleva se suma al cobro del día.",
    thirtySeconds: [
      "La agenda respeta el tiempo real de cada servicio: un corte no ocupa lo mismo que una coloración o un tratamiento.",
      "La confirmación y el recordatorio llegan solos antes del turno, sin que nadie tenga que llamar.",
      "La ficha de la clienta guarda el color, la marca y el tono usados la última vez.",
      "El shampoo o la crema que se lleva la clienta se cobra en el mismo ticket que el servicio.",
    ],
    sections: [
      {
        kicker: "Ningún servicio dura lo mismo",
        title: "La agenda respeta el tiempo real de cada uno",
        paragraphs: [
          "Un corte lleva media hora, una coloración con tiempo de pausa lleva dos horas, y un tratamiento capilar otra cosa distinta. La agenda arma cada turno con la duración real del servicio elegido, así no se agenda un color en el mismo espacio que un corte.",
          "Cuando la coloración necesita tiempo de pausa, ese hueco queda reservado en la agenda del box, sin que otra clienta se agende encima sin querer.",
        ],
        linkLabel: "Ver la agenda por servicio",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Miércoles · tarde",
          title: "Agenda · Box 2",
          rows: [
            {
              left: "14:00 – 16:00",
              right: "Coloración completa",
              sub: ["Marisol Ayala"],
            },
            {
              left: "16:00 – 16:40",
              right: "Corte y peinado",
              sub: ["Yolanda Insfrán"],
            },
          ],
        },
      },
      {
        kicker: "El tono de la última vez",
        title: "La ficha de la clienta no se olvida de nada",
        paragraphs: [
          "Cada clienta tiene su ficha con el historial de servicios: qué tono de color se usó, qué marca, qué tratamiento pidió la última vez. La próxima visita empieza sabiendo eso, no reinventando la fórmula a ojo.",
          "La confirmación del turno y el recordatorio del día anterior salen solos, así la clienta no se olvida y el box no queda vacío por una cita que nadie confirmó.",
        ],
        linkLabel: "Ver la ficha de la clienta",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Ficha · Marisol Ayala",
          title: "Historial de servicios",
          rows: [
            { left: "Coloración · rubio ceniza", right: "hace 6 semanas" },
            { left: "Corte + brushing", right: "hace 3 semanas" },
          ],
        },
      },
      {
        kicker: "El servicio y el producto, juntos",
        title: "Cobrar el turno con lo que la clienta se lleva",
        paragraphs: [
          "Al terminar el servicio, el cobro junta la coloración, el brushing y el shampoo que la clienta compra para su casa en un solo ticket, sin pasar por una caja aparte para el producto de reventa.",
          "Si la clienta prefiere un paquete de sesiones de tratamiento capilar, se vende una vez y se descuenta sesión por sesión en cada visita, sin volver a cobrar cada vez.",
        ],
        linkLabel: "Ver el cobro con producto",
        linkHref: "/modulos/gift-cards",
        mockup: {
          label: "Turno 16:00 · Marisol Ayala",
          title: "Cobrar turno",
          rows: [
            { left: "Coloración completa", right: "{money:280000}" },
            { left: "1× Shampoo reparador", right: "{money:65000}" },
          ],
          footer: { left: "Total", right: "{money:345000}" },
        },
      },
    ],
  },
  {
    slug: "consultorios-medicos",
    destacado: true,
    grupo: "salud-y-belleza",
    label: "Consultorios médicos",
    posesivo: "tu consultorio",
    eyebrow: "Para consultorios médicos",
    heroTitle: "La agenda de cada profesional, la historia de cada paciente",
    heroDescription:
      "Cada profesional tiene su propia agenda, el paciente recibe recordatorio antes de la consulta y su ficha guarda el historial de visitas. La consulta se cobra como particular o como obra social, sin planilla aparte.",
    thirtySeconds: [
      "Cada profesional tiene su propia agenda, con su duración de consulta y sus días de atención.",
      "El paciente recibe confirmación y recordatorio del turno antes de venir.",
      "Cada turno queda marcado como confirmado, atendido o ausente, para no perder el rastro de quién faltó.",
      "La ficha del paciente guarda sus visitas anteriores, y el cobro distingue particular de obra social.",
    ],
    sections: [
      {
        kicker: "Un consultorio, varios profesionales",
        title: "Cada agenda con sus propios tiempos",
        paragraphs: [
          "Si el consultorio atiende con dos o tres profesionales, cada uno tiene su propia agenda: sus días, sus horarios y la duración real de su consulta, sin mezclar los turnos de uno con los del otro en una sola grilla confusa.",
          "La recepción ve todas las agendas juntas para coordinar la sala de espera, pero cada profesional gestiona la suya sin pisar la del compañero.",
        ],
        linkLabel: "Ver la agenda por profesional",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Martes · mañana",
          title: "Agenda del consultorio",
          rows: [
            {
              left: "09:00 · Dra. Servín",
              right: "Consulta clínica",
              sub: ["Rubén Acosta"],
            },
            {
              left: "09:30 · Dr. Bogado",
              right: "Control",
              sub: ["Liliana Ferreira"],
            },
          ],
        },
      },
      {
        kicker: "El paciente no repite su historia",
        title: "La ficha guarda cada consulta anterior",
        paragraphs: [
          "Cada paciente tiene su ficha con el historial de visitas: motivo de consulta, fecha y profesional que lo atendió. El médico llega a la consulta sabiendo qué pasó la última vez, sin que el paciente tenga que repetir todo desde cero.",
          "La confirmación del turno y el recordatorio del día anterior salen solos, y si el paciente falta, el turno queda marcado como ausente en vez de perderse en la agenda sin explicación.",
        ],
        linkLabel: "Ver la ficha del paciente",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Ficha · Rubén Acosta",
          title: "Historial de consultas",
          rows: [
            {
              left: "Consulta clínica",
              right: "hace 2 meses",
              sub: ["Dra. Servín"],
            },
            {
              left: "Control de presión",
              right: "hace 3 semanas",
              sub: ["Dra. Servín"],
            },
          ],
        },
      },
      {
        kicker: "Particular o con cobertura",
        title: "El cobro distingue quién paga qué",
        paragraphs: [
          "Al terminar la consulta, el cobro se hace desde el mismo turno: si el paciente paga particular, sale el {docFiscal} en el momento; si tiene obra social, la consulta queda registrada para la liquidación correspondiente sin mezclarse con las particulares del día.",
          "El reporte del día separa las consultas por profesional y por tipo de cobertura, para que cerrar el mes no dependa de repasar la agenda entera a mano.",
        ],
        linkLabel: "Ver el cobro de la consulta",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Turno 09:00 · Rubén Acosta",
          title: "Cobrar consulta",
          rows: [
            { left: "Consulta clínica", right: "{money:180000}" },
            { left: "Modalidad", right: "Particular" },
          ],
          footer: { left: "Total", right: "{money:180000}" },
        },
      },
    ],
  },
  {
    slug: "odontologia",
    grupo: "salud-y-belleza",
    label: "Odontología",
    posesivo: "tu consultorio",
    eyebrow: "Para consultorios odontológicos",
    heroTitle: "El tratamiento entero, sesión por sesión, bajo control",
    heroDescription:
      "Un tratamiento de varias sesiones se presupuesta una vez y se sigue sesión por sesión, la agenda de cada profesional se arma sola con recordatorio para el paciente, y su ficha guarda el plan completo, no solo la última visita.",
    thirtySeconds: [
      "Un tratamiento de varias sesiones se presupuesta entero, y cada sesión se descuenta de ese plan sin recotizar cada vez.",
      "La agenda del profesional muestra el turno con confirmación y recordatorio automático para el paciente.",
      "Cada turno pasa por sus estados — confirmado, atendido, ausente — para hacer seguimiento del plan sin perder sesiones en el camino.",
      "La ficha del paciente guarda el plan de tratamiento completo, con lo hecho y lo que falta.",
    ],
    sections: [
      {
        kicker: "El plan, no la sesión suelta",
        title: "Presupuestar el tratamiento entero, una sola vez",
        paragraphs: [
          "Un tratamiento de conducto o una ortodoncia no se resuelven en una visita: el presupuesto se arma por el plan completo, con sus sesiones estimadas, y el paciente sabe desde el principio cuánto va a costar todo, no solo la sesión de hoy.",
          "Cada sesión que se cumple se descuenta de ese plan, así nadie tiene que recordar a mano cuántas quedan pendientes ni recotizar cada vez que el paciente vuelve.",
        ],
        linkLabel: "Ver un presupuesto de tratamiento",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Presupuesto · Ortodoncia",
          title: "Plan de tratamiento",
          rows: [
            { left: "Sesión 1 de 8 · Colocación", right: "realizada" },
            { left: "Sesión 2 de 8 · Control", right: "realizada" },
            { left: "Sesión 3 de 8 · Control", right: "pendiente" },
          ],
          footer: { left: "Total del plan", right: "{money:4200000}" },
        },
      },
      {
        kicker: "El paciente no se pierde una sesión",
        title: "Agenda, recordatorio y seguimiento del plan",
        paragraphs: [
          "Cada sesión del plan se agenda con su propio turno, y el paciente recibe confirmación y recordatorio antes de venir, igual que para cualquier consulta — el tratamiento largo no depende de que se acuerde solo.",
          "Si el paciente falta a una sesión, el turno queda marcado como ausente y el plan sigue esperando esa sesión pendiente, en vez de perderse entre las demás consultas del consultorio.",
        ],
        linkLabel: "Ver el seguimiento del plan",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Ficha · Norma Villalba",
          title: "Seguimiento de ortodoncia",
          rows: [
            { left: "Sesión 3 de 8", right: "ausente", sub: ["reprogramada"] },
            { left: "Sesión 3 de 8 (nueva fecha)", right: "confirmado" },
          ],
        },
      },
      {
        kicker: "Lo que se cobra en cada visita",
        title: "El cobro sigue al plan, no a la memoria",
        paragraphs: [
          "Si el plan se paga en cuotas por sesión, cada cobro queda asociado al tratamiento y al paciente, así al final se ve cuánto se cobró del plan y cuánto falta sin reconstruirlo de las boletas sueltas.",
          "La ficha del paciente muestra el plan completo — hecho, pendiente y cobrado — en un solo lugar, listo para la próxima consulta de seguimiento.",
        ],
        linkLabel: "Ver el cobro por sesión",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Sesión 2 de 8 · Norma Villalba",
          title: "Cobrar sesión",
          rows: [
            { left: "Cuota del plan", right: "{money:525000}" },
            { left: "Pagado hasta hoy", right: "{money:1050000}" },
          ],
          footer: { left: "Saldo del plan", right: "{money:3150000}" },
        },
      },
    ],
  },
  {
    slug: "veterinarias",
    grupo: "salud-y-belleza",
    label: "Veterinarias",
    posesivo: "tu veterinaria",
    eyebrow: "Para veterinarias",
    heroTitle: "Cada mascota con su ficha, cada dueño con su historia",
    heroDescription:
      "La ficha va por mascota y por dueño, las vacunas y controles se agendan con recordatorio, y el alimento o los accesorios que se llevan se suman a la consulta en el mismo ticket.",
    thirtySeconds: [
      "Cada mascota tiene su propia ficha, y cada dueño puede tener varias, todas enlazadas a su nombre.",
      "El turno de vacuna o control se agenda con confirmación y recordatorio para que el dueño no se olvide.",
      "La ficha guarda el historial de vacunas, controles y tratamientos de cada mascota.",
      "El alimento o los accesorios que el dueño compra se cobran en el mismo ticket que la consulta.",
    ],
    sections: [
      {
        kicker: "Una ficha por mascota, no por dueño",
        title: "Firulais y Michi, cada uno con su historia",
        paragraphs: [
          "Un mismo dueño puede tener perro y gato, y cada mascota tiene su propia ficha con su peso, su raza y su historial — todas enlazadas al mismo dueño para no cargar sus datos de contacto dos veces.",
          "Cuando el dueño llama para consultar por 'el perro', la recepción encuentra la ficha exacta sin confundirla con la del gato de la misma familia.",
        ],
        linkLabel: "Ver la ficha de la mascota",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Dueño · Familia Aquino",
          title: "Mascotas registradas",
          rows: [
            { left: "Firulais · Labrador", right: "4 años" },
            { left: "Michi · Siamés", right: "2 años" },
          ],
        },
      },
      {
        kicker: "Ninguna vacuna se pasa de fecha",
        title: "Controles y vacunas con recordatorio",
        paragraphs: [
          "Cada vacuna aplicada queda registrada en la ficha con la fecha de la próxima dosis, y el turno de control se agenda con su recordatorio para que el dueño no deje pasar la fecha sin darse cuenta.",
          "El estado del turno — confirmado, atendido, ausente — permite hacer seguimiento de los controles que quedaron pendientes, en vez de esperar a que el dueño se acuerde solo.",
        ],
        linkLabel: "Ver el calendario de vacunas",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Ficha · Firulais",
          title: "Historial de vacunas",
          rows: [
            {
              left: "Antirrábica",
              right: "aplicada",
              sub: ["próxima: en 11 meses"],
            },
            { left: "Séxtuple", right: "vence en 2 semanas" },
          ],
        },
      },
      {
        kicker: "La consulta y lo que se lleva",
        title: "Alimento y accesorios en el mismo ticket",
        paragraphs: [
          "Cuando el dueño retira a su mascota después de la consulta, el cobro junta el control, la vacuna aplicada y la bolsa de alimento que se lleva en un solo ticket, sin pasar por una caja aparte para el producto.",
          "El stock de alimentos y accesorios se descuenta igual que en cualquier mostrador, así la veterinaria sabe cuándo reponer sin esperar a que falte en la góndola.",
        ],
        linkLabel: "Ver el cobro con producto",
        mockup: {
          label: "Consulta · Firulais",
          title: "Cobrar visita",
          rows: [
            { left: "Control + vacuna séxtuple", right: "{money:150000}" },
            { left: "1× Alimento 15kg", right: "{money:280000}" },
          ],
          footer: { left: "Total", right: "{money:430000}" },
        },
      },
    ],
  },
  {
    slug: "estetica-y-cosmetologia",
    destacado: true,
    grupo: "salud-y-belleza",
    label: "Estética y cosmetología",
    posesivo: "tu centro",
    eyebrow: "Para centros de estética y cosmetología",
    heroTitle: "El paquete de sesiones, seguido de principio a fin",
    heroDescription:
      "Un tratamiento de varias sesiones se vende como paquete y se descuenta sesión por sesión, cada una con su turno y su recordatorio, mientras la ficha del cliente sigue el progreso y el insumo usado en cada visita.",
    thirtySeconds: [
      "Un paquete de sesiones se vende una vez y se descuenta sesión por sesión en cada visita.",
      "Cada sesión se agenda con confirmación y recordatorio, sin que el cliente tenga que acordarse solo.",
      "El estado del turno — confirmado, atendido, ausente — hace seguimiento del paquete sin perder sesiones.",
      "La ficha del cliente guarda qué tratamiento e insumo se usó en cada sesión anterior.",
    ],
    sections: [
      {
        kicker: "El paquete, no la sesión suelta",
        title: "Vender el tratamiento completo, descontar sesión por sesión",
        paragraphs: [
          "Un tratamiento de depilación láser o de limpieza facial rara vez se resuelve en una visita: el paquete de sesiones se vende una sola vez, con su precio total, y cada sesión que el cliente cumple se descuenta de ahí, sin recobrar ni recontar a mano.",
          "El cliente ve cuántas sesiones le quedan del paquete que compró, y el centro sabe qué paquetes están por vencerse antes de que el cliente se olvide de usarlos.",
        ],
        linkLabel: "Ver un paquete de sesiones",
        linkHref: "/modulos/gift-cards",
        mockup: {
          label: "Paquete · Depilación láser",
          title: "Piernas completas · 6 sesiones",
          rows: [
            { left: "Sesión 1", right: "realizada" },
            { left: "Sesión 2", right: "realizada" },
            { left: "Sesión 3", right: "pendiente" },
          ],
          footer: { left: "Pagado", right: "{money:900000}" },
        },
      },
      {
        kicker: "Cada sesión, su turno",
        title: "Agenda y recordatorio para no perder el ritmo",
        paragraphs: [
          "Cada sesión del paquete se agenda con su propio turno, y el cliente recibe confirmación y recordatorio antes de venir — el tratamiento de varias semanas no depende de que se acuerde solo entre sesión y sesión.",
          "Si falta a una sesión, el turno queda marcado como ausente y el paquete sigue mostrando esa sesión como pendiente, para reprogramarla sin perderla de vista.",
        ],
        linkLabel: "Ver la agenda del paquete",
        linkHref: "/modulos/gift-cards",
        mockup: {
          label: "Ficha · Carla Bogarín",
          title: "Próxima sesión",
          rows: [
            {
              left: "Sesión 3 · Piernas completas",
              right: "confirmado",
              sub: ["Jueves 16:00"],
            },
          ],
        },
      },
      {
        kicker: "El insumo también se cuenta",
        title: "Seguimiento del cliente y del insumo por sesión",
        paragraphs: [
          "Cada sesión registra qué producto o insumo se usó — la ampolla, la crema, el gel — así el centro conoce el costo real del tratamiento y no solo el precio de venta del paquete.",
          "La ficha del cliente junta todo: qué tratamientos hizo, con qué resultado y qué insumo se le aplicó cada vez, lista para la próxima sesión sin preguntar de nuevo.",
        ],
        linkLabel: "Ver el insumo por sesión",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Sesión 2 · Carla Bogarín",
          title: "Insumos usados",
          rows: [
            { left: "Gel conductor", right: "{money:8000}" },
            { left: "Costo de la sesión", right: "{money:8000}" },
          ],
        },
      },
    ],
  },
]

/** Los que van al menú principal: cuatro por grupo. */
export function rubrosDestacados(): Rubro[] {
  return RUBROS.filter((r) => r.destacado)
}

export function getRubro(slug: string): Rubro | undefined {
  return RUBROS.find((r) => r.slug === slug)
}
