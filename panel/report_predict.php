<?php
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
include_once("includes/secure.php");
theErrorHandler();//error handler
list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$r = (REGISTER_ID > 1)?' AND registerId = '.REGISTER_ID:'';
$o = (OUTLET_ID > 1)?' AND outletId = '.OUTLET_ID:'';

/*if(validateBool('action') == 'update' && validateBool('id',true,'post')){
	$record = array();

	$record['transactionNote'] 		= $_POST['note'];
	$record['transactionTotal'] 	= formatNumberToInsertDB($_POST['total']);
	$record['transactionDiscount'] 	= formatNumberToInsertDB($_POST['discount']);
	$record['transactionTax'] 		= formatNumberToInsertDB($_POST['tax']);
	$record['transactionDate'] 		= $_POST['date'];
	$record['customerId'] 			= $_POST['customer'];
	$record['userId'] 				= $_POST['user'];
	$record['outletId'] 			= $_POST['outlet'];

	$update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = '.$db->Prepare($_POST['id'])); 
	if($update === false){
		echo 'false|0|'.$_POST['id'];
	}else{
		echo 'true|0|'.$_POST['id'];
	}
	$db->Close();
	die();
}
if(validateBool('action') == 'delete' && validateBool('id')){
	///delete code here
	//al borrar tengo que reponer la cantidad de items compradas al items en el inventario
	
	$db->StartTrans(); //Esto hace que verifique si mas de una transaccion fallo, en el caso de que solo una falle, todas fallan
	
	$result		= $db->Execute("SELECT transactionDetails,outletId FROM transaction WHERE transactionId = ?", array($_GET['id']));
	$delete 	= $db->Execute('DELETE FROM transaction WHERE transactionId = ? LIMIT 1', array($_GET['id'])); 
	$deleteSold = $db->Execute('DELETE FROM itemSold WHERE transactionId = ?', array($_GET['id'])); 
	
	$arr 		= json_decode($result->fields['transactionDetails']);
	
	$outlet 	= $result->fields['outletId'];
	$case 		= '';
	$repeated	= array();

	for($i=0;$i<count($arr);$i++){
		$id		= $arr[$i]->itemId;
		$count 	= $arr[$i]->count;
	
		if(array_key_exists($id,$repeated)){
			$repeated[$id] += $count;
	
		}else{
			$repeated[$id] = $count;
	
		}		
	}
	
	foreach($repeated as $id => $count){
		$case 	.= "WHEN itemId = ".$id." THEN inventoryCount+".$count." ";
	}
	
	$update 	= $db->Execute('UPDATE inventory
									SET inventoryCount = CASE
										'.$case.'
										ELSE inventoryCount
									END
							WHERE outletId = ?', array($outlet));
	
	$result->Close();
	
	$db->CompleteTrans();

	if($db->HasFailedTrans()){
		echo 'false';
	}else{
		echo 'true';
	}
	$db->Close();
die();
}
*/

if(validateBool('action') == 'edit' && validateBool('id')){
	$id = $_GET['id'];
	?>
    <?php $result = $db->Execute("SELECT * FROM transaction WHERE transactionId = ? LIMIT 1",array(dec($id)));?>
    
    
    
    <!--<form action="report_sales.php?action=update&indexRow=<?=$_GET['index'];?>" method="POST" id="editSale" name="editSale">-->
    	
		<div class="row">
	        <div class="col-sm-6">
	            <label>Fecha:</label>
	            <input type="text" class="form-control error m-b" name="date" value="<?=$result->fields['transactionDate']?>" autocomplete="off" />
	            <label>Usuario:</label>
	            <?php $user = $db->Execute('SELECT * FROM user WHERE '.$SQLcompanyId);?>
	            <select name="user" tabindex="1" data-placeholder="Seleccione.." class="form-control m-b" autocomplete="off">
	               <?php while (!$user->EOF) {?>
	               <option value="<?=$user->fields['userId'];?>" <?=($user->fields['userId'] == $result->fields['userId'])?'selected':'';?>><?=$user->fields['userName'];?></option>
	               <?php 
	                $user->MoveNext(); 
	                }
	                $user->Close();
	                ?>
	            </select>
	            
	            
	            <label>Cliente:</label>
	            <?php $customer = $db->Execute('SELECT * FROM customer WHERE '.$SQLcompanyId);?>
	            <select name="customer" tabindex="1" data-placeholder="Seleccione.." class="form-control m-b" autocomplete="off">
	                <option value="0" <?=($result->fields['customerId'] == 0)?'selected':'';?>>Ninguno</option>
	               <?php while (!$customer->EOF) {?>
	               <option value="<?=$customer->fields['customerId'];?>" <?=($customer->fields['customerId'] == $result->fields['customerId'])?'selected':'';?>><?=$customer->fields['customerName'];?></option>
	               <?php 
	                $customer->MoveNext(); 
	                }
	                $customer->Close();
	                ?>
	            </select>
	            
	            <label>Sucursal:</label>
	            <?php selectInputOutlet('outlet',$result->fields['outletId']); ?>
	            
	       	</div>

	        <div class="col-sm-6">                    
	            <label>Descuento:</label>
	            <input type="text" class="form-control m-b" name="discount" value="<?=formatCurrentNumber($result->fields['transactionDiscount'])?>" autocomplete="off"/>
	            <label><?=TAX_NAME?>:</label>
	            <input type="text" class="form-control m-b" name="tax" value="<?=formatCurrentNumber($result->fields['transactionTax'])?>" autocomplete="off" />
	            
	            <label>Monto Total:</label>
	            <input type="text" class="form-control m-b" name="total" value="<?=formatCurrentNumber($result->fields['transactionTotal']-$result->fields['transactionDiscount'])?>" autocomplete="off" />
	            <?php
	            $paymentTypeData = '';
				$paymentType = json_decode($result->fields['transactionPaymentType']);
				
				for($i=0;$i<count($paymentType);$i++){
					$paymentTypeData .= "<tr><td><strong>".$paymentType[$i]->name."</strong></td><td>".CURRENCY.formatCurrentNumber($paymentType[$i]->price)."</td></tr>";
				}
				
				?>
	            <table class="table">
	            	<tbody>
	                	<?=$paymentTypeData?>
	                </tbody>
	            </table>
	            
	        </div>
	    </div>
	    <div class="row">
	        <div class="col-sm-12">
	            <label>Nota:</label>
	            <input type="text" class="form-control" name="note" value="<?=$result->fields['transactionNote']?>" autocomplete="off" />
	            <input type="hidden" name="id" value="<?=$result->fields['transactionId'];?>" autocomplete="off" />
	        </div>
	    </div>
	    <div class="row m-t">
	    	<div class="col-sm-12">
	            <table class="table">
	                <thead>
	                    <tr>
	                        <th>Unidades</th>
	                        <th>Nombre</th>
	                        <th>Descuento</th>
	                        <th><?=TAX_NAME?></th>
	                        <th>Total</th>
	                        <th></th>
	                    </tr>
	                </thead>
	                <tbody>
	                    <?php 
	                        $items 	= $db->Execute("SELECT * FROM itemSold WHERE transactionId = ".$result->fields['transactionId']);
	                        while (!$items->EOF) {
	                            $units 		= formatCurrentNumber($items->fields['itemSoldUnits']);
	                            $name 		= getTableObjectName($items->fields['itemId'], 'item');
	                            $discount	= formatCurrentNumber($items->fields['itemSoldDiscount']);
	                            $tax 		= formatCurrentNumber($items->fields['itemSoldTax']);
	                            $total 		= formatCurrentNumber($items->fields['itemSoldTotal']-$items->fields['itemSoldDiscount']);
	                    ?>
	                        <tr>
	                            <td><?=$units?></td>
	                            <td><?=$name?></td>
	                            <td><?=CURRENCY.$discount?></td>
	                            <td><?=CURRENCY.$tax?></td>
	                            <td><?=CURRENCY.$total?></td>
	                            <td></td>
	                        </tr>
	                    <?php
	                        $items->MoveNext();
	                    }
	                    
	                    $items->Close();
	                    ?>
	                </tbody>
	            </table>
	        </div>
		</div>

		<div class="row">
		    <div class="col-xs-12 m-t">
			    <button class="btn btn-default cancelItemView m-r  pull-right">Cancelar</button>
			</div>
		</div>

   <!-- </form>-->
    <?php
	$db->Close();
die();
}

if(validateBool('action') == 'detailTable'){
	$saleDay 	= $db->Execute("SELECT * 
								FROM transaction 
								WHERE transactionDate 
								BETWEEN ? 
								AND ? 
								AND transactionType = 'end'
								".$r."
								".$o."  
								AND ".$SQLcompanyId." 
								ORDER BY transactionDate 
								DESC", array($startDate,$endDate));
	$table = '<thead class="text-u-c">
				<tr>
					<th class="sort" data-sort="date">Fecha</th>
					<th class="sort" data-sort="user">Vendedor/a</th>
					<th class="sort hidden-xs" data-sort="customer">Cliente</th>
					<th class="sort text-right" data-sort="total">Total</th>
				</tr>
			</thead>
			<tbody class="list">';
	while (!$saleDay->EOF) {
		$paymentTypeName = '';
		$paymentType = json_decode($saleDay->fields['transactionPaymentType']);
		
		for($i=0;$i<count($paymentType);$i++){
			$paymentTypeName .= $paymentType[$i]->name."/";
		}

		$itemId = enc($saleDay->fields['transactionId']);
		
		//$discount = abs($saleDay->fields['transactionDiscount']);
		//$total = ($saleDay->fields['transactionTotal'] < 1)?0:$saleDay->fields['transactionTotal']-$discount;

		$fecha 			=  niceDate($saleDay->fields['transactionDate']);
		$customerTabe 	= getRealCustomerId($saleDay->fields['customerId']);
		if($customerTabe == 'customerUID'){
			$customer = getValue('customer', 'customerName', 'WHERE customerUID = '.$saleDay->fields['customerId']);
		}else{
			$customer = getTableObjectName($saleDay->fields['customerId'], 'customer','',1);
		}
		$customer = ($customer == '0')?'':$customer;

		$table .= '<tr data-id="'.$itemId.'" data-load="?action=edit&id='.$itemId.'">'; 
			$table .= '<td class="date"> '.$fecha.' </td>';
			$table .= '<td class="user"> '.getTableObjectName($saleDay->fields['userId'], 'user','',1).' </td>';
			$table .= '<td class="customer  hidden-xs"> '.$customer.' </td>';
			$table .= '<td class="total text-right"> '.CURRENCY.formatCurrentNumber($saleDay->fields['transactionTotal']-$saleDay->fields['transactionDiscount']).' </td>';
		$table .= '</tr>';
					
		$saleDay->MoveNext();
	}
	
	$table .= '</tbody>';

	$table = '<table>'.$table.'</table>';

	$saleDay->Close();
	$db->Close();
	die($table);

}

if(validateBool('action') == 'generalTable'){
	//--
	$result 	= $db->Execute("SELECT transactionDate, 
									SUM(transactionUnitsSold), 
									COUNT(transactionDate), 
									SUM(transactionDiscount), 
									SUM(transactionTax), 
									SUM(transactionTotal) 
								  FROM transaction 
								 WHERE transactionDate >= ?
								   AND transactionDate < ? 
								   AND transactionType = 'end'
								   ".$r."
								   ".$o."  
								   AND ".$SQLcompanyId."
							  GROUP BY CAST(transactionDate AS DATE)
							  ORDER BY transactionDate DESC",array($startDate,$endDate));
								
	$COGS 	= getItemsCOGS($startDate,$endDate,true);
	$OC		= getOperatingCost(OUTLET_ID);

	

	$table = '<thead class="text-u-c">
				<tr>
					<th class="sort" data-sort="date">Fecha</th>
					<th class="sort hidden-xs" data-sort="usold">U. Vendidas</th>
					<th class="sort" data-sort="sales">Nro. de Ventas</th>
					<th class="sort text-right hidden-xs" data-sort="discount">Descuentos</th>
					<th class="sort text-right" data-sort="total">Total</th>
				</tr>
			</thead>
			<tbody class="list">';
  	
	
	
	while (!$result->EOF) {
		
		$fecha 		=  niceDate($result->fields[0]);
		$uSold 		= $result->fields[1];
		$sales 		= $result->fields[2];
		//$tax 		= $result->fields[4];
		$discount 	= $result->fields[3];
		$total 		= $result->fields[5]-$discount;
		//$COGSandOC 	= $COGS[$dateOnly]+$OC;
		$itemId = enc($result->fields[0]);

		$table .= '<tr> <td class="date"> '.$fecha.' </td>';

		$table .= '<td class="usold hidden-xs"> '.$uSold.' </td>';
		$table .= '<td class="sales"> '.$sales.' </td>';
		$table .= '<td class="dicount text-right hidden-xs"> '.CURRENCY.formatCurrentNumber($discount).' </td>';

		$table .= '<td class="total text-right"> '.CURRENCY.formatCurrentNumber($total).' </td> </tr>';
		
		$result->MoveNext();
	}

	$table .= '</tbody>';

	$table = '<table>'.$table.'</table>';
	
	$result->Close();

	$db->Close();
	die($table);
	//
}

//CHARTS
$startDateCompare 	= date('Y-m-d',strtotime($startDate));
$endDateCompare 	= date('Y-m-d',strtotime($endDate));

$COGS 	= getItemsCOGS($startDate,$endDate,true);
$OC		= 0;//getOperatingCost(OUTLET_ID);						   
$sameDay = false;
if($startDateCompare == $endDateCompare){
	$COGS 	= getItemsCOGS($startDate,$endDate,true,true);
	
	/*$chart 	= $db->Execute("SELECT transactionDate,
								transactionDiscount, 
								transactionTax, 
								transactionTotal,
								transactionUnitsSold
							FROM transaction 
							WHERE transactionDate >= ?
							   AND transactionDate < ?
							   AND transactionType = 'end'
							   AND ".$SQLcompanyIdANDoutletId."
							   
							   ORDER BY transactionDate ASC
							   ",array($startDate,$endDate));*/

	$chart 	= $db->Execute("SELECT transactionDate,
								  SUM(transactionDiscount) AS discount,
								  SUM(transactionTax) AS tax,
								  SUM(transactionTotal) AS total,
								  SUM(transactionUnitsSold) AS usold,
								  EXTRACT(YEAR from transactionDate) AS year,
								  EXTRACT(MONTH from transactionDate) AS month,
								  EXTRACT(DAY from transactionDate) AS day,
								  EXTRACT(HOUR from transactionDate) AS hour,
								  EXTRACT(MINUTE from transactionDate) AS minute
							FROM transaction
							WHERE transactionDate
								BETWEEN ?
								AND ?
								AND transactionType = 'end'
								".$r."
								".$o."  
							   	AND ".$SQLcompanyId."
							GROUP BY hour ASC", array($startDate,$endDate));

	//$chart 	= $db->Execute("SELECT * FROM transaction WHERE transactionDate BETWEEN ? AND ? AND transactionType = 'end' AND ".$SQLcompanyIdANDoutletId, array($startDate,$endDate));
	$sameDay = true;				   
}else{
	
	$chart 	= $db->Execute("SELECT transactionDate,
								SUM(transactionDiscount), 
								SUM(transactionTax), 
								SUM(transactionTotal),
								COUNT(transactionId),
								SUM(transactionUnitsSold)
							FROM transaction 
							WHERE transactionDate
								BETWEEN ?
								AND ?
							   AND transactionType = 'end'
							   ".$r."  
							   ".$o."
							   AND ".$SQLcompanyId."
							   GROUP BY DATE(transactionDate) 
							   ORDER BY transactionDate ASC
							   ",array($startDate,$endDate));
							   
//							"SELECT transactionDate, SUM(transactionTotal) FROM transaction WHERE transactionDate BETWEEN '2014-06-01' AND '2014-08-11' AND transactionType = 'end' GROUP BY CAST(transactionDate AS DATE) ORDER BY transactionDate ASC"
}


//VER DE HACER ALGO PARA LLENAR LOS GAPSM, CALCULANDO EL START DATE Y EL END DATE

$totalDiscount 	= '';
$totalTax		= '';
$fullTotal		= '';
$totalEatnings 	= '';

$recordCount 	= $chart->RecordCount();

$hoursArray = array('01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24');


if(count($calendar) == 1){
	$calendar = $hoursArray;
	$isDay = true;
}else{
	$isDay = false;
}

$byweek = array();

$z=0;
$x=0;
while($z<count($calendar)){
	
	if($isDay){
		$fullDate 	= ($chart->fields['transactionDate'])?$chart->fields['transactionDate']:0;

		if($fullDate != 0){
			$date		= date('Y-m-d',strtotime($fullDate));
			$h			= date('H',strtotime($fullDate));
			$mi			= date('i',strtotime($fullDate));	
		}else{
			$h = 0;
		}

		$tax 			= 0;
		$discount 		= 0;
		$total 			= 0;
		$units 			= 0;
		$dayTotal 		= 0;
		$dayEarnings 	= 0;

		if($calendar[$z] == $h){
			$COGSandOC			= $COGS[$h];

			$tax 				= $chart->fields['tax'];
			$discount 			= $chart->fields['discount'];
			$total 				= $chart->fields['total'];
			$sales				= $recordCount;
			$units				= $chart->fields['usold'];
			$dayTotal 			= $total-$discount;
			$dayEarnings 		= round(($dayTotal-$tax)-$COGSandOC);
			
			$dataDisc 	.= $discount.',';
			$dataTax 	.= $tax.',';
			$dataTotal 	.= $dayTotal.',';
			$dataEarn 	.= $dayEarnings.',';

			$labels 	.= '"'.$h.':00",';
			
			$chart->MoveNext();
			$x++;
		}else{
			$dataDisc 	.= '0,';
			$dataTax 	.= '0,';
			$dataTotal 	.= '0,';
			$dataEarn 	.= '0,';

			$labels 	.= '"'.$calendar[$z].':00",';
		}

		//echo $calendar[$z].' == '.$h.' '.$total.' date: '.$fullDate.' - ';
	
		$z++;
	
	}else{

		$fullDate 	= $chart->fields['transactionDate'];
		$date		= date('Y-m-d',strtotime($fullDate));
		$w			= date('N',strtotime($fullDate));

		$COGSandOC			= $COGS[$date];
		//print_r($COGS);
		
		$tax 				= $chart->fields[2];
		$discount 			= $chart->fields[1];
		$total 				= $chart->fields[3];
		$sales				= $chart->fields[4];
		$units				= $chart->fields[5];
		$dayTotal 			= $total-$discount;
		$dayEarnings 		= round(($dayTotal-$tax)-$COGSandOC);

		if($date == $calendar[$z]){
			$labels 	.= '"'.$date.'",';

			$dataDisc 	.= $discount.',';
			$dataTax 	.= $tax.',';
			$dataTotal 	.= $dayTotal.',';
			$dataEarn 	.= $dayEarnings.',';

			$byweek[$w] = $byweek[$w]+$dayTotal;

			$chart->MoveNext();
		}else{
			$labels 	.= '"'.$calendar[$z].'",';

			$dataDisc 	.= '0,';
			$dataTax 	.= '0,';
			$dataTotal 	.= '0,';
			$dataEarn 	.= '0,';
		}

		$z++;

	}

	$totalDiscount 	+= $discount;
	$totalTax		+= $tax;
	$fullTotal		+= $dayTotal;
	$totalEatnings 	+= $dayEarnings;
	$fullUnits		+= $units;
	$fullSales		+= $sales;
	
}


$chartTotal = '{
		label: "Total",
        fillColor: "rgba(23, 195, 229, 0.1)",
        strokeColor: "rgba(23, 195, 229, 1)",
        pointColor: "rgba(23, 195, 229, 1)",
        pointStrokeColor: "#17c3e5",
        pointHighlightFill: "#fff",
        pointHighlightStroke: "rgba(220,220,220,1)",
        data: ['.$dataTotal.']
	    },';

$chartDisc = '{ //#778490
		label: "Descuentos",
        fillColor: "rgba(119, 132, 144, 0.2)",
        strokeColor: "rgba(119, 132, 144, 1)",
        pointColor: "rgba(119, 132, 144, 1)",
        pointStrokeColor: "#778490",
        pointHighlightFill: "#fff",
        pointHighlightStroke: "rgba(220,220,220,1)",
        data: ['.$dataDisc.']
	    },';

$chartTax = '{//#5a6a7a
		label: "Impuestos",
        fillColor: "rgba(90, 106, 122, 0.2)",
        strokeColor: "rgba(90, 106, 122, 1)",
        pointColor: "rgba(90, 106, 122, 1)",
        pointStrokeColor: "#5a6a7a",
        pointHighlightFill: "#fff",
        pointHighlightStroke: "rgba(220,220,220,1)",
        data: ['.$dataTax.']
	    },';

$chartEarn = '{//#232c32
		label: "Ganancias",
        fillColor: "rgba(47, 57, 64, 0.2)",
        strokeColor: "rgba(47, 57, 64, 1)",
        pointColor: "rgba(47, 57, 64, 1)",
        pointStrokeColor: "#2f3940",
        pointHighlightFill: "#fff",
        pointHighlightStroke: "rgba(220,220,220,1)",
        data: ['.$dataEarn.']
	    },';

$out = $chartTotal.$chartEarn.$chartTax.$chartDisc;

$chart->Close();
//--



$submenu 	= 'reports_sales';
$menu 		= 'reports';
?>
<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>Predicciones | Income POS</title>

<!-- core styles -->
<link rel="stylesheet" href="css/bootstrap.css">
<link rel="stylesheet" href="css/font-awesome.min.css" type="text/css" />
<link rel="stylesheet" href="css/simple-line-icons.css" type="text/css" />
<link rel="stylesheet" href="css/font.css" type="text/css" />
<link rel="stylesheet" href="css/app.css" type="text/css" />  
<link rel="stylesheet" href="css/style.css" type="text/css" />  
<link rel="stylesheet" href="css/datepicker.css" type="text/css" />  

<!-- /core styles -->
<script type="text/javascript" src="scripts/jquery.min.js"></script>
<style>
	.line-legend{
		list-style: none;
	}
	.line-legend li{
		float: left;
		vertical-align: middle;
		margin-right: 10px;
	}
	.line-legend li span{
		display: inline-block;
		width: 10px;
		height: 10px;
		margin-right: 5px;
	}
</style>
</head>
<body>

<section class="vbox bg-light lter">
  <?=head(true,true);?>
  
  <section id="content">
    <section class="vbox">

    	<?=menuReports();?>
      
      <section class="wrapper">
      	<div class="col-xs-12">
	      	<div class="row m-b hidden-xs">
	      		<div class="row">
	      			<div class="col-sm-6 m-b-xs">
	      				<?=dateButtons();?>
	      				<?=customDateRangeForm()?>
	      			</div>
	      			<div class="col-sm-6 m-b-xs">
		      			<div class="pull-right" id="legendDiv"></div>
		      		</div>
	      		</div>

	      		<canvas id="myChart" width="200" height="50"></canvas>	      		
	      	</div>
	      	

	      	<hr class="bg-light dker">

	      	<div class="row text-center">
	      		<section class="col-sm-3">
	                <h4 class="font-thin">Ingresos</h4>
	                <h2 class="m-n text-info"><?=CURRENCY?><?=formatCurrentNumber($fullTotal)?></h2>
                </section>
                <section class="col-sm-3">
	                <h4 class="font-thin">Descuentos</h4>
	                <h2 class="m-n"><?=CURRENCY?><?=formatCurrentNumber($totalDiscount)?></h2>
                </section>
                <section class="col-sm-3">
	                <h4 class="font-thin">Impuestos (<?=TAX_NAME?>)</h4>
	                <h2 class="m-n"><?=CURRENCY?><?=formatCurrentNumber($totalTax)?></h2>
                </section>
                <section class="col-sm-3">
	                <h4 class="font-thin">Ganancias (<?=formatCurrentNumber(($totalEatnings/$fullTotal)*100)?>%)</h4>
	                <h2 class="m-n"><?=CURRENCY?><?=formatCurrentNumber($totalEatnings)?></h2>
                </section>
	      	</div>

	      	<hr class="bg-light dker">

	      	<div class="row  hidden-xs">
	      		<div class="col-sm-3 text-center">
	      			<h4 class="font-thin">Tipos de pago</h4>
	      			
            			<canvas id="chart-area" width="250" height="150" class="m-t" ></canvas>	
            		
	      		</div>

	      		
	      		<div class="col-sm-6 text-center">
	      			<h4 class="font-thin">Día de la semana</h4>
            		<canvas id="myBar" width="200" height="50"></canvas>	
	      		</div>
	      		
	      	</div>

	      	<hr class="bg-light dker">

      		<div class="row">
	            <section class="col-sm-12 no-padder">
		            <ul class="nav nav-tabs">
		                <li class="active">
		                    <a href="#tab1" data-toggle="tab">General</a>
		                </li>
		                <li>
		                    <a href="#tab2" data-toggle="tab">Detallado</a>
		                </li>
		            </ul>
		            <section class="panel">
		                <div class="panel-body">
		                    <div class="tab-content">
		                        <div class="tab-pane active overflow-auto" id="tab1"> 
		                        	<div class="col-md-9  col-sm-6">
		                        	</div>
		                        	<div class="col-md-3  col-sm-6">
										<input type="text" class="form-control rounded pull-right search" placeholder="Buscar...">
									</div>                                   	
		                        	
		                            <table class="table table1">
		                                
		                            </table>
		                        </div>
		                        <div class="tab-pane overflow-auto col-xs-12" id="tab2">
		                        	<div class="col-sm-12" id="tabIn" style="margin:none;">
			                        	<div class="col-md-9  col-sm-6">
			                        		
			                        	</div>
			                        	<div class="col-md-3  col-sm-6">
											<input type="text" class="form-control rounded pull-right search" placeholder="Buscar...">
										</div>    
			                            <table class="table table2">
			                                
			                            </table>
		                            </div>
		                            <div class="col-sm-6 well bg-light lter wrapper" id="formItemSlot" style="display:none;">

									</div>
		                        </div>
		                        
		                    </div>
		                </div>
		            </section>
	            </section>
	        </div>


      	</div>
      </section>
    </section>
  </section>
</section>

<script type="text/javascript" src="scripts/bootstrap.min.js"></script>
<script type="text/javascript" src="scripts/chartjs.js"></script>
<script type="text/javascript" src="scripts/app.js"></script>
<script type="text/javascript" src="scripts/datatables.js"></script>
<script type="text/javascript" src="scripts/common.js"></script>
<script type="text/javascript" src="scripts/bsdatepicker.js"></script>

<script>
	$(document).ready(function(){
		$('#datepicker').datepicker();

		showTable('#tab1','.table1',"?action=generalTable&from=<?=$startDate?>&to=<?=$endDate?>",0);
		showTable('#tab2','.table2',"?action=detailTable&from=<?=$startDate?>&to=<?=$endDate?>",0);
		
		onClickWrap('.table2 tbody tr',function(event,tis){
			var load = tis.attr('data-load');
			loadForm(load,'#formItemSlot');
			openCloseFormPanel('open','#tabIn','#formItemSlot', function(){ 
				$('.table tbody tr').removeClass('bg-info');
				tis.addClass('bg-info');
			});
		});

		onClickWrap('.cancelItemView',function(event,tis){
			openCloseFormPanel('close','#tabIn','#formItemSlot', function(){ 
				$('.table tbody tr').removeClass('bg-info');
			});
		});

		onClickWrap('.toggleDates',function(event,tis){
			var target = tis.attr('data-target');
			$(target).toggle();
		});

		var options = {
					    responsive: true,
					    pointDotStrokeWidth : 2
					};

		var data = {
		    labels: [<?=$labels?>],
		    datasets: [<?=$out?>]
		};

		var ctx = document.getElementById("myChart").getContext("2d");

		var myLineChart = new Chart(ctx).Line(data, options);
		document.getElementById("legendDiv").innerHTML = myLineChart.generateLegend();

		<?php
		    $val = getPaymentTypes($startDate,$endDate);
		    $keys = array('Efectivo','T. de Crédito','T. de Débito','Cheque','Otros');
		    $colors = array('#4cb6cb','#2f3940','#405161','#778490','#d7e5e8');
			
			for($z=0;$z<count($val);$z++){
				$doughnut .= '
		                {
		                  value: "'.$val[$z].'",
		                  color:"'.$colors[$z].'",
		              
		                  label: "'.$keys[$z].'"
		                },';
			}
		?>

		var doughnutData = [
		        <?=$doughnut;?>
		      ];
		var options = {animateRotate : false};
		
	  	var ctx2 = document.getElementById("chart-area").getContext("2d");
	  	var myDoughnut = new Chart(ctx2).Doughnut(doughnutData,options);
		

		<?php
		ksort($byweek);
		$days = array('1'=>'Lunes','2'=>'Martes','3'=>'Miercoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sabado','7'=>'Domingo');
		$week = $byweek + array_fill(1, 7, 0);

		for($i=1;$i<count($days)+1;$i++){
			$label .= '"'.$days[$i].'",';

			$data .= $week[$i].',';
		}

		//print_r($byweek);
		?>
		var data = {
		    labels: [<?=$label?>],
		    datasets: [
		        		{
				            label: "Semana",
				            fillColor: "#4cb6cb",
				            strokeColor: "#4cb6cb",
				            highlightFill: "#4cb6cb",
				            highlightStroke: "#fff",
				            data: [<?=$data?>]
				        }
		    ]
		};

		var options = {
					    responsive: true
					};


		var ctx3 = document.getElementById("myBar").getContext("2d");

		var myBarChart = new Chart(ctx3).Bar(data, options);

});
</script>
	
</body>
</html>
<?php
$db->Close();
?>