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
 * (`ItemService`, `ContactService`, `CategoryService`, `OutletsService`,
 * `RegisterAdminService`). La única tabla que este importador escribe a mano
 * es `migration_map`, que es suya.
 *
 * No es purismo: esos servicios son los que aplican los invariantes. Saltearlos
 * con un INSERT es exactamente cómo entrarían dos cajas con el mismo punto de
 * expedición, un contacto con teléfono duplicado o un ítem sin fila en
 * `item_outlet`. El costo es velocidad; lo que se compra es que un comercio
 * migrado quede indistinguible de uno cargado a mano.
 *
 * ── Idempotencia ────────────────────────────────────────────────────────
 * Antes de crear cualquier cosa se pregunta a `migration_map` si ese id del
 * legacy ya tiene un id de Punto para esta empresa. Si lo tiene, se saltea.
 * Correr el job dos veces da los mismos conteos, con todo en `skipped`.
 *
 * ── Una fila mala no mata el dominio ────────────────────────────────────
 * …salvo en las CAJAS, que son la excepción deliberada (ver `config()`): ahí
 * un choque de punto de expedición aborta el dominio ENTERO sin importar
 * ninguna, porque media tanda de cajas fiscales es peor que ninguna.
 */
final class EncomImportService
{
    /** @var array<int,array{domain:string,message:string,at:string}> */
    private array $errors = [];

    /** @var array<int,array{at:string,message:string}> */
    private array $log = [];

    private array $progress = [];

    public function __construct(
        private readonly string $companyId,
        private readonly EncomSource $source,
        private readonly ?string $jobId = null,
    ) {
    }

    /**
     * Corre los dominios pedidos.
     *
     * Un dominio que revienta entero queda registrado en `errors` y NO frena
     * a los otros: si el catálogo falla, los clientes igual se migran. Lo que
     * no puede pasar es que el job diga `done` como si nada — el endpoint
     * marca `failed` cuando hay errores.
     *
     * @param array<int,string> $domains
     * @return array{progress:array,errors:array,log:array}
     */
    public function run(array $domains, array $options = []): array
    {
        // El catálogo va PRIMERO cuando está pedido junto con la config: los
        // ítems no dependen de las sucursales, pero `OutletsService::create()`
        // siembra filas de inventario para los ítems rastreados que ya
        // existan. Con el orden inverso, una sucursal nueva nace sin esas
        // filas para todo lo que se importe después.
        $order = ['catalog', 'customers', 'config'];

        foreach ($order as $domain) {
            if (!in_array($domain, $domains, true)) {
                continue;
            }

            try {
                match ($domain) {
                    'catalog'   => $this->catalog(),
                    'customers' => $this->customers(),
                    'config'    => $this->config($options),
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
    // catalog — categorías, marcas, etiquetas, artículos
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
            if ($name === '') {
                return null;
            }
            // `pos` del legacy es el orden de la categoría; en Punto ese dato
            // vive en `extra`, igual que lo escribe el panel.
            return $categories->create($this->companyId, [
                'name'  => $name,
                'extra' => isset($row['pos']) ? (string) $row['pos'] : null,
            ]);
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

        // ── Artículos ────────────────────────────────────────────────────
        $items = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));

        $this->each('item', $this->source->items(), function (array $row) use ($items): ?string {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $kind  = $this->kindFor($row);
            $flags = \Punto\Api\Items\ItemImporter::legacyFlagsForKind($kind);

            // Categoría y marca se resuelven por el MAPA, cuya clave es el
            // NOMBRE: el export de artículos del legacy trae la categoría y la
            // marca por nombre, no por id (tanto en su modo JSON como en la
            // tabla HTML). No hay id que usar del otro lado — por eso
            // `categories()`/`brands()` derivan de estos mismos nombres.
            $categoryId = $this->mapOf('category', $row['category'] ?? null);
            $brandId    = $this->mapOf('brand', $row['brand'] ?? null);

            // El alta de un artículo son DOS pasos (`createBlank()` + `update()`)
            // y sin transacción no son atómicos: si el update falla, queda un
            // "Nuevo Artículo" vacío en el catálogo del cliente que nadie
            // relaciona con la migración. Lo detectó el arnés — una fila mala
            // dejaba el ítem huérfano y el re-run creaba otro.
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

        // El stock inicial NO se migra en F1: un saldo es un movimiento del
        // ledger (context/52), con costo y sucursal, y el export del legacy
        // solo trae un número suelto. Meterlo como ajuste sin fecha ni costo
        // real ensucia el costeo promedio desde el día uno.
        $this->note('El stock inicial no se migra: se carga con un conteo en la sucursal (context/77 §F2).');
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
        $sku = trim((string) ($row['sku'] ?? ''));

        $patch = [
            'itemName'           => $name,
            'itemSKU'            => $sku !== '' ? $sku : null,
            'itemKind'           => $kind,
            'itemType'           => $flags['itemType'],
            'itemCanSale'        => $flags['itemCanSale'],
            'itemTrackInventory' => $flags['itemTrackInventory'],
            'itemProduction'     => $flags['itemProduction'],
            'itemDescription'    => trim((string) ($row['description'] ?? '')),
            // `cost` y `stock` son claves CONDICIONALES en el legacy: solo
            // vienen si el artículo rastrea inventario. `null` = "no lo sé",
            // que no es lo mismo que 0.
            'itemCost'           => $this->numOrNull($row['cost'] ?? null),
            'itemPrice'          => $this->numOrNull($row['price'] ?? null),
            'itemDiscount'       => $this->numOrZero($row['discount'] ?? null),
            'itemUOM'            => trim((string) ($row['uom'] ?? '')),
            // El export solo lista los artículos ACTIVOS (el legacy filtra
            // `itemStatus = 1` salvo que se le pida `archived`), así que todo
            // lo que llega acá está activo. No se deriva de un campo que el
            // export no manda.
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

        if (!$items->update($itemId, $this->companyId, $patch)) {
            throw new \RuntimeException('no se pudieron guardar los datos del artículo');
        }

        // m2m: el panel lee `item_category` / `item_brand`, y la columna
        // `item.categoryId` es la FK legacy. Escribir solo una de las dos deja
        // el artículo sin categoría en la mitad de las pantallas — es la
        // trampa que ya pisó la mig 136 (context/41).
        $this->linkM2m('item_category', 'categoryId', $itemId, $categoryId);
        $this->linkM2m('item_brand', 'brandId', $itemId, $brandId);
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

        $this->each('customer', $this->source->customers(), function (array $row) use ($contacts): ?string {
            // El CSV del legacy SÍ separa razón social de nombre de persona:
            // "RAZÓN SOCIAL" y "NOMBRE Y APELLIDO" son columnas distintas, y
            // `ContactService` tiene un campo para cada una. Alcanza con que
            // venga una de las dos.
            $fiscalName = trim((string) ($row['fiscalName'] ?? ''));
            $personName = trim((string) ($row['name'] ?? ''));
            if ($fiscalName === '' && $personName === '') {
                return null;
            }

            $in = [
                'tin'     => trim((string) ($row['tin'] ?? '')),
                'phone'   => trim((string) ($row['phone'] ?? '')),
                'email'   => trim((string) ($row['email'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'note'    => trim((string) ($row['note'] ?? '')),
                'type'    => \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER,
            ];
            if ($fiscalName !== '') {
                $in['fiscalName'] = $fiscalName;
            }
            if ($personName !== '') {
                $in['name'] = $personName;
            }

            $address2 = trim((string) ($row['address2'] ?? ''));
            if ($address2 !== '') {
                $in['address2'] = $address2;
            }

            return $contacts->create($this->companyId, $in);
        }, [$this, 'customerKey']);
    }

    /**
     * Clave natural de un cliente, para `migration_map`.
     *
     * El CSV de `a_contacts?action=download` NO trae el id del contacto — es
     * un export pensado para abrir en una planilla, no para sincronizar. Sin
     * clave no hay idempotencia: re-correr el job duplicaría toda la cartera.
     *
     * Se usa el documento fiscal cuando está (es el identificador real del
     * cliente) y, si no, el nombre normalizado. Dos clientes distintos con el
     * mismo nombre y sin documento se fusionan en uno — es el costo conocido
     * de no tener id, y el lado seguro: `ContactService` igual rechaza
     * duplicados de documento y teléfono.
     */
    public function customerKey(array $row): ?string
    {
        $tin = preg_replace('/[^0-9A-Za-z]/', '', (string) ($row['tin'] ?? '')) ?? '';
        if ($tin !== '') {
            return 'tin:' . strtoupper($tin);
        }

        $name = trim((string) ($row['fiscalName'] ?? '')) ?: trim((string) ($row['name'] ?? ''));
        $name = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name, 'UTF-8');

        return $name === '' ? null : 'name:' . $name;
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
                'description' => trim((string) ($row['description'] ?? '')),
                'status'      => 1,
            ];

            // El teléfono se valida con libphonenumber y LANZA si no parsea.
            // Un teléfono mal cargado en el legacy no puede costar la
            // sucursal entera: se manda solo si viene, y si el servicio lo
            // rechaza se reintenta sin él dejando la nota.
            $phone = trim((string) ($row['phone'] ?? ''));

            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $fields['lat'] = (float) $lat;
                $fields['lng'] = (float) $lng;
            }

            try {
                $id = $outlets->create($this->companyId, $phone !== '' ? $fields + ['phone' => $phone] : $fields);
            } catch (\Throwable $e) {
                if ($phone === '') {
                    throw $e;
                }
                $this->note('La sucursal "' . $name . '" se importó sin teléfono: el legacy tenía "' . $phone . '", que no es un número válido.');
                $id = $outlets->create($this->companyId, $fields);
            }

            return is_string($id) && $id !== '' ? $id : null;
        });

        // ── Cajas ────────────────────────────────────────────────────────
        $this->registers($options);
    }

    /**
     * Importa las cajas CONTINUANDO su serie fiscal (D5).
     *
     * La caja de Punto nace con el timbrado y el punto de expedición del
     * legacy, y su `document_sequence` arranca en "último emitido + 1". El
     * contador del legacy guarda el ÚLTIMO número usado; el de Punto guarda
     * el PRÓXIMO (mig 117). Esa asimetría es precisamente el +1 — no es un
     * margen de seguridad.
     *
     * ── Validación DURA, antes de crear nada ─────────────────────────────
     * Dos cajas con el mismo (timbrado, punto de expedición) llevarían la
     * misma secuencia y terminarían emitiendo dos facturas con el mismo
     * número: documento duplicado, ilegal ante la SET (context/29 §2). Por
     * eso el chequeo corre sobre TODO el lote ANTES de crear la primera caja
     * y aborta el dominio entero. Importar "hasta donde se pudo" dejaría al
     * comercio con la mitad de sus cajas y sin señal de cuáles faltan.
     *
     * `RegisterAdminService` igual tiene su propio guard (y la mig 143 su
     * índice único): esto no lo reemplaza, se adelanta para poder fallar
     * SIN efectos parciales.
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
        // falló), se usa la que el operador eligió; sin ninguna de las dos NO
        // se inventa (memoria: prohibido resolver una dimensión faltante con
        // "el primer outlet activo").
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
     * Aborta si dos cajas del lote comparten (timbrado, punto de expedición),
     * o si alguna choca con una caja ACTIVA que ya existe en el destino.
     */
    private function assertExpeditionPointsFree(array $rows): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $auth   = $this->digits($row['invoiceAuth'] ?? '');
            $prefix = trim((string) ($row['prefix'] ?? ''));
            $name   = trim((string) ($row['name'] ?? '')) ?: 'sin nombre';

            // Sin timbrado no hay serie fiscal que pueda chocar: esa caja
            // entra sin punto de expedición y el comercio lo carga después.
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

        $exp = trim((string) ($row['invoiceAuthExp'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) {
            $extra['fiscal']['invoiceAuthExpiration'] = substr($exp, 0, 10);
        }

        // Ancho de impresión del correlativo: es FORMATO, va aparte del
        // número (mig 159 / context/29 §1).
        $zeros = (int) $this->digits($row['docsZeros'] ?? '');
        if ($zeros >= 1 && $zeros <= 12) {
            $extra['padWidth'] = ['factura' => (string) $zeros];
        }

        return $extra;
    }

    /**
     * La caja placeholder que `OutletsService::create()` dejó en la sucursal:
     * activa, sin timbrado y sin mapear a ninguna caja del legacy.
     *
     * El filtro por `migration_map` es lo que evita pisar una caja ya
     * importada que todavía no tenga timbrado cargado.
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
    // Motor común
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Recorre las filas de un dominio aplicando idempotencia y contabilidad.
     *
     * `$create` devuelve el id de Punto creado, o null si la fila no es
     * importable (sin nombre, por ejemplo). Lo que lance se cuenta como
     * fallo de ESA fila y no frena al resto.
     */
    private function each(string $domain, array $rows, callable $create, ?callable $keyOf = null): void
    {
        $counts = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $counts['failed']++;
                continue;
            }

            // `$keyOf` es para los dominios cuyo export NO trae id y hay que
            // derivar una clave natural (clientes, ver `customerKey()`).
            $legacyId = $keyOf !== null ? $keyOf($row) : $this->legacyIdOf($row);
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
     * Kind canónico de Punto para un artículo del legacy.
     *
     * El legacy tiene un `type` mucho más pobre que los 12 kinds de Punto, así
     * que el mapeo es conservador: lo que no se reconoce entra como
     * `producto`. Es la opción reversible — el comercio puede reclasificar un
     * artículo desde el panel, pero no puede recuperar uno que no se importó.
     */
    private function kindFor(array $row): string
    {
        $type = strtolower(trim((string) ($row['type'] ?? '')));

        return match ($type) {
            'service', 'servicio'         => 'servicio',
            'combo', 'precombo', 'comboaddons' => 'combo_fijo',
            'discount', 'descuento'       => 'descuento',
            'giftcard', 'gift card'       => 'giftcard',
            'production', 'produccion'    => 'produccion_previa',
            default                       => 'producto',
        };
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

    private function numOrZero(mixed $v): float
    {
        return $this->numOrNull($v) ?? 0.0;
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
