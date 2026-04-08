<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$modules 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

$record 	= [];
$ID         = dec( validateHttp('ID','post') );
$value 		= validateHttp('data','post');

if(is_string($value)){
	$value = stripslashes($value);
}

if(isJson($value)){
	$value = json_decode($value,true);
}
    
if(!validity($ID)){
    apiOk(['error' => 'Ingrese un ID válido'], 403);
}

$result = ncmExecute('SELECT * FROM banks WHERE bankId = ? AND companyId = ? LIMIT 100', [$ID, COMPANY_ID]);

if($result){
    $name = $value['name'] ? $value['name'] : $result['bankName'];
    $datas = [
        "name" => $name
    ];

    $record['bankData'] 		= json_encode($datas);

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