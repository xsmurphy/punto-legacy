<?php
include_once('../tp_head.php');

$data = explodes(',', base64_decode(validateHttp('s')));

define("COMPANY_ID", dec( $db->Prepare( $data[0] ) ));
define("TRANSACTION_ID", dec( $db->Prepare( $data[1] ) ));

$setting    = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_modules   = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

if(!$_modules['tusfacturas']){
  header('Content-Type: application/json');
  echo json_encode(['error'=>'Modulo inhabilitado']);
  dai();
}

define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set($setting['settingTimeZone']);
define('TODAY', date('Y-m-d H:i:s'));

//transaction
$result         = ncmExecute("SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1",[TRANSACTION_ID,COMPANY_ID]);
$apiData        = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'tusFacturas' AND outletId = ? LIMIT 1",[$result['outletId']]);

define('TUSFACTURAS_APITOKEN', explodes(',', $apiData['taxonomyName'],false,0));
define('TUSFACTURAS_USERTOKEN', explodes(',', $apiData['taxonomyName'],false,1));
define('TUSFACTURAS_APIKEY', explodes(',', $apiData['taxonomyName'],false,2));
define('TUSFACTURAS_PTOVENTA', explodes(',', $apiData['taxonomyName'],false,3));

$allTotal       = roundIt($result['transactionTotal'],$setting['settingDecimal']);
$allDiscount    = roundIt($result['transactionDiscount'],$setting['settingDecimal']);

$customerData   = getCustomerData($result['customerId'], 'uid');
 
if($result){
  $items      = ncmExecute("SELECT * FROM itemSold WHERE transactionId = ?",[TRANSACTION_ID],false,true);
  $totalFinal = 0;

  if($items){
    $itemsInSale  = [];
    $tTotal       = 0;
    $tTax         = 0;
    $count        = 1;
    $nextDiscAdd  = 0;
    $totalPrevNoTax = 0;

    while (!$items->EOF) {
      $fields               = $items->fields;
      $itm                  = getItemData($fields['itemId']);

      if(!$fields['itemSoldParent'] && in_array($itm['itemType'],['combo','precombo']) ){
        continue;
      }

      $discount           = roundIt($fields['itemSoldDiscount'],$setting['settingDecimal']);
      $allDiscount        += $discount;//sumo toodos los descuentos en una var
      $count              ++;// $fields['itemSoldUnits'];

      $items->MoveNext();
    }

    $items->MoveFirst();

    $discountEach       = 0;//divider($allDiscount, $count);

    echo 'TOTAL DISCOUNT: ' . $allDiscount . ' DISCOUNT EACH: ' . $discountEach;

    while (!$items->EOF) {
      $fields               = $items->fields;
      $itm                  = getItemData($fields['itemId']);
      $itemDiscountPercent  = 0;
      $discountEach         = $discountEach + $nextDiscAdd;

      $price                = roundIt($fields['itemSoldTotal'],$setting['settingDecimal']) + $discountEach;
      $taxName              = getTaxonomyName($itm['taxId']);
      $units                = $fields['itemSoldUnits'];
      $priceTax             = divider($price, $units);
      $unitTax              = getTaxOfPrice($taxName, $priceTax);
      $priceNoTax           = $priceTax - $unitTax;

      if($_GET['test']){
        echo $itm['itemName'] . '<br>';
        echo 'Precio - discoutn - IVA :' . $priceNoTax . ' <br>';
        echo 'IVA                     :' . $unitTax . '<br>';
        echo '<br><br>';
      }

      $itemsInSale[] = [
                          "cantidad"                => $fields['itemSoldUnits'],
                          "afecta_stock"            => "S",
                          "bonificacion_porcentaje" => "0",
                          "producto"                =>  [
                                                          "descripcion"                   => $itm['itemName'],
                                                          "unidad_bulto"                  => $units,
                                                          "lista_precios"                 => "PRINCIPAL",
                                                          "codigo"                        => $itm['itemSKU'],
                                                          "precio_unitario_sin_iva"       => $priceNoTax,
                                                          "alicuota"                      => $taxName,
                                                          "impuestos_internos_alicuota"   => 0,
                                                          "unidad_medida"                 => $itm['itemUOM'],
                                                          "actualiza_precio"              => "S"
                                                        ],
                          "leyenda"                 => $fields['itemSoldNote']
                        ];

      

      
      $items->MoveNext();
    }
  }
}

function tfDate($date){
  $str = strtotime($date);
  return date('d/m/Y',$str);
}

function roundIt($amount,$decimal='yes'){
  return $amount;
  if($decimal == 'no'){
    return round($amount);
  }else{
    return $amount;
  }
}

/*echo $saleTotal;
print_r($itemsInSale);
dai();*/

$invDate = validateHttp('Fecha','post');
$dueDate = iftn($result['transactionDueDate'],$invDate);

if(strtotime($invDate) < strtotime($dueDate)){
  $dueDate = $invDate;
}

$invoice                  = [];

$invoice["usertoken"]     = TUSFACTURAS_USERTOKEN;
$invoice["apikey"]        = TUSFACTURAS_APIKEY;
$invoice["apitoken"]      = TUSFACTURAS_APITOKEN;
$invoice["cliente"]       = [
                              "documento_tipo"      => validateHttp('IdTipoDocumento','post'),
                              "razon_social"        => $customerData['name'],
                              "email"               => $customerData['email'],
                              "domicilio"           => $customerData['address'],
                              "documento_nro"       => preg_replace("/[^0-9]/", "", $customerData['ruc']),
                              "provincia"           => validateHttp('provincia','post'),//'2',//ver de hacer seleccionable
                              "envia_por_mail"      => "S",
                              "condicion_pago"      => 214,
                              "condicion_pago_otra" => 'Cobrado en punto de venta',
                              "condicion_iva"       => validateHttp('IdTipoIVA','post')
                            ];

$invoice["comprobante"]   = [
                              "fecha"                         => tfDate($invDate),
                              "tipo"                          => validateHttp('IdTipoComprobante','post'), //FACTURA A, FACTURA B
                              "moneda"                        => "PES",//ver de poner para seleccionar o algo
                              "idioma"                        => "1",
                              "cotizacion"                    => "",
                              "operacion"                     => "V",
                              "punto_venta"                   => TUSFACTURAS_PTOVENTA,
                              "numero"                        => iftn(validateHttp('NroFactura','post'),$result['invoiceNo']),
                              "periodo_facturado_desde"       => tfDate($invDate),
                              "periodo_facturado_hasta"       => tfDate($invDate),
                              "vencimiento"                   => iftn(tfDate($dueDate),tfDate($invDate)),
                              "rubro"                         => "",
                              "rubro_grupo_contable"          => "",
                              "detalle"                       => $itemsInSale,
                              "comentario"                    => $result['transactionNote'],
                              "bonificacion"                  => $allDiscount,
                              "total"                         => $allTotal - $allDiscount
                            ];

if($_GET['test']){
  echo '<pre>';
  dai(json_encode($invoice,JSON_PRETTY_PRINT));
  echo '</pre>';
}

header('Content-Type: application/json');

//echo curlContents("https://www.tusfacturas.com.ar/app/api/v2/facturacion/nuevo", 'POST', json_encode($invoice),["Content-Type"=>"application/json"]);
dai();
?>