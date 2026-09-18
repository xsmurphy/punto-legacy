<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

use Punto\Api\Storage\S3Client;

/**
 * Rostro del empleado — enrolamiento autorizado y bajada al quiosco
 * (`employee_face` + `employee_face_enrollment`, mig 231). RRHH F2,
 * context/83 §4, D4 y D5.
 *
 * ── Qué hace este archivo y qué NO ─────────────────────────────────────────
 *
 * NO reconoce a nadie. Acá no corre ningún modelo: el reconocimiento vive en el
 * navegador del dispositivo (D5), sin red y sin mandarle la cara de un empleado
 * a un tercero. Este service GUARDA los vectores que el quiosco calculó, los
 * SIRVE de vuelta al quiosco de su sucursal, y —lo más importante— gobierna
 * QUIÉN puede enrolarse y CUÁNDO.
 *
 * ── El enrolamiento es autorizado, y ahí está toda la seguridad ────────────
 *
 * El modelo entero se apoya en una sola idea: si quien sabe un PIN pudiera
 * registrar su propia cara bajo el nombre de otro, tendríamos el buddy punching
 * de vuelta, con más pasos y con mejor coartada — porque después la cara
 * "confirma" al impostor todos los días.
 *
 * Por eso el enrolamiento tiene dos mitades que viven en realms distintos:
 *
 *   1. Alguien con `hr.employees.manage` lo ABRE desde el panel, para UNA
 *      persona, y la apertura vence sola (`openEnrollment()`).
 *   2. El quiosco de esa sucursal captura, y solo mientras esa apertura esté
 *      viva y sea de su sucursal (`enroll()`).
 *
 * El quiosco NUNCA elige a quién enrola: recibe el nombre desde el servidor y
 * captura. Un device comprometido no puede darse de alta como nadie.
 *
 * ── Sin consentimiento no hay enrolamiento ─────────────────────────────────
 *
 * La cara de una persona es un dato sensible y el legajo ya tiene dónde
 * registrar que lo consintió (`biometricconsentat`, mig 229). Se verifica DOS
 * veces —al abrir y al guardar— porque entre las dos hay diez minutos y una
 * persona que puede haberlo retirado en el medio.
 *
 * ── La versión del modelo no es metadata ───────────────────────────────────
 *
 * Dos modelos distintos producen vectores que no son comparables, y la
 * comparación no falla: devuelve un número. Por eso `SUPPORTED_MODELS` es una
 * lista CERRADA con el largo exacto de cada vector, y un enrolamiento que
 * declare otra cosa se rechaza. Aceptar cualquier versión "porque el cliente
 * sabrá" es cómo se guarda un vector que después nunca reconoce a nadie, sin
 * que nada haya dado error.
 */
final class EmployeeFaceService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Modelos aceptados → largo exacto de su vector.
     *
     * Lista CERRADA a propósito (ver el docblock). Sumar un modelo es sumar una
     * línea acá, y tiene que pasar en el mismo deploy en que el front lo empieza
     * a usar: el espejo de esta constante es `FACE_MODEL_VERSION` en
     * `frontend/lib/pos/face/face-match.ts`.
     */
    public const SUPPORTED_MODELS = [
        'face-api-recognition-128' => 128,
    ];

    /**
     * Cuánto dura una apertura de enrolamiento.
     *
     * Corto porque es un permiso, no una configuración: alcanza para que la
     * persona camine hasta el quiosco y se saque las tomas. Si se venció, se
     * vuelve a abrir en un toque — que es mucho más barato que un comercio con
     * media docena de enrolamientos abiertos hace semanas.
     */
    public const ENROLLMENT_TTL_MINUTES = 10;

    /** Capturas por enrolamiento. Menos de 3 no promedia nada; más de 5 cansa. */
    public const MIN_SAMPLES = 3;
    public const MAX_SAMPLES = 5;

    /**
     * Distancia máxima ENTRE las capturas de un mismo enrolamiento.
     *
     * Es el único chequeo de coherencia que el servidor puede hacer sobre algo
     * que calculó el cliente, y vale la pena: si las tomas son de personas
     * distintas —alguien se corrió delante de la cámara a mitad de camino— el
     * promedio no es la cara de nadie, y lo que se guarda es un vector que
     * después reconoce mal a todo el mundo.
     *
     * Holgada (mucho más que el umbral con el que se identifica) porque son
     * frames distintos de la misma persona moviéndose: acá solo se rechaza lo
     * que evidentemente no es la misma cara.
     */
    public const MAX_SAMPLE_SPREAD = 0.28;

    /** Tope de la foto de enrolamiento. Mismo criterio que la foto de marcación. */
    public const MAX_PHOTO_BYTES = 3 * 1024 * 1024;

    private ?S3Client $s3;

    public function __construct(?S3Client $s3 = null)
    {
        $this->s3 = $s3;
    }

    // ── Apertura del enrolamiento (panel) ───────────────────────────────────

    /**
     * Habilita al quiosco a capturar el rostro de esta persona, por un rato.
     *
     * La sucursal sale del LEGAJO, no de quien abre ni del dispositivo: es lo
     * que hace que el quiosco no pueda elegir a quién enrola. `NULL` en el
     * legajo significa "sin sucursal asignada" y habilita cualquier quiosco del
     * comercio — mismo criterio que `AttendanceService::rosterForOutlet()`, y
     * por el mismo motivo: si no, el dueño y quien rota no se pueden enrolar en
     * ningún lado.
     *
     * @return array<string,mixed>
     */
    public function openEnrollment(string $companyId, string $employeeId, ?string $actorId): array
    {
        $employee = $this->employeeOrFail($companyId, $employeeId);

        if (($employee['enddate'] ?? null) !== null || ((int) ($employee['status'] ?? 1)) !== 1) {
            throw new \RuntimeException('El legajo no está vigente');
        }
        if (($employee['biometricconsentat'] ?? null) === null) {
            // El gate real del consentimiento. No es un chequeo de formulario:
            // sin esta fila no se puede guardar la cara de nadie, y por eso está
            // acá y no solo en la pantalla.
            throw new \RuntimeException('Falta registrar en el legajo que la persona aceptó identificarse con su rostro');
        }

        $expiresAt = date('Y-m-d H:i:sP', time() + self::ENROLLMENT_TTL_MINUTES * 60);

        // UPSERT: volver a abrir RENUEVA la apertura viva en vez de acumular
        // uno por click. La PK (companyid, contactid) es lo que lo garantiza.
        ncmExecute(
            'INSERT INTO employee_face_enrollment
                 (companyid, contactid, outletid, expiresat, createdby)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (companyid, contactid) DO UPDATE
                SET outletid  = EXCLUDED.outletid,
                    expiresat = EXCLUDED.expiresat,
                    createdby = EXCLUDED.createdby,
                    createdat = now()',
            [
                $companyId,
                $employeeId,
                self::uuidOrNull($employee['outletid'] ?? null),
                $expiresAt,
                self::uuidOrNull($actorId),
            ]
        );

        return [
            'employeeId' => $employeeId,
            'outletId'   => self::strOrNull($employee['outletid'] ?? null),
            'expiresAt'  => $expiresAt,
        ];
    }

    /** Cierra la apertura antes de que venza. */
    public function cancelEnrollment(string $companyId, string $employeeId): void
    {
        $this->employeeOrFail($companyId, $employeeId);
        ncmExecute(
            'DELETE FROM employee_face_enrollment WHERE companyid = ? AND contactid = ?',
            [$companyId, $employeeId]
        );
    }

    /**
     * La apertura vigente que le toca a ESTE quiosco, si hay alguna.
     *
     * Devuelve una sola: dos personas esperando enrolarse en el mismo quiosco al
     * mismo tiempo es una situación que no existe (alguien está parado frente a
     * la cámara), y ofrecer una lista invitaría al operador a elegir — que es
     * justo la decisión que no queremos que tome el quiosco.
     *
     * @return array<string,mixed>|null
     */
    public function pendingEnrollment(string $companyId, string $outletId): ?array
    {
        $where  = ['f.companyid = ?', 'f.expiresat > now()', 'e.status = 1', 'e.enddate IS NULL'];
        $params = [$companyId];

        if (preg_match(self::UUID_RE, $outletId)) {
            $where[]  = '(f.outletid IS NULL OR f.outletid = ?)';
            $params[] = $outletId;
        } else {
            // Device sin sucursal resuelta: solo las aperturas sin sucursal.
            // Adivinar cuál le corresponde sería dejar que el contexto faltante
            // lo complete el servidor, que es lo que el POS tiene prohibido.
            $where[] = 'f.outletid IS NULL';
        }

        $row = ncmExecute(
            'SELECT f.contactid, f.expiresat, c.contactname, e.jobtitle
               FROM employee_face_enrollment f
               JOIN employee e ON e.contactid = f.contactid AND e.companyid = f.companyid
               JOIN contact  c ON c.contactid = f.contactid
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY f.createdat DESC
              LIMIT 1',
            $params
        );
        if (!$row) {
            return null;
        }

        return [
            'employeeId' => (string) $row['contactid'],
            'name'       => (string) $row['contactname'],
            'jobTitle'   => self::strOrNull($row['jobtitle'] ?? null),
            'expiresAt'  => (string) $row['expiresat'],
        ];
    }

    // ── Captura (quiosco) ───────────────────────────────────────────────────

    /**
     * Guarda el rostro capturado en el quiosco y CONSUME la apertura.
     *
     * A diferencia de la marcación, esto NO es fail-open y no debería serlo: un
     * enrolamiento que sale mal no deja a nadie sin poder registrar su jornada
     * —el PIN sigue estando— y en cambio un vector malo reconoce mal todos los
     * días hasta que alguien se da cuenta. Acá el rechazo es la respuesta
     * correcta: se vuelve a intentar y listo.
     *
     * @param array<int, array<int, float|int|string>> $samples vectores calculados en el dispositivo
     * @param array|null $photo fila de `$_FILES` con la foto de enrolamiento
     * @return array<string,mixed>
     */
    public function enroll(
        string $companyId,
        string $employeeId,
        array $samples,
        string $modelVersion,
        string $outletId,
        ?array $photo = null
    ): array {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            throw new \RuntimeException('No se indicó a quién corresponde el rostro');
        }

        $dims = self::SUPPORTED_MODELS[$modelVersion] ?? null;
        if ($dims === null) {
            // El dispositivo corre una versión que este servidor no conoce.
            // Guardarla igual sería sembrar vectores que nada va a poder
            // comparar; el mensaje apunta a lo único accionable.
            throw new \RuntimeException('Este dispositivo tiene una versión distinta de la app. Actualizalo y volvé a intentar');
        }

        // ── La apertura manda: existe, está viva y es de esta sucursal ──
        //
        // Se relee acá y no se confía en lo que el quiosco vio hace un minuto:
        // entre que se le mostró el nombre y llegó esta request pudo vencer, o
        // el panel pudo cancelarla.
        $pending = $this->pendingEnrollment($companyId, $outletId);
        if ($pending === null || $pending['employeeId'] !== $employeeId) {
            throw new \RuntimeException('El registro del rostro ya no está habilitado. Volvé a activarlo desde el legajo');
        }

        $employee = $this->employeeOrFail($companyId, $employeeId);
        if (($employee['biometricconsentat'] ?? null) === null) {
            // Segunda verificación: entre abrir y guardar pasaron minutos, y el
            // consentimiento se puede retirar en cualquiera de ellos.
            throw new \RuntimeException('Falta registrar en el legajo que la persona aceptó identificarse con su rostro');
        }

        // Quién AUTORIZÓ el enrolamiento, que es el dato que vale registrar —
        // no el dispositivo que capturó. Se lee de la apertura antes de
        // consumirla, y no viaja al quiosco en `pendingEnrollment()`: la caja no
        // tiene por qué saber qué usuario del panel lo habilitó.
        $authorizer = ncmExecute(
            'SELECT createdby FROM employee_face_enrollment WHERE companyid = ? AND contactid = ? LIMIT 1',
            [$companyId, $employeeId]
        );

        $vectors = $this->normalizeSamples($samples, $dims);
        $average = self::averageUnit($vectors);

        // La foto va ANTES del INSERT: una fila que promete una foto que no está
        // es peor que una fila sin foto. Si falla, el enrolamiento falla — al
        // revés que en la marcación, donde la foto jamás bloquea.
        $photoKey = null;
        if ($photo !== null && !empty($photo['tmp_name'])) {
            $photoKey = $this->storePhoto($companyId, $employeeId, $photo);
        }

        // Re-enrolar PISA: nadie tiene dos caras. El `DO UPDATE` es lo que hace
        // que "volver a registrar el rostro" sea una operación y no un borrado
        // seguido de un alta que puede quedar a mitad.
        ncmExecute(
            'INSERT INTO employee_face
                 (companyid, contactid, embedding, modelversion, samples, photokey, createdby)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (companyid, contactid, modelversion) DO UPDATE
                SET embedding = EXCLUDED.embedding,
                    samples   = EXCLUDED.samples,
                    photokey  = COALESCE(EXCLUDED.photokey, employee_face.photokey),
                    updatedat = now()',
            [
                $companyId,
                $employeeId,
                json_encode($average, JSON_THROW_ON_ERROR),
                $modelVersion,
                count($vectors),
                $photoKey,
                self::uuidOrNull($authorizer['createdby'] ?? null),
            ]
        );

        // La apertura se consume. Dejarla viva permitiría capturar de nuevo sin
        // que nadie lo autorice otra vez, que es exactamente lo que el permiso
        // acotado existe para impedir.
        ncmExecute(
            'DELETE FROM employee_face_enrollment WHERE companyid = ? AND contactid = ?',
            [$companyId, $employeeId]
        );

        $status = $this->statusFor($companyId, $employeeId);
        return $status ?? ['employeeId' => $employeeId, 'modelVersion' => $modelVersion];
    }

    // ── Bajada al quiosco ───────────────────────────────────────────────────

    /**
     * Los rostros contra los que puede comparar ESTE quiosco.
     *
     * Acotado por sucursal y por versión de modelo, y nada más que eso: el
     * quiosco recibe un id y un vector. No viaja el nombre acá —ya lo tiene del
     * roster de la F1, que baja en el bootstrap con el mismo gate— porque
     * duplicarlo sería mandar dos veces el mismo dato por dos caminos que pueden
     * quedar desfasados.
     *
     * Quién entra: los mismos que pueden marcar (vigentes, sin egreso, de esta
     * sucursal o sin sucursal). Un egresado cuyo vector todavía no se borró no
     * se sirve igual — el borrado del egreso es la garantía, este filtro es el
     * cinturón.
     *
     * @return array<int, array<string,mixed>>
     */
    public function facesForOutlet(string $companyId, string $outletId, string $modelVersion): array
    {
        if (!isset(self::SUPPORTED_MODELS[$modelVersion])) {
            // Versión desconocida: se devuelve vacío en vez de todo. Mandarle
            // vectores de otro modelo al dispositivo es peor que no mandarle
            // nada — compararía y obtendría números sin sentido.
            return [];
        }

        $where = [
            'f.companyid = ?',
            'f.modelversion = ?',
            'e.status = 1',
            'e.enddate IS NULL',
        ];
        $params = [$companyId, $modelVersion];

        if (preg_match(self::UUID_RE, $outletId)) {
            $where[]  = '(e.outletid IS NULL OR e.outletid = ?)';
            $params[] = $outletId;
        }

        $rs = ncmExecute(
            'SELECT f.contactid, f.embedding, f.modelversion, f.updatedat
               FROM employee_face f
               JOIN employee e ON e.contactid = f.contactid AND e.companyid = f.companyid
              WHERE ' . implode(' AND ', $where),
            $params,
            false,
            true
        );

        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $raw = $rs->fields['embedding'] ?? null;
                $vec = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
                if (is_array($vec) && $vec !== []) {
                    $rows[] = [
                        'employeeId'   => (string) $rs->fields['contactid'],
                        'embedding'    => array_map(static fn($n) => (float) $n, array_values($vec)),
                        'modelVersion' => (string) $rs->fields['modelversion'],
                        'updatedAt'    => self::strOrNull($rs->fields['updatedat'] ?? null),
                    ];
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    // ── Estado y borrado ────────────────────────────────────────────────────

    /**
     * Si esta persona tiene rostro registrado, y de cuándo. Lo que muestra la
     * ficha del legajo.
     *
     * El vector NO sale por acá. El panel solo necesita saber si hay o no hay —
     * mandarle la biometría a una pantalla que no la usa sería exponerla sin
     * ningún motivo.
     *
     * @return array<string,mixed>|null
     */
    public function statusFor(string $companyId, string $employeeId): ?array
    {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            return null;
        }
        $row = ncmExecute(
            'SELECT modelversion, samples, updatedat, createdat
               FROM employee_face
              WHERE companyid = ? AND contactid = ?
              ORDER BY updatedat DESC
              LIMIT 1',
            [$companyId, $employeeId]
        );
        if (!$row) {
            return null;
        }
        return [
            'employeeId'   => $employeeId,
            'modelVersion' => (string) $row['modelversion'],
            'samples'      => (int) ($row['samples'] ?? 1),
            'enrolledAt'   => self::strOrNull($row['updatedat'] ?? null)
                ?? self::strOrNull($row['createdat'] ?? null),
        ];
    }

    /**
     * Estado de varios legajos de una, para el listado del panel.
     *
     * Existe para que la grilla no haga una query por fila. Devuelve solo los
     * que TIENEN rostro; la ausencia en el mapa es "sin rostro".
     *
     * @param array<int,string> $employeeIds
     * @return array<string, array<string,mixed>>
     */
    public function statusForMany(string $companyId, array $employeeIds): array
    {
        $ids = array_values(array_filter(
            array_map(static fn($id) => (string) $id, $employeeIds),
            static fn($id) => preg_match(self::UUID_RE, $id) === 1
        ));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            'SELECT contactid, modelversion, samples, updatedat
               FROM employee_face
              WHERE companyid = ? AND contactid IN (' . $placeholders . ')',
            array_merge([$companyId], $ids),
            false,
            true
        );

        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $out[(string) $rs->fields['contactid']] = [
                    'employeeId'   => (string) $rs->fields['contactid'],
                    'modelVersion' => (string) $rs->fields['modelversion'],
                    'samples'      => (int) ($rs->fields['samples'] ?? 1),
                    'enrolledAt'   => self::strOrNull($rs->fields['updatedat'] ?? null),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Borra la biometría de una persona: el vector, su foto de enrolamiento y
     * cualquier apertura pendiente.
     *
     * Lo llama el EGRESO (D5), y también el borrado a pedido desde la ficha. No
     * es un job ni una limpieza diferida: el borrado es parte del egreso, y una
     * tarea programada es algo que puede no haber corrido el día que alguien
     * pregunta.
     *
     * No tira nunca. Se lo invoca desde dentro de un egreso que ya ocurrió: que
     * S3 esté caído no puede hacer fallar la baja de un empleado. Lo que sí
     * queda garantizado es que la fila —la única forma de volver a usar el
     * vector— desaparece.
     */
    public function deleteFor(string $companyId, string $employeeId): void
    {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            return;
        }

        $keys = [];
        $rs = ncmExecute(
            'SELECT photokey FROM employee_face WHERE companyid = ? AND contactid = ?',
            [$companyId, $employeeId],
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $key = self::strOrNull($rs->fields['photokey'] ?? null);
                if ($key !== null) {
                    $keys[] = $key;
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        ncmExecute('DELETE FROM employee_face WHERE companyid = ? AND contactid = ?', [$companyId, $employeeId]);
        ncmExecute('DELETE FROM employee_face_enrollment WHERE companyid = ? AND contactid = ?', [$companyId, $employeeId]);

        if ($this->s3 !== null) {
            foreach ($keys as $key) {
                try {
                    $this->s3->delete($key);
                } catch (\Throwable) {
                    // El objeto queda huérfano en el bucket. Es un residuo sin
                    // sujeto —la fila que decía de quién era ya no existe— y no
                    // vale hacer fallar un egreso por él.
                }
            }
        }
    }

    // ── Validación de los vectores ──────────────────────────────────────────

    /**
     * Valida las capturas y las devuelve normalizadas.
     *
     * Tres cosas se verifican acá, todas sobre datos que calculó el cliente:
     * que sean la cantidad esperada, que cada una tenga el largo del modelo
     * declarado, y que se parezcan ENTRE SÍ. Las dos primeras atrapan un cliente
     * roto; la tercera atrapa el caso real de que alguien se haya cruzado
     * delante de la cámara a mitad del enrolamiento.
     *
     * @param array<int, array<int, float|int|string>> $samples
     * @return array<int, array<int, float>> vectores unitarios
     */
    private function normalizeSamples(array $samples, int $dims): array
    {
        $vectors = [];
        foreach ($samples as $sample) {
            if (!is_array($sample) || count($sample) !== $dims) {
                throw new \RuntimeException('Las capturas no llegaron completas. Volvé a intentar');
            }
            $vec = [];
            foreach (array_values($sample) as $n) {
                if (!is_numeric($n)) {
                    throw new \RuntimeException('Las capturas no llegaron completas. Volvé a intentar');
                }
                $vec[] = (float) $n;
            }
            $unit = self::toUnit($vec);
            if ($unit === null) {
                throw new \RuntimeException('Las capturas no llegaron completas. Volvé a intentar');
            }
            $vectors[] = $unit;
        }

        $count = count($vectors);
        if ($count < self::MIN_SAMPLES || $count > self::MAX_SAMPLES) {
            throw new \RuntimeException('Hacen falta entre ' . self::MIN_SAMPLES . ' y ' . self::MAX_SAMPLES . ' tomas');
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::cosineDistance($vectors[$i], $vectors[$j]) > self::MAX_SAMPLE_SPREAD) {
                    throw new \RuntimeException('Las tomas no parecen de la misma persona. Volvé a intentar');
                }
            }
        }

        return $vectors;
    }

    /**
     * Promedio de vectores unitarios, vuelto a normalizar.
     *
     * Se promedia y no se guarda cada toma porque la comparación del quiosco es
     * uno contra todos: guardar cinco vectores por persona multiplicaría por
     * cinco el trabajo de cada marcación para ganar muy poco. El promedio de
     * varias tomas de la misma cara ya es más estable que cualquiera de ellas.
     *
     * @param array<int, array<int, float>> $vectors
     * @return array<int, float>
     */
    private static function averageUnit(array $vectors): array
    {
        $dims = count($vectors[0]);
        $sum  = array_fill(0, $dims, 0.0);
        foreach ($vectors as $vec) {
            for ($i = 0; $i < $dims; $i++) {
                $sum[$i] += $vec[$i];
            }
        }
        $count = count($vectors);
        for ($i = 0; $i < $dims; $i++) {
            $sum[$i] /= $count;
        }
        return self::toUnit($sum) ?? $sum;
    }

    /** @param array<int,float> $vec @return array<int,float>|null */
    private static function toUnit(array $vec): ?array
    {
        $norm = 0.0;
        foreach ($vec as $n) {
            if (!is_finite($n)) {
                return null;
            }
            $norm += $n * $n;
        }
        $norm = sqrt($norm);
        if ($norm <= 1e-9) {
            return null;
        }
        return array_map(static fn($n) => $n / $norm, $vec);
    }

    /**
     * Distancia coseno entre dos vectores YA unitarios: `1 - producto punto`.
     *
     * 0 = idénticos, 1 = sin relación. Es el mismo cálculo que corre el
     * dispositivo (`face-match.ts`); acá se usa solo para el chequeo de
     * coherencia del enrolamiento — identificar NO se hace en el servidor.
     *
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    private static function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $n   = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return 1.0 - $dot;
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    /**
     * Guarda la foto de enrolamiento. PRIVADA, como todo lo del legajo.
     *
     * La clave lleva el id del empleado y nada más: hay UNA foto de enrolamiento
     * por persona y re-enrolar la pisa, igual que pisa el vector. Un histórico
     * de caras viejas sería biometría que nadie pidió guardar.
     */
    private function storePhoto(string $companyId, string $employeeId, array $photo): string
    {
        if ($this->s3 === null) {
            throw new \RuntimeException('No se pudo guardar la foto. Volvé a intentar');
        }
        $size = (int) ($photo['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_PHOTO_BYTES) {
            throw new \RuntimeException('No se pudo guardar la foto. Volvé a intentar');
        }
        $tmp = (string) ($photo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('No se pudo guardar la foto. Volvé a intentar');
        }
        // El tipo sale del CONTENIDO, no del header que declaró el cliente.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        if ((string) $finfo->file($tmp) !== 'image/jpeg') {
            throw new \RuntimeException('No se pudo guardar la foto. Volvé a intentar');
        }
        $data = file_get_contents($tmp);
        if ($data === false || $data === '') {
            throw new \RuntimeException('No se pudo guardar la foto. Volvé a intentar');
        }

        $key = sprintf('employees/%s/%s/face/enrollment.jpg', $companyId, $employeeId);
        $this->s3->put($key, $data, 'image/jpeg', false);
        return $key;
    }

    /**
     * Los BYTES de la foto de enrolamiento, para servirla desde el panel.
     *
     * Devuelve la foto y no su clave, igual que `AttendanceService::photo()`: la
     * clave de un objeto privado no tiene por qué salir de este archivo.
     */
    public function photo(string $companyId, string $employeeId): ?string
    {
        if (!preg_match(self::UUID_RE, $employeeId) || $this->s3 === null) {
            return null;
        }
        $row = ncmExecute(
            'SELECT photokey FROM employee_face
              WHERE companyid = ? AND contactid = ?
              ORDER BY updatedat DESC LIMIT 1',
            [$companyId, $employeeId]
        );
        $key = $row ? self::strOrNull($row['photokey'] ?? null) : null;
        return $key === null ? null : $this->s3->get($key);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * La fila del legajo, o se corta.
     *
     * SIN tipo de retorno y SIN castear a `array`, a propósito: `ncmExecute()`
     * devuelve la fila envuelta por el wrapper del DB layer, que resuelve las
     * claves sin distinguir mayúsculas. Un `(array)` encima de ese objeto
     * devuelve sus propiedades internas y deja de responder a los nombres de
     * columna — y no falla: las claves simplemente salen `null`, que acá se
     * leería como "esta persona no dio su consentimiento". Es el mismo criterio
     * con el que el resto de los services pasan la fila tal cual (ver `shape()`
     * en `EmployeeService`).
     */
    private function employeeOrFail(string $companyId, string $employeeId)
    {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        $row = ncmExecute(
            'SELECT contactid, outletid, status, enddate, biometricconsentat
               FROM employee
              WHERE contactid = ? AND companyid = ?
              LIMIT 1',
            [$employeeId, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        return $row;
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
}
