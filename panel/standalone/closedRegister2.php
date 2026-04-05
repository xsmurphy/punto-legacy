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
    $result       = getSalesByPayment($drwr['drawerOpenDate'],$drwr['drawerCloseDate'],REGISTER_ID,OUTLET_ID,true);
    $expense      = ncmExecute("SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate BETWEEN ? AND ? AND registerId = ?",[$drwr['drawerOpenDate'],$drwr['drawerCloseDate'],$drwr['registerId']],true);

    define('USER_ID', $drwr['drawerUserClose']);
    define('USER_NAME', getValue('contact','contactName','WHERE contactId = ' . $drwr['drawerUserClose']));
    define('OUTLET_NAME', getValue('outlet','outletName','WHERE outletId = ' . $drwr['outletId']));
    define('OUTLET_ID', $drwr['outletId']);
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
    
    <div class="col-md-2 col-sm-1"></div>
    <div class="col-md-8 col-sm-10 no-padder">
      <div class="col-xs-12 h3 font-bold m-b">
        <?=USER_NAME?>
        <div class="font-normal text-center m-t-xs text-sm"><?=$openDate?> al <?=$closeDate?></div>
      </div>

      <div class="col-xs-12 no-padder">

          <section class="panel panel-default">
            <header class="panel-heading bg-light no-border text-u-c">
              Control de Caja 
              <ul class="nav nav-pills pull-right">  <li><a href="#!" data-toggle="collapse" data-target="#collapseOtros"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
            </header>
            <div class="alt collapse" id="collapseOtros">
              <ul class="list-group blindDrawer" id="registerClousure">
                <?php
                  $li          = '<li class="list-group-item"> <div class="clear"> <span class="pull-right total">' . formatCurrentNumber($cajaInicial) . '</span> <span class="pull-left name">Caja Inicial</span> </div> </li>';

                  if(validity($result,'array')){
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
                    $finalTotal   = (($cajaInicial + $total) - $expense['expense']);

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

                                  '<li class="list-group-item"> ' .
                                  ' <div class="clear"> ' .
                                  '   <span class="pull-right text-u-c font-bold total"> ' . 
                                        formatCurrentNumber((($cajaInicial + $cashPrice) - $expense['expense'])) . 
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
          </section>

          <section class="panel panel-default">
            <header class="panel-heading bg-light no-border text-u-c">
              Resumen de Ventas
              <ul class="nav nav-pills pull-right">  <li><a href="#!" data-toggle="collapse" data-target="#collapseResumen"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
            </header>
            <div class="alt collapse" id="collapseResumen">
              <div class="col-xs-12 panel-heading">

                <div class="col-sm-6 col-xs-12">
                  <div class="b-b text-center wrapper-md">
                    <div class="h1 m-t m-b-xs total font-bold">
                      <span class="text-muted text-lg"><?=CURRENCY?></span> 
                      <span id="globalSubtotal">6.005.000</span>
                    </div>
                    Total Vendido
                  </div>
                </div>

                <div class="col-sm-6 col-xs-12">
                  <table class="table">
                    <tbody>
                  <?php
                  $roc       = getRoc(1);
                  $roc       = str_replace(['outletId','companyId'],['b.outletId','b.companyId'],$roc);
                  $sql = "SELECT SUM(a.itemSoldUnits) as count, SUM(a.itemSoldPrice) as total, a.itemId as id, c.itemName as item
                    FROM itemSold a, transaction b, item c
                    WHERE a.itemSoldDate
                    BETWEEN ?
                    AND ?
                    " . $roc . "
                    AND b.transactionType IN(0,3)
                    AND a.transactionId = b.transactionId
                    AND a.itemId = c.itemId
                    GROUP BY item
                    ORDER BY count DESC
                    LIMIT 5";
           
                   $result    = ncmExecute($sql,[$drwr['drawerOpenDate'],$drwr['drawerCloseDate']],false,true);

                   if($result){
                     while (!$result->EOF) {
                       ?>
                       <tr>
                         <td><?=formatQty($result->fields['count']);?></td>
                         <td><?=$result->fields['item'];?></td>
                         <td><?=formatCurrentNumber($result->fields['total']);?></td>
                       </tr>
                       <?php
                       $result->MoveNext(); 
                     }
                     $result->Close();  
                   }
                  ?>
                    </tbody>
                  </table>
                </div>

              </div>

            </div>
          </section>

      </div> 
    </div>
    <div class="col-md-2 col-sm-1"></div>
    
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
  var noSessionCheck = true;
</script>
<?php
loadCDNFiles([],'js');
?>
<script>
  $(document).ready(function(){

    $.get('?action=1&s=<?=$_GET['s']?>',function(result){
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