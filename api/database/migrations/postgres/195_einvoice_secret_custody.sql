-- 195_einvoice_secret_custody.sql
-- Custodia del certificado de firma y del CSC de SIFEN
-- (context/28-facturacion-electronica-plan.md §Custodia del certificado y del
-- CSC, decisión del owner 2026-09-06).
--
-- ============================================================
-- CAMBIO DE DECISIÓN: ANTES "PASA Y SE DESCARTA", AHORA SE GUARDA CIFRADO
-- ============================================================
--
-- La regla original del plan era que el `.pfx` y el `CSCProduccion` viajaran
-- al proveedor y se descartaran. El owner la revirtió por LOCK-IN: con
-- cientos de comercios, cambiar de proveedor de facturación electrónica
-- obligaría a pedirle el certificado a cada uno de nuevo — o sea, a no poder
-- migrar nunca. El costo de no guardarlos es quedar preso del proveedor.
--
-- Lo que NO cambia con esto: el `.pfx` ya pasaba por los servidores de Punto
-- (el comercio lo sube por nuestra UI). Lo que cambia es la RETENCIÓN, no si
-- Punto alguna vez lo toca.
--
-- ============================================================
-- POR QUÉ COLUMNAS EN LA BD Y NO S3
-- ============================================================
--
-- Un `.pfx` son unos KB (base64, ~33% más). No justifica un objeto en S3 con
-- su propio ciclo de vida, sus propias credenciales de acceso y una segunda
-- superficie desde la cual filtrarse. La fila del emisor ya es el lugar donde
-- vive todo lo demás de su identidad fiscal, y la vida del secreto es
-- exactamente la vida de la fila (ON DELETE CASCADE desde `company`).
--
-- ============================================================
-- CIFRADO: `CredentialVault` (AES-256-GCM), CLAVE EN EL ENTORNO
-- ============================================================
--
-- Mismo vault que ya protege `password_enc`/`phone_enc` (mig 92/95): AES-256
-- autenticado con la clave en `APP_ENCRYPTION_KEY`, que es env y NO base.
-- Eso es lo que hace defendible la decisión: un dump de Postgres por sí solo
-- no expone nada, hacen falta la base Y la clave. Límite honesto y anotado en
-- el plan: si se filtran las dos juntas quedan expuestos todos los
-- certificados — la rotación de esa clave pasa a ser parte del perímetro
-- fiscal.
--
-- Ninguna de estas columnas se lee sin dejar rastro: el único acceso es
-- `api/lib/EInvoice/FiscalSecretStore.php`, que escribe una fila en
-- `tenant_audit` ANTES de descifrar. Condición de la decisión, no un extra —
-- un secreto que se puede leer sin dejar rastro es un secreto del que no se
-- sabe si se filtró. Nunca vuelven al frontend ni al log.
--
-- ============================================================
-- POR QUÉ EL CSC TAMBIÉN, Y POR QUÉ ES MENOS DISCUTIBLE
-- ============================================================
--
-- El `CSCProduccion` es el código con el que se genera el QR del KuDE, no una
-- llave de firma: quien lo tenga NO puede firmar como el contribuyente. Su
-- riesgo es MENOR que el del `.pfx`, y hasta hoy el provisioning lo mandaba a
-- Factomate y lo tiraba — con lo cual reconfigurar la emisión obligaba a
-- pedírselo de nuevo al comercio, que a su vez tiene que ir a buscarlo al
-- Marangatu. Mismo criterio de custodia, mismo vault.
--
-- El `IdCSCProduccion` NO lleva columna: no es secreto (es el identificador
-- del par, no el código) y ya vive en `einvoice_account.fiscal`, que es el
-- espejo del formulario. Duplicarlo sería inventar una segunda fuente.
--
-- ============================================================
-- LOS TIMESTAMPS SON PARTE DEL CONTRATO CON EL COMERCIO
-- ============================================================
--
-- La UI le dice al comercio que Punto conserva su certificado cifrado y desde
-- cuándo — enterarse por accidente es peor que la custodia misma. Ese "desde
-- cuándo" sale de `cert_uploaded_at`/`csc_updated_at`: son lo ÚNICO que estas
-- columnas exponen hacia afuera. El contenido nunca sale del backend.
--
-- Todo lowercase sin comillas (patrón de 92_einvoice.sql / 100_einvoice_whitelabel.sql).
-- IF NOT EXISTS en todo — la migración tiene que poder correr dos veces.

BEGIN;

-- El `.pfx` en base64, cifrado. TEXT y no BYTEA: lo que se persiste es
-- exactamente lo que se le manda al proveedor (base64), y `CredentialVault`
-- devuelve base64 — un BYTEA agregaría dos conversiones sin ganar nada.
ALTER TABLE einvoice_account
    ADD COLUMN IF NOT EXISTS cert_pfx_enc TEXT;

-- La contraseña que abre el `.pfx`. Va cifrada igual que el archivo: sin ella
-- el certificado no sirve, así que guardarla en claro anularía el cifrado del
-- otro. (Factomate la guarda solo en Base64 de su lado — no lo controlamos,
-- pero no es razón para replicarlo acá.)
ALTER TABLE einvoice_account
    ADD COLUMN IF NOT EXISTS cert_password_enc TEXT;

-- Cuándo se cargó el certificado que está guardado HOY. Se reescribe en cada
-- reemplazo y se limpia al borrarlo. Es el único dato del certificado que la
-- UI puede mostrar.
ALTER TABLE einvoice_account
    ADD COLUMN IF NOT EXISTS cert_uploaded_at TIMESTAMPTZ;

-- Secreto del CSC de producción (SIFEN).
ALTER TABLE einvoice_account
    ADD COLUMN IF NOT EXISTS csc_secret_enc TEXT;

ALTER TABLE einvoice_account
    ADD COLUMN IF NOT EXISTS csc_updated_at TIMESTAMPTZ;

COMMENT ON COLUMN einvoice_account.cert_pfx_enc IS
    'Certificado de firma (.pfx en base64) cifrado con CredentialVault. NUNCA '
    'vuelve al frontend ni al log. Se lee solo por FiscalSecretStore, que '
    'audita la lectura en tenant_audit. Ver mig 195 y context/28 §Custodia.';

COMMENT ON COLUMN einvoice_account.cert_password_enc IS
    'Contraseña del .pfx, cifrada con CredentialVault. Mismas reglas que '
    'cert_pfx_enc — sin ella el certificado no abre. Ver mig 195.';

COMMENT ON COLUMN einvoice_account.cert_uploaded_at IS
    'Cuándo se cargó el certificado vigente. Único dato del certificado que la '
    'UI muestra ("cargado el ..."). NULL = no hay certificado guardado.';

COMMENT ON COLUMN einvoice_account.csc_secret_enc IS
    'CSCProduccion de SIFEN, cifrado con CredentialVault. El IdCSCProduccion '
    'NO va acá: no es secreto y ya vive en einvoice_account.fiscal. Ver mig 195.';

COMMENT ON COLUMN einvoice_account.csc_updated_at IS
    'Cuándo se guardó el CSC vigente. NULL = no hay secreto de CSC guardado.';

COMMIT;
