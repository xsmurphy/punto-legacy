<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$params = $post;

if(!array_key_exists("registerId",$params) || empty($params['registerId'])){
    apiError("El campo registerId es obligatorio", 400);
} 

$transactions = ncmExecute("SELECT invoiceNo FROM transaction WHERE companyId = '".COMPANY_ID."' AND registerId='".dec($params["registerId"])."' AND transactionType IN (0, 3) AND transactionDate::date = CURRENT_DATE ORDER BY invoiceNo DESC LIMIT 1",false,true);
if(!$transactions){
    apiError("Hubo un error en el servidor", 400);
}else{
    echo json_encode($transactions);
}

?>