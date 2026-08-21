/**
 * Contenido de las minipages de módulo (`/modulos/[modulo]`).
 *
 * Misma idea que `rubros.ts`: config pura que el template renderiza. Acá
 * viven los tres protagonistas del producto (Punto de Venta, Panel y
 * Punto AI); los módulos menores se suman cuando se escriban.
 */

import type { RubroMockup } from "@/lib/site/rubros"

export type ModuloSection = {
  kicker: string
  title: string
  paragraphs: string[]
  linkLabel: string
  mockup: RubroMockup
}

export type Modulo = {
  slug: string
  /** Nombre corto para listas y navegación. */
  label: string
  eyebrow: string
  heroTitle: string
  heroDescription: string
  /** Captura del módulo, protagonista del hero. */
  heroImage: { src: string; alt: string }
  essentials: string[]
  sections: ModuloSection[]
}

export const MODULOS: Modulo[] = [
  {
    slug: "punto-de-venta",
    label: "Punto de Venta",
    eyebrow: "Punto de Venta",
    heroTitle: "Vender no debería tomar más de unos segundos",
    heroDescription:
      "La pantalla donde pasa el día: buscar, cobrar y entregar el comprobante sin que la fila se entere. Funciona con dedo en tablet o entera por teclado, y no se detiene cuando se corta internet.",
    heroImage: {
      src: "/site/pos-screenshot.png",
      alt: "Punto de Venta de Punto: catálogo con fotos, carrito y total de la venta",
    },
    essentials: [
      "Artículos con foto a la vista, buscador instantáneo y lector de código de barras.",
      "Contado, crédito, QR o varios medios en la misma venta, con vuelto calculado.",
      "Descuentos, notas y precios por lista aplicados sin salir de la pantalla.",
      "Sigue vendiendo sin internet: al volver la conexión sincroniza sola.",
    ],
    sections: [
      {
        kicker: "La fila no espera",
        title: "Todo el flujo de venta, sin soltar el teclado",
        paragraphs: [
          "El artículo aparece mientras escribís, el escáner lo suma directo y el total se recalcula en cada toque. Cobrar, entregar el comprobante y arrancar la próxima venta son tres pasos que se hacen con atajos, sin buscar botones ni abrir menús.",
          "En tablet la lógica es la misma pero con targets grandes: el cajero opera con el pulgar y los elementos no cambian de lugar según el estado, así la memoria muscular no se rompe.",
        ],
        linkLabel: "Ver cómo se cobra",
        mockup: {
          label: "Caja 1",
          title: "Venta en curso",
          rows: [
            { left: "1× Lomito árabe", right: "Gs. 35.000", sub: ["Sin cebolla · Extra queso"] },
            { left: "2× Jugo de mburucuyá", right: "Gs. 24.000" },
          ],
          footer: { left: "Cobrar", right: "Gs. 59.000" },
        },
      },
      {
        kicker: "Un cobro, varios medios",
        title: "Efectivo, tarjeta, QR o crédito — o todo junto",
        paragraphs: [
          "Una venta puede cerrarse con parte en efectivo y parte en transferencia, o quedar a crédito contra la cuenta corriente del cliente. Cada medio queda registrado por separado, así el arqueo del turno cuadra sin adivinanzas.",
          "El comprobante sale al cerrar: ticket para el que no pide nada, factura electrónica para el que da sus datos. La numeración la controla el sistema, no el cajero.",
        ],
        linkLabel: "Ver el comprobante",
        mockup: {
          label: "Cobro",
          title: "Medios de pago",
          rows: [
            { left: "Efectivo", right: "Gs. 40.000" },
            { left: "Transferencia", right: "Gs. 19.000" },
            { left: "Vuelto", right: "Gs. 0" },
          ],
          footer: { left: "Total cobrado", right: "Gs. 59.000" },
        },
      },
      {
        kicker: "Se corta la luz y seguís",
        title: "El modo offline no es un plan B, es la base",
        paragraphs: [
          "Punto guarda cada venta en el dispositivo y la emite igual: sin internet, sin energía en la manzana, con una tablet a batería. Nada de 'volvé más tarde' ni de anotar en un papel para cargar después.",
          "Cuando la conexión vuelve, las ventas suben solas y aparecen en el panel con su hora real — la del momento en que se vendió, no la de la sincronización.",
        ],
        linkLabel: "Ver la sincronización",
        mockup: {
          label: "Sin conexión",
          title: "Ventas en espera",
          rows: [
            { left: "Ticket #482", right: "Gs. 59.000", sub: ["emitido 19:41"] },
            { left: "Ticket #483", right: "Gs. 118.000", sub: ["emitido 19:47"] },
          ],
          footer: { left: "Se suben al reconectar", right: "2" },
        },
      },
      {
        kicker: "Cada caja, su responsable",
        title: "Turnos, permisos y arqueo que cuadra",
        paragraphs: [
          "Cada dispositivo es una caja con su sesión y su numeración. El cajero abre turno con su usuario, y lo que pasa en ese turno — ventas, retiros, gastos — queda con nombre y hora.",
          "Al cerrar, el arqueo compara lo que el sistema esperaba contra lo que se contó. La diferencia, si la hay, aparece sola: nadie tiene que reconstruir el día de memoria.",
        ],
        linkLabel: "Ver el cierre de turno",
        mockup: {
          label: "Turno noche · Caja 2",
          title: "Arqueo de caja",
          rows: [
            { left: "Apertura", right: "Gs. 500.000" },
            { left: "Ventas en efectivo", right: "Gs. 2.140.000" },
            { left: "Retiros", right: "Gs. -300.000" },
          ],
          footer: { left: "Esperado", right: "Gs. 2.340.000" },
        },
      },
    ],
  },
  {
    slug: "panel",
    label: "Panel",
    eyebrow: "Panel de administración",
    heroTitle: "Tu negocio entero, a la vista",
    heroDescription:
      "Todas las sucursales en la misma pantalla: qué se vendió hoy, qué falta reponer, quién debe y con qué margen cerró el mes. Desde la computadora del local o desde el teléfono, con los mismos datos.",
    heroImage: {
      src: "/site/panel-screenshot.png",
      alt: "Panel de administración de Punto: resumen de ventas del negocio",
    },
    essentials: [
      "Ventas del día por sucursal, caja y usuario, actualizadas al instante.",
      "Catálogo, precios y listas centralizados, con lo que ve cada sucursal.",
      "Stock por depósito, mínimos con aviso y compras que actualizan el costo.",
      "Cuentas por cobrar, cobranzas y la historia completa de cada cliente.",
    ],
    sections: [
      {
        kicker: "Una marca, varios locales",
        title: "Multi-sucursal sin planillas paralelas",
        paragraphs: [
          "Cada sucursal opera con sus cajas, su depósito y su gente, pero el dueño ve el conjunto: comparar el día del centro contra el de la sucursal nueva no requiere pedirle el número a nadie.",
          "Los permisos siguen la misma lógica: el encargado ve lo suyo, la administración ve todo, y cada quien entra con su usuario.",
        ],
        linkLabel: "Ver el resumen por sucursal",
        mockup: {
          label: "Hoy",
          title: "Ventas por sucursal",
          rows: [
            { left: "Centro", right: "Gs. 4.180.000", sub: ["92 tickets"] },
            { left: "Villa Morra", right: "Gs. 2.940.000", sub: ["61 tickets"] },
            { left: "San Lorenzo", right: "Gs. 1.300.000", sub: ["31 tickets"] },
          ],
          footer: { left: "Total del día", right: "Gs. 8.420.000" },
        },
      },
      {
        kicker: "El catálogo, en un lugar",
        title: "Cargar una vez y que lo vean todas las cajas",
        paragraphs: [
          "Artículos, categorías, variantes y precios se cargan en el panel y bajan a cada punto de venta al instante. Si subís un precio a las tres de la tarde, la caja lo cobra a las tres y un minuto.",
          "Las listas de precio conviven: mostrador, mayorista y delivery pueden tener el suyo sin duplicar el artículo ni llevar una planilla aparte.",
        ],
        linkLabel: "Ver listas de precio",
        mockup: {
          label: "Artículo",
          title: "Café en grano 1kg",
          rows: [
            { left: "Mostrador", right: "Gs. 95.000" },
            { left: "Mayorista", right: "Gs. 78.000", sub: ["desde 6 unidades"] },
            { left: "Costo", right: "Gs. 52.000", sub: ["margen 45%"] },
          ],
        },
      },
      {
        kicker: "Antes de que falte",
        title: "Stock y compras que se hablan entre sí",
        paragraphs: [
          "Cada venta descuenta stock en su depósito, cada compra lo repone y actualiza el costo. Los mínimos avisan antes del quiebre y los ajustes quedan con fecha, usuario y motivo — el inventario deja de ser un misterio de fin de mes.",
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
        kicker: "Números, no intuición",
        title: "Reportes listos al abrir la pantalla",
        paragraphs: [
          "Ventas por hora, ranking de productos, márgenes, medios de pago, cuentas por cobrar y los libros que pide el contador. Todo sale del mismo dato que generó la caja, sin exportar ni cruzar planillas.",
        ],
        linkLabel: "Ver los reportes",
        mockup: {
          label: "Este mes",
          title: "Lo más vendido",
          rows: [
            { left: "Empanada de carne", right: "Gs. 1.278.000", sub: ["142 unidades"] },
            { left: "Café con leche", right: "Gs. 1.470.000", sub: ["98 unidades"] },
            { left: "Combo desayuno", right: "Gs. 1.586.000", sub: ["61 unidades"] },
          ],
        },
      },
    ],
  },
  {
    slug: "punto-ai",
    label: "Punto AI",
    eyebrow: "Punto AI",
    heroTitle: "Un analista que ya conoce tus números",
    heroDescription:
      "Preguntale en tu idioma y responde con los datos reales de tu negocio: arma el reporte, lo grafica y explica qué está pasando. Sin exportar planillas y sin saber por dónde empezar.",
    heroImage: {
      src: "/site/ai-screenshot.png",
      alt: "Punto AI: gráfico de ventas diarias con el análisis escrito del asistente",
    },
    essentials: [
      "Preguntás como le hablarías a tu contador y responde con tus datos, no con generalidades.",
      "Arma el gráfico y el resumen escrito en el mismo paso.",
      "Compara períodos, sucursales y productos sin que tengas que armar el filtro.",
      "Puede cargar y corregir datos básicos del catálogo, siempre pidiéndote confirmación.",
    ],
    sections: [
      {
        kicker: "Preguntar es la interfaz",
        title: "La pregunta que harías en voz alta, respondida con datos",
        paragraphs: [
          "\"¿Cómo viene el mes contra el anterior?\", \"¿qué producto me deja más margen?\", \"¿qué clientes no volvieron en 60 días?\". Punto AI entiende la pregunta, busca en tu información y contesta con el número concreto — más el gráfico cuando ayuda a verlo.",
          "No hay que aprender dónde vive cada reporte ni qué filtro combinar: la conversación reemplaza el recorrido por los menús.",
        ],
        linkLabel: "Ver una respuesta",
        mockup: {
          label: "Punto AI",
          title: "¿Cómo viene la semana?",
          rows: [
            { left: "Ventas 01 al 09", right: "Gs. 2.310.000", sub: ["8 días con ventas"] },
            { left: "Día pico", right: "Gs. 495.000", sub: ["08/06"] },
            { left: "Más vendido", right: "Pizza peperoni" },
          ],
        },
      },
      {
        kicker: "No solo el qué, el por qué",
        title: "Analiza y te dice dónde mirar",
        paragraphs: [
          "La respuesta no es una tabla muda: señala el día flojo, el producto que cayó, el cliente que dejó de comprar o el margen que se comió un costo nuevo. Lo que un dueño encontraría revisando reportes durante una hora, en la primera respuesta.",
          "Y como trabaja sobre los datos de tu negocio, las conclusiones son tuyas: nada de promedios de industria ni consejos genéricos.",
        ],
        linkLabel: "Ver el análisis",
        mockup: {
          label: "Hallazgos",
          title: "Qué mirar esta semana",
          rows: [
            { left: "Margen en bebidas", right: "-6 pts", sub: ["subió el costo del proveedor"] },
            { left: "Martes", right: "-38%", sub: ["contra el resto de la semana"] },
            { left: "Clientes sin volver", right: "9", sub: ["compraban cada 15 días"] },
          ],
        },
      },
      {
        kicker: "También ordena",
        title: "Carga y corrige datos, con tu confirmación",
        paragraphs: [
          "Además de leer, Punto AI puede poner orden: crear un artículo, corregir una categoría mal escrita, cargar un contacto nuevo. Cada acción que modifica algo te la muestra antes y espera que confirmes.",
          "Lo sensible queda fuera por diseño: no toca ventas, ni caja, ni permisos, ni borra nada en masa. Ordena el catálogo, no la contabilidad.",
        ],
        linkLabel: "Ver una acción confirmada",
        mockup: {
          label: "Confirmación",
          title: "Crear artículo",
          rows: [
            { left: "Nombre", right: "Medialuna de manteca" },
            { left: "Categoría", right: "Panadería" },
            { left: "Precio", right: "Gs. 6.000" },
          ],
          footer: { left: "Confirmar", right: "Cancelar" },
        },
      },
    ],
  },
  {
    slug: "mesas-y-ordenes",
    label: "Mesas y órdenes",
    eyebrow: "Mesas y órdenes",
    heroTitle: "El salón y la cocina, en la misma página",
    heroDescription:
      "Cada mesa con su cuenta abierta, cada pedido con su hora de entrada en cocina. Se agrega una ronda, se divide la cuenta o se cobra desde cualquier caja del local, y todos los dispositivos ven lo mismo al instante.",
    heroImage: {
      src: "/site/pos-screenshot.png",
      alt: "Punto de Venta con el salón y las mesas abiertas",
    },
    essentials: [
      "La mesa acumula rondas y muestra su cuenta al día desde cualquier dispositivo.",
      "El pedido entra a cocina con su hora, sus agregados y sus aclaraciones.",
      "La cuenta se divide por ítems, por monto o en partes iguales.",
      "Cada estación — cocina, barra, plancha — recibe solo lo suyo.",
    ],
    sections: [
      {
        kicker: "Estado compartido, no copias",
        title: "La mesa es la misma desde cualquier caja",
        paragraphs: [
          "El mozo toma el pedido en el salón, la caja del fondo cobra y el encargado mira desde el panel: los tres ven el mismo saldo. No hay una versión de la mesa por dispositivo ni un papel que haya que ir a buscar.",
          "Cuando el cliente pide la cuenta, la mesa lo señala sin bloquearse — si alguien suma un postre después, entra igual y la cuenta se actualiza.",
        ],
        linkLabel: "Ver el salón",
        mockup: {
          label: "Salón",
          title: "Mesas abiertas",
          rows: [
            { left: "Mesa 3", right: "Gs. 128.000", sub: ["Ocupada · 25 min"] },
            { left: "Mesa 7", right: "Gs. 96.000", sub: ["Pidió la cuenta"] },
            { left: "Mesa 12", right: "—", sub: ["Libre"] },
          ],
        },
      },
      {
        kicker: "Cada estación, lo suyo",
        title: "La comanda se reparte sola entre cocina y barra",
        paragraphs: [
          "Los tragos van a la barra, los platos a la cocina y la pizza al horno, cada uno en su pantalla y en orden de llegada. Nadie tiene que gritar el pedido ni repartir papeles entre sectores.",
          "Los agregados y las aclaraciones bajan literales — sin cebolla, punto jugoso, para llevar — y cada línea se marca como lista cuando sale.",
        ],
        linkLabel: "Ver la pantalla de cocina",
        mockup: {
          label: "Cocina · en preparación",
          title: "Cola de comandas",
          rows: [
            { left: "#47 · Mesa 3", right: "hace 4 min", sub: ["2× Lomito · sin cebolla"] },
            { left: "#48 · Mesa 7", right: "hace 1 min", sub: ["1× Costilla al horno"] },
          ],
        },
      },
      {
        kicker: "La cuenta sin drama",
        title: "Dividir y cobrar en partes, con su comprobante",
        paragraphs: [
          "La mesa se puede cobrar entera o en partes: por lo que consumió cada uno, por un monto suelto o en partes iguales. Cada pago parcial genera su propio comprobante, así el que necesita factura la tiene.",
          "La mesa se cierra recién cuando el saldo llega a cero. No hay forma de dejarla abierta con plata pendiente por descuido.",
        ],
        linkLabel: "Ver el cobro dividido",
        mockup: {
          label: "Mesa 3 · 3 personas",
          title: "Dividir la cuenta",
          rows: [
            { left: "Pagó Gladys", right: "Gs. 45.000", sub: ["por sus ítems"] },
            { left: "Pagó Osvaldo", right: "Gs. 45.000", sub: ["efectivo"] },
            { left: "Pendiente", right: "Gs. 38.000" },
          ],
        },
      },
    ],
  },
  {
    slug: "gift-cards",
    label: "Gift cards y vales",
    eyebrow: "Gift cards y vales",
    heroTitle: "Cobrar hoy lo que se entrega después",
    heroDescription:
      "La gift card es plata a favor del cliente; el vale, productos ya pagos. Las dos se venden en la caja, se canjean con un código y descuentan solo lo que corresponde — sin cuadernos ni papelitos detrás del mostrador.",
    heroImage: {
      src: "/site/pos-screenshot.png",
      alt: "Punto de Venta con el canje de una gift card",
    },
    essentials: [
      "La gift card guarda un importe y se usa como medio de pago, entera o en varias compras.",
      "El vale guarda productos exactos, con su precio congelado al emitirse.",
      "El canje ocurre dentro de la venta: si algo falla, no se consume el saldo.",
      "Cada código deja su historia: cuándo se vendió, dónde se usó y qué queda.",
    ],
    sections: [
      {
        kicker: "Plata por adelantado",
        title: "La gift card entra a la caja hoy",
        paragraphs: [
          "El cliente compra un monto, se lleva el código y lo usa cuando quiera. Para el negocio es caja hoy y una visita casi asegurada después — con el detalle de que la mayoría gasta más que el saldo.",
          "Se puede usar en una compra o en varias: el sistema lleva el saldo restante y lo aplica como un medio de pago más, combinable con efectivo o tarjeta.",
        ],
        linkLabel: "Ver el canje",
        mockup: {
          label: "Cobro",
          title: "Gift card aplicada",
          rows: [
            { left: "Total de la venta", right: "Gs. 185.000" },
            { left: "Gift card GC-4821", right: "Gs. -150.000", sub: ["saldo restante Gs. 0"] },
            { left: "Efectivo", right: "Gs. 35.000" },
          ],
        },
      },
      {
        kicker: "Mercadería ya paga",
        title: "El vale es por productos, no por plata",
        paragraphs: [
          "Un combo de desayuno, diez lavados, una torta encargada: el vale guarda los ítems exactos con su precio congelado al momento de emitirse. Cuando el cliente lo trae, las líneas entran a la venta sin volver a sumar al total — ya se cobraron.",
          "Si el precio subió en el medio, no importa: lo que se vendió fue el producto, y el sistema lo respeta.",
        ],
        linkLabel: "Ver un vale",
        mockup: {
          label: "Vale V-1042",
          title: "Productos incluidos",
          rows: [
            { left: "2× Café con leche", right: "incluido" },
            { left: "2× Medialuna", right: "incluido" },
            { left: "Emitido", right: "12/08", sub: ["un solo uso"] },
          ],
        },
      },
      {
        kicker: "Sin dobles canjes",
        title: "El código se consume una sola vez",
        paragraphs: [
          "El canje pasa dentro de la venta, en el mismo movimiento: o se cobra y se consume el saldo, o no pasa nada. No existe el caso de un vale marcado como usado en una venta que después se cayó.",
          "Y cada código guarda su rastro — quién lo vendió, en qué sucursal se usó y qué saldo queda — así el reclamo del mostrador se resuelve mirando la pantalla.",
        ],
        linkLabel: "Ver el historial",
        mockup: {
          label: "GC-4821",
          title: "Historial del código",
          rows: [
            { left: "Vendida", right: "Gs. 150.000", sub: ["Centro · 02/08"] },
            { left: "Usada", right: "Gs. 150.000", sub: ["Villa Morra · 19/08"] },
            { left: "Saldo", right: "Gs. 0" },
          ],
        },
      },
    ],
  },
  {
    slug: "pantalla-de-cocina",
    label: "Pantalla de cocina",
    eyebrow: "Pantalla de cocina (KDS)",
    heroTitle: "La comanda deja de ser un papel",
    heroDescription:
      "Cada estación ve en su pantalla lo que le toca preparar, en orden de llegada y con el tiempo de espera corriendo. Sin impresora que se quede sin papel, sin tickets que se pierden entre la barra y la plancha.",
    heroImage: {
      src: "/site/pos-screenshot.png",
      alt: "Pantalla de cocina de Punto con las comandas en preparación",
    },
    essentials: [
      "Un tablero por estación: cocina, barra, plancha o el horno ven solo lo suyo.",
      "La comanda entra sola apenas se manda el pedido, con su hora de entrada.",
      "Los agregados y las aclaraciones se leen indentados bajo cada plato.",
      "Si algo se marcó listo por error, se vuelve atrás sin llamar a nadie.",
    ],
    sections: [
      {
        kicker: "Cada estación, su tablero",
        title: "La cocina ve platos; la barra, tragos",
        paragraphs: [
          "Un pedido de mesa puede repartirse entre tres estaciones y cada una recibe solo su parte. El que arma tragos no tiene que leer la comanda entera para encontrar lo suyo, y nadie prepara dos veces lo mismo.",
          "El orden lo pone la hora de entrada, no quién grita más fuerte: la tanda se arma por antigüedad y el tiempo de espera de cada comanda está a la vista.",
        ],
        linkLabel: "Ver el tablero",
        mockup: {
          label: "Cocina · en preparación",
          title: "Tablero por estación",
          rows: [
            { left: "#47 · Mesa 3", right: "4 min", sub: ["2× Lomito · sin cebolla"] },
            { left: "#48 · Mostrador", right: "2 min", sub: ["1× Milanesa · con papas"] },
            { left: "#49 · Mesa 7", right: "1 min", sub: ["1× Costilla al horno"] },
          ],
        },
      },
      {
        kicker: "Sin traducción de por medio",
        title: "Lo que pidió el cliente, tal cual",
        paragraphs: [
          "El punto de la carne, la mitad sin aceitunas, el extra de queso: los agregados bajan indentados bajo su plato, no como una nota suelta al final que alguien puede saltear.",
          "La comanda muestra todo lo que hay que preparar, cobre o no cobre. El que cocina no tiene que saber qué se facturó — solo qué sale.",
        ],
        linkLabel: "Ver una comanda",
        mockup: {
          label: "#47 · Mesa 3",
          title: "Comanda completa",
          rows: [
            { left: "1× Lomito completo", sub: ["Sin cebolla", "Extra queso"] },
            { left: "1× Papas rústicas", sub: ["Punto crocante"] },
            { left: "1× Limonada de menta" },
          ],
        },
      },
      {
        kicker: "Marcar y deshacer",
        title: "El plato avanza — y también puede volver",
        paragraphs: [
          "Cuando el plato sale, se marca listo y desaparece del tablero. Si se marcó de más — pasa en hora pico — se vuelve atrás desde la misma pantalla, sin pedirle permiso a la caja.",
          "Cada cambio queda registrado con su hora, así el encargado puede mirar después cuánto tardó realmente cada comanda en salir.",
        ],
        linkLabel: "Ver los tiempos",
        mockup: {
          label: "Turno noche",
          title: "Tiempos de preparación",
          rows: [
            { left: "Promedio de salida", right: "9 min" },
            { left: "Comanda más lenta", right: "21 min", sub: ["#38 · mesa de 8"] },
            { left: "Servidas", right: "84" },
          ],
        },
      },
    ],
  },
]

export function getModulo(slug: string): Modulo | undefined {
  return MODULOS.find((m) => m.slug === slug)
}

/**
 * Entrada de módulo para menús y listados. Los que todavía no tienen
 * minipage propia van con `href: "#"`.
 */
export type ModuloLink = { label: string; description: string; href: string }

/** Grupos de módulos: los de base y los que definen un tipo de negocio. */
export type ModuloGroup = { key: string; label: string; items: ModuloLink[] }

export const MODULO_GROUPS: ModuloGroup[] = [
  {
    key: "base",
    label: "En todo negocio",
    items: [
      {
        label: "Punto de Venta",
        description: "Vender en segundos, con o sin internet",
        href: "/modulos/punto-de-venta",
      },
      {
        label: "Panel de administración",
        description: "Ventas, stock y reportes de todas las sucursales",
        href: "/modulos/panel",
      },
      {
        label: "Punto AI",
        description: "El asistente que analiza tus datos y responde",
        href: "/modulos/punto-ai",
      },
      {
        label: "Facturación electrónica",
        description: "El comprobante se emite y se envía solo",
        href: "#",
      },
    ],
  },
  {
    key: "gastronomia",
    label: "Para gastronomía",
    items: [
      {
        label: "Mesas y órdenes",
        description: "Cuenta por mesa y rondas que se suman",
        href: "/modulos/mesas-y-ordenes",
      },
      {
        label: "Pantalla de cocina",
        description: "La comanda entra sola a cada estación",
        href: "/modulos/pantalla-de-cocina",
      },
      {
        label: "Producción y recetas",
        description: "La receta descuenta insumos y calcula el costo",
        href: "#",
      },
    ],
  },
  {
    key: "comercio",
    label: "Para comercio",
    items: [
      {
        label: "Stock y compras",
        description: "Existencias por depósito y costos al día",
        href: "#",
      },
      {
        label: "Clientes y crédito",
        description: "Cuenta corriente con límite y cobranzas",
        href: "#",
      },
      {
        label: "Gift cards y vales",
        description: "Cobrar hoy lo que se entrega después",
        href: "/modulos/gift-cards",
      },
    ],
  },
]

/** Módulos que destaca cada rubro, para el bloque de su minipage. */
export const RUBRO_MODULOS: Record<string, string[]> = {
  restaurantes: ["mesas-y-ordenes", "pantalla-de-cocina", "punto-de-venta", "panel"],
  "bares-y-pubs": ["mesas-y-ordenes", "pantalla-de-cocina", "punto-de-venta", "panel"],
  cafeterias: ["punto-de-venta", "mesas-y-ordenes", "gift-cards", "panel"],
  panaderias: ["punto-de-venta", "pantalla-de-cocina", "panel", "punto-ai"],
  heladerias: ["punto-de-venta", "mesas-y-ordenes", "gift-cards", "panel"],
  minimarkets: ["punto-de-venta", "panel", "punto-ai", "gift-cards"],
  farmacias: ["punto-de-venta", "panel", "punto-ai"],
  ferreterias: ["punto-de-venta", "panel", "punto-ai"],
  "tiendas-de-ropa": ["punto-de-venta", "panel", "gift-cards", "punto-ai"],
}

export function modulosForRubro(slug: string): Modulo[] {
  return (RUBRO_MODULOS[slug] ?? [])
    .map((s) => getModulo(s))
    .filter((m): m is Modulo => Boolean(m))
}
