<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * Resuelve el CLIENTE de una venta histórica del legacy contra los clientes
 * del comercio en Punto (context/77 §17.18).
 *
 * ── Por qué por nombre y no por id ───────────────────────────────────────
 * El listado de ventas del legacy (`a_report_transactions?action=detailTable`)
 * NO trae el id del cliente: la fila lleva `data-id` = la VENTA, y la celda
 * `Cliente` solo `contactName` (texto) + `data-filter` con "{contactName}
 * {contactSecondName} con:cliente" (ver `EncomParse::customerCell()`). Lo
 * único más que trae es la columna `RUC` (`contactTIN`). Filtrar por cliente
 * (`cusId`) sería una request por cliente: miles.
 *
 * Así que se resuelve con lo que hay, contra TODOS los clientes (`type = 1`)
 * del comercio —migrados, unificados por documento o cargados en Punto—, en
 * memoria (un SELECT por corrida):
 *
 *   1. clave de NOMBRE normalizada → un solo cliente: ese.
 *   2. más de uno: el RUC de la celda desempata; si no desempata → AMBIGUO,
 *      no se vincula (pegarle la venta al homónimo equivocado es peor que no
 *      pegarla: el reporte de clientes mentiría sin que nadie lo note).
 *   3. ninguno por nombre: si el RUC de la celda es de UN solo cliente, ese
 *      (el contacto unificado por documento puede tener otro nombre).
 *
 * ── Las claves de un contacto ────────────────────────────────────────────
 * El importador de clientes copia 1:1 `contactName` → `contactName` y
 * `contactSecondName` → `data.contactSecondName`, pero cuando el legacy no
 * tenía segundo nombre `ContactService` repite el nombre ahí. O sea:
 *
 *   · segundo nombre DISTINTO del nombre → la celda es "nombre segundo".
 *   · segundo nombre vacío o IGUAL → la celda es "nombre" (o "nombre nombre"
 *     si el legacy lo tenía repetido de verdad): se indexan las dos.
 *
 * No se indexa "nombre" solo para el primer caso: haría ambiguas celdas que
 * no lo son.
 *
 * ── Normalización ────────────────────────────────────────────────────────
 * Minúsculas; la puntuación (`,` `.` `-` …) vale espacio; y TODO carácter no
 * ASCII —Ñ, Ü, vocales con tilde— y los restos de encoding del legacy (`?`,
 * `¿`, `�`, mojibake de dos bytes) se colapsan en un comodín `*`. Medido en
 * producción: 113 de 5.720 clientes del tenant 019ff24f están guardados como
 * "ACU?A"/"AG¿ERO" mientras el listado puede traer "ACUÑA": con el comodín
 * los dos son "acu*a". El precio es que "gomez" y "gómez" NO coinciden, y
 * está bien: los dos lados salen del MISMO registro del legacy, así que
 * difieren en encoding, no en ortografía.
 */
final class EncomCustomerMatcher
{
    public const OK        = 'ok';
    public const NONE      = 'none';        // la venta no tenía cliente
    public const AMBIGUOUS = 'ambiguous';   // más de un cliente con ese nombre
    public const NOT_FOUND = 'not_found';   // el nombre no es de ningún cliente

    /**
     * Placeholders del legacy que NO son un cliente: `getCustomerData()`
     * devuelve "Sin Nombre" cuando el contacto ya no existe, y "Consumidor
     * Final" es la venta de mostrador cargada como contacto. Vincularlos
     * juntaría miles de ventas anónimas en un "cliente" que encabezaría el
     * análisis de clientes.
     */
    private const SIN_CLIENTE = ['sin nombre', 'consumidor final'];

    /** @var array<string,array<string,true>> clave de nombre → contactIds */
    private array $porNombre = [];

    /** @var array<string,array<string,true>> RUC normalizado → contactIds */
    private array $porRuc = [];

    /** @var array<string,string> contactId → RUC normalizado */
    private array $rucDe = [];

    private bool $cargado = false;

    public function __construct(private readonly string $companyId)
    {
    }

    /**
     * @return array{status:string,id:string,by:string}
     *         `by` = 'name' | 'tin' | '' (cómo se resolvió).
     */
    public function resolve(string $nombre, string $ruc = ''): array
    {
        $clave = self::key($nombre);
        if ($clave === '' || self::esSinCliente($clave)) {
            return ['status' => self::NONE, 'id' => '', 'by' => ''];
        }

        $this->cargar();
        $rucN = self::tinKey($ruc);

        $ids = array_keys($this->porNombre[$clave] ?? []);
        if (count($ids) === 1) {
            return ['status' => self::OK, 'id' => (string) $ids[0], 'by' => 'name'];
        }

        if (count($ids) > 1) {
            if ($rucN !== '') {
                $conRuc = array_values(array_filter(
                    $ids,
                    fn ($id) => ($this->rucDe[(string) $id] ?? '') === $rucN
                ));
                if (count($conRuc) === 1) {
                    return ['status' => self::OK, 'id' => (string) $conRuc[0], 'by' => 'tin'];
                }
            }
            return ['status' => self::AMBIGUOUS, 'id' => '', 'by' => ''];
        }

        if ($rucN !== '') {
            $porRuc = array_keys($this->porRuc[$rucN] ?? []);
            if (count($porRuc) === 1) {
                return ['status' => self::OK, 'id' => (string) $porRuc[0], 'by' => 'tin'];
            }
        }

        return ['status' => self::NOT_FOUND, 'id' => '', 'by' => ''];
    }

    /** Clave de comparación de un nombre. Ver el docblock de la clase. */
    public static function key(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Puntuación → espacio ("BAEZ, LILIAN" = "BAEZ LILIAN").
        $s = preg_replace('/[\\.,;:\'"`´()\\/\\-_]+/u', ' ', $s) ?? $s;
        // Todo lo que no es ASCII alfanumérico ni espacio → comodín, y una
        // racha de comodines (mojibake de 2 bytes) es UNO.
        $s = preg_replace('/[^a-z0-9 ]+/u', '*', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Placeholder, solo o repetido ("consumidor final consumidor final"). */
    private static function esSinCliente(string $clave): bool
    {
        foreach (self::SIN_CLIENTE as $p) {
            if ($clave === $p || $clave === $p . ' ' . $p) {
                return true;
            }
        }
        return false;
    }

    /** RUC sin puntos, guiones ni espacios; '' si no hay ("-" del legacy). */
    public static function tinKey(string $s): string
    {
        $s = strtoupper(trim($s));
        return preg_replace('/[^0-9A-Z]/', '', $s) ?? '';
    }

    private function cargar(): void
    {
        if ($this->cargado) {
            return;
        }
        $this->cargado = true;

        $rows = \ncmRows(
            "SELECT contactId AS id, contactName AS name,
                    data->>'contactSecondName' AS second, contactTIN AS tin
               FROM contact
              WHERE companyId = ? AND type = 1",
            [$this->companyId]
        );

        foreach ($rows as $r) {
            $id     = (string) ($r['id'] ?? '');
            $nombre = self::key((string) ($r['name'] ?? ''));
            $second = self::key((string) ($r['second'] ?? ''));
            if ($id === '') {
                continue;
            }

            $claves = [];
            if ($nombre !== '') {
                if ($second === '' || $second === $nombre) {
                    $claves[] = $nombre;
                    $claves[] = $nombre . ' ' . $nombre;
                } else {
                    $claves[] = $nombre . ' ' . $second;
                }
            } elseif ($second !== '') {
                $claves[] = $second;
            }
            foreach ($claves as $k) {
                $this->porNombre[$k][$id] = true;
            }

            $ruc = self::tinKey((string) ($r['tin'] ?? ''));
            if ($ruc !== '') {
                $this->porRuc[$ruc][$id] = true;
                $this->rucDe[$id]        = $ruc;
            }
        }
    }
}
