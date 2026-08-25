<?php
namespace Punto\Api\Services;

use Punto\Api\Auth\DeviceAuth;

class DeviceInvitationService
{
    // 'print' = Estación de Impresión (P1, context/26-print-station-plan.md):
    // device pareado que corre en la PC con las impresoras físicas.
    private const VALID_MODULES = ['pos', 'screen', 'kds', 'display', 'print'];
    // Alfabeto sin I ni O para evitar confusión visual
    private const ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ';         // 24 chars
    private const ALNUM = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars
    // Bytes del secreto de sesión de pairing (mig 171). 32 bytes = 64 hex, el
    // mismo orden de magnitud que un token opaco de `auth_session`.
    private const PAIRING_SECRET_BYTES = 32;

    public function create(
        string $companyId,
        string $createdByUserId,
        string $module,
        ?string $outletId,
        ?string $registerId,
        ?string $deviceName,
        int $ttlHours = 24
    ): array {
        if (!in_array($module, self::VALID_MODULES, true)) {
            throw new \RuntimeException('module inválido', 422);
        }
        // companyId/outletId/registerId son obligatorios para un device module=pos
        // (context/29 — nunca se infieren con "¿qué está activo ahora?"). El panel
        // ya lo exige client-side (device-invite-create-dialog.tsx), pero sin este
        // guard server-side una invitación creada por API directa (o un bug futuro
        // en el diálogo) podía parear un device pos-app sin caja/sucursal — el
        // mismo agujero que `DeviceAuth::requireCompleteContext()` cierra en el
        // OTRO extremo (resolución del token), acá se cierra en el de origen
        // (creación del pareo) para que el dispositivo nunca llegue a existir a
        // medias. screen/kds/display/print quedan afuera: son legítimamente
        // outlet/register-less (ver doc de requireCompleteContext() en
        // DeviceAuth.php).
        if ($module === 'pos' && (($outletId === null || $outletId === '') || ($registerId === null || $registerId === ''))) {
            throw new \RuntimeException('Un dispositivo POS necesita sucursal y caja asignadas', 422);
        }
        if ($outletId !== null && $outletId !== '') {
            $exists = ncmExecute(
                'SELECT 1 FROM outlet WHERE outletId = ?::uuid AND companyId = ?::uuid',
                [$outletId, $companyId]
            );
            if (!$exists) {
                throw new \RuntimeException('outletId no pertenece al tenant', 422);
            }
        }
        if ($registerId !== null && $registerId !== '') {
            $exists = ncmExecute(
                'SELECT 1 FROM register WHERE registerId = ?::uuid AND companyId = ?::uuid',
                [$registerId, $companyId]
            );
            if (!$exists) {
                throw new \RuntimeException('registerId no pertenece al tenant', 422);
            }
        }
        $row = ncmExecute(
            'INSERT INTO device_invitation (company_id, created_by, module, outlet_id, register_id, device_name, expires_at)
             VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?, now() + ?::interval)
             RETURNING id, expires_at',
            [
                $companyId,
                $createdByUserId,
                $module,
                ($outletId !== null && $outletId !== '') ? $outletId : null,
                ($registerId !== null && $registerId !== '') ? $registerId : null,
                ($deviceName !== null && $deviceName !== '') ? $deviceName : null,
                $ttlHours . ' hours',
            ]
        );
        if (!$row) {
            throw new \RuntimeException('No se pudo crear la invitación', 500);
        }
        $id     = (string) ($row['id'] ?? '');
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'https://app.punto.la', '/');
        return [
            'id'        => $id,
            'url'       => $appUrl . '/connect/' . $id,
            'expiresAt' => (string) ($row['expires_at'] ?? ''),
        ];
    }

    /**
     * Primera mitad del canje: el dispositivo abre la invitación y recibe el
     * `userCode` que le va a mostrar al admin, más —sólo la primera vez— el
     * `pairingSecret` que lo identifica como EL lector de esta invitación.
     *
     * ── Por qué existe el pairingSecret ─────────────────────────────────────
     *
     * Hasta el 2026-08-25 esta función era idempotente por id a secas: si la
     * invitación ya estaba `opened`, CUALQUIER segundo navegador que abriera
     * el link recibía el MISMO userCode y quedaba adherido a la misma
     * invitación. Sumado a que `status()` re-emitía token en cada consulta,
     * el link (que viaja por WhatsApp) habilitaba N dispositivos sobre la
     * misma caja. Reproducido por el owner con dos navegadores; en prod había
     * un device con 3 sesiones activas creadas en 6 segundos desde 3
     * user-agents distintos.
     *
     * El comentario viejo tenía razón en una cosa: el `user_code` es PÚBLICO
     * (la pantalla se lo muestra a quien la mire) y regenerarlo no suma
     * seguridad. Pero eso justificaba no regenerarlo, no admitir un segundo
     * lector. Ahora el code se conserva —el admin sigue viendo el mismo en su
     * listado, sin el desfasaje del incidente 2026-06-28— y lo que se exige
     * para volver a leerlo es el secreto.
     *
     * El secreto se genera server-side, se devuelve UNA vez en claro y en BD
     * queda sólo su sha256 (igual que `auth_session.tokenHash`). El navegador
     * legítimo lo guarda y lo presenta en cada reload y en cada poll: por eso
     * recargar la página funciona y abrir el link en otro navegador no.
     *
     * Se evaluó y descartó usar `device_ua`/`device_ip` como identidad del
     * lector: en la red del comercio dos tablets salen por la misma IP y con
     * el mismo user-agent, o sea que darían falso positivo justo entre los dos
     * dispositivos que más importa distinguir; y un cambio de NAT o de
     * forwarding daría falso negativo contra el dispositivo legítimo. Siguen
     * persistiéndose, pero como telemetría, no como control de acceso.
     *
     * @param string|null $pairingSecret secreto en claro que el navegador
     *        recibió en su primera apertura. `null` sólo es válido cuando la
     *        invitación todavía no fue abierta por nadie.
     */
    public function open(string $id, string $userAgent, string $ip, ?string $pairingSecret = null): array
    {
        $row = ncmExecute(
            'SELECT id, company_id, module, status, opened_at, device_ua, device_ip, expires_at, user_code, auto_approve, device_id, pairing_secret
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        $status    = (string) ($row['status'] ?? '');
        $expiresAt = (string) ($row['expires_at'] ?? '');
        $expired   = $expiresAt !== '' && strtotime($expiresAt) < time();

        // Expiración PRIMERO y para todos los caminos. Antes vivía después del
        // early-return de "ya opened", así que una invitación vencida pero ya
        // abierta seguía devolviendo su userCode indefinidamente.
        if ($expired && in_array($status, ['pending', 'opened'], true)) {
            try {
                ncmExecute(
                    "UPDATE device_invitation SET status='expired' WHERE id=?::uuid AND status IN ('pending','opened')",
                    [$id]
                );
            } catch (\Throwable) {
                // best-effort — el throw de abajo es la respuesta, no el UPDATE
            }
            throw new \RuntimeException('Invitación expirada', 410);
        }

        if ($status === 'consumed') {
            throw new \RuntimeException(
                'Este link ya fue usado por otro dispositivo. Pedí uno nuevo desde Configuración › Dispositivos.',
                410
            );
        }
        if (!in_array($status, ['pending', 'opened'], true)) {
            throw new \RuntimeException('Invitación en estado inválido: ' . $status, 410);
        }

        $persistedHash = trim((string) ($row['pairing_secret'] ?? ''));
        $hasUserCode   = !empty($row['user_code']);

        // Ya abierta y con dueño: sólo ese navegador la vuelve a leer.
        if ($status === 'opened' && $persistedHash !== '' && $hasUserCode) {
            if (!self::pairingSecretMatches($pairingSecret, $persistedHash)) {
                throw new \RuntimeException(
                    'Esta invitación ya está en uso en otro dispositivo. Pedí un link nuevo desde Configuración › Dispositivos.',
                    409
                );
            }
            try {
                ncmExecute(
                    'UPDATE device_invitation SET device_ua=?, device_ip=?::inet WHERE id=?::uuid',
                    [$userAgent, ($ip !== '') ? $ip : null, $id]
                );
            } catch (\Throwable) {
                // best-effort — telemetría, no control de acceso
            }
            return [
                'userCode'      => (string) $row['user_code'],
                'module'        => (string) ($row['module'] ?? ''),
                'status'        => 'opened',
                'expiresAt'     => $expiresAt,
                'pairingSecret' => null, // el navegador legítimo ya lo tiene
            ];
        }

        // Primera apertura. El claim del secreto ES el lock de concurrencia:
        // el UPDATE condiciona sobre `pairing_secret IS NULL`, así que de dos
        // navegadores simultáneos exactamente uno afecta una fila (Read
        // Committed re-evalúa el WHERE después de tomar el lock de fila) y el
        // otro se lleva el 409. Mismo patrón CAS que `markPaid` y
        // `SpaceSettlementService`.
        $secret     = bin2hex(random_bytes(self::PAIRING_SECRET_BYTES));
        $secretHash = self::hashPairingSecret($secret);

        // Fila abierta ANTES de la mig 171 (sin secreto): la adopta el primer
        // lector post-deploy conservando su userCode. Regenerarlo desincroniza
        // la pantalla del admin en plena ventana de deploy por nada.
        if ($status === 'opened' && $hasUserCode) {
            $claim = ncmExecute(
                "UPDATE device_invitation
                    SET pairing_secret=?, device_ua=?, device_ip=?::inet
                  WHERE id=?::uuid AND status='opened' AND pairing_secret IS NULL
                RETURNING user_code",
                [$secretHash, $userAgent, ($ip !== '') ? $ip : null, $id]
            );
            if (!$claim) {
                throw new \RuntimeException(
                    'Esta invitación ya está en uso en otro dispositivo. Pedí un link nuevo desde Configuración › Dispositivos.',
                    409
                );
            }
            $userCode = (string) ($claim['user_code'] ?? '');
        } else {
            $userCode   = null;
            $maxRetries = 3;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                $candidate = $this->generateUserCode();
                try {
                    $claim = ncmExecute(
                        "UPDATE device_invitation
                            SET status='opened', opened_at=now(), user_code=?, pairing_secret=?,
                                device_ua=?, device_ip=?::inet
                          WHERE id=?::uuid AND status IN ('pending','opened') AND pairing_secret IS NULL
                        RETURNING user_code",
                        [$candidate, $secretHash, $userAgent, ($ip !== '') ? $ip : null, $id]
                    );
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'uq_di_user_code_active')) {
                        continue; // colisión de code: la fila no se tocó, reintentar
                    }
                    throw $e;
                }
                if (!$claim) {
                    // Cero filas = otro navegador ganó el claim entre el SELECT
                    // y este UPDATE. Fail-closed: no le damos el code.
                    throw new \RuntimeException(
                        'Esta invitación ya está en uso en otro dispositivo. Pedí un link nuevo desde Configuración › Dispositivos.',
                        409
                    );
                }
                $userCode = $candidate;
                break;
            }
            if ($userCode === null) {
                throw new \RuntimeException('No se pudo generar un código único', 500);
            }
        }

        // Auto-approve: las invitaciones de reconexión (createReconnect) se
        // resuelven acá mismo, sin userCode ni aprobación del admin. También
        // son de un solo uso: el CAS consume ANTES de emitir, así que dos
        // aperturas simultáneas producen un token y un 410, nunca dos tokens.
        $autoApprove = ($row['auto_approve'] ?? false) === true || ($row['auto_approve'] ?? '') === 't';
        if ($autoApprove) {
            $targetDeviceId = (string) ($row['device_id'] ?? '');
            if ($targetDeviceId === '') {
                throw new \RuntimeException('Invitación auto-aprobada sin device target', 500);
            }
            $consumed = ncmExecute(
                "UPDATE device_invitation
                    SET status='consumed', approved_at=now(), approved_by=created_by, consumed_at=now()
                  WHERE id=?::uuid AND status='opened' AND expires_at > now()
                RETURNING device_id",
                [$id]
            );
            if (!$consumed) {
                throw new \RuntimeException(
                    'Este link de reconexión ya fue usado o venció. Pedí uno nuevo desde Configuración › Dispositivos.',
                    410
                );
            }
            // Si la emisión falla acá (device revocado entre medio), la
            // invitación queda quemada. Es lo correcto: preferimos pedir un
            // link nuevo antes que dejar una invitación reintentable.
            //
            // El companyId va explícito aunque `createReconnect()` ya haya
            // validado que el device pertenece al tenant: ésta es una emisión
            // de credencial y no debe depender de una validación hecha en otro
            // método, en otro request y horas antes. `issueTokenForExistingDevice`
            // con companyId filtra por tenant en el mismo SELECT que resuelve el
            // device — mismo criterio que el canje de `status()`.
            $issued = \Punto\Api\Auth\DeviceAuth::issueTokenForExistingDevice(
                $targetDeviceId,
                (string) ($row['company_id'] ?? '')
            );
            return [
                'id'            => $id,
                'status'        => 'approved',
                'userCode'      => null,
                'autoApprove'   => true,
                'token'         => $issued['token'],
                'deviceId'      => $issued['deviceId'],
                'module'        => (string) ($row['module'] ?? ''),
                'companyId'     => (string) ($issued['companyId']  ?? ''),
                'registerId'    => (string) ($issued['registerId'] ?? ''),
                'pairingSecret' => null, // ya entregó el token: no hay polling
            ];
        }

        return [
            'userCode'      => $userCode,
            'module'        => (string) ($row['module'] ?? ''),
            'status'        => 'opened',
            'expiresAt'     => $expiresAt,
            'pairingSecret' => $secret, // ÚNICA vez que sale en claro
        ];
    }

    /**
     * Segunda mitad del canje: el dispositivo pollea hasta que el admin
     * aprueba, y en la consulta que encuentra `approved` recibe su token.
     *
     * ── El canje es de UN SOLO USO ──────────────────────────────────────────
     *
     * Antes esta función emitía un token NUEVO en cada consulta mientras la
     * invitación siguiera en `approved`, sin marca de consumida, sin límite de
     * veces y sin chequear quién preguntaba. Como `expires_at` sólo se
     * evaluaba en `pending`/`opened`, tampoco caducaba una vez aprobada: el
     * link era un emisor de credenciales permanente para esa caja, y el link
     * queda en un chat de WhatsApp. Además de sesiones de más, eso rompe la
     * exclusividad del punto de expedición
     * (context/29-numeracion-y-exclusividad-de-caja.md): dos dispositivos en
     * la misma caja pueden emitir facturas duplicadas con el mismo timbrado.
     *
     * Ahora hay dos cerrojos, y los dos tienen que abrirse:
     *
     *   1. **Identidad**: hay que presentar el `pairingSecret` que `open()`
     *      entregó al primer navegador. Quien tenga el link pero no el secreto
     *      no canjea.
     *   2. **Un solo uso**: el token se emite dentro de un CAS
     *      (`UPDATE ... WHERE status='approved' AND expires_at > now()
     *      RETURNING`) que mueve la invitación al estado terminal `consumed`.
     *      De N requests concurrentes exactamente una afecta una fila; las
     *      demás no reciben token. Mismo patrón que `markPaid` y
     *      `SpaceSettlementService`.
     *
     * El CAS lleva `expires_at > now()` adentro a propósito: así la expiración
     * post-aprobación se evalúa de forma atómica con el canje y no queda una
     * ventana entre el chequeo y el UPDATE.
     *
     * Fail-closed asumido: si la respuesta con el token se pierde en la red
     * después de que el UPDATE commiteó, la invitación queda quemada y hay que
     * pedir un link nuevo. Es el intercambio correcto — la alternativa
     * (reintentos) es exactamente el agujero que esto cierra.
     */
    public function status(string $id, ?string $pairingSecret = null): array
    {
        $row = ncmExecute(
            'SELECT id, company_id, module, status, expires_at, device_id, user_code, pairing_secret
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        $status    = (string) ($row['status'] ?? '');
        $expiresAt = (string) ($row['expires_at'] ?? '');

        // Expiración silenciosa best-effort. `approved` entra en la lista: una
        // invitación aprobada y vencida ya no puede entregar credenciales.
        if (in_array($status, ['pending', 'opened', 'approved'], true)
            && $expiresAt !== ''
            && strtotime($expiresAt) < time()
        ) {
            try {
                ncmExecute(
                    "UPDATE device_invitation SET status='expired'
                      WHERE id=?::uuid AND status IN ('pending','opened','approved')",
                    [$id]
                );
            } catch (\Throwable) {
                // best-effort — el CAS de abajo igual exige expires_at > now()
            }
            $status = 'expired';
        }

        if ($status !== 'approved') {
            return ['status' => $status];
        }

        // Cerrojo 1: identidad del que pregunta.
        $persistedHash = trim((string) ($row['pairing_secret'] ?? ''));
        if ($persistedHash === '') {
            // Fila anterior a la mig 171: no hay forma de saber si quien
            // pregunta es el dispositivo que abrió el link o cualquiera que lo
            // haya recibido reenviado. Fail-closed.
            throw new \RuntimeException(
                'Este link fue generado con una versión anterior y ya no puede entregar credenciales. '
                . 'Pedí uno nuevo desde Configuración › Dispositivos.',
                410
            );
        }
        if (!self::pairingSecretMatches($pairingSecret, $persistedHash)) {
            throw new \RuntimeException(
                'Esta invitación pertenece a otro dispositivo. Pedí un link nuevo desde Configuración › Dispositivos.',
                409
            );
        }

        // Cerrojo 2: canje único + expiración, atómicos.
        $claim = ncmExecute(
            "UPDATE device_invitation
                SET status='consumed', consumed_at=now()
              WHERE id=?::uuid AND status='approved' AND expires_at > now()
            RETURNING device_id, company_id, module",
            [$id]
        );
        if (!$claim) {
            // Perdió la carrera contra otro request, o venció entre el SELECT
            // y el UPDATE. En ninguno de los dos casos se emite token.
            $fresh = ncmExecute('SELECT status FROM device_invitation WHERE id = ?::uuid', [$id]);
            return ['status' => (string) ($fresh['status'] ?? 'consumed')];
        }

        $deviceId  = (string) ($claim['device_id'] ?? '');
        $companyId = (string) ($claim['company_id'] ?? '');
        if ($deviceId === '' || $companyId === '') {
            throw new \RuntimeException('La invitación fue aprobada sin dispositivo asociado', 500);
        }

        $jwt = DeviceAuth::issueTokenForExistingDevice($deviceId, $companyId);

        // Se responde `approved` (no `consumed`): para el dispositivo que sí
        // canjeó, el resultado ES la aprobación. `consumed` sólo lo ve quien
        // llega tarde.
        return [
            'status'        => 'approved',
            'token'         => $jwt['token'],
            'deviceId'      => $deviceId,
            'cookieExpires' => time() + (int) ($jwt['expiresIn'] ?? 0),
            'module'        => (string) ($claim['module'] ?? 'pos'),
            'companyId'     => (string) ($jwt['companyId']  ?? ''),
            'registerId'    => (string) ($jwt['registerId'] ?? ''),
        ];
    }

    public function approve(
        string $id,
        string $companyIdOfAdmin,
        string $approvedByUserId,
        string $userCodeConfirm
    ): array {
        $row = ncmExecute(
            'SELECT id, company_id, module, outlet_id, register_id, device_name, status, user_code, expires_at, pairing_secret
             FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }

        if ((string) ($row['company_id'] ?? '') !== $companyIdOfAdmin) {
            throw new \RuntimeException('No autorizado', 403);
        }
        if ((string) ($row['status'] ?? '') !== 'opened') {
            throw new \RuntimeException('La invitación no está en estado abierto', 409);
        }
        if ((string) ($row['user_code'] ?? '') !== $userCodeConfirm) {
            throw new \RuntimeException('Código incorrecto', 422);
        }
        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            throw new \RuntimeException('Invitación expirada', 410);
        }
        // Sin secreto de pairing no hay a quién entregarle el token después:
        // `status()` se negaría a canjear y el device recién creado quedaría
        // huérfano. Sólo puede pasar con filas abiertas antes de la mig 171.
        if (trim((string) ($row['pairing_secret'] ?? '')) === '') {
            throw new \RuntimeException(
                'El dispositivo tiene que volver a abrir el link antes de aprobar la conexión.',
                409
            );
        }

        $outletId   = (string) ($row['outlet_id'] ?? '');
        $registerId = (string) ($row['register_id'] ?? '');
        $deviceName = (string) ($row['device_name'] ?? '');
        $module     = (string) ($row['module'] ?? '');

        // Se registra el device SIN emitir sesión: el único token de este
        // dispositivo lo emite el canje en `status()`, y una sola vez. Antes
        // acá se llamaba a `createDeviceAndIssueToken()` y el Bearer resultante
        // volvía en la respuesta del ADMIN, que no lo usa para nada — quedaba
        // una sesión activa de más en `auth_session` por cada pareo.
        // El module se persiste en la fila device y llega al claim 'mdl' del
        // token cuando el canje lo emite.
        $created = DeviceAuth::createDevice(
            $companyIdOfAdmin,
            $outletId,
            $registerId,
            $approvedByUserId,
            $deviceName !== '' ? $deviceName : null,
            null,    // userAgent no disponible aquí
            null,    // browserLocalId no aplica en device flow
            $module, // persiste en device.module
        );

        $deviceId = $created['deviceId'];

        // CAS: sólo se aprueba lo que sigue abierto y vigente. Dos admins
        // aprobando la misma invitación no generan dos devices con token.
        $approved = ncmExecute(
            "UPDATE device_invitation
                SET status='approved', approved_at=now(), approved_by=?::uuid, device_id=?::uuid
              WHERE id=?::uuid AND status='opened' AND expires_at > now()
            RETURNING id",
            [$approvedByUserId, $deviceId, $id]
        );
        if (!$approved) {
            // El device recién creado queda huérfano, pero inerte: sin
            // invitación aprobada que lo apunte nadie puede canjear un token
            // para él. Preferible a dejar la aprobación a medias.
            throw new \RuntimeException('La invitación cambió de estado; volvé a pedirle el código al dispositivo', 409);
        }

        return ['deviceId' => $deviceId];
    }

    public function createReconnect(string $deviceId, string $companyId, string $userId): array
    {
        $device = ncmExecute(
            'SELECT deviceid, companyid, outletid, registerid, module, devicename
             FROM device
             WHERE deviceid = ?::uuid AND status = 1',
            [$deviceId]
        );
        if (!$device) {
            throw new \RuntimeException('Device no encontrado o revocado', 404);
        }
        if ((string)($device['companyid'] ?? '') !== $companyId) {
            throw new \RuntimeException('No autorizado', 403);
        }
        $row = ncmExecute(
            "INSERT INTO device_invitation
               (company_id, created_by, module, outlet_id, register_id, device_name, device_id, auto_approve, expires_at)
             VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?, ?::uuid, true, now() + interval '10 minutes')
             RETURNING id, expires_at",
            [
                $companyId, $userId,
                (string)($device['module'] ?? 'pos'),
                $device['outletid'] !== null ? (string)$device['outletid'] : null,
                $device['registerid'] !== null ? (string)$device['registerid'] : null,
                (string)($device['devicename'] ?? ''),
                $deviceId,
            ]
        );
        if (!$row) {
            throw new \RuntimeException('No se pudo crear la invitación de reconexión', 500);
        }
        $id     = (string)($row['id'] ?? '');
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'https://app.punto.la', '/');
        return [
            'id'          => $id,
            'url'         => $appUrl . '/connect/' . $id,
            'expiresAt'   => (string)($row['expires_at'] ?? ''),
            'autoApprove' => true,
        ];
    }

    public function deny(string $id, string $companyIdOfAdmin): void
    {
        $row = ncmExecute(
            'SELECT company_id, status FROM device_invitation WHERE id = ?::uuid',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('Invitación no encontrada', 404);
        }
        if ((string) ($row['company_id'] ?? '') !== $companyIdOfAdmin) {
            throw new \RuntimeException('No autorizado', 403);
        }
        if (!in_array((string) ($row['status'] ?? ''), ['pending', 'opened'], true)) {
            throw new \RuntimeException('No se puede denegar en este estado', 409);
        }
        ncmExecute(
            "UPDATE device_invitation SET status='denied' WHERE id=?::uuid",
            [$id]
        );
    }

    public function list(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT id, user_code, module, outlet_id, register_id, device_name, device_ua, device_ip,
                    status, opened_at, created_at, expires_at
             FROM device_invitation
             WHERE company_id = ?::uuid
               AND status IN ('pending','opened')
               AND expires_at > now()
             ORDER BY created_at DESC",
            [$companyId],
            false,
            true  // forceObj=true -> recordset, iterar con while(!$rs->EOF)
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $rows[] = [
                    'id'         => (string) ($f['id'] ?? ''),
                    'userCode'   => $f['user_code'] !== null ? (string) $f['user_code'] : null,
                    'module'     => (string) ($f['module'] ?? ''),
                    'outletId'   => $f['outlet_id'] !== null ? (string) $f['outlet_id'] : null,
                    'registerId' => $f['register_id'] !== null ? (string) $f['register_id'] : null,
                    'deviceName' => $f['device_name'] !== null ? (string) $f['device_name'] : null,
                    'deviceUa'   => $f['device_ua'] !== null ? (string) $f['device_ua'] : null,
                    'deviceIp'   => $f['device_ip'] !== null ? (string) $f['device_ip'] : null,
                    'status'     => (string) ($f['status'] ?? ''),
                    'openedAt'   => $f['opened_at'] !== null ? (string) $f['opened_at'] : null,
                    'createdAt'  => (string) ($f['created_at'] ?? ''),
                    'expiresAt'  => (string) ($f['expires_at'] ?? ''),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    /**
     * sha256 hex del secreto de pairing. En BD NUNCA se guarda el crudo —
     * misma política que `auth_session.tokenHash`: si alguien lee la tabla, no
     * se lleva credenciales utilizables.
     */
    private static function hashPairingSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Compara el secreto presentado contra el hash persistido, en tiempo
     * constante (`hash_equals`) y fail-closed: sin secreto, o con un hash
     * persistido vacío, la respuesta es SIEMPRE false.
     *
     * `trim()` sobre el persistido porque la columna es `char(64)` y Postgres
     * devuelve blank-padded si algún día cambia el largo del hash.
     */
    private static function pairingSecretMatches(?string $presented, string $persistedHash): bool
    {
        $persistedHash = trim($persistedHash);
        if ($persistedHash === '' || $presented === null || $presented === '') {
            return false;
        }
        return hash_equals($persistedHash, self::hashPairingSecret($presented));
    }

    private function generateUserCode(): string
    {
        $alpha = self::ALPHA;
        $alnum = self::ALNUM;
        $code  = '';
        for ($i = 0; $i < 3; $i++) {
            $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $code .= '-';
        for ($i = 0; $i < 4; $i++) {
            $code .= $alnum[random_int(0, strlen($alnum) - 1)];
        }
        return $code;
    }
}
