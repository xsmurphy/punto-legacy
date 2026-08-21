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
]

export function getModulo(slug: string): Modulo | undefined {
  return MODULOS.find((m) => m.slug === slug)
}
