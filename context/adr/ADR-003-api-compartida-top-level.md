# ADR-003: API compartida en /api top-level (futura mudanza a server dedicado)

**Estado:** Aceptado
**Fecha:** 2026-05-28
**Deciden:** xstian (producto/arquitectura) + Claude
**Commit:** d75dd0b `refactor(api): extraer la API compartida a /api top-level (fuera de /panel y /app)`

## Contexto

Al desacoplar el monolito `/app` (POS), los primeros 5 slices crearon endpoints en `/app/API/v1/` y
services en `/app/lib/`. Ese approach era correcto para un primer paso, pero el usuario identificó
que es estructuralmente incorrecto:

- La **API** no es "parte de /app" ni "parte de /panel" — es el **backend único del sistema**.
- Eventualmente la API correrá en un **server dedicado** que /panel y /app consuman remotamente
  como clientes HTTP. Si los endpoints viven dentro de /app, ese split es imposible sin un refactor.
- El panel ya tenía `panel/API/*` (~93 endpoints) pero con la misma limitación implícita:
  están acoplados al módulo /panel.

## Decisión

Crear un directorio **`/api` top-level** (hermano de `/panel` y `/app`) que contenga:
- `api/router.php` — dev server router (port :8000). Superficie pública SOLO `/v1/*`.
- `api/bootstrap.php` — bootstrap + `apiAuthTenant()` (JWT de tenant compartido).
- `api/lib/response.php` — `apiOk()`/`apiError()` (envelope canónico).
- `api/lib/services/*Service.php` — servicios de dominio (SQL + reglas de negocio).
- `api/v1/*.php` — endpoints REST versionados.

Los 5 slices del desacople de /app se mueven a /api. Los BFFs de /app apuntan a /api
vía `PUNTO_API_BASE` (dev: `http://localhost:8000`).

**Panel**: `panel/API/*` queda donde está por ahora; migra a /api gradualmente conforme
se tocan módulos. No hay timeline forzado — la migración es incremental.

## Auth compartida

`apiAuthTenant()` autentica el JWT de tenant (cookie `_jwt` | `Authorization: Bearer` | POST `_jwt`).
Usa el mismo `JWT_SECRET` y claims (`cid`, etc.) que /panel (`_jwt_panel`) y /app (`_jwt`) ya validan.
**Una API autentica ambos clientes** sin duplicar el secret.

El JWT de /panel usa cookie `_jwt_panel`; el de /app usa `_jwt`. Ambos tienen el mismo secret y claims.
`apiAuthTenant()` acepta cualquiera de los dos (el BFF reenvía la cookie correcta).

## Consecuencias

**Positivas:**
- El split a server dedicado = cambiar `PUNTO_API_BASE` en los BFFs. Sin cambios de código de
  endpoints ni services.
- Una sola API genérica y reusable (puede servir a futuras apps: ecommerce, billetera digital).
- /app queda como puro front+BFF; /panel idem gradualmente.

**Negativas / deuda:**
- **Transitoria**: `api/bootstrap.php` hace `chdir(/app)` y reutiliza los includes de /app
  (`db/functions/jwt_middleware/head.php/data.php`) vía rutas absolutas. Esto acopla /api a /app
  en desarrollo. La consolidación de un `/api/includes` canónico (independiente de /panel y /app)
  es la tarea pendiente antes de que /api pueda mudarse a su propio server.
- **Gradual**: `panel/API/*` todavía está fuera de /api. Hay que migrar en paralelo con el trabajo
  de módulos, sin un deadline forzado.

## Alternativas descartadas

- **Dejar los endpoints en /app/API/v1/**: impide el split a server dedicado.
- **Mover todo de una vez**: big-bang, riesgo de regresión, no alineado al principio de migración
  incremental del proyecto.

## Referencias

- `02-arquitectura.md § API compartida (/api)`
- `05-modulos-clave.md § /api`
- `06-infraestructura.md § PUNTO_API_BASE`
- `08-convenciones.md §23`
- `10-roadmap.md § Consolidar /api/includes canónico`
