# Hand-off — 2026-08-31 (2)

## Objetivo
El owner pidió analizar si los reportes tenían un balance y un flujo de
efectivo usables — no había ninguno. Acotó el alcance con la frase que es la
decisión de fondo: *"gerencial.. nosotros no nos metemos en lo contable"* —
no partida doble, es lo que un dueño mira para decidir. De paso, la marca
del conector MCP (título/ícono en el handshake) destapó un dominio
hardcodeado y otros dos fixes chicos de infra.

## Estado al cerrar
`origin/main` en `7312dc8c` al momento de escribir (puede haber avanzado por
sesiones paralelas). Working tree limpio. Los 6 commits propios de esta
sesión (`8efbd353..7312dc8c`, intercalados con una sesión paralela) YA están
pusheados. **Sin verificar si están deployados** — no se disparó deploy
desde esta sesión; antes de cerrar cualquier sesión futura, confirmar con
`mcp__coolify__list_deployments` que el Front cubre estos commits.

## Archivos y cambios
- `api/lib/Reports/CashflowService.php` — reescrito sobre `fin_movement`/
  `fin_account`, recalcula saldos (no lee `currentbalance` cacheado);
  `source='transfer'` excluido de ingresos/egresos pero SÍ mueve saldo por
  cuenta.
- `api/lib/Reports/BalanceService.php` — nuevo. Snapshot a HOY (no rango).
  Pasivo excluye `type='purchase'` de obligaciones (ya cuenta como cuenta
  por pagar; contarla dos veces era el bug). `notes.missingFixedAssets:
  true` — el sistema no registra activos fijos, se declara en vez de mentir.
- `api/v1/reports/cashflow.php` / `balance.php` — `apiAuthTenant(['panel',
  'api'])`, resuelven `VIEW_OUTLET_ID`.
- `frontend/app/(panel)/reports/cashflow/page.tsx` reescrita, `.../balance/
  page.tsx` nueva (sin `DateRangePicker` a propósito, es una foto de hoy).
- `context/60-balance-y-flujo-de-efectivo.md` — doc nuevo del módulo.
- `frontend/app/api/mcp/route.ts` — `serverInfo` con `title`/`description`/
  `websiteUrl`/`icons` (SEP-973).
- `frontend/next.config.ts` — rewrite de `/favicon.ico` (Next servía `/icon.
  png` pero no `/favicon.ico`; devolvía el HTML del panel).
- `context/58-mcp-server.md` — sección sobre el WAF "Block AI bots" de
  Cloudflare.

## Callejones sin salida
- **Perseguir `serverInfo.icons` como fuente del logo del conector fue
  tiempo mal gastado, y probablemente no se puede.** Los conectores custom/
  self-hosted NO reciben ícono de marca hoy — eso sale del registro interno
  de Anthropic, solo first-party/marketplace. Issues abiertos pidiendo esa
  función: `anthropics/claude-ai-mcp#152`, `anthropics/claude-code#49040`,
  `modelcontextprotocol/modelcontextprotocol#1040` y discussion `#2573`.
  Favicon, URLs absolutas y data URIs en `icons` — todo probado, nada
  funciona, y coincide con lo que esos issues reportan.
- **La premisa que disparó la investigación era casi seguro falsa.** El
  owner dijo que el ícono aparecía *"desde la primera conexión"* — antes de
  que existieran los `icons` del handshake y antes del fix del favicon. Si
  ya estaba sin ninguna de las dos cosas desplegadas, no viene de nada que
  controlemos: hipótesis viva es que es el ícono GENÉRICO del cliente
  (círculo oscuro + punto verde de "conectado"), no el logo de Punto.
- **`"https://app.punto.la"` como fallback de `APP_URL` "funcionaba" en
  prod por coincidencia, no por configuración.** Al verificar contra
  Coolify, `APP_URL` NO existe en el env del Front. Regla: un `?? "literal"`
  se verifica contra el env real antes de darlo por resuelto — no alcanza
  con que ande.

## Próximo paso
Pedirle al owner que compare `https://app.punto.la/icon.png` contra la
ficha del conector en Claude Desktop/Connectors. Si son visualmente
distintos, el ícono que se ve es el genérico del cliente — dejar de tocar
`serverInfo.icons` y avisarle a la sesión "Fish" (otro proyecto, mencionado
por el owner) que pare de perseguir lo mismo ahí.

## Trampas conocidas
- **`get_sales_summary` (tool MCP) devuelve `tax: 0` en TODOS los meses**,
  incluidos los de uso real (junio: 68 tickets, 10 clientes), con el tenant
  teniendo IVA y RUC configurados. Sin diagnosticar — bug concreto más
  urgente que quedó abierto.
- **Balance y Flujo de efectivo sin verificar contra datos reales.** Las
  queries no se corrieron contra Postgres real; falta que el owner revise a
  ojo la valuación de inventario y el patrimonio derivado contra lo que él
  sabe del negocio.
- Cloudflare "Block AI bots" desactivada A MANO en el dashboard (fuera del
  repo) — Cloudflare anunció migración de esa tarjeta para el 15 de
  septiembre; si se reactiva sola, el conector MCP vuelve a fallar con
  "Couldn't reach Punto" y el síntoma no apunta a Cloudflare.
- Del informe del tester sigue abierto el ítem 5 (reporte de stock lista
  ítems de toda la compañía — el endpoint scopea, lo company-wide es la
  query de `item`; necesita que el owner mire datos reales) y `context/56`
  (cotización PDF, proyecto de varias horas).
- 2 P2 de auth decididos pero sin implementar (detalle en `context/10-
  roadmap.md`): consolidado por sucursal vía `user_outlet`+`Roc::build`; y
  fichaje por QR con secreto rotable, bloqueado hasta que el owner decida
  `soon` o completar el módulo `attendance`.
- WebSocket de realtime sin auth; TZ `America/Asuncion` literal en migs
  157/160 y `period-close.php`; ticket con logo en térmica FÍSICA sin
  probar.

**Nota de concurrencia**: esta sesión cerró en paralelo con otra que hizo el
sitio de marketing (`punto.la`, commits `53ce1895..b91b2991`). Ese trabajo
NO está descrito arriba — su propio entry de bitácora es "## 2026-08-31 (2)
— sitio de marketing..." y su hand-off detallado (objetivo, callejones,
trampas) fue sobrescrito por este archivo al cerrar. Si hace falta ese
detalle, está en el historial de git de `_handoff.md` o se puede reconstruir
del entry de bitácora + `context/61-sitio-marketing.md`.
