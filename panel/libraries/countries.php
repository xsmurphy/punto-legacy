<?php
/**
 * Países habilitados para registro: solo Latinoamérica.
 * Fuente de verdad: countries_hispanic.json
 *
 * Cada país incluye: nombre, código telefónico, moneda (símbolo, decimales,
 * separador de miles, nombre de IVA), idioma, TIN y código ISO.
 * La función signUp() usa estos datos para preconfigurar la cuenta nueva.
 */

$countriesHispanic = json_decode(
    file_get_contents(__DIR__ . '/countries_hispanic.json'),
    true
);

// Alias global por compatibilidad con código existente
$countries     = $countriesHispanic;
$_COUNTRIES    = $countriesHispanic;
$_COUNTRIES_H  = $countriesHispanic;
