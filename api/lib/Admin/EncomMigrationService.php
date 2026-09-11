<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomClient.php';
require_once __DIR__ . '/EncomMigrationException.php';

/**
 * Ciclo de vida de un job de migración ENCOM → Punto (context/77).
 *
 * Esta clase NO importa nada: crea el job, lo lista, lo entrega al worker y
 * registra su resultado. El import vive en `EncomImportService`, que corre en
 * el proceso del worker. La separación es la que hace que el endpoint de
 * /admin pueda contestar en milisegundos mientras el export tarda minutos.
 *
 * ── Por qué cola y no inline (D3) ───────────────────────────────────────
 * Dos razones INDEPENDIENTES, y conviene no confundirlas porque la segunda
 * es la que se verificó y la primera resultó ser más acotada de lo que el
 * plan original suponía:
 *
 *   1. **Contexto de tenant por proceso.** `api/data.php` define
 *      `COMPANY_ID` / `OUTLET_ID` / `TODAY` con `define()`, o sea UNA vez por
 *      proceso PHP. Una request de /admin no está en el contexto del tenant
 *      destino y no puede entrar en él sin envenenárselo a lo que venga
 *      después. (Los SERVICIOS de import sí reciben `$companyId` por
 *      argumento — eso se verificó y es mejor de lo que el plan asumía —,
 *      pero el contexto global que necesitan igual se define una sola vez.)
 *   2. **Duración.** El export son decenas de requests HTTP contra el legacy
 *      PACEADAS a 60/min. Un catálogo mediano ya son minutos de pared: muy
 *      por encima de cualquier timeout de PHP-FPM. Esta razón sola alcanza
 *      para descartar el inline, y es la que no depende de ningún detalle de
 *      implementación de los servicios.
 */
final class EncomMigrationService
{
    /**
     * Dominios que el operador puede pedir.
     *
     * `users` y `payments` existen desde que el export pasa por `/fetchs`
     * (context/77): las pantallas del panel no exponían ni el equipo del
     * comercio ni sus medios de pago, así que la F1 los declaraba no migrables.
     * Los combos y las recetas NO son un dominio aparte: viajan dentro de cada
     * artículo (`compound`) y se importan con `catalog`.
     *
     * `stock` (la APERTURA de inventario) SÍ es un dominio aparte, y va último:
     * necesita el mapa de ARTÍCULOS (lo llena `catalog`) y el de SUCURSALES (lo
     * llena `config`), porque un saldo es un movimiento del ledger por (ítem,
     * sucursal). Meterlo dentro de `catalog` lo dejaría corriendo antes de que
     * las sucursales existieran.
     *
     * ── Los tres dominios de HISTÓRICO (F2) ─────────────────────────────
     * `sales_history`, `purchases_history` y `expenses_history` son TRES y no
     * uno solo por tres razones, no por gusto de separar:
     *
     *   1. **Salen de endpoints distintos del legacy** y con formas distintas
     *      (las ventas necesitan una request POR VENTA para sus líneas; las
     *      compras traen todas las líneas del rango en una).
     *   2. **Escriben cosas distintas**: una venta y una compra son filas de
     *      `transaction` con tipos opuestos, pero un movimiento de caja no es
     *      una transacción en absoluto — va a `expenses`.
     *   3. **El operador tiene que poder pedir uno sin los otros.** Un
     *      comercio que solo quiere sus ventas para los reportes no debería
     *      tener que traerse las compras, y si un dominio falla los otros
     *      deben poder entrar igual.
     *
     * Van DESPUÉS de todo lo demás: un asiento histórico referencia artículos,
     * clientes, usuarios y sucursales, y todos esos mapas los llenan los
     * dominios anteriores.
     */
    public const DOMAINS = [
        'catalog', 'customers', 'config', 'users', 'payments', 'stock',
        'sales_history', 'purchases_history', 'expenses_history',
    ];

    /** Tope de reintentos del drain antes de dar el job por perdido. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Crea el job: hace el login al legacy EN EL MOMENTO (D2) y guarda solo
     * las cookies. Si el login falla, el job NO se crea.
     *
     * `identifier` es lo que el cliente tipea en el campo de usuario del panel
     * legacy: su email o su celular, indistinto. Viaja tal cual — quien
     * resuelve cuál de los dos es, es el legacy (ver `EncomClient::login()`).
     *
     * @param array{identifier:string,password:string} $creds
     * @param array<int,string> $domains
     * @return array{jobId:string}
     */
    public function create(
        string $companyId,
        array $creds,
        array $domains,
        ?string $registerOutletId,
        ?string $createdBy,
        array $extra = []
    ): array {
        global $db;

        $companyId = trim($companyId);
        if ($companyId === '') {
            throw new EncomMigrationException('Falta la empresa destino.', 422);
        }

        $domains = array_values(array_intersect(self::DOMAINS, array_map('strval', $domains)));
        if ($domains === []) {
            throw new EncomMigrationException('Elegí al menos un dominio para migrar.', 422);
        }

        // ── Guard: la empresa destino existe ────────────────────────────
        // Es realm admin (cross-tenant por definición), así que no hay dueño
        // que validar: alcanza con que exista. Lo que NO puede pasar es
        // importar contra un companyId inventado — quedaría un catálogo
        // colgado que nadie ve y que ningún borrado alcanza.
        $company = self::row('SELECT companyId FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        if (!$company) {
            throw new EncomMigrationException('La empresa destino no existe.', 404);
        }

        // ── Guard: un solo job vivo por empresa ─────────────────────────
        // También hay índice único parcial en la base (mig 218). Acá se
        // chequea para dar un mensaje útil en vez de un choque de constraint.
        $alive = self::row(
            "SELECT jobid FROM migration_job
              WHERE companyid = ? AND status IN ('pending','running') LIMIT 1",
            [$companyId]
        );
        if ($alive) {
            throw new EncomMigrationException(
                'Esa empresa ya tiene una migración en curso. Esperá a que termine antes de lanzar otra.',
                409
            );
        }

        $baseUrl = defined('ENCOM_MIGRATION_URL') ? (string) ENCOM_MIGRATION_URL : '';
        if (trim($baseUrl) === '') {
            throw new EncomMigrationException(
                'Falta configurar ENCOM_MIGRATION_URL en el entorno: sin la dirección del panel legacy no se puede migrar.',
                503
            );
        }

        // ── D2: el login ocurre ACÁ y la password muere con esta request ──
        $identifier = trim((string) ($creds['identifier'] ?? ''));
        if ($identifier === '') {
            throw new EncomMigrationException(
                'Falta el usuario del cliente en el sistema legacy (email o celular).',
                422
            );
        }

        $client = EncomClient::login($baseUrl, $identifier, (string) ($creds['password'] ?? ''));

        // Solo las cookies llegan a la base. `$creds['password']` no se toca
        // nunca más y no aparece en ningún log.
        //
        // `scope` es el par (companyId, outletId) DEL LEGACY —hashids cortos,
        // no UUID— que `login()` dedujo de la sesión. Es lo que `/fetchs` pide
        // en el cuerpo de cada request, así que sin él el worker no puede
        // exportar nada. Se guarda junto a las cookies porque tiene la misma
        // vida útil que ellas: se borra cuando el job termina.
        $credentials = [
            'cookies'   => $client->cookies(),
            'scope'     => $client->scope(),
            'issuedAt'  => gmdate('c'),
            'legacyUrl' => $baseUrl,
        ];

        // La sucursal destino de las CAJAS. Ver `EncomImportService::config()`:
        // el legacy solo expone las cajas de su sucursal activa y no dice
        // cuál es, así que la elige el operador o el dominio se saltea.
        //
        // `historyFrom`/`historyTo` acotan el HISTÓRICO. El rango es del
        // operador y no se deduce: el legacy no dice desde cuándo tiene datos,
        // y traer "todo" sobre un comercio viejo son decenas de miles de
        // requests —una por venta— además de años de particiones. Sin rango
        // elegido, el importador toma los últimos 12 meses.
        $options = [
            'registerOutletId' => $registerOutletId ?: null,
            'historyFrom'      => self::fechaOpcional($extra['historyFrom'] ?? null),
            'historyTo'        => self::fechaOpcional($extra['historyTo'] ?? null),
        ];

        $row = self::row(
            'INSERT INTO migration_job (companyid, source, status, domains, credentials, progress, createdby)
             VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?)
             RETURNING jobid',
            [
                $companyId,
                'encom',
                'pending',
                json_encode($domains, JSON_UNESCAPED_UNICODE),
                json_encode($credentials, JSON_UNESCAPED_UNICODE),
                json_encode(['options' => $options], JSON_UNESCAPED_UNICODE),
                $createdBy,
            ]
        );

        $jobId = $row !== null ? (string) ($row['jobid'] ?? '') : '';
        if ($jobId === '') {
            throw new EncomMigrationException('No se pudo crear el job de migración.', 500);
        }

        return ['jobId' => $jobId];
    }

    /**
     * Listado para /admin. NUNCA devuelve `credentials` — solo si hay o no.
     *
     * @return array<int,array>
     */
    public function listJobs(?string $companyId = null, int $limit = 50): array
    {
        global $db;

        $where  = '';
        $params = [];
        if ($companyId !== null && trim($companyId) !== '') {
            $where    = ' WHERE j.companyid = ?';
            $params[] = trim($companyId);
        }

        $limit = $limit > 0 && $limit <= 200 ? $limit : 50;

        $rs = $db->Execute(
            'SELECT j.jobid, j.companyid, j.source, j.status, j.domains, j.progress,
                    j.errors, j.attempts, j.started_at, j.finished_at, j.created_at,
                    (j.credentials IS NOT NULL) AS hascredentials,
                    -- El nombre del comercio vive en el JSONB `config`, no en
                    -- una columna (context/34 §22.8).
                    c.config->>\'settingName\' AS companyname
               FROM migration_job j
               LEFT JOIN company c ON c.companyId = j.companyid'
            . $where .
            ' ORDER BY j.created_at DESC LIMIT ' . $limit,
            $params
        );

        $out = [];
        while ($rs !== false && !$rs->EOF) {
            $out[] = $this->shape($rs->fields);
            $rs->MoveNext();
        }
        return $out;
    }

    /** Detalle de un job, con su bitácora. Tampoco devuelve `credentials`. */
    public function detail(string $jobId): array
    {
        $row = self::row(
            'SELECT j.jobid, j.companyid, j.source, j.status, j.domains, j.progress,
                    j.errors, j.log, j.attempts, j.started_at, j.finished_at, j.created_at,
                    (j.credentials IS NOT NULL) AS hascredentials,
                    c.config->>\'settingName\' AS companyname
               FROM migration_job j
               LEFT JOIN company c ON c.companyId = j.companyid
              WHERE j.jobid = ? LIMIT 1',
            [$jobId]
        );
        if (!$row) {
            throw new EncomMigrationException('Job no encontrado.', 404);
        }

        $shaped        = $this->shape($row);
        $shaped['log'] = $this->jsonField($row, 'log', []);
        return $shaped;
    }

    /**
     * Toma el job pendiente más viejo y lo marca `running`, atómicamente.
     *
     * El `UPDATE ... WHERE status='pending' ... RETURNING` toma el row lock de
     * PG, así que dos drains concurrentes no se llevan el mismo job: el
     * segundo no matchea ninguna fila y devuelve null. Es el mismo mecanismo
     * que usa `DocumentNumber::allocate()` y evita tener que sumar un
     * advisory lock propio.
     *
     * @return array|null El job con sus credenciales (lo necesita el worker).
     */
    public function claimNext(): ?array
    {
        $row = self::row(
            "UPDATE migration_job
                SET status     = 'running',
                    attempts   = attempts + 1,
                    started_at = COALESCE(started_at, now()),
                    updated_at = now()
              WHERE jobid = (
                    SELECT jobid FROM migration_job
                     WHERE status = 'pending' AND attempts < ?
                     ORDER BY created_at
                     FOR UPDATE SKIP LOCKED
                     LIMIT 1
              )
              RETURNING jobid, companyid, domains, credentials, progress",
            [self::MAX_ATTEMPTS]
        );

        return $row ?: null;
    }

    /**
     * Un ciclo del drain: rescata jobs colgados y lanza el siguiente pendiente.
     *
     * Lo invoca `POST /v1/maintenance?job=migration-drain` desde el cron de la
     * imagen. Lanza UN job por ciclo a propósito: dos migraciones en paralelo
     * compiten por el límite de 60 req/min del legacy y las dos se vuelven más
     * lentas que si hubieran corrido en fila.
     *
     * @return array Resumen para el log del maintenance.
     */
    public function drain(): array
    {
        $swept    = $this->sweepStaleCredentials();
        $requeued = $this->requeueStale();

        $job = $this->claimNext();
        if ($job === null) {
            return ['spawned' => null, 'requeued' => $requeued, 'swept' => $swept];
        }

        $jobId     = (string) ($job['jobid'] ?? '');
        $companyId = (string) ($job['companyid'] ?? '');

        $script = dirname(__DIR__, 2) . '/scripts/migration_worker.php';

        // Proceso APARTE y en segundo plano: el drain no puede quedarse
        // esperando minutos de export paceado, y el worker necesita su propio
        // proceso para fijar `COMPANY_ID` (ver el docblock del worker).
        // La salida va a /dev/null porque el estado real del job vive en la
        // fila de `migration_job`; lo que haga falta depurar sale por
        // `error_log` del worker.
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg($script) . ' '
             . escapeshellarg($jobId) . ' '
             . escapeshellarg($companyId)
             . ' > /dev/null 2>&1 &';

        @exec($cmd);

        return ['spawned' => $jobId, 'requeued' => $requeued, 'swept' => $swept];
    }

    /** Vida útil de la sesión del legacy. Pasado esto, la cookie no sirve. */
    public const CREDENTIALS_TTL_HOURS = 24;

    /**
     * Barrido de credenciales huérfanas.
     *
     * `finish()` borra las cookies al cerrar un job, pero eso solo cubre a los
     * que LLEGARON a correr. Un job que nunca fue reclamado —porque faltaba
     * `ENCOM_MIGRATION_URL`, porque el cron estuvo caído, porque el worker
     * murió antes de empezar— se queda con la sesión viva del panel de un
     * cliente guardada en la base para siempre.
     *
     * La sesión del legacy dura 24 h: pasado ese plazo la cookie ya no sirve
     * para nada, así que retenerla es solo superficie de exposición. El TTL
     * estaba documentado; esto lo hace EFECTIVO.
     *
     * ── Por qué además se cierra el job, y no solo se borra la cookie ──────
     * Un job `pending` sin credenciales NO PUEDE correr: el worker lo tomaría,
     * fallaría con "el job no tiene la sesión" y quemaría un intento. Peor: el
     * índice `uq_migration_job_alive` bloquea toda migración nueva de esa
     * empresa mientras haya una `pending`, así que un job vencido dejaría al
     * comercio sin poder migrar nunca más hasta que alguien lo tocara a mano.
     * Se cierra como `failed` con el motivo escrito.
     *
     * @return int Cuántos jobs quedaron sin credenciales.
     */
    private function sweepStaleCredentials(): int
    {
        global $db;

        $motivo = json_encode([[
            'domain'  => 'job',
            'message' => 'La sesión del panel legacy caducó (dura '
                . self::CREDENTIALS_TTL_HOURS . ' h) antes de que la migración llegara a ejecutarse. '
                . 'Creá la migración de nuevo.',
            'at'      => gmdate('c'),
        ]], JSON_UNESCAPED_UNICODE);

        // `pending` vencido: se cierra Y se le borran las cookies.
        $rs = $db->Execute(
            "UPDATE migration_job
                SET status      = 'failed',
                    credentials = NULL,
                    errors      = errors || ?::jsonb,
                    finished_at = now(),
                    updated_at  = now()
              WHERE status = 'pending'
                AND created_at < now() - (? || ' hours')::interval
              RETURNING jobid",
            [$motivo, self::CREDENTIALS_TTL_HOURS]
        );

        $n = 0;
        while ($rs !== false && !$rs->EOF) {
            $n++;
            $rs->MoveNext();
        }

        // Red de contención para cualquier job ya cerrado al que le hayan
        // quedado cookies (un worker que murió entre el import y `finish()`).
        $rs2 = $db->Execute(
            "UPDATE migration_job
                SET credentials = NULL, updated_at = now()
              WHERE credentials IS NOT NULL
                AND status IN ('done', 'failed')
                AND created_at < now() - (? || ' hours')::interval
              RETURNING jobid",
            [self::CREDENTIALS_TTL_HOURS]
        );

        while ($rs2 !== false && !$rs2->EOF) {
            $n++;
            $rs2->MoveNext();
        }

        return $n;
    }

    /**
     * Devuelve a `pending` los jobs que quedaron `running` sin dueño vivo.
     *
     * Un worker que muere (OOM, deploy a mitad de camino) deja el job en
     * `running` para siempre, y el índice único de "un job vivo por empresa"
     * bloquearía cualquier intento nuevo sobre ese comercio. Mismo patrón que
     * `PurchaseDraftService::requeueStale()`.
     *
     * Pasado el tope de intentos se marca `failed` en vez de reencolar: un job
     * que mata al worker tres veces lo va a matar la cuarta.
     */
    private function requeueStale(int $staleMinutes = 45): int
    {
        global $db;

        $db->Execute(
            "UPDATE migration_job
                SET status      = 'failed',
                    credentials = NULL,
                    errors      = errors || ?::jsonb,
                    finished_at = now(),
                    updated_at  = now()
              WHERE status = 'running'
                AND started_at < now() - (? || ' minutes')::interval
                AND attempts >= ?",
            [
                json_encode([[
                    'domain'  => 'job',
                    'message' => 'El proceso de migración se interrumpió y se agotaron los reintentos.',
                    'at'      => gmdate('c'),
                ]], JSON_UNESCAPED_UNICODE),
                $staleMinutes,
                self::MAX_ATTEMPTS,
            ]
        );

        $rs = $db->Execute(
            "UPDATE migration_job
                SET status = 'pending', updated_at = now()
              WHERE status = 'running'
                AND started_at < now() - (? || ' minutes')::interval
                AND attempts < ?
              RETURNING jobid",
            [$staleMinutes, self::MAX_ATTEMPTS]
        );

        $n = 0;
        while ($rs !== false && !$rs->EOF) {
            $n++;
            $rs->MoveNext();
        }
        return $n;
    }

    /** Cierra el job y BORRA las credenciales, salga bien o mal. */
    public function finish(string $jobId, string $status, array $progress, array $errors, array $log): void
    {
        global $db;

        if (!in_array($status, ['done', 'failed'], true)) {
            $status = 'failed';
        }

        $db->Execute(
            'UPDATE migration_job
                SET status      = ?,
                    progress    = ?::jsonb,
                    errors      = ?::jsonb,
                    log         = ?::jsonb,
                    credentials = NULL,
                    finished_at = now(),
                    updated_at  = now()
              WHERE jobid = ?',
            [
                $status,
                json_encode($progress, JSON_UNESCAPED_UNICODE),
                json_encode(array_values($errors), JSON_UNESCAPED_UNICODE),
                json_encode(array_values($log), JSON_UNESCAPED_UNICODE),
                $jobId,
            ],
        );
    }

    /** Progreso parcial, para que /admin vea avanzar un job largo. */
    public function reportProgress(string $jobId, array $progress, array $log): void
    {
        global $db;
        $db->Execute(
            'UPDATE migration_job
                SET progress = ?::jsonb, log = ?::jsonb, updated_at = now()
              WHERE jobid = ?',
            [
                json_encode($progress, JSON_UNESCAPED_UNICODE),
                json_encode(array_values($log), JSON_UNESCAPED_UNICODE),
                $jobId,
            ]
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // migration_map — idempotencia (D4)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Id de Punto ya mapeado para ese id del legacy, o null.
     *
     * Es la pregunta que hace idempotente al importador: si devuelve algo, la
     * entidad ya se creó en una corrida anterior y se saltea.
     */
    public static function mapped(string $companyId, string $domain, string $legacyId): ?string
    {
        $row = self::row(
            'SELECT puntoid FROM migration_map
              WHERE companyid = ? AND domain = ? AND legacyid = ? LIMIT 1',
            [$companyId, $domain, $legacyId]
        );
        if (!$row) {
            return null;
        }
        $id = (string) ($row['puntoid'] ?? $row['puntoId'] ?? '');
        return $id !== '' ? $id : null;
    }

    /**
     * Registra la correspondencia legacy → Punto.
     *
     * `ON CONFLICT DO NOTHING` y no `DO UPDATE`: si la clave ya existe, el id
     * de Punto que vale es el PRIMERO: es el que se creó de verdad y al que
     * pueden estar apuntando ítems ya importados. Pisarlo dejaría el mapa
     * apuntando a una entidad y los datos a otra.
     */
    public static function remember(
        string $companyId,
        string $domain,
        string $legacyId,
        string $puntoId,
        ?string $jobId = null
    ): void {
        global $db;
        $db->Execute(
            'INSERT INTO migration_map (companyid, domain, legacyid, puntoid, jobid)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (companyid, domain, legacyid) DO NOTHING',
            [$companyId, $domain, $legacyId, $puntoId, $jobId]
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /** Shape estable para la API, sin `credentials`. */
    /**
     * Una fila, con la superficie de DB que SÍ existe en el realm admin.
     *
     * `ncmExecute()` vive en `includes/functions.php`, que el realm admin NO
     * carga — es un realm aislado a propósito (ver el docblock de
     * `CompanyAdminService`, que por lo mismo reimplementa el flatten de JSONB
     * en vez de depender del global). Usarlo acá tiraba
     * "Call to undefined function Punto\Api\Admin\ncmExecute()" y el endpoint
     * respondía 500 al crear una migración — el worker no lo veía porque entra
     * por `bootstrap.php`, que sí lo define.
     *
     * Devuelve un array plano o null. Los campos JSONB pueden venir como
     * string; `jsonField()` ya tolera las dos formas, y el worker decodifica
     * con su propio helper.
     */
    /**
     * 'YYYY-MM-DD' o null.
     *
     * Lo que no tiene esa forma se descarta en vez de "interpretarse": una
     * fecha mal leída acá se convierte en el rango del histórico, y un rango
     * equivocado significa meses importados de menos (silencioso) o años de
     * particiones vacías (ruidoso). El importador ya tiene un default sensato
     * para cuando no hay fecha; no necesita una adivinada.
     */
    private static function fechaOpcional(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }

    private static function row(string $sql, array $params = []): ?array
    {
        global $db;

        $rs = $db->Execute($sql, $params);
        if ($rs === false || $rs->EOF) {
            return null;
        }
        $fields = $rs->fields;

        return $fields instanceof \CaseInsensitiveArray
            ? $fields->toArray()
            : (is_array($fields) ? $fields : []);
    }

    private function shape(array|\ArrayAccess $row): array
    {
        return [
            'jobId'          => (string) ($row['jobid'] ?? ''),
            'companyId'      => (string) ($row['companyid'] ?? ''),
            'companyName'    => (string) ($row['companyname'] ?? ''),
            'source'         => (string) ($row['source'] ?? 'encom'),
            'status'         => (string) ($row['status'] ?? ''),
            'domains'        => $this->jsonField($row, 'domains', []),
            'progress'       => $this->jsonField($row, 'progress', []),
            'errors'         => $this->jsonField($row, 'errors', []),
            'attempts'       => (int) ($row['attempts'] ?? 0),
            'hasCredentials' => $this->pgBool($row['hascredentials'] ?? false),
            'startedAt'      => $row['started_at'] ?? null,
            'finishedAt'     => $row['finished_at'] ?? null,
            'createdAt'      => $row['created_at'] ?? null,
        ];
    }

    /** jsonb que el driver puede devolver ya decodificado o como string. */
    private function jsonField(array|\ArrayAccess $row, string $key, mixed $default): mixed
    {
        $raw = $row[$key] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return $decoded ?? $default;
        }
        return $default;
    }

    /** PG devuelve booleanos como 't'/'f' según el driver. */
    private function pgBool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }
}
