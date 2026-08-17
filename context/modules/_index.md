# Módulos del sistema — índice y mapa de interacciones

> Creado 2026-08-16 por pedido del owner. **Propósito**: que nadie tenga que
> re-descubrir un módulo entero, y sobre todo que nadie **asuma** cómo funciona
> otro módulo al integrarse con él.
>
> Motivo concreto, de esta misma sesión: se cerró en falso un reporte de
> cuentas por pagar ("no existe ninguna compra a crédito") apoyándose en un
> conteo en vez del flujo; se "arregló" un bug (T8) que ya estaba resuelto; y
> un badge que decía "Completa" para el estado del DOCUMENTO se leyó dos veces
> como "pagada". Los tres son el mismo error: asumir en vez de verificar.

## Qué es esto y qué NO es

- **Es**: cómo funciona cada módulo hoy, qué reglas lo gobiernan, y **qué
  asume de los demás**. Con evidencia `path:line`.
- **No es**: una bitácora de commits (eso es `05-modulos-clave.md`) ni un plan
  de cambios (esos son los `context/NN-*.md` numerados). Este doc describe el
  estado y las reglas; los planes describen las modificaciones.

## Reglas para escribir y mantener estos docs

1. **Todo con evidencia.** Cada regla lleva `path:line`. Sin evidencia es
   suposición, y la suposición es exactamente lo que este doc elimina.
2. **Lo no verificado se marca como no verificado.** Es información valiosa.
   Afirmar sin haber mirado es peor que dejar el hueco declarado.
3. **La sección de interacciones es obligatoria** aunque el módulo parezca
   aislado. Ningún módulo lo está.
4. **Se actualiza al cambiar el módulo**, no "después". Un doc desactualizado
   es peor que no tenerlo: da confianza falsa.
5. Plantilla: `_template.md`. Respetarla — la uniformidad es lo que hace que
   se pueda leer rápido el módulo que no conocés.

## Índice

Estado: ⬜ sin escribir · 🟡 borrador · ✅ verificado contra código

### Catálogo y precios
- ⬜ `01-catalogo-items.md` — tipos de artículo (`kind`), campos, variantes
- ✅ `02-combos-y-addons.md` — combo fijo, combo dinámico, grupos de add-ons
- ✅ `03-listas-de-precio.md` — prioridad de listas, ajustes, overrides por ítem
- ✅ `04-impuestos.md` — tasas, incluido/añadido, exento vs 0%, congelado por línea

### Inventario
- ⬜ `05-stock.md` — movimientos, `manageStock` como choke point, ajustes, conteo
- 🟡 `06-produccion.md` — recetas, producción directa vs previa, merma, costos
- ⬜ `07-transferencias.md` — entre sucursales/depósitos, y su relación con remisión

### Compras
- ⬜ `08-compras.md` — contado vs crédito, packSize, OCR de facturas, borradores
- ⬜ `09-notas-credito-compra.md` — devolución a proveedor, modos de reembolso

### Ventas
- ⬜ `10-pos-venta.md` — carrito, medios de pago, descuentos, offline
- ⬜ `11-ordenes-y-comandas.md` — orden vs venta, cocina, estados
- ⬜ `12-espacios.md` — mesas, sesiones, cobro parcial, estado compartido
- ⬜ `13-cotizaciones.md`

### Dinero
- ⬜ `14-caja.md` — apertura, cierre, arqueo, movimientos
- ⬜ `15-credito-y-cobranzas.md` — cuentas por cobrar/pagar, distribución FIFO, anulación
- ⬜ `16-giftcards-y-vales.md`

### Documentos
- ⬜ `17-numeracion.md` — correlativos, arriendo offline, scope por caja/sucursal
- ⬜ `18-impresion.md` — plantillas, bloques, bindings, transports
- ⬜ `19-facturacion-electronica.md` — SIFEN/Factomate, qué se congela
- ⬜ `20-remision.md`

### Transversales
- ⬜ `21-contactos.md` — clientes, proveedores, direcciones, crédito habilitado
- ⬜ `22-sincronizacion.md` — realtime, delta, offline-first, lápidas
- ⬜ `23-auth-y-permisos.md` — realms, roles, claves de permiso
- ⬜ `24-sucursales-y-scopes.md`
- ⬜ `25-reportes.md` — incluidos los fiscales (RG90, Libro Ventas)

## Mapa de interacciones (se completa a medida que se escriben los docs)

Las flechas más peligrosas — donde una suposición equivocada rompe plata:

| Origen | Destino | Qué asume |
|---|---|---|
| Venta | Impuestos | Que la tasa se congela por línea al vender, no se recalcula del catálogo |
| Venta | Numeración | Que hay correlativo disponible; sin él NO se emite |
| Venta | Stock | Que `manageStock` es el único camino que mueve inventario |
| Compras | Cuentas por pagar | Que crédito ⇒ `transactionComplete = false` |
| Cobranzas | `transaction_link` | Que el saldo sale de sumar vínculos, no de una columna |
| POS | Sincronización | Que el cache local es fuente de verdad para operar |
| Impresión | Plantillas | Que lo que se imprime lo decide la plantilla, no el renderer |
| Órdenes/mesas | Add-ons | Que el `unitPrice` plano de la línea ya "cobra" el add-on — pero `CreateOrderItemInput`/`OrderItem` no tienen `selections`: una orden de mesa con add-ons pierde el desglose, el stock de la opción y el dato para la comanda (nunca corre `expandAddonSelections`) |
| Add-ons | Stock | Que cada opción elegida (incluidas `isLocked`) descuenta con la misma `explodeRecipe` que cualquier ítem — sin excepción para las que el cajero no tocó |
| Venta/Anulación | Producción | Que ambas resuelven "¿esta receta se explota?" con el MISMO predicado (`Inventory::saleExplodesRecipe`, contra BD) — no contra `$saleDetail[]['type']`, que el POS nunca manda. Divergencia real ya ocurrida (fix `822f8df3`): producción previa consumía insumos dos veces y anular reponía insumos jamás gastados |
| Producción | Reportes | Contrato roto hoy: los tabs de "producción directa" filtran `item.itemType = 'direct_production'` y `stock.stockSource = 'production'`, pero ninguno de los dos valores llega a ocurrir nunca (el primero es una etiqueta sintética que no se persiste; el segundo depende del mismo campo de carrito que el POS no manda) — esos tabs quedan vacíos siempre, sin error visible |
| Listas de precio | Impuestos | Que el precio YA ajustado por lista (descuento o recargo) es la base sobre la que se calcula el IVA — verificado: `line.unitPrice` post-resolución viaja como `price` al motor de impuestos sin ningún paso que revierta el ajuste antes de gravar |
| POS | Listas de precio | Que `/v1/price_resolve` siempre resuelve el precio correcto — sin conexión, el front atrapa el error y cobra precio BASE en silencio: un cliente con lista de descuento paga precio lleno offline, sin aviso (plan `context/44` sin implementar) |
| Impuestos | Facturación electrónica / RG90 | Que `kind=exempt` y `kind=rate,rate=0` son fiscalmente distintos — pero el layout fijo de RG90 (3 columnas) los junta en "exento" por falta de columna, no por error de dato |
