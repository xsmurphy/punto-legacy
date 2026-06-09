<?php
/**
 * Phone helpers — sistema-wide convention para números de teléfono.
 * Ver panel/includes/phone.php para la documentación completa.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
