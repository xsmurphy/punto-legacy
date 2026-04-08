<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$cache 	= validateHttp('cache','post') ? validateHttp('cache','post') : false;

$result = ncmExecute("SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra AS INTEGER) as sort FROM taxonomy WHERE taxonomyType = 'category' AND companyId = ? ORDER BY sort ASC LIMIT 500",[COMPANY_ID],$cache,true);
$arrays 		= [];

if($result){
	while (!$result->EOF) {

		$arrays[] = [
						"ID" 	=> enc($result->fields['taxonomyId']),
						"name" 	=> unXss($result->fields['taxonomyName']),
						"pos" 	=> (int)$result->fields['taxonomyExtra']
					];

	    $result->MoveNext(); 
	}
	$result->Close();

	apiOk($arrays);

} else {
	apiNotFound();
}
