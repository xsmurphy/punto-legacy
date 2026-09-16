---
title: Transferencias entre depósitos
slug: transferencias-entre-depositos
modulo: stock
audiencia: administrativo
keywords: [transferencia, traslado, mover mercaderia, entre sucursales, entre depositos, envio de stock, remito interno]
resumen: Cómo mover mercadería entre sucursales o depósitos propios de tu comercio.
---

# Transferencias entre depósitos

Cuando tenés más de una sucursal o más de un depósito, en algún momento vas a necesitar mover mercadería de uno a otro: reponer una sucursal desde el depósito central, por ejemplo. Para eso está la transferencia de stock, que descuenta el artículo del origen y lo suma al destino de forma prolija y trazable.

## Cómo hacer una transferencia

1. Entrá a [Artículos › Transferencias](panel:/stock-transfer).
2. Creá una transferencia nueva.
3. Elegí la sucursal (o depósito) de origen y la de destino. Tienen que ser distintas entre sí.
4. Agregá los artículos y la cantidad de cada uno que vas a trasladar.
5. Confirmá la transferencia.

Al confirmar, el sistema descuenta la cantidad del origen y la suma al destino en el mismo momento. Queda un número de documento propio para identificar y buscar esa transferencia más adelante.

Si alguno de los artículos que agregaste no lleva control de stock (por ejemplo, un servicio cargado por error), la transferencia lo salta y sigue con el resto — no hace falta que todos los artículos sean válidos para completar la operación.

## Cancelar una transferencia

Si necesitás anular una transferencia ya confirmada, podés cancelarla desde su detalle. Al cancelar, se revierte el movimiento: la mercadería vuelve a sumarse en el origen y se descuenta del destino.

Tené en cuenta que si parte de esa mercadería ya se vendió en el destino antes de cancelar la transferencia, el sistema igual permite cancelar — el stock del destino puede quedar en negativo momentáneamente, y te va a corresponder corregirlo con un [ajuste de stock](30-03-control-de-stock-y-ajustes.md) si hace falta.

## Qué NO es una transferencia

La transferencia es exclusivamente para mover mercadería entre sucursales o depósitos de tu propio comercio. No es el mecanismo para registrar una venta, una devolución a un proveedor ni un envío a un cliente — esos casos tienen su propio flujo en Ventas y Compras.

## Preguntas frecuentes

**¿Puedo transferir a una sucursal que todavía no tiene ese artículo cargado con stock?**
Sí, la transferencia crea el saldo en el destino si no existía.

**¿La transferencia se puede imprimir como remito?**
Sí, podés imprimir el documento de la transferencia como comprobante interno del traslado.

**¿Puedo transferir un artículo que no tiene stock suficiente en el origen?**
El sistema te va a permitir cargarlo, pero es buena práctica confirmar la cantidad real disponible antes de transferir para evitar diferencias que después haya que corregir con un ajuste.
