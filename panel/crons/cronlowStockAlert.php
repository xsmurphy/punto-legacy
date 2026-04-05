<?php
include_once './cronHead.php';

//defino la hora a enviarse el email o SMS
$timeToAlert 	= 9; //9:00 am
$allowedPlans 	= '1,2,4,5,8,9,10';
$in 			= [];
$itemsIds 		= '';
$outletsIds 	= '';
$values 		= [];

$c = 0;
$e = 0;

//if(date('H') >= $timeToAlert){
//obtengo todas las companies con plan starter y company
//EJ: SELECT companyId FROM company WHERE companyPlan IN (1,2)
$company = ncmExecute("SELECT
							companyId
						FROM company
						WHERE
							companyStatus = 'Active'
						AND	companyPlan IN (" . $allowedPlans . ") ORDER BY companyId ASC");
if($company){
	while (!$company->EOF) {
		$fields = $company->fields;
	   	$in[] 	= $fields['companyId'];
	   	$c++;

	   	$company->MoveNext(); 
	}
	$company->Close();
	$in = implodes(',', $in);
}else{
	dai();
}

//$in = '10'; //PARA EL TEST

//obtengo todo el inventario
$inventory = ncmExecute(
'SELECT a.companyId as company, a.outletId as outlet, b.itemId as item, b.stockTriggerCount as triger, c.count as count
FROM outlet a, stockTrigger b, ( SELECT SUM(inventoryCount) as count, itemId, outletId
                                FROM inventory 
                                WHERE companyId IN(' . $in . ') 
                                 AND inventoryCount > -1
                                GROUP BY itemId, outletId
                               ) c
WHERE a.companyId IN(' . $in . ') 
AND a.outletId 	= b.outletId
AND b.itemId 	= c.itemId
AND (c.count < b.stockTriggerCount + 1)
AND b.outletId 	= c.outletId
ORDER BY  b.itemId DESC',
false,false,true);

if($inventory){
	$comps = [];
	while(!$inventory->EOF) {
		$invent 		= $inventory->fields;
		$cmp 			= $invent['company'];
		$out 			= $invent['outlet'];
		$cnt 			= $invent['count'];
		$itm 			= $invent['item'];

		$itemsIds 		.= $itm . ',';
		$outletsIds 	.= $out . ',';		
		
	   $inventory->MoveNext(); 
	}
}else{
	dai();
}


//Nombres & emails
$itemsIds 	= rtrim($itemsIds,',');
$outletsIds = rtrim($outletsIds,',');

//ITEM NAMES
$itemsNames = ncmExecute("	SELECT
								itemName,
								itemId
							FROM item
							WHERE
								itemId IN (" . $itemsIds . ")",false,false,true);

if($itemsNames){
	while (!$itemsNames->EOF) {
		$fields = $itemsNames->fields; 
	   	$itemName[$fields['itemId']] = $fields['itemName'];
	   	$itemsNames->MoveNext(); 
	}
}
$itemsNames->Close();
//ITEM

//OUTLETS
$outletsNames = ncmExecute("SELECT
							outletName,
							outletId
						FROM outlet
						WHERE
							outletId IN (" . $outletsIds . ")",false,false,true);

while (!$outletsNames->EOF) {
	$fields = $outletsNames->fields; 
   	$outletName[$fields['outletId']] = $fields['outletName'];
   	$outletsNames->MoveNext(); 
}
$outletsNames->Close();
//OUTLETS


//EMAILS
$email = ncmExecute("SELECT
							contactEmail,
							companyId
						FROM contact
						WHERE
							companyId 
						IN (" . $in . ") 
						AND type = 0 
						AND main = 'true' 
						ORDER BY companyId ASC",false,false,true);

while (!$email->EOF) {
	$fields = $email->fields; 
   	$emailA[$fields['companyId']] = $fields['contactEmail'];

   	//BUILD TABLE
	$inventory->MoveFirst();

	$count 	= 0;
	$table 	= 	'<table border="0" cellpadding="10" cellspacing="0" style="width:100%;">' . '<tbody>' .
				'<tr>' . 
				'	<th align="left">Artículo y Sucursal</th>' . 
				'	<th align="right">Stock Mínimo</th>' . 
				'	<th align="right">Stock Actual</th>' . 
				'</tr>';
	$cItm 	= '';
	$go 	= false;
	while(!$inventory->EOF) {

		$invent 		= $inventory->fields;
		$cmp 			= $invent['company'];
		$out 			= $invent['outlet'];
		$cnt 			= $invent['count'];
		$itm 			= $invent['item'];
		$tgr 			= $invent['triger'];

		if($fields['companyId'] == $cmp){

			if($cItm != $itm){
				$cItm 	= $itm;
				$table .= 	'<tr>' . 
							'	<th colspan="3" align="left">'.$itemName[$itm].'</th>' . 
							'</tr>';
				$count++;
			}

			$table .= 	'<tr>' .
						'	<td align="left">'.$outletName[$out].'</td>' .
						'	<td align="right">'.formatCurrentNumber($tgr,'no').'</td>' .
						'	<td align="right">'.formatCurrentNumber(($cnt > 0) ? $cnt : 0,'no').'</td>' .
						'</tr>';

			$go 	= true;
		}
		
	   $inventory->MoveNext(); 
	}
	$table .= '</tbody>' . '</table>';
	//BUILD TABLE END

	if($go){ 

		$meta['subject'] = 'Alerta de Inventario';
		$meta['to']      = $fields['contactEmail'];
		$meta['type']    = 'internal';
		$meta['fromName']= 'ENCOM';
		$meta['data']    = [
		                    "message"     => $table
		                	];

		//sendEmails($meta);

		
		$e++;

		$ops = [
				"title" 	=> "Alerta de Inventario",
				"message" 	=> "Tiene productos que llegaron a sus niveles mínimos de stock.",
				"type" 		=> 1,
				"link"     	=> "https://panel.encom.app/@#items",
				"company" 	=> $fields['companyId'],
				"push"      => [
		                        "tags" => [[
		                                        "key"   => "companyId",
		                                        "value" => enc($fields['companyId'])
		                                    ],
		                                    [
		                                        "key"   => "isResource",
		                                        "value" => "false"
		                                    ]],
		                        "where"     => 'panel'
		                        ]
				];

		insertNotifications($ops);
	}

   	$email->MoveNext(); 
}
$email->Close();
//EMAILS


dai();
?>