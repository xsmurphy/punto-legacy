<?php
/**
 * Phone helpers — sistema-wide convention para números de teléfono.
 *
 * REGLA: SIEMPRE storage en E.164 internacional (ej. "+595991234567"), conversión
 * a/desde formato nacional EXCLUSIVAMENTE vía libphonenumber-for-php. Nunca
 * concatenar "+" o "0" a mano. Nunca string-manipulate phones.
 *
 *   - DB / queries / API payloads → E.164 (con "+")
 *   - Display al usuario → formato nacional (vía phoneFormatNational())
 *   - Input del usuario → parseado con phoneToE164($input, $defaultIso)
 *
 * El cliente browser usa el bundle JS `libphonenumber-1.6.8.min.js` ya cargado
 * (window.libphonenumber.parsePhoneNumberFromString).
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Normaliza un input (con cualquier formato — nacional, sin prefijo, con +) al formato
 * E.164. Valida que sea un número telefónico real para el país dado.
 *
 * @param string $input        Lo que escribió el user, ej. "0991234567", "991234567", "+595991234567"
 * @param string $defaultIso   ISO 3166-1 alpha-2 del país (ej. "PY", "AR", "MX")
 * @return string|null         "+595991234567" o null si no es válido
 */
function phoneToE164(string $input, string $defaultIso = 'PY'): ?string
{
    $input = trim($input);
    if ($input === '') return null;

    try {
        $util  = \libphonenumber\PhoneNumberUtil::getInstance();
        $proto = $util->parse($input, strtoupper($defaultIso));
        if (!$util->isValidNumber($proto)) return null;
        return $util->format($proto, \libphonenumber\PhoneNumberFormat::E164);
    } catch (\libphonenumber\NumberParseException $e) {
        return null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Formatea un E.164 al formato nacional del país que el número indica.
 * Para display al usuario.
 *
 * @param string $e164  "+595991234567"
 * @return string|null  "099 123 4567" o null si no es válido
 */
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
 * Valida que un input sea un teléfono celular válido (MOBILE o FIXED_LINE_OR_MOBILE).
 * Útil cuando solo aceptamos celulares (no fijos), ej. signup para SMS.
 *
 * @param string $input        El input del user
 * @param string $defaultIso   ISO del país
 * @return bool
 */
function phoneIsMobile(string $input, string $defaultIso = 'PY'): bool
{
    try {
        $util  = \libphonenumber\PhoneNumberUtil::getInstance();
        $proto = $util->parse(trim($input), strtoupper($defaultIso));
        if (!$util->isValidNumber($proto)) return false;
        $type = $util->getNumberType($proto);
        return $type === \libphonenumber\PhoneNumberType::MOBILE
            || $type === \libphonenumber\PhoneNumberType::FIXED_LINE_OR_MOBILE;
    } catch (\Throwable $e) {
        return false;
    }
}
