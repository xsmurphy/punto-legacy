<?php

/**
 * WhatsAppSender.php — único punto de salida de WhatsApp de la API.
 *
 * Envía por Evolution API (`POST {url}/message/sendText/{instance}`), que es
 * el canal que ya usa el OTP del signup. Este archivo NO agrega un canal
 * nuevo: EXTRAE el que estaba inline en `api/v1/signup/start.php` para que el
 * segundo caller (los avisos de vencimiento de plan, P2 de
 * context/34-admin-saas-plan.md §F7) no lo copie.
 *
 * Copiar el bloque de curl habría dejado dos lugares donde arreglar el
 * timeout, la precedencia platform_config→env y el manejo de "no configurada".
 * Con el wrapper, `start.php` y el job comparten exactamente el mismo camino.
 *
 * Precedencia de credenciales (context/34 F6 §3, la misma de siempre): si el
 * admin guardó `integration.evolution` en `platform_config`, gana entero sobre
 * las constantes de `simple.config.php`.
 *
 * Convención de teléfono del proyecto: Evolution recibe E.164 SIN el '+'
 * inicial, igual que `contact.contactPhone` en storage. `send()` acepta con o
 * sin '+' y normaliza — ningún caller tiene que acordarse.
 *
 * NUNCA lanza excepción: devuelve un resultado. Un aviso que no sale no puede
 * tumbar la venta del signup ni la corrida cross-tenant de un job.
 */

declare(strict_types=1);

namespace Punto\Api\Notify;

final class WhatsAppSender
{
    /** Mismo timeout que tenía el envío inline del OTP. */
    private const TIMEOUT_SECONDS = 8;

    /**
     * @return array{url: string, instance: string, key: string}
     */
    public static function config(): array
    {
        require_once __DIR__ . '/../Admin/PlatformConfig.php';

        $cfg = \PlatformConfig::get('integration.evolution', [
            'url'      => defined('EVOLUTION_API_URL') ? EVOLUTION_API_URL : '',
            'instance' => defined('EVOLUTION_INSTANCE') ? EVOLUTION_INSTANCE : '',
            'key'      => defined('EVOLUTION_API_KEY') ? EVOLUTION_API_KEY : '',
        ]);

        return [
            'url'      => rtrim((string) ($cfg['url'] ?? ''), '/'),
            'instance' => (string) ($cfg['instance'] ?? ''),
            'key'      => (string) ($cfg['key'] ?? ''),
        ];
    }

    /** ¿Hay credenciales completas para enviar? */
    public static function isConfigured(): bool
    {
        $c = self::config();

        return $c['url'] !== '' && $c['instance'] !== '' && $c['key'] !== '';
    }

    /**
     * Envía un texto. Devuelve ['ok'=>bool, 'status'=>int, 'error'=>?string].
     *
     * `status` es el HTTP de Evolution (0 si ni se intentó o el transporte
     * falló) — `start.php` lo necesita para distinguir "no configurada" (500
     * de configuración) de "el número no existe" (error del proveedor).
     *
     * @return array{ok: bool, status: int, error: ?string}
     */
    public static function send(string $phoneE164, string $text): array
    {
        $cfg = self::config();
        if ($cfg['url'] === '' || $cfg['instance'] === '' || $cfg['key'] === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Evolution API no configurada'];
        }

        $phone = ltrim(trim($phoneE164), '+');
        if ($phone === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'teléfono vacío'];
        }

        $ch = curl_init($cfg['url'] . '/message/sendText/' . $cfg['instance']);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'curl_init falló'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['number' => $phone, 'text' => $text], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $cfg['key']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
        ]);
        curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'error' => null];
        }

        return [
            'ok'     => false,
            'status' => $status,
            'error'  => $curlErr !== '' ? $curlErr : ('HTTP ' . $status),
        ];
    }
}
