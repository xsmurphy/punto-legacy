-- 233_rrhh_usuario_unico.sql
-- RRHH — una persona = UN usuario (context/83 §9.1, D2 SUPERSEDED).
--
-- ── Qué cambia y por qué ────────────────────────────────────────────────────
--
-- La mig 229 modeló `employee` como entidad PARALELA al usuario del sistema,
-- con un vínculo OPCIONAL (`userid`). El owner lo corrigió el 2026-09-18:
--
--   "No entiendo por qué está separado usuario de empleado… una misma persona
--    como usuario tiene un PIN y como empleado tiene otro PIN, ¿cuál es el
--    punto?"
--
-- Las dos cosas que la separación compraba se consiguen igual con el modelo
-- simple:
--
--   - "Hay personal que nunca toca el sistema" → es un usuario SIN PERMISOS.
--     No se le inventa una credencial: se le crea la identidad que el resto
--     del sistema ya sabe nombrar, sin rol y sin PIN.
--   - "El legajo sobrevive al egreso" → sigue siendo cierto: el egreso escribe
--     `enddate` y la fila se queda. Lo que ahora NO puede pasar es que el
--     legajo sobreviva al BORRADO del contacto, y eso es correcto: un legajo
--     sin persona no es historial de nadie.
--
-- A cambio se resuelven tres cosas que el modelo paralelo no podía:
--
--   1. UN solo PIN por persona (`contact.pinhash`). El doble PIN era la queja
--      literal del owner.
--   2. Las ventas, comisiones y la auditoría ya se atribuyen al `contact`: con
--      el legajo colgado del MISMO id, la liquidación no necesita ningún mapeo
--      entre dos identidades que podían no coincidir.
--   3. El nombre deja de estar en dos lados. Hasta hoy `employee.fullname` y
--      `contact.contactname` eran dos nombres de la misma persona que nadie
--      sincronizaba: la caja saludaba con uno y el reporte de asistencia
--      imprimía el otro.
--
-- ── `contactid` ES la clave primaria ────────────────────────────────────────
--
-- No una columna más al lado de `employeeid`. El legajo es un SATÉLITE 1:1 del
-- contacto (patrón `document_remision_transporte`, context/42): dos claves para
-- la misma fila serían dos formas de nombrarla y, tarde o temprano, dos filas.
-- Como `contact.contactid` es único en toda la base, la PK sola ya garantiza
-- "un legajo por persona por comercio" sin índice adicional.
--
-- `ON DELETE CASCADE` y no `SET NULL`: sin contacto no hay legajo posible.
--
-- ── Qué se BORRA en esta migración ──────────────────────────────────────────
--
-- Un legajo cuyo `userid` no resuelve a un `contact` type=0 del MISMO comercio
-- NO SE PUEDE MIGRAR: en el modelo nuevo esa persona no existe, no puede
-- marcar, no puede cobrar y no se le puede atribuir una venta. Se borra, con
-- sus adjuntos, su rostro y sus marcaciones (CASCADE).
--
-- Estado real de producción al escribir esto (verificado): `attendance_mark`
-- VACÍA, UN solo `employee` —de prueba, sin `userid`— y los enrolamientos
-- vencidos. O sea: en producción esto borra una fila de prueba y nada más.
--
-- Si un legajo tiene datos que el comercio quiere conservar, el camino es
-- crearle el usuario ANTES de aplicar esta migración y vincularlo.
--
-- ── Qué se va de `employee` ─────────────────────────────────────────────────
--
--   `markpinhash` — muere el segundo PIN (§9.3). El PIN es el de siempre
--                   (`contact.pinhash`) y es OPCIONAL: quien solo marca
--                   asistencia lo hace con la cara.
--   `fullname`    — la identidad es del contacto. Ver arriba.
--   `phone`       — ídem: `contact.contactphone` es el que usa el login.
--   `email`       — ídem `contact.contactemail`.
--
-- `documentnumber`, `address` y `birthdate` SE QUEDAN aunque `contact` tenga
-- columnas parecidas (`contactci`, `contactaddress`, `contactbirthday`): esas
-- son del contacto como CLIENTE, no están en ninguna pantalla de usuarios, y
-- el legajo necesita el documento con índice único por comercio para que no se
-- cargue dos veces a la misma persona. Mover esos tres es otro slice, con su
-- pantalla; duplicarlos a medias sería peor que dejarlos donde están.

SET LOCAL lock_timeout = '10s';

-- ── 1. Legajos que no se pueden migrar ──────────────────────────────────────
--
-- Se corre ANTES de tocar el schema y solo si la columna vieja todavía existe
-- (idempotencia: en una base ya migrada este bloque no hace nada).
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'employee' AND column_name = 'userid'
  ) THEN
    -- Sin usuario, con un usuario de otro comercio, o apuntando a un CONTACTO
    -- que no es del equipo (un cliente): las tres son "no hay persona".
    DELETE FROM employee e
     WHERE NOT EXISTS (
       SELECT 1 FROM contact c
        WHERE c.contactid = e.userid
          AND c.companyid = e.companyid
          AND c.type      = 0
     );

    -- Dos legajos para la misma persona. El índice único de la mig 229 era
    -- PARCIAL (solo `status = 1`), así que un legajo archivado podía compartir
    -- usuario con el vigente. Se conserva UNO, con criterio determinístico:
    -- vigente antes que archivado, sin egreso antes que egresado, y a igualdad
    -- el más reciente. Los otros se borran con lo que cuelgue de ellos.
    DELETE FROM employee e
     WHERE e.employeeid <> (
       SELECT k.employeeid
         FROM employee k
        WHERE k.userid = e.userid
        ORDER BY k.status DESC,
                 (k.enddate IS NULL) DESC,
                 k.createdat DESC,
                 k.employeeid DESC
        LIMIT 1
     );
  END IF;
END $$;

-- ── 2. `userid` pasa a ser `contactid`, y a ser la identidad ────────────────
DO $$
DECLARE
  fk_name text;
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'employee' AND column_name = 'userid'
  ) THEN
    ALTER TABLE employee RENAME COLUMN userid TO contactid;
  END IF;

  -- La FK vieja era ON DELETE SET NULL (desvincular sin tocar el legajo). En el
  -- modelo nuevo desvincular no existe: se busca por columna y no por nombre
  -- porque el nombre lo puso Postgres y sobrevivió al RENAME.
  SELECT con.conname INTO fk_name
    FROM pg_constraint con
    JOIN pg_attribute att
      ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
   WHERE con.conrelid = 'employee'::regclass
     AND con.contype  = 'f'
     AND att.attname  = 'contactid'
   LIMIT 1;

  IF fk_name IS NOT NULL THEN
    EXECUTE format('ALTER TABLE employee DROP CONSTRAINT %I', fk_name);
  END IF;
END $$;

ALTER TABLE employee ALTER COLUMN contactid SET NOT NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conname = 'employee_contactid_fkey' AND conrelid = 'employee'::regclass
  ) THEN
    ALTER TABLE employee
      ADD CONSTRAINT employee_contactid_fkey
      FOREIGN KEY (contactid) REFERENCES contact(contactid) ON DELETE CASCADE;
  END IF;
END $$;

-- ── 3. Los satélites del legajo apuntan a la persona ────────────────────────
--
-- `attendance_mark`, `employee_face`, `employee_face_enrollment` y
-- `employee_attachment` referencian hoy `employee(employeeid)`. Se les agrega
-- `contactid`, se rellena desde el legajo y se tira la columna vieja.
--
-- El orden importa: mientras exista una FK contra `employeeid` no se puede
-- sacar la PK de `employee`.
ALTER TABLE attendance_mark            ADD COLUMN IF NOT EXISTS contactid UUID;
ALTER TABLE employee_face              ADD COLUMN IF NOT EXISTS contactid UUID;
ALTER TABLE employee_face_enrollment   ADD COLUMN IF NOT EXISTS contactid UUID;
ALTER TABLE employee_attachment        ADD COLUMN IF NOT EXISTS contactid UUID;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'employee' AND column_name = 'employeeid'
  ) THEN
    UPDATE attendance_mark m
       SET contactid = e.contactid
      FROM employee e
     WHERE e.employeeid = m.employeeid AND m.contactid IS NULL;

    UPDATE employee_face f
       SET contactid = e.contactid
      FROM employee e
     WHERE e.employeeid = f.employeeid AND f.contactid IS NULL;

    UPDATE employee_face_enrollment n
       SET contactid = e.contactid
      FROM employee e
     WHERE e.employeeid = n.employeeid AND n.contactid IS NULL;

    UPDATE employee_attachment a
       SET contactid = e.contactid
      FROM employee e
     WHERE e.employeeid = a.employeeid AND a.contactid IS NULL;
  END IF;
END $$;

-- Huérfanas: filas cuyo legajo se borró en el paso 1 y que el CASCADE no
-- alcanzó porque la FK vieja ya no estaba, o que nunca tuvieron legajo.
DELETE FROM attendance_mark          WHERE contactid IS NULL;
DELETE FROM employee_face            WHERE contactid IS NULL;
DELETE FROM employee_face_enrollment WHERE contactid IS NULL;
DELETE FROM employee_attachment      WHERE contactid IS NULL;

ALTER TABLE attendance_mark          ALTER COLUMN contactid SET NOT NULL;
ALTER TABLE employee_face            ALTER COLUMN contactid SET NOT NULL;
ALTER TABLE employee_face_enrollment ALTER COLUMN contactid SET NOT NULL;
ALTER TABLE employee_attachment      ALTER COLUMN contactid SET NOT NULL;

-- La columna vieja se va con sus FKs y sus índices.
ALTER TABLE attendance_mark          DROP COLUMN IF EXISTS employeeid;
ALTER TABLE employee_face            DROP COLUMN IF EXISTS employeeid;
ALTER TABLE employee_face_enrollment DROP COLUMN IF EXISTS employeeid;
ALTER TABLE employee_attachment      DROP COLUMN IF EXISTS employeeid;

-- ── 4. `employee`: la PK pasa a ser la persona ──────────────────────────────
ALTER TABLE employee DROP COLUMN IF EXISTS employeeid;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conrelid = 'employee'::regclass AND contype = 'p'
  ) THEN
    ALTER TABLE employee ADD PRIMARY KEY (contactid);
  END IF;
END $$;

-- El único por usuario de la mig 229 lo reemplaza la PK, que además cubre a los
-- archivados (el índice viejo era parcial y dejaba entrar un segundo legajo).
DROP INDEX IF EXISTS uidx_employee_user;

-- ── 5. Muere el segundo PIN (§9.3) ──────────────────────────────────────────
--
-- Un solo PIN por persona: el de `contact.pinhash`, el mismo del lockscreen. Y
-- es OPCIONAL — quien solo marca asistencia usa la cara. Sin rostro Y sin PIN
-- no se puede marcar, y el reloj lo dice en una línea.
DROP INDEX IF EXISTS uidx_employee_markpin;
ALTER TABLE employee DROP COLUMN IF EXISTS markpinhash;

-- ── 6. La identidad vive en `contact` ───────────────────────────────────────
ALTER TABLE employee DROP COLUMN IF EXISTS fullname;
ALTER TABLE employee DROP COLUMN IF EXISTS phone;
ALTER TABLE employee DROP COLUMN IF EXISTS email;

-- Ordenaba por `fullname`, que ya no existe. El orden del listado sale ahora de
-- `contact.contactname` en el JOIN.
DROP INDEX IF EXISTS idx_employee_list;
CREATE INDEX IF NOT EXISTS idx_employee_list ON employee (companyid, status);

-- ── 7. FKs de los satélites, ahora contra la persona ────────────────────────
--
-- `attendance_mark` referencia `contact` y NO `employee`: una marcación es un
-- hecho sobre una PERSONA. Si mañana se borra el legajo (que hoy nunca se borra
-- —el egreso escribe una fecha— pero podría), las horas trabajadas que alimentan
-- la liquidación no tienen por qué desaparecer con él.
--
-- El rostro y su habilitación, al revés, cuelgan del LEGAJO: el consentimiento
-- biométrico es una columna de `employee`, así que borrar el legajo tiene que
-- borrar la biometría en el mismo movimiento (D5). Lo mismo los adjuntos, que
-- son la documentación de esa relación laboral.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'attendance_mark_contactid_fkey') THEN
    ALTER TABLE attendance_mark
      ADD CONSTRAINT attendance_mark_contactid_fkey
      FOREIGN KEY (contactid) REFERENCES contact(contactid) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'employee_face_contactid_fkey') THEN
    ALTER TABLE employee_face
      ADD CONSTRAINT employee_face_contactid_fkey
      FOREIGN KEY (contactid) REFERENCES employee(contactid) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'employee_face_enrollment_contactid_fkey') THEN
    ALTER TABLE employee_face_enrollment
      ADD CONSTRAINT employee_face_enrollment_contactid_fkey
      FOREIGN KEY (contactid) REFERENCES employee(contactid) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'employee_attachment_contactid_fkey') THEN
    ALTER TABLE employee_attachment
      ADD CONSTRAINT employee_attachment_contactid_fkey
      FOREIGN KEY (contactid) REFERENCES employee(contactid) ON DELETE CASCADE;
  END IF;
END $$;

-- ── 8. Índices y claves de los satélites ────────────────────────────────────
--
-- `employee_face_enrollment` tenía PK (companyid, employeeid). Se rehace sobre
-- la persona: una sola habilitación abierta por legajo, que es lo que hace que
-- el `ON CONFLICT` del service reemplace en vez de acumular ventanas.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conrelid = 'employee_face_enrollment'::regclass AND contype = 'p'
  ) THEN
    ALTER TABLE employee_face_enrollment ADD PRIMARY KEY (companyid, contactid);
  END IF;
END $$;

-- Un vector por persona y modelo (era (companyid, employeeid, modelversion)).
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_face
    ON employee_face (companyid, contactid, modelversion);

CREATE INDEX IF NOT EXISTS idx_attendance_mark_contact
    ON attendance_mark (companyid, contactid, markedat);

CREATE INDEX IF NOT EXISTS idx_employee_attachment_contact
    ON employee_attachment (companyid, contactid, createdat DESC);

-- Los que nombraban la columna vieja quedaron sin sujeto.
DROP INDEX IF EXISTS idx_attendance_mark_employee;
DROP INDEX IF EXISTS idx_employee_attachment_employee;

-- ── 9. Comentarios ──────────────────────────────────────────────────────────
COMMENT ON TABLE employee IS
  'Legajo del empleado (context/83 §9.1). SATÉLITE 1:1 del usuario del sistema '
  '(contact type=0): una persona = un usuario = un PIN. El personal que no opera '
  'Punto es un usuario SIN PERMISOS, no una entidad aparte.';
COMMENT ON COLUMN employee.contactid IS
  'El usuario del sistema. Es la identidad y la clave primaria: nombre, teléfono '
  'y email salen de `contact`, no se copian acá.';
COMMENT ON COLUMN employee.documentnumber IS
  'Documento del legajo, con único por comercio. Se queda acá y no en '
  '`contact.contactci` porque ese campo es del contacto como cliente.';
COMMENT ON TABLE attendance_mark IS
  'Marcación de asistencia (context/83 F1). Fila INMUTABLE por marcación, '
  'atribuida a la PERSONA (`contactid`): sobrevive al borrado del legajo.';
