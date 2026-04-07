<?php
//user var
$plansValues = getAllPlans();
require_once __DIR__ . '/../vendor/autoload.php';

use Mailgun\Mailgun as MailgunClient;

$__modules 	= json_decode($_modules['moduleData'], true);
$__modules 	= is_array($__modules) ? $__modules : [];

if (isset($__modules['extraItems']) && is_numeric($__modules['extraItems'])) {
	$plansValues[PLAN]['max_items'] = $plansValues[PLAN]['max_items'] + $__modules['extraItems'];
}


function minifyJS($arr, $multiple = false, $noMin = false, $print = false)
{
	minify($arr, 'https://javascript-minifier.com/raw', $multiple, $noMin, $print);
}

function minifyCSS($arr, $multiple = false)
{
	minify($arr, 'https://cssminifier.com/raw', $multiple, false, false);
}

function minify($arr, $url, $multiple, $noMin, $print)
{

	$allData = '';
	foreach ($arr as $key => $value) {
		if ($multiple) {
			if (strpos($key, 'http') !== false) { //check if url or plain
				$data = getFileContent($key);
			} else {
				$data = $key;
			}

			$file 		= $multiple;

			if (strpos($key, 'min.') === false && !$noMin) { //check if already min
				$allData 	.= getMinified($url, $data) . "\r\n";
			} else {
				$allData 	.= $data . "\r\n";
			}
		} else {
			if (strpos($key, 'http') === 0) { //check if url or plain
				$data = getFileContent($key);
			} else {
				$data = $key;
			}

			$file 		= $value;
			$allData 	= getMinified($url, $data);
		}
	}

	if ($print) {
		echo $allData;
	} else {
		$handler = fopen($file, 'w');
		fwrite($handler, $allData);
		fclose($handler);
	}
}

function ob_gets_contents()
{
	$data = ob_get_contents();
	ob_end_clean();
	return $data;
}

function getMinified($url, $content)
{
	return $content;

	/*$postdata = array('http' => array(
        'method'  => 'POST',
        'header'  => 'Content-type: application/x-www-form-urlencoded',
        'content' => http_build_query( array('input' => $content) ) ) );
    	return file_get_contents($url, false, stream_context_create($postdata));*/
}

function isHttps()
{
	$isSecure = false;
	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
		$isSecure = true;
	} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
		$isSecure = true;
	}

	//$REQUEST_PROTOCOL = $isSecure ? 'https' : 'http';

	if (!$isSecure) {
		$redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		header('HTTP/1.1 301 Moved Permanently');
		header('Location: ' . $redirect);
		exit();
	}
}

function the_file_exists($filename)
{
	$file_headers = @get_headers($filename);
	// if(is_bool($file_headers)){
	// 	return false;
	// }
	if ($file_headers[0] == 'HTTP/1.0 404 Not Found') {
		return false;
	} else if ($file_headers[0] == 'HTTP/1.0 302 Found' && $file_headers[7] == 'HTTP/1.0 404 Not Found') {
		return false;
	} else {
		return true;
	}
}

function companyLogo($size = 150, $bw = false)
{
	$size 		= is_nan($size) ? 70 : $size;

	$folder 	= defined('SYSIMGS_FOLDER') ? SYSIMGS_FOLDER : '';
	$assetsUrl 	= defined('ASSETS_URL') ? ASSETS_URL : '';

	$dayUpdt 	= date('i');

	$compId 	= enc(COMPANY_ID);
	$file 		= $compId . '.jpg';
	$img 		= $folder . '/' . $compId . '.jpg?' . $dayUpdt;
	$isImg 		= the_file_exists($assetsUrl . $img);

	if (!$isImg) {
		$img 		= 'images/add.png';
	} else {
		if ($bw) {
			$img 	= $assetsUrl . '/' . $size . '-' . $size . '/&f=2%7C4,-50/' . $file;
		} else {
			$img 	= $assetsUrl . '/' . $size . '-' . $size . '/0/' . $file . '?' . $dayUpdt;
		}
	}
	return $img;
}

function companySocialSites($socialArr, $wa)
{
	$social 	= json_decode($socialArr, true);

	$utm 		= '?utm_source=saas_online_receipt&utm_medium=saas_footer_icons&
utm_campaign=saas_social_media_marketing';

	$facebook 	= 'https://facebook.com/' . str_replace('@', '', $social['facebook']) . $utm;
	$instagram 	= 'https://instagram.com/' . str_replace('@', '', $social['instagram']) . $utm;
	$youtube 	= 'https://youtube.com/' . str_replace('@', '', $social['youtube']) . $utm;
	$twitter 	= 'https://twitter.com/' . str_replace('@', '', $social['twitter']) . $utm;
	$whatsapp 	= 'https://wa.me/' . $wa;
?>
	<?php
	if ($social['facebook']) {
	?>
		<a href="<?= $facebook; ?>"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/facebook.svg" class="svg" width="20"></a>
	<?php
	}
	if ($social['instagram']) {
	?>
		<a href="<?= $instagram; ?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/instagram.svg" class="svg" width="20"></a>
	<?php
	}
	if ($social['youtube']) {
	?>
		<a href="<?= $youtube; ?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/youtube.svg" class="svg" width="20"></a>
	<?php
	}
	if ($social['twitter']) {
	?>
		<a href="<?= $twitter; ?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/twitter.svg" class="svg" width="20"></a>
	<?php
	}
	if ($wa) {
	?>
		<a href="<?= $whatsapp; ?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/whatsapp.svg" class="svg" width="20"></a>
	<?php
	}
}

function imageToBase64($path)
{
	$type = pathinfo($path, PATHINFO_EXTENSION);
	$data = curlContents($path . '?' . rand());
	$base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
	return $base64;
}

function isBase64Decode($str, $unentity = true)
{

	if (!$str) {
		return '';
	}

	$out = $str;

	if (preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $str)) {
		$out = base64_decode($str);
	}

	if ($unentity) {
		return urldecode(html_entity_decode($out));
	} else {
		return $out;
	}
}

function isHTML($string)
{
	return ($string != strip_tags($string)) ? true : false;
}

function CRUDArray($ops)
{

	$array 		= validInArray($ops, 'array');
	$key 			= validInArray($ops, 'key');
	$value 		= validInArray($ops, 'value');
	$action 	= validInArray($ops, 'action');

	if (!is_array($array) || !$key || !$action) {
		return $array;
	}

	if ($action == 'remove') {

		unset($array[$key]);
	} else if ($action == 'update') {

		foreach ($value as $k => $val) {
			$array[$key][$k] 		= $val;
		}
	} else if ($action == 'create') {

		$array[$key] 	= $value;
	}

	return $array;
}

function markupt2HTML($options)
{
	if (is_array($options)) {
		$text = $options['text'] ? $options['text'] : '';
		$type = $options['type']; //or MtH
	} else {
		$text = $options;
		$type = false;
	}


	if (!$type) { // si no especifico el tipo detecto
		if (isHTML($text)) {
			$type = 'HtM';
		} else {
			$type = 'MtH';
		}
	}

	$HtMrules = [
		["find" => '<br>', "replace" 	=> '\n'],
		["find" => '<br/>', "replace" 	=> '\n'],
		["find" => '<br />', "replace" 	=> '\n'],
		["find" => '<b>', "replace" 	=> '*'],
		["find" => '</b>', "replace" 	=> '*'],
		["find" => '<strong>', "replace" => '*'],
		["find" => '</strong>', "replace" => '*'],
		["find" => '<em>', "replace" 	=> '_'],
		["find" => '</em>', "replace" 	=> '_'],
		["find" => '<i>', "replace" 	=> '_'],
		["find" => '</i>', "replace" 	=> '_'],
		["find" => '</i>', "replace" 	=> '_'],
		["find" => '<li>', "replace" 	=> '- '],
		["find" => '</li>', "replace" 	=> ''],
		["find" => '<u>', "replace" 	=> '~'],
		["find" => '</u>', "replace" 	=> '~'],
		["find" => '&nbsp;&nbsp;•&nbsp;', "replace" => '- '],
		["find" => '<div>', "replace" 	=> '\n'],
		["find" => '</div>', "replace" 	=> ''],
		["find" => '<p>', "replace" 	=> '\n'],
		["find" => '</p>', "replace" 	=> '']
	];

	$MtHrules = [
		["find" => '/\*(.*?)\*/', "replace" => '<strong>$1</strong>'],
		["find" => '/\_(.*?)\_/', "replace" => '<em>$1</em>'],
		["find" => '/\~(.*?)\~/', "replace" => '<u>$1</u>'],
		["find" => '/\- (.*?)/', "replace" => '&nbsp;&nbsp;•&nbsp; $1 &nbsp;'],
		["find" => '/\```(.*?)\```/', "replace" => '<pre>$1</pre>']
	];

	if ($type == 'HtM') {
		foreach ($HtMrules as $rule) {
			$texts 	= explode($rule['find'], $text); //text.split(rule.find).join(rule.replace);
			$text 	= implode($rule['replace'], $texts);
		}

		$text = strip_tags($text);
	} else {
		$text = strip_tags($text);

		/*$text = explode('\n', $text);
		$text = implode('<br>', $text);
		$text = explode('\r', $text);
		$text = implode('<br>', $text);

		$text = str_replace(['\n','\r'],['<br>','<br>'],$text);*/

		$text = nl2br($text);

		foreach ($MtHrules as $rule) {
			$text = preg_replace($rule['find'], $rule['replace'], $text); //text.replace(rule.find, rule.replace);
		}
	}

	return $text;
}


function tildesHtml($cadena)
{
	//manejar acentos con acute
	return str_replace(
		array("á", "é", "í", "ó", "ú", "ñ", "Á", "É", "Í", "Ó", "Ú", "Ñ"),
		array("&aacute;", "&eacute;", "&iacute;", "&oacute;", "&uacute;", "&ntilde;", "&Aacute;", "&Eacute;", "&Iacute;", "&Oacute;", "&Uacute;", "&Ntilde;"),
		$cadena
	);
}

function getImage($name, $w, $h)
{
	$compId 	= enc(COMPANY_ID);

	$img 		= SYSIMGS_FOLDER . '/' . $name . '.jpg';
	$isImg 		= the_file_exists(ASSETS_URL . $img);
	if (!$isImg) {
		$img 		= 'images/transparent.png';
	} else {
		$img 		= ASSETS_URL . '/src.php?src=' . $img . '&w=' . $w . '&h=' . $h . '&' . rand();
	}
	return $img;
}

function makeEmailActionBtn($url, $txt)
{
	return 	'<div style="text-align:center;padding:10px">' .
		' <a href="' . $url . '" style="color:white;background-color:#4CB6CB;padding:13px 25px;text-decoration:none;text-transform: uppercase;font-family:Arial;font-size:0.9em;border-radius:100px;font-weight:bold;">' .
		$txt .
		' </a>' .
		'</div>';
}

function passEncoder($pass)
{
	// A salt is randomly generated here to protect again brute force attacks
	// and rainbow table attacks.  The following statement generates a hex
	// representation of an 8 byte salt.  Representing this in hex provides
	// no additional security, but makes it easier for humans to read.
	// For more information:
	// http://en.wikipedia.org/wiki/Salt_%28cryptography%29
	// http://en.wikipedia.org/wiki/Brute-force_attack
	// http://en.wikipedia.org/wiki/Rainbow_table
	$salt = dechex(mt_rand(0, SALT)) . dechex(mt_rand(0, SALT));

	// This hashes the password with the salt so that it can be stored securely
	// in your database.  The output of this next statement is a 64 byte hex
	// string representing the 32 byte sha256 hash of the password.  The original
	// password cannot be recovered from the hash.  For more information:
	// http://en.wikipedia.org/wiki/Cryptographic_hash_function
	$password = hash('sha256', $pass . $salt);

	// Next we hash the hash value 65536 more times.  The purpose of this is to
	// protect against brute force attacks.  Now an attacker must compute the hash 65537
	// times for each guess they make against a password, whereas if the password
	// were hashed only once the attacker would have been able to make 65537 different 
	// guesses in the same amount of time instead of only one.
	for ($round = 0; $round < HASH_TIMES; $round++) {
		$password = hash('sha256', $password . $salt);
	}

	return array($password, $salt);
}

function thalog($arr)
{
	if ($arr) {
		foreach ($arr as $txt) {
			echo $txt . ' ';
		}
	}
}

function checkForPassword($password, $salt)
{
	$check_password = hash('sha256', $password . $salt);
	for ($round = 0; $round < HASH_TIMES; $round++) {
		$check_password = hash('sha256', $check_password . $salt);
	}
	return $check_password;
}

if (!function_exists("curPageURL")) {
	function curPageURL()
	{
		$pageURL = 'http';
		if ($_SERVER["HTTPS"] == 'on') {
			$pageURL .= 's';
		}
		$pageURL .= "://";
		if ($_SERVER["SERVER_PORT"] != "80") {
			$pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
		} else {
			$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
		}
		return $pageURL;
	}
}

if (validateHttp('o')) {
	//die($_GET['o']);
	if (validateHttp('r')) {
		$_SESSION['user']['registerId'] = db_prepare($_GET['r']);
	}

	$oId = db_prepare($_GET['o']);
	$_SESSION['user']['outletId'] = $oId;

	if ($oId != 1) {
		$register = $db->Execute("SELECT registerId FROM register WHERE outletId = ? LIMIT 1", [dec($oId)]);

		$_SESSION['user']['registerId'] = enc($register->fields['registerId']);
	}

	$extra = '';
	if (validateHttp('state') == 'outcome') {
		$extra = '?state=outcome';
	}

	list($url) = explode('?', curPageURL());
	header('location:' . $url . $extra);
}

if (validateHttp('r')) {
	$_SESSION['user']['registerId'] = db_prepare(($_GET['r']));
	list($url) = explode('?', curPageURL());
	header('location:' . $url);
}

function getCompanyCategoryName($array, $id, $father = true)
{
	foreach ($array as $key => $val) {
		foreach ($val as $k => $v) {
			if ($id === $v) {
				if ($father) {
					return $key;
				} else {
					if ($k == 'Other') {
						return $key;
					} else {
						return $k;
					}
				}

				break;
			}
		}
	}
}

function getItemData($id, $cache = false)
{
	$result = ncmExecute("SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1", [$id, COMPANY_ID], $cache);

	if ($result) {
		return $result;
	} else {
		return [];
	}
}

function getItemName($id)
{
	$data = getItemData($id, true);
	return toUTF8($data['itemName']);
}

function getItemId($value, $field = 'itemSKU', $companyId = false, $cache = false)
{
	global $db, $ADODB_CACHE_DIR;
	$extr = '';
	if ($companyId) {
		$extr = ' AND companyId = ' . db_prepare($companyId);
	}

	$sql = "SELECT itemId FROM item WHERE " . $field . " = '" . $value . "'" . $extr . " LIMIT 1";

	if ($cache) {
		$obj = $db->cacheExecute($sql);
	} else {
		$obj = $db->Execute($sql);
	}

	if (validateResultFromDB($obj)) {
		return $obj->fields['itemId'];
	} else {
		return false;
	}
}

function getItemsFilledWithData($details)
{
	//Funcion que completa informacion de los items en un array de items de una transacción
	foreach ($details as &$itmLine) {
		$tags 							= $itmLine['tags'];
		$itmLine['note'] 		= $itmLine['note']; //isBase64Decode($itmLine['note'],false);

		if ($itmLine['status'] === 0) {
			$itmLine['status'] 		= false;
		} else {
			$itmLine['status'] 		= true;
		}

		$item 									= getItemData(dec($itmLine['itemId']), true);
		$itmLine['name'] 				= $item['itemName'];
		$itmLine['duration'] 		= $item['itemDuration'];
		$itmLine['category_id'] = enc($item['categoryId']);
		$itmLine['tag_names']		= explodes(',', printOutTags($tags, false, true));
	}

	return $details;
}

function getTaxValue($id)
{
	if (!$id) {
		return '';
	}

	$tax 		= ncmExecute("SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? LIMIT 1", [$id]);
	if ($tax) {
		if ($tax['taxonomyName'] == "0") {
			$out = $tax['taxonomyName'];
		} else {
			$out 	= toUTF8($tax['taxonomyName']);
		}
	} else {
		$out 	= '';
	}

	return $out;
}

function getTaxOfPrice($tax, $price)
{

	if ($tax && $price && $tax > 0) {
		$taxVal 	= $price / (1 + ($tax / 100));
		$total 		= $price - $taxVal;

		if ($total && $total > 0) {
			return $total;
		} else {
			return 0;
		}
	} else {
		return 0;
	}
}

function calculateTax($total, $tax)
{
	$integer 	= ($tax < 10) ? '0.' : '1.';
	$taxC 		= $integer . $tax;
	$noTax 		= divider($total, $taxC);
	$taxValue 	= $total - $noTax;

	return [$noTax, $taxValue];
}

function addTax($tax, $price)
{
	if ($tax && $price && $tax > 0) {
		$taxVal   = $price / (1 + ($tax / 100));
		$total    = $price - $taxVal;

		if ($total && $total > 0) {
			return $total;
		} else {
			return 0;
		}
	} else {
		return 0;
	}
}

function getTableObjectName($id, $table, $customQuery = false, $column = 1, $where = '')
{
	global $db, $SQLcompanyId;
	if (!$customQuery) {
		if ($table != 'role') { //aqui filtro las tablas en la BD que no se basan en Company IDS
			$obj = $db->Execute("SELECT " . $column . " FROM " . $table . " WHERE " . $table . "Id = " . $id . " AND " . $SQLcompanyId);
		} else {
			$obj = $db->Execute("SELECT " . $column . " FROM " . $table . " WHERE " . $table . "Id = " . $id);
		}
	} else {
		$obj = $db->Execute($customQuery);
	}

	if (validateBool($obj->fields[$column], false, false)) {
		return $obj->fields[$column];
	} else {
		return 0;
	}
	$obj->Close();
}

function getContactTransactions($id, $from = false, $to = false, $field = 'customerId', $cache = false)
{

	$from 	= ($from) ? $from : COMPANY_DATE;
	$to 	= ($to) ? $to : TODAY;

	$sql 	= "SELECT transactionId as id FROM transaction WHERE " . $field . " = ? AND transactionDate BETWEEN ? AND ? AND companyId = ?";

	$result = ncmExecute($sql, [$id, $from, $to, COMPANY_ID], $cache, true);

	$out 	= [];

	if ($result) {
		while (!$result->EOF) {
			$out[] = $result->fields['id'];

			$result->MoveNext();
		}
	}

	$out = implodes(',', $out);

	return $out;
}

function getContactInSales($id, $from = false, $to = false, $field = 'customerId', $cache = false)
{
	global $db, $ADODB_CACHE_DIR;

	$from 	= ($from) ? $from : COMPANY_DATE;
	$to 	= ($to) ? $to : TODAY;
	$out 	= [];

	$sql 			= "SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, COUNT(transactionId) as count FROM transaction WHERE " . $field . " = ? AND transactionType IN(0,3) AND transactionDate BETWEEN ? AND ? AND companyId = ?";

	$result 		= ncmExecute($sql, [$id, $from, $to, COMPANY_ID], $cache);

	if ($result) {
		$total 		= $result['total'];
		$discount 	= $result['discount'];
		$out 		= [($total - $discount), $result['count']];
	}

	return $out;
}

function getContactItemsSold($id, $from = false, $to = false, $field = 'customerId', $cache = false)
{
	global $db, $ADODB_CACHE_DIR;

	$from 		= iftn($from, COMPANY_DATE);
	$to 		= iftn($to, TODAY);
	$out 		= [];

	$sql 		= "SELECT SUM(itemSoldTotal) as total, SUM(itemSoldDiscount) as discount, COUNT(transactionId) as count FROM itemSold WHERE " . $field . " = ? AND itemSoldDate BETWEEN ? AND ? ";

	$result 	= ncmExecute($sql, [$id, $from, $to], $cache);

	if ($result) {
		$total 		= $result['total'];
		$discount 	= $result['discount'];
		$out 		= [($total - $discount), $result['count']];
	}

	return $out;

	$result->Close();
}

function getContactPurchasedItems($id, $from = false, $to = false, $field = 'customerId', $cache = false)
{

	$transactionsIds = getContactTransactions($id, $from, $to, $field, $cache);

	if ($transactionsIds) {
		$sql   	= "SELECT SUM(itemSoldUnits) as units FROM itemSold WHERE transactionId IN (" . $transactionsIds . ")";

		$result = ncmExecute($sql, [], $cache);

		if ($result) {
			return $result['units'];
		} else {
			return 0;
		}
	} else {
		return 0;
	}
}

function getContactAccountBalance($cId)
{
	global $db, $SQLcompanyId;

	$totalComprado 	= 0;
	$totalPendiente	= 0;
	$ids 						= [];
	$total	= ncmExecute(
		'
												SELECT 
													transactionTotal, 
													transactionDiscount,
													transactionId,
													transactionComplete
												FROM transaction 
												WHERE customerId = ? 
												AND transactionType = 3 
												AND companyId = ?',
		[$cId, COMPANY_ID]
	);

	while (!$total->EOF) {
		$totalComprado += ($total->fields['transactionTotal'] - $total->fields['transactionDiscount']);
		$ids[] 					= $total->fields['transactionId'];

		if ($total->fields['transactionComplete'] < 1) {
			$totalPendiente += ($total->fields['transactionTotal'] - $total->fields['transactionDiscount']);
		}

		$total->MoveNext();
	}
	$total->MoveFirst();

	$totalPagado 		= 0;
	$totalDeuda 		= 0;
	$iids						= implode(',', $ids);

	if ($total) {
		$payed	= ncmExecute(
			'
								SELECT 
									SUM(transactionTotal) as payed 
								FROM transaction 
								WHERE customerId = ? 
								AND transactionType = 5 
								AND transactionParentId IN(' . $iids . ') 
								AND companyId = ?',
			[$cId, COMPANY_ID]
		);

		$totalPagado 		= $payed['payed'];
		$totalDeuda 		= $totalComprado - $totalPagado;
	}

	return [$totalComprado, $totalPagado, $totalPendiente];
}

function getTopCategoriesByCustomer($id, $from = false, $to = false, $limit = 5, $cache = false)
{
	global $db;

	if ($from && $to) {
		$result   	= ncmExecute("SELECT a.itemId, 
										SUM(a.itemSoldUnits) as usold, 
										b.categoryId 
									FROM itemSold a, 
											item b, 
											transaction c 
									WHERE a.itemId = b.itemId 
									AND a.itemSoldDate 
									BETWEEN ? 
									AND ? 
									AND a.transactionId = c.transactionId
									AND c.customerId = ?
									GROUP BY b.categoryId
									ORDER BY usold DESC 
									LIMIT " . $limit, [$from, $to, $id], $cache, true);
	} else {
		$result   	= ncmExecute("SELECT a.itemId, 
										SUM(a.itemSoldUnits) as usold, 
										b.categoryId 
								FROM itemSold a, 
										item b, 
										transaction c 
								WHERE a.itemId = b.itemId  
								AND a.transactionId = c.transactionId
								AND c.customerId = ?
								GROUP BY b.categoryId 
								ORDER BY usold DESC 
								LIMIT " . $limit, [$id], $cache, true);
	}
	$array 		= [];

	if ($result) {
		while (!$result->EOF) {
			$array[getTaxonomyName($result->fields['categoryId'])] = $result->fields['usold'];
			$result->MoveNext();
		}
	}

	return $array;
}

function getRealCustomerId($id)
{
	$l = strlen((string)$id);
	if ($l > 11) {
		return 'customerUID';
	} else {
		return 'customerId';
	}
}

function generateUID($add = 0)
{
	return number_format(microtime(true) * 1000, 0, '.', '') + $add;
}

function getValue($table, $field, $where = '', $returnType = 'number')
{
	global $db;

	$limit = ' LIMIT 1';

	if (strpos($where, 'LIMIT') !== false) { // si where contine limit le saco
		$limit = '';
	}

	$obj 	= ncmExecute("SELECT " . $field . " FROM " . $table . " " . $where . $limit, [], false, true);

	if ($obj) {
		return $obj->fields[$field];
	} else {
		if ($returnType == 'number') {
			return 0;
		} else if ($returnType == 'boolean') {
			return false;
		} else if ($returnType == 'string') {
			return '';
		}
	}
}

function getTaxonomyJson($type, $company)
{

	if ($company) {
		$company = 'companyId = ' . $company;
	} else {
		$company = $SQLcompanyId;
	}

	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND " . $company, [$type], false, true);

	$out = '[';

	while (!$result->EOF) {
		$out .= '{"tagid":"' . $result->fields['taxonomyId'] . '","tagname":"' . $result->fields['taxonomyName'] . '"},';

		$result->MoveNext();
	}

	$out = rtrim($out, ',') . "]";

	return $out;
}

function getTaxonomyArray($type, $company, $compZero = false)
{
	global $db, $SQLcompanyId;

	$compZero 	= ($compZero) ? 'companyId = 1 OR ' : '';

	if ($company) {
		$company = $compZero . 'companyId = ' . $company;
	} else {
		$company = $compZero . $SQLcompanyId;
	}

	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND taxonomyExtra != 'internal' AND (?) LIMIT 500", [$type, $company], false, true);

	$out 	= [];

	if ($result) {
		while (!$result->EOF) { //ID sin codificar a proposito
			$out[] = [
				'tagid' 	=> $result->fields['taxonomyId'],
				'tagname' => $result->fields['taxonomyName']
			];

			$result->MoveNext();
		}
		$result->Close();
	}

	return json_encode($out);
}

function getTagsDefaults($company)
{
	global $db, $SQLcompanyId;

	// $company puede llegar como `true` (llamada legacy que usaba ID 1 en MySQL).
	// En PostgreSQL con UUIDs no existe un ID numérico; usar COMPANY_ID como fallback.
	$company = ($company && $company !== true) ? $company : COMPANY_ID;

	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = 'tag' AND companyId = ? AND taxonomyExtra != 'internal' LIMIT 20", [$company], true, true);

	$out = [];

	if (!$result) return $out;

	while (!$result->EOF) {
		$out[] = ["tagid" => $result->fields['taxonomyId'], "tagname" => $result->fields['taxonomyName']];
		$result->MoveNext();
	}

	return $out;
}

function isDeliveryOrPickup($tags)
{
	$is 		= 'forhere';

	if (validity($tags, 'string')) {
		$tags = explodes(',', $tags);
	}

	$chkTags 	= [472 => 'delivery', 473 => 'pickup'];

	foreach ($tags as $key => $tag) {
		foreach ($chkTags as $i => $ta) {
			if ($tag == $i || dec($tag) == $i) {
				$is = $ta;
			}
		}
	}

	return $is;
}

function getTaxonomyName($id, $numeric = false, $company = false, $cache = false)
{

	if (!$id) {
		return '';
	}

	if ($cache) {
		if (isset($_SESSION['NCM_ALLS']['TAXONOMY_' . $id])) {
			return $_SESSION['NCM_ALLS']['TAXONOMY_' . $id];
		}
	}

	$obj = ncmExecute("SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? LIMIT 1", [$id], $cache);

	if ($obj) {
		$out = toUTF8($obj['taxonomyName']);

		$_SESSION['NCM_ALLS']['TAXONOMY_' . $id] = $out;

		return $out;
	} else {
		if ($numeric) {
			return 0;
		} else {
			return 'None';
		}
	}
}

function printOutTags($tags, $bg = 'bg-white', $flat = false)
{

	if (!validity($tags, 'array')) {
		return '';
	}

	$joiner = [];
	foreach ($tags as $tag) {
		$name 		= getTaxonomyName($tag);
		if ($name != 'None') {
			$joiner[] = $name;
		}
	}

	return arrayToLabelsUI(['data' => $joiner, 'bg' => $bg, 'flat' => $flat]);
}

function isInternalSale($tags, $forceIgnored = false)
{
	global $_fullSettings;

	if (!$forceIgnored) {
		if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
			return false;
		}
	}

	if (!validity($tags, 'array')) {
		return false;
	}

	if (in_array('166227', $tags) || in_array(166227, $tags)) {
		return true;
	} else {
		return false;
	}
}

function isParentInternalSale($parentId)
{
	global $_fullSettings;

	if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
		return false;
	}

	if (!validity($parentId)) {
		return false;
	}

	$ignore = false;
	$field 	= ncmExecute('SELECT tags FROM transaction WHERE transactionId = ? AND transactionType IN(0,3) AND companyId = ? LIMIT 1', [$parentId, COMPANY_ID]);

	if ($field) {
		$tags 	= json_decode($field['tags'] ?? "", true);
		$ignore = isInternalSale($tags);
	}

	return $ignore;
}

function lessInternalTotals($roc, $from, $to, $tTypes = false)
{
	global $_fullSettings;

	if (empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']) {
		return ['total' => 0, 'discount' => 0, 'tax' => 0, 'qty' => 0, 'count' => 0];
	}

	$tTypes = $tTypes ? db_prepare($tTypes) : '0,3';

	$result = ncmExecute('SELECT transactionTotal, tags, transactionDiscount, transactionUnitsSold, transactionTax FROM transaction USE INDEX(transactionType,transactionDate) WHERE transactionDate BETWEEN ? AND ? AND transactionType IN(' . $tTypes . ') ' . $roc . ' LIMIT 5000', [$from, $to], 1200, true);

	$total  	= 0;
	$discount  	= 0;
	$tax  		= 0;
	$qty  		= 0;
	$count 		= 0;
	if ($result) {
		while (!$result->EOF) {
			$field = $result->fields;
			$tags = json_decode($field['tags'] ?? "", true);
			if (isInternalSale($tags)) {
				$total 		+= $field['transactionTotal'] - $field['transactionDiscount'];
				$discount  	+= $field['transactionDiscount'];
				$tax  		+= $field['transactionTax'];
				$qty  		+= $field['transactionUnitsSold'];

				$count++;
			}
			$result->MoveNext();
		}
		$result->Close();
	}

	return ['total' => (float) $total, 'discount' => (float) $discount, 'tax' => (float) $tax, 'qty' => (float) $qty, 'count' => (float) $count];
}

function arrayToLabelsUI($ops)
{
	$data 		= $ops['data'];
	$flat 		= $ops['flat'] ?? false;
	$field 		= $ops['field'] ?? false;
	$bg 		= $ops['bg'];

	$joiner 	= [];
	$glue 		= $flat ? ',' : '<i class="hidden">;</i>';


	if (validity($data, 'array')) {
		foreach ($data as $k => $val) {
			if ($field) {
				$dato = $val[$field];
			} else {
				$dato = $val;
			}

			if ($flat) {
				$joiner[] = $dato;
			} else {
				$joiner[] = '<span class="label ' . $bg . '">' . $dato . '</span> ';
			}
		}
	}

	$imploded = implode($glue, $joiner);
	return $imploded;
}

function getCurrentOutletName($id = OUTLET_ID)
{
	global $allOutletsArray;

	if (validity($allOutletsArray)) {
		if ($id > 1 && array_key_exists($id, $allOutletsArray)) {
			return toUTF8($allOutletsArray[$id]['name']);
		} else {
			if (OUTLETS_COUNT > 1) {
				return 'Todas';
			} else {
				return toUTF8($allOutletsArray[FIXED_OUTLET_ID]['name']);
			}
		}
	} else {
		$name = ncmExecute('SELECT outletName FROM outlet WHERE outletId = ? AND companyId = ?', [$id, COMPANY_ID], true);
		if ($name) {
			return toUTF8($name['outletName']);
		} else {
			return 'Todas';
		}
	}
}

function getLocationName($id)
{
	if ($id) {
		$name = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
		if ($name) {
			return toUTF8($name['taxonomyName']);
		} else {
			return 'Principal';
		}
	} else {
		return 'Principal';
	}
}

function getAllUnpaidParentsTransactions($cache = false)
{
	global $db, $ADODB_CACHE_DIR;
	$roc 	= getROC(1);
	$date 	= '';
	$a 		= [];
	$sql 	= 'SELECT SUM(transactionTotal) as payed, transactionParentId FROM transaction WHERE transactionType = 5' . $roc . ' GROUP BY transactionParentId';

	$result = ncmExecute($sql, [], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['transactionParentId']] = $result->fields['payed'];
			$result->MoveNext();
		}
		$result->Close();
	}

	return $a;
}

function getAllToPayTransactions($cache = false, $where = '')
{
	global $db, $ADODB_CACHE_DIR, $SQLcompanyId;

	$date 	= '';
	$a 			= [];
	$sql 		= 'SELECT SUM(ABS(transactionTotal)) as payed, transactionParentId FROM transaction WHERE transactionType IN(5,6) AND companyId = ? ' . $where . ' GROUP BY transactionParentId';

	$result = ncmExecute($sql, [COMPANY_ID], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['transactionParentId']] = abs($result->fields['payed'] ?? 0);
			$result->MoveNext();
		}
		$result->Close();
	}

	return $a;
}

function getAllPayingCompaniesData()
{
	global $db, $SQLcompanyId;
	$a 		= [];
	$result = $db->Execute("SELECT * FROM company WHERE plan IN (1,2,5,7) AND status = 'Active'");

	while (!$result->EOF) {
		$a[$result->fields['companyId']] = array(
			'balance'		=> $result->fields['balance'],
			'date'			=> $result->fields['createdAt'],
			'plan'			=> $result->fields['plan'],
			'lastUpdate'	=> $result->fields['companyLastUpdate'],
			'expires'		=> $result->fields['expiresAt'],
			'discount'		=> $result->fields['discount'],
		);
		$result->MoveNext();
	}
	$result->Close();

	return $a;
}

function getAllTransactionPayments($from = false, $to = false, $roc = false, $cache = false)
{
	global $db, $ADODB_CACHE_DIR;

	$roc 		= (!$roc) ? getROC(1) : $roc;
	$date 		= '';

	if ($from && $to) {
		$date 	= ' transactionDate BETWEEN "' . $from . '" AND "' . $to . '" AND';
	}

	$a 			= [];
	$sql 		= 'SELECT transactionTotal, userId, transactionDate, transactionPaymentType, transactionParentId, invoiceNo, transactionDetails, transactionId FROM transaction WHERE' . $date . ' transactionType = 5 ' . $roc;

	$result 	= ncmExecute($sql, [], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;
			$pushIt =  [
				'id'		=> $fields['transactionId'],
				'total'		=> $fields['transactionTotal'],
				'userid'	=> $fields['userId'],
				'date'		=> $fields['transactionDate'],
				'methods'	=> $fields['transactionPaymentType'],
				'number'	=> $fields['invoiceNo'],
				'details'	=> $fields['transactionDetails']
			];

			if (!isset($a[$fields['transactionParentId']])) {
				$a[$fields['transactionParentId']] = [];
			}

			array_push($a[$fields['transactionParentId']], $pushIt);

			$result->MoveNext();
		}

		$result->Close();
	}

	return $a;
}

function getAllTransactions($cache = false, $roc = false)
{
	global $db, $startDate, $endDate, $ADODB_CACHE_DIR;
	if (!$roc) {
		$roc 	= getROC(1);
	}
	$a 		= array();
	$sql 	= "SELECT transactionTotal,
						transactionId,
						transactionType,
						transactionDiscount,
						customerId,
						outletId,
						registerId,
						invoiceNo
				FROM transaction 
				WHERE transactionType IN (0,3)
				AND transactionDate 
				BETWEEN ? 
				AND ? 								
				" . $roc . "
				ORDER BY transactionDate 
				DESC";

	if ($cache) {
		$result = $db->cacheExecute($sql, array($startDate, $endDate));
	} else {
		$result = $db->Execute($sql, array($startDate, $endDate));
	}

	while (!$result->EOF) {
		$a[$result->fields['transactionId']] = array(
			"total"		=> $result->fields['transactionTotal'],
			"id"		=> $result->fields['transactionId'],
			"type"		=> $result->fields['transactionType'],
			"discount"	=> $result->fields['transactionDiscount'],
			"customer"	=> $result->fields['customerId'],
			"user"		=> $result->fields['userId'] ?? '',
			"outlet"	=> $result->fields['outletId'],
			"register"	=> $result->fields['registerId'],
			"invoiceno" => $result->fields['invoiceNo']
		);
		$result->MoveNext();
	}
	$result->Close();
	//
	return $a;
}

function getAllTransactionsRaw($cache = false, $roc = false, $limit = false)
{
	global $db, $startDate, $endDate, $ADODB_CACHE_DIR;
	$limit = iftn($limit, '', ' LIMIT ' . $limit);
	if (!$roc) {
		$roc 	= getROC(1);
	}
	$a 		= array();
	$sql 	= "SELECT 
						transactionId,
						transactionTotal,
						transactionType,
						transactionDiscount,
						customerId,
						userId,
						outletId,
						registerId,
						invoiceNo
				FROM transaction 
				WHERE transactionType IN (0,3)
				AND transactionDate 
				BETWEEN ? 
				AND ? 								
				" . $roc . $limit;



	if ($cache) {
		return $db->CacheGetAssoc('3600', $sql, array($startDate, $endDate));
	} else {
		return $db->GetAssoc($sql, array($startDate, $endDate));
	}
}

function getAllTransactionTaxes($ids, $cache = false)
{
	$out = [];
	if (validity($ids)) {
		$result = ncmExecute('SELECT * FROM toTaxObj WHERE transactionId IN(' . $ids . ') AND companyId = ? LIMIT 1000', [COMPANY_ID], $cache, true);

		if ($result) {
			while (!$result->EOF) {
				$fields = $result->fields;

				$trId 			= $fields['transactionId'];
				$decoded 		= json_decode($fields['toTaxObjText'], true);
				$newTaxes 		= [];

				if (validity($decoded, 'array')) {
					foreach ($decoded as $k => $value) {
						$newTaxes[$value['name']] = $value['val'];
					}

					$out[$trId] 	= $newTaxes;
				}

				$result->MoveNext();
			}
			$result->Close();
		}
	}

	return $out;
}

function getIndexFromArray($array, $field, $value, $multi = false)
{
	$a = [];
	if ($array) {
		foreach ($array as $index => $arr) {
			if ($arr[$field] == $value) {
				if (!$multi) {
					return $index;
				} else {
					$a[] = $index;
				}
			}
		}
		return ($multi) ? $a : false;
	}
}

function getAllItemsRaw($parents = false, $cache = false)
{
	global $db, $ADODB_CACHE_DIR;
	//GET ALL ITEMS ARRAY

	if ($parents == 'children') {
		$parent = ' AND itemParentId > 0';
	} else if ($parents == 'noparent') {
		$parent = ' AND itemIsParent = 0';
	} else {
		$parent = '';
	}

	$sql 	= "SELECT itemId,
						itemName,
						itemPrice,
						brandId,
						categoryId,
						itemIsParent,
						itemParentId,
						itemSKU,
						itemType,
						taxId,
						itemComissionPercent,
						itemCategory
				FROM item 
				WHERE itemStatus = 1" . $parent . " 
				AND companyId = " . COMPANY_ID;



	if ($cache) {
		return $db->CacheGetAssoc('3600', $sql);
	} else {
		return $db->GetAssoc($sql);
	}
}

function getAllContactsRaw($type = false, $index = false, $cache = false, $fields = false, $where = '', $test = false)
{
	global $db, $startDate, $endDate;
	//GET ALL CUSTOMERS ARRAY
	$typeand 	= '';
	$fieldsAre = 'contactId,
					contactRealId,
					contactId,
					contactName,
					contactSecondName,
					contactEmail,
					contactAddress,
					contactPhone,
					contactNote,
					contactCity,
					contactCountry,
					contactTIN,
					contactDate,
					role,
					lockPass,
					outletId,
					updated_at';

	/*if((COMPANY_ID == INCOME_COMPANY_ID) && $type > 0){
		$result = $db->Execute("SELECT * FROM company");

		$sql = "SELECT companyId as contactId, settingName as contactName, settingRUC as contactTIN FROM company";

		return $db->GetAssoc($sql);
	}else{*/
	if ($type > -1) {
		$typeand = ' AND type = ' . $type;
	}
	$indexs = 'contactId';

	if ($index === 0) {
		$indexs = 'contactId';
	} else if ($index === 1) {
		$indexs = 'contactRealId';
	}

	if ($fields) {
		$fieldsAre = $fields;
	}

	$sql = "SELECT
					" . $indexs . ",
					" . $fieldsAre . "
				FROM contact 
				WHERE companyId = " . COMPANY_ID . $where . "
				" . $typeand;

	if ($test) {
		dai($sql);
	}

	if ($cache) {
		$out = $db->CacheGetAssoc('3600', $sql);
	} else {
		$out = $db->GetAssoc($sql);
	}

	return $out;
	//}
}

function getAllItemSold($transId, $cache = false, $countOnly = false)
{
	global $db, $startDate, $endDate, $ADODB_CACHE_DIR;

	if (!$transId) {
		return false;
	}

	$sql 	= "SELECT itemSoldId,
						itemId,
						itemSoldUnits,
						itemSoldTotal, 
						itemSoldTax, 
						itemSoldDiscount,
						itemSoldDate,
						transactionId
				FROM itemSold 
				WHERE itemSoldDate 
				BETWEEN ? 
				AND ? 								
				AND transactionId IN (" . $transId . ")";

	if ($countOnly) {
		$sql 	= "SELECT COUNT(itemSoldId)
				FROM itemSold 
				WHERE itemSoldDate 
				BETWEEN ? 
				AND ? 								
				AND transactionId IN (" . $transId . ")";
		$out = $db->Execute($sql, array($startDate, $endDate));
		return $out->RecordCount();
	}

	if ($cache) {
		return $db->CacheGetAssoc('3600', $sql, array($startDate, $endDate));
	} else {
		return $db->GetAssoc($sql, array($startDate, $endDate));
	}
}

function getAllGiftcardSoldTotal($startDate, $endDate)
{
	global $db;

	$items 	= ncmExecute('SELECT STRING_AGG(itemId::text, \',\') as ids FROM item WHERE itemType = \'giftcard\' AND companyId = ' . COMPANY_ID);
	$total 	= 0;

	if ($items) {
		$totalis 	= ncmExecute("SELECT SUM(itemSoldTotal) as total FROM itemSold WHERE itemSoldTotal > 0 AND itemId IN(" . $items['ids'] . ") AND itemSoldDate BETWEEN ? AND ?", [$startDate, $endDate]);
		if ($totalis) {
			$total = $totalis['total'];
		}
	}

	return $total;
}

function getTransactionTypeIcons($type)
{
	$icons = [];
	if ($type == 13) { //schedule
		$icons = [
			0 => 'stars',
			1 => 'thumb_up',
			2 => 'keyboard_arrow_down',
			3 => 'keyboard_arrow_right',
			4 => 'block',
			5 => 'person_add_disabled',
			6 => 'check',
			7 => 'block'
		];
	} else if (in_array($type, [0, 3])) { //venta

	}

	return $icons;
}

function getTransactionTypeName($type)
{
	$out = 'Venta';
	if ($type == 13) { //schedule
		$out = 'Cita o Reserva';
	} else if (in_array($type, [0, 3])) { //venta
		$out = 'Venta';
	} else if ($type == 12) {
		$out = 'Orden';
	} else if ($type == 11) {
		$out = 'Reserva de espacio o mesa';
	} else if ($type == 9) {
		$out = 'Cotización';
	} else if ($type == 6) {
		$out = 'Nota de Crédito o Devolución';
	}

	return $out;
}

function getCashFlowReceivedPayments($typePay, $typeTrans, $roc, $startDate, $endDate)
{
	$limits = "";
	$sql 				= 	"SELECT *
									FROM transaction 
									WHERE transactionType IN (" . $typePay . ")
										AND transactionDate 
										BETWEEN ?
										AND ? 
								  	" . $roc . "
								  ORDER BY transactionDate DESC" . $limits;

	$result 		= ncmExecute($sql, [$startDate, $endDate], false, true, true);
	$finalSum 	= 0;

	if ($result) {

		foreach ($result as $key => $fields) {
			$parentIs = ncmExecute('SELECT transactionType, invoiceNo, invoicePrefix FROM transaction WHERE transactionId = ? AND transactionType = ' . $typeTrans . ' AND companyId = ? LIMIT 1', [$fields['transactionParentId'], COMPANY_ID], true);

			if ($parentIs) {
				$finalSum += $fields['transactionTotal'];
			}
		}
	}

	return $finalSum;
}

function getTotalScheduleByStatus($status, $startDate, $endDate, $roc, $id, $type = '0')
{
	$stat 		= '';
	$limit 		= 50000;
	$theType 	= 'userId';
	$contact = "";
	if ($status !== false) {
		$stat = ' AND transactionStatus = ' . $status;
	}

	if ($type == 1) {
		$theType = 'customerId';
	}

	if ($id) {
		$contact 	= ' AND ' . $theType . ' = ' . $id . ' ';
		$limit 		= 1;
		//$roc 	= ' AND companyId = ' . COMPANY_ID . ' ';
	}

	$result 		= ncmExecute("	SELECT COUNT(transactionId) as total
									FROM transaction
									WHERE transactionType = 13 
									" . $contact . "
									" . $stat . "
									AND fromDate
									BETWEEN ?
									AND ?" . $roc . " LIMIT " . $limit, [$startDate, $endDate], true);
	return $result['total'];
}

function getROC($register = false, $outlet = false, $company = false)
{
	// Register o Outlet o Company — devuelve un fragmento SQL WHERE.
	// getROC(1) es un flag legacy que significa "sin filtro de caja, solo sucursal/empresa".
	// Solo se filtra por register/outlet si el valor es un UUID real (36 chars).

	$register = iftn($register, REGISTER_ID);
	$outlet   = iftn($outlet,   OUTLET_ID);
	$company  = iftn($company,  COMPANY_ID);

	$roc = " AND companyId = '$company'";

	$isUUID = fn($v) => is_string($v) && strlen($v) === 36;

	if ($isUUID($register)) {
		$roc = " AND registerId = '$register'";
	} elseif ($isUUID($outlet)) {
		$roc = " AND outletId = '$outlet'";
	}

	return $roc;
}

function getAllOutletsNames()
{
	global $allOutletsArray;

	$names = '';
	foreach ($allOutletsArray as $name) {
		$names .= toUTF8($name['name']) . ', ';
	}

	return $names;
}

function getAllOutlets($companyId = false)
{
	global $db;
	//GET ALL OUTLETS ARRAY

	if (isset($_SESSION['NCM_ALLS']['ALL_OUTLETS'])) {
		return $_SESSION['NCM_ALLS']['ALL_OUTLETS'];
	}

	$a 				= [];
	$companyId 		= iftn($companyId, COMPANY_ID);
	$result 		= ncmExecute("SELECT outletName, outletId, outletLatLng FROM outlet WHERE companyId = ? LIMIT 50", [$companyId], true, true);

	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;
			$lat 	= 0;
			$lng 	= 0;

			if ($fields['outletLatLng']) {
				$latLng = explodes(',', $fields['outletLatLng']);
				$lat 	= $latLng[0];
				$lng 	= $latLng[1];
			}

			$a[$fields['outletId']] = 	[
				"name" 		=> $fields['outletName'],
				"id" 			=> $fields['outletId'],
				"lat" 		=> $lat,
				"lng" 		=> $lng,
				"latLng" 	=> $fields['outletLatLng']
			];
			$result->MoveNext();
		}
		$result->Close();

		$_SESSION['NCM_ALLS']['ALL_OUTLETS'] = $a;
	}

	//
	return $a;
}

function getAllPlans($planId = false)
{
	global $db;
	$plans 	= [];
	$result = ncmExecute("SELECT * FROM plans", [], false, true);
	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;
			$plans[$fields['id']] = $fields;/*[
		    								"id"			=> $fields['id'],
											"name"			=> $fields['name'],
											"type"			=> $fields['type'],
											"price"			=> $fields['price'],
											"max_users"		=> $fields['max_users'],
											"max_items"		=> $fields['max_items'],
											"max_customers"	=> $fields['max_customers'],
											"max_outlets"	=> $fields['max_outlets'],
											"max_registers"	=> $fields['max_registers'],
											"max_suppliers"	=> $fields['max_suppliers'],
											"max_categories"=> $fields['max_categories'],
											"max_brands"	=> $fields['max_brands'],
											"bulk_btns"		=> $fields['bulk_btns'],
											"expenses"		=> $fields['expenses'],
											"purchase" 		=> $fields['purchase'],
											"tags"			=> $fields['tags'],
											"clockinout"	=> $fields['clockinout'],
											"satisfaction"	=> $fields['satisfaction'],
											"orders" 		=> $fields['orders'], 
											"geosales"		=> $fields['geosales'],
											"custom_payments"=>$fields['custom_payments'],
											"ecommerce"		=> $fields['ecommerce'],
											"duration_days"	=> $fields['duration_days'],
											"inventory" 	=> $fields['inventory'],
											"inventory_count"=> $fields['inventory_count'],
											"batch_inventory"=> $fields['batch_inventory'],
											"basicSettings" => $fields['basicSettings'],
											"production" 	=> $fields['production'],
											"drawerControl" => $fields['drawerControl'],
											"activityLog" 	=> $fields['activityLog'],
											"loyalty" 		=> $fields['loyalty'],
											"storeCredit" 	=> $fields['storeCredit'],
											"storeTables" 	=> $fields['storeTables'],
											"schedule" 		=> $fields['schedule'],
											"customerRecords" => $fields['customerRecords'],
											"notify" 		=> $fields['notify']
											];*/
			$result->MoveNext();
		}
		$result->Close();
	}
	//
	if ($planId) {
		return $plans[$planId];
	} else {
		return $plans;
	}
}

function getAllCompanies()
{
	global $db;
	//GET ALL OUTLETS ARRAY
	$a = array();
	$result = $db->Execute("SELECT * FROM company LIMIT 10000");
	while (!$result->EOF) {
		$a[] = $result->fields['companyId'];
		$result->MoveNext();
	}
	$result->Close();
	//
	$b = array();
	$result = $db->Execute("SELECT * FROM company");
	$c = 0;
	while (!$result->EOF) {
		$b[$a[$c]] = array(
			"name" 	=> toUTF8($result->fields['settingName']),
			"id" 	=> toUTF8($a[$c])
		);
		$c++;
		$result->MoveNext();
	}
	$result->Close();
	return $b;
}

function getAllRegisters($cache = true)
{
	//GET ALL REGISTERS ARRAY
	if (isset($_SESSION['NCM_ALLS']['ALL_REGISTERS'])) {
		return $_SESSION['NCM_ALLS']['ALL_REGISTERS'];
	}

	$a 		= [];
	$result = ncmExecute("SELECT * FROM register WHERE companyId = ? LIMIT 80", [COMPANY_ID], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['registerId']] = [
				"id" 										=> $result->fields['registerId'],
				"name" 									=> toUTF8($result->fields['registerName']),
				"invoiceNo" 						=> $result->fields['registerInvoiceNumber'],
				"invoicePrefix"					=> $result->fields['registerInvoicePrefix'],
				"invoiceAuthNo"					=> $result->fields['registerInvoiceAuth'],
				"invoiceAuthExpiration"	=> $result->fields['registerInvoiceAuthExpiration'],
				"quoteNo" 							=> $result->fields['registerQuoteNumber']
			];
			$result->MoveNext();
		}

		$result->Close();

		//$_SESSION['NCM_ALLS']['ALL_REGISTERS'] = $a;
	} else {

		$a[0] = [
			"name" => 'None'
		];
	}
	//

	return $a;
}

function getAllRoles()
{
	global $db;
	//GET ALL REGISTERS ARRAY
	$a = [];
	$result = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'role'", [], true, true);

	if ($result) {
		while (!$result->EOF) {
			$field = $result->fields;
			$a[$field['taxonomyExtra']] = 	[
				"name" => toUTF8($field['taxonomyName'])
			];
			$result->MoveNext();
		}
		$result->Close();
	}
	//
	return $a;
}

function getRoleName($id)
{
	if ($id) {
		$role = getAllRoles();
		return toUTF8($role[$id]['name']);
	} else {
		return 'None';
	}
}

function getAllTaxonomy($type, $companyId = COMPANY_ID)
{
	$a = [];
	$result = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = ? AND companyId = ? ORDER BY taxonomyName ASC LIMIT 1000", [$type, $companyId], false, true);

	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['taxonomyId']] = [
				"name" 	=> toUTF8($result->fields['taxonomyName']),
				"extra" => $result->fields['taxonomyExtra']
			];
			$result->MoveNext();
		}
		$result->Close();
	}
	//
	return $a;
}

function array_pushit($array1, $array2)
{
	if ($array1) {
		array_push($array1, $array2);
	} else {
		$array1 = $array2;
	}

	return $array1;
}

function divider($val1, $val2, $force = false)
{
	if ($val1 > 0 && $val2 > 0) {
		if ($force) {
			return $val1 / $val2;
		}

		if ($val1 > $val2) {
			$out = ($val1 / $val2);
		} else {
			$out = ($val2 / $val1);
		}
	} else {
		$out = 0;
	}

	return $out;
}

function rester($first, $second)
{
	if ($first > $second) {
		return floatval($first) - floatval($second);
	} elseif ($first < $second) {
		return floatval($second) - floatval($first);
	} else {
		return 0;
	}
}

function percenter($value, $percent)
{
	if ($value && $percent) {
		$perce = ($value * abs($percent ?? 0)) / 100;
		if ($percent > -1) {
			return $value + $perce;
		} else {
			return $value - $perce;
		}
	} else {
		return $value;
	}
}

function getAllTax()
{
	global $db, $SQLcompanyId;

	if (isset($_SESSION['NCM_ALLS']['ALL_TAX'])) {
		return $_SESSION['NCM_ALLS']['ALL_TAX'];
	}

	$result = ncmExecute("SELECT taxonomyName, taxonomyId FROM taxonomy WHERE taxonomyType = 'tax' AND companyId = ? ORDER BY taxonomyName ASC LIMIT 100", [COMPANY_ID], false, true);

	$tax 		= [];
	$added 	= [];

	if ($result) {
		while (!$result->EOF) {

			$fields = $result->fields;

			if (!in_array($fields['taxonomyName'], $added)) {
				$tax[$fields['taxonomyId']] = 	[
					"name" => toUTF8($fields['taxonomyName'])
				];

				array_push($added, $fields['taxonomyName']);
			}

			$result->MoveNext();
		}

		$result->Close();

		//$_SESSION['NCM_ALLS']['ALL_TAX'] = $tax;
	}

	return $tax;
	//
}

function getTaxTotalsBySaleItems($items)
{
	$allTaxes = [];

	foreach ($items as $key => $line) {
		$tax				= $line['tax'];
		$currentTax = getTaxOfPrice($tax, $line['total']);

		if ($allTaxes['tax'][$tax]) {
			$allTaxes['tax'][$tax] 		+= $currentTax;
			$allTaxes['grav'][$tax] 	+= ($line['total'] - $currentTax);
			$allTaxes['total'][$tax] 	+= $line['total'];
		} else {
			$allTaxes['tax'][$tax] 		= $currentTax;
			$allTaxes['grav'][$tax] 	= ($line['total'] - $currentTax);
			$allTaxes['total'][$tax] 	= $line['total'];
		}
	}

	return $allTaxes;
}

function getPriceRule($parentId)
{
	if (!$parentId) {
		return '';
	}

	$parent 		= ncmExecute("SELECT data FROM item WHERE itemId = ? AND companyId = ? LIMIT 1", [dec($parentId), COMPANY_ID]);
	if ($parent) {
		$out = json_decode($parent['data'], true);
	} else {
		$out 	= '';
	}

	return $out;
}

function getPricesOfParents($items, $parentId)
{
	if (!$items || !$parentId) {
		return '';
	}

	$out = [];

	foreach ($items as $item) {
		if (isset($item['parent']) && $item['parent'] == $parentId && isset($item['price']) && $item['price'] > 0) {
			$out[] = $item['price'];
		}
	}

	if (empty($out)) {
		return '';
	}

	return $out;
}

function getAllTags($encoded = false, $cache = false)
{
	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = 'tag' AND (companyId = ? OR companyId = 0)", [COMPANY_ID], $cache, true);
	$tax = [];

	if ($encoded) {
		$id = enc(166227);
	} else {
		$id = 166227;
	}

	$tax[$id] =	[
		"name" => "INTERNO"
	];

	if ($result) {
		while (!$result->EOF) {
			if ($encoded) {
				$id = enc($result->fields['taxonomyId']);
			} else {
				$id = $result->fields['taxonomyId'];
			}

			$tax[$id] =	[
				"name" => toUTF8($result->fields['taxonomyName'])
			];
			$result->MoveNext();
		}
		$result->Close();
	}

	return $tax;
	//
}

//INVENTORY LOGIC
function removeFromArrayByKey($array, $key)
{
	$i = 0;
	while ($i < counts($array)) {
		unset($array[$i][$key]);
		$i++;
	}
	return $array;
}

function searchInArray($array, $field, $match)
{
	foreach ($array as $key => $val) {
		if ($val[$field] == $match) {
			return $key;
			break;
		}
	}
	return -1;
}

function sumProperties($arr, $property)
{
	$sum = 0;
	foreach ($arr as $object) {
		$sum += isset($object[$property]) ? $object[$property] : 0;
	}
	return $sum;
}

function insertNegativeBatch($itemId)
{
	$roc 	= getROC(1);
	$result = ncmExecute('SELECT * FROM inventory WHERE inventoryCount <= 0 ' . $roc . ' LIMIT 1');
	if ($result) { //si hay lote
		$value = $result['inventoryCount'];
	}
}

function substractTheRightBatch($need, $lotes)
{
	$rest 		= [];
	$i 			= 0;
	$allLotes 	= sumProperties($lotes, 'count');

	if ($allLotes > $need) {
		while ($need > sumProperties($rest, 'count')) {
			$restSu 	= sumProperties($rest, 'count');
			$c 			= $lotes[$i]['count'];
			$id 		= $lotes[$i]['id'];
			$cogs 		= $lotes[$i]['cogs'];
			$expires 	= $lotes[$i]['expires'];
			$waste 		= $lotes[$i]['waste'];
			$uid 		= $lotes[$i]['uid'];
			$supplier 	= $lotes[$i]['supplier'];
			$restSumP 	= $restSu + $c;

			if ($restSumP > $need) { //si el lote tiene mas de lo que necesito para completar, solo actualizo
				array_push($rest, [
					'id'		=> $id,
					'date'		=> $date,
					'count'		=> abs($restSu - $need ?? 0),
					'cogs'		=> $cogs,
					'expires'	=> $expires,
					'waste'		=> $waste,
					'uid'		=> $uid,
					'supplier'	=> $supplier,
					'soldout'	=> false
				]);
				break;
			} else { //si la cantidad a descontar es mayor o igual al total del lote, elimino el lote

				$difference = $restSumP - $need;

				array_push($rest, [
					'id'			=> $id,
					'date'			=> $date,
					'count'			=> $c,
					'cogs'			=> $cogs,
					'expires'		=> $expires,
					'waste'			=> $waste,
					'uid'			=> $uid,
					'supplier'		=> $supplier,
					'soldout'		=> true,
					'soldoutcount' 	=> $difference
				]);
			}

			$i++;
		}
	} else {
		foreach ($lotes as $val) {

			$difference = $allLotes - $need;

			array_push($rest, [
				'id'			=> $val['id'],
				'date'			=> $val['date'],
				'count'			=> $val['count'],
				'cogs'			=> $val['cogs'],
				'expires'		=> $val['expires'],
				'uid'			=> $val['uid'],
				'supplier'		=> $val['supplier'],
				'soldout'		=> true,
				'soldoutcount' 	=> $difference
			]);
		}
	}

	return $rest;
}

function getInventoryByAcountingType($id, $outlet = OUTLET_ID, $limit = '')
{ //fifo lifo random

	$outId 	= ($outlet) ? $outlet : OUTLET_ID;
	$result = ncmExecute('SELECT inventoryMethod as meth FROM item WHERE itemId = ? AND itemTrackInventory > 0 LIMIT 1', [$id]);

	if ($result) {
		if (!$fields['meth'] || $fields['meth'] < 1) {
			//fifo
			$orderBy = ' ORDER BY inventoryDate ASC';
		} else if ($fields['meth'] == 1) {
			//lifo
			$orderBy = ' ORDER BY inventoryDate DESC';
		} else if ($fields['meth'] == 2) {
			//ramdpon
			$orderBy = ' ORDER BY RAND()';
		} else if ($fields['meth'] == 3) {
			//FEFO
			$orderBy = ' ORDER BY inventoryExpirationDate ASC';
		}

		$batches 	= [];

		$inv 		= ncmExecute('SELECT * FROM inventory WHERE inventoryType = 0 AND inventoryCount > 0 AND itemId = ? AND outletId = ? ' . $orderBy . $limit, [$id, $outId], false, true);

		if ($inv) {
			while (!$inv->EOF) {
				$fields = $inv->fields;
				array_push($batches, [
					"id"		=> $fields['inventoryId'],
					"date"		=> $fields['inventoryDate'],
					"count"		=> $fields['inventoryCount'],
					"cogs"		=> $fields['inventoryCOGS'],
					"expires"	=> $fields['inventoryExpirationDate'],
					"supplier"	=> $fields['supplierId'],
					"uid"		=> $fields['inventoryUID']
				]);
				$inv->MoveNext();
			}

			$inv->Close();

			return $batches;
		} else {
			return false;
		}
	} else {
		return false;
	}
}

function discountInventoryBatch($itemId, $count, $substract = true, $batches = false)
{
	global $db, $SQLcompanyId;

	if (!$batches) {
		$batches = getInventoryByAcountingType($itemId);
	}

	if ($batches && counts($batches) > 0) { //si hay batches
		/*preparo un array con con la cantidad a sustraer de cada batch (o eliminar si consumo todo el inventario)*/
		$prepared 	= substractTheRightBatch($count, $batches);
		$case 		= [];
		$in 		= [];
		$flagSold 	= '';
		$sign 		= '+';
		$totalCOGS 	= 0;

		foreach ($prepared as $nbatch) {
			$less 		= $nbatch['count'];
			$id 		= $nbatch['id'];
			$cogs 		= $nbatch['cogs'];
			$soldout 	= $nbatch['soldout'];

			if ($substract) {
				$sign = '-';
			}

			if ($soldout) { //si consumo todo el inventario lo elimino ya que no me sirve de nada en el panel
				//0 = Inventario activo, 1 = Merma o inactivo 2 = vendido
				$db->Execute('DELETE FROM inventory WHERE inventoryId = ?', [$id]);
				//$db->AutoExecute('inventory', array('inventoryType'=>1), 'UPDATE', 'inventoryId = '.$id);
			} else {
				$case[]  	= 'WHEN inventoryId = ' . $id . ' THEN inventoryCount' . $sign . $less;
				$in[]    	= $id;
			}

			$totalCOGS 	+= $cogs * $less;
		}

		if (validity($case) && validity($in)) {
			$db->Execute('UPDATE inventory 
	                        SET inventoryCount = (CASE ' . implodes(' ', $case) . ' END) 
	                        WHERE inventoryId in (' . implodes(',', $in) . ')
	                        AND companyId = ? 
	                        AND outletId = ?', array(COMPANY_ID, OUTLET_ID));
		}

		return $totalCOGS;
	}
}

function transferInventoryBatch($itemId, $count, $from, $to, $lFrom, $lTo)
{
	global $db, $SQLcompanyId;
	//obtener la cantidad de stock antes de transferir, del from y del to
	//luego btengo la cantidad de stock luego de la transferencia
	$ops 			  = [];

	$count 			  = formatNumberToInsertDB($count, true, 5);

	//sustraigo
	$ops['itemId']    = $itemId;
	$ops['outletId']  = $from;
	$ops['locationId'] = $lFrom;
	$ops['count']     = $count;
	$ops['type']      = '-';
	$ops['source']    = 'transfer';

	$done = manageStock($ops);

	if ($done) {
		$ops 			  = [];
		//inserto
		$ops['itemId']    = $itemId;
		$ops['outletId']  = $to;
		$ops['locationId'] = $lTo;
		$ops['count']     = $count;
		$ops['type']      = '+';
		$ops['source']    = 'transfer';

		return manageStock($ops);
	} else {
		return false;
	}
}

function outletOrLocation($outlet)
{

	if (!validity($outlet)) {
		return [];
	}

	$isOutlet       = ncmExecute('SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1', [$outlet, COMPANY_ID]);

	if (!$isOutlet) { //si no es outlet es location
		$outletId     = ncmExecute('SELECT outletId FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$outlet, COMPANY_ID]);
		$location     = $outlet;
		$outlet       = $outletId['outletId'];
	} else {
		$location     = NULL;
	}

	return [$outlet, $location];
}

function countExpiringInventory($itemId = false, $outletId = false)
{

	$oneWeek	= date('Y-m-d 01:00:00', strtotime("+1 week"));
	$today		= date('Y-m-d 01:00:00');
	$item 		= '';
	$outlet		= '';
	$count 		= 0;

	if ($itemId) {
		$item = ' AND itemId = ' . $itemId;
	}

	if ($outletId) {
		$outlet = ' AND outletId = ' . $outletId;
	}

	$inventory 	= ncmExecute('SELECT SUM(inventoryCount) as count FROM inventory WHERE inventoryExpirationDate BETWEEN "' . $today . '" AND "' . $oneWeek . '"' . $item . $outlet . ' AND inventoryType < 1 AND companyId = ' . COMPANY_ID);

	if ($inventory) {
		$count 		= $inventory['count'];
	}

	return $count;
}

function getAllInventory($tax = false, $cache = false, $itemId = false, $outletId = false)
{
	global $db, $ADODB_CACHE_DIR;

	$roc 	= getROC(1); //el uno evita que filtre por caja
	$inv 	= [];
	$taxit 	= '';
	$itm 	= '';

	if ($itemId) {
		$itm 	= ' AND itemId = ' . dec($itemId);
	}

	if ($outletId) {
		$roc 	= ' AND outletId = ' . dec($outletId);
	}

	if ($tax) {
		$taxit = ', taxId';
	}

	$sql = 'SELECT itemId, SUM(inventoryCount) as count, inventoryCOGS as cogs' . $taxit . ' 
			FROM inventory 
			WHERE inventoryCount > 0 
			AND inventoryType = 0' .
		$itm .
		$roc . ' 
			GROUP BY itemId 
			ORDER BY inventoryId DESC';

	$result = ncmExecute($sql, false, $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;
			if ($tax) {
				$inv[$fields['itemId']] = [
					"count" 	=> $fields['count'],
					"cogs"		=> $fields['cogs'],
					"tax"		=> $fields['taxId']
				];
			} else {
				$inv[$fields['itemId']] = [
					"count"		=> $fields['count'],
					"cogs"		=> $fields['cogs'],
					"reorder"	=> $fields['reorder'] ?? ''
				];
			}
			$result->MoveNext();
		}
		$result->Close();
	}

	return $inv;
	//
}

function getAllIndividualInventory($id = false, $cache = false, $enc = 'si')
{
	global $db, $plansValues;

	$roc = str_replace(['companyId', 'outletId'], ['a.companyId', 'a.outletId'], getROC(1));

	//GET ALL ITEMS ARRAY
	$item 			= [];
	$outlet 		= [];
	$inventoryCount = '';
	$sqlItemId 		= 'AND b.itemId = a.itemId';

	if ($plansValues[PLAN]['purchase']) {
		$inventoryCount = 'inventoryCount > 0 AND ';
	}

	if (validity($id)) {
		$sqlItemId 	= 'AND a.itemId = ' . $id;
	}

	$sql = '
			SELECT a.inventoryId, 
					a.inventoryDate, 
					a.inventoryCOGS, 
					a.inventoryExpirationDate, 
					a.inventoryCount, 
					a.supplierId,
					a.itemId,
					a.outletId
			FROM inventory a, item b 
			WHERE b.itemIsParent = 0
            AND b.itemTrackInventory = 1
            ' . $sqlItemId . ' 
			AND a.outletId > 0 
			AND a.itemId > 0 
			AND a.inventoryType = 0
			AND a.inventoryCount > 0
			' . $roc;

	$result = ncmExecute($sql, [], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$fields 		= $result->fields;
			$itemId 		= ($enc == 'si') ? enc($fields['itemId']) : $fields['itemId'];
			$outletId 		= ($enc == 'si') ? enc($fields['outletId']) : $fields['outletId'];
			$inventoryId 	= ($enc == 'si') ? enc($fields['inventoryId']) : $fields['inventoryId'];
			$supplierId 	= iftn($fields['supplierId'], '', enc($fields['supplierId']));

			$inventory 		= 	[
				'id'		=> $inventoryId,
				'date'		=> $fields['inventoryDate'],
				'cogs'		=> iftn($fields['inventoryCOGS'], 0),
				'expires'	=> $fields['inventoryExpiringDate'],
				'count'		=> ($fields['inventoryCount'] < 0) ? 0 : $fields['inventoryCount'],
				'supplier'	=> $supplierId,
				'item'		=> $itemId,
				'outlet'	=> $outletId
			];


			if ($item[$itemId][$outletId]) {
				array_push($item[$itemId][$outletId], $inventory);
			} else {
				$item[$itemId][$outletId][] = $inventory;
			}

			$result->MoveNext();
			unset($inventory);
		}
		$result->Close();
	}

	return $item;
	//
}

function getAllIndividualInventoryRaw($cache = false, $itemId = false, $outlet = false)
{
	global $db, $plansValues, $ADODB_CACHE_DIR;

	$roc = str_replace(['companyId', 'outletId'], ['a.companyId', 'a.outletId'], getROC(1));

	if ($outlet) {
		$roc = 'AND a.outletId = ' . dec($outlet) . ' AND a.companyId = ' . COMPANY_ID;
	}

	$itm = 'AND b.itemId = a.itemId';

	if ($itemId) {
		$itm = 'AND a.itemId = ' . dec($itemId);
	}



	//GET ALL ITEMS ARRAY
	$item 		= array();
	$outlet 	= array();
	$inventoryCount = '';
	if ($plansValues[PLAN]['purchase']) {
		$inventoryCount = 'inventoryCount > 0 AND ';
	}

	$sql = 'SELECT 
					a.itemId,
					a.inventoryId, 
					a.inventoryDate, 
					a.inventoryCOGS, 
					a.inventoryExpirationDate, 
					a.inventoryCount, 
					a.supplierId,
					a.outletId
			FROM inventory a, item b 
			WHERE b.itemIsParent = 0
            AND b.itemTrackInventory = 1
            ' . $itm . '
			AND a.outletId > 0 
			AND a.itemId > 0 
			AND a.inventoryType = 0
			AND a.inventoryCount > 0
			' . $roc;

	if ($cache) {
		return $db->CacheGetAssoc('3600', $sql);
	} else {
		return $db->GetAssoc($sql);
	}
}

function getAllInventoryAndItemsModule()
{ //Obtengo, total de costo de inventario, total de unidades de inventario y total de valor de venta de inventario, por sucursal
	/*
	-Selecciono el precio de costo de todos los articulos en venta menos los parents.
	-basandome en su ID selecciono el nivel de inventario de cada articulo
	-multiplico el costo del articulo por su inventario y obtengo el total del valor de su inventario
	-sumo todos los totales y obtengo el valor total general del inventario
	*/
	$inventory 	= getAllItemStock(false, false, false, false, true);
	$items 			= getAllItems(false, true);

	$costoTotal = 0;
	$ventaTotal = 0;
	$cantidad 	= 0;

	foreach ($items as $id => $array) {
		if (array_key_exists($id, $inventory) && $inventory[$id]['onHand'] > 0) {
			$costoTotal += $inventory[$id]['cogs'] * $inventory[$id]['onHand'];
			$ventaTotal += $array['price'] * $inventory[$id]['onHand'];
			$cantidad 	+= $inventory[$id]['onHand'];
		}
	}

	return array($costoTotal, $ventaTotal, $cantidad);
}

function sumInventoryInOutlet($array, $outletId = false)
{
	$count 		= 0;
	$id 		= ($outletId) ? $outletId : OUTLET_ID;

	if ($id > 0 && is_array($array)) {
		$array = $array[enc($id)];
		if (validity($array, 'array')) {
			foreach ($array as $batchs) {
				$count += $batchs['count'];
			}
		} else {
			return 0;
		}
	} else {
		if (validity($array, 'array')) {
			foreach ($array as $outlet) {
				if ($outlet) {
					foreach ($outlet as $batchs) {
						$count += $batchs['count'];
					}
				} else {
					return 0;
				}
			}
		} else {
			return 0;
		}
	}
	return $count;
}

function sumInventoryInOutletNoENC($array, $outletId = false)
{
	$count 		= 0;
	$id 		= ($outletId) ? $outletId : OUTLET_ID;

	if ($id > 0) {
		$array = $array[$id];
		if (validity($array, 'array')) {
			foreach ($array as $batchs) {
				$count += $batchs['count'];
			}
		} else {
			return 0;
		}
	} else {
		if (validity($array, 'array')) {
			foreach ($array as $outlet) {
				if ($outlet) {
					foreach ($outlet as $batchs) {
						$count += $batchs['count'];
					}
				} else {
					return 0;
				}
			}
		} else {
			return 0;
		}
	}
	return $count;
}

function getAverageInventoryCOGS($array, $outletId = false)
{
	$count 		= 0;
	$id 		= ($outletId) ? $outletId : OUTLET_ID;

	if ($id > 0) {
		$arrays = $array[enc($id)];
		if (validity($arrays, 'array')) {
			$i = 0;
			foreach ($arrays as $batchs) {
				if ($batchs['cogs'] > 0) {
					$count += $batchs['cogs'];
					$i++;
				}
			}
			$count = divider($count, $i, true);
		} else {
			return 0;
		}
	} else {
		if (validity($array, 'array')) {
			foreach ($array as $outlet) {
				if ($outlet) {
					$i = 0;
					foreach ($outlet as $batchs) {
						if ($batchs['cogs'] > 0) {
							$count += $batchs['cogs'];
							$i++;
						}
					}
					$count = divider($count, $i, true);
				} else {
					return 0;
				}
			}
		} else {
			return 0;
		}
	}
	return $count;
}

function getItemCOGS($itemId)
{
	if (!validity($itemId)) {
		return false;
	}

	$roc 		= getROC(1);
	$result = ncmExecute('SELECT stockOnHandCOGS FROM stock WHERE itemId = ? ' . $roc . ' AND stockOnHandCOGS > 0 ORDER BY stockId DESC LIMIT 1', [$itemId]);

	if ($result) {
		return $result['stockOnHandCOGS'];
	} else {
		return 0;
	}
}

function getItemCOGSWithWaste($itemId)
{
	if (!validity($itemId)) {
		return false;
	}

	$roc 		= getROC(1);
	$result = ncmExecute('SELECT stockOnHandCOGS, itemId FROM stock WHERE itemId = ? ' . $roc . ' ORDER BY stockId DESC LIMIT 1', [$itemId]);

	$waste 	= !empty($result['itemId']) ? getAllWasteValue($result['itemId']) : false;
	$wasteP = !is_bool($waste) && array_key_exists($result['itemId'], $waste ?? []) ? $waste[$result['itemId']] : 0;

	if ($wasteP > 0) {
		$count 	= getNeedWithWaste(1, $wasteP);
	} else {
		$count 	= 1;
	}

	if ($result) {
		return $result['stockOnHandCOGS'] * $count;
	} else {
		return 0;
	}
}

function getItemTypeName($realType, $result = [])
{
	if ($realType == 'product') {
		if ($result['itemProduction'] > 0) {
			$type 						= 'production';
			$typeName 				= 'Producción Previa';
			$inventoryTools 	= true;
		} else if ($result['itemType'] == 'product' && $result['itemTrackInventory'] < 1 && validity(getCompoundsArray($result['itemId']))) {
			$type 						= 'direct_production';
			$typeName 				= 'Producción Directa';
			$productionTools 	= true;
		} else if ($result['itemCanSale'] < 1) {
			$type 						= 'compound';
			$typeName 				= 'Activo/Compuesto';
			$inventoryTools 	= true;
		} else {
			$typeName 				= 'Producto';
			$productionTools 	= true;
			$inventoryTools 	= true;
		}
	} else if ($realType == 'precombo') {
		$typeName 			= 'Combo Predefinido';
		$comboTools 		= true;
	} else if ($realType == 'combo') {
		$typeName 			= 'Combo Dinámico';
		$comboTools 		= true;
	} else if ($realType == 'comboAddons') {
		$typeName 			= 'Combo Add-on';
		$comboTools 		= true;
		$productionTools 	= false;
	} else if ($realType == 'production') {
		$typeName 			= 'Producción Previa';
		$productionTools 	= true;
	} else if ($realType == 'direct_production') {
		$typeName 			= 'Producción Directa';
		$productionTools 	= true;
	} else if ($realType == 'dynamic') {
		$typeName 			= 'Dinámico';
		$productionTools 	= false;
		$inventoryTools 	= false;
	}

	return [$typeName, $productionTools, $inventoryTools];
}

function deleteItem($id)
{
	global $db;
	if ($id) {
		$db->Execute('UPDATE item SET itemParentId = NULL WHERE itemParentId = ? AND companyId = ?', [$id, COMPANY_ID]);
		$db->Execute('DELETE FROM inventory WHERE itemId = ? AND companyId = ?', [$id, COMPANY_ID]);

		//ITE SOLD
		$itemSold = ncmExecute('SELECT itemSoldId FROM itemSold WHERE itemId = ?', [$id], false, true);
		if ($itemSold) {
			while (!$itemSold->EOF) {
				$iSold = $itemSold->fields;

				$db->Execute('DELETE FROM inventorySold WHERE itemSoldId = ?', [$iSold['itemSoldId']]);

				$itemSold->MoveNext();
			}
			$itemSold->Close();
			$db->Execute('DELETE FROM itemSold WHERE itemId = ?', [$id]);
		}
		//

		//$db->Execute('DELETE FROM inventoryTransfer WHERE itemId = ?', [$id]);
		//$db->Execute('DELETE FROM inventoryHistory WHERE itemId = ?', [$id]);
		$db->Execute('DELETE FROM stock WHERE itemId = ?', [$id]);
		$db->Execute('DELETE FROM stockTrigger WHERE itemId = ?', [$id]);
		$db->Execute('DELETE FROM production WHERE itemId = ?', [$id]);
		$db->Execute('DELETE FROM price WHERE itemId = ?', [$id]);

		$db->Execute('DELETE FROM upsell WHERE upsellParentId = ? AND companyId = ?', [$id, COMPANY_ID]);

		$db->Execute('DELETE FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
		$db->Execute('DELETE FROM toCompound WHERE itemId = ?', [$id, COMPANY_ID]);
		return true;
	} else {
		return false;
	}
}

function deleteItemBulk($ids, $companyId = COMPANY_ID)
{
	global $db;

	if ($ids) {
		$ids = db_prepare($ids);
		$db->Execute('UPDATE item SET itemParentId = NULL WHERE itemParentId IN(' . $ids . ') AND companyId = ?', [$companyId]);
		$db->Execute('DELETE FROM inventory WHERE itemId IN(' . $ids . ') AND companyId = ?', [$companyId]);

		//ITE SOLD
		$itemSold = ncmExecute('SELECT itemSoldId FROM itemSold WHERE itemId IN(' . $ids . ')', [], false, true);
		if ($itemSold) {
			while (!$itemSold->EOF) {
				$iSold = $itemSold->fields;

				$db->Execute('DELETE FROM inventorySold WHERE itemSoldId = ?', [$iSold['itemSoldId']]);

				$itemSold->MoveNext();
			}
			$itemSold->Close();
			$db->Execute('DELETE FROM itemSold WHERE itemId IN(' . $ids . ')');
		}
		//

		$db->Execute('DELETE FROM inventoryTransfer WHERE itemId IN(' . $ids . ')');
		$db->Execute('DELETE FROM inventoryHistory WHERE itemId IN(' . $ids . ')');
		$db->Execute('DELETE FROM stockTrigger WHERE itemId IN(' . $ids . ')');
		$db->Execute('DELETE FROM production WHERE itemId IN(' . $ids . ')');
		$db->Execute('DELETE FROM price WHERE itemId IN(' . $ids . ')');
		$db->Execute('DELETE FROM upsell WHERE upsellParentId IN(' . $ids . ') AND companyId = ?', [$companyId]);
		$db->Execute('DELETE FROM item WHERE itemId IN(' . $ids . ') AND companyId = ?', [$companyId]);
		$db->Execute('DELETE FROM toCompound WHERE itemId IN(' . $ids . ')');
		return true;
	} else {
		return false;
	}
}

function deleteTransaction($id, $adjustStock = true)
{
	global $db;

	if ($id) {
		$transaction = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1', [$id]);
		ncmExecute('DELETE FROM transaction WHERE transactionParentId = ? AND companyId = ?', [$id, COMPANY_ID]);
		ncmExecute('DELETE FROM giftCardSold WHERE transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);
		ncmExecute('DELETE FROM satisfaction WHERE transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);
		ncmExecute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);
		ncmExecute('DELETE FROM comission WHERE transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);
		ncmExecute('DELETE FROM toScheduleUID WHERE transactionUID = ?', [$transaction['transactionUID']]);
		ncmExecute('DELETE FROM toTaxObj WHERE transactionId = ?', [$id]);
		ncmExecute('DELETE FROM toTag WHERE parentId = ?', [$id]);
		ncmExecute('DELETE FROM toPaymentMethod WHERE parentId = ?', [$id]);

		list($outletId, $locationId) = outletOrLocation($transaction['outletId']);

		//ITE SOLD
		if ($adjustStock) {
			$itemSold = ncmExecute('SELECT * FROM itemSold WHERE transactionId = ?', [$id], false, true);
			if ($itemSold) {
				while (!$itemSold->EOF) {
					$iSold 	 = $itemSold->fields;
					$ops     = [];
					$goInv 	 = false;
					if ($iSold['itemId']) {
						if (in_array($transaction['transactionType'], [1, 4])) { //si fue compra vuelvo a restar stock
							$ops['source']      = 'purchase';
							$ops['type']        = '-';
							$goInv 							= true;
						} else if (in_array($transaction['transactionType'], [0, 3])) {
							$ops['source']      = 'sale';
							$ops['type']        = '+';
							$goInv 							= true;
						}

						$ops['itemId']        = $iSold['itemId'];
						$ops['outletId']      = $outletId;
						$ops['locationId']    = $locationId;
						$ops['count']         = $iSold['itemSoldUnits'];

						if ($goInv) {
							manageStock($ops);
						}
					}

					$itemSold->MoveNext();
				}
				$itemSold->Close();
			}
		}
		//

		$db->Execute('DELETE FROM itemSold WHERE transactionId = ?', [$id]);

		return true;
	} else {
		return false;
	}
}

function voidSale($trId, $motive = '')
{
	global $db;
	$compId 	= COMPANY_ID;
	$outId 		= OUTLET_ID;
	$db->StartTrans(); //Esto hace que verifique si mas de una transaccion fallo, en el caso de que solo una falle, todas fallan

	$trId     = dec($trId);

	//veo si tiene cliente la venta y si se uso loyalty obtengo el monto para reponer
	$customer = ncmExecute("SELECT
                               customerId,
                               transactionPaymentType,
                               outletId
                          FROM transaction
                          WHERE
                            transactionId = ? LIMIT 1", [$trId]);

	if ($customer) {

		$group 			= [];
		$payments 	= json_decode($customer['transactionPaymentType'], true);
		$group 			= groupByPaymentMethod($payments, $group);

		if ($group) {
			foreach ($group as $dat) {

				if (validity($customer['customerId'])) {
					if ($dat['type'] == 'points') { //devuelvo loyalties
						ncmExecute('UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount + ' . $dat['price'] . ' WHERE contactId = ?', [$customer['customerId']]);
					} else if ($dat['type'] == 'storeCredit') { //devuelvo credito interno
						ncmExecute('UPDATE contact SET contactStoreCredit = contactStoreCredit + ' . $dat['price'] . ' WHERE contactId = ?', [$customer['customerId']]);
					}
				}

				if ($dat['type'] == 'giftcard' && $dat['price'] > 0) { //si es giftcard devuelvo
					ncmExecute('UPDATE giftCardSold SET giftCardSoldValue = giftCardSoldValue + ' . $dat['price'] . ' WHERE (giftCardSoldCode = ? OR timestamp = ?) AND outletId = ', [$dat['extra'], $dat['extra'], $customer['outletId']]);
				}
			}
		}
	}

	//flagueo la transaccion anulada
	$record['transactionType'] 	= '7';
	$record['transactionNote'] 	= $motive;
	$record['responsibleId'] 		= USER_ID;

	ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $trId]); //records (arr), table (str), where (str)
	//elimino pagos si hay
	ncmExecute("DELETE FROM transaction WHERE transactionParentId = ?", [$trId]);

	//inventario
	$items = ncmExecute("SELECT
                             itemId, itemSoldUnits
                        FROM itemSold
                        WHERE
                          transactionId = ?", [$trId], false, true);

	if ($items) {
		while (!$items->EOF) {
			$fields 		= $items->fields;
			$compound   = getCompoundsArray($fields['itemId']);

			if (validity($compound, 'array')) {
				foreach ($compound as $comr) {
					$itmData = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$comr['compoundId'], COMPANY_ID]);
					manageStock([
						'itemId'    			=> $comr['compoundId'],
						'outletId'  			=> OUTLET_ID,
						'date'          	=> TODAY,
						'count'     			=> abs($comr['toCompoundQty'] * $fields['itemSoldUnits'] ?? 0),
						'source'    			=> 'void',
						'locationId' 			=> $itmData['locationId'],
						'transactionId' 	=> $trId
					]);
				}
			}

			$itmData = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$fields['itemId'], COMPANY_ID]);
			manageStock([
				'itemId'    			=> $fields['itemId'],
				'outletId'  			=> OUTLET_ID,
				'date'          	=> TODAY,
				'locationId' 			=> $itmData['locationId'],
				'count'     			=> abs($fields['itemSoldUnits'] ?? 0),
				'source'    			=> 'void',
				'transactionId' 	=> $trId
			]);

			$items->MoveNext();
		}
		$items->Close();
	}

	//Elimino item solds poruqe voy a usar los que quedan guardados en la transaccion en json
	ncmExecute("DELETE FROM itemSold WHERE transactionId = ?", [$trId]);
	ncmExecute("DELETE FROM giftCardSold WHERE transactionId = ?", [$trId]);
	ncmExecute('DELETE FROM comission WHERE transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);

	$failedTransaction = $db->HasFailedTrans();
	$db->CompleteTrans();

	if ($failedTransaction) {
		jsonDieMsg($db->ErrorMsg());
	} else {
		updateLastTimeEdit($compId, 'item');
		jsonDieMsg('true', 200, 'success');
	}
}

function deleteOutlet($id, $fulldelete = false)
{
	global $db;

	//me aseguro que la sucursal exista y sea de esta empresa
	$outlet = ncmExecute('SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ?', [$id, COMPANY_ID]);

	if ($outlet) {
		//$id = $outlet['outeltId'];

		$db->Execute('DELETE FROM drawer WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM expenses WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM inventory WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM register WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM taxonomy WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM stock WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM stockTrigger WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM satisfaction WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM production WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM notify WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM giftCardSold WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM comission WHERE outletId = ?', [$id]);
		$db->Execute('DELETE FROM toItemLocation WHERE outletId = ?', [$id]);

		if (!$fulldelete) {
			$db->Execute('UPDATE item SET outletId = NULL WHERE outletId = ?', [$id]);
			$db->Execute('UPDATE contact SET outletId = NULL WHERE outletId = ?', [$id]);
		}

		$result = ncmExecute('SELECT STRING_AGG(transactionId::text, \',\') as ids FROM transaction WHERE outletId = ? AND companyId = ?', [$id, COMPANY_ID]);

		if ($result) {
			$db->Execute('DELETE FROM itemSold WHERE transactionId IN(' . $result['ids'] . ')');
			$db->Execute('DELETE FROM transaction WHERE outletId = ?', [$id]);
		}

		$delete = $db->Execute('DELETE FROM outlet WHERE outletId = ? LIMIT 1', [$id]);
		return $delete;
	} else {
		return false;
	}
}

function getAverageCogs($itemId)
{
	global $db;

	$average 	= 0;
	$result 	= ncmExecute("SELECT COUNT(inventoryId) as count, SUM(inventoryCOGS) as price FROM inventory WHERE itemId = ? AND inventoryCount > 0 AND inventoryCOGS > 0 AND companyId = ?", [$itemId, COMPANY_ID]);

	if ($result) {
		$average = divider($result['price'], $result['count']);
	}

	return $average;
}

function getCompoundsArray($itemId, $cache = false)
{
	$result = ncmExecute('SELECT * FROM toCompound WHERE itemId = ? ORDER BY toCompoundOrder LIMIT 1000', [$itemId], $cache, true, true);
	return $result;
}

function getAllCompoundsArray($itemIndex = false)
{

	if (isset($_SESSION['NCM_ALLS']['ALL_COMPOUNDS'])) {
		$result = $_SESSION['NCM_ALLS']['ALL_COMPOUNDS'];
	} else {
		$items 	= ncmExecute('SELECT itemId FROM item WHERE companyId = ? AND itemStatus = 1 LIMIT 100000', [COMPANY_ID], false, true);

		if ($items) {
			$ids = [];
			while (!$items->EOF) {
				$ids[] = $items->fields['itemId'];
				$items->MoveNext();
			}
			$items->Close();

			$idss 	= implode(',', $ids);
			$result = ncmExecute('SELECT * FROM toCompound WHERE itemId IN(' . $idss . ') ORDER BY toCompoundOrder LIMIT 100000', [], false, true, true);

			$_SESSION['NCM_ALLS']['ALL_COMPOUNDS'] = $result;
		}
	}

	if ($itemIndex && $result) {
		$results 	= $result;
		$result 	= [];
		foreach ($results as $value) {
			$result[$value['itemId']][] = [
				'toCompoundId' 		=> $value['toCompoundId'],
				'toCompoundQty' 	=> $value['toCompoundQty'],
				'toCompoundOrder' 	=> $value['toCompoundOrder'],
				'compoundId' 		=> $value['compoundId'],
				'itemId' 			=> $value['itemId']
			];
		}
	}

	return $result;
}

function displayableCompounds($id)
{
	$out 		= [];
	$compounds 	= getCompoundsArray($id);

	if ($compounds) {
		foreach ($compounds as $key => $value) {
			$item 	= getItemName($value['compoundId']);
			$out[] 	= ['id' => enc($value['compoundId']), 'name' => $item, 'units' => $value['toCompoundQty']];
		}
	}

	return $out;
}

function getComboCOGS($parent)
{


	$result 			= getCompoundsArray($parent, true);
	$comboCOGS 		= 0;

	if (validity($result, 'array')) {
		foreach ($result as $resulta) {
			$id 			= $resulta['compoundId'];
			$units 		= number_format($resulta['toCompoundQty'], 2); //dejo en 2 ceros

			$compData = ncmExecute('SELECT itemPrice FROM item WHERE itemId = ? LIMIT 1', [$id]);
			$price 		= $compData['itemPrice'] ?? 0;

			$comboCOGS += $price * $units;
		}
	}

	return $comboCOGS;
}

function getProductionCOGS($itemId, $wasted = true)
{
	$total 		= 0;
	$result 	= getCompoundsArray($itemId);
	if ($result) {
		$waste 	= getAllWasteValue();

		foreach ($result as $key => $value) {
			$id 		= $value['compoundId'];
			$count 	= (float)$value['toCompoundQty'];

			$wasteP = array_key_exists($id, $waste) && $waste[$id];

			if ($wasteP > 0 && $wasted) {
				$count 	= getNeedWithWaste($count, $wasteP);
			}

			$avrg 	= getItemStock($id);
			$avrg 	= $avrg['stockOnHandCOGS'];

			$price 	= ($avrg * $count);
			$total += $price;
		}
	}
	return $total;
}

function getAllWasteValue($id = false, $cache = false)
{
	$andId 		= ' LIMIT 1000';

	if ($id) {
		$andId 	= ' AND itemId = ' . $id . ' LIMIT 1';
	}

	$sql 			= 'SELECT itemWaste, itemId FROM item WHERE itemWaste > 0 AND companyId = ? ' . $andId;
	$result 	= ncmExecute($sql, [COMPANY_ID], $cache, true);
	$out 			= [];

	if ($result) {
		while (!$result->EOF) {
			$fields 								= $result->fields;
			$out[$fields['itemId']] = $fields['itemWaste'];
			$result->MoveNext();
		}

		$result->Close();
	}

	return $out;
}

function getNeedWithWaste($need, $wasteP)
{ //cantidad + waste percent
	$wasteFactor 	= $wasteP / 100;
	$wasteValue 	= $need * $wasteFactor;
	return $need + $wasteValue;
}

//INVENTORY LOGIC END

function getAllItemCategories()
{
	//GET ALL CATEGORIES ARRAY
	$a 		= [];
	$result = ncmExecute("SELECT taxonomyId,taxonomyName, CAST(taxonomyExtra AS INTEGER) as sort FROM taxonomy WHERE taxonomyType = 'category' AND companyId = ? ORDER BY sort ASC LIMIT 2000", [COMPANY_ID], false, true);
	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['taxonomyId']] = [
				"name" => toUTF8($result->fields['taxonomyName']),
				"sort" => (int)$result->fields['taxonomyName']
			];
			$result->MoveNext();
		}
		$result->Close();
	}
	//
	return $a;
}

function getAllItemBrands()
{
	//GET ALL BRANDS ARRAY
	$a 		= [];
	$result = ncmExecute("SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'brand' AND companyId = ? LIMIT 500", [COMPANY_ID], false, true);

	if ($result) {
		while (!$result->EOF) {
			$a[$result->fields['taxonomyId']] = array(
				"name" => toUTF8($result->fields['taxonomyName'])
			);
			$result->MoveNext();
		}
		$result->Close();
	}
	//
	return $a;
}

function getAllItems($parents = false, $cache = false, $in = false, $realKeys = false)
{
	global $db;
	//GET ALL ITEMS ARRAY
	$a 			= [];
	$parent = '';
	$inIDs 	= '';

	if ($parents == 'children') {
		$parent = ' AND itemParentId > 0';
	} else if ($parents != false) {
		$parent = ' AND itemIsParent = 0';
	}

	if ($in) {
		$in 		= db_prepare($in);
		$inIDs 	= ' AND itemId IN(' . $in . ')';
	}

	$sql 	= "SELECT * FROM item WHERE itemId IS NOT NULL " . $parent . " AND companyId = " . COMPANY_ID . $inIDs;

	$result = ncmExecute($sql, [], $cache, true);

	if ($result) {

		while (!$result->EOF) {
			$fields 	= $result->fields;
			$index 		= ($parents == 'children') ? $fields['itemParentId'] : $fields['itemId'];

			if ($realKeys) {
				$a[$index] = 	$fields;
			} else {
				$a[$index] = 	[
					"id"			=> $fields['itemId'],
					"name"		=> toUTF8($fields['itemName']),
					"sku"			=> toUTF8($fields['itemSKU']),
					"price"		=> $fields['itemPrice'],
					"brand"		=> $fields['brandId'],
					"category" => $fields['categoryId'],
					"tax"			=> $fields['taxId'],
					"isparent" => $fields['itemIsParent'],
					"type"		=> $fields['itemType'],
					"commission" => $fields['itemComissionPercent'],
					"comboAddon" => $fields['itemComboAddons']
				];
			}

			$result->MoveNext();
		}
		$result->Close();
	}
	//
	return $a;
}

function getAllCombosCompoundsDiscount($roc, $from, $to)
{

	$sql 	= "SELECT a.itemId, 
					a.itemSoldUnits,
					a.itemSoldTotal, 
					a.itemSoldTax,
					a.itemSoldCOGS,
					a.itemSoldComission,
					a.itemSoldDiscount,
					a.itemSoldParent
				FROM itemSold a, transaction b, item c
				WHERE b.transactionType IN (0,3)
				AND b.transactionDate BETWEEN ? AND ? 
				" . $roc . "
				AND a.transactionId = b.transactionId
				AND (a.itemSoldParent IS NOT NULL AND a.itemSoldParent != 0)
				AND a.itemId = c.itemId
				AND c.itemType NOT IN('precombo', 'combo', 'comboAddon')
				ORDER BY a.itemSoldUnits DESC";

	$result = ncmExecute($sql, [$from, $to], true, true);
	$ids 	= [];

	if ($result) {
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$qty 		= $fields['itemSoldUnits'];
			if (isset($ids[$fields['itemId']]) && $ids[$fields['itemId']]) {
				$ids[$fields['itemId']]['itemSoldUnits'] 		+= $qty;
				$ids[$fields['itemId']]['itemSoldTotal'] 		+= $fields['itemSoldTotal'];
				$ids[$fields['itemId']]['itemSoldTax'] 			+= $fields['itemSoldTax'];
				$ids[$fields['itemId']]['itemSoldCOGS'] 		+= $fields['itemSoldCOGS'] * $qty;
				$ids[$fields['itemId']]['itemSoldComission'] 	+= $fields['itemSoldComission'];
				$ids[$fields['itemId']]['itemSoldDiscount'] 	+= $fields['itemSoldDiscount'] * $qty;
			} else {
				$ids[$fields['itemId']] = 	[
					'itemSoldUnits' 	=> $qty,
					'itemSoldTotal' 	=> $fields['itemSoldTotal'],
					'itemSoldTax' 		=> $fields['itemSoldTax'],
					'itemSoldCOGS' 		=> $fields['itemSoldCOGS'] * $qty,
					'itemSoldComission' => $fields['itemSoldComission'],
					'itemSoldDiscount' 	=> $fields['itemSoldDiscount'] * $qty
				];
			}

			$result->MoveNext();
		}
		$result->Close();
	}
	return $ids;
}

function getAllByIDBuild($res, $field = 'itemId')
{
	$ids 		= [];
	$result = $res; //copy to avoid manipulating original array
	if ($result) {
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$ids[] 		= $fields[$field];

			$result->MoveNext();
		}
		$result->moveFirst();
		//$result->Close();
	}

	return $ids;
}



function getDefaultCustomerAddress($id, $cache = false, $company = false)
{
	$out 		= [];
	$company 	= iftn($company, COMPANY_ID);

	if (!$company) {
		return [];
	}

	$obj 		= ncmExecute("SELECT * FROM customerAddress WHERE customerAddressDefault = 1 AND customerId = ? AND companyId = ? LIMIT 1", [$id, $company], $cache);
	if ($obj) {
		$out = [
			'id' 		=> enc($obj['customerAddressId']),
			'name' 		=> toUTF8($obj['customerAddressName']),
			'address'	=> toUTF8($obj['customerAddressText']),
			'city'		=> toUTF8($obj['customerAddressCity']),
			'location'	=> toUTF8($obj['customerAddressLocation']),
			'lat'		=> $obj['customerAddressLat'],
			'lng'		=> $obj['customerAddressLng']
		];
	}

	return $out;
}



function getCustomerData($id, $type = false, $cache = false)
{
	global $db;

	if (!validity($id)) {
		return [];
	}

	$isCustomer = false;
	$where 			= 'contactId = ' . $db->Prepare($id);

	if ($type == 'uid' || $type == 'contactId') {
		$where 			= 'contactId = ' . $id;
		$isCustomer = true;
	}

	$obj 			= ncmExecute("SELECT * FROM contact WHERE " . $where . " AND companyId = ? LIMIT 1", [COMPANY_ID], $cache);

	$name 		= toUTF8($obj['contactName'] ?? "");
	$sname 		= toUTF8($obj['contactSecondName'] ?? "");
	$note 		= toUTF8($obj['contactNote'] ?? "");
	$address 	= toUTF8($obj['contactAddress'] ?? "");
	$address2 = toUTF8($obj['contactAddress2'] ?? "");
	$location = toUTF8($obj['contactLocation'] ?? "");
	$city 		= toUTF8($obj['contactCity'] ?? "");

	if ($obj) {
		if ($isCustomer) {

			$tAddress 		= getDefaultCustomerAddress($obj['contactId'], true);
			if ($tAddress) {
				$address 		= $tAddress['address'];
				$location 	= $tAddress['location'];
				$city 			= $tAddress['city'];

				$obj['contactAddressName'] 	= $tAddress['name'];
				$obj['contactLatLng'] 			= $tAddress['lat'] . ',' . $tAddress['lng'];
				$obj['contactAddress'] 			= $address;
				$obj['contactLocation'] 		= $location;
				$obj['contactCity'] 				= $city;
			}
		}

		if (validity($obj['contactName']) || validity($obj['contactSecondName'])) {
			return [
				'id'				=> $obj['contactId'],
				'uid'				=> $obj['contactId'],
				'name'			=> $name,
				'secondName' => $sname,
				'ruc'				=> $obj['contactTIN'],
				'ci'				=> $obj['contactCI'],
				'phone'			=> $obj['contactPhone'],
				'phone2'		=> $obj['contactPhone2'],
				'address'		=> $address,
				'address2'	=> $address2,
				'email'			=> $obj['contactEmail'],
				'location'	=> $location,
				'city'			=> $city,
				'bday'			=> $obj['contactBirthDay'],
				'loyalty'		=> $obj['contactLoyaltyAmount'],
				'note'			=> $note,
				'arr' 			=> $obj
			];
		} else {
			return [
				'id'				=> $obj['contactId'],
				'uid'				=> $obj['contactId'],
				'name'			=> 'Sin Nombre',
				'secondName' => '',
				'ruc'				=> '',
				'phone'			=> '',
				'phone2'		=> '',
				'address'		=> '',
				'address2'	=> '',
				'email'			=> '',
				'note'			=> '',
				'arr' 			=> $obj
			];
		}
	} else {
		return [
			'id'				=> $id,
			'uid'				=> $id,
			'name'			=> 'Sin Nombre',
			'secondName' => '',
			'ruc'				=> '',
			'phone'			=> '',
			'phone2'		=> '',
			'address'		=> '',
			'address2'	=> '',
			'email'			=> '',
			'note'			=> '',
			'arr' 			=> $obj
		];
	}
}

function ncmHtmlspecialcharsArr(&$variable)
{
	foreach ($variable as &$value) {
		if (is_array($value)) {
			ncmHtmlspecialcharsArr($value);
		} else if (is_string($value)) {
			$value = htmlspecialchars($value);
		} else {
			return $value;
		}
	}
}

function xssEcho($text)
{ //alias de unXss
	return unXss($text);
}

function unXss($text)
{
	if (is_array($text)) {
		return ncmHtmlspecialcharsArr($text);
	} else if (is_string($text)) {
		return htmlspecialchars($text);
	} else {
		return $text;
	}
}

function toUTF8($text)
{

	if (!validity($text)) {
		return '';
	}

	$return = '-';
	$wrong 	= ['Ã¡', 'Ã©',	'Ã³',	'º',	'Ã±',	'í±',	'Ã']; //la í ('Ã') siempre poner al final
	$right 	= ['á',	'é',	'ó',	'ú',	'ñ',	'ñ',	'í'];

	$text = str_replace($wrong, $right, $text);

	if (validity($text)) {
		$utfd = mb_convert_encoding($text, 'UTF-8');

		if (validity($utfd)) {
			$return = $utfd;
		}
	}

	if (defined('COMPANY_ID') && COMPANY_ID == 10) { // TODO: replace integer 10 with company UUID
		return unXss($return);
	} else {
		return $return;
	}
}

function getContactData($id, $type = false, $cache = false)
{ //ALIAS
	return getCustomerData($id, $type, $cache);
}

function getCustomerName($data, $part = false)
{
	$name = $data['name'] ?? "";

	if (!empty($data['secondName']) && counts($data['secondName']) > 2) {
		$name = $data['secondName'];
	}

	if (!validity($name)) {
		return 'Sin Nombre';
	}

	if ($part) {
		$part 	= ($part == 'first') ? 0 : 1;
		$name 	= explodes(' ', $name, false, $part);
	}

	return $name;
}

function getCustomerIdDecoded($id)
{
	if (!validity($id)) {
		return 0;
	}

	if (validity($id, 'numeric')) {
		if (counts($id) > 10) {
			return $id;
		} else {
			return 0;
		}
	} else {
		return dec($id);
	}
}

function coorsToKms($latitude1, $longitude1, $latitude2, $longitude2, $unit = 'kilometers')
{
	if (!$latitude1 || !$longitude1 || !$latitude2 || !$longitude2) {
		return '0';
	}

	$theta = $longitude1 - $longitude2;
	$distance = (sin(deg2rad($latitude1)) * sin(deg2rad($latitude2))) + (cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * cos(deg2rad($theta)));
	$distance = acos($distance);
	$distance = rad2deg($distance);
	$distance = $distance * 60 * 1.1515;
	switch ($unit) {
		case 'miles':
			break;
		case 'kilometers':
			$distance = $distance * 1.609344;
	}
	return (round($distance, 2));
}

function getShortURL($url)
{
	$creator 	= '/screens/shorturl.php?c=';
	$short 		= file_get_contents($creator . $url); //@file_get_contents($creator . $url);
	if ($short && $short != 'false') {
		return $short;
	} else {
		return '/';
	}
}

function getAllContacts($type = false, $cache = true, $fieldId = 'contactId', $in = false, $realKeys = false)
{
	global $db;
	//GET ALL CUSTOMERS ARRAY
	$a1 		= [];
	$a2 		= [];
	$a3 		= [];
	$typeand 	= '';
	$inIDs 		= '';

	if ($in) {
		$in 		= db_prepare($in);
		$inIDs 	= ' AND ' . $fieldId . ' IN(' . $in . ')';
	}

	if ($type > -1) {
		$typeand = ' AND type = ' . $type;
	}

	$result = ncmExecute("SELECT * FROM contact WHERE companyId = ?" . $typeand . $inIDs, [COMPANY_ID], $cache, true);

	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;

			if ($realKeys) {
				$values = $fields;
			} else {
				$values = [
					"name" 		=> toUTF8($fields['contactName']),
					"sname" 	=> toUTF8($fields['contactSecondName']),
					"address" 	=> toUTF8($fields['contactAddress']),
					"email" 	=> toUTF8($fields['contactEmail']),
					"ruc" 		=> unXss($fields['contactTIN']),
					"id" 		=> $fields['contactId'],
					"rid" 		=> $fields['contactRealId'],
					"phone" 	=> unXss($fields['contactPhone']),
					"note" 		=> toUTF8($fields['contactNote']),
					"city" 		=> toUTF8($fields['contactCity']),
					"date" 		=> $fields['contactDate'],
					"type" 		=> $fields['type'],
					"role" 		=> $fields['role'],
					"main" 		=> $fields['main'],
					"lockpass" 	=> $fields['lockPass'],
					"outlet" 	=> $fields['outletId']
				];
			}

			$a1[$result->fields['contactId']] 		= $values;
			$a2[$result->fields['contactId']] 		= $values;
			$a3[$result->fields['contactRealId']] 	= $values;

			$result->MoveNext();
		}

		$result->Close();
	}
	//

	return [$a1, $a2, $a3];
}

function getAllUsers()
{
	if (isset($_SESSION['NCM_ALLS']['ALL_USERS'])) {
		return $_SESSION['NCM_ALLS']['ALL_USERS'];
	}

	$contact = getAllContacts(0);
	$_SESSION['NCM_ALLS']['ALL_USERS'] = $contact[1];

	return $contact[1];
}

function getAllCustomersAddress($in = false)
{
	$ins 	= 0;
	if ($in) {
		$ins = ' AND customerId IN (' . implodes(',', $in) . ')';
	}

	$custAddresses  = ncmExecute('SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = 1' . $ins, [COMPANY_ID], false, true);
	$allAddress = [];
	if ($custAddresses) {
		while (!$custAddresses->EOF) {
			$cAfields = $custAddresses->fields;
			$latLng   = false;
			if ($cAfields['customerAddressLat'] && $cAfields['customerAddressLng']) {
				$latLng = $cAfields['customerAddressLat'] . ',' . $cAfields['customerAddressLng'];
			}

			$allAddress[$cAfields['customerId']] = [
				'address'   => $cAfields['customerAddressText'],
				'lat'    	=> $cAfields['customerAddressLat'],
				'lng'    	=> $cAfields['customerAddressLng'],
				'latLng'   	=> $latLng,
				'location'  => $cAfields['customerAddressLocation'],
				'city'      => $cAfields['customerAddressCity']
			];
			$custAddresses->MoveNext();
		}
	}

	return $allAddress;
}

function getUserComissions($userId)
{
	global $db, $startDate, $endDate;
	$sql = 'SELECT SUM(itemSoldComission) as comission
			FROM itemSold
			WHERE itemSoldDate
			BETWEEN ?
			AND ? 
			AND userId = ?
			';
	$result = $db->Execute($sql, [$startDate, $endDate, $userId]);
	if (validateResultFromDB($result)) {
		return $result->fields['comission'];
	} else {
		return 0;
	}
}

function getTheContactField($id, $array, $field = 'name')
{
	//Esta función busca hacer un match entre el ID proveido que puede ser un UID, ID nuevo o ID viejo (AKA RealID), con alguno de los 3 posibles IDs en la DB, (ID, UID, RealID), si encuentra un match devuelve el field especificado
	if (validateBool($id, false, false)) {
		$ck = 0;
		$out = '';
		while (1) {
			$out = $array[$ck][$id][$field] ?? false;
			if (validateBool($out, false, false) || $ck == 2) {
				break;
			}
			$ck++;
		}
		return $out;
	} else {
		return '';
	}
}

function colorSelector($options)
{
	$colors = ['e57373', 'F06292', 'BA68C8', '9575CD', '7986CB', '64B5F6', '4FC3F7', '4DD0E1', '4DB6AC', '81C784', 'AED581', 'DCE775', 'FFF176', 'FFD54F', 'FFB74D', 'FF8A65', 'A1887F', 'E0E0E0', '90A4AE', 'ef5350'];

	$selected = str_replace('#', '', $options['selected']);

	if ($options['selected']) {
		if (!in_array($selected, $colors)) {
			array_push($colors, $selected);
		}
	}

	if (array_key_exists('extra', $options) && !empty($options['extra'])) {
		if (!in_array($options['extra'], $colors)) {
			array_push($colors, $options['extra']);
		}
	}

	$out = '';
	foreach ($colors as $color) {
		$select = '';
		if ($color == $selected) {
			$select = 'selected';
		}
		$out .= '<option value="' . $color . '" data-color="#' . $color . '">' . $color . '</option>';
	}

	return '<select id="' . ($options['id'] ?? "") . '" class="' . $options['class'] . '" name="' . ($options['name'] ?? "") . '">' . $out . '</select>';
}

function selectInputGenerator($array, $options = [])
{
	$ops 		= '';
	$multiple 	= '';

	if (array_key_exists("select", $options) && validity($options['select'])) {
		$ops 	= '<option value="">Seleccionar</option>';
	}

	if (array_key_exists('allowNone', $options) && validity($options['allowNone'])) {
		$ops 	= '<option value="">Ninguno</option>';
	}

	if (array_key_exists('multiple', $options) && validity($options['multiple'])) {
		$multiple 	= '[' . $options['multiple'] . ']';
	}

	foreach ($array as $value => $title) {
		$selected = '';

		if (is_array($options) && array_key_exists('match', $options)) {
			if ($value == $options['match']) {
				if (!empty($options['debug'])) {
					echo $value . ' - ';
				}
				$selected = 'selected';
			}
		} else {
			if (array_key_exists('match', $options) && $options['match'] == $value) {
				$selected = 'selected';
			}
		}

		$ops .= '<option value="' . $value . '" ' . $selected . '>' . $title . '</option>';
	}

	return '<select name="' . ($options['name'] ?? "") . $multiple . '" class="form-control ' . $options['class'] . '" ' . ($options['data'] ?? "") . '>' . $ops . '</select>';
}

function selectInputTaxonomy($type, $match = '', $multi = false, $clas = '', $order = 'taxonomyName ASC', $allowNone = false)
{
	global $SQLcompanyId;
	$name = "";
	if (is_array($type)) {
		$data 		= $type;
		$type 		= $data['type'];
		$match 		= $data['match'];
		$multi 		= iftn($data['multi']);
		$clas 		= iftn($data['class'], '');
		$order 		= iftn($data['order'], 'taxonomyName ASC');
		$allowNone	= $data['allowNone'] ? true : false;
		$name		= iftn($data['name'], '');
	}

	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND " . $SQLcompanyId . " ORDER BY " . $order, [$type], false, true);
	if ($multi) {
		$type = $type . '[]';
	}

	if ($name) {
		$type = $name;
	}
	?>
	<select name="<?= $type ?>" class="form-control <?= $clas ?>">
		<?php
		if ($allowNone) {
		?>
			<option value="0" selected>Ninguno</option>
			<?php
		}

		if ($result) {
			while (!$result->EOF) {
				$fields 		= $result->fields;
				$selected 		= '';
				$name 			= unXss($fields['taxonomyName']);

				if ($fields['taxonomyId'] == $match) {
					$selected 	= 'selected';
				}
			?>
				<option value="<?= enc($fields['taxonomyId']); ?>" <?= $selected ?>><?= $name; ?></option>
		<?php
				$result->MoveNext();
			}
			$result->Close();
		}
		?>
	</select>
	<?php
}

function selectInputPaymentMethods($options = [])
{
	$pM 		= ncmExecute("SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'paymentMethod' AND companyId = ? ORDER BY taxonomyName ASC", [COMPANY_ID], false, true);
	$fixed 	= ['cash' => 'Efectivo', 'creditcard' => 'T. Crédito', 'debitcard' => 'T. Débito', 'check' => 'Cheque'];
	$custom = [];

	if ($pM) {

		while (!$pM->EOF) {
			$id 					= enc($pM->fields['taxonomyId']);
			$name 				= toUTF8($pM->fields['taxonomyName']);
			$custom[$id] 	= $name;

			$pM->MoveNext();
		}

		$pM->Close();
	}

	//Array merge elimina el id codificado cuando este es numérico y lo remplaza por cero, por lo que insertamos los indices de forma manual
	//$array = array_merge($fixed,$custom);

	// Combinar arrays manualmente para evitar sobrescrituras
	foreach ($custom as $key => $value) {
		$fixed[$key] = $value;
	}

	$array = $fixed;

	return selectInputGenerator($array, $options);
}

function selectInputTax($match, $multi = false, $clas = '', $allowNone = false, $internal = false)
{
	global $db, $SQLcompanyId;
	$type 		= 'tax';
	$options 	= '';
	$result 	= $db->Execute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND " . $SQLcompanyId . " ORDER BY taxonomyName::INTEGER DESC", array($type));
	if ($multi) {
		$type = $type . '[]';
	}

	if ($allowNone) {
		$options .= '<option value="" selected>Seleccionar</option>';
	}

	while ($result && !$result->EOF) {
		$selected 		= '';
		$fields 		= $result->fields;
		$taxName 		= (!$fields['taxonomyName']) ? '' : unXss($fields['taxonomyName']);

		if ($fields['taxonomyId'] == $match) {
			$selected 	= 'selected';
		} else if ((is_null($match) || empty($match)) && ($taxName == '10') && !$allowNone) {
			$selected 	= 'selected';
		}

		$options .= '<option value="' . enc($fields['taxonomyId']) . '" ' . $selected . '>' . $taxName . '%</option>';

		$result->MoveNext();
	}
	$result->Close();

	$select = '</select>';

	if ($internal) {
		return $options;
	} else {
		return '<select name="' . $type . '" class="form-control ' . $clas . '">' . $options . '</select>';
	}
}

function selectItems($resultset)
{
	$compSelect = '<select name="itemid[]" tabindex="1" class="form-control" autocomplete="off">';
	$compSelect .= '<option value="">Seleccione un Artículo</option>';
	$match 		= dec($match);

	while (!$resultset->EOF) {
		if ($match && $match == $resultset->fields['itemId']) {
			$selected = 'selected';
		} else {
			$selected = '';
		}

		$compSelect .= '<option value="' . enc($resultset->fields['itemId']) . '" ' . $selected . '>';
		$compSelect .= toUTF8($resultset->fields['itemName']);
		$compSelect .= '</option>';

		$resultset->MoveNext();
	}
	$resultset->MoveFirst();

	$compSelect .= '</select>';

	return $compSelect;
}

function round_precision($number, $precision = 2, $separator = '.')
{
	//return ceil($number * pow(10, $precision)) / pow(10, $precision);
	//return round($number,$precision);

	$numberParts = explode($separator, $number);
	$response = $numberParts[0];
	if (counts($numberParts) > 1) {
		$response .= $separator;
		$response .= substr($numberParts[1], 0, $precision);
	}
	return $response;
}

function selectInputCompound($resultset, $match = '', $inventory = array(), $clas = '')
{
	$compSelect = '';
	$match 		= $match;
	$price 		= '';
	$tPrice 	= 0;

	if ($resultset) {
		while (!$resultset->EOF) {
			if ($match && $match == $resultset->fields['id']) {
				$selected = 'selected';
				$tPrice = $inventory[$resultset->fields['id']][enc(OUTLET_ID)]['cogs'] ?? 0;
			} else {
				$selected = '';
			}

			if ($tPrice) {
				$price = ' (' . formatCurrentNumber($tPrice) . ')';
			}

			$compSelect .= '<option value="' . enc($resultset->fields['id']) . '" data-uom="' . toUTF8($resultset->fields['uom'] ?? "") . '" ' . $selected . '>';
			$compSelect .= toUTF8($resultset->fields['name']) . $price;
			$compSelect .= '</option>';

			$resultset->MoveNext();
		}

		$resultset->MoveFirst();
	}

	$compSelect = '<select name="compid[]" tabindex="1" class="form-control compoundSelect ' . $clas . '" autocomplete="off" data-price="' . $tPrice . '"><option value="">Seleccionar</option>' . $compSelect . '</select>';

	return json_encode($compSelect);
}

function selectInputOutlet($match = '', $multi = false, $class = '', $name = 'outlet', $allowAll = false, $allowNone = false, $locations = false, $registers = false, $registerSelected = false)
{
	global $db, $SQLcompanyId;
	$result = ncmExecute("SELECT outletName,outletId FROM outlet WHERE " . $SQLcompanyId . " AND outletStatus = 1 ORDER BY outletName ASC", [], false, true);

	if ($multi) {
		$name = $name . '[]';
	}
	$out 	= '';
	$out .= '<select name="' . $name . '" class="form-control ' . $class . '" id="' . $name . 'Select">';

	if ($allowAll) {
		$out .= '<option value="all" ' . iftn($match, 'selected', '') . '>Todas</option>';
	}
	if ($allowNone) {
		$out .= '<option value="" selected>Seleccionar</option>';
	}

	if ($result) {
		$i = 0;
		while (!$result->EOF) {
			$selected 	= '';
			$fields 	= $result->fields;
			if ($fields['outletId'] == $match) {
				$selected = 'selected';
			}

			if (!$locations && !$registers) {
				$out .= '<option value="' . enc($fields['outletId']) . '" ' . $selected . '>' . toUTF8($fields['outletName']) . '</option>';
			}

			if ($registers) {
				$register = ncmExecute('SELECT * FROM register WHERE outletId = ?', [$fields['outletId']], false, true);
				$out .= '<optgroup label="' . toUTF8($fields['outletName']) . '">';
				if ($register) {
					while (!$register->EOF) {
						$reg = $register->fields;
						if ($registerSelected) {
							$selected 	= '';
							if ($registerSelected == $reg['registerId']) {
								$selected = 'selected';
							}
						}
						$out .= '	<option value="' . enc($fields['outletId']) . '" ' . $selected . ' data-register="' . enc($reg['registerId']) . '">&nbsp;' . $reg['registerName'] . '</option>';

						$register->MoveNext();
					}
				}
			}

			if ($locations) {
				$location = ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = \'location\' AND outletId = ?', [$fields['outletId']], false, true);
				$out .= '<optgroup label="' . toUTF8($fields['outletName']) . '">' .
					'	<option value="' . enc($fields['outletId']) . '" ' . $selected . '>&nbsp;Principal (' . toUTF8($fields['outletName']) . ')</option>';

				if ($location) {
					while (!$location->EOF) {
						$locations = $location->fields;

						$out .= '<option value="' . enc($locations['taxonomyId']) . '">&nbsp;' . toUTF8($locations['taxonomyName']) . '</option>';
						$location->MoveNext();
					}
				}
			}
			$i++;
			$result->MoveNext();
		}
		$result->Close();
	}

	$out .= '</select>';

	return $out;
}

function selectInputRegister($match = '', $multi = false, $class = '', $name = 'register', $allowNone = false, $outlet = 0)
{
	global $db, $SQLcompanyId;
	$result = $db->Execute("SELECT registerName,registerId,registerInvoiceNumber FROM register WHERE " . $SQLcompanyId . " AND registerStatus = 1 AND outletId = " . $outlet . " ORDER BY registerName ASC");
	if ($multi) {
		$name = $name . '[]';
	}
	$out = '';
	$out .= '<select name="' . $name . '" class="form-control ' . $class . '">';
	if ($allowNone) {
		$out .= '<option value="all" ' . iftn($match, 'selected', '') . '>Todas</option>';
	}
	while (!$result->EOF) {
		$selected = '';
		if ($result->fields['registerId'] == $match) {
			$selected = 'selected';
		}

		$out .= '<option value="' . enc($result->fields['registerId']) . '" data-invoice="' . $result->fields['registerInvoiceNumber'] . '" ' . $selected . '>' . toUTF8($result->fields['registerName']) . '</option>';

		$result->MoveNext();
	}
	$result->Close();

	$out .= '</select>';

	return $out;
}

function selectInputUser($match = '', $multi = false, $class = '', $name = 'user', $cache = false, $data = '')
{
	global $db, $ADODB_CACHE_DIR;

	$sql = 'SELECT contactId, contactRealId, contactName FROM contact WHERE type = 0 AND companyId = ' . COMPANY_ID;

	$result = ncmExecute($sql, [], $cache, true);

	if ($multi) {
		$name = $name . '[]';
	}

	$out = 	'<select name="' . $name . '" class="form-control ' . $class . '" ' . $data . '>' .
		'	<option>Seleccionar</option>';
	if ($result) {
		while (!$result->EOF) {
			$selected = '';

			if ($multi && is_array($match)) {
				if (in_array($result->fields['contactId'], $match)) {
					$selected = 'selected';
				}
			} else {
				if ($result->fields['contactId'] == $match || $result->fields['contactRealId'] == $match) {
					$selected = 'selected';
				}
			}

			$out .= '<option value="' . enc($result->fields['contactId']) . '" ' . $selected . '>' . toUTF8($result->fields['contactName']) . '</option>';

			$result->MoveNext();
		}
		$result->Close();
	}
	$out .= '</select>';

	return $out;
}

function selectInputCustomer($match = '', $multi = false, $class = '', $name = 'customer', $cache = false)
{
	global $db, $SQLcompanyId, $ADODB_CACHE_DIR, $plansValues;

	if (COMPANY_ID == INCOME_COMPANY_ID) {
		$sql = "SELECT companyId as contactId, companyId as contactId, config->>'settingName' as contactName, config->>'settingEmail' as contactEmail FROM company";
	} else {
		$sql = 'SELECT contactId, contactName, contactEmail FROM contact WHERE type = 1 AND ' . $SQLcompanyId . ' LIMIT ' . $plansValues[PLAN]['max_customers'];
	}

	$result = ncmExecute($sql, [], $cache, true);

	if ($multi) {
		$name = $name . '[]';
	}

	$out = 	'<select name="' . $name . '" class="form-control ' . $class . '">' .
		'	<option>Seleccionar</option>';

	if ($result) {
		while (!$result->EOF) {
			$selected = '';
			if ($result->fields['contactId'] == $match) {
				$selected = 'selected';
			}

			$out .= '<option value="' . enc($result->fields['contactId']) . '" data-email="' . $result->fields['contactEmail'] . '" ' . $selected . '>' .
				toUTF8($result->fields['contactName']) .
				'</option>';

			$result->MoveNext();
		}
		$result->Close();
	}

	$out .= '</select>';

	return $out;
}

function selectInputSupplier($match = '', $multi = false, $class = '', $name = 'supplier', $allowNone = false)
{
	global $db, $SQLcompanyId;
	$result = ncmExecute('SELECT contactId, contactRealId, contactName FROM contact WHERE type = 2 AND ' . $SQLcompanyId, [], false, true);
	if ($multi) {
		$name = $name . '[]';
	}
	$out = '';
	$out .= '<select name="' . $name . '" class="form-control ' . $class . '">';

	if ($allowNone) {
		$out .= '<option value="0">Ninguno</option>';
	}

	if ($result) {
		while (!$result->EOF) {
			$selected = '';
			if ($result->fields['contactId'] == $match || $result->fields['contactRealId'] == $match) {
				$selected = 'selected';
			}

			$out .= '<option value="' . enc($result->fields['contactId']) . '" ' . $selected . '>' . toUTF8($result->fields['contactName']) . '</option>';

			$result->MoveNext();
		}
		$result->Close();
	}

	$out .= '</select>';

	return $out;
}

function selectInputCategory($match = '', $multi = false, $class = '', $name = 'category', $allowNone = false, $data = '')
{
	global $db, $SQLcompanyId, $plansValues;

	$result = ncmExecute("SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra AS INTEGER) as sort FROM taxonomy WHERE taxonomyType = 'category' AND " . $SQLcompanyId . " ORDER BY sort ASC LIMIT 500", [], false, true);

	if ($multi) {
		$name = $name . '[]';
	}

	$out = '';
	$out .= '<select name="' . $name . '" id="' . $name . '" class="form-control ' . $class . '" ' . $data . '>';

	if ($allowNone) {
		$out .= '<option value="" ' . iftn($match, 'selected', '') . '>Seleccionar</option>';
	}

	if ($result) {
		$i = 0;
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= enc($fields['taxonomyId']);
			$selected 	= '';

			if (is_array($match)) {
				if (in_array($id, $match)) {
					$selected = 'selected';
				}
			} else {
				if ($id == $match) {
					$selected = 'selected';
				}
			}

			$out .= '<option value="' . $id . '" ' . $selected . '>' . toUTF8($fields['taxonomyName']) . '</option>';

			$i++;
			$result->MoveNext();
		}
	}

	$out .= '</select>';

	return $out;
}

function formatCurrentNumber($number, $de = '', $ts = false, $extDec = 2)
{
	if (!is_numeric($number)) {
		$number = 0;
	}

	$decimal 	= iftn($de, DECIMAL);
	$thouS 		= iftn($ts, THOUSAND_SEPARATOR);
	$extDec 	= (int) $extDec;

	if ($decimal == 'no') {
		//$explode 	= explode('.',$number); //esto es para eliminar los decimales
		//$number 	= $explode[0];
		$number 	= round($number);
		if ($thouS == 'comma') {
			$num = number_format($number, 0, '.', ',');
		} else {
			$num = number_format($number, 0, ',', '.');
		}
		return $num;
	} else {
		if ($thouS == 'comma') {
			$num = number_format($number, $extDec, '.', ',');
		} else {
			$num = number_format($number, $extDec, ',', '.');
		}
		return $num;
	}
}

function formatQty($val, $extDec = 2)
{
	if (strpos($val . '', '.') === false) { //es entero
		return formatCurrentNumber($val, 'no', false);
	} else { //si tiene decimales
		$getDec = explode('.', $val);
		if ($getDec[1] > 0) { //si los decimales no son 0 fuerzo a enviar decimales
			return formatCurrentNumber($val, 'yes', false, $extDec);
		} else { //de lo contrario envio enteros
			return formatCurrentNumber($val, 'no', false);
		}
	}
}

function is_decimal($val)
{
	return is_numeric($val) && floor($val) != $val;
}

function like_match($pattern, $subject)
{
	$pattern = str_replace('%', '.*', preg_quote($pattern, '/'));
	return (bool) preg_match("/^{$pattern}$/i", $subject);
}

function formatNumberToInsertDB($number, $forceDecimals = false, $decimalsCount = 2)
{
	//if(!is_numeric($number)){$number = 0;}

	if (DECIMAL == 'no' && !$forceDecimals) {
		if (THOUSAND_SEPARATOR == 'dot') {
			$explode 	= explode(',', $number); //esto es para eliminar los decimales
			$number 	= $explode[0];
			$number 	= str_replace('.', '', $number);
		} else {
			$explode 	= explode('.', $number); //esto es para eliminar los decimales
			$number 	= $explode[0];
			$number 	= str_replace(',', '', $number);
		}

		return preg_replace("/[^0-9,.-]/", "", $number);
	} else {
		if (THOUSAND_SEPARATOR == 'dot') {
			$number = str_replace('.', '', $number); //1.000,00 => 1000,00
			$number = str_replace(',', '.', $number); //1000,00 => 1000.00
		} else {
			$number = str_replace(',', '', $number); //1,000.00 => 1000.00
		}

		$number = preg_replace("/[^0-9,.-]/", "", $number);

		$number = forceExtraDecimalsNumber($number, $decimalsCount);

		return (float) $number;
	}
}

function forceExtraDecimalsNumber($num, $max = 3)
{
	$num = (float) $num;
	return number_format($num, $max, '.', '');
}

function array_flatten(array $array)
{
	$return = array();
	array_walk_recursive($array, function ($a) use (&$return) {
		$return[] = $a;
	});
	return $return;
}

function getTotalCountOfQuery($sql, $array = [])
{
	global $db;
	//get total recs
	$countSQL 	= explodes('LIMIT', $sql);
	$result 	= $db->Execute($countSQL[0], $array);
	$recs 		= validateResultFromDB($result, true);

	return $recs;
	//
}

function paginator($current, $total, $limit, $search = false, $url = '', $container = '')
{
	$search 	= ($search) ? '&sea=' . $search : '';
	$totalNums 	= divider($total, $limit, true);
	$container 	= 'data-container="' . $container . '"';
	$start 		= '<div class="pagination btn-group">';
	$first 		= '<a href="#" data-url="' . $url . '&cur=1' . $search . '" ' . $container . ' class="btn btn-default servPagination ' . (($current < 2) ? 'disabled' : '') . '">Primero</a>';
	$prev 		= '<a href="#" data-url="' . $url . '&cur=' . (($current > 1) ? ($current - 1) . $search : '0' . $search) . '" ' . $container . ' class="btn btn-default servPagination ' . (($current > 1) ? '' : 'disabled') . '">Anterior</a>';
	$btn 		= '';

	for ($x = 1; $x < $totalNums; $x++) {
		if (($x < $current + 5 && $x > $current - 5)) {
			$active = ($x == $current) ? 'btn-info current disabled' : 'btn-default';
			$btn 	.= '<a href="#" data-url="' . $url . '&cur=' . $x . $search . '" ' . $container . ' class="btn servPagination hidden-sm hidden-xs ' . $active . '">' . $x . '</a>';
		}
	}
	$next 		= '<a href="#" data-url="' . $url . '&cur=' . ($current + 1) . $search . '" ' . $container . ' class="servPagination btn btn-default ' . (($total <= $limit) ? 'disabled' : '') . '">Siguiente</a>';
	$last 		= '<a href="#" data-url="' . $url . '&cur=' . ceil($totalNums) . $search . '" ' . $container . ' class="servPagination btn btn-default ' . (($total <= $limit) ? 'disabled' : '') . ' ' . (($current == ceil($totalNums)) ? 'disabled' : '') . '">Último</a>';
	$end 		= '</div>';

	return $start . $first . $prev . $btn . $next . $last . $end;
}

function validateResultFromDB($result, $num = false)
{
	if ($result) {
		if ($result->RecordCount() > 0) {
			return ($num) ? $result->RecordCount() : true;
		}
	}

	return ($num) ? 0 : false;
}

function getPreviousPeriod($start, $end)
{
	$startF     = strtotime($start);
	$endF       = strtotime($end);
	$diference  = ($endF - $startF) + 1;
	$startDate  = date('Y-m-d H:i:00', ($startF - $diference));
	$endDate    = date('Y-m-d H:i:00', ($endF - $diference));
	return [$startDate, $endDate];
}

function getDateTimeByPieces($src)
{
	if (!validity($src)) {
		return false;
	}

	$dates 		= strtotime($src);
	$h			= date('H', $dates);
	$mi			= date('i', $dates);
	$s			= date('s', $dates);
	$d			= date('d', $dates);
	$m			= date('m', $dates);
	$y			= date('y', $dates);
	$w			= date('N', $dates);
	$date		= date('Y-m-d', $dates);

	return [
		'y' 	=> $y, //ano
		'm' 	=> $m, //mes
		'd' 	=> $d, //dia
		'h' 	=> $h, //hora
		'mi' 	=> $mi, //minuto
		's' 	=> $s, //segundo
		'w' 	=> $w, //semana
		'date' 	=> $date //fecha
	];
}

function comparePeriodsArrowsPercent($now, $past, $pastF, $inverted = false, $icon = false)
{
	$now 		= abs($now ?? 0);
	$past 		= abs($past ?? 0);
	$total 		= $now + $past;
	$arrowUp 	= $icon ? '<i class="material-icons">trending_up</i>' : '▲';
	$arrowDown 	= $icon ? '<i class="material-icons">trending_down</i>' : '▼';
	$equal 		= $icon ? '<i class="material-icons">trending_flat</i>' : '=';
	$good 		= 'text-success';
	$bad 		= 'text-danger';
	$neutral	= 'text-muted';
	if ($now > $past) { //good sign
		$sign 		= $arrowUp;
		$color 		= ($inverted) ? $bad : $good;
		$difference	= (($now - $past) * 100 / $now);
	} else if ($now < $past) { //bad sign
		$sign 		= $arrowDown;
		$color 		= ($inverted) ? $good : $bad;
		$difference	= (($past - $now) * 100 / $past);
	} else { //equal
		$sign 		= $equal;
		$color 		= $neutral;
		$difference	= 0;
	}

	$difference = round($difference);

	return '<span class="' . $color . ' pointer" data-toggle="tooltip" title="Periodo anterior: ' . $pastF . '">' . $sign . ' ' . $difference . '%</span>';
}

function generateUuidV7(): string
{
    $timestamp = (int)(microtime(true) * 1000);
    $timeHex   = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);

    $rand = random_bytes(10);
    $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70); // version 7
    $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80); // variant

    $randHex = bin2hex($rand);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($timeHex, 0, 8),
        substr($timeHex, 8, 4),
        substr($randHex, 0, 4),
        substr($randHex, 4, 4),
        substr($randHex, 8, 12)
    );
}

/**
 * Registro de esquemas de tablas con columnas JSONB.
 * Devuelve un array indexado por nombre de tabla con:
 *   'jsonbCol' => nombre de la columna JSONB de la tabla
 *   'columns'  => array de columnas reales (no JSONB)
 *
 * Uso: cualquier campo en $record que NO aparezca en 'columns'
 * será redirigido automáticamente al 'jsonbCol' por _routeToJsonb().
 */
function _getTableSchema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    // 'pk'      => columna PK de la tabla (para auto-generar UUID en ncmInsert)
    // 'jsonbCol'=> columna JSONB donde van los campos no reconocidos
    // 'columns' => todas las columnas reales de la tabla (incluyendo pk y jsonbCol)
    $schema = [
        'company' => [
            'pk'       => 'companyId',
            'jsonbCol' => 'config',
            'columns'  => ['companyId', 'status', 'plan', 'balance', 'slug', 'blocked',
                           'planExpired', 'isTrial', 'smsCredit', 'parentId', 'isParent',
                           'createdAt', 'updatedAt', 'expiresAt', 'config'],
        ],
        'item' => [
            'pk'       => 'itemId',
            'jsonbCol' => 'data',
            'columns'  => ['itemId', 'itemName', 'itemDate', 'itemSKU', 'itemCost', 'itemPrice',
                           'itemIsParent', 'itemParentId', 'itemType', 'itemImage', 'itemStatus',
                           'itemTrackInventory', 'itemCanSale', 'itemTaxExcluded', 'itemDiscount',
                           'itemUOM', 'itemSort', 'itemProduction', 'taxId', 'brandId',
                           'categoryId', 'supplierId', 'locationId', 'outletId', 'companyId',
                           'updated_at', 'data'],
        ],
        'contact' => [
            'pk'       => 'contactId',
            'jsonbCol' => 'data',
            'columns'  => ['contactId', 'contactName', 'contactSecondName', 'contactEmail',
                           'contactAddress', 'contactAddress2', 'contactPhone', 'contactPhone2',
                           'contactNote', 'contactCity', 'contactLocation', 'contactCountry',
                           'contactTIN', 'contactCI', 'contactDate', 'contactBirthDay',
                           'contactPassword', 'contactLoyalty', 'contactLoyaltyAmount',
                           'contactStoreCredit', 'contactCreditable', 'contactCreditLine',
                           'contactStatus', 'contactLastNotificationSeen', 'debtLastNotify',
                           'type', 'main', 'role', 'lockPass', 'salt', 'parentId', 'categoryId',
                           'userId', 'outletId', 'companyId', 'updated_at', 'data'],
        ],
        'transaction' => [
            'pk'       => 'transactionId',
            'jsonbCol' => 'meta',
            'columns'  => ['transactionId', 'transactionDate', 'transactionDiscount',
                           'transactionTax', 'transactionTotal', 'transactionUnitsSold',
                           'transactionPaymentType', 'transactionType', 'transactionName',
                           'transactionNote', 'transactionParentId', 'transactionComplete',
                           'transactionDueDate', 'transactionStatus', 'transactionUID',
                           'transactionCurrency', 'fromDate', 'toDate', 'invoiceNo',
                           'invoicePrefix', 'tableno', 'timestamp', 'packageId',
                           'categoryTransId', 'customerId', 'registerId', 'userId',
                           'responsibleId', 'supplierId', 'outletId', 'companyId',
                           'updated_at', 'meta'],
        ],
        'itemSold' => [
            'pk'       => 'itemSoldId',
            'jsonbCol' => 'meta',
            'columns'  => ['itemSoldId', 'itemSoldTotal', 'itemSoldTax', 'itemSoldDate',
                           'itemSoldUnits', 'itemSoldDiscount', 'itemSoldCOGS',
                           'itemSoldComission', 'itemSoldDescription', 'itemSoldParent',
                           'itemSoldCategory', 'itemId', 'userId', 'transactionId', 'meta'],
        ],
        'outlet' => [
            'pk'       => 'outletId',
            'jsonbCol' => 'data',
            'columns'  => ['outletId', 'outletName', 'outletStatus', 'outletAddress',
                           'outletPhone', 'outletWhatsApp', 'outletEmail', 'outletBillingName',
                           'outletRUC', 'outletLatLng', 'outletDescription', 'outletCreationDate',
                           'outletNextExpirationDate', 'outletPurchaseOrderNo',
                           'outletOrderTransferNo', 'taxId', 'companyId', 'data'],
        ],
        'register' => [
            'pk'       => 'registerId',
            'jsonbCol' => 'data',
            'columns'  => ['registerId', 'registerName', 'registerStatus', 'registerCreationDate',
                           'registerInvoiceAuth', 'registerInvoiceAuthExpiration',
                           'registerInvoicePrefix', 'registerInvoiceSufix', 'registerInvoiceNumber',
                           'registerRemitoNumber', 'registerQuoteNumber', 'registerReturnNumber',
                           'registerTicketNumber', 'registerOrderNumber', 'registerPedidoNumber',
                           'registerBoletaNumber', 'registerScheduleNumber',
                           'registerDocsLeadingZeros', 'lastupdated', 'sessionId',
                           'outletId', 'companyId', 'data'],
        ],
        'plans' => [
            'pk'       => 'id',
            'jsonbCol' => 'features',
            'columns'  => ['id', 'name', 'type', 'price', 'duration_days', 'max_items',
                           'max_users', 'max_customers', 'max_outlets', 'max_registers',
                           'max_suppliers', 'max_categories', 'max_brands', 'features'],
        ],
        'recurring' => [
            'pk'       => 'recurringId',
            'jsonbCol' => 'data',
            'columns'  => ['recurringId', 'recurringNextDate', 'recurringEndDate',
                           'recurringFrecuency', 'recurringStatus', 'recurringTransactionData',
                           'companyId', 'data'],
        ],
        'tasks' => [
            'pk'       => 'ID',
            'jsonbCol' => 'data',
            'columns'  => ['ID', 'date', 'dueDate', 'type', 'sourceId', 'status',
                           'outletId', 'companyId', 'data'],
        ],
        'customerRecord' => [
            'pk'       => 'customerRecordId',
            'jsonbCol' => 'data',
            'columns'  => ['customerRecordId', 'customerRecordSort', 'customerRecordName',
                           'companyId', 'data'],
        ],
        'inventoryCount' => [
            'pk'       => 'inventoryCountId',
            'jsonbCol' => 'data',
            'columns'  => ['inventoryCountId', 'inventoryCountDate', 'inventoryCountUpdated',
                           'inventoryCountName', 'inventoryCountStatus', 'inventoryCountCounted',
                           'inventoryCountNote', 'inventoryCountBlind',
                           'userId', 'outletId', 'companyId', 'data'],
        ],
        'priceList' => [
            'pk'       => 'ID',
            'jsonbCol' => 'data',
            'columns'  => ['ID', 'data', 'companyId'],
        ],
        'vPayments' => [
            'pk'       => 'ID',
            'jsonbCol' => 'data',
            'columns'  => ['ID', 'date', 'payoutDate', 'depositedDate', 'amount', 'payoutAmount',
                           'comission', 'tax', 'deposited', 'orderNo', 'authCode', 'operationNo',
                           'inBank', 'status', 'UID', 'source', 'transactionId', 'customerId',
                           'userId', 'outletId', 'companyId', 'updated_at', 'data'],
        ],
    ];

    return $schema;
}

/**
 * Separa $record en columnas reales vs campos que van al JSONB.
 *
 * Para tablas registradas en _getTableSchema():
 *   - Campos que coinciden con columnas reales → se quedan en $record
 *   - Campos desconocidos → se fusionan en la columna JSONB de la tabla
 *
 * Para tablas no registradas: devuelve $record sin cambios.
 *
 * @param  string $table  Nombre de la tabla
 * @param  array  $record Array campo → valor a insertar/actualizar
 * @return array  [$cleanRecord, $jsonbExtra, $jsonbCol]
 *                $cleanRecord  : campos para columnas reales (incluye jsonbCol si tiene valor)
 *                $jsonbExtra   : solo los campos que van al JSONB (vacío si ninguno)
 *                $jsonbCol     : nombre de la columna JSONB ('data', 'meta', 'config', …)
 */
function _routeToJsonb(string $table, array $record): array
{
    $schema = _getTableSchema();

    if (!isset($schema[$table])) {
        return [$record, [], ''];
    }

    $jsonbCol   = $schema[$table]['jsonbCol'];
    $knownCols  = array_flip($schema[$table]['columns']);

    $cleanRecord = [];
    $jsonbExtra  = [];

    foreach ($record as $key => $value) {
        if (isset($knownCols[$key])) {
            $cleanRecord[$key] = $value;
        } else {
            $jsonbExtra[$key] = $value;
        }
    }

    return [$cleanRecord, $jsonbExtra, $jsonbCol];
}

/**
 * Aplana columnas JSONB al root del array de resultado.
 * Las columnas regulares tienen prioridad sobre claves del JSONB.
 * La columna JSONB en sí se elimina del resultado final.
 *
 * Acepta tanto arrays PHP como CaseInsensitiveArray (PDO wrapper).
 * Siempre devuelve un CaseInsensitiveArray para mantener acceso case-insensitive.
 */
function _flattenJsonb($row): CaseInsensitiveArray
{
    // Normalizar a array plano para poder usar array_merge
    $arr = ($row instanceof CaseInsensitiveArray) ? $row->toArray() : (array) $row;

    static $jsonbCols = ['data', 'meta', 'config'];
    foreach ($jsonbCols as $col) {
        $val = $arr[$col] ?? $arr[strtolower($col)] ?? null;
        if (isset($val) && is_string($val) && $val !== '') {
            $decoded = json_decode($val, true);
            // Solo aplanar JSON objects (arrays asociativos), no JSON arrays ([...])
            // Los JSON arrays se dejan intactos para que el código que los usa pueda leerlos directamente
            if (is_array($decoded) && !array_is_list($decoded)) {
                $arr = array_merge($decoded, $arr); // columna gana sobre JSONB
                unset($arr[$col]);
            }
        }
    }

    return new CaseInsensitiveArray($arr);
}

function ncmExecute($sql, $array = false, $cache = false, $forceObj = false, $getAssoc = false)
{
	global $db, $ADODB_CACHE_DIR;
	//No se necesita cerrar la conexión ej: $result->Close(), la conexion se cierra sola al terminar el script

	$go 	= false;
	$sql 	= $db->Prepare($sql);

	if ($forceObj || $getAssoc) {
		$ADODB_FETCH_MODE 	= ADODB_FETCH_BOTH;
	}

	if (!$cache) {
		if ($getAssoc) {
			$result = $db->GetAssoc($sql, $array);
		} else {
			$result = $db->Execute($sql, $array);
		}

		$db->cacheFlush($sql, $array);
	} else {
		$cachTime = 3600; //en segundos
		if (is_numeric($cache)) {
			$cachTime = $cache;
		}

		if ($getAssoc) {
			$result = $db->CacheGetAssoc($cachTime, $sql, $array);
		} else {
			$result = $db->cacheExecute($cachTime, $sql, $array);
		}
	}

	if ($getAssoc) {
		$count = counts($result);
	} else {
		$count = validateResultFromDB($result, true);
	}

	if ($getAssoc) {
		if (validity($result, 'array')) {
			$go = true;
		}
	} else {
		if (validateResultFromDB($result)) {
			$go = true;
		}
	}

	if ($go) {
		if ($getAssoc) {
			// Aplanar JSONB en cada fila del array asociativo
			$returns = array_map('_flattenJsonb', $result);
		} else {
			if ($count > 1 || $forceObj) {
				$returns = $result; // objeto ADOdb — se aplana en ncmWhile
			} else if ($count > 0) {
				$returns = _flattenJsonb($result->fields);
			} else {
				$returns = 0;
			}
		}
	} else {
		$returns = false;
	}

	if (strtoupper(explode(" ", trim($sql))[0]) == 'INSERT' && $db->ErrorNo() == 0) {
		$returns = true;
	}
	// if($result->Affected_Rows() > 0){
	// 	$returns = true;
	// }
	//No puedo usar $result->Close() porque se usa el $result en los loop while

	return $returns;
}

function ncmInsert($options)
{ //records, table
	global $db;

	if (!validity($options, 'array') || !validity($options['records'], 'array') || !validity($options['table'])) {
		return false;
	}

	$table  = $options['table'];
	$record = $options['records'];

	// Determinar la columna PK de esta tabla (fallback 'id' para tablas no registradas)
	$tableSchema = _getTableSchema();
	$pkCol       = isset($tableSchema[$table]['pk']) ? $tableSchema[$table]['pk'] : 'id';

	// Generar UUID v7 si el registro no trae el PK
	if (empty($record[$pkCol])) {
		$record[$pkCol] = generateUuidV7();
	}

	// Enrutar campos desconocidos al JSONB de la tabla
	[$record, $jsonbExtra, $jsonbCol] = _routeToJsonb($table, $record);
	if (!empty($jsonbExtra)) {
		$existing = [];
		if (isset($record[$jsonbCol]) && is_string($record[$jsonbCol])) {
			$existing = json_decode($record[$jsonbCol], true) ?? [];
		}
		$record[$jsonbCol] = json_encode(array_merge($existing, $jsonbExtra));
	}

	$insert = $db->AutoExecute($table, $record, 'INSERT');

	if ($insert !== false) {
		return $record[$pkCol];
	} else {
		return false;
	}
}

function ncmDelete($sql, $array = false)
{
	global $db;

	$result = $db->Execute($sql, $array);
	return $result;
}

function ncmUpdate($options)
{ //records (arr), table (str), where (str)
	global $db;

	if (!validity($options, 'array') || !validity($options['records'], 'array') || !validity($options['table']) || !validity($options['where'])) {
		return false;
	}

	$table  = $options['table'];
	$record = $options['records'];
	$where  = $options['where'];

	// Enrutar campos desconocidos al JSONB de la tabla
	[$cleanRecord, $jsonbExtra, $jsonbCol] = _routeToJsonb($table, $record);

	// Actualizar columnas reales via AutoExecute (solo si hay campos reales)
	$update   = true;
	$updateId = null;
	if (!empty($cleanRecord)) {
		$update   = $db->AutoExecute($table, $cleanRecord, 'UPDATE', $where);
		$updateId = $db->Insert_ID();
	}

	// Fusionar campos JSONB usando el operador || de PostgreSQL (non-destructive merge).
	// COALESCE maneja el caso en que la columna sea NULL en la fila existente.
	if ($update !== false && !empty($jsonbExtra)) {
		$jsonSql = "UPDATE $table SET $jsonbCol = COALESCE($jsonbCol, '{}') || ?::jsonb WHERE $where";
		$db->Execute($jsonSql, [json_encode($jsonbExtra)]);
	}

	if ($update !== false) {
		return ['error' => false, 'id' => $updateId];
	} else {
		return ['error' => $db->ErrorMsg()];
	}
}

function ncmWhile($result, $callback, $vars)
{
	if ($result) {
		while (!$result->EOF) {
			$field = _flattenJsonb($result->fields);
			if (is_callable($callback)) {
				call_user_func($callback, $field, $vars);
			}
			$result->MoveNext();
		}
		$result->Close();
	}
}


function loadCDNFiles($urls = [], $type = 'js', $manifest = '/manifest.json')
{
	global $plansValues, $countries;

	if ($type == 'js') {
		$default = [
			'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js', //quitar cuando pase a ajax page loads
			'https://cdnjs.cloudflare.com/ajax/libs/df-number-format/2.1.5/jquery.number.min.js',

			'https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js',
			'https://cdn.datatables.net/buttons/1.5.2/js/dataTables.buttons.min.js',

			'https://cdn.datatables.net/plug-ins/1.10.16/api/sum().js',

			'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.11/jquery.mask.js',
			'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.19.1/moment.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js',
			'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-daterangepicker/2.1.25/daterangepicker.min.js', //quitar cuando pase a ajax page loads
			'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery.form/4.2.1/jquery.form.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/ismobilejs/0.4.1/isMobile.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/snap.js/1.9.3/snap.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.15.2/xlsx.mini.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery.matchHeight/0.7.2/jquery.matchHeight-min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery-fullscreen-plugin/1.1.5/jquery.fullscreen-min.js',
			'https://browser.sentry-cdn.com/5.15.4/bundle.min.js'
		];

		//if($_GET['debug']){
		$rand = '?' . rand();
		//}else{
		//	$rand = '';
		//}

		$defaultLast = [APP_URL . '/scripts/common.js' . $rand];
	} else if ($type == 'css') {
		$default = [
			'https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap',
			'https://fonts.googleapis.com/icon?family=Material+Icons',
			'https://fonts.googleapis.com/css2?family=Shadows+Into+Light&display=swap',
			'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css',
			'https://cdnjs.cloudflare.com/ajax/libs/simple-line-icons/2.4.1/css/simple-line-icons.css',
			APP_URL . '/css/font.css',
			'https://fonts.googleapis.com/icon?family=Material+Icons',
			'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-daterangepicker/2.1.25/daterangepicker.min.css',
			'https://cdnjs.cloudflare.com/ajax/libs/animate.css/3.5.2/animate.min.css',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.css'
		];
		$defaultLast = [APP_URL . '/css/app.css', APP_URL . '/css/style.css?' . mt_rand()];

		//FAVICONS
		$_faviconBase = APP_URL;
		echo '<link rel="apple-touch-icon-precomposed" sizes="57x57" href="' . $_faviconBase . '/apple-touch-icon-57x57.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="' . $_faviconBase . '/apple-touch-icon-114x114.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="' . $_faviconBase . '/apple-touch-icon-72x72.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="144x144" href="' . $_faviconBase . '/apple-touch-icon-144x144.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="60x60" href="' . $_faviconBase . '/apple-touch-icon-60x60.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="120x120" href="' . $_faviconBase . '/apple-touch-icon-120x120.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="76x76" href="' . $_faviconBase . '/apple-touch-icon-76x76.png" />
	    <link rel="apple-touch-icon-precomposed" sizes="152x152" href="' . $_faviconBase . '/apple-touch-icon-152x152.png" />
	    <link rel="icon" type="image/png" href="' . $_faviconBase . '/favicon-196x196.png" sizes="196x196" />
	    <link rel="icon" type="image/png" href="' . $_faviconBase . '/favicon-96x96.png" sizes="96x96" />
	    <link rel="icon" type="image/png" href="' . $_faviconBase . '/favicon-32x32.png" sizes="32x32" />
	    <link rel="icon" type="image/png" href="' . $_faviconBase . '/favicon-16x16.png" sizes="16x16" />
	    <link rel="icon" type="image/png" href="' . $_faviconBase . '/favicon-128.png" sizes="128x128" />
	    <meta name="application-name" content=APP_NAME/>
	    <meta name="msapplication-TileColor" content="#FFFFFF" />
	    <meta name="msapplication-TileImage" content="' . $_faviconBase . '/mstile-144x144.png" />
	    <meta name="msapplication-square70x70logo" content="' . $_faviconBase . '/mstile-70x70.png" />
	    <meta name="msapplication-square150x150logo" content="' . $_faviconBase . '/mstile-150x150.png" />
	    <meta name="msapplication-wide310x150logo" content="' . $_faviconBase . '/mstile-310x150.png" />
	    <meta name="msapplication-square310x310logo" content="' . $_faviconBase . '/mstile-310x310.png" />';
		//FAVICONS
	}

	$array = array_merge($default, $urls, $defaultLast);

	foreach ($array as $url) {
		if ($type == 'js') {
			echo '<script type="text/javascript" src="' . $url . '" ></script>';
		} else if ($type == 'css') {
			echo '<link rel="stylesheet" href="' . $url . '" type="text/css" />';
		}
	}

	if ($type == 'css') { ?>
		<link rel="manifest" href="<?= $manifest ?>" />
	<?php
	}
}

function getComissionValue($percent, $price)
{
	if ($percent && $price) {
		return ($price * $percent) / 100;
	} else {
		return 0;
	}
}

function toggleMenuActive($submenu, $subon, $menu, $menuon, $multi = false)
{
	if (!$multi) {
		if (($submenu == $subon && $submenu && $subon) || ($menu == $menuon && $menu && $menuon)) {
			return 'active';
		}
	} else {
		$ex = explode('|', $multi);
		if (in_array($submenu, $ex)) {
			return 'active';
		}
	}
}

function curlContents($url, $method = 'GET', $data = false, $headers = false, $returnInfo = false, $timeout = 20, $referer = false)
{
	$ch = curl_init();

	if ($method == 'POST') {
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		if ($data !== false) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		}
	} else {
		if ($data !== false) {
			if (is_array($data)) {
				$dataTokens = array();
				foreach ($data as $key => $value) {
					array_push($dataTokens, urlencode($key) . '=' . urlencode($value));
				}
				$data = implode('&', $dataTokens);
			}
			curl_setopt($ch, CURLOPT_URL, $url . '?' . $data);
		} else {
			curl_setopt($ch, CURLOPT_URL, $url);
		}
	}

	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

	if ($referer) {
		curl_setopt($ch, CURLOPT_REFERER, $referer);
	}

	if ($headers !== false) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}

	$contents = curl_exec($ch);

	if ($returnInfo) {
		$info = curl_getinfo($ch);
	}

	curl_close($ch);

	if ($returnInfo) {
		return array('contents' => $contents, 'info' => $info);
	} else {
		return $contents;
	}
}

function getFileContent($url)
{ //usar solo con urls propias y controladas por encom
	$ops = 	[
		"ssl" => [
			"verify_peer" 		=> false,
			"verify_peer_name" 	=> false,
		],
		'http' => [
			'header' 			=>
			'Cookie: ' . $_SERVER['HTTP_COOKIE'] . "\r\n"
		]
	];

	return file_get_contents($url, false, stream_context_create($ops));
}

function maintaining()
{
	echo '<div class="bg-warning r-24x lter font-bold col-xs-12 wrapper text-center text-dark">
		Plataforma en mantenimiento, puede que experimente ciertos inconvenientes temporales.
	</div>';
}

function footerInjector()
{
	global $plansValues, $startDate, $endDate;

	$dS 	= '.';
	$tsS 	= ',';
	if (THOUSAND_SEPARATOR == 'dot') {
		$dS 	= ',';
		$tsS 	= '.';
	}
	$out = '<script>';
	$out .= 'var noSessionCheck = false;';
	$out .= 'window.decimal = "' . DECIMAL . '";';
	$out .= 'window.thousandSeparator = "' . THOUSAND_SEPARATOR . '";';
	$out .= 'window.decimalSymbol = "' . $dS . '";';
	$out .= 'window.thousandSeparatorSymbol = "' . $tsS . '";';
	$out .= 'window.currency = "' . CURRENCY . '";';
	$out .= 'window.companyId = "' . enc(COMPANY_ID) . '";';
	$out .= 'window.startDate = "' . $startDate . '";';
	$out .= 'window.endDate = "' . $endDate . '";';
	$out .= '</script>';
	echo $out;

	?>


<?php
}

function plansTables()
{
	global $plansValues;
	$monthDiscount  = 2;

	$starter    = round((($plansValues[8]['price'] * 12) - ($plansValues[8]['price'] * $monthDiscount)) / 12, 1);
	$company    = (($plansValues[9]['price'] * 12) - ($plansValues[9]['price'] * $monthDiscount)) / 12;
	$full       = (($plansValues[10]['price'] * 12) - ($plansValues[10]['price'] * $monthDiscount)) / 12;

	$starte     = explode('.', $starter);
	$starter    = $starte[0];
	if ($starte[1] > 0 && $not) {
		$starter = $starter . '<span class="text-md font-bold">.' . iftn($starte[1], '0') . '</span>';
	}
	$starter    = $starter . '<span class="text-md font-bold text-muted">/Año</span>';
	$starterAlt = 'O $' . ($plansValues[8]['price'] * 1) . '/mes';

	$compan     = explode('.', $company);
	$company    = $compan[0];
	if ($compan[1] > 0 && $not) {
		$company = $company . '<span class="text-md font-bold">.' . iftn($compan[1], '0') . '</span>';
	}
	$company    = $company . '<span class="text-md font-bold text-muted">/Año</span>';
	$companyAlt = 'O $' . ($plansValues[9]['price'] * 1) . '/mes';

	$ful     = explode('.', $full);
	$full    = $ful[0];
	if ($ful[1] > 0 && $not) {
		$full = $full . '<span class="text-md font-bold">.' . iftn($ful[1], '0') . '</span>';
	}
	$full = $full . '<span class="text-md font-bold text-muted">/Año</span>';
	$fullAlt = 'O $' . ($plansValues[10]['price'] * 1) . '/mes';
?>

	<div class="col-xs-12 text-dark text-u-c font-bold" style="font-size:1.5em;">Planes</div>

	<div class="col-xs-12 no-padder hidden-xs">
		<div class="col-sm-4 wrapper-sm"></div>
		<div class="col-sm-4 wrapper-sm"></div>
		<div class="col-sm-4 wrapper-sm"></div>
	</div>

	<div class="col-xs-12 no-padder r-3x clear">
		<div class="col-sm-4 m-b no-padder" style="">
			<div class="text-u-c font-bold m-t-sm col-xs-12 no-padder"><?= $plansValues[9]['name'] ?></div>
			<div class="font-bold" style="font-size:5em;">$45</div>
			<a href="/@#history_billing?upgraded=pay&planis=<?= enc(9) ?>" class="btn btn-info btn-rounded text-u-c font-bold btn-lg m-t-xs navigateAway">Seleccionar</a>
			<ul class="text-left text-md list-group no-bg no-border m-t">
				<li class="list-group-item">
					3 usuarios por sucursal
				</li>
				<li class="list-group-item">
					2 puntos de venta por sucursal
				</li>
				<li class="list-group-item">
					1.000 productos o servicios
				</li>
				<li class="list-group-item">
					Sucursales ilimitadas*
				</li>
				<li class="list-group-item">
					Hasta 1.500 ventas al mes por sucursal
				</li>
				<li class="list-group-item">
					Soporte vía chat y por e-mail.
				</li>
			</ul>
		</div>
		<div class="col-sm-4 m-b no-padder dk" style="">
			<div class="text-u-c font-bold m-t-sm col-xs-12 no-padder"><?= $plansValues[10]['name'] ?></div>
			<div class="font-bold" style="font-size:5em;">$60</div>

			<a href="/@#history_billing?upgraded=pay&planis=<?= enc(10) ?>" class="btn btn-info btn-rounded text-u-c font-bold btn-lg m-t-xs navigateAway">Seleccionar</a>
			<ul class="text-left text-md list-group no-bg no-border m-t">
				<li class="list-group-item">
					Todo lo del plan <?= $plansValues[9]['name'] ?>
				</li>
				<li class="list-group-item">
					6 usuarios por sucursal
				</li>
				<li class="list-group-item">
					3 puntos de venta por sucursal
				</li>
				<li class="list-group-item">
					5.000 productos y servicios
				</li>
				<li class="list-group-item">
					Sucursales ilimitadas*
				</li>
				<li class="list-group-item">
					Hasta 5.000 ventas al mes por sucursal
				</li>
				<li class="list-group-item">
					Acceso a la API
				</li>
			</ul>
		</div>
		<div class="col-sm-4 m-b no-padder b-r bg" style="">
			<div class="text-u-c font-bold m-t-sm col-xs-12 no-padder"><?= $plansValues[11]['name'] ?></div>
			<div class="font-bold" style="font-size:5em;">$100</div>

			<a href="/@#history_billing?upgraded=pay&planis=<?= enc(11) ?>" class="btn btn-info btn-rounded text-u-c font-bold btn-lg m-t-xs navigateAway">Seleccionar</a>
			<ul class="text-left text-md list-group no-bg no-border m-t">
				<li class="list-group-item">
					Todo lo del plan <?= $plansValues[1]['name'] ?>
				</li>
				<li class="list-group-item">
					15 usuario
				</li>
				<li class="list-group-item">
					5 puntos de venta
				</li>
				<li class="list-group-item">
					10.000 productos o servicios
				</li>
				<li class="list-group-item">
					Sucursales ilimitadas*
				</li>
				<li class="list-group-item">
					Hasta 15.000 ventas al mes
				</li>
			</ul>
		</div>
	</div>

	<div class="wrapper col-xs-12 text-center font-bold text-muted">
		*El precio del plan es por cada sucursal
	</div>

	<div class="col-xs-12 no-padder m-b text-muted text-center text-sm">
		<a href="//precios" class="btn btn-info btn-lg text-u-c font-bold btn-rounded">Comparar Planes</a>
	</div>

<?php
}
function noDataMessage($title = false, $description = false, $image = false, $url = false, $btn = false, $classBtn = '', $extraBtn = '')
{
	$title 			= iftn($title, 'No hay reportes que mostrar');
	$description 	= iftn($description, 'No hay información suficiente para generar reportes');
	$image 			= iftn($image, 'emptystate4.png');
	if (strpos($image, 'images/') !== false) {
	} else {
		$image = 'images/' . $image;
	}
?>
	<div class="text-center col-xs-12 wrapper noDataMessage">
		<img src="<?= $image; ?>" height="140">
		<h1 class="font-bold"><?= $title; ?></h1>
		<div class="text-muted m-t">
			<p>
				<?= $description; ?>
			</p>
			<?php
			if ($btn) {
			?>
				<a href="<?= $url; ?>" class="btn btn-primary btn-lg btn-rounded all-shadows m-t <?= $classBtn ?>" <?= $extraBtn ?>><?= $btn; ?></a>
			<?php
			}
			?>
		</div>
	</div>
<?php

}

function upgradeTooltipMsg($plan, $custom = false)
{

	if ($plan == 'Starter') {
		$color = 'text-warning';
	} else if ($plan == 'Company' || $plan == 'Advanced') {
		$color = 'text-success';
	} else {
		$color = 'text-info';
	}
	$out = '<div class="text-center text-sm text-white wrapper-sm" style="min-width:100px;">' .
		'<div class="">Actualice al Plan</div>' .
		'<div class="h2 ' . $color . '">' . $plan . '</div>' .
		'<div class="">Para Habilitar</div>' .
		'</div>';

	if ($custom) {
		$out = $plan;
	}

	return htmlspecialchars($out, ENT_QUOTES);
}

function getRolePermissions($roleId, $companyId)
{
	global $_ROLES_DATA;

	$index 		= ncmExecute("SELECT sourceId FROM taxonomy WHERE taxonomyType = 'role' AND taxonomyExtra = ? LIMIT 1", [$roleId], true);

	if ($index) {
		$saved 		= ncmExecute("SELECT taxonomyExtra FROM taxonomy WHERE taxonomyType = 'roleData' AND sourceId = ? AND companyId = ? LIMIT 1", [$index['sourceId'], $companyId]);

		if ($saved) {
			return json_decode($saved['taxonomyExtra'], true);
		} else {
			return $_ROLES_DATA[$index['sourceId']];
		}
	} else {
		// Si hay permisos en sesión, usarlos; si no, usar BOSS (acceso total) como fallback
		if (!empty($_SESSION['user']['rolePermisions'])) {
			return $_SESSION['user']['rolePermisions'];
		}
		return $_ROLES_DATA[0]; // $_BOSS como fallback
	}
}

function allowUser($section, $action, $boolean = false)
{
	if ($section && $action && !validateHttp('widget')) {
		$company = COMPANY_ID;

		if (SAAS_ADM) {
			$company = 15;
		}

		$permissions 	= getRolePermissions(ROLE_ID, $company);
		$permissions 	= is_array($permissions) ? ($permissions['panel'] ?? []) : [];
		$check = false;
		if (in_array($section, ['sales', 'expenses'])) {
			$check 	 = $permissions['reports'][$section][$action] ?? false;
		}
		if (!in_array($section, ['sales', 'expenses']) && (!array_key_exists($section, $permissions) || !array_key_exists($action, (array)$permissions[$section]))) {
			$check = false;
		} else if (!in_array($section, ['sales', 'expenses'])) {
			$check 		 		= $permissions[$section][$action];
		}


		if (!$check) {
			if ($boolean) {
				return false;
			} else {
				include_once("a_stand_by_page.php");
				dai();
			}
		} else {
			if ($boolean) {
				return true;
			}
		}
	}
}

function topHook()
{
	if (validateHttp('action') || validateHttp('widget')) {
		theErrorHandler('json'); //error handler
		if (in_array(validateHttp('widget'), ['notificationsCount'])) {
			return false;
		}
		session_write_close();
	} else {
		theErrorHandler(); //error handler
	}

	if (ROLE_ID > 1 && COMPANY_ID == ENCOM_COMPANY_ID) {
		//    header('location:/main');
	}
}

function mainAlerts()
{
	if (ACCEPTED_TERMS < 1 && ROLE_ID == 1) {
		echo 	'<div class="bg-info lter col-xs-12 wrapper r-24x text-left">' .
			'	<div class="col-sm-8">Hemos actualizado nuestros <a href="' . ASSETS_URL . '/terminosycondiciones.pdf" class="text-u-l" target="_blank">Términos y Condiciones</a> de uso del sistema. <br>' .
			'		Para aceptarlos y continuar utilizando el sistema haga click en el botón Acepto ' .
			'	</div> ' .
			'	<div class="col-sm-4 text-right">' .
			'		<a href="?acceptTerms=true" class="btn no-bg font-bold text-u-c btn-s-md btn-rounded">Acepto</a>' .
			'	</div> ' .
			'</div>';
	}

	if (EXPIRED == 1) {
		echo 	'<div class="bg-danger gradBgRed animateBg col-xs-12 wrapper r-24x text-left">' .
			'	<div class="col-sm-9">Le recordamos que posee <strong>facturas vencidas</strong> en su cuenta ' . APP_NAME . '. <br>' .
			'		<i class="hidden-xs">Le recomendamos que pueda ponerse al día o bien pongase en contacto con nosotros y le asistiremos.</i>' .
			'	</div>' .
			'	<div class="col-sm-3 text-right">' .
			'		<a href="/@#history_billing" class="btn no-bg font-bold text-u-c btn-s-md btn-rounded">Ver Pendientes</a>' .
			'	</div>' .
			'</div>';
	}
}

function insertNotifications($ops)
{
	global $db;
	$title 		= $ops['title'];
	$msg 		= $ops['message'];
	$date 		= iftn($ops['date'], TODAY);
	$link 		= $ops['link'];
	$type 		= $ops['type'];
	$mode 		= ($ops['mode']) ? $ops['mode'] : 1;
	$status 	= ($ops['status']) ? $ops['status'] : '1';
	$register 	= $ops['register'];
	$outlet		= $ops['outlet'];
	$company	= $ops['company'];
	$edata		= $ops['edata'];
	$record 	= [];

	$record['notifyTitle'] 		= $title;
	$record['notifyMessage'] 	= $msg;
	$record['notifyDate'] 		= $date;
	$record['notifyLink'] 		= $link;
	$record['notifyType'] 		= $type;
	$record['notifyMode'] 		= $mode;
	$record['notifyStatus']		= $status;
	$record['registerId'] 		= $register;
	$record['outletId'] 		= $outlet;
	$record['companyId'] 		= $company;
	$insert 					= $db->AutoExecute('notify', $record, 'INSERT');

	//envio push al panel
	if (validity($ops['push'])) {
		$tags 			= $ops['push']['tags'];
		$where 			= $ops['push']['where'] ? $ops['push']['where'] : 'panel';

		$link 			= iftn($link, ($where == 'panel') ? APP_URL : '');

		sendPush([
			"ids" 		=> enc($company),
			"message" 	=> $msg,
			"title" 	=> $title,
			"where" 	=> $where,
			"web_url"	=> $link,
			"app_url" 	=> $link,
			"filters" 	=> $tags,
			"edata" 	=> $edata
		]);
	}

	if (validity($ops['email']) && validity($company)) { //si hay que enviar por email
		$email = ncmExecute("SELECT
								contactEmail,
								companyId
							FROM contact
							WHERE companyId = ?
							AND type = 0 
							AND main = \'true\' 
							ORDER BY companyId ASC", [$company], false, true);
		if ($email) {
			while (!$email->EOF) {
				$fields = $email->fields;

				$body = $msg;

				if (validity($link)) {
					$body .= '<br><a href="' . $link . '">Ver más</a>';
				}

				$meta['subject'] = '[' . APP_NAME . '] ' . $title;
				$meta['to']      = $fields['contactEmail'];
				$meta['fromName'] = APP_NAME;
				$meta['data']    = [
					"message"     => $body,
					"companyname" => APP_NAME,
					"companylogo" => '/assets/150-150/0/' . enc($fields['companyId']) . '.jpg?' . date('h')
				];

				sendEmails($meta);

				$email->MoveNext();
			}
		}
	}
}

function leftMenu($isoutlet = false, $register = false, $submenu = false)
{
	global $db, $SQLcompanyId, $plansValues, $_modules;
	include_once("includes/analyticstracking.php");
?>
	<?= menuMobile('left', $isoutlet) ?>
	<aside class="bg-light aside hidden-print hidden-xs nav-xs text-center" id="nav" style="height:99vh; max-width:80px; width:80px; padding:10px 0 10px 10px;">
		<section class="vbox bg-black lter rounded animated fadeInLeft speed-3x hidden">
			<section class="w-f-md">
				<nav class="nav-primary">
					<a href="/@#dashboard" class="wrapper block"> <img src="/images/iconincomesmwhite.png" alt=APP_NAME width="35" id="toastnlogo"> </a>
					<ul class="nav" data-ride="collapse">

						<?php

						if (SAAS_ADM) {
							$main = 'main';
						?>
							<li>
								<a href="/main?backToSaaS=true"> <i class="material-icons">store</i> <span class="font-bold text-u-c">Empresas</span> </a>
							</li>
						<?php
						}

						?>

						<li>
							<a href="/@#items" class="mnItemsBtn"> <i class="material-icons">view_list</i> <span class="font-bold text-u-c">Artículos</span> </a>
						</li>

						<li>
							<a href="/@#contacts" class="mnContactsBtn"> <i class="material-icons">person</i> <span class="font-bold text-u-c">Contactos</span> </a>
						</li>

						<li>
							<a href="/@#reports" class="mnReportsBtn"> <i class="material-icons">bar_chart</i> <span class="font-bold text-u-c">Reportes</span> </a>
						</li>

						<li class="hidden-xs">
							<?php
							$based 	= '?i=' . base64_encode(enc(COMPANY_ID) . ',' . enc(OUTLET_ID));
							?>
							<a href="<?= POS_URL ?><?= $based; ?>" target="_blank" class="mnPOSBtn"> <i class="material-icons">chrome_reader_mode</i> <span class="font-bold text-u-c">Caja</span> </a>
						</li>

					</ul>
				</nav>
			</section>
			<footer class="footer no-padder text-center-nav-xs" style="position:absolute;bottom:0;max-width:70px;">

				<a href="#" class="hover col-xs-12 wrapper notifybtn" data-toggle="tooltip" data-placement="right" title="Notificaciones">
					<i class="material-icons">notifications</i>
					<span class="badge badge-sm up bg-danger count animated bounceIn hidden notifybtncount" style="display: inline-block;">0</span>
				</a>

				<div class="dropup col-xs-12 no-padder" data-toggle="tooltip" data-placement="right" title="<?= getCurrentOutletName(); ?>">

					<a href="#" class="dropdown-toggle lt col-xs-12 wrapper rounded" data-toggle="dropdown">
						<span class="thumb-sm avatar">
							<img src="<?= companyLogo(70); ?>" alt="<?= COMPANY_NAME ?>">
							<i class="on b-light hidden"></i>
						</span>
					</a>

					<ul class="dropdown-menu bg-white animated fadeInLeft animatedx2 aside text-left">
						<?php $outlet = ncmExecute('SELECT * FROM outlet WHERE outletStatus = 1 AND ' . $SQLcompanyId . ' LIMIT 20', [], false, true); ?>

						<li class="dropdown-header text-center"><?= USER_NAME; ?></li>

						<li class="row">
							<div class="col-xs-6 text-right wrapper-sm">
								<a href="#" class="btn r-3x b b-light ncmDarkMode" data-toggle="tooltip" data-placement="right" title="Dark Mode">
									<i class="material-icons">brightness_medium</i>
								</a>
							</div>
							<div class="col-xs-6 text-left wrapper-sm">
								<a href="#" class="btn r-3x b b-light ncmFullscreenMode" data-toggle="tooltip" data-placement="right" title="Pantalla Completa">
									<i class="material-icons">fullscreen</i>
								</a>
							</div>
						</li>

						<?php if (($isoutlet && OUTLETS_COUNT > 1) && (ROLE_ID < 2 || $_SESSION['user']['toFixedOutlet'] < 1)) { ?>

							<li class="text-u-c wrap-l text-sm">Sucursales</li>
							<?php
							$extra = '';
							if (validateHttp('state') == 'outcome') {
								$extra = '&state=outcome';
							}

							if ($outlet) {
								while (!$outlet->EOF) {
									if (OUTLET_ID != $outlet->fields['outletId']) {
										echo '<li> <a href="/@?o=' . enc($outlet->fields['outletId']) . $extra . '">' . toUTF8($outlet->fields['outletName']) . '</a> </li>';
									}
									$outlet->MoveNext();
								}
							}
							?>

							<?= (OUTLETS_COUNT > 1) ? '<li> <a href="/@?o=1&r=1' . $extra . '">Todas</a> </li>' : '' ?>

							<li class="divider"></li>

						<?php } ?>
						<li> <a href="/@#history_billing">Estado de Cuenta</a> </li>
						<li> <a href="/@#purchase">Compras y Gastos</a> </li>
						<li> <a href="/@#modules">Módulos</a> </li>
						<li> <a href="/@#settings"><?= L_M_SETTINGS ?></a> </li>
						<li> <a href="/logout"><span class="text-danger"><?= L_M_LOGOUT ?></span></a> </li>
					</ul>
				</div>
			</footer>
		</section>
	</aside>

	<?php
}

function menuFrame($position, $isoutlet = false, $register = false, $submenu = false)
{
	if ($position == 'top') {
	?>
		<section class="hbox stretch">
			<?php leftMenu($isoutlet, $register, $submenu); ?>
			<section id="content" class="col-xs-12 no-padder noscroll" style="background-color: #2f3940;">
				<?php menuMobile('top', $isoutlet); ?>
				<div class="hidden no-border no-bg slideInRight animated animatedx2 notyfybg" id="notify">
					<div class="col-xs-12 no-padder">
						<div class="col-xs-12 scrollable" style="height: 95vh;" id="notifyBody">
							<div class="col-xs-12 wrapper no-bg text-right text-white notytime">
								<a href="#" class="pull-left btn notifybtn m-l-n"><i class="material-icons">close</i></a>
								<div class="m-b-n font-thin notifyHour">
									##:##
								</div>
								<div class="text-md notifyDate">
									####, ## ### ####
								</div>
							</div>

							<div id="notifyList">

								<div class="text-right col-xs-12 wrapper m-t-md">
									<h1 class="font-bold text-white">Sin nuevas notificaciones</h1>
									<div class=""><a href="/@#report_notifications" class="navigateAway text-white text-u-l">Historial de Notificaciones &gt;</a></div>
								</div>

							</div>

						</div>
					</div>
				</div>

				<section class="col-xs-12 wrapper bg-light scrollable" style="height:100vh;" id="bodyContent">
					<?= mainAlerts(); ?>

				<?
			} else {
				?>
				</section>
			</section>
		</section>

	<?php
			}
		}

		function menuMobile($position, $isoutlet = false)
		{
			global $db, $SQLcompanyId, $plansValues;

			if ($position == 'left') {
	?>
		<div class="text-white scrollable no-padder bg-black lter col-xs-12 no-padder hidden-print visible-xs" style="width:270px; height: 100vh; position:absolute; top:0; left:0; z-index:0;">

			<div class="wrapper-sm text-center clickeable m-b" data-type="hideMenu" style="width: 270px;">
				<a href="#" class="thumb-lg m-t rounded" id="companyLogo" style="background-image: url('/assets/150-150/0/<?= enc(COMPANY_ID) ?>.jpg?<?= date('d') ?>'); transition: background-image 5s ease 0s; background-size: 128px 128px; height: 128px; width: 128px;">
					<img src="" class="img-circle" id="companyImg" style="display: none;">
				</a>
				<div class="text-sm">
					<div class="h3 m-t-xs m-b-xs font-bold text-white" id="companyName"><?= COMPANY_NAME ?></div>
					<span id="outletAndRegisterText"><?= getCurrentOutletName(); ?></span>
					<i class="block" id="userName"><?= USER_NAME ?></i>
				</div>
			</div>

			<div class="divider b"></div>

			<div class="col-xs-12 no-padder">

				<?php
				if (SAAS_ADM) {
					$main = 'main';
				?>
					<a href="/main?backToSaaS=true" class="block wrapper-md hoverMenu text-md">
						<i class="material-icons text-muted m-r-sm m-l">store</i> <span class="text-white">Empresas</span>
					</a>
				<?php
				}
				?>

				<a href="/@#items" class="block wrapper-md hoverMenu text-md mmnItemsBtn">
					<i class="material-icons text-muted m-r-sm m-l">view_list</i> <span class="text-white">Artículos</span>
				</a>

				<a href="/@#contacts" class="block wrapper-md hoverMenu text-md mmnContactsBtn">
					<i class="material-icons text-muted m-r-sm m-l">person</i> <span class="text-white">Contactos</span>
				</a>

				<a href="/@#reports" class="block wrapper-md hoverMenu text-md mmnReportsBtn">
					<i class="material-icons text-muted m-r-sm m-l">bar_chart</i> <span class="text-white">Reportes</span>
				</a>

				<a href="" class="block wrapper-md hoverMenu text-md mmnPOSBtn hidden-xs">
					<i class="material-icons text-muted m-r-sm m-l">chrome_reader_mode</i> <span class="text-white">Caja</span>
				</a>

				<?php $outlet = ncmExecute('SELECT * FROM outlet WHERE outletStatus = 1 AND ' . $SQLcompanyId . ' LIMIT 20', [], false, true); ?>



				<?php if (($isoutlet && OUTLETS_COUNT > 1) && (ROLE_ID < 3 || FIXED_OUTLET_ID < 1)) { ?>

					<a class="block wrapper-md hoverMenu text-md" data-toggle="collapse" href="#collapseOutlets" role="button" aria-expanded="false" aria-controls="collapseOutlets">
						<i class="material-icons text-muted m-r-sm m-l">add</i> <span class="text-white">Sucursales</span> <i class="material-icons pull-right">keyboard_arrow_down</i>
					</a>
					<div class="col-xs-12 panel bg m-b no-padder">
						<div class="list-group no-radius no-border auto text-md collapse" id="collapseOutlets">
							<?php
							$extra = '';
							if (validateHttp('state') == 'outcome') {
								$extra = '&state=outcome';
							}

							if ($outlet) {
								while (!$outlet->EOF) {
									if (OUTLET_ID != $outlet->fields['outletId']) {
										echo '<a href="/@?o=' . enc($outlet->fields['outletId']) . $extra . '" class="list-group-item no-bg b-black m-l"> <span>' . toUTF8($outlet->fields['outletName']) . '</span> </a>';
									}
									$outlet->MoveNext();
								}
							}
							?>

							<?= (OUTLETS_COUNT > 1) ? '<a href="/@?o=1&r=1' . $extra . '" class="list-group-item no-bg b-black m-l"> <span>Todas</span> </a>' : '' ?>
						</div>
					</div>
				<?php } ?>

				<div class="divider b m-t m-b"></div>

				<div id="mSubMenu">
					<a href="/@#history_billing" class="block wrapper-sm hoverMenu">
						<span class="text-white m-l">Estado de Cuenta</span>
					</a>
					<a href="/@#purchase" class="block wrapper-sm hoverMenu">
						<span class="text-white m-l">Compras y Gastos</span>
					</a>
					<a href="/@#modules" class="block wrapper-sm ">
						<span class="text-white m-l">Módulos</span>
					</a>
					<a href="/@#settings" class="block wrapper-sm">
						<span class="text-white m-l"><?= L_M_SETTINGS ?></span>
					</a>

					<a href="/logout" class="block wrapper-sm hoverMenu">
						<span class="text-danger m-l">Cerrar Sesión</span>
					</a>
				</div>

			</div>
		</div>
	<?php
			} else if ($position == 'top') {
	?>
		<div class="col-xs-12 no-padder text-white visible-xs">
			<div class="col-xs-4 no-padder text-left">
				<a href="#" class="col-xs-12 wrapper" id="openMobileMenu">
					<i class="material-icons text-white">sort</i>
				</a>
			</div>
			<div class="col-xs-4 wrapper-xs text-center">
				<a href="/@#dashboard">
					<img src="/images/iconincomesmwhite.png" height="35">
				</a>
			</div>
			<div class="col-xs-4 no-padder text-right">
				<a href="#" class="col-xs-6 wrapper ncmDarkMode">
					<i class="material-icons text-white">brightness_medium</i>
				</a>

				<a href="#" class="col-xs-6 wrapper notifybtn">
					<i class="material-icons text-white">notifications</i>
				</a>
			</div>
		</div>

	<?php
			}
	?>
<?php
		}

		function headerPrint($ops = [])
		{
			global $_fullSettings;
?>
	<div class="col-xs-12 text-center m-t m-b visible-print">
		<img src="<?= companyLogo(50); ?>" width="50" class="img-circle b">
		<div class="col-xs-12 font-bold h3 m-t-sm"><?= COMPANY_NAME ?></div>
		<div class="col-xs-12"><?= $_fullSettings['ruc'] ?></div>
		<?php
			if (empty($ops['noOutlet'])) {
		?>
			<div class="col-xs-12 no-padder m-t-sm"><?= getCurrentOutletName(); ?></div>
		<?php
			}
		?>
		<?php
			if (!empty($ops['text'])) {
		?>
			<div class="col-xs-12 no-padder m-t-sm"><?= $ops['text']; ?></div>
		<?php
			}
		?>
	</div>
<?php
		}

		function footerPrint($ops = [])
		{

			$mTop = (!empty($ops['top'])) ? $ops['top'] . 'px' : '200px';
?>
	<div class="col-xs-12 no-padder visible-print">
		<div class="col-xs-12 text-left">
			<span class="font-bold text-u-c">Emitido por</span> <?= USER_NAME ?> <br>
			<span class="font-bold text-u-c">Fecha</span> <?= niceDate(TODAY, true) ?>
		</div>
		<div class="col-xs-12 text-center <?= $ops['signatures'] ? '' : 'hidden'; ?>" style="margin-top: <?= $mTop ?>;">
			<?php
			if ($ops['signatures'] == 2) {
			?>
				<div class="col-sm-4 b-t b-dark text-center font-bold text-u-c">
					Firma 1
				</div>
				<div class="col-sm-4 col-sm-offset-4 b-t b-dark text-center font-bold text-u-c">
					Firma 2
				</div>
			<?php
			} else if ($ops['signatures'] == 1) {
			?>
				<div class="col-sm-4 b-t b-dark text-center font-bold text-u-c">
					Firma
				</div>
			<?php
			}
			?>
		</div>
		<div class="col-xs-12 text-sm m-t-lg text-center">
			Usamos <?= APP_NAME ?> - <?= APP_URL ?>
		</div>
	</div>
<?php
		}

		function getTableLimits($limit, $offset, $noLimit = false, $returns = 'query')
		{
			//obtengo limit y offset para añadir al query de una tabla
			$noLimit 	= ($noLimit) ? $noLimit : validateHttp('nolimit');
			$to 		= (validateHttp('offset')) ? validateHttp('offset') : $offset;
			$limite 	= (validateHttp('limit')) ? validateHttp('limit') : $limit;

			if ($noLimit) {
				$limits 	= ' LIMIT 10000';
				if ($returns != 'query') {
					$limits 	= ['10000', $to];
				}
			} else {
				$limits 	= ' LIMIT ' . $limite . ' OFFSET ' . $to;
				if ($returns != 'query') {
					$limits 	= [$limite, $to];
				}
			}
			return $limits;
		}

		function adm($value, $type, $id, $action, $callback = '')
		{
			global $db, $SQLcompanyId;

			if (validateHttp('tableExtra') && validateHttp('actionExtra')) {
				$record 									= [];
				$value 										= str_replace(['× '], [''], $value);
				$toggle 									= validateHttp('toggleExtra');
				$record['taxonomyName']		= trim(strip_tags($value));
				$record['taxonomyType']		= $type;
				$record['taxonomyExtra'] 	= $toggle;
				$record['companyId'] 			= COMPANY_ID;

				if (validateHttp('admOutlet')) {
					$record['outletId'] 		= dec(validateHttp('admOutlet'));
				}

				if ($action == 'add') {
					$result = ncmExecute('SELECT taxonomyId,taxonomyName FROM taxonomy WHERE taxonomyName = ? AND taxonomyType = ? AND ' . $SQLcompanyId . ' LIMIT 1', [$value, $type]);
					if ($result) {
						dai('<option value="' . $result['taxonomyId'] . '" selected>' . toUTF8($result['taxonomyName']) . ' (Already exists)</option>');
					} else {
						$record['taxonomyExtra'] = 2;
						$insert 	= $db->AutoExecute('taxonomy', $record, 'INSERT');
						$insertedId = $db->Insert_ID();
						if ($insert === false) {
							dai('<option value="" selected>Insert Error</option>');
						} else {
							updateLastTimeEdit();
							if ($callback) {
								call_user_func($callback, $insertedId, $action);
							}
							dai('<option value="' . enc($insertedId) . '" selected>' . toUTF8($value) . '</option>');
						}
					}
				} elseif ($action == 'edit') {
					$update = $db->AutoExecute('taxonomy', $record, 'UPDATE', 'taxonomyId = ' . $id . ' AND ' . $SQLcompanyId);
					if ($update === false) {
						dai('<option value="" selected>Edit Error</option>');
					} else {
						updateLastTimeEdit();
						dai('<option value="' . enc($id) . '" data-toggle="' . $toggle . '" selected>' . (($toggle == 1) ? '× ' : '') . toUTF8($value) . '</option>');
					}
				} elseif ($action == 'delete') {
					$delete = $db->Execute("DELETE FROM taxonomy WHERE taxonomyId = " . $id . " AND " . $SQLcompanyId . " LIMIT 1");

					if ($delete === false) {
						dai('null');
					} else {
						echo enc($id);
						updateLastTimeEdit();
						dai();
					}
				} elseif ($action == 'toggle') {

					$record['taxonomyExtra'] = $toggle;

					$update = $db->AutoExecute('taxonomy', $record, 'UPDATE', 'taxonomyId = ' . $id . ' AND ' . $SQLcompanyId);
					if ($update === false) {
						dai('<option value="" selected>Edit Error</option>');
					} else {
						updateLastTimeEdit();
						dai('<option value="' . enc($id) . '" data-toggle="' . $toggle . '" selected>' . (($toggle == 1) ? '× ' : '') . toUTF8($value) . '</option>');
					}
				}
			}

			dai();
		}

		function updateLastTimeEdit($id = false, $table = false)
		{
			global $SQLcompanyId, $db;

			$date 			= TODAY;
			$record 		= [];
			if ($id) {
				$SQLcompanyId = 'companyId = ' . $id;
			}

			if ($table == 'customer') {
				$record['customersLastUpdate'] 	= $date;
			} else if ($table == 'item') {
				$record['itemsLastUpdate'] 		= $date;
			} else if ($table == 'calendar') {
				$record['calendarLastUpdate']   = $date;
			} else if ($table == 'order') {
				$record['orderLastUpdate'] 		= $date;
			} else {
			}
			$record['companyLastUpdate'] 		= $date;
			$db->AutoExecute('company', $record, 'UPDATE', $SQLcompanyId);
			return $date;
		}

		//truncate a string only at a whitespace (by nogdog)
		function truncate($str, $length, $end = '...')
		{
			if (validity($str)) {
				$text = explodes(" ", $str);
				$out = '';
				if (counts($text) > $length) {
					for ($i = 0; $i < $length; $i++) {
						$out .= $text[$i] . " ";
					}
					$out = rtrim($out) . $end;
					return $out;
				} else {
					return $str;
				}
			}
		}

		function setTimeZone($companyId, $setting = false)
		{
			$setting = ($setting) ? $setting : ncmExecute("SELECT settingTimeZone FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
			date_default_timezone_set($setting['settingTimeZone']);
		}

		function dates_month($month = 1, $year = 2000, $format = 'Y-m-d')
		{
			$num = cal_days_in_month(CAL_GREGORIAN, $month, $year);
			$dates_month = array();
			for ($i = 1; $i <= $num; $i++) {
				$mktime = mktime(0, 0, 0, $month, $i, $year);

				if ($format == 'each') {
					$d = date('d', $mktime);
					$m = date('m', $mktime);
					$y = date('Y', $mktime);
					$date = array($y, $m, $d);
					$dates_month[$i] = $date;
				} else {
					$date = date($format, $mktime);
					$d = date('d', $mktime);
					$m = date('m', $mktime);
					$y = date('Y', $mktime);
					$each = array($date, $y, $m, $d);
					$dates_month[$i] = $each;
				}
			}
			return $dates_month;
		}

		function googleMapsUrlParser($url)
		{
			//$url 	= 'https://www.google.com/maps/place/Coinpa+S.A.E.C.A/@-25.2773256,-57.5518445,17z/data=!4m5!3m4!1s0x945da5ffe345512f:0x1fd0c23602a99957!8m2!3d-25.2784727!4d-57.5529682';
			//$url 	= 'https://www.google.com/maps?q=-25.283803939819336,-57.56672668457031&z=17&hl=en';

			if (!validity($url)) {
				return false;
			}

			$separators = '/@';

			if (strpos($url, $separators) !== false) {
				$part 		= explodes($separators, $url, true, 1); // explode($separators,$url)[1];
				$coors    	= explodes(',', $part);
				$lat        = $coors[0];
				$lng        = $coors[1];
			} else {
				$parsed 	= parse_url($url);
				parse_str(htmlspecialchars_decode($parsed['query']), $query);

				$coors 		= explodes(',', $query['q']);
				$lat        = $coors[0];
				$lng        = $coors[1];
			}

			if (!is_numeric($lat) || !is_numeric($lng)) {
				return false;
			} else {
				return ['lat' => $lat, 'lng' => $lng];
			}
		}

		//parse a CSV file into a two-dimensional array
		//this seems as simple as splitting a string by lines and commas, but this only works if tricks are performed
		//to ensure that you do NOT split on lines and commas that are inside of double quotes.
		function parse_csv($str)
		{
			//match all the non-quoted text and one series of quoted text (or the end of the string)
			//each group of matches will be parsed with the callback, with $matches[1] containing all the non-quoted text,
			//and $matches[3] containing everything inside the quotes
			$str = preg_replace_callback('/([^"]*)("((""|[^"])*)"|$)/s', 'parse_csv_quotes', $str);

			//remove the very last newline to prevent a 0-field array for the last line
			$str = preg_replace('/\n$/', '', $str);

			//split on LF and parse each line with a callback
			return array_map('parse_csv_line', explode("\n", $str));
		}

		//replace all the csv-special characters inside double quotes with markers using an escape sequence
		function parse_csv_quotes($matches)
		{
			//anything inside the quotes that might be used to split the string into lines and fields later,
			//needs to be quoted. The only character we can guarantee as safe to use, because it will never appear in the unquoted text, is a CR
			//So we're going to use CR as a marker to make escape sequences for CR, LF, Quotes, and Commas.
			$str = str_replace("\r", "\rR", $matches[3]);
			$str = str_replace("\n", "\rN", $str);
			$str = str_replace('""', "\rQ", $str);
			$str = str_replace(',', "\rC", $str);

			//The unquoted text is where commas and newlines are allowed, and where the splits will happen
			//We're going to remove all CRs from the unquoted text, by normalizing all line endings to just LF
			//This ensures us that the only place CR is used, is as the escape sequences for quoted text
			return preg_replace('/\r\n?/', "\n", $matches[1]) . $str;
		}

		//split on comma and parse each field with a callback
		function parse_csv_line($line)
		{
			return array_map('parse_csv_field', explode(',', $line));
		}

		//restore any csv-special characters that are part of the data
		function parse_csv_field($field)
		{
			$field = str_replace("\rC", ',', $field);
			$field = str_replace("\rQ", '"', $field);
			$field = str_replace("\rN", "\n", $field);
			$field = str_replace("\rR", "\r", $field);
			return $field;
		}

		function generateCSVfromArray($data, $filename = 'archivo', $export = true)
		{
			# Generate CSV data from array
			$fh = fopen('php://temp', 'rw'); # don't create a file, attempt
			# to use memory instead

			if (!validity($data, 'array')) {
				return false;
			}

			# write out the headers
			fputcsv($fh, array_keys(current($data)));

			# write out the data
			foreach ($data as $row) {
				fputcsv($fh, $row);
			}
			rewind($fh);
			$csv = stream_get_contents($fh);
			fclose($fh);

			if ($export) {
				header('Content-Type: text/csv');
				header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
				echo $csv;
			} else {
				return $csv;
			}
		}

		function generateXLSfromArray($data, $filename = 'archivo', $export = true)
		{
			$xls = '';

			if (!validity($data, 'array')) {
				return false;
			}
			$flag = false;
			foreach ($data as $row) {
				if (!$flag) {
					$xls .= implode("\t", $row) . "\r\n";
					$flag = true;
				} else {
					$xls .= implode("\t", $row) . "\r\n";
				}
			}

			if ($export) {
				header('Content-Type: application/vnd.ms-excel');
				header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
				echo $xls;
			} else {
				echo $xls;
			}
		}

		function slugify($string)
		{
			$string = strtolower($string);
			$from 	= ['ñ', 'á', 'é', 'í', 'ó', 'ú', 'Ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', '-', '_'];
			$to 	= ['n', 'a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', ' ', ' '];

			$string = str_replace($from, $to, $string);
			$string = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
			$string = trim($string, '-');

			return $string;
		}

		function createSlug($name = false, $slug = false, $companyId)
		{
			global $db;

			$slug   = db_prepare(($slug) ? $slug : slugify($name));
			$isSlug = ncmExecute('SELECT companyId FROM company WHERE slug = ? AND companyId != ? LIMIT 1', [$slug, $companyId]);
			if ($isSlug) { // si ya existe una emrpesa con este slug
				createSlug('', $slug . '-' . mt_rand(5, 90), $companyId);
			} else {
				$db->Execute("UPDATE company SET slug = '" . $slug . "' WHERE companyId = " . $companyId);
			}
		}

		function detectDelimiter($fh)
		{
			$delimiters = ["\t", ";", "|", ","];
			$data_1 	= null;
			$data_2 	= null;
			$delimiter 	= $delimiters[0];

			foreach ($delimiters as $d) {
				$data_1 = fgetcsv($fh, 4096, $d);
				if (sizeof($data_1) > sizeof($data_2)) {
					$delimiter = $d;
					$data_2 = $data_1;
				}
				rewind($fh);
			}

			return $delimiter;
		}

		function checkAmount($table, $extra = '')
		{
			global $db, $SQLcompanyId;

			if ($extra && $extra != '') {
				$extra = $extra . ' AND ' . $SQLcompanyId;
			} else {
				$extra = $SQLcompanyId;
			}

			$result = ncmExecute('SELECT COUNT(' . $table . 'Id) as count FROM ' . $table . ' WHERE ' . $extra);

			if ($result) {
				return $result['count'];
			} else {
				return 0;
			}
		}

		function uploadImage($file, $itemImgPath, $max_size)
		{
			$options  = array('jpegQuality' => 90);
			$false    = 'false';


			if ($file['tmp_name'] && $file['error'] == 0) {

				if (is_uploaded_file($file['tmp_name'])) {

					$imgInfo  = getimagesize($file['tmp_name']);
					$type     = $imgInfo['mime'];

					if ($type == 'image/jpeg') {
						$ext = '.jpg';
					} elseif ($type == 'image/png') {
						$ext = '.png';
					} elseif ($type == 'image/gif') {
						$ext = '.gif';
					} else {
						$ext = false;
					}

					if ($file['size'] < $max_size && $ext) {
						@unlink($itemImgPath);
						move_uploaded_file($file['tmp_name'], $itemImgPath);
						$thumb = PhpThumbFactory::create($itemImgPath, $options);
						//$thumb->adaptiveResize($w, $h)->save($name,'jpg');
						$thumb->save($itemImgPath, 'jpg');
						chmod($itemImgPath, 0705);

						return $itemImgPath;
					} else {
						return 'img_size_invalid';
					}
				} else {
					return 'img_not_uploaded';
				}
			} else {
				return 'img_not_tmp_name';
			}
		}

		function checkPlanMaxReached($table, $max, $extra = '')
		{
			if (!$max) {
				return false; // null/0 = sin límite configurado
			}
			if (checkAmount($table, $extra) >= $max) {
				return true;
			} else {
				return false;
			}
		}

		function checkIfExists($str, $field, $table, $id = false, $sameCompany = true)
		{
			global $db, $SQLcompanyId;

			$chkid = '';
			$sqlc = '';

			if ($id) {
				$chkid = ' AND ' . $table . 'Id != ' . $id;
			}

			if ($sameCompany) {
				$sqlc = " AND " . $SQLcompanyId;
			}

			if ($str) {
				$obj = $db->Execute("SELECT " . $field . " FROM " . $table . " WHERE " . $field . " = '" . $str . "'" . $chkid . $sqlc);

				return validateResultFromDB($obj);
			} else {
				return false;
			}
		}

		function itemHasParent($id)
		{
			global $db;

			if ($id != '' && !empty($id)) {
				$obj 		= $db->Execute("SELECT itemParentId FROM item WHERE itemId = ?", array($id));
				$parentId 	= $obj->fields['itemParentId'];

				$obj->Close();

				if ($parentId < 1) {
					return false;
				} else {
					return $parentId;
				}
			} else {
				return false;
			}
		}

		function getItemChildren($parentId)
		{
			global $db;

			if ($parentId != '' && !empty($parentId)) {
				$result 		= $db->Execute("SELECT itemId FROM item WHERE itemParentId = ?", array($parentId));
				$out = '';
				while (!$result->EOF) {
					$out .= $obj->fields['itemParentId'] . ',';
					$result->MoveNext();
				}
				$result->Close();
				return $out;
			} else {
				return false;
			}
		}

		function getAllComapnyItemsChildren()
		{
			global $db, $SQLcompanyId;
			$result = ncmExecute("SELECT itemId, itemParentId FROM item WHERE itemParentId > 0 AND companyId = ? LIMIT 1000", [COMPANY_ID], true, true);

			$out 	= [];
			$child 	= [];

			if ($result) {
				while (!$result->EOF) {
					$fields = $result->fields;
					$pId 	= enc($fields['itemParentId']);
					$iId 	= enc($fields['itemId']);

					if (array_key_exists($pId, $out)) {
						$out[$pId] = $out[$pId] . ',' . $iId;
					} else {
						$out[$pId] = $iId;
					}

					$result->MoveNext();
				}
				$result->Close();
			}
			return $out;
		}

		function getIdOrInsert($name, $table, $insertIt = true, $extra = '')
		{
			global $db, $SQLcompanyId;
			if ($extra != '') {
				$extra = ' AND ' . $extra;
			}
			$obj = $db->Execute("SELECT " . $table . "Id FROM " . $table . " WHERE " . $table . "Name = '" . $name . "'" . $extra . " AND " . $SQLcompanyId);

			if ($obj->fields[0] != '') {
				return $obj->fields[0];
			} else {
				if ($insertIt == true) {
					$record[$table . 'Name'] 	= $name;
					$record['companyId'] 	= COMPANY_ID;

					$insert = $db->AutoExecute($table, $record, 'INSERT');
					if ($insert === true) {
						return $db->Insert_ID();
					}
				}
			}
		}

		function insertCategory($array, $parentId, $encoded = false)
		{

			if (!validity($parentId)) {
				return false;
			}

			if (!is_array($array)) {
				$array = [$array];
			}

			ncmExecute('DELETE FROM toCategory WHERE parentId = ?', [$parentId]);

			if (validity($array)) {
				foreach ($array as $key => $value) {
					$value = $encoded ? dec($value) : $value;
					if (validity($value)) {
						ncmInsert(['records' => ['parentId' => $parentId, 'categoryId' => $value], 'table' => 'toCategory']);
					}
				}
			}
		}

		function getCategories($parentId, $encoded = false)
		{
			$result = ncmExecute('SELECT * FROM toCategory WHERE parentId = ?', [$parentId]);
			$out 	= [];
			if ($result) {
				while (!$result->EOF) {
					$fields = $result->fields;
					$out[] 	= $encoded ? enc($fields['categoryId']) : $fields['categoryId'];
					$result->MoveNext();
				}
				$result->Close();
			}

			return $out;
		}

		function getPendingSalesToCharge($startDate, $endDate)
		{
			global $db;
			$result = $db->Execute("SELECT * FROM transaction WHERE (transactionType = '3') AND transactionDate BETWEEN ? AND ? " . $o . " AND " . $SQLcompanyId . " GROUP BY DATE(transactionDate) ORDER BY transactionDate DESC", array($startDate, $endDate));
		}

		function getTotalSoldContado($startDate, $endDate)
		{
			global $db;
			$result = $db->Execute("SELECT SUM(transactionTotal) as total FROM transaction WHERE (transactionType = '0') AND transactionDate BETWEEN ? AND ? " . $o . " AND " . $SQLcompanyId . " GROUP BY DATE(transactionDate) ORDER BY transactionDate DESC", array($startDate, $endDate));
			if (validateResultFromDB($result)) {
				return $result->fields['total'];
			}
		}

		function getTaxonomyIdOrInsert($name, $type, $insertIt = true, $companyId = false)
		{
			global $db;

			$companyId = iftn($companyId, COMPANY_ID);

			if (validity($name)) {
				$oname 	= db_prepare((string)$name);
				$name 	= strtolower($oname);
				$obj 	= ncmExecute("SELECT taxonomyId FROM taxonomy WHERE LOWER(taxonomyName) = ? AND taxonomyType = ? AND companyId = ? LIMIT 1", [$name, $type, $companyId]);

				if ($obj) {
					return $obj['taxonomyId'];
				} else {
					if ($insertIt) {
						$record['taxonomyName'] = $oname;
						$record['taxonomyType'] = $type;
						$record['companyId'] 	= $companyId;

						$insert = $db->AutoExecute('taxonomy', $record, 'INSERT');
						if ($insert === true) {
							return $db->Insert_ID();
						}
					}
				}
			}
		}



		function stringToUrl($str)
		{
			$z = strtolower($z);
			$z = preg_replace('/[^a-zA-Z -]+/', '', $z);
			$z = str_replace(' ', '-', $z);
			return trim($z, '-');
		}

		function addToHistory($count, $reorder, $type, $tax, $outletId, $itemId)
		{
			global $db;
			$record = array();
			$record['inventoryHistoryCount'] 	= $count;
			//$record['inventoryHistoryReorder'] 	= $reorder;
			$record['inventoryHistoryType'] 	= $type;
			//$record['taxId'] 					= $tax;
			$record['outletId'] 				= $outletId;
			$record['itemId'] 					= $itemId;
			$record['userId'] 					= USER_ID;
			$record['companyId'] 				= COMPANY_ID;

			$insert = $db->AutoExecute('inventoryHistory', $record, 'INSERT');
			if ($insert === false) {
				return false;
			} else {
				return true;
			}
		}

		function jsGlobals()
		{
			/*$dS 	= '.';
	$tsS 	= ',';
	if(THOUSAND_SEPARATOR=='dot'){
		$dS 	= ',';
		$tsS 	= '.';
	}
	$out = '<script>';
   		$out .= 'window.decimal = "'.DECIMAL.'";';
		$out .= 'window.thousandSeparator = "'.THOUSAND_SEPARATOR.'";';
		$out .= 'window.decimalSymbol = "'.$dS.'";';
		$out .= 'window.thousandSeparatorSymbol = "'.$tsS.'";';
    
    $out .= '</script>';
	return $out;*/
		}

		function isBoss()
		{
			if (ROLE_ID == 1 || ROLE_ID == 0) {
				return true;
			} else {
				return false;
			}
		}

		function accessControl($denied = [0], $chck = true)
		{

			if (!$chck) {
				header('location:/billing?viewplans=1');
				return false;
			} else {
				if (in_array(PLAN, $denied)) {
					header('location:/billing?viewplans=1');
					return false;
				}
			}
			if (ROLE_ID > 1 && COMPANY_ID == ENCOM_COMPANY_ID) {
				header('location:/main');
			}
		}

		function sanitizeForDB($str, $lnb = ' - ')
		{
			$breaks 	= array("\r\n", "\n", "\r");
			$str 		= preg_replace('/[^a-zA-Z0-9_ %\[\]\.\(\)%&-]/s', '', str_replace($breaks, $lnb, $str));
			return trim(htmlspecialchars($str));
		}

		function dateRange($first, $last, $step = '+1 day', $format = 'Y-m-d')
		{

			$dates 		= array();
			$current 	= strtotime($first);
			$last 		= strtotime($last);

			while ($current <= $last) {
				$dates[] = date($format, $current);
				$current = strtotime($step, $current);
			}

			return $dates;
		}

		function datesForGraphs($n = '', $forceStartAndEnd = false, $forceSimple = false, $persistantDate = true)
		{
			global $db;
			$mathStart 		= false;
			$mathEnd		= false;
			if (validateHttp('f')) {
				if ($_GET['f'] == 'today') {
					$lessDays 	= 0;
				} else if ($_GET['f'] == 'yt') {
					$lessDays 	= 1;
				} else if ($_GET['f'] == 'week') {
					$lessDays 	= 7;
				} else if ($_GET['f'] == 'month') {
					//$lessDays 	= 30;
					$mathStart 		= strtotime('first day of this month');
					$mathEnd		= strtotime('last day of this month');
				} else if ($_GET['f'] == 'year') {
					$begYear 	= strtotime(date("Y-01-01"));
					$passed 	= strtotime(date("Y-m-d"));
					$lessDays 	= ($passed - $begYear) / 86400;
				} else {
					$lessDays 	= 7;
				}
			} else {
				if ($n > -1) {
					$lessDays 	= $n;
				} else {
					//$lessDays 	= 7;
					$lessDays 	= 0;
				}
			}

			$allDays 	= array();

			$mathStart 	= iftn($mathStart, time() - (86400 * ($lessDays)));
			$mathEnd	= iftn($mathEnd, time());

			$monthStart	= date('m', $mathStart);
			$monthEnd	= date('m', $mathEnd);

			$yearStart	= date('Y', $mathStart);
			$yearEnd	= date('Y', $mathEnd);

			$dayStart	= date('d', $mathStart);
			$dayEnd		= date('d', $mathEnd);

			$startDate	= $yearStart . "-" . $monthStart . "-" . $dayStart . " 00:00:00";
			$endDate	= $yearEnd . "-" . $monthEnd . "-" . $dayEnd . " 23:59:59";

			if (validateHttp('from', 'post') || validateHttp('to', 'post')) {

				$startDate	= $_POST['from'];
				$endDate	= $_POST['to'];

				/*$startDate	= date('Y-m-d',strtotime($_POST['from']))." 00:00:01";
		$endDate	= date('Y-m-d',strtotime($_POST['to']))." 23:59:59";*/
			} else if (validateHttp('range', 'post')) {

				//date with hour
				$date 		= explodes(' - ', rtrim($_POST['range']));
				$startDate	= $date[0];
				$endDate	= $date[1];
			} else if (($_SESSION['user']['endDate'] && $_SESSION['user']['startDate']) && !$forceSimple) {
				$startDate	= $_SESSION['user']['startDate'];
				$endDate	= $_SESSION['user']['endDate'];
				if ($forceStartAndEnd) {
					$st = explodes(' ', $startDate);
					$en = explodes(' ', $endDate);
					$startDate	= $st[0] . " 00:00:00";
					$endDate	= $en[0] . " 23:59:59";
				}
			} else {
				$startDate	= $yearStart . "-" . $monthStart . "-" . $dayStart . " 00:00:00";
				$endDate	= $yearEnd . "-" . $monthEnd . "-" . $dayEnd . " 23:59:59";
			}

			$allDays 	= dateRange($startDate, $endDate);

			$startDate 	= validateHttp('from') ? validateHttp('from') : $startDate;
			$endDate 	= validateHttp('to') ? validateHttp('to') : $endDate;

			if ($persistantDate) {
				$_SESSION['user']['startDate'] 	= $startDate;
				$_SESSION['user']['endDate'] 	= $endDate;
			}

			if (validateHttp('hashed', 'post')) {
				dai(true);
			}

			return [db_prepare($allDays), db_prepare($startDate), db_prepare($endDate), db_prepare($lessDays)];
		}

		function iftn($if, $else = false, $then = false)
		{
			$else 		= validity($else) ? $else : '';
			$final 		= validity($then) ? $then : $if;

			return validity($if) ? $final : $else;
		}

		function ePOSLink($array)
		{

			$array['date'] = date('Y-m-d', strtotime($array['date']));

			return PUBLIC_URL . '/payment?s=' . base64_encode(json_encode($array));
		}

		function validateBool($value, $server = true, $type = 'get')
		{
			if ($server === true) { //verifico si realmente los metodos fueron pasados por post o get
				if ($_SERVER['REQUEST_METHOD'] != 'POST' && $_SERVER['REQUEST_METHOD'] != 'GET') {
					return false;
				}
			}

			if ($type == 'get') {
				if (isset($_GET[$value])) {
					return validity($_GET[$value]);
				} else {
					return false;
				}
			} else if ($type == 'post') {
				if (isset($_POST[$value])) {
					return validity($_POST[$value]);
				} else {
					return false;
				}
			} else {
				return validity($value);
			}
		}

		function validateHttp($value, $type = 'get')
		{ //alias de validateBool
			global $db;

			$result = validateBool($value, true, $type);

			if ($db && $result !== false && $result !== null) {
				$result = db_prepare($result);
			}

			unset($value, $type);
			return $result;
		}

		function validity($value, $force = false)
		{
			/*if(defined($value)){

		$cons = constant($value);
		return validity($cons,$force);

	}else{*/

			if (!isset($value)) {
				return false;
			} else {
				if (!$value || empty($value) || $value == 'undefined' || $value === null || $value == false || $value === false || $value == '' || counts($value) < 0.00001 || $value === '0000-00-00 00:00:00') {
					return false;
				} else {
					if ($force) {
						if ($force === 'email') {
							if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
								return $value;
							} else {
								return false;
							}
						} else if (myGetType($value) === $force) {
							return validity($value);
						} else {
							return false;
						}
					}
					return $value;
				}
			}

			//}
		}

		function validInArray($array, $value = '')
		{
			$out = false;
			if (is_array($array)) {
				$out = array_key_exists($value, $array) ? $array[$value] : false;
			}
			return $out;
		}

		function validity2($value, $force = false)
		{
			if (defined($value) && COMPANY_ID == 10) { // TODO: replace integer 10 with company UUID

				$cons = constant($value);
				return validity($cons, $force);
			} else {

				if (!isset($value)) {
					return false;
				} else {
					if (!$value || empty($value) || $value == 'undefined' || $value === null || $value == false || $value === false || $value == '' || counts($value) < 0.00001 || $value === '0000-00-00 00:00:00') {
						return false;
					} else {
						if ($force) {
							if ($force === 'email') {
								if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
									return $value;
								} else {
									return false;
								}
							} else if (myGetType($value) === $force) {
								return validity($value);
							} else {
								return false;
							}
						}
						return $value;
					}
				}
			}
		}

		function ncmDefined($value, $rStr = false)
		{
			return $value;
			if (defined($value) && validity($value)) {
				return $rStr ? $value : true;
			} else {
				return $rStr ? '' : false;
			}
		}

		function isJson($string)
		{
			if (validity($string, 'array')) {
				return false;
			}

			json_decode($string);
			return (json_last_error() == JSON_ERROR_NONE);
		}

		function autoFilterInputTable($input)
		{
			$flter = validateHttp('fltr');
			if ($flter) {
				echo 'autoFilterInputTable($("' . $input . '"),"' . validateHttp('fltr') . '");';
			}
		}

		function isAreturnedSale($record, $field, $type = 'transactionType')
		{
			//verifico si es una devolución entonces devuelvo en negativo
			if ($record[$type] == 6) { //devolucion
				$out = flipNumberSign(abs($record[$field] ?? 0)); //devulvo negativo
			} else {
				$out = $record[$field]; //nada pasa
			}

			return $out;
		}

		function flipNumberSign($number)
		{
			return $number * -1;
		}

		function counts($val)
		{
			if (is_numeric($val)) { //primero check is numeric para procesar numeric strings
				return $val;
			} else if (is_string($val)) {
				return strlen($val);
			} else if (is_array($val)) {
				return count($val);
			} else {
				return 0;
			}
		}

		function myGetType($var)
		{
			if (is_array($var)) return "array";
			if (is_bool($var)) return "boolean";
			if (is_float($var)) return "float";
			if (is_int($var)) return "integer";
			if (is_null($var)) return "NULL";
			if (is_numeric($var)) return "numeric";
			if (is_object($var)) return "object";
			if (is_resource($var)) return "resource";
			if (is_string($var)) return "string";
			return "unknown type";
		}

		function getNumberOfCustomers()
		{
			global $db, $SQLcompanyId;
			$query = $db->Execute("SELECT COUNT(customerId) as total
								FROM customer
								WHERE " . $SQLcompanyId);

			$total = $query->fields[0];
			//$query->Close();
			return $total;
		}



		function getNumberOfItems($inventoryNcost = false)
		{
			global $db, $SQLcompanyId;

			if ($inventoryNcost) {
				$inv = $db->Execute('SELECT SUM(inventoryCount) as inv FROM inventory WHERE ' . $SQLcompanyId);
				//$cogs = $db->Execute('SELECT SUM(itemCOGS) as cogs FROM item WHERE '.$SQLcompanyId);
				$result = $db->Execute('SELECT COUNT(itemId) as items FROM item WHERE ' . $SQLcompanyId);
				$total = array($result->fields['items'], "", $inv->fields['inv']);
			} else {
				$result = $db->Execute('SELECT COUNT(itemId) FROM item WHERE ' . $SQLcompanyId);
				$total = $result->fields[0];
			}
			/*$query = $db->Execute("SELECT COUNT(customerId) as total
								FROM customer
								WHERE ".$SQLcompanyId);*/


			//$query->Close();
			return $total;
		}

		function getItemPrice($id)
		{
			global $db, $SQLcompanyId;

			$result 	= $db->Execute('SELECT itemPrice as total FROM item WHERE itemId = ?', array($id));
			$total = 0;
			if (!$result->EOF) {
				$total 		= $result->fields['total'];
				$result->MoveNext();
			}

			return $total;
		}

		function getOperatingCost($outletId)
		{
			global $db;
			$opCost = $db->Execute("SELECT outletOperatingCosts
								FROM outlet
								WHERE outletId = " . $outletId . " 
								LIMIT 1");

			$operationCost = $opCost->fields[0];
			$opCost->Close();
			return $operationCost;
		}

		function getItemsCOGS($from, $to, $array = false, $sameday = false, $cache = false)
		{
			global $db, $ADODB_CACHE_DIR;

			$roc = str_replace(array('registerId', 'outletId', 'companyId'), array('c.registerId', 'c.outletId', 'c.companyId'), getROC());

			if ($array == false) {
				$sql = "SELECT 
					SUM((a.itemSoldCOGS*a.itemSoldUnits)) as sum
				FROM itemSold a, 
					 transaction c
				WHERE a.transactionId = c.transactionId 
				  AND c.transactionType IN(0,3)
				  AND c.transactionDate 
				  BETWEEN ? 
				  AND ?
				  " . $roc;

				if ($cache) {
					$result = $db->cacheExecute($sql, array($from, $to));
				} else {
					$result = $db->Execute($sql, array($from, $to));
				}

				//

				if (validateResultFromDB($result)) {
					return $result->fields['sum'];
				} else {
					return 0;
				}

				$result->Close();
			} else {

				if ($sameday == true) {
					$sql = "SELECT HOUR(c.transactionDate) as hour,
					SUM((b.itemSoldCOGS*b.itemSoldUnits)) as sum
					FROM itemSold b, transaction c
					WHERE c.transactionDate
					BETWEEN ?
					AND ?
					AND c.transactionType IN(0,3)
					AND b.transactionId = c.transactionId
					" . $roc . "
					GROUP BY HOUR(c.transactionDate)";

					if ($cache) {
						$result = $db->cacheExecute($sql, array($from, $to));
					} else {
						$result = $db->Execute($sql, array($from, $to));
					}
				} else {
					$sql = "SELECT c.transactionDate as date,
					SUM((b.itemSoldCOGS*b.itemSoldUnits)) as sum
					FROM itemSold b, transaction c
					WHERE c.transactionDate
					BETWEEN ?
					AND ?
					AND c.transactionType IN(0,3)
					AND c.transactionId = b.transactionId
					" . $roc . "
					GROUP BY DATE(date) 
					ORDER BY date ASC";

					if ($cache) {
						$result = $db->cacheExecute($sql, array($from, $to));
					} else {
						$result = $db->Execute($sql, array($from, $to));
					}
				}

				$group = array();
				while (!$result->EOF) {
					if ($sameday == true) {
						$group[$result->fields['hour']] = $result->fields['sum'];
					} else {
						$group[date('Y-m-d', strtotime($result->fields['date']))] = $result->fields['sum'];
					}
					$result->MoveNext();
				}
				$result->Close();
				return $group;
			}
		}



		function getTopCategories($from = false, $to = false, $limit = 25, $cache = false)
		{
			global $db;

			$roc = str_replace(['outletId', 'companyId'], ['c.outletId', 'c.companyId'], getROC(1));

			$sql = "SELECT a.itemId, 
					SUM(a.itemSoldUnits) as usold, 
					b.categoryId 
			FROM itemSold a, 
					item b, 
					transaction c 
			WHERE a.itemId = b.itemId 
			AND a.itemSoldDate 
			BETWEEN ? 
			AND ? 
			AND c.transactionType IN(0,3)
			AND a.transactionId = c.transactionId 
			" . $roc . "
			GROUP BY b.categoryId 
			ORDER BY usold DESC 
			LIMIT " . $limit;

			$result   	= ncmExecute($sql, [$from, $to], $cache, true);

			$array 		= [];
			if ($result) {
				while (!$result->EOF) {
					$array[getTaxonomyName($result->fields['categoryId'])] = $result->fields['usold'];
					$result->MoveNext();
				}
			}

			return $array;
		}

		function getTopBrands($from = false, $to = false, $limit = 25, $cache = false)
		{
			global $db;

			$roc = str_replace(['outletId', 'companyId'], ['c.outletId', 'c.companyId'], getROC(1));

			$sql = "SELECT a.itemId, 
					SUM(a.itemSoldUnits) as usold, 
					b.brandId 
			FROM itemSold a, 
					item b, 
					transaction c 
			WHERE a.itemId = b.itemId 
			AND a.itemSoldDate 
			BETWEEN ? 
			AND ? 
			AND c.transactionType IN(0,3)
			AND a.transactionId = c.transactionId 
			" . $roc . "
			GROUP BY b.brandId 
			ORDER BY usold DESC 
			LIMIT " . $limit;

			$result   	= ncmExecute($sql, [$from, $to], $cache, true);

			$array 		= [];
			if ($result) {
				while (!$result->EOF) {
					$array[getTaxonomyName($result->fields['brandId'])] = $result->fields['usold'];
					$result->MoveNext();
				}
			}

			return $array;
		}

		function getTopSoldItems($startDate, $endDate)
		{
			global $db, $SQLcompanyIdANDoutletId;
			$chart     = '';
			$list     = '';

			$result   = $db->Execute("SELECT transactionDetails FROM transaction WHERE transactionDate BETWEEN ? AND ? AND " . $SQLcompanyIdANDoutletId . " GROUP BY transactionId", array($from, $to));

			$arrProd = array();
			$n = 0;
			while (!$result->EOF) {

				$arr  = json_decode($result->fields['transactionDetails']);

				for ($i = 0; $i < counts($arr); $i++) {
					$name   	= toUTF8($arr[$i]->name);
					$count   	= $arr[$i]->count;

					if ($name != 'Descuento' && $name != 'Discount') {
						if ($arrProd[$name] > 0) {
							$arrProd[$name] = $arrProd[$name] + $count;
						} else {
							$arrProd[$name] = $count;
						}
					}
				}

				$result->MoveNext();
			}
			arsort($arrProd);
			$i = 0;
			$colors = array('#4cb6cb', '#2f3940', '#405161', '#778490', '#d7e5e8');
			foreach ($arrProd as $key => $val) {
				$list .= '<li class="list-group-item">
                  <div class="clear"> 
                    <small class="pull-right">' . $val . '</small> 
                    ' . $key . '
                  </div> 
                  
                </li>';

				$chart .= '
                {
                  value: ' . $val . ',
                  color:"' . $colors[$i] . '",
              
                  label: "' . $key . '"
                },';
				if ($i++ == 4) break;
			}

			$result->Close();
		}

		function dateStartEndTime($startDate, $endDate)
		{
			$date 	= explode(' ', $startDate)[0];
			$start 	= explode(' ', $startDate)[1];
			$end 	= explode(' ', $endDate)[1];

			$start 	= explode(':', $start)[0] . ':' . explode(':', $start)[1];
			$end 	= explode(':', $end)[0] . ':' . explode(':', $end)[1];

			return array($date, $start, $end);
		}

		function strToDate($format, $date)
		{
			$format = iftn($format, 'Y-m-d H:i:s');
			if (validity($date)) {
				return date($format, strtotime($date));
			} else {
				return '';
			}
		}

		function dateToStartAndEnd($date)
		{
			$start 	= strToDate('Y-m-d 00:00:00', $date);
			$end 	= strToDate('Y-m-d 23:59:59', $date);

			return [$start, $end];
		}

		function dateRangeLimits($from, $to, $maxDays = 0)
		{
			$earlier 	= new DateTime($from);
			$later 		= new DateTime($to);
			$days 		= $later->diff($earlier)->format("%a");

			if ($days > $maxDays) {
				return false;
			} else {
				return true;
			}
		}

		function niceDate2($datetime, $full = false)
		{

			if ($datetime && $datetime != '0000-00-00 00:00:00') {
				$now = new DateTime;
				$ago = new DateTime($datetime);
				$diff = $now->diff($ago);

				$weekends = floor($diff->d / 7);
				$diff->d -= $weekends * 7;

				$string = array(
					'y' => 'año',
					'm' => 'mes',
					// 'w' => 'semana',
					'd' => 'día',
					'h' => 'hora',
					'i' => 'minuto',
					's' => 'segundo',
				);
				foreach ($string as $k => &$v) {
					if ($diff->$k) {
						$plural = ($k == 'm') ? 'es' : 's';
						$v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? $plural : '');
					} else {
						unset($string[$k]);
					}
				}

				if (!$full) $string = array_slice($string, 0, 1);
				return $string ? 'Hace ' . implodes(', ', $string) : 'Ahora';
			} else {
				return '';
			}
		}

		function timeago($date)
		{
			$lang = 'es';
			if ($date != '0000-00-00 00:00:00') {
				$timestamp = strtotime($date);

				if ($lang == 'es') {
					$strTime = array("segundo", "minuto", "hora", "día", "mes", "año");
				} else {
					$strTime = array("second", "minute", "hour", "day", "month", "year");
				}
				$length = array("60", "60", "24", "30", "12", "10");

				$currentTime = time();
				if ($currentTime >= $timestamp) {
					$diff     = time() - $timestamp;
					for ($i = 0; $diff >= $length[$i] && $i < counts($length) - 1; $i++) {
						$diff = $diff / $length[$i];
					}

					$diff = round($diff);

					$plural = ($strTime[$i] == 'mes') ? 'es' : 's';
					$tail = ($diff > 1) ? $plural : '';
					return $diff . " " . $strTime[$i] . $tail;
				} else {
					return $date;
				}
			} else {
				return '';
			}
		}

		function niceDate($date, $hours = false, $mini = false, $literal = false)
		{
			global $dias, $meses;
			if ($date == '0000-00-00 00:00:00' || empty($date)) {
				return 'No date';
			}

			$y 			= date('Y', strtotime($date));
			$m			= date('m', strtotime($date));
			$d			= date('d', strtotime($date));
			$h			= date('H', strtotime($date));
			$mi			= date('i', strtotime($date));
			$s			= date('s', strtotime($date));
			$w			= date('w', strtotime($date));

			if (!$mini) {
				$hoursto 	= ($hours == true) ? ' a las ' . $h . ':' . $mi : '';
				$dateTo 	= $d . " " . $meses[$m - 1] . ", " . $y . $hoursto;
			} else {
				$hh 		= $h;
				$h 			= date('h', strtotime($date));
				$h 			= ($hh > 11) ? $h . ' p.m.' : $h . ' a.m.';
				$hoursto 	= ($hours == true) ? ', ' . $h : '';
				$dateTo 	= $d . "/" . $m . "/" . $y . $hoursto;
			}

			if ($literal) {
				return $dias[$w] . " " . $d . ", " . $meses[$m - 1] . ' ' . $y . $hoursto;
			} else {
				return $dateTo;
			}

			//return $dias[$w]." ".$d." de ".$meses[$m-1]." del ".$y.$hoursto;

		}

		function elegantDate($date)
		{
			global $dias;
			$dia    = $dias[date('w', strtotime($date))] . ' ' . date('d', strtotime($date));
			$mes    = $meses[date('n', strtotime($date)) - 1];
			$ano    = date('Y', strtotime($date));
			$literalDate = $dia . ' de ' . $mes . ', ' . $ano;
		}

		function getNextDatePeriod($frecuency, $times, $date = TODAY, $format = 'Y-m-d 00:00:00')
		{
			if ($frecuency == 'daily') {
				$strtotime = strtotime($date . ' +' . $times . ' day');
			} else if ($frecuency == 'weekly') {
				$strtotime = strtotime($date . ' +' . $times . ' week');
			} else if ($frecuency == 'monthly') {
				$strtotime = strtotime($date . ' +' . $times . ' month');
			} else if ($frecuency == 'quarterly') {
				$strtotime = strtotime($date . ' +' . ($times * 3) . ' month');
			} else if ($frecuency == 'yearly') {
				$strtotime = strtotime($date . ' +' . $times . ' year');
			}

			return date($format, $strtotime);
		}

		function welcomeMessage()
		{
			$hour = date('H');
			if ($hour < 12) {
				return 'Buenos días';
			} else if ($hour >= 12 && $hour <= 17) { /* Hour is from noon to 5pm (actually to 5:59 pm) */
				return 'Buenas tardes';
			} else if (date('H') > 17 && date('H') <= 24) { /* the hour is after 5pm, so it is between 6pm and midnight */
				return 'Buenas noches';
			} else { /* the hour is not between 0 and 24, so something is wrong */
				return 'Hola';
			}
		};

		//Add date 1 Month Usage: endCycle($startDate, $nMonths) 
		function cycle_end_date($cycle_start_date, $months)
		{
			$cycle_start_date_object = new DateTime($cycle_start_date);

			//Find the date interval that we will need to add to the start date
			$date_interval = find_date_interval($months, $cycle_start_date_object);

			//Add this date interval to the current date (the DateTime class handles remaining complexity like year-ends)
			$cycle_end_date_object = $cycle_start_date_object->add($date_interval);

			//Subtract (sub) 1 day from date
			$cycle_end_date_object->sub(new DateInterval('P1D'));

			//Format final date to Y-m-d
			$cycle_end_date = $cycle_end_date_object->format('Y-m-d');

			return $cycle_end_date;
		}

		//Find the date interval we need to add to start date to get end date
		function find_date_interval($n_months, DateTime $cycle_start_date_object)
		{
			//Create new datetime object identical to inputted one
			$date_of_last_day_next_month = new DateTime($cycle_start_date_object->format('Y-m-d'));

			//And modify it so it is the date of the last day of the next month
			$date_of_last_day_next_month->modify('last day of +' . $n_months . ' month');

			//If the day of inputted date (e.g. 31) is greater than last day of next month (e.g. 28)
			if ($cycle_start_date_object->format('d') > $date_of_last_day_next_month->format('d')) {
				//Return a DateInterval object equal to the number of days difference
				return $cycle_start_date_object->diff($date_of_last_day_next_month);
				//Otherwise the date is easy and we can just add a month to it
			} else {
				//Return a DateInterval object equal to a period (P) of 1 month (M)
				return new DateInterval('P' . $n_months . 'M');
			}
		}


		//

		function getCompanyGeneratedIncome($from = false, $to = false, $outlet = false, $companyId = false)
		{
			global $db, $SQLcompanyId;

			$outlet = ($outlet) ? ' AND outletId = ' . $outlet : '';

			if ($from && $to) {
				$result   = $db->Execute("SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount FROM transaction WHERE transactionDate BETWEEN ? AND ? AND (transactionType = '0' OR transactionType = '3') AND " . $SQLcompanyId . $outlet, array($from, $to));
			} else {
				$result   = $db->Execute("SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount FROM transaction WHERE (transactionType = '0' OR transactionType = '3') AND " . $SQLcompanyId . $outlet);
			}

			$total 		= $result->fields['total'];
			$discount 	= $result->fields['discount'];
			$out 		= $total - $discount;

			return $out;

			$result->Close();
		}

		function checkInventory()
		{
			return 0;

			global $db;
			$roc 		= getROC(1);
			$result   	= $db->Execute("SELECT inventoryId 
								FROM inventory 
								WHERE inventoryCount <= inventoryReorder
								AND inventoryReorder > 0
								" . $roc);
			$count 		= $result->RecordCount();
			return $count;
		}

		function getSalesByPayment($from, $to, $regId = false, $outId = false, $cache = false, $ungroup = false)
		{
			global $db;
			//selecciono todas las transacciones filtradas por la fecha de apertura de caja y cierrre si es que tiene, es decir: fecha transacción && fecha apertura caja
			//hago un loop para agrupar todos los metodos de pago
			if (validity($from)) {

				$roc 	= db_prepare(getROC(iftn($regId, 1), $outId, false));
				$from 	= db_prepare($from);
				$to 	= db_prepare($to);

				if (validity($to)) {
					$date 		= 'transactionDate >= ? AND transactionDate <= ?';
					$arrQuery 	= [$from, $to];
				} else {
					$date 		= 'transactionDate > ?';
					$arrQuery 	= [$from];
				}

				$sql 	= 	"SELECT transactionPaymentType, transactionType, transactionParentId, tags
					FROM transaction USE INDEX(transactionType,transactionDate)
					WHERE  " . $date . "
					AND transactionType IN (0,5)" .
					$roc;

				$result 	= ncmExecute($sql, $arrQuery, false, true);

				if ($result) {
					$group = [];
					while (!$result->EOF) {
						$fields 	= $result->fields;
						$new 		= json_decode($fields['transactionPaymentType'], true);

						if ($fields['transactionType'] == 5) {
							$ignore = isParentInternalSale($fields['transactionParentId']);
						} else {
							$tags 	= json_decode(!is_null($fields['tags']) ? $fields['tags'] : "", true);
							$ignore = isInternalSale($tags);
						}

						if (validity($new) && !$ignore) {
							$group 	= groupByPaymentMethod($new, $group); //en cada loop concatena un nuevo metodo y reemplaza el anterior
						}

						$result->MoveNext();
					}

					$result->Close();
					return $group;
				} else {
					return [];
				}
			} else {
				return [];
			}
		}

		function getPaymentTypes($from, $to, $type = '0,5')
		{
			global $db, $SQLcompanyId, $o;

			$result 	= $db->Execute("SELECT transactionPaymentType 
								FROM transaction 
								WHERE transactionType IN (" . $type . ") 
									AND transactionDate 
								BETWEEN ? 
									AND ?
									" . $o . "
									AND " . $SQLcompanyId, array($from, $to));

			$group = array();

			while (!$result->EOF) {
				$new 	= json_decode($result->fields['transactionPaymentType'], true);
				$group 	= groupByPaymentMethod($new, $group);

				$result->MoveNext();
			}
			return $group;

			$result->Close();
		}

		function satisfactionToPercent($uno = 0, $dos = 0, $tres = 0)
		{
			//$tres 	= 0;
			//$dos 	= 20;
			//$uno 	= 0;

			$tress 	= 3 * $tres;
			$doss 	= 2 * $dos;
			$unos 	= $uno;

			$total 	= 3 * ($tres + $dos + $uno);
			$voted 	= ($tress + $doss + $unos);

			$percent = round(divider(($voted * 100), $total, true));

			return $percent;
		}

		function getNonAddingToSales($array = [])
		{
			global $_fullSettings;
			//obtengo cantidades de ventas que no deben sumar a la venta total, gift card, puntos, credito interno
			$startDate 			= $array['startDate'];
			$endDate 			= $array['endDate'];
			$backThen 			= $array['backThen'];
			$roc 				= iftn($array['roc'], getROC(1));
			$cache 				= $array['cache'] ?? false;

			$totalGiftcards 	= 0;
			$totalCredit 		= 0;
			$totalPoints 		= 0;

			$totalGiftcardsB 	= 0;
			$totalCreditB 		= 0;
			$totalPointsB 		= 0;

			$out 				= [];

			$pmnts    			= getSalesByPayment($startDate, $endDate, $roc, false, $cache);
			foreach ($pmnts as $methd) {
				if ($methd['type'] == 'giftcard') {
					$totalGiftcards += $methd['price'];
				} else if ($methd['type'] == 'storeCredit') {
					$totalCredit += $methd['price'];
				} else if ($methd['type'] == 'points') {
					$totalPoints += $methd['price'];
				}
			}

			$totalInternal = 0;

			$totalInternal = lessInternalTotals($roc, $startDate, $endDate);


			$out['total'] 			= $totalGiftcards + $totalCredit + $totalPoints + $totalInternal['total'];
			$out['totalGiftCards'] 	= $totalGiftcards;
			$out['totalGiftCredit']	= $totalCredit;
			$out['totalPoints']		= $totalPoints;

			if ($backThen) {
				list($startDateBack, $endDateBack) 	= getPreviousPeriod($startDate, $endDate);
				$pmntsB    							= getSalesByPayment($startDateBack, $endDateBack, $roc, false, $cache);

				foreach ($pmntsB as $methdB) {
					if ($methdB['type'] == 'giftcard') {
						$totalGiftcardsB += $methdB['price'];
					} else if ($methdB['type'] == 'storeCredit') {
						$totalCreditB += $methdB['price'];
					} else if ($methdB['type'] == 'points') {
						$totalPointsB += $methdB['price'];
					}
				}


				$totalInternalB = lessInternalTotals($roc, $startDateBack, $endDateBack);


				$out['totalB'] 				= $totalGiftcardsB + $totalCreditB + $totalPointsB + $totalInternalB['total'];
				$out['totalGiftCardsB'] 	= $totalGiftcardsB;
				$out['totalGiftCreditB']	= $totalCreditB;
				$out['totalPointsB']		= $totalPointsB;
			}

			return $out;
		}

		function getSalesByDrawerPeriod($from, $to, $regId = false, $outId = false, $clearCache = false)
		{
			global $db;
			//selecciono todas las transacciones filtradas por la fecha de apertura de caja y cierrre si es que tiene, es decir: fecha transacción && fecha apertura caja
			//hago un loop para agrupar todos los metodos de pago
			if (validity($from)) {
				if ($to == '0000-00-00 00:00:00' || !validity($to, 'string')) {
					$to = false;
				}

				$roc 					= getROC(1);
				$from 				= db_prepare($from);
				$to 					= db_prepare($to);

				if (!validity($to)) {
					$date 	= 'transactionDate > "' . $from . '"';
				} else {
					$date 	= 'transactionDate BETWEEN "' . $from . '" AND "' . $to . '"';
				}

				$query 				= "	SELECT SUM(transactionTotal) as total
								FROM transaction 
								WHERE  " . $date . "
								AND transactionType IN (0,5,6)" .
					$roc;

				$result 					= ncmExecute($query);
				$lessInternals 		= lessInternalTotals($roc, $from, $to, '0,5,6');

				$total 						= $result['total'] - $lessInternals['total'];

				if ($result) {
					return $total;
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		function getAllSalesByDrawerPeriod($from, $to, $clearCache = false)
		{
			$roc 				= getROC(1);

			$query 				= "SELECT transactionTotal as total, transactionDiscount as discount, registerId, transactionDate, transactionParentId, transactionType, tags
							FROM transaction 
							WHERE transactionDate 
							BETWEEN '" . db_prepare($from) . "' 
							AND '" . db_prepare($to) . "'
							AND transactionType IN (0,5,6)" . $roc;

			$result 			= ncmExecute($query, [], false, true);
			$a 					= [];

			if ($result) {
				while (!$result->EOF) {
					$fields = $result->fields;

					if ($fields['transactionType'] == 5) {
						$ignore = isParentInternalSale($fields['transactionParentId']);
					} else {
						$tags 	= json_decode($fields['tags'], true);
						$ignore = isInternalSale($tags);
					}

					if (!$ignore) {
						$a[$fields['registerId']][] = 	[
							'date' 	=>	$fields['transactionDate'],
							'total' =>	$fields['total'] - $fields['discount']
						];
					}

					$result->MoveNext();
				}
				$result->Close();

				return $a;
			} else {
				return false;
			}
		}

		function sumTotalBetweenDateRanges($array, $register, $from, $to)
		{
			$totalOut = 0;
			if ($to == '0000-00-00 00:00:00' || !validity($to, 'string')) {
				$to = TODAY;
			}

			if (validity($array, 'array')) {
				foreach ($array as $thisRegister => $value) {
					if ($thisRegister == $register) {
						foreach ($value as $index => $data) {
							if ($data['date'] > $from && $data['date'] < $to) {
								$totalOut += $data['total'];
							}
						}
					}
				}
			}

			return $totalOut;
		}

		function groupByPaymentMethod($new, $old)
		{
			if (!$new) {
				return [];
			}
			$nuPrice = 0;
			$nuTotal = 0;

			foreach ($new as $nu) {

				if (!array_key_exists('total', $nu) || !array_key_exists('price', $nu)) {
					continue;
				}

				$nuPrice 	= iftn(abs($nu['price'] ?? 0), 0); // lo que se ingresa en el visor d epago
				$nuTotal 	= iftn(abs($nu['total'] ?? 0), 0); // saldo a pagar

				$nu['type']	= getPaymentMethodDecoded($nu['type'], true);

				if ($nuPrice > $nuTotal) {
					$nu['price'] 	= $nuTotal;
					$nuPrice 			= (float)abs($nu['price'] ?? 0);
				}

				$match = false;
				foreach ($old as $index => $ol) {
					if ($nu['type'] === $ol['type']) {
						$old[$index]['price'] = (float)$ol['price'] + (float)$nuPrice;
						$match = true;
					}
				}

				if (!$match) {
					unset($nu['extra']);
					unset($nu['total']);
					array_push($old, $nu);
				}
			}

			return $old;
		}

		function getPaymentMethodName($id, $decode = false)
		{
			global $db;
			if ($id == 'cash') {
				$out = 'Efectivo';
			} else if ($id == 'pix') {
				$out = 'PIX';
			} else if ($id == 'creditcard') {
				$out = 'T. Crédito';
			} else if ($id == 'debitcard') {
				$out = 'T. Débito';
			} else if ($id == 'check') {
				$out = 'Cheque';
			} else if ($id == 'giftcard') {
				$out = 'Gift Card';
			} else if ($id == 'inCredit' || $id == 'storeCredit') {
				$out = 'Crédito Interno';
			} else if ($id == 'points') {
				$out = 'Loyalty';
			} else if ($id == 'QRPayment' || $id == 'VPOS' || $id == 'ePOS' || $id == 'epos' || $id == 'bancardQROnline') {
				$out = 'ePOS';
			} else if ($id == 'ePOSCard') {
				$out = 'ePOS Card';
			} else {
				if ($decode) {
					$id = dec($id);
				}

				$result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);

				if ($result) {
					$out = $result['taxonomyName'];
				} else {
					if (!$decode) {
						$id = dec($id);
					}
					$result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
					if ($result) {
						$out = $result['taxonomyName'];
					} else {
						$out = '';
					}
				}
			}

			return iftn($out, '-');
		}

		function getPaymentMethodStandardArray()
		{
			return 	[
				'cash' 				=> 'Efectivo',
				'pix' 				=> 'PIX',
				'creditcard' 	=> 'T. Crédito',
				'debitcard' 	=> 'T. Débito',
				'check' 			=> 'Cheque',
				'points' 			=> 'Loyalty',
				'giftcard' 		=> 'Gift Card',
				'inCredit' 		=> 'Crédito Interno',
				'storeCredit'	=> 'Crédito Interno',
				'ePOS'				=> 'ePOS',
				'ePOSCard' 		=> 'ePOS Card',
				'QRPayment' 	=> 'ePOS',
				'bancardQROnline' 	=> 'ePOS',
			];
		}

		function getPaymentMethodDecoded($encoded, $returnencoded = false)
		{
			$array = [
				'cash',
				'creditcard',
				'debitcard',
				'check',
				'points',
				'giftcard',
				'inCredit',
				'storeCredit',
				'ePOS',
				'ePOSCard',
				'QRPayment',
				'VPOS',
				'vPayment',
				'bancardQROnline',
				'pix'
			];

			if (!in_array($encoded, $array)) {
				$decoded = dec($encoded);
				if (!$decoded) { //quiere decir que esta decoded
					if ($returnencoded) {
						return enc($encoded);
					} else {
						return $encoded;
					}
				} else {
					//verifico si este codigo de pago existe en la empresa por si pudo decodificar de onda
					$exists = ncmExecute('SELECT taxonomyId FROM taxonomy WHERE taxonomyId = ? AND taxonomyType = ? AND companyId = ? LIMIT 1', [$decoded, 'paymentMethod', COMPANY_ID]);
					if ($exists) {
						if ($returnencoded) {
							return $encoded;
						} else {
							return $decoded;
						}
					} else {
						//quiere decir que pase un ID que se pudo decodificar de onda pero no es un medio de pago real, entonces lo devuelvo
						if ($returnencoded) {
							return enc($encoded);
						} else {
							return $encoded;
						}
					}
				}
			} else {
				return $encoded;
			}
		}

		function getAllPaymentMethodsArray($decoded = false)
		{
			$pTypes = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'paymentMethod' AND (companyId = ? OR companyId IS NULL) LIMIT 100", [COMPANY_ID], false, true, true);
			$tPtypes = [];

			if ($pTypes) {
				foreach ($pTypes as $value) {
					$kay = enc($value['taxonomyId']);

					if ($decoded) {
						$kay = $value['taxonomyId'];
					}

					$tPtypes[$kay] = $value['taxonomyName'];
				}
			}

			//$tPtypes = array_merge($tPtypes,getPaymentMethodStandardArray());
			$tPtypes = $tPtypes + getPaymentMethodStandardArray();

			return $tPtypes;
		}

		function getPaymentMethodsInArray($json)
		{
			$array 			= json_decode(iftn($json, '{}'), true);
			$usedPayments 	= [];
			if (validity($array)) {
				foreach ($array as $key => $value) {
					$usedPayments[] = [
						'type' 	=> is_numeric($value['type']) ? enc($value['type']) : $value['type'],
						'name' 	=> getPaymentMethodName($value['type']),
						'price' => (float)$value['price'],
						'total' => (float) ($value['total'] ?? 0),
						'extra' => $value['extra'] ?? 0
					];
				}
			}

			return $usedPayments;
		}

		function returnInventory($itemId, $count, $outlet, $source = 'unknown_panel')
		{
			global $db;
			//por el momento no inserta su fecha de vencimiento ni proveedor
			// por logica no sabemos esos datos porque si lleva de varios batches no sabremos cuales está devolviendo y a que batch pertenece, solo sabemos el ID del producto, la cantidad, sucursal y empresa
			if (validity($count) && validity($itemId) && validity($outlet)) {
				$db->Execute('INSERT INTO inventory (inventoryCount, itemId, inventorySource, companyId, outletId) VALUES (' . $count . ',' . $itemId . ',"' . $source . '",' . COMPANY_ID . ',' . $outlet . ')');
			}
		}

		function createInventory($id, $outletId = OUTLET_ID, $source = 'manual')
		{
			global $db, $plansValues;

			if ($outletId > 1) {
				createSingleInventory($id, $outletId, $source);
			} else {
				$outlets = getAllOutlets();
				foreach ($outlets as $outVal) {
					createSingleInventory($id, $outVal['id'], $source);
				}
			}
		}

		function getItemStock($itemId, $outlet = false, $wasted = false)
		{
			if (!validity($itemId)) {
				return false;
			}

			if ($outlet > 0) { //outlet es para forzar a traer items de esa sucursal sin importar el ROC
				$roc 	= ' AND outletId = ' . $outlet;
			} else {
				$roc 	= getROC(1);
			}

			$result = ncmExecute('SELECT * FROM stock WHERE itemId = ? ' . $roc . ' ORDER BY stockId DESC LIMIT 1', [$itemId]);

			return $result;
		}

		function getItemMainStock($itemId, $outletId)
		{

			$inventory 	= getItemStock($itemId, $outletId);
			$count 			= $inventory['stockOnHand'];
			$depo 			= ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC", [$outletId], false, true);

			if ($depo) {
				$dTotal = 0;
				while (!$depo->EOF) {
					$dCount 	= 0;
					$depCount 	= ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1', [$depo->fields['taxonomyId'], $itemId]);

					if ($depCount) {
						$dCount = $depCount['toLocationCount'];
					}

					$dTotal += $dCount;

					$count 	= $count - $dTotal;

					$depo->MoveNext();
				}
			}
			return $count;
		}


		function getItemLocationsStock($itemId, $outletId)
		{
			$depo 		= ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC", [$outletId], false, true);
			$depoA 		= [];

			if ($depo) {
				$dTotal = 0;
				while (!$depo->EOF) {
					$dCount 	= 0;
					$depCount 	= ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1', [$depo->fields['taxonomyId'], $itemId]);

					$depoA[$depo->fields['taxonomyId']] = $depCount['toLocationCount'];

					$depo->MoveNext();
				}
			}
			return $depoA;
		}

		function getItemLastStockOnHand($itemId, $outlet = false, $cache = false)
		{
			if (!validity($itemId)) {
				return false;
			}

			if ($outlet > 0) { //outlet es para forzar a traer items de esa sucursal sin importar el ROC
				$roc 	= ' AND outletId = ' . $outlet;
			} else {
				$roc 	= getROC(1);
			}

			$result = ncmExecute('SELECT * FROM stock WHERE itemId = ? ' . $roc . ' AND stockOnHandCOGS > 0 ORDER BY stockId DESC LIMIT 1', [$itemId], $cache);

			return ($result) ? $result['stockOnHandCOGS'] : 0;
		}

		function getAllItemStock($outlet = false, $all = false, $in = false, $cache = false, $date = false)
		{
			global $db, $allOutletsArray, $startDate, $endDate;
			$ins = '';

			if ($in !== false) {
				$ins = ' AND itemId IN (' . implode(',', $in) . ')';
			}

			$dateFromDateTo = '';
			if ($date !== false) {
				$dateFromDateTo = "AND stockDate BETWEEN '$startDate' AND '$endDate' ";
			}


			$sql 	= '	SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs, t1.stockCOGS as cogss
						FROM stock t1
						JOIN
						(
						  SELECT max(stockId) AS stockId
						  FROM stock
						  WHERE outletId = ?
						  ' . $ins . '
						  ' . $dateFromDateTo . '
						  GROUP BY itemId
						) t2 ON t1.stockId = t2.stockId AND t1.outletId = ?';

			if ($all) {

				$result = [];

				foreach ($allOutletsArray as $outlet => $val) {

					$item = ncmExecute($sql, [$outlet, $outlet], $cache, true, true);
					if ($item) {
						foreach ($item as $itemId => $values) {
							if (empty($result[$itemId]['onHand'])) {
								$result[$itemId]['onHand'] = 0;
							}
							$result[$itemId]['itemId'] 	= $values['itemId'];
							$result[$itemId]['onHand'] += $values['onHand'] ?? 0;
							$result[$itemId]['cogs'] 		= iftn($values['cogs'], $values['cogss']);
						}
					}
				}
			} else {
				$outlet = iftn($outlet, OUTLET_ID);

				$result = ncmExecute($sql, [$outlet, $outlet], $cache, true, true);
			}

			if (validity($result)) {
				return $result;
			} else {
				return [];
			}
		}

		function manageStock($ops)
		{
			global $db;

			$itemId 				= $ops['itemId'];
			$source 				= iftn($ops['source'] ?? "adjustment", 'adjustment');
			$count 					= $ops['count'];
			$type 					= $ops['type'] ?? "+";
			$COGS					= $ops['cogs'] ?? NULL;
			$user 					= iftn($ops['userId'] ?? USER_ID, USER_ID);
			$transaction			= $ops['transactionId'] ?? NULL;
			$supplier				= $ops['supplierId'] ?? NULL;
			$outlet					= $ops['outletId'] ?? NULL;
			$location				= $ops['locationId'] ?? NULL;
			$note					= $ops['note'] ?? "";
			$date					= iftn($ops['date'] ?? TODAY, TODAY);
			$company				= iftn($ops['companyId'] ?? COMPANY_ID, COMPANY_ID);

			/*if(!validity($count)){
		return false;
	}*/

			//verifico si el item tiene control de stock y no es un servicio
			$isStockeable 		= ncmExecute('SELECT itemTrackInventory FROM item WHERE itemStatus = 1 AND itemId = ? AND companyId = ? LIMIT 1', [$itemId, COMPANY_ID]);

			if (!$isStockeable || $isStockeable['itemTrackInventory'] < 1) {
				return false;
			}

			$stock 				= getItemStock($itemId, $outlet);

			$oldStock			= is_numeric($stock['stockOnHand'] ?? false) ? $stock['stockOnHand'] : 0;
			$oldACOGS			= is_numeric($stock['stockOnHandCOGS'] ?? false) ? $stock['stockOnHandCOGS'] : 0; //getItemLastStockOnHand($itemId,$outlet);

			if (!validity($COGS)) {
				$COGS = $stock['stockCOGS'];
			}

			if ($type == '+') {
				$newOnHand 			= $oldStock + $count; //obtengo nueva cantidad en stock

				if ($count == 0) { //esto sirve para poder añadir precio de costo sin modificar stock
					$newCOGS 		= $COGS;
					$newTotalCOGS 	= $newCOGS;
				} else {
					if ($oldStock < 0) { //si el stock viejo es menor a 0 el costo se calcula con el onhand
						//Es así para que pueda aumentar el negativo y comenzar de cero con el positivo
						$newCOGS 		= $COGS * abs($newOnHand ?? 0);
					} else { //si no se calcula con la cantidad añadida
						$newCOGS 		= $COGS * $count;
					}

					$newTotalCOGS 		= (($oldACOGS * $oldStock) + $newCOGS);
					$newTotalCOGS 		= divider($newTotalCOGS, $newOnHand, true);
				}
			} else { //si es venta o quito stock
				$newOnHand 			= $oldStock - $count;
				$COGS 				= $oldACOGS;

				if ($newOnHand <= 0) {
					$newTotalCOGS 		= 0;
				} else {
					$newTotalCOGS 		= $oldACOGS;
				}
			}

			$row['stockSource']   	= $source;
			$row['stockNote']   	= $note;
			$row['stockCount']   	= $type . $count;
			$row['stockCOGS']   	= $COGS;
			$row['stockOnHand']   	= $newOnHand;
			$row['stockOnHandCOGS'] = $newTotalCOGS;
			$row['itemId'] 			= $itemId;
			$row['transactionId']	= iftn($transaction, NULL);
			$row['userId'] 			= $user;
			$row['supplierId'] 		= iftn($supplier, NULL);
			$row['outletId'] 		= $outlet;
			$row['locationId'] 		= $location;
			$row['companyId']		= $company;

			if ($date) {
				$row['stockDate']	= $date;
			}

			$insert = $db->AutoExecute('stock', $row, 'INSERT');

			if ($insert !== true) {
				return false;
			} else {
				if ($location) {
					$isLocation = ncmExecute('SELECT toLocationId FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1', [$location, $itemId]);
					if ($isLocation) {
						$db->Execute('UPDATE toLocation SET toLocationCount = toLocationCount' . $type . $count . ' WHERE toLocationId = ' . $isLocation['toLocationId']);
					} else {
						$db->AutoExecute('toLocation', ['locationId' => $location, 'toLocationCount' => $type . $count, 'itemId' => $itemId], 'INSERT');
					}
				}

				ncmUpdate(['records' => ['updated_at' => TODAY], 'table' => 'item', 'where' => 'itemId = ' . $itemId . ' AND companyId = ' . $company]);

				updateLastTimeEdit($company, 'item');

				return $row;
			}
		}

		function kindOfItem($result)
		{
			$type 			= $result->fields['itemType'];
			$parent 		= $result->fields['itemIsParent'];
			$compound 	= getCompoundsArray($result->fields['itemId']);
			$production	= $result->fields['itemProduction'];
			$return 		= false;

			if ($type == 'discount') {
				$return = 'discount';
			} else if ($type == 'product') {
				$return = 'product';
			} else if ($type == 'combo') {
				$return = 'combo';
			}

			//Es producción directa
			if (($type == 'product' && $parent == 0 && (!$compound && !$production)) || $production) {
			}
		}

		function stockTriggerManager($id, $count, $outletId = OUTLET_ID)
		{
			global $db;

			$db->Execute('DELETE FROM stockTrigger WHERE itemId = ? AND outletId = ?', [$id, $outletId]);

			if ($count > 0) {
				$trigger['itemId'] 				= $id;
				$trigger['stockTriggerCount'] 	= $count;
				$trigger['outletId'] 			= $outletId;

				$db->AutoExecute('stockTrigger', $trigger, 'INSERT');
			}
		}

		function createSingleInventory($id, $outletId = OUTLET_ID, $source = 'manual', $units = 1)
		{
			global $db, $plansValues;
			if ($plansValues[PLAN]['inventory']) {
				$hasInv = ncmExecute('SELECT inventoryId FROM inventory WHERE itemId = ? AND inventoryType = 0 AND inventoryCount > 0 AND outletId = ? LIMIT 1', [$id, $outletId]);

				if (!$hasInv) {
					$addInv['inventoryCount'] 	= $units;
					$addInv['inventorySource'] 	= $source;
					$addInv['companyId'] 		= COMPANY_ID;
					$addInv['outletId'] 		= $outletId;
					$addInv['itemId'] 			= $id;

					$db->AutoExecute('inventory', $addInv, 'INSERT');
				}
			}
		}

		function getProductionCapacity($compounds, $inventory, $waste = false)
		{

			//obtengo la capacidad de produccion de un articulo basandome en el inventario de sus compuestos
			//recibo los compuestos y un array de inventario
			//por cada compuesto sumo el total del inventario y divido por la cantidad que necesito
			//el resultado es la cantidad de unidades que puedo hacer con ese compuesto ej: 5,2,8
			//entonces guardo cada cantidad de produccion en un array y luego devuelvo el menor valor, ej: 2 (es la máxima cantidad que puedo producir)
			if (!$waste) {
				$waste = [];
			}

			if (validity($compounds, 'array') && $inventory) {
				$canMake 		= 0;
				$eachAmount = [];

				foreach ($compounds as $val) {
					$need 				= $val['toCompoundQty'];
					$wasteP 			= $waste[$val['compoundId']] ?? 0;

					if ($wasteP > 0) {
						$need = getNeedWithWaste($need, $wasteP);
					}

					if ($need > 0) { //ignoro las cantidades en 0 para que no divida en 0

						$have 			= $inventory[$val['compoundId']]['onHand'] ?? 0;

						$divi 			= divider($need, $have);
						$eachAmount[] 	= round($divi, 3); //limito los decimales a 3
					}
				}

				return ($eachAmount) ? min($eachAmount) : 0; //obtengo el menor valor del array
			} else {
				return 0;
			}
		}

		function checkIfCanProduce($array, $units, $inventory = false)
		{
			global $db;

			$canProduce = getProductionCapacity($array, $inventory);

			if ($canProduce < $units) {
				dai('noinventory');
			}
		}

		function produce($itemId, $units, $outletId, $expires, $order = false)
		{
			global $db, $SQLcompanyId;

			$max 								= 40; //pongo un limite de compuestos por producto producido para que no se haga un looop con queries que pueda cogar el server
			$activityCompunds 	= [];
			$newInv 						= [];
			$produce 						= [];
			$wasteValue 				= 0;
			$totalCOGS 					= 0;

			list($outletId, $locationId) = outletOrLocation($outletId);

			//$inventoryArray 	= getAllIndividualInventory();

			if (validity($itemId) && validity($units)) {
				$array  		= getCompoundsArray($itemId);

				$waste 			= getAllWasteValue();

				if (counts($array) > $max) {
					dai('limit');
				}

				if (!validity($array, 'array')) {
					return false;
				}

				foreach ($array as $arr) { //loop de cada compuesto
					$count    		= $units * floatval($arr['toCompoundQty']);
					$id     			= $arr['compoundId'];
					$wasteP 			= $waste[$id];
					if ($wasteP > 0) {
						$count 			= getNeedWithWaste($count, $wasteP);
					}
					$thisItemCogs = 0;
					$itmData 			= ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);

					if (!validity($order)) { //si es solo una orden de impresión no afecto el inventario

						$ops 			  			= [];
						//inserto
						$ops['itemId']    = $id;
						$ops['outletId']  = $outletId;
						$ops['locationId'] = $itmData['locationId'];
						$ops['count']     = $count;
						$ops['type']      = '-';
						$ops['source']    = 'production';
						$managed 		  		= manageStock($ops);
						$thisItemCogs 	  = $managed['stockOnHandCOGS'];

						$totalCOGS 		+= $thisItemCogs * $count;
					}

					$activityCompunds[enc($id)] = ['units' => $count, 'cogs' => $thisItemCogs]; //activity log
				}

				//Nuevo inventario es INSERT en cada producción
				$newInv['inventoryCount']       		= $units;
				$newInv['inventoryDate']       			= TODAY;
				$newInv['inventoryCOGS']        		= divider($totalCOGS, $units, true);
				$newInv['inventoryExpirationDate']  	= $expires;
				$newInv['inventorySource']				= 'production';
				$newInv['outletId']           			= $outletId;
				$newInv['companyId']          			= COMPANY_ID;
				$newInv['itemId']             			= $itemId;
				$newInv['supplierId']           		= COMPANY_ID; //supplier es la misma empresa que produjo

				if (!validity($order)) { //si es solo una orden de impresión no afecto el inventario
					$ops 			  = [];
					//inserto
					$ops['itemId']    = $itemId;
					$ops['outletId']  = $outletId;
					$ops['locationId'] = $locationId;
					$ops['count']     = $units;
					$ops['cogs']	  = divider($totalCOGS, $units, true);
					$ops['type']      = '+';
					$ops['source']    = 'production';
					manageStock($ops);
				}

				$produce['productionCount']       		= $units;
				$produce['productionDate']       		= TODAY;
				$produce['productionCOGS']        		= $totalCOGS;
				//$produce['companyExpirationDate']  	= $expires;
				$produce['productionRecipe']			= json_encode($activityCompunds);
				$produce['productionType']				= (!validity($order)) ? 1 : 2;
				//$produce['productionWasteValue']		= $wasteValue;
				$produce['itemId']             			= $itemId;
				$produce['userId']           			= USER_ID;
				$produce['outletId']           			= $outletId;
				$produce['companyId']          			= COMPANY_ID;

				$produced = $db->AutoExecute('production', $produce, 'INSERT');

				if ($produced !== true) {
					$producedId = false;
				} else {
					$producedId = $db->Insert_ID();
				}

				return $producedId;
			}
		}

		function updateRowLastUpdate($table, $where)
		{
			global $db, $compId;
			$record 				= array();
			$record['updated_at'] 	= TODAY;
			$db->AutoExecute($table, $record, 'UPDATE', $where);
		}

		function trackEvent($action, $array)
		{
			global $mp;
			$array['company_id'] 	= enc(COMPANY_ID);
			$array['outlet_id'] 	= enc(OUTLET_ID);
			$array['user_id'] 		= enc(USER_ID);
			$array['date'] 			= date("Y-m-d h:i:s");
			//$mp->track($action, $array);
		}



		function sumChildrenInventory($parentId)
		{
			global $db, $SQLcompanyId;
			$resultInv = $db->Execute('SELECT SUM(a.inventoryCount) as suma FROM a.inventory, b.item WHERE b.itemParentId = ' . $parentId . ' AND a.itemId = b.itemId AND a.outletId = ' . OUTLET_ID);
			return $resultInv->fields['suma'];
		}

		function switchIn($name, $status = false, $class = '', $val = 1)
		{
			$state = '';
			$class = '' . $class;
			$color = '';
			if ($status) {
				$state = 'checked';
				$class = 'selected ' . $class;
			}

			return '<div class="switch-select ' . $color . ' switch ' . $class . '" id="' . $name . '">
		        <div class="swinner">
		        	<input type="checkbox" name="' . $name . '" class="' . $name . 'Class" value="' . $val . '" ' . $state . ' />
		        </div>
		    </div>';
		}

		function reportsTitle($title, $hideChart = false, $tutorial = false, $showLoading = false, $hideTimePckr = false, $pickerReplace = '')
		{
			global $startDate, $endDate;
			$tutorialink = '';
			if ($tutorial) {
				$tutorialink = '<a href="' . $tutorial . '" class="m-l-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="Visitar el centro de ayuda"><i class="material-icons text-info m-b-xs">help_outline</i></a>';
			}
?> <div class="col-xs-12 no-padder">
		<?= headerPrint(); ?>
		<div class="col-xs-12 no-padder">
			<div class="col-sm-5 col-xs-12 m-t-sm m-b">
				<?php
				if (!$hideTimePckr) {
				?>
					<form action="" class="col-md-9 col-xs-12 no-padder" method="post" id="manualDate" name="manualDate">
						<label class="visible-print text-u-c font-bold text-xs m-b-n-xs">Fecha</label>
						<input type="text" id="customDateR" class="form-control no-border bg-white pointer font-bold text-center rounded needsclick" name="range" value="" />
					</form>
				<?php
				} else {
					echo $pickerReplace;
				}
				?>
			</div>

			<div class="col-sm-7 col-xs-12 m-t-sm m-b">
				<div class="pull-right">
					<h1 class="no-padder hidden-print m-n font-bold"><?= '<span id="pageTitle">' . $title . '</span>' . $tutorialink ?></h1>
					<h3 class="no-padder visible-print m-n font-bold"><?= $title ?></h3>
				</div>
			</div>
		</div>
		<?php
			if (!$hideChart) {
		?>

			<div class="col-xs-12 no-padder m-b hidden-print">
				<?php
				if ($showLoading) {
					echo placeHolderLoader('chart');
				}
				?>
				<canvas id="myChart" height="350" style="width:100%; height:350px;" class="<?= ($showLoading) ? 'hidden' : '' ?>"></canvas>
			</div>
		<?php
			}
		?>
	</div>
<?php
		}

		function getCustomersRate($startDate, $endDate)
		{

			$customerStart 		= 0;
			$customerEnd 		= 0;
			$acquired 			= 0;
			$roc 				= str_replace(['companyId', 'outletId'], ['c.companyId', 'c.outletId'], getROC(1));

			list($backStart, $backEnd) = getPreviousPeriod($startDate, $endDate);

			$newC 		= ncmExecute(
				"
                        SELECT COUNT(contactId) as count
                        FROM contact 
                        WHERE type = 1 
                        AND contactDate 
                        BETWEEN ? 
                        AND ? 
                        AND companyId = " . COMPANY_ID,
				[$startDate, $endDate],
				true
			);

			if ($newC) {
				$acquired = $newC['count'];
			}

			$sqlPast 	= 	'SELECT COUNT(c.contactId) as count 
					FROM contact c 
					WHERE c.contactDate < ?
					' . $roc . '
					AND EXISTS (
						SELECT 1 
						FROM transaction t 
						WHERE t.transactionDate BETWEEN ? AND ?
						AND t.transactionType IN(0,3)
						AND t.customerId = c.contactId
					)';

			$resultPast = ncmExecute($sqlPast, [$backStart, $backStart, $backEnd], true);

			if ($resultPast) {
				$customerStart = $resultPast['count'];
			}

			$sqlNow 	= 	'SELECT COUNT(c.contactId) as count 
					FROM contact c 
					WHERE c.contactDate < ?
					' . $roc . '
					AND EXISTS (
						SELECT 1 
						FROM transaction t 
						WHERE t.transactionDate BETWEEN ? AND ?
						AND t.transactionType IN(0,3)
						AND t.customerId = c.contactId
					)';

			$resultNow = ncmExecute($sqlPast, [$startDate, $startDate, $endDate], true);

			if ($resultNow) {
				$customerEnd = $resultNow['count'];
			}

			$acquired = 0;

			$custGrowth = $customerEnd - $customerStart;
			$growthR 	= divider($custGrowth, $customerStart, true) * 100;

			//$churn 		= $customerEnd - $customerStart;

			$churn 		= $customerEnd - $acquired - $customerStart;

			if ($churn > 0) {
				$churn = 0;
			} else {
				$churn = abs($churn ?? 0);
			}

			$churnR 	= divider(abs($churn ?? 0), $customerStart, true) * 100;

			$retentionR = divider(($customerEnd - $acquired), $customerStart, true) * 100;

			return 	[
				'churn_rate' 			=> round($churnR, 2),
				'churn' 				=> round($churn, 2),
				'retention_rate' 		=> round($retentionR - $growthR, 2),
				'customer_growth' 		=> round($custGrowth, 2),
				'customer_growth_rate' 	=> round($growthR, 2),
				'start_count' 			=> $customerStart,
				'end_count' 			=> $customerEnd,
				'new_count'				=> $acquired
			];
		}

		function reportsDayAndTitle($opt)
		{
			global $startDate, $endDate;

			$title 			= iftn($opt['title'], 'Reporte');
			$hideChart		= $opt['hideChart'];
			$tutorial 		= $opt['tutorial'];
			$tour 			= $opt['tour'];
			$showLoading 	= !$opt['hideChart'] ? true : false;
			$hideTimePckr 	= $opt['hideDate'];
			$pickerReplace 	= iftn($opt['pickerReplace'], '');
			$maxDays 		= iftn($opt['maxDays'], '0');
			$chartId 		= iftn($opt['chartId'], 'myChart');
			$chartH 		= iftn($opt['chartH'], '350');
			$nextToPicker 	= iftn($opt['nextToPicker'], '');

			$tutorialink 	= '';
			$tourLink 		= '';

			if ($tutorial) {
				$tutorialink = '<a href="' . $tutorial . '" class="m-l-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="Visitar el centro de ayuda"><i class="material-icons text-info m-b-xs">help_outline</i></a>';
			}

			if ($tour) {
				$tourLink = '<a href="' . $tour . '" class="m-l-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="Hacer un tour"><i class="material-icons text-info m-b-xs">live_help</i></a>';
			}

?> <div class="col-xs-12 no-padder">
		<?= headerPrint(); ?>
		<div class="col-xs-12 no-padder">
			<div class="col-sm-5 col-xs-12 m-t-sm m-b">
				<?php
				if (!$hideTimePckr) {
				?>
					<form action="" class="col-md-9 col-xs-12 no-padder" method="post" id="manualDate" name="manualDate">
						<label class="visible-print text-u-c font-bold text-xs m-b-n-xs">Fecha</label>
						<input type="text" id="customDateR" class="form-control no-border bg-white pointer font-bold text-center rounded needsclick" name="range" value="" data-max="<?= $maxDays; ?>" />
					</form>
					<div class="col-xs-12 visible-xs m-b"></div>
					<?= $nextToPicker ?>
				<?php
				} else {
					echo $pickerReplace;
				}
				?>
			</div>

			<div class="col-sm-7 col-xs-12 m-t-sm m-b">
				<div class="pull-right">
					<h1 class="no-padder hidden-print m-n font-bold"><?= '<span id="pageTitle">' . $title . '</span>' . $tutorialink . $tourLink ?></h1>
					<h3 class="no-padder visible-print m-n font-bold"><?= $title ?></h3>
				</div>
			</div>
		</div>
		<?php
			if (!$hideChart) {
		?>

			<div class="col-xs-12 no-padder m-b hidden-print">
				<?php
				if ($showLoading) {
					echo placeHolderLoader('chart');
				}
				?>
				<canvas id="<?= $chartId; ?>" height="<?= $chartH ?>" style="width:100%; height:<?= $chartH ?>px;" class="<?= ($showLoading) ? 'hidden' : '' ?>"></canvas>
			</div>
		<?php
			}
		?>
	</div>
<?php
		}

		function reportsTitle2($title, $hideChart = false, $tutorial = false, $showLoading = false, $hideTimePckr = false)
		{
			global $startDate, $endDate;
			$tutorialink = '';
			if ($tutorial) {
				$tutorialink = '<a href="' . $tutorial . '" class="m-r-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="Visitar el centro de ayuda"><i class="material-icons text-info">help_outline</i></a>';
			}
?> <div class="col-xs-12 no-padder">
		<?= headerPrint(); ?>
		<div class="col-xs-12 no-padder m-b">
			<div class="col-sm-1 text-center m-t-xs">
				<?= menuBtn(); ?>
			</div>
			<div class="col-sm-4 m-t-sm">
				<?php
				if (!$hideTimePckr) {
				?>
					<form action="" class="" method="post" id="manualDate" name="manualDate">
						<input type="text" id="customDateR" class="form-control no-border bg-white pointer font-bold text-center rounded needsclick" name="range" value="" />
					</form>
				<?php
				}
				?>
			</div>

			<div class="col-sm-7 m-t-sm">
				<div class="pull-right">
					<h2 class="no-padder m-n font-bold"><?= $tutorialink . $title ?></h2>
				</div>
			</div>
		</div>
		<?php
			if (!$hideChart) {
		?>

			<div class="col-xs-12 no-padder m-b">
				<?php
				if ($showLoading) {
					echo placeHolderLoader('chart');
				}
				?>
				<canvas id="myChart" height="350" style="width:100%; height:350px;" class="<?= ($showLoading) ? 'hidden' : '' ?>"></canvas>
			</div>
		<?php
			}
		?>
	</div>
<?php
		}

		function menuBtn()
		{
			return '<a href="#" class="btn btn-rounded btn-lg btn-icon dker" data-toggle="tooltip" title="Menú" data-placement="right"><i class="material-icons">sort</i></a>';
		}

		function limitReportAccess()
		{
			if (ROLE_ID == 7) {
				include('empty_page.php');
				dai();
			}
		}

		function isInvoiceEditable($options = [])
		{
			if (!empty($options['blockOnRO'])) {
				if (validateHttp('ro')) {
					return false;
				} else {
					return true;
				}
			} else if (!empty($options['blockOnROLE'])) {
				if (ROLE_ID == 7) {
					return false;
				} else {
					return true;
				}
			} else {
				if (validateHttp('ro') || ROLE_ID == 7) {
					return false;
				} else {
					return true;
				}
			}
		}

		function getNextDocNumber($number, $type, $register)
		{
			$result  = ncmExecute('SELECT invoiceNo FROM transaction FORCE INDEX (idx_transaction_optimization_2) WHERE registerId = ? AND companyId = ? AND (invoiceNo IS NOT NULL AND invoiceNo > 0) AND transactionType IN(?) ORDER BY transactionDate DESC LIMIT 1', [$register, COMPANY_ID, $type]);
			$no = (int)$result['invoiceNo'];

			if ($no >= $number) {
				return $no + 1;
			} else {
				if ($no < 1) {
					return 1;
				} else {
					return $no;
				}
			}
		}

		function menuReports($extra = '', $outcome = false)
		{
			global $plansValues;
		}

		function salesReportsByDate($startDate, $endDate, $outlet, $register)
		{
			global $db, $SQLcompanyId;
			$startDateCompare 	= date('Y-m-d', strtotime($startDate));
			$endDateCompare 	= date('Y-m-d', strtotime($endDate));

			//$COGS 		= getItemsCOGS($startDate,$endDate,true);
			$OC			= 0; //getOperatingCost(OUTLET_ID);						   

			if ($startDateCompare == $endDateCompare) {
				//$COGS 	= getItemsCOGS($startDate,$endDate,true,true);

				$result 	= $db->Execute("SELECT transactionDate as date,
									  SUM(transactionDiscount) AS discount,
									  SUM(transactionTax) AS tax,
									  SUM(transactionTotal) AS total,
									  SUM(transactionUnitsSold) AS usold,
									  EXTRACT(YEAR from transactionDate) AS year,
									  EXTRACT(MONTH from transactionDate) AS month,
									  EXTRACT(DAY from transactionDate) AS day,
									  EXTRACT(HOUR from transactionDate) AS hour,
									  EXTRACT(MINUTE from transactionDate) AS minute
								FROM transaction
								WHERE transactionDate
									BETWEEN ?
									AND ?
									AND transactionType = '0' 
									" . $register . "
									" . $outlet . "  
								   	AND " . $SQLcompanyId . "
								GROUP BY hour ASC", array($startDate, $endDate));
			} else {

				$result 	= $db->Execute("SELECT transactionDate as date, 
										SUM(transactionUnitsSold) as usold, 
										COUNT(transactionDate) as count, 
										SUM(transactionDiscount) as discount, 
										SUM(transactionTax) as tax, 
										SUM(transactionTotal) as total 
									  FROM transaction 
									 WHERE transactionDate 
									 	BETWEEN ?
									 	AND ? 
									   	AND transactionType = '0' 
									   " . $register . "
									   " . $outlet . "  
									   AND " . $SQLcompanyId . "
								  GROUP BY DATE(date)
								  ORDER BY date ASC", array($startDate, $endDate));
			}

			echo '<pre>';
			while (!$result->EOF) {
				print_r($result->fields);
				$result->MoveNext();
			}

			$result->Close();
			echo '';
			echo '</pre>';

			die();
		}

		function getExpensesOfGivenTime()
		{
			global $db, $startDate, $endDate, $o;

			$outlet = str_replace('b.outletId', 'outletId', $o);
			$result = $db->Execute("SELECT SUM(expensesAmount)
									FROM 
										expenses
									WHERE 
									expensesDate BETWEEN ? AND ? 
									" . $outlet, array($startDate, $endDate));
			return $result->fields['expensesAmount'];
		}

		function toAscii($str, $replace = array(), $delimiter = '-')
		{
			//string to url sanitizor
			if (!empty($replace)) {
				$str = str_replace((array)$replace, ' ', $str);
			}

			//$clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
			$clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $str);
			$clean = strtolower(trim($clean, '-'));
			$clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);

			return $clean;
		}

		function loginPart($result)
		{
			global $db;
			$company = ncmExecute(
				"SELECT * FROM company WHERE companyId = ? LIMIT 1",
				[$result['companyId']]
			);

			if ($company['status'] != 'Active') { //
				dai('Cuenta inhabilitada, por favor contactenos al correo <?= EMAIL_FROM ?>');
			}

			//dona chipa
			/*if($company['parentId'] === 1522 && !$_SESSION['refered_access']){
		return 'false';
	}*/
			//dona chipa

			$outlet 	= ncmExecute("SELECT
									outletId
								FROM outlet
								WHERE
									outletStatus = 1
								AND	companyId = ? 
								" . iftn($result['outletId'], '', ' AND outletId = ' . $result['outletId']) . "
								ORDER BY 
								outletId ASC LIMIT 1", [$result['companyId']]);

			$outletCount = ncmExecute("SELECT
									COUNT(outletId) as count
								FROM outlet
								WHERE
									outletStatus = 1
								AND companyId = ?", [$result['companyId']]);


			// Here I am preparing to store the $row array into the $_SESSION by
			// removing the salt and password values from it.  Although $_SESSION is
			// stored on the server-side, there is no reason to store sensitive values
			// in it unless you have to.  Thus, it is best practice to remove these
			// sensitive values first.
			unset($result['salt'], $result['userPassword']);

			//
			//si el rol tiene una sucursal predefinida, le asigno esa, si no, tiene acceso a todas
			$assignedOutlet = enc($outlet['outletId']);
			$oCount 		= $outletCount['count'];
			if ($oCount > 1) {
				if ($result['role'] == 2 || $result['role'] == 7) {
					$assignedOutlet = enc($result['outletId']);
				}
			}
			//

			// Regenerar ID de sesión al autenticar — previene session fixation
			session_regenerate_id(true);

			$_SESSION['last_activity'] 					= time();
			$_SESSION['user']['companyId']  		= enc($result['companyId']);
			$_SESSION['user']['companyDB']  		= $company['companyDB'];
			$_SESSION['user']['companyStatus']  = $company['status'];
			$_SESSION['user']['companyParent']  = ($company['isParent']) ? $company['isParent'] : 0;
			$_SESSION['user']['userId']  				= enc($result['contactId']);
			$_SESSION['user']['userName']  			= $result['contactName'];
			$_SESSION['user']['userEmail'] 			= $result['contactEmail'];
			$_SESSION['user']['role']  					= enc($result['role']);
			$_SESSION['user']['roleName']				= getRoleName($_SESSION['user']['role']);
			$_SESSION['user']['rolePermisions']	= getRolePermissions($result['role'], $result['companyId']);

			if ($result['companyId'] == 10 && 1 == 2) {
				$setting 	= ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$result['companyId']]);
				$modules 	= ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$result['companyId']]);

				foreach ($setting as $key => $value) {
					$setting[$key] = unXss($value);
				}

				foreach ($modules as $key => $value) {
					$modules[$key] = unXss($value);
				}

				$_SESSION['user']['companySettings'] = $setting;
				$_SESSION['user']['companyModules']  = $modules;
			}

			$_SESSION['user']['outletId'] 		= $assignedOutlet;
			$_SESSION['user']['toFixedOutlet']	= dec($assignedOutlet);
			$_SESSION['user']['plan'] 			= enc($company['plan']);
			$_SESSION['user']['planExpires'] 	= $company['expiresAt'];
			$_SESSION['user']['outletsCount'] 	= $oCount;
			$_SESSION['user']['startDate'] 		= false;
			$_SESSION['user']['endDate'] 		= false;
			$_SESSION['user']['SAAS_ADM']    	= ($result['companyId'] == ENCOM_COMPANY_ID) ? true : false;

			return 'true';
			$result->Close();
		}

		function findEmailOrPhoneLogin($email)
		{
			$result = ncmExecute("SELECT
                          *
                        FROM contact
                        WHERE contactEmail = ? 
                        AND role IN (0,1,2,7)
                        AND type = 0
                        LIMIT 1", [$email]);
			if (!$result) {
				$result = ncmExecute("SELECT
		                      *
		                    FROM contact
		                    WHERE contactPhone = ? 
		                    AND role IN (0,1,2,7)
		                    AND type = 0 
		                    LIMIT 1", [$email]);
			}

			return $result;
		}

		function getUserIpAddr()
		{
			if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
				//ip from share internet
				$ip = $_SERVER['HTTP_CLIENT_IP'];
			} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				//ip pass from proxy
				$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
			} else {
				$ip = $_SERVER['REMOTE_ADDR'];
			}
			return $ip;
		}

function sendEmail($to, $subject, $body, $altbody, $from = EMAIL_FROM, $smtp = true)
		{
			// Envio de correo con Mailgun
			$mgClient = MailgunClient::create(MAILGUN_TOKEN);
			$domain = MAILGUN_DOMAIN;

			# Make the call to the client.			
			try {
				$resultMail = $mgClient->messages()->send($domain, [
					'from'    => 'Income Register <' . $from . '>',
					'to'      => $to,
					'subject' => toUTF8($subject),
					'html'    => toUTF8($body),
					'text'    => $altbody,
					'h:Reply-To' => 'Income Register <' . $from . '>'
				]);

				// Verificar el estado del envío
				if ($resultMail->getId()) {
					//error_log("Correo enviado exitosamente. ID: " . $resultMail->getId(), 3, './error_log');
					return true;
				} else {
					error_log("No se pudo enviar el correo.", 3, './error_log');
					return "No se pudo enviar el correo.";
				}
			} catch (\Exception $e) {
				// Manejo de errores
				error_log("Error al enviar el correo: " . $e->getMessage(), 3, './error_log');
				return "Error al enviar el correo: " . $e->getMessage();
			}
		}

		function convert($size)
		{
			$unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
			return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
		}

		function sendSMTPEmail($options, $to, $subject, $body, $altbody, $from = EMAIL_FROM, $fromName = APP_NAME)
		{
			// Envio de correo con Mailgun
			$mgClient = MailgunClient::create(MAILGUN_TOKEN);
			$domain = MAILGUN_DOMAIN;

			# Make the call to the client.			
			try {
				$resultMail = $mgClient->messages()->send($domain, [
					'from'    => $fromName . ' <' . $from . '>',
					'to'      => $to,
					'subject' => toUTF8($subject),
					'html'    => toUTF8($body),
					'text'    => $altbody,
					'h:Reply-To' => $fromName . ' <' . $from . '>'
				]);

				// Verificar el estado del envío
				if ($resultMail->getId()) {
					//error_log("Correo enviado exitosamente. ID: " . $resultMail->getId(), 3, './error_log');
					return true;
				} else {
					error_log("No se pudo enviar el correo.", 3, './error_log');
					return "No se pudo enviar el correo.";
				}
			} catch (\Exception $e) {
				// Manejo de errores
				error_log("Error al enviar el correo: " . $e->getMessage(), 3, './error_log');
				return "Error al enviar el correo: " . $e->getMessage();
			}
		}

		function sendSMS($number, $msg, $country = false, $smsCredit = 0, $companyId = false)
		{
			global $apiKey, $companyId;

			$data =   [
				'api_key'       => iftn($apiKey, API_KEY),
				'company_id'    => iftn($companyId, enc(COMPANY_ID)),
				'phone'         => $number,
				'country'       => iftn($country, COUNTRY_CODE),
				'msg'       	=> $msg,
				'credit'       	=> iftn($smsCredit, SMS_CREDIT),
				'secret' 		=> NCM_SECRET
			];

			$out = curlContents(API_URL . '/send_sms', 'POST', $data);

			return [$out, $number];
		}

		function sendEmails($options)
		{

			$from     = iftn($options['from'], EMAIL_FROM);
			$fromName = iftn($options['fromName'], APP_NAME);
			$to       = $options['to'];
			$subject  = $options['subject'];
			// $type 	= $options['type'];
			// $options['data']['subject'] = $subject;
			// $data     = json_encode($options['data']); //paso php array y convierto a json

			// $data   = '{' .
			// 	' "from":{' .
			// 	'   "email":"' . $from . '",' .
			// 	'	"name":"' . $fromName . '"' .
			// 	' },' .
			// 	' "personalizations":[{' .
			// 	'   "to":[' .
			// 	'         { "email":"' . $to . '"}' .
			// 	'        ],' .
			// 	'   "dynamic_template_data":' . $data .
			// 	' }],' .
			// 	'}';



			// Envio de correo con Mailgun
			$mgClient = MailgunClient::create(MAILGUN_TOKEN);
			$domain = MAILGUN_DOMAIN;

			# Make the call to the client.			
			try {
				$resultMail = $mgClient->messages()->send($domain, [
					'from'    => $fromName . ' <' . $from . '>',
					'to'      => $to,
					'subject' => toUTF8($subject),
					'html'    => toUTF8($options['data'])
				]);

				// Verificar el estado del envío
				if ($resultMail->getId()) {
					//error_log("Correo enviado exitosamente. ID: " . $resultMail->getId(), 3, './error_log');
					return true;
				} else {
					error_log("No se pudo enviar el correo.", 3, './error_log');
					return "No se pudo enviar el correo.";
				}
			} catch (\Exception $e) {
				// Manejo de errores
				error_log("Error al enviar el correo: " . $e->getMessage(), 3, './error_log');
				return "Error al enviar el correo: " . $e->getMessage();
			}
		}

		function sendPush($options)
		{

			$companyId = $options['companyId']; //para el api auth
			$options['where'] = $options['where'] ? $options['where'] : 'caja';

			$data = [
				'api_key'       => iftn($apiKey, API_KEY),
				'company_id'    => iftn($companyId, enc(COMPANY_ID)),
				"secret" 		=> NCM_SECRET,
				"ids"       	=> $options['ids'],
				"message"    	=> $options['message'],
				"where"      	=> $options['where'],
				"title"      	=> $options['title'],
				"web_url"      	=> $options['web_url'],
				"app_url"      	=> $options['app_url'],
				"filters"   	=> json_encode($options['filters']),
				"edata"   		=> json_encode($options['edata'])
			];

			return json_decode(curlContents('http://localhost:8002/API/send_push', 'POST', $data));
		}

		function getAllContactPusheableIds($companyId = COMPANY_ID)
		{
			$ids = ncmExecute('SELECT contactId as ids FROM contact WHERE companyId = ? AND type = 0', [$companyId], false, true);
			$out = [];

			if ($ids) {
				while (!$ids->EOF) {
					$out[] = enc($ids->fields['ids']);
					$ids->MoveNext();
				}
				$ids->Close();
			}

			return $out;
		}


		function SMSSegmentsCounter($str)
		{
			if (validity($str, 'string')) {
				//$str 		= mb_convert_encoding($str,'UCS-2LE');//convierto a encoding ucs2, version latina de encoding
				//Como el server no soporta mb_convert_encoding multipllico el output por 2 ya que al convertir a ucs2 es el doble de largo de un SMS normal
				$charln 	= 160;
				$length 	= counts($str) * 1.2;
				$segments 	= divider($length, $charln, true, 'up');
				return $segments;
			} else {
				return 0;
			}
		}

		function getValidPhone($phone, $country, $format = false)
		{
			$phone      = preg_replace("/[^0-9]/", "", $phone);
			$format     = ($format) ? $format : 'international';
			$valid      = json_decode(getFileContent(API_URL . '/phonevalidator.php?phone=' . $phone . '&format=national&country=' . $country . '&format=' . $format), true);

			return $valid;
		}

		function getPhoneFormat($number, $countryCode = false, $returnField = 'phone_number')
		{
			if (!validity($number)) {
				return '';
			}
			$countryCode = iftn($countryCode, COUNTRY_CODE);
			$valid = json_decode(getFileContent(API_URL . '/phonevalidator.php?phone=' . rawurlencode($number) . '&country=' . $countryCode . '&format=international'), true);

			if (empty($valid) || !empty($valid['error'])) {
				return false;
			}

			// Mapa de campos Twilio Lookups → phonevalidator
			$fieldMap = ['phone_number' => 'phone', 'national_format' => 'national'];
			$field = $fieldMap[$returnField] ?? 'phone';

			return iftn($valid[$field], '');
		}

		function addWhatsAppLink($text = false)
		{
			$add = '';
			if (OUTLET_WHATS_APP) {

				if ($text) {
					$add = ' WA: ';
				}

				if (OUTLET_WHATS_APP) {
					return '\n' . $add . ' https://wa.me/' . OUTLET_WHATS_APP;
				} else {
					return '';
				}
			}
		}

		function random_password($length = 5)
		{
			$chars 		= 'abcdefghijklmnopqrstuvwxyz0123456789';
			$password 	= substr(str_shuffle($chars), 0, $length);
			return $password;
		}

		function implodes($str, $array, $returnEmpty = false)
		{
			if (validity($array, 'array')) {
				return implode($str, $array);
			} else {
				if ($returnEmpty) {
					return '';
				} else {
					return false;
				}
			}
		}

		function explodes($char, $string, $returnEmpty = true, $returnIndex = -1)
		{
			if (validity($string, 'string')) {
				$string = rtrim($string, $char);
				if ($returnIndex > -1) {
					$out = explode($char, $string);
					return $out[$returnIndex];
				} else {
					return explode($char, $string);
				}
			} else {
				if ($returnEmpty) {
					return array();
				} else {
					return false;
				}
			}
		}

		function json_encodes($data, $addH = false)
		{
			if ($addH) {
				header('Content-Type: application/json; charset=utf-8;');
			}
			return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		function dai($val = '', $noclose = false)
		{
			global $db;
			if (!$noclose) {
				$db->Close();
			}

			if (validity($val, 'string')) {
				echo $val;
			} else if (validity($val, 'array')) {
				print_r($val);
			}

			die();
		}

		function setPlanToFreeIfCantCharge()
		{
			global $db;

			$result = $db->Execute("SELECT companyId FROM company WHERE createdAt < NOW() - INTERVAL '2 weeks' AND plan = 3");
			while (!$result->EOF) {
				$id = $result->fields['companyId'];
				$where .= 'companyId = ' . $id . ' AND ';
				$result->MoveNext();
			}

			if (validateResultFromDB($result)) {
				$update = $db->Execute("UPDATE company SET plan = 0 WHERE " . rtrim($where, ' AND '));

				$user = $db->Execute('SELECT userEmail FROM user WHERE role = 1 AND ' . rtrim($where, ' AND '));
				while (!$user->EOF) {
					$options = json_encode(array(
						"to" => array($user->fields['userEmail']),
						"sub" => array(":email" => array($user->fields['userEmail'])),
						"filters" => array(
							"templates" => array(
								"settings" => array("enable" => 1, "template_id" => "24d96e49-c106-4dc3-bdb6-38c28cf9b018")
							)
						)
					));
					sendSMTPEmail($options, $user->fields['userEmail'], 'Su plan fue modificado', 'Income', 'Income');
					$user->MoveNext();
				}

				$user->Close();
			}

			$result->Close();
		}

		function checkToSendMonthlyInvoice()
		{
			global $db;

			$result = $db->Execute("SELECT * FROM company WHERE plan = 1");
			while (!$result->EOF) {
				$id = $result->fields['companyId'];
				// $where .= $id.',';

				if (validateResultFromDB($result)) {

					$payment = $db->Execute("SELECT * FROM cpayments WHERE companyId = " . $id . " LIMIT 1");

					while (!$payment->EOF) {
						$monthIs 		= endCycle($payment->fields['cpaymentsDate'], 1); //agrego un mes a la ultima fecha de pago realizada
						$strDBMonth 	= strtotime($monthIstime); //a la fecha le resto 5 dias
						$today 			= strtotime(date('YYY-m-d')); //fecha de hoy

						if ($strDBMonth == $today) { //si hoy es la fecha 5 dias antes del vencimiento
							echo 'Vence en 5 dias y puede pagar hasta 5 dias despues de su vencimiento, envio email a ' . $id;
						}

						/*$options = json_encode(array(
			              "to"=> array($user->fields['userEmail']),
			              "sub"=> array(":email"=>array($user->fields['userEmail'])),
			              "filters"=> array(
			                        "templates"=>array(
			                                  "settings"=>array("enable"=>1,"template_id"=>"24d96e49-c106-4dc3-bdb6-38c28cf9b018")
			                                )
			                      )
			              ));
			    sendSMTPEmail($options,$user->fields['userEmail'],'Su plan fue modificado','Income','Income');*/
						$payment->MoveNext();
					}

					$payment->Close();
				}


				$result->MoveNext();
			}



			$result->Close();
		}

		function add_months($months, DateTime $dateObject)
		{
			$next = new DateTime($dateObject->format('Y-m-d'));
			$next->modify('last day of +' . $months . ' month');

			if ($dateObject->format('d') > $next->format('d')) {
				return $dateObject->diff($next);
			} else {
				return new DateInterval('P' . $months . 'M');
			}
		}

		function getTimeDifference($starts, $ends)
		{
			$diff = false;
			if ($starts && $ends) {
				$start      = new DateTime($starts);
				$end        = new DateTime($ends);
				$diff       = $start->diff($end);
			}

			return $diff;
		}

		function endCycle($d1, $months)
		{
			$date = new DateTime($d1);

			// call second function to add the months
			$newDate = $date->add(add_months($months, $date));

			// goes back 5 day from date
			$newDate->sub(new DateInterval('P5D'));

			//formats final date to Y-m-d form
			$dateReturned = $newDate->format('Y-m-d');

			return $dateReturned;
		}

		function signUp($post, $login = true)
		{
			global $db, $countries, $installConfig;

			$doLet 		= false; //esta bar dice si se puede registrar o no los datos ingresados

			$email 		= strtolower($post['email']);
			$storeName 	= ucwords($post['storename']);

			if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$isEmail = true;
				$resultEmail 	= ncmExecute("SELECT
										*
									FROM contact
									WHERE
									type = 0
									AND
									contactEmail = ?", [$email]);
			} else {
				$isEmail = false;
				$resultEmail 	= ncmExecute("SELECT
										*
									FROM contact
									WHERE
									type = 0
									AND
									contactPhone = ?", [$email]);
			}


			if ($resultEmail) {
				return "Ya existe una cuenta con este número o email";
				$doLet = false;
			} else {
				$doLet = true;
			}



			if ($doLet) {
				// An INSERT query is used to add new rows to a database table.
				// Again, we are using special tokens (technically called parameters) to
				// protect against SQL injection attacks.

				$db->StartTrans();

				$accountId 		= rand(); //por ahora uso un numero random nomas como Account ID hasta que haga la DB de administracion de cuentas
				$companyRecord 	= [];
				$outletRecord 	= [];
				$registerRecord = [];
				$billRecord 	= [];
				$settingRecord 	= [];
				$moduleRecord 	= [];
				$userRecord 	= [];
				$itemRecord 	= [];
				//
				$companyRecord['companyName'] 			= $storeName;
				$companyRecord['plan'] 			= 3;
				$companyRecord['status'] 		= 'Active';
				$companyRecord['expiresAt'] 	= date('Y-m-d 00:00:00', strtotime("+14 days"));
				$companyRecord['accountId'] 			= $accountId;

				if ($post['parent']) {
					$companyRecord['parentId'] 			= dec($post['parent']);
				}

				$companyInsert 	= ncmInsert(['records' => $companyRecord, 'table' => 'company']);
				$company 		= $companyInsert;

				//
				$outletRecord['outletName'] 	= 'Central';
				$outletRecord['outletStatus'] 	= 1;
				$outletRecord['companyId'] 		= $company;
				//$outletRecord['outletTax'] 		= 0;

				$outletInsert 	= ncmInsert(['records' => $outletRecord, 'table' => 'outlet']);
				$outlet 		= $outletInsert;

				//
				$registerRecord['registerName']     = 'Caja Principal';
				$registerRecord['registerStatus']   = 1;
				$registerRecord['outletId']         = $outlet;
				$registerRecord['companyId']        = $company;

				$registerInsert = ncmInsert(['records' => $registerRecord, 'table' => 'register']);
				$register 		= $registerInsert;

				//
				$countryCode = strtoupper($post['country']);
				$cSymbol 	= $countries[$countryCode]['currency']['symbol'];
				$lang 		= explode(',', $countries[$countryCode]['languages']);
				$decim 		= ($countries[$countryCode]['currency']['decimal_digits'] < 1) ? 'no' : 'yes';
				$taxName 	= $countries[$countryCode]['currency']['vat_name'];
				$tin 		= $countries[$countryCode]['tin'];

				$settingRecord['settingName']           = $storeName;
				$settingRecord['settingCurrency']       = iftn($cSymbol, '$');
				$settingRecord['settingCountry']        = $countryCode;
				$settingRecord['settingLanguage']       = iftn($lang[0], 'es');
				$settingRecord['settingTimeZone']       = 'America/Asuncion';
				$settingRecord['settingAcceptedTerms']  = 1;

				$settingRecord['settingBillTemplate']   	= 'ticket';
				$settingRecord['settingDecimal']        	= $decim;
				$settingRecord['settingThousandSeparator']  = 'dot';
				$settingRecord['settingTaxName']        	= iftn($taxName, 'VAT');
				$settingRecord['settingTIN']        		= iftn($tin, 'TIN');
				$settingRecord['settingCompanyCategoryId'] 	= $post['category'];

				$settingRecord['companyId']             	= $company;

				$settingInsert 	= ncmInsert(['records' => $settingRecord, 'table' => 'company']);

				createSlug($storeName, false, $company);

				$vat 			= iftn($countries[$countryCode]['currency']['vat'], false);
				if ($vat) {
					$taxonomyRecord['taxonomyName']	= $vat;
					$taxonomyRecord['taxonomyType']	= 'tax';
					$taxonomyRecord['companyId'] 	= $company;
					$taxonomyInsert = ncmInsert(['records' => $taxonomyRecord, 'table' => 'taxonomy']);
				}

				//MODULES
				foreach ($installConfig as $key => $val) {
					$match 		= $val['match'];
					$modules 	= $val['modules'];
					if (in_array($post['category'], $match)) {
						if (in_array('schedule', $modules)) {
							$moduleRecord['calendar'] 	= 1;
						}
						if (in_array('tables', $modules)) {
							$moduleRecord['tables'] 	= 1;
						}
						if (in_array('ordersPanel', $modules)) {
							$moduleRecord['ordersPanel'] 	= 1;
						}
						if (in_array('ecom', $modules)) {
							$moduleRecord['ecom'] 	= 1;
						}
						if (in_array('dunning', $modules)) {
							$moduleRecord['dunning'] 	= 1;
						}
						if (in_array('recurring', $modules)) {
							$moduleRecord['recurring'] 	= 1;
						}
						if (in_array('kds', $modules)) {
							$moduleRecord['kds'] 	= 1;
						}
						if (in_array('production', $modules)) {
							$moduleRecord['production'] 	= 1;
						}
						if (in_array('feedback', $modules)) {
							$moduleRecord['feedback'] 	= 1;
						}
					}
				}

				// Los campos de módulo van al config JSONB de la fila company ya existente
				$moduleUpdate 	= ncmUpdate(['records' => $moduleRecord, 'table' => 'company', 'where' => 'companyId = ' . $db->qstr($company)]);
				//		

				//productos y servicios
				foreach ($installConfig as $key => $val) {
					$match = $val['match'];
					$items = $val['items'];
					if (in_array($post['category'], $match)) {
						$hotKeys = [];
						foreach ($items as $i => $item) {
							$itemRecord['itemName'] 		= $item['name'];
							$itemRecord['itemSKU'] 			= "PS 00" . $i;
							$itemRecord['itemStatus'] 		= 1;
							$itemRecord['taxId'] 			= $taxonomyInsert;
							$itemRecord['itemImage'] 		= 'false';
							$itemRecord['itemPrice'] 		= $item['price'];

							$itemRecord['companyId'] 		= $company;

							$itemInsert = ncmInsert(['records' => $itemRecord, 'table' => 'item']);
							$hotKeys[] = ['color' => '', 'itemId' => $itemInsert, 'position' => ($i + 1)];
						}

						//add hotkeys
						$regHotKeys = ['registerHotkeys' => json_encode($hotKeys)];
						ncmUpdate(['records' => $regHotKeys, 'table' => 'register', 'where' => 'registerId = ' . $registerInsert . ' AND companyId = ' . $company]);
					}
				}

				//Customer
				$customerRecord = [];
				$customerRecord['contactName']   	= 'Primer Cliente';
				$customerRecord['companyId']  		= $company;
				$customerRecord['type'] 			= '1';

				$customerInsert 					= ncmInsert(['records' => $customerRecord, 'table' => 'contact']);


				if ($outletInsert && $companyInsert && $registerInsert && $settingInsert) {

					$pasSalt = passEncoder($post['password']);

					// Here we prepare our tokens for insertion into the SQL query.  We do not
					// store the original password; only the hashed version of it.  We do store
					// the salt (in its plaintext form; this is not a security risk).
					$userRecord['contactName']   	= ucwords($post['username']);
					$userRecord['contactPassword'] 	= $pasSalt[0];
					//$userRecord['contactEmail']  	= $email;
					$userRecord['contactPhone']  	= $email;
					$userRecord['contactInCalendar'] = 1;

					$userRecord['companyId']  		= $company;
					$userRecord['outletId']  		= $outlet;
					$userRecord['main']     		= 'true';
					$userRecord['role']     		= 1; //1 = Super Admin
					$userRecord['salt']     		= $pasSalt[1];
					$userRecord['lockPass'] 		= '1111';
					$userRecord['type'] 			= '0';

					$userInsert 					= ncmInsert(['records' => $userRecord, 'table' => 'contact']);

					$failedTransaction = $db->HasFailedTrans();
					$db->CompleteTrans();

					if (!$failedTransaction) {

						if ($login) {
							$result = findEmailOrPhoneLogin($email);
							return loginPart($result);
						} else {
							return 'true';
						}
					} else {
						return $db->ErrorMsg();
						return 'false';
					}
				} else {
					return $db->ErrorMsg();
					return 'false';
				}
			}

			return false;
		}

		//SUPER PANEL
		function generateInvoice($companyId, $planId)
		{
			global $db;
			//Inserto el new invoice en la DB y envio el email al usuario y si hay credito descuento
			$invoice                    = array();
			$invoice['cinvoiceAmount']  = $plansValues[$plaId]['price'];
			$invoice['companyId']       = $companyId;

			$insert = $db->AutoExecute('cinvoice', $invoice, 'INSERT');
		}

		function acceptCompanyPayment($amount, $companyId)
		{ //company ID seria el ID del cliente de Income
			global $db, $meses;

			$update 	= $db->Execute('UPDATE company SET balance = balance+' . $amount . ' WHERE companyId = ?', array($companyId));
			if ($update) {
				$m 		 = date('m');
				$month	 = $meses[$m - 1];

				$email 	 = getValue('user', 'userEmail', 'WHERE main = \'true\' AND companyId = ' . $companyId);
				$options = json_encode(array(
					"to" => array($email),
					"sub" => array(
						":previousmonth" => array($month),
						":total" => array('$' . $amount)
					),
					"filters" => array(
						"templates" => array(
							"settings" => array(
								"enable" => 1,
								"template_id" => "9f8ce200-803d-46c4-847b-5f9c162db288"
							)
						)
					)


				));

				//sendSMTPEmail($options,$email,'Pago procesado exitosamente','Income','Income');

				return true;
			} else {
				return false;
			}
		}

		function generateBlankSpace($length = 20)
		{
			$out = '';
			for ($i = 0; $i < $length; $i++) {
				$out .= '&nbsp; ';
			}

			return $out;
		}

		function placeHolderLoader($type = false, $no = 10, $id = false)
		{
			$long 	= generateBlankSpace(50);
			$short 	= generateBlankSpace(30);

			if ($type == 'table') {
				echo 	'<tr>' .
					'	<th colspan="4"></td>' .
					'</tr>';

				echo 	'<tr>' .
					'	<th> <span class="label bg-light dk r-3x">' . $long . '</span> </td>' .
					'	<th> <span class="label bg-light dk r-3x pull-right">' . $short . '</span> </td>' .
					'	<th> <span class="label bg-light dk r-3x pull-right">' . $short . '</span> </td>' .
					'	<th> <span class="label bg-light dk r-3x pull-right">' . $short . '</span> </td>' .
					'</tr>';

				for ($i = 0; $i < 7; $i++) {
					echo 	'<tr>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x">' . $long . '</span> </td>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x pull-right">' . $short . '</span> </td>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x pull-right">' . $short . '</span> </td>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x pull-right">' . $short . '</span> </td>' .
						'</tr>';
				}
			} else if ($type == 'table-sm') {
				for ($i = 0; $i < 4; $i++) {
					echo 	'<tr>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x">' . $long . '</span> </td>' .
						'	<td> <span class="label bg-light animated flash infinite animatedx25 r-3x pull-right">' . $short . '</span> </td>' .
						'</tr>';
				}
			} else if ($type == 'chart') {
				echo '<div class="col-xs-12 wrapper" id="' . ($id ? $id : 'loadingChart') . '" style="height:350px;">
			    <div class="col-xs-3 wrapper" style="height:100%;"><div class="col-xs-12 wrapper bg-light dker" style="height:100%;"></div></div>
			    <div class="col-xs-3 wrapper" style="height:100%;"><div class="col-xs-12 wrapper bg-light dker" style="height:90%; margin-top:10%;"></div></div>
			    <div class="col-xs-3 wrapper" style="height:100%;"><div class="col-xs-12 wrapper bg-light dker" style="height:75%; margin-top:25%;"></div></div>
			    <div class="col-xs-3 wrapper" style="height:100%;"><div class="col-xs-12 wrapper bg-light dker" style="height:60%; margin-top:40%;"></div></div>
			</div>';
			} else {
				echo '<span class="label bg-black-opacity-1 animated flash infinite animatedx15 r-3x">' . generateBlankSpace($no) . '</span>';
			}
		}

		function startPageLoadTimeCalculator()
		{
			$time = microtime();
			$time = explode(' ', $time);
			$time = $time[1] + $time[0];
			return $time;
		}

		function endPageLoadTimeCalculator($start, $name, $write = false)
		{

			if (COMPANY_ID != 3733) {
				return false;
			}

			$time = microtime();
			$time = explode(' ', $time);
			$time = $time[1] + $time[0];
			$finish = $time;
			$total_time = round(($finish - $start), 4);

			if ($write) {
				error_log($name . ': ' . $total_time . ' segundos.');
			} else {
				return $name . ': ' . $total_time . ' segundos.';
			}
		}

		function validateAPIAccess($id, $secret, $debug = false)
		{

			$returns 	= false;

			if ($id) {
				$result = ncmExecute("SELECT accountId
								FROM company
								WHERE companyId = ?
								AND status = 'Active'
								LIMIT 1", [dec($id)], true);

				if (sha1($result['accountId']) == $secret) {
					$returns = true;
				}
			}

			return $returns;
		}

		function getAPICreds($id)
		{
			if ($id) {
				$result = ncmExecute("	SELECT accountId
								FROM company
								WHERE companyId = ?
								AND status = 'Active'
								LIMIT 1", [$id]);
				if ($result) {
					return sha1($result['accountId']);
				}
			} else {
				return false;
			}
		}

		function jsonDieMsg($msg = 'true', $code = 401, $type = 'error')
		{
			global $db;
			$array = [$type => $msg];

			http_response_code($code);
			header('Content-Type: application/json');

			echo json_encode($array);

			unset($array, $code, $db);
			die();
		}

		function jsonDieResult($array, $code = 200)
		{
			global $db;
			http_response_code($code);
			header('Content-Type: application/json');
			if (is_array($array)) {
				echo json_encode($array);
			}
			unset($array, $code, $db);
			die();
		}

		function jsonError($message = '', $type = 'error', $extra = array())
		{
			return json_encode(array($type => array('message' => $message), 'detail' => $extra));
		}

		function leadingZeros($num, $size = 0)
		{
			$s = $num . "";
			while (strlen($s) < $size) $s = "0" . $s;
			return $s;
		}

		function csvToBankData($data)
		{
			$parts 		= explode(';', $data);
			$bankId		= $parts[0];
			$bank 		= getTaxonomyName($bankId);
			$numChk 	= $parts[1];
			$dueChck 	= $parts[2];

			if (validity($bank)) {
				return $bank . ' <span class="badge">' . $numChk . '</span> ' . $dueChck;
			} else {
				return '';
			}
		}

		function loadLanguage($lang = 'es')
		{
			if (!$lang) {
				$lang = 'es';
			}

			include_once('languages/' . $lang . '.php');
		}

		function sendAuditoria($data, $token)
		{
			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL 						=> AUDITORIA_URL . '/api/auditoria',
				CURLOPT_RETURNTRANSFER 				=> true,
				CURLOPT_ENCODING 					=> '',
				CURLOPT_MAXREDIRS 					=> 10,
				CURLOPT_TIMEOUT 					=> 0,
				CURLOPT_FOLLOWLOCATION 				=> true,
				CURLOPT_HTTP_VERSION 				=> CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST 				=> 'POST',
				CURLOPT_POSTFIELDS 					=> json_encode($data),
				CURLOPT_HTTPHEADER 					=> [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $token
				]
			));

			$response = curl_exec($curl);
			curl_close($curl);
			return $response;
		}
?>