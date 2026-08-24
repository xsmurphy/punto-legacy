/**
 * Contenido de las minipages de módulo (`/modulos/[modulo]`).
 *
 * Misma idea que `rubros.ts`: config pura que el template renderiza. Acá
 * viven los tres protagonistas del producto (Punto de Venta, Panel y
 * Punto AI); los módulos menores se suman cuando se escriban.
 */

import type { MarketCode } from "@/lib/site/markets"
import { getMarket } from "@/lib/site/markets"
import type { RubroMockup } from "@/lib/site/rubros"

export type ModuloSection = {
  kicker: string
  title: string
  paragraphs: string[]
  linkLabel: string
  /** Mockup compuesto con tokens. Se ignora si la sección trae `image`. */
  mockup: RubroMockup
  /**
   * Captura real para ilustrar la sección. Pensada para recortes de
   * pantalla (un gráfico, un panel), no para capturas completas — esas van
   * al hero o al spotlight.
   */
  image?: { src: string; alt: string }
  /**
   * Destino del link de cierre. Cuando la sección habla de otro módulo,
   * apunta a su página; sin destino, el link no se dibuja (nada de flechas
   * que no llevan a ningún lado).
   */
  linkHref?: string
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
  /**
   * Mercados donde este módulo se ofrece con ESTE contenido. Sin la marca,
   * vale para todos. La facturación electrónica, por ejemplo, nombra a
   * SIFEN: en otro país ese contenido no aplica y habrá que escribir el
   * suyo.
   */
  mercados?: MarketCode[]
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
      src: "/site/pos-gastro-light.png",
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
        linkHref: "/modulos/facturacion-electronica",
        mockup: {
          label: "Caja 1",
          title: "Venta en curso",
          rows: [
            {
              left: "1× Lomito árabe",
              right: "{money:35000}",
              sub: ["Sin cebolla · Extra queso"],
            },
            { left: "2× Jugo de mburucuyá", right: "{money:24000}" },
            { left: "1× Papas rústicas", right: "{money:18000}" },
          ],
          footer: { left: "Cobrar", right: "{money:59000}" },
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
        linkHref: "/modulos/facturacion-electronica",
        mockup: {
          label: "Cobro",
          title: "Medios de pago",
          rows: [
            { left: "Efectivo", right: "{money:40000}" },
            { left: "Transferencia", right: "{money:19000}" },
            { left: "Vuelto", right: "{money:0}" },
          ],
          footer: { left: "Total cobrado", right: "{money:59000}" },
        },
      },
      {
        kicker: "Del lado del cliente",
        title: "Una pantalla que muestra lo que se está cobrando",
        paragraphs: [
          "Un segundo monitor mirando al cliente le muestra lo que el cajero va cargando y el total a pagar, línea por línea. El que espera ve su cuenta armarse en vivo, sin tener que pedir el detalle.",
          "Sirve además como cartel del negocio entre venta y venta, y evita la discusión más común del mostrador: qué se cobró y por cuánto.",
        ],
        linkLabel: "Ver la pantalla del cliente",
        linkHref: "/modulos/punto-de-venta",
        image: {
          src: "/site/customer-display.png",
          alt: "Pantalla del cliente de Punto mostrando el total y los artículos",
        },
        mockup: {
          label: "Pantalla del cliente",
          title: "Total a pagar",
          rows: [
            { left: "1× Pizza 4 quesos", right: "{money:70000}" },
            { left: "2× Pizza peperoni", right: "{money:150000}" },
            { left: "1× Café americano", right: "{money:13000}" },
          ],
          footer: { left: "Total", right: "{money:233000}" },
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
        linkHref: "/modulos/panel",
        mockup: {
          label: "Sin conexión",
          title: "Ventas en espera",
          rows: [
            {
              left: "Ticket #482",
              right: "{money:59000}",
              sub: ["emitido 19:41"],
            },
            {
              left: "Ticket #483",
              right: "{money:118000}",
              sub: ["emitido 19:47"],
            },
            {
              left: "Ticket #484",
              right: "{money:74000}",
              sub: ["emitido 19:52"],
            },
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
        linkHref: "/modulos/panel",
        mockup: {
          label: "Turno noche · Caja 2",
          title: "Arqueo de caja",
          rows: [
            { left: "Apertura", right: "{money:500000}" },
            { left: "Ventas en efectivo", right: "{money:2140000}" },
            { left: "Retiros", right: "{money:-300000}" },
          ],
          footer: { left: "Esperado", right: "{money:2340000}" },
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
        linkHref: "/modulos/punto-ai",
        mockup: {
          label: "Hoy",
          title: "Ventas por sucursal",
          rows: [
            { left: "Centro", right: "{money:4180000}", sub: ["92 tickets"] },
            {
              left: "Villa Morra",
              right: "{money:2940000}",
              sub: ["61 tickets"],
            },
            {
              left: "San Lorenzo",
              right: "{money:1300000}",
              sub: ["31 tickets"],
            },
          ],
          footer: { left: "Total del día", right: "{money:8420000}" },
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
        linkHref: "/modulos/stock-y-compras",
        mockup: {
          label: "Artículo",
          title: "Café en grano 1kg",
          rows: [
            { left: "Mostrador", right: "{money:95000}" },
            {
              left: "Mayorista",
              right: "{money:78000}",
              sub: ["desde 6 unidades"],
            },
            { left: "Costo", right: "{money:52000}", sub: ["margen 45%"] },
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
        kicker: "Números, no intuición",
        title: "Reportes listos al abrir la pantalla",
        paragraphs: [
          "Ventas por hora, ranking de productos, márgenes, medios de pago, cuentas por cobrar y los libros que pide el contador. Todo sale del mismo dato que generó la caja, sin exportar ni cruzar planillas.",
        ],
        linkLabel: "Ver los reportes",
        linkHref: "/modulos/punto-ai",
        image: {
          src: "/site/reportes-stats.png",
          alt: "Reportes de Punto: margen, ingresos y egresos del período",
        },
        mockup: {
          label: "Este mes",
          title: "Lo más vendido",
          rows: [
            {
              left: "Empanada de carne",
              right: "{money:1278000}",
              sub: ["142 unidades"],
            },
            {
              left: "Café con leche",
              right: "{money:1470000}",
              sub: ["98 unidades"],
            },
            {
              left: "Combo desayuno",
              right: "{money:1586000}",
              sub: ["61 unidades"],
            },
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
          '"¿Cómo viene el mes contra el anterior?", "¿qué producto me deja más margen?", "¿qué clientes no volvieron en 60 días?". Punto AI entiende la pregunta, busca en tu información y contesta con el número concreto — más el gráfico cuando ayuda a verlo.',
          "No hay que aprender dónde vive cada reporte ni qué filtro combinar: la conversación reemplaza el recorrido por los menús.",
        ],
        linkLabel: "Ver una respuesta",
        linkHref: "/modulos/panel",
        mockup: {
          label: "Punto AI",
          title: "¿Cómo viene la semana?",
          rows: [
            {
              left: "Ventas 01 al 09",
              right: "{money:2310000}",
              sub: ["8 días con ventas"],
            },
            { left: "Día pico", right: "{money:495000}", sub: ["08/06"] },
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
        linkHref: "/modulos/panel",
        mockup: {
          label: "Hallazgos",
          title: "Qué mirar esta semana",
          rows: [
            {
              left: "Margen en bebidas",
              right: "-6 pts",
              sub: ["subió el costo del proveedor"],
            },
            {
              left: "Martes",
              right: "-38%",
              sub: ["contra el resto de la semana"],
            },
            {
              left: "Clientes sin volver",
              right: "9",
              sub: ["compraban cada 15 días"],
            },
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
        linkHref: "/modulos/stock-y-compras",
        mockup: {
          label: "Confirmación",
          title: "Crear artículo",
          rows: [
            { left: "Nombre", right: "Medialuna de manteca" },
            { left: "Categoría", right: "Panadería" },
            { left: "Precio", right: "{money:6000}" },
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
        linkHref: "/modulos/pantalla-de-cocina",
        mockup: {
          label: "Salón",
          title: "Mesas abiertas",
          rows: [
            {
              left: "Mesa 3",
              right: "{money:128000}",
              sub: ["Ocupada · 25 min"],
            },
            {
              left: "Mesa 7",
              right: "{money:96000}",
              sub: ["Pidió la cuenta"],
            },
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
        linkHref: "/modulos/pantalla-de-cocina",
        mockup: {
          label: "Cocina · en preparación",
          title: "Cola de comandas",
          rows: [
            {
              left: "#47 · Mesa 3",
              right: "hace 4 min",
              sub: ["2× Lomito · sin cebolla"],
            },
            {
              left: "#48 · Mesa 7",
              right: "hace 1 min",
              sub: ["1× Costilla al horno"],
            },
            {
              left: "#49 · Barra",
              right: "recién",
              sub: ["2× Gin tonic", "1× Picada chica"],
            },
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
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Mesa 3 · 3 personas",
          title: "Dividir la cuenta",
          rows: [
            {
              left: "Pagó Gladys",
              right: "{money:45000}",
              sub: ["por sus ítems"],
            },
            { left: "Pagó Osvaldo", right: "{money:45000}", sub: ["efectivo"] },
            { left: "Pendiente", right: "{money:38000}" },
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
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Cobro",
          title: "Gift card aplicada",
          rows: [
            { left: "Total de la venta", right: "{money:185000}" },
            {
              left: "Gift card GC-4821",
              right: "{money:-150000}",
              sub: ["saldo restante {money:0}"],
            },
            { left: "Efectivo", right: "{money:35000}" },
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
        linkHref: "/modulos/punto-de-venta",
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
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "GC-4821",
          title: "Historial del código",
          rows: [
            {
              left: "Vendida",
              right: "{money:150000}",
              sub: ["Centro · 02/08"],
            },
            {
              left: "Usada",
              right: "{money:150000}",
              sub: ["Villa Morra · 19/08"],
            },
            { left: "Saldo", right: "{money:0}" },
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
      src: "/site/kds.png",
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
        kicker: "De dónde entra",
        title: "Mostrador, mesa o envío, todo llega al mismo tablero",
        paragraphs: [
          "La comanda puede nacer en la caja del mostrador, en una mesa del salón o en un pedido para envío. Sea cual sea el origen, entra a la pantalla con su número, su hora y de dónde viene — la cocina no necesita preguntar para qué es cada cosa.",
          "El que toma el pedido no manda nada aparte: al confirmar la orden, la comanda ya está en cocina. Nadie transcribe, nadie camina hasta la plancha con un papel.",
        ],
        linkLabel: "Ver mesas y órdenes",
        linkHref: "/modulos/mesas-y-ordenes",
        mockup: {
          label: "Entradas de hoy",
          title: "Origen de las comandas",
          rows: [
            { left: "Mostrador", right: "64", sub: ["caja 1 y caja 2"] },
            { left: "Mesas del salón", right: "38", sub: ["12 espacios"] },
            { left: "Para envío", right: "17" },
          ],
        },
      },
      {
        kicker: "Cada estación, su tablero",
        title: "La cocina ve platos; la barra, tragos",
        paragraphs: [
          "Un pedido de mesa puede repartirse entre tres estaciones y cada una recibe solo su parte. El que arma tragos no tiene que leer la comanda entera para encontrar lo suyo, y nadie prepara dos veces lo mismo.",
          "El orden lo pone la hora de entrada, no quién grita más fuerte: la tanda se arma por antigüedad y el tiempo de espera de cada comanda está a la vista.",
        ],
        linkLabel: "Ver el tablero",
        linkHref: "/modulos/mesas-y-ordenes",
        mockup: {
          label: "Cocina · en preparación",
          title: "Tablero por estación",
          rows: [
            {
              left: "#47 · Mesa 3",
              right: "4 min",
              sub: ["2× Lomito · sin cebolla"],
            },
            {
              left: "#48 · Mostrador",
              right: "2 min",
              sub: ["1× Milanesa · con papas"],
            },
            {
              left: "#49 · Mesa 7",
              right: "recién",
              sub: ["1× Costilla al horno"],
            },
            {
              left: "#49 · Mesa 7",
              right: "1 min",
              sub: ["1× Costilla al horno"],
            },
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
        linkHref: "/modulos/mesas-y-ordenes",
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
        kicker: "La salida",
        title: "La pantalla de despacho arma el pedido completo",
        paragraphs: [
          "Cocina prepara por estación, pero el cliente se lleva el pedido entero. La pantalla de despacho muestra cada orden con todo lo que la compone y en qué anda: en espera, en proceso o lista para salir.",
          "Quien entrega mira una sola columna y sabe qué está pronto y qué falta, sin ir a preguntar a la barra si el trago ya salió. El mostrador entrega completo o no entrega.",
        ],
        linkLabel: "Ver el despacho",
        image: {
          src: "/site/despacho.png",
          alt: "Pantalla de despacho de Punto con las órdenes en espera, en proceso y listas",
        },
        mockup: {
          label: "Despacho",
          title: "Órdenes por estado",
          rows: [
            { left: "En espera", right: "7" },
            { left: "En proceso", right: "3" },
            { left: "Listas para entregar", right: "2", sub: ["#27 y #28"] },
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
        linkHref: "/modulos/panel",
        mockup: {
          label: "Turno noche",
          title: "Tiempos de preparación",
          rows: [
            { left: "Promedio de salida", right: "9 min" },
            {
              left: "Comanda más lenta",
              right: "21 min",
              sub: ["#38 · mesa de 8"],
            },
            { left: "Servidas", right: "84" },
          ],
        },
      },
    ],
  },

  {
    slug: "facturacion-electronica",
    label: "Facturación electrónica",
    eyebrow: "Facturación electrónica",
    mercados: ["PY"],
    heroTitle: "Facturación electrónica gratis y sin límites",
    heroDescription:
      "Emitís todos los documentos electrónicos que tu negocio necesite y no te cobramos ni uno. Sin paquetes de comprobantes, sin cupos mensuales y sin sorpresas cuando el mes viene bueno: la factura sale de la misma venta y viaja sola a SIFEN.",
    heroImage: {
      src: "/site/pos-success.png",
      alt: "Punto confirmando el cobro de una venta",
    },
    essentials: [
      "Documentos electrónicos ilimitados, sin costo por comprobante.",
      "La factura se arma con la venta: el vendedor no carga nada dos veces.",
      "Factura, autofactura y nota de crédito electrónica, con su CDC y su KuDE.",
      "El estado de cada documento se ve en el panel, uno por uno.",
    ],
    sections: [
      {
        kicker: "Sin cupos ni paquetes",
        title: "Facturar de más no te sale más caro",
        paragraphs: [
          "En Paraguay lo normal es pagar por tandas de documentos: mil comprobantes, cinco mil, y cuando se acaban hay que comprar otra. El negocio termina midiendo cuánto factura contra cuánto le queda de paquete — justo al revés de lo que debería preocuparle.",
          "En Punto no funciona así. Emitir un documento electrónico no nos cuesta, y por eso no te lo cobramos: facturás lo que vendas, todos los meses, sin contar comprobantes ni renovar nada.",
        ],
        linkLabel: "Ver el plan completo",
        linkHref: "/precios",
        mockup: {
          label: "Este mes",
          title: "Documentos emitidos",
          rows: [
            { left: "Facturas electrónicas", right: "1.284" },
            { left: "Notas de crédito", right: "37" },
            { left: "Costo por documento", right: "{money:0}" },
          ],
          footer: { left: "Total facturado al sistema", right: "{money:0}" },
        },
      },
      {
        kicker: "Sale de la venta",
        title: "El comprobante no es un trámite aparte",
        paragraphs: [
          "El cajero cobra como siempre y, si el cliente da su RUC, el documento electrónico se genera con esa misma venta: los ítems, las tasas de IVA que realmente se cobraron y el total que cierra exacto contra el ticket.",
          "Punto declara la tasa que se aplicó en el momento de vender, no la que figura hoy en el catálogo. Si cambiás un precio o una tasa después, los documentos ya emitidos siguen contando la verdad de esa venta.",
        ],
        linkLabel: "Ver la venta facturada",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Factura electrónica",
          title: "001-001-0000482",
          rows: [
            { left: "González e Hijos S.A.", sub: ["{docFiscal} 80012345-6"] },
            { left: "Gravadas 10%", right: "{money:450000}" },
            { left: "IVA 10%", right: "{money:45000}" },
          ],
          footer: { left: "Total del documento", right: "{money:495000}" },
        },
      },
      {
        kicker: "Aprobado o pendiente, siempre visible",
        title: "Sabés en qué estado está cada documento",
        paragraphs: [
          "Cada documento muestra si SIFEN lo aprobó, si está en camino o si algo lo frenó, con su CDC a la vista y el KuDE listo para descargar o mandarle al cliente.",
          "Si el envío falla — se cayó la conexión, el servicio no responde — el documento se reintenta solo. La venta nunca queda trabada esperando al fisco: se cobra igual y el comprobante se acomoda después.",
        ],
        linkLabel: "Ver los documentos",
        linkHref: "/modulos/panel",
        mockup: {
          label: "Documentos electrónicos",
          title: "Estado de hoy",
          rows: [
            {
              left: "001-001-0000482",
              right: "Aprobado",
              sub: ["CDC disponible"],
            },
            { left: "001-001-0000483", right: "Aprobado" },
            {
              left: "001-001-0000484",
              right: "En proceso",
              sub: ["reintenta solo"],
            },
          ],
        },
      },
      {
        kicker: "Cuando hay que corregir",
        title: "Nota de crédito electrónica, atada a su factura",
        paragraphs: [
          "Una devolución no se resuelve borrando la factura: se emite la nota de crédito electrónica vinculada al documento original, con las tasas congeladas de esa venta.",
          "Y si un documento tiene que anularse ante SIFEN, se cancela desde el panel indicando el motivo — con permiso propio, para que no lo haga cualquiera desde la caja.",
        ],
        linkLabel: "Ver una nota de crédito",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Nota de crédito",
          title: "Sobre 001-001-0000482",
          rows: [
            { left: "1× Café en grano 1kg", right: "{money:95000}" },
            { left: "Motivo", right: "Devolución" },
            { left: "Cliente", right: "González e Hijos S.A." },
          ],
          footer: { left: "Total acreditado", right: "{money:95000}" },
        },
      },
    ],
  },
  {
    slug: "stock-y-compras",
    label: "Stock y compras",
    eyebrow: "Stock y compras",
    heroTitle: "Saber qué hay, antes de que falte",
    heroDescription:
      "Cada venta descuenta y cada compra repone. La factura del proveedor se carga sacándole una foto — la IA extrae los artículos, las cantidades y los precios — y el costo queda al día sin tipear una línea.",
    heroImage: {
      src: "/site/item-profile.png",
      alt: "Ficha de un artículo en Punto, con su precio, IVA y stock por sucursal",
    },
    essentials: [
      "Saldo por depósito y por sucursal, actualizado con cada movimiento.",
      "La factura del proveedor se carga con una foto: la IA extrae los datos y vos aprobás.",
      "La compra ingresa la mercadería con el costo real y deja la deuda al proveedor.",
      "Mínimos con aviso, para reponer antes del quiebre y no después.",
    ],
    sections: [
      {
        kicker: "Una sola aritmética",
        title: "Venta, compra, producción y ajuste tocan el mismo saldo",
        paragraphs: [
          "Vender, comprar, producir, transferir entre sucursales, registrar una merma o cerrar un conteo modifican el inventario por el mismo camino. Por eso el número no diverge según por dónde entró el movimiento — y el costo de lo vendido tampoco.",
          "Cada movimiento queda registrado con su motivo, su usuario y su hora. Cuando el saldo no cuadra, se puede ver exactamente qué pasó en vez de suponerlo.",
        ],
        linkLabel: "Ver el historial de un artículo",
        linkHref: "/modulos/panel",
        mockup: {
          label: "Café en grano 1kg",
          title: "Últimos movimientos",
          rows: [
            {
              left: "Compra",
              right: "+24",
              sub: ["hoy · costo {money:52000}"],
            },
            { left: "Ventas del día", right: "-9" },
            { left: "Ajuste por rotura", right: "-1", sub: ["Elvira · 14:20"] },
          ],
          footer: { left: "Saldo", right: "38" },
        },
      },
      {
        kicker: "Sacale una foto",
        title: "La factura del proveedor se carga sola, con IA",
        paragraphs: [
          "Cargar una compra a mano es tipear veinte líneas mirando un papel. En Punto le sacás una foto a la factura — o subís el PDF — y la IA extrae el proveedor, el número de comprobante, cada artículo con su cantidad, su precio y su IVA.",
          "Lo que sale es un borrador para revisar, no un movimiento hecho: corregís lo que haga falta y recién al aprobarlo entra la mercadería al stock. La IA nunca toca el inventario ni la caja por su cuenta.",
        ],
        linkLabel: "Ver el borrador de una factura",
        linkHref: "/modulos/punto-ai",
        mockup: {
          label: "Borrador · extraído de la foto",
          title: "Distribuidora del Este",
          rows: [
            { left: "24× Café en grano 1kg", right: "{money:1248000}" },
            { left: "12× Azúcar 1kg", right: "{money:96000}" },
            { left: "Condición", right: "Crédito 30 días" },
          ],
          footer: { left: "Total", right: "{money:1344000}" },
        },
      },
      {
        kicker: "Del papel al inventario",
        title: "Aprobada la compra, el costo queda al día",
        paragraphs: [
          "Al aprobar el borrador entra la mercadería con el costo real de esta compra, y el margen de cada artículo se recalcula con ese número. Si fue a crédito, la deuda con el proveedor aparece sola en cuentas por pagar, con su vencimiento.",
          "El alta manual sigue disponible y termina en el mismo lugar: haya venido de una foto o de la carga a mano, la compra es una sola cosa en el sistema.",
        ],
        linkLabel: "Ver cuentas por pagar",
        linkHref: "/modulos/clientes-y-credito",
        mockup: {
          label: "Cuentas por pagar",
          title: "Vencimientos próximos",
          rows: [
            {
              left: "Distribuidora del Este",
              right: "{money:1344000}",
              sub: ["vence en 12 días"],
            },
            {
              left: "Lácteos del Sur",
              right: "{money:480000}",
              sub: ["vence en 3 días"],
            },
            {
              left: "Panificados Ñemity",
              right: "{money:265000}",
              sub: ["vence en 20 días"],
            },
          ],
          footer: { left: "Total", right: "{money:1824000}" },
        },
      },
      {
        kicker: "Antes del quiebre",
        title: "El mínimo avisa mientras todavía hay tiempo",
        paragraphs: [
          "Cada artículo puede tener su mínimo por depósito. Cuando lo toca, aparece en la lista de reposición — no cuando ya se acabó y el cliente se fue con las manos vacías.",
          "La misma lista sirve para armar el pedido al proveedor, así reponer deja de depender de que alguien se acuerde de mirar la góndola.",
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
    ],
  },
  {
    slug: "clientes-y-credito",
    label: "Clientes y crédito",
    eyebrow: "Clientes y crédito",
    heroTitle: "La libreta del mostrador, jubilada",
    heroDescription:
      "Quién compró, qué se llevó y cuánto debe, con su límite y sus recibos. Un pago puede saldar varias facturas de una vez, y el saldo sale siempre de los movimientos reales, no de un número escrito a mano.",
    heroImage: {
      src: "/site/cliente-comportamiento.png",
      alt: "Perfil de un cliente en Punto, con sus horarios, medios de pago y sucursales",
    },
    essentials: [
      "Cuenta corriente con límite por cliente y aviso al superarlo.",
      "Un cobro se reparte entre varias facturas pendientes de una sola vez.",
      "Cada cobro deja su recibo, y uno mal cargado se revierte sin romper el saldo.",
      "El perfil del cliente muestra qué compra, cuándo y en qué sucursal.",
    ],
    sections: [
      {
        kicker: "El saldo no se escribe, se calcula",
        title: "Lo que debe un cliente sale de sus movimientos",
        paragraphs: [
          "El pendiente de cada factura se recalcula sumando lo que se cobró contra ella, en vez de guardarse en una columna que alguien puede tocar. Por eso el saldo del cliente y el de la factura nunca se contradicen.",
          "Si un cobro se cargó mal, se revierte y todo vuelve a su lugar solo — sin ajustes manuales que después nadie sabe explicar.",
        ],
        linkLabel: "Ver un estado de cuenta",
        linkHref: "/modulos/panel",
        mockup: {
          label: "Cuenta corriente",
          title: "Elvira Ruiz",
          rows: [
            {
              left: "Factura 001-0000482",
              right: "{money:180000}",
              sub: ["vence en 6 días"],
            },
            {
              left: "Factura 001-0000501",
              right: "{money:95000}",
              sub: ["vence en 21 días"],
            },
            { left: "Límite", right: "{money:500000}" },
          ],
          footer: { left: "Saldo", right: "{money:275000}" },
        },
      },
      {
        kicker: "Cobrar de una vez",
        title: "Un pago, todas las facturas que alcance",
        paragraphs: [
          "El cliente que viene a saldar tres facturas no obliga a cargar tres cobros: se ingresa el monto y el sistema lo reparte entre las pendientes, empezando por las más viejas.",
          "También se puede cobrar parcialmente una factura puntual. En los dos casos sale el recibo, y lo cobrado impacta en la caja del turno como cualquier otro ingreso.",
        ],
        linkLabel: "Ver un cobro",
        linkHref: "/modulos/punto-de-venta",
        mockup: {
          label: "Cobro",
          title: "Recibo 0000-0311",
          rows: [
            {
              left: "Factura 001-0000482",
              right: "{money:180000}",
              sub: ["saldada"],
            },
            {
              left: "Factura 001-0000501",
              right: "{money:70000}",
              sub: ["parcial · quedan {money:25000}"],
            },
            { left: "Medio de pago", right: "Efectivo" },
          ],
          footer: { left: "Total cobrado", right: "{money:250000}" },
        },
      },
      {
        kicker: "Conocer al que vuelve",
        title: "Qué compra cada cliente, cuándo y dónde",
        paragraphs: [
          "El perfil de cada cliente muestra su historia: qué se lleva, a qué hora suele venir, con qué paga y en qué sucursal compra. Sirve para decidir qué reponer, cuándo abrir y a quién conviene llamar.",
          "Los datos son del negocio, no de una plataforma: si el cliente dejó de venir, el sistema puede mostrarlo antes de que sea tarde.",
        ],
        linkLabel: "Ver el perfil de un cliente",
        linkHref: "/modulos/punto-ai",
        mockup: {
          label: "Comportamiento",
          title: "Albert Estanislao",
          rows: [
            { left: "Compras", right: "21", sub: ["última hace 3 días"] },
            { left: "Sucursal preferida", right: "Centro", sub: ["14 de 21"] },
            { left: "Suele venir", right: "martes 18:00" },
          ],
        },
      },
    ],
  },
  {
    slug: "produccion-y-recetas",
    label: "Producción y recetas",
    eyebrow: "Producción y recetas",
    heroTitle: "Lo que se produce descuenta lo que se usa",
    heroDescription:
      "Cargás la receta una vez y cada plato, torta o combo descuenta sus insumos al producirse o al venderse. El costo sale del insumo real, así sabés cuánto te deja cada cosa antes de fijar el precio.",
    heroImage: {
      src: "/site/item-profile.png",
      alt: "Ficha de un artículo compuesto en Punto, con sus componentes",
    },
    essentials: [
      "La receta define qué insumos y en qué cantidad lleva cada producto.",
      "Producción directa o previa: descontar al vender, o armar tandas y stockear.",
      "El costo del producto se calcula con el costo real de sus insumos.",
      "La merma se registra con su motivo, en vez de desaparecer del inventario.",
    ],
    sections: [
      {
        kicker: "Dos formas de producir",
        title: "Armar la tanda de madrugada o al momento de vender",
        paragraphs: [
          "Una panadería hornea a las cuatro de la mañana y stockea lo producido; una cocina arma el plato recién cuando lo piden. Punto soporta las dos: producción previa, donde la tanda entra al inventario como un artículo más, y directa, donde vender descuenta los insumos en ese mismo momento.",
          "Cada artículo elige su modelo y no se mezclan, así el inventario de insumos nunca se descuenta dos veces por lo mismo.",
        ],
        linkLabel: "Ver una orden de producción",
        linkHref: "/modulos/stock-y-compras",
        mockup: {
          label: "Producción de hoy",
          title: "Tanda de las 04:00",
          rows: [
            {
              left: "Pan francés × 80",
              right: "{money:96000}",
              sub: ["Harina 12kg · Levadura 200g"],
            },
            {
              left: "Facturas surtidas × 60",
              right: "{money:84000}",
              sub: ["Harina 6kg · Manteca 1.5kg"],
            },
            {
              left: "Chipa × 40",
              right: "{money:52000}",
              sub: ["Almidón 4kg · Queso 1.2kg"],
            },
          ],
          footer: { left: "Costo de la tanda", right: "{money:180000}" },
        },
      },
      {
        kicker: "El margen, antes de vender",
        title: "Cuánto cuesta cada plato, con números y no a ojo",
        paragraphs: [
          "El costo del producto se arma sumando sus insumos al costo con el que entraron. Cuando sube la harina, el costo del pan sube solo — y el margen que veías deja de ser el de hace tres meses.",
          "Con eso a la vista, subir un precio o cambiar una receta deja de ser una corazonada.",
        ],
        linkLabel: "Ver el costo de un producto",
        linkHref: "/modulos/panel",
        mockup: {
          label: "Milanesa napolitana",
          title: "Costo por porción",
          rows: [
            { left: "Precio de venta", right: "{money:45000}" },
            { left: "Costo de insumos", right: "{money:18500}" },
            { left: "Margen", right: "59%" },
          ],
        },
      },
      {
        kicker: "Lo que se pierde también cuenta",
        title: "La merma se registra, no se descuenta en silencio",
        paragraphs: [
          "El pan que sobró, la fruta que se pasó, la botella que se rompió: la merma entra con su motivo y su responsable, y sale del inventario como un movimiento más.",
          "Al final del mes se puede mirar cuánto se perdió y por qué, en vez de descubrir el faltante recién en el conteo.",
        ],
        linkLabel: "Ver la merma del mes",
        linkHref: "/modulos/stock-y-compras",
        mockup: {
          label: "Este mes",
          title: "Merma registrada",
          rows: [
            { left: "Pan del día anterior", right: "{money:210000}" },
            { left: "Fruta vencida", right: "{money:84000}" },
            { left: "Roturas", right: "{money:35000}" },
          ],
          footer: { left: "Total", right: "{money:329000}" },
        },
      },
    ],
  },
]

/** Módulos visibles en el mercado activo. */
export function modulosVisibles(): Modulo[] {
  const code = getMarket().code
  return MODULOS.filter((m) => !m.mercados || m.mercados.includes(code))
}

export function getModulo(slug: string): Modulo | undefined {
  return modulosVisibles().find((m) => m.slug === slug)
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
        description: "Documentos ilimitados, sin costo por comprobante",
        href: "/modulos/facturacion-electronica",
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
        href: "/modulos/produccion-y-recetas",
      },
    ],
  },
  {
    key: "comercio",
    label: "Para comercio",
    items: [
      {
        label: "Stock y compras",
        description: "La factura del proveedor se carga con una foto",
        href: "/modulos/stock-y-compras",
      },
      {
        label: "Clientes y crédito",
        description: "Cuenta corriente con límite y cobranzas",
        href: "/modulos/clientes-y-credito",
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
  restaurantes: [
    "mesas-y-ordenes",
    "pantalla-de-cocina",
    "punto-de-venta",
    "panel",
  ],
  "bares-y-pubs": [
    "mesas-y-ordenes",
    "pantalla-de-cocina",
    "punto-de-venta",
    "panel",
  ],
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
