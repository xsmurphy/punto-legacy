# 29 — Numeración fiscal y exclusividad de caja (MODELO CANÓNICO)

> ⚠ **Este documento es la base del tema. Si una propuesta lo contradice, la
> propuesta está mal — no el documento.** La versión anterior (arriendo de
> bloques de números) fue REVISADA Y RECHAZADA por el owner el 2026-08-17.
> Lo rechazado está en §6, con el motivo, para que no se reintroduzca.
>
> Decidido con el owner: 2026-07-28 (exclusividad de caja) y 2026-08-17
> (modelo del punto de expedición, que elimina el arriendo).

---

## 1. El modelo del punto de expedición (Paraguay)

Un número de comprobante paraguayo tiene tres partes:

```
001 - 001 - 1234567
 │     │      └── correlativo, 7 dígitos
 │     └───────── número de CAJA dentro de esa sucursal
 └─────────────── número de SUCURSAL
```

- **Punto de expedición** = `sucursal + caja`, o sea el prefijo `001-001`.
- El documento completo es `punto de expedición + correlativo`.
- Esa combinación está asociada a un **número de timbrado**.

> **Los 7 dígitos son FORMATO, no el número** (mig 159, D7 de `context/37`).
> El correlativo se guarda entero (`document_sequence.nextnumber`,
> `transaction.invoiceNo`, ambos BIGINT) y los ceros a la izquierda se ponen
> al pintarlo. El ancho es configurable por talonario
> (`document_sequence.padwidth`, default 7, editable en Sucursal › Cajas) y
> lo aplica UN solo formateador por lado: `DocumentNumber::format()` en PHP,
> `lib/documents/format-document-number.ts` en el front. Prohibido un
> `str_pad`/`padStart` suelto en un call-site — es exactamente cómo se
> separaron los cuatro formatos que la mig 159 unificó.

En Punto: **una caja va atada a un punto de expedición propio.** Si `001-001`
ya está asignado a una caja, JAMÁS otra caja puede usar `001-001` — la
siguiente caja de esa sucursal es `001-002`.

## 2. El invariante

**Por timbrado, la combinación `punto de expedición + correlativo` es única.**

- `001-001-1234567` no puede existir dos veces. Dos facturas con ese número a
  dos clientes distintos es ilegal — multa por CADA factura, no una multa
  única.
- `001-001-1234567` y `001-002-1234567` **sí conviven**: mismo correlativo,
  distinto punto de expedición. Son dos ramas de numeración independientes y
  no se chocan.

Enforcement en código: constraint de unicidad del punto de expedición por
timbrado (mig 143). Antes de eso el invariante existía solo por disciplina
operativa.

## 3. Consecuencia: la numeración offline no necesita coordinación

Como cada caja tiene su propia rama de numeración y ninguna otra caja puede
usar su punto de expedición, **una caja no tiene con quién chocar**.

Entonces, offline, el POS solo necesita saber cuál fue el último correlativo
que emitió su caja y sumar uno:

> si la caja `001-001` va por la `1234567`, la próxima es la `1234568`, sin
> riesgo de que otra caja emita ese mismo número.

No hace falta reservar números por adelantado, ni pedirle permiso al servidor,
ni coordinar entre cajas. El servidor sigue siendo la fuente de verdad
(`document_sequence`) cuando hay conexión, pero offline el device se basta solo.

**Corolario:** este modelo también hace innecesario el vencimiento de
numeración por fecha. Sin bloques reservados no hay nada que pueda expirar ni
arrastrarse a un día siguiente.

## 4. Exclusividad de caja por dispositivo (VIGENTE)

El modelo del §3 se sostiene sobre una premisa: **una caja se usa desde un
solo dispositivo a la vez.** Si dos tablets comparten la caja `001-001` y las
dos calculan "el último fue 1234567, sigo con 1234568", el duplicado vuelve
por esa puerta.

Por eso:

1. **Una caja tiene UN tenedor (`deviceId`) a la vez.** Garantizado en la base
   por `UNIQUE (registerId) WHERE status='active'` sobre `register_lease`, no
   solo por lógica de aplicación.
2. **El segundo dispositivo NO puede facturar.** Si abro la misma caja en otro
   dispositivo, el botón de cobro/facturar queda **bloqueado**. La respuesta
   del servidor es un 409 que dice qué dispositivo la tiene, para poder
   mostrar un mensaje útil ("Esta caja la está usando la tablet de la barra")
   en vez de un error genérico.
3. **Para que el nuevo dispositivo tome la caja hay que revocarla primero** —
   acción explícita de admin desde el panel ("Liberar caja"), con permiso
   dedicado y confirmación. Nunca automática.
4. **La tenencia no vence sola.** Se libera al cerrar la caja, o por
   revocación desde el panel. (Antes se vencía por fecha para que no
   sobreviviera un bloque de números; sin bloques, ese motivo desapareció.)

> Nota histórica: en producción llegó a haber 4 dispositivos sobre la misma
> caja (verificado 2026-07-28). Fue consecuencia de que el sistema todavía no
> impedía el pareo múltiple; eran dispositivos de prueba, sin impacto real. Se
> corrige con lo de arriba, sin arrastrar nada retroactivo.

## 5. Qué documento lleva número

Regla del owner, textual (2026-08-17): *"un documento (factura, orden, recibo,
remisión, NC, etc) jamás puede salir sin un número correlativo"*.

Distinción que hay que respetar: **numeramos los documentos que emitimos
nosotros.** Un documento de un TERCERO (la nota de crédito que emite el
proveedor, su recibo) no lleva correlativo nuestro — lo que hay que guardar es
el número y timbrado que vienen impresos en su papel, que es lo que el
contador necesita para el Libro de Compras.

## 6. ARQUITECTURAS RECHAZADAS — no reintroducir

| Arquitectura | Estado | Por qué se rechazó |
|---|---|---|
| **Arriendo de bloques de numeración** (`numbering_lease`, bloques de 100, `/v1/numbering/lease.php`, TTL 24h) | **RECHAZADA 2026-08-17** | Resuelve un problema que la unicidad del punto de expedición ya resuelve sola. Cada caja tiene su rama propia: no hay con quién coordinar. El arriendo agregaba TTL, vencimiento, huecos por bloques no consumidos y errores de sincronización (`LEASE_EXPIRED`, `LEASE_REVOKED`) sin comprar nada. |
| **"El último dispositivo pisa al anterior"** en la tenencia de caja | **RECHAZADA 2026-07-28** | Dos fallas independientes: (a) si el tenedor anterior está offline no se entera de que perdió la caja y sigue emitiendo — duplicado; (b) aunque no duplique, rompe el orden fecha↔número (el desplazado emite un correlativo menor con fecha posterior). |
| **Vencimiento de numeración por fecha / medianoche** | **OBSOLETA 2026-08-17** | Existía solo para que un bloque reservado no sobreviviera a un cambio de fecha. Sin bloques, no aplica. |
| **Asignar el número en el servidor al recibir la venta online** (`DocumentNumber::allocate()` en el camino online) | **RECHAZADA 2026-08-17** | Convive mal con cualquier número que el device ya haya decidido offline: el servidor entrega un correlativo mayor y el device después emite uno menor con fecha posterior. El número lo decide el device, siempre. |

## 7. Estado de implementación

| Pieza | Estado |
|---|---|
| Constraint de unicidad del punto de expedición por timbrado (mig 143) | ✅ |
| `register_lease` — tenencia de caja, único por caja en BD | ✅ |
| 409 con dispositivo tenedor + pantalla bloqueante en el POS | ✅ |
| Venta online persiste su número y lo lleva al ticket impreso | ✅ |
| Recibo de cliente con correlativo propio | ✅ |
| Devolución de venta con correlativo propio | ✅ |
| **Sacar el arriendo de bloques** (§6, fila 1) | ✅ (commits `be5563f2`/`d0571fce`; `numbering_lease` sin escritores nuevos) |
| **POS: "último correlativo de mi caja + 1" offline** | ✅ (`frontend/lib/pos/invoice-numbering.ts`) |
| **Panel: "Liberar caja"** (revocar tenencia) | ✅ (`registers-tab.tsx:135,576`, migs 148/149, permiso `settings.register.release`) |
| Capturar número + timbrado del proveedor en compras (§5) | ✅ (`supplierAuthNo`, mig 144, `PurchasesService.php:46-133`) |
| Recibo de proveedor, NC de compra | ✅ (`PurchaseCreditNoteService.php:289,539`) |
