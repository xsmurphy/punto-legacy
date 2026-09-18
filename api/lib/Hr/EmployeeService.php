<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

/**
 * Legajo del empleado (`employee`, migs 229 + 232) — RRHH, context/83 §9.1.
 *
 * Un empleado ES un usuario del sistema. El legajo es un SATÉLITE 1:1 del
 * `contact` type=0: misma persona, misma identidad, mismo PIN. El personal que
 * no opera Punto es un usuario SIN PERMISOS — no una entidad paralela.
 *
 * Por eso acá NO viven el nombre, el teléfono ni el email: salen de `contact`
 * por JOIN. Copiarlos daba dos nombres para la misma persona y nadie los
 * sincronizaba (la caja saludaba con uno, el reporte de asistencia imprimía el
 * otro). Lo que sí vive acá es la RELACIÓN LABORAL: fechas, puesto,
 * remuneración, horario, consentimiento biométrico.
 *
 * Dos bajas distintas, a propósito (ver el docblock de la migración):
 *   - `terminate()` = EGRESO. Escribe `enddate`. La fila se queda: es el
 *     historial laboral, que es justamente lo que el plan pide conservar.
 *   - `archive()`   = la fila cargada por error sale del listado (status=0).
 *
 * Multi-tenant: `$companyId` explícito en TODA query (§33.2). Las dos
 * referencias que el payload puede traer —`contactId` y `outletId`— se validan
 * contra el tenant antes de escribirse: sin eso, un id de otro comercio
 * entraría por el formulario y la FK no lo notaría (apunta a la tabla, no al
 * tenant).
 *
 * Auditoría: NO se registra acá. El embudo `apiAuthTenant()` audita toda
 * mutación bajo `/v1/` con la atribución de actor ya resuelta
 * (`api/bootstrap.php`, `AuditActor`) — un registro propio sería una segunda
 * fila del mismo hecho.
 */
final class EmployeeService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Periodicidad del componente FIJO. Debe coincidir con el CHECK de la mig 229. */
    public const FIXED_PERIODS = ['monthly', 'biweekly', 'weekly'];

    /** Filtro de estado laboral del listado. */
    public const STATES = ['active', 'terminated', 'all'];

    /**
     * La proyección del legajo, con la identidad del contacto pegada.
     *
     * Una sola constante para el listado y el detalle: cuando estaban
     * duplicadas, agregar una columna significaba acordarse de los dos lugares.
     *
     * `c.pinhash` viaja para responder UNA pregunta —¿esta persona tiene código?—
     * y el `shape()` lo colapsa a un booleano. El hash nunca sale del panel: es
     * SHA-256 sin sal de 4 dígitos, o sea el PIN mismo para quien tenga cinco
     * minutos.
     */
    private const SELECT_BASE = '
        SELECT e.*,
               o.outletname   AS outletname,
               c.contactname  AS contactname,
               c.contactphone AS contactphone,
               c.contactemail AS contactemail,
               c.pinhash      AS pinhash,
               c.contactstatus AS contactstatus
          FROM employee e
          JOIN contact  c ON c.contactid = e.contactid
          LEFT JOIN outlet o ON o.outletid = e.outletid';

    /**
     * El rostro registrado (F2, mig 231), inyectado.
     *
     * Se inyecta y no se construye acá porque necesita el cliente S3 para poder
     * borrar la FOTO de enrolamiento junto con el vector, y el S3 se arma en el
     * endpoint como en todo el resto del proyecto. Sin él, el egreso borraría la
     * fila pero dejaría la cara en el bucket — que es media promesa de la D5.
     *
     * `null` = este llamador no maneja biometría (un CLI, un test del legajo).
     * El legajo funciona igual; lo que no hace es limpiar lo que no conoce.
     */
    private ?EmployeeFaceService $faces;

    public function __construct(?EmployeeFaceService $faces = null)
    {
        $this->faces = $faces;
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * Listado del panel.
     *
     * @param array{q?:?string,state?:?string,outletId?:?string,includeArchived?:bool} $filters
     * @return array<int, array<string,mixed>>
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = ['e.companyid = ?'];
        $params = [$companyId];

        if (empty($filters['includeArchived'])) {
            $where[] = 'e.status = 1';
        }

        $state = (string) ($filters['state'] ?? 'all');
        if ($state === 'active') {
            $where[] = 'e.enddate IS NULL';
        } elseif ($state === 'terminated') {
            $where[] = 'e.enddate IS NOT NULL';
        }

        if (!empty($filters['outletId']) && preg_match(self::UUID_RE, (string) $filters['outletId'])) {
            $where[] = 'e.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }

        // Búsqueda por nombre, documento o puesto. El nombre es el del CONTACTO
        // (mig 232): el legajo ya no guarda una copia. `unaccent` NO se usa: no
        // está garantizada en todos los despliegues y el resto del panel
        // busca igual con ILIKE.
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(c.contactname ILIKE ? OR e.documentnumber ILIKE ? OR e.jobtitle ILIKE ?)';
            array_push($params, $like, $like, $like);
        }

        // JOIN y no LEFT JOIN: `contactid` es la PK y tiene FK con CASCADE, así
        // que un legajo sin contacto no puede existir. Un LEFT acá solo serviría
        // para devolver filas con el nombre en null y esconder una corrupción.
        $sql = self::SELECT_BASE
             . ' WHERE ' . implode(' AND ', $where)
             . ' ORDER BY c.contactname ASC';

        $rs = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $this->withFaces($companyId, $rows);
    }

    /**
     * Pega el estado del rostro (F2) a un lote de legajos ya leídos.
     *
     * Una consulta para todo el listado y no una por fila: el panel muestra
     * "sin rostro / registrado" en cada renglón, y resolverlo dentro de
     * `shape()` sería un N+1 que crece con el equipo.
     *
     * Ausente del mapa = sin rostro registrado. Esa es la única lectura posible,
     * y por eso la clave queda en `null` en vez de omitirse: el front distingue
     * "no tiene" de "esta versión del backend no sabe del tema".
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    private function withFaces(string $companyId, array $rows): array
    {
        if ($rows === [] || $this->faces === null) {
            return $rows;
        }
        $status = $this->faces->statusForMany(
            $companyId,
            array_map(static fn($r) => (string) $r['id'], $rows)
        );
        foreach ($rows as $i => $row) {
            $rows[$i]['face'] = $status[(string) $row['id']] ?? null;
        }
        return $rows;
    }

    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute(
            self::SELECT_BASE . ' WHERE e.contactid = ? AND e.companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            return null;
        }
        return $this->withFaces($companyId, [$this->shape($row)])[0];
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * Le cuelga el legajo a un usuario que YA existe.
     *
     * El usuario se elige o se crea ANTES, en el endpoint (`employees.php`), con
     * `UsersService`: crear la credencial es asunto del servicio de usuarios, y
     * duplicar acá su validación —teléfono, email repetido, tope del plan— sería
     * tener dos altas de usuario que se separan con el primer cambio.
     *
     * @param array<string,mixed> $data
     */
    public function create(string $companyId, array $data, ?string $actorId = null): array
    {
        $records = $this->buildRecords($companyId, $data, true);
        $records['companyid'] = $companyId;
        $records['status']    = 1;
        $records['createdby'] = self::uuidOrNull($actorId);
        $records['updatedby'] = self::uuidOrNull($actorId);

        // La PK la trae el payload (es la persona), no la genera la base: el
        // id ya existe antes del INSERT y por eso no se lee del retorno.
        $contactId = (string) $records['contactid'];

        $this->guardUnique(static fn() => ncmInsert([
            'records' => $records,
            'table'   => 'employee',
        ]));

        $row = $this->find($contactId, $companyId);
        if (!$row) {
            throw new \RuntimeException('Empleado creado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Update PARCIAL: solo se toca lo que viene en el payload. Un formulario
     * que edita el puesto no puede borrar la fecha de nacimiento por no
     * mandarla.
     *
     * @param array<string,mixed> $data
     */
    public function update(string $id, string $companyId, array $data, ?string $actorId = null): array
    {
        $current = $this->find($id, $companyId);
        if (!$current) {
            throw new \RuntimeException('Empleado no encontrado');
        }

        $records = $this->buildRecords($companyId, $data, false, $current);
        if (empty($records)) {
            return $current;
        }
        $records['updatedby'] = self::uuidOrNull($actorId);

        // UPDATE armado a mano y no `ncmUpdate()` por UNA razón: `updatedat`
        // tiene que salir del `now()` del servidor. Pasarlo por el record
        // obligaría a mandar un timestamp de PHP, que se interpreta en la zona
        // de la SESIÓN de Postgres (`TenantClock`) y no en la del proceso —
        // dos relojes distintos para un dato que solo sirve si es uno.
        $sets   = [];
        $params = [];
        foreach ($records as $col => $value) {
            $sets[]   = $col . ' = ?';
            $params[] = $value;
        }
        $sets[]   = 'updatedat = now()';
        $params[] = $id;
        $params[] = $companyId;

        $sql = 'UPDATE employee SET ' . implode(', ', $sets)
             . ' WHERE contactid = ? AND companyid = ?';

        $this->guardUnique(static fn() => ncmExecute($sql, $params));

        // Retirar el consentimiento BORRA la cara registrada.
        //
        // Sin esto el consentimiento sería decorativo: se retira la autorización
        // y el dato sigue ahí, sirviéndose a los quioscos todos los días. Que
        // "retiré el permiso" y "el dato se fue" sean la misma operación es lo
        // que hace que la casilla del legajo signifique algo.
        if (array_key_exists('biometricconsentat', $records) && $records['biometricconsentat'] === null) {
            $this->faces?->deleteFor($companyId, $id);
        }

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el empleado actualizado');
        }
        return $row;
    }

    /**
     * EGRESO. No borra ni archiva: escribe la fecha de salida y el legajo
     * queda como historial (context/83 §3).
     *
     * Reabrir un legajo (volver `enddate` a NULL) se hace por `update()` con
     * `endDate: null` — recontratar a alguien es una edición del legajo, no
     * una operación aparte.
     */
    public function terminate(
        string $id,
        string $companyId,
        ?string $endDate,
        ?string $reason,
        ?string $actorId = null
    ): array {
        $current = $this->find($id, $companyId);
        if (!$current) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        if ($current['endDate'] !== null) {
            throw new \RuntimeException('El empleado ya tiene registrada su fecha de egreso');
        }

        $date = self::dateOrNull($endDate) ?? date('Y-m-d');
        if ($current['hireDate'] !== null && $date < $current['hireDate']) {
            throw new \RuntimeException('La fecha de egreso no puede ser anterior a la de ingreso');
        }

        ncmExecute(
            'UPDATE employee
                SET enddate = ?, endreason = ?, updatedby = ?, updatedat = now()
              WHERE contactid = ? AND companyid = ? AND enddate IS NULL',
            [$date, self::textOrNull($reason), self::uuidOrNull($actorId), $id, $companyId]
        );

        // La biometría se borra CON el egreso (D5). No se difiere a un job ni se
        // deja para una limpieza posterior: "se borra al irse" tiene que ser
        // cierto el día que alguien lo pregunta, y una tarea programada es algo
        // que puede no haber corrido.
        //
        // El legajo, en cambio, SOBREVIVE — es historial laboral. Lo que
        // desaparece es solo la cara: el dato que se guardó para reconocer a
        // alguien que ya no viene a trabajar.
        $this->faces?->deleteFor($companyId, $id);

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el empleado');
        }
        return $row;
    }

    /**
     * Archiva la fila (status=0). Es para el legajo cargado por error, NO para
     * el empleado que se fue — para eso está `terminate()`.
     */
    public function archive(string $id, string $companyId): void
    {
        if (!$this->find($id, $companyId)) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        ncmExecute(
            'UPDATE employee SET status = 0, updatedat = now() WHERE contactid = ? AND companyid = ?',
            [$id, $companyId]
        );
        // Una fila archivada es una que no debería existir. Su biometría tampoco.
        $this->faces?->deleteFor($companyId, $id);
    }

    // ── Normalización y validación ──────────────────────────────────────────

    /**
     * Payload → columnas. En `create` los requeridos se exigen; en `update`
     * solo se mapea lo que vino (patch parcial).
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $current fila actual, para validar contra ella en update
     * @return array<string,mixed>
     */
    private function buildRecords(
        string $companyId,
        array $data,
        bool $isCreate,
        ?array $current = null
    ): array {
        $rec = [];

        // ── La persona ──
        //
        // Solo en el ALTA: el legajo no cambia de dueño. Mover un historial
        // laboral de una persona a otra no es una edición, es un error de carga
        // que se corrige archivando la fila y cargándola bien.
        if ($isCreate) {
            $rec['contactid'] = $this->resolveContact($data['contactId'] ?? null, $companyId);
        }

        // ── Fechas de la relación laboral ──
        if ($isCreate || array_key_exists('hireDate', $data)) {
            $hire = self::dateOrNull($data['hireDate'] ?? null);
            if ($hire === null) {
                throw new \RuntimeException('La fecha de ingreso es requerida');
            }
            $rec['hiredate'] = $hire;
        }
        if (array_key_exists('endDate', $data)) {
            $rec['enddate'] = self::dateOrNull($data['endDate']);
        }
        if (array_key_exists('endReason', $data)) {
            $rec['endreason'] = self::textOrNull($data['endReason']);
        }

        // El CHECK de la BD es la red final; esto da el mensaje entendible.
        $hire = $rec['hiredate'] ?? ($current['hireDate'] ?? null);
        $end  = array_key_exists('enddate', $rec) ? $rec['enddate'] : ($current['endDate'] ?? null);
        if ($hire !== null && $end !== null && $end < $hire) {
            throw new \RuntimeException('La fecha de egreso no puede ser anterior a la de ingreso');
        }

        // ── Datos del legajo ──
        //
        // El nombre, el teléfono y el email NO están acá: son del CONTACTO
        // (mig 232) y se editan donde se edita el usuario. Lo que queda es lo
        // que el legajo necesita y la ficha de usuario no tiene.
        if (array_key_exists('documentNumber', $data)) {
            $rec['documentnumber'] = self::textOrNull($data['documentNumber']);
        }
        if (array_key_exists('address', $data)) {
            $rec['address'] = self::textOrNull($data['address']);
        }
        if (array_key_exists('birthDate', $data)) {
            $rec['birthdate'] = self::dateOrNull($data['birthDate']);
        }
        if (array_key_exists('jobTitle', $data)) {
            $rec['jobtitle'] = self::textOrNull($data['jobTitle']);
        }
        if (array_key_exists('notes', $data)) {
            $rec['notes'] = self::textOrNull($data['notes']);
        }

        // ── Referencias: se validan contra el TENANT, no solo contra la FK ──
        if (array_key_exists('outletId', $data)) {
            $rec['outletid'] = $this->resolveOutlet($data['outletId'], $companyId);
        }

        // ── Remuneración (los tres conviven, §2 / D1) ──
        //
        // Monto y período son UN dato: si viene uno, se resuelve el par entero
        // (tomando del payload o de la fila actual) y se escriben los dos.
        // Escribir solo la mitad deja una fila que el CHECK rechaza y un
        // mensaje de BD que el operador no entiende.
        if (array_key_exists('fixedAmount', $data) || array_key_exists('fixedPeriod', $data)) {
            $amount = array_key_exists('fixedAmount', $data)
                ? self::amountOrNull($data['fixedAmount'])
                : ($current['fixedAmount'] ?? null);
            $period = array_key_exists('fixedPeriod', $data)
                ? self::textOrNull($data['fixedPeriod'])
                : ($current['fixedPeriod'] ?? null);

            if ($amount === null) {
                $period = null; // sin monto, el período no dice nada
            } elseif ($period === null) {
                throw new \RuntimeException('Elegí cada cuánto se paga el monto fijo');
            } elseif (!in_array($period, self::FIXED_PERIODS, true)) {
                throw new \RuntimeException('La periodicidad del monto fijo no es válida');
            }

            $rec['fixedamount'] = $amount;
            $rec['fixedperiod'] = $period;
        }
        if (array_key_exists('hourlyRate', $data)) {
            $rec['hourlyrate'] = self::amountOrNull($data['hourlyRate']);
        }
        if (array_key_exists('commissions', $data)) {
            // BOOLEAN de PG: true/false PHP, nunca 0/1 (§46).
            $rec['commissions'] = self::boolOf($data['commissions']);
        }

        // El PIN de marcación murió acá (mig 232, §9.3): hay UN solo PIN por
        // persona, el del usuario, y se gestiona en su ficha como siempre. Es
        // además OPCIONAL — quien solo marca asistencia lo hace con la cara.

        // ── Horario declarado (F1) ──
        if (array_key_exists('schedule', $data)) {
            $rec['schedule'] = self::scheduleOrNull($data['schedule']);
        }

        // ── Consentimiento biométrico (se usa en F2) ──
        //
        // Se registra como un HECHO con fecha y autor: "consintió". Retirarlo
        // vuelve las dos columnas a NULL.
        if (array_key_exists('biometricConsent', $data)) {
            $given = self::boolOf($data['biometricConsent']);
            $already = ($current['biometricConsentAt'] ?? null) !== null;
            if ($given && !$already) {
                $rec['biometricconsentat'] = date('Y-m-d H:i:sP');
                $rec['biometricconsentby'] = self::uuidOrNull($data['actorId'] ?? null);
            } elseif (!$given && $already) {
                $rec['biometricconsentat'] = null;
                $rec['biometricconsentby'] = null;
            }
        }

        return $rec;
    }

    /** null explícito desvincula; un id ajeno al tenant se rechaza. */
    private function resolveOutlet(mixed $value, string $companyId): ?string
    {
        $id = self::textOrNull($value);
        if ($id === null) {
            return null;
        }
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('La sucursal indicada no es válida');
        }
        $row = ncmExecute(
            'SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('La sucursal indicada no existe');
        }
        return $id;
    }

    /**
     * La persona del legajo. Tiene que ser un `contact` type=0 del MISMO
     * comercio: la FK apunta a `contact` entera, así que sin este chequeo se
     * podría cargar el legajo de un CLIENTE —o del usuario de otro tenant— y
     * quedaría atribuido a alguien que no es del equipo.
     */
    private function resolveContact(mixed $value, string $companyId): string
    {
        $id = self::textOrNull($value);
        if ($id === null || !preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('El legajo tiene que corresponder a una persona del equipo');
        }
        $row = ncmExecute(
            'SELECT contactid FROM contact
              WHERE contactid = ? AND companyid = ? AND type = 0
              LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Esa persona no pertenece al equipo del comercio');
        }
        return $id;
    }

    /** @param array<string,string> $extra índices adicionales a traducir */
    private function guardUnique(callable $fn)
    {
        return \Punto\Api\Support\UniqueViolation::guard(
            $fn,
            [
                // La PK. Elegir a alguien que ya tiene legajo es el choque más
                // probable del alta, y sin traducir salía como error crudo de BD.
                'employee_pkey'          => 'Esa persona ya tiene un legajo cargado',
                'uidx_employee_document' => 'Ya hay un empleado cargado con ese documento',
            ],
            'Ya existe un empleado con esos datos',
        );
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * Fila → shape de la API (camelCase). Los montos salen como float y no
     * como el string que devuelve PDO para NUMERIC: el front los formatea con
     * los helpers del tenant y un string rompe el `<MoneyInput>`.
     */
    private function shape($f): array
    {
        return [
            // El id del legajo ES el de la persona (mig 232). La clave sigue
            // llamándose `id` para todo lo que ya la consume.
            'id'                 => (string) $f['contactid'],
            'fullName'           => (string) ($f['contactname'] ?? ''),
            'documentNumber'     => self::strOrNull($f['documentnumber'] ?? null),
            'phone'              => self::strOrNull($f['contactphone'] ?? null),
            'email'              => self::strOrNull($f['contactemail'] ?? null),
            'address'            => self::strOrNull($f['address'] ?? null),
            'birthDate'          => self::strOrNull($f['birthdate'] ?? null),
            'jobTitle'           => self::strOrNull($f['jobtitle'] ?? null),
            'hireDate'           => self::strOrNull($f['hiredate'] ?? null),
            'endDate'            => self::strOrNull($f['enddate'] ?? null),
            'endReason'          => self::strOrNull($f['endreason'] ?? null),
            'outletId'           => self::strOrNull($f['outletid'] ?? null),
            'outletName'         => self::strOrNull($f['outletname'] ?? null),
            // El usuario del sistema no es un vínculo opcional: es la misma
            // fila. Se expone para que el panel pueda linkear a su ficha sin
            // tener que saber que los dos ids son el mismo.
            'userId'             => (string) $f['contactid'],
            // ¿La credencial está activa? Un legajo vigente con el usuario
            // desactivado es alguien que sigue trabajando pero no puede entrar
            // al sistema — caso normal desde que el personal sin login es un
            // usuario más, y el listado lo tiene que poder distinguir.
            'userActive'         => ((int) ($f['contactstatus'] ?? 1)) === 1,
            'fixedAmount'        => self::floatOrNull($f['fixedamount'] ?? null),
            'fixedPeriod'        => self::strOrNull($f['fixedperiod'] ?? null),
            'hourlyRate'         => self::floatOrNull($f['hourlyrate'] ?? null),
            'commissions'        => self::boolOf($f['commissions'] ?? false),
            'notes'              => self::strOrNull($f['notes'] ?? null),
            // Si esta persona tiene código, no cuál. El hash no sale: es
            // SHA-256 sin sal de 4 dígitos, o sea el PIN mismo para quien tenga
            // cinco minutos. Al reloj de marcación baja por otro camino y con
            // otro gate (realm `pos-app` + device `clock`).
            //
            // Es el PIN del USUARIO (`contact.pinhash`), el único que hay desde
            // la mig 232, y es OPCIONAL: sin código y sin rostro no se puede
            // marcar, que es exactamente lo que el legajo tiene que dejar ver.
            'hasPin'             => self::strOrNull($f['pinhash'] ?? null) !== null,
            'schedule'           => self::decodeSchedule($f['schedule'] ?? null),
            'biometricConsentAt' => self::strOrNull($f['biometricconsentat'] ?? null),
            'status'             => (int) ($f['status'] ?? 1),
            // Derivado y no columna: "activo" es no tener egreso. Guardarlo
            // sería un segundo estado que puede divergir de `enddate`.
            'active'             => ($f['enddate'] ?? null) === null,
            'createdAt'          => self::strOrNull($f['createdat'] ?? null),
            'updatedAt'          => self::strOrNull($f['updatedat'] ?? null),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Días válidos del horario declarado, en el orden de `EXTRACT(ISODOW)`. */
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Horario declarado → JSON para la columna, o `null`.
     *
     * Se NORMALIZA en vez de guardarse tal cual: es un JSONB, o sea que la base
     * acepta cualquier cosa que sea JSON válido. Sin normalizar, un día con la
     * clave mal escrita o una hora en un formato raro entra sin ruido y
     * reaparece meses después como una tardanza que nadie sabe explicar.
     *
     * Forma de salida — la única que el reporte sabe leer:
     *   { "days": { "mon": {"in":"08:00","out":"17:00"}, ... },
     *     "toleranceMinutes": 10 }
     *
     * Un día sin entrada válida se DESCARTA (no es laborable). Sin ningún día
     * válido, el horario entero es `null`: un horario vacío y la ausencia de
     * horario significan lo mismo —no hay contra qué medir— y tener dos formas
     * de decirlo obligaría a chequear las dos en cada lectura.
     */
    private static function scheduleOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($raw)) {
            throw new \RuntimeException('El horario no es válido');
        }

        $days = is_array($raw['days'] ?? null) ? $raw['days'] : [];
        $out  = [];
        foreach (self::WEEKDAYS as $day) {
            $entry = $days[$day] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $in    = self::timeOrNull($entry['in']  ?? null);
            $leave = self::timeOrNull($entry['out'] ?? null);
            if ($in === null) {
                // Sin hora de entrada no hay tardanza que medir ni turno que
                // declarar: ese día no es laborable, diga lo que diga el resto.
                continue;
            }
            $out[$day] = ['in' => $in, 'out' => $leave];
        }

        if ($out === []) {
            return null;
        }

        // Tolerancia: los minutos de gracia antes de contar una llegada como
        // tarde. Vive en el horario y no en un ajuste del comercio porque es
        // parte del acuerdo con esa persona. Techo de 4 horas: más que eso no
        // es tolerancia, es otro horario.
        $tolerance = (int) ($raw['toleranceMinutes'] ?? 0);
        $tolerance = max(0, min(240, $tolerance));

        return json_encode(['days' => $out, 'toleranceMinutes' => $tolerance]);
    }

    /** Columna JSONB → array para la API. */
    private static function decodeSchedule(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return (is_array($decoded) && !empty($decoded['days'])) ? $decoded : null;
    }

    /** 'H:MM' / 'HH:MM' → 'HH:MM'. `null` si no es una hora del día. */
    private static function timeOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || !preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    private static function strOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = (string) $v;
        return $s === '' ? null : $s;
    }

    private static function textOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function uuidOrNull(mixed $v): ?string
    {
        $s = self::textOrNull($v);
        return ($s !== null && preg_match(self::UUID_RE, $s)) ? $s : null;
    }

    /** 'YYYY-MM-DD' válido, o null. Una fecha basura se rechaza, no se guarda. */
    private static function dateOrNull(mixed $v): ?string
    {
        $s = self::textOrNull($v);
        if ($s === null) return null;
        $s = substr($s, 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new \RuntimeException('La fecha no es válida');
        }
        return $s;
    }

    private static function amountOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) {
            throw new \RuntimeException('El monto no es válido');
        }
        $n = (float) $v;
        if ($n < 0) {
            throw new \RuntimeException('El monto no puede ser negativo');
        }
        return $n;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /** PDO devuelve 't'/'f' para BOOLEAN; el front manda true/false o "true". */
    private static function boolOf(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true);
    }
}
