<?php

declare(strict_types=1);

namespace Punto\Api\Ai;

/**
 * HelpEmbedder — vectoriza texto con OpenRouter (`POST /api/v1/embeddings`,
 * formato OpenAI). Ver context/82 D5.
 *
 * El modelo queda FIJO por índice y se guarda en cada fila: vectores de
 * modelos distintos no son comparables, y mezclarlos devuelve resultados sin
 * sentido SIN dar ningún error. Por eso el servicio, antes de indexar,
 * compara el modelo configurado contra el de las filas existentes y avisa que
 * hay que reindexar todo en vez de agregar vectores incompatibles.
 *
 * El costo lo absorbe Punto (D10): esta clase no toca `ai_credit_ledger` ni
 * conoce a ningún tenant.
 */
final class HelpEmbedder
{
    /**
     * Último recurso si nadie pasa modelo. Lo normal es que el modelo llegue
     * de `RAG_EMBEDDING_MODEL` (via `HelpKbService`); esta constante solo
     * evita que la clase quede sin default propio. 1536 dimensiones, que es lo
     * que declara el schema.
     */
    public const DEFAULT_MODEL = 'openai/text-embedding-3-small';

    /** Dimensiones que espera la columna `embedding VECTOR(1536)`. */
    public const DIMENSIONS = 1536;

    /** Tope de textos por request — lotes grandes multiplican el riesgo de timeout. */
    private const BATCH_SIZE = 32;

    private string $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model !== null && trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Resuelve la API key igual que AiAdminService: la constante si el caller
     * cargó `includes/simple.config.php`, y si no el entorno.
     *
     * Los endpoints de /admin NO pasan por `bootstrap.php`, así que
     * `defined('OPENROUTER_API_KEY')` da false aunque la clave esté presente en
     * el contenedor — ya mordió una vez (2026-09-08, el botón "Probar" de
     * /admin/ai reportaba "no configurada"). El fallback es el arreglo de raíz:
     * la clase no depende de qué archivo cargó quien la llama.
     */
    public static function apiKey(): string
    {
        if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') {
            return (string) OPENROUTER_API_KEY;
        }
        return (string) ($_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '');
    }

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '';
    }

    /**
     * Vectoriza una lista de textos. Devuelve los vectores EN EL MISMO ORDEN
     * que entraron — el llamador los aparea por posición con sus fragmentos.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     * @throws \RuntimeException si la clave falta, la red falla o la respuesta
     *         no trae la cantidad o la dimensión esperadas.
     */
    public function embedAll(array $texts): array
    {
        if (!$texts) {
            return [];
        }

        $apiKey = self::apiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('OPENROUTER_API_KEY no configurada en el server');
        }

        $out = [];
        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            foreach ($this->requestBatch($batch, $apiKey) as $vector) {
                $out[] = $vector;
            }
        }

        if (count($out) !== count($texts)) {
            throw new \RuntimeException(
                'El proveedor devolvió ' . count($out) . ' vectores para ' . count($texts) . ' textos'
            );
        }

        return $out;
    }

    /**
     * @param list<string> $batch
     * @return list<list<float>>
     */
    private function requestBatch(array $batch, string $apiKey): array
    {
        $payload = json_encode([
            'model' => $this->model,
            'input' => array_values($batch),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://openrouter.ai/api/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Error de red al vectorizar: ' . $curlErr);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta no-JSON del proveedor de vectores (HTTP ' . $httpCode . ')');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('El proveedor de vectores rechazó la petición: ' . (string) $msg);
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta del proveedor sin datos de vectores');
        }

        // El formato OpenAI trae `index` por fila y NO garantiza el orden del
        // array. Se reordena explícitamente: aparear por posición del array
        // mezclaría el vector de un fragmento con el texto de otro, y eso no
        // da ningún error — solo búsquedas que devuelven cualquier cosa.
        $byIndex = [];
        foreach ($data as $i => $row) {
            if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
                throw new \RuntimeException('Fila de vector malformada en la respuesta del proveedor');
            }
            $idx = isset($row['index']) ? (int) $row['index'] : (int) $i;
            $vec = array_map(static fn ($v): float => (float) $v, array_values($row['embedding']));
            if (count($vec) !== self::DIMENSIONS) {
                throw new \RuntimeException(
                    'El modelo devolvió ' . count($vec) . ' dimensiones y el índice espera ' . self::DIMENSIONS
                );
            }
            $byIndex[$idx] = $vec;
        }
        ksort($byIndex);

        return array_values($byIndex);
    }

    /**
     * Serializa un vector al literal que entiende pgvector: `[0.1,0.2,…]`.
     *
     * @param list<float> $vector
     */
    public static function toVectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.8F', $v), '0'), '.') ?: '0',
            $vector
        )) . ']';
    }
}
