# Hand-off — 2026-08-31

## Objetivo
El MCP server de Punto (`context/58`) funciona técnicamente (M0/M1 hechas
2026-08-30) pero hasta ayer nadie había podido CONECTARLO desde un cliente
real (Claude Desktop/Connectors) ni ejecutar una tool contra prod. Esta
sesión cerró esa verificación.

## Estado al cerrar
`origin/main` = `ff66e624`. Working tree limpio. Deployado: Front,
deployment `nd7exabsoeb9kvcdokibmkuf`, `finished` 2026-08-31 03:12 UTC, app
`running:healthy`. Backend NO se tocó esta sesión (no hubo cambios en `api/`).

**El conector de Claude quedó funcionando de punta a punta.** M2 (prueba
real con Claude Desktop) queda VERIFICADA: el owner conectó el conector y
desde la sesión se ejecutaron 4 tools con datos reales del tenant ICAS
(`get_settings`, `get_outlets`, `get_sales_summary`, `get_top_products`).

Hoy el conector funciona por **API key vía header adicional** (`x-api-key`
u otras variantes) — sirve para un técnico que edita config a mano o pega la
key en el diálogo de Connectors, pero NO es instalable por un comercio sin
ayuda. Eso es lo que falta para que sea un producto vendible (ver Próximo
paso).

## Archivos y cambios
- `frontend/app/api/mcp/route.ts` — `initialize`/`tools/list` ya no exigen
  key (200 sin credencial); `tools/call` sin key devuelve `isError: true` en
  vez de rechazar en el protocolo.
- `frontend/lib/__tests__/mcp-route.test.ts` — 6/6. Se reescribieron 2 tests
  (el que exigía 401 ahora exige 200 sin credencial) y se agregó uno nuevo
  que verifica que `tools/call` sin key no llega a llamar a la API.
- `context/58-mcp-server.md` — sección nueva "RESUELTO — el conector conecta
  de punta a punta" con las dos causas, el fix, y la investigación de RFCs/
  librerías para OAuth (camino 2). M2 marcada verificada en la tabla de fases.

## Callejones sin salida
- **Dos errores distintos se veían como "el mismo problema".** "Couldn't
  register with Punto's sign-in service" (OAuth/DCR) y "Couldn't reach
  Punto" (Cloudflare) son causas independientes y secuenciales. Si el owner
  dice "sigue el mismo error", comparar el TEXTO exacto antes de asumir que
  no hubo avance — cambió.
- **Un bloqueo de borde es invisible desde curl.** Todo el smoke previo (y el
  del hand-off de ayer) usaba curl con su user-agent default, que Cloudflare
  dejaba pasar. Al depurar "no conecta desde el cliente X", reproducir con el
  USER-AGENT real de X.
- **El scope de "Block AI bots" no filtra por path.** Se buscó excluir solo
  `/api/mcp` y la UI de esa tarjeta legacy no lo permite (solo: todas las
  páginas / páginas con ads / no bloquear). La alternativa habría sido una
  WAF custom rule con acción Skip (hay un molde en la cuenta: "Allow
  Telegram Webhook", `/api/webhooks/telegram`). Se optó por desactivar la
  tarjeta entera porque `punto.la` no tiene contenido público scrapeable y
  las políticas nuevas de esa misma pantalla ya estaban en "Allow".
- **Claude no puede tocar configuración de seguridad** (WAF/Cloudflare) ni
  con autorización explícita del usuario — el clasificador corta la acción.
  Preparar los pasos exactos y pedírselos al owner, no reintentar.

## Próximo paso
Implementar OAuth para el conector (camino 2 del hand-off anterior): es lo
que lo vuelve instalable por un comercio sin un técnico de por medio (hoy
depende de pegar una API key a mano). La investigación ya está hecha —
sección nueva en `context/58-mcp-server.md`, cerca de la línea 366—: cadena
RFC 9728 (protected resource metadata) + 8414 (AS metadata) + 7591 (DCR) o
CIMD + 8707 + 9207, 401 con `WWW-Authenticate: Bearer resource_metadata=...`
como disparador, y librerías candidatas `mcp-auth` / `fastmcp-oauth` /
`@cloudflare/workers-oauth-provider` (ninguna lista out-of-box para Next.js
App Router + PHP — hay que armar el resource server a mano).

## Trampas conocidas
- **Cloudflare "Block AI bots" fue desactivado A MANO en el dashboard, no en
  el repo.** Si alguien la vuelve a activar (o Cloudflare la reactiva con su
  migración anunciada para el 15 de septiembre), el conector vuelve a morir
  con "Couldn't reach Punto" y el síntoma no apunta a Cloudflare — repetir el
  probe con user-agents `Claude-User/1.0`/`Claude-Web/1.0`/`anthropic-ai/1.0`
  antes de sospechar del backend.
- **2 P2 de auth DECIDIDOS pero SIN implementar** (`context/10-roadmap.md`):
  (a) el consolidado por sucursal pasa a "permisos del usuario + sucursales
  asignadas" — necesita `user_outlet` y que `Roc::build` emita `IN (...)`
  (ver `context/25` §4.5); (b) el fichaje por QR va a secreto rotable por
  sucursal, BLOQUEADO porque el módulo `attendance` figura `available` sin
  UI ni generador de QR en el stack nuevo — falta que el owner decida
  `soon` o completarlo.
- **Sin tope de tamaño de respuesta en el MCP**: `get_transactions` trae
  hasta 5000 filas y todas viajan. Sin límite de concurrencia por key más
  allá de `maxDuration = 60`. Sin scopes por key (el campo `meta` está listo,
  sin usar).
- Del informe del tester quedan el ítem 5 (stock — el endpoint scopea, lo
  company-wide es la query de `item`; necesita que el owner mire datos
  reales antes de tocarlo) y `context/56` (cotización PDF, proyecto de varias
  horas).
- Pendientes de antes: WebSocket de realtime sin auth, TZ `America/Asuncion`
  literal en migs 157/160 y `period-close.php`, y el ticket con logo en
  térmica FÍSICA sin probar.
