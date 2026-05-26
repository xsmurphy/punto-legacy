/**
 * Front del reporte Resumen (a_report_summary) — extraído del <script> inline de
 * a_report_summary.php (split front/back, piloto del módulo Reportes).
 *
 * El back (a_report_summary.php?action=getSales/getTypeSales/getGiftcards/
 * getChartSales/topHours/salesListByDay) ya devuelve JSON; este front solo pinta
 * KPIs + charts. Las vars que antes se inyectaban con tags PHP en el JS inline
 * ahora llegan por window.reportSummary (seteadas en la vista PHP).
 */
(function () {

	var rs           = window.reportSummary || {};
	var baseUrl      = rs.baseUrl;
	var startDate    = rs.startDate;
	var endDate      = rs.endDate;
	var taxName      = rs.taxName;
	var currency     = rs.currency;
	var offsetDetail = rs.offset;
	var limitDetail  = rs.limit;

	$(document).ready(function(){

		dateRangePickerForReports(startDate, endDate, false, true);

		var xhr = ncmHelpers.load({
				url 		: baseUrl + "?action=getSales",
				httpType 	: 'GET',
				hideLoader 	: true,
				type 		: 'json',
				success 	: function(result){

					$('#globalSubtotal').html(result.grossSales);
					$('#globalSubtotalB').html(result.grossSalesB);

					$('#globalCogs').html(result.totalReturns);
					$('#globalCogsB').html(result.totalReturnsB);

					$('#globalDiscount').html(result.totalDiscounts);
					$('#globalDiscountB').html(result.totalDiscountsB);

					$('#globalUtility').html(result.netSales);
					$('#globalUtilityB').html(result.netSalesB);

					//ventas
					var table 		= 	'<tr class="bg-light lter">' +
										'	<td class="font-bold">' +
										' 		Ventas Brutas' +
										'	</td>' +
										'	<td class="text-right font-bold">' +
											result.grossSales +
										'	</td>' +
										'</tr>' +
										'<tr>' +
										'	<td class="">' +
										' 		<span class="text-u-l pointer" data-toggle="tooltip" title="Pagos realizados con créditos de la empresa, Gift Cards, Crédito Interno o Puntos Loyalty">Pagos con créditos</span>' +
										'	</td>' +
										'	<td class="text-right">' +
											'-' + result.creditPays +
										'	</td>' +
										'</tr>' +
										'<tr>' +
										'	<td class="">' +
										' 		Devoluciones' +
										'	</td>' +
										'	<td class="text-right">' +
											'-' + result.totalReturns +
										'	</td>' +
										'</tr>'+
										'<tr>' +
										'	<td class="">' +
										' 		Descuentos' +
										'	</td>' +
										'	<td class="text-right">' +
											'-' + result.totalDiscounts +
										'	</td>' +
										'</tr>' +
										'<tr class="bg-light lter">' +
										'	<td class="font-bold">' +
										' 		Ventas Netas' +
										'	</td>' +
										'	<td class="text-right font-bold">' +
											result.netSales +
										'	</td>' +
										'</tr>' +
										'<tr>' +
										'	<td class="">' +
										'\t\t' + taxName +
										'	</td>' +
										'	<td class="text-right">' +
											result.totalTax +
										'	</td>' +
										'</tr>';

					$('#salesTable').html(table);

					//m de pagos
					if(result.payments){
						var table = '';
						$.each(result.payments,function(i,k){
							table += 	'<tr>' +
										'	<td>' +
										k.name +
										'	</td>' +
										'	<td class="text-right">' +
										k.total +
										'	</td>' +
										'</tr>';
						});

						table += '<tr class="font-bold text-u-c"><td>Total</td><td class="text-right">' + result.totalPayments + '</td></tr>';

						$('#paymentsMethodsTable').html(table);
					}

					ncmHelpers.load({
						url 		: baseUrl + '?action=getGiftcards',
						httpType 	: 'GET',
						hideLoader 	: true,
						type 		: 'json',
						warnTimeout : false,
						success 	: function(resultg){
							//$.get(baseUrl + '?action=getGiftcards',function(resultg){
								var table 		= 	'<tr>' +
													'	<td class="font-bold text-u-c">' +
													' 		Vendido' +
													'	</td>' +
													'	<td class="text-right font-bold">' +
														resultg.totalSold +
													'	</td>' +
													'</tr>'+
													'<tr>' +
													'	<td>' +
													' 		Cantidad' +
													'	</td>' +
													'	<td class="text-right">' +
														resultg.totalCount +
													'	</td>' +
													'</tr>' +
													'<tr>' +
													'	<td>' +
													' 		Canjeado' +
													'	</td>' +
													'	<td class="text-right">' +
														result.totalGiftcardUsed +
													'	</td>' +
													'</tr>';

								$('#giftcardsTabe').html(table);
							}
					});

					$('[data-toggle="tooltip"]').tooltip();
				}
		});

		window.xhrs.push(xhr);

		var xhr = ncmHelpers.load({
			url 		: baseUrl + "?action=getTypeSales",
			httpType 	: 'GET',
			hideLoader 	: true,
			type 		: 'json',
			warnTimeout : false,
			success 	: function(result){
				var table 		= 	'<tr>' +
									'	<td>' +
									' 		Ventas al Contado' +
									'	</td>' +
									'	<td class="text-right">' +
										result.cashSales +
									'	</td>' +
									'</tr>'+
									'<tr>' +
									'	<td>' +
									' 		Ventas a Crédito' +
									'	</td>' +
									'	<td class="text-right">' +
										result.creditSales +
									'	</td>' +
									'</tr>' +
									'<tr>' +
									'	<td class="font-bold text-u-c">' +
									' 		Total Bruto' +
									'	</td>' +
									'	<td class="text-right font-bold">' +
										result.totalSold +
									'	</td>' +
									'</tr>';

				$('#typeSalesTable').html(table);
			}
		});

		window.xhrs.push(xhr);

		var xhr = ncmHelpers.load({
			url 		: baseUrl + "?action=getChartSales&expenses=1",
			httpType 	: 'GET',
			hideLoader 	: true,
			type 		: 'json',
			warnTimeout : false,
			success 	: function(result){
				if(result.chart.sales.gross.length){
					$('#summaryChart').removeClass('hidden');
					$('#loadingChart').addClass('hidden');
					drawChart(result);
				}else{
					$('#summaryChart').addClass('hidden');
				}

				$('.noDayHolder').addClass(result.chart.noDayShow);
			}
		});

		window.xhrs.push(xhr);

		var xhr = ncmHelpers.load({
			url 		: baseUrl + '?action=topHours',
			httpType 	: 'GET',
			hideLoader 	: true,
			type 		: 'json',
			warnTimeout : false,
			success 	: function(result){
				if(result.total.length){
					chartByHours(result);
				}else{
					$('#hours').addClass('hidden');
				}
			}
		});

		window.xhrs.push(xhr);

		var tableViewed = false;

		$('#byDayTabLink').on('shown.bs.tab', function (e) {
			if(!tableViewed){
				var url       = baseUrl + "?action=salesListByDay";
			    var rawUrl    = url;

			    var xhr = ncmHelpers.load({
					url 		: url,
					httpType 	: 'GET',
					hideLoader 	: true,
					type 		: 'json',
					warnTimeout : false,
					success 	: function(result){

						tableViewed = true;

						var info1 = {
									"container" 	: "#byDayTable",
									"url" 			: url,
									"rawUrl"      	: rawUrl,
									"iniData" 		: result.table,
									"table" 		: ".table1",
									"sort" 			: 0,
									"footerSumCol" 	: [1,2,3,4,5],
									"currency" 		: currency,
									"decimal" 		: decimal,
									"thousand" 		: thousandSeparator,
									"offset" 		: offsetDetail,
									"limit" 		: limitDetail,
									"noMoreBtn" 	: true,
									"tableName" 	: 'tableTransactions',
									"fileTitle" 	: 'Resumen Por Día',
									"ncmTools"    	: {
							                          left  : '',
							                          right   : ''
							                          }
						};

						ncmDataTables(info1);
					}
				});

				window.xhrs.push(xhr);

			}
		});

		ncmHelpers.onClickWrap('.export',function(event,tis){
			var table 	= tis.data('table');
			var name 	= tis.data('name');
			table2Xlsx(table,name);
		});

	});

	function drawChart(result){
		var charter = result.chart;

		Chart.defaults.global.legend.display 		= true;
		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;

		var myChart 		= $('#summaryChart')[0].getContext("2d");
		var gradientStroke 	= myChart.createLinearGradient(1600, 0, 0, 0);
		gradientStroke.addColorStop(0, "#4cb6cb");
		gradientStroke.addColorStop(0.5, "#54cfc7");
		gradientStroke.addColorStop(1, "#54cfc7");

		var annots    = charter.annotations;
	    var recAnnots = [];

	    if(ncmHelpers.validity(annots)){
	      recAnnots = annots.map(function(val, index) {
	        var id        = 'vline' + index;
	        var mode      = 'vertical';
	        var scaleId   = "x-axis-0";
	        var position  = iftn(val.position,'center');

	        if(val.orientation == 'horizontal'){
	          id        = 'hline' + index;
	          mode      = 'horizontal';
	          scaleId   = "y-axis-0";
	        }

	        var value = val.value;

	        return {
	          type      : "line",
	          id        : id,
	          mode      : mode,
	          scaleID   : scaleId,
	          value     : value.toFixed(2),
	          borderColor: val.color,
	          borderWidth: 2,
	          borderDash : [2, 7],
	          borderDashOffset : 5,
	          label     : {
	            backgroundColor: 'rgba(77,93,110,0.6)',
	            enabled: true,
	            position: position,
	            content: val.text,
	            font: {
		            size: 7
		        }
	          }
	        };
	      });
	    }


	    chartBarStackedGraphOptions.annotation = {
	                                              drawTime    : "afterDatasetsDraw",
	                                              annotations : recAnnots
	                                            };

		var data = {
		    labels 	: charter.sales.labels,
		    datasets: [
		    	{
	                label                     : "Margen",
	                data                      : charter.sales.margin,
	                type                      : 'line',
	                borderColor               : '#FF9469',

	                pointColor                : '#FF9469',
	                pointHoverRadius          : 8,
	                pointHoverBorderColor     : "#fff",
	                pointHoverBackgroundColor : '#FF9469',
	                pointBorderColor          : '#FF9469',
	                pointBackgroundColor      : '#FF9469',
	                pointRadius               : 3,
	                pointHoverBorderWidth     : 3,
	                pointBorderWidth          : 1,
	                pointHitRadius            : 20,
	                borderWidth               : 3,
	                fill                      : false
	            },
		        {

		            label 					  	: "Ingreso Anterior",
		            data 						: charter.sales.grossB,
		            type                      	: 'line',
		            borderColor 				: chartSecondColor,

		            pointColor 					: chartSecondColor,
		            pointHoverRadius 			: 8,
		            pointHoverBorderColor 		: "#fff",
		            pointHoverBackgroundColor 	: chartSecondColor,
		            pointBorderColor 			: chartSecondColor,
		            pointBackgroundColor 		: chartSecondColor,
		            pointRadius 				: 3,
		            pointHoverBorderWidth 		: 3,
		            pointBorderWidth 			: 3,
		            pointHitRadius 				: 20,
		            borderDash 					: [10,5],
		            borderWidth 				: 3,
		            fill 						: false
		        },
		        {
		        	type 						: 'bar',
		            label 						: "Ingreso Actual",
		            backgroundColor 			: gradientStroke,
		            data 						: charter.sales.gross
		        },
		        {
		        	type 						: 'bar',
		            label 						: "Egresos",
		            backgroundColor 			: chartSecondColor,
		            data 						: charter.sales.grossE
		        }
		    ]
		};

		chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
	    chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;

		var chart = new Chart(myChart, {
			type        : 'bar',
		    data 		: data,
		    animation 	: true,
		    options 	: chartBarStackedGraphOptions
		});


		chart.getDatasetMeta(3).hidden = true;
		chart.update();

		chartBarStackedGraphOptions.annotation = {};


		var days 			= $('#days')[0].getContext("2d");
		var gradientStroke 	= days.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, "#4cb6cb");
		gradientStroke.addColorStop(1, "#54cfc7");

		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;
		Chart.defaults.global.legend.display       	= false;

		var dataD = {
		    labels: charter.days.labels,
		    datasets: [
		        {
		        	label: "Total " + currency,
		            data: charter.days.data,
		            backgroundColor: gradientStroke
		        }]
		};

		chartBarStackedGraphOptions.scales.xAxes[0].display = true;

		var methods = new Chart(days, {
		    type      : 'bar',
		    data      : dataD,
		    animation : true,
		    options   : chartBarStackedGraphOptions
		});

		chartBarStackedGraphOptions.scales.xAxes[0].display = false;

	}

	function chartByHours(result){

		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;
		Chart.defaults.global.legend.display       	= false;

		var hoursChart 		=$('#hours')[0].getContext("2d");
		var gradientStroke 	= hoursChart.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, "#4cb6cb");
		gradientStroke.addColorStop(1, "#54cfc7");

		var dataH = {
		    labels 		: result.hour,
		    datasets 	: [
		    				{
				                label                     : "Ventas",
				                data                      : result.total,
				                type                      : 'line',
				                borderColor               : '#FF9469',

				                pointColor                : '#FF9469',
				                pointHoverRadius          : 8,
				                pointHoverBorderColor     : "#fff",
				                pointHoverBackgroundColor : '#FF9469',
				                pointBorderColor          : '#FF9469',
				                pointBackgroundColor      : '#FF9469',
				                pointRadius               : 3,
				                pointHoverBorderWidth     : 3,
				                pointBorderWidth          : 3,
				                pointHitRadius            : 20,
				                borderWidth               : 5,
				                fill                      : false
				            }
		    			]
		};

		chartLineGraphOptions.scales.xAxes[0].display = true;

		var methods = new Chart(hoursChart, {
		    type 		: 'line',
		    data 		: dataH,
		    animation 	: true,
		    options   	: chartLineGraphOptions
		 });

		chartLineGraphOptions.scales.xAxes[0].display = false;
	}

})();
