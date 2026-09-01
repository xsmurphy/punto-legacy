<?php
declare(strict_types=1);

namespace Punto\Api\Ai;

require_once __DIR__ . '/../Auth/OperatorContext.php';
require_once __DIR__ . '/../Auth/hasPermission.php';

use Punto\Api\Auth\OperatorContext;

/**
 * AgentActor — QUIÉN le está pidiendo al agente IA que escriba, y qué puede.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * `/v1/ai/confirm` y `/v1/ai/execute` son las dos mitades de la MISMA
 * operación: una registra el lote y la otra lo ejecuta. Desde que aceptan dos
 * realms, cada una tiene que responder tres preguntas idénticas —quién es la
 * persona, si puede usar el agente, y si puede hacer cada acción— y contestarlas
 * por separado es la receta para que se desincronicen: alcanzaría con que
 * `confirm` se afloje un día para que `execute` reciba lotes que nunca debieron
 * registrarse. Las respuestas viven acá, una sola vez.
 *
 * ── El invariante: en la caja, la credencial NO es la persona ───────────────
 *
 * El Bearer del realm `pos-app` identifica una TABLET: no expira, vive en el
 * localStorage de un dispositivo compartido y su `userId` es el del contacto que
 * pareó la caja hace meses (`api/bootstrap.php`, rama `pos-app`). Autorizar una
 * ESCRITURA con eso solo sería darle permiso de escribir al mueble, no a nadie.
 *
 * Por eso, bajo `pos-app`, la persona sale de la `OperatorAssertion` que emite
 * `/v1/unlock-pin` tras validar el PIN server-side, y TODO se evalúa contra el
 * rol de esa persona (`OperatorContext`). Sin operador identificado no hay
 * escritura: 403 y se terminó. Fail-closed, sin excepciones ni modo degradado.
 *
 * Bajo `panel` la credencial SÍ es la persona, así que el mismo objeto resuelve
 * contra `hasPermission()` y el comportamiento del panel no cambia en nada.
 *
 * ── Configurar el comercio NO se puede pedir desde la caja ─────────────────
 *
 * Cuatro acciones se bloquean por realm, explícitamente, acá abajo: las que
 * fabrican accesos al comercio (`create_user`, `assign_role`) y las que definen
 * su estructura fiscal (`create_outlet`, `create_register`). Ninguna es tarea
 * de cajero: son decisiones de dueño, se toman con el equipo delante y con el
 * timbrado de la SET a mano, no de pie en el mostrador mientras espera un
 * cliente.
 *
 * El motivo de que el bloqueo sea explícito importa: se creía que `create_user`
 * quedaba fuera de alcance solo, porque exige `ai.agent.elevated` y
 * `unlock-pin.php` filtra al prefijo `pos.` los permisos que le manda al front.
 * Eso es falso. Ese filtro decide qué se CACHEA en la tablet, no qué evalúa el
 * backend: `OperatorContext::can()` consulta el rol real del operador, donde un
 * encargado o un dueño sí tiene `ai.agent.elevated` (ver el seed de
 * `RoleService`). Lo mismo vale para `settings.outlet.manage` y
 * `settings.register.manage`: el rol del encargado los tiene, así que sin este
 * bloqueo "abrí una sucursal" desde el mostrador habría funcionado.
 */
final class AgentActor
{
    /** Habilita el agente en el panel. La credencial ES la persona. */
    public const PANEL_ENTRY_PERMISSION = 'ai.agent.use';

    /**
     * Habilita el asistente en la caja. Clave propia y no `ai.agent.use` porque
     * `unlock-pin.php` solo baja al dispositivo los permisos `pos.*`, y porque
     * el comercio tiene que poder habilitar el mostrador sin abrir el panel.
     */
    public const POS_ENTRY_PERMISSION = 'pos.ai.use';

    /** Clave elevada que exigen las ELEVATED_ACTIONS ADEMÁS de su permiso propio. */
    public const ELEVATED_PERMISSION = 'ai.agent.elevated';

    /** Acciones que ninguna caja ejecuta, tenga el operador el rol que tenga. */
    private const POS_BLOCKED_ACTIONS = [
        'create_user',
        'assign_role',
        'create_outlet',
        'create_register',
    ];

    /**
     * Permiso REAL que exige cada acción — el que la persona necesitaría para
     * hacer eso mismo a mano por el panel. Es data, no lógica, y por eso vive
     * en una constante: el arnés de permisos la lee por reflexión y verifica
     * que toda acción del catálogo de `/v1/ai/confirm` tenga su gate, así una
     * acción nueva sin entrada acá sale roja en vez de quedar sin control.
     *
     * `tabular_import` no figura: su permiso depende de QUÉ se importa y se
     * resuelve del payload en `requiredPermission()`.
     *
     * No existe permiso propio para taxonomías (category/brand/tag): se gatean
     * con `inventory.item.edit` (gestión de catálogo), el más restrictivo
     * razonable.
     */
    private const ACTION_PERMISSION = [
        'create_contact'    => 'contacts.customer.create',
        'update_contact'    => 'contacts.customer.edit',
        'create_item'       => 'inventory.item.create',
        'update_item_price' => 'inventory.item.edit',
        'create_user'       => 'contacts.user.manage',
        'assign_role'       => 'contacts.user.manage',
        'create_category'   => 'inventory.item.edit',
        'create_brand'      => 'inventory.item.edit',
        'create_tag'        => 'inventory.item.edit',
        'create_outlet'     => 'settings.outlet.manage',
        'create_register'   => 'settings.register.manage',
    ];

    /**
     * Acciones que ADEMÁS exigen la clave elevada: las que tocan el equipo del
     * comercio, o sea la puerta a más accesos. Crear un usuario y cambiarle el
     * rol a uno existente son la misma superficie de riesgo — la segunda es
     * incluso más directa, porque no fabrica una persona nueva sino que le
     * cambia lo que puede hacer a una que ya entra todos los días.
     */
    private const ELEVATED_ACTIONS = ['create_user', 'assign_role'];

    private function __construct(
        private readonly string $realm,
        private readonly string $companyId,
        private readonly string $userId,
        /** @var array{userId: ?string, roleId: ?string, identified: bool}|null null = realm panel */
        private readonly ?array $operator,
    ) {
    }

    /**
     * Resuelve el actor y aplica el gate de ENTRADA. Corta la response (403) si
     * no hay nadie o si esa persona no puede usar el agente en esta superficie.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     */
    public static function authorize(array $ctx): self
    {
        $realm     = (string) ($ctx['realm'] ?? '');
        $companyId = (string) ($ctx['companyId'] ?? '');

        if ($realm !== 'pos-app') {
            if (!\hasPermission(self::PANEL_ENTRY_PERMISSION)) {
                \apiError('No tenés permiso para esta acción (requiere: ' . self::PANEL_ENTRY_PERMISSION . ')', 403);
            }
            return new self($realm, $companyId, (string) ($ctx['userId'] ?? ''), null);
        }

        // Caja: la persona se prueba, no se declara. Sin `X-Operator-Token`
        // válido no hay a quién atribuirle el cambio ni contra qué rol medirlo.
        $operator = OperatorContext::resolve($ctx);
        if (!$operator['identified']) {
            \apiError('Desbloqueá la caja con tu PIN para que el asistente pueda hacer cambios', 403);
        }
        if (!OperatorContext::can($operator, self::POS_ENTRY_PERMISSION, $companyId)) {
            \apiError('No tenés permiso para esta acción (requiere: ' . self::POS_ENTRY_PERMISSION . ')', 403);
        }

        return new self($realm, $companyId, (string) $operator['userId'], $operator);
    }

    /**
     * El usuario al que se le atribuye lo que se escriba, y con el que se ata el
     * token de confirmación. En la caja es el OPERADOR — nunca el contacto que
     * pareó el dispositivo, que es lo que trae la credencial.
     */
    public function userId(): string
    {
        return $this->userId;
    }

    public function realm(): string
    {
        return $this->realm;
    }

    /** true si el pedido viene de una caja (realm `pos-app`). */
    public function isRegister(): bool
    {
        return $this->operator !== null;
    }

    /**
     * ¿El actor tiene este permiso? UNA sola puerta para los dos realms: contra
     * el rol del operador en la caja, contra la credencial en el panel.
     */
    public function can(string $perm): bool
    {
        if ($this->operator === null) {
            return \hasPermission($perm);
        }
        return OperatorContext::can($this->operator, $perm, $this->companyId);
    }

    /**
     * ¿Esta acción se puede siquiera pedir desde donde está el actor?
     * Se chequea en `confirm` (para no registrar un lote imposible) y otra vez
     * en `execute` (porque un token viejo podría traerla).
     */
    public function allowsAction(string $action): bool
    {
        if (!$this->isRegister()) {
            return true;
        }
        return !in_array($action, self::POS_BLOCKED_ACTIONS, true);
    }

    /**
     * Permiso ESPECÍFICO por acción (defense in depth — la clave de entrada solo
     * habilita el agente; cada acción exige además el permiso real que la
     * persona necesitaría para hacerla a mano). El agente NUNCA puede ejecutar
     * algo que quien lo opera no esté autorizado a hacer por sí mismo.
     *
     * El mapa vive en `ACTION_PERMISSION`; acá quedan los dos casos que no son
     * una constante: la clave elevada y el permiso de `tabular_import`
     * (operación masiva), que exige el de CREAR de la entidad importada —
     * gate fuerte, resuelto por $payload['kind'].
     *
     * @param array<string,mixed> $payload
     * @return string|null clave requerida, o null si la acción no tiene gate propio
     */
    public function requiredPermission(string $action, array $payload): ?string
    {
        // Las acciones que tocan el equipo del comercio exigen la clave elevada
        // ADEMÁS de contacts.user.manage — sin esto `ai.agent.elevated` era una
        // clave decorativa del catálogo, mostrada en el panel y chequeada en
        // ningún lado. Seed: manager y owner la tienen; cashier no. (Desde la
        // caja estas acciones no llegan hasta acá: las corta `allowsAction()`.)
        if (in_array($action, self::ELEVATED_ACTIONS, true) && !$this->can(self::ELEVATED_PERMISSION)) {
            return self::ELEVATED_PERMISSION;
        }

        if ($action === 'tabular_import') {
            $importKind = (string) ($payload['kind'] ?? '');
            return $importKind === 'contacts'
                ? 'contacts.customer.create'
                : 'inventory.item.create';
        }

        return self::ACTION_PERMISSION[$action] ?? null;
    }
}
