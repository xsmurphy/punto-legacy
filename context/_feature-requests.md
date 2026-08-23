<!-- Feature requests reales de clientes, capturados directo del soporte / contacto comercial.
     NO confundir con roadmap técnico (`10-roadmap.md`) — esto es la pila de productos del usuario.
     Formato: una sección por área del producto. Cada item lleva análisis breve de complejidad,
     dependencias técnicas conocidas y una etiqueta de tamaño (S/M/L/XL). Más reciente arriba. -->

# Feature requests — clientes

## 2026-07-31 — testers, segunda tanda (Requerimientos Panel + POS)

Dos docs nuevos de los mismos testers. Bugs auditados en `10-roadmap.md`
§"Auditoría 2026-07-31". Re-reportado (no se duplica): filtros+columnas de
transacciones, footer-sum TOTAL, etiquetas/canal de venta, gift card
recarga+saldo, conteo de stock por categoría, cocina unificada, viandas
(roadmap §"Consumo a cuenta"), timbrados en caja (ya existe
`register.invoiceAuth`/`invoicePrefix` — el pedido es la UI de settings),
facturación electrónica (en curso, `context/28`).

### Catálogo / Items

- **Crear categoría inline desde el form de artículo** — CERRADO
  (`frontend/components/items/categories-picker.tsx:56-81`).
- **Cantidad de sesiones en packs de sesiones** — CERRADO (`itemSessions`
  expuesto en `items/[id]/page.tsx:930`, `ItemService.php:81`).
- **Stock mínimo + notificación automática** — `M`. Hook natural: centro de
  notificaciones (`context/31`). Requiere umbral por item (¿columna nueva o
  JSONB?) + job que compare stock.
- **Columnas "stock actual" y "costo del stock" en el listado de items** —
  CERRADO (`ItemsQuery.php:171-173`).
- **Historial de movimientos del artículo (kardex) en el detalle** — CERRADO
  (`components/items/stock-tab.tsx`, `StockMovementsService.php`).

### Contactos / Usuarios

- **Comisiones por usuario en Gs. o %** — `M`. Hoy la comisión es por item
  (`itemComissionPercent`/`Type`); esto pide default por usuario.
- **Línea de crédito: dónde cargarla** — CERRADO (`contact-detail-view.tsx`).
- **Cobrar facturas a crédito desde el módulo Clientes** — CERRADO
  (`ContactDetailView` + `AccountStatementSection`).
- **Historial de transacciones en la ficha del contacto** — YA EXISTE
  (tab "Transacciones" de `ContactDetailView`, commit `e63cd670` 2026-07-17,
  con reimpresión y cobro de crédito). Problema de descubribilidad, no de
  código — verificado 2026-08-01.

### Reportes

- **RG90 / Libro de Ventas y Compras (export fiscal PY)** — `L`. Formato
  normado por la SET; definir alcance con el owner.
- **Export/print en TODOS los módulos de reportes** — parcial (verificado
  2026-08-22): 19 de 25 páginas de reporte ya exportan XLSX. Falta export en
  `cashflow`, `orders`, `schedule`; **impresión no existe en ningún reporte**.
- **Notificaciones de facturas a crédito por vencer** con detalle completo
  (doc, cliente, ítems, IVA) — `M`. Centro de notificaciones (`context/31`).
- **Reporte de productos "modo detallado"** (usuario, cliente, doc,
  fecha/hora por venta) — `M`. Es un drill-down de itemSold.
- **Medios de pago: vista "Detallado"** (doc, cliente, RUC, método, sucursal,
  entregado, total) — `S-M`.
- **Control de cajas: imprimir/PDF + edición para admin/jefe** — `M`.
- **Reporte de transferencias de stock** (quién envió/recibió, productos,
  fechas) con formato de **Nota de Remisión** — `M`. La remisión es documento
  fiscal — cruzar con FE (`context/28`).

### Compras / Gastos

- **Columnas doc/timbrado/usuario/condición (contado-crédito) en registro y
  reporte de compras** — CERRADO en el reporte (`reports/purchases/page.tsx:74,88`,
  columnas de documento y timbrado). `/purchase` ya captura timbrado+prefijo+nro.
- **Compra por caja/paquete (1 caja = 24 unidades)** — CERRADO (`packSize`,
  `PurchasesService.php:453`).
- **Subcategorías de gastos** — CERRADO (`fin_category.parentid`, mig 72,
  `CategoryService::resolveParentId()`).
- **Recordar último costo de compra por producto** — la precarga en
  `/purchase` YA EXISTE (verificado 2026-08-01): al elegir el ítem, el
  ProductPicker llama `GET /v1/items?id=X&resource=last-purchase-price`
  (`api/v1/items.php:263-289`, último `itemSoldTotal/itemSoldUnits` de una
  compra) y autorrellena el precio de la línea. **Lo que sigue abierto** es la
  otra mitad del pedido: producción sin stock del insumo genera costos
  negativos / márgenes distorsionados porque `RecipeCosting.php:197-205` usa
  `item.itemCost` vigente, no un último-costo de referencia. Eso es del
  módulo de producción (`context/23`), no de compras — `M`.

### POS / Espacios

- **Selector de mozo al abrir mesa** — parcial, reiterado por el owner
  2026-08-23: backend completo (`space_session.waiterid`;
  `SpaceSessionService.php:35,57,403`) y el tipo ya viaja en
  `use-pos-spaces.ts:86`, pero **falta toda la UI** — ningún componente lo
  setea al abrir mesa. Es trabajo solo de frontend. Sin esto tampoco se puede
  contar personas atendidas por mesero (pedido relacionado más abajo).
- **Unir/cambiar espacio, renombrar y etiquetar desde el POS** — **marcado
  IMPORTANTE por el owner (2026-08-23)**. Incluye que el mozo pueda ponerle
  un nombre a la mesa para identificarla más fácil (ej. "los del
  cumpleaños"). Confirmado abierto por ausencia (auditoría 2026-08-22): hoy
  no hay forma de renombrar/etiquetar ni de unir/cambiar de espacio desde el
  POS.
- **Shortcut de teclado "O" para pantalla de órdenes** — CERRADO
  (`use-pos-hotkeys.ts:99-101`).
- **Cobro por ítems individuales** — YA EXISTE (split `kind='items'`,
  2026-07-27). El tester no lo encontró o le falló (ver bug "Sale transaction
  aborted" en roadmap) — puede ser problema de descubribilidad.

### Impresión

- **Cierre de caja automático al cerrar** — CERRADO (`pos-main-menu.tsx:1101-1110`,
  commit `eac29e7e`).
- **Imprimir recibo al registrar pagos de crédito** — `M`, sigue abierto. Los
  docs traen formatos de referencia de recibo y factura (ver los .docx).
- **Plantillas: columna de IVA por ítem + total IVA** — `S` (template editor).
- **Formatos A4 / preimpresos con posicionamiento** — `L`. Editor de layout
  para hoja completa, distinto del ticket 80mm. Vive en Ajustes: el tamaño de
  papel es configuración de la plantilla, NO se elige en Caja (decisión del
  owner 2026-08-22, ver `10-roadmap.md` punto 5 de la tanda 2026-08-18).

## 2026-07-30 — testers (2 documentos)

Dos documentos de testers ("Cambios para analizar dentro de Punto" — uso real
en caja — y "Punto Panel"). Los 12 candidatos a bug están auditados en
`10-roadmap.md` §"Auditoría 2026-07-30 — reportes de testers". Este bloque es
solo lo que es pedido de producto (no bug) y que **no** está ya cubierto por
el backlog de 2026-06-16 o el de 2026-07-07 — esos, en vez de duplicarse acá,
quedaron marcados "re-reportado" en su lugar de origen (ver notas en
`10-roadmap.md` y en las secciones de abajo). Casi todo el doc "Punto Panel"
es un re-report casi literal del backlog 2026-07-07 — dato de prioridad, no
pedido nuevo.

### Producción / Cocina

**Orden de pedido unificado para cocina (por cantidad de preparación)** — `M`
> "Orden de pedido para producción en cocina: lista de pedidos unificados por
> cantidad de preparación."

- Distinto del KDS actual (`/pos/ordenes`, comandas en fila por pedido
  individual, `context/24-orders-module-plan.md`): esto es una vista
  CONSOLIDADA — "12 empanadas de carne" sumando todos los pedidos abiertos,
  no una tarjeta por pedido. No hay endpoint ni vista que agregue por
  producto entre órdenes abiertas hoy (verificado: cero referencias a
  agregación por item en `frontend/app/(panel)/produccion` ni en el módulo
  de Órdenes).
- Implica: query que agrupe `pos_order_item`/`itemSold` de órdenes en estado
  abierto por `itemId`, sumando cantidades — candidato natural para una
  pantalla nueva en Producción o una vista alternativa del KDS.
- **Reiterado y ampliado por el owner (2026-08-23)**, textual: *"una especie
  de orden de producción, donde de todos los pedidos activos se pueda ver
  qué cantidad se necesita de cada plato, ingrediente, etc., para no
  preparar de a uno sino hacer ya todos los mismos ítems para distintos
  platos de una vez."* Agrega una segunda dimensión a la agregación de
  arriba: no solo por ítem vendido, también **por ingrediente** — cruza con
  recetas (`RecipeCosting`, `explodeRecipe`) para explotar cada plato
  pedido en sus insumos y sumar cantidad total de ingrediente necesaria
  entre todos los pedidos abiertos. No planificar en detalle todavía —
  queda como feature descripta, pendiente de diseño.

### Ventas — canal / tipo de venta

**Identificar canal de venta (Pedidos Ya, Mayoristas) en las ventas
realizadas** — `S`
> "Poder identificar el tipo de venta (Pedidos Ya, Mayoristas) dentro de las
> ventas realizadas."

- Mucho más chico que la integración real con marketplaces (`XL`, ver
  "Pedidos Ya y Monchis" más abajo, sección owner): acá alcanza con poder
  ETIQUETAR manualmente una venta con su canal, no con ingestar pedidos
  automáticamente.
- El modelo ya tiene la pieza: `tags: string[]` en el carrito
  (`frontend/lib/cart/store.ts:226`) y la opción "Etiquetas" en el drawer de
  opciones de venta (`sale-options-drawer.tsx:298`) ya existen. Falta
  exponer esas etiquetas como columna/filtro en el reporte de transacciones
  — ver el ítem de abajo.

### Reportes / Transacciones

**Columna "etiquetas" en el listado de transacciones** — `S`
> Columnas pedidas: factura, nota de crédito, etiquetas internas, método de
> pago, caja registradora.

- Las etiquetas de venta ya se capturan (`CartLine`/`tags` en
  `frontend/lib/cart/store.ts:226`, ver ítem anterior); falta exponerlas
  como columna en `transactions-list.tsx` y en el service de reportes. El
  resto de las columnas pedidas (factura/NC/método/caja) ya está cubierto
  por el backlog 2026-07-07 (`10-roadmap.md`, "Filtros en transacciones...
  + columnas tipo doc/método/caja") — re-reportado, no nuevo.

**Suma de la columna TOTAL al pie del listado** — `S`
> "Que al pie de la columna TOTAL sume el total de transacciones en Gs."

- Footer de agregación en el `<DataTable>` de transacciones. No hay
  precedente de footer-sum en ese listado hoy (verificar si `<DataTable>`
  soporta footer genérico o si hay que agregarlo al componente compartido —
  en ese caso conviene resolverlo en el wrapper, no en la página, para que
  otros reportes lo hereden gratis).

### Gift Card

**Reporte Gift Card editable — recargar créditos + saldo visible en el
cliente** — `M`
> "Poder editarlo para recargar créditos en una gift card ya vendida; mostrar
> en la información del cliente cuánto saldo le queda."

- Genuinamente nuevo — no está en ningún backlog anterior. Dos partes: (1)
  UI de recarga sobre una gift card existente (`giftcard.currentBalance` ya
  es mutable por diseño — el canje ya la decrementa,
  `api/v1/giftcards.php` resource=consume — falta el camino inverso, sumar
  saldo); (2) mostrar el saldo en la ficha del cliente beneficiario
  (`giftcard.beneficiaryContactId` ya vincula la gift card a un contacto,
  `SaleService.php:974-986` — falta el query + la UI en
  `frontend/app/(panel)/contacts/[id]/page.tsx`).
- Relacionado con el bug de case-sensitivity del canje (ver auditoría en
  `10-roadmap.md`) — mismo módulo, no bloqueante entre sí.

## 2026-07-30 — owner

### Settings

**Buscador de secciones arriba del menú de `/settings`** — `S`
> "Hay demasiadas secciones y ya se necesita un buscador."

- Hoy `/settings` lista todas las secciones sin filtro; el menú creció a ~12
  entradas (catalog, devices, espacios, facturación electrónica, price-lists,
  print-templates, printers, roles, sessions, team, …) y va a seguir creciendo.
- Implica: input de filtro arriba del menú + match por nombre y por sinónimos
  (que "impresora" encuentre "print-templates", que "usuarios" encuentre "team")
  — sin sinónimos el buscador falla justo en los casos donde más se necesita.
- Sin backend: el catálogo de secciones ya está en el cliente. Ideal sumarle
  atajo de teclado y navegación con flechas (el patrón de command palette que
  el POS ya usa para buscar ítems).

### Integraciones — delivery marketplaces

**Pedidos Ya y Monchis: los pedidos caen en el panel de pedidos, con sonido y alerta** — `XL`
> "Que los pedidos de cada comercio por estas plataformas caigan en su panel de
> pedidos, debe haber un sonido que indique que llegó un nuevo pedido y una
> alerta para que el cajero pueda tomarlo."

- **La mitad del camino ya está hecha**: el módulo Órdenes (`context/24`) tiene
  el panel (`/pos/ordenes`), estados con transiciones, KDS, pantalla de despacho
  y realtime por WebSocket (canal `kds`). El schema ya contempla el origen
  externo: `pos_order.source CHECK IN ('counter','table','ecommerce','schedule')`
  y `fulfillment` es ortogonal (un pedido de plataforma es `delivery`), así que
  un pedido de PedidosYa entra al mismo flujo sin inventar un modelo paralelo.
- **Lo que falta es la capa de integración por plataforma**, y ahí está el 90%
  del esfuerzo: credenciales por comercio (cada tenant tiene su cuenta en la
  plataforma), ingesta por webhook con endpoint público + verificación de firma,
  reintentos e idempotencia (las plataformas reenvían), y el **mapeo de catálogo**
  — el ítem "Pizza Muzzarella" de PedidosYa tiene que resolver a un itemId de
  Punto o el pedido entra sin stock ni COGS. Ese mapeo es el punto que más suele
  doler: hace falta UI de vinculación producto↔producto por plataforma.
- **Aceptar/rechazar tiene que viajar de vuelta**: si el cajero toma el pedido en
  Punto, la plataforma tiene que enterarse (y viceversa — si el cliente cancela
  en la app, la orden debe cancelarse acá). Es integración bidireccional, no solo
  ingesta.
- **Sonido y alerta**: hoy NO hay infraestructura de audio en el front (cero
  `new Audio()` en todo el frontend). Existía un toggle "Sonidos en alertas"
  (`sonidosAlertas`) sin ningún consumidor — se quitó de Ajustes el 2026-07-30
  justamente por eso, la key sigue en `PosRegisterConfig`. Implica: asset de
  sonido, desbloqueo de autoplay (los navegadores exigen interacción previa del
  usuario — en una tablet de caja que queda abierta todo el día hay que resolver
  el "primer gesto"), y una alerta visual persistente que no dependa de que
  alguien esté mirando la pantalla correcta.
- Sugerencia de corte: F1 ingesta de UNA plataforma (la que dé mejor API/sandbox)
  con mapeo manual de catálogo + sonido/alerta; F2 la segunda plataforma sobre la
  misma abstracción; F3 bidireccional (aceptar/rechazar/cancelar).
- **Dato a confirmar antes de estimar**: ambas plataformas dan API pública de
  partner o hay que ir por integrador (Otter/Deliverect y similares). Cambia por
  completo el tamaño del trabajo.

### Roles y permisos

**Roles personalizados con permisos de Vista / Creación / Edición / Eliminación** — `L`
> "Necesitamos una sección donde podamos crear Roles personalizados, y a estos
> roles asignarle permisos sobre todo el sistema, especialmente de Vista,
> Edición, Creación y Eliminación de contenido."

- **Ya existe la base y bastante más de lo que parece**: `/settings/roles` con
  ABM de roles personalizados y matriz de permisos por checkbox
  (`frontend/app/(panel)/settings/roles/page.tsx`), catálogo de 45 permisos
  (`api/lib/Auth/PermissionCatalog.php`), roles sembrados por tenant
  (Dueño / Encargado / Cajero, `RoleService::SEED_PERMISSIONS`) y
  `hasPermission()` en el backend.
- **El problema real no es que falte la sección: es que la matriz es mayormente
  decorativa.** Remedido el 2026-08-22 (el catálogo creció): son **47
  permisos**, de los cuales **27 se chequean** en algún lado del backend —
  **20 sin ningún gate**. En orden de gravedad: `contacts.user.manage`
  (`api/v1/users.php` POST/PUT/DELETE sin ningún gate — cualquier autenticado
  crea/edita/borra usuarios y roles), `contacts.customer.delete`,
  `contacts.supplier.manage`, `inventory.item.delete`, `pos.sale.refund`
  (`returns.php`), `pos.drawer.open/close`, `settings.register.manage`,
  `billing.view/manage`, `settings.tax.manage`, `settings.template.manage`,
  `settings.device.pair/manage`, `ai.agent.elevated`, `pos.discount.apply`.
  Un rol "solo ver" hoy puede anular ventas.
  **Nota irónica**: el agente IA (`api/v1/ai/execute.php:66-75`) SÍ chequea
  permisos que los endpoints humanos equivalentes no chequean.
- En el frontend el gating es casi inexistente: un solo archivo consume
  `usePermissions()`. La UI no esconde ni deshabilita lo que el rol no puede.
- La granularidad tampoco es uniforme: `inventory.item.*` y `contacts.customer.*`
  ya tienen view/create/edit/delete, pero reportes son solo `.view` y
  finanzas/producción/FE son un único `.manage` indivisible.
- **Trabajo real**: (1) completar el catálogo a CRUD parejo por área,
  (2) enforcement backend en TODOS los endpoints de escritura — es la parte
  grande y la única que da la garantía, (3) gating en el frontend (ocultar/
  deshabilitar), (4) tests por rol. El orden importa: sin (2), (3) es cosmético.
- Relacionado: "Permisos granulares por usuario (override sobre el rol)" del
  2026-06-16 más abajo — mismo sistema, capa extra encima del rol.

## 2026-06-24 — pendientes Panel (owner → desarrollo, lista vieja)

Auditado contra el código el 2026-07-30.

Lista de bugs/feature requests del frontend que el owner mandó en sesiones
anteriores. Varios items pueden estar ya resueltos en el sprint del 2026-06-23
— auditoría inicial pendiente.

### Bulk edit items
- **Opción "Quitar"** en el form de edición masiva — hoy solo permite cambiar categoría/marca/tax pero no eliminar el valor existente. — CERRADO (`frontend/components/items/bulk-edit-dialog.tsx:47-48,109-123`, checkbox "Quitar valor actual")
- **Lista no refresca al instante** al hacer bulk edit — requiere F5. — CERRADO (`frontend/hooks/use-items.ts:109`, `invalidateQueries`)

### Realtime
- **Barra de categorías no se actualiza en tiempo real** — CERRADO en
  general: `use-realtime-sync.ts:55` invalida la queryKey `["taxonomies","category"]`.
  **Bug nuevo encontrado en la auditoría 2026-08-22**: el filtro de categorías
  de Artículos usa la queryKey `["taxonomies"]` a secas (`use-items.ts:483`);
  TanStack no invalida por prefijo, así que la barra de categorías puede no
  actualizarse específicamente en Items.
- **Nuevas funciones de Items no funcionan** (descripción vaga del owner — necesita aclaración). (sigue abierto — necesita aclaración del owner)

### Bugs visibles
- `/stock-transfer/new` — error `Uncaught Error: A <Select.Item /> must have a value prop that is not an empty string`. — CERRADO (usa `const NONE = "__none__"`, `frontend/app/(panel)/stock-transfer/new/page.tsx:58`)
- `/inventory-count` — error `outletId invalido para este tenant` aunque la sucursal seleccionada estaba correcta. — CERRADO (el sentinel se strippea antes del POST, `frontend/app/(panel)/inventory-count/page.tsx:89`; el mensaje exacto del reporte viene de StationService (órdenes), no de este endpoint)
- `/stock-adjustment` — 422 al agregar items (`POST /v1/stock_adjustment`). — CERRADO (mismo strip del sentinel, `frontend/app/(panel)/stock-adjustment/page.tsx:162`)

### Sidebar
- **Contactos como NavGroup** con sub-secciones: Clientes, Proveedores, Usuarios.

---

## 2026-06-24 — pendientes POS (segundo batch, owner)

Auditado contra el código el 2026-07-30.

### Bugs P0
- **Guardar venta falla** — toast "No se pudo guardar la venta" al confirmar. — CERRADO (owner confirmó 2026-08-09 que hace tiempo no ocurre)

### Numpad / cantidades
- **Primer keystroke = reemplazo**, no append. Hoy: abre con `5`, presiono `3` → muestra `53`. Esperado: `3`. — CERRADO (`frontend/components/pos/numeric-pad.tsx:51-79`, `isFirstRef`)
- **Numpad virtual cierra modal** al presionar — bug. — CERRADO (owner confirmó 2026-08-09; el fix es `4c0158d0`, `hooks/use-outside-pointerdown.ts`)
- **Softkeyboards visibles solo si el operador los activa en Ajustes** — útiles solo en pantallas touch; default OFF. — CERRADO (`frontend/lib/ui/store.ts:74`)
- **SHIFT togglea entero ↔ decimales (3 decimales)** para cantidades — gramos, comida por peso, etc. — CERRADO (owner confirmó 2026-08-09)

### Inconsistencia de diseño POS
- **Modal "Agregar usuario" tiene dos UI distintas** según desde dónde se abra:
  - Desde un ítem en el listado de venta (`<LineSellerDialog>`)
  - Desde el menú "Opciones de venta" → Usuario (`<UserDialog>` de `sale-options-drawer`)
- Ninguno tiene **buscador**. Con 50+ usuarios se vuelve inusable.
- Unificar a un solo componente reusable con buscador. — CERRADO (unificado en `frontend/components/pos/seller-picker-dialog.tsx`, tiene Input de búsqueda + filtro; ambos call-sites lo usan)

---

## 2026-06-24 — pendientes POS (primer batch, owner → desarrollo)

Lista de cambios pedidos para el módulo POS (`app/(pos)/pos` en frontend).
Una de varias listas que el owner mandó en sesiones anteriores. Sin priorización
todavía. Auditoría inicial pendiente para marcar qué está implementado.

Auditado contra el código el 2026-07-30.

### Bootstrap / cache local
- **Cargar todo al inicio del POS en localStorage** (clientes, usuarios, bootstrap, config, items). El lockscreen valida PIN contra el array local de usuarios, no contra la API.
- **Bug PIN del master no acepta** — solo el PIN de un usuario funciona, el del master no. (sigue abierto — depende de datos del tenant)

### Visual
- **Color del category bar #22252A** (el neutro de botones).

### Catálogo
- **Faltan artículos en la caja** — no aparecen los agrupados. Verificar que carguen todos los vendibles. (sigue abierto — depende de datos del tenant)

### UX listados
- **Empty state** en búsqueda de items y de clientes cuando no hay resultados. — CERRADO (`frontend/components/pos/product-search-dialog.tsx:136`)
- **No limpiar el input de búsqueda** al cerrar el modal de items/clientes. — CERRADO (`frontend/components/pos/product-search-dialog.tsx:54-67`; es intencional, no bug)
- **Toast "Bienvenido {nombre}"** después de PIN, no solo "Bienvenido". — CERRADO (`frontend/components/register/lock-screen.tsx:118`)

### Cart / venta
- Click en ítem de venta → opciones → **botón "agregar usuario" debe abrir modal con lista de usuarios**.
- Botón modificar cantidad: **número más pequeño** + **SHIFT togglea decimales** (gramos/medidas).
- Descuento por ítem y por venta: **modal numérico**, default moneda (ej. 2.500), **SHIFT togglea %** (ej. 10%).
- **Descuento de venta solo aplica a items presentes al momento de añadirlo** — items agregados después no se afectan. Items con descuento individual tampoco son afectados por el descuento de venta.
- **Confirm nativo del navegador** al cerrar/refrescar si hay items en la lista de venta. — CERRADO (`frontend/app/(pos)/pos/layout.tsx:49-56`)
- **Tab de ventas en curso visible en caja** — para retomarlas sin ir al listado de transacciones (en legacy había que ir hasta ahí).

### Modal de pago
- Mostrar **monto total convertido a las otras monedas configuradas** (según cotizaciones del panel) debajo de los botones.
- **Línea secundaria con Giftcard + Crédito interno**. Giftcard pide código del cliente, valida no vencida + no usada, **consumo total en una sola transacción** (es crédito a favor).

### Apertura de caja
- Si el cajero quiere vender con caja cerrada, **primero pedir monto de apertura**, después mostrar modal de pago. Hoy va directo al pago. — CERRADO (gate por `controlCaja`, `frontend/components/register/cart-panel.tsx:283-289`)

### Datos / auditoría
- **Transacciones con timestamp completo** (hora+minuto+segundo). Hoy aparecen en 0. — CERRADO (`frontend/lib/format-date.ts:105-125`)

### Listado de Transacciones en /pos

Modal full-width split 2 col, ver screenshots del owner (2026-06-24).
Diseño con design system de Punto, NO copiar visual del legacy.

**Izquierda — lista:**
- Header "Transacciones" + date picker single-day (limpiable, default sin fecha → últimas N)
- Input search único — matchea cliente Y `transactionNo`
- Filas: nombre cliente (o "Sin Nombre") + monto + subtexto `fecha hora #comprobante` + badge tipo
- Badges: Contado (neutro), Crédito (destructive), Cotización (warning)
- Botón "Cargar más" al final — paginación tradicional offset/limit

**Derecha — detalle:**
- Header: etiqueta tipo + fecha venc si aplica + cliente + RUC + `#comprobante` + fecha completa
- Botón principal **dinámico por tipo**:
  - Contado/Crédito sin deuda → **DUPLICAR** (merge items al cart actual)
  - Cotización → **FACTURAR** (carga items al cart para convertir)
  - Crédito con deuda > 0 → **PAGAR** (reutiliza `CreditPaymentService`)
- Menú "…": Anular · Agregar · Devolución · Reimprimir · Ver PDF
- Cards `Pagado` / `Deuda` arriba si es crédito
- Card items: cantidad, nombre, vendedor, precio. Combos con sub-líneas indentadas `↳`
- Descuento + **TOTAL** grande
- Tabla pagos abajo: Método / Identificador / Monto

**"Agregar"**: merge items de la transacción con el cart en curso (suma al carrito actual, no reemplaza).
**"Duplicar"**: mismo merge pero implicitamente espera nueva venta; el cajero decide si la convierte en venta, cotización, orden, etc.

**Plan de slices:**
- T1 — modal + lista paginada + buscador + datepicker + detalle (read-only)
- T2 — Duplicar + Reimprimir + Ver PDF
- T3 — Facturar (cotización) + Pagar (crédito)
- T4 — Anular + Devolución
- T5 — Agregar (merge to cart)

---

### Opciones de venta (sección "Opciones")
Catálogo definitivo:
- **Imprimir** — imprime la venta en curso (depende del módulo de impresión).
- **Descuento Global** — aplica al precio de cada item, NO al total. Ej: 2 items de 10.000 con 10% global → cada uno pasa a 9.000. El total siempre es la sumatoria.
- **Nota** — modal con textarea.
- **Usuario** — asigna usuario a todos los items de la venta (caso de uso: cajero asigna el ejecutor del servicio para cálculo de comisiones).
- **Etiquetas** — añade etiquetas a la venta.
- **Guardar** — guarda el estado actual para retomarla más adelante (parked sale).
- **Moneda** — QUITAR, no se usa.
- **Devolución (nota de crédito)** — QUITAR, va dentro de una transacción específica.
- **Cotización** — guarda la transacción como cotización (luego se puede convertir a factura).
- **Remisión** — genera una nota de remisión.
- **Cita** — entra en modo agendamiento; aparece en el calendario.
- **Orden** — entra en modo orden; aparece en el módulo de órdenes; luego se convierte a factura.
- **Lista de precios** — selecciona la lista de precio activa para la venta actual.

---

## 2026-06-16 — batch comercial (soporte → owner)

Lista compilada por soporte tras múltiples contactos con clientes que pidieron paridad con el panel legacy y features nuevas. Sin priorización todavía — pendiente decisión del owner sobre cuáles entran al sprint del rewrite y cuáles esperan.

---

### Contactos / Usuarios

**Permisos granulares por usuario (override sobre el rol)** — `XL`
> "Si hay dos cajeros con el mismo rol, que se pueda habilitar a uno permisos que el otro no tiene."

- Hoy el modelo de roles es plano: `contact.role` (smallint) → 1 = Super Admin, hardcoded. Sin granularidad ni override per-user.
- Implica: tabla `userPermission` (o JSONB `overridePermissions` en contacto) + UI de "permisos" en el form de equipo + integración con `apiAuthTenant`/ACL del backend para chequear permisos efectivos = rol_base ∪ user_override.
- **Bloqueante**: el rewrite todavía no tiene matriz de permisos. Resolverlo bien fuerza definir las claves de permiso del sistema (cobrar, devolver, dar descuento, ver reportes, etc) — eso es deuda pendiente igual.
- Alta demanda comercial — gastronomía y retail con varios cajeros lo piden.

---

### Artículos

**Shortcuts "Producir / Contabilizar / Ajustar / Comprar" arriba de Artículos** — `S`
> Recuperar los 4 botones grandes que estaban en el header del listado de items en el legacy.

- Regresión visual del rewrite. Son links a otros módulos (`/purchase`, `/inventory-count`, `/bulk-adjustment`, `/production`) que ya existen.
- Decisión a tomar: ¿toolbar fijo en `/items` o un dropdown "Acciones" para no saturar el header?

**Predefinido fijo dentro de combos (item base + guarniciones opcionales)** — `M`
> Una hamburguesa fija + N guarniciones a elegir. Hoy todos los items del combo son seleccionables.

- `ComboGroupService` ya existe (`api/lib/Items/ComboGroupService.php`). Hay que verificar si soporta items "obligatorios fijos" (qty fija, no seleccionables) vs "elegibles" (sourceType=manual/category con minSelection/maxSelection).
- Posible que el modelo de datos lo soporte parcialmente y solo falte la UI; posible que requiera flag `isFixed` por línea del combo.

---

### Reportes / Transacciones

**Columna "Tipo de Documento"** — `S`
> Falta identificar factura / nota de crédito / venta interna en el listado.

- `transaction` tiene `transactionDoc` y `transactionType` (0=venta, 3=ticket, 5=cobro, 6=NC, etc). Mapeo a label legible + columna en DataTable. Datos ya están en el endpoint.

**Columnas descuento / subtotal / IVA / total gravado** — `S`
> Faltan columnas estándar de facturación.

- Campos disponibles: `transactionDiscount`, `transactionTax`, `transactionTotal`. Solo agregar al SELECT del service y a las columnas del DataTable.

**Export RG90 + Libro Ventas** — `L`
> Reportes regulatorios paraguayos. El legacy los exportaba.

- Formato definido por la SET — requiere replicar el mapeo del legacy (`panel/reports/rg90.php` o similar). Verificar si el código del export está en el monolito y portarlo.
- Crítico para clientes paraguayos formales.

**Pagos recibidos y cotizaciones** — `S`
> Vistas `cobros` y `quotes` del legacy.

- El endpoint `/v1/reports/transactions?view=cobros|quotes` YA existe (lo vi al diagnosticar el bug de array_key_exists). Solo falta UI en `/reports/transactions` para alternar entre vistas (tabs o filtro).

---

### Reportes / Productos y Servicios

**Modos DETALLADO y COMBOS** — `M`
> Hoy solo hay vista agregada por producto.

- Detallado = una row por venta del producto. Combos = expansión de items del combo.
- Requiere endpoints adicionales o flags en el endpoint actual (`view=detail|combos`).
- Re-reportado por testers el 2026-07-30 ("verificar ventas de productos en
  resumen y tener detalle de sus movimientos").

**Columnas descuento / usuario / comisiones / fecha / cliente** — `S`
> Agregar columnas al reporte.

- Datos disponibles vía `itemSold` JOIN `transaction`. Solo extender el query del service.

---

### Reportes / Análisis de Clientes

**Modos "listas" y "análisis"** — `M`
> Dual view: listado tabular crudo + vista analítica (segmentos, gráficos).

- "Listas" probablemente es la vista actual. "Análisis" requiere KPIs nuevos (segmentación por consumo, frecuencia, churn por cliente).

**Columnas cumpleaños / dirección / ciudad / descuentos aplicados** — `S`
> Agregar columnas al listado de clientes.

- Cumpleaños, dirección y ciudad viven en `contact.data` JSONB tras la migración 25. Hay que extraer con `data->>'contactBirthDay'`, etc. Descuentos requiere agregación de `transaction.transactionDiscount` por cliente.

---

### Reportes / Staff y Usuarios

**Drill-down por usuario con cliente / fecha / N° documento** — `M`
> Click en un cajero → detalle de cada venta que hizo.

- Vista detalle del reporte. Requiere endpoint que filtre `transaction.userId` y devuelva filas individuales (no agregadas).

---

### Reportes / Medios de Pagos

**Modo detallado + columna "caja"** — parcial (verificado 2026-08-22): el
backend YA devuelve `detail[]` (`PaymentMethodsService.php:65-78`); el
frontend solo consume `summary`. Falta la columna "caja" y consumir el
detalle que ya viene. Hallazgo de la misma auditoría: hoy `reports/payment-methods`
calcula y descarta `detail[]` **en cada request** — costo de query pagado sin uso.

---

### Reportes / Compras y Gastos

**Columnas documento / prefijo / tipo / usuario / subtotal / descuentos** — `S`
> Paridad con el reporte de transacciones.

- Mismos campos del modelo `transaction` ya existentes. Extender el query.

**Export RG90 y Libro Compras** — `L`
> Equivalente al Libro Ventas para compras.

- Mismo trabajo que el RG90 de ventas pero del lado de compras.

**Vista de pagos realizados** — `S`
> Equivalente a "pagos recibidos" pero para compras.

- Pattern conocido — `dataset` flag en el endpoint o vista separada.

---

### Órdenes

**Descontar stock al hacer pedido (opcional / setting)** — `M`
> Hoy el descuento del stock probablemente pasa al facturar.

- Decisión de modelo: ¿se reserva stock al tomar la orden (transaccional) o solo al facturar (actual)? Si se reserva → necesita ledger de reservas para devolverlo si la orden se cancela.
- Setting por tenant o por outlet.

**Finalizar en lote pedidos terminados** — `M`
> Multi-select + acción bulk.

- UX típica de tablas. El backend probablemente acepta múltiples updates en paralelo.

**Listar pedidos pendientes para facturar** — `M`
> Vista dedicada de pedidos sin factura aún.

- Filtro por `transactionStatus IN (pendiente, terminado pero sin factura)`. Pantalla nueva o filtro nuevo en la existente.

**Procesar Órdenes → volver a Módulos si no se completa el cobro** — `S`
> UX fix: si abrís Procesar Órdenes desde el listado de módulos y cancelás el cobro, el sistema no te devuelve a Módulos.

- Cambio de navegación / breadcrumb history. Bajo riesgo.

---

### Features mayores (no son solo "columnas" — son módulos o cambios de modelo)

**Suscripciones recurrentes como paquetes** — `XL`
> "Agregar suscripciones como paquetes" — un cliente que paga mensual por un paquete de servicios.

- Modelo nuevo: `subscription` (planId, contactId, status, nextBillingDate). Worker de cobro automático. Integración con cuenta corriente del cliente.
- Distinto a la tabla `plans` (que es para el plan SaaS del tenant). Es para que los tenants vendan suscripciones A SUS clientes.

**"Recibo de dinero" como tipo de transacción propio** — `M`
> Hoy se está usando "venta de servicio (crédito interno)" como hack para recibir dinero sin que sea una venta.

- Nuevo `transactionType` o flag. NO suma a ventas; SÍ entra al cajón. Reportes deben excluirlo de "ventas brutas".
- Hay precedente: `NonAddingSales` ya separa este tipo de movimientos en el resumen.

**Múltiples comisiones por producto+profesional** — `XL`
> "Cuando varios profesionales hacen el mismo servicio con comisiones distintas, hoy hay que crear N copias del servicio."

- Modelo actual: `item.commission` único por producto. Necesario: tabla `itemCommission` (itemId, userId, percent) o JSONB `commissions: {userId: percent}` en item.
- El `itemSold` ya guarda `itemSoldComission` (calculada al vender). Solo cambia QUÉ comisión aplica según el `userId` de la venta.
- Demanda alta en estética/belleza.

**Comisiones diferentes por servicio Y por profesional** — `XL`
> Combinación del anterior — variable por ambos ejes.

- Mismo modelo. Va junto con el item anterior.
- **Distinto** del pedido más simple "Comisiones por usuario (Gs. o %)" del
  backlog 2026-07-07 (`10-roadmap.md`, re-reportado por testers el
  2026-07-30): ese es un % o monto fijo por VENDEDOR sin importar el
  producto — no existe ningún campo `commission` a nivel contacto/usuario
  hoy (verificado, cero referencias en `api/lib/Items/*.php`,
  `api/lib/Contacts/*.php`). Ese pedido simple podría resolverse sin tocar
  el modelo por-producto de este ítem; no bloquear uno esperando al otro.

**Mesas asignadas a meseros, con exclusividad** — `M`
> Restricción de acceso por mesa. Cada mesero solo ve y opera sus mesas.

- Nueva relación `table → userId` (asignación). Filtro en el listado de mesas + permission check al abrir orden.
- **Reiterado por el owner (2026-08-23) con precisión: es exclusividad, no
  solo visibilidad** — las mesas asignadas a un mozo **no pueden ser
  modificadas por otros**. No es solo UI: necesita enforcement en el
  backend (rechazar la escritura, no solo ocultar en el front). Se cruza
  con el trabajo de permisos que entró el 2026-08-22 (`hasPermission`, rol
  `device`, gates documentados en `context/08-convenciones-criticas.md`) —
  antes de implementar, decidir si la exclusividad es un permiso más del
  catálogo o una regla de datos aparte (dueño de la sesión de mesa).
  Mismo pedido ya anotado en `context/10-roadmap.md` § Espacios v1 (owner
  2026-08-21) — no duplicar el diseño, converger acá.

**Contar personas atendidas por mesero** — `S`
> Capturar # de comensales al abrir una mesa, agregar al reporte de staff.

- Campo `partySize` o `guests` en la transacción de mesa. Reporte ya tiene la dimensión userId; solo agregar suma.
- Depende de "Selector de mozo al abrir mesa" (arriba, § POS/Espacios) — sin
  esa UI tampoco hay quién contar.

**Último costo de compra en producción (fix cálculo COGS)** — `M`
> Bug en el cálculo de utilidad cuando se compra mercadería específicamente para producción: el sistema descuenta antes de calcular el costo de la producción, lo que mete ruido en el cálculo de precio final cuando se usa "% sobre costo".

- Bug semántico de `manageStock` / `Inventory::manageStock` + cálculo de `itemSoldCogs` para items producidos.
- Probablemente requiere snapshot del `lastPurchaseCost` por item antes de descontar.
- **Re-reportado dos veces el 2026-07-30** (docs "Cambios para analizar" y
  "Punto Panel"), con una SEGUNDA motivación que unifica con esta: si un
  insumo se agota (ej. harina a 8.000 Gs/kg) y se sigue registrando
  producción antes de cargar la factura de compra nueva, los reportes deben
  seguir saliendo bien — los clientes cargan compras cuando baja el
  movimiento, no al instante. Es el mismo campo (`lastPurchaseCost`
  persistido) resolviendo dos síntomas: ruido en el % sobre costo (arriba) y
  huecos de costo cuando la carga de la factura se demora.

**Precios mayoristas / minoristas / por cajas + variantes (color y talle)** — `XXL`
> Modelo de variantes (S/M/L, rojo/azul) + tiered pricing.

- Cambio MAYOR de modelo. Hoy items son planos. Requiere:
  - Variantes: `itemVariant` (size, color, sku propio, stock propio).
  - Tiered pricing: tabla `priceList` ya existe — extenderla con conditional por contacto/grupo.
- Inversión enorme. Decidir scope: ¿soportar tiered pricing primero (sin variantes) y variantes después?
- Cliente Macatera Chuchi (mencionado al final) es el caso emblemático.

**Reporte de transferencias enviadas / recibidas con auditoría** — `M`
> Para auditar movimientos entre sucursales: qué se envió, quién aceptó.

- `transaction` tipo 12 (transferencia) ya existe. Reporte específico con columnas: origen / destino / itemsSold / fromUserId / toUserId / fecha aceptación.

**Solución para clientes con catálogos enormes (Macatera Chuchi)** — `M / XL`
> "Ver qué solución podemos dar a clientes con miles de productos."

- Performance: paginación server-side, virtualización en DataTable, lazy load de imágenes.
- UX: búsqueda más fina (códigos cortos, atajos), categorías colapsables.
- Si va con variantes (request anterior) → cambia el approach (un item "padre" con N variantes en vez de N items planos).

---

## Sugerencia de orden (no decidido — para discutir)

**Quick wins (sprint 1)** — todo `S/M` con valor inmediato y baja deuda:
1. Columnas faltantes en Transacciones (tipo doc, descuento, IVA, subtotal)
2. Columnas faltantes en Productos / Clientes / Compras
3. Pagos recibidos y cotizaciones en `/reports/transactions` (endpoint ya existe)
4. Detalle por usuario en Staff
5. Shortcuts en /items (Producir/Ajustar/Comprar)
6. Procesar Órdenes navigation fix

**Antes del próximo deploy comercial (sprint 2)**:
7. Export RG90 + Libro Ventas + Libro Compras (regulatorio)
8. Permisos granulares por usuario (bloquea features que requieren ACL fina)
9. Recibo de dinero como tipo de transacción propio
10. Combos con item fijo

**Esfuerzo grande, planificar aparte**:
- Suscripciones como paquetes
- Comisiones múltiples (producto × profesional)
- Variantes (color/talle) + tiered pricing
- Solución catálogos grandes (probablemente va con variantes)

**Operacional (cuando el módulo Mesas sea prioridad)**:
- Mesas asignadas a meseros
- Conteo de personas atendidas
- Stock opcional al hacer pedido

---

*Próximo paso sugerido — el owner marca con [x] / etiqueta de prioridad los items que entran al sprint y movemos los compromisos al `10-roadmap.md` como tareas formales.*
