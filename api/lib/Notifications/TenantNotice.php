<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

// `RoleService` vive en el namespace global y hoy llega por transitividad
// (bootstrap.php → hasPermission.php). Requerirlo explícitamente es lo que hace
// el resto de `api/lib/` que lo usa, y lo que evita que este archivo dependa de
// un orden de carga que no controla.
require_once __DIR__ . '/../Auth/RoleService.php';

/**
 * A quién y cómo se le avisa algo al COMERCIO (no al cliente final).
 *
 * Extraído de `PlanLifecycleService` (2026-09-07) cuando apareció el segundo
 * aviso de la misma familia —el vencimiento del TIMBRADO de una caja— y las
 * tres piezas reusables iban a quedar duplicadas: a qué dirección va, cómo se
 * escribe una fecha en la zona y la región del tenant, y cómo se manda el mail
 * sin que un fallo tumbe el job. Son decisiones del canal, no del plan.
 *
 * `PlanLifecycleService` delega acá; no hay dos definiciones de "el dueño".
 */
final class TenantNotice
{
    /**
     * Email del DUEÑO del tenant, o `null` si no hay ninguno cargado.
     *
     * Al dueño, no al cajero: tanto un plan vencido como un timbrado vencido
     * son del negocio, no del turno.
     *
     * El predicado del rol de dueño NO se duplica — sale de
     * `RoleService::ownerRoleSql()`, el único lugar del codebase que sabe
     * distinguir el int legacy `'1'` del UUID del rol con slug `owner`.
     *
     * El `ORDER BY` es deliberado: con dos dueños cargados, la elección tiene
     * que ser la misma en todas las corridas.
     */
    public static function ownerEmail(string $companyId): ?string
    {
        if ($companyId === '') {
            return null;
        }

        $rows = ncmRows(
            "SELECT c.contactemail
               FROM contact c
              WHERE c.companyid = ?
                AND c.type = 0
                AND coalesce(c.contactemail, '') <> ''
                AND " . \RoleService::ownerRoleSql('c') . "
              ORDER BY (coalesce(c.main, '') = 'true') DESC, c.contactid
              LIMIT 1",
            [$companyId]
        );

        if ($rows === []) {
            return null;
        }
        $email = trim((string) ($rows[0]['contactemail'] ?? ''));

        return $email !== '' ? $email : null;
    }

    /** Email enmascarado para el log: basta para identificar, no para filtrar. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Fecha en el formato y la zona DEL TENANT.
     *
     * Nada de `to_char(..., 'DD/MM/YYYY')`: eso es el formato de un país
     * hardcodeado para todos los comercios. `TenantLocale` es el lector único
     * de país y zona del proyecto.
     *
     * Idioma del PRODUCTO (`es`, que es como formatea el front en todos lados)
     * + REGIÓN del tenant, que es lo que decide el orden de la fecha: un tenant
     * argentino recibe `es-AR`, uno paraguayo `es-PY`. `und-PY` NO sirve — ICU
     * lo rechaza lanzando, y así murieron los seis primeros avisos reales del
     * plan (verificado en prod 2026-09-05).
     *
     * Sin `intl`, o con una fecha ilegible, devuelve ISO: ambiguo para nadie, y
     * preferible a fabricar el formato de un país que no es el del comercio.
     */
    public static function formatDate(string $companyId, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $tz = \Punto\Api\Support\TenantLocale::timezone($companyId);
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $dt = $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Throwable $e) {
            return '';
        }

        if (!class_exists(\IntlDateFormatter::class)) {
            return $dt->format('Y-m-d');
        }

        $country = \Punto\Api\Support\TenantLocale::country($companyId);
        $locale  = ($country !== null && $country !== '') ? 'es-' . strtoupper($country) : 'es';

        // try/catch ADEMÁS del chequeo de null: `create()` puede lanzar o
        // devolver null según el modo de error de intl. Un aviso no puede
        // caerse por el formato de una fecha.
        try {
            $fmt = \IntlDateFormatter::create(
                $locale,
                \IntlDateFormatter::SHORT,
                \IntlDateFormatter::NONE,
                $tz
            );
        } catch (\Throwable $e) {
            return $dt->format('Y-m-d');
        }
        if ($fmt === null) {
            return $dt->format('Y-m-d');
        }

        $out = $fmt->format($dt);

        // Mismo criterio que el original en `PlanLifecycleService`: cualquier
        // cosa que no sea un string NO VACÍO cae al ISO. `=== false` solo no
        // alcanza — un `''` dejaría el aviso sin fecha.
        return (is_string($out) && $out !== '') ? $out : $dt->format('Y-m-d');
    }

    /**
     * Manda el aviso por email.
     *
     * Devuelve `true`, o el motivo del fallo como string — NUNCA lanza. Es el
     * contrato de `Notification::sendEmails()`, que existe así porque hay
     * call-sites legacy que mandan mail como efecto lateral de una venta.
     *
     * El texto entra como plano y se escapa acá: el cuerpo lleva nombres de
     * caja y de sucursal cargados por el comercio, o sea entrada de usuario que
     * termina dentro de un HTML.
     */
    public static function sendEmail(string $to, string $subject, string $text): true|string
    {
        $res = \Punto\App\Services\Notification::sendEmails([
            'to'      => $to,
            'subject' => $subject,
            'data'    => ['message' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))],
        ]);

        if ($res === true) {
            return true;
        }

        // `sendEmails()` está tipado `mixed`. Normalizamos a string para que el
        // caller solo tenga que distinguir `true` de "no se pudo, y este es el
        // motivo" — sin volver a adivinar el shape en cada job.
        return (is_string($res) && trim($res) !== '') ? $res : 'error desconocido al enviar';
    }
}
