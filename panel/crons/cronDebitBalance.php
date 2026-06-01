<?php
include_once './cronHead.php';

/**
 * cronDebitBalance — DESHABILITADO.
 *
 * Cron histórico que debitaba el plan a empresas con créditos vencidos
 * (degradar trial → free, anular venta a crédito, etc.). El código original
 * llevaba años sin ejecutarse: tenía un `die()` al tope precedido por un
 * `mail()` a drahgster@gmail.com (debug personal), y el body real estaba
 * mayormente comentado y dependía de tablas/flujos viejos (planExpired,
 * INCOME_USER_ID/OUTLET_ID/REGISTER_ID/COMPANY_ID, getAllPayingCompaniesData).
 *
 * Si necesitás reactivar este flujo, ver el commit que lo deshabilitó para
 * recuperar la lógica histórica (git log -- panel/crons/cronDebitBalance.php).
 *
 * Mientras tanto: si el crontab sigue invocándolo (no se encontró referencia
 * al registrar el fix en P0.4), el error_log captura la invocación para
 * detectarlo. Si después de un mes no aparecen logs, este archivo se puede
 * borrar definitivamente.
 */
error_log('[cronDebitBalance] invocado (deshabilitado) — argv=' . json_encode($_GET));
die('cronDebitBalance: deshabilitado');
