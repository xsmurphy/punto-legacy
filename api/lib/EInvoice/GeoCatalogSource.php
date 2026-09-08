<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * De dónde salen las filas crudas del catálogo geográfico (mig 207).
 *
 * Existe para que el SYNC no dependa de la red. `GeoCatalogSync` transforma y
 * hace upsert; esta interfaz trae las filas. Así el arnés le pasa una fuente
 * en memoria con el shape REAL de la API y verifica idempotencia y jerarquía
 * sin tocar el ambiente dev del proveedor — que es inestable y no puede ser
 * la condición para que un test corra.
 *
 * El shape que devuelven los dos métodos es el CRUDO del proveedor, sin
 * normalizar: quien normaliza es el sync, en un solo lugar.
 */
interface GeoCatalogSource
{
    /**
     * Departamentos.
     *
     * Shape de cada fila (verificado contra la API real 2026-09-08):
     *   { Id, Identifier, Name, CountryCode, Deleted }
     *
     * @return array<int,array<string,mixed>>
     */
    public function departments(): array;

    /**
     * Ciudades, con el distrito y el departamento ANIDADOS.
     *
     * Shape de cada fila (verificado contra la API real 2026-09-08):
     *   { Id, Identifier, Name, DistrictCode, DistrictId,
     *     Disctrict: { Id, Identifier, Name, DepartmentCode, DepartmentId,
     *                  Department: { Id, Identifier, Name, CountryCode, Deleted } },
     *     Deleted }
     *
     * `Disctrict` está MAL ESCRITO en la API del proveedor. Se respeta tal
     * cual (ver `FactomateProvider::cities()`).
     *
     * @return array<int,array<string,mixed>>
     */
    public function cities(): array;
}
