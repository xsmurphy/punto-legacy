<?php
declare(strict_types=1);

namespace Punto\Api\Imports;

final class ImportSession
{
    public const TTL_SECONDS = 3600;
    private const KEY_PREFIX = 'import:session:';

    private static function redisConfig(): array
    {
        if (!empty($_ENV['REDIS_URL'])) {
            $ru   = parse_url((string) $_ENV['REDIS_URL']);
            $host = $ru['host'] ?? '127.0.0.1';
            $port = (int) ($ru['port'] ?? 6379);
            $pass = isset($ru['pass']) ? urldecode($ru['pass']) : '';
        } else {
            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $pass = (string) ($_ENV['REDIS_PASSWORD'] ?? '');
        }
        return [$host, $port, $pass];
    }

    private static function redisRespCmd(string ...$parts): string
    {
        $out = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }
        return $out;
    }

    private static function redisReadReply($sock): mixed
    {
        $line = fgets($sock);
        if ($line === false) return null;
        $type    = $line[0];
        $payload = substr($line, 1, -2);
        switch ($type) {
            case '+': return $payload;
            case '-': return null;
            case ':': return (int) $payload;
            case '$':
                $len = (int) $payload;
                if ($len < 0) return null;
                $data = '';
                while (strlen($data) < $len) {
                    $chunk = fread($sock, $len - strlen($data));
                    if ($chunk === false || $chunk === '') break;
                    $data .= $chunk;
                }
                fread($sock, 2);
                return $data;
            default: return null;
        }
    }

    private static function redisCmd(string ...$args): mixed
    {
        [$host, $port, $pass] = self::redisConfig();
        $errno = 0; $errstr = '';
        $sock  = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            error_log('[ImportSession] No se pudo conectar a Redis');
            return null;
        }
        stream_set_timeout($sock, 2);
        if ($pass !== '') {
            fwrite($sock, self::redisRespCmd('AUTH', $pass));
            $auth = self::redisReadReply($sock);
            if ($auth !== 'OK') { fclose($sock); return null; }
        }
        fwrite($sock, self::redisRespCmd(...$args));
        $reply = self::redisReadReply($sock);
        fclose($sock);
        return $reply;
    }

    public static function create(
        string $companyId,
        string $userId,
        string $csvPath,
        string $originalFilename,
        array  $columns,
        int    $rowCount,
        array  $sample
    ): string {
        $sessionId = bin2hex(random_bytes(16));
        $key       = self::KEY_PREFIX . $sessionId;
        $data      = json_encode([
            'companyId'        => $companyId,
            'userId'           => $userId,
            'csvPath'          => $csvPath,
            'originalFilename' => $originalFilename,
            'columns'          => $columns,
            'rowCount'         => $rowCount,
            'sample'           => $sample,
            'createdAt'        => time(),
        ]);
        self::redisCmd('SETEX', $key, (string) self::TTL_SECONDS, (string) $data);
        return $sessionId;
    }

    public static function get(string $sessionId, string $companyId): ?array
    {
        $key  = self::KEY_PREFIX . $sessionId;
        $raw  = self::redisCmd('GET', $key);
        if ($raw === null) return null;
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) return null;
        if (($data['companyId'] ?? '') !== $companyId) return null;
        return $data;
    }

    public static function delete(string $sessionId): void
    {
        self::redisCmd('DEL', self::KEY_PREFIX . $sessionId);
    }

    public static function run(
        string  $sessionId,
        string  $kind,
        ?array  $mapping,
        string  $mode,
        string  $companyId,
        string  $userId
    ): array {
        $session = self::get($sessionId, $companyId);
        if ($session === null) {
            return ['ok' => false, 'error' => 'Sesión no encontrada o expirada'];
        }

        $csvPath = (string) ($session['csvPath'] ?? '');
        if (!file_exists($csvPath)) {
            self::delete($sessionId);
            return ['ok' => false, 'error' => 'Archivo temporal no encontrado'];
        }

        $workPath = $csvPath;
        if ($mapping !== null && count($mapping) > 0) {
            $workPath = $csvPath . '-mapped.csv';
            self::rewriteWithMapping($csvPath, $workPath, $mapping);
        }

        global $db;

        try {
            if ($kind === 'items') {
                $fileContents = file_get_contents($workPath);
                if ($fileContents === false) {
                    return ['ok' => false, 'error' => 'No se pudo leer el archivo'];
                }
                $svc      = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));
                $importer = new \Punto\Api\Items\ItemImporter($svc, $db);
                $report   = $importer->import($fileContents, $companyId, $mode);
            } elseif ($kind === 'contacts') {
                $importer = new \Punto\Api\Contacts\ContactImporter($db);
                $report   = $importer->importFromCsv($workPath, $mode, $companyId, $userId);
            } else {
                return ['ok' => false, 'error' => "kind inválido: {$kind}"];
            }
        } finally {
            if (file_exists($csvPath)) @unlink($csvPath);
            if ($workPath !== $csvPath && file_exists($workPath)) @unlink($workPath);
            self::delete($sessionId);
        }

        return ['ok' => true, 'report' => $report];
    }

    private static function rewriteWithMapping(string $srcPath, string $destPath, array $mapping): void
    {
        $in  = fopen($srcPath, 'r');
        $out = fopen($destPath, 'w');
        if (!$in || !$out) return;

        $originalHeaders = fgetcsv($in);
        if (!$originalHeaders) { fclose($in); fclose($out); return; }

        $origIdx = [];
        foreach ($originalHeaders as $i => $h) {
            $origIdx[trim(strtoupper((string) $h))] = $i;
        }

        $canonicalHeaders = array_keys($mapping);
        fputcsv($out, $canonicalHeaders);

        while (($row = fgetcsv($in)) !== false) {
            $newRow = [];
            foreach ($mapping as $canonical => $original) {
                $origKey  = trim(strtoupper((string) $original));
                $idx      = $origIdx[$origKey] ?? null;
                $newRow[] = $idx !== null ? ($row[$idx] ?? '') : '';
            }
            fputcsv($out, $newRow);
        }

        fclose($in);
        fclose($out);
    }
}
