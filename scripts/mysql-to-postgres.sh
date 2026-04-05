#!/bin/bash

# Script para convertir esquema MySQL a PostgreSQL
# Uso: bash scripts/mysql-to-postgres.sh

set -e

echo "🔄 Convirtiendo esquema MySQL a PostgreSQL..."

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Verificar que existe el esquema MySQL
if [ ! -f "db-schema.sql" ]; then
    echo -e "${RED}❌ No se encuentra db-schema.sql${NC}"
    exit 1
fi

# Instalar pgloader si no está instalado
if ! command -v pgloader &> /dev/null; then
    echo -e "${YELLOW}⚠️  pgloader no está instalado${NC}"
    echo "Instalando pgloader..."
    
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        brew install pgloader
    elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
        # Linux
        sudo apt-get update && sudo apt-get install -y pgloader
    else
        echo -e "${RED}❌ Sistema operativo no soportado${NC}"
        exit 1
    fi
fi

# Crear archivo de configuración para pgloader
cat > /tmp/pgloader-config.load << 'EOF'
LOAD DATABASE
    FROM mysql://encom:encom123@localhost:3306/encomdb
    INTO postgresql://encom:encom123@localhost:5432/encomdb

WITH include drop, create tables, create indexes, reset sequences,
     workers = 8, concurrency = 1

SET PostgreSQL PARAMETERS
    maintenance_work_mem to '128MB',
    work_mem to '12MB'

CAST type datetime to timestamptz
     drop default drop not null using zero-dates-to-null,
     type date drop not null drop default using zero-dates-to-null,
     type tinyint to boolean using tinyint-to-boolean,
     type year to integer

BEFORE LOAD DO
    $$ DROP SCHEMA IF EXISTS public CASCADE; $$,
    $$ CREATE SCHEMA public; $$;
EOF

echo -e "${GREEN}✅ Configuración de pgloader creada${NC}"
echo ""

# Verificar que MySQL y PostgreSQL estén corriendo
if ! docker-compose ps | grep -q "encom_mysql.*Up"; then
    echo -e "${RED}❌ MySQL no está corriendo. Ejecuta: docker-compose up -d mysql${NC}"
    exit 1
fi

if ! docker-compose ps | grep -q "encom_postgres.*Up"; then
    echo -e "${RED}❌ PostgreSQL no está corriendo. Ejecuta: docker-compose up -d postgres${NC}"
    exit 1
fi

echo "⏳ Esperando a que las bases de datos estén listas..."
sleep 5

# Ejecutar migración
echo "🚀 Iniciando migración con pgloader..."
echo ""

pgloader /tmp/pgloader-config.load

echo ""
echo -e "${GREEN}✅ Migración completada${NC}"
echo ""

# Verificar tablas migradas
echo "📊 Verificando tablas migradas..."
docker exec -it encom_postgres psql -U encom -d encomdb -c "\dt"

echo ""
echo -e "${GREEN}✨ ¡Migración exitosa!${NC}"
echo ""
echo "Puedes acceder a pgAdmin en: http://localhost:5050"
echo "  Email: admin@encom.local"
echo "  Password: admin123"
