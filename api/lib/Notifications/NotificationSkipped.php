<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

/**
 * "Esto ya no hay que mandarlo" — distinto de "falló, reintentá".
 *
 * Entre que una notificación se encola y que sale pueden pasar días (el
 * backoff de `NotificationOutbox` es deliberadamente paciente), y en ese
 * tiempo la entidad puede dejar de ser notificable: una factura anulada por el
 * comercio, o reemplazada por una reemisión. Mandar igual sería peor que no
 * mandar — el comprador archivaría un PDF que ya no vale.
 *
 * El drainer trata esto como TERMINAL y escribe el motivo en `last_error`, así
 * que no desaparece en silencio: queda visible como envío no realizado, con la
 * razón. Un fallo normal (el KuDE todavía no está generado, el proveedor
 * caído) NO usa esta excepción: ese vuelve a la cola.
 */
final class NotificationSkipped extends \RuntimeException
{
}
