<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

/**
 * Outbox genérico de notificaciones salientes — E0 de
 * `context/57-entrega-digital-del-kude.md` (D4). Mig 202.
 *
 * Reparto de responsabilidades, y es lo que evita que esto se convierta en
 * "el service que manda mails del KuDE":
 *
 *   - ESTA clase sabe de DURABILIDAD: encolar sin duplicar, tomar trabajo sin
 *     pisarse con otra corrida, reintentar con backoff y rendirse con el
 *     motivo a la vista. No sabe qué dice el mensaje ni cómo viaja.
 *   - El ADAPTER (uno por `channel`, ej. `EmailAdapter`) sabe CÓMO viaja.
 *   - El BUILDER (uno por `entitytype`, ej. `KudeEmailBuilder`) sabe QUÉ dice.
 *
 * `Notification::sendEmails()` sigue siendo el transporte y sigue siendo
 * fire-and-forget: la durabilidad la pone esta tabla, no el wrapper.
 *
 * CROSS-TENANT: `drain()` corre desde el cron, que no tiene sesión ni
 * `COMPANY_ID`. Cada fila trae su `companyid` y el builder resuelve el tenant
 * al construir el mensaje — mismo molde que `EInvoiceService::drain()`.
 */
final class NotificationOutbox
{
    /** Tipos de entidad conocidos. `entitytype` es libre en la BD; acá se valida al encolar. */
    public const ENTITY_EINVOICE_DOCUMENT = 'einvoice_document';

    public const CHANNEL_EMAIL = 'email';

    /**
     * Tope de intentos antes de marcar `error`. Ocho con el backoff de abajo
     * son ~3 días y medio de insistencia: suficiente para cubrir una caída
     * larga del proveedor sin dejar la fila reintentando para siempre.
     */
    private const MAX_ATTEMPTS = 8;

    /**
     * Backoff exponencial base 3 arrancando en 5 minutos, con techo de 24 h:
     * 5m, 15m, 45m, 2.2h, 6.7h, 20h, 24h, 24h.
     *
     * PACIENTE a propósito (F1 de context/57, contexto de mercado cerrado por
     * el owner 2026-09-06): en Paraguay es normal recibir la factura uno o dos
     * días después de emitida, así que estirar el reintento no cuesta nada en
     * experiencia y sí ahorra llamadas al proveedor. El primer intento real
     * ocurre al encolar + hasta 5 minutos (la cadencia del cron), no acá.
     *
     * SQL y no PHP: el cálculo va adentro del mismo UPDATE que hace el claim,
     * así el nuevo `next_attempt_at` se escribe de forma atómica con el
     * incremento de `attempts` (ver `claim()`).
     *
     * Los casts a `double precision` son OBLIGATORIOS, no cosmética. Con
     * argumentos enteros, `power()` es ambigua entre la variante float8 y la
     * numeric — y `interval * numeric` NO existe como operador en Postgres.
     * Resolver mal la sobrecarga acá no rompe el build ni el `php -l`: rompe
     * en la primera corrida del cron, en producción, con la cola entera
     * parada. Mismo tipo de falla que el `?` de jsonb contra PDO.
     */
    private const BACKOFF_SQL =
        "least(interval '5 minutes' * power(3::double precision, attempts::double precision), interval '24 hours')";

    /**
     * Encola una notificación. Idempotente por
     * `(companyid, entitytype, entityid, channel, recipient)`: la segunda
     * llamada con los mismos cinco valores no hace nada y devuelve `false`.
     *
     * Que el `recipient` entre en la clave es deliberado: reenviar el mismo
     * documento a OTRA dirección (D8 — "mandámelo a la del contador") es una
     * fila nueva y legítima, no un duplicado.
     *
     * NO valida que el destinatario exista ni que la entidad esté en estado de
     * ser notificada: eso lo decide quien encola, que es el único que conoce
     * las reglas de su dominio (ver `EInvoiceService::enqueueKudeEmail`).
     *
     * @param array<string,mixed> $meta contexto del encolado (nunca el cuerpo del mensaje).
     * @return bool true si se creó la fila; false si ya existía.
     */
    public static function enqueue(
        string $companyId,
        string $entityType,
        string $entityId,
        string $channel,
        string $recipient,
        array $meta = []
    ): bool {
        $recipient = trim($recipient);
        if ($companyId === '' || $entityId === '' || $recipient === '') {
            return false;
        }

        // El conflicto REARMA una fila muerta en vez de ignorarla: sin el
        // DO UPDATE, un envío que agotó sus intentos (status='error') dejaba
        // esa dirección clausurada para siempre — el reenvío manual (D8)
        // chocaba contra la fila terminal, devolvía queued:false y la UI le
        // decía al operador "ya había un envío pendiente", que era falso.
        // Solo se rearma lo TERMINAL: un 'pending' vivo o un 'sent' reciente
        // siguen siendo DO NOTHING de facto (el WHERE no matchea) y conservan
        // la idempotencia contra reconciliaciones repetidas.
        $row = ncmExecute(
            "INSERT INTO notification_outbox
                    (companyid, entitytype, entityid, channel, recipient, meta)
             VALUES (?, ?, ?, ?, ?, ?::jsonb)
             ON CONFLICT (companyid, entitytype, entityid, channel, recipient) DO UPDATE
                SET status = 'pending', attempts = 0, next_attempt_at = now(), last_error = NULL
              WHERE notification_outbox.status = 'error'
             RETURNING notificationid",
            [
                $companyId,
                $entityType,
                $entityId,
                $channel,
                mb_substr($recipient, 0, 255),
                json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );

        return (bool) $row;
    }

    /**
     * Drena la cola: toma hasta `$limit` notificaciones vencidas y las manda.
     * Lo invoca el cron (`maintenance.php?job=notification-drain`, cada 5 min).
     *
     * @return array{claimed:int,sent:int,retry:int,failed:int}
     */
    public function drain(int $limit = 25): array
    {
        $rows = $this->claim($limit);
        $out  = ['claimed' => count($rows), 'sent' => 0, 'retry' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $id       = (string) ($row['notificationid'] ?? '');
            $attempts = (int) ($row['attempts'] ?? 0); // ya incrementado por el claim
            if ($id === '') {
                continue;
            }

            try {
                $this->dispatch($row);
                $this->markSent($id);
                $out['sent']++;
            } catch (\Throwable $e) {
                // El KuDE que todavía no está generado cae acá y tiene que
                // VOLVER a intentarse: la factura ya se emitió, el envío no
                // puede rendirse por eso (context/57 §Arquitectura).
                //
                // Dos caminos, y la diferencia importa: `NotificationSkipped`
                // dice "esto ya NO hay que mandarlo" (documento anulado o
                // reemplazado, destinatario inválido) y se cierra en el acto
                // con el motivo escrito; cualquier otro fallo es transitorio y
                // vuelve a la cola hasta agotar los intentos.
                $terminal = $e instanceof NotificationSkipped || $attempts >= self::MAX_ATTEMPTS;
                $this->markFailure($id, $e->getMessage(), $terminal);
                $out[$terminal ? 'failed' : 'retry']++;
                error_log(sprintf(
                    '[NotificationOutbox] envío %s falló (intento %d/%d%s): %s',
                    $id,
                    $attempts,
                    self::MAX_ATTEMPTS,
                    $terminal ? ', TERMINAL' : '',
                    $e->getMessage()
                ));
            }
        }

        return $out;
    }

    /**
     * Toma trabajo de forma atómica. El claim ES el UPDATE: incrementa
     * `attempts` y empuja `next_attempt_at` con el backoff en la misma
     * sentencia, así la fila deja de ser elegible desde el instante en que
     * este proceso la agarra. Por eso no hace falta un estado 'sending'
     * (y no hay filas huérfanas si el proceso muere a mitad: la fila queda
     * `pending` con el próximo intento ya agendado).
     *
     * `FOR UPDATE SKIP LOCKED` sobre el subselect: dos corridas simultáneas se
     * reparten filas distintas en vez de bloquearse. El advisory lock del
     * endpoint de mantenimiento ya evita el caso normal; esto cubre el día que
     * el API se escale a N réplicas.
     *
     * @return list<\CaseInsensitiveArray>
     */
    private function claim(int $limit): array
    {
        $limit = min(200, max(1, $limit));

        return ncmRows(
            'UPDATE notification_outbox o
                SET attempts        = o.attempts + 1,
                    next_attempt_at = now() + ' . self::BACKOFF_SQL . '
              WHERE o.notificationid IN (
                    SELECT notificationid
                      FROM notification_outbox
                     WHERE status = \'pending\' AND next_attempt_at <= now()
                     ORDER BY next_attempt_at
                     LIMIT ?
                       FOR UPDATE SKIP LOCKED
                    )
            RETURNING o.notificationid, o.companyid, o.entitytype, o.entityid,
                      o.channel, o.recipient, o.attempts,
                      -- ALIAS obligatorio: `meta` es uno de los nombres mágicos
                      -- que `Query::flattenJsonb()` de-estructura y BORRA de la
                      -- fila en toda lectura del wrapper (mismo caveat que
                      -- documentó `einvoice_account.config`). Sin el alias, las
                      -- claves del meta se derraman al nivel de la fila y
                      -- podrían pisar una columna real.
                      o.meta AS outbox_meta',
            [$limit]
        );
    }

    /**
     * Rutea al adapter del canal. El adapter NO decide qué se manda: le pasa
     * la fila al builder del `entitytype` y se ocupa del transporte.
     *
     * @param \CaseInsensitiveArray|array<string,mixed> $row
     * @throws \RuntimeException si el canal no tiene adapter, o si el envío falla.
     */
    private function dispatch(mixed $row): void
    {
        $channel = (string) ($row['channel'] ?? '');

        switch ($channel) {
            case self::CHANNEL_EMAIL:
                (new EmailAdapter())->send($row);
                return;
            default:
                // Terminal de hecho: reintentar un canal que no existe no lo
                // va a hacer existir. Se agota igual por intentos y queda el
                // motivo escrito, que es lo que se necesita para diagnosticar.
                throw new \RuntimeException('Canal de notificación sin adapter: ' . $channel);
        }
    }

    private function markSent(string $id): void
    {
        ncmExecute(
            "UPDATE notification_outbox
                SET status = 'sent', sent_at = now(), last_error = NULL
              WHERE notificationid = ?",
            [$id]
        );
    }

    /**
     * Anota el fallo. `$terminal` = se agotaron los intentos → `error`, que es
     * el estado que el panel muestra como envío fallido. Si no, la fila queda
     * `pending` con el `next_attempt_at` que ya escribió el claim.
     */
    private function markFailure(string $id, string $message, bool $terminal): void
    {
        try {
            ncmExecute(
                'UPDATE notification_outbox
                    SET status = ' . ($terminal ? "'error'" : 'status') . ',
                        last_error = ?
                  WHERE notificationid = ?',
                [mb_substr($message, 0, 1000), $id]
            );
        } catch (\Throwable $e) {
            // Best-effort: que no se pueda escribir el motivo no puede tirar
            // abajo el resto de la corrida.
            error_log('[NotificationOutbox] no se pudo anotar el fallo de ' . $id . ': ' . $e->getMessage());
        }
    }
}
