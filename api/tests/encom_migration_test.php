<?php
declare(strict_types=1);

/**
 * Arnés del migrador ENCOM → Punto (context/77).
 *
 * Corre contra Postgres REAL (descartable, lo levanta run_encom_migration_test.sh),
 * contra los SERVICIOS REALES de import y contra el CLIENTE REAL del legacy.
 * Lo único que se reemplaza es el TRANSPORTE: `FixtureEncomClient` sobreescribe
 * `fetch()` y sirve los payloads de `/fetchs` con el shape EXACTO que devuelve
 * el sistema vivo (`api/tests/fixtures/encom/fetchs-*.json`).
 *
 * Por qué así y no con un `EncomSource` de JSON ya normalizado: lo que más se
 * puede equivocar es justamente el mapeo —de qué campo sale cada dato, cómo se
 * junta `registers` con `docsNum`, cómo se lee el `compound` inline— y un
 * fixture normalizado lo saltea entero. Acá se ejercita.
 *
 * Casos:
 *   S. ALCANCE — companyId/outletId salen del `?i=` en base64, por el redirect
 *      y por el fallback del home; si no sale por ninguna vía, LANZA.
 *   N. HOSTS — `/fetchs` va contra la app del POS y el costo contra el panel
 *      (son dos dominios distintos); sin el host del POS no se pide NADA.
 *   G. PREREQUISITO — sin sucursales migradas, el histórico aborta con UN
 *      error que nombra la causa, no con uno por venta.
 *   L. LOGIN — los `name` del form del deploy vivo (`email`/`password`) y el
 *      código de país que el navegador le antepone a un celular.
 *   X. EXPORT — el mapeo de cada dominio de `/fetchs`.
 *   A. Import completo — conteos por dominio y entidades realmente creadas.
 *   R. COMBOS Y RECETAS — la composición inline se resuelve por el mapa; lo que
 *      no mapea limpio NO se inventa y queda anotado para revisar.
 *   C. MAPEO — `migration_map`, y el artículo apunta a la categoría importada.
 *   D. CONTINUACIÓN DE NUMERACIÓN (D5) — `document_sequence` con el timbrado y
 *      el punto del legacy y `nextnumber` = último emitido + 1, por doctype.
 *   U. USUARIOS — PIN, sucursal y el rol de Punto asignado por nombre.
 *   P. MEDIOS DE PAGO — se suman los del legacy sin duplicar los que ya existen.
 *   F. La caja placeholder de la sucursal se REUSA (no quedan fantasmas).
 *   B. IDEMPOTENCIA — re-correr no duplica NADA, recetas incluidas.
 *   E. RECHAZO por punto de expedición duplicado — aborta el dominio SIN
 *      importar ninguna caja.
 *   Z. Barrido de credenciales huérfanas (TTL 24 h).
 *
 * Job 71e8282d (2026-09-18) — cada bug arreglado tiene su caso:
 *   T. TAXONOMÍAS — una categoría/marca que ya existe por nombre (otro case)
 *      se REUSA y se mapea, no choca contra el UNIQUE.
 *   K. CLIENTES DUPLICADOS — política del owner: documento repetido unifica,
 *      teléfono repetido o inválido entra sin teléfono con el número en la
 *      nota; el cliente sin id del legacy queda NOMBRADO en la bitácora
 *      como ADVERTENCIA, no como error (no marca el job `failed`).
 *
 * 2026-09-19:
 *   CV. COMPLETAR CLIENTES YA IMPORTADOS — relanzar llena lo VACÍO en Punto
 *      (incluida la fila default de dirección sin texto que dejó el migrador
 *      viejo), no pisa lo editado, manda a la nota el teléfono repetido o
 *      inválido sin duplicarla, respeta la unicidad del documento, y una
 *      segunda corrida no cambia nada.
 *   V. PROVEEDORES — se migran como contactos type 2 y la compra se cuelga
 *      del proveedor, no del cliente homónimo.
 *   W. LOG DE ÍTEMS — el `data-id` es el de la VENTA: una venta partida entre
 *      páginas no aborta, cada línea entra una vez, y `nolimit` lee de una.
 *   J. COMPLETAR — relanzar completa las compras que entraron sin líneas ni
 *      proveedor (y descarta el alias viejo que apuntaba a un cliente).
 *   U7. "Jefe" → Dueño (decisión del owner).
 *
 *   Y. SUCURSAL EXISTENTE (2026-09-18, regla del owner: nunca duplicar) —
 *      mapeada → se usa; homónima libre → se reusa; otra libre → la más
 *      antigua; todas tomadas → se crea. Sus cajas no se fusionan.
 */

require_once __DIR__ . '/_harness.php';

$companyId = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d1122';
$companyB  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d3344';
$companyC  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d5566';
// Empresa con un período contable CERRADO, para el caso H17/H18.
$companyD  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d7788';
// Empresa contra la que corre un cliente SIN el host del POS (caso N4).
$companyE  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d99aa';
// Empresa SIN sucursales, para el prerequisito del histórico (caso Q).
$companyF  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6dbbcc';
// Empresa con sucursales y usuarios migrados, para los casos del EXPORT: el
// tope de filas por request (P), la sonda del detalle (S), el detalle apagado
// (T) y el latido del job (R).
$companyG  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6dddee';
// Casos del job 71e8282d (2026-09-18).
$companyH  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0011';   // U7 — rol "Jefe"
$companyI  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0022';   // T — taxonomías que ya existen
$companyJ  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0033';   // J — completar compras
$companyK  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0044';   // K — clientes duplicados
// Caso Y (2026-09-18): la sucursal que ya existe en el destino no se duplica.
$companyL  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0055';   // Y1 — reusa por nombre
$companyM  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0066';   // Y2 — reusa la única del signup
$companyN  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0077';   // Y3 — ninguna coincide: la más antigua libre
$companyO  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0088';   // Y5 — todas tomadas: crea
$companyP  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d0099';   // Y6 — orden del export invertido
// Caso VC (2026-09-18): el cliente de las ventas históricas.
$companyQ  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d00aa';
// Caso CV (2026-09-19): completar clientes ya importados.
$companyR  = '7b1d0c44-2f3e-4a51-9c77-0e8a5b6d00bb';

define('COMPANY_ID', $companyId);
define('OUTLET_ID', '');
define('USER_ID', '');
define('REGISTER_ID', '');
define('ROLE_ID', '');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomClient.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomImportService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomMigrationService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomCustomerMatcher.php';

use Punto\Api\Admin\EncomClient;
use Punto\Api\Admin\EncomCustomerMatcher;
use Punto\Api\Admin\EncomParse;
use Punto\Api\Admin\EncomImportService;
use Punto\Api\Admin\EncomMigrationService;

global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/**
 * Cliente real del legacy con el transporte cambiado por fixtures.
 *
 * Sobreescribe SOLO `fetch()`: todo lo de arriba —el mapeo campo a campo, la
 * unión de `registers` con `docsNum`, la derivación de categorías y marcas, la
 * lectura tolerante de `tags` y `paymentMethods`— es el código de producción.
 */
class FixtureEncomClient extends EncomClient
{
    /** @var array<int,string> `load` pedidos, en orden. */
    public array $calls = [];

    public function __construct(protected readonly string $dir)
    {
        // El par que devolvió el sistema vivo en el relevamiento.
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture'], 'QE22', '62Lm');
    }

    protected function fetch(string $load, ?string $outletHash = null): array
    {
        $this->calls[] = $load . ($outletHash !== null ? '@' . $outletHash : '');

        // El STOCK se pide por sucursal: `/fetchs` contesta el bootstrap de UNA
        // caja. El fixture por sucursal (`fetchs-items-out-2.json`) es lo que
        // permite verificar que cada saldo entra donde corresponde; sin archivo
        // propio, la sucursal usa el payload general.
        $file = $outletHash !== null
            ? $this->dir . '/fetchs-' . $load . '-' . $outletHash . '.json'
            : $this->dir . '/fetchs-' . $load . '.json';

        if (!is_file($file)) {
            $file = $this->dir . '/fetchs-' . $load . '.json';
        }

        $raw  = is_file($file) ? (string) file_get_contents($file) : '[]';
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * El COSTO es lo único que NO sale de `/fetchs`: sale de la tabla del
     * panel, que es lo que sirve este `get()`.
     */
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $action = (string) ($params['action'] ?? '');

        // El COSTO y todo el HISTÓRICO son lo único que NO sale de `/fetchs`:
        // salen de las pantallas del panel, que es lo que sirve este `get()`.
        $file = match (true) {
            $path === '/a_items'                                          => 'panel-items-costs.json',
            $path === '/a_report_transactions' && $action === 'detailTable' => 'panel-sales.json',
            // El log de ítems vendidos: en el legacy es una tabla APARTE de
            // las transacciones, con su propio reporte en bloque.
            $path === '/a_report_products'  && $action === 'detailTable'   => 'panel-items-sold.json',
            $path === '/a_report_purchases' && $action === 'general'       => 'panel-purchases.json',
            $path === '/a_report_purchases' && $action === 'detailTable'   => 'panel-purchase-lines.json',
            $path === '/a_report_expenses'  && $action === 'generalTable'  => 'panel-expenses.json',
            // Proveedores: no están en /fetchs (el bootstrap del POS solo trae
            // clientes), salen de la tabla de contactos del panel.
            $path === '/a_contacts' && $action === 'generalTable'
                && ($params['rol'] ?? '') === 'supplier'                   => 'panel-suppliers.json',
            default                                                        => '',
        };

        if ($file === '') {
            return '';
        }

        $this->calls[] = $path . '?' . $action;

        $full = $this->dir . '/' . $file;
        return is_file($full) ? (string) file_get_contents($full) : '';
    }
}

/**
 * Variante: el panel no entrega la tabla de costos (otro deploy, permiso,
 * sesión caída). El catálogo NO puede caerse por eso.
 */
final class SinCostosEncomClient extends FixtureEncomClient
{
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        throw new \RuntimeException('el panel respondió 403');
    }
}

/**
 * Listado de ventas del legacy con N filas, con el MISMO shape que el fixture
 * real: los mismos encabezados (incluidos los pares ambiguos `Tipo
 * Documento`/`Tipo` y `Total Gravado`/`Total`), el valor crudo en `data-order`
 * y el id de la venta en `data-id`.
 *
 * @param array<int,string> $ids
 */
function ventasHtml(array $ids): string
{
    $thead = '<thead><tr>'
        . '<th>ID</th><th>#Autorización</th><th>#Documento</th><th>Fecha</th><th>Hora</th>'
        . '<th>Vencimiento</th><th>Cliente</th><th>RUC</th><th>Usuario</th><th>Sucursal</th>'
        . '<th>Caja</th><th>Caja FE Activa</th><th>M.de Pago</th><th>Nota</th><th>Etiquetas</th>'
        . '<th>Tipo Documento</th><th>Tipo</th><th>Descuento</th><th>Subtotal</th><th>IVA</th>'
        . '<th>Total Gravado</th><th>Total</th></tr></thead>';

    $filas = '';
    foreach ($ids as $id) {
        $filas .= '<tr data-id="' . $id . '">'
            . '<td data-order="' . $id . '">' . $id . '</td>'
            . '<td data-order="16543210">16543210</td>'
            . '<td data-order="001-001-0009999">001-001-0009999</td>'
            . '<td data-order="2026-08-14 10:30:00">14 ago</td>'
            . '<td data-order="10:30">10:30</td>'
            . '<td data-order="">-</td>'
            . '<td data-order="">-</td>'
            . '<td data-order="">-</td>'
            // Un usuario que NO está migrado: la venta se rechaza ANTES de
            // pedir su detalle, así que estos casos miden el LISTADO y nada
            // más (y no insertan miles de filas para probar la paginación).
            . '<td data-order="Usuario Fantasma">Usuario Fantasma</td>'
            . '<td data-order="Casa Central">Casa Central</td>'
            . '<td data-order="Caja Uno">Caja Uno</td>'
            . '<td data-order="Sí">Sí</td>'
            . '<td data-order="Efectivo">Efectivo</td>'
            . '<td data-order="">-</td>'
            . '<td data-order="">-</td>'
            . '<td data-order="Factura">Factura</td>'
            . '<td data-order="Contado">Contado</td>'
            . '<td data-order="0">0</td>'
            . '<td data-order="9091">9.091</td>'
            . '<td data-order="909">909</td>'
            . '<td data-order="9091">9.091</td>'
            . '<td data-order="10000">10.000</td>'
            . '</tr>';
    }

    return (string) json_encode(['table' => $thead . '<tbody>' . $filas . '</tbody>']);
}

/**
 * Legacy con un listado GRANDE y un TOPE de filas por request.
 *
 * Es lo que se llevó el 96% del histórico en la primera corrida real: el
 * legacy capa en 100 filas y contesta 200 con una tabla perfectamente formada,
 * así que "300 ventas importadas, 0 errores" era en realidad el techo tres
 * veces. El fixture reproduce las dos variantes que importan:
 *
 *   · `$pagina = false` — ignora los parámetros de paginación (el deploy que
 *     nos mordió). El export tiene que ABORTAR, no asentar 100 de 250.
 *   · `$pagina = true` — respeta `part/offset/limit`, pero con un techo PROPIO
 *     por página más chico que lo pedido. Es el caso que obliga a avanzar el
 *     offset por filas LEÍDAS y no por página pedida: avanzando de a 1000 se
 *     saltearía todo lo del medio en silencio.
 */
class ListadoLargoEncomClient extends FixtureEncomClient
{
    /** @var array<int,array<string,mixed>> Parámetros de cada pedido al listado. */
    public array $pedidos = [];

    public function __construct(
        string $dir,
        private readonly int $filas,
        private readonly bool $pagina,
        private readonly int $porPagina = 100,
    ) {
        parent::__construct($dir);
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $action = (string) ($params['action'] ?? '');

        if ($path === '/a_report_transactions' && $action === 'detailTable') {
            $this->pedidos[] = $params;

            // Sin parámetros de paginación, el legacy contesta su tope.
            $desde   = 0;
            $cuantas = min(100, $this->filas);

            $lePiden = isset($params['part']) || isset($params['start']);
            if ($this->pagina && $lePiden) {
                $desde   = (int) ($params['offset'] ?? $params['start'] ?? 0);
                $pedidas = (int) ($params['limit'] ?? $params['length'] ?? 100);
                $cuantas = max(0, min($pedidas, $this->porPagina, $this->filas - $desde));
            }

            $ids = [];
            for ($i = $desde; $i < $desde + $cuantas; $i++) {
                $ids[] = 'tx-lote-' . $i;
            }

            return ventasHtml($ids);
        }

        // El log de ítems vendidos, VACÍO: estos casos miden el listado de
        // ventas y nada más.
        if ($path === '/a_report_products') {
            return (string) json_encode(['table' => '']);
        }

        return parent::get($path, $params, $allowRedirect);
    }
}

/**
 * El LOG DE ÍTEMS VENDIDOS con el shape REAL del legacy: el `data-id` de cada
 * fila es el de su VENTA (`enc(transactionId)` en `a_report_products.php`), así
 * que todas las líneas de una venta comparten id.
 *
 * Es el incidente del job 71e8282d: con el id de la venta como identidad de la
 * fila, una venta de tres líneas partida entre dos páginas se leía como "el
 * listado dejó de avanzar" y abortaba el dominio; y como la idempotencia por
 * línea usaba ese mismo id, solo habría entrado la PRIMERA línea de cada venta.
 *
 * `$nolimit`: si el deploy respeta `nolimit=1` (la ventana entera, sin OFFSET).
 */
final class LogPartidoEncomClient extends FixtureEncomClient
{
    /** @var array<int,array<string,mixed>> */
    public array $pedidosLog = [];

    public function __construct(
        string $dir,
        private readonly int $ventas,
        private readonly bool $nolimit,
        private readonly int $porPagina = 50,
        // Un log que devuelve SIEMPRE el tope, ignore lo que se le pida.
        private readonly bool $truncado = false,
        private readonly string $prefijo = 'lp-',
    ) {
        parent::__construct($dir);
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $action = (string) ($params['action'] ?? '');

        if ($path === '/a_report_transactions' && $action === 'detailTable') {
            $filas = '';
            for ($i = 0; $i < $this->ventas; $i++) {
                $filas .= '<tr data-id="' . $this->prefijo . $i . '">'
                    . '<td data-order="' . $this->prefijo . $i . '">x</td>'
                    . '<td data-order="16543210">16543210</td>'
                    . '<td data-order="001-001-' . (5000 + $i) . '">x</td>'
                    . '<td data-order="2026-08-10 12:00:00">10 ago</td><td data-order="12:00">12:00</td>'
                    . '<td data-order="">-</td><td data-order="">-</td><td data-order="">-</td>'
                    . '<td data-order="Pedro Cajero">Pedro Cajero</td><td data-order="Casa Central">Casa Central</td>'
                    . '<td data-order="Caja Uno">Caja Uno</td><td data-order="Sí">Sí</td><td data-order="Efectivo">Efectivo</td>'
                    . '<td data-order="">-</td><td data-order="">-</td><td data-order="Factura">Factura</td>'
                    . '<td data-order="Contado">Contado</td><td data-order="0">0</td><td data-order="2727">x</td>'
                    . '<td data-order="273">x</td><td data-order="2727">x</td><td data-order="3000">x</td></tr>';
            }
            $thead = '<thead><tr><th>ID</th><th>#Autorización</th><th>#Documento</th><th>Fecha</th><th>Hora</th>'
                . '<th>Vencimiento</th><th>Cliente</th><th>RUC</th><th>Usuario</th><th>Sucursal</th>'
                . '<th>Caja</th><th>Caja FE Activa</th><th>M.de Pago</th><th>Nota</th><th>Etiquetas</th>'
                . '<th>Tipo Documento</th><th>Tipo</th><th>Descuento</th><th>Subtotal</th><th>IVA</th>'
                . '<th>Total Gravado</th><th>Total</th></tr></thead>';
            return (string) json_encode(['table' => $thead . '<tbody>' . $filas . '</tbody>']);
        }

        if ($path === '/a_report_products' && $action === 'detailTable') {
            $this->pedidosLog[] = $params;

            // 3 líneas por venta, en el orden del legacy (por fecha: las de una
            // venta quedan juntas). La venta 0 trae DOS líneas IDÉNTICAS: son
            // dos cafés, no una fila repetida.
            $todas = [];
            for ($i = 0; $i < $this->ventas; $i++) {
                $arts = $i === 0 ? ['Cafe Doble', 'Cafe Doble', 'Tostado'] : ['Cafe Doble', 'Tostado', 'Jugo'];
                foreach ($arts as $art) {
                    $todas[] = [$i, $art];
                }
            }

            if ($this->truncado) {
                $desde   = 0;
                $cuantas = 100;
            } elseif (!empty($params['nolimit']) && $this->nolimit) {
                $desde = 0;
                $cuantas = count($todas);
            } elseif (isset($params['part'])) {
                $desde   = (int) ($params['offset'] ?? 0);
                $cuantas = min((int) ($params['limit'] ?? 100), $this->porPagina);
            } else {
                $desde   = 0;
                $cuantas = 100;   // el tope del legacy
            }

            $filas = '';
            foreach (array_slice($todas, $desde, $cuantas) as [$i, $art]) {
                $filas .= '<tr data-id="' . $this->prefijo . $i . '" class="clickrow pointer">'
                    . '<td>Casa Central</td><td>Caja Uno</td><td class="text-right">001-001-' . (5000 + $i) . '</td>'
                    . '<td>Pedro Cajero</td><td data-filter=""></td>'
                    . '<td data-order="2026-08-10 12:00:00">10 ago</td>'
                    . '<td data-filter=""> ' . $art . ' </td><td> </td><td> </td><td> </td>'
                    . '<td class="tdNumeric" data-order="1"> 1 </td><td class="tdNumeric" data-order="0"> 0 </td>'
                    . '<td class="tdNumeric" data-order="400"> 400 </td><td class="tdNumeric" data-order="91"> 91 </td>'
                    . '<td class="tdNumeric" data-order="0"> 0 </td><td class="tdNumeric" data-order="600"> 600 </td>'
                    . '<td class="tdNumeric" data-order="1000"> 1.000 </td></tr>';
            }
            $thead = '<thead class="text-u-c"><tr><th class="ignored">Sucursal</th><th>Caja</th><th># Documento</th>'
                . '<th>Usuario</th><th>Cliente</th><th class="no-search">Fecha</th><th>Nombre</th><th>Código/SKU</th>'
                . '<th>Marca</th><th>Categoría</th><th>Cantidad</th><th>Comisión</th><th>Costo</th><th>IVA</th>'
                . '<th>Descuentos</th><th>Utilidad</th><th>Total</th></tr></thead>';
            return (string) json_encode(['table' => $thead . '<tbody>' . $filas . '</tbody>']);
        }

        return parent::get($path, $params, $allowRedirect);
    }
}

/** Legacy cuyo equipo tiene usuarios de rol "Jefe", el más alto de su escala. */
final class JefeEncomClient extends FixtureEncomClient
{
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'users') {
            return [
                ['userId' => 'usr-j1', 'name' => 'Duenio Del Comercio', 'roleName' => 'Jefe', 'role' => '1'],
                ['userId' => 'usr-j2', 'name' => 'Soporte Sistema Anterior', 'roleName' => 'Jefe', 'role' => '1'],
                ['userId' => 'usr-j3', 'name' => 'Admin Base', 'roleName' => 'Admin. Base', 'role' => '3'],
                ['userId' => 'usr-j4', 'name' => 'Cajero Base', 'roleName' => 'Cajero Base', 'role' => '5'],
            ];
        }
        return parent::fetch($load, $outletHash);
    }
}

/**
 * Clientes con los tres casos de duplicado del job 71e8282d, más la fila sin
 * id propio del legacy (contacto sin `contactUID`).
 */
final class DuplicadosEncomClient extends FixtureEncomClient
{
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'customers') {
            return [
                ['customerId' => 'dup-a', 'name' => 'Juan Perez', 'ci' => '1234567', 'phone' => '0981555111'],
                // 1. MISMO documento: la misma persona cargada dos veces.
                ['customerId' => 'dup-b', 'name' => 'JUAN PEREZ', 'ci' => '1.234.567', 'phone' => '0981999888'],
                // 2. OTRA persona con el teléfono de dup-a.
                ['customerId' => 'dup-c', 'name' => 'Otra Persona', 'ci' => '7654321', 'phone' => '0981555111',
                 'note' => 'Cliente de los martes'],
                // 3. Teléfono que no es un número.
                ['customerId' => 'dup-d', 'name' => 'Tel Malo', 'ci' => '1111111', 'phone' => '12'],
                // 1 y 2 a la vez: manda el documento.
                ['customerId' => 'dup-e', 'name' => 'Juan P.', 'ci' => '1234567', 'phone' => '0981555111'],
                // Sin id del legacy.
                ['name' => 'Cliente Sin Id', 'ci' => '9999999'],
            ];
        }
        return parent::fetch($load, $outletHash);
    }
}

/**
 * Clientes del legacy para el caso CV: relanzar sobre clientes que una versión
 * vieja del migrador importó casi vacíos (el caso real de Don Ramón).
 */
final class CompletarEncomClient extends FixtureEncomClient
{
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'customers') {
            return [
                // Todo vacío en Punto: se completa todo.
                ['customerId' => 'cv-1', 'name' => 'Ana Vacia', 'ci' => '2000001', 'phone' => '0981200001',
                 'email' => 'ana@legacy.com', 'address' => 'Calle Uno 123', 'city' => 'Asuncion',
                 'note' => 'Nota del legacy', 'birthDay' => '1990-05-06', 'creditLine' => 500000],
                // Editado en Punto (teléfono y email): no se pisa; lo vacío sí se completa.
                ['customerId' => 'cv-2', 'name' => 'Beto Editado', 'ci' => '2000002', 'phone' => '0981200002',
                 'email' => 'beto@legacy.com', 'address' => 'Calle Dos 456'],
                // Su teléfono lo tiene OTRO cliente en Punto (el de Beto, editado): va a la nota.
                ['customerId' => 'cv-3', 'name' => 'Caro TelRep', 'phone' => '0981200099', 'note' => 'Viene los lunes'],
                // Ya tiene dirección en Punto: la del legacy no se toca.
                ['customerId' => 'cv-4', 'name' => 'Dani ConDir', 'address' => 'Otra Direccion Legacy',
                 'city' => 'Luque'],
                // Su documento ya lo tiene OTRO cliente de Punto (Beto, completado arriba).
                ['customerId' => 'cv-5', 'name' => 'Eva DocRep', 'ci' => '2000002', 'email' => 'eva@legacy.com'],
                // Teléfono inválido: va a la nota.
                ['customerId' => 'cv-6', 'name' => 'Fede TelMalo', 'phone' => '12'],
                // Sin id del legacy: advertencia, no error.
                ['name' => 'Primer Cliente'],
            ];
        }
        return parent::fetch($load, $outletHash);
    }
}

/**
 * El legacy como lo leía el migrador ANTES del arreglo: el detalle de compras
 * no devuelve ninguna fila legible. Sirve para dejar una compra importada SIN
 * líneas —el estado en que quedaron las 246 del job 71e8282d— y probar que
 * relanzar la completa.
 */
final class SinDetalleComprasEncomClient extends FixtureEncomClient
{
    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        if ($path === '/a_report_purchases' && ($params['action'] ?? '') === 'detailTable') {
            return '';
        }
        return parent::get($path, $params, $allowRedirect);
    }
}

/**
 * El cliente de las ventas con la celda REAL del legacy
 * (`a_report_transactions.php`): `data-filter="{contactName}
 * {contactSecondName} con:cliente"` o `data-filter="sin:cliente"`, y el RUC
 * como texto sin `data-order`. Nombres tomados de la bitácora del job
 * b580baa3 (tenant 019ff24f), incluido el `?` que el legacy guardó en lugar
 * de la Ñ del lado de los contactos.
 */
final class ClientesDeVentasEncomClient extends FixtureEncomClient
{
    /** [id, celda Cliente (data-filter), RUC] */
    public const VENTAS = [
        ['vc-1', 'ACUÑA FRANCO ALEXIS ANTONIO  con:cliente', '-'],
        ['vc-2', 'LEGUIZAMON GONZALEZ MARIA PERLA PLG INGENIERIA con:cliente', '-'],
        ['vc-3', 'Juan Gomez  con:cliente', '-'],
        ['vc-4', 'Juan Gomez  con:cliente', '80011122-3'],
        ['vc-5', 'sin:cliente', '-'],
        ['vc-6', 'Sin Nombre  con:cliente', '-'],
        ['vc-7', 'Fulano Inexistente  con:cliente', '-'],
        ['vc-8', 'CONSUMIDOR FINAL  con:cliente', '-'],
        ['vc-9', 'ESTIGARRIBIA GARCIA, FABIOLA INES ESTIGARRIBIA GARCIA, FABIOLA INES con:cliente', '-'],
    ];

    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'customers') {
            return [
                ['customerId' => 'vc-c1', 'name' => 'ACU?A FRANCO ALEXIS ANTONIO', 'ci' => '5414926'],
                ['customerId' => 'vc-c2', 'name' => 'LEGUIZAMON GONZALEZ MARIA PERLA',
                    'fullName' => 'PLG INGENIERIA', 'ci' => '3300111'],
                // Dos homónimos: sin RUC en la celda NO se adivina cuál.
                ['customerId' => 'vc-c3', 'name' => 'Juan Gomez', 'ruc' => '80011122-3', 'ci' => '4400111'],
                ['customerId' => 'vc-c4', 'name' => 'Juan Gomez', 'ruc' => '80044455-6', 'ci' => '4400222'],
                ['customerId' => 'vc-c5', 'name' => 'CONSUMIDOR FINAL', 'ci' => '4400333'],
                ['customerId' => 'vc-c6', 'name' => 'ESTIGARRIBIA GARCIA, FABIOLA INES', 'ci' => '4400444'],
            ];
        }
        return parent::fetch($load, $outletHash);
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        $action = (string) ($params['action'] ?? '');
        if ($path === '/a_report_transactions' && $action === 'detailTable') {
            return self::ventas();
        }
        if ($path === '/a_report_products' && $action === 'detailTable') {
            // El log de ítems vacío: estos casos miden el CLIENTE.
            $base = (string) parent::get($path, $params, $allowRedirect);
            $html = EncomParse::tableHtml($base);
            $thead = substr($html, 0, (int) strpos($html, '<tbody>'));
            return (string) json_encode(['table' => $thead . '<tbody></tbody>']);
        }
        return parent::get($path, $params, $allowRedirect);
    }

    private static function ventas(): string
    {
        $thead = '<thead><tr>'
            . '<th>ID</th><th>#Autorización</th><th>#Documento</th><th>Fecha</th><th>Hora</th>'
            . '<th>Vencimiento</th><th>Cliente</th><th>RUC</th><th>Usuario</th><th>Sucursal</th>'
            . '<th>Caja</th><th>Caja FE Activa</th><th>M.de Pago</th><th>Nota</th><th>Etiquetas</th>'
            . '<th>Tipo Documento</th><th>Tipo</th><th>Descuento</th><th>Subtotal</th><th>IVA</th>'
            . '<th>Total Gravado</th><th>Total</th></tr></thead>';

        $filas = '';
        foreach (self::VENTAS as $n => [$id, $cliente, $ruc]) {
            $filas .= '<tr data-id="' . $id . '" class="clickrow ">'
                . '<td class="bg-light dk">' . $id . '</td>'
                . '<td>16543210</td>'
                . '<td data-order="' . (7000 + $n) . '">001-001-000' . (7000 + $n) . '</td>'
                . '<td data-order="2026-08-1' . $n . ' 10:30:00">1' . $n . ' ago</td>'
                . '<td> 10:30 </td>'
                . '<td data-order="">-</td>'
                . '<td data-filter="' . htmlspecialchars($cliente) . '">' . htmlspecialchars(explode('  ', $cliente)[0]) . '</td>'
                . '<td>' . $ruc . '</td>'
                . '<td>Pedro Cajero</td>'
                . '<td>Casa Central</td>'
                . '<td>Caja Uno</td>'
                . '<td>Sí</td>'
                . '<td>Efectivo</td>'
                . '<td></td>'
                . '<td data-tags=""> </td>'
                . '<td>Factura</td>'
                . '<td data-filter="contado"> Contado </td>'
                . '<td data-order="0">0</td>'
                . '<td data-order="9091">9.091</td>'
                . '<td data-order="909">909</td>'
                . '<td data-order="9091">9.091</td>'
                . '<td data-order="10000">10.000</td>'
                . '</tr>';
        }
        return (string) json_encode(['table' => $thead . '<tbody>' . $filas . '</tbody>']);
    }
}

/**
 * Legacy con UNA sola sucursal y una caja: el caso del comercio chico que se
 * dio de alta en Punto (el signup le creó "Central") y después migró.
 */
final class UnaSucursalEncomClient extends FixtureEncomClient
{
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'outlets') {
            return [[
                'outletId'    => 'una-out-1',
                'name'        => 'Casa Central',
                'outletRazon' => 'Una Sucursal SA',
            ]];
        }
        if ($load === 'registers') {
            return [
                'registers' => [[
                    'registerId'    => 'una-reg-1',
                    'name'          => 'Caja Legacy',
                    'outletId'      => 'una-out-1',
                    'invoicePrefix' => '003-001-',
                    'invoiceAuthNo' => '17777777',
                    'leadingZero'   => 7,
                ]],
                'docsNum' => [['registerId' => 'una-reg-1', 'invoiceNo' => 40]],
            ];
        }
        return parent::fetch($load, $outletHash);
    }
}

/** El export con las sucursales en el orden inverso (caso Y6). */
final class OrdenInvertidoEncomClient extends FixtureEncomClient
{
    protected function fetch(string $load, ?string $outletHash = null): array
    {
        $data = parent::fetch($load, $outletHash);
        return $load === 'outlets' ? array_reverse($data) : $data;
    }
}

/** Variante del caso E: dos cajas con el mismo (timbrado, punto). */
final class ClashEncomClient extends EncomClient
{
    public function __construct()
    {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture'], 'QE22', '62Lm');
    }

    protected function fetch(string $load, ?string $outletHash = null): array
    {
        if ($load === 'outlets') {
            return [[
                'outletId'    => 'out-1',
                'name'        => 'Casa Central',
                'outletRazon' => 'Con Choque SA',
            ]];
        }
        if ($load === 'registers') {
            return [
                'registers' => [
                    [
                        'registerId'    => 'dup-1',
                        'name'          => 'Caja Uno',
                        'outletId'      => 'out-1',
                        'invoicePrefix' => '001-001-',
                        'invoiceAuthNo' => '16543210',
                        'leadingZero'   => 7,
                    ],
                    [
                        'registerId'    => 'dup-2',
                        'name'          => 'Caja Dos',
                        'outletId'      => 'out-1',
                        'invoicePrefix' => '001-001-',
                        'invoiceAuthNo' => '16543210',
                        'leadingZero'   => 7,
                    ],
                ],
                'docsNum' => [
                    ['registerId' => 'dup-1', 'invoiceNo' => 100],
                    ['registerId' => 'dup-2', 'invoiceNo' => 250],
                ],
            ];
        }
        return [];
    }
}

/**
 * Sonda del ALCANCE: de dónde salen companyId y outletId del legacy.
 *
 * Es la pieza que, si se rompe, importa el comercio EQUIVOCADO — o ninguno. No
 * se puede probar contra el sistema real, así que se simulan las dos vías: el
 * header `Location` del redirect de `pos-redirect` y el HTML del home del panel.
 */
final class ScopeProbeClient extends EncomClient
{
    public function __construct(
        private readonly ?string $location,
        private readonly string $body,
    ) {
        parent::__construct('https://legacy.test', ['PHPSESSID' => 'fixture']);
    }

    protected function lastLocation(): ?string
    {
        return $this->location;
    }

    protected function get(string $path, array $params = [], bool $allowRedirect = false): string
    {
        return $this->body;
    }

    /** @return array{companyId:string,outletId:string} */
    public function resolveNow(): array
    {
        $this->resolveScope();
        return $this->scope();
    }
}

/**
 * Sonda del HOST: contra QUÉ dirección sale cada request.
 *
 * Es la costura del incidente del 2026-09-11. El legacy son DOS aplicaciones
 * con dominios distintos —el panel (login, reportes, costos) y el POS, que es
 * donde vive `/fetchs`— y el cliente las trataba como una sola: todo salía
 * contra el panel, que contesta 404 a `/fetchs`.
 *
 * El fixture pone los dos hosts DISTINTOS a propósito: con una sola base (el
 * caso que el arnés anterior simulaba) el bug era invisible.
 */
final class HostProbeClient extends EncomClient
{
    /** @var array<int,string> URLs absolutas pedidas, en orden. */
    public array $urls = [];

    public function __construct(string $posUrl = 'https://app.encom.test')
    {
        parent::__construct('https://panel.encom.test', ['PHPSESSID' => 'fixture'], 'QE22', '62Lm', $posUrl);
    }

    protected function send(
        string $method,
        string $url,
        ?string $body,
        ?string $contentType,
        bool $allowRedirect
    ): string {
        $this->urls[] = $url;

        // `/fetchs` contesta JSON; las pantallas del panel, HTML.
        return str_contains($url, '/fetchs') ? '[]' : '<table></table>';
    }
}

/**
 * Sonda del cuerpo del LOGIN.
 *
 * El login no se puede ejercitar entero sin red, pero lo que se rompió —y de
 * forma invisible desde este lado, porque el legacy contesta 200 igual— fueron
 * los `name` del form: se mandaba `phone`/`iso`, que el deploy VIVO no tiene.
 */
final class LoginBodyProbe extends EncomClient
{
    public static function body(string $identifier, string $password, string $phoneCode = ''): string
    {
        return parent::loginBody($identifier, $password, $phoneCode);
    }
}

function seedCompany(string $companyId, string $name): void
{
    global $db;
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
        [
            $companyId,
            json_encode([
                'settingName'              => $name,
                'settingCountry'           => 'PY',
                'settingCurrency'          => 'PYG',
                'settingTimeZone'          => 'America/Asuncion',
                'settingDecimal'           => 0,
                'settingThousandSeparator' => '.',
            ], JSON_UNESCAPED_UNICODE),
        ]
    );
}

function scalar(string $sql, array $params): mixed
{
    global $db;
    return $db->GetOne($sql, $params);
}

function countOf(string $table, string $companyId): int
{
    return (int) scalar("SELECT count(*) FROM $table WHERE companyId = ?", [$companyId]);
}

function cleanup(string $companyId): void
{
    global $db;
    foreach ([
        'DELETE FROM migration_map WHERE companyid = ?',
        'DELETE FROM migration_job WHERE companyid = ?',
        'DELETE FROM document_sequence WHERE companyid = ?',
        'DELETE FROM item_compound WHERE companyId = ?',
        'DELETE FROM item_category WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_brand    WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_tag      WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        'DELETE FROM item_outlet   WHERE itemId IN (SELECT itemId FROM item WHERE companyId = ?)',
        // El ledger ANTES que los artículos: `stock.itemId` es FK dura y sin
        // esta línea el DELETE de `item` falla y deja la empresa a medio limpiar.
        'DELETE FROM stock WHERE companyId = ?',
        'DELETE FROM item WHERE companyId = ?',
        'DELETE FROM tax WHERE companyId = ?',
        'DELETE FROM category WHERE companyId = ?',
        'DELETE FROM brand WHERE companyId = ?',
        'DELETE FROM tag WHERE companyId = ?',
        // Usuarios: `contact_outlet` cuelga del contacto, y los roles del
        // tenant viven en `taxonomy` (los borra la línea de más abajo).
        'DELETE FROM contact_outlet WHERE companyid = ?',
        // `customeraddress` cuelga de `contact` con FK dura.
        'DELETE FROM customeraddress WHERE customerId IN (SELECT contactId FROM contact WHERE companyId = ?)',
        'DELETE FROM contact WHERE companyId = ?',
        'DELETE FROM register WHERE companyId = ?',
        // Los roles del tenant NO tienen tabla propia: viven en `taxonomy`
        // (type='role') y sus permisos en el JSONB de esa misma fila, así que
        // la línea de abajo se los lleva.
        'DELETE FROM taxonomy WHERE companyId = ?',
        'DELETE FROM outlet WHERE companyId = ?',
        'DELETE FROM company WHERE companyId = ?',
    ] as $sql) {
        try {
            $db->Execute($sql, [$companyId]);
        } catch (\Throwable $e) {
            // Una tabla ausente en este schema no invalida el cleanup.
        }
    }
}

$fixtures = __DIR__ . '/fixtures/encom';

cleanup($companyId);
cleanup($companyB);
cleanup($companyH);
cleanup($companyI);
cleanup($companyJ);
cleanup($companyK);
cleanup($companyL);
cleanup($companyM);
cleanup($companyN);
cleanup($companyO);
cleanup($companyP);
cleanup($companyQ);
cleanup($companyR);
cleanup($companyC);
cleanup($companyE);
cleanup($companyF);

try {
    // ══════════════════════════════════════════════════════════════════
    // S. ALCANCE — de dónde salen companyId y outletId
    // ══════════════════════════════════════════════════════════════════
    // Literal tomado del sistema vivo: base64('PnXa,KLzV').
    $iParam = 'UG5YYSxLTHpW';

    $porRedirect = (new ScopeProbeClient('https://app.encom.com.py/?i=' . $iParam, ''))->resolveNow();

    check(
        'S1 · el alcance sale del ?i= del redirect de pos-redirect (base64 → "companyId,outletId")',
        $porRedirect === [
            'companyId' => 'PnXa',
            'outletId'  => 'KLzV',
            // El host del POS sale de la MISMA URL: es el origen del redirect.
            'posUrl'    => 'https://app.encom.com.py',
        ],
        'scope = ' . json_encode($porRedirect),
        $failures, $checks
    );

    // Fallback: el deploy viejo no tiene /bff/pos-redirect.php, así que el
    // mismo `?i=` se busca en el href del botón "Caja" del home del panel.
    $homeHtml = '<ul><li><a href="/a_items">Artículos</a></li>'
        . '<li><a id="mnPOSBtn" href="https://app.encom.com.py/?i=' . $iParam . '">Caja</a></li></ul>';

    $porHome = (new ScopeProbeClient(null, $homeHtml))->resolveNow();

    check(
        'S2 · sin pos-redirect, el alcance sale del href del botón "Caja" del panel',
        $porHome === [
            'companyId' => 'PnXa',
            'outletId'  => 'KLzV',
            'posUrl'    => 'https://app.encom.com.py',
        ],
        'scope = ' . json_encode($porHome),
        $failures, $checks
    );

    $scopeErr = '';
    try {
        (new ScopeProbeClient(null, '<html><body>nada que ver</body></html>'))->resolveNow();
    } catch (\Throwable $e) {
        $scopeErr = $e->getMessage();
    }

    check(
        'S3 · si NINGUNA vía da el alcance, LANZA (no exporta el comercio equivocado)',
        $scopeErr !== '' && str_contains($scopeErr, 'comercio'),
        'mensaje = ' . var_export($scopeErr, true),
        $failures, $checks
    );

    // Un deploy que sirviera el enlace RELATIVO da el par pero NO el host del
    // POS. Antes eso pasaba desapercibido y todo `/fetchs` salía contra el
    // panel (404); ahora corta acá, con el operador mirando.
    $sinOrigen = '';
    try {
        (new ScopeProbeClient(null, '<a id="mnPOSBtn" href="/?i=' . $iParam . '">Caja</a>'))->resolveNow();
    } catch (\Throwable $e) {
        $sinOrigen = $e->getMessage();
    }

    check(
        'S4 · con el alcance pero sin URL absoluta, LANZA nombrando el POS (no se cae al panel)',
        $sinOrigen !== '' && str_contains($sinOrigen, 'POS'),
        'mensaje = ' . var_export($sinOrigen, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // N. HOSTS — /fetchs vive en el POS, el panel es OTRA aplicación
    // ══════════════════════════════════════════════════════════════════
    // El incidente del 2026-09-11: `post('/fetchs...')` resolvía contra
    // `ENCOM_MIGRATION_URL` (el panel), que responde 404. Los dos hosts del
    // fixture son DISTINTOS a propósito — con uno solo el bug es invisible.
    $host = new HostProbeClient();
    $host->outlets();

    check(
        'N1 · /fetchs se pide contra el host del POS, NUNCA contra el del panel',
        count($host->urls) === 1
            && str_starts_with($host->urls[0], 'https://app.encom.test/fetchs?load=outlets')
            && !str_contains($host->urls[0], 'panel.encom.test'),
        'urls = ' . json_encode($host->urls),
        $failures, $checks
    );

    $hostPanel = new HostProbeClient();
    $hostPanel->itemCosts();

    check(
        'N2 · el costo sigue saliendo del PANEL: son dos bases distintas y las dos siguen vivas',
        count($hostPanel->urls) === 1
            && str_starts_with($hostPanel->urls[0], 'https://panel.encom.test/a_items'),
        'urls = ' . json_encode($hostPanel->urls),
        $failures, $checks
    );

    $sinHost = new HostProbeClient('');
    $errHost = '';
    try {
        $sinHost->outlets();
    } catch (\Throwable $e) {
        $errHost = $e->getMessage();
    }

    check(
        'N3 · sin el host del POS no se pide NADA (fail-closed, no hay respaldo contra el panel)',
        $errHost !== '' && str_contains($errHost, 'POS') && $sinHost->urls === [],
        'error = ' . var_export($errHost, true) . ' · urls = ' . json_encode($sinHost->urls),
        $failures, $checks
    );

    seedCompany($companyE, 'Sin Host SA');

    $runSinHost = (new EncomImportService($companyE, new HostProbeClient(''), null))
        ->run(['catalog', 'config']);

    check(
        'N4 · el job falla con la causa y no escribe una sola fila (ni artículos ni sucursales)',
        str_contains(json_encode($runSinHost['errors'], JSON_UNESCAPED_UNICODE), 'POS')
            && countOf('item', $companyE) === 0
            && countOf('outlet', $companyE) === 0,
        'errors = ' . json_encode($runSinHost['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // L. LOGIN — los campos del form del deploy VIVO
    // ══════════════════════════════════════════════════════════════════
    parse_str(LoginBodyProbe::body('cliente@example.com', 'secreta 1'), $loginFields);

    check(
        'L1 · el login manda email+password, NUNCA phone/iso',
        array_keys($loginFields) === ['email', 'password']
            && ($loginFields['password'] ?? '') === 'secreta 1',
        'campos = ' . json_encode($loginFields, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // El JS del form vivo antepone el código de país del desplegable cuando lo
    // tipeado es numérico; lo guardado va SIN el 0 troncal (verificado 2026-09-18).
    parse_str(LoginBodyProbe::body('0984123456', 'x', '+595'), $phoneFields);

    check(
        'L2 · un celular viaja con el código de país ADELANTE y sin el 0 troncal (como está guardado)',
        ($phoneFields['email'] ?? '') === '+595984123456',
        'email = ' . var_export($phoneFields['email'] ?? null, true),
        $failures, $checks
    );

    parse_str(LoginBodyProbe::body('cliente@example.com', 'x', '+54'), $mailFields);

    check(
        'L3 · un email viaja tal cual aunque venga un código de país',
        ($mailFields['email'] ?? '') === 'cliente@example.com',
        'email = ' . var_export($mailFields['email'] ?? null, true),
        $failures, $checks
    );

    parse_str(LoginBodyProbe::body('+595984123456', 'x', '+595'), $plusFields);

    check(
        'L4 · un número que ya trae "+" no se toca (no se duplica el código)',
        ($plusFields['email'] ?? '') === '+595984123456',
        'email = ' . var_export($plusFields['email'] ?? null, true),
        $failures, $checks
    );

    // Con espacios `$.isNumeric` da false y el navegador no antepone nada.
    parse_str(LoginBodyProbe::body('0981 123456', 'x', '+595'), $spaceFields);

    check(
        'L5 · con espacios no es "numérico": viaja tal cual, sin código (igual que el navegador)',
        ($spaceFields['email'] ?? '') === '0981 123456',
        'email = ' . var_export($spaceFields['email'] ?? null, true),
        $failures, $checks
    );

    $rechazos = [];
    foreach (
        [
            'código inválido' => ['0984123456', '595'],
            'código largo'    => ['0984123456', '+59512'],
            'código basura'   => ['cliente@example.com', '+5a'],
            'celular sin código' => ['0984123456', ''],
        ] as $caso => [$idf, $code]
    ) {
        try {
            LoginBodyProbe::body($idf, 'x', $code);
            $rechazos[$caso] = 'NO lanzó';
        } catch (\Punto\Api\Admin\EncomMigrationException $e) {
            $rechazos[$caso] = $e->status() === 422 ? 'ok' : 'status ' . $e->status();
        }
    }

    check(
        'L6 · código de país inválido o celular sin código se rechazan con 422 (antes de tocar la red)',
        array_unique(array_values($rechazos)) === ['ok'],
        json_encode($rechazos, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // X. EXPORT — el mapeo de cada dominio de /fetchs
    // ══════════════════════════════════════════════════════════════════
    $client = new FixtureEncomClient($fixtures);

    $items = $client->items();
    check(
        'X1 · se leen los 9 artículos con su kind del legacy',
        count($items) === 9 && ($items[0]['name'] ?? '') === 'Café Espresso',
        'items = ' . json_encode(array_column($items, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X2 · la composición inline viaja cruda para la segunda pasada',
        str_contains((string) ($items[3]['compound'] ?? ''), 'itm-5')
            && ($items[3]['kind'] ?? '') === 'combo',
        'compound = ' . var_export($items[3]['compound'] ?? null, true),
        $failures, $checks
    );

    $cats = $client->categories();
    check(
        'X3 · las categorías se derivan de los artículos por categoryId y "-" no es categoría',
        count($cats) === 4,
        'categorías = ' . json_encode(array_column($cats, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $regs = $client->registers();
    check(
        'X4 · registers junta cada caja con SU último correlativo de docsNum',
        count($regs) === 3
            && ($regs[0]['invoiceNo'] ?? 0) === 2128
            && ($regs[0]['quoteNo'] ?? 0) === 15
            && ($regs[0]['returnNo'] ?? 0) === 3,
        'caja = ' . json_encode($regs[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X5 · el punto de expedición se normaliza: "001-001-" → "001-001"',
        ($regs[0]['prefix'] ?? '') === '001-001' && ($regs[0]['invoiceAuth'] ?? '') === '16543210',
        'prefix = ' . var_export($regs[0]['prefix'] ?? null, true),
        $failures, $checks
    );

    $usuarios = $client->users();
    check(
        'X6 · los usuarios traen su PIN y el nombre de su rol legacy',
        count($usuarios) === 3
            && ($usuarios[0]['lockPass'] ?? '') === '1234'
            && ($usuarios[0]['roleName'] ?? '') === 'Administrador',
        'usuarios = ' . json_encode($usuarios[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $pms = $client->paymentMethods();
    check(
        'X7 · los medios de pago salen de settings.paymentMethods',
        count($pms) === 3 && ($pms[1]['name'] ?? '') === 'Transferencia',
        'medios = ' . json_encode(array_column($pms, 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'X8 · las etiquetas salen de settings.tags (lista de nombres)',
        count($client->tags()) === 2,
        'tags = ' . json_encode(array_column($client->tags(), 'name'), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $clientes = $client->customers();
    check(
        'X9 · el cliente trae id propio, documento, saldo a favor y línea de crédito',
        ($clientes[0]['ID'] ?? '') === 'cus-1'
            && ($clientes[0]['creditLine'] ?? 0) == 1000000
            && ($clientes[0]['storeCredit'] ?? 0) == 50000,
        'cliente = ' . json_encode($clientes[0] ?? null, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // A. Import completo
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyId, 'Comercio Migrado SA');

    $run1 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'suppliers', 'config', 'users', 'payments', 'stock']);

    $p1 = $run1['progress'];

    // Los únicos errores esperados son los de COMPOSICIÓN: el fixture trae a
    // propósito tres combos que no se pueden componer (R1), y desde el job
    // 71e8282d toda falla deja su línea en `errors` en vez de solo sumar al
    // contador (R7).
    $erroresNoCompound = array_values(array_filter(
        $run1['errors'],
        static fn(array $e) => ($e['domain'] ?? '') !== 'compound'
    ));
    check(
        'A1 · no hubo errores en el import (fuera de los combos que el fixture rompe a propósito)',
        $erroresNoCompound === [],
        'errores: ' . json_encode($run1['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── V. Proveedores ────────────────────────────────────────────────
    check(
        'V1 · los 3 proveedores del panel entran como contactos PROVEEDOR (type 2)',
        ($p1['supplier']['imported'] ?? 0) === 3
            && (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 2', [$companyId]) === 3,
        'progress.supplier = ' . json_encode($p1['supplier'] ?? null)
            . ' · errores = ' . json_encode($run1['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $supRuiz = EncomMigrationService::mapped($companyId, 'supplier', 'sup-1');
    check(
        'V2 · el proveedor conserva razón social, RUC y encargado (no se confunde con el CLIENTE homónimo)',
        $supRuiz !== null
            && (string) scalar('SELECT contactName FROM contact WHERE contactId = ?', [$supRuiz]) === 'Carlos Ruiz'
            && (string) scalar('SELECT contactTIN FROM contact WHERE contactId = ?', [$supRuiz]) === '4567890-1'
            && (string) scalar("SELECT data->>'contactSecondName' FROM contact WHERE contactId = ?", [$supRuiz]) === 'Carlos'
            && $supRuiz !== EncomMigrationService::mapped($companyId, 'customer', 'cus-2'),
        'supplier sup-1 → ' . var_export($supRuiz, true),
        $failures, $checks
    );

    $supMalo = EncomMigrationService::mapped($companyId, 'supplier', 'sup-3');
    check(
        'V3 · el proveedor con teléfono inválido entra SIN teléfono y con el número en la nota',
        $supMalo !== null
            && (string) scalar('SELECT COALESCE(contactPhone, \'\') FROM contact WHERE contactId = ?', [$supMalo]) === ''
            && str_contains((string) scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$supMalo]), '123'),
        'nota = ' . var_export($supMalo === null ? null : scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$supMalo]), true),
        $failures, $checks
    );

    check(
        'A2 · categorías (4), marcas (1) y etiquetas (2)',
        ($p1['category']['imported'] ?? 0) === 4
            && ($p1['brand']['imported'] ?? 0) === 1
            && ($p1['tag']['imported'] ?? 0) === 2,
        'progress = ' . json_encode([$p1['category'] ?? null, $p1['brand'] ?? null, $p1['tag'] ?? null]),
        $failures, $checks
    );

    check(
        'A3 · artículos importados (9)',
        ($p1['item']['imported'] ?? 0) === 9,
        'progress.item = ' . json_encode($p1['item'] ?? null),
        $failures, $checks
    );

    check(
        'A4 · clientes (2), sucursales (2) y cajas (3)',
        ($p1['customer']['imported'] ?? 0) === 2
            && ($p1['outlet']['imported'] ?? 0) === 2
            && ($p1['register']['imported'] ?? 0) === 3,
        'progress = ' . json_encode([$p1['customer'] ?? null, $p1['outlet'] ?? null, $p1['register'] ?? null]),
        $failures, $checks
    );

    check(
        'A5 · usuarios (3) y medios de pago (3)',
        ($p1['user']['imported'] ?? 0) === 3 && ($p1['payment']['imported'] ?? 0) === 3,
        'progress = ' . json_encode([$p1['user'] ?? null, $p1['payment'] ?? null]),
        $failures, $checks
    );

    $itemsInDb = countOf('item', $companyId);
    check(
        'A6 · los artículos están en la base (9)',
        $itemsInDb === 9,
        "item count = $itemsInDb",
        $failures, $checks
    );

    $contactsInDb = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 1', [$companyId]);
    check(
        'A7 · los clientes están en la base (2)',
        $contactsInDb === 2,
        "contact count = $contactsInDb",
        $failures, $checks
    );

    // El IVA del legacy ("10") es el `name` de la tabla `tax` de Punto.
    $itm1 = EncomMigrationService::mapped($companyId, 'item', 'itm-1');
    $taxOfItem = $itm1 === null ? null : scalar(
        'SELECT t.name FROM item i JOIN tax t ON t.taxId = i.taxId WHERE i.itemId = ?',
        [$itm1]
    );
    check(
        'A8 · el artículo queda con el impuesto del legacy (10)',
        (string) $taxOfItem === '10',
        'tax.name = ' . var_export($taxOfItem, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // Q. COSTO — el único dato del catálogo que NO sale de /fetchs
    // ══════════════════════════════════════════════════════════════════
    // El bootstrap del POS no manda el costo (no lo necesita para vender), así
    // que sale de la tabla del panel y se cruza por SKU o por nombre. Perderlo
    // deja en cero todo reporte de margen del comercio migrado.
    $costDe = static function (?string $itemId) {
        return $itemId === null ? null : scalar('SELECT itemCost FROM item WHERE itemId = ?', [$itemId]);
    };

    $costCafe = $costDe($itm1);
    check(
        'Q1 · el costo se cruza por SKU (CAF-001 → 5000)',
        $costCafe !== null && abs((float) $costCafe - 5000.0) < 0.0001,
        'itemCost = ' . var_export($costCafe, true),
        $failures, $checks
    );

    // La pizza no tiene SKU en /fetchs y en la tabla del panel figura con "-":
    // el cruce cae al nombre normalizado.
    $costPizza = $costDe(EncomMigrationService::mapped($companyId, 'item', 'itm-4'));
    check(
        'Q2 · sin SKU, el costo se cruza por nombre normalizado (22000)',
        $costPizza !== null && abs((float) $costPizza - 22000.0) < 0.0001,
        'itemCost = ' . var_export($costPizza, true),
        $failures, $checks
    );

    // La harina no está en la tabla del panel: entra SIN costo y con nombre y
    // apellido en la bitácora. No se inventa un 0 — "no lo sé" y "cuesta cero"
    // no son lo mismo, y un 0 falso arruina el margen de ese artículo.
    $costHarina = $costDe(EncomMigrationService::mapped($companyId, 'item', 'itm-6'));
    check(
        // NULL, no 0: el driver puede devolverlo como null, '' o false, pero un
        // 0 real (que sí sería un costo) tiene que fallar este check.
        'Q3 · lo que no matchea entra SIN costo, no con 0',
        $costHarina === null || $costHarina === '' || $costHarina === false,
        'itemCost = ' . var_export($costHarina, true),
        $failures, $checks
    );

    // La bitácora de la primera corrida: la miran este caso, R5/R6 (combos que
    // hay que revisar) y U6 (el rol asignado a cada usuario).
    $logText = json_encode($run1['log'], JSON_UNESCAPED_UNICODE);

    check(
        'Q4 · y queda nombrado en la bitácora para que soporte lo complete',
        str_contains($logText, 'Sin costo') && str_contains($logText, 'Harina 000'),
        "log = $logText",
        $failures, $checks
    );

    // El costo es un ENRIQUECIMIENTO: si el panel no lo entrega, el catálogo
    // entra igual. Es la diferencia entre migrar sin costos y no migrar.
    seedCompany($companyC, 'Comercio Sin Costos SA');
    $runSinCostos = (new EncomImportService($companyC, new SinCostosEncomClient($fixtures), null))->run(['catalog']);

    check(
        'Q5 · si el panel no entrega costos, el catálogo se importa IGUAL (9 artículos)',
        ($runSinCostos['progress']['item']['imported'] ?? 0) === 9,
        'progress.item = ' . json_encode($runSinCostos['progress']['item'] ?? null),
        $failures, $checks
    );

    check(
        'Q6 · y el job lo dice, en vez de quedar mudo',
        str_contains(json_encode($runSinCostos['log'], JSON_UNESCAPED_UNICODE), 'No se pudieron traer los costos'),
        'log = ' . json_encode($runSinCostos['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // Los errores de COMPOSICIÓN son los combos que el fixture rompe a
    // propósito (R1/R7) y no tienen que ver con los costos.
    check(
        'Q7 · el dominio catálogo no registró errores por la falta de costos',
        array_values(array_filter(
            $runSinCostos['errors'],
            static fn(array $e) => ($e['domain'] ?? '') !== 'compound'
        )) === [],
        'errores = ' . json_encode($runSinCostos['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // R. COMBOS Y RECETAS — la novedad de /fetchs
    // ══════════════════════════════════════════════════════════════════
    check(
        'R1 · se compusieron 2 artículos (el combo fijo y la receta), 3 quedaron sin componer',
        ($p1['compound']['total'] ?? 0) === 5
            && ($p1['compound']['imported'] ?? 0) === 2
            && ($p1['compound']['failed'] ?? 0) === 3,
        'progress.compound = ' . json_encode($p1['compound'] ?? null),
        $failures, $checks
    );

    $compoundRows = (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]);
    check(
        'R2 · quedaron 4 filas de receta (2 del combo + 1 de producción + 1 del mixto a medias)',
        $compoundRows === 4,
        "item_compound = $compoundRows",
        $failures, $checks
    );

    $combo = EncomMigrationService::mapped($companyId, 'item', 'itm-4');
    $masa  = EncomMigrationService::mapped($companyId, 'item', 'itm-5');
    $medialuna = EncomMigrationService::mapped($companyId, 'item', 'itm-2');

    $qty = ($combo === null || $medialuna === null) ? null : scalar(
        'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
        [$combo, $medialuna]
    );
    check(
        'R3 · el combo resuelve sus componentes por el mapa, con la cantidad del legacy (2.000 → 2)',
        $qty !== null && abs((float) $qty - 2.0) < 0.0001,
        'quantity = ' . var_export($qty, true),
        $failures, $checks
    );

    $kindCombo = $combo === null ? null : scalar('SELECT itemKind FROM item WHERE itemId = ?', [$combo]);
    $kindMasa  = $masa === null ? null : scalar('SELECT itemKind FROM item WHERE itemId = ?', [$masa]);
    check(
        'R4 · los kinds del legacy se mapean: combo → combo_fijo, direct_production → produccion_directa',
        (string) $kindCombo === 'combo_fijo' && (string) $kindMasa === 'produccion_directa',
        'kinds = ' . var_export([$kindCombo, $kindMasa], true),
        $failures, $checks
    );

    $errText = json_encode($run1['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'R5 · el combo con un componente inexistente NO se inventa: queda como ERROR para revisar, con el id',
        str_contains($errText, 'Revisar a mano') && str_contains($errText, 'Combo Roto')
            && str_contains($errText, 'itm-999'),
        "errors = $errText",
        $failures, $checks
    );

    check(
        'R6 · el combo con opciones elegibles tampoco se inventa (en Punto son grupos de add-ons)',
        str_contains($errText, 'Armá tu plato') || str_contains($logText, 'Armá tu plato'),
        "errors = $errText · log = $logText",
        $failures, $checks
    );

    // Job 71e8282d: "compound failed 2" con UNA sola explicación, y en el log
    // en vez de en los errores. Cada falla contada tiene que tener su línea.
    $erroresCompound = array_values(array_filter(
        $run1['errors'],
        static fn(array $e) => ($e['domain'] ?? '') === 'compound'
    ));
    check(
        'R7 · cada composición fallida deja SU línea en errors (3 fallidas → 3 errores)',
        count($erroresCompound) === ($p1['compound']['failed'] ?? -1),
        'failed = ' . json_encode($p1['compound'] ?? null) . ' · errores compound = ' . json_encode($erroresCompound, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'R8 · y el mensaje dice POR QUÉ el componente no está (el legacy solo exporta lo activo y vendible)',
        str_contains($errText, 'vendibles'),
        "errors = $errText",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // C. Mapeo
    // ══════════════════════════════════════════════════════════════════
    $catId = EncomMigrationService::mapped($companyId, 'category', 'cat-100');
    check(
        'C1 · migration_map mapea la categoría por su id del legacy',
        $catId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    if ($itm1 !== null && $catId !== null) {
        $itemCat = scalar('SELECT categoryId FROM item WHERE itemId = ? AND companyId = ?', [$itm1, $companyId]);
        check(
            'C2 · el artículo apunta a la categoría IMPORTADA',
            (string) $itemCat === (string) $catId,
            'item.categoryId = ' . var_export($itemCat, true) . " vs mapeada $catId",
            $failures, $checks
        );

        $m2m = (int) scalar(
            'SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?',
            [$itm1, $catId]
        );
        check(
            'C3 · la m2m item_category también quedó escrita (context/41)',
            $m2m === 1,
            "item_category count = $m2m",
            $failures, $checks
        );
    }

    check(
        'C4 · el cliente se mapea por su id del legacy (ya no por clave natural)',
        EncomMigrationService::mapped($companyId, 'customer', 'cus-1') !== null,
        'no hay mapeo para cus-1',
        $failures, $checks
    );

    $cus1 = EncomMigrationService::mapped($companyId, 'customer', 'cus-1');
    $creditLine = $cus1 === null ? null : scalar(
        "SELECT contactCreditLine FROM contact WHERE contactId = ?",
        [$cus1]
    );
    check(
        'C5 · el cliente conserva su línea de crédito (el CSV del panel no la traía)',
        $creditLine !== null && (float) $creditLine == 1000000.0,
        'contactCreditLine = ' . var_export($creditLine, true),
        $failures, $checks
    );

    // El legacy numera los tipos de documento con SU tabla (manda 1 y 2), que
    // no es la Tabla 3 de la SET que valida Punto (11..17). El código no se
    // traduce a ciegas —es un dato fiscal—, pero el NÚMERO del documento, que
    // es lo que identifica al cliente, tiene que llegar igual.
    $tin = $cus1 === null ? null : scalar('SELECT contactTIN FROM contact WHERE contactId = ?', [$cus1]);
    check(
        'C6 · el documento del cliente se migra aunque su TIPO use otra tabla de códigos',
        (string) $tin === '80099887-1',
        'contactTIN = ' . var_export($tin, true),
        $failures, $checks
    );

    // `contactCI` NO es columna: vive en el JSONB `data` desde la mig 25
    // (`contactTIN` sí es columna, de ahí que C6 la lea directo).
    $cus2 = EncomMigrationService::mapped($companyId, 'customer', 'cus-2');
    $ci   = $cus2 === null ? null : scalar("SELECT data->>'contactCI' FROM contact WHERE contactId = ?", [$cus2]);
    check(
        'C7 · el cliente con cédula (sin RUC) también entra, con su número',
        (string) $ci === '4567890',
        'contactCI = ' . var_export($ci, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // D. Continuación de numeración (D5)
    // ══════════════════════════════════════════════════════════════════
    $regId = EncomMigrationService::mapped($companyId, 'register', 'reg-1');
    check(
        'D1 · la caja reg-1 quedó mapeada',
        $regId !== null,
        'mapped() devolvió null',
        $failures, $checks
    );

    if ($regId !== null) {
        $auth = scalar("SELECT data ->> 'registerInvoiceAuth' FROM register WHERE registerId = ?", [$regId]);
        $prefix = scalar("SELECT data ->> 'registerInvoicePrefix' FROM register WHERE registerId = ?", [$regId]);
        check(
            'D2 · la caja conserva timbrado (16543210) y punto de expedición (001-001)',
            (string) $auth === '16543210' && (string) $prefix === '001-001',
            'auth = ' . var_export($auth, true) . ' / prefix = ' . var_export($prefix, true),
            $failures, $checks
        );

        $seqOf = static function (string $regId, string $docType, string $companyId, string $auth, string $prefix) {
            return scalar(
                "SELECT nextnumber FROM document_sequence
                  WHERE companyid = ? AND doctype = ? AND scopetype = 'register'
                    AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
                [$companyId, $docType, $regId, $auth, $prefix]
            );
        };

        check(
            'D3 · la FACTURA continúa la serie: nextnumber = 2129 (último emitido 2128 + 1)',
            (int) $seqOf($regId, 'factura', $companyId, '16543210', '001-001') === 2129,
            'nextnumber = ' . var_export($seqOf($regId, 'factura', $companyId, '16543210', '001-001'), true),
            $failures, $checks
        );

        // `docsNum` trae un contador POR TIPO: eso es lo que el scraping no
        // daba. La cotización no tiene serie fiscal (serie vacía) y la nota de
        // crédito hereda la de la factura (mig 215).
        check(
            'D4 · la COTIZACIÓN continúa su propio correlativo: 16 (último 15 + 1)',
            (int) $seqOf($regId, 'cotizacion', $companyId, '', '') === 16,
            'nextnumber = ' . var_export($seqOf($regId, 'cotizacion', $companyId, '', ''), true),
            $failures, $checks
        );

        check(
            'D5 · la NOTA DE CRÉDITO continúa su propio correlativo: 4 (último 3 + 1)',
            (int) $seqOf($regId, 'nota_credito', $companyId, '16543210', '001-001') === 4,
            'nextnumber = ' . var_export($seqOf($regId, 'nota_credito', $companyId, '16543210', '001-001'), true),
            $failures, $checks
        );

        $pad = scalar(
            "SELECT padwidth FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'D6 · el ancho de impresión sale de leadingZero (7 dígitos)',
            (int) $pad === 7,
            'padwidth = ' . var_export($pad, true),
            $failures, $checks
        );

        $regId2 = EncomMigrationService::mapped($companyId, 'register', 'reg-2');
        if ($regId2 !== null) {
            check(
                'D7 · cada caja continúa SU serie: la segunda arranca en 3779',
                (int) $seqOf($regId2, 'factura', $companyId, '16543210', '001-002') === 3779,
                'nextnumber = ' . var_export($seqOf($regId2, 'factura', $companyId, '16543210', '001-002'), true),
                $failures, $checks
            );
        }

        $regId3 = EncomMigrationService::mapped($companyId, 'register', 'reg-3');
        if ($regId3 !== null) {
            check(
                'D8 · una caja sin facturas emitidas arranca en 1 (no en 0)',
                (int) $seqOf($regId3, 'factura', $companyId, '16543210', '002-001') === 1,
                'nextnumber = ' . var_export($seqOf($regId3, 'factura', $companyId, '16543210', '002-001'), true),
                $failures, $checks
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // U. Usuarios
    // ══════════════════════════════════════════════════════════════════
    $usersInDb = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 0', [$companyId]);
    check(
        'U1 · los 3 usuarios están en la base como equipo (type = 0)',
        $usersInDb === 3,
        "usuarios = $usersInDb",
        $failures, $checks
    );

    $pedro = EncomMigrationService::mapped($companyId, 'user', 'usr-2');
    $rolPedro = $pedro === null ? null : scalar(
        "SELECT t.taxonomyname FROM contact c
           JOIN taxonomy t ON t.taxonomyid::text = c.role AND t.taxonomytype = 'role'
          WHERE c.contactId = ?",
        [$pedro]
    );
    check(
        'U2 · el "Cajero" del legacy cae en el rol Cajero de Punto (match por nombre)',
        (string) $rolPedro === 'Cajero',
        'rol = ' . var_export($rolPedro, true),
        $failures, $checks
    );

    $maria = EncomMigrationService::mapped($companyId, 'user', 'usr-1');
    $rolMaria = $maria === null ? null : scalar(
        "SELECT t.taxonomyname FROM contact c
           JOIN taxonomy t ON t.taxonomyid::text = c.role AND t.taxonomytype = 'role'
          WHERE c.contactId = ?",
        [$maria]
    );
    check(
        'U3 · el "Administrador" del legacy cae en Encargado, NUNCA en Dueño (nunca de más)',
        (string) $rolMaria === 'Encargado',
        'rol = ' . var_export($rolMaria, true),
        $failures, $checks
    );

    $pin = $maria === null ? null : scalar('SELECT lockPass FROM contact WHERE contactId = ?', [$maria]);
    $pinHash = $maria === null ? null : scalar('SELECT pinhash FROM contact WHERE contactId = ?', [$maria]);
    check(
        'U4 · el PIN de la caja se migra y queda hasheado para la pantalla de bloqueo',
        (string) $pin === '1234' && (string) $pinHash === hash('sha256', '1234'),
        'lockPass = ' . var_export($pin, true),
        $failures, $checks
    );

    $asignaciones = (int) scalar(
        'SELECT count(*) FROM contact_outlet WHERE companyid = ?',
        [$companyId]
    );
    check(
        'U5 · cada usuario va a SU sucursal; el que no tenía queda global (2 filas, no 3)',
        $asignaciones === 2,
        "contact_outlet = $asignaciones",
        $failures, $checks
    );

    check(
        'U6 · la bitácora dice qué rol se le asignó a cada usuario (para que soporte lo revise)',
        str_contains($logText, 'rol de Punto') && str_contains($logText, 'María Dueña'),
        "log = $logText",
        $failures, $checks
    );

    // ── U7. "Jefe" → Dueño (decisión del owner, 2026-09-18) ────────────
    // En el legacy "Jefe" es el rol MÁS ALTO y lo tiene el administrador
    // principal. No matcheaba ninguna palabra clave y caía al más bajo: el
    // dueño del comercio entraba como Cajero (job 71e8282d).
    seedCompany($companyH, 'Comercio Con Jefe SA');
    $runJefe = (new EncomImportService($companyH, new JefeEncomClient($fixtures), null))->run(['config', 'users']);

    $rolDe = static function (string $cid, string $legacy): ?string {
        $id = EncomMigrationService::mapped($cid, 'user', $legacy);
        return $id === null ? null : (string) scalar(
            "SELECT t.taxonomyname FROM contact c
               JOIN taxonomy t ON t.taxonomyid::text = c.role AND t.taxonomytype = 'role'
              WHERE c.contactId = ?",
            [$id]
        );
    };

    check(
        'U7 · el "Jefe" del legacy entra como Dueño; Admin. Base → Encargado; Cajero Base → Cajero',
        $rolDe($companyH, 'usr-j1') === 'Dueño'
            && $rolDe($companyH, 'usr-j3') === 'Encargado'
            && $rolDe($companyH, 'usr-j4') === 'Cajero',
        'roles = ' . json_encode([
            $rolDe($companyH, 'usr-j1'), $rolDe($companyH, 'usr-j3'), $rolDe($companyH, 'usr-j4'),
        ], JSON_UNESCAPED_UNICODE) . ' · errores = ' . json_encode($runJefe['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    $logJefe = json_encode($runJefe['log'], JSON_UNESCAPED_UNICODE);
    check(
        'U8 · la cuenta de soporte con rol "Jefe" entra por la misma regla, y la bitácora pide confirmarla',
        $rolDe($companyH, 'usr-j2') === 'Dueño'
            && str_contains($logJefe, 'Soporte Sistema Anterior')
            && str_contains($logJefe, 'TODOS los permisos'),
        "log = $logJefe",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // T. TAXONOMÍAS que ya existen por nombre (job 71e8282d)
    // ══════════════════════════════════════════════════════════════════
    // El comercio ya tenía "bebidas" y "COMBOS" cargadas en Punto; el legacy
    // trae "Bebidas" y "Combos". Antes: 23505 contra uq_category_company_name,
    // la categoría "fallida" y sus artículos sin categoría.
    seedCompany($companyI, 'Comercio Con Catalogo Previo SA');
    $catSvc = new \Punto\Api\Categories\CategoryService($db);
    $bebidasPrevia = $catSvc->create($companyI, ['name' => 'bebidas']);
    $combosPrevia  = $catSvc->create($companyI, ['name' => 'COMBOS']);
    $catsAntes     = countOf('category', $companyI);

    $runTax = (new EncomImportService($companyI, new FixtureEncomClient($fixtures), null))->run(['catalog']);
    $errTax = array_values(array_filter(
        $runTax['errors'],
        static fn(array $e) => in_array($e['domain'] ?? '', ['category', 'brand', 'tag', 'item'], true)
    ));

    check(
        'T1 · la categoría que ya existe (otro case) NO falla: se reusa y queda mapeada a la existente',
        $errTax === []
            && ($runTax['progress']['category']['failed'] ?? -1) === 0
            && EncomMigrationService::mapped($companyI, 'category', 'cat-100') === $bebidasPrevia
            && EncomMigrationService::mapped($companyI, 'category', 'cat-300') === $combosPrevia,
        'progress.category = ' . json_encode($runTax['progress']['category'] ?? null)
            . ' · errores = ' . json_encode($errTax, JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'T2 · no se duplica: solo se crean las 2 categorías que faltaban (4 del legacy − 2 reusadas)',
        countOf('category', $companyI) === $catsAntes + 2,
        'categorías antes/después = ' . $catsAntes . '/' . countOf('category', $companyI),
        $failures, $checks
    );

    $itmCafeI = EncomMigrationService::mapped($companyI, 'item', 'itm-1');
    check(
        'T3 · y el artículo queda colgado de la categoría REUSADA (antes entraba sin categoría)',
        $itmCafeI !== null
            && (int) scalar('SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?', [$itmCafeI, $bebidasPrevia]) === 1,
        'item_category del café = ' . json_encode(scalar('SELECT categoryId FROM item_category WHERE itemId = ? LIMIT 1', [$itmCafeI ?? ''])),
        $failures, $checks
    );

    check(
        'T4 · la bitácora dice cuáles se reusaron',
        str_contains(json_encode($runTax['log'], JSON_UNESCAPED_UNICODE), 'se reusaron'),
        'log = ' . json_encode($runTax['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // T5/T6. El estado en que quedaron los artículos del job 71e8282d: la
    // categoría falló, el artículo entró SIN categoría y es idempotente, así
    // que relanzar lo salteaba y quedaba así para siempre.
    $db->Execute('UPDATE item SET categoryId = NULL WHERE itemId = ?', [$itmCafeI]);
    $db->Execute('DELETE FROM item_category WHERE itemId = ?', [$itmCafeI]);
    // Y uno que el comercio recategorizó a mano después: NO se toca.
    $itmMedialunaI = EncomMigrationService::mapped($companyI, 'item', 'itm-2');
    $db->Execute('UPDATE item SET categoryId = ? WHERE itemId = ?', [$combosPrevia, $itmMedialunaI]);
    $db->Execute('DELETE FROM item_category WHERE itemId = ?', [$itmMedialunaI]);
    $db->Execute('INSERT INTO item_category (itemId, categoryId, isPrimary) VALUES (?, ?, TRUE)', [$itmMedialunaI, $combosPrevia]);

    $runTax2 = (new EncomImportService($companyI, new FixtureEncomClient($fixtures), null))->run(['catalog']);

    check(
        'T5 · relanzar le COMPLETA la categoría al artículo ya importado que había quedado sin ella',
        (string) scalar('SELECT categoryId FROM item WHERE itemId = ?', [$itmCafeI]) === $bebidasPrevia
            && (int) scalar('SELECT count(*) FROM item_category WHERE itemId = ? AND categoryId = ?', [$itmCafeI, $bebidasPrevia]) === 1,
        'categoryId = ' . var_export(scalar('SELECT categoryId FROM item WHERE itemId = ?', [$itmCafeI]), true)
            . ' · log = ' . json_encode($runTax2['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'T6 · y NO pisa la categoría que el comercio eligió a mano después de migrar',
        (string) scalar('SELECT categoryId FROM item WHERE itemId = ?', [$itmMedialunaI]) === $combosPrevia
            && (int) scalar('SELECT count(*) FROM item_category WHERE itemId = ?', [$itmMedialunaI]) === 1,
        'categoryId medialuna = ' . var_export(scalar('SELECT categoryId FROM item WHERE itemId = ?', [$itmMedialunaI]), true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // K. CLIENTES DUPLICADOS — política del owner (2026-09-18)
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyK, 'Comercio Con Duplicados SA');
    $runDup = (new EncomImportService($companyK, new DuplicadosEncomClient($fixtures), null))->run(['customers']);
    $idA = EncomMigrationService::mapped($companyK, 'customer', 'dup-a');

    check(
        'K1 · documento repetido: NO se crea otro contacto, el id del legacy se mapea al que ya lo tiene',
        $idA !== null
            && EncomMigrationService::mapped($companyK, 'customer', 'dup-b') === $idA
            && (int) scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 1', [$companyK]) === 3,
        'dup-a=' . var_export($idA, true) . ' dup-b=' . var_export(EncomMigrationService::mapped($companyK, 'customer', 'dup-b'), true)
            . ' clientes=' . scalar('SELECT count(*) FROM contact WHERE companyId = ? AND type = 1', [$companyK])
            . ' · errores = ' . json_encode($runDup['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'K2 · documento Y teléfono repetidos a la vez: manda el documento (se unifica)',
        EncomMigrationService::mapped($companyK, 'customer', 'dup-e') === $idA,
        'dup-e → ' . var_export(EncomMigrationService::mapped($companyK, 'customer', 'dup-e'), true),
        $failures, $checks
    );

    $idC = EncomMigrationService::mapped($companyK, 'customer', 'dup-c');
    $notaC = $idC === null ? '' : (string) scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$idC]);
    check(
        'K3 · teléfono de OTRA persona: entra sin teléfono, con el número en la nota y sin perder la nota que traía',
        $idC !== null && $idC !== $idA
            && (string) scalar('SELECT COALESCE(contactPhone, \'\') FROM contact WHERE contactId = ?', [$idC]) === ''
            && str_contains($notaC, '0981555111') && str_contains($notaC, 'Cliente de los martes'),
        'nota = ' . var_export($notaC, true),
        $failures, $checks
    );

    $idD = EncomMigrationService::mapped($companyK, 'customer', 'dup-d');
    check(
        'K4 · teléfono inválido: entra sin teléfono y con el número original en la nota',
        $idD !== null
            && (string) scalar('SELECT COALESCE(contactPhone, \'\') FROM contact WHERE contactId = ?', [$idD]) === ''
            && str_contains((string) scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$idD]), 'no es válido'),
        'nota = ' . var_export($idD === null ? null : scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$idD]), true),
        $failures, $checks
    );

    $errDup = json_encode($runDup['errors'], JSON_UNESCAPED_UNICODE);
    $logSinId = json_encode($runDup['log'], JSON_UNESCAPED_UNICODE);
    check(
        'K5 · el cliente sin id del legacy es ADVERTENCIA con nombre, no error: el job no queda failed por él',
        $runDup['errors'] === [] && str_contains($logSinId, 'Cliente Sin Id')
            && ($runDup['progress']['customer']['omitted'] ?? 0) === 1
            && ($runDup['progress']['customer']['failed'] ?? -1) === 0,
        "errors = $errDup · progress = " . json_encode($runDup['progress']['customer'] ?? null),
        $failures, $checks
    );

    $logDup = json_encode($runDup['log'], JSON_UNESCAPED_UNICODE);
    check(
        'K6 · la bitácora cuenta cada caso con ejemplos (unificados, teléfono repetido, teléfono inválido)',
        str_contains($logDup, 'UNIFICARON') && str_contains($logDup, 'ya lo tiene otro cliente')
            && str_contains($logDup, 'no es válido'),
        "log = $logDup",
        $failures, $checks
    );

    $contactosDup = (int) scalar('SELECT count(*) FROM contact WHERE companyId = ?', [$companyK]);
    (new EncomImportService($companyK, new DuplicadosEncomClient($fixtures), null))->run(['customers']);
    check(
        'K7 · re-correr no crea contactos ni reescribe la nota',
        (int) scalar('SELECT count(*) FROM contact WHERE companyId = ?', [$companyK]) === $contactosDup
            && (string) scalar("SELECT data->>'contactNote' FROM contact WHERE contactId = ?", [$idC ?? '']) === $notaC,
        'contactos antes/después = ' . $contactosDup . '/' . scalar('SELECT count(*) FROM contact WHERE companyId = ?', [$companyK]),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // CV. COMPLETAR CLIENTES YA IMPORTADOS (owner 2026-09-19)
    // ══════════════════════════════════════════════════════════════════
    // Estado de partida = lo que dejó el migrador viejo en Don Ramón: el
    // cliente existe y está mapeado, sin teléfono/email/nota, y con una fila
    // default de dirección con el texto VACÍO.
    require_once dirname(__DIR__) . '/lib/Contacts/ContactService.php';
    require_once dirname(__DIR__) . '/lib/Contacts/ContactRepository.php';
    seedCompany($companyR, 'Comercio A Completar SA');
    $svcR = new \Punto\Api\Contacts\ContactService(new \Punto\Api\Contacts\ContactRepository($db));
    $viejo = static function (string $legacy, array $in) use ($svcR, $companyR): string {
        $id = $svcR->create($companyR, $in + ['type' => 1, 'address' => '', 'city' => '', 'note' => '']);
        EncomMigrationService::remember($companyR, 'customer', $legacy, $id, null);
        return $id;
    };
    $cv1 = $viejo('cv-1', ['name' => 'Ana Vacia']);
    $cv2 = $viejo('cv-2', ['name' => 'Beto Editado', 'phone' => '0981200099', 'email' => 'beto@punto.com']);
    $cv3 = $viejo('cv-3', ['name' => 'Caro TelRep']);
    $cv4 = $viejo('cv-4', ['name' => 'Dani ConDir', 'address' => 'Direccion Punto 1']);
    $cv5 = $viejo('cv-5', ['name' => 'Eva DocRep']);
    $cv6 = $viejo('cv-6', ['name' => 'Fede TelMalo']);
    $dirVieja1 = (string) scalar('SELECT customerAddressId FROM customeraddress WHERE customerId = ?', [$cv1]);

    $runCv = (new EncomImportService($companyR, new CompletarEncomClient($fixtures), null))->run(['customers']);
    $col = static fn (string $expr, string $id): string
        => (string) scalar("SELECT COALESCE(($expr)::text, '') FROM contact WHERE contactId = ?", [$id]);
    $dir = static fn (string $id): array => [
        (int) scalar('SELECT count(*) FROM customeraddress WHERE customerId = ? AND status = 1', [$id]),
        (string) scalar("SELECT COALESCE(string_agg(customerAddressText, '|'), '') FROM customeraddress WHERE customerId = ? AND status = 1", [$id]),
    ];

    [$nDir1, $txtDir1] = $dir($cv1);
    check(
        'CV1 · cliente vacío: se completan teléfono, email, documento, nota, nacimiento y línea de crédito',
        $col('contactPhone', $cv1) === '595981200001'
            && $col('contactEmail', $cv1) === 'ana@legacy.com'
            && $col("data->>'contactCI'", $cv1) === '2000001'
            && $col("data->>'contactNote'", $cv1) === 'Nota del legacy'
            && str_starts_with($col("data->>'contactBirthDay'", $cv1), '1990-05-06')
            && (float) $col('contactCreditLine', $cv1) === 500000.0,
        'tel=' . $col('contactPhone', $cv1) . ' email=' . $col('contactEmail', $cv1) . ' ci=' . $col("data->>'contactCI'", $cv1)
            . ' nota=' . $col("data->>'contactNote'", $cv1) . ' bday=' . $col("data->>'contactBirthDay'", $cv1)
            . ' linea=' . $col('contactCreditLine', $cv1) . ' · errores=' . json_encode($runCv['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'CV2 · la dirección se completa en la MISMA fila default vacía (no se crea una segunda)',
        $nDir1 === 1 && $txtDir1 === 'Calle Uno 123'
            && (string) scalar('SELECT customerAddressId FROM customeraddress WHERE customerId = ? AND status = 1', [$cv1]) === $dirVieja1
            && (string) scalar('SELECT customerAddressCity FROM customeraddress WHERE customerId = ?', [$cv1]) === 'Asuncion',
        "direcciones=$nDir1 texto=$txtDir1",
        $failures, $checks
    );

    [$nDir2, $txtDir2] = $dir($cv2);
    check(
        'CV3 · lo editado en Punto NO se pisa (teléfono y email), lo vacío sí se completa (documento, dirección)',
        $col('contactPhone', $cv2) === '595981200099'
            && $col('contactEmail', $cv2) === 'beto@punto.com'
            && $col("data->>'contactCI'", $cv2) === '2000002'
            && $nDir2 === 1 && $txtDir2 === 'Calle Dos 456',
        'tel=' . $col('contactPhone', $cv2) . ' email=' . $col('contactEmail', $cv2) . " dir=$txtDir2",
        $failures, $checks
    );

    [$nDir4, $txtDir4] = $dir($cv4);
    check(
        'CV4 · el cliente que YA tiene dirección conserva la suya (ni texto ni ciudad del legacy)',
        $nDir4 === 1 && $txtDir4 === 'Direccion Punto 1'
            && (string) scalar("SELECT COALESCE(customerAddressCity, '') FROM customeraddress WHERE customerId = ?", [$cv4]) === '',
        "direcciones=$nDir4 texto=$txtDir4",
        $failures, $checks
    );

    $nota3 = $col("data->>'contactNote'", $cv3);
    check(
        'CV5 · teléfono que ya tiene otro cliente: no va al campo, va a la nota (junto con la nota del legacy)',
        $col('contactPhone', $cv3) === ''
            && substr_count($nota3, '0981200099') === 1
            && str_contains($nota3, 'Viene los lunes'),
        'nota=' . var_export($nota3, true),
        $failures, $checks
    );

    $nota6 = $col("data->>'contactNote'", $cv6);
    check(
        'CV6 · teléfono inválido: a la nota',
        $col('contactPhone', $cv6) === '' && str_contains($nota6, 'Teléfono del sistema anterior: 12'),
        'nota=' . var_export($nota6, true),
        $failures, $checks
    );

    $logCv = json_encode($runCv['log'], JSON_UNESCAPED_UNICODE);
    check(
        'CV7 · documento de OTRO cliente: no se completa (unicidad) y se cuenta; lo demás de ese cliente sí',
        $col("data->>'contactCI'", $cv5) === ''
            && $col('contactEmail', $cv5) === 'eva@legacy.com'
            && str_contains($logCv, 'no recibieron el documento') && str_contains($logCv, 'Eva DocRep'),
        'ci=' . $col("data->>'contactCI'", $cv5) . " log=$logCv",
        $failures, $checks
    );

    check(
        'CV8 · la bitácora dice cuántos se completaron (con qué) y cuántos datos se dejaron por tener otro valor',
        str_contains($logCv, 'cliente(s) ya importados se completaron') && str_contains($logCv, 'con dirección')
            && str_contains($logCv, 'con teléfono') && str_contains($logCv, 'con email')
            && str_contains($logCv, 'NO se tocaron') && str_contains($logCv, 'teléfono'),
        "log=$logCv",
        $failures, $checks
    );

    check(
        'CV9 · "Primer Cliente" sin id es advertencia: cero errores, el job no queda failed',
        $runCv['errors'] === [] && str_contains($logCv, 'Primer Cliente')
            && ($runCv['progress']['customer']['omitted'] ?? 0) === 1
            && ($runCv['progress']['customer']['skipped'] ?? 0) === 6
            && ($runCv['progress']['customer']['imported'] ?? -1) === 0,
        'errores=' . json_encode($runCv['errors'], JSON_UNESCAPED_UNICODE) . ' progress=' . json_encode($runCv['progress']['customer'] ?? null),
        $failures, $checks
    );

    // Idempotencia: `xmin` cambia con CUALQUIER UPDATE aunque escriba el mismo
    // valor (y `updated_at` no sirve: TODAY es constante en el proceso).
    $foto = static fn (): string => (string) scalar(
        "SELECT string_agg(x, ';' ORDER BY x) FROM (
            SELECT contactId::text || ':' || xmin::text AS x FROM contact WHERE companyId = ?
            UNION ALL
            SELECT customerAddressId::text || ':' || xmin::text FROM customeraddress WHERE companyId = ?
         ) t",
        [$companyR, $companyR]
    );
    $antes = $foto();
    $runCv2 = (new EncomImportService($companyR, new CompletarEncomClient($fixtures), null))->run(['customers']);
    $logCv2 = json_encode($runCv2['log'], JSON_UNESCAPED_UNICODE);
    check(
        'CV10 · segunda corrida: no escribe NADA (ni contactos ni direcciones) y no duplica la nota',
        $foto() === $antes
            && substr_count($col("data->>'contactNote'", $cv3), '0981200099') === 1
            && !str_contains($logCv2, 'se completaron')
            && $runCv2['errors'] === [],
        "log2=$logCv2",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // P. Medios de pago
    // ══════════════════════════════════════════════════════════════════
    $transferencia = (int) scalar(
        "SELECT count(*) FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod' AND taxonomyName = 'Transferencia'",
        [$companyId]
    );
    check(
        'P1 · el medio de pago que el comercio tenía y Punto no, se crea',
        $transferencia === 1,
        "Transferencia = $transferencia",
        $failures, $checks
    );

    $efectivo = (int) scalar(
        "SELECT count(*) FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod' AND taxonomyName ILIKE 'efectivo'",
        [$companyId]
    );
    check(
        'P2 · "Efectivo" NO se duplica: el que ya existe se reusa',
        $efectivo === 1,
        "Efectivo = $efectivo",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // I. APERTURA DE STOCK — cantidad y costo, por sucursal
    // ══════════════════════════════════════════════════════════════════
    // El migrador ya importaba `item.itemCost`, pero ESE CAMPO NO SE USA AL
    // VENDER: `SaleService::resolveUnitCOGS()` lee el costo promedio ponderado
    // que dejó el ÚLTIMO movimiento del ledger. Sin apertura, el COGS de toda
    // venta es null y los reportes de margen del comercio migrado nacen vacíos.

    $outlet1 = EncomMigrationService::mapped($companyId, 'outlet', 'out-1');
    $outlet2 = EncomMigrationService::mapped($companyId, 'outlet', 'out-2');
    $harinaId = EncomMigrationService::mapped($companyId, 'item', 'itm-6');
    $servicioId = EncomMigrationService::mapped($companyId, 'item', 'itm-3');

    $saldoDe = static function (?string $itemId, ?string $outletId): ?float {
        if ($itemId === null || $outletId === null) {
            return null;
        }
        return (float) scalar(
            'SELECT COALESCE(SUM(stockCount), 0) FROM stock WHERE itemId = ? AND outletId = ?',
            [$itemId, $outletId]
        );
    };

    // La expresión EXACTA de `SaleService::resolveUnitCOGS()` para un artículo
    // con stock propio. Se copia a propósito: lo que se está verificando es
    // qué COGS resolvería una venta posterior, y el método real es privado.
    $cogsDeLaVenta = static function (?string $itemId, ?string $outletId): ?float {
        if ($itemId === null || $outletId === null) {
            return null;
        }
        $stock = \Punto\App\Domain\Inventory::getItemStock($itemId, $outletId);
        if (!is_array($stock) && !($stock instanceof \ArrayAccess)) {
            return null;
        }
        $val = $stock['stockOnHandCOGS'] ?? null;
        return is_numeric($val) ? (float) $val : null;
    };

    check(
        'I1 · la apertura se contabiliza por (artículo, sucursal): 6 con saldo, 3 abiertos, 1 salteado, 2 sin costo',
        ($p1['stock']['total'] ?? 0) === 6
            && ($p1['stock']['imported'] ?? 0) === 3
            && ($p1['stock']['skipped'] ?? 0) === 1
            && ($p1['stock']['failed'] ?? 0) === 2,
        'progress.stock = ' . json_encode($p1['stock'] ?? null),
        $failures, $checks
    );

    check(
        'I2 · el saldo entra en la sucursal que le toca, con la cantidad del legacy (Café: 24 en Casa Central)',
        abs(($saldoDe($itm1, $outlet1) ?? -1) - 24.0) < 0.0001,
        'saldo = ' . var_export($saldoDe($itm1, $outlet1), true),
        $failures, $checks
    );

    check(
        'I3 · MULTI-SUCURSAL: la segunda sucursal recibe SU propio saldo, no el de la primera (Café: 7)',
        abs(($saldoDe($itm1, $outlet2) ?? -1) - 7.0) < 0.0001,
        'saldo en sucursal 2 = ' . var_export($saldoDe($itm1, $outlet2), true),
        $failures, $checks
    );

    // Lo que esta feature vino a arreglar: que la venta tenga de dónde sacar
    // el costo.
    check(
        'I4 · el COGS que resolvería una venta posterior sale de la apertura (5000), no de la nada',
        $cogsDeLaVenta($itm1, $outlet1) === 5000.0,
        'resolveUnitCOGS = ' . var_export($cogsDeLaVenta($itm1, $outlet1), true),
        $failures, $checks
    );

    // ── La regla dura: NUNCA un 0 que se lea como "cuesta cero" ──────────
    // La harina tiene saldo (120) pero no está en la tabla de costos del panel.
    // Abrirla con costo 0 haría que toda venta suya saliera con margen 100%,
    // para siempre y sin que nadie lo mire. Se prefiere NO abrirla.
    check(
        'I5 · el artículo SIN costo conocido NO recibe apertura (un 0 daría margen 100%)',
        abs(($saldoDe($harinaId, $outlet1) ?? -1) - 0.0) < 0.0001,
        'saldo de la harina = ' . var_export($saldoDe($harinaId, $outlet1), true),
        $failures, $checks
    );

    check(
        'I6 · y por eso su COGS queda en null (la venta OMITE la columna) en vez de 0.0 → NO hay margen 100%',
        $cogsDeLaVenta($harinaId, $outlet1) === null,
        'resolveUnitCOGS = ' . var_export($cogsDeLaVenta($harinaId, $outlet1), true),
        $failures, $checks
    );

    check(
        'I7 · el artículo sin costo queda NOMBRADO en la bitácora, con su cantidad, para que soporte lo complete',
        str_contains($logText, 'Sin costo, sin apertura') && str_contains($logText, 'Harina 000'),
        "log = $logText",
        $failures, $checks
    );

    // Un servicio no lleva stock propio: su costo sale de la receta
    // (`RecipeCosting`). El legacy manda 9 unidades igual — no se le cree.
    check(
        'I8 · el artículo que NO trackea inventario no recibe apertura aunque el legacy mande cantidad',
        abs(($saldoDe($servicioId, $outlet2) ?? -1) - 0.0) < 0.0001,
        'saldo del servicio = ' . var_export($saldoDe($servicioId, $outlet2), true),
        $failures, $checks
    );

    $filaCafe = ($itm1 === null || $outlet1 === null) ? null : $db->GetRow(
        'SELECT stocksource, userid, stockcogs, stockonhandcogs, locationid
           FROM stock WHERE itemId = ? AND outletId = ? LIMIT 1',
        [$itm1, $outlet1]
    );

    // El wrapper devuelve un `CaseInsensitiveArray` (ArrayAccess), NO un array
    // nativo: `is_array()` da false sobre una fila perfectamente válida. Es el
    // mismo idioma que usan `SaleService` y `manageStock()` para leer filas.
    $hayFila = is_array($filaCafe) || $filaCafe instanceof \ArrayAccess;

    check(
        'I9 · el movimiento entra por el camino del AJUSTE (source que los reportes ya traducen) y con su costo',
        $hayFila
            && (string) ($filaCafe['stocksource'] ?? '') === 'adjustment'
            && abs((float) ($filaCafe['stockcogs'] ?? 0) - 5000.0) < 0.0001,
        'fila = ' . json_encode($filaCafe),
        $failures, $checks
    );

    // El worker corre sin usuario de sesión. `stock.userId` es uuid: antes de
    // arreglar `manageStock()`, la cadena vacía reventaba el INSERT entero.
    check(
        'I10 · el movimiento del worker queda SIN autor (NULL), no con una cadena vacía que reviente el uuid',
        $hayFila && ($filaCafe['userid'] ?? null) === null,
        'userid = ' . var_export($filaCafe['userid'] ?? null, true),
        $failures, $checks
    );

    // D8 de context/52: el stock siempre está en un depósito.
    check(
        'I11 · la apertura queda imputada al depósito por defecto de la sucursal',
        $hayFila && ($filaCafe['locationid'] ?? null) !== null,
        'locationid = ' . var_export($filaCafe['locationid'] ?? null, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // F. La caja placeholder se reusa
    // ══════════════════════════════════════════════════════════════════
    $registersInDb = countOf('register', $companyId);
    check(
        'F1 · no quedan cajas fantasma: 3 cajas, no 5 (se reusa el placeholder de cada sucursal)',
        $registersInDb === 3,
        "register count = $registersInDb (esperado 3)",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // B. Idempotencia
    // ══════════════════════════════════════════════════════════════════
    $before = [
        'item'          => countOf('item', $companyId),
        // El ledger entra en la comparación porque acá duplicar es PLATA: una
        // segunda corrida que vuelva a abrir el inventario le regala stock al
        // comercio y nadie lo nota hasta el primer arqueo.
        'stock'         => countOf('stock', $companyId),
        'item_compound' => (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]),
        'category'      => countOf('category', $companyId),
        'brand'         => countOf('brand', $companyId),
        'contact'       => countOf('contact', $companyId),
        'outlet'        => countOf('outlet', $companyId),
        'register'      => countOf('register', $companyId),
        'tax'           => countOf('tax', $companyId),
    ];

    $run2 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'suppliers', 'config', 'users', 'payments', 'stock']);
    $p2 = $run2['progress'];

    check(
        'V4 · re-correr no duplica proveedores (3 salteados, ninguno nuevo)',
        ($p2['supplier']['imported'] ?? -1) === 0 && ($p2['supplier']['skipped'] ?? 0) === 3,
        'progress.supplier = ' . json_encode($p2['supplier'] ?? null),
        $failures, $checks
    );

    check(
        'B1 · la segunda corrida no importa artículos ni clientes nuevos',
        ($p2['item']['imported'] ?? -1) === 0 && ($p2['item']['skipped'] ?? 0) === 9
            && ($p2['customer']['imported'] ?? -1) === 0 && ($p2['customer']['skipped'] ?? 0) === 2,
        'progress = ' . json_encode([$p2['item'] ?? null, $p2['customer'] ?? null]),
        $failures, $checks
    );

    check(
        'B2 · la segunda corrida no importa cajas, usuarios ni medios de pago nuevos',
        ($p2['register']['imported'] ?? -1) === 0 && ($p2['register']['skipped'] ?? 0) === 3
            && ($p2['user']['imported'] ?? -1) === 0 && ($p2['user']['skipped'] ?? 0) === 3
            && ($p2['payment']['imported'] ?? -1) === 0 && ($p2['payment']['skipped'] ?? 0) === 3,
        'progress = ' . json_encode([$p2['register'] ?? null, $p2['user'] ?? null, $p2['payment'] ?? null]),
        $failures, $checks
    );

    // La receta es el caso donde re-correr SIN marca duplicaría cantidades:
    // `ItemCompoundService::add()` suma cuando el ingrediente ya está.
    check(
        'B3 · la composición NO se vuelve a aplicar (si no, cada corrida sumaría la cantidad otra vez)',
        ($p2['compound']['imported'] ?? -1) === 0 && ($p2['compound']['skipped'] ?? 0) === 2,
        'progress.compound = ' . json_encode($p2['compound'] ?? null),
        $failures, $checks
    );

    $after = [
        'item'          => countOf('item', $companyId),
        'stock'         => countOf('stock', $companyId),
        'item_compound' => (int) scalar('SELECT count(*) FROM item_compound WHERE companyId = ?', [$companyId]),
        'category'      => countOf('category', $companyId),
        'brand'         => countOf('brand', $companyId),
        'contact'       => countOf('contact', $companyId),
        'outlet'        => countOf('outlet', $companyId),
        'register'      => countOf('register', $companyId),
        'tax'           => countOf('tax', $companyId),
    ];

    check(
        'B4 · los conteos de la base NO se movieron tras re-correr',
        $before === $after,
        'antes=' . json_encode($before) . ' después=' . json_encode($after),
        $failures, $checks
    );

    // La apertura de stock es el otro caso donde re-correr cuesta plata: el
    // movimiento SUMA al saldo, así que sin la marca por (artículo, sucursal)
    // cada corrida le regalaría 24 unidades más de café al comercio.
    check(
        'B7 · la apertura NO se vuelve a aplicar: los 3 abiertos quedan en skipped, ninguno se importa de nuevo',
        ($p2['stock']['imported'] ?? -1) === 0
            && ($p2['stock']['skipped'] ?? 0) === 4
            && ($p2['stock']['total'] ?? 0) === 6,
        'progress.stock = ' . json_encode($p2['stock'] ?? null),
        $failures, $checks
    );

    check(
        'B8 · y el SALDO es el mismo tras dos corridas (24, no 48)',
        abs(($saldoDe($itm1, $outlet1) ?? -1) - 24.0) < 0.0001
            && abs(($saldoDe($itm1, $outlet2) ?? -1) - 7.0) < 0.0001,
        'saldos = ' . var_export([$saldoDe($itm1, $outlet1), $saldoDe($itm1, $outlet2)], true),
        $failures, $checks
    );

    if ($combo !== null && $medialuna !== null) {
        $qtyAgain = scalar(
            'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
            [$combo, $medialuna]
        );
        check(
            'B5 · la cantidad de la receta sigue siendo 2, no 4',
            $qtyAgain !== null && abs((float) $qtyAgain - 2.0) < 0.0001,
            'quantity = ' . var_export($qtyAgain, true),
            $failures, $checks
        );
    }

    if ($regId !== null) {
        $nextAgain = scalar(
            "SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register'
                AND scopeid = ? AND invoiceauth = ? AND prefix = ?",
            [$companyId, $regId, '16543210', '001-001']
        );
        check(
            'B6 · re-correr NO vuelve a mover la numeración fiscal (sigue en 2129)',
            (int) $nextAgain === 2129,
            'nextnumber = ' . var_export($nextAgain, true),
            $failures, $checks
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // M. La receta a medias se COMPLETA, no queda congelada
    // ══════════════════════════════════════════════════════════════════
    // "Combo Mixto" tiene dos componentes fijos: uno resuelve (itm-2) y el otro
    // no existe en el catálogo migrado (itm-777). Marcar al padre como
    // compuesto igual lo congelaría: la corrida siguiente lo saltearía por
    // idempotente y la receta quedaría incompleta PARA SIEMPRE, con
    // `explodeRecipe` descontando de menos en cada venta y en silencio.
    $mixto = EncomMigrationService::mapped($companyId, 'item', 'itm-9');

    check(
        'M1 · la receta con un componente sin resolver NO queda marcada como compuesta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9') === null,
        'quedó marcada: la próxima corrida la saltearía y nunca se completaría',
        $failures, $checks
    );

    check(
        'M2 · el componente que SÍ resolvió quedó marcado por su cuenta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9:itm-2') !== null,
        'sin marca por componente, reintentar volvería a SUMAR la cantidad',
        $failures, $checks
    );

    $filasMixto = $mixto === null ? -1 : (int) scalar(
        'SELECT count(*) FROM item_compound WHERE parentItemId = ?',
        [$mixto]
    );
    check(
        'M3 · por ahora la receta tiene UN solo componente',
        $filasMixto === 1,
        "item_compound del mixto = $filasMixto",
        $failures, $checks
    );

    // Soporte crea a mano el artículo que faltaba y queda mapeado. La corrida
    // siguiente tiene que TERMINAR la receta.
    $harina = EncomMigrationService::mapped($companyId, 'item', 'itm-6');
    if ($harina !== null) {
        EncomMigrationService::remember($companyId, 'item', 'itm-777', $harina, null);
    }

    $run3 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))->run(['catalog']);

    check(
        'M4 · la corrida siguiente COMPLETA la receta que había quedado a medias',
        ($run3['progress']['compound']['imported'] ?? 0) === 1,
        'progress.compound = ' . json_encode($run3['progress']['compound'] ?? null),
        $failures, $checks
    );

    check(
        'M5 · recién ahora queda marcada como compuesta',
        EncomMigrationService::mapped($companyId, 'compound', 'itm-9') !== null,
        'sigue sin marcar',
        $failures, $checks
    );

    $filasMixto2 = $mixto === null ? -1 : (int) scalar(
        'SELECT count(*) FROM item_compound WHERE parentItemId = ?',
        [$mixto]
    );
    check(
        'M6 · la receta quedó con sus DOS componentes',
        $filasMixto2 === 2,
        "item_compound del mixto = $filasMixto2",
        $failures, $checks
    );

    // Lo que protege la marca por componente: `ItemCompoundService::add()` SUMA
    // cuando el ingrediente ya está, así que completar la receta no puede
    // volver a contar el que ya se había escrito.
    $qtyMixto = ($mixto === null || $medialuna === null) ? null : scalar(
        'SELECT quantity FROM item_compound WHERE parentItemId = ? AND childItemId = ?',
        [$mixto, $medialuna]
    );
    check(
        'M7 · el componente que ya estaba sigue en 1, no en 2 (completar no duplica)',
        $qtyMixto !== null && abs((float) $qtyMixto - 1.0) < 0.0001,
        'quantity = ' . var_export($qtyMixto, true),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // E. Rechazo por punto de expedición duplicado (D5)
    // ══════════════════════════════════════════════════════════════════
    seedCompany($companyB, 'Comercio Con Choque SA');

    $runBad  = (new EncomImportService($companyB, new ClashEncomClient(), null))->run(['config']);
    $errText = json_encode($runBad['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'E1 · el dominio config falla si dos cajas comparten timbrado + punto de expedición',
        $runBad['errors'] !== [],
        'no se registró ningún error',
        $failures, $checks
    );

    check(
        'E2 · el error nombra el punto de expedición en conflicto',
        str_contains($errText, '001-001') && str_contains($errText, '16543210'),
        "errores: $errText",
        $failures, $checks
    );

    $mappedBad = (int) scalar(
        "SELECT count(*) FROM migration_map WHERE companyid = ? AND domain = 'register'",
        [$companyB]
    );
    check(
        'E3 · NINGUNA caja se importó (no se importa a medias)',
        $mappedBad === 0,
        "migration_map tiene $mappedBad cajas mapeadas, esperado 0",
        $failures, $checks
    );

    $seqBad = (int) scalar(
        "SELECT count(*) FROM document_sequence WHERE companyid = ? AND invoiceauth = '16543210'",
        [$companyB]
    );
    check(
        'E4 · no quedó ninguna serie fiscal a medio crear',
        $seqBad === 0,
        "document_sequence tiene $seqBad filas con ese timbrado, esperado 0",
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // H. HISTÓRICO (F2) — ventas con líneas, compras y movimientos de caja
    // ══════════════════════════════════════════════════════════════════
    // Es el dominio donde el import NO pasa por los servicios de negocio, así
    // que el arnés tiene que probar las dos mitades: que los hechos entren
    // BIEN, y —sobre todo— que NO pase nada de lo que tiene prohibido pasar
    // (numeración fiscal, stock, caja, facturación electrónica).
    //
    // El rango es agosto de 2026 a propósito: es un mes ANTERIOR al actual, o
    // sea que su partición no existe cuando el test arranca. Si el importador
    // no la creara, las filas caerían en la partición DEFAULT — que es
    // exactamente el modo de falla que la mig 221 viene a cerrar.

    $seqAntes    = (int) scalar('SELECT count(*) FROM document_sequence WHERE companyid = ?', [$companyId]);
    $stockAntes  = (int) scalar('SELECT count(*) FROM stock WHERE companyId = ?', [$companyId]);

    $histOpts = ['historyFrom' => '2026-08-01', 'historyTo' => '2026-08-31'];
    $runH = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['sales_history', 'purchases_history', 'expenses_history'], $histOpts);

    $ph = $runH['progress'];

    check(
        'H1 · ventas: 3 leídas, 2 asentadas y 1 rechazada por usuario sin migrar',
        ($ph['sales_history']['total'] ?? 0) === 3
            && ($ph['sales_history']['imported'] ?? 0) === 2
            && ($ph['sales_history']['failed'] ?? 0) === 1,
        'progress.sales_history = ' . json_encode($ph['sales_history'] ?? null),
        $failures, $checks
    );

    // Prohibido inventar una dimensión obligatoria: `transaction.userid` es NOT
    // NULL, y meterle cualquier usuario le atribuiría ventas a quien no las
    // hizo. La venta no entra y el log dice qué falta.
    check(
        'H2 · la venta del usuario sin migrar NO se asentó con otro usuario',
        (int) scalar(
            "SELECT count(*) FROM transaction WHERE companyId = ? AND meta->>'legacyId' = 'tx-3'",
            [$companyId]
        ) === 0,
        'tx-3 no debía entrar',
        $failures, $checks
    );

    $tx1 = $db->Execute(
        "SELECT transactionid, transactiontype, invoiceno, invoiceprefix, invoiceauth, voidedat,
                transactiontotal, tableoid::regclass::text AS particion
           FROM transaction WHERE companyId = ? AND meta->>'legacyId' = 'tx-1' LIMIT 1",
        [$companyId]
    );
    $f1 = ($tx1 !== false && !$tx1->EOF) ? $tx1->fields : [];

    check(
        'H3 · el número fiscal entra CONGELADO del legacy (001-001-0001234, timbrado 16543210)',
        (int) ($f1['invoiceno'] ?? 0) === 1234
            && (string) ($f1['invoiceprefix'] ?? '') === '001-001'
            && (string) ($f1['invoiceauth'] ?? '') === '16543210',
        'tx-1 = ' . json_encode($f1),
        $failures, $checks
    );

    // ── Lo que el histórico tiene PROHIBIDO tocar ──────────────────────
    check(
        'H4 · NO se creó ninguna serie fiscal nueva (document_sequence intacta)',
        (int) scalar('SELECT count(*) FROM document_sequence WHERE companyid = ?', [$companyId]) === $seqAntes,
        'document_sequence cambió: el histórico estaría numerando con la serie del comercio',
        $failures, $checks
    );

    check(
        'H5 · NO se movió el stock (la mercadería de esas ventas ya salió hace meses)',
        (int) scalar('SELECT count(*) FROM stock WHERE companyId = ?', [$companyId]) === $stockAntes,
        'aparecieron movimientos de stock nuevos',
        $failures, $checks
    );

    check(
        'H6 · NO se encoló ningún documento electrónico',
        (int) scalar('SELECT count(*) FROM einvoice_document WHERE companyid = ?', [$companyId]) === 0,
        'el histórico estaría mandando documentos ya emitidos a SIFEN',
        $failures, $checks
    );

    // Las VENTAS no mueven caja. Las 2 filas de `expenses` son las del dominio
    // de movimientos de caja, que es otra cosa.
    check(
        'H7 · la caja solo tiene los 2 movimientos del dominio de gastos, ninguno de las ventas',
        (int) scalar('SELECT count(*) FROM expenses WHERE companyId = ?', [$companyId]) === 2,
        'expenses = ' . scalar('SELECT count(*) FROM expenses WHERE companyId = ?', [$companyId]),
        $failures, $checks
    );

    // ── Particionado: la prueba de que la mig 221 hace falta ───────────
    check(
        'H8 · la venta de agosto quedó en SU partición mensual, no en la DEFAULT',
        (string) ($f1['particion'] ?? '') === 'transaction_y2026m08',
        'partición = ' . var_export($f1['particion'] ?? null, true)
            . ' (si dice transaction_default, el histórico no se reclasifica NUNCA)'
            . ' — bitácora del job: ' . json_encode($runH['log'] ?? [], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Anulada: `voidedAt`, no tipo 7 ────────────────────────────────
    $tx2 = $db->Execute(
        "SELECT transactiontype, voidedat FROM transaction
          WHERE companyId = ? AND meta->>'legacyId' = 'tx-2' LIMIT 1",
        [$companyId]
    );
    $f2 = ($tx2 !== false && !$tx2->EOF) ? $tx2->fields : [];

    check(
        'H9 · la venta anulada entra marcada con voidedAt (que es lo que los rollups excluyen)',
        !empty($f2['voidedat']) && (int) ($f2['transactiontype'] ?? -1) === 0,
        'tx-2 = ' . json_encode($f2),
        $failures, $checks
    );

    // ── Líneas ────────────────────────────────────────────────────────
    $lineas = (int) scalar(
        "SELECT count(*) FROM itemSold WHERE transactionId = ?",
        [(string) ($f1['transactionid'] ?? '')]
    );
    check(
        'H10 · la venta entró con sus 2 líneas',
        $lineas === 2,
        "itemSold de tx-1 = $lineas",
        $failures, $checks
    );

    // El artículo que no existe en el catálogo migrado NO se descarta (el total
    // de la venta dejaría de cerrar contra la suma de sus ítems): entra como
    // artículo HISTÓRICO archivado y fuera del POS.
    $hist = $db->Execute(
        "SELECT itemName, itemStatus, itemCanSale FROM item
          WHERE companyId = ? AND itemName LIKE '[Histórico]%' LIMIT 1",
        [$companyId]
    );
    $fh = ($hist !== false && !$hist->EOF) ? $hist->fields : [];

    check(
        'H11 · el artículo sin match entra como histórico ARCHIVADO y no vendible',
        !empty($fh['itemname'])
            && (int) ($fh['itemstatus'] ?? 1) === 0
            && in_array((string) ($fh['itemcansale'] ?? ''), ['0', 'f', 'false', ''], true),
        'artículo histórico = ' . json_encode($fh),
        $failures, $checks
    );

    // ── COGS por línea ────────────────────────────────────────────────
    // El legacy no expone el costo de cada venta, así que se congela el costo
    // ACTUAL del artículo (`item.itemCost`). Sin esta columna el margen
    // histórico directamente NO EXISTE: los reportes leen el costo congelado
    // por línea, no lo recalculan.
    //
    // El contrato es el de `SaleService`: la columna guarda el costo UNITARIO.
    // El log del legacy trae el costo de la LÍNEA (verificado con dos filas
    // reales: Total − Costo = Utilidad), así que hay que DIVIDIR por la
    // cantidad: 10.000 de costo en 2 unidades ⇒ 5.000. Si acá apareciera
    // 10.000, el margen histórico saldría hundido por un factor igual a la
    // cantidad, en silencio y para siempre.
    $cogsConCosto = scalar(
        "SELECT itemSoldCOGS FROM itemSold
          WHERE transactionId = ? AND itemSoldDescription = 'CAFE ESPRESSO GRANDE' LIMIT 1",
        [(string) ($f1['transactionid'] ?? '')]
    );
    check(
        'H12 · el costo de LÍNEA del log se guarda como COGS UNITARIO (10.000 / 2 = 5.000)',
        $cogsConCosto !== null && $cogsConCosto !== false && abs((float) $cogsConCosto - 5000.0) < 0.01,
        'itemSoldCOGS = ' . var_export($cogsConCosto, true)
            . ' — esperado 5000; 10000 significaría que se escribió el costo de la línea sin dividir',
        $failures, $checks
    );

    // Y el que no se sabe queda NULL, nunca 0: `flipOnReturn(null)` devuelve 0
    // y un 0 se lee como "costó nada" → margen 100%. Por eso la columna se
    // OMITE del insert en vez de escribirse en null.
    $rsSinCosto = $db->Execute(
        "SELECT itemSoldCOGS, (itemSoldCOGS IS NULL) AS es_null FROM itemSold
          WHERE transactionId = ? AND itemSoldDescription = 'Producto Que Ya No Existe' LIMIT 1",
        [(string) ($f1['transactionid'] ?? '')]
    );
    $fSinCosto = ($rsSinCosto !== false && !$rsSinCosto->EOF) ? $rsSinCosto->fields : [];

    check(
        'H12b · la línea de un artículo SIN costo deja el COGS en NULL, nunca en 0',
        in_array((string) ($fSinCosto['es_null'] ?? $fSinCosto['ES_NULL'] ?? ''), ['1', 't', 'true'], true),
        'itemSoldCOGS = ' . var_export($fSinCosto['itemsoldcogs'] ?? $fSinCosto['itemSoldCOGS'] ?? null, true)
            . ' — un 0 acá daría margen 100% en todos los reportes de ese artículo',
        $failures, $checks
    );

    // ── Compras y movimientos de caja ─────────────────────────────────
    check(
        'H13 · la compra entró como tipo 1 (contado) con el documento del proveedor',
        ($ph['purchases_history']['imported'] ?? 0) === 1
            && (int) scalar(
                "SELECT count(*) FROM transaction
                  WHERE companyId = ? AND transactionType = 1 AND supplierDocNo = 55",
                [$companyId]
            ) === 1,
        'progress.purchases_history = ' . json_encode($ph['purchases_history'] ?? null),
        $failures, $checks
    );

    $compraId = EncomMigrationService::mapped($companyId, 'purchase_history', 'pur-1');
    check(
        'H13b · la compra entra CON sus 2 líneas (las filas del detalle no traen data-id: se leen por su data-load)',
        $compraId !== null
            && (int) scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraId]) === 2
            && ($ph['purchases_history']['lines'] ?? 0) === 2,
        'líneas = ' . var_export($compraId === null ? null : scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraId]), true)
            . ' · progress = ' . json_encode($ph['purchases_history'] ?? null)
            . ' · log = ' . json_encode($runH['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'V5 · y queda colgada del PROVEEDOR migrado, no del cliente homónimo "Carlos Ruiz"',
        $compraId !== null
            && (string) scalar('SELECT supplierId FROM transaction WHERE transactionId = ?', [$compraId])
                === (string) EncomMigrationService::mapped($companyId, 'supplier', 'sup-1'),
        'supplierId = ' . var_export($compraId === null ? null : scalar('SELECT supplierId FROM transaction WHERE transactionId = ?', [$compraId]), true),
        $failures, $checks
    );

    check(
        'H14 · los 2 movimientos de caja entran con su signo (type 1 = ingreso, NULL = extracción)',
        ($ph['expenses_history']['imported'] ?? 0) === 2
            && (int) scalar('SELECT count(*) FROM expenses WHERE companyId = ? AND type = 1', [$companyId]) === 1
            && (int) scalar('SELECT count(*) FROM expenses WHERE companyId = ? AND type IS NULL', [$companyId]) === 1,
        'progress.expenses_history = ' . json_encode($ph['expenses_history'] ?? null),
        $failures, $checks
    );

    // ── REANUDACIÓN: dos corridas = mismos totales, cero duplicados ────
    // Es el caso que protege al job que se corta a la mitad. Sin la marca en
    // `migration_map` —y sin que esa marca vaya en la MISMA transacción que el
    // asiento— la segunda corrida asentaría todo de nuevo y el comercio vería
    // el doble de facturación.
    $txAntes  = (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyId]);
    $linAntes = (int) scalar('SELECT count(*) FROM itemSold WHERE companyId = ?', [$companyId]);
    $expAntes = (int) scalar('SELECT count(*) FROM expenses WHERE companyId = ?', [$companyId]);

    $runH2 = (new EncomImportService($companyId, new FixtureEncomClient($fixtures), null))
        ->run(['sales_history', 'purchases_history', 'expenses_history'], $histOpts);

    $ph2 = $runH2['progress'];

    check(
        'H15 · la segunda corrida NO duplica ningún asiento',
        (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyId]) === $txAntes
            && (int) scalar('SELECT count(*) FROM itemSold WHERE companyId = ?', [$companyId]) === $linAntes
            && (int) scalar('SELECT count(*) FROM expenses WHERE companyId = ?', [$companyId]) === $expAntes,
        'transacciones antes/después = ' . $txAntes . '/'
            . scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyId]),
        $failures, $checks
    );

    check(
        'H16 · y lo ya asentado se reporta como salteado, no como importado',
        ($ph2['sales_history']['skipped'] ?? 0) === 2
            && ($ph2['purchases_history']['skipped'] ?? 0) === 1
            && ($ph2['expenses_history']['skipped'] ?? 0) === 2,
        'progress 2ª corrida = ' . json_encode($ph2),
        $failures, $checks
    );

    // ── PERÍODO CERRADO ───────────────────────────────────────────────
    // El guard de la base (mig 157) es BEFORE UPDATE OR DELETE: un INSERT con
    // fecha en un período cerrado entra sin que nada lo frene. O sea que si el
    // importador no chequea, una migración reescribe un mes ya conciliado.
    seedCompany($companyD, 'Comercio Con Período Cerrado SA');

    // La sucursal existe para que este caso AÍSLE su condición: sin ninguna, lo
    // que corta el dominio es el prerequisito (caso Q) y H17 pasaría por el
    // motivo equivocado — verde diciendo "no se asentó nada", pero por falta de
    // sucursales y no por el período cerrado. Un comercio real con un período
    // cerrado tiene sucursales.
    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (gen_random_uuid(), ?, 1, ?)',
        ['Casa Central', $companyD]
    );

    $db->Execute("SELECT period_close_run(?::uuid, '2026-08-01'::date, NULL, 'manual')", [$companyD]);

    $runCerrado = (new EncomImportService($companyD, new FixtureEncomClient($fixtures), null))
        ->run(['sales_history'], $histOpts);

    check(
        'H17 · un período CERRADO no se toca: no se asienta nada de ese mes',
        ($runCerrado['progress']['sales_history']['total'] ?? -1) === 0
            && (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyD]) === 0,
        'progress = ' . json_encode($runCerrado['progress'] ?? null),
        $failures, $checks
    );

    check(
        'H18 · y el job dice POR QUÉ no entró (no queda mudo)',
        str_contains(json_encode($runCerrado['errors'], JSON_UNESCAPED_UNICODE), 'CERRADO'),
        'errors = ' . json_encode($runCerrado['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // P/S/T/R. EL EXPORT — tope de filas, sonda del detalle, y latido
    // ══════════════════════════════════════════════════════════════════
    // Todo este bloque sale de la primera corrida real (2026-09-11), donde el
    // job dijo "300 ventas importadas, 0 errores" y en realidad había perdido
    // el 96% del histórico y no había entrado una sola línea.
    seedCompany($companyG, 'Comercio Con Mucho Historico SA');
    (new EncomImportService($companyG, new FixtureEncomClient($fixtures), null))
        ->run(['config', 'users'], []);

    // ── P. El tope de 100 filas por request ───────────────────────────
    $topeCli = new ListadoLargoEncomClient($fixtures, 250, false);
    $runTope = (new EncomImportService($companyG, $topeCli, null))->run(['sales_history'], $histOpts);
    $errTope = json_encode($runTope['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'P1 · un mes capado en el tope ABORTA el dominio en vez de asentar 100 de 250',
        count($runTope['errors']) === 1
            && str_contains($errTope, 'TRUNCADO')
            && ($runTope['progress']['sales_history']['imported'] ?? -1) === 0,
        'errors = ' . $errTope,
        $failures, $checks
    );

    // Sin esto el corte sería carísimo: probar convenciones inventadas contra
    // el servidor del cliente, una request por cada una, por mes.
    check(
        'P2 · y antes de abortar solo gasta 4 pedidos (el listado + nolimit + una sonda por convención)',
        count($topeCli->pedidos) === 4,
        'pedidos = ' . count($topeCli->pedidos) . ' → ' . json_encode($topeCli->pedidos),
        $failures, $checks
    );

    // ── P3/P4. Con paginación soportada se lee TODO ───────────────────
    $pagCli = new ListadoLargoEncomClient($fixtures, 250, true, 100);
    $runPag = (new EncomImportService($companyG, $pagCli, null))->run(['sales_history'], $histOpts);

    check(
        'P3 · con part/offset/limit se leen las 250 ventas, no las 100 del tope',
        ($runPag['progress']['sales_history']['total'] ?? 0) === 250,
        'progress = ' . json_encode($runPag['progress']['sales_history'] ?? null)
            . ' · errores = ' . json_encode($runPag['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // El fixture respeta `limit` pero con un techo propio de 100: si el offset
    // avanzara de a 1000 (lo PEDIDO) en vez de por filas leídas, se saltearía
    // todo lo del medio y este caso daría 100.
    check(
        'P4 · el offset avanza por filas LEÍDAS: 7 pedidos (listado + nolimit + sonda + 3 páginas + la vacía)',
        count($pagCli->pedidos) === 7,
        'pedidos = ' . count($pagCli->pedidos) . ' → ' . json_encode($pagCli->pedidos),
        $failures, $checks
    );

    // ── S. El log de ítems: lo que no se puede pegar, se informa ──────
    // Una línea cuya venta no está importada NO se puede asentar
    // (`itemsold.transactionid` es NOT NULL con FK) y las dos salidas fáciles
    // están mal: inventarle una transacción falsea la facturación del período,
    // y descartarla en silencio es el bug que este trabajo vino a cerrar.
    check(
        'S1 · la línea cuyo documento no corresponde a ninguna venta importada NO se asienta',
        (int) scalar(
            "SELECT count(*) FROM itemSold WHERE companyId = ? AND itemSoldDescription = 'CAFE ESPRESSO GRANDE'",
            [$companyId]
        ) === 1,
        'el log trae ese artículo DOS veces: una en la venta tx-1 y otra en un documento que no se importó',
        $failures, $checks
    );

    check(
        'S2 · y el job dice cuáles quedaron afuera, con su documento',
        str_contains(json_encode($runH['log'], JSON_UNESCAPED_UNICODE), 'pegar')
            && str_contains(json_encode($runH['log'], JSON_UNESCAPED_UNICODE), '001-001-0009999'),
        'log = ' . json_encode($runH['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // Las unidades de la venta se completan cuando entra el log: al asentar la
    // cabecera todavía no se habían leído sus líneas.
    check(
        'S3 · la venta queda con sus unidades vendidas (2 + 1), que llegan con el segundo log',
        abs((float) scalar(
            "SELECT COALESCE(transactionUnitsSold, 0) FROM transaction WHERE transactionId = ?",
            [(string) ($f1['transactionid'] ?? '')]
        ) - 3.0) < 0.001,
        'transactionUnitsSold = ' . var_export(scalar(
            "SELECT transactionUnitsSold FROM transaction WHERE transactionId = ?",
            [(string) ($f1['transactionid'] ?? '')]
        ), true),
        $failures, $checks
    );

    // ── W. El log de ítems con el data-id de la VENTA (job 71e8282d) ──
    // 40 ventas × 3 líneas = 120 filas: más que el tope, así que hay que
    // paginar. Con páginas de 50, la venta 16 queda partida entre la 1 y la 2.
    $opsW = ['historyFrom' => '2026-08-01', 'historyTo' => '2026-08-31'];
    $linW = static fn() => (int) scalar(
        "SELECT count(*) FROM itemSold i JOIN migration_map m ON m.puntoid::uuid = i.transactionid
          WHERE m.companyid = ? AND m.domain = 'sale_history' AND m.legacyid LIKE 'lp-%'",
        [$companyG]
    );

    $cliW   = new LogPartidoEncomClient($fixtures, 40, false, 50);
    $runW   = (new EncomImportService($companyG, $cliW, null))->run(['sales_history'], $opsW);
    $errW   = json_encode($runW['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'W1 · una venta de varias líneas partida entre dos páginas NO se lee como "el listado no avanza"',
        !str_contains($errW, 'dejó de avanzar') && ($runW['progress']['sales_history']['imported'] ?? 0) === 40,
        'progress = ' . json_encode($runW['progress']['sales_history'] ?? null) . " · errors = $errW",
        $failures, $checks
    );

    check(
        'W2 · entran las 120 líneas: TODAS las de cada venta, no solo la primera (la idempotencia es por línea)',
        $linW() === 120,
        'líneas = ' . $linW() . " · errors = $errW",
        $failures, $checks
    );

    $idVenta0 = EncomMigrationService::mapped($companyG, 'sale_history', 'lp-0');
    check(
        'W3 · dos líneas IDÉNTICAS de la misma venta son dos líneas, no una repetida',
        $idVenta0 !== null && (int) scalar(
            "SELECT count(*) FROM itemSold WHERE transactionId = ? AND itemSoldDescription = 'Cafe Doble'",
            [$idVenta0]
        ) === 2,
        'cafés de lp-0 = ' . var_export($idVenta0 === null ? null : scalar(
            "SELECT count(*) FROM itemSold WHERE transactionId = ? AND itemSoldDescription = 'Cafe Doble'", [$idVenta0]
        ), true),
        $failures, $checks
    );

    (new EncomImportService($companyG, new LogPartidoEncomClient($fixtures, 40, true), null))->run(['sales_history'], $opsW);
    check(
        'W4 · re-correr (ahora por la vía sin tope) no agrega una sola línea',
        $linW() === 120,
        'líneas tras re-correr = ' . $linW(),
        $failures, $checks
    );

    $cliNL = new LogPartidoEncomClient($fixtures, 40, true);
    (new EncomImportService($companyG, $cliNL, null))->run(['sales_history'], $opsW);
    check(
        'W5 · con nolimit el log entero sale en 2 pedidos (el listado con tope + la ventana sin OFFSET)',
        count($cliNL->pedidosLog) === 2 && !empty($cliNL->pedidosLog[1]['nolimit']),
        'pedidos al log = ' . json_encode($cliNL->pedidosLog),
        $failures, $checks
    );

    // ── W6/W7. Un log truncado corta el mes SIN escribir cabeceras ────
    // Job 71e8282d: el log de ítems abortaba DESPUÉS de asentar las 753
    // cabeceras de enero, y el mensaje decía "no se importa nada". Ahora los
    // dos logs se leen antes de escribir.
    $runT = (new EncomImportService($companyG, new LogPartidoEncomClient($fixtures, 40, false, 50, true, 'lt-'), null))
        ->run(['sales_history'], $opsW);
    $errT = json_encode($runT['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'W6 · si el log de ítems del mes viene truncado, NO se asienta ni una cabecera de ese mes',
        (int) scalar(
            "SELECT count(*) FROM migration_map WHERE companyid = ? AND domain = 'sale_history' AND legacyid LIKE 'lt-%'",
            [$companyG]
        ) === 0
            && ($runT['progress']['sales_history']['imported'] ?? -1) === 0,
        'cabeceras lt- = ' . scalar(
            "SELECT count(*) FROM migration_map WHERE companyid = ? AND domain = 'sale_history' AND legacyid LIKE 'lt-%'",
            [$companyG]
        ) . " · errors = $errT",
        $failures, $checks
    );

    check(
        'W7 · y el job lo dice (TRUNCADO), sin afirmar nada falso sobre lo ya asentado',
        str_contains($errT, 'TRUNCADO') && !str_contains($errT, 'no se importa nada'),
        "errors = $errT",
        $failures, $checks
    );

    // ── J. Relanzar COMPLETA las compras que entraron incompletas ──────
    // El estado en que quedaron las 246 compras del job 71e8282d: asentadas
    // sin una línea (el parser descartaba el detalle) y colgadas de un
    // contacto que no es proveedor (la búsqueda no filtraba por tipo, y el
    // alias `supplier_name` quedó apuntando a un CLIENTE).
    seedCompany($companyJ, 'Comercio A Completar SA');
    (new EncomImportService($companyJ, new FixtureEncomClient($fixtures), null))
        ->run(['catalog', 'customers', 'config', 'users']);
    $clienteRuizJ = EncomMigrationService::mapped($companyJ, 'customer', 'cus-2');

    (new EncomImportService($companyJ, new SinDetalleComprasEncomClient($fixtures), null))
        ->run(['purchases_history'], $histOpts);
    $compraJ = EncomMigrationService::mapped($companyJ, 'purchase_history', 'pur-1');

    // Lo que dejó el código VIEJO y el nuevo ya no produce: la compra colgada
    // del cliente homónimo y el alias por nombre apuntando a ese cliente.
    $db->Execute('UPDATE transaction SET supplierId = ? WHERE transactionId = ?', [$clienteRuizJ, $compraJ]);
    EncomMigrationService::remember($companyJ, 'supplier_name', 'carlos ruiz', (string) $clienteRuizJ, null);

    check(
        'J0 · (preparación) la compra quedó como en el incidente: sin líneas y colgada del CLIENTE',
        $compraJ !== null
            && (int) scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraJ]) === 0
            && (string) scalar('SELECT supplierId FROM transaction WHERE transactionId = ?', [$compraJ]) === (string) $clienteRuizJ,
        'compra = ' . var_export($compraJ, true),
        $failures, $checks
    );

    $runJ = (new EncomImportService($companyJ, new FixtureEncomClient($fixtures), null))
        ->run(['suppliers', 'purchases_history'], $histOpts);

    check(
        'J1 · relanzar le agrega sus 2 líneas a la compra ya importada (sin crear otra compra)',
        (int) scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraJ ?? '']) === 2
            && (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ? AND transactionType IN (1, 4)', [$companyJ]) === 1,
        'líneas = ' . scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraJ ?? ''])
            . ' · errores = ' . json_encode($runJ['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'J2 · y la cuelga del PROVEEDOR: el alias viejo que apuntaba al cliente se descarta',
        (string) scalar('SELECT supplierId FROM transaction WHERE transactionId = ?', [$compraJ ?? ''])
            === (string) EncomMigrationService::mapped($companyJ, 'supplier', 'sup-1')
            && EncomMigrationService::mapped($companyJ, 'supplier_name', 'carlos ruiz') !== $clienteRuizJ,
        'supplierId = ' . var_export(scalar('SELECT supplierId FROM transaction WHERE transactionId = ?', [$compraJ ?? '']), true),
        $failures, $checks
    );

    check(
        'J3 · la bitácora dice que completó compras ya importadas',
        str_contains(json_encode($runJ['log'], JSON_UNESCAPED_UNICODE), 'COMPLETARON'),
        'log = ' . json_encode($runJ['log'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    (new EncomImportService($companyJ, new FixtureEncomClient($fixtures), null))
        ->run(['suppliers', 'purchases_history'], $histOpts);
    check(
        'J4 · una tercera corrida no agrega nada (las líneas de compra también son idempotentes)',
        (int) scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraJ ?? '']) === 2,
        'líneas = ' . scalar('SELECT count(*) FROM itemSold WHERE transactionId = ?', [$compraJ ?? '']),
        $failures, $checks
    );

    // ── VC. El cliente de las ventas históricas (2026-09-18) ──────────
    // El bug: la celda Cliente se leía por su `data-filter` crudo ("X  con:
    // cliente" / "sin:cliente") y casi ninguna venta quedó vinculada —5 de
    // ~5.000 en el tenant 019ff24f—. Y como la venta es idempotente, relanzar
    // no la arreglaba.
    check(
        'VC0 · la celda se limpia: "sin:cliente" es vacío y "X  con:cliente" es X',
        EncomParse::customerCell('sin:cliente') === ''
            && EncomParse::customerCell('ARGUELLO MARTINEZ FEDERICO EDMUNDO  con:cliente') === 'ARGUELLO MARTINEZ FEDERICO EDMUNDO'
            && EncomParse::customerCell('Carlos Ruiz') === 'Carlos Ruiz'
            && EncomCustomerMatcher::key('ACU?A  Baez, Lilian') === EncomCustomerMatcher::key('ACUÑA BAEZ LILIAN')
            && EncomCustomerMatcher::key('AG¿ERO') === EncomCustomerMatcher::key('AGÜERO'),
        'customerCell/key no normalizan como se espera',
        $failures, $checks
    );

    seedCompany($companyQ, 'Comercio Clientes De Ventas SA');
    $cliQ = new ClientesDeVentasEncomClient($fixtures);
    (new EncomImportService($companyQ, $cliQ, null))->run(['customers', 'config', 'users']);

    $contactoQ = static fn (string $legacy): ?string => EncomMigrationService::mapped($companyQ, 'customer', $legacy);
    $clienteDeVentaQ = static function (string $legacyId) use ($companyQ): string {
        return (string) scalar(
            "SELECT COALESCE(customerId::text, '') FROM transaction WHERE companyId = ? AND meta->>'legacyId' = ?",
            [$companyQ, $legacyId]
        );
    };

    $runQ = (new EncomImportService($companyQ, $cliQ, null))->run(['sales_history'], $histOpts);
    $logQ = json_encode($runQ['log'], JSON_UNESCAPED_UNICODE);

    check(
        'VC1 · se vinculan por nombre limpio (con la Ñ guardada como "?") y por nombre + segundo nombre',
        $clienteDeVentaQ('vc-1') === (string) $contactoQ('vc-c1')
            && $clienteDeVentaQ('vc-2') === (string) $contactoQ('vc-c2')
            && $clienteDeVentaQ('vc-9') === (string) $contactoQ('vc-c6')
            && $contactoQ('vc-c1') !== null,
        'vc-1=' . $clienteDeVentaQ('vc-1') . ' vc-2=' . $clienteDeVentaQ('vc-2') . ' vc-9=' . $clienteDeVentaQ('vc-9')
            . ' · log = ' . $logQ . ' · errors = ' . json_encode($runQ['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    check(
        'VC2 · homónimos: sin RUC NO se adivina; con el RUC de la celda se vincula al correcto',
        $clienteDeVentaQ('vc-3') === '' && $clienteDeVentaQ('vc-4') === (string) $contactoQ('vc-c3'),
        'vc-3=' . $clienteDeVentaQ('vc-3') . ' vc-4=' . $clienteDeVentaQ('vc-4'),
        $failures, $checks
    );

    check(
        'VC3 · sin cliente real (sin:cliente, "Sin Nombre", consumidor final) y no encontrado quedan SIN cliente y sin error',
        $clienteDeVentaQ('vc-5') === '' && $clienteDeVentaQ('vc-6') === ''
            && $clienteDeVentaQ('vc-7') === '' && $clienteDeVentaQ('vc-8') === ''
            && ($runQ['progress']['sales_history']['failed'] ?? -1) === 0
            && ($runQ['progress']['sales_history']['imported'] ?? -1) === 9,
        'progress = ' . json_encode($runQ['progress']['sales_history'] ?? null)
            . ' · vc-8=' . $clienteDeVentaQ('vc-8'),
        $failures, $checks
    );

    check(
        'VC4 · la bitácora separa los casos con nombres LIMPIOS (nada de "con:cliente")',
        !str_contains($logQ, 'con:cliente') && !str_contains($logQ, 'sin:cliente')
            && str_contains($logQ, 'MÁS DE UN cliente') && str_contains($logQ, 'Juan Gomez')
            && str_contains($logQ, 'Fulano Inexistente')
            && str_contains($logQ, '4 venta(s) leídas tienen su cliente')
            && str_contains($logQ, '3 venta(s) no tenían cliente en el legacy'),
        'log = ' . $logQ,
        $failures, $checks
    );

    // Una venta REAL hecha en Punto, sin cliente, dentro del rango (después de
    // la primera corrida, que crea la partición del mes): el migrador no
    // puede tocarla nunca.
    $outletQ = (string) EncomMigrationService::mapped($companyQ, 'outlet', 'out-1');
    $userQ   = (string) scalar('SELECT contactId FROM contact WHERE companyId = ? AND type = 0 LIMIT 1', [$companyQ]);
    $realQ   = (string) ncmInsert(['table' => 'transaction', 'records' => [
        'transactionDate' => '2026-08-15 12:00:00', 'transactionType' => 0, 'transactionStatus' => 1,
        'transactionComplete' => 1, 'transactionTotal' => 5000, 'userId' => $userQ,
        'outletId' => $outletQ, 'companyId' => $companyQ,
    ]]);

    // El estado en que quedaron las ventas del tenant 019ff24f: importadas
    // SIN cliente. Y una que alguien ya vinculó a mano a otro cliente.
    $db->Execute(
        "UPDATE transaction SET customerId = NULL WHERE companyId = ? AND meta->>'legacyId' IN ('vc-1', 'vc-4')",
        [$companyQ]
    );
    $db->Execute(
        "UPDATE transaction SET customerId = ? WHERE companyId = ? AND meta->>'legacyId' = 'vc-2'",
        [$contactoQ('vc-c5'), $companyQ]
    );
    $ventasAntesQ = (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyQ]);

    $runQ2 = (new EncomImportService($companyQ, $cliQ, null))->run(['sales_history'], $histOpts);
    $logQ2 = json_encode($runQ2['log'], JSON_UNESCAPED_UNICODE);

    check(
        'VC5 · relanzar COMPLETA el cliente de las ventas ya importadas, sin duplicar ninguna',
        $clienteDeVentaQ('vc-1') === (string) $contactoQ('vc-c1')
            && $clienteDeVentaQ('vc-4') === (string) $contactoQ('vc-c3')
            && (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyQ]) === $ventasAntesQ
            && ($runQ2['progress']['sales_history']['skipped'] ?? -1) === 9
            && str_contains($logQ2, '2 de ellas ya estaban importadas sin cliente y se COMPLETARON'),
        'vc-1=' . $clienteDeVentaQ('vc-1') . ' vc-4=' . $clienteDeVentaQ('vc-4') . ' · log = ' . $logQ2,
        $failures, $checks
    );

    check(
        'VC6 · NO pisa un cliente ya puesto ni toca la venta REAL hecha en Punto',
        $clienteDeVentaQ('vc-2') === (string) $contactoQ('vc-c5')
            && (string) scalar("SELECT COALESCE(customerId::text, '') FROM transaction WHERE transactionId = ?", [$realQ]) === '',
        'vc-2=' . $clienteDeVentaQ('vc-2'),
        $failures, $checks
    );

    // ── R. El latido, y el reaper que mata corridas sanas ─────────────
    // `requeueStale` medía contra `started_at`, o sea que era un TOPE DE
    // DURACIÓN disfrazado de detector de muerte: a los 45 minutos devolvía a
    // `pending` un job que estaba trabajando bien, y el drain le lanzaba un
    // SEGUNDO worker encima del primero.
    $jobLatido = (string) scalar(
        "INSERT INTO migration_job (companyid, source, status, domains, progress, attempts,
                                    created_at, started_at, updated_at)
         VALUES (?, 'encom', 'running', ?::jsonb, '{}'::jsonb, 1,
                 now() - interval '90 minutes', now() - interval '90 minutes', now() - interval '10 minutes')
         RETURNING jobid",
        [$companyG, json_encode(['expenses_history'])]
    );

    (new EncomMigrationService())->requeueStale(45);

    check(
        'R1 · un job que LATÓ hace 10 minutos NO se reencola, aunque arrancó hace 90',
        (string) scalar('SELECT status FROM migration_job WHERE jobid = ?', [$jobLatido]) === 'running',
        'status = ' . var_export(scalar('SELECT status FROM migration_job WHERE jobid = ?', [$jobLatido]), true)
            . ' (si dice pending, el reaper está matando corridas largas sanas)',
        $failures, $checks
    );

    $db->Execute(
        "UPDATE migration_job SET updated_at = now() - interval '60 minutes' WHERE jobid = ?",
        [$jobLatido]
    );
    (new EncomMigrationService())->requeueStale(45);

    check(
        'R2 · uno que DEJÓ de latir sí se reencola (el worker murió de verdad)',
        (string) scalar('SELECT status FROM migration_job WHERE jobid = ?', [$jobLatido]) === 'pending',
        'status = ' . var_export(scalar('SELECT status FROM migration_job WHERE jobid = ?', [$jobLatido]), true),
        $failures, $checks
    );

    // Y que el import LATA de verdad: sin esto, lo de arriba solo cambia
    // contra qué se mide un latido que nadie emite.
    $db->Execute(
        "UPDATE migration_job
            SET status = 'running', updated_at = now() - interval '30 minutes'
          WHERE jobid = ?",
        [$jobLatido]
    );

    (new EncomImportService($companyG, new FixtureEncomClient($fixtures), $jobLatido))
        ->run(['expenses_history'], $histOpts);

    $latidoFresco = scalar(
        "SELECT updated_at > now() - interval '1 minute' FROM migration_job WHERE jobid = ?",
        [$jobLatido]
    );

    check(
        'R3 · el import escribe progreso mientras corre, que es lo que mantiene vivo al job',
        in_array((string) $latidoFresco, ['1', 't', 'true'], true)
            && str_contains(
                (string) scalar('SELECT progress::text FROM migration_job WHERE jobid = ?', [$jobLatido]),
                'expenses_history'
            ),
        'latido fresco = ' . var_export($latidoFresco, true)
            . ' · progress = ' . (string) scalar('SELECT progress::text FROM migration_job WHERE jobid = ?', [$jobLatido]),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // G. PREREQUISITO — un dominio dependiente no emite cientos de derivados
    // ══════════════════════════════════════════════════════════════════
    // El incidente: `config` no mapeó ninguna sucursal y el histórico emitió
    // UN error POR VENTA —512— que enterraron la causa real bajo el síntoma.
    seedCompany($companyF, 'Sin Sucursales SA');

    $clientePrereq = new FixtureEncomClient($fixtures);
    $runPrereq     = (new EncomImportService($companyF, $clientePrereq, null))
        ->run(['sales_history'], $histOpts);

    $errPrereq = json_encode($runPrereq['errors'], JSON_UNESCAPED_UNICODE);

    check(
        'G1 · sin sucursales, el histórico aborta con UN error — no con uno por venta',
        count($runPrereq['errors']) === 1,
        'errors = ' . $errPrereq,
        $failures, $checks
    );

    check(
        'G2 · y ese error nombra la CAUSA (falta el dominio config), no el síntoma por fila',
        str_contains($errPrereq, 'config') && !str_contains($errPrereq, 'Venta '),
        'errors = ' . $errPrereq,
        $failures, $checks
    );

    check(
        'G3 · el chequeo corre ANTES de la red: no se le pidió una sola venta al legacy',
        $clientePrereq->calls === []
            && (int) scalar('SELECT count(*) FROM transaction WHERE companyId = ?', [$companyF]) === 0,
        'calls = ' . json_encode($clientePrereq->calls),
        $failures, $checks
    );

    // ══════════════════════════════════════════════════════════════════
    // Z. Barrido de credenciales huérfanas (TTL 24 h)
    // ══════════════════════════════════════════════════════════════════
    // Un job que nunca se ejecuta —falta ENCOM_MIGRATION_URL, cron caído—
    // retendría la sesión viva del panel de un cliente para siempre.
    seedCompany($companyId, 'Comercio Migrado SA');

    $db->Execute(
        "INSERT INTO migration_job (companyid, source, status, domains, credentials, created_at)
         VALUES (?, 'encom', 'pending', '[\"catalog\"]'::jsonb, ?::jsonb, now() - interval '30 hours')",
        [$companyId, json_encode(['cookies' => ['PHPSESSID' => 'viva'], 'legacyUrl' => 'https://legacy.test'])]
    );

    $antes = (int) scalar(
        'SELECT count(*) FROM migration_job WHERE companyid = ? AND credentials IS NOT NULL',
        [$companyId]
    );

    (new EncomMigrationService())->drain();

    $conCreds = (int) scalar(
        'SELECT count(*) FROM migration_job WHERE companyid = ? AND credentials IS NOT NULL',
        [$companyId]
    );

    check(
        'Z1 · el job viejo tenía credenciales guardadas antes del barrido',
        $antes === 1,
        "jobs con credenciales antes = $antes",
        $failures, $checks
    );

    check(
        'Z2 · el drain borra las cookies de un job pending de más de 24 h',
        $conCreds === 0,
        "jobs con credenciales después = $conCreds",
        $failures, $checks
    );

    $estado = scalar(
        'SELECT status FROM migration_job WHERE companyid = ? ORDER BY created_at DESC LIMIT 1',
        [$companyId]
    );
    check(
        'Z3 · además lo cierra como failed (si no, bloquearía toda migración futura de esa empresa)',
        (string) $estado === 'failed',
        'status = ' . var_export($estado, true),
        $failures, $checks
    );

    $motivo = (string) scalar(
        'SELECT errors::text FROM migration_job WHERE companyid = ? ORDER BY created_at DESC LIMIT 1',
        [$companyId]
    );
    check(
        'Z4 · el job dice por qué murió (la sesión caducó), no queda mudo',
        str_contains($motivo, 'caduc'),
        "errors = $motivo",
        $failures, $checks
    );
    // ══════════════════════════════════════════════════════════════════
    // Y. SUCURSAL QUE YA EXISTE — no se duplica (caso real 2026-09-18)
    // ══════════════════════════════════════════════════════════════════
    // El signup crea "Central" con su depósito y su caja. El dominio config
    // creaba SIEMPRE otra por cada sucursal del legacy y el comercio quedaba
    // con dos "Central": cajas y stock en una, histórico en la otra.
    $outletSvc = new \Punto\Api\Outlets\OutletsService();
    $origen    = \Punto\Api\Outlets\OutletsService::ORIGIN_SUPPORT;
    $depositos = static fn (string $cid): int => (int) scalar(
        "SELECT count(*) FROM taxonomy WHERE companyid = ? AND taxonomytype = 'location'", [$cid]
    );
    $nombreCaja = static fn (string $regId): string => (string) scalar(
        'SELECT registerName FROM register WHERE registerId = ?', [$regId]
    );
    $cajaDe = static fn (string $cid): string => (string) scalar(
        'SELECT registerId FROM register WHERE companyId = ? ORDER BY registerCreationDate LIMIT 1', [$cid]
    );

    // ── Y1. Reusa por NOMBRE, y la otra toma la libre que queda ───────
    seedCompany($companyL, 'Comercio Con Dos Sucursales SA');
    $centralL = (string) $outletSvc->create($companyL, ['name' => '  CASA   central '], $origen);
    $norteL   = (string) $outletSvc->create($companyL, ['name' => 'Depósito Norte'], $origen);
    $cajaPreviaL = $cajaDe($companyL);
    $runL = (new EncomImportService($companyL, new FixtureEncomClient($fixtures), null))->run(['config']);

    check(
        'Y1 · la homónima se reusa por NOMBRE y la otra toma la libre que queda: 2 sucursales, no 4',
        EncomMigrationService::mapped($companyL, 'outlet', 'out-1') === $centralL
            && EncomMigrationService::mapped($companyL, 'outlet', 'out-2') === $norteL
            && countOf('outlet', $companyL) === 2
            && $depositos($companyL) === 2,
        'mapa out-1 = ' . var_export(EncomMigrationService::mapped($companyL, 'outlet', 'out-1'), true)
            . ' · outlets = ' . countOf('outlet', $companyL) . ' · depósitos = ' . $depositos($companyL)
            . ' · errors = ' . json_encode($runL['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );
    check(
        'Y1b · la caja del comercio NO se fusiona con una del legacy (sigue siendo la suya)',
        $nombreCaja($cajaPreviaL) === 'Nueva Caja'
            && (int) scalar(
                "SELECT count(*) FROM migration_map WHERE companyid = ? AND domain = 'register' AND puntoid = ?",
                [$companyL, $cajaPreviaL]
            ) === 0,
        'nombre = ' . $nombreCaja($cajaPreviaL),
        $failures, $checks
    );
    check(
        'Y1c · la bitácora dice que se reusó la sucursal existente',
        str_contains(json_encode($runL['log'] ?? [], JSON_UNESCAPED_UNICODE), 'mismo nombre')
            && str_contains(json_encode($runL['log'] ?? [], JSON_UNESCAPED_UNICODE), 'la más antigua todavía sin asignar'),
        'log = ' . json_encode($runL['log'] ?? [], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Y2. Reusa la ÚNICA sucursal del signup (nombre distinto) ──────
    seedCompany($companyM, 'Comercio Recién Dado De Alta SA');
    $centralM    = (string) $outletSvc->create($companyM, ['name' => 'Central'], $origen);
    $cajaPreviaM = $cajaDe($companyM);
    $runM = (new EncomImportService($companyM, new UnaSucursalEncomClient($fixtures), null))->run(['config']);
    $cajaLegacyM = (string) EncomMigrationService::mapped($companyM, 'register', 'una-reg-1');

    check(
        'Y2 · con UNA sucursal de cada lado se reusa la del signup: sigue habiendo una sola "Central"',
        EncomMigrationService::mapped($companyM, 'outlet', 'una-out-1') === $centralM
            && countOf('outlet', $companyM) === 1
            && $depositos($companyM) === 1,
        'outlets = ' . countOf('outlet', $companyM) . ' · depósitos = ' . $depositos($companyM)
            . ' · errors = ' . json_encode($runM['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );
    check(
        'Y2b · la caja del legacy se CREA dentro de la sucursal reusada, al lado de la del comercio',
        $cajaLegacyM !== '' && $cajaLegacyM !== $cajaPreviaM
            && (string) scalar('SELECT outletId FROM register WHERE registerId = ?', [$cajaLegacyM]) === $centralM
            && $nombreCaja($cajaPreviaM) === 'Nueva Caja'
            && countOf('register', $companyM) === 2,
        'caja legacy = ' . $cajaLegacyM . ' · cajas = ' . countOf('register', $companyM),
        $failures, $checks
    );
    check(
        'Y2c · completa lo que la sucursal no tenía (razón social) sin pisarle el nombre',
        (string) scalar('SELECT outletName FROM outlet WHERE outletId = ?', [$centralM]) === 'Central'
            && ($outletSvc->get($centralM, $companyM)['billingName'] ?? '') === 'Una Sucursal SA',
        'sucursal = ' . json_encode($outletSvc->get($centralM, $companyM), JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Y3. Dos sucursales libres y ninguna homónima → la más antigua ─
    seedCompany($companyN, 'Comercio Sin Coincidencia SA');
    $centralN = (string) $outletSvc->create($companyN, ['name' => 'Central'], $origen);
    $outletSvc->create($companyN, ['name' => 'Norte'], $origen);
    $runN = (new EncomImportService($companyN, new UnaSucursalEncomClient($fixtures), null))->run(['config']);
    $nuevaN = (string) EncomMigrationService::mapped($companyN, 'outlet', 'una-out-1');

    check(
        'Y3 · sin homónima se reusa la sucursal libre MÁS ANTIGUA, no se crea otra',
        $nuevaN === $centralN && countOf('outlet', $companyN) === 2,
        'outlets = ' . countOf('outlet', $companyN) . ' · errors = ' . json_encode($runN['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Y5. Todas las del destino ya tomadas → recién ahí se crea ─────
    seedCompany($companyO, 'Comercio Que Crece SA');
    $centralO = (string) $outletSvc->create($companyO, ['name' => 'Central'], $origen);
    $runO = (new EncomImportService($companyO, new FixtureEncomClient($fixtures), null))->run(['config']);
    $out2O = (string) EncomMigrationService::mapped($companyO, 'outlet', 'out-2');

    check(
        'Y5 · la primera toma la única libre y la segunda, sin ninguna libre, se CREA',
        EncomMigrationService::mapped($companyO, 'outlet', 'out-1') === $centralO
            && $out2O !== '' && $out2O !== $centralO
            && countOf('outlet', $companyO) === 2
            && str_contains(json_encode($runO['log'] ?? [], JSON_UNESCAPED_UNICODE), 'se creó nueva'),
        'outlets = ' . countOf('outlet', $companyO) . ' · log = ' . json_encode($runO['log'] ?? [], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Y6. El orden del export no roba la homónima de otra ───────────
    // "Sucursal Shopping" llega PRIMERO y no tiene homónima: la más antigua
    // libre es "Norte", no "Casa Central", que está reservada para la suya.
    seedCompany($companyP, 'Comercio Orden Invertido SA');
    $norteP   = (string) $outletSvc->create($companyP, ['name' => 'Norte'], $origen);
    $centralP = (string) $outletSvc->create($companyP, ['name' => 'Casa Central'], $origen);
    (new EncomImportService($companyP, new OrdenInvertidoEncomClient($fixtures), null))->run(['config']);

    check(
        'Y6 · con el export invertido cada una cae en la que corresponde (sin robar la homónima)',
        EncomMigrationService::mapped($companyP, 'outlet', 'out-1') === $centralP
            && EncomMigrationService::mapped($companyP, 'outlet', 'out-2') === $norteP
            && countOf('outlet', $companyP) === 2,
        'out-1 = ' . var_export(EncomMigrationService::mapped($companyP, 'outlet', 'out-1'), true)
            . ' · out-2 = ' . var_export(EncomMigrationService::mapped($companyP, 'outlet', 'out-2'), true),
        $failures, $checks
    );

    // ── Y7. Crash entre la marca `outlet_reused` y el mapa `outlet` ───
    // El worker murió después de marcar la reusada y antes de mapearla: al
    // relanzar tiene que volver a caer en LA MISMA sucursal, sin crear otra.
    cleanup($companyP);
    seedCompany($companyP, 'Comercio Que Se Cortó SA');
    $centralY7 = (string) $outletSvc->create($companyP, ['name' => 'Central'], $origen);
    EncomMigrationService::remember($companyP, 'outlet_reused', 'una-out-1', $centralY7, null);
    $runY7 = (new EncomImportService($companyP, new UnaSucursalEncomClient($fixtures), null))->run(['config']);

    check(
        'Y7 · tras un corte a mitad de camino, relanzar mapea la misma sucursal y no crea otra',
        EncomMigrationService::mapped($companyP, 'outlet', 'una-out-1') === $centralY7
            && countOf('outlet', $companyP) === 1
            && $runY7['errors'] === [],
        'outlets = ' . countOf('outlet', $companyP) . ' · errors = ' . json_encode($runY7['errors'], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );

    // ── Y4. Idempotencia: relanzar no crea sucursales, depósitos ni cajas ─
    $fotoY = static fn (string $cid): array => [
        countOf('outlet', $cid), $depositos($cid), countOf('register', $cid),
        (int) scalar('SELECT count(*) FROM migration_map WHERE companyid = ?', [$cid]),
    ];
    $antesL = $fotoY($companyL);
    $antesM = $fotoY($companyM);
    $antesN = $fotoY($companyN);
    $antesO = $fotoY($companyO);
    $rerunL = (new EncomImportService($companyL, new FixtureEncomClient($fixtures), null))->run(['config']);
    $rerunM = (new EncomImportService($companyM, new UnaSucursalEncomClient($fixtures), null))->run(['config']);
    (new EncomImportService($companyN, new UnaSucursalEncomClient($fixtures), null))->run(['config']);
    (new EncomImportService($companyO, new FixtureEncomClient($fixtures), null))->run(['config']);

    check(
        'Y4 · relanzar no duplica nada, dice que ya estaba migrada y la caja del comercio sigue intacta',
        $fotoY($companyL) === $antesL && $fotoY($companyM) === $antesM && $fotoY($companyN) === $antesN
            && $fotoY($companyO) === $antesO
            && str_contains(json_encode($rerunL['log'] ?? [], JSON_UNESCAPED_UNICODE), 'ya estaba migrada')
            && $nombreCaja($cajaPreviaL) === 'Nueva Caja' && $nombreCaja($cajaPreviaM) === 'Nueva Caja'
            && $rerunL['errors'] === [] && $rerunM['errors'] === [],
        'L ' . json_encode([$antesL, $fotoY($companyL)]) . ' · M ' . json_encode([$antesM, $fotoY($companyM)])
            . ' · N ' . json_encode([$antesN, $fotoY($companyN)])
            . ' · errors = ' . json_encode([$rerunL['errors'], $rerunM['errors']], JSON_UNESCAPED_UNICODE),
        $failures, $checks
    );
} finally {
    cleanup($companyId);
    cleanup($companyB);
    cleanup($companyC);
    cleanup($companyE);
    cleanup($companyF);
    cleanup($companyG);
    cleanup($companyH);
    cleanup($companyI);
    cleanup($companyJ);
    cleanup($companyK);
    cleanup($companyL);
    cleanup($companyM);
    cleanup($companyN);
    cleanup($companyO);
    cleanup($companyP);
    cleanup($companyQ);
    cleanup($companyR);
    // `period_close` cuelga de la empresa y no la borra `cleanup()`: sin esta
    // línea, una segunda corrida del arnés contra la misma base encontraría el
    // período ya cerrado y H17 pasaría por el motivo equivocado.
    try {
        $db->Execute('DELETE FROM period_close WHERE companyid = ?', [$companyD]);
    } catch (\Throwable $e) {
        // la tabla puede no existir en una base vieja; el cleanup no falla por eso
    }
    cleanup($companyD);
}

harnessFinish($failures, $checks);
