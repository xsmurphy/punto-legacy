<?php
declare(strict_types=1);

namespace Punto\Api\Storage;

/**
 * Minimal cliente S3-compatible (PUT/DELETE) usando AWS Signature V4.
 *
 * Sin dependencias — usa cURL + hash_hmac. Pensado para DO Spaces y AWS S3.
 *
 * Construcción típica:
 *   new S3Client('https://nyc3.digitaloceanspaces.com', 'us-east-1', 'ncmaspace', $key, $secret)
 *
 * URLs públicas: <endpoint>/<bucket>/<objectKey>. Para DO Spaces el formato
 * virtual-host (`https://<bucket>.nyc3.digitaloceanspaces.com/<key>`) también
 * funciona — `publicUrl()` devuelve el formato path-style que es universal.
 *
 * No se soporta GET via signed URL — para imágenes public-read no hace falta.
 */
final class S3Client
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $key;
    private string $secret;
    private const SERVICE = 's3';

    public function __construct(string $endpoint, string $region, string $bucket, string $key, string $secret)
    {
        if ($endpoint === '' || $bucket === '' || $key === '' || $secret === '') {
            throw new \InvalidArgumentException('S3Client: endpoint/bucket/key/secret requeridos');
        }
        $this->endpoint = rtrim($endpoint, '/');
        $this->region   = $region !== '' ? $region : 'us-east-1';
        $this->bucket   = $bucket;
        $this->key      = $key;
        $this->secret   = $secret;
    }

    /**
     * Sube un objeto. Devuelve URL pública (path-style).
     * $publicRead → x-amz-acl: public-read (anyone con la URL puede leer).
     */
    public function put(string $objectKey, string $body, string $contentType = 'application/octet-stream', bool $publicRead = true): string
    {
        $headers = ['content-type' => $contentType];
        if ($publicRead) $headers['x-amz-acl'] = 'public-read';
        $this->signedRequest('PUT', $objectKey, $body, $headers);
        return $this->publicUrl($objectKey);
    }

    public function delete(string $objectKey): void
    {
        $this->signedRequest('DELETE', $objectKey, '', []);
    }

    public function publicUrl(string $objectKey): string
    {
        return $this->endpoint . '/' . $this->bucket . '/' . ltrim($objectKey, '/');
    }

    private function signedRequest(string $method, string $objectKey, string $body, array $extraHeaders): void
    {
        $host        = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $path        = '/' . $this->bucket . '/' . ltrim($objectKey, '/');
        $payloadHash = hash('sha256', $body);
        $now         = gmdate('Ymd\THis\Z');
        $today       = substr($now, 0, 8);
        $scope       = "$today/{$this->region}/" . self::SERVICE . '/aws4_request';

        $headers = array_change_key_case($extraHeaders, CASE_LOWER);
        $headers['host']                 = $host;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date']           = $now;
        ksort($headers);

        $canonicalHeaders = '';
        $signedNames      = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim((string) $v) . "\n";
            $signedNames[]     = $k;
        }
        $signedHeaders = implode(';', $signedNames);

        $canonicalRequest = $method . "\n"
                          . $this->canonicalUri($path) . "\n"
                          . "\n"  // no query string
                          . $canonicalHeaders . "\n"
                          . $signedHeaders . "\n"
                          . $payloadHash;

        $stringToSign = "AWS4-HMAC-SHA256\n"
                      . $now . "\n"
                      . $scope . "\n"
                      . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $today, 'AWS4' . $this->secret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSign    = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSign);

        $authorization = "AWS4-HMAC-SHA256 "
                       . "Credential={$this->key}/$scope, "
                       . "SignedHeaders=$signedHeaders, "
                       . "Signature=$signature";

        $curlHeaders = ['Authorization: ' . $authorization];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "$k: $v";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException("S3 $method falló (HTTP $status): " . ($err !== '' ? $err : (string) $response));
        }
    }

    private function canonicalUri(string $path): string
    {
        // Encode cada segmento sin tocar los '/'.
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
