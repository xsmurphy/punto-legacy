/**
 * Exporta el contenido del sitio a Markdown, un archivo por página.
 *
 * El destino (`content/sitio/`) alimenta al agente de atención al cliente:
 * se genera desde las MISMAS fuentes que renderiza el sitio, así que el
 * agente nunca responde con copy viejo. Regenerar con:
 *
 *   npm run export:content
 *
 * Node lo corre con type stripping nativo (v22.6+), sin dependencias.
 */

import { mkdir, rm, writeFile } from "node:fs/promises"
import { join } from "node:path"

import { MODULOS, MODULO_GROUPS, RUBRO_MODULOS } from "../lib/site/modulos.ts"
import { RUBROS, RUBRO_GRUPOS } from "../lib/site/rubros.ts"
import { MODULE_TABS, FEATURE_CARDS } from "../lib/site/modules.ts"
import { applyMarketTerms, getMarket, marketMoney } from "../lib/site/markets.ts"
import { CONTACTO } from "../lib/site/contacto.ts"
import { DOCUMENTOS_LEGALES, type DocumentoLegal } from "../lib/site/legal.ts"

const DESTINO = join(import.meta.dirname, "..", "..", "content", "sitio")
const market = getMarket()
const t = (s: string) => applyMarketTerms(s, market)

/** Encabezado YAML para que el agente sepa de dónde salió cada archivo. */
function front(meta: Record<string, string>) {
  const campos = Object.entries(meta)
    .map(([k, v]) => `${k}: ${JSON.stringify(v)}`)
    .join("\n")
  return `---\n${campos}\n---\n`
}

function seccionesToMd(
  secciones: {
    kicker: string
    title: string
    paragraphs: string[]
    linkLabel?: string
    linkHref?: string
    mockup?: { label?: string; title: string; rows: unknown[] }
  }[],
) {
  return secciones
    .map((s) => {
      const link = s.linkHref ? `\nVer también: ${s.linkLabel} → ${s.linkHref}\n` : ""
      return `## ${t(s.title)}\n\n_${t(s.kicker)}_\n\n${s.paragraphs
        .map((p) => t(p))
        .join("\n\n")}\n${link}`
    })
    .join("\n")
}

/**
 * Un documento legal a Markdown. Sale de la MISMA fuente que renderiza
 * `/terminos` y `/privacidad`, así que el agente nunca cita una cláusula
 * que ya cambió en el sitio.
 */
function legalToMd(doc: DocumentoLegal) {
  const cuerpo = doc.secciones
    .map((s, i) => {
      const bloques = [`## ${i + 1}. ${t(s.titulo)}`, s.parrafos.map(t).join("\n\n")]
      if (s.lista?.length) {
        bloques.push(s.lista.map((x) => `- ${t(x)}`).join("\n"))
      }
      if (s.tabla) {
        const head = `| ${s.tabla.headers.map(t).join(" | ")} |`
        const sep = `| ${s.tabla.headers.map(() => "---").join(" | ")} |`
        const filas = s.tabla.filas.map((f) => `| ${f.map(t).join(" | ")} |`)
        bloques.push([head, sep, ...filas].join("\n"))
      }
      return bloques.join("\n\n")
    })
    .join("\n\n")

  return `# ${doc.titulo}

_Última actualización: ${doc.actualizado}_

${t(doc.intro)}

${cuerpo}
`
}

async function main() {
  // Se borra todo lo generado, pero NUNCA los archivos editados a mano
  // (marcados con `editable` en el frontmatter, ej. faq-ventas.md).
  await mkdir(DESTINO, { recursive: true })
  const { readdir, readFile } = await import("node:fs/promises")
  for (const nombre of await readdir(DESTINO)) {
    const ruta = join(DESTINO, nombre)
    const texto = await readFile(ruta, "utf8").catch(() => "")
    if (/^editable:/m.test(texto)) continue
    await rm(ruta, { force: true })
  }

  const archivos: { nombre: string; contenido: string; titulo: string; url: string }[] = []

  /* ---------------------------------------------------------------- */
  /* Home                                                              */
  /* ---------------------------------------------------------------- */
  const tabs = MODULE_TABS.map(
    (tab) =>
      `### ${t(tab.title)}\n\n${t(tab.description)}\n\nPensado para: ${tab.idealFor}`,
  ).join("\n\n")
  const cards = FEATURE_CARDS.map((c) => `- **${c.title}** — ${t(c.description)}`).join("\n")

  archivos.push({
    nombre: "home.md",
    titulo: "Punto — Sistema de punto de venta y facturación electrónica",
    url: "/",
    contenido: `# Punto — Sistema de punto de venta y facturación electrónica

Punto es un sistema de punto de venta y gestión para comercios de ${market.pais}: vender, cobrar, facturar electrónicamente, controlar stock y clientes, y ver los números del negocio — todo en un mismo lugar.

## Los tres protagonistas

${MODULOS.filter((m) => ["punto-de-venta", "panel", "punto-ai"].includes(m.slug))
  .map((m) => `### ${m.label}\n\n${t(m.heroDescription)}\n\nPágina: /modulos/${m.slug}`)
  .join("\n\n")}

## Módulos del sistema

${tabs}

## Todo lo que viene incluido

${cards}

## Rubros

${RUBRO_GRUPOS.map(
  (g) =>
    `### ${g.label}\n\n${RUBROS.filter((r) => r.grupo === g.key)
      .map((r) => `- ${r.label} — /para/${r.slug}`)
      .join("\n")}`,
).join("\n\n")}
`,
  })

  /* ---------------------------------------------------------------- */
  /* Precios                                                           */
  /* ---------------------------------------------------------------- */
  const precio = marketMoney(market.plan.precio, market)
  const creditos = new Intl.NumberFormat("es-PY").format(market.plan.creditosIa)
  archivos.push({
    nombre: "precios.md",
    titulo: "Precios y planes",
    url: "/precios",
    contenido: `# Precios y planes

Un solo plan, con todo adentro. Sin versiones recortadas ni módulos que se desbloquean pagando de más.

- **Precio:** ${precio} ${market.plan.periodo}
- **Condición:** se paga mes a mes, sin contrato ni permanencia
${market.plan.badge ? `- **Estado:** ${market.plan.badge}\n` : ""}
## Qué incluye

- Facturación electrónica ilimitada, sin costo por comprobante ni cupos mensuales
- Usuarios ilimitados, cada uno con sus permisos
- Cajas ilimitadas por sucursal
- Productos ilimitados, con fotos y variantes
- Transacciones ilimitadas: no se cobra por ticket
- ${creditos} créditos de IA por mes, para preguntarle a Punto AI
- Soporte online 24/7

## Preguntas frecuentes

**¿El precio es por negocio o por sucursal?**
Por sucursal. Cada local paga ${precio} por mes y adentro no hay límites. Si abrís una segunda sucursal, se suma solo esa.

**¿La facturación electrónica se cobra aparte?**
No. Está incluida y es ilimitada — no se cobra por comprobante emitido ni se venden paquetes de facturas.

**¿Hay contrato o permanencia?**
No. Se paga mes a mes y se puede dar de baja cuando el cliente quiera; los datos son suyos y se los lleva cuando lo pida.

**¿Puedo ver el sistema antes de contratar?**
Sí. Se coordina una demostración con casos del rubro del cliente antes de decidir. No hay prueba gratuita autogestionada.

**¿Qué pasa si se corta internet?**
El punto de venta sigue funcionando: la venta se emite igual y se sincroniza sola cuando vuelve la conexión.

**¿Necesito comprar equipos especiales?**
No. Funciona en la computadora, tablet o teléfono que el comercio ya tenga, desde el navegador. Impresora de tickets y lector de código de barras son opcionales.

**¿Me ayudan a cargar mis productos?**
Sí. Hay acompañamiento en la puesta en marcha e importación de catálogo y clientes desde planilla.

**¿Qué son los créditos de IA y para qué alcanzan?**
Son el consumo de Punto AI. El plan incluye ${creditos} por mes, que cubren el uso normal de un comercio. Si el equipo lo usa mucho más, se pueden sumar créditos aparte.

**¿El precio promocional sube después?**
Se mantiene mientras la cuenta siga activa. Si cambia la lista, se avisa con anticipación.
`,
  })

  /* ---------------------------------------------------------------- */
  /* Contacto                                                          */
  /* ---------------------------------------------------------------- */
  archivos.push({
    nombre: "contacto.md",
    titulo: "Contacto",
    url: "/contacto",
    contenido: `# Contacto

- **WhatsApp:** ${CONTACTO.telefono}
- **Oficina:** ${CONTACTO.direccion}
- **Horario de respuesta:** de lunes a sábado se responde en el día; el soporte para clientes funciona 24/7.

No hay formulario de contacto en el sitio: el canal es WhatsApp o la visita a la oficina.
`,
  })

  /* ---------------------------------------------------------------- */
  /* Legales                                                           */
  /* ---------------------------------------------------------------- */
  for (const doc of DOCUMENTOS_LEGALES) {
    archivos.push({
      nombre: `${doc.url.replace(/^\//, "")}.md`,
      titulo: doc.titulo,
      url: doc.url,
      contenido: legalToMd(doc),
    })
  }

  /* ---------------------------------------------------------------- */
  /* Módulos                                                           */
  /* ---------------------------------------------------------------- */
  for (const m of MODULOS) {
    const grupo = MODULO_GROUPS.find((g) =>
      g.items.some((i) => i.href === `/modulos/${m.slug}`),
    )
    archivos.push({
      nombre: `modulo-${m.slug}.md`,
      titulo: m.label,
      url: `/modulos/${m.slug}`,
      contenido: `# ${m.label}

**${t(m.heroTitle)}**

${t(m.heroDescription)}

${grupo ? `Grupo: ${grupo.label}\n` : ""}${m.mercados ? `Disponible en: ${m.mercados.join(", ")}\n` : ""}
## Lo esencial

${m.essentials.map((e) => `- ${t(e)}`).join("\n")}

${seccionesToMd(m.sections)}`,
    })
  }

  /* ---------------------------------------------------------------- */
  /* Rubros                                                            */
  /* ---------------------------------------------------------------- */
  for (const r of RUBROS) {
    const grupo = RUBRO_GRUPOS.find((g) => g.key === r.grupo)
    const modulos = (RUBRO_MODULOS[r.slug] ?? [])
      .map((slug) => MODULOS.find((m) => m.slug === slug))
      .filter(Boolean)

    archivos.push({
      nombre: `rubro-${r.slug}.md`,
      titulo: `Punto para ${r.label.toLowerCase()}`,
      url: `/para/${r.slug}`,
      contenido: `# Punto para ${r.label.toLowerCase()}

**${t(r.heroTitle)}**

${t(r.heroDescription)}

Grupo: ${grupo?.label ?? "—"}

${
  r.thirtySeconds
    ? `## Lo esencial\n\n${r.thirtySeconds.map((s) => `- ${t(s)}`).join("\n")}\n`
    : ""
}
${r.sections ? seccionesToMd(r.sections) : ""}
${
  modulos.length
    ? `## Módulos que más usa\n\n${modulos
        .map((m) => `- ${m!.label} — /modulos/${m!.slug}`)
        .join("\n")}\n`
    : ""
}`,
    })
  }

  /* ---------------------------------------------------------------- */
  /* Escritura + índice                                                */
  /* ---------------------------------------------------------------- */
  for (const a of archivos) {
    const contenido =
      front({ titulo: a.titulo, url: a.url, fuente: "sitio punto.la" }) +
      "\n" +
      a.contenido.trim() +
      "\n"
    await writeFile(join(DESTINO, a.nombre), contenido, "utf8")
  }

  const manuales = [{ nombre: "faq-ventas.md", titulo: "Preguntas de venta que el sitio no responde" }]

  const indice = `---
titulo: "Índice del contenido del sitio"
fuente: "sitio punto.la"
---

# Contenido del sitio de Punto

Generado desde el código del sitio con \`npm run export:content\`.

## Páginas del sitio (no editar — se sobreescriben)

${archivos.map((a) => `- [${a.titulo}](${a.nombre}) — ${a.url}`).join("\n")}

## Contenido editado a mano (persiste entre corridas)

${manuales.map((m) => `- [${m.titulo}](${m.nombre})`).join("\n")}
`
  await writeFile(join(DESTINO, "_index.md"), indice, "utf8")

  console.log(`${archivos.length + 1} archivos escritos en content/sitio/`)
}

main()
