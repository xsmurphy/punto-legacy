#!/bin/bash

# Script completo para migrar el sistema actual de MySQL a PostgreSQL
# Incluye conversión de esquema y migración de datos

set -e

echo "🔄 Migrando sistema actual de MySQL a PostgreSQL..."
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# Verificar que ambas BD estén corriendo
echo "📋 Verificando servicios..."

if ! docker-compose ps | grep -q "encom_mysql.*Up"; then
    echo -e "${RED}❌ MySQL no está corriendo${NC}"
    echo "Ejecuta: docker-compose up -d mysql"
    exit 1
fi

if ! docker-compose ps | grep -q "encom_postgres.*Up"; then
    echo -e "${RED}❌ PostgreSQL no está corriendo${NC}"
    echo "Ejecuta: docker-compose up -d postgres"
    exit 1
fi

echo -e "${GREEN}✅ Servicios corriendo${NC}"
echo ""

# Paso 1: Convertir esquema
echo -e "${BLUE}📝 Paso 1: Convirtiendo esquema MySQL a PostgreSQL...${NC}"

if [ -f "db-schema.sql" ]; then
    # Usar script Python para conversión
    if command -v python3 &> /dev/null; then
        python3 scripts/convert-schema.py db-schema.sql postgres-schema.sql
        echo -e "${GREEN}✅ Esquema convertido: postgres-schema.sql${NC}"
    else
        echo -e "${YELLOW}⚠️  Python3 no encontrado, conversión manual necesaria${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  db-schema.sql no encontrado${NC}"
fi
echo ""

# Paso 2: Crear esquema en PostgreSQL
echo -e "${BLUE}📝 Paso 2: Creando esquema en PostgreSQL...${NC}"

# Limpiar base de datos
docker exec -i encom_postgres psql -U encom -d encomdb << 'EOF'
-- Eliminar todas las tablas
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public;
GRANT ALL ON SCHEMA public TO encom;
GRANT ALL ON SCHEMA public TO public;
EOF

echo -e "${GREEN}✅ Base de datos limpia${NC}"

# Aplicar esquema convertido si existe
if [ -f "postgres-schema.sql" ]; then
    echo "Aplicando esquema..."
    docker exec -i encom_postgres psql -U encom -d encomdb < postgres-schema.sql
    echo -e "${GREEN}✅ Esquema aplicado${NC}"
else
    echo -e "${YELLOW}⚠️  Esquema PostgreSQL no encontrado, usando pgloader...${NC}"
fi
echo ""

# Paso 3: Migrar datos con pgloader
echo -e "${BLUE}📝 Paso 3: Migrando datos con pgloader...${NC}"

if command -v pgloader &> /dev/null; then
    
    # Crear archivo de configuración
    cat > /tmp/pgloader-encom.load << 'PGLOADER_EOF'
LOAD DATABASE
    FROM mysql://encom:encom123@localhost:3306/encomdb
    INTO postgresql://encom:encom123@localhost:5432/encomdb

WITH include drop, create tables, create indexes, reset sequences,
     workers = 8, concurrency = 1,
     prefetch rows = 1000, batch rows = 1000

SET PostgreSQL PARAMETERS
    maintenance_work_mem to '256MB',
    work_mem to '32MB'

CAST 
    type datetime to timestamptz drop default drop not null using zero-dates-to-null,
    type date drop not null drop default using zero-dates-to-null,
    type tinyint to boolean using tinyint-to-boolean,
    type tinyint when (= precision 1) to boolean using tinyint-to-boolean,
    type year to integer,
    type bigint when unsigned to bigint drop typemod,
    type integer when unsigned to bigint drop typemod,
    type decimal when (and (= precision 15) (= scale 2)) to numeric,
    type char when (< precision 4) to varchar drop typemod,
    type varchar when (= precision 255) to text drop typemod

BEFORE LOAD DO
    $$ DROP SCHEMA IF EXISTS public CASCADE; $$,
    $$ CREATE SCHEMA public; $$,
    $$ GRANT ALL ON SCHEMA public TO encom; $$;
PGLOADER_EOF

    echo "Ejecutando pgloader..."
    pgloader /tmp/pgloader-encom.load
    
    echo -e "${GREEN}✅ Datos migrados${NC}"
    
else
    echo -e "${RED}❌ pgloader no está instalado${NC}"
    echo ""
    echo "Instala pgloader:"
    echo "  macOS:  brew install pgloader"
    echo "  Linux:  sudo apt-get install pgloader"
    echo ""
    exit 1
fi
echo ""

# Paso 4: Ajustes post-migración
echo -e "${BLUE}📝 Paso 4: Aplicando ajustes post-migración...${NC}"

docker exec -i encom_postgres psql -U encom -d encomdb << 'EOF'
-- Crear extensiones útiles
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "unaccent";

-- Función para updated_at automático
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Aplicar trigger a tablas con updated_at
DO $$
DECLARE
    t record;
BEGIN
    FOR t IN 
        SELECT table_name 
        FROM information_schema.columns 
        WHERE column_name = 'updated_at' 
        AND table_schema = 'public'
    LOOP
        EXECUTE format('
            DROP TRIGGER IF EXISTS update_%I_updated_at ON %I;
            CREATE TRIGGER update_%I_updated_at
                BEFORE UPDATE ON %I
                FOR EACH ROW
                EXECUTE FUNCTION update_updated_at_column();
        ', t.table_name, t.table_name, t.table_name, t.table_name);
    END LOOP;
END $$;

-- Configurar timezone
SET TIME ZONE 'America/Asuncion';

SELECT 'Ajustes aplicados correctamente' as resultado;
EOF

echo -e "${GREEN}✅ Ajustes aplicados${NC}"
echo ""

# Paso 5: Validación
echo -e "${BLUE}📝 Paso 5: Validando migración...${NC}"

echo "Contando tablas..."
MYSQL_TABLES=$(docker exec encom_mysql mysql -uencom -pencom123 encomdb -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'encomdb'")
PG_TABLES=$(docker exec encom_postgres psql -U encom -d encomdb -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")

echo "  MySQL: $MYSQL_TABLES tablas"
echo "  PostgreSQL: $PG_TABLES tablas"

if [ "$MYSQL_TABLES" -eq "$PG_TABLES" ]; then
    echo -e "${GREEN}✅ Número de tablas coincide${NC}"
else
    echo -e "${YELLOW}⚠️  Diferencia en número de tablas${NC}"
fi
echo ""

# Paso 6: Configurar sistema para usar PostgreSQL
echo -e "${BLUE}📝 Paso 6: Configurando sistema para usar PostgreSQL...${NC}"

# Backup de archivos actuales
if [ -f "panel/includes/db.php" ] && [ ! -f "panel/includes/db.php.mysql-backup" ]; then
    cp panel/includes/db.php panel/includes/db.php.mysql-backup
    echo -e "${GREEN}✅ Backup de panel/includes/db.php creado${NC}"
fi

if [ -f "app/includes/db.php" ] && [ ! -f "app/includes/db.php.mysql-backup" ]; then
    cp app/includes/db.php app/includes/db.php.mysql-backup
    echo -e "${GREEN}✅ Backup de app/includes/db.php creado${NC}"
fi

# Crear symlinks a archivos PostgreSQL
ln -sf db.postgres.php panel/includes/db.php
ln -sf db.postgres.php app/includes/db.php

echo -e "${GREEN}✅ Sistema configurado para usar PostgreSQL${NC}"
echo ""

# Resumen final
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}✨ ¡Migración completada!${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📊 Resumen:"
echo "  • Esquema convertido y aplicado"
echo "  • Datos migrados de MySQL a PostgreSQL"
echo "  • Ajustes post-migración aplicados"
echo "  • Sistema configurado para usar PostgreSQL"
echo ""
echo "🌐 Servicios:"
echo "  • pgAdmin: http://localhost:5050"
echo "    Email: admin@encom.local | Password: admin123"
echo ""
echo "🔄 Para volver a MySQL:"
echo "  ln -sf db.php.mysql-backup panel/includes/db.php"
echo "  ln -sf db.php.mysql-backup app/includes/db.php"
echo ""
echo "⚠️  IMPORTANTE:"
echo "  • Prueba todas las funcionalidades"
echo "  • Algunos queries pueden necesitar ajustes"
echo "  • Revisa los logs por errores"
echo ""
echo "📚 Documentación: README-POSTGRES.md"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
