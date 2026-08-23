/**
 * Healthcheck del contenedor del front.
 *
 * Lo consume el `HEALTHCHECK` del Dockerfile (y, a través de él, Coolify: sin
 * esto solo sabe si el proceso existe, no si Next terminó de levantar y puede
 * responder — el estado que mostraba era "Running (no healthcheck)").
 *
 * Deliberadamente NO toca la API ni la base: es la salud de ESTE proceso. Si
 * el back está caído, el front sigue sano y debe seguir sirviendo — el POS
 * opera offline y depende de que este contenedor esté en pie. Encadenar la
 * salud del front a la del back haría que Coolify lo reinicie durante un
 * incidente del back, justo cuando la caja más lo necesita.
 *
 * `force-dynamic` para que no se prerenderice en build: un healthcheck
 * cacheado responde OK aunque el runtime esté en llamas.
 */

export const dynamic = "force-dynamic"

export function GET() {
  return Response.json({ ok: true })
}
