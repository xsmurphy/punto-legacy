<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * De dónde salen los datos del cliente que se está migrando (context/77).
 *
 * Existe como interfaz por UNA razón concreta: el arnés
 * (`api/tests/run_encom_migration_test.sh`) tiene que poder verificar el
 * IMPORTADOR —idempotencia, mapeo, continuación de numeración, rechazo del
 * punto de expedición duplicado— sin depender de que el panel legacy esté
 * arriba, ni de las credenciales de un cliente real, ni de la red.
 *
 * El importador (`EncomImportService`) habla SOLO con esta interfaz. La
 * implementación HTTP (`EncomClient`) y la de fixtures del arnés son
 * intercambiables sin que el importador se entere, así que lo que el test
 * ejercita es exactamente el mismo código que corre en producción — no una
 * copia con el shape "parecido".
 *
 * Cada método devuelve una lista de filas ya DESENVUELTAS del envelope
 * `{ok, data}` del legacy. Normalizar los nombres de campo NO es tarea de
 * esta capa: el mapeo legacy → Punto vive en el importador, en un solo lugar.
 */
interface EncomSource
{
    /** Configuración de la empresa (nombre, RUC, ciudad, moneda, decimales...). */
    public function settings(): array;

    /** @return array<int,array> Sucursales. */
    public function outlets(): array;

    /**
     * @return array<int,array> Cajas, con timbrado / punto de expedición /
     *                          último número emitido si el legacy los expone.
     */
    public function registers(): array;

    /** @return array<int,array> Artículos del catálogo. */
    public function items(): array;

    /** @return array<int,array> Categorías. */
    public function categories(): array;

    /** @return array<int,array> Marcas. */
    public function brands(): array;

    /** @return array<int,array> Etiquetas. */
    public function tags(): array;

    /** @return array<int,array> Clientes (type=1 en el legacy). */
    public function customers(): array;

    /** @return array<int,array> Cuentas / medios de pago. */
    public function banks(): array;
}
