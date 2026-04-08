<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$record 	= [];
$ID         = dec( validateHttp('ID','post') );
$add	    = validateHttp('add','post');
$remove	    = validateHttp('remove','post');
    
if(!validity($ID)){
    apiOk(['error' => 'Ingrese un ID válido'], 403);
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
        apiOk(['error' => 'No se pudo actualizar']);
    }else{
        apiOk(['success' => 'Banco actualizado']);
    }
}else{
	apiOk(['error' => 'No se encontraron datos','failed'=>$value], 404);
}

?>