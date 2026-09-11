-- 218_migracion_encom.sql
-- Migrador ENCOM → Punto (context/77). Tabla-cola del job + tabla de mapeo.
--
-- ── Por qué una cola y no ejecución inline ──────────────────────────────
-- Mismo motivo que el seeder de datos demo (context/65): los servicios de
-- import (`RegisterAdminService`, `ItemService`, `ContactService`) resuelven
-- el tenant por constantes de PROCESO (COMPANY_ID / OUTLET_ID), no por
-- argumento en cada método. Un import corriendo dentro de la request de
-- /admin tendría que reescribir esas constantes en caliente y le envenenaría
-- el contexto a todo lo que atienda esa misma request.
--
-- Además un export de catálogo completo son decenas de requests HTTP contra
-- el legacy PACEADAS a 60/min: minutos de pared, muy por encima de cualquier
-- timeout de PHP-FPM razonable. El endpoint encola y contesta; el worker CLI
-- que dispara el drain de `api/v1/maintenance.php` es el que trabaja.
--
-- ── Las credenciales NO se persisten (D2) ───────────────────────────────
-- El endpoint de /admin hace el login contra el legacy EN EL MOMENTO y
-- guarda acá SOLO las cookies de sesión resultantes. La password del cliente
-- nunca toca la base ni un log. Si el login falla, el job no llega a crearse.
--
-- Las cookies siguen siendo material sensible (son una sesión viva del panel
-- del cliente, TTL 24 h), así que:
--   1. viven en su propia columna, separadas del resto del estado, para que
--      borrarlas sea un UPDATE de una columna y no una cirugía sobre el JSONB
--      del progreso;
--   2. el worker las NULEA al terminar el job, salga bien o mal;
--   3. no se seleccionan nunca en los endpoints de listado/detalle — el
--      servicio expone `hascredentials` (booleano), jamás el contenido.
--
-- ── Idempotencia ────────────────────────────────────────────────────────
-- `migration_map` es la ÚNICA tabla que el importador escribe con INSERT
-- directo (D4): todo lo demás entra por los servicios reales de Punto. Su PK
-- (companyid, domain, legacyid) es lo que hace que re-correr un job no
-- duplique — antes de crear una entidad se pregunta si ese `legacyid` ya
-- tiene `puntoid` para esta empresa, y si lo tiene se saltea.
--
-- Todo lowercase sin comillas: tablas nuevas, no hay DDL legacy que respetar.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- 1. migration_job — la cola
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS migration_job (
  jobid        uuid         PRIMARY KEY DEFAULT gen_random_uuid(),

  -- Empresa DESTINO en Punto. Sin FK dura a `company` a propósito: el job es
  -- un registro de auditoría de soporte y tiene que sobrevivir a que la
  -- empresa se dé de baja. El endpoint valida que exista al crear.
  companyid    uuid         NOT NULL,

  -- De qué sistema se migra. Hoy solo 'encom', pero la tabla no se llama
  -- `encom_job`: el mecanismo (cola + mapeo + import por servicios) es el
  -- mismo para cualquier origen y renombrar tablas en prod cuesta.
  source       varchar(20)  NOT NULL DEFAULT 'encom',

  status       varchar(20)  NOT NULL DEFAULT 'pending',

  -- Qué dominios pidió el operador: ['catalog','customers','config'].
  domains      jsonb        NOT NULL DEFAULT '[]'::jsonb,

  -- Cookies efímeras del login al legacy. NULL = ya consumidas o job cerrado.
  -- Ver el bloque de arriba: nunca se devuelven por la API.
  credentials  jsonb,

  -- Progreso por dominio: {"catalog": {"total": 120, "imported": 118,
  -- "skipped": 2, "failed": 0, "phase": "items"}}.
  progress     jsonb        NOT NULL DEFAULT '{}'::jsonb,

  -- Errores por dominio, append: [{"domain":"config","message":"...",
  -- "at":"2026-09-11T12:00:00Z"}]. Un error acá NO implica status='failed':
  -- un dominio puede fallar y los otros importarse.
  errors       jsonb        NOT NULL DEFAULT '[]'::jsonb,

  -- Bitácora legible para el equipo de soporte, append.
  log          jsonb        NOT NULL DEFAULT '[]'::jsonb,

  -- admin_user que lo creó. Sin FK por la misma razón que companyid.
  createdby    uuid,

  -- Guard anti-loop del drain: un job que revienta el worker no se puede
  -- reintentar para siempre.
  attempts     smallint     NOT NULL DEFAULT 0,

  started_at   timestamptz,
  finished_at  timestamptz,
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now(),

  CONSTRAINT migration_job_status_chk
    CHECK (status IN ('pending', 'running', 'done', 'failed')),
  CONSTRAINT migration_job_attempts_chk CHECK (attempts >= 0)
);

-- El drain busca exactamente por esto: el pendiente más viejo.
CREATE INDEX IF NOT EXISTS ix_migration_job_pending
  ON migration_job (created_at)
  WHERE status = 'pending';

-- Listado de /admin: los jobs de una empresa, más nuevo primero.
CREATE INDEX IF NOT EXISTS ix_migration_job_company
  ON migration_job (companyid, created_at DESC);

-- ── Un solo job VIVO por empresa ────────────────────────────────────────
-- Dos jobs concurrentes sobre la misma empresa se pisan: los dos leen
-- `migration_map` antes de que el otro escriba y crean el mismo ítem dos
-- veces. La idempotencia protege el RE-CORRER (secuencial), no el correr en
-- paralelo. El endpoint también lo chequea y devuelve 409, pero un
-- invariante de datos no puede depender de que el chequeo de aplicación
-- siga ahí.
CREATE UNIQUE INDEX IF NOT EXISTS uq_migration_job_alive
  ON migration_job (companyid)
  WHERE status IN ('pending', 'running');

COMMENT ON TABLE migration_job IS
  'Cola de jobs de migracion desde un sistema externo (context/77). La '
  'procesa api/scripts/migration_worker.php, disparado por el drain de '
  'api/v1/maintenance.php. Un solo job vivo por empresa.';

COMMENT ON COLUMN migration_job.credentials IS
  'Cookies de sesion del legacy obtenidas al crear el job (D2: la password '
  'NUNCA se persiste). El worker las nulea al terminar. Jamas se exponen por '
  'la API: el servicio devuelve solo el booleano hascredentials.';

-- ═══════════════════════════════════════════════════════════════════════
-- 2. migration_map — identidad legacy → Punto
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS migration_map (
  companyid  uuid         NOT NULL,

  -- 'item' | 'category' | 'brand' | 'tag' | 'customer' | 'outlet' | 'register'
  domain     varchar(30)  NOT NULL,

  -- Id en el sistema de origen. `varchar` y no uuid: el legacy usa enteros
  -- autoincrementales en unas tablas y uuid en otras, y el mapeo tiene que
  -- aguantar las dos formas sin castear.
  legacyid   varchar(64)  NOT NULL,

  puntoid    uuid         NOT NULL,

  -- Qué job lo creó. Documental: sirve para leer "esta corrida trajo N
  -- items" sin tocar el progreso, y para depurar una importacion parcial.
  jobid      uuid,

  created_at timestamptz  NOT NULL DEFAULT now(),

  -- ESTA es la idempotencia. Re-correr el job vuelve a ver el mismo
  -- (empresa, dominio, id legacy) y se saltea en vez de crear un duplicado.
  PRIMARY KEY (companyid, domain, legacyid)
);

-- Vuelta inversa: "¿este item de Punto de dónde salió?". La usa el reporte
-- del job y cualquier depuración de soporte.
CREATE INDEX IF NOT EXISTS ix_migration_map_punto
  ON migration_map (companyid, domain, puntoid);

CREATE INDEX IF NOT EXISTS ix_migration_map_job
  ON migration_map (jobid);

COMMENT ON TABLE migration_map IS
  'Mapeo id-origen → id-Punto por empresa y dominio (context/77 D4). Unica '
  'tabla que el importador escribe con INSERT directo; todo lo demas entra '
  'por los servicios reales. Su PK es lo que hace idempotente al re-correr.';

COMMIT;
