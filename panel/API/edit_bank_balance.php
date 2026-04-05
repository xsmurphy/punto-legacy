<?php
include_once('api_head.php');

$record 	= [];
$ID         = dec( validateHttp('ID','post') );
$add	    = validateHttp('add','post');
$remove	    = validateHttp('remove','post');
    
if(!validity($ID)){
    jsonDieResult(['error' => 'Ingrese un ID válido'],403);
}

$result = ncmExecute('SELECT * FROM banks WHERE bankId = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

if($result){
    $balance = $result['bankBalance'];

    if($add > 0){
        $balance = $balance + $add;
    }else if($remove > 0){
        $balance = $balance - $remove;
    }

    $record['bankBalance'] 		= $balance;
    $record['updated_at'] 		= TODAY;

    $updated = ncmUpdate(['records' => $record, 'table' => 'banks', 'where' => 'bankId = ' . $ID . ' AND companyId = ' . COMPANY_ID]);

    if($updated['error']){
        jsonDieResult(['error' => 'No se pudo actualizar'],200);
    }else{
        jsonDieResult(['success' => 'Banco actualizado'],200);
    }
}else{
	jsonDieResult(['error' => 'No se encontraron datos','failed'=>$value],404);
}

?>