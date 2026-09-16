---
title: Emitir, consultar y entregar el comprobante electrónico
slug: emitir-consultar-y-entregar-el-comprobante
modulo: facturacion-electronica
audiencia: administrativo
keywords: [emitir factura electronica, consultar SIFEN, estado de factura, rechazo SIFEN, KuDE, entregar comprobante, portal cliente, CDC, factura rechazada, reenviar factura, QR factura]
resumen: Cómo se emite un comprobante electrónico en Punto, cómo consultar su estado ante SIFEN y cómo entregárselo al cliente.
---

# Emitir, consultar y entregar el comprobante electrónico

Este artículo aplica solo a comercios en Paraguay con la facturación electrónica ya activada (ver [Qué es la facturación electrónica y qué necesitás](70-01-que-es-la-facturacion-electronica-y-que-necesitas.md)). Cubre el día a día: qué pasa al cobrar una venta, cómo revisar si un comprobante quedó bien ante SIFEN, y cómo hacerle llegar el comprobante al cliente.

## Cómo se emite

Con la caja configurada con su timbrado, el Documento Electrónico (DE) se emite automáticamente cuando se cobra la venta. No hace falta ninguna acción extra: el cajero cobra como siempre y el comprobante se envía a SIFEN por detrás.

Si por algún motivo una venta quedó sin emitir (por ejemplo, quedó pendiente de proceso), se puede emitir a mano:

1. Entrá al detalle de esa venta (desde [Reportes › Transacciones](panel:/reports/sales?tab=transacciones), abrí la venta puntual).
2. Hacé clic en "Emitir factura electrónica".
3. El sistema intenta emitirla en el momento. Si la venta ya tenía su comprobante emitido, te lo va a avisar — no hay riesgo de duplicar por apretar el botón de más.

## Cómo consultar el estado

Desde el detalle de la venta vas a ver uno de estos estados:

- **Emitida**: el comprobante salió y tiene su número de identificación fiscal (CDC) asignado.
- **Pendiente / en proceso**: todavía se está enviando a SIFEN.
- **Rechazada**: SIFEN no aprobó el comprobante. El motivo del rechazo se muestra en la misma pantalla.
- **Con error**: algo impidió la emisión antes de llegar a SIFEN. También se muestra el detalle.

## Si SIFEN rechaza un comprobante

Un comprobante rechazado no se puede "reenviar" con el mismo número: cada emisión genera un documento nuevo, así que reintentar la emisión de una venta ya rechazada significaría declarar dos veces la misma venta. Por eso Punto no ofrece un botón de reenvío para un documento rechazado. Si te aparece un rechazo, revisá el motivo indicado y consultá con tu contador o con soporte antes de tomar una acción — la corrección no se hace reintentando el envío.

El comprobante rechazado queda igual visible en el sistema, con su motivo, para que quede constancia — no desaparece ni se oculta.

## Cómo entregarle el comprobante al cliente

- **Descargar e imprimir el KuDE**: desde el detalle de la venta ya emitida, el botón "Descargar KuDE" te da el PDF con la representación oficial del comprobante (incluido su código QR de validación), listo para imprimir o enviar por otro medio. Este botón no está disponible si el comprobante fue rechazado, porque un KuDE de un documento rechazado no tiene validez.
- **Portal de consulta para el cliente**: cada comprobante emitido tiene una página propia donde el cliente puede consultarlo y descargar su KuDE. Si tu plantilla de ticket incluye el bloque de enlace al portal (configurable desde el editor de plantillas de impresión), el ticket sale con ese enlace o su código QR, y el cliente entra directo desde su celular.
- **A pedido del cliente**: si el cliente te pide el comprobante impreso, siempre podés entregárselo así usando "Descargar KuDE" e imprimiéndolo.

## Preguntas frecuentes

**¿El ticket que imprime la caja en el momento de la venta ya es la factura electrónica?**
No necesariamente. El ticket que sale por defecto es un comprobante de la operación; el documento fiscal es el DE, y su representación oficial (el KuDE) se descarga desde el detalle de la venta una vez emitido.

**¿Qué hago si un cliente perdió su comprobante y lo necesita de nuevo?**
Buscá la venta en [Reportes › Transacciones](panel:/reports/sales?tab=transacciones) y volvé a descargar el KuDE desde ahí — no hace falta volver a emitir nada.

**¿Puedo corregir un comprobante que ya salió con un dato mal?**
La emisión ya hecha no se edita ni se vuelve a enviar. Las correcciones sobre una venta ya facturada se manejan con los mecanismos de anulación o nota de crédito, no reemitiendo el mismo documento.
