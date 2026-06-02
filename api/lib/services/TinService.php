<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

/**
 * TinService — búsqueda de RUC paraguayo en Marangatu (SET).
 *
 * Reemplaza el path legacy /app/load.php?load=tin → panel/API/get_tin.php (que a su vez
 * llamaba a Marangatu). El nuevo Service llama directo a Marangatu desde /api, sin
 * proxy intermedio al panel.
 *
 * Decisión 2026-06-02: solo Marangatu como fuente. Removido el fallback a la BD legacy
 * `ruc_py` (`incomepo_rucpy` está rota, registrada como deuda) y el fallback a CI vía
 * eas.suace.gov.py (la búsqueda por cédula queda fuera de scope).
 *
 * Marangatu es API pública sin auth; solo PY soportado por ahora.
 */
final class TinService
{
    private const MARANGATU_URL = 'https://marangatu.set.gov.py/eset-restful/contribuyentes/consultar?codigoEstablecimiento=1&ruc=';

    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Busca un RUC en Marangatu. Acepta el RUC con o sin dígito verificador
     * (ej. `80012345` o `80012345-7`); el DV se descarta antes de la consulta.
     *
     * @param string $id       RUC a buscar (con o sin DV).
     * @param string $country  ISO code del país. Solo 'PY' soportado.
     * @return array|null      Shape { id, tin, name, fullName, address, phone } o null si no se encontró.
     * @throws \InvalidArgumentException si country no es 'PY' o id vacío.
     */
    public function lookup(string $id, string $country): ?array
    {
        if ($country !== 'PY') {
            throw new \InvalidArgumentException('Solo PY soportado');
        }
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('id obligatorio');
        }

        // Si viene con DV (ej. "80012345-7"), quedarse con el RUC base.
        if (str_contains($id, '-')) {
            $id = explode('-', $id, 2)[0];
        }

        $res = curlContents(self::MARANGATU_URL . urlencode($id));
        if ($res === false || $res === '') {
            return null;
        }

        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['nombre'])) {
            return null;
        }

        $dv  = $data['dv'] ?? '';
        $ruc = (string) ($data['ruc'] ?? $id);

        return [
            'id'       => $ruc,
            'tin'      => $dv !== '' ? "$ruc-$dv" : $ruc,
            'name'     => (string) ($data['nombre']         ?? ''),
            'fullName' => (string) ($data['nombreFantasia'] ?? ''),
            'address'  => (string) ($data['direccion']      ?? ''),
            'phone'    => (string) ($data['telefono']       ?? ''),
        ];
    }
}
