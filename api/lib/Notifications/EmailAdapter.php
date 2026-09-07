<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

use Punto\App\Services\Notification;

/**
 * Adapter del canal `email` del outbox (E1 de context/57).
 *
 * SABE CÓMO VIAJA, NO QUÉ DICE. El contenido lo arma un builder por
 * `entitytype`; acá sólo se resuelve cuál, se le pide el mensaje y se lo
 * entrega al transporte (`Notification::sendEmails()`, que desde 2026-09-06
 * habla con Resend y soporta adjuntos y `reply_to`). Si mañana el mismo
 * outbox tiene que mandar una cotización en PDF (context/56), lo que se suma
 * es un builder, no otra copia de esto.
 *
 * REGLA DURA DEL REMITENTE. El `from` es el dominio de Punto —es el que tiene
 * SPF/DKIM verificados, poner el del comercio exigiría DNS por tenant— pero el
 * NOMBRE visible es el del comercio y el `reply_to` es SU casilla. El dominio
 * de Punto tiene la recepción deshabilitada: un cliente que responda al `from`
 * escribe al vacío. Sin `reply_to` correcto, un email de factura es un callejón
 * sin salida para el comprador que tiene una duda.
 */
final class EmailAdapter
{
    /**
     * @param \CaseInsensitiveArray|array<string,mixed> $row fila reclamada del outbox.
     * @throws NotificationSkipped   si la entidad ya no se debe notificar (terminal).
     * @throws \RuntimeException     si el envío falló (la fila vuelve a la cola).
     */
    public function send(mixed $row): void
    {
        $companyId  = (string) ($row['companyid'] ?? '');
        $entityType = (string) ($row['entitytype'] ?? '');
        $entityId   = (string) ($row['entityid'] ?? '');
        $recipient  = trim((string) ($row['recipient'] ?? ''));

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            // Terminal: reintentar una dirección inválida no la vuelve válida.
            throw new NotificationSkipped('Dirección de email inválida: ' . $recipient);
        }

        $message = $this->build($companyId, $entityType, $entityId);

        $res = Notification::sendEmails([
            'to'          => $recipient,
            'subject'     => $message['subject'],
            'fromName'    => $message['fromName'],
            'replyTo'     => $message['replyTo'],
            'data'        => ['message' => $message['html']],
            'attachments' => $message['attachments'],
        ]);

        // `sendEmails()` nunca lanza: devuelve true, o el motivo como string
        // (hay call-sites legacy que mandan mail como efecto lateral de una
        // venta y una venta no puede caerse por el correo). Acá SÍ queremos la
        // excepción: es lo que hace que la fila vuelva a la cola con backoff.
        if ($res !== true) {
            throw new \RuntimeException((string) $res);
        }
    }

    /**
     * Ruteo al builder del tipo de entidad.
     *
     * @return array{subject:string,html:string,fromName:string,replyTo:string,attachments:list<array{filename:string,content:string}>}
     */
    private function build(string $companyId, string $entityType, string $entityId): array
    {
        switch ($entityType) {
            case NotificationOutbox::ENTITY_EINVOICE_DOCUMENT:
                return (new KudeEmailBuilder())->build($companyId, $entityId);
            default:
                throw new NotificationSkipped('No hay plantilla de email para el tipo: ' . $entityType);
        }
    }
}
