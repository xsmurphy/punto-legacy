---
title: Producción y recetas
slug: produccion-y-recetas
modulo: catalogo
audiencia: administrativo
keywords: [produccion, receta, insumos, ingredientes, fabricar, lote, merma, desperdicio, armado, ensamble, manufactura]
resumen: Cómo cargar la receta de un producto que se arma con otros, y cómo fabricar lotes con su costo y su merma.
---

# Producción y recetas

Si vendés productos que se arman a partir de otros (un plato con sus ingredientes, un combo con sus componentes, un artículo que se ensambla), Producción te deja definir esa receta y controlar cómo se descuenta el inventario cuando se vende o se fabrica.

## Los dos modelos

Al crear un artículo, elegís entre dos tipos dentro de la categoría "Producción":

- **Producción directa**: el producto se arma en el momento de la venta. Cuando se vende, el sistema descuenta automáticamente los insumos de su receta — no hace falta fabricarlo antes.
- **Producción previa**: el producto se fabrica antes, en lotes, y queda con su propio stock. La venta descuenta ese stock ya fabricado, no los insumos — para vender tiene que haber lotes producidos con anticipación.

## Cargar la receta

1. Entrá a [Artículos › Catálogo](panel:/items) y abrí el producto (o creá uno nuevo eligiendo "Producción directa" o "Producción previa").
2. Andá a la pestaña "Producción".
3. Agregá los insumos que componen el producto, con la cantidad de cada uno.
4. Guardá. El costo del producto se calcula solo a partir del costo de sus insumos.

Una receta puede incluir, como insumo, otro producto que a su vez tiene su propia receta — el sistema baja nivel por nivel hasta los insumos reales.

## Fabricar un lote (producción previa)

1. Entrá a [Artículos › Producción](panel:/produccion).
2. Creá una nueva orden de producción: elegí el producto y la cantidad que planeás fabricar.
3. Al completarla, el sistema descuenta los insumos de la receta y da de alta el stock del producto terminado, con su costo real.
4. Si alguna unidad del lote salió fallada, registrala como merma con su motivo — esas unidades no entran al stock disponible.

## Registrar merma sin pasar por un lote

Si necesitás dar de baja stock por una pérdida puntual (se rompió, se venció, se descartó), registrala directamente desde [Artículos › Producción](panel:/produccion), indicando el producto, la cantidad y el motivo — sin necesidad de que venga de una orden de fabricación.

## Preguntas frecuentes

**¿El cajero ve la receta de un producto en la caja?**
No. En la caja se ve cuántas unidades se pueden armar con el stock disponible, pero no qué insumos la componen — esa información queda en el panel, no se expone en la venta.

**¿Qué diferencia hay con un combo?**
Un combo empaqueta productos completos a un precio propio y su composición sí se muestra al vender, porque es parte de lo que el cliente está comprando. Una receta de producción son los insumos internos con los que armás un producto — ver [Combos y adicionales](30-05-combos-y-adicionales.md).

**¿Se puede fabricar parcialmente un lote y completarlo después?**
No, una orden de producción se completa entera de una vez.

## Ver también

- [Combos y adicionales](30-05-combos-y-adicionales.md)
- [Control de stock y ajustes](30-03-control-de-stock-y-ajustes.md)
