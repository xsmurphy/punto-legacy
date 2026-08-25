<?php
/**
 * Phone helpers — sistema-wide convention para números de teléfono.
 * Ver panel/includes/phone.php para la documentación completa.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * `$defaultIso` es OBLIGATORIO a propósito: antes era `= 'PY'` y cualquier
 * caller que se olvidara de pasarlo interpretaba el número como paraguayo en
 * silencio (un '0991 234567' de un comercio argentino se guardaba como
 * +595...). Ahora cada call-site tiene que decir de qué país es el número —
 * normalmente el `settingCountry` del tenant, vía TenantLocale::country().
 *
 * `null` es un valor válido y significa "no hay país de referencia": el
 * número TIENE que venir ya en E.164 (con '+'), y si no, se rechaza. Es la
 * opción correcta en los endpoints pre-login (no hay tenant del cual sacar
 * el país) donde el frontend ya manda E.164.
 */
function phoneParseRegion(?string $defaultIso): ?string
{
    $iso = strtoupper(trim((string) $defaultIso));
    return $iso !== '' ? $iso : null;
}

function phoneToE164(string $input, ?string $defaultIso): ?string
{
    $input = trim($input);
    if ($input === '') return null;

    try {
        $util  = \libphonenumber\PhoneNumberUtil::getInstance();
        $proto = $util->parse($input, phoneParseRegion($defaultIso));
        if (!$util->isValidNumber($proto)) return null;
        return $util->format($proto, \libphonenumber\PhoneNumberFormat::E164);
    } catch (\libphonenumber\NumberParseException $e) {
        return null;
    } catch (\Throwable $e) {
        return null;
    }
}

function phoneFormatNational(string $e164): ?string
{
    try {
        $util  = \libphonenumber\PhoneNumberUtil::getInstance();
        $proto = $util->parse($e164, null);
        if (!$util->isValidNumber($proto)) return null;
        return $util->format($proto, \libphonenumber\PhoneNumberFormat::NATIONAL);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Normaliza un E.164 para almacenar en BD. Convención del proyecto:
 * los phones se persisten SIN el '+' inicial (ej. '595991742353', no
 * '+595991742353'). El '+' se re-agrega al consumir externamente
 * (SMS/WhatsApp/dLocal). Retorna null si el input es null/vacío.
 */
function normalizePhoneForStorage(?string $phone): ?string
{
    if ($phone === null) return null;
    $phone = trim($phone);
    if ($phone === '') return null;
    return ltrim($phone, '+');
}

/**
 * Helper combo: valida con phoneToE164 y devuelve listo para storage
 * (sin '+'). Retorna null si el input es vacío; throw RuntimeException
 * con mensaje custom si el número es inválido.
 */
function phoneValidateForStorage(?string $input, ?string $defaultIso, string $errorMsg = 'Teléfono inválido'): ?string
{
    if ($input === null) return null;
    $input = trim($input);
    if ($input === '') return null;
    $e164 = phoneToE164($input, $defaultIso);
    if ($e164 === null) throw new RuntimeException($errorMsg);
    return normalizePhoneForStorage($e164);
}

function phoneIsMobile(string $input, ?string $defaultIso): bool
{
    try {
        $util  = \libphonenumber\PhoneNumberUtil::getInstance();
        $proto = $util->parse(trim($input), phoneParseRegion($defaultIso));
        if (!$util->isValidNumber($proto)) return false;
        $type = $util->getNumberType($proto);
        return $type === \libphonenumber\PhoneNumberType::MOBILE
            || $type === \libphonenumber\PhoneNumberType::FIXED_LINE_OR_MOBILE;
    } catch (\Throwable $e) {
        return false;
    }
}
