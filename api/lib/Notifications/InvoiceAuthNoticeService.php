<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

use Punto\Api\Support\TenantClock;

/**
 * Avisos de TIMBRADO POR VENCER / VENCIDO, por caja.
 *
 * Lo invoca el job diario `invoice-auth-notices` de `api/v1/maintenance.php`.
 *
 * ============================================================
 * POR QUÉ EXISTE
 * ============================================================
 *
 * Desde 2026-09-07 la caja NO puede facturar con el timbrado vencido: el guard
 * está en `InvoiceAuthGate` y lo aplican el POS (localmente, offline) y
 * `/v1/sales.php`. Un corte duro sin aviso previo, sin embargo, es una caja que
 * amanece sin poder vender: renovar un timbrado es un trámite ante la autoridad
 * fiscal, no un botón. El bloqueo y el aviso son las dos mitades de la misma
 * entrega — una sola no sirve.
 *
 * ============================================================
 * MECANISMO — EL MISMO QUE LOS AVISOS DEL PLAN
 * ============================================================
 *
 * No se inventa un scheduler ni una tabla: se copia, deliberadamente, lo que ya
 * resolvió `PlanLifecycleService` (context/34 §F7 D7), porque los dos problemas
 * son el mismo problema.
 *
 * - **VENTANAS, no igualdad de día.** `d7` cubre (3, 7] y `d3` cubre [0, 3];
 *   son DISJUNTAS. Con `= 7` exacto, un día sin corrida —un deploy, el cron que
 *   no arrancó— perdía ese aviso PARA SIEMPRE, porque al día siguiente la resta
 *   ya da 6 y ninguna rama lo agarra. `d3` incluye el día 0 a propósito: ese día
 *   el timbrado TODAVÍA sirve (vence al terminar), así que el aviso todavía es
 *   "por vencer".
 * - **`expired` mira hacia atrás una ventana acotada** ([-7, -1]): sin ese piso,
 *   el día del deploy saldría un mail por cada caja con un timbrado caído hace
 *   meses. El aviso es sobre algo accionable, no un censo histórico.
 * - **Idempotencia por marca en JSONB, sin tabla nueva.** En
 *   `register.data.invoiceAuthNotices` quedan `{d7, d3, expired}` y el VALOR de
 *   cada una es el vencimiento del timbrado que la disparó. Correr el job dos
 *   veces el mismo día no manda dos mails porque la marca ya coincide; y cuando
 *   el comercio renueva, el vencimiento cambia, las marcas dejan de coincidir
 *   solas y el ciclo nuevo vuelve a avisar. Sin cron de limpieza.
 * - **La marca se escribe SOLO tras un envío real.** En dry-run no se escribe
 *   nada: si se marcara, prender el switch después encontraría todo "ya
 *   enviado". Y un envío fallido se reintenta en la próxima corrida mientras la
 *   ventana siga abierta — la idempotencia protege del duplicado, no de
 *   reintentar.
 *
 * ============================================================
 * UN MAIL POR COMERCIO, CON EL DETALLE POR CAJA
 * ============================================================
 *
 * El vencimiento es por CAJA (`register.data.registerInvoiceAuthExpiration`),
 * pero el destinatario es el dueño del comercio. Un tenant con seis cajas que
 * vencen el mismo día tiene que recibir UN mail que las liste, no seis mails.
 * Por eso los targets se agrupan por `companyId` y las marcas se escriben por
 * caja: la agrupación es del mensaje, la idempotencia es del hecho.
 *
 * ============================================================
 * ZONA HORARIA
 * ============================================================
 *
 * Los días restantes NO se calculan en SQL con `current_date`: eso sería la
 * zona de la sesión de Postgres para todos los comercios por igual. La query
 * trae candidatos con una ventana holgada y el corte fino lo hace PHP contra
 * `TenantClock::now($companyId)`, o sea el día real del comercio.
 */
final class InvoiceAuthNoticeService
{
    /** Umbrales de aviso previo, de menos urgente a más urgente. */
    private const NOTICE_DAYS = [7, 3];

    /** Días hacia atrás que sigue avisando un timbrado ya vencido. */
    private const EXPIRED_LOOKBACK_DAYS = 7;

    /** Clave de las marcas de idempotencia dentro de `register.data`. */
    private const NOTICE_KEY = 'invoiceAuthNotices';

    /**
     * ¿Los avisos salen de verdad?
     *
     * **Encendidos por default**, al revés que los del plan. Ahí el default es
     * apagado porque el canal era WhatsApp a comercios reales y encenderlo no
     * podía ser un efecto lateral de un deploy. Acá el canal es email, el
     * volumen está acotado por las ventanas (y por el piso de
     * `EXPIRED_LOOKBACK_DAYS`, que es justamente lo que evita el mailing masivo
     * del primer día), y sobre todo: el bloqueo de facturación ya está activo.
     * Un aviso que hay que acordarse de encender con una env var es un aviso que
     * no se entregó, y deja el corte duro sin su mitad.
     *
     * `INVOICE_AUTH_NOTIFY=0` (u `off`/`false`) lo apaga y lo deja en dry-run.
     */
    public static function noticesEnabled(): bool
    {
        $raw = strtolower(trim((string) (
            defined('INVOICE_AUTH_NOTIFY') ? INVOICE_AUTH_NOTIFY : ($_ENV['INVOICE_AUTH_NOTIFY'] ?? '')
        )));

        return !($raw === '0' || $raw === 'off' || $raw === 'false');
    }

    /**
     * @return array{sent:int, skipped:int, dryRun:bool, detail:list<array<string,mixed>>}
     */
    public function run(): array
    {
        $dryRun = !self::noticesEnabled();
        $sent   = 0;
        $skip   = 0;
        $detail = [];

        // Agrupado por companyId: un mail por comercio con todas sus cajas.
        // @var array<string, list<array<string,mixed>>> $byCompany
        $byCompany = [];

        foreach ($this->candidates() as $row) {
            $companyId  = (string) ($row['companyid'] ?? '');
            $registerId = (string) ($row['registerid'] ?? '');
            $expiration = substr(trim((string) ($row['expiration'] ?? '')), 0, 10);
            if ($companyId === '' || $registerId === '' || $expiration === '') {
                continue;
            }

            $days = $this->daysLeft($companyId, $expiration);
            if ($days === null) {
                continue;
            }

            $kind = $this->kindFor($days);
            if ($kind === null) {
                continue;   // fuera de toda ventana: nada que avisar hoy
            }

            // Idempotencia: la marca guarda el vencimiento que disparó ese
            // aviso. Igual ⇒ ya salió para ESTE ciclo. Distinto (o ausente) ⇒
            // es un ciclo nuevo, o nunca se avisó.
            if (trim((string) ($row['mark_' . $kind] ?? '')) === $expiration) {
                continue;
            }

            $byCompany[$companyId][] = [
                'registerId' => $registerId,
                'register'   => trim((string) ($row['registername'] ?? '')),
                'outlet'     => trim((string) ($row['outletname'] ?? '')),
                'expiration' => $expiration,
                'days'       => $days,
                'kind'       => $kind,
            ];
        }

        foreach ($byCompany as $companyId => $items) {
            try {
                $owner = TenantNotice::ownerEmail($companyId);
                if ($owner === null) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'result' => 'sin email de dueño'];
                    continue;
                }

                // El asunto lo fija la caja MÁS urgente del lote: si una ya
                // venció, el mail no puede titularse "está por vencer".
                $worst = $this->mostUrgent($items);
                $text  = $this->noticeText($companyId, $items);

                if ($dryRun) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'result' => 'dry-run', 'cajas' => count($items)];
                    error_log(sprintf(
                        '[invoice-auth-notices] DRY-RUN aviso %s a %s (%s, %d caja/s): %s',
                        $worst,
                        $companyId,
                        TenantNotice::maskEmail($owner),
                        count($items),
                        $text
                    ));
                    continue;
                }

                $res = TenantNotice::sendEmail($owner, $this->subject($worst), $text);
                if ($res !== true) {
                    $skip++;
                    $detail[] = ['companyId' => $companyId, 'result' => 'error: ' . (string) $res];
                    continue;
                }

                // Recién ahora se marca, y una marca POR CAJA: el mail fue uno
                // solo, pero los hechos avisados son varios y cada uno tiene su
                // propio ciclo.
                foreach ($items as $it) {
                    $this->markSent(
                        (string) $it['registerId'],
                        $companyId,
                        (string) $it['kind'],
                        (string) $it['expiration'],
                    );
                }

                $sent++;
                $detail[] = ['companyId' => $companyId, 'result' => 'enviado', 'cajas' => count($items)];
            } catch (\Throwable $e) {
                $skip++;
                $detail[] = ['companyId' => $companyId, 'result' => 'excepción: ' . $e->getMessage()];
                error_log('[invoice-auth-notices] aviso falló para ' . $companyId . ': ' . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'skipped' => $skip, 'dryRun' => $dryRun, 'detail' => $detail];
    }

    /**
     * Cajas ACTIVAS con vencimiento cargado, de comercios vivos, dentro de una
     * ventana holgada alrededor de hoy.
     *
     * La ventana en SQL es deliberadamente ancha (`current_date` de la sesión ±
     * unos días de más): sirve para no traer la tabla entera, no para decidir.
     * El corte exacto lo hace `daysLeft()` en la zona de CADA comercio, que es
     * la única que puede decir qué día es allá.
     *
     * Cajas sin vencimiento cargado no entran nunca — mismo criterio que el
     * guard: donde no hay dato, no hay ni bloqueo ni aviso.
     *
     * OJO con `->>` y los placeholders: el operador jsonb que colisiona con el
     * `?` de PDO es `?`/`?|`/`?&`, no `->`/`->>`. Acá no hace falta
     * `jsonb_exists()`.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(): array
    {
        $lookahead = max(self::NOTICE_DAYS) + 2;
        $lookback  = self::EXPIRED_LOOKBACK_DAYS + 2;

        return ncmRows(
            "SELECT r.registerId   AS registerid,
                    r.companyId    AS companyid,
                    r.registerName AS registername,
                    o.outletName   AS outletname,
                    substr(r.data->>'registerInvoiceAuthExpiration', 1, 10) AS expiration,
                    coalesce(r.data->'" . self::NOTICE_KEY . "'->>'d7', '')      AS mark_d7,
                    coalesce(r.data->'" . self::NOTICE_KEY . "'->>'d3', '')      AS mark_d3,
                    coalesce(r.data->'" . self::NOTICE_KEY . "'->>'expired', '') AS mark_expired
               FROM register r
               JOIN company c ON c.companyId = r.companyId
               LEFT JOIN outlet o ON o.outletId = r.outletId AND o.companyId = r.companyId
              WHERE r.registerStatus = TRUE
                AND coalesce(r.data->>'registerInvoiceAuthExpiration', '') <> ''
                AND coalesce(c.status, 'active') = 'active'
                AND coalesce(c.blocked, 0) = 0
                AND substr(r.data->>'registerInvoiceAuthExpiration', 1, 10)
                      BETWEEN (current_date - (?)::int)::text
                          AND (current_date + (?)::int)::text
              ORDER BY r.companyId, o.outletName, r.registerName",
            [$lookback, $lookahead]
        );
    }

    /**
     * Días desde HOY (en la zona del comercio) hasta el vencimiento.
     * `0` = vence hoy y todavía sirve; negativo = ya venció.
     */
    private function daysLeft(string $companyId, string $expiration): ?int
    {
        $today = substr(TenantClock::now($companyId), 0, 10);
        try {
            $a = new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('UTC'));
            $b = new \DateTimeImmutable($expiration . ' 00:00:00', new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }

        return (int) $a->diff($b)->format('%r%a');
    }

    /** Ventana a la que cae `$days`, o `null` si no cae en ninguna. */
    private function kindFor(int $days): ?string
    {
        $thresholds = self::NOTICE_DAYS;
        foreach ($thresholds as $i => $d) {
            $lower = $thresholds[$i + 1] ?? -1;   // el último incluye el día 0
            if ($days <= $d && $days > $lower) {
                return 'd' . $d;
            }
        }

        if ($days < 0 && $days >= -self::EXPIRED_LOOKBACK_DAYS) {
            return 'expired';
        }

        return null;
    }

    /**
     * El aviso más urgente del lote — decide el asunto del mail.
     * `expired` gana sobre `d3`, que gana sobre `d7`.
     *
     * @param list<array<string,mixed>> $items
     */
    private function mostUrgent(array $items): string
    {
        $rank = ['d7' => 0, 'd3' => 1, 'expired' => 2];
        $best = 'd7';
        foreach ($items as $it) {
            $kind = (string) $it['kind'];
            if (($rank[$kind] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $kind;
            }
        }

        return $best;
    }

    /** Asunto. Dice el estado, no el mecanismo. */
    private function subject(string $worst): string
    {
        $app = defined('APP_NAME') ? APP_NAME : 'Punto';

        return $worst === 'expired'
            ? $app . ' — timbrado vencido'
            : $app . ' — tu timbrado está por vencer';
    }

    /**
     * Cuerpo del mail: una línea por caja, con sucursal, caja y fecha, y dónde
     * se renueva. Texto plano; `TenantNotice::sendEmail()` lo escapa (los
     * nombres de caja y sucursal los carga el comercio).
     *
     * @param list<array<string,mixed>> $items
     */
    private function noticeText(string $companyId, array $items): string
    {
        $app  = defined('APP_NAME') ? APP_NAME : 'Punto';
        $rows = ncmRows(
            "SELECT coalesce(nullif(config->>'companyName', ''), nullif(config->>'settingName', '')) AS name
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $name = trim((string) ($rows[0]['name'] ?? ''));
        $hola = $name !== '' ? "Hola {$name}: " : 'Hola: ';

        $anyExpired = false;
        foreach ($items as $it) {
            if ($it['kind'] === 'expired') {
                $anyExpired = true;
                break;
            }
        }

        $intro = $anyExpired
            ? "[{$app}] {$hola}hay cajas que no pueden facturar porque su timbrado venció."
            : "[{$app}] {$hola}se está por vencer el timbrado de tus cajas.";

        $lines = [];
        foreach ($items as $it) {
            $fecha    = TenantNotice::formatDate($companyId, (string) $it['expiration']);
            $fecha    = $fecha !== '' ? $fecha : (string) $it['expiration'];
            $caja     = (string) $it['register'] !== '' ? (string) $it['register'] : 'Caja sin nombre';
            $sucursal = (string) $it['outlet'] !== '' ? (string) $it['outlet'] : '';
            $donde    = $sucursal !== '' ? "{$sucursal} — {$caja}" : $caja;
            $days     = (int) $it['days'];

            $cuando = match (true) {
                $days <  0 => "venció el {$fecha}",
                $days === 0 => "vence hoy ({$fecha})",
                $days === 1 => "vence mañana ({$fecha})",
                default     => "vence en {$days} días ({$fecha})",
            };

            $lines[] = "- {$donde}: {$cuando}";
        }

        $cierre = 'Renovalo en Sucursales → Cajas. Con el timbrado vencido la caja no puede emitir facturas.';

        return $intro . "\n\n" . implode("\n", $lines) . "\n\n" . $cierre;
    }

    /**
     * Marca el aviso como enviado para ESTE ciclo del timbrado.
     *
     * `jsonb_set` sobre la subclave y `||` para mergear: nunca un reemplazo del
     * objeto entero, que borraría las otras marcas (y el resto de `data`, que es
     * donde viven timbrado, hotkeys, impresoras y `posConfig`).
     */
    private function markSent(string $registerId, string $companyId, string $kind, string $cycle): void
    {
        global $db;
        $db->Execute(
            "UPDATE register
                SET data = jsonb_set(
                      coalesce(data, '{}'::jsonb),
                      '{" . self::NOTICE_KEY . "}',
                      coalesce(data->'" . self::NOTICE_KEY . "', '{}'::jsonb)
                        || jsonb_build_object(?::text, ?::text),
                      true)
              WHERE registerId = ? AND companyId = ?",
            [$kind, $cycle, $registerId, $companyId]
        );
    }
}
