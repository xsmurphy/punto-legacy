# Hand-off — 2026-08-30

## Objetivo
Construir el MCP server de Punto (`context/58`) para que un tenant conecte
Claude u otra IA a sus datos. Decisión del owner: el MCP se hace sí o sí, no
había que validarlo.

## Estado al cerrar
`origin/main` en `48b4c9ee`. **Deployado hasta `a5de9258`** (Front y Backend,
ambos `running:healthy`, verificados por MCP de Coolify + curl contra prod).

⚠ `48b4c9ee` y otros commits del asistente del POS son de una **sesión
paralela** y NO los deployó esta sesión — confirmar con esa sesión antes de
asumir que están en prod.

Migración **182** (rename del realm) aplicada.

**M0 y M1 completas.** La cadena funciona de punta a punta contra prod,
verificada con curl:

```
POST /api/mcp con x-api-key → 200, handshake OK, 0.3s
GET  /api/mcp               → 405 Allow: POST, 0.26s
```

**PERO no se pudo conectar desde la UI de Connectors de Claude Desktop.** Ver
Callejones. El camino por archivo de config con `mcp-remote` SÍ conectó
(`Proxy established successfully`), pero nadie llegó a ejecutar una tool: **que
las llamadas devuelvan datos sigue SIN VERIFICAR**.

## Archivos y cambios
- `frontend/lib/agent/read-tools.ts` — NUEVO. Las 20 tools de lectura, extraídas
  de `app/api/agent/chat/route.ts` (734 → 282 líneas). Agnóstico del transporte:
  `defineTool` propio, sin importar `tool()` del AI SDK.
- `frontend/app/api/mcp/route.ts` — el server. Stateless obligatorio; un
  `McpServer` POR REQUEST (compartirlo filtraría la credencial del primer
  tenant); `KEY_HEADERS` acepta la key por `x-api-key` y variantes; GET/DELETE
  → 405 inmediato.
- `api/lib/Auth/ApiKeyService.php` + `api/v1/api-keys.php` — emisión/listado/
  revocación. Sin tabla nueva: `auth_session` con realm `api`.
- `api/bootstrap.php` — TRES guards del realm `api` en el embudo: read-only
  (405 si no es GET/HEAD), rate limit (60/min + 5000/día por key, FAIL_OPEN), y
  auditoría de TODA llamada (invierte la regla general: audita lecturas).
- `api/database/migrations/postgres/182_realm_mcp_to_api.sql` — rename del realm
  en `auth_session` y `tenant_audit`.
- 18 endpoints de `/v1/*` con `'api'` en su allowlist.
- `frontend/app/(panel)/settings/api-keys/page.tsx` + `hooks/use-api-keys.ts`.
- Arneses: `api/tests/{api_key,api_realm}_test.php` (24/24 y 13/13) y
  `frontend/lib/__tests__/mcp-route.test.ts` (5/5).

## Callejones sin salida
- **La UI de Connectors de Claude Desktop NO conecta.** Falla con *"Couldn't
  register with Punto's sign-in service"*: para servers remotos asume OAuth e
  intenta dynamic client registration. Sus "Additional request headers" NO
  reemplazan a OAuth — se mandan *"alongside the OAuth bearer token"*, y
  `authorization` aparece deshabilitado en el menú porque Claude lo reserva.
  Agregar `x-api-key` (`61e25e2c`) no alcanzó.
- **El GET colgado era un bug real pero NO el que bloquea el conector.** El
  transporte en stateless abría un stream SSE que nunca cerraba (`curl` daba
  HTTP 000 a los 15s). Se arregló (`e5772e5b`, 405 inmediato) y el conector
  siguió fallando igual con el error de OAuth. No volver a atribuirle eso.
- **Dos veces se culpó al deploy sin verificar la hora.** El primer 404 sí era
  un deploy a medio terminar; el segundo NO — el deploy había cerrado 2,5 h
  antes. Paraguay es UTC−3: comparar contra `finished_at` en UTC antes de
  afirmar.
- **`authSessionCreate('mcp', ...)` no entró en el primer barrido del rename.**
  Habría seguido emitiendo keys con el realm viejo contra endpoints que ya
  esperaban el nuevo: ninguna key nueva habría funcionado. En un rename de
  realm, revisar el WRITER, no solo los readers.
- **El PHP de desarrollo no tiene phpredis**, así que el rate limit solo se
  ejercita por su rama FAIL_OPEN. Que corte en 60/min únicamente se comprueba
  donde haya Redis.
- El token de Coolify NO tiene `read:sensitive`: `get_deployment` con
  `include_log_summary` falla. No se pueden leer logs de build desde el MCP.

## Próximo paso
**Decidir entre dos caminos para que el conector de Claude Desktop funcione**, y
ejecutar el elegido:

1. **Probar la hipótesis del 401** (barato, incierto): que Claude intente OAuth
   porque nuestro server responde 401 sin credencial. El experimento es dejar
   que `initialize` y `tools/list` respondan SIN credencial y exigir la key solo
   en `tools/call` — hoy el listado ya sale con una key inventada igual, porque
   la validez la resuelve la API recién en cada llamada, así que no baja la
   seguridad. Es una hipótesis sin confirmar.
2. **Implementar OAuth** (caro, seguro): metadata del authorization server,
   dynamic client registration, endpoint de autorización con pantalla de
   consentimiento, token y refresh. Es el camino prolijo y el que vuelve esto
   instalable por un comercio.

Antes de cualquiera de los dos: **verificar por el camino que YA funciona** que
las tools devuelven datos. Config en
`~/Library/Application Support/Claude/claude_desktop_config.json` con
`mcp-remote` y `--transport http-only`; el header va como
`"Authorization:${VAR}"` SIN espacio (Claude parte los args por espacios).

## Trampas conocidas
- **Nadie ejecutó una tool todavía.** El smoke cubre handshake y `tools/list`;
  que `/v1/*` devuelva datos con una key real está sin probar. Si falla, mirar
  **Reportes → Auditoría** filtrando realm `api`: si la llamada figura, la key
  entró y el problema está más adentro; si no figura, no pasó el gate de auth.
- La key existente quedó migrada a realm `api` por la mig 182 y sigue sirviendo.
  La URL de la pantalla cambió a `/settings/api-keys`.
- **Sin tope de tamaño de respuesta**: `get_transactions` trae hasta 5000 filas
  y todas viajan. Sin límite de concurrencia por key más allá del
  `maxDuration = 60`. Sin scopes por key (el campo `meta` está listo, sin usar).
- **2 P2 de auth DECIDIDOS pero SIN implementar** (`context/10-roadmap.md`):
  (a) el consolidado por sucursal pasa a "permisos del usuario + sucursales
  asignadas" — NO es un parche, necesita `user_outlet` y que `Roc::build` emita
  `IN (...)`, y ese helper lo lee TODO reporte (ver `context/25` §4.5);
  (b) el fichaje por QR va a secreto rotable por sucursal, pero está BLOQUEADO:
  el módulo `attendance` figura `status: "available"` y en el stack nuevo solo
  existe el verificador — no hay UI ni generador de QR. Falta que el owner
  decida si se marca `soon` o se completa.
- Del informe del tester quedan el ítem 5 (stock — el endpoint scopea, lo
  company-wide es la query de `item`; necesita que el owner mire datos reales
  antes de tocarlo) y el 3 (`context/56`, proyecto de varias horas).
- Pendientes de antes: WebSocket de realtime sin auth, TZ `America/Asuncion`
  literal en migs 157/160 y `period-close.php`, y el ticket con logo en térmica
  FÍSICA sin probar.
