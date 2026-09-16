---
title: Trabajar sin internet
slug: trabajar-sin-internet
modulo: sincronizacion
audiencia: cajero
keywords: [sin internet, sin conexión, offline, caída de internet, wifi, no hay red, se cortó internet, funciona sin internet, abrir caja sin internet, cerrar caja sin internet, arqueo sin conexión, cierre a ciegas]
resumen: Qué sigue funcionando en la caja cuando se corta internet y qué necesita conexión.
---

# Trabajar sin internet

Un corte de internet no puede parar tu negocio a mitad de una venta. Punto está diseñado para que lo más importante — cobrar y entregar el comprobante — siga funcionando aunque la caja se quede sin conexión, y se ocupa de poner todo en orden apenas vuelve la señal.

## Qué sigue funcionando sin conexión

- **Cobrar una venta**: podés seguir armando el carrito, cobrando y emitiendo el comprobante correspondiente sin internet, siempre que estés usando una impresora conectada directamente a la caja (no una impresora remota que dependa de la red).
- **Comandas y pedidos internos**, si tu negocio los usa (por ejemplo, un restaurante), también se emiten sin conexión.
- **Abrir la caja, cerrarla y hacer el arqueo**: podés arrancar y terminar el turno sin conexión. También podés registrar extracciones o ingresos de efectivo durante el turno sin internet.
- **Ajustes de la caja** (interruptores de configuración, accesos rápidos, impresoras conectadas) también se pueden cambiar sin conexión y se sincronizan solos después. Lo único que no se puede cambiar sin internet es la sucursal o la caja con la que estás operando — esos selectores quedan bloqueados porque de ahí sale la numeración fiscal de tus comprobantes.

El sistema nunca rechaza una venta ya cobrada por falta de conexión: la guarda en el dispositivo y la sincroniza sola apenas vuelve la señal. Vas a ver un aviso en pantalla que te indica cuántas ventas están pendientes de sincronizar.

### Cerrar la caja sin conexión

Al cerrar el turno sin internet, la caja no puede pedirle el total al sistema central — así que te muestra el total según lo que ESTE dispositivo registró (sus propias ventas y movimientos desde que abrió). Si hay algo que este dispositivo no pudo ver —por ejemplo, movimientos de efectivo cargados desde el panel, o ventas de un turno que abrió otro aparato— la pantalla te lo avisa en vez de mostrar un número que podría estar incompleto.

Ese total local es solo una ayuda para contar: el arqueo definitivo siempre lo termina de calcular el sistema central cuando el cierre se sincroniza. Si el total que contaste no coincide con lo que el sistema calculó al recibir el cierre, vas a ver un aviso y el turno va a quedar marcado para revisar en Reportes › Control de cajas.

Si tu negocio tiene activado el control de caja a ciegas (el cajero cuenta sin ver de antemano cuánto "debería" haber), esto no cambia por estar sin conexión: seguís sin ver el total esperado, sea que estés online u offline. Y por la misma razón, el ticket del cierre no se imprime cuando el turno se cerró sin conexión.

El cierre no se manda al sistema central hasta que todas las ventas de ese turno terminaron de sincronizarse — así la comparación del arqueo es contra el turno completo y no contra uno a medio sincronizar. Mientras tanto, el cierre queda en espera, no como un error.

## Qué necesita conexión

- **Cualquier cosa que dependa de coordinarse con otras cajas al mismo tiempo** puede quedar bloqueada hasta reconectar. El caso típico es la gestión compartida de mesas o pedidos entre varios puestos de la misma sucursal: si dos cajas necesitan ver el mismo estado en tiempo real, esa parte no puede resolverse sin red.
- **Cobrar una mesa, una orden, o un cobro parcial** (situaciones donde varias cajas podrían estar tocando el mismo pedido) requiere conexión — a diferencia de una venta simple de mostrador.
- **Una impresora remota** (que no está conectada directo a la caja sino que imprime a través de la red) depende de que haya conexión en el momento de imprimir.

## Si una venta queda pendiente de sincronizar

Mientras la conexión no vuelve, esa venta ya está cobrada y su comprobante ya se imprimió — no se pierde. Cuando la caja recupera señal, el sistema intenta sincronizar cada venta pendiente automáticamente. Si alguna no logra sincronizarse por un problema real (no solo por falta de red), va a quedar marcada para que la revises, en vez de reintentarse sola indefinidamente.

## Preguntas frecuentes

**¿Puedo vender si se corta internet a mitad del día?**
Sí, mientras se trate de una venta simple de mostrador con una impresora conectada directo a la caja.

**¿Qué pasa si dos cajas venden el mismo producto al mismo tiempo sin conexión?**
El sistema trabaja con la información que la caja tenía guardada antes de perder conexión. Por eso lo que exige coordinación en tiempo real entre cajas (como mesas compartidas) se bloquea sin red, para evitar inconsistencias.

**¿Cómo sé si tengo ventas sin sincronizar?**
La caja muestra un aviso mientras haya ventas pendientes de enviar al servidor. No hace falta que hagas nada manualmente salvo que el aviso te indique que alguna falló y necesita revisión.

**¿Puedo abrir y cerrar la caja si no tengo internet?**
Sí. Podés abrir el turno, registrar extracciones o ingresos, y cerrar con el arqueo sin conexión. Al cerrar sin internet vas a ver el total según lo que ese dispositivo registró, no el del sistema central — la diferencia, si la hay, se revisa apenas el cierre se sincroniza.
