<?php
/**
 * Asset loader — genera <script> y <link> tags individuales.
 * Cada archivo se carga por separado (HTTP/2 multiplexing).
 *
 * Vendor files: cache inmutable (versión en el nombre del archivo).
 * App files:    cache con APP_VERSION como query string.
 */

$_v = '/assets/vendor';
$_ver = defined('APP_VERSION') ? APP_VERSION : '1.0';

// ─── CSS ─────────────────────────────────────────────────────────────────
function appCSS() {
	global $_v, $_ver;

	$vendor = [
		'https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap',
		'https://fonts.googleapis.com/icon?family=Material+Icons',
		'https://fonts.googleapis.com/css2?family=Shadows+Into+Light&display=swap',
		"$_v/css/bootstrap-3.4.1.min.css",
		"$_v/css/animate-4.0.0.compat.min.css",
		"$_v/css/bootstrap-datetimepicker-4.17.47.min.css",
		"$_v/css/jquery.toast-1.3.2.min.css",
		"$_v/css/sweetalert2-7.33.1.min.css",
		"$_v/css/leaflet-1.7.1.css",
	];

	$app = [
		'/css/fonts.css',
		'/css/app.css',
		'/css/ncmCalendars.css',
		'/css/chosen.css',
		'/css/custom.css',
	];

	$panel = [
		'/css/color-selector-2.css',
	];

	foreach ($vendor as $f) {
		echo '<link rel="stylesheet" href="' . $f . '" type="text/css" />' . "\n";
	}
	foreach ($app as $f) {
		echo '<link rel="stylesheet" href="' . $f . '?' . $_ver . '" type="text/css" />' . "\n";
	}
	foreach ($panel as $f) {
		$base = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) ? 'http://localhost:8001' : '';
		echo '<link rel="stylesheet" href="' . $base . $f . '" type="text/css" />' . "\n";
	}
}

// ─── JS ──────────────────────────────────────────────────────────────────
function appJS($mode = 'js') {
	global $_v, $_ver;

	$panelBase = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) ? 'http://localhost:8001' : '';

	// Core vendor libs (immutable cache — version in filename)
	$vendor = [
		"$_v/js/jquery-3.6.3.min.js",
		"$_v/js/bootstrap-3.4.1.min.js",
		"$_v/js/moment-2.24.0-with-locales.min.js",
		"$_v/js/moment-locale-es.js",
		"$_v/js/jquery.dataTables-1.10.22.min.js",
		"$_v/js/isMobile-0.4.1.min.js",
		"$_v/js/offline-0.7.19.min.js",
		"$_v/js/chosen-1.8.7.min.js",
		"$_v/js/jquery.number-2.1.6.min.js",
		"$_v/js/mousetrap-1.6.3.min.js",
		"$_v/js/jquery.actual-1.0.19.min.js",
		"$_v/js/simpleStorage-0.2.1.min.js",
		"$_v/js/pouchdb-7.2.1.min.js",
		"$_v/js/lz-string-1.4.4.min.js",
		"$_v/js/rsvp-4.8.5.min.js",
		"$_v/js/jsrsasign-all-min.js",
		"$_v/js/qz-tray-2.2.1.min.js",
		"$_v/js/bootstrap-datetimepicker-4.17.47.min.js",
		"$_v/js/libphonenumber-1.6.8.min.js",
		"$_v/js/Chart-2.9.4.min.js",
		"$_v/js/sweetalert2-7.33.1.min.js",
		"$_v/js/push-1.0.8.min.js",
		"$_v/js/mustache-4.0.1.min.js",
		"$_v/js/jquery.fullscreen-1.1.5.min.js",
		"$_v/js/leaflet-1.7.1.js",
		"$_v/js/leaflet-routing-machine-3.2.12.js",
		"$_v/js/fingerprintjs-3.min.js",
		"$_v/js/jquery.geolocation-1.0.50.min.js",
		"$_v/js/jquery.toast-1.3.2.min.js",
	];

	// Extra vendor for debug/mobile mode
	if ($mode === 'debug' || $mode === 'mobile') {
		array_splice($vendor, 1, 0, ["$_v/js/fastclick-1.0.6.min.js"]);
		$vendor[] = "$_v/js/qrious.min.js";
	}

	// App code (versioned cache)
	$app = [
		'/scripts/sha256.js',
		'/scripts/sign-message.js',
		'/scripts/iguider.stub.js',
		'/scripts/ncm-ws.js',
	];

	// Panel shared scripts
	$panel = [
		$panelBase . '/scripts/written-number.min.js',
		$panelBase . '/scripts/color-selector-2.js',
		$panelBase . '/scripts/rb.min.js',
		$panelBase . '/scripts/num2word.js',
		$panelBase . '/scripts/documentPrintBuilder.source.js',
	];

	// Main app script (last)
	$mainScript = ($mode === 'debug' || $mode === 'mobile')
		? '/scripts/debug.js'
		: '/scripts/globalv2.js';

	// Emit tags
	foreach ($vendor as $f) {
		echo '<script src="' . $f . '"></script>' . "\n";
	}
	foreach ($app as $f) {
		echo '<script src="' . $f . '?' . $_ver . '"></script>' . "\n";
	}
	foreach ($panel as $f) {
		echo '<script src="' . $f . '?' . $_ver . '"></script>' . "\n";
	}
	echo '<script src="' . $mainScript . '?' . $_ver . '"></script>' . "\n";
}
