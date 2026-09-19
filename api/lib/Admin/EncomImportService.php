<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

require_once __DIR__ . '/EncomSource.php';
require_once __DIR__ . '/EncomMigrationException.php';
require_once __DIR__ . '/EncomMigrationService.php';

/**
 * Importa a Punto los datos exportados del legacy (context/77).
 *
 * ── D4: por los servicios reales, nunca INSERT directo ──────────────────
 * Cada entidad se crea con el MISMO servicio que usa el panel
 * (`ItemService`, `ItemCompoundService`, `ContactService`, `CategoryService`,
 * `OutletsService`, `RegisterAdminService`, `UsersService`,
 * `PaymentMethodService`). La única tabla que este importador escribe a mano es
 * `migration_map`, que es suya.
 *
 * No es purismo: esos servicios son los que aplican los invariantes. Saltearlos
 * con un INSERT es exactamente cómo entrarían dos cajas con el mismo punto de
 * expedición, un contacto con teléfono duplicado, un ítem sin fila en
 * `item_outlet` o una receta con un ciclo.
 *
 * ── Idempotencia ────────────────────────────────────────────────────────
 * Antes de crear cualquier cosa se pregunta a `migration_map` si ese id del
 * legacy ya tiene un id de Punto para esta empresa. Si lo tiene, se saltea.
 * Correr el job dos veces da los mismos conteos, con todo en `skipped`. La
 * COMPOSICIÓN de un combo tiene su propio dominio en el mapa por un motivo
 * concreto: `ItemCompoundService::add()` SUMA la cantidad cuando el ingrediente
 * ya está, así que sin esa marca la segunda corrida duplicaría cada receta.
 *
 * ── Una fila mala no mata el dominio ────────────────────────────────────
 * …salvo en las CAJAS, que son la excepción deliberada (ver `registers()`): ahí
 * un choque de punto de expedición aborta el dominio ENTERO sin importar
 * ninguna, porque media tanda de cajas fiscales es peor que ninguna.
 */
final class EncomImportService
{
    /**
     * Kinds de Punto cuya composición ES una receta de `item_compound`: el
     * combo fijo y las dos formas de producción. Para el motor de stock son lo
     * mismo (`explodeRecipe` no los distingue), y por eso comparten tabla.
     *
     * El combo DINÁMICO no está: su composición no es una receta sino grupos de
     * opciones que el cliente elige (`addon_group`), y el export del legacy no
     * trae ni el nombre del grupo, ni los mínimos y máximos, ni el recargo de
     * cada opción. Ver `compose()`.
     */
    private const RECIPE_KINDS = ['combo_fijo', 'produccion_previa', 'produccion_directa'];

    /** @var array<int,array{domain:string,message:string,at:string}> */
    private array $errors = [];

    /** @var array<int,array{at:string,message:string}> */
    private array $log = [];

    private array $progress = [];

    /** Memo de impuestos del destino: nombre normalizado → taxId. */
    private ?array $taxByName = null;

    /** Memo de artículos del destino: nombre normalizado → [itemId => true]. */
    private ?array $itemsPorNombre = null;

    /** Costos del panel: SKU normalizado → costo, y nombre normalizado → costo. */
    private array $costBySku  = [];
    private array $costByName = [];

    /** Artículos que entraron sin costo teniendo la tabla de costos disponible. */
    private array $sinCosto = [];

    public function __construct(
        private readonly string $companyId,
        private readonly EncomSource $source,
        private readonly ?string $jobId = null,
    ) {
    }

    /**
     * Corre los dominios pedidos.
     *
     * Un dominio que revienta entero queda registrado en `errors` y NO frena a
     * los otros: si el catálogo falla, los clientes igual se migran. Lo que no
     * puede pasar es que el job diga `done` como si nada — el worker marca
     * `failed` cuando hay errores.
     *
     * @param array<int,string> $domains
     * @return array{progress:array,errors:array,log:array}
     */
    public function run(array $domains, array $options = []): array
    {
        // El ORDEN no es cosmético:
        //   · `catalog` va primero porque `OutletsService::create()` siembra
        //     filas de inventario para los ítems rastreados que YA existan: con
        //     el orden inverso, una sucursal nueva nace sin esas filas.
        //   · `users` va DESPUÉS de `config` porque un usuario se asigna a las
        //     sucursales del legacy, y esas sucursales tienen que existir y
        //     estar mapeadas para poder asignarlas.
        //   · `stock` va ÚLTIMO porque una apertura de inventario es un
        //     movimiento por (artículo, sucursal): necesita el mapa de
        //     artículos que llena `catalog` Y el de sucursales que llena
        //     `config`.
        //   · Los tres de HISTÓRICO van al FINAL, después de `stock`: un
        //     asiento histórico referencia artículos, clientes, usuarios y
        //     sucursales, y todos esos mapas los llenan los dominios de
        //     arriba. Entre ellos el orden es indistinto —no se referencian—
        //     pero las ventas van primero porque son las que el operador
        //     mira.
        //   · `suppliers` va antes del histórico: las compras se cuelgan de
        //     sus proveedores, y sin ellos entraban sin proveedor.
        $order = [
            'catalog', 'customers', 'suppliers', 'config', 'users', 'payments', 'stock',
            'sales_history', 'purchases_history', 'expenses_history',
        ];

        foreach ($order as $domain) {
            if (!in_array($domain, $domains, true)) {
                continue;
            }

            try {
                match ($domain) {
                    'catalog'   => $this->catalog(),
                    'customers' => $this->customers(),
                    'suppliers' => $this->suppliers(),
                    'config'    => $this->config($options),
                    'users'     => $this->users(),
                    'payments'  => $this->payments(),
                    'stock'     => $this->stockOpening(),
                    'sales_history', 'purchases_history', 'expenses_history'
                                => $this->history($domain, $options),
                };
            } catch (\Throwable $e) {
                $this->fail($domain, $e->getMessage());
            }

            // Latido en cada borde de dominio, además de los que emite el
            // histórico por dentro: así los dominios que no laten solos
            // (catálogo, clientes, configuración) tampoco dejan al job callado
            // mientras corren.
            $this->latir();
        }

        return [
            'progress' => $this->progress,
            'errors'   => $this->errors,
            'log'      => $this->log,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // HISTÓRICO — ventas, compras y movimientos de caja (F2)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Delega en `EncomHistoryImporter`, que es donde vive el ÚNICO camino de
     * escritura del proyecto que no pasa por los servicios de negocio.
     *
     * Está en una clase aparte a propósito: el resto de este importador
     * cumple el D4 ("por los servicios reales") y el histórico es su
     * excepción explícita y acotada. Mezclarlos en el mismo archivo haría que
     * la excepción se lea como la regla — y el día que alguien copie de acá
     * para importar otra cosa, lo que tiene que encontrar es el docblock que
     * explica por qué una venta histórica NO puede pasar por
     * `SaleService::save()`.
     */
    private function history(string $domain, array $options): void
    {
        require_once __DIR__ . '/EncomHistoryImporter.php';
        require_once dirname(__DIR__) . '/Support/TenantClock.php';

        // La zona del TENANT, no la de la plataforma: las fechas del legacy
        // vienen en hora local del comercio y se guardan como texto que la
        // sesión de PG interpreta. Sin esto, una venta de las 23:30 del 31 se
        // asienta en otro mes —y por lo tanto en otra partición y en otro
        // rollup— que el día en que el comercio la cobró.
        try {
            \Punto\Api\Support\TenantClock::apply($this->companyId);
        } catch (\Throwable $e) {
            $this->note('No se pudo fijar la zona horaria del comercio: las fechas del histórico '
                . 'pueden correrse de día en los bordes. (' . $e->getMessage() . ')');
        }

        $importer = new EncomHistoryImporter(
            $this->companyId,
            $this->source,
            $this->jobId,
            // El LATIDO. El histórico del primer cliente real son más de dos
            // horas de requests paceadas (6.927 ventas, una por detalle), y
            // hasta hoy el progreso se escribía recién en `finish()`: la
            // pantalla del job no mostraba nada en todo ese tiempo y el reaper
            // daba por muerto un worker que estaba trabajando bien.
            function (array $counts) use ($domain): void {
                $this->progress[$domain] = $counts;
                $this->latir();
            }
        );

        try {
            match ($domain) {
                'sales_history'     => $importer->sales($options),
                'purchases_history' => $importer->purchases($options),
                'expenses_history'  => $importer->expenses($options),
                default             => null,
            };
        } finally {
            // Pase lo que pase, incluido un dominio que ABORTA a mitad de
            // camino: los conteos de lo que sí entró, la bitácora y los errores
            // llegan al job. Con el `return` como única vía, un dominio que
            // lanzaba se llevaba consigo la evidencia —justamente la de las
            // sondas, que existe para explicar por qué abortó—.
            $this->progress[$domain] = $importer->counts();

            foreach ($importer->log() as $entrada) {
                $this->log[] = $entrada;
            }
            foreach ($importer->errors() as $error) {
                $this->errors[] = $error;
            }
        }
    }

    /**
     * Latido del job: progreso parcial y, con él, señal de vida.
     *
     * `reportProgress()` bumpea `updated_at`, que es contra lo que
     * `EncomMigrationService::requeueStale()` mide si el worker sigue vivo. O
     * sea que esto no es solo cosmética de la pantalla: es lo que impide que
     * una corrida larga se reencole sola y termine con dos workers sobre el
     * mismo job.
     *
     * Un latido que falla NO puede tirar el import: es telemetría. Si la base
     * estuviera caída, el import se cae solo y con un error que dice algo.
     */
    private function latir(): void
    {
        if ($this->jobId === null || $this->jobId === '') {
            return;
        }

        try {
            (new EncomMigrationService())->reportProgress($this->jobId, $this->progress, $this->log);
        } catch (\Throwable $e) {
            // Intencionalmente en silencio. Ver el docblock.
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // catalog — categorías, marcas, etiquetas, artículos y su composición
    // ═══════════════════════════════════════════════════════════════════

    private function catalog(): void
    {
        require_once dirname(__DIR__) . '/Categories/CategoryService.php';
        require_once dirname(__DIR__) . '/Brands/BrandService.php';
        require_once dirname(__DIR__) . '/Tags/TagService.php';
        require_once dirname(__DIR__) . '/Items/ItemService.php';
        require_once dirname(__DIR__) . '/Items/ItemRepository.php';
        require_once dirname(__DIR__) . '/Items/ItemImporter.php';

        global $db;

        // ── Taxonomías primero: los ítems las referencian ────────────────
        // Las tres tienen UNIQUE (empresa, nombre sin mayúsculas), así que una
        // que YA existe en Punto —cargada a mano, o que el legacy trae dos
        // veces con distinto case— se REUSA y se mapea, en vez de fallar con un
        // 23505 y dejar sus artículos sin categoría (job 71e8282d, 2026-09-18:
        // "ENTRADAS", "COMBOS", "Buffet", "DELIVERY" y "BEBIDAS"). Lo resuelve
        // el SERVICIO de cada taxonomía (`resolveOrCreateByName()`), no el
        // importador: es la misma regla que el índice, en un solo lugar.
        $categories = new \Punto\Api\Categories\CategoryService($db);
        $this->eachTaxonomy('category', 'Categorías', $this->source->categories(), $categories);

        $brands = new \Punto\Api\Brands\BrandService($db);
        $this->eachTaxonomy('brand', 'Marcas', $this->source->brands(), $brands);

        $tags = new \Punto\Api\Tags\TagService($db);
        $this->eachTaxonomy('tag', 'Etiquetas', $this->source->tags(), $tags);

        // El COSTO sale de otra superficie que el resto del catálogo (la tabla
        // del panel, no `/fetchs`) y es un ENRIQUECIMIENTO: si no se puede
        // traer, los artículos entran igual, sin costo. Se carga ANTES del
        // bucle para no pedir la tabla una vez por artículo.
        $this->loadItemCosts();

        // ── Artículos: PRIMERA pasada, sin composición ───────────────────
        // Un combo referencia ítems que pueden venir DESPUÉS que él en el
        // export, así que la composición no se puede resolver mientras se crea.
        // Se crean todos, y `compose()` los relaciona con el mapa ya completo.
        $items = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));

        $this->each('item', $this->source->items(), function (array $row) use ($items): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $kind  = $this->kindFor($row);
            $flags = \Punto\Api\Items\ItemImporter::legacyFlagsForKind($kind);

            $categoryId = $this->mapOf('category', $row['categoryId'] ?? null)
                ?: $this->mapOf('category', $row['category'] ?? null);
            $brandId    = $this->mapOf('brand', $row['brand'] ?? null);

            // El alta de un artículo son DOS pasos (`createBlank()` +
            // `update()`) y sin transacción no son atómicos: si el update
            // falla, queda un "Nuevo Artículo" vacío en el catálogo del cliente
            // que nadie relaciona con la migración. Lo detectó el arnés.
            global $db;
            $db->StartTrans();

            try {
                $itemId = $items->createBlank($this->companyId, $flags['itemType'], $kind);
                if (!is_string($itemId) || $itemId === '') {
                    throw new \RuntimeException('no se pudo crear el artículo "' . $name . '"');
                }

                $this->writeItem($items, $itemId, $name, $kind, $flags, $categoryId, $brandId, $row);
            } catch (\Throwable $e) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw $e;
            }

            $db->CompleteTrans();

            return $itemId;
        });

        // Relanzar COMPLETA la categoría/marca de los artículos que ya estaban
        // importados y entraron sin ella (job 71e8282d: cinco categorías
        // chocaron contra el UNIQUE y sus artículos quedaron sin categoría; el
        // artículo es idempotente y la corrida siguiente lo saltea).
        $this->completarTaxonomiasDeArticulos($items, $this->source->items());

        // Qué artículos quedaron sin costo teniendo la tabla disponible. No se
        // inventa un 0 —"no lo sé" y "cuesta cero" no son lo mismo, y un 0
        // falso arruina el margen de ese artículo para siempre—, así que se
        // nombran para que soporte los complete.
        if ($this->sinCosto !== []) {
            foreach (array_slice($this->sinCosto, 0, 30) as $nombre) {
                $this->note('Sin costo (no se encontró en la tabla del panel): ' . $nombre);
            }
            if (count($this->sinCosto) > 30) {
                $this->note('… y ' . (count($this->sinCosto) - 30) . ' artículo(s) más sin costo.');
            }
        }

        // ── SEGUNDA pasada: combos y recetas ─────────────────────────────
        $this->compose();

        // El stock inicial SÍ se migra desde 2026-09-11, pero NO acá: es su
        // propio dominio (`stock`) y corre al final, porque una apertura
        // necesita además el mapa de SUCURSALES que llena `config`.
    }

    /**
     * Importa una taxonomía con unicidad por nombre (categoría, marca,
     * etiqueta): la que ya existe con ese nombre —sin importar mayúsculas— se
     * REUSA y queda mapeada; solo se crea la que falta.
     *
     * @param object $svc servicio con `findIdByName()` y `resolveOrCreateByName()`
     */
    private function eachTaxonomy(string $domain, string $titulo, array $rows, object $svc): void
    {
        $reusadas = [];

        $this->each($domain, $rows, function (array $row) use ($svc, &$reusadas): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                throw new \RuntimeException('vino sin nombre en el legacy.');
            }
            if ($svc->findIdByName($this->companyId, $name) !== null) {
                $reusadas[] = $name;
            }
            return $svc->resolveOrCreateByName($this->companyId, $name);
        });

        if ($reusadas !== []) {
            $reusadas = array_values(array_unique($reusadas));
            $this->note(
                $titulo . ' que ya existían en Punto con el mismo nombre y se reusaron en vez de duplicarse: '
                . implode(', ', array_slice($reusadas, 0, 30))
                . (count($reusadas) > 30 ? ' … y ' . (count($reusadas) - 30) . ' más.' : '.')
            );
        }
    }

    /**
     * A un artículo YA importado que quedó sin categoría o sin marca, le pone
     * la que ahora sí resuelve el mapa. Nunca pisa una que ya tenga: puede ser
     * la que el comercio eligió a mano después de la migración.
     *
     * Escribe las dos formas, igual que `writeItem()`: la FK legacy
     * `item.categoryId`/`brandId` por el servicio y la m2m
     * (`item_category`/`item_brand`).
     */
    private function completarTaxonomiasDeArticulos(\Punto\Api\Items\ItemService $items, array $rows): void
    {
        $completados = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $legacyId = $this->legacyIdOf($row);
            $itemId   = $legacyId === null ? '' : $this->mapOf('item', $legacyId);
            if ($itemId === '') {
                continue;
            }

            $categoryId = $this->mapOf('category', $row['categoryId'] ?? null)
                ?: $this->mapOf('category', $row['category'] ?? null);
            $brandId    = $this->mapOf('brand', $row['brand'] ?? null);
            if ($categoryId === '' && $brandId === '') {
                continue;
            }

            $actual = \ncmExecute(
                'SELECT i.categoryId AS categoryid, i.brandId AS brandid,
                        EXISTS (SELECT 1 FROM item_category ic WHERE ic.itemId = i.itemId) AS tienecat,
                        EXISTS (SELECT 1 FROM item_brand ib WHERE ib.itemId = i.itemId) AS tienemarca
                   FROM item i
                  WHERE i.itemId = ? AND i.companyId = ?
                  LIMIT 1',
                [$itemId, $this->companyId]
            );
            if (!$actual) {
                continue;
            }

            $sinCat   = (string) ($actual['categoryid'] ?? '') === '' && !self::pgTruthy($actual['tienecat'] ?? false);
            $sinMarca = (string) ($actual['brandid'] ?? '') === '' && !self::pgTruthy($actual['tienemarca'] ?? false);

            $patch = [];
            if ($sinCat && $categoryId !== '') {
                $patch['categoryId'] = $categoryId;
            }
            if ($sinMarca && $brandId !== '') {
                $patch['brandId'] = $brandId;
            }
            if ($patch === []) {
                continue;
            }

            try {
                $items->update($itemId, $this->companyId, $patch);
                if (isset($patch['categoryId'])) {
                    $this->linkM2m('item_category', 'categoryId', $itemId, $categoryId);
                }
                if (isset($patch['brandId'])) {
                    $this->linkM2m('item_brand', 'brandId', $itemId, $brandId);
                }
                $completados[] = trim((string) ($row['name'] ?? $legacyId));
            } catch (\Throwable $e) {
                $this->fail('item', 'Item "' . trim((string) ($row['name'] ?? '?')) . '": no se le pudo completar la categoría/marca: ' . $e->getMessage());
            }
        }

        if ($completados !== []) {
            $this->note(
                count($completados) . ' artículo(s) ya importados que habían quedado sin categoría o marca y se '
                . 'completaron en esta corrida: ' . implode(', ', array_slice($completados, 0, 20))
                . (count($completados) > 20 ? ' … y ' . (count($completados) - 20) . ' más.' : '.')
            );
        }
    }

    /** Completa el artículo recién creado y engancha sus taxonomías. */
    private function writeItem(
        \Punto\Api\Items\ItemService $items,
        string $itemId,
        string $name,
        string $kind,
        array $flags,
        string $categoryId,
        string $brandId,
        array $row,
    ): void {
        $sku     = trim((string) ($row['sku'] ?? ''));
        $barcode = trim((string) ($row['barcode'] ?? ''));

        $patch = [
            'itemName'           => $name,
            'itemSKU'            => $sku !== '' ? $sku : null,
            // Columna `item.barcode` (mig 220). El bootstrap del POS legacy no
            // lo relevó, así que lo normal es que venga vacío; cuando viene, es
            // el código con el que el comercio ya escanea.
            'barcode'            => $barcode !== '' ? $barcode : null,
            'itemKind'           => $kind,
            'itemType'           => $flags['itemType'],
            'itemCanSale'        => $flags['itemCanSale'],
            'itemTrackInventory' => $flags['itemTrackInventory'],
            'itemProduction'     => $flags['itemProduction'],
            'itemDescription'    => trim((string) ($row['description'] ?? '')),
            // `null` = "no lo sé", que no es lo mismo que 0. `/fetchs` no manda
            // el costo, así que casi siempre sale de la tabla del panel, por
            // SKU o por nombre (ver `costFor()`).
            'itemCost'           => $this->numOrNull($row['cost'] ?? null) ?? $this->costFor($row),
            'itemPrice'          => $this->numOrNull($row['price'] ?? null),
            'itemUOM'            => trim((string) ($row['uom'] ?? '')),
            'itemStatus'         => 1,
            'itemTaxIncluded'    => 1,
            // NULL, no '': son columnas `uuid` y Postgres rechaza la cadena
            // vacía con "invalid input syntax for type uuid". Un artículo sin
            // categoría en el legacy es un artículo SIN categoría, no uno con
            // la categoría "". Lo detectó el arnés.
            'categoryId'         => $categoryId !== '' ? $categoryId : null,
            'brandId'            => $brandId !== '' ? $brandId : null,
            'updated_at'         => TODAY,
        ];

        // El IVA del artículo: `/fetchs` lo manda como el VALOR legacy ("10",
        // "5", "0"), que es exactamente el `name` de la tabla `tax` de Punto.
        $taxId = $this->taxIdFor($row['tax'] ?? null);
        if ($taxId !== null) {
            $patch['taxId'] = $taxId;
        }

        if (!$items->update($itemId, $this->companyId, $patch)) {
            throw new \RuntimeException('no se pudieron guardar los datos del artículo');
        }

        // m2m: el panel lee `item_category` / `item_brand`, y la columna
        // `item.categoryId` es la FK legacy. Escribir solo una de las dos deja
        // el artículo sin categoría en la mitad de las pantallas — es la trampa
        // que ya pisó la mig 136 (context/41).
        $this->linkM2m('item_category', 'categoryId', $itemId, $categoryId);
        $this->linkM2m('item_brand', 'brandId', $itemId, $brandId);
    }

    /**
     * Trae los costos del panel y los indexa por SKU y por nombre.
     *
     * NUNCA lanza: el costo es un enriquecimiento y no puede voltear el
     * catálogo. Si la tabla no se puede leer —el legacy cambió, el usuario no
     * tiene permiso, la sesión del panel se cayó— los artículos entran sin
     * costo y la bitácora lo dice con el motivo.
     */
    private function loadItemCosts(): void
    {
        try {
            $rows = $this->source->itemCosts();
        } catch (\Throwable $e) {
            $this->note(
                'No se pudieron traer los costos del panel legacy (' . $e->getMessage() . '). '
                . 'Los artículos se importan SIN costo; se cargan después desde el panel de Punto.'
            );
            return;
        }

        if ($rows === []) {
            $this->note('La tabla de artículos del panel legacy no devolvió costos: los artículos entran sin costo.');
            return;
        }

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cost = $this->numOrNull($r['cost'] ?? null);
            if ($cost === null) {
                continue;
            }

            $sku = $this->normalizeSku((string) ($r['sku'] ?? ''));
            if ($sku !== '' && !isset($this->costBySku[$sku])) {
                $this->costBySku[$sku] = $cost;
            }

            // El nombre es el fallback, así que ante dos artículos homónimos
            // gana el PRIMERO en vez de pisarse: con el nombre repetido no hay
            // forma de saber cuál es cuál, y elegir el último es igual de
            // arbitrario pero menos predecible.
            $name = $this->normalizeName((string) ($r['name'] ?? ''));
            if ($name !== '' && !isset($this->costByName[$name])) {
                $this->costByName[$name] = $cost;
            }
        }
    }

    /**
     * Costo de un artículo de `/fetchs`: por SKU cuando lo tiene, y por nombre
     * normalizado como respaldo.
     *
     * El SKU va primero porque es el identificador que el comercio controla; el
     * nombre es una heurística razonable —las dos superficies son del mismo
     * comercio y el nombre lo escribió una sola vez— pero no es una clave.
     * Lo que no matchea por ninguna de las dos NO se inventa: el artículo entra
     * sin costo y queda nombrado en la bitácora.
     */
    private function costFor(array $row): ?float
    {
        // Sin tabla de costos no hay nada que buscar ni nada que reportar: el
        // motivo ya se anotó una sola vez en `loadItemCosts()`.
        if ($this->costBySku === [] && $this->costByName === []) {
            return null;
        }

        $sku = $this->normalizeSku((string) ($row['sku'] ?? ''));
        if ($sku !== '' && isset($this->costBySku[$sku])) {
            return $this->costBySku[$sku];
        }

        $name = $this->normalizeName((string) ($row['name'] ?? ''));
        if ($name !== '' && isset($this->costByName[$name])) {
            return $this->costByName[$name];
        }

        $this->sinCosto[] = trim((string) ($row['name'] ?? '')) ?: '(sin nombre)';
        return null;
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtoupper(trim($sku), 'UTF-8');
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name), 'UTF-8');
    }

    /**
     * SEGUNDA pasada del catálogo: combos y recetas de producción.
     *
     * ── De dónde sale, y por qué esto no existía antes ───────────────────
     * El campo `compound` viene INLINE en cada artículo de `/fetchs`, como un
     * string con un JSON adentro:
     *
     *     [{"id":"6KNgR","units":"1.000","select":"0"}]
     *
     * donde `id` es el itemId del componente EN EL LEGACY, `units` la cantidad
     * y `select` si el componente lo elige el cliente al vender. Las pantallas
     * del panel no lo exponían por ningún lado: por eso la F1 daba los combos y
     * las recetas por no migrables.
     *
     * ── Qué se mapea y qué NO se inventa ─────────────────────────────────
     * Un componente con `select = "0"` es FIJO: entra en `item_compound`, que
     * es la receta que el motor de stock explota al vender (misma tabla para el
     * combo fijo y para producción — `explodeRecipe` no los distingue).
     *
     * Un componente con `select = "1"` es una OPCIÓN que el cliente elige. En
     * Punto eso no es una receta sino un grupo de add-ons (`addon_group`), y el
     * export NO trae nada de lo que ese modelo necesita: ni el nombre del
     * grupo, ni cuántas opciones se pueden elegir (`minSelect`/`maxSelect`), ni
     * el recargo de cada una (`priceDelta`). Fabricar un grupo con valores
     * inventados es peor que no migrarlo: un `maxSelect` adivinado deja al
     * cajero sin poder cerrar la venta, y un `priceDelta` en 0 regala el
     * agregado. Así que esos componentes NO se escriben y el artículo queda
     * anotado en la bitácora del job con su nombre y su kind del legacy, para
     * que soporte lo arme a mano. Lo mismo para el combo dinámico entero.
     *
     * ── Recetas a medias: se completan, no se congelan ───────────────────
     * La idempotencia tiene DOS niveles a propósito. Cada componente escrito
     * deja su propia marca (`padre:hijo`), y el PADRE solo se marca cuando no
     * quedó ningún componente sin resolver.
     *
     * El motivo es corrección de stock: si una receta con un componente
     * faltante se marcara como compuesta, la corrida siguiente —ya con el ítem
     * creado por soporte— la saltearía por idempotente y la receta quedaría
     * incompleta para siempre, con `explodeRecipe` descontando de menos en cada
     * venta y sin una sola señal. Con la marca por componente, reintentar
     * COMPLETA lo que falta sin volver a sumar lo que ya estaba (`add()` suma
     * la cantidad cuando el ingrediente ya existe).
     */
    private function compose(): void
    {
        require_once dirname(__DIR__) . '/Items/ItemCompoundService.php';

        global $db;
        $compounds = new \Punto\Api\Items\ItemCompoundService($db);

        $counts    = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'omitted' => 0];
        $revisar   = [];
        $porNombre = [];

        foreach ($this->source->items() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $raw = trim((string) ($row['compound'] ?? ''));
            if ($raw === '' || $raw === '[]') {
                continue;
            }

            $counts['total']++;

            $legacyId = $this->legacyIdOf($row);
            $name     = trim((string) ($row['name'] ?? '')) ?: '(sin nombre)';
            $kind     = $this->kindFor($row);

            if ($legacyId === null) {
                // El artículo mismo ya quedó omitido (y nombrado) en la pasada
                // de artículos por la misma razón: es un aviso, no una falla
                // del job — ver el docblock de each().
                $counts['omitted']++;
                $this->note('La composición de "' . $name . '" no se importó: el artículo vino sin id del legacy.');
                continue;
            }

            // Idempotencia propia: `ItemCompoundService::add()` SUMA la
            // cantidad cuando el ingrediente ya existe, así que re-correr sin
            // esta marca convertiría 1 unidad en 2, y en 3. El dominio del mapa
            // es `compound` y no `item` porque son dos hechos distintos: el
            // artículo existe / el artículo ya está compuesto.
            if (EncomMigrationService::mapped($this->companyId, 'compound', $legacyId) !== null) {
                $counts['skipped']++;
                continue;
            }

            $parentId = $this->mapOf('item', $legacyId);
            if ($parentId === '') {
                // El artículo padre no se importó (falló en la primera pasada):
                // su receta no tiene dónde colgarse.
                $counts['failed']++;
                $this->fail(
                    'compound',
                    'La composición de "' . $name . '" no se importó porque el artículo mismo no se importó (ver su '
                    . 'error en esta bitácora).'
                );
                continue;
            }

            if (!in_array($kind, self::RECIPE_KINDS, true)) {
                $counts['failed']++;
                $this->fail(
                    'compound',
                    'Revisar a mano: ' . $name . ' (' . (trim((string) ($row['kind'] ?? '')) ?: 'sin kind') . ') trae '
                    . 'composición pero Punto no lo importa como combo ni receta con ese tipo.'
                );
                continue;
            }

            $parts = json_decode($raw, true);
            if (!is_array($parts) || $parts === []) {
                $counts['failed']++;
                $this->fail('compound', 'La composición de "' . $name . '" no se pudo leer: ' . $raw);
                continue;
            }

            $escritos    = 0;
            $yaEstaban   = 0;
            $selectables = 0;
            $faltantes   = [];

            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }

                // El componente elegible NO se escribe: ver el docblock.
                if (trim((string) ($part['select'] ?? '0')) !== '0') {
                    $selectables++;
                    continue;
                }

                $childLegacy = trim((string) ($part['id'] ?? ''));
                $childName   = trim((string) ($part['name'] ?? ''));
                $childLabel  = ($childName !== '' ? '"' . $childName . '" ' : '') . '(' . ($childLegacy ?: 'sin id') . ')';
                if ($childLegacy === '') {
                    $faltantes[] = $childLabel;
                    continue;
                }

                // Marca POR COMPONENTE. Es lo que permite COMPLETAR una receta
                // que quedó a medias sin volver a sumar lo que ya se escribió:
                // `add()` SUMA la cantidad si el ingrediente ya está, así que
                // reintentar el combo entero convertiría 1 unidad en 2.
                $partKey = $this->compoundKey($legacyId, $childLegacy);
                if (EncomMigrationService::mapped($this->companyId, 'compound', $partKey) !== null) {
                    $yaEstaban++;
                    continue;
                }

                $childId = $this->mapOf('item', $childLegacy);
                if ($childId === '') {
                    // El componente NO vino en el catálogo: `/fetchs` solo
                    // exporta artículos ACTIVOS y VENDIBLES de la sucursal del
                    // alcance (`itemStatus = 1 AND itemCanSale = 1`, leído en
                    // el código del legacy), así que un insumo no vendible, uno
                    // archivado o uno de otra sucursal nunca llega al mapa. El
                    // `compound` sí trae su NOMBRE: si en Punto hay UN solo
                    // artículo con ese nombre (del mismo comercio), es ese. Con
                    // cero o con varios no se adivina.
                    $childId = $this->itemIdPorNombreUnico($childName);
                    if ($childId === '') {
                        $faltantes[] = $childLabel;
                        continue;
                    }
                    $porNombre[] = $name . ' → ' . $childLabel;
                }

                // `units` viaja como "1.000" — decimal con punto, no un miles.
                $units = $this->numOrNull($part['units'] ?? null) ?? 1.0;
                if ($units <= 0) {
                    $units = 1.0;
                }

                try {
                    $compounds->add($parentId, $this->companyId, $childId, $units);
                    EncomMigrationService::remember($this->companyId, 'compound', $partKey, $childId, $this->jobId);
                    $escritos++;
                } catch (\Throwable $e) {
                    // Un ciclo o un componente de otro tenant: lo rechaza el
                    // servicio, que es justamente para lo que se lo usa.
                    $faltantes[] = $childLabel . ': ' . $e->getMessage();
                }
            }
            if ($selectables > 0) {
                $revisar[] = $name . ' — tiene ' . $selectables . ' componente(s) que el cliente elige al vender: '
                    . 'hay que armarlos como grupo de opciones en la ficha del artículo.';
            }

            if ($faltantes !== []) {
                $this->fail(
                    'compound',
                    'Revisar a mano: ' . $name . ' — componentes que no se pudieron resolver: ' . implode(', ', $faltantes)
                    . '. No vinieron en el catálogo del sistema anterior, que solo exporta artículos activos y '
                    . 'vendibles de esta sucursal (un insumo no vendible, uno archivado o uno de otra sucursal queda '
                    . 'afuera), y en Punto no hay un único artículo con ese nombre. Creá el componente y volvé a '
                    . 'lanzar: la receta se completa sin duplicar lo que ya entró.'
                );
                // ── La receta INCOMPLETA no se marca como compuesta ───────
                // Marcarla la congelaría a medias para siempre: la corrida
                // siguiente la saltearía por idempotente, y `explodeRecipe`
                // descontaría de menos en CADA venta, en silencio. Sin la
                // marca del padre, el próximo intento vuelve a entrar acá —
                // los componentes ya escritos los saltea su propia marca— y
                // termina la receta en cuanto soporte cree el que faltaba.
                $counts['failed']++;
            } elseif ($escritos > 0 || $yaEstaban > 0) {
                EncomMigrationService::remember($this->companyId, 'compound', $legacyId, $parentId, $this->jobId);
                $counts['imported']++;
            } elseif ($selectables > 0) {
                // Todos sus componentes se eligen al vender: no hay receta fija
                // que escribir. No es una falla —queda la nota de arriba para
                // armar el grupo de opciones— y contarla como tal escondía
                // cuál era el problema.
                $counts['skipped']++;
            } else {
                $counts['failed']++;
                $this->fail('compound', 'La composición de "' . $name . '" vino sin componentes utilizables: ' . $raw);
            }
        }

        $this->progress['compound'] = $counts;

        if ($porNombre !== []) {
            $this->note(
                'Componentes de combos/recetas que no vinieron en el catálogo del sistema anterior y se '
                . 'encontraron en Punto por su nombre (un único artículo con ese nombre): '
                . implode('; ', array_slice($porNombre, 0, 30))
                . (count($porNombre) > 30 ? ' … y ' . (count($porNombre) - 30) . ' más.' : '.')
            );
        }

        foreach (array_slice($revisar, 0, 50) as $linea) {
            $this->note('Revisar a mano: ' . $linea);
        }
        if (count($revisar) > 50) {
            $this->note('… y ' . (count($revisar) - 50) . ' artículo(s) más para revisar a mano.');
        }
    }

    /**
     * Id del ÚNICO artículo del destino con ese nombre (sin mayúsculas ni
     * espacios de más), o '' si no hay ninguno o hay más de uno.
     *
     * Una consulta por corrida, no por componente.
     */
    private function itemIdPorNombreUnico(string $nombre): string
    {
        $clave = $this->normalizeName($nombre);
        if ($clave === '') {
            return '';
        }

        if ($this->itemsPorNombre === null) {
            $this->itemsPorNombre = [];
            $rs = \ncmExecute(
                'SELECT itemId, itemName FROM item WHERE companyId = ?',
                [$this->companyId],
                false,
                true
            );
            if ($rs !== false && is_object($rs)) {
                while (!$rs->EOF) {
                    $k  = $this->normalizeName((string) ($rs->fields['itemname'] ?? $rs->fields['itemName'] ?? ''));
                    $id = (string) ($rs->fields['itemid'] ?? $rs->fields['itemId'] ?? '');
                    if ($k !== '' && $id !== '') {
                        $this->itemsPorNombre[$k][$id] = true;
                    }
                    $rs->MoveNext();
                }
                $rs->Close();
            }
        }

        $ids = array_keys($this->itemsPorNombre[$clave] ?? []);
        return count($ids) === 1 ? (string) $ids[0] : '';
    }

    // ═══════════════════════════════════════════════════════════════════
    // customers — clientes
    // ═══════════════════════════════════════════════════════════════════

    private function customers(): void
    {
        require_once dirname(__DIR__) . '/Contacts/ContactService.php';
        require_once dirname(__DIR__) . '/Contacts/ContactRepository.php';

        global $db;
        $contacts = new \Punto\Api\Contacts\ContactService(new \Punto\Api\Contacts\ContactRepository($db));

        $dup = self::duplicadosVacios();

        // La idempotencia usa el id REAL del contacto en el legacy
        // (`customerId`), que `/fetchs` sí manda. El CSV del panel no lo traía y
        // obligaba a una clave natural (documento, o el nombre normalizado) que
        // fusionaba a dos homónimos sin documento en un solo cliente. Ese
        // parche se fue junto con el scraping.
        //
        // Un cliente YA mapeado no se saltea a ciegas: se COMPLETA lo que en
        // Punto está vacío (owner 2026-09-19, context/77 §17.19). Ver
        // `completarCliente()`.
        $fill = self::completadosVacios();
        $this->each(
            'customer',
            $this->source->customers(),
            function (array $row) use ($contacts, &$dup): ?string {
                $in = $this->entradaCliente($row);
                return $in === null ? null : $this->crearContacto($contacts, $in, 'cliente', $dup);
            },
            function (array $row, string $contactId) use ($contacts, &$fill): void {
                $in = $this->entradaCliente($row);
                if ($in !== null) {
                    $this->completarCliente($contacts, $contactId, $in, $fill);
                }
            }
        );

        $this->avisarDuplicados('cliente', $dup);
        $this->avisarCompletados($fill);
    }

    /**
     * Entrada de `ContactService` para un cliente del legacy, o null si la fila
     * no trae ningún nombre (no es importable). La MISMA para el alta y para
     * completar un cliente ya importado: así los dos caminos leen el legacy
     * con un solo criterio.
     */
    private function entradaCliente(array $row): ?array
    {
        $fiscalName = trim((string) ($row['fiscalName'] ?? ''));
        $personName = trim((string) ($row['name'] ?? ''));
        if ($fiscalName === '' && $personName === '') {
            return null;
        }

        $in = [
            'tin'     => trim((string) ($row['tin'] ?? '')),
            'ci'      => trim((string) ($row['ci'] ?? '')),
            'phone'   => trim((string) ($row['phone'] ?? '')),
            'email'   => trim((string) ($row['email'] ?? '')),
            'address' => trim((string) ($row['address'] ?? '')),
            'city'    => trim((string) ($row['city'] ?? '')),
            'note'    => trim((string) ($row['note'] ?? '')),
            'type'    => \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER,
        ];
        if ($fiscalName !== '') {
            $in['fiscalName'] = $fiscalName;
        }
        if ($personName !== '') {
            $in['name'] = $personName;
        }

        foreach (['location', 'country'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                $in[$k] = $v;
            }
        }

        // Tipo de documento: el legacy manda `typeIdentifier` con SU
        // numeración, que NO es la Tabla 3 de la SET que usa Punto
        // (`ID_TYPES` = 11..17). Su tabla de códigos no está relevada, así
        // que traducirla sería adivinar sobre un dato FISCAL — y
        // `ContactService` rechaza con excepción cualquier código que no
        // reconozca, o sea que adivinar mal cuesta el cliente entero.
        //
        // Se manda SOLO si el valor ya es un código válido de Punto. Si no,
        // se omite y Punto infiere el tipo al leer (el propio servicio lo
        // documenta): el NÚMERO del documento se migra igual, en `tin`/`ci`,
        // que es lo que identifica al cliente.
        $idType = $row['idType'] ?? null;
        if (is_numeric($idType)
            && in_array((int) $idType, \Punto\Api\Contacts\ContactService::ID_TYPES, true)
        ) {
            $in['idType'] = (int) $idType;
        }

        // Saldo a favor y línea de crédito: el legacy los tenía y el CSV
        // no los exponía. `creditLine > 0` es además lo que habilita la
        // venta a crédito en el POS.
        $storeCredit = $this->numOrNull($row['storeCredit'] ?? null);
        if ($storeCredit !== null) {
            $in['storeCredit'] = $storeCredit;
        }
        $creditLine = $this->numOrNull($row['creditLine'] ?? null);
        if ($creditLine !== null) {
            $in['creditLine']   = $creditLine;
            $in['isCreditable'] = $creditLine > 0 ? 1 : 0;
        }
        $loyalty = $this->numOrNull($row['loyalty'] ?? null);
        if ($loyalty !== null) {
            $in['loyalty'] = $loyalty;
        }

        if (is_numeric($row['lat'] ?? null) && is_numeric($row['lng'] ?? null)) {
            $in['lat'] = $row['lat'];
            $in['lng'] = $row['lng'];
        }

        $bday = trim((string) ($row['bday'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $bday)) {
            $in['bday'] = substr($bday, 0, 10);
        }

        return $in;
    }

    /** @return array{clientes:int,campos:array<string,int>,dejados:array<string,int>,telNota:array<int,string>,docRepetido:array<int,string>} */
    private static function completadosVacios(): array
    {
        return ['clientes' => 0, 'campos' => [], 'dejados' => [], 'telNota' => [], 'docRepetido' => []];
    }

    /**
     * Completa un cliente YA importado con lo que el legacy trae y en Punto
     * está VACÍO (owner 2026-09-19, context/77 §17.19).
     *
     * Por qué existe: los 919 clientes de Don Ramón entraron con una versión
     * vieja del migrador —sin teléfono, email, nota ni dirección— y relanzar
     * los salteaba por idempotentes. Ahora relanzar los completa.
     *
     * La regla, campo por campo:
     *   · vacío en Punto (NULL o '' tras trim) y con valor en el legacy → se completa;
     *   · con valor en Punto → NO se toca, aunque difiera: puede ser una
     *     corrección hecha en Punto. Se CUENTA para la bitácora;
     *   · nunca se borra un valor.
     *
     * Excepciones deliberadas, las mismas reglas del alta (`crearContacto()`):
     *   · teléfono inválido o que ya tiene otro cliente → no va al campo, va a
     *     la nota. Es la ÚNICA escritura sobre un campo con valor, y es un
     *     AGREGADO: la línea se busca antes, así una segunda corrida no la
     *     duplica;
     *   · documento personal que ya tiene OTRO contacto → no se completa
     *     (unicidad del servicio) y se cuenta.
     *
     * Dirección: si el cliente no tiene NINGUNA dirección con texto, se
     * completa la default —se crea si no existe, igual que en el alta—. Si ya
     * tiene una, el bloque entero (texto, ciudad, barrio, coordenadas) no se
     * toca. OJO, el caso real: el migrador viejo dejó una fila default con el
     * texto VACÍO por cliente; `ContactService::update()` completa ESA fila
     * (no crea una segunda default), y solo en las columnas vacías.
     *
     * Saldo a favor y puntos tienen default en la tabla (0.00 / 1), así que en
     * la práctica nunca están vacíos y nunca se completan: es la regla, no un
     * olvido — pisar un saldo es plata.
     *
     * Escribe por `ContactService::update()` (patch parcial: solo las claves
     * presentes), dentro de una transacción por cliente para que contacto y
     * dirección queden juntos. La unicidad se chequea ANTES de escribir, así
     * que el reintento sin el campo en conflicto no deja nada a medias.
     *
     * @param array{clientes:int,campos:array<string,int>,dejados:array<string,int>,telNota:array<int,string>,docRepetido:array<int,string>} $fill
     */
    private function completarCliente(
        \Punto\Api\Contacts\ContactService $svc,
        string $contactId,
        array $in,
        array &$fill
    ): void {
        $actual = $svc->find($contactId, $this->companyId);
        if ($actual === null) {
            return;
        }

        $txt   = static fn (mixed $v): string => trim(strip_tags((string) ($v ?? '')));
        $igual = static fn (string $a, string $b): bool
            => mb_strtolower(preg_replace('/\s+/u', ' ', $a) ?? $a, 'UTF-8')
            === mb_strtolower(preg_replace('/\s+/u', ' ', $b) ?? $b, 'UTF-8');
        $digitos = static fn (string $v): string
            => \Punto\Api\Contacts\ContactService::normalizePhoneDigits($v);

        $patch   = [];
        $campos  = [];
        $dejados = [];

        // ── Texto: clave pública => [columna, etiqueta, normalizador] ──────
        // La razón social (`fiscalName` → `contactName`) no está a propósito:
        // `ContactService::create()` la exige, así que un cliente importado
        // nunca la tiene vacía — y con valor, la regla es no tocarla.
        $textos = [
            'name'    => ['contactSecondName', 'nombre', null],
            'tin'     => ['contactTIN', 'RUC', null],
            'ci'      => ['contactCI', 'documento', static fn (string $v): string
                => \Punto\Api\Contacts\ContactService::normalizePersonalId($v)],
            'email'   => ['contactEmail', 'email', null],
            'country' => ['contactCountry', 'país', null],
        ];
        foreach ($textos as $k => [$col, $label, $norm]) {
            $nuevo = $txt($in[$k] ?? '');
            if ($nuevo === '') {
                continue;
            }
            $viejo = $txt($actual[$col] ?? '');
            if ($viejo === '') {
                $patch[$k]      = $nuevo;
                $campos[$label] = true;
            } elseif ($norm !== null ? $norm($viejo) !== $norm($nuevo) : !$igual($viejo, $nuevo)) {
                $dejados[] = $label;
            }
        }
        // `mapToColumns()` escribe `contactName` con el `name` cuando no viene
        // `fiscalName`: se manda el que YA tiene para que completar el nombre
        // de la persona no pise la razón social.
        if (isset($patch['name'])) {
            $patch['fiscalName'] = $txt($actual['contactName'] ?? '') ?: $patch['name'];
        }

        // Fecha de nacimiento: se compara el día, no el texto.
        if (isset($in['bday'])) {
            $viejo = substr($txt($actual['contactBirthDay'] ?? ''), 0, 10);
            if ($viejo === '') {
                $patch['bday']                 = $in['bday'];
                $campos['fecha de nacimiento'] = true;
            } elseif ($viejo !== $in['bday']) {
                $dejados[] = 'fecha de nacimiento';
            }
        }

        // Tipo de documento (solo llega si es un código válido de Punto).
        if (isset($in['idType'])) {
            $viejo = $actual['contactIdType'] ?? null;
            if ($viejo === null || $viejo === '') {
                $patch['idType']             = $in['idType'];
                $campos['tipo de documento'] = true;
            } elseif ((int) $viejo !== (int) $in['idType']) {
                $dejados[] = 'tipo de documento';
            }
        }

        // Números. NULL = vacío; un 0 guardado es un valor.
        $numeros = [
            'creditLine'  => ['contactCreditLine', 'línea de crédito'],
            'storeCredit' => ['contactStoreCredit', 'saldo a favor'],
            'loyalty'     => ['contactLoyalty', 'puntos'],
        ];
        foreach ($numeros as $k => [$col, $label]) {
            if (!isset($in[$k])) {
                continue;
            }
            $viejo = $actual[$col] ?? null;
            if ($viejo === null || trim((string) $viejo) === '') {
                $patch[$k]      = $in[$k];
                $campos[$label] = true;
            } elseif (abs((float) $viejo - (float) $in[$k]) > 0.000001) {
                $dejados[] = $label;
            }
        }
        // `contactCreditable` es DERIVADO de la línea (así lo arma el alta):
        // una línea NULL dice que nadie tocó el crédito en Punto, así que el
        // flag se alinea con la línea que se completa.
        if (isset($patch['creditLine'])) {
            $patch['isCreditable'] = $in['isCreditable'];
        }

        // ── Dirección ────────────────────────────────────────────────────
        $conTexto = false;
        $default  = null;
        foreach ($svc->addresses($contactId, $this->companyId) as $a) {
            if ($txt($a['address'] ?? '') !== '') {
                $conTexto = true;
            }
            if ($default === null && self::pgTruthy($a['default'] ?? null)) {
                $default = $a;
            }
        }
        $dirCampos = [
            'address'  => ['contactAddress', 'dirección'],
            'city'     => ['contactCity', 'ciudad'],
            'location' => ['contactLocation', 'barrio'],
        ];
        foreach ($dirCampos as $k => [$col, $label]) {
            $nuevo = $txt($in[$k] ?? '');
            if ($nuevo === '') {
                continue;
            }
            // Lo que el cliente MUESTRA: la fila default y, si no hay, el
            // espejo en `contact.data` (mismo criterio que `presentRow()`).
            $viejo = $txt($default !== null ? ($default[$k] ?? '') : ($actual[$col] ?? ''));
            if ($conTexto) {
                // Ya tiene dirección: el bloque no se toca. Cuenta como
                // "dejado" solo si lo que difiere es un dato de verdad.
                if ($viejo !== '' ? !$igual($viejo, $nuevo) : $k === 'address') {
                    $dejados[] = $label;
                }
                continue;
            }
            if ($viejo === '') {
                $patch[$k]      = $nuevo;
                $campos[$label] = true;
            } elseif (!$igual($viejo, $nuevo)) {
                $dejados[] = $label;
            }
        }
        if (!$conTexto && isset($in['lat'], $in['lng'])
            && ($default === null || (($default['lat'] ?? null) === null && ($default['lng'] ?? null) === null))
        ) {
            $patch['lat']        = $in['lat'];
            $patch['lng']        = $in['lng'];
            $campos['ubicación'] = true;
        }

        // ── Teléfono ─────────────────────────────────────────────────────
        $telLegacy = $txt($in['phone'] ?? '');
        $telMotivo = null;
        $paisApoyo = false;
        if ($telLegacy !== '') {
            $telActual = $txt($actual['contactPhone'] ?? '');
            if ($telActual !== '') {
                if ($digitos($telActual) !== $digitos($telLegacy)) {
                    $dejados[] = 'teléfono';
                }
            } else {
                // El país del CONTACTO decide cómo se parsea (el que ya tiene
                // o el que se le completa), igual que en el alta.
                $pais = (string) ($patch['country'] ?? $txt($actual['contactCountry'] ?? ''));
                if (!$svc->phoneIsStorable($this->companyId, ['phone' => $telLegacy, 'country' => $pais])) {
                    $telMotivo = 'el número no es válido';
                } else {
                    $patch['phone'] = $telLegacy;
                    if ($pais !== '' && !isset($patch['country'])) {
                        // El mismo valor que ya tiene: solo para que
                        // `mapToColumns()` parsee con el país del contacto.
                        $patch['country'] = $pais;
                        $paisApoyo        = true;
                    }
                }
            }
        }

        // ── Nota ─────────────────────────────────────────────────────────
        $notaActual = $txt($actual['contactNote'] ?? '');
        $notaLegacy = $txt($in['note'] ?? '');
        $nota       = $notaActual;
        if ($notaLegacy !== '') {
            if ($notaActual === '') {
                $nota           = $notaLegacy;
                $campos['nota'] = true;
            } elseif (!str_contains(mb_strtolower($notaActual, 'UTF-8'), mb_strtolower($notaLegacy, 'UTF-8'))) {
                $dejados[] = 'nota';
            }
        }

        // ── Escritura: se reintenta sin el campo que choca con otro contacto ─
        global $db;
        $nombre = $txt($actual['contactName'] ?? '') ?: $contactId;
        for ($intento = 0; $intento < 3; $intento++) {
            $notaFinal = $nota;
            if ($telMotivo !== null
                && !str_contains($notaFinal, 'Teléfono del sistema anterior: ' . $telLegacy)
            ) {
                $notaFinal = $this->sinTelefono(['phone' => $telLegacy, 'note' => $notaFinal], $telMotivo)['note'];
            }

            $escribir = $patch;
            if ($notaFinal !== $notaActual) {
                $escribir['note'] = $notaFinal;
            }
            // Lo que va de apoyo (razón social, flag derivado, país para
            // parsear) no es un cambio: sin nada más, no se escribe.
            $reales = array_diff_key($escribir, ['fiscalName' => 1, 'isCreditable' => 1]);
            if ($paisApoyo) {
                unset($reales['country']);
            }
            if ($reales === []) {
                break;
            }

            $db->StartTrans();
            try {
                if (!$svc->update($contactId, $this->companyId, $escribir)) {
                    throw new \RuntimeException('no se pudo actualizar el cliente');
                }
                $db->CompleteTrans();
            } catch (\Punto\Api\Contacts\DuplicateContactException $e) {
                $db->FailTrans();
                $db->CompleteTrans();
                if ($e->field === 'ci' && isset($patch['ci'])) {
                    unset($patch['ci'], $campos['documento']);
                    $fill['docRepetido'][] = $nombre . ' (' . $in['ci'] . ', ya lo tiene ' . $e->contactName . ')';
                    continue;
                }
                if ($e->field === 'phone' && isset($patch['phone'])) {
                    unset($patch['phone']);
                    if ($paisApoyo) {
                        unset($patch['country']);
                        $paisApoyo = false;
                    }
                    $telMotivo = 'ya lo tiene otro cliente: ' . $e->contactName;
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw $e;
            }

            if (isset($patch['phone'])) {
                $campos['teléfono'] = true;
            }
            $telANota = $telMotivo !== null && $notaFinal !== $nota;
            if ($telANota) {
                $fill['telNota'][] = $nombre . ' (' . $telLegacy . ': ' . $telMotivo . ')';
            }
            if ($campos !== [] || $telANota) {
                $fill['clientes']++;
            }
            foreach (array_keys($campos) as $label) {
                $fill['campos'][$label] = ($fill['campos'][$label] ?? 0) + 1;
            }
            break;
        }

        foreach (array_unique($dejados) as $label) {
            $fill['dejados'][$label] = ($fill['dejados'][$label] ?? 0) + 1;
        }
    }

    /** Resumen de lo que se completó en clientes ya importados. */
    private function avisarCompletados(array $fill): void
    {
        $conteo = static function (array $conteos, string $prefijo): string {
            arsort($conteos);
            $partes = [];
            foreach ($conteos as $label => $n) {
                $partes[] = $n . $prefijo . $label;
            }
            return implode(', ', $partes);
        };
        $ejemplos = static fn (array $lista): string
            => implode('; ', array_slice($lista, 0, 10))
            . (count($lista) > 10 ? ' … y ' . (count($lista) - 10) . ' más.' : '.');

        if ($fill['clientes'] > 0) {
            $this->note(
                $fill['clientes'] . ' cliente(s) ya importados se completaron con datos que en Punto estaban vacíos'
                . ($fill['campos'] !== [] ? ': ' . $conteo($fill['campos'], ' con ') : '') . '.'
            );
        }
        if ($fill['dejados'] !== []) {
            $this->note(
                array_sum($fill['dejados']) . ' dato(s) de clientes ya importados NO se tocaron porque en Punto ya '
                . 'tenían otro valor (puede ser una corrección hecha en Punto): '
                . $conteo($fill['dejados'], ' ') . '.'
            );
        }
        if ($fill['telNota'] !== []) {
            $this->note(
                count($fill['telNota']) . ' cliente(s) ya importados no recibieron el teléfono del sistema anterior '
                . '(inválido o de otro cliente); el número quedó en su nota. Ejemplos: ' . $ejemplos($fill['telNota'])
            );
        }
        if ($fill['docRepetido'] !== []) {
            $this->note(
                count($fill['docRepetido']) . ' cliente(s) ya importados no recibieron el documento del sistema '
                . 'anterior porque ese número ya lo tiene otro cliente de Punto. Ejemplos: '
                . $ejemplos($fill['docRepetido'])
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // suppliers — proveedores (context/77 §17.14)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Proveedores del legacy como contactos proveedor (`type = 2`), por el
     * servicio real.
     *
     * No existía: el histórico de compras buscaba al proveedor por NOMBRE
     * entre los contactos del destino —de cualquier tipo— y como los
     * proveedores nunca se habían migrado, las compras entraban sin proveedor
     * o, peor, colgadas de un cliente o un usuario homónimo (job 71e8282d:
     * 26 proveedores sin migrar).
     *
     * Va antes del histórico en el orden del job, y la idempotencia es la de
     * siempre: `migration_map` por el id del legacy (`enc(contactId)`).
     */
    private function suppliers(): void
    {
        require_once dirname(__DIR__) . '/Contacts/ContactService.php';
        require_once dirname(__DIR__) . '/Contacts/ContactRepository.php';

        global $db;
        $contacts = new \Punto\Api\Contacts\ContactService(new \Punto\Api\Contacts\ContactRepository($db));
        $dup      = self::duplicadosVacios();

        $this->each('supplier', $this->source->suppliers(), function (array $row) use ($contacts, &$dup): ?string {
            $razon     = trim((string) ($row['name'] ?? ''));
            $encargado = trim((string) ($row['contact'] ?? ''));
            if ($razon === '' && $encargado === '') {
                return null;
            }

            $in = [
                'fiscalName' => $razon !== '' ? $razon : $encargado,
                'name'       => $encargado !== '' ? $encargado : $razon,
                'type'       => \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER,
            ];
            foreach (['tin', 'phone', 'email', 'address'] as $k) {
                $v = trim((string) ($row[$k] ?? ''));
                if ($v !== '') {
                    $in[$k] = $v;
                }
            }

            return $this->crearContacto($contacts, $in, 'proveedor', $dup);
        });

        $this->avisarDuplicados('proveedor', $dup);
    }

    /** @return array{unificados:array<int,string>,telRepetido:array<int,string>,telInvalido:array<int,string>} */
    private static function duplicadosVacios(): array
    {
        return ['unificados' => [], 'telRepetido' => [], 'telInvalido' => []];
    }

    /**
     * Alta de un contacto del legacy con la política del owner para los datos
     * que Punto no acepta repetidos (decisión 2026-09-18, context/77 §17.15):
     *
     *   1. **Documento personal repetido** → es la MISMA persona cargada dos
     *      veces en el legacy. NO se crea otro contacto: el id del legacy se
     *      mapea al contacto de Punto que ya tiene ese documento, así sus
     *      ventas históricas quedan en un solo cliente.
     *   2. **Teléfono repetido con otra persona** → se importa SIN teléfono y
     *      el número original queda en la nota del contacto.
     *   3. **Teléfono inválido** → igual que 2.
     *
     * Si cae en 1 y 2 a la vez manda 1, y sale solo: `ContactService` chequea
     * el documento ANTES que el teléfono.
     *
     * Idempotente sin nada extra: el alta (o la unificación) se hace una sola
     * vez, porque `each()` la marca en `migration_map` y la corrida siguiente
     * la saltea — la nota no se vuelve a escribir.
     *
     * @param array{unificados:array<int,string>,telRepetido:array<int,string>,telInvalido:array<int,string>} $dup
     */
    private function crearContacto(
        \Punto\Api\Contacts\ContactService $svc,
        array $in,
        string $rol,
        array &$dup
    ): string {
        $nombre = (string) ($in['fiscalName'] ?? $in['name'] ?? '?');

        // 3. Inválido: se decide ANTES del alta y con la regla del servicio,
        //    no reconociendo el error por el texto del mensaje.
        if (isset($in['phone']) && !$svc->phoneIsStorable($this->companyId, $in)) {
            $dup['telInvalido'][] = $nombre . ' (' . $in['phone'] . ')';
            $in = $this->sinTelefono($in, 'el número no es válido');
        }

        try {
            return $svc->create($this->companyId, $in);
        } catch (\Punto\Api\Contacts\DuplicateContactException $e) {
            if ($e->field === 'ci') {
                // 1. La misma persona: se unifica en el contacto que ya existe.
                $dup['unificados'][] = $nombre . ' → ' . $e->contactName;
                return $e->contactId;
            }
            if ($e->field !== 'phone' || !isset($in['phone'])) {
                throw $e;
            }

            // 2. El teléfono es de OTRO contacto: entra sin él.
            $dup['telRepetido'][] = $nombre . ' (' . $in['phone'] . ', ya lo tiene ' . $e->contactName . ')';
            return $svc->create(
                $this->companyId,
                $this->sinTelefono($in, 'ya lo tiene otro ' . $rol . ': ' . $e->contactName)
            );
        }
    }

    /** El contacto sin teléfono, con el número original guardado en su nota. */
    private function sinTelefono(array $in, string $motivo): array
    {
        $linea = 'Teléfono del sistema anterior: ' . $in['phone'] . ' (no se cargó: ' . $motivo . ').';
        $nota  = trim((string) ($in['note'] ?? ''));

        unset($in['phone']);
        $in['note'] = $nota === '' ? $linea : $nota . "\n" . $linea;

        return $in;
    }

    /** Resumen de la política de duplicados: conteo y ejemplos por caso. */
    private function avisarDuplicados(string $rol, array $dup): void
    {
        $casos = [
            'unificados'  => ' se UNIFICARON con el ' . $rol . ' de Punto que ya tenía su mismo documento personal '
                . '(la misma persona cargada dos veces en el sistema anterior): no se creó otro contacto y su '
                . 'histórico queda en uno solo',
            'telRepetido' => ' entraron SIN teléfono porque ese número ya lo tiene otro ' . $rol . '; el número '
                . 'original quedó en la nota del contacto',
            'telInvalido' => ' entraron SIN teléfono porque el número no es válido; el número original quedó en la '
                . 'nota del contacto',
        ];

        foreach ($casos as $k => $texto) {
            $lista = $dup[$k] ?? [];
            if ($lista === []) {
                continue;
            }
            $this->note(
                count($lista) . ' ' . $rol . '(s)' . $texto . '. Ejemplos: '
                . implode('; ', array_slice($lista, 0, 10))
                . (count($lista) > 10 ? ' … y ' . (count($lista) - 10) . ' más.' : '.')
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // config — sucursales y cajas (D5)
    // ═══════════════════════════════════════════════════════════════════

    private function config(array $options): void
    {
        require_once dirname(__DIR__) . '/Outlets/OutletsService.php';
        require_once dirname(__DIR__) . '/services/RegisterAdminService.php';

        global $db;

        // ── Sucursales ───────────────────────────────────────────────────
        $outlets = new \Punto\Api\Outlets\OutletsService();

        $legacyOutlets = $this->source->outlets();

        // Regla del owner (2026-09-18): el destino SIEMPRE tiene al menos una
        // sucursal (la crea el alta) y el migrador NUNCA duplica. Cada sucursal
        // del legacy, en orden: 1) ya mapeada → se usa; 2) homónima libre → se
        // reusa; 3) cualquier otra libre → la más antigua; 4) todas tomadas →
        // se crea. Cada caso deja su línea en la bitácora. Ver context/77 §11.2.
        //
        // Los nombres de las del legacy TODAVÍA sin mapear quedan reservados:
        // la regla 3 no le puede dar a una sucursal la homónima de OTRA que se
        // procesa después (el orden del export no se controla).
        $reservados = [];
        foreach ($legacyOutlets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $legacyId = $this->legacyIdOf($row);
            $ya = $legacyId !== null ? EncomMigrationService::mapped($this->companyId, 'outlet', $legacyId) : null;
            if ($ya === null) {
                if ($legacyId !== null) {
                    $reservados[$legacyId] = self::claveSucursal((string) ($row['name'] ?? ''));
                }
            } else {
                $this->note(
                    'La sucursal "' . trim((string) ($row['name'] ?? '')) . '" del sistema anterior ya estaba '
                    . 'migrada a "' . $this->nombreSucursal($ya) . '": se usa esa.'
                );
            }
        }

        $this->each('outlet', $legacyOutlets, function (array $row) use ($outlets, &$reservados): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $fields = [
                'name'        => $name,
                'address'     => trim((string) ($row['address'] ?? '')),
                'email'       => trim((string) ($row['email'] ?? '')),
                'billingName' => trim((string) ($row['billingName'] ?? '')),
                'ruc'         => trim((string) ($row['tin'] ?? '')),
                'status'      => 1,
            ];

            // El teléfono se valida con libphonenumber y LANZA si no parsea. Un
            // teléfono mal cargado en el legacy no puede costar la sucursal
            // entera: se manda solo si viene, y si el servicio lo rechaza se
            // reintenta sin él dejando la nota.
            $phone = trim((string) ($row['phone'] ?? ''));

            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $fields['lat'] = (float) $lat;
                $fields['lng'] = (float) $lng;
            }

            // ¿Hay una sucursal del destino libre para esta? El alta crea
            // "Central" (con su depósito y su caja), y crear otra al lado dejaba
            // al comercio con dos "Central": las cajas y el stock en una, el
            // histórico en la otra (caso real 2026-09-18).
            // Esta deja de reservar su nombre: se resuelve ahora, sea como sea.
            unset($reservados[(string) $this->legacyIdOf($row)]);
            $existente = $this->sucursalExistentePara($name, array_values($reservados));
            if ($existente !== null) {
                $legacyId = (string) $this->legacyIdOf($row);
                $this->completarSucursalReusada($outlets, $existente['id'], $fields, $phone);
                // Marca DURABLE de "esta sucursal ya existía": `registers()` la
                // lee para no convertir una caja del comercio en una del
                // legacy, también en un relanzamiento.
                EncomMigrationService::remember($this->companyId, 'outlet_reused', $legacyId, $existente['id'], $this->jobId);
                $this->note(
                    'La sucursal "' . $name . '" del sistema anterior se unió a la sucursal existente "'
                    . $existente['name'] . '"'
                    . ($existente['porNombre'] ? ' (mismo nombre)' : ' (era la más antigua todavía sin asignar)')
                    . '; no se creó otra. Sus cajas se crean dentro de ella.'
                );
                return $existente['id'];
            }

            // `ORIGIN_SUPPORT`: la migración la opera Punto, no el comercio —
            // estas sucursales YA existían en el sistema anterior, así que no
            // pasan por la solicitud con paywall (mig 219).
            $origin = \Punto\Api\Outlets\OutletsService::ORIGIN_SUPPORT;

            try {
                $id = $outlets->create($this->companyId, $phone !== '' ? $fields + ['phone' => $phone] : $fields, $origin);
            } catch (\DomainException $e) {
                // El gate de origen NO es un teléfono inválido: reintentar sin
                // teléfono lo volvería a rechazar y escondería el motivo real.
                throw $e;
            } catch (\Throwable $e) {
                if ($phone === '') {
                    throw $e;
                }
                $this->note('La sucursal "' . $name . '" se importó sin teléfono: el legacy tenía "' . $phone . '", que no es un número válido.');
                $id = $outlets->create($this->companyId, $fields, $origin);
            }

            if (is_string($id) && $id !== '') {
                $this->note(
                    'La sucursal "' . $name . '" del sistema anterior se creó nueva: todas las sucursales que '
                    . 'ya había en Punto quedaron asignadas a otras del sistema anterior.'
                );
                return $id;
            }
            return null;
        });

        // El horario de atención (`weekHours`) viene en el export pero NO se
        // importa: el `outlet` de Punto no tiene un modelo de horarios
        // mantenido (la columna `data` lo menciona, ningún servicio lo escribe
        // ni lo lee). Inventar una forma de guardarlo acá sería crear un campo
        // que solo el migrador conoce. Ver context/77 §8.
        $this->note('El horario de atención de las sucursales no se migra: Punto todavía no tiene dónde guardarlo.');

        // ── Cajas ────────────────────────────────────────────────────────
        $this->registers($options);
    }

    /**
     * La sucursal del destino que toma una del legacy SIN crear otra, o null
     * si hay que crearla. Solo se llama cuando ese id del legacy todavía no
     * está en `migration_map` (eso lo resuelve `each()` antes). "Libre" =
     * activa y no mapeada a ninguna sucursal del legacy.
     *
     *   1. Libre y con el MISMO nombre (sin distinguir mayúsculas ni espacios).
     *   2. Si no: la libre más antigua (la del alta, típicamente).
     *   3. Ninguna libre → null: todas ya son de otras sucursales del legacy.
     *
     * Ante empate, la más antigua: es la que el comercio más probablemente ya
     * usa, y el orden es determinístico al relanzar.
     *
     * @return array{id: string, name: string, porNombre: bool}|null
     */
    private function sucursalExistentePara(string $name, array $reservados): ?array
    {
        global $db;

        $libres = [];
        $rs = $db->Execute(
            "SELECT o.outletId AS id, o.outletName AS name
               FROM outlet o
              WHERE o.companyId = ? AND o.outletStatus = 1
                AND NOT EXISTS (
                      SELECT 1 FROM migration_map m
                       WHERE m.companyid = o.companyId
                         AND m.domain    = 'outlet'
                         AND m.puntoid   = o.outletId
                )
              ORDER BY o.outletCreationDate, o.outletId",
            [$this->companyId]
        );
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $libres[] = ['id' => (string) ($f['id'] ?? ''), 'name' => (string) ($f['name'] ?? '')];
                $rs->MoveNext();
            }
        }

        $clave = self::claveSucursal($name);
        foreach ($libres as $o) {
            if ($o['id'] !== '' && self::claveSucursal($o['name']) === $clave) {
                return $o + ['porNombre' => true];
            }
        }

        // Sin homónima: la libre más antigua que no sea la homónima de OTRA
        // sucursal del legacy que todavía falta procesar.
        $otras = $reservados;
        foreach ($libres as $o) {
            if ($o['id'] !== '' && !in_array(self::claveSucursal($o['name']), $otras, true)) {
                return $o + ['porNombre' => false];
            }
        }

        return null;
    }

    /** Nombre de sucursal normalizado: sin mayúsculas ni espacios de más. */
    private static function claveSucursal(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** Nombre de una sucursal del destino, para la bitácora. */
    private function nombreSucursal(string $outletId): string
    {
        $row = ncmExecute(
            'SELECT outletName AS name FROM outlet WHERE companyId = ? AND outletId = ? LIMIT 1',
            [$this->companyId, $outletId]
        );
        return $row ? (string) ($row['name'] ?? '') : '';
    }

    /**
     * Completa la sucursal reusada con lo que el legacy trae y ella NO tiene
     * (razón social, RUC, dirección…). Nunca pisa un dato cargado: la sucursal
     * ya es del comercio, y el nombre se conserva.
     */
    private function completarSucursalReusada(
        \Punto\Api\Outlets\OutletsService $outlets,
        string $outletId,
        array $fields,
        string $phone
    ): void {
        $actual = $outlets->get($outletId, $this->companyId) ?? [];

        $patch = [];
        foreach (['address', 'email', 'billingName', 'ruc'] as $k) {
            if (($fields[$k] ?? '') !== '' && trim((string) ($actual[$k] ?? '')) === '') {
                $patch[$k] = $fields[$k];
            }
        }
        if (isset($fields['lat'], $fields['lng']) && ($actual['lat'] ?? null) === null && ($actual['lng'] ?? null) === null) {
            $patch['lat'] = $fields['lat'];
            $patch['lng'] = $fields['lng'];
        }
        if ($patch !== []) {
            $outlets->update($outletId, $this->companyId, $patch);
        }

        if ($phone !== '' && trim((string) ($actual['phone'] ?? '')) === '') {
            try {
                $outlets->update($outletId, $this->companyId, ['phone' => $phone]);
            } catch (\Throwable $e) {
                $this->note('La sucursal "' . (string) ($actual['name'] ?? '') . '" quedó sin teléfono: el legacy tenía "' . $phone . '", que no es un número válido.');
            }
        }
    }

    /** Si la sucursal ya existía en el destino y el migrador la reusó. */
    private function esSucursalReusada(string $outletId): bool
    {
        $row = ncmExecute(
            "SELECT 1 AS si FROM migration_map
              WHERE companyid = ? AND domain = 'outlet_reused' AND puntoid = ?
              LIMIT 1",
            [$this->companyId, $outletId]
        );
        return (bool) $row;
    }

    /**
     * Importa las cajas CONTINUANDO su serie fiscal (D5).
     *
     * La caja de Punto nace con el timbrado y el punto de expedición del
     * legacy, y su `document_sequence` arranca en "último emitido + 1". El
     * contador del legacy guarda el ÚLTIMO número usado; el de Punto guarda el
     * PRÓXIMO (mig 117). Esa asimetría es precisamente el +1 — no es un margen
     * de seguridad.
     *
     * Desde que el export sale de `/fetchs`, ese "último emitido" ya no se lee
     * de un campo de texto de un formulario: viene en `docsNum`, la misma
     * estructura con la que el POS legacy numera sus documentos, y trae un
     * contador POR TIPO (factura, cotización, devolución...).
     *
     * ── Validación DURA, antes de crear nada ─────────────────────────────
     * Dos cajas con el mismo (timbrado, punto de expedición) llevarían la misma
     * secuencia y terminarían emitiendo dos facturas con el mismo número:
     * documento duplicado, ilegal ante la SET (context/29 §2). Por eso el
     * chequeo corre sobre TODO el lote ANTES de crear la primera caja y aborta
     * el dominio entero.
     */
    private function registers(array $options): void
    {
        global $db;

        $rows = $this->source->registers();
        $this->progress['register'] = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        if ($rows === []) {
            return;
        }

        // Solo las que faltan: una caja ya importada no se revalida (su punto
        // de expedición ya está tomado POR ELLA MISMA y daría un falso choque).
        $pending = [];
        foreach ($rows as $row) {
            $legacyId = $this->legacyIdOf($row);
            if ($legacyId === null) {
                $this->progress['register']['failed']++;
                continue;
            }
            if (EncomMigrationService::mapped($this->companyId, 'register', $legacyId) !== null) {
                $this->progress['register']['skipped']++;
                continue;
            }
            $pending[] = $row;
        }

        if ($pending === []) {
            return;
        }

        $this->assertExpeditionPointsFree($pending);

        // Sucursal destino de cada caja: la que corresponde a su sucursal del
        // legacy. Si el mapa no la tiene (no se migró `config`, o la sucursal
        // falló), se usa la que el operador eligió; sin ninguna de las dos NO se
        // inventa (memoria: prohibido resolver una dimensión faltante con "el
        // primer outlet activo").
        $fallbackOutlet = trim((string) ($options['registerOutletId'] ?? ''));

        foreach ($pending as $row) {
            $legacyId = (string) $this->legacyIdOf($row);
            $name     = trim((string) ($row['name'] ?? '')) ?: 'Caja';

            try {
                $outletId = $this->mapOf('outlet', $row['outletLegacyId'] ?? null) ?: $fallbackOutlet;
                if ($outletId === '') {
                    throw new \RuntimeException(
                        'no se sabe a qué sucursal pertenece: migrá también la configuración, o elegí una sucursal destino para las cajas'
                    );
                }

                $extra = $this->fiscalExtraFor($row);

                $svc = new \Punto\Api\Services\RegisterAdminService($this->companyId);

                // `OutletsService::create()` deja una caja placeholder ("Nueva
                // Caja", sin timbrado) para cumplir el invariante "sucursal sin
                // caja no existe". Se REUSA para la primera caja importada de
                // esa sucursal en vez de crear otra al lado: si no, cada
                // sucursal migrada queda con una caja fantasma que el comercio
                // tiene que borrar a mano.
                //
                // En una sucursal que YA existía (la reusó `config`) no hay
                // placeholder: su caja es del comercio —puede estar pareada y
                // haber vendido— y convertirla en una caja del legacy sería
                // fusionarlas. Las del legacy se crean al lado.
                $placeholder = $this->esSucursalReusada($outletId) ? null : $this->freePlaceholderRegister($outletId);
                if ($placeholder !== null) {
                    $svc->update($placeholder, ['name' => $name] + $extra);
                    $registerId = $placeholder;
                } else {
                    $created    = $svc->create($outletId, $name, $extra);
                    $registerId = (string) ($created['id'] ?? '');
                }

                if ($registerId === '') {
                    throw new \RuntimeException('no se pudo crear la caja');
                }

                EncomMigrationService::remember($this->companyId, 'register', $legacyId, $registerId, $this->jobId);
                $this->progress['register']['imported']++;
            } catch (\Throwable $e) {
                $this->progress['register']['failed']++;
                $this->fail('config', 'Caja "' . $name . '": ' . $e->getMessage());
            }
        }
    }

    /**
     * Aborta si dos cajas del lote comparten (timbrado, punto de expedición), o
     * si alguna choca con una caja ACTIVA que ya existe en el destino.
     */
    private function assertExpeditionPointsFree(array $rows): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $auth   = $this->digits($row['invoiceAuth'] ?? '');
            $prefix = trim((string) ($row['prefix'] ?? ''));
            $name   = trim((string) ($row['name'] ?? '')) ?: 'sin nombre';

            // Sin timbrado no hay serie fiscal que pueda chocar: esa caja entra
            // sin punto de expedición y el comercio lo carga después.
            if ($auth === '' || $prefix === '') {
                continue;
            }

            if (!preg_match('/^\d{3}-\d{3}$/', $prefix)) {
                throw new EncomMigrationException(
                    'La caja "' . $name . '" tiene el punto de expedición "' . $prefix . '", que no tiene el formato EEE-PPP ' .
                    '(ej. 001-001). No se importa ninguna caja: corregilo en el legacy y volvé a lanzar la migración.',
                    422
                );
            }

            $key = $auth . '|' . $prefix;
            if (isset($seen[$key])) {
                throw new EncomMigrationException(
                    'Las cajas "' . $seen[$key] . '" y "' . $name . '" comparten el timbrado ' . $auth .
                    ' y el punto de expedición ' . $prefix . '. Emitirían facturas con el mismo número, así que no se ' .
                    'importa ninguna caja: resolvelo en el legacy y volvé a lanzar la migración.',
                    409
                );
            }
            $seen[$key] = $name;

            $clash = ncmExecute(
                "SELECT registerName FROM register
                  WHERE companyId = ? AND registerStatus = TRUE
                    AND data ->> 'registerInvoiceAuth' = ?
                    AND data ->> 'registerInvoicePrefix' = ?
                  LIMIT 1",
                [$this->companyId, $auth, $prefix]
            );
            if ($clash) {
                $other = (string) ($clash['registerName'] ?? $clash['registername'] ?? '');
                throw new EncomMigrationException(
                    'La caja "' . $name . '" usa el timbrado ' . $auth . ' con el punto de expedición ' . $prefix .
                    ', que en Punto ya tiene la caja "' . $other . '". No se importa ninguna caja: resolvelo antes de migrar.',
                    409
                );
            }
        }
    }

    /**
     * Traduce el bloque fiscal del legacy al shape de `RegisterAdminService`.
     *
     * El `+1` del correlativo es la continuación de la serie: el contador del
     * legacy es el ÚLTIMO emitido y el de Punto el PRÓXIMO a emitir.
     *
     * `docsNum` del legacy trae SIETE contadores (factura, ticket, orden,
     * cotización, devolución, remisión, agenda) y Punto tiene secuencia
     * numerada para TRES: factura, cotización y nota de crédito
     * (`RegisterAdminService::DOC_TYPES`). Mandar cualquier otro hace que ese
     * servicio rechace el alta entera con "Tipo de documento desconocido", así
     * que se mapean solo esos tres y el resto se declara en la bitácora — no se
     * inventa una secuencia para un documento que Punto no numera por caja.
     */
    private function fiscalExtraFor(array $row): array
    {
        $auth   = $this->digits($row['invoiceAuth'] ?? '');
        $prefix = trim((string) ($row['prefix'] ?? ''));
        $last   = (int) $this->digits($row['invoiceNo'] ?? '0');

        $extra = [
            'fiscal' => [
                'invoiceAuth'   => $auth,
                'invoicePrefix' => $prefix,
            ],
            'numbering' => [
                'factura' => (string) max(1, $last + 1),
            ],
        ];

        // La cotización no tiene serie fiscal y la nota de crédito hereda la de
        // la factura (mig 215): las dos continúan su propio correlativo.
        foreach (['quoteNo' => 'cotizacion', 'returnNo' => 'nota_credito'] as $src => $docType) {
            $n = (int) $this->digits($row[$src] ?? '0');
            if ($n > 0) {
                $extra['numbering'][$docType] = (string) ($n + 1);
            }
        }

        $exp = trim((string) ($row['invoiceAuthExp'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) {
            $extra['fiscal']['invoiceAuthExpiration'] = substr($exp, 0, 10);
        }

        // Ancho de impresión del correlativo: es FORMATO, va aparte del número
        // (mig 159 / context/29 §1).
        $zeros = (int) $this->digits($row['docsZeros'] ?? '');
        if ($zeros >= 1 && $zeros <= 12) {
            $extra['padWidth'] = ['factura' => (string) $zeros];
        }

        return $extra;
    }

    /**
     * La caja placeholder que `OutletsService::create()` dejó en la sucursal:
     * activa, sin timbrado y sin mapear a ninguna caja del legacy.
     */
    private function freePlaceholderRegister(string $outletId): ?string
    {
        $row = ncmExecute(
            "SELECT r.registerId
               FROM register r
              WHERE r.companyId = ? AND r.outletId = ? AND r.registerStatus = TRUE
                AND COALESCE(r.data ->> 'registerInvoiceAuth', '') = ''
                AND NOT EXISTS (
                      SELECT 1 FROM migration_map m
                       WHERE m.companyid = r.companyId
                         AND m.domain    = 'register'
                         AND m.puntoid   = r.registerId
                )
              ORDER BY r.registerCreationDate
              LIMIT 1",
            [$this->companyId, $outletId]
        );
        if (!$row) {
            return null;
        }
        $id = (string) ($row['registerId'] ?? $row['registerid'] ?? '');
        return $id !== '' ? $id : null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // users — el equipo del comercio
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Importa los usuarios con su PIN de caja y un ROL de Punto.
     *
     * ── Por qué el rol y no los permisos ─────────────────────────────────
     * El legacy manda un objeto `permissions` anidado
     * (`{register: {access, orders: {create, edit, view}, ...}}`) cuya forma no
     * tiene ninguna relación con las permission keys de Punto
     * (`pos.sale.create`, `contacts.user.view`, …). Traducirlo clave por clave
     * es adivinar, y adivinar de más significa darle a un cajero un permiso que
     * nunca tuvo — el tipo de error que nadie descubre hasta que alguien anula
     * una venta que no debía.
     *
     * Entonces: se asigna el ROL de Punto más parecido POR NOMBRE y se deja que
     * el rol traiga sus permisos. Ante la duda, el rol más bajo (`cashier`):
     * agregarle permisos a un usuario es un clic en el panel, sacárselos
     * después de que operó, no.
     *
     * Cada asignación queda escrita en la bitácora del job con el nombre del
     * rol legacy y el de Punto, para que soporte la revise con el comercio.
     *
     * ── La contraseña del panel NO se migra ──────────────────────────────
     * El legacy guarda un hash con otro algoritmo y otra sal: no se puede
     * reusar, y pedirla en claro sería peor. Cada usuario nace con una
     * contraseña aleatoria que nadie conoce — entra al POS con su PIN, que sí
     * se migra, y la del panel se restablece desde Equipo.
     */
    private function users(): void
    {
        require_once dirname(__DIR__) . '/Users/UsersService.php';
        require_once dirname(__DIR__) . '/Auth/RoleService.php';

        $svc = new \Punto\Api\Users\UsersService();

        // `RoleService` vive en el namespace GLOBAL, no en `Punto\Api\Auth`
        // como el resto de `api/lib/Auth/` — de ahí la barra sola. Llamarlo con
        // el namespace "obvio" tira "class not found" y se lleva puesto el
        // dominio entero.
        //
        // `getRoles()` siembra los roles del tenant si todavía no los tiene y
        // devuelve vacío esa primera vez (no reintenta solo), así que la segunda
        // llamada es la que trae los roles recién sembrados: una empresa recién
        // creada igual tiene contra qué mapear.
        $roles = \RoleService::getRoles($this->companyId);
        if ($roles === []) {
            $roles = \RoleService::getRoles($this->companyId);
        }
        if ($roles === []) {
            throw new EncomMigrationException(
                'La empresa destino no tiene roles configurados y no se pudieron sembrar: no se importan usuarios '
                . 'para no crearlos sin permisos.',
                500
            );
        }

        $this->note(
            'Los usuarios se crean con una contraseña de panel aleatoria: entran a la caja con su PIN '
            . '(que sí se migra) y la contraseña se restablece desde Equipo.'
        );

        $this->each('user', $this->source->users(), function (array $row) use ($svc, $roles): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $role = $this->roleFor($roles, (string) ($row['roleName'] ?? ''));

            $in = [
                'name'     => $name,
                // 32 hex = 128 bits. No se loguea ni se devuelve: es
                // deliberadamente irrecuperable.
                'password' => bin2hex(random_bytes(16)),
                'roleId'   => $role['id'],
            ];

            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $in['email'] = $email;
            }
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone !== '') {
                $in['phone'] = $phone;
            }
            $color = trim((string) ($row['color'] ?? ''));
            if ($color !== '') {
                $in['color'] = $color;
            }

            // PIN de la caja. Punto exige 4 dígitos y que no lo tenga otro
            // usuario ACTIVO: las dos cosas las valida `UsersService`, que
            // lanza. Se manda solo si tiene la forma correcta.
            $lockPass = preg_replace('/\D/', '', (string) ($row['lockPass'] ?? '')) ?? '';
            if (strlen($lockPass) === 4) {
                $in['lockPass'] = $lockPass;
            }

            // Sucursales: las del legacy, por el mapa. Sin fila en
            // `contact_outlet` el usuario es GLOBAL (context/25), que es
            // exactamente lo que significa un usuario sin sucursal del otro
            // lado.
            $outletId = $this->mapOf('outlet', $row['outletLegacyId'] ?? null);
            if ($outletId !== '') {
                $in['outletIds'] = [$outletId];
            }

            $id = $this->createUserTolerando($svc, $in, $name);

            $this->note(
                'Usuario "' . $name . '": rol del legacy "'
                . (trim((string) ($row['roleName'] ?? '')) ?: 'sin rol') . '" → rol de Punto "' . $role['name'] . '"'
                . (($role['slug'] ?? null) === 'owner'
                    ? ' (con TODOS los permisos: confirmá que corresponde, por ejemplo que no sea una cuenta de soporte del sistema anterior).'
                    : '.')
            );

            return $id;
        });
    }

    /**
     * Crea el usuario y, si el alta se cae por un dato OPCIONAL que el legacy
     * traía sucio, reintenta sin él dejando la nota.
     *
     * Los tres casos reales: un email que ya tiene otro usuario, un PIN que ya
     * tiene otro usuario, y un teléfono que libphonenumber no parsea. Ninguno
     * justifica perder al usuario entero —el nombre y el rol son lo que
     * importa— pero tampoco se pisan en silencio: cada uno deja su línea en la
     * bitácora para que soporte lo complete.
     */
    private function createUserTolerando(\Punto\Api\Users\UsersService $svc, array $in, string $name): string
    {
        try {
            return $svc->create($this->companyId, $in);
        } catch (\Throwable $e) {
            $opcionales = array_intersect_key($in, array_flip(['email', 'phone', 'lockPass', 'color']));
            if ($opcionales === []) {
                throw $e;
            }

            $this->note(
                'El usuario "' . $name . '" se importó sin ' . implode(', ', array_keys($opcionales))
                . ': el legacy los tenía, pero Punto los rechazó (' . $e->getMessage() . '). Cargalos a mano.'
            );

            foreach (array_keys($opcionales) as $k) {
                unset($in[$k]);
            }
            return $svc->create($this->companyId, $in);
        }
    }

    /**
     * Rol de Punto más cercano al del legacy, por NOMBRE.
     *
     * Primero el nombre exacto (un comercio que ya tenía "Cajero" del otro lado
     * matchea con el "Cajero" sembrado acá), después por palabra clave, y ante
     * la duda el rol MÁS BAJO que exista. Nunca `device`: ese rol no es para
     * una persona, lo lleva la sesión de un dispositivo pareado.
     *
     * @param array<int,array{id:string,name:string,slug:?string}> $roles
     * @return array{id:string,name:string,slug:?string}
     */
    private function roleFor(array $roles, string $legacyRoleName): array
    {
        $asignables = array_values(array_filter($roles, static fn(array $r) => ($r['slug'] ?? null) !== 'device'));
        if ($asignables === []) {
            $asignables = $roles;
        }

        $bySlug = [];
        foreach ($asignables as $r) {
            if (($r['slug'] ?? null) !== null) {
                $bySlug[(string) $r['slug']] = $r;
            }
        }

        $legacy = mb_strtolower(trim($legacyRoleName), 'UTF-8');

        // 1. Nombre idéntico.
        foreach ($asignables as $r) {
            if ($legacy !== '' && mb_strtolower(trim((string) $r['name']), 'UTF-8') === $legacy) {
                return ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'slug' => $r['slug'] ?? null];
            }
        }

        // 2. Palabra clave → slug. El "administrador" del legacy cae en
        //    `manager` y no en `owner`, igual que en el mapa de roles legacy de
        //    `RoleService`.
        //
        //    "Jefe" SÍ es `owner` (decisión del owner, 2026-09-18): en el
        //    legacy es el rol más alto —la escala es Cajero Base < Cajero <
        //    Admin. Base < Administrador < Jefe— y lo tiene el administrador
        //    principal del comercio. Antes no matcheaba ninguna palabra y caía
        //    al rol MÁS BAJO: el dueño del comercio entraba como Cajero.
        //    Ojo: la cuenta de soporte del legacy también suele ser "Jefe" y
        //    entra como Dueño por esta misma regla; la bitácora la nombra.
        $porSlug = null;
        if ($legacy !== '') {
            foreach ([
                'owner'   => ['dueñ', 'duen', 'owner', 'propietar', 'titular', 'jefe'],
                'manager' => ['encargad', 'gerent', 'supervis', 'manager', 'admin'],
                'cashier' => ['cajer', 'vendedor', 'mozo', 'cashier', 'empleado', 'moso'],
            ] as $slug => $palabras) {
                foreach ($palabras as $p) {
                    if (str_contains($legacy, $p)) {
                        $porSlug = $slug;
                        break 2;
                    }
                }
            }
        }
        if ($porSlug !== null && isset($bySlug[$porSlug])) {
            return ['id' => (string) $bySlug[$porSlug]['id'], 'name' => (string) $bySlug[$porSlug]['name'], 'slug' => $porSlug];
        }

        // 3. El más bajo que exista.
        foreach (['cashier', 'manager', 'owner'] as $slug) {
            if (isset($bySlug[$slug])) {
                return ['id' => (string) $bySlug[$slug]['id'], 'name' => (string) $bySlug[$slug]['name'], 'slug' => $slug];
            }
        }

        $primero = $asignables[0];
        return ['id' => (string) $primero['id'], 'name' => (string) $primero['name'], 'slug' => $primero['slug'] ?? null];
    }

    // ═══════════════════════════════════════════════════════════════════
    // payments — medios de pago
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Importa los medios de pago que el comercio tenía configurados.
     *
     * `ensureSeed()` primero: un tenant nuevo tiene que quedar con Efectivo,
     * tarjetas, giftcard y cheque —los que disparan flujos propios del POS por
     * su `systemKey`— pase lo que pase con el export. Recién después se suman
     * los del legacy.
     *
     * Un medio que YA existe en el destino con el mismo nombre se ADOPTA (se
     * mapea al existente) en vez de crear un homónimo: `taxonomy` tiene UNIQUE
     * por (empresa, tipo, nombre) y "Efectivo" además es único por diseño, así
     * que duplicarlo no es posible ni deseable.
     */
    private function payments(): void
    {
        require_once dirname(__DIR__) . '/PaymentMethods/PaymentMethodService.php';

        global $db;
        $svc = new \Punto\Api\PaymentMethods\PaymentMethodService($db);
        $svc->ensureSeed($this->companyId);

        $existentes = [];
        foreach ($svc->list($this->companyId) as $m) {
            $existentes[mb_strtolower(trim((string) $m['name']), 'UTF-8')] = (string) $m['id'];
        }

        $adoptados = [];

        $this->each('payment', $this->source->paymentMethods(), function (array $row) use ($svc, &$existentes, &$adoptados): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $key = mb_strtolower($name, 'UTF-8');
            if (isset($existentes[$key])) {
                $adoptados[] = $name;
                return $existentes[$key];
            }

            $id = $svc->create($this->companyId, [
                'name' => $name,
                'code' => mb_substr(trim((string) ($row['code'] ?? '')), 0, 1, 'UTF-8'),
            ]);

            $existentes[$key] = $id;
            return $id;
        });

        if ($adoptados !== []) {
            $this->note(
                'Medios de pago que ya existían en Punto y se reusaron en vez de duplicarse: '
                . implode(', ', array_unique($adoptados)) . '.'
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // stock — APERTURA de inventario (context/52, context/77)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Abre el inventario de cada artículo en cada sucursal: cantidad y costo.
     *
     * ── Por qué existe (y por qué `item.itemCost` no alcanzaba) ──────────
     * El migrador ya importaba `item.itemCost`, pero ESE CAMPO NO SE USA AL
     * VENDER: `SaleService::resolveUnitCOGS()` lee el costo promedio ponderado
     * que dejó el ÚLTIMO movimiento del ledger
     * (`getItemStock(...)['stockOnHandCOGS']`). Sin un solo movimiento, el COGS
     * de la venta es null y TODO reporte de margen del comercio migrado nace
     * vacío. Cantidad y costo son la misma operación —una apertura— y por eso
     * se importan juntos.
     *
     * ── Por el servicio real, nunca INSERT (D4) ──────────────────────────
     * `Inventory::manageStock()` es el ÚNICO escritor del ledger (D1/D6 de
     * context/52). El contrato que se copia es el del ajuste que ya existe
     * (`StockAdjustmentService::create()` y `ItemImporter::cargarStockInicial()`,
     * que hace exactamente esto para la planilla de alta): `source='adjustment'`.
     *
     * No se inventa un `source` nuevo: los lectores que FILTRAN por `stockSource`
     * buscan valores concretos (`production`, `purchase_credit_note`) y los que
     * lo MUESTRAN lo traducen con una tabla cerrada —`adjustment` → "Ajuste" en
     * el reporte de inventario, "Ajuste manual" en la ficha del ítem—. Un valor
     * nuevo saldría crudo en pantalla y no lo entendería ningún reporte.
     *
     * ── El costo: NUNCA un 0 que se lea como "cuesta cero" ───────────────
     * Verificado EMPÍRICAMENTE contra Postgres real antes de elegir:
     *
     *   · `manageStock()` con el costo desconocido NO guarda NULL: lo escribe
     *     como 0.00 en `stockCOGS` y en `stockOnHandCOGS`.
     *   · Con esa fila, `resolveUnitCOGS()` devuelve 0.0 —no null—, así que la
     *     venta ESCRIBE `itemSoldCOGS = 0` y el margen sale 100%.
     *   · Sin ninguna fila, devuelve null y la venta OMITE la columna, que es
     *     exactamente lo que `SaleService` ya hace a propósito.
     *   · La columna `stock.stockCOGS` sí acepta NULL escrito a mano, pero ese
     *     NULL NO SOBREVIVE: al primer movimiento posterior el promedio móvil
     *     lo castea a 0 y lo propaga al snapshot nuevo. O sea que "costo sin
     *     definir" no es un estado que el ledger sepa sostener.
     *
     * Por eso, artículo sin costo conocido ⇒ **NO se abre** (opción (b) del
     * plan). Queda nombrado en la bitácora, con su cantidad, para que soporte
     * le cargue el costo y vuelva a lanzar: la corrida siguiente lo abre sin
     * tocar los que ya estaban. Es el mismo criterio que `itemCost` (§4.4): "no
     * lo sé" y "cuesta cero" no son lo mismo.
     *
     * ── Por sucursal ─────────────────────────────────────────────────────
     * Un saldo vive en UNA sucursal. `/fetchs` contesta el bootstrap de una
     * caja, así que el saldo de cada sucursal se pide con su propio `outletId`
     * (`itemStock()`), y entra en la sucursal de Punto que le corresponde por
     * el mapa. Una sucursal sin mapear NO se resuelve con "la primera activa":
     * se saltea y se dice en la bitácora.
     *
     * ── Idempotencia: acá duplicar es PLATA ──────────────────────────────
     * La marca es por (artículo, sucursal) en el dominio `stock_opening`, y el
     * movimiento y su marca van en la MISMA transacción. Sin eso, un worker que
     * muere entre las dos escrituras dejaría el movimiento sin marcar y la
     * corrida siguiente lo SUMARÍA de nuevo: el mismo riesgo que las recetas
     * (§6), pero peor, porque acá el duplicado es stock que el comercio cree
     * tener.
     */
    private function stockOpening(): void
    {
        require_once dirname(__DIR__) . '/Taxonomies/LocationTaxonomyService.php';

        global $db;

        $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        $locations = new \Punto\Api\Taxonomies\LocationTaxonomyService($db);
        $catalogo  = $this->puntoItemIndex();

        /** @var array<int,string> Artículos con saldo que se quedaron sin abrir. */
        $sinCosto  = [];
        $sinMapear = [];
        $sinControl = [];

        foreach ($this->source->outlets() as $o) {
            if (!is_array($o)) {
                continue;
            }

            $legacyOutlet = $this->legacyIdOf($o);
            if ($legacyOutlet === null) {
                continue;
            }

            $nombreSucursal = trim((string) ($o['name'] ?? '')) ?: $legacyOutlet;

            // Prohibido resolver una dimensión faltante con "la primera activa"
            // (memoria del proyecto): sin sucursal mapeada no hay dónde imputar
            // el saldo, y meterlo en otra sucursal es peor que no meterlo.
            $outletId = $this->mapOf('outlet', $legacyOutlet);
            if ($outletId === '') {
                $this->note(
                    'La sucursal "' . $nombreSucursal . '" no está mapeada, así que su stock no se abre: '
                    . 'migrá también la configuración y volvé a lanzar.'
                );
                continue;
            }

            // El stock siempre está en un depósito (D8 de context/52). El de
            // por defecto es el que el panel preselecciona; si la sucursal no
            // tuviera ninguno, el ledger acepta NULL y los lectores lo
            // consolidan igual.
            $default    = $locations->defaultFor($this->companyId, $outletId);
            $locationId = $default['id'] ?? null;

            try {
                $saldos = $this->source->itemStock($legacyOutlet);
            } catch (\Throwable $e) {
                $this->fail(
                    'stock',
                    'No se pudo traer el stock de la sucursal "' . $nombreSucursal . '": ' . $e->getMessage()
                );
                continue;
            }

            foreach ($saldos as $fila) {
                if (!is_array($fila)) {
                    continue;
                }

                $legacyItem = $this->legacyIdOf($fila);
                if ($legacyItem === null) {
                    continue;
                }

                $cantidad = $this->numOrNull($fila['count'] ?? null) ?? 0.0;

                // Saldo 0 (o negativo) no es una apertura: no hay movimiento
                // que registrar. `manageStock()` además lo trataría como no-op.
                if ($cantidad <= 0) {
                    continue;
                }

                $counts['total']++;

                $marca = $this->openingKey($legacyItem, $legacyOutlet);
                if (EncomMigrationService::mapped($this->companyId, 'stock_opening', $marca) !== null) {
                    $counts['skipped']++;
                    continue;
                }

                $itemId = $this->mapOf('item', $legacyItem);
                if ($itemId === '' || !isset($catalogo[$itemId])) {
                    $counts['failed']++;
                    $sinMapear[] = $legacyItem;
                    continue;
                }

                $articulo = $catalogo[$itemId];

                // Solo los artículos con stock PROPIO. Un servicio, un combo o
                // una producción no llevan apertura: su costo se calcula por
                // explosión de receta (`RecipeCosting`), no por saldo. Manda el
                // artículo YA migrado, no el flag del legacy.
                if (!$articulo['track']) {
                    // Se nombra: un "omitido" sin explicación en el progreso
                    // no le dice a soporte si falta algo (job 71e8282d).
                    $counts['skipped']++;
                    $sinControl[] = $articulo['name'] . ' (' . $nombreSucursal . ': ' . $this->cantidad($cantidad) . ')';
                    continue;
                }

                // Ver el docblock: un 0 acá pinta margen 100% para siempre.
                if ($articulo['cost'] === null) {
                    $counts['failed']++;
                    $sinCosto[] = $articulo['name'] . ' (' . $nombreSucursal . ': ' . $this->cantidad($cantidad) . ')';
                    continue;
                }

                try {
                    // El movimiento y su marca, atómicos: ver el docblock.
                    $db->StartTrans();

                    \Punto\App\Domain\Inventory::manageStock([
                        'itemId'        => $itemId,
                        'source'        => 'adjustment',
                        'count'         => $cantidad,
                        'type'          => '+',
                        'cogs'          => $articulo['cost'],
                        // El worker no tiene usuario de sesión: la fila queda
                        // sin autor (NULL), no con una cadena vacía.
                        'userId'        => '',
                        'transactionId' => null,
                        'outletId'      => $outletId,
                        'locationId'    => $locationId,
                        'note'          => 'Apertura de stock — migración desde el sistema anterior',
                        'date'          => TODAY,
                        'companyId'     => $this->companyId,
                    ]);

                    EncomMigrationService::remember(
                        $this->companyId,
                        'stock_opening',
                        $marca,
                        $itemId,
                        $this->jobId
                    );

                    $db->CompleteTrans();
                    $counts['imported']++;
                } catch (\Throwable $e) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    $counts['failed']++;
                    $this->fail(
                        'stock',
                        'No se pudo abrir el stock de "' . $articulo['name'] . '" en "' . $nombreSucursal
                        . '": ' . $e->getMessage()
                    );
                }
            }
        }

        $this->progress['stock'] = $counts;

        if ($sinCosto !== []) {
            $this->note(
                'Artículos CON saldo que NO se abrieron por no saberse su costo (un costo 0 daría margen '
                . '100% en todos los reportes): cargales el costo y volvé a lanzar la migración, que abre '
                . 'solo los que faltan.'
            );
            foreach (array_slice($sinCosto, 0, 30) as $linea) {
                $this->note('Sin costo, sin apertura: ' . $linea);
            }
            if (count($sinCosto) > 30) {
                $this->note('… y ' . (count($sinCosto) - 30) . ' artículo(s) más sin abrir por falta de costo.');
            }
        }

        if ($sinMapear !== []) {
            $this->note(
                'Hay ' . count($sinMapear) . ' artículo(s) con saldo en el legacy que no existen en el '
                . 'catálogo migrado: migrá el catálogo y volvé a lanzar.'
            );
        }

        if ($sinControl !== []) {
            $this->note(
                count($sinControl) . ' saldo(s) del sistema anterior NO se abrieron porque en Punto ese artículo '
                . 'no lleva control de stock (servicio, combo o producción: su costo sale de la receta, no de un '
                . 'saldo): ' . implode(', ', array_slice($sinControl, 0, 20))
                . (count($sinControl) > 20 ? ' … y ' . (count($sinControl) - 20) . ' más.' : '.')
                . ' Si alguno tendría que llevar stock, activalo en su ficha y volvé a lanzar.'
            );
        }
    }

    /**
     * Artículos del destino indexados por su id de Punto: si llevan stock y
     * cuánto costaron.
     *
     * Una sola consulta para todo el catálogo en vez de una por artículo: la
     * apertura recorre (artículos × sucursales) y preguntar de a uno convierte
     * un catálogo mediano en miles de round-trips.
     *
     * El costo sale de `item.itemCost` —el que el propio migrador escribió, o
     * el que soporte cargó después— y NO se vuelve a pedir al panel legacy: es
     * una fuente por dato (§4.4), y para cuando corre este dominio el costo ya
     * vive en Punto.
     *
     * @return array<string,array{track:bool,cost:?float,name:string}>
     */
    private function puntoItemIndex(): array
    {
        $rs = ncmExecute(
            'SELECT itemId, itemName, itemTrackInventory, itemCost
               FROM item WHERE companyId = ? AND itemStatus = 1',
            [$this->companyId],
            false,
            true // forceObj → recordset, se itera con EOF
        );

        $out = [];
        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $f  = $rs->fields;
                $id = (string) ($f['itemId'] ?? $f['itemid'] ?? '');
                if ($id !== '') {
                    $cost = $f['itemCost'] ?? $f['itemcost'] ?? null;
                    $out[$id] = [
                        'track' => self::pgTruthy($f['itemTrackInventory'] ?? $f['itemtrackinventory'] ?? false),
                        // `is_numeric` y no un cast: NULL es "no lo sé" y tiene
                        // que llegar como null hasta la decisión de abrir o no.
                        'cost'  => is_numeric($cost) ? (float) $cost : null,
                        'name'  => trim((string) ($f['itemName'] ?? $f['itemname'] ?? '')) ?: '(sin nombre)',
                    ];
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $out;
    }

    /**
     * Clave de la apertura de UN artículo en UNA sucursal, en `migration_map`.
     *
     * Mismo criterio que `compoundKey()`: `legacyid` es `varchar(64)` y una
     * clave que no entra haría fallar el INSERT de la marca, que es lo único
     * que impide volver a sumar el saldo en la corrida siguiente.
     */
    private function openingKey(string $legacyItemId, string $legacyOutletId): string
    {
        $key = $legacyItemId . '@' . $legacyOutletId;
        return strlen($key) <= 64 ? $key : 'h:' . sha1($key);
    }

    /** Los booleanos de PG llegan como `t`/`f`, `true`/`false` o 1/0 según el driver. */
    private static function pgTruthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }

    /** Cantidad legible para la bitácora, sin decimales de relleno. */
    private function cantidad(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Motor común
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Recorre las filas de un dominio aplicando idempotencia y contabilidad.
     *
     * `$create` devuelve el id de Punto creado, o null si la fila no es
     * importable (sin nombre, por ejemplo). Lo que lance se cuenta como fallo de
     * ESA fila y no frena al resto.
     *
     * `$complete` (opcional) recibe las filas YA mapeadas junto con su id de
     * Punto, para completar lo que haya quedado vacío en una corrida anterior
     * (clientes: `completarCliente()`). Sin él, una fila mapeada se saltea como
     * siempre. Se cuenta igual en `skipped` —la fila "ya estaba"—, y lo que
     * haya completado lo cuenta y lo informa el propio dominio.
     *
     * ── Advertencia ≠ error ─────────────────────────────────────────────
     * Una fila que el legacy manda sin identificador o sin nombre es un DATO
     * del sistema anterior, no una falla del job: no se importa, se nombra en
     * la bitácora y se cuenta en `omitted`. Ir a `errors` dejaba el job en
     * `failed` por un "Primer Cliente" sin id que nadie puede arreglar
     * relanzando, y un job siempre rojo entrena a no mirar el rojo. Lo que
     * sigue en `errors` es lo que SÍ es una falla: un servicio que rechaza la
     * fila, una excepción, un dominio que no pudo correr.
     *
     * @param ?callable(array,string):void $complete
     */
    private function each(string $domain, array $rows, callable $create, ?callable $complete = null): void
    {
        $counts = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'omitted' => 0];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $counts['failed']++;
                continue;
            }

            $legacyId = $this->legacyIdOf($row);
            if ($legacyId === null) {
                // Nombrada: "una fila vino sin identificador" no le dice a
                // soporte a QUIÉN buscar. En clientes pasa con contactos del
                // legacy que no tienen `contactUID` (el bootstrap del POS solo
                // manda `customerId` si existe): ninguna venta puede
                // referenciarlo, y sin id no hay forma idempotente de traerlo.
                $counts['omitted']++;
                $this->note(
                    ucfirst($domain) . ' "' . $this->nombreDeFila($row) . '": no se importó porque vino sin '
                    . 'identificador del legacy (en el sistema anterior no tiene id propio), y sin él relanzar lo '
                    . 'duplicaría. Si hace falta, cargalo a mano.'
                );
                continue;
            }

            $puntoId = EncomMigrationService::mapped($this->companyId, $domain, $legacyId);
            if ($puntoId !== null) {
                $counts['skipped']++;
                if ($complete !== null) {
                    try {
                        $complete($row, $puntoId);
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->fail(
                            $domain,
                            ucfirst($domain) . ' "' . $this->nombreDeFila($row) . '" (ya importado): no se pudo '
                            . 'completar: ' . $e->getMessage()
                        );
                    }
                }
                continue;
            }

            try {
                $puntoId = $create($row);
                if (!is_string($puntoId) || $puntoId === '') {
                    // Toda omisión deja mensaje: un contador que sube sin una
                    // línea que lo explique es un número que nadie puede
                    // resolver.
                    $counts['omitted']++;
                    $this->note(
                        ucfirst($domain) . ' "' . $this->nombreDeFila($row) . '" (' . $legacyId . '): no se importó '
                        . 'porque vino sin nombre en el legacy.'
                    );
                    continue;
                }
                EncomMigrationService::remember($this->companyId, $domain, $legacyId, $puntoId, $this->jobId);
                $counts['imported']++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->fail($domain, ucfirst($domain) . ' "' . (string) ($row['name'] ?? '?') . '": ' . $e->getMessage());
            }
        }

        $this->progress[$domain] = $counts;
    }

    /** Cómo nombrar una fila en la bitácora: lo más humano que traiga. */
    private function nombreDeFila(array $row): string
    {
        foreach (['name', 'fiscalName', 'tin', 'ci', 'phone', 'email'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '?';
    }

    /** Id del legacy. Las APIs no son simétricas: unas mandan `ID`, otras `id`. */
    private function legacyIdOf(array $row): ?string
    {
        foreach (['ID', 'id', 'UID'] as $key) {
            $v = trim((string) ($row[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** Id de Punto ya mapeado para un id del legacy, o '' si no hay. */
    private function mapOf(string $domain, mixed $legacyId): string
    {
        $legacyId = trim((string) ($legacyId ?? ''));
        if ($legacyId === '') {
            return '';
        }
        return EncomMigrationService::mapped($this->companyId, $domain, $legacyId) ?? '';
    }

    /** Fila m2m del artículo (categoría o marca). Ausente = nada que enlazar. */
    private function linkM2m(string $table, string $column, string $itemId, string $refId): void
    {
        if ($refId === '') {
            return;
        }
        global $db;
        $db->Execute(
            'INSERT INTO ' . $table . ' (itemId, ' . $column . ', isPrimary)
             VALUES (?, ?, TRUE) ON CONFLICT DO NOTHING',
            [$itemId, $refId]
        );
    }

    /**
     * `taxId` de Punto para el impuesto del artículo.
     *
     * El legacy manda el VALOR ("10", "5", "0"), que es exactamente lo que
     * Punto guarda en `tax.name` — la compatibilidad con `getTaxValue()` es
     * histórica y sigue vigente. Si el destino todavía no tiene ese impuesto se
     * crea con `TaxService`, que deriva `rate`/`kind` del nombre con el mismo
     * criterio que usa el resto del sistema (no se duplica la fórmula acá).
     */
    private function taxIdFor(mixed $raw): ?string
    {
        $name = trim((string) (is_scalar($raw) ? $raw : ''));
        if ($name === '') {
            return null;
        }

        require_once dirname(__DIR__) . '/Taxes/TaxService.php';
        global $db;
        $svc = new \Punto\Api\Taxes\TaxService($db);

        if ($this->taxByName === null) {
            $this->taxByName = [];
            foreach ($svc->list($this->companyId) as $t) {
                $this->taxByName[mb_strtolower(trim((string) $t['name']), 'UTF-8')] = (string) $t['id'];
            }
        }

        $key = mb_strtolower($name, 'UTF-8');
        if (isset($this->taxByName[$key])) {
            return $this->taxByName[$key];
        }

        try {
            $id = $svc->create($this->companyId, ['name' => $name]);
        } catch (\Throwable $e) {
            // Un impuesto que no se pudo crear no puede costar el artículo: se
            // importa sin impuesto y el comercio lo asigna.
            $this->note('No se pudo crear el impuesto "' . $name . '": los artículos que lo usan quedan sin impuesto.');
            return null;
        }

        return $this->taxByName[$key] = $id;
    }

    /**
     * Kind canónico de Punto para un artículo del legacy.
     *
     * `/fetchs` manda `kind`, que es MUCHO más informativo que el `type` que
     * daba la tabla HTML: distingue el combo fijo del combo con opciones y la
     * producción previa de la directa. El `type` queda como respaldo para un
     * deploy que no mande `kind`.
     *
     * Lo que no se reconoce entra como `producto`: es la opción reversible — el
     * comercio puede reclasificar un artículo desde el panel, pero no puede
     * recuperar uno que no se importó.
     */
    private function kindFor(array $row): string
    {
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));
        $type = strtolower(trim((string) ($row['type'] ?? '')));

        foreach ([$kind, $type] as $v) {
            $mapped = match ($v) {
                'product', 'producto'                 => 'producto',
                'service', 'servicio'                 => 'servicio',
                // Un "precombo" es un combo cerrado que se usa como componente
                // de otro: para Punto es un combo fijo más.
                'combo', 'precombo'                   => 'combo_fijo',
                // El combo con opciones elegibles. En Punto su composición NO
                // es una receta: son grupos de add-ons, que este importador no
                // fabrica (ver `compose()`).
                'comboaddons', 'dynamic'              => 'combo_dinamico',
                'production', 'produccion'            => 'produccion_previa',
                'direct_production'                   => 'produccion_directa',
                'giftcard', 'gift card'               => 'giftcard',
                'discount', 'descuento'               => 'descuento',
                default                               => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return 'producto';
    }

    /**
     * Clave de UN componente de una receta en `migration_map`.
     *
     * `padre:hijo`, ambos ids del legacy. `migration_map.legacyid` es
     * `varchar(64)`: los ids del legacy son hashids cortos, pero si el par se
     * pasara de largo el INSERT fallaría y ese componente quedaría sin marcar
     * (y se volvería a sumar en cada corrida). El hash cubre ese borde sin
     * cambiar el caso normal, que sigue siendo legible al depurar.
     */
    private function compoundKey(string $parentLegacyId, string $childLegacyId): string
    {
        $key = $parentLegacyId . ':' . $childLegacyId;
        return strlen($key) <= 64 ? $key : 'h:' . sha1($key);
    }

    private function digits(mixed $v): string
    {
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    private function numOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    private function fail(string $domain, string $message): void
    {
        $this->errors[] = ['domain' => $domain, 'message' => $message, 'at' => gmdate('c')];
    }

    private function note(string $message): void
    {
        $this->log[] = ['at' => gmdate('c'), 'message' => $message];
    }
}
