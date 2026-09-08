-- 207_geo_catalog.sql
-- Catálogo geográfico fiscal (departamento → distrito → ciudad).
--
-- POR QUÉ EXISTE. La pantalla de facturación electrónica pedía el
-- departamento, el distrito y la ciudad como CÓDIGOS NUMÉRICOS tipeados a
-- mano (`GeoCodeField`), con la descripción en un input separado. Nadie sabe
-- de memoria que CAPITAL es el 1, y los dos campos podían quedar
-- contradictorios sin que nada avisara: un domicilio fiscal declarado ante la
-- autoridad tributaria con el código de una ciudad y el nombre de otra. El
-- alta del emisor se frenaba ahí.
--
-- POR QUÉ SINCRONIZADO Y NO CONSULTADO EN VIVO. Es la decisión ya escrita en
-- `context/42-remision.md` ("catálogos geográficos se sincronizan de
-- Factomate, no se seedean de la SET"). Son ~6.400 ciudades que cambian muy
-- de vez en cuando; el formulario no puede depender de que la API del
-- proveedor fiscal esté arriba (su `PhoneLogin` estuvo caído un día entero el
-- 2026-09-07), y el usuario necesita buscar/filtrar sin un round-trip por
-- tecla. La copia local es la fuente que lee la pantalla; el proveedor es
-- solo el origen del dato.
--
-- DATOS DE PLATAFORMA, NO DE TENANT. No hay `companyid` ni FK a `company`: el
-- mapa de un país es el mismo para todos los comercios. Es la misma clase de
-- tabla que un catálogo de países — se sincroniza una vez y la leen todos.
-- Corolario: NINGUNA lectura de estas tablas necesita filtro multi-tenant, y
-- ninguna escritura viene de un operador (solo del job de sync).
--
-- NADA HARDCODEADO A UN PAÍS. `countrycode` viaja en el dato de origen
-- (`CountryCode` de la API) y entra en la clave única de los tres niveles.
-- Hoy el catálogo es paraguayo porque el proveedor fiscal lo es; mañana un
-- segundo país convive en las mismas tablas sin migración.
--
-- CÓDIGO FISCAL vs. PK DEL PROVEEDOR. `code` es el `Identifier` de la API —
-- el número que termina en el documento electrónico y el que el comercio ve
-- en su constancia. `providerid` es el `Id` interno del proveedor, que se
-- guarda solo para poder rastrear una fila hasta su origen; NUNCA se muestra
-- ni se manda al documento. Confundirlos declararía un domicilio equivocado.
--
-- LA JERARQUÍA VA POR CÓDIGO, NO POR UUID. `geo_district.departmentcode`
-- referencia `geo_department(countrycode, code)` con FK real, y
-- `geo_city.districtcode` a `geo_district(countrycode, code)`. Se usa el par
-- natural y no el UUID porque es el dato que la API devuelve anidado
-- (`Disctrict.DepartmentCode`, `City.DistrictCode`) y porque la jerarquía es
-- exactamente lo que la pantalla en cascada necesita validar. La FK es real y
-- no una convención: una ciudad huérfana es una ciudad que el select de
-- cascada nunca podría mostrar, y descubrirlo en la BD es barato — al
-- sincronizar — en vez de descubrirlo en el alta fiscal de un comercio.
--
-- `geo_city.departmentcode` es DENORMALIZACIÓN deliberada: viene de la misma
-- fila de origen (`City.Disctrict.DepartmentCode`), se escribe en el mismo
-- upsert, y permite listar/contar las ciudades de un departamento sin join
-- cuando el usuario todavía no eligió distrito. No es una segunda fuente de
-- verdad: si contradijera a `geo_district`, la FK de `districtcode` sigue
-- siendo la que manda.
--
-- BAJAS: `active`, NUNCA DELETE. La API marca `Deleted` en vez de borrar, y
-- acá pasa lo mismo por una razón que va más allá del espejo: un comercio
-- puede tener guardado en su domicilio fiscal el código de una ciudad que el
-- proveedor dio de baja después. Si la fila desapareciera, la pantalla no
-- podría ni mostrar de qué ciudad se trata. Se conserva inactiva y el
-- selector no la ofrece, pero sí la resuelve.
--
-- `searchname`: el nombre en minúsculas y SIN acentos, calculado por el
-- servicio de sync (PHP) y guardado como columna. No se usa `unaccent()` en
-- un índice porque no es IMMUTABLE (no indexable sin wrapper), ni `pg_trgm`
-- porque ninguna migración del repo depende todavía de esa extensión y no
-- hace falta: 6.400 filas con un btree de prefijo alcanzan de sobra, y la
-- búsqueda por substring sobre ese universo es instantánea igual.
--
-- Todo lowercase sin comillas (convención del repo). IF NOT EXISTS en todo —
-- la migración tiene que poder correr dos veces sin romper.

BEGIN;

CREATE TABLE IF NOT EXISTS geo_department (
  geodepartmentid  UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  countrycode      VARCHAR(8)    NOT NULL,
  code             INTEGER       NOT NULL,
  name             VARCHAR(160)  NOT NULL,
  searchname       VARCHAR(160)  NOT NULL,
  providerid       INTEGER,
  source           VARCHAR(20)   NOT NULL DEFAULT 'factomate',
  active           BOOLEAN       NOT NULL DEFAULT TRUE,
  synced_at        TIMESTAMPTZ   NOT NULL DEFAULT now()
);

-- Clave natural del nivel y destino de la FK de `geo_district`.
CREATE UNIQUE INDEX IF NOT EXISTS uq_geo_department_code
  ON geo_department(countrycode, code);

CREATE TABLE IF NOT EXISTS geo_district (
  geodistrictid    UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  countrycode      VARCHAR(8)    NOT NULL,
  code             INTEGER       NOT NULL,
  name             VARCHAR(160)  NOT NULL,
  searchname       VARCHAR(160)  NOT NULL,
  departmentcode   INTEGER       NOT NULL,
  providerid       INTEGER,
  source           VARCHAR(20)   NOT NULL DEFAULT 'factomate',
  active           BOOLEAN       NOT NULL DEFAULT TRUE,
  synced_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT fk_geo_district_department
    FOREIGN KEY (countrycode, departmentcode)
    REFERENCES geo_department(countrycode, code)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_geo_district_code
  ON geo_district(countrycode, code);

-- "Distritos de este departamento, alfabéticos" — la segunda pantalla de la
-- cascada. El nombre entra en el índice para que el ORDER BY salga del índice.
CREATE INDEX IF NOT EXISTS idx_geo_district_parent
  ON geo_district(countrycode, departmentcode, searchname);

CREATE TABLE IF NOT EXISTS geo_city (
  geocityid        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  countrycode      VARCHAR(8)    NOT NULL,
  code             INTEGER       NOT NULL,
  name             VARCHAR(160)  NOT NULL,
  searchname       VARCHAR(160)  NOT NULL,
  districtcode     INTEGER       NOT NULL,
  departmentcode   INTEGER       NOT NULL,
  providerid       INTEGER,
  source           VARCHAR(20)   NOT NULL DEFAULT 'factomate',
  active           BOOLEAN       NOT NULL DEFAULT TRUE,
  synced_at        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT fk_geo_city_district
    FOREIGN KEY (countrycode, districtcode)
    REFERENCES geo_district(countrycode, code)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_geo_city_code
  ON geo_city(countrycode, code);

-- "Ciudades de este distrito, alfabéticas" — la tercera pantalla de la cascada.
CREATE INDEX IF NOT EXISTS idx_geo_city_parent
  ON geo_city(countrycode, districtcode, searchname);

-- Fallback de la cascada cuando el usuario eligió departamento pero todavía no
-- distrito (y del contador de cobertura por departamento).
CREATE INDEX IF NOT EXISTS idx_geo_city_department
  ON geo_city(countrycode, departmentcode, searchname);

-- Buscador del combobox: `searchname LIKE 'x%'` sale por índice; el `%x%` cae
-- a scan sobre ~6.400 filas, que es más rápido que la red que lo trajo.
CREATE INDEX IF NOT EXISTS idx_geo_city_search
  ON geo_city(countrycode, searchname varchar_pattern_ops);

COMMENT ON TABLE geo_department IS
  'Catálogo geográfico fiscal, nivel 1. Dato de PLATAFORMA (sin companyid), sincronizado del proveedor fiscal por maintenance.php?job=geo-catalog-sync.';
COMMENT ON TABLE geo_district IS
  'Catálogo geográfico fiscal, nivel 2. Se deriva de lo ANIDADO en la ciudad: el endpoint /api/District/get del proveedor responde 500 (verificado 2026-09-08).';
COMMENT ON TABLE geo_city IS
  'Catálogo geográfico fiscal, nivel 3 (~6.400 filas). Alimenta el selector en cascada del domicilio de los establecimientos fiscales.';
COMMENT ON COLUMN geo_department.code IS
  'Identifier del proveedor = CÓDIGO FISCAL que va al documento electrónico. NO confundir con providerid (su PK interna).';
COMMENT ON COLUMN geo_city.departmentcode IS
  'Denormalizado del mismo registro de origen para listar ciudades por departamento sin join. La FK de districtcode es la jerarquía autoritativa.';
COMMENT ON COLUMN geo_city.active IS
  'false = dado de baja en el origen. La fila se CONSERVA para poder resolver el nombre de un código que un comercio ya tenía guardado; el selector no la ofrece.';

COMMIT;
