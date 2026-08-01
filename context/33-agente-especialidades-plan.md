# Especialidades del agente IA ("Super Poderes") — plan

> Visión del owner (2026-08-01): el agente base (deepseek flash) queda como
> está — consulta y ejecuta lo básico. Encima, **especialidades pagas por
> suscripción** (Finanzas, Marketing, Administración, Ventas, …) que suman
> análisis profundo, entregables (XLS/PDF) y un modelo LLM superior.
> Decisión: NO construir el framework de 4 especialidades de entrada —
> UNA (Finanzas) de punta a punta valida el patrón; las demás se estampan
> después sobre lo aprendido.

## Principio de diseño

Una especialidad NO es un agente aparte: es el MISMO chat con más
capacidades cuando el módulo está activo. El usuario no cambia de
interfaz — el agente "sabe más". Componentes de una especialidad:

| Pieza | Mecanismo (ya existe) |
|---|---|
| Suscripción/billing | Módulo activable por tenant (catálogo de módulos + BillingService), key `agent-finance` etc. |
| Modelo LLM propio | `ai_model_config` por capability — capability nueva por especialidad (ej. `finance_analysis`), modelo y precio de créditos configurables desde `/admin` sin deploy |
| Tools especializadas | El route del agente arma el toolset por request según módulos activos del tenant |
| Prompt | Bloque de especialidad inyectado tras los guardrails (mismo patrón que la personalidad, `d6f946c8`) |
| Cobro por uso | Créditos IA existentes (`/v1/ai/debit`) — la capability cara debita más por token |

Sin la suscripción, el agente base detecta la pregunta especializada y
ofrece la especialidad (upsell dentro del chat, sin bloquear lo básico).

## Ruteo de modelo

El route decide la capability POR LLAMADA: la conversación corre en la
capability `chat` (deepseek flash); cuando el pedido matchea la
especialidad activa, esa consulta corre con la capability de la
especialidad (modelo superior). Criterio v1 simple: si el módulo está
activo y el mensaje dispara una tool especializada → modelo de la
especialidad para ese turn. Nada de clasificadores aparte.

## Especialidad 1 — Finanzas (v1, valida el patrón)

Los datos ya están: `fin_movement`/cuentas, forecast (`ObligationsService`),
créditos (`fin_loan`), cheques, compras a crédito, rollups de reportes
(context/18). Piezas:

1. **Módulo** `agent-finance` en el catálogo + gating en el route.
2. **Capability** `finance_analysis` en `ai_model_config` (seed con un
   modelo razonable, ajustable en `/admin`).
3. **Tools v1** (4, no más):
   - `get_finance_series`: series mensuales/semanales de
     ingresos/egresos/neto por cuenta y rango (sobre rollups + fin_movement).
   - `get_cashflow_projection`: proyección de caja N semanas — saldo actual
     + obligaciones del forecast (cheques/cuotas/compras a crédito) +
     promedio móvil de ingresos/egresos de los últimos 90 días. Determinística
     en SQL/PHP; el LLM la narra e interpreta, NO la calcula.
   - `get_expense_breakdown`: desglose de egresos por categoría fin_category
     con variación contra el período anterior.
   - `generate_xlsx_report`: entregable descargable (ver abajo).
4. **Prompt de especialidad**: rol de analista financiero; SIEMPRE cifras
   de tools (anti-invento vigente); proyección ≠ certeza (comunicar
   supuestos); usa `render_chart` para toda serie/comparación.

### Entregables

- **v1 = XLSX**: tool `generate_xlsx_report(sheets: [{title, columns,
  rows}])` — endpoint PHP genera el archivo (misma librería del export de
  DataTable si es server-side; si el export actual es client-side, se usa
  una lib PHP de spreadsheet en el backend), lo guarda en S3 scoped al
  tenant y devuelve URL firmada/temporal. El chat muestra card de descarga.
  Los datos del XLSX salen de las tools de datos en la MISMA conversación
  (regla anti-invento aplica a entregables).
- **PDF: v2.** Requiere plantilla con identidad de marca — no entra hasta
  que el XLSX pruebe demanda.

### Análisis largos (v1.5, no v1)

Una "investigación profunda" no cabe en el request del chat. Cuando haga
falta: job en background (tabla `agent_job` + worker o procesamiento
diferido), resultado notificado por el centro de notificaciones
(`kind` nuevo en el feed, rail listo — context/31). v1 se limita a
análisis que resuelven en un request.

## Fases

- **F0** — módulo `agent-finance` + capability + gating de toolset +
  prompt + upsell. Sin tools nuevas todavía: el gate se prueba solo.
- **F1** — tools de datos (series, proyección, desglose) + charts.
- **F2** — `generate_xlsx_report` + card de descarga en el chat.
- **F3** — jobs en background + notificación (análisis largos).
- **F4** — segunda especialidad (Ventas o Marketing) reusando el patrón —
  acá se decide qué se generaliza a un registry de especialidades.

## Decisiones abiertas (owner, antes de F0)

1. Precio y nombre comercial del módulo ("Super Poder: Finanzas"?).
2. Modelo para `finance_analysis` (sugerencia: uno mid-tier razonable
   primero; el margen de créditos se calibra en `/admin`).
3. ¿La especialidad la ve todo rol con acceso al chat, o solo roles con
   `finance.manage`? (Sugerencia: gatear por el permiso — el agente no
   debería contar finanzas a un cajero.)

## Anti-objetivos

- Chat/interfaz separada por especialidad.
- Clasificador de intención como servicio aparte.
- Framework genérico de especialidades antes de que Finanzas venda.
- PDF en v1.
- Cálculo financiero hecho por el LLM (todo número computado sale de SQL/PHP).
