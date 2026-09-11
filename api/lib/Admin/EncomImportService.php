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
        $order = ['catalog', 'customers', 'config', 'users', 'payments'];

        foreach ($order as $domain) {
            if (!in_array($domain, $domains, true)) {
                continue;
            }

            try {
                match ($domain) {
                    'catalog'   => $this->catalog(),
                    'customers' => $this->customers(),
                    'config'    => $this->config($options),
                    'users'     => $this->users(),
                    'payments'  => $this->payments(),
                };
            } catch (\Throwable $e) {
                $this->fail($domain, $e->getMessage());
            }
        }

        return [
            'progress' => $this->progress,
            'errors'   => $this->errors,
            'log'      => $this->log,
        ];
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
        $categories = new \Punto\Api\Categories\CategoryService($db);
        $this->each('category', $this->source->categories(), function (array $row) use ($categories): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            return $name === '' ? null : $categories->create($this->companyId, ['name' => $name]);
        });

        $brands = new \Punto\Api\Brands\BrandService($db);
        $this->each('brand', $this->source->brands(), function (array $row) use ($brands): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            return $name === '' ? null : $brands->create($this->companyId, ['name' => $name]);
        });

        $tags = new \Punto\Api\Tags\TagService($db);
        $this->each('tag', $this->source->tags(), function (array $row) use ($tags): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            return $name === '' ? null : $tags->create($this->companyId, ['name' => $name]);
        });

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

        // ── SEGUNDA pasada: combos y recetas ─────────────────────────────
        $this->compose();

        // El stock inicial NO se migra: un saldo es un movimiento del ledger
        // (context/52), con costo y sucursal, y aunque `/fetchs` trae el conteo
        // actual (`inventory[].count`) sigue siendo un número suelto. Meterlo
        // como ajuste sin fecha ni costo real ensucia el costeo promedio desde
        // el día uno.
        $this->note('El stock inicial no se migra: se carga con un conteo en la sucursal (context/77 §8).');
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
            // `null` = "no lo sé", que no es lo mismo que 0. El bootstrap del
            // POS no manda el COSTO (no lo necesita para vender), así que lo
            // habitual es que el artículo entre sin costo — ver context/77 §8.
            'itemCost'           => $this->numOrNull($row['cost'] ?? null),
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
     */
    private function compose(): void
    {
        require_once dirname(__DIR__) . '/Items/ItemCompoundService.php';

        global $db;
        $compounds = new \Punto\Api\Items\ItemCompoundService($db);

        $counts   = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        $revisar  = [];

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
                $counts['failed']++;
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
                continue;
            }

            if (!in_array($kind, self::RECIPE_KINDS, true)) {
                $revisar[] = $name . ' (' . (trim((string) ($row['kind'] ?? '')) ?: 'sin kind') . ')';
                $counts['failed']++;
                continue;
            }

            $parts = json_decode($raw, true);
            if (!is_array($parts) || $parts === []) {
                $counts['failed']++;
                $this->fail('catalog', 'La composición de "' . $name . '" no se pudo leer: ' . $raw);
                continue;
            }

            $escritos    = 0;
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
                $childId     = $this->mapOf('item', $childLegacy);
                if ($childId === '') {
                    $faltantes[] = $childLegacy !== '' ? $childLegacy : '(sin id)';
                    continue;
                }

                // `units` viaja como "1.000" — decimal con punto, no un miles.
                $units = $this->numOrNull($part['units'] ?? null) ?? 1.0;
                if ($units <= 0) {
                    $units = 1.0;
                }

                try {
                    $compounds->add($parentId, $this->companyId, $childId, $units);
                    $escritos++;
                } catch (\Throwable $e) {
                    // Un ciclo o un componente de otro tenant: lo rechaza el
                    // servicio, que es justamente para lo que se lo usa.
                    $faltantes[] = ($childLegacy !== '' ? $childLegacy : '(sin id)') . ': ' . $e->getMessage();
                }
            }

            if ($faltantes !== []) {
                $revisar[] = $name . ' — componentes que no se pudieron resolver: ' . implode(', ', $faltantes);
            }
            if ($selectables > 0) {
                $revisar[] = $name . ' — tiene ' . $selectables . ' componente(s) que el cliente elige al vender: '
                    . 'hay que armarlos como grupo de opciones en la ficha del artículo.';
            }

            if ($escritos > 0) {
                EncomMigrationService::remember($this->companyId, 'compound', $legacyId, $parentId, $this->jobId);
                $counts['imported']++;
            } else {
                $counts['failed']++;
            }
        }

        $this->progress['compound'] = $counts;

        foreach (array_slice($revisar, 0, 50) as $linea) {
            $this->note('Revisar a mano: ' . $linea);
        }
        if (count($revisar) > 50) {
            $this->note('… y ' . (count($revisar) - 50) . ' artículo(s) más para revisar a mano.');
        }
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

        // La idempotencia usa el id REAL del contacto en el legacy
        // (`customerId`), que `/fetchs` sí manda. El CSV del panel no lo traía y
        // obligaba a una clave natural (documento, o el nombre normalizado) que
        // fusionaba a dos homónimos sin documento en un solo cliente. Ese
        // parche se fue junto con el scraping.
        $this->each('customer', $this->source->customers(), function (array $row) use ($contacts): ?string {
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

            return $contacts->create($this->companyId, $in);
        });
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

        $this->each('outlet', $this->source->outlets(), function (array $row) use ($outlets): ?string {
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

            return is_string($id) && $id !== '' ? $id : null;
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
                $placeholder = $this->freePlaceholderRegister($outletId);
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
                . (trim((string) ($row['roleName'] ?? '')) ?: 'sin rol') . '" → rol de Punto "' . $role['name'] . '".'
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
     * @return array{id:string,name:string}
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
                return ['id' => (string) $r['id'], 'name' => (string) $r['name']];
            }
        }

        // 2. Palabra clave → slug. El "administrador" del legacy cae en
        //    `manager` y no en `owner`, igual que en el mapa de roles legacy de
        //    `RoleService`: el dueño es uno solo y no se reparte por nombre.
        $porSlug = null;
        if ($legacy !== '') {
            foreach ([
                'owner'   => ['dueñ', 'duen', 'owner', 'propietar', 'titular'],
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
            return ['id' => (string) $bySlug[$porSlug]['id'], 'name' => (string) $bySlug[$porSlug]['name']];
        }

        // 3. El más bajo que exista.
        foreach (['cashier', 'manager', 'owner'] as $slug) {
            if (isset($bySlug[$slug])) {
                return ['id' => (string) $bySlug[$slug]['id'], 'name' => (string) $bySlug[$slug]['name']];
            }
        }

        $primero = $asignables[0];
        return ['id' => (string) $primero['id'], 'name' => (string) $primero['name']];
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
    // Motor común
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Recorre las filas de un dominio aplicando idempotencia y contabilidad.
     *
     * `$create` devuelve el id de Punto creado, o null si la fila no es
     * importable (sin nombre, por ejemplo). Lo que lance se cuenta como fallo de
     * ESA fila y no frena al resto.
     */
    private function each(string $domain, array $rows, callable $create): void
    {
        $counts = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $counts['failed']++;
                continue;
            }

            $legacyId = $this->legacyIdOf($row);
            if ($legacyId === null) {
                $counts['failed']++;
                $this->fail($domain, 'Una fila de ' . $domain . ' vino sin identificador del legacy.');
                continue;
            }

            if (EncomMigrationService::mapped($this->companyId, $domain, $legacyId) !== null) {
                $counts['skipped']++;
                continue;
            }

            try {
                $puntoId = $create($row);
                if (!is_string($puntoId) || $puntoId === '') {
                    $counts['failed']++;
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
