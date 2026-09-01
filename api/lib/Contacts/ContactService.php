<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

use CaseInsensitiveArray;
use InvalidArgumentException;
use RuntimeException;

/**
 * ContactService — orquesta CRUD de contactos (clientes/proveedores) + reglas de negocio.
 *
 * Punto de entrada para api/v1/contacts.php y el legacy panel/a_contacts.php (in-process,
 * hasta que F3 migre el front estático y los handlers se eliminen).
 * Delega persistencia en ContactRepository. NO genera output HTTP — solo retorna data o tira.
 *
 * Convención del "shape público" (lo que recibe/devuelve la API), heredado de los
 * endpoints legacy (add_customer / edit_customer / get_customer):
 *   fiscalName → contactName (razón social)
 *   name       → contactSecondName (nombre de la persona)
 *   tin        → contactTIN (RUC)
 *   ci         → contactCI (cédula)
 *   bday       → contactBirthDay
 *   + note, city, location, country, address, phone, phone2, email,
 *     status, storeCredit, loyalty, loyaltyAmount, lat/lng
 *
 * Port FIEL de panel/lib/contacts/ContactService.php (Fase 2 del desacople de /panel).
 * Cambios respecto al original: namespace, `final`, `declare(strict_types=1)`, `use` de
 * `CaseInsensitiveArray`/`InvalidArgumentException`/`RuntimeException` (globales). Lógica
 * y shape de respuesta idénticos.
 *
 * Nota namespace: ContactRepository vive en el mismo namespace — sin `use` necesario.
 * Funciones globales (toUTF8, TODAY) resuelven por fallback de PHP.
 */
final class ContactService
{
    /** Discriminador `type` de la tabla `contact`. Solo cliente y proveedor;
        el resto de tipos (3+) son legacy y no entran al frontend por ahora. */
    const TYPE_CUSTOMER = 1;
    const TYPE_SUPPLIER = 2;

    /**
     * Tabla 3 de la SET ("Especificación Técnica para Importación", SET,
     * junio 2021) — tipo de documento de identidad del receptor.
     *
     * Por qué esta columna es de Paraguay y no de "el país del tenant": lo que
     * se guarda son CÓDIGOS DE UN FISCO CONCRETO, y los dos consumidores que
     * los leen (SaleToInvoiceMapper::mapIdType y FiscalService) los
     * interpretan como Tabla 3 sin preguntar de qué país es el comercio.
     * Meterle una segunda codificación por país la volvería ambigua para
     * ambos. Cómo se LLAMAN los documentos en cada país es otra dimensión, de
     * presentación, y vive en CountryDefaults::taxIdLabel()/personalIdLabel()
     * (espejo del front: frontend/lib/contact-id-types.ts). Un tenant no-PY no
     * pierde nada: ve sus dos campos con el nombre correcto de su país, sin
     * selector, porque no hay taxonomía que guardar.
     *
     * OJO: esto NO es la codificación que usa la API de Factomate para su
     * propio campo `identityDocumentTypeCode` (catálogo `IdentityDocumentType/
     * Get`, ej. 1=CÉDULA, 2=PASAPORTE, 5=INNOMINADO). Son dos tablas
     * distintas — el mapeo explícito entre ambas vive en
     * SaleToInvoiceMapper::mapIdType(), no acá.
     */
    const ID_TYPE_RUC                        = 11;
    const ID_TYPE_CEDULA                     = 12;
    const ID_TYPE_PASAPORTE                  = 13;
    const ID_TYPE_CEDULA_EXTRANJERA          = 14;
    const ID_TYPE_SIN_NOMBRE                 = 15;
    const ID_TYPE_DIPLOMATICO                = 16;
    const ID_TYPE_IDENTIFICACION_TRIBUTARIA  = 17;
    const ID_TYPES = [
        self::ID_TYPE_RUC, self::ID_TYPE_CEDULA, self::ID_TYPE_PASAPORTE,
        self::ID_TYPE_CEDULA_EXTRANJERA, self::ID_TYPE_SIN_NOMBRE,
        self::ID_TYPE_DIPLOMATICO, self::ID_TYPE_IDENTIFICACION_TRIBUTARIA,
    ];

    private ContactRepository $repo;

    public function __construct(ContactRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * País del tenant (ISO-2) o null si no lo tiene configurado.
     *
     * Delega en TenantLocale, que es el resolver único de la localización del
     * comercio y ya cachea por companyId. Antes había un cache propio acá
     * sobre `ContactRepository::companyCountry()`: dos fuentes para el mismo
     * dato, y el resto del módulo igual terminaba asumiendo 'PY' por su lado.
     */
    private function tenantCountry(string $companyId): ?string
    {
        return \Punto\Api\Support\TenantLocale::country($companyId);
    }

    /**
     * Único lugar server-side que decide si el tenant puede usar
     * `contactIdType`: la feature es exclusiva de Paraguay (brief 2026-08-08).
     * Tanto la escritura (create/update) como la lectura (presentRow) pasan
     * por acá — así el gate no se repite ni se puede desincronizar entre
     * ambos lados.
     */
    private function isPyTenant(string $companyId): bool
    {
        return $this->tenantCountry($companyId) === 'PY';
    }

    /**
     * Regla de inferencia para contactos creados ANTES de esta feature
     * (2026-08-08) que no tienen `contactIdType` persistido (columna NULL):
     *   tiene RUC → 11, si no tiene CI → 12, si no → 15 (innominado).
     * Pública y estática porque EInvoiceService también la necesita al armar
     * el receptor del documento electrónico — single source of truth, no se
     * duplica la regla entre el CRUD de contactos y el pipeline de FE.
     */
    public static function inferIdType(?string $tin, ?string $ci): int
    {
        if (trim((string) $tin) !== '') {
            return self::ID_TYPE_RUC;
        }
        if (trim((string) $ci) !== '') {
            return self::ID_TYPE_CEDULA;
        }
        return self::ID_TYPE_SIN_NOMBRE;
    }

    // ── Unicidad de identidad (documento personal y teléfono) ───────────────

    /**
     * Normaliza un documento PERSONAL para compararlo: mayúsculas y solo
     * alfanuméricos. "1.234.567", "1234567" y "1 234 567" son el mismo
     * documento escrito de tres formas; sin esta normalización el bloqueo no
     * detecta el duplicado más común de todos.
     *
     * Alfanumérico y no solo dígitos porque el mismo campo (`contactCI`)
     * guarda pasaportes y carnets extranjeros — ver el docblock de
     * `CONTACT_ID_TYPES.numberField` en frontend/lib/contact-id-types.ts.
     *
     * No hay espejo en el front a propósito: el bloqueo es del backend (misma
     * regla para panel, POS, importador y agente IA), y duplicar la
     * normalización en el cliente crearía una segunda definición de "mismo
     * documento" que puede divergir sin que nada falle.
     */
    public static function normalizePersonalId(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $value))) ?? '';
    }

    /**
     * Normaliza un teléfono para compararlo: SOLO los dígitos. Ver el porqué
     * en `ContactRepository::findDuplicatePhone()`.
     */
    public static function normalizePhoneDigits(?string $value): string
    {
        return preg_replace('/[^0-9]/', '', (string) $value) ?? '';
    }

    /**
     * Bloqueo duro de duplicados de documento personal y teléfono.
     *
     * Decisión del owner (2026-08-31): el identificador FISCAL puede
     * repetirse —varias personas facturan a nombre de la misma empresa, es un
     * caso legítimo— pero el documento personal y el celular no. Vive acá y
     * no en el formulario del POS porque la misma regla tiene que valer para
     * el panel, el importador de CSV y el agente IA: los tres pasan por
     * `create()`/`update()`.
     *
     * Los duplicados que YA existen en la base NO se tocan y NO pueden trabar
     * una edición: si el número que llega es el MISMO que ya tiene el
     * contacto (comparado normalizado), no se chequea nada. Así, guardar un
     * contacto viejo sin tocarle el número nunca falla — ni por chocar
     * consigo mismo ni por un duplicado preexistente que alguien más ya
     * tenía. Solo se valida el número que efectivamente CAMBIA.
     *
     * La búsqueda es POR ROL (`type`): un cliente y un proveedor con el mismo
     * documento son dos registros legítimos de libros distintos, y bloquear
     * entre roles sorprendería a un comercio que le compra al mismo señor al
     * que le vende.
     *
     * Alcance en EMPLEADOS (type=0): el alta y la edición del equipo van por
     * `UsersService` (`/v1/users`), que no pasa por acá — el único camino que
     * llega es el PUT de `/v1/contacts`, ya restringido al realm `panel`. Ahí
     * el bloqueo de teléfono es deseable y no accidental: el login del panel
     * autentica por `contactPhone AND type=0` (ver el guard de realm en
     * api/v1/contacts.php), así que dos empleados con el mismo número es una
     * ambigüedad de identidad, no una comodidad.
     *
     * @param array      $rec      Record ya mapeado a columnas (post `mapToColumns`).
     * @param ?array     $existing Fila actual en update; null en create.
     */
    private function assertIdentityIsFree(
        string $companyId,
        array $rec,
        int $type,
        ?string $excludeId,
        array|CaseInsensitiveArray|null $existing,
    ): void {
        // Documento personal.
        if (array_key_exists('contactCI', $rec)) {
            $new = self::normalizePersonalId((string) ($rec['contactCI'] ?? ''));
            $old = $existing !== null ? self::normalizePersonalId((string) ($existing['contactCI'] ?? '')) : null;
            if ($new !== '' && $new !== $old) {
                $hit = $this->repo->findDuplicatePersonalId($new, $companyId, $type, $excludeId);
                if ($hit !== null) {
                    throw new DuplicateContactException(
                        $this->duplicateMessage($companyId, 'ci', $type, $hit['contactName']),
                        'ci',
                        $hit['contactId'],
                        $hit['contactName'],
                    );
                }
            }
        }

        // Teléfono.
        if (array_key_exists('contactPhone', $rec)) {
            $new = self::normalizePhoneDigits((string) ($rec['contactPhone'] ?? ''));
            $old = $existing !== null ? self::normalizePhoneDigits((string) ($existing['contactPhone'] ?? '')) : null;
            if ($new !== '' && $new !== $old) {
                $hit = $this->repo->findDuplicatePhone($new, $companyId, $type, $excludeId);
                if ($hit !== null) {
                    throw new DuplicateContactException(
                        $this->duplicateMessage($companyId, 'phone', $type, $hit['contactName']),
                        'phone',
                        $hit['contactId'],
                        $hit['contactName'],
                    );
                }
            }
        }
    }

    /**
     * Mensaje del choque. Nombra al contacto en conflicto a propósito: un
     * "ya existe" seco obliga al cajero a salir del alta, buscar a mano y
     * volver — el trabajo que esta regla venía a ahorrar.
     *
     * El nombre del documento sale del PAÍS del tenant
     * (`CountryDefaults::personalIdLabel`), nunca literal: en Argentina el
     * mismo error tiene que decir DNI, no "cédula".
     */
    private function duplicateMessage(string $companyId, string $field, int $type, string $name): string
    {
        $role = match ($type) {
            self::TYPE_CUSTOMER => 'cliente',
            self::TYPE_SUPPLIER => 'proveedor',
            default             => 'contacto',
        };

        if ($field === 'phone') {
            return "Ese teléfono ya lo tiene otro {$role}: {$name}";
        }

        $label = \Punto\Api\Support\CountryDefaults::personalIdLabel($this->tenantCountry($companyId))
            ?? 'documento';
        // "DNI"/"CPF" son siglas y van en mayúscula en medio de la frase;
        // "Cédula de identidad" es una descripción y ahí la mayúscula sobra.
        // La distinción es si la etiqueta ya viene toda en mayúsculas.
        if ($label !== mb_strtoupper($label, 'UTF-8')) {
            $label = mb_strtolower(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($label, 1, null, 'UTF-8');
        }

        return "Ese número de {$label} ya lo tiene otro {$role}: {$name}";
    }

    /**
     * Mapea el shape público a columnas de `contact`.
     * Solo incluye las claves presentes (para updates parciales).
     *
     * @param ?string $tenantIso País del tenant (ISO-2) para parsear el
     *   teléfono cuando el contacto no trae `country` propio. Es un parámetro
     *   y no un default cableado porque este mapper es estático y no conoce el
     *   companyId: antes asumía 'PY' y el teléfono local de un cliente de un
     *   comercio no paraguayo se rechazaba como inválido. `null` = sin país de
     *   referencia, el número debe venir en E.164.
     */
    public static function mapToColumns(array $in, ?string $tenantIso = null): array
    {
        $rec = [];

        // Razón social: fiscalName tiene prioridad; si no, el nombre de persona.
        if (!empty($in['fiscalName'])) {
            $rec['contactName'] = strip_tags($in['fiscalName']);
        } elseif (!empty($in['name'])) {
            $rec['contactName'] = strip_tags($in['name']);
        }
        if (!empty($in['name'])) {
            $rec['contactSecondName'] = strip_tags($in['name']);
        }

        if (isset($in['tin']))   $rec['contactTIN'] = strip_tags((string) $in['tin']);
        // Fix de bug legacy: edit_customer.php escribía `ci` en contactTIN.
        if (isset($in['ci']))    $rec['contactCI'] = $in['ci'];
        if (!empty($in['bday'])) $rec['contactBirthDay'] = $in['bday'];

        // Tipo de documento (Tabla 3 SET) — feature exclusiva de Paraguay.
        // mapToColumns es un mapper puro sin acceso a companyId, así que acá
        // SOLO valida que el código exista en el set permitido; el gate de
        // país (¿se persiste o se ignora?) vive en create()/update(), que sí
        // conocen el tenant (ver isPyTenant()). '' o null limpia el campo
        // (contacto vuelve a inferirse on-read).
        if (array_key_exists('idType', $in)) {
            if ($in['idType'] === null || $in['idType'] === '') {
                $rec['contactIdType'] = null;
            } else {
                $idType = (int) $in['idType'];
                if (!in_array($idType, self::ID_TYPES, true)) {
                    throw new InvalidArgumentException("Tipo de documento inválido: {$in['idType']}");
                }
                $rec['contactIdType'] = $idType;
            }
        }

        // Migración 25 (2026-06-13): los siguientes campos viven en `data` JSONB.
        // ncmInsert/ncmUpdate los enrutan automáticamente vía _routeToJsonb porque
        // están AUSENTES del whitelist `contact` en _getTableSchema. Seguimos
        // populating el record con el mismo nombre que tenían como columna —
        // el router los detecta como "fuera del whitelist" y los mueve a JSONB.
        if (isset($in['note']))     $rec['contactNote']     = strip_tags((string) $in['note']);
        if (isset($in['city']))     $rec['contactCity']     = strip_tags((string) $in['city']);
        if (isset($in['location'])) $rec['contactLocation'] = strip_tags((string) $in['location']);
        if (isset($in['country']))  $rec['contactCountry']  = strip_tags((string) $in['country']);
        if (isset($in['address']))  $rec['contactAddress']  = strip_tags((string) $in['address']);
        if (isset($in['address2'])) $rec['contactAddress2'] = strip_tags((string) $in['address2']);
        if (isset($in['phone'])) {
            require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
            // `country` del contacto (puede ser distinto al del comercio: un
            // cliente extranjero) y, si no vino, el país del tenant.
            $iso = strtoupper(trim((string)($in['country'] ?? ''))) ?: $tenantIso;
            $rec['contactPhone'] = phoneValidateForStorage($in['phone'], $iso);
        }
        // contactPhone2 ELIMINADO de la tabla (Migración 25). El form ya no lo
        // pide; ignoramos cualquier valor legacy que llegue en el payload.
        if (isset($in['email']))    $rec['contactEmail']    = strip_tags((string) $in['email']);

        if (isset($in['status']))        $rec['contactStatus']        = (int) $in['status'];
        if (isset($in['storeCredit']))   $rec['contactStoreCredit']   = $in['storeCredit'];
        if (isset($in['loyalty']))       $rec['contactLoyalty']       = $in['loyalty'];
        if (isset($in['loyaltyAmount'])) $rec['contactLoyaltyAmount'] = $in['loyaltyAmount'];
        // Línea de crédito: habilita venta a crédito (isCreditable en POS) + tope de saldo.
        if (isset($in['isCreditable']))  $rec['contactCreditable']    = !empty($in['isCreditable']) ? 1 : 0;
        if (isset($in['creditLine']))    $rec['contactCreditLine']    = $in['creditLine'];

        // priceListId vive en data JSONB; ncmInsert/ncmUpdate lo enrutan solos
        // por ausencia del campo en el whitelist de la tabla contact.
        // UUID vacío → null (limpiar asignación). Cadena vacía también limpia.
        if (array_key_exists('priceListId', $in)) {
            $plId = trim((string) ($in['priceListId'] ?? ''));
            $rec['priceListId'] = $plId !== '' ? $plId : null;
        }

        // contactLatLng vive en data JSONB; ncmInsert/ncmUpdate lo enrutan solos.
        if (self::hasCoords($in)) {
            $rec['contactLatLng'] = strip_tags($in['lat'] . ',' . $in['lng']);
        }

        return $rec;
    }

    /**
     * ¿El input trae el PAR completo de coordenadas?
     *
     * Existe para que los dos mappers de coordenadas —`mapToColumns()` (JSONB
     * `contactLatLng`) y `mapToAddress()` (columnas de `customerAddress`)—
     * decidan con el MISMO criterio: si discrepan, un contacto termina con
     * punto en un lado y sin punto en el otro.
     *
     * Antes era `!empty($in['lat']) && !empty($in['lng'])`, y `empty()` trata
     * al CERO como ausente. `0` es una coordenada perfectamente válida: el
     * meridiano de Greenwich (lng = 0) pasa por Londres y por Accra, así que un
     * comercio ahí cargaba su ubicación y las dos coordenadas se descartaban
     * sin un solo mensaje. Se compara contra null y '' —el mismo criterio de
     * `Ai\ContactPayload::coordsError()`, que es quien rechaza el par
     * incompleto antes de llegar acá— para que "el valor es cero" y "no mandó
     * nada" dejen de ser lo mismo.
     */
    private static function hasCoords(array $in): bool
    {
        $lat = $in['lat'] ?? null;
        $lng = $in['lng'] ?? null;
        return $lat !== null && $lat !== '' && $lng !== null && $lng !== '';
    }

    /**
     * Extrae los campos de dirección default del shape público.
     * Devuelve [] si no vino ningún dato de dirección.
     */
    public static function mapToAddress(array $in): array
    {
        $addr = [];
        if (isset($in['city']))     $addr['customerAddressCity']     = strip_tags((string) $in['city']);
        if (isset($in['location'])) $addr['customerAddressLocation'] = strip_tags((string) $in['location']);
        if (isset($in['address']))  $addr['customerAddressText']     = strip_tags((string) $in['address']);
        if (self::hasCoords($in)) {
            $addr['customerAddressLat'] = $in['lat'];
            $addr['customerAddressLng'] = $in['lng'];
        }
        return $addr;
    }

    /**
     * Crea un contacto + su dirección default.
     *
     * @return string contactId del nuevo registro.
     * @throws InvalidArgumentException si falta el nombre/razón social.
     * @throws RuntimeException si la inserción falla.
     */
    public function create(string $companyId, array $in): string
    {
        if (empty($in['fiscalName']) && empty($in['name'])) {
            throw new InvalidArgumentException('Nombre y apellido o Razón social son obligatorios');
        }

        $rec                  = self::mapToColumns($in, $this->tenantCountry($companyId));
        // type viene del input (1=cliente, 2=proveedor). Default cliente para
        // backwards-compat con consumidores que no lo mandan.
        $inputType            = (int) ($in['type'] ?? self::TYPE_CUSTOMER);
        $rec['type']          = in_array($inputType, [self::TYPE_CUSTOMER, self::TYPE_SUPPLIER], true)
            ? $inputType
            : self::TYPE_CUSTOMER;
        $rec['contactStatus'] = 1; // legacy: nuevo contacto siempre activo
        $rec['companyId']     = $companyId;
        $rec['contactDate']   = TODAY;
        $rec['updated_at']    = TODAY;

        // contactIdType es exclusivo de Paraguay — un tenant de otro país que
        // mande el campo igual (ej. cliente viejo del form cacheado) lo ve
        // ignorado en silencio, no rechazado: no es un error del cajero.
        if (!$this->isPyTenant($companyId)) {
            unset($rec['contactIdType']);
        }

        // Unicidad de documento personal y teléfono — ver assertIdentityIsFree().
        // Va DESPUÉS de mapToColumns porque el teléfono se compara ya
        // normalizado a la forma de storage (E.164 sin '+'): comparar el crudo
        // que tipeó el cajero contra lo guardado no matchea nunca.
        $this->assertIdentityIsFree($companyId, $rec, (int) $rec['type'], null, null);

        $newId = $this->repo->create($rec);
        if ($newId === false) {
            throw new RuntimeException('No se pudo crear el contacto');
        }

        $this->syncDefaultAddress((string) $newId, $companyId, $in, true);

        return (string) $newId;
    }

    /**
     * Actualiza un contacto existente (patch parcial) + sincroniza dirección default.
     *
     * @return bool true si el UPDATE no falló.
     */
    public function update(string $id, string $companyId, array $in): bool
    {
        $rec = self::mapToColumns($in, $this->tenantCountry($companyId));
        $rec['updated_at'] = TODAY;

        // Mismo gate que create() — ver comentario ahí.
        if (!$this->isPyTenant($companyId)) {
            unset($rec['contactIdType']);
        }

        // Unicidad: el `type` y los números ACTUALES salen de la fila, no del
        // patch — el PUT de `/v1/contacts` borra `type` del payload, y sin la
        // fila no hay contra qué comparar para saber si el número cambió (que
        // es lo único que se valida; ver assertIdentityIsFree()).
        $existing = $this->repo->find($id, $companyId);
        if ($existing !== null) {
            $this->assertIdentityIsFree(
                $companyId,
                $rec,
                (int) ($existing['type'] ?? self::TYPE_CUSTOMER),
                $id,
                $existing,
            );
        }

        $ok = $this->repo->update($id, $companyId, $rec);
        if (!$ok) return false;

        $this->syncDefaultAddress($id, $companyId, $in, false);

        return true;
    }

    public function archive(string $id, string $companyId): bool
    {
        return $this->repo->archive($id, $companyId);
    }

    /**
     * Find por contactId + companyId — sin filtro por type. Útil cuando ya
     * tenés el id (PK) y no querés discriminar. Para listings filtrados por
     * cliente/proveedor usar listByType().
     */
    public function find(string $id, string $companyId): array|CaseInsensitiveArray|null
    {
        return $this->repo->find($id, $companyId);
    }

    /**
     * Lista contactos del tipo dado con su dirección default resuelta.
     * `$type` = TYPE_CUSTOMER (1) o TYPE_SUPPLIER (2). Solo se filtra por
     * type en SQL — la presentación es la misma para ambos.
     *
     * @return array{contacts: array, total: int, limit: int, offset: int}
     */
    public function listByType(int $type, string $companyId, array $opts = []): array
    {
        $limit  = max(1, min((int) ($opts['limit'] ?? 50), 1000));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $rows  = $this->repo->listByType($type, $companyId, $opts);
        $total = $this->repo->countByType($type, $companyId, $opts);

        $contacts = [];
        foreach ($rows as $row) {
            $contacts[] = $this->presentRow($row, $companyId);
        }

        return [
            'contacts' => $contacts,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
        ];
    }

    /**
     * @deprecated usar listByType(TYPE_CUSTOMER, ...). Mantenido como alias
     * mientras existan consumidores legacy.
     */
    public function listCustomers(string $companyId, array $opts = []): array
    {
        return $this->listByType(self::TYPE_CUSTOMER, $companyId, $opts);
    }

    /**
     * Detalle de un contacto del tipo dado, o null si no existe.
     */
    public function getByType(string $id, int $type, string $companyId): ?array
    {
        $row = $this->repo->find($id, $companyId, $type);
        if ($row === null) return null;
        return $this->presentRow($row, $companyId);
    }

    /**
     * Detalle por id, SIN filtrar por type. El detalle por id es una vista de
     * "este registro", no de "este registro si es cliente/proveedor" — filtrar
     * por type acá causaba que proveedores (type=2) devolvieran 404 cuando el
     * caller no pasaba `?type=2` explícito (ej. tab detalle post-creación),
     * dando la falsa impresión de que el alta no se había guardado.
     */
    public function getById(string $id, string $companyId): ?array
    {
        $row = $this->repo->find($id, $companyId, null);
        if ($row === null) return null;
        return $this->presentRow($row, $companyId);
    }

    /** @deprecated usar getByType(id, TYPE_CUSTOMER, companyId). */
    public function getCustomer(string $id, string $companyId): ?array
    {
        return $this->getByType($id, self::TYPE_CUSTOMER, $companyId);
    }

    /**
     * Fetch puntual por lista de ids (bulk-get quirúrgico, context/15). Ids
     * que no existen, no son del tenant, o no son del `$type` pedido
     * simplemente no vienen en el resultado — el caller lo interpreta como
     * "borrado" (mismo criterio que `listByType`: type=1 cliente por
     * default, el único caller hoy es el sync de clientes del POS).
     */
    public function getManyByIds(array $ids, string $companyId, int $type = self::TYPE_CUSTOMER): array
    {
        $out = [];
        foreach ($this->repo->getManyByIds($ids, $companyId, $type) as $row) {
            $out[] = $this->presentRow($row, $companyId);
        }
        return $out;
    }

    /**
     * Delta incremental (context/43-sync-incremental.md, sync del POS) —
     * mismo `presentRow()` que `getManyByIds()`, así el shape de fila nunca
     * diverge entre "vengo de un fetch puntual por ids" y "vengo del delta
     * por fecha". `$since = null` trae TODOS los contactos del tipo (full).
     */
    public function manyUpdatedSince(int $type, string $companyId, ?string $since): array
    {
        $out = [];
        foreach ($this->repo->listUpdatedSince($type, $companyId, $since) as $row) {
            $out[] = $this->presentRow($row, $companyId);
        }
        return $out;
    }

    public function addresses(string $contactId, string $companyId): array
    {
        $out = [];
        foreach ($this->repo->addresses($contactId, $companyId) as $a) {
            $lat = $a['customerAddressLat'] ?? null;
            $lng = $a['customerAddressLng'] ?? null;
            $out[] = [
                'id'       => $a['customerAddressId'] ?? null,
                'name'     => $a['customerAddressName'] ?? null,
                'date'     => $a['customerAddressDate'] ?? null,
                'address'  => $a['customerAddressText'] ?? null,
                'lat'      => $lat,
                'lng'      => $lng,
                'latLng'   => ($lat && $lng) ? $lat . ',' . $lng : null,
                'location' => $a['customerAddressLocation'] ?? null,
                'city'     => $a['customerAddressCity'] ?? null,
                'default'  => $a['customerAddressDefault'] ?? null,
            ];
        }
        return $out;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Crea o actualiza la dirección default según exista o no.
     * En create ($isNew=true) inserta directamente; en update verifica primero.
     */
    private function syncDefaultAddress(string $contactId, string $companyId, array $in, bool $isNew): void
    {
        $addr = self::mapToAddress($in);
        if (empty($addr)) return;

        $addr['companyId'] = $companyId;
        $addr['customerId'] = $contactId;

        if (!$isNew && $this->repo->defaultAddress($contactId, $companyId) !== null) {
            $this->repo->updateDefaultAddress($contactId, $companyId, $addr);
            return;
        }

        $addr['customerAddressDefault'] = 1;
        $this->repo->createAddress($addr);
    }

    /**
     * Da forma al shape público de un contacto (incluye dirección default).
     */
    private function presentRow(array|CaseInsensitiveArray $row, string $companyId): array
    {
        $id      = (string) $row['contactId'];
        $address = $this->repo->defaultAddress($id, $companyId);

        // idType: null para tenants no-PY (feature no existe para ellos,
        // mismo gate que la escritura — ver isPyTenant()). Para PY: el valor
        // persistido, o inferido si el contacto es de antes de esta feature
        // (contactIdType NULL) — ver inferIdType().
        $idType = null;
        if ($this->isPyTenant($companyId)) {
            $storedIdType = $row['contactIdType'] ?? null;
            $idType = $storedIdType !== null
                ? (int) $storedIdType
                : self::inferIdType($row['contactTIN'] ?? null, $row['contactCI'] ?? null);
        }

        return [
            'id'          => $id,
            'UID'         => $id,
            'name'        => toUTF8($row['contactName'] ?? ''),
            'fullname'    => toUTF8($row['contactSecondName'] ?? ''),
            'tin'         => $row['contactTIN'] ?? null,
            'ci'          => $row['contactCI'] ?? null,
            'idType'      => $idType,
            'bday'        => $row['contactBirthDay'] ?? null,
            'phone'       => $row['contactPhone'] ?? null,
            // contactPhone2 ELIMINADO de la tabla (Migración 25). El front ya
            // no lo lee; preservar la key vacía rompía nada pero ensucia el shape.
            'email'       => $row['contactEmail'] ?? null,
            'note'        => $row['contactNote'] ?? null,
            'status'      => $row['contactStatus'] ?? null,
            'storeCredit' => $row['contactStoreCredit'] ?? null,
            'loyalty'     => $row['contactLoyalty'] ?? null,
            'loyaltyAmount' => $row['contactLoyaltyAmount'] ?? null,
            'isCreditable' => (bool) ((int) ($row['contactCreditable'] ?? 0) > 0),
            'creditLine'  => (float) ($row['contactCreditLine'] ?? 0),
            'country'     => $row['contactCountry'] ?? null,
            'date'        => $row['contactDate'] ?? null,
            'addressId'   => $address['customerAddressId'] ?? null,
            'address'     => $address['customerAddressText'] ?? ($row['contactAddress'] ?? null),
            'address2'    => $row['contactAddress2'] ?? null,
            'city'        => $address['customerAddressCity'] ?? ($row['contactCity'] ?? null),
            'location'    => $address['customerAddressLocation'] ?? ($row['contactLocation'] ?? null),
            'lat'         => $address['customerAddressLat'] ?? null,
            'lng'         => $address['customerAddressLng'] ?? null,
            // priceListId desde data JSONB — null si el contacto no tiene lista asignada.
            'priceListId' => $row['priceListId'] ?? null,
            // Rol del registro en la tabla `contact`: 1=cliente, 2=proveedor.
            // Expuesto para que el detalle por id (que ya no filtra por type)
            // pueda derivar el label "cliente"/"proveedor" del propio dato.
            'type'        => isset($row['type']) ? (int) $row['type'] : self::TYPE_CUSTOMER,
        ];
    }
}
