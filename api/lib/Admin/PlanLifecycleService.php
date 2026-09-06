<?php

/**
 * PlanLifecycleService.php — motor del ciclo de vida del plan del tenant.
 * P2 de context/34-admin-saas-plan.md §F7. Lo invoca el job diario
 * `plan-lifecycle` de `api/v1/maintenance.php`.
 *
 * ============================================================
 * EL AGUJERO QUE TAPA
 * ============================================================
 *
 * Hasta acá `company.expiresAt` solo alimentaba reportes (MRR/churn en
 * `AdminReportsService`) y el semáforo de `TenantHealthService`. Varios
 * servicios LEEN `company.planExpired`, pero nadie lo escribía: no había job.
 * El trial de 14 días que da `SignupService` no vencía nunca. Medido en prod
 * el 2026-09-05: 6 tenants con `expiresAt` pasado, los 6 con `status=active`,
 * `blocked=0` y `planExpired=false`; el más viejo hace 74 días.
 *
 * Y los créditos IA del plan solo se acreditaban al CAMBIAR de plan
 * (`CompanyAdminService::grantPlanAiCredits`, fix del 2026-09-05): no había
 * recarga mensual.
 *
 * ============================================================
 * POR QUÉ DIARIO Y NO MENSUAL
 * ============================================================
 *
 * Vencimiento, gracia y avisos son transiciones POR DÍA: "hace 5 días que
 * venció", "faltan 3". Un job mensual no las puede expresar. La recarga de
 * créditos es la única rama con cadencia mensual, y se resuelve como una rama
 * que casi siempre no hace nada: mira si al tenant ya se le acreditó el
 * período corriente y, si sí, sigue de largo.
 *
 * ============================================================
 * ESTE SERVICIO MUERDE. NO ES UN MARCADOR (leer antes de deployar)
 * ============================================================
 *
 * La rama 2 escribe `blocked = 1`, y `blocked` NO es una señal inerte:
 * `companyAccessDenial()` (api/includes/functions.php) la enforcea desde
 * `apiAuthTenant()` en `api/bootstrap.php` y desde `apiAuthPosContext.php`.
 * O sea que 5 días después de vencer, el tenant queda AFUERA de la API
 * entera —panel y POS— en la request siguiente, sin que este slice toque una
 * línea del gate de sesión. Cualquier lectura de este archivo como "por ahora
 * solo marca" es falsa: marca la rama 1, muerde la rama 2.
 *
 * GAP CONOCIDO CONTRA LA D5 — no está implementada del todo. La D5 dice que
 * durante los 5 días de gracia el tenant queda en SOLO LECTURA (consulta y
 * exporta, no vende). Ese modo NO EXISTE en el codebase: no hay gate de
 * sesión read-only, así que hoy la gracia es acceso COMPLETO durante 5 días y
 * después nada. Es más permisivo que la decisión del owner al principio y
 * exactamente igual de restrictivo al final. Implementarlo es P3 (gate de
 * sesión) y no vive acá. No leer este servicio como si la D5 estuviera
 * cumplida.
 *
 * ============================================================
 * LA D8 ES UN INVARIANTE, NO UNA CONSECUENCIA DE ESTE ARCHIVO
 * ============================================================
 *
 * "Una venta encolada offline NUNCA se rechaza por cuenta impaga" (D8). Un POS
 * que sincroniza contra un tenant que esta rama bloqueó recibe 403, y sin más
 * información ese 403 mata la venta en la cola. Por eso el 403 del gate viaja
 * con `error.details.reason = 'account_blocked'` y el transporte del POS lo
 * traduce a ESPERA —no a error, no a reintento contado— en
 * `frontend/lib/pos/account-block.ts`. Si alguien cambia el motivo, o lo saca
 * del sobre, rompe la D8 sin tocar este archivo.
 *
 * ============================================================
 * ORDEN DE LAS RAMAS (importa)
 * ============================================================
 *
 * 1. vencer   → los que pasaron `expiresAt` y todavía no estaban marcados
 * 2. bloquear → los vencidos hace más de GRACE_DAYS
 * 3. avisar   → 7 días antes, 3 días antes, y al ENTRAR en gracia
 * 4. créditos → recarga mensual del plan
 *
 * 3 va después de 1 porque el aviso de "entraste en gracia" se le manda a
 * quien esté vencido y dentro de la ventana de gracia, y la rama 1 es la que
 * pone a alguien en ese estado: al revés, el que vence hoy esperaría un día
 * entero para enterarse. 2 va antes que 3 nada más que por prolijidad de
 * lectura del resultado: bloquear y avisar no comparten conjunto (la rama 3
 * excluye a los bloqueados).
 *
 * ============================================================
 * CROSS-TENANT SIN SESIÓN
 * ============================================================
 *
 * Mismo molde que `EInvoiceService::reconcileAll()`: se seleccionan filas con
 * su `companyid` y se procesa por fila, sin COMPANY_ID ni sesión de tenant.
 * Un tenant roto (sin dueño, sin teléfono, con el plan apuntando a nada) NO
 * puede tumbar la corrida: try/catch por tenant, se loguea y se sigue.
 */

declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/CompanyAdminService.php';
require_once __DIR__ . '/../Auth/RoleService.php';
require_once __DIR__ . '/../Support/TenantLocale.php';
require_once __DIR__ . '/../App/Services/Notification.php';


final class PlanLifecycleService
{
    /** D5 del owner: 5 días entre el vencimiento y el bloqueo. */
    private const GRACE_DAYS = 5;

    /** D7 del owner: avisos previos, en días restantes hasta `expiresAt`. */
    private const NOTICE_DAYS = [7, 3];

    /** Clave dentro de `company.config` donde se anota qué avisos ya salieron. */
    private const NOTICE_CONFIG_KEY = 'planLifecycleNotices';

    /** @var array<string, bool> cache de existencia de columnas por corrida */
    private static array $columnCache = [];

    /**
     * Corre las cuatro ramas. No lanza: cada rama acumula sus errores en el
     * resultado para que el log del job los muestre y la corrida siga.
     *
     * @return array{
     *   expired: list<string>,
     *   blocked: list<string>,
     *   notices: array{sent: int, skipped: int, dryRun: bool, detail: list<array<string,mixed>>},
     *   credits: array{granted: int, credits: int, period: string},
     *   errors: list<string>
     * }
     */
    public function run(): array
    {
        $errors = [];

        $expired = [];
        try {
            $expired = $this->expireDuePlans();
        } catch (\Throwable $e) {
            $errors[] = 'expire: ' . $e->getMessage();
        }

        $blocked = [];
        try {
            $blocked = $this->blockAfterGrace();
        } catch (\Throwable $e) {
            $errors[] = 'block: ' . $e->getMessage();
        }

        $notices = ['sent' => 0, 'skipped' => 0, 'dryRun' => !self::noticesEnabled(), 'detail' => []];
        try {
            $notices = $this->sendNotices();
        } catch (\Throwable $e) {
            $errors[] = 'notices: ' . $e->getMessage();
        }

        $credits = ['granted' => 0, 'credits' => 0, 'period' => self::currentPeriod()];
        try {
            $credits = $this->rechargeMonthlyAiCredits();
        } catch (\Throwable $e) {
            $errors[] = 'credits: ' . $e->getMessage();
        }

        return [
            'expired' => $expired,
            'blocked' => $blocked,
            'notices' => $notices,
            'credits' => $credits,
            'errors'  => $errors,
        ];
    }

    // ── Rama 1: vencer ─────────────────────────────────────────────────────

    /**
     * `expiresAt` en el pasado y todavía sin marcar → `planExpired = true` +
     * `planExpiredAt = now()`.
     *
     * `planExpiredAt` se escribe ACÁ, en el momento de la marca, y NO se
     * backfillea desde `expiresAt`. Es la propiedad de diseño de la que
     * depende que la primera corrida no bloquee a nadie de golpe: los 6
     * tenants que hoy están vencidos (uno hace 74 días) nunca fueron avisados,
     * y contar la gracia desde `expiresAt` los mandaría directo al bloqueo.
     * Contándola desde `planExpiredAt` entran en gracia HOY y recién dentro de
     * GRACE_DAYS son candidatos a bloqueo — con el aviso de entrada en gracia
     * en el medio. Ver mig 189.
     *
     * @return list<string> companyIds marcados
     */
    private function expireDuePlans(): array
    {
        $where = "expiresAt IS NOT NULL
                  AND expiresAt < now()
                  AND coalesce(planExpired, false) = false
                  AND " . $this->tenantScopeSql();

        $ids = $this->collectIds("SELECT companyId FROM company WHERE {$where}");
        if ($ids === []) {
            return [];
        }

        ncmExecute(
            "UPDATE company
                SET planExpired   = true,
                    planExpiredAt = now(),
                    updatedAt     = now()
              WHERE {$where}"
        );

        return $ids;
    }

    // ── Rama 2: bloquear ───────────────────────────────────────────────────

    /**
     * Vencidos hace más de GRACE_DAYS → `blocked = 1`.
     *
     * ⚠ ESTA RAMA NO ES INERTE — es la única de las cuatro que MUERDE HOY.
     *
     * El resto del job escribe señales que hoy nadie enforcea (`planExpired`
     * se lee para reportes y el semáforo). `blocked`, en cambio, YA tiene
     * gate: `checkCompanyStatus()` (api/includes/functions.php) devuelve false
     * si `blocked` no es vacío, y lo llaman `api/bootstrap.php:134` y
     * `api/lib/Auth/apiAuthPosContext.php:81`. O sea que poner `blocked = 1`
     * deja al tenant AFUERA de la API entera —panel y POS— en la request
     * siguiente, sin que este slice toque una sola línea del gate de sesión.
     *
    * Eso ES lo que pide la D5 ("+5 días → Bloqueada, no puede entrar"), pero
     * tiene una consecuencia operativa que hay que tener a la vista: con
     * `PLAN_LIFECYCLE_NOTIFY` apagado (el default) los avisos de la D7 no
     * salen, así que un comercio pasa de operar normalmente a no poder entrar
     * sin haber recibido ninguna advertencia. Los 6 tenants vencidos que hay
     * hoy en prod quedan bloqueados 5 días después de la primera corrida.
     * Antes de deployar esto conviene prender los avisos, o asumir el corte.
     *
     * Lo que este bloqueo NO puede hacer es matar una venta: el 403 que sale
     * de acá lleva `details.reason = 'account_blocked'` y el POS lo trata como
     * espera (D8). Ver el docblock del archivo.
     *
     * SE DESHACE SOLO desde /admin: `update()` y `extendTrial()` limpian
     * `blocked`, `planExpired` y `planExpiredAt` juntos cuando el vencimiento
     * se corre al futuro. Sin ese reset de `planExpiredAt` el desbloqueo
     * manual duraba hasta la corrida siguiente.
     *
     * `planExpiredAt IS NOT NULL` es un guard, no una formalidad: un tenant
     * con `planExpired = true` y sin timestamp (marcado a mano antes de que
     * existiera este job) NUNCA se bloquea. Fail-closed hacia el lado seguro.
     *
     * NO toca `status` ni `suspended`. `suspended` es una señal independiente
     * —decisión comercial, reversible— y pisarla haría que un tenant que pasa
     * por suspend→unsuspend pierda el estado de mora (ver el docblock de
     * `CompanyAdminService::suspend()`, que documenta ese mismo bug ya
     * arreglado en la otra dirección).
     *
     * @return list<string> companyIds bloqueados
     */
    private function blockAfterGrace(): array
    {
        $where = "coalesce(planExpired, false) = true
                  AND planExpiredAt IS NOT NULL
                  AND planExpiredAt < now() - make_interval(days => " . self::GRACE_DAYS . ")
                  AND coalesce(blocked, 0) = 0
                  AND " . $this->tenantScopeSql();

        $ids = $this->collectIds("SELECT companyId FROM company WHERE {$where}");
        if ($ids === []) {
            return [];
        }

        ncmExecute("UPDATE company SET blocked = 1, updatedAt = now() WHERE {$where}");

        return $ids;
    }

    // ── Rama 3: avisos (D7) ────────────────────────────────────────────────

    /**
     * ¿Los avisos salen de verdad? Apagados por default: son mensajes de
     * WhatsApp a comercios reales, y encender eso no puede ser un efecto
     * lateral de un deploy. Con `PLAN_LIFECYCLE_NOTIFY` sin setear el job
     * corre las ramas 1, 2 y 4 y solo LOGUEA a quién habría avisado.
     */
    public static function noticesEnabled(): bool
    {
        $raw = strtolower(trim((string) (
            defined('PLAN_LIFECYCLE_NOTIFY') ? PLAN_LIFECYCLE_NOTIFY : ($_ENV['PLAN_LIFECYCLE_NOTIFY'] ?? '')
        )));

        return $raw === '1' || $raw === 'on' || $raw === 'true';
    }

    /**
     * Avisos a los 7 y 3 días del vencimiento, y al entrar en gracia (D7).
     *
     * VENTANAS, NO IGUALDAD DE DÍA. La primera versión matcheaba
     * `(expiresAt::date - current_date) = 7`, y con eso un día sin corrida
     * —un deploy, el contenedor caído, el cron que no arrancó— perdía ese
     * aviso PARA SIEMPRE: al día siguiente la resta ya da 6 y ninguna rama lo
     * agarra. Un aviso que se pierde en silencio es peor que no tenerlo,
     * porque el bloqueo de la rama 2 llega igual.
     *
     * Ahora cada aviso es un intervalo de días restantes, y los intervalos son
     * DISJUNTOS: 'd7' cubre (3, 7] y 'd3' cubre [0, 3]. Un tenant que se
     * perdió el d7 lo recibe al día siguiente; uno que aparece con 2 días de
     * plazo recibe solo el d3, que es el urgente, y nunca los dos juntos.
     *
     * Idempotencia SIN columna nueva y SIN depender de que el job corra
     * exactamente una vez por día: cada aviso deja una marca en
     * `company.config->planLifecycleNotices.<clave>` cuyo VALOR es la fecha de
     * `expiresAt` del ciclo al que corresponde. Es la marca —y no la igualdad
     * de día— lo que impide el aviso repetido dentro de la ventana. Cuando el
     * comercio renueva y `expiresAt` se corre, las marcas dejan de coincidir
     * solas y los avisos del ciclo nuevo vuelven a habilitarse. Sin cron de
     * limpieza.
     *
     * La marca se escribe SOLO si el mensaje se envió de verdad. En dry-run no
     * se anota nada — si no, prender `PLAN_LIFECYCLE_NOTIFY` más adelante
     * encontraría todos los avisos ya "mandados" y nunca saldría ninguno. Y
     * como el envío puede fallar (Evolution caído), el aviso que no salió
     * vuelve a intentarse en la corrida siguiente mientras la ventana siga
     * abierta: la idempotencia protege del duplicado, no de reintentar.
     *
     * @return array{sent: int, skipped: int, dryRun: bool, detail: list<array<string,mixed>>}
     */
    private function sendNotices(): array
    {
        $dryRun = !self::noticesEnabled();
        $sent   = 0;
        $skip   = 0;
        $detail = [];

        $targets = [];

        // Avisos previos, por ventana de días restantes. `$lower` es el umbral
        // del aviso SIGUIENTE (más urgente), o -1 para el último, que así
        // incluye el día 0 —vence hoy, todavía no venció—.
        $thresholds = self::NOTICE_DAYS;
        foreach ($thresholds as $i => $days) {
            $key   = 'd' . $days;
            $lower = $thresholds[$i + 1] ?? -1;
            $rows  = $this->fetchRows(
                "SELECT companyId, expiresAt, expiresAt::date::text AS cycle,
                        (expiresAt::date - current_date) AS daysleft
                   FROM company
                  WHERE expiresAt IS NOT NULL
                    AND expiresAt > now()
                    AND (expiresAt::date - current_date) <= ?
                    AND (expiresAt::date - current_date) >  ?
                    AND coalesce(config->'" . self::NOTICE_CONFIG_KEY . "'->>'{$key}', '') <> expiresAt::date::text
                    AND coalesce(blocked, 0) = 0
                    AND " . $this->tenantScopeSql(),
                [$days, $lower]
            );
            foreach ($rows as $r) {
                $targets[] = ['kind' => $key, 'days' => (int) ($r['daysleft'] ?? $days), 'row' => $r];
            }
        }

        // Entrada en gracia. Se consulta por ESTADO —vencido, dentro de la
        // ventana de gracia, todavía sin bloquear— y no por "los que la rama 1
        // acabó de marcar". Con la lista de la rama 1, un envío que fallaba
        // (Evolution caído esa madrugada) no se reintentaba nunca: al día
        // siguiente el tenant ya estaba marcado y no volvía a aparecer. Preguntar
        // por el estado lo trae mientras la gracia siga abierta, y la marca de
        // `config` es la que evita el duplicado.
        //
        // `planExpiredAt IS NOT NULL` no es una formalidad: sin marca no hay
        // gracia que contar (ver `blockAfterGrace()`).
        $graceRows = $this->fetchRows(
            "SELECT companyId, expiresAt, expiresAt::date::text AS cycle
               FROM company
              WHERE coalesce(planExpired, false) = true
                AND planExpiredAt IS NOT NULL
                AND planExpiredAt >= now() - make_interval(days => " . self::GRACE_DAYS . ")
                AND coalesce(blocked, 0) = 0
                AND coalesce(config->'" . self::NOTICE_CONFIG_KEY . "'->>'grace', '') <> coalesce(expiresAt::date::text, '')
                AND " . $this->tenantScopeSql()
        );
        foreach ($graceRows as $r) {
            $targets[] = ['kind' => 'grace', 'days' => 0, 'row' => $r];
        }

        foreach ($targets as $t) {
            $companyId = (string) ($t['row']['companyid'] ?? '');
            if ($companyId === '') {
                continue;
            }

            try {
                $owner = $this->ownerEmail($companyId);
                if ($owner === null) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'kind' => $t['kind'], 'result' => 'sin email de dueño'];
                    continue;
                }

                $text = $this->noticeText($companyId, (string) $t['kind'], (int) $t['days']);

                if ($dryRun) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'kind' => $t['kind'], 'result' => 'dry-run'];
                    error_log(sprintf(
                        '[plan-lifecycle] DRY-RUN aviso %s a %s (%s): %s',
                        (string) $t['kind'],
                        $companyId,
                        self::maskEmail($owner),
                        $text
                    ));
                    continue;
                }

                // `sendEmails()` devuelve true, o el motivo del fallo como
                // string — nunca lanza (hay call-sites legacy que mandan mail
                // como efecto lateral de una venta).
                $res = \Punto\App\Services\Notification::sendEmails([
                    'to'      => $owner,
                    'subject' => $this->noticeSubject((string) $t['kind']),
                    'data'    => ['message' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))],
                ]);
                if ($res !== true) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'kind' => $t['kind'], 'result' => 'error: ' . (string) $res];
                    continue;
                }

                $this->markNoticeSent($companyId, (string) $t['kind'], (string) ($t['row']['cycle'] ?? ''));
                $sent++;
                $detail[] = ['companyId' => $companyId, 'kind' => $t['kind'], 'result' => 'enviado'];
            } catch (\Throwable $e) {
                $skip++;
                $detail[] = ['companyId' => $companyId, 'kind' => $t['kind'], 'result' => 'excepción: ' . $e->getMessage()];
                error_log('[plan-lifecycle] aviso falló para ' . $companyId . ': ' . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'skipped' => $skip, 'dryRun' => $dryRun, 'detail' => $detail];
    }

    /**
     * Teléfono del DUEÑO del tenant. El predicado del rol de dueño NO se
     * duplica: sale de `RoleService::ownerRoleSql()`, el único lugar del
     * codebase que sabe distinguir el int legacy '1' del UUID del rol con
     * slug 'owner'.
     *
     * A diferencia de `findPhoneLogin()`, este `LIMIT 1` va con `ORDER BY`:
     * gana el contacto marcado `main = 'true'` (el dueño REGISTRADO, mismo
     * criterio que `ownerContactSql`) y el `contactid` desempata. Un LIMIT 1
     * sin orden sobre un tenant con dos dueños elegiría una fila distinta en
     * cada corrida — el bug que context/55 §8 documenta en el login.
     */
    /**
     * Email del dueño del tenant — destinatario de los avisos.
     *
     * Era el TELÉFONO y los avisos salían por WhatsApp (Evolution). El owner
     * acotó el uso de Evolution el 2026-09-06: no es un canal de
     * notificaciones. Y era una decisión que además no funcionaba — no hay
     * ninguna instancia pareada en `evo.punto.la`, así que ningún aviso podía
     * salir. Email sí funciona (Resend, dominio verificado el mismo día).
     *
     * Al DUEÑO, no al cajero: un vencimiento de plan es del negocio. Ver
     * `context/34` §F7 D7.
     */
    private function ownerEmail(string $companyId): ?string
    {
        $rows = $this->fetchRows(
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

    /** Asunto del aviso. Dice el estado, no el mecanismo. */
    private function noticeSubject(string $kind): string
    {
        $app = defined('APP_NAME') ? APP_NAME : 'Punto';

        return match ($kind) {
            'grace' => $app . ' — tu plan venció',
            default => $app . ' — tu plan está por vencer',
        };
    }

    /** Email enmascarado para el log: basta para identificar, no para filtrar. */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** Texto del aviso. El nombre del comercio vive en `config->>'companyName'`. */
    private function noticeText(string $companyId, string $kind, int $days): string
    {
        $app  = defined('APP_NAME') ? APP_NAME : 'Punto';
        $rows = $this->fetchRows(
            "SELECT coalesce(nullif(config->>'companyName', ''), nullif(config->>'settingName', '')) AS name,
                    expiresAt AS vence
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $name  = trim((string) ($rows[0]['name'] ?? ''));
        $vence = $this->formatTenantDate($companyId, (string) ($rows[0]['vence'] ?? ''));
        $hola  = $name !== '' ? "Hola {$name}: " : 'Hola: ';

        if ($kind === 'grace') {
            return "[{$app}] {$hola}tu plan venció" . ($vence !== '' ? " el {$vence}" : '')
                 . '. Podés seguir consultando y exportando tu información, pero no vender. '
                 . 'Tenés ' . self::GRACE_DAYS . ' días para regularizar el pago antes de que la cuenta se bloquee.';
        }

        $cuando = $days <= 0
            ? 'tu plan vence hoy'
            : ($days === 1 ? 'tu plan vence mañana' : "tu plan vence en {$days} días");

        return "[{$app}] {$hola}{$cuando}"
             . ($vence !== '' ? " ({$vence})" : '')
             . '. Renovalo para no interrumpir las ventas.';
    }

    /**
     * Fecha en el formato y la zona DEL TENANT.
     *
     * Antes esto era un `to_char(expiresAt, 'DD/MM/YYYY')` en la query, o sea
     * el formato de Paraguay hardcodeado para todos los comercios, y en la zona
     * de la sesión de Postgres en vez de la del comercio. El proyecto no puede
     * asumir un país: `TenantLocale` ya es el lector único de país y zona (lo
     * usan `ContactImporter`, `TaxpayerLookupService`, `SettingsService`), así
     * que el patrón existe y esto se le engancha.
     *
     * La región sale del tenant y el idioma se deja sin especificar (`und`):
     * ICU resuelve el patrón por REGIÓN, que es lo que cambia acá — un mismo
     * texto en castellano se escribe 05/09/2026 en PY y 9/5/2026 en US.
     *
     * Sin `intl` (o con una fecha ilegible) se devuelve ISO, que es ambiguo
     * para nadie. Preferible a fabricar un formato de un país que no es el del
     * comercio.
     */
    private function formatTenantDate(string $companyId, string $raw): string
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

        // Idioma del PRODUCTO + región del TENANT.
        //
        // Acá decía `und-PY` (idioma indefinido + región), y ICU lo RECHAZA:
        // `IntlDateFormatter::create()` LANZA con ese locale en vez de
        // devolver null, así que el guard de abajo nunca llegaba a correr y
        // los seis avisos de la primera corrida real murieron con
        // "IntlDateFormatter::create(): Argument #1 ($locale) \"und-PY\" is
        // invalid" (verificado en prod, 2026-09-05 — se salvó porque los
        // avisos estaban en dry-run).
        //
        // 'es' no es hardcodear Paraguay: es el idioma del producto entero
        // (el front formatea con `date-fns/locale` `es` en todos lados). Lo
        // que SÍ sale del tenant es la región, que es lo que decide el orden
        // de la fecha. Un tenant argentino recibe es-AR, uno paraguayo es-PY.
        $country = \Punto\Api\Support\TenantLocale::country($companyId);
        $locale  = ($country !== null && $country !== '') ? 'es-' . strtoupper($country) : 'es';

        // try/catch ADEMÁS del chequeo de null: `create()` puede lanzar (es lo
        // que pasó) o devolver null según el modo de error de intl. Un aviso
        // no puede caerse por el formato de una fecha.
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

        return is_string($out) && $out !== '' ? $out : $dt->format('Y-m-d');
    }

    /**
     * Anota el aviso en `company.config`. MERGE sobre la subclave, no
     * reemplazo del JSONB entero: `config` es donde viven `moduleData`, los
     * settings del comercio y medio schema demoted (§22.8) — pisarlo entero
     * borraría la configuración del tenant.
     */
    private function markNoticeSent(string $companyId, string $kind, string $cycle): void
    {
        ncmExecute(
            "UPDATE company
                SET config = jsonb_set(
                      coalesce(config, '{}'::jsonb),
                      '{" . self::NOTICE_CONFIG_KEY . "}',
                      coalesce(config->'" . self::NOTICE_CONFIG_KEY . "', '{}'::jsonb) || jsonb_build_object(?::text, ?::text),
                      true),
                    updatedAt = now()
              WHERE companyId = ?",
            [$kind, $cycle, $companyId]
        );
    }

    // ── Rama 4: recarga mensual de créditos IA ─────────────────────────────

    /** Período de acreditación: mes calendario, 'YYYY-MM'. */
    public static function currentPeriod(): string
    {
        return gmdate('Y-m');
    }

    /**
     * Acredita `plans.ai_credits_monthly` una vez por período mensual.
     *
     * IDEMPOTENCIA SIN COLUMNA NUEVA: la acreditación deja en
     * `ai_credit_ledger.meta` el objeto `{"kind":"plan_monthly","period":"YYYY-MM"}`
     * y una segunda acreditación de ese mismo kind+period se descarta.
     * `reason` es texto libre editado por humanos (`grantAiCredits` lo recorta
     * a 120 chars) — matchear por texto sería frágil; `meta` es un contrato de
     * máquina.
     *
     * ESE CHEQUEO NO VIVE ACÁ. Está adentro de `grantAiCredits()`, bajo el
     * `SELECT ... FOR UPDATE` de la fila de `company`, por dos razones: el lock
     * lo hace libre de carrera, y hay OTRO camino que acredita con el mismo
     * kind+period —el cambio de plan desde /admin—. Mientras la regla estuvo
     * duplicada acá, ese otro camino no la tenía y alternar de plan A→B→A→B en
     * el mismo mes acreditaba el monto completo cada vez. Este método manda
     * todos los tenants candidatos y cuenta los que la función efectivamente
     * acreditó (`skipped` distingue uno del otro): así no hay una segunda copia
     * de la regla que se pueda desincronizar de la primera.
     *
     * NO hay índice único (companyId, kind, period). Sería una idempotencia
     * más dura, pero rompería el caso legítimo del upgrade de plan a mitad de
     * mes: `CompanyAdminService::grantPlanAiCredits()` escribe el MISMO kind y
     * period, y con el índice el tenant que sube de plan el día 12 se quedaría
     * sin los créditos del plan nuevo hasta el mes siguiente — que es
     * exactamente el bug reportado ("Balloon Party" con saldo 0), en chico.
     *
     * NO se le recarga a un tenant vencido o bloqueado: los créditos IA son
     * plata (tokens que paga la plataforma), y regalarlos a una cuenta que
     * dejó de pagar hace 74 días es la misma fuga que este job vino a tapar.
     * Al regularizar, la recarga vuelve sola en la corrida siguiente.
     *
     * @return array{granted: int, credits: int, period: string}
     */
    private function rechargeMonthlyAiCredits(): array
    {
        $period = self::currentPeriod();

        // `c.plan <> 0` no es cosmético: el índice único de `plans.plan_code`
        // es PARCIAL (`WHERE plan_code != 0`), así que el código 0 puede estar
        // repetido y el JOIN multiplicaría filas.
        $rows = $this->fetchRows(
            "SELECT c.companyId, p.name AS planname, p.ai_credits_monthly
               FROM company c
               JOIN plans p ON p.plan_code = c.plan
              WHERE c.plan <> 0
                AND p.ai_credits_monthly > 0
                AND coalesce(c.blocked, 0) = 0
                AND coalesce(c.planExpired, false) = false
                AND " . $this->tenantScopeSql('c') . "
              ORDER BY c.companyId"
        );

        $svc     = new \CompanyAdminService();
        $granted = 0;
        $credits = 0;

        foreach ($rows as $r) {
            $companyId = (string) ($r['companyid'] ?? '');
            $monthly   = (int) ($r['ai_credits_monthly'] ?? 0);
            $planName  = (string) ($r['planname'] ?? '');
            if ($companyId === '' || $monthly <= 0) {
                continue;
            }

            try {
                $res = $svc->grantAiCredits(
                    $companyId,
                    $monthly,
                    trim("Recarga mensual del plan {$planName} ({$period})"),
                    ['kind' => 'plan_monthly', 'period' => $period]
                );
                if (empty($res['ok'])) {
                    error_log(sprintf(
                        '[plan-lifecycle] recarga de %d créditos a %s falló: %s',
                        $monthly,
                        $companyId,
                        (string) ($res['error'] ?? 'error desconocido')
                    ));
                    continue;
                }
                // El período ya estaba acreditado (por una corrida anterior o
                // por un cambio de plan de este mes). No es un error y no suma.
                if (!empty($res['skipped'])) {
                    continue;
                }
                $granted++;
                $credits += $monthly;
            } catch (\Throwable $e) {
                error_log('[plan-lifecycle] recarga falló para ' . $companyId . ': ' . $e->getMessage());
            }
        }

        return ['granted' => $granted, 'credits' => $credits, 'period' => $period];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Fragmento SQL con el alcance del job: ni cuentas internas del propio
     * SaaS (`isinternal = 1`, mig 114) ni tenants ya cancelados. `suspended`
     * NO se filtra ni se toca: es una señal ortogonal a la mora.
     *
     * `isinternal` se chequea contra `information_schema` en vez de asumirse:
     * varias columnas de `company` fueron demoted a `config` JSONB (§22.8) y
     * una referencia a una columna inexistente no falla en build, falla en la
     * corrida del cron a las 6 de la mañana.
     */
    private function tenantScopeSql(string $alias = ''): string
    {
        $a   = $alias !== '' ? $alias . '.' : '';
        $sql = "coalesce({$a}status, 'active') <> 'cancelled'";

        if ($this->hasColumn('company', 'isinternal')) {
            $sql .= " AND coalesce({$a}isinternal, 0) = 0";
        }

        return '(' . $sql . ')';
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset(self::$columnCache[$key])) {
            return self::$columnCache[$key];
        }

        $rows = $this->fetchRows(
            'SELECT 1 AS ok FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?
              LIMIT 1',
            [$table, $column]
        );

        return self::$columnCache[$key] = ($rows !== []);
    }

    /**
     * SELECT → todas las filas, en orden. Es `ncmRows()`, el wrapper del
     * proyecto, y no un bucle propio sobre `ncmExecute(..., forceObj: true)`:
     * ese modo devuelve un RECORDSET, no un array, y tratarlo como array da
     * `[]` siempre (bug shipped 2026-06-18). `ncmRows` tampoco indexa por la
     * primera columna como el modo `getAssoc` —que hace DESAPARECER filas con
     * valor repetido, sin error—: acá se itera un conjunto de tenants donde
     * perder una fila en silencio sería perder una acreditación o un aviso.
     *
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $sql, array $params = []): array
    {
        return ncmRows($sql, $params);
    }

    /**
     * @param array<int, mixed> $params
     * @return list<string>
     */
    private function collectIds(string $sql, array $params = []): array
    {
        $ids = [];
        foreach ($this->fetchRows($sql, $params) as $row) {
            $id = (string) ($row['companyid'] ?? $row['companyId'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

}
