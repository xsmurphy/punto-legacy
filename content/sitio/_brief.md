---
titulo: "Brief de contenido del sitio"
fuente: "sitio punto.la"
---

# Brief de contenido — punto.la

Todo el contenido publicado del sitio, en un solo documento. Es el material
para quien tenga que escribir, diseñar o traducir sobre el sitio sin entrar al
código.

Se genera con `npm run export:content` desde las mismas fuentes que renderiza
el sitio (`lib/site/*.ts`), así que refleja lo que está publicado. **No editarlo
a mano**: se sobreescribe en cada build. Para cambiar un texto se cambia la
fuente.

El tono y el uso de la marca están en `context/68-brand-kit-social.md`; el
detalle técnico del sitio, en `context/61-sitio-marketing.md`.

## Mapa del sitio

**Páginas principales**

- `/` — Punto — Sistema de punto de venta y facturación electrónica
- `/precios` — Precios y planes
- `/contacto` — Contacto

**Módulos**

- `/modulos/punto-de-venta` — Punto de Venta
- `/modulos/panel` — Panel
- `/modulos/punto-ai` — Punto AI
- `/modulos/mesas-y-ordenes` — Mesas y órdenes
- `/modulos/gift-cards` — Gift cards y vales
- `/modulos/pantalla-de-cocina` — Pantalla de cocina
- `/modulos/facturacion-electronica` — Facturación electrónica
- `/modulos/stock-y-compras` — Stock y compras
- `/modulos/clientes-y-credito` — Clientes y crédito
- `/modulos/produccion-y-recetas` — Producción y recetas

**Rubros**

- `/para/restaurantes` — Punto para restaurantes
- `/para/minimarkets` — Punto para minimarkets
- `/para/farmacias` — Punto para farmacias
- `/para/ferreterias` — Punto para ferreterías
- `/para/ropa-y-accesorios` — Punto para ropa y accesorios
- `/para/bares-y-cafes` — Punto para bares y cafés
- `/para/comida-rapida` — Punto para comida rápida
- `/para/dark-kitchen` — Punto para dark kitchen
- `/para/decoracion-y-hogar` — Punto para decoración y hogar
- `/para/barberias` — Punto para barberías
- `/para/peluquerias` — Punto para peluquerías
- `/para/consultorios-medicos` — Punto para consultorios médicos
- `/para/odontologia` — Punto para odontología
- `/para/veterinarias` — Punto para veterinarias
- `/para/estetica-y-cosmetologia` — Punto para estética y cosmetología

**Legales**

- `/terminos` — Términos y Condiciones
- `/privacidad` — Política de Privacidad
- `/reembolsos` — Política de Reembolsos

---

## Páginas principales

_La home, el plan y cómo contactarnos._

### Punto — Sistema de punto de venta y facturación electrónica

`/`

Punto es un sistema de punto de venta y gestión para comercios de Paraguay: vender, cobrar, facturar electrónicamente, controlar stock y clientes, y ver los números del negocio — todo en un mismo lugar.

#### Los tres protagonistas

##### Punto de Venta

La pantalla donde pasa el día: buscar, cobrar y entregar el comprobante sin que la fila se entere. Funciona con dedo en tablet o entera por teclado, y no se detiene cuando se corta internet.

Página: /modulos/punto-de-venta

##### Panel

Todas las sucursales en la misma pantalla: qué se vendió hoy, qué falta reponer, quién debe y con qué margen cerró el mes. Desde la computadora del local o desde el teléfono, con los mismos datos.

Página: /modulos/panel

##### Punto AI

Preguntale en tu idioma y responde con los datos reales de tu negocio: arma el reporte, lo grafica y explica qué está pasando. Sin exportar planillas y sin saber por dónde empezar.

Página: /modulos/punto-ai

#### Módulos del sistema

##### La venta, en segundos

La venta se arma tocando el catálogo o escaneando, y el total sale solo. Contado, crédito o mixto, en la misma pantalla.

Pensado para: minimarkets · tiendas · farmacias · barberías

##### El turno cierra con números, no con memoria

Apertura, movimientos y arqueo por turno y por caja. Lo esperado contra lo contado, y cada diferencia con nombre y hora.

Pensado para: locales con turnos · más de un cajero

##### Factura electrónica sin trámite aparte

La factura sale de la misma venta y se envía sola al organismo fiscal. Numeración y series controladas por el sistema, siempre en regla.

Pensado para: todo negocio que emite comprobantes

##### Saber qué hay, antes de que falte

Cada venta descuenta stock al instante, por depósito y por sucursal. Mínimos con aviso y ajustes con historia.

Pensado para: minimarkets · farmacias · ferreterías

##### Saber quién te compra

Quién compró, qué compró y cuánto debe. Crédito con límite, cobranzas al día y la historia completa de cada cliente.

Pensado para: farmacias · ferreterías · consultorios · locales de barrio

##### El salón, mesa por mesa

Cada mesa con su cuenta abierta y su pedido en cocina. Se agrega, se une, se divide o se cobra desde cualquier caja del local.

Pensado para: restaurantes · bares · comida rápida

##### El negocio en números, sin planillas

Qué se vende, a qué hora y en qué sucursal. Ventas, márgenes y ranking de productos, listos al abrir el panel.

Pensado para: dueños que deciden con datos

#### Todo lo que viene incluido

- **Punto AI** — Preguntale por tus números y responde con los datos del negocio.
- **Factura electrónica** — El comprobante se emite y se envía solo, con su estado siempre a la vista.
- **Multi-sucursal** — Catálogo, precios y reportes por sucursal, bajo una sola marca.
- **Órdenes y mesas** — La comanda entra sola a su estación y cada mesa muestra su cuenta abierta.
- **Cotizaciones** — El presupuesto se arma como una venta y se convierte en una con un toque.
- **Gift cards y vales** — Se venden por adelantado y se canjean en caja, sin papelitos.
- **Crédito y cobranzas** — Venta a crédito con límite por cliente y recibos de cada pago.
- **Facturas de compra con IA** — Foto a la factura del proveedor y la carga sale sola, lista para aprobar.
- **Compras y proveedores** — La compra carga el stock y deja el costo actualizado.
- **Producción y recetas** — La receta descuenta insumos y calcula el costo real de lo que producís.
- **Combos y agregados** — Medias medidas, adicionales y combos que bajan claros a cada línea.
- **Listas de precio** — Mayorista, mostrador o delivery: cada canal con su precio.
- **Modo offline** — Se corta internet y la caja sigue vendiendo. Al volver, todo se sincroniza.
- **Sync en tiempo real** — Lo que pasa en una caja se ve en todas, al instante.
- **Remisiones** — El traslado entre depósitos sale documentado, no anotado.
- **Reportes fiscales** — Los libros de venta y de compra salen del sistema, no del contador apurado.
- **Plantillas de impresión** — Ticket, factura, comanda o remito con tu logo, tal como los querés.
- **Pantalla para el cliente** — El que espera ve su cuenta armarse y el total, sin pedir el detalle.
- **Dispositivos y cajas** — Cada caja con su sesión, sus permisos y su numeración propia.

#### Rubros

##### Gastronomía

- Restaurantes — /para/restaurantes
- Bares y cafés — /para/bares-y-cafes
- Comida rápida — /para/comida-rapida
- Dark kitchen — /para/dark-kitchen

##### Retail

- Minimarkets — /para/minimarkets
- Farmacias — /para/farmacias
- Ferreterías — /para/ferreterias
- Ropa y accesorios — /para/ropa-y-accesorios
- Decoración y hogar — /para/decoracion-y-hogar

##### Salud y belleza

- Barberías — /para/barberias
- Peluquerías — /para/peluquerias
- Consultorios médicos — /para/consultorios-medicos
- Odontología — /para/odontologia
- Veterinarias — /para/veterinarias
- Estética y cosmetología — /para/estetica-y-cosmetologia

---

### Precios y planes

`/precios`

Un solo plan, con todo adentro. Sin versiones recortadas ni módulos que se desbloquean pagando de más.

- **Precio:** Gs. 295.000 por mes, por sucursal
- **Condición:** se paga mes a mes, sin contrato ni permanencia
- **Estado:** Precio promocional

#### Qué incluye

- Facturación electrónica ilimitada, sin costo por comprobante ni cupos mensuales
- Usuarios ilimitados, cada uno con sus permisos
- Cajas ilimitadas por sucursal
- Productos ilimitados, con fotos y variantes
- Transacciones ilimitadas: no se cobra por ticket
- 10.000 créditos de IA por mes, para preguntarle a Punto AI
- Soporte online 24/7

#### Preguntas frecuentes

**¿El precio es por negocio o por sucursal?**
Por sucursal. Cada local paga Gs. 295.000 por mes y adentro no hay límites. Si abrís una segunda sucursal, se suma solo esa.

**¿La facturación electrónica se cobra aparte?**
No. Está incluida y es ilimitada — no se cobra por comprobante emitido ni se venden paquetes de facturas.

**¿Hay contrato o permanencia?**
No. Se paga mes a mes y se puede dar de baja cuando el cliente quiera; los datos son suyos y se los lleva cuando lo pida.

**¿Puedo ver el sistema antes de contratar?**
Sí. Se coordina una demostración con casos del rubro del cliente antes de decidir. No hay prueba gratuita autogestionada.

**¿Qué pasa si se corta internet?**
El punto de venta sigue funcionando: la venta se emite igual y se sincroniza sola cuando vuelve la conexión.

**¿Necesito comprar equipos especiales?**
No. Funciona en la computadora, tablet o teléfono que el comercio ya tenga, desde el navegador. Impresora de tickets y lector de código de barras son opcionales.

**¿Me ayudan a cargar mis productos?**
Sí. Hay acompañamiento en la puesta en marcha e importación de catálogo y clientes desde planilla.

**¿Qué son los créditos de IA y para qué alcanzan?**
Son el consumo de Punto AI. El plan incluye 10.000 por mes, que cubren el uso normal de un comercio. Si el equipo lo usa mucho más, se pueden sumar créditos aparte.

**¿El precio promocional sube después?**
Se mantiene mientras la cuenta siga activa. Si cambia la lista, se avisa con anticipación.

---

### Contacto

`/contacto`

- **WhatsApp:** +595 981 078798
- **Oficina:** Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay
- **Horario de respuesta:** de lunes a sábado se responde en el día; el soporte para clientes funciona 24/7.

No hay formulario de contacto en el sitio: el canal es WhatsApp o la visita a la oficina.

---

## Módulos

_Una página por capacidad del sistema._

### Punto de Venta

`/modulos/punto-de-venta`

**Vender no debería tomar más de unos segundos**

La pantalla donde pasa el día: buscar, cobrar y entregar el comprobante sin que la fila se entere. Funciona con dedo en tablet o entera por teclado, y no se detiene cuando se corta internet.

Grupo: En todo negocio

#### Lo esencial

- Artículos con foto a la vista, buscador instantáneo y lector de código de barras.
- Contado, crédito, QR o varios medios en la misma venta, con vuelto calculado.
- Descuentos, notas y precios por lista aplicados sin salir de la pantalla.
- Sigue vendiendo sin internet: al volver la conexión sincroniza sola.

#### Todo el flujo de venta, sin soltar el teclado

_La fila no espera_

El artículo aparece mientras escribís, el escáner lo suma directo y el total se recalcula en cada toque. Cobrar, entregar el comprobante y arrancar la próxima venta son tres pasos que se hacen con atajos, sin buscar botones ni abrir menús.

En tablet la lógica es la misma pero con targets grandes: el cajero opera con el pulgar y los elementos no cambian de lugar según el estado, así la memoria muscular no se rompe.

Ver también: Ver cómo se cobra → /modulos/facturacion-electronica

#### Efectivo, tarjeta, QR o crédito — o todo junto

_Un cobro, varios medios_

Una venta puede cerrarse con parte en efectivo y parte en transferencia, o quedar a crédito contra la cuenta corriente del cliente. Cada medio queda registrado por separado, así el arqueo del turno cuadra sin adivinanzas.

El comprobante sale al cerrar: ticket para el que no pide nada, factura electrónica para el que da sus datos. La numeración la controla el sistema, no el cajero.

Ver también: Ver el comprobante → /modulos/facturacion-electronica

#### Una pantalla que muestra lo que se está cobrando

_Del lado del cliente_

Un segundo monitor mirando al cliente le muestra lo que el cajero va cargando y el total a pagar, línea por línea. El que espera ve su cuenta armarse en vivo, sin tener que pedir el detalle.

Sirve además como cartel del negocio entre venta y venta, y evita la discusión más común del mostrador: qué se cobró y por cuánto.

Ver también: Ver la pantalla del cliente → /modulos/punto-de-venta

#### El modo offline no es un plan B, es la base

_Se corta la luz y seguís_

Punto guarda cada venta en el dispositivo y la emite igual: sin internet, sin energía en la manzana, con una tablet a batería. Nada de 'volvé más tarde' ni de anotar en un papel para cargar después.

Cuando la conexión vuelve, las ventas suben solas y aparecen en el panel con su hora real — la del momento en que se vendió, no la de la sincronización.

Ver también: Ver la sincronización → /modulos/panel

#### Turnos, permisos y arqueo que cuadra

_Cada caja, su responsable_

Cada dispositivo es una caja con su sesión y su numeración. El cajero abre turno con su usuario, y lo que pasa en ese turno — ventas, retiros, gastos — queda con nombre y hora.

Al cerrar, el arqueo compara lo que el sistema esperaba contra lo que se contó. La diferencia, si la hay, aparece sola: nadie tiene que reconstruir el día de memoria.

Ver también: Ver el cierre de turno → /modulos/panel

---

### Panel

`/modulos/panel`

**Tu negocio entero, a la vista**

Todas las sucursales en la misma pantalla: qué se vendió hoy, qué falta reponer, quién debe y con qué margen cerró el mes. Desde la computadora del local o desde el teléfono, con los mismos datos.

Grupo: En todo negocio

#### Lo esencial

- Ventas del día por sucursal, caja y usuario, actualizadas al instante.
- Catálogo, precios y listas centralizados, con lo que ve cada sucursal.
- Stock por depósito, mínimos con aviso y compras que actualizan el costo.
- Cuentas por cobrar, cobranzas y la historia completa de cada cliente.

#### Multi-sucursal sin planillas paralelas

_Una marca, varios locales_

Cada sucursal opera con sus cajas, su depósito y su gente, pero el dueño ve el conjunto: comparar el día del centro contra el de la sucursal nueva no requiere pedirle el número a nadie.

Los permisos siguen la misma lógica: el encargado ve lo suyo, la administración ve todo, y cada quien entra con su usuario.

Ver también: Ver el resumen por sucursal → /modulos/punto-ai

#### Cargar una vez y que lo vean todas las cajas

_El catálogo, en un lugar_

Artículos, categorías, variantes y precios se cargan en el panel y bajan a cada punto de venta al instante. Si subís un precio a las tres de la tarde, la caja lo cobra a las tres y un minuto.

Las listas de precio conviven: mostrador, mayorista y delivery pueden tener el suyo sin duplicar el artículo ni llevar una planilla aparte.

Ver también: Ver listas de precio → /modulos/stock-y-compras

#### Stock y compras que se hablan entre sí

_Antes de que falte_

Cada venta descuenta stock en su depósito, cada compra lo repone y actualiza el costo. Los mínimos avisan antes del quiebre y los ajustes quedan con fecha, usuario y motivo — el inventario deja de ser un misterio de fin de mes.

Ver también: Ver la reposición → /modulos/stock-y-compras

#### Reportes listos al abrir la pantalla

_Números, no intuición_

Ventas por hora, ranking de productos, márgenes, medios de pago, cuentas por cobrar y los libros que pide el contador. Todo sale del mismo dato que generó la caja, sin exportar ni cruzar planillas.

Ver también: Ver los reportes → /modulos/punto-ai

---

### Punto AI

`/modulos/punto-ai`

**Un analista que ya conoce tus números**

Preguntale en tu idioma y responde con los datos reales de tu negocio: arma el reporte, lo grafica y explica qué está pasando. Sin exportar planillas y sin saber por dónde empezar.

Grupo: En todo negocio

#### Lo esencial

- Preguntás como le hablarías a tu contador y responde con tus datos, no con generalidades.
- Arma el gráfico y el resumen escrito en el mismo paso.
- Compara períodos, sucursales y productos sin que tengas que armar el filtro.
- Puede cargar y corregir datos básicos del catálogo, siempre pidiéndote confirmación.

#### La pregunta que harías en voz alta, respondida con datos

_Preguntar es la interfaz_

"¿Cómo viene el mes contra el anterior?", "¿qué producto me deja más margen?", "¿qué clientes no volvieron en 60 días?". Punto AI entiende la pregunta, busca en tu información y contesta con el número concreto — más el gráfico cuando ayuda a verlo.

No hay que aprender dónde vive cada reporte ni qué filtro combinar: la conversación reemplaza el recorrido por los menús.

Ver también: Ver una respuesta → /modulos/panel

#### Analiza y te dice dónde mirar

_No solo el qué, el por qué_

La respuesta no es una tabla muda: señala el día flojo, el producto que cayó, el cliente que dejó de comprar o el margen que se comió un costo nuevo. Lo que un dueño encontraría revisando reportes durante una hora, en la primera respuesta.

Y como trabaja sobre los datos de tu negocio, las conclusiones son tuyas: nada de promedios de industria ni consejos genéricos.

Ver también: Ver el análisis → /modulos/panel

#### Carga y corrige datos, con tu confirmación

_También ordena_

Además de leer, Punto AI puede poner orden: crear un artículo, corregir una categoría mal escrita, cargar un contacto nuevo. Cada acción que modifica algo te la muestra antes y espera que confirmes.

Lo sensible queda fuera por diseño: no toca ventas, ni caja, ni permisos, ni borra nada en masa. Ordena el catálogo, no la contabilidad.

Ver también: Ver una acción confirmada → /modulos/stock-y-compras

---

### Mesas y órdenes

`/modulos/mesas-y-ordenes`

**El salón y la cocina, en la misma página**

Cada mesa con su cuenta abierta, cada pedido con su hora de entrada en cocina. Se agrega una ronda, se divide la cuenta o se cobra desde cualquier caja del local, y todos los dispositivos ven lo mismo al instante.

Grupo: Para gastronomía

#### Lo esencial

- La mesa acumula rondas y muestra su cuenta al día desde cualquier dispositivo.
- El pedido entra a cocina con su hora, sus agregados y sus aclaraciones.
- La cuenta se divide por ítems, por monto o en partes iguales.
- Cada estación — cocina, barra, plancha — recibe solo lo suyo.

#### La mesa es la misma desde cualquier caja

_Estado compartido, no copias_

El mozo toma el pedido en el salón, la caja del fondo cobra y el encargado mira desde el panel: los tres ven el mismo saldo. No hay una versión de la mesa por dispositivo ni un papel que haya que ir a buscar.

Cuando el cliente pide la cuenta, la mesa lo señala sin bloquearse — si alguien suma un postre después, entra igual y la cuenta se actualiza.

Ver también: Ver el salón → /modulos/pantalla-de-cocina

#### La comanda se reparte sola entre cocina y barra

_Cada estación, lo suyo_

Los tragos van a la barra, los platos a la cocina y la pizza al horno, cada uno en su pantalla y en orden de llegada. Nadie tiene que gritar el pedido ni repartir papeles entre sectores.

Los agregados y las aclaraciones bajan literales — sin cebolla, punto jugoso, para llevar — y cada línea se marca como lista cuando sale.

Ver también: Ver la pantalla de cocina → /modulos/pantalla-de-cocina

#### Dividir y cobrar en partes, con su comprobante

_La cuenta sin drama_

La mesa se puede cobrar entera o en partes: por lo que consumió cada uno, por un monto suelto o en partes iguales. Cada pago parcial genera su propio comprobante, así el que necesita factura la tiene.

La mesa se cierra recién cuando el saldo llega a cero. No hay forma de dejarla abierta con plata pendiente por descuido.

Ver también: Ver el cobro dividido → /modulos/punto-de-venta

---

### Gift cards y vales

`/modulos/gift-cards`

**Cobrar hoy lo que se entrega después**

La gift card es plata a favor del cliente; el vale, productos ya pagos. Las dos se venden en la caja, se canjean con un código y descuentan solo lo que corresponde — sin cuadernos ni papelitos detrás del mostrador.

Grupo: Para comercio

#### Lo esencial

- La gift card guarda un importe y se usa como medio de pago, entera o en varias compras.
- El vale guarda productos exactos, con su precio congelado al emitirse.
- El canje ocurre dentro de la venta: si algo falla, no se consume el saldo.
- Cada código deja su historia: cuándo se vendió, dónde se usó y qué queda.

#### La gift card entra a la caja hoy

_Plata por adelantado_

El cliente compra un monto, se lleva el código y lo usa cuando quiera. Para el negocio es caja hoy y una visita casi asegurada después — con el detalle de que la mayoría gasta más que el saldo.

Se puede usar en una compra o en varias: el sistema lleva el saldo restante y lo aplica como un medio de pago más, combinable con efectivo o tarjeta.

Ver también: Ver el canje → /modulos/punto-de-venta

#### El vale es por productos, no por plata

_Mercadería ya paga_

Un combo de desayuno, diez lavados, una torta encargada: el vale guarda los ítems exactos con su precio congelado al momento de emitirse. Cuando el cliente lo trae, las líneas entran a la venta sin volver a sumar al total — ya se cobraron.

Si el precio subió en el medio, no importa: lo que se vendió fue el producto, y el sistema lo respeta.

Ver también: Ver un vale → /modulos/punto-de-venta

#### El código se consume una sola vez

_Sin dobles canjes_

El canje pasa dentro de la venta, en el mismo movimiento: o se cobra y se consume el saldo, o no pasa nada. No existe el caso de un vale marcado como usado en una venta que después se cayó.

Y cada código guarda su rastro — quién lo vendió, en qué sucursal se usó y qué saldo queda — así el reclamo del mostrador se resuelve mirando la pantalla.

Ver también: Ver el historial → /modulos/clientes-y-credito

---

### Pantalla de cocina

`/modulos/pantalla-de-cocina`

**La comanda deja de ser un papel**

Cada estación ve en su pantalla lo que le toca preparar, en orden de llegada y con el tiempo de espera corriendo. Sin impresora que se quede sin papel, sin tickets que se pierden entre la barra y la plancha.

Grupo: Para gastronomía

#### Lo esencial

- Un tablero por estación: cocina, barra, plancha o el horno ven solo lo suyo.
- La comanda entra sola apenas se manda el pedido, con su hora de entrada.
- Los agregados y las aclaraciones se leen indentados bajo cada plato.
- Si algo se marcó listo por error, se vuelve atrás sin llamar a nadie.

#### Mostrador, mesa o envío, todo llega al mismo tablero

_De dónde entra_

La comanda puede nacer en la caja del mostrador, en una mesa del salón o en un pedido para envío. Sea cual sea el origen, entra a la pantalla con su número, su hora y de dónde viene — la cocina no necesita preguntar para qué es cada cosa.

El que toma el pedido no manda nada aparte: al confirmar la orden, la comanda ya está en cocina. Nadie transcribe, nadie camina hasta la plancha con un papel.

Ver también: Ver mesas y órdenes → /modulos/mesas-y-ordenes

#### La cocina ve platos; la barra, tragos

_Cada estación, su tablero_

Un pedido de mesa puede repartirse entre tres estaciones y cada una recibe solo su parte. El que arma tragos no tiene que leer la comanda entera para encontrar lo suyo, y nadie prepara dos veces lo mismo.

El orden lo pone la hora de entrada, no quién grita más fuerte: la tanda se arma por antigüedad y el tiempo de espera de cada comanda está a la vista.

Ver también: Ver el tablero → /modulos/mesas-y-ordenes

#### Lo que pidió el cliente, tal cual

_Sin traducción de por medio_

El punto de la carne, la mitad sin aceitunas, el extra de queso: los agregados bajan indentados bajo su plato, no como una nota suelta al final que alguien puede saltear.

La comanda muestra todo lo que hay que preparar, cobre o no cobre. El que cocina no tiene que saber qué se facturó — solo qué sale.

Ver también: Ver una comanda → /modulos/mesas-y-ordenes

#### La pantalla de despacho arma el pedido completo

_La salida_

Cocina prepara por estación, pero el cliente se lleva el pedido entero. La pantalla de despacho muestra cada orden con todo lo que la compone y en qué anda: en espera, en proceso o lista para salir.

Quien entrega mira una sola columna y sabe qué está pronto y qué falta, sin ir a preguntar a la barra si el trago ya salió. El mostrador entrega completo o no entrega.

#### El plato avanza — y también puede volver

_Marcar y deshacer_

Cuando el plato sale, se marca listo y desaparece del tablero. Si se marcó de más — pasa en hora pico — se vuelve atrás desde la misma pantalla, sin pedirle permiso a la caja.

Cada cambio queda registrado con su hora, así el encargado puede mirar después cuánto tardó realmente cada comanda en salir.

Ver también: Ver los tiempos → /modulos/panel

---

### Facturación electrónica

`/modulos/facturacion-electronica`

**Facturación electrónica gratis y sin límites**

Emitís todos los documentos electrónicos que tu negocio necesite y no te cobramos ni uno. Sin paquetes de comprobantes, sin cupos mensuales y sin sorpresas cuando el mes viene bueno: la factura sale de la misma venta y viaja sola a SIFEN.

Grupo: En todo negocio
Disponible en: PY

#### Lo esencial

- Documentos electrónicos ilimitados, sin costo por comprobante.
- La factura se arma con la venta: el vendedor no carga nada dos veces.
- Factura, autofactura y nota de crédito electrónica, con su CDC y su KuDE.
- El estado de cada documento se ve en el panel, uno por uno.

#### Facturar de más no te sale más caro

_Sin cupos ni paquetes_

En Paraguay lo normal es pagar por tandas de documentos: mil comprobantes, cinco mil, y cuando se acaban hay que comprar otra. El negocio termina midiendo cuánto factura contra cuánto le queda de paquete — justo al revés de lo que debería preocuparle.

En Punto no funciona así. Emitir un documento electrónico no nos cuesta, y por eso no te lo cobramos: facturás lo que vendas, todos los meses, sin contar comprobantes ni renovar nada.

Ver también: Ver el plan completo → /precios

#### El comprobante no es un trámite aparte

_Sale de la venta_

El cajero cobra como siempre y, si el cliente da su RUC, el documento electrónico se genera con esa misma venta: los ítems, las tasas de IVA que realmente se cobraron y el total que cierra exacto contra el ticket.

Punto declara la tasa que se aplicó en el momento de vender, no la que figura hoy en el catálogo. Si cambiás un precio o una tasa después, los documentos ya emitidos siguen contando la verdad de esa venta.

Ver también: Ver la venta facturada → /modulos/punto-de-venta

#### Sabés en qué estado está cada documento

_Aprobado o pendiente, siempre visible_

Cada documento muestra si SIFEN lo aprobó, si está en camino o si algo lo frenó, con su CDC a la vista y el KuDE listo para descargar o mandarle al cliente.

Si el envío falla — se cayó la conexión, el servicio no responde — el documento se reintenta solo. La venta nunca queda trabada esperando al fisco: se cobra igual y el comprobante se acomoda después.

Ver también: Ver los documentos → /modulos/panel

#### Nota de crédito electrónica, atada a su factura

_Cuando hay que corregir_

Una devolución no se resuelve borrando la factura: se emite la nota de crédito electrónica vinculada al documento original, con las tasas congeladas de esa venta.

Y si un documento tiene que anularse ante SIFEN, se cancela desde el panel indicando el motivo — con permiso propio, para que no lo haga cualquiera desde la caja.

Ver también: Ver una nota de crédito → /modulos/clientes-y-credito

---

### Stock y compras

`/modulos/stock-y-compras`

**Saber qué hay, antes de que falte**

Cada venta descuenta y cada compra repone. La factura del proveedor se carga sacándole una foto — la IA extrae los artículos, las cantidades y los precios — y el costo queda al día sin tipear una línea.

Grupo: Para comercio

#### Lo esencial

- Saldo por depósito y por sucursal, actualizado con cada movimiento.
- La factura del proveedor se carga con una foto: la IA extrae los datos y vos aprobás.
- La compra ingresa la mercadería con el costo real y deja la deuda al proveedor.
- Mínimos con aviso, para reponer antes del quiebre y no después.

#### Venta, compra, producción y ajuste tocan el mismo saldo

_Una sola aritmética_

Vender, comprar, producir, transferir entre sucursales, registrar una merma o cerrar un conteo modifican el inventario por el mismo camino. Por eso el número no diverge según por dónde entró el movimiento — y el costo de lo vendido tampoco.

Cada movimiento queda registrado con su motivo, su usuario y su hora. Cuando el saldo no cuadra, se puede ver exactamente qué pasó en vez de suponerlo.

Ver también: Ver el historial de un artículo → /modulos/panel

#### La factura del proveedor se carga sola, con IA

_Sacale una foto_

Cargar una compra a mano es tipear veinte líneas mirando un papel. En Punto le sacás una foto a la factura — o subís el PDF — y la IA extrae el proveedor, el número de comprobante, cada artículo con su cantidad, su precio y su IVA.

Lo que sale es un borrador para revisar, no un movimiento hecho: corregís lo que haga falta y recién al aprobarlo entra la mercadería al stock. La IA nunca toca el inventario ni la caja por su cuenta.

Ver también: Ver el borrador de una factura → /modulos/punto-ai

#### Aprobada la compra, el costo queda al día

_Del papel al inventario_

Al aprobar el borrador entra la mercadería con el costo real de esta compra, y el margen de cada artículo se recalcula con ese número. Si fue a crédito, la deuda con el proveedor aparece sola en cuentas por pagar, con su vencimiento.

El alta manual sigue disponible y termina en el mismo lugar: haya venido de una foto o de la carga a mano, la compra es una sola cosa en el sistema.

Ver también: Ver cuentas por pagar → /modulos/clientes-y-credito

#### El mínimo avisa mientras todavía hay tiempo

_Antes del quiebre_

Cada artículo puede tener su mínimo por depósito. Cuando lo toca, aparece en la lista de reposición — no cuando ya se acabó y el cliente se fue con las manos vacías.

La misma lista sirve para armar el pedido al proveedor, así reponer deja de depender de que alguien se acuerde de mirar la góndola.

Ver también: Ver la reposición → /modulos/stock-y-compras

---

### Clientes y crédito

`/modulos/clientes-y-credito`

**La libreta del mostrador, jubilada**

Quién compró, qué se llevó y cuánto debe, con su límite y sus recibos. Un pago puede saldar varias facturas de una vez, y el saldo sale siempre de los movimientos reales, no de un número escrito a mano.

Grupo: Para comercio

#### Lo esencial

- Cuenta corriente con límite por cliente y aviso al superarlo.
- Un cobro se reparte entre varias facturas pendientes de una sola vez.
- Cada cobro deja su recibo, y uno mal cargado se revierte sin romper el saldo.
- El perfil del cliente muestra qué compra, cuándo y en qué sucursal.

#### Lo que debe un cliente sale de sus movimientos

_El saldo no se escribe, se calcula_

El pendiente de cada factura se recalcula sumando lo que se cobró contra ella, en vez de guardarse en una columna que alguien puede tocar. Por eso el saldo del cliente y el de la factura nunca se contradicen.

Si un cobro se cargó mal, se revierte y todo vuelve a su lugar solo — sin ajustes manuales que después nadie sabe explicar.

Ver también: Ver un estado de cuenta → /modulos/panel

#### Un pago, todas las facturas que alcance

_Cobrar de una vez_

El cliente que viene a saldar tres facturas no obliga a cargar tres cobros: se ingresa el monto y el sistema lo reparte entre las pendientes, empezando por las más viejas.

También se puede cobrar parcialmente una factura puntual. En los dos casos sale el recibo, y lo cobrado impacta en la caja del turno como cualquier otro ingreso.

Ver también: Ver un cobro → /modulos/punto-de-venta

#### Qué compra cada cliente, cuándo y dónde

_Conocer al que vuelve_

El perfil de cada cliente muestra su historia: qué se lleva, a qué hora suele venir, con qué paga y en qué sucursal compra. Sirve para decidir qué reponer, cuándo abrir y a quién conviene llamar.

Los datos son del negocio, no de una plataforma: si el cliente dejó de venir, el sistema puede mostrarlo antes de que sea tarde.

Ver también: Ver el perfil de un cliente → /modulos/punto-ai

---

### Producción y recetas

`/modulos/produccion-y-recetas`

**Lo que se produce descuenta lo que se usa**

Cargás la receta una vez y cada plato, torta o combo descuenta sus insumos al producirse o al venderse. El costo sale del insumo real, así sabés cuánto te deja cada cosa antes de fijar el precio.

Grupo: Para gastronomía

#### Lo esencial

- La receta define qué insumos y en qué cantidad lleva cada producto.
- Producción directa o previa: descontar al vender, o armar tandas y stockear.
- El costo del producto se calcula con el costo real de sus insumos.
- La merma se registra con su motivo, en vez de desaparecer del inventario.

#### Armar la tanda de madrugada o al momento de vender

_Dos formas de producir_

Una panadería hornea a las cuatro de la mañana y stockea lo producido; una cocina arma el plato recién cuando lo piden. Punto soporta las dos: producción previa, donde la tanda entra al inventario como un artículo más, y directa, donde vender descuenta los insumos en ese mismo momento.

Cada artículo elige su modelo y no se mezclan, así el inventario de insumos nunca se descuenta dos veces por lo mismo.

Ver también: Ver una orden de producción → /modulos/stock-y-compras

#### Cuánto cuesta cada plato, con números y no a ojo

_El margen, antes de vender_

El costo del producto se arma sumando sus insumos al costo con el que entraron. Cuando sube la harina, el costo del pan sube solo — y el margen que veías deja de ser el de hace tres meses.

Con eso a la vista, subir un precio o cambiar una receta deja de ser una corazonada.

Ver también: Ver el costo de un producto → /modulos/panel

#### La merma se registra, no se descuenta en silencio

_Lo que se pierde también cuenta_

El pan que sobró, la fruta que se pasó, la botella que se rompió: la merma entra con su motivo y su responsable, y sale del inventario como un movimiento más.

Al final del mes se puede mirar cuánto se perdió y por qué, en vez de descubrir el faltante recién en el conteo.

Ver también: Ver la merma del mes → /modulos/stock-y-compras

---

## Rubros

_La misma propuesta contada para cada tipo de negocio._

### Punto para restaurantes

`/para/restaurantes`

**El salón, la cocina y la caja, en sintonía**

El pedido llega directo a cocina, cada mesa muestra su cuenta abierta y la factura sale al cerrar, con mitades y agregados escritos tal como los pidió el cliente. Sin cuaderno y sin gritos al pasaplatos.

Grupo: Gastronomía

#### Lo esencial

- El pedido de cada mesa entra a la cocina en el momento, con agregados y aclaraciones literales.
- La cuenta se divide en partes iguales o por lo que consumió cada uno, desde la misma pantalla.
- La factura electrónica sale al cerrar la mesa y se envía sola.
- Si se corta internet, la caja sigue emitiendo: al volver la conexión todo se sincroniza.

#### El pedido llega a cocina tal como se pidió

_Sin gritos al pasaplatos_

En hora pico el salón no tiene tiempo que perder: el mozo carga el pedido en la mesa y la comanda aparece en cocina con su hora de entrada, sus agregados y sus aclaraciones, sin pasar por un papel que se pierde entre la barra y la plancha.

La cocina arma las tandas por orden de llegada, no por quién reclamó más fuerte. Y cuando el plato cambia — sin cebolla, punto jugoso, para llevar — eso baja literal, no interpretado.

Ver también: Ver cómo trabaja la cocina → /modulos/pantalla-de-cocina

#### Dividir, cobrar y facturar en el mismo paso

_La cuenta sin drama_

Al final de la comida cada uno sabe cuánto le toca: en partes iguales o por lo que pidió. La mesa se cobra en efectivo, QR o tarjeta — o mezclado — y la factura electrónica sale en ese mismo toque, con el RUC que el cliente diga.

Nada de reconstruir la mesa desde tres papeles: la cuenta vivió en el sistema desde el primer pedido.

Ver también: Ver el cobro de una mesa → /modulos/mesas-y-ordenes

#### Caja por turno y reportes que no piden planilla

_El día cierra en números_

Cada turno abre y cierra su caja: lo esperado contra lo contado, con cada movimiento anotado. El dueño ve el día por sucursal — qué se vendió, a qué hora, con qué margen — sin esperar a que alguien pase todo a una planilla el lunes.

Ver también: Ver el arqueo del turno → /modulos/punto-de-venta

#### Módulos que más usa

- Mesas y órdenes — /modulos/mesas-y-ordenes
- Pantalla de cocina — /modulos/pantalla-de-cocina
- Punto de Venta — /modulos/punto-de-venta
- Panel — /modulos/panel

---

### Punto para minimarkets

`/para/minimarkets`

**La fila avanza y el stock se cuida solo**

Escanear, cobrar y facturar en segundos, con el stock descontándose en cada ticket. Los mínimos avisan antes de que falte y la compra al proveedor deja el costo al día. La caja rinde por turno, no por confianza.

Grupo: Retail

#### Lo esencial

- El código de barras arma el ticket: escanear, cobrar, siguiente cliente.
- Cada venta descuenta stock al instante; el mínimo avisa antes del quiebre.
- La factura electrónica sale del mismo ticket cuando el cliente la pide.
- El cierre de turno compara lo esperado contra lo contado, cajero por cajero.

#### Cobrar al ritmo del escáner

_La fila no espera_

En hora pico el mostrador se mide en segundos por cliente. El ticket se arma escaneando, el total se hace solo y el cobro acepta efectivo, QR o tarjeta sin cambiar de pantalla. Si el cliente pide factura, sale con su RUC en el mismo paso.

El teclado alcanza para todo el flujo — la caja de alto volumen no depende del mouse ni de menús escondidos.

Ver también: Ver la caja rápida → /modulos/punto-de-venta

#### El stock avisa, no sorprende

_Antes de que falte_

Cada ticket descuenta stock en el momento, por depósito. Cuando un producto toca su mínimo, aparece en la lista de reposición — y la compra al proveedor carga la mercadería y actualiza el costo sin doble tipeo.

El inventario deja de ser un fin de semana de conteo: los ajustes quedan con fecha, usuario y motivo.

Ver también: Ver la reposición → /modulos/stock-y-compras

#### Caja por cajero, diferencia con nombre

_El turno rinde cuentas_

Cada cajero abre su turno y lo cierra con arqueo: lo que el sistema esperaba contra lo que se contó. Los retiros y gastos del día quedan anotados en el momento, no reconstruidos de memoria a las diez de la noche.

Ver también: Ver el cierre de turno → /modulos/punto-de-venta

#### Módulos que más usa

- Punto de Venta — /modulos/punto-de-venta
- Panel — /modulos/panel
- Punto AI — /modulos/punto-ai
- Gift cards y vales — /modulos/gift-cards

---

### Punto para farmacias

`/para/farmacias`

**Vender con receta, vencimiento y crédito bajo control**

El mostrador cobra rápido, el stock vigila vencimientos y cada cliente con cuenta corriente tiene su límite y su historia. La factura electrónica sale en el mismo paso, ya lista para el organismo fiscal.

Grupo: Retail

#### Lo esencial

- Búsqueda por nombre, droga o código de barras, con el precio de cada lista.
- El lote y el vencimiento se controlan al vender, no al descubrir la caja vencida.
- La cuenta corriente del cliente lleva límite, saldo y recibos de cada pago.
- La factura electrónica del cliente sale del mismo ticket, sin trámite aparte.

#### Encontrar el producto como lo pida el cliente

_El mostrador no adivina_

Por marca, por droga o escaneando la caja: el buscador responde al primer intento y muestra existencia por sucursal. Si en esta sucursal no hay, se ve dónde sí — y la venta no se pierde por no saber.

Ver también: Ver la búsqueda → /modulos/punto-de-venta

#### Vencimientos vigilados por el sistema

_Primero en vencer, primero en salir_

Cada lote entra con su vencimiento y el reporte de próximos a vencer ordena la góndola antes de que sea pérdida. Lo vencido no se vende: la caja lo frena, no el ojo del cajero.

Ver también: Ver próximos a vencer → /modulos/stock-y-compras

#### Cuenta corriente con límite y recibo

_La libreta, jubilada_

El cliente de siempre compra a crédito con un límite definido, y cada pago queda con su recibo. La cobranza del mes sale de un listado, no de una libreta que solo entiende quien la escribió.

Ver también: Ver cuentas corrientes → /modulos/clientes-y-credito

#### Módulos que más usa

- Punto de Venta — /modulos/punto-de-venta
- Panel — /modulos/panel
- Punto AI — /modulos/punto-ai

---

### Punto para ferreterías

`/para/ferreterias`

**Miles de artículos, un mostrador que no duda**

Buscar entre miles de códigos, vender fraccionado, cotizar obras y llevar cuenta corriente de los clientes de siempre. El stock por depósito y los precios por lista, sin planillas paralelas.

Grupo: Retail

#### Lo esencial

- El buscador encuentra el código entre miles de artículos con una marca, una medida o el nombre a medias.
- Los tornillos, caños y todo lo que se vende suelto se cobra por unidad, metro o kilo, no por bulto cerrado.
- La cotización para una obra se arma en minutos y se convierte en venta sin cargar todo de nuevo.
- El cliente de cuenta corriente compra fiado dentro de su límite y paga cuando cobra la obra.

#### El mostrador encuentra el artículo al primer intento

_Miles de códigos, un solo buscador_

El cliente pide "un caño de media" o "el tornillo autoperforante de una pulgada" y el buscador responde por nombre, medida o código, sin que el vendedor tenga que memorizar dónde está cada cosa entre miles de artículos.

Si en el depósito de esta sucursal no queda, se ve al toque dónde sí hay stock, antes de mandar al cliente a buscar en otro lado.

Ver también: Ver el buscador de artículos → /modulos/punto-de-venta

#### Fraccionado, por metro o por kilo, sin perder margen

_Se vende suelto_

No todo se vende en su envase cerrado: el tornillo se cobra por unidad, el caño por metro y el cemento a veces por bolsa partida. Cada artículo tiene su unidad de venta real, y el margen se calcula sobre eso, no sobre el bulto entero.

La cotización para una obra junta materiales de rubros distintos — caños, cables, cemento — y cuando el cliente confirma, se convierte en venta con un solo toque, sin recargar cada ítem de nuevo.

Ver también: Ver una cotización de obra → /modulos/punto-de-venta

#### Cuenta corriente y stock por depósito, sin planillas paralelas

_El cliente de siempre_

El maestro de obra o el cliente frecuente compra fiado dentro de un límite, y cada pago queda registrado con su recibo — la cobranza del mes sale de un listado, no de una libreta atrás del mostrador.

El stock se lleva por depósito: lo que hay en el local no es lo mismo que lo que hay en el galpón de atrás, y cada venta descuenta del lugar correcto.

#### Módulos que más usa

- Punto de Venta — /modulos/punto-de-venta
- Panel — /modulos/panel
- Punto AI — /modulos/punto-ai

---

### Punto para ropa y accesorios

`/para/ropa-y-accesorios`

**Talles, colores y temporadas en orden**

Variantes por talle y color sin duplicar artículos, cambios y devoluciones con nota de crédito, y el reporte de qué se mueve antes de recomprar la temporada.

Grupo: Retail

#### Lo esencial

- Cada talle y color del mismo modelo es una variante, no un artículo nuevo que duplicar.
- Un cambio o una devolución se resuelve con nota de crédito, sin romper la caja del día.
- El reporte de lo más vendido dice qué reponer antes de recomprar la temporada.
- El mostrador cobra a un precio y el mayorista a otro, desde la misma lista de precios.

#### Talle y color sin multiplicar artículos

_Un modelo, todas sus variantes_

El vestido "floreado corto" es un solo artículo con sus variantes de talle y color: buscarlo en el mostrador muestra de una el stock de cada combinación, sin tener que adivinar entre veinte códigos parecidos.

Cuando un talle se agota, se ve al instante — y el vendedor puede ofrecer el color que sí queda antes de perder la venta.

Ver también: Ver las variantes de un modelo → /modulos/punto-de-venta

#### Cambios y devoluciones con nota de crédito

_El cambio no rompe la caja_

Cuando la clienta vuelve con la prenda porque no le entró el talle, el cambio se resuelve con nota de crédito: la prenda vuelve al stock y el saldo queda a favor para la próxima compra, sin que el cierre de caja del día quede descuadrado.

Ver también: Ver una nota de crédito → /modulos/clientes-y-credito

#### Qué se movió y a qué precio venderlo

_Antes de recomprar_

El reporte de ventas por temporada dice qué modelos y talles se movieron y cuáles quedaron colgados, para no recomprar de nuevo lo que no salió. Y el mostrador vende a un precio mientras el cliente mayorista compra a otro, desde la misma lista de precios sin duplicar catálogo.

Ver también: Ver lo más vendido de la temporada → /modulos/punto-de-venta

---

### Punto para bares y cafés

`/para/bares-y-cafes`

**La barra no para y la cuenta no se pierde**

Sirve para bares, cafeterías, heladerías, panaderías y confiterías: la cuenta queda abierta por mesa o por cliente, los agregados bajan claros a la barra o al mostrador, y la noche fuerte cierra en un arqueo que no deja dudas.

Grupo: Gastronomía

#### Lo esencial

- La cuenta se abre por mesa o por cliente y queda ahí hasta que alguien pide cerrarla.
- Un café con leche de almendra, un helado con dos toppings o una docena mixta de facturas: el agregado baja claro a la barra o al mostrador.
- Vender por peso, por unidad o por docena — el helado al kilo, la factura por unidad — desde la misma pantalla.
- El gift card se vende, se carga y se descuenta como un medio de pago más.

#### Cada mesa con su cuenta, cada pedido en orden

_La barra en hora pico_

El sábado a la noche la barra recibe pedidos de diez mesas a la vez, más los que piden parado. Cada mesa tiene su cuenta abierta desde el primer pedido, y sumar un café más o una porción de torta no obliga a recontar toda la mesa desde cero.

Lo mismo para el cliente que se sienta solo en la barra: su cuenta se abre a su nombre y se cobra cuando él lo pide, sin mezclarla con la mesa de al lado.

Ver también: Ver la barra en hora pico → /modulos/punto-de-venta

#### Leche, toppings o docena: el pedido baja claro

_El agregado que cambia todo_

En la cafetería el agregado es la leche o el shot de más; en la heladería, el segundo topping o la crema; en la panadería, si la docena es surtida o de un solo tipo. Cada rubro tiene su propio agregado y Punto lo deja elegir sin inventar un artículo nuevo por cada combinación.

El mostrador vende por unidad, por kilo o por docena según el producto — la facturería no se pesa, el helado sí, y el sistema sabe la diferencia sin que el vendedor tenga que acordarse.

Ver también: Ver los agregados por producto → /modulos/punto-de-venta

#### Dividir la cuenta, cobrar con gift card, arquear al final

_La noche fuerte cierra en números_

Cuando el grupo pide dividir la cuenta, cada uno paga lo suyo desde la misma pantalla — en efectivo, QR, tarjeta o con un gift card que ya tiene cargado. El gift card se vende como cualquier producto y se descuenta solo cuando el cliente lo usa.

Al cerrar la noche, el arqueo compara lo esperado contra lo contado sin depender de que alguien se acuerde de cada movimiento. La noche más fuerte del mes queda tan clara como cualquier martes tranquilo.

Ver también: Ver el arqueo de la noche → /modulos/punto-de-venta

---

### Punto para comida rápida

`/para/comida-rapida`

**El mostrador no frena y la comanda llega clara**

El pedido se arma en segundos con sus combos y agregados, la pantalla de cocina lo reparte por estación y cada cliente sabe si es para el salón o para llevar. El pico de la noche no descontrola nada.

Grupo: Gastronomía

#### Lo esencial

- El combo se arma con un toque y el agregado — doble carne, sin cebolla, extra queso — baja literal a la plancha.
- La pantalla de cocina separa el pedido por estación: plancha, freidora, armado.
- Cada pedido queda marcado como para el salón o para llevar, sin confundirse en el mostrador.
- En el pico de la noche el mostrador sigue cobrando al mismo ritmo, con el teclado alcanzando para todo.

#### Combos y agregados que bajan claros a la plancha

_El mostrador no puede frenar_

El cliente pide una hamburguesa doble, sin cebolla, con papas grandes y una gaseosa — todo en un combo armado con un toque. El pedido baja a la plancha exactamente así, sin que el cocinero tenga que adivinar qué significa 'la de siempre pero distinta'.

Cada agregado tiene su precio propio, así que el combo se cobra completo sin que el cajero tenga que sumar a mano lo que cambia respecto al de la carta.

Ver también: Ver un combo armado → /modulos/punto-de-venta

#### La pantalla de cocina reparte el pedido, no lo amontona

_Cada estación con lo suyo_

En vez de un solo papel con todo mezclado, la pantalla de cocina muestra a la plancha lo que le toca a la plancha y a la freidora lo que le toca a la freidora. Cada estación ve su parte del pedido y lo marca listo cuando termina.

El pedido completo se arma solo cuando todas las estaciones terminaron la suya — así nada sale a medias ni se enfría esperando la papa.

Ver también: Ver la pantalla de cocina → /modulos/pantalla-de-cocina

#### El pico de la noche sin perder el hilo

_Salón o para llevar_

Cada pedido queda marcado desde que se toma: para el salón, con su número de mesa, o para llevar, con el nombre de quien lo espera. En el pico de la noche eso evita que un pedido para llevar se sirva en una bandeja o que uno de salón se quede armado en el mostrador.

El cierre del turno junta todo lo cobrado — salón y para llevar — en un solo arqueo, sin planillas separadas por tipo de pedido.

Ver también: Ver el pico de la noche → /modulos/punto-de-venta

---

### Punto para dark kitchen

`/para/dark-kitchen`

**Una cocina, varias marcas, un solo control**

Sin salón que atender, todo pasa por la comanda: varias marcas operando desde la misma cocina, cada pedido por estación, el tiempo de preparación medido y el costo de cada plato bajo control.

Grupo: Gastronomía

#### Lo esencial

- Cada marca que opera desde la cocina tiene su propio menú y su propia numeración, aunque compartan el mismo espacio.
- La comanda llega por estación: armado, plancha, frituras, cada una ve solo lo suyo.
- El tiempo entre que entra el pedido y sale listo queda medido, plato por plato.
- La receta de cada plato fija el costo real, y el margen se ve sin recalcular a mano.

#### Cada marca con su menú, todas con la misma comanda

_Varias marcas, una sola cocina_

Una cocina puede operar dos o tres marcas distintas al mismo tiempo — cada una con su propio menú, sus propios precios y su propia numeración de pedidos — sin que eso signifique manejar tres sistemas separados.

El pedido entra identificado con su marca desde el primer momento, así la cocina sabe para cuál de las tres está armando cada plato.

Ver también: Ver el pedido por marca → /modulos/punto-de-venta

#### La comanda por estación, sin amontonar

_Sin salón, con orden_

Sin mozos ni mesas, todo el ritmo de la cocina depende de la comanda: cada estación — armado, plancha, frituras — ve solo los pasos que le tocan, y el pedido completo se arma cuando todas terminaron.

El tiempo entre que el pedido entra y sale listo queda registrado por plato, así se ve qué preparación se está atrasando antes de que se acumulen diez pedidos esperando lo mismo.

Ver también: Ver el tiempo de preparación → /modulos/punto-de-venta

#### La receta dice cuánto cuesta cada plato

_El costo, plato por plato_

Cada plato tiene su receta cargada con los insumos exactos que lleva, y el costo se recalcula solo cuando cambia el precio de un ingrediente. Así el margen de cada plato de cada marca se ve al toque, sin planilla aparte.

El reporte del día junta lo que vendió cada marca y a qué costo, para saber cuál plato conviene empujar y cuál está perdiendo margen sin que nadie lo note.

Ver también: Ver el costo por plato → /modulos/produccion-y-recetas

---

### Punto para decoración y hogar

`/para/decoracion-y-hogar`

**Del catálogo a la entrega, sin perder el hilo**

Un catálogo grande con fotos, variantes de color y medida, cotizaciones para amueblar un ambiente entero y entregas que se pactan para más adelante con una seña. El stock se lleva por depósito, no de memoria.

Grupo: Retail

#### Lo esencial

- Cada artículo puede tener foto, y sus variantes de color o medida se buscan sin duplicar el catálogo.
- Los artículos de bajo movimiento y alto valor — un sillón, una mesa de diseño — se controlan igual que los de rotación diaria.
- Una cotización para amueblar un ambiente se arma en un documento y se convierte en venta cuando el cliente confirma.
- La entrega diferida se pacta con seña, y el saldo se cobra el día que el mueble sale del depósito.

#### Fotos, variantes y stock por depósito

_El catálogo se ve, no se adivina_

Un sillón de tres cuerpos en tres colores no son tres artículos distintos: es un modelo con sus variantes, cada una con su foto y su stock propio. El vendedor busca el modelo y muestra al cliente lo que hay en cada color sin ir hasta el depósito a confirmar.

El stock se lleva por depósito — lo que está en el local de exhibición no es lo mismo que lo que espera en el galpón — y cada venta descuenta del lugar correcto.

Ver también: Ver el catálogo con variantes → /modulos/punto-de-venta

#### Alto valor, bajo movimiento, mismo control

_Lo que se vende poco pero vale mucho_

Una lámpara de diseño o una mesa importada no se venden todos los días, pero cuando se venden el margen importa. Cada unidad queda identificada, así no hay que confiar en la memoria de quién la vio pasar por el depósito la semana pasada.

El reporte de rotación separa lo que se mueve rápido de lo que espera meses, para no recomprar por reflejo lo que ya sobra en el depósito.

Ver también: Ver la rotación por artículo → /modulos/punto-de-venta

#### Cotizar el ambiente, entregar cuando esté listo

_El proyecto entero, en un documento_

Cuando el cliente quiere amueblar un living completo, la cotización junta sillón, mesa, lámpara y alfombra en un solo documento con un total — y si el cliente confirma, se convierte en venta sin cargar todo de nuevo.

Si la entrega es para dentro de tres semanas porque el mueble llega de otra sucursal, se cobra una seña ahora y el saldo el día que sale del depósito, con la fecha de entrega pactada quedando escrita, no prometida de palabra.

Ver también: Ver una cotización con entrega diferida → /modulos/punto-de-venta

---

### Punto para barberías

`/para/barberias`

**Turnos cortos, sillones llenos, nadie esperando de más**

La agenda encadena los turnos de cada barbero sin espacios muertos, el cliente recibe su recordatorio antes de venir y el cobro se hace en el mismo turno, con lo que compró de producto sumado al servicio.

Grupo: Salud y belleza

#### Lo esencial

- La agenda muestra los sillones en simultáneo, con cada turno encadenado al siguiente sin espacios muertos.
- El cliente recibe la confirmación y el recordatorio del turno antes de venir, sin llamada de por medio.
- Cada turno pasa por sus estados — confirmado, atendido, ausente — así se ve quién no vino sin revisar la agenda entera.
- El corte se cobra en el momento, con la cera o el aceite que el cliente se lleva sumado al mismo ticket.

#### Turnos encadenados, sillón por sillón

_El sillón no puede quedar vacío_

Un corte dura veinte minutos y el siguiente cliente ya está sentado esperando: la agenda muestra los sillones en simultáneo, uno por barbero, y cada turno nuevo se encadena al anterior sin dejar huecos que nadie llena.

Si un barbero atiende corte y barba en el mismo turno, el tiempo se ajusta solo — la agenda no trata todos los servicios como si duraran lo mismo.

Ver también: Ver la agenda de sillones → /modulos/punto-de-venta

#### Confirmación y recordatorio, sin llamar a nadie

_El cliente no se olvida_

Apenas se agenda el turno, el cliente recibe la confirmación; el día anterior, el recordatorio. Nadie de la barbería tiene que llamar uno por uno para asegurarse de que se acuerden.

Cada turno queda marcado como confirmado, atendido o ausente, así al final del día se ve de un vistazo cuántos turnos se perdieron y de quién — sin repasar la agenda completa buscando huecos.

Ver también: Ver los estados del turno → /modulos/punto-de-venta

#### Cobrar desde el turno, con lo que se lleva sumado

_El corte y el producto, un solo cobro_

Cuando el cliente termina, el cobro se hace desde el mismo turno: el corte, la barba y la cera que se lleva quedan en un solo ticket, sin pasar por una caja aparte a recalcular todo.

La ficha del cliente guarda su historial de cortes, así el próximo barbero que lo atienda sabe qué le hicieron la última vez sin tener que preguntar.

Ver también: Ver el cobro desde el turno → /modulos/punto-de-venta

---

### Punto para peluquerías

`/para/peluquerias`

**Cada servicio con su tiempo real, cada clienta con su historia**

Un corte no dura lo mismo que una coloración: la agenda respeta el tiempo real de cada servicio, la ficha de la clienta guarda qué tono se usó la última vez, y el producto que se lleva se suma al cobro del día.

Grupo: Salud y belleza

#### Lo esencial

- La agenda respeta el tiempo real de cada servicio: un corte no ocupa lo mismo que una coloración o un tratamiento.
- La confirmación y el recordatorio llegan solos antes del turno, sin que nadie tenga que llamar.
- La ficha de la clienta guarda el color, la marca y el tono usados la última vez.
- El shampoo o la crema que se lleva la clienta se cobra en el mismo ticket que el servicio.

#### La agenda respeta el tiempo real de cada uno

_Ningún servicio dura lo mismo_

Un corte lleva media hora, una coloración con tiempo de pausa lleva dos horas, y un tratamiento capilar otra cosa distinta. La agenda arma cada turno con la duración real del servicio elegido, así no se agenda un color en el mismo espacio que un corte.

Cuando la coloración necesita tiempo de pausa, ese hueco queda reservado en la agenda del box, sin que otra clienta se agende encima sin querer.

Ver también: Ver la agenda por servicio → /modulos/punto-de-venta

#### La ficha de la clienta no se olvida de nada

_El tono de la última vez_

Cada clienta tiene su ficha con el historial de servicios: qué tono de color se usó, qué marca, qué tratamiento pidió la última vez. La próxima visita empieza sabiendo eso, no reinventando la fórmula a ojo.

La confirmación del turno y el recordatorio del día anterior salen solos, así la clienta no se olvida y el box no queda vacío por una cita que nadie confirmó.

Ver también: Ver la ficha de la clienta → /modulos/clientes-y-credito

#### Cobrar el turno con lo que la clienta se lleva

_El servicio y el producto, juntos_

Al terminar el servicio, el cobro junta la coloración, el brushing y el shampoo que la clienta compra para su casa en un solo ticket, sin pasar por una caja aparte para el producto de reventa.

Si la clienta prefiere un paquete de sesiones de tratamiento capilar, se vende una vez y se descuenta sesión por sesión en cada visita, sin volver a cobrar cada vez.

Ver también: Ver el cobro con producto → /modulos/gift-cards

---

### Punto para consultorios médicos

`/para/consultorios-medicos`

**La agenda de cada profesional, la historia de cada paciente**

Cada profesional tiene su propia agenda, el paciente recibe recordatorio antes de la consulta y su ficha guarda el historial de visitas. La consulta se cobra como particular o como obra social, sin planilla aparte.

Grupo: Salud y belleza

#### Lo esencial

- Cada profesional tiene su propia agenda, con su duración de consulta y sus días de atención.
- El paciente recibe confirmación y recordatorio del turno antes de venir.
- Cada turno queda marcado como confirmado, atendido o ausente, para no perder el rastro de quién faltó.
- La ficha del paciente guarda sus visitas anteriores, y el cobro distingue particular de obra social.

#### Cada agenda con sus propios tiempos

_Un consultorio, varios profesionales_

Si el consultorio atiende con dos o tres profesionales, cada uno tiene su propia agenda: sus días, sus horarios y la duración real de su consulta, sin mezclar los turnos de uno con los del otro en una sola grilla confusa.

La recepción ve todas las agendas juntas para coordinar la sala de espera, pero cada profesional gestiona la suya sin pisar la del compañero.

Ver también: Ver la agenda por profesional → /modulos/punto-de-venta

#### La ficha guarda cada consulta anterior

_El paciente no repite su historia_

Cada paciente tiene su ficha con el historial de visitas: motivo de consulta, fecha y profesional que lo atendió. El médico llega a la consulta sabiendo qué pasó la última vez, sin que el paciente tenga que repetir todo desde cero.

La confirmación del turno y el recordatorio del día anterior salen solos, y si el paciente falta, el turno queda marcado como ausente en vez de perderse en la agenda sin explicación.

Ver también: Ver la ficha del paciente → /modulos/clientes-y-credito

#### El cobro distingue quién paga qué

_Particular o con cobertura_

Al terminar la consulta, el cobro se hace desde el mismo turno: si el paciente paga particular, sale el RUC en el momento; si tiene obra social, la consulta queda registrada para la liquidación correspondiente sin mezclarse con las particulares del día.

El reporte del día separa las consultas por profesional y por tipo de cobertura, para que cerrar el mes no dependa de repasar la agenda entera a mano.

Ver también: Ver el cobro de la consulta → /modulos/clientes-y-credito

---

### Punto para odontología

`/para/odontologia`

**El tratamiento entero, sesión por sesión, bajo control**

Un tratamiento de varias sesiones se presupuesta una vez y se sigue sesión por sesión, la agenda de cada profesional se arma sola con recordatorio para el paciente, y su ficha guarda el plan completo, no solo la última visita.

Grupo: Salud y belleza

#### Lo esencial

- Un tratamiento de varias sesiones se presupuesta entero, y cada sesión se descuenta de ese plan sin recotizar cada vez.
- La agenda del profesional muestra el turno con confirmación y recordatorio automático para el paciente.
- Cada turno pasa por sus estados — confirmado, atendido, ausente — para hacer seguimiento del plan sin perder sesiones en el camino.
- La ficha del paciente guarda el plan de tratamiento completo, con lo hecho y lo que falta.

#### Presupuestar el tratamiento entero, una sola vez

_El plan, no la sesión suelta_

Un tratamiento de conducto o una ortodoncia no se resuelven en una visita: el presupuesto se arma por el plan completo, con sus sesiones estimadas, y el paciente sabe desde el principio cuánto va a costar todo, no solo la sesión de hoy.

Cada sesión que se cumple se descuenta de ese plan, así nadie tiene que recordar a mano cuántas quedan pendientes ni recotizar cada vez que el paciente vuelve.

Ver también: Ver un presupuesto de tratamiento → /modulos/punto-de-venta

#### Agenda, recordatorio y seguimiento del plan

_El paciente no se pierde una sesión_

Cada sesión del plan se agenda con su propio turno, y el paciente recibe confirmación y recordatorio antes de venir, igual que para cualquier consulta — el tratamiento largo no depende de que se acuerde solo.

Si el paciente falta a una sesión, el turno queda marcado como ausente y el plan sigue esperando esa sesión pendiente, en vez de perderse entre las demás consultas del consultorio.

Ver también: Ver el seguimiento del plan → /modulos/punto-de-venta

#### El cobro sigue al plan, no a la memoria

_Lo que se cobra en cada visita_

Si el plan se paga en cuotas por sesión, cada cobro queda asociado al tratamiento y al paciente, así al final se ve cuánto se cobró del plan y cuánto falta sin reconstruirlo de las boletas sueltas.

La ficha del paciente muestra el plan completo — hecho, pendiente y cobrado — en un solo lugar, listo para la próxima consulta de seguimiento.

Ver también: Ver el cobro por sesión → /modulos/clientes-y-credito

---

### Punto para veterinarias

`/para/veterinarias`

**Cada mascota con su ficha, cada dueño con su historia**

La ficha va por mascota y por dueño, las vacunas y controles se agendan con recordatorio, y el alimento o los accesorios que se llevan se suman a la consulta en el mismo ticket.

Grupo: Salud y belleza

#### Lo esencial

- Cada mascota tiene su propia ficha, y cada dueño puede tener varias, todas enlazadas a su nombre.
- El turno de vacuna o control se agenda con confirmación y recordatorio para que el dueño no se olvide.
- La ficha guarda el historial de vacunas, controles y tratamientos de cada mascota.
- El alimento o los accesorios que el dueño compra se cobran en el mismo ticket que la consulta.

#### Firulais y Michi, cada uno con su historia

_Una ficha por mascota, no por dueño_

Un mismo dueño puede tener perro y gato, y cada mascota tiene su propia ficha con su peso, su raza y su historial — todas enlazadas al mismo dueño para no cargar sus datos de contacto dos veces.

Cuando el dueño llama para consultar por 'el perro', la recepción encuentra la ficha exacta sin confundirla con la del gato de la misma familia.

Ver también: Ver la ficha de la mascota → /modulos/clientes-y-credito

#### Controles y vacunas con recordatorio

_Ninguna vacuna se pasa de fecha_

Cada vacuna aplicada queda registrada en la ficha con la fecha de la próxima dosis, y el turno de control se agenda con su recordatorio para que el dueño no deje pasar la fecha sin darse cuenta.

El estado del turno — confirmado, atendido, ausente — permite hacer seguimiento de los controles que quedaron pendientes, en vez de esperar a que el dueño se acuerde solo.

Ver también: Ver el calendario de vacunas → /modulos/punto-de-venta

#### Alimento y accesorios en el mismo ticket

_La consulta y lo que se lleva_

Cuando el dueño retira a su mascota después de la consulta, el cobro junta el control, la vacuna aplicada y la bolsa de alimento que se lleva en un solo ticket, sin pasar por una caja aparte para el producto.

El stock de alimentos y accesorios se descuenta igual que en cualquier mostrador, así la veterinaria sabe cuándo reponer sin esperar a que falte en la góndola.

---

### Punto para estética y cosmetología

`/para/estetica-y-cosmetologia`

**El paquete de sesiones, seguido de principio a fin**

Un tratamiento de varias sesiones se vende como paquete y se descuenta sesión por sesión, cada una con su turno y su recordatorio, mientras la ficha del cliente sigue el progreso y el insumo usado en cada visita.

Grupo: Salud y belleza

#### Lo esencial

- Un paquete de sesiones se vende una vez y se descuenta sesión por sesión en cada visita.
- Cada sesión se agenda con confirmación y recordatorio, sin que el cliente tenga que acordarse solo.
- El estado del turno — confirmado, atendido, ausente — hace seguimiento del paquete sin perder sesiones.
- La ficha del cliente guarda qué tratamiento e insumo se usó en cada sesión anterior.

#### Vender el tratamiento completo, descontar sesión por sesión

_El paquete, no la sesión suelta_

Un tratamiento de depilación láser o de limpieza facial rara vez se resuelve en una visita: el paquete de sesiones se vende una sola vez, con su precio total, y cada sesión que el cliente cumple se descuenta de ahí, sin recobrar ni recontar a mano.

El cliente ve cuántas sesiones le quedan del paquete que compró, y el centro sabe qué paquetes están por vencerse antes de que el cliente se olvide de usarlos.

Ver también: Ver un paquete de sesiones → /modulos/gift-cards

#### Agenda y recordatorio para no perder el ritmo

_Cada sesión, su turno_

Cada sesión del paquete se agenda con su propio turno, y el cliente recibe confirmación y recordatorio antes de venir — el tratamiento de varias semanas no depende de que se acuerde solo entre sesión y sesión.

Si falta a una sesión, el turno queda marcado como ausente y el paquete sigue mostrando esa sesión como pendiente, para reprogramarla sin perderla de vista.

Ver también: Ver la agenda del paquete → /modulos/gift-cards

#### Seguimiento del cliente y del insumo por sesión

_El insumo también se cuenta_

Cada sesión registra qué producto o insumo se usó — la ampolla, la crema, el gel — así el centro conoce el costo real del tratamiento y no solo el precio de venta del paquete.

La ficha del cliente junta todo: qué tratamientos hizo, con qué resultado y qué insumo se le aplicó cada vez, lista para la próxima sesión sin preguntar de nuevo.

Ver también: Ver el insumo por sesión → /modulos/punto-de-venta

---

## Legales

_Términos, privacidad y reembolsos. Forman parte del contrato._

### Términos y Condiciones

`/terminos`

_Última actualización: 3 de septiembre de 2026_

Estos términos regulan el uso de Punto, el sistema de punto de venta y gestión que ofrecemos en punto.la y app.punto.la. Al crear una cuenta o usar el servicio, el comercio acepta lo que sigue. Está escrito para que lo entienda quien lleva el negocio, no solo un abogado.

#### 1. Quiénes somos

El servicio lo presta Brixton Capital S.A., con RUC 80164242-6 y domicilio en Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay.

punto.la es el sitio público donde contamos qué hace Punto y cuánto cuesta. app.punto.la es la aplicación: el punto de venta, el panel de administración y todo lo que el comercio opera con su cuenta.

Tenemos un solo canal de contacto para todo — soporte, facturación, temas legales y privacidad: info@punto.la. También atendemos por WhatsApp al +595 981 078798.

#### 2. Qué es Punto y qué no es

Punto es un software que se usa por internet (no se instala ni se compra una licencia perpetua). Sirve para vender y cobrar, emitir comprobantes, controlar stock, administrar clientes y ver los números del negocio.

Punto es una herramienta: ordena y muestra la información que el comercio carga y las operaciones que registra. No garantizamos resultados comerciales — ni volumen de ventas, ni margen, ni crecimiento.

Punto no es asesoría contable, tributaria ni legal. Los reportes, libros y comprobantes que genera son insumos para el contador del comercio, no un reemplazo de su criterio profesional ni de las obligaciones que el comercio tenga frente a la SET.

#### 3. Cuenta, alta y responsabilidad de las credenciales

El alta de la cuenta se hace con un número de teléfono, que se verifica con un código de un solo uso enviado por WhatsApp. Ese teléfono identifica al titular de la cuenta.

El titular puede crear los usuarios que necesite y asignarles roles y permisos. Cada usuario opera con su propio acceso, y las cajas se desbloquean con un PIN personal.

El titular es responsable de lo que hagan los usuarios que creó: qué permisos les dio, qué operaciones registraron y qué datos cargaron. Nosotros no podemos distinguir a un usuario legítimo de alguien que consiguió sus credenciales.

- Mantené el teléfono del titular bajo control: con él se recupera el acceso a toda la cuenta.
- No compartas usuarios ni PIN entre personas — el registro de auditoría deja de servir para nada.
- Dá de baja al usuario cuando alguien se va del equipo, el mismo día.
- Avisanos apenas sospeches un acceso no autorizado.

#### 4. Plan, precio y forma de pago

El comercio contrata el plan que elija entre los publicados en punto.la/precios. Ahí figuran, siempre vigentes, el precio de cada uno, sobre qué se cobra y qué incluye. Se factura en PYG.

El ciclo es mensual y se renueva automáticamente mientras la cuenta esté activa. Cada renovación se cobra por adelantado, al inicio del período.

El cobro se procesa a través de un procesador de pagos externo, con los medios que estén habilitados en cada momento. Los datos completos de la tarjeta se ingresan en el entorno del procesador: nosotros no los vemos ni los guardamos.

Los precios que publicamos incluyen los impuestos aplicables: lo que se ve es lo que se paga. Por cada cobro emitimos el comprobante fiscal a nombre de los datos que el comercio tenga cargados en su cuenta.

#### 5. Créditos de IA

El plan contratado incluye una cantidad de créditos de inteligencia artificial por mes, publicada en punto.la/precios. Cubren el uso de Punto AI: preguntar por los números del negocio, pedir un reporte, leer una factura de compra con la cámara.

Los créditos se renuevan al inicio de cada ciclo mensual y no se acumulan: lo que no se usa en el mes se pierde.

Cuando se agotan, el resto del sistema sigue funcionando con normalidad — solo dejan de estar disponibles las funciones de IA hasta la renovación. Si el comercio necesita más, puede comprar créditos adicionales.

#### 6. Facturación electrónica

Punto emite los documentos electrónicos del comercio y los transmite a la SET a través de un proveedor habilitado. La emisión es ilimitada: no cobramos por comprobante.

El timbrado, los puntos de expedición, los datos fiscales de la empresa y el cumplimiento de las obligaciones tributarias son responsabilidad del comercio. Punto no es agente fiscal ni representante del comercio ante la autoridad tributaria.

No respondemos por rechazos, observaciones o multas originados en datos cargados por el comercio (timbrado vencido, RUC del cliente incorrecto, tasas mal configuradas), ni por interrupciones del servicio de la autoridad tributaria o del proveedor de transmisión.

#### 7. Responsabilidad del comercio por el uso legal del sistema

Punto pone la herramienta; lo que se hace con ella lo decide el comercio. La información que se carga, los comprobantes que se emiten, las operaciones que se registran y las que se dejan de registrar son actos del comercio y de las personas a las que le dio acceso.

Brixton Capital S.A. no audita, no supervisa y no valida el contenido que el comercio carga, y no responde por el uso que se le dé al sistema en infracción de la ley. Esto incluye, sin limitarse a ello, la evasión o elusión de tributos, la omisión o adulteración de operaciones, la emisión de comprobantes que no respalden operaciones reales, el lavado de activos, y el incumplimiento de la normativa laboral, de defensa del consumidor o de protección de datos personales vigente en el país donde el comercio opera.

Si una autoridad o un tercero le reclama a Brixton Capital S.A. por un hecho de esa clase atribuible al comercio, el comercio nos mantiene indemnes: asume su propia defensa y responde por los costos, multas o condenas que se deriven.

Detectar un uso de este tipo nos habilita a suspender o dar de baja la cuenta de inmediato y sin reembolso, y a responder los requerimientos que nos haga una autoridad competente en el marco de sus facultades.

#### 8. Cancelación, mora y baja

No hay contrato de permanencia ni cargo por cancelar. El comercio da de baja la cuenta cuando quiere, escribiéndonos a info@punto.la. La baja se hace efectiva al final del ciclo ya pagado: hasta esa fecha el servicio sigue funcionando completo y después no se renueva.

Si un cobro falla, lo reintentamos durante los 7 días corridos siguientes y avisamos por los canales de contacto de la cuenta. Pasados esos 7 días sin regularizar, la cuenta se suspende: no se puede vender ni emitir, y el panel queda en modo de solo lectura para que el comercio consulte y exporte su información.

Tras la baja, el comercio tiene 30 días corridos para exportar sus datos. Vencido ese plazo eliminamos la información operativa de la cuenta, salvo lo que la normativa fiscal nos obliga a conservar. Para coordinar una exportación escribinos a info@punto.la.

#### 9. Reembolsos

La suscripción se paga por adelantado y no se devuelve la parte no usada del mes en curso: el servicio queda disponible hasta que termine el ciclo pagado.

Tampoco se devuelve una vez iniciada la puesta en marcha de la cuenta —configuración, alta de sucursales y usuarios, importación de datos, acompañamiento—, porque ese trabajo consume recursos desde el primer día y no se recupera.

Sí devolvemos el dinero cuando el cobro no correspondía —un cobro duplicado, un error de facturación nuestro, un cobro posterior a una baja ya efectiva— y cuando una falla del servicio atribuible a Punto impide operar y no la corregimos en un plazo razonable.

El detalle completo —qué cubre, cómo se pide, en cuánto se responde y en cuánto se acredita— está en la Política de Reembolsos, en punto.la/reembolsos. Esa política forma parte de estos términos.

#### 10. Propiedad de los datos

Los datos que el comercio carga y genera en Punto son del comercio: su catálogo, sus clientes, sus ventas, sus comprobantes, su stock.

Nosotros los tratamos únicamente para prestar el servicio, darle soporte y cumplir obligaciones legales. No los vendemos, no los cedemos a terceros con fines comerciales y no los usamos para publicidad.

El comercio puede exportar su información cuando quiera desde el panel, y pedirnos una exportación asistida si necesita un formato distinto.

#### 11. Uso aceptable

Punto se usa para operar un negocio legítimo. Al usarlo, el comercio se compromete a no hacer nada de lo siguiente:

- Registrar operaciones de actividades ilegales o usar el sistema para simular operaciones inexistentes.
- Ocultar, suprimir o alterar operaciones ya registradas para declarar menos de lo que corresponde, o llevar registros paralelos con ese fin.
- Emitir comprobantes a nombre de terceros sin autorización, o usar timbrados que no le pertenezcan.
- Enviar mensajes masivos no solicitados desde los canales del sistema.
- Intentar eludir límites técnicos, acceder a datos de otros comercios, o hacer ingeniería inversa del software.
- Revender, sublicenciar o dar acceso al sistema a terceros como si fuera un servicio propio.
- Compartir credenciales entre personas o entre comercios distintos.

#### 12. Disponibilidad, mantenimiento y modo offline

Trabajamos para que el servicio esté disponible todo el tiempo, pero ningún sistema lo está al 100%. Puede haber interrupciones por mantenimiento, por fallas de nuestros proveedores de infraestructura o por causas fuera de nuestro control.

Las ventanas de mantenimiento programado se avisan con al menos 48 horas de anticipación y se hacen de madrugada, en el horario de menor actividad. Las incidencias no programadas se comunican por los canales de soporte mientras se están resolviendo.

El punto de venta funciona sin internet: la venta se emite igual y queda guardada en el dispositivo. Lo que necesita conexión es la sincronización — enviar la operación a la nube, transmitir el documento electrónico y compartir el estado con otras cajas. Mientras no haya conexión, esas partes quedan pendientes y se resuelven solas al volver.

No ofrecemos un acuerdo de nivel de servicio (SLA) con compromisos de disponibilidad medidos, salvo que se firme un contrato corporativo específico que lo incluya.

#### 13. Propiedad intelectual

El software, el diseño, la documentación, la marca Punto y todo lo que compone el servicio son propiedad de Brixton Capital S.A. o de quienes nos licenciaron esos elementos.

Mientras la cuenta esté activa y al día, el comercio recibe una licencia de uso no exclusiva, no transferible y revocable, limitada a operar su propio negocio.

Esa licencia no incluye derecho a copiar el software, derivar productos de él, usar la marca sin autorización escrita, ni ofrecerlo a terceros.

#### 14. Cambios de estos términos y del precio

Podemos actualizar estos términos: cambia el producto, cambian las normas, aparecen situaciones que el texto no contemplaba. Los cambios se avisan con al menos 15 días de anticipación por los canales de contacto de la cuenta y publicando la nueva versión con su fecha de vigencia.

Los cambios de precio se avisan con al menos 30 días de anticipación y nunca se aplican de forma retroactiva al ciclo en curso.

Si el comercio no está de acuerdo con un cambio, puede dar de baja la cuenta sin penalidad antes de que entre en vigencia. Seguir usando el servicio después de esa fecha implica aceptar la nueva versión.

#### 15. Soporte

El soporte online funciona 24/7 por WhatsApp al +595 981 078798, por el chat del sitio y por info@punto.la.

Cubre el uso del sistema: cómo hacer algo, revisar una configuración, entender un comportamiento, resolver un problema técnico de la plataforma.

No cubre la operación del negocio del comercio: no cargamos su catálogo por él en el día a día, no registramos sus ventas ni tomamos decisiones contables o fiscales por él. La puesta en marcha inicial sí está acompañada.

#### 16. Limitación de responsabilidad

El servicio se presta "tal como está" y "según disponibilidad". Hacemos nuestro mejor esfuerzo, y aun así el software puede tener errores.

En la medida que lo permita la ley, no respondemos por daños indirectos, lucro cesante, pérdida de oportunidades comerciales, ni por pérdida de datos que el comercio pudo haber exportado y no exportó.

Nuestra responsabilidad total acumulada por cualquier reclamo relacionado con el servicio no supera el monto que el comercio nos haya pagado en los 12 meses anteriores al hecho que originó el reclamo.

Tampoco respondemos por las consecuencias del uso que el comercio le dé al sistema en infracción de la ley, según lo previsto en la sección sobre responsabilidad del comercio por el uso legal del sistema.

Nada de lo anterior limita responsabilidades que la ley declare no renunciables.

#### 17. Ley aplicable y jurisdicción

Estos términos se rigen por las leyes de la República del Paraguay.

Cualquier controversia se somete a los tribunales ordinarios de la ciudad de Asunción, con renuncia a cualquier otro fuero. Antes de llegar ahí, preferimos hablarlo: escribinos y buscamos una solución.

#### 18. Contacto

Brixton Capital S.A. — RUC 80164242-6

Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay

info@punto.la · +595 981 078798

---

### Política de Privacidad

`/privacidad`

_Última actualización: 3 de septiembre de 2026_

Esta política explica qué datos personales tratamos cuando un comercio usa Punto, para qué los usamos, con quién los compartimos y qué puede hacer cada persona con los suyos. Está escrita en criollo a propósito: una política que nadie entiende no protege a nadie.

#### 1. Quién es responsable

Brixton Capital S.A., con RUC 80164242-6 y domicilio en Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay, es quien presta el servicio Punto y quien responde por el tratamiento descripto en esta política.

Para cualquier tema de privacidad o para ejercer derechos sobre tus datos, escribinos a info@punto.la.

#### 2. Los dos roles: cuándo decidimos nosotros y cuándo decide el comercio

Esta es la sección más importante de todo el documento, porque define quién responde por qué.

Respecto de los datos de la CUENTA del comercio — el teléfono del titular, el nombre, el email, el RUC, los usuarios del equipo, los datos de facturación — nosotros somos los responsables: decidimos para qué se usan y respondemos por su tratamiento.

Respecto de los datos que el comercio CARGA sobre sus propios clientes — nombres, teléfonos, direcciones, historial de compras, cuentas corrientes — el responsable es el comercio. Él decide qué carga, para qué y con qué base legal. Nosotros actuamos como encargados del tratamiento: solo procesamos esos datos siguiendo sus instrucciones y para que el sistema funcione.

En criollo: si sos cliente de un comercio que usa Punto y querés saber por qué tienen tu teléfono, el que te tiene que responder es ese comercio. Nosotros lo asistimos técnicamente, pero no decidimos qué datos suyos guarda.

#### 3. Qué datos tratamos

Agrupados por origen, esto es lo que pasa por el sistema:

- De la cuenta: teléfono del titular, nombre, email, RUC y razón social de la empresa, sucursales, cajas, usuarios del equipo con sus roles y permisos.
- De la operación: ventas, comprobantes emitidos, compras, movimientos de stock, cajas abiertas y cerradas, órdenes, y los contactos que el comercio carga sobre sus propios clientes y proveedores.
- Técnicos: dirección IP, tipo de dispositivo y navegador, sesiones activas, registros de actividad y auditoría (quién hizo qué y cuándo), y registros de error para diagnosticar fallas.
- De facturación: plan contratado, historial de cobros y su estado, comprobantes que emitimos al comercio. Los datos completos de la tarjeta quedan en el procesador de pagos, no en nuestros sistemas.

#### 4. Para qué los usamos

Tratamos los datos únicamente para operar el servicio que el comercio contrató:

- Prestar el servicio: que la caja venda, que el panel muestre los números, que el stock se descuente.
- Autenticar: verificar el teléfono en el alta, validar el PIN de caja, mantener las sesiones y detectar accesos indebidos.
- Cobrar la suscripción y los créditos adicionales, y emitir los comprobantes correspondientes.
- Emitir y transmitir los documentos electrónicos del comercio a la SET.
- Dar soporte: entender qué pasó cuando algo falla, y responder consultas.
- Cumplir obligaciones legales, contables y fiscales, y responder requerimientos de autoridad competente.

#### 5. No vendemos datos ni hacemos publicidad con ellos

No vendemos, alquilamos ni cedemos datos personales a terceros con fines comerciales.

No usamos los datos del comercio ni los de sus clientes para publicidad de terceros, ni construimos perfiles publicitarios. El sitio no tiene píxeles de redes sociales ni herramientas de analítica publicitaria.

No usamos los datos de un comercio para beneficiar a otro. Cada cuenta está aislada de las demás.

#### 6. Inteligencia artificial

Punto AI, el asistente del sistema, y la lectura automática de facturas de compra funcionan con modelos de lenguaje que corremos a través de un proveedor externo que enruta cada consulta al modelo elegido.

Qué se envía: la pregunta que escribe el usuario, más los datos del negocio que hacen falta para responderla (por ejemplo, el resumen de ventas del período consultado). En la lectura de facturas se envía la foto o el archivo del comprobante.

Qué no se envía: nada más que eso. El asistente no vuelca la base de datos del comercio al modelo, y lo que puede leer o modificar está limitado por los permisos del usuario que lo está usando.

Ese proveedor y los modelos que enruta actúan como subencargados, bajo compromisos contractuales de confidencialidad. Los datos enviados no se usan para entrenar modelos de terceros.

#### 7. Con quién compartimos

Compartimos datos solo con los proveedores necesarios para que el servicio funcione, y solo lo mínimo que cada uno necesita. Todos actúan como subencargados del tratamiento, bajo compromisos contractuales de confidencialidad y seguridad.

Los listamos por categoría y no por nombre porque un proveedor puede cambiar sin que cambie el tratamiento. La lista actualizada de los proveedores concretos que usamos en cada categoría se entrega a pedido: escribinos a info@punto.la.

| Categoría de proveedor | Para qué |
| --- | --- |
| Procesamiento de pagos | Cobro de la suscripción y de los packs de créditos de IA, y devolución de los reembolsos que correspondan. |
| Infraestructura y almacenamiento en la nube | Servidores donde corre el sistema y guardado de los archivos que el comercio sube (fotos de productos, adjuntos). |
| Facturación electrónica | Proveedor habilitado que transmite los documentos electrónicos a la SET. |
| Modelos de inteligencia artificial | Generación de las respuestas del asistente y lectura automática de las facturas de compra. |
| Envío de email y SMS | Notificaciones y comunicaciones transaccionales del sistema. |
| Mensajería instantánea | Envío del código de verificación en el alta de la cuenta. |
| Chat de atención al cliente | Atención embebida en punto.la, operada por Brixton Capital S.A. |
| Pasarelas de cobro del comercio | Medios de pago que el comercio habilita para cobrarle a sus clientes desde la caja. No son cobros de Punto: los datos van directo del cliente a la pasarela. |

#### 8. Cookies y tecnologías similares

En la aplicación usamos cookies y almacenamiento local estrictamente necesarios: mantener la sesión iniciada, recordar el dispositivo de caja emparejado, guardar las preferencias de la interfaz y permitir que el punto de venta funcione sin conexión.

En el sitio punto.la el único script de terceros es el del chat de atención, que guarda un identificador de conversación para que no se pierda el hilo si recargás la página.

No usamos cookies publicitarias, de perfilado de terceros ni de redes sociales. No hay píxeles de seguimiento.

Podés bloquear o borrar cookies desde la configuración de tu navegador. Si bloqueás las de la aplicación, no vas a poder iniciar sesión ni operar la caja: son las que sostienen el acceso.

#### 9. Transferencias internacionales

Los servidores donde corre Punto y varios de los proveedores listados arriba están fuera de Paraguay. Eso significa que los datos se transfieren y se procesan en otros países.

Esas transferencias se hacen bajo compromisos contractuales de confidencialidad y seguridad con cada proveedor, limitados a las finalidades descriptas en esta política.

#### 10. Cuánto tiempo conservamos los datos

Mientras la cuenta esté activa, conservamos los datos para que el comercio pueda operar y consultar su historial.

Tras la baja, hay 30 días corridos para exportar la información. Vencido ese plazo eliminamos los datos operativos de la cuenta.

Hay una excepción: los documentos electrónicos emitidos y los respaldos contables se conservan por el plazo que exige la normativa fiscal, aunque la cuenta se haya dado de baja. No podemos borrarlos antes.

Los registros técnicos de error se conservan 90 días, los necesarios para diagnosticar una falla. El registro de auditoría de operaciones acompaña a la cuenta mientras esté activa: es parte del historial del negocio.

#### 11. Seguridad

Aplicamos medidas técnicas y organizativas razonables para proteger la información:

- Cifrado del tráfico en tránsito (HTTPS) en todo el sistema.
- Contraseñas y PIN almacenados con funciones de hash, nunca en texto plano.
- Aislamiento por comercio: cada cuenta opera sobre su propio espacio de datos y las consultas están acotadas a él.
- Control de acceso granular por permisos y roles, definidos por el titular de la cuenta.
- Registro de auditoría de las operaciones sensibles, con identificación del usuario que las hizo.
- Acceso restringido de nuestro equipo, limitado a lo necesario para soporte y operación.

#### 12. Notificación de incidentes

Ninguna medida de seguridad es infalible. Si ocurre una brecha que afecte datos personales, la tratamos como incidente prioritario.

Contenemos el incidente, evaluamos el alcance y notificamos a los comercios afectados dentro de las 72 horas de confirmado qué datos se vieron involucrados, junto con lo que recomendamos hacer. Si la normativa lo exige, notificamos también a la autoridad competente.

Cuando el comercio sea el responsable de los datos afectados (los de sus propios clientes), le damos la información que necesite para cumplir con sus propias obligaciones de notificación.

#### 13. Tus derechos

Toda persona cuyos datos tratamos puede pedirnos acceder a ellos, rectificarlos, actualizarlos, solicitar su supresión, pedir una copia en formato portable u oponerse a determinados tratamientos.

Para ejercerlos, escribí a info@punto.la indicando qué querés hacer. Para proteger tus datos de un tercero que se haga pasar por vos, vamos a pedirte que verifiques tu identidad — normalmente confirmando el control del teléfono o del email asociados.

Respondemos dentro de los 15 días hábiles de recibido el pedido verificado. Si el caso requiere más tiempo, te avisamos por qué y en cuánto lo resolvemos.

Si el pedido es sobre datos que un comercio cargó sobre vos como su cliente, te vamos a derivar a ese comercio, que es el responsable — y lo asistimos para que pueda responderte.

#### 14. Datos de los clientes del comercio

Cuando un comercio carga en Punto los datos de sus clientes, sigue siendo él el responsable frente a esas personas.

Eso implica que el comercio debe tener una base legal para tratar esos datos, informar a sus clientes qué hace con ellos, y responder los pedidos de acceso, rectificación o supresión que reciba.

Nosotros lo asistimos técnicamente: le damos las herramientas para buscar, editar, exportar y eliminar la información de un contacto, y respondemos sus consultas sobre cómo hacerlo.

#### 15. Menores de edad

Punto es un servicio para comercios y sus equipos de trabajo. No está dirigido a menores de edad ni recolectamos datos de menores a sabiendas.

Si detectamos que se cargaron datos de un menor sin base legal, o si nos lo informan a info@punto.la, los eliminamos dentro de los 5 días hábiles.

#### 16. Cambios a esta política

Si cambia lo que hacemos con los datos, actualizamos esta política y publicamos la nueva versión con su fecha de vigencia.

Los cambios relevantes se avisan además por los canales de contacto de la cuenta, con al menos 15 días de anticipación.

#### 17. Contacto

Brixton Capital S.A. — RUC 80164242-6

Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay

info@punto.la · +595 981 078798

---

### Política de Reembolsos

`/reembolsos`

_Última actualización: 3 de septiembre de 2026_

Esta política dice cuándo devolvemos el dinero y cuándo no, en qué plazo y cómo se pide. Forma parte de los Términos y Condiciones de Punto.

#### 1. Qué cubre esta política

Punto le cobra al comercio dos cosas, y ninguna más: la suscripción al plan contratado y los packs de créditos de IA que compre aparte cuando quiere más de los incluidos en ese plan.

No cobramos por comprobante emitido, por usuario, por producto ni por transacción. No hay costo de instalación, de puesta en marcha ni de baja. Si alguna vez ves un cargo distinto de esos dos conceptos, escribinos: es un error y lo devolvemos.

Esta política aplica a los cobros que hace Punto. No aplica a los cobros que el comercio le hace a sus propios clientes desde la caja: esos son entre el comercio y su cliente, y la devolución la resuelve el comercio con sus propias reglas.

#### 2. Regla general: el mes empezado no se devuelve

La suscripción se paga por adelantado al inicio de cada ciclo mensual. Al dar de baja no devolvemos la parte no usada del mes en curso.

Lo que sí garantizamos es que ese mes se presta completo: el servicio queda disponible con todas sus funciones hasta el último día del ciclo pagado, y después no se renueva ni se vuelve a cobrar.

No hay contrato de permanencia, ni cargo por cancelar, ni monto mínimo. Dar de baja es escribir un mensaje.

#### 3. La puesta en marcha, una vez iniciada, no se devuelve

Contratar Punto no es descargar un archivo: apenas se confirma el pago empieza un trabajo concreto sobre la cuenta. Crearla y configurarla, dar de alta sucursales, cajas y usuarios con sus permisos, importar el catálogo y los clientes, dejar operativa la facturación electrónica, y acompañar al equipo del comercio en la puesta en marcha.

Ese trabajo consume horas de nuestro equipo, infraestructura y servicios de terceros desde el primer día, y no se recupera si el comercio decide después no seguir. Por eso, una vez iniciada la puesta en marcha no devolvemos el importe pagado.

Vale igual si la cuenta se terminó usando poco o nada: lo que consume el recurso es el trabajo hecho y el servicio puesto a disposición, no cuánto se lo haya usado.

Esto no toca las cuatro situaciones que enumera «Cuándo sí devolvemos». Un cobro duplicado, un error de facturación nuestro, un cobro posterior a una baja o una falla que impida operar se devuelven igual, esté la puesta en marcha iniciada o no.

#### 4. Cuándo sí devolvemos

Hay cuatro situaciones concretas en las que la devolución corresponde y la hacemos sin discutir:

- Cobro duplicado: se cobró dos veces el mismo ciclo. Devolvemos el importe duplicado, íntegro.
- Error de facturación imputable a Punto: se cobró un monto distinto del que corresponde al plan, o se cobró una sucursal que no está dada de alta. Devolvemos la diferencia.
- Cobro posterior a una baja ya efectiva: el comercio pidió la baja y el sistema igual cobró el ciclo siguiente. Devolvemos ese cobro completo.
- Falla del servicio atribuible a Punto que impide operar y que no logramos corregir en un plazo razonable. En ese caso el reembolso es proporcional a los días del ciclo que no se prestaron.

#### 5. Créditos de IA

Los créditos mensuales que trae el plan son parte de la suscripción, no un producto aparte: no se reembolsan por separado, no se acumulan de un mes al otro y no se convierten en dinero ni en descuento.

Los packs de créditos que el comercio compra aparte no son reembolsables una vez acreditados en la cuenta, porque quedan disponibles para usar desde ese mismo momento.

La excepción son los casos que enumera «Cuándo sí devolvemos»: si el pack se cobró dos veces, se cobró por error nuestro o se cobró después de una baja, lo devolvemos igual que la suscripción.

#### 6. Cómo se pide un reembolso

El pedido se hace por escrito a info@punto.la, dentro de los 30 días corridos contados desde la fecha del cobro. Pasado ese plazo no procesamos el reclamo.

Para poder resolverlo sin idas y vueltas, el mensaje tiene que incluir:

- El nombre de la empresa y su RUC, tal como figuran en la cuenta.
- La fecha y el monto del cobro que se reclama.
- El motivo: cuál de los casos de esta política aplica.

#### 7. Plazos y forma de devolución

Respondemos todo pedido de reembolso dentro de los 5 días hábiles de recibido, diciendo si corresponde o no y por qué.

La devolución se hace por el mismo medio de pago con el que se cobró, a través del procesador que tomó el cobro. No devolvemos en efectivo, ni a una cuenta distinta, ni como crédito para usar en el sistema.

Una vez aprobado, Punto ordena la devolución dentro de los 5 días hábiles. La acreditación efectiva depende del emisor de la tarjeta o del banco del comercio y suele tomar entre 5 y 15 días hábiles adicionales. Ese último tramo no lo controlamos y no prometemos una fecha: lo que sí hacemos es darte el comprobante de la devolución ordenada para que puedas reclamarle a tu banco si se demora.

#### 8. Contracargos

Si hay un cobro que no reconocés, escribinos primero a info@punto.la. Casi todo se resuelve más rápido por acá que por el banco: nosotros vemos el cobro en el momento y podemos devolverlo directamente.

Si en cambio se abre un contracargo con el banco o la tarjeta, el proceso pasa a manos del emisor y puede tardar semanas. Mientras dure la disputa, la cuenta puede quedar suspendida.

Un contracargo resuelto a favor del comercio cierra el tema y no genera ningún cargo adicional de nuestra parte.

#### 9. Impuestos

El reembolso incluye los impuestos que se hayan cobrado sobre el importe devuelto: se devuelve lo que efectivamente se pagó, no el monto sin impuestos.

Cuando el cobro original tenía comprobante fiscal, emitimos el documento que corresponde a la devolución y se lo mandamos al comercio para que su contador lo registre.

#### 10. Contacto

Brixton Capital S.A. — RUC 80164242-6

Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay

info@punto.la · +595 981 078798

---

## Preguntas que el sitio no responde

_Escrito a mano, no sale del sitio: es lo que pregunta la gente y todavía no está publicado._

Lo que el equipo contesta por WhatsApp y no está en ninguna página. A
diferencia del resto de `content/sitio/`, este archivo **no se genera**: se
edita a mano y sobrevive a `npm run export:content`.

Regla para el agente: si una respuesta de acá contradice a una página del
sitio, gana la página. Y si la pregunta no está contemplada, es mejor
ofrecer contacto con el equipo que improvisar una respuesta.

#### Qué es Punto y qué vende

**¿Qué productos o servicios ofrecen?**
Punto es un solo producto: un sistema de gestión para comercios que se
contrata por suscripción mensual, por sucursal. Incluye el punto de venta
(con modo offline), el panel de administración, la facturación electrónica
ilimitada, el asistente Punto AI, y todos los módulos del sistema — mesas y
órdenes, pantalla de cocina, stock y compras, clientes y crédito,
producción y recetas, gift cards, cotizaciones, reportes y multi-sucursal.
No se venden módulos por separado ni licencias por puesto: el precio es uno
y adentro no hay límites de usuarios, cajas, productos ni transacciones.
No vendemos hardware; el sistema funciona en la computadora, tablet o
teléfono que el comercio ya tiene.

**¿Venden equipos, impresoras o lectores?**
No. Se puede usar el hardware que el comercio ya tenga, y si necesita
comprar algo, el equipo lo asesora sobre qué modelos funcionan bien.

**¿Es un programa que se instala?**
No. Funciona en el navegador, sin instalar nada. Se entra desde cualquier
dispositivo con la cuenta del comercio.

#### Migración y arranque

**Ya uso otro sistema, ¿puedo pasar mis datos?**
Sí. El catálogo de productos y la lista de clientes se importan desde una
planilla. El histórico de ventas del sistema anterior no se migra: queda
como consulta en el sistema viejo y en Punto se arranca desde el día uno.

**¿Cuánto tarda tener el sistema andando?**
Crear la cuenta y hacer la primera venta es cuestión de minutos. Lo que
lleva tiempo es cargar bien el catálogo: con la planilla de productos lista,
un comercio chico opera el mismo día; uno con miles de artículos o varias
sucursales conviene coordinarlo con el equipo.

**¿Dan capacitación al personal?**
Sí, el acompañamiento en la puesta en marcha está incluido. El POS está
pensado para que un cajero nuevo aprenda en una jornada.

#### Facturación electrónica

**Ya tengo timbrado y facturo con otro proveedor, ¿sirve igual?**
Sí. Se configura el timbrado del comercio en Punto y se sigue facturando con
la numeración que corresponde. Conviene coordinar la fecha de corte con el
equipo para que no se superpongan numeraciones.

**¿Punto hace el trámite de habilitación ante el fisco?**
No. La habilitación como facturador electrónico la gestiona el comercio o su
contador; Punto emite y envía los documentos una vez que está habilitado.
El equipo orienta sobre qué se necesita.

**¿Y si todavía no facturo electrónicamente?**
Se puede empezar usando Punto para vender y controlar el negocio, e ir
sumando la facturación electrónica cuando el comercio la tenga habilitada.

#### Precio y facturación del servicio

**¿Cómo se paga?**
Mes a mes, sin contrato ni permanencia. Los medios de pago disponibles los
confirma el equipo al momento de contratar.

**¿Hay descuento por varias sucursales o por pago anual?**
No está publicado: cualquier condición especial la define el equipo caso por
caso. El agente no debe prometer descuentos.

**¿Hay prueba gratis?**
No hay una prueba autogestionada. Lo que se ofrece es una demostración con
el equipo, sobre casos del rubro del cliente.

#### Límites y expectativas

**¿Funciona sin internet?**
El punto de venta sí: sigue vendiendo y emitiendo, y sincroniza solo cuando
vuelve la conexión. Lo que necesita estado compartido entre cajas —el estado
de las mesas, por ejemplo— requiere conexión.

**¿Sirve para un negocio con varias sucursales?**
Sí. Cada sucursal opera con sus cajas y su depósito, y el panel muestra el
conjunto. Se paga una suscripción por sucursal.

**¿Tiene agenda de turnos o citas?**
Está anunciada para los rubros de salud y belleza, pero **todavía no está
disponible**. Si el cliente pregunta por agenda, recordatorios automáticos o
fichas de paciente, el agente debe decir que está en desarrollo y pasar la
consulta al equipo, sin dar fecha.

**¿Tiene delivery con mapa o gestión de repartidores?**
No. Se registran pedidos para envío, pero no hay rutas, mapa ni app de
repartidor.

**¿Se integra con mi tienda online o con marketplaces?**
No hay integración disponible hoy. Si el cliente lo necesita, conviene
pasarlo al equipo para entender el caso.
