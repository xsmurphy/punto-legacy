<?php
include_once('../tp_head.php');

$data       = explodes(',', base64_decode(validateHttp('s')));
$COMPANY_ID = dec($data[0]);
$OUTLET_ID  = dec($data[1]);
$DATE       = $data[2];
$FROM       = date('Y-m-d', strtotime($DATE)) . ' 00:00:00';
$TO         = date('Y-m-d', strtotime($DATE)) . ' 23:59:59';
$ENDPOINT   = 'https://sistema.mariscal.com.py/api/contrato/ventas';
$METHOD     = 'POST';
$ID         = $data[3];
$setting    = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[$COMPANY_ID]);
$modules    = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[$COMPANY_ID]);
$mcalArr    = json_decode($modules['moduleData'], true);
$send       = [];
$replaceChars = [',','.',';','|',' '];
$result     = ['error' => 'Modulo inactivo'];

if(validInArray($mcalArr, 'mcal')){

  if(validInArray($mcalArr['mcal'], 'outlets')){
    if(!in_array($data[1], $mcalArr['mcal']['outlets'])){
      error_log('Sucursal inhabilitada');
      jsonDieMsg('Sucursal inhabilitada', 404, 'error');  
    }
  }else{
    error_log('Debe seleccionar una sucursal');
    jsonDieMsg('Debe seleccionar una sucursal', 500, 'error');
  }

  $CONTRACT = $mcalArr['mcal']['contract'];
  $API_KEY  = $mcalArr['mcal']['apiKey'];

  error_log('Contract: ' . $CONTRACT . ', API key: ' . $API_KEY);

  if(!$CONTRACT || !$API_KEY){
    jsonDieMsg('Debe proveer nro. de contrato y llave', 500, 'error');
  }

  $salesArr = [];

  if(validity($ID)){    
    $sales    = ncmExecute('SELECT * FROM transaction WHERE companyId = ? AND transactionId = ? AND transactionType IN(?) LIMIT 1', [$COMPANY_ID, dec($ID), '0,3,6,7'], false, true);
  }else{
    $sales    = ncmExecute('SELECT * FROM transaction WHERE companyId = ? AND outletId = ? AND transactionType IN(?) AND transactionDate BETWEEN ? AND ? LIMIT 10000', [$COMPANY_ID, $OUTLET_ID, '0,3,6,7', $FROM, $TO], false, true);

    $FROM = date('d-m-Y', strtotime($FROM));
  }

  if($sales){

    while (!$sales->EOF) {
      $fields = $sales->fields;
      
      $cName  = 'SIN NOMBRE';
      $cTIN   = '0000000-0';
      $type   = 'FACT';
      $dDate  = date('d-m-Y', strtotime($fields['transactionDate']));
      $taxTen = 0.00;
      $taxFiv = 0.00;
      $exent  = 0.00;

      $register   = ncmExecute('SELECT registerInvoicePrefix FROM register WHERE registerId = ? LIMIT 1', [$fields['registerId']], true);

      if($fields['transactionType'] == 6 ){
        $type                   = 'NCR';
      }

      if($fields['transactionType'] == 7 ){
        $type                   = 'ANCR';
        $salesArr[]['anulado']  = $dDate;
      }

      if($fields['customerId']){
        $customer = ncmExecute('SELECT contactName, contactTIN FROM contact WHERE contactId = ? LIMIT 1', [$fields['customerId']], true);
        if($customer){
          $cName  = $customer['contactName'];
          $cTIN   = $customer['contactTIN'];
        }
      }

      if($fields['transactionTax']){

        $tax      = ncmExecute('SELECT toTaxObjText FROM toTaxObj WHERE transactionId = ? LIMIT 1', [$fields['transactionId']], true);

        if($tax){

          $taxes  = json_decode($tax['toTaxObjText'], true);

          foreach ($taxes as $key => $taxName) {
            $tVal   = str_replace(',', '', $taxName['val']);
            $tVal   = floatval(round($tVal));
            //$tVal   = number_format($tVal, 2, '.', '');

            if($taxName['name'] == '10'){
              $taxTen = $tVal;
            }else if($taxName['name'] == '5'){
              $taxFiv = $tVal;
            }else if($taxName['name'] == '0'){
              $exent  = $tVal;
            }

          }

        }

      }
      $total = 0;
      $tax10 = 0;
      $tax5 = 0;
      $tax0 = 0;
      if(!empty($fields['transactionDetails'])){
        foreach(json_decode($fields['transactionDetails'],true) as $detail){
          $total += $detail['total'];
          switch($detail['tax']){
            case 10:
              $tax10 += $detail['total'];
              break;
            case 5:
              $tax5 += $detail['total'];
              break;
            case 0:
              $tax0 += $detail['total'];
              break;
          }
        }
      }

      // 'gravadas10'    => $taxTen,
      // 'gravadas5'     => $taxFiv,
      // 'exentas'       => $exent,

      $salesArr[]   = [
                        'comprobante'   => $register['registerInvoicePrefix'] . leadingZeros($fields['invoiceNo'], 7),
                        'fecha'         => $dDate,
                        'tipo'          => $type,
                        'moneda'        => strtoupper( str_replace($replaceChars, '', $setting['settingCurrency'] ) ),
                        'tipoCambio'    => 0,
                        'gravadas10'    => $tax10,
                        'gravadas5'     => $tax5,
                        'exentas'       => $tax0,
                        'total'         => $total,
                        'cliente'       => $cName,
                        'ruc'           => $cTIN
                      ];

      $sales->MoveNext(); 
    }
    $sales->Close();
  }

  if(!validity($salesArr)){
    return false;
  }

  if(validity($ID)){
    $FROM = $dDate;
  }

  $send   =   [
                'contrato'  => $CONTRACT,
                'fecha'     => $FROM,
                'ventas'    => $salesArr
              ];

  $header =   [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $API_KEY
              ];
              
  error_log(json_encode($send));
  $result = curlContents($ENDPOINT, $METHOD, json_encode($send), $header);

}

error_log($result);

jsonDieResult($result);
?>