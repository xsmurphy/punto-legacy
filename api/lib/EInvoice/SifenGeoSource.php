<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Fuente del catálogo geográfico fiscal: SIFEN, desde un SEED versionado.
 *
 * ── Por qué SIFEN y no el proveedor intermediario ────────────────────────
 *
 * Punto emite por FE-PY (motor propio, `einvoice_account.provider = 'fepy'`),
 * y FE-PY VALIDA los códigos geográficos contra el catálogo de la SET antes
 * de armar el XML. La fuente de verdad de un código fiscal es quien lo
 * valida: un código que viene de otro catálogo y que el validador no
 * reconoce es un documento RECHAZADO. Los números no coinciden — el catálogo
 * de Factomate traía 6.419 ciudades, el de SIFEN tiene 6.766 — así que no es
 * una diferencia teórica.
 *
 * Corolario, y es la razón por la que `FactomateGeoSource` se BORRÓ en vez de
 * quedar como alternativa: dos fuentes para un mismo código fiscal que pueden
 * discrepar son exactamente el bug que este cambio viene a cerrar.
 *
 * ── Por qué un archivo y no una llamada ──────────────────────────────────
 *
 * El catálogo cambia cuando la SET crea o renombra una ciudad: años, no
 * sprints. Un seed commiteado lo vuelve dato VERSIONADO — el diff de un
 * cambio de catálogo se revisa fila por fila en un PR, en vez de aparecer un
 * domingo de madrugada sin que nadie lo mire — y hace que cargarlo sea
 * instantáneo y no dependa de que ninguna red esté arriba. Se regenera a mano
 * con `scripts/extract-sifen-geo.mjs`, que documenta de qué archivo y de qué
 * versión de FE-PY salió.
 *
 * ── Lo único paraguayo del sistema ───────────────────────────────────────
 *
 * `COUNTRY_CODE = 'PY'` vive acá y en ningún otro lado. No contradice la regla
 * de no hardcodear Paraguay: no es un default aplicado al tenant, es la
 * identidad de ESTE catálogo, que es el de la autoridad tributaria paraguaya
 * por definición. El sync, las tablas y el endpoint siguen siendo agnósticos.
 */
final class SifenGeoSource implements GeoCatalogSource
{
    /** El catálogo de la SET es paraguayo por definición. Ver el docblock. */
    private const COUNTRY_CODE = 'PY';

    private const SOURCE_KEY = 'sifen';

    private string $path;

    /** @var array{departamentos:array<int,mixed>,distritos:array<int,mixed>,ciudades:array<int,mixed>}|null */
    private ?array $seed = null;

    public function __construct(?string $path = null)
    {
        // `api/lib/EInvoice/` → `api/database/seeds/`. En el container el
        // código vive en /var/www/api, así que la ruta relativa resuelve
        // igual que en el checkout.
        $this->path = $path ?? dirname(__DIR__, 2) . '/database/seeds/sifen-geo.json';
    }

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }

    /** Identidad del seed cargado — sale en el log del job para poder auditar qué versión se cargó. */
    public function origin(): string
    {
        $seed = $this->seed();
        return isset($seed['_fuente']) && is_string($seed['_fuente']) ? $seed['_fuente'] : '(sin _fuente)';
    }

    public function departments(): array
    {
        return $this->rows('departamentos', null);
    }

    public function districts(): array
    {
        return $this->rows('distritos', 'departamento');
    }

    public function cities(): array
    {
        return $this->rows('ciudades', 'distrito');
    }

    /**
     * Traduce un nivel del seed al shape del dominio.
     *
     * El seed usa las claves del catálogo de la SET (`codigo`, `descripcion`,
     * y el padre nombrado por su nivel); la traducción vive acá, que es el
     * único punto del sistema que tiene por qué conocer ese vocabulario.
     *
     * @return array<int,array{code:int,name:string,departmentCode?:int,districtCode?:int}>
     */
    private function rows(string $key, ?string $parentKey): array
    {
        $raw = $this->seed()[$key] ?? null;
        if (!is_array($raw)) {
            throw new \RuntimeException(
                "El seed del catálogo geográfico no trae \"$key\" ({$this->path}). " .
                'Regeneralo con: node scripts/extract-sifen-geo.mjs'
            );
        }

        $parentField = $parentKey === 'departamento' ? 'departmentCode' : 'districtCode';

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $row['codigo'] ?? null;
            $name = $row['descripcion'] ?? null;
            if (!is_numeric($code) || !is_string($name)) {
                // Una fila ilegible no se saltea en silencio: el seed es un
                // artefacto controlado, así que esto solo puede ser un
                // extractor roto, y cargar medio catálogo es peor que no
                // cargar ninguno.
                throw new \RuntimeException(
                    "Fila inválida en \"$key\" del seed geográfico: " . json_encode($row)
                );
            }

            $item = ['code' => (int) $code, 'name' => trim($name)];
            if ($parentKey !== null) {
                $parent = $row[$parentKey] ?? null;
                if (!is_numeric($parent)) {
                    throw new \RuntimeException(
                        "Fila de \"$key\" sin \"$parentKey\" en el seed geográfico: " . json_encode($row)
                    );
                }
                $item[$parentField] = (int) $parent;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function seed(): array
    {
        if ($this->seed !== null) {
            return $this->seed;
        }

        if (!is_file($this->path)) {
            throw new \RuntimeException(
                "No encuentro el seed del catálogo geográfico en {$this->path}. " .
                'Es un archivo versionado del repo: si falta, el deploy quedó incompleto.'
            );
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("El seed del catálogo geográfico no es JSON válido ({$this->path}).");
        }

        /** @var array{departamentos:array<int,mixed>,distritos:array<int,mixed>,ciudades:array<int,mixed>} $decoded */
        return $this->seed = $decoded;
    }
}
