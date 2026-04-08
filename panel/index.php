<?php
if(!isset($_SESSION['user'])){
	header('location:/login');
	die();
}else{
	die("loading...");
}
?>

<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler
accessControl([0]);
limitReportAccess();
list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);
?>
<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>{title}</title>

<?php
	loadCDNFiles([],'css');
	footerInjector();
	loadCDNFiles([
				'/assets/vendor/js/Chart-2.9.4.min.js',
				'/assets/vendor/js/simpleStorage-0.2.1.min.js',
				'/scripts/jquery.table2excel.js'
				],'js');
?>
	<?php if (defined('FACEBOOK_PIXEL_ID') && FACEBOOK_PIXEL_ID): ?>
	<script>
       !function(f,b,e,v,n,t,s)
       {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
       n.callMethod.apply(n,arguments):n.queue.push(arguments)};
       if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
       n.queue=[];t=b.createElement(e);t.async=!0;
       t.src=v;s=b.getElementsByTagName(e)[0];
       s.parentNode.insertBefore(t,s)}(window, document,'script',
       'https://connect.facebook.net/en_US/fbevents.js');
       fbq('init', '<?= FACEBOOK_PIXEL_ID ?>');
       fbq('track', 'PageView');
       fbq('track', 'CompleteRegistration');
     </script>
	<?php endif; ?>
</head>
<body class="bg-light lter"> 
	<?=menuFrame('top',true);?>

	<?=menuFrame('bottom');?>

	<div class="modal fade" tabindex="-1" id="modalView" role="dialog">
	    <div class="modal-dialog modal-lg">
	      <div class="modal-content no-bg no-border all-shadows">
	        <div class="modal-body bg-light clear r-3x">
	          
	        </div>
	      </div>
	    </div>
	</div>

	<script type="text/javascript">
		$(document).ready(function(){
			helpers.loadPageOnHashChange();
			$(window).trigger('hashchange');
		});
	</script>

</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>