<?php
include_once("includes/db.php");
include_once("includes/simple.config.php");
include_once("includes/functions.php");
include_once("includes/invoicetemplates.php");

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

if(validateBool('dec')){
	echo dec($_GET['dec']);
	dai();
}

if(validateBool('enc')){
	echo enc($_GET['enc']);
	dai();
}

isHttps();

?>

<html>
	<head>
		<script type="text/javascript" src="/assets/vendor/js/jquery-3.6.3.min.js"></script>
	</head>
	<body>
		<a href="#">Process</a>
	</body>
	<script>
		var sendDataToServer = function(data,callback){
			return false;
			var compId 		= '<?=enc(1769)?>';//'2PY';
			var outId 		= '<?=enc(2050)?>';//'AzE3';
			var userId 		= '<?=enc(39611)?>';//'Jyy9';
			var registerId 	= '<?=enc(2222)?>';//'E865';

			var url = '/index.php?action=processData&companyId='+compId+'&outletId='+outId+'&userId='+userId+'&roleId=1&registerId='+registerId+'&test=true';
			
			var post 	= $.post(url, {'data[]': JSON.stringify(data) });
			post.done(function(dt){
				console.log(dt);
			});
			post.fail(function(){
				alert('fails');
			});
	
		};

		var moyan = {"ident":"Sale","total":24000,"subtotal":24000,"discount":0,"tax":2181.81818182,"client":0,"user":"n90b","note":"","tags":"[]","date":"2017-08-15 13:47:07","type":0,"sale":[{"itemId":"1vZ5","uId":"113","name":"Croquecheddar","uniPrice":6000,"count":1,"discount":"0.000","discAmount":0,"totalDiscount":0,"price":6000,"tax":10,"note":"","type":"product","total":6000,"tags":[]},{"itemId":"NjDQ","uId":"370","name":"Pica\u00f1a c\/Queso BR","uniPrice":11000,"count":1,"discount":"0.000","discAmount":0,"totalDiscount":0,"price":11000,"tax":10,"note":"","type":"product","total":11000,"tags":[]},{"itemId":"DjN6","uId":"36","name":"Sprite 500ml","uniPrice":7000,"count":1,"discount":"0.000","discAmount":0,"totalDiscount":0,"price":7000,"tax":10,"note":"","type":"product","total":7000,"tags":[]}],"invoiceno":34,"payment":[{"type":"debitcard","name":"T. D\u00e9bito","price":24000,"total":24000,"extra":"1690"}],"parentId":false,"dueDate":false,"timestamp":1502819227095,"uid":18611707240077544};

		$('a').click(function(e){
			e.preventDefault();
			sendDataToServer(moyan);	
		});

		
	</script>
</html>