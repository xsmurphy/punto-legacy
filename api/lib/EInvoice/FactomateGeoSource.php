<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Fuente real: la API de Factomate.
 *
 * QUÉ CREDENCIAL USA, y por qué en ese orden. El catálogo es dato de
 * PLATAFORMA, así que la credencial natural es la ADMIN de Punto
 * (`FACTOMATE_ADMIN_*`), que no pertenece a ningún comercio. Pero los
 * endpoints de catálogo se verificaron con el bearer de un TENANT, no con el
 * admin: si el admin no está configurado —o el proveedor le niega el
 * catálogo— se cae al bearer de una cuenta de emisor activa. Cualquiera de
 * los dos sirve: lo que se lee es el mismo catálogo público del proveedor,
 * idéntico para todos, y no se escribe nada del lado de ellos.
 *
 * La cuenta de tenant que se elige es la PRIMERA activa por antigüedad, no
 * una en particular: no hay dato del comercio en juego, es una llamada de
 * lectura a un catálogo compartido. El `ORDER BY created_at` está para que la
 * elección sea DETERMINÍSTICA — sin él, dos corridas podrían salir por
 * cuentas distintas sin motivo. Y si esa cuenta deja de estar operativa, la
 * corrida siguiente sale por la que le sigue, en silencio y a propósito: el
 * job no puede caerse porque un comercio cualquiera rotó su credencial. Lo
 * único que corta el sync es que NINGUNA sirva, y ahí el error es explícito.
 */
final class FactomateGeoSource implements GeoCatalogSource
{
    /**
     * Tope de filas por llamada. El catálogo tenía ~6.400 ciudades al
     * 2026-09-08; 20.000 deja margen de años sin paginar. Si algún día no
     * alcanza, `GeoCatalogSync` corta con el conteo a la vista en vez de
     * guardar un catálogo a medias.
     */
    private const PAGE_SIZE = 20000;

    private FactomateProvider $provider;
    private FactomateSession $session;

    /** [$login, $bearer, $environment] resuelto una sola vez por instancia. */
    private ?array $credential = null;

    public function __construct(?FactomateProvider $provider = null, ?FactomateSession $session = null)
    {
        $this->provider = $provider ?? new FactomateProvider();
        $this->session  = $session ?? new FactomateSession($this->provider);
    }

    public function departments(): array
    {
        [$login, $bearer, $environment] = $this->credential();
        $raw = $this->provider->departments($environment, $login, $bearer, self::PAGE_SIZE);
        return self::items($raw, 'Department');
    }

    public function cities(): array
    {
        [$login, $bearer, $environment] = $this->credential();

        $raw   = $this->provider->cities($environment, $login, $bearer, self::PAGE_SIZE);
        $items = self::items($raw, 'City');
        $total = (int) ($raw['TotalSize'] ?? $raw['totalSize'] ?? count($items));

        // Segundo (y último) intento con el tamaño exacto que la API declara.
        // No se inventa un `?page=`: el único parámetro de paginación
        // verificado es `size`, y un parámetro que la API ignora en silencio
        // dejaría el catálogo truncado sin ningún síntoma.
        if ($total > count($items)) {
            $raw   = $this->provider->cities($environment, $login, $bearer, $total + 100);
            $items = self::items($raw, 'City');
            $total = (int) ($raw['TotalSize'] ?? $raw['totalSize'] ?? count($items));
        }

        if ($total > count($items)) {
            throw new \RuntimeException(
                'El catálogo de ciudades no entró en una sola llamada (declara ' . $total .
                ', devolvió ' . count($items) . '). El sync NO guarda un catálogo a medias: ' .
                'hay que confirmar con el proveedor cómo se pagina /api/City/get.'
            );
        }

        return $items;
    }

    /**
     * `{ Items: [...] }` es el sobre de estos endpoints. Un cuerpo sin
     * `Items` es un contrato roto, no un catálogo vacío: se corta nombrando
     * las claves que sí vinieron, para que el error sea diagnosticable desde
     * el log del job.
     *
     * @param array<string,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private static function items(array $raw, string $what): array
    {
        $items = $raw['Items'] ?? $raw['items'] ?? null;
        if (!is_array($items)) {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException(
                "La respuesta de $what no trae Items (claves: $keys)."
            );
        }
        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @return array{0:string,1:string,2:string} [$login, $bearer, $environment]
     */
    private function credential(): array
    {
        if ($this->credential !== null) {
            return $this->credential;
        }

        // El entorno sale de las cuentas que existen, no de una constante:
        // en un despliegue de prueba todas las cuentas son 'test'.
        $environment = (string) (ncmExecute(
            "SELECT environment FROM einvoice_account
              WHERE provider = 'automate' AND status = 'ok'
              ORDER BY created_at ASC LIMIT 1"
        )['environment'] ?? 'test');

        $adminError = null;
        if (FactomateSession::hasAdminCredentials($environment)) {
            try {
                $bearer = $this->session->getAdminBearer($environment);
                return $this->credential = [
                    $this->session->getAdminLogin($environment),
                    $bearer,
                    $environment,
                ];
            } catch (\RuntimeException $e) {
                $adminError = $e->getMessage();
                error_log('[GeoCatalog] la credencial admin no sirvió para el catálogo: ' . $adminError);
            }
        }

        // Fallback: el bearer de una cuenta de emisor activa. Los endpoints
        // de catálogo se verificaron con esta credencial.
        $row = ncmExecute(
            "SELECT companyid FROM einvoice_account
              WHERE provider = 'automate' AND status = 'ok'
              ORDER BY created_at ASC LIMIT 1"
        );
        if (!$row) {
            throw new \RuntimeException(
                'No hay con qué autenticarse contra el proveedor para bajar el catálogo geográfico: ' .
                'la credencial admin ' . ($adminError !== null ? 'falló (' . $adminError . ')' : 'no está configurada') .
                ' y ninguna cuenta de facturación electrónica está operativa.'
            );
        }

        $companyId = (string) $row['companyid'];
        [$login, $env] = $this->session->identity($companyId);

        return $this->credential = [$login, $this->session->getBearer($companyId), $env];
    }
}
