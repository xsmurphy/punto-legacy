-- Script de inicialización para PostgreSQL
-- Este archivo se ejecuta automáticamente cuando se crea el contenedor

-- Crear extensiones útiles
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm"; -- Para búsquedas de texto
CREATE EXTENSION IF NOT EXISTS "unaccent"; -- Para búsquedas sin acentos

-- Configurar zona horaria por defecto
SET timezone = 'America/Asuncion';

-- Crear función para actualizar updated_at automáticamente
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Mensaje de bienvenida
DO $$
BEGIN
    RAISE NOTICE '✅ PostgreSQL inicializado correctamente';
    RAISE NOTICE '📊 Base de datos: encomdb';
    RAISE NOTICE '🌍 Zona horaria: America/Asuncion';
END $$;
