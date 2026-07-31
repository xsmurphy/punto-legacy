# Centro de notificaciones del panel

> Plan cerrado con el owner el 2026-07-30. Decisiones NO relitigables:
> v1 = vencimientos financieros (derivados en vivo del forecast) + eventos
> existentes (tabla `notify`); anticipación 7 días antes + al vencer,
> vencidos visibles hasta resolverse.

## Contexto

Infra existente:
- Tabla `notify` + `NotificationService` (list/count con watermark
  `contactLastNotificationSeen`) + `/v1/notifications` — realm `pos-app`,
  port del legacy. Escritores ya activos: órdenes, espacios, billing, ventas.
  **Nada del frontend lo consume hoy.**
- `/v1/finance/forecast` calcula vencimientos (cheques emitidos/recibidos,
  cuotas de fin_loan, compras a crédito). Derivar avisos EN VIVO de ahí evita
  jobs programados (no hay infra de cron) y duplicados: si la obligación se
  resuelve, el aviso desaparece solo.

## Diseño

**Feed unificado, dos fuentes:**
1. Eventos: filas de `notify` del tenant (scope outlet como hoy).
2. Vencimientos derivados: obligaciones del forecast con
   `duedate <= hoy + 7 días` (incluye vencidas sin límite hacia atrás).
   Sin persistencia propia — se computan por request.

**Identidad y estado por item** (no watermark): cada item tiene `alertKey`
determinística — `notify:<notifyId>` para eventos,
`due:<tipo>:<id>:<duedate>` para derivados (ej. `due:check:<checkid>:2026-08-06`).
Tabla nueva `notification_state` (mig 103): companyid, userid, alertkey,
readat, dismissedat, UNIQUE (companyid, userid, alertkey). Leído ≠ descartado:
leído atenúa, descartado saca del feed. El watermark legacy del POS queda
intacto (el POS sigue con su flujo).

**Backend** — `/v1/notifications/feed.php`, realm `panel`:
- GET → { items: [...], unreadCount } — unión de las dos fuentes, orden:
  vencidas primero, después por fecha. Item: { alertKey, kind
  (check_due|loan_due|purchase_due|event), title, message, dueDate|date,
  link (ruta del panel al origen), read, severity (overdue|upcoming|info) }.
- POST op=read { alertKeys: [] } / op=dismiss { alertKey } / op=readAll.
- Reusa la lógica de forecast (extraer helper compartido si hace falta,
  NO duplicar los queries).

**Frontend:**
- Campanita en el sidebar del panel (`app-sidebar.tsx`) con badge de
  no-leídos. Click → Popover (NO Sheet/Drawer) con los últimos ~10, acción
  marcar todo leído, link "Ver todas".
- Página `/notificaciones`: lista completa, filtro por tipo, descartar por
  item. Cada item linkea a su origen (cheque, crédito, compra).
- Count: poll suave (refetchInterval ~60s en el hook del badge). Realtime WS
  queda para después.

## Extensiones futuras (rail listo, fuera de v1)

Stock mínimo por ítem y facturas a crédito por cobrar (pedidos de testers,
`context/_feature-requests.md`) entran como nuevos `kind` del feed sin tocar
el modelo. Push/email/WS de avisos: `Notification::sendPush` ya existe si se
quiere después.
