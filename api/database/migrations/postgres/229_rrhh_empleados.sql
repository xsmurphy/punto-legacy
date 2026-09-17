-- 229_rrhh_empleados.sql
-- RRHH F0 — legajo del empleado (context/83 §3, §7).
--
-- ── Por qué una tabla propia y no un flag en `contact` ──────────────────────
--
-- D2 del plan, cerrada por el owner. Los usuarios del sistema YA son
-- `contact` con type=0 (credencial: PIN, rol, permisos, sucursales). Un
-- EMPLEADO es otra cosa: una relación laboral con fechas, que
--
--   a) existe para gente que nunca toca el sistema (cocina, limpieza) y que
--      no debe tener login inventado solo para figurar en el legajo, y
--   b) SOBREVIVE al egreso y a la desactivación del usuario — el legajo es
--      historial laboral, no una sesión.
--
-- De ahí el vínculo OPCIONAL `userid`: apunta al `contact` type=0 cuando esa
-- persona además opera el sistema. `ON DELETE SET NULL` es la mitad
-- importante: desvincular (o borrar) al usuario NO toca el legajo, que es
-- justamente lo que pide la D2.
--
-- ── Dos fechas y un estado, que NO son lo mismo ─────────────────────────────
--
--   `enddate`  = EGRESO. La relación laboral terminó. La fila se queda: es
--                el historial. NULL = activo.
--   `status`   = archivado (0) / vigente (1), patrón `contact.contactstatus`.
--                Es para la fila cargada por error, no para el que se fue.
--
-- Mezclarlas haría que dar de baja a alguien borre su historial, que es
-- exactamente lo que el plan NO quiere.
--
-- ── Remuneración: tres campos, no un enum ───────────────────────────────────
--
-- D1/§2: fijo, por hora y comisión CONVIVEN y se combinan (base fija +
-- comisión es el caso típico de un vendedor). Por eso son columnas
-- independientes y no un `scheme` excluyente: un enum obligaría a inventar
-- valores compuestos ('fixed_plus_commission'…) y a migrarlos cada vez que
-- aparezca otra combinación.
--
-- `commissions` es solo el INTERRUPTOR de "esta persona comisiona". El
-- TARIFARIO (regla por producto/categoría, override por empleado — D10) es
-- otra fase y otras tablas: acá no se modela, ni siquiera parcialmente.
--
-- ── Consentimiento biométrico ───────────────────────────────────────────────
--
-- Columnas presentes desde F0 y sin uso todavía: la biometría llega en la F2,
-- pero el consentimiento es un dato del LEGAJO (D5) y su lugar es esta tabla.
-- Nacen NULL = sin consentimiento registrado.

SET LOCAL lock_timeout = '10s';

CREATE TABLE IF NOT EXISTS employee (
  employeeid        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  -- Vínculo OPCIONAL al usuario del sistema (contact type=0). Ver arriba.
  userid            UUID          REFERENCES contact(contactid) ON DELETE SET NULL,
  -- Sucursal principal. SET NULL y no CASCADE: si se elimina una sucursal, el
  -- legajo de quien trabajaba ahí no se borra.
  outletid          UUID          REFERENCES outlet(outletId) ON DELETE SET NULL,

  -- ── Datos personales ────────────────────────────────────────────────────
  fullname          TEXT          NOT NULL CHECK (btrim(fullname) <> ''),
  documentnumber    VARCHAR(40),
  phone             VARCHAR(32),
  email             VARCHAR(255),
  address           TEXT,
  birthdate         DATE,

  -- ── Relación laboral ────────────────────────────────────────────────────
  jobtitle          VARCHAR(120),
  hiredate          DATE          NOT NULL,
  -- NULL = activo. Setearla ES el egreso.
  enddate           DATE,
  endreason         TEXT,

  -- ── Remuneración (los tres combinables, ver arriba) ─────────────────────
  fixedamount       NUMERIC(15,2),
  fixedperiod       VARCHAR(10)   CHECK (fixedperiod IS NULL
                                         OR fixedperiod IN ('monthly','biweekly','weekly')),
  hourlyrate        NUMERIC(15,2),
  commissions       BOOLEAN       NOT NULL DEFAULT FALSE,

  notes             TEXT,

  -- ── Consentimiento biométrico (se usa en F2) ────────────────────────────
  biometricconsentat TIMESTAMPTZ,
  biometricconsentby UUID,

  status            SMALLINT      NOT NULL DEFAULT 1 CHECK (status IN (0, 1)),
  createdby         UUID,
  updatedby         UUID,
  createdat         TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedat         TIMESTAMPTZ   NOT NULL DEFAULT now(),

  -- El monto fijo y su periodicidad son UN dato en dos columnas: un monto sin
  -- período no se puede liquidar y un período sin monto no dice nada.
  CONSTRAINT employee_fixed_pair_chk
    CHECK ((fixedamount IS NULL) = (fixedperiod IS NULL)),
  CONSTRAINT employee_fixed_amount_chk
    CHECK (fixedamount IS NULL OR fixedamount >= 0),
  CONSTRAINT employee_hourly_rate_chk
    CHECK (hourlyrate IS NULL OR hourlyrate >= 0),
  -- Un egreso anterior al ingreso es un error de carga, no un caso de borde.
  CONSTRAINT employee_dates_chk
    CHECK (enddate IS NULL OR enddate >= hiredate)
);

COMMENT ON TABLE employee IS
  'Legajo del empleado (context/83 F0). Relación laboral, no credencial: '
  'sobrevive al egreso y al borrado del usuario vinculado.';
COMMENT ON COLUMN employee.userid IS
  'Contacto type=0 (usuario del sistema) si esta persona además opera Punto. '
  'NULL para personal que nunca entra al sistema.';
COMMENT ON COLUMN employee.enddate IS
  'Fecha de egreso. NULL = activo. Setearla NO archiva la fila: el legajo es historial.';
COMMENT ON COLUMN employee.commissions IS
  'Interruptor de "comisiona". El tarifario de comisiones (D10) es otra fase.';

-- Un usuario del sistema no puede tener dos legajos vigentes en el mismo
-- comercio: si los tuviera, la marcación y la liquidación no sabrían a cuál
-- imputar las horas. Parcial sobre los archivados a propósito — una fila
-- archivada (cargada por error) no debe bloquear la carga correcta.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_user
    ON employee (companyid, userid)
 WHERE userid IS NOT NULL AND status = 1;

-- Mismo criterio para el documento: dos legajos con la misma cédula son el
-- mismo empleado cargado dos veces.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_document
    ON employee (companyid, lower(btrim(documentnumber)))
 WHERE documentnumber IS NOT NULL AND btrim(documentnumber) <> '' AND status = 1;

-- Listado del panel: el tenant, el filtro de archivados y el orden por nombre.
CREATE INDEX IF NOT EXISTS idx_employee_list
    ON employee (companyid, status, fullname);

-- "Quién está activo hoy" y el filtro activos/egresados del listado.
CREATE INDEX IF NOT EXISTS idx_employee_active
    ON employee (companyid, enddate)
 WHERE status = 1;


-- ── Adjuntos del legajo (contrato, cédula) ──────────────────────────────────
--
-- PRIVADOS, no `publicRead`. El resto del proyecto sube a S3 con ACL pública
-- (imágenes de ítems, logo del comercio) porque son cosas que el comercio
-- muestra; el contrato y la cédula de un empleado son datos personales de un
-- tercero y una URL pública adivinable los expone sin sesión. Se guardan con
-- ACL privada y se bajan por GET firmado desde el endpoint, mismo mecanismo
-- que el archivo del XML fiscal (`EInvoiceService::fiscalStorage()`).
--
-- Por eso se guarda el `objectkey` y NO una URL: la URL de un objeto privado
-- caduca, así que persistirla sería guardar algo que deja de servir.

CREATE TABLE IF NOT EXISTS employee_attachment (
  attachmentid  UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid     UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  employeeid    UUID          NOT NULL REFERENCES employee(employeeid) ON DELETE CASCADE,
  -- Clave del objeto en S3. Ver arriba: no se guarda URL.
  objectkey      TEXT          NOT NULL,
  filename       TEXT          NOT NULL,
  mime           VARCHAR(120)  NOT NULL,
  sizebytes      INTEGER       NOT NULL CHECK (sizebytes > 0),
  -- Etiqueta libre del comercio ("Contrato", "Cédula"). Sin enum: cada rubro
  -- archiva lo suyo y una lista cerrada obliga a migrar por cada papel nuevo.
  label          VARCHAR(120),
  createdby      UUID,
  createdat      TIMESTAMPTZ   NOT NULL DEFAULT now()
);

COMMENT ON TABLE employee_attachment IS
  'Adjuntos del legajo (context/83 F0). Objetos S3 PRIVADOS: se bajan por GET firmado.';
COMMENT ON COLUMN employee_attachment.objectkey IS
  'Clave S3. No se guarda URL porque la de un objeto privado caduca.';

CREATE INDEX IF NOT EXISTS idx_employee_attachment_employee
    ON employee_attachment (companyid, employeeid, createdat DESC);
