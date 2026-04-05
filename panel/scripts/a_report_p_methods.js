	

	dateRangePickerForReports(startDate,endDate);

	$.get(url,function(result){
		var options = {
						"container" 		: ".tableContainer",
						"url" 				: url,
						"rawUrl" 			: rawUrl,
						"iniData" 			: result.table,
						"table" 			: ".table1",
						"sort" 				: 0,
						"footerSumCol" 		: [6],
						"currency" 			: currency,
						"decimal" 			: decimal,
						"thousand" 			: thousandSeparator,
						"offset" 			: offset,
						"limit" 			: limit,
						"nolimit" 			: true,
						"tableName" 		: 'tableMethods',
						"fileTitle" 		: 'Medios de Pago Detallado',
						"ncmTools"			: {
													left 	: '',
													right 	: ''
											  },
						"colsFilter"		: {
												name 	: 'methodsDetails2',
												menu 	:  [
																{"index":0,"name":"# Documento","visible":true},
																{"index":1,"name":"Cliente","visible":true},
																{"index":2,"name":tin_name,"visible":true},
																{"index":3,"name":"Medio","visible":true},
																{"index":4,"name":'Detalle',"visible":true},
																{"index":5,"name":'Sucursal',"visible":true},
																{"index":6,"name":'Entregado',"visible":true},
																{"index":7,"name":'Total',"visible":true},
																{"index":8,"name":'Vendido',"visible":true}
															]
											  },
						"clickCB" 		: function(event,tis){
								var load = tis.data('load');
								loadForm(load,'#modalLarge .modal-content',function(){
									$('#modalLarge').modal('show');
								});
						}
		};

		ncmDataTables(options);

		if(result.chart.data){
			$('#myChart').removeClass('hidden');
			$('#loadingChart').addClass('hidden');

			var myChart = document.getElementById('myChart').getContext("2d");

			var gradientStroke = myChart.createLinearGradient(300, 0, 100, 0);
			gradientStroke.addColorStop(0, "#4cb6cb");
			gradientStroke.addColorStop(1, "#54cfc7");

			Chart.defaults.global.responsive = true;
			Chart.defaults.global.maintainAspectRatio = false;
			Chart.defaults.global.legend.display       = false;

			var dataD = {
			    labels: result.chart.labels,
			    datasets: [
			        {
			        	label: "Total",
			            data: result.chart.data,
			            backgroundColor: gradientStroke
			        }]
			};

			setTimeout(function(){
				var methods = new Chart(myChart, {
				    type: 'bar',
				    data: dataD,
				    animation: true,
				    options:chartBarStackedGraphOptions
				});
			}, 200);
		}

		var options = {
						"container" 		: ".tableGeneralContainer",
						"url" 				: url,
						"rawUrl" 			: rawUrl,
						"iniData" 			: result.table2,
						"table" 			: ".table2",
						"sort" 				: 0,
						"footerSumCol" 		: [1],
						"currency" 			: currency,
						"decimal" 			: decimal,
						"thousand" 			: thousandSeparator,
						"offset" 			: offset,
						"limit" 			: limit,
						"nolimit" 			: true,
						"tableName" 		: 'tableMethodsGeneral',
						"fileTitle" 		: 'Ranking de Medios de Pago',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'methodsGeneral',
											menu 	:  [
															{"index":0,"name":"Medio","visible":true},
															{"index":1,"name":"Total","visible":true}
														]
										  }
		};

		ncmDataTables(options);
	});
	
	