---
title: Combos y adicionales
slug: combos-y-adicionales
modulo: catalogo
audiencia: administrativo
keywords: [combo, adicional, adicionales, extras, opciones, agregados, tamaño, variante de venta, promo, paquete]
resumen: Cómo armar productos con opciones que elige el cliente (adicionales) y productos empaquetados a precio propio (combos).
---

# Combos y adicionales

Dos formas de ofrecer productos armados a partir de otros:

- **Adicionales**: opciones que el cliente elige en el momento de la venta (tamaño, guarnición, extras), con o sin recargo.
- **Combo**: un producto que empaqueta otros a un precio propio, sin que el cliente elija nada.

## Adicionales: cómo configurarlos

1. Entrá a Artículos › Catálogo y abrí el producto al que le querés sumar opciones.
2. Andá a la pestaña "Componentes".
3. Creá un grupo de opciones (por ejemplo "Elegí tu bebida" o "Agregados"): ponele un nombre, y definí un mínimo y un máximo de opciones que el cliente puede elegir en ese grupo.
4. Cargá las opciones del grupo. Cada opción es un producto real de tu catálogo, con su propio precio y su propio stock.
5. Para cada opción, definí si suma un recargo al precio del producto o no. Si no suma nada, dejala en cero.
6. Si querés que una opción venga siempre incluida sin que el cliente tenga que elegirla (por ejemplo, la salsa que siempre lleva), marcala como fija.
7. Guardá. La próxima vez que se venda ese producto en la caja, se va a abrir el selector de opciones automáticamente.

Podés copiar los grupos de un producto a otro si varios productos comparten las mismas opciones — es una copia real: después podés editar cada uno por separado sin que se afecten entre sí.

## Cómo se vende con adicionales

En la caja, tocar un producto con opciones abre un selector antes de agregarlo al carrito. El cajero tiene que completar los mínimos de cada grupo antes de poder confirmar. El precio se actualiza en vivo a medida que se eligen opciones con recargo.

## Combo: cómo armarlo

1. Creá un producto nuevo en Artículos › Catálogo, con el precio al que se va a vender el combo.
2. Entrá a su pestaña "Producción" (o "Componentes", según el tipo de producto) y cargá los productos que lo componen, con la cantidad de cada uno.
3. Guardá. En la ficha del producto vas a ver el descuento implícito del combo: la diferencia entre lo que costaría comprar esos productos por separado y el precio fijado para el combo.

A diferencia de un adicional, el combo no le pide nada al cliente: se vende como un producto más, y su composición se muestra en la caja porque es parte de lo que el cliente está comprando (distinto de la receta de un producto de producción, que es información interna del negocio y no se muestra en la caja — ver [Producción y recetas](30-06-produccion-y-recetas.md)).

## Preguntas frecuentes

**¿Puedo dejar que el cliente repita la misma opción varias veces (por ejemplo, "2 salsas extra")?**
Sí, si definiste un máximo por opción mayor a uno. El mínimo y el máximo del grupo cuentan cuántas opciones distintas se eligieron; el máximo por opción cuenta cuántas veces se puede repetir la misma.

**¿El recargo de un adicional respeta descuentos o listas de precio?**
El recargo de cada opción es un valor fijo que se define al configurarla, independiente de descuentos aplicados a la venta.

**¿Puedo armar un combo con productos que a su vez tienen receta propia?**
Sí, el sistema descuenta el stock correspondiente en cada nivel: si un componente del combo se arma con insumos, esos insumos también se descuentan.

## Ver también

- [Cómo cargar artículos y categorías](30-01-cargar-articulos-y-categorias.md)
- [Producción y recetas](30-06-produccion-y-recetas.md)
