---
title: Impuestos y tasas por artículo
slug: impuestos-y-tasas-por-articulo
modulo: catalogo
audiencia: administrativo
keywords: [impuesto, iva, tasa, exento, gravado, precio incluye impuesto, precio mas impuesto, tributo]
resumen: Cómo definir los impuestos de tu comercio y asignarle a cada artículo la tasa que le corresponde.
---

# Impuestos y tasas por artículo

Cada artículo de tu catálogo puede tener un impuesto asociado (por ejemplo, el IVA de tu país), que se calcula automáticamente en cada venta. Podés tener más de una tasa a la vez — no todos los artículos tienen por qué pagar lo mismo.

## Crear las tasas de tu negocio

1. Entrá a [Configuración › Catálogo](panel:/settings/catalog?tab=taxes), pestaña "Impuestos".
2. Cargá cada tasa que uses: un nombre (por ejemplo, "Tasa general") y el porcentaje que corresponde.
3. Si tenés artículos que no pagan impuesto en absoluto, marcalos como exentos en lugar de ponerles una tasa del 0%. Son dos cosas distintas para el documento fiscal de tu país: un artículo con tasa 0% y uno exento no se reportan igual, aunque en el momento de la venta el cliente pague lo mismo por los dos.

## Asignar el impuesto a un artículo

1. Entrá a [Artículos › Catálogo](panel:/items) y abrí el artículo.
2. En la pestaña "Configuración", buscá la sección "Impuestos y descuentos".
3. Elegí el impuesto que le corresponde.
4. Indicá si el precio de venta que cargaste ya incluye el impuesto o si el impuesto se suma aparte. Esto define cómo se calcula el desglose en el ticket: con la primera opción, el impuesto se calcula "hacia adentro" del precio que ves en el catálogo; con la segunda, se suma al precio en el momento de cobrar.

Si no definís nada para un artículo puntual, se usa el criterio general configurado para tu comercio.

## Por qué una venta vieja no cambia si modificás una tasa

El impuesto de cada línea vendida queda fijado en el momento de la venta. Si más adelante cambiás el porcentaje de una tasa, las ventas ya hechas no se recalculan — siguen mostrando, si las reimprimís o las consultás, la tasa con la que se cobraron en su momento. Solo las ventas nuevas usan la tasa actualizada.

## Preguntas frecuentes

**¿Puedo tener artículos con distintas tasas en la misma venta?**
Sí, cada línea de la venta calcula su propio impuesto según la tasa de ese artículo puntual, y el ticket muestra el desglose agrupado por tasa.

**¿Qué diferencia hay entre "exento" y una tasa del 0%?**
Para el cliente, ninguna en el momento de pagar. Para los reportes fiscales de tu país sí son categorías distintas, así que conviene usar la que corresponda según cómo esté catalogado ese artículo.

**Si cambio si el precio "incluye" o "suma" el impuesto, ¿cambia el precio que ve el cliente?**
Puede cambiar el total, según cómo esté cargado el precio del artículo. Revisá el precio final después de tocar esta configuración.

## Ver también

- [Precios y listas de precio](30-02-precios-y-listas-de-precio.md)
- [Cómo cargar artículos y categorías](30-01-cargar-articulos-y-categorias.md)
