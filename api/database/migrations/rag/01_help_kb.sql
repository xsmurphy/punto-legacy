-- 01_help_kb.sql — esquema de la base de conocimiento de Punto AI.
-- Ver context/82-base-de-conocimiento-punto-ai.md §4.
--
-- ⚠ Esta migración NO corre contra la base de los tenants. Vive en
-- `migrations/rag/` y la aplica `database/migrate_rag.php`, que apunta a la
-- base pgvector APARTE (D4). El runner de los tenants (`migrate.php`) lee
-- `migrations/postgres/` y nunca ve este archivo.
--
-- Acá no hay un solo dato de ningún comercio: es contenido global que el owner
-- carga desde /admin y que el bot le responde a cualquier tenant.

BEGIN;

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- Documento cargado desde /admin: el texto ORIGINAL, tal cual lo escribió o
-- pegó el owner. Se guarda entero para poder re-fragmentar sin pedirle que
-- vuelva a subir nada si cambia la regla de fragmentado.
CREATE TABLE IF NOT EXISTS help_document (
  documentid  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  slug        TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  body        TEXT NOT NULL,
  rubros      TEXT[] NOT NULL DEFAULT '{}',
  -- Si salió de un artículo de ayuda (D7, fase R4): su slug y el hash del
  -- contenido al momento de importarlo, para poder AVISAR que el artículo
  -- cambió en git después. Nunca se reimporta solo. R1 los deja escritos en
  -- NULL: quien los llene es la fase R4, no esta.
  fromayuda   TEXT,
  ayudahash   TEXT,
  isactive    BOOLEAN NOT NULL DEFAULT TRUE,
  -- Resultado del último indexado. `indexerror` distinto de NULL = el
  -- documento está cargado pero sus fragmentos no se pudieron generar (cayó
  -- OpenRouter, por ejemplo): la pantalla lo muestra y ofrece reintentar.
  indexedat   TIMESTAMPTZ,
  indexerror  TEXT,
  updatedby   UUID,                    -- admin_user de la base de los tenants (sin FK: otra base)
  updatedat   TIMESTAMPTZ NOT NULL DEFAULT now(),
  createdat   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Fragmento embebido. Un documento tiene N; se borran y se reinsertan enteros
-- en la misma transacción cuando el documento se reemplaza.
CREATE TABLE IF NOT EXISTS help_chunk (
  chunkid      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  documentid   UUID NOT NULL REFERENCES help_document(documentid) ON DELETE CASCADE,
  slug         TEXT NOT NULL,
  title        TEXT NOT NULL,
  headingpath  TEXT NOT NULL,
  audiencia    TEXT,
  rubros       TEXT[] NOT NULL DEFAULT '{}',   -- D10b: vacío = aplica a todos
  content      TEXT NOT NULL,
  -- Idempotencia: el hash cubre TODO lo que se embebe (título + ruta de
  -- encabezados + rubros + cuerpo), no solo el cuerpo. Si cambia el título del
  -- documento, el texto embebido cambia y el fragmento se re-embebe aunque su
  -- cuerpo sea idéntico. Único POR DOCUMENTO y no global: dos documentos
  -- distintos pueden compartir un párrafo y los dos tienen que conservarlo.
  contenthash  TEXT NOT NULL,
  model        TEXT NOT NULL,                  -- D5: nunca mezclar modelos en el mismo índice
  embedding    VECTOR(1536) NOT NULL,          -- text-embedding-3-small
  -- D8: mitad textual de la búsqueda híbrida. Se calcula en PHP al insertar,
  -- NUNCA como columna generada ni en un índice de expresión: `unaccent()` no
  -- es IMMUTABLE y Postgres rechaza las dos cosas.
  search       TSVECTOR NOT NULL,
  position     INTEGER NOT NULL DEFAULT 0,     -- orden dentro del documento
  indexedat    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT help_chunk_hash_per_document UNIQUE (documentid, contenthash)
);

CREATE INDEX IF NOT EXISTS idx_help_chunk_embedding ON help_chunk USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS idx_help_chunk_search    ON help_chunk USING GIN (search);
CREATE INDEX IF NOT EXISTS idx_help_chunk_rubros    ON help_chunk USING GIN (rubros);
CREATE INDEX IF NOT EXISTS idx_help_chunk_document  ON help_chunk (documentid);

COMMIT;
