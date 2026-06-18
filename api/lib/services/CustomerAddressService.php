<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)
/**
 * CustomerAddressService — capa de datos de las direcciones de cliente del POS.
 *
 * Lógica portada de app/action.php (handlers customerAddress* 910-1019) y
 * app/load.php (load=customerAddress, 2240). Primer slice del desacople de /app:
 * la SQL/negocio sale del IF gigante y queda acá; la API solo orquesta.
 *
 * IMPORTANTE — por qué las ESCRITURAS NO usan ncmInsert/ncmUpdate: en /app esos
 * wrappers llaman a $db->Insert_ID(), método que la DB.php de /app (divergida del
 * panel, post-Phase-PG) NO implementa → fatal. Las escrituras usan $db->Execute/
 * $db->Insert directos y PARAMETRIZADOS (las lecturas sí usan ncmExecute, que no
 * toca Insert_ID). Esto además corrige bugs PG latentes del legacy:
 *   - UUIDs bindeados (el legacy los interpolaba sin comillas vía db_prepare identity).
 *   - DELETE sin `LIMIT 1` (PG no lo soporta en DELETE).
 *   - addressId (client-supplied) parametrizado → sin inyección.
 *   - clear-defaults scopeado por companyId (el legacy omitía el tenant).
 * (customerAddress NO está degradada a JSONB → columnas reales, INSERT directo OK.)
 *
 * Todas las ops scopean por companyId (tenant). La identidad viene del JWT en la API.
 */

final class CustomerAddressService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /** Lista las direcciones de un cliente (o una sola si se pasa addressId). Shape = front legacy. */
    public function listForCustomer(string $companyId, string $customerId, ?string $addressId = null): array
    {
        if ($addressId !== null && $addressId !== '') {
            $row = ncmExecute(
                'SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',
                [$addressId, $companyId],
                false
            );
            return $row ? [$this->shape($row)] : [];
        }

        $rows = ncmExecute(
            'SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ?
              ORDER BY customerAddressDefault DESC, customerAddressId DESC LIMIT 10',
            [$customerId, $companyId],
            false,
            true
        );

        $out = [];
        if ($rows) {
            while (!$rows->EOF) {
                $out[] = $this->shape($rows->fields);
                $rows->MoveNext();
            }
        }
        return $out;
    }

    /** Crea una dirección (queda como default). Desmarca el default de las demás ANTES de insertar. */
    public function add(string $companyId, string $customerId, array $f): array
    {
        global $db;

        $record = $this->fieldsToRecord($f);
        $record['customerAddressDate']    = TODAY;
        $record['customerAddressDefault'] = true;
        $record['customerId']             = $customerId;
        $record['companyId']              = $companyId;

        // Clear-then-insert atómico: sin Insert_ID en /app no podemos excluir el nuevo
        // PK del clear, así que limpiamos el default ANTES e insertamos como default.
        // La transacción evita dejar al cliente sin ninguna default si el INSERT falla.
        $db->StartTrans();
        $db->Execute(
            'UPDATE customerAddress SET customerAddressDefault = NULL WHERE customerId = ? AND companyId = ?',
            [$customerId, $companyId]
        );
        if ($db->Insert('customerAddress', $record) === false) {
            $db->FailTrans();
        }
        if (!$db->CompleteTrans()) {
            return ['ok' => false];
        }

        $this->touchContact($companyId, $customerId);
        $this->touchCompany($companyId);
        return ['ok' => true];
    }

    /** Actualiza nombre/dirección/ubicación/ciudad/coordenadas de una dirección del cliente. */
    public function update(string $companyId, string $customerId, string $addressId, array $f): array
    {
        global $db;

        $record = $this->fieldsToRecord($f);
        $record['updated_at'] = TODAY;

        $sets   = [];
        $params = [];
        foreach ($record as $col => $val) {
            $sets[]   = $col . ' = ?';
            $params[] = $val;
        }
        $params[] = $customerId;
        $params[] = $companyId;
        $params[] = $addressId;

        $res = $db->Execute(
            'UPDATE customerAddress SET ' . implode(', ', $sets)
                . ' WHERE customerId = ? AND companyId = ? AND customerAddressId = ?',
            $params
        );
        if ($res === false) {
            return ['ok' => false];
        }

        $this->touchContact($companyId, $customerId);
        $this->touchCompany($companyId);
        return ['ok' => true];
    }

    /** Borra una dirección y sus referencias en toAddress (ambos scopeados al tenant). */
    public function delete(string $companyId, string $customerId, string $addressId): array
    {
        global $db;

        $db->StartTrans();

        // toAddress no tiene companyId propio → scopear vía subquery a customerAddress
        // del tenant, así un addressId ajeno/adivinado no borra refs de otra empresa.
        $db->Execute(
            'DELETE FROM toAddress WHERE customerAddressId = ? AND customerAddressId IN
               (SELECT customerAddressId FROM customerAddress WHERE companyId = ?)',
            [$addressId, $companyId]
        );

        if ($db->Execute(
            'DELETE FROM customerAddress WHERE customerAddressId = ? AND customerId = ? AND companyId = ?',
            [$addressId, $customerId, $companyId]
        ) === false) {
            $db->FailTrans();
        }

        if (!$db->CompleteTrans()) {
            return ['ok' => false];
        }

        $this->touchContact($companyId, $customerId);
        $this->touchCompany($companyId);
        return ['ok' => true];
    }

    /** Marca una dirección como default y desmarca el resto del cliente. */
    public function setDefault(string $companyId, string $customerId, string $addressId): array
    {
        global $db;

        $db->Execute(
            'UPDATE customerAddress SET customerAddressDefault = NULL WHERE customerId = ? AND companyId = ?',
            [$customerId, $companyId]
        );

        $res = $db->Execute(
            'UPDATE customerAddress SET customerAddressDefault = true
              WHERE customerId = ? AND companyId = ? AND customerAddressId = ?',
            [$customerId, $companyId, $addressId]
        );
        if ($res === false) {
            return ['ok' => false];
        }

        $this->touchContact($companyId, $customerId);
        $this->touchCompany($companyId);
        return ['ok' => true];
    }

    // --- internos -----------------------------------------------------------

    /** Mapea los campos del request (name/address/location/city/lat/lng/latLng) al record de BD. */
    private function fieldsToRecord(array $f): array
    {
        $record = [
            'customerAddressName'     => strip_tags($f['name'] ?? ''),
            'customerAddressText'     => strip_tags($f['address'] ?? ''),
            'customerAddressLocation' => strip_tags($f['location'] ?? ''),
            'customerAddressCity'     => strip_tags($f['city'] ?? ''),
        ];

        // Coordenadas: leemos primero los campos directos (lat/lng) que el
        // front nuevo manda como números. Fallback al string latLng "lat,lng"
        // para back-compat con clientes legacy.
        $lat = $f['lat'] ?? null;
        $lng = $f['lng'] ?? null;
        if (($lat === null || $lng === null) && !empty($f['latLng']) && strpos($f['latLng'], ',') !== false) {
            [$lat, $lng] = explode(',', $f['latLng'], 2);
        }
        if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
            $record['customerAddressLat'] = (float) $lat;
            $record['customerAddressLng'] = (float) $lng;
        }

        return $record;
    }

    /** Marca el contacto como modificado (sync cache-busting). */
    private function touchContact(string $companyId, string $customerId): void
    {
        global $db;
        $db->Execute(
            'UPDATE contact SET updated_at = ? WHERE contactId = ? AND companyId = ?',
            [TODAY, $customerId, $companyId]
        );
    }

    /**
     * Marca el lastUpdate de la company (equivale a updateLastTimeEdit($id,'customer'),
     * pero parametrizado — el helper legacy interpola el UUID sin comillas, roto en PG).
     */
    private function touchCompany(string $companyId): void
    {
        global $db;
        $db->Execute(
            'UPDATE company SET customersLastUpdate = ?, companyLastUpdate = ? WHERE companyId = ?',
            [TODAY, TODAY, $companyId]
        );
    }

    private function shape($row): array
    {
        $lat = $row['customerAddressLat'] ?? null;
        $lng = $row['customerAddressLng'] ?? null;
        return [
            'id'         => (string) $row['customerAddressId'],
            'name'       => $row['customerAddressName'] ?? '',
            'address'    => $row['customerAddressText'] ?? '',
            'default'    => $row['customerAddressDefault'] ?? null,
            'location'   => $row['customerAddressLocation'] ?? '',
            'city'       => $row['customerAddressCity'] ?? '',
            'latLng'     => $lat ? ($lat . ',' . $lng) : false,
            'lat'        => $lat,
            'lng'        => $lng,
            'customerId' => (string) $row['customerId'],
        ];
    }
}
