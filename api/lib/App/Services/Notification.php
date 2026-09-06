<?php
declare(strict_types=1);

namespace Punto\App\Services;

/**
 * Servicios de notificación del POS (email, SMS, push, WebSocket).
 *
 * Reemplaza las funciones globales (Slice 15 del plan PSR-4):
 *   - sendEmails($options)                          → Notification::sendEmails($options)
 *   - sendEmail($options)                           → Notification::sendEmail($options)
 *   - sendSMS($number, $msg, $numval, $auto)        → Notification::sendSMS(...)
 *   - sendPush($options)                            → Notification::sendPush($options)
 *   - sendSMTPEmail($meta, $tpl, $to, $sub, ...)   → Notification::sendSMTP(...)
 *   - sendNCMSMS($number, $msg, $country, $compId) → Notification::sendNCMSMS(...)
 *   - sendWS($ops)                                  → Notification::sendWS($ops)
 *
 * Riesgo bajo: estas funciones son side-effects externos (HTTP/SMTP).
 * Los wrappers tienen cero breaking changes.
 */
final class Notification
{
    /**
     * Envía un email transaccional por Resend (`POST /emails`).
     *
     * ── Por qué Resend y no el Mailgun que decía acá ────────────────────────
     * Esta función NUNCA funcionó. Llamaba a `\MailgunClient::create()` con
     * barra inicial, o sea el namespace GLOBAL, pero el alias
     * `use Mailgun\Mailgun as MailgunClient` vive en `includes/functions.php`
     * y en PHP los `use` son POR ARCHIVO: nunca llegó hasta acá. Cualquier
     * llamada moría con "Class MailgunClient not found" — no se notó porque
     * Mailgun jamás estuvo configurado en prod (dominio, token y EMAIL_FROM,
     * los tres vacíos). Hay tres call-sites vivos (`transactions.php`,
     * `schedule.php`, `orders.php`) que habrían fataleado el día que alguien
     * cargara las credenciales.
     *
     * Como el wrapper había que reescribirlo igual, el owner eligió Resend
     * (2026-09-06). Sin SDK: es un POST con JSON, y el SDK de Mailgun
     * arrastraba toda la pila PSR-18 para esto.
     *
     * ── El remitente ────────────────────────────────────────────────────────
     * El dominio es de PUNTO (`EMAIL_FROM`, verificado con SPF/DKIM en
     * Resend): poner el del comercio exigiría DNS por tenant. Pero el NOMBRE
     * visible es el del comercio y el `reply_to` es su casilla — una factura
     * es del comercio hacia su cliente, y un `from` que dice "Punto" confunde
     * a quien la recibe. De ahí `fromName` y `replyTo` por llamada.
     *
     * @param array{
     *   to: string|list<string>, subject: string,
     *   from?: string, fromName?: string, replyTo?: string,
     *   data?: array{message?: string},
     *   attachments?: list<array{filename: string, content: string}>
     * } $options `attachments[].content` es el binario CRUDO; el base64 lo
     *            hace esta función (D5 de context/57 — el KuDE va adjunto).
     * @return true|string `true`, o el motivo del fallo listo para loguear.
     */
    public static function sendEmails(mixed $options): mixed
    {
        $from     = iftn($options['from'] ?? '', EMAIL_FROM);
        $fromName = iftn($options['fromName'] ?? '', APP_NAME);
        $to       = $options['to'];
        $subject  = $options['subject'];
        $data     = $options['data']['message'] ?? '';

        // Misma precedencia que tenía Mailgun (context/34 F6 §3): lo que el
        // admin cargue en platform_config le gana al env.
        require_once __DIR__ . '/../../Admin/PlatformConfig.php';
        $cfg = \PlatformConfig::get('integration.resend', [
            'key'  => defined('RESEND_API_KEY') ? RESEND_API_KEY : '',
            'from' => $from,
        ]);
        $apiKey = (string) ($cfg['key'] ?? '');
        $from   = (string) ($cfg['from'] ?? '') ?: $from;

        if ($apiKey === '' || $from === '') {
            // Sin credenciales NO se lanza: hay call-sites legacy que mandan
            // mail como efecto lateral de una venta, y una venta no se puede
            // caer porque falte configurar el correo.
            return 'Email no configurado (falta RESEND_API_KEY o EMAIL_FROM).';
        }

        $payload = [
            'from'    => sprintf('%s <%s>', $fromName, $from),
            'to'      => is_array($to) ? array_values($to) : [(string) $to],
            'subject' => toUTF8($subject),
            'html'    => toUTF8($data),
        ];
        if (!empty($options['replyTo'])) {
            $payload['reply_to'] = (string) $options['replyTo'];
        }
        foreach (($options['attachments'] ?? []) as $att) {
            if (!is_array($att) || ($att['filename'] ?? '') === '' || !isset($att['content'])) {
                continue;
            }
            $payload['attachments'][] = [
                'filename' => (string) $att['filename'],
                'content'  => base64_encode((string) $att['content']),
            ];
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return 'Error de red al enviar el correo: ' . $err;
        }
        if ($status < 200 || $status >= 300) {
            // El cuerpo de Resend trae el motivo real (dominio sin verificar,
            // destinatario inválido). Truncado: esto va a un log.
            return 'Resend respondió ' . $status . ': ' . substr((string) $raw, 0, 300);
        }

        return true;
    }

    /**
     * Envía un email vía API interna (legacy). Equivalente legacy: `sendEmail($options)`.
     */
    public static function sendEmail(mixed $options): mixed
    {
        $data = [
            'api_key'   => API_KEY,
            'company_id' => enc(COMPANY_ID),
            'fromName'  => $options['fromName'],
            'to'        => $options['to'],
            'subject'   => $options['subject'],
            'mode'      => 'notify',
            'autoSend'  => $options['auto'],
            'secret'    => NCM_SECRET,
        ];

        return curlContents(API_URL . '/send_email', 'POST', $data);
    }

    /**
     * Envía un SMS vía API interna. Equivalente legacy: `sendSMS($number, $msg, ...)`.
     */
    public static function sendSMS(mixed $number, mixed $msg, bool $numvalidation = true, bool $auto = false): mixed
    {
        $data = [
            'api_key'   => API_KEY,
            'company_id' => enc(COMPANY_ID),
            'phone'     => $number,
            'country'   => COUNTRY_CODE,
            'msg'       => $msg,
            'credit'    => SMS_CREDIT,
            'autoSend'  => $auto,
            'secret'    => NCM_SECRET,
        ];

        return curlContents(API_URL . '/send_sms', 'POST', $data);
    }

    /**
     * Envía una push notification. Equivalente legacy: `sendPush($options)`.
     */
    public static function sendPush(mixed $options): mixed
    {
        $companyId = !empty($options['companyId']) ? $options['companyId'] : null;
        $options['where'] = $options['where'] ? $options['where'] : 'caja';

        $data = [
            'api_key'    => API_KEY,
            'company_id' => iftn($companyId, enc(COMPANY_ID)),
            'secret'     => NCM_SECRET,
            'ids'        => $options['ids'],
            'message'    => $options['message'],
            'where'      => $options['where'],
            'title'      => $options['title'],
            'web_url'    => $options['web_url'] ?? '',
            'app_url'    => $options['app_url'] ?? '',
            'filters'    => json_encode($options['filters']),
        ];

        return json_decode(curlContents(API_URL . '/send_push', 'POST', $data));
    }

    /**
     * Envía un email vía SMTP de SendGrid.
     * Equivalente legacy: `sendSMTPEmail($meta, $template, $to, $subject, ...)`.
     */
    public static function sendSMTP(
        mixed $meta,
        mixed $template,
        mixed $to,
        mixed $subject,
        mixed $body    = APP_NAME,
        mixed $altbody = APP_NAME
    ): mixed {
        if (!validity($to, 'email')) {
            return false;
        }

        $fromName = APP_NAME;
        $replayTo = iftn(OUTLET_EMAIL, EMAIL_FROM);
        $from     = EMAIL_FROM;

        $mail = new \PHPMailer(true);

        $options = json_encode([
            'to'      => [$to],
            'sub'     => $meta,
            'filters' => [
                'templates' => [
                    'settings' => [
                        'enable'      => 1,
                        'template_id' => $template,
                    ],
                ],
            ],
        ]);

        $mail->isSMTP();
        $mail->Host       = 'smtp.sendgrid.net';
        $mail->SMTPAuth   = true;
        $mail->Username   = SENDGRID_SMTP_USER;
        $mail->Password   = SENDGRID_SMTP_PASS;
        $mail->Port       = 587;
        $mail->SMTPSecure = 'tls';

        $mail->setFrom($from, $fromName);
        $mail->addReplyTo($replayTo, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->msgHTML($body);
        $mail->addCustomHeader('X-SMTPAPI: ' . $options);
        $mail->addCustomHeader('MIME-Version: 1.0');
        $mail->addCustomHeader('Content-Type: text/html; charset=utf-8');

        $mail->Subject = utf8_decode($subject);
        $mail->Body    = $altbody;

        if (!$mail->send()) {
            return $mail->ErrorInfo;
        }

        return true;
    }

    /**
     * Envía un SMS vía NCM (sistema interno).
     * Equivalente legacy: `sendNCMSMS($number, $msg, $country, $companyId)`.
     */
    public static function sendNCMSMS(mixed $number, mixed $msg, mixed $country, mixed $companyId = ''): array
    {
        $data = [
            'api_key'    => NCM_SMS_API_KEY,
            'company_id' => NCM_SMS_COMPANY_ID,
            'phone'      => $number,
            'country'    => $country,
            'msg'        => $msg,
            'credit'     => 100,
            'secret'     => NCM_SECRET,
        ];

        $sent = curlContents(API_URL . '/send_sms', 'POST', $data);

        return [$sent, $number];
    }

    /**
     * Envía un evento WebSocket al servidor ws-server interno.
     * Equivalente legacy: `sendWS($ops)`.
     */
    public static function sendWS(array $ops = []): mixed
    {
        // FIX PG/dev-server: directo a Redis via wsPublish() — elimina la llamada
        // curl a send_webSocket.php que deadlockeaba el servidor built-in.
        require_once __DIR__ . '/../includes/ws_publish.php';

        $channel = (string) ($ops['channel'] ?? '');
        $event   = (string) ($ops['event']   ?? '');
        $message = (string) ($ops['message'] ?? '');

        // Replicar la lógica de send_webSocket.php: si message es JSON, desempaquetar;
        // si es escalar, envolver en ['message' => valor] igual que el endpoint previo.
        $data = [];
        if ($message !== '') {
            $decoded = json_decode($message, true);
            $data = is_array($decoded) ? $decoded : ['message' => $message];
        }

        wsPublish($channel, $event, $data);
        return true;
    }
}
