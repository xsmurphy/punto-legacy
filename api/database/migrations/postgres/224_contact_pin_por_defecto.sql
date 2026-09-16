-- 224_contact_pin_por_defecto.sql
-- Marca de "este PIN lo puso el alta de la cuenta, no la persona"
-- (context/72 §9.3, D-P2).
--
-- ── Por qué hace falta ──────────────────────────────────────────────────
-- El signup (`SignupService::create()`) le pone al dueño el PIN `1111` sin
-- decírselo. Mientras la sucursal tiene UN solo usuario la caja no pide PIN,
-- así que nunca lo necesita. El día que se da de alta un segundo usuario el
-- bloqueo vuelve solo y el dueño queda frente a un PIN que no conoce. Al dar
-- de alta ese segundo usuario el panel le pide elegir el suyo — y para eso
-- hay que saber si el PIN que tiene es el del signup.
--
-- Comparar contra `1111` no alcanza: alguien puede haberlo ELEGIDO. La marca
-- dice quién lo puso: `true` solo al nacer del signup; cualquier escritura del
-- PIN (todas pasan por `UsersService`) la baja.
--
-- ── Backfill ────────────────────────────────────────────────────────────
-- Conservador: el usuario PRINCIPAL del signup (`main = 'true'`), activo, con
-- `lockpass = '1111'`. Es el único que el signup crea con ese PIN. Si uno de
-- ellos lo eligió a mano igual a `1111`, se le va a pedir elegir de nuevo una
-- sola vez — preferible a dejar a un dueño con el PIN por defecto sin avisar.
--
-- Idempotente: `ADD COLUMN IF NOT EXISTS` (con DEFAULT constante es solo
-- metadata, no reescribe `contact`) y el UPDATE solo toca filas en `false`.

ALTER TABLE contact ADD COLUMN IF NOT EXISTS pinisdefault boolean NOT NULL DEFAULT false;

UPDATE contact
   SET pinisdefault = true
 WHERE type = 0
   AND main = 'true'
   AND contactstatus = 1
   AND lockpass = '1111'
   AND pinisdefault = false;
