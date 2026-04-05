<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('CUSTOMER_ID', dec($data[1]));
define('DATE', $data[2]);
define('OUTLETS_COUNT', 0);


$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ?",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', toUTF8($setting['settingName']));

date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

if(validateHttp('action')){
  $time         = $db->Prepare(iftn(DATE,TODAY));
  $timeSql      = " AND cRecordValueDate < '" . $time . "'";
  $records      = ncmExecute('SELECT a.customerRecordName as name, a.customerRecordId as id FROM customerRecord a, cRecordField b WHERE a.companyId = ? AND b.customerRecordId = a.customerRecordId AND b.cRecordFieldProgress = 1 GROUP BY id',[COMPANY_ID],false,true);

  if($records){
    $contact      = getContactData(CUSTOMER_ID,'uid');
    $customerName = getCustomerName($contact);
    ?>
   
    <div class="col-xs-12 wrapper bg-light lter"> 
      <div class="m-b-lg text-center">
        <a href="#" class="thumb-md animated fadeInDown"> 
          <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
        </a>
      </div>
      <div class="text-center">Progresos de</div> 
      <div class="col-xs-12 h1 font-bold text-center text-dark"><?=$customerName?></div>
      <div class="text-center m-b-lg">Hasta el <?=niceDate($time)?></div> 

       <script type="text/javascript">
        var chartGridColors     = 'rgba(217,228,230,1)';
        var chartSecondColor    = '#778490';
        var chartAxisFotnColor  = '#939aa0';
        var chartFotnFamily     = "'Source Sans Pro', 'Helvetica Neue', 'Helvetica', 'Arial'";
        var chartTooltipBg      = 'rgba(77,93,110,1)';
        var chartTooltipBgH     = '#4d5d6e';
        
        var chartTooltipFontColor = '#eaeef1';

      var chartBarStackedGraphOptions = {
                title: {
                  display: false
                },
                responsive: true,
                scales: {
                  xAxes: [{
                    display: false,
                    stacked: true,
                    ticks: {
                            beginAtZero   : true,
                            fontColor     : chartAxisFotnColor,
                            fontFamily    : chartFotnFamily
                        },
                        gridLines: {
                          color: chartGridColors,
                          lineWidth: 1
                        },
                        zeroLineColor   : chartGridColors
                  }],
                  yAxes: [{
                    stacked: true,
                    ticks: {
                            beginAtZero   : true,
                            fontColor     : chartAxisFotnColor,
                            fontFamily    : chartFotnFamily,
                            callback: function(value, index, values) {
                                         // return formatNumber(value,'',decimal,thousandSeparator,false,false,true);
                                         return value;
                                      }
                        },
                        gridLines: {
                          color: chartGridColors,
                          lineWidth: 1
                        },
                        zeroLineColor   : chartGridColors
                  }]
                },
                tooltips: {
                        backgroundColor: chartTooltipBg,
                        callbacks: {
                            labelColor: function(tooltipItem, chart) {
                                return {
                                    backgroundColor: chartTooltipBg
                                }
                            },
                            labelTextColor:function(tooltipItem, chart){
                                return chartTooltipFontColor;
                            },
                            label: function(tooltipItem, chart){
                                      var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                                      //return datasetLabel + ': ' + formatNumber(tooltipItem.yLabel,'',decimal,thousandSeparator);
                                      return datasetLabel + ': ' + tooltipItem.yLabel;
                                  }
                        }
                    }
                  };
    </script>
      
      <div class="text-left" style="display: block; position: relative;margin: 0 auto; max-width: 700px;">
        <div class="col-xs-12 h3 font-bold m-b"><?=$name;?></div>

        <?php
        if($records){
          while (!$records->EOF){
            $recordss   = $records->fields;
            $name       = $recordss['name'];
            $id         = $recordss['id'];

            $fields     = ncmExecute('SELECT * FROM cRecordField WHERE cRecordFieldProgress = 1 AND customerRecordId = ? GROUP BY cRecordFieldId',array($id),false,true);

            ?>
            <div class="col-xs-12 b-l b-default b-4x m-b-lg pagebreak">
              <div class="col-xs-12 h2 font-bold m-b"><?=$name?></div>

            <?php
            if($fields){
              while (!$fields->EOF){
                $fieldss      = $fields->fields;
                $fname        = toUTF8($fieldss['cRecordFieldName']);
                $fid          = $fieldss['cRecordFieldId'];
                $type         = $fieldss['cRecordFieldType'];
                $build        = false;

                $values       = ncmExecute('SELECT * FROM cRecordValue WHERE cRecordFieldId = ? AND customerId = ? '.$timeSql.' ORDER BY cRecordValueDate DESC',[$fid, CUSTOMER_ID],false,true);
                $valuesss     = [];
                if($values){
                  while (!$values->EOF){
                    $valuess = $values->fields; 
                    $valuesss[$valuess['cRecordValueDate']] = toUTF8( html_entity_decode( $valuess['cRecordValueName'] ) );
                    $values->MoveNext();
                  }
                }
              
                if(counts($valuesss) > 0){
                  if($type == 0 || $type == 1 || $type == 4 || $type == 3){//texto corto
                    $build = '<ul class="list-group no-border no-bg">';

                    foreach($valuesss as $date => $value){
                      if($value == '1'){
                        $value = '<i class="material-icons text-success">check</i>'; 
                      }else if($value == '0'){
                        $value = '<i class="material-icons text-danger">close</i>'; 
                      }

                      $build .= '<li class="list-group-item">' .
                                ' <span class="pull-right badge bg-light lter" data-toggle="tooltip" data-original-title="'.$date.'">' . niceDate($date) . '</span>' .
                                $value .
                                '</li>';
                    }
                    $build .= '</ul>';
                  }else if($type == 2){//numero
                    $labels   = '';
                    $grphdata = '';
                    $valuesss = array_reverse($valuesss);
                    foreach($valuesss as $date => $value){
                      $labels   .= '"' . niceDate($date) . '",';
                      $grphdata .= (float)$value.',';
                    }

                    $labels   = trim($labels,',');
                    $grphdata = trim($grphdata,',');

                    if(COMPANY_ID == 10){
                      $annots   = json_encode([ ['value' => 30, 'text' => 'Bajo', 'color' => 'yellow'], ['value' => 50, 'text' => 'Medio', 'color' => 'green'], ['value' => 80, 'text' => 'Alto', 'color' => 'red'] ] );
                    }else{
                      $annots = '""';
                    }
                    
                    $build = '';
                    $build = '<canvas id="line' . $fid . '" width="600" height="200"></canvas>' .
                              '<script>' .
                              'var annots = ' . $annots . ';' .
                              'var recAnnots = [];' .
                              'if(annots){' .
                              ' recAnnots = annots.map(function(val, index) {' .
                              '   return {' .
                              '     type      : "line",' .
                              '     id        : "hline" + index,' .
                              '     mode      : "horizontal",' .
                              '     scaleID   : "y-axis-0",' .
                              '     value     : val.value,' .
                              '     borderColor: val.color,' .
                              '     borderWidth: 1,' .
                              '     borderDash : [4, 4],' .
                              '     label     : {' .
                              '       backgroundColor: chartTooltipBgH,' .
                              '       enabled: true,' .
                              '       position: "center",' .
                              '       content: val.text' .
                              '     }' .
                              '   };' .
                              ' });' .
                              '}' .

                              'var dataD = {' .
                              '   labels: [' . $labels . '],' .
                              '   datasets: [' .
                              '     {' .
                              '       label: "' . $fname . '",' .
                              '       data: [' . $grphdata . '],' .
                              '       backgroundColor: "#4cb6cb",' .
                              '       minHeight: 5' .
                              '     }' .
                              '   ]' .
                              '};' .

                            'chartBarStackedGraphOptions.annotation = {' .
                            '     drawTime  : "afterDatasetsDraw",' .
                            '     annotations : recAnnots' .
                            '};' .

                            'chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;' .
                            'chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;' .
                            'chartBarStackedGraphOptions.legend = {display : false};' .

                            'console.log(chartBarStackedGraphOptions);' .
                            
                            'var methods = new Chart($("#line' . $fid . '"), {' .
                            '    type        : "bar",' .
                            '    data        : dataD,' .
                            '    animation   : true,' .
                            '    options     : chartBarStackedGraphOptions' .
                            '});' .

                            '</script>';
                  }
                }
              ?>

                <div class="col-xs-12 panel wrapper r-3x bg-white m-b">
                  <div class="m-b h4 font-bold">
                    <?=$fname;?>
                  </div>
                  <?php
                  if(!$build){
                    echo '<div class="text-center text-muted h4">Sin información</div>';
                  }else{
                    echo $build;
                  }
                  ?>
                </div>

              <?php
                $fields->MoveNext();
              }
            }

            ?>
            </div>
            <?php

            $records->MoveNext(); 
          }
        }
        ?>

        <div class="col-xs-12 wrapper text-center hidden-print">
          <a href="https://public.encom.app/customerProgressView?s=<?=$_GET['s']?>" class="nativeA" target="_blank">Ver versión independiente</a>
        </div>


      </div>
      
    </div>
    <?php

  }else{
    ?>
    <div class="text-center col-xs-12 wrapper noDataMessage">
      <img src="https://panel.encom.app/images/emptystate2.png" height="140">
      <h1 class="font-thin">No posee progresos</h1>
      <div class="text-muted m-t">
        <p>Puede crear fichas de clientes en la sección Contactos del Panel de Control y luego podrá asignar progresos</p>
      </div>
    </div>
    <?php
  }

  dai();
}
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Progreso</title>

  <?php
  loadCDNFiles([''],'css');
  ?>
</head>
<body class="bg-light lter">
<div id="results" class="col-xs-12 no-padder"></div>

<div class="hidden-print text-center">
  <a href="https://encom.app?utm_source=ENCOM_customer_progress&utm_medium=ENCOM_footer_icon&
  utm_campaign=<?=COMPANY_NAME?>" class="m-t-lg m-b-lg text-center block">
    <span class="text-muted">Usamos</span> <br>
    <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
  </a>
</div>

<script type="text/javascript">
  var noSessionCheck          = true;
  window.standAlone           = true;
  window.decimal              = "<?=DECIMAL?>";
  window.thousandSeparator    = "<?=THOUSAND_SEPARATOR?>";
</script>
<?php
loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js','https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-annotation/0.5.7/chartjs-plugin-annotation.min.js'],'js');
?>
<script>
    $(document).ready(function(){

      $.get('?action=1&s=<?=$_GET['s']?>',function(result){
        if(result){
          $('#results').html(result);
          $('[data-toggle="tooltip"]').tooltip();
        }
      });
      
    });
  </script>

</body>
</html>
<?php
dai();
?>
