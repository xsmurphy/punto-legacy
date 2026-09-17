-- 231_rrhh_face.sql
-- RRHH F2 — reconocimiento facial en el quiosco (context/83 §4, D4 y D5).
--
-- ── Qué se guarda, y qué NO ────────────────────────────────────────────────
--
-- Se guarda un VECTOR: la lista de números que un modelo local produjo al mirar
-- la cara. No es la foto y de él no se recupera la foto. Sirve para una sola
-- cosa: comparar contra otro vector calculado del mismo modo y decir cuánto se
-- parecen.
--
-- Y se guarda la foto de enrolamiento, privada en S3, por la misma razón por la
-- que el legajo guarda el contrato: cuando el dueño revisa una marcación
-- flageada necesita ver contra QUIÉN se estaba comparando. Sin ella, "no
-- reconoció" es una afirmación que nadie puede auditar.
--
-- Biometría: dato sensible. Nunca sale a un tercero (el modelo corre en el
-- navegador del dispositivo, D5), viaja solo entre el comercio y su propio
-- quiosco, y se BORRA al egreso — lo hace `EmployeeService::terminate()`, no un
-- job: el borrado es parte del egreso, no una tarea que puede quedar pendiente.
--
-- ── `modelversion` no es metadata: es parte de la identidad del vector ─────
--
-- Dos modelos distintos producen vectores que NO son comparables entre sí, y lo
-- peor es que la comparación no falla: devuelve un número. Un vector de otro
-- modelo mezclado en la misma bolsa da distancias sin sentido — que es como se
-- le acredita la entrada de una persona a otra.
--
-- Por eso la versión del modelo viaja EN LA FILA y el cliente pide solo las de
-- su propia versión. Es el mismo principio con el que el RAG fija un modelo de
-- embeddings por índice (context/82): no se mezcla, y no se confía en que nadie
-- lo mezcle por accidente.
--
-- El UNIQUE es por (empleado, versión) y no por empleado: re-enrolar con el
-- mismo modelo REEMPLAZA el vector anterior (nadie tiene dos caras), pero
-- cambiar de modelo tiene que poder convivir con el anterior mientras los
-- dispositivos se actualizan. Sin eso, el primer quiosco que enrola con el
-- modelo nuevo deja ciegos a todos los que todavía corren el viejo.

SET LOCAL lock_timeout = '10s';

CREATE TABLE IF NOT EXISTS employee_face (
  faceid        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid     UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  -- CASCADE: un vector sin empleado no identifica a nadie. No es un dato
  -- incompleto, es un dato sin sujeto.
  employeeid    UUID          NOT NULL REFERENCES employee(employeeid) ON DELETE CASCADE,

  -- El vector, como array JSON de números. `jsonb` y no un `float8[]` de
  -- Postgres ni pgvector:
  --
  --   - Acá NO se busca por similitud en SQL. La comparación corre en el
  --     dispositivo, sin red (D5), así que esta columna es TRANSPORTE: se lee
  --     entera, se manda al quiosco y se compara allá. Un tipo indexable para
  --     búsqueda vectorial resolvería un problema que este diseño no tiene.
  --   - pgvector no está instalado en la base de los tenants, y la base del RAG
  --     que sí lo tiene es OTRA a propósito (context/82 D4).
  embedding     JSONB         NOT NULL,

  -- Cuál modelo produjo el vector. Ver el docblock: no es metadata.
  modelversion  VARCHAR(40)   NOT NULL,

  -- Cuántas capturas se promediaron. Sirve para leer la calidad del
  -- enrolamiento cuando algo no reconoce: tres tomas iguales de frente rinden
  -- distinto que cinco con la persona moviéndose.
  samples       SMALLINT      NOT NULL DEFAULT 1 CHECK (samples > 0),

  -- Clave del objeto S3 con la foto de enrolamiento. PRIVADA, igual que los
  -- adjuntos del legajo y las fotos de marcación (migs 229/230). Se guarda la
  -- clave y NUNCA una URL: la de un objeto privado caduca.
  photokey      TEXT,

  -- Quién autorizó el enrolamiento (el usuario del panel que lo activó), no el
  -- dispositivo que capturó. El enrolamiento es un acto AUTORIZADO: lo que
  -- importa registrar es quién lo autorizó.
  createdby     UUID,
  createdat     TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedat     TIMESTAMPTZ   NOT NULL DEFAULT now(),

  -- Un vector vacío o escalar no es un vector. El largo exacto lo valida el
  -- service contra el modelo declarado — acá solo se rechaza lo que ni siquiera
  -- tiene forma de lista.
  CONSTRAINT employee_face_embedding_chk
    CHECK (jsonb_typeof(embedding) = 'array' AND jsonb_array_length(embedding) > 0)
);

COMMENT ON TABLE employee_face IS
  'Vector facial del empleado (context/83 F2, D5). Biometría: se borra al egreso '
  'y NUNCA sale a un tercero — la comparación corre en el dispositivo.';
COMMENT ON COLUMN employee_face.embedding IS
  'Array de números producido por el modelo local. No es la foto ni permite reconstruirla.';
COMMENT ON COLUMN employee_face.modelversion IS
  'Modelo que produjo el vector. Vectores de modelos distintos NO son comparables: '
  'la comparación no falla, devuelve un número sin sentido.';

-- Una cara por persona y por modelo. Re-enrolar pisa; cambiar de modelo
-- convive. Ver el docblock.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_face
    ON employee_face (companyid, employeeid, modelversion);

-- La lectura del quiosco: todas las caras vigentes de su sucursal para SU
-- versión de modelo. Es la única consulta caliente de esta tabla.
CREATE INDEX IF NOT EXISTS idx_employee_face_model
    ON employee_face (companyid, modelversion);


-- ── El permiso para enrolar, con fecha de vencimiento ───────────────────────
--
-- D5 del brief, y es lo que separa esta feature de reconstruir el problema que
-- vino a resolver: **el enrolamiento no es self-service**. Si quien sabe un PIN
-- pudiera registrar su propia cara bajo el nombre de otro, el buddy punching
-- vuelve con más pasos y con mejor coartada.
--
-- Entonces: alguien con permiso sobre el legajo ABRE el enrolamiento de UNA
-- persona desde el panel, y recién ahí el quiosco de esa sucursal ofrece
-- capturar — para ESA persona y por un rato corto. Es la misma forma que ya
-- tiene la invitación de pareo de un dispositivo: un permiso acotado, con
-- vencimiento, que se consume.
--
-- PK (companyid, employeeid): una sola apertura viva por persona. Volver a
-- abrirla renueva la que había en vez de acumular aperturas que después nadie
-- cierra.
--
-- La fila se BORRA al consumirse. No queda un registro de "enrolamientos
-- pedidos" porque el hecho que importa ya está en `employee_face` (con su fecha
-- y su autor): esta tabla es un permiso en curso, no un historial.

CREATE TABLE IF NOT EXISTS employee_face_enrollment (
  companyid     UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  employeeid    UUID          NOT NULL REFERENCES employee(employeeid) ON DELETE CASCADE,
  -- Dónde puede capturarse. Sale de la sucursal del legajo al abrir, no del
  -- dispositivo: el quiosco no elige a quién enrola.
  --
  -- NULL = cualquier quiosco del comercio, que es lo correcto para el personal
  -- sin sucursal asignada (el dueño, quien rota). Es el mismo criterio con el
  -- que `rosterForOutlet()` decide quién puede marcar dónde.
  outletid      UUID          REFERENCES outlet(outletId) ON DELETE SET NULL,
  expiresat     TIMESTAMPTZ   NOT NULL,
  createdby     UUID,
  createdat     TIMESTAMPTZ   NOT NULL DEFAULT now(),

  PRIMARY KEY (companyid, employeeid)
);

COMMENT ON TABLE employee_face_enrollment IS
  'Permiso VIGENTE para capturar el rostro de una persona en el quiosco '
  '(context/83 F2). El enrolamiento NO es self-service: lo abre el panel y vence solo.';
COMMENT ON COLUMN employee_face_enrollment.expiresat IS
  'Vencimiento corto. Una apertura olvidada deja de ofrecerse sola, sin que nadie la cierre.';

-- Lo que pregunta el quiosco: "¿hay alguien para enrolar acá, ahora?".
CREATE INDEX IF NOT EXISTS idx_employee_face_enrollment_open
    ON employee_face_enrollment (companyid, expiresat);
