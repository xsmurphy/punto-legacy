<?php
include_once('includes/compression_start.php');
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('expenses','view');

$baseUrl = '/' . basename(__FILE__,'.php');

if(validateHttp('action') == 'insert'){
  if(!allowUser('expenses','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $isOrder        = false;
  $isReposition   = false;
  $trStatus       = 1;

  if(validateHttp('typeOfEvent','post') == '1'){
    $isOrder        = true;
    $trStatus       = 0;
  }else if(validateHttp('typeOfEvent','post') == '2'){
    $isReposition   = true;
    $trStatus       = 3;
  }

  $supplier       = dec( validateHttp('supplier','post') );
  $outlet         = dec( validateHttp('outlet','post') );
  $type           = (validateHttp('type','post') == '1') ? "4" : "1";
  $invoicen       = (int)validateHttp('invoicen','post');
  $prefix         = validateHttp('invPrefix','post');
  $invDate        = iftn(validateHttp('invDate','post'),TODAY,validateHttp('invDate','post') . ' 00:00:00');
  $category       = validateHttp('transactionCategory','post') ? dec(validateHttp('transactionCategory','post')) : NULL;
  $discount       = formatNumberToInsertDB( validateHttp('discount','post') );
  $items          = validateHttp('item','post');
  $details        = [];
  
  $totalUnits     = 0;
  $total          = 0;
  $payment        = NULL;
  $totalTax       = 0;
  $totalUnits     = 0;
  $total          = 0;

  $allTaxes       = getAllTax();

  list($outlet,$location)   = outletOrLocation($outlet);

  if(!validity($items,'array')){ 
    jsonDieResult(['error'=>'Debe añadir al menos un producto o gasto']);
  }

  foreach($items as $key => $label){
    $units      = (float) formatNumberToInsertDB($label['units'],true,3);
    $price      = (float) formatNumberToInsertDB($label['price']);
    $plan       = validateHttp('transactionCategory','post');

    if($label['pack'] > 0){
      $units      = (DECIMAL == 'no') ? (int) $label['packedUnits'] : (float) $label['packedUnits'];
      $price      = (DECIMAL == 'no') ? (int) $label['packedPrice'] : (float) $label['packedPrice'];
      //dai('Got: ' . formatNumberToInsertDB($label['packedPrice']) . ' conv: ' . $price);
    }

    if($label['plan']){
      $plan = $label['plan'];
    }

    $tPrice       = abs($price * $units);

    if(DECIMAL == 'no'){
      $tPrice     = round($tPrice);
    }

    $id         = $label['itemId'];
    
    $tax        = formatNumberToInsertDB($label['taxvalue']);

    $totalTax   += $tax;
    $totalUnits += $units;
    $total      += $tPrice;

    if($id || $label['title']){
      if($isOrder){
        $details[] = [
                      'itemId'  => $id, 
                      'qty'     => formatCurrentNumber($units,'yes',false,3), 
                      'title'   => $label['title'], 
                      'price'   => $tPrice, 
                      'tax'     => $tax,
                      'plan'    => $plan
                    ];
      }else{
        $details[] = [
                      'itemId'  => $id, 
                      'qty'     => formatCurrentNumber($units,'yes',false,3), 
                      'title'   => $label['title'],
                      'plan'    => $plan
                    ];
      }
    }
  }


  if($type < 4){
    $payment        = json_encode(  [ 
                                      [
                                        'type'  => validateHttp('paymentMethod','post'), 
                                        'price' => $total
                                      ] 
                                    ] 
                                  );
  }

  //ACTUALIZO NRO DE ORDEN
  if($isOrder){
    $pastNo   = ncmExecute('SELECT outletPurchaseOrderNo FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',[$outlet,COMPANY_ID]);
    $invoicen = $pastNo['outletPurchaseOrderNo'] + 1;

    ncmUpdate([
                'records' => ['outletPurchaseOrderNo' => $invoicen], 
                'table'   => 'outlet', 
                'where'   => 'outletId = ' . $outlet . ' AND companyId = ' . COMPANY_ID
              ]);

  }else if($isReposition){
    $pastNo   = ncmExecute('SELECT outletOrderTransferNo FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',[$outlet,COMPANY_ID]);
    $invoicen = $pastNo['outletOrderTransferNo'] + 1;

    ncmUpdate([
                'records' => ['outletOrderTransferNo' => $invoicen], 
                'table'   => 'outlet', 
                'where'   => 'outletId = ' . $outlet . ' AND companyId = ' . COMPANY_ID
              ]);
  }

  $record['transactionDueDate']     = validateHttp('dueDate','post') . ' 00:00:00';
  $record['transactionType']        = $type;
  $record['transactionComplete']    = ($type == 4) ? 0 : 1 ;
  $record['transactionStatus']      = $trStatus; //0 orden 1 compra 2 pedido
  $record['transactionNote']        = validateHttp('note','post');
  $record['transactionDetails']     = json_encode($details);
  
  $record['invoiceNo']              = $invoicen; 
  $record['invoicePrefix']          = $prefix;
  $record['transactionDate']        = $invDate;
  
  $record['userId']                 = USER_ID;
  $record['supplierId']             = $supplier;
  $record['outletId']               = $outlet;
  $record['companyId']              = COMPANY_ID;


  if($isOrder){
    $record['transactionTotal']       = (float)$total - (float)$discount;
    $record['transactionDiscount']    = $discount;
    $record['transactionUnitsSold']   = $totalUnits;
    $record['transactionTax']         = $totalTax;
  }else if($isReposition){
  }else{
    $record['transactionPaymentType'] = $payment;
    $record['transactionTotal']       = (float)$total - (float)$discount;
    $record['transactionDiscount']    = $discount;
    $record['transactionUnitsSold']   = $totalUnits;
    $record['transactionTax']         = $totalTax;
    $record['categoryTransId']        = $category;
  }

  $insertTransaction                = ncmInsert(['records' => $record, 'table' => 'transaction']);
  $transID                          = $insertTransaction;

  $inserts                          = [];
  $itmIns                           = [];
  $invUpdate                        = true;

  //SI ES ORDEN METO TODOS LOS PRODUCTOS EN EL TRANSACTIONDETAILS EN JSON

  if( $transID > 0 && (!$isOrder && !$isReposition) ){

    foreach($items as $key => $itm){
      if(!$itm['itemId'] && !$itm['title']){
        continue;
      }

      $id         = dec($itm['itemId']);
      $units      = abs( formatNumberToInsertDB($itm['units'],true) );
      $price      = abs( formatNumberToInsertDB($itm['price']) );
      $expires    = $itm['expires'] . ' 00:00:00';
      $uid        = $itm['uid'];
      $title      = $itm['title'] ? $itm['title'] : NULL;
      $tax        = formatNumberToInsertDB($itm['taxvalue']);

      if($itm['pack'] > 0){
        $units      = $itm['packedUnits'];
        $price      = $itm['packedPrice'];
      }

      $total        = abs($price * $units);

      $insert                         = [];
      $insert['itemSoldTotal']        = $total;
      $insert['itemSoldTax']          = $tax;
      $insert['itemSoldUnits']        = $units;
      $insert['itemSoldDate']         = $invDate;
      $insert['itemSoldDescription']  = $title;
      $insert['itemId']               = $id;
      $insert['transactionId']        = $transID;

      $itmSoldLast = ncmInsert(['records' => $insert, 'table' => 'itemSold']);
      
      if( validity($id) && (!$isOrder && !$isReposition) ){

        if($units > 0){
          $ops                  = [];
          $ops['itemId']        = $id;
          $ops['outletId']      = $outlet;
          $ops['cogs']          = $price;
          $ops['count']         = $units;
          $ops['supplierId']    = $supplier;
          $ops['source']        = 'purchase';
          $ops['transactionId'] = $transID;
          $ops['locationId']    = $location;
          
          $stocked              = manageStock($ops);
        }

      }
    }

  }
   
  if($insertTransaction === false || $itmInsert === false || $invUpdate == false){
    echo 'false';
  }else{
    echo 'true';
  }

  dai();
}

$taxInput = selectInputTax(false,false,'',false,true);
?>
    
<div class="col-xs-12 no-padder" id="contentAppear">
  <div class="col-xs-12 no-padder m-b">
    <span class="font-bold h1 pull-left m-l" id="pageTitle">
      Compras y Gastos
      <a href="#" class="hidden-print iguiderStart" data-toggle="tooltip" title="Hacer un tour" data-placement="bottom">
        <span class="material-icons m-l text-info m-b-xs">live_help</span>
      </a>
    </span>
    <a href="#report_purchases" class="pull-right btn m-t text-info text-u-c font-bold hidden-print">Listado de Compras y Gastos</a>
  </div>
  <form action="<?=$baseUrl?>?action=insert" method="POST" id="addPurchase" class="col-xs-12 no-padder r-24x clear" autocomplete="off">
      <div class="col-md-3 col-sm-12 col-xs-12 matchCols no-padder bg-info gradBgBlue tutLeftColumn">

        <div class="col-xs-12 wrapper" style="min-height:450px;">

          <div class="col-xs-12 no-padder visiblePurchase visibleOrder">
            <label class="font-bold text-xs text-u-c">Proveedor</label>
            <div class="input-group">
              <select class="form-control no-border no-bg searchAjax tabindex" id="supplierSelect" placeholder="Seleccione un proveedor" name="supplier"></select>
              <span class="input-group-btn"> <a href="#" class="btn createSupplier" tabindex="-1"><i class="material-icons">add</i></a> </span>
            </div>
          </div>

          <div class="col-xs-12 wrapper pointer font-bold text-u-c text-center hidden-print" id="moreOps">+ Más opciones</div>
          
          <div class="col-xs-12 no-padder hidden" id="moreOpsPanel">
            <div class="col-xs-12 no-padder m-b visiblePurchase">
              <label class="font-bold text-xs text-u-c">Métodos de Pago</label>
              <?php $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "paymentMethod" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',[],false,true); ?>
              <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control search" autocomplete="off">
                 <option value="cash">Efectivo</option>
                 <option value="creditcard">T. Crédito</option>
                 <option value="debitcard">T. Débito</option>
                 <option value="check">Cheque</option>
                <?php 
                  if($pM){
                    while (!$pM->EOF) {
                      $pMId = enc($pM->fields['taxonomyId']);
                ?>
                      <option value="<?=$pMId;?>">
                        <?=$pM->fields['taxonomyName'];?>
                      </option>
                <?php 
                      $pM->MoveNext(); 
                    }
                    $pM->Close();
                  }
                  
                ?>
              </select>
            </div>
           
            <div class="col-xs-12 no-padder">
              <label class="font-bold text-xs text-u-c">Fecha de proceso</label>
              <div class="no-bg bg-light">
                <input type="text" name="invDate" class="form-control text-center datepickerTime no-border no-bg b-b text-white pointer" value="<?=date('Y-m-d H:i:s')?>" readonly style="background:none;"/>
              </div>
            </div>

            <div class="col-sm-12 col-xs-12 no-padder m-t m-b-sm font-bold visiblePurchase">

              <div class="col-md-4 col-sm-12 col-xs-4 m-t-sm text-success" id="contadoText">
                Contado
              </div>
              <div class="col-md-4 col-sm-12 col-xs-4">
                <div class="switch-select switch">
                    <div class="swinner"><input class="hidden" name="type" type="checkbox" value="1"></div>
                </div>
              </div>
              <div class="col-md-4 col-sm-12 col-xs-4 m-t-sm" id="creditoText">
                Crédito
              </div>

            </div>

            <div id="dueDateSelect" style="display:none;" class="col-xs-12 no-padder">
              <label class="font-bold text-xs text-u-c">Fecha de vencimiento</label>
              <div class="no-bg bg-light">
                <input type="text" class="form-control text-center m-b datepicker no-border no-bg b-b text-white pointer" name="dueDate" value="<?=TODAY?>" readonly style="background:none;"/>
              </div>
            </div>

            <div class="col-xs-12 no-padder m-b visiblePurchase">
              <label class="font-bold text-xs text-u-c">Plan de cuentas (Egresos)</label>
              <div><?php selectInputTaxonomy('transactionCategory',false,false,'m-b search no-border','taxonomyName ASC',true); ?></div>
            </div>

          </div>

          <div class="col-xs-12 no-padder m-b">
            <label class="font-bold text-xs text-u-c">Sucursal y Depósito</label>
            <div><?php echo selectInputOutlet(OUTLET_ID,false,'m-b search no-border rounded tabindex','outlet',false,false,true); ?></div>
          </div>                

          <div class="col-xs-12 no-padder visiblePurchase">
            <div class="col-md-6 col-sm-12 col-xs-6 no-padder">
              <label class="font-bold text-xs text-u-c"># Documento</label>
              <input type="text" name="invPrefix" class="form-control m-b no-border b-b no-bg tabindex text-white" value="" autocomplete="off" placeholder="Prefijo">
            </div>
            <div class="col-md-6 col-sm-12 col-xs-6 no-padder">
              <label class="font-bold text-xs text-u-c">&nbsp;</label>
              <input type="number" min="0" step="1" name="invoicen" class="form-control m-b text-right no-border b-b no-bg text-white" value="" autocomplete="off" placeholder="Número">
            </div>
          </div>

          <div class="col-xs-12 no-padder visiblePurchase">
            <label class="font-bold text-xs text-u-c">Descuento</label>
            <input type="text" name="discount" class="form-control m-b no-border b-b no-bg tabindex maskCurrency text-white" value="" autocomplete="off" placeholder="##.###" id="discount">
          </div>

          <div class="col-xs-12 no-padder m-b">
            <label class="font-bold text-xs text-u-c">Tipo</label>
            <select class="form-control no-bg no-border b-b text-white" name="typeOfEvent" id="typeOfOrder">
              <option value="0">Compra o Gasto</option>
              <option value="1">Orden de Compra</option>
              <option value="2">Pedido de Reposición</option>
            </select>
          </div>

          <div class="col-xs-12 no-padder">
            <label class="font-bold text-xs text-u-c">Nota</label>
            <textarea class="form-control text-white m-b no-border b-b no-bg" name="note"></textarea>
          </div>

          <div class="text-center col-xs-12 no-padder m-t hidden-print">
            <input class="btn btn-default no-border btn-rounded btn-lg text-u-c font-bold m-b btn-status mainActionBtn" type="submit" value="Registrar" id="totalPurchase" disabled>
            <br>
            <input class="no-bg no-border btn-status hidden" title="Genera una orden de compra" type="submit" value="Orden de Compra" id="totalOrder">
          </div>

        </div>
      </div>

      <div class="col-md-9 col-sm-12 col-xs-12 bg-white no-padder m-n table-responsive panel matchCols" style="min-height:450px;">

        <div class="col-xs-12 wrapper font-bold">
          <div class="col-xs-12 no-padder text-u-c">
            <div class="col-md-1 col-sm-1 hidden-xs wrapper-xs visiblePurchase hidden-print"></div>
            <div class="col-md-2 col-sm-2 col-xs-12 wrapper-xs text-right">Cantidad</div>
            <div class="col-md-5 col-sm-5 col-xs-12 wrapper-xs">Producto/Gasto</div>
            <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs text-right visiblePurchase visibleOrder">Costo Uni.</div>
            <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs text-right visiblePurchase visibleOrder">Total</div>
          </div>
        </div>

        <div id="itemsList" class="col-xs-12 no-padder"></div>

        <div class="col-xs-12 wrapper text-center hidden-print">
          <a href="#" id="add" class="m-r font-bold text-u-c" tabindex="-1"><span class="text-info">Agregar</span></a>
          <a href="#" class="remove font-bold text-u-c" tabindex="-1">Eliminar</a>
        </div>
      </div>

  </form>

  <div id="productsSelect" class="hidden"><?=$select?></div>
  <div id="taxSelect" class="hidden"><?=$taxInput?></div>
</div>

<script type="text/html" id="lineTpl">
  <div class="col-xs-12 no-padder m-b-sm b-b liner isLast" data-index="{{index}}" id="line{{index}}">

    <div class="col-xs-12 wrapper-sm">
      {{#noPurchase}}
      {{/noPurchase}}
      {{^noPurchase}}
      <div class="col-md-1 col-sm-1 col-xs-4 wrapper-xs text-center visiblePurchase hidden-print">
        <a href="#" class="openRow block m-t-xs" data-id="{{index}}" tabindex="-1"><span class="text-info font-bold h3 rowIcon{{index}}"><i class="material-icons">more_vert</i></span><span class="text-danger font-bold h3 hidden rowIcon{{index}}"><i class="material-icons">expand_less</i></span></a>
      </div>
      {{/noPurchase}}

      <div class="col-md-2 col-sm-2 col-xs-8 wrapper-xs">
        <input type="text" name="item[{{index}}][units]" data-id="{{index}}" class="form-control no-bg no-border b-b text-right units maskFloat3 tabindex" placeholder="Uni." value="{{qty}}" id="units{{index}}">
      </div>

      <div class="col-md-5 col-sm-5 col-xs-12 wrapper-xs productExspenceLine{{index}}">
        {{#title}}
        <input class="form-control no-border b-b expense{{index}} tabindex" value="{{title}}" name="item[{{index}}][title]" placeholder="Añada una descripción del gasto">
        {{/title}}
        {{^title}}
          <div class="input-group">
            <select class="form-control no-border no-bg searchAjaxItem tabindex" placeholder="Seleccione un artículo" name="item[{{index}}][itemId]" data-id="{{index}}" id="item{{index}}">
              {{#itemId}}
              <option value="{{itemId}}" selected>{{itemName}}</option>
              {{/itemId}}
            </select>

            <span class="input-group-btn hidden-print"> 
              <a href="#" tabindex="-1" data-toggle="tooltip" title="Crear Producto" class="btn createItem">
                <i class="material-icons text-info">add</i>
              </a> 
            </span>

            <span class="input-group-btn hidden-print"> 
              <a href="#" data-index="{{index}}" data-toggle="tooltip" title="Cambiar a Gasto" class="btn text-info addExpense tabindex">
                <i class="material-icons">account_balance_wallet</i>
              </a> 
            </span>
          </div>
        {{/title}}
      </div>

      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs visiblePurchase visibleOrder">
        <input type="text" name="item[{{index}}][price]" data-id="{{index}}" class="form-control no-border b-b maskCurrency text-right no-bg price tabindex" value="{{price}}" id="price{{index}}">
      </div>
      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs text-right visiblePurchase visibleOrder">
        <span id="totalX{{index}}" class="block totalItemX m-t-n-xs text-muted text-xs" data-raw="">-</span>
        <span id="total{{index}}" class="block totalItem text-md font-bold" data-raw="">0</span>
      </div>
    
    </div>

    <div class="col-xs-12 wrapper-sm secondRow{{index}} hidden visiblePurchase">
      <div class="col-md-1 col-sm-1 wrapper-xs"></div>

      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs">
        <span class="text-sm font-bold">Plan de Cuentas</span>
        <?php selectInputTaxonomy([
                                    'type'      => 'transactionCategory',
                                    'name'      => 'item[{{index}}][plan]',
                                    'class'     => 'm-b search no-border',
                                    'order'     => 'taxonomyName ASC',
                                    'allowNone' => true
                                    ]); 
        ?>
      </div>

      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs">
        <span class="text-sm font-bold"><?=TAX_NAME?></span>
        <select class="form-control no-bg no-border b-b b-light tax" placeholder="Seleccione un artículo" name="item[{{index}}][tax]" data-id="{{index}}" id="tax{{index}}">
          <?=$taxInput?>
        </select>
      </div>

      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs">
        <span class="text-sm font-bold">Valor de <?=TAX_NAME?></span>
        <input type="text" name="item[{{index}}][taxvalue]" data-id="{{index}}" class="form-control no-border b-b text-right no-bg pack maskCurrency"  value="" id="taxvalue{{index}}">
      </div>
      
      <div class="col-md-2 col-sm-2 col-xs-6 wrapper-xs">
        <span class="text-sm text-u-l pointer font-bold" data-toggle="tooltip" title="Indique la cantidad de unidades en el pack">Pack</span>
        <input type="text" name="item[{{index}}][pack]" data-id="{{index}}" class="form-control no-bg no-border b-b text-right pack maskInteger"  value="" id="pack{{index}}">
        <input type="hidden" name="item[{{index}}][packedPrice]" id="packedPrice{{index}}">
        <input type="hidden" name="item[{{index}}][packedUnits]" id="packedUnits{{index}}">
      </div>

      <div class="col-md-1 col-sm-1 wrapper-xs"></div>

    </div>

  </div>
</script>

<script type="text/html" id="noDataTpl">
  <div class="text-center wrapper-xl" id="noContentMsg">
    <img src="https://assets.encom.app/images/emptystate7.png" height="120">
    <div class="h4 m-t">Presione en AGREGAR<br>para comenzar</div>
  </div>
</script>

<script>
  
  var baseUrl         = '<?=$baseUrl?>';
  var autoStartGuide  = false;
  var unOrderAction   = {lines : []};

  <?php
  if(validateHttp('action') == 'tutorial'){
    echo "autoStartGuide = true;";
  }
  ?>

  <?php
    $id             = validateHttp('i');
    $isExtraction   = false;
    if($id){
      $result       = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ?',[dec($id),COMPANY_ID]);

      if(!$result){
        $result = ncmExecute('SELECT * FROM expenses WHERE expensesId = ? AND companyId = ?',[dec($id),COMPANY_ID]);
        $isExtraction = true;
      }

      if($isExtraction){

        if($result){
        ?>
            unOrderAction.extraction  = true;
            unOrderAction.description = "<?=toUTF8($result['expensesDescription'])?>";
            unOrderAction.amount      = "<?=$result['expensesAmount']?>";
        <?php
        }
      }else{
        
        if($result){
          $supp   = ncmExecute('SELECT * FROM contact WHERE contactId = ? LIMIT 1',[$result['supplierId']]);
          $items  = json_decode($result['transactionDetails'],true);
          $lines  = [];
          foreach ($items as $key => $value) {
            $itemName = getItemName(dec($value['itemId']));
            $lines[]  = [ "qty" => $value['qty'], "itemId" => $value['itemId'], "itemName" => $itemName, "title" => $value['title'], "price" => $value['price'] ];
          }
          ?>

          unOrderAction.lines     = <?=json_encode($lines);?>;
          unOrderAction.unOrder   = true;

          $('#supplierSelect').append($('<option>', {
              value : "<?=enc($result['supplierId'])?>",
              text  : "<?=$supp['contactName'];?>"
          }));

          $('textarea[name="note"]').val("Ref. Orden #<?=$result['invoiceNo']?> | <?=$result['transactionNote']?>");          

          <?php
        }
      }    
    }
  ?>
  

  <?php
  if($_GET['update']){
    ob_start();
  ?>
    var ncmPurchase = {
      inxs            : 0,
      expenseMode     : false,
      noPurchaseMode  : false,
      noOrderMode     : false,
      noTransferMode  : false,
      currentMode     : 0,
      tabIndex        : 0,
      checkVisibles   : function(value){
        if(value == 0){//compra
          $('.mainActionBtn').attr('id','totalPurchase');
          $('.visiblePurchase').show();
          ncmPurchase.noPurchaseMode = false;
        }else if(value == 1){//orden de compra
          $('.mainActionBtn').attr('id','totalOrder');
          $('.visibleOrder').show();
          ncmPurchase.noOrderMode     = true;
        }else if(value == 2){//pedido de reposición
          $('.mainActionBtn').attr('id','totalReposition');
          ncmPurchase.noTransferMode  = false;
        }
      },
      listeners       : function(){

        $(document).on('focus', '.select2-selection.select2-selection--single', function (e) {
          $(this).closest(".select2-container").siblings('select:enabled').select2('open');
        });

        // steal focus during close - only capture once and stop propogation
        $('select.searchAjax, select.searchAjaxItem, select.search, select.searchSimple').on('select2:closing', function (e) {
          $(e.target).data("select2").$selection.one('focus focusin', function (e) {
            e.stopPropagation();
          });
        });

        select2Simple('#typeOfOrder','body',function(tis){
          var value = tis.val();
          ncmPurchase.currentMode = value;

          $('.visiblePurchase').hide();
          $('.visibleOrder').hide();
          ncmPurchase.noPurchaseMode  = true;
          ncmPurchase.noOrderMode     = true;
          ncmPurchase.noTransferMode  = true;

          ncmPurchase.checkVisibles(value);
          $('.matchCols').matchHeight();
        });

        select2Simple('.search');

        $(document).on('keyup change','.price, .units, .pack, .tax, #discount',function(e){
          var code    = e.keyCode || e.which;
          var tis     = $(this);
          var prevVal = tis.val();
          var index   = tis.data('id');
          ncmPurchase.calculatePurchase();
        }).on('keydown','.price',function(e){
          var code    = e.keyCode || e.which;
          var tis     = $(this);
          var index   = tis.data('id');
          if(code === 9 && tis.hasClass('price') && $('#line' + index).hasClass('isLast')) { //Enter keycode
            e.preventDefault(); 
            var prevVal = tis.val();
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs);
             tis.val(prevVal);

             ncmPurchase.checkVisibles(ncmPurchase.currentMode);
             
            //ncmPurchase.calculatePurchase();  
            return false;
          }
        });
      },
      load : function(){
        $('.datepicker').datetimepicker({
          format            : 'YYYY-MM-DD',
          showClear         : true,
          ignoreReadonly    : true
        });

        var options = {
          placeholder       : "Seleccione...",
          allowClear        : true
        };

        masksCurrency($('.units'),thousandSeparator,'no');
        masksCurrency($('.maskFloat'),thousandSeparator,'yes');
        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
        //masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
        ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
        masksCurrency($('.maskInteger'),thousandSeparator,'no');

        $('.tabindex').each(function(){
          var tis = $(this);
          tis.attr('tabindex',ncmPurchase.tabIndex);
          ncmPurchase.tabIndex++;
        });

        ncmPurchase.events();
        ncmPurchase.itemSelect2();
        ncmPurchase.calculatePurchase();

        //ncmPurchase.addNewLine(0);
        ncmHelpers.mustacheIt($('#noDataTpl'),[],$('#itemsList'));

        $('.matchCols').matchHeight();
        setTimeout(function(){
          select2Simple('.search');
          ncmPurchase.itemSelect2('0');
        },180);

        ncmPurchase.listeners();
        ncmPurchase.unOrder();
      },
      events : function(){
        onClickWrap('#totalOrder',function(){

          confirmation('¿Desea generar la orden?', function (e) {
            if (e) {
              $('.btn-status').attr('disabled',true);

              setTimeout(function(){
                $('.btn-status').attr('disabled',false);
              },6000);

              $('#addPurchase').attr('action',baseUrl + '?action=insert&typestate=order').submit();
              window.onbeforeunload = null;
            }
          });
          
        },false,true);

        onClickWrap('#totalPurchase',function(){

          confirmation('¿Desea generar la compra?', function (e) {
            if (e) {
              spinner('body', 'show');

              $('.btn-status').attr('disabled',true);

              setTimeout(function(){
                $('.btn-status').attr('disabled',false);
              },6000);

              $('#addPurchase').attr('action', baseUrl + '?action=insert&typestate=purchase').submit();
              window.onbeforeunload = null;
            }
          });

        },false,true);

        onClickWrap('.openRow',function(event,tis){
          var id = tis.data('id');
          $('.secondRow' + id + ', .rowIcon' + id).toggleClass('hidden');
        },false,true);

        onClickWrap('#moreOps',function(event,tis){
          $('#moreOpsPanel').toggleClass('hidden');
          if($('#moreOpsPanel').hasClass('hidden')){
            tis.text('+ Más opciones');
          }else{
            tis.text('- Ocultar opciones');
            $('.datepickerTime').datetimepicker({
              format            : 'YYYY-MM-DD HH:mm:ss',
              showClear         : true,
              ignoreReadonly    : true
            });
          }

          switchit(function(tis, active){
            $('#creditoText,#contadoText').removeClass('text-success');
            if(active){
              $('#creditoText').addClass('text-success');
              $('#dueDateSelect').show();
            }else{
              $('#contadoText').addClass('text-success');
              $('#dueDateSelect').hide();
            }
          },true);

          $('.matchCols').matchHeight();
        },false,true);

        submitForm('#addPurchase',function(element,id){
          $('#addPurchase')[0].reset();
          $('#itemsList').html('');
          $('#totalPurchase').val('Registrar');
          $('.btn-status').attr('disabled');
          spinner('body', 'hide');
          message('Generado','success');
          ncmPurchase.tabIndex = 0;
          ncmHelpers.loadPageRefresh(false,'purchase');
        });

        var dataSelect  = $('#productsSelect').html();
        var taxSelect   = $('#taxSelect').html();

        onClickWrap("#add",function(event,tis) {
          var max = 1;
          var i   = 0;
          while (i < max) {
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs);
            i++;
          }
          ncmPurchase.checkVisibles(ncmPurchase.currentMode);
        },false,true);

        onClickWrap(".remove",function(event,tis) {
          ncmPurchase.tabIndex = ncmPurchase.tabIndex - 7;
          
          $('#itemsList').children().last().remove();

          ncmPurchase.expenseMode = false;

          var amount = 0;
          $(".totalItem").each(function(i,v){
            amount += Number($(this).attr('data-raw'));
          });

          $("#totalOrder,#totalPurchase,#totalReposition").val('Registrar ' + formatNumber(amount,currency,decimal,thousandSeparator));
          $('.btn-status').attr('disabled',false);

          if($.trim($("#itemsList").html())==''){
            ncmHelpers.mustacheIt($('#noDataTpl'),[],$('#itemsList'));
            window.onbeforeunload = null;
          }
        },false,true);

        onClickWrap(".addExpense",function(event,tis) {
          var index = tis.data('index');
          $('.productExspenceLine' + index).html('<input class="form-control no-bg no-border b-b expense' + index + ' tabindex" value="" name="item[' + index + '][title]" placeholder="Añada una descripción del gasto">');

          ncmPurchase.expenseMode = true;

          $('.tabindex').each(function(){
            var tis = $(this);
            tis.attr('tabindex',ncmPurchase.tabIndex);
            ncmPurchase.tabIndex++;
          });

          setTimeout(function(){
            $('.expense' + index).focus();
          },100);
        },false,true);

        //CREAR PROVEEDOR
        select2Ajax({element:'.searchAjax',url:'/a_contacts?action=searchCustomerInputJson&t=2',type:'contact'});

        onClickWrap('.createSupplier',function(event,tis){
          loadForm('/a_contacts?action=form&type=zg','#modalLarge .modal-content',function(){
            $('#modalLarge').modal('show');
            $('.lockpass').mask('0000');
            masksCurrency($('.maskInteger'),thousandSeparator,'no');
            //masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
            ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
          });
        },false,true);
        ///

        //create item
        onClickWrap('.createItem',function(event,tis){
          $.get('/a_items?action=insertBtn',function(response){
            response = response.split('|');
            if(response[0] == 'true'){
              id = response[2];
              loadForm('/a_items?action=editform&outcall=true&id=' + id,'#modalLarge .modal-content',function(){
                $('#modalLarge .modal-dialog').addClass('modal-lg');
                ncmPurchase.expenseMode  = false;
                $('#modalLarge').modal('show');
                $('.matchCols').matchHeight();
              });
              
            }else if(response[0] == 'false'){
              message('Error al intentar procesar su petición','danger');
            }else if(response[0] == 'max'){
              $('#maxReached').modal('show');
            }else{
              alert(response[0]);
              return false;
            }
          });
        },false,true);

        $('#modalLarge').off('shown.bs.modal').on('shown.bs.modal',function(){
          select2Simple('.search,.searchSimple'); 
          submitForm('#contactForm,#editItem',function(element,id){
            $('#modalLarge').modal('hide');
            $('#modalLarge').modal('hide');
          });
        });
        

        //
        onClickWrap(".cancelItemView",function(event,tis) {
          $('.modal').modal('hide');
        },false,true);
      },
      itemSelect2 : function(index){
        select2Ajax({
          element :'.searchAjaxItem',
          url     :'/a_items?action=searchItemStockableInputJson',
          type    :'item',
          onLoad  : function(el,container){
            //var closetabIn = container.closest('.searchAjaxItem').attr('tabindex');
            var closetabIn = $('units' + index).attr('tabindex');
            container.find('.select2-selection').attr('tabindex',closetabIn + 1);
          },
          onChange  : function($el,data){
              var id            = data.id;
              var taxId         = data.tax;
              var cost          = data.cost;

              $('input#price' + index).val(cost);
              $('section.scrollable').removeAttr('data-select2-id');
              $('select#tax' + index + ' option').each(function(){
                var tis = $(this);
                currVal = tis.text();
                currVal = currVal.substring(0, currVal.length - 1);

                if(currVal == taxId){
                    tis.attr("selected","selected");
                }
              });

              ncmPurchase.calculatePurchase();

              setTimeout(function(){
                $('input#price' + index).focus();  
              },100);
          }
        });
      },
      addNewLine : function(i,qty,itemId,itemName,title,total){
        var theTitle = (ncmPurchase.expenseMode) ? ' ' : false;
        var itotal = 0;
        var iunits = 0;
        if(title){
          theTitle = title;
        }

        if($('#itemsList #noContentMsg').length){
          $('#itemsList #noContentMsg').remove();
        }

        $('.isLast').removeClass('isLast');

        if(total){
          iunits  = unMaskCurrency(qty,thousandSeparator,'yes');
          itotal  = (total/iunits);
        }

          var data =  {
                        index     : i,
                        title     : theTitle,
                        qty       : iftn(qty,'1,000'),
                        price     : itotal,
                        itemId    : itemId,
                        itemName  : itemName,
                        noPurchase: ncmPurchase.noPurchaseMode,
                        noOrder   : ncmPurchase.noOrderMode,
                        noTransfer: ncmPurchase.noTransferMode
                      };

          var newtr = ncmHelpers.mustacheIt($('#lineTpl'),data,false,true);
        
          $('#itemsList').append(newtr);

          select2Simple('.search');
          ncmPurchase.itemSelect2(i);

          $('.datepicker').datetimepicker({
            format            : 'YYYY-MM-DD',
            showClear         : true,
            ignoreReadonly    : true
          });

          $('[data-toggle="tooltip"]').tooltip();
          $('.matchCols').matchHeight();

          setTimeout(function(){
            $('input#units' + i).focus();  
          },100);

          masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
          ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
          masksCurrency($('.maskInteger'),thousandSeparator,'no');

          $('.tabindex').each(function(){
            var tis = $(this);
            tis.attr('tabindex',ncmPurchase.tabIndex);
            ncmPurchase.tabIndex++;
          });

          window.onbeforeunload = function() {
              return true;
          };
      },
      calculatePurchase : function(){
        var total = 0;
        $('#itemsList .liner').each(function(k,val){
          var id        = $(this).data('index');
          var pricey    = $('#price' + id).val();
          var unitsy    = $('#units' + id).val();
          var packsy    = $('#pack' + id).val();
          var taxy      = parseFloat($('#tax' + id + ' option:selected').text());
          
          var price     = unMaskCurrency(pricey,thousandSeparator,decimal);
          var units     = unMaskCurrency(unitsy,thousandSeparator,'yes');
          var pack      = unMaskCurrency(packsy,thousandSeparator,'no');
          var thePrice  = price * units;

          var taxval    = getTaxOfPrice(taxy,thePrice);

          if(decimal == 'no'){
            taxval = Math.round(taxval);
          }else{
            taxval = taxval.toFixed(2);
          }
          

          if(pack > 0){
            //thePrice = thePrice / pack;
            $('#packedPrice' + id).val(price / pack);
            $('#packedUnits' + id).val(pack * units);
          }
          
          var fTotal  = formatsNumber({number:thePrice,currency:currency});
          var fTotalX = 'P.U. ' + pricey;

          $("#taxvalue" + id).val(taxval);

          $("#total" + id).text(fTotal).data('raw',thePrice);
          $("#totalX" + id).text(fTotalX).data('raw',thePrice);

          total = total + thePrice;
          
        });

        var discounty = $('#discount').val();
        var discount  = unMaskCurrency(discounty,thousandSeparator,decimal);
        var total     = total - discount;

        if(total < 0.001){
          total = 0;
        }

        $("#totalPurchase").val('Registrar ' + formatsNumber({number:total,currency:currency}));
        $('.btn-status').attr('disabled',false);
      },
      unOrder : function(){
        if(unOrderAction.extraction){
          ncmPurchase.expenseMode = true;
          ncmPurchase.inxs++;
          ncmPurchase.addNewLine(ncmPurchase.inxs,"1,000",false,unOrderAction.description,unOrderAction.description,unOrderAction.amount);
          ncmPurchase.calculatePurchase();
        }else if(unOrderAction.unOrder){
          $.each(unOrderAction.lines,(i,val) => {
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs, val.qty, val.itemId, val.itemName, val.title, val.price);
          });
          ncmPurchase.calculatePurchase();
        }
      }
    };

    ncmPurchase.load(); 

    ncmiGuiderConfig.tourTitle  = 'guide.purchase';
    ncmiGuiderConfig.loc        = '/@#purchase';
    ncmiGuiderConfig.intro = {
                                cover:'https://encom.app/wordpress/wp-content/uploads/2020/07/macbook-dashboard-plant.png',
                                title:'¿Dudas de cómo usar la sección compras y gastos?',
                                content:'Hagamos una guía rápida!',
                                overlayColor:'#3b464d'
                              };

    ncmiGuiderConfig.steps = [{
                                title     :'Configuración',       
                                content   :'En esta sección podrá añadir los datos principales de su compra o gasto como el proveedor, número de documento, descuento, comentarios, etc.', 
                                target    : (isMobile.phone) ? '#contentAppear > div.col-xs-12.no-padder.m-b' : '#addPurchase > div.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn',
                                disable   : true
                              },{
                                title     : 'Proveedor',       
                                content   : 'Aquí puede crear y buscar un proveedor para añadirlo a la compra o gasto.',  
                                target    : '#addPurchase > div.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn > div > div.col-xs-12.no-padder.visiblePurchase.visibleOrder > div',
                                disable   : true
                              },{
                                title     : 'Más Opciones',       
                                content   : 'Aquí podrá mostrar/ocultar más datos para añadir a su compra como por ej. fecha de vencimiento, forma de pago, contado/crédito, etc.',  
                                target    :'#moreOps',
                                disable   : true
                              },{
                                title     :'Tipo',       
                                content   :'No solo puedes registrar una compra o gasto, también puede generar una orden de compra o un pedido de reposición de stock',
                                target    :'.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn div > div:nth-child(7)',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Hagamos una prueba',       
                                content   : 'Presiona en <b>Agregar</b> para comenzar a cargar productos o gastos.',
                                target    : '#add span',
                                event     : 'click',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title           : 'Cantidad adquirida',       
                                content         : 'Añade la cantidad que corresponde, las unidades van a la izquierda de la coma. Presiona 0 tres veces para pasarlo a la izquierda y luego en Sig.',
                                target          : '#units1',
                                waitElementTime : 200,
                                delayBefore     : 250,
                                before          : ncmiGuiderConfig.scrollToIt
                              },{
                                title     :'Añade un Producto',
                                content   :'En este campo puedes buscar y crear un producto inventariable.',
                                target    :'.col-md-5.col-sm-5.col-xs-12.wrapper-xs.productExspenceLine1',
                                timer     : '6000',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : '¿Es un Gasto?',       
                                content   : 'Mejor registremos un gasto, presiona aquí para añadir un gasto en lugar de un producto.',
                                target    : '#line1 div:nth-child(1) > div:nth-child(3) > div > span:nth-child(4) > a > i',
                                event     : 'click',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Escribe la descripción del gasto',       
                                content   : 'Por ej. Alquiler del local',
                                target    : '#line1 > div:nth-child(1) > div.col-md-5.col-sm-5.col-xs-12.wrapper-xs.productExspenceLine1',
                                timer     : '20000',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Costo',
                                content   : 'Aquí va el costo unitario del producto o gasto',
                                target    : '#price1',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Cálculo del Total',
                                content   : 'Aquí se calculará automáticamente el total del producto.',
                                target    : '#line1 > div:nth-child(1) > div.col-md-2.col-sm-2.col-xs-6.wrapper-xs.text-right.visiblePurchase.visibleOrder',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title:'¿Más productos o gastos?',       
                                content:'Presiona en Agregar o Eliminar para añadir o quitar líneas',
                                timer           : '8000',
                                target:'#addPurchase > div.col-md-9.col-sm-12.col-xs-12.bg-white.no-padder.m-n.table-responsive.panel.matchCols > div.col-xs-12.wrapper.text-center.hidden-print',
                                disable:true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title:'¿Todo listo?',       
                                content:'Ahora si presiona en <b>Registrar</b> para procesar y finalizar la operación, <a href="https://docs.encom.app/panel-de-control/compras-y-gastos" target="_blank" class="text-white">visita el tutorial online</a> para más información.',
                                target    :'#totalPurchase',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              }];

    var guideMade = simpleStorage.get('iguide_purchase');

    if(!guideMade){
      simpleStorage.set('iguide_purchase',true);
      ncmiGuiderConfig.start();
    }

    ncmHelpers.onClickWrap('.iguiderStart',function(event,tis){
      ncmiGuiderConfig.start();
    });

    if(autoStartGuide){
      ncmiGuiderConfig.start();
    }


<?php
  $script = ob_gets_contents();
  minifyJS([$script => 'scripts' . $baseUrl . '.js']);
}
?>
</script>
<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>


<?php
include_once('includes/compression_end.php');
dai();
?>
