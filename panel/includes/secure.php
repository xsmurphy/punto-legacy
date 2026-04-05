<?php

// if ($_SERVER["SERVER_PORT"] != 443) {
//     $redir = "Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
//     header($redir);
//     exit();
// }

session_start();

// At the top of the page we check to see whether the user is logged in or not
if(empty($_SESSION['user'])){
	if(isset($_GET['js']) && $_GET['js']){
    	die('expired');
    }
	// If they are not, we redirect them to the login page.
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8001';
	$baseUrl = $scheme . '://' . $host;
	header("Location: {$baseUrl}/login?ref={$baseUrl}{$_SERVER['REQUEST_URI']}");

	// Remember that this die statement is absolutely critical.  Without it,
	// people can view your members-only content without logging in.
	die("Redirecting empty user");
}else{

	define('SESSION_TIMEOUT', 604800); // 7 días en segundos

	function session_timed_out() {
	    return isset($_SESSION['last_activity']) && time() >= $_SESSION['last_activity'] + SESSION_TIMEOUT;
	}

	if (session_timed_out()) {
	    unset($_SESSION['user'],$_SESSION['last_activity']);
	    if(isset($_GET['js']) && $_GET['js']){
	    	die('expired');
	    }
	    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8001';
	    $baseUrl = $scheme . '://' . $host;
	    header("Location: {$baseUrl}/login?msg=Login Expired&ref={$baseUrl}{$_SERVER['REQUEST_URI']}");
	    die("Redirecting time out");
	} else {
		if(!isset($_GET['js'])){
		    $_SESSION['last_activity'] = time();
		}
	}
}
// Everything below this point in the file is secured by the login system


//check if its https, if not, redirect

// HTTPS redirect - desactivado para desarrollo local
$isLocalDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
    $isSecure = true;
}
elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
    $isSecure = true;
}

if(!$isSecure && !$isLocalDev){
	$redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit();
}


?>