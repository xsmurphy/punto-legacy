/**
 * Front del reporte Resumen de Ingresos (a_report_summary) — BFF de 3 niveles.
 *
 * Diseño IDÉNTICO al legacy; cambia solo la plomería de datos:
 *   - config (currency/decimal/thousand/taxName) ← GET /bff/bootstrap.php
 *   - KPIs + tablas Resumen                       ← GET /bff/reports/summary.php?view=kpis
 *   - gráfico de ingresos                         ← GET /bff/reports/summary.php?view=chart
 *   - ventas por hora                             ← GET /bff/reports/summary.php?view=hours
 *   - pestaña Por Día                             ← GET /bff/reports/summary.php?view=byday
 *
 * El front NUNCA pega a /API/v1 (siempre al BFF). Los valores vienen YA PRE-FORMATEADOS
 * del BFF (REGLA RAÍZ 2: el PHP no genera markup, solo JSON con datos de display); este JS
 * solo ARMA el markup colocando esos strings + construye los spans de comparación desde el
 * objeto `compare`. drawChart/chartByHours quedan intactos (el BFF emite su shape exacto,
 * con números para Chart.js y labels pre-formateadas). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/summary.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	// Config de la company (se hidrata desde el bootstrap del BFF).
	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA' };

	// Rango actual del reporte (default: últimos 7 días). El date-picker lo actualiza.
	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	/* ───────────── markup (el front solo arma visual; los valores vienen pre-formateados del BFF) ───────────── */

	// Escapa texto antes de inyectarlo como contenido de markup (el front es dueño del markup,
	// así que escapa para su contexto). Los montos/fechas vienen del BFF (numéricos, seguros);
	// el name del medio de pago viene de la BD → se escapa.
	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Arma el span de comparación desde el objeto {dir,pct,prev,positive} del BFF.
	// El BFF ya hizo el cálculo y el formateo; acá solo elegimos icono y clase.
	function buildCmpSpan(c) {
		if (!c) { return '...'; }
		var icon  = c.dir === 'up' ? 'trending_up' : (c.dir === 'down' ? 'trending_down' : 'trending_flat');
		var color = c.positive === true ? 'text-success' : (c.positive === false ? 'text-danger' : 'text-muted');
		return '<span class="' + color + ' pointer" data-toggle="tooltip" title="Periodo anterior: ' + c.prev + '"><i class="material-icons">' + icon + '</i> ' + c.pct + '%</span>';
	}

	/* ───────────── carga de datos (BFF) ───────────── */

	function bffLoad(view, success) {
		var xhr = ncmHelpers.load({
			url         : BFF + '?view=' + view + '&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO),
			httpType    : 'GET',
			hideLoader  : true,
			type        : 'json',
			warnTimeout : false,
			success     : function (res) {
				if (res && res.ok) { success(res.data); }
			}
		});
		window.xhrs.push(xhr);
	}

	function renderKpis(d) {
		var cur = d.current || {};
		var cmp = d.compare || {};

		$('#globalSubtotal').html(cur.grossSales);
		$('#globalSubtotalB').html(buildCmpSpan(cmp.grossSales));

		$('#globalCogs').html(cur.totalReturns);
		$('#globalCogsB').html(buildCmpSpan(cmp.totalReturns));

		$('#globalDiscount').html(cur.totalDiscounts);
		$('#globalDiscountB').html(buildCmpSpan(cmp.totalDiscounts));

		$('#globalUtility').html(cur.netSales);
		$('#globalUtilityB').html(buildCmpSpan(cmp.netSales));

		// Tabla Ventas (valores ya formateados por el BFF)
		var salesTable =
			'<tr class="bg-light lter"><td class="font-bold"> Ventas Brutas</td><td class="text-right font-bold">' + cur.grossSales + '</td></tr>' +
			'<tr><td> <span class="text-u-l pointer" data-toggle="tooltip" title="Pagos realizados con créditos de la empresa, Gift Cards, Crédito Interno o Puntos Loyalty">Pagos con créditos</span></td><td class="text-right">-' + cur.creditPays + '</td></tr>' +
			'<tr><td> Devoluciones</td><td class="text-right">-' + cur.totalReturns + '</td></tr>' +
			'<tr><td> Descuentos</td><td class="text-right">-' + cur.totalDiscounts + '</td></tr>' +
			'<tr class="bg-light lter"><td class="font-bold"> Ventas Netas</td><td class="text-right font-bold">' + cur.netSales + '</td></tr>' +
			'<tr><td>' + RS.taxName + '</td><td class="text-right">' + cur.totalTax + '</td></tr>';
		$('#salesTable').html(salesTable);

		// Tabla Medios de Pago (name + monto pre-formateado; total calculado por el BFF)
		var payTable = '';
		$.each(cur.payments || [], function (i, p) {
			payTable += '<tr><td>' + esc(p.name) + '</td><td class="text-right">' + p.amount + '</td></tr>';
		});
		payTable += '<tr class="font-bold text-u-c"><td>Total</td><td class="text-right">' + cur.paymentsTotal + '</td></tr>';
		$('#paymentsMethodsTable').html(payTable);

		// Tabla Tipos (totalBruto calculado por el BFF)
		var typeTable =
			'<tr><td> Ventas al Contado</td><td class="text-right">' + cur.cashSales + '</td></tr>' +
			'<tr><td> Ventas a Crédito</td><td class="text-right">' + cur.creditSales + '</td></tr>' +
			'<tr><td class="font-bold text-u-c"> Total Bruto</td><td class="text-right font-bold">' + cur.totalBruto + '</td></tr>';
		$('#typeSalesTable').html(typeTable);

		// Tabla Gift Cards
		var gcTable =
			'<tr><td class="font-bold text-u-c"> Vendido</td><td class="text-right font-bold">' + cur.giftcardsSold + '</td></tr>' +
			'<tr><td> Cantidad</td><td class="text-right">' + cur.giftcardsCount + '</td></tr>' +
			'<tr><td> Canjeado</td><td class="text-right">' + cur.totalGiftcardUsed + '</td></tr>';
		$('#giftcardsTabe').html(gcTable);

		$('[data-toggle="tooltip"]').tooltip();
	}

	function renderByDay(d) {
		var rows = d.rows || [];
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Fecha</th>' +
			'<th class="text-center">Nro. de Ventas</th>' +
			'<th class="text-center">Descuentos</th>' +
			'<th class="text-center">' + RS.taxName + '</th>' +
			'<th class="text-center">Gravado</th>' +
			'<th class="text-center">Total</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			// display ya formateado por el BFF; *Raw para data-order (sort + footer de DataTables).
			body +=
				'<tr class="clickrow">' +
				' <td data-order="' + r.dateRaw + '"> ' + r.date + ' </td>' +
				' <td class="text-right" data-order="' + r.countRaw + '"> ' + r.count + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.discountRaw + '" data-format="money"> ' + r.discount + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.taxRaw + '" data-format="money"> ' + r.tax + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.subtotalRaw + '" data-format="money"> ' + r.subtotal + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.totalRaw + '" data-format="money"> ' + r.total + ' </td>' +
				'</tr>';
		});

		var foot =
			'</tbody><tfoot><tr>' +
			'<th class="">TOTALES:</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'<th class="text-right"></th><th class="text-right"></th>' +
			'</tr></tfoot>';

		ncmDataTables({
			"container"     : "#byDayTable",
			"iniData"       : head + body + foot,
			"table"         : ".table1",
			"sort"          : 0,
			"footerSumCol"  : [1, 2, 3, 4, 5],
			"currency"      : RS.currency,
			"decimal"       : RS.decimal,
			"thousand"      : RS.thousand,
			"offset"        : 0,
			"limit"         : 100,
			"noMoreBtn"     : true,
			"tableName"     : 'tableTransactions',
			"fileTitle"     : 'Resumen Por Día',
			"ncmTools"      : { left: '', right: '' }
		});
	}

	function loadAll() {
		bffLoad('kpis',  renderKpis);
		bffLoad('chart', function (d) {
			if (d.chart && d.chart.sales.gross.length) {
				$('#summaryChart').removeClass('hidden');
				$('#loadingChart').addClass('hidden');
				drawChart(d);
			} else {
				$('#summaryChart').addClass('hidden');
			}
			$('.noDayHolder').addClass(d.chart ? d.chart.noDayShow : '');
		});
		bffLoad('hours', function (d) {
			if (d.total && d.total.length) {
				chartByHours(d);
			} else {
				$('#hours').addClass('hidden');
			}
		});
	}

	/* ───────────── init ───────────── */

	$(document).ready(function () {

		// 1) Config de la company (currency/decimal/thousand/taxName) desde el BFF.
		ncmHelpers.load({
			url         : BOOTSTRAP,
			httpType    : 'GET',
			hideLoader  : true,
			type        : 'json',
			warnTimeout : false,
			success     : function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					RS.taxName  = res.data.taxName  || 'IVA';
					// El shell también usa estos globals (ncmDataTables, masks).
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadAll();
			}
		});

		// 2) Date-picker: misma UI; en vez de recargar la página, re-fetchea del BFF.
		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO,   'YYYY-MM-DD HH:mm:ss'),
			false, true
		);

		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadAll();
		});

		// 3) Pestaña "Por Día" — carga perezosa la primera vez que se abre.
		var byDayLoaded = false;
		$('#byDayTabLink').on('shown.bs.tab', function () {
			if (!byDayLoaded) {
				byDayLoaded = true;
				bffLoad('byday', renderByDay);
			}
		});

		// 4) Exportar a Excel (reusa el helper del shell).
		ncmHelpers.onClickWrap('.export', function (event, tis) {
			table2Xlsx(tis.data('table'), tis.data('name'));
		});
	});

	/* ───────────── gráficos (INTACTOS — el BFF emite su shape exacto) ───────────── */

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
		        	label: "Total " + RS.currency,
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
