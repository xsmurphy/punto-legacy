# 12 — Espacios

> Estado del doc: verificado contra código 2026-08-17
> Responsable de la última verificación: sesión 2026-08-17 (este doc)

## 1. Qué resuelve

Modela mesas físicas (gastronomía) como estado compartido entre cajas: una
mesa se abre, acumula órdenes de distintas rondas, y se cobra — entera o en
partes (split de cuenta). A diferencia de una venta directa, acá el estado
("qué debe esta mesa ahora mismo") tiene que ser el mismo sin importar desde
qué dispositivo se mire.

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `space` | La mesa física: `sectorId`, `outletId`, `shape`. | Estado derivado, NO columna propia — `SpaceService::listWithState()` lo calcula de la sesión activa: sin sesión → `free`; sesión `open` → `occupied`; sesión `bill_requested` → `bill_requested` (`SpaceService.php:356-400`). |
| `space_session` | Una ocupación de la mesa: `status` (`open`/`bill_requested`/`closed`/`cancelled`). | `bill_requested` NO bloquea nuevas órdenes — pedir la cuenta es una señal para caja, no un cierre; una orden nueva revierte la sesión a `open` (`OrderCoreService.php:325-330`). Cierre EXCLUSIVO vía `SpaceSessionService::close()`, con el invariante "solo cierra con saldo ≤ 0" (`SpaceBalanceService::isCovered`). |
| `space_session_payment` (mig 90) | Ledger de pagos parciales: `transactionid`, `amount`, `kind` (`items`/`amount`/`share`), `sharecount`. | Cada fila es un pago YA vinculado a una `transaction` real (su propio comprobante) — el service NO crea transacciones, solo lleva el ledger (`SpaceSettlementService.php:24-28`). Índice único por `transactionid` (mig 91) es el respaldo estructural de la idempotencia. |
| `pos_order_item.settledpaymentid` | Marca qué ítems ya se cobraron — SOLO se usa con `kind='items'`. | Es un CAS: el `UPDATE ... WHERE settledpaymentid IS NULL RETURNING` es la única garantía real contra el doble cobro por ítems — no un lock ni una validación previa (`SpaceSettlementService.php:160-187`). Las líneas hijas de add-on (`parentorderitemid` no null) quedan EXCLUIDAS del cálculo de saldo — no son unidades cobrables por separado (`SpaceBalanceService.php:75-81`). |

## 3. Reglas de negocio

1. **Estado compartido ⇒ online-only.** Dos cajas pueden tocar la misma mesa
   al mismo tiempo; sin sincronización con el server no hay forma de saber
   si la otra ya cobró algo — es la otra mitad de la distinción de
   `context/08-convenciones-criticas.md §53` (emisión vs. estado
   compartido). Ver §6.
2. **Cobro parcial: cuatro modalidades, dos "familias" mutuamente
   excluyentes por sesión.** `total` (mesa completa, sin partials previos)
   usa el camino atómico de siempre: `loadFromSession` → una venta →
   `markPaid` de cada orden → `close` (`space-settlement-provider.tsx:123-134`,
   `pay-dialog.tsx:683-702`). `items`/`amount`/`share` van por el LEDGER
   (`loadForSettlement` + `settlementIntent`). **No se pueden mezclar
   `items` con `amount`/`share` en la misma sesión** (decisión del owner
   2026-07-19) — motivo es STOCK, no plata: `items` marca y descuenta una
   sola vez vía CAS; `amount`/`share` prorratean sobre lo NO saldado sin
   marcar. Mezclar dejaría un ítem prorrateado y luego vuelto a cobrar por
   `items` con su stock descontado dos veces — "la plata queda bien... el
   inventario deriva en silencio, que es peor porque no se nota"
   (`SpaceSettlementService.php:329-356`).
3. **Cada pago parcial es su propia transacción con su propio comprobante.**
   El front crea la venta PRIMERO (con el `PayDialog` normal) y recién
   DESPUÉS registra el pago en el ledger con ese `transactionId` ya creado
   (`pay-dialog.tsx:638-682`) — `SpaceSettlementService` nunca factura, solo
   lleva el libro (`SpaceSettlementService.php:24-28`).
4. **La reconciliación post-cobro vive en un provider del layout, NO en la
   página `/pos/espacios` — bug T8.** Antes el diálogo de split +
   `handleSplitCharge` + la reconciliación vivían en la página, que asumía
   seguir montada detrás del carrito — cierto en desktop, falso en
   mobile/tablet donde `/pos/espacios` se pinta como Dialog fullscreen
   ENCIMA del `CartPanel` y tapaba el botón "Cliente"
   (`space-settlement-provider.tsx:1-19`). Ahora `SpaceSettlementProvider` se
   monta UNA vez en `app/(pos)/pos/layout.tsx`, junto al `CartPanel`, fuera
   del slot de rutas — sobrevive a cualquier navegación.
5. **El intent vive en un store aparte porque `clearCart()` lo resetea ANTES
   de que el `PayDialog` dispare `onOpenChange(false)`.** Si `settlingSpace`
   viviera en el cart store, ya estaría en `null` cuando el efecto de
   reconciliación necesita leerlo tras el cierre del diálogo
   (`settlement-store.ts:11-15`). Por eso `useSpaceSettlementStore` es un
   store propio, ni el cart store ni `usePosUIStore` (ese es solo
   open/close de diálogos, esto es estado de dominio).
6. **Guarda de doble cobro (`chargeInFlight`).** `preparingCharge`
   deshabilita el botón, pero entre dos taps consecutivos puede no haber
   re-render — un `ref` corta la segunda invocación mientras la primera
   sigue en vuelo. Dos cobros en simultáneo serían dos comprobantes por la
   misma parte (`space-settlement-provider.tsx:96-101`).
7. **El saldo se RELEE siempre antes de cobrar, nunca se usa el que mostró
   el diálogo.** Tanto al preparar el carrito (`handleSplitCharge` pide
   `fetchSessionBalance` fresco, comentario explícito: *"el saldo cacheado
   es para mirar; para cobrar, este"* — `space-settlement-provider.tsx:105-115`)
   como server-side (`preflightPayment`, ANTES de crear la venta, y
   `registerPayment` con `FOR UPDATE` sobre la fila de sesión, que serializa
   pagos concurrentes — `SpaceSettlementService.php:118-125`).
8. **El preflight reduce la ventana de carrera, no la elimina.** Corre las
   MISMAS validaciones que `registerPayment` (una sola definición,
   `validateAndComputeAmount`) pero sin `FOR UPDATE` ni el CAS de
   `settledpaymentid` — el caller SIEMPRE debe manejar un rechazo de
   `registerPayment` aunque el preflight haya aprobado
   (`SpaceSettlementService.php:254-299`). Documentado explícitamente en el
   código, no un supuesto implícito.
9. **Idempotencia del ledger por `transactionId`** (hallazgo del
   code-reviewer, cerrado): antes solo `kind='items'` estaba cubierto por el
   CAS; `amount`/`share` no tenían protección contra un reintento
   duplicando el pago. Ahora es no-op idempotente si el `transactionId` ya
   está en el ledger (`SpaceSettlementService.php:128-147`).

## 4. Flujos principales

**Abrir mesa → tomar orden(es):** ver `11-ordenes-y-comandas.md` regla 7 —
una orden con `spaceSessionId` fuerza `source='table'`/`dine_in`. Cada nueva
orden revierte `bill_requested` a `open` si correspondía.

**Cobrar la mesa completa (sin partials previos):**
1. `handleSplitCharge` con `selection.mode==="total"` y `balance.paid<=0` →
   `loadFromSession` carga TODAS las órdenes billable al carrito, navega a
   `/pos`.
2. `pay-dialog.tsx` cobra normal; al confirmar, `markPaid` de cada orden +
   `close` de la sesión — camino atómico de siempre, no toca el ledger.

**Cobro parcial (split — items/amount/share):**
1. `SplitBillDialog` arma la selección; `handleSplitCharge` relee saldo
   fresco, valida contra `balance` (ítems ya saldados, monto que no entra),
   arma las líneas (`buildItemsLines`/`buildProportionalLines`) y hace
   `loadForSettlement` con el `SettlementIntent` correspondiente.
2. `pay-dialog.tsx` corre el preflight (`validateSessionPayment`) ANTES de
   crear la venta — un rechazo aborta ahí, sin cobrar nada (fix T1,
   2026-08-03, ver `10-pos-venta.md` regla 3 patrón afín).
3. Venta confirmada → `registerSessionPayment` con el `transactionId` real.
   Si con este pago el saldo llega a 0, `settleIfCovered` (MISMA transacción
   SQL que el INSERT del ledger) hace `markPaid` de las órdenes activas +
   `close` de la sesión — atómico, nunca un parcial que cierra antes de
   tiempo.
4. Reconciliación: al cerrarse el `PayDialog`, `SpaceSettlementProvider`
   relee el saldo. Saldo 0 → toast "cuenta saldada". Saldo > 0 → reabre el
   `SplitBillDialog` con el saldo nuevo, para cobrar la parte siguiente —
   sea cual sea la ruta en la que esté el cajero en ese momento.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Órdenes | `settleIfCovered` hace `markPaid` de cada orden activa de la sesión y `close()` de la sesión, atómico con el pago que lo dispara. El saldo excluye líneas hijas de add-on (`parentorderitemid`). | Ver `11-ordenes-y-comandas.md` — mismo criterio de exclusión de add-on-children que usa el cálculo de saldo (`SpaceBalanceService.php:75-81`), consistente entre los dos módulos. |
| POS (carrito/venta) | `loadFromSession`/`loadForSettlement` arman el carrito; `pay-dialog.tsx` decide, tras confirmar, si cierra la sesión entera o registra un parcial según qué campo esté seteado (`sessionParentId` vs `settlementIntent`, mutuamente excluyentes). | Ver `10-pos-venta.md` — el contrato de qué se resetea en `clear()` es crítico: un `settlementIntent` que sobreviva imputaría la siguiente venta normal a una mesa vieja. |
| Sincronización | `SpaceService::publish`/`publishBalance`/`publishSessionState` notifican a todas las cajas mirando el mapa de mesas. | Asume conectividad — sin ella, dos cajas pueden ver estados divergentes del mapa (ver §6). |
| Impresión | Cada pago parcial, al ser su propia `transaction`, imprime su propio comprobante — mismo pipeline que cualquier venta (`10-pos-venta.md`). | No hay un "ticket de la mesa completa" separado del ticket de cada venta/pago. |
| Add-ons/Stock | Las líneas hijas de add-on no son unidades cobrables por separado — no aparecen en la UI de selección "por ítems" del split. | Depende de que `parentorderitemid IS NULL` siga siendo el filtro correcto — si algún día una hija necesitara cobrarse aparte, este query tendría que revisarse junto con el de `11-ordenes-y-comandas.md`. |

## 6. Offline (POS)

**Ninguna operación de Espacios es offline.** Abrir/cerrar sesión, tomar
orden asociada a mesa, y cualquier cobro (total o parcial) requieren
conectividad — es estado compartido entre cajas, la mitad de §53 que
explícitamente NO tiene que funcionar sin red. `pay-dialog.tsx` lo hace
explícito: el timeout del POST de venta se extiende a 20s (vs. 5s del
camino simple) cuando `sessionParentId`/`orderParentId`/`settlementIntent`
están presentes, y un fallo de red en ese camino NUNCA se encola — se
propaga como error para que el cajero reintente con conexión
(`pay-dialog.tsx:494-513`, mismo criterio citado en
`11-ordenes-y-comandas.md §6`).

## 7. Huecos conocidos y NO verificado

- **Ventana de carrera entre preflight y registro real**: documentada
  explícitamente en el código (regla 8), no un hueco oculto — pero vale
  confirmar que TODOS los callers (no solo `pay-dialog.tsx`) manejan el
  rechazo de `registerPayment` después de un preflight aprobado.
  **NO VERIFICADO** si existe algún otro caller de `registerPayment` fuera
  del POS (ej. un endpoint admin) que no repita ese manejo.
- **Family lock (`items` vs `amount`/`share`) es solo a nivel sesión** — no
  se auditó qué pasa si una sesión se cancela y se reabre otra sobre la
  misma mesa; presumiblemente cada `space_session` es una fila nueva sin
  arrastrar el lock, pero no se confirmó leyendo `SpaceSessionService`
  completo.
- **NO VERIFICADO**: comportamiento del mapa de mesas (`listWithState`)
  ante dos sesiones simultáneas sobre el mismo `spaceId` — se asume que el
  modelo previene esto (una mesa, una sesión activa a la vez) pero no se
  leyó el `CREATE`/lock de `SpaceService::create` a fondo en esta sesión.
- **Reimpresión del comprobante de un pago parcial específico**: no se
  auditó si el flujo de reimpresión del panel/POS distingue "esta
  transacción fue un pago parcial de tal mesa" para mostrarlo en el
  detalle — separado del gap ya documentado en `39-detalle-transaccion.md`.

## 8. Planes y decisiones relacionados

- `context/15-espacios-module-plan.md` — plan técnico cerrado 2026-07-19
  (§F3 split de cuenta), fuente de las decisiones del owner citadas acá.
- `context/08-convenciones-criticas.md §53` — regla base offline-first; acá
  es donde se aplica la mitad "estado compartido = puede bloquear".
- `context/modules/11-ordenes-y-comandas.md` — ciclo de vida de la orden,
  `markPaid`/`close`, y el filtro de add-on-children compartido con el
  cálculo de saldo.
- `context/modules/10-pos-venta.md` — el carrito y el `PayDialog` que
  Espacios reusa sin cambios para cada cobro (total o parcial).
