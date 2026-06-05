<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Datos de clientes/contactos del POS.
 *
 * Reemplaza las funciones globales (Slice 9 del plan PSR-4):
 *   - getRealCustomerId($id)                 → Customer::getRealCustomerId($id)
 *   - manageCustomerLoyalty(...)             → Customer::manageLoyalty(...)
 *   - manageCustomerStoreCredit(...)         → Customer::manageStoreCredit(...)
 *   - manageGiftCard($amount, $id)           → Customer::manageGiftCard($amount, $id)
 *   - getAllContacts($type, $where)           → Customer::getAllContacts($type, $where)
 *   - getTheContactField($id, $arr, $field)  → Customer::getContactField($id, $arr, $field)
 *   - getCustomerData($id, $type)            → Customer::getData($id, $type)
 *   - getCustomerTransactionAddress(...)     → Customer::getTransactionAddress(...)
 *   - getContactData($id, $type, $cache)     → Customer::getContactData($id, $type, $cache)
 *   - getCustomerName($data, $part)          → Customer::getName($data, $part)
 *   - getContactCreditLine($uid, $creditLine)→ Customer::getContactCreditLine($uid, $creditLine)
 *
 * Las funciones globales permanecen como wrappers — cero breaking changes en
 * los ~139 callsites totales del POS.
 *
 * GDPR: getContactData/getData devuelven PII. Los filtros por companyId del
 * legacy se preservan VERBATIM — no se amplían ni reducen datos expuestos.
 */
final class Customer
{
    /**
     * Devuelve el campo de identificación del contacto.
     * Equivalente legacy: `getRealCustomerId($id)`.
     *
     * Nota: ambas ramas del legacy retornaban 'contactId' — dead code preservado.
     */
    public static function getRealCustomerId(mixed $id): string
    {
        $l = strlen((string) $id);
        if ($l > 11) {
            return 'contactId';
        } else {
            return 'contactId';
        }
    }

    /**
     * Gestiona el saldo de loyalty de un cliente (acumula o descuenta).
     * Equivalente legacy: `manageCustomerLoyalty($type, $amount, $id, $compId)`.
     *
     * Nota: $amount se mantiene numérico (floatval) — NO usar $db->Prepare() porque
     * qstr-quotea el número y rompe comparaciones/matemática en el branch 'earned'.
     */
    public static function manageLoyalty(mixed $type, mixed $amount, mixed $id, mixed $compId = false): void
    {
        global $db;

        $compId = iftn($compId, COMPANY_ID);
        $amount = floatval($amount);

        if ($type == 'used') {
            $db->Execute(
                "UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount - ?, updated_at = '" . TODAY . "' WHERE contactId = ?",
                [$amount, $id]
            );
        } elseif ($type == 'earned') {
            $contactAble = ncmExecute('SELECT contactLoyalty FROM contact WHERE contactId = ? LIMIT 1', [$id]);

            if (!empty($contactAble) && ($contactAble['contactLoyalty'] > 0)) {
                // loyaltyMin/loyaltyValue están DEMOTED a company.config JSONB —
                // SELECT * + _flattenJsonb (vía ncmExecute) los expone. §22.8.
                $loyaltyVal = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$compId]);
                $loyalMin   = floatval($loyaltyVal['loyaltyMin'] ?? 0);
                $loyalVal   = floatval($loyaltyVal['loyaltyValue'] ?? 0);

                if ($loyalMin > 0 && $amount >= $loyalMin) {
                    $mult   = divider($amount, $loyalMin, false, 'down');
                    $earned = $loyalVal * $mult;
                    $db->Execute(
                        "UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount + ?, updated_at = '" . TODAY . "' WHERE contactId = ?",
                        [$earned, $id]
                    );
                }
            }
        }

        updateLastTimeEdit($compId, 'customer');
    }

    /**
     * Gestiona el crédito interno de un cliente (descuenta o acumula).
     * Equivalente legacy: `manageCustomerStoreCredit($type, $amount, $id, $compId)`.
     *
     * Nota: updated_at con comillas SIMPLES (las dobles en PG son identificadores). §22.5.
     */
    public static function manageStoreCredit(mixed $type, mixed $amount, mixed $id, mixed $compId = false): void
    {
        global $db;

        $compId = iftn($compId, COMPANY_ID);
        $amount = floatval($amount);

        if ($type == 'used') {
            $db->Execute(
                "UPDATE contact SET contactStoreCredit = contactStoreCredit - ?, updated_at = '" . TODAY . "' WHERE contactId = ?",
                [$amount, $id]
            );
        } elseif ($type == 'earned') {
            // rama vacía — no-op, preservado verbatim
        }

        updateLastTimeEdit($compId, 'customer');
    }

    /**
     * Deduce un monto de una gift card (por código o timestamp).
     * Equivalente legacy: `manageGiftCard($amount, $id)`.
     */
    public static function manageGiftCard(mixed $amount, mixed $id): void
    {
        $amount = floatval($amount);

        if ($amount > 0) {
            $value = ncmExecute(
                'SELECT giftCardSoldValue FROM giftCardSold WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ? LIMIT 1',
                [$id, $id, COMPANY_ID]
            );

            if ($amount > $value['giftCardSoldValue']) {
                $amount = $value['giftCardSoldValue'];
            }

            $record = [
                'giftCardSoldValue'    => $value['giftCardSoldValue'] - $amount,
                'giftCardSoldLastUsed' => TODAY,
            ];

            ncmUpdate([
                'records' => $record,
                'table'   => 'giftCardSold',
                'where'   => '(giftCardSoldCode = ' . $id . ' OR timestamp = ' . $id . ') AND companyId = ' . COMPANY_ID . ' LIMIT 1',
            ]);
        }
    }

    /**
     * Lista todos los contactos de la empresa. Devuelve [a1, a2, a3] —
     * tres índices del mismo conjunto (por id, id, realId — quirk legacy).
     * Equivalente legacy: `getAllContacts($type, $where)`.
     */
    public static function getAllContacts(mixed $type = false, string $where = ''): array
    {
        global $compId;

        $compId       = iftn($compId, COMPANY_ID);
        $plan         = getAllPlans(PLAN_ID);
        $outletsCount = getOutletCount($compId);

        if ($type == 0) {
            $limit = ' LIMIT ' . $plan['max_users'] * $outletsCount;
        } elseif ($type == 1) {
            $limit = ' LIMIT ' . $plan['max_customers'] * $outletsCount;
        } else {
            $limit = ' LIMIT ' . $plan['max_suppliers'] * $outletsCount;
        }

        if ($type == 0 || $type > 0) {
            $typeand = ' AND type = ' . $type;
        } else {
            $typeand = '';
        }

        $result = ncmExecute(
            'SELECT * FROM contact WHERE companyId = ?' . $where . $typeand . $limit,
            [COMPANY_ID],
            false,
            true
        );

        $a1 = [];
        $a2 = [];
        $a3 = [];

        if ($result) {
            while (!$result->EOF) {
                $values = [
                    'name'     => $result->fields['contactName'],
                    'sname'    => $result->fields['contactSecondName'],
                    'address'  => $result->fields['contactAddress'],
                    'email'    => $result->fields['contactEmail'],
                    'ruc'      => $result->fields['contactTIN'],
                    'id'       => $result->fields['contactId'],
                    'rid'      => $result->fields['contactRealId'],
                    'uid'      => $result->fields['contactId'],
                    'phone'    => $result->fields['contactPhone'],
                    'note'     => $result->fields['contactNote'],
                    'city'     => $result->fields['contactCity'],
                    'date'     => $result->fields['contactDate'],
                    'type'     => $result->fields['type'],
                    'role'     => $result->fields['role'],
                    'main'     => $result->fields['main'],
                    'lockpass' => $result->fields['lockPass'],
                    'outlet'   => $result->fields['outletId'],
                ];

                $a1[$result->fields['contactId']]      = $values;
                $a2[$result->fields['contactId']]      = $values;
                $a3[$result->fields['contactRealId']]  = $values;

                $result->MoveNext();
            }
            $result->Close();
        }

        return [$a1, $a2, $a3];
    }

    /**
     * Busca en los 3 índices de contacto devueltos por getAllContacts y
     * retorna el campo solicitado para el primer match encontrado.
     * Equivalente legacy: `getTheContactField($id, $array, $field)`.
     */
    public static function getContactField(mixed $id, array $array, string $field = 'name'): mixed
    {
        if (validity($id)) {
            $ck  = 0;
            $out = '';
            while (1) {
                $out = array_key_exists($id, $array[$ck]) && $array[$ck][$id][$field];
                if (validity($out) || $ck == 2) {
                    break;
                }
                $ck++;
            }
            return $out;
        }

        return '';
    }

    /**
     * Datos completos de un cliente por ID. Alias de getContactData.
     * Equivalente legacy: `getCustomerData($id, $type)`.
     */
    public static function getData(mixed $id, mixed $type = false): mixed
    {
        return self::getContactData($id, $type);
    }

    /**
     * Dirección de entrega asociada a una transacción.
     * Equivalente legacy: `getCustomerTransactionAddress($transId, $encode)`.
     */
    public static function getTransactionAddress(mixed $transId, bool $encode = false): mixed
    {
        $out    = false;
        $trAddr = ncmExecute('SELECT customerAddressId as id FROM toAddress WHERE transactionId = ? LIMIT 1', [$transId]);

        if ($trAddr) {
            $address = ncmExecute(
                'SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',
                [$trAddr['id'], COMPANY_ID]
            );
            if ($address) {
                $out = [
                    'id'       => $encode ? enc($address['customerAddressId']) : $address['customerAddressId'],
                    'name'     => $address['customerAddressName'],
                    'address'  => $address['customerAddressText'],
                    'location' => $address['customerAddressLocation'],
                    'city'     => $address['customerAddressCity'],
                    'lat'      => $address['customerAddressLat'],
                    'lng'      => $address['customerAddressLng'],
                ];
            }
        }

        return $out;
    }

    /**
     * Datos completos de un contacto incluyendo dirección por defecto, edad y país.
     * Equivalente legacy: `getContactData($id, $type, $cache)`.
     *
     * GDPR: devuelve PII (nombre, teléfono, email, dirección). El filtro
     * companyId = ? se preserva verbatim — aislamiento de tenant mantenido.
     *
     * Quirk §22.5: UUID concatenado entre comillas simples en WHERE — PG requiere
     * esto para evitar syntax error con UUIDs sin comillas.
     */
    public static function getContactData(mixed $id, mixed $type = false, mixed $cache = false): mixed
    {
        $countries = [];

        // PG: UUID entre comillas en SQL concat (§22.5).
        $where   = "contactId = '" . $id . "'";
        $genders = ['Masculino', 'Femenino', 'Otro'];

        $result = ncmExecute(
            'SELECT * FROM contact WHERE ' . $where . ' AND companyId = ? LIMIT 1',
            [COMPANY_ID],
            $cache
        );

        if ($result) {
            if (validity($result['contactName']) || validity($result['contactSecondName'])) {
                $name    = toUTF8($result['contactName']);
                $sname   = toUTF8($result['contactSecondName']);
                $note    = toUTF8($result['contactNote']);
                $address  = false;
                $location = false;
                $city     = false;
                $lat      = 0;
                $lng      = 0;

                $addressRow = ncmExecute(
                    'SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? AND customerAddressDefault = true LIMIT 1',
                    [$id, COMPANY_ID]
                );

                if ($addressRow) {
                    $address  = toUTF8($result['customerAddressText']  ?? '');
                    $location = toUTF8($result['customerAddressLocation'] ?? '');
                    $city     = toUTF8($result['customerAddressCity']  ?? '');
                    $lat      = $result['customerAddressLat'] ?? '';
                    $lng      = $result['customerAddressLng'] ?? '';
                }

                $age = '';
                if ($result['contactBirthDay']) {
                    $age = date_diff(date_create($result['contactBirthDay']), date_create('today'))->y;
                }

                if ($result['contactCountry']) {
                    include_once('libraries/countries.php');
                }

                return [
                    'id'          => $result['contactId'],
                    'uid'         => $result['contactId'],
                    'name'        => $name,
                    'secondName'  => $sname,
                    'ruc'         => $result['contactTIN'],
                    'phone'       => $result['contactPhone'],
                    'phone2'      => $result['contactPhone2'],
                    'addressId'   => enc($result['customerAddressId'] ?? ''),
                    'address'     => $address,
                    'location'    => $location,
                    'city'        => $city,
                    'lat'         => $lat,
                    'lng'         => $lng,
                    'email'       => $result['contactEmail'],
                    'note'        => $note,
                    'rol'         => $result['role'],
                    'bDay'        => $result['contactBirthDay'],
                    'age'         => $age,
                    'country'     => $result['contactCountry'],
                    'countryName' => $countries[$result['contactCountry'] ?? '']['name'] ?? '',
                    'gender'      => $genders[$result['contactGender'] ?? ''] ?? '',
                    'ci'          => $result['contactCI'],
                    'since'       => $result['contactDate'],
                ];
            }

            return [
                'id'         => $result['contactId'],
                'uid'        => $result['contactId'],
                'name'       => 'Sin Nombre',
                'secondName' => 'Sin Nombre',
                'ruc'        => '',
                'phone'      => '',
                'address'    => '',
                'lat'        => '',
                'lng'        => '',
                'city'       => '',
                'country'    => '',
                'location'   => '',
                'email'      => '',
                'note'       => '',
                'rol'        => $result['role'],
                'bDay'       => '',
                'age'        => '',
                'gender'     => '',
                'ci'         => '',
                'since'      => '',
            ];
        }

        return false;
    }

    /**
     * Nombre "mostrable" de un cliente: prefiere secondName salvo que sea inválido.
     * Función pura (sin DB). Con $part='first'/'last' devuelve solo esa parte.
     * Equivalente legacy: `getCustomerName($data, $part)`.
     */
    public static function getName(mixed $data, mixed $part = false): mixed
    {
        if (!is_array($data)) {
            return '';
        }
        $name = $data['secondName'];
        if (!validity($name) || $name == 'Sin Nombre' || counts($name) < 2) {
            $name = $data['name'];
        }

        if ($part) {
            $part = ($part == 'first') ? 0 : 1;
            $name = explodes(' ', $name, $part);
        }

        return $name;
    }

    /**
     * Línea de crédito disponible = creditLine - deuda_pendiente.
     * Equivalente legacy: `getContactCreditLine($uid, $creditLine)`.
     */
    public static function getContactCreditLine(mixed $uid, mixed $creditLine): mixed
    {
        if (empty($uid)) {
            return 0;
        }

        $totalC = ncmExecute(
            'SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, STRING_AGG(transactionId::text, \',\') as ids
             FROM transaction
             WHERE customerId = ?
             AND transactionType = 3
             AND transactionComplete = 0',
            [$uid],
            60
        );

        if (empty($totalC) || empty($totalC['ids'])) {
            return $creditLine;
        }

        $totalRetrns = ncmExecute(
            ' SELECT SUM(transactionTotal) as total
              FROM transaction
              WHERE customerId = ?
              AND transactionType = 6
              AND transactionParentId IN(' . $totalC['ids'] . ')',
            [$uid]
        );

        $payedC = ncmExecute(
            ' SELECT SUM(transactionTotal) as payed
              FROM transaction
              WHERE transactionType = 5
              AND transactionParentId IN(' . $totalC['ids'] . ')
              AND customerId = ?',
            [$uid]
        );

        $totalComprado = $totalC['total'] - $totalC['discount'];
        $totalPagado   = $payedC['payed'] + abs($totalRetrns['total'] ?? 0);

        if ($totalPagado >= $totalComprado) {
            $totalDeuda = 0;
        } else {
            $totalDeuda = $totalComprado - $totalPagado;
        }

        return $creditLine - $totalDeuda;
    }
}
