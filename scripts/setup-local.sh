#!/bin/bash

# Script de configuración automática para desarrollo local
# Uso: bash scripts/setup-local.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Configurando Punto para desarrollo local..."
echo ""

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Verificar requisitos
echo "Verificando requisitos..."

if ! command -v docker &> /dev/null; then
    echo -e "${RED}ERROR: Docker no está instalado${NC}"
    exit 1
fi

if ! command -v php &> /dev/null; then
    echo -e "${RED}ERROR: PHP no está instalado${NC}"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo -e "${RED}ERROR: Composer no está instalado${NC}"
    exit 1
fi

echo -e "${GREEN}OK: Requisitos verificados${NC}"
echo ""

cd "$ROOT_DIR"

# Crear .env si no existe
if [ ! -f .env ]; then
    echo "Creando .env desde .env.example..."
    cp .env.example .env
    echo -e "${GREEN}OK: .env creado${NC}"
else
    echo -e "${YELLOW}SKIP: .env ya existe${NC}"
fi
echo ""

# Directorios de cache
echo "Creando directorios de cache..."
mkdir -p cache/adodb
mkdir -p app/cach
chmod -R 777 cache app/cach
echo -e "${GREEN}OK: Directorios creados${NC}"
echo ""

# db.php apunta a db.local.php (que usa PDO)
echo "Configurando conexión a base de datos..."

for dir in panel app; do
    target="$dir/includes/db.php"
    if [ -f "$target" ]; then
        # Reemplazar contenido para apuntar a db.local.php
        echo "<?php
// LOCAL: apunta a db.local.php (PDO wrapper).
// PROD:  cambiar a db.postgres.php (ADOdb) o db.pdo.php una vez validado.
require_once __DIR__ . '/db.local.php';
" > "$target"
        echo -e "${GREEN}OK: $target → db.local.php${NC}"
    fi
done
echo ""

# Levantar Docker
echo "Iniciando servicios Docker (PostgreSQL + pgAdmin + Redis)..."
docker compose up -d

echo "Esperando a que PostgreSQL esté listo..."
for i in $(seq 1 15); do
    if docker exec punto_postgres pg_isready -U encom -d encomdb &>/dev/null; then
        echo -e "${GREEN}OK: PostgreSQL listo${NC}"
        break
    fi
    if [ $i -eq 15 ]; then
        echo -e "${RED}ERROR: PostgreSQL no respondió a tiempo${NC}"
        docker compose logs postgres
        exit 1
    fi
    sleep 2
done
echo ""

# Instalar dependencias Composer
echo "Instalando dependencias Composer..."

if [ -f "panel/composer.json" ]; then
    cd panel && composer install --no-interaction --quiet && cd "$ROOT_DIR"
    echo -e "${GREEN}OK: panel/vendor/ instalado${NC}"
fi

if [ -f "app/composer.json" ]; then
    cd app && composer install --no-interaction --quiet && cd "$ROOT_DIR"
    echo -e "${GREEN}OK: app/vendor/ instalado${NC}"
fi
echo ""

# Datos de prueba
echo "¿Crear datos de prueba en PostgreSQL? (s/n)"
read -r response

if [[ "$response" =~ ^([sS][iI]|[sS])$ ]]; then
    echo "Insertando datos de prueba..."

    docker exec -i punto_postgres psql -U encom -d encomdb << 'PGSQL'
-- Empresa de prueba
INSERT INTO company (companyId, status, plan, smsCredit, balance, createdAt)
VALUES (
    '00000000-0000-0000-0000-000000000001',
    'Active', 1, 0, 0,
    NOW()
) ON CONFLICT DO NOTHING;

-- Sucursal
INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
VALUES (
    '00000000-0000-0000-0000-000000000010',
    'Sucursal Principal', 1,
    '00000000-0000-0000-0000-000000000001'
) ON CONFLICT DO NOTHING;

-- Caja
INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
VALUES (
    '00000000-0000-0000-0000-000000000100',
    'Caja 1', 1,
    '00000000-0000-0000-0000-000000000010',
    '00000000-0000-0000-0000-000000000001'
) ON CONFLICT DO NOTHING;

-- Usuario admin (password: admin123)
INSERT INTO contact (
    contactId, contactName, contactEmail, contactPassword,
    type, role, companyId, outletId, contactStatus
) VALUES (
    '00000000-0000-0000-0000-000000001000',
    'Administrador', 'admin@local.test',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    0, 1,
    '00000000-0000-0000-0000-000000000001',
    '00000000-0000-0000-0000-000000000010',
    1
) ON CONFLICT DO NOTHING;

SELECT 'Datos de prueba insertados' AS resultado;
PGSQL

    echo -e "${GREEN}OK: Datos de prueba creados${NC}"
    echo ""
    echo -e "${YELLOW}Credenciales:${NC}"
    echo "  Email:    admin@local.test"
    echo "  Password: admin123"
fi
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}Configuracion completada${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Servicios Docker:"
echo "  PostgreSQL:  localhost:5432  (user: encom / encom123)"
echo "  pgAdmin:     http://localhost:5050  (admin@punto.local / admin123)"
echo "  Redis:       localhost:6379"
echo ""
echo "Iniciar servidores PHP:"
echo "  cd panel && php -S localhost:8001"
echo "  cd app   && php -S localhost:8000"
echo ""
echo "URLs:"
echo "  Panel:  http://localhost:8001"
echo "  App:    http://localhost:8000"
echo ""
