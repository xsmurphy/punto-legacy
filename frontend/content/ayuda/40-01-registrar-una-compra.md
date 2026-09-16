---
title: Cómo registrar una compra
slug: registrar-una-compra
modulo: compras
audiencia: administrativo
keywords: [compra, comprar, proveedor, factura de compra, registro de compras, reponer stock, costo, compra a credito, compra al contado, bulto, caja cerrada]
resumen: Cómo registrar una compra a un proveedor y qué efecto tiene sobre el stock y el costo de tus artículos.
---

# Cómo registrar una compra

Cada vez que le comprás mercadería a un proveedor, registrarla en Punto hace dos cosas a la vez: suma esa mercadería a tu stock y actualiza el costo de tus artículos con lo que realmente pagaste. Si la compra es a crédito, además queda registrada la deuda con el proveedor.

## Cómo registrar una compra manualmente

1. Entrá a [Compras y Gastos › Registro de compras](panel:/purchase).
2. Elegí el proveedor. Si todavía no lo tenés cargado, podés darlo de alta ahí mismo (ver [clientes y proveedores](50-01-clientes-y-proveedores.md)).
3. Elegí si la compra es **al contado** o **a crédito**. Si es a crédito, vas a tener que indicar la fecha de vencimiento del pago — sin fecha de vencimiento el sistema no te deja guardar una compra a crédito, porque esa fecha es la que alimenta el reporte de cuentas por pagar.
4. Agregá los artículos comprados, la cantidad y el costo pagado por cada uno.
5. Si compraste por bulto o caja cerrada (por ejemplo, una caja de 12 unidades), cargá el precio pagado por el bulto y la cantidad de unidades que contiene cada bulto: el sistema convierte automáticamente al costo por unidad para que tu stock quede en unidades reales, no en bultos.
6. Si además tuviste un gasto asociado que no es un artículo puntual (por ejemplo, flete), podés cargarlo como una línea de gasto libre, sin necesidad de que corresponda a un producto del catálogo.
7. Revisá el impuesto de cada línea, si corresponde.
8. Guardá la compra.

Al guardar, el stock de cada artículo comprado se actualiza al instante y el costo promedio del artículo se recalcula con lo que pagaste en esa compra.

## Una nota sobre el costo de inventario

El costo que se registra es el que realmente pagaste, con impuestos incluidos — no el costo neto sin impuestos. Es una decisión de cómo Punto valora tu inventario, así que no necesitás desglosarlo vos: cargá el monto tal como aparece en la factura del proveedor.

## Compra al contado vs. a crédito

- **Al contado**: la compra queda marcada como pagada en el momento y afecta tu caja o cuenta de pago según cómo la hayas cargado.
- **A crédito**: la compra queda pendiente de pago y aparece en [Compras y Gastos › Cuentas por pagar](panel:/reports/open-invoices?tab=pagar). El pago se registra después, como una operación aparte, y puede cubrir una o varias compras pendientes del mismo proveedor a la vez. Para más detalle sobre cómo se cobra y se paga a crédito, ver [ventas a crédito y cobranzas](50-02-ventas-a-credito-y-cobranzas.md) (la misma lógica aplica, del lado proveedor).

## Cargar una compra a partir de una foto de la factura

Si tenés la factura en papel o en PDF, podés subir una foto y el sistema te arma un borrador con los datos extraídos automáticamente (proveedor, artículos, cantidades, precios). Ese borrador nunca se carga solo: vos lo revisás, corregís lo que haga falta y recién al aprobarlo se registra como una compra real, exactamente igual que si la hubieras cargado a mano.

## Anular una compra

Si cargaste una compra por error, podés anularla desde su detalle. Al anular, la mercadería se descuenta del stock que había ingresado, revirtiendo el efecto de la compra.

## Preguntas frecuentes

**¿Qué pasa si compro un artículo que no tengo cargado en el catálogo?**
Tenés que cargarlo primero en el catálogo (ver [cargar artículos y categorías](30-01-cargar-articulos-y-categorias.md)) para poder asociarle stock y costo desde una compra.

**¿Puedo cargar un gasto que no corresponde a mercadería, como el alquiler?**
El registro de compras está pensado para compras a proveedores con o sin mercadería asociada. Para otro tipo de gastos del negocio, existe un módulo de Compras y Gastos separado donde podés revisar el listado histórico.

**¿La compra genera un número de comprobante propio de Punto?**
No. El número de factura que cargás en una compra es el que emitió tu proveedor. Punto no emite comprobantes fiscales por tus compras, solo las registra.
