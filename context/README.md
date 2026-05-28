# /context/ — Kit de Contexto Técnico

Documentación viva del proyecto Punto POS. Se mantiene actualizada sesión a sesión.

## Índice

| # | Archivo | Qué contiene |
|---|---------|-------------|
| — | [_session-log.md](_session-log.md) | Bitácora de sesiones (cronológico inverso) |
| 01 | [01-producto.md](01-producto.md) | Producto, modelo de negocio, principios UX |
| 02 | [02-arquitectura.md](02-arquitectura.md) | Vista de 30 segundos, patrones, god nodes |
| 03 | [03-stack.md](03-stack.md) | Lenguajes, frameworks, versiones exactas |
| 04 | [04-modelo-de-dominio.md](04-modelo-de-dominio.md) | Schema, entidades, relaciones, invariantes |
| 05 | [05-modulos-clave.md](05-modulos-clave.md) | Qué hace cada servicio/módulo |
| 06 | [06-infraestructura.md](06-infraestructura.md) | Deploy, Docker, env vars, migraciones |
| 07 | [07-glosario.md](07-glosario.md) | Términos del producto y del código |
| 08 | [08-convenciones.md](08-convenciones.md) | Reglas de colaboración detalladas |
| 09 | [09-costos-y-creditos.md](09-costos-y-creditos.md) | APIs pagas, modelo de créditos IA |
| 10 | [10-roadmap.md](10-roadmap.md) | Backlog técnico priorizado |
| 11 | [11-design-system.md](11-design-system.md) | Manual de marca: colores, tipografía y clases existentes (BS3 + app.css) |

## Reglas de mantenimiento

- Cada doc tiene un comentario HTML al inicio indicando cuándo actualizarlo
- Ver la tabla de actualización en `CLAUDE.md` (REGLA OBLIGATORIA #2)
- Criterio: "¿la próxima sesión arrancaría confundida sin esta nota?"
- Cap blando de `_session-log.md`: 200 líneas. Al superar, archivar a `_session-log-archive-YYYY-MM.md`
