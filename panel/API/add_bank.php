<?php
include_once('api_head.php');

$modules 		= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

$record 	= [];
$i 			= 0;
$success 	= 0;
$fail 		= 0;
$failArray 	= [];
$OUTLET_ID  = 0;
$value 		= validateHttp('data','post');

if(is_string($value)){
	$value = stripslashes($value);
}

if(isJson($value)){
	$value = json_decode($value,true);
}

if(validity($value,'array')){

	if(!validity($value['name'])){
		jsonDieResult(['error' => 'Debe añadir un nombre válido'],403);
	}

    if($value['balance'] && !is_numeric($value['balance'])){
		jsonDieResult(['error' => 'El balance debe ser numérico'],403);
	}

    /*if(!validity($value['outlet'])){
        jsonDieResult(['error' => 'Asigne una sucursal válida'],403);
    }else{
        $OUTLET_ID  = dec( $value['outlet'] );
        $outlet     = ncmExecute('SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',[$OUTLET_ID,COMPANY_ID]);
        if(!$outlet){
            jsonDieResult(['error' => 'Asigne una sucursal válida'],403);
        }
    }*/

    $datas = [
		        "name" => $value['name']
		     ];

	$balance = ($value['balance']) ? $value['balance'] : 0;

	$record['bankData'] 		= json_encode($datas);
	$record['bankBalance']	    = $balance;
	$record['bankDate']	    	= TODAY;
    $record['outletId'] 		= $OUTLET_ID;
	$record['companyId'] 		= COMPANY_ID;

    $insert = ncmInsert(['records' => $record, 'table' => 'banks']);

	if($insert === false){
		jsonDieResult(['error' => 'No se pudo crear el banco'],403);
	}else{
		jsonDieResult(['success' => 'Banco creado'],200);
	}
}else{
	jsonDieResult(['error'=>'No se encontraron datos','failed'=>$value],404);
}

?>