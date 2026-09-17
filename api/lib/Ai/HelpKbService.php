<?php

declare(strict_types=1);

namespace Punto\Api\Ai;

require_once __DIR__ . '/HelpChunker.php';
require_once __DIR__ . '/HelpEmbedder.php';
require_once __DIR__ . '/../../database/rag_pdo_connect.php';

/**
 * La base del RAG no está disponible (no configurada, o no responde).
 *
 * Es una situación ESPERADA, no un bug: la base pgvector es un recurso aparte
 * que puede estar reiniciando o todavía sin crear. El endpoint la traduce a un
 * mensaje de pantalla; nada más se cae por esto.
 */
final class HelpKbUnavailable extends \RuntimeException
{
    /**
     * true = la base NUNCA se configuró (falta `RAG_DATABASE_URL`), que es el
     * estado normal hasta que se cree en Coolify. false = está configurada
     * pero no respondió. La pantalla los cuenta distinto: uno es "todavía no
     * la armaste", el otro es "volvé a intentar".
     */
    public function __construct(string $message, public readonly bool $notConfigured = false)
    {
        parent::__construct($message);
    }
}

/**
 * HelpKbService — base de conocimiento de Punto AI: documentos cargados desde
 * /admin y sus fragmentos vectorizados.
 * Ver context/82-base-de-conocimiento-punto-ai.md (R1).
 *
 * ── Dónde vive el dato ─────────────────────────────────────────────────────
 *
 * En la base pgvector APARTE (D4), NO en la de los tenants. Por eso esta clase
 * usa su propio PDO (`ragConnectFromEnv`) y no `global $db`: el wrapper de la
 * app apunta a la base de los comercios, y una consulta de acá contra esa base
 * sería justo lo que D4 decidió que no pasara. No hay `companyId` en ninguna
 * fila: el contenido es global y cualquier comercio lo puede leer a través del
 * bot.
 *
 * ── Idempotencia ───────────────────────────────────────────────────────────
 *
 * Cada fragmento guarda el hash del texto que se embebió. Al reindexar, los
 * fragmentos cuyo hash no cambió reusan el vector que ya está en la base:
 * reindexar un documento intacto no le cuesta a Punto una sola llamada al
 * proveedor. El hash es por documento, no global — dos documentos pueden
 * compartir un párrafo y cada uno conserva el suyo.
 *
 * ── Reemplazo ──────────────────────────────────────────────────────────────
 *
 * Volver a cargar un `slug` que ya existe borra sus fragmentos e inserta los
 * nuevos en la MISMA transacción, así el bot nunca lee un documento a medio
 * actualizar. Si el indexado falla (se cayó el proveedor), los fragmentos
 * VIEJOS quedan como estaban: una versión anterior completa y coherente es
 * mejor respuesta que ninguna, el documento queda marcado con el fallo en
 * /admin, y el reintento reusa los vectores en vez de pagarlos de nuevo.
 */
final class HelpKbService
{
    private ?\PDO $pdo = null;

    /**
     * Configuración de texto de Postgres para el `tsvector`. El contenido de
     * esta base lo escribe el equipo de Punto en español; no es un dato del
     * tenant ni depende de su país (que sí sale del bootstrap en el resto del
     * producto).
     */
    private const TS_CONFIG = 'spanish';

    public function __construct(private ?string $model = null)
    {
    }

    /** Modelo de embeddings en uso. FIJO por índice — ver D5. */
    public function model(): string
    {
        return (new HelpEmbedder($this->model ?? ragEmbeddingModel()))->model();
    }

    /**
     * ¿Hay base de conocimiento configurada? Se responde SIN conectarse, para
     * que la pantalla pueda mostrar el estado "todavía no está" sin esperar un
     * timeout de red.
     */
    public function isConfigured(): bool
    {
        return ragIsConfigured(dirname(__DIR__, 2));
    }

    /** @throws HelpKbUnavailable */
    private function db(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        try {
            $this->pdo = ragConnectFromEnv(dirname(__DIR__, 2));
        } catch (\Throwable $e) {
            throw new HelpKbUnavailable($e->getMessage(), $e->getMessage() === 'RAG_NOT_CONFIGURED');
        }
        return $this->pdo;
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * Documentos cargados, con su estado de indexado.
     *
     * @return list<array<string,mixed>>
     */
    public function listDocuments(): array
    {
        $sql = 'SELECT d.documentid, d.slug, d.title, d.rubros, d.isactive,
                       d.fromayuda, d.ayudahash, d.indexedat, d.indexerror,
                       d.updatedat, d.createdat,
                       length(d.body) AS bodylength,
                       (SELECT count(*) FROM help_chunk c WHERE c.documentid = d.documentid) AS chunkcount
                  FROM help_document d
                 ORDER BY d.title ASC';

        $out = [];
        foreach ($this->db()->query($sql) as $row) {
            $out[] = $this->mapDocumentRow($row);
        }
        return $out;
    }

    /** Un documento con su cuerpo completo (para editarlo en /admin). */
    public function getDocument(string $slug): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT documentid, slug, title, body, rubros, isactive, fromayuda, ayudahash,
                    indexedat, indexerror, updatedat, createdat,
                    (SELECT count(*) FROM help_chunk c WHERE c.documentid = help_document.documentid) AS chunkcount
               FROM help_document WHERE slug = ?'
        );
        $stmt->execute([$this->normalizeSlug($slug)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $doc         = $this->mapDocumentRow($row);
        $doc['body'] = (string) ($row['body'] ?? '');
        return $doc;
    }

    /**
     * Estado general del índice: cuántos fragmentos hay, con qué modelo se
     * generaron y si hace falta reindexar todo.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $db       = $this->db();
        $inUse    = $this->model();
        $totals   = $db->query('SELECT count(*) AS chunks, count(DISTINCT documentid) AS docs FROM help_chunk')->fetch() ?: [];
        $models   = [];
        foreach ($db->query('SELECT model, count(*) AS n FROM help_chunk GROUP BY model ORDER BY n DESC') as $row) {
            $models[] = ['model' => (string) $row['model'], 'chunks' => (int) $row['n']];
        }
        $failed = (int) (($db->query('SELECT count(*) AS n FROM help_document WHERE indexerror IS NOT NULL')->fetch() ?: ['n' => 0])['n']);

        // D5: si hay fragmentos generados con OTRO modelo, no se mezclan —
        // comparar vectores de modelos distintos devuelve resultados sin
        // sentido y sin ningún error. Se avisa para reindexar todo.
        $needsReindexAll = false;
        foreach ($models as $m) {
            if ($m['model'] !== $inUse) {
                $needsReindexAll = true;
            }
        }

        return [
            'model'           => $inUse,
            'chunks'          => (int) ($totals['chunks'] ?? 0),
            'indexedDocuments' => (int) ($totals['docs'] ?? 0),
            'models'          => $models,
            'needsReindexAll' => $needsReindexAll,
            'failedDocuments' => $failed,
            'providerReady'   => HelpEmbedder::isConfigured(),
        ];
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * Crea o reemplaza un documento y lo indexa.
     *
     * @param array<string,mixed> $input {slug?, title, body, rubros?, isactive?}
     * @return array<string,mixed>
     */
    public function upsertDocument(array $input, ?string $actingAdminId = null): array
    {
        // `is_string` antes de castear: en PHP 8 un `(string)` sobre un array
        // LANZA, y el 500 resultante puede llevar detalle técnico a la
        // respuesta según el ambiente. Un payload malformado tiene que salir
        // por el 422 de siempre, no por una excepción.
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $body  = is_string($input['body'] ?? null) ? $input['body'] : '';

        if ($title === '') {
            return ['ok' => false, 'error' => 'Poné un título', 'code' => 422];
        }
        if (trim($body) === '') {
            return ['ok' => false, 'error' => 'El documento está vacío', 'code' => 422];
        }

        $rawSlug = is_string($input['slug'] ?? null) ? $input['slug'] : '';
        $slug    = $this->normalizeSlug($rawSlug) ?: $this->normalizeSlug($title);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'No pude armar un identificador con ese título', 'code' => 422];
        }

        $rubros   = HelpChunker::normalizeRubros(is_array($input['rubros'] ?? null) ? $input['rubros'] : []);
        $isActive = array_key_exists('isactive', $input) ? (bool) $input['isactive'] : true;

        $db   = $this->db();
        $stmt = $db->prepare(
            'INSERT INTO help_document (slug, title, body, rubros, isactive, updatedby, updatedat)
                  VALUES (?, ?, ?, ?::text[], ?, ?, now())
             ON CONFLICT (slug) DO UPDATE
                    SET title = EXCLUDED.title,
                        body = EXCLUDED.body,
                        rubros = EXCLUDED.rubros,
                        isactive = EXCLUDED.isactive,
                        updatedby = EXCLUDED.updatedby,
                        updatedat = now()
               RETURNING documentid, (xmax = 0)::int AS inserted'
        );
        $stmt->execute([
            $slug,
            $title,
            $body,
            $this->toPgTextArray($rubros),
            // Booleano como literal de texto: con emulate_prepares=false PDO
            // manda todo como texto y PG infiere el tipo por contexto — 'true'
            // es inequívoco, un 1 depende de que lo lea como booleano.
            $isActive ? 'true' : 'false',
            $this->normalizeUuid($actingAdminId),
        ]);
        $row = $stmt->fetch() ?: [];

        $documentId = (string) ($row['documentid'] ?? '');
        // `::int` y no el booleano crudo: PDO_PGSQL devuelve los booleanos
        // como 't'/'f' según la versión, y `(bool) 'f'` es TRUE — un reemplazo
        // quedaría auditado como alta.
        $created    = (int) ($row['inserted'] ?? 0) === 1;

        $index = $this->indexDocument($documentId);

        return [
            'ok'       => true,
            'slug'     => $slug,
            'created'  => $created,
            'document' => $this->getDocument($slug),
            'index'    => $index,
        ];
    }

    /** Saca (o devuelve) un documento a las búsquedas sin borrarlo. */
    public function toggleDocument(string $slug, bool $isActive): array
    {
        $slug = $this->normalizeSlug($slug);
        $stmt = $this->db()->prepare(
            'UPDATE help_document SET isactive = ?, updatedat = now() WHERE slug = ? RETURNING documentid'
        );
        $stmt->execute([$isActive ? 'true' : 'false', $slug]);
        if (!$stmt->fetch()) {
            return ['ok' => false, 'error' => 'No encontré ese documento', 'code' => 404];
        }
        return ['ok' => true, 'slug' => $slug, 'isactive' => $isActive];
    }

    /** Borra el documento y, por cascada, sus fragmentos. */
    public function deleteDocument(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $stmt = $this->db()->prepare('DELETE FROM help_document WHERE slug = ? RETURNING title');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'No encontré ese documento', 'code' => 404];
        }
        return ['ok' => true, 'slug' => $slug, 'title' => (string) $row['title']];
    }

    /** Fuerza el reprocesado de un documento. */
    public function reindexBySlug(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $stmt = $this->db()->prepare('SELECT documentid FROM help_document WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'No encontré ese documento', 'code' => 404];
        }
        $index = $this->indexDocument((string) $row['documentid']);
        return ['ok' => $index['ok'], 'slug' => $slug, 'index' => $index];
    }

    /**
     * Reprocesa todos los documentos. Es lo que hay que correr cuando cambia
     * el modelo de embeddings (D5): con el modelo nuevo ningún hash reusa
     * vector, así que se re-embebe todo y el índice deja de estar mezclado.
     *
     * ⚠ LÍMITE CONOCIDO: corre SÍNCRONO dentro del request. Con pocos
     * documentos —lo que el owner escribe a mano— entra sobrado, y cada
     * documento se commitea por separado, así que un timeout no deja nada a
     * medias: deja documentos VIEJOS, que siguen respondiendo. Cuando el
     * volumen lo pida, el patrón del proyecto para esto ya existe y es una
     * tabla-cola + proceso CLI (`demo_seed_job`, context/65), no alargar el
     * timeout de PHP.
     */
    public function reindexAll(): array
    {
        $ids = [];
        foreach ($this->db()->query('SELECT documentid, slug FROM help_document ORDER BY title ASC') as $row) {
            $ids[(string) $row['documentid']] = (string) $row['slug'];
        }

        $okCount = 0;
        $errors  = [];
        foreach ($ids as $documentId => $slug) {
            $res = $this->indexDocument($documentId);
            if ($res['ok']) {
                $okCount++;
            } else {
                $errors[] = ['slug' => $slug, 'error' => $res['error'] ?? 'error'];
            }
        }

        return [
            'ok'        => $errors === [],
            'documents' => count($ids),
            'indexed'   => $okCount,
            'errors'    => $errors,
        ];
    }

    // ── Indexado ────────────────────────────────────────────────────────────

    /**
     * Fragmenta, vectoriza lo que haga falta y reemplaza los fragmentos del
     * documento en una sola transacción.
     *
     * Nunca lanza por un fallo del proveedor: lo escribe en `indexerror` y
     * devuelve ok=false. Quien llama ya guardó el texto del owner, que es lo
     * que no se puede perder.
     *
     * @return array<string,mixed>
     */
    public function indexDocument(string $documentId): array
    {
        $db = $this->db();

        $stmt = $db->prepare('SELECT documentid, slug, title, body, rubros FROM help_document WHERE documentid = ?');
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            return ['ok' => false, 'error' => 'No encontré ese documento', 'chunks' => 0];
        }

        $rubros = $this->fromPgTextArray($doc['rubros'] ?? null);
        $chunks = HelpChunker::chunk((string) $doc['title'], (string) $doc['body'], $rubros, null);

        if (!$chunks) {
            // Un documento sin texto aprovechable no es un error del sistema:
            // se deja sin fragmentos y se dice por qué, para que el owner lo
            // vea en la lista en vez de creer que quedó indexado.
            $this->writeIndexResult($documentId, 'El documento no tiene contenido para indexar');
            return ['ok' => false, 'error' => 'El documento no tiene contenido para indexar', 'chunks' => 0];
        }

        $embedder = new HelpEmbedder($this->model ?? ragEmbeddingModel());
        $model    = $embedder->model();

        try {
            // Vectores ya guardados que se pueden reusar: mismo hash Y mismo
            // modelo. El filtro por modelo es la mitad práctica de D5 — sin él,
            // cambiar de modelo dejaría el documento con vectores de los dos.
            $existing = [];
            $sel = $db->prepare('SELECT contenthash, embedding::text AS embedding FROM help_chunk WHERE documentid = ? AND model = ?');
            $sel->execute([$documentId, $model]);
            foreach ($sel as $row) {
                $existing[(string) $row['contenthash']] = (string) $row['embedding'];
            }

            $missingTexts   = [];
            $missingIndexes = [];
            foreach ($chunks as $i => $chunk) {
                if (!isset($existing[$chunk['contentHash']])) {
                    $missingTexts[]   = $chunk['embedText'];
                    $missingIndexes[] = $i;
                }
            }

            $vectors = $missingTexts ? $embedder->embedAll($missingTexts) : [];

            $literals = [];
            foreach ($chunks as $i => $chunk) {
                $literals[$i] = $existing[$chunk['contentHash']] ?? null;
            }
            foreach ($missingIndexes as $k => $i) {
                $literals[$i] = HelpEmbedder::toVectorLiteral($vectors[$k]);
            }

            // Reemplazo atómico: el bot nunca ve el documento a medio cambiar.
            $db->beginTransaction();
            try {
                $del = $db->prepare('DELETE FROM help_chunk WHERE documentid = ?');
                $del->execute([$documentId]);

                // El `tsvector` se calcula ACÁ, al insertar, y no en una columna
                // generada ni en un índice de expresión: `unaccent()` no es
                // IMMUTABLE y Postgres rechaza las dos formas.
                $ins = $db->prepare(
                    'INSERT INTO help_chunk
                        (documentid, slug, title, headingpath, audiencia, rubros, content,
                         contenthash, model, embedding, search, position, indexedat)
                     VALUES (?, ?, ?, ?, NULL, ?::text[], ?, ?, ?, ?::vector,
                             to_tsvector(?::regconfig, unaccent(?)), ?, now())'
                );

                $rubrosLiteral = $this->toPgTextArray($rubros);
                foreach ($chunks as $i => $chunk) {
                    $ins->execute([
                        $documentId,
                        (string) $doc['slug'],
                        (string) $doc['title'],
                        $chunk['headingPath'],
                        $rubrosLiteral,
                        $chunk['content'],
                        $chunk['contentHash'],
                        $model,
                        $literals[$i],
                        self::TS_CONFIG,
                        $chunk['embedText'],
                        $chunk['position'],
                    ]);
                }

                $db->prepare('UPDATE help_document SET indexedat = now(), indexerror = NULL WHERE documentid = ?')
                   ->execute([$documentId]);

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            return [
                'ok'       => true,
                'chunks'   => count($chunks),
                'embedded' => count($missingTexts),
                'reused'   => count($chunks) - count($missingTexts),
                'model'    => $model,
            ];
        } catch (\Throwable $e) {
            // Los fragmentos viejos quedan intactos: una versión anterior
            // completa responde mejor que un vacío, y el reintento vuelve a
            // reusar sus vectores en vez de pagarlos de nuevo. El fallo se ve
            // en la pantalla de /admin.
            $this->writeIndexResult($documentId, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage(), 'chunks' => 0];
        }
    }

    private function writeIndexResult(string $documentId, ?string $error): void
    {
        try {
            $this->db()
                ->prepare('UPDATE help_document SET indexerror = ? WHERE documentid = ?')
                ->execute([$error !== null ? mb_substr($error, 0, 500) : null, $documentId]);
        } catch (\Throwable $e) {
            error_log('[HelpKbService] no pude registrar el error de indexado: ' . $e->getMessage());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Booleano de Postgres a booleano de PHP.
     *
     * PDO_PGSQL puede devolver 't'/'f' en vez de true/false, y `(bool) 'f'` es
     * TRUE: un documento DESACTIVADO se mostraría como activo en la lista.
     */
    private static function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $v = strtolower(trim((string) $raw));
        return !in_array($v, ['f', 'false', '0', '', 'no'], true);
    }

    /** @param array<string,mixed> $row */
    private function mapDocumentRow(array $row): array
    {
        return [
            'documentId' => (string) ($row['documentid'] ?? ''),
            'slug'       => (string) ($row['slug'] ?? ''),
            'title'      => (string) ($row['title'] ?? ''),
            'rubros'     => $this->fromPgTextArray($row['rubros'] ?? null),
            'isActive'   => self::toBool($row['isactive'] ?? true),
            'fromAyuda'  => isset($row['fromayuda']) ? (string) $row['fromayuda'] : null,
            'chunkCount' => (int) ($row['chunkcount'] ?? 0),
            'indexedAt'  => $row['indexedat'] ?? null,
            'indexError' => isset($row['indexerror']) ? (string) $row['indexerror'] : null,
            'updatedAt'  => $row['updatedat'] ?? null,
            'createdAt'  => $row['createdat'] ?? null,
            'length'     => (int) ($row['bodylength'] ?? 0),
        ];
    }

    /**
     * Identificador estable del documento. Sin acentos ni espacios: es la
     * clave con la que se reemplaza, así que "Impresoras de cocina" y
     * "impresoras de cocina" tienen que caer en la misma fila.
     */
    public function normalizeSlug(string $raw): string
    {
        $slug = trim($raw);
        if ($slug === '') {
            return '';
        }
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        if (is_string($transliterated) && $transliterated !== '') {
            $slug = $transliterated;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return substr($slug, 0, 120);
    }

    private function normalizeUuid(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) ? $raw : null;
    }

    /**
     * Literal de array de texto de Postgres. Se arma acá y se manda como
     * parámetro con cast `::text[]` — nunca interpolado en el SQL.
     *
     * @param list<string> $values
     */
    private function toPgTextArray(array $values): string
    {
        if (!$values) {
            return '{}';
        }
        $escaped = array_map(
            static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
            $values
        );
        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * Parsea el literal `{a,b}` que devuelve PDO para una columna TEXT[].
     *
     * @return list<string>
     */
    private function fromPgTextArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return HelpChunker::normalizeRubros($raw);
        }
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '{}') {
            return [];
        }
        $inner = substr($raw, 1, -1);
        $out   = [];
        foreach (str_getcsv($inner, ',', '"', '\\') as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }
}
