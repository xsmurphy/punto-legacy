<!-- REGLA: Roadmap de mejoras POS post-fusión panel-next (sprint 2026-06-21+).
     6 fases ordenadas por dependencia. Cada fase = una delegación a Sonnet con
     brief cerrado. Al completar un item, mover a _archive-roadmap-completado.md
     o marcarlo done acá hasta cerrar la fase. -->

# 19 — POS Roadmap (post-fusión panel-next)

Lista de pendientes POS organizados por dependencia + riesgo. Origen: pedido del
owner 2026-06-21. La ejecución se hace siempre con Sonnet (regla CLAUDE.md);
este doc es el brief base para cada delegación.

> **Estado:** plan acordado, sin ejecución. Próximo paso: arrancar Fase 1.

---

## Fase 1 — Precarga local + auth (foundation)

Habilita Fases 3, 5, 6 (necesitan users/customers/items locales). Resuelve B1
gratis: el PIN del master falla hoy probablemente por scope server-side; con el
array local correcto ambos PINs matchean sin roundtrip.

| # | Item | Tipo |
|---|---|---|
| A1 | Al entrar a /pos, fetch + persist en localStorage (Zustand persist): bootstrap, config, **users** (todos los del outlet), **customers**, **items vendibles** | Arch |
| A2 | Lockscreen: match del PIN contra `users[].pin` local en vez de server roundtrip | Arch |
| B1 | PIN del master deja de fallar (consecuencia natural de A1+A2) | Bug |
| U6 | Toast "Bienvenido {nombre}" — lee el `user.name` del match local | UI |
| U3 | "Asignar usuario" en opciones de ítem → modal con `users` del local | UI |

**Brief para Sonnet:** "Precachear users/customers/items/bootstrap/config en
Zustand persist al montar /pos; reemplazar validación de PIN del lockscreen por
match local; toast con nombre; modal de usuarios reusable para la sección de
opciones de ítem."

---

## Fase 2 — Bugs sueltos (paralelo a Fase 1)

| # | Item | Tipo |
|---|---|---|
| B2 | Items agrupados / packs / combos no aparecen en catálogo del POS — auditar query y filtros | Bug |
| B4 | Timestamps de transacciones con minutos+segundos en 0 — fix al insert | Bug (audit) |
| B3 | Buscador (items/clientes) preserva el input al cerrar el panel | Bug UX |

**Brief para Sonnet:** "3 bugs independientes en /pos: catálogo no lista
agrupados (revisar query + flags); transacciones se persisten con HH:00:00
(buscar el truncate al insert); search input no debe limpiarse al cerrar el
panel — preservar estado en el store del buscador."

---

## Fase 3 — Componente NumericPad reusable

Antes de Fase 4: ambos modales (cantidad y descuento) comparten widget.

| # | Item | Notas |
|---|---|---|
| U4 | Modal cantidad: número del botón más chico; **SHIFT togglea entero ↔ decimal** | Para gramos/peso |
| U5 | Modal descuento: misma base; **SHIFT togglea moneda ↔ %** | Aplica a ítem y a total venta |

Un solo componente `<NumericPad mode="qty"|"discount" />` con prop
`onShiftToggle`. Detecta tecla SHIFT y cambia el modo del display + parser.

**Brief para Sonnet:** "Crear `components/pos/numeric-pad.tsx` reusable con
prop `mode` y `onShiftToggle`; reemplazar los modales actuales de cantidad y
descuento; persistir el modo elegido por sesión."

---

## Fase 4 — Lógica de descuentos (depende de Fase 3 para UI)

| # | Item | Tipo |
|---|---|---|
| F3 | Descuento global se aplica **al precio unitario de cada línea**, no al total. Total venta = suma de líneas siempre | Lógica |
| F2 | Descuento global es **snapshot al momento de aplicarlo** — items agregados después no lo reciben; items con descuento individual lo ignoran | Lógica |

Re-modela el carrito: cada línea guarda su descuento efectivo, el total se
reconstruye desde líneas, no hay "descuento global" como campo separado.

**Brief para Sonnet:** "Refactor de `lib/cart/store.ts`: descuento global se
distribuye al precio unitario de cada línea presente al momento de aplicarlo;
líneas nuevas no lo reciben; líneas con descuento individual quedan exentas.
Total siempre derivado de líneas."

---

## Fase 5 — Flujo de caja + guardar/retomar + Opciones de Venta

| # | Item | Tipo |
|---|---|---|
| F4 | Si caja cerrada y cajero confirma venta → prompt de apertura ANTES del modal de pago | Flujo |
| F1 | Tab visible en /pos con "Ventas en curso" (sin ir a Transacciones) | Feature |
| U7 | `beforeunload` confirm nativo cuando hay líneas en el carrito | UX |
| O | **Refactor "Opciones de Venta"** — ver tabla abajo | Refactor |

F1 + "Guardar" del menú O comparten backend (estado de venta persistido por
outlet/cajero), por eso van juntos.

### Refactor "Opciones de Venta" (O)

| Opción | Acción | Estado |
|---|---|---|
| Imprimir | Imprime venta en curso (usa módulo impresión — slice futuro) | Conservar |
| Descuento Global | Modal NumericPad (Fase 3) + lógica Fase 4 | Conservar |
| Nota | Modal con textarea | Conservar |
| Usuario | Asigna 1 user a TODOS los items de la venta (comisiones por servicios) | Conservar |
| Etiquetas | Añade tags a la venta | Conservar |
| Guardar | Persiste venta en curso para retomar (compartido con F1) | Conservar |
| Moneda | — | **Quitar** |
| Devolución (nota de crédito) | Va dentro de una transacción existente | **Quitar** |
| Cotización | Guarda como cotización, convertible a factura después | Conservar |
| Remisión | Genera nota de remisión | Conservar |
| Cita | Modo agendamiento → calendario | Conservar |
| Orden | Modo orden → módulo órdenes, convertible a factura | Conservar |
| Lista de precios | Selector de price list activa para la venta | Conservar |

**Brief para Sonnet:** "Modal de apertura de caja inline en el flujo de venta;
tab persistente de ventas en curso (lista + restore); beforeunload guard;
refactor del menú lateral de opciones según tabla — quitar Moneda y Devolución."

---

## Fase 6 — UI final + Giftcard

| # | Item | Tipo |
|---|---|---|
| U1 | Color del category bar a `#22252A` | UI 1-liner |
| U2 | Empty state en búsqueda sin resultados (items y clientes) | UI |
| U8 | Modal de pago: debajo de los botones, monto total convertido a cada moneda configurada (usa cotizaciones del panel) | UI + lookup config |
| U9 | Reordenar medios de pago — línea secundaria con Giftcard + Crédito Interno | UI |
| F5 | **Giftcard como medio de pago**: validar código (no vencida, no usada), consumo total en una transacción | Backend + Front |

F5 puede partirse en una sub-fase 6b si conviene (necesita endpoint validate +
consume + columna `usedAt` en tabla giftcard).

**Brief para Sonnet:** "UI cosmética (color bar, empty states, conversión
multi-moneda en pago, layout de métodos); luego implementar Giftcard como
método de pago con validación de código + consumo total + auditoría."

---

## Resumen de delegaciones

| Fase | Brief | Archivos esperados |
|---|---|---|
| 1 | Precarga + lockscreen local | `lib/pos-bootstrap-store.ts`, lockscreen, modal users |
| 2 | 3 bugs paralelos | catálogo query, sale insert, search store |
| 3 | NumericPad reusable | `components/pos/numeric-pad.tsx` + 2 wrappers |
| 4 | Refactor descuentos | `lib/cart/store.ts` |
| 5 | Caja + guardar + Opciones | ~6 archivos del flujo /pos |
| 6 | UI final + Giftcard | varios + nuevo módulo giftcard |

**Total estimado:** 6-7 sesiones de Sonnet, secuenciales (Fase 4 depende de 3;
Fase 6 depende de 5 si compartiera modales).
