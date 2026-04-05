<?php
include_once('api_head.php');

$field 			= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1', [COMPANY_ID]);
$jsonResult 	= [];
$notAllowed 	= ['companyId'];

if ($field) {

	foreach ($field as $key => $value) {
		if (!is_numeric($key)) {
			if (!in_array($key, $notAllowed)) {
				if ($key == "ecom_data") {
					$ecom_data = json_decode($value, true);

					if (isset($ecom_data['tiers']) && is_array($ecom_data['tiers'])) {
						foreach ($ecom_data['tiers'] as &$tier) {
							if (isset($tier['id'])) {
								$id = dec($tier['id']);
								$result = ncmExecute('SELECT itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID], true);
								$tier['price'] = 0;

								if ($result) {
									$tier['price'] = $result['itemPrice'];
								}
							}
						}
					}

					$value = json_encode($ecom_data);
				}
				$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
				$jsonResult[$key] = $value;
			}
		}
	}
}

jsonDieResult($jsonResult, 200);
