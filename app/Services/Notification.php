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
     * Envía un email vía Mailgun. Equivalente legacy: `sendEmails($options)`.
     */
    public static function sendEmails(mixed $options): mixed
    {
        $from     = iftn($options['from'] ?? '', EMAIL_FROM);
        $fromName = iftn($options['fromName'] ?? '', APP_NAME);
        $to       = $options['to'];
        $subject  = $options['subject'];
        $data     = $options['data']['message'] ?? '';

        $mgClient = \MailgunClient::create(MAILGUN_TOKEN);
        $domain   = MAILGUN_DOMAIN;

        try {
            $resultMail = $mgClient->messages()->send($domain, [
                'from'    => $fromName . '<' . $from . '>',
                'to'      => $to,
                'subject' => toUTF8($subject),
                'html'    => toUTF8($data),
            ]);

            if ($resultMail->getId()) {
                return true;
            }

            return 'No se pudo enviar el correo.';
        } catch (\Exception $e) {
            return 'Error al enviar el correo: ' . $e->getMessage();
        }
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
     * Envía un email vía SMTP de SendGrid (legacy, credenciales hardcodeadas).
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
        $mail->Username   = 'incomeregister';
        $mail->Password   = 'Holasendgrid1!';
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
     * Envía un SMS vía NCM (sistema interno con credenciales fijas).
     * Equivalente legacy: `sendNCMSMS($number, $msg, $country, $companyId)`.
     */
    public static function sendNCMSMS(mixed $number, mixed $msg, mixed $country, mixed $companyId = ''): array
    {
        $data = [
            'api_key'    => '340f3033a868ce57b9300f6e1e3732e272639bdf',
            'company_id' => 'Og',
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
        $data = [
            'api_key'    => API_KEY,
            'company_id' => enc(COMPANY_ID),
            'channel'    => $ops['channel'],
            'event'      => $ops['event'],
            'message'    => $ops['message'],
        ];

        return curlContents(API_URL . '/send_webSocket.php', 'POST', $data);
    }
}
