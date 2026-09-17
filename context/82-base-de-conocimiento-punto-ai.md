# 82 — RAG de ayuda para Punto AI

> Pedido del owner (2026-09-17): que Punto AI responda cómo se usa Punto a
> partir de la base de conocimiento.
>
> **Estado: plan sin implementar.** D1-D4 CERRADAS por el owner 2026-09-17
> (D3 ampliada el mismo día: son DOS fuentes); D5-D10 PROPUESTAS sin su OK. Leer §6 arquitecturas rechazadas antes de
> proponer nada.
>
> Reescrito el mismo día: la primera versión proponía subir `.md` desde
> `/admin` y búsqueda full-text en la base de los tenants. Las dos premisas
> eran equivocadas — ver D3 y D4.

## 1. El hueco que cierra

Punto AI conoce sus tools, no el producto. Su prompt se lo dice explícito:
nunca afirmar que Punto "no tiene" algo, porque que una tool no ofrezca un
dato no significa que el sistema no lo soporte. Ante "¿cómo configuro la
impresora de cocina?" hoy no tiene de dónde responder.

## 2. Estado actual (verificado 2026-09-17)

- **La base de conocimiento ya existe**: `frontend/content/ayuda/`, 28
  artículos `NN-slug.md` con frontmatter (`title`, `slug`, `modulo`,
  `audiencia`, `keywords`, `resumen`). Genera `docs.punto.la` en el build del
  Front, y su `README.md` ya la define como fuente única "que lee el sitio y
  lee el asistente". Tiene test de integridad de rutas
  (`lib/docs/__tests__/ayuda-integrity.test.ts`).
- **Cero infraestructura de recuperación**: ni pgvector, ni embeddings, ni
  tool que busque en documentos.
- **La BD de producción es `postgres:18-alpine`** (Coolify `Punto BD`,
  `w6rtfxm2n6l45r4r9melj3hl`) — NO la 16 del `docker-compose.yml` local. La
  imagen oficial no trae pgvector.
- **El Front no habla con bases de datos.** `frontend/package.json` no tiene
  driver: todo dato pasa por la API PHP.
- **La imagen del backend no contiene `frontend/`.** Su build context es
  `./api` (`api/Dockerfile`), así que no puede leer los artículos de su propio
  disco.
- **OpenRouter tiene embeddings**: `POST /api/v1/embeddings`, formato OpenAI,
  con `text-embedding-3-small`/`-large` entre otros. La misma
  `OPENROUTER_API_KEY` que ya usa el agente.
- `content/sitio/*.md` es OTRA base, comercial, para el agente de atención
  externo (`context/61`). No se toca.

## 3. Decisiones

### D1 — El RAG es para Punto AI de los comercios. **Cerrada (2026-09-17).**

El asistente del panel y de la caja, no el agente de atención.

### D2 — Una sola burbuja: Punto AI. El soporte del CRM queda FUERA de alcance. **Cerrada (2026-09-17).**

Punto AI conserva la burbuja y es lo único que se construye acá.

El CRM de soporte (IA + humanos) es otro sistema, con su propio RAG ya hecho,
y **no se integra todavía**. Nada de este plan depende de él ni lo prepara: si
más adelante entra, se decide ahí dónde vive su acceso. Lo único que queda
dicho es la regla que lo motivó — dos burbujas flotantes en la misma pantalla,
no.

### D3 — DOS fuentes en un solo índice: `ayuda` (git) y carga en /admin. **Cerrada (2026-09-17, ampliada el mismo día).**

1. **`frontend/content/ayuda/`** — los artículos que ya se escriben para
   `docs.punto.la`, versionados en git y publicados con el deploy del Front.
   El bot indexa ESOS mismos archivos; no se vuelven a subir a mano, porque
   dos copias de lo mismo divergen.
2. **Carga desde `/admin`** — para lo que el owner quiere que el bot sepa y
   **no va al sitio público**: políticas, casos raros, respuestas a situaciones
   que todavía no están escritas como artículo.

La regla que las separa: si algo sirve para cualquier comercio y se puede
publicar, se escribe como artículo en git. `/admin` es para lo que no.

⚠ **Nada cargado en `/admin` se publica en `docs.punto.la`** — pero sí lo
puede leer cualquier comercio, porque el bot responde con eso. No es un
cajón de notas internas: no subir precios de costo, datos de otros clientes,
credenciales ni nada que no quieras que lea un comerciante.

**Si las dos fuentes se contradicen no hay desempate automático.** Las dos
llegan al modelo etiquetadas con su origen, y una contradicción es un problema
de contenido que se arregla escribiendo, no una regla de precedencia — inventar
que "`/admin` pisa a git" esconde el error en vez de mostrarlo. R4 registra
estos casos.

### D4 — Base pgvector APARTE, exclusiva del RAG. **Cerrada (2026-09-17).**

Propuesta del owner. Una base Postgres nueva con pgvector (imagen
`pgvector/pgvector:pg18`) como recurso propio en Coolify. La base de los
tenants no se toca, no se reinicia, no cambia de imagen.

Encaja con el dato: la base de ayuda es global, no tiene datos de ningún
comercio y no necesita cruzarse con nada de la base de los tenants.

**Consecuencia que simplifica**: no necesita backups. Se reconstruye entera
desde git en cualquier momento. Si se pierde, se reindexa.

### D5 — Embeddings por OpenRouter, modelo fijo por índice. *(propuesta)*

`text-embedding-3-small` vía `/api/v1/embeddings` de OpenRouter, con la clave
que ya existe. La cuenta de OpenAI no hace falta.

El modelo queda anotado en cada fila. **Cambiar de modelo obliga a reindexar
todo**: vectores de modelos distintos no son comparables, y mezclarlos devuelve
resultados sin sentido sin dar ningún error.

### D6 — Solo el backend habla con la base del RAG. *(propuesta)*

Endpoint `GET /v1/help/search?q=` en la API PHP, que ya tiene PDO. Acepta el
realm del panel y el del device (Bearer, sin cookies — mandato del POS).
Embebe la consulta, busca y devuelve fragmentos.

Meter un driver de Postgres en el Front sería abrir un patrón nuevo solo para
esto y romper la regla de que el Front pasa por la API.

### D7 — Cómo llegan los artículos al índice: sincronización por hash. *(propuesta)*

Los artículos viven en el Front y el índice en el backend (§2). El puente:

1. El build del Front arma un manifiesto de los artículos ya fragmentados
   (D8), con un hash por fragmento.
2. Al arrancar el contenedor del Front, se envía ese manifiesto a un endpoint
   interno del backend, autenticado con clave interna.
3. El backend compara hashes: embebe solo los fragmentos nuevos o cambiados,
   borra los que ya no están, y no toca el resto.

⚠ **El borrado del paso 3 se acota a `source = 'ayuda'`.** El manifiesto del
Front no sabe nada de lo cargado en `/admin` (D3): un `DELETE` de todo lo que
no venga en el manifiesto borraría esa fuente entera en el primer deploy.

Por qué por hash: re-embeber todo en cada deploy es pagar embeddings de 28
artículos cada vez que se cambia una línea de CSS. Y como es idempotente,
mandarlo dos veces no hace nada.

La pantalla de `/admin` hace las dos cosas: **cargar** lo de la segunda fuente
(D3) y **diagnosticar** — última sincronización, fragmentos por fuente, fallos,
y un botón Reindexar que fuerza el proceso.

### D8 — Fragmentar por encabezado, con el frontmatter adentro. *(propuesta)*

Cada artículo se parte por sus secciones `##`. Cada fragmento lleva título,
ruta de encabezados, `keywords` y `audiencia` del artículo, más el `slug` para
linkear a `docs.punto.la`.

Las `keywords` se escribieron justo para cómo pregunta un comerciante
(`README.md`: "los sinónimos que un comerciante realmente escribiría"). Van
dentro del texto embebido y además en un `tsvector` en la misma base: búsqueda
**híbrida**, vector + texto, para que un término exacto ("timbrado") no pierda
contra algo que solo se le parece.

### D9 — Tool `search_punto_help`, con umbral. *(propuesta)*

En el catálogo compartido `frontend/lib/agent/read-tools.ts`. Devuelve los 3-5
fragmentos más relevantes que pasen un umbral de similitud, con el link al
artículo.

Regla en el prompt: el CÓMO se responde con lo que devuelve la tool, citando
el artículo. Si nada pasa el umbral, se dice que no está documentado — nunca
se inventa un paso, y nunca se afirma que la función no existe (el artículo
puede faltar aunque la función esté). No se nombra ningún canal de soporte:
mientras el CRM esté fuera de alcance (D2), no hay uno que ofrecer desde acá.

⚠ El agente de la caja usa ALLOWLIST (`lib/pos/agent-tools.ts`): la tool hay
que sumarla ahí a propósito.

El resultado entra como resultado de tool, nunca al system prompt: aunque lo
escriba Punto, es DATO.

### D10b — Conocimiento por RUBRO, y cómo se conecta con el comercio que pregunta. *(propuesta)*

Un caso central de la carga de `/admin` (D3) es el conocimiento que aplica a un
rubro y no a todos: qué mirar en una panadería, cómo suele trabajar una
veterinaria, qué se le suele preguntar a un taller.

Cada documento de `/admin` puede declarar a qué rubros aplica (o a ninguno =
vale para todos). Eso sirve para dos cosas:

1. **Dar contexto general**, aunque nadie pregunte por el rubro: entra por la
   búsqueda como cualquier otro fragmento.
2. **Priorizar lo del rubro del comercio que está preguntando.** El agente ya
   sabe de qué va cada negocio: `agentBusinessContext` (`context/69`) es el
   texto que el propio comercio escribió sobre sí mismo, y viaja en el prompt
   del panel y de la caja. Un fragmento del rubro que coincide se ordena
   primero; los de otros rubros quedan abajo, no se ocultan.

Lo que NO se hace: filtrar duro por rubro. El rubro del comercio sale de un
texto libre que el comercio escribió (D1 de `context/69`), no de una taxonomía
— usarlo para ESCONDER contenido haría que una panadería que se describió como
"cafetería" deje de ver lo que le sirve. Ordena, no censura.

### D10 — El costo lo absorbe Punto. *(propuesta)*

Indexar es costo de Punto, no de un comercio. La consulta de cada búsqueda es
un embedding de pocas palabras. Se registra en logs para saber cuánto cuesta,
pero no se descuenta del crédito IA del tenant.

## 4. Modelo *(propuesta, en la base del RAG)*

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE TABLE help_chunk (
  chunkid      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  source       TEXT NOT NULL,          -- 'ayuda' (git) | 'admin' (D3)
  slug         TEXT NOT NULL,          -- artículo; solo 'ayuda' linkea a docs.punto.la
  title        TEXT NOT NULL,
  headingpath  TEXT NOT NULL,
  audiencia    TEXT,
  rubros       TEXT[],                 -- D10b: vacío = aplica a todos
  content      TEXT NOT NULL,
  contenthash  TEXT NOT NULL UNIQUE,   -- idempotencia de D7
  model        TEXT NOT NULL,          -- D5: nunca mezclar modelos
  embedding    VECTOR(1536) NOT NULL,  -- text-embedding-3-small
  search       TSVECTOR NOT NULL,      -- D8, calculado en el servicio
  indexed_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_help_chunk_embedding ON help_chunk USING hnsw (embedding vector_cosine_ops);
CREATE INDEX idx_help_chunk_search    ON help_chunk USING GIN (search);

-- Documentos cargados en /admin: el texto original, para re-fragmentar sin
-- pedirle al owner que vuelva a subirlos si cambia la regla de D8.
CREATE TABLE help_document (
  documentid  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  slug        TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  body        TEXT NOT NULL,
  isactive    BOOLEAN NOT NULL DEFAULT TRUE,
  updatedby   UUID,                    -- admin_user
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Subir un archivo con el mismo `slug` lo reemplaza: se borran sus fragmentos y
se insertan los nuevos en la MISMA transacción, así el bot nunca lee un
documento a medio actualizar. Desactivar lo saca de las búsquedas sin borrarlo.
Cada carga, reemplazo y baja queda en la auditoría de `/admin`.

⚠ `unaccent` no es `IMMUTABLE`: el `tsvector` se calcula en el servicio al
insertar, no en una columna generada.

Las migraciones de esta base van en una carpeta propia, separadas de las de los
tenants: el runner que corre al arrancar el backend apunta a la base de los
tenants y no debe tocar esta ni la otra por error.

## 5. Fases *(propuestas)*

| Fase | Qué |
|---|---|
| **R0** | Crear la base pgvector en Coolify + env vars de conexión en el backend |
| **R1** | Esquema + servicio de indexado por hash + endpoint interno + manifiesto en el build del Front |
| **R2** | Endpoint de búsqueda híbrida + tool `search_punto_help` + regla en el prompt del panel |
| **R3** | Sumarla al agente de la caja |
| **R4** | Botón Reindexar + estado en `/admin`, y registro de búsquedas sin resultado |

R4 convierte el RAG en algo que mejora: las preguntas sin respuesta son la
lista de artículos pendientes de escribir.

## 6. Arquitecturas RECHAZADAS

- **Subir desde `/admin` los artículos que YA están en git.** Dos copias del
  mismo contenido divergen: el sitio muestra una versión y el bot responde con
  otra. La carga de `/admin` es para lo que NO va al sitio. Ver D3.
- **Una regla de precedencia entre las dos fuentes.** Esconde la contradicción
  en vez de mostrarla. Ver D3.
- **Cambiar la imagen de la base de los tenants para tener pgvector.** Reinicia
  la base de producción con clientes facturando, para guardar datos que no son
  de ningún tenant. Ver D4.
- **Full-text solo, sin vectores.** Era la propuesta de la primera versión. Se
  queda como mitad de la búsqueda híbrida (D8), no como la búsqueda.
- **Driver de base de datos en el Front.** Ver D6.
- **Re-embeber todo en cada deploy.** Ver D7.
- **Mezclar modelos de embedding en el mismo índice.** Resultados sin sentido,
  sin error. Ver D5.
- **Dos burbujas flotantes.** Ver D2.
- **Dejar ganchos preparados para el CRM de soporte.** Está fuera de alcance
  (D2) y tiene su propio RAG: código de integración escrito "por las dudas"
  envejece sin que nadie lo ejercite.
- **Reusar `content/sitio/*.md`.** Contenido comercial para otro bot.
- **Inyectar la base entera en el system prompt.** Costo en cada request y
  pérdida de relevancia.
