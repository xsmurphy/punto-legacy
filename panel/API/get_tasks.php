<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
$resourceId = validateHttp("resourceId", "post");
if(!empty($resourceId)){
    $resourceId = dec($resourceId);
    $result = ncmExecute("SELECT ID,date,type,status,data,sourceId FROM tasks where sourceId = '".$resourceId."' and companyId = '".COMPANY_ID."' LIMIT 500",[],false,true);
}else{
    $result = ncmExecute("SELECT ID,date,type,status,data,sourceId FROM tasks where companyId = '".COMPANY_ID."' LIMIT 500",[],false,true);
}
$arrays = [];

if($result){
    while (!$result->EOF) {
        $arrays[] = [
                        "ID"     => enc($result->fields['ID']),
                        "date"     => $result->fields['date'],
                        "type"     => $result->fields['type'],
                        "status" => $result->fields['status'],
                        "resourceId" => $result->fields['sourceId'],
                        "data"     => json_decode($result->fields['data'],true),
                    ];
        $result->MoveNext();
    }
    $result->Close();
    apiOk($arrays);
}else{
    apiError('No se encontraron registros', 404);
}

?>