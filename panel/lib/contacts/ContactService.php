<?php

/**
 * ContactService — orquesta CRUD de contactos (clientes/proveedores) + reglas de negocio.
 *
 * Punto de entrada para panel/API/v1/contacts.php y, a futuro, los handlers de a_contacts.php.
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
 */

require_once __DIR__ . '/ContactRepository.php';

class ContactService
{
    /** type=1 en la tabla `contact` cubre cliente/proveedor. */
    const TYPE_CUSTOMER = 1;

    private ContactRepository $repo;

    public function __construct(ContactRepository $repo)
    {
        $this->repo = $repo;
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

        if (isset($in['note']))     $rec['contactNote']     = strip_tags((string) $in['note']);
        if (isset($in['city']))     $rec['contactCity']     = strip_tags((string) $in['city']);
        if (isset($in['location'])) $rec['contactLocation'] = strip_tags((string) $in['location']);
        if (isset($in['country']))  $rec['contactCountry']  = strip_tags((string) $in['country']);
        if (isset($in['address']))  $rec['contactAddress']  = strip_tags((string) $in['address']);
        if (isset($in['phone']))    $rec['contactPhone']    = $in['phone'];
        if (isset($in['phone2']))   $rec['contactPhone2']   = $in['phone2'];
        if (isset($in['email']))    $rec['contactEmail']    = strip_tags((string) $in['email']);

        if (isset($in['status']))        $rec['contactStatus']        = (int) $in['status'];
        if (isset($in['storeCredit']))   $rec['contactStoreCredit']   = $in['storeCredit'];
        if (isset($in['loyalty']))       $rec['contactLoyalty']       = $in['loyalty'];
        if (isset($in['loyaltyAmount'])) $rec['contactLoyaltyAmount'] = $in['loyaltyAmount'];

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
        $rec['type']          = self::TYPE_CUSTOMER;
        $rec['contactStatus'] = 1; // legacy: nuevo contacto siempre activo
        $rec['companyId']     = $companyId;
        $rec['contactDate']   = TODAY;
        $rec['updated_at']    = TODAY;

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

        $ok = $this->repo->update($id, $companyId, $rec);
        if (!$ok) return false;

        $this->syncDefaultAddress($id, $companyId, $in, false);

        return true;
    }

    public function archive(string $id, string $companyId): bool
    {
        return $this->repo->archive($id, $companyId);
    }

    public function find(string $id, string $companyId): ?CaseInsensitiveArray
    {
        return $this->repo->find($id, $companyId, self::TYPE_CUSTOMER);
    }

    /**
     * Lista de clientes con su dirección default resuelta, lista para la API/UI.
     *
     * @return array{contacts: array, total: int, limit: int, offset: int}
     */
    public function listCustomers(string $companyId, array $opts = []): array
    {
        $limit  = max(1, min((int) ($opts['limit'] ?? 50), 1000));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $rows  = $this->repo->listByType(self::TYPE_CUSTOMER, $companyId, $opts);
        $total = $this->repo->countByType(self::TYPE_CUSTOMER, $companyId, $opts);

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
     * Detalle de un cliente (contacto + dirección default), o null si no existe.
     */
    public function getCustomer(string $id, string $companyId): ?array
    {
        $row = $this->repo->find($id, $companyId, self::TYPE_CUSTOMER);
        if ($row === null) return null;
        return $this->presentRow($row, $companyId);
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
    private function presentRow(CaseInsensitiveArray $row, string $companyId): array
    {
        $id      = (string) $row['contactId'];
        $address = $this->repo->defaultAddress($id, $companyId);

        return [
            'id'          => $id,
            'UID'         => $id,
            'name'        => toUTF8($row['contactName'] ?? ''),
            'fullname'    => toUTF8($row['contactSecondName'] ?? ''),
            'tin'         => $row['contactTIN'] ?? null,
            'ci'          => $row['contactCI'] ?? null,
            'bday'        => $row['contactBirthDay'] ?? null,
            'phone'       => $row['contactPhone'] ?? null,
            'phone2'      => $row['contactPhone2'] ?? null,
            'email'       => $row['contactEmail'] ?? null,
            'note'        => $row['contactNote'] ?? null,
            'status'      => $row['contactStatus'] ?? null,
            'storeCredit' => $row['contactStoreCredit'] ?? null,
            'loyalty'     => $row['contactLoyalty'] ?? null,
            'loyaltyAmount' => $row['contactLoyaltyAmount'] ?? null,
            'country'     => $row['contactCountry'] ?? null,
            'date'        => $row['contactDate'] ?? null,
            'addressId'   => $address['customerAddressId'] ?? null,
            'address'     => $address['customerAddressText'] ?? ($row['contactAddress'] ?? null),
            'city'        => $address['customerAddressCity'] ?? ($row['contactCity'] ?? null),
            'location'    => $address['customerAddressLocation'] ?? ($row['contactLocation'] ?? null),
            'lat'         => $address['customerAddressLat'] ?? null,
            'lng'         => $address['customerAddressLng'] ?? null,
        ];
    }
}
