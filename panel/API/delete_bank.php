<?php
include_once('api_head.php');

$record 	= [];
$ID         = validateHttp('ID','post');
    
if(!validity($ID)){
    jsonDieResult(['error' => 'Ingrese un ID válido'],403);
}

$ID     = dec($ID);
$result = ncmExecute('SELECT * FROM banks WHERE bankId = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

if($result){
    $delete = ncmExecute('DELETE FROM banks WHERE bankId = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

    if(!$delete){
        jsonDieResult(['error' => 'No se pudo actualizar'],200);
    }else{
        jsonDieResult(['success' => 'Banco actualizado'],200);
    }
}else{
	jsonDieResult(['error' => 'No se encontraron datos', 'failed' => $result], 404);
}

?>