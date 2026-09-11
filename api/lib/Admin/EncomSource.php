<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * De dónde salen los datos del cliente que se está migrando (context/77).
 *
 * Existe como interfaz por UNA razón concreta: el arnés
 * (`api/tests/run_encom_migration_test.sh`) tiene que poder verificar el
 * IMPORTADOR —idempotencia, mapeo, composición de combos y recetas,
 * continuación de numeración, rechazo del punto de expedición duplicado— sin
 * depender de que el panel legacy esté arriba, ni de las credenciales de un
 * cliente real, ni de la red.
 *
 * El importador (`EncomImportService`) habla SOLO con esta interfaz. La
 * implementación HTTP (`EncomClient`) y la de fixtures del arnés son
 * intercambiables sin que el importador se entere, así que lo que el test
 * ejercita es exactamente el mismo código que corre en producción — no una
 * copia con el shape "parecido".
 *
 * ── La fuente es el bootstrap del POS, no las pantallas del panel ───────────
 * Desde 2026-09-11 todos estos métodos salen de `POST /fetchs?load=<X>`, el
 * bootstrap JSON que el POS legacy consume. Reemplazó al scraping de HTML y
 * CSV del panel, que era lo único que se conocía cuando se escribió la F1.
 * El cambio no es cosmético: `/fetchs` trae cosas que las pantallas NO
 * exponían —la composición de combos y recetas, los usuarios con su PIN y su
 * rol, los medios de pago, el último correlativo REAL por tipo de documento—
 * y las trae ya tipadas, sin depender del orden de las columnas de una tabla.
 *
 * Cada método devuelve una lista de filas ya desenvueltas. Normalizar los
 * nombres de campo al modelo de Punto NO es tarea de esta capa: el mapeo
 * legacy → Punto vive en el importador, en un solo lugar.
 */
interface EncomSource
{
    /** Configuración de la empresa (nombre, RUC, moneda, país, decimales...). */
    public function settings(): array;

    /** @return array<int,array> Sucursales. */
    public function outlets(): array;

    /**
     * @return array<int,array> Cajas, con timbrado / punto de expedición y el
     *         ÚLTIMO número emitido por tipo de documento (`docsNum`).
     */
    public function registers(): array;

    /**
     * Artículos del catálogo, con su composición cruda en `compound` cuando la
     * tienen (combos y recetas de producción). El importador la resuelve en una
     * segunda pasada — ver `EncomImportService::compose()`.
     *
     * @return array<int,array>
     */
    public function items(): array;

    /** @return array<int,array> Categorías (derivadas de los artículos). */
    public function categories(): array;

    /** @return array<int,array> Marcas (derivadas de los artículos). */
    public function brands(): array;

    /** @return array<int,array> Etiquetas. */
    public function tags(): array;

    /** @return array<int,array> Clientes. */
    public function customers(): array;

    /**
     * @return array<int,array> Usuarios del comercio, con su PIN de caja y el
     *         nombre del rol que tenían en el legacy.
     */
    public function users(): array;

    /** @return array<int,array> Medios de pago configurados por el comercio. */
    public function paymentMethods(): array;
}
