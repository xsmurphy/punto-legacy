<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('DRAWER_ID', dec($data[1])); 

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);


date_default_timezone_set(TIMEZONE);

define(TODAY, date('Y-m-d H:i:s'));

if(validateHttp('action')){

  $cajaInicial= 0;
  $result     = [];
  $drwr       = ncmExecute("SELECT *
                            FROM drawer 
                            WHERE drawerId = ?
                            AND companyId = ?
                            LIMIT 1",[DRAWER_ID,COMPANY_ID],true);

  if($drwr){
    $cajaInicial  = $drwr['drawerOpenAmount'];
    $cajaFinal    = $drwr['drawerCloseAmount'];
    $openDate     = niceDate($drwr['drawerOpenDate'],true);
    $closeDate    = niceDate($drwr['drawerCloseDate'],true);
    $result       = getSalesByPayment($drwr['drawerOpenDate'],$drwr['drawerCloseDate'],$drwr['registerId'],$drwr['outletId']);
    $expense      = ncmExecute("SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate BETWEEN ? AND ? AND type IS NULL AND registerId = ?",[$drwr['drawerOpenDate'],$drwr['drawerCloseDate'],$drwr['registerId']],true);
    $income       = ncmExecute("SELECT SUM(expensesAmount) as income FROM expenses WHERE expensesDate BETWEEN ? AND ? AND type = 1 AND registerId = ?",[$drwr['drawerOpenDate'],$drwr['drawerCloseDate'],$drwr['registerId']],true);

    define('USER_ID', $drwr['drawerUserClose']);
    define('USER_NAME', iftn(getValue('contact','contactName','WHERE contactId = ' . $drwr['drawerUserClose']),'') );
    define('OUTLET_NAME', getValue('outlet','outletName','WHERE outletId = ' . $drwr['outletId']));
    define('REGISTER_NAME', getValue('register','registerName','WHERE registerId = ' . $drwr['registerId']));
  }
  ?>
  
  <div class="col-xs-12 wrapper bg-light lter"> 
    <div class="m-b-lg text-center col-xs-12">
      <a href="#" class="thumb-md animated fadeInDown"> 
        <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
      </a>
    </div>
    <div class="col-xs-12 h2 font-bold text-dark text-center animated fadeIn"><?=COMPANY_NAME?></div>
    <div class="text-center h4 col-xs-12 m-b"><?=REGISTER_NAME?> - <?=OUTLET_NAME?></div> 
    

    <div class="col-md-6 col-md-offset-3 col-sm-10 col-sm-offset-1 col-xs-12 no-padder">
      <div class="col-xs-12 h3 font-bold m-b">
        <?=USER_NAME?>
        <div class="font-normal text-center m-t-xs text-sm"><?=$openDate?> al <?=$closeDate?></div>
      </div>

      <div class="col-xs-12 wrapper panel r-24x">

          <div class="col-xs-12 no-padder">            
            <ul class="list-group blindDrawer" id="registerClousure">
            <?php
            $li          = '<li class="list-group-item"> <div class="clear"> <span class="pull-right total">' . formatCurrentNumber($cajaInicial) . '</span> <span class="pull-left name">Caja Inicial</span> </div> </li>';

            if(validity($result,'array')){
              $total = 0;
              foreach ($result as $arr){
                $name     = str_replace('u00e9','é',$arr['name']);
                $type     = $arr['type'];
                $price    = $arr['price'];

                if($type == 'cash'){
                  $cashPrice  = $price;
                }

                $total += $price;
                
                $li .=  '<li class="list-group-item">' .
                        ' <div class="clear">' .
                        '   <span class="pull-right total">' .
                              formatCurrentNumber($price,$dec,$ts) .
                        '   </span>' .
                        '   <span class="pull-left name">' . 
                              $name . 
                        '   </span>' .
                        ' </div>' .
                        '</li>';
              }
              $finalTotal   = (($cajaInicial + $total + $income['income']) - $expense['expense']);

              if($finalTotal > $cajaFinal){//si falta plata
                $faltante = '<span class="text-danger">-' . formatCurrentNumber($finalTotal - $cajaFinal) . '</span>';
              }else if($cajaFinal > $finalTotal){//si sobra plata
                $faltante = '<span class="text-default">' . formatCurrentNumber($cajaFinal - $finalTotal) . '</span>';
              }else{//cierre ok
                $faltante = '<span class=""><i class="material-icons text-success">check</i></span>';
              }
            }

            $li          .= '<li class="list-group-item">' .
                            ' <div class="clear">' .
                            ' <span class="pull-right total">' . 
                                formatCurrentNumber($expense['expense'],$dec,$ts) . 
                            ' </span> ' .
                            ' <span class="pull-left name">Extracciones (Efectivo)</span> ' .
                            ' </div>' .
                            '</li>' .

                            '<li class="list-group-item">' .
                            ' <div class="clear">' .
                            ' <span class="pull-right total">' . 
                                formatCurrentNumber($income['income'],$dec,$ts) . 
                            ' </span> ' .
                            ' <span class="pull-left name">Ingresos (Efectivo)</span> ' .
                            ' </div>' .
                            '</li>' .

                            '<li class="list-group-item"> ' .
                            ' <div class="clear"> ' .
                            '   <span class="pull-right text-u-c font-bold total"> ' . 
                                  formatCurrentNumber((($cajaInicial + $cashPrice + $income['income']) - $expense['expense'])) . 
                            '   </span>' .
                            '   <span class="pull-left text-u-c font-bold name">Total de efectivo:</span> </div>' . 
                            '</li>' .

                            '<li class="list-group-item">' .
                            ' <div class="clear">' . 
                            '   <span class="pull-right text-u-c h3 total font-bold">' . 
                                  formatCurrentNumber($finalTotal) . 
                            '   </span> ' .
                            '   <span class="pull-left text-u-c h3 name font-bold">Total:</span> ' .
                            ' </div> ' . 
                            '</li>' .

                            '<li class="list-group-item"> ' .
                            ' <div class="clear"> ' .
                            '   <span class="pull-right text-u-c font-bold total"> ' . 
                                  formatCurrentNumber($cajaFinal) . 
                            '   </span>' .
                            '   <span class="pull-left text-u-c font-bold name">Declarado en el cierre:</span> </div>' . 
                            '</li>' .

                            '<li class="list-group-item"> ' .
                            ' <div class="clear"> ' .
                            '   <span class="pull-right text-u-c font-bold"> ' . 
                                  $faltante . 
                            '   </span>' .
                            '   <span class="pull-left text-u-c font-bold name">Diferencia:</span> </div>' . 
                            '</li>';

            echo $li;
            ?>
            </ul>
          </div>
      </div> 
    </div>
    
  </div>
  <?php
  dai();
}
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Cierre de Caja</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
<div id="results" class="col-xs-12 no-padder">
  <div class="col-xs-12 font-bold text-center h2 text-u-c col-xs-12 wrapper-lg">Cargando</div> 
</div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles([],'js');
?>
<script>
  $(document).ready(function(){

    $.get('?action=1&s=<?=$_GET['s']?>&test=<?=$_GET['test']?>',function(result){
      if(result){
        $('#results').html(result);
        $('[data-toggle="tooltip"]').tooltip();
      }
    });
    
  });
</script>

</body>
</html>
<?php
dai();
?>