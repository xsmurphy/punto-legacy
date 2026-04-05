<?php
include_once './cronHead.php';

$result = ncmExecute("SELECT * FROM giftCardSold WHERE giftCardSoldSendDate BETWEEN '" . TODAY_START . "' AND '" . TODAY_END . "' AND giftCardSoldBeneficiaryId IS NOT NULL",[],false,true);
$e = 0;
if($result){
	while (!$result->EOF){
		$fields 		= $result->fields;

		$COMPANY_ID = $fields['companyId'];

		$cSettings = ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[$COMPANY_ID]);

		$companyName 	= $cSettings['settingName'];
		$companyEmail 	= $cSettings['settingEmail'];
		$compCountry	= $cSettings['settingCountry'];
		$smsCredit		= $cSettings['companySMSCredit'];
		$compLogo 		= 'https://assets.encom.app/80-80/0/' . enc($COMPANY_ID) . '.jpg';

		$COMPANY_NAME 	= $companyName;
		$COMPANY_EMAIL 	= $companyEmail;
		
		$senderId 		= getValue('transaction','customerId',' WHERE transactionId = '.$fields['transactionId']);

		$allContacts 	= getAllContactsRaw(1,0);
		$benefData  	= $allContacts[$fields['giftCardSoldBeneficiaryId']];
		$senderData   	= $allContacts[$senderId];

		if(validity($benefData['contactEmail'])){
			$senderName   = $COMPANY_NAME;
			$benefName    = '!';

			if(validity($senderId)){
				$senderName   = $senderData['contactSecondName'];
				if(!validity($senderName)){
					$senderName = $senderData['contactName'];
				}
			}

			$benefName   = $benefData['contactSecondName'];
			if(!validity($benefName)){
				$benefName = $benefData['contactName'];
			}

			$benefName 	= explodes(' ',$benefName,false,0);
			$url        = getShortURL('https://public.encom.app/giftCardRedeem?s=' . base64_encode($fields['timestamp'].','.enc($COMPANY_ID)));

			$subject  = '[' . $COMPANY_NAME . '] Gift Card';
			$body     = 'Hola ' . $benefName . ',' .
			          '<p>' . $senderName . ' le ha enviado una Gift Card' . '</p>' .
			          makeEmailActionBtn($url,'Ver Gift Card') .
			          '<p>' . 'Si tiene preguntas o dudas por favor contacte a ' . $COMPANY_NAME . ' en ' . $COMPANY_EMAIL . '.</p>';

			$bodySMS 	= '[' . $COMPANY_NAME . '] Hola ' . $benefName . ', ' . $senderName . ' le ha enviado una Gift Card ' . $url;


			$meta['subject'] = $subject;
			$meta['to']      = $benefData['contactEmail'];
			$meta['fromName']= $COMPANY_NAME;
			$meta['data']    = [
			                    "message"     => $body,
			                    "companyname" => $COMPANY_NAME,
			                    "companylogo" => $compLogo
			                  ];

			sendEmails($meta);
			sendSMS($benefData['contactPhone'],$bodySMS,$compCountry,$smsCredit);

			$e++;
		}

		$result->MoveNext(); 
    }
}

dai($sent);
?>