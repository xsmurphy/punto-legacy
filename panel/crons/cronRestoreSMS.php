<?php
include_once './cronHead.php';

$result		= ncmExecute('
							SELECT a.companyId as company, a.smsCredit as credit
							FROM company a
							WHERE (a.config->>\'settingAutoSMSCredit\')::int = 1
							AND a.config->>\'settingEncomID\' IS NOT NULL
							AND a.smsCredit < 100 LIMIT 2000'
						,[],false,true);

if($result){
	while (!$result->EOF) {
		$fields = $result->fields;
		ncmUpdate([
					'records' 	=> ['smsCredit' => ($fields['credit'] + (100 - $fields['credit']) ) ], 
					'table' 	=> 'company', 
					'where' 	=> 'companyId = ' . $fields['company']
				]);

		$result->MoveNext(); 
	}
	$result->Close();
}

dai();
?>