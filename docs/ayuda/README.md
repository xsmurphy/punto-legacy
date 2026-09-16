# Documentación de ayuda para clientes de Punto

Esta carpeta es la base de conocimiento de uso de Punto para dos lectores distintos:

1. **El comerciante** (dueño, cajero o administrativo) que busca cómo hacer algo puntual en el sistema.
2. **El asistente de atención de Punto**, que indexa cada artículo y responde citándolo.

Por eso cada artículo es **autocontenido**: no asume que el lector leyó otro artículo antes, nombra las pantallas tal como aparecen en el panel, y no usa nada técnico (nombres de tablas, endpoints, códigos de error, jerga de programación).

## Cómo está organizada

Un archivo por artículo, nombrado `NN-slug.md`. El número de dos dígitos agrupa por bloque temático:

| Rango | Bloque |
|---|---|
| `10-x` | Primeros pasos |
| `20-x` | Ventas y caja |
| `30-x` | Catálogo y stock |
| `40-x` | Compras |
| `50-x` | Contactos y cobranzas |
| `60-x` | Reportes |
| `70-x` | Facturación electrónica |
| `80-x` | Configuración |

## Cómo escribir un artículo nuevo

### Fuente de verdad

Antes de escribir, confirmá que la funcionalidad existe leyendo `context/modules/` (un doc por módulo — empezá por `context/modules/_index.md`) y, si hace falta ver nombres de pantalla exactos, `frontend/lib/navigation/routes.ts`. **Si no podés confirmar que algo existe o está implementado, no lo documentes.** Varios docs de `context/` narran planes o decisiones "sin implementar todavía" mezclados con lo que sí funciona hoy — separá bien antes de escribir.

### Frontmatter obligatorio

```yaml
---
title: Cómo cobrar una venta en la caja
slug: cobrar-una-venta-en-la-caja
modulo: pos
audiencia: cajero        # cajero | dueño | administrativo
keywords: [cobrar, venta, caja, pago, efectivo, tarjeta]
resumen: Una sola oración que responde de qué se trata el artículo.
---
```

`keywords` es lo que usa el asistente para encontrar el artículo: sumá los sinónimos que un comerciante realmente escribiría (por ejemplo "sucursal, local, tienda, punto de venta"), no solo el término técnico.

### Estilo — no negociable

- Español, tratamiento de "vos" ("entrá", "cargá", "hacé"). Neutro, sin modismos cerrados de un solo país — **excepto el bloque `70-x`**, que por naturaleza documenta un trámite específico de Paraguay (SIFEN) y sí puede usar su terminología, siempre explicándola.
- Nada técnico en pantalla: prohibido nombrar tablas, columnas, endpoints, archivos, códigos de error internos, etiquetas XML o proveedores internos del sistema.
- Nada de emojis.
- Nada hardcodeado a Paraguay fuera del bloque `70-x`: "tu moneda", "el documento fiscal de tu país", nunca "Gs" ni "RUC" como si fueran universales.
- Estructura: título H1 → párrafo de contexto (1-3 oraciones) → pasos numerados o secciones H2 → "Preguntas frecuentes" (2-5 pares) cuando aporte.
- Los pasos nombran la ruta de navegación completa tal como está en pantalla: "Entrá a Ventas › Transacciones".
- 60 a 200 líneas. Si un tema no entra, se parte en dos artículos linkeados entre sí con `[texto](20-cobrar-una-venta.md)` (link relativo).
- Sin capturas de pantalla.

## Índice

### Primeros pasos (10-x)

- [Qué es Punto y cómo está organizado](10-01-que-es-punto-y-como-esta-organizado.md)
- [Primer ingreso y navegación del panel](10-02-primer-ingreso-y-navegacion-del-panel.md)
- [Usuarios, roles y permisos](10-03-usuarios-roles-y-permisos.md)

### Ventas y caja (20-x)

- [Cómo cobrar una venta en la caja](20-01-cobrar-una-venta-en-la-caja.md)
- [Medios de pago y pagos combinados](20-02-medios-de-pago-y-pagos-combinados.md)
- [Apertura, cierre y arqueo de caja](20-03-apertura-cierre-y-arqueo-de-caja.md)
- [Anular una venta y emitir una nota de crédito](20-04-anular-una-venta-y-emitir-una-nota-de-credito.md)
- [Trabajar sin internet](20-05-trabajar-sin-internet.md)
- *Pendiente* — Órdenes, comandas y cocina
- *Pendiente* — Espacios y mesas
- *Pendiente* — Cotizaciones
- *Pendiente* — Gift cards y vales

### Catálogo y stock (30-x)

- [Cómo cargar artículos y categorías](30-01-cargar-articulos-y-categorias.md)
- [Precios y listas de precio](30-02-precios-y-listas-de-precio.md)
- [Control de stock y ajustes](30-03-control-de-stock-y-ajustes.md)
- [Transferencias entre depósitos](30-04-transferencias-entre-depositos.md)
- *Pendiente* — Combos y adicionales
- *Pendiente* — Producción y recetas
- *Pendiente* — Remisión (traslado de mercadería con documento)
- *Pendiente* — Impuestos y tasas por artículo

### Compras (40-x)

- [Cómo registrar una compra](40-01-registrar-una-compra.md)

### Contactos y cobranzas (50-x)

- [Clientes y proveedores](50-01-clientes-y-proveedores.md)
- [Ventas a crédito y cobranzas](50-02-ventas-a-credito-y-cobranzas.md)

### Reportes (60-x)

- [Qué reporte responde cada pregunta de tu negocio](60-01-que-reporte-responde-cada-pregunta-del-negocio.md)

### Facturación electrónica (70-x)

- [Qué es la facturación electrónica y qué necesitás para activarla](70-01-que-es-la-facturacion-electronica-y-que-necesitas.md)
- [Emitir, consultar y entregar el comprobante electrónico](70-02-emitir-consultar-y-entregar-el-comprobante.md)

### Configuración (80-x)

- [Cómo configurar sucursales y cajas](80-01-sucursales-y-cajas.md)
- [Cómo configurar impresoras y plantillas de impresión](80-02-impresoras-y-plantillas-de-impresion.md)

Los ítems marcados *Pendiente* corresponden a módulos que ya existen en Punto (ver `context/modules/_index.md`) pero todavía no tienen artículo de ayuda escrito.
