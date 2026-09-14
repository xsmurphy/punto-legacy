<?php
declare(strict_types=1);
require_once __DIR__ . '/_harness.php';

$company = 'a77a0000-0000-4000-8000-000000000001';
$a = 'a77a0000-0000-4000-8000-000000000002';
$b = 'a77a0000-0000-4000-8000-000000000003';
$user = 'a77a0000-0000-4000-8000-000000000004';
$other = 'a77a0000-0000-4000-8000-000000000005';
$c = 'a77a0000-0000-4000-8000-000000000006';
define('COMPANY_ID', $company);
define('OUTLET_ID', $a);
define('USER_ID', $user);
require_once dirname(__DIR__) . '/bootstrap.php';
use Punto\Api\Reports\SummaryYearService;
use Punto\Api\Reports\NonAddingSales;
use Punto\Api\Reports\Roc;
use Punto\Api\Support\TenantClock;

$checks = $failures = 0;
function verifyAnnual(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; }
    echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
}
function annualTx(string $company, string $outlet, int $type, string $date, float $total,
    float $discount = 0, int $status = 1, bool $voided = false, string $payments = '[]', bool $internal = false): string {
    global $db, $user;
    $id = $db->GetOne("INSERT INTO transaction (companyid,outletid,userid,transactiontype,
        transactiondate,transactiontotal,transactiondiscount,transactionstatus,voidedat,
        transactionpaymenttype,meta,transactionunitssold,transactiontax)
        VALUES (?,?,?,?,?::timestamptz,?,?,?,?::timestamptz,?,?::jsonb,1,0) RETURNING transactionid",
        [$company,$outlet,$user,$type,$date,$total,$discount,$status,$voided ? $date : null,
            $payments,json_encode(['tags' => json_encode($internal ? ['166227'] : [])])]);
    return (string) $id;
}
$db->Execute('BEGIN');
try {
    foreach ([$company, $other] as $cid) {
        $db->Execute("INSERT INTO company(companyid,status,plan,balance,isparent,createdat,config)
            VALUES (?,'active',1,0,false,'2026-01-01',?::jsonb)",
            [$cid,json_encode(['settingName'=>'Annual test','settingTimeZone'=>'America/Asuncion'])]);
    }
    foreach ([[$a,$company],[$b,$company],[$c,$other]] as [$oid,$cid]) {
        $db->Execute("INSERT INTO outlet(outletid,companyid,outletname,outletstatus) VALUES (?,?,'Annual',1)",[$oid,$cid]);
    }
    $db->Execute("INSERT INTO contact(contactid,companyid,outletid,contactname,type) VALUES (?,?,?,'Annual user',0)",[$user,$company,$a]);
    TenantClock::apply($company);
    $_fullSettings = ['ignoreInternal'=>true];
    // Historical document imports deliberately have NO items, payments or finance ledger.
    annualTx($company,$a,0,'2020-01-10 12:00:00',100,10);
    annualTx($company,$a,3,'2020-01-11 12:00:00',50,5);
    annualTx($company,$a,6,'2020-01-12 12:00:00',-20);
    annualTx($company,$a,0,'2020-01-13 12:00:00',900,0,1,true);
    annualTx($company,$a,7,'2020-01-14 12:00:00',900);
    annualTx($company,$a,6,'2020-01-15 12:00:00',-900,0,6);
    annualTx($company,$a,6,'2020-01-16 12:00:00',-900,0,1,true);
    // Purchase-only month: paid/credit documents count once, payment does not.
    annualTx($company,$a,1,'2020-02-01 12:00:00',30,3);
    annualTx($company,$a,4,'2020-02-02 12:00:00',40);
    annualTx($company,$a,5,'2020-02-03 12:00:00',40);
    annualTx($company,$a,1,'2020-02-04 12:00:00',999,0,6);
    annualTx($company,$a,4,'2020-02-05 12:00:00',999,0,1,true);
    annualTx($company,$a,6,'2020-03-01 12:00:00',-7);
    annualTx($company,$a,0,'2020-04-01 12:00:00',20,2,1,false,'[]',true);
    annualTx($company,$a,0,'2020-04-02 12:00:00',15,0,1,false,'[{"type":"giftcard","total":15}]');
    annualTx($company,$a,0,'2021-01-01 02:59:59.999999+00',9); // Dec 31 local, subsecond inclusive
    annualTx($company,$a,0,'2021-01-01 03:00:00+00',11); // next year local
    annualTx($company,$b,0,'2020-01-10 12:00:00',500);
    annualTx($company,$b,1,'2018-01-01 12:00:00',500);
    annualTx($other,$c,0,'2017-01-01 12:00:00',999);
    annualTx($other,$c,0,'2020-01-01 12:00:00',999);
    annualTx($company,$a,7,'2016-01-01 12:00:00',999);
    foreach ([null,1] as $kind) {
        $db->Execute("INSERT INTO expenses(companyid,outletid,expensesamount,expensesdate,type)
            VALUES (?,?,800,'2020-02-03',?)",[$company,$a,$kind]);
    }
    $svc = new SummaryYearService();
    $roc = Roc::build($company,$a);
    $live = $svc->yearlyLive(2020,$roc,$company);
    verifyAnnual('12 ordered months',array_column($live['months'],'month') === range(1,12));
    $jan = $live['months'][0];
    verifyAnnual('stored gross subtotal and discount preserved; sales count excludes returns/voids',
        $jan['salesTotal'] === 150.0 && $jan['discount'] === 15.0 && $jan['count'] === 2);
    verifyAnnual('only valid returns, positive magnitude', $jan['returnsTotal'] === 20.0);
    verifyAnnual('presentation income remains subtotal-discount-returns',
        $jan['salesTotal']-$jan['discount']-$jan['returnsTotal'] === 115.0);
    verifyAnnual('purchase-only month excludes payments, voids and cash movements', $live['months'][1]['expensesTotal'] === 70.0);
    verifyAnnual('return-only month', $live['months'][2]['returnsTotal'] === 7.0);
    verifyAnnual('empty month is numeric zero', array_sum($live['months'][4]) === 5.0);
    verifyAnnual('tenant-local subsecond year boundary', $live['months'][11]['salesTotal'] === 9.0);
    verifyAnnual('scoped historical years ignore company creation and other outlets/tenants',
        $live['years'] === [$live['currentYear'],2021,2020]);
    verifyAnnual('currentYear from tenant clock',$live['currentYear'] === (int) substr(TenantClock::now($company),0,4));
    $all = $svc->yearlyLive(2020,Roc::build($company),$company);
    verifyAnnual('consolidated tenant includes second outlet but no foreign tenant', $all['months'][0]['salesTotal'] === 650.0 && in_array(2018,$all['years'],true) && !in_array(2017,$all['years'],true));
    // Regenerate REAL rollup; cancelled purchases contaminate legacy expense rollup on purpose.
    foreach (['2020-01-10','2020-01-11','2020-01-12','2020-01-13','2020-01-14','2020-01-15','2020-01-16','2020-03-01','2020-04-01','2020-04-02','2020-12-31'] as $day) {
        $db->Execute("SELECT rollup_recompute_period(?::uuid,'sales',?::date)",[$company,$day]);
    }
    $db->Execute("SELECT rollup_recompute_period(?::uuid,'expenses','2020-02-01'::date)",[$company]);
    $rolled = $svc->yearly(2020,$roc,$company,[$a],true);
    verifyAnnual('entire live/rollup response parity', $rolled === $live);
    verifyAnnual('consolidated live/rollup parity', $svc->yearly(2020,Roc::build($company),$company,[],true) === $all);
    $nonAdding = new NonAddingSales();
    $old = $nonAdding->compute('2020-04-01 00:00:00','2020-04-30 23:59:59',$roc);
    verifyAnnual('batched legacy nonAdding parity (internal discount + giftcard)',
        $live['months'][3]['nonAddingTotal'] === (float)$old['total'] && (float)$old['total'] === 33.0);
    foreach ([1900,9999,2035] as $year) {
        foreach ([false,true] as $rollup) {
            $empty = $svc->yearly($year,$roc,$company,[$a],$rollup);
            verifyAnnual("empty year $year rollup=".(int)$rollup,
                count($empty['months']) === 12 && array_sum(array_column($empty['months'],'salesTotal')) === 0.0);
        }
    }
    foreach (['0000','1899','10000','2020x','2020\n',[],true,2020.5,' 2020','2e03'] as $invalid) {
        try { SummaryYearService::parseYear($invalid,2026); verifyAnnual('invalid year rejected',false); }
        catch (InvalidArgumentException) { verifyAnnual('invalid year rejected',true); }
    }
    verifyAnnual('missing year defaults',SummaryYearService::parseYear(null,2026) === 2026);
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL exception: '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
} finally {
    $db->Execute('ROLLBACK');
}
harnessFinish($failures,$checks);
