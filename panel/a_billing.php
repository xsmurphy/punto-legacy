<?php
die('No disponible');

include_once("includes/compression_start.php");

include_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("libraries/countries.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");

topHook();
allowUser('settings','view');

$baseUrl = '/' . basename(__FILE__,'.php');

if(validateHttp('action') == 'addPayment'){
  if(validateHttp('cc','post')){

    $meta['subject'] = 'Nuevo pago de ' . COMPANY_NAME;
    $meta['to']      = 'income.register@gmail.com';
    $meta['fromName']= 'ENCOM';
    $meta['data']    = [
                        "message"     => USER_NAME . ': ' . json_encode(validateHttp('cc','post')),
                        "companyname" => 'ENCOM',
                        "companylogo" => 'https://assets.encom.app/150-150/0/' . enc(COMPANY_ID) . '.jpg'
                      ];

    sendEmails($meta);
    $sent  = true;

    if($sent){

      if(validateBool('selectplan')){
        $selectedPlan = dec(validateBool('selectplan'));

        $_SESSION['user']['plan'] = validateBool('selectplan');

        //aqui creo una venta
          
        if($selectedPlan == 1){//company
          $itemId = PLAN_COMPANY_ID;
        }else if($selectedPlan == 2){
          $itemId = PLAN_FULL_ID;
        }else if($selectedPlan == 5){
          $itemId = PLAN_STARTER_ID;
        }else if($selectedPlan == 7){
          $itemId = PLAN_MICRO_ID;
        } 

        $plan     = $plansValues[$selectedPlan]['price'];
        $oCount   = OUTLETS_COUNT;
        $total    = $plan*$oCount;
        $tax      = addTax(10,$total);

        $detail = '[{"itemId":"'.enc($itemId).'","uId":"","name":"'.getItemName($itemId).'","uniPrice":'.$plan.',"count":'.$oCount.',"discount":0,"discAmount":0,"totalDiscount":0,"price":'.$plan.',"tax":10,"note":"","type":"product","total":'.$total.'}]';

        $record['transactionTotal']       = $total; //total sale amount
        $record['transactionUnitsSold']   = $oCount;
        $record['transactionTax']         = $tax;
        
        $record['transactionDetails']     = $detail;

        $record['customerId']       = ENCOM_UID;
        $record['userId']           = INCOME_USER_ID;
        $record['outletId']         = INCOME_OUTLET_ID;
        $record['companyId']        = ENCOM_COMPANY_ID;

        $record['transactionNote']  = COMPANY_NAME . ' plan seleccionado desde su propio panel de control';
        
        //item sold
        $records['itemSoldTotal']     = $plan;
        $records['itemSoldTax']       = $tax;
        $records['itemSoldUnits']     = $oCount;
        $records['itemId']            = $itemId;

        if(BALANCE >= $total){
          
          $pay = $db->Execute('UPDATE company SET companyBalance = companyBalance-'.$total.', companyPlan = ? WHERE '.$SQLcompanyId,array($selectedPlan));
        
          if($pay){ //si se pudo descontar del saldo, realizo una venta al Contado
            $record['transactionType']        = '0'; //contado
            $record['transactionComplete']    = 1;
            $db->AutoExecute('transaction', $record, 'INSERT');
            $transID                          = $db->Insert_ID();

            $records['transactionId']         = $transID;
            $db->AutoExecute('itemSold', $records, 'INSERT');
            //

            header('location:' . $baseUrl . '?passed=payed');
          }else{
            header('location:' . $baseUrl . '?passed=false');
          }
        }else{
          $pay = $db->Execute('UPDATE company SET companyPlan = ? WHERE ' . $SQLcompanyId, [$selectedPlan]);

          $record['transactionDueDate']     = TODAY;
          $record['transactionDate']        = TODAY;
          $record['transactionType']        = '3'; //credito
          $record['transactionComplete']    = 0;
          
          $db->AutoExecute('transaction', $record, 'INSERT');
          $transID                    = $db->Insert_ID();

          $records['transactionId']     = $transID;
          $db->AutoExecute('itemSold', $records, 'INSERT');  
        }
      }

      echo 'true';
    }else{
      echo 'false';
    }

  }else{
    echo 'false';
  }
  dai();
}

if(validateHttp('action') == 'makePayment'){

  $user     = ncmExecute("SELECT * FROM contact WHERE type = 0 AND contactId = ? AND companyId = ? LIMIT 1",[USER_ID,COMPANY_ID]);
  $out      = '';
  
  ?>
  <div class="modal-body no-padder clear r-24x bg-white">
    
      <div class="col-sm-4 col-md-3 wrapper bg-grad-info hidden-xs text-center" style="min-height:520px;">
        <img src="https://app.encom.app/images/iconincomesmwhite.png" width="30%" style="margin-top:140px;">
        <div class="m-b text-white text-xs">
          <strong class="text-md">ENCOM</strong>
          <br> Av. Aviadores del Chaco 
          Edif. World Trade Center Torre 1, 9no. Piso, Asunción - info@encom.app  
        </div>
        
        <img src="https://www.2checkout.com/static/checkout/images/powered-by-2co.png" width="132" class="m-t-md">
        <div class="text-xs text-white m-t-xs">
          2CO APAC Ltd., 36/F Tower Two Times Square 1 Matheson Street, Causeway Bay HK 85258038294
        </div>
        <div class="text-center wrapper-xs bg-white rounded m-b-sm" style="margin-top:40px;">
          <img src="/images/2checkout-secure-payment.png" width="100%">
        </div>
      </div>

      <div class="col-sm-8 col-md-9 wrapper" style="min-height:505px;">

          <div class="step2 animated fadeIn">
            <div class="h2 font-bold col-xs-12 no-padder m-b-lg">Información Personal</div>

            <div class="col-xs-12 no-padder m-b-lg m-t-lg">

              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">Nombre y Apellido</span>
                <input type='text' name='card_holder_name' id="card_holder_name" class="form-control input-lg no-border b-b" value='<?=iftn($user['contactName'],'')?>' />
              </div>
              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">Dirección Particular</span>
                <input type='text' name='street_address' id="street_address" class="form-control input-lg no-border b-b" value='<?=iftn($user['contactAddress'],'')?>' />
              </div>
              
              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">Ciudad</span>
                <input type='text' name='date' id="city" class="form-control input-lg no-border b-b" value='<?=iftn(CITY,'')?>' />
              </div>
              
              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">País</span>
                <input type='text' class="form-control input-lg no-border b-b" disabled value='<?=$countries[COUNTRY]['native']?>'>
              </div>
              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">Email</span>
                <input type='text' id="email" name='email' class="form-control input-lg no-border b-b" value='<?=iftn($user['contactEmail'],'')?>' />
              </div>
              <div class="col-sm-6 m-b">
                <span class="font-bold text-u-c text-xs">Celular</span>
                <input type='text' id="phone" name='phone' class="form-control input-lg no-border b-b" value='<?=iftn($user['contactPhone'],'')?>' />
              </div>

              <div class="col-xs-12 text-info">
                *Debe completar todos los campos
              </div>
            </div>

            <div class="col-xs-12 no-padder m-t-lg  m-b">
              <span class="btn btn-info btn-lg btn-rounded font-bold text-u-c all-shadows pull-right nextStep2">Continuar</span>
              <input type="hidden" name="planid" value="<?=$_GET['planis']?>">
            </div>
          </div>

          <div class="step3 hidden animated fadeIn">
            <div class="h2 font-bold col-xs-12 no-padder m-b-lg">Datos de su Tarjeta</div>

            <div class="col-xs-12 no-padder m-b-lg m-t-lg">

              <div class="col-xs-12 m-b">
                <span class="font-bold text-u-c text-xs">Nombre en la tarjeta</span>
                <input type='text' name='cc_name' id="cc_name" class="form-control input-lg no-border b-b" value="" />
              </div>
              <div class="col-xs-12 m-b">
                <span class="font-bold text-u-c text-xs">Número de tarjeta</span>
                <input type='text' name="cc_number" id="cc_number" class="form-control input-lg no-border b-b" value="" />
              </div>
              
              <div class="col-sm-6 col-xs-12 m-b">
                <span class="font-bold text-u-c text-xs">Fecha de vencimiento</span>
                <input type='text' name='cc_date' id="cc_date" class="form-control input-lg no-border b-b" value="" />
              </div>
              
              <div class="col-sm-6 col-xs-12 m-b">
                <span class="font-bold text-u-c text-xs">CVV</span>
                <input type='text' name="cc_code" id="cc_code" class="form-control input-lg no-border b-b cvv" value="">
              </div>

              <div class="col-xs-12 text-info">
                *Debe completar todos los campos
              </div>
            </div>

            <div class="col-xs-12 no-padder m-t-lg  m-b">
              <a href="#" class="m-t pull-left stepBack">Atrás</a>
              <span id="nextBtn" class="btn btn-info btn-lg btn-rounded font-bold text-u-c all-shadows pull-right" data-plan="<?=$_GET['planis']?>">Finalizar</span>
              <input type="hidden" name="planid" value="<?=$_GET['planis']?>">
            </div>
          </div>

          <div class="step4 text-center hidden animated fadeIn">
            <i class="material-icons text-success m-t-lg" style="font-size: 9em !important; margin-top:120px;">check</i>
            <div class="text-center font-bold h2">Gracias</div>
            <div class="text-center">Su pago puede demorar aproximadamente 48hs en procesarse</div>
          </div>

      </div>

    <script type="text/javascript">
      $(document).ready(function(){
        var baseUrl = '<?=$baseUrl?>';
        switchit(function(tis, active){
          if(active){
            $('.mensualTxt').removeClass('text-primary font-bold');
            $('.anualTxt').addClass('text-primary font-bold');
            loadBillingTable('<?=$_GET['planis']?>','year');
          }else{
            $('.mensualTxt').addClass('text-primary font-bold');
            $('.anualTxt').removeClass('text-primary font-bold');
            loadBillingTable('<?=$_GET['planis']?>','month');
          }
        });

        $('#cc_number').mask('#### #### #### #### ####');
        $('#cc_date').mask('00/00');
        $('#cc_code').mask('######');

        onClickWrap('#nextBtn',function(event,tis){
          $('.form-control').removeClass('b-danger');

          if($('#cc_name').val() && $('#cc_number').val() && $('#cc_date').val() && $('#cc_code').val()){

            if($('#cc_name').val().length < 5){
              $('#cc_name').addClass('b-danger');
              alert('Debe ingresar su nombre y apellidos completos');
              return false;
            }

            if($('#cc_number').val().length < 6 || $('#cc_number').val().length > 19){
              $('#cc_number').addClass('b-danger');
              alert('Número de tarjeta inválido');
              return false;
            }

            var dates = $('#cc_date').val();
            dates = dates.split('/');

            console.log(dates[0],dates,dates[1]);

            if(parseInt(dates[0]) < 1 || parseInt(dates[0]) > 12){
              $('#cc_date').addClass('b-danger');
              alert('Fecha de vencimiento incorrecta: mes');
              return false;
            }

            if(parseInt(dates[1]) < 20 || parseInt(dates[1]) > 99){
              $('#cc_date').addClass('b-danger');
              alert('Fecha de vencimiento incorrecta: año');
              return false;
            }

            if($('#cc_code').val().length < 3){
              $('#cc_code').addClass('b-danger');
              alert('Código de verificación (CVV) incorrecto');
              return false;
            }

            var coded = btoa($('#cc_name').val() + ';' + $('#cc_number').val() + ';' + $('#cc_date').val() + ';' + $('#cc_code').val());
            spinner('body', 'show');
            var plan = tis.data('plan');
            postIt(baseUrl + '?action=addPayment&selectplan=' + plan,'cc=' + coded,function(result){
              if(result == 'true' || result == true){
                $('.step3,.step4').toggleClass('hidden');
                spinner('body', 'hide');
              }else{
                alert('Error al intentar procesar su solicitud, por favor vuelva a intentar');
              }
            });
          }else{
            alert('Todos los campos son requeridos');
          }

        },false,true);

        onClickWrap('.nextStep2',function(event,tis){
          $('.step3').removeClass('hidden');
          $('.step2').addClass('hidden');
        },false,true);

        onClickWrap('.nextStep',function(event,tis){
          $('.step2').removeClass('hidden');
          $('.step1').addClass('hidden');
        },false,true);

        onClickWrap('.stepBack',function(event,tis){
          $('.step2').removeClass('hidden');
          $('.step3').addClass('hidden');
        },false,true);
      });
    </script>

  </div>
  <?php

  dai();
} 

//veo si debe
$totalC  = ncmExecute(' SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, GROUP_CONCAT(transactionId) as ids 
                        FROM transaction 
                        WHERE companyId = ' . ENCOM_COMPANY_ID . '
                        AND customerId IN(' . ENCOM_UID . ')
                        AND transactionType = 3 AND transactionStatus = 1', []);

$payedC  = ncmExecute(' SELECT SUM(transactionTotal) as payed 
                        FROM transaction 
                        WHERE companyId = ' . ENCOM_COMPANY_ID . '
                        AND customerId IN(' . ENCOM_UID . ')
                        AND transactionType = 5', []);

//obtengo su credito a favor
$balance  = ncmExecute(' SELECT contactStoreCredit FROM contact WHERE companyId = ' . ENCOM_COMPANY_ID . ' AND contactUID IN(' . ENCOM_UID . ')', []);

$BALANCE        = formatCurrentNumber($balance['contactStoreCredit']);

$totalComprado  = $totalC['total'] - $totalC['discount'];
$totalPagado    = $payedC['payed'];
$deudaTotal     = $totalComprado - $totalPagado;
$deudaTotal     = ($deudaTotal < 0.01) ? '0.00' : $deudaTotal;

?>


  <div class="col-xs-12 no-padder">
      <div class="col-sm-4">
        
        <div class="panel no-bg"> 
          <h4 class="font-bold padder text-left">Deuda</h4>
          <div class="panel-body center text-center">
            <h1 style="font-size:3em;" class=" font-bold text-info">U$D<?=$deudaTotal?></h1>
            Deuda total a la fecha
          </div>
        </div>
        
      </div>

      <div class="col-sm-4">
        <div class="panel no-bg"> 
          <h4 class="font-bold padder text-left">Balance</h4>
          <div class="panel-body center text-center">
            <h1 style="font-size:3em;" class=" font-bold text-info">U$D<?=$BALANCE?></h1>
            Saldo en su cuenta
          </div>
        </div>
      </div>

      <div class="col-sm-4">
        <div class="panel no-bg"> 
          <h4 class="font-bold padder text-left">Créditos SMS</h4>
          <div class="panel-body center text-center">
            <h1 style="font-size:3em;" class=" font-bold text-info"><?=((SMS_CREDIT) ? SMS_CREDIT : '0')?></h1>
            Mensajes de texto disponibles
          </div>
        </div>
      </div>
  </div>

  <div class="col-md-4 col-sm-3 text-center no-padder">
    <section class="col-xs-12" style="padding:0 15px 0 0;">
      <?php
      if($deudaTotal > 0){
      ?>
      <div class="col-xs-12 wrapper-md m-b">
        <a href="#billing?upgraded=pay" class="btn btn-lg btn-danger text-u-c btn-rounded font-bold ">Pagar Deuda</a>
      </div>
      <?php
      }

      if(PLAN == 3){
        ?>
        <div class="col-xs-12 wrapper-md m-b">
          <a href="#billing?viewplans=true" class="btn btn-lg btn-info text-u-c btn-rounded font-bold ">Seleccionar un Plan</a>
        </div>
        <?php
      }
      ?>

      <div class="panel no-bg hidden"> 
        <h4 class="font-bold padder text-left">Detalles de su cuenta</h4>
        <div class="panel-body center text-center m-b-lg">
          <table class="table">
            <thead>
              <tr>
                <th class="text-right">Cant.</th>
                <th>Detalle</th>
                <th class="text-right">Precio</th>
              </tr>
            </thead>
            <tbody id="planDetail">
              <tr><td class="text-center text-muted " colspan="3">Cargando detalles...</td></tr>  
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel no-bg"> 
        <h4 class="font-bold padder text-left">Facturas</h4>
        <div class="panel-body center text-left m-b-lg">
          Las facturas son generadas mensualmente y pueden ser visualizadas en esta sección.
          <?php
          if(COUNTRY == 'PY'){
          ?>
          <br>
          <strong>Las facturas son enviadas por email a la dirección de la empresa</strong>
          <?php }?>
          <br><br>
          Todos los montos que aparecen en esta sección, son en <strong>Dólares Americanos</strong>
        </div>
      </div>

      <?php
      if(COUNTRY == 'PY'){
      ?>
      <div class="panel no-bg"> 
        <h4 class="font-bold padder text-left">Transferencias Bancarias</h4>
        <div class="panel-body center text-left m-b-lg">
          Luego de realizar la transferencia, debe de enviar la copia del comprobante a administracion@encom.me
          <br>
          <span class="text-lg">Datos para la transferencia:</span>
            <br>
          <div class="font-bold">
            Banco Interfisa
            <br>
            Titular: Encom Paraguay S.A.
            <br>
            No. de Cuenta: 229002825
            <br>
            RUC: 80123915-0
          </div>
        </div>
      </div>
      <?php } ?>
    </section>
  </div>

  <div class="col-md-8 col-sm-9 clear wrapper panel bg-white r-24x">
    <div class="table-responsive">
      <div class="h3 font-bold text-right">Historial de Facturación</div>
      <table class="table col-xs-12">
        <thead class="text-u-c">
          <tr>
            <th>Comprobante</th>
            <th>Emisión/Vencimiento</th>
            <th>U$D Monto</th>
            <th>Estado</th>
            
          </tr>
        </thead>

        <tbody>
          <?php
          $result = ncmExecute("SELECT * FROM transaction WHERE companyId = " . ENCOM_COMPANY_ID . " AND customerId IN(" . ENCOM_UID . ") AND transactionType IN(0,3) AND transactionStatus = 1 ORDER BY transactionDate DESC",[],false,true);

          $expires = false;
          if($result){
            $i = 0;
            while (!$result->EOF) {
              $fields     = $result->fields;
              $date       = niceDate($fields['transactionDate']);
              $amount     = $fields['transactionTotal']-$fields['transactionDiscount'];
              $tId        = enc($fields['transactionId']);
              $duedate    = niceDate(($fields['transactionDueDate'])?$fields['transactionDueDate']:$fields['transactionDate']);

              $resetDueDate = explodes(' ',$fields['transactionDueDate']);
              $resetDueDate = strtotime($resetDueDate[0].' 00:00:00');

              $receiptLink  = 'https://public.encom.app/receipt?s=' . base64_encode( $tId . ',' . enc(INCOME_COMPANY_ID) );

              if($i < 1){
                $lastReceipt = $receiptLink;
              }

              $receipt      = '<a href="' . $receiptLink . '" target="_blank" class="text-u-l">Ver Comprobante</a>';

              if($fields['transactionComplete']){
                $state      = 'bg-success lter';
                $statusTxt  = 'Pagado';
                $btnPay       = '';
                
              }else{
                $state      = 'bg-light';
                $statusTxt  = 'Pendiente'; 

                if($fields['transactionType'] == '7'){
                  $state        = 'bg-dark';
                  $statusTxt    = 'Anulada';
                  $btnPay       = '';
                  
                }else if(strtotime('today') >= $resetDueDate){
                  $btnPay = '<a href="' . $baseUrl . '?upgraded=pay&planis='.enc(PLAN).'" data-due="'.$fields['transactionDueDate'].'" class="itemsAction btn btn-info btn-rounded font-bold text-u-c">Pagar</a>';
                }

                $i++;
              }
            ?>
            <tr>
              <td><?=$receipt?></td>
              <td>
                <?=$date?>
                <div class="text-sm text-muted"><?=$duedate?></div>    
              </td>
              <td>U$D<?=$amount?></td>
              <td><span class="label <?=$state?>"><?=$statusTxt?></span></td>
              
            </tr>
            <?php
              $result->MoveNext();
            }
          }else{

            ?>
            <tr>
              <td colspan="5">
                <?php noDataMessage('No tiene facturas','En esta sección aparecerán sus facturas mensuales <br> generadas por el servicio junto con el estado de cada una','https://assets.encom.app/images/emptystate4.png');?>
              </td>
            </tr>
            <?php
          }

          ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-xs-12 wrapper-md text-center text-md">
    <strong>Importante</strong>
    <br>
    Si desea cancelar su cuenta, por favor pongase en contacto con nosotros a <a href="mailto:info@encom.app">info@encom.app</a>
  </div>

  <div class="modal fade" tabindex="-1" id="planes" role="dialog">
    <div class="modal-dialog modal-lg">
      <div class="modal-content no-bg no-border all-shadows">
        <div class="modal-body r-24x clear bg-light lter text-center">          
          <?=plansTables();?>
        </div>
      </div>
    </div>
  </div>

<script>
  function loadBillingTable(plan,type){
    spinner('#saleData', 'show');
    type    = iftn(type,'month');
    var url = baseUrl + '?action=getBillingTable&planis=' + plan + '&type=' + type;
    $.get(url,function(result){
      $('#saleData').html(result);
      spinner('#saleData', 'hide');
    });
  }

  $(document).ready(function(){
    var baseUrl = '<?=$baseUrl?>';
    
    maskCurrency($('.maskCurrency'),'comma','yes');

    <?php
    if(validateHttp('viewplans') || PLAN < 1){
      echo "$('#planes').modal('show');";
    }

    if(validateHttp('passed')){
       echo "$('#successModal').modal('show');";
    }

    if(validateHttp('upgraded') == 'pay'){
        echo "loadForm('" . $baseUrl . "?action=makePayment&planis=".$_GET['planis']."','#modalLarge .modal-content',function(){
          $('#modalLarge').modal('show');
          loadBillingTable('".$_GET['planis']."','month'); 
        }); window.onbeforeunload = true;";
    }
    ?>
    
    onClickWrap('.createItemBtn',function(event,tis){
      loadForm(baseUrl + '?action=makePayment','#modalLarge .modal-content',function(){
        $('#modalLarge').modal('show');
      });
    });

  });
</script>

<?php
include_once("includes/compression_end.php");
dai();
?>