---
title: Ventas a crédito y cobranzas
slug: ventas-a-credito-y-cobranzas
modulo: credito
audiencia: administrativo
keywords: [venta a credito, fiar, cuenta corriente, cobranza, cobrar, cuentas por cobrar, saldo pendiente, recibo de cobro, pago parcial, deuda del cliente]
resumen: Cómo vender a un cliente a crédito y cómo se cobra esa deuda más adelante.
---

# Ventas a crédito y cobranzas

Vender a crédito significa entregar la mercadería ahora y cobrarla después. Punto te permite habilitar esto por cliente, y después llevar el control de cuánto te debe cada uno y registrar los cobros a medida que van pagando.

## Habilitar crédito para un cliente

Antes de poder venderle a crédito a un cliente, tenés que habilitarlo desde su ficha en Contactos › Clientes: activá la opción de crédito y, si querés, definí un tope de saldo como referencia. Un cliente sin esta opción habilitada no va a poder pagarse a crédito en la caja.

## Cómo se vende a crédito

En la caja, al cobrar una venta, elegí crédito como medio de pago y seleccioná el cliente (tiene que estar habilitado para crédito, como se explicó arriba). La venta se emite igual que cualquier otra — el cliente se lleva la mercadería y el comprobante — pero queda registrada como pendiente de pago en vez de cobrada.

## Dónde ver la deuda de tus clientes

Entrá a Ventas › Cuentas por cobrar para ver el listado completo de clientes con saldo pendiente. Desde ahí también podés entrar al detalle de un cliente puntual y ver cada factura pendiente con lo que ya se le cobró de cada una.

## Cómo cobrar una deuda

Hay dos formas de registrar un cobro:

1. **Cobro puntual**: elegís una o varias facturas concretas de ese cliente y el monto que estás cobrando de cada una. Útil cuando el cliente te dice exactamente qué factura está pagando.
2. **Cobro por monto libre**: le cobrás un monto total al cliente sin especificar factura, y el sistema reparte automáticamente ese monto entre sus facturas pendientes, empezando por la que vence más antigua. Si el monto no le alcanza para cubrir todo, la última factura que toca queda con un saldo parcial.

En cualquiera de los dos casos, se genera un recibo de cobro que podés imprimir o entregar al cliente. Si el monto ingresado supera toda la deuda del cliente, el sistema no lo acepta — no se puede cobrar de más por error.

Cobrar un crédito pendiente requiere conexión a internet, a diferencia de la venta original a crédito, que sí se puede emitir sin conexión.

## Anular un cobro

Si registraste un cobro por error, podés anularlo desde su detalle. Al anular, la o las facturas afectadas vuelven a mostrar el saldo pendiente que corresponda — si esa factura ya tenía otros cobros parciales válidos, esos se mantienen y solo se revierte el que anulaste.

Importante: lo que se anula es el recibo de cobro, no la factura de venta original. Si la factura en sí está mal cargada (no el cobro), hoy no hay una forma de anularla desde este flujo.

## Preguntas frecuentes

**¿Puedo cobrar parte de una factura y dejar el resto pendiente?**
Sí, tanto en el cobro puntual como en el reparto por monto libre podés dejar una factura parcialmente pagada.

**¿El sistema me avisa si un cliente supera su tope de crédito?**
El tope de saldo que cargás en la ficha del cliente es una referencia informativa; hoy no bloquea automáticamente una nueva venta a crédito si el cliente ya superó ese monto.

**¿Un cajero puede anular un cobro?**
Depende de los permisos de su rol. Por defecto, un cajero puede cobrar crédito pero no anular un cobro ya hecho — esa acción suele requerir un perfil con más permisos.

**¿Cómo funciona del lado de mis proveedores?**
Es el mismo mecanismo pero invertido: en vez de que te deban a vos, vos le debés al proveedor. Ver [cómo registrar una compra](40-01-registrar-una-compra.md) para el alta de la deuda, y Compras y Gastos › Cuentas por pagar para pagarla.
