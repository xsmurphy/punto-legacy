#!/bin/bash

# Script de configuración automática para desarrollo local
# Uso: bash scripts/setup-local.sh

set -e

echo "🚀 Configurando ENCOM para desarrollo local..."
echo ""

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Verificar requisitos
echo "📋 Verificando requisitos..."

if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker no está instalado${NC}"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}❌ Docker Compose no está instalado${NC}"
    exit 1
fi

if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP no está instalado${NC}"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo -e "${RED}❌ Composer no está instalado${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Todos los requisitos están instalados${NC}"
echo ""

# Crear archivo .env si no existe
if [ ! -f .env ]; then
    echo "📝 Creando archivo .env..."
    cp .env.example .env
    echo -e "${GREEN}✅ Archivo .env creado${NC}"
else
    echo -e "${YELLOW}⚠️  .env ya existe, saltando...${NC}"
fi
echo ""

# Crear directorios necesarios
echo "📁 Creando directorios necesarios..."
mkdir -p cache/adodb
mkdir -p app/cach
chmod -R 777 cache
chmod -R 777 app/cach
echo -e "${GREEN}✅ Directorios creados${NC}"
echo ""

# Configurar archivos de base de datos
echo "🔧 Configurando archivos de conexión a BD..."

# Panel
if [ -f panel/includes/db.php ] && [ ! -L panel/includes/db.php ]; then
    mv panel/includes/db.php panel/includes/db.php.production
    echo -e "${GREEN}✅ Respaldo de panel/includes/db.php creado${NC}"
fi

if [ ! -L panel/includes/db.php ]; then
    ln -sf db.local.php panel/includes/db.php
    echo -e "${GREEN}✅ Symlink creado para panel/includes/db.php${NC}"
fi

# App
if [ -f app/includes/db.php ] && [ ! -L app/includes/db.php ]; then
    mv app/includes/db.php app/includes/db.php.production
    echo -e "${GREEN}✅ Respaldo de app/includes/db.php creado${NC}"
fi

if [ ! -L app/includes/db.php ]; then
    ln -sf db.local.php app/includes/db.php
    echo -e "${GREEN}✅ Symlink creado para app/includes/db.php${NC}"
fi
echo ""

# Levantar Docker
echo "🐳 Iniciando servicios Docker..."
docker-compose up -d

echo "⏳ Esperando a que MySQL esté listo..."
sleep 10

# Verificar que MySQL esté corriendo
if docker-compose ps | grep -q "encom_mysql.*Up"; then
    echo -e "${GREEN}✅ MySQL está corriendo${NC}"
else
    echo -e "${RED}❌ MySQL no se pudo iniciar${NC}"
    docker-compose logs mysql
    exit 1
fi
echo ""

# Instalar dependencias de Composer
echo "📦 Instalando dependencias de Composer..."

if [ -d "panel/composer" ]; then
    cd panel/composer
    composer install --no-interaction
    cd ../..
    echo -e "${GREEN}✅ Dependencias del panel instaladas${NC}"
fi

if [ -d "app/composer" ]; then
    cd app/composer
    composer install --no-interaction
    cd ../..
    echo -e "${GREEN}✅ Dependencias de la app instaladas${NC}"
fi
echo ""

# Crear datos de prueba
echo "🗄️  ¿Deseas crear datos de prueba? (s/n)"
read -r response

if [[ "$response" =~ ^([sS][iI]|[sS])$ ]]; then
    echo "Creando datos de prueba..."
    
    docker exec -i encom_mysql mysql -uroot -proot123 encomdb << 'EOF'
-- Insertar empresa de prueba
INSERT IGNORE INTO company (companyId, companyStatus, companyPlan, companyUserActivated) 
VALUES (1, 'Active', 1, 1);

-- Insertar configuración
INSERT IGNORE INTO setting (companyId, settingName, settingCountry, settingCurrency, settingTimeZone, settingDecimal, settingThousandSeparator) 
VALUES (1, 'Mi Empresa Local', 'PY', 'PYG', 'America/Asuncion', ',', '.');

-- Insertar módulos
INSERT IGNORE INTO module (companyId, moduleData) 
VALUES (1, '{}');

-- Insertar sucursal
INSERT IGNORE INTO outlet (outletId, outletName, outletStatus, companyId) 
VALUES (1, 'Sucursal Principal', 1, 1);

-- Insertar caja registradora  
INSERT IGNORE INTO register (registerId, registerName, registerStatus, outletId, companyId) 
VALUES (1, 'Caja 1', 1, 1, 1);

-- Insertar usuario admin
INSERT IGNORE INTO contact (
    contactId, contactName, contactEmail, contactPassword, 
    type, role, main, companyId, outletId, contactStatus
) VALUES (
    1, 'Administrador', 'admin@local.test', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    0, 1, 'admin', 1, 1, 1
);

SELECT 'Datos de prueba creados exitosamente' AS resultado;
EOF
    
    echo -e "${GREEN}✅ Datos de prueba creados${NC}"
    echo ""
    echo -e "${YELLOW}Credenciales de acceso:${NC}"
    echo "  Email: admin@local.test"
    echo "  Password: admin123"
fi
echo ""

# Resumen final
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}✨ ¡Configuración completada!${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🌐 Servicios disponibles:"
echo "  • PHPMyAdmin: http://localhost:8080"
echo "    Usuario: root | Password: root123"
echo ""
echo "🚀 Para iniciar el sistema:"
echo ""
echo "  Panel Admin:"
echo "    cd panel && php -S localhost:8001"
echo "    Abre: http://localhost:8001"
echo ""
echo "  App POS:"
echo "    cd app && php -S localhost:8000"
echo "    Abre: http://localhost:8000"
echo ""
echo "📚 Documentación: README-LOCAL.md"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
