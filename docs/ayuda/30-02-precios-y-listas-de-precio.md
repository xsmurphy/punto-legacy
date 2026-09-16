---
title: Precios y listas de precio
slug: precios-y-listas-de-precio
modulo: catalogo
audiencia: administrativo
keywords: [precio, precio de venta, lista de precios, mayorista, descuento, recargo, precio especial, precio por cliente, precio por sucursal]
resumen: Cómo funciona el precio base de un artículo y cómo armar listas de precio para cobrar distinto según cliente o sucursal.
---

# Precios y listas de precio

Todo artículo tiene un precio base de venta, pero muchos comercios necesitan cobrar distinto según a quién le venden (por ejemplo, un precio mayorista) o desde qué sucursal. Para eso existen las listas de precio: te permiten ajustar el precio de catálogo sin tener que ir artículo por artículo.

## El precio base

El precio base es el que cargás en la ficha del artículo, en Artículos › Catálogo. Es el precio con el que se vende cuando no hay ninguna lista de precio aplicada. Si el artículo tiene un impuesto asociado, se calcula sobre este precio.

## Qué es una lista de precio

Una lista de precio es un conjunto de reglas de ajuste que se aplica sobre el precio base. Sirve para casos como:

- Un precio "Mayorista" con un descuento general del 10% sobre todo el catálogo.
- Un recargo para ventas en una sucursal específica.
- Un precio fijo distinto para un artículo puntual, sin tocar el resto de la lista.

## Cómo crear una lista de precio

1. Entrá a Configuración › Listas de precios.
2. Creá una lista nueva y ponele un nombre descriptivo (por ejemplo "Mayorista" o "Sucursal Centro").
3. Definí un ajuste general en porcentaje: negativo para descuento, positivo para recargo. Este ajuste se aplica a todos los artículos de la lista salvo que definas una excepción puntual.
4. Si necesitás un precio distinto para un artículo en particular, agregalo como excepción dentro de la lista: podés poner un precio fijo para ese artículo o un porcentaje de ajuste propio que reemplaza al general.
5. Opcionalmente, definí desde cuándo y hasta cuándo está vigente la lista. Si no ponés fechas, queda vigente siempre mientras esté activada.
6. Guardá la lista.

## Cómo se asigna una lista de precio

Una lista se puede asignar de tres formas, y si hay más de una aplicable, manda la más específica en este orden:

1. **Manual en el momento de la venta**: el cajero elige la lista al armar la venta, para ese cliente puntual.
2. **Por cliente**: asignás una lista de precio en la ficha del cliente (ver [clientes y proveedores](50-01-clientes-y-proveedores.md)), y se aplica automáticamente cada vez que le vendés a ese cliente.
3. **Por sucursal**: asignás una lista default a una sucursal, y se aplica a todas las ventas de esa sucursal que no tengan una lista más específica.

Si la lista asignada no está vigente (por fecha o porque la desactivaste), el sistema prueba con el siguiente nivel de prioridad. Si ninguna aplica, se cobra el precio base.

## Una nota sobre impuestos

Además del precio, cada artículo puede tener un impuesto asociado que se calcula sobre el precio ya ajustado por la lista de precio (si corresponde). La configuración de impuestos por artículo se administra en Configuración › Catálogo, en la pestaña "Impuestos", pero para el día a día de precios y listas no necesitás tocar esa sección salvo que quieras revisar qué impuesto tiene asignado un artículo.

## Preguntas frecuentes

**¿Puedo combinar un descuento general de la lista con un precio especial para un artículo?**
No al mismo tiempo. Si le ponés un precio fijo a un artículo dentro de una lista, ese precio manda y el descuento general de la lista no se le aplica a ese artículo.

**¿Qué pasa si vendo sin conexión y el cliente tiene una lista de precio asignada?**
Si la caja no tiene conexión al momento de vender, el precio de lista no se puede resolver y la venta sale a precio base. Es importante que el equipo lo sepa para evitar sorpresas con clientes de precio especial en zonas con conexión inestable.

**¿Puedo tener varias listas de precio activas a la vez?**
Sí, podés tener todas las que necesites; lo que importa es a qué cliente o sucursal la asignás y el orden de prioridad explicado arriba.
