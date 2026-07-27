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

    /**
     * Lista las direcciones de un cliente (o una sola si se pasa addressId).
     * Shape = front legacy. `status = 1` filtra las borradas (soft-delete,
     * mig 87) — una dirección referenciada por una orden histórica
     * (tabla toAddress) sigue existiendo en BD pero no debe reaparecer acá.
     */
    public function listForCustomer(string $companyId, string $customerId, ?string $addressId = null): array
    {
        if ($addressId !== null && $addressId !== '') {
            $row = ncmExecute(
                'SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? AND status = 1 LIMIT 1',
                [$addressId, $companyId],
                false
            );
            return $row ? [$this->shape($row)] : [];
        }

        $rows = ncmExecute(
            'SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? AND status = 1
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

    /**
     * Soft-delete (status = 0, mig 87) — ya NO es DELETE físico. Una
     * dirección referenciada por una orden histórica (tabla toAddress,
     * órdenes legacy type=12) tiene que seguir existiendo para que esa
     * orden pueda seguir mostrando a dónde fue; solo desaparece de los
     * listados (listForCustomer filtra status = 1). Si la dirección
     * borrada era la default, queda sin default — mismo comportamiento que
     * antes (no se promueve otra automáticamente).
     */
    public function delete(string $companyId, string $customerId, string $addressId): array
    {
        global $db;

        $res = $db->Execute(
            'UPDATE customerAddress SET status = 0, customerAddressDefault = NULL
              WHERE customerAddressId = ? AND customerId = ? AND companyId = ?',
            [$addressId, $customerId, $companyId]
        );
        if ($res === false) {
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

        // status = 1: no se puede "predeterminar" una dirección ya borrada
        // (soft-delete, mig 87) — el UPDATE simplemente no afecta filas y
        // el cliente queda sin default, mismo resultado que un addressId
        // inexistente.
        $res = $db->Execute(
            'UPDATE customerAddress SET customerAddressDefault = true
              WHERE customerId = ? AND companyId = ? AND customerAddressId = ? AND status = 1',
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
            // Referencia verbal ("portón negro, timbre 2") — mig 87. Vacío se
            // guarda como NULL (no '') para poder distinguir "sin referencia"
            // de "referencia vacía a propósito" si algún día hace falta.
            'reference'               => isset($f['reference']) && $f['reference'] !== ''
                ? strip_tags((string) $f['reference'])
                : null,
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
            'reference'  => $row['reference'] ?? '',
            'latLng'     => $lat ? ($lat . ',' . $lng) : false,
            'lat'        => $lat,
            'lng'        => $lng,
            'customerId' => (string) $row['customerId'],
        ];
    }
}
