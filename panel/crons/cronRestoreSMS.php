<?php
include_once './cronHead.php';

$result		= ncmExecute('
							SELECT a.companyId as company, a.companySMSCredit as credit 
							FROM company a, setting b 
							WHERE b.settingAutoSMSCredit = 1 
							AND b.settingEncomID IS NOT NULL 
							AND b.companyId = a.companyId 
							AND a.companySMSCredit < 100 LIMIT 2000'
						,[],false,true);

if($result){
	while (!$result->EOF) {
		$fields = $result->fields;
		ncmUpdate([
					'records' 	=> ['companySMSCredit' => ($fields['credit'] + (100 - $fields['credit']) ) ], 
					'table' 	=> 'company', 
					'where' 	=> 'companyId = ' . $fields['company']
				]);

		$result->MoveNext(); 
	}
	$result->Close();
}

dai();
?>