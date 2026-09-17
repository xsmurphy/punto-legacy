-- 230_rrhh_marcacion.sql
-- RRHH F1 — marcación de asistencia desde el quiosco del comercio
-- (context/83 §4, §7).
--
-- ── Qué reemplaza ───────────────────────────────────────────────────────────
--
-- La tabla `attendance` del legacy (schema base, no una migración) guardaba
-- PARES abierto/cerrado en una fila: `attendanceopendate` +
-- `attendanceclosedate`, y el fichaje era un TOGGLE. Ese modelo no sobrevive a
-- la marcación offline:
--
--   a) Una fila que se abre y se cierra en dos momentos distintos exige que el
--      segundo momento ENCUENTRE al primero. Con dos tablets marcando y una
--      cola que sincroniza horas después, el cierre puede llegar antes que su
--      apertura y "cerrar" el turno de ayer.
--   b) Un toggle no tiene identidad: reenviar el mismo fichaje tras un timeout
--      abre o cierra otro turno según el estado en que lo encuentre. La cola de
--      operaciones del POS exige lo contrario (idempotencia por `opId`).
--
-- Acá cada marcación es una FILA INMUTABLE con su tipo explícito ('in'/'out'),
-- y el par entrada/salida se arma al LEER (el reporte los aparea por empleado y
-- día). Un hecho que ya ocurrió no se edita; a lo sumo se revisa.
--
-- La tabla `attendance` legacy NO se dropea: es el registro de lo que los
-- comercios ficharon con el QR. Queda sin escritores (su endpoint y su service
-- se eliminan con esta fase) y sin lectores nuevos.
--
-- ── `markedat` es el momento en que la persona marcó ────────────────────────
--
-- No el momento en que el servidor recibió la fila. Es la misma regla que
-- gobierna `transaction.transactionDate` y las fechas de caja, y acá es
-- INELUDIBLE: la marcación es offline-nativa (D7) y una tablet sin red puede
-- sincronizar al día siguiente. Con la hora del guardado, la entrada de las
-- 08:00 del lunes se registraría el martes a las 11:00 y todo el cálculo de
-- horas y tardanzas quedaría inventado.
--
-- `receivedat` guarda la otra fecha —cuándo llegó— porque la diferencia entre
-- las dos es lo único que permite explicar una marcación que apareció tarde.
--
-- ── `method`: 'pin' hoy, 'face' en la F2 ────────────────────────────────────
--
-- La F1 identifica por PIN. El reconocimiento facial (F2) NO agrega una tabla
-- ni una columna: agrega un valor a este CHECK. La foto del momento —que la F1
-- ya guarda SIEMPRE— es la misma evidencia en los dos casos, así que el modelo
-- de datos no cambia cuando llegue.
--
-- ── Fail-open, con la marca puesta (D4) ─────────────────────────────────────
--
-- `needsreview` + `reviewreason` existen para que la marcación ENTRE cuando
-- algo no salió como debía —no había cámara, el permiso estaba denegado, el
-- PIN cambió mientras el device estaba sin red— en vez de rechazarla. Un
-- empleado legítimo nunca se queda sin poder marcar; el problema queda visible
-- para el dueño, que es quien puede resolverlo. Mismo principio que la venta
-- offline.

SET LOCAL lock_timeout = '10s';

-- ── PIN de marcación, propio del EMPLEADO ───────────────────────────────────
--
-- No se reusa `contact.pinhash` (el PIN del lockscreen) por la misma razón por
-- la que `employee` no es un flag en `contact`: hay personal que no tiene
-- usuario del sistema —cocina, limpieza— y necesita marcar igual. Inventarle
-- una credencial de sistema para que pueda fichar sería crear un login que
-- nadie va a usar y que hay que acordarse de desactivar.
--
-- SHA-256 sin sal de 4 dígitos, EXACTAMENTE como `contact.pinhash` (ver
-- `unlock-pin.php` y `UsersService::rosterForOutlet()`). No es un descuido
-- criptográfico que se copia: es el requisito. El quiosco valida el PIN SIN
-- RED contra el hash que bajó en el bootstrap, igual que el lockscreen, y eso
-- solo funciona si el hash es determinístico. Una sal por empleado obligaría a
-- bajar la sal también, que es el mismo secreto con un paso más.
--
-- Qué protege entonces: que el PIN no viaje ni se guarde en claro. Qué NO
-- protege: a alguien con el hash y ganas de probar 10.000 combinaciones — por
-- eso la evidencia real de la marcación es la FOTO, no el PIN.
ALTER TABLE employee ADD COLUMN IF NOT EXISTS markpinhash CHAR(64);

COMMENT ON COLUMN employee.markpinhash IS
  'SHA-256 del PIN de marcación (4 dígitos). Mismo esquema que contact.pinhash '
  'porque el quiosco lo valida sin red contra el hash del bootstrap.';

-- Único por comercio entre los VIGENTES: dos empleados con el mismo PIN
-- harían que la marcación no supiera a quién atribuirse, y resolverlo "por el
-- primero que matchea" es cómo se le acreditan horas a otra persona.
--
-- Parcial sobre activos y no egresados: el legajo es historial y sobrevive al
-- egreso (mig 229), así que el PIN de alguien que se fue hace dos años no
-- puede bloquear el alta de quien entra hoy. El hash del egresado se queda en
-- su fila —no hay motivo para borrarlo— pero deja de competir por el espacio
-- de 10.000 códigos.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_markpin
    ON employee (companyid, markpinhash)
 WHERE markpinhash IS NOT NULL AND status = 1 AND enddate IS NULL;

-- ── Horario declarado ───────────────────────────────────────────────────────
--
-- JSONB y no columnas ni tabla hija: es la DECLARACIÓN del comercio sobre un
-- empleado ("entra 8, sale 17, de lunes a viernes"), se lee entera o no se lee,
-- y nunca se consulta por pedazos. Una tabla `employee_schedule` con siete
-- filas por persona sería siete filas para leer un objeto que siempre viaja
-- junto.
--
-- Forma:
--   { "days": { "mon": { "in": "08:00", "out": "17:00" }, ... },
--     "toleranceMinutes": 10 }
--
-- Un día ausente (o en null) = NO laborable. NULL en la columna = sin horario
-- declarado, y entonces NO HAY TARDANZA que calcular: el reporte informa las
-- horas y se calla sobre la puntualidad, en vez de inventar un horario
-- estándar y acusar a alguien de llegar tarde contra una regla que nadie
-- escribió.
ALTER TABLE employee ADD COLUMN IF NOT EXISTS schedule JSONB;

COMMENT ON COLUMN employee.schedule IS
  'Horario declarado: { days: { mon..sun: {in,out} }, toleranceMinutes }. '
  'NULL = sin horario: el reporte no calcula tardanzas para esta persona.';


-- ── La marcación ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS attendance_mark (
  markid        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid     UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  -- CASCADE y no SET NULL, al revés que casi todo lo demás: una marcación sin
  -- empleado no es un dato incompleto, es un dato sin sentido (no se le pueden
  -- sumar horas a nadie). Y `employee` no se borra en la operación normal —
  -- el egreso escribe una fecha y la fila se queda.
  employeeid    UUID          NOT NULL REFERENCES employee(employeeid) ON DELETE CASCADE,
  -- Dónde se marcó. SET NULL: si se elimina la sucursal, la marcación sigue
  -- siendo cierta.
  outletid      UUID          REFERENCES outlet(outletId) ON DELETE SET NULL,
  -- Qué aparato del comercio tomó la marcación. Sin FK (mismo criterio que
  -- `device.userid`): el device se puede revocar y borrar, y la marcación es
  -- anterior e independiente de eso.
  registerid    UUID,
  deviceid      UUID,

  -- 'in' = entrada, 'out' = salida. Explícito y no derivado del estado
  -- anterior: ver el docblock de arriba sobre por qué el toggle no sobrevive
  -- al offline.
  kind          VARCHAR(4)    NOT NULL CHECK (kind IN ('in', 'out')),

  -- El momento en que la persona marcó (hora del tenant). NO el del guardado.
  markedat      TIMESTAMPTZ   NOT NULL,
  -- Cuándo llegó al servidor. Sirve para explicar una marcación que apareció
  -- con horas de atraso — y para nada más: ningún cálculo la usa.
  receivedat    TIMESTAMPTZ   NOT NULL DEFAULT now(),

  -- 'pin' hoy; 'face' cuando llegue la F2. Ver el docblock.
  method        VARCHAR(8)    NOT NULL DEFAULT 'pin' CHECK (method IN ('pin', 'face')),

  -- Clave del objeto S3 con la foto del momento. PRIVADO, igual que los
  -- adjuntos del legajo (mig 229): es la cara de una persona. Se guarda la
  -- clave y NUNCA una URL — la de un objeto privado caduca.
  --
  -- NULL = la marcación entró sin foto, y entonces `needsreview` es true con
  -- su motivo. Es un caso esperado, no una inconsistencia (D4: la foto se
  -- intenta SIEMPRE, pero su ausencia jamás bloquea).
  photokey      TEXT,

  -- Fail-open con la marca puesta. `reviewreason` es un CÓDIGO, no un texto
  -- para mostrar: la redacción vive en el front, donde se puede cambiar sin
  -- migrar filas.
  needsreview   BOOLEAN       NOT NULL DEFAULT FALSE,
  reviewreason  VARCHAR(32),
  reviewedat    TIMESTAMPTZ,
  reviewedby    UUID,

  -- Identidad de la operación, puesta por el CLIENTE (`X-Punto-Op-Id`). Es la
  -- clave de idempotencia de la cola del POS: la misma marcación reenviada tras
  -- un timeout tiene que encontrar su propia fila, no crear una segunda
  -- entrada a la misma hora. Ver `pending-ops-transport.ts`.
  opid          VARCHAR(64)   NOT NULL,

  createdat     TIMESTAMPTZ   NOT NULL DEFAULT now(),

  -- Una marcación revisada tiene que decir cuándo la revisaron. Al revés no:
  -- `reviewedat` sin `needsreview` es el estado normal de una revisada (el
  -- flag se apaga al revisar), así que solo se prohíbe la mitad que no tiene
  -- lectura posible.
  CONSTRAINT attendance_mark_review_chk
    CHECK (needsreview = FALSE OR reviewedat IS NULL)
);

COMMENT ON TABLE attendance_mark IS
  'Marcación de asistencia (context/83 F1). Fila INMUTABLE por marcación: el '
  'par entrada/salida se arma al leer. Reemplaza el toggle de `attendance`.';
COMMENT ON COLUMN attendance_mark.markedat IS
  'Momento en que la persona marcó, NO el del guardado: la marcación es offline-nativa.';
COMMENT ON COLUMN attendance_mark.opid IS
  'Identidad puesta por el cliente. Idempotencia de la cola del POS: un reenvío no duplica.';

-- Idempotencia. El reenvío de una marcación encolada dos veces encuentra su
-- propia fila (INSERT ... ON CONFLICT DO NOTHING + relectura por este índice)
-- en vez de registrar una segunda entrada a la misma hora.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_attendance_mark_op
    ON attendance_mark (companyid, opid);

-- El reporte: rango de fechas por comercio, y el detalle por empleado dentro
-- de ese rango. El orden por `markedat` es el del apareo entrada/salida.
CREATE INDEX IF NOT EXISTS idx_attendance_mark_range
    ON attendance_mark (companyid, markedat);
CREATE INDEX IF NOT EXISTS idx_attendance_mark_employee
    ON attendance_mark (companyid, employeeid, markedat);

-- "Qué quedó por revisar" es una pregunta propia del dueño y se hace sobre
-- pocas filas: índice parcial, que no paga el costo en las que están bien.
CREATE INDEX IF NOT EXISTS idx_attendance_mark_review
    ON attendance_mark (companyid, markedat)
 WHERE needsreview = TRUE;
