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

    /**
     * COSTO de los artículos, que es el único dato del catálogo que `/fetchs`
     * no manda (el POS no lo necesita para vender) y que sale de otra
     * superficie: la tabla del panel. Ver `EncomClient::itemCosts()`.
     *
     * Se devuelve aparte de `items()` —y no mezclado adentro— justamente para
     * que se vea que viene de otro lado: el que lo lea tiene que saber que
     * puede faltar entero sin que el catálogo falle.
     *
     * @return array<int,array{sku:string,name:string,cost:float|null}>
     */
    public function itemCosts(): array;

    /**
     * SALDO de cada artículo EN UNA SUCURSAL del legacy.
     *
     * Va aparte de `items()` porque el catálogo es del comercio y el saldo es
     * de la SUCURSAL: `/fetchs` contesta el bootstrap de UNA caja, así que el
     * mismo `load=items` devuelve `inventory[].count` distinto según el
     * `outletId` que viaje en el cuerpo. Pedirlo por sucursal es la única forma
     * de que el saldo entre donde corresponde — un saldo suelto, sin sucursal,
     * no es un movimiento de ledger válido (context/52).
     *
     * @return array<int,array{ID:string,count:float,hasCount:bool,trackStock:mixed}>
     */
    public function itemStock(string $outletLegacyId): array;

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

    // ═══════════════════════════════════════════════════════════════════
    // HISTÓRICO (F2) — lo único que NO sale de `/fetchs`
    // ═══════════════════════════════════════════════════════════════════
    //
    // `/fetchs` es el bootstrap de una caja, no un reporte: no expone el
    // pasado por ningún `load`. El histórico sale de las pantallas de reporte
    // del panel, con la misma sesión.
    //
    // Estos métodos devuelven las filas con las columnas YA RESUELTAS POR
    // ENCABEZADO —o sea, claves con el significado del legacy, no posiciones—
    // porque "qué columna es el total" es una pregunta sobre la FUENTE. El
    // mapeo de ese significado al modelo de Punto sigue siendo del importador.

    /**
     * Cabeceras de las ventas de un rango.
     *
     * @param string $from 'YYYY-MM-DD HH:MM:SS'
     * @param string $to   'YYYY-MM-DD HH:MM:SS'
     * @return array<int,array{ID:string,docNumber:string,authNo:string,date:string,
     *         dueDate:string,customer:string,customerTin:string,user:string,
     *         outlet:string,register:string,paymentMethod:string,note:string,
     *         docType:string,type:string,discount:?float,tax:?float,total:?float}>
     */
    public function salesHistory(string $from, string $to): array;

    /**
     * Líneas de UNA venta. Es una request POR VENTA (el legacy no tiene un
     * endpoint de líneas por rango, a diferencia de las compras), así que el
     * importador la llama paceada y por mes.
     *
     * `legacyItemId` viene vacío cuando el form no lo expone (lo normal en el
     * deploy relevado): ahí el artículo se resuelve por nombre contra el
     * catálogo ya migrado, que es el mismo criterio con el que el migrador
     * cruza los costos.
     *
     * @return array<int,array{ID:string,legacyItemId:string,itemName:string,
     *         qty:?float,price:?float,tax:?float,total:?float,user:string}>
     */
    public function saleLines(string $legacyId): array;

    /**
     * Cabeceras de las compras de un rango.
     *
     * @return array<int,array{ID:string,docNumber:string,authNo:string,date:string,
     *         dueDate:string,supplier:string,outlet:string,user:string,
     *         type:string,tax:?float,total:?float}>
     */
    public function purchasesHistory(string $from, string $to): array;

    /**
     * Líneas de TODAS las compras de un rango, en UNA request.
     *
     * Se juntan con su cabecera por número de documento — el listado de
     * detalle del legacy no trae el id de la compra, solo el `#Documento`.
     *
     * @return array<int,array{docNumber:string,supplier:string,outlet:string,
     *         itemName:string,qty:?float,price:?float,tax:?float,total:?float}>
     */
    public function purchaseLines(string $from, string $to): array;

    /**
     * Movimientos de caja (extracciones e ingresos) de un rango.
     *
     * @return array<int,array{ID:string,date:string,outlet:string,register:string,
     *         user:string,note:string,type:string,total:?float}>
     */
    public function expensesHistory(string $from, string $to): array;
}
