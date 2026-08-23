# Hand-off — 2026-08-23

## Objetivo
Sesión larga multi-eje: cerrar escalamiento de datos (particionado + cierre de
período + rollup diario, `context/48`), hacer que el wrapper DB y los arneses
dejen de mentir sobre errores, cerrar el gap de 25 permisos sin enforcement,
llevar el POS a operar completo sin conexión, y auditar el backlog completo
contra el código real.

## Estado al cerrar
Todo commiteado, pusheado y mergeado a `main` (`4b929e60..6896d69b`, 153
commits, 31 merges, migs 156-164). Working tree limpio, sin branches
colgando. Verificado en prod: particionado (E1), cierre de período (E1b),
rollup diario (D8), el fix de bootstrap 500, y el semáforo de arqueo.

- **Escalamiento (`context/48`)**: E1/E1b/D8 implementadas y verificadas.
  E2/E3 (réplica) siguen sin empezar — E3 bloqueada hasta confirmar si
  Coolify soporta réplica read-only.
- **Seguridad**: 45/47 permisos del catálogo con enforcement (2 restantes
  quedan abiertos por diseño offline-first). Rol seed `device` (mig 162)
  reemplaza el `roleId='1'` (owner) con el que operaba el POS antes.
- **POS offline**: arranca sin red, opera, abre/cierra turno, verifica
  tenencia antes de emitir. No cubre gestión de mesas/órdenes compartidas
  entre cajas (requiere estado compartido, decisión de diseño ya tomada).
- **2 bugs de plata siguen abiertos**, ambos esfuerzo M, no tocados esta
  sesión (ver Próximo paso).
- Backlog completo auditado por 5 agentes: ~50 de ~95 items ya estaban
  resueltos en código aunque el doc no lo reflejara. Docs sincronizados.

## Archivos y cambios
- `api/database/migrations/postgres/156_*.sql` — particionado mensual
  `transaction`/`itemsold` + tabla `transaction_registry` (ancla de 20 FKs
  entrantes + unicidad fiscal, NO particionada).
- `api/database/migrations/postgres/157_*.sql` — cierre de período
  (`fn_period_guard`, solo tipos económicos).
- `api/database/migrations/postgres/160_*.sql` — rollup diario
  (`rollup_sales_day`, `rollup_item_sales_day`, `rollup_payments_day`).
- `api/database/migrations/postgres/162_*.sql` — rol seed `device`.
- `api/database/migrations/postgres/163_*.sql` — exclusividad de mesas
  vía aserción HMAC del operador.
- `api/database/migrations/postgres/164_*.sql` — semáforo de cuadre en
  arqueos (fix del faltante fantasma: esperado se recalculaba contra todos
  los medios de pago en vez de solo efectivo).
- `api/includes/lib/DB.php` — `Execute()` lanza `DbQueryException`, kill
  switch `DB_THROW_ON_ERROR`.
- `api/v1/bootstrap.php` — fix `moduleData` (vivía en JSONB `config`, no
  columna propia; causaba 500 → "Sin conexión con el servidor" en el POS).
- `api/lib/services/*` — `getAllItemStock($all=true)` corregido (iteraba
  campos de una sucursal como si fueran outletIds, nunca agregaba).
- `api/v1/users.php`, `api/v1/roles.php` — anti-escalación de privilegios.
- `store.ts` (frontend, POS) — cola de operaciones offline, apertura/cierre
  de turno sin red.
- `SaleToInvoiceMapper.php:174` — guard de cuadratura pendiente de fix (ver
  Próximo paso, NO tocado esta sesión).
- `SpaceBalanceService.php:81` — filtra hijas de add-ons, pendiente (ver
  Próximo paso, NO tocado esta sesión).
- `context/48-escalamiento-de-datos.md`, `context/10-roadmap.md`,
  `context/08-convenciones-criticas.md` (§56-59 nuevas), `context/49-*`
  (KuDE y portal), `context/50-*` (uPay) — actualizados por los propios
  agentes durante la sesión.

## Callejones sin salida
- Particionar rompió las unicidades globales de Postgres (no se puede tener
  un UNIQUE cross-partición sin incluir la columna de partición) — de ahí
  `transaction_registry` como tabla no particionada aparte, en vez de forzar
  la unicidad fiscal dentro de las particiones.
- Un contenedor con prefijo `api-asqhqb…` en el server NO es Punto — costó
  dos diagnósticos falsos antes de identificarlo. Ya documentado en
  `context/06-infraestructura.md`, pero volvé a chequear el nombre exacto
  del contenedor antes de operar sobre él.
- El layout de archivos dentro del container NO espeja el repo (código en
  `/var/www/api`, migraciones en `/var/www/database`) — una migración PHP
  con `dirname(__DIR__, N)` asumiendo el layout del repo tiró el deploy
  entero. Se resolvió buscando el archivo hacia arriba en vez de asumir N.
- Colisión de número de migración entre sesiones paralelas pasó DOS veces
  en este bloque — mirar `ls api/database/migrations/postgres | sort -n |
  tail` justo antes de numerar una nueva, no confiar en el HEAD de otro
  agente.
- `set_exception_handler` consumía la excepción antes de que PHP aplicara
  su exit code 255 — el runner de arneses veía exit 0 y cantaba "TODO OK"
  aunque la ejecución hubiera abortado sin correr una sola aserción (pasó
  con `JWT_SECRET` no exportado). Se resolvió exigiendo la línea literal
  `HARNESS RESULT` en cada runner, no el exit code solo.
- Un agente instaló PHP 8.4 en el host de producción para poder correr
  arneses ahí — quedó instalado (CLI inerte, no se desinstaló). No repetir:
  los arneses corren dentro del contenedor, no en el host.

## Próximo paso
De los dos bugs de plata que quedan abiertos, arrancar por
`api/lib/App/.../SaleToInvoiceMapper.php:174` — el guard de cuadratura
compara el monto bruto contra el neto cuando la venta canjeó un vale, así
que una venta con vale nunca puede facturarse. El otro es
`frontend/.../store.ts:1178` (descarta las `selections` de add-ons al
cobrar una orden/mesa) + `SpaceBalanceService.php:81` (filtra las hijas) —
el stock de add-ons no se descuenta. Ambos esfuerzo M, sin dependencias
externas, se pueden arrancar en cualquier orden.

## Trampas conocidas
- Las 257 ventas históricas sin número de documento NO se van a backfillear
  — decisión del owner, no es un bug pendiente.
- El formato de papel de impresión se configura en Ajustes, nunca en Caja
  (convención cerrada, §57 de `context/08`). El cierre de turno offline
  SÍ debe mostrar el total registrado por el dispositivo (con sus huecos
  declarados) — no es un bug si parece "incompleto", es la especificación.
- uPay y KuDE están bloqueados por terceros (alta en Ueno Bank; preguntas a
  Factomate + consulta al contador sobre si el QR satisface el art. 25 del
  Decreto 872/2023) — no asumir que se puede avanzar sin esas respuestas.
- Docs `context/08` tiene duplicados en §40-43, preexistentes a esta sesión,
  no introducidos ahora — no es urgente pero está anotado.
- Postgres UUIDs son v4 random: `ORDER BY id`/`max(id)` nunca da "más
  reciente" (recordatorio recurrente, ya en memoria pero mordió antes).
- Backlog completo auditado, versión visual: https://claude.ai/code/artifact/6f23d6f5-f511-4db3-a378-c2abe1a35ebb
