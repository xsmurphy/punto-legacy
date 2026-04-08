<?php
include_once("./app_version.php");
header('Content-Type: application/javascript');
?>
"use strict";

var version = '<?=APP_VERSION?>';

//console.log('WORKER: executing version ' + version);

var offlineFundamentals = [
  '/',
  '/index.html',
  '/API/config.php',
  // Core vendor libs (immutable — version in filename)
  '/assets/vendor/js/jquery-3.6.3.min.js',
  '/assets/vendor/js/bootstrap-3.4.1.min.js',
  '/assets/vendor/css/bootstrap-3.4.1.min.css',
  '/assets/vendor/js/moment-2.24.0-with-locales.min.js',
  '/assets/vendor/js/sweetalert2-7.33.1.min.js',
  '/assets/vendor/css/sweetalert2-7.33.1.min.css',
  '/assets/vendor/js/simpleStorage-0.2.1.min.js',
  '/assets/vendor/css/animate-4.0.0.compat.min.css',
  // App scripts
  '/scripts/globalv2.js',
  '/scripts/ncm-ws.js',
  // App images
  '/images/incomeLogoLgGray.png',
  '/images/openLight.png',
  '/images/closedLight.png',
  '/images/iconincomesm.png',
  '/assets/images/encom_app.png',
  '/images/iconincomesmwhite.png',
  '/assets/images/emptystate2.png',
  '/images/transparent.png',
  '/assets/images/bg_transactions.png',
  '/assets/images/bg_transactions_xs.png',
  '/assets/images/bg_customer.png',
  '/assets/images/bg_customer_xs.png',
  '/assets/images/bg_itemInfo.png',
  '/assets/images/bg_itemInfo_xs.png',
  '/assets/images/bg_drawer.png',
  '/assets/images/bg_drawer_xs.png',
  '/assets/images/bg_tables.png',
  '/assets/images/bg_tables_xs.png',
  '/assets/images/bg_orders.png',
  '/assets/images/bg_orders_xs.png',
  '/images/iconincomesmw.png',
  // Fonts
  '/fonts/fakereceipt.ttf',
  '/fonts/dotmatrix.ttf',
  '/fonts/glyphicons-halflings-regular.woff',
  '/fonts/glyphicons-halflings-regular.woff2',
  '/css/fonts.css'
];

var noCacheMap = ['action?l=','load?l=','ping','fetchs?load=','login?action='];

function indexOfArr(str,arr){
  var lngth = arr.length, i=0;
  var fnd   = false;
  while(i<lngth){
    if(str.indexOf(arr[i]) !== -1){
      fnd = true;
      break;
    }
    i += 1;
  }
  return fnd;
}

self.addEventListener("install", function(event) {
  //console.log('WORKER: install event in progress.');
  event.waitUntil(
    caches.open(version + 'fundamentals').then(function(cache) {
        return cache.addAll(offlineFundamentals);
      })
      .then(function() {
        //console.log('WORKER: install completed');
      })
  );
});

self.addEventListener("fetch", function(event) {
  //console.log('WORKER: fetch event in progress.');

  if (event.request.method !== 'GET') {
    //console.log('WORKER: fetch event ignored.', event.request.method, event.request.url);
    return;
  }
  if (indexOfArr(event.request.url,noCacheMap)) { 
    //console.log('WORKER: fetch event is blacklisted.', event.request.method, event.request.url);
    return; 
  }
  
  event.respondWith(
    caches.match(event.request).then(function(cached) {
        
        var networked = fetch(event.request)
          // We handle the network request with success and failure scenarios.
          .then(fetchedFromNetwork, unableToResolve)
          // We should catch errors on the fetchedFromNetwork handler as well.
          .catch(unableToResolve);

        
        //console.log('WORKER: fetch event', cached ? '(cached)' : '(network)', event.request.url);
        return cached || networked;

        function fetchedFromNetwork(response) {
          var cacheCopy = response.clone();

          //console.log('WORKER: fetch response from network.', event.request.url);

          caches
            // We open a cache to store the response for this request.
            .open(version + 'pages')
            .then(function add(cache) {
              return cache.put(event.request, cacheCopy);
            })
            .then(function() {
              //console.log('WORKER: fetch response stored in cache.', event.request.url);
            });

          // Return the response so that the promise is settled in fulfillment.
          return response;
        }

        function unableToResolve () {

          //console.log('WORKER: fetch request failed in both cache and network.');

          return new Response('<h1>Service Unavailable</h1>', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: new Headers({
              'Content-Type': 'text/html'
            })
          });
        }
      })
  );
});

self.addEventListener("activate", function(event) {

  //console.log('WORKER: activate event in progress.');

  event.waitUntil(
    caches.keys().then(function (keys) {
        // We return a promise that settles when all outdated caches are deleted.
        return Promise.all(
          keys
            .filter(function (key) {
              // Filter by keys that don't start with the latest version prefix.
              return !key.startsWith(version);
            })
            .map(function (key) {
              /* Return a promise that's fulfilled
                 when each outdated cache is deleted.
              */
              return caches.delete(key);
            })
        );
      }).then(function() {
        //console.log('WORKER: activate completed.');
      })
  );
});