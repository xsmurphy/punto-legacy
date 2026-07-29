-- 100_contact_pin_hashes_promote.sql
-- Rescata los hashes de PIN / lock que se escribieron en el JSONB `data` de
-- `contact` en vez de sus columnas reales.
--
-- Mismo bug que la mig 99 (item): `lockPassHash` (mig 49) y `pinhash` (mig 55)
-- no estaban en el schema map de `_getTableSchema()`, así que
-- `UsersService::create/update` los mandaba al JSONB vía ncmInsert/ncmUpdate.
-- El unlock del POS (`api/v1/unlock-pin.php`) valida contra las COLUMNAS, así
-- que cambiar el PIN de un usuario respondía OK y no tenía ningún efecto: se
-- seguía entrando con el PIN viejo.
--
-- Qué valor gana: el del JSONB. La columna quedó congelada en el último write
-- que pasó por SQL crudo (backfill de la mig 49); el JSONB tiene el cambio más
-- reciente hecho desde la UI, que es lo que el usuario espera que valga.
--
-- Verificado en prod antes de escribir esta mig: 3 contactos con la clave en el
-- JSONB, 2 de ellos con hash DISTINTO al de la columna (dos cambios de PIN que
-- nunca tomaron efecto).
--
-- jsonb_exists() y no el operador `?`: `?` colisiona con el placeholder de PDO.
BEGIN;

UPDATE contact
   SET lockpasshash = data->>'lockPassHash',
       data         = data - 'lockPassHash'
 WHERE jsonb_exists(data, 'lockPassHash');

UPDATE contact
   SET pinhash = data->>'pinhash',
       data    = data - 'pinhash'
 WHERE jsonb_exists(data, 'pinhash');

COMMIT;
