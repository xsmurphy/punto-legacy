<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

use RuntimeException;

/**
 * Choque de unicidad al guardar un contacto: el número de documento PERSONAL
 * o el teléfono ya los tiene otro contacto del mismo tenant y del mismo rol.
 *
 * Por qué es una excepción propia y no un `RuntimeException` pelado: el error
 * tiene que llegar al cajero diciendo CON QUIÉN choca, no un "ya existe" seco.
 * Un mensaje sin el nombre del cliente obliga a salir del alta, buscar a mano y
 * volver — que es exactamente el trabajo que esta regla venía a evitar. Los
 * datos del contacto en conflicto viajan estructurados para que la UI pueda
 * ofrecer "usar este cliente" con un toque (`api/v1/contacts.php` los publica
 * en `error.details`).
 *
 * OJO — el identificador FISCAL (`contactTIN`) NO entra acá y es deliberado:
 * varias personas pueden facturar a nombre de la misma empresa, así que dos
 * contactos con el mismo RUC/CUIT son un caso legítimo (decisión del owner,
 * 2026-08-31). Lo único que no se puede repetir es el documento personal y el
 * teléfono.
 */
final class DuplicateContactException extends RuntimeException
{
    /** Campo del shape público que choca: 'ci' o 'phone'. */
    public string $field;

    /** contactId del registro que ya tiene ese número. */
    public string $contactId;

    /** Nombre del registro en conflicto — lo que el cajero necesita ver. */
    public string $contactName;

    public function __construct(string $message, string $field, string $contactId, string $contactName)
    {
        parent::__construct($message);
        $this->field       = $field;
        $this->contactId   = $contactId;
        $this->contactName = $contactName;
    }

    /** Payload para `apiError(..., 409, $details)`. */
    public function details(): array
    {
        return [
            'field'       => $this->field,
            'contactId'   => $this->contactId,
            'contactName' => $this->contactName,
        ];
    }
}
