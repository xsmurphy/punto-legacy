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
  /** Foto de fondo del hero (public/site/...). Sin ella, el hero va claro. */
  heroImage?: string
  thirtySeconds?: string[]
  sections?: RubroSection[]
}

export const RUBROS: Rubro[] = [
  {
    slug: "restaurantes",
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
          "Al final de la comida cada uno sabe cuánto le toca: en partes iguales o por lo que pidió. La mesa se cobra en efectivo, QR o tarjeta — o mezclado — y la factura electrónica sale en ese mismo toque, con el RUC que el cliente diga.",
          "Nada de reconstruir la mesa desde tres papeles: la cuenta vivió en el sistema desde el primer pedido.",
        ],
        linkLabel: "Ver el cobro de una mesa",
        mockup: {
          label: "Mesa 12 · 3 personas",
          title: "Dividir la cuenta",
          rows: [
            { left: "Gladys", right: "Gs. 75.000" },
            { left: "Osvaldo", right: "Gs. 80.000" },
            { left: "Rocío", right: "Gs. 60.000" },
          ],
          footer: { left: "Total", right: "Gs. 215.000" },
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
  {
    slug: "ferreterias",
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
          "El cliente pide \"un caño de media\" o \"el tornillo autoperforante de una pulgada\" y el buscador responde por nombre, medida o código, sin que el vendedor tenga que memorizar dónde está cada cosa entre miles de artículos.",
          "Si en el depósito de esta sucursal no queda, se ve al toque dónde sí hay stock, antes de mandar al cliente a buscar en otro lado.",
        ],
        linkLabel: "Ver el buscador de artículos",
        mockup: {
          label: "Mostrador",
          title: "Buscar: caño PVC",
          rows: [
            { left: "Caño PVC 1/2\" × 6m", right: "Gs. 38.000", sub: ["Depósito central: 42 · Sucursal Ñemby: 6"] },
            { left: "Codo PVC 1/2\"", right: "Gs. 3.500", sub: ["Depósito central: 210"] },
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
            { left: "18× Caño PVC 1/2\" (m)", right: "Gs. 216.000" },
            { left: "2× Bolsa cemento 50kg", right: "Gs. 130.000" },
            { left: "1× Kit grifería", right: "Gs. 380.000" },
          ],
          footer: { left: "Total cotizado", right: "Gs. 726.000" },
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
            { left: "Construcciones Ayala", right: "Gs. 1.240.000", sub: ["límite Gs. 3.000.000"] },
            { left: "Don Feliciano", right: "Gs. 95.000", sub: ["último pago hace 12 días"] },
          ],
        },
      },
    ],
  },
  {
    slug: "cafeterias",
    label: "Cafeterías",
    posesivo: "tu cafetería",
    eyebrow: "Para cafeterías",
    heroTitle: "El mostrador rápido y la clientela que vuelve",
    heroDescription:
      "Cobrar el café en segundos, con combos y agregados que bajan claros a la barra. Gift cards para regalar y la historia de cada cliente para hacer que vuelva.",
    thirtySeconds: [
      "El pedido se arma en pantalla con tamaño, tipo de leche y agregados, y baja claro a la barra.",
      "Los combos (café + medialuna) se cobran como una sola línea, al precio del combo.",
      "Las gift cards se venden y se cargan como cualquier producto, listas para regalar.",
      "La caja abre y cierra por turno, con el arqueo de cada barista.",
    ],
    sections: [
      {
        kicker: "Hora pico en la barra",
        title: "El pedido baja claro, sin que el barista adivine",
        paragraphs: [
          "En la fila de las ocho de la mañana no hay tiempo para repetir el pedido dos veces: tamaño, tipo de leche, si va con azúcar o extra shot queda escrito en la pantalla de la barra apenas se cobra, en el orden en que llegó.",
          "El combo de café con medialuna se cobra como una sola línea al precio del combo, sin que la cajera tenga que acordarse de aplicar el descuento a mano.",
        ],
        linkLabel: "Ver el pedido en barra",
        mockup: {
          label: "Barra · 8:05",
          title: "Pedidos en cola",
          caption: "Cada pedido con su hora de entrada.",
          rows: [
            { left: "Orden 14 · hace 1 min", right: "2 items", sub: ["1× Latte grande, leche de avena", "1× Medialuna de manteca"] },
            { left: "Orden 15 · recién", right: "1 item", sub: ["1× Espresso doble, sin azúcar"] },
          ],
        },
      },
      {
        kicker: "Para regalar y para volver",
        title: "Gift cards y el cliente que ya sabés cómo lo pide",
        paragraphs: [
          "La gift card se vende como cualquier producto y queda cargada con su saldo, lista para que alguien la regale y otro la use en su próxima visita.",
          "El cliente frecuente tiene su cuenta con el historial de compras, así que cuando pide \"lo de siempre\" la caja ya sabe de qué habla.",
        ],
        linkLabel: "Ver una gift card",
        mockup: {
          label: "Gift card",
          title: "Tarjeta regalo",
          rows: [
            { left: "Código GC-3391", right: "Gs. 100.000" },
            { left: "Usado hasta hoy", right: "Gs. 35.000" },
          ],
          footer: { left: "Saldo disponible", right: "Gs. 65.000" },
        },
      },
      {
        kicker: "El día cierra en números",
        title: "Caja por turno, cada barista con su arqueo",
        paragraphs: [
          "Cada turno abre con un monto y cierra con arqueo: lo esperado contra lo contado, sin sorpresas al final del día. El dueño ve las ventas por franja horaria — el pico de la mañana no se parece en nada al de la tarde — sin pasar nada a mano a una planilla.",
        ],
        linkLabel: "Ver el arqueo del turno",
        mockup: {
          label: "Turno mañana",
          title: "Cierre de caja",
          rows: [
            { left: "Apertura", right: "Gs. 200.000" },
            { left: "Ventas en efectivo", right: "Gs. 890.000" },
            { left: "Esperado", right: "Gs. 1.090.000" },
          ],
          footer: { left: "Contado", right: "Gs. 1.085.000" },
        },
      },
    ],
  },
  {
    slug: "panaderias",
    label: "Panaderías",
    posesivo: "tu panadería",
    eyebrow: "Para panaderías",
    heroTitle: "Producción de madrugada, caja sin fila",
    heroDescription:
      "La receta descuenta harina y calcula el costo de cada horneada. En el mostrador se vende por unidad o al peso, rápido, con la caja rindiendo por turno.",
    thirtySeconds: [
      "La receta de cada horneada descuenta harina, levadura y todo insumo, y calcula el costo real del lote.",
      "En el mostrador se vende por unidad o por peso, con el mismo ticket para las dos formas.",
      "El domingo a la mañana la caja aguanta la fila sin perder velocidad.",
      "El reporte de producción compara lo horneado contra lo vendido, para ver qué sobró.",
    ],
    sections: [
      {
        kicker: "Antes de que abra el local",
        title: "Cada horneada con su receta y su costo real",
        paragraphs: [
          "A las cuatro de la mañana la producción arranca con recetas: cada lote de pan francés o de facturas descuenta la harina, la levadura y el resto de los insumos del stock, y deja el costo real de esa horneada, no un número estimado a ojo.",
          "Cuando sube el precio de la harina, el costo del producto se actualiza solo — no hace falta recalcular cada receta a mano.",
        ],
        linkLabel: "Ver una orden de producción",
        mockup: {
          label: "Producción 4:30",
          title: "Horneada del día",
          rows: [
            { left: "Pan francés × 80", right: "Gs. 96.000", sub: ["Harina 12kg · Levadura 200g"] },
            { left: "Facturas surtidas × 60", right: "Gs. 84.000", sub: ["Harina 6kg · Manteca 1.5kg"] },
          ],
          footer: { left: "Costo del lote", right: "Gs. 180.000" },
        },
      },
      {
        kicker: "El domingo a la mañana",
        title: "Por unidad o por peso, el mismo mostrador",
        paragraphs: [
          "La fila del domingo no perdona: el pan se vende por kilo y la factura por unidad, y el mostrador cobra las dos cosas en el mismo ticket, sin cambiar de pantalla ni de balanza a las cajas.",
          "El cliente que compra la docena de facturas y el kilo de pan casero sale con un solo ticket y, si lo pide, con su factura electrónica.",
        ],
        linkLabel: "Ver el mostrador",
        mockup: {
          label: "Mostrador domingo",
          title: "Ticket en curso",
          rows: [
            { left: "1.2kg Pan casero", right: "Gs. 14.400" },
            { left: "12× Factura de manteca", right: "Gs. 24.000" },
          ],
          footer: { left: "Cobrar", right: "Gs. 38.400" },
        },
      },
      {
        kicker: "Lo que sobró importa",
        title: "Producido contra vendido, sin adivinar",
        paragraphs: [
          "Al cierre del día, el reporte compara cuánto se horneó contra cuánto se vendió: lo que sobró de cada producto queda anotado, y con eso la producción de mañana se ajusta en vez de repetir el mismo error.",
        ],
        linkLabel: "Ver el reporte de producción",
        mockup: {
          label: "Cierre del día",
          title: "Producido vs. vendido",
          rows: [
            { left: "Pan francés", right: "80 / 74", sub: ["sobraron 6"] },
            { left: "Facturas surtidas", right: "60 / 60", sub: ["sin sobrante"] },
          ],
        },
      },
    ],
  },
  {
    slug: "tiendas-de-ropa",
    label: "Tiendas de ropa",
    posesivo: "tu tienda",
    eyebrow: "Para tiendas de ropa",
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
          "El vestido \"floreado corto\" es un solo artículo con sus variantes de talle y color: buscarlo en el mostrador muestra de una el stock de cada combinación, sin tener que adivinar entre veinte códigos parecidos.",
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
            { left: "Devuelve: Blusa lino Talle M", right: "Gs. 145.000" },
            { left: "Lleva: Blusa lino Talle S", right: "Gs. 145.000" },
          ],
          footer: { left: "Saldo a favor", right: "Gs. 0" },
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
            { left: "Pantalón cargo", right: "9 unidades", sub: ["quedó stock"] },
          ],
        },
      },
    ],
  },
  {
    slug: "bares-y-pubs",
    label: "Bares y pubs",
    posesivo: "tu bar",
    eyebrow: "Para bares y pubs",
    heroTitle: "La barra no para, la cuenta tampoco se pierde",
    heroDescription:
      "Cada mesa y cada cliente en la barra tiene su cuenta abierta, con lo que va pidiendo. La comanda sale impresa donde corresponde, la cuenta se divide entre varios al cerrar, y la caja de una noche fuerte cierra en un arqueo, no en una discusión.",
    thirtySeconds: [
      "La cuenta se abre por mesa o por cliente en la barra, y se va cargando trago a trago.",
      "El pedido baja impreso donde corresponde: el trago a la barra, el picoteo a la cocina.",
      "Al cerrar, la cuenta se divide entre varios con efectivo, QR y tarjeta mezclados.",
      "El cierre de una noche fuerte compara lo esperado contra lo contado, turno por turno.",
    ],
    sections: [
      {
        kicker: "La barra en su hora pico",
        title: "El pedido baja donde corresponde, sin gritos",
        paragraphs: [
          "Un viernes a la noche la barra no da abasto para gritar cada pedido hacia la cocina: el trago entra a la cola de la barra y el picoteo a la cola de la cocina, cada uno en su pantalla, con la mesa y la hora de pedido bien claras.",
          "El barman arma la tanda por orden de llegada, sin depender de que alguien se acuerde de avisarle a los gritos entre la música.",
        ],
        linkLabel: "Ver las comandas de la barra",
        mockup: {
          label: "Viernes 23:15",
          title: "Barra · cola de comandas",
          caption: "Cada pedido con su mesa y su hora.",
          rows: [
            { left: "Mesa 5 · hace 2 min", right: "3 tragos", sub: ["2× Gin tonic", "1× Cerveza tirada 1L"] },
            { left: "Barra · hace 1 min", right: "1 trago", sub: ["1× Whisky doble, sin hielo"] },
          ],
        },
      },
      {
        kicker: "Cada uno paga lo suyo",
        title: "Cuenta abierta, dividida al cerrar entre todos",
        paragraphs: [
          "La mesa que llegó a las diez y sigue pidiendo hasta la una tiene su cuenta abierta todo ese tiempo, sumando cada ronda. Al cerrar, se divide en partes iguales o por lo que tomó cada uno, y se cobra mezclando efectivo, QR y tarjeta sin reabrir nada.",
        ],
        linkLabel: "Ver el cierre de una mesa",
        mockup: {
          label: "Mesa 5 · 4 personas",
          title: "Dividir la cuenta",
          rows: [
            { left: "Braulio", right: "Gs. 95.000" },
            { left: "Nadia", right: "Gs. 95.000" },
            { left: "Fabricio", right: "Gs. 60.000" },
            { left: "Delia", right: "Gs. 90.000" },
          ],
          footer: { left: "Total", right: "Gs. 340.000" },
        },
      },
      {
        kicker: "La noche fuerte también cierra",
        title: "Arqueo de caja después de la última ronda",
        paragraphs: [
          "A las tres de la mañana, cuando se apagan las luces, el turno cierra con arqueo: lo esperado contra lo contado, con cada retiro del día anotado desde antes. El dueño ve la noche completa — qué se vendió, a qué hora fue el pico — sin esperar a reconstruirla el lunes.",
        ],
        linkLabel: "Ver el arqueo de la noche",
        mockup: {
          label: "Turno noche",
          title: "Arqueo de caja",
          rows: [
            { left: "Apertura", right: "Gs. 400.000" },
            { left: "Ventas en efectivo", right: "Gs. 1.850.000" },
            { left: "Esperado", right: "Gs. 2.250.000" },
          ],
          footer: { left: "Contado", right: "Gs. 2.230.000" },
        },
      },
    ],
  },
  {
    slug: "heladerias",
    label: "Heladerías",
    posesivo: "tu heladería",
    eyebrow: "Para heladerías",
    heroTitle: "Por peso, por bocha, y la misma receta en todas las sucursales",
    heroDescription:
      "El pote se cobra por peso o por cantidad de bochas, con los agregados que pida el cliente. La receta de cada sabor descuenta los insumos de producción, y el catálogo es el mismo en todas las sucursales, para que el pico del verano no encuentre a nadie con una lista distinta.",
    thirtySeconds: [
      "El pote se cobra por peso o por bocha, con los agregados sumados en la misma pantalla.",
      "Los combos (pote + toppings) salen a un precio cerrado, sin sumar cada ítem a mano.",
      "La receta de cada sabor descuenta leche, crema y fruta al producirlo, con su costo real.",
      "Todas las sucursales venden del mismo catálogo, así el precio no cambia de una punta a otra.",
    ],
    sections: [
      {
        kicker: "Por peso o por bocha",
        title: "El mostrador cobra como se pide el helado",
        paragraphs: [
          "El cliente pide \"un cuarto\" o \"tres bochas\" y el mostrador cobra de la misma forma: por peso en la balanza o por cantidad, con los agregados — chips, salsa, cucurucho de más — sumados en la misma pantalla sin recalcular nada a mano.",
          "En la fila del sábado a la tarde eso hace la diferencia entre atender rápido o frenar la cola en cada pedido raro.",
        ],
        linkLabel: "Ver un pedido en el mostrador",
        mockup: {
          label: "Mostrador sábado",
          title: "Ticket en curso",
          rows: [
            { left: "0.5kg Pote 2 sabores", right: "Gs. 42.000" },
            { left: "Cucurucho 3 bochas", right: "Gs. 18.000", sub: ["+ chips de chocolate"] },
          ],
          footer: { left: "Cobrar", right: "Gs. 60.000" },
        },
      },
      {
        kicker: "El sabor no se improvisa",
        title: "Cada producción con su receta y su costo",
        paragraphs: [
          "El sabor de dulce de leche granizado se produce con una receta que descuenta la leche, la crema y el dulce de leche del stock, y deja el costo real de ese lote — no una estimación de memoria.",
          "Cuando el precio de un insumo sube, el costo del sabor se actualiza solo, sin recalcular receta por receta.",
        ],
        linkLabel: "Ver una orden de producción",
        mockup: {
          label: "Producción de sabores",
          title: "Dulce de leche granizado",
          rows: [
            { left: "Lote de 8kg", right: "Gs. 96.000", sub: ["Leche 5L · Dulce de leche 2kg"] },
          ],
          footer: { left: "Costo por kilo", right: "Gs. 12.000" },
        },
      },
      {
        kicker: "El mismo catálogo en todas partes",
        title: "Del centro a la costanera, el mismo precio",
        paragraphs: [
          "En el pico del verano cada sucursal vende del mismo catálogo, con los mismos sabores y el mismo precio — el cliente no encuentra una lista distinta si va a la sucursal de la costanera en vez de la del centro. Y cuando un sabor se agota en una sucursal, se ve al instante en cuál sí queda.",
        ],
        linkLabel: "Ver el catálogo por sucursal",
        mockup: {
          label: "Sábado de verano",
          title: "Stock por sucursal",
          rows: [
            { left: "Dulce de leche granizado", right: "Centro: 4kg", sub: ["Costanera: 0kg — agotado"] },
            { left: "Frutilla a la crema", right: "Centro: 6kg", sub: ["Costanera: 3kg"] },
          ],
        },
      },
    ],
  },
]

export function getRubro(slug: string): Rubro | undefined {
  return RUBROS.find((r) => r.slug === slug)
}
