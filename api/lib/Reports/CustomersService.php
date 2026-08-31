<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactDisplayName;

/**
 * Dominio de Reportes — Clientes (API compartida, motor ERP).
 *
 * Tres secciones, una por tab del reporte de "Análisis de clientes":
 *
 *   - `ranking()`   → una fila por cliente con actividad en el período
 *                     (listado completo + ranking por monto).
 *   - `dashboard()` → nuevos vs recurrentes, tasas de retorno/retención/
 *                     crecimiento/pérdida y métricas de comportamiento.
 *   - `geography()` → dónde residen los clientes: localidades, ciudades y
 *                     coordenadas para el mapa de calor.
 *
 * `ranking()` es el port original de panel/lib/reports/ReportCustomersService.php
 * (Fase 2 batch 5), con dos agregados posteriores: `displayName` (resuelto con
 * ContactDisplayName, ver §"nombre vs razón social" abajo) y `avgTicket`.
 *
 * Tenant: TODA query agregada usa `Roc::build()` (companyId + outlet del
 * view-scope); los lookups de contacto llevan `companyId` bound.
 *
 * ── Nombre vs razón social ───────────────────────────────────────────────────
 * `name` es `contactName` (razón social) y `secondName` es el nombre de la
 * persona: se dejan CRUDOS porque hay consumidores externos del endpoint
 * (read-tools del agente / MCP) que ya leen ese shape. El nombre correcto para
 * mostrar sale de `displayName`, que aplica la regla del owner (nombre; si no
 * hay, razón social; NUNCA concatenar) vía `ContactDisplayName::resolve()`.
 *
 * ── Columnas que ya no son columnas ──────────────────────────────────────────
 * `contactSecondName`, `contactCI`, `contactAddress`, `contactCity` y
 * `contactLocation` fueron degradadas al JSONB `data` (migs 06/25). Se leen
 * igual porque el wrapper de BD aplana `data` sobre la fila
 * (`Query::flattenJsonb`) — por eso el `SELECT *` de `contactsByIds()` las
 * sigue devolviendo. Cualquier query que proyecte columnas explícitas de
 * `contact` tiene que traer `data` o leerlas con `data->>'…'`.
 */
final class CustomersService
{
    /** Ventas: contado (0) y crédito (3). Mismo criterio que el resto de los reportes. */
    private const VENTA_TYPES_SQL = '(0, 3)';

    /** Tope de filas del listado — el reporte es de análisis, no un export masivo. */
    private const RANKING_LIMIT = 500;

    /** Tope de localidades/ciudades devueltas. Más que esto no se lee en pantalla. */
    private const GEO_LIST_LIMIT = 200;

    /** Tope de puntos del heatmap: acota el payload sin cambiar la forma de la mancha. */
    private const GEO_POINTS_LIMIT = 5000;

    /** @return array filas crudas por cliente, ordenadas por total bruto desc. */
    public function ranking($from, $to, $roc, $companyId)
    {
        $sql = "SELECT customerId                          AS id,
                       SUM(transactionUnitsSold)           AS usold,
                       SUM(transactionTotal)               AS grosstotal,
                       SUM(transactionDiscount)            AS discount,
                       COUNT(transactionId)                AS count,
                       STRING_AGG(meta->>'tags', ',')      AS tags
                FROM transaction
                WHERE transactionType IN " . self::VENTA_TYPES_SQL . "
                  AND " . SaleFilters::notVoidedSql() . "
                  AND transactionDate BETWEEN ? AND ?
                  AND customerId IS NOT NULL" . $roc . "
                GROUP BY customerId
                ORDER BY grosstotal DESC
                LIMIT " . self::RANKING_LIMIT;

        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        $agg = [];
        $ids = [];
        while (!$res->EOF) {
            $f   = $res->fields;
            $cId = (string) $f['id'];
            $ids[$cId] = true;
            $agg[] = [
                'customerId' => $cId,
                'tags'       => $this->parseTags($f['tags'] ?? ''),
                'usold'      => (float) $f['usold'],
                'grossTotal' => (float) $f['grosstotal'],
                'discount'   => (float) $f['discount'],
                'count'      => (int)   $f['count'],
            ];
            $res->MoveNext();
        }
        $res->Close();

        $contacts = $this->contactsByIds(array_keys($ids), $companyId);

        $rows = [];
        foreach ($agg as $a) {
            $c = $contacts[$a['customerId']] ?? [];
            $rows[] = [
                'customerId'  => $a['customerId'],
                'name'        => (string) ($c['contactName'] ?? ''),
                'secondName'  => (string) ($c['contactSecondName'] ?? ''),
                // El nombre que se muestra. Ver §"nombre vs razón social".
                'displayName' => ContactDisplayName::resolve(
                    $c['contactName'] ?? null,
                    $c['contactSecondName'] ?? null
                ),
                'ruc'        => (string) ($c['contactTIN'] ?? ''),
                'ci'         => (string) ($c['contactCI'] ?? ''),
                'bday'       => (string) ($c['contactBirthDay'] ?? ''),
                'email'      => (string) ($c['contactEmail'] ?? ''),
                'phone'      => (string) ($c['contactPhone'] ?? ''),
                'phone2'     => (string) ($c['contactPhone2'] ?? ''),
                'address'    => (string) ($c['contactAddress'] ?? ''),
                'location'   => (string) ($c['contactLocation'] ?? ''),
                'city'       => (string) ($c['contactCity'] ?? ''),
                'tags'       => $a['tags'],
                'loyalty'    => (float)  ($c['contactLoyaltyAmount'] ?? 0),
                'usold'      => $a['usold'],
                'grossTotal' => $a['grossTotal'],
                'discount'   => $a['discount'],
                'count'      => $a['count'],
                // Gasto promedio por compra en el período. Se calcula acá y no
                // en el front para que el export XLSX lleve el mismo número
                // que la pantalla.
                'avgTicket'  => $a['count'] > 0 ? $a['grossTotal'] / $a['count'] : 0.0,
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Dashboard
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * KPIs de comportamiento de la cartera en el período.
     *
     * DEFINICIONES (explícitas porque cada una admite variantes y el número
     * cambia según cuál se elija):
     *
     *  - Cliente ACTIVO: compró al menos una vez dentro del período.
     *  - Cliente NUEVO: su PRIMERA venta histórica cae dentro del período.
     *    "Histórica" está scopeada igual que el resto del reporte, así que en
     *    vista de una sucursal significa "primera compra en ESA sucursal".
     *  - Cliente RECURRENTE: activo cuya primera venta es anterior al período.
     *  - Tasa de RETORNO: activos con 2+ compras DENTRO del período / activos.
     *    Mide si el que vino, volvió; no depende del período anterior.
     *  - Tasa de RETENCIÓN: activos del período anterior que también compraron
     *    en este / activos del período anterior.
     *  - Tasa de PÉRDIDA (churn): el complemento de la retención — activos del
     *    período anterior que NO volvieron.
     *  - Tasa de CRECIMIENTO: variación de la cantidad de activos contra el
     *    período anterior.
     *  - INTERVALO entre compras: promedio, sobre los clientes con 2+ compras
     *    en el período, de (días entre su primera y última compra) / (compras
     *    - 1). Los de una sola compra no aportan intervalo — incluirlos como 0
     *    hundiría el promedio.
     *
     * El período anterior es uno de la MISMA duración, inmediatamente previo.
     * Si no tuvo ningún cliente activo, retención / pérdida / crecimiento
     * vuelven como `null` — NO como 0%. Un 0% dice "los perdiste a todos"; la
     * verdad es "no hay con qué comparar", y son cosas distintas.
     *
     * Costo: la CTE `historia` recorre el historial del tenant restringido a
     * los clientes activos del período. Es el precio de saber quién es nuevo
     * sin una tabla de rollup; si el reporte se vuelve lento, ese es el lugar
     * a atacar (context/18 — rollups pre-agregados).
     */
    public function dashboard(string $from, string $to, string $companyId, string $outletId): array
    {
        $rocT = Roc::build($companyId, $outletId, 't');
        $rocH = Roc::build($companyId, $outletId, 'h');
        $nvT  = SaleFilters::notVoidedSql('t');
        $nvH  = SaleFilters::notVoidedSql('h');
        $tipo = self::VENTA_TYPES_SQL;

        // ── 1. Actividad por cliente en el período + su primera compra ──────
        $activos = $this->fetchAll(
            "WITH periodo AS (
                 SELECT t.customerId            AS cid,
                        COUNT(t.transactionId)  AS cnt,
                        COALESCE(SUM(t.transactionTotal), 0) AS total,
                        MIN(t.transactionDate)  AS first_in,
                        MAX(t.transactionDate)  AS last_in
                   FROM transaction t
                  WHERE t.transactionType IN $tipo
                    AND $nvT
                    AND t.transactionDate BETWEEN ? AND ?
                    AND t.customerId IS NOT NULL$rocT
                  GROUP BY t.customerId
             ), historia AS (
                 SELECT h.customerId AS cid, MIN(h.transactionDate) AS first_ever
                   FROM transaction h
                  WHERE h.transactionType IN $tipo
                    AND $nvH
                    AND h.customerId IS NOT NULL$rocH
                    AND h.customerId IN (SELECT cid FROM periodo)
                  GROUP BY h.customerId
             )
             SELECT p.cid, p.cnt, p.total, p.first_in, p.last_in, h.first_ever
               FROM periodo p
               JOIN historia h ON h.cid = p.cid",
            [$from, $to]
        );

        $fromTs = strtotime($from);
        $toTs   = strtotime($to);

        $totalActivos   = 0;
        $totalNuevos    = 0;
        $totalCompras   = 0;
        $totalFacturado = 0.0;
        $conDosOMas     = 0;
        $intervalos     = [];
        // Clientes nuevos por día: la fecha de su primera compra histórica.
        // Sirve para partir la serie diaria sin repetir la CTE `historia`.
        $nuevosPorDia   = [];

        foreach ($activos as $r) {
            $cnt   = (int)   $r['cnt'];
            $total = (float) $r['total'];
            $totalActivos++;
            $totalCompras   += $cnt;
            $totalFacturado += $total;

            $firstEver = $r['first_ever'] ?? null;
            $feTs      = $firstEver ? strtotime((string) $firstEver) : null;
            if ($feTs !== null && $feTs >= $fromTs && $feTs <= $toTs) {
                $totalNuevos++;
                $dia = date('Y-m-d', $feTs);
                $nuevosPorDia[$dia] = ($nuevosPorDia[$dia] ?? 0) + 1;
            }

            if ($cnt >= 2) {
                $conDosOMas++;
                $spanDias = (strtotime((string) $r['last_in']) - strtotime((string) $r['first_in'])) / 86400;
                if ($spanDias > 0) {
                    $intervalos[] = $spanDias / ($cnt - 1);
                }
            }
        }

        // ── 2. Serie diaria: clientes distintos por día ─────────────────────
        // Los nuevos salen del paso 1 (un cliente es nuevo exactamente el día
        // de su primera compra); el resto de los que compraron ese día son
        // recurrentes por definición.
        $porDia = $this->fetchAll(
            "SELECT to_char(date_trunc('day', t.transactionDate), 'YYYY-MM-DD') AS day_key,
                    COUNT(DISTINCT t.customerId)         AS clientes,
                    COALESCE(SUM(t.transactionTotal), 0) AS total
               FROM transaction t
              WHERE t.transactionType IN $tipo
                AND $nvT
                AND t.transactionDate BETWEEN ? AND ?
                AND t.customerId IS NOT NULL$rocT
              GROUP BY day_key
              ORDER BY day_key ASC",
            [$from, $to]
        );

        $serie = [];
        foreach ($porDia as $d) {
            $dia      = (string) $d['day_key'];
            $clientes = (int) $d['clientes'];
            $nuevos   = min($nuevosPorDia[$dia] ?? 0, $clientes);
            $serie[]  = [
                'date'        => $dia,
                'nuevos'      => $nuevos,
                'recurrentes' => $clientes - $nuevos,
                'total'       => (float) $d['total'],
            ];
        }

        // ── 3. Período anterior (misma duración, inmediatamente previo) ─────
        [$prevFrom, $prevTo] = self::previousWindow($from, $to);

        $comp = $this->fetchOne(
            "SELECT COUNT(*) FILTER (WHERE en_previo)               AS previos,
                    COUNT(*) FILTER (WHERE en_previo AND en_actual) AS retenidos
               FROM (
                     SELECT t.customerId,
                            bool_or(t.transactionDate BETWEEN ? AND ?) AS en_previo,
                            bool_or(t.transactionDate BETWEEN ? AND ?) AS en_actual
                       FROM transaction t
                      WHERE t.transactionType IN $tipo
                        AND $nvT
                        AND t.customerId IS NOT NULL$rocT
                        AND t.transactionDate BETWEEN ? AND ?
                      GROUP BY t.customerId
                    ) x",
            [$prevFrom, $prevTo, $from, $to, $prevFrom, $to]
        );

        $previos   = (int) ($comp['previos']   ?? 0);
        $retenidos = (int) ($comp['retenidos'] ?? 0);

        // Sin base de comparación no hay tasa: null, nunca 0 (ver docblock).
        $tasaRetencion   = $previos > 0 ? ($retenidos / $previos) * 100 : null;
        $tasaPerdida     = $tasaRetencion !== null ? 100 - $tasaRetencion : null;
        $tasaCrecimiento = $previos > 0 ? (($totalActivos - $previos) / $previos) * 100 : null;
        $tasaRetorno     = $totalActivos > 0 ? ($conDosOMas / $totalActivos) * 100 : null;

        return [
            'periodo' => [
                'from'         => $from,
                'to'           => $to,
                'prevFrom'     => $prevFrom,
                'prevTo'       => $prevTo,
                'prevActivos'  => $previos,
                'retenidos'    => $retenidos,
            ],
            'totales' => [
                'activos'     => $totalActivos,
                'nuevos'      => $totalNuevos,
                'recurrentes' => $totalActivos - $totalNuevos,
                'compras'     => $totalCompras,
                'facturado'   => $totalFacturado,
            ],
            'tasas' => [
                'retorno'     => $tasaRetorno,
                'retencion'   => $tasaRetencion,
                'crecimiento' => $tasaCrecimiento,
                'perdida'     => $tasaPerdida,
            ],
            'comportamiento' => [
                'promedioPorCliente' => $totalActivos > 0 ? $totalFacturado / $totalActivos : null,
                'frecuenciaCompra'   => $totalActivos > 0 ? $totalCompras / $totalActivos : null,
                'intervaloDias'      => $intervalos ? array_sum($intervalos) / count($intervalos) : null,
                // Cuántos clientes sostienen el intervalo — sin esto no se
                // puede juzgar si el promedio significa algo.
                'intervaloBase'      => count($intervalos),
            ],
            'serie' => $serie,
        ];
    }

    /**
     * Ventana anterior de la MISMA duración, terminando un segundo antes del
     * inicio de la actual. Trabaja en la zona horaria del proceso, igual que
     * el resto de los reportes (los timestamps de negocio se guardan naive).
     *
     * @return array{0:string,1:string} [prevFrom, prevTo] en 'Y-m-d H:i:s'.
     */
    private static function previousWindow(string $from, string $to): array
    {
        $fromTs = strtotime($from);
        $toTs   = strtotime($to);
        // Duración mínima de 1 día: un rango de un solo día se compara contra
        // el día anterior, no contra una ventana de 0 segundos.
        $span   = max($toTs - $fromTs, 86400 - 1);
        $prevTo = $fromTs - 1;
        return [
            date('Y-m-d H:i:s', $prevTo - $span),
            date('Y-m-d H:i:s', $prevTo),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Geografía
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dónde residen los clientes del comercio: ranking de localidades y
     * ciudades + puntos para el mapa de calor.
     *
     * ALCANCE — deliberadamente NO depende del rango de fechas. "Dónde viven
     * mis clientes" es una propiedad del padrón, no del período: atarlo al
     * rango haría que el mapa de un rango de 7 días (el default del reporte)
     * dibujara la zona de los que compraron esta semana y la presentara como
     * la zona de la clientela. El front lo dice explícito en la pantalla.
     *
     * FUENTE de cada dato, en orden de preferencia:
     *   - dirección default activa del cliente (`customerAddress`), que es la
     *     que el POS usa para el delivery y por eso la más cuidada;
     *   - los campos sueltos del contacto (`contactLocation` / `contactCity` /
     *     `contactLatLng`), que viven en el JSONB `data` desde las migs 06/25.
     *
     * COBERTURA: se devuelve siempre, y es lo primero que el front tiene que
     * mostrar. Las coordenadas solo existen si alguien las cargó; un mapa de
     * calor hecho con el 10% del padrón presentado como "dónde viven tus
     * clientes" es una conclusión falsa sobre la que el dueño decide repartos.
     */
    public function geography(string $companyId): array
    {
        // `c.data` se proyecta entero a propósito: el wrapper lo aplana sobre
        // la fila, así que de ahí salen contactLocation / contactCity /
        // contactLatLng, que ya no son columnas.
        $rows = ncmRows(
            "SELECT c.contactId AS cid,
                    c.data,
                    a.customerAddressLocation AS addr_location,
                    a.customerAddressCity     AS addr_city,
                    a.customerAddressLat      AS addr_lat,
                    a.customerAddressLng      AS addr_lng
               FROM contact c
               LEFT JOIN LATERAL (
                    SELECT ca.customerAddressLocation, ca.customerAddressCity,
                           ca.customerAddressLat, ca.customerAddressLng
                      FROM customerAddress ca
                     WHERE ca.customerId = c.contactId
                       AND ca.companyId  = c.companyId
                       AND ca.status     = 1
                     ORDER BY ca.customerAddressDefault DESC NULLS LAST,
                              ca.updated_at DESC NULLS LAST
                     LIMIT 1
               ) a ON TRUE
              WHERE c.companyId = ?
                AND c.type = 1
                AND c.contactStatus = 1",
            [$companyId]
        );

        $totalClientes = 0;
        $conLocalidad  = 0;
        $conCiudad     = 0;
        $conCoords     = 0;

        /** @var array<string,array{label:string,clientes:int,variantes:array<string,int>}> */
        $localidades = [];
        $ciudades    = [];
        /** @var array<string,array{lat:float,lng:float,peso:int}> */
        $puntos = [];

        foreach ($rows as $r) {
            $totalClientes++;

            $loc = self::firstNonEmpty([$r['addr_location'] ?? null, $r['contactLocation'] ?? null]);
            $ciu = self::firstNonEmpty([$r['addr_city'] ?? null, $r['contactCity'] ?? null]);

            if ($loc !== null) { $conLocalidad++; self::tally($localidades, $loc); }
            if ($ciu !== null) { $conCiudad++;    self::tally($ciudades, $ciu); }

            $coords = self::resolveCoords($r);
            if ($coords !== null) {
                $conCoords++;
                // Agrupamos por coordenada redondeada a 5 decimales (~1 m):
                // dos clientes del mismo edificio son un punto de peso 2, no
                // dos puntos superpuestos que el heatmap pinta igual pero que
                // engordan el payload.
                $key = number_format($coords[0], 5, '.', '') . ',' . number_format($coords[1], 5, '.', '');
                if (isset($puntos[$key])) {
                    $puntos[$key]['peso']++;
                } else {
                    $puntos[$key] = ['lat' => $coords[0], 'lng' => $coords[1], 'peso' => 1];
                }
            }
        }

        // Los más pesados primero: si hay que recortar, se recortan los puntos
        // sueltos, no los núcleos de densidad que dan la forma de la mancha.
        $puntosOut = array_values($puntos);
        usort($puntosOut, fn($a, $b) => $b['peso'] <=> $a['peso']);
        $puntosTruncados = count($puntosOut) > self::GEO_POINTS_LIMIT;
        if ($puntosTruncados) {
            $puntosOut = array_slice($puntosOut, 0, self::GEO_POINTS_LIMIT);
        }

        return [
            'cobertura' => [
                'clientes'       => $totalClientes,
                'conCoordenadas' => $conCoords,
                'conLocalidad'   => $conLocalidad,
                'conCiudad'      => $conCiudad,
            ],
            'localidades'     => self::rankTally($localidades),
            'ciudades'        => self::rankTally($ciudades),
            'puntos'          => $puntosOut,
            'puntosTruncados' => $puntosTruncados,
        ];
    }

    /** Primer valor no vacío de la lista, ya trimeado. `null` si no hay ninguno. */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($c === null) continue;
            $s = trim((string) $c);
            if ($s !== '') return $s;
        }
        return null;
    }

    /**
     * Suma un cliente al grupo de su localidad/ciudad, agrupando por la forma
     * NORMALIZADA y recordando cada variante literal que apareció.
     *
     * @param array<string,array{clientes:int,variantes:array<string,int>}> $bucket
     */
    private static function tally(array &$bucket, string $raw): void
    {
        $key = self::normalizeLocality($raw);
        if ($key === '') return;
        if (!isset($bucket[$key])) {
            $bucket[$key] = ['clientes' => 0, 'variantes' => []];
        }
        $bucket[$key]['clientes']++;
        $variante = preg_replace('/\s+/u', ' ', trim($raw)) ?? $raw;
        $bucket[$key]['variantes'][$variante] = ($bucket[$key]['variantes'][$variante] ?? 0) + 1;
    }

    /**
     * Ordena un bucket por cantidad de clientes desc y elige la etiqueta de
     * display: la variante literal MÁS FRECUENTE, para no inventar una grafía
     * que nadie escribió.
     *
     * `variantes` viaja al front como número: es la señal de que el dato está
     * sucio ("Asunción" agrupó 3 escrituras distintas), y el dueño merece
     * verla en vez de creer que su padrón está prolijo.
     */
    private static function rankTally(array $bucket): array
    {
        $out = [];
        foreach ($bucket as $key => $g) {
            arsort($g['variantes']);
            $topVariante = (string) array_key_first($g['variantes']);
            $out[] = [
                'key'       => $key,
                'label'     => self::prettyLabel($topVariante),
                'clientes'  => $g['clientes'],
                'variantes' => count($g['variantes']),
            ];
        }
        usort($out, fn($a, $b) => $b['clientes'] <=> $a['clientes'] ?: strcmp($a['label'], $b['label']));
        return array_slice($out, 0, self::GEO_LIST_LIMIT);
    }

    /**
     * Clave de agrupación de una localidad/ciudad.
     *
     * `contactCity` / `customerAddressCity` / `location` son TEXTO LIBRE: no
     * hay catálogo detrás. En el padrón real conviven "ASUNCION", "Asunción",
     * "asuncion " y "Asuncion." como si fueran cuatro ciudades, y el ranking
     * sale fragmentado. Normalizamos a: minúsculas, sin acentos, espacios
     * colapsados y sin puntuación de borde.
     *
     * Lo que NO hace, a propósito: fuzzy matching, sinónimos o corrección
     * ortográfica. "Asunción" y "Asunsión" siguen siendo dos grupos — agrupar
     * por parecido inventaría datos que nadie cargó. La solución de fondo es
     * un catálogo de localidades, no un matcher más agresivo acá.
     */
    private static function normalizeLocality(string $raw): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($s === '') return '';
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        return trim($s, " .,-_\t\n\r\0\x0B");
    }

    /**
     * Etiqueta de display de una localidad. Solo se re-capitaliza cuando el
     * dato viene TODO en mayúsculas (patrón típico de los imports legacy:
     * "ASUNCION"); si el usuario lo tipeó con mayúsculas y minúsculas, se
     * respeta tal cual — él sabe cómo se escribe su barrio.
     */
    private static function prettyLabel(string $raw): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($s === '') return $s;
        if (mb_strtoupper($s, 'UTF-8') === $s) {
            return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        return $s;
    }

    /**
     * Coordenadas del cliente: primero las de la dirección default
     * (`customerAddressLat/Lng`, columnas reales), después el `contactLatLng`
     * del JSONB, que ContactService guarda como el string "lat,lng"
     * (ContactService.php:354).
     *
     * @return array{0:float,1:float}|null
     */
    private static function resolveCoords($row): ?array
    {
        $lat = $row['addr_lat'] ?? null;
        $lng = $row['addr_lng'] ?? null;
        if ($lat !== null && $lng !== null && self::validCoords((float) $lat, (float) $lng)) {
            return [(float) $lat, (float) $lng];
        }
        return self::parseLatLng($row['contactLatLng'] ?? null);
    }

    /** @return array{0:float,1:float}|null */
    private static function parseLatLng($raw): ?array
    {
        if ($raw === null) return null;
        $parts = explode(',', (string) $raw);
        if (count($parts) !== 2) return null;
        $lat = filter_var(trim($parts[0]), FILTER_VALIDATE_FLOAT);
        $lng = filter_var(trim($parts[1]), FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false) return null;
        return self::validCoords($lat, $lng) ? [$lat, $lng] : null;
    }

    /**
     * Descarta coordenadas imposibles y el "null island" (0,0), que en la
     * práctica es un formulario enviado sin tocar el mapa — nunca un
     * domicilio. Un solo punto en (0,0) desplaza el encuadre del heatmap al
     * Golfo de Guinea y arruina la vista entera.
     */
    private static function validCoords(float $lat, float $lng): bool
    {
        if (!is_finite($lat) || !is_finite($lng)) return false;
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) return false;
        return abs($lat) <= 90 && abs($lng) <= 180;
    }

    // ── Helpers de query ────────────────────────────────────────────────────

    /** @return array<int,\CaseInsensitiveArray> */
    private function fetchAll(string $sql, array $params): array
    {
        return ncmRows($sql, $params);
    }

    /** @return array<string,mixed> Row plano o []. */
    private function fetchOne(string $sql, array $params): array
    {
        $rows = ncmRows($sql, $params);
        if (!$rows) return [];
        $out = [];
        foreach ($rows[0] as $k => $v) { $out[(string) $k] = $v; }
        return $out;
    }

    /** Lookup batch contactId → fila de contacto, scopeado por companyId, con bound params. */
    private function contactsByIds(array $ids, $companyId)
    {
        if (!$ids || $companyId === '') {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT * FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];

        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = $c;
        }
        return $map;
    }

    /**
     * Normaliza el STRING_AGG de `meta->>'tags'` (cada valor puede ser un JSON array string
     * `["a","b"]` o texto plano) a un array de etiquetas únicas. Las labels las arma el front.
     */
    private function parseTags($raw)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return [];
        }
        $clean = str_replace(['[', ']', '"', '\\'], '', $raw);
        $parts = array_map('trim', explode(',', $clean));
        $parts = array_filter($parts, fn($t) => $t !== '');
        return array_values(array_unique($parts));
    }
}
