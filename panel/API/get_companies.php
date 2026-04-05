<?php
include_once('api_head.php');
// if($companyId != "Og"){
//     jsonDieMsg('Acceso denegado',403);
// }
$result = ncmExecute('SELECT companyId, settingName from setting',[],false,true);
$companies = [];
if($result){
    while(!$result->EOF){
        $array = [];
        $array['id'] = enc($result->fields['companyId']);
        $array['name'] = $result->fields['settingName'];
        $companies[] = $array;
        $result->MoveNext();
    }
}
jsonDieResult([
    "data" => $companies
]);