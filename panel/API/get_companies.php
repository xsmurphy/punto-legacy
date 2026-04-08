<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// if($companyId != 'Og'){
//     apiError('Acceso denegado', 403);
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
apiOk([
    "data" => $companies
]);