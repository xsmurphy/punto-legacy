<?php
include_once './cronHead.php';

$c = 0;
$e = 0;

//$result = ncmExecute("SELECT companyId FROM company WHERE companyExpiringDate = '" . date('Y-m-d 00:00:00') . "' AND companyPlan = 3",[],false,true);
$result = ncmExecute("SELECT companyId FROM company WHERE companyDate < DATE(NOW()) - INTERVAL 2 WEEK AND companyPlan = 3",[],false,true);

if($result){
	$where = [];
	while (!$result->EOF) {
		$id = $result->fields['companyId'];
		$where[] = $id;
		$c++;
		$result->MoveNext(); 
	}
	
	$update = ncmExecute("UPDATE company SET companyPlan = '0' WHERE companyId IN(" . implode(',',$where) . ")");

	$user 	= ncmExecute("SELECT contactEmail FROM contact WHERE role = 1 AND type = 0 AND companyId IN(" . implodes(',', $where) . ")",[],false,true);

	if($user){
		while (!$user->EOF) {
		   $options = json_encode(array(
		              "to" 		=> array( $user->fields['contactEmail'] ),
		              "sub" 	=> array( ":email"=>array($user->fields['contactEmail']) ),
		              "filters"	=> array(
		                        "templates" => array(
		                                  "settings" => array("enable"=>1,"template_id"=>"24d96e49-c106-4dc3-bdb6-38c28cf9b018")
		                                )
		                      )
		              ));
		   $e++;

		   $user->MoveNext(); 
		}

		$user->Close();
	}
}

dai();
?>