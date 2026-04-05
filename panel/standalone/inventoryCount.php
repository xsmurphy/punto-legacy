<?php

require_once('sa_head.php');

$data         = explode(',', base64_decode($_GET['s']));

$inventoryId  = dec($data[0]);
define('COMPANY_ID', dec($data[1]));

if(!$_GET['debug'] && !validateHttp('outlet','post')){
  //dai('Sección en mantenimiento..');
}

$SQLcompanyId   = 'companyId = ' . COMPANY_ID;
$setting        = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$company        = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$result         = ncmExecute('SELECT * FROM inventoryCount WHERE inventoryCountId = ? LIMIT 1', [$inventoryId]);

list($outletId,$locationId)     = outletOrLocation($result['outletId']);
$timezone                       = $setting['settingTimeZone'];
date_default_timezone_set($timezone);
define('ASSETS_URL', 'https://assets.encom.app');
define('DECIMAL', $setting['settingDecimal']);
define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('CURRENCY', $setting['settingCurrency']);
define('PLAN', $company['companyPlan']);
define('WEBSITE', $setting['settingWebSite']);
define('COMPANY_NAME', $setting['settingName']);
define('COMPANY_EMAIL', $setting['settingEmail']);
define('OUTLETS_COUNT', 0);
define('TODAY', date('Y-m-d H:i:s'));
define('OUTLET_ID', $outletId);

if(validateHttp('action') == 'update'){
  if(!$inventoryId){
    dai('Inv ID not found');
  }

  $allData    = validateHttp('data','post');

  $allItems   = json_decode($allData['items'], true);
  $invId      = validateHttp('id');
  $pType      = $allData['type'];
  $ceroIgnore = $allData['ceroIgnore'];
  $status     = 0;
  $record     = [];
  $inputedArr = [];
  $onHandArr  = [];

  foreach($allItems as $i => $value){
    $inputedArr[$value['id']] = $value['inputed'];
    $onHandArr[$value['id']]  = $value['onhand'];
  }

  if($pType){
    if($pType == 'save'){

      $status     = 1;
      $data       = [ "inputed" => $inputedArr ];

    }else{

      $status     = 2;
      $data       = [ "inputed" => $inputedArr, "onhand" => $onHandArr ];
    
      //afecto inventario
      if($pType == 'affect'){

        foreach($inputedArr as $id => $count){

          $onHandCount  = $onHandArr[$id];//tengo
          $counted      = formatNumberToInsertDB($count,true,3);//conte
          $ops          = [];

          if($counted == 0 && $ceroIgnore == 'true'){
            continue;
          }else if($counted == 0 && $ceroIgnore == 'false'){
            //error_log($onHandCount);
            $type = ($onHandCount > 0) ? "-" : "+" ;
            $amount           = abs($onHandCount);
            
            //error_log($amount);
            $ops['itemId']    = dec($id);
            $ops['outletId']  = OUTLET_ID;
            $ops['locationId']= $locationId;
            $ops['count']     = $amount;
            $ops['type']      = $type;
            $ops['source']    = 'count';
            $ops['date']      = TODAY;

            manageStock($ops);

            continue;
          }else{

          }

          if($counted > $onHandCount){//si inputed es mayor a lo que hay en el sistema

            if($onHandCount < 0){

              $onHandCount    = abs($onHandCount);
              $amount         = $counted + $onHandCount;

            }else{

              $amount         = ($counted - $onHandCount);

            }

            $ops['itemId']    = dec($id);
            $ops['outletId']  = OUTLET_ID;
            $ops['locationId']= $locationId;
            $ops['count']     = $amount;
            $ops['source']    = 'count';
            $ops['date']      = TODAY;

            manageStock($ops);
            
          }else if($counted < $onHandCount){//si inputed es menor al sistema resto

            $amount           = ($onHandCount - $counted);

            $ops['itemId']    = dec($id);
            $ops['outletId']  = OUTLET_ID;
            $ops['locationId']= $locationId;
            $ops['count']     = $amount;
            $ops['type']      = '-';
            $ops['source']    = 'count';
            $ops['date']      = TODAY;

            manageStock($ops);
            
          }

        }
      }

    }
  }

  $updated  = date('Y-m-d H:i:s');

  $result   = ncmExecute('SELECT data FROM inventoryCount WHERE inventoryCountId = ? AND companyId = ? LIMIT 1',[$inventoryId, COMPANY_ID]);

  if($result){
    $counterData                    = json_decode( $result['data'], true );
    $counterData['ceroIgnore']      = $ceroIgnore;
    $record['data']                 = json_encode($counterData);
  }

  $record['inventoryCountedData']   = json_encode($data);
  $record['inventoryCountStatus']   = $status;
  $record['inventoryCountUpdated']  = $updated;

  $update = ncmUpdate(['records' => $record, 'table' => 'inventoryCount', 'where' => 'inventoryCountId = ' . db_prepare($inventoryId)]);

  if(!$update['error']){
    jsonDieResult(['error'=>false],200);
  }else{
    jsonDieResult(['error'=>true],500);
  }
  dai();
}

$category         = getAllItemCategories();
$data             = json_decode($result['inventoryCountData'],true);
$isBlind          = validity($result['inventoryCountBlind']) ? 'hidden' : '';
$isBlind          = ($result['inventoryCountStatus'] < 2) ? $isBlind : '';
$infoData         = json_decode($result['data'],true);
$note             = $infoData['note'] ? $infoData['note'] : $result['inventoryCountNote'];
$name             = $infoData['name'] ? $infoData['name'] : $result['inventoryCountName'];
$cogs             = $infoData['cogs'];
$ceroIgnore       = $infoData['ceroIgnore'];

?>

<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title><?=$name?></title>
<?php
  loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'],'css');
?>
</head>
<body>
  
  <div class="col-xs-12 col-sm-12 col-md-10 col-md-offset-1 bg-white wrapper all-shadows m-t r-24x" id="countTable">

    <div class="col-xs-12 no-padder hidden-print hidden text-right m-b-n-lg" id="exportTools">
      <span class="dropdown" title="Opciones" data-placement="right">
        <a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown">
          <span class="material-icons">more_horiz</span>
        </a>
        <ul class="dropdown-menu animated fadeIn speed-4x" role="menu">
          <li>
            <a class="exportTable text-default" data-table="resultTable" data-name="Conteo <?=niceDate($result['inventoryCountDate'],true)?>" href="#">
              <span class="material-icons m-r-sm">get_app</span> A Excel
            </a>
          </li>
          <li> 
            <a class="text-default" href="javascript:window.print();">
              <span class="material-icons m-r-sm">print</span> Imprimir
            </a>
          </li>
        </ul>
      </span>
    </div>

    <div class="col-xs-12 text-center m-t m-b">
      <img src="<?=companyLogo(80);?>" class="img-circle">
      <div class="col-xs-12 font-bold h3 m-t-sm">
        <?=COMPANY_NAME?>
        <div class="h4 text-muted">
          <?=getCurrentOutletName()?> - <?=getLocationName($locationId)?>
        </div>
      </div>
      <div class="col-xs-12 no-padder m-t-sm"><?=$name?> - <?=niceDate($result['inventoryCountDate'],true)?> - <?=iftn(getValue('contact', 'contactName', 'WHERE contactId = ' . $result['userId'],'string'),'Sin Encargado')?></div>
      <div class="col-xs-12 no-padder m-t-sm">
        <?=$note?>
      </div>
      <div class="col-xs-12 no-padder m-t-sm hidden-print">
        <input type="text" name="barcode" placeholder="Utilice su lector de códigos" class="form-control input-lg rounded bg-light no-border font-bold text-center" id="barcodeScanner">
      </div>
    </div>
    
    <form action="?action=update&s=<?=$_GET['s']?>&i=<?=enc($inventoryId)?>" class="row" method="POST" id="countForm" name="editSale">
      
      <input type="hidden" name="id" value="<?=enc($inventoryId)?>" id="inventoryId">
      <input type="hidden" name="outlet" value="<?=enc($outletId)?>" id="outletId">

      <?php
      $printTable = '';

      if($result['inventoryCountStatus'] < 2){
        echo '<input type="hidden" name="type" id="typeSubmit" value="save">';
      }
      ?>

      <div class="col-xs-12 no-padder hidden-print">
          <?php

          if($result['inventoryCountStatus'] > 1){
            $allItems  = getAllItems();
            $plus      = 0;
            $minus     = 0;
            $plusC     = 0;
            $minusC    = 0;
          }

          $maxCount = 80000;
          $inpCnt   = 1;
          
          if(validity($data)){
            $tabin      = 1;
            $printTable = '';
            $positiveTotal = 0; // Variable para almacenar el total de valores positivos
            $negativeTotal = 0; // Variable para almacenar el total de valores negativos
            foreach($data as $catId){
              if(validity($catId)){
                $item   = ncmExecute('SELECT itemName, itemId, itemSKU FROM item WHERE itemIsParent < 1 AND itemStatus > 0 AND itemTrackInventory > 0 AND categoryId = ? AND (outletId = 0 OR outletId IS NULL OR outletId = ?)', [dec($catId), OUTLET_ID],false,true);

                $printTable .=  '<table class="table visible-print">' .
                                ' <thead>' .
                                '   <tr>' .
                                '     <th>' . $category[dec($catId)]['name'] . '</th>' .
                                '     <th>SKU</th>' .
                                '     <th class="text-right">P. Costo</th>' .
                                '     <th class="text-right">Stock</th>' .
                                '     <th class="text-right">Contabilizado</th>' .
                                '     <th class="text-right">Diferencia</th>' .
                                '   </tr>' .
                                ' </thead>' . 
                                ' <tbody>';
                ?>

                <div class="col-xs-12 wrapper bg-light text-md text-left">
                  <div class="col-md-2 col-xs-12 font-bold text-u-c"><?=$category[dec($catId)]['name']?></div>
                  <div class="col-md-2 col-xs-3 text-xs text-right font-bold text-u-c m-t-xs">P. Costo</div>
                  <div class="col-md-2 col-xs-3 text-xs text-right font-bold text-u-c m-t-xs">Stock</div>
                  <div class="col-md-2 col-xs-3 text-xs text-right font-bold text-u-c m-t-xs">Contabilizado</div>
                  <div class="col-md-2 col-xs-3 text-xs text-right font-bold text-u-c m-t-xs">Diferencia</div>
                  <div class="col-md-2 col-xs-3 text-xs text-right font-bold text-u-c m-t-xs">Ajuste</div>
                </div>

                <?php
                $counted = json_decode($result['inventoryCountedData'],true);
                
                if($item){
                  $leftover = 0;
                  $missing = 0;
                  $positive = 0;
                  $negative = 0;
                 
                  while (!$item->EOF) {
                    $itemId   = enc($item->fields['itemId']);
                    $onHand   = 0;
                    $itmCogs  = '##.###';
                  
                    if($cogs){
                      $itmCogs = getItemCOGS($item->fields['itemId']);
                    }

                    if($result['inventoryCountStatus'] < 2){//no finalizado

                      if(validity($locationId)){//si es por deposito obtengo stock en el deposito

                        $stockIs    = ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$locationId,$item->fields['itemId']]);
                        if($stockIs){
                          $onHand   = $stockIs['toLocationCount'];
                        }

                      }else{
                        $onHand     = getItemMainStock($item->fields['itemId'],$outletId);
                        $nolocation = 'si';
                      }

                      $inputed      = $counted['inputed'][$itemId];

                    }else{

                      $onHand       = $counted['onhand'][$itemId];
                      $inputed      = formatNumberToInsertDB($counted['inputed'][$itemId],true,3);

                    }
                    
                    if($result['inventoryCountStatus'] > 1){
                      $strike       = '';
                      if($inputed < 0.001){
                        $strike     = 'text-l-t';
                      }
                    }
                    if($onHand <= 0){
                      $missing  = $missing + $onHand; 
                    }else{
                      $leftover = $leftover + $onHand;
                    }
 
                    $printTable .=  '<tr>' .
                                    ' <td>' . $item->fields['itemName'] . '</td>' .
                                    ' <td>' . iftn($item->fields['itemSKU'],'-') . '</td>' .
                                    ' <td class="text-right">' . $itmCogs . '</td>' .
                                    ' <td class="text-right">' . formatQty($onHand) . '</td>' .
                                    ' <td class="text-right">' . formatQty($inputed) . '</td>';
                    ?>

                    <div class="col-xs-12 wrapper text-left clickeable blink b-b b-light <?=$strike?>" data-sku="<?=$item->fields['itemSKU']?>" data-cnt="<?=$inpCnt?>">
                      <div class="col-md-2 col-xs-12">
                        <div class="h4 font-bold"><?=$item->fields['itemName']?></div>
                        <div class="text-muted"> <?=iftn($item->fields['itemSKU'],'-')?> </div>  
                      </div>

                      <div class="col-md-2 col-xs-3 text-md font-bold text-u-c m-t text-right">
                        <?=formatCurrentNumber($itmCogs);?>
                      </div>

                      <div class="col-md-2 col-xs-3 text-md font-bold text-u-c m-t text-right" data-raw="<?=$onHand?>">
                        <?php
                        if(!$isBlind){
                          echo formatQty($onHand,3);
                        }
                        ?>
                      </div>

                      <div class="col-md-2 col-xs-3 text-md font-bold text-u-c text-right">
                        
                          <?php
                            if($result['inventoryCountStatus'] < 2){ //si aun no se finalizo
                          ?>  
                              <input type="tel" pattern="[0-9]*" novalidate class="form-control hidden-print no-border b-b text-right input-lg maskFloat3 counterInput no-bg text-dark inputed inp<?=$itemId?>" data-id="<?=$itemId?>" data-onhand="<?=$onHand?>" value="<?=$inputed?>" name="inputed[<?=$itemId?>]" tabindex="<?=$tabin;?>" disabled>
                          <?php
                              $tabin++;
                            }else{// si se finalizo
                              echo '<div class="m-t counted" data-id="' . $itemId . '" data-raw="' . $counted['inputed'][$itemId] . '">' . formatQty($inputed,3) . '</div>';
                            }
                          ?>
                      </div>
                      <div class="col-md-2 col-xs-3 text-md font-bold text-u-c m-t text-right">
                        
                        <?php
                        if($result['inventoryCountStatus'] < 2){//no finalizo
                        ?>
                          <span class="ajus<?=$itemId?> <?=$isBlind?>">-</span>
                        <?php
                        }else{

                          $differenzia = 0;

                          if(validity($inputed)){

                            if($onHand > $inputed){

                              $minusVal     = ($inputed - $onHand);
                              $differenzia  = $minusVal;
                             $minus        += $minusVal * $allItems[dec($itemId)]['price'];
                             $minusC       += $minusVal;
                              
 
                            }else if($inputed > $onHand){

                              $plusVal      = ($inputed - $onHand);
                              $differenzia  = $plusVal;
                              $plus         += $plusVal * $allItems[dec($itemId)]['price'];
                              $plusC        += $plusVal;
                            

                            }else{
                              $differenzia  = 0;
                          

                            }

                            echo $differenzia+" ";
                           
                             

                          }else{

                          

                            if($ceroIgnore == 'false'){
                             $text = ($minusVal > 0) ? 'text-danger':'';
                              $minusVal     = $inputed - $onHand;
                              $minus        += $minusVal * $allItems[dec($itemId)]['price'];
                              $minusC       += $minusVal;
                              $differenzia  = $minusVal;
                            

                              echo $differenzia+" ";
                       
                              
                            }else{
                              echo '-';  
                            }
                            
                            
                          }
                         
                          
                        }
                        if ($differenzia > 0) {
                          $positiveTotal += $differenzia;
                        } else if ($differenzia < 0) {
                          $negativeTotal += $differenzia;
                        }


                        $printTable .=  '<td class="text-right">' . $differenzia . '</td></tr>';
                 
                        ?>
                      </div>

                      <div class="col-md-2 col-xs-3 text-md font-bold text-u-c m-t text-right">
                    
                        <?php
                        if($result['inventoryCountStatus'] < 2){//no finalizo
                        ?>
                          <span class="dif<?=$itemId?> <?=$isBlind?>">-</span>
                        <?php
                        }else{

                          $differenzia = 0;

                          if(validity($inputed)){

                            if($onHand > $inputed){

                              $minusVal     = ($inputed - $onHand);
                              $differenzia  = $minusVal;
                            
                              
 
                            }else if($inputed > $onHand){

                              $plusVal      = ($inputed - $onHand);
                              $differenzia  = $plusVal;
                          

                            }else{
                              $differenzia  = 0;
                             

                            }
                           
                           
                          

                            echo $differenzia+" " ;
                            
                       
                           

                          }else{

                         
                            if($ceroIgnore == 'false'){
                             $text = ($minusVal > 0) ? 'text-danger':'';
                      
                              $minusVal     = $onHand ;
                              $differenzia  = $minusVal;
                         

                              echo $differenzia+" ";
                           
                              
                            }else{
                              echo '-';  
                            }
                            
                            
                          }
                         
                          
                        }
                    
                       
                      

                        $printTable .=  '<td class="text-right">' . $differenzia . '</td></tr>';
                     
               
                        ?>
                      </div>
                    </div>

                    <?php
                    $inpCnt++;
                    $item->MoveNext();
                  }

                  $item->Close();
                }else{
                  ?>
                  <div class="col-xs-12 wrapper-md text-center font-bold h4">
                    <img src="https://assets.encom.app/images/emptystate7.png" height="110" class="m-b">
                    <div>Sin productos inventariables en esta categoría</div>
                  </div>
                  <?php
                }

                if($inpCnt > $maxCount){
                  echo '<script type="text/javascript">alert("Ha superado el máximo de artículos permitidos, no podrá procesar este conteo");</script>';
                }
              }
            }

            $printTable .=  '</tbody> </table>';

          }else{
            echo noDataMessage("No se seleccionaron categorías","Debe seleccionar las categorías que desea inventariar","https://assets.encom.app/images/emptystate7.png");
          }

          if($result['inventoryCountStatus'] > 1){
            $printTable .=  '<table class="table visible-print font-bold"> <tbody>' .
                            '<tr><td colspan="5" class="text-right font-bold text-u-c h4">Resumen</td></tr>' .
                            '<tr>' .
                            ' <td></td><td></td><td class="text-right">Sobrante</td>' .
                            ' <td class="text-right">' . $positiveTotal. '</td>' .
                            ' <td class="text-right">' . CURRENCY . formatCurrentNumber($plus) . '</td>' .
                            '</tr>' .

                            '<tr>' .
                            ' <td></td><td></td><td class="text-right">Faltante</td>' .
                            ' <td class="text-right">' . $negativeTotal . '</td>' .
                            ' <td class="text-right"><div>' . CURRENCY . '-' . formatCurrentNumber($minus) . '</div><span class="text-xs">Precio de venta</span></td>' .
                            '</tr>' .

                            '<tr>' .
                            ' <td></td><td></td><td class="text-right">Diferencia</td>' .
                            ' <td class="text-right">' . formatQty( $positiveTotal+$negativeTotal, 3) . '</td>' .
                            ' <td class="text-right">' . CURRENCY . formatCurrentNumber( abs($plus - $minus) ) . '</td>' .
                            '</tr>' .
                            '</tbody> </table>';
          ?>
          <div class="col-xs-12 wrapper hidden-print">
            <div class="col-sm-6"></div>
            <div class="col-sm-6 cols-xs-12 wrapper bg-light lter r-24x">
              <div class="col-xs-12 text-center font-bold text-muted h3 wrapper">Resumen</div>
              <table class="table">
                <tr class="font-bold text-md">
                  <td class="text-right text-u-c">Sobrante</td>
                  <td class="text-right"><?=$positiveTotal?></td>
                  <td class="text-right" colspan="2"><?=CURRENCY.formatCurrentNumber($plus)?></td>
                </tr>
                <tr class="font-bold text-md">
                  <td class="text-right text-u-c">Faltante</td>
                  <td class="text-right text-danger"> 
                    <?= $negativeTotal; ?> 
                  </td>
                  <td class="text-right text-danger" colspan="2">
                    <div><?=CURRENCY.''.formatCurrentNumber($minus)?></div>
                    <span class="text-xs">Precio de venta</span>
                  </td>
                </tr>
                <tr class="font-bold text-md">
                  <td class="text-right text-u-c">Diferencia</td>
                  <td class="text-right"><?= formatQty( $positiveTotal+$negativeTotal, 3) ?></td>
                  <td class="text-right" colspan="2"><?=CURRENCY.formatCurrentNumber($minus+$plus)?></td>
                </tr>
              </table>
            </div>
          </div>
          <?php
          }
          ?>
      </div>

      <?php
      echo '<div class="col-xs-12 wrapper-lg" id="resultTable">' . $printTable . '</div>';
      $finished = false;
      if($result['inventoryCountStatus'] < 2 && $inpCnt < $maxCount){
      ?>

      <div class="col-xs-12 wrapper m-t-md hidden-print">
        <div class="col-xs-12 m-b">
          <div class="font-bold text-u-c">Ignorar valores en cero</div>
          <?=switchIn('ceroIgnore',1)?>
        </div>

        <div class="col-md-4 col-sm-6 col-xs-12 m-b-sm">
          <a href="#" class="btn btn-block btn-lg btn-dark r-24x submitFormBtn" data-type="save">
            <div class="font-bold">Continuar más tarde</div>
            <div class="text-sm">Guardar el estado actual</div>
          </a>
        </div>
        <div class="col-md-4 col-sm-6 col-xs-12 no-padder m-b-sm">
          <a href="#" class="btn btn-block btn-lg btn-info r-24x submitFormBtn" data-type="finish">
            <div class="font-bold">Finalizar sin afectar</div>
            <div class="text-sm">Finalizará el conteo sin modificar el stock</div>
          </a>
        </div>
        <div class="col-md-4 col-sm-12 col-xs-12 m-b-sm">
          <a href="#" class="btn btn-block btn-lg btn-success r-24x submitFormBtn" data-type="affect">
            <div class="font-bold">Finalizar y afectar</div>
            <div class="text-sm">Finalizará el conteo y ajustará el stock</div>
          </a>
        </div>
      </div>

      <?php
      }else{
        $finished = true;
      }
      ?>

    </form>
  </div>

<?php
footerInjector();
?>
<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js','https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.16.2/xlsx.full.min.js'],'js');
?>
<script>
  var isFinished            = '<?=$finished?>';
  var inventoryCountStatus  = '<?=$result['inventoryCountStatus']?>';
  var ese                   = '<?=validateHttp('s')?>';


  $(document).ready(function(){
    FastClick.attach(document.body);

    ncmUI.setDarkMode.auto();

    var ncmInventoryCount = {
      ceroIgnore            : true,
      editting              : false,
      getAllFormValues      : () => { },
      calculateStockCounted : function(tis){
        //debugger;
        var id          = tis.data('id');
        var onHand      = tis.data('onhand');
        console.log(onHand);
        var rest        = 0;
        var rest2       = 0;

        var fNops     =   {
                            number        : tis.val(),//str/num
                            typing        : true,
                            decimal       : 'yes',
                            raw           : true,
                            customDecimal : 3
                          };
        var fNops2     =   {
                            number        : tis.val(),//str/num
                            typing        : true,
                            decimal       : 'yes',
                            raw           : true,
                            customDecimal : 3
                          };

        var value     = formatsNumber(fNops);
        var value2     = formatsNumber(fNops2);
        rest2 = onHand - value2 ;
        if(value == onHand){
          var diference = 0;
          var diference2 = 0;
        }else{
          
          if(!ncmInventoryCount.ceroIgnore){
              console.log(onHand);
          
       
                if(value == 0){
                  if(onHand < 0){
                    rest = Math.abs(onHand);
                  }else{
                    rest = -Math.abs(onHand);
                  }
                   
                    
                }else{
                   rest      = (value - onHand);
                }
                
          }else{
    
            if(onHand < 0){
              //onHand = Math.abs(onHand);
              rest      = (value + onHand);
          
            }else{
              rest      = (value - onHand);
            }
          }
          
          
            
          fNops2.number  = rest2;
          fNops2.raw     = false;
          fNops2.typing  = false;
          fNops.number  = rest;
          fNops.raw     = false;
          fNops.typing  = false;
          //var color     = (fNops.number < 0) ? 'text-danger' : '';

          var diference = '<span class="">' + formatsNumber(fNops2) + '</span>';
          var diference2 = '<span class="">' + formatsNumber(fNops) + '</span>';
        }

        if(ncmInventoryCount.ceroIgnore){
            if(value <= 0){
                diference = '-';
            }
        }

        $('.dif' + id).html(diference2);
        $('.ajus' + id).html(diference);
      },
      load : function(){}
    };

    ncmInventoryCount.editting = false;

    masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');

    $('.input-lg').each(function(){
      ncmInventoryCount.calculateStockCounted($(this));
    });

    $('.input-lg').off('keyup').on('keyup',function(){
      ncmInventoryCount.calculateStockCounted($(this));
      ncmInventoryCount.editting = true;
    });

    onClickWrap('.exportTable',function(event,tis){
      var theTable  = tis.data('table');
      var name      = tis.data('name');

      table2Xlsx(theTable,name);
    });

    if(isFinished){
      $('#exportTools').removeClass('hidden');
    }

    onClickWrap('#print', function(event,tis){       
      window.print();
    });
 
    $(document).on('submit','#countForm',function(e) {
      ncmInventoryCount.editting = false;

      if(inventoryCountStatus < 2){

        spinner('.table', 'show');

        var formData  = $(this).serialize().toString();
        formData      = formData.split('=').join('≠');
        formData      = formData.split('&').join('~');
        
        $.ajax({ // create an AJAX call...
          data    : 'data=' + formData,
          type    : $(this).attr('method'),
          url     : $(this).attr('action'),
          success : function(response) {
            if(response === 'true'){
              location.reload();
            }else if(response === 'false'){
              alert('Hubo un error!');
            }else{
              alert(response);
            }
            spinner('.table', 'hide');
          }
        });
      
      }else{
        e.preventDefault();
      }
      
      return false; // cancel original event to prevent form submitting
    });

    var collectDataToSubmit = (type) => {
      var json =  [];

      $('input.inputed').each((i, val) => {

        var inputed = $(val).val();
        var id      = $(val).data('id');
        var onhand  = $(val).data('onhand');

        json.push({ id : id, inputed : inputed, onhand : onhand });

      });
      console.log(json);

      ncmHelpers.load({
        type        : 'json',
        url         : '?s=' + ese + '&action=update',
        data        : { 
                        data : { 
                                  items       : JSON.stringify(json), 
                                  outlet      : $('#outletId').val(), 
                                  ceroIgnore  : ncmInventoryCount.ceroIgnore, 
                                  type        : type 
                                } 
                      }
                      ,
        success     : (result) => {

          if( !ncmHelpers.validInObj(result,'error') ){
            message('Conteo procesado','success');
            ncmInventoryCount.editting = false;

            setTimeout(() => {
              location.reload();
            }, 900);

          }else{
            message('No se pudo procesar el conteo','danger');
          }

          $('.submitFormBtn').removeClass('disabled');

        },

        fail        : () => {
          message('No se pudo procesar el conteo','danger');
          $('.submitFormBtn').removeClass('disabled');
        }

      });

    };

    onClickWrap('.submitFormBtn', function(event,tis){
      if(tis.hasClass('disabled')){
        return false;
      }

      var type = tis.data('type');
      tis.addClass('disabled');
      $('#typeSubmit').val(type);
      if(type != 'save'){
        ncmDialogs.confirm('Realmente desea continuar?','','question',function(confi){
          if(confi){
            //$('#countForm').submit();
            collectDataToSubmit(type);

          }else{
            spinner('body', 'hide');
            tis.removeClass('disabled');
          }
        });        
      }else{
        //$('#countForm').submit();

        collectDataToSubmit(type);


      }
    });

    $('input.counterInput').prop('disabled',false);

    window.onbeforeunload = function(){
      if(ncmInventoryCount.editting){
        return true;
      }else{
        return null;
      }
    }

    $('input#barcodeScanner').keyup(function(e){
      if(e.keyCode == 13){
          var code = $('input#barcodeScanner').val();
          var $row = $('[data-sku="' + code + '"]');

          if(code && $row.length){
            var $input  = $row.find('input.counterInput');
            var prev    = $input.val();
            var value   = parseFloat(unMaskCurrency(prev,window.thousandSeparator,true)) + 1;
            var out     = formatsNumber({number : value, decimal : 'yes', customDecimal : 3});
            $input.val(out);
            ncmInventoryCount.calculateStockCounted($input);
          }
      }
    });

    switchit((tis, isActive) => {
        ncmInventoryCount.ceroIgnore = isActive;
        $('.input-lg').each(function(){
          ncmInventoryCount.calculateStockCounted($(this));
        });
    });
   
  });
</script>

</body>
</html>
<?php
dai();
?>
