<?php
declare(strict_types=1);

namespace Punto\Api\Auth;

// `auth_session.php` solo se carga dentro de `jwtAuthenticate()`
// (jwt_middleware.php), así que un caller que no pase por el gate de auth —un
// arnés, un job de mantenimiento— no lo tiene. El service no puede depender de
// que alguien más lo haya cargado antes.
require_once __DIR__ . '/../../includes/auth_session.php';

/**
 * API keys del tenant — emisión, listado y revocación (M0 de `context/58`).
 *
 * ── Por qué NO hay tabla nueva ──────────────────────────────────────────────
 * Una key es una sesión más: `auth_session` (mig 69) ya guarda el hash
 * del token (nunca el crudo), su realm, a qué empresa y usuario pertenece, si
 * está revocada y cuándo se la vio por última vez. La columna `realm` es
 * `varchar(16)` SIN CHECK, así que `'api'` es un valor más — no hay migración.
 *
 * ── Por qué el realm es PROPIO y no se reusa `panel` ────────────────────────
 * `apiAuthTenant(['panel'])` es el allowlist endpoint por endpoint. Si una key
 * se emitiera con realm `panel`, entraría a TODO lo que el panel puede hacer
 * —incluidas las escrituras— sin que nadie lo hubiera decidido. Con `'api'`
 * cada endpoint tiene que optar explícitamente. Es la misma disciplina que
 * evitó tres incidentes en el POS (`context/08` §60).
 *
 * ── Por qué `api` y no `mcp` (rename, mig 182) ──────────────────────────────
 * El realm describe "acceso programático de solo lectura en nombre de un
 * usuario". MCP resultó ser su primer consumidor, no su definición: la MISMA
 * key sirve como API key común contra cualquier endpoint que optó por el realm.
 * Nombrarlo por el primer caso de uso habría dejado a un comercio integrando su
 * dashboard autenticando contra un realm llamado "mcp".
 *
 * ── Los permisos de la key ⊆ los del usuario, por construcción ──────────────
 * La sesión se emite con el MISMO `userId`/`roleId`/`outletId` del operador que
 * la creó, así que `apiAuthTenant()` deriva el contexto igual que para su
 * sesión de panel y `hasPermission()` resuelve exactamente lo mismo. No hay una
 * segunda tabla de permisos que pueda divergir de la primera: una key nunca
 * puede más que quien la emitió, y si al usuario le bajan el rol, la key lo
 * hereda en su próximo request (D6 de `context/58`).
 *
 * ── Expiración: acá SÍ, a diferencia del POS ────────────────────────────────
 * Un device del POS se emite con `expiresAt = null` ("device eterno") y eso está
 * bien: es un aparato pareado que el comercio ve y despareja desde Ajustes. Una
 * API key vive en un archivo de configuración que se sincroniza a la nube y a
 * veces termina en un repo. Por eso `issue()` exige una vigencia y `DEFAULT_TTL_DAYS`
 * es el default cuando el caller no opina (D7).
 */
final class ApiKeyService
{
    /** Vigencia por defecto de una key nueva. Larga, pero no infinita. */
    public const DEFAULT_TTL_DAYS = 365;

    /** Tope duro: nadie emite una key eterna "por comodidad". */
    public const MAX_TTL_DAYS = 730;

    /**
     * Emite una key y devuelve el token CRUDO — la ÚNICA vez que existe en
     * texto plano. El caller se lo muestra al usuario y lo descarta; en la BD
     * solo queda el sha256 (`authSessionCreate`).
     *
     * @param array{companyId:string,userId:string,outletId:string,roleId:string} $ctx
     *        Contexto del operador que la emite — lo que la key va a heredar.
     * @return array{token:string,name:string,expiresAt:string}
     */
    public function issue(array $ctx, string $name, ?int $ttlDays = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('La key necesita un nombre — es lo único que la distingue en la lista al momento de revocarla.');
        }
        if (mb_strlen($name) > 60) {
            throw new \InvalidArgumentException('El nombre no puede pasar de 60 caracteres.');
        }

        $ttl = $ttlDays ?? self::DEFAULT_TTL_DAYS;
        if ($ttl < 1 || $ttl > self::MAX_TTL_DAYS) {
            throw new \InvalidArgumentException('La vigencia debe estar entre 1 y ' . self::MAX_TTL_DAYS . ' días.');
        }
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttl} days"));

        $token = \authSessionCreate('api', [
            'companyId' => $ctx['companyId'],
            // Identidad heredada: es lo que hace que los permisos de la key sean
            // los del usuario sin mantener una segunda lista. Ver docblock.
            'userId'    => $ctx['userId'],
            'outletId'  => $ctx['outletId'],
            'roleId'    => $ctx['roleId'],
            'module'    => 'api',
            'expiresAt' => $expiresAt,
            'meta'      => ['name' => $name],
        ]);

        return ['token' => $token, 'name' => $name, 'expiresAt' => $expiresAt];
    }

    /**
     * Keys del tenant. NUNCA devuelve el token ni su hash: una vez emitida, la
     * key solo se identifica por nombre y fecha — si el usuario la perdió, la
     * revoca y emite otra, que es justamente el comportamiento deseado.
     *
     * @return list<array<string,mixed>>
     */
    public function listForCompany(string $companyId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT sessionid, meta, createdat, lastseenat, expiresat, status, revokedat, userid
                  FROM auth_session
                 WHERE companyid = ?::uuid AND realm = \'api\'';
        if (!$includeRevoked) {
            $sql .= ' AND status = 1';
        }
        $sql .= ' ORDER BY createdat DESC LIMIT 200';

        // `ncmRows()` y NO `ncmExecute(..., getAssoc: true)`: getAssoc indexa por
        // la PRIMERA columna proyectada y descarta en silencio las filas que la
        // repiten (footgun documentado en su propio docblock). Acá la primera es
        // `sessionid`, que es PK y no colisiona — pero el caller quiere una
        // LISTA, no un mapa, y pedir el modo que casualmente no rompe es cómo se
        // hereda el bug cuando alguien cambia el SELECT.
        $rows = \ncmRows($sql, [$companyId]);
        $out  = [];
        foreach ($rows as $r) {
            // `meta` es JSONB y el wrapper lo APLANA al nivel de la fila
            // (`Query::flattenJsonb` cubre data/meta/config), así que `meta.name`
            // llega como `$r['name']` y la clave `meta` ya no existe. El fallback
            // decodifica por si alguna ruta devuelve la columna cruda.
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                $meta = $r['meta'] ?? null;
                if (is_string($meta)) {
                    $meta = json_decode($meta, true);
                }
                $name = is_array($meta) ? (string) ($meta['name'] ?? '') : '';
            }
            $expiresAt = (string) ($r['expiresat'] ?? '');
            $out[] = [
                'id'        => (string) $r['sessionid'],
                'name'      => $name,
                'createdAt' => (string) ($r['createdat'] ?? ''),
                'lastSeenAt'=> (string) ($r['lastseenat'] ?? ''),
                'expiresAt' => $expiresAt,
                'userId'    => (string) ($r['userid'] ?? ''),
                'revoked'   => ((int) ($r['status'] ?? 1)) !== 1,
                // Derivado y no columna: una key vencida sigue con status=1 en la
                // tabla (nada la revoca al expirar — `authResolve` simplemente la
                // rechaza). Sin esta bandera la lista mostraría como activa una
                // key que ya no sirve.
                'expired'   => $expiresAt !== '' && strtotime($expiresAt) < time(),
            ];
        }
        return $out;
    }

    /**
     * Revoca una key del tenant. Devuelve false si no existe, si es de otra
     * empresa o si no es del realm `mcp`.
     *
     * El `companyId` va DENTRO del UPDATE, no en un chequeo previo: una key
     * ajena tiene que ser indistinguible de una inexistente, o el endpoint se
     * vuelve un oráculo de existencia — el mismo P2 que se cerró en
     * `api/v1/devices.php` el 2026-08-30.
     */
    public function revoke(string $sessionId, string $companyId, string $revokedBy): bool
    {
        global $db;
        $r = $db->Execute(
            'UPDATE auth_session
                SET status = 0, revokedat = now(), revokedby = ?::uuid
              WHERE sessionid = ?::uuid AND companyid = ?::uuid AND realm = \'api\' AND status = 1',
            [$revokedBy, $sessionId, $companyId]
        );
        if ($r === false) {
            return false;
        }
        return $db->Affected_Rows() > 0;
    }
}
