<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

use Punto\Api\Storage\S3Client;

/**
 * Marcación de asistencia — el quiosco del comercio y su reporte
 * (`attendance_mark`, mig 230). RRHH F1, context/83 §4 y §7.
 *
 * ── La regla que gobierna TODO este archivo: fail-open (D4) ─────────────────
 *
 * Una marcación que llega SIEMPRE se guarda. No hay un solo camino en el que
 * este service tire una excepción por algo que el empleado no puede resolver
 * parado frente a la tablet: sin cámara, con el permiso denegado, con el PIN
 * rotado mientras el device estaba sin red, o incluso con el legajo ya dado de
 * baja — la fila entra y queda FLAGEADA (`needsreview` + un código de motivo).
 *
 * No es laxitud: es la misma decisión que la venta offline. Dejar a alguien que
 * sí fue a trabajar sin poder registrar que fue a trabajar es un daño concreto
 * e inmediato; el fraude, en cambio, se ataca con la evidencia que esta misma
 * fila guarda (la foto del momento, el aparato, la hora) y con la revisión del
 * dueño. Un portón no reduce el fraude más que el flag — solo agrega víctimas.
 *
 * Lo ÚNICO que se rechaza es lo que hace imposible guardar un hecho: un empleado
 * que no existe en este comercio, un tipo de marcación que no es entrada ni
 * salida, una fecha ilegible o un `opId` ausente. Ahí no hay marcación que
 * salvar: hay un cliente mandando cualquier cosa.
 *
 * ── El PIN identifica; no autoriza ─────────────────────────────────────────
 *
 * El quiosco resuelve el PIN LOCALMENTE contra los hashes que bajaron en el
 * bootstrap (offline-nativo, igual que el lockscreen) y manda el `employeeId`
 * que matcheó JUNTO CON el hash que tipeó la persona. Acá se verifica el par:
 *
 *   - coinciden          → marcación limpia.
 *   - no coinciden       → la marcación ENTRA igual, flageada `pin_stale`.
 *
 * Por qué no se rechaza el segundo caso: la causa realista es que el PIN se
 * cambió en el panel mientras la tablet estaba sin red, y la persona marcó con
 * el que sabía. Rechazarlo destruye un hecho real y, peor, deja la operación
 * muerta en la cola del POS trabando el canal.
 *
 * Y por qué se manda el hash igual si no bloquea: porque la alternativa —creerle
 * al `employeeId` y nada más— no deja rastro de que la identificación falló. Con
 * el par verificado, el dueño ve exactamente qué marcaciones no pudieron
 * probar quién las hizo.
 *
 * ── Idempotencia ───────────────────────────────────────────────────────────
 *
 * `opId` del cliente (`X-Punto-Op-Id`), único por comercio. Una marcación
 * reenviada tras un timeout encuentra su propia fila en vez de registrar una
 * segunda entrada a la misma hora — y la foto NO se vuelve a subir: la
 * verificación de idempotencia corre ANTES de tocar S3.
 */
final class AttendanceService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tipos de marcación. 'face' llega en la F2 y no agrega nada más que este valor. */
    public const METHODS = ['pin', 'face'];

    public const KINDS = ['in', 'out'];

    /** Tope de la foto de evidencia. El quiosco manda ~100 KB; esto es el techo. */
    public const MAX_PHOTO_BYTES = 3 * 1024 * 1024;

    /**
     * Motivos por los que una marcación queda para revisar. Códigos, no textos:
     * la redacción vive en el front y cambia sin migrar filas.
     */
    public const REVIEW_REASONS = [
        'no_camera',         // el dispositivo no tiene cámara utilizable
        'camera_denied',     // el permiso de cámara está denegado
        'photo_failed',      // la captura falló en el navegador
        'photo_lost',        // la foto viajó pero no se pudo archivar
        'pin_stale',         // el hash del PIN no coincide con el del legajo
        'employee_inactive', // el legajo ya no está vigente
    ];

    /** Días de la semana del horario declarado, en el orden de `date('N')` - 1. */
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private ?S3Client $s3;

    public function __construct(?S3Client $s3 = null)
    {
        $this->s3 = $s3;
    }

    // ── Roster del quiosco ──────────────────────────────────────────────────

    /**
     * Los empleados que pueden marcar en esta sucursal, con su hash de PIN y su
     * última marcación conocida. Es lo que baja al snapshot del dispositivo.
     *
     * Proyección MÍNIMA a propósito — id, nombre, hash y última marcación. NO
     * viaja el sueldo, ni el documento, ni el teléfono: el quiosco solo necesita
     * saber a quién corresponde un PIN y si le toca entrar o salir. El legajo
     * completo es realm `panel` y permiso propio (ver `employees.php`).
     *
     * Quién entra:
     *   - vigente (`status = 1`) y sin egreso;
     *   - con PIN de marcación cargado — sin hash no hay nada contra qué validar
     *     sin red, así que mandarlo solo agregaría un nombre que nunca matchea;
     *   - de ESTA sucursal, o sin sucursal asignada. El legajo tiene UNA
     *     sucursal principal (mig 229) y `NULL` significa "no está atado a
     *     ninguna" — típico del dueño o de quien rota. Excluirlos los dejaría sin
     *     poder marcar en ningún lado.
     *
     * @return array<int, array<string,mixed>>
     */
    public function rosterForOutlet(string $companyId, string $outletId): array
    {
        $where  = ['e.companyid = ?', 'e.status = 1', 'e.enddate IS NULL', 'e.markpinhash IS NOT NULL'];
        $params = [$companyId];

        if (preg_match(self::UUID_RE, $outletId)) {
            $where[] = '(e.outletid IS NULL OR e.outletid = ?)';
            $params[] = $outletId;
        }

        // La última marcación se resuelve con un LATERAL y no con un GROUP BY
        // sobre toda la tabla: son N empleados por un índice
        // (`idx_attendance_mark_employee`), no un scan del histórico entero del
        // comercio para quedarse con la última fila de cada uno.
        $sql = 'SELECT e.employeeid, e.fullname, e.jobtitle, e.markpinhash,
                       lm.kind AS lastkind, lm.markedat AS lastmarkedat
                  FROM employee e
                  LEFT JOIN LATERAL (
                        SELECT m.kind, m.markedat
                          FROM attendance_mark m
                         WHERE m.employeeid = e.employeeid
                           AND m.companyid  = e.companyid
                         ORDER BY m.markedat DESC
                         LIMIT 1
                  ) lm ON TRUE
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY e.fullname ASC';

        $rs = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $rows[] = [
                    'id'           => (string) $f['employeeid'],
                    'name'         => (string) $f['fullname'],
                    'jobTitle'     => self::strOrNull($f['jobtitle'] ?? null),
                    'markPinHash'  => (string) $f['markpinhash'],
                    'lastKind'     => self::strOrNull($f['lastkind'] ?? null),
                    'lastMarkedAt' => self::strOrNull($f['lastmarkedat'] ?? null),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    // ── Alta desde el quiosco ───────────────────────────────────────────────

    /**
     * Registra una marcación. Ver el docblock de la clase: solo tira cuando no
     * hay hecho que guardar.
     *
     * @param array{
     *   opId:string, employeeId:string, kind:string, markedAt:string,
     *   markPinHash?:?string, method?:?string, outletId?:?string,
     *   registerId?:?string, deviceId?:?string, noPhotoReason?:?string
     * } $input
     * @param array|null $photo fila de `$_FILES` con la foto del momento, o null
     * @return array{mark:array<string,mixed>, duplicate:bool}
     */
    public function mark(string $companyId, array $input, ?array $photo = null): array
    {
        // El `opId` termina siendo parte de la CLAVE del objeto en S3, así que
        // se restringe el alfabeto además del largo. Contra la API de S3 no es
        // explotable (las claves no se resuelven como rutas), pero es una
        // cadena que elige el cliente construyendo una ubicación de storage:
        // acotarla cuesta una línea.
        $opId = trim((string) ($input['opId'] ?? ''));
        if ($opId === '' || strlen($opId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $opId)) {
            throw new \RuntimeException('Falta el identificador de la operación (o es inválido)');
        }

        // ── Idempotencia PRIMERO, antes de tocar S3 ──
        // Un reenvío no puede subir una segunda foto del mismo momento: sería
        // un objeto huérfano en el bucket por cada reintento de una marcación
        // que ya está guardada.
        $existing = $this->findByOpId($companyId, $opId);
        if ($existing !== null) {
            return ['mark' => $existing, 'duplicate' => true];
        }

        $employeeId = trim((string) ($input['employeeId'] ?? ''));
        if (!preg_match(self::UUID_RE, $employeeId)) {
            throw new \RuntimeException('La marcación no indica de quién es');
        }

        $employee = ncmExecute(
            'SELECT employeeid, markpinhash, status, enddate
               FROM employee
              WHERE employeeid = ? AND companyid = ?
              LIMIT 1',
            [$employeeId, $companyId]
        );
        if (!$employee) {
            // Único rechazo de identidad que queda: sin legajo no hay a quién
            // sumarle horas. No es un caso de borde del empleado — es un id que
            // no existe en este comercio.
            throw new \RuntimeException('El empleado no existe en este comercio');
        }

        $kind = strtolower(trim((string) ($input['kind'] ?? '')));
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('La marcación tiene que ser entrada o salida');
        }

        $method = strtolower(trim((string) ($input['method'] ?? 'pin')));
        if (!in_array($method, self::METHODS, true)) {
            $method = 'pin';
        }

        $markedAt = self::instantOrNull($input['markedAt'] ?? null);
        if ($markedAt === null) {
            throw new \RuntimeException('La marcación no tiene una hora válida');
        }

        // ── Motivo de revisión: se resuelve con TODO lo que se sabe ──
        //
        // Puede haber más de una causa (sin foto Y con el PIN viejo). Se guarda
        // UNA, la primera de esta lista, porque `reviewreason` existe para que
        // el dueño sepa qué mirar, no para auditar el estado completo del
        // dispositivo. El orden es el de "qué explica mejor lo que ve": la
        // identidad primero, la evidencia después.
        $reasons = [];

        $storedHash  = self::strOrNull($employee['markpinhash'] ?? null);
        $offeredHash = strtolower(trim((string) ($input['markPinHash'] ?? '')));
        if ($method === 'pin' && ($storedHash === null || $offeredHash === '' || !hash_equals($storedHash, $offeredHash))) {
            $reasons[] = 'pin_stale';
        }

        $isActive = ((int) ($employee['status'] ?? 1)) === 1 && ($employee['enddate'] ?? null) === null;
        if (!$isActive) {
            // El legajo se dio de baja mientras esta marcación esperaba en la
            // cola del dispositivo. El hecho ocurrió igual: entra flageado, no
            // se descarta. Descartarlo dejaría además la operación muerta en la
            // cola, trabando el canal entero (ver `pending-ops-sync.ts`).
            $reasons[] = 'employee_inactive';
        }

        // ── La foto ──
        // Se intenta SIEMPRE (D4) y NUNCA bloquea. Si el quiosco no pudo
        // capturarla, manda el motivo; si la mandó y el archivo no se puede
        // guardar, el motivo lo pone este service.
        $photoKey = null;
        if ($photo !== null && !empty($photo['tmp_name'])) {
            try {
                $photoKey = $this->storePhoto($companyId, $employeeId, $opId, $photo);
            } catch (\Throwable) {
                // S3 caído, tipo inesperado, archivo corrupto: da igual cuál.
                // Ninguno de esos es motivo para perder la marcación.
                $reasons[] = 'photo_lost';
            }
        } else {
            $declared = trim((string) ($input['noPhotoReason'] ?? ''));
            $reasons[] = in_array($declared, self::REVIEW_REASONS, true) ? $declared : 'photo_failed';
        }

        $reason      = $reasons[0] ?? null;
        $needsReview = $reason !== null;

        $rs = ncmExecute(
            'INSERT INTO attendance_mark
                 (companyid, employeeid, outletid, registerid, deviceid,
                  kind, markedat, method, photokey, needsreview, reviewreason, opid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (companyid, opid) DO NOTHING
             RETURNING markid',
            [
                $companyId,
                $employeeId,
                self::uuidOrNull($input['outletId']   ?? null),
                self::uuidOrNull($input['registerId'] ?? null),
                self::uuidOrNull($input['deviceId']   ?? null),
                $kind,
                $markedAt,
                $method,
                $photoKey,
                $needsReview ? 't' : 'f',
                $reason,
                $opId,
            ],
            false,
            true
        );

        $insertedId = null;
        if ($rs && is_object($rs)) {
            if (!$rs->EOF) {
                $insertedId = (string) ($rs->fields['markid'] ?? '');
            }
            $rs->Close();
        }

        if ($insertedId === null) {
            // Carrera: otra request con el MISMO `opId` ganó entre el chequeo de
            // idempotencia y este INSERT.
            //
            // La foto que acabamos de subir NO se borra, y esto es
            // contraintuitivo a propósito: la clave del objeto sale del `opId`
            // (ver `storePhoto()`), así que las dos requests escribieron LA
            // MISMA clave y la fila ganadora apunta justamente a ella. Borrarla
            // como "huérfana" dejaría a la marcación que sí quedó registrada
            // sin su evidencia — que es lo único que esta feature existe para
            // guardar. No hay objeto huérfano que limpiar: hay uno solo, y
            // tiene dueño.
            $winner = $this->findByOpId($companyId, $opId);
            if ($winner === null) {
                throw new \RuntimeException('No se pudo registrar la marcación');
            }
            return ['mark' => $winner, 'duplicate' => true];
        }

        $row = $this->find($insertedId, $companyId);
        if ($row === null) {
            throw new \RuntimeException('Marcación registrada pero no se pudo leer de vuelta');
        }
        return ['mark' => $row, 'duplicate' => false];
    }

    // ── Revisión ────────────────────────────────────────────────────────────

    /**
     * Marca como REVISADA una marcación flageada. No la corrige ni la borra: un
     * hecho que ocurrió no se edita. "Revisada" significa que una persona la
     * miró y la dio por buena — que es justamente lo que el flag pedía.
     */
    public function review(string $markId, string $companyId, ?string $actorId): array
    {
        $current = $this->find($markId, $companyId);
        if ($current === null) {
            throw new \RuntimeException('Marcación no encontrada');
        }
        ncmExecute(
            'UPDATE attendance_mark
                SET needsreview = FALSE, reviewedat = now(), reviewedby = ?
              WHERE markid = ? AND companyid = ? AND needsreview = TRUE',
            [self::uuidOrNull($actorId), $markId, $companyId]
        );
        $row = $this->find($markId, $companyId);
        if ($row === null) {
            throw new \RuntimeException('No se pudo releer la marcación');
        }
        return $row;
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    public function find(string $markId, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $markId)) {
            return null;
        }
        $row = ncmExecute($this->selectSql() . ' WHERE m.markid = ? AND m.companyid = ? LIMIT 1',
            [$markId, $companyId]);
        return $row ? $this->shape($row) : null;
    }

    public function findByOpId(string $companyId, string $opId): ?array
    {
        $row = ncmExecute($this->selectSql() . ' WHERE m.companyid = ? AND m.opid = ? LIMIT 1',
            [$companyId, $opId]);
        return $row ? $this->shape($row) : null;
    }

    /**
     * Los BYTES de la foto de una marcación, para servirla.
     *
     * Devuelve la foto y no su clave a propósito: la clave de un objeto privado
     * no tiene por qué salir de este archivo. Que el endpoint la recibiera
     * invitaría a construir con ella una URL directa al bucket, que es
     * exactamente lo que el objeto privado existe para impedir.
     *
     * `null` = esa marcación no tiene foto (caso esperado, D4) o el objeto ya no
     * está. Los dos son un 404 para quien pregunta.
     */
    public function photo(string $markId, string $companyId): ?string
    {
        if (!preg_match(self::UUID_RE, $markId) || $this->s3 === null) {
            return null;
        }
        $row = ncmExecute(
            'SELECT photokey FROM attendance_mark WHERE markid = ? AND companyid = ? LIMIT 1',
            [$markId, $companyId]
        );
        $key = $row ? self::strOrNull($row['photokey'] ?? null) : null;
        return $key === null ? null : $this->s3->get($key);
    }

    /**
     * El reporte del período: las marcaciones crudas y el resumen por empleado.
     *
     * ── Por qué las dos cosas en una respuesta ──
     *
     * Son la misma lectura. El resumen no se puede calcular sin las marcaciones
     * (hay que aparearlas en orden), así que devolverlo por separado obligaría a
     * leer dos veces el mismo rango para responder la misma pregunta.
     *
     * @param array{employeeId?:?string,outletId?:?string,needsReview?:bool} $filters
     */
    public function report(string $companyId, string $from, string $to, array $filters = []): array
    {
        $where  = ['m.companyid = ?', 'm.markedat >= ?', 'm.markedat <= ?'];
        $params = [$companyId, $from, $to];

        if (!empty($filters['employeeId']) && preg_match(self::UUID_RE, (string) $filters['employeeId'])) {
            $where[]  = 'm.employeeid = ?';
            $params[] = (string) $filters['employeeId'];
        }
        if (!empty($filters['outletId']) && preg_match(self::UUID_RE, (string) $filters['outletId'])) {
            $where[]  = 'm.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }
        if (!empty($filters['needsReview'])) {
            $where[] = 'm.needsreview = TRUE';
        }

        $rs = ncmExecute(
            $this->selectSql() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.fullname ASC, m.markedat ASC',
            $params,
            false,
            true
        );

        $marks = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $marks[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $this->summarize($companyId, $marks);
    }

    // ── Cálculo ─────────────────────────────────────────────────────────────

    /**
     * Aparea entradas con salidas, suma horas y mide tardanzas.
     *
     * ── El apareo NO se corta por día ──
     *
     * Se recorren las marcaciones de cada persona en orden y una entrada se
     * cierra con la PRIMERA salida que venga después, aunque sea del día
     * siguiente. Cortar por día partiría en dos todo turno nocturno, que es
     * media gastronomía. La contracara: una entrada que nadie cerró se lleva
     * consigo la siguiente salida solo si esa salida existe — si no, queda
     * ABIERTA, suma cero horas y se cuenta aparte.
     *
     * Un par abierto no se "estima" hasta el fin del día ni hasta ahora: sería
     * inventar horas que nadie declaró, y esas horas se pagan.
     *
     * ── La tardanza se mide UNA vez por día ──
     *
     * Contra la PRIMERA entrada del día y nada más. Sin eso, volver del
     * almuerzo a las 14:00 contra un horario que empieza 08:00 sumaría seis
     * horas de tardanza todos los días.
     *
     * Sin horario declarado en el legajo no hay tardanza: el reporte informa
     * horas y se calla. Inventar un horario estándar sería acusar a alguien de
     * llegar tarde contra una regla que nadie escribió.
     *
     * @param array<int, array<string,mixed>> $marks
     */
    private function summarize(string $companyId, array $marks): array
    {
        $schedules = $this->schedulesFor($companyId, array_values(array_unique(
            array_map(static fn($m) => (string) $m['employeeId'], $marks)
        )));

        /** @var array<string, array<string,mixed>> $byEmployee */
        $byEmployee = [];
        /** @var array<string, array<string,mixed>> $openMark id de la marcación de entrada abierta */
        $open = [];
        /** @var array<string, array<string,bool>> $firstInSeen empleado → día ya evaluado */
        $firstInSeen = [];

        foreach ($marks as $i => $mark) {
            $employeeId = (string) $mark['employeeId'];

            if (!isset($byEmployee[$employeeId])) {
                $byEmployee[$employeeId] = [
                    'employeeId'    => $employeeId,
                    'employeeName'  => $mark['employeeName'],
                    'jobTitle'      => $mark['jobTitle'],
                    'outletName'    => $mark['outletName'],
                    'workedMinutes' => 0,
                    'pairs'         => 0,
                    'openPairs'     => 0,
                    'days'          => [],
                    'lateCount'     => 0,
                    'lateMinutes'   => 0,
                    'needsReview'   => 0,
                    'hasSchedule'   => isset($schedules[$employeeId]),
                ];
            }
            $acc = &$byEmployee[$employeeId];
            $acc['days'][(string) $mark['localDay']] = true;
            if ($mark['needsReview'] === true) {
                $acc['needsReview']++;
            }

            if ($mark['kind'] === 'in') {
                // Una entrada con otra entrada abierta adelante: la anterior
                // queda sin cerrar. Se cuenta como par abierto y la nueva toma
                // su lugar — la alternativa (ignorar la nueva) perdería la
                // única entrada que sí tiene salida.
                if (isset($open[$employeeId])) {
                    $acc['openPairs']++;
                    $marks[$open[$employeeId]['index']]['unpaired'] = true;
                }
                $open[$employeeId] = ['index' => $i, 'at' => (string) $mark['markedAt']];

                $day = (string) $mark['localDay'];
                if (!isset($firstInSeen[$employeeId][$day])) {
                    $firstInSeen[$employeeId][$day] = true;
                    $late = $this->lateMinutes($schedules[$employeeId] ?? null, $mark);
                    $marks[$i]['lateMinutes'] = $late;
                    if ($late !== null && $late > 0) {
                        $acc['lateCount']++;
                        $acc['lateMinutes'] += $late;
                    }
                }
                continue;
            }

            // Salida.
            if (!isset($open[$employeeId])) {
                // Salida sin entrada: no hay intervalo que medir. Se muestra en
                // el detalle marcada como suelta, y no se descarta — es un hecho
                // que ocurrió y que el dueño necesita ver para entender el día.
                $marks[$i]['unpaired'] = true;
                continue;
            }

            $minutes = (int) round(
                (strtotime((string) $mark['markedAt']) - strtotime($open[$employeeId]['at'])) / 60
            );
            if ($minutes < 0) {
                $minutes = 0;
            }
            $acc['workedMinutes'] += $minutes;
            $acc['pairs']++;
            $marks[$i]['pairedMinutes'] = $minutes;
            unset($open[$employeeId]);
        }
        unset($acc);

        // Lo que quedó abierto al terminar el rango.
        foreach ($open as $employeeId => $pending) {
            $byEmployee[$employeeId]['openPairs']++;
            $marks[$pending['index']]['unpaired'] = true;
        }

        $employees = [];
        foreach ($byEmployee as $row) {
            $row['days'] = count($row['days']);
            $employees[] = $row;
        }

        return [
            'marks'     => array_values($marks),
            'employees' => $employees,
            'totals'    => [
                'marks'         => count($marks),
                'employees'     => count($employees),
                'workedMinutes' => array_sum(array_column($employees, 'workedMinutes')),
                'lateCount'     => array_sum(array_column($employees, 'lateCount')),
                'needsReview'   => count(array_filter($marks, static fn($m) => $m['needsReview'] === true)),
            ],
        ];
    }

    /**
     * Minutos de tardanza de una entrada contra el horario declarado, o `null`
     * si no hay con qué medirla (sin horario, o ese día no es laborable).
     *
     * `null` y 0 son distintos y el reporte los muestra distinto: `null` es "no
     * se sabe", 0 es "llegó a horario".
     *
     * @param array<string,mixed>|null $schedule
     * @param array<string,mixed> $mark
     */
    private function lateMinutes(?array $schedule, array $mark): ?int
    {
        if ($schedule === null) {
            return null;
        }
        $weekday = self::WEEKDAYS[((int) $mark['weekday']) - 1] ?? null;
        if ($weekday === null) {
            return null;
        }
        $day = $schedule['days'][$weekday] ?? null;
        if (!is_array($day)) {
            return null; // día no laborable declarado
        }
        $expected = self::minutesOfDay((string) ($day['in'] ?? ''));
        if ($expected === null) {
            return null;
        }
        $actual = self::minutesOfDay((string) $mark['localTime']);
        if ($actual === null) {
            return null;
        }
        $tolerance = max(0, (int) ($schedule['toleranceMinutes'] ?? 0));
        $diff = $actual - $expected - $tolerance;
        return $diff > 0 ? $diff : 0;
    }

    /**
     * Horarios declarados de un conjunto de empleados.
     *
     * @param array<int,string> $employeeIds
     * @return array<string, array<string,mixed>>
     */
    private function schedulesFor(string $companyId, array $employeeIds): array
    {
        $ids = array_values(array_filter($employeeIds, static fn($id) => preg_match(self::UUID_RE, $id) === 1));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // `companyid` explícito aunque los ids YA vengan de una lectura
        // scopeada por tenant (§33.2): una query a una tabla de tenant sin
        // filtro de tenant es correcta hasta el día en que alguien reusa el
        // helper con ids que vienen de otro lado, y ese día no avisa.
        $rs = ncmExecute(
            'SELECT employeeid, schedule
               FROM employee
              WHERE companyid = ? AND employeeid IN (' . $placeholders . ')',
            array_merge([$companyId], $ids),
            false,
            true
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $raw = $rs->fields['schedule'] ?? null;
                $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
                if (is_array($decoded) && !empty($decoded['days'])) {
                    $out[(string) $rs->fields['employeeid']] = $decoded;
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    // ── SQL y presentación ──────────────────────────────────────────────────

    /**
     * El SELECT compartido por todas las lecturas.
     *
     * `localday` / `localtime` / `weekday` salen de Postgres y no de PHP: la
     * sesión ya tiene la zona del tenant aplicada (`TenantClock::apply()` en el
     * embudo de auth), así que ahí el día calendario es el del comercio. Calcular
     * el día en PHP usaría la zona del PROCESO, que es otra — y un turno que
     * empieza 22:00 caería en el día equivocado.
     */
    private function selectSql(): string
    {
        return 'SELECT m.markid, m.employeeid, m.outletid, m.registerid, m.deviceid,
                       m.kind, m.markedat, m.receivedat, m.method, m.photokey,
                       m.needsreview, m.reviewreason, m.reviewedat, m.opid,
                       to_char(m.markedat, \'YYYY-MM-DD\') AS localday,
                       to_char(m.markedat, \'HH24:MI\')    AS localtime,
                       EXTRACT(ISODOW FROM m.markedat)     AS weekday,
                       e.fullname AS employeename, e.jobtitle AS jobtitle,
                       o.outletname AS outletname
                  FROM attendance_mark m
                  JOIN employee e ON e.employeeid = m.employeeid
                  LEFT JOIN outlet o ON o.outletid = m.outletid';
    }

    /** @return array<string,mixed> */
    private function shape($f): array
    {
        return [
            'id'            => (string) $f['markid'],
            'employeeId'    => (string) $f['employeeid'],
            'employeeName'  => (string) ($f['employeename'] ?? ''),
            'jobTitle'      => self::strOrNull($f['jobtitle'] ?? null),
            'outletId'      => self::strOrNull($f['outletid'] ?? null),
            'outletName'    => self::strOrNull($f['outletname'] ?? null),
            'registerId'    => self::strOrNull($f['registerid'] ?? null),
            'kind'          => (string) $f['kind'],
            'markedAt'      => (string) $f['markedat'],
            'receivedAt'    => self::strOrNull($f['receivedat'] ?? null),
            'localDay'      => (string) ($f['localday'] ?? ''),
            'localTime'     => (string) ($f['localtime'] ?? ''),
            'weekday'       => (int) ($f['weekday'] ?? 0),
            'method'        => (string) $f['method'],
            // La CLAVE del objeto no sale nunca de acá: el front pide la foto
            // por el id de la marcación y el endpoint la sirve. Publicar la
            // clave invitaría a construir una URL directa al bucket, que es
            // justo lo que el objeto privado impide.
            'hasPhoto'      => self::strOrNull($f['photokey'] ?? null) !== null,
            'needsReview'   => self::boolOf($f['needsreview'] ?? false),
            'reviewReason'  => self::strOrNull($f['reviewreason'] ?? null),
            'reviewedAt'    => self::strOrNull($f['reviewedat'] ?? null),
            // Los completa `summarize()`; existen desde acá para que la forma de
            // la fila no dependa de por dónde se la haya leído.
            'lateMinutes'   => null,
            'pairedMinutes' => null,
            'unpaired'      => false,
        ];
    }

    /**
     * Guarda la foto del momento. PRIVADA, igual que los adjuntos del legajo:
     * es la cara de una persona y una URL pública adivinable la entrega sin
     * sesión.
     *
     * El tipo sale del CONTENIDO (`finfo`), no del header que declaró el
     * cliente. Solo JPEG: es lo que produce el quiosco (canvas → toBlob) y
     * ampliar la lista sería aceptar formatos que nadie genera.
     */
    private function storePhoto(string $companyId, string $employeeId, string $opId, array $photo): string
    {
        if ($this->s3 === null) {
            throw new \RuntimeException('Almacenamiento no disponible');
        }
        $size = (int) ($photo['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('Foto vacía');
        }
        if ($size > self::MAX_PHOTO_BYTES) {
            throw new \RuntimeException('La foto supera el tamaño máximo');
        }
        $tmp = (string) ($photo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Foto no recibida');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if ($mime !== 'image/jpeg') {
            throw new \RuntimeException('Formato de foto no soportado');
        }
        $data = file_get_contents($tmp);
        if ($data === false || $data === '') {
            throw new \RuntimeException('No se pudo leer la foto');
        }

        // La clave lleva el `opId` y no el `markId`: la foto se sube ANTES del
        // INSERT (para no escribir una fila que prometa una foto que no está), y
        // en ese momento el id de la marcación todavía no existe. El `opId` ya
        // es único por comercio, así que identifica igual — y hace que un
        // reintento que llegue hasta acá pise su propio objeto en vez de dejar
        // uno nuevo.
        $key = sprintf('employees/%s/%s/attendance/%s.jpg', $companyId, $employeeId, $opId);
        $this->s3->put($key, $data, 'image/jpeg', false);
        return $key;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Instante válido, normalizado a ISO-8601 con offset.
     *
     * Acepta lo que manda el quiosco (`Date.toISOString()`, o sea UTC con `Z`) y
     * también una hora naive del tenant, por si un cliente viejo la manda así.
     * La naive la resuelve `DateTimeImmutable` con la zona del proceso, que el
     * embudo de auth ya alineó con la del tenant.
     */
    private static function instantOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
        // Una fecha absurda (reloj de tablet en 1970 o en 2099) no se corrige:
        // corregirla sería inventar el dato que el reporte va a leer. Se
        // rechaza, y el quiosco muestra el error — es de las poquísimas cosas
        // que la persona parada ahí SÍ puede resolver (poner el reloj en hora).
        $year = (int) $d->format('Y');
        if ($year < 2000 || $year > 2100) {
            return null;
        }
        return $d->format('Y-m-d\TH:i:sP');
    }

    /** 'HH:MM' → minutos desde medianoche. `null` si no es una hora. */
    private static function minutesOfDay(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    private static function strOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = (string) $v;
        return $s === '' ? null : $s;
    }

    private static function uuidOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return ($s !== '' && preg_match(self::UUID_RE, $s)) ? $s : null;
    }

    /** PDO devuelve 't'/'f' para BOOLEAN. */
    private static function boolOf(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;
        return in_array(strtolower(trim((string) $v)), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
