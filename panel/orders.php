<?php
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler

if(validateBool('action') == 'insert'){

  //if(count($_POST['item']) < 1){die('La fecha, monto, tipo y sucursal son obligatorios '.$_POST['outlet']);}

  $supplier       = iftn(dec($_POST['supplier']),null);
  $outlet         = iftn(dec($_POST['outlet']),null);
  $note           = $_POST['note'];
  $type           = $_POST['type'];

  if($type == 'order'){


  }else if($type == 'purchase'){

  }else if($type == 'quote'){

  }else if($type == 'remito'){

  }else if($type == 'invoice'){
    dai('false');
  }else{
    dai('false');
  }

  //Pendiente el payment method
  /*$paymentMethod[]= array("type"=>$_POST['paymentMethod']);
  [{"type":"cash","name":"Cr\u00e9dito","price":186000}]*/

  $type           = ($_POST['type'] == '1')?"4":"1";

  $dueDate        = explode('/',$_POST['dueDate']);
  $dueDate        = $dueDate[2].'-'.$dueDate[0].'-'.$dueDate[1].' 08:00:00';
 // $paymentMethod = '[{"type":"check","name":"Cheque","price":36000,"total":36000,"extra":""}]';

  $totalUnits     = 0;
  $total          = 0;
  $details        = array();

  $c = 0;
  foreach($_POST['item'] as $label){
    $units  = ($label['units']);
    $id     = $label['itemId'];
    $price  = formatNumberToInsertDB($label['price']);
    $name   = $label['name'];

    if($id){
      $totalUnits += $units;
      $total      += $price*$units;

      $details[]  = array("id"=>$id,"name"=>$name,"price"=>$price,"tax"=>$tax,"count"=>$units);

      $items[$c]['id']    = dec($id);
      $items[$c]['units'] = $units;
      $items[$c]['price'] = $price*$units;
    }

    $c++;
  }
  //print_r($items);
  //die();

  $record['transactionDueDate']     = $dueDate; //
  $record['transactionTotal']       = ($total); //
  $record['transactionDetails']     = json_encode($details); //
  $record['transactionUnitsSold']   = $totalUnits; //
 // $record['transactionPaymentType'] = json_encode($paymentMethod);
  $record['transactionType']        = $type; //
  $record['transactionComplete']    = ($type == '4')?0:1; //
  $record['transactionName']        = 'purchase'; //
  $record['transactionStatus']      = $typeState; //0 orden 1 compra
  $record['transactionNote']        = $note; //

  $record['userId']           = USER_ID; //
  $record['supplierId']       = $supplier; //
  $record['outletId']         = $outlet; //
  $record['companyId']        = COMPANY_ID; //

  
  $insertTransaction          = $db->AutoExecute('transaction', $record, 'INSERT');
  $transID                    = $db->Insert_ID();

  foreach($items as $itm){
    if($transID > 0){

      $id     = $itm['id'];
      $units  = abs($itm['units']);
      $price  = ($itm['price']);

      $iSold['itemSoldTotal']     = $price;
      $iSold['itemSoldUnits']     = $units;
      $iSold['itemId']            = $id;
      $iSold['transactionId']     = $transID;    
      
      $itmInsert = $db->AutoExecute('itemSold', $iSold, 'INSERT');

      if($typeState == '1'){// si no es una orden afecto el inventario
        addToHistory($units, 0, 'in', 0, $outlet, $id);
        $invUpdate = $db->Execute('UPDATE inventory SET inventoryCount = inventoryCount+'.$units.' WHERE itemId = ? AND '.$SQLcompanyId.' AND outletId = ? LIMIT 1', array($id,$outlet));
      }else{
        $invUpdate = true;
      }
    }
  }
	 
	if($insertTransaction === false || $itmInsert === false || $invUpdate == false){
		echo 'false';
	}else{
		echo 'true';
	}
	die();
}


$it     = $db->Execute('SELECT itemId, itemName, itemCOGS, itemPrice FROM item WHERE itemStatus = 1 AND itemIsParent = 0 AND '.$SQLcompanyId.' ORDER BY itemName ASC LIMIT '.$plansValues[PLAN]['max_items']);
$out    = '<option></option>';
while (!$it->EOF) {
  $name = $it->fields['itemName'];
  $id   = enc($it->fields['itemId']);

  $out .= '<option value="'.$id.'"" data-cogs="'.formatCurrentNumber($it->fields['itemCOGS']).'" data-price="'.formatCurrentNumber($it->fields['itemPrice']).'">'.$name.'</option>';
  
  $it->MoveNext();
}

$it->Close();
 
?>
<!DOCTYPE html>
<html class="no-js">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title><?=COMPANY_NAME?> Compras</title>


<?=coreFiles('top')?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" type="text/css" />
<link rel="stylesheet" href="css/select2-bootstrap.css" type="text/css" />
<?=jsGlobals();?>
<style type="text/css">
  .select2{
    width:100%!important;
  }
</style>
</head>
<body class="bg-light dk">
  <?=head();?>

  <?php 
    $result = $db->Execute('SELECT * FROM setting WHERE '.$SQLcompanyId); 
    $compId = enc(COMPANY_ID);

    $img = SYSIMGS_FOLDER.'/'.$compId.'.jpg';
    $isImg = file_exists($img);
    if(!$isImg){
      $img = 'images/transparent.png';
    }
  ?>
      <div class="row">
        <div class="col-md-2 col-sm-1"></div>
        <div class="col-md-8 col-sm-10 animated fadeInUp animatedx3" id="contentAppear" style="display:none;">

          <form action="?action=insert" method="POST" id="addItem" class="col-xs-12 no-padder">

            <div class="col-xs-12 wrapper">
              <div class="col-sm-3 no-padder">
                <span class="font-thin text-left title h2">
                  Ordenes
                </span>
              </div>
              <div class="col-sm-6 no-padder">
              </div>
              <div class="col-sm-3 no-padder text-right">
                <select name="type" tabindex="1" data-placeholder="Seleccione.." class="form-control animated" id="selectType" autocomplete="off">
                  <option value="">Seleccione un Tipo</option>
                  <option value="order">Realizar Orden de Compra</option>
                  <option value="purchase">Añadir Compra Realizada</option>
                  <option value="quote">Enviar Presupuesto</option>
                  <option value="remito">Generar Remito</option>
                  <!--<option value="invoice">Orden de Pago</option>-->
                </select>
              </div>
            </div>

            <div class="col-xs-12 no-padder">
              <div class="col-sm-4 lter wrapper">

                <div class="hiddens order purchase remito">
                  <label>Proveedor:</label>
                  <?php $supplier = $db->Execute('SELECT * FROM contact WHERE type = 2 AND '.$SQLcompanyId);?>
                  <select name="supplier" tabindex="1" data-placeholder="Seleccione.." class="form-control m-b" autocomplete="off">
                    <option value="">Ninguno</option>
                     <?php while (!$supplier->EOF) {?>
                     <option value="<?=enc($supplier->fields['contactId']);?>"><?=$supplier->fields['contactName'];?></option>
                     <?php 
                      $supplier->MoveNext(); 
                      }
                      $supplier->Close();
                      ?>
                  </select>
                </div>

                <div class="hiddens quote">
                  <label class="block">Cliente:</label>

                  <div class="block m-b">
                  <?php $customer = $db->Execute('SELECT * FROM contact WHERE type = 1 AND '.$SQLcompanyId);?>
                  <select name="customer" id="selectCustomer" tabindex="1" data-placeholder="Seleccione.." class="form-control contact" autocomplete="off" aria-hidden="true">
                    <option value="">Seleccione un cliente</option>
                     <?php while (!$customer->EOF) {?>
                     <option value="<?=enc($customer->fields['contactId']);?>" data-email="<?=$customer->fields['contactEmail'];?>"><?=$customer->fields['contactName'];?></option>
                     <?php 
                      $customer->MoveNext(); 
                      }
                      $customer->Close();
                      ?>
                  </select>
                  </div>

                  <input type="text" name="customerName" class="form-control m-b" id="customerName" placeholder="Nombre" />
                  <input type="text" name="customerEmail" class="form-control m-b" id="customerEmail" placeholder="Email" />
                </div>

                <!--<label>Métodos de Pago</label>
                <?php $pM = $db->Execute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "paymentMethod" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC'); ?>
                <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control m-b" autocomplete="off">
                   <option value="cash">Efectivo</option>
                   <option value="creditcard">T. Crédito</option>
                   <option value="debitcard">T. Débito</option>
                   <option value="check">Cheque</option>
                  <?php while (!$pM->EOF) {
                    $pMId = enc($pM->fields['taxonomyId']);
                  ?>
                    <option value="<?=$pMId;?>">
                      <?=$pM->fields['taxonomyName'];?>
                    </option>
                  <?php 
                    $pM->MoveNext(); 
                    }
                    $pM->Close();
                  ?>
                </select>-->

                <div class="hiddens order purchase">
                  <div class="col-xs-12 no-padder m-t-sm m-b-sm">
                    <div class="col-xs-4 m-t-sm">
                      Contado
                    </div>
                    <div class="col-xs-4">
                      <div class="switch-select switch">
                          <div class="swinner"><input class="hidden" name="type" type="checkbox" value="1"></div>
                      </div>
                    </div>
                    <div class="col-xs-4 m-t-sm">
                      Crédito
                    </div>
                  </div>

                  <div id="dueDateSelect" style="display:none;" class="animated animatedx3 bounceIn col-xs-12 no-padder">
                    <label>Fecha de pago:</label>
                    <input type="text" id="datepicker" class="form-control m-b" name="dueDate" value="<?=(!validateBool('from'))?date('Y-m-d h:i:s', strtotime('today')):$_POST['from'];?>" />
                  </div>
                </div>

                <input type="text" name="invoiceNumber" class="form-control m-b hiddens purchase" id="billNumber" placeholder="No. de Factura" />
                
                <div class="hiddens order purchase remito">
                  <label>Sucursal:</label>
                  <?php selectInputOutlet('outlet',$result->fields['outletId'],'m-b'); ?>
                </div>

                <label>Nota:</label>
                <textarea class="form-control m-b" name="note"></textarea>

                <input class="btn btn-info btn-block btn-lg m-b btn-status" type="submit" value="Enviar" id="totalPurchase" disabled>
              
              </div>

              <div class="col-sm-8 bg-white no-padder table-responsive" style="min-height:437px;">
                <table class="table table-condensed table-striped no-padder" id="itemsList">
                  <thead>
                    <tr>
                      <th>
                        Unidades
                      </th>
                      <th>
                        Artículo
                      </th>
                      <th>
                        Precio
                      </th>
                      <th>
                        Total
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td width="17%">
                        <input type="text" name="item[0][units]" data-id="0" class="form-control text-right units" placeholder="Uni." value="1" id="units0">
                      </td>
                      <td width="40%">
                        <select class="form-control search" name="item[0][itemId]" data-id="0">
                          <?=$out?>
                        </select>
                        <input type="hidden" name="" id="itemId0">
                        <input type="hidden" name="item[0][name]" class="" id="name0">
                      </td>
                      <td width="25%"><input type="text" name="item[0][price]" data-id="0" class="form-control maskCurrency text-right price" placeholder="Costo" id="price0"></td>
                      <td width="18%" class="text-md text-center b-l"><span id="total0" class="block m-t-xs totalItem" data-raw="">0</span></td>
                    </tr>
                  </tbody>
                </table>
                <div class="wrapper text-center">
                  <a href="#" id="add" class="text-success m-r">Agregar</a>
                  <a href="#" class="remove">Eliminar</a>
                </div>
              </div>
            </div>
          </form>

        </div>

        <div class="col-xs-12 bg wrapper">
          <div class="col-sm-2 text-center">
            <i class="h1 icon-info block m-t"></i>
          </div>
          <div class="col-sm-10 no-padder">
            Las órdenes de compra son únicamente para la re compra de productos que ya posee en su listado de artículos, si desea crear un artículo nuevo, dirijase a <a href="items">Artículos</a> y luego en el botón <strong>Crear Artículo</strong>.
            Una vez que haya creado y configurado el o los artículos nuevos, puede volver a esta página para realizar la orden de compra. <br> Si desea cargar gastos generales o compras de productos que no se encuentren en su listado de artículos, diríjase a la sección <a href="expenses">Gastos</a>
          </div>
        </div>
          
        <div class="col-md-2 col-sm-1"></div>
      </div>

<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui-touch-punch/0.2.3/jquery.ui.touch-punch.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>


<?php
  footerInjector();
  coreFiles();
  ?>
<script>
  FastClick.attach(document.body);
  $(document).ready(function(){
    $('#datepicker').datetimepicker({
      format: 'YYYY-MM-DD HH:mm:ss'
    });

      $('.hiddens').hide();

      $('#contentAppear').show();

      onClickWrap('#totalPurchase',function(){
        $('#addItem').attr('action','?action=insert').submit();
      });

      $('#selectType').on('change',function(){
        var type = $(this).val();
        $('.hiddens').hide();
        $('.'+type).show();
        $('.title').text('Ordenes');

        if(type == 'order'){
          $('.title').text('Orden de Compra');
          window.selling = false;
          
        }else if(type == 'purchase'){
          $('.title').text('Añadir Compra');
          window.selling = false;
          
        }else if(type == 'quote'){
          $('.title').text('Presupuesto');
          window.selling = true;

        }else if(type == 'remito'){
          $('.title').text('Remito');
          window.selling = true;

        }else if(type == 'invoice'){
          $('.title').text('Orden de Pago');
          window.selling = true;

        }else{
          window.selling = false;

        } 
      });

      $('#selectCustomer').on('change',function(){
        var id = $(this).val();
        var selected = $("#selectCustomer option:selected");
        var email = selected.data('email');
        var name = selected.text();

        $('#customerName').val(name);
        $('#customerEmail').val(email);
      });

      submitForm('#addItem',function(element,id){
        $('#addItem')[0].reset();
        $('#itemsList tbody').html('');
        $('#totalPurchase').val('Comprar');
        $('.btn-status').attr('disabled');
        message('Compra/Orden Generada','success');
      });

      switchit(function(tis, active){
        if(active){
          $('#dueDateSelect').show();
        }else{
          $('#dueDateSelect').hide();
        }
      });

      var options = {
        placeholder: "Seleccione...",
        allowClear: true
      };

      maskCurrency($('.maskCurrency'),thousandSeparator,decimal);
      $('.units').mask('000000000000', {reverse: true});

      $(document).on('change','.search',function(){
        var price = $(this).find(':selected').data('price');
        var cogs  = $(this).find(':selected').data('cogs');
        var name  = $(this).find(':selected').text();
        var id    = $(this).val();
        var field = $(this).data('id');

        var value = (window.selling)?price:cogs;

        $('#itemId'+field).val(id);
        $('#price'+field).val(value);
        $('#name'+field).val(name);
        maskCurrency($('.maskCurrency'),thousandSeparator,decimal);
        calculateTotal(field,thousandSeparator,decimal);
        $('.units').mask('000000000000', {reverse: true});
      });

      $(document).on('keyup','.price, .units',function(){
        calculateTotal($(this).data('id'),thousandSeparator,decimal);
      });

      $(".search, .contact").select2(options);

      var i = 0;

      onClickWrap("#add",function(tis) {
        i++;
        var newtr = $('<tr class="animated animatedx3 slideInDown"><td width="17%"><input type="text" name="item[' + i + '][units]" class="form-control text-right units" data-id="' + i + '" placeholder="Uni." value="1" id="units' + i + '"></td><td width="40%"><select class="form-control search" name="item[' + i + '][itemId]" data-id="' + i + '"><?=$out?></select><input type="hidden" name="" class="itemId" id="itemId' + i + '"><input type="hidden" name="item[' + i + '][name]" class="" id="name' + i + '"></td><td width="25%"><input type="text" name="item[' + i + '][price]" data-id="' + i + '" class="form-control maskCurrency text-right price" placeholder="Costo" id="price' + i + '"></td><td width="18%" class="text-md text-center b-l"><span id="total' + i + '" class="block m-t-xs totalItem" data-raw="">0</span></td></tr>');
        
          $('table.table tbody').append(newtr);
          $(".search").select2(options);
      });

      onClickWrap(".remove",function(tis) {
        $('.table tbody>tr:last').remove();

         var amount = 0;
        $(".totalItem").each(function(i,v){
          amount += Number($(this).attr('data-raw'));
        });

        $("#totalPurchase").val('Comprar '+formatNumber(amount,currency,decimal,thousandSeparator));
        $('.btn-status').attr('disabled',false);
      });

      function calculateTotal(id,thousandSeparator,decimal){
        var price = unMaskCurrency($('#price'+id).val(),thousandSeparator,decimal);
        var units = $('#units'+id).val();

        $('#total'+id).text(formatNumber((price*units),currency,decimal,thousandSeparator));
        $('#total'+id).attr("data-raw",price*units);

        var amount = 0;
        $(".totalItem").each(function(i,v){
          amount += Number($(this).attr('data-raw'));
        });

        $("#totalPurchase").val('Comprar '+formatNumber(amount,currency,decimal,thousandSeparator));
        $('.btn-status').attr('disabled',false);

      }
  });
</script>

</body>
</html>
<?php
$db->Close();
?>
