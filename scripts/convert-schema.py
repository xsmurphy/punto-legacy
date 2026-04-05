#!/usr/bin/env python3
"""
Script para convertir esquema MySQL a PostgreSQL
Convierte tipos de datos, sintaxis y características específicas
"""

import re
import sys

def convert_mysql_to_postgres(mysql_schema):
    """Convierte esquema MySQL a PostgreSQL"""
    
    postgres_schema = mysql_schema
    
    # Eliminar comentarios específicos de MySQL
    postgres_schema = re.sub(r'/\*!.*?\*/', '', postgres_schema, flags=re.DOTALL)
    postgres_schema = re.sub(r'--.*?Server version.*?\n', '', postgres_schema)
    
    # Convertir tipos de datos
    type_conversions = {
        r'\bTINYINT\(1\)\b': 'BOOLEAN',
        r'\bTINYINT\b': 'SMALLINT',
        r'\bINT\(\d+\)': 'INTEGER',
        r'\bBIGINT\(\d+\)': 'BIGINT',
        r'\bDATETIME\b': 'TIMESTAMP',
        r'\bDOUBLE\b': 'DOUBLE PRECISION',
        r'\bTEXT\b': 'TEXT',
        r'\bLONGTEXT\b': 'TEXT',
        r'\bMEDIUMTEXT\b': 'TEXT',
        r'\bENUM\((.*?)\)': r'VARCHAR(50) CHECK (value IN (\1))',
    }
    
    for mysql_type, postgres_type in type_conversions.items():
        postgres_schema = re.sub(mysql_type, postgres_type, postgres_schema, flags=re.IGNORECASE)
    
    # Convertir AUTO_INCREMENT a SERIAL
    postgres_schema = re.sub(
        r'(\w+)\s+INT(?:EGER)?\s+(?:NOT\s+NULL\s+)?AUTO_INCREMENT',
        r'\1 SERIAL',
        postgres_schema,
        flags=re.IGNORECASE
    )
    
    # Convertir ENGINE=InnoDB a nada (PostgreSQL no usa esto)
    postgres_schema = re.sub(r'ENGINE=\w+', '', postgres_schema, flags=re.IGNORECASE)
    postgres_schema = re.sub(r'DEFAULT CHARSET=\w+', '', postgres_schema, flags=re.IGNORECASE)
    postgres_schema = re.sub(r'COLLATE=\w+', '', postgres_schema, flags=re.IGNORECASE)
    
    # Convertir backticks a comillas dobles
    postgres_schema = postgres_schema.replace('`', '"')
    
    # Convertir CURRENT_TIMESTAMP() a CURRENT_TIMESTAMP
    postgres_schema = re.sub(r'CURRENT_TIMESTAMP\(\)', 'CURRENT_TIMESTAMP', postgres_schema)
    
    # Convertir DEFAULT '0000-00-00 00:00:00' a NULL
    postgres_schema = re.sub(
        r"DEFAULT\s+'0000-00-00 00:00:00'",
        'DEFAULT NULL',
        postgres_schema,
        flags=re.IGNORECASE
    )
    
    # Convertir UNSIGNED a CHECK constraint
    postgres_schema = re.sub(
        r'(\w+)\s+INTEGER\s+UNSIGNED',
        r'\1 INTEGER CHECK (\1 >= 0)',
        postgres_schema,
        flags=re.IGNORECASE
    )
    
    # Eliminar KEY que no son PRIMARY o FOREIGN
    postgres_schema = re.sub(r',\s*KEY\s+\w+\s+\([^)]+\)', '', postgres_schema, flags=re.IGNORECASE)
    
    # Convertir ON UPDATE CURRENT_TIMESTAMP
    postgres_schema = re.sub(
        r'ON UPDATE CURRENT_TIMESTAMP',
        '',
        postgres_schema,
        flags=re.IGNORECASE
    )
    
    # Limpiar líneas vacías múltiples
    postgres_schema = re.sub(r'\n\s*\n\s*\n', '\n\n', postgres_schema)
    
    return postgres_schema


def main():
    """Función principal"""
    
    if len(sys.argv) < 2:
        print("Uso: python3 convert-schema.py <archivo-mysql.sql> [archivo-salida.sql]")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2] if len(sys.argv) > 2 else 'postgres-schema.sql'
    
    print(f"📖 Leyendo {input_file}...")
    
    try:
        with open(input_file, 'r', encoding='utf-8') as f:
            mysql_schema = f.read()
    except FileNotFoundError:
        print(f"❌ Error: No se encuentra el archivo {input_file}")
        sys.exit(1)
    
    print("🔄 Convirtiendo esquema...")
    postgres_schema = convert_mysql_to_postgres(mysql_schema)
    
    print(f"💾 Guardando en {output_file}...")
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(postgres_schema)
    
    print("✅ Conversión completada!")
    print(f"\n⚠️  IMPORTANTE: Revisa manualmente {output_file}")
    print("   Algunas conversiones pueden requerir ajustes manuales.")


if __name__ == '__main__':
    main()
