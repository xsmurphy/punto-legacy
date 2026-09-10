<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cdc — el Código de Control (CDC) de un Documento Electrónico de SIFEN.
 *
 * Clase PURA: no toca BD, no habla con el motor, no lee config. Todo lo que
 * necesita entra por argumento. Eso la hace testeable contra el CDC real de un
 * KuDE emitido (ver `verify_cdc.php`, caso de oro) sin levantar nada.
 *
 * ── Anatomía (44 dígitos) ────────────────────────────────────────────────
 *
 * Verificada contra un KuDE REAL y legal del sistema anterior de un tenant
 * (`context/refs/kude-ejemplo-balloon-party.pdf`):
 *
 *   01 03595193 1 001 001 0000833 1 20260904 1 000000001 2
 *   ─┬ ──────┬─ ┬ ─┬─ ─┬─ ───┬─── ┬ ────┬─── ┬ ────┬──── ┬
 *    │       │  │  │   │     │    │     │    │     │     └ (11) DV módulo 11
 *    │       │  │  │   │     │    │     │    │     └────── (10) código de seguridad (9)
 *    │       │  │  │   │     │    │     │    └──────────── (9)  tipo de emisión (1)
 *    │       │  │  │   │     │    │     └───────────────── (8)  fecha AAAAMMDD (8)
 *    │       │  │  │   │     │    └─────────────────────── (7)  tipo contribuyente (1)
 *    │       │  │  │   │     └──────────────────────────── (6)  número del documento (7)
 *    │       │  │  │   └────────────────────────────────── (5)  punto de expedición (3)
 *    │       │  │  └────────────────────────────────────── (4)  establecimiento (3)
 *    │       │  └───────────────────────────────────────── (3)  DV del RUC (1)
 *    │       └──────────────────────────────────────────── (2)  RUC emisor sin DV (8)
 *    └──────────────────────────────────────────────────── (1)  tipo de documento (2)
 *
 * TODOS los componentes son calculables localmente: no hay ninguno que solo
 * el proveedor pueda saber. Eso es lo que habilita los dos usos de esta clase.
 *
 * ── Para qué se usa HOY: verificación estructural ────────────────────────
 *
 * Desde el merge de la numeración del emisor, la factura electrónica sale con
 * el número congelado de la caja (`transaction.invoiceNo`), el MISMO que salió
 * impreso en el ticket — ver `SaleToFePyMapper::resolveDocumentNumber()`.
 * Ese es un invariante fiscal, no una preferencia: si el motor ignorara
 * nuestro número y numerara por su cuenta, el CDC que vuelve identificaría un
 * documento DISTINTO del que el cliente se llevó impreso, y todo lo que el
 * comercio imprima después (CDC, QR, link de consulta) estaría MINTIENDO.
 *
 * `assertMatchesSale()` descompone el CDC devuelto y verifica que el número,
 * el RUC, el establecimiento, el punto de expedición y la fecha coincidan con
 * lo que la venta declara. La discrepancia se marca VISIBLE (el documento
 * queda en `error` con el motivo en castellano), nunca en silencio: un CDC
 * ajeno impreso en un comprobante fiscal es peor que no imprimir nada.
 *
 * ── Para qué se prepara: CDC del EMISOR (gateado, sin activar) ───────────
 *
 * Si el motor acepta el CDC y el código de seguridad calculados por el
 * emisor, el CDC pasa a calcularse ACÁ
 * antes de emitir: el comprobante sale con CDC y QR IMPRESOS EN EL MOMENTO DE
 * LA VENTA, offline incluido, sin esperar la respuesta asíncrona del
 * proveedor. `build()` y `securityCode()` ya hacen esa parte; lo único que
 * falta es el campo del payload, y el interruptor es
 * `einvoice_account.config->>'emitterCdc'` (default false, sin UI) — ver
 * `SaleToInvoiceMapper::build()`.
 *
 * ── El código de seguridad es ALEATORIO CRIPTOGRÁFICO, nunca secuencial ──
 *
 * Es la razón de ser del campo: sin él, conocer el RUC de un comercio y un
 * número de factura alcanza para armar el CDC de CUALQUIER otra factura suya y
 * consultarla en el portal público de la SET. El sistema anterior del tenant
 * numeraba estos 9 dígitos en secuencia (el KuDE de referencia lleva
 * `000000001`), que es exactamente el caso enumerable que el campo existe para
 * impedir. Acá sale de `random_int()` (CSPRNG), nunca de un contador.
 */
final class Cdc
{
    /** Largo total del CDC, con dígito verificador. */
    public const LENGTH = 44;

    /** Largo del código de seguridad (componente 10). */
    public const SECURITY_CODE_LENGTH = 9;

    /**
     * Componentes en orden, con su largo. El ORDEN Y EL LARGO son el formato
     * — `parse()` y `build()` los recorren de acá, así que no pueden divergir
     * entre sí (que es como se rompen normalmente los parsers de posición
     * fija escritos dos veces).
     *
     * El DV no está en la lista: no es un componente de entrada, se CALCULA
     * sobre la concatenación de los otros diez.
     *
     * @var array<string,int>
     */
    private const LAYOUT = [
        'documentType'      => 2,
        'ruc'               => 8,
        'rucCheckDigit'     => 1,
        'establishment'     => 3,
        'expeditionPoint'   => 3,
        'number'            => 7,
        'taxpayerType'      => 1,
        'date'              => 8,
        'emissionType'      => 1,
        'securityCode'      => self::SECURITY_CODE_LENGTH,
    ];

    /**
     * Dígito verificador módulo 11 en base 11 (SET).
     *
     * Los dígitos se recorren de DERECHA A IZQUIERDA con pesos que ciclan
     * 2,3,…,11 y vuelven a 2. `dv = 11 - (suma mod 11)`, con los dos casos
     * de borde canónicos: 11 → 0 y 10 → 1.
     *
     * El "base 11" del nombre es el TOPE DEL PESO, no el módulo — ese detalle
     * es el que se equivoca siempre: con pesos 2..9 (la variante de módulo 11
     * más común en otros documentos) este mismo CDC da 7 en vez de 2.
     *
     * Es EL MISMO algoritmo que produce el DV del RUC paraguayo, y por eso
     * vive en un solo lugar: el KuDE de referencia trae `3595193-1` (emisor) y
     * `4178655-6` (receptor), y las tres verificaciones —los dos RUC y el
     * CDC— pasan por esta función en `verify_cdc.php`. Antes de escribir otra
     * implementación de mod 11 en este repo, usar esta.
     *
     * @param string $digits Solo dígitos. Un carácter no numérico lanza.
     */
    public static function checkDigit(string $digits): int
    {
        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            throw new \InvalidArgumentException(
                'El dígito verificador módulo 11 se calcula sobre dígitos; llegó: ' . $digits
            );
        }

        $sum    = 0;
        $weight = 2;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += ((int) $digits[$i]) * $weight;
            $weight++;
            if ($weight > 11) {
                $weight = 2;
            }
        }

        $dv = 11 - ($sum % 11);
        if ($dv === 11) {
            return 0;
        }
        if ($dv === 10) {
            return 1;
        }

        return $dv;
    }

    /**
     * Código de seguridad de 9 dígitos, aleatorio criptográfico.
     *
     * `random_int()` y no `rand()`/`mt_rand()`: este número es lo único que
     * impide enumerar los comprobantes del comercio en el portal público de la
     * SET (ver docblock de la clase), así que un PRNG predecible acá anula el
     * campo entero.
     *
     * Nunca `000000000`: es el valor que un emisor devuelve cuando "todavía no
     * lo generó", y confundirlo con un código real haría pasar por válido un
     * CDC a medio armar.
     */
    public static function securityCode(): string
    {
        return str_pad((string) random_int(1, 999999999), self::SECURITY_CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Arma los 44 dígitos a partir de los componentes, calculando el DV.
     *
     * Cada componente se normaliza a SOLO DÍGITOS y se rellena con ceros a la
     * izquierda hasta su largo (`0000833` de un `833` entero, `001` del punto
     * de expedición). Un componente que YA excede su largo es un error de
     * datos y lanza — truncarlo produciría un CDC sintácticamente válido que
     * identifica otro documento, que es el peor fallo posible acá.
     *
     * @param array{
     *   documentType: int|string,
     *   ruc: int|string,
     *   rucCheckDigit: int|string,
     *   establishment: int|string,
     *   expeditionPoint: int|string,
     *   number: int|string,
     *   taxpayerType: int|string,
     *   date: string,
     *   emissionType: int|string,
     *   securityCode: int|string
     * } $parts `date` en AAAAMMDD o YYYY-MM-DD (se le sacan los guiones).
     */
    public static function build(array $parts): string
    {
        $base = '';
        foreach (self::LAYOUT as $field => $length) {
            if (!array_key_exists($field, $parts) || $parts[$field] === null || $parts[$field] === '') {
                throw new \InvalidArgumentException("Falta el componente '{$field}' para armar el CDC.");
            }

            // Los guiones de una fecha 'YYYY-MM-DD' y cualquier separador que
            // venga en el RUC se descartan acá: el CDC es posicional y solo
            // acepta dígitos.
            $raw = preg_replace('/\D/', '', (string) $parts[$field]) ?? '';
            if ($raw === '') {
                throw new \InvalidArgumentException("El componente '{$field}' del CDC no tiene dígitos.");
            }
            if (strlen($raw) > $length) {
                throw new \InvalidArgumentException(sprintf(
                    "El componente '%s' del CDC no entra en %d dígitos (llegó '%s'). No se trunca: " .
                    'un CDC truncado identifica otro documento.',
                    $field,
                    $length,
                    $raw
                ));
            }

            $base .= str_pad($raw, $length, '0', STR_PAD_LEFT);
        }

        return $base . self::checkDigit($base);
    }

    /**
     * Descompone un CDC en sus componentes. Lanza si no son 44 dígitos.
     *
     * NO valida el DV (para eso está `isValid()`): descomponer un CDC con DV
     * malo sigue siendo útil para explicar QUÉ tiene de distinto.
     *
     * @return array<string,string> Los diez componentes más `checkDigit`.
     */
    public static function parse(string $cdc): array
    {
        $clean = preg_replace('/\s+/', '', $cdc) ?? '';
        if (strlen($clean) !== self::LENGTH || preg_match('/^\d+$/', $clean) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Un CDC son %d dígitos; llegó "%s" (%d caracteres).',
                self::LENGTH,
                $cdc,
                strlen($clean)
            ));
        }

        $out    = [];
        $offset = 0;
        foreach (self::LAYOUT as $field => $length) {
            $out[$field] = substr($clean, $offset, $length);
            $offset += $length;
        }
        $out['checkDigit'] = substr($clean, $offset, 1);

        return $out;
    }

    /** true si son 44 dígitos y el DV cierra contra los primeros 43. */
    public static function isValid(string $cdc): bool
    {
        try {
            $parts = self::parse($cdc);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $clean = preg_replace('/\s+/', '', $cdc) ?? '';

        return (string) self::checkDigit(substr($clean, 0, self::LENGTH - 1)) === $parts['checkDigit'];
    }

    /**
     * CDC formateado para IMPRIMIR. El KuDE de referencia lo saca CORRIDO
     * (`CDC: 0103595193…0012`, sin separadores), así que eso es lo que
     * devuelve: el formato del documento legal manda sobre la legibilidad.
     *
     * Existe como función igual —en vez de imprimir el campo crudo— para que
     * el día que se decida agrupar de a cuatro haya UN lugar que cambiar y no
     * tres renderers.
     */
    public static function forDisplay(string $cdc): string
    {
        return preg_replace('/\s+/', '', $cdc) ?? $cdc;
    }

    /**
     * ── EL GUARD ────────────────────────────────────────────────────────
     *
     * Verifica que el CDC que devolvió el proveedor describa LA MISMA VENTA
     * que mandamos. Devuelve la lista de discrepancias en castellano; vacía
     * significa que el documento electrónico y el ticket impreso son el mismo
     * comprobante.
     *
     * El chequeo que importa de verdad es `number`: es el único componente que
     * el proveedor podría reescribir por su cuenta (numerando él en vez de
     * respetar el correlativo congelado de la caja), y es justo el que el
     * cliente ya tiene impreso en la mano.
     *
     * Los demás componentes se comparan SOLO si el llamador tiene un valor
     * local con qué comparar: exigirlos siempre convertiría un dato faltante
     * de nuestro lado en un falso positivo que bloquearía emisiones válidas.
     * Ausente ⇒ no se opina, nunca ⇒ se asume que está bien.
     *
     * @param array{
     *   documentType?: int|string|null,
     *   ruc?: int|string|null,
     *   establishment?: int|string|null,
     *   expeditionPoint?: int|string|null,
     *   number?: int|string|null,
     *   date?: string|null
     * } $expected
     * @return list<string> Discrepancias. Vacío = todo coincide.
     */
    public static function assertMatchesSale(string $cdc, array $expected): array
    {
        try {
            $parts = self::parse($cdc);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        $problems = [];

        if (!self::isValid($cdc)) {
            $problems[] = sprintf(
                'el dígito verificador del CDC %s no cierra (módulo 11), así que el código está mal formado ' .
                'o llegó alterado',
                $cdc
            );
        }

        // El número es el invariante fiscal duro — mensaje propio y explícito.
        if (isset($expected['number']) && $expected['number'] !== '' && $expected['number'] !== null) {
            $wantNumber = (int) preg_replace('/\D/', '', (string) $expected['number']);
            $gotNumber  = (int) $parts['number'];
            if ($wantNumber !== $gotNumber) {
                $problems[] = sprintf(
                    'el CDC dice que el documento es el número %d, pero la venta se cobró e imprimió con el ' .
                    'número %d. El proveedor no respetó la numeración de la caja: el comprobante que tiene el ' .
                    'cliente y el documento electrónico son documentos DISTINTOS',
                    $gotNumber,
                    $wantNumber
                );
            }
        }

        $simple = [
            'documentType'    => ['tipo de documento', $parts['documentType']],
            'ruc'             => ['RUC del emisor', $parts['ruc']],
            'establishment'   => ['establecimiento', $parts['establishment']],
            'expeditionPoint' => ['punto de expedición', $parts['expeditionPoint']],
            'date'            => ['fecha de emisión', $parts['date']],
        ];
        foreach ($simple as $key => [$label, $got]) {
            if (!isset($expected[$key]) || $expected[$key] === '' || $expected[$key] === null) {
                continue;
            }
            // Comparación por DÍGITOS con relleno al largo del componente: un
            // establecimiento guardado como '1' y uno como '001' son el mismo,
            // y una fecha 'YYYY-MM-DD' es la misma que 'AAAAMMDD'.
            $want = preg_replace('/\D/', '', (string) $expected[$key]) ?? '';
            if ($want === '') {
                continue;
            }
            $want = str_pad($want, strlen($got), '0', STR_PAD_LEFT);
            if ($want !== $got) {
                $problems[] = sprintf(
                    'el %s del CDC es %s y la venta declara %s',
                    $label,
                    $got,
                    $want
                );
            }
        }

        return $problems;
    }
}
