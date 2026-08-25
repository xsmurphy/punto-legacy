-- 172_normalize_contact_main_flag.sql
--
-- `contact.main` es el flag "es el contacto principal del comercio" y el único
-- valor que escribe el código vivo es 'true' (SignupService al dar de alta el
-- tenant, y el alta de usuario en functions.php). Quedaron filas de seeds
-- viejos con main = 'admin', que NO significa nada para el sistema: los admins
-- de la plataforma viven en la tabla `admin_user`, no en `contact`.
--
-- El efecto era que el dueño de esos comercios no resolvía en las lecturas de
-- /admin (`RoleService::ownerContactSql()` exige main = 'true'), así que la
-- ficha del tenant salía sin propietario. Normalizar el dato es la solución;
-- aflojar el predicado sería propagar la basura, porque `main` es justamente
-- lo que separa al contacto principal del resto del padrón.
--
-- Acotada a contactos type = 0 (usuarios del panel) con rol de dueño, para no
-- tocar clientes/proveedores que hubieran heredado el valor por otra vía.
-- Idempotente: una segunda corrida no matchea ninguna fila.

UPDATE contact c
   SET main = 'true'
 WHERE c.main = 'admin'
   AND c.type = 0
   AND (
     c.role = '1'
     OR EXISTS (
       SELECT 1 FROM taxonomy owner_role
        WHERE owner_role.taxonomyid::text = c.role
          AND owner_role.taxonomytype = 'role'
          AND owner_role.companyid = c.companyid
          AND owner_role.taxonomyextra::json->>'slug' = 'owner'
     )
   );
