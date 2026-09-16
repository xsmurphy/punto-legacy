---
title: Cómo configurar impresoras y plantillas de impresión
slug: impresoras-y-plantillas-de-impresion
modulo: impresion
audiencia: administrativo
keywords: [impresoras, impresora termica, ticketera, comandera, plantillas de impresion, editor de plantillas, ticket, factura, comanda, usb, bluetooth, red, wifi, cajon monedero]
resumen: Cómo dar de alta una impresora en Punto y cómo diseñar qué se imprime en cada tipo de documento.
---

# Cómo configurar impresoras y plantillas de impresión

Imprimir en Punto tiene dos partes separadas: qué impresora física usás y para qué documentos ([Configuración › Impresoras](panel:/settings/printers)), y qué información sale impresa en cada tipo de documento — ticket, factura, comanda ([Configuración › Documentos](panel:/settings?section=documentos), con su editor visual en [Plantillas de impresión](panel:/settings/print-templates)). Configurá primero tus impresoras y después diseñá qué imprime cada una.

## Configurar una impresora

1. Entrá a [Configuración › Impresoras](panel:/settings/printers).
2. Elegí la caja para la que vas a configurar la impresora (cada caja tiene su propia configuración de impresoras).
3. Hacé clic en agregar impresora y completá:
   - **Nombre**: para identificarla vos (por ejemplo, "Epson mostrador" o "Comandera cocina").
   - **Tipo de dispositivo**: cómo se conecta — por USB, por Bluetooth, por red (con su dirección IP y puerto), como impresora del sistema/navegador, o como impresora de estación (servidor de impresión compartido).
   - **Modo**: térmica (imprime directo en formato de ticket) o navegador (usa el diálogo de impresión de la computadora, para documentos tipo hoja).
   - **Plantilla**: qué diseño de impresión usa esta impresora. Si no elegís una, usa la plantilla predeterminada de tu comercio.
   - **Ancho del papel, copias y demora entre copias** si imprime más de un ejemplar.
4. Elegí para qué tipos de documento sirve esta impresora: factura, presupuesto, retiro de caja, cierre de caja, devolución, comanda, etc. Una impresora puede estar asignada a más de un tipo.
5. Si querés que esa impresora solo imprima productos de ciertas categorías (por ejemplo, que la comandera de la barra solo imprima bebidas), podés limitarla por categoría.
6. Activá "Auto-imprimir al cerrar venta" si querés que imprima sola apenas se cobra, sin que el cajero tenga que apretar nada. Si la impresora tiene cajón monedero conectado, activá también que lo abra al imprimir.

### Impresora local versus impresora de estación

Una impresora conectada directo por USB, Bluetooth o red a la computadora de la caja funciona sin necesidad de internet. Una impresora de estación (servidor de impresión compartido) sí necesita conexión: el trabajo de impresión viaja al servidor y de ahí a la impresora. Si tu conexión a internet falla, la venta se sigue registrando igual, pero lo que dependa de una impresora de estación no va a salir hasta que vuelva la conexión.

## Diseñar qué se imprime (plantillas)

1. Entrá a [Configuración › Documentos](panel:/settings?section=documentos) para ver el listado de tus plantillas, agrupadas por tipo de documento (ticket, factura, presupuesto, orden de trabajo, gift card).
2. Desde ahí podés editar una plantilla existente, duplicarla para crear una variante, o eliminarla.
3. El editor visual (Plantillas de impresión) te deja armar el diseño arrastrando bloques de información: nombre del negocio, datos del cliente, listado de ítems, totales, impuestos, número de comprobante, código QR, y más.
4. Solo sale impreso lo que vos pusiste en la plantilla. Si un dato no aparece en tu ticket, es porque el bloque correspondiente no está agregado — agregalo desde el editor.
5. Podés diseñar en formato de rollo angosto (para ticketeras térmicas) o en formato de hoja completa, según el tipo de documento y la impresora que lo va a imprimir.

## Preguntas frecuentes

**¿Por qué no me imprime nada al cerrar una venta?**
Revisá que la impresora tenga asignado ese tipo de documento y que "Auto-imprimir al cerrar venta" esté activado.

**¿Puedo usar plantillas distintas en cada sucursal?**
Sí, la plantilla se elige por impresora, así que cada caja o cada sucursal puede tener su propio diseño.

**Falta un dato en mi ticket (por ejemplo, el número de comprobante).**
Entrá al editor de esa plantilla y fijate si el bloque de ese dato está agregado al diseño. Si el bloque existe pero el espacio es muy angosto, puede que el texto se corte — probá ensanchar el bloque.

**¿Qué pasa si se corta la conexión a internet?**
Las impresoras conectadas directo a la caja (USB, Bluetooth, red local) siguen funcionando sin problema. Las que dependen de un servidor de impresión compartido no van a imprimir hasta que vuelva la conexión.
