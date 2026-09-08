<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * De dónde salen las filas del catálogo geográfico fiscal (mig 207).
 *
 * ── Por qué el shape es NEUTRO y no el de un proveedor ───────────────────
 *
 * Hasta la mig 207 esta interfaz devolvía el JSON CRUDO de Factomate —
 * `{Id, Identifier, Name, CountryCode, Deleted}` y el distrito ANIDADO bajo
 * una clave mal escrita (`Disctrict`)—, y el sync existía en buena medida
 * para traducir eso. Punto ya no factura por Factomate: el motor es FE-PY
 * (`einvoice_account.provider = 'fepy'`), y la fuente del catálogo es ahora
 * el catálogo de SIFEN que FE-PY usa para VALIDAR los códigos antes de armar
 * el XML. Mantener el shape del intermediario —con su typo incluido— para una
 * fuente que ya no existe sería arrastrar la forma de un proveedor muerto
 * dentro del contrato.
 *
 * Así que el contrato es el del DOMINIO, no el de nadie: tres niveles planos,
 * cada uno con su código fiscal, su nombre y el código de su padre. Una fuente
 * nueva adapta lo suyo a esto; el sync no vuelve a saber de proveedores.
 *
 * ── El país lo declara la FUENTE ─────────────────────────────────────────
 *
 * `countryCode()` y no una columna por fila: un catálogo geográfico pertenece
 * entero a una autoridad tributaria. Antes el país viajaba fila por fila
 * porque el proveedor lo mandaba así y una fila podía venir sin él (había un
 * contador de descartes para eso). Con la fuente declarándolo, esa clase de
 * agujero deja de existir: o la fuente sabe de qué país es su catálogo, o no
 * es una fuente. Nada queda hardcodeado en el sync — `SifenGeoSource` dice
 * 'PY' porque el catálogo de la SET ES paraguayo; otra fuente dirá lo suyo y
 * convivirán en las mismas tablas (la clave única de los tres niveles es
 * `(countrycode, code)`).
 */
interface GeoCatalogSource
{
    /**
     * Clave corta de la fuente. Se guarda en `geo_*.source`, para que una
     * fila diga siempre de qué catálogo salió su código: dos catálogos que
     * discrepan en un código son un documento fiscal rechazado, y sin esta
     * columna la discrepancia sería invisible.
     */
    public function sourceKey(): string;

    /** País del catálogo, ISO-3166-1 alpha-2 (ej. 'PY'). */
    public function countryCode(): string;

    /**
     * Nivel 1.
     *
     * @return array<int,array{code:int,name:string}>
     */
    public function departments(): array;

    /**
     * Nivel 2. `departmentCode` referencia un `code` de `departments()`.
     *
     * @return array<int,array{code:int,name:string,departmentCode:int}>
     */
    public function districts(): array;

    /**
     * Nivel 3. `districtCode` referencia un `code` de `districts()`; el
     * departamento NO se declara acá — lo deriva el sync del distrito, que es
     * la jerarquía autoritativa (ver la FK de la mig 207). Pedirlo dos veces
     * abriría la puerta a que una ciudad declare un departamento distinto del
     * de su propio distrito.
     *
     * @return array<int,array{code:int,name:string,districtCode:int}>
     */
    public function cities(): array;
}
