<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

/**
 * Legajo del empleado (`employee`, mig 229) — RRHH F0, context/83 §3 y §7.
 *
 * Un empleado NO es un usuario del sistema. Los usuarios son `contact` con
 * type=0 (credencial: PIN, rol, permisos); el legajo es la relación laboral,
 * y existe igual para quien nunca toca Punto. El vínculo `userId` es
 * OPCIONAL y se puede soltar sin tocar el legajo (D2).
 *
 * Dos bajas distintas, a propósito (ver el docblock de la migración):
 *   - `terminate()` = EGRESO. Escribe `enddate`. La fila se queda: es el
 *     historial laboral, que es justamente lo que el plan pide conservar.
 *   - `archive()`   = la fila cargada por error sale del listado (status=0).
 *
 * Multi-tenant: `$companyId` explícito en TODA query (§33.2). Las dos
 * referencias que el payload puede traer —`userId` y `outletId`— se validan
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

        // Búsqueda por nombre, documento o puesto. `unaccent` NO se usa: no
        // está garantizada en todos los despliegues y el resto del panel
        // busca igual con ILIKE.
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(e.fullname ILIKE ? OR e.documentnumber ILIKE ? OR e.jobtitle ILIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $sql = 'SELECT e.*, o.outletname AS outletname, c.contactname AS username
                  FROM employee e
                  LEFT JOIN outlet  o ON o.outletid  = e.outletid
                  LEFT JOIN contact c ON c.contactid = e.userid
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY e.fullname ASC';

        $rs = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute(
            'SELECT e.*, o.outletname AS outletname, c.contactname AS username
               FROM employee e
               LEFT JOIN outlet  o ON o.outletid  = e.outletid
               LEFT JOIN contact c ON c.contactid = e.userid
              WHERE e.employeeid = ? AND e.companyid = ?
              LIMIT 1',
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function create(string $companyId, array $data, ?string $actorId = null): array
    {
        $records = $this->buildRecords($companyId, $data, true);
        $records['companyid'] = $companyId;
        $records['status']    = 1;
        $records['createdby'] = self::uuidOrNull($actorId);
        $records['updatedby'] = self::uuidOrNull($actorId);

        $id = $this->guardUnique(static fn() => ncmInsert([
            'records' => $records,
            'table'   => 'employee',
        ]));

        if (!$id || $id === true) {
            throw new \RuntimeException('No se pudo crear el empleado');
        }

        $row = $this->find((string) $id, $companyId);
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
             . ' WHERE employeeid = ? AND companyid = ?';

        $this->guardUnique(static fn() => ncmExecute($sql, $params));

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
              WHERE employeeid = ? AND companyid = ? AND enddate IS NULL',
            [$date, self::textOrNull($reason), self::uuidOrNull($actorId), $id, $companyId]
        );

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
            'UPDATE employee SET status = 0, updatedat = now() WHERE employeeid = ? AND companyid = ?',
            [$id, $companyId]
        );
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

        // ── Nombre ──
        if ($isCreate || array_key_exists('fullName', $data)) {
            $name = trim((string) ($data['fullName'] ?? ''));
            if ($name === '') {
                throw new \RuntimeException('El nombre del empleado es requerido');
            }
            $rec['fullname'] = $name;
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

        // ── Datos personales ──
        if (array_key_exists('documentNumber', $data)) {
            $rec['documentnumber'] = self::textOrNull($data['documentNumber']);
        }
        if (array_key_exists('phone', $data)) {
            // E.164 sin '+' — convención de almacenamiento del proyecto. El
            // país sale del payload o del tenant, nunca de un default fijo.
            $iso = strtoupper(trim((string) ($data['country'] ?? '')))
                ?: \Punto\Api\Support\TenantLocale::country($companyId);
            $rec['phone'] = phoneValidateForStorage(
                self::textOrNull($data['phone']),
                $iso,
                'El teléfono no es válido'
            );
        }
        if (array_key_exists('email', $data)) {
            $email = self::textOrNull($data['email']);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('El email no es válido');
            }
            $rec['email'] = $email;
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
        if (array_key_exists('userId', $data)) {
            $rec['userid'] = $this->resolveUser($data['userId'], $companyId);
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
     * El vínculo al usuario del sistema. Tiene que ser un `contact` type=0 del
     * MISMO comercio: la FK apunta a `contact` entera, así que sin este
     * chequeo se podría vincular un CLIENTE —o el usuario de otro tenant— y el
     * legajo quedaría atribuido a alguien que no es del equipo.
     */
    private function resolveUser(mixed $value, string $companyId): ?string
    {
        $id = self::textOrNull($value);
        if ($id === null) {
            return null;
        }
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('El usuario indicado no es válido');
        }
        $row = ncmExecute(
            'SELECT contactid FROM contact
              WHERE contactid = ? AND companyid = ? AND type = 0
              LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('El usuario indicado no pertenece al equipo del comercio');
        }
        return $id;
    }

    /** @param array<string,string> $extra índices adicionales a traducir */
    private function guardUnique(callable $fn)
    {
        return \Punto\Api\Support\UniqueViolation::guard(
            $fn,
            [
                'uidx_employee_user'     => 'Ese usuario ya está vinculado a otro empleado',
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
            'id'                 => (string) $f['employeeid'],
            'fullName'           => (string) $f['fullname'],
            'documentNumber'     => self::strOrNull($f['documentnumber'] ?? null),
            'phone'              => self::strOrNull($f['phone'] ?? null),
            'email'              => self::strOrNull($f['email'] ?? null),
            'address'            => self::strOrNull($f['address'] ?? null),
            'birthDate'          => self::strOrNull($f['birthdate'] ?? null),
            'jobTitle'           => self::strOrNull($f['jobtitle'] ?? null),
            'hireDate'           => self::strOrNull($f['hiredate'] ?? null),
            'endDate'            => self::strOrNull($f['enddate'] ?? null),
            'endReason'          => self::strOrNull($f['endreason'] ?? null),
            'outletId'           => self::strOrNull($f['outletid'] ?? null),
            'outletName'         => self::strOrNull($f['outletname'] ?? null),
            'userId'             => self::strOrNull($f['userid'] ?? null),
            'userName'           => self::strOrNull($f['username'] ?? null),
            'fixedAmount'        => self::floatOrNull($f['fixedamount'] ?? null),
            'fixedPeriod'        => self::strOrNull($f['fixedperiod'] ?? null),
            'hourlyRate'         => self::floatOrNull($f['hourlyrate'] ?? null),
            'commissions'        => self::boolOf($f['commissions'] ?? false),
            'notes'              => self::strOrNull($f['notes'] ?? null),
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
