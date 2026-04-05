<?php
include_once('api_head.php');

$params = $post;

if(!array_key_exists("registerId",$params) || empty($params['registerId'])){
    jsonDieMsg("El campo registerId es obligatorio",400);
} 

$transactions = ncmExecute("SELECT invoiceNo FROM transaction WHERE companyId = '".COMPANY_ID."' AND registerId='".dec($params["registerId"])."' AND transactionType IN (0, 3) AND DATE(transactionDate) = CURDATE() ORDER BY invoiceNo DESC LIMIT 1",false,true);
if(!$transactions){
    jsonDieMsg("Hubo un error en el servidor",400);
}else{
    echo json_encode($transactions);
}

?>