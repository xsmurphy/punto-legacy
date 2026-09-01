# Hand-off — 2026-08-31

## Objetivo
Que el agente IA (MCP y el chat embebido del panel/POS) lea datos reales de Punto y devuelva reportes verificables en vez de adivinar semántica de columnas crudas. Cubrió: destrabar el conector MCP de Claude, normalizar el catálogo de 20 tools compartido, sumar comparativas de período, y en paralelo el asistente IA de la caja + teclado virtual + limpieza de reportes (sesiones paralelas, ver bitácora).

## Estado al cerrar
`main` en `dd53e606` (más los merges de las otras 3 sesiones paralelas de hoy — ver bitácora, entries `(2)`/`(3)`/`(4)`). Deploys verificados con el MCP de Coolify: Front en `5630c3d1` (deployment `1oag5axpukdg1cnq2wdheqvv`, finished), Backend en `6afe4ba0` (deployment `t8idwjy4kr4hwfvieaztilk2`, finished). **`dd53e606` (Front) quedó SIN deployar** — único commit de código posterior al último deploy front. El MCP funciona de punta a punta, verificado con datos reales del tenant ICAS: 20 tools, `pagos_epos` fuera del catálogo, `compareWith`/`get_sales_kpis` presentes, `ventas_resumen`/`clientes` devuelven filas reales con la moneda del tenant en `meta`.

## Archivos y cambios
- `frontend/lib/domain/sale-type.ts` — fuente única TS de tipos de transacción (reemplaza vocabulario triplicado: enum PHP + mapa parcial + enteros mágicos).
- `frontend/lib/agent/normalize-tool-result.ts` + `tool-field-rules.ts` — normalización semántica de las 20 tools (compartido MCP + agente del panel), poda de campos internos, tope 200 filas, moneda en `meta`.
- `frontend/lib/agent/read-tools.ts` — `compareWith` (previous_period/previous_year) + `get_sales_kpis`; `pagos_epos` retirado.
- Rutas/enum de `get_report` centralizados en tabla única + test contra filesystem (arreglaba 3 de 20 reportes que 404eaban en prod).
- 14 endpoints de `api/v1/reports/*` — allowlist del realm `api` (3 con condición por método).
- ~63 archivos, texto visible: `mesa`→`espacio` (identificadores de código y sitio de marketing quedaron afuera a propósito).
- `context/58-mcp-server.md` — actualizado (handshake, causa Cloudflare).
- `frontend/app/(panel)/reports/customers/**` — quitado el aviso de cobertura del mapa.

## Callejones sin salida
- El 401 del MCP tenía DOS causas encadenadas, no una: primero el handshake disparaba OAuth/DCR (fix), y resuelto eso seguía fallando por Cloudflare bloqueando los user-agents de Anthropic — sin separarlas parecía un solo bug a medio resolver.
- 3 de los 20 reportes de `get_report` apuntaban a endpoints que no existen en prod (404) y el modelo los elegía igual, porque el catálogo no validaba rutas contra el filesystem — ahora sí, con test.

## Próximo paso
OAuth para el conector (camino 2 de `context/58`): hoy anda con API key por header adicional, sirve para un técnico pero no para que un comercio lo instale solo. La investigación (RFC 9728+8414+7591+PKCE, librerías candidatas) ya está en `context/58`. Alternativa que mencionó el owner: metodologías de análisis para el agente ("skills" del lado de Punto) — ahora tienen datos reales que las sostienen.

## Trampas conocidas
- **Cloudflare "Block AI bots" está desactivada A MANO** en la zona `punto.la`, fuera del repo. Si alguien la reactiva, el conector MCP muere con "Couldn't reach Punto" y el síntoma no señala a Cloudflare por ningún lado.
- **El catálogo del MCP se cachea del lado del cliente**: tras cambiar tools hay que reconectar el conector para verlas.
- El asistente de la caja NO responde hasta que el tenant tenga créditos IA cargados en `/admin` → Empresas → Créditos IA (saldo 0 es literal, no bug).
- `tenant_audit` atribuye las escrituras del asistente del POS al contacto que pareó la tablet, no al operador del PIN — P1 pendiente.
- D9 de `context/59` sin implementar: `/v1/reports/drawers` no scopea por caja ni chequea permisos; `get_drawers` quedó fuera del catálogo del asistente como mitigación.
- `context/62-dashboard-operaciones.md` es plan con D1-D9 propuestas SIN OK del owner — no asumir ninguna cerrada.
- Plan de compras en el POS (alcance ya cerrado por el owner: solo cargar, mismo impacto en stock que el panel) sigue sin escribirse.
- Trampas recurrentes: no culpar al deploy sin comparar horas en UTC (Paraguay es UTC−3); `npx vitest` desde la raíz del repo falla, correr desde `frontend/`; `psql`/SSH a la BD bloqueados por el classifier.
