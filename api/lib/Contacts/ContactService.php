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
     * junio 2021) — tipo de documento de identidad del receptor. Feature
     * exclusiva de Paraguay (brief 2026-08-08, cliente extranjero + FE).
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

    /** Cache in-memory del país del tenant por companyId — un request nunca
        mezcla tenants, así que 1 lookup por companyId alcanza. Ver isPyTenant(). */
    private array $countryCache = [];

    public function __construct(ContactRepository $repo)
    {
        $this->repo = $repo;
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
        if (!array_key_exists($companyId, $this->countryCache)) {
            $this->countryCache[$companyId] = $this->repo->companyCountry($companyId) === 'PY';
        }
        return $this->countryCache[$companyId];
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

    /**
     * Mapea el shape público a columnas de `contact`.
     * Solo incluye las claves presentes (para updates parciales).
     */
    public static function mapToColumns(array $in): array
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
            $iso = (string)($in['country'] ?? 'PY');
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
        if (!empty($in['lat']) && !empty($in['lng'])) {
            $rec['contactLatLng'] = strip_tags($in['lat'] . ',' . $in['lng']);
        }

        return $rec;
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
        if (!empty($in['lat']) && !empty($in['lng'])) {
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

        $rec                  = self::mapToColumns($in);
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
        $rec = self::mapToColumns($in);
        $rec['updated_at'] = TODAY;

        // Mismo gate que create() — ver comentario ahí.
        if (!$this->isPyTenant($companyId)) {
            unset($rec['contactIdType']);
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
