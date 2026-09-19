<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * "Requiere atención" — los pendientes del comercio que alguien tiene que
 * resolver, para la columna lateral del dashboard (widget `attention`).
 *
 * ── La regla que manda (owner, cerrada) ────────────────────────────────────
 *
 * "Hay que mostrar el bloque si hay info y no llenar el dashboard con bloques
 * vacíos." Punto es multi-rubro: hay comercios sin stock, sin crédito, sin FE.
 * Por eso esta respuesta trae SOLO las filas con algo que resolver — conteo o
 * monto > 0 — y nunca un cero. Sin filas, el front no pinta la card (silencio,
 * no "todo en orden").
 *
 * ── Qué NO calcula esta clase ──────────────────────────────────────────────
 *
 * Ninguna cuenta. Cada fila le pregunta a su dueño, con la MISMA definición
 * que usa la pantalla a la que linkea, para que el número del dashboard y el
 * de esa pantalla no puedan divergir:
 *
 *   einvoice     EInvoiceService::actionPendingCount()  → Ajustes › FE
 *   stock        StockService::alertCount()             → Artículos
 *   margin       MarginAlertService::belowTarget()      → Artículos
 *   receivables  OpenInvoicesService::overdueReceivable() → Cuentas por cobrar
 *   attendance   AttendanceService::pendingReviewCount() → Asistencia
 *
 * ── Permiso por fila ───────────────────────────────────────────────────────
 *
 * Cada fila exige la clave de la pantalla a la que lleva: quien no puede ver
 * las deudas no ve la fila de deudas, ni su monto. Una fila sin permiso NO se
 * calcula: no se consulta para después descartarla.
 *
 * ── Aislamiento de fallas ──────────────────────────────────────────────────
 *
 * Cada fila corre en su propio try/catch: si una consulta falla, esa fila se
 * omite y se loguea, y las demás salen igual. Un pendiente que no se pudo
 * calcular no rompe la card entera.
 */
final class AttentionService
{
    /** Clave de fila => [permiso requerido, destino]. El orden es el de pantalla. */
    public const ROWS = [
        'einvoice'    => ['einvoice.manage',      '/settings/facturacion-electronica'],
        'stock'       => ['inventory.item.view',  '/items'],
        'margin'      => ['inventory.item.view',  '/items'],
        'receivables' => ['reports.sales.view',   '/reports/open-invoices?tab=cobrar'],
        'attendance'  => ['hr.attendance.view',   '/reports/attendance?review=1'],
    ];

    /** @var array<string,callable(string,array):?array> */
    private array $sources;

    /**
     * @param array<string,callable(string,array):?array>|null $sources Solo para
     *        el arnés (forzar la falla de una fila). En producción, las reales.
     */
    public function __construct(?array $sources = null)
    {
        $this->sources = $sources ?? [
            'einvoice'    => $this->einvoice(...),
            'stock'       => $this->stock(...),
            'margin'      => $this->margin(...),
            'receivables' => $this->receivables(...),
            'attendance'  => $this->attendance(...),
        ];
    }

    /**
     * @param list<string>          $outletIds Alcance por sucursal (`[]` = todas).
     * @param callable(string):bool $can       ¿La persona tiene este permiso?
     * @return array{rows: list<array{key:string,count:int,amount:?float,href:string}>}
     */
    public function rows(string $companyId, array $outletIds, callable $can): array
    {
        $rows = [];
        foreach (self::ROWS as $key => [$perm, $href]) {
            if (!$can($perm) || !isset($this->sources[$key])) {
                continue;
            }
            try {
                $v = ($this->sources[$key])($companyId, $outletIds);
            } catch (\Throwable $e) {
                error_log("AttentionService: la fila '$key' falló y se omite — " . $e->getMessage());
                continue;
            }
            if ($v === null || (int) ($v['count'] ?? 0) <= 0) {
                continue;
            }
            $rows[] = [
                'key'    => $key,
                'count'  => (int) $v['count'],
                'amount' => isset($v['amount']) ? (float) $v['amount'] : null,
                'href'   => $href,
            ];
        }
        return ['rows' => $rows];
    }

    // ── Fuentes ────────────────────────────────────────────────────────────

    /** @return array{count:int}|null */
    private function einvoice(string $companyId, array $outletIds): ?array
    {
        $n = (new \Punto\Api\EInvoice\EInvoiceService())->actionPendingCount($companyId, $outletIds);
        return $n === null ? null : ['count' => $n];
    }

    /** @return array{count:int}|null */
    private function stock(string $companyId, array $outletIds): ?array
    {
        $r = (new StockService())->alertCount($companyId, $outletIds);
        return $r === null ? null : ['count' => $r['out'] + $r['low']];
    }

    /** @return array{count:int}|null */
    private function margin(string $companyId, array $outletIds): ?array
    {
        $r = (new \Punto\Api\Items\MarginAlertService())->belowTarget($companyId, $outletIds);
        return $r === null ? null : ['count' => $r['count']];
    }

    /** Conteo = clientes con deuda vencida; monto = total vencido. */
    private function receivables(string $companyId, array $outletIds): ?array
    {
        $r = (new OpenInvoicesService())->overdueReceivable($companyId, $outletIds);
        return $r['amount'] > 0 ? ['count' => $r['contacts'], 'amount' => $r['amount']] : null;
    }

    /** @return array{count:int} */
    private function attendance(string $companyId, array $outletIds): array
    {
        return ['count' => (new \Punto\Api\Hr\AttendanceService())->pendingReviewCount($companyId, $outletIds)];
    }
}
